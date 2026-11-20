#!/usr/bin/env php
<?php

include 'qstat.inc.php';

date_default_timezone_set('Europe/Moscow');

// Защита от параллельного запуска
$lockFp = fopen('/tmp/sync_outbound_calls-new.lock', 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$config = [
    'dsn'             => $dsn,
    'user'            => $dbUser,
    'password'        => $dbPass,
    'system_id'       => $system_id,
    'internal_prefix' => $internal_prefix,      // '17' из qstat.inc.php
    'sync_start'      => '2026-10-01 00:00:00', // самая ранняя точка синхронизации
    'lookback_hours'  => 6,                     // cdr пишется по окончании звонка: перечитываем хвост
    'state_key'       => 'outbound_last_calldate',
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

// Верхняя граница: самая свежая запись cdr
$maxStmt = $pdo->query("SELECT MAX(calldate) FROM cdr");
$maxCdr = $maxStmt->fetchColumn();
$maxStmt->closeCursor();
if (!$maxCdr) {
    exit(0);
}

$lastState = getState($pdo, $config['state_key'], $config['sync_start']);
if ($lastState < $config['sync_start']) {
    $lastState = $config['sync_start'];
}

// Начало чтения = последняя точка минус lookback, но не раньше sync_start
$from = date('Y-m-d H:i:s', strtotime($lastState) - (int)$config['lookback_hours'] * 3600);
if ($from < $config['sync_start']) {
    $from = $config['sync_start'];
}
$to = date('Y-m-d H:i:s', strtotime($maxCdr) + 1);

$likePrefix = addcslashes($config['internal_prefix'], '%_\\') . '%';

$select = $pdo->prepare("
    SELECT calldate, uniqueid, sequence, src, dst, cnum,
           duration, billsec, disposition, recordingfile
    FROM cdr
    WHERE lastapp = 'Dial'
      AND cnum LIKE :prefix_cnum
      AND dst NOT LIKE :prefix_dst
      AND calldate >= :date_from
      AND calldate <  :date_to
    ORDER BY calldate ASC
");

$upsert = $pdo->prepare("
    INSERT INTO cc_outbound_calls (
        system_id, call_time, callid, cdr_sequence, did_number, client_number,
        agent_number, wait_seconds, talk_seconds, status, recording_file,
        created_at, updated_at
    ) VALUES (
        :system_id, :call_time, :callid, :cdr_sequence, :did_number, :client_number,
        :agent_number, :wait_seconds, :talk_seconds, :status, :recording_file,
        NOW(), NOW()
    )
    ON DUPLICATE KEY UPDATE
        call_time      = VALUES(call_time),
        did_number     = VALUES(did_number),
        client_number  = VALUES(client_number),
        agent_number   = VALUES(agent_number),
        wait_seconds   = VALUES(wait_seconds),
        talk_seconds   = VALUES(talk_seconds),
        status         = VALUES(status),
        recording_file = VALUES(recording_file),
        updated_at     = NOW()
");

$newest = $lastState;

try {
    // Идём окнами по суткам: первый запуск (бэкфилл с 2026-10-01) не грузит всё одним запросом
    $winFrom = $from;
    while ($winFrom < $to) {
        $winTo = date('Y-m-d H:i:s', strtotime($winFrom) + 86400);
        if ($winTo > $to) {
            $winTo = $to;
        }

        $select->execute([
            ':prefix_cnum' => $likePrefix,
            ':prefix_dst'  => $likePrefix,
            ':date_from'   => $winFrom,
            ':date_to'     => $winTo,
        ]);
        $rows = $select->fetchAll();
        $select->closeCursor();

        $pdo->beginTransaction();
        foreach ($rows as $r) {
            $duration = (int)$r['duration'];
            $billsec  = (int)$r['billsec'];

            $upsert->execute([
                ':system_id'      => $config['system_id'],
                ':call_time'      => $r['calldate'],
                ':callid'         => $r['uniqueid'],
                ':cdr_sequence'   => (int)$r['sequence'],
                ':did_number'     => $r['src'],
                ':client_number'  => $r['dst'],
                ':agent_number'   => $r['cnum'],
                // ожидание (набор/гудки) = общая длительность минус разговор
                ':wait_seconds'   => max(0, $duration - $billsec),
                ':talk_seconds'   => $billsec,
                ':status'         => $r['disposition'],
                ':recording_file' => $r['recordingfile'] !== '' ? $r['recordingfile'] : null,
            ]);
            if ($r['calldate'] > $newest) {
                $newest = $r['calldate'];
            }
        }
        $pdo->commit();

        $winFrom = $winTo;
    }

    setState($pdo, $config['state_key'], $newest);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
