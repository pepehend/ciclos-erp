<?php
/**
 * Administração de usuários pelo console do servidor (só pela linha de comando).
 * Pela tela, um administrador não altera outro administrador (nem rebaixa, desativa ou exclui); por aqui, pode.
 *
 *   php app/users-cli.php listar
 *   php app/users-cli.php desativar <e-mail>
 *   php app/users-cli.php ativar <e-mail>
 *   php app/users-cli.php rebaixar <e-mail>     (administrador vira membro)
 *   php app/users-cli.php promover <e-mail>     (membro vira administrador)
 *   php app/users-cli.php excluir <e-mail>      (apaga se nunca usou o sistema; senão só desativa)
 *   php app/users-cli.php senha <e-mail>        (gera uma senha nova e mostra)
 *   php app/users-cli.php email <e-mail> <novo> (troca o e-mail/login)
 *   php app/users-cli.php superadmin <e-mail> <nome>   (cria um superadmin: administra as empresas; gera a senha)
 *
 * Nunca deixa o sistema sem nenhum administrador ativo.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/bootstrap.php';

$cmd = $argv[1] ?? '';
$email = mb_strtolower(trim($argv[2] ?? ''));
$out = fn(string $s) => fwrite(STDOUT, $s . "\n");
$fail = function (string $s) { fwrite(STDERR, "ERRO: $s\n"); exit(1); };

if (!app_connect()) $fail('o sistema não está instalado (falta o app/config.php).');

$ajuda = <<<TXT
Uso: php users-cli.php <comando> [e-mail]
  listar              mostra todos os usuários
  desativar <e-mail>  bloqueia o acesso (mantém o histórico)
  ativar <e-mail>     libera o acesso de novo
  rebaixar <e-mail>   administrador passa a membro
  promover <e-mail>   membro passa a administrador
  excluir <e-mail>    apaga se nunca usou o sistema; se tiver registros, só desativa
  senha <e-mail>      gera uma senha nova e mostra na tela
  email <e-mail> <novo-e-mail>   troca o e-mail (login)
  superadmin <e-mail> <nome>     cria um superadmin (cadastra e ativa as empresas; não vê os dados delas)
TXT;

try {
    if ($cmd === 'listar') {
        $rows = DB::all('SELECT u.id, u.name, u.email, u.role, u.active, c.slug FROM users u LEFT JOIN companies c ON c.id = u.company_id ORDER BY c.slug, u.role, u.name');
        foreach ($rows as $r) {
            $out(sprintf('%-18s %-26s %-32s %-14s %s', mb_strimwidth((string)($r['slug'] ?? '(superadmin)'), 0, 17, '…'), mb_strimwidth($r['name'], 0, 25, '…'), $r['email'],
                $r['role'] === 'superadmin' ? 'superadmin' : ($r['role'] === 'admin' ? 'administrador' : 'membro'), $r['active'] ? 'ativo' : 'INATIVO'));
        }
        exit(0);
    }
    if ($cmd === 'superadmin') {
        $nome = trim((string)($argv[3] ?? ''));
        if ($email === '' || $nome === '') $fail('uso: php users-cli.php superadmin <e-mail> <nome>');
        $nova = '';
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 12; $i++) $nova .= $chars[random_int(0, strlen($chars) - 1)];
        Auth::createUser(['name' => $nome, 'email' => $email, 'password' => $nova, '_superadmin' => true]);
        $out("Superadmin criado: $nome ($email). Senha: $nova");
        $out('Passe para a pessoa e peça para ela trocar em Minha conta.');
        exit(0);
    }
    if (!in_array($cmd, ['desativar', 'ativar', 'rebaixar', 'promover', 'excluir', 'senha', 'email'], true)) { $out($ajuda); exit($cmd === '' ? 0 : 1); }
    if ($email === '') $fail("informe o e-mail do usuário. Ex.: php users-cli.php $cmd fulano@empresa.com");
    $u = DB::one('SELECT id, name, email, role, active, company_id FROM users WHERE email = ?', [$email]);
    if (!$u) $fail("nenhum usuário com o e-mail $email (veja a lista com: php users-cli.php listar)");
    Tenant::set($u['company_id'] !== null ? (int)$u['company_id'] : null);   // o e-mail é único: ele já diz a empresa
    $id = (int)$u['id'];
    $lastAdmin = $u['role'] === 'admin' && $u['active'] && Auth::activeAdmins() <= 1;

    switch ($cmd) {
        case 'desativar':
            if (!$u['active']) { $out("{$u['name']} já está inativo."); break; }
            if ($lastAdmin) $fail('este é o único administrador ativo: ative ou promova outro antes.');
            DB::update('users', $id, ['active' => 0]);
            $out("Desativado: {$u['name']} ({$u['email']}).");
            break;
        case 'ativar':
            DB::update('users', $id, ['active' => 1]);
            $out("Ativado: {$u['name']} ({$u['email']}).");
            break;
        case 'rebaixar':
            if ($u['role'] !== 'admin') { $out("{$u['name']} já é membro."); break; }
            if ($lastAdmin) $fail('este é o único administrador ativo: promova outro antes.');
            DB::update('users', $id, ['role' => 'membro']);
            $out("{$u['name']} agora é membro. Ajuste o perfil de permissão dele em Configurações → Sistema → Usuários.");
            break;
        case 'promover':
            DB::update('users', $id, ['role' => 'admin', 'active' => 1]);
            $out("{$u['name']} agora é administrador.");
            break;
        case 'senha':
            $nova = '';
            $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
            for ($i = 0; $i < 12; $i++) $nova .= $chars[random_int(0, strlen($chars) - 1)];
            DB::update('users', $id, ['password_hash' => password_hash($nova, PASSWORD_DEFAULT)]);
            $out("Senha nova de {$u['name']} ({$u['email']}): $nova");
            $out('Passe para a pessoa e peça para ela trocar em Minha conta.');
            break;
        case 'email':
            $novo = Auth::cleanEmail((string)($argv[3] ?? ''), $id);
            DB::update('users', $id, ['email' => $novo]);
            $out("E-mail de {$u['name']} trocado: {$u['email']} → $novo");
            break;
        case 'excluir':
            $r = Auth::deleteUser($id, 0, true);
            $out($r === 'deleted' ? "Excluído: {$u['name']} ({$u['email']})."
                : "{$u['name']} tem vendas, orçamentos, notas ou outros registros: foi DESATIVADO (não apagado) para manter o histórico.");
            break;
    }
} catch (AppError $e) {
    $fail($e->getMessage());
}
