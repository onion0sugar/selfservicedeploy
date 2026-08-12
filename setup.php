<?php
/**
 * Self Service Deploy — GLPI plugin setup.
 *
 * Instalacja:
 *   - skopiuj katalog do <GLPI>/plugins/selfservicedeploy
 *   - uzupełnij config.php (id taskjoba + ewentualnie pakietu)
 *   - aktywuj plugin w interfejsie (Setup > Plugins)
 *
 * Wariant jednoelementowy: plugin sam obsługuje stronę użytkownika
 * (front/restart.php), tokeny, CSRF, rate limit i audit log — bez
 * osobnej aplikacji.
 */

define('PLUGIN_SELF_SERVICE_DEPLOY_VERSION', '1.3.0');

/**
 * Plugin init
 */
function plugin_init_selfservicedeploy()
{
    // Załaduj config.php (domyślne wartości + helpery ssd_cfg*) — potrzebne
    // na KAŻDEJ stronie GLPI (zakładka konfiguracji, front/restart.php).
    require_once(__DIR__ . '/config.php');

    // Zakładka konfiguracji w Setup → General (Config)
    Plugin::registerClass('PluginSelfservicedeployConfig', ['addtabon' => ['Config']]);

    // Brak innego interfejsu — strona użytkownika to front/restart.php
    // (GET = strona, POST = zlecenie restartu).
    Plugin::registerClass('PluginSelfservicedeploy', ['has_tab' => false]);
}

/**
 * Plugin version
 */
function plugin_version_selfservicedeploy()
{
    return [
        'name'         => 'Self Service Deploy',
        'version'      => PLUGIN_SELF_SERVICE_DEPLOY_VERSION,
        'author'       => 'IT',
        'license'      => 'AGPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi'    => ['min' => '10.0.0'],
            'plugins' => [
                'glpiinventory' => ['min' => '1.4.0'],
            ],
        ],
    ];
}
