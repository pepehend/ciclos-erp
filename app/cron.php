<?php
/**
 * Tarefas agendadas (só pela linha de comando). Hoje: backup automático no Google Drive.
 * Chamado a cada ~15 minutos pela publicação automática (deploy/auto-deploy.sh). Também dá para rodar à mão:
 *
 *   php app/cron.php
 *
 * Escreve uma linha só quando faz algo (backup enviado ou erro).
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/bootstrap.php';

try {
    if (!app_connect()) exit(0);
    $msg = GDrive::cron();
    if ($msg !== '') echo date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    exit(str_starts_with($msg, 'ERRO') ? 1 : 0);
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' ERRO nas tarefas agendadas: ' . $e->getMessage() . "\n";
    exit(1);
}
