<?php
/**
 * Self Service Deploy — hooki instalacji.
 *
 * Plugin tworzy WŁASNE tabele (tokeny, logi, blokady) w bazie GLPI.
 * NIE dotyka danych glpiinventory — deployment zleca wyłącznie przez klasy
 * pluginu (PluginGlpiinventoryTaskjobstate / Taskjoblog).
 */

/**
 * Wykonanie zapytania DDL w sposób odporny na wersję GLPI:
 * doQuery() od 10.0.11, query() wcześniej.
 * (definicja także w config.php — hook.php i front ładują się osobno)
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

function plugin_selfservicedeploy_install()
{
    /* Tabela tokenów: token_hash (SHA-256), computer_id, nazwa, enabled */
    ssd_db_query(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_selfservicedeploy_tokens` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `token_hash` CHAR(64) NOT NULL,
            `computer_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `computer_name` VARCHAR(255) NOT NULL DEFAULT '',
            `enabled` TINYINT NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT NULL,
            `last_used_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token_hash` (`token_hash`),
            KEY `computer_id` (`computer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    /* Tabela logów audytowych */
    ssd_db_query(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_selfservicedeploy_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `computer_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `token_id` INT UNSIGNED NULL DEFAULT NULL,
            `action` VARCHAR(64) NOT NULL DEFAULT '',
            `result` VARCHAR(64) NOT NULL DEFAULT '',
            `source_ip` VARCHAR(64) NOT NULL DEFAULT '',
            `detail` TEXT NULL,
            PRIMARY KEY (`id`),
            KEY `computer_id` (`computer_id`),
            KEY `timestamp` (`timestamp`),
            KEY `result` (`result`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    /* Blokada równoległych restartów: 1 wiersz na computer_id */
    ssd_db_query(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_selfservicedeploy_locks` (
            `computer_id` INT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`computer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    return true;
}

function plugin_selfservicedeploy_uninstall()
{
    ssd_db_query('DROP TABLE IF EXISTS `glpi_plugin_selfservicedeploy_locks`');
    ssd_db_query('DROP TABLE IF EXISTS `glpi_plugin_selfservicedeploy_logs`');
    ssd_db_query('DROP TABLE IF EXISTS `glpi_plugin_selfservicedeploy_tokens`');

    // usuń konfigurację z glpi_configs (context='selfservicedeploy')
    if (class_exists('Config')) {
        Config::deleteConfigurationValues('selfservicedeploy', [
            'SSD_DEPLOY_TASKJOB_ID',
            'SSD_DEPLOY_PACKAGE_ID',
            'SSD_WAKEUP_AGENT',
            'SSD_RATE_LIMIT_MAX',
            'SSD_RATE_LIMIT_SECONDS',
            'SSD_LOCK_TTL_SECONDS',
            'SSD_COOKIE_SECURE',
            'SSD_TRUST_PROXY',
            'SSD_APP_PUBLIC_URL',
            'SSD_SERVICE_LABEL',
        ]);
    }
    return true;
}
