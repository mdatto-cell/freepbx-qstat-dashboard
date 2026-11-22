<?php
include 'qstat.inc.php';



// MySQL connection
if (!function_exists('qstat_db_connect')) {
        function qstat_db_connect($dbname) {
                $connect = mysqli_connect('localhost', $GLOBALS['dbUser'], $GLOBALS['dbPass']);
                mysqli_select_db($connect, $dbname);
                return $connect;
        }
}



$uniqueid = isset($_GET['uniqueid']) ? trim((string)$_GET['uniqueid']) : '';
$outgoingId = isset($_GET['outgoing_id']) ? (int)$_GET['outgoing_id'] : 0;
if ($uniqueid === '' && $outgoingId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ERROR: UniqueID is empty!');
}

$connect = qstat_db_connect('asteriskcdrdb');
if (!$connect) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ERROR: DB connection failed');
}

$uniqueid_sql = mysqli_real_escape_string($connect, $uniqueid);
if ($outgoingId > 0) {
    $res = mysqli_query($connect, "SELECT call_time AS calldate, recording_file AS recordingfile, callid
        FROM cc_outbound_calls WHERE id = " . $outgoingId . " LIMIT 1");
} else {
    $res = mysqli_query($connect, "SELECT calldate, recordingfile FROM cdr WHERE uniqueid = '$uniqueid_sql' LIMIT 1");
}
if (!$res) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ERROR: CDR query failed: ' . mysqli_error($connect));
}

$row = mysqli_fetch_assoc($res);
mysqli_close($connect);

if (!$row || empty($row['recordingfile']) || empty($row['calldate'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ERROR: Recording not found in CDR');
}

$file = basename($row['recordingfile']);
$datetime = $row['calldate'];
$y = substr($datetime, 0, 4);
$m = substr($datetime, 5, 2);
$d = substr($datetime, 8, 2);

$sourceFile = "/var/spool/asterisk/monitor/$y/$m/$d/$file";
$mp3Dir = '/var/www/html/qstat/mp3';
$filename = preg_replace('/\.[^.]+$/', '', $file);
$mp3File = $mp3Dir . '/' . $filename . '.mp3';
$tmpWav = $mp3Dir . '/' . sha1($sourceFile) . '-' . getmypid() . '.wav';

if (!is_dir($mp3Dir)) {
    mkdir($mp3Dir, 0775, true);
}

if (!is_file($mp3File)) {
    if (!is_file($sourceFile)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('ERROR: Source recording file not found');
    }

    if (!copy($sourceFile, $tmpWav)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('ERROR: Unable to copy source recording');
    }

    $cmd = 'ffmpeg -y -i ' . escapeshellarg($tmpWav) . ' -ab 32k -f mp3 ' . escapeshellarg($mp3File) . ' 2>&1';
    exec($cmd, $out, $code);
    @unlink($tmpWav);

    if ($code !== 0 || !is_file($mp3File)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("ERROR: ffmpeg conversion failed\n" . implode("\n", $out));
    }
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . filesize($mp3File));
header('Content-Disposition: inline; filename="' . basename($mp3File) . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
readfile($mp3File);
exit;

