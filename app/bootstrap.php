<?php
/**
 * Carrega configuração, bibliotecas e conexão com o banco.
 */

declare(strict_types=1);

const APP_VERSION = '1.6.0';
define('APP_ROOT', dirname(__DIR__));
define('CONFIG_FILE', __DIR__ . '/config.php');

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/inventory.php';
require __DIR__ . '/lib/fiscal.php';
require __DIR__ . '/lib/import.php';
require __DIR__ . '/lib/invoices.php';
require __DIR__ . '/lib/reports.php';
require __DIR__ . '/lib/customers.php';
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/quotes.php';
require __DIR__ . '/lib/payments.php';
require __DIR__ . '/lib/terms.php';
require __DIR__ . '/lib/qstatus.php';
require __DIR__ . '/lib/pdf.php';
require __DIR__ . '/lib/finance.php';
require __DIR__ . '/lib/purchases.php';
require __DIR__ . '/lib/service_orders.php';
require __DIR__ . '/lib/questionnaires.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/backup.php';
require __DIR__ . '/lib/gdrive.php';
require __DIR__ . '/lib/portal.php';

// Autoload da biblioteca de leitura de PDF (smalot/pdfparser, licença LGPL)
spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'Smalot\\PdfParser\\')) {
        $file = __DIR__ . '/vendor/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) require $file;
    }
});

function app_config(): ?array
{
    static $cfg = null;
    if ($cfg === null && is_file(CONFIG_FILE)) $cfg = require CONFIG_FILE;
    return $cfg ?: null;
}

/** Endereço base do sistema (ex.: https://site.com.br/estoque/), para links enviados ao cliente. */
function app_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function app_connect(): bool
{
    $cfg = app_config();
    if (!$cfg) return false;
    DB::connect($cfg['db']);
    DB::upgrade();
    return true;
}
