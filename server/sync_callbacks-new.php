#!/usr/bin/env php
<?php

include 'qstat.inc.php';

date_default_timezone_set('Europe/Moscow');

// Защита от параллельного запуска
$lockFp = fopen('/tmp/sync_callbacks-new.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0);
}

/* before include
$config = [
    'dsn'                   => 'mysql:host=127.0.0.1;dbname=asteriskcdrdb;charset=utf8mb4',
    'user'                  => 'freepbxuser',
    'password'              => 'w1WbnSxbkdcl',
    'internal_prefix'       => '17',
    'callback_window_hours' => 24,
    'batch'                 => 500,
    'callback_dcontext'     => 'from-internal',
];
*/

$config = [
    'dsn'       => $dsn,
    'user'      => $dbUser,
    'password'  => $dbPass,
    'internal_prefix'       => $internal_prefix,
    'callback_window_hours' => $callback_window_hours,
    'batch'     => 5000,
    'callback_dcontext'     => 'from-internal',
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
    return $digits !== '' ? $digits : null;
}

function extFromCnum($cnum, $prefix)
{
    $digits = normalizePhone($cnum);
    if ($digits === null) {
        return null;
    }

    return strpos($digits, $prefix) === 0 ? $digits : null;
}

$selectMissed = $pdo->prepare("
    SELECT
        id,
        call_time,
        callid,
        client_number,
        queue_number,
        did_number,
        status
    FROM cc_calls_report
    WHERE status IN ('missed_no_callback','miss_night_1','miss_night_2')
      AND client_number IS NOT NULL
      AND client_number <> ''
      AND id > :after_id
      AND call_time >= :since
    ORDER BY id ASC
    LIMIT " . (int)$config['batch']
);

$findCallback = $pdo->prepare("
    SELECT
        calldate,
        src,
        dst,
        cnum,
        cnam,
        disposition,
        billsec,
        duration,
        uniqueid,
        linkedid,
        dcontext,
        lastapp,
        channel,
        dstchannel
    FROM cdr
    WHERE calldate > :date_from
      AND calldate <= :date_to
      AND dcontext = :dcontext
      AND dst = :dst
      AND cnum LIKE :cnum_like
    ORDER BY calldate DESC, uniqueid DESC
    LIMIT 1
");

$findSelfCallback = $pdo->prepare("
    SELECT
        id,
        call_time,
        callid,
        did_number,
        queue_number,
        agent_ext,
        status
    FROM cc_calls_report
    WHERE client_number = :client_number
      AND id > :source_id
      AND status = 'answered'
      AND call_time > :date_from
      AND call_time <= :date_to
    ORDER BY id ASC
    LIMIT 1
");

$updateReport = $pdo->prepare("
    UPDATE cc_calls_report
    SET
        status = :status,
        callback_time = :callback_time,
        callback_agent_ext = :callback_agent_ext,
        callback_disposition = :callback_disposition,
        linkedid = CASE
            WHEN linkedid IS NULL OR linkedid = '' THEN :linkedid
            ELSE linkedid
        END,
        updated_at = NOW()
    WHERE id = :id
");

// По умолчанию смотрим только пропущенные за последние callback_window_hours часов.
// Полный пересчёт: php sync_callbacks-new.php --all
$all = in_array('--all', isset($argv) ? $argv : [], true);
$since = $all ? '1000-01-01 00:00:00'
              : date('Y-m-d H:i:s', time() - (int)$config['callback_window_hours'] * 3600);
$afterId = 0;

while (true) {

$selectMissed->execute([':after_id' => $afterId, ':since' => $since]);
$rows = $selectMissed->fetchAll();
$selectMissed->closeCursor();

if (!$rows) {
    break;
}

$afterId = (int)$rows[count($rows) - 1]['id'];

$pdo->beginTransaction();

try {
    foreach ($rows as $row) {
        $reportId = (int)$row['id'];
        $callTime = $row['call_time'];
        $callId = isset($row['callid']) ? trim((string)$row['callid']) : '';
        $clientNumber = normalizePhone($row['client_number']);

        if (!$clientNumber || !$callTime) {
            continue;
        }

        $dateFrom = date('Y-m-d H:i:s', strtotime($callTime));
        $dateTo = date('Y-m-d H:i:s', strtotime($callTime . ' +' . (int)$config['callback_window_hours'] . ' hours'));

        $findCallback->execute([
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
            ':dcontext' => $config['callback_dcontext'],
            ':dst' => $clientNumber,
            ':cnum_like' => $config['internal_prefix'] . '%',
        ]);

        $cdr = $findCallback->fetch();
        $findCallback->closeCursor();

        if ($cdr) {
            $agentExt = extFromCnum(isset($cdr['cnum']) ? $cdr['cnum'] : null, $config['internal_prefix']);
            $disposition = strtoupper(trim((string)$cdr['disposition']));
            $billsec = (int)$cdr['billsec'];

            // NO ANSWER в cdr может иметь billsec > 0, поэтому смотрим только disposition
            if ($disposition === 'ANSWERED') {
                $newStatus = 'missed_callback_answered';
            } else {
                $newStatus = 'missed_callback_noanswer';
            }

            $updateReport->execute([
                ':status' => $newStatus,
                ':callback_time' => $cdr['calldate'],
                ':callback_agent_ext' => $agentExt,
                ':callback_disposition' => $disposition,
                ':linkedid' => isset($cdr['linkedid']) ? $cdr['linkedid'] : null,
                ':id' => $reportId,
            ]);

            continue;
        }

        $findSelfCallback->execute([
            ':client_number' => $clientNumber,
            ':source_id' => $reportId,
            ':date_from' => $dateFrom,
            ':date_to' => $dateTo,
        ]);

        $selfCall = $findSelfCallback->fetch();
        $findSelfCallback->closeCursor();

        if ($selfCall) {
            $selfCallId = (int)$selfCall['id'];
            $selfCallCallId = isset($selfCall['callid']) ? trim((string)$selfCall['callid']) : '';

            if ($selfCallId !== $reportId && $selfCallCallId !== $callId) {
                $updateReport->execute([
                    ':status' => 'missed_callback_self',
                    ':callback_time' => $selfCall['call_time'],
                    ':callback_agent_ext' => isset($selfCall['agent_ext']) ? $selfCall['agent_ext'] : null,
                    ':callback_disposition' => 'SELF_CALLBACK',
                    ':linkedid' => $selfCallCallId !== '' ? $selfCallCallId : null,
                    ':id' => $reportId,
                ]);

                continue;
            }
        }
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

} // while
