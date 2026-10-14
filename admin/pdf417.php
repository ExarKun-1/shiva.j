<?php
/* =========================================================
   SHIVA.J — PDF417 (HUB-3 barkod za uplatu) u PHP-u   (v24)
   Isti barkod koji stranica crta u pregledniku (js/hub3-barcode.js), ali na poslužitelju: ide kao privitak
   u e-mail kupcu, pa kupac može platiti ravno iz e-maila. Kodiranje bajtovima (byte compaction), razina
   ispravljanja pogrešaka 4 (32 kodne riječi), 9 stupaca — kao na stranici. Tablice uzoraka: pdf417_tablice.php
   (bwip-js, MIT). Provjera: tests/pdf417.js (dekoder + neovisni čitač).
   ========================================================= */
declare(strict_types=1);

/* Tekst HUB-3 naloga — isto kao hub3Text() u index.html (polja, duljine, iznos u centima na 15 znamenki). */
function sj_hub3_text(array $d): string {
  $cut = fn($t, int $n) => mb_substr(trim((string)preg_replace('/[\r\n]+/', ' ', (string)$t)), 0, $n);
  $addr = (string)($d['address'] ?? '');
  $i = mb_strrpos($addr, ',');
  $street = $i !== false && $i > 0 ? mb_substr($addr, 0, $i) : $addr;
  $place = $i !== false && $i > 0 ? mb_substr($addr, $i + 1) : '';
  $cents = (int)round((float)($d['amount'] ?? 0) * 100);
  return implode("\n", ['HRVHUB30', 'EUR', str_pad((string)$cents, 15, '0', STR_PAD_LEFT), '', '', '',
    $cut($d['recipient'] ?? '', 25), $cut($street, 25), $cut($place, 27), $cut(preg_replace('/\s+/', '', (string)($d['iban'] ?? '')), 21),
    $cut(($d['model'] ?? '') !== '' ? $d['model'] : 'HR00', 4), $cut($d['ref'] ?? '', 22), 'OTHR', $cut($d['desc'] ?? '', 35)]) . "\n";
}

/* Koeficijenti generatorskog polinoma g(x) = (x−3)(x−3²)…(x−3^k) nad GF(929), od najnižeg stupnja. */
function sj_pdf417_gen(int $k): array {
  $g = [1];
  $a = 1;
  for ($i = 1; $i <= $k; $i++) {
    $a = ($a * 3) % 929;
    $n = array_fill(0, count($g) + 1, 0);
    foreach ($g as $j => $c) {
      $n[$j + 1] = ($n[$j + 1] + $c) % 929;
      $n[$j] = ($n[$j] + 929 - ($c * $a) % 929) % 929;
    }
    $g = $n;
  }
  return $g; /* $g[$k] = 1 */
}

/* Kodne riječi ispravljanja pogrešaka (ostatak dijeljenja s g(x)), redom kako idu u simbol. */
function sj_pdf417_ec(array $data, int $k): array {
  $g = sj_pdf417_gen($k);
  $r = array_fill(0, $k, 0); /* r[k-1] je najviši stupanj */
  foreach ($data as $d) {
    $t = ($d + $r[$k - 1]) % 929;
    for ($j = $k - 1; $j > 0; $j--) $r[$j] = ($r[$j - 1] + 929 - ($t * $g[$j]) % 929) % 929;
    $r[0] = (929 - ($t * $g[0]) % 929) % 929;
  }
  $out = [];
  for ($j = $k - 1; $j >= 0; $j--) $out[] = (929 - $r[$j]) % 929;
  return $out;
}

/* Sve kodne riječi simbola: opisnik duljine, prijelaz u bajtove, podaci, popuna (900), ispravljanje pogrešaka. */
function sj_pdf417_codewords(string $bytes, int $cols = 9, int $ecl = 4): array {
  $n = strlen($bytes);
  $cw = [$n % 6 === 0 ? 924 : 901];
  $full = intdiv($n, 6) * 6;
  for ($i = 0; $i < $full; $i += 6) {
    $v = 0; /* 6 bajtova = 48 bita, stane u PHP int (64 bita) */
    for ($j = 0; $j < 6; $j++) $v = $v * 256 + ord($bytes[$i + $j]);
    $g = [];
    for ($j = 0; $j < 5; $j++) { $g[] = $v % 900; $v = intdiv($v, 900); }
    foreach (array_reverse($g) as $x) $cw[] = $x;
  }
  for ($i = $full; $i < $n; $i++) $cw[] = ord($bytes[$i]);
  $k = 2 ** ($ecl + 1);
  $rows = max(3, (int)ceil((count($cw) + 1 + $k) / $cols));
  if ($rows > 90) throw new RuntimeException('PDF417: predugo');
  $pad = $rows * $cols - (count($cw) + 1) - $k;
  $data = array_merge([count($cw) + 1 + $pad], $cw, array_fill(0, $pad, 900));
  return ['rows' => $rows, 'cols' => $cols, 'ecl' => $ecl, 'cw' => array_merge($data, sj_pdf417_ec($data, $k))];
}

/* Matrica modula: po retku niz '0'/'1' (start, lijevi indikator, podaci, desni indikator, stop). */
function sj_pdf417_matrix(string $bytes, int $cols = 9, int $ecl = 4): array {
  static $T = null;
  if ($T === null) $T = require __DIR__ . '/pdf417_tablice.php';
  $s = sj_pdf417_codewords($bytes, $cols, $ecl);
  $r = $s['rows'];
  $pat = fn(int $cl, int $v) => str_pad(decbin($T[$cl][$v]), 17, '0', STR_PAD_LEFT);
  $out = [];
  for ($i = 0; $i < $r; $i++) {
    $cl = $i % 3; $base = 30 * intdiv($i, 3);
    $rv = intdiv($r - 1, 3); $ev = $ecl * 3 + ($r - 1) % 3; $cv = $cols - 1;
    [$left, $right] = $cl === 0 ? [$rv, $cv] : ($cl === 1 ? [$ev, $rv] : [$cv, $ev]);
    $row = '11111111010101000' . $pat($cl, $base + $left);
    for ($j = 0; $j < $cols; $j++) $row .= $pat($cl, $s['cw'][$i * $cols + $j]);
    $out[] = $row . $pat($cl, $base + $right) . '111111101000101001';
  }
  return $out;
}

/* PNG barkoda (GD): modul 2 px, redak 6 px, bijeli rub 12 px — kao na stranici. Vraća true ako je zapisan. */
function sj_pdf417_png(string $text, string $path, int $scale = 2, int $rowmult = 3, int $pad = 6): bool {
  if (!function_exists('imagecreate')) return false;
  $m = sj_pdf417_matrix($text);
  $w = strlen($m[0]) * $scale + 2 * $pad * $scale;
  $h = count($m) * $rowmult * $scale + 2 * $pad * $scale;
  $im = imagecreate($w, $h);
  if (!$im) return false;
  imagecolorallocate($im, 255, 255, 255);
  $black = imagecolorallocate($im, 0, 0, 0);
  foreach ($m as $y => $row) {
    $len = strlen($row);
    for ($x = 0; $x < $len; $x++) {
      if ($row[$x] !== '1') continue;
      $x0 = $pad * $scale + $x * $scale; $y0 = $pad * $scale + $y * $rowmult * $scale;
      imagefilledrectangle($im, $x0, $y0, $x0 + $scale - 1, $y0 + $rowmult * $scale - 1, $black);
    }
  }
  $ok = imagepng($im, $path);
  imagedestroy($im);
  return $ok && is_file($path);
}
