<?php
/**
 * Leitura de notas fiscais (NF-e/NFC-e em XML, DANFE em PDF, NFC-e pelo QR Code)
 * e entrada automática no estoque.
 *
 * Todos os leitores devolvem o mesmo formato:
 * [kind, access_key, number, series, supplier_name, supplier_cnpj, issued_at, total,
 *  items => [[code, ean, description, unit, qty, unit_price, total, cost_total]], warnings => []]
 */

class Invoices
{
    // ---------- XML da NF-e / NFC-e ----------

    public static function parseXml(string $xml): array
    {
        $xml = trim($xml);
        // remove BOM
        if (str_starts_with($xml, "\xEF\xBB\xBF")) $xml = substr($xml, 3);
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) throw new AppError('O arquivo não é um XML válido');

        $xp = new DOMXPath($doc);
        // Trabalha sem depender do namespace
        $q = fn(string $path, $ctx = null) => $xp->query(
            preg_replace('#/(\w+)#', '/*[local-name()="$1"]', $path), $ctx);
        $t = function (string $path, $ctx = null) use ($q): ?string {
            $n = $q($path, $ctx);
            return $n && $n->length ? trim($n->item(0)->textContent) : null;
        };

        $inf = $q('//infNFe');
        if (!$inf || !$inf->length) throw new AppError('Este XML não é de uma NF-e/NFC-e (infNFe não encontrado)');
        $inf = $inf->item(0);
        $key = preg_replace('/\D/', '', (string)$inf->getAttribute('Id'));
        if (strlen($key) !== 44) $key = preg_replace('/\D/', '', (string)$t('//protNFe//chNFe')) ?: null;

        $mod = $t('./ide/mod', $inf);
        $tpNF = $t('./ide/tpNF', $inf);
        $warnings = [];
        if ($tpNF === '0') $warnings[] = 'Esta é uma nota de ENTRADA emitida pelo próprio emitente (tpNF=0). Confira se é mesmo uma compra.';

        $items = [];
        foreach ($q('./det', $inf) as $det) {
            $vProd = (float)$t('./prod/vProd', $det);
            $extra = (float)$t('./prod/vFrete', $det) + (float)$t('./prod/vSeg', $det)
                + (float)$t('./prod/vOutro', $det) - (float)$t('./prod/vDesc', $det)
                + (float)$t('./imposto/IPI/IPITrib/vIPI', $det)
                + (float)$t('./imposto/ICMS/*/vICMSST', $det)
                + (float)$t('./imposto/ICMS/*/vFCPST', $det);
            $ean = $t('./prod/cEAN', $det);
            if (!$ean || !preg_match('/^\d{8,14}$/', $ean)) $ean = $t('./prod/cEANTrib', $det);
            if (!$ean || !preg_match('/^\d{8,14}$/', $ean)) $ean = null;
            $qty = (float)$t('./prod/qCom', $det);
            $items[] = [
                'code' => $t('./prod/cProd', $det),
                'ean' => $ean,
                'description' => $t('./prod/xProd', $det),
                'unit' => strtoupper((string)$t('./prod/uCom', $det)),
                'ncm' => $t('./prod/NCM', $det),
                'cest' => $t('./prod/CEST', $det),
                'orig' => $t('./imposto/ICMS/*/orig', $det),
                'qty' => $qty,
                'unit_price' => (float)$t('./prod/vUnCom', $det),
                'total' => round($vProd, 2),
                'cost_total' => round($vProd + $extra, 2),
            ];
        }
        if (!$items) throw new AppError('Nenhum item encontrado no XML');

        $dh = $t('./ide/dhEmi', $inf) ?: $t('./ide/dEmi', $inf);
        // Faturamento: duplicatas (boletos) com vencimento e valor
        $dups = [];
        foreach ($q('./cobr/dup', $inf) as $d) {
            $venc = self::parseDate($t('./dVenc', $d));
            $val = (float)$t('./vDup', $d);
            if ($venc && $val > 0) $dups[] = ['number' => $t('./nDup', $d), 'due_date' => substr($venc, 0, 10), 'amount' => round($val, 2)];
        }
        $tPag = $t('./pag/detPag/tPag', $inf);
        return [
            'kind' => $mod === '65' ? 'nfce' : 'nfe',
            'access_key' => strlen((string)$key) === 44 ? $key : null,
            'number' => $t('./ide/nNF', $inf),
            'series' => $t('./ide/serie', $inf),
            'supplier_name' => $t('./emit/xFant', $inf) ?: $t('./emit/xNome', $inf),
            'supplier_cnpj' => $t('./emit/CNPJ', $inf) ?: $t('./emit/CPF', $inf),
            'recipient_doc' => $t('./dest/CNPJ', $inf) ?: $t('./dest/CPF', $inf),
            'recipient_name' => $t('./dest/xNome', $inf),
            'issued_at' => self::parseDate($dh),
            'total' => (float)$t('./total/ICMSTot/vNF', $inf),
            'items' => $items,
            'billing_number' => $t('./cobr/fat/nFat', $inf),
            'installments' => $dups,
            'payment_method' => (function ($m) { return PaymentMethods::exists($m) ? $m : null; })(self::PAG_METHODS[$tPag] ?? ($dups ? 'boleto' : null)),
            'warnings' => $warnings,
        ];
    }

    /** Código tPag da NF-e → forma de pagamento do financeiro. */
    const PAG_METHODS = ['01' => 'dinheiro', '02' => 'cheque', '03' => 'credito', '04' => 'debito', '15' => 'boleto',
        '16' => 'transferencia', '17' => 'pix', '18' => 'transferencia', '99' => 'outro'];

    /** Duplicatas do quadro "FATURA / DUPLICATA" do DANFE: número, vencimento e valor. */
    public static function danfeInstallments(string $flat): array
    {
        if (!preg_match('/FATURA(?:\s*\/\s*DUPLICATAS?)?|DUPLICATAS?/iu', $flat, $m, PREG_OFFSET_CAPTURE)) return [];
        $start = $m[0][1];
        $end = preg_match('/C[ÁA]LCULO\s+DO\s+IMPOSTO/iu', $flat, $e, PREG_OFFSET_CAPTURE, $start) ? $e[0][1] : $start + 1500;
        $block = substr($flat, $start, $end - $start);
        $out = [];
        if (preg_match_all('/(?:(\d{1,4}(?:[\/\-]\d{1,3})?)\s+)?(\d{2}\/\d{2}\/\d{4})\s+(?:R\$\s*)?((?:\d{1,3}(?:\.\d{3})*|\d+),\d{2})/u', $block, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $r) {
                $d = self::parseDate($r[2]);
                $v = Inventory::num($r[3]);
                if ($d && $v > 0) $out[] = ['number' => $r[1] !== '' ? $r[1] : null, 'due_date' => substr($d, 0, 10), 'amount' => round($v, 2)];
            }
        }
        return $out;
    }

    // ---------- PDF do DANFE ----------

    public static function parseDanfePdf(string $path): array
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $text = $pdf->getText();
        } catch (Throwable $e) {
            throw new AppError('Não consegui ler este PDF (' . $e->getMessage() . ')');
        }
        if (trim($text) === '') {
            throw new AppError('Este PDF não tem texto (parece ser uma imagem escaneada). Use o XML da nota ou lance os itens manualmente.');
        }
        return self::parseDanfeText($text);
    }

    public static function parseDanfeText(string $text): array
    {
        $text = str_replace(["\r", "\xC2\xA0"], ["", ' '], $text);
        $flat = preg_replace('/[ \t]+/', ' ', $text);
        $warnings = [];

        // Chave de acesso: 44 dígitos, normalmente em grupos de 4
        $key = null;
        if (preg_match_all('/(?<!\d)(\d{4}[ .]?){10}\d{4}(?!\d)/', $flat, $m)) {
            foreach ($m[0] as $cand) {
                $k = preg_replace('/\D/', '', $cand);
                if (strlen($k) === 44 && self::validKey($k)) { $key = $k; break; }
            }
        }
        $cnpj = $number = $series = $issued = null;
        if ($key) {
            $cnpj = substr($key, 6, 14);
            $series = (string)(int)substr($key, 22, 3);
            $number = (string)(int)substr($key, 25, 9);
        } else {
            $warnings[] = 'Não encontrei a chave de acesso no PDF. Não será possível bloquear importação duplicada desta nota.';
            if (preg_match('/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/', $flat, $c)) $cnpj = preg_replace('/\D/', '', $c[0]);
        }

        $name = null;
        if (preg_match('/RECEBEMOS\s+DE\s+(.+?)\s+OS\s+PRODUTOS/isu', $flat, $n)) {
            $name = trim(preg_replace('/\s+/', ' ', $n[1]));
        }
        if (preg_match('/(?:DATA\s+DA\s+EMISS[ÃA]O|EMISS[ÃA]O)\D{0,40}(\d{2}\/\d{2}\/\d{4})/iu', $flat, $d)) {
            $issued = self::parseDate($d[1]);
        }
        $vNF = self::labelValue($flat, 'VALOR\s+TOTAL\s+DA\s+NOTA');
        $vProdTotal = self::labelValue($flat, 'VALOR\s+TOTAL\s+DOS\s+PRODUTOS');

        // Itens: CÓDIGO DESCRIÇÃO NCM CST CFOP UN QTD V.UNIT V.TOTAL ...
        $num = '(?:\d{1,3}(?:\.\d{3})*|\d+),\d{1,10}|\d+';
        $re = '/(?:^|\n)\s*(\S{1,30})\s+(.+?)\s+(\d{8})\s+(\d{3,4})\s+([1-7]\d{3})\s+([A-Za-z]{1,6}\d{0,3})\s+(' . $num . ')\s+(' . $num . ')\s+(' . $num . ')/u';
        $items = [];
        $lines = preg_replace('/[ \t]+/', ' ', $text);
        if (preg_match_all($re, $lines, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $r) {
                $qty = Inventory::num($r[7]);
                $vun = Inventory::num($r[8]);
                $vtot = Inventory::num($r[9]);
                // Confere se qtd x unitário bate com o total (tolerância de 2%)
                if ($qty > 0 && $vtot > 0 && abs($qty * $vun - $vtot) > max(0.05, $vtot * 0.02)) continue;
                $items[] = [
                    'code' => $r[1],
                    'ean' => null,
                    'description' => trim($r[2]),
                    'ncm' => $r[3],
                    'unit' => strtoupper($r[6]),
                    'qty' => $qty,
                    'unit_price' => $vun,
                    'total' => round($vtot, 2),
                    'cost_total' => round($vtot, 2),
                ];
            }
        }
        $sum = array_sum(array_column($items, 'total'));
        if (!$items) {
            $warnings[] = 'Não consegui identificar os itens neste DANFE. Lance os itens manualmente abaixo ou use o XML da nota.';
        } elseif ($vNF && $sum > 0 && abs($vNF - $sum) > 0.01) {
            // Distribui frete, impostos (IPI/ST), descontos e outras despesas proporcionalmente
            $ratio = $vNF / $sum;
            foreach ($items as &$it) $it['cost_total'] = round($it['total'] * $ratio, 2);
            unset($it);
            $warnings[] = sprintf('Total dos itens (R$ %s) difere do total da nota (R$ %s). A diferença (frete, impostos, descontos) foi distribuída no custo dos itens.',
                number_format($sum, 2, ',', '.'), number_format($vNF, 2, ',', '.'));
        }
        if ($vProdTotal && $items && abs($vProdTotal - $sum) > 0.01) {
            $warnings[] = 'A soma dos itens lidos não bate com o "valor total dos produtos" do DANFE. Algum item pode não ter sido lido — confira com a nota.';
        }
        $warnings[] = 'Leitura de PDF é aproximada: confira quantidades e valores antes de confirmar.';
        // Destinatário: primeiro CNPJ/CPF depois do quadro "DESTINATÁRIO / REMETENTE"
        $recipient = null;
        if (preg_match('/DESTINAT[ÁA]RIO.{0,600}?(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}|\d{3}\.\d{3}\.\d{3}-\d{2})/isu', $flat, $rm)) {
            $recipient = preg_replace('/\D/', '', $rm[1]);
        }
        $dups = self::danfeInstallments($flat);

        return [
            'kind' => 'danfe',
            'access_key' => $key,
            'number' => $number,
            'series' => $series,
            'supplier_name' => $name,
            'supplier_cnpj' => $cnpj,
            'issued_at' => $issued,
            'total' => $vNF ?: $sum,
            'recipient_doc' => $recipient,
            'items' => $items,
            'installments' => $dups,
            'payment_method' => $dups && PaymentMethods::exists('boleto') ? 'boleto' : null,
            'warnings' => $warnings,
        ];
    }

    private static function labelValue(string $text, string $label): ?float
    {
        if (preg_match('/' . $label . '\s*(?:R\$)?\s*((?:\d{1,3}(?:\.\d{3})*|\d+),\d{2})/iu', $text, $m)) {
            return Inventory::num($m[1]);
        }
        return null;
    }

    // ---------- NFC-e pelo QR Code ----------

    public static function keyFromQr(string $qr): ?string
    {
        $qr = trim($qr);
        if (preg_match('/[?&]p=(\d{44})/', $qr, $m)) return $m[1];
        if (preg_match('/chNFe=(\d{44})/i', $qr, $m)) return $m[1];
        if (preg_match('/^\d{44}$/', preg_replace('/\D/', '', $qr))) return preg_replace('/\D/', '', $qr);
        return null;
    }

    public static function fetchNfce(string $qr): array
    {
        $qr = trim($qr);
        if (!preg_match('#^https?://#i', $qr)) {
            throw new AppError('O conteúdo do QR Code não é um endereço da SEFAZ. Escaneie o QR Code impresso no cupom.');
        }
        $host = strtolower((string)parse_url($qr, PHP_URL_HOST));
        // Só consulta portais oficiais (.gov.br) para evitar uso indevido do servidor
        if (!preg_match('/(\.|^)gov\.br$/', $host)) {
            throw new AppError('O endereço do QR Code não é de um portal oficial (.gov.br)');
        }
        if (!function_exists('curl_init')) throw new AppError('A extensão cURL do PHP não está ativa na hospedagem');
        $ch = curl_init($qr);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: pt-BR,pt;q=0.9'],
        ]);
        $html = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($html === false || $code >= 400) {
            throw new AppError('Não consegui consultar a SEFAZ agora' . ($err ? " ($err)" : " (HTTP $code)") . '. Tente novamente mais tarde.');
        }
        $data = self::parseNfceHtml((string)$html);
        if (!$data['access_key']) $data['access_key'] = self::keyFromQr($qr);
        if ($data['access_key'] && !$data['supplier_cnpj']) $data['supplier_cnpj'] = substr($data['access_key'], 6, 14);
        return $data;
    }

    /** Lê a página de consulta da NFC-e (layout padrão usado pela maioria das SEFAZ). */
    public static function parseNfceHtml(string $html): array
    {
        if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);
        $cls = fn(string $c) => "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
        $txt = function (string $query, $ctx = null) use ($xp): ?string {
            $n = $xp->query($query, $ctx);
            return $n && $n->length ? trim(preg_replace('/\s+/u', ' ', $n->item(0)->textContent)) : null;
        };
        $strip = fn(?string $s, string $label) => $s === null ? null : trim(preg_replace('/^.*?' . $label . '\s*:?/iu', '', $s));

        $items = [];
        $rows = $xp->query("//table[@id='tabResult']//tr");
        foreach ($rows as $tr) {
            $desc = $txt(".//span[{$cls('txtTit')}] | .//span[{$cls('txtTit2')}]", $tr);
            if (!$desc) continue;
            $code = $txt(".//span[{$cls('RCod')}]", $tr);
            $code = $code ? trim(preg_replace('/[^\w.\-\/]/u', ' ', preg_replace('/C[óo]digo\s*:?/iu', '', $code))) : null;
            $qty = Inventory::num($strip($txt(".//span[{$cls('Rqtd')}]", $tr), 'Qtde\.?'));
            $un = $strip($txt(".//span[{$cls('RUN')}]", $tr), 'UN');
            $vun = Inventory::num($strip($txt(".//span[{$cls('RvlUnit')}]", $tr), 'Vl\.?\s*Unit\.?'));
            $tot = Inventory::num($txt(".//span[{$cls('valor')}]", $tr));
            if ($qty <= 0) continue;
            if ($tot <= 0) $tot = round($qty * $vun, 2);
            $items[] = [
                'code' => $code ?: null,
                'ean' => null,
                'description' => $desc,
                'unit' => strtoupper((string)$un) ?: 'UN',
                'qty' => $qty,
                'unit_price' => $vun ?: ($qty ? $tot / $qty : 0),
                'total' => round($tot, 2),
                'cost_total' => round($tot, 2),
            ];
        }
        if (!$items) {
            throw new AppError('A SEFAZ respondeu, mas não reconheci os itens da página. Alguns estados usam outro layout ou pedem captcha. Peça o XML ao vendedor ou lance os itens manualmente.');
        }

        $name = $txt("//div[@id='u20'] | //div[{$cls('txtTopo')}]");
        $all = preg_replace('/\s+/u', ' ', $doc->textContent);
        $cnpj = null;
        if (preg_match('/CNPJ\s*:?\s*([\d.\/\-]{14,18})/u', $all, $m)) $cnpj = preg_replace('/\D/', '', $m[1]);
        $key = null;
        $k = $txt("//span[{$cls('chave')}]");
        if ($k) $key = preg_replace('/\D/', '', $k);
        if (!$key && preg_match('/((?:\d{4}\s?){10}\d{4})/', $all, $m)) $key = preg_replace('/\D/', '', $m[1]);
        if ($key && strlen($key) !== 44) $key = null;
        $number = preg_match('/N[úu]mero\s*:?\s*(\d+)/u', $all, $m) ? $m[1] : null;
        $series = preg_match('/S[ée]rie\s*:?\s*(\d+)/u', $all, $m) ? $m[1] : null;
        $issued = preg_match('/Emiss[ãa]o\s*:?\s*(\d{2}\/\d{2}\/\d{4}(?:\s+\d{2}:\d{2}(?::\d{2})?)?)/u', $all, $m) ? self::parseDate($m[1]) : null;

        // Consumidor identificado no cupom (CNPJ/CPF na nota)
        $recipient = null;
        if (preg_match('/CONSUMIDOR\b.{0,80}?(?:CNPJ|CPF)\s*:?\s*([\d.\/\-]{11,18})/iu', $all, $m)) $recipient = preg_replace('/\D/', '', $m[1]);
        $sum = array_sum(array_column($items, 'total'));
        $total = null;
        $tn = $xp->query("//div[@id='totalNota']//div[@id='linhaTotal']");
        foreach ($tn as $line) {
            $label = $txt(".//label", $line);
            if ($label && preg_match('/a\s+pagar/iu', $label)) $total = Inventory::num($txt(".//span", $line));
        }
        $warnings = [];
        if ($total && $sum > 0 && abs($total - $sum) > 0.01) {
            $ratio = $total / $sum;
            foreach ($items as &$it) $it['cost_total'] = round($it['total'] * $ratio, 2);
            unset($it);
            $warnings[] = 'O cupom tem descontos ou acréscimos. Eles foram distribuídos no custo dos itens.';
        }

        return [
            'kind' => 'nfce',
            'access_key' => $key,
            'number' => $number,
            'series' => $series,
            'supplier_name' => $name,
            'supplier_cnpj' => $cnpj,
            'issued_at' => $issued,
            'total' => $total ?: $sum,
            'recipient_doc' => $recipient,
            'items' => $items,
            'warnings' => $warnings,
        ];
    }

    /**
     * Só aceita notas emitidas para o CNPJ da empresa (Configurações → Dados da empresa).
     * Devolve a mensagem de bloqueio ou null se estiver tudo certo. Lançamento manual não passa por aqui.
     */
    public static function recipientError(array $inv): ?string
    {
        if (($inv['kind'] ?? 'manual') === 'manual') return null;
        // Configurações → Notas fiscais: "Aceitar notas de qualquer CNPJ"
        if (Settings::get('invoice_cnpj_mode') === 'qualquer') return null;
        $company = preg_replace('/\D/', '', Settings::get('company_document'));
        $allowed = Settings::companyDocs();
        if (!$allowed) {
            return 'Para importar notas, cadastre o CNPJ da empresa em Configurações → Dados da empresa. Só são aceitas notas emitidas para esse CNPJ ou para os CNPJs aceitos em Configurações → Notas fiscais (lá também dá para aceitar qualquer CNPJ).';
        }
        $doc = preg_replace('/\D/', '', (string)($inv['recipient_doc'] ?? ''));
        $fmt = fn($d) => strlen($d) === 14 ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d)
            : (strlen($d) === 11 ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d) : $d);
        $list = implode(', ', array_map($fmt, array_keys($allowed)));
        $which = count($allowed) > 1 ? 'da empresa ou dos CNPJs aceitos (' . $list . ')' : 'da empresa (' . $list . ')';
        if ($doc === '') {
            return ($inv['kind'] ?? '') === 'nfce'
                ? 'Este cupom não tem o CNPJ do comprador. Só são aceitas notas emitidas para o CNPJ ' . $which . ': peça ao vendedor para informar o CNPJ no cupom.'
                : 'Não encontrei o CNPJ do destinatário nesta nota. Só são aceitas notas emitidas para o CNPJ ' . $which . '. Use o XML da nota.';
        }
        if (!isset($allowed[$doc])) {
            return 'Esta nota foi emitida para ' . $fmt($doc) . (!empty($inv['recipient_name']) ? ' (' . $inv['recipient_name'] . ')' : '')
                . ', que não é o CNPJ ' . $which . '. Se for uma filial ou outro CNPJ seu, cadastre-o em Configurações → Notas fiscais.';
        }
        return null;
    }

    // ---------- Sugestão de produtos e confirmação ----------

    /** Acrescenta a cada item o produto sugerido e o fator de conversão já usado antes. */
    public static function suggest(array $inv): array
    {
        $cnpj = $inv['supplier_cnpj'] ?? null;
        foreach ($inv['items'] as &$it) {
            $it['product_id'] = null;
            $it['factor'] = 1;
            $it['match'] = null;
            if ($cnpj && !empty($it['code'])) {
                $m = DB::one('SELECT m.product_id, m.factor FROM supplier_map m JOIN products p ON p.id = m.product_id
                    WHERE m.company_id = ? AND p.company_id = m.company_id AND m.supplier_cnpj = ? AND m.supplier_code = ? AND p.active = 1', [Tenant::need(), $cnpj, $it['code']]);
                if ($m) { $it['product_id'] = (int)$m['product_id']; $it['factor'] = (float)$m['factor']; $it['match'] = 'fornecedor'; continue; }
            }
            if (!empty($it['ean'])) {
                $p = DB::one('SELECT id FROM products WHERE company_id = ? AND barcode = ? AND active = 1', [Tenant::need(), $it['ean']]);
                if ($p) { $it['product_id'] = (int)$p['id']; $it['match'] = 'codigo_barras'; continue; }
            }
            if (!empty($it['description'])) {
                $p = DB::one('SELECT id FROM products WHERE company_id = ? AND LOWER(name) = LOWER(?) AND active = 1', [Tenant::need(), trim($it['description'])]);
                if ($p) { $it['product_id'] = (int)$p['id']; $it['match'] = 'nome'; }
            }
        }
        unset($it);
        $inv['recipient_error'] = self::recipientError($inv);
        $inv['duplicate'] = false;
        if (!empty($inv['access_key'])) {
            $d = DB::one('SELECT id, created_at FROM invoices WHERE company_id = ? AND access_key = ?', [Tenant::need(), $inv['access_key']]);
            if ($d) {
                $inv['duplicate'] = (int)$d['id'];
                array_unshift($inv['warnings'], 'Esta nota já foi importada em ' . date('d/m/Y H:i', strtotime($d['created_at'])) . '.');
            }
        }
        return $inv;
    }

    /** Grava a nota e dá entrada dos itens marcados. */
    public static function confirm(array $in, int $userId): int
    {
        $items = array_values(array_filter($in['items'] ?? [], fn($i) => !empty($i['import'])));
        if (!$items) throw new AppError('Marque pelo menos um item para dar entrada');
        $key = preg_replace('/\D/', '', (string)($in['access_key'] ?? '')) ?: null;
        if ($key && strlen($key) !== 44) throw new AppError('A chave de acesso deve ter 44 dígitos');
        $kind = in_array($in['kind'] ?? '', ['nfe', 'nfce', 'danfe', 'manual'], true) ? $in['kind'] : 'manual';
        $cnpj = preg_replace('/\D/', '', (string)($in['supplier_cnpj'] ?? '')) ?: null;
        if ($err = self::recipientError(['kind' => $kind, 'recipient_doc' => $in['recipient_doc'] ?? null, 'recipient_name' => $in['recipient_name'] ?? null])) {
            throw new AppError($err);
        }

        return DB::tx(function () use ($in, $items, $key, $kind, $cnpj, $userId) {
            if ($key && DB::val('SELECT id FROM invoices WHERE company_id = ? AND access_key = ?', [Tenant::need(), $key])) {
                throw new AppError('Esta nota já foi importada antes');
            }
            $issued = self::parseDate($in['issued_at'] ?? null);
            $supplierId = !empty($in['supplier_id']) ? (int)$in['supplier_id'] : Customers::supplierFor($cnpj, $in['supplier_name'] ?? null);
            $invoiceId = DB::insert('invoices', [
                'access_key' => $key,
                'kind' => $kind,
                'number' => Inventory::nullable($in['number'] ?? null),
                'series' => Inventory::nullable($in['series'] ?? null),
                'supplier_name' => Inventory::nullable($in['supplier_name'] ?? null),
                'supplier_cnpj' => $cnpj,
                'supplier_id' => $supplierId,
                'issued_at' => $issued,
                'total' => 0,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
            $sum = 0.0;
            $ref = 'NF ' . ($in['number'] ?? 's/n') . (!empty($in['supplier_name']) ? ' - ' . $in['supplier_name'] : '');
            foreach ($items as $n => $it) {
                $line = $n + 1;
                $qty = Inventory::num($it['qty'] ?? 0);
                $factor = Inventory::num($it['factor'] ?? 1) ?: 1;
                $cost = Inventory::num($it['cost_total'] ?? ($it['total'] ?? 0));
                if ($qty <= 0) throw new AppError("Item $line: quantidade inválida");
                if ($factor <= 0) throw new AppError("Item $line: fator de conversão inválido");
                if ($cost < 0) throw new AppError("Item $line: valor inválido");
                $pid = (int)($it['product_id'] ?? 0);
                if (!$pid) {
                    $np = $it['new_product'] ?? null;
                    if (!$np || trim((string)($np['name'] ?? '')) === '') {
                        throw new AppError("Item $line: escolha um produto ou cadastre um novo");
                    }
                    $np['barcode'] = $np['barcode'] ?? ($it['ean'] ?? null);
                    $pid = Inventory::saveProduct($np);
                }
                Fiscal::fillFromInvoice($pid, $it['ncm'] ?? null, $it['cest'] ?? null, isset($it['orig']) ? (string)$it['orig'] : null, Settings::get('invoice_fiscal'));
                $stockQty = $qty * $factor;
                $unitCost = $stockQty > 0 ? $cost / $stockQty : 0;
                DB::insert('invoice_items', [
                    'invoice_id' => $invoiceId,
                    'product_id' => $pid,
                    'supplier_code' => Inventory::nullable($it['code'] ?? null),
                    'description' => Inventory::nullable($it['description'] ?? null),
                    'invoice_unit' => Inventory::nullable($it['unit'] ?? null),
                    'invoice_qty' => $qty,
                    'factor' => $factor,
                    'stock_qty' => round($stockQty, 4),
                    'unit_cost' => round($unitCost, 6),
                    'total' => round($cost, 2),
                ]);
                Inventory::move($pid, 'nota', $stockQty, $unitCost, [
                    'user_id' => $userId, 'invoice_id' => $invoiceId, 'note' => $ref,
                ]);
                $sum += $cost;
                // Preço de venda novo (sugerido pela margem e confirmado na conferência da nota)
                if (isset($it['sale_price']) && $it['sale_price'] !== '' && $it['sale_price'] !== null) {
                    $price = round(Inventory::num($it['sale_price']), 2);
                    if ($price < 0) throw new AppError("Item $line: preço de venda inválido");
                    if ($price > 0) DB::update('products', $pid, ['sale_price' => $price, 'updated_at' => now()]);
                }
                if ($cnpj && !empty($it['code'])) {
                    $code = mb_substr((string)$it['code'], 0, 60);
                    $ex = DB::one('SELECT id FROM supplier_map WHERE company_id = ? AND supplier_cnpj = ? AND supplier_code = ?', [Tenant::need(), $cnpj, $code]);
                    if ($ex) DB::update('supplier_map', (int)$ex['id'], ['product_id' => $pid, 'factor' => $factor]);
                    else DB::insert('supplier_map', ['supplier_cnpj' => $cnpj, 'supplier_code' => $code, 'product_id' => $pid, 'factor' => $factor]);
                }
            }
            DB::update('invoices', $invoiceId, ['total' => round($sum, 2)]);
            if (!empty($in['payables'])) {
                Finance::fromInvoice($invoiceId, $supplierId, (array)($in['installments'] ?? []),
                    (string)($in['payment_method'] ?? ''), $userId, (string)($in['number'] ?? ''), (string)($in['supplier_name'] ?? ''));
            }
            return $invoiceId;
        });
    }

    /** Desfaz a entrada de uma nota (ex.: importada por engano). */
    public static function revert(int $invoiceId, int $userId): void
    {
        DB::tx(function () use ($invoiceId, $userId) {
            $inv = DB::one('SELECT * FROM invoices WHERE id = ? AND company_id = ?', [$invoiceId, Tenant::need()]);
            if (!$inv) throw new AppError('Nota não encontrada', 404);
            if (DB::val("SELECT COUNT(*) FROM financial_entries WHERE invoice_id = ? AND company_id = ? AND status = 'pago'", [$invoiceId, Tenant::need()])) {
                throw new AppError('Esta nota tem parcelas já pagas em Contas a pagar. Reabra os pagamentos antes de desfazer a entrada.');
            }
            DB::run('DELETE FROM financial_entries WHERE invoice_id = ? AND company_id = ?', [$invoiceId, Tenant::need()]);
            $movs = DB::all("SELECT * FROM movements WHERE invoice_id = ? AND company_id = ? AND type = 'nota'", [$invoiceId, Tenant::need()]);
            foreach ($movs as $m) {
                Inventory::move((int)$m['product_id'], 'estorno_nota', -(float)$m['quantity'], null, [
                    'user_id' => $userId, 'reverse_cost' => (float)$m['unit_cost'], 'allow_inactive' => true,
                    'note' => 'Estorno da NF ' . ($inv['number'] ?? 's/n'),
                ]);
            }
            DB::run('UPDATE movements SET invoice_id = NULL WHERE invoice_id = ? AND company_id = ?', [$invoiceId, Tenant::need()]);
            DB::run('DELETE FROM invoices WHERE id = ? AND company_id = ?', [$invoiceId, Tenant::need()]);
        });
    }

    // ---------- Utilidades ----------

    public static function validKey(string $k): bool
    {
        if (!preg_match('/^\d{44}$/', $k)) return false;
        $sum = 0; $w = 2;
        for ($i = 42; $i >= 0; $i--) {
            $sum += (int)$k[$i] * $w;
            $w = $w === 9 ? 2 : $w + 1;
        }
        $dv = 11 - ($sum % 11);
        if ($dv >= 10) $dv = 0;
        return $dv === (int)$k[43];
    }

    public static function parseDate(?string $s): ?string
    {
        $s = trim((string)$s);
        if ($s === '') return null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?/', $s, $m)) {
            return sprintf('%s-%s-%s %s:%s:%s', $m[3], $m[2], $m[1], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
