<?php
/**
 * Configurações gerais (dados da empresa que aparecem no orçamento).
 */

class Settings
{
    const DEFAULTS = [
        'company_name' => '',
        'company_legal_name' => '',
        'company_document' => '',
        'company_phone' => '',
        'company_email' => '',
        'company_address' => '',
        'company_cep' => '',
        'company_street' => '',
        'company_number' => '',
        'company_complement' => '',
        'company_district' => '',
        'company_city' => '',
        'company_state' => '',
        'company_logo' => '',
        'company_site' => '',
        'quote_validity_days' => '10',
        'quote_on_sent' => 'enviado',
        'quote_on_approved' => 'aprovado',
        'quote_on_refused' => 'recusado',
        'quote_default_notes' => '',
        'cash_opening_balance' => '0',
        'company_branches' => '[]',
        'po_default_notes' => '',
        // Vendas → Comportamento
        'sale_negative_stock' => '0',   // 1 = deixa vender sem estoque (fica negativo)
        'sale_default_customer' => '0', // 1 = nova venda já abre com "Consumidor final"
        'sale_default_method' => '',
        'sale_cash_open' => '0',        // 1 = venda à vista entra "Em aberto" no financeiro
        'sale_max_discount' => '0',     // % máximo de desconto para não administradores (0 = sem limite)
        'sale_cancel_admin' => '0',     // 1 = só administradores cancelam venda
        'sale_cancel_days' => '0',      // prazo para cancelar (não administradores; 0 = sem prazo)
        'sale_default_notes' => '',
        // Notas fiscais → Comportamento
        'invoice_cnpj_mode' => 'cadastrados', // cadastrados | qualquer
        'invoice_payables' => 'duplicatas',   // duplicatas | sempre | nunca
        'invoice_pay_method' => 'boleto',
        'invoice_fiscal' => 'vazios',         // vazios | sempre | nunca
        'invoice_price' => 'manter',          // manter | sugerir | atualizar
        'invoice_markup' => '0',              // margem (%) sobre o custo para o preço de venda
        // Ordens de compra → Comportamento
        'po_delivery_days' => '0',
        'po_default_method' => '',
        'po_default_terms' => '',
        'po_statuses' => '{}',                // nomes e cores trocados: {"enviada": {"label": "...", "color": "#..."}}
        'po_supplier_confirm' => '1',
        'po_message' => '',
    ];

    /** Escolhas válidas dos campos de comportamento (o primeiro é o padrão). */
    const CHOICES = [
        'invoice_cnpj_mode' => ['cadastrados', 'qualquer'],
        'invoice_payables' => ['duplicatas', 'sempre', 'nunca'],
        'invoice_fiscal' => ['vazios', 'sempre', 'nunca'],
        'invoice_price' => ['manter', 'sugerir', 'atualizar'],
    ];
    const FLAGS = ['sale_negative_stock', 'sale_default_customer', 'sale_cash_open', 'sale_cancel_admin', 'po_supplier_confirm'];
    const PO_STATUS_CODES = ['rascunho', 'enviada', 'confirmada', 'recebida', 'cancelada'];

    public static function all(): array
    {
        $out = self::DEFAULTS;
        foreach (DB::all('SELECT name, value FROM settings') as $r) {
            if (array_key_exists($r['name'], $out)) $out[$r['name']] = (string)$r['value'];
        }
        return $out;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    const LOGO_MAX_BYTES = 600 * 1024;

    public static function save(array $in): array
    {
        if (array_key_exists('company_logo', $in)) $in['company_logo'] = self::validLogo((string)$in['company_logo']);
        // Endereço em campos: monta também a linha única usada no cabeçalho do orçamento
        $parts = ['company_street', 'company_number', 'company_complement', 'company_district', 'company_city', 'company_state', 'company_cep'];
        if (array_intersect($parts, array_keys($in))) {
            $g = fn($k) => trim((string)($in[$k] ?? ''));
            $cep = preg_replace('/\D/', '', $g('company_cep'));
            $in['company_address'] = implode(' · ', array_filter([
                implode(', ', array_filter([$g('company_street'), $g('company_number')])),
                $g('company_complement'), $g('company_district'),
                implode('/', array_filter([$g('company_city'), strtoupper($g('company_state'))])),
                strlen($cep) === 8 ? 'CEP ' . substr($cep, 0, 5) . '-' . substr($cep, 5) : '',
            ]));
            $in['company_cep'] = $cep;
            $in['company_state'] = strtoupper(mb_substr($g('company_state'), 0, 2));
        }
        if (array_key_exists('company_branches', $in)) $in['company_branches'] = json_encode(self::cleanBranches($in['company_branches']), JSON_UNESCAPED_UNICODE);
        if (DB::driver() === 'mysql') self::ensureLargeValue();
        DB::tx(function () use ($in) {
            foreach (self::DEFAULTS as $k => $_) {
                if (!array_key_exists($k, $in)) continue;
                $v = trim((string)$in[$k]);
                if ($k === 'company_document') $v = preg_replace('/\D/', '', $v);
                if ($k === 'company_phone') $v = Customers::phoneMask($v);
                if ($k === 'quote_validity_days') $v = (string)max(0, min(365, (int)$v));
                if (in_array($k, ['sale_cancel_days', 'po_delivery_days'], true)) $v = (string)max(0, min(365, (int)$v));
                if ($k === 'sale_max_discount') $v = (string)max(0, min(100, round(Inventory::num($v), 2)));
                if ($k === 'invoice_markup') $v = (string)max(0, min(1000, round(Inventory::num($v), 2)));
                if (isset(self::CHOICES[$k]) && !in_array($v, self::CHOICES[$k], true)) $v = self::CHOICES[$k][0];
                if (in_array($k, self::FLAGS, true)) $v = $v === '1' || $v === 'on' || $v === 'true' ? '1' : '0';
                if (in_array($k, ['sale_default_method', 'invoice_pay_method', 'po_default_method'], true) && $v !== '' && !PaymentMethods::exists($v)) {
                    throw new AppError('Forma de pagamento inválida');
                }
                if ($k === 'po_statuses') $v = json_encode(self::cleanPoStatuses($in[$k]), JSON_UNESCAPED_UNICODE);
                if (!in_array($k, ['company_logo', 'company_branches', 'po_statuses'], true)) $v = mb_substr($v, 0, 2000);
                if (DB::val('SELECT id FROM settings WHERE name = ?', [$k])) {
                    DB::run('UPDATE settings SET value = ? WHERE name = ?', [$v, $k]);
                } else {
                    DB::insert('settings', ['name' => $k, 'value' => $v]);
                }
            }
        });
        return self::all();
    }

    /** Nomes e cores dos status da ordem de compra (só os trocados ficam guardados). */
    public static function cleanPoStatuses($list): array
    {
        if (is_string($list)) $list = json_decode($list, true) ?: [];
        $out = [];
        foreach (self::PO_STATUS_CODES as $code) {
            $r = (array)($list[$code] ?? []);
            $label = mb_substr(trim((string)($r['label'] ?? '')), 0, 40);
            $color = strtolower(trim((string)($r['color'] ?? '')));
            $row = [];
            if ($label !== '') $row['label'] = $label;
            if (preg_match('/^#[0-9a-f]{6}$/', $color)) $row['color'] = $color;
            if ($row) $out[$code] = $row;
        }
        return $out;
    }

    /** Configurações de comportamento que o navegador precisa (vão no GET meta, para todos os usuários). */
    public static function behavior(): array
    {
        $a = self::all();
        $out = [];
        foreach ($a as $k => $v) if (preg_match('/^(sale|invoice|po)_/', $k)) $out[$k] = $v;
        $out['po_statuses'] = json_decode($a['po_statuses'], true) ?: [];
        if ($a['sale_default_customer'] === '1') {
            $c = DB::one('SELECT id, name, trade_name, document, phone, city, state FROM customers WHERE id = ?', [Customers::ensureDefault()]);
            $out['sale_customer'] = $c ?: null;
        }
        return $out;
    }

    /** CNPJs aceitos além do principal: lista de ['cnpj' => 14 dígitos, 'name' => apelido]. Recebe array ou JSON. */
    public static function cleanBranches($list): array
    {
        if (is_string($list)) $list = json_decode($list, true) ?: [];
        $out = []; $seen = [];
        $main = preg_replace('/\D/', '', (string)self::get('company_document'));
        foreach ((array)$list as $b) {
            $cnpj = preg_replace('/\D/', '', (string)($b['cnpj'] ?? ''));
            $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80);
            if ($cnpj === '' && $name === '') continue;
            if (!Customers::validCnpj($cnpj)) throw new AppError('CNPJ inválido na lista de CNPJs aceitos: ' . ($b['cnpj'] ?? ''));
            if (isset($seen[$cnpj]) || $cnpj === $main) continue;
            if (count($out) >= 30) throw new AppError('Cadastre no máximo 30 CNPJs aceitos');
            $seen[$cnpj] = true;
            $out[] = ['cnpj' => $cnpj, 'name' => $name];
        }
        return $out;
    }

    /** CNPJs aceitos nas notas: o principal e os da lista de CNPJs aceitos (Notas fiscais → Comportamento). */
    public static function companyDocs(): array
    {
        $docs = [];
        $main = preg_replace('/\D/', '', self::get('company_document'));
        if (strlen($main) === 14 || strlen($main) === 11) $docs[$main] = 'empresa';
        foreach (json_decode(self::get('company_branches'), true) ?: [] as $b) {
            if (!empty($b['cnpj'])) $docs[$b['cnpj']] = $b['name'] ?: 'CNPJ aceito';
        }
        return $docs;
    }

    /** Aceita PNG, JPEG ou WebP em "data:image/...;base64," de até 600 KB. Vazio remove o logo. */
    public static function validLogo(string $data): string
    {
        $data = trim($data);
        if ($data === '') return '';
        if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
            throw new AppError('Envie o logo em PNG, JPG ou WebP');
        }
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > self::LOGO_MAX_BYTES) throw new AppError('O logo é grande demais (máx. 600 KB)');
        $info = @getimagesizefromstring($bin);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new AppError('Arquivo de imagem inválido');
        }
        return $data;
    }

    /** No MySQL a coluna precisa ser MEDIUMTEXT para caber o logo (bancos criados antes da v1.6 usam TEXT). */
    private static function ensureLargeValue(): void
    {
        $t = DB::val("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'value'");
        if ($t && strtolower($t) === 'text') DB::pdo()->exec('ALTER TABLE settings MODIFY value MEDIUMTEXT NULL');
    }
}
