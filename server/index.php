<?php
include __DIR__ . '/qstat.inc.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['call_csrf'])) $_SESSION['call_csrf'] = bin2hex(openssl_random_pseudo_bytes(32));
$callCsrf = $_SESSION['call_csrf'];
session_write_close();
$showIncomingSummary = !empty($showIncomingSummary);
$call_agents = isset($call_agents) && is_array($call_agents) ? $call_agents : array();
$showClientName = true;

function db() {
    static $pdo = null;
    global $dsn, $dbUser, $dbPass;
    if ($pdo === null) {
        $pdo = new PDO($dsn, $dbUser, $dbPass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    }
    return $pdo;
}

// debug
function getDebugData() {
    $params = array();
    $where = buildFilters($params);

    $debug = array(
        'get' => $_GET,
        'where' => $where,
        'params' => $params,
    );

    $sql1 = "
        SELECT
            id, call_time, did_number, client_number, queue_number, status,
            callback_time, callback_agent_ext, callback_disposition
        FROM cc_calls_report
        WHERE status = 'missed_callback_self'
        ORDER BY call_time DESC
        LIMIT 20
    ";
    $debug['raw_self_rows'] = db()->query($sql1)->fetchAll();

    $sql2 = "
        SELECT
            id, call_time, did_number, client_number, queue_number, status,
            callback_time, callback_agent_ext, callback_disposition
        FROM cc_calls_report
        WHERE $where AND status = 'missed_callback_self'
        ORDER BY call_time DESC
        LIMIT 20
    ";
    $stmt2 = db()->prepare($sql2);
    $stmt2->execute($params);
    $debug['filtered_self_rows'] = $stmt2->fetchAll();

    $sql3 = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'missed_callback_self' THEN 1 ELSE 0 END) AS sum_self,
            COUNT(CASE WHEN status = 'missed_callback_self' THEN 1 END) AS count_self
        FROM cc_calls_report
        WHERE $where
    ";
    $stmt3 = db()->prepare($sql3);
    $stmt3->execute($params);
    $debug['aggregate_check'] = $stmt3->fetch();

    return $debug;
}
//// debug

function normalizeDate($value, $endOfDay) {
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
}

function mapName($value, $map) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return isset($map[$value]) ? $map[$value] : $value;
}

function buildFilters(&$params) {
    $where = array('1=1');

    $dateFrom = isset($_GET['date_from']) ? normalizeDate($_GET['date_from'], false) : null;
    $dateTo   = isset($_GET['date_to']) ? normalizeDate($_GET['date_to'], true) : null;
    $did      = isset($_GET['did']) ? trim($_GET['did']) : '';
    $queue    = isset($_GET['queue']) ? trim($_GET['queue']) : '';
    $status   = isset($_GET['status']) ? trim($_GET['status']) : '';
    $search   = isset($_GET['search']) ? trim($_GET['search']) : '';

    if ($dateFrom) {
        $where[] = 'call_time >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $where[] = 'call_time <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    if ($did !== '') {
        $where[] = 'did_number = :did';
        $params[':did'] = $did;
    }
    if ($queue !== '') {
        $where[] = 'queue_number = :queue';
        $params[':queue'] = $queue;
    }
    if ($status !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }
    if ($search !== '') {
        $where[] = '(
            callid LIKE :search OR
            did_number LIKE :search OR
            client_number LIKE :search OR
            queue_number LIKE :search OR
            agent_ext LIKE :search
        )';
        $params[':search'] = '%' . $search . '%';
    }

    return implode(' AND ', $where);
}

function getFilterOptions($column) {
    $allowed = array('did_number', 'queue_number');
    if (!in_array($column, $allowed, true)) {
        return array();
    }
    $sql = "SELECT DISTINCT $column AS value FROM cc_calls_report WHERE $column IS NOT NULL AND $column <> '' ORDER BY $column";
    return db()->query($sql)->fetchAll();
}

/*function getKpiData() {
    $params = array();
    $where = buildFilters($params);
    $sql = "
        SELECT
            COUNT(*) AS total_calls,
            SUM(CASE WHEN status = 'missed_no_callback' THEN 1 ELSE 0 END) AS missed_no_callback,
            SUM(CASE WHEN status = 'missed_callback_answered' THEN 1 ELSE 0 END) AS missed_callback_answered,
            SUM(CASE WHEN status = 'missed_callback_noanswer' THEN 1 ELSE 0 END) AS missed_callback_noanswer,
            SUM(CASE WHEN status = 'missed_callback_self' THEN 1 ELSE 0 END) AS missed_callback_self
        FROM cc_calls_report
        WHERE $where
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}
*/
function getKpiData() {
    $params = array();
    $where = buildFilters($params);
    $sql = "
        SELECT
            COUNT(*) AS total_calls,
            COALESCE(SUM(CASE WHEN status = 'missed_no_callback' THEN 1 ELSE 0 END), 0) AS missed_no_callback,
            COALESCE(SUM(CASE WHEN status = 'missed_callback_answered' THEN 1 ELSE 0 END), 0) AS missed_callback_answered,
            COALESCE(SUM(CASE WHEN status = 'missed_callback_noanswer' THEN 1 ELSE 0 END), 0) AS missed_callback_noanswer,
            COALESCE(SUM(CASE WHEN status = 'missed_callback_self' THEN 1 ELSE 0 END), 0) AS missed_callback_self,
            COALESCE(SUM(CASE WHEN status = 'miss_night_1' THEN 1 ELSE 0 END), 0) AS miss_night_1,
            COALESCE(SUM(CASE WHEN status = 'miss_night_2' THEN 1 ELSE 0 END), 0) AS miss_night_2
        FROM cc_calls_report
        WHERE $where
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}
function getDidSummary() {
    $params = array();
    $where = buildFilters($params);
    $sql = "
        SELECT
            did_number,
            COUNT(*) AS total_calls,
            SUM(CASE WHEN status IN ('missed_no_callback','miss_night_1','miss_night_2') THEN 1 ELSE 0 END) AS missed_no_callback,
            SUM(CASE WHEN status = 'missed_callback_answered' THEN 1 ELSE 0 END) AS missed_callback_answered,
            SUM(CASE WHEN status = 'missed_callback_noanswer' THEN 1 ELSE 0 END) AS missed_callback_noanswer,
            SUM(CASE WHEN status = 'missed_callback_self' THEN 1 ELSE 0 END) AS missed_callback_self
        FROM cc_calls_report
        WHERE $where
        GROUP BY did_number
        ORDER BY total_calls DESC, did_number ASC
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getCalls() {
    global $did_array, $queue_array;
    $params = array();
    $where = buildFilters($params);
    $sql = "
        SELECT
            id,
            call_time,
            did_number,
            client_number,
	    client_name,
            queue_number,
            agent_ext,
            wait_seconds,
            talk_seconds,
            recording_url,
            recording_file,
            callid,
            status,
            callback_time,
            callback_agent_ext,
            callback_disposition
        FROM cc_calls_report
        WHERE $where
        ORDER BY call_time DESC
        LIMIT 1000
    ";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['did_label'] = mapName(isset($row['did_number']) ? $row['did_number'] : '', $did_array);
        $row['queue_label'] = mapName(isset($row['queue_number']) ? $row['queue_number'] : '', $queue_array);
    }
    return $rows;
}


function outgoingFilters(&$params) {
    $where = array('1=1');
    foreach (array('date_from', 'date_to') as $key) {
        $v = isset($_GET[$key]) ? normalizeDate($_GET[$key], $key === 'date_to') : null;
        if ($v) { $where[] = 'call_time ' . ($key === 'date_from' ? '>=' : '<=') . ' :' . $key; $params[':'.$key] = $v; }
    }
    foreach (array('did'=>'did_number', 'agent'=>'agent_number') as $key=>$column) {
        if (isset($_GET[$key]) && trim($_GET[$key]) !== '') {
            $where[] = $column.' = :'.$key; $params[':'.$key] = trim($_GET[$key]);
        }
    }
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    if ($status === 'ANSWERED') $where[] = "status = 'ANSWERED'";
    if ($status === 'NO ANSWER' || $status === 'BUSY') $where[] = "status IN ('NO ANSWER', 'BUSY')";
    return implode(' AND ', $where);
}
function outgoingOptions($column) {
    if (!in_array($column, array('did_number','agent_number'), true)) return array();
    return db()->query("SELECT DISTINCT $column AS value FROM cc_outbound_calls WHERE $column IS NOT NULL AND $column <> '' ORDER BY $column")->fetchAll();
}
function outgoingData() {
    $params = array(); $where = outgoingFilters($params);
    $stmt = db()->prepare("SELECT COUNT(*) AS total_calls,
        COALESCE(SUM(status='ANSWERED'),0) AS answered,
        COALESCE(SUM(status IN ('NO ANSWER','BUSY')),0) AS noanswer
        FROM cc_outbound_calls WHERE $where");
    $stmt->execute($params); $kpi = $stmt->fetch();
    $stmt = db()->prepare("SELECT id, call_time, callid, agent_number, client_number,
        did_number, wait_seconds, talk_seconds, recording_file, status
        FROM cc_outbound_calls WHERE $where ORDER BY call_time DESC, id DESC");
    $stmt->execute($params);
    return array('kpi'=>$kpi, 'calls'=>$stmt->fetchAll());
}
$page = isset($_GET['page']) ? trim((string)$_GET['page']) : 'incoming';
if (!in_array($page, array('incoming','outgoing'), true)) $page = 'incoming';

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($page === 'outgoing' ? outgoingData() : array(
        'kpi' => getKpiData(),
        'didSummary' => $showIncomingSummary ? getDidSummary() : array(),
        'calls' => getCalls(),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$didOptions = $page === 'outgoing' ? outgoingOptions('did_number') : getFilterOptions('did_number');
$queueOptions = $page === 'incoming' ? getFilterOptions('queue_number') : array();
$agentOptions = $page === 'outgoing' ? outgoingOptions('agent_number') : array();
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Статистика Звонков</title>
  <link rel="stylesheet" href="dashboard.css">
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="topbar-inner">
      <div class="head-left">
        <div class="brand">
          <div class="logo" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17V7"></path><path d="M12 17V4"></path><path d="M19 17v-8"></path><path d="M3 20h18"></path></svg>
          </div>
          <div>
            <h1><?php echo $MainName;?></h1>
            <p>Статистика звонков FreePBX</p>
          </div>
        </div>
        <nav class="top-nav" aria-label="Разделы">
          <a href="?page=incoming" class="<?php echo $page === 'incoming' ? 'active' : ''; ?>">Входящие звонки</a>
          <a href="?page=outgoing" class="<?php echo $page === 'outgoing' ? 'active' : ''; ?>">Исходящие звонки</a>
          <a href="../qstat-old/index.php">Старая Версия</a>
        </nav>
      </div>
      <div class="actions">
        <button class="btn" data-theme-toggle>Тема</button>
      </div>
    </div>
<?php if ($page === 'incoming') { ?>
    <div class="topbar-inner filters">
      <div class="filter-grid">
        <div class="field"><label for="date_from">Дата от</label><input id="date_from" type="date"></div>
        <div class="field"><label for="date_to">Дата до</label><input id="date_to" type="date"></div>
        <div class="field"><label for="did">Входящий номер</label><select id="did"><option value="">Все DID</option><?php foreach ($didOptions as $opt) { $v = htmlspecialchars($opt['value']); $label = htmlspecialchars(mapName($opt['value'], $did_array)); ?><option value="<?php echo $v; ?>"><?php echo $label; ?></option><?php } ?></select></div>
        <div class="field"><label for="queue">Очередь</label><select id="queue"><option value="">Все очереди</option><?php foreach ($queueOptions as $opt) { $v = htmlspecialchars($opt['value']); $label = htmlspecialchars(mapName($opt['value'], $queue_array)); ?><option value="<?php echo $v; ?>"><?php echo $label; ?></option><?php } ?></select></div>
        <div class="field"><label for="status">Статус</label><select id="status"><option value="">Все статусы</option><option value="answered">Отвеченные</option><option value="missed_no_callback">Пропущенные</option><option value="missed_callback_answered">Перезвонили</option><option value="missed_callback_noanswer">Не дозвонились</option><option value="missed_callback_self">Клиент перезвонил</option><option value="miss_night_1">Ночь: Нужно перезвонить</option><option value="miss_night_2">Ночь: Перезвонит сам</option></select></div>
        <div class="field"><label for="search">Поиск</label><input id="search" type="text" placeholder="Клиент, агент или uniqueid"></div>
      </div>
    </div>

 <div class="panel-head kpi-stack">
    <section class="kpis" aria-label="Основные показатели">
      <article class="kpi kpi-total"><span class="label">Входящие</span><strong class="value" id="kpi-total">0</strong></article>
      <article class="kpi kpi-danger"><span class="label">Пропущенные</span><strong class="value" id="kpi-missed">0</strong></article>
      <article class="kpi kpi-success"><span class="label">Перезвонили</span><strong class="value" id="kpi-callback-ok">0</strong></article>
      <article class="kpi kpi-warning"><span class="label">Не дозвонились</span><strong class="value" id="kpi-callback-fail">0</strong></article>
      <article class="kpi kpi-info"><span class="label">Клиент перезвонил</span><strong class="value" id="kpi-callback-self">0</strong></article>
    </section>
    <section class="kpis kpis-night" aria-label="Ночные пропущенные">
      <article class="kpi kpi-night-call"><span class="label">Ночь: Нужно перезвонить</span><strong class="value" id="kpi-night-1">0</strong></article>
      <article class="kpi kpi-night-self"><span class="label">Ночь: Перезвонит сам</span><strong class="value" id="kpi-night-2">0</strong></article>
    </section>
</div>
<?php } elseif ($page === 'outgoing') { ?>
    <div class="topbar-inner filters"><div class="filter-grid outgoing-filters">
      <div class="field"><label for="date_from">Дата от</label><input id="date_from" type="date"></div>
      <div class="field"><label for="date_to">Дата до</label><input id="date_to" type="date"></div>
      <div class="field"><label for="agent">Агент</label><select id="agent"><option value="">Все агенты</option><?php foreach ($agentOptions as $opt) { ?><option value="<?php echo htmlspecialchars($opt['value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opt['value'], ENT_QUOTES, 'UTF-8'); ?></option><?php } ?></select></div>
      <div class="field"><label for="did">Исходящий номер</label><select id="did"><option value="">Все номера</option><?php foreach ($didOptions as $opt) { ?><option value="<?php echo htmlspecialchars($opt['value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($opt['value'], ENT_QUOTES, 'UTF-8'); ?></option><?php } ?></select></div>
      <div class="field"><label for="status">Статус</label><select id="status"><option value="">Все статусы</option><option value="BUSY">Занят</option><option value="ANSWERED">Ответили</option><option value="NO ANSWER">Не ответили</option></select></div>
    </div></div>
   <div class="panel-head">
     <section class="kpis outgoing-kpis">
      <article class="kpi kpi-out-answered"><span class="label">Ответили</span><strong class="value" id="kpi-out-answered">0</strong></article>
      <article class="kpi kpi-warning"><span class="label">Не ответили</span><strong class="value" id="kpi-out-noanswer">0</strong></article>
    </section>
   </div>
<?php } ?>
  </header>

  <main class="page" id="page-root">
<?php if ($page === 'incoming') { ?>
    <article class="card">
      <div class="panel-head">
        <div>
          <h2>Журнал входящих звонков</h2>
        </div>
        <span class="badge badge-neutral" id="visible-count">0 строк</span>
      </div>
      <div class="panel-body">
        <div class="calls-wrap">
          <table>
            <thead>
              <tr>
                <th>Дата/время</th><th>Входящий номер</th><th>Номер клиента</th><th>Очередь</th><th>Агент</th><th>Ожидание</th><th>Разговор</th><th>Запись</th><th>Статус</th><!--<th>callid</th>-->
              </tr>
            </thead>
            <tbody id="calls-body"></tbody>
          </table>
        </div>
      </div>
    </article>

<?php if ($showIncomingSummary) { ?>
    <article class="card">
      <div class="panel-head">
        <div>
          <h2>Итого</h2>
        </div>
      </div>
      <div class="panel-body">
        <table>
          <thead>
            <tr>
              <th>Входящий номер</th>
              <th>Всего</th>
              <th>Пропущенные, не перезвонили</th>
              <th>Перезвонили, ответили</th>
              <th>Перезвонили, не ответили</th>
              <th>Клиент перезвонил сам</th>
            </tr>
          </thead>
          <tbody id="did-body"></tbody>
        </table>
      </div>
    </article>
<?php } ?>
<?php } elseif ($page === 'outgoing') { ?>
    <article class="card"><div class="panel-head"><h2>Журнал исходящих звонков</h2><span class="badge badge-neutral" id="visible-count">0 строк</span></div>
    <div class="panel-body"><div class="calls-wrap outgoing-calls"><table><thead><tr>
      <th>Дата/время</th><th>Агент</th><th>Номер клиента</th><th>Исходящий номер</th><th>Ожидание</th><th>Разговор</th><th>Запись</th><th>Статус</th>
    </tr></thead><tbody id="calls-body"></tbody></table></div><div id="outgoing-error" class="muted" role="alert"></div></div></article>
<?php } ?>
  </main>
</div>

<div class="recording-modal" id="recording-modal" aria-hidden="true">
  <div class="recording-dialog" role="dialog" aria-modal="true" aria-labelledby="recording-title">
    <div class="recording-head">
      <h3 id="recording-title">Запись звонка</h3>
      <button class="btn" type="button" id="recording-close">Закрыть</button>
    </div>
    <div class="recording-body">
      <div id="recording-meta" class="muted">Запись не выбрана</div>
      <audio id="recording-player" controls preload="none"></audio>
    </div>
  </div>
</div>


<div class="recording-modal" id="call-modal" aria-hidden="true">
  <div class="recording-dialog" role="dialog" aria-modal="true" aria-labelledby="call-title">
    <div class="recording-head"><h3 id="call-title">Позвонить клиенту</h3><button class="btn" type="button" id="call-close">Закрыть</button></div>
    <div class="recording-body"><div id="call-client" class="mono"></div><div id="call-agent-buttons" class="call-agent-buttons"></div></div>
  </div>
</div>

<script>
(function () {
  var currentPage = <?php echo json_encode($page); ?>;
  var isIncomingPage = <?php echo $page === 'incoming' ? 'true' : 'false'; ?>;
  var pageRoot = document.getElementById('page-root');
  var controls = {
    date_from: document.getElementById('date_from'),
    date_to: document.getElementById('date_to'),
    did: document.getElementById('did'),
    agent: document.getElementById('agent'),
    queue: document.getElementById('queue'),
    status: document.getElementById('status'),
    search: document.getElementById('search')
  };

  var didMap = <?php echo json_encode($did_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var queueMap = <?php echo json_encode($queue_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var modal = document.getElementById('recording-modal');
  var player = document.getElementById('recording-player');
  var recordingMeta = document.getElementById('recording-meta');

  function mapLabel(value, map) {
    return map[value] || value || '';
  }

  function qs(params) {
    var search = new URLSearchParams(params);
    return search.toString();
  }

  function debounce(fn, delay) {
    var t = null;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, delay);
    };
  }

  function formatSeconds(v) {
    var n = parseInt(v || 0, 10);
    if (!n) return '00:00';
    var m = String(Math.floor(n / 60)).padStart(2, '0');
    var s = String(n % 60).padStart(2, '0');
    return m + ':' + s;
  }

  function escapeHtml(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function rowClass(status) {
    if (status === 'missed_no_callback') return 'row-missed';
    if (status === 'missed_callback_answered') return 'row-callback-ok';
    if (status === 'missed_callback_noanswer') return 'row-callback-fail';
    if (status === 'missed_callback_self') return 'row-callback-self';
    if (status === 'miss_night_1' || status === 'miss_night_2') return 'row-missed';
    return '';
  }

  function statusBadge(status) {
    if (status === 'missed_no_callback') return '<span class="badge badge-danger">Пропущен</span>';
    if (status === 'missed_callback_answered') return '<span class="badge badge-success">Перезвонили</span>';
    if (status === 'missed_callback_noanswer') return '<span class="badge badge-warning">Не дозвонились</span>';
    if (status === 'missed_callback_self') return '<span class="badge badge-info">Клиент перезвонил</span>';
    if (status === 'miss_night_1') return '<span class="badge badge-night-call">Ночь: нужно перезвонить</span>';
    if (status === 'miss_night_2') return '<span class="badge badge-night-self">Ночь: перезвонит сам</span>';
    return '<span class="badge badge-neutral">Отвечен</span>';
  }

  function currentParams() {
    return {
      ajax: '1',
      page: currentPage,
      agent: controls.agent ? controls.agent.value : '',
      date_from: controls.date_from ? controls.date_from.value : '',
      date_to: controls.date_to ? controls.date_to.value : '',
      did: controls.did ? controls.did.value : '',
      queue: controls.queue ? controls.queue.value : '',
      status: controls.status ? controls.status.value : '',
      search: controls.search ? controls.search.value : ''
    };
  }

  function applyUrl() {

    var params = currentParams();
    delete params.ajax;
    params.page = currentPage;
    var cleaned = {};
    Object.keys(params).forEach(function (k) {
      if (params[k] !== '') cleaned[k] = params[k];
    });
    var query = qs(cleaned);
    history.replaceState(null, '', query ? ('?' + query) : location.pathname);
  }

  function hydrateFromUrl() {

    var search = new URLSearchParams(window.location.search);
    ['date_from','date_to','did','agent','queue','status','search'].forEach(function (key) {
      if (search.has(key) && controls[key]) controls[key].value = search.get(key);
    });
    if (!controls.date_from.value || !controls.date_to.value) {
      var now = new Date();
      var local = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0');
      if (!controls.date_from.value) controls.date_from.value = local;
      if (!controls.date_to.value) controls.date_to.value = local;
    }
  }

  function renderKpi(kpi) {
    document.getElementById('kpi-total').textContent = kpi.total_calls || 0;
    var n1 = parseInt(kpi.miss_night_1, 10) || 0;
    var n2 = parseInt(kpi.miss_night_2, 10) || 0;
    document.getElementById('kpi-missed').textContent = (parseInt(kpi.missed_no_callback, 10) || 0) + n1 + n2;
    document.getElementById('kpi-night-1').textContent = n1;
    document.getElementById('kpi-night-2').textContent = n2;
    document.getElementById('kpi-callback-ok').textContent = kpi.missed_callback_answered || 0;
    document.getElementById('kpi-callback-fail').textContent = kpi.missed_callback_noanswer || 0;
    document.getElementById('kpi-callback-self').textContent = kpi.missed_callback_self || 0;
  }

  function renderDid(summary) {
    var body = document.getElementById('did-body');
    if (!body) return;
    if (!summary.length) {
      body.innerHTML = '<tr><td colspan="6" class="muted">Нет данных</td></tr>';
      return;
    }
    body.innerHTML = summary.map(function (row) {
      var didNumber = row.did_number || '';
      var didLabel = mapLabel(didNumber, didMap);
      return '<tr>' +
        '<td><strong>' + escapeHtml(didLabel) + '</strong><span class="dim mono">' + escapeHtml(didNumber) + '</span></td>' +
        '<td class="mono">' + escapeHtml(row.total_calls || 0) + '</td>' +
        '<td class="mono">' + escapeHtml(row.missed_no_callback || 0) + '</td>' +
        '<td class="mono">' + escapeHtml(row.missed_callback_answered || 0) + '</td>' +
        '<td class="mono">' + escapeHtml(row.missed_callback_noanswer || 0) + '</td>' +
        '<td class="mono">' + escapeHtml(row.missed_callback_self || 0) + '</td>' +
      '</tr>';
    }).join('');
  }



  function renderCalls(rows) {
    var body = document.getElementById('calls-body');
    document.getElementById('visible-count').textContent = (rows.length || 0) + ' строк';
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="10" class="muted">Нет данных по выбранным фильтрам</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (r) {
      var didNumber = r.did_number || '';
      var queueNumber = r.queue_number || '';
      var rec = r.callid ? '<button class="btn btn-link js-play-recording" type="button" data-callid="' + escapeHtml(r.callid || '') + '">слушать</button>' : '—';
      var agent = r.agent_ext && r.agent_ext !== '' ? r.agent_ext : 'нет';
      return '<tr class="' + rowClass(r.status) + '">' +
        '<td class="mono">' + escapeHtml(r.call_time) + '</td>' +
        '<td><strong>' + escapeHtml(r.did_label || mapLabel(didNumber, didMap)) + '</strong><span class="dim mono">' + escapeHtml(didNumber) + '</span></td>' +
'<td><strong>' + escapeHtml(r.client_name || r.client_number || '') + '</strong><span class="dim mono">' + clientNumberButton(r.client_number) + '</span></td>' +
        '<td><strong>' + escapeHtml(r.queue_label || mapLabel(queueNumber, queueMap)) + '</strong><span class="dim mono">' + escapeHtml(queueNumber) + '</span></td>' +
        '<td class="mono">' + escapeHtml(agent) + '</td>' +
        '<td class="mono">' + formatSeconds(r.wait_seconds) + '</td>' +
        '<td class="mono">' + formatSeconds(r.talk_seconds) + '</td>' +
        '<td>' + rec + '</td>' +
        /*'<td class="mono">' + escapeHtml(r.callid || '') + '</td>' +*/
        '<td>' + statusBadge(r.status) + '</td>' +
        /*'<td class="mono">' + escapeHtml(r.callid || '') + '</td>' +*/
      '</tr>';
    }).join('');
  }



/*        '<td class="mono">' + escapeHtml(r.client_number || '') + '</td>' + 
function renderCalls(rows) {
  var body = document.getElementById('calls-body');
  document.getElementById('visible-count').textContent = (rows.length || 0) + ' строк';
  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="10" class="muted">Нет данных по выбранным фильтрам</td></tr>';
    return;
  }
  body.innerHTML = rows.map(function (r) {
    var didNumber = r.did_number || '';
    var queueNumber = r.queue_number || '';
    var clientNumber = r.client_number || '';
    var clientName = r.client_name || '';
    var rec = r.callid ? '<button class="btn btn-link js-play-recording" type="button" data-callid="' + escapeHtml(r.callid || '') + '">слушать</button>' : '—';
    var agent = r.agent_ext && r.agent_ext !== '' ? r.agent_ext : 'нет';

    var clientCell = '<td class="mono">' + escapeHtml(clientNumber) + '</td>';
    if (showClientName && clientName !== '') {
      clientCell = '<td><strong>' + escapeHtml(clientName) + '</strong><span class="dim mono">' + escapeHtml(clientNumber) + '</span></td>';
    }

    return '<tr class="' + rowClass(r.status) + '">' +
      '<td class="mono">' + escapeHtml(r.call_time) + '</td>' +
      '<td><strong>' + escapeHtml(r.did_label || mapLabel(didNumber, didMap)) + '</strong><span class="dim mono">' + escapeHtml(didNumber) + '</span></td>' +
      clientCell +
      '<td><strong>' + escapeHtml(r.queue_label || mapLabel(queueNumber, queueMap)) + '</strong><span class="dim mono">' + escapeHtml(queueNumber) + '</span></td>' +
      '<td class="mono">' + escapeHtml(agent) + '</td>' +
      '<td class="mono">' + formatSeconds(r.wait_seconds) + '</td>' +
      '<td class="mono">' + formatSeconds(r.talk_seconds) + '</td>' +
      '<td>' + rec + '</td>' +
      '<td>' + statusBadge(r.status) + '</td>' +
    '</tr>';
  }).join('');
}

*/

  function renderOutgoing(data) {
    var kpi = data.kpi || {}, rows = data.calls || [];
    document.getElementById('kpi-out-answered').textContent = kpi.answered || 0;
    document.getElementById('kpi-out-noanswer').textContent = kpi.noanswer || 0;
    document.getElementById('visible-count').textContent = rows.length + ' строк';
    document.getElementById('calls-body').innerHTML = rows.length ? rows.map(function(r) {
      var noanswer = r.status === 'NO ANSWER' || r.status === 'BUSY';
      var badge = r.status === 'ANSWERED' ? '<span class="badge badge-neutral">Ответили</span>' :
        noanswer ? '<span class="badge badge-warning">Не ответили</span>' : '<span class="badge badge-neutral">' + escapeHtml(r.status) + '</span>';
      var rec = r.recording_file ? '<button class="btn btn-link js-play-recording" data-outgoing-id="'+escapeHtml(r.id)+'" data-callid="'+escapeHtml(r.callid)+'" type="button">слушать</button>' : '—';
      return '<tr class="'+(noanswer?'row-callback-fail':'')+'">' +
        '<td class="mono">'+escapeHtml(r.call_time)+'</td>' +
        '<td class="mono">'+escapeHtml(r.agent_number)+'</td>' +
        '<td class="mono">'+clientNumberButton(r.client_number)+'</td>' +
        '<td class="mono">'+escapeHtml(r.did_number)+'</td>' +
        '<td class="mono">'+formatSeconds(r.wait_seconds)+'</td>' +
        '<td class="mono">'+formatSeconds(r.talk_seconds)+'</td>' +
        '<td>'+rec+'</td><td>'+badge+'</td></tr>';
    }).join('') : '<tr><td colspan="8" class="muted">Нет данных по выбранным фильтрам</td></tr>';
  }


  var callAgents = <?php echo json_encode((object)$call_agents, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var callToken = <?php echo json_encode($callCsrf); ?>;
  var callModal = document.getElementById('call-modal');
  var callClient = '', callSent = false, callOpener = null;
//  function clientNumberButton(number) {
//    if (!number) return '—';
//    return '<button type="button" class="client-number js-call-client" data-client="'+escapeHtml(number)+'">'+escapeHtml(number)+'</button>';
//  }

function clientNumberButton(number) {
  if (!number) return '—';

  return '<a href="#call-modal"'
    + ' class="client-number js-call-client"'
    + ' data-client="' + escapeHtml(number) + '"'
    + ' aria-haspopup="dialog"'
    + ' aria-controls="call-modal">'
    + escapeHtml(number)
    + '</a>';
}

  function closeCall() {
    callModal.classList.remove('open'); callModal.setAttribute('aria-hidden','true');
    if (callOpener && document.contains(callOpener)) callOpener.focus();
  }
  function openCall(number, opener) {
    callClient = number; callSent = false; callOpener = opener;
    document.getElementById('call-client').textContent = 'Клиент: ' + number;
    var agents = Object.keys(callAgents);
    document.getElementById('call-agent-buttons').innerHTML = agents.length ? agents.map(function(agent) {
      return '<button class="btn js-call-agent" type="button" data-agent="'+escapeHtml(agent)+'">'+escapeHtml(agent)+' — '+escapeHtml(callAgents[agent])+'</button>';
    }).join('') : '<span class="muted">Агенты не настроены</span>';
    callModal.classList.add('open'); callModal.setAttribute('aria-hidden','false');
    document.getElementById('call-close').focus();
  }
  document.addEventListener('click', function(event) {
    var client = event.target.closest('.js-call-client');
    if (client) { openCall(client.getAttribute('data-client'),client); return; }
    var agent = event.target.closest('.js-call-agent');
    if (agent && callModal.classList.contains('open') && !callSent) {
      callSent = true;
      var url = 'call.php?from='+encodeURIComponent(agent.getAttribute('data-agent'))+'&to='+encodeURIComponent(callClient)+'&token='+encodeURIComponent(callToken);
      fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',keepalive:true}).catch(function(e){console.error('Не удалось отправить запрос звонка',e);});
      closeCall(); return;
    }
    if (event.target.id === 'call-close' || event.target === callModal) closeCall();
  });
  document.addEventListener('keydown',function(event){
    if (event.key === 'Escape' && callModal.classList.contains('open')) closeCall();
  });

  function openRecording(callid, outgoingId) {
    player.pause();
    player.removeAttribute('src');
    player.load();
    var url = outgoingId ? 'recordings.php?outgoing_id=' + encodeURIComponent(outgoingId) : 'recordings.php?uniqueid=' + encodeURIComponent(callid);
    player.src = url;
    recordingMeta.textContent = callid ? ('UniqueID: ' + callid) : 'Запись звонка';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    player.load();
  }

  function closeRecording() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    player.pause();
    player.removeAttribute('src');
    player.load();
  }

  function loadData() {

    pageRoot.classList.add('loading');
    fetch('index.php?' + qs(currentParams()), { headers: { 'X-Requested-With': 'XMLHttpRequest' } }) 
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (isIncomingPage) {
          renderKpi(data.kpi || {}); renderDid(data.didSummary || []); renderCalls(data.calls || []);
        } else {
          renderOutgoing(data);
          document.getElementById('outgoing-error').textContent = '';
        }
        applyUrl();
      })
      .catch(function (err) {
        console.error(err);
        if (!isIncomingPage) document.getElementById('outgoing-error').textContent = 'Ошибка загрузки данных: ' + err.message;
      })
      .finally(function () {
        pageRoot.classList.remove('loading');
      });
  }

  var debouncedLoad = debounce(loadData, 250);
  Object.keys(controls).forEach(function (key) {
    if (!controls[key]) return;
    var evt = key === 'search' ? 'input' : 'change';
    controls[key].addEventListener(evt, debouncedLoad);
    if (key !== 'search') controls[key].addEventListener('input', debouncedLoad);
  });

  document.querySelector('[data-theme-toggle]').addEventListener('click', function () {
    var root = document.documentElement;
    root.setAttribute('data-theme', root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  });

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('.js-play-recording');
    if (btn) {
      openRecording(btn.getAttribute('data-callid') || '', btn.getAttribute('data-outgoing-id'));
      return;
    }
    if (event.target.id === 'recording-close' || event.target === modal) {
      closeRecording();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal.classList.contains('open')) {
      closeRecording();
    }
  });

  hydrateFromUrl();
  loadData();
  if (true) {
    setInterval(loadData, 30000);
  }
})();
</script>
</body>
</html>

