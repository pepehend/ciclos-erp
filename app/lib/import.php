<?php
/**
 * Importação de produtos por planilha (Excel .xlsx ou CSV), sem bibliotecas externas.
 * - XlsxReader: lê a primeira aba de um .xlsx (o .xlsx é um zip com XML dentro).
 * - ProductImport: confere as linhas (prévia) e cadastra em lotes.
 *
 * Ordem das colunas: A SKU | B código do fabricante | C nome | D marca | E preço de compra |
 * F última movimentação | G localização | H estoque inicial | I observações. A 1ª linha é o cabeçalho.
 */

class XlsxReader
{
    /** Lê as entradas do zip (só as que interessam), com ou sem compressão. */
    private static function unzip(string $bin, callable $want): array
    {
        $out = [];
        $eocd = strrpos($bin, "PK\x05\x06");
        if ($eocd === false) throw new AppError('Arquivo .xlsx inválido');
        $e = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset', substr($bin, $eocd + 4, 16));
        $p = $e['cd_offset'];
        for ($i = 0; $i < $e['entries']; $i++) {
            if (substr($bin, $p, 4) !== "PK\x01\x02") break;
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Vlocal', substr($bin, $p + 4, 42));
            $name = substr($bin, $p + 46, $h['nlen']);
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
            if (!$want($name)) continue;
            $l = unpack('vnlen/velen', substr($bin, $h['local'] + 26, 4));
            $data = substr($bin, $h['local'] + 30 + $l['nlen'] + $l['elen'], $h['csize']);
            if ($h['method'] === 8) $data = @gzinflate($data);
            elseif ($h['method'] !== 0) throw new AppError('Compressão do .xlsx não suportada');
            if ($data === false) throw new AppError('Não foi possível ler o .xlsx');
            $out[$name] = $data;
        }
        return $out;
    }

    /** Número da coluna a partir da referência (A1 → 0, AB7 → 27). */
    private static function col(string $ref): int
    {
        preg_match('/^[A-Z]+/', $ref, $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }

    /** Linhas da primeira aba: lista de arrays (índice = coluna). Datas do Excel vêm como número. */
    public static function rows(string $bin): array
    {
        $files = self::unzip($bin, fn($n) => in_array($n, ['xl/sharedStrings.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'], true) || str_starts_with($n, 'xl/worksheets/sheet'));
        // Primeira aba do livro (pela ordem do workbook, não pelo nome do arquivo)
        $sheet = null;
        if (isset($files['xl/workbook.xml'], $files['xl/_rels/workbook.xml.rels'])) {
            $wb = @simplexml_load_string($files['xl/workbook.xml']);
            $rels = @simplexml_load_string($files['xl/_rels/workbook.xml.rels']);
            if ($wb && $rels) {
                $first = $wb->sheets->sheet[0] ?? null;
                $rid = $first ? (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
                foreach ($rels->Relationship as $r) if ((string)$r['Id'] === $rid) $sheet = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', (string)$r['Target']), '/');
            }
        }
        if (!$sheet || !isset($files[$sheet])) {
            $names = array_values(array_filter(array_keys($files), fn($n) => str_starts_with($n, 'xl/worksheets/sheet')));
            sort($names, SORT_NATURAL);
            $sheet = $names[0] ?? null;
        }
        if (!$sheet) throw new AppError('A planilha não tem nenhuma aba');

        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            $sx = @simplexml_load_string($files['xl/sharedStrings.xml']);
            if ($sx) foreach ($sx->si as $si) {
                if (isset($si->t)) $shared[] = (string)$si->t;
                else { $t = ''; foreach ($si->r as $r) $t .= (string)$r->t; $shared[] = $t; }
            }
        }
        $xml = @simplexml_load_string($files[$sheet]);
        if (!$xml) throw new AppError('Não foi possível ler a aba da planilha');
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $vals = [];
            foreach ($row->c as $c) {
                $t = (string)$c['t'];
                $v = isset($c->v) ? (string)$c->v : null;
                if ($t === 's') $v = $shared[(int)$v] ?? '';
                elseif ($t === 'inlineStr') $v = isset($c->is->t) ? (string)$c->is->t : implode('', array_map('strval', $c->is->xpath('.//t') ?: []));
                elseif ($t === 'b') $v = $v === '1' ? '1' : '0';
                $vals[self::col((string)$c['r'])] = $v;
            }
            $rows[(int)$row['r'] ?: count($rows) + 1] = $vals;
        }
        return $rows;
    }
}

class ProductImport
{
    const BATCH_MAX = 500;
    const COLS = ['sku', 'mfr_code', 'name', 'brand', 'price', 'last_move', 'location', 'stock', 'notes'];

    /** Texto da célula, sem os "_x000D_" que o Excel deixa nas quebras de linha. */
    private static function text($v): string
    {
        $v = str_replace(['_x000D_', "\r"], '', (string)($v ?? ''));
        return trim($v);
    }

    /** Número da célula: aceita 25, 25.5 e "25,50" / "1.234,56". */
    private static function number($v): ?float
    {
        if ($v === null || trim((string)$v) === '') return null;
        if (is_numeric($v)) return (float)$v;
        $s = preg_replace('/[^\d,.\-]/', '', (string)$v);
        if ($s === '' || $s === '-') return null;
        return Inventory::num($s);
    }

    /** Data da célula: "dd/mm/aaaa", "aaaa-mm-dd" ou número de data do Excel. Devolve Y-m-d ou null. */
    public static function date($v): ?string
    {
        $d = self::parseDate($v);
        return $d && $d >= '1900-01-02' ? $d : null; // 30/12/1899 é a "data zero" do Excel: célula sem data
    }

    private static function parseDate($v): ?string
    {
        $s = trim((string)($v ?? ''));
        if ($s === '') return null;
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', $s, $m)) {
            $y = (int)$m[3]; if ($y < 100) $y += 2000;
            return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        if (is_numeric($s) && (float)$s > 1 && (float)$s < 80000) return gmdate('Y-m-d', (int)round(((float)$s - 25569) * 86400));
        return null;
    }

    /** SKU como texto (números do Excel sem ".0"). */
    private static function code($v): string
    {
        $s = self::text($v);
        if (preg_match('/^\d+\.0+$/', $s)) $s = preg_replace('/\.0+$/', '', $s);
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $s)) $s = sprintf('%.0f', (float)$s); // 1.6156038E9 → 1615603800
        return $s;
    }

    /** Lê o arquivo enviado (.xlsx ou .csv) e devolve as linhas brutas, sem o cabeçalho. */
    public static function read(string $bin, string $filename = ''): array
    {
        if (str_starts_with($bin, "PK")) {
            $rows = XlsxReader::rows($bin);
        } else {
            // CSV: separador ; ou , (o que aparecer mais no cabeçalho), em UTF-8 ou Windows-1252
            if (!mb_check_encoding($bin, 'UTF-8')) $bin = mb_convert_encoding($bin, 'UTF-8', 'Windows-1252');
            $bin = preg_replace('/^\xEF\xBB\xBF/', '', $bin);
            $first = strtok($bin, "\n");
            $sep = substr_count((string)$first, ';') >= substr_count((string)$first, ',') ? ';' : ',';
            $h = fopen('php://temp', 'r+'); fwrite($h, $bin); rewind($h);
            $rows = []; $n = 0;
            while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) { $n++; if ($r !== [null]) $rows[$n] = $r; }
            fclose($h);
            if (!$rows) throw new AppError('Arquivo vazio. Envie a planilha em .xlsx ou .csv');
        }
        ksort($rows);
        unset($rows[array_key_first($rows)]); // cabeçalho (mantém o número da linha da planilha)
        return $rows;
    }

    /**
     * Prévia: confere cada linha e marca SKU já cadastrado ou repetido na planilha.
     * Devolve ['rows' => [...válidas para importar], 'skipped' => [...], 'errors' => [...], 'total' => n].
     */
    public static function preview(array $raw): array
    {
        $existing = array_flip(array_map('mb_strtolower', array_column(DB::all("SELECT sku FROM products WHERE sku IS NOT NULL AND sku <> '' AND deleted_at IS NULL"), 'sku')));
        $seen = []; $ok = []; $skipped = []; $errors = []; $total = 0;
        foreach ($raw as $line => $r) {
            $cells = [];
            foreach (self::COLS as $i => $k) $cells[$k] = $r[$i] ?? null;
            if (!array_filter($cells, fn($v) => self::text($v) !== '')) continue; // linha em branco
            $total++;
            $row = [
                'line' => $line + 0,
                'sku' => mb_substr(self::code($cells['sku']), 0, 60),
                'mfr_code' => mb_substr(self::text($cells['mfr_code']), 0, 80),
                'name' => mb_substr(preg_replace('/\s+/', ' ', self::text($cells['name'])), 0, 200),
                'brand' => mb_substr(self::text($cells['brand']), 0, 80),
                'price' => self::number($cells['price']),
                'last_move' => self::date($cells['last_move']),
                'location' => mb_substr(self::text($cells['location']), 0, 80),
                'stock' => self::number($cells['stock']),
                'notes' => mb_substr(self::text($cells['notes']), 0, 5000),
            ];
            $err = [];
            // Sem nome: usa o código do fabricante (ou o SKU) como nome, para não perder o produto
            if ($row['name'] === '' && ($row['mfr_code'] !== '' || $row['sku'] !== '')) {
                $row['name'] = $row['mfr_code'] !== '' ? $row['mfr_code'] : 'SKU ' . $row['sku'];
                $row['noname'] = true;
            }
            if ($row['name'] === '') $err[] = 'sem nome nem código';
            if ($row['price'] !== null && $row['price'] < 0) $err[] = 'preço de venda negativo';
            if ($row['stock'] !== null && $row['stock'] < 0) $err[] = 'estoque negativo';
            if (self::text($cells['last_move']) !== '' && !$row['last_move'] && !self::parseDate($cells['last_move'])) $err[] = 'data da última movimentação inválida';
            if ($err) { $errors[] = ['line' => $row['line'], 'sku' => $row['sku'], 'name' => $row['name'], 'error' => implode(', ', $err)]; continue; }
            $key = mb_strtolower($row['sku']);
            if ($row['sku'] !== '' && isset($existing[$key])) { $skipped[] = ['line' => $row['line'], 'sku' => $row['sku'], 'name' => $row['name'], 'reason' => 'SKU já cadastrado']; continue; }
            if ($row['sku'] !== '' && isset($seen[$key])) { $skipped[] = ['line' => $row['line'], 'sku' => $row['sku'], 'name' => $row['name'], 'reason' => 'SKU repetido na planilha (linha ' . $seen[$key] . ')']; continue; }
            if ($row['sku'] !== '') $seen[$key] = $row['line'];
            $ok[] = $row;
        }
        return ['rows' => $ok, 'skipped' => $skipped, 'errors' => $errors, 'total' => $total,
            'noname' => count(array_filter($ok, fn($r) => !empty($r['noname'])))];
    }

    /**
     * Cadastra um lote de linhas já conferidas. Estoque > 0: "Ajuste de inventário" (contagem) na data da
     * última movimentação (ou hoje). Estoque 0: registro "Entrada" de quantidade 0 na data da importação.
     */
    public static function import(array $rows, ?int $userId = null): array
    {
        if (count($rows) > self::BATCH_MAX) throw new AppError('Lote grande demais (máx. ' . self::BATCH_MAX . ' linhas)');
        $created = 0; $skipped = []; $errors = [];
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $sku = mb_substr(trim((string)($r['sku'] ?? '')), 0, 60);
            try {
                DB::tx(function () use ($r, $sku, $userId, $today, &$created, &$skipped) {
                    if ($sku !== '' && DB::val('SELECT id FROM products WHERE LOWER(sku) = ? AND deleted_at IS NULL', [mb_strtolower($sku)])) {
                        $skipped[] = ['line' => $r['line'] ?? null, 'sku' => $sku, 'name' => $r['name'] ?? '', 'reason' => 'SKU já cadastrado'];
                        return;
                    }
                    $price = isset($r['price']) && $r['price'] !== null && $r['price'] !== '' ? round(max(0, (float)$r['price']), 2) : 0.0;
                    $stock = isset($r['stock']) && $r['stock'] !== null && $r['stock'] !== '' ? max(0, (float)$r['stock']) : 0.0;
                    $id = Inventory::saveProduct([
                        'name' => (string)($r['name'] ?? ''), 'type' => 'produto', 'unit' => 'un', 'sku' => $sku,
                        'mfr_code' => $r['mfr_code'] ?? '', 'brand' => $r['brand'] ?? '', 'location' => $r['location'] ?? '',
                        'description' => $r['notes'] ?? '', 'sale_price' => $price,
                    ], null, $userId);
                    $date = self::date($r['last_move'] ?? null);
                    if ($date && $date > $today) $date = $today; // data futura: usa hoje
                    if ($stock > 0) {
                        Inventory::move($id, 'ajuste', $stock, 0, [
                            'note' => 'Contagem (importação de planilha)', 'user_id' => $userId,
                            'at' => ($date ?: $today) . ($date && $date !== $today ? ' 12:00:00' : date(' H:i:s')),
                        ]);
                    } else {
                        DB::insert('movements', [
                            'product_id' => $id, 'type' => 'entrada', 'quantity' => 0, 'unit_cost' => 0, 'balance_after' => 0,
                            'note' => 'Cadastro por importação de planilha', 'user_id' => $userId, 'created_at' => now(),
                        ]);
                    }
                    $created++;
                });
            } catch (AppError $e) {
                $errors[] = ['line' => $r['line'] ?? null, 'sku' => $sku, 'name' => $r['name'] ?? '', 'error' => $e->getMessage()];
            }
        }
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ---------- Desfazer importação ----------

    const NOTES = ['Contagem (importação de planilha)', 'Cadastro por importação de planilha'];

    /** Produtos que vieram de planilha, com o dia da importação e se ainda dá para apagar. */
    private static function imported(?string $day = null): array
    {
        $ph = implode(',', array_fill(0, count(self::NOTES), '?'));
        $p = self::NOTES;
        $w = "p.deleted_at IS NULL AND EXISTS (SELECT 1 FROM movements m WHERE m.product_id = p.id AND m.note IN ($ph))";
        if ($day) { $w .= ' AND p.created_at BETWEEN ? AND ?'; array_push($p, "$day 00:00:00", "$day 23:59:59"); }
        // Usado depois da importação = qualquer movimento que não seja da importação, ou item de venda/orçamento/nota/ordem
        $used = "(SELECT COUNT(*) FROM movements m2 WHERE m2.product_id = p.id AND (m2.note IS NULL OR m2.note NOT IN ($ph)))
            + (SELECT COUNT(*) FROM sale_items x WHERE x.product_id = p.id) + (SELECT COUNT(*) FROM quote_items x WHERE x.product_id = p.id)
            + (SELECT COUNT(*) FROM invoice_items x WHERE x.product_id = p.id) + (SELECT COUNT(*) FROM purchase_order_items x WHERE x.product_id = p.id)";
        return DB::all("SELECT p.id, p.sku, p.name, p.created_at, $used AS used FROM products p WHERE $w ORDER BY p.id", array_merge(self::NOTES, $p));
    }

    /** Importações feitas, por dia: quantos produtos e quantos já foram usados (não serão apagados). */
    public static function history(): array
    {
        $out = [];
        foreach (self::imported() as $r) {
            $d = substr($r['created_at'], 0, 10);
            $out[$d] ??= ['day' => $d, 'total' => 0, 'used' => 0];
            $out[$d]['total']++;
            if ((int)$r['used'] > 0) $out[$d]['used']++;
        }
        krsort($out);
        return array_values($out);
    }

    /**
     * Desfaz a importação de um dia: apaga de vez os produtos importados nesse dia que não foram usados depois
     * (com o histórico da importação, referências e códigos de fornecedor). Os usados ficam e voltam na lista.
     */
    public static function undo(string $day): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) throw new AppError('Data inválida');
        $rows = self::imported($day);
        if (!$rows) throw new AppError('Nenhum produto importado nesse dia');
        $del = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => (int)$r['used'] === 0));
        $kept = array_values(array_map(fn($r) => ['sku' => $r['sku'], 'name' => $r['name']], array_filter($rows, fn($r) => (int)$r['used'] > 0)));
        DB::tx(function () use ($del) {
            foreach (array_chunk($del, 500) as $ids) {
                $in = implode(',', $ids); // inteiros, vindos do banco
                foreach (['movements', 'product_refs', 'supplier_map'] as $t) DB::run("DELETE FROM $t WHERE product_id IN ($in)");
                DB::run("DELETE FROM recipe_items WHERE product_id IN ($in) OR ingredient_id IN ($in)");
                DB::run("DELETE FROM products WHERE id IN ($in)");
            }
        });
        return ['deleted' => count($del), 'kept' => $kept];
    }
}
