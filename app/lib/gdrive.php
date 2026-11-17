<?php
/**
 * Backup automático no Google Drive, por um Google Apps Script publicado na conta Google do usuário.
 *
 * Sem Google Cloud nem OAuth: a tela Configurações → Sistema → Backup mostra o script pronto (com uma chave secreta
 * gerada aqui), o administrador cola em script.google.com, publica como "App da Web" (executar como ele,
 * acesso a qualquer pessoa) e cola o endereço /exec no sistema, junto com o link da pasta do Drive.
 * O sistema manda o backup (.sql.gz em base64) para o script, que grava na pasta e manda para a lixeira
 * os backups mais antigos além da quantidade a guardar. Sem a chave, o script recusa qualquer envio.
 * O agendador (app/cron.php, chamado a cada ~15 minutos) envia na frequência escolhida.
 *
 * Tudo fica na tabela settings com o prefixo gdrive_ (fora do Settings::all() e fora do backup .sql).
 */

class GDrive
{
    const FILE_PREFIX = 'backup-estoque-';
    const KEEP_DEFAULT = 30;
    const MAX_BYTES = 35 * 1024 * 1024; // o Apps Script aceita até ~50 MB por envio (o base64 aumenta um terço)
    const WEEKDAYS = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];

    /** Transporte HTTP trocável nos testes: fn(url, array $json) => [status, body]. */
    public static $transport = null;

    // ---------- Configurações (tabela settings, prefixo gdrive_) ----------

    public static function get(string $k, string $default = ''): string
    {
        $v = DB::val('SELECT value FROM settings WHERE name = ?', ['gdrive_' . $k]);
        return $v === null || $v === false ? $default : (string)$v;
    }

    public static function set(string $k, ?string $v): void
    {
        $name = 'gdrive_' . $k;
        if (DB::val('SELECT id FROM settings WHERE name = ?', [$name])) {
            DB::run('UPDATE settings SET value = ? WHERE name = ?', [(string)$v, $name]);
        } else {
            DB::insert('settings', ['name' => $name, 'value' => (string)$v]);
        }
    }

    /** Chave secreta que vai dentro do script (gerada na primeira vez). */
    public static function key(): string
    {
        $k = self::get('key');
        if ($k === '') { $k = bin2hex(random_bytes(24)); self::set('key', $k); }
        return $k;
    }

    /** Pronto para enviar: tem endereço do script e pasta testados. */
    public static function connected(): bool
    {
        return self::get('url') !== '' && self::get('folder_id') !== '' && self::get('folder_name') !== '';
    }

    /** Situação para a tela. */
    public static function status(string $now = ''): array
    {
        $now = $now ?: now();
        $folder = self::get('folder_id');
        return [
            'url' => self::get('url'),
            'folder_id' => $folder,
            'folder_url' => $folder ? 'https://drive.google.com/drive/folders/' . rawurlencode($folder) : '',
            'folder_name' => self::get('folder_name'),
            'account' => self::get('account'),
            'connected' => self::connected(),
            'script' => self::script(),
            'freq' => self::get('freq', 'off'),
            'hour' => (int)self::get('hour', '2'),
            'weekday' => (int)self::get('weekday', '0'),
            'keep' => (int)self::get('keep', (string)self::KEEP_DEFAULT),
            'last_ok' => self::get('last_ok'),
            'last_file' => self::get('last_file'),
            'last_size' => (int)self::get('last_size', '0'),
            'last_try' => self::get('last_try'),
            'last_error' => self::get('last_error'),
            'cron_at' => self::get('cron_at'),
            'next_at' => self::connected() ? self::nextAt($now) : null,
            'weekdays' => self::WEEKDAYS,
        ];
    }

    /** "https://drive.google.com/drive/folders/ID?usp=..." (ou só o ID) → ID. */
    public static function folderId(string $s): string
    {
        $s = trim($s);
        if (preg_match('#/folders/([\w-]{10,})#', $s, $m) || preg_match('#[?&]id=([\w-]{10,})#', $s, $m)) return $m[1];
        if (preg_match('#^[\w-]{10,}$#', $s)) return $s;
        throw new AppError('Link da pasta inválido. Abra a pasta no Google Drive e copie o endereço da barra do navegador.');
    }

    /** Endereço do script e pasta: testa a ligação (o script responde o nome da pasta e a conta) e salva. */
    public static function connect(string $url, string $folder): array
    {
        $url = trim($url);
        if (!preg_match('#^https://script\.google\.com/(a/macros/[^/]+|macros)/s/[\w-]{20,}/exec$#', $url)) {
            throw new AppError('Endereço do script inválido. Copie o "URL do app da Web", que começa com https://script.google.com/macros/s/ e termina em /exec.');
        }
        $id = self::folderId($folder);
        $res = self::call($url, ['acao' => 'teste', 'pasta' => $id]);
        self::set('url', $url);
        self::set('folder_id', $id);
        self::set('folder_name', mb_substr(trim((string)($res['pasta'] ?? '')), 0, 200) ?: 'Pasta do Drive');
        self::set('account', mb_substr((string)($res['conta'] ?? ''), 0, 200));
        self::set('last_error', '');
        return self::status();
    }

    public static function disconnect(): void
    {
        foreach (['url', 'folder_id', 'folder_name', 'account'] as $k) self::set($k, '');
    }

    /** Agenda. */
    public static function saveConfig(array $in, string $now = ''): array
    {
        $sched = [self::get('freq', 'off'), self::get('hour', '2'), self::get('weekday', '0')];
        if (array_key_exists('freq', $in)) {
            $f = (string)$in['freq'];
            if (!in_array($f, ['off', 'daily', 'weekly'], true)) throw new AppError('Frequência inválida');
            self::set('freq', $f);
        }
        if (array_key_exists('hour', $in)) self::set('hour', (string)max(0, min(23, (int)$in['hour'])));
        if (array_key_exists('weekday', $in)) self::set('weekday', (string)max(0, min(6, (int)$in['weekday'])));
        if (array_key_exists('keep', $in)) {
            $k = (int)$in['keep'];
            if ($k < 0 || $k > 1000) throw new AppError('Quantidade de backups a guardar: de 1 a 1000 (0 = guardar todos)');
            self::set('keep', (string)$k);
        }
        // Agenda mudou: o primeiro backup é no próximo horário marcado, não na hora
        if ($sched !== [self::get('freq', 'off'), self::get('hour', '2'), self::get('weekday', '0')]) self::set('sched_at', $now ?: now());
        return self::status();
    }

    /** Código do Apps Script, com a chave secreta deste sistema. */
    public static function script(): string
    {
        $key = self::key();
        return <<<JS
// Backup do Sistema de Estoque no Google Drive.
// Gerado pelo sistema (Configurações → Sistema → Backup). Não compartilhe: a chave abaixo libera o envio de arquivos.
const CHAVE = '$key';

function doPost(e) {
  try {
    const d = JSON.parse(e.postData.contents);
    if (d.chave !== CHAVE) return resposta({ ok: false, erro: 'Chave inválida: copie o script de novo pelo sistema.' });
    const pasta = DriveApp.getFolderById(d.pasta);
    if (d.acao === 'teste') {
      return resposta({ ok: true, pasta: pasta.getName(), conta: Session.getEffectiveUser().getEmail() });
    }
    if (d.acao === 'enviar') {
      const arquivo = pasta.createFile(Utilities.newBlob(Utilities.base64Decode(d.dados), 'application/gzip', d.nome));
      // Guarda só os últimos backups (os outros arquivos da pasta não são tocados)
      let removidos = 0;
      if (d.guardar > 0) {
        const lista = [];
        const it = pasta.getFiles();
        while (it.hasNext()) { const f = it.next(); if (f.getName().indexOf(d.prefixo) === 0) lista.push(f); }
        lista.sort(function (a, b) { return b.getDateCreated() - a.getDateCreated(); });
        lista.slice(d.guardar).forEach(function (f) { f.setTrashed(true); removidos++; });
      }
      return resposta({ ok: true, id: arquivo.getId(), removidos: removidos });
    }
    return resposta({ ok: false, erro: 'Ação desconhecida' });
  } catch (erro) {
    return resposta({ ok: false, erro: String(erro && erro.message || erro) });
  }
}

// Rode esta função uma vez pelo botão Executar para autorizar o acesso ao Drive
function autorizar() {
  ScriptApp.requireAllScopes(ScriptApp.AuthMode.FULL); // pede de novo as permissões que faltarem
  DriveApp.getRootFolder();
  Logger.log('Acesso ao Google Drive autorizado. Agora implante como App da Web.');
}

function resposta(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}

JS;
    }

    // ---------- Backup ----------

    /** Envia um backup agora. Devolve ['file', 'size', 'removed']. Erros ficam gravados em last_error. */
    public static function run(string $now = ''): array
    {
        $now = $now ?: now();
        if (!self::connected()) throw new AppError('Configure antes o script e a pasta do Google Drive');
        $lock = fopen(sys_get_temp_dir() . '/estoque-gdrive-' . md5(__DIR__) . '.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) throw new AppError('Já há um backup sendo enviado. Aguarde um pouco.');
        self::set('last_try', $now);
        try {
            $name = self::FILE_PREFIX . date('Y-m-d-His', strtotime($now)) . '.sql.gz';
            $data = gzencode(Backup::sql(), 6);
            if (strlen($data) > self::MAX_BYTES) throw new AppError('O backup ficou grande demais para o Google Apps Script (' . round(strlen($data) / 1048576) . ' MB)');
            $res = self::call(self::get('url'), [
                'acao' => 'enviar', 'pasta' => self::get('folder_id'), 'nome' => $name, 'dados' => base64_encode($data),
                'guardar' => (int)self::get('keep', (string)self::KEEP_DEFAULT), 'prefixo' => self::FILE_PREFIX,
            ]);
            self::set('last_ok', $now);
            self::set('last_file', $name);
            self::set('last_size', (string)strlen($data));
            self::set('last_error', '');
            return ['file' => $name, 'size' => strlen($data), 'removed' => (int)($res['removidos'] ?? 0)];
        } catch (Throwable $e) {
            $msg = $e instanceof AppError ? $e->getMessage() : 'Erro inesperado: ' . $e->getMessage();
            self::set('last_error', mb_substr($msg, 0, 500));
            throw $e instanceof AppError ? $e : new AppError($msg, 500);
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /** Chamado pelo agendador a cada poucos minutos: envia se chegou a hora. Devolve uma linha para o registro, ou ''. */
    public static function cron(string $now = ''): string
    {
        $now = $now ?: now();
        self::set('cron_at', $now);
        if (!self::isDue($now)) return '';
        try {
            $r = self::run($now);
            return 'Backup enviado ao Google Drive: ' . $r['file'] . ($r['removed'] ? " ({$r['removed']} antigo(s) para a lixeira)" : '');
        } catch (AppError $e) {
            return 'ERRO no backup do Google Drive: ' . $e->getMessage();
        }
    }

    /** Horário agendado mais recente (até agora). */
    public static function lastSlot(string $now): ?int
    {
        $freq = self::get('freq', 'off');
        if ($freq === 'off') return null;
        $t = strtotime($now);
        $slot = strtotime(date('Y-m-d', $t) . sprintf(' %02d:00:00', (int)self::get('hour', '2')));
        if ($freq === 'daily') return $slot <= $t ? $slot : strtotime('-1 day', $slot);
        $back = ((int)date('w', $slot) - (int)self::get('weekday', '0') + 7) % 7;
        $slot = strtotime("-$back days", $slot);
        return $slot <= $t ? $slot : strtotime('-7 days', $slot);
    }

    public static function nextAt(string $now): ?string
    {
        $last = self::lastSlot($now);
        if ($last === null) return null;
        if (!self::isDue($now, true)) $last = strtotime(self::get('freq') === 'weekly' ? '+7 days' : '+1 day', $last);
        return date('Y-m-d H:i:s', $last);
    }

    /** Chegou a hora: há um horário agendado depois do último backup e não houve tentativa na última hora. */
    public static function isDue(string $now, bool $ignoreRetry = false): bool
    {
        if (!self::connected()) return false;
        $slot = self::lastSlot($now);
        if ($slot === null) return false;
        $since = self::get('sched_at');
        if ($since !== '' && $slot < strtotime($since)) return false;
        $ok = self::get('last_ok');
        if ($ok !== '' && strtotime($ok) >= $slot) return false;
        $try = self::get('last_try');
        if (!$ignoreRetry && $try !== '' && strtotime($try) > strtotime($now) - 3300 && strtotime($try) >= $slot) return false;
        return true;
    }

    // ---------- HTTP ----------

    /** Chama o script com a chave. Devolve a resposta (ok = true) ou lança o erro em português. */
    private static function call(string $url, array $data): array
    {
        $data = ['chave' => self::key()] + $data;
        if (self::$transport) {
            [$st, $body] = (self::$transport)($url, $data);
        } else {
            if (!function_exists('curl_init')) throw new AppError('A extensão cURL do PHP não está ativa no servidor');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true, // o Google responde com um redirecionamento para a resposta do script
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'SistemaEstoque/' . APP_VERSION,
            ]);
            $body = curl_exec($ch);
            $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) throw new AppError('Sem conexão com o Google (' . $err . ')');
        }
        $res = json_decode((string)$body, true);
        if (!is_array($res)) {
            if ($st === 404) throw new AppError('Script não encontrado: confira o endereço (URL do app da Web).');
            throw new AppError('O script não respondeu como esperado (HTTP ' . $st . '). Confira se a implantação está como "App da Web", executando como você e com acesso a "Qualquer pessoa".');
        }
        if (empty($res['ok'])) {
            $e = (string)($res['erro'] ?? 'erro desconhecido');
            if (preg_match('/getFolderById|DriveApp|auth\/drive|autoriza|authoriz/i', $e)) {
                throw new AppError('O script ainda não tem autorização para usar o seu Google Drive. Abra o projeto em script.google.com, escolha a função "autorizar" na barra de cima (em scripts copiados antes, "doPost"), clique em Executar e marque todas as permissões (principalmente a do Google Drive). Depois clique de novo em Testar e salvar (não precisa implantar de novo).');
            }
            if (preg_match('/not found|no item|não encontrad|access denied|permiss/i', $e)) {
                $e .= ' (confira o link da pasta e se ela é da mesma conta Google do script)';
            }
            throw new AppError('O Google Drive recusou: ' . $e);
        }
        return $res;
    }
}
