<?php
declare(strict_types=1);

/**
 * Minimal QR Code encoder (ISO/IEC 18004): byte mode, error correction level M, versions 1–10
 * (up to 213 bytes: enough for the NFS-e consultation URL). No dependencies.
 * qr_matrix($text) returns a square bool[][] (true = dark module), without the quiet zone.
 */

const QR_M_BLOCKS = [ // version => [EC codewords per block, [[blocks, data codewords per block], ...]]
    1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
    6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
];
const QR_ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

function qr_gf(): array
{
    static $t = null;
    if ($t) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11D;
    }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = [$exp, $log];
}

/** Reed–Solomon error correction codewords for $data. */
function qr_rs(array $data, int $n): array
{
    [$exp, $log] = qr_gf();
    $gen = [1];
    for ($i = 0; $i < $n; $i++) { // (x - a^i)
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $g) {
            $next[$j] ^= $g;
            $next[$j + 1] ^= $g ? $exp[$log[$g] + $i] : 0;
        }
        $gen = $next;
    }
    $res = array_fill(0, $n, 0);
    foreach ($data as $d) {
        $f = $d ^ $res[0];
        array_shift($res);
        $res[] = 0;
        if ($f) for ($j = 0; $j < $n; $j++) $res[$j] ^= $gen[$j + 1] ? $exp[$log[$gen[$j + 1]] + $log[$f]] : 0;
    }
    return $res;
}

function qr_matrix(string $text): array
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    $len = count($bytes);
    $ver = 0;
    foreach (QR_M_BLOCKS as $v => [, $groups]) {
        $cap = array_sum(array_map(fn($g) => $g[0] * $g[1], $groups));
        if ((4 + ($v < 10 ? 8 : 16) + 8 * $len + 7) >> 3 <= $cap) { $ver = $v; break; }
    }
    if (!$ver) throw new InvalidArgumentException('Texto longo demais para o QR Code.');
    [$ecn, $groups] = QR_M_BLOCKS[$ver];
    $cap = array_sum(array_map(fn($g) => $g[0] * $g[1], $groups));
    // bit stream
    $bits = '0100' . str_pad(decbin($len), $ver < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $cap * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $data = array_map('bindec', str_split($bits, 8));
    for ($i = 0; count($data) < $cap; $i++) $data[] = $i % 2 ? 0x11 : 0xEC;
    // blocks + interleaving
    $blocks = [];
    $ecs = [];
    $pos = 0;
    foreach ($groups as [$count, $size]) {
        for ($b = 0; $b < $count; $b++) {
            $blk = array_slice($data, $pos, $size);
            $pos += $size;
            $blocks[] = $blk;
            $ecs[] = qr_rs($blk, $ecn);
        }
    }
    $final = [];
    $max = max(array_map('count', $blocks));
    for ($i = 0; $i < $max; $i++) foreach ($blocks as $blk) if (isset($blk[$i])) $final[] = $blk[$i];
    for ($i = 0; $i < $ecn; $i++) foreach ($ecs as $ec) $final[] = $ec[$i];
    // matrix: null = free, bool = function module
    $n = 17 + 4 * $ver;
    $m = array_fill(0, $n, array_fill(0, $n, null));
    $finder = function (int $r, int $c) use (&$m, $n) {
        for ($i = -1; $i <= 7; $i++) for ($j = -1; $j <= 7; $j++) {
            $y = $r + $i; $x = $c + $j;
            if ($y < 0 || $x < 0 || $y >= $n || $x >= $n) continue;
            $in = $i >= 0 && $i <= 6 && $j >= 0 && $j <= 6;
            $m[$y][$x] = $in && ($i === 0 || $i === 6 || $j === 0 || $j === 6 || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4));
        }
    };
    $finder(0, 0); $finder(0, $n - 7); $finder($n - 7, 0);
    for ($i = 8; $i < $n - 8; $i++) { $m[6][$i] = $i % 2 === 0; $m[$i][6] = $i % 2 === 0; }
    $na = count(QR_ALIGN[$ver]);
    foreach (QR_ALIGN[$ver] as $ia => $ay) foreach (QR_ALIGN[$ver] as $ja => $ax) {
        if (($ia === 0 && $ja === 0) || ($ia === 0 && $ja === $na - 1) || ($ia === $na - 1 && $ja === 0)) continue; // finder corners
        for ($i = -2; $i <= 2; $i++) for ($j = -2; $j <= 2; $j++) $m[$ay + $i][$ax + $j] = max(abs($i), abs($j)) !== 1;
    }
    $m[4 * $ver + 9][8] = true; // dark module
    // reserve format (and version) areas
    for ($i = 0; $i < 9; $i++) { if ($m[8][$i] === null) $m[8][$i] = false; if ($m[$i][8] === null) $m[$i][8] = false; }
    for ($i = 0; $i < 8; $i++) { if ($m[8][$n - 1 - $i] === null) $m[8][$n - 1 - $i] = false; if ($m[$n - 1 - $i][8] === null) $m[$n - 1 - $i][8] = false; }
    if ($ver >= 7) for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) { $m[$i][$n - 11 + $j] = false; $m[$n - 11 + $j][$i] = false; }
    $func = array_map(fn($row) => array_map(fn($v) => $v !== null, $row), $m);
    // data placement (zigzag)
    $bitsOut = '';
    foreach ($final as $cw) $bitsOut .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    $k = 0;
    $up = true;
    for ($col = $n - 1; $col > 0; $col -= 2) {
        if ($col === 6) $col--;
        for ($t = 0; $t < $n; $t++) {
            $row = $up ? $n - 1 - $t : $t;
            for ($c = 0; $c < 2; $c++) {
                $x = $col - $c;
                if ($func[$row][$x]) continue;
                $m[$row][$x] = $k < strlen($bitsOut) && $bitsOut[$k] === '1';
                $k++;
            }
        }
        $up = !$up;
    }
    // choose the mask with the lowest penalty
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $t = qr_apply($m, $func, $mask, $ver);
        $s = qr_penalty($t);
        if ($s < $bestScore) { $bestScore = $s; $best = $t; }
    }
    return $best;
}

function qr_apply(array $m, array $func, int $mask, int $ver): array
{
    $n = count($m);
    for ($r = 0; $r < $n; $r++) for ($c = 0; $c < $n; $c++) {
        if ($func[$r][$c]) continue;
        $inv = match ($mask) {
            0 => ($r + $c) % 2 === 0, 1 => $r % 2 === 0, 2 => $c % 3 === 0, 3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0, 5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
            6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0, default => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
        };
        if ($inv) $m[$r][$c] = !$m[$r][$c];
    }
    // format information: EC level M (00) + mask, BCH(15,5), XOR 0x5412
    $d = (0 << 3) | $mask;
    $rem = $d << 10;
    for ($i = 14; $i >= 10; $i--) if ($rem & (1 << $i)) $rem ^= 0x537 << ($i - 10);
    $f = (($d << 10) | $rem) ^ 0x5412;
    $bit = fn($i) => (($f >> $i) & 1) === 1;
    for ($i = 0; $i <= 5; $i++) $m[$i][8] = $bit($i);
    $m[7][8] = $bit(6); $m[8][8] = $bit(7); $m[8][7] = $bit(8);
    for ($i = 9; $i < 15; $i++) $m[8][14 - $i] = $bit($i);
    for ($i = 0; $i < 8; $i++) $m[8][$n - 1 - $i] = $bit($i);
    for ($i = 8; $i < 15; $i++) $m[$n - 15 + $i][8] = $bit($i);
    $m[$n - 8][8] = true;
    if ($ver >= 7) { // version information, BCH(18,6)
        $rem = $ver << 12;
        for ($i = 17; $i >= 12; $i--) if ($rem & (1 << $i)) $rem ^= 0x1F25 << ($i - 12);
        $v = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $b = (($v >> $i) & 1) === 1;
            $m[intdiv($i, 3)][$n - 11 + $i % 3] = $b;
            $m[$n - 11 + $i % 3][intdiv($i, 3)] = $b;
        }
    }
    return $m;
}

function qr_penalty(array $m): int
{
    $n = count($m);
    $s = 0;
    for ($pass = 0; $pass < 2; $pass++) { // runs of 5+ and finder-like patterns, rows then columns
        for ($i = 0; $i < $n; $i++) {
            $line = '';
            for ($j = 0; $j < $n; $j++) $line .= ($pass ? $m[$j][$i] : $m[$i][$j]) ? '1' : '0';
            preg_match_all('/0{5,}|1{5,}/', $line, $mm);
            foreach ($mm[0] as $run) $s += strlen($run) - 2;
            $s += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
        }
    }
    for ($i = 0; $i < $n - 1; $i++) for ($j = 0; $j < $n - 1; $j++) {
        $v = $m[$i][$j];
        if ($v === $m[$i + 1][$j] && $v === $m[$i][$j + 1] && $v === $m[$i + 1][$j + 1]) $s += 3;
    }
    $dark = 0;
    foreach ($m as $row) foreach ($row as $v) $dark += $v ? 1 : 0;
    $s += 10 * intdiv(abs($dark * 20 - $n * $n * 10), $n * $n);
    return $s;
}
