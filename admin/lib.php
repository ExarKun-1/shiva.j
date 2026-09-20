<?php
/* =========================================================
   SHIVA.J — zajedničke funkcije za admin i API   (lib.php v01)
   Radi na običnom Linux hostingu (PHP 8.1+, GD). Podaci se čuvaju
   u JSON datotekama u mapi data/ (zaštićena .htaccess-om):
     data/config.json       postavke + lozinka (hash)
     data/proizvodi.json    glavni popis torbi (i skrivene)
     data/rezervacije.json  rezervacije i oznake "plaćeno"
     data/narudzbe.json     zadnjih 300 narudžbi
   Javno: torbe.json (vidljive torbe) i img/ (fotografije).
   ========================================================= */
declare(strict_types=1);

define('SJ_ROOT', dirname(__DIR__));
define('SJ_DATA', SJ_ROOT . '/data');
define('SJ_IMG', SJ_ROOT . '/img');
define('SJ_PUBLIC_JSON', SJ_ROOT . '/torbe.json');
define('SJ_VERSION', 'admin v01');

/* ---------- postavke ---------- */

function sj_defaults(): array {
  return [
    'shop'             => 'Shiva.J',
    'owner_email'      => 'shivaj.handmade@gmail.com', // kamo stižu narudžbe
    'from_email'       => '',                          // npr. info@shivaj.hr; prazno = owner_email
    'site_url'         => '',                          // npr. https://shivaj.hr; prazno = automatski
    'ttl_hours'        => 24,                          // koliko sati traje rezervacija
    'confirm_customer' => true,                        // automatska potvrda kupcu
    'mail_mode'        => 'mail',                      // 'mail' = PHP mail(); 'log' = data/mail-log.txt (test)
    'allowed_origins'  => [],                          // dodatni dopušteni izvori za API (npr. testni)
    'payment' => [
      'recipient' => 'SHIVA. J, obrt za dizajn',
      'address'   => 'Matije Gupca 33, 49210 Zabok',
      'iban'      => 'HR9823600001102790442',
      'model'     => 'HR00',
      'barcode'   => 'img/barkod-uplata.png',
    ],
    'pickup_info'      => 'Matije Gupca 33, Zabok — radnim danom od 8 do 15 sati, nakon uplate javite se za termin.',
    'password_hash'    => '',
    'login_fails'      => [],
    'order_seq'        => 0,
  ];
}

function sj_config(bool $reload = false): array {
  static $c = null;
  if ($c === null || $reload) $c = array_replace_recursive(sj_defaults(), sj_read_json(SJ_DATA . '/config.json', []));
  return $c;
}

/* Sve promjene postavki idu kroz bravu, jer i API (brojač narudžbi) i admin pišu istu datoteku.
   Nakon zapisa osvježava se i predmemorija sj_config(). */
function sj_config_update(callable $fn): array {
  $new = sj_with_lock(SJ_DATA . '/config.json', function (array &$d) use ($fn) {
    $d = array_replace_recursive(sj_defaults(), $d);
    $fn($d);
    return $d;
  }, []);
  sj_config(true);
  return $new;
}

function sj_save_config(array $c): void {
  sj_config_update(function (array &$d) use ($c) {
    $seq = max((int)($d['order_seq'] ?? 0), (int)($c['order_seq'] ?? 0));
    $d = $c;
    $d['order_seq'] = $seq;
  });
}

function sj_site_url(): string {
  $c = sj_config();
  if (!empty($c['site_url'])) return rtrim($c['site_url'], '/');
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return ($https ? 'https://' : 'http://') . $host;
}

/* ---------- JSON datoteke ---------- */

function sj_read_json(string $path, $default) {
  if (!is_file($path)) return $default;
  $d = json_decode((string)file_get_contents($path), true);
  return is_array($d) ? $d : $default;
}

function sj_write_json(string $path, $data): void {
  $dir = dirname($path);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $tmp = $path . '.tmp';
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
  rename($tmp, $path);
}

/* Zaključani rad nad JSON datotekom: $fn(array &$data) smije mijenjati $data; vraća što god želi. */
function sj_with_lock(string $path, callable $fn, $default = []) {
  $dir = dirname($path);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $fp = fopen($path . '.lock', 'c');
  flock($fp, LOCK_EX);
  try {
    $data = sj_read_json($path, $default);
    $before = json_encode($data);
    $result = $fn($data);
    if (json_encode($data) !== $before) sj_write_json($path, $data);
    return $result;
  } finally {
    flock($fp, LOCK_UN);
    fclose($fp);
  }
}

/* ---------- proizvodi ---------- */

function sj_products_path(): string { return SJ_DATA . '/proizvodi.json'; }

/* Glavni popis; ako još ne postoji, uvozi se iz javne torbe.json (prvi prijenos na hosting). */
function sj_products(): array {
  $p = sj_read_json(sj_products_path(), null);
  if ($p === null) {
    $p = sj_read_json(SJ_PUBLIC_JSON, []);
    if ($p) sj_write_json(sj_products_path(), $p);
  }
  return is_array($p) ? array_values($p) : [];
}

function sj_save_products(array $list): void {
  $list = array_values($list);
  sj_write_json(sj_products_path(), $list);
  $public = [];
  foreach ($list as $p) {
    if (!empty($p['hidden'])) continue;
    unset($p['hidden']);
    $public[] = $p;
  }
  sj_write_json(SJ_PUBLIC_JSON, $public);
}

function sj_slug(string $s): string {
  $map = ['č'=>'c','ć'=>'c','ž'=>'z','š'=>'s','đ'=>'d','Č'=>'c','Ć'=>'c','Ž'=>'z','Š'=>'s','Đ'=>'d'];
  $s = strtr($s, $map);
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
  return trim($s, '-') ?: 'torba';
}

function sj_unique_id(string $base, array $list, ?string $keep = null): string {
  $id = $base; $n = 2;
  $taken = [];
  foreach ($list as $p) if (($p['id'] ?? '') !== $keep) $taken[$p['id'] ?? ''] = true;
  while (isset($taken[$id])) $id = $base . '-' . $n++;
  return $id;
}

/* ---------- rezervacije ---------- */

function sj_res_path(): string { return SJ_DATA . '/rezervacije.json'; }

function sj_res_default(): array { return ['res' => [], 'sold' => []]; }

/* istekle rezervacije se brišu pri svakom čitanju */
function sj_res_prune(array &$d): void {
  $now = time();
  foreach ($d['res'] ?? [] as $id => $r) {
    if (strtotime($r['until'] ?? '') < $now) unset($d['res'][$id]);
  }
}

function sj_res_read(): array {
  return sj_with_lock(sj_res_path(), function (array &$d) {
    $d = array_replace(sj_res_default(), $d);
    sj_res_prune($d);
    return $d;
  }, sj_res_default());
}

function sj_iso(int $t): string { return gmdate('Y-m-d\TH:i:s\Z', $t); }

function sj_token(): string { return bin2hex(random_bytes(16)); }

/* ---------- prijava ---------- */

function sj_session_start(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
  session_name('sjadmin');
  session_start();
}

function sj_logged_in(): bool {
  sj_session_start();
  return !empty($_SESSION['sj_admin']);
}

function sj_login(string $password): bool {
  $c = sj_config(true);
  $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
  $fails = $c['login_fails'][$ip] ?? ['n' => 0, 't' => 0];
  if ($fails['n'] >= 8 && time() - $fails['t'] < 900) return false; /* 15 min pauze nakon 8 promašaja */
  if ($c['password_hash'] && password_verify($password, $c['password_hash'])) {
    sj_session_start();
    session_regenerate_id(true);
    $_SESSION['sj_admin'] = true;
    $_SESSION['sj_csrf'] = sj_token();
    sj_config_update(function (array &$d) use ($ip) { unset($d['login_fails'][$ip]); });
    return true;
  }
  usleep(400000);
  sj_config_update(function (array &$d) use ($ip, $fails) { $d['login_fails'][$ip] = ['n' => (int)$fails['n'] + 1, 't' => time()]; });
  return false;
}

function sj_logout(): void {
  sj_session_start();
  $_SESSION = [];
  session_destroy();
}

function sj_csrf(): string {
  sj_session_start();
  if (empty($_SESSION['sj_csrf'])) $_SESSION['sj_csrf'] = sj_token();
  return $_SESSION['sj_csrf'];
}

function sj_check_csrf(): bool {
  sj_session_start();
  return isset($_POST['csrf'], $_SESSION['sj_csrf']) && hash_equals($_SESSION['sj_csrf'], (string)$_POST['csrf']);
}

/* ---------- pomoćne ---------- */

function e($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function sj_clean(?string $v, int $max): string {
  $v = trim((string)$v);
  if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8'); /* neispravni bajtovi → ne ruši unos */
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
  if ($v === null) $v = '';
  return mb_substr($v, 0, $max);
}

function sj_fmt_eur(float $n): string { return number_format($n, 2, ',', '.') . ' €'; }

function sj_fmt_hr(?string $iso): string {
  if (!$iso) return '—';
  $t = strtotime($iso);
  if (!$t) return $iso;
  $dt = new DateTime('@' . $t);
  $dt->setTimezone(new DateTimeZone('Europe/Zagreb'));
  return $dt->format('d. m. Y. H:i');
}

function sj_json_out($data, int $status = 200, array $headers = []): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  foreach ($headers as $k => $v) header("$k: $v");
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* ---------- fotografije ---------- */

/* Sprema prenesenu sliku kao img/<base>-<n>.jpg, smanjenu na najviše 1400 px. Vraća relativnu putanju ili null. */
function sj_store_image(string $tmpPath, string $base): ?string {
  $info = @getimagesize($tmpPath);
  if (!$info) return null;
  [$w, $h, $type] = $info;
  switch ($type) {
    case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($tmpPath); break;
    case IMAGETYPE_PNG:  $img = @imagecreatefrompng($tmpPath); break;
    case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false; break;
    default: return null;
  }
  if (!$img) return null;

  /* okretanje prema EXIF orijentaciji (fotografije s telefona) */
  if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $exif = @exif_read_data($tmpPath);
    $o = (int)($exif['Orientation'] ?? 1);
    if ($o === 3) $img = imagerotate($img, 180, 0);
    elseif ($o === 6) $img = imagerotate($img, -90, 0);
    elseif ($o === 8) $img = imagerotate($img, 90, 0);
    $w = imagesx($img); $h = imagesy($img);
  }

  $max = 1400;
  $scale = min(1.0, $max / max($w, $h));
  $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
  $out = imagecreatetruecolor($nw, $nh);
  $white = imagecolorallocate($out, 255, 255, 255);
  imagefill($out, 0, 0, $white); /* PNG prozirnost → bijela */
  imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

  if (!is_dir(SJ_IMG)) mkdir(SJ_IMG, 0755, true);
  $n = 1;
  do { $file = SJ_IMG . '/' . $base . '-' . $n . '.jpg'; $n++; } while (file_exists($file));
  imagejpeg($out, $file, 82);
  imagedestroy($out); imagedestroy($img);
  return 'img/' . basename($file);
}

/* ---------- e-mail ---------- */

function sj_mail_from(): string {
  $c = sj_config();
  return $c['from_email'] ?: $c['owner_email'];
}

/* Šalje običan tekstualni e-mail (UTF-8), s neobaveznim privitkom. U mail_mode 'log' zapisuje u data/mail-log.txt. */
function sj_send_mail(string $to, string $subject, string $text, ?string $attachmentPath = null, ?string $replyTo = null): bool {
  $c = sj_config();
  $from = sj_mail_from();
  $shop = $c['shop'];
  $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $fromHeader = '=?UTF-8?B?' . base64_encode($shop) . '?= <' . $from . '>';
  $boundary = 'sj-' . bin2hex(random_bytes(8));
  $headers = ["From: $fromHeader", "Reply-To: " . ($replyTo ?: $from), "MIME-Version: 1.0", "X-Mailer: Shiva.J"];

  if ($attachmentPath && is_file($attachmentPath)) {
    $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n";
    $body .= "--$boundary\r\nContent-Type: image/png; name=\"" . basename($attachmentPath) . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . basename($attachmentPath) . "\"\r\n\r\n";
    $body .= chunk_split(base64_encode((string)file_get_contents($attachmentPath))) . "--$boundary--\r\n";
  } else {
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";
    $body = $text;
  }

  if (($c['mail_mode'] ?? 'mail') === 'log') {
    if (!is_dir(SJ_DATA)) mkdir(SJ_DATA, 0755, true);
    $entry = "=== " . date('c') . " | To: $to | Subject: $subject | Attachment: " . ($attachmentPath ? basename($attachmentPath) : '-') . "\n" . $text . "\n\n";
    return (bool)file_put_contents(SJ_DATA . '/mail-log.txt', $entry, FILE_APPEND | LOCK_EX);
  }
  return @mail($to, $encSubject, $body, implode("\r\n", $headers));
}
