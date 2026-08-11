<?php
/**
 * Self Service Deploy — zakładka konfiguracji w GLPI (Setup → General).
 *
 * Wartości zapisywane są przez Config::setConfigurationValues('selfservicedeploy', ...)
 * w tabeli glpi_configs (context='selfservicedeploy'). Stałe z config.php pełnią
 * rolę domyślnych wartości — nadpisuje je konfiguracja z UI.
 */
class PluginSelfservicedeployConfig extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0)
    {
        return 'Self Service Deploy';
    }

    public static function canView()
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate()
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Config && self::canView()) {
            return self::getTypeName();
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Config) {
            $config = new self();
            $config->showForm();
        }
        return true;
    }

    /**
     * Formularz konfiguracji.
     */
    public function showForm()
    {
        $canedit = self::canCreate();

        /* --- informacja o aktualnie skonfigurowanym deploymentcie --- */
        $this->showDeploymentInfo();

        echo '<form method="post" action="' . Plugin::getWebDir('selfservicedeploy') . '/front/config.form.php">';
        echo '<table class="tab_cadre_fixe">';
        echo '<tr><th colspan="4">' . self::getTypeName() . ' — konfiguracja</th></tr>';

        /* job */
        echo '<tr class="tab_bg_1">';
        echo '<td><label for="taskjob_id">' . __('ID joba deployinstall (taskjob)', 'selfservicedeploy') . '</label></td>';
        echo '<td colspan="3"><input type="number" id="taskjob_id" name="taskjob_id" min="0" step="1" value="'
            . (int)ssd_cfg_int('SSD_DEPLOY_TASKJOB_ID', 0) . '">';
        echo '<br><small>' . __('ID z glpi_plugin_glpiinventory_taskjobs — job "Restart MyService" z menu Tasks.', 'selfservicedeploy') . '</small></td></tr>';

        /* pakiet */
        echo '<tr class="tab_bg_1">';
        echo '<td><label for="package_id">' . __('ID pakietu (0 = z targets joba)', 'selfservicedeploy') . '</label></td>';
        echo '<td colspan="3"><input type="number" id="package_id" name="package_id" min="0" step="1" value="'
            . (int)ssd_cfg_int('SSD_DEPLOY_PACKAGE_ID', 0) . '">';
        echo '<br><small>' . __('Pakiet zawiera volvo_restart.bat i akcje restartu.', 'selfservicedeploy') . '</small></td></tr>';

        /* wakeup */
        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('Wybudzaj agenta po zleceniu (port 62354)', 'selfservicedeploy') . '</td>';
        echo '<td colspan="3">';
        Dropdown::showYesNo('wakeup_agent', ssd_cfg_bool('SSD_WAKEUP_AGENT', true) ? 1 : 0);
        echo '<br><small>' . __('Natychmiastowe wykonanie zamiast czekania na kolejny poll agenta.', 'selfservicedeploy') . '</small></td></tr>';

        /* rate limit */
        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('Rate limit — maks. restartów na komputer', 'selfservicedeploy') . '</td>';
        echo '<td><input type="number" name="rate_limit_max" min="1" step="1" value="'
            . (int)ssd_cfg_int('SSD_RATE_LIMIT_MAX', 1) . '"></td>';
        echo '<td>' . __('w ciągu (sekund)', 'selfservicedeploy') . '</td>';
        echo '<td><input type="number" name="rate_limit_seconds" min="10" step="1" value="'
            . (int)ssd_cfg_int('SSD_RATE_LIMIT_SECONDS', 120) . '"></td></tr>';

        /* lock ttl */
        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('TTL blokady równoległych restartów (s)', 'selfservicedeploy') . '</td>';
        echo '<td colspan="3"><input type="number" name="lock_ttl_seconds" min="30" step="1" value="'
            . (int)ssd_cfg_int('SSD_LOCK_TTL_SECONDS', 300) . '"></td></tr>';

        /* cookie secure */
        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('Cookie CSRF tylko przez HTTPS (Secure)', 'selfservicedeploy') . '</td>';
        echo '<td colspan="3">';
        Dropdown::showYesNo('cookie_secure', ssd_cfg_bool('SSD_COOKIE_SECURE', false) ? 1 : 0);
        echo '</td></tr>';

        /* trust proxy */
        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('Ufaj nagłówkowi X-Forwarded-For (za reverse proxy)', 'selfservicedeploy') . '</td>';
        echo '<td colspan="3">';
        Dropdown::showYesNo('trust_proxy', ssd_cfg_bool('SSD_TRUST_PROXY', false) ? 1 : 0);
        echo '</td></tr>';

        /* app public url */
        echo '<tr class="tab_bg_1">';
        echo '<td><label for="app_public_url">' . __('Adres GLPI (weryfikacja Origin w POST)', 'selfservicedeploy') . '</label></td>';
        echo '<td colspan="3"><input type="text" id="app_public_url" name="app_public_url" style="width:90%" value="'
            . htmlspecialchars(ssd_cfg_str('SSD_APP_PUBLIC_URL', '')) . '">';
        echo '<br><small>' . __('np. https://glpi.example.org — bez końcowego "/". Puste = sprawdzenie pominięte.', 'selfservicedeploy') . '</small></td></tr>';

        /* service label */
        echo '<tr class="tab_bg_1">';
        echo '<td><label for="service_label">' . __('Etykieta usługi na stronie użytkownika', 'selfservicedeploy') . '</label></td>';
        echo '<td colspan="3"><input type="text" id="service_label" name="service_label" style="width:90%" value="'
            . htmlspecialchars(ssd_cfg_str('SSD_SERVICE_LABEL', 'usługa')) . '"></td></tr>';

        if ($canedit) {
            echo '<tr class="tab_bg_2"><td colspan="4" class="center">';
            Html::submit('Zapisz', ['name' => 'update']);
            echo '</td></tr>';
        }
        echo '</table>';
        Html::closeForm();
    }

    /**
     * Podsumowanie skonfigurowanego deploymentu (read-only, z klas glpiinventory).
     */
    protected function showDeploymentInfo()
    {
        $taskjob_id = ssd_cfg_int('SSD_DEPLOY_TASKJOB_ID', 0);
        $package_id = ssd_cfg_int('SSD_DEPLOY_PACKAGE_ID', 0);

        echo '<table class="tab_cadre_fixe">';
        echo '<tr><th colspan="2">' . __('Aktualny deployment (podgląd)', 'selfservicedeploy') . '</th></tr>';

        if (!Plugin::isPluginActive('glpiinventory') || !defined('PLUGIN_GLPI_INVENTORY_DIR')) {
            echo '<tr class="tab_bg_2"><td colspan="2"><span class="red">'
                . __('Plugin GLPI Inventory jest nieaktywny — deployment nie zadziała.', 'selfservicedeploy')
                . '</span></td></tr>';
            echo '</table>';
            return;
        }

        if ($taskjob_id <= 0) {
            echo '<tr class="tab_bg_2"><td colspan="2"><span class="red">'
                . __('Nie skonfigurowano ID joba (taskjob_id = 0).', 'selfservicedeploy')
                . '</span></td></tr>';
            echo '</table>';
            return;
        }

        require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/taskjobview.class.php');
        require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/taskview.class.php');
        require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/task.class.php');
        require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/taskjob.class.php');
        require_once(PLUGIN_GLPI_INVENTORY_DIR . '/inc/deploypackage.class.php');

        $job = new PluginGlpiinventoryTaskjob();
        if (!$job->getFromDB($taskjob_id)) {
            echo '<tr class="tab_bg_2"><td colspan="2"><span class="red">'
                . sprintf(__('Job #%d nie istnieje w glpi_plugin_glpiinventory_taskjobs.', 'selfservicedeploy'), $taskjob_id)
                . '</span></td></tr>';
            echo '</table>';
            return;
        }

        $method_ok = ($job->fields['method'] === 'deployinstall');
        echo '<tr class="tab_bg_1"><td>' . __('Job', 'selfservicedeploy') . '</td><td>'
            . htmlspecialchars((string)$job->fields['name'])
            . ' <small>(method: ' . htmlspecialchars((string)$job->fields['method']) . ')</small>'
            . ($method_ok ? '' : ' <span class="red">— musi być deployinstall!</span>')
            . '</td></tr>';

        $task = new PluginGlpiinventoryTask();
        if ($task->getFromDB((int)$job->fields['plugin_glpiinventory_tasks_id'])) {
            $active = ((int)$task->fields['is_active'] === 1);
            echo '<tr class="tab_bg_1"><td>' . __('Task', 'selfservicedeploy') . '</td><td>'
                . htmlspecialchars((string)$task->fields['name'])
                . ' — is_active: ' . ($active ? __('tak', 'selfservicedeploy') : '<span class="red">' . __('nie! (stany będą anulowane)', 'selfservicedeploy') . '</span>')
                . '</td></tr>';
        }

        /* pakiety */
        $packages = [];
        if ($package_id > 0) {
            $packages[] = $package_id;
        } else {
            $targets = importArrayFromDB($job->fields['targets']);
            foreach ($targets as $target) {
                if (is_array($target) && isset($target['PluginGlpiinventoryDeployPackage'])) {
                    $packages[] = (int)$target['PluginGlpiinventoryDeployPackage'];
                }
            }
        }
        $names = [];
        foreach (array_unique($packages) as $pid) {
            $pkg = new PluginGlpiinventoryDeployPackage();
            $names[] = $pkg->getFromDB($pid)
                ? ('#' . $pid . ' ' . $pkg->fields['name'])
                : ('<span class="red">#' . $pid . ' — brak pakietu</span>');
        }
        echo '<tr class="tab_bg_1"><td>' . __('Pakiet(y)', 'selfservicedeploy') . '</td><td>'
            . (count($names) ? implode('<br>', $names) : '<span class="red">' . __('brak — ustaw package_id lub targets joba', 'selfservicedeploy') . '</span>')
            . '</td></tr>';

        echo '</table>';
        echo '<br>';
    }
}
