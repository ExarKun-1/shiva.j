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
define('SJ_VERSION', 'admin v40');

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
/* „torbu čuvamo” / „torbe čuvamo” / „rok za uplatu produžujemo” (narudžba bez unikata) — za tekstove kupcu */
function sj_unique_count(array $o): int { $n = 0; foreach ((array)($o['order']['items'] ?? []) as $it) if (empty($it['made'])) $n++; return $n; }
/* v70 (D70-5, D70-9): rok je prošao — torbu više ne čuvamo automatski; vlasnica provjerava izvod (bez novog obećanja) */
function sj_late_text(array $o, string $lead): string {
  $n = sj_unique_count($o);
  return $lead . ($n === 0 ? ', pa ga više ne možemo automatski produžiti' : ', pa ' . ($n > 1 ? 'torbe' : 'torbu') . ' više ne možemo automatski čuvati')
    . '. Javili smo vlasnici: provjerit će uplatu i javiti vam se.' . ($n === 0 ? '' : ($n > 1 ? ' Ako je neka od torbi u međuvremenu otišla drugom kupcu, za nju vam vraćamo uplaćeni iznos.' : ' Ako je torba u međuvremenu otišla drugom kupcu, vraćamo vam cijeli iznos.'));
}
function sj_already_text(array $o): string {
  if (!empty($o['claimLate']) || (int)strtotime(sj_pay_until($o)) <= time()) return sj_late_text($o, 'Već ste nam javili da ste uplatili. Rok za uplatu je prošao');
  return 'Već ste nam javili da ste uplatili; ' . sj_hold_phrase($o) . ' do ' . sj_fmt_deadline(sj_pay_until($o)) . '. Ako uplata do tada ne bude vidljiva, javit ćemo vam se e-mailom.';
}
function sj_hold_phrase(array $o): string {
  $n = sj_unique_count($o);
  return $n > 1 ? 'torbe čuvamo za vas' : ($n === 1 ? 'torbu čuvamo za vas' : 'rok za uplatu produžen je');
}
function sj_ucfirst(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
const SJ_CLAIM_MAX_FROM_CLICK = 96 * 3600;  /* vijeće (sigurnost): najviše 96 h od klika */
const SJ_CLAIM_MAX_FROM_ORDER = 120 * 3600; /* … i najviše 120 h od narudžbe */
const SJ_PROOF_MAX_DAYS = 7;
/* v70 (vijeće, K70-6): datum unaprijed za tekstove; „najmanje ” kad ga granica od 96 h skraćuje prije 23:59 radnog
   dana (blagdani) — pravi datum nakon klika tada je kasniji, pa tekst ostaje točan */
function sj_claim_preview(array $o): string {
  $t = sj_claim_until($o); $pu = (int)strtotime(sj_pay_until($o));
  return ($t < sj_next_workday_end($pu) ? 'najmanje ' : '') . 'do ' . sj_fmt_deadline(sj_iso($t));
}                /* „Potvrda primljena”: najkasnije 7 dana od narudžbe, najviše dvaput */
/* Do kada „Uplatio/la sam” čuva narudžbu: 23:59 radnog dana nakon kasnijeg od (rok, klik), uz granice; nikad kraće
   od postojećeg roka. Bez $now: datum za tekstove unaprijed (potvrda narudžbe, ploča, podsjetnik) — računa se kao da
   kupac klikne odmah po narudžbi, pa je pravi rok nakon klika prije isteka uvijek isti ili kasniji (obećanje vrijedi). */
function sj_claim_until(array $o, ?int $now = null): int {
  $pu = (int)strtotime(sj_pay_until($o)); $at = (int)strtotime((string)($o['at'] ?? '')) ?: $pu;
  $click = $now ?? min($at, $pu);
  $t = min(sj_next_workday_end(max($pu, $click)), $click + SJ_CLAIM_MAX_FROM_CLICK, $at + SJ_CLAIM_MAX_FROM_ORDER);
  return max($t, $pu);
}
/* „Potvrda primljena” (vlasnica je vidjela snimku): 23:59 radnog dana nakon kasnijeg od (rok, klik), najviše 96 h od
   klika; bez granice od narudžbe (vrijedi granica od 7 dana za sam gumb, SJ_PROOF_MAX_DAYS) */
function sj_proof_until(array $o, int $now): int {
  $pu = (int)strtotime(sj_pay_until($o));
  return max(min(sj_next_workday_end(max($pu, $now)), $now + SJ_CLAIM_MAX_FROM_CLICK), $pu);
}
/* Smije li se još kliknuti „Potvrda primljena”: '' = da, inače razlog */
function sj_proof_block(array $o, ?int $now = null): string {
  $now = $now ?? time();
  if (!empty($o['paidAt'])) return 'narudžba je već plaćena';
  if (!empty($o['cancelledAt'])) return 'narudžba je stornirana';
  if (empty($o['payUntil'])) return 'narudžba nema rok za uplatu';
  if (count((array)($o['proofAt'] ?? [])) >= 2) return 'potvrda je već dvaput zabilježena';
  if ($now > ((int)strtotime((string)($o['at'] ?? '')) + SJ_PROOF_MAX_DAYS * 86400)) return 'prošlo je više od ' . SJ_PROOF_MAX_DAYS . ' dana od narudžbe';
  return '';
}
/* v72: do kada je torba ove narudžbe stvarno rezervirana (najraniji kraj među njezinim unikatima; 0 = nije) */
function sj_held_until(array $o): int {
  $tok = (string)($o['reservation']['token'] ?? ''); if ($tok === '') return 0;
  $d = array_replace(sj_res_default(), sj_read_json(sj_res_path(), [])); $min = 0; $all = true;
  foreach ((array)($o['order']['items'] ?? []) as $it) { if (!empty($it['made'])) continue;
    $r = $d['res'][(string)($it['id'] ?? '')] ?? null;
    if (!$r || (string)($r['token'] ?? '') !== $tok) { $all = false; continue; }
    $u = (int)strtotime((string)($r['until'] ?? '')); $min = $min ? min($min, $u) : $u; }
  return $all ? $min : 0;
}
const SJ_HOLD_MAX_FROM_ORDER = 168 * 3600; /* v72: torba se drži najviše 7 dana od narudžbe */
/* v72: do kada je torba rezervirana nakon „Uplatio/la sam” / „Potvrda primljena”: do automatskog storna (rok za uplatu
   + auto_cancel_hours), ako su rokovi uključeni; nikad kraće od roka za uplatu */
function sj_hold_res_until(array $o, int $payTs): int {
  $c = sj_config(); $auto = !empty($c['deadline_tick']) ? max(0, (int)($c['auto_cancel_hours'] ?? 0)) : 0;
  if ($auto <= 0) return $payTs;
  $at = (int)strtotime((string)($o['at'] ?? '')) ?: $payTs;
  return max($payTs, min($payTs + $auto * 3600, $at + SJ_HOLD_MAX_FROM_ORDER));
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
     najviše 7 dana od narudžbe; rok za uplatu (payUntil) ostaje $new */
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
        if ($auto > 0 && $exp > 0 && $now >= $exp + $auto * 3600) {
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
    "Ove narudžbe automatski su stornirane jer uplata nije označena ni " . sj_hours_text($auto) . " nakon isteka roka:\n\n"
    . implode("\n", array_map(fn($x) => '- ' . (string)($x[1]['no'] ?? '') . ' — ' . (string)($x[1]['name'] ?? '') . ', ' . (string)($x[1]['total'] ?? '') . ', poziv na broj ' . sj_payment_ref((string)($x[1]['no'] ?? '')), $canc))
    . "\n\nKupci su dobili e-mail o stornu. Ako ipak vidite uplatu, u adminu → Narudžbe kliknite „Označi plaćenom”: ako je komad još slobodan, ostaje kupcu."
    . (sj_site_url_trusted() !== '' ? "\n\n" . sj_site_url_trusted() . '/admin/?s=narudzbe' : ''));
  return array_map(fn($x) => [$x[0], (string)($x[1]['no'] ?? '')], $todo);
}
function sj_deadline_mail(string $kind, array $o, array $c, int $auto): void {
  $no = (string)($o['no'] ?? ''); $name = (string)($o['name'] ?? ''); $until = sj_fmt_deadline(sj_pay_until($o)); $untilAcc = sj_fmt_deadline(sj_pay_until($o), 'acc');
  $uq = []; foreach ((array)($o['order']['items'] ?? []) as $it) if (empty($it['made'])) $uq[] = '„' . (string)($it['name'] ?? '') . '”';
  $many = count($uq) > 1;
  $hi = 'Dobar dan, ' . $name . ",\n\n"; $bye = "\n\nSrdačan pozdrav,\n" . sj_signature();
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
    $storno = $auto > 0 ? ' Ako do ' . sj_fmt_deadline(sj_iso($exp + $auto * 3600)) . ' ne vidimo ni uplatu ni vašu potvrdu o uplati, narudžbu storniramo.' : '';
    if (!empty($o['claimedAt']) || !empty($o['proofAt'])) {
      /* v72 (odluka vlasnice, 7. krug): kraći tekst; torba ostaje rezervirana do storna („ne stavljaj tu torbu
         ponovno u prodaju do isteka roka”) — datum iz stvarne rezervacije, ne obećanje */
      $held = sj_held_until($o);
      $heldTxt = $held > time() ? sj_fmt_deadline(sj_iso($held)) : '';
      sj_send_mail((string)$o['email'], $c['shop'] . " — uplatu još ne vidimo, narudžba $no",
        $hi . (!empty($o['proofAt']) && empty($o['claimedAt']) ? "primili smo vašu potvrdu o uplati za narudžbu br. $no, ali uplatu još ne vidimo na računu. Molimo vas, provjerite sa svojom bankom je li uplata izvršena i odgovorite na ovaj e-mail."
          : "javili ste nam da ste platili narudžbu br. $no, ali uplatu još ne vidimo na računu. Molimo vas, odgovorite na ovaj e-mail i pošaljite potvrdu o uplati (snimku ili potvrdu iz banke).")
        . ($uq && $heldTxt !== ''
          ? "\n\n" . ($many ? 'Torbe ' . implode(' i ', $uq) . ' i dalje čuvamo' : 'Torbu ' . $uq[0] . ' i dalje čuvamo') . " za vas do $heldTxt. Ako do tada ne dobijemo ni uplatu ni potvrdu, narudžbu storniramo i " . ($many ? 'torbe se vraćaju' : 'torba se vraća') . ' u prodaju.'
          : ($uq ? "\n\n" . ($many ? 'Torbe ' . implode(' i ', $uq) . ' su' : 'Torba ' . $uq[0] . ' je') . ' zasad ponovno u ponudi; ako ' . ($many ? 'ih' : 'je') . ' netko kupi prije nego što vaša uplata stigne, vraćamo vam cijeli iznos.' . $storno : $storno))
        . $bye, null, (string)$c['owner_email']);
    } else {
      /* v70: klik nakon isteka više ne čuva torbu (v69.1) — javlja vlasnici, koja provjeri izvod i javi se */
      sj_send_mail((string)$o['email'], $c['shop'] . " — rok za uplatu je prošao, narudžba $no",
        $hi . "rok za uplatu narudžbe br. $no prošao je u $untilAcc, a uplatu još ne vidimo" . ($back !== '' ? ", pa $back" : '') . '.'
        . "\n\nVeć ste platili? " . ($claim !== '' ? "Kliknite „Uplatio/la sam”:\n$claim\n(ili odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke)." : 'Odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke.')
        . ' Ako ste nam snimku potvrde već poslali, ne trebate ništa više. Provjerit ćemo izvod i javiti vam se' . ($uq ? '; ako je ' . ($many ? 'neka od torbi' : 'torba') . ' u međuvremenu otišla drugom kupcu, vraćamo vam cijeli iznos.' : '.')
        . "\n\nAko ste još uvijek zainteresirani za narudžbu, molimo vas da izvršite uplatu i pošaljete potvrdu o plaćanju" . ($uq ? '; ako ' . ($many ? 'torbe' : 'torbu') . ' u međuvremenu nije kupio netko drugi, ' . ($many ? 'one su vaše' : 'ona je vaša') . '.' : '.')
        . $storno
        . "\n\n" . sj_pay_lines($o, $c) . $bye, null, (string)$c['owner_email']);
    }
    sj_send_mail((string)$c['owner_email'], $c['shop'] . " — rok za uplatu prošao: $no — $name",
      "Rok za uplatu narudžbe $no ($name, " . (string)($o['total'] ?? '') . ", poziv na broj " . sj_payment_ref($no) . ") prošao je u $untilAcc." . ($uq ? (($hO = sj_held_until($o)) > time() ? ' Kupac je javio uplatu, pa ' . ($many ? 'su torbe i dalje rezervirane' : 'je torba i dalje rezervirana') . ' za njega do ' . sj_fmt_deadline(sj_iso($hO)) . ' (do automatskog storna).' : ' ' . ($many ? 'Torbe su vraćene' : 'Torba je vraćena') . ' u prodaju.') : '')
      . "\n\nAko vidite uplatu, u adminu → Narudžbe → „Rok istekao” kliknite „Označi plaćenom”; ako ne, „Storniraj narudžbu…”." . ($auto > 0 ? " Ako ne kliknete ništa, narudžba se automatski stornira za " . sj_hours_text($auto) . ' i kupac dobiva e-mail.' : '')
      . (sj_site_url_trusted() !== '' ? "\n\n" . sj_site_url_trusted() . '/admin/?s=narudzbe' : ''));
  } else {
    sj_send_mail((string)$o['email'], $c['shop'] . " — narudžba $no je stornirana",
      $hi . "narudžba br. $no je stornirana jer uplata nije stigla ni nakon roka." . ($uq ? ' ' . ($many ? 'Torbe su ponovno' : 'Torba je ponovno') . ' u prodaji.' : '')
      . "\n\nAko ste ipak platili, javite nam se odgovorom na ovaj e-mail i riješit ćemo to: " . ($uq ? 'ako je torba još slobodna, šaljemo vam je, a inače vam vraćamo cijeli iznos.' : 'narudžbu obnavljamo ili vam vraćamo cijeli iznos.') . $bye, null, (string)$c['owner_email']);
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
        : ($anyUnique ? ', a zatim cijelu narudžbu šaljemo zajedno, u jednom paketu' : '') . ($lead !== '' ? '; paket obično šaljemo ' . $lead . ' nakon izrade.' : '.') . ' Kad paket krene, poslat ćemo vam poruku s potvrdom slanja.');
  } else {
    $next = $pickup ? 'Javit ćemo vam se kad narudžba bude spremna za preuzimanje.'
      : 'Paket ' . ($lead !== '' ? 'obično šaljemo za ' . $lead : 'šaljemo uskoro') . '; kad krene, poslat ćemo vam poruku s potvrdom slanja.';
  }
  $ok = sj_send_mail((string)$o['email'], $c['shop'] . " — uplata je stigla, narudžba $no",
    'Dobar dan, ' . (string)($o['name'] ?? '') . ",\n\nuplata za narudžbu br. $no je stigla, hvala! " . $next . "\n\nSrdačan pozdrav,\n" . sj_signature(), null, (string)$c['owner_email']);
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
    'Dobar dan, ' . (string)($o['name'] ?? '') . ",\n\nnarudžba br. $no je stornirana$why."
    /* v69 (D69-13): za „nije uplaćeno u roku” isto obećanje kao kod automatskog storna */
    . ($reason === 'nije uplaćeno u roku'
      ? "\n\nAko ste ipak platili, javite nam se odgovorom na ovaj e-mail i riješit ćemo to: " . ($uq ? 'ako je torba još slobodna, šaljemo vam je, a inače vam vraćamo cijeli iznos.' : 'narudžbu obnavljamo ili vam vraćamo cijeli iznos.')
      : "\n\nAko ste je već platili, javite nam se odgovorom na ovaj e-mail i vratit ćemo vam cijeli iznos.")
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
  if ($tok !== '' || $no !== '') sj_order_edit($no !== '' ? ['no' => $no] : ['token' => $tok], function (array &$o) { unset($o['paidAt']); });
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
