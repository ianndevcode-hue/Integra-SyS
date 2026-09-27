<?php
declare(strict_types=1);

/**
 * Minimal PDF 1.4 writer (no dependencies): A4 pages, Helvetica / Helvetica-Bold (WinAnsi),
 * text with wrapping, lines, rectangles and fills. Coordinates are in points from the TOP-left
 * corner (converted internally). Used for DANFSe PDFs and fiscal reports.
 */
class MiniPdf
{
    public const W = 595.28;
    public const H = 841.89;
    private array $pages = [];
    private int $page = -1;
    private string $font = 'F1';
    private float $size = 10;
    private array $color = [0, 0, 0];
    private string $title;

    private const HELV = [32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const HELVB = [32 => 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    public function __construct(string $title = 'Documento')
    {
        $this->title = $title;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->page = count($this->pages) - 1;
    }

    public function font(bool $bold, float $size): self
    {
        $this->font = $bold ? 'F2' : 'F1';
        $this->size = $size;
        return $this;
    }

    public function color(int $r, int $g, int $b): self
    {
        $this->color = [$r, $g, $b];
        return $this;
    }

    private function rgb(array $c): string
    {
        return sprintf('%.3F %.3F %.3F', $c[0] / 255, $c[1] / 255, $c[2] / 255);
    }

    private function out(string $s): void
    {
        if ($this->page < 0) $this->addPage();
        $this->pages[$this->page] .= $s . "\n";
    }

    /** UTF-8 → WinAnsi (cp1252) bytes, escaped for a PDF string. */
    private function enc(string $s): string
    {
        $s = strtr($s, ['“' => '"', '”' => '"', '‘' => "'", '’' => "'", '…' => '...', "\t" => ' ']);
        $b = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        if ($b === false) $b = preg_replace('/[^\x20-\x7E]/', '?', $s);
        return strtr($b, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    public function width(string $s, ?float $size = null, ?bool $bold = null): float
    {
        $table = ($bold ?? $this->font === 'F2') ? self::HELVB : self::HELV;
        $w = 0;
        $plain = strip_accents($s);
        $len = mb_strlen($plain);
        for ($i = 0; $i < $len; $i++) {
            $o = mb_ord(mb_substr($plain, $i, 1)) ?: 63;
            $w += $table[$o] ?? ($o === 8212 ? 1000 : 556);
        }
        return $w * ($size ?? $this->size) / 1000;
    }

    /** Draw text; $align: L, R or C relative to $w. Y is the baseline measured from the top. */
    public function text(float $x, float $y, string $s, float $w = 0, string $align = 'L'): void
    {
        if ($s === '') return;
        if ($w > 0 && $align !== 'L') {
            $tw = $this->width($s);
            $x += $align === 'R' ? $w - $tw : ($w - $tw) / 2;
        }
        $this->out(sprintf('BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $this->rgb($this->color), $this->font, $this->size, $x, self::H - $y, $this->enc($s)));
    }

    /** Wrap text into lines that fit $w. */
    public function wrap(string $s, float $w): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $s) as $para) {
            $words = preg_split('/\s+/', trim($para));
            $line = '';
            foreach ($words as $word) {
                if ($word === '') continue;
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->width($try) <= $w) { $line = $try; continue; }
                if ($line !== '') $lines[] = $line;
                while ($this->width($word) > $w && mb_strlen($word) > 1) { // hard-break long tokens
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->width(mb_substr($word, 0, $cut)) > $w) $cut--;
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $line = $word;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** Paragraph; returns the Y after the last line. */
    public function para(float $x, float $y, float $w, string $s, float $lead = 0, int $maxLines = 0): float
    {
        $lead = $lead ?: $this->size * 1.35;
        $lines = $this->wrap($s, $w);
        if ($maxLines && count($lines) > $maxLines) { $lines = array_slice($lines, 0, $maxLines); $lines[$maxLines - 1] .= ' (...)'; }
        foreach ($lines as $l) { $this->text($x, $y, $l); $y += $lead; }
        return $y;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [200, 205, 215]): void
    {
        $this->out(sprintf('%s RG %.2F w %.2F %.2F m %.2F %.2F l S', $this->rgb($rgb), $width, $x1, self::H - $y1, $x2, self::H - $y2));
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = [200, 205, 215], float $lw = 0.5): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $cmd = ($fill ? $this->rgb($fill) . ' rg ' : '') . ($stroke ? $this->rgb($stroke) . ' RG ' . sprintf('%.2F w ', $lw) : '');
        $this->out($cmd . sprintf('%.2F %.2F %.2F %.2F re %s', $x, self::H - $y - $h, $w, $h, $op));
    }

    /** Rotated large text (watermark). */
    public function watermark(string $s, array $rgb = [230, 60, 80]): void
    {
        $this->out(sprintf('q %s rg BT /F2 70 Tf 0.707 0.707 -0.707 0.707 150 220 Tm (%s) Tj ET Q', $this->rgb($rgb), $this->enc($s)));
    }

    public function output(): string
    {
        if (!$this->pages) $this->addPage();
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fonts = '<< /F1 3 0 R /F2 4 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $stream = gzcompress($content);
            $objs[$n] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font %s >> /Contents %d 0 R >>', self::W, self::H, $fonts, $n + 1);
            $objs[$n + 1] = "<< /Length " . strlen($stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream";
            $kids[] = "$n 0 R";
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[$n] = '<< /Title (' . $this->enc($this->title) . ') /Producer (Integra Fiscal Hub) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "$i 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        $pdf .= "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R /Info $n 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}

/**
 * Build a ZIP archive in memory. $files: [name => content]. Uses ZipArchive when available,
 * otherwise a small pure-PHP writer (deflate).
 */
function zip_build(array $files): string
{
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::OVERWRITE) === true) {
            foreach ($files as $name => $content) $z->addFromString($name, $content);
            $z->close();
            $data = (string)file_get_contents($tmp);
            @unlink($tmp);
            if ($data !== '') return $data;
        }
        @unlink($tmp);
    }
    $out = '';
    $central = '';
    $count = 0;
    [$dosTime, $dosDate] = [((int)date('H') << 11) | ((int)date('i') << 5) | intdiv((int)date('s'), 2), (((int)date('Y') - 1980) << 9) | ((int)date('n') << 5) | (int)date('j')];
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $comp = gzdeflate($content, 6);
        $offset = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($content), strlen($name), 0) . $name . $comp;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($content), strlen($name), 0, 0, 0, 0, 32, $offset) . $name;
        $count++;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
}
