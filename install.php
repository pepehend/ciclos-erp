<?php
/**
 * Instalador: cria o arquivo de configuração, as tabelas e o primeiro administrador.
 * Depois de instalado, ele se bloqueia sozinho.
 */

require __DIR__ . '/app/bootstrap.php';

$errors = [];
$done = false;

function installed(): bool
{
    if (!app_connect()) return false;
    try {
        return (int)DB::val('SELECT COUNT(*) FROM users') > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (installed()) {
    $locked = true;
} else {
    $locked = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $f = fn($k) => trim((string)($_POST[$k] ?? ''));
        $driver = $f('driver') === 'sqlite' ? 'sqlite' : 'mysql';
        $db = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'path' => APP_ROOT . '/data/estoque.sqlite']
            : ['driver' => 'mysql', 'host' => $f('db_host') ?: 'localhost', 'port' => (int)($f('db_port') ?: 3306),
               'name' => $f('db_name'), 'user' => $f('db_user'), 'pass' => (string)($_POST['db_pass'] ?? '')];
        if ($driver === 'mysql' && ($db['name'] === '' || $db['user'] === '')) $errors[] = 'Preencha o nome do banco e o usuário do banco.';
        if ($f('admin_name') === '') $errors[] = 'Informe o seu nome.';
        if (!filter_var($f('admin_email'), FILTER_VALIDATE_EMAIL)) $errors[] = 'Informe um e-mail válido.';
        if (mb_strlen((string)($_POST['admin_password'] ?? '')) < 8) $errors[] = 'A senha precisa ter pelo menos 8 caracteres.';
        if (($_POST['admin_password'] ?? '') !== ($_POST['admin_password2'] ?? '')) $errors[] = 'As senhas não conferem.';

        if (!$errors) {
            try {
                DB::connect($db);
                DB::migrate();
                Customers::ensureDefault();
                Auth::seedProfiles();
                PaymentMethods::seed();
                PaymentTerms::seed();
                QuoteStatuses::seed();
                DB::tx(function () use ($f) {
                    Auth::createUser(['name' => $f('admin_name'), 'email' => $f('admin_email'),
                        'password' => (string)$_POST['admin_password'], 'role' => 'admin']);
                });
                $config = "<?php\n// Gerado pelo instalador em " . date('d/m/Y H:i') . "\nreturn " . var_export([
                    'db' => $db,
                    'debug' => false,
                ], true) . ";\n";
                if (@file_put_contents(CONFIG_FILE, $config) === false) {
                    $errors[] = 'Banco criado, mas não consegui gravar app/config.php. Dê permissão de escrita na pasta "app" (755) e tente de novo, ou crie o arquivo manualmente com o conteúdo abaixo.';
                    $manual = $config;
                } else {
                    @chmod(CONFIG_FILE, 0640);
                    $done = true;
                }
            } catch (PDOException $e) {
                $errors[] = 'Não consegui conectar ao banco: ' . $e->getMessage();
            } catch (AppError $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}
$v = fn($k, $d = '') => htmlspecialchars((string)($_POST[$k] ?? $d), ENT_QUOTES);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){try{var t=localStorage.getItem('theme')||'auto';var d=t==='dark'||(t==='auto'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');}catch(e){}})();</script>
<title>Instalação · Estoque</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<main class="auth-card wide">
  <h1>Instalação do sistema</h1>
  <?php if ($locked): ?>
    <p>O sistema já está instalado. <a href="./">Ir para o login</a>.</p>
    <p class="muted">Por segurança, você pode apagar o arquivo <code>install.php</code> do servidor.</p>
  <?php elseif ($done): ?>
    <div class="alert ok">Tudo pronto! O banco foi criado e seu usuário administrador está ativo.</div>
    <p><a class="btn primary" href="./">Entrar no sistema</a></p>
    <p class="muted">Por segurança, apague o arquivo <code>install.php</code> do servidor.</p>
  <?php else: ?>
    <?php foreach ($errors as $e): ?><div class="alert err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    <?php if (!empty($manual)): ?><pre class="code"><?= htmlspecialchars($manual) ?></pre><?php endif; ?>
    <form method="post" class="form">
      <fieldset>
        <legend>Banco de dados</legend>
        <p class="muted">Crie um banco MySQL e um usuário no cPanel (em "Bancos de dados MySQL"), dê ao usuário todas as permissões no banco e preencha abaixo.</p>
        <label>Tipo
          <select name="driver" onchange="document.getElementById('mysql').hidden = this.value === 'sqlite'">
            <option value="mysql">MySQL / MariaDB (recomendado)</option>
            <option value="sqlite" <?= ($_POST['driver'] ?? '') === 'sqlite' ? 'selected' : '' ?>>SQLite (só para testes)</option>
          </select>
        </label>
        <div id="mysql" <?= ($_POST['driver'] ?? '') === 'sqlite' ? 'hidden' : '' ?>>
          <div class="row">
            <label>Servidor <input name="db_host" value="<?= $v('db_host', 'localhost') ?>"></label>
            <label class="small">Porta <input name="db_port" value="<?= $v('db_port', '3306') ?>"></label>
          </div>
          <label>Nome do banco <input name="db_name" value="<?= $v('db_name') ?>" placeholder="ex.: usuario_estoque"></label>
          <label>Usuário do banco <input name="db_user" value="<?= $v('db_user') ?>" autocomplete="off"></label>
          <label>Senha do banco <input name="db_pass" type="password" autocomplete="new-password"></label>
        </div>
      </fieldset>
      <fieldset>
        <legend>Seu usuário (administrador)</legend>
        <label>Nome <input name="admin_name" value="<?= $v('admin_name') ?>" required></label>
        <label>E-mail <input name="admin_email" type="email" value="<?= $v('admin_email') ?>" required></label>
        <label>Senha (mín. 8 caracteres) <input name="admin_password" type="password" minlength="8" required autocomplete="new-password"></label>
        <label>Repita a senha <input name="admin_password2" type="password" minlength="8" required autocomplete="new-password"></label>
      </fieldset>
      <button class="btn primary" type="submit">Instalar</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
