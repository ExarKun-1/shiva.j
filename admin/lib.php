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
/* v82 (pregled prije objave): datumi (godina u broju narudžbe, arhive, tjedni) po hrvatskom vremenu i kad hosting radi u UTC-u */
date_default_timezone_set('Europe/Zagreb');

define('SJ_ROOT', dirname(__DIR__));
define('SJ_DATA', SJ_ROOT . '/data');
define('SJ_IMG', SJ_ROOT . '/img');
define('SJ_PUBLIC_JSON', SJ_ROOT . '/torbe.json');
define('SJ_VERSION', 'admin v53');

/* ---------- postavke ---------- */

function sj_defaults(): array {
  return [
    'shop'             => 'Shiva.J',
    'owner_email'      => 'shivaj.handmade@gmail.com', // kamo stižu narudžbe
    'from_email'       => '',                          // npr. info@shivaj.hr; prazno = owner_email
    'site_url'         => '',                          // npr. https://shivaj.hr; prazno = automatski
    'oib'              => '',                          // v25: OIB obrta za potpis u e-mailu kupcu; prazno = ne prikazuje se
    'ttl_hours'        => 24,                          // koliko sati traje rezervacija = rok za uplatu (v68)
    'remind_hours'     => 12,                          // v68: podsjetnik kupcu (bez barkoda) nakon toliko sati, ako nije označeno plaćeno; 0 = bez
    'auto_cancel_hours'=> 48,                          // v68: automatski storno toliko sati NAKON isteka roka, ako vlasnica ne klikne; 0 = nikad
    'deadline_tick'    => true,                        // v68: podsjetnici, „rok istekao” i automatski storno (ispitni podaci: false)
    'signature'        => 'Jasmina · Shiva.J',         // v68: potpis e-mailova kupcima
    'free_ship'        => ['min' => 120, 'ids' => ['boxnow']], // v68: besplatna dostava kad roba ≥ min (0 = isključeno), samo za ove načine
    'sizes'            => [['mjera' => '22 × 7 cm', 'opis' => ''], ['mjera' => '24 × 7 cm', 'opis' => ''], ['mjera' => '26 × 7 cm', 'opis' => '']], // v68: mjere s univerzalnim opisom
    'size_all'         => '',                          // v68: tekst za sve torbe (npr. remen), u bloku „Veličina i nošenje”
    'confirm_customer' => true,                        // automatska potvrda kupcu
    'require_confirm'  => true,                        // narudžba vrijedi tek kad je kupac potvrdi linkom iz e-maila
    'show_ostalo'      => false,                       // v62: rubrika „Ostalo” (artikli po narudžbi) na stranici; kod ostaje
    'confirm_minutes'  => 60,                          // koliko minuta vrijedi link za potvrdu
    'mail_mode'        => 'mail',                      // 'mail' = PHP mail(); 'smtp' = SMTP s prijavom (v79); 'log' = data/mail-log.txt (test)
    'smtp_host'        => '',                          // v79: npr. smtp.shivaj.hr (Croadria: smtp.<domena>)
    'smtp_port'        => 465,                         // v79: 465 = SSL, 587 = STARTTLS
    'smtp_secure'      => 'ssl',                       // v79: 'ssl' | 'starttls' | 'none' (samo 127.0.0.1, testovi)
    'smtp_user'        => '',                          // v79: cijela e-adresa sandučića
    'smtp_pass'        => '',                          // v79: lozinka sandučića; nikad se ne prikazuje u adminu
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
    'ship_lead'        => '1–2 radna dana',            // rok slanja nakon uplate (v75: odluka vlasnice, 10. krug); prazno = ne prikazuje se
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
    foreach (['shipping', 'allowed_origins', 'sizes'] as $k) if (isset($saved[$k]) && is_array($saved[$k])) $c[$k] = array_values($saved[$k]);
    if (isset($saved['free_ship']['ids']) && is_array($saved['free_ship']['ids'])) $c['free_ship']['ids'] = array_values($saved['free_ship']['ids']);
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

/* v68: besplatna dostava — prag robe (EUR) i načini dostave na koje vrijedi (zadano samo BOX NOW) */
function sj_free_ship(): array {
  $f = (array)(sj_config()['free_ship'] ?? []);
  return ['min' => max(0, round((float)($f['min'] ?? 0), 2)), 'ids' => array_values(array_map('strval', (array)($f['ids'] ?? [])))];
}
/* cijena dostave za narudžbu: 0 kad roba dosegne prag i način je na popisu besplatnih; jedini izračun za API i potvrdu */
function sj_ship_price(array $ship, float $goods): float {
  $f = sj_free_ship();
  if ($f['min'] > 0 && $goods + 0.005 >= $f['min'] && in_array((string)($ship['id'] ?? ''), $f['ids'], true)) return 0.0;
  return round((float)($ship['price'] ?? 0), 2);
}
/* v68: potpis e-mailova kupcima (Postavke) */
/* v80 (završna recenzija, S-2): ime u pozdravu samo kad je samo slovima — inače bi „ime” s poveznicom
   (npr. „Uplatite na secure-pay.shop”) stiglo na tuđu adresu s adrese trgovine; tada samo „Dobar dan,” */
function sj_hi(string $name): string {
  $name = trim($name);
  return preg_match("/^\p{L}[\p{L} .'’\-]{0,79}$/u", $name) ? 'Dobar dan, ' . $name . ',' : 'Dobar dan,';
}
function sj_signature(): string { $s = trim((string)(sj_config()['signature'] ?? '')); return $s !== '' ? $s : (string)sj_config()['shop']; }
/* v68: rok za uplatu narudžbe; ISO ili ''. v69: rok je zapisan uz narudžbu (payUntil) i ne mijenja se kad vlasnica
   promijeni rok u Postavkama; za starije narudžbe (bez payUntil) samo za prikaz: vrijeme narudžbe + trenutni rok */
function sj_pay_until(array $o): string {
  if (!empty($o['payUntil'])) return (string)$o['payUntil'];
  $at = (int)strtotime((string)($o['at'] ?? ''));
  return $at > 0 ? sj_iso($at + max(1, (int)sj_config()['ttl_hours']) * 3600) : '';
}
/* v68: neplaćena (i nestornirana) narudžba kojoj je prošao rok za uplatu. v69: samo narudžbe sa zapisanim rokom
   (primljene od v69); starije ostaju kako su bile i vlasnica ih rješava sama */
function sj_order_expired(array $o, ?int $now = null): bool {
  if (!empty($o['paidAt']) || !empty($o['cancelledAt']) || empty($o['payUntil'])) return false;
  $u = (int)strtotime((string)$o['payUntil']);
  return $u > 0 && $u <= ($now ?? time());
}
/* v70 (odluka vlasnice, 5. krug): „Uplatio/la sam” čuva torbu do 23:59 sljedećeg radnog dana (Europe/Zagreb).
   Blagdani i neradni dani: Zakon o blagdanima, spomendanima i neradnim danima u RH, NN 110/19, čl. 1 (izmjena
   NN 72/25 dodaje samo spomendan 9. siječnja, koji nije neradni dan). Uskrs po gregorijanskom računanju (Meeus/Jones/
   Butcher); Tijelovo = Uskrs + 60 dana. */
function sj_easter(int $y): array {
  $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
  $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
  $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
  return [intdiv($h + $l - 7 * $m + 114, 31), (($h + $l - 7 * $m + 114) % 31) + 1]; /* [mjesec, dan] */
}
function sj_hr_holidays(int $y): array {
  static $memo = [];
  if (isset($memo[$y])) return $memo[$y];
  $fixed = ['01-01', '01-06', '05-01', '05-30', '06-22', '08-05', '08-15', '11-01', '11-18', '12-25', '12-26'];
  [$em, $ed] = sj_easter($y);
  $tz = new DateTimeZone('Europe/Zagreb');
  $easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $em, $ed), $tz);
  foreach ([0, 1, 60] as $plus) $fixed[] = $easter->modify("+$plus day")->format('m-d'); /* Uskrs, Uskrsni ponedjeljak, Tijelovo */
  return $memo[$y] = array_fill_keys($fixed, true);
}
function sj_is_workday(DateTimeInterface $d): bool {
  return (int)$d->format('N') <= 5 && !isset(sj_hr_holidays((int)$d->format('Y'))[$d->format('m-d')]);
}
/* 23:59 (zagrebačko vrijeme) prvog radnog dana NAKON dana trenutka $ts */
function sj_next_workday_end(int $ts): int {
  $tz = new DateTimeZone('Europe/Zagreb');
  $d = (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->setTime(0, 0);
  do { $d = $d->modify('+1 day'); } while (!sj_is_workday($d));
  return $d->setTime(23, 59)->getTimestamp();
}
/* v76 (odluka vlasnika projekta, 11. krug, točka B): rok za uplatu = trenutak + ttl sati; ako to pada u subotu, nedjelju
   ili na blagdan RH, rok se pomiče na 23:59 prvoga sljedećeg radnog dana (i bez „Uplatio/la sam”) */
function sj_pay_deadline(int $from, int $ttlHours): int {
  return sj_workday_shift($from + max(1, $ttlHours) * 3600);
}
/* trenutak $t; ako pada u subotu, nedjelju ili na blagdan RH → 23:59 prvoga sljedećeg radnog dana */
function sj_workday_shift(int $t): int {
  $d = (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Zagreb'));
  return sj_is_workday($d) ? $t : max($t, sj_next_workday_end($t));
}
/* v81 (odluka vlasnice, 13. krug, p2; ZZP čl. 15.: uplata je pravodobna kad banka kupca primi nalog do roka): unikat se
   drži do 23:59 prvoga radnog dana nakon roka za uplatu, i bez „Uplatio/la sam” — obična uplata stiže sljedeći radni dan */
function sj_hold_after_deadline(int $payTs): int { return max($payTs, sj_next_workday_end($payTs)); }
/* v78 (odluka vlasnice, 12. krug, t3): automatski storno $auto sati nakon isteka roka; ako to pada u subotu, nedjelju
   ili na blagdan, u 23:59 prvoga sljedećeg radnog dana (Uvjeti t. 5) */
function sj_auto_cancel_at(int $exp, int $auto): int { return sj_workday_shift($exp + $auto * 3600); }
/* „torbu čuvamo” / „torbe čuvamo” / „rok za uplatu produžujemo” (narudžba bez unikata) — za tekstove kupcu */
function sj_unique_count(array $o): int { $n = 0; foreach ((array)($o['order']['items'] ?? []) as $it) if (empty($it['made'])) $n++; return $n; }
/* v70 (D70-5, D70-9): rok je prošao — torbu više ne čuvamo automatski; vlasnica provjerava izvod (bez novog obećanja) */
function sj_late_text(array $o, string $lead): string {
  $n = sj_unique_count($o);
  return $lead . ($n === 0 ? ', pa ga više ne možemo automatski produžiti' : ', pa ' . ($n > 1 ? 'torbe' : 'torbu') . ' više ne možemo automatski čuvati')
    . '. Javili smo vlasnici: provjerit će uplatu i javiti vam se.' . ($n === 0 ? '' : ($n > 1 ? ' Ako je neka od torbi u međuvremenu otišla drugom kupcu, za nju vam vraćamo uplaćeni iznos što prije, a najkasnije u roku od sedam dana.' : ' Ako je torba u međuvremenu otišla drugom kupcu, vraćamo vam cijeli iznos što prije, a najkasnije u roku od sedam dana.'));
}
function sj_already_text(array $o): string {
  /* v72 (vijeće, D72-1/2): istekao rok, a torba se i dalje drži do storna — isti tekst kao e-mail „uplatu još ne vidimo” */
  if (empty($o['claimLate']) && (int)strtotime(sj_pay_until($o)) <= time() && ($hs = sj_held_sentence(sj_held_info($o))) !== '') {
    if (!empty($o['proofAt'])) return 'Vašu potvrdu o uplati već smo primili. ' . $hs . ' Kad uplatu vidimo na računu, pripremamo vašu narudžbu.';
    if (!empty($o['claimedAt'])) return 'Već ste nam javili da ste uplatili, ali uplatu još ne vidimo na računu. ' . $hs . ' Molimo vas, odgovorite na e-mail o narudžbi i pošaljite potvrdu o uplati (snimku ili potvrdu iz banke).';
  }
  if (!empty($o['claimLate']) || (int)strtotime(sj_pay_until($o)) <= time()) return sj_late_text($o, 'Već ste nam javili da ste uplatili. Rok za uplatu je prošao');
  return 'Već ste nam javili da ste uplatili; ' . sj_hold_phrase($o) . ' do ' . sj_fmt_deadline(sj_pay_until($o)) . '. Ako uplata do tada ne bude vidljiva, javit ćemo vam se e-mailom.';
}
/* v72 (D72-2): „Potvrda primljena”, rok istekao, torba se drži — klik na stari link ne smije upisati claimLate */
function sj_proof_held(array $o): bool {
  return empty($o['claimedAt']) && !empty($o['proofAt']) && (int)strtotime(sj_pay_until($o)) <= time() && sj_held_until($o) > time();
}
function sj_hold_phrase(array $o): string {
  $n = sj_unique_count($o);
  return $n > 1 ? 'torbe čuvamo za vas' : ($n === 1 ? 'torbu čuvamo za vas' : 'rok za uplatu produžen je');
}
function sj_ucfirst(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
/* v76 (odluka vlasnika projekta, 11. krug): granice od 96 h od klika, 120 h od narudžbe i 7 dana (vijeće v70/v72)
   nisu bile odluka vlasnice i uklonjene su. Zlouporabu ograničava to što „Uplatio/la sam” vrijedi jednom po narudžbi,
   a „Potvrdu primljena” klikne samo vlasnica, najviše dvaput. */
function sj_claim_preview(array $o): string { return 'do ' . sj_fmt_deadline(sj_iso(sj_claim_until($o))); }
/* Do kada „Uplatio/la sam” čuva narudžbu: 23:59 radnog dana nakon kasnijeg od (rok, klik), uz granice; nikad kraće
   od postojećeg roka. Bez $now: datum za tekstove unaprijed (potvrda narudžbe, ploča, podsjetnik) — računa se kao da
   kupac klikne odmah po narudžbi, pa je pravi rok nakon klika prije isteka uvijek isti ili kasniji (obećanje vrijedi). */
function sj_claim_until(array $o, ?int $now = null): int {
  $pu = (int)strtotime(sj_pay_until($o)); $at = (int)strtotime((string)($o['at'] ?? '')) ?: $pu;
  $click = $now ?? min($at, $pu);
  return max(sj_next_workday_end(max($pu, $click)), $pu);
}
/* „Potvrda primljena” (vlasnica je vidjela snimku): 23:59 radnog dana nakon kasnijeg od (rok, klik) */
function sj_proof_until(array $o, int $now): int {
  $pu = (int)strtotime(sj_pay_until($o));
  return max(sj_next_workday_end(max($pu, $now)), $pu);
}
/* Smije li se još kliknuti „Potvrda primljena”: '' = da, inače razlog */
function sj_proof_block(array $o, ?int $now = null): string {
  $now = $now ?? time();
  if (!empty($o['paidAt'])) return 'narudžba je već plaćena';
  if (!empty($o['cancelledAt'])) return 'narudžba je stornirana';
  if (!empty($o['anonymizedAt'])) return 'podaci narudžbe su obrisani'; /* v81 (recenzija S1) */
  if (empty($o['payUntil'])) return 'narudžba nema rok za uplatu';
  if (count((array)($o['proofAt'] ?? [])) >= 2) return 'potvrda je već dvaput zabilježena';
  return '';
}
/* v72: koje su torbe ove narudžbe još stvarno rezervirane za nju i do kada (najraniji kraj među njima).
   Vijeće (N72-2): po torbi — ako je jedna otišla, druga je i dalje rezervirana. */
function sj_held_info(array $o): array {
  $out = ['until' => 0, 'held' => [], 'gone' => [], 'taken' => []];
  $tok = (string)($o['reservation']['token'] ?? '');
  $d = array_replace(sj_res_default(), sj_read_json(sj_res_path(), [])); $now = time();
  foreach ((array)($o['order']['items'] ?? []) as $it) { if (!empty($it['made'])) continue;
    $id = (string)($it['id'] ?? ''); $nm = (string)($it['name'] ?? ''); $r = $d['res'][$id] ?? null;
    $u = $r ? (int)strtotime((string)($r['until'] ?? '')) : 0;
    if ($tok !== '' && $r && (string)($r['token'] ?? '') === $tok && $u > $now) { $out['held'][] = $nm; $out['until'] = $out['until'] ? min($out['until'], $u) : $u; continue; }
    $out['gone'][] = $nm;
    /* otišla drugom kupcu: prodana ili rezervirana narudžbom drugoga (inače je samo ponovno u ponudi) */
    if (isset($d['sold'][$id]) || ($r && !empty($r['ordered']) && $u > $now && (string)($r['token'] ?? '') !== $tok)) $out['taken'][] = $nm; }
  return $out;
}
/* v72 (vijeće, D72-1/2): „Torbu „X” i dalje čuvamo za vas do D.” ili '' ako se ništa ne drži */
function sj_held_sentence(array $hi): string {
  if (!$hi['held'] || $hi['until'] <= time()) return '';
  $q = array_map(fn($n) => '„' . $n . '”', $hi['held']);
  return (count($q) > 1 ? 'Torbe ' . implode(' i ', $q) : 'Torbu ' . $q[0]) . ' i dalje čuvamo za vas do ' . sj_fmt_deadline(sj_iso($hi['until'])) . '.';
}
/* do kada je barem jedna torba ove narudžbe još rezervirana za nju (0 = nijedna) */
function sj_held_until(array $o): int { return sj_held_info($o)['until']; }
/* v72: do kada je torba rezervirana nakon „Uplatio/la sam” / „Potvrda primljena”: do automatskog storna (rok za uplatu
   + auto_cancel_hours), ako su rokovi uključeni; nikad kraće od roka za uplatu */
function sj_hold_res_until(array $o, int $payTs): int {
  $c = sj_config(); $auto = !empty($c['deadline_tick']) ? max(0, (int)($c['auto_cancel_hours'] ?? 0)) : 0;
  if ($auto <= 0) return $payTs;
  return sj_auto_cancel_at($payTs, $auto); /* v76: bez granice od 7 dana; v78: preko vikenda do radnog dana */
}
/* Produži rok narudžbe do $newTs i rezervaciju njezinih unikata (samo komade koji su slobodni ili još njezini) —
   zajedničko za „Uplatio/la sam” (api) i „Potvrda primljena” (admin). Poziva se pod bravom narudzba-u-tijeku.json.
   Vraća ['ok' => bool, 'lost' => [nazivi], 'state' => …]. */
function sj_hold_extend(string $no, int $newTs, array $mark): array {
  $o = sj_order_find(['no' => $no]);
  if (!$o) return ['ok' => false, 'state' => 'missing'];
  if (!empty($o['paidAt'])) return ['ok' => false, 'state' => 'paid'];
  if (!empty($o['cancelledAt'])) return ['ok' => false, 'state' => 'cancelled'];
  $now = time(); $new = sj_iso($newTs);
  /* v72 (odluka vlasnice, 7. krug): torba ostaje rezervirana do automatskog storna (rok za uplatu + auto_cancel_hours),
     (v76: bez granice od 7 dana); rok za uplatu (payUntil) ostaje $new */
  $resNew = sj_iso(sj_hold_res_until($o, $newTs));
  $tok = (string)($o['reservation']['token'] ?? '');
  $uIds = []; $names = [];
  foreach ((array)($o['order']['items'] ?? []) as $it) if (empty($it['made'])) { $uIds[] = (string)($it['id'] ?? ''); $names[(string)($it['id'] ?? '')] = (string)($it['name'] ?? ''); }
  $lost = [];
  /* istekla narudžba ne drži mjesto u kolekciji: produžiti samo ako je mjesto još slobodno (inače upozorenje) */
  if ((int)strtotime(sj_pay_until($o)) <= $now) {
    $byId = sj_products_by_id_all(); sj_made_counts(true);
    foreach ((array)($o['order']['items'] ?? []) as $it) { $p = $byId[(string)($it['id'] ?? '')] ?? null;
      if ($p && sj_is_collection($p) && sj_made_left($p) < max(1, (int)($it['qty'] ?? 1))) $lost[] = (string)($p['name'] ?? ''); }
    /* v70 (vijeće, N70-1): popunjeno mjesto u kolekciji — ne produžujemo (inače bi model bio iznad granice) */
    if ($lost) return ['ok' => false, 'state' => 'full', 'lost' => $lost, 'payUntil' => sj_pay_until($o)];
  }
  if ($uIds) {
    $free = array_flip(sj_free_unique_ids(sj_products_by_id_all()));
    if ($tok === '') $tok = sj_token();
    sj_with_lock(sj_res_path(), function (array &$d) use ($uIds, $o, $tok, $resNew, $now, $free, $names, &$lost) {
      $d = array_replace(sj_res_default(), $d);
      sj_res_prune($d);
      foreach ($uIds as $id) {
        $r = $d['res'][$id] ?? null;
        $mine = $r && (string)($r['token'] ?? '') === $tok;
        if (isset($d['sold'][$id]) || !isset($free[$id]) || ($r && !$mine && !empty($r['ordered']))) { $lost[] = $names[$id] ?? $id; continue; }
        $rec = $mine ? $r : ['token' => $tok, 'ip' => (string)($o['ip'] ?? ''), 'ip_prev' => (string)($o['ip_prev'] ?? ''), 'name' => (string)($o['name'] ?? ''), 'email' => (string)($o['email'] ?? ''), 'since' => sj_iso($now), 'at' => sj_iso($now), 'ordered' => true, 'id' => $id];
        $rec['until'] = $resNew;
        $d['res'][$id] = $rec;
      }
      return true;
    }, sj_res_default());
  }
  $okEdit = false;
  sj_order_edit(['no' => $no], function (array &$x) use ($new, $resNew, $tok, $uIds, $mark, &$okEdit) {
    if (!empty($x['cancelledAt']) || !empty($x['paidAt'])) return; /* u međuvremenu stornirana ili plaćena */
    if (isset($mark['claimedAt']) && !empty($x['claimedAt'])) return; /* „Uplatio/la sam” samo jednom */
    $okEdit = true;
    $x['payUntil'] = $new; unset($x['expiredAt']);
    foreach ($mark as $k => $v) { if ($v === null) { unset($x[$k]); continue; } if ($k === 'proofAt') { $x['proofAt'] = array_merge((array)($x['proofAt'] ?? []), [$v]); } else $x[$k] = $v; }
    if ($uIds && empty($x['reservation']['token'])) $x['reservation'] = ['until' => $resNew, 'token' => $tok];
    elseif ($uIds) $x['reservation']['until'] = $resNew;
  });
  if (!$okEdit) {
    if ($uIds) sj_with_lock(sj_res_path(), function (array &$d) use ($tok, $resNew) {
      $d = array_replace(sj_res_default(), $d);
      foreach ($d['res'] as $id => $r) if ((string)($r['token'] ?? '') === $tok && (string)($r['until'] ?? '') === $resNew) unset($d['res'][$id]);
      return true;
    }, sj_res_default());
    $x = sj_order_find(['no' => $no]) ?? [];
    return ['ok' => false, 'state' => !empty($x['paidAt']) ? 'paid' : (!empty($x['cancelledAt']) ? 'cancelled' : 'already'), 'payUntil' => sj_pay_until($x)];
  }
  sj_made_counts(true);
  return ['ok' => true, 'state' => 'extended', 'payUntil' => $new, 'lost' => array_values(array_unique($lost)), 'o' => $o];
}
/* Kada se neplaćena istekla narudžba automatski stornira: auto_cancel_hours nakon upisanog „rok istekao”.
   v72 (vijeće, N72-1): ako je torba držana do storna, najkasnije kad ta rezervacija istječe (tick može kasniti,
   promjena postavki; v76: bez granice od 7 dana) — da torba ne bude u prodaji dok narudžba još stoji. */
function sj_cancel_due(array $o, int $auto): int {
  $exp = (int)strtotime((string)($o['expiredAt'] ?? '')) ?: (int)strtotime(sj_pay_until($o));
  $due = sj_auto_cancel_at($exp, $auto); /* v78 (t3) */
  /* v81 (recenzija R81-2): bez klika storno nikad prije obećanog držanja (23:59 radnog dana nakon roka, Uvjeti t. 5) */
  if (empty($o['claimedAt']) && empty($o['proofAt']) && ($pu = (int)strtotime(sj_pay_until($o))) > 0) $due = max($due, sj_hold_after_deadline($pu));
  $hu = (!empty($o['claimedAt']) || !empty($o['proofAt'])) ? (int)strtotime((string)($o['reservation']['until'] ?? '')) : 0;
  return $hu > $exp ? min($due, $hu) : $due;
}
/* v81 (odluka vlasnice, 13. krug, p4; Izjava t. 5): automatsko brisanje osobnih podataka — neostvarene narudžbe (storno,
   nepotvrđene i neplaćene) 30 dana nakon storna, ostale 3 godine nakon uplate (rok teče od preuzimanja, koje ne bilježimo,
   pa je od uplate sigurna gornja granica), izjave o raskidu nakon 3 godine. Ostaju broj, datumi, iznos i nazivi artikala
   (bez osobnih podataka). Najviše jednom dnevno. Kopije e-mailova u pretincu vlasnica briše ručno. */
const SJ_PURGE_UNREAL_DAYS = 30;
const SJ_PURGE_KEEP_DAYS = 1096; /* 3 godine */
function sj_anonymize_order(array &$o, int $now): void {
  foreach (['name', 'email', 'phone', 'address', 'note', 'claim', 'ip', 'ip_prev', 'withdrawals', 'key'] as $k) unset($o[$k]);
  $o['name'] = '(anonimizirano)';
  if (isset($o['order']) && is_array($o['order'])) {
    unset($o['order']['customer']); $o['order']['note'] = '';
    if (isset($o['order']['shipping']['locker'])) $o['order']['shipping']['locker'] = '';
    foreach ((array)($o['order']['items'] ?? []) as $i => $it) if (is_array($it)) $o['order']['items'][$i]['note'] = '';
    $o['items'] = implode("\n", array_map(fn($it) => '- ' . (string)($it['name'] ?? '') . ((int)($it['qty'] ?? 1) > 1 ? ' × ' . (int)$it['qty'] : ''), (array)($o['order']['items'] ?? [])));
    if (!empty($o['order']['shipping']['label'])) $o['shipping'] = (string)$o['order']['shipping']['label'];
  } else { $o['items'] = ''; $o['shipping'] = ''; }
  if (isset($o['reservation']) && is_array($o['reservation'])) unset($o['reservation']['token']);
  if (str_starts_with((string)($o['cancelReason'] ?? ''), 'drugo')) $o['cancelReason'] = 'drugo';
  $o['anonymizedAt'] = sj_iso($now);
}
function sj_privacy_purge(bool $force = false, ?int $now = null): int {
  $now = $now ?? time();
  $run = sj_with_lock(SJ_DATA . '/brisanje.json', function (array &$d) use ($now, $force) {
    if (!$force && (int)($d['last'] ?? 0) > $now - 86400) return false;
    $d['last'] = $now; return true;
  }, []);
  if (!$run) return 0;
  $n = 0;
  foreach (sj_order_files() as $f) {
    if (!is_file($f)) continue;
    sj_with_lock($f, function (array &$list) use (&$n, $now) {
      foreach ($list as &$o) {
        if (!is_array($o) || !empty($o['anonymizedAt'])) continue;
        /* recenzija R81-6: storno uz javljenu ili označenu uplatu (mogući povrat ili spor) čuva se 3 godine kao izvršena narudžba */
        $disputed = !empty($o['claimedAt']) || !empty($o['proofAt']) || !empty($o['unpaidAt']) || !empty($o['uncancelledAt']) || !empty($o['paidAt']);
        $unreal = (!empty($o['cancelledAt']) || (!empty($o['unconfirmed']) && empty($o['paidAt']))) && !$disputed;
        $base = $unreal ? ((int)strtotime((string)($o['cancelledAt'] ?? '')) ?: (int)strtotime((string)($o['at'] ?? '')))
                        : ((int)strtotime((string)($o['paidAt'] ?? '')) ?: (int)strtotime((string)($o['at'] ?? '')));
        if ($base <= 0) continue;
        /* otvorena neplaćena narudžba: rješavaju je rokovi i storno; ako ostane bez odluke (kasni klik, „Ukloni oznaku”,
           stara bez roka), v82 (završna recenzija, R82-3): briše se 3 godine od narudžbe, kao i ostale */
        if (!$unreal && empty($o['paidAt']) && empty($o['cancelledAt'])) $base = (int)strtotime((string)($o['at'] ?? '')) ?: $base;
        if (!$unreal && empty($o['paidAt'])) $base = (int)strtotime((string)($o['cancelledAt'] ?? '')) ?: $base; /* sporni storno: 3 godine od storna */
        if ($base + ($unreal ? SJ_PURGE_UNREAL_DAYS : SJ_PURGE_KEEP_DAYS) * 86400 > $now) continue;
        sj_anonymize_order($o, $now); $n++;
      }
      return true;
    }, []);
  }
  /* recenzija R81-5: i ime i e-mail u oznakama „plaćeno” (rezervacije.json) nakon 3 godine */
  if (is_file(sj_res_path())) sj_with_lock(sj_res_path(), function (array &$d) use (&$n, $now) {
    foreach ((array)($d['sold'] ?? []) as $id => $x) { if (!is_array($x)) continue; $pt = (int)strtotime((string)($x['paidAt'] ?? '')); if ($pt > 0 && $pt + SJ_PURGE_KEEP_DAYS * 86400 <= $now && (($x['name'] ?? '') !== '' || ($x['email'] ?? '') !== '')) { $d['sold'][$id]['name'] = ''; $d['sold'][$id]['email'] = ''; $n++; } }
    return true;
  }, sj_res_default());
  if (is_file(SJ_DATA . '/raskidi.json')) sj_with_lock(SJ_DATA . '/raskidi.json', function (array &$list) use (&$n, $now) {
    $keep = array_values(array_filter($list, fn($r) => !is_array($r) || ($t = (int)strtotime((string)($r['at'] ?? ''))) <= 0 || $t + SJ_PURGE_KEEP_DAYS * 86400 > $now));
    $n += count($list) - count($keep); $list = $keep; return true;
  }, []);
  return $n;
}
/* v69: najviše toliko e-mailova kupcima u jednom prolazu rokova (ostatak u sljedećem) */
const SJ_TICK_MAX_MAILS = 20;
/* v69: poveznica „Uplatio/la sam” za kupca (tajna je uz narudžbu); '' bez provjerene adrese stranice */
function sj_claim_url(array $o): string {
  $u = sj_site_url_trusted(); $t = (string)($o['claim'] ?? '');
  return ($u !== '' && preg_match('/^[a-f0-9]{32}$/', $t)) ? $u . '/?uplata=' . rawurlencode((string)($o['no'] ?? '')) . '&t=' . $t : '';
}

/* v68: rokovi za uplatu (poluautomatski storno, odluka vlasnice 25. 10.). Poziva se pri api/status i otvaranju admina,
   najviše jednom u 5 minuta (stranica nema „budilicu”; može i cron koji otvara api/status). Za neplaćene, nestornirane
   i potvrđene narudžbe iz narudzbe.json:
   - nakon remind_hours: podsjetnik kupcu BEZ barkoda i podataka za uplatu (da ne plati dvaput), samo prije isteka roka;
   - nakon ttl_hours (rok za uplatu): komad je već vraćen u prodaju (rezervacija istječe sama, mjesto u kolekciji se
     više ne broji); kupac dobiva umirujuću poruku, vlasnica kratku obavijest; narudžba je u adminu pod „Rok istekao”;
   - auto_cancel_hours nakon isteka roka, ako vlasnica ne klikne Plaćeno ni Storniraj: automatski storno uz e-mail kupcu.
   Oznake (remindedAt, expiredAt) se upisuju prije slanja, pa se nijedna poruka ne šalje dvaput.
   v69 (vijeće na v68): samo narudžbe sa zapisanim rokom (payUntil) — stara arhiva se pri objavi ne stornira; storno
   tek auto_cancel_hours nakon upisanog „rok istekao” (nikad bez njega); narudžba kojoj je vlasnica uklonila oznaku
   plaćeno (unpaidAt) se ne dira; najviše SJ_TICK_MAX_MAILS e-mailova kupcima po prolazu; vlasnici sažetak storna. */
function sj_deadline_tick(bool $force = false): array {
  $c = sj_config();
  if (empty($c['deadline_tick']) && !$force) return [];
  $now = time();
  $run = sj_with_lock(SJ_DATA . '/rokovi.json', function (array &$d) use ($now, $force) {
    if (!$force && (int)($d['last'] ?? 0) > $now - 300) return false;
    $d['last'] = $now; return true;
  }, []);
  if (!$run) return [];
  $ttl = max(1, (int)$c['ttl_hours']); $rem = max(0, (int)($c['remind_hours'] ?? 0)); $auto = max(0, (int)($c['auto_cancel_hours'] ?? 0));
  $todo = [];
  sj_with_lock(SJ_DATA . '/narudzbe.json', function (array &$list) use ($now, $rem, $auto, &$todo) {
    foreach ($list as &$o) {
      if (count($todo) >= SJ_TICK_MAX_MAILS) break;
      if (!empty($o['paidAt']) || !empty($o['cancelledAt']) || !empty($o['unconfirmed']) || !empty($o['unpaidAt']) || empty($o['email']) || empty($o['payUntil'])) continue;
      $at = (int)strtotime((string)($o['at'] ?? '')); $until = (int)strtotime((string)$o['payUntil']);
      if ($at <= 0 || $until <= 0) continue;
      if ($now >= $until) {
        if (empty($o['expiredAt'])) { $o['expiredAt'] = sj_iso($now); $todo[] = ['expired', $o]; continue; }
        /* v69.1: kupac je nakon isteka javio uplatu — bez automatskog storna; vlasnica provjeri izvod i odluči */
        if (!empty($o['claimLate'])) continue;
        $exp = (int)strtotime((string)$o['expiredAt']);
        if ($auto > 0 && $exp > 0 && $now >= sj_cancel_due($o, $auto)) {
          $o['cancelledAt'] = sj_iso($now); $o['cancelReason'] = 'nije uplaćeno u roku'; $o['cancelBy'] = 'automatski';
          $todo[] = ['cancel', $o];
        }
        continue;
      }
      if ($rem > 0 && empty($o['remindedAt']) && empty($o['claimedAt']) && empty($o['proofAt']) && $now >= $at + $rem * 3600 && $at + $rem * 3600 < $until) { $o['remindedAt'] = sj_iso($now); $todo[] = ['remind', $o]; }
    }
    return true;
  }, []);
  if (!$todo) return [];
  /* automatski storno oslobađa rezervacije te narudžbe (unikati) */
  $toks = []; foreach ($todo as [$k, $o]) if ($k === 'cancel' && !empty($o['reservation']['token'])) $toks[] = (string)$o['reservation']['token'];
  if ($toks) sj_with_lock(sj_res_path(), function (array &$d) use ($toks) {
    $d = array_replace(sj_res_default(), $d);
    foreach ($d['res'] as $id => $r) if (in_array((string)($r['token'] ?? ''), $toks, true)) unset($d['res'][$id]);
    return true;
  }, sj_res_default());
  foreach ($todo as [$k, $o]) sj_deadline_mail($k, $o, $c, $auto);
  /* v69: vlasnica dobiva jedan sažetak automatskih storna (prije o njima nije saznala ništa) */
  $canc = array_values(array_filter($todo, fn($x) => $x[0] === 'cancel'));
  if ($canc) sj_send_mail((string)$c['owner_email'], $c['shop'] . ' — automatski stornirano: ' . implode(', ', array_map(fn($x) => (string)($x[1]['no'] ?? ''), $canc)),
    "Ove narudžbe automatski su stornirane jer uplata nije označena ni " . sj_hours_text($auto) . " nakon isteka roka (subota, nedjelja i blagdan pomiču storno na 23:59 radnog dana):\n\n"
    . implode("\n", array_map(fn($x) => '- ' . (string)($x[1]['no'] ?? '') . ' — ' . (string)($x[1]['name'] ?? '') . ', ' . (string)($x[1]['total'] ?? '') . ', poziv na broj ' . sj_payment_ref((string)($x[1]['no'] ?? '')), $canc))
    . "\n\nKupci su dobili e-mail o stornu. Ako ipak vidite uplatu, u adminu → Narudžbe kliknite „Označi plaćenom”: ako je komad još slobodan, ostaje kupcu."
    . (sj_site_url_trusted() !== '' ? "\n\n" . sj_site_url_trusted() . '/admin/?s=narudzbe' : ''));
  return array_map(fn($x) => [$x[0], (string)($x[1]['no'] ?? '')], $todo);
}
function sj_deadline_mail(string $kind, array $o, array $c, int $auto): void {
  /* vijeće V78-S9: narudžba je možda u međuvremenu stornirana (raskid) ili plaćena — tada bez e-maila o roku */
  if ($kind !== 'cancel') { $fresh = sj_order_find(['no' => (string)($o['no'] ?? '')]); if ($fresh && (!empty($fresh['cancelledAt']) || !empty($fresh['paidAt']))) return; }
  $no = (string)($o['no'] ?? ''); $name = (string)($o['name'] ?? ''); $until = sj_fmt_deadline(sj_pay_until($o)); $untilAcc = sj_fmt_deadline(sj_pay_until($o), 'acc');
  $uq = []; foreach ((array)($o['order']['items'] ?? []) as $it) if (empty($it['made'])) $uq[] = '„' . (string)($it['name'] ?? '') . '”';
  $many = count($uq) > 1;
  $hi = sj_hi($name) . "\n\n"; $bye = "\n\nSrdačan pozdrav,\n" . sj_signature();
  /* v70 (odluka vlasnice, 5. krug): jedno obećanje — „Uplatio/la sam” čuva do točnog datuma; snimka je drugi put */
  $claim = empty($o['claimedAt']) ? sj_claim_url($o) : '';
  $alt = 'Link ne radi? Odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke.';
  if ($kind === 'remind') {
    $hold = $uq ? ($many ? 'torbe' : 'torbu') . ' čuvamo' : 'rok za uplatu produžujemo';
    /* v70 (D70-2): tekst po tome je li kupac kliknuo, ne po tome postoji li link */
    $claimTxt = !empty($o['claimedAt']) ? 'Javili ste nam da ste uplatili; ' . sj_hold_phrase($o) . ' do ' . $until . '. Ako uplata do tada ne stigne, javit ćemo vam se.'
      : ($claim !== '' ?  'Kliknite „Uplatio/la sam” i ' . $hold . ' ' . sj_claim_preview($o) . ":\n$claim\n($alt)"
      : 'Odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke, pa ćemo provjeriti izvod.');
    /* v69 (odluka vlasnice): podaci za uplatu u podsjetniku, ali bez barkoda (da se ne plati dvaput) */
    sj_send_mail((string)$o['email'], $c['shop'] . " — podsjetnik za uplatu, narudžba $no",
      $hi . "podsjetnik: vaša narudžba br. $no čeka uplatu do $until." . ($uq ? ' Do tada ' . ($many ? 'su torbe ' . implode(' i ', $uq) . ' rezervirane' : 'je torba ' . $uq[0] . ' rezervirana') . ' samo za vas.' : '')
      /* v69 (D69-4): najprije za one koji su platili (da ne zanemare poruku i ne propuste „Uplatio/la sam”) */
      . "\n\nVeć ste platili? Nemojte plaćati ponovno. " . $claimTxt
      . "\n\nJoš niste platili? " . sj_pay_lines($o, $c) . $bye, null, (string)$c['owner_email']);
  } elseif ($kind === 'expired') {
    $back = $uq ? ($many ? 'su torbe ' . implode(' i ', $uq) : 'je torba ' . $uq[0]) . ' ponovno u ponudi' : ''; /* v69 (D69-3): red riječi */
    $exp = (int)strtotime((string)($o['expiredAt'] ?? '')) ?: time();
    $storno = $auto > 0 ? ' Ako do ' . sj_fmt_deadline(sj_iso(sj_cancel_due($o + ['expiredAt' => sj_iso($exp)], $auto))) . ' ne vidimo ni uplatu ni vašu potvrdu o uplati, narudžbu storniramo.' : '';
    if (!empty($o['claimedAt']) || !empty($o['proofAt'])) {
      /* v72 (odluka vlasnice, 7. krug): kraći tekst; torba ostaje rezervirana do storna („ne stavljaj tu torbu
         ponovno u prodaju do isteka roka”) — datum iz stvarne rezervacije, ne obećanje */
      $hI = sj_held_info($o); $hs = sj_held_sentence($hI); $pf = !empty($o['proofAt']);
      $taken = $hs !== '' ? $hI['taken'] : []; $free = $hs !== '' ? array_values(array_diff($hI['gone'], $taken)) : array_map(fn($x) => trim($x, '„”'), $uq);
      $Q = fn(array $a) => implode(' i ', array_map(fn($n) => '„' . $n . '”', $a));
      /* D72-3: potvrdu već imamo — ne tražiti je ponovno; v72.1 (odluka vlasnice, 8. krug): „Ako do tada uplata ne stigne” */
      $cond = $pf ? 'uplata ne stigne' : 'ne dobijemo ni uplatu ni potvrdu';
      $stornoP = $auto > 0 ? ' Ako do ' . sj_fmt_deadline(sj_iso(sj_cancel_due($o + ['expiredAt' => sj_iso($exp)], $auto))) . ' ' . $cond . ', narudžbu storniramo.' : '';
      sj_send_mail((string)$o['email'], $c['shop'] . " — uplatu još ne vidimo, narudžba $no",
        /* D72-4: inačica „primili smo potvrdu” kad god je potvrda zabilježena */
        $hi . ($pf ? "primili smo vašu potvrdu o uplati za narudžbu br. $no, ali uplatu još ne vidimo na računu. Molimo vas, provjerite sa svojom bankom je li uplata izvršena i odgovorite na ovaj e-mail."
          : "javili ste nam da ste platili narudžbu br. $no, ali uplatu još ne vidimo na računu. Molimo vas, odgovorite na ovaj e-mail i pošaljite potvrdu o uplati (snimku ili potvrdu iz banke).")
        . ($hs !== '' ? "\n\n" . $hs : '')
        /* D72-5: po torbi */
        . ($taken ? ($hs !== '' ? ' ' : "\n\n") . 'Nažalost, ' . (count($taken) > 1 ? 'torbe ' . $Q($taken) . ' u međuvremenu su otišle' : 'torba ' . $Q($taken) . ' u međuvremenu je otišla') . ' drugom kupcu; ako ste ' . (count($taken) > 1 ? 'ih' : 'je') . ' već platili, za ' . (count($taken) > 1 ? 'njih' : 'nju') . ' vam vraćamo uplaćeni iznos što prije, a najkasnije u roku od sedam dana.' : '')
        . ($free ? ($hs !== '' ? ' ' : "\n\n") . (count($free) > 1 ? 'Torbe ' . $Q($free) . ' zasad su' : 'Torba ' . $Q($free) . ' zasad je') . ' ponovno u ponudi; ako ' . (count($free) > 1 ? 'neku od njih' : 'je') . ' netko kupi prije nego što vaša uplata stigne, za ' . (count($free) > 1 ? 'nju' : 'nju') . ' vam vraćamo uplaćeni iznos što prije, a najkasnije u roku od sedam dana.' : '')
        . ($hs !== '' ? ' Ako do tada ' . $cond . ', narudžbu storniramo i ' . (count($hI['held']) > 1 ? 'torbe se vraćaju' : 'torba se vraća') . ' u prodaju.' : $stornoP)
        . $bye, null, (string)$c['owner_email']);
    } else {
      /* v70: klik nakon isteka više ne čuva torbu (v69.1) — javlja vlasnici, koja provjeri izvod i javi se */
      sj_send_mail((string)$o['email'], $c['shop'] . " — rok za uplatu je prošao, narudžba $no",
        $hi . "rok za uplatu narudžbe br. $no prošao je u $untilAcc, a uplatu još ne vidimo" . (($hsN = sj_held_sentence(sj_held_info($o))) !== '' ? '. ' . rtrim($hsN, '.') . ', jer obična uplata poslana do roka može stići sljedeći radni dan' : ($back !== '' ? ", pa $back" : '')) . '.' /* v81 (p2) */
        . "\n\nVeć ste platili? " . ($claim !== '' ? "Kliknite „Uplatio/la sam”:\n$claim\n(ili odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke)." : 'Odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke.')
        . ' Ako ste nam snimku potvrde već poslali, ne trebate ništa više. Provjerit ćemo izvod i javiti vam se' . ($uq ? '; ako je ' . ($many ? 'neka od torbi' : 'torba') . ' u međuvremenu otišla drugom kupcu, vraćamo vam cijeli iznos što prije, a najkasnije u roku od sedam dana.' : '.')
        . "\n\nAko ste još uvijek zainteresirani za narudžbu, molimo vas da izvršite uplatu i pošaljete potvrdu o plaćanju" . ($uq ? '; ako ' . ($many ? 'torbe' : 'torbu') . ' u međuvremenu nije kupio netko drugi, ' . ($many ? 'one su vaše' : 'ona je vaša') . '.' : '.')
        . $storno
        . "\n\n" . sj_pay_lines($o, $c) . $bye, null, (string)$c['owner_email']);
    }
    sj_send_mail((string)$c['owner_email'], $c['shop'] . " — rok za uplatu prošao: $no — $name",
      "Rok za uplatu narudžbe $no ($name, " . (string)($o['total'] ?? '') . ", poziv na broj " . sj_payment_ref($no) . ") prošao je u $untilAcc." . ($uq ? (($hO = sj_held_info($o))['until'] > time()
        ? ' ' . (!empty($o['claimedAt']) ? 'Kupac je javio uplatu („Uplatio/la sam”)' : (!empty($o['proofAt']) ? 'Kliknuli ste „Potvrda primljena”' : 'Uplata poslana do roka može stići sljedeći radni dan (ZZP čl. 15.)')) . ', pa ' . (count($hO['held']) > 1 ? 'su torbe „' . implode('” i „', $hO['held']) . '” i dalje rezervirane' : 'je torba „' . $hO['held'][0] . '” i dalje rezervirana') . ' za kupca do ' . sj_fmt_deadline(sj_iso($hO['until'])) . ((!empty($o['claimedAt']) || !empty($o['proofAt'])) ? ' (do automatskog storna).' : '.')
          . ($hO['taken'] ? ' ' . (count($hO['taken']) > 1 ? 'Torbe „' . implode('” i „', $hO['taken']) . '” otišle su' : 'Torba „' . $hO['taken'][0] . '” otišla je') . ' drugom kupcu: ako kupac plati, vratite mu iznos za ' . (count($hO['taken']) > 1 ? 'njih' : 'nju') . '.' : '')
          . (($fO = array_diff($hO['gone'], $hO['taken'])) ? ' ' . (count($fO) > 1 ? 'Torbe „' . implode('” i „', $fO) . '” vraćene su' : 'Torba „' . implode('', $fO) . '” vraćena je') . ' u prodaju.' : '')
        : ' ' . ($many ? 'Torbe su vraćene' : 'Torba je vraćena') . ' u prodaju.') : '')
      . "\n\nAko vidite uplatu, u adminu → Narudžbe → „Rok istekao” kliknite „Označi plaćenom”; ako ne, „Storniraj narudžbu…”." . ($auto > 0 ? " Ako ne kliknete ništa, narudžba se automatski stornira u " . sj_fmt_deadline(sj_iso(sj_cancel_due($o + ['expiredAt' => sj_iso(time())], $auto)), 'acc') . ' i kupac dobiva e-mail.' : '')
      . (sj_site_url_trusted() !== '' ? "\n\n" . sj_site_url_trusted() . '/admin/?s=narudzbe' : ''));
  } else {
    sj_send_mail((string)$o['email'], $c['shop'] . " — narudžba $no je stornirana",
      $hi . "narudžba br. $no je stornirana jer uplata nije stigla ni nakon roka." . ($uq ? ' ' . ($many ? 'Torbe su ponovno' : 'Torba je ponovno') . ' u prodaji.' : '')
      . "\n\nAko ste ipak platili, javite nam se odgovorom na ovaj e-mail i riješit ćemo to: " . ($uq ? 'ako je torba još slobodna, šaljemo vam je, a inače vam vraćamo cijeli iznos što prije, a najkasnije u roku od sedam dana.' : 'narudžbu obnavljamo ili vam vraćamo cijeli iznos što prije, a najkasnije u roku od sedam dana.') . $bye, null, (string)$c['owner_email']);
  }
}
/* v69: podaci za uplatu u tekstu e-maila (podsjetnik, rok prošao) — bez barkoda */
function sj_pay_lines(array $o, array $c): string {
  $p = (array)($c['payment'] ?? []); $no = (string)($o['no'] ?? '');
  $total = (float)($o['order']['total'] ?? 0);
  return "PODACI ZA UPLATU\n- Primatelj: " . (string)($p['recipient'] ?? '') . (($p['address'] ?? '') !== '' ? ', ' . $p['address'] : '')
    . "\n- IBAN: " . trim(chunk_split(preg_replace('/\s+/', '', (string)($p['iban'] ?? '')) ?? '', 4, ' '))
    . "\n- Iznos: " . ($total > 0 ? sj_fmt_eur($total) : (string)($o['total'] ?? ''))
    . "\n- Model: " . (string)($p['model'] ?? 'HR00') . "\n- Poziv na broj: " . sj_payment_ref($no) . ' (upišite točno ovako, bez „SJ-”)'
    . "\n- Opis plaćanja: " . sj_payment_desc($no);
}
/* v71 (odluka vlasnice, 6. krug): „uplata je stigla” — automatski uz „Označi plaćenom” (kvačica, zadano uključena),
   najviše jednom po narudžbi (paidMailAt). Rok slanja iz Postavki (ship_lead), rok izrade iz podatka artikla. */
function sj_paid_mail(array $o): string { /* 'sent' | 'already' | 'failed' | 'skip' */
  $c = sj_config();
  if (!empty($o['paidMailAt'])) return 'already';
  if (empty($o['email']) || empty($o['paidAt']) || !empty($o['cancelledAt'])) return 'skip';
  $no = (string)($o['no'] ?? '');
  $first = false;
  /* vijeće N71-3: pod bravom i stanje plaćeno / storno (drugi prozor admina) */
  sj_order_edit(['no' => $no], function (array &$x) use (&$first) { if (empty($x['paidMailAt']) && !empty($x['paidAt']) && empty($x['cancelledAt'])) { $x['paidMailAt'] = sj_iso(time()); $first = true; } });
  if (!$first) return 'already';
  $items = (array)($o['order']['items'] ?? []);
  $made = array_values(array_filter($items, fn($it) => !empty($it['made'])));
  $anyUnique = count($made) < count($items);
  $pickup = (($o['order']['shipping']['id'] ?? '') === 'pickup');
  $lead = trim((string)($c['ship_lead'] ?? ''));
  /* vijeće D71-4…6: isto obećanje kao potvrda narudžbe (sj_customer_mail), nazivi artikala, rok izrade po artiklu */
  $q = function (array $its) { $n = array_map(fn($it) => '„' . (string)($it['name'] ?? '') . '”', $its); return count($n) > 1 ? implode(', ', array_slice($n, 0, -1)) . ' i ' . end($n) : implode('', $n); };
  if ($made) {
    $withLead = array_values(array_filter($made, fn($it) => !empty($it['lead'])));
    $ldU = array_values(array_unique(array_map(fn($it) => (string)$it['lead'], $withLead)));
    $ld = count($ldU) > 1 ? ' (rok izrade od primitka uplate: ' . implode(', ', array_map(fn($it) => '„' . $it['name'] . '” ' . $it['lead'], $withLead)) . ')' : ($ldU ? ' (rok izrade ' . $ldU[0] . ' od primitka uplate)' : '');
    $next = 'Sada izrađujemo ' . $q($made) . $ld
      . ($pickup ? ($anyUnique ? ', a kad je sve gotovo, cijelu narudžbu pripremamo za preuzimanje u radionici.' : '.') . ' Javit ćemo vam se kad narudžba bude spremna za preuzimanje.'
        : ($anyUnique ? ', a zatim cijelu narudžbu šaljemo zajedno, u jednom paketu' : '') . ($lead !== '' ? '; paket šaljemo u roku ' . $lead . ' nakon izrade.' : '.') . ' Kad paket krene, poslat ćemo vam poruku s potvrdom slanja.');
  } else {
    $next = $pickup ? 'Javit ćemo vam se kad narudžba bude spremna za preuzimanje.'
      : 'Paket ' . ($lead !== '' ? 'šaljemo u roku ' . $lead : 'šaljemo uskoro') . '; kad krene, poslat ćemo vam poruku s potvrdom slanja.';
  }
  $ok = sj_send_mail((string)$o['email'], $c['shop'] . " — uplata je stigla, narudžba $no",
    sj_hi((string)($o['name'] ?? '')) . "\n\nuplata za narudžbu br. $no je stigla, hvala! " . $next . "\n\nSrdačan pozdrav,\n" . sj_signature(), null, (string)$c['owner_email']);
  /* vijeće N71-2: neuspjelo slanje — oznaka se briše, kvačica se vraća i vlasnica dobiva upozorenje */
  if (!$ok) { sj_order_edit(['no' => $no], function (array &$x) { unset($x['paidMailAt']); }); return 'failed'; }
  return 'sent';
}
/* v69 (odluka vlasnice): kad vlasnica sama stornira narudžbu, kupac dobiva e-mail (kvačica, zadano uključena).
   Za „lažna narudžba” e-mail se nikad ne šalje (ne potvrđujemo adresu napadaču). */
function sj_cancel_mail(array $o, string $reason): bool {
  $c = sj_config();
  if ($reason === 'lažna narudžba' || empty($o['email'])) return false;
  $no = (string)($o['no'] ?? '');
  $uq = []; foreach ((array)($o['order']['items'] ?? []) as $it) if (empty($it['made'])) $uq[] = '„' . (string)($it['name'] ?? '') . '”';
  $why = ['nije uplaćeno u roku' => ' jer uplata nije stigla u roku', 'kupac odustao' => ' na vaš zahtjev', 'torba oštećena ili nedostupna' => '; žao nam je, ' . ($uq ? (count($uq) > 1 ? 'torbe nisu dostupne' : 'torba nije dostupna') : 'artikl nije dostupan')][$reason] ?? '';
  return sj_send_mail((string)$o['email'], $c['shop'] . " — narudžba $no je stornirana",
    sj_hi((string)($o['name'] ?? '')) . "\n\nnarudžba br. $no je stornirana$why."
    /* v69 (D69-13): za „nije uplaćeno u roku” isto obećanje kao kod automatskog storna */
    . ($reason === 'nije uplaćeno u roku'
      ? "\n\nAko ste ipak platili, javite nam se odgovorom na ovaj e-mail i riješit ćemo to: " . ($uq ? 'ako je torba još slobodna, šaljemo vam je, a inače vam vraćamo cijeli iznos što prije, a najkasnije u roku od sedam dana.' : 'narudžbu obnavljamo ili vam vraćamo cijeli iznos što prije, a najkasnije u roku od sedam dana.')
      : "\n\nAko ste je već platili, javite nam se odgovorom na ovaj e-mail i vratit ćemo vam cijeli iznos što prije, a najkasnije u roku od sedam dana.")
    . "\n\nSrdačan pozdrav,\n" . sj_signature(), null, (string)$c['owner_email']);
}

/* Ograničenje broja zahtjeva po IP adresi (npr. 10 narudžbi na sat). true = smije. */
/* Skraćena adresa: HMAC adrese (IPv6 po mreži /48) s nasumičnom tajnom (data/sol.json, random_bytes, mijenja se
   svaki tjedan), 16 znakova. Kad tajna istekne, stare skraćene adrese više se ne mogu povezati s pravima. */
function sj_short_ip(?string $ip = null, bool $prev = false): string {
  $ip = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? '?');
  /* v69 (vijeće, N69-6): IPv4 zapisan kao IPv6 (::ffff:a.b.c.d) broji se kao IPv4, a ne kao jedna zajednička mreža /48 */
  if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m4)) $ip = $m4[1];
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

/* Vidljivi proizvodi po id-u (za admin). Za razliku od sj_products_by_id u api/index.php ne izostavlja nepotpune (v83):
   postojeće narudžbe i rezervacije takvog artikla vlasnica i dalje vodi do kraja (produženje, plaćeno). */
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
/* v62: Stalna kolekcija — model po narudžbi (made, kategorija torbe) prima ograničen broj narudžbi.
   Broje se zaprimljene narudžbe (i arhiva) od trenutka kad je vlasnica otvorila mjesta (made_open_from), bez storniranih.
   Mjesta se ne oslobađaju sama: vlasnica ih otvara u adminu („Otvori nova mjesta”). */
function sj_is_collection(array $p): bool { return !empty($p['made']) && (($p['cat'] ?? 'torbe') === 'torbe'); }
function sj_made_limit(array $p): int { $v = $p['made_limit'] ?? 3; return ($v === '' || $v === null) ? 3 : max(0, (int)$v); }
/* v63: jedan prolaz kroz narudžbe za sve modele (prije: jedan prolaz po modelu pri svakom api/status).
   Ne broje se stornirane ni narudžbe primljene bez potvrde e-mailom (preopterećenje). Arhive starije od
   najstarijeg otvaranja mjesta se ne čitaju. Rezultat se pamti do kraja zahtjeva ($reset nakon upisa). */
function sj_made_counts(bool $reset = false): array {
  static $cache = null;
  if ($reset) { $cache = null; return []; }
  if ($cache !== null) return $cache;
  $from = []; $minT = PHP_INT_MAX;
  foreach (sj_products() as $p) if (!empty($p['id']) && sj_is_collection($p)) { $t = (int)strtotime((string)($p['made_open_from'] ?? '')) ?: 0; $from[(string)$p['id']] = $t; $minT = min($minT, $t); }
  $cache = array_fill_keys(array_keys($from), 0);
  if (!$from) return $cache;
  $minYear = $minT > 0 ? (int)date('Y', $minT) : 0;
  foreach (sj_order_files() as $f) {
    if (preg_match('/narudzbe-arhiva-(\d{4})/', $f, $m) && (int)$m[1] < $minYear) continue;
    foreach (sj_read_json($f, []) as $o) {
      /* v65: narudžba bez potvrde (preopterećenje) ne broji se dok nije plaćena — plaćena zauzima mjesto kao svaka druga */
      if (!empty($o['cancelledAt']) || (!empty($o['unconfirmed']) && empty($o['paidAt']))) continue;
      /* v68: neplaćena narudžba nakon roka za uplatu više ne drži mjesto (vraća se u prodaju, kao i unikat) */
      if (sj_order_expired($o)) continue;
      $at = (int)strtotime((string)($o['at'] ?? ''));
      foreach ((array)($o['order']['items'] ?? []) as $it) { $id = (string)($it['id'] ?? ''); if (isset($from[$id]) && $at >= $from[$id]) $cache[$id] += max(1, (int)($it['qty'] ?? 1)); }
    }
  }
  return $cache;
}
function sj_made_used(string $id, string $from = ''): int { return (int)(sj_made_counts()[$id] ?? 0); }
function sj_made_left(array $p): int { return max(0, sj_made_limit($p) - sj_made_used((string)($p['id'] ?? ''))); }
/* v63: ista e-mail adresa smije imati samo jednu neplaćenu (nestorniranu) narudžbu iz Stalne kolekcije */
function sj_made_open_order(string $email, array $collIds): string {
  $ek = sj_email_key($email);
  foreach (sj_read_json(SJ_DATA . '/narudzbe.json', []) as $o) {
    /* v64: narudžba primljena bez potvrde (preopterećenje) ne blokira adresu — inače bi tuđa adresa mogla zatvoriti kupca */
    if (!empty($o['paidAt']) || !empty($o['cancelledAt']) || !empty($o['unconfirmed']) || sj_order_expired($o) || sj_email_key((string)($o['email'] ?? '')) !== $ek) continue;
    foreach ((array)($o['order']['items'] ?? []) as $it) if (in_array((string)($it['id'] ?? ''), $collIds, true)) return (string)($o['no'] ?? '');
  }
  return '';
}

/* v62: druga plaćena narudžba za istu torbu (SUKOB) — da je vlasnica nakon „Ukloni oznaku” ne proda trećem kupcu */
function sj_other_paid(string $id, string $exceptNo): string {
  foreach (sj_order_files() as $f) foreach (sj_read_json($f, []) as $o) {
    if (empty($o['paidAt']) || ($o['no'] ?? '') === $exceptNo) continue;
    foreach ((array)($o['order']['items'] ?? []) as $it) if ((string)($it['id'] ?? '') === $id && empty($it['made']))
      return ' PAŽNJA: za ' . sj_product_names([$id]) . ' plaćena je i narudžba ' . (string)($o['no'] ?? '') . ' (' . (string)($o['name'] ?? '') . ') — torbu joj dodijelite tako da u Narudžbama za ' . (string)($o['no'] ?? '') . ' kliknete „Ukloni oznaku”, zatim „Označi plaćenom”; ne prodajte je drugome.';
  }
  return '';
}

/* v63: „1 mjesto”, „3 mjesta”, „5 mjesta” (hrvatski brojevi; 11–14 kao 5) */
function sj_count_words(int $n, string $one, string $few, string $many): string {
  $m10 = $n % 10; $m100 = $n % 100;
  return $n . ' ' . (($m10 === 1 && $m100 !== 11) ? $one : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? $few : $many));
}

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
function sj_order_cancelled(string $token, string $reason = '', string $by = 'vlasnica'): void {
  if ($token !== '') sj_order_edit(['token' => $token], function (array &$o) use ($reason, $by) { if (empty($o['cancelledAt'])) { $o['cancelledAt'] = sj_iso(time()); $o['cancelReason'] = $reason; $o['cancelBy'] = $by; } });
}
/* v68: podrubrike rubrike Ostalo (oznaka => naziv na stranici), redoslijed kao na stranici */
function sj_subs(): array { return ['remeni' => 'Remeni', 'more' => 'Torbe za more', 'kozmeticke' => 'Kozmetičke torbice', 'novcanici' => 'Mini novčanici', 'platnene' => 'Platnene torbe']; }
/* v68: mjere s univerzalnim opisom iz Postavki (samo ispunjene) */
function sj_sizes(): array {
  $out = [];
  foreach ((array)(sj_config()['sizes'] ?? []) as $x) { $m = trim((string)($x['mjera'] ?? '')); if ($m !== '') $out[] = ['mjera' => $m, 'opis' => trim((string)($x['opis'] ?? ''))]; }
  return $out;
}
/* v68: razlozi storna (admin bira pri „Storniraj”; ulaze u izvoz i analizu) */
function sj_cancel_reasons(): array { return ['nije uplaćeno u roku', 'kupac odustao', 'lažna narudžba', 'torba oštećena ili nedostupna', 'drugo']; }
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
    if (!empty($o['cancelledAt'])) return; /* v69 (N68-3): stornirana narudžba ne postaje plaćena (pod bravom) */
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
  if ($tok !== '' || $no !== '') sj_order_edit($no !== '' ? ['no' => $no] : ['token' => $tok], function (array &$o) { if (empty($o['anonymizedAt'])) unset($o['paidAt']); }); /* v81 (S1): obrisana narudžba ostaje zatvorena */
}

/* Sve promjene postavki idu kroz bravu, jer i API (brojač narudžbi) i admin pišu istu datoteku.
   Nakon zapisa osvježava se i predmemorija sj_config(). */
function sj_config_update(callable $fn): array {
  $new = sj_with_lock(SJ_DATA . '/config.json', function (array &$d) use ($fn) {
    $saved = $d;
    $d = array_replace_recursive(sj_defaults(), $d);
    /* popisi (dostava, izvori) se ne spajaju po indeksu sa zadanima, inače bi obrisana opcija „uskrsnula” */
    foreach (['shipping', 'allowed_origins', 'sizes'] as $k) if (isset($saved[$k]) && is_array($saved[$k])) $d[$k] = array_values($saved[$k]);
    if (isset($saved['free_ship']['ids']) && is_array($saved['free_ship']['ids'])) $d['free_ship']['ids'] = array_values($saved['free_ship']['ids']);
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

/* v83 (završno vijeće; ZZP čl. 60. st. 1. t. 1. i 8., čl. 74. st. 1. — NN 59/26): artikl se smije naručiti tek kad su
   uz njega upisani glavni podaci — mjere, materijal (torbe su uvijek od umjetne kože, v64, pa im prazno polje ne smeta)
   i, za artikl po narudžbi, rok izrade. Bez toga bi narudžba bila neobvezujuća. Vraća što nedostaje (prazno = potpun). */
function sj_val_filled($v): bool { $t = trim((string)$v); return $t !== '' && !preg_match('/^\{.*\}$/u', $t); }
function sj_product_missing(array $p): array {
  $miss = [];
  if (!sj_val_filled($p['dims'] ?? '')) $miss[] = 'mjere';
  if (($p['cat'] ?? 'torbe') === 'ostalo' && !sj_val_filled($p['material'] ?? '')) $miss[] = 'materijal';
  if (!empty($p['made']) && !sj_val_filled($p['lead'] ?? '')) $miss[] = 'rok izrade';
  return $miss;
}
function sj_product_orderable(array $p): bool { return !empty($p['sold']) || !sj_product_missing($p); }

/* v83 (recenzija): nakon nadogradnje javni torbe.json uskladi se s pravilom o potpunim podacima i bez izmjene popisa
   (poziva se pri otvaranju admina; piše samo kad se sadržaj razlikuje) */
function sj_sync_public_products(): void {
  if (!is_file(sj_products_path())) return;
  sj_with_lock(sj_products_path(), function (array &$list) {
    $list = array_values($list); $cur = sj_read_json(SJ_PUBLIC_JSON, null);
    $want = []; foreach ($list as $p) { if (!empty($p['hidden']) || !sj_product_orderable($p)) continue; unset($p['hidden']); $want[] = $p; }
    if (!is_array($cur) || json_encode(array_values($cur)) !== json_encode($want)) sj_write_public_products($list);
    return true;
  }, []);
}

function sj_write_public_products(array $list): void {
  $public = [];
  foreach ($list as $p) {
    if (!empty($p['hidden'])) continue;
    if (!sj_product_orderable($p)) continue; /* v83: nepotpun artikl nije na stranici */
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
  /* v66: i Unicode prijelomi reda (U+0085, U+2028, U+2029) i znakovi za smjer teksta — inače bi jednoredno polje
     u e-mailu vlasnici moglo podmetnuti „novi redak” ili okrenuti tekst */
  $v = preg_replace('/[\p{Cf}\x{80}-\x{9F}]/u', '', $v) ?? ''; /* v67: svi „format” znakovi (smjer, nevidljivi, U+FEFF, U+00AD, oznake) i C1 */
  $v = preg_replace('/[\x{0085}\x{2028}\x{2029}]/u', $multiline ? "\n" : ' ', $v) ?? '';
  $v = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u' : '/[\x00-\x1F\x7F]/u', $multiline ? '' : ' ', $v);
  if ($v === null) $v = '';
  return trim(mb_substr($v, 0, $max)); /* v67: i na kraju — ime od samih nevidljivih znakova ostaje prazno */
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

/* v77 (recenzija R76-S3, R76-P9): poveznice i e-adrese u tekstu koji kupac upiše, a mi ga ponavljamo u e-mailu */
/* vijeće V77-2: nastavak domene samo malim slovima (ne „Ilica 1.Online”, „Pozdrav.Eu”), „@” samo u obliku e-adrese (ne „@ana”) */
const SJ_LINK_RE = '~(https?:|www\.|[a-z0-9-]+\.(?-i:hr|com|net|org|eu|io|ly|me|info|xyz|ru|top|site|online|link)\b|\S+@\S+\.\S+)~iu';
function sj_has_link(string $s): bool { return (bool)preg_match(SJ_LINK_RE, $s); }
function sj_unlink(string $s): string { return preg_replace('~(https?://\S+|www\.\S+|\S+@\S+\.\S+|[a-z0-9-]+\.(?-i:hr|com|net|org|eu|io|ly|me|info|xyz|ru|top|site|online|link)\b\S*)~iu', '[uklonjeno]', $s) ?? ''; }
/* v77: boje i duljine remena iz ponude (odluka vlasnice, 11. krug) — isti popis kao STRAP_COLORS/STRAP_LENGTHS u index.html */
const SJ_STRAP_COLORS = ['crna', 'smeđa', 'crvena', 'bež', 'plava', 'tamno zelena', 'bordo', 'siva'];
const SJ_STRAP_LENGTHS = ['140 cm', '100 cm'];
/* v78 (12. krug, t1): toliko dana nakon storna isti e-mail ili veza ne naručuje istu torbu (api sj_recent_cancel_text) */
const SJ_REORDER_BLOCK_DAYS = 7;
/* napomena modela iz Stalne kolekcije iz košarice: „remen: boja, duljina; želja” → [remen, želja]. Vijeće V77-1: „iz ponude”
   samo za Stalnu kolekciju i samo kad su boja i duljina s popisa; inače je cijeli tekst želja */
function sj_strap_split(string $note, ?array $p = null): array {
  if ($p !== null && !sj_is_collection($p)) return ['', trim($note)];
  if (preg_match('/^remen: ([^,;]+), ([^;]+?)(?:; (.*))?$/su', trim($note), $m)
      && in_array(trim($m[1]), SJ_STRAP_COLORS, true) && in_array(trim($m[2]), SJ_STRAP_LENGTHS, true))
    return [trim($m[1]) . ', ' . trim($m[2]), trim((string)($m[3] ?? ''))];
  return ['', trim($note)];
}
/* v74 (vijeće V74-1): upute za njegu idu uz torbe (rubrika Torbe) od umjetne kože — prazan ili nepopunjen {…}
   materijal kod torbe znači umjetna koža (kao prikaz torbe na stranici, filled() i matShown) */
function sj_care_applies(array $p): bool {
  if ((string)($p['cat'] ?? 'torbe') !== 'torbe') return false;
  $m = trim((string)($p['material'] ?? ''));
  if ($m === '' || str_contains($m, '{')) $m = 'umjetna koža';
  return (bool)preg_match('/umjetn/iu', $m);
}
/* v82 (recenzija): bez oznaka u index.html e-mail kupcu ide bez Uvjeta ili Njege — to se ne smije dogoditi tiho */
function sj_marker_missing(string $what): void {
  @file_put_contents(SJ_DATA . '/greske.log', gmdate('c') . ' index.html: nema oznaka ' . $what . '-START/END — e-mail kupcu ide bez tog teksta' . "\n", FILE_APPEND | LOCK_EX);
}
/* v74: upute za njegu torbe (tekst vlasnice) — doslovno iz index.html, između NJEGA-START i NJEGA-END */
function sj_care_text(): string {
  static $t = null; if ($t !== null) return $t;
  $html = @file_get_contents(dirname(__DIR__) . '/index.html');
  if ($html === false || !preg_match('/<!-- NJEGA-START -->(.*?)<!-- NJEGA-END -->/s', $html, $m)) { sj_marker_missing('NJEGA'); return $t = ''; }
  $p = preg_split('#</p>\s*#', $m[1]) ?: [];
  $p = array_values(array_filter(array_map(fn($x) => trim(html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $p), fn($x) => $x !== ''));
  return $t = implode("\n\n", $p);
}

/* v73: cijeli tekst Uvjeta kupnje za e-mail s potvrdom narudžbe (trajni medij, ZZP čl. 76. NN 19/22) — doslovno iz
   index.html, između oznaka UVJETI-START i UVJETI-END, pa e-mail i stranica ne mogu reći različito. Grane
   data-if-confirm / data-if-noconfirm biraju se po postavci, data-ttl-text dobiva broj sati iz Postavki. */
function sj_terms_text(?array $c = null): string {
  static $cache = [];
  $c = $c ?? sj_config();
  $autoH = !empty($c['deadline_tick']) ? max(0, (int)($c['auto_cancel_hours'] ?? 0)) : 0; /* v78 (t3): kao stranica (api/status autoCancel) */
  $key = (!empty($c['require_confirm']) ? 'c' : 'n') . (int)$c['ttl_hours'] . '|' . (string)($c['ship_lead'] ?? '') . '|' . md5(json_encode($c['shipping'] ?? [])) . '|' . $autoH;
  if (isset($cache[$key])) return $cache[$key];
  $html = @file_get_contents(dirname(__DIR__) . '/index.html');
  if ($html === false || !preg_match('/<!-- UVJETI-START -->(.*?)<!-- UVJETI-END -->/s', $html, $m)) { sj_marker_missing('UVJETI'); return $cache[$key] = ''; }
  $h = $m[1];
  $drop = !empty($c['require_confirm']) ? 'data-if-noconfirm' : 'data-if-confirm';
  $h = preg_replace('#<span ' . $drop . '>.*?</span>#s', '', $h) ?? '';
  $h = preg_replace('#<span data-ttl-text>.*?</span>#s', sj_hours_text(max(1, (int)$c['ttl_hours'])), $h) ?? '';
  /* v75: rok slanja iz Postavki; prazno = rečenica bez roka (kao stranica) */
  $sl = trim((string)($c['ship_lead'] ?? ''));
  /* vijeće V75-3: rok se umeće tek nakon strip_tags/dekodiranja (oznaka SJSHIPLEAD), pa e-mail = stranica */
  $h = preg_replace('#<span data-terms-ship-wrap>(.*?)<span data-terms-ship-lead>.*?</span></span>#s', $sl !== '' ? '${1}SJSHIPLEAD' : '', $h) ?? '';
  /* vijeće V77-7: najjeftinija dostava s cijenom iz Postavki (kao stranica) */
  $paid = array_values(array_filter(sj_shipping(), fn($o) => (float)($o['price'] ?? 0) > 0)); usort($paid, fn($a, $b) => (float)$a['price'] <=> (float)$b['price']);
  $cheap = $paid ? (string)$paid[0]['label'] : '';
  $h = preg_replace('#<span data-terms-cheap-wrap>(.*?)<span data-terms-cheapest>.*?</span></span>#s', $cheap !== '' ? '${1}SJCHEAPEST' : '', $h) ?? '';
  $h = preg_replace('#<span data-terms-auto-wrap>(.*?)<span data-terms-auto>.*?</span>(.*?)</span>#s', $autoH > 0 ? '${1}SJAUTOH${2}' : '', $h) ?? '';
  $h = preg_replace('#<h3>(.*?)</h3>#s', "\n\n$1\n", $h) ?? '';
  $h = preg_replace('#<h4>(.*?)</h4>#s', "\n$1\n", $h) ?? '';
  $h = preg_replace('#</p>|</pre>|</li>#', "\n", $h) ?? '';
  $h = preg_replace('#<li>#', '- ', $h) ?? '';
  $h = html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $h = str_replace("\u{00A0}", ' ', $h);
  $h = str_replace(['SJSHIPLEAD', 'SJCHEAPEST', 'SJAUTOH'], [$sl, $cheap, sj_hours_text(max(1, $autoH))], $h);
  $lines = array_map(fn($l) => trim(preg_replace('/[ \t]+/', ' ', $l) ?? ''), explode("\n", $h));
  $out = []; foreach ($lines as $l) { if ($l === '' && (!$out || end($out) === '')) continue; $out[] = $l; }
  while ($out && end($out) === '') array_pop($out);
  return $cache[$key] = implode("\n", $out);
}
/* „1 sat”, „24 sata”, „12 sati”, „48 sati” (kao hoursText na stranici) */
function sj_hours_text(int $n): string {
  $m10 = $n % 10; $m100 = $n % 100;
  $w = ($m10 === 1 && $m100 !== 11) ? 'sat' : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? 'sata' : 'sati');
  return $n . ' ' . $w;
}

function sj_fmt_eur(float $n): string { return number_format($n, 2, ',', '.') . ' €'; }

/* v69 (vijeće na v68, D69-6): rok kao na stranici — „četvrtka, 29. 10. 2026. u 08:16” ($case 'gen', za „do …”)
   ili „četvrtak, 29. 10. 2026. u 08:16” ('acc', za „u …”) */
function sj_fmt_deadline(?string $iso, string $case = 'gen'): string {
  $t = $iso ? strtotime($iso) : false;
  if (!$t) return sj_fmt_hr($iso);
  $dt = new DateTime('@' . $t); $dt->setTimezone(new DateTimeZone('Europe/Zagreb'));
  $n = (int)$dt->format('N');
  $gen = [1 => 'ponedjeljka', 'utorka', 'srijede', 'četvrtka', 'petka', 'subote', 'nedjelje'];
  $acc = [1 => 'ponedjeljak', 'utorak', 'srijedu', 'četvrtak', 'petak', 'subotu', 'nedjelju'];
  return ($case === 'acc' ? $acc[$n] : $gen[$n]) . ', ' . $dt->format('j. n. Y.') . ' u ' . $dt->format('H:i');
}
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
  if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('Fotografija nije spremljena: na poslužitelju nije uključeno PHP proširenje GD (Plesk → PHP → proširenja, ili zamolite podršku hostinga). Ostali podaci torbe su ispravni — spremite ih bez fotografije pa je dodajte kad GD proradi.'); /* v82 */
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
  /* v79: SMTP s prijavom (Croadria: „potrebno je autorizirati se … i preko web skripte”) — bez tihog prelaska na mail() */
  if (($c['mail_mode'] ?? 'mail') === 'smtp') {
    $hdr = array_values(array_filter($headers, fn($h) => !str_starts_with($h, 'Return-Path:'))); /* Return-Path upisuje primatelj */
    $domain = substr(strrchr($from, '@') ?: '@shivaj.hr', 1);
    array_unshift($hdr, 'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>', 'To: <' . $to . '>', 'Subject: ' . $encSubject);
    return sj_smtp_send($c, $from, $to, implode("\r\n", $hdr) . "\r\n\r\n" . $body);
  }
  /* -f = adresa pošiljatelja na omotnici (Return-Path); bez nje poslužitelj šalje kao korisnik hostinga,
     pa provjere SPF/DMARC padaju i potvrda s IBAN-om završi u neželjenoj pošti */
  $sent = @mail($to, $encSubject, $body, implode("\r\n", $headers), '-f' . $from);
  if (!$sent) $sent = @mail($to, $encSubject, $body, implode("\r\n", $headers)); /* neki hostinzi ne dopuštaju -f */
  return $sent;
}

/* v79: zadnji ishod slanja preko SMTP-a (za upozorenje u adminu i „Pošalji probni e-mail"); lozinka se nikad ne zapisuje */
function sj_smtp_status_path(): string { return SJ_DATA . '/smtp-zadnja-greska.json'; }
function sj_smtp_status(): array { clearstatcache(); $s = json_decode((string)@file_get_contents(sj_smtp_status_path()), true); return is_array($s) ? $s : []; } /* bez predmemorije: mijenja se unutar istog zahtjeva */
function sj_smtp_note(bool $ok, string $msg = '', string $kind = ''): void {
  if (!is_dir(SJ_DATA)) @mkdir(SJ_DATA, 0755, true);
  $msg = sj_clean($msg, 300);
  /* vijeće V79-2: odbijena adresa jednog kupca (RCPT) nije kvar slanja — samo zapis, bez upozorenja u adminu */
  if ($kind !== 'rcpt') @file_put_contents(sj_smtp_status_path(), json_encode(['at' => sj_iso(time()), 'ok' => $ok, 'msg' => $msg, 'kind' => $kind], JSON_UNESCAPED_UNICODE), LOCK_EX);
  if (!$ok) @file_put_contents(SJ_DATA . '/greske.log', gmdate('c') . ' SMTP: ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}
/* v79: što nedostaje u postavkama SMTP-a ('' = sve upisano) */
function sj_smtp_missing(array $c): string {
  $m = [];
  if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', (string)($c['smtp_host'] ?? ''))) $m[] = 'poslužitelj';
  if ((int)($c['smtp_port'] ?? 0) < 1 || (int)($c['smtp_port'] ?? 0) > 65535) $m[] = 'port';
  if (trim((string)($c['smtp_user'] ?? '')) === '') $m[] = 'korisničko ime';
  if ((string)($c['smtp_pass'] ?? '') === '') $m[] = 'lozinka';
  return implode(', ', $m);
}
/* v79: mali SMTP klijent (RFC 5321, AUTH PLAIN/LOGIN po RFC 4616/4954), bez vanjskih biblioteka.
   ssl = TLS od početka (465), starttls = STARTTLS (587), none = bez šifriranja, dopušteno samo prema 127.0.0.1 (testovi).
   Certifikat poslužitelja se provjerava. Vraća true kad poslužitelj prihvati poruku (250 nakon DATA). */
function sj_smtp_send(array $c, string $from, string $to, string $message): bool {
  if (($miss = sj_smtp_missing($c)) !== '') { sj_smtp_note(false, 'u Postavkama nedostaje: ' . $miss); return false; }
  $host = (string)$c['smtp_host']; $port = (int)$c['smtp_port']; $sec = (string)($c['smtp_secure'] ?? 'ssl');
  $local = in_array(strtolower($host), ['127.0.0.1', 'localhost'], true);
  if (!in_array($sec, ['ssl', 'starttls', 'none'], true)) $sec = 'ssl';
  if ($sec === 'none' && !$local) { sj_smtp_note(false, 'bez šifriranja lozinka bi putovala otvoreno — odaberite SSL (465) ili STARTTLS (587)'); return false; }
  /* vijeće V79-4: nakon pada veze ne pokušava se ponovno 5 minuta (inače svaki e-mail čeka do isteka vremena) */
  $st = sj_smtp_status();
  if (isset($st['ok']) && !$st['ok'] && ($st['kind'] ?? '') === 'conn' && (int)strtotime((string)($st['at'] ?? '')) > time() - 300) return false;
  $tls = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0); /* V79-9 */
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true, 'crypto_method' => $tls]]);
  $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) { sj_smtp_note(false, 'poslužitelj ' . $host . ':' . $port . ' nije dostupan (' . (trim((string)$errstr) !== '' ? sj_clean((string)$errstr, 120) : 'možda certifikat ili krivi port/šifriranje') . ')', 'conn'); return false; }
  stream_set_timeout($fp, 8);
  $read = function () use ($fp): array { /* višeredni odgovor „250-…” do „250 …” */
    $all = '';
    while (($l = fgets($fp, 8192)) !== false) { $all .= $l; if (strlen($l) < 4 || $l[3] !== '-') break; } /* V79-7 */
    return [(int)substr($all, 0, 3), trim($all)];
  };
  $say = function (string $cmd) use ($fp, $read): array { @fwrite($fp, $cmd . "\r\n"); return $read(); };
  $fail = function (string $what, array $r, string $kind = '') use ($fp): bool {
    @fwrite($fp, "QUIT\r\n"); @fclose($fp);
    sj_smtp_note(false, $what . ($r[1] !== '' ? ' — poslužitelj: ' . preg_replace('/\s+/', ' ', $r[1]) : ' — poslužitelj ne odgovara'), $r[0] === 0 && $kind === '' ? 'conn' : $kind);
    return false;
  };
  $helo = preg_replace('/[^A-Za-z0-9.-]/', '', (string)(substr(strrchr($from, '@') ?: '', 1))) ?: 'localhost';
  if (($r = $read())[0] !== 220) return $fail('poslužitelj nije spreman', $r);
  if (($e = $say('EHLO ' . $helo))[0] !== 250) return $fail('EHLO odbijen', $e);
  if ($sec === 'starttls') {
    if (($r = $say('STARTTLS'))[0] !== 220) return $fail('STARTTLS odbijen', $r);
    if ((int)(stream_get_meta_data($fp)['unread_bytes'] ?? 0) > 0) return $fail('neočekivani podaci prije TLS-a', [0, '']); /* V79-5 */
    if (!@stream_socket_enable_crypto($fp, true, $tls)) return $fail('TLS nije uspostavljen (certifikat?)', [0, '']);
    if (($e = $say('EHLO ' . $helo))[0] !== 250) return $fail('EHLO odbijen', $e);
  }
  $user = (string)$c['smtp_user']; $pass = (string)$c['smtp_pass'];
  $mech = preg_match('/^250[ -]AUTH[ =](.*)$/mi', $e[1], $mm) ? strtoupper($mm[1]) : '';
  if (str_contains($mech, 'PLAIN')) {
    if (($r = $say('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass)))[0] !== 235) return $fail('prijava odbijena (provjerite korisničko ime i lozinku)', $r);
  } elseif (str_contains($mech, 'LOGIN')) {
    if (($r = $say('AUTH LOGIN'))[0] !== 334) return $fail('prijava odbijena', $r);
    if (($r = $say(base64_encode($user)))[0] !== 334) return $fail('prijava odbijena (korisničko ime)', $r);
    if (($r = $say(base64_encode($pass)))[0] !== 235) return $fail('prijava odbijena (provjerite korisničko ime i lozinku)', $r);
  } else return $fail('poslužitelj ne nudi prijavu (AUTH PLAIN ili LOGIN)', $e);
  if (($r = $say('MAIL FROM:<' . $from . '>' . (preg_match('/^250[ -]8BITMIME/mi', $e[1]) ? ' BODY=8BITMIME' : '')))[0] !== 250) /* V79-6 */ return $fail('pošiljatelj odbijen (adresa pošiljatelja mora biti sandučić s kojim se prijavljujete)', $r);
  if (!in_array(($r = $say('RCPT TO:<' . $to . '>'))[0], [250, 251], true)) return $fail('primatelj ' . $to . ' odbijen', $r, 'rcpt');
  if (($r = $say('DATA'))[0] !== 354) return $fail('DATA odbijen', $r);
  $msg = preg_replace("/\r\n|\r|\n/", "\r\n", $message) ?? '';
  $msg = preg_replace('/^\./m', '..', $msg) ?? ''; /* RFC 5321 4.5.2: red koji počinje točkom */
  $out = rtrim($msg, "\r\n") . "\r\n.\r\n";
  while ($out !== '') { $n = @fwrite($fp, $out); if (!$n) return $fail('veza prekinuta tijekom slanja', [0, '']); $out = (string)substr($out, $n); }
  stream_set_timeout($fp, 60); /* v80 (S-3): poslužitelj smije provjeriti poruku (antivirus) prije odgovora 250 */
  if (($r = $read())[0] !== 250) return $fail($r[0] === 0 ? 'poslužitelj nije potvrdio primitak poruke (možda je ipak poslana)' : 'poruka odbijena', $r, $r[0] === 0 ? 'data' : '');
  @fwrite($fp, "QUIT\r\n"); @fclose($fp);
  sj_smtp_note(true);
  return true;
}
