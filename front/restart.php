<?php
/**
 * Self Service Deploy — strona self-service + zlecenie restartu.
 *
 * GET  /plugins/selfservicedeploy/front/restart.php?token=<plaintext>
 *   -> strona z nazwą komputera i przyciskiem "Zrestartuj usługę"
 * POST /plugins/selfservicedeploy/front/restart.php?token=<plaintext>
 *   -> weryfikacja (token, CSRF, rate limit, blokada) -> zakolejkowanie
 *      deploymentu "Restart MyService" dla tego komputera -> audit log
 *
 * Komenda restartu usług żyje NA STAŁE w pakiecie GLPI Inventory
 * (glpi-package/volvo_restart.bat). Ten skrypt nigdy nie przyjmuje
 * command / service_name / package_id / kodu — jedyny parametr to token.
 *
 * Mechanizm (zweryfikowany w kodzie glpiinventory 1.5.x:
 * inc/taskview.class.php::prepareTaskjobs, ajax/restart_job.php):
 * tworzymy wiersz PluginGlpiinventoryTaskjobstate ze stanem PREPARED dla
 * agenta komputera + log TASK_PREPARED. Agent pobiera go przy pollu
 * (b/deploy getJobs) i wykonuje pakiet z uprawnieniami usługi agenta.
 */

include('../../../inc/includes.php');
require_once(__DIR__ . '/../config.php');

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/* GLPI 11: anonimowe strony legacy nie zawsze dostają globalny $DB —
   zapewniamy połączenie z config_db.php (wzorzec jak w skrypcie CLI). */
$DB = ssd_db_ensure();
if (!($DB instanceof DBmysql)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Błąd konfiguracji: brak połączenia z bazą GLPI (config_db.php nie znaleziony).';
    exit;
}

/* ============================================================ helpers */

function ssd_hash_token($plain)
{
    return hash('sha256', $plain);
}

function ssd_client_ip()
{
    if (ssd_cfg_bool('SSD_TRUST_PROXY', false) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function ssd_log($computer_id, $token_id, $action, $result, $source_ip, $detail = '')
{
    global $DB;
    $DB->insert('glpi_plugin_selfservicedeploy_logs', [
        'computer_id' => (int)$computer_id,
        'token_id'    => $token_id === null ? null : (int)$token_id,
        'action'      => $action,
        'result'      => $result,
        'source_ip'   => $source_ip,
        'detail'      => $detail,
    ]);
}

function ssd_page($title, $message, $code = 200)
{
    http_response_code($code);
    $badge = $code < 400 ? 'ok' : 'err';
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title) . '</title><style>';
    echo 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;margin:0;min-height:100vh;';
    echo 'display:flex;align-items:center;justify-content:center;background:#f2f4f8;color:#1c2430}';
    echo '.card{background:#fff;border-radius:12px;padding:40px 44px;width:min(440px,92vw);';
    echo 'box-shadow:0 8px 30px rgba(20,30,60,.12);text-align:center}';
    echo '.badge{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;';
    echo 'text-transform:uppercase;letter-spacing:.4px;margin-bottom:14px}';
    echo '.badge.ok{background:#e5f6ec;color:#1e7a45}.badge.err{background:#fdeaea;color:#b93229}';
    echo 'h1{font-size:19px;margin:0 0 10px}p{font-size:14.5px;color:#41506b;margin:0;line-height:1.5}';
    echo '</style></head><body><div class="card">';
    echo '<span class="badge ' . $badge . '">' . ($code < 400 ? 'sukces' : 'informacja') . '</span>';
    echo '<h1>' . htmlspecialchars($title) . '</h1>';
    echo '<p>' . htmlspecialchars($message) . '</p>';
    echo '</div></body></html>';
    exit;
}

/* ============================================================ token */

$token = $_GET['token'] ?? '';
if (!is_string($token) || $token === '') {
    ssd_page('Nieprawidłowy link', 'Brak tokenu.', 404);
}

$row = null;
foreach ($DB->request([
    'FROM'  => 'glpi_plugin_selfservicedeploy_tokens',
    'WHERE' => ['token_hash' => ssd_hash_token($token)],
    'LIMIT' => 1,
]) as $data) {
    $row = $data;
}
if ($row === null) {
    ssd_page('Nieprawidłowy link', 'Link nie istnieje lub został usunięty.', 404);
}
if (!(int)$row['enabled']) {
    ssd_page('Link wyłączony', 'Ten link został wyłączony przez administratora.', 403);
}

$computer_id = (int)$row['computer_id'];
$token_id    = (int)$row['id'];
$source_ip   = ssd_client_ip();

/* ======================================================== GET: strona */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $csrf = bin2hex(random_bytes(24));
    setcookie('ssd_csrf', $csrf, [
        'expires'  => time() + 1800,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => ssd_cfg_bool('SSD_COOKIE_SECURE', false),
    ]);
    $label = htmlspecialchars(ssd_cfg_str('SSD_SERVICE_LABEL', 'usługa'));
    $name  = htmlspecialchars($row['computer_name'] ?: ('computer_' . $computer_id));
    $action = htmlspecialchars('/plugins/selfservicedeploy/front/restart.php?token=' . $token);
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Restart usługi</title><style>';
    echo 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;margin:0;min-height:100vh;';
    echo 'display:flex;align-items:center;justify-content:center;background:#f2f4f8;color:#1c2430}';
    echo '.card{background:#fff;border-radius:12px;padding:40px 44px;width:min(440px,92vw);';
    echo 'box-shadow:0 8px 30px rgba(20,30,60,.12);text-align:center}';
    echo 'h1{font-size:20px;margin:0 0 6px}.computer{color:#41506b;margin:0 0 26px;font-size:15px}';
    echo '.computer strong{color:#1c2430}button{width:100%;padding:13px 16px;font-size:16px;font-weight:600;';
    echo 'cursor:pointer;color:#fff;background:#d4382d;border:0;border-radius:8px}';
    echo 'button:hover{background:#b93229}.hint{margin-top:18px;font-size:12.5px;color:#6b7688}';
    echo '</style></head><body><div class="card">';
    echo '<h1>Zrestartuj ' . $label . '</h1>';
    echo '<p class="computer">Komputer: <strong>' . $name . '</strong></p>';
    echo '<form method="post" action="' . $action . '">';
    echo '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">';
    echo '<button type="submit">Zrestartuj usługę</button></form>';
    echo '<p class="hint">Restart wykona agent GLPI z uprawnieniami usługi systemowej. Operacja jest logowana.</p>';
    echo '</div></body></html>';
    exit;
}

/* ======================================================== POST: akcja */

/* --- 1. CSRF (double-submit: cookie == pole formularza) --- */
$csrf_ok = isset($_COOKIE['ssd_csrf'], $_POST['csrf'])
    && is_string($_POST['csrf'])
    && hash_equals($_COOKIE['ssd_csrf'], $_POST['csrf']);
if (!$csrf_ok) {
    ssd_log($computer_id, $token_id, 'restart', 'invalid_csrf', $source_ip);
    ssd_page('Odmowa', 'Nieprawidłowy token CSRF.', 403);
}
if (ssd_cfg_str('SSD_APP_PUBLIC_URL', '') !== '') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $base   = rtrim(ssd_cfg_str('SSD_APP_PUBLIC_URL', ''), '/');
    if ($origin === '' || (strpos($origin, $base) !== 0 && $origin !== $base)) {
        ssd_log($computer_id, $token_id, 'restart', 'invalid_origin', $source_ip);
        ssd_page('Odmowa', 'Odrzucono żądanie z nieznanego źródła (Origin).', 403);
    }
}

/* --- 2. rate limit: 1 restart / komputer / 2 min --- */
$cutoff = date('Y-m-d H:i:s', time() - ssd_cfg_int('SSD_RATE_LIMIT_SECONDS', 120));
$recent = countElementsInTable('glpi_plugin_selfservicedeploy_logs', [
    'computer_id' => $computer_id,
    'action'      => 'restart',
    'result'      => 'scheduled',
    'timestamp'   => ['>=', $cutoff],
]);
if ($recent >= ssd_cfg_int('SSD_RATE_LIMIT_MAX', 1)) {
    $rl_max = ssd_cfg_int('SSD_RATE_LIMIT_MAX', 1);
    $rl_sec = ssd_cfg_int('SSD_RATE_LIMIT_SECONDS', 120);
    ssd_log($computer_id, $token_id, 'restart', 'rate_limited', $source_ip,
        'limit ' . $rl_max . '/' . $rl_sec . 's');
    ssd_page('Zbyt częste żądanie',
        'Restart można wykonać maksymalnie ' . $rl_max . ' raz(y) na '
        . $rl_sec . ' sekund. Spróbuj później.', 429);
}

/* --- 3. blokada równoległych restartów (INSERT ... ON DUPLICATE KEY) --- */
ssd_db_query('DELETE FROM `glpi_plugin_selfservicedeploy_locks`
              WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ' . (int)ssd_cfg_int('SSD_LOCK_TTL_SECONDS', 300) . ' SECOND)');
ssd_db_query('INSERT INTO `glpi_plugin_selfservicedeploy_locks` (`computer_id`, `created_at`)
              VALUES (' . (int)$computer_id . ', NOW())
              ON DUPLICATE KEY UPDATE `computer_id` = `computer_id`');
$locked = ($DB->affectedRows() === 1);
if (!$locked) {
    ssd_log($computer_id, $token_id, 'restart', 'locked', $source_ip);
    ssd_page('Restart w toku', 'Restart dla tego komputera jest już w trakcie realizacji.', 409);
}

try {
    /* --- 4. zakolejkowanie deploymentu przez klasy glpiinventory --- */
    $result = ssd_schedule($computer_id);
    if (!$result['ok']) {
        ssd_log($computer_id, $token_id, 'restart', 'glpi_error', $source_ip,
            '[' . $result['code'] . '] ' . $result['message']);
        ssd_page('Błąd GLPI', $result['message'], $result['http']);
    }

    /* --- 5. sukces: last_used_at + audit --- */
    $DB->update('glpi_plugin_selfservicedeploy_tokens',
        ['last_used_at' => date('Y-m-d H:i:s')],
        ['id' => $token_id]);
    ssd_log($computer_id, $token_id, 'restart', 'scheduled', $source_ip,
        'jobstate_ids=' . implode(',', $result['jobstate_ids']));
    $display_name = $row['computer_name'] ?: ('computer_' . $computer_id);
    ssd_page('Restart został zlecony',
        'Restart ' . ssd_cfg_str('SSD_SERVICE_LABEL', 'usługa') . ' na komputerze „' . $display_name . '” '
        . 'został zlecony. Agent GLPI wykona go w ciągu kilku minut.', 200);
} finally {
    ssd_db_query('DELETE FROM `glpi_plugin_selfservicedeploy_locks`
                  WHERE `computer_id` = ' . (int)$computer_id);
}

/* ================================================= zakolejkowanie joba */

/**
 * Tworzy stan PREPARED dla (job, pakiet, agent komputera) — dokładnie tak,
 * jak robi to GUI glpiinventory (prepareTaskjobs / restartJob).
 *
 * @return array ['ok'=>bool, 'message'=>string, 'code'=>string,
 *                'http'=>int, 'jobstate_ids'=>int[], 'agent_woken'=>bool]
 */
function ssd_schedule($computer_id)
{
    $fail = function ($http, $message, $code) {
        return ['ok' => false, 'http' => $http, 'message' => $message, 'code' => $code];
    };

    /* plugin GLPI Inventory musi być aktywny */
    if (!Plugin::isPluginActive('glpiinventory') || !defined('PLUGIN_GLPI_INVENTORY_DIR')) {
        return $fail(500, 'Plugin GLPI Inventory nie jest aktywny.', 'plugin_missing');
    }
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/taskjobview.class.php');
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/taskview.class.php');
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/task.class.php');
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/taskjob.class.php');
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/taskjobstate.class.php');
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/taskjoblog.class.php');
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/agentmodule.class.php');
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/deploypackage.class.php');
    require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/agentwakeup.class.php');

    /* komputer musi istnieć */
    $computer = new Computer();
    if (!$computer->getFromDB($computer_id)) {
        return $fail(404, 'Komputer nie istnieje w GLPI.', 'computer_not_found');
    }

    /* komputer musi mieć agenta */
    $agent = new Agent();
    if (!$agent->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer_id])) {
        return $fail(404, 'Brak agenta GLPI zarejestrowanego dla tego komputera.', 'no_agent');
    }
    $agents_id = (int)$agent->fields['id'];

    /* moduł deploy musi być włączony dla agenta (jak w b/deploy getJobs) */
    $agentmodule = new PluginGlpiinventoryAgentmodule();
    if (!$agentmodule->isAgentCanDo('DEPLOY', $agents_id)) {
        return $fail(403, 'Moduł deploy jest wyłączony dla tego agenta.', 'deploy_disabled');
    }

    /* skonfigurowany job */
    if (ssd_cfg_int('SSD_DEPLOY_TASKJOB_ID', 0) <= 0) {
        return $fail(500, 'SSD_DEPLOY_TASKJOB_ID nie jest skonfigurowany.', 'not_configured');
    }
    $job = new PluginGlpiinventoryTaskjob();
    if (!$job->getFromDB(ssd_cfg_int('SSD_DEPLOY_TASKJOB_ID', 0))) {
        return $fail(500, 'Skonfigurowany taskjob nie istnieje.', 'bad_config');
    }
    if ($job->fields['method'] !== 'deployinstall') {
        return $fail(500, 'Skonfigurowany taskjob nie jest jobem deployinstall.', 'bad_config');
    }

    /* task musi być aktywny — inaczej glpiinventory anuluje stan przy pollu */
    $task = new PluginGlpiinventoryTask();
    if (!$task->getFromDB((int)$job->fields['plugin_glpiinventory_tasks_id'])) {
        return $fail(500, 'Task skonfigurowanego taskjoba nie istnieje.', 'bad_config');
    }
    if ((int)$task->fields['is_active'] !== 1) {
        return $fail(500, 'Task deploy jest wyłączony w GLPI (is_active = 0).', 'task_disabled');
    }

    /* pakiety: jawna konfiguracja albo z targets joba */
    $packages = [];
    if (ssd_cfg_int('SSD_DEPLOY_PACKAGE_ID', 0) > 0) {
        $packages[] = ssd_cfg_int('SSD_DEPLOY_PACKAGE_ID', 0);
    } else {
        $targets = importArrayFromDB($job->fields['targets']);
        foreach ($targets as $target) {
            if (is_array($target) && isset($target['PluginGlpiinventoryDeployPackage'])) {
                $packages[] = (int)$target['PluginGlpiinventoryDeployPackage'];
            }
        }
    }
    $packages = array_values(array_unique($packages));
    if (count($packages) === 0) {
        return $fail(500, 'Brak skonfigurowanego pakietu dla tego joba.', 'bad_config');
    }

    /* stany terminalne — ich obecność NIE blokuje nowego restartu */
    $terminal = [
        PluginGlpiinventoryTaskjobstate::FINISHED,
        PluginGlpiinventoryTaskjobstate::IN_ERROR,
        PluginGlpiinventoryTaskjobstate::POSTPONED,
        PluginGlpiinventoryTaskjobstate::CANCELLED,
    ];

    $jobstate = new PluginGlpiinventoryTaskjobstate();
    $joblog   = new PluginGlpiinventoryTaskjoblog();

    /* 0) walidacja pakietów + kontrola duplikatów — wszystkie na raz,
          żeby nie zlecić częściowo przy błędzie na ostatnim pakiecie */
    foreach ($packages as $package_id) {
        $pkg = new PluginGlpiinventoryDeployPackage();
        if (!$pkg->getFromDB($package_id)) {
            return $fail(500, 'Skonfigurowany pakiet deploy nie istnieje.', 'bad_config');
        }
        $running = $jobstate->find([
            'plugin_glpiinventory_taskjobs_id' => (int)$job->fields['id'],
            'items_id'                         => $package_id,
            'itemtype'                         => 'PluginGlpiinventoryDeployPackage',
            'agents_id'                        => $agents_id,
            'NOT'                              => ['state' => $terminal],
        ]);
        if (count($running) > 0) {
            return $fail(409, 'Restart dla tego komputera jest już w kolejce lub w trakcie.', 'already_pending');
        }
    }

    /* 1) utworzenie stanów PREPARED + logów joba */
    $jobstates_ids = [];
    foreach ($packages as $package_id) {
        $input = [
            'plugin_glpiinventory_taskjobs_id' => (int)$job->fields['id'],
            'items_id'                         => $package_id,
            'itemtype'                         => 'PluginGlpiinventoryDeployPackage',
            'state'                            => PluginGlpiinventoryTaskjobstate::PREPARED,
            'agents_id'                        => $agents_id,
            'uniqid'                           => uniqid('ssd_', true),
            'date_start'                       => null,
        ];
        $jobstates_id = $jobstate->add($input);
        if ($jobstates_id === false) {
            return $fail(500, 'Nie udało się utworzyć stanu joba deploy.', 'db_error');
        }
        $jobstates_ids[] = (int)$jobstates_id;

        $joblog->add([
            'plugin_glpiinventory_taskjobstates_id' => (int)$jobstates_id,
            'date'     => date('Y-m-d H:i:s'),
            'items_id' => $package_id,
            'itemtype' => 'PluginGlpiinventoryDeployPackage',
            'state'    => PluginGlpiinventoryTaskjoblog::TASK_PREPARED,
            'comment'  => 'Self-service restart requested via selfservicedeploy',
        ]);
    }

    /* 2) opcjonalnie: natychmiastowe wybudzenie agenta (port 62354) */
    $woken = false;
    if (ssd_cfg_bool('SSD_WAKEUP_AGENT', true)) {
        $woken = PluginGlpiinventoryAgentWakeup::wakeUp($agent);
    }

    return [
        'ok'           => true,
        'message'      => 'scheduled',
        'code'         => '',
        'http'         => 200,
        'jobstate_ids' => $jobstates_ids,
        'agent_woken'  => $woken,
    ];
}
