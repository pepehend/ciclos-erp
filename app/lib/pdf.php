<?php
/**
 * Geração de PDF e ZIP sem bibliotecas externas (a hospedagem não tem Composer).
 * - SimplePdf: A4, Helvetica/Helvetica-Bold (WinAnsi, com acentos), texto, linhas, retângulos e imagem JPEG.
 * - ZipWriter: .zip com compressão deflate.
 * - QuotePdf: o orçamento no mesmo conteúdo da página pública (orcamento.php).
 */

class SimplePdf
{
    const W = 595.28, H = 841.89;
    /** Larguras (1/1000 em) de Helvetica e Helvetica-Bold para ASCII 32..126. */
    const WIDTHS = [
        false => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584],
        true => [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584],
    ];

    private array $pages = [];
    private int $cur = -1;
    private array $images = [];

    public function addPage(): void { $this->pages[] = ''; $this->cur = count($this->pages) - 1; }
    public function pageCount(): int { return count($this->pages); }
    public function setPage(int $i): void { $this->cur = $i; }

    private function op(string $s): void { $this->pages[$this->cur] .= $s . "\n"; }
    private static function n(float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0'; }
    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return implode(' ', array_map(fn($i) => self::n(hexdec(substr($hex, $i, 2)) / 255), [0, 2, 4]));
    }

    /** Converte para WinAnsi (cp1252); o que não existe lá vira "?". */
    private static function enc(string $s): string
    {
        $s = str_replace(["\u{2212}", "\u{00A0}"], ['-', ' '], $s);
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    public function width(string $s, float $size, bool $bold = false): float
    {
        $w = 0;
        $t = self::enc($s);
        $base = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($base === false || strlen($base) !== strlen($t)) $base = $t;
        for ($i = 0, $n = strlen($base); $i < $n; $i++) {
            $c = ord($base[$i]);
            $w += ($c >= 32 && $c <= 126) ? self::WIDTHS[$bold][$c - 32] : 556;
        }
        return $w * $size / 1000;
    }

    /** Texto na posição (x, y) com y medido do topo da página até a linha de base. */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $color = '#212529', string $align = 'L'): void
    {
        if ($s === '') return;
        if ($align === 'R') $x -= $this->width($s, $size, $bold);
        elseif ($align === 'C') $x -= $this->width($s, $size, $bold) / 2;
        $t = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::enc($s));
        $this->op('BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::n($size) . ' Tf ' . self::rgb($color) . ' rg ' . self::n($x) . ' ' . self::n(self::H - $y) . " Td ($t) Tj ET");
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#dee2e6', float $w = 0.7): void
    {
        $this->op(self::rgb($color) . ' RG ' . self::n($w) . ' w ' . self::n($x1) . ' ' . self::n(self::H - $y1) . ' m ' . self::n($x2) . ' ' . self::n(self::H - $y2) . ' l S');
    }

    public function rect(float $x, float $y, float $w, float $h, string $fill): void
    {
        $this->op(self::rgb($fill) . ' rg ' . self::n($x) . ' ' . self::n(self::H - $y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . ' re f');
    }

    /** Imagem JPEG (dados binários) desenhada em (x, y) com largura/altura em pontos. */
    public function jpeg(string $data, int $pw, int $ph, float $x, float $y, float $w, float $h): void
    {
        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = [$data, $pw, $ph];
        $this->op('q ' . self::n($w) . ' 0 0 ' . self::n($h) . ' ' . self::n($x) . ' ' . self::n(self::H - $y - $h) . " cm /$name Do Q");
    }

    /** Quebra o texto em linhas que caibam na largura. */
    public function wrap(string $s, float $max, float $size, bool $bold = false): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $s) as $para) {
            $line = '';
            foreach (preg_split('/ +/', trim($para)) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($this->width($try, $size, $bold) <= $max || $line === '') {
                    // Palavra sozinha maior que a linha: corta
                    while ($line === '' && $this->width($try, $size, $bold) > $max && mb_strlen($try) > 1) {
                        $k = mb_strlen($try);
                        while ($k > 1 && $this->width(mb_substr($try, 0, $k), $size, $bold) > $max) $k--;
                        $out[] = mb_substr($try, 0, $k); $try = mb_substr($try, $k);
                    }
                    $line = $try;
                } else { $out[] = $line; $line = $word; }
            }
            $out[] = $line;
        }
        return $out;
    }

    public function output(): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5; $xobj = '';
        foreach ($this->images as $name => [$data, $pw, $ph]) {
            $objs[$next] = "<< /Type /XObject /Subtype /Image /Width $pw /Height $ph /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n$data\nendstream";
            $xobj .= "/$name $next 0 R "; $next++;
        }
        $res = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xobj ? " /XObject << $xobj>>" : '') . ' >>';
        $kids = [];
        foreach ($this->pages as $content) {
            $z = gzcompress($content);
            $objs[$next] = '<< /Length ' . strlen($z) . " /Filter /FlateDecode >>\nstream\n$z\nendstream";
            $objs[$next + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . "] /Resources $res /Contents $next 0 R >>";
            $kids[] = ($next + 1) . ' 0 R'; $next += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($objs as $i => $o) { $off[$i] = strlen($pdf); $pdf .= "$i 0 obj\n$o\nendobj\n"; }
        $size = max(array_keys($objs)) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) $pdf .= isset($off[$i]) ? sprintf("%010d 00000 n \n", $off[$i]) : "0000000000 65535 f \n";
        return $pdf . "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}

class ZipWriter
{
    private string $data = '';
    private string $dir = '';
    private int $count = 0;

    public function add(string $name, string $content): void
    {
        $t = getdate();
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $z = gzdeflate($content, 6);
        $crc = crc32($content);
        $head = pack('vvvvvVVVvv', 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($z), strlen($content), strlen($name), 0);
        $offset = strlen($this->data);
        $this->data .= "PK\x03\x04" . $head . $name . $z;
        $this->dir .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($z), strlen($content), strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
        $this->count++;
    }

    public function output(): string
    {
        return $this->data . $this->dir . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $this->count, $this->count, strlen($this->dir), strlen($this->data), 0);
    }
}

class QuotePdf
{
    const ACCENT = '#b02a37', INK = '#212529', MUTED = '#6c757d', LINE = '#dee2e6', SOFT = '#f6f7f8';
    const L = 40, R = 555.28, BOTTOM = 790;

    private SimplePdf $p;
    private float $y = 0;

    private static function money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
    private static function qty($v): string { return rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ','); }
    private static function date($v): string { return $v ? date('d/m/Y', strtotime($v)) : ''; }
    private static function doc($d): string
    {
        $d = preg_replace('/\D/', '', (string)$d);
        if (strlen($d) === 14) return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d);
        if (strlen($d) === 11) return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d);
        return $d;
    }

    /** Nome do arquivo: orcamento-12-cliente.pdf */
    public static function fileName(array $q): string
    {
        $client = $q['customer_id'] ? ($q['customer_trade_name'] ?: $q['customer_name']) : $q['lead_name'];
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)@iconv('UTF-8', 'ASCII//TRANSLIT', (string)$client)), '-'));
        return 'orcamento-' . (int)$q['number'] . ($slug ? '-' . substr($slug, 0, 40) : '') . '.pdf';
    }

    private function need(float $h): void
    {
        if ($this->y + $h > self::BOTTOM) { $this->p->addPage(); $this->y = 50; }
    }

    private function title(string $t): void
    {
        $this->need(40);
        $this->y += 22;
        $this->p->text(self::L, $this->y, mb_strtoupper($t), 8.5, true, self::MUTED);
        $this->y += 8;
    }

    /** Logo da empresa convertido para JPEG (precisa do GD); devolve [dados, largura, altura] ou null. */
    private static function logo(string $dataUrl): ?array
    {
        if (!$dataUrl || !function_exists('imagecreatefromstring') || !preg_match('#^data:image/[a-z]+;base64,(.+)$#', $dataUrl, $m)) return null;
        $img = @imagecreatefromstring((string)base64_decode($m[1]));
        if (!$img) return null;
        $w = imagesx($img); $h = imagesy($img);
        $bg = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255)); // transparência vira branco
        imagecopy($bg, $img, 0, 0, 0, 0, $w, $h);
        ob_start(); imagejpeg($bg, null, 90); $jpg = ob_get_clean();
        imagedestroy($img); imagedestroy($bg);
        return [$jpg, $w, $h];
    }

    public static function render(array $q, array $co): string
    {
        $self = new self();
        return $self->build($q, $co);
    }

    private function build(array $q, array $co): string
    {
        $p = $this->p = new SimplePdf();
        $p->addPage();
        $L = self::L; $R = self::R;
        $company = $co['company_name'] ?: 'Orçamento';

        // ---- Cabeçalho: empresa à esquerda, dados do orçamento à direita
        $x = $L; $top = 40;
        if ($logo = self::logo((string)($co['company_logo'] ?? ''))) {
            [$jpg, $lw, $lh] = $logo;
            $s = min(110 / $lw, 55 / $lh);
            $p->jpeg($jpg, $lw, $lh, $L, $top, $lw * $s, $lh * $s);
            $x = $L + $lw * $s + 12;
        }
        $yl = $top + 14;
        $p->text($x, $yl, $company, 14, true);
        $info = array_filter([
            implode(' · ', array_filter([
                trim((string)($co['company_legal_name'] ?? '')) !== '' && mb_strtolower(trim($co['company_legal_name'])) !== mb_strtolower(trim((string)$co['company_name'])) ? trim($co['company_legal_name']) : '',
                $co['company_document'] ? 'CNPJ ' . self::doc($co['company_document']) : '',
            ])),
            (string)$co['company_address'],
            implode(' · ', array_filter([$co['company_phone'], $co['company_email'], $co['company_site'] ?? ''])),
        ]);
        foreach ($info as $line) foreach ($p->wrap($line, 300 - ($x - $L), 8.5) as $l) { $yl += 11.5; $p->text($x, $yl, $l, 8.5, false, self::MUTED); }

        $yr = $top + 14;
        $p->text($R, $yr, 'Orçamento #' . (int)$q['number'], 14, true, self::ACCENT, 'R');
        $meta = array_filter([
            'Emitido em ' . self::date($q['request_date']),
            $q['expires_at'] ? 'Válido até ' . self::date($q['expires_at']) : '',
            $q['external_code'] ? 'Ref.: ' . $q['external_code'] : '',
        ]);
        foreach ($meta as $m) { $yr += 12; $p->text($R, $yr, $m, 9, false, self::INK, 'R'); }
        if ($q['user_name']) {
            $yr += 16; $p->text($R, $yr, 'RESPONSÁVEL', 7.5, true, self::MUTED, 'R');
            $yr += 11; $p->text($R, $yr, $q['user_name'], 9, true, self::INK, 'R');
            foreach (array_filter([$q['user_phone'], $q['user_email']]) as $c) { $yr += 11; $p->text($R, $yr, $c, 8.5, false, self::MUTED, 'R'); }
        }
        $label = $q['status'] === 'aprovado' || $q['status'] === 'vendido' ? 'APROVADO' : ($q['status'] === 'recusado' ? 'RECUSADO' : (Quotes::isExpired($q) ? 'VENCIDO' : ''));
        if ($label) { $yr += 14; $p->text($R, $yr, $label, 8.5, true, $label === 'APROVADO' ? '#15803d' : '#dc2626', 'R'); }

        $this->y = max($yl, $yr, $top + 55) + 12;
        $p->line($L, $this->y, $R, $this->y, self::ACCENT, 1.5);
        $this->y += 4;

        // ---- Cliente
        $client = $q['customer_id'] ? ($q['customer_trade_name'] ?: $q['customer_name']) : $q['lead_name'];
        $addr = implode(' · ', array_filter([
            implode(', ', array_filter([$q['customer_street'] ?? '', $q['customer_number'] ?? ''])), $q['customer_complement'] ?? '', $q['customer_district'] ?? '',
            implode('/', array_filter([$q['customer_city'] ?? '', $q['customer_state'] ?? ''])),
        ]));
        $details = implode(' · ', array_filter([$q['customer_document'] ? self::doc($q['customer_document']) : '', $addr,
            $q['customer_id'] ? $q['customer_phone'] : $q['lead_phone'], $q['customer_id'] ? $q['customer_email'] : $q['lead_email']]));
        $this->title('Cliente');
        $lines = $details ? $p->wrap($details, $R - $L - 20, 8.5) : [];
        $sub = $q['customer_id'] && $q['customer_trade_name'] ? $q['customer_name'] : '';
        $h = 24 + ($sub ? 12 : 0) + count($lines) * 11.5;
        $p->rect($L, $this->y, $R - $L, $h, self::SOFT);
        $yy = $this->y + 16; $p->text($L + 10, $yy, $client ?: '—', 10.5, true);
        if ($sub) { $yy += 12; $p->text($L + 10, $yy, $sub, 9); }
        foreach ($lines as $l) { $yy += 11.5; $p->text($L + 10, $yy, $l, 8.5, false, self::MUTED); }
        $this->y += $h;

        // ---- Itens
        $disc = (bool)array_filter($q['items'], fn($i) => (float)$i['discount'] > 0);
        foreach (['produto' => 'Produtos', 'servico' => 'Serviços'] as $kind => $t) {
            $rows = array_values(array_filter($q['items'], fn($i) => $i['kind'] === $kind));
            if ($rows) $this->items($rows, $t, $disc);
        }
        if ($q['extras']) {
            $this->title('Custos adicionais');
            foreach ($q['extras'] as $x2) {
                $this->need(20); $this->y += 14;
                $p->text($L + 4, $this->y, $x2['description'], 9); $p->text($R - 4, $this->y, self::money($x2['amount']), 9, false, self::INK, 'R');
                $this->y += 6; $p->line($L, $this->y, $R, $this->y);
            }
        }

        // ---- Totais (à direita)
        $tot = array_filter([
            (float)$q['products_total'] ? ['Produtos', self::money($q['products_total'])] : null,
            (float)$q['services_total'] ? ['Serviços', self::money($q['services_total'])] : null,
            (float)$q['extras_total'] ? ['Custos adicionais', self::money($q['extras_total'])] : null,
            (float)$q['discount_total'] ? ['Desconto' . ($q['discount_type'] === 'percent' ? ' (' . self::qty($q['discount_value']) . '%)' : ''), '- ' . self::money($q['discount_total'])] : null,
        ]);
        $this->need(30 + count($tot) * 15);
        $this->y += 12; $tx = $R - 230;
        foreach ($tot as [$a, $b]) { $this->y += 14; $p->text($tx, $this->y, $a, 9, false, self::MUTED); $p->text($R - 4, $this->y, $b, 9, false, self::INK, 'R'); }
        $this->y += 8; $p->line($tx, $this->y, $R, $this->y, self::INK, 1);
        $this->y += 17; $p->text($tx, $this->y, 'Total', 12, true); $p->text($R - 4, $this->y, self::money($q['total']), 12, true, self::INK, 'R');

        // ---- Pagamento
        $methods = $q['payment_methods'] ? array_map(fn($m) => PaymentMethods::label($m), explode(',', $q['payment_methods'])) : [];
        $plan = json_decode((string)($q['installment_plan'] ?? ''), true);
        $hasPlan = ((int)$q['installments'] > 1 || (int)$q['installment_entry']) && is_array($plan) && $plan;
        if ($methods || $q['payment_terms']) {
            $this->title('Pagamento');
            $cells = array_values(array_filter([
                $methods ? ['Forma de pagamento', implode(', ', $methods)] : null,
                $hasPlan ? ['Parcelamento', ((int)$q['installment_entry'] ? 'Entrada + ' : '') . (int)$q['installments'] . 'x'] : null,
                $q['payment_terms'] ? ['Condição', $q['payment_terms']] : null,
            ]));
            $cw = ($R - $L - 10 * (count($cells) - 1)) / max(1, count($cells));
            $wrapped = array_map(fn($c) => $p->wrap($c[1], $cw - 16, 9.5), $cells);
            $h = 26 + max(array_map('count', $wrapped)) * 12;
            $this->need($h);
            $cx = $L;
            foreach ($cells as $k => [$a]) {
                $p->rect($cx, $this->y, $cw, $h, self::SOFT);
                $p->text($cx + 8, $this->y + 14, $a, 7.5, false, self::MUTED);
                foreach ($wrapped[$k] as $n => $l) $p->text($cx + 8, $this->y + 27 + $n * 12, $l, 9.5);
                $cx += $cw + 10;
            }
            $this->y += $h;
            if ($hasPlan) {
                $hasE = !empty($plan[0]['entry']); $np = count($plan) - ($hasE ? 1 : 0);
                $this->need(40); $this->y += 18;
                $c2 = $L + 200; $c3 = $L + 330;
                $p->text($L + 4, $this->y, 'PARCELA', 7.5, true, self::MUTED); $p->text($c2, $this->y, 'VALOR', 7.5, true, self::MUTED, 'R'); $p->text($c3, $this->y, 'VENCIMENTO', 7.5, true, self::MUTED, 'R');
                $this->y += 5; $p->line($L, $this->y, $c3 + 4, $this->y);
                foreach ($plan as $k => $pp) {
                    $this->need(16); $this->y += 13;
                    $p->text($L + 4, $this->y, !empty($pp['entry']) ? 'Entrada' : ($k + ($hasE ? 0 : 1)) . '/' . $np, 9, !empty($pp['entry']));
                    $p->text($c2, $this->y, self::money($pp['amount']), 9, false, self::INK, 'R');
                    $p->text($c3, $this->y, self::date($pp['due_date'] ?? ''), 9, false, self::INK, 'R');
                    $this->y += 5; $p->line($L, $this->y, $c3 + 4, $this->y);
                }
            }
        }

        // ---- Observações
        if (trim((string)$q['notes']) !== '') {
            $this->title('Observações');
            foreach ($p->wrap((string)$q['notes'], $R - $L - 8, 9.5) as $l) { $this->need(14); $this->y += 13; $p->text($L + 4, $this->y, $l, 9.5); }
        }

        // ---- Rodapé em todas as páginas
        $n = $p->pageCount();
        for ($i = 0; $i < $n; $i++) {
            $p->setPage($i);
            $p->line($L, 808, $R, 808);
            $p->text($L, 821, $company . ' · Orçamento #' . (int)$q['number'], 7.5, false, self::MUTED);
            $p->text($R, 821, 'Página ' . ($i + 1) . ' de ' . $n, 7.5, false, self::MUTED, 'R');
        }
        return $p->output();
    }

    private function items(array $rows, string $title, bool $disc): void
    {
        $p = $this->p; $L = self::L; $R = self::R;
        $this->title($title);
        // Colunas: descrição | qtd | valor unit. | (desconto) | total — números alinhados à direita
        $cTot = $R - 4; $cDisc = $disc ? $R - 95 : null; $cUnit = $disc ? $R - 170 : $R - 95; $cQty = $cUnit - 80;
        $descW = $cQty - 42 - $L;
        $head = function () use ($p, $L, $R, $cTot, $cDisc, $cUnit, $cQty) {
            $this->y += 12;
            $p->text($L + 4, $this->y, 'DESCRIÇÃO', 7.5, true, self::MUTED);
            $p->text($cQty, $this->y, 'QTD.', 7.5, true, self::MUTED, 'R');
            $p->text($cUnit, $this->y, 'VALOR UNIT.', 7.5, true, self::MUTED, 'R');
            if ($cDisc) $p->text($cDisc, $this->y, 'DESCONTO', 7.5, true, self::MUTED, 'R');
            $p->text($cTot, $this->y, 'TOTAL', 7.5, true, self::MUTED, 'R');
            $this->y += 5; $p->line($L, $this->y, $R, $this->y);
        };
        $this->need(40); $head();
        foreach ($rows as $i) {
            $lines = $p->wrap((string)$i['description'], $descW, 9);
            $h = 8 + count($lines) * 11.5;
            if ($this->y + $h + 6 > self::BOTTOM) { $p->addPage(); $this->y = 40; $head(); }
            $yy = $this->y + 13;
            foreach ($lines as $k => $l) $p->text($L + 4, $yy + $k * 11.5, $l, 9);
            $p->text($cQty, $yy, self::qty($i['quantity']) . ($i['unit'] ? ' ' . $i['unit'] : ''), 9, false, self::INK, 'R');
            $p->text($cUnit, $yy, self::money($i['unit_price']), 9, false, self::INK, 'R');
            if ($cDisc) $p->text($cDisc, $yy, (float)$i['discount'] > 0 ? '- ' . self::money($i['discount']) : '—', 9, false, self::MUTED, 'R');
            $p->text($cTot, $yy, self::money($i['total']), 9, true, self::INK, 'R');
            $this->y += $h + 6;
            $p->line($L, $this->y, $R, $this->y);
        }
    }
}
