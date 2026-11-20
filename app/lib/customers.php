<?php
/**
 * Cadastro de clientes, validação de CPF/CNPJ e consulta automática de CNPJ e CEP.
 */

class Customers
{
    const DEFAULT_NAME = 'Consumidor final';

    const FIELDS = ['name', 'trade_name', 'state_registration', 'email', 'phone', 'cep', 'street', 'number',
        'complement', 'district', 'city', 'state', 'notes'];

    /** A mesma ficha serve para clientes e fornecedores. */
    public static function table(string $t): string
    {
        if (!in_array($t, ['customers', 'suppliers'], true)) throw new AppError('Cadastro inválido');
        return $t;
    }

    public static function get(int $id, string $table = 'customers'): array
    {
        $table = self::table($table);
        $c = DB::one("SELECT * FROM $table WHERE id = ?", [$id]);
        if (!$c) throw new AppError(($table === 'suppliers' ? 'Fornecedor' : 'Cliente') . ' não encontrado', 404);
        return $c;
    }

    /** Cria o cliente "Consumidor final" para vendas de balcão sem identificação. */
    public static function ensureDefault(): int
    {
        $id = DB::val('SELECT id FROM customers WHERE name = ? AND document IS NULL ORDER BY id LIMIT 1', [self::DEFAULT_NAME]);
        if ($id) return (int)$id;
        return DB::insert('customers', [
            'type' => 'pf', 'name' => self::DEFAULT_NAME, 'active' => 1,
            'notes' => 'Para vendas de balcão sem identificar o cliente.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function save(array $in, ?int $id = null, string $table = 'customers'): int
    {
        $table = self::table($table);
        $who = $table === 'suppliers' ? 'fornecedor' : 'cliente';
        $type = ($in['type'] ?? 'pj') === 'pf' ? 'pf' : 'pj';
        $doc = preg_replace('/\D/', '', (string)($in['document'] ?? ''));
        if ($doc !== '') {
            if ($type === 'pj' && !self::validCnpj($doc)) throw new AppError('CNPJ inválido. Confira os números.');
            if ($type === 'pf' && !self::validCpf($doc)) throw new AppError('CPF inválido. Confira os números.');
            $dup = DB::one("SELECT id, name, deleted_at FROM $table WHERE document = ?" . ($id ? ' AND id <> ?' : ''), $id ? [$doc, $id] : [$doc]);
            // Cadastro novo com o documento de um excluído: o excluído volta, com os dados novos (mantém o histórico)
            if ($dup && $dup['deleted_at'] && !$id) $restore = (int)$dup['id'];
            elseif ($dup) throw new AppError("Já existe um $who " . ($dup['deleted_at'] ? 'excluído ' : '') . 'com este ' . ($type === 'pj' ? 'CNPJ' : 'CPF') . ': ' . $dup['name']);
        }
        $data = ['type' => $type, 'document' => $doc === '' ? null : $doc, 'updated_at' => now()];
        $limits = ['name' => 200, 'trade_name' => 200, 'state_registration' => 30, 'email' => 190, 'phone' => 40,
            'cep' => 8, 'street' => 200, 'number' => 20, 'complement' => 200, 'district' => 100, 'city' => 100,
            'state' => 2, 'notes' => 500];
        foreach (self::FIELDS as $f) {
            $v = trim((string)($in[$f] ?? ''));
            if ($f === 'cep') $v = preg_replace('/\D/', '', $v);
            if ($f === 'state') $v = strtoupper($v);
            if ($f === 'phone') $v = self::phoneMask($v);
            $data[$f] = $v === '' ? null : mb_substr($v, 0, $limits[$f]);
        }
        if (!$data['name']) throw new AppError($type === 'pj' ? 'Informe a razão social' : "Informe o nome do $who");
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) throw new AppError('E-mail inválido');
        if (isset($in['active'])) $data['active'] = $in['active'] ? 1 : 0;

        if ($id) {
            if (self::get($id, $table)['deleted_at']) throw new AppError(ucfirst($who) . ' excluído não pode ser alterado');
            DB::update($table, $id, $data);
            return $id;
        }
        if (!empty($restore)) {
            DB::update($table, $restore, array_merge($data, ['active' => 1, 'deleted_at' => null]));
            return $restore;
        }
        $data['created_at'] = now();
        $data['active'] = 1;
        return DB::insert($table, $data);
    }

    /**
     * Encontra (ou cadastra) o fornecedor de uma nota pelo CNPJ/CPF ou, sem documento, pelo nome.
     */
    public static function supplierFor(?string $doc, ?string $name): ?int
    {
        $doc = preg_replace('/\D/', '', (string)$doc);
        $name = trim((string)$name);
        if ($doc !== '') {
            $id = DB::val('SELECT id FROM suppliers WHERE document = ?', [$doc]);
            if ($id) { self::undelete((int)$id); return (int)$id; }
            $valid = strlen($doc) === 14 ? self::validCnpj($doc) : (strlen($doc) === 11 && self::validCpf($doc));
            if ($valid) {
                return DB::insert('suppliers', ['type' => strlen($doc) === 14 ? 'pj' : 'pf', 'document' => $doc,
                    'name' => mb_substr($name ?: 'Fornecedor ' . $doc, 0, 200), 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if ($name === '') return null;
        $id = DB::val('SELECT id FROM suppliers WHERE (LOWER(name) = LOWER(?) OR LOWER(trade_name) = LOWER(?)) AND deleted_at IS NULL ORDER BY id LIMIT 1', [$name, $name]);
        if ($id) return (int)$id;
        return DB::insert('suppliers', ['type' => 'pj', 'name' => mb_substr($name, 0, 200), 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Fornecedor excluído que aparece de novo numa nota importada volta para o cadastro. */
    private static function undelete(int $id): void
    {
        if (DB::val('SELECT deleted_at FROM suppliers WHERE id = ?', [$id])) DB::update('suppliers', $id, ['deleted_at' => null, 'active' => 1, 'updated_at' => now()]);
    }

    // ---------- Validação ----------

    public static function validCnpj(string $c): bool
    {
        $c = preg_replace('/\D/', '', $c);
        if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) return false;
        foreach ([12, 13] as $len) {
            $w = $len === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $len; $i++) $sum += (int)$c[$i] * $w[$i];
            $d = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int)$c[$len] !== $d) return false;
        }
        return true;
    }

    public static function validCpf(string $c): bool
    {
        $c = preg_replace('/\D/', '', $c);
        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
        foreach ([9, 10] as $len) {
            $sum = 0;
            for ($i = 0; $i < $len; $i++) $sum += (int)$c[$i] * ($len + 1 - $i);
            $d = ($sum * 10) % 11 % 10;
            if ((int)$c[$len] !== $d) return false;
        }
        return true;
    }

    // ---------- Consulta de CNPJ ----------

    /**
     * Busca os dados públicos da empresa. Tenta BrasilAPI, depois CNPJ.ws e ReceitaWS
     * (serviços gratuitos que repassam os dados da Receita Federal).
     */
    public static function lookupCnpj(string $cnpj): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (!self::validCnpj($cnpj)) throw new AppError('CNPJ inválido. Confira os números.');
        $sources = [
            ['https://brasilapi.com.br/api/cnpj/v1/' . $cnpj, [self::class, 'fromBrasilApi']],
            ['https://publica.cnpj.ws/cnpj/' . $cnpj, [self::class, 'fromCnpjWs']],
            ['https://receitaws.com.br/v1/cnpj/' . $cnpj, [self::class, 'fromReceitaWs']],
        ];
        $notFound = 0;
        foreach ($sources as [$url, $parser]) {
            [$code, $json] = self::httpJson($url);
            if ($code === 404 || $code === 400) { $notFound++; continue; }
            if ($code !== 200 || !is_array($json)) continue;
            $r = $parser($json);
            if ($r && !empty($r['name'])) {
                $r['document'] = $cnpj;
                $r['type'] = 'pj';
                return $r;
            }
        }
        if ($notFound) throw new AppError('CNPJ não encontrado na Receita Federal.', 404);
        throw new AppError('Os serviços de consulta de CNPJ não responderam agora. Tente de novo em 1 minuto ou preencha manualmente.', 503);
    }

    public static function fromBrasilApi(array $j): ?array
    {
        if (empty($j['razao_social'])) return null;
        $phone = preg_replace('/\D/', '', (string)($j['ddd_telefone_1'] ?? ''));
        $street = trim(($j['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($j['logradouro'] ?? ''));
        return [
            'name' => self::title($j['razao_social']),
            'trade_name' => self::title($j['nome_fantasia'] ?? ''),
            'email' => strtolower(trim((string)($j['email'] ?? ''))) ?: null,
            'phone' => self::fmtPhone($phone),
            'cep' => preg_replace('/\D/', '', (string)($j['cep'] ?? '')),
            'street' => self::title($street),
            'number' => trim((string)($j['numero'] ?? '')),
            'complement' => self::title($j['complemento'] ?? ''),
            'district' => self::title($j['bairro'] ?? ''),
            'city' => self::title($j['municipio'] ?? ''),
            'state' => strtoupper((string)($j['uf'] ?? '')),
            'state_registration' => null,
            'status' => $j['descricao_situacao_cadastral'] ?? null,
        ];
    }

    public static function fromCnpjWs(array $j): ?array
    {
        $e = $j['estabelecimento'] ?? null;
        if (empty($j['razao_social']) || !is_array($e)) return null;
        $ie = null;
        foreach ($e['inscricoes_estaduais'] ?? [] as $i) {
            if (!empty($i['ativo']) && !empty($i['inscricao_estadual'])) { $ie = $i['inscricao_estadual']; break; }
        }
        $street = trim(($e['tipo_logradouro'] ?? '') . ' ' . ($e['logradouro'] ?? ''));
        return [
            'name' => self::title($j['razao_social']),
            'trade_name' => self::title($e['nome_fantasia'] ?? ''),
            'email' => strtolower(trim((string)($e['email'] ?? ''))) ?: null,
            'phone' => self::fmtPhone(($e['ddd1'] ?? '') . ($e['telefone1'] ?? '')),
            'cep' => preg_replace('/\D/', '', (string)($e['cep'] ?? '')),
            'street' => self::title($street),
            'number' => trim((string)($e['numero'] ?? '')),
            'complement' => self::title($e['complemento'] ?? ''),
            'district' => self::title($e['bairro'] ?? ''),
            'city' => self::title($e['cidade']['nome'] ?? ''),
            'state' => strtoupper((string)($e['estado']['sigla'] ?? '')),
            'state_registration' => $ie,
            'status' => $e['situacao_cadastral'] ?? null,
        ];
    }

    public static function fromReceitaWs(array $j): ?array
    {
        if (($j['status'] ?? '') === 'ERROR' || empty($j['nome'])) return null;
        return [
            'name' => self::title($j['nome']),
            'trade_name' => self::title($j['fantasia'] ?? ''),
            'email' => strtolower(trim((string)($j['email'] ?? ''))) ?: null,
            'phone' => self::fmtPhone(trim(explode('/', (string)($j['telefone'] ?? ''))[0])),
            'cep' => preg_replace('/\D/', '', (string)($j['cep'] ?? '')),
            'street' => self::title($j['logradouro'] ?? ''),
            'number' => trim((string)($j['numero'] ?? '')),
            'complement' => self::title($j['complemento'] ?? ''),
            'district' => self::title($j['bairro'] ?? ''),
            'city' => self::title($j['municipio'] ?? ''),
            'state' => strtoupper((string)($j['uf'] ?? '')),
            'state_registration' => null,
            'status' => $j['situacao'] ?? null,
        ];
    }

    // ---------- Consulta de CEP ----------

    public static function lookupCep(string $cep): array
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) throw new AppError('CEP deve ter 8 números');
        [$code, $j] = self::httpJson("https://viacep.com.br/ws/$cep/json/");
        if ($code === 200 && is_array($j) && empty($j['erro'])) {
            return ['cep' => $cep, 'street' => $j['logradouro'] ?? '', 'district' => $j['bairro'] ?? '',
                'city' => $j['localidade'] ?? '', 'state' => $j['uf'] ?? ''];
        }
        [$code, $j] = self::httpJson("https://brasilapi.com.br/api/cep/v1/$cep");
        if ($code === 200 && is_array($j) && !empty($j['city'])) {
            return ['cep' => $cep, 'street' => $j['street'] ?? '', 'district' => $j['neighborhood'] ?? '',
                'city' => $j['city'] ?? '', 'state' => $j['state'] ?? ''];
        }
        if (is_array($j) && (!empty($j['erro']) || $code === 404)) throw new AppError('CEP não encontrado', 404);
        throw new AppError('A consulta de CEP não respondeu agora. Preencha o endereço manualmente.', 503);
    }

    // ---------- Utilidades ----------

    /** GET simples que devolve [status HTTP, JSON decodificado]. */
    public static function httpJson(string $url): array
    {
        if (!function_exists('curl_init')) throw new AppError('A extensão cURL do PHP não está ativa na hospedagem');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'SistemaEstoque/' . APP_VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) return [0, null];
        return [$code, json_decode((string)$body, true)];
    }

    /** "PADARIA DO JOAO LTDA" → "Padaria do Joao Ltda" (mantém siglas curtas como ME, SA, EPP). */
    public static function title($s): ?string
    {
        $s = trim(preg_replace('/\s+/', ' ', (string)$s));
        if ($s === '') return null;
        $small = ['de', 'da', 'do', 'das', 'dos', 'e', 'em'];
        $keep = ['LTDA' => 'Ltda', 'ME' => 'ME', 'EPP' => 'EPP', 'SA' => 'SA', 'S/A' => 'S/A', 'EIRELI' => 'Eireli', 'MEI' => 'MEI', 'SN' => 'S/N'];
        $words = explode(' ', mb_strtolower($s));
        foreach ($words as $i => &$w) {
            $up = mb_strtoupper($w);
            if (isset($keep[$up])) { $w = $keep[$up]; continue; }
            if ($i > 0 && in_array($w, $small, true)) continue;
            $w = mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
        }
        return implode(' ', $words);
    }

    public static function fmtPhone(string $p): ?string
    {
        $p = self::phoneMask($p);
        return $p === '' ? null : $p;
    }

    /**
     * Máscara de telefone brasileiro (a mesma do navegador): (31) 3355-6953, (31) 99876-5432,
     * 0800 123 4567. Tira o +55 da frente. Número estrangeiro (+ outro país) ou fora do padrão fica como veio.
     */
    public static function phoneMask(?string $p): string
    {
        $raw = trim(preg_replace('/\s+/', ' ', (string)$p));
        if ($raw === '') return '';
        $d = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '+') && !str_starts_with($d, '55')) return $raw;
        if ((strlen($d) === 12 || strlen($d) === 13) && str_starts_with($d, '55')) $d = substr($d, 2);
        if (strlen($d) === 11 && preg_match('/^0[3589]00/', $d)) return substr($d, 0, 4) . ' ' . substr($d, 4, 3) . ' ' . substr($d, 7);
        if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1); // 0 + DDD + número
        if (strlen($d) === 12 && $d[0] === '0') $d = substr($d, 1);
        return match (strlen($d)) {
            8 => substr($d, 0, 4) . '-' . substr($d, 4),
            9 => substr($d, 0, 5) . '-' . substr($d, 5),
            10 => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6),
            11 => '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7),
            default => $raw,
        };
    }
}
