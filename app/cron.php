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
    // Uma rodada por empresa ativa (cada uma com o próprio Google Drive e a própria agenda)
    $erro = false;
    foreach (Tenant::activeIds() as $companyId) {
        $msg = Tenant::with($companyId, fn() => GDrive::cron());
        if ($msg !== '') echo date('Y-m-d H:i:s') . " [empresa $companyId] " . $msg . "
";
        if (str_starts_with($msg, 'ERRO')) $erro = true;
    }
    exit($erro ? 1 : 0);
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' ERRO nas tarefas agendadas: ' . $e->getMessage() . "\n";
    exit(1);
}
