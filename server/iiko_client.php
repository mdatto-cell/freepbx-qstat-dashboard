<?php
// Библиотека проекта. Не запускает AGI и не выводит AGI-команды.
class QstatIikoClient
{
    private $config;
    private $enabled;
    private $token = null;
    private $tokenUntil = 0;
    private $cache = array();
    private $authFailed = false;

    public function __construct($config, $enabled)
    {
        $this->config = $config;
        $this->enabled = (bool)$enabled;
    }

    private function post($path, $payload, $token = null)
    {
        if (!function_exists('curl_init')) {
            error_log('qstat iiko: PHP cURL extension is missing');
            return array(0, null);
        }
        $headers = array('Content-Type: application/json');
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => rtrim($this->config['base_url'], '/') . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int)$this->config['connect_timeout']),
            CURLOPT_TIMEOUT => max(1, (int)$this->config['request_timeout']),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($body === false) {
            error_log('qstat iiko: transport error ' . $errno);
            return array(0, null);
        }
        $data = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            error_log('qstat iiko: HTTP ' . $code . ' or invalid JSON');
        }
        return array($code, is_array($data) ? $data : null);
    }

    private function token()
    {
        if ($this->authFailed) return null;
        if ($this->token !== null && time() < $this->tokenUntil) return $this->token;
        if (empty($this->config['api_login']) || empty($this->config['organization_id'])) {
            $this->authFailed = true;
            error_log('qstat iiko: configuration is incomplete');
            return null;
        }
        list($code, $data) = $this->post('/api/1/access_token', array('apiLogin'=>$this->config['api_login']));
        if ($code < 200 || $code >= 300 || empty($data['token']) || !is_string($data['token'])) {
            $this->authFailed = true;
            return null;
        }
        $this->token = $data['token'];
        // Локальное время переиспользования; при HTTP 401 получаем новый токен.
        $this->tokenUntil = time() + 600;
        return $this->token;
    }

    public function lookupName($phone)
    {
        $phone = preg_replace('/\D+/', '', (string)$phone);
        if ($phone === '' || !$this->enabled) return null;
        if (array_key_exists($phone, $this->cache)) return $this->cache[$phone];
        $this->cache[$phone] = null;
        $token = $this->token();
        if ($token === null) return null;
        $payload = array('phone'=>$phone, 'type'=>'phone', 'organizationId'=>$this->config['organization_id']);
        list($code, $data) = $this->post('/api/1/loyalty/iiko/customer/info', $payload, $token);
        if ($code === 401) {
            $this->token = null;
            $token = $this->token();
            if ($token === null) return null;
            list($code, $data) = $this->post('/api/1/loyalty/iiko/customer/info', $payload, $token);
        }
        if ($code < 200 || $code >= 300 || !is_array($data)) return null;
        $parts = array();
        foreach (array('name','surname') as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $part = trim($data[$key]);
                if ($part !== '' && $part !== '0') $parts[] = $part;
            }
        }
        $name = trim(implode(' ', $parts));
        $this->cache[$phone] = $name !== '' ? $name : null;
        return $this->cache[$phone];
    }
}

// Ручная проверка только через CLI: php iiko_client.php 89631020673
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    require __DIR__ . '/qstat.inc.php';
    if (empty($argv[1])) { fwrite(STDERR, "Usage: php iiko_client.php PHONE\n"); exit(1); }
    $phone = preg_replace('/\D+/', '', $argv[1]);
    if ($phone === '') { fwrite(STDERR, "Invalid phone\n"); exit(1); }
    $client = new QstatIikoClient(isset($iiko_config) ? $iiko_config : array(), !empty($ClientNameLookup_iiko));
    $name = $client->lookupName($phone);
    echo ($name !== null ? $name : $phone) . PHP_EOL;
}
