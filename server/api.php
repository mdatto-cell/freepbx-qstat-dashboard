<?php
/**
 * FreePBX QStat Dashboard - read-only JSON API
 * Compatible with the project's legacy PHP style (PHP 5.6+).
 *
 * GET ?type=incoming|outgoing
 *     &date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
 *     &status=...&limit=100&offset=0
 * Optional filters: incoming did, queue, search; outgoing did, agent.
 */
require_once __DIR__ . '/qstat.inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function apiRespond($httpCode, $payload)
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Optional CORS support for browser-based third-party frontends.
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$corsOrigins = isset($api_cors_origins) && is_array($api_cors_origins) ? $api_cors_origins : array();
if ($origin !== '' && (in_array('*', $corsOrigins, true) || in_array($origin, $corsOrigins, true))) {
    header('Access-Control-Allow-Origin: ' . (in_array('*', $corsOrigins, true) ? '*' : $origin));
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
}
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$pushAction = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if (($pushAction === 'register_push' || $pushAction === 'unregister_push') ? $method !== 'POST' : $method !== 'GET') {
    header('Allow: GET, POST, OPTIONS');
    apiRespond(405, array('ok' => false, 'error' => 'Method not allowed'));
}

// Authentication can be disabled in qstat.inc.php. Prefer Authorization: Bearer.
$authEnabled = !isset($api_auth_enabled) || $api_auth_enabled !== false;
if ($authEnabled) {
    $providedToken = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/^Bearer\s+(.+)$/i', trim($_SERVER['HTTP_AUTHORIZATION']), $matches)) {
            $providedToken = trim($matches[1]);
        }
    }
    // Fallback for clients that cannot set Authorization header. HTTPS is strongly recommended.
    if ($method === 'GET' && $providedToken === '' && isset($_GET['token']) && is_string($_GET['token'])) {
        $providedToken = $_GET['token'];
    }
    $configuredTokens = isset($api_tokens) && is_array($api_tokens) ? $api_tokens : array();
    $authorized = false;
    if ($providedToken !== '') {
        foreach ($configuredTokens as $knownToken) {
            if (is_string($knownToken) && $knownToken !== '' && hash_equals($knownToken, $providedToken)) {
                $authorized = true;
                break;
            }
        }
    }
    if (!$authorized) {
        apiRespond(401, array('ok' => false, 'error' => 'Unauthorized'));
    }
}

function apiDb()
{
    global $dsn, $dbUser, $dbPass;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($dsn, $dbUser, $dbPass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ));
    }
    return $pdo;
}

function apiDate($value, $endOfDay)
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $dt->format('Y-m-d') !== $value) {
        return false;
    }
    return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
}

function apiPagination()
{
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    if ($limit < 1) $limit = 100;
    if ($limit > 1000) $limit = 1000;
    if ($offset < 0) $offset = 0;
    return array($limit, $offset);
}

function apiAddDateFilters(&$where, &$params)
{
    if (isset($_GET['date_from']) && $_GET['date_from'] !== '') {
        $date = apiDate($_GET['date_from'], false);
        if ($date === false) apiRespond(400, array('ok' => false, 'error' => 'Invalid date_from; expected YYYY-MM-DD'));
        $where[] = 'call_time >= :date_from';
        $params[':date_from'] = $date;
    }
    if (isset($_GET['date_to']) && $_GET['date_to'] !== '') {
        $date = apiDate($_GET['date_to'], true);
        if ($date === false) apiRespond(400, array('ok' => false, 'error' => 'Invalid date_to; expected YYYY-MM-DD'));
        $where[] = 'call_time <= :date_to';
        $params[':date_to'] = $date;
    }
    if (isset($_GET['date_from'], $_GET['date_to']) && $_GET['date_from'] !== '' && $_GET['date_to'] !== '' && $_GET['date_from'] > $_GET['date_to']) {
        apiRespond(400, array('ok' => false, 'error' => 'date_from must be earlier than or equal to date_to'));
    }
}

function apiIncoming()
{
    global $did_array, $queue_array;
    $where = array('1=1');
    $params = array();
    apiAddDateFilters($where, $params);

    $statuses = array('answered', 'missed_no_callback', 'missed_callback_answered', 'missed_callback_noanswer', 'missed_callback_self', 'miss_night_1', 'miss_night_2');
    $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
    if ($status !== '') {
        if (!in_array($status, $statuses, true)) apiRespond(400, array('ok' => false, 'error' => 'Invalid incoming status', 'allowed' => $statuses));
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    foreach (array('did' => 'did_number', 'queue' => 'queue_number') as $key => $column) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $where[] = $column . ' = :' . $key;
            $params[':' . $key] = trim((string)$_GET[$key]);
        }
    }
    if (isset($_GET['search']) && trim((string)$_GET['search']) !== '') {
        $where[] = '(callid LIKE :search1 OR did_number LIKE :search2 OR client_number LIKE :search3 OR queue_number LIKE :search4 OR agent_ext LIKE :search5)';
        $searchValue = '%' . trim((string)$_GET['search']) . '%';
        $params[':search1'] = $searchValue;
        $params[':search2'] = $searchValue;
        $params[':search3'] = $searchValue;
        $params[':search4'] = $searchValue;
        $params[':search5'] = $searchValue;
    }

    $whereSql = implode(' AND ', $where);
    list($limit, $offset) = apiPagination();
    $count = apiDb()->prepare('SELECT COUNT(*) FROM cc_calls_report WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $sql = 'SELECT id, call_time, did_number, client_number, client_name, queue_number, agent_ext,
                   wait_seconds, talk_seconds, recording_url, recording_file, callid, status,
                   callback_time, callback_agent_ext, callback_disposition
            FROM cc_calls_report WHERE ' . $whereSql . ' ORDER BY call_time DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
    $stmt = apiDb()->prepare($sql);
    $stmt->execute($params);
    $calls = $stmt->fetchAll();
    foreach ($calls as &$call) {
        $did = isset($call['did_number']) ? (string)$call['did_number'] : '';
        $queue = isset($call['queue_number']) ? (string)$call['queue_number'] : '';
        $call['did_label'] = isset($did_array[$did]) ? $did_array[$did] : $did;
        $call['queue_label'] = isset($queue_array[$queue]) ? $queue_array[$queue] : $queue;
    }
    unset($call);

    $kpiSql = "SELECT COUNT(*) AS total_calls,
        COALESCE(SUM(status = 'missed_no_callback'), 0) AS missed_no_callback,
        COALESCE(SUM(status = 'missed_callback_answered'), 0) AS missed_callback_answered,
        COALESCE(SUM(status = 'missed_callback_noanswer'), 0) AS missed_callback_noanswer,
        COALESCE(SUM(status = 'missed_callback_self'), 0) AS missed_callback_self,
        COALESCE(SUM(status = 'miss_night_1'), 0) AS miss_night_1,
        COALESCE(SUM(status = 'miss_night_2'), 0) AS miss_night_2
        FROM cc_calls_report WHERE " . $whereSql;
    $kpi = apiDb()->prepare($kpiSql);
    $kpi->execute($params);

    return array(
        'ok' => true,
        'type' => 'incoming',
        'filters' => array('date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : null,
                           'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : null,
                           'status' => $status),
        'pagination' => array('limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + count($calls)) < $total),
        'kpi' => $kpi->fetch(),
        'calls' => $calls,
        'available_statuses' => array(
            array('value' => 'answered', 'label' => 'Отвеченные'),
            array('value' => 'missed_no_callback', 'label' => 'Пропущенные'),
            array('value' => 'missed_callback_answered', 'label' => 'Перезвонили'),
            array('value' => 'missed_callback_noanswer', 'label' => 'Не дозвонились'),
            array('value' => 'missed_callback_self', 'label' => 'Клиент перезвонил'),
            array('value' => 'miss_night_1', 'label' => 'Ночь: Нужно перезвонить'),
            array('value' => 'miss_night_2', 'label' => 'Ночь: Перезвонит сам')
        )
    );
}

function apiOutgoing()
{
    $where = array('1=1');
    $params = array();
    apiAddDateFilters($where, $params);

    $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
    $allowed = array('ANSWERED', 'NO ANSWER', 'BUSY');
    if ($status !== '') {
        if (!in_array($status, $allowed, true)) apiRespond(400, array('ok' => false, 'error' => 'Invalid outgoing status', 'allowed' => $allowed));
        // Each outgoing status is filtered independently. The combined KPI
        // 'Не дозвонились' is calculated separately for the unfiltered view.
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    foreach (array('did' => 'did_number', 'agent' => 'agent_number') as $key => $column) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $where[] = $column . ' = :' . $key;
            $params[':' . $key] = trim((string)$_GET[$key]);
        }
    }

    $whereSql = implode(' AND ', $where);
    list($limit, $offset) = apiPagination();
    $count = apiDb()->prepare('SELECT COUNT(*) FROM cc_outbound_calls WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = apiDb()->prepare('SELECT id, call_time, callid, agent_number, client_number, did_number,
                                     wait_seconds, talk_seconds, recording_file, status
                              FROM cc_outbound_calls WHERE ' . $whereSql . '
                              ORDER BY call_time DESC, id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
    $stmt->execute($params);
    $calls = $stmt->fetchAll();

    $kpiStmt = apiDb()->prepare("SELECT COUNT(*) AS total_calls,
        COALESCE(SUM(status = 'ANSWERED'), 0) AS answered,
        COALESCE(SUM(status IN ('NO ANSWER', 'BUSY')), 0) AS noanswer
        FROM cc_outbound_calls WHERE " . $whereSql);
    $kpiStmt->execute($params);

    return array(
        'ok' => true,
        'type' => 'outgoing',
        'filters' => array('date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : null,
                           'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : null,
                           'status' => $status),
        'pagination' => array('limit' => $limit, 'offset' => $offset, 'total' => $total, 'has_more' => ($offset + count($calls)) < $total),
        'kpi' => $kpiStmt->fetch(),
        'calls' => $calls,
        'available_statuses' => array(
            array('value' => 'ANSWERED', 'label' => 'Ответили'),
            array('value' => 'NO ANSWER', 'label' => 'Не ответили'),
            array('value' => 'BUSY', 'label' => 'Занят')
        )
    );
}

function apiPushAction($action)
{
    // Never allow push registration if Dashboard API authentication is disabled.
    global $authEnabled;
    if (!$authEnabled) apiRespond(403, array('ok' => false, 'error' => 'Push registration requires API authentication'));
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 8192) apiRespond(400, array('ok' => false, 'error' => 'Invalid body size'));
    $data = json_decode($raw, true);
    if (!is_array($data)) apiRespond(400, array('ok' => false, 'error' => 'Invalid JSON'));
    $installation = isset($data['installation_id']) ? trim((string)$data['installation_id']) : '';
    if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $installation)) apiRespond(400, array('ok' => false, 'error' => 'Invalid installation_id'));
    $pdo = apiDb();
    if ($action === 'unregister_push') {
        $stmt = $pdo->prepare('UPDATE cc_push_devices SET enabled=0, updated_at=NOW() WHERE installation_id=?');
        $stmt->execute(array($installation));
        apiRespond(200, array('ok' => true));
    }
    $platform = isset($data['platform']) ? strtolower(trim((string)$data['platform'])) : '';
    $token = isset($data['fcm_token']) ? trim((string)$data['fcm_token']) : '';
    if (!in_array($platform, array('android', 'ios'), true) || strlen($token) < 20 || strlen($token) > 512 || preg_match('/\s/', $token)) apiRespond(400, array('ok' => false, 'error' => 'Invalid platform or token'));
    $hash = hash('sha256', $token);
    $pdo->beginTransaction();
    try {
        // A rotated token may previously belong to a different installation.
        $stmt = $pdo->prepare('DELETE FROM cc_push_devices WHERE token_sha256=? AND installation_id<>?');
        $stmt->execute(array($hash, $installation));
        $stmt = $pdo->prepare('INSERT INTO cc_push_devices (installation_id,platform,fcm_token,token_sha256,enabled,created_at,updated_at,last_seen_at) VALUES (?,?,?,?,1,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE platform=VALUES(platform), fcm_token=VALUES(fcm_token), token_sha256=VALUES(token_sha256), enabled=1, updated_at=NOW(), last_seen_at=NOW()');
        $stmt->execute(array($installation, $platform, $token, $hash));
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); throw $e; }
    apiRespond(200, array('ok' => true));
}

try {
    if ($pushAction === 'register_push' || $pushAction === 'unregister_push') apiPushAction($pushAction);
    if ($pushAction === 'config') {
        $mobileName = isset($MobileName) && is_string($MobileName) && trim($MobileName) !== '' ? trim($MobileName) : 'DashBoard';
        apiRespond(200, array('ok' => true, 'mobile_name' => $mobileName));
    }
    if ($pushAction === 'agents') {
        $items = array();
        if (isset($call_agents) && is_array($call_agents)) foreach ($call_agents as $number => $name) $items[] = array('number' => (string)$number, 'name' => (string)$name);
        apiRespond(200, array('ok' => true, 'agents' => $items));
    }
    if ($pushAction !== '') apiRespond(400, array('ok' => false, 'error' => 'Unknown action'));
    $type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : 'incoming';
    if ($type === 'incoming') {
        apiRespond(200, apiIncoming());
    } elseif ($type === 'outgoing') {
        apiRespond(200, apiOutgoing());
    } else {
        apiRespond(400, array('ok' => false, 'error' => 'Invalid type; use incoming or outgoing'));
    }
} catch (Exception $e) {
    error_log('qstat API error: ' . $e->getMessage());
    apiRespond(500, array('ok' => false, 'error' => 'Internal server error'));
}
