<?php
/**
 * Self Service Deploy — DOMYŚLNE wartości konfiguracji.
 *
 * Konfigurację ustawia się w GLPI: Setup → General → zakładka
 * "Self Service Deploy" (zapis do glpi_configs, context='selfservicedeploy').
 * Stałe poniżej to wartości początkowe/fallback — wartości z UI je nadpisują.
 *
 * =====================================================================
 * 1) SSD_DEPLOY_TASKJOB_ID
 *    ID joba "Restart MyService" w glpi_plugin_glpiinventory_taskjobs.
 *    Wymagania (docs/02-konfiguracja-glpi-deploy.md):
 *      - method = 'deployinstall'
 *      - targets = [{"PluginGlpiinventoryDeployPackage":"<id pakietu>"}]
 *      - task nadrzędny: is_active = 1, bez okna czasowego
 *      - actors = statyczna grupa deploy z uprawnionymi komputerami
 *        (inaczej glpiinventory anuluje przygotowany stan przy pollu agenta)
 * =====================================================================
 * 2) SSD_DEPLOY_PACKAGE_ID (opcjonalnie)
 *    0 = pakiet(y) czytane z pola targets joba (zalecane).
 * =====================================================================
 * 3) SSD_WAKEUP_AGENT (opcjonalnie)
 *    Po zakolejkowaniu wybudza agenta (HTTP 62354, trusted hosts GLPI core),
 *    żeby restart wykonał się natychmiast zamiast przy kolejnym pollu.
 * =====================================================================
 * 4) Zabezpieczenia: rate limit, TTL blokady, cookie Secure, zaufany proxy,
 *    weryfikacja Origin (adres GLPI), etykieta usługi.
 * =====================================================================
 */

define('SSD_DEPLOY_TASKJOB_ID', 0);

define('SSD_DEPLOY_PACKAGE_ID', 0);

define('SSD_WAKEUP_AGENT', true);

/* rate limit: maks. SSD_RATE_LIMIT_MAX udanych restartów na komputer
   w SSD_RATE_LIMIT_SECONDS */
define('SSD_RATE_LIMIT_MAX', 1);
define('SSD_RATE_LIMIT_SECONDS', 120);

/* martwy lock równoległego restartu jest usuwany po tym czasie (s) */
define('SSD_LOCK_TTL_SECONDS', 300);

/* cookie CSRF: ustaw true, gdy GLPI działa za HTTPS */
define('SSD_COOKIE_SECURE', false);

/* ufaj nagłówkowi X-Forwarded-For (tylko za reverse proxy) */
define('SSD_TRUST_PROXY', false);

/* adres GLPI (bez końcowego "/") — weryfikacja nagłówka Origin/Referer
   w POST; puste = sprawdzenie pominięte */
define('SSD_APP_PUBLIC_URL', '');

/* etykieta usługi pokazywana użytkownikowi na stronie */
define('SSD_SERVICE_LABEL', 'usługa');

/**
 * Wykonanie zapytania DDL w sposób odporny na wersję GLPI:
 * doQuery() od 10.0.11, query() wcześniej.
 * (definicja w config.php i hook.php — te pliki nigdy nie ładują się razem)
 */
if (!function_exists('ssd_db_query')) {
    function ssd_db_query($sql)
    {
        global $DB;
        if (method_exists($DB, 'doQuery')) {
            return $DB->doQuery($sql);
        }
        return $DB->query($sql);
    }
}

/**
 * Odczyt wartości konfiguracji: glpi_configs (context='selfservicedeploy')
 * nadpisuje stałe z config.php. Wartości z UI ustawiane w zakładce
 * Setup → General → Self Service Deploy.
 */
if (!function_exists('ssd_cfg')) {
    function ssd_cfg($key, $default = null)
    {
        static $cache = null;
        if ($cache === null) {
            $cache = class_exists('Config')
                ? Config::getConfigurationValues('selfservicedeploy', [])
                : [];
        }
        if (is_array($cache) && array_key_exists($key, $cache) && $cache[$key] !== '') {
            return $cache[$key];
        }
        if (defined($key)) {
            return constant($key);
        }
        return $default;
    }

    function ssd_cfg_bool($key, $default = false)
    {
        return (bool)(int)ssd_cfg($key, $default ? 1 : 0);
    }

    function ssd_cfg_int($key, $default = 0)
    {
        return (int)ssd_cfg($key, $default);
    }

    function ssd_cfg_str($key, $default = '')
    {
        return (string)ssd_cfg($key, $default);
    }
}
