<?php
/* =========================================================
   SHIVA.J — javni API na hostingu   (api/index.php v09)
     GET  api/status          rezervirane i prodane torbe, ttlHours,
                              načini dostave i podaci za uplatu (iz postavki)
     POST api/reserve         rezervacija torbi iz narudžbe (409 ako zauzeto)
     POST api/order           zaprimanje narudžbe: cijene i iznos računa
                              servis iz vlastitog popisa, sprema, e-mail
                              vlasnici, automatska potvrda kupcu s barkodom
     GET/POST api/storno      link iz e-maila vlasnici: Plaćeno / Storniraj /
                              Ukloni oznaku — radnje samo uz prijavu u admin
   v02: cijene i iznos se ne uzimaju iz preglednika; rezervirati se mogu
        samo stvarni, vidljivi, neprodani unikati; ograničenje broja
        zahtjeva po IP adresi; storno token ne ide kupcu; e-mail adrese
        se provjeravaju prije upisa u zaglavlja; veličina narudžbe ograničena.
   v03: poziv na broj = broj narudžbe (npr. SJ-2026-0007 → 2026-0007, model
        HR00) umjesto datuma uplate; odgovor servisa i potvrda kupcu ga nose.
   v09: narudžba sama rezervira unikate u istoj bravi (409 ako je torba prodana, ručno rezervirana ili je drži
        drugi kupac); api/reserve drži torbu najviše 2 sata, narudžba produljuje na puni rok; ključ narudžbe se
        provjerava i upisuje pod istom bravom, ponovljeni odgovor vraća stvarni ishod e-mailova; artikl po
        narudžbi označen nedostupnim se ne prima; e-mail kupcu: trajanje rezervacije s ispravnim oblikom
        („1 sat”, „48 sati”), jednina/množina torbi, preuzimanje jednom, povrat prema t. 6 Uvjeta.
   v08: idempotentna narudžba (_order.key), e-mail kupcu bez „sada je vaša”, pravo na raskid u tekstu,
        opis plaćanja do 35 znakova, preuzimanje „javit ćemo vam se”; e-mail vlasnici: uputa za Plaćeno.
   v07: orders_m = narudžbe bez unikata; brojač preskače prijavljenu vlasnicu; skraćena adresa
        u ograničenju zahtjeva brojača; e-mail kupcu bez „sve naše torbe postoje samo jednom”, preuzimanje.
   v06: status?c=rad|cart|copy, ograničenje zahtjeva na brojaču, orders_m (narudžbe bez unikata).
   v05: status?c=press broji klik na članak u Radionici (anonimno, samo zbroj).
   v04: status vraća rok slanja (shipLead); anonimni dnevni brojač (sj_count):
        api/status?v=1 broji otvaranje stranice (&src=ig s Instagrama), narudžbe.
   ========================================================= */
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';

$c = sj_config();
$a = (string)($_GET['a'] ?? '');
if ($a === '') {
  $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
  if (preg_match('#/(status|reserve|order|storno)/?$#', $path, $m)) $a = $m[1];
}

/* ---------- CORS: vlastita domena uvijek, dodatni izvori iz postavki ---------- */
function sj_origin_allowed(string $origin, array $c): bool {
  if ($origin === '') return true;
  $oh = (string)(parse_url($origin, PHP_URL_HOST) ?? '');
  $host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0];
  if ($oh !== '' && strcasecmp($oh, $host) === 0) return true;
  if (in_array($origin, $c['allowed_origins'] ?? [], true)) return true;
  if (in_array($host, ['localhost', '127.0.0.1'], true) && in_array($oh, ['localhost', '127.0.0.1'], true)) return true;
  return false;
}
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$originOk = sj_origin_allowed($origin, $c);
if ($origin !== '' && $originOk) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

const SJ_MAX_BODY = 32 * 1024;   /* najveće tijelo zahtjeva (narudžba s 6 torbi ima oko 2 KB) */
const SJ_MAX_ITEMS = 6;          /* najviše različitih stavki u jednoj narudžbi */
const SJ_MAX_QTY = 10;           /* najviše komada artikla po narudžbi */
const SJ_PREHOLD_HOURS = 2;      /* api/reserve: koliko sati torba čeka da stigne narudžba (zatim je drži puni rok) */

function sj_body_json(): ?array {
  $raw = (string)file_get_contents('php://input');
  if (strlen($raw) > SJ_MAX_BODY) sj_json_out(['ok' => false, 'error' => 'Narudžba je prevelika.'], 413);
  $d = json_decode($raw, true);
  return is_array($d) ? $d : null;
}

function sj_str($v, int $max, bool $multiline = false): string { return is_scalar($v) ? sj_clean((string)$v, $max, $multiline) : ''; }

/* opis plaćanja za HUB-3 nalog: najviše 35 znakova, zato samo trgovina i broj narudžbe */
function sj_payment_desc(string $no): string { return mb_substr('Shiva.J ' . sj_payment_ref($no), 0, 35); }

function sj_storno_url(string $token): string { return sj_site_url() . '/api/storno?token=' . $token; }

/* Popis proizvoda po id-u, bez skrivenih */
function sj_products_by_id(): array {
  $out = [];
  foreach (sj_products() as $p) if (!empty($p['id']) && empty($p['hidden'])) $out[(string)$p['id']] = $p;
  return $out;
}

/* ---------- status ---------- */
if ($a === 'status') {
  $owner = isset($_COOKIE['sjadmin']); /* prijavljena vlasnica: njezini posjeti i klikovi se ne broje */
  if (!$owner && ($_GET['v'] ?? '') === '1' && sj_rate_limit('visit', 30, 3600, true)) { sj_count('visits'); if (($_GET['src'] ?? '') === 'ig') sj_count('ig'); }
  $ck = (string)($_GET['c'] ?? '');
  if (!$owner && in_array($ck, ['press', 'rad', 'cart', 'copy'], true) && sj_rate_limit('count', 60, 3600, true)) sj_count($ck); /* drugi ključevi se ne primaju */
  $d = sj_res_read();
  $pay = $c['payment'];
  sj_json_out([
    'ok' => true,
    'ttlHours' => (int)$c['ttl_hours'],
    'ids' => array_map('strval', array_keys($d['res'])),
    'sold' => array_map('strval', array_keys($d['sold'])),
    'shipping' => sj_shipping(),
    'payment' => ['recipient' => (string)$pay['recipient'], 'address' => (string)$pay['address'], 'iban' => (string)$pay['iban'], 'model' => (string)$pay['model'], 'barcode' => (string)$pay['barcode']],
    'pickup' => (string)$c['pickup_info'],
    'ownerEmail' => (string)$c['owner_email'],
    'shipLead' => (string)($c['ship_lead'] ?? ''),
  ]);
}

/* ---------- reserve ---------- */
if ($a === 'reserve') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sj_json_out(['ok' => false, 'error' => 'POST only'], 405);
  if (!$originOk) sj_json_out(['ok' => false, 'error' => 'origin not allowed'], 403);
  $b = sj_body_json();
  $ids = [];
  foreach ((array)($b['ids'] ?? []) as $id) { $id = sj_str($id, 40); if ($id !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id)) $ids[$id] = true; }
  $ids = array_keys($ids);
  if (!$ids) sj_json_out(['ok' => false, 'error' => 'Nema torbi za rezervaciju.'], 400);
  if (count($ids) > SJ_MAX_ITEMS) sj_json_out(['ok' => false, 'error' => 'Najviše ' . SJ_MAX_ITEMS . ' torbi u jednoj narudžbi.'], 400);
  if (!sj_rate_limit('reserve', 20, 3600)) sj_json_out(['ok' => false, 'error' => 'Previše pokušaja. Pokušajte ponovno za sat vremena.'], 429);

  /* samo stvarni, vidljivi, neprodani unikati (artikli po narudžbi se ne rezerviraju) */
  $prods = sj_products_by_id();
  $unknown = []; $gone = [];
  foreach ($ids as $id) {
    $p = $prods[$id] ?? null;
    if (!$p || !empty($p['made'])) { $unknown[] = $id; continue; }
    if (!empty($p['sold']) || !empty($p['reserved'])) $gone[] = $id;
  }
  if ($unknown) sj_json_out(['ok' => false, 'error' => 'Nepoznat proizvod: ' . implode(', ', $unknown)], 400);
  if ($gone) sj_json_out(['ok' => false, 'taken' => $gone], 409);

  $ttl = max(1, (int)$c['ttl_hours']);
  $hold = min($ttl, SJ_PREHOLD_HOURS); /* prije narudžbe torba se drži kratko; tek zaprimljena narudžba je drži puni rok */
  $name = sj_str($b['name'] ?? '', 80);
  $email = sj_valid_email(sj_str($b['email'] ?? '', 120)) ?? '';

  $result = sj_with_lock(sj_res_path(), function (array &$d) use ($ids, $hold, $name, $email) {
    $d = array_replace(sj_res_default(), $d);
    sj_res_prune($d);
    $taken = [];
    foreach ($ids as $id) if (isset($d['res'][$id]) || isset($d['sold'][$id])) $taken[] = $id;
    if ($taken) return ['taken' => $taken];
    $token = sj_token();
    $now = time();
    $rec = ['token' => $token, 'name' => $name, 'email' => $email, 'since' => sj_iso($now), 'until' => sj_iso($now + $hold * 3600)];
    foreach ($ids as $id) $d['res'][$id] = $rec + ['id' => $id];
    return ['until' => $rec['until']];
  }, sj_res_default());

  if (isset($result['taken'])) sj_json_out(['ok' => false, 'taken' => $result['taken']], 409);
  /* token i storno link ostaju na poslužitelju; kupac dobiva samo rok */
  sj_json_out(['ok' => true, 'until' => $result['until'], 'ttlHours' => $ttl]);
}

/* ---------- order ---------- */
if ($a === 'order') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sj_json_out(['ok' => false, 'error' => 'POST only'], 405);
  if (!$originOk) sj_json_out(['ok' => false, 'error' => 'origin not allowed'], 403);
  $b = sj_body_json();
  if (!$b) sj_json_out(['ok' => false, 'error' => 'Neispravan zahtjev.'], 400);
  if (!empty($b['_gotcha']) || !empty($b['website'])) sj_json_out(['ok' => true]); /* bot je ispunio skriveno polje: tiho ignoriraj */
  if (!sj_rate_limit('order', 10, 3600)) sj_json_out(['ok' => false, 'error' => 'Previše narudžbi s ove adrese. Pokušajte ponovno za sat vremena.'], 429);

  $in = is_array($b['_order'] ?? null) ? $b['_order'] : [];
  $inCust = is_array($in['customer'] ?? null) ? $in['customer'] : [];
  $cust = [
    'name'   => sj_str($inCust['name'] ?? '', 80),
    'email'  => sj_valid_email(sj_str($inCust['email'] ?? '', 120)),
    'phone'  => sj_str($inCust['phone'] ?? '', 40),
    'street' => sj_str($inCust['street'] ?? '', 120),
    'city'   => sj_str($inCust['city'] ?? '', 80),
  ];
  if ($cust['name'] === '' || $cust['email'] === null) sj_json_out(['ok' => false, 'error' => 'Upišite ime i ispravnu e-mail adresu.'], 400);
  $note = sj_str($b['Napomena'] ?? ($in['note'] ?? ''), 500, true);
  if ($note === '—') $note = '';

  /* stavke: nazivi, cijene i količine iz vlastitog popisa, ne iz preglednika */
  $prods = sj_products_by_id();
  $items = []; $goods = 0.0; $uniqueIds = [];
  foreach ((array)($in['items'] ?? []) as $it) {
    if (!is_array($it)) continue;
    $id = sj_str($it['id'] ?? '', 40);
    $p = $prods[$id] ?? null;
    if (!$p) sj_json_out(['ok' => false, 'error' => 'Proizvod više nije u ponudi: ' . ($id !== '' ? $id : '?') . '. Osvježite stranicu.'], 409);
    $made = !empty($p['made']);
    if (!$made && (!empty($p['sold']) || !empty($p['reserved']))) sj_json_out(['ok' => false, 'taken' => [$id]], 409);
    if ($made && !empty($p['sold'])) sj_json_out(['ok' => false, 'error' => '„' . $p['name'] . '” trenutno nije dostupan za narudžbu. Uklonite ga iz košarice i pošaljite ostatak.'], 409);
    $qty = $made ? max(1, min(SJ_MAX_QTY, (int)($it['qty'] ?? 1))) : 1;
    $price = round((float)($p['price'] ?? 0), 2);
    $items[] = ['id' => $id, 'name' => sj_str($p['name'] ?? $id, 80), 'qty' => $qty, 'note' => $made ? sj_str($it['note'] ?? '', 120) : '',
                'price' => $price, 'made' => $made, 'lead' => $made ? preg_replace('/^\{.*\}$/s', '', sj_str($p['lead'] ?? '', 60)) : '']; /* nepopunjen {ROK} se ne šalje kupcu */
    $goods += $price * $qty;
    if (!$made) $uniqueIds[] = $id;
  }
  if (!$items) sj_json_out(['ok' => false, 'error' => 'Košarica je prazna.'], 400);
  if (count($items) > SJ_MAX_ITEMS) sj_json_out(['ok' => false, 'error' => 'Najviše ' . SJ_MAX_ITEMS . ' stavki u jednoj narudžbi.'], 400);

  /* dostava: opcija i cijena iz postavki */
  $inShip = is_array($in['shipping'] ?? null) ? $in['shipping'] : [];
  $shipId = sj_str($inShip['id'] ?? '', 40);
  $ship = null;
  foreach (sj_shipping() as $o) if ($o['id'] === $shipId) $ship = $o;
  $opts = sj_shipping();
  if ($opts && !$ship) sj_json_out(['ok' => false, 'error' => 'Odaberite način dostave.'], 400);
  $locker = ($ship && $ship['locker']) ? sj_str($inShip['locker'] ?? '', 160) : '';
  if ($ship && $ship['locker'] && $locker === '') sj_json_out(['ok' => false, 'error' => 'Odaberite ili upišite paketomat.'], 400);
  if ($ship && $ship['address'] && ($cust['street'] === '' || $cust['city'] === '')) sj_json_out(['ok' => false, 'error' => 'Upišite adresu za dostavu.'], 400);
  if (!$ship || !$ship['address']) { $cust['street'] = ''; $cust['city'] = ''; } /* ne čuvamo što ne treba */
  $shipPrice = $ship ? (float)$ship['price'] : 0.0;
  $total = round($goods + $shipPrice, 2);

  /* iznos koji je kupac vidio mora biti isti kao naš; inače tražimo da osvježi stranicu */
  $seen = is_numeric($in['total'] ?? null) ? round((float)$in['total'], 2) : null;
  if ($seen !== null && abs($seen - $total) > 0.005) sj_json_out(['ok' => false, 'error' => 'Cijene su se u međuvremenu promijenile. Osvježite stranicu i pošaljite narudžbu ponovno.'], 409);

  $key = sj_str($in['key'] ?? '', 64);
  if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $key)) $key = '';
  $ttl = max(1, (int)$c['ttl_hours']);
  $itemsText = implode("\n", array_map(fn($it) => '- ' . $it['name'] . ($it['qty'] > 1 ? ' × ' . $it['qty'] : '') . ($it['note'] !== '' ? ' (' . $it['note'] . ')' : '') . ' — ' . sj_fmt_eur($it['price'] * $it['qty']), $items));
  $shipText = $ship ? $ship['label'] . ' — ' . ($shipPrice > 0 ? sj_fmt_eur($shipPrice) : 'besplatno') . ($locker !== '' ? ' — paketomat: ' . $locker : '') : 'po dogovoru';

  /* Jedna narudžba u isto vrijeme (brava samo za narudžbe, uvijek prva, pa nema zastoja s drugim bravama):
     1) isti ključ iz preglednika (ponovni klik, prekid veze, osvježena stranica) → isti odgovor, bez nove narudžbe;
     2) unikati: prodani ili rezervirani za drugog kupca → 409; slobodni ili kupčevi → rezervirani puni rok;
     3) broj narudžbe i upis. E-mailovi idu nakon brave. */
  $done = sj_with_lock(SJ_DATA . '/narudzba-u-tijeku.json', function (array &$unused) use ($key, $uniqueIds, $cust, $ttl, $items, $ship, $shipPrice, $locker, $goods, $total, $note, $itemsText, $shipText) {
    if ($key !== '') foreach (sj_read_json(SJ_DATA . '/narudzbe.json', []) as $prev) if (($prev['key'] ?? '') === $key) return ['prev' => $prev];

    $res = null;
    if ($uniqueIds) {
      $r = sj_with_lock(sj_res_path(), function (array &$d) use ($uniqueIds, $cust, $ttl) {
        $d = array_replace(sj_res_default(), $d);
        sj_res_prune($d);
        $taken = []; $mine = []; $tok = ''; $since = '';
        foreach ($uniqueIds as $id) {
          $r = $d['res'][$id] ?? null;
          $same = $r && strcasecmp((string)($r['email'] ?? ''), $cust['email']) === 0;
          if (isset($d['sold'][$id]) || ($r && !$same)) $taken[] = $id;                 /* prodano ili drugi kupac */
          elseif ($r && !empty($r['ordered'])) $mine[(string)($r['token'] ?? '')] = $id; /* isti kupac, već u ranijoj narudžbi */
          elseif ($r && $tok === '') { $tok = (string)($r['token'] ?? ''); $since = (string)($r['since'] ?? ''); }
        }
        if ($taken) return ['taken' => $taken];
        if ($mine) return ['mine' => $mine];
        $now = time();
        $rec = ['token' => $tok !== '' ? $tok : sj_token(), 'name' => $cust['name'], 'email' => $cust['email'], 'since' => $since !== '' ? $since : sj_iso($now),
                'until' => sj_iso($now + $ttl * 3600), 'ordered' => true];
        foreach ($uniqueIds as $id) $d['res'][$id] = $rec + ['id' => $id];
        return ['token' => $rec['token'], 'until' => $rec['until']];
      }, sj_res_default());
      if (isset($r['taken']) || isset($r['mine'])) return $r;
      $res = $r;
    }

    $seq = 0;
    sj_config_update(function (array &$cfg) use (&$seq) {
      $cfg['order_seq'] = (int)($cfg['order_seq'] ?? 0) + 1;
      $seq = (int)$cfg['order_seq'];
    });
    $no = 'SJ-' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    $o = ['customer' => $cust, 'items' => $items, 'shipping' => $ship ? ['id' => $ship['id'], 'label' => $ship['label'], 'price' => $shipPrice, 'locker' => $locker] : null,
          'goods' => round($goods, 2), 'total' => $total, 'note' => $note, 'reservation' => $res ? ['until' => $res['until']] : null];
    $entry = ['no' => $no, 'key' => $key, 'at' => sj_iso(time()), 'name' => $cust['name'], 'email' => $cust['email'], 'phone' => $cust['phone'],
              'total' => sj_fmt_eur($total), 'items' => $itemsText, 'shipping' => $shipText, 'address' => trim($cust['street'] . ', ' . $cust['city'], ', '),
              'note' => $note, 'reservation' => $res ? ['until' => $res['until'], 'token' => $res['token']] : null, 'order' => $o];
    sj_with_lock(SJ_DATA . '/narudzbe.json', function (array &$list) use ($entry) {
      array_unshift($list, $entry);
      if (count($list) > 300) $list = array_slice($list, 0, 300);
      return true;
    }, []);
    return ['no' => $no, 'o' => $o, 'res' => $res];
  }, []);

  if (isset($done['taken'])) sj_json_out(['ok' => false, 'taken' => $done['taken']], 409);
  if (isset($done['mine'])) {
    /* torba je već u ranijoj narudžbi istog kupca (npr. prvi pokušaj je stigao, a kupac je zatim promijenio napomenu) */
    $prevNo = '';
    foreach (sj_read_json(SJ_DATA . '/narudzbe.json', []) as $prev) if (isset($done['mine'][(string)($prev['reservation']['token'] ?? '')])) { $prevNo = (string)$prev['no']; break; }
    $names = array_map(fn($id) => '„' . ($prods[$id]['name'] ?? $id) . '”', array_values($done['mine']));
    sj_json_out(['ok' => false, 'error' => 'Torba ' . implode(', ', $names) . ' već je u vašoj narudžbi' . ($prevNo !== '' ? ' ' . $prevNo : '') . '; podatke za uplatu poslali smo vam e-mailom. Ako želite nešto promijeniti, odgovorite na taj e-mail.'], 409);
  }
  if (isset($done['prev'])) {
    $prev = $done['prev'];
    $m = is_array($prev['mail'] ?? null) ? $prev['mail'] : [];
    sj_json_out(['ok' => true, 'orderNo' => $prev['no'], 'reference' => sj_payment_ref((string)$prev['no']), 'total' => (float)($prev['order']['total'] ?? 0),
                 'until' => $prev['reservation']['until'] ?? null, 'mailOwner' => $m['owner'] ?? true, 'mailCustomer' => $m['customer'] ?? true, 'repeated' => true]);
  }
  $no = (string)$done['no']; $o = $done['o']; $res = $done['res'];
  $ref = sj_payment_ref($no);
  $stornoUrl = $res ? sj_storno_url((string)$res['token']) : '';
  sj_count('orders');
  if (!$uniqueIds) sj_count('orders_m'); /* narudžba bez unikata (samo artikli po narudžbi) */

  /* e-mail vlasnici */
  $lines = ["Nova narudžba $no — " . $c['shop'], ""];
  $lines[] = 'Kupac: ' . $cust['name'];
  $lines[] = 'E-mail: ' . $cust['email'];
  if ($cust['phone'] !== '') $lines[] = 'Telefon: ' . $cust['phone'];
  if ($cust['street'] !== '') $lines[] = 'Adresa: ' . $cust['street'] . ', ' . $cust['city'];
  $lines[] = '';
  $lines[] = 'Naručeno:';
  $lines[] = $itemsText;
  $lines[] = 'Dostava: ' . $shipText;
  $lines[] = 'Ukupno: ' . sj_fmt_eur($total);
  if ($note !== '') { $lines[] = ''; $lines[] = 'Napomena: ' . $note; }
  $lines[] = '';
  $lines[] = 'Kupac je prihvatio Uvjete kupnje (narudžba s obvezom plaćanja).';
  if ($res) {
    $lines[] = 'Rezervacija vrijedi do: ' . sj_fmt_hr($res['until']);
    $lines[] = 'Kad stigne potvrda uplate, kliknite „Plaćeno” prije isteka rezervacije: kupcu smo obećali da torbu tada čuvamo i nakon isteka.';
    $lines[] = 'Plaćeno / storno rezervacije (uz prijavu u admin): ' . $stornoUrl;
  }
  if (!$uniqueIds) $lines[] = 'Narudžba bez unikata: kad kupac uplati, označite je kao plaćenu u adminu → Narudžbe (tako je Mjerenje broji).';
  if (($ship['id'] ?? '') === 'pickup') $lines[] = 'Preuzimanje u radionici: kad je narudžba spremna, javite se kupcu i dogovorite termin.';
  $lines[] = '';
  $lines[] = 'Narudžbe i rezervacije: ' . sj_site_url() . '/admin/';
  $subject = "Narudžba $no — " . $cust['name'] . ' — ' . implode(', ', array_map(fn($it) => $it['name'], $items));
  $sentOwner = sj_send_mail((string)$c['owner_email'], $subject, implode("\n", $lines), null, $cust['email']);

  /* automatska potvrda kupcu */
  $sentCust = false;
  if (!empty($c['confirm_customer'])) {
    $bar = SJ_ROOT . '/' . ltrim((string)($c['payment']['barcode'] ?? ''), '/');
    $text = sj_customer_mail($o, $no, $c, is_file($bar));
    $sentCust = sj_send_mail($cust['email'], $c['shop'] . " — potvrda narudžbe $no", $text, is_file($bar) ? $bar : null, (string)$c['owner_email']);
  }
  /* ishod e-mailova ostaje uz narudžbu, da ponovljeni zahtjev s istim ključem kaže istinu */
  sj_with_lock(SJ_DATA . '/narudzbe.json', function (array &$list) use ($no, $sentOwner, $sentCust) {
    foreach ($list as &$e) if (($e['no'] ?? '') === $no) { $e['mail'] = ['owner' => $sentOwner, 'customer' => $sentCust]; break; }
    return true;
  }, []);
  sj_json_out(['ok' => true, 'orderNo' => $no, 'reference' => $ref, 'total' => $total, 'until' => $res ? $res['until'] : null, 'mailOwner' => $sentOwner, 'mailCustomer' => $sentCust]);
}

/* ---------- storno / plaćeno (link iz e-maila vlasnici; radnje samo uz prijavu u admin) ---------- */
if ($a === 'storno') {
  $token = (string)(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
  $action = (string)($_POST['action'] ?? '');
  if (!preg_match('/^[a-f0-9]{32}$/', $token)) sj_html_page('Rezervacija', '<p>Nevažeći link.</p>', 400);

  $loggedIn = sj_logged_in();
  $adminUrl = sj_site_url() . '/admin/';
  $d = sj_res_read();
  $mine = array_filter($d['res'], fn($r) => ($r['token'] ?? '') === $token);
  $sold = array_filter($d['sold'], fn($r) => ($r['token'] ?? '') === $token);

  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$loggedIn) sj_html_page('Rezervacija', '<p>Za ovu radnju potrebna je prijava u admin.</p><p><a href="' . e($adminUrl) . '">Prijava u admin</a></p>', 403);
    if (!sj_check_csrf()) sj_html_page('Rezervacija', '<p>Obrazac je zastario. Otvorite link iz e-maila ponovno.</p>', 400);
    /* u bravi se radi samo nad zapisima koji nose ovaj token (rezervacija je u međuvremenu mogla isteći i pripasti drugom kupcu) */
    $done = sj_with_lock(sj_res_path(), function (array &$d) use ($action, $token) {
      $d = array_replace(sj_res_default(), $d);
      $ids = [];
      if ($action === 'release') { foreach ($d['res'] as $id => $r) if (($r['token'] ?? '') === $token) { unset($d['res'][$id]); $ids[] = $id; } }
      elseif ($action === 'sold') { foreach ($d['res'] as $id => $r) if (($r['token'] ?? '') === $token) { sj_mark_paid($d, (string)$id); $ids[] = $id; } }
      elseif ($action === 'unsold') { foreach ($d['sold'] as $id => $r) if (($r['token'] ?? '') === $token) { unset($d['sold'][$id]); $ids[] = $id; } }
      return $ids;
    }, sj_res_default());
    $list = $done ? '<b>' . e(implode(', ', $done)) . '</b>' : '';
    if ($action === 'release') sj_html_page('Rezervacija', $done ? "<p>Rezervacija je stornirana: $list. Torba je ponovno dostupna na stranici.</p>" : '<p>Rezervacija je već stornirana ili je istekla.</p>');
    if ($action === 'sold') sj_html_page('Rezervacija', $done ? "<p>Označeno kao plaćeno: $list. Na stranici piše „Prodano“ i kad rezervacija istekne. Torbu trajno označite prodanom u adminu.</p>" : '<p>Rezervacija je već stornirana ili je istekla. Ako je kupac ipak platio, torbu označite prodanom u adminu.</p>');
    if ($action === 'unsold') sj_html_page('Rezervacija', $done ? "<p>Oznaka „prodano“ uklonjena: $list.</p>" : '<p>Nema oznake za uklanjanje.</p>');
    sj_html_page('Rezervacija', '<p>Nepoznata radnja.</p>', 400);
  }

  $loginNote = $loggedIn ? '' : '<p class="muted">Gumbi rade tek nakon <a href="' . e($adminUrl) . '">prijave u admin</a>; nakon prijave otvorite ovaj link ponovno. Iste radnje imate i u adminu pod „Rezervacije“.</p>';
  $csrf = $loggedIn ? '<input type="hidden" name="csrf" value="' . e(sj_csrf()) . '">' : '';
  $dis = $loggedIn ? '' : ' disabled';
  if ($mine) {
    $rows = '';
    foreach ($mine as $r) $rows .= '<li><b>' . e($r['id']) . '</b> — ' . e($r['name']) . ' (' . e($r['email']) . '), rezervirano ' . e(sj_fmt_hr($r['since'])) . ', vrijedi do ' . e(sj_fmt_hr($r['until'])) . '</li>';
    sj_html_page('Rezervacija', '<p>Ova narudžba drži rezervaciju:</p><ul>' . $rows . '</ul>' . $loginNote . '
      <div class="row">
        <form method="post">' . $csrf . '<input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="sold"><button' . $dis . '>Plaćeno — torba ostaje prodana</button></form>
        <form method="post">' . $csrf . '<input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="release"><button class="alt"' . $dis . '>Storniraj — vrati u prodaju</button></form>
      </div>
      <p class="muted">„Plaćeno“ drži torbu kao prodanu i nakon isteka rezervacije. „Storniraj“ odmah vraća torbu u prodaju. Sve to vidite i u <a href="' . e($adminUrl) . '">adminu</a>.</p>');
  }
  if ($sold) {
    sj_html_page('Rezervacija', '<p>Označeno kao plaćeno / prodano: <b>' . e(implode(', ', array_keys($sold))) . '</b>.</p>' . $loginNote . '
      <form method="post">' . $csrf . '<input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="unsold"><button class="alt"' . $dis . '>Ukloni oznaku</button></form>
      <p class="muted">Uklanjanje oznake vraća torbu u prodaju ako u adminu nije označena prodanom.</p>');
  }
  sj_html_page('Rezervacija', '<p>Rezervacija je već stornirana ili je istekla. Ako je kupac ipak platio, torbu označite prodanom u adminu.</p>');
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found";
exit;

/* poziv na broj za model HR00: broj narudžbe bez slovnog dijela (SJ-2026-0007 → 2026-0007);
   HR00 dopušta samo znamenke i crticu, najviše 22 znaka, bez kontrolnog broja */
function sj_payment_ref(string $no): string {
  $ref = preg_replace('/[^0-9-]/', '', $no) ?? '';
  return substr(trim($ref, '-'), 0, 22);
}

/* ---------- potvrda kupcu (svi podaci su već provjereni na poslužitelju) ---------- */
function sj_customer_mail(array $o, string $no, array $c, bool $hasBar = true): string {
  $cust = $o['customer'] ?? [];
  $items = is_array($o['items'] ?? null) ? $o['items'] : [];
  $ship = is_array($o['shipping'] ?? null) ? $o['shipping'] : [];
  $res = is_array($o['reservation'] ?? null) ? $o['reservation'] : null;
  $names = []; $lines = []; $anyMade = false; $anyUnique = false; $leads = []; $nUnique = 0;
  foreach ($items as $it) {
    $q = max(1, (int)($it['qty'] ?? 1));
    $n = (string)($it['name'] ?? '');
    $names[] = $n . ($q > 1 ? " × $q" : '');
    $lines[] = '- ' . $n . ($q > 1 ? " × $q" : '') . (!empty($it['note']) ? ' (' . $it['note'] . ')' : '') . ' — ' . sj_fmt_eur((float)($it['price'] ?? 0) * $q);
    if (!empty($it['made'])) { $anyMade = true; if (!empty($it['lead'])) $leads[] = (string)$it['lead']; } else { $anyUnique = true; $nUnique++; }
  }
  $torbu = $nUnique > 1 ? 'torbe' : 'torbu';
  if ($ship) $lines[] = '- Dostava: ' . $ship['label'] . ' — ' . ((float)($ship['price'] ?? 0) > 0 ? sj_fmt_eur((float)$ship['price']) : 'besplatno');
  else $lines[] = '- Dostava: po dogovoru';
  $total = (float)($o['total'] ?? 0);
  $p = $c['payment'];
  $ibanPretty = trim(chunk_split(preg_replace('/\s+/', '', (string)$p['iban']) ?? '', 4, ' '));
  $torba = $nUnique > 1 ? 'Torbe su rezervirane za vas — svaka postoji samo u jednom primjerku i čeka vašu uplatu.' : 'Torba je rezervirana za vas — postoji samo u jednom primjerku i čeka vašu uplatu.';

  $t = [];
  $t[] = 'Dobar dan, ' . (string)($cust['name'] ?? '') . ',';
  $t[] = '';
  $t[] = "hvala vam na narudžbi br. $no!" . ($anyUnique && $res ? ' ' . $torba : ($anyMade && !$anyUnique ? ' Artikle izrađujemo po narudžbi, posebno za vas.' : ''));
  $t[] = '';
  $t[] = 'SAŽETAK NARUDŽBE';
  foreach ($lines as $l) $t[] = $l;
  $t[] = 'Ukupno za uplatu: ' . sj_fmt_eur($total);
  $t[] = '';
  $sid = (string)($ship['id'] ?? '');
  if (!empty($ship['locker'])) $t[] = 'Paketomat: ' . $ship['locker'] . '. Kod za preuzimanje dobit ćete SMS-om ili e-mailom od BOX NOW-a kad paket stigne.';
  elseif ($sid === 'pickup') $t[] = 'Preuzimanje u radionici: ' . rtrim((string)$c['pickup_info'], '.') . '.';
  elseif (!empty($cust['street'])) $t[] = 'Adresa za dostavu: ' . $cust['street'] . ', ' . ($cust['city'] ?? '') . '.';
  if (!empty($o['note'])) $t[] = 'Vaša napomena: ' . $o['note'];
  $t[] = '';
  $t[] = 'PODACI ZA UPLATU';
  $t[] = '- Primatelj: ' . $p['recipient'] . ', ' . $p['address'];
  $t[] = '- IBAN: ' . $ibanPretty;
  $t[] = '- Iznos: ' . sj_fmt_eur($total);
  $t[] = '- Model: ' . $p['model'];
  $t[] = '- Poziv na broj: ' . sj_payment_ref($no) . ' (broj vaše narudžbe)';
  $t[] = '- Opis plaćanja: ' . sj_payment_desc($no);
  $t[] = '';
  if ($hasBar) {
    $t[] = 'Najbrže: u aplikaciji svoje banke skenirajte barkod iz privitka. Primatelj i IBAN popune se sami, a vi upišite još iznos (' . sj_fmt_eur($total) . '), model ' . $p['model'] . ' i poziv na broj ' . sj_payment_ref($no) . '. Ako ovaj e-mail čitate na mobitelu, spremite sliku barkoda i učitajte je u aplikaciji banke, ako to nudi, ili podatke prepišite.';
    $t[] = '';
  }
  if ($res && !empty($res['until'])) {
    $t[] = 'Rezervacija vrijedi ' . sj_hours_text(max(1, (int)$c['ttl_hours'])) . ', do ' . sj_fmt_hr((string)$res['until']) . '. Molimo vas da uplatite odmah i da nam čim platite pošaljete potvrdu uplate (snimku zaslona) odgovorom na ovaj e-mail: ' . $torbu . ' tada čuvamo za vas i nakon isteka rezervacije, bez obzira na to kad banka proknjiži uplatu. Ako u tom roku ne primimo uplatu ni potvrdu, rezervacija automatski istječe i ' . ($nUnique > 1 ? 'torbe se ponovno nude' : 'torba se ponovno nudi') . ' drugim kupcima.';
    $t[] = '';
  } elseif ($anyUnique) {
    $t[] = 'Molimo vas da uplatite odmah i da nam pošaljete potvrdu uplate (snimku zaslona) odgovorom na ovaj e-mail; ' . $torbu . ' tada čuvamo za vas.';
    $t[] = '';
  }
  if ($anyMade) {
    $t[] = 'Rok izrade' . ($leads ? ' (' . implode(', ', array_unique($leads)) . ')' : '') . ' teče od primitka uplate. Artikle po vašim posebnim željama (boja, duljina, mjera) šijemo samo za vas, pa za njih ne vrijedi pravo na jednostrani raskid.';
    $t[] = '';
  }
  $pickup = (($o['shipping']['id'] ?? '') === 'pickup');
  /* zadani tekst preuzimanja već kaže „javit ćemo vam se… dogovoriti termin”; tada se ne ponavlja */
  $pickupCalls = stripos((string)$c['pickup_info'], 'javit') !== false;
  $t[] = 'Čim uplata bude vidljiva, ' . ($anyMade ? ($pickup ? 'krećemo s izradom, a gotove artikle pripremamo za preuzimanje u radionici.' : 'krećemo s izradom, a gotove artikle pažljivo pakiramo i šaljemo.') : ($pickup ? $torbu . ' pripremamo za preuzimanje u radionici.' : $torbu . ' pažljivo pakiramo i šaljemo.'))
    . ($pickup ? ($pickupCalls ? '' : ' Javit ćemo vam se kad bude spremno za preuzimanje i dogovoriti termin.') : ' Poslat ćemo vam poruku s potvrdom slanja.');
  $t[] = '';
  $what = !$anyMade ? ($nUnique > 1 ? 'Torbe' : 'Torbu') : (count($items) > 1 ? 'Artikle' : 'Artikl');
  $t[] = 'Ova poruka je potvrda vaše narudžbe. Ako se predomislite, ugovor možete jednostrano raskinuti u roku 14 dana od dana kad ste ' . ($pickup ? 'preuzeli narudžbu' : 'primili pošiljku') . ', bez navođenja razloga: dovoljna je nedvosmislena izjava e-mailom (obrazac je u Uvjetima kupnje, t. 6). ' . $what . ' vraćate u roku 14 dana od izjave, o svom trošku; uplaćeni iznos vraćamo u roku i na način iz t. 6 Uvjeta kupnje.' . ($anyMade ? '' : ' Za artikle rađene po vašim posebnim željama pravo na raskid ne vrijedi.') . ' Uvjeti kupnje: ' . sj_site_url() . '/#uvjeti';
  $t[] = 'Za bilo kakva pitanja odgovorite na ovaj e-mail ili nam se javite porukom na Instagramu (@shiva.j_handmade).';
  $t[] = '';
  $t[] = 'Srdačan pozdrav,';
  $t[] = $c['shop'];
  $t[] = 'Ručno šivane torbe iz Zaboka';
  return implode("\n", $t);
}

/* ---------- HTML stranica (storno) ---------- */
function sj_html_page(string $title, string $body, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  header('Referrer-Policy: no-referrer');
  echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' — Shiva.J</title>
<style>body{margin:0;background:#F6F6F3;color:#1B1A17;font:16px/1.6 system-ui,sans-serif}main{max-width:760px;margin:0 auto;padding:40px 20px}h1{font-weight:400;font-size:1.5rem;letter-spacing:.04em;border-bottom:1.5px dashed #2E4A3A;padding-bottom:12px}button{background:#2E4A3A;color:#F6F6F3;border:1.5px solid #2E4A3A;border-radius:2px;padding:9px 14px;font-size:.85rem;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}button.alt{background:none;color:#2E4A3A;border-style:dashed}button[disabled]{opacity:.45;cursor:not-allowed}.row{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.muted{color:#5C5A53;font-size:.85rem}a{color:#2E4A3A}</style></head><body><main><h1>' . e($title) . '</h1>' . $body . '</main></body></html>';
  exit;
}
