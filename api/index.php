<?php
/* =========================================================
   SHIVA.J — javni API na hostingu   (api/index.php v36)
     GET  api/status          rezervirane i prodane torbe, ttlHours,
                              načini dostave i podaci za uplatu (iz postavki)
     POST api/reserve         rezervacija torbi iz narudžbe (409 ako zauzeto)
     POST api/order           zaprimanje narudžbe: cijene i iznos računa
                              servis iz vlastitog popisa, sprema, e-mail
                              vlasnici, automatska potvrda kupcu s barkodom
     POST api/confirm         klik na link iz e-maila „Potvrdite narudžbu”
     GET/POST api/storno      link iz e-maila vlasnici: Plaćeno / Storniraj /
                              Ukloni oznaku — radnje samo uz prijavu u admin
   v02: cijene i iznos se ne uzimaju iz preglednika; rezervirati se mogu
        samo stvarni, vidljivi, neprodani unikati; ograničenje broja
        zahtjeva po IP adresi; storno token ne ide kupcu; e-mail adrese
        se provjeravaju prije upisa u zaglavlja; veličina narudžbe ograničena.
   v03: poziv na broj = broj narudžbe (npr. SJ-2026-0007 → 2026-0007, model
        HR00) umjesto datuma uplate; odgovor servisa i potvrda kupcu ga nose.
   v36 (stranica v68): svaka narudžba rezervira svoje torbe do roka za uplatu — ukinute granica „pola ponude”,
        narudžbe „bez rezervacije” i granice neplaćenih narudžbi po vezi (ostaju granice broja zahtjeva i potvrda
        e-mailom); preopterećenje odbija narudžbu; najviše 5 torbi i 10 artikala po narudžbi; besplatna dostava
        iznad praga (sj_ship_price); status: freeShip, limits, sizes, sizeAll; rokovi za uplatu (sj_deadline_tick:
        podsjetnik, rok istekao, automatski storno); potpis e-mailova iz Postavki; točan rok u potvrdi kupcu.
   v35: rok izrade za više artikala s nazivima (isti rok jednom); „javit ćemo vam se”
   v34: sj_clean uklanja Unicode prijelome reda i znakove smjera; granica po modelu ima vlastitu poruku (model, broj
        mjesta); otvorena narudžba nosi vrstu (kind); e-mail kupcu: izrada, rok i slanje u jednom odlomku, bez datuma
        rezervacije u uvodu, „izradu bez te želje”, „uplatite što prije”; e-mail vlasnici: PAŽNJA jednom, s nazivima
   v33: granica kolekcije po vezi broji i mjesta po modelu (jedna veza nikad sama ne popuni model); plaćena narudžba
        bez potvrde zauzima mjesto; Odbijene pamte vezu iz narudžbe; predmemorija madeLeft prati i arhivu; e-mail kupcu:
        rok uz naziv modela, rok slanja nakon izrade, potvrda želje kupca, nazivi spojeni s „i”; e-mail vlasnici: ŽELJA KUPCA,
        upozorenja na vrhu; tekst otvorene narudžbe kaže gdje su podaci za uplatu
   v32: isti proizvod dvaput u narudžbi spaja se u jednu stavku (granica mjesta), najviše 3 neplaćene narudžbe iz
        kolekcije s iste veze (zapis veze uz narudžbu), madeLeft u api/status iz predmemorije, kod greške (field: email,
        code: open_order) umjesto pogađanja po tekstu, nazivi torbi u e-mailu kupcu, povrat uplate kad unikat bez
        rezervacije uplati netko drugi, e-mail vlasnici „Popunjeno”
   v31: Stalna kolekcija — najviše 1 komad po modelu, jedna neplaćena narudžba iz kolekcije po e-mailu, brojanje
        jednim prolazom (bez nepotvrđenih), e-mail vlasnici „zauzeto n od m”; potvrda provjerava show_ostalo; storno link
        traži confirm=1 i bez prijave ne pokazuje kupca; odbijene: 30 dana, najviše 5 po vezi; brisanje starih slika
   v30: Stalna kolekcija — najviše made_limit (zadano 3) zaprimljenih narudžbi po modelu, dok vlasnica ne otvori
        nova mjesta; madeLeft u statusu; rubrika Ostalo samo uz show_ostalo; confirm vraća podatke za uplatu iz Postavki
   v29: ponovljeni klik na odbijeni link ne dodaje novi redak u „Odbijene” (isti e-mail i artikli unutar sata)
   v28: storno link „Ukloni oznaku” skida i plaćeno s narudžbe, uz potvrdu; odbijene narudžbe (ista veza) u
        data/odbijene.json (admin → Narudžbe); nazivi torbi u e-mailu vlasnici
   v27: neplaćene narudžbe s iste veze 2 → 6 (dijeljene mobilne/kućne mreže), odbijena potvrda javlja se vlasnici;
        storno link: potvrda kvačicom, nazivi torbi, broj narudžbe, „javite se kupcu”; rok slanja uz „Čim uplata…”
   v26: granica rezervacija u maloj ponudi barem tri (sj_hold_cap); istinit razlog kad torba ostane bez rezervacije;
        storno upisuje cancelledAt; poziv na broj u e-mailu vlasnici; granica potvrda po adresi 10
   v25: e-mail kupcu — potvrda i rok rezervacije na vrhu, „bez SJ-” uz poziv na broj, raskid istim riječima kao Uvjeti,
        OIB u potpisu (ako je upisan), privitak „barkod-za-uplatu-….png”; privremena slika briše se i kad slanje padne
   v24: e-mail kupcu nosi HUB-3 barkod narudžbe (iznos, model, poziv na broj, opis) kao privitak — PHP ga crta sam
        (admin/pdf417.php); bez GD-a ostaje opći barkod iz Postavki.
   v23: status javlja requireConfirm (stranica prilagođava tekst o potvrdi linkom); storno link ne dira trajno
        prodanu torbu; stranica storna ne smije se otvarati u okviru (X-Frame-Options, CSP).
   v22: odgovor potvrde (sj_order_view) nosi i napomenu stavke (boja, duljina) za ploču za uplatu.
   v21: e-mail „potvrdite narudžbu” kaže koliko link vrijedi („Link vrijedi 1 sat (do …)”), kao stranica.
   v20: po recenziji v43: preko ukupne granice e-mailova za potvrdu narudžba se prima bez e-maila i bez rezervacije
        (sj_order_overload, trgovina se ne zatvara); granica 2/h po paru adresa+primatelj i 5/h po primatelju; granice
        se upisuju tek kad je e-mail poslan.
   v19: po recenziji v40: e-mail za potvrdu ograničen po primatelju (2/h) i adresi (3/h), ukupno 200/h uz upozorenje
        vlasnici od 100/h (trgovina se ne zatvara); bez provjerene adrese stranice 503 + upozorenje (potvrda ne otpada
        tiho); tuđi e-mail ne briše tuđu nepotvrđenu narudžbu (do 3 po adresi); potvrda ponovno provjerava katalog,
        cijene i dostavu.
   v18: potvrda narudžbe e-mailom (require_confirm, zadano uključeno): api/order sprema nepotvrđenu narudžbu
        (data/narudzbe-na-cekanju.json, bez broja i rezervacije) i šalje kupcu link; api/confirm tek tada radi
        rezervaciju, broj, upis i e-mailove (sj_place_order / sj_finish_order). Nepotvrđene ne drže ništa, pa
        lažne narudžbe s tuđim e-mailom ne blokiraju ponudu. Odgovor nosi stavke i dostavu (ploča i na drugom uređaju).
   v17: rezervacija dodijeljena narudžbi vrijedi do isteka (obećanje kupcu); granica „pola ponude” vrijedi za nove
        rezervacije. Admin: „Plaćeno” za cijelu narudžbu, i za torbe bez rezervacije (tko prvi uplati).
   v16: granica „pola ponude” vrijedi stalno (sj_blocking_ids): blokiraju samo najstarije rezervacije iz narudžbi,
        do pola stvarno slobodnih; novije preko toga ne blokiraju (status, reserve, order); narudžba s dvije torbe
        rezervira koliko stane; brojanje samo torbi na prodaji.
   v15: granica „pola ponude” računa stvarno slobodne torbe (bez torbi označenih „Plaćeno”) i zaokružuje naniže;
        jedna torba smije se rezervirati samo ako barem jedna ostaje slobodna, pa napadač nikad ne drži više od pola.
   v14: granice nikad ne zatvaraju trgovinu: ukupna granica narudžbi na sat više ne odbija (samo upozorava
        vlasnicu); kad bi neplaćene narudžbe držale više od pola slobodnih unikata, narudžba se prima BEZ
        rezervacije (torbu dobiva tko prvi uplati), pa napadač nikad ne drži više od pola ponude; ponovljeni
        ključ narudžbe provjerava se prije svih granica; granice po adresi prepoznaju i prošlotjednu oznaku.
   v13: IPv6 po mreži /48; sve granice po skraćenoj adresi; najviše 6 novih neplaćenih narudžbi sa šarenom
        torbom na sat za cijelu stranicu i 3 automatske potvrde na sat po adresi (limits u config.json);
        odgovor „već je u vašoj narudžbi” bez broja narudžbe.
   v12: blokira samo zaprimljena narudžba: rezervacija iz košarice (bez narudžbe) ne blokira druge kupce ni na
        stranici ni pri narudžbi (prva zaprimljena narudžba dobiva torbu); IPv6 se broji po mreži /64, tajna za
        skraćenu adresu traje tjedan; granica po e-mailu broji normaliziran e-mail (sj_email_key); najviše 30
        automatskih potvrda kupcima na sat za cijelu stranicu; starije narudžbe idu u arhivu umjesto brisanja.
   v11: zaštita ponude od sabotaže: najviše 2 unikata po narudžbi, 1 neplaćena narudžba s unikatom po e-mailu,
        2 po skraćenoj adresi; kad neplaćene narudžbe drže više od pola slobodnih unikata, nova narudžba drži
        torbu samo 2 sata i vlasnica dobiva upozorenje; api/reserve više nema ukupne granice (pravi kupac nikad
        nije blokiran tuđim rezervacijama).
   v10: neuhvaćena greška → JSON 500 i zapis u data/greske.log (bez osobnih podataka); api/reserve vraća
        tajni ključ rezervacije (hold), pa narudžba s ispravljenim e-mailom ne blokira vlastitu torbu;
        najviše 2 nenaručene rezervacije po skraćenoj adresi i najviše pola slobodnih unikata u nenaručenim
        rezervacijama; ograničenje narudžbi broji samo zaprimljene; rezervacija se vraća ako upis narudžbe
        ne uspije; linkovi u e-mailovima samo s provjerenom adresom stranice (sj_site_url_trusted).
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
require __DIR__ . '/../admin/pdf417.php';

/* greške ne smiju pokvariti JSON odgovor ni otkriti putanje; neuhvaćena iznimka → 500 i kratki zapis u log */
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
  @file_put_contents(SJ_DATA . '/greske.log', gmdate('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND | LOCK_EX);
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
  }
  echo json_encode(['ok' => false, 'error' => 'Narudžba trenutno nije moguća zbog greške na poslužitelju. Pokušajte ponovno za nekoliko minuta ili nam pišite e-mailom.'], JSON_UNESCAPED_UNICODE);
});

$c = sj_config();
$a = (string)($_GET['a'] ?? '');
if ($a === '') {
  $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
  if (preg_match('#/(status|reserve|order|confirm|storno)/?$#', $path, $m)) $a = $m[1];
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
const SJ_MAX_ITEMS = 10;         /* najviše različitih stavki u jednoj narudžbi (v68: 10 artikala ukupno) */
const SJ_MAX_QTY = 10;           /* najviše komada artikla po narudžbi */
const SJ_PREHOLD_HOURS = 2;      /* api/reserve: koliko sati torba čeka da stigne narudžba (zatim je drži puni rok) */
/* granice protiv „zaključavanja” ponude; zadano 2, mogu se promijeniti u data/config.json pod "limits" */
function sj_limit(string $k, int $def): int { $l = sj_config()['limits'] ?? []; return max(1, (int)($l[$k] ?? $def)); }
define('SJ_HOLDS_PER_IP', sj_limit('holds_per_ip', 2));             /* nenaručene rezervacije s iste skraćene adrese */
define('SJ_MAX_UNIQUE_PER_ORDER', sj_limit('unique_per_order', 2)); /* šarene torbe (unikati) u jednoj narudžbi */
/* v68: granice po vezi za neplaćene narudžbe su ukinute (odluka vlasnice: smije se naručiti s iste adrese); ostaju
   granice broja zahtjeva (sj_rate_limit), potvrda e-mailom i granice po narudžbi */
define('SJ_MAX_BAGS', sj_limit('bags_per_order', 5));               /* v68: torbi (unikati i Stalna kolekcija) u jednoj narudžbi */
define('SJ_MAX_ARTICLES', sj_limit('articles_per_order', 10));      /* v68: artikala ukupno (s količinama) u jednoj narudžbi */
define('SJ_UNIQUE_ORDERS_PER_HOUR', sj_limit('unique_orders_per_hour', 6)); /* nove narudžbe sa šarenom torbom na sat, ukupno */
define('SJ_CONFIRM_PER_IP', sj_limit('confirm_per_ip', 10));         /* automatske potvrde kupcima na sat s iste adrese */
define('SJ_CONFIRM_TOTAL', sj_limit('confirm_total', 200));        /* e-mailovi za potvrdu na sat ukupno (krajnja zaštita) */

/* v68: poruka o granicama narudžbe (ista na stranici) */
function sj_limits_text(): string { return 'U jednoj narudžbi može biti najviše ' . SJ_MAX_BAGS . ' torbi i ' . SJ_MAX_ARTICLES . ' artikala ukupno, od toga ' . (SJ_MAX_UNIQUE_PER_ORDER === 1 ? 'jedna unikatna torba.' : SJ_MAX_UNIQUE_PER_ORDER . ' unikatne torbe.'); }
function sj_unique_words(int $n): string { return $n === 1 ? 'može biti najviše jedna unikatna torba' : ($n <= 4 ? 'mogu biti najviše ' . [2 => 'dvije', 3 => 'tri', 4 => 'četiri'][$n] . ' unikatne torbe' : 'može biti najviše ' . $n . ' unikatnih torbi'); }

/* odgovor za ponovljeni zahtjev s istim ključem narudžbe (ista narudžba, bez nove) */
function sj_order_repeat_out(array $prev): void {
  $m = is_array($prev['mail'] ?? null) ? $prev['mail'] : [];
  sj_json_out(['ok' => true, 'orderNo' => $prev['no'], 'reference' => sj_payment_ref((string)$prev['no']), 'total' => (float)($prev['order']['total'] ?? 0),
               'until' => $prev['reservation']['until'] ?? null, 'payUntil' => sj_pay_until($prev), 'reserved' => true, 'mailOwner' => $m['owner'] ?? true, 'mailCustomer' => $m['customer'] ?? true, 'repeated' => true] + sj_pay_out() + sj_order_view((array)($prev['order'] ?? [])));
}
/* v62: podaci za uplatu iz Postavki uz odgovor narudžbe / potvrde */
function sj_pay_out(): array {
  $c = sj_config(); $p = (array)$c['payment'];
  return ['payment' => ['recipient' => (string)($p['recipient'] ?? ''), 'address' => (string)($p['address'] ?? ''), 'iban' => (string)($p['iban'] ?? ''), 'model' => (string)($p['model'] ?? ''), 'barcode' => (string)($p['barcode'] ?? '')], 'ownerEmail' => (string)$c['owner_email']];
}

function sj_body_json(): ?array {
  $raw = (string)file_get_contents('php://input');
  if (strlen($raw) > SJ_MAX_BODY) sj_json_out(['ok' => false, 'error' => 'Narudžba je prevelika.'], 413);
  $d = json_decode($raw, true);
  return is_array($d) ? $d : null;
}

function sj_str($v, int $max, bool $multiline = false): string { return is_scalar($v) ? sj_clean((string)$v, $max, $multiline) : ''; }


function sj_storno_url(string $token): string { $u = sj_site_url_trusted(); return $u !== '' ? $u . '/api/storno?token=' . $token : ''; }

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
  /* nepotvrđene narudžbe (osobni podaci) brišu se dan nakon isteka linka; provjera najviše jednom na sat */
  $pf = sj_pending_path();
  if (is_file($pf) && filemtime($pf) < time() - 3600) sj_pending_prune();
  /* v68: podsjetnici, „rok istekao” i automatski storno (najviše jednom u 5 minuta); greška ne smije srušiti stranicu */
  try { sj_deadline_tick(); } catch (Throwable $e) { @file_put_contents(SJ_DATA . '/greske.log', gmdate('c') . ' rokovi: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX); }
  $d = sj_res_read();
  $pay = $c['payment'];
  sj_json_out([
    'ok' => true,
    'ttlHours' => (int)$c['ttl_hours'],
    /* kao „Rezervirano” na stranici samo torbe iz zaprimljenih narudžbi; tuđa košarica ne zatvara torbu drugima */
    'ids' => array_map('strval', array_keys(sj_blocking_ids($d, sj_free_unique_ids(sj_products_by_id())))),
    'sold' => array_map('strval', array_keys($d['sold'])),
    'shipping' => sj_shipping(),
    'freeShip' => sj_free_ship(), /* v68: prag besplatne dostave i načini na koje vrijedi */
    'sizes' => sj_sizes(), 'sizeAll' => (string)($c['size_all'] ?? ''), /* v68: blok „Veličina i nošenje” */
    'limits' => ['bags' => SJ_MAX_BAGS, 'articles' => SJ_MAX_ARTICLES, 'unique' => SJ_MAX_UNIQUE_PER_ORDER], /* v68 */
    'payment' => ['recipient' => (string)$pay['recipient'], 'address' => (string)$pay['address'], 'iban' => (string)$pay['iban'], 'model' => (string)$pay['model'], 'barcode' => (string)$pay['barcode']],
    'pickup' => (string)$c['pickup_info'],
    'ownerEmail' => (string)$c['owner_email'],
    'shipLead' => (string)($c['ship_lead'] ?? ''),
    'requireConfirm' => !empty($c['require_confirm']),
    'showOstalo' => !empty($c['show_ostalo']),
    'madeLeft' => sj_status_made_left(),
  ]);
}

/* v64: preostala mjesta za api/status — predmemorija dok se narudžbe i proizvodi ne promijene (najviše 60 s), da česti
   pozivi stranice ne čitaju svaki put sve narudžbe i arhive */
function sj_status_made_left(): object {
  clearstatcache();
  $f = fn($n) => is_file(SJ_DATA . '/' . $n) ? filemtime(SJ_DATA . '/' . $n) . ':' . filesize(SJ_DATA . '/' . $n) : '-';
  $key = $f('narudzbe.json') . '|' . $f('proizvodi.json') . '|' . $f('narudzbe-arhiva-' . date('Y') . '.json') . '|' . date('Y'); /* v65: i arhiva tekuće godine */
  $cf = SJ_DATA . '/tmp/made-left.json';
  $c = is_file($cf) ? json_decode((string)@file_get_contents($cf), true) : null;
  if (is_array($c) && ($c['k'] ?? '') === $key && (int)($c['t'] ?? 0) > time() - 60 && is_array($c['v'] ?? null)) return (object)$c['v'];
  $v = array_map(fn($p) => sj_made_left($p), array_filter(sj_products_by_id(), fn($p) => sj_is_collection($p) && empty($p['sold'])));
  if (!is_dir(SJ_DATA . '/tmp')) @mkdir(SJ_DATA . '/tmp', 0755, true);
  @file_put_contents($cf . '.' . getmypid(), json_encode(['k' => $key, 't' => time(), 'v' => $v])) && @rename($cf . '.' . getmypid(), $cf);
  return (object)$v;
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

  $ip = sj_short_ip(); $ipPrev = sj_short_ip(null, true); $myIps = sj_short_ips();
  $freeIds = sj_free_unique_ids($prods);
  $result = sj_with_lock(sj_res_path(), function (array &$d) use ($ids, $hold, $name, $email, $ip, $ipPrev, $myIps, $freeIds) {
    $d = array_replace(sj_res_default(), $d);
    sj_res_prune($d);
    /* zauzeto je samo prodano ili zaprimljena narudžba; tuđa rezervacija iz košarice (bez narudžbe) se prepisuje,
       jer torbu na kraju dobiva prva zaprimljena narudžba */
    $taken = [];
    $blocking = sj_blocking_ids($d, $freeIds);
    foreach ($ids as $id) if (isset($d['sold'][$id]) || isset($blocking[$id])) $taken[] = $id;
    if ($taken) return ['taken' => $taken];
    foreach ($ids as $id) if (!empty($d['res'][$id]['ordered'])) return ['nohold' => true]; /* torba je u tuđoj neplaćenoj narudžbi koja je ne blokira: ne prepisujemo je; narudžba odlučuje */
    /* zaštita od zatrpavanja: rezervacije bez narudžbe po adresi (ne blokiraju druge, ali ne smiju se gomilati) */
    $mineTok = [];
    foreach ($d['res'] as $r) if (empty($r['ordered']) && sj_same_ip($r, $myIps)) $mineTok[(string)($r['token'] ?? '')] = true;
    if (count($mineTok) >= SJ_HOLDS_PER_IP) return ['busy' => 'Već imate otvorenu rezervaciju. Pošaljite tu narudžbu ili pričekajte da istekne (najviše ' . SJ_PREHOLD_HOURS . ' sata).'];
    $now = time();
    $rec = ['token' => sj_token(), 'hold' => sj_token(), 'ip' => $ip, 'ip_prev' => $ipPrev, 'name' => $name, 'email' => $email, 'since' => sj_iso($now), 'until' => sj_iso($now + $hold * 3600)];
    foreach ($ids as $id) $d['res'][$id] = $rec + ['id' => $id];
    return ['until' => $rec['until'], 'hold' => $rec['hold']];
  }, sj_res_default());

  if (isset($result['taken'])) sj_json_out(['ok' => false, 'taken' => $result['taken']], 409);
  if (isset($result['nohold'])) sj_json_out(['ok' => false, 'error' => 'Ova torba je u drugoj narudžbi koja čeka uplatu; možete je naručiti, a dobiva je tko prvi uplati.'], 429); /* stranica na 429 ipak šalje narudžbu */
  if (isset($result['busy'])) sj_json_out(['ok' => false, 'error' => $result['busy']], 429);
  /* token i storno link ostaju na poslužitelju; kupac dobiva rok i tajni ključ rezervacije (hold), kojim
     narudžba dokazuje da je rezervacija njegova i kad ispravi e-mail */
  sj_json_out(['ok' => true, 'until' => $result['until'], 'hold' => $result['hold'], 'ttlHours' => $ttl]);
}

/* ---------- order ---------- */
if ($a === 'order') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sj_json_out(['ok' => false, 'error' => 'POST only'], 405);
  if (!$originOk) sj_json_out(['ok' => false, 'error' => 'origin not allowed'], 403);
  $b = sj_body_json();
  if (!$b) sj_json_out(['ok' => false, 'error' => 'Neispravan zahtjev.'], 400);
  if (!empty($b['_gotcha']) || !empty($b['website'])) sj_json_out(['ok' => true]); /* bot je ispunio skriveno polje: tiho ignoriraj */
  /* ponovljeni zahtjev (prekid veze, osvježena stranica) uvijek dobiva svoju narudžbu, prije svih granica */
  $earlyKey = is_array($b['_order'] ?? null) ? sj_str($b['_order']['key'] ?? '', 64) : '';
  if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $earlyKey)) foreach (sj_read_json(SJ_DATA . '/narudzbe.json', []) as $prev) if (($prev['key'] ?? '') === $earlyKey) sj_order_repeat_out($prev);
  if (!sj_rate_limit('order', 10, 3600, false, false)) sj_json_out(['ok' => false, 'error' => 'Previše narudžbi s ove adrese. Pokušajte ponovno za sat vremena.'], 429);

  $in = is_array($b['_order'] ?? null) ? $b['_order'] : [];
  $inCust = is_array($in['customer'] ?? null) ? $in['customer'] : [];
  $cust = [
    'name'   => sj_str($inCust['name'] ?? '', 80),
    'email'  => sj_valid_email(sj_str($inCust['email'] ?? '', 120)),
    'phone'  => sj_str($inCust['phone'] ?? '', 40),
    'street' => sj_str($inCust['street'] ?? '', 120),
    'city'   => sj_str($inCust['city'] ?? '', 80),
  ];
  if ($cust['name'] === '' || $cust['email'] === null) sj_json_out(['ok' => false, 'error' => 'Upišite ime i ispravnu e-mail adresu.', 'field' => 'email'], 400);
  $note = sj_str($b['Napomena'] ?? ($in['note'] ?? ''), 500, true);
  if ($note === '—') $note = '';

  /* stavke: nazivi, cijene i količine iz vlastitog popisa, ne iz preglednika */
  $prods = sj_products_by_id();
  $items = []; $goods = 0.0; $uniqueIds = []; $seen = [];
  foreach ((array)($in['items'] ?? []) as $it) {
    if (!is_array($it)) continue;
    $id = sj_str($it['id'] ?? '', 40);
    $p = $prods[$id] ?? null;
    if (!$p) sj_json_out(['ok' => false, 'error' => 'Proizvod više nije u ponudi: ' . ($id !== '' ? $id : '?') . '. Osvježite stranicu.'], 409);
    $made = !empty($p['made']);
    /* v62: rubrika Ostalo je isključena — njezini artikli se ne naručuju */
    if (($p['cat'] ?? 'torbe') === 'ostalo' && empty($c['show_ostalo'])) sj_json_out(['ok' => false, 'error' => '„' . $p['name'] . '” trenutno nije u ponudi. Uklonite ga iz košarice i pošaljite ostatak.'], 409);
    if (!$made && (!empty($p['sold']) || !empty($p['reserved']))) sj_json_out(['ok' => false, 'taken' => [$id]], 409);
    if ($made && !empty($p['sold'])) sj_json_out(['ok' => false, 'error' => '„' . $p['name'] . '” trenutno nije dostupan za narudžbu. Uklonite ga iz košarice i pošaljite ostatak.'], 409);
    /* v63: model iz Stalne kolekcije najviše 1 komad po narudžbi (za više komada kupac se javlja) */
    $qty = $made ? (sj_is_collection($p) ? 1 : max(1, min(SJ_MAX_QTY, (int)($it['qty'] ?? 1)))) : 1;
    if (sj_is_collection($p) && sj_made_left($p) < 1) sj_json_out(['ok' => false, 'error' => 'Model „' . $p['name'] . '” je trenutno popunjen (ograničen broj narudžbi po modelu). Javite nam se e-mailom ili na Instagramu pa vam napišemo kad otvorimo nova mjesta.'], 409);
    $price = round((float)($p['price'] ?? 0), 2);
    /* v64: isti proizvod dvaput u jednoj narudžbi — spaja se u jednu stavku (inače bi jedna narudžba zauzela više
       mjesta u Stalnoj kolekciji ili zaobišla 1 komad po modelu); Ostalo zbraja količinu do SJ_MAX_QTY */
    if (isset($seen[$id])) {
      $k = $seen[$id];
      if ($made && !sj_is_collection($p)) { $add = max(0, min(SJ_MAX_QTY - $items[$k]['qty'], $qty)); $items[$k]['qty'] += $add; $goods += $price * $add; }
      continue;
    }
    $seen[$id] = count($items);
    $items[] = ['id' => $id, 'name' => sj_str($p['name'] ?? $id, 80), 'qty' => $qty, 'note' => $made ? sj_str($it['note'] ?? '', 120) : '',
                'price' => $price, 'made' => $made, 'lead' => $made ? preg_replace('/^\{.*\}$/s', '', sj_str($p['lead'] ?? '', 60)) : '']; /* nepopunjen {ROK} se ne šalje kupcu */
    $goods += $price * $qty;
    if (!$made) $uniqueIds[] = $id;
  }
  if (!$items) sj_json_out(['ok' => false, 'error' => 'Košarica je prazna.'], 400);
  if (count($items) > SJ_MAX_ITEMS) sj_json_out(['ok' => false, 'error' => 'Najviše ' . SJ_MAX_ITEMS . ' stavki u jednoj narudžbi.'], 400);
  if (count($uniqueIds) > SJ_MAX_UNIQUE_PER_ORDER) sj_json_out(['ok' => false, 'error' => 'U jednoj narudžbi ' . sj_unique_words(SJ_MAX_UNIQUE_PER_ORDER) . '. Za više nam se javite e-mailom ili na Instagramu.'], 400);
  /* v68: najviše SJ_MAX_BAGS torbi i SJ_MAX_ARTICLES artikala (s količinama) u jednoj narudžbi */
  $nBags = 0; $nArt = 0;
  foreach ($items as $it) { $nArt += (int)$it['qty']; if (($prods[$it['id']]['cat'] ?? 'torbe') === 'torbe') $nBags += (int)$it['qty']; }
  if ($nBags > SJ_MAX_BAGS || $nArt > SJ_MAX_ARTICLES) sj_json_out(['ok' => false, 'error' => sj_limits_text() . ' Za više nam se javite e-mailom ili na Instagramu, rado ćemo dogovoriti.'], 400);

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
  $shipPrice = $ship ? sj_ship_price($ship, $goods) : 0.0; /* v68: besplatna dostava iznad praga (samo odabrani načini) */
  $total = round($goods + $shipPrice, 2);

  /* iznos koji je kupac vidio mora biti isti kao naš; inače tražimo da osvježi stranicu */
  $seen = is_numeric($in['total'] ?? null) ? round((float)$in['total'], 2) : null;
  if ($seen !== null && abs($seen - $total) > 0.005) sj_json_out(['ok' => false, 'error' => 'Cijene su se u međuvremenu promijenile. Osvježite stranicu i pošaljite narudžbu ponovno.'], 409);

  $holdKey = sj_str($in['hold'] ?? '', 64);
  if (!preg_match('/^[a-f0-9]{32}$/', $holdKey)) $holdKey = '';
  $key = sj_str($in['key'] ?? '', 64);
  if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $key)) $key = '';
  $ttl = max(1, (int)$c['ttl_hours']);
  $ip = sj_short_ip(); $ipPrev = sj_short_ip(null, true);
  /* unikati koji su u katalogu na prodaji; torbe označene „Plaćeno” oduzimaju se tek pod bravom rezervacija */
  $freeIds = sj_free_unique_ids($prods);
  $itemsText = implode("\n", array_map(fn($it) => '- ' . $it['name'] . ($it['qty'] > 1 ? ' × ' . $it['qty'] : '') . ($it['note'] !== '' ? ' (' . $it['note'] . ')' : '') . ' — ' . sj_fmt_eur($it['price'] * $it['qty']), $items));
  $shipText = $ship ? $ship['label'] . ' — ' . ($shipPrice > 0 ? sj_fmt_eur($shipPrice) : 'besplatno') . ($locker !== '' ? ' — paketomat: ' . $locker : '') : 'po dogovoru';

  $P = ['key' => $key, 'holdKey' => $holdKey, 'uniqueIds' => $uniqueIds, 'cust' => $cust, 'ttl' => $ttl, 'ip' => $ip, 'ipPrev' => $ipPrev,
        'freeIds' => $freeIds, 'items' => $items, 'ship' => $ship, 'shipPrice' => $shipPrice, 'locker' => $locker, 'goods' => $goods, 'total' => $total,
        'note' => $note, 'itemsText' => $itemsText, 'shipText' => $shipText];
  /* v18: narudžba čeka da kupac potvrdi e-mail (link); tek tada dobiva broj i rezervaciju.
     v19: bez provjerene adrese stranice (link u e-mailu) narudžba se ne prima, umjesto da potvrda tiho otpadne */
  if (!empty($c['require_confirm'])) {
    if (sj_site_url_trusted() === '') sj_confirm_unavailable();
    sj_order_pending($P);
  }
  $done = sj_with_lock(SJ_DATA . '/narudzba-u-tijeku.json', function (array &$unused) use ($P) { return sj_place_order($P); }, []);
  sj_finish_order($done, $P);
}

/* ---------- confirm: kupac je kliknuo link iz e-maila „Potvrdite narudžbu” ---------- */
if ($a === 'confirm') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sj_json_out(['ok' => false, 'error' => 'POST only'], 405);
  if (!$originOk) sj_json_out(['ok' => false, 'error' => 'origin not allowed'], 403);
  $b = sj_body_json() ?? [];
  $pid = sj_str($b['id'] ?? '', 40); $sec = sj_str($b['t'] ?? '', 40);
  if (!preg_match('/^[a-f0-9]{32}$/', $pid) || !preg_match('/^[a-f0-9]{32}$/', $sec)) sj_json_out(['ok' => false, 'error' => 'Neispravan link za potvrdu. Kopirajte cijeli link iz e-maila.'], 400);
  if (!sj_rate_limit('confirmclick', 30, 3600)) sj_json_out(['ok' => false, 'error' => 'Previše pokušaja. Pokušajte ponovno za sat vremena.'], 429);
  /* ista brava kao narudžba: potvrda i nove narudžbe idu jedna po jedna */
  $out = sj_with_lock(SJ_DATA . '/narudzba-u-tijeku.json', function (array &$unused) use ($pid, $sec) {
    $e = null;
    foreach (sj_read_json(sj_pending_path(), []) as $x) if (($x['id'] ?? '') === $pid) { $e = $x; break; }
    if (!$e) return ['err' => 410];
    if (!hash_equals((string)($e['h'] ?? ''), hash('sha256', $sec))) return ['err' => 403];
    if (!empty($e['no'])) return ['prevNo' => (string)$e['no']];
    if (strtotime((string)($e['until'] ?? '')) < time()) return ['err' => 410];
    $P = (array)$e['P'];
    /* v19: od narudžbe je moglo proći do sat vremena — ponuda i cijene provjeravaju se ponovno */
    $chk = sj_order_recheck($P);
    if ($chk) return $chk;
    $done = sj_place_order($P);
    if (isset($done['no']) || isset($done['prev'])) {
      $no = (string)($done['no'] ?? ($done['prev']['no'] ?? ''));
      sj_with_lock(sj_pending_path(), function (array &$list) use ($pid, $no) {
        foreach ($list as &$x) if (($x['id'] ?? '') === $pid) { $x['no'] = $no; $x['confirmedAt'] = sj_iso(time()); }
        return true;
      }, []);
    }
    return ['done' => $done, 'P' => $P];
  }, []);
  if (($out['err'] ?? 0) === 403) sj_json_out(['ok' => false, 'error' => 'Neispravan link za potvrdu. Otvorite najnoviji e-mail „Potvrdite narudžbu”.'], 403);
  if (($out['err'] ?? 0) === 410) sj_json_out(['ok' => false, 'expired' => true, 'error' => 'Link za potvrdu je istekao, pa narudžba nije zaprimljena i torba nije rezervirana. Pošaljite narudžbu ponovno.'], 410);
  if (isset($out['taken'])) sj_json_out(['ok' => false, 'taken' => $out['taken']], 409);
  if (isset($out['changed'])) sj_json_out(['ok' => false, 'error' => $out['changed']], 409);
  if (isset($out['prevNo'])) {
    $o = sj_order_find(['no' => $out['prevNo']]);
    if ($o) sj_order_repeat_out($o);
    sj_json_out(['ok' => false, 'error' => 'Narudžba je već potvrđena; podatke za uplatu poslali smo vam e-mailom.'], 409);
  }
  sj_finish_order($out['done'], $out['P'], true);
}

/* ---------- potvrda e-mailom: pomoćne funkcije ---------- */
function sj_pending_path(): string { return SJ_DATA . '/narudzbe-na-cekanju.json'; }

/* potvrda je uključena, a nema provjerene adrese stranice za link: 503, vlasnica dobiva upozorenje najviše jednom na sat */
function sj_confirm_unavailable(): void {
  $c = sj_config();
  @file_put_contents(SJ_DATA . '/greske.log', gmdate('c') . " potvrda e-mailom: adresa stranice nije upisana ni provjerena\n", FILE_APPEND | LOCK_EX);
  if (sj_global_limit('alert_site', 1, 3600))
    sj_send_mail((string)$c['owner_email'], $c['shop'] . ' — PAŽNJA: narudžbe ne rade', "Kupac je upravo pokušao naručiti, ali narudžba nije primljena: za link „potvrdite narudžbu” u e-mailu potrebna je adresa stranice.\n\nUpišite je u admin → Postavke → „Adresa stranice” (npr. https://shivaj.hr). Ova poruka stiže najviše jednom na sat.");
  sj_json_out(['ok' => false, 'error' => 'Narudžbe su trenutno nedostupne. Pišite nam e-mailom na ' . $c['owner_email'] . ' i javit ćemo vam se.'], 503);
}
function sj_pending_prune(): void {
  sj_with_lock(sj_pending_path(), function (array &$list) {
    $list = array_values(array_filter($list, fn($x) => strtotime((string)($x['until'] ?? '')) >= time() - 86400));
    return true;
  }, []);
  @touch(sj_pending_path());
}
/* potvrda: stavke iz spremljene narudžbe uspoređuju se s trenutnim katalogom i dostavom; mijenja $P['freeIds'] */
function sj_order_recheck(array &$P): ?array {
  $prods = sj_products_by_id();
  $taken = [];
  foreach ((array)$P['items'] as $it) {
    $id = (string)$it['id']; $p = $prods[$id] ?? null;
    if (!$p) return ['changed' => 'Proizvod „' . $it['name'] . '” više nije u ponudi. Narudžba nije potvrđena; pošaljite je ponovno bez njega.'];
    /* v63: rubrika Ostalo isključena dok je narudžba čekala potvrdu */
    if (($p['cat'] ?? 'torbe') === 'ostalo' && empty(sj_config()['show_ostalo'])) return ['changed' => '„' . $it['name'] . '” trenutno nije u ponudi. Narudžba nije potvrđena; pošaljite je ponovno bez njega.'];
    if (empty($p['made']) && (!empty($p['sold']) || !empty($p['reserved']))) { $taken[] = $id; continue; }
    if (!empty($p['made']) && !empty($p['sold'])) return ['changed' => '„' . $it['name'] . '” trenutno nije dostupan za narudžbu. Narudžba nije potvrđena; pošaljite je ponovno bez njega.'];
    if (abs(round((float)($p['price'] ?? 0), 2) - (float)$it['price']) > 0.005) return ['changed' => 'Cijene su se u međuvremenu promijenile, pa narudžba nije potvrđena. Osvježite stranicu i pošaljite je ponovno.'];
  }
  if ($taken) return ['taken' => $taken];
  if (is_array($P['ship'] ?? null)) {
    $ok = false;
    foreach (sj_shipping() as $o) if ($o['id'] === $P['ship']['id'] && abs(sj_ship_price($o, (float)$P['goods']) - (float)$P['shipPrice']) < 0.005) $ok = true;
    if (!$ok) return ['changed' => 'Cijena dostave se u međuvremenu promijenila, pa narudžba nije potvrđena. Osvježite stranicu i pošaljite je ponovno.'];
  }
  $P['freeIds'] = sj_free_unique_ids($prods);
  return null;
}
function sj_confirm_minutes(): int { return max(10, min(24 * 60, (int)(sj_config()['confirm_minutes'] ?? 60))); }

/* Narudžba se sprema kao nepotvrđena (bez broja, bez rezervacije) i kupac dobiva link za potvrdu.
   Nepotvrđena narudžba ne drži ništa, pa lažne narudžbe s tuđim ili izmišljenim e-mailom ne blokiraju ponudu. */
function sj_order_pending(array $P): void {
  $c = sj_config();
  $cust = $P['cust'];
  /* v63: već ima neplaćenu narudžbu iz Stalne kolekcije — reći odmah, ne tek pri potvrdi */
  $prodsP = sj_products_by_id();
  $collP = array_keys(array_filter($prodsP, fn($x) => sj_is_collection($x)));
  if (array_filter((array)$P['items'], fn($it) => in_array((string)($it['id'] ?? ''), $collP, true)) && sj_made_open_order((string)$cust['email'], $collP) !== '')
    sj_json_out(['ok' => false, 'error' => 'Prethodna narudžba iz Stalne kolekcije čeka vašu uplatu; podatke za uplatu imate u e-mailu s potvrdom narudžbe. Kad je uplatite, možete naručiti sljedeću torbu; za više torbi odjednom javite nam se e-mailom ili na Instagramu.', 'code' => 'open_order', 'kind' => 'coll'], 409);
  /* odmah reći ono što bi potvrda ionako odbila (torba prodana ili u tuđoj narudžbi, kupac već ima neplaćenu) */
  if ($P['uniqueIds']) {
    $d = sj_res_read();
    $blocking = sj_blocking_ids($d, $P['freeIds']);
    $ek = sj_email_key((string)$cust['email']);
    $taken = [];
    foreach ($P['uniqueIds'] as $id) {
      $r = $d['res'][$id] ?? null;
      if (isset($d['sold'][$id]) || ($r && isset($blocking[$id]) && sj_email_key((string)($r['email'] ?? '')) !== $ek)) $taken[] = $id;
    }
    if ($taken) sj_json_out(['ok' => false, 'taken' => $taken], 409);
    foreach ($d['res'] as $rid => $x)
      if (!empty($x['ordered']) && !in_array((string)$rid, $P['uniqueIds'], true) && sj_email_key((string)($x['email'] ?? '')) === $ek)
        sj_json_out(['ok' => false, 'error' => 'Prethodna narudžba s unikatnom torbom čeka vašu uplatu; podatke za uplatu imate u e-mailu s potvrdom narudžbe. Kad je uplatite, možete naručiti sljedeću torbu; za više torbi odjednom javite nam se e-mailom ili na Instagramu.', 'code' => 'open_order', 'kind' => 'unique'], 409);
  }
  /* e-mail za potvrdu (stranica ne smije slati poštu na tuđe adrese). v20: sve granice se najprije samo provjeravaju,
     a upisuju tek kad je e-mail stvarno poslan (odbijen ili neposlan pokušaj ne troši granicu):
     - 3 na sat s iste skraćene adrese;
     - 2 na sat za par adresa + primatelj, 5 na sat po primatelju (tuđa adresa ne može sat vremena blokirati pravog kupca);
     - ukupno SJ_CONFIRM_TOTAL na sat: preko toga se narudžba ipak prima, ali BEZ e-maila i BEZ rezervacije
       (torbu dobiva tko prvi uplati), pa napadač s mnogo adresa ne može zatvoriti trgovinu ni držati torbe. */
  $ekC = sj_email_key((string)$cust['email']);
  $rk = 'mailto-' . substr(hash('sha256', $ekC), 0, 20);
  if (!sj_rate_limit('confirm', SJ_CONFIRM_PER_IP, 3600, false, false))
    sj_json_out(['ok' => false, 'error' => 'Previše narudžbi u kratkom vremenu. Pokušajte ponovno za sat vremena ili nam pišite e-mailom.'], 429);
  if (!sj_rate_limit($rk, 2, 3600, false, false) || !sj_global_limit($rk, 5, 3600, false))
    sj_json_out(['ok' => false, 'error' => 'Na ovu adresu već smo poslali e-mail za potvrdu. Pogledajte i neželjenu poštu (spam) ili pokušajte ponovno za sat vremena.'], 429);
  if (!sj_global_limit('confirm', SJ_CONFIRM_TOTAL, 3600, false)) sj_order_overload($P);

  $sec = sj_token();
  $until = sj_iso(time() + sj_confirm_minutes() * 60);
  $ek = sj_email_key((string)$cust['email']);
  $pid = sj_with_lock(sj_pending_path(), function (array &$list) use ($P, $sec, $until, $ek) {
    $now = time(); $id = '';
    /* isti ključ (ponovno slanje): isti zapis s novom tajnom, stari link prestaje vrijediti; potvrđeni i stari zapisi
       brišu se dan nakon isteka */
    $keep = [];
    foreach ($list as $x) {
      $old = strtotime((string)($x['until'] ?? '')) < $now - 86400;
      if ($old) continue;
      if (empty($x['no']) && ($P['key'] !== '' && ($x['key'] ?? '') === $P['key'])) { $id = (string)$x['id']; continue; }
      $keep[] = $x;
    }
    /* v19: tuđa nepotvrđena narudžba s istim e-mailom se ne briše (tko upiše tuđi e-mail, ne smije poništiti pravi link);
       po adresi najviše 3 nepotvrđene, najstarija ispada */
    $same = 0;
    $keep = array_values(array_filter($keep, function ($x) use ($ek, &$same) {
      if (!empty($x['no']) || ($x['ek'] ?? '') !== $ek) return true;
      return ++$same <= 2;
    }));
    if ($id === '') $id = sj_token();
    array_unshift($keep, ['id' => $id, 'h' => hash('sha256', $sec), 'key' => $P['key'], 'at' => sj_iso($now), 'until' => $until, 'ek' => $ek, 'P' => $P]);
    $list = array_slice($keep, 0, 500);
    return $id;
  }, []);
  if ($pid === '') throw new RuntimeException('nepotvrđena narudžba nije spremljena');

  $link = sj_site_url_trusted() . '/?potvrda=' . $pid . '.' . $sec;
  $t = [];
  $t[] = 'Dobar dan, ' . $cust['name'] . ',';
  $t[] = '';
  $t[] = 'hvala vam na narudžbi! Da bismo je zaprimili' . ($P['uniqueIds'] ? ' i rezervirali torbu za vas' : '') . ', potvrdite je klikom na link:';
  $t[] = '';
  $t[] = $link;
  $t[] = '';
  $cm = sj_confirm_minutes();
  /* v21: koliko link vrijedi, ne samo do kada (kao na stranici od v45) */
  $t[] = 'Link vrijedi ' . ($cm % 60 === 0 ? sj_hours_text(intdiv($cm, 60)) : $cm . ' minuta') . ' (do ' . sj_fmt_hr($until) . '). Nakon potvrde otvara se stranica s podacima za uplatu, a iste podatke šaljemo vam i e-mailom.';
  $t[] = '';
  $t[] = 'NARUDŽBA';
  $t[] = $P['itemsText'];
  $t[] = '- Dostava: ' . $P['shipText'];
  $t[] = 'Ukupno: ' . sj_fmt_eur((float)$P['total']);
  $t[] = '';
  $t[] = 'Ako niste naručili vi, zanemarite ovaj e-mail: bez potvrde narudžba se ne zaprima i ništa se ne rezervira.';
  $t[] = '';
  $t[] = 'Srdačan pozdrav,';
  $t[] = sj_signature(); /* v68: potpis iz Postavki */
  $sent = sj_send_mail((string)$cust['email'], $c['shop'] . ' — potvrdite narudžbu', implode("\n", $t), null, (string)$c['owner_email']);
  if ($sent) {
    sj_rate_limit('confirm', SJ_CONFIRM_PER_IP, 3600);
    sj_rate_limit($rk, 2, 3600);
    sj_global_limit($rk, 5, 3600);
    sj_global_limit('confirm', SJ_CONFIRM_TOTAL, 3600);
    if (!sj_global_limit('confirm_warn', 100, 3600) && sj_global_limit('alert_c', 1, 3600))
      sj_send_mail((string)$c['owner_email'], $c['shop'] . ' — PAŽNJA: mnogo narudžbi', 'U zadnjih sat vremena poslano je više od 100 e-mailova „potvrdite narudžbu”. Ako to nisu pravi kupci, netko zlorabi obrazac; narudžbe se i dalje primaju, a nepotvrđene ništa ne drže. Ova poruka stiže najviše jednom na sat.');
  }
  sj_rate_limit('order', PHP_INT_MAX, 3600);
  sj_json_out(['ok' => true, 'pending' => true, 'confirmUntil' => $until, 'confirmMinutes' => sj_confirm_minutes(), 'mailCustomer' => $sent]);
}

/* v20: preopterećenje (ukupna granica e-mailova za potvrdu). v68: narudžba se više ne prima „bez rezervacije”, nego se
   odbija s porukom (ukinuta je iznimka „bez rezervacije”); vlasnica dobiva upozorenje najviše jednom na sat. */
function sj_order_overload(array $P): void {
  $c = sj_config();
  if (sj_global_limit('alert_nc', 1, 3600))
    sj_send_mail((string)$c['owner_email'], $c['shop'] . ' — PAŽNJA: previše narudžbi', 'U zadnjih sat vremena stiglo je toliko narudžbi da se e-mailovi za potvrdu više ne šalju (zaštita od zlouporabe), pa se nove narudžbe privremeno ne primaju. Ako to nisu pravi kupci, netko zlorabi obrazac. Ova poruka stiže najviše jednom na sat.');
  sj_json_out(['ok' => false, 'error' => 'Trenutno primamo previše narudžbi odjednom. Pokušajte ponovno za sat vremena ili nam pišite e-mailom na ' . $c['owner_email'] . '.'], 503);
}

/* Jedna narudžba u isto vrijeme (poziva se pod bravom narudzba-u-tijeku.json, koja je uvijek prva, pa nema zastoja):
   1) isti ključ iz preglednika (ponovni klik, prekid veze, osvježena stranica) → isti odgovor, bez nove narudžbe;
   2) unikati: prodani ili rezervirani za drugog kupca → 409; slobodni ili kupčevi → rezervirani puni rok;
   3) broj narudžbe i upis. E-mailovi idu nakon brave (sj_finish_order). */
function sj_place_order(array $P): array {
  /* v62: Stalna kolekcija — provjera preostalih mjesta i upis narudžbe pod istom bravom (dvije istodobne narudžbe
     ne mogu zauzeti isto zadnje mjesto) */
  $coll = [];
  $byId = sj_products_by_id();
  /* v64: količina po modelu zbraja se (ponovljena stavka ne smije proći provjeru mjesta svaka zasebno) */
  foreach ((array)($P['items'] ?? []) as $it) { $p = $byId[(string)($it['id'] ?? '')] ?? null; if (!$p || !sj_is_collection($p)) continue;
    $k = (string)$p['id']; $coll[$k] = [$p, ($coll[$k][1] ?? 0) + max(1, (int)($it['qty'] ?? 1))]; }
  if (!$coll) return sj_place_order_locked($P);
  if (!is_dir(SJ_DATA)) mkdir(SJ_DATA, 0755, true);
  $fp = fopen(SJ_DATA . '/kolekcija.lock', 'c');
  if (!$fp) throw new RuntimeException('kolekcija.lock');
  flock($fp, LOCK_EX);
  try {
    $key = (string)($P['key'] ?? '');
    if ($key !== '') foreach (sj_read_json(SJ_DATA . '/narudzbe.json', []) as $prev) if (($prev['key'] ?? '') === $key) return ['prev' => $prev];
    sj_made_counts(true); /* svježe brojanje pod bravom */
    /* v63: jedna neplaćena narudžba iz Stalne kolekcije po e-mailu (lažna narudžba ne može popuniti sve modele) */
    $allColl = array_keys(array_filter($byId, fn($x) => sj_is_collection($x)));
    if (sj_made_open_order((string)($P['cust']['email'] ?? ''), $allColl) !== '') return ['limit' => 'Prethodna narudžba iz Stalne kolekcije čeka vašu uplatu; podatke za uplatu imate u e-mailu s potvrdom narudžbe. Kad je uplatite, možete naručiti sljedeću torbu; za više torbi odjednom javite nam se e-mailom ili na Instagramu.', 'status' => 409, 'code' => 'open_order', 'kind' => 'coll'];
    foreach ($coll as [$p, $q]) if (sj_made_left($p) < $q) return ['limit' => 'Model „' . $p['name'] . '” je trenutno popunjen (ograničen broj narudžbi po modelu). Javite nam se e-mailom ili na Instagramu pa vam napišemo kad otvorimo nova mjesta.', 'status' => 409];
    return sj_place_order_locked($P);
  } finally { flock($fp, LOCK_UN); fclose($fp); sj_made_counts(true); }
}
function sj_place_order_locked(array $P): array {
  extract($P, EXTR_SKIP);
  if ($key !== '') foreach (sj_read_json(SJ_DATA . '/narudzbe.json', []) as $prev) if (($prev['key'] ?? '') === $key) return ['prev' => $prev];

  $res = null; $back = null;
  if ($uniqueIds) {
    $r = sj_with_lock(sj_res_path(), function (array &$d) use ($uniqueIds, $cust, $ttl, $holdKey, $ip, $ipPrev, $freeIds) {
      $d = array_replace(sj_res_default(), $d);
      sj_res_prune($d);
      $blocking = sj_blocking_ids($d, $freeIds);
      $taken = []; $mine = []; $tok = ''; $since = ''; $prev = [];
      foreach ($uniqueIds as $id) {
        $r = $d['res'][$id] ?? null;
        $prev[$id] = $r; /* za vraćanje ako upis narudžbe ne uspije */
        /* rezervacija je kupčeva ako se slaže e-mail ili ako je narudžba donijela njezin tajni ključ (ispravljen e-mail) */
        $same = $r && (sj_email_key((string)($r['email'] ?? '')) === sj_email_key($cust['email']) || ($holdKey !== '' && hash_equals((string)($r['hold'] ?? ''), $holdKey)));
        if ($r && !$same && empty($r['ordered'])) $r = null;                          /* tuđa košarica bez narudžbe: ne blokira */
        if (isset($d['sold'][$id]) || ($r && !$same && isset($blocking[$id]))) $taken[] = $id; /* prodano ili tuđa narudžba koja blokira */
        elseif ($r && !empty($r['ordered'])) $mine[(string)($r['token'] ?? '')] = $id; /* isti kupac, već u ranijoj narudžbi */
        elseif ($r && $tok === '') { $tok = (string)($r['token'] ?? ''); $since = (string)($r['since'] ?? ''); }
      }
      if ($taken) return ['taken' => $taken];
      if ($mine) return ['mine' => $mine];
      /* zaštita od sabotaže: jedna neplaćena narudžba s unikatom po e-mailu (v68: granice po vezi i „pola ponude” ukinute —
         svaka zaprimljena narudžba rezervira svoje torbe do isteka roka za uplatu) */
      foreach ($d['res'] as $rid => $x) {
        if (empty($x['ordered']) || in_array((string)$rid, $uniqueIds, true)) continue;
        if (sj_email_key((string)($x['email'] ?? '')) === sj_email_key($cust['email'])) return ['limit' => 'Prethodna narudžba s unikatnom torbom čeka vašu uplatu; podatke za uplatu imate u e-mailu s potvrdom narudžbe. Kad je uplatite, možete naručiti sljedeću torbu; za više torbi odjednom javite nam se e-mailom ili na Instagramu.', 'status' => 409, 'code' => 'open_order', 'kind' => 'unique'];
      }
      $holdIds = $uniqueIds;
      $out = ['prev' => $prev];
      $now = time();
      $rec = ['token' => $tok !== '' ? $tok : sj_token(), 'ip' => $ip, 'ip_prev' => $ipPrev, 'name' => $cust['name'], 'email' => $cust['email'], 'since' => $since !== '' ? $since : sj_iso($now),
              'at' => sj_iso($now), 'until' => sj_iso($now + $ttl * 3600), 'ordered' => true];
      foreach ($holdIds as $id) $d['res'][$id] = $rec + ['id' => $id];
      return $out + ['token' => $rec['token'], 'until' => $rec['until'], 'hours' => $ttl];
    }, sj_res_default());
    if (isset($r['taken']) || isset($r['mine']) || isset($r['limit'])) return $r;
    $back = $r;
    if (!empty($r['token'])) $res = $r;
  }

  $seq = 0;
  sj_config_update(function (array &$cfg) use (&$seq) {
    $cfg['order_seq'] = (int)($cfg['order_seq'] ?? 0) + 1;
    $seq = (int)$cfg['order_seq'];
  });
  $no = 'SJ-' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
  $o = ['customer' => $cust, 'items' => $items, 'shipping' => $ship ? ['id' => $ship['id'], 'label' => $ship['label'], 'price' => $shipPrice, 'locker' => $locker] : null,
        'goods' => round($goods, 2), 'total' => $total, 'note' => $note, 'reservation' => $res ? ['until' => $res['until'], 'hours' => $res['hours']] : null];
  $entry = ['no' => $no, 'key' => $key, 'at' => sj_iso(time()), 'name' => $cust['name'], 'email' => $cust['email'], 'phone' => $cust['phone'],
            'total' => sj_fmt_eur($total), 'items' => $itemsText, 'shipping' => $shipText, 'address' => trim($cust['street'] . ', ' . $cust['city'], ', '),
            'note' => $note, 'reservation' => $res ? ['until' => $res['until'], 'token' => $res['token']] : null, 'order' => $o,
            'ip' => (string)($ip ?? ''), 'ip_prev' => (string)($ipPrev ?? '')]; /* skraćena veza: admin „Storniraj sve s ove adrese” */
  try {
    sj_orders_prepend($entry);
  } catch (Throwable $e) {
    /* narudžba nije zapisana: rezervacije se vraćaju kakve su bile, da kupac može poslati ponovno */
    if ($back) sj_with_lock(sj_res_path(), function (array &$d) use ($back) {
      $d = array_replace(sj_res_default(), $d);
      foreach ($back['prev'] as $id => $r) { if ($r === null) unset($d['res'][$id]); else $d['res'][$id] = $r; }
      return true;
    }, sj_res_default());
    throw $e;
  }
  return ['no' => $no, 'o' => $o, 'res' => $res];
}

/* Nakon upisa narudžbe: odgovori za odbijenu ili ponovljenu narudžbu, brojači, e-mailovi vlasnici i kupcu, JSON. */
function sj_finish_order(array $done, array $P, bool $confirmed = false): void {
  $c = sj_config();
  extract($P, EXTR_SKIP);
  if (isset($done['taken'])) sj_json_out(['ok' => false, 'taken' => $done['taken']], 409);
  if (isset($done['limit'])) {
    sj_json_out(['ok' => false, 'error' => $done['limit']] + (isset($done['code']) ? ['code' => $done['code'], 'kind' => $done['kind'] ?? ''] : []), (int)$done['status']);
  }
  if (isset($done['mine'])) {
    /* torba je već u ranijoj narudžbi istog kupca (npr. prvi pokušaj je stigao, a kupac je zatim promijenio napomenu) */
    $nm = []; foreach ($items as $it) $nm[(string)$it['id']] = $it['name'];
    $names = array_map(fn($id) => '„' . ($nm[$id] ?? $id) . '”', array_values($done['mine']));
    /* broj narudžbe se ne navodi: tko upiše tuđi e-mail, ne smije saznati tuđu narudžbu */
    sj_json_out(['ok' => false, 'error' => 'Torba ' . implode(', ', $names) . ' već je u vašoj ranijoj narudžbi; podatke za uplatu poslali smo vam e-mailom. Ako želite nešto promijeniti, odgovorite na taj e-mail.'], 409);
  }
  if (isset($done['prev'])) sj_order_repeat_out($done['prev']);
  $no = (string)$done['no']; $o = $done['o']; $res = $done['res'];
  $ref = sj_payment_ref($no);
  $stornoUrl = $res ? sj_storno_url((string)$res['token']) : '';
  if (!$confirmed) sj_rate_limit('order', PHP_INT_MAX, 3600); /* zaprimljena narudžba ulazi u ograničenje (provjera je bila na početku) */
  /* mnogo narudžbi sa šarenom torbom u satu: samo upozorenje vlasnici (najviše jednom na sat), narudžbe se i dalje primaju */
  $manyOrders = false;
  if ($uniqueIds) {
    sj_global_limit('orders_u', PHP_INT_MAX, 3600);
    $recentU = array_filter((array)(sj_read_json(SJ_DATA . '/zahtjevi.json', [])['sve|orders_u'] ?? []), fn($t) => (int)$t > time() - 3600);
    $manyOrders = count($recentU) > SJ_UNIQUE_ORDERS_PER_HOUR && sj_global_limit('alert_u', 1, 3600);
  }
  sj_count('orders');
  if (!$uniqueIds) sj_count('orders_m'); /* narudžba bez unikata (samo artikli po narudžbi) */

  /* najviše 30 automatskih potvrda kupcima na sat za cijelu stranicu: stranica se ne smije koristiti za slanje pošte */
  /* potvrđena narudžba je već prošla tu granicu pri slanju e-maila za potvrdu */
  /* v20: narudžba primljena u preopterećenju (bez potvrde e-mailom): bez e-mailova, vlasnica dobiva sažetak najviše jednom na sat */
  $capped = !$confirmed && !empty($c['confirm_customer']) && (!sj_rate_limit('confirm', SJ_CONFIRM_PER_IP, 3600) || !sj_global_limit('confirm', 30, 3600));

  /* e-mail vlasnici */
  $lines = ["Nova narudžba $no — " . $c['shop'], ""];
  $lines[] = 'Kupac: ' . $cust['name'];
  $lines[] = 'E-mail: ' . $cust['email'];
  if ($cust['phone'] !== '') $lines[] = 'Telefon: ' . $cust['phone'];
  if ($cust['street'] !== '') $lines[] = 'Adresa: ' . $cust['street'] . ', ' . $cust['city'];
  $lines[] = '';
  $lines[] = 'Naručeno:';
  $lines[] = $itemsText;
  /* v65: želja kupca istaknuta — da se ne previdi prije šivanja */
  foreach ($items as $it) if (!empty($it['made']) && trim((string)($it['note'] ?? '')) !== '') $lines[] = 'ŽELJA KUPCA — ' . $it['name'] . ': ' . trim((string)$it['note']);
  $lines[] = 'Dostava: ' . $shipText;
  $lines[] = 'Ukupno: ' . sj_fmt_eur($total);
  $lines[] = 'Poziv na broj (za usporedbu s izvodom banke): ' . sj_payment_ref($no);
  /* v63: stanje mjesta u Stalnoj kolekciji — da vlasnica zna kad je model popunjen */
  $byIdM = sj_products_by_id();
  foreach ($items as $it) { $pm = $byIdM[(string)$it['id']] ?? null; if (!$pm || !sj_is_collection($pm)) continue;
    $um = sj_made_used((string)$it['id']); $lm = sj_made_limit($pm);
    $lines[] = $um >= $lm ? '„' . $pm['name'] . '” je sada popunjen (' . $um . '/' . $lm . ' mjesta); na stranici piše „Popunjeno”. Kad budete spremni za nove narudžbe: admin → Torbe → „Otvori nova mjesta”.'
                          : 'Stalna kolekcija: „' . $pm['name'] . '” — zauzeto ' . $um . ' od ' . $lm . ' mjesta.'; }
  if ($note !== '') { $lines[] = ''; $lines[] = 'Napomena: ' . $note; }
  $lines[] = '';
  $lines[] = 'Kupac je prihvatio Uvjete kupnje (narudžba s obvezom plaćanja)' . ($confirmed ? ' i potvrdio narudžbu linkom iz e-maila.' : '.');
  if ($res) {
    $lines[] = 'Rezervacija vrijedi do: ' . sj_fmt_hr($res['until']);
    $lines[] = 'Kad stigne potvrda uplate, kliknite „Označi plaćenom” prije isteka rezervacije: kupcu smo obećali da torbu tada čuvamo i nakon isteka.';

    $lines[] = $stornoUrl !== '' ? 'Plaćeno / storno rezervacije (uz prijavu u admin): ' . $stornoUrl : 'Plaćeno / storno rezervacije: u adminu pod „Rezervacije” (upišite adresu stranice u Postavkama, pa će ovdje stajati izravan link).';
  }
  if ($capped) $lines[] = 'PAŽNJA: s iste veze ili ukupno stiglo je u zadnjih sat vremena previše narudžbi, pa kupcu NIJE poslana automatska potvrda (zaštita od zlouporabe). Ako je narudžba prava, pošaljite kupcu podatke za uplatu ručno.';
  if (!$uniqueIds) $lines[] = 'Narudžba bez unikata: kad kupac uplati, označite je kao plaćenu u adminu → Narudžbe (tako je Mjerenje broji).';
  if (($ship['id'] ?? '') === 'pickup') $lines[] = 'Preuzimanje u radionici: kad je narudžba spremna, javite se kupcu i dogovorite termin.';
  $lines[] = '';
  if (sj_site_url_trusted() !== '') $lines[] = 'Narudžbe i rezervacije: ' . sj_site_url_trusted() . '/admin/';
  $warnAt = count($lines);
  if ($manyOrders) $lines[] = 'PAŽNJA: u zadnjih sat vremena stiglo je više od ' . SJ_UNIQUE_ORDERS_PER_HOUR . ' narudžbi s unikatnim torbama. Provjerite jesu li prave (ova napomena stiže najviše jednom na sat).';
  /* v65: upozorenja na vrh e-maila, odmah ispod naslova (naslov već kaže „PAŽNJA”) */
  if (count($lines) > $warnAt) { $warn = array_splice($lines, $warnAt); array_splice($lines, 2, 0, array_merge($warn, [''])); /* v66: naslov, prazan red, PAŽNJA, prazan red */ }
  $subject = ($manyOrders ? 'PAŽNJA, mnogo narudžbi — ' : '') . "Narudžba $no — " . $cust['name'] . ' — ' . implode(', ', array_map(fn($it) => $it['name'], $items));
  $sentOwner = sj_send_mail((string)$c['owner_email'], $subject, implode("\n", $lines), null, $cust['email']);

  /* automatska potvrda kupcu */
  $sentCust = false;
  if (!empty($c['confirm_customer']) && !$capped) {
    /* v24: barkod narudžbe (sve popunjeno); ako ga nije moguće nacrtati, opći iz Postavki (samo primatelj i IBAN) */
    $pay = (array)($c['payment'] ?? []);
    $bar = ''; $barKind = '';
    if (!empty($pay['iban']) && (float)($o['total'] ?? 0) > 0) {
      $tmpDir = SJ_DATA . '/tmp';
      if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
      /* v63: zaostale slike (prekinut PHP) starije od sat vremena */
      foreach (glob($tmpDir . '/barkod-*.png') ?: [] as $old) if (@filemtime($old) < time() - 3600) @unlink($old);
      $tmp = '';
      try {
        $tmp = $tmpDir . '/barkod-' . preg_replace('/[^A-Za-z0-9-]/', '', $no) . '-' . bin2hex(random_bytes(4)) . '.png';
        $hub = sj_hub3_text(['amount' => (float)$o['total'], 'recipient' => (string)($pay['recipient'] ?? ''), 'address' => (string)($pay['address'] ?? ''),
          'iban' => (string)$pay['iban'], 'model' => (string)($pay['model'] ?? 'HR00'), 'ref' => sj_payment_ref($no), 'desc' => sj_payment_desc($no)]);
        if (sj_pdf417_png($hub, $tmp)) { $bar = $tmp; $barKind = 'order'; }
      } catch (Throwable $e) { /* opći barkod */ }
      if ($barKind !== 'order' && $tmp !== '') @unlink($tmp); /* djelomično zapisana slika ne ostaje */
    }
    if ($bar === '') { $g = SJ_ROOT . '/' . ltrim((string)($pay['barcode'] ?? ''), '/'); if (is_file($g)) { $bar = $g; $barKind = 'generic'; } }
    /* v25: privremena slika briše se i kad slanje baci grešku */
    try {
      $text = sj_customer_mail($o, $no, $c, $barKind);
      $sentCust = sj_send_mail($cust['email'], $c['shop'] . " — potvrda narudžbe $no", $text, $bar !== '' ? $bar : null, (string)$c['owner_email'],
        $bar !== '' ? 'barkod-za-uplatu-' . preg_replace('/[^0-9-]/', '', sj_payment_ref($no)) . '.png' : null);
    } catch (Throwable $e) {
      $sentCust = false;
    } finally {
      if ($barKind === 'order') @unlink($bar);
    }
  }
  /* ishod e-mailova ostaje uz narudžbu, da ponovljeni zahtjev s istim ključem kaže istinu */
  sj_with_lock(SJ_DATA . '/narudzbe.json', function (array &$list) use ($no, $sentOwner, $sentCust) {
    foreach ($list as &$e) if (($e['no'] ?? '') === $no) { $e['mail'] = ['owner' => $sentOwner, 'customer' => $sentCust]; break; }
    return true;
  }, []);
  sj_json_out(['ok' => true, 'orderNo' => $no, 'reference' => $ref, 'total' => $total, 'until' => $res ? $res['until'] : null, 'payUntil' => sj_pay_until(['at' => sj_iso(time())]), 'reserved' => true, 'mailOwner' => $sentOwner, 'mailCustomer' => $sentCust]
    + sj_pay_out() /* v62: ploča za uplatu ne ovisi o tome je li api/status stigao */ + sj_order_view($o));
}

/* stavke i dostava za ploču s podacima za uplatu (kupac može potvrditi na drugom uređaju, bez košarice) */
function sj_order_view(array $o): array {
  $items = [];
  foreach ((array)($o['items'] ?? []) as $it) $items[] = ['id' => (string)($it['id'] ?? ''), 'name' => (string)($it['name'] ?? ''), 'qty' => (int)($it['qty'] ?? 1), 'price' => (float)($it['price'] ?? 0), 'made' => !empty($it['made']), 'lead' => (string)($it['lead'] ?? ''), 'note' => (string)($it['note'] ?? '')];
  $s = is_array($o['shipping'] ?? null) ? $o['shipping'] : null;
  return ['items' => $items, 'ship' => $s ? ['id' => (string)$s['id'], 'label' => (string)$s['label'], 'price' => (float)$s['price'], 'locker' => (string)($s['locker'] ?? '')] : null];
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
    /* v23: trajno prodana torba (zastavica u katalogu) se linkom ne vraća — samo „Vrati u prodaju” u adminu */
    if ($action === 'unsold') foreach ($sold as $sid => $_) foreach (sj_products() as $p) if (($p['id'] ?? '') === (string)$sid && !empty($p['sold']))
      sj_html_page('Rezervacija', '<p>Torba je u adminu označena kao trajno prodana. Ako je zaista treba vratiti u prodaju, u adminu kliknite „Vrati u prodaju”.</p>', 409);
    if (!$loggedIn) sj_html_page('Rezervacija', '<p>Za ovu radnju potrebna je prijava u admin.</p><p><a href="' . e($adminUrl) . '">Prijava u admin</a></p>', 403);
    if (!sj_check_csrf()) sj_html_page('Rezervacija', '<p>Obrazac je zastario. Otvorite link iz e-maila ponovno.</p>', 400);
    /* v63: kvačica za potvrdu provjerava se i na poslužitelju */
    if (in_array($action, ['release', 'unsold'], true) && ($_POST['confirm'] ?? '') !== '1') sj_html_page('Rezervacija', '<p>Označite kvačicu za potvrdu i pokušajte ponovno.</p>', 400);
    /* u bravi se radi samo nad zapisima koji nose ovaj token (rezervacija je u međuvremenu mogla isteći i pripasti drugom kupcu) */
    $done = sj_with_lock(sj_res_path(), function (array &$d) use ($action, $token) {
      $d = array_replace(sj_res_default(), $d);
      $ids = [];
      if ($action === 'release') { foreach ($d['res'] as $id => $r) if (($r['token'] ?? '') === $token) { unset($d['res'][$id]); $ids[] = $id; } }
      if ($action === 'release' && $ids) sj_order_cancelled($token);
      elseif ($action === 'sold') { foreach ($d['res'] as $id => $r) if (($r['token'] ?? '') === $token) { sj_mark_paid($d, (string)$id); $ids[] = $id; } }
      elseif ($action === 'unsold') { $nn = ''; foreach ($d['sold'] as $id => $r) if (($r['token'] ?? '') === $token) { $nn = $nn ?: (string)($r['no'] ?? ''); unset($d['sold'][$id]); $ids[] = $id; }
        if ($ids) sj_order_unpaid_if_empty($d['sold'], $token, $nn); }
      return $ids;
    }, sj_res_default());
    $pn = []; foreach (sj_products() as $pp) $pn[(string)($pp['id'] ?? '')] = (string)($pp['name'] ?? '');
    $ordS = sj_order_find(['token' => $token]);
    $list = $done ? '<b>' . e(implode(', ', array_map(fn($i) => $pn[(string)$i] ?? (string)$i, $done))) . '</b>' . ($ordS ? ' (narudžba ' . e((string)($ordS['no'] ?? '')) . ')' : '') : '';
    $callC = $ordS ? ' Kupac ne dobiva automatsku poruku: javite mu se (' . e((string)($ordS['email'] ?? '')) . ').' : '';
    if ($action === 'release') sj_html_page('Rezervacija', $done ? "<p>Rezervacija je stornirana: $list. Torba je ponovno dostupna na stranici.$callC</p>" : '<p>Rezervacija je već stornirana ili je istekla.</p>');
    if ($action === 'sold') sj_html_page('Rezervacija', $done ? "<p>Označeno kao plaćeno: $list. Na stranici piše „Prodano“ i kad rezervacija istekne. Torbu trajno označite prodanom u adminu.</p>" : '<p>Rezervacija je već stornirana ili je istekla. Ako je kupac ipak platio, torbu označite prodanom u adminu.</p>');
    if ($action === 'unsold') sj_html_page('Rezervacija', $done ? "<p>Oznaka „plaćeno“ uklonjena: $list. Torba je ponovno u prodaji; ako je kupac platio, vratite mu novac.$callC" . e(implode('', array_map(fn($i) => sj_other_paid((string)$i, (string)($ordS['no'] ?? '')), $done))) . "</p>" : '<p>Nema oznake za uklanjanje.</p>');
    sj_html_page('Rezervacija', '<p>Nepoznata radnja.</p>', 400);
  }

  $loginNote = $loggedIn ? '' : '<p class="muted">Gumbi rade tek nakon <a href="' . e($adminUrl) . '">prijave u admin</a>; nakon prijave otvorite ovaj link ponovno. Iste radnje imate i u adminu pod „Rezervacije“.</p>';
  $csrf = $loggedIn ? '<input type="hidden" name="csrf" value="' . e(sj_csrf()) . '">' : '';
  $dis = $loggedIn ? '' : ' disabled';
  if ($mine) {
    $rows = '';
    $pn = []; foreach (sj_products() as $pp) $pn[(string)($pp['id'] ?? '')] = (string)($pp['name'] ?? '');
    $ordS = sj_order_find(['token' => $token]);
    foreach ($mine as $r) $rows .= '<li><b>' . e($pn[(string)$r['id']] ?? $r['id']) . '</b>' . ($ordS ? ' · ' . e((string)($ordS['no'] ?? '')) : '') . ($loggedIn ? ' — ' . e($r['name']) . ' (' . e($r['email']) . ')' : '') . ', rezervirano ' . e(sj_fmt_hr($r['since'])) . ', vrijedi do ' . e(sj_fmt_hr($r['until'])) . '</li>';
    sj_html_page('Rezervacija', '<p>Ova narudžba drži rezervaciju:</p><ul>' . $rows . '</ul>' . $loginNote . '
      <div class="row">
        <form method="post">' . $csrf . '<input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="sold"><button' . $dis . '>Označi plaćenom</button></form>
        <form method="post">' . $csrf . '<input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="release"><label style="display:block;margin:6px 0"><input type="checkbox" name="confirm" value="1" required' . $dis . '> Da, storniraj (torba se vraća u prodaju)</label><button class="alt"' . $dis . '>Storniraj</button></form>
      </div>
      <p class="muted">„Označi plaćenom“ drži torbu kao prodanu i nakon isteka rezervacije. „Storniraj“ odmah vraća torbu u prodaju (kupcu se javite sami). Sve to vidite i u <a href="' . e($adminUrl) . '">adminu</a>.</p>');
  }
  if ($sold) {
    $pn = []; foreach (sj_products() as $pp) $pn[(string)($pp['id'] ?? '')] = (string)($pp['name'] ?? '');
    $s0 = reset($sold);
    sj_html_page('Rezervacija', '<p>Označeno kao plaćeno / prodano: <b>' . e(implode(', ', array_map(fn($i) => $pn[(string)$i] ?? (string)$i, array_keys($sold)))) . '</b>' . ($loggedIn && ($s0['name'] ?? '') !== '' ? ' — ' . e((string)$s0['name']) . ' (' . e((string)($s0['email'] ?? '')) . ')' : '') . (($s0['no'] ?? '') !== '' ? ' · ' . e((string)$s0['no']) : '') . '.</p>' . $loginNote . '
      <form method="post">' . $csrf . '<input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="unsold"><label style="display:block;margin:6px 0"><input type="checkbox" name="confirm" value="1" required' . $dis . '> Da, ukloni oznaku plaćeno (torba se vraća u prodaju)</label><button class="alt"' . $dis . '>Ukloni oznaku</button></form>
      <p class="muted">Ako je kupac platio, vratite mu novac. Trajno prodana torba vraća se samo u adminu („Vrati u prodaju”).</p>');
  }
  sj_html_page('Rezervacija', '<p>Rezervacija je već stornirana ili je istekla. Ako je kupac ipak platio, torbu označite prodanom u adminu.</p>');
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found";
exit;


/* ---------- potvrda kupcu (svi podaci su već provjereni na poslužitelju) ---------- */
function sj_customer_mail(array $o, string $no, array $c, string $bar = 'generic'): string {
  $cust = $o['customer'] ?? [];
  $items = is_array($o['items'] ?? null) ? $o['items'] : [];
  $ship = is_array($o['shipping'] ?? null) ? $o['shipping'] : [];
  $res = is_array($o['reservation'] ?? null) ? $o['reservation'] : null;
  $names = []; $lines = []; $anyMade = false; $anyUnique = false; $leads = []; $nUnique = 0;
  /* v63: narudžba samo s torbama (unikati i Stalna kolekcija) — u e-mailu „torba”, ne „artikl” */
  $byIdC = sj_products_by_id(); $allColl = true; $anyMadeItem = false;
  foreach ($items as $it0) if (!empty($it0['made'])) { $anyMadeItem = true; $pc = $byIdC[(string)($it0['id'] ?? '')] ?? null; if (!$pc || !sj_is_collection($pc)) $allColl = false; }
  if (!$anyMadeItem) $allColl = false;
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
  $until = ($res && !empty($res['until'])) ? ' do ' . sj_fmt_hr((string)$res['until']) : '';
  /* v64: koja je torba rezervirana, poimence */
  $uq = []; foreach ($items as $it) if (empty($it['made'])) $uq[] = '„' . (string)($it['name'] ?? '') . '”';
  $until = ''; /* v66: datum rezervacije samo u odlomku „Rezervacija vrijedi …” */
  $torba = $nUnique > 1 ? "Torbe " . implode(' i ', $uq) . " su rezervirane za vas$until — svaka postoji samo u jednom primjerku i čeka vašu uplatu." : "Torba " . implode('', $uq) . " je rezervirana za vas$until — postoji samo u jednom primjerku i čeka vašu uplatu.";

  $t = [];
  $t[] = 'Dobar dan, ' . (string)($cust['name'] ?? '') . ',';
  $t[] = '';
  $t[] = "hvala vam na narudžbi br. $no! Ova poruka je potvrda vaše narudžbe." . ($anyUnique ? ' ' . $torba : '' /* v66: izrada i rok su u jednom odlomku niže */);
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
  $t[] = '- Poziv na broj: ' . sj_payment_ref($no) . ' (upišite točno ovako, bez „SJ-”)';
  $t[] = '- Opis plaćanja: ' . sj_payment_desc($no);
  $t[] = '';
  if ($bar === 'order') {
    $t[] = 'Najbrže: u aplikaciji svoje banke skenirajte barkod iz privitka — primatelj, IBAN, iznos, model, poziv na broj i opis popune se sami; samo potvrdite plaćanje. Ako ovaj e-mail čitate na mobitelu, spremite sliku barkoda i učitajte je u aplikaciji banke, ako to nudi, ili podatke kopirajte.';
    $t[] = '';
  } elseif ($bar === 'generic') {
    $t[] = 'Najbrže: u aplikaciji svoje banke skenirajte barkod iz privitka. Primatelj i IBAN popune se sami, a vi upišite još iznos (' . sj_fmt_eur($total) . '), model ' . $p['model'] . ' i poziv na broj ' . sj_payment_ref($no) . '. Ako ovaj e-mail čitate na mobitelu, spremite sliku barkoda i učitajte je u aplikaciji banke, ako to nudi, ili podatke kopirajte.';
    $t[] = '';
  }
  /* v68: rok za uplatu za svaku narudžbu (i bez unikata), s točnim vremenom; savjet za instant uplatu i potvrdu uplate */
  $hrs = max(1, (int)($res['hours'] ?? $c['ttl_hours']));
  $payUntil = ($res && !empty($res['until'])) ? (string)$res['until'] : sj_iso(time() + $hrs * 3600);
  $t[] = 'ROK ZA UPLATU: do ' . sj_fmt_hr($payUntil) . ' (' . sj_hours_text($hrs) . ').';
  $t[] = 'Ako vaša banka nudi instant uplatu, odaberite je: uplata tada obično stigne brže. Ako ste već platili, odgovorite na ovaj e-mail sa snimkom potvrde o uplati' . ($anyUnique ? '; tada ' . $torbu . ' čuvamo za vas i nakon isteka roka, bez obzira na to kad banka proknjiži uplatu.' : '.');
  if ($anyUnique) $t[] = 'Ako do roka ne vidimo uplatu ni potvrdu, ' . ($nUnique > 1 ? 'torbe se vraćaju' : 'torba se vraća') . ' u prodaju. Ako ste platili, a uplata još putuje, ne brinite: ' . ($nUnique > 1 ? 'torbe vam čuvamo dok su slobodne, a ako ih je' : 'torbu vam čuvamo dok je slobodna, a ako ju je') . ' u međuvremenu netko kupio, vraćamo vam cijeli iznos.';
  $t[] = '';
  $pickup = (($o['shipping']['id'] ?? '') === 'pickup');
  $shipLead = trim((string)($c['ship_lead'] ?? ''));
  /* zadani tekst preuzimanja već kaže „javit ćemo vam se… dogovoriti termin”; tada se ne ponavlja */
  $pickupCalls = stripos((string)$c['pickup_info'], 'javit') !== false;
  if ($anyMade) {
    /* v66: izrada, rok i slanje u jednom odlomku (prije tri puta isto) */
    $madeIt = array_values(array_filter($items, fn($it) => !empty($it['made'])));
    /* v67: isti rok jednom; različiti s nazivima („A” 5 radnih dana, „B” 7 radnih dana) */
    $ldU = array_values(array_unique(array_map(fn($it) => (string)$it['lead'], array_filter($madeIt, fn($it) => !empty($it['lead'])))));
    $ld = count($ldU) > 1 ? [implode(', ', array_map(fn($it) => '„' . $it['name'] . '” ' . $it['lead'], array_filter($madeIt, fn($it) => !empty($it['lead']))))] : $ldU;
    $subj = $allColl && !$anyUnique ? (count($madeIt) > 1 ? 'vaše torbe' : 'vašu torbu') : sj_and_join(array_map(fn($it) => '„' . (string)$it['name'] . '”', $madeIt));
    $sent = 'Čim uplata bude vidljiva, ' . ($allColl ? 'šijemo ' : 'izrađujemo ') . $subj . ($ld ? (count($ldU) > 1 ? ' (rok izrade od primitka uplate: ' . $ld[0] . ')' : ' (rok izrade ' . $ld[0] . ' od primitka uplate)') : ' (rok izrade teče od primitka uplate)');
    if ($anyUnique) $sent .= $pickup ? ', a kad je sve gotovo, cijelu narudžbu pripremamo za preuzimanje u radionici.'
      : ', zatim cijelu narudžbu — ' . sj_and_join(array_map(fn($it) => '„' . (string)($it['name'] ?? '') . '”', $items)) . ' — šaljemo zajedno, u jednom paketu' . ($shipLead !== '' ? '; slanje je obično ' . $shipLead . ' nakon izrade.' : ', kad je sve gotovo.');
    else $sent .= $pickup ? ', a gotovu narudžbu pripremamo za preuzimanje u radionici.' : '; gotovu narudžbu pažljivo pakiramo i šaljemo' . ($shipLead !== '' ? ', obično ' . $shipLead . ' nakon izrade.' : '.');
    $t[] = $sent . ($pickup ? ($pickupCalls ? '' : ' Javit ćemo vam se kad bude spremno za preuzimanje i dogovoriti termin.') : ' Poslat ćemo vam poruku s potvrdom slanja.');
  } else
  $t[] = 'Čim uplata bude vidljiva, ' . ($pickup ? $torbu . ' pripremamo za preuzimanje u radionici.' : $torbu . ' pažljivo pakiramo i šaljemo.')
    . ($pickup ? ($pickupCalls ? '' : ' Javit ćemo vam se kad bude spremno za preuzimanje i dogovoriti termin.') : ($shipLead !== '' ? ' Slanje je obično za ' . $shipLead . '.' : '') . ' Poslat ćemo vam poruku s potvrdom slanja.');
  if ($anyMade && array_filter($madeIt, fn($it) => trim((string)($it['note'] ?? '')) !== '')) {
    $t[] = '';
    /* v65: želja kupca (boja, duljina) — potvrda i obećanje iz košarice */
    foreach ($madeIt as $it) if (trim((string)($it['note'] ?? '')) !== '')
      $t[] = 'Vaša želja za „' . $it['name'] . '”: ' . trim((string)$it['note']) . '. Ako je ne možemo ispuniti, javit ćemo vam se prije izrade — tada birate izradu bez te želje ili povrat cijelog iznosa.';
  }
  $t[] = '';
  $what = (!$anyMade || $allColl) ? (count($items) > 1 ? 'Torbe' : 'Torbu') : (count($items) > 1 ? 'Artikle' : 'Artikl');
  $t[] = 'Ako se predomislite, ugovor možete jednostrano raskinuti' . ($anyMade ? ' (osim artikala izrađenih po vašim posebnim željama)' : '') . ' u roku 14 dana od dana kad ste ' . ($pickup ? 'preuzeli narudžbu' : 'primili pošiljku') . ', bez navođenja razloga: dovoljna je nedvosmislena izjava e-mailom (obrazac je u Uvjetima kupnje, t. 6). ' . $what . ' vraćate u roku 14 dana od izjave, o svom trošku; uplaćeni iznos vraćamo u roku i na način iz t. 6 Uvjeta kupnje.' . (sj_site_url_trusted() !== '' ? ' Uvjeti kupnje: ' . sj_site_url_trusted() . '/#uvjeti' : '');
  $t[] = 'Za bilo kakva pitanja odgovorite na ovaj e-mail ili nam se javite porukom na Instagramu (@shiva.j_handmade).';
  $t[] = '';
  $t[] = 'Srdačan pozdrav,';
  $t[] = sj_signature(); /* v68: potpis iz Postavki */
  $t[] = 'Ručno šivane torbe iz Zaboka';
  if (preg_match('/^\d{11}$/', (string)($c['oib'] ?? ''))) $t[] = 'OIB: ' . $c['oib'];
  return implode("\n", $t);
}

/* v65: „A”, „B” i „C” */
function sj_and_join(array $a): string { $a = array_values($a); $n = count($a); return $n > 1 ? implode(', ', array_slice($a, 0, -1)) . ' i ' . $a[$n - 1] : implode('', $a); }

/* ---------- HTML stranica (storno) ---------- */
function sj_html_page(string $title, string $body, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  header('Referrer-Policy: no-referrer');
  header('X-Frame-Options: DENY');
  header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
  echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' — Shiva.J</title>
<style>body{margin:0;background:#F6F6F3;color:#1B1A17;font:16px/1.6 system-ui,sans-serif}main{max-width:760px;margin:0 auto;padding:40px 20px}h1{font-weight:400;font-size:1.5rem;letter-spacing:.04em;border-bottom:1.5px dashed #2E4A3A;padding-bottom:12px}button{background:#2E4A3A;color:#F6F6F3;border:1.5px solid #2E4A3A;border-radius:2px;padding:9px 14px;font-size:.85rem;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}button.alt{background:none;color:#2E4A3A;border-style:dashed}button[disabled]{opacity:.45;cursor:not-allowed}.row{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.muted{color:#5C5A53;font-size:.85rem}a{color:#2E4A3A}</style></head><body><main><h1>' . e($title) . '</h1>' . $body . '</main></body></html>';
  exit;
}
