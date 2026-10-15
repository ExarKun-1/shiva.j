<?php
/* =========================================================
   SHIVA.J — zajedničke funkcije za admin i API   (lib.php v03)
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
define('SJ_VERSION', 'admin v29');

/* ---------- postavke ---------- */

function sj_defaults(): array {
  return [
    'shop'             => 'Shiva.J',
    'owner_email'      => 'shivaj.handmade@gmail.com', // kamo stižu narudžbe
    'from_email'       => '',                          // npr. info@shivaj.hr; prazno = owner_email
    'site_url'         => '',                          // npr. https://shivaj.hr; prazno = automatski
    'oib'              => '',                          // v25: OIB obrta za potpis u e-mailu kupcu; prazno = ne prikazuje se
    'ttl_hours'        => 24,                          // koliko sati traje rezervacija
    'confirm_customer' => true,                        // automatska potvrda kupcu
    'require_confirm'  => true,                        // narudžba vrijedi tek kad je kupac potvrdi linkom iz e-maila
    'confirm_minutes'  => 60,                          // koliko minuta vrijedi link za potvrdu
    'mail_mode'        => 'mail',                      // 'mail' = PHP mail(); 'log' = data/mail-log.txt (test)
    'allowed_origins'  => [],                          // dodatni dopušteni izvori za API (npr. testni)
    /* Načini dostave — jedini izvor istine: stranica ih čita iz api/status, a servis
       po njima računa iznos narudžbe. price u EUR (0 = besplatno), address = traži se
       adresa, locker = kupac bira BOX NOW paketomat. Prva opcija je zadana. */
    'shipping' => [
      ['id' => 'boxnow', 'label' => 'BOX NOW paketomat', 'price' => 4.00, 'address' => false, 'locker' => true,
       'note' => 'Paket stiže u paketomat po vašem izboru; kod za preuzimanje dobivate SMS-om ili e-mailom.'],
      ['id' => 'gls', 'label' => 'GLS dostava na adresu', 'price' => 6.00, 'address' => true, 'locker' => false,
       'note' => 'Kurir dostavlja na vašu adresu, obično sljedeći radni dan nakon slanja.'],
      ['id' => 'pickup', 'label' => 'Osobno preuzimanje u radionici', 'price' => 0, 'address' => false, 'locker' => false,
       'note' => 'Matije Gupca 33, Zabok — radnim danom od 8 do 15 sati, nakon uplate i uz dogovor termina.'],
    ],
    'payment' => [
      'recipient' => 'SHIVA. J, obrt za dizajn',
      'address'   => 'Matije Gupca 33, 49210 Zabok',
      'iban'      => 'HR9823600001102790442',
      'model'     => 'HR00',
      'barcode'   => 'img/barkod-uplata.png',
    ],
    'ship_lead'        => '',                          // rok slanja nakon uplate, npr. "1–2 radna dana" (prazno = ne prikazuje se)
    'pickup_info'      => 'Matije Gupca 33, Zabok — radnim danom od 8 do 15 sati; kad je narudžba spremna, javit ćemo vam se i dogovoriti termin.',
    'password_hash'    => '',
    'login_fails'      => [],
    'order_seq'        => 0,
  ];
}

function sj_config(bool $reload = false): array {
  static $c = null;
  if ($c === null || $reload) {
    $saved = sj_read_json(SJ_DATA . '/config.json', []);
    $c = array_replace_recursive(sj_defaults(), $saved);
    /* popisi se ne spajaju po indeksu, nego zamjenjuju u cijelosti */
    foreach (['shipping', 'allowed_origins'] as $k) if (isset($saved[$k]) && is_array($saved[$k])) $c[$k] = array_values($saved[$k]);
  }
  return $c;
}

/* config.json postoji, ali nije čitljiv JSON → admin ne smije ponuditi novo postavljanje lozinke */
function sj_config_corrupt(): bool {
  $p = SJ_DATA . '/config.json';
  return is_file($p) && sj_read_json($p, null) === null;
}

/* Prva prijava (postavljanje lozinke) dopuštena je samo dok postoji ova datoteka; briše se
   čim je lozinka postavljena, pa se admin ne može „preuzeti” ako se config.json ošteti. */
/* v23: imenica uz ime u porukama admina — „Torba „Luna” je skrivena”, „Artikl „Remen” je skriven” (rod slaže imenica) */
function sj_kind(array $p): array {
  $art = (($p['cat'] ?? 'torbe') === 'ostalo');
  return $art ? ['Artikl', false] : ['Torba', true];
}
function sj_setup_path(): string { return SJ_DATA . '/PRVA-PRIJAVA.txt'; }
/* v20: kod za prvu lozinku — redak „kod: …” (barem 16 znakova) u data/PRVA-PRIJAVA.txt. Bez koda prvi posjetitelj
   admina ne može postaviti lozinku (zaštita dok datoteka čeka na hostingu). */
function sj_setup_code(): string {
  $t = (string)@file_get_contents(sj_setup_path());
  return preg_match('/^\s*kod:\s*(\S{16,})\s*$/mi', $t, $m) ? $m[1] : '';
}

function sj_shipping(): array {
  $out = [];
  foreach (sj_config()['shipping'] ?? [] as $o) {
    if (!is_array($o) || empty($o['id'])) continue;
    $out[] = ['id' => (string)$o['id'], 'label' => (string)($o['label'] ?? $o['id']), 'price' => round((float)($o['price'] ?? 0), 2),
              'address' => !empty($o['address']), 'locker' => !empty($o['locker']), 'note' => (string)($o['note'] ?? '')];
  }
  return $out;
}

/* Ograničenje broja zahtjeva po IP adresi (npr. 10 narudžbi na sat). true = smije. */
/* Skraćena adresa: HMAC adrese (IPv6 po mreži /48) s nasumičnom tajnom (data/sol.json, random_bytes, mijenja se
   svaki tjedan), 16 znakova. Kad tajna istekne, stare skraćene adrese više se ne mogu povezati s pravima. */
function sj_short_ip(?string $ip = null, bool $prev = false): string {
  $ip = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? '?');
  /* IPv6: kućni priključak obično dobiva cijelu mrežu /56 ili /48, pa se broji mreža /48, ne pojedina adresa */
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { $b = @inet_pton($ip); if ($b !== false) $ip = bin2hex(substr($b, 0, 6)) . '::/48'; }
  /* tajna traje tjedan dana; prošlotjedna se čuva, da granice i „Storniraj sve s ove adrese” prepoznaju istu adresu
     i nakon zamjene tajne ($prev = true vraća oznaku po prošlotjednoj tajni, ili '' ako je nema) */
  $week = date('o-\\WW');
  $keys = (array)sj_with_lock(SJ_DATA . '/sol.json', function (array &$d) use ($week) {
    if (($d['day'] ?? '') !== $week || empty($d['key'])) $d = ['day' => $week, 'key' => bin2hex(random_bytes(32)), 'prev' => (string)($d['key'] ?? '')];
    return [(string)$d['key'], (string)($d['prev'] ?? '')];
  }, []);
  $key = $prev ? ($keys[1] ?? '') : ($keys[0] ?? '');
  if ($prev && $key === '') return '';
  return substr(hash_hmac('sha256', $ip, $key ?: $week), 0, 16);
}

/* Oznake ove adrese (trenutna i prošlotjedna) i provjera pripada li zapis (ip, ip_prev) istoj adresi */
function sj_short_ips(?string $ip = null): array { return array_values(array_filter([sj_short_ip($ip), sj_short_ip($ip, true)])); }
function sj_same_ip(array $rec, array $mine): bool {
  foreach (['ip', 'ip_prev'] as $k) if (!empty($rec[$k]) && in_array((string)$rec[$k], $mine, true)) return true;
  return false;
}

/* Sve granice broje skraćenu adresu (sj_short_ip: IPv4 adresa ili IPv6 mreža /48), pa promjena IPv6 adrese ne
   zaobilazi granicu i ne napuhuje datoteku. $short ostaje radi starih poziva i više ništa ne mijenja. */
/* $record = false: samo provjera, bez upisa (npr. narudžba se upisuje tek kad je zaprimljena, pa odbijeni
   pokušaji, 409 i slično, ne troše ograničenje); upis kasnije istim pozivom s $record = true */
function sj_rate_limit(string $key, int $max, int $windowSec, bool $short = false, bool $record = true): bool {
  $ip = sj_short_ip((string)($_SERVER['REMOTE_ADDR'] ?? '?'));
  $now = time();
  return (bool)sj_with_lock(SJ_DATA . '/zahtjevi.json', function (array &$d) use ($key, $ip, $max, $windowSec, $now, $record) {
    foreach ($d as $k => $times) {
      $keep = (str_starts_with($k, 'visit|') || str_starts_with($k, 'count|') || str_starts_with($k, 'sve|')) ? 3600 : 86400;
      $times = array_values(array_filter((array)$times, fn($t) => (int)$t > $now - $keep));
      if ($times) $d[$k] = $times; else unset($d[$k]);
    }
    /* gornja granica veličine: najviše 5000 ključeva. v20: zaštitni ključevi 'sve|' (ukupne granice i granice po
       primatelju) nikad se ne brišu; prvi ispadaju brojači posjeta i klikova ('visit|', 'count|'), zatim najstariji */
    if (count($d) >= 5000) {
      $keepAll = array_filter($d, fn($k) => str_starts_with((string)$k, 'sve|'), ARRAY_FILTER_USE_KEY);
      $rest = array_diff_key($d, $keepAll);
      uksort($rest, function ($a, $b) use ($rest) {
        $pa = (str_starts_with($a, 'visit|') || str_starts_with($a, 'count|')) ? 0 : 1;
        $pb = (str_starts_with($b, 'visit|') || str_starts_with($b, 'count|')) ? 0 : 1;
        return $pa !== $pb ? $pb <=> $pa : max((array)$rest[$b]) <=> max((array)$rest[$a]);
      });
      $d = $keepAll + array_slice($rest, 0, max(0, 4999 - count($keepAll)), true);
    }
    $k = $key . '|' . $ip;
    $recent = array_filter((array)($d[$k] ?? []), fn($t) => (int)$t > $now - $windowSec);
    if (count($recent) >= $max) return false;
    if (!$record) return true;
    $d[$k] = array_merge(array_values($recent), [$now]);
    return true;
  }, []);
}

/* Unikati koji su u katalogu na prodaji (nisu artikli po narudžbi, prodani, ručno rezervirani ni skriveni) */
function sj_free_unique_ids(array $prodsById): array {
  $out = [];
  /* v26: unikat bez fotografije se ne može naručiti, pa se ne broji u ponudu */
  foreach ($prodsById as $id => $p) if (empty($p['made']) && empty($p['sold']) && empty($p['reserved']) && !empty($p['imgs'])) $out[] = (string)$id;
  return $out;
}

/* Koje rezervacije blokiraju torbu za druge kupce (id => true): sve rezervacije iz zaprimljenih narudžbi, do isteka.
   Rezervacija iz košarice (bez narudžbe) nikad. Granica „pola ponude” vrijedi pri dodjeli NOVE rezervacije (api/order):
   tko je rezervaciju već dobio, zadržava je do isteka i kad se poslije proda neka druga torba (obećanje kupcu vrijedi).
   Kad zato neplaćene narudžbe drže više od pola, admin to pokazuje u Rezervacijama. $freeIds ostaje radi poziva. */
function sj_blocking_ids(array $d, array $freeIds): array {
  $out = [];
  foreach ((array)($d['res'] ?? []) as $id => $r) if (!empty($r['ordered'])) $out[(string)$id] = true;
  return $out;
}

/* Vidljivi proizvodi po id-u (za admin; isto što i sj_products_by_id u api/index.php) */
function sj_products_by_id_all(): array {
  $out = [];
  foreach (sj_products() as $p) if (!empty($p['id']) && empty($p['hidden'])) $out[(string)$p['id']] = $p;
  return $out;
}

/* Nova narudžba na vrh popisa; zadnjih $keep ostaje u narudzbe.json, starije idu u godišnju arhivu
   data/narudzbe-arhiva-GGGG.json (ne brišu se, pa lažne narudžbe ne mogu istisnuti prave) */
function sj_orders_prepend(array $entry, int $keep = 300): void {
  sj_with_lock(SJ_DATA . '/narudzbe.json', function (array &$list) use ($entry, $keep) {
    array_unshift($list, $entry);
    if (count($list) > $keep) {
      $old = array_slice($list, $keep); $list = array_slice($list, 0, $keep);
      sj_with_lock(SJ_DATA . '/narudzbe-arhiva-' . date('Y') . '.json', function (array &$a) use ($old) { foreach (array_reverse($old) as $o) $a[] = $o; return true; }, []);
    }
    return true;
  }, []);
}

/* Datoteke s narudžbama, od najnovije: narudzbe.json pa godišnje arhive (novija godina prva) */
function sj_order_files(): array {
  $arh = glob(SJ_DATA . '/narudzbe-arhiva-*.json') ?: [];
  rsort($arh);
  return array_merge([SJ_DATA . '/narudzbe.json'], $arh);
}

/* Prva narudžba po broju ili tokenu rezervacije, i u arhivi (za čitanje) */
function sj_order_find(array $by): ?array {
  foreach (sj_order_files() as $f) foreach (sj_read_json($f, []) as $o) if (sj_order_hit($o, $by)) return $o;
  return null;
}

function sj_order_hit(array $o, array $by): bool {
  return (isset($by['no']) && $by['no'] !== '' && ($o['no'] ?? '') === $by['no'])
      || (isset($by['token']) && $by['token'] !== '' && (($o['reservation']['token'] ?? '') === $by['token']));
}

/* Promjena prve pronađene narudžbe pod bravom njene datoteke; vraća false ako narudžbe nema */
function sj_order_edit(array $by, callable $fn): bool {
  foreach (sj_order_files() as $f) {
    if (!is_file($f)) continue;
    $hit = sj_with_lock($f, function (array &$list) use ($by, $fn) {
      foreach ($list as &$o) if (sj_order_hit($o, $by)) { $fn($o); return true; }
      return false;
    }, []);
    if ($hit) return true;
  }
  return false;
}

/* Narudžba se u brojač „plaćeno” upisuje najviše jednom: traži narudžbu po tokenu rezervacije ili broju
   (i u arhivi), upisuje paidAt (ako nema) i paidCounted; vraća true samo prvi put. */
/* v26: storno rezervacije ostaje zapisan uz narudžbu (Narudžbe prikazuju „stornirano”, ne „rezervacija istekla”) */
function sj_order_cancelled(string $token): void {
  if ($token !== '') sj_order_edit(['token' => $token], function (array &$o) { if (empty($o['cancelledAt'])) $o['cancelledAt'] = sj_iso(time()); });
}
/* v26: granica rezervacija iz neplaćenih narudžbi — pola slobodnih unikata, ali u maloj ponudi barem tri (inače
   već drugi kupac ostaje bez rezervacije); zaštitu od jednog napadača i dalje čine granice po e-mailu i adresi */
function sj_hold_cap(int $free): int {
  $min = max(0, (int)(sj_config()['limits']['hold_min'] ?? 3)); /* 0 = staro pravilo (samo pola) */
  return max(min($free, $min), $free >= 2 ? intdiv($free, 2) : 0);
}
/* v27: OIB — 11 znamenki i ispravna kontrolna znamenka (ISO 7064, MOD 11,10) */
function sj_oib_ok(string $oib): bool {
  if (!preg_match('/^\d{11}$/', $oib)) return false;
  $a = 10;
  for ($i = 0; $i < 10; $i++) { $a = ($a + (int)$oib[$i]) % 10; if ($a === 0) $a = 10; $a = ($a * 2) % 11; }
  return (11 - $a) % 10 === (int)$oib[10];
}
function sj_order_paid_once(array $by): bool {
  $first = false;
  sj_order_edit($by, function (array &$o) use (&$first) {
    if (empty($o['paidAt'])) $o['paidAt'] = sj_iso(time());
    if (empty($o['paidCounted'])) { $o['paidCounted'] = true; $first = true; }
  });
  return $first;
}

/* Rezervacija → oznaka „plaćeno” (isti zapis gradi admin i API) */
/* $manual = true (gumb „Ručno označi plaćeno”): prodaja izvan stranice, ne ulazi u brojač. Torbu s aktivnom
   rezervacijom kupca ručni način ne dira (vraća false): nju se označuje kod rezervacija ili prvo stornira.
   Već prodana torba se ne prepisuje. Vraća true kad je oznaka upisana. */
function sj_mark_paid(array &$d, string $id, bool $manual = false): bool {
  if (isset($d['sold'][$id])) return false;
  if ($manual && isset($d['res'][$id])) return false;
  $r = $manual ? ['id' => $id] : ($d['res'][$id] ?? ['id' => $id]);
  /* „plaćeno” se broji po narudžbi: sve torbe iste narudžbe nose isti token, pa se broji samo prva;
     oznaka paidCounted ostaje u zapisu narudžbe i kad se oznaka plaćeno ukloni pa vrati */
  $tok = (string)($r['token'] ?? '');
  if ($tok !== '' && sj_order_paid_once(['token' => $tok])) sj_count('paid');
  unset($d['res'][$id]);
  /* v28: broj narudžbe uz zapis plaćeno (potvrde i tablica u adminu) */
  $no = $tok !== '' ? (string)(sj_order_find(['token' => $tok])['no'] ?? '') : '';
  $d['sold'][$id] = ['id' => $id, 'token' => $tok, 'no' => $no, 'name' => $r['name'] ?? '', 'email' => $r['email'] ?? '', 'since' => $r['since'] ?? '', 'paidAt' => sj_iso(time())];
  return true;
}

/* v28: nazivi torbi za poruke („Luna”, „Tara”) umjesto oznaka */
function sj_product_names(array $ids): string {
  $n = []; foreach (sj_products() as $p) $n[(string)($p['id'] ?? '')] = (string)($p['name'] ?? '');
  return implode(', ', array_map(fn($i) => '„' . (($n[(string)$i] ?? '') !== '' ? $n[(string)$i] : (string)$i) . '”', $ids));
}

/* v28: kad narudžbi s tim tokenom / brojem više nijedna torba nije plaćena, ni narudžba nije plaćena */
function sj_order_unpaid_if_empty(array $sold, string $tok, string $no): void {
  foreach ($sold as $x) if (($tok !== '' && ($x['token'] ?? '') === $tok) || ($no !== '' && ($x['no'] ?? '') === $no)) return;
  if ($tok !== '' || $no !== '') sj_order_edit($no !== '' ? ['no' => $no] : ['token' => $tok], function (array &$o) { unset($o['paidAt']); });
}

/* Sve promjene postavki idu kroz bravu, jer i API (brojač narudžbi) i admin pišu istu datoteku.
   Nakon zapisa osvježava se i predmemorija sj_config(). */
function sj_config_update(callable $fn): array {
  $new = sj_with_lock(SJ_DATA . '/config.json', function (array &$d) use ($fn) {
    $saved = $d;
    $d = array_replace_recursive(sj_defaults(), $d);
    /* popisi (dostava, izvori) se ne spajaju po indeksu sa zadanima, inače bi obrisana opcija „uskrsnula” */
    foreach (['shipping', 'allowed_origins'] as $k) if (isset($saved[$k]) && is_array($saved[$k])) $d[$k] = array_values($saved[$k]);
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

/* Adresa stranice za linkove u e-mailovima: postavka site_url, a dok je prazna samo host iz zahtjeva koji je
   provjeren (domena adrese pošiljatelja, dopušteni izvori ili lokalno). Inače '' i linkovi se ne šalju, jer bi
   zaglavlje Host mogao podmetnuti bilo tko. */
function sj_site_url_trusted(): string {
  $c = sj_config();
  if (!empty($c['site_url'])) return rtrim($c['site_url'], '/');
  $host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
  if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) return '';
  $ok = in_array($host, ['localhost', '127.0.0.1'], true);
  $fromHost = strtolower((string)substr(strrchr((string)sj_mail_from(), '@') ?: '', 1));
  $bare = preg_replace('/^www\./', '', $host);
  if ($fromHost !== '' && ($bare === $fromHost || str_ends_with($bare, '.' . $fromHost))) $ok = true;
  foreach ((array)($c['allowed_origins'] ?? []) as $o) if (strtolower((string)parse_url((string)$o, PHP_URL_HOST)) === $host) $ok = true;
  return $ok ? sj_site_url() : '';
}

/* ---------- JSON datoteke ---------- */

function sj_read_json(string $path, $default) {
  if (!is_file($path)) return $default;
  $d = json_decode((string)file_get_contents($path), true);
  return is_array($d) ? $d : $default;
}

/* Piše preko privremene datoteke; ako zapis ne uspije (pun disk, neispravan JSON), postojeća datoteka ostaje
   netaknuta i baca se iznimka, umjesto da se zamijeni praznom ili krnjom. */
function sj_write_json(string $path, $data): void {
  $dir = dirname($path);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) throw new RuntimeException('Zapis nije uspio (neispravan sadržaj): ' . basename($path));
  $json .= "\n";
  $tmp = $path . '.tmp';
  $n = @file_put_contents($tmp, $json, LOCK_EX);
  if ($n !== strlen($json)) { @unlink($tmp); throw new RuntimeException('Zapis nije uspio (disk?): ' . basename($path)); }
  if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Zapis nije uspio (preimenovanje): ' . basename($path)); }
}

/* Zaključani rad nad JSON datotekom: $fn(array &$data) smije mijenjati $data; vraća što god želi. */
function sj_with_lock(string $path, callable $fn, $default = []) {
  $dir = dirname($path);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $fp = fopen($path . '.lock', 'c');
  flock($fp, LOCK_EX);
  try {
    $data = sj_read_json($path, $default);
    /* datoteka postoji i nije prazna, ali nije čitljiv JSON: čuva se kopija (ime.json.osteceno-datum), a postavke se
       ne prepisuju zadanima (izgubili bi se lozinka, brojač narudžbi, adresa pošiljatelja) */
    if (is_file($path) && filesize($path) > 0 && sj_read_json($path, null) === null) {
      $bak = $path . '.osteceno-' . date('Ymd');
      if (!is_file($bak)) @copy($path, $bak);
      if (basename($path) === 'config.json') throw new RuntimeException('data/config.json nije čitljiv; vratite ga iz sigurnosne kopije.');
    }
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
  sj_products_update(function (array &$cur) use ($list) { $cur = $list; });
}

/* Izmjena popisa proizvoda pod bravom: čitanje, izmjena i zapis (glavni popis i javni torbe.json) u istoj
   bravi, pa dvije istodobne izmjene u adminu (npr. dvije kartice) ne gube jedna drugu. $fn(array &$list)
   smije baciti iznimku: tada se ništa ne zapisuje. Vraća što vrati $fn. */
function sj_products_update(callable $fn) {
  sj_products(); /* prvi prijenos: uvoz iz torbe.json ako glavnog popisa još nema */
  return sj_with_lock(sj_products_path(), function (array &$list) use ($fn) {
    $list = array_values($list);
    $before = json_encode($list);
    $r = $fn($list);
    $list = array_values($list);
    if (json_encode($list) !== $before || !is_file(SJ_PUBLIC_JSON)) sj_write_public_products($list);
    return $r;
  }, []);
}

function sj_write_public_products(array $list): void {
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
  $now = time(); $n = 0; $seen = [];
  foreach ($d['res'] ?? [] as $id => $r) {
    if (strtotime($r['until'] ?? '') < $now) {
      unset($d['res'][$id]);
      /* „isteklo” se broji po narudžbi (isti token = ista narudžba), ne po torbi, i samo ako je narudžba
         stvarno zaprimljena (ordered); rezervacija bez narudžbe (kupac odustao, kvar) se ne broji */
      if (empty($r['ordered'])) continue;
      $tok = (string)($r['token'] ?? '');
      if ($tok === '' || !isset($seen[$tok])) { $n++; if ($tok !== '') $seen[$tok] = true; }
    }
  }
  if ($n) sj_count('expired', $n); /* poziva se samo unutar brave koja zapis i sprema */
}

/* ---------- anonimno mjerenje ----------
   data/brojac.json: dnevni zbrojevi bez ikakvih osobnih podataka (bez imena, e-maila, IP adrese):
   visits (otvaranja stranice), ig (od toga s Instagrama), orders, paid, expired (istekle neplaćene
   rezervacije), press (klikovi na članke u Radionici), rad (Radionica u vidnom polju), cart (prvo dodavanje u košaricu), copy (prvi „Kopiraj”), orders_m (narudžbe bez unikata). orders, paid i expired broje se po narudžbi (ne po torbi), pa je omjer B usporediv. Služi vlasnici da promjene na stranici mjeri prije/poslije. Čuva se 400 dana. */
function sj_count(string $key, int $n = 1): void {
  if (!in_array($key, ['visits', 'ig', 'orders', 'paid', 'expired', 'press', 'rad', 'cart', 'copy', 'orders_m'], true) || $n < 1) return;
  $day = (new DateTime('now', new DateTimeZone('Europe/Zagreb')))->format('Y-m-d');
  sj_with_lock(SJ_DATA . '/brojac.json', function (array &$d) use ($key, $n, $day) {
    $d[$day][$key] = (int)($d[$day][$key] ?? 0) + $n;
    if (count($d) > 400) { ksort($d); $d = array_slice($d, -400, null, true); }
    return true;
  }, []);
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

/* v20: sesija vrijedi samo uz lozinku s kojom je otvorena — promjena ili reset lozinke odjavljuje sve preglednike */
function sj_pw_mark(): string { return hash('sha256', 'sj-sesija|' . (string)(sj_config()['password_hash'] ?? '')); }
function sj_logged_in(): bool {
  sj_session_start();
  if (empty($_SESSION['sj_admin'])) return false;
  if (!hash_equals(sj_pw_mark(), (string)($_SESSION['sj_pw'] ?? ''))) { $_SESSION = []; return false; }
  return true;
}

/* v25: je li prijava s ove adrese u pauzi (8 promašaja, 15 min) — ekran tada javlja blokadu, ne „pogrešna lozinka” */
function sj_login_locked(): bool {
  $f = sj_config(true)['login_fails'][sj_short_ip()] ?? ['n' => 0, 't' => 0];
  return (int)$f['n'] >= 8 && time() - (int)$f['t'] < 900;
}
function sj_login_left(): int {
  $f = sj_config(true)['login_fails'][sj_short_ip()] ?? ['n' => 0, 't' => 0];
  return time() - (int)$f['t'] < 900 ? max(0, 8 - (int)$f['n']) : 8;
}
function sj_login(string $password): bool {
  $c = sj_config(true);
  $ip = sj_short_ip(); /* skraćena adresa: u postavkama ne ostaje prava adresa */
  if (sj_login_locked()) return false; /* 15 min pauze nakon 8 promašaja */
  /* ukupno za cijelu prijavu (napad s mnogo adresa): iznad 30 promašaja na sat svaki pokušaj čeka 3 s; prijava se ne
     zaključava potpuno, da napadač ne može vlasnicu zaključati izvan admina */
  $all = array_filter((array)($c['login_fails_all'] ?? []), fn($t) => (int)$t > time() - 3600);
  if (count($all) >= 30) sleep(3);
  if ($c['password_hash'] && password_verify($password, $c['password_hash'])) {
    sj_session_start();
    session_regenerate_id(true);
    $_SESSION['sj_admin'] = true;
    $_SESSION['sj_pw'] = hash('sha256', 'sj-sesija|' . $c['password_hash']);
    $_SESSION['sj_csrf'] = sj_token();
    sj_config_update(function (array &$d) use ($ip) { unset($d['login_fails'][$ip]); });
    return true;
  }
  usleep(400000);
  /* brojač promašaja raste unutar brave (paralelni pokušaji ga ne zaobilaze); zapisi stariji od dana se brišu */
  sj_config_update(function (array &$d) use ($ip) {
    $now = time();
    foreach ((array)($d['login_fails'] ?? []) as $k => $f) if ($now - (int)($f['t'] ?? 0) > 86400) unset($d['login_fails'][$k]);
    $f = $d['login_fails'][$ip] ?? ['n' => 0, 't' => 0];
    $d['login_fails'][$ip] = ['n' => (int)$f['n'] + 1, 't' => $now];
    $all = array_values(array_filter((array)($d['login_fails_all'] ?? []), fn($t) => (int)$t > $now - 3600));
    $all[] = $now; $d['login_fails_all'] = array_slice($all, -200);
  });
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

/* Očisti unos: bez kontrolnih znakova, ograničena duljina. Novi redovi ostaju samo uz $multiline
   (napomene, popis stavki); u jednorednim poljima (ime, e-mail, id, predmet) se uklanjaju, jer bi
   inače mogli ubaciti dodatna zaglavlja u e-mail. */
function sj_clean(?string $v, int $max, bool $multiline = false): string {
  $v = trim((string)$v);
  if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8'); /* neispravni bajtovi → ne ruši unos */
  $v = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u' : '/[\x00-\x1F\x7F]/u', $multiline ? '' : ' ', $v);
  if ($v === null) $v = '';
  return mb_substr($v, 0, $max);
}

/* e-mail za brojanje (granica „1 neplaćena narudžba po e-mailu”): mala slova, bez „+oznake”, a za Gmail bez točaka,
   jer sve te inačice stižu u isti sandučić */
function sj_email_key(string $email): string {
  $email = strtolower(trim($email));
  $at = strrpos($email, '@');
  if ($at === false) return $email;
  $local = substr($email, 0, $at); $dom = substr($email, $at + 1);
  $plus = strpos($local, '+'); if ($plus !== false) $local = substr($local, 0, $plus);
  if (in_array($dom, ['gmail.com', 'googlemail.com'], true)) { $local = str_replace('.', '', $local); $dom = 'gmail.com'; }
  return $local . '@' . $dom;
}

/* Ukupna granica za cijelu stranicu (npr. potvrde kupcima na sat), bez obzira na adresu. true = smije. */
/* $record = false: samo provjera, bez upisa (v20: granice se upisuju tek kad je radnja uspjela) */
function sj_global_limit(string $key, int $max, int $windowSec, bool $record = true): bool {
  $now = time();
  return (bool)sj_with_lock(SJ_DATA . '/zahtjevi.json', function (array &$d) use ($key, $max, $windowSec, $now, $record) {
    $k = 'sve|' . $key;
    $recent = array_values(array_filter((array)($d[$k] ?? []), fn($t) => (int)$t > $now - $windowSec));
    if (count($recent) >= $max) { $d[$k] = $recent; return false; }
    if (!$record) return true;
    $recent[] = $now; $d[$k] = $recent;
    return true;
  }, []);
}

function sj_valid_email(?string $v): ?string {
  $v = trim((string)$v);
  return ($v !== '' && strlen($v) <= 120 && filter_var($v, FILTER_VALIDATE_EMAIL)) ? $v : null;
}

/* poziv na broj za model HR00: broj narudžbe bez slovnog dijela (SJ-2026-0007 → 2026-0007);
   HR00 dopušta samo znamenke i crticu, najviše 22 znaka, bez kontrolnog broja */
function sj_payment_ref(string $no): string {
  $ref = preg_replace('/[^0-9-]/', '', $no) ?? '';
  return substr(trim($ref, '-'), 0, 22);
}

/* opis plaćanja za HUB-3 nalog: najviše 35 znakova, zato samo trgovina i broj narudžbe */
function sj_payment_desc(string $no): string { return mb_substr('Shiva.J ' . sj_payment_ref($no), 0, 35); }

/* „1 sat”, „24 sata”, „12 sati”, „48 sati” (kao hoursText na stranici) */
function sj_hours_text(int $n): string {
  $m10 = $n % 10; $m100 = $n % 100;
  $w = ($m10 === 1 && $m100 !== 11) ? 'sat' : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? 'sata' : 'sati');
  return $n . ' ' . $w;
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
function sj_send_mail(string $to, string $subject, string $text, ?string $attachmentPath = null, ?string $replyTo = null, ?string $attachmentName = null): bool {
  $c = sj_config();
  /* adrese u zaglavljima moraju biti ispravne e-mail adrese: bez novih redova, bez dodatnih zaglavlja */
  $to = sj_valid_email($to);
  $from = sj_valid_email(sj_mail_from());
  if ($to === null || $from === null) return false;
  $replyTo = sj_valid_email($replyTo);
  $subject = sj_clean($subject, 200);
  $shop = sj_clean((string)$c['shop'], 80);
  $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $fromHeader = '=?UTF-8?B?' . base64_encode($shop) . '?= <' . $from . '>';
  $boundary = 'sj-' . bin2hex(random_bytes(8));
  $headers = ["From: $fromHeader", "Reply-To: " . ($replyTo ?: $from), "Return-Path: <$from>", "MIME-Version: 1.0", "X-Mailer: Shiva.J"];

  /* v25: prikazno ime privitka (kupac vidi npr. barkod-za-uplatu-2026-0007.png, ne ime privremene datoteke) */
  $attName = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($attachmentName ?: ($attachmentPath ? basename($attachmentPath) : ''))) ?: 'privitak.png';
  if ($attachmentPath && is_file($attachmentPath)) {
    $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n";
    $body .= "--$boundary\r\nContent-Type: image/png; name=\"" . $attName . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $attName . "\"\r\n\r\n";
    $body .= chunk_split(base64_encode((string)file_get_contents($attachmentPath))) . "--$boundary--\r\n";
  } else {
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";
    $body = $text;
  }

  if (($c['mail_mode'] ?? 'mail') === 'log') {
    if (!is_dir(SJ_DATA)) mkdir(SJ_DATA, 0755, true);
    $entry = "=== " . date('c') . " | To: $to | Subject: $subject | Attachment: " . ($attachmentPath ? $attName : '-') . "\n" . $text . "\n\n";
    return (bool)file_put_contents(SJ_DATA . '/mail-log.txt', $entry, FILE_APPEND | LOCK_EX);
  }
  /* -f = adresa pošiljatelja na omotnici (Return-Path); bez nje poslužitelj šalje kao korisnik hostinga,
     pa provjere SPF/DMARC padaju i potvrda s IBAN-om završi u neželjenoj pošti */
  $sent = @mail($to, $encSubject, $body, implode("\r\n", $headers), '-f' . $from);
  if (!$sent) $sent = @mail($to, $encSubject, $body, implode("\r\n", $headers)); /* neki hostinzi ne dopuštaju -f */
  return $sent;
}
