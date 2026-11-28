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
define('SJ_VERSION', 'admin v58');

/* ---------- postavke ---------- */

function sj_defaults(): array {
  return [
    'shop'             => 'Shiva.J',
    'owner_email'      => 'shivaj.handmade@gmail.com', // kamo stižu narudžbe
    'from_email'       => '',                          // npr. info@shivaj.hr; prazno = owner_email
    'pow_bits'         => 16,                          // v93: provjera „niste robot” izračunom (0 = isključeno)
    'pow_secret'       => '',                          // v93: tajna za potpis zadatka; stvara se sama
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
    if (!isset($GLOBALS['sj_cfg_snap'])) $GLOBALS['sj_cfg_snap'] = $c; /* v95: stanje na početku zahtjeva (sj_save_config sprema samo promjene) */
    /* v99 (recenzija vijeća v98, L1): prazan ili nečitljiv config.json — traka HITNO i e-mail vlasnici (jednom dnevno), jer ga više
       ništa ne prepisuje; stranica dotle radi sa zadanim postavkama */
    static $noted = false; $cp = SJ_DATA . '/config.json';
    if (!$noted && is_file($cp) && sj_read_json($cp, null) === null) { $noted = true; try { sj_corrupt_note('config.json'); } catch (Throwable $e) {} }
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
/* v91: .php (poslužitelj je ne šalje kao tekst); stari naziv .txt vrijedi dok ga nadogradnja ne zamijeni */
function sj_setup_path(): string { $p = SJ_DATA . '/PRVA-PRIJAVA.php'; $old = SJ_DATA . '/PRVA-PRIJAVA.txt'; return (!is_file($p) && is_file($old)) ? $old : $p; }
/* v20: kod za prvu lozinku — redak „kod: …” (barem 16 znakova) u data/PRVA-PRIJAVA.php. Bez koda prvi posjetitelj
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
  /* v91 (recenzija): bez točke (domena „secure-pay.shop”) i najviše 4 riječi */
  return (preg_match("/^\p{L}[\p{L} '’\-]{0,59}$/u", $name) && count(preg_split('/\s+/', $name)) <= 4) ? 'Dobar dan, ' . $name . ',' : 'Dobar dan,';
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
/* „torbu čuvamo” / „torbe čuvamo” / „rok za uplatu produljujemo” (narudžba bez unikata) — za tekstove kupcu */
function sj_unique_count(array $o): int { $n = 0; foreach ((array)($o['order']['items'] ?? []) as $it) if (empty($it['made'])) $n++; return $n; }
/* v70 (D70-5, D70-9): rok je prošao — torbu više ne čuvamo automatski; vlasnica provjerava izvod (bez novog obećanja) */
function sj_late_text(array $o, string $lead): string {
  $n = sj_unique_count($o);
  return $lead . ($n === 0 ? ', pa ga više ne možemo automatski produljiti' : ', pa ' . ($n > 1 ? 'torbe' : 'torbu') . ' više ne možemo automatski čuvati')
    . '. Javili smo vlasnici: provjerit će uplatu i javiti vam se.' . ($n === 0 ? '' : ($n > 1 ? ' Ako je neka od torbi u međuvremenu otišla drugom kupcu, za nju vam vraćamo uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.' : ' Ako je torba u međuvremenu otišla drugom kupcu, vraćamo vam uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.'));
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
  return $n > 1 ? 'torbe čuvamo za vas' : ($n === 1 ? 'torbu čuvamo za vas' : 'rok za uplatu produljen je');
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
    /* v70 (vijeće, N70-1): popunjeno mjesto u kolekciji — ne produljujemo (inače bi model bio iznad granice) */
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
    $x['payUntil'] = $new; unset($x['expiredAt'], $x['cancelDue']); /* v92 (recenzija): novi rok — novi rok storna pri sljedećem isteku */
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
  if (!empty($o['cancelDue']) && ($cd = (int)strtotime((string)$o['cancelDue'])) > 0) $due = max($due, $cd); /* v92 (recenzija): zapisan pri isteku — kasnija promjena postavke ga ne skraćuje */
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
  foreach (['name', 'email', 'phone', 'address', 'note', 'claim', 'ip', 'ip_prev', 'withdrawals', 'key', 'series'] as $k) unset($o[$k]);
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
  foreach (glob(SJ_DATA . '/raskidi-visak-*.jsonl') ?: [] as $f) /* v95: dodatni popis izjava, isti rok čuvanja */
    if (preg_match('/(\d{4})-(\d{2})\.jsonl$/', $f, $mm) && (int)strtotime($mm[1] . '-' . $mm[2] . '-01 +1 month') + SJ_PURGE_KEEP_DAYS * 86400 <= $now && @unlink($f)) $n++;
  if (is_file(SJ_DATA . '/raskidi.json')) sj_with_lock(SJ_DATA . '/raskidi.json', function (array &$list) use (&$n, $now) {
    $keep = array_values(array_filter($list, fn($r) => !is_array($r) || ($t = (int)strtotime((string)($r['at'] ?? ''))) <= 0 || $t + SJ_PURGE_KEEP_DAYS * 86400 > $now));
    $n += count($list) - count($keep); $list = $keep; return true;
  }, []);
  return $n;
}
/* v95 (recenzija vijeća v94, sigurnost 4): izjave o raskidu iznad granica glavne evidencije — mjesečna datoteka, jedan redak JSON po izjavi */
function sj_raskid_overflow_path(int $t): string { return SJ_DATA . '/raskidi-visak-' . gmdate('Y-m', $t) . '.jsonl'; }
/* v99 (recenzija vijeća v98, sigurnost H1): dnevnik svake izjave prije odgovora (isti put za sve); briše se nakon 2 mjeseca (sj_daily_backup) */
function sj_raskid_journal_path(int $t): string { return SJ_DATA . '/raskidi-ulaz-' . gmdate('Y-m', $t) . '.jsonl'; }
function sj_raskid_overflow_read(int $months = 2, int $max = 100, int $skip = 0): array {
  $out = []; $n = 0; /* v96: $skip — stranice u adminu (od najnovije) */
  for ($i = 0; $i < $months; $i++) {
    $f = sj_raskid_overflow_path(gmmktime(0, 0, 0, (int)gmdate('n') - $i, 1, (int)gmdate('Y'))); /* v95 (recenzija): mjesec u UTC kao pri upisu */
    if (!is_file($f)) continue;
    $fh = @fopen($f, 'rb'); if (!$fh) continue;
    while (($ln = fgets($fh)) !== false) { $x = json_decode($ln, true); if (!is_array($x)) continue; $n++; $out[] = $x; if (count($out) > $max + $skip) array_shift($out); }
    fclose($fh);
  }
  return ['n' => $n, 'list' => array_slice(array_reverse($out), $skip, $max)];
}
/* v96 (recenzija vijeća v95, N5): najviše ~20 MB dodatnog popisa mjesečno; iznad toga samo brojač (sažetak vlasnici) */
const SJ_RASKID_OVERFLOW_MAX = 20 * 1024 * 1024;
/* recenzija v99: odgovor na izjavu o raskidu ide prije upisa u popis — ako je proces prekinut nakon odgovora (istek vremena, pad,
   ponovno pokretanje poslužitelja), izjava je samo u dnevniku (raskidi-ulaz). Svakih 10 minuta (api/status, admin) izjave iz dnevnika
   starije od 15 minuta kojih nema ni u glavnom ni u dodatnom popisu upisuju se u glavni popis („obnovljeno”) i vlasnica dobiva e-mail
   da kupcu sama pošalje potvrdu. Mjesec s punim dodatnim popisom (zlouporaba) se preskače; najviše 20 izjava po prolazu. */
function sj_raskid_reconcile(bool $force = false, array $extraJournals = []): array {
  /* v100 (recenzija vijeća v99, softver N2/N4): čita redak po redak (fgets) i pamti samo id-ove i mjesta u datoteci, pa i dnevnik od
     20 MB stane u malo memorije; za svaki dnevnik pamti do kojeg je mjesta sve već usklađeno (raskidi-provjera.json → off); e-mail
     vlasnici koji nije prošao ponavlja se pri sljedećem prolazu (→ notify) */
  $now = time(); $stF = SJ_DATA . '/raskidi-provjera.json';
  $st = sj_with_lock($stF, function (array &$d) use ($now, $force) {
    if (!$force && (int)($d['last'] ?? 0) > $now - 600) return null;
    $d['last'] = $now; return $d;
  }, []);
  if (!is_array($st)) return [];
  $months = [$now, gmmktime(0, 0, 0, (int)gmdate('n', $now) - 1, 1, (int)gmdate('Y', $now))];
  $cand = []; $newOff = (array)($st['off'] ?? []); $GLOBALS['sj_reconcile_read'] = [];
  /* parovi dnevnik → dodatni popis istog mjeseca; recenzija v100: i stari dnevnik koji dnevna kopija upravo briše */
  $pairs = array_map(fn($t) => [sj_raskid_journal_path($t), sj_raskid_overflow_path($t)], $months);
  foreach ($extraJournals as $xj) $pairs[] = [$xj, SJ_DATA . '/' . str_replace('raskidi-ulaz-', 'raskidi-visak-', basename($xj))];
  foreach ($pairs as [$jf, $of]) {
    $bn = basename($jf);
    if (!is_file($jf) || (is_file($of) && filesize($of) > SJ_RASKID_OVERFLOW_MAX)) continue;
    $fh = @fopen($jf, 'rb'); if (!$fh) continue;
    $off = (int)($newOff[$bn] ?? 0); if ($off > 0 && $off <= filesize($jf)) fseek($fh, $off); else $off = 0;
    while (true) {
      $pos = ftell($fh); $ln = fgets($fh);
      if ($ln === false || substr($ln, -1) !== "\n") { $off = $pos; break; } /* kraj ili redak koji se upravo piše */
      $e = json_decode($ln, true);
      if (is_array($e) && !empty($e['id']) && (int)strtotime((string)($e['at'] ?? '')) >= $now - 900) { $off = $pos; break; } /* premlada — obrada možda još traje */
      if (is_array($e) && !empty($e['id'])) $cand[(string)$e['id']] = [$bn, $pos];
      $off = ftell($fh);
    }
    fclose($fh); $newOff[$bn] = $off;
    clearstatcache(true, $jf); if ($off >= filesize($jf)) $GLOBALS['sj_reconcile_read'][] = $bn; /* recenzija v101: pročitan do kraja (dnevna kopija smije brisati samo takve) */
  }
  $missing = [];
  if ($cand) {
    foreach ((array)sj_read_json(SJ_DATA . '/raskidi.json', []) as $x) unset($cand[(string)($x['id'] ?? '')]);
    foreach ($pairs as [, $of]) { if (!$cand || !is_file($of)) continue; $fh = @fopen($of, 'rb'); if (!$fh) continue;
      while (($ln = fgets($fh)) !== false) { if (preg_match('/"id":"([0-9a-f]{8,64})"/', $ln, $mm)) unset($cand[$mm[1]]); } fclose($fh); }
    /* najviše 20 po prolazu; mjesto u dnevniku ne prelazi prvu izjavu koja je ostala neobnovljena */
    $k = 0;
    foreach ($cand as $id => [$bn, $pos]) {
      if ($k >= 20) { if ($pos < (int)$newOff[$bn]) $newOff[$bn] = $pos; continue; }
      $fh = @fopen(SJ_DATA . '/' . $bn, 'rb'); if (!$fh) continue; fseek($fh, $pos); $e = json_decode((string)fgets($fh), true); fclose($fh);
      if (is_array($e)) { $missing[] = $e; $k++; }
    }
    if ($missing) sj_with_lock(SJ_DATA . '/raskidi.json', function (array &$list) use ($missing, $now) {
      $have = array_flip(array_map(fn($x) => (string)($x['id'] ?? ''), $list));
      foreach ($missing as $e) if (!isset($have[(string)$e['id']])) $list[] = $e + ['recovered' => sj_iso($now)];
    }, []);
  }
  /* obavijest: nove obnovljene izjave i one čiji e-mail ranije nije prošao */
  $notifyIds = array_values(array_unique(array_merge(array_map('strval', (array)($st['notify'] ?? [])), array_map(fn($e) => (string)$e['id'], $missing))));
  $sent = true;
  if ($notifyIds) {
    $byId = []; foreach ((array)sj_read_json(SJ_DATA . '/raskidi.json', []) as $x) if (in_array((string)($x['id'] ?? ''), $notifyIds, true)) $byId[(string)$x['id']] = $x;
    $c = sj_config();
    $lines = array_map(fn($e) => '- ' . sj_fmt_hr((string)($e['at'] ?? '')) . ': ' . (string)($e['name'] ?? '') . ', ' . (string)($e['email'] ?? '') . ', narudžba: ' . (string)($e['no'] ?? '')
      . (!empty($e['iban']) ? ', IBAN: ' . (string)$e['iban'] : '') . (!empty($e['note']) ? "\n  napomena: " . (string)$e['note'] : ''), array_values($byId));
    $sent = !$byId || sj_send_mail((string)$c['owner_email'], (string)($c['shop'] ?? 'Shiva.J') . ' — HITNO: izjava o raskidu nije do kraja obrađena',
      "Stranica je kupcu potvrdila primitak izjave o raskidu, ali obrada nakon toga je prekinuta (npr. istek vremena ili ponovno pokretanje poslužitelja). Izjave su sada upisane u Narudžbe → Izjave o raskidu (oznaka „obnovljeno”).\n\n"
      . "Kupac možda nije dobio potvrdu e-mailom: pošaljite mu je sami (ZZP čl. 81.a) i provjerite narudžbu (storno, povrat novca).\n\n" . implode("\n", $lines)
      . (sj_site_url_trusted() !== '' ? "\n\n" . sj_site_url_trusted() . '/admin/?s=narudzbe' : ''));
  }
  sj_with_lock($stF, function (array &$d) use ($newOff, $notifyIds, $sent) {
    $keep = [basename(sj_raskid_journal_path(time())), basename(sj_raskid_journal_path(gmmktime(0, 0, 0, (int)gmdate('n') - 1, 1, (int)gmdate('Y'))))];
    $d['off'] = array_intersect_key($newOff, array_flip($keep));
    $d['notify'] = $sent ? [] : array_slice($notifyIds, 0, 200);
  }, []);
  return array_map(fn($e) => (string)$e['id'], $missing);
}
/* sažetak vlasnici jednom na sat za izjave koje nisu poslane pojedinačno (poziva se iz api/status i admina) */
function sj_raskid_digest(): void {
  $f = SJ_DATA . '/raskid-sazetak.json';
  if (!is_file($f)) return;
  $now = time(); $send = 0; $since = '';
  $noConf = 0;
  sj_with_lock($f, function (array &$d) use ($now, &$send, &$since, &$noConf) {
    if ((int)($d['n'] ?? 0) <= 0 || (int)($d['last'] ?? 0) > $now - 3600) return false;
    $send = (int)$d['n']; $since = (string)($d['since'] ?? ''); $noConf = (int)($d['noConf'] ?? 0); $d = ['n' => 0, 'last' => $now]; return true;
  }, []);
  if ($send <= 0) return;
  $noConf = (int)($noConf ?? 0);
  $c = sj_config();
  if (!sj_send_mail((string)$c['owner_email'], $c['shop'] . ' — izjave o raskidu: sažetak (' . $send . ')', 'Od ' . ($since !== '' ? sj_fmt_hr($since) : 'zadnjeg sažetka') . ' stiglo je još ' . $send . ' izjava o raskidu koje vam nisu poslane pojedinačno: ne podudaraju se s narudžbom ili ih je uz istu narudžbu danas stiglo više (zaštita od zlouporabe; možda ih netko šalje masovno). Sve su upisane: admin → Narudžbe → Izjave o raskidu. Prave izjave prepoznat ćete po imenu i e-mailu kupca.' . ($noConf > 0 ? ' Uz ' . $noConf . ' od njih kupac nije automatski dobio potvrdu primitka (dnevna granica e-mailova): ako je izjava prava, pošaljite mu potvrdu sami.' : '')))
    sj_with_lock($f, function (array &$d) use ($send, $since, $noConf) { $d['n'] = (int)($d['n'] ?? 0) + $send; if ($noConf) $d['noConf'] = (int)($d['noConf'] ?? 0) + $noConf; if ($since !== '') $d['since'] = $since; $d['last'] = time() - 3600 + 900; return true; }, []); /* neposlano: ponovno za 15 min (v96, N8: ne pri svakom zahtjevu) */
}
/* recenzija v96: otisak ugrađene skripte u .htaccess (CSP iz paketa) mora odgovarati index.html — inače preglednik
   blokira cijelu skriptu (nema košarice ni narudžbi), npr. nakon što je zamijenjen samo index.html. '' = u redu ili bez otiska */
function sj_csp_hash_problem(string $root): string {
  $ht = @file_get_contents($root . '/.htaccess'); $html = @file_get_contents($root . '/index.html');
  if (!is_string($ht) || !is_string($html)) return '';
  if (!preg_match_all("~'sha256-([A-Za-z0-9+/=]+)'~", $ht, $m)) {
    /* v98 (recenzija vijeća v97, sigurnost L2): .htaccess iz repozitorija (bez otiska) — trgovina radi, ali bez stroge zaštite */
    return (stripos($ht, 'Content-Security-Policy') !== false && preg_match('~<script(?![^>]*\bsrc\s*=)(?![^>]*ld\+json)[^>]*>~i', $html))
      ? 'PAŽNJA — .htaccess nema otiska skripte (vjerojatno je učitan iz repozitorija, a ne iz paketa): trgovina radi, ali bez stroge zaštite od ubačenih skripti (CSP). Učitajte .htaccess iz paketa „nadogradnja”.' : '';
  }
  $want = $m[1];
  if (!preg_match_all('~<script\b([^>]*)>(.*?)</script>~is', $html, $sc, PREG_SET_ORDER)) return '';
  foreach ($sc as $x) {
    if (preg_match('~\bsrc\s*=~i', $x[1]) || preg_match('~type\s*=\s*["\']application/ld\+json~i', $x[1])) continue;
    if (!in_array(base64_encode(hash('sha256', $x[2], true)), $want, true)) return 'POZOR — index.html i .htaccess nisu iz istog paketa (otisak skripte se ne podudara), pa preglednici blokiraju trgovinu. Učitajte cijeli paket „nadogradnja” (index.html i .htaccess zajedno).';
  }
  return '';
}
/* recenzija v102 (sigurnost N5): nevidljivi znakovi koji NISU kategorije Cf, na jednom mjestu (sj_clean, sj_link_norm, maska) — i nedodijeljeni
   „zadano nevidljivi” (U+2065, U+FFF0–FFF8, U+E0000–E0FFF) te nevidljive oznake (U+2D7F, U+16FE4, U+1BC9D) */
const SJ_INVIS = '\\x{034F}\\x{115F}\\x{1160}\\x{17B4}\\x{17B5}\\x{180B}-\\x{180F}\\x{2065}\\x{2800}\\x{2D7F}\\x{3164}\\x{FFA0}\\x{FFF0}-\\x{FFF8}\\x{FE00}-\\x{FE0F}\\x{16FE4}\\x{1BC9D}\\x{E0000}-\\x{E0FFF}';
/* v95 (sigurnost 5): u ponovljenoj napomeni IBAN i dugi brojevi (račun, telefon) nisu vidljivi */
/* recenzija v102 (sigurnost N4): vrijednost „broja” koji nije znamenka (eksponenti, indeksi, zaokruženi i sl.), ili null */
function sj_no_digit(int $cp): ?int {
  static $one = [0x00B2 => 2, 0x00B3 => 3, 0x00B9 => 1, 0x2070 => 0, 0x24EA => 0, 0x24FF => 0, 0x1F100 => 0, 0x1F10B => 0, 0x1F10C => 0];
  if (isset($one[$cp])) return $one[$cp];
  foreach ([[0x2074, 4], [0x2080, 0], [0x1F101, 0]] as [$a, $v0]) if ($cp >= $a && $cp <= $a + 9 - $v0) return $cp - $a + $v0;
  foreach ([0x2460, 0x2474, 0x2488, 0x24F5, 0x2776, 0x2780, 0x278A] as $a) if ($cp >= $a && $cp <= $a + 8) return $cp - $a + 1;
  if (class_exists('IntlChar')) { $v = \IntlChar::getNumericValue($cp); if (is_float($v) && $v >= 0 && $v <= 9 && floor($v) === $v) return (int)$v; }
  return null;
}
function sj_mask_numbers(string $s): string {
  /* v95: pravi oblik IBAN-a, ne „u roku od 14 dana”; v96: višestruki razmaci; v98 (recenzija vijeća v97, sigurnost M2): mala slova,
     neprelomivi razmaci, podvlake i zagrade („hr12  2360 …”, „HR12_2360_…”, „+385 (91) 234-5678”), a datumi, vremena i brojevi
     narudžbi ostaju vidljivi („18. 11. 2026. 14:30”, „2026-0007”) — recenzija v98: samo kad uz njih (3 znaka) nema drugih znamenki,
     inače bi zaštićeni dio sakrio telefon („091 2345-6789”, „09:12 345 678”) */
  $s = preg_replace('~[\x{00A0}\x{202F}\x{2007}\x{2009}\t]~u', ' ', $s) ?? '';
  $s = preg_replace('~[' . SJ_INVIS . ']~u', '', $s) ?? $s; /* provjera v102 (krug 2): nevidljiva „slova” između znamenki */
  /* v99 (recenzija vijeća v98, sigurnost N4): znamenke iz drugih pisama i pune širine („０９１”) kao obične */
  $s = preg_replace_callback('~(?![0-9])\p{Nd}~u', function ($m) { $cp = mb_ord($m[0], 'UTF-8');
    if (class_exists('IntlChar')) { $v = \IntlChar::charDigitValue($cp); if ($v >= 0) return (string)$v; }
    return ($cp >= 0xFF10 && $cp <= 0xFF19) ? (string)($cp - 0xFF10) : $m[0]; }, $s) ?? $s;
  /* recenzija v102 (sigurnost N1): slova koja izgledaju kao crtica ili okomita crta (U+3161, U+4E00, U+01C0…) između znamenki su crtica — „091ㅡ234ㅡ5678” */
  $s = preg_replace('~(?<=[\d\p{No}])(\s{0,2})[\x{01C0}-\x{01C3}\x{1173}\x{1175}\x{3127}\x{3161}\x{3163}\x{30FC}\x{4E00}\x{4E28}\x{FF70}\x{FFDA}\x{FFDC}](?=\s{0,2}[\d\p{No}])~u', '$1-', $s) ?? $s;
  /* recenzija v102 (sigurnost N4): „brojevi” koji nisu znamenke (⓪⑨①, ⁰⁹¹, ₀₉₁…) pomiješani s običnima — u nizu od sedam ili više znamenki postaju obične
     („⓪⑨①234⑤⑥⑦8”); kraći nizovi ostaju („22 m²”) */
  $s = preg_replace_callback('~[\d\p{No}](?:[^\p{L}\p{N}\p{Sc}]{0,6}[\d\p{No}])+~u', function ($m) {
    if (!preg_match('~\p{No}~u', $m[0]) || preg_match_all('~[\d\p{No}]~u', $m[0]) < 7) return $m[0];
    return (string)preg_replace_callback('~\p{No}~u', function ($x) { $v = sj_no_digit(mb_ord($x[0], 'UTF-8')); return $v === null ? $x[0] : (string)$v; }, $m[0]);
  }, $s) ?? $s;
  /* v102 (recenzija vijeća v101, sigurnost N3): „brojevi” koji nisu znamenke (⓪⑨①…, ⁰⁹¹…) — sedam ili više zaredom skriva se kao broj */
  $s = preg_replace('~\p{No}(?:[^\p{L}\p{N}]{0,3}\p{No}){6,}~u', '[broj skriven]', $s) ?? $s;
  $keep = [];
  $protect = function (string $re, ?callable $valid = null) use (&$s, &$keep): void {
    $subj = $s;
    $s = preg_replace_callback($re, function ($m) use (&$keep, $subj, $valid) {
      $txt = $m[0][0];
      if ($valid && !$valid($m, $subj)) return $txt;
      $keep[] = $txt; return "\x01" . (count($keep) - 1) . "\x02";
    }, $s, -1, $cnt, PREG_OFFSET_CAPTURE) ?? '';
  };
  $protect('~(?<![\d\-])(?:SJ-)?20\d{2}-\d{4,6}(?:(?:,? | i )(?:SJ-)?20\d{2}-\d{4,6})*(?![\d\-])~i');                 /* broj(evi) narudžbe (godina 20xx) */
  /* v102: raspon datuma („20.11.-25.11.2026.”, „20. 11. – 25. 11.”) kao cjelina */
  $protect('~(?<!\d)(\d{1,2})\.\s?(\d{1,2})\.(?:\s?20\d{2}\.?)?\s?[-–]\s?(\d{1,2})\.\s?(\d{1,2})\.(?:\s?20\d{2}\.?)?(?!\d)~u', /* provjera v102: godina samo 20xx („09.12.3456.-7.8.” nije raspon datuma) */ fn($m) => (int)$m[1][0] >= 1 && (int)$m[1][0] <= 31 && (int)$m[2][0] >= 1 && (int)$m[2][0] <= 12 && (int)$m[3][0] >= 1 && (int)$m[3][0] <= 31 && (int)$m[4][0] >= 1 && (int)$m[4][0] <= 12);
  $protect('~(?<!\d)(\d{1,2})\.\s?(\d{1,2})\.(?:\s?(?:19|20)\d{2}\.?)?(?:,?\s?(?:u\s)?\d{1,2}:\d{2})?(?!\d)~', /* provjera v102: godina 19xx/20xx („09.12.3456.” nije datum) */ fn($m) => (int)$m[1][0] >= 1 && (int)$m[1][0] <= 31 && (int)$m[2][0] >= 1 && (int)$m[2][0] <= 12); /* datum */
  $protect('~(?<!\d)(?:[01]?\d|2[0-4]):[0-5]\d\s?[-–]\s?(?:[01]?\d|2[0-4]):[0-5]\d(?!\d)~u'); /* krug 3: vrijeme od–do kao cjelina („8:00-16:00”) */
  $protect('~(?<!\d)(?:[01]?\d|2[0-4]):[0-5]\d(?!\d)~'); /* provjera v102 (krug 2, sigurnost N1): samo stvarno vrijeme („38:59” nije) */
  /* v102 (recenzija vijeća v101, sigurnost N5): mjere („22 × 14 × 6, 28 × 22 × 8 cm”) — najviše dvoznamenkasti brojevi bez vodeće nule */
  $dim = '[1-9]\d?(?:[.,]\d)?(?:\s?[×x]\s?[1-9]\d?(?:[.,]\d)?){1,2}';
  $protect('~(?<![\d×x])' . $dim . '(?:\s?[,;/]\s?' . $dim . '){0,2}(?=\s?(?:cm|mm)\b)~u');
  /* v100: kućni broj i poštanski broj („Ilica 123/4, 10000 Zagreb”) — zarez i razmak, pa pet znamenki i naziv mjesta */
  /* provjera v102 (sigurnost N2): i raspon kućnih brojeva („Ilica 12-14, 10000 Zagreb”), poštanski broj na kraju i oznaka paketomata s karte („…106/1, 10000 (ID 4051)”) */
  /* recenzija v102 (sigurnost N2/N4): i četveroznamenkasti kućni broj ili broj stana („Savska cesta 1060, 10000 …”, „stan 1234, 10000 Zagreb”), osim onih na 9
     („9123, 45678” je mobitel bez nule) */
  $protect('~(?<![\d/])([1-9]\d{0,2}|[1-8]\d{3})(?:[-–]([1-9]\d{0,2})|/\d{1,2})?[a-zA-Z]?,\s?[1-5]\d{4}(?:\s?\(ID\s?\d{1,5}\))?(?=\s\p{L}|\s*$)~u', /* recenzija v101: iza kose crte najviše dvije znamenke („385/912, 34567” nije kućni broj) */ function ($m, $subj) {
    /* provjera v102 (krug 2, sigurnost N2): raspon kućnih brojeva je uzlazan i bez kose crte („12-14”; „385-91/23, 45678” nije adresa) */
    if (isset($m[2]) && $m[2][0] !== '' && (int)$m[2][0] <= (int)$m[1][0]) return false; $bf = mb_strcut($subj, max(0, $m[0][1] - 40), min(40, $m[0][1]), 'UTF-8');
    /* v101 (recenzija vijeća v100, sigurnost): riječi samo na početku riječi i u cijelom obliku — „Hrvatskih branitelja 123/4”, „Trg Ivana Zovka 5” ostaju adrese */
    return (bool)preg_match('~\p{L}{3,}\.?\s$~u', $bf) && !preg_match('~(?<!\p{L})(?:tel(?:efon\p{L}*)?|mob(?:itel\p{L}*|iln\p{L}*)?|broj\p{L}*|kontakt(?!\s+adres)\p{L}*|nazov\p{L}*|zovi\p{L}*|zove\p{L}*|poziv\p{L}*|whats\p{L}*|viber|sms|telegram|signal|messenger)(?!\p{L})~iu', $bf); }); /* recenzija v100: ispred mora biti naziv ulice, a ne „nazovite”, „tel”, „broj” */ /* kućni broj ne počinje nulom, poštanski broj u RH 1xxxx–5xxxx — „0912, 34567 Zagreb” se ipak skriva */                                                                     /* vrijeme */
  /* provjera v102 (sigurnost N4): sati od–do („6-14, 14-22, 22-6”) */
  $protect('~(?<![\d:.,/\x01-])(?:[01]?\d|2[0-4])\s?[-–]\s?(?:[01]?\d|2[0-4])(?![\d:/\x02]|\.\d)(?!\s?[-–]\s?\d)~u'); /* krug 2: točka na kraju rečenice ne smeta */
  /* provjera v102 (krugovi 1–3): zaštićeni dijelovi procjenjuju se po SKUPINAMA — znamenke i zaštićeni dijelovi na najviše dva znaka razmaka,
     zatim i na šest (koliko premošćuje pravilo za telefon). Maska pritom ne smije biti slabija od v101 („znamenka u tri znaka skida zaštitu”):
     - skupina s manje od devet znamenki ostaje (premalo za telefon);
     - jedan zaštićeni dio sam ostaje; uz širi razmak smiju uz datum s godinom ili broj narudžbe stajati do dvije druge znamenke („20.11.2026. — 5 kom”);
     - dva zaštićena dijela ostaju samo kao termin: vrijeme (ili od–do) uz datum (ili raspon) s godinom („8:00-16:00, 20.11.-25.11.2026.”);
     - sve ostalo (tri „raspona sati”, „vremena” ili „datuma” zaredom, zaštićeni dio uz druge znamenke) ide pod masku — tako se telefon
       ne može složiti od zaštićenih dijelova („09-8 12-3 14-5”, „09:12 3:45 6:07”, „09:12 345 678”). */
  $isTime = fn(string $t): bool => (bool)preg_match('~^\d{1,2}:\d{2}(?:\s?[-–]\s?\d{1,2}:\d{2})?$~u', $t);
  $isDateY = fn(string $t): bool => (bool)preg_match('~^\d{1,2}\.\s?\d{1,2}\.(?:\s?(?:19|20)\d{2}\.?)?(?:\s?[-–]\s?\d{1,2}\.\s?\d{1,2}\.(?:\s?(?:19|20)\d{2}\.?)?)?(?:,?\s?(?:u\s)?\d{1,2}:\d{2})?$~u', $t) && (bool)preg_match('~\.\s?(?:19|20)\d{2}~', $t);
  /* recenzija v102 (sigurnost N2): maska se sada primjenjuje uvijek, pa ne smije kvariti obične napomene. Uz JEDAN zaštićeni dio smiju stajati do dvije
     druge znamenke („20.11.2026., 2 komada”, „Narudžba 2026-0001, 2 kom”, „Ilica 5, 20.11.2026.”, „8:00-16:00, 12. kat”) kad je taj dio datum s godinom,
     broj narudžbe ili vrijeme od–do na četvrt sata — telefon u takav oblik stane samo iznimno (godina 19xx/20xx na 5.–8. mjestu, odnosno minute 00/15/30/45
     na dva mjesta). Popis tri ili četiri datuma „dd.mm.” odvojenih zarezom i razmakom ostaje („23.11., 24.11., 25.11., 26.11.”): 12 ili 16 znamenki s
     ispravnim danom i mjesecem nije zapis telefona (385…, 00385… i 09… ne daju ispravne datume te duljine). */
  $isOrd = fn(string $t): bool => (bool)preg_match('~^(?:SJ-)?20[2-4]\d-\d{4,6}~i', $t); /* krug 4 (sigurnost N2): godina trgovine, ne „2071-234567” */
  $isQRange = fn(string $t): bool => (bool)preg_match('~^\d{1,2}:(?:00|15|30|45)\s?[-–]\s?\d{1,2}:(?:00|15|30|45)$~u', $t);
  $isDM = fn(string $t): bool => (bool)preg_match('~^\d{2}\.\d{2}\.$~', $t);
  foreach ([2, 6] as $gap) {
    $s = preg_replace_callback('~(?:\x01\d+\x02|\d)(?:[^\p{L}\d\p{Sc}\x01\x02]{0,' . $gap . '}(?:\x01\d+\x02|\d))+~u', function ($m) use (&$keep, $gap, $isTime, $isDateY, $isOrd, $isQRange, $isDM) {
      $run = $m[0]; $nTok = preg_match_all('~\x01(\d+)\x02~', $run, $mm);
      if (!$nTok) return $run;
      $left = preg_match_all('~\d~', (string)preg_replace('~\x01\d+\x02~', '', $run));
      $toks = array_map(fn($i) => (string)($keep[(int)$i] ?? ''), $mm[1]);
      $tokDigits = 0; foreach ($toks as $t) $tokDigits += preg_match_all('~\d~', $t);
      if ($left + $tokDigits < 9) return $run;
      /* do dvije druge znamenke smiju stajati samo uz datum s godinom, broj narudžbe ili vrijeme od–do na četvrt sata, ne uz svako vrijeme („09:12-3:45 - 67”) */
      /* krug 4 (sigurnost N2): uz broj narudžbe druge znamenke smiju stajati samo IZA njega („Narudžba 2026-0001, 2 kom”), ne ispred („+44 2025-550123”, „09 2012-3456”) */
      if ($nTok === 1 && ($left === 0 || ($left < 3 && ($isDateY($toks[0]) || ($isOrd($toks[0]) && str_starts_with($run, "\x01")) || $isQRange($toks[0]))))) return $run;
      if ($nTok >= 3 && $nTok <= 4 && $left === 0 && count(array_filter($toks, $isDM)) === $nTok && preg_match('~^\x01\d+\x02(?:, \x01\d+\x02)+$~', $run)) return $run;
      if ($nTok === 2 && $left === 0 && (($isTime($toks[0]) && $isDateY($toks[1])) || ($isDateY($toks[0]) && $isTime($toks[1])))) return $run;
      return (string)preg_replace_callback('~\x01(\d+)\x02~', fn($x) => $keep[(int)$x[1]] ?? '', $run);
    }, $s) ?? $s;
  }
  /* IBAN: oznaka zemlje + kontrolni broj + znamenke (HR), ili skupine po 4 (i malim slovima, uz barem 6 znamenki) */
  /* v99: razdjelnik je bilo što osim slova, znamenke i oznake valute (zarez, točka-zarez, |, *, ~, crtice, novi red, više razmaka) */
  $s = preg_replace('~\b[A-Za-z]{2}[^\p{L}\d\p{Sc}\x01\x02]{0,3}\d{2}(?:[^\p{L}\d\p{Sc}\x01\x02]{0,4}\d){11,30}(?!\d)~u', '[IBAN skriven]', $s) ?? '';
  $s = preg_replace_callback('~\b[A-Za-z]{2}\d{2}(?:[ _.\-]{0,2}[A-Za-z0-9]{4}){3,7}(?:[ _.\-]{0,2}[A-Za-z0-9]{1,4})?\b~', fn($m) => preg_match_all('~\d~', $m[0]) >= 6 ? '[IBAN skriven]' : $m[0], $s) ?? '';
  /* telefon i račun: 9 ili više znamenki s razmacima, crticama, točkama, kosim crtama, podvlakama i zagradama između */
  $s = preg_replace('~\+?\(?\d(?:(?:[^\p{L}\d\p{Sc}\x01\x02]|\p{Lm}){0,6}\d){8,}\)?~u', '[broj skriven]', $s) ?? ''; /* v101: i slova-modifikatori (ʼ) kao razdjelnik */ /* v100 (recenzija vijeća v99, sigurnost N2): bez iznimke za „zarez i razmak” — adresa je zaštićena gore */
  return preg_replace_callback('~\x01(\d+)\x02~', fn($m) => $keep[(int)$m[1]] ?? '', $s) ?? '';
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
      if (!empty($o['paidAt']) || !empty($o['cancelledAt']) || !empty($o['unconfirmed']) || !empty($o['unpaidAt']) || sj_withdrawn($o) || empty($o['email']) || empty($o['payUntil'])) continue;
      /* v98 (recenzija vijeća v97, sigurnost M1): izjava bez poveznice iz e-maila — bez podsjetnika i e-maila „rok prošao”, ali rok i storno teku */
      /* v99 (recenzija vijeća v98, sigurnost N3): neprovjerena izjava (bez poveznice iz e-maila) ne zaustavlja ništa — podsjetnik i „rok prošao”
         dobivaju napomenu o izjavi (sj_deadline_mail), storno teče; samo izjava preko poveznice (sj_withdrawn) sve zaustavlja */
      $at = (int)strtotime((string)($o['at'] ?? '')); $until = (int)strtotime((string)$o['payUntil']);
      if ($at <= 0 || $until <= 0) continue;
      if ($now >= $until) {
        if (empty($o['expiredAt'])) { $o['expiredAt'] = sj_iso($now); $o['cancelDue'] = sj_iso(sj_cancel_due($o, $auto)); $todo[] = ['expired', $o]; continue; } /* v99: i uz neprovjerenu izjavu (s napomenom u e-mailu) */ /* v92: rok storna prema postavci u trenutku isteka (isti kao u e-mailu) */
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
    (count($canc) === 1 ? "Ova narudžba automatski je stornirana" : "Ove narudžbe automatski su stornirane") /* v102 (recenzija vijeća v101, dizajn 5): jednina */ . " jer uplata nije označena ni " . sj_hours_text($auto) . " nakon isteka roka (subota, nedjelja i blagdan pomiču storno na 23:59 radnog dana):\n\n"
    . implode("\n", array_map(fn($x) => '- ' . (string)($x[1]['no'] ?? '') . ' — ' . (string)($x[1]['name'] ?? '') . ', ' . (string)($x[1]['total'] ?? '') . ', poziv na broj ' . sj_payment_ref((string)($x[1]['no'] ?? '')), $canc))
    . "\n\n" . (count($canc) === 1 ? 'Kupac je dobio' : 'Kupci su dobili') . " e-mail o stornu. Ako ipak vidite uplatu, u adminu → Narudžbe kliknite „Označi plaćenom”: ako je komad još slobodan, ostaje kupcu."
    . (sj_site_url_trusted() !== '' ? "\n\n" . sj_site_url_trusted() . '/admin/?s=narudzbe' : ''));
  return array_map(fn($x) => [$x[0], (string)($x[1]['no'] ?? '')], $todo);
}
function sj_deadline_mail(string $kind, array $o, array $c, int $auto): void {
  /* vijeće V78-S9: narudžba je možda u međuvremenu stornirana (raskid) ili plaćena — tada bez e-maila o roku */
  if ($kind !== 'cancel') { $fresh = sj_order_find(['no' => (string)($o['no'] ?? '')]); if ($fresh && (!empty($fresh['cancelledAt']) || !empty($fresh['paidAt']))) return; }
  /* v90 (vijeće): ni e-mail o stornu ako je vlasnica u međuvremenu označila plaćeno */
  else { $fresh = sj_order_find(['no' => (string)($o['no'] ?? '')]); if ($fresh && !empty($fresh['paidAt'])) return; }
  $okC = true;
  $no = (string)($o['no'] ?? ''); $name = (string)($o['name'] ?? ''); $until = sj_fmt_deadline(sj_pay_until($o)); $untilAcc = sj_fmt_deadline(sj_pay_until($o), 'acc');
  $uq = []; foreach ((array)($o['order']['items'] ?? []) as $it) if (empty($it['made'])) $uq[] = '„' . (string)($it['name'] ?? '') . '”';
  $many = count($uq) > 1;
  $hi = sj_hi($name) . "\n\n"; $bye = "\n\nSrdačan pozdrav,\n" . sj_signature();
  /* v99: uz neprovjerenu izjavu o raskidu podsjetnik i „rok prošao” to kažu (kupac koji je poslao izjavu ne treba plaćati) */
  if (($kind === 'remind' || $kind === 'expired') && sj_withdrawn($o, false)) $hi .= "Za ovu narudžbu zaprimili smo i izjavu o jednostranom raskidu ugovora, poslanu bez poveznice iz e-maila. Ako ste je poslali vi, narudžbu ne trebate platiti: odgovorite na ovaj e-mail pa je storniramo odmah, a inače se automatski stornira nakon roka. Ako izjavu niste poslali vi, ovaj odlomak zanemarite.\n\n";
  /* v70 (odluka vlasnice, 5. krug): jedno obećanje — „Uplatio/la sam” čuva do točnog datuma; snimka je drugi put */
  $claim = empty($o['claimedAt']) ? sj_claim_url($o) : '';
  $alt = 'Link ne radi? Odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke.';
  if ($kind === 'remind') {
    $hold = $uq ? ($many ? 'torbe' : 'torbu') . ' čuvamo' : 'rok za uplatu produljujemo';
    /* v70 (D70-2): tekst po tome je li kupac kliknuo, ne po tome postoji li link */
    $claimTxt = !empty($o['claimedAt']) ? 'Javili ste nam da ste uplatili; ' . sj_hold_phrase($o) . ' do ' . $until . '. Ako uplata do tada ne stigne, javit ćemo vam se.'
      : ($claim !== '' ?  'Kliknite „Uplatio/la sam” i ' . $hold . ' ' . sj_claim_preview($o) . ":\n$claim\n($alt)"
      : 'Odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke, pa ćemo provjeriti izvod.');
    /* v69 (odluka vlasnice): podaci za uplatu u podsjetniku, ali bez barkoda (da se ne plati dvaput) */
    $okC = sj_send_mail((string)$o['email'], $c['shop'] . " — podsjetnik za uplatu, narudžba $no",
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
      $okC = sj_send_mail((string)$o['email'], $c['shop'] . " — uplatu još ne vidimo, narudžba $no",
        /* D72-4: inačica „primili smo potvrdu” kad god je potvrda zabilježena */
        $hi . ($pf ? "primili smo vašu potvrdu o uplati za narudžbu br. $no, ali uplatu još ne vidimo na računu. Molimo vas, provjerite sa svojom bankom je li uplata izvršena i odgovorite na ovaj e-mail."
          : "javili ste nam da ste platili narudžbu br. $no, ali uplatu još ne vidimo na računu. Molimo vas, odgovorite na ovaj e-mail i pošaljite potvrdu o uplati (snimku ili potvrdu iz banke).")
        . ($hs !== '' ? "\n\n" . $hs : '')
        /* D72-5: po torbi */
        . ($taken ? ($hs !== '' ? ' ' : "\n\n") . 'Nažalost, ' . (count($taken) > 1 ? 'torbe ' . $Q($taken) . ' u međuvremenu su otišle' : 'torba ' . $Q($taken) . ' u međuvremenu je otišla') . ' drugom kupcu; ako ste ' . (count($taken) > 1 ? 'ih' : 'je') . ' već platili, za ' . (count($taken) > 1 ? 'njih' : 'nju') . ' vam vraćamo uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.' : '')
        . ($free ? ($hs !== '' ? ' ' : "\n\n") . (count($free) > 1 ? 'Torbe ' . $Q($free) . ' zasad su' : 'Torba ' . $Q($free) . ' zasad je') . ' ponovno u ponudi; ako ' . (count($free) > 1 ? 'neku od njih' : 'je') . ' netko kupi prije nego što vaša uplata stigne, za ' . (count($free) > 1 ? 'nju' : 'nju') . ' vam vraćamo uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.' : '')
        . ($hs !== '' ? ' Ako do tada ' . $cond . ', narudžbu storniramo i ' . (count($hI['held']) > 1 ? 'torbe se vraćaju' : 'torba se vraća') . ' u prodaju.' : $stornoP)
        . $bye, null, (string)$c['owner_email']);
    } else {
      /* v70: klik nakon isteka više ne čuva torbu (v69.1) — javlja vlasnici, koja provjeri izvod i javi se */
      $okC = sj_send_mail((string)$o['email'], $c['shop'] . " — rok za uplatu je prošao, narudžba $no",
        $hi . "rok za uplatu narudžbe br. $no prošao je u $untilAcc, a uplatu još ne vidimo" . (($hsN = sj_held_sentence(sj_held_info($o))) !== '' ? '. ' . rtrim($hsN, '.') . ', jer obična uplata poslana do roka može stići sljedeći radni dan' : ($back !== '' ? ", pa $back" : '')) . '.' /* v81 (p2) */
        . "\n\nVeć ste platili? " . ($claim !== '' ? "Kliknite „Uplatio/la sam”:\n$claim\n(ili odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke)." : 'Odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke.')
        . ' Ako ste nam snimku potvrde već poslali, ništa više ne trebate poduzimati. Provjerit ćemo izvod i javiti vam se' . ($uq ? '; ako je ' . ($many ? 'neka od torbi' : 'torba') . ' u međuvremenu otišla drugom kupcu, vraćamo vam uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.' : '.')
        . "\n\nAko ste i dalje zainteresirani za narudžbu, uplatite iznos i pošaljite nam potvrdu o uplati" . ($uq ? '; ako ' . ($many ? 'torbe' : 'torbu') . ' u međuvremenu nije kupio netko drugi, ' . ($many ? 'one su vaše' : 'ona je vaša') . '.' : '.')
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
  } elseif (sj_withdrawn($o, false)) {
    /* recenzija v98: uz izjavu o raskidu (bez poveznice iz e-maila) storno ne obećava povrat s kamatama, nego povrat kao kod raskida */
    $okC = sj_send_mail((string)$o['email'], $c['shop'] . " — narudžba $no je stornirana",
      $hi . "narudžba br. $no je stornirana: uplata nije stigla, a za ovu narudžbu zaprimili smo i izjavu o jednostranom raskidu ugovora." . ($uq ? ' ' . ($many ? 'Torbe su ponovno' : 'Torba je ponovno') . ' u prodaji.' : '')
      . "\n\nAko ste ipak platili, cijeli iznos vraćamo najkasnije 14 dana od primitka izjave, na račun s kojeg ste platili (Uvjeti kupnje, točka 7.); javite nam se odgovorom na ovaj e-mail." . $bye, null, (string)$c['owner_email']); /* v100 (softver N6): i ovdje odgovor ide vlasnici */
  } else {
    $okC = sj_send_mail((string)$o['email'], $c['shop'] . " — narudžba $no je stornirana",
      $hi . "narudžba br. $no je stornirana jer uplata nije stigla ni nakon roka." . ($uq ? ' ' . ($many ? 'Torbe su ponovno' : 'Torba je ponovno') . ' u prodaji.' : '')
      . "\n\nAko ste ipak platili, javite nam se odgovorom na ovaj e-mail i riješit ćemo to: " . ($uq ? ($many ? 'ako su torbe još slobodne, šaljemo vam ih' : 'ako je torba još slobodna, šaljemo vam je') /* v102: množina */ . ', a inače vam vraćamo uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.' : 'narudžbu obnavljamo ili vam vraćamo uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.') . $bye, null, (string)$c['owner_email']);
  }
  /* v90 (vijeće): neuspjeh slanja kupcu bilježi se uz narudžbu (admin → Narudžbe) */
  if (!$okC) { try { sj_order_edit(['no' => $no], function (array &$x) use ($kind) { $x['mailFail'][$kind] = sj_iso(time()); }); } catch (Throwable $e) {} }
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
    $next = 'Sada izrađujemo ' . (count($made) > 1 ? 'artikle ' : 'artikl ') . $q($made) . $ld /* v95: nominativ uz „artikl” */
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
      ? "\n\nAko ste ipak platili, javite nam se odgovorom na ovaj e-mail i riješit ćemo to: " . ($uq ? (count($uq) > 1 ? 'ako su torbe još slobodne, šaljemo vam ih' : 'ako je torba još slobodna, šaljemo vam je') /* v102: množina */ . ', a inače vam vraćamo uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.' : 'narudžbu obnavljamo ili vam vraćamo uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana.')
      : ($reason === 'kupac odustao' /* v93 (recenzija): raskid kupca — povrat u 14 dana, bez kamata (ZZP čl. 83) */
        ? "\n\nAko ste je već platili, cijeli iznos vraćamo najkasnije 14 dana od primitka vaše izjave, na račun s kojeg ste platili (Uvjeti kupnje, točka 7.)."
        : "\n\nAko ste je već platili, javite nam se odgovorom na ovaj e-mail i vratit ćemo vam uplaćeni iznos uvećan za zakonske zatezne kamate od dana uplate do dana povrata, što prije, a najkasnije u roku od sedam dana."))
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
function sj_rl_attacker_key(string $k): bool { foreach (['sve|dan|', 'sve|raskid_to|', 'sve|mailto-', 'sve|pow|', 'sve|c24|', 'sve|cdom|'] as $p) if (str_starts_with($k, $p)) return true; return false; }
function sj_rate_limit(string $key, int $max, int $windowSec, bool $short = false, bool $record = true): bool {
  $ip = sj_short_ip((string)($_SERVER['REMOTE_ADDR'] ?? '?'));
  $now = time();
  return (bool)sj_with_lock(SJ_DATA . '/zahtjevi.json', function (array &$d) use ($key, $ip, $max, $windowSec, $now, $record) {
    foreach ($d as $k => $times) {
      /* v90 (recenzija): dnevna granica po primatelju ('sve|dan|') čuva se 24 h, ostale 'sve|' 1 h */
      $keep = (str_starts_with($k, 'sve|dan|') || str_starts_with($k, 'sve|dnevno|')) ? 86400 : ((str_starts_with($k, 'visit|') || str_starts_with($k, 'count|') || str_starts_with($k, 'sve|')) ? 3600 : 86400);
      $times = array_values(array_filter((array)$times, fn($t) => (int)$t > $now - $keep));
      if ($times) $d[$k] = $times; else unset($d[$k]);
    }
    /* gornja granica veličine: najviše 5000 ključeva. v20: zaštitni ključevi 'sve|' (ukupne granice i granice po
       primatelju) nikad se ne brišu; prvi ispadaju brojači posjeta i klikova ('visit|', 'count|'), zatim najstariji */
    /* v94 (recenzija vijeća, H1): zaštićeni su samo pravi ukupni brojači; ključeve koje napadač može stvarati sam
       (po primatelju: 'sve|dan|', 'sve|raskid_to|', 'sve|mailto-', 'sve|pow|') ne smiju istisnuti granice po adresi —
       redoslijed izbacivanja: posjeti i klikovi, zatim napadačevi ključevi, tek onda ključevi po adresi (najstariji prvi) */
    /* v100 (recenzija vijeća v99, sigurnost N4): odvojene kvote — ključevi po vezi (do 4000) i ključevi po primatelju/mreži/domeni
       (sj_rl_attacker_key, do 4000) istiskuju samo sebe; tisuće ključeva po vezi više ne mogu izbrisati dnevnu granicu žrtve (i obrnuto).
       Pravi ukupni brojači ('sve|' bez napadačevih) se ne brišu. Unutar kvote prvi ispadaju posjeti i klikovi, zatim najstariji. */
    $byAge = function (array $x): array { uasort($x, fn($a, $b) => max((array)$b) <=> max((array)$a)); return $x; }; /* najnoviji prvi */
    $tot = array_filter($d, fn($k) => str_starts_with((string)$k, 'sve|') && !sj_rl_attacker_key((string)$k), ARRAY_FILTER_USE_KEY);
    $rcp = array_filter($d, fn($k) => sj_rl_attacker_key((string)$k), ARRAY_FILTER_USE_KEY);
    $ipk = array_diff_key($d, $tot, $rcp);
    if (count($rcp) > 4000) $rcp = array_slice($byAge($rcp), 0, 4000, true);
    if (count($ipk) >= 4000) {
      $cnt = array_filter($ipk, fn($k) => str_starts_with((string)$k, 'visit|') || str_starts_with((string)$k, 'count|'), ARRAY_FILTER_USE_KEY);
      $oth = $byAge(array_diff_key($ipk, $cnt));
      $ipk = count($oth) >= 3999 ? array_slice($oth, 0, 3999, true) : $oth + array_slice($byAge($cnt), 0, 3999 - count($oth), true);
    }
    $d = $tot + $rcp + $ipk;
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
/* v90 (vijeće): najveći broj narudžbe koji već postoji (narudzbe.json i arhive) — brojač nikad ne ide unatrag,
   ni kad se config.json vrati iz sigurnosne kopije ili postavi iznova (ponovljen poziv na broj = zabuna pri uplati) */
function sj_order_seq_floor(): int {
  $max = 0;
  foreach (array_merge([SJ_DATA . '/narudzbe.json'], glob(SJ_DATA . '/narudzbe-arhiva-*.json') ?: []) as $f) {
    foreach ((array)sj_read_json($f, []) as $o) if (is_array($o) && preg_match('/^SJ-\d{4}-(\d+)$/', (string)($o['no'] ?? ''), $m)) $max = max($max, (int)$m[1]);
  }
  return $max;
}
function sj_next_order_seq(): int {
  $floor = sj_order_seq_floor(); $seq = 0;
  sj_config_update(function (array &$cfg) use (&$seq, $floor) {
    $cfg['order_seq'] = max((int)($cfg['order_seq'] ?? 0), $floor) + 1;
    $seq = (int)$cfg['order_seq'];
  });
  return $seq;
}

function sj_orders_prepend(array $entry, int $keep = 300): void {
  sj_with_lock(SJ_DATA . '/narudzbe.json', function (array &$list) use ($entry, $keep) {
    array_unshift($list, $entry);
    if (count($list) > $keep) {
      /* v95 (recenzija vijeća v94, L1): u arhivu idu samo zatvorene narudžbe (plaćene, stornirane, obrisane); otvorena (čeka uplatu,
         izjava o raskidu, javljena uplata) ostaje u glavnom popisu, pa je rokovi, „Uplatio/la sam” i admin i dalje vide.
         Krajnja granica 2 × $keep (i otvorene), da datoteka ne raste bez kraja */
      $closed = fn($o) => !empty($o['paidAt']) || !empty($o['cancelledAt']) || !empty($o['anonymizedAt']);
      $remove = [];
      for ($i = count($list) - 1; $i >= $keep && count($list) - count($remove) > $keep; $i--) if ($closed($list[$i])) $remove[$i] = true;
      $old = []; $new = [];
      foreach ($list as $i => $o) { if (isset($remove[$i])) $old[] = $o; else $new[] = $o; }
      if (count($new) > 2 * $keep) { $old = array_merge($old, array_slice($new, 2 * $keep)); $new = array_slice($new, 0, 2 * $keep); }
      $list = $new;
      if ($old) sj_with_lock(SJ_DATA . '/narudzbe-arhiva-' . date('Y') . '.json', function (array &$a) use ($old) { foreach (array_reverse($old) as $o) $a[] = $o; return true; }, []);
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

/* v96 (recenzija vijeća v95, sigurnost N8): poslužitelj vidi adresu posrednika umjesto posjetitelja — privatna, rezervirana
   (uključujući 127.0.0.1 i ::1) ili ista kao adresa poslužitelja; ista provjera na oba mjesta u adminu */
function sj_proxy_ip_suspect(): bool {
  $ra = (string)($_SERVER['REMOTE_ADDR'] ?? ''); $sa = (string)($_SERVER['SERVER_ADDR'] ?? '');
  return $ra === '' || $ra === $sa || !filter_var($ra, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
/* v96 (recenzija vijeća v95, N4): sve narudžbe jednom po zahtjevu — po broju, e-adresi i imenu (admin: izjave o raskidu) */
function sj_orders_index(): array {
  static $ix = null;
  if ($ix !== null) return $ix;
  $ix = ['no' => [], 'ek' => [], 'name' => []];
  foreach (sj_order_files() as $f) foreach (sj_read_json($f, []) as $o) {
    if (!is_array($o) || empty($o['no'])) continue;
    $no = (string)$o['no']; if (!isset($ix['no'][$no])) $ix['no'][$no] = $o;
    $em = (string)($o['order']['customer']['email'] ?? $o['email'] ?? ''); if ($em !== '') $ix['ek'][sj_email_key($em)] = $no;
    $nm = mb_strtolower(trim((string)($o['name'] ?? ''))); if ($nm !== '') $ix['name'][$nm] = $no;
  }
  return $ix;
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
/* v93 (odluka vlasnice 12. 11.): provjera „niste robot” izračunom — preglednik kupca traži broj (nonce) uz koji
   sha256(zadatak:nonce) počinje s pow_bits nultih bitova; zadatak je potpisan tajnom, vrijedi 30 min, najviše 5 narudžbi */
function sj_pow_bits(): int {
  $b = max(0, min(24, (int)(sj_config()['pow_bits'] ?? 16)));
  if ($b <= 0) return 0;
  /* v94 (recenzija vijeća): kad je u zadnjem satu poslano više od 20 e-mailova za potvrdu, zadatak je 16 puta teži (+4 bita) */
  $conf = (array)(sj_read_json(SJ_DATA . '/zahtjevi.json', [])['sve|confirm'] ?? []);
  $n = count(array_filter($conf, fn($t) => (int)$t > time() - 3600));
  if ($n > 20) $b = min(22, $b + 4);
  /* v95 (recenzija vijeća v94, sigurnost 1): od ukupne granice (zadano 50) nove veze i dalje mogu naručiti, ali uz najteži zadatak */
  if ($n >= max(1, (int)((sj_config()['limits'] ?? [])['confirm_total'] ?? 50))) $b = max($b, min(22, $b + 6));
  return $b;
}
/* v98 (recenzija vijeća v97, sigurnost L1): ključ za cron — „cron zadnji put” u adminu bilježi samo poziv s ovim ključem
   (adresu s ključem admin ispisuje na dnu stranice; upute za objavu, odjeljak 7) */
function sj_cron_key(): string { return substr(hash('sha256', 'cron|' . sj_pow_secret()), 0, 16); }
function sj_pow_secret(): string {
  $sec = (string)(sj_config()['pow_secret'] ?? '');
  /* v98 (recenzija vijeća v97, N1): prazan ili nečitljiv config.json se ne dira — privremena tajna samo za ovaj poslužitelj */
  $cp = SJ_DATA . '/config.json';
  if (strlen($sec) < 32 && is_file($cp) && sj_read_json($cp, null) === null) { static $tmp = null; if ($tmp === null) { $f = SJ_DATA . '/.pow-privremeno'; $tmp = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if (strlen($tmp) !== 64) { $tmp = bin2hex(random_bytes(32)); $tf = $f . '.tmp-' . bin2hex(random_bytes(4)); if (@file_put_contents($tf, $tmp) !== false) { if (is_file($f) && strlen(trim((string)@file_get_contents($f))) === 64) { @unlink($tf); $tmp = trim((string)file_get_contents($f)); } else @rename($tf, $f); } } } return $tmp; } /* recenzija v98: nasumična, ne iz putanje i imena poslužitelja */
  if (strlen($sec) < 32) { sj_config_update(function (array &$c) { if (strlen((string)($c['pow_secret'] ?? '')) < 32) $c['pow_secret'] = bin2hex(random_bytes(32)); }); $sec = (string)(sj_config(true)['pow_secret'] ?? ''); }
  return $sec;
}
function sj_pow_issue(): ?array {
  $bits = sj_pow_bits(); if ($bits <= 0) return null;
  $s = time() . '.' . bin2hex(random_bytes(8));
  return ['salt' => $s . '.' . substr(hash_hmac('sha256', $s, sj_pow_secret()), 0, 20), 'bits' => $bits];
}
function sj_pow_zero_bits(string $hex, int $bits): bool {
  $full = intdiv($bits, 4); if (strncmp($hex, str_repeat('0', $full), $full) !== 0) return false;
  $rem = $bits % 4; return $rem === 0 || hexdec($hex[$full]) < (1 << (4 - $rem));
}
function sj_pow_check($pow): bool {
  $bits = sj_pow_bits(); if ($bits <= 0) return true;
  if (!is_array($pow)) return false;
  $salt = (string)($pow['salt'] ?? ''); $nonce = (string)($pow['nonce'] ?? '');
  if (!preg_match('/^(\d{9,11})\.([0-9a-f]{16})\.([0-9a-f]{20})$/', $salt, $m) || !preg_match('/^\d{1,12}$/', $nonce)) return false;
  if (!hash_equals(substr(hash_hmac('sha256', $m[1] . '.' . $m[2], sj_pow_secret()), 0, 20), $m[3])) return false;
  $age = time() - (int)$m[1]; if ($age < -60 || $age > 1800) return false;
  if (!sj_pow_zero_bits(hash('sha256', $salt . ':' . $nonce), $bits)) return false;
  /* v94 (recenzija vijeća, H1): samo provjera — upis tek kad je narudžba primljena (sj_pow_spend), u zasebnu datoteku */
  $used = (array)(sj_read_json(SJ_DATA . '/pow-iskoristeno.json', [])[$m[2]] ?? []);
  return count(array_filter($used, fn($t) => (int)$t > time() - 3600)) < 5; /* isti zadatak najviše za 5 narudžbi */
}
function sj_pow_spend($pow): void {
  if (!is_array($pow) || !preg_match('/^\d{9,11}\.([0-9a-f]{16})\./', (string)($pow['salt'] ?? ''), $m)) return;
  $now = time();
  sj_with_lock(SJ_DATA . '/pow-iskoristeno.json', function (array &$d) use ($m, $now) {
    foreach ($d as $k => $ts) { $ts = array_values(array_filter((array)$ts, fn($t) => (int)$t > $now - 3600)); if ($ts) $d[$k] = $ts; else unset($d[$k]); }
    $d[$m[1]] = array_merge((array)($d[$m[1]] ?? []), [$now]);
    if (count($d) > 5000) { uasort($d, fn($a, $b) => max((array)$a) <=> max((array)$b)); $d = array_slice($d, -5000, null, true); } /* najstariji ispadaju */
    return true;
  }, []);
}
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

/* v95 (recenzija vijeća v94, L2): sprema samo ključeve koje je ovaj zahtjev promijenio (u odnosu na stanje na početku zahtjeva) —
   istodobna promjena u drugom zahtjevu (brojač neuspjelih prijava, tajna „niste robot”, lozinka iz druge kartice) se ne gubi */
function sj_save_config(array $c): void {
  $snap = $GLOBALS['sj_cfg_snap'] ?? null;
  sj_config_update(function (array &$d) use ($c, $snap) {
    $seq = max((int)($d['order_seq'] ?? 0), (int)($c['order_seq'] ?? 0));
    if (is_array($snap)) {
      foreach ($c as $k => $v) if (!array_key_exists($k, $snap) || $snap[$k] !== $v) $d[$k] = $v;
      foreach ($snap as $k => $_) if (!array_key_exists($k, $c)) unset($d[$k]);
    } else $d = $c;
    $d['order_seq'] = $seq;
  });
  $GLOBALS['sj_cfg_snap'] = sj_config();
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
  $hostFull = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
  if (!preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/', $hostFull)) return ''; /* v102 (recenzija vijeća v101, sigurnost N4): cijelo zaglavlje, ne samo dio prije dvotočke („127.0.0.1:80@zlo.example”) */
  $host = explode(':', $hostFull)[0];
  if ($host === '') return '';
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
  /* v98 (recenzija vijeća v97, N5): jedinstvena privremena datoteka (dva istodobna zapisa se ne sudaraju) i fsync prije
     preimenovanja (nestanak struje ne ostavlja praznu datoteku) */
  $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
  $fh = @fopen($tmp, 'wb'); $n = $fh ? @fwrite($fh, $json) : false;
  if ($fh) { @fflush($fh); if (function_exists('fsync')) @fsync($fh); fclose($fh); }
  if ($n !== strlen($json)) { @unlink($tmp); throw new RuntimeException('Zapis nije uspio (disk?): ' . basename($path)); }
  if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Zapis nije uspio (preimenovanje): ' . basename($path)); }
}

/* Zaključani rad nad JSON datotekom: $fn(array &$data) smije mijenjati $data; vraća što god želi. */
function sj_is_critical_data(string $base): bool {
  /* narudzbe-na-cekanju.json nije ovdje: drži samo nepotvrđene narudžbe (link vrijedi 1 h), pa se oštećena odlaže i počinje prazna */
  return in_array($base, ['rezervacije.json', 'narudzbe.json', 'raskidi.json', 'proizvodi.json'], true) || (bool)preg_match('/^narudzbe-arhiva-\d{4}\.json$/', $base);
}
/* oštećena važna datoteka: zapis za admin (crvena traka) i jedan e-mail vlasnici dnevno */
function sj_corrupt_note(string $base): void {
  $f = SJ_DATA . '/ostecene-datoteke.json';
  $d = (array)sj_read_json($f, []); $first = !isset($d[$base]) || substr((string)$d[$base], 0, 10) !== date('Y-m-d');
  if ($first) { $d[$base] = date('c'); @file_put_contents($f, json_encode($d), LOCK_EX); } /* upis samo prvi put u danu */
  if ($first) { try { $c = sj_config(); if (!empty($c['owner_email'])) sj_send_mail((string)$c['owner_email'], (string)($c['shop'] ?? 'Shiva.J') . ' — HITNO: oštećena datoteka ' . $base,
    "Datoteka data/$base nije čitljiva. " . ($base === 'config.json'
      ? "Narudžbe su zaustavljene dok se datoteka ne vrati (kupci vide poruku o tehničkoj smetnji), a izmjene u adminu se ne spremaju.\n\nVratite datoteku iz dnevne kopije (data/kopije/GGGG-MM-DD/$base) u mapu data/ (upute za objavu, odjeljak D).\n" /* v99: config.json ne zaustavlja narudžbe i nema kopije .osteceno */
      : "Stranica je zaustavila narudžbe da se ništa ne izgubi.\n\nVratite datoteku iz dnevne kopije (data/kopije/GGGG-MM-DD/$base) u mapu data/ (upute za objavu, odjeljak D). Oštećena kopija spremljena je kao data/$base.osteceno-datum.\n")); } catch (Throwable $e) {} }
}

/* v90 (vijeće): jednom dnevno kopija data/*.json u data/kopije/GGGG-MM-DD/ (čuva se 14 dana; data/ je zaštićen,
   pa i kopije). Pokreće je cron api/status ili otvaranje admina. Oštećena datoteka se ne kopira (ostaje jučerašnja). */
function sj_daily_backup(int $keepDays = 14): ?string {
  $root = SJ_DATA . '/kopije'; $day = date('Y-m-d'); $dir = $root . '/' . $day;
  if (is_file($dir . '/.gotovo')) return null; /* v98 (recenzija vijeća v97, N5): napola kopiran dan dovršava se pri sljedećem zahtjevu */
  if (!is_dir($root) && !@mkdir($root, 0755, true)) return null;
  $fp = @fopen($root . '/.lock', 'c'); if (!$fp) return null;
  try {
    if (!flock($fp, LOCK_EX | LOCK_NB) || is_file($dir . '/.gotovo')) return null;
    if (!is_dir($dir) && !@mkdir($dir, 0755)) return null;
    foreach (glob(SJ_DATA . '/*.json') ?: [] as $f) {
      if (filesize($f) > 0 && sj_read_json($f, null) === null) continue;
      if (filesize($f) === 0 && (sj_is_critical_data(basename($f)) || basename($f) === 'config.json')) continue; /* v96: prazna kritična datoteka ne prepisuje dobru kopiju; v98: ni prazan config.json */
      @copy($f, $dir . '/' . basename($f));
    }
    /* v96–v98: i dodatni popis izjava o raskidu, do pune granice, samo kad se promijenio (14 dana × 20 MB napunilo bi disk) */
    /* v99 (recenzija vijeća v98, L4): usporedba s najnovijom kopijom koja tu datoteku stvarno sadrži (ne s jučerašnjom, koja ju je
       možda preskočila); prvog dana u mjesecu i datoteka prošlog mjeseca (izjave sa zadnjeg dana mjeseca) */
    $bks = array_values(array_filter(array_map('basename', glob($root . '/????-??-??', GLOB_ONLYDIR) ?: []), fn($b) => $b !== $day)); rsort($bks);
    $ovFiles = [sj_raskid_overflow_path(time())];
    if ((int)gmdate('j') === 1) $ovFiles[] = sj_raskid_overflow_path(gmmktime(0, 0, 0, (int)gmdate('n') - 1, 1, (int)gmdate('Y')));
    foreach ($ovFiles as $ovF) {
      if (!is_file($ovF) || filesize($ovF) > SJ_RASKID_OVERFLOW_MAX + 65536) continue;
      $prevCopy = '';
      foreach ($bks as $b) { if (is_file($root . '/' . $b . '/' . basename($ovF))) { $prevCopy = $root . '/' . $b . '/' . basename($ovF); break; } }
      if ($prevCopy === '' || filesize($prevCopy) !== filesize($ovF)) @copy($ovF, $dir . '/' . basename($ovF));
    }
    /* recenzija v99: i dnevnik izjava (raskidi-ulaz) ide u kopiju, kad se promijenio */
    foreach (glob(SJ_DATA . '/raskidi-ulaz-*.jsonl') ?: [] as $jf) {
      if (filesize($jf) > SJ_RASKID_OVERFLOW_MAX + 65536) continue;
      $pc = ''; foreach ($bks as $b) { if (is_file($root . '/' . $b . '/' . basename($jf))) { $pc = $root . '/' . $b . '/' . basename($jf); break; } }
      if ($pc === '' || filesize($pc) !== filesize($jf)) @copy($jf, $dir . '/' . basename($jf));
    }
    /* v99 (recenzija vijeća v98, L5): zaostale privremene datoteke (proces prekinut između zapisa i preimenovanja) starije od 1 sata;
       dnevnik izjava (raskidi-ulaz) stariji od 2 mjeseca — izjave su u glavnom ili dodatnom popisu */
    foreach (glob(SJ_DATA . '/*.tmp-*') ?: [] as $tf) if (is_file($tf) && filemtime($tf) < time() - 3600) @unlink($tf);
    $keepJ = [basename(sj_raskid_journal_path(time())), basename(sj_raskid_journal_path(gmmktime(0, 0, 0, (int)gmdate('n') - 1, 1, (int)gmdate('Y'))))];
    $oldJ = array_filter(glob(SJ_DATA . '/raskidi-ulaz-*.jsonl') ?: [], fn($jf) => !in_array(basename($jf), $keepJ, true));
    /* recenzija v99: prije brisanja starog dnevnika još jedna provjera; v100: samo tada. v101 (recenzija vijeća v100, softver F): stari dnevnik
       briše se samo kad je provjera prošla do kraja (manje od 20 obnovljenih, bez iznimke) — inače čeka sljedeći dan */
    $recOk = !$oldJ;
    if ($oldJ) { try { $recOk = count(sj_raskid_reconcile(true, array_values($oldJ))) < 20; } catch (Throwable $e) { $recOk = false; } }
    if ($recOk) foreach ($oldJ as $jf) if (in_array(basename($jf), (array)($GLOBALS['sj_reconcile_read'] ?? []), true)) @unlink($jf); /* recenzija v101: samo dnevnik koji je provjera stvarno pročitala do kraja */
    foreach (glob($root . '/????-??-??', GLOB_ONLYDIR) ?: [] as $old) {
      if (strtotime(basename($old)) < strtotime($day) - $keepDays * 86400) { foreach (array_merge(glob($old . '/*') ?: [], [$old . '/.gotovo']) as $x) if (is_file($x)) @unlink($x); @rmdir($old); }
    }
    if (@file_put_contents($dir . '/.gotovo', date('c')) === false) @touch($dir . '/.gotovo'); /* recenzija v98: bez oznake bi se kopija ponavljala pri svakom posjetu */
    return $dir;
  } finally { flock($fp, LOCK_UN); fclose($fp); }
}
/* v99: zaglavlja odgovora (provjera CSP-a); null = nema odgovora */
function sj_http_headers(string $url): ?string {
  if (!function_exists('curl_init')) return null;
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_HEADER => true, CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
  $r = curl_exec($ch); curl_close($ch);
  return is_string($r) && $r !== '' ? $r : null;
}
/* v90: HTTP status adrese (provjera zaštite); 0 = nema odgovora */
function sj_http_code(string $url, array $hdr = []): int {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    if ($hdr) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr); /* v98: provjera X-Forwarded-For */
    /* v90 (recenzija): bez Range (206 bi sakrio otvorenu datoteku); preusmjerenje http→https se slijedi */
    curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_MAXFILESIZE => 2097152]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $code;
  }
  $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 6, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 3] + ($hdr ? ['header' => implode("\r\n", $hdr)] : [])]);
  @file_get_contents($url, false, $ctx, 0, 1);
  $code = 0; /* nakon preusmjerenja vrijedi zadnji status */
  foreach ((array)($http_response_header ?? []) as $hl) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hl, $m)) $code = (int)$m[1];
  return $code;
}
function sj_last_backup(): string { $d = glob(SJ_DATA . '/kopije/????-??-??', GLOB_ONLYDIR) ?: []; sort($d); return $d ? basename(end($d)) : ''; }
/* v90 (vijeće): greske.log najviše ~1 MB (stariji dio u greske.log.1) */
function sj_log_rotate(): void { $f = SJ_DATA . '/greske.log'; if (is_file($f) && filesize($f) > 1048576) @rename($f, $f . '.1'); }

function sj_with_lock(string $path, callable $fn, $default = []) {
  $dir = dirname($path);
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  /* v95 (recenzija vijeća v94, M3): neuspjela brava (vlasnik datoteke nakon vraćanja preko FTP-a, puna kvota) — jasna greška, ne TypeError */
  $fp = @fopen($path . '.lock', 'c');
  if (!$fp) throw new RuntimeException('brava se ne može otvoriti: data/' . basename($path) . '.lock (provjerite prava pristupa i prostor na hostingu)');
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException('brava se ne može zaključati: data/' . basename($path) . '.lock'); }
  try {
    $data = sj_read_json($path, $default);
    /* v96 (recenzija vijeća v95, N2): prazna (0 B) kritična datoteka (prekinuto vraćanje kopije) nije „nema narudžbi” — kao oštećena:
       ništa se ne prepisuje, traka HITNO i e-mail vlasnici (plaćena torba inače bi izgledala slobodna); proizvodi.json se obnavlja sam */
    clearstatcache(true, $path);
    /* v98 (recenzija vijeća v97, N1): i prazan config.json — inače bi ga prvi posjet prepisao zadanima (lozinka, SMTP, adresa) */
    if (is_file($path) && filesize($path) === 0 && ((sj_is_critical_data(basename($path)) && basename($path) !== 'proizvodi.json') || basename($path) === 'config.json')) {
      sj_corrupt_note(basename($path));
      throw new RuntimeException('data/' . basename($path) . ' je prazna (0 B); ništa nije prepisano. Vratite je iz dnevne kopije (data/kopije/).');
    }
    /* datoteka postoji i nije prazna, ali nije čitljiv JSON: čuva se kopija (ime.json.osteceno-datum), a postavke se
       ne prepisuju zadanima (izgubili bi se lozinka, brojač narudžbi, adresa pošiljatelja) */
    if (is_file($path) && filesize($path) > 0 && sj_read_json($path, null) === null) {
      $bak = $path . '.osteceno-' . date('Ymd');
      if (!is_file($bak)) @copy($path, $bak);
      if (basename($path) === 'config.json') throw new RuntimeException('data/config.json nije čitljiv; vratite ga iz sigurnosne kopije.');
      /* v90 (vijeće): ni narudžbe ni rezervacije (oznake „plaćeno”) ne smiju se prepisati praznima — plaćena torba bi se mogla prodati dvaput */
      if (sj_is_critical_data(basename($path))) { sj_corrupt_note(basename($path)); throw new RuntimeException('data/' . basename($path) . ' nije čitljiv; ništa nije prepisano. Vratite ga iz sigurnosne kopije (data/kopije/).'); }
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
  $path = sj_products_path();
  /* v94 (recenzija vijeća, M1): uvoz iz javnog torbe.json samo kad glavnog popisa nema; oštećeni popis se ne prepisuje —
     kopija .osteceno, traka HITNO u adminu i e-mail vlasnici, a trgovina dotle prikazuje zadnji javni popis (bez skrivenih) */
  if (!is_file($path) || filesize($path) === 0) { /* v94 (recenzija): prazna datoteka (prekinut prijenos) kao da je nema — uvoz javnog popisa */
    clearstatcache(true, $path);
    /* v95 (recenzija vijeća v94, L3): prazna datoteka (ne nepostojeća) — prvo zadnja dnevna kopija (s nevidljivim proizvodima),
       uz napomenu vlasnici; javni torbe.json samo ako kopije nema */
    /* v99 (recenzija vijeća v98, N5): obnova pod bravom — dva istodobna zahtjeva ne obnavljaju dvaput (ni dva e-maila vlasnici) */
    $fromBk = ''; $out = null;
    sj_with_lock($path, function (array &$d) use ($path, &$fromBk, &$out) {
      clearstatcache(true, $path);
      if (is_file($path) && filesize($path) > 0) { $cur = sj_read_json($path, null); if (is_array($cur)) { $out = array_values($cur); return false; } }
      if (is_file($path)) foreach (array_reverse(glob(SJ_DATA . '/kopije/*/proizvodi.json') ?: []) as $bk) {
        $bp = sj_read_json($bk, null);
        if (is_array($bp) && $bp) { $d = array_values($bp); $out = $d; $fromBk = basename(dirname($bk)); return true; }
      }
      $p = sj_read_json(SJ_PUBLIC_JSON, []);
      $d = is_array($p) ? array_values($p) : []; $out = $d; return true;
    }, []);
    if ($fromBk !== '') { try { $c = sj_config(); if (!empty($c['owner_email'])) sj_send_mail((string)$c['owner_email'], (string)($c['shop'] ?? 'Shiva.J') . ' — popis proizvoda vraćen iz kopije',
      "Datoteka data/proizvodi.json bila je prazna (vjerojatno prekinut prijenos). Stranica ju je vratila iz dnevne kopije " . $fromBk . ".\n\nProvjerite u adminu → Proizvodi jesu li svi proizvodi tu; promjene napravljene nakon te kopije treba ponoviti."); } catch (Throwable $e) {} }
    return is_array($out) ? $out : [];
  }
  $p = sj_read_json($path, null);
  if (!is_array($p)) {
    $raw = (string)@file_get_contents($path);
    if (trim($raw) !== '') { $bk = $path . '.osteceno-' . date('Ymd'); if (!is_file($bk)) @copy($path, $bk); sj_corrupt_note(basename($path)); }
    $pub = sj_read_json(SJ_PUBLIC_JSON, []);
    return is_array($pub) ? array_values($pub) : [];
  }
  return array_values($p);
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
  sj_products(); /* v94 (recenzija): prazan glavni popis se najprije obnovi iz javnog, da ga sinkronizacija ne isprazni */
  if (!is_file(sj_products_path()) || filesize(sj_products_path()) === 0) return;
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
  /* v95 (recenzija vijeća v94, M3): brojač je samo statistika — njegova greška (brava, oštećena datoteka) ne smije srušiti stranicu ni narudžbu */
  try { sj_with_lock(SJ_DATA . '/brojac.json', function (array &$d) use ($key, $n, $day) {
    $d[$day][$key] = (int)($d[$day][$key] ?? 0) + $n;
    if (count($d) > 400) { ksort($d); $d = array_slice($d, -400, null, true); }
    return true;
  }, []); } catch (Throwable $e) { @file_put_contents(SJ_DATA . '/greske.log', gmdate('c') . ' brojač: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX); }
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
  /* provjera v102 (krug 2, sigurnost P1/S4): nevidljivi znakovi koji NISU kategorije Cf — hangulska i brajeva „praznina” (slova!), kmerski i mongolski
     nevidljivi znakovi, selektori varijante, U+034F. Između znamenki skrivali su telefon i IBAN od maske („091”+U+3164+„234…”), a iza točke domenu od filtra. */
  $v = preg_replace('/[' . SJ_INVIS . ']/u', '', $v) ?? '';
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
  /* v95 (recenzija vijeća v94, sigurnost 2): samo običan oblik ime@domena.hr — bez navodnika, „\\” i adresa [IP], jer bi
     „"ana"@gmail.com” dobio vlastitu granicu e-mailova, a stiže u isti sandučić */
  /* v96 (recenzija vijeća v95): bez „%” i bez rezerviranih domena (localhost, local, invalid, test, example) */
  return ($v !== '' && strlen($v) <= 120 && preg_match('/^[A-Za-z0-9._+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,24}$/D', $v)
    && !preg_match('/[@.](localhost|localdomain|local|invalid|test|example)$/i', $v) && filter_var($v, FILTER_VALIDATE_EMAIL)) ? $v : null;
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
/* v91 (recenzija): za odbijanje narudžbe popis vršnih domena (malim ili VELIKIM slovima, i s punom točkom „．”) — ne svaki
   izostavljeni razmak iza točke („12.kat”, „Hvala.lp”); široki uzorak samo u sj_unlink (zamjena u e-mailu) */
/* v95 (recenzija vijeća v94): dulji popis vršnih domena; poznate „opasne” i miješanim slovima („Naplata.Com”), svaka domena s
   putanjom („t.co/x”) i adresa IP („192.168.0.1/pay”) */
/* v99 (recenzija vijeća v98, sigurnost N4): i pw, ovh, art, blog, id; „se” namjerno nije na popisu — česta hrvatska riječ iza
   izostavljenog razmaka („Ulaz.se nalazi…”), a .se se rijetko koristi za prijevare */
const SJ_TLDS = 'ai|gg|sh|tv|cn|ml|cf|dev|page|zip|mov|icu|lol|sbs|cfd|gq|cyou|monster|buzz|rest|bond|hr|com|net|org|eu|io|ly|info|xyz|ru|site|online|link|shop|store|rs|co|uk|biz|app|club|live|tk|cc|ws|cz|pl|fr|es|nl|ch|be|click|pay|bank|win|vip|help|support|cloud|ee|website|space|fun|tech|email|world|today|life|news|cash|money|finance|loan|wallet|host|cam|work|best|now|global|group|city|zone|wiki|lt|lv|sk|hu|ro|bg|gr|mk|ua|kz|tr|ca|au|jp|kr|dk|fi|pt|ie|lu|gl|ms|ac|cx|nu|ninja|men|date|stream|download|review|trade|party|science|racing|bid|loans|account|ltd|services|agency|digital|network|center|systems|solutions|company|exchange|crypto|bet|casino|porn|sex|xxx|quest|beauty|hair|skin|makeup|baby|sale|deals|bargains|discount|coupons|rocks|fyi|fm|nz|mx|games|gift|mobi|lat|vn|pw|ovh|art|blog|id'; /* v99 (recenzija vijeća v98, sigurnost N4): i pw, ovh, art, blog, se, id; recenzija v98: „br” samo s putanjom („Tel.br.”, „kuć.br. 33”); v98 (recenzija vijeća v97, sigurnost 7): i games, gift, mobi, lat, vn, br; v95 (recenzija): bez „do”, „red”, „al”, „im” (obične riječi: „Hvala.do viđenja”, „boja.red”) */
/* v100 (recenzija vijeća v99, sigurnost N3): nastavci koji su i hrvatske (ili česte) riječi — to, su, me, si, ga, it, ba, de, in, at, us —
   poveznica su samo s putanjom ili na kraju teksta („Hvala.to je sve”, „doma.me nazovite” prolaze) */
const SJ_TLDS_WORDS = 'to|su|me|si|ga|it|ba|de|in|at|us';
/* v102 (recenzija vijeća v101, sigurnost N5): i ovi nastavci su hrvatske riječi („mamu.mom bratu”, „crnu.plus zlatni”, „torbe.one crne”, „Hvala.Top ste”) —
   poveznica su s putanjom ili kad oznaka ima crticu ili znamenku („secure-pay.top”); inače ih u e-mailu sj_unlink razdvaja razmakom */
const SJ_TLDS_WORDS2 = 'mom|plus|one|top|pro';
const SJ_TLDS_PATH = SJ_TLDS . '|' . SJ_TLDS_WORDS . '|' . SJ_TLDS_WORDS2 . '|do|red|al|im|br|gd|mp|gy|ae|tt|cl'; /* provjera v102 (sigurnost N1): i nastavci servisa za kratke poveznice („v.gd/x”, „j.mp/x”, „rb.gy/x”) */ /* s putanjom („bit.do/x”) ipak poveznica */
/* v101 (recenzija vijeća v100, sigurnost): točka i kao U+FF61; nastavci-riječi (to, su, me…) poveznica su SAMO s putanjom (ni na kraju teksta,
   ni uz crticu u imenu — „Ana-Marija.to je sve”, „Hvala.Me” prolaze; u e-mailu ih sj_unlink razdvaja razmakom); svaki nastavak ćirilicom je poveznica */
const SJ_DOT = '[.．。｡․ꓸ﹒⸼]'; /* v102: i U+2024, U+A4F8, U+FE52; recenzija v102: i U+2E3C */
/* v102 (recenzija vijeća v101, sigurnost N2): oznaka domene smije nositi kombinirajuće znakove („uplata” + U+0301 + „.com”, U+FE0F, U+034F) */
const SJ_LBL = '[\\p{L}\\p{N}\\p{M}\\p{So}\\p{Sk}-]'; /* recenzija v102 (sigurnost N5): i simbol pred točkom („uplata★.com”, „uplata💳.ws”) */
const SJ_SCHEMES = 'skype|callto|facetime|mailto'; /* provjera v102: samo malim slovima; bez viber/whatsapp/signal/tg, koje ljudi pišu kao oznake („kontakt viber:isti broj kao gore”) — njihov oblik s „://” hvata opće pravilo */
define('SJ_LINK_RE', '~(https?:|(?<![\\p{L}\\p{N}])[a-z][a-z0-9+.-]{1,15}://|(?<![\\p{L}\\p{N}])(?-i:' . SJ_SCHEMES . '):(?=[^\\s\\d+])|www\\s*' . SJ_DOT
  . '|' . SJ_LBL . '{2,}' . SJ_DOT . '(?-i:' . SJ_TLDS . '|' . strtoupper(SJ_TLDS) . ')(?![\\p{L}\\p{N}]|' . SJ_DOT . '\\p{L})'
  . '|' . SJ_LBL . '{3,}' . SJ_DOT . '(?:com|net|org|info|xyz|online|site|shop|store|app|link|click|help|support|pay|bank|cloud|website|hr)(?![\\p{L}\\p{N}]|' . SJ_DOT . '\\p{L})'
  . '|' . SJ_LBL . '{3,}' . SJ_DOT . '(?:ru|eu|pw|io|biz|cn)(?=/|\\s*$)'
  . '|' . SJ_LBL . '{2,}' . SJ_DOT . '\\p{Cyrillic}{2,}(?![\\p{L}\\p{N}])'
  . '|(?<![\\p{L}\\p{N}\\p{M}-])[\\p{L}\\p{N}]\\p{M}*' . SJ_DOT . '(?-i:' . SJ_TLDS . '|' . strtoupper(SJ_TLDS) . ')(?![\\p{L}\\p{N}]|' . SJ_DOT . '\\p{L})' /* v102: jednoslovna oznaka („t.ly”, „x.de”, „a.bg”) */
  . '|(?=' . SJ_LBL . '*[\\p{N}-])' . SJ_LBL . '{3,}' . SJ_DOT . '(?:' . SJ_TLDS_WORDS2 . ')(?![\\p{L}\\p{N}])' /* v102: riječ-nastavak uz crticu ili znamenku u oznaci */
  . '|' . SJ_LBL . '{3,}\\s+' . SJ_DOT . '(?:hr|com)(?![\\p{L}\\p{N}])|' . SJ_LBL . '{2,}' . SJ_DOT . 'xn--[a-z0-9-]{2,}|' . SJ_LBL . '+' . SJ_DOT . '(?:' . SJ_TLDS_PATH . ')/[^\\s]'
  . '|(?<![\\d.])\\d{1,3}(?:' . SJ_DOT . '\\d{1,3}){3}[/:]|[^\\s@]+@[^\\s@]+\\.[^\\s@]+)~iu'); /* v102: e-adresa bez kvadratnog vremena */
function sj_link_norm(string $s): string {
  /* provjera v102 (sigurnost): nevidljivi znakovi koji nisu kategorije Cf (U+034F, selektori varijante) brišu se — „naplata.c”+U+034F+„om” izgleda kao
     prava domena; u e-mailu kupcu tekst zato izlazi bez njih */
  $s = preg_replace('~[' . SJ_INVIS . ']~u', '', $s) ?? $s;
  /* v94 (recenzija): „(.)”, „[.]”, „ dot ”, „ točka ” postaju točka samo kad iza njih stoji vršna domena („primjer točka hr”),
     da obična „točka 2 Uvjeta” ostane netaknuta */
  $tld = SJ_TLDS;
  /* v99 (recenzija vijeća v98, sigurnost N4): i razmak prije točke („primjer .eu”, „primjer . com”) */
  $s = preg_replace('~\s*(?:\(\s*(?:\.|dot|tocka|točka)\s*\)|\[\s*(?:\.|dot|tocka|točka)\s*\]|\s(?:dot|tocka|točka)\s)\s*(?=(?:' . $tld . ')(?![\p{L}\p{N}]))~iu', '.', $s) ?? $s;
  /* recenzija v99: razmak prije točke samo pred rizičnim nastavcima — ne „hvala . to je sve”, „nema . me doma” */
  $risky = 'com|net|org|hr|eu|ru|info|xyz|shop|online|site|link|click|io|ly|pw|icu|zip|mov|ml|cf|gq|tk|sbs|cfd|cyou|buzz|bond|rest|monster|lol|ws|cc|biz|cn|app|dev|page|live|club|vip|win|bank|pay|support|help|cloud|website'; /* v100 (sigurnost N2): i opasni nastavci */
  $s = preg_replace('~\s+' . SJ_DOT . '\s*(?=(?:' . $risky . ')(?![\p{L}\p{N}]))~iu', '.', $s) ?? $s;
  /* v100: „naplata. pw” (razmak iza točke) — samo na kraju teksta ili pred putanjom, da „Lijepa torba. Hr kraj” ostane rečenica;
     v101 (recenzija vijeća v100): samo malim slovima i samo nastavci koji nisu obične riječi („sve pohvale. Top”, „Super. top”, „Ana. Info” prolaze) */
  return preg_replace('~(?<=[\p{L}\p{N}])' . SJ_DOT . '\s+(?=(?-i:com|net|org|hr|eu|ru|xyz|pw|icu|ml|cf|gq|tk|sbs|cfd|cyou|ws|cc|biz|cn|io|ly|zip|mov)\s*(?:$|/))~iu', '.', $s) ?? $s;
}
function sj_has_link(string $s): bool { return (bool)preg_match(SJ_LINK_RE, $s) || (bool)preg_match(SJ_LINK_RE, sj_link_norm($s)); }
function sj_unlink(string $s): string {
  /* v94 (recenzija vijeća): „primjer (.) hr”, „primjer [dot] com”, „primjer tocka com” — prvo u običan oblik, pa filtar */
  return sj_unlink_raw(sj_link_norm($s));
}
/* v95 (recenzija vijeća v94): u ponovljenom tekstu i miješana slova („Naplata.Com”), domena s putanjom i adresa IP */
function sj_unlink_raw(string $s, bool $soft = false): string {
  /* v101 (recenzija vijeća v100, sigurnost): uklanjaju se samo prave poveznice (shema, www, e-adresa, IP, sve s putanjom, nastavak iz SJ_TLDS ili
     ćirilicom). Ostali oblici „riječ.riječ” (i nastavci-riječi to, su, me…) dobivaju razmak iza točke — „naplata.ba danas” → „naplata. ba danas”,
     „Konzum Sv.Nedelja” → „Konzum Sv. Nedelja”: ništa nije klikabilno, a običan tekst se više ne briše („[uklonjeno] je sve”) */
  $d = SJ_DOT; $L = SJ_LBL;
  /* v102 (recenzija vijeća v101): i druge sheme („tg://…”, „skype:ime”), oznaka s kombinirajućim znakom, jednoslovna oznaka; oblik „riječ.riječ/…”
     briše se samo uz poznat nastavak — „Sv.Duh/Novaki 4”, „12.kat/stan 45” ostaju (dobiju razmak iza točke) */
  /* recenzija v102 (sigurnost N3): oblici s putanjom brišu se po ISTOM pravilu po kojem se adresa odbija na ulazu (api, $addrLink) — „Livada 2.red/5” i
     „…, k.br/2” više ne postaju „[uklonjeno]”. $soft (ulica, mjesto, paketomat): briše se samo ono što ulaz odbija; domena bez putanje („Ul.hr branitelja”)
     dobiva razmak iza točke umjesto brisanja */
  $hard = '(?<![\p{L}\p{N}])[a-z][a-z0-9+.-]{1,15}://\S+|(?<![\p{L}\p{N}])(?-i:' . SJ_SCHEMES . '):[^\s\d+]\S*|www\s*' . $d . '\S+|[^\s@]+@[^\s@]+\.[^\s@]+'
    . '|' . $L . '{2,}' . $d . '(?:' . SJ_TLDS . ')/\S*|(?=[\p{N}\p{M}-]*\p{L})' . $L . '{2,}' . $d . '(?:' . SJ_TLDS_PATH . ')/\S*'
    . '|(?<![\p{L}\p{N}\p{M}-])[\p{L}\p{N}]\p{M}*' . $d . '(?:' . SJ_TLDS . '|gd|mp|gy|ae|tt|cl|to|me)/\S*|(?<![\d.])\d{1,3}(?:' . $d . '\d{1,3}){3}[/:]\S*'; /* krug 4 (sigurnost N3): i IP s putanjom */
  $bare = '\d{1,3}(?:' . $d . '\d{1,3}){3}\S*'
    . '|' . $L . '{2,}' . $d . '(?:' . SJ_TLDS . ')(?![\p{L}]|' . $d . '\p{L})\S*|(?<![\p{L}\p{N}\p{M}-])[\p{L}\p{N}]\p{M}*' . $d . '(?-i:' . SJ_TLDS . '|' . strtoupper(SJ_TLDS) . ')(?![\p{L}\p{N}]|' . $d . '\p{L})\S*'
    . '|(?=' . $L . '*[\p{N}-])' . $L . '{3,}' . $d . '(?:' . SJ_TLDS_WORDS2 . ')(?![\p{L}\p{N}])\S*|' . $L . '{2,}' . $d . 'xn--[a-z0-9-]{2,}\S*|' . $L . '{2,}' . $d . '\p{Cyrillic}{2,}(?![\p{L}])\S*';
  $s = preg_replace('~(' . $hard . ($soft ? '' : '|' . $bare) . ')~iu', '[uklonjeno]', $s) ?? '';
  /* recenzija v102 (sigurnost N5): razmak iza točke i kad je oznaka jedno slovo ili znamenka („x.de” → „x. de”, „t.me”, „1.at”, „A.Šenoe” → „A. Šenoe”) te kad se
     među slovima nastavka krije kombinirajući znak („naplata.c” + U+1BC9D + „om”); „d.o.o.” ostaje (iza točke jedno slovo) */
  $la = '(?=\p{M}*\p{L}\p{M}*\p{L}[\p{L}\p{M}]{0,22}(?![\p{L}\p{M}]))';
  $s = preg_replace('~(' . $L . '{2,})' . $d . $la . '~u', '$1. ', $s) ?? $s;
  return preg_replace('~(?<![\p{L}\p{N}\p{M}-])([\p{L}\p{N}]\p{M}*)' . $d . $la . '~u', '$1. ', $s) ?? $s;
}
/* recenzija v102 (sigurnost N3): adresa u e-mailu kupcu — bez brisanja onoga što je ulaz primio */
function sj_unlink_addr(string $s): string { return sj_unlink_raw(sj_link_norm($s), true); }
/* provjera v102 (krug 4, sigurnost S1): široki i višestruki razmaci (U+3000, U+2003…) postaju jedan običan razmak — inače bi niz takvih znakova
   tekst kupca prelomio u zaseban redak koji izgleda kao obavijest trgovine */
function sj_echo_spaces(string $s): string { return trim((string)preg_replace('~[\p{Zs}\t]+~u', ' ', $s)); }
/* recenzija v102 (sigurnost N1); v104 (recenzija v103, sigurnost N1): tekst kupca kao JEDAN omeđen odlomak — redovi spojeni kosom crtom, navodnici običnim,
   da ne izgleda kao zaseban odlomak trgovine (napomena, adresa, paketomat) */
function sj_quote_block(string $s): string { return trim((string)preg_replace('~\s*[\r\n]+\s*~u', ' / ', str_replace(['„', '”', '“'], '"', $s))); }
/* tekst kupca kakav se ponavlja kupcu (e-mail, ploča na stranici): bez poveznica, dugih brojeva i širokih razmaka */
/* v104 (recenzija v103, sigurnost N2/N3): u kopiji za kupca (e-mail, ploča) i
   - odredište plaćanja kao oznaka: „@ime” (e-adrese su već uklonjene), „revtag ime”, „Revolut: ime”, „revolut me/ime”, „PayPal ime99” → „[oznaka skrivena]”;
   - telefon razlomljen riječima: pozivni broj (0xx, +385 xx), do pet riječi ili kratkih skupina znamenki, ukupno 9 ili više znamenki („Pozivni 091, broj 2345678”,
     „Pozovi 091 234 i nastavak 5678”) → „[broj skriven]”. Pozivni broj ne smije biti dio datuma ni vremena („01.12.2026”, „08:00”).
   Vlasnica u svom e-mailu i u adminu vidi izvorni tekst. */
function sj_mask_handles(string $s): string {
  $s = str_replace('＠', '@', $s); /* krug provjere v104: „@” pune širine */
  $s = preg_replace('~(?<![\p{L}\p{N}._@-])@[\p{L}\p{N}._-]{2,}~u', '[oznaka skrivena]', $s) ?? $s;
  /* krug provjere v104 (sigurnost B7): i „Revolut - ime”, „Revolut – ime”, „Revolut → ime”, „Revolut (ime)”, „Revolut tag: ime” */
  /* v110 (recenzija v109, sigurnost N1): i padeži („Revolutom”, „Revolutu”, „PayPalom”, „Keks Payem”), „na …”, „račun …”, razdjelnici = / @ … ->,
     gola oznaka na kraju dijela rečenice („Revolut ivanhorvat”) i oznaka s točkom („ivan.horvat99” / „ivan. horvat99”); obične riječi ostaju */
  /* v111 (pregled v110, sigurnost N2/N3): uputa na plaćanje (2. lice: „uplatite”, „pošaljite”, „plaćanje na” …) bilo gdje u napomeni — tada je oznaka skrivena i kad iza nje
     slijedi riječ („Uplatite Revolutom ivanhorvat do petka”); bez upute gola oznaka mora biti na kraju dijela rečenice (ne pitanje), ne smije imati č ć ž š đ
     („uopće”, „nažalost”) i ne stoji iza „preko/putem” (kupac opisuje svoj način: „Uplatit ću preko Revoluta sestrinog”) — pitanja kupaca ostaju */
  $imp = (bool)preg_match('~(?<!\p{L})(?:uplatite|platite|pošaljite|šaljite|prebacite|uplati|plati|pošalji|prebaci|uplaćujete|plaćate|šaljete|možete\s+(?:uplatiti|platiti|poslati)|(?:uplata|uplatu|plaćanje|novac|iznos)\s+(?:ide\s+)?na)(?!\p{L})~iu', $s);
  $s = preg_replace_callback('~((?<!\p{L})(?:preko|putem)\s+)?\b(revtag|revolut|paypal|aircash|keks\s?pay)(\p{L}{0,3})\b((?:\s+(?:tag|na|račun|računa|account|korisnik|korisničko\s+ime|ime|username|user|profil))?)(\s*(?:[:=/@…\-–—→(]|\.{2,}|->)+\s*|\s+)((?:me\s*/\s*)?)@?([\p{L}\p{N}_-]{3,}(?:\.\s?[\p{L}\p{N}_-]+)*)(\)?)(?=(.{0,12}))~isu', function ($m) use ($imp) {
    /* drugi krug provjere v104 (N4): crtica, strelica, zagrada i „tag” bez dvotočke vrijede samo za samostalnu oznaku na kraju dijela rečenice, a ne za obične riječi
       („Revolut → banka”, „PayPal (ako može)”, „Revolut tag nemam”); v110: popis običnih riječi proširen (vrijeme, glagoli plaćanja) */
    $word = preg_match('~^(?:ali|ako|ili|banka|banku|banke|instant|nemam|imam|hoće|hoćete|može|mogu|možete|sve|molim|radije|samo|isto|nije|nema|je|da|ne|na|za|i|a|u|s|sa|iz|od|do|po|pa|jer|kad|kada|čim|nakon|prije|poslije|preko|putem|kartica|kartice|karticom|račun|računa|uplata|uplatu|uplate|uplatom|uplatnicom|isplata|isplatu|aplikacija|aplikaciju|opcija|opciju|opcije|mogućnost|vrijeme|sutra|danas|odmah|kasnije|večeras|ujutro|navečer|vikend|vikendom|prekosutra|plaćam|platim|platiti|platit|plaćanje|šaljem|šaljemo|pošaljem|uplatim|uplaćujem|uplatiti|radi|ne radi|hvala|ok|okej|svejedno|nemate|imate|imaš|nemaš|bih|bi|ću|će|ćemo|sam|smo|ste|li|vas|vam|nas|nam|mi|ti|ja|vi|oni|to|ovo|ono|tako|ovako|isto|također|kao|nego|još|već|uvijek|nikad|možda|svakako|naravno|ne mogu|primjer|sreću|žalost)$~iu', $m[7]);
    $alone = preg_match('~^[)\s]*(?:$|[.,;!]|\s+(?:ili|i|a|hvala)\b)~u', (string)($m[9] ?? '')); /* v111: „?” nije kraj oznake — pitanje kupca */
    $plain = !$word && !preg_match('~[čćžšđ]~iu', $m[7]);
    $hit = stripos($m[2], 'revtag') === 0 || preg_match('~[:=/@…]|\.\.|->~u', $m[5]) || $m[6] !== '' || preg_match('~[\d._]~', $m[7])
      || ($plain && ($imp || (preg_match('~\S~u', $m[4]) && !preg_match('~^\s+na$~iu', $m[4])) || ($alone && $m[1] === '' ) || ($alone && preg_match('~^\s+na$~iu', $m[4]))));
    return $hit ? $m[1] . $m[2] . $m[3] . $m[4] . $m[5] . '[oznaka skrivena]' . $m[8] : $m[0];
  }, $s) ?? $s;
  /* krug provjere v104 (sigurnost 1/5): razdjelnici i . ! ( ) — – →; pozivni broj nije dio vremena ili datuma (ni iza „:”, „/”, „.” ili znamenke) */
  $sep = '[\s,;:\-/.!()—–→]+'; $pre = '(?:\+\s?385\s?\d{1,2}|0\d{1,2})(?![\d]|[.:/]\d)';
  $s = preg_replace_callback('~(?<![\p{L}\p{N}:/.])' . $pre . '((?:' . $sep . '(?:\p{L}{1,12}|\d{1,8})){1,6})(?!\d)~u', function ($m) {
    if (!preg_match('~\p{L}~u', $m[1]) || preg_match_all('~\d~', $m[0]) < 9) return $m[0];
    /* drugi krug provjere v104 (N4): opis zgrade nije telefon („Ulaz 05 — kod 2468 — kat 3 — stan 12”); ostatak broja mora imati skupinu od 5+ znamenki ili dvije od 3+ (ukupno 6+) */
    $grp = array_values(array_filter(array_map('strlen', preg_split('~\D+~', $m[1])), fn($n) => $n >= 3));
    if ((!$grp || max($grp) < 5) && preg_match('~(?<!\p{L})(?:kat|katu|stan|stanu|zgrada|zgradi|ulaz|ulazu|kod|vrata|portafon|interfon|prizemlje|br)(?!\p{L})~iu', $m[1])) return $m[0];
    if (!$grp || (max($grp) < 5 && array_sum($grp) < 6)) return $m[0];
    /* do zadnje skupine znamenki (riječi iza nje ostaju) */
    return (string)preg_replace('~^(.*\d).*$~su', '[broj skriven]', $m[0]) . (string)preg_replace('~^.*\d~su', '', $m[0]);
  }, $s) ?? $s;
  /* krug provjere v104: obrnut redoslijed — najprije broj, pa riječi, pa pozivni („broj 2345678, pozivni 091”) */
  return preg_replace_callback('~(?<![\p{L}\p{N}:/.])(\d[\d \-/]{4,12}\d)((?:' . $sep . '\p{L}{1,12}){1,4}' . $sep . ')(' . $pre . ')~u', function ($m) {
    if (preg_match_all('~\d~', $m[1]) < 6 || preg_match_all('~\d~', $m[1] . $m[3]) < 9) return $m[0];
    return '[broj skriven]' . $m[2] . '[broj skriven]';
  }, $s) ?? $s;
}
function sj_echo_text(string $s): string { return sj_mask_handles(sj_mask_numbers(sj_unlink(sj_echo_spaces($s)))); }
function sj_echo_addr(string $s): string { return sj_mask_handles(sj_mask_numbers(sj_unlink_addr(sj_echo_spaces($s)))); }
/* recenzija v102 (sigurnost N4); krug 4 (sigurnost N1): ulica i mjesto maskiraju se ZAJEDNO samo kad ulica završava brojem koji počinje s 0, 9, + ili
   zagradom (početak telefona: „…nazovite 09123” + „45678 odmah, Zagreb”); kućni brojevi („Vukovara 222/104”, „Ilica 26 / 32”) ostaju */
function sj_echo_street_city(string $street, string $city): string {
  $sa = sj_unlink_addr(sj_echo_spaces($street)); $ca = sj_unlink_addr(sj_echo_spaces($city));
  /* v104 (recenzija v103, sigurnost N3): u mjestu iza „poštanski broj + naziv mjesta” nema mjesta za broj — tri ili više znamenki se skrivaju
     („10000 Zagreb 2345678”, a ulica „…, tel 091”) */
  if (preg_match('~^(\s*\d{5}\s+\p{L}[\p{L}\s.\'’-]*?)(\s*[,;]?\s*.*)$~u', $ca, $cm) && preg_match_all('~\d~', $cm[2]) >= 3)
    $ca = $cm[1] . (string)preg_replace('~[\d+(][\d\s()/.\-]*\d~u', '[broj skriven]', $cm[2]);
  $sep = sj_mask_handles(sj_mask_numbers($sa)) . ', ' . sj_mask_handles(sj_mask_numbers($ca));
  if (!preg_match('~(?<![\d])[+(09][\d\s()/.\-]*$~u', $sa)) return $sep;
  $joint = sj_mask_handles(sj_mask_numbers($sa . ', ' . $ca));
  return substr_count($joint, ' skriven]') > substr_count($sep, ' skriven]') ? $joint : $sep;
}
/* v77: boje i duljine remena iz ponude (odluka vlasnice, 11. krug) — isti popis kao STRAP_COLORS/STRAP_LENGTHS u index.html */
const SJ_STRAP_COLORS = ['crna', 'smeđa', 'crvena', 'bež', 'plava', 'tamno zelena', 'bordo', 'siva'];
const SJ_STRAP_LENGTHS = ['140 cm', '100 cm'];
/* v78 (12. krug, t1): toliko dana nakon storna isti e-mail ili veza ne naručuje istu torbu (api sj_recent_cancel_text) */
const SJ_REORDER_BLOCK_DAYS = 7;
/* recenzija v102 (sigurnost N7): toliko dana ostaje zapis potvrđene narudžbe na čekanju, bez podataka kupca (api sj_pending_old); ovdje jer se
   konstante u api/index.php ne bi stigle definirati prije odgovora */
const SJ_PENDING_STUB_DAYS = 30;
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
  if ($m === '' || str_contains($m, '{')) $m = 'kvalitetna umjetna koža';
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
  /* v98: grana se briše s ugniježđenim oznakama (npr. <span class="nw">e-maila</span>) — lijeni uzorak stao bi na prvom </span> */
  $h = preg_replace('#<span ' . $drop . '>((?:[^<]++|<(?!/?span\b)|<span\b[^>]*+>(?1)</span>)*+)</span>#s', '', $h) ?? '';
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
  /* v84 (recenzija): slika EU obavijesti nema smisla u e-mailu — umjesto nje kratka uputa na stranicu */
  $h = preg_replace('#<figure class="lg-notice">.*?</figure>#s', '<p>Usklađenu obavijest EU o zakonskom jamstvu možete pogledati na našoj stranici, u Uvjetima kupnje (točka 8.).</p>', $h) ?? '';
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
  return ($case === 'acc' ? $acc[$n] : $gen[$n]) . ', ' . $dt->format('j. n. Y.') . ' u ' . $dt->format('H:i') . sj_night_note($dt);
}
/* v90 (vijeće, kupci): rok u 00:01 čitao se kao „imam cijeli četvrtak” — u noćnim satima dodaje se „(u noći sa srijede na četvrtak)” */
function sj_night_note(DateTime $dt): string {
  if ((int)$dt->format('G') >= 6) return '';
  $n = (int)$dt->format('N'); $p = $n === 1 ? 7 : $n - 1;
  $gen = [1 => 'ponedjeljka', 'utorka', 'srijede', 'četvrtka', 'petka', 'subote', 'nedjelje'];
  $acc = [1 => 'ponedjeljak', 'utorak', 'srijedu', 'četvrtak', 'petak', 'subotu', 'nedjelju'];
  return ' (u noći ' . (in_array($p, [3, 6], true) ? 'sa' : 's') . ' ' . $gen[$p] . ' na ' . $acc[$n] . ')';
}
function sj_fmt_hr(?string $iso): string {
  if (!$iso) return '—';
  $t = strtotime($iso);
  if (!$t) return $iso;
  $dt = new DateTime('@' . $t);
  $dt->setTimezone(new DateTimeZone('Europe/Zagreb'));
  return $dt->format('j. n. Y. \\u H:i'); /* v98 (recenzija vijeća v97, dizajn): isti oblik kao rok i potvrda raskida („8. 1. 2026. u 08:05”) */
}

/* v98 (recenzija vijeća v97, sigurnost H1): odgovor odmah, a skripta nastavlja (e-mailovi nakon odgovora). U ugrađenom
   poslužitelju (testovi) ostaje sinkrono. Neuhvaćena greška nakon toga samo se bilježi (sj_responded). */
function sj_json_respond_now(array $data): void {
  $j = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
  $GLOBALS['sj_responded'] = true;
  if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
  if (PHP_SAPI === 'cli-server' || PHP_SAPI === 'cli') { echo $j; return; }
  if (!headers_sent()) { header('Content-Length: ' . strlen($j)); header('Connection: close'); }
  echo $j;
  if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
  elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
  else { while (ob_get_level() > 0) @ob_end_flush(); @flush(); }
}
/* v98 (recenzija vijeća v97, sigurnost M1): narudžba je „povučena” (bez podsjetnika, storna i čuvanja nakon „Uplatio/la sam”)
   samo uz izjavu poslanu preko poveznice iz e-maila; izjava bez nje ide vlasnici na provjeru. Stari zapisi (bez 'tok') vrijede. */
function sj_withdrawn(array $o, bool $verifiedOnly = true): bool {
  foreach ((array)($o['withdrawals'] ?? []) as $w) if (!$verifiedOnly || !is_array($w) || !array_key_exists('tok', $w) || !empty($w['tok'])) return true;
  return false;
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

/* v89: „20M” / „512K” / „1G” iz php.ini → bajtovi (0 = bez ograničenja ili nepoznato) */
function sj_ini_bytes(string $v): int {
  $v = trim($v); if ($v === '' || $v === '-1') return 0;
  $n = (float)$v; $u = strtolower(substr($v, -1));
  return (int)round($n * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1))));
}
/* v89: razlog neuspjelog prijenosa fotografije, za vlasnicu */
function sj_upload_error_text(int $code, string $name): string {
  $n = $name !== '' ? '„' . $name . '”' : 'Fotografija';
  if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE)
    return $n . ' nije spremljena: veća je od ograničenja poslužitelja (upload_max_filesize = ' . (string)ini_get('upload_max_filesize') . '). U Plesku → PHP postavke upišite 20M (upute za objavu) ili fotografiju prije prijenosa smanjite.';
  if ($code === UPLOAD_ERR_PARTIAL) return $n . ' nije spremljena: prijenos je prekinut. Pokušajte ponovno.';
  return $n . ' nije spremljena (greška poslužitelja pri prijenosu, kod ' . $code . '). Javite osobi koja održava stranicu.';
}

/* Sprema prenesenu sliku kao img/<base>-<n>.jpg, smanjenu na najviše 1400 px. Vraća relativnu putanju ili null. */
function sj_store_image(string $tmpPath, string $base): ?string {
  if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('Fotografija nije spremljena: na poslužitelju nije uključeno PHP proširenje GD (Plesk → PHP → proširenja, ili zamolite podršku hostinga). Ostali podaci torbe su ispravni — spremite ih bez fotografije pa je dodajte kad GD proradi.'); /* v82 */
  $info = @getimagesize($tmpPath);
  if (!$info) return null;
  [$w, $h, $type] = $info;
  /* v89: fotografije s fotoaparata (npr. 4000 × 4000) — GD treba oko 4 B po pikselu; najviše 50 MP */
  if ($w * $h > 50000000) throw new RuntimeException('Fotografija je prevelika (' . round($w * $h / 1e6) . ' MP; najviše 50 MP). Smanjite je prije prijenosa.');
  $need = (int)($w * $h * 4 * 2.2) + 33554432; $lim = sj_ini_bytes((string)ini_get('memory_limit'));
  if ($lim > 0 && $lim < $need) @ini_set('memory_limit', (string)max($need, 268435456));
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
    $ang = $o === 3 ? 180 : ($o === 6 ? -90 : ($o === 8 ? 90 : 0));
    if ($ang) { $rot = imagerotate($img, $ang, 0); if ($rot) { imagedestroy($img); $img = $rot; } } /* v89: stara kopija odmah iz memorije */
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
  /* v95 (recenzija): za zaglavlja dovoljna je osnovna provjera (bez novih redaka) — strogi oblik vrijedi za nov unos (narudžba,
     raskid), a spremljene adrese (Postavke, starije narudžbe) i dalje primaju e-mail */
  $hdr = fn(?string $v) => (($v = trim((string)$v)) !== '' && strlen($v) <= 120 && !preg_match('/[\r\n\0<>",;]/', $v) && filter_var($v, FILTER_VALIDATE_EMAIL)) ? $v : null;
  $to = $hdr($to);
  $from = $hdr(sj_mail_from());
  if ($to === null || $from === null) return false;
  $replyTo = $hdr($replyTo);
  $subject = sj_clean($subject, 200);
  $shop = sj_clean((string)$c['shop'], 80);
  $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $fromHeader = '=?UTF-8?B?' . base64_encode($shop) . '?= <' . $from . '>';
  $boundary = 'sj-' . bin2hex(random_bytes(8));
  $headers = ["From: $fromHeader", "Reply-To: " . ($replyTo ?: $from), "Return-Path: <$from>", "MIME-Version: 1.0", "X-Mailer: Shiva.J"];

  /* v25: prikazno ime privitka (kupac vidi npr. barkod-za-uplatu-2026-0007.png, ne ime privremene datoteke) */
  $attName = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($attachmentName ?: ($attachmentPath ? basename($attachmentPath) : ''))) ?: 'privitak.png';
  /* v95 (recenzija vijeća v94, L6): redak dulji od 900 bajtova (npr. dugačak tekst iz Postavki) — quoted-printable, jer SMTP
     odbija retke dulje od 998 znakova (RFC 5321); kratki e-mailovi ostaju 8bit kao dosad */
  $te = '8bit'; $textOut = $text;
  if (max(array_map('strlen', preg_split('/\r?\n/', $text) ?: [''])) > 900) { $te = 'quoted-printable'; $textOut = quoted_printable_encode(preg_replace('/\r?\n/', "\r\n", $text)); }
  if ($attachmentPath && is_file($attachmentPath)) {
    $headers[] = "Content-Type: multipart/mixed; boundary=\"$boundary\"";
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: $te\r\n\r\n" . $textOut . "\r\n";
    $body .= "--$boundary\r\nContent-Type: image/png; name=\"" . $attName . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $attName . "\"\r\n\r\n";
    $body .= chunk_split(base64_encode((string)file_get_contents($attachmentPath))) . "--$boundary--\r\n";
  } else {
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: $te";
    $body = $textOut;
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
