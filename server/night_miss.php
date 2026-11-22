#!/usr/bin/php -q
<?php
// Фиксация ночных пропущенных звонков в cc_calls_report.
// dialplan: AGI(night_miss.php,miss_night_1,${FROM_DID},${CHANNEL(linkedid)})
// Повторный вызов с тем же uniqueid (абонент нажал 1 в IVR) меняет статус существующей записи.
// Фоновое получение имени из iiko: night_miss.php --enrich <report_id> <phone>

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(20);

const QSTAT_DIR = '/var/www/html/qstat';   // каталог проекта (qstat.inc.php, iiko_client.php)
const NM_LOG    = '/var/log/asterisk/night_miss.log';
const NM_PHP    = '/usr/bin/php';

function nm_log($msg) {
    @file_put_contents(NM_LOG, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
}

// qstat.inc.php имеет закрывающий тег PHP в конце: любой вывод ломает AGI, поэтому буферизуем.
ob_start();
require QSTAT_DIR . '/qstat.inc.php';
require_once QSTAT_DIR . '/iiko_client.php';
ob_end_clean();

date_default_timezone_set('Europe/Moscow');

function nm_pdo() {
    global $dsn, $dbUser, $dbPass;
    return new PDO($dsn, $dbUser, $dbPass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3,
    ));
}

function nm_digits($v) {
    $d = preg_replace('/\D+/', '', (string)$v);
    return $d !== '' ? $d : null;
}

/* ---- режим 2: фоновое получение имени клиента из iiko ---- */
if (isset($argv[1]) && $argv[1] === '--enrich') {
    try {
        $id    = isset($argv[2]) ? (int)$argv[2] : 0;
        $phone = nm_digits(isset($argv[3]) ? $argv[3] : '');
        if ($id > 0 && $phone !== null) {
            $client = new QstatIikoClient(isset($iiko_config) ? $iiko_config : array(), !empty($ClientNameLookup_iiko));
            $name = $client->lookupName($phone);
            if ($name !== null) {
                $st = nm_pdo()->prepare(
                    "UPDATE cc_calls_report SET client_name = :n, updated_at = NOW()
                     WHERE id = :id AND status IN ('miss_night_1','miss_night_2')");
                $st->execute(array(':n' => $name, ':id' => $id));
            }
        }
    } catch (Throwable $e) {
        nm_log('enrich error: ' . $e->getMessage());
    }
    exit(0);
}

/* ---- режим 1: вызов из dialplan (AGI) ---- */
$agi = array();
stream_set_timeout(STDIN, 2);
while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '') break;
    $p = strpos($line, ':');
    if ($p !== false) $agi[substr($line, 0, $p)] = trim(substr($line, $p + 1));
}

try {
    $status = isset($argv[1]) ? trim($argv[1]) : '';
    if (!in_array($status, array('miss_night_1', 'miss_night_2'), true)) {
        throw new Exception('bad status: ' . $status);
    }

    $callid = isset($agi['agi_uniqueid']) ? $agi['agi_uniqueid'] : '';
    $client = nm_digits(isset($agi['agi_callerid']) ? $agi['agi_callerid'] : '');
    $did    = nm_digits(isset($argv[2]) ? $argv[2] : '');
    if ($did === null) $did = nm_digits(isset($agi['agi_dnid']) ? $agi['agi_dnid'] : '');
    $linked = (isset($argv[3]) && $argv[3] !== '') ? $argv[3] : null;

    if ($callid === '' || $client === null) {
        throw new Exception('no callid/callerid: ' . json_encode($agi));
    }

    $pdo = nm_pdo();
    $sid = isset($system_id) ? $system_id : 1;

    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT id, status FROM cc_calls_report WHERE callid = :c ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $st->execute(array(':c' => $callid));
    $row = $st->fetch();
    $rid = 0;
    $isNew = false;

    if ($row) {
        $rid = (int)$row['id'];
        $old = $row['status'];
        // Меняем только ночные записи. miss_night_1 («нужно перезвонить») не понижаем до miss_night_2.
        // Звонок, который уже ушёл в очередь или получил перезвон, не трогаем.
        if (in_array($old, array('miss_night_1', 'miss_night_2'), true)
            && !($old === 'miss_night_1' && $status === 'miss_night_2')
            && $old !== $status) {
            $pdo->prepare("UPDATE cc_calls_report
                           SET status = :s,
                               linkedid = COALESCE(linkedid, :l),
                               updated_at = NOW()
                           WHERE id = :id")
                ->execute(array(':s' => $status, ':l' => $linked, ':id' => $rid));
            nm_log("status $old -> $status, id=$rid, callid=$callid");
        }
    } else {
        $pdo->prepare("
            INSERT INTO cc_calls_report
                (system_id, queue_log_id, call_time, callid, did_number, client_number,
                 client_name, queue_number, agent_ext, wait_seconds, talk_seconds,
                 status, linkedid, debug_uniqueid, updated_at)
            VALUES
                (:sid, 0, :ct, :callid, :did, :client,
                 :client_name, NULL, NULL, 0, 0,
                 :status, :linked, :callid2, NOW())")
            ->execute(array(
                ':sid' => $sid, ':ct' => date('Y-m-d H:i:s'), ':callid' => $callid, ':callid2' => $callid,
                ':did' => $did, ':client' => $client, ':client_name' => $client,
                ':status' => $status, ':linked' => $linked,
            ));
        $rid = (int)$pdo->lastInsertId();
        $isNew = true;
    }

    $pdo->commit();

    // Имя из iiko запрашиваем только для новой записи
    if ($isNew && $rid > 0) {
        exec(NM_PHP . ' ' . escapeshellarg(__FILE__) . ' --enrich ' . $rid . ' ' . escapeshellarg($client) . ' >/dev/null 2>&1 &');
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    nm_log('agi error: ' . $e->getMessage());   // звонок не ломаем никогда
}
exit(0);
