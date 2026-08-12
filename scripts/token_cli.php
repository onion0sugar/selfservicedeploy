#!/usr/bin/env php
<?php
/**
 * Self Service Deploy — CLI do zarządzania tokenami.
 *
 * Uruchamianie na serwerze GLPI:
 *   php <GLPI>/plugins/selfservicedeploy/scripts/token_cli.php create --computer-id 42 --name "PC-123" [--url-prefix https://glpi.example.org]
 *   php <GLPI>/plugins/selfservicedeploy/scripts/token_cli.php list
 *   php <GLPI>/plugins/selfservicedeploy/scripts/token_cli.php set-enabled --token <plaintext> --enabled false
 *
 * Token: 256 bitów entropii; w bazie przechowywany wyłącznie SHA-256 hash.
 */

/**
 * Bootstrap GLPI — ścieżka liczona od katalogu tego skryptu (__DIR__),
 * więc komenda działa niezależnie od katalogu, z którego jest wywoływana.
 */
$ssd_glpi_includes = dirname(__DIR__, 3) . '/inc/includes.php';
if (!file_exists($ssd_glpi_includes)) {
    fwrite(
        STDERR,
        'Nie można znaleźć ' . $ssd_glpi_includes . "\n"
        . 'Uruchom ten skrypt z katalogu <GLPI>/plugins/selfservicedeploy/scripts/ '
        . "albo sprawdź ścieżkę instalacji GLPI.\n"
    );
    exit(1);
}
include($ssd_glpi_includes);
require_once(__DIR__ . '/../config.php');

$args = $argv;
array_shift($args); // nazwa skryptu
$cmd = array_shift($args) ?? '';

function ssd_cli_hash($plain)
{
    return hash('sha256', $plain);
}

function ssd_cli_getopt($args, $name, $default = null)
{
    $args = array_values($args);
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === $name && isset($args[$i + 1])) {
            return $args[$i + 1];
        }
    }
    return $default;
}

function ssd_cli_usage()
{
    echo "Użycie:\n";
    echo "  token_cli.php create --computer-id N --name \"NAZWA\" [--url-prefix URL] [--disabled]\n";
    echo "  token_cli.php list\n";
    echo "  token_cli.php set-enabled --token <plaintext> --enabled true|false\n";
    exit(1);
}

switch ($cmd) {
    case 'create':
        $computer_id = (int)ssd_cli_getopt($args, '--computer-id', 0);
        if ($computer_id <= 0) {
            ssd_cli_usage();
        }
        $name       = ssd_cli_getopt($args, '--name', '') ?: ('computer_' . $computer_id);
        $url_prefix = rtrim((string)ssd_cli_getopt($args, '--url-prefix', ''), '/');
        $disabled   = in_array('--disabled', $args, true);

        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); // 256 bitów
        $DB->insert('glpi_plugin_selfservicedeploy_tokens', [
            'token_hash'    => ssd_cli_hash($plain),
            'computer_id'   => $computer_id,
            'computer_name' => $name,
            'enabled'       => $disabled ? 0 : 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $base = $url_prefix !== '' ? $url_prefix : '<GLPI_URL>';
        echo "======================================================================\n";
        echo "UTWORZONO TOKEN (token pokazany tylko RAZ — zapisz go teraz):\n\n";
        echo "  Token : $plain\n";
        echo "  Link  : $base/plugins/selfservicedeploy/front/restart.php?token=$plain\n\n";
        echo "  computer_id : $computer_id\n";
        echo "  computer    : $name\n";
        echo "  enabled     : " . ($disabled ? 'false' : 'true') . "\n";
        echo "======================================================================\n";
        echo "W bazie przechowywany jest wyłącznie SHA-256 hash tokenu.\n";
        break;

    case 'list':
        $found = false;
        foreach ($DB->request(['FROM' => 'glpi_plugin_selfservicedeploy_tokens',
            'ORDER' => 'id']) as $row) {
            $found = true;
            printf("%4d  %11d  %-30s  %-7s  %s\n",
                $row['id'], $row['computer_id'], $row['computer_name'],
                ((int)$row['enabled'] ? 'true' : 'false'),
                $row['created_at']);
        }
        if (!$found) {
            echo "Brak tokenów.\n";
        }
        break;

    case 'set-enabled':
        $t = (string)ssd_cli_getopt($args, '--token', '');
        $e = (string)ssd_cli_getopt($args, '--enabled', '');
        if ($t === '' || !in_array($e, ['true', 'false'], true)) {
            ssd_cli_usage();
        }
        $row = null;
        foreach ($DB->request(['FROM' => 'glpi_plugin_selfservicedeploy_tokens',
            'WHERE' => ['token_hash' => ssd_cli_hash($t)], 'LIMIT' => 1]) as $data) {
            $row = $data;
        }
        if ($row === null) {
            fwrite(STDERR, "Nie znaleziono tokenu.\n");
            exit(1);
        }
        $DB->update('glpi_plugin_selfservicedeploy_tokens',
            ['enabled' => $e === 'true' ? 1 : 0],
            ['id' => $row['id']]);
        echo "Token id={$row['id']} (computer_id={$row['computer_id']}) enabled={$e}\n";
        break;

    default:
        ssd_cli_usage();
}
