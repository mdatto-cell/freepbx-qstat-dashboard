<?php
require __DIR__ . '/qstat.inc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function callFail($code, $message) {
    http_response_code($code);
    error_log('qstat call: ' . $message);
    echo json_encode(array('ok'=>false, 'error'=>$message));
    exit;
}
if (session_status() === PHP_SESSION_NONE) session_start();
$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
$expected = isset($_SESSION['call_csrf']) ? $_SESSION['call_csrf'] : '';
if ($expected === '' || !hash_equals($expected, $token)) {
    session_write_close(); callFail(403, 'Invalid request token');
}
$from = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : '';
$to = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : '';
if (!preg_match('/^\d{2,6}$/D', $from) || !isset($call_agents[$from])) {
    session_write_close(); callFail(400, 'Agent is not allowed');
}
if (!preg_match('/^\+?[0-9 ()-]{6,24}$/D', $to)) {
    session_write_close(); callFail(400, 'Invalid client number');
}
$to = preg_replace('/\D/', '', $to);
if (strlen($to) === 11 && ($to[0] === '7' || $to[0] === '8')) $to = '8' . substr($to,1);
if (strlen($to) === 6) $to = $call_local_prefix . $to;
if (!preg_match('/^\d{7,15}$/D', $to)) {
    session_write_close(); callFail(400, 'Invalid number length');
}
if (!empty($_SESSION['call_last_request']) && time() - $_SESSION['call_last_request'] < 2) {
    session_write_close(); callFail(429, 'Too many call requests');
}
$_SESSION['call_last_request'] = time();
session_write_close();

// Читаем ответ AMI блоками до пустой строки, без sleep().
function amiResponse($socket, $actionId) {
    while (!feof($socket)) {
        $fields = array();
        while (($line = fgets($socket, 4096)) !== false) {
            if (trim($line) === '') break;
            $parts = explode(':', trim($line), 2);
            if (count($parts) === 2) $fields[$parts[0]] = trim($parts[1]);
        }
        if ($line === false) return false;
        if (isset($fields['ActionID']) && $fields['ActionID'] === $actionId && isset($fields['Response'])) return $fields;
    }
    return false;
}
function amiSend($socket, $packet) {
    $length = strlen($packet); $offset = 0;
    while ($offset < $length) {
        $written = fwrite($socket, substr($packet, $offset));
        if (!$written) return false;
        $offset += $written;
    }
    return true;
}
$socket = @fsockopen($call_ami_host, (int)$call_ami_port, $errno, $errstr, 3);
if (!$socket) callFail(502, 'AMI connection failed');
stream_set_timeout($socket, 3);
$loginId = 'qstat-login-' . uniqid();
amiSend($socket, "Action: Login\r\nActionID: $loginId\r\nUsername: $call_ami_user\r\nSecret: $call_ami_secret\r\nEvents: off\r\n\r\n");
$login = amiResponse($socket, $loginId);
if (!$login || $login['Response'] !== 'Success') {
    fclose($socket); callFail(502, 'AMI login failed');
}
$actionId = 'qstat-call-' . uniqid();
$packet = "Action: Originate\r\nActionID: $actionId\r\nChannel: Local/$from@from-internal\r\nTimeout: 30000\r\nCallerID: 1C-outcall <$from>\r\nExten: $to\r\nContext: from-internal\r\nPriority: 1\r\nAsync: true\r\n\r\n";
if (!amiSend($socket, $packet)) { fclose($socket); callFail(502,'AMI write failed'); }
$response = amiResponse($socket, $actionId);
amiSend($socket, "Action: Logoff\r\n\r\n");
fclose($socket);
if (!$response || $response['Response'] !== 'Success') callFail(502,'AMI originate rejected');
echo json_encode(array('ok'=>true));
