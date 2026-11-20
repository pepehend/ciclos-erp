<?php
/**
 * Prepara o banco do servidor (linha de comando, no lugar de um instalador web):
 * cria/atualiza as tabelas e os cadastros padrão.
 *
 *   php setup.php /var/www/ciclos-erp            cria/atualiza as tabelas
 *   php setup.php /var/www/ciclos-erp --admin    e cadastra um administrador (pergunta nome, e-mail e senha)
 *   php setup.php /var/www/ciclos-erp --admin-se-vazio   cadastra o administrador só se ainda não houver usuários
 *   php setup.php /var/www/ciclos-erp --quieto   sem mensagens (usado pela atualização automática)
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Precisa de root (lê a configuração do banco): se foi chamado por um usuário comum, roda de novo com sudo
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    if (!trim((string)shell_exec('command -v sudo'))) { fwrite(STDERR, "Este comando precisa de root: rode como root ou instale o sudo.\n"); exit(1); }
    passthru('sudo ' . escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map('escapeshellarg', $argv)), $rc);
    exit($rc);
}

$site = rtrim($argv[1] ?? '/var/www/ciclos-erp', '/');
$admin = in_array('--admin', $argv, true);
$adminIfEmpty = in_array('--admin-se-vazio', $argv, true);
$quiet = in_array('--quieto', $argv, true);
$say = function (string $m) use ($quiet) { if (!$quiet) echo $m, "\n"; };

if (!is_file("$site/app/bootstrap.php")) { fwrite(STDERR, "Sistema não encontrado em $site\n"); exit(1); }
require "$site/app/bootstrap.php";

$cfg = app_config();
if (!$cfg) { fwrite(STDERR, "Falta $site/app/config.php (rode o install.sh)\n"); exit(1); }

try {
    DB::connect($cfg['db']);
    DB::migrate();           // CREATE TABLE IF NOT EXISTS
    DB::upgrade();           // colunas e ajustes de versões novas
    Customers::ensureDefault();
    Auth::seedProfiles();
    PaymentMethods::seed();
    PaymentTerms::seed();
    QuoteStatuses::seed();
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro ao preparar o banco: ' . $e->getMessage() . "\n");
    exit(1);
}
$users = (int)DB::val('SELECT COUNT(*) FROM users');
$say('Banco pronto (' . APP_VERSION . '). Usuários cadastrados: ' . $users . '.');

if ($adminIfEmpty) {
    if ($users) exit(0);
    echo "\nVamos criar o administrador do sistema (o primeiro login).\n";
    $admin = true;
}
if (!$admin) {
    if (!$users) $say("Ainda não há usuários: rode com --admin para criar o administrador.");
    exit(0);
}

// ---- cadastro do administrador
$ask = function (string $q, bool $secret = false): string {
    echo $q;
    if ($secret && DIRECTORY_SEPARATOR === '/') system('stty -echo 2>/dev/null');
    $v = trim((string)fgets(STDIN));
    if ($secret && DIRECTORY_SEPARATOR === '/') { system('stty echo 2>/dev/null'); echo "\n"; }
    return $v;
};
$name = $ask('Seu nome: ');
$email = $ask('E-mail (login): ');
$pass = $ask('Senha (mínimo 8 caracteres): ', true);
$pass2 = $ask('Repita a senha: ', true);
if ($pass !== $pass2) { fwrite(STDERR, "As senhas não conferem.\n"); exit(1); }
try {
    Auth::createUser(['name' => $name, 'email' => $email, 'password' => $pass, 'role' => 'admin']);
} catch (AppError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Administrador criado. Entre no sistema com $email.\n";
