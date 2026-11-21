<?php
/**
 * Dados fiscais (tributação) do produto: os valores padrão para uma futura emissão de NF-e.
 * Ficam em products.fiscal (JSON). O sistema não emite nota: aqui só se cadastra e valida.
 * Campos da aba Tributação (Simples Nacional: ICMS por CSOSN).
 */

class Fiscal
{
    const ORIGENS = [
        '0' => '0 - Nacional, exceto as indicadas nos códigos 3 a 5',
        '1' => '1 - Estrangeira - Importação direta, exceto a indicada no código 6',
        '2' => '2 - Estrangeira - Adquirida no mercado interno, exceto a indicada no código 7',
        '3' => '3 - Nacional, com Conteúdo de Importação superior a 40% e inferior ou igual a 70%',
        '4' => '4 - Nacional, produzida conforme os processos produtivos básicos (PPB)',
        '5' => '5 - Nacional, com Conteúdo de Importação inferior ou igual a 40%',
        '6' => '6 - Estrangeira - Importação direta, sem similar nacional (lista Camex) e gás natural',
        '7' => '7 - Estrangeira - Adquirida no mercado interno, sem similar nacional (lista Camex) e gás natural',
        '8' => '8 - Nacional, com Conteúdo de Importação superior a 70%',
    ];

    const CSOSN = [
        '101' => '101 - Tributada pelo Simples Nacional com permissão de crédito',
        '102' => '102 - Tributada pelo Simples Nacional sem permissão de crédito',
        '103' => '103 - Isenção do ICMS no Simples Nacional para faixa de receita bruta',
        '201' => '201 - Tributada pelo Simples Nacional com permissão de crédito e com cobrança do ICMS por substituição tributária',
        '202' => '202 - Tributada pelo Simples Nacional sem permissão de crédito e com cobrança do ICMS por substituição tributária',
        '203' => '203 - Isenção do ICMS no Simples Nacional para faixa de receita bruta e com cobrança do ICMS por substituição tributária',
        '300' => '300 - Imune',
        '400' => '400 - Não tributada pelo Simples Nacional',
        '500' => '500 - ICMS cobrado anteriormente por substituição tributária (substituído) ou por antecipação',
        '900' => '900 - Outros',
    ];
    /** Quais grupos de campos do ICMS cada CSOSN usa. */
    const CSOSN_GROUPS = [
        'cred' => ['101', '201', '900'],          // crédito do Simples
        'own' => ['900'],                          // ICMS próprio
        'st' => ['201', '202', '203', '900'],      // ICMS ST
        'ret' => ['500'],                          // ST retido anteriormente
    ];

    const MOD_BC = [
        '0' => '0 - Margem valor agregado',
        '1' => '1 - Pauta',
        '2' => '2 - Preço tabelado ou máximo sugerido',
        '3' => '3 - Valor da operação',
    ];
    const MOD_BC_ST = [
        '0' => '0 - Preço tabelado ou máximo sugerido',
        '1' => '1 - Lista negativa',
        '2' => '2 - Lista positiva',
        '3' => '3 - Lista neutra',
        '4' => '4 - Margem valor agregado',
        '5' => '5 - Pauta',
    ];

    const CST_PIS_COFINS = [
        '01' => '01 - Operação Tributável com Alíquota Básica',
        '02' => '02 - Operação Tributável com Alíquota Diferenciada',
        '03' => '03 - Operação Tributável com Alíquota por Unidade de Medida de Produto',
        '04' => '04 - Operação Tributável Monofásica - Revenda a Alíquota Zero',
        '05' => '05 - Operação Tributável por Substituição Tributária',
        '06' => '06 - Operação Tributável a Alíquota Zero',
        '07' => '07 - Operação Isenta da Contribuição',
        '08' => '08 - Operação sem Incidência da Contribuição',
        '09' => '09 - Operação com Suspensão da Contribuição',
        '49' => '49 - Outras Operações de Saída',
        '50' => '50 - Crédito - Vinculada Exclusivamente a Receita Tributada no Mercado Interno',
        '51' => '51 - Crédito - Vinculada Exclusivamente a Receita Não-Tributada no Mercado Interno',
        '52' => '52 - Crédito - Vinculada Exclusivamente a Receita de Exportação',
        '53' => '53 - Crédito - Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno',
        '54' => '54 - Crédito - Vinculada a Receitas Tributadas no Mercado Interno e de Exportação',
        '55' => '55 - Crédito - Vinculada a Receitas Não Tributadas no Mercado Interno e de Exportação',
        '56' => '56 - Crédito - Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno e de Exportação',
        '60' => '60 - Crédito Presumido - Aquisição Vinculada Exclusivamente a Receita Tributada no Mercado Interno',
        '61' => '61 - Crédito Presumido - Aquisição Vinculada Exclusivamente a Receita Não-Tributada no Mercado Interno',
        '62' => '62 - Crédito Presumido - Aquisição Vinculada Exclusivamente a Receita de Exportação',
        '63' => '63 - Crédito Presumido - Aquisição Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno',
        '64' => '64 - Crédito Presumido - Aquisição Vinculada a Receitas Tributadas no Mercado Interno e de Exportação',
        '65' => '65 - Crédito Presumido - Aquisição Vinculada a Receitas Não-Tributadas no Mercado Interno e de Exportação',
        '66' => '66 - Crédito Presumido - Aquisição Vinculada a Receitas Tributadas e Não-Tributadas no Mercado Interno e de Exportação',
        '67' => '67 - Crédito Presumido - Outras Operações',
        '70' => '70 - Operação de Aquisição sem Direito a Crédito',
        '71' => '71 - Operação de Aquisição com Isenção',
        '72' => '72 - Operação de Aquisição com Suspensão',
        '73' => '73 - Operação de Aquisição a Alíquota Zero',
        '74' => '74 - Operação de Aquisição sem Incidência da Contribuição',
        '75' => '75 - Operação de Aquisição por Substituição Tributária',
        '98' => '98 - Outras Operações de Entrada',
        '99' => '99 - Outras Operações',
    ];

    const CST_IPI = [
        '00' => '00 - Entrada com recuperação de crédito',
        '01' => '01 - Entrada tributada com alíquota zero',
        '02' => '02 - Entrada isenta',
        '03' => '03 - Entrada não-tributada',
        '04' => '04 - Entrada imune',
        '05' => '05 - Entrada com suspensão',
        '49' => '49 - Outras entradas',
        '50' => '50 - Saída tributada',
        '51' => '51 - Saída tributável com alíquota zero',
        '52' => '52 - Saída isenta',
        '53' => '53 - Saída não-tributada',
        '54' => '54 - Saída imune',
        '55' => '55 - Saída com suspensão',
        '99' => '99 - Outras saídas',
    ];

    const CST_IBS_CBS = [
        '000' => '000 - Tributação integral',
        '010' => '010 - Tributação com alíquotas uniformes (setor financeiro)',
        '011' => '011 - Tributação com alíquotas uniformes reduzidas',
        '200' => '200 - Alíquota reduzida ou zero',
        '220' => '220 - Alíquota fixa',
        '221' => '221 - Alíquota fixa proporcional',
        '222' => '222 - Redução de base de cálculo',
        '400' => '400 - Isenção',
        '410' => '410 - Imunidade e não incidência',
        '510' => '510 - Diferimento',
        '515' => '515 - Diferimento com redução de alíquota',
        '550' => '550 - Suspensão',
        '620' => '620 - Tributação monofásica',
        '800' => '800 - Transferência de crédito',
        '810' => '810 - Ajustes de IBS na ZFM',
        '811' => '811 - Ajustes',
        '820' => '820 - Tributação em documento específico',
        '830' => '830 - Exclusão de base de cálculo',
    ];

    /** CFOPs de saída mais usados (sugestões do campo; qualquer CFOP válido de 4 dígitos é aceito). */
    const CFOP_COMMON = [
        '5101' => 'Venda de produção do estabelecimento',
        '5102' => 'Venda de mercadoria adquirida ou recebida de terceiros',
        '5103' => 'Venda de produção do estabelecimento, efetuada fora do estabelecimento',
        '5104' => 'Venda de mercadoria de terceiros, efetuada fora do estabelecimento',
        '5401' => 'Venda de produção do estabelecimento com ST, como contribuinte substituto',
        '5403' => 'Venda de mercadoria de terceiros com ST, como contribuinte substituto',
        '5405' => 'Venda de mercadoria de terceiros com ST, como contribuinte substituído',
        '5202' => 'Devolução de compra para comercialização',
        '5411' => 'Devolução de compra para comercialização com ST',
        '5551' => 'Venda de bem do ativo imobilizado',
        '5910' => 'Remessa em bonificação, doação ou brinde',
        '5911' => 'Remessa de amostra grátis',
        '5915' => 'Remessa de mercadoria ou bem para conserto ou reparo',
        '5916' => 'Retorno de mercadoria ou bem recebido para conserto ou reparo',
        '5933' => 'Prestação de serviço tributado pelo ISSQN',
        '5949' => 'Outra saída de mercadoria ou prestação de serviço não especificado',
        '6101' => 'Venda de produção do estabelecimento (outro estado)',
        '6102' => 'Venda de mercadoria adquirida ou recebida de terceiros (outro estado)',
        '6107' => 'Venda de produção do estabelecimento, destinada a não contribuinte (outro estado)',
        '6108' => 'Venda de mercadoria de terceiros, destinada a não contribuinte (outro estado)',
        '6403' => 'Venda de mercadoria de terceiros com ST, como contribuinte substituto (outro estado)',
        '6404' => 'Venda de mercadoria com ST cujo imposto já foi retido anteriormente (outro estado)',
        '6202' => 'Devolução de compra para comercialização (outro estado)',
        '6910' => 'Remessa em bonificação, doação ou brinde (outro estado)',
        '6915' => 'Remessa de mercadoria ou bem para conserto ou reparo (outro estado)',
        '6916' => 'Retorno de mercadoria ou bem recebido para conserto ou reparo (outro estado)',
        '6949' => 'Outra saída de mercadoria ou prestação de serviço não especificado (outro estado)',
    ];

    const CALC_PIS_COFINS = ['' => 'Não calcular', 'percentual' => 'Percentual', 'valor' => 'Valor'];
    const CALC_IPI = ['' => 'Não calcular', 'percentual' => 'Percentual'];

    /** Listas para as telas (GET meta). */
    public static function options(): array
    {
        return [
            'origens' => self::ORIGENS, 'csosn' => self::CSOSN, 'csosn_groups' => self::CSOSN_GROUPS,
            'mod_bc' => self::MOD_BC, 'mod_bc_st' => self::MOD_BC_ST, 'cst_pis_cofins' => self::CST_PIS_COFINS,
            'cst_ipi' => self::CST_IPI, 'cst_ibs_cbs' => self::CST_IBS_CBS, 'cfop' => self::CFOP_COMMON,
            'calc_pis_cofins' => self::CALC_PIS_COFINS, 'calc_ipi' => self::CALC_IPI,
        ];
    }

    /** Lê o JSON gravado no produto. */
    public static function decode($json): array
    {
        if (is_array($json)) return $json;
        $d = json_decode((string)$json, true);
        return is_array($d) ? $d : [];
    }

    /** JSON para gravar (null quando não há nada preenchido). */
    public static function encode(array $f): ?string
    {
        return $f ? json_encode($f, JSON_UNESCAPED_UNICODE) : null;
    }

    private static function pick($v, array $list, string $label): ?string
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') return null;
        if (!isset($list[$v])) throw new AppError("$label inválido");
        return $v;
    }

    private static function digits($v, array $sizes, string $label): ?string
    {
        $v = preg_replace('/\D/', '', (string)($v ?? ''));
        if ($v === '') return null;
        if (!in_array(strlen($v), $sizes, true)) throw new AppError("$label deve ter " . implode(' ou ', $sizes) . ' dígitos');
        return $v;
    }

    private static function pct($v, string $label, float $max = 100): ?float
    {
        if ($v === null || trim((string)$v) === '') return null;
        $n = Inventory::num($v);
        if ($n < 0 || $n > $max) throw new AppError("$label deve ficar entre 0 e " . str_replace('.', ',', (string)$max));
        return round($n, 4);
    }

    private static function money($v, string $label): ?float
    {
        if ($v === null || trim((string)$v) === '') return null;
        $n = Inventory::num($v);
        if ($n < 0) throw new AppError("$label não pode ser negativo");
        return round($n, 4);
    }

    /**
     * Valida e limpa os dados vindos da tela. Campos que não se aplicam à situação escolhida
     * (ex.: ICMS ST com CSOSN 102) são descartados. Devolve [] se nada foi preenchido.
     */
    public static function normalize($in): array
    {
        $in = self::decode($in);
        $s = fn($k) => $in[$k] ?? null;
        $sub = fn($k) => is_array($in[$k] ?? null) ? $in[$k] : [];

        $cfop = self::digits($s('cfop'), [4], 'CFOP');
        if ($cfop !== null && !in_array($cfop[0], ['1', '2', '3', '5', '6', '7'], true)) throw new AppError('CFOP inválido');
        $unit = mb_strtoupper(trim((string)($s('unidade') ?? '')));
        $desc = trim(preg_replace('/\s+/', ' ', (string)($s('descricao') ?? '')));
        $cbenef = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($s('cbenef') ?? '')));
        if ($cbenef !== '' && strlen($cbenef) > 10) throw new AppError('Código de benefício fiscal (cBenef) deve ter até 10 caracteres');

        $out = [
            'origem' => self::pick($s('origem'), self::ORIGENS, 'Origem'),
            'unidade' => $unit !== '' ? mb_substr($unit, 0, 6) : null,
            'ncm' => self::digits($s('ncm'), [2, 8], 'NCM'),
            'cest' => self::digits($s('cest'), [7], 'CEST'),
            'cfop' => $cfop,
            'descricao' => $desc !== '' ? mb_substr($desc, 0, 120) : null,
            'cbenef' => $cbenef !== '' ? $cbenef : null,
        ];

        // ---- ICMS (Simples Nacional)
        $i = $sub('icms');
        $csosn = self::pick($i['csosn'] ?? null, self::CSOSN, 'CSOSN do ICMS');
        $has = fn($g) => $csosn !== null && in_array($csosn, self::CSOSN_GROUPS[$g], true);
        $icms = ['csosn' => $csosn];
        if ($has('cred')) $icms['p_cred_sn'] = self::pct($i['p_cred_sn'] ?? null, 'Alíquota de crédito do Simples');
        if ($has('own')) {
            $icms['mod_bc'] = self::pick($i['mod_bc'] ?? null, self::MOD_BC, 'Modalidade da BC do ICMS');
            $icms['p_red_bc'] = self::pct($i['p_red_bc'] ?? null, 'Redução da BC do ICMS');
            $icms['p_icms'] = self::pct($i['p_icms'] ?? null, 'Alíquota do ICMS');
        }
        if ($has('st')) {
            $icms['mod_bc_st'] = self::pick($i['mod_bc_st'] ?? null, self::MOD_BC_ST, 'Modalidade da BC do ICMS ST');
            $icms['p_mva_st'] = self::pct($i['p_mva_st'] ?? null, 'MVA do ICMS ST', 999);
            $icms['p_red_bc_st'] = self::pct($i['p_red_bc_st'] ?? null, 'Redução da BC do ICMS ST');
            $icms['p_icms_st'] = self::pct($i['p_icms_st'] ?? null, 'Alíquota do ICMS ST');
        }
        if ($has('ret')) {
            $icms['v_bc_st_ret'] = self::money($i['v_bc_st_ret'] ?? null, 'Base de cálculo do ICMS retido');
            $icms['p_st'] = self::pct($i['p_st'] ?? null, 'Alíquota suportada pelo consumidor final');
            $icms['v_icms_substituto'] = self::money($i['v_icms_substituto'] ?? null, 'ICMS próprio do substituto');
            $icms['v_icms_st_ret'] = self::money($i['v_icms_st_ret'] ?? null, 'ICMS ST retido');
        }
        $out['icms'] = $icms;

        // ---- PIS e COFINS
        foreach (['pis' => 'PIS', 'cofins' => 'COFINS'] as $k => $label) {
            $x = $sub($k);
            $calc = trim((string)($x['calc'] ?? ''));
            if (!isset(self::CALC_PIS_COFINS[$calc])) throw new AppError("Tipo de cálculo do $label inválido");
            $g = ['cst' => self::pick($x['cst'] ?? null, self::CST_PIS_COFINS, "Situação tributária do $label"), 'calc' => $calc ?: null];
            if ($calc === 'percentual') {
                $g['bc'] = self::money($x['bc'] ?? null, "Base de cálculo do $label");
                $g['p'] = self::pct($x['p'] ?? null, "Alíquota do $label");
            } elseif ($calc === 'valor') {
                $g['v_unit'] = self::money($x['v_unit'] ?? null, "Valor unitário do $label");
            }
            $out[$k] = $g;
        }

        // ---- IPI
        $x = $sub('ipi');
        $calc = trim((string)($x['calc'] ?? ''));
        if (!isset(self::CALC_IPI[$calc])) throw new AppError('Tipo de cálculo do IPI inválido');
        $ipi = [
            'cst' => self::pick($x['cst'] ?? null, self::CST_IPI, 'Situação tributária do IPI'),
            'enq' => self::digits($x['enq'] ?? null, [3], 'Código de enquadramento do IPI'),
            'calc' => $calc ?: null,
        ];
        if ($calc === 'percentual') {
            $ipi['bc'] = self::money($x['bc'] ?? null, 'Base de cálculo do IPI');
            $ipi['p'] = self::pct($x['p'] ?? null, 'Alíquota do IPI');
        }
        if (!empty($x['devolvido'])) {
            $ipi['devolvido'] = true;
            $ipi['p_devol'] = self::pct($x['p_devol'] ?? null, 'Percentual da mercadoria devolvida');
            $ipi['v_devol'] = self::money($x['v_devol'] ?? null, 'Valor do IPI devolvido');
        }
        $out['ipi'] = $ipi;

        // ---- IBS / CBS (reforma tributária)
        $x = $sub('ibs_cbs');
        $out['ibs_cbs'] = [
            'cst' => self::pick($x['cst'] ?? null, self::CST_IBS_CBS, 'CST do IBS/CBS'),
            'cclass' => self::digits($x['cclass'] ?? null, [6], 'Classificação tributária do IBS/CBS'),
        ];

        return self::prune($out);
    }

    /** Remove vazios (null, grupos vazios). */
    private static function prune(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) { $v = self::prune($v); if ($v) $a[$k] = $v; else unset($a[$k]); }
            elseif ($v === null || $v === '') unset($a[$k]);
        }
        return $a;
    }

    /**
     * Ao importar nota de compra: completa NCM, CEST e Origem do produto quando ainda estão em branco.
     * Origem 1/6 (importação direta pelo fornecedor) vira 2/7 (estrangeira adquirida no mercado interno).
     */
    /** $mode (Configurações → Notas fiscais): vazios = só preenche o que falta | sempre = atualiza | nunca = não mexe. */
    public static function fillFromInvoice(int $productId, ?string $ncm, ?string $cest, ?string $orig, string $mode = 'vazios'): bool
    {
        if ($mode === 'nunca') return false;
        $all = $mode === 'sempre';
        $p = DB::one('SELECT type, fiscal FROM products WHERE id = ? AND company_id = ?', [$productId, Tenant::need()]);
        if (!$p || $p['type'] === 'servico') return false;
        $f = self::decode($p['fiscal']);
        $ncm = preg_replace('/\D/', '', (string)$ncm);
        $cest = preg_replace('/\D/', '', (string)$cest);
        $orig = trim((string)$orig);
        $orig = ['1' => '2', '6' => '7'][$orig] ?? $orig;
        $changed = false;
        if (($all || empty($f['ncm'])) && strlen($ncm) === 8 && ($f['ncm'] ?? null) !== $ncm) { $f['ncm'] = $ncm; $changed = true; }
        if (($all || empty($f['cest'])) && strlen($cest) === 7 && ($f['cest'] ?? null) !== $cest) { $f['cest'] = $cest; $changed = true; }
        if (($all || !isset($f['origem'])) && isset(self::ORIGENS[$orig]) && ($f['origem'] ?? null) !== $orig) { $f['origem'] = $orig; $changed = true; }
        if ($changed) DB::update('products', $productId, ['fiscal' => self::encode($f)]);
        return $changed;
    }
}
