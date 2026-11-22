#!/usr/bin/env php
<?php

require __DIR__ . '/qstat.inc.php';
require_once __DIR__ . '/iiko_client.php';
$iikoClient = new QstatIikoClient(isset($iiko_config) ? $iiko_config : array(), !empty($ClientNameLookup_iiko));

date_default_timezone_set('Europe/Moscow');

// Защита от параллельного запуска (cron стартует скрипт каждые 30 сек)
$lockFp = fopen('/tmp/sync_queue_report-new.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$config = [
    'dsn'            => $dsn,
    'user'           => $dbUser,
    'password'       => $dbPass,
    'system_id'      => $system_id,
    'batch'          => 5000,
];

$pdo = new PDO(
    $config['dsn'],
    $config['user'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]
);

function normalizePhone($value)
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $value);
    return $digits !== '' ? $digits : $value;
}

function extractAgentExt($agent)
{
    if ($agent === null) {
        return null;
    }

    $agent = trim((string)$agent);
    if ($agent === '' || strtoupper($agent) === 'NONE') {
        return null;
    }

    if (preg_match('/Local\/(\d+)@/i', $agent, $m)) {
        return $m[1];
    }
    if (preg_match('/SIP\/(\d+)/i', $agent, $m)) {
        return $m[1];
    }
    if (preg_match('/PJSIP\/(\d+)/i', $agent, $m)) {
        return $m[1];
    }
    if (preg_match('/\b(\d{3,6})\b/', $agent, $m)) {
        return $m[1];
    }

    return $agent;
}

function getState(PDO $pdo, $key, $default = '')
{
    $stmt = $pdo->prepare("SELECT state_value FROM cc_etl_state WHERE state_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $value !== false ? (string)$value : $default;
}

function setState(PDO $pdo, $key, $value)
{
    $stmt = $pdo->prepare("
        INSERT INTO cc_etl_state (state_key, state_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            state_value = VALUES(state_value),
            updated_at = NOW()
    ");
    $stmt->execute([$key, $value]);
}

// Reconcile recently inserted missed calls even when the queuelog cursor has advanced.
// Only new report rows (last 10 minutes) are eligible: no historical mass mailing.
// UNIQUE (system_id,callid,event_type) makes repeated runs idempotent.
function reconcileRecentMissedPush(PDO $pdo, $systemId)
{
    $stmt = $pdo->prepare("INSERT IGNORE INTO cc_push_events
        (system_id,callid,event_type,report_id,client_number,client_name,queue_number,did_number,call_time,created_at)
        SELECT r.system_id,r.callid,'missed_call',r.id,r.client_number,r.client_name,
               r.queue_number,r.did_number,r.call_time,NOW()
        FROM cc_calls_report r
        WHERE r.system_id = ?
          AND r.status = 'missed_no_callback'
          AND r.client_number IS NOT NULL AND r.client_number <> ''
          AND r.created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
          AND r.call_time >= DATE_SUB(NOW(), INTERVAL 2 HOUR)");
    $stmt->execute(array($systemId));
    $count = $stmt->rowCount();
    if ($count > 0) {
        error_log('QStat push: reconciled ' . $count . ' missed-call event(s)');
    }
}

// Курсор = максимальный уже просмотренный id queuelog.
// Читаем НОВЫЕ строки, но по каждому затронутому callid подтягиваем ВСЕ его строки,
// чтобы долгий звонок (ENTERQUEUE/CONNECT раньше курсора) не терял начало.
$lastId = (int)getState($pdo, 'queue_log_max_id', '0');

$stmt = $pdo->prepare("
    SELECT id, callid
    FROM queuelog
    WHERE id > ?
    ORDER BY id ASC
    LIMIT " . (int)$config['batch']);
$stmt->execute([$lastId]);
$newRows = $stmt->fetchAll();
$stmt->closeCursor();

if (!$newRows) {
    reconcileRecentMissedPush($pdo, $config['system_id']);
    exit(0);
}

$maxId = $lastId;
$touched = [];
foreach ($newRows as $nr) {
    if ((int)$nr['id'] > $maxId) {
        $maxId = (int)$nr['id'];
    }
    $cid = trim((string)$nr['callid']);
    if ($cid !== '' && strtoupper($cid) !== 'NONE') {
        $touched[$cid] = true;
    }
}

$rows = [];
foreach (array_chunk(array_keys($touched), 500) as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $stmt = $pdo->prepare("
        SELECT id, time, callid, queuename, agent, event, data1, data2, data3, data4, data5
        FROM queuelog
        WHERE callid IN ($in)
        ORDER BY id ASC");
    $stmt->execute($chunk);
    $rows = array_merge($rows, $stmt->fetchAll());
    $stmt->closeCursor();
}

if (!$rows) {
    setState($pdo, 'queue_log_max_id', (string)$maxId);
    reconcileRecentMissedPush($pdo, $config['system_id']);
    exit(0);
}

usort($rows, function ($x, $y) {
    return (int)$x['id'] - (int)$y['id'];
});

$calls = [];

foreach ($rows as $row) {
    $id       = (int)$row['id'];
    $callid   = trim((string)$row['callid']);
    $event    = strtoupper(trim((string)$row['event']));
    $time     = trim((string)$row['time']);
    $queue    = trim((string)$row['queuename']);
    $agentRaw = trim((string)$row['agent']);

    if ($callid === '' || strtoupper($callid) === 'NONE') {
        continue;
    }

    if (!isset($calls[$callid])) {
        $calls[$callid] = [
            'first_id'        => $id,
            'queue_log_id'    => $id,
            'call_time'       => null,
            'callid'          => $callid,
            'did_number'      => null,
            'client_number'   => null,
            'client_name'     => null,
            'queue_number'    => null,
            'agent_ext'       => null,
            'wait_seconds'    => 0,
            'talk_seconds'    => 0,
            'status'          => 'missed_no_callback',
            'linkedid'        => null,
            'debug_uniqueid'  => $callid,
            'has_enterqueue'  => false,
            'is_complete'     => false,
        ];
    }

    if ($id < $calls[$callid]['first_id']) {
        $calls[$callid]['first_id'] = $id;
    }

    if ($id > $calls[$callid]['queue_log_id']) {
        $calls[$callid]['queue_log_id'] = $id;
    }

    switch ($event) {
        case 'DID':
            $did = normalizePhone(isset($row['data1']) ? $row['data1'] : null);
            if ($did !== null) {
                $calls[$callid]['did_number'] = $did;
            }
            if ($queue !== '' && strtoupper($queue) !== 'NONE') {
                $calls[$callid]['queue_number'] = $queue;
            }
            break;

        case 'ENTERQUEUE':
            $calls[$callid]['has_enterqueue'] = true;
            if (empty($calls[$callid]['call_time'])) {
                $calls[$callid]['call_time'] = $time;
            }
            if ($queue !== '' && strtoupper($queue) !== 'NONE') {
                $calls[$callid]['queue_number'] = $queue;
            }
            $client = normalizePhone(isset($row['data2']) ? $row['data2'] : null);
            if ($client !== null) {
                $calls[$callid]['client_number'] = $client;
            }
            break;

        case 'CONNECT':
            $agentExt = extractAgentExt($agentRaw);
            if ($agentExt !== null) {
                $calls[$callid]['agent_ext'] = $agentExt;
            }
            $wait = (int)(isset($row['data1']) ? $row['data1'] : 0);
            if ($wait > $calls[$callid]['wait_seconds']) {
                $calls[$callid]['wait_seconds'] = $wait;
            }
            $calls[$callid]['status'] = 'answered';
            break;

        case 'COMPLETEAGENT':
        case 'COMPLETECALLER':
            $calls[$callid]['is_complete'] = true;

            $agentExt = extractAgentExt($agentRaw);
            if ($agentExt !== null) {
                $calls[$callid]['agent_ext'] = $agentExt;
            }

            $wait = (int)(isset($row['data1']) ? $row['data1'] : 0);
            $talk = (int)(isset($row['data2']) ? $row['data2'] : 0);

            if ($wait > $calls[$callid]['wait_seconds']) {
                $calls[$callid]['wait_seconds'] = $wait;
            }
            if ($talk > $calls[$callid]['talk_seconds']) {
                $calls[$callid]['talk_seconds'] = $talk;
            }

            $calls[$callid]['status'] = 'answered';
            break;

        case 'ABANDON':
            $calls[$callid]['is_complete'] = true;

            $wait = (int)(isset($row['data3']) ? $row['data3'] : 0);
            if ($wait > $calls[$callid]['wait_seconds']) {
                $calls[$callid]['wait_seconds'] = $wait;
            }

            if ($calls[$callid]['status'] !== 'answered') {
                $calls[$callid]['status'] = 'missed_no_callback';
            }
            break;

        case 'EXITWITHTIMEOUT':
        case 'EXITEMPTY':
        case 'EXITWITHKEY':
            $calls[$callid]['is_complete'] = true;

            if ($calls[$callid]['status'] !== 'answered') {
                $calls[$callid]['status'] = 'missed_no_callback';
            }
            break;
    }
}

$orderedCallIds = array_keys($calls);

$upsert = $pdo->prepare("
    INSERT INTO cc_calls_report (
        system_id,
        queue_log_id,
        call_time,
        callid,
        did_number,
        client_number,
        client_name,
        queue_number,
        agent_ext,
        wait_seconds,
        talk_seconds,
        status,
        linkedid,
        debug_uniqueid,
        updated_at
    ) VALUES (
        :system_id,
        :queue_log_id,
        :call_time,
        :callid,
        :did_number,
        :client_number,
        :client_name,
        :queue_number,
        :agent_ext,
        :wait_seconds,
        :talk_seconds,
        :status,
        :linkedid,
        :debug_uniqueid,
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        queue_log_id   = GREATEST(queue_log_id, VALUES(queue_log_id)),
        call_time      = COALESCE(VALUES(call_time), call_time),
        did_number     = COALESCE(VALUES(did_number), did_number),
        client_number  = COALESCE(VALUES(client_number), client_number),
        client_name    = COALESCE(NULLIF(VALUES(client_name), ''), client_name),
        queue_number   = COALESCE(VALUES(queue_number), queue_number),
        agent_ext      = COALESCE(VALUES(agent_ext), agent_ext),
        wait_seconds   = CASE
                            WHEN VALUES(wait_seconds) > wait_seconds THEN VALUES(wait_seconds)
                            ELSE wait_seconds
                         END,
        talk_seconds   = CASE
                            WHEN VALUES(talk_seconds) > talk_seconds THEN VALUES(talk_seconds)
                            ELSE talk_seconds
                         END,
        status         = CASE
                            WHEN VALUES(status) = 'answered' THEN 'answered'
                            WHEN status = 'answered' THEN status
                            WHEN status LIKE 'missed\_callback%' THEN status
                            ELSE VALUES(status)
                         END,
        linkedid       = COALESCE(VALUES(linkedid), linkedid),
        debug_uniqueid = COALESCE(VALUES(debug_uniqueid), debug_uniqueid),
        updated_at     = NOW()
");

$pdo->beginTransaction();

try {
    foreach ($orderedCallIds as $callid) {
        $call = $calls[$callid];

        if (!$call['has_enterqueue']) {
            continue;
        }

        if (!$call['is_complete']) {
            continue;
        }

        if (empty($call['call_time'])) {
            continue;
        }

        if (empty($call['client_number'])) {
            continue;
        }


	// При отключённом iiko HTTP не вызывается. При отсутствии имени — номер.
        $clientName = $iikoClient->lookupName($call['client_number']);
        $call['client_name'] = $clientName !== null ? $clientName : $call['client_number'];

        $upsert->execute([
            ':system_id'      => $config['system_id'],
            ':queue_log_id'   => $call['queue_log_id'],
            ':call_time'      => $call['call_time'],
            ':callid'         => $call['callid'],
            ':did_number'     => $call['did_number'],
            ':client_number'  => $call['client_number'],
            ':client_name'    => $call['client_name'],
            ':queue_number'   => $call['queue_number'],
            ':agent_ext'      => $call['agent_ext'],
            ':wait_seconds'   => $call['wait_seconds'],
            ':talk_seconds'   => $call['talk_seconds'],
            ':status'         => $call['status'],
            ':linkedid'       => $call['linkedid'],
            ':debug_uniqueid' => $call['debug_uniqueid'],
        ]);

    }

    // Event is recorded in the same transaction as the call and ETL cursor.
    // This is idempotent across retries and updates to the same call.
    $pushEvent = $pdo->prepare("INSERT IGNORE INTO cc_push_events
        (system_id,callid,event_type,report_id,client_number,client_name,queue_number,did_number,call_time,created_at)
        SELECT system_id,callid,'missed_call',id,client_number,client_name,queue_number,did_number,call_time,NOW()
        FROM cc_calls_report WHERE system_id=? AND callid=? AND status='missed_no_callback'");
    foreach ($orderedCallIds as $callid) {
        $call = $calls[$callid];
        if (!$call['has_enterqueue'] || !$call['is_complete'] || empty($call['call_time']) || empty($call['client_number'])) continue;
        if ($call['status'] !== 'missed_no_callback') continue;
        $pushEvent->execute(array($config['system_id'], $callid));
    }

    setState($pdo, 'queue_log_max_id', (string)$maxId);
    setState($pdo, 'queue_log_last_id', (string)$maxId);

    $pdo->commit();
    // Reconciliation is separate from the ETL transaction: an FCM-queue issue
    // cannot roll back a successfully imported call or its cursor.
    try {
        reconcileRecentMissedPush($pdo, $config['system_id']);
    } catch (Exception $pushError) {
        error_log('QStat push reconciliation failed: ' . $pushError->getMessage());
    }
} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
