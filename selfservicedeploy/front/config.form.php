<?php
/**
 * Self Service Deploy — zapis konfiguracji (POST z zakładki Setup → General).
 *
 * Wartości zapisywane do glpi_configs (context='selfservicedeploy').
 * Formularz zawiera _glpi_csrf_token (Html::closeForm) — walidujemy go
 * Session::checkCSRFToken().
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (!isset($_POST['update'])) {
    // bezpośrednie wejście (bez POST) — wróć do zakładki
    Html::redirect(Toolbox::getItemTypeFormURL('Config') . '?forcetab=PluginSelfservicedeployConfig$1');
}

Session::checkCSRFToken($_POST);

$values = [
    'SSD_DEPLOY_TASKJOB_ID'  => max(0, (int)($_POST['taskjob_id'] ?? 0)),
    'SSD_DEPLOY_PACKAGE_ID'  => max(0, (int)($_POST['package_id'] ?? 0)),
    'SSD_WAKEUP_AGENT'       => (($_POST['wakeup_agent'] ?? '0') === '1') ? '1' : '0',
    'SSD_RATE_LIMIT_MAX'     => max(1, (int)($_POST['rate_limit_max'] ?? 1)),
    'SSD_RATE_LIMIT_SECONDS' => max(10, (int)($_POST['rate_limit_seconds'] ?? 120)),
    'SSD_LOCK_TTL_SECONDS'   => max(30, (int)($_POST['lock_ttl_seconds'] ?? 300)),
    'SSD_COOKIE_SECURE'      => (($_POST['cookie_secure'] ?? '0') === '1') ? '1' : '0',
    'SSD_TRUST_PROXY'        => (($_POST['trust_proxy'] ?? '0') === '1') ? '1' : '0',
    'SSD_APP_PUBLIC_URL'     => trim((string)($_POST['app_public_url'] ?? '')),
    'SSD_SERVICE_LABEL'      => trim((string)($_POST['service_label'] ?? '')),
];

Config::setConfigurationValues('selfservicedeploy', $values);

Session::addMessageAfterRedirect(
    __('Zapisano konfigurację Self Service Deploy.', 'selfservicedeploy'),
    false,
    INFO
);

Html::redirect(Toolbox::getItemTypeFormURL('Config') . '?forcetab=PluginSelfservicedeployConfig$1');
