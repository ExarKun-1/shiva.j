<?php
/* Jedinični testovi PHP-a, bez okvira:  php tests/run.php
   Složi zasebnu probnu stranicu (tests/serve.sh bez poslužitelja) i testira admin/lib.php nad njezinim data/. */
$site = getenv('SHIVAJ_UNIT_SITE') ?: sys_get_temp_dir() . '/shivaj-unit';
passthru('SHIVAJ_NO_SERVE=1 sh ' . escapeshellarg(__DIR__ . '/serve.sh') . ' ' . escapeshellarg($site) . ' >/dev/null', $rc);
if ($rc !== 0) { fwrite(STDERR, "serve.sh nije uspio\n"); exit(2); }
require $site . '/admin/lib.php';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$R = []; $ok = function ($c, $m) use (&$R) { $R[] = ($c ? 'PASS ' : 'FAIL ') . $m; };
$day = fn() => array_values(json_decode((string)@file_get_contents(SJ_DATA . '/brojac.json'), true) ?: [[]])[0] ?? [];

/* A: ručno plaćeno ne dira tuđu aktivnu rezervaciju */
$d = ['res' => ['b' => ['id' => 'b', 'token' => 'tact', 'name' => 'Ana', 'email' => 'a@b.hr', 'until' => '2099-01-01T00:00:00Z', 'ordered' => true]], 'sold' => []];
$r1 = sj_mark_paid($d, 'b', true);
$ok($r1 === false && isset($d['res']['b']) && !isset($d['sold']['b']), 'ručno „Plaćeno” odbija torbu s aktivnom rezervacijom i ne briše je');
$r2 = sj_mark_paid($d, 'c', true);
$ok($r2 === true && ($d['sold']['c']['token'] ?? 'x') === '', 'ručno „Plaćeno” na slobodnoj torbi upisuje oznaku bez tokena');
$ok(sj_mark_paid($d, 'c', true) === false, 'već plaćena torba se ne prepisuje');

/* B: istekla rezervacija broji se samo ako ima narudžbu */
file_put_contents(SJ_DATA . '/brojac.json', '{}');
file_put_contents(SJ_DATA . '/rezervacije.json', json_encode(['res' => [
  'x' => ['id' => 'x', 'token' => 't1', 'until' => '2000-01-01T00:00:00Z'],
  'y' => ['id' => 'y', 'token' => 't2', 'until' => '2000-01-01T00:00:00Z', 'ordered' => true],
  'z' => ['id' => 'z', 'token' => 't2', 'until' => '2000-01-01T00:00:00Z', 'ordered' => true]], 'sold' => []]));
sj_res_read();
$ok((int)($day()['expired'] ?? 0) === 1, 'isteklo: samo rezervacija s narudžbom, po narudžbi (očekivano 1, dobiveno ' . (int)($day()['expired'] ?? 0) . ')');

/* C: neuspio zapis ne prazni datoteku */
$p = SJ_DATA . '/proba.json'; file_put_contents($p, "{\"a\":1}\n");
$threw = false; try { sj_write_json($p, ['x' => INF]); } catch (RuntimeException $e) { $threw = true; }
$ok($threw && file_get_contents($p) === "{\"a\":1}\n" && !is_file($p . '.tmp'), 'neispravan sadržaj: iznimka, datoteka netaknuta');
@unlink($p);

/* D: oštećen config.json se ne prepisuje zadanima */
$cp = SJ_DATA . '/config.json'; $orig = file_get_contents($cp);
file_put_contents($cp, '{"oštećeno": ');
$threw = false; try { sj_config_update(function (array &$c) { $c['order_seq'] = 99; }); } catch (RuntimeException $e) { $threw = true; }
$ok($threw && file_get_contents($cp) === '{"oštećeno": ' && is_file($cp . '.osteceno-' . date('Ymd')), 'oštećen config.json: iznimka, sigurnosna kopija, bez prepisivanja');
file_put_contents($cp, $orig); @unlink($cp . '.osteceno-' . date('Ymd')); sj_config(true);
/* ostale datoteke: kopija i nastavak (brojač ne smije zaustaviti stranicu) */
$bp = SJ_DATA . '/brojac.json'; file_put_contents($bp, 'krnje');
sj_count('visits');
$ok(is_file($bp . '.osteceno-' . date('Ymd')) && (int)($day()['visits'] ?? 0) === 1, 'oštećen brojač: kopija, brojanje nastavlja');
@unlink($bp . '.osteceno-' . date('Ymd'));

/* E: sati */
$h = array_map('sj_hours_text', [1, 2, 5, 11, 12, 21, 22, 24, 48]);
$ok($h === ['1 sat', '2 sata', '5 sati', '11 sati', '12 sati', '21 sat', '22 sata', '24 sata', '48 sati'], 'sj_hours_text: ' . implode(', ', $h));

/* F: promašaji prijave: stari zapisi se brišu, novi se broji u bravi */
sj_config_update(function (array &$c) { $c['login_fails'] = ['staro' => ['n' => 3, 't' => time() - 2 * 86400]]; });
sj_login('kriva-lozinka-xyz');
$lf = sj_config(true)['login_fails'];
$ok(!isset($lf['staro']) && count($lf) === 1 && (int)array_values($lf)[0]['n'] === 1, 'neuspjela prijava: stari zapis obrisan, novi n=1');
sj_config_update(function (array &$c) { $c['login_fails'] = []; });


/* G: ograničenje zahtjeva: provjera bez upisa ne troši, upis troši */
@unlink(SJ_DATA . '/zahtjevi.json');
$okCheck = sj_rate_limit('t', 1, 3600, false, false) && sj_rate_limit('t', 1, 3600, false, false);
$okHit = sj_rate_limit('t', 1, 3600) && !sj_rate_limit('t', 1, 3600, false, false);
$ok($okCheck && $okHit, 'ograničenje: provjera bez upisa ne troši, upis troši');

/* H: proizvodi pod bravom: izmjena čita svježe stanje, javni popis bez skrivenih */
sj_products_update(function (array &$l) { $l[0]['name'] = 'Prva izmjena'; });
sj_products_update(function (array &$l) { $l[1]['hidden'] = true; });
$pl = sj_read_json(SJ_DATA . '/proizvodi.json', []); $pub = sj_read_json(SJ_PUBLIC_JSON, []);
$ok($pl[0]['name'] === 'Prva izmjena' && !empty($pl[1]['hidden']) && count($pub) === count($pl) - 1 && $pub[0]['name'] === 'Prva izmjena', 'izmjene proizvoda pod bravom se zbrajaju; javni popis bez skrivenih');
$threw = false; try { sj_products_update(function (array &$l) { $l = []; throw new RuntimeException('x'); }); } catch (RuntimeException $e) { $threw = true; }
$ok($threw && count(sj_read_json(SJ_DATA . '/proizvodi.json', [])) === count($pl), 'iznimka u izmjeni: ništa se ne zapisuje');

/* I: adresa stranice za e-mail: podmetnuti Host ne prolazi */
sj_config_update(function (array &$c) { $c['site_url'] = ''; $c['from_email'] = 'info@shivaj.hr'; });
$_SERVER['HTTP_HOST'] = 'zlo.example';
$bad = sj_site_url_trusted();
$_SERVER['HTTP_HOST'] = 'www.shivaj.hr';
$good = sj_site_url_trusted();
sj_config_update(function (array &$c) { $c['from_email'] = ''; });
$ok($bad === '' && $good === 'http://www.shivaj.hr', 'linkovi u e-mailu: tuđi Host odbijen (' . var_export($bad, true) . '), vlastita domena prihvaćena');

/* J: poziv na broj */
$ok(sj_payment_ref('SJ-2026-0007') === '2026-0007', 'poziv na broj iz broja narudžbe');

/* K: IPv6 po mreži /64, IPv4 po adresi */
$a6 = sj_short_ip('2001:db8:1:2:aaaa::1'); $b6 = sj_short_ip('2001:db8:1:ff00:ffff:1:2:3'); $c6 = sj_short_ip('2001:db8:2::1');
$ok($a6 === $b6 && $a6 !== $c6 && sj_short_ip('203.0.113.1') !== sj_short_ip('203.0.113.2'), 'IPv6 iz iste /48 mreže = ista oznaka; druga mreža i druga IPv4 adresa = druga');
/* granica po adresi: dvije IPv6 adrese iz iste /48 dijele brojač */
@unlink(SJ_DATA . '/zahtjevi.json');
$_SERVER['REMOTE_ADDR'] = '2001:db8:5:1::1'; $g1 = sj_rate_limit('t6', 1, 3600);
$_SERVER['REMOTE_ADDR'] = '2001:db8:5:99::7'; $g2 = sj_rate_limit('t6', 1, 3600);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$ok($g1 && !$g2, 'dvije IPv6 adrese iz iste /48 mreže dijele granicu');
/* zahtjevi.json najviše 5000 ključeva */
$big = []; $t = time(); for ($i = 0; $i < 6000; $i++) $big['x|' . $i] = [$t - 6000 + $i];
file_put_contents(SJ_DATA . '/zahtjevi.json', json_encode($big));
sj_rate_limit('t7', 5, 3600);
$zj = sj_read_json(SJ_DATA . '/zahtjevi.json', []);
$ok(count($zj) <= 5000 && isset($zj['x|5999']) && !isset($zj['x|0']), 'zahtjevi.json najviše 5000 ključeva, najstariji se brišu (' . count($zj) . ')');

/* K2: zamjena tjedne tajne: prošlotjedna oznaka iste adrese i dalje se prepoznaje */
$cur = sj_short_ip('198.51.100.7');
$sol = json_decode(file_get_contents(SJ_DATA . '/sol.json'), true); $sol['day'] = '2000-W01'; file_put_contents(SJ_DATA . '/sol.json', json_encode($sol));
$new = sj_short_ip('198.51.100.7'); $old = sj_short_ip('198.51.100.7', true);
$ok($new !== $cur && $old === $cur && sj_same_ip(['ip' => $cur], sj_short_ips('198.51.100.7')), 'nakon zamjene tajne stara oznaka iste adrese se prepoznaje');

/* L: e-mail za brojanje */
$ok(sj_email_key('Ana.Kovac+torba@GMAIL.com') === sj_email_key('anakovac@gmail.com') && sj_email_key('ana+1@primjer.hr') === 'ana@primjer.hr' && sj_email_key('a.b@primjer.hr') !== sj_email_key('ab@primjer.hr'), 'e-mail za granicu: mala slova, bez +oznake, Gmail bez točaka');

/* M: arhiva narudžbi umjesto brisanja */
file_put_contents(SJ_DATA . '/narudzbe.json', '[]'); @unlink(SJ_DATA . '/narudzbe-arhiva-' . date('Y') . '.json');
for ($i = 1; $i <= 5; $i++) sj_orders_prepend(['no' => 'SJ-T-' . $i, 'paidAt' => '2026-01-01T00:00:00Z'], 3); /* v95: u arhivu idu samo zatvorene */
$list = sj_read_json(SJ_DATA . '/narudzbe.json', []); $arh = sj_read_json(SJ_DATA . '/narudzbe-arhiva-' . date('Y') . '.json', []);
$ok(array_column($list, 'no') === ['SJ-T-5', 'SJ-T-4', 'SJ-T-3'] && array_column($arh, 'no') === ['SJ-T-1', 'SJ-T-2'], 'starije narudžbe idu u arhivu, ništa se ne briše');

/* N: ukupna granica (potvrde kupcima) */
@unlink(SJ_DATA . '/zahtjevi.json');
$n = 0; for ($i = 0; $i < 5; $i++) if (sj_global_limit('proba', 3, 3600)) $n++;
$ok($n === 3, 'ukupna granica: 3 od 5 propušteno');
/* v20: provjera bez upisa; pri 5000 ključeva 'sve|' ostaje, a brojači posjeta ispadaju prvi */
@unlink(SJ_DATA . '/zahtjevi.json');
$chk = sj_global_limit('proba2', 1, 3600, false) && sj_global_limit('proba2', 1, 3600, false) && sj_global_limit('proba2', 1, 3600) && !sj_global_limit('proba2', 1, 3600, false);
$ok($chk, 'ukupna granica: provjera bez upisa ne troši granicu');
$big = ['sve|mailto-zrtva' => [time()]]; $old = time() - 60;
for ($i = 0; $i < 3000; $i++) $big["t|10.$i"] = [$old + $i];
for ($i = 0; $i < 2100; $i++) $big["visit|10.$i"] = [time()];
file_put_contents(SJ_DATA . '/zahtjevi.json', json_encode($big));
sj_rate_limit('t8', 5, 3600);
$zj = sj_read_json(SJ_DATA . '/zahtjevi.json', []);
$visits = count(array_filter(array_keys($zj), fn($k) => str_starts_with($k, 'visit|')));
$ok(isset($zj['sve|mailto-zrtva']) && count($zj) <= 5000 && $visits < 2100 && count(array_filter(array_keys($zj), fn($k) => str_starts_with($k, 't|'))) === 3000, 'pri 5000 ključeva: sve| ostaje, ispadaju brojači posjeta (' . count($zj) . ' ključeva, ' . $visits . ' visit)');

/* v66: Unicode prijelomi reda i znakovi smjera ne ulaze u jednoredna polja (lažni redak u e-mailu vlasnici) */
$ok(sj_clean("a\u{FEFF}b\u{00AD}c\u{0085}d\u{061C}e", 80) === 'abcde' && sj_clean("\u{2028}\u{2028}", 80) === '' && sj_clean("Ana Kovač", 80) === 'Ana Kovač', 'v67: sj_clean uklanja i \\p{Cf} i C1; ime od nevidljivih znakova ostaje prazno');
$ok(sj_clean("crna\u{2028}Poziv na broj: 99", 80) === 'crna Poziv na broj: 99' && sj_clean("a\u{202E}b\u{200F}c", 80) === 'abc' && sj_clean("x\u{2029}y", 80, true) === "x\ny", 'v66: sj_clean uklanja U+2028/U+2029 i znakove smjera');

/* v69 (vijeće na v68, N69-1): .htaccess i router.php propuštaju točno one radnje koje api/index.php prepoznaje */
$root = dirname(__DIR__);
$acts = fn(string $f, string $re) => preg_match($re, (string)file_get_contents($root . '/' . $f), $mm) ? explode('|', $mm[1]) : [];
$aApi = $acts('api/index.php', '~#/\(([a-z|]+)\)/\?\$#~'); $aHt = $acts('.htaccess', '~\^api/\(([a-z|]+)\)~'); $aRt = $acts('router.php', '~\^/api/\(([a-z|]+)\)~');
sort($aApi); sort($aHt); sort($aRt);
$ok($aApi && $aApi === $aHt && $aApi === $aRt && in_array('paidclaim', $aApi, true), 'v69: .htaccess, router.php i api/index.php imaju iste radnje (' . implode(',', $aHt) . ')');

/* v69 (vijeće, N69-6): IPv4 zapisan kao IPv6 broji se kao ista IPv4 adresa, a različite adrese ostaju različite */
$ok(sj_short_ip('::ffff:93.184.216.34') === sj_short_ip('93.184.216.34') && sj_short_ip('::ffff:93.184.216.34') !== sj_short_ip('::ffff:10.1.2.3'), 'v69: ::ffff:a.b.c.d se broji kao IPv4 (ne kao jedna zajednička mreža)');
$ok(sj_fmt_deadline('2026-10-29T07:16:00Z') === 'četvrtka, 29. 10. 2026. u 08:16' && sj_fmt_deadline('2026-10-30T07:16:00Z', 'acc') === 'petak, 30. 10. 2026. u 08:16', 'v69: rok u e-mailovima kao na stranici (dan u tjednu, „u”)');

/* v70: blagdani RH (NN 110/19, čl. 1; NN 72/25 dodaje samo spomendan 9. 1.) i „Uplatio/la sam” do 23:59 radnog dana */
$hol = fn(int $y) => array_keys(sj_hr_holidays($y)); $h26 = $hol(2026); sort($h26); $h27 = $hol(2027); sort($h27);
$ok($h26 === ['01-01', '01-06', '04-05', '04-06', '05-01', '05-30', '06-04', '06-22', '08-05', '08-15', '11-01', '11-18', '12-25', '12-26']
  && $h27 === ['01-01', '01-06', '03-28', '03-29', '05-01', '05-27', '05-30', '06-22', '08-05', '08-15', '11-01', '11-18', '12-25', '12-26']
  && sj_easter(2025) === [4, 20] && sj_easter(2028) === [4, 16] && !isset(sj_hr_holidays(2026)['01-09']),
  'v70: blagdani 2026. i 2027. (Uskrs, Uskrsni ponedjeljak, Tijelovo = Uskrs + 60; 9. 1. nije neradni dan)');
$zg = new DateTimeZone('Europe/Zagreb');
$T = fn(string $s) => (new DateTimeImmutable($s, $zg))->getTimestamp();
$F = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone($zg)->format('D Y-m-d H:i');
$ok($F(sj_next_workday_end($T('2026-10-30 21:00'))) === 'Mon 2026-11-02 23:59' && $F(sj_next_workday_end($T('2026-10-31 21:00'))) === 'Mon 2026-11-02 23:59'
  && $F(sj_next_workday_end($T('2026-12-24 10:00'))) === 'Mon 2026-12-28 23:59' && $F(sj_next_workday_end($T('2026-10-27 09:00'))) === 'Wed 2026-10-28 23:59'
  && $F(sj_next_workday_end($T('2026-04-03 12:00'))) === 'Tue 2026-04-07 23:59' && $F(sj_next_workday_end($T('2026-10-24 21:00'))) === 'Mon 2026-10-26 23:59',
  'v70: sljedeći radni dan u 23:59 — petak/subota → ponedjeljak (1. 11.), Badnjak → 28. 12., Uskrs → utorak, prijelaz na zimsko vrijeme');
/* narudžba pet 30. 10. 21:00, rok sub 21:00, klik sub navečer → pon 2. 11. 23:59; Badnjak: granica 96 h od klika; granica 120 h od narudžbe */
$oF = ['at' => gmdate('c', $T('2026-10-30 21:00')), 'payUntil' => gmdate('c', $T('2026-10-31 21:00'))];
$oX = ['at' => gmdate('c', $T('2026-12-23 12:00')), 'payUntil' => gmdate('c', $T('2026-12-24 12:00'))];
$ok($F(sj_claim_until($oF, $T('2026-10-31 20:30'))) === 'Mon 2026-11-02 23:59' && $F(sj_claim_until($oF)) === 'Mon 2026-11-02 23:59'
  && $F(sj_claim_until($oX, $T('2026-12-24 11:00'))) === 'Mon 2026-12-28 23:59' && $F(sj_claim_until($oX)) === 'Mon 2026-12-28 23:59'
  && sj_claim_until($oX) <= sj_claim_until($oX, $T('2026-12-24 11:00')) && sj_claim_until(['at' => gmdate('c', $T('2026-10-30 21:00')), 'payUntil' => gmdate('c', $T('2026-11-04 20:00'))], $T('2026-11-04 19:00')) === $T('2026-11-05 23:59'),
  'v70/v76: „Uplatio/la sam” — 23:59 prvoga radnog dana nakon roka, bez granica od 96 h i 120 h (Božić → pon 28. 12.); datum unaprijed nikad kasniji od pravog');
$ok(sj_claim_until(['at' => gmdate('c', $T('2026-10-01 10:00')), 'payUntil' => gmdate('c', $T('2026-10-08 10:00'))], $T('2026-10-08 09:00')) === $T('2026-10-09 23:59')
  && sj_proof_until(['payUntil' => gmdate('c', $T('2026-10-31 18:00'))], $T('2026-10-31 19:00')) === $T('2026-11-02 23:59') && sj_proof_until(['payUntil' => gmdate('c', $T('2026-12-24 12:00'))], $T('2026-12-24 13:00')) === $T('2026-12-28 23:59'),
  'v70/v76: rok se nikad ne skraćuje; „Potvrda primljena” do 23:59 prvoga radnog dana (bez granice od 96 h)');

$ok(sj_claim_preview($oF) === 'do ponedjeljka, 2. 11. 2026. u 23:59' && sj_claim_preview($oX) === 'do ponedjeljka, 28. 12. 2026. u 23:59'
  && str_contains(sj_already_text(['claimLate' => true, 'payUntil' => '2000-01-01T00:00:00Z', 'order' => ['items' => [['id' => 'a', 'name' => 'A']]]]), 'torbu više ne možemo automatski čuvati')
  && str_contains(sj_late_text(['order' => ['items' => [['id' => 'k', 'made' => true]]]], 'Rok je prošao'), 'ne možemo automatski produljiti'),
  'v70/v76: datum unaprijed bez „najmanje” (nema granice od 96 h); nakon isteka bez obećanja čuvanja; bez unikata bez „torbe”');

/* v72: torba do storna — granica 7 dana od narudžbe */
$okH = (function () use ($T) {
  $c = sj_config(); $c['deadline_tick'] = true; $c['auto_cancel_hours'] = 48; sj_save_config($c);
  $a = sj_hold_res_until(['at' => gmdate('c', $T('2026-10-30 21:00'))], $T('2026-11-02 23:59')) === $T('2026-11-04 23:59');
  $b = sj_hold_res_until(['at' => gmdate('c', $T('2026-10-30 21:00'))], $T('2026-11-04 21:00')) === $T('2026-11-06 21:00');
  $c['deadline_tick'] = false; sj_save_config($c);
  $d = sj_hold_res_until(['at' => gmdate('c', $T('2026-10-30 21:00'))], $T('2026-11-02 23:59')) === $T('2026-11-02 23:59');
  return $a && $b && $d;
})();
$ok($okH, 'v72: rezervacija do storna (rok + 48 h), najviše 7 dana od narudžbe; bez rokova samo do roka za uplatu');

$ok(sj_care_applies(['material' => 'Umjetna koža, podstava od lana']) && sj_care_applies(['material' => '']) && sj_care_applies(['material' => '{MATERIJAL}'])
  && !sj_care_applies(['material' => 'Pamučna tkanina s ručnim printom']) && !sj_care_applies(['cat' => 'ostalo', 'material' => 'umjetna koža']) && !sj_care_applies(['cat' => 'ostalo']),
  'v74 (vijeće V74-1/5): upute za njegu samo uz torbe od umjetne kože (prazan materijal torbe = umjetna koža), ne uz tkaninu ni Ostalo');
$ok(str_starts_with(sj_care_text(), 'Bok, ja sam tvoja nova torba.') && str_ends_with(sj_care_text(), 'Uz malo brige dugo ćemo ići zajedno.'), 'v74: tekst uputa za njegu doslovno iz index.html');
$c75 = sj_defaults(); $tA = sj_terms_text($c75); $c75['ship_lead'] = ''; $tB = sj_terms_text($c75); $c75['ship_lead'] = '3 $1 dana'; $tC = sj_terms_text($c75);
$ok(str_contains($tA, 'nakon primitka uplate, u roku 1–2 radna dana. Modele') && str_contains($tB, 'nakon primitka uplate. Modele') && str_contains($tC, 'u roku 3 $1 dana.') && str_contains($tA, 'najkasnije u roku 14 dana od primitka uplate'),
  'v75: Uvjeti t. 6 — rok slanja iz Postavki (zadano 1–2 radna dana; prazno bez roka), Stalna kolekcija najkasnije 14 dana');
$Z = fn(string $x) => (new DateTime($x, new DateTimeZone('Europe/Zagreb')))->getTimestamp();
$ok(sj_pay_deadline($Z('2026-11-06 20:00'), 24) === $Z('2026-11-09 23:59') && sj_pay_deadline($Z('2026-11-05 20:00'), 24) === $Z('2026-11-06 20:00')
  && sj_pay_deadline($Z('2026-12-24 20:00'), 24) === $Z('2026-12-28 23:59') && sj_pay_deadline($Z('2026-11-07 10:00'), 24) === $Z('2026-11-09 23:59') && sj_pay_deadline($Z('2026-11-08 23:30'), 1) === $Z('2026-11-09 00:30'),
  'v76 (točka B): rok koji pada u subotu, nedjelju ili blagdan pomiče se na 23:59 prvoga radnog dana (pet 20:00 → pon 23:59; Badnjak → pon 28. 12.); radni dan bez promjene');
/* v78 (odluka vlasnice, 12. krug, t3): automatski storno 48 h nakon roka; subota, nedjelja, blagdan → 23:59 radnog dana */
$ok(sj_auto_cancel_at($Z('2026-11-06 20:00'), 48) === $Z('2026-11-09 23:59') && sj_auto_cancel_at($Z('2026-11-03 20:00'), 48) === $Z('2026-11-05 20:00')
  && sj_auto_cancel_at($Z('2026-12-23 12:00'), 48) === $Z('2026-12-28 23:59') && sj_auto_cancel_at($Z('2026-11-04 10:00'), 48) === $Z('2026-11-06 10:00')
  && sj_cancel_due(['expiredAt' => sj_iso($Z('2026-11-06 20:00'))], 48) === $Z('2026-11-09 23:59'),
  'v78 (t3): automatski storno koji pada u subotu, nedjelju ili blagdan pomiče se na 23:59 prvoga radnog dana (pet 20:00 + 48 h → pon 23:59; 23. 12. → pon 28. 12.); radni dan bez promjene');
/* v81 (13. krug, p2): unikat se drži do 23:59 prvoga radnog dana nakon roka (ZZP čl. 15.) */
$ok(sj_hold_after_deadline($Z('2026-11-06 20:00')) === $Z('2026-11-09 23:59') && sj_hold_after_deadline($Z('2026-11-04 10:00')) === $Z('2026-11-05 23:59') && sj_hold_after_deadline($Z('2026-12-23 12:00')) === $Z('2026-12-24 23:59'),
  'v81 (p2): držanje unikata do 23:59 prvoga radnog dana nakon roka (pet → pon; sri → čet; 23. 12. → 24. 12.)');
/* v81 (13. krug, p4): automatsko brisanje — neostvarene 30 dana nakon storna, plaćene 3 godine nakon uplate */
$nowP = time(); $isoP = fn(int $d) => gmdate('Y-m-d\TH:i:s\Z', $nowP - $d * 86400);
$mkP = fn(string $no, array $x) => $x + ['no' => $no, 'at' => $isoP(40), 'name' => 'Ana Kupac', 'email' => 'ana@primjer.hr', 'phone' => '091', 'address' => 'Ulica 1, Zabok', 'note' => 'napomena', 'claim' => str_repeat('a', 32), 'ip' => 'abcd', 'items' => '- Luna (remen: crna, 140 cm) — 65,00 €', 'shipping' => 'BOX NOW — paketomat: Zabok 1',
  'order' => ['customer' => ['name' => 'Ana Kupac', 'email' => 'ana@primjer.hr'], 'items' => [['id' => 'luna', 'name' => 'Luna', 'qty' => 1, 'note' => 'remen: crna, 140 cm']], 'shipping' => ['id' => 'boxnow', 'label' => 'BOX NOW paketomat', 'locker' => 'Zabok 1'], 'note' => 'n']];
file_put_contents(SJ_DATA . '/narudzbe.json', json_encode([$mkP('SJ-2026-0901', ['cancelledAt' => $isoP(31)]), $mkP('SJ-2026-0902', ['cancelledAt' => $isoP(5)]), $mkP('SJ-2026-0903', ['paidAt' => $isoP(1100), 'at' => $isoP(1101)]), $mkP('SJ-2026-0904', ['paidAt' => $isoP(100)]), $mkP('SJ-2026-0905', [])]));
file_put_contents(SJ_DATA . '/raskidi.json', json_encode([['at' => $isoP(1100), 'name' => 'Stari', 'email' => 's@p.hr'], ['at' => $isoP(10), 'name' => 'Novi', 'email' => 'n@p.hr']]));
@unlink(SJ_DATA . '/brisanje.json');
$nP = sj_privacy_purge(); $nP2 = sj_privacy_purge();
$byP = []; foreach (json_decode(file_get_contents(SJ_DATA . '/narudzbe.json'), true) as $x) $byP[$x['no']] = $x; $rkP = json_decode(file_get_contents(SJ_DATA . '/raskidi.json'), true);
$anon = fn($x) => !empty($x['anonymizedAt']) && ($x['name'] ?? '') === '(anonimizirano)' && !isset($x['email']) && !isset($x['phone']) && !isset($x['order']['customer']) && !str_contains(json_encode($x, JSON_UNESCAPED_UNICODE), 'crna, 140') && !str_contains(json_encode($x, JSON_UNESCAPED_UNICODE), 'Zabok 1') && str_contains($x['items'], 'Luna');
$ok($nP === 3 && $nP2 === 0 && $anon($byP['SJ-2026-0901']) && $anon($byP['SJ-2026-0903']) && empty($byP['SJ-2026-0902']['anonymizedAt']) && empty($byP['SJ-2026-0904']['anonymizedAt']) && empty($byP['SJ-2026-0905']['anonymizedAt']) && count($rkP) === 1 && $rkP[0]['name'] === 'Novi',
  'v81 (p4): anonimizirane neostvarene narudžbe nakon 30 dana i plaćene nakon 3 godine (ostaju broj, iznos i nazivi), izjava o raskidu nakon 3 godine; najviše jednom dnevno');
/* recenzija R81-6/R81-5: sporni storno (javljena uplata) čuva se 3 godine; oznake „plaćeno” bez imena nakon 3 godine */
file_put_contents(SJ_DATA . '/narudzbe.json', json_encode([$mkP('SJ-2026-0911', ['cancelledAt' => $isoP(40), 'claimedAt' => $isoP(41)]), $mkP('SJ-2026-0912', ['cancelledAt' => $isoP(1100), 'unpaidAt' => $isoP(1101)])]));
$rs0 = json_decode((string)@file_get_contents(sj_res_path()), true) ?: []; $rs = $rs0 + ['res' => [], 'sold' => []];
$rs['sold']['stara'] = ['id' => 'stara', 'name' => 'Stari Kupac', 'email' => 'st@p.hr', 'paidAt' => $isoP(1100)]; $rs['sold']['nova'] = ['id' => 'nova', 'name' => 'Novi Kupac', 'email' => 'no@p.hr', 'paidAt' => $isoP(10)];
file_put_contents(sj_res_path(), json_encode($rs)); @unlink(SJ_DATA . '/brisanje.json'); sj_privacy_purge();
$byQ = []; foreach (json_decode(file_get_contents(SJ_DATA . '/narudzbe.json'), true) as $x) $byQ[$x['no']] = $x; $rsA = json_decode(file_get_contents(sj_res_path()), true);
$ok(empty($byQ['SJ-2026-0911']['anonymizedAt']) && !empty($byQ['SJ-2026-0912']['anonymizedAt']) && $rsA['sold']['stara']['name'] === '' && $rsA['sold']['stara']['email'] === '' && $rsA['sold']['nova']['name'] === 'Novi Kupac',
  'v81 (recenzija R81-5/6): storno uz javljenu uplatu čuva se 3 godine; ime i e-mail u oznakama „plaćeno” brišu se nakon 3 godine');
/* v82 (završna recenzija, R82-3): otvorena neplaćena narudžba bez odluke (kasni klik) briše se 3 godine od narudžbe */
file_put_contents(SJ_DATA . '/narudzbe.json', json_encode([$mkP('SJ-2026-0913', ['at' => $isoP(1100), 'payUntil' => $isoP(1099), 'claimedAt' => $isoP(1099), 'claimLate' => true]), $mkP('SJ-2026-0914', ['payUntil' => $isoP(39), 'claimedAt' => $isoP(39), 'claimLate' => true])]));
@unlink(SJ_DATA . '/brisanje.json'); sj_privacy_purge();
$byR = []; foreach (json_decode(file_get_contents(SJ_DATA . '/narudzbe.json'), true) as $x) $byR[$x['no']] = $x;
$ok(!empty($byR['SJ-2026-0913']['anonymizedAt']) && empty($byR['SJ-2026-0914']['anonymizedAt']) && ($byR['SJ-2026-0914']['email'] ?? '') === 'ana@primjer.hr', 'v82 (R82-3): otvorena narudžba bez odluke briše se 3 godine od narudžbe, mlađa ostaje');
file_put_contents(sj_res_path(), json_encode($rs0 ?: ['res' => [], 'sold' => []]));
/* recenzija R81-2: bez klika automatski storno nikad prije obećanog držanja (i kad je u Postavkama manje od 48 h) */
$puC = $Z('2026-11-09 23:30'); $oC = ['payUntil' => gmdate('Y-m-d\TH:i:s\Z', $puC), 'expiredAt' => gmdate('Y-m-d\TH:i:s\Z', $puC)];
$ok(sj_cancel_due($oC, 1) === $Z('2026-11-10 23:59') && sj_cancel_due($oC, 48) === $Z('2026-11-11 23:30'), 'v81 (R81-2): automatski storno (1 h u Postavkama) tek nakon držanja do 23:59 radnog dana; 48 h bez promjene');
file_put_contents(SJ_DATA . '/narudzbe.json', '[]'); @unlink(SJ_DATA . '/raskidi.json');
$c78 = sj_defaults(); $c78['deadline_tick'] = true; $c78['auto_cancel_hours'] = 48; $tOn = sj_terms_text($c78); $c78['deadline_tick'] = false; $tOff = sj_terms_text($c78);
$ok(str_contains($tOn, 'Narudžbu bez uplate storniramo 48 sati nakon isteka roka (ako to pada u subotu, nedjelju ili na blagdan, u 23:59 prvoga sljedećeg radnog dana) i o tome vas obavještavamo e-mailom.')
  && str_contains($tOff, 'Narudžbu bez uplate storniramo i o tome vas obavještavamo e-mailom.'),
  'v78 (t3): Uvjeti t. 5 u e-mailu — automatski storno iz Postavki (bez rokova rečenica bez sati)');
$ok(str_contains($tOn, 'Torbe pakiramo u kartonsku kutiju s papirnatim punjenjem, zamotanu u papir i zatvorenu papirnatom ljepljivom trakom; manje artikle (npr. kozmetičke torbice) ponekad šaljemo u plastičnoj ambalaži. Zasebnu isporuku prihvatljivu za okoliš ne nudimo.')
  && !preg_match('/posebn\w* želj|osobnim specifikacijama izvan ponude|Kada pravo na raskid nemate/u', $tOn) && str_contains($tOn, 'Pravo na raskid imate za sve artikle: za unikate, za modele iz Stalne kolekcije i za druge artikle izrađene po narudžbi'),
  'v78 (t6, bez želja): Uvjeti t. 6 pakiranje i isporuka za okoliš; bez posebnih želja i iznimke od raskida');
$okL = !sj_has_link('100 cm. Hvala') && !sj_has_link('Ilica 1.Online') && !sj_has_link('Pozdrav.Eu sam') && !sj_has_link('pratite me @ana') && !sj_has_link('Ana-Marija Šimić') && !sj_has_link('BOX NOW 1234, Ilica 1, 10000 Zagreb')
  && sj_has_link('www.prevara.example') && sj_has_link('https://x.y') && sj_has_link('pisi na a@b.hr') && sj_has_link('posjeti prevara.hr') && sj_unlink('idi na http://zlo.example sad') === 'idi na [uklonjeno] sad';
$ok($okL, 'v77 (vijeće V77-2): filtar poveznica — bez lažnih pogodaka (velika slova iza točke, @ime), poveznice i e-adrese i dalje odbijene');
$kol = ['made' => true, 'cat' => 'torbe']; $ost = ['made' => true, 'cat' => 'ostalo'];
$ok(sj_strap_split('remen: crna, 140 cm; inicijali', $kol) === ['crna, 140 cm', 'inicijali'] && sj_strap_split('remen: ljubičasta, 200 cm', $kol) === ['', 'remen: ljubičasta, 200 cm']
  && sj_strap_split('remen: crna, 140 cm', $ost) === ['', 'remen: crna, 140 cm'] && sj_strap_split('smeđi remen', $kol) === ['', 'smeđi remen'],
  'v77 (vijeće V77-1): „iz ponude” samo za Stalnu kolekciju i samo s popisa boja i duljina');
$html = (string)file_get_contents(__DIR__ . '/../index.html');
preg_match('/const STRAP_COLORS = (\[[^\]]*\]);/u', $html, $mC); preg_match('/const STRAP_LENGTHS = (\[[^\]]*\]);/u', $html, $mL);
/* v84 (recenzija): e-mail s Uvjetima bez opisa slike EU obavijesti, s uputom na stranicu */
$tt84 = sj_terms_text();
$ok(!str_contains($tt84, 'Klikom') && str_contains($tt84, 'Usklađenu obavijest EU o zakonskom jamstvu možete pogledati na našoj stranici'), 'v84: e-mail Uvjeta — umjesto slike EU obavijesti uputa na stranicu');
$ok(str_contains($tt84, 'telefon: 099 886 6877') && str_contains($tt84, 'tel. 099 886 6877'), 'v85: e-mail Uvjeta nosi telefon obrta (t. 1 i obrazac za raskid)');
$ok(json_decode($mC[1] ?? 'null', true) === SJ_STRAP_COLORS && json_decode($mL[1] ?? 'null', true) === SJ_STRAP_LENGTHS, 'v77: popis boja i duljina remena isti na stranici i u PHP-u');
/* v90 (vijeće): brojač narudžbi nikad unatrag (config vraćen iz kopije) */
$nb = (string)@file_get_contents(SJ_DATA . '/narudzbe.json'); $cb = (string)@file_get_contents(SJ_DATA . '/config.json');
file_put_contents(SJ_DATA . '/narudzbe.json', json_encode([['no' => 'SJ-2026-0007', 'at' => '2026-11-01T10:00:00Z', 'email' => 'x@p.hr']]));
sj_config_update(function (array &$c90) { $c90['order_seq'] = 0; });
$ok(sj_order_seq_floor() === 7 && sj_next_order_seq() === 8 && sj_next_order_seq() === 9, 'v90: brojač ide od najvećeg postojećeg broja (0007 → 0008), ne od 1');
/* v90: oštećene rezervacije i narudžbe se ne prepisuju */
$rp = sj_res_path(); $rb = (string)@file_get_contents($rp);
file_put_contents($rp, '{"res":{"luna":'); $thr = false;
try { sj_with_lock($rp, function (array &$d) { $d = ['res' => [], 'sold' => []]; return true; }, sj_res_default()); } catch (RuntimeException $e) { $thr = str_contains($e->getMessage(), 'nije čitljiv'); }
$ok($thr && file_get_contents($rp) === '{"res":{"luna":' && is_file($rp . '.osteceno-' . date('Ymd')) && isset(json_decode((string)file_get_contents(SJ_DATA . '/ostecene-datoteke.json'), true)['rezervacije.json']), 'v90: oštećena rezervacije.json — greška, datoteka netaknuta, kopija i zapis za admin');
file_put_contents(SJ_DATA . '/narudzbe.json', '[{"no":'); $thr2 = false;
try { sj_orders_prepend(['no' => 'SJ-2026-0100']); } catch (RuntimeException $e) { $thr2 = true; }
$ok($thr2 && file_get_contents(SJ_DATA . '/narudzbe.json') === '[{"no":', 'v90: oštećena narudzbe.json — nova narudžba je ne prepisuje praznom');
file_put_contents($rp, $rb); file_put_contents(SJ_DATA . '/narudzbe.json', $nb); file_put_contents(SJ_DATA . '/config.json', $cb); @unlink(SJ_DATA . '/ostecene-datoteke.json'); sj_config(true);
/* v90: dnevna kopija, jednom dnevno, čuva se 14 dana */
@mkdir(SJ_DATA . '/kopije/2000-01-01', 0755, true); file_put_contents(SJ_DATA . '/kopije/2000-01-01/x.json', '{}');
$b1 = sj_daily_backup(); $b2 = sj_daily_backup();
$ok($b1 !== null && is_file($b1 . '/config.json') && $b2 === null && !is_dir(SJ_DATA . '/kopije/2000-01-01') && sj_last_backup() === date('Y-m-d'), 'v90: dnevna kopija data/*.json — jednom dnevno, starije od 14 dana brišu se');
/* v98 (recenzija vijeća v97, N5): napola kopiran dan (bez oznake .gotovo) dovršava se; prazan config.json ne ide u kopiju */
$bd98 = SJ_DATA . '/kopije/' . date('Y-m-d'); @unlink($bd98 . '/.gotovo'); @unlink($bd98 . '/config.json');
$cb98 = file_get_contents(SJ_DATA . '/config.json'); file_put_contents(SJ_DATA . '/config.json', '');
$b3 = sj_daily_backup();
$ok($b3 !== null && is_file($bd98 . '/.gotovo') && !is_file($bd98 . '/config.json'), 'v98: dnevna kopija dovršava napola kopiran dan; prazan config.json se ne kopira');
$thr98 = false; try { sj_config_update(function (array &$c) { $c['shop'] = 'X'; }); } catch (RuntimeException $e) { $thr98 = true; }
$sec98 = sj_pow_secret();
$ok($thr98 && file_get_contents(SJ_DATA . '/config.json') === '' && strlen($sec98) === 64, 'v98 (N1): prazan config.json — izmjena se odbija, tajna za „niste robot” privremena, ništa se ne prepisuje');
$ok(isset(json_decode((string)@file_get_contents(SJ_DATA . '/ostecene-datoteke.json'), true)['config.json']), 'v99 (L1): prazan config.json — zapis u ostecene-datoteke.json (traka HITNO, e-mail vlasnici jednom dnevno)');
file_put_contents(SJ_DATA . '/config.json', $cb98); @unlink(SJ_DATA . '/ostecene-datoteke.json'); sj_config(true);
/* v90 (recenzija): dnevna granica po primatelju ostaje 24 h i nakon čišćenja drugih ključeva */
$zq = SJ_DATA . '/zahtjevi.json'; $zb = (string)@file_get_contents($zq); $tn = time();
file_put_contents($zq, json_encode(['sve|dan|abc' => [$tn - 7200, $tn - 5400, $tn - 4000], 'sve|kratko' => [$tn - 7200]]));
sj_rate_limit('visit', 30, 3600, true);
$zd = json_decode((string)file_get_contents($zq), true);
$ok(count($zd['sve|dan|abc'] ?? []) === 3 && !isset($zd['sve|kratko']) && !sj_global_limit('dan|abc', 3, 86400, false), 'v90: ključ „sve|dan|” čuva se 24 h (granica 3 e-maila dnevno ne istječe nakon sat vremena)');
file_put_contents($zq, $zb);
$ok(!sj_is_critical_data('narudzbe-na-cekanju.json') && sj_is_critical_data('narudzbe-arhiva-2026.json'), 'v90: nepotvrđene narudžbe nisu „kritične” (oštećena se odlaže, stranica radi)');
$ok(sj_has_link('prijavite se na secure-pay.shop') && sj_has_link('moj-hpb.SHOP') && sj_has_link('evil．com') && sj_has_link('povrat-novca.de/hr') && !sj_has_link('dr. sc. Ana') && !sj_has_link('ul. Matije Gupca 33') && sj_unlink('idi na secure-pay.shop/x sad') === 'idi na [uklonjeno] sad', 'v91 (recenzija): filtar poveznica prepoznaje bilo koju domenu (.shop, .de, velikim slovima, puna točka)');
$fp91 = array_filter(['Molim brzo.hvala', 'Hvala.lp', 'do 17h.ok', 'npr.crna', '12.kat', 'ILICA 12.KAT', 'SV.ROKA', '140.cm', 'crna/140.cm', '140cm.lp', 'crna.duljina 140', 'dr.sc. Ana', 'LP.IVANA', 'tj.smeđa'], 'sj_has_link');
$ok(!$fp91, 'v91 (recenzija): bez lažnih pogodaka za izostavljeni razmak iza točke (napomene, mjere, imena) ' . json_encode(array_values($fp91), JSON_UNESCAPED_UNICODE));
$ok(sj_hi('Ivana Kovač') === 'Dobar dan, Ivana Kovač,' && sj_hi('Uplatite na secure-pay.shop') === 'Dobar dan,' && sj_hi('Hitno racun blokiran prijavite se') === 'Dobar dan,', 'v91 (recenzija): ime u pozdravu bez točke i najviše 4 riječi');
$cd92 = sj_cancel_due(['expiredAt' => '2026-11-02T10:00:00Z', 'payUntil' => '2026-11-01T10:00:00Z', 'cancelDue' => '2026-11-09T10:00:00Z'], 1);
$ok($cd92 === strtotime('2026-11-09T10:00:00Z'), 'v92 (recenzija): rok automatskog storna zapisan pri isteku ne skraćuje se kad se postavka kasnije smanji');
$cd92b = sj_cancel_due(['expiredAt' => '2026-11-05T23:00:00Z', 'payUntil' => '2026-11-05T22:59:00Z', 'cancelDue' => '2026-11-01T10:00:00Z', 'claimedAt' => '2026-11-04T10:00:00Z'], 48);
$ok($cd92b > strtotime('2026-11-05T23:00:00Z'), 'v92 (recenzija): stari zapisani rok storna nikad ne skraćuje novi (nakon produženja roka)');
$tt93 = sj_terms_text();
$ok(str_contains($tt93, 'uvećan za zakonske zatezne kamate od dana uplate do dana povrata') && str_contains($tt93, 'Kliknite „Uplatio/la sam” u e-mailu o narudžbi ili na stranici s podacima za uplatu: više vam ne šaljemo podsjetnike.'), 'v93 (odluka vlasnice): Uvjeti u e-mailu — zatezne kamate (ZZP čl. 14) i „Uplatio/la sam” bez podsjetnika');
$ok(sj_pow_zero_bits('000fff', 12) && !sj_pow_zero_bits('001fff', 12) && sj_pow_zero_bits('07ff', 5) && !sj_pow_zero_bits('08ff', 5), 'v93: provjera nultih bitova (sj_pow_zero_bits)');
/* v94 (recenzija vijeća, H1): poplava ključeva koje napadač može stvarati ne briše granice po adresi */
$zq = SJ_DATA . '/zahtjevi.json'; $zb94 = (string)@file_get_contents($zq); $tn = time(); $ip94 = sj_short_ip((string)($_SERVER['REMOTE_ADDR'] ?? '?'));
$flood = []; for ($i = 0; $i < 5100; $i++) $flood['sve|mailto-' . $i] = [$tn - 10];
$flood['raskid|' . $ip94] = [$tn - 50, $tn - 40, $tn - 30, $tn - 20, $tn - 10]; $flood['sve|confirm'] = [$tn - 5];
file_put_contents($zq, json_encode($flood));
sj_rate_limit('visit', 30, 3600, true);
$zd94 = json_decode((string)file_get_contents($zq), true);
$ok(count($zd94['raskid|' . $ip94] ?? []) === 5 && isset($zd94['sve|confirm']) && count($zd94) < 5001 && !sj_rate_limit('raskid', 5, 3600, false, false), 'v94 (recenzija vijeća): poplava ključeva po primatelju ne istiskuje granice po adresi ni ukupne brojače');
file_put_contents($zq, $zb94);
/* v94: provjera izračunom više ne piše u bilježnicu ograničenja */
$okW = !str_contains((string)@file_get_contents($zq), 'sve|pow|');
$ok($okW && function_exists('sj_pow_spend'), 'v94: zadatak „niste robot” se troši tek kad je narudžba primljena (zasebna datoteka)');
/* v94 (recenzija vijeća, M1): oštećeni popis torbi se ne prepisuje javnom kopijom */
$pp = sj_products_path(); $pb94 = (string)@file_get_contents($pp);
file_put_contents($pp, $pb94 . '{pokvareno'); $lst = sj_products();
$ok(file_get_contents($pp) === $pb94 . '{pokvareno' && is_file($pp . '.osteceno-' . date('Ymd')) && is_array($lst) && isset(json_decode((string)file_get_contents(SJ_DATA . '/ostecene-datoteke.json'), true)['proizvodi.json']), 'v94 (recenzija vijeća): oštećena proizvodi.json — netaknuta, kopija, zapis za admin; trgovina prikazuje javni popis');
file_put_contents($pp, $pb94); @unlink($pp . '.osteceno-' . date('Ymd')); @unlink(SJ_DATA . '/ostecene-datoteke.json');
$ok(sj_has_link('platite na secure-pay (.) hr') && sj_has_link('povrat tocka com') && sj_has_link('secure-pay.icu') && !sj_has_link('BOX NOW Zagreb 1, Ilica 5'), 'v94: filtar poveznica i za „(.)”, „tocka” i nove vršne domene; paketomat bez lažnih pogodaka');
/* v94 (recenzija): prazna proizvodi.json (prekinut prijenos) obnavlja se iz javnog popisa, a sinkronizacija ne prazni trgovinu */
$pp = sj_products_path(); $pb94b = (string)@file_get_contents($pp); $pub94 = (string)@file_get_contents(SJ_PUBLIC_JSON);
file_put_contents($pp, ''); clearstatcache(); sj_sync_public_products(); clearstatcache();
$ok(filesize($pp) > 2 && count((array)json_decode((string)file_get_contents(SJ_PUBLIC_JSON), true)) === count((array)json_decode($pub94, true)), 'v94 (recenzija): prazna proizvodi.json se obnavlja iz javnog popisa; trgovina ne ostaje prazna');
file_put_contents($pp, $pb94b); file_put_contents(SJ_PUBLIC_JSON, $pub94);
$ok(sj_unlink('Razlog: točka 2 Uvjeta') === 'Razlog: točka 2 Uvjeta' && sj_has_link('pay.ai/login'), 'v94 (recenzija): „točka” u običnom tekstu ostaje; nove vršne domene u filtru');
/* v95 (recenzija vijeća v94, L1): u arhivu idu samo zatvorene narudžbe; otvorena stara narudžba ostaje u glavnom popisu */
$nb95 = (string)@file_get_contents(SJ_DATA . '/narudzbe.json'); $ab95 = SJ_DATA . '/narudzbe-arhiva-' . date('Y') . '.json'; $abk95 = is_file($ab95) ? file_get_contents($ab95) : null;
$l95 = [['no' => 'SJ-OPEN-OLD', 'at' => '2026-01-01T00:00:00Z']]; for ($i = 0; $i < 6; $i++) $l95[] = ['no' => 'SJ-C-' . $i, 'paidAt' => '2026-01-02T00:00:00Z'];
file_put_contents(SJ_DATA . '/narudzbe.json', json_encode(array_reverse($l95))); @unlink($ab95);
sj_orders_prepend(['no' => 'SJ-NEW'], 3);
$m95 = array_column((array)json_decode((string)file_get_contents(SJ_DATA . '/narudzbe.json'), true), 'no'); $a95 = array_column((array)json_decode((string)@file_get_contents($ab95), true), 'no');
$ok(in_array('SJ-OPEN-OLD', $m95, true) && !in_array('SJ-OPEN-OLD', $a95, true) && count($m95) === 4 && count($a95) === 4, 'v95 (L1): otvorena narudžba ostaje u glavnom popisu, zatvorene idu u arhivu ' . json_encode([$m95, count($a95)]));
file_put_contents(SJ_DATA . '/narudzbe.json', $nb95); if ($abk95 === null) @unlink($ab95); else file_put_contents($ab95, $abk95);
/* v95 (L3): prazna proizvodi.json vraća se iz zadnje dnevne kopije (s nevidljivim proizvodima), ne iz javnog popisa */
$pp = sj_products_path(); $pb95 = (string)@file_get_contents($pp); @mkdir(SJ_DATA . '/kopije/2099-01-01', 0755, true);
file_put_contents(SJ_DATA . '/kopije/2099-01-01/proizvodi.json', json_encode([['id' => 'skrivena95', 'name' => 'Skrivena', 'hidden' => true]]));
file_put_contents($pp, ''); clearstatcache(); $l95p = sj_products(); clearstatcache();
$ok(count($l95p) === 1 && ($l95p[0]['id'] ?? '') === 'skrivena95', 'v95 (L3): prazna proizvodi.json obnovljena iz dnevne kopije');
file_put_contents($pp, $pb95); @unlink(SJ_DATA . '/kopije/2099-01-01/proizvodi.json'); @rmdir(SJ_DATA . '/kopije/2099-01-01');
/* v95 (M3): brava koja se ne može otvoriti — jasna greška, ne TypeError */
@mkdir(SJ_DATA . '/brava95.json.lock'); $e95 = '';
try { sj_with_lock(SJ_DATA . '/brava95.json', fn(array &$d) => true, []); } catch (Throwable $e) { $e95 = get_class($e) . ': ' . $e->getMessage(); }
$ok(str_starts_with($e95, 'RuntimeException: brava se ne može otvoriti'), 'v95 (M3): neuspjela brava daje jasnu grešku (' . $e95 . ')');
@rmdir(SJ_DATA . '/brava95.json.lock');
/* v95 (recenzija): dodatni popis izjava čita se iz istog mjeseca u koji se piše; maska ne dira običan tekst; napomene s „1.kat/stan” prolaze */
$ovf95 = sj_raskid_overflow_path(time()); @unlink($ovf95); file_put_contents($ovf95, json_encode(['name' => 'Ov95', 'at' => sj_iso(time())]) . "\n");
$ovr95 = sj_raskid_overflow_read(2, 100); @unlink($ovf95);
$ok($ovr95['n'] === 1 && ($ovr95['list'][0]['name'] ?? '') === 'Ov95', 'v95 (recenzija): dodatni popis izjava vidljiv u adminu (isti mjesec)');
$ok(sj_mask_numbers('hr12  2360  0001 1012 3456 7') === '[IBAN skriven]' && sj_mask_numbers("HR12\u{00A0}2360\u{00A0}0001\u{00A0}1012\u{00A0}3456\u{00A0}7") === '[IBAN skriven]' && sj_mask_numbers('HR12_2360_0001_1012_3456_7') === '[IBAN skriven]' && sj_mask_numbers('091  234  5678') === '[broj skriven]' && sj_mask_numbers('+385 (91) 234-5678') === '[broj skriven]' && sj_mask_numbers('01.23.45.67.89') === '[broj skriven]' && sj_mask_numbers('12. 11. 2026., Luna') === '12. 11. 2026., Luna', 'v98 (recenzija vijeća v97, sigurnost M2): maska — mala slova, neprelomivi razmaci, podvlake, zagrade; datum ostaje');
$ok(sj_mask_numbers('091 2345-6789') === '[broj skriven]' && sj_mask_numbers('0912-345678') === '[broj skriven]' && sj_mask_numbers('09:12 345 678') === '[broj skriven]' && sj_mask_numbers('Nazovite 1. 1. 0912 345 678') === 'Nazovite [broj skriven]' && sj_mask_numbers('HR12 1001 0051-863000 160') === '[IBAN skriven]' && sj_mask_numbers('gb29 nwbk 6016 1331 9268 19') === '[IBAN skriven]' && sj_mask_numbers('Poslano 18. 11. 2026. u 14:30') === 'Poslano 18. 11. 2026. u 14:30' && sj_mask_numbers('2026-0007 i 2026-0008') === '2026-0007 i 2026-0008', 'recenzija v98: zaštićeni datum, vrijeme ili broj narudžbe ne skrivaju telefon ni IBAN');
$ok(!sj_has_link('Tel.br. 091 234') && !sj_has_link('kuć.br. 33') && sj_has_link('x.br/abc'), 'recenzija v98: „br” je poveznica samo s putanjom („Tel.br.”, „kuć.br. 33” prolaze)');
$ok(sj_has_link('evil .hr') && sj_has_link('a.co/x') && sj_has_link('nesto.games') && !sj_has_link('torbu br 12') && !sj_has_link('Lijepa torba. Hr kraj'), 'v98 (sigurnost 7): filtar poveznica — razmak prije .hr, jednoslovne .co, nove domene');
$ok(sj_mask_numbers('NA 14 DANA ZBOG BOLESTI') === 'NA 14 DANA ZBOG BOLESTI' && sj_mask_numbers('Poslano 18. 11. 2026. 14:30') === 'Poslano 18. 11. 2026. 14:30' && sj_mask_numbers('2026-0007 2026-0008, SJ-2026-0009') === '2026-0007 2026-0008, SJ-2026-0009' && sj_mask_numbers('tel 091 234 5678') === 'tel [broj skriven]' && sj_mask_numbers('HR12  1001 0051 8630 0016 0') === '[IBAN skriven]', 'v96 (recenzija): maska ne skriva velika slova, datum ni brojeve narudžbi; telefon i IBAN skriva');
$ok(sj_mask_numbers('Raskidam u roku od 14 dana od primitka.') === 'Raskidam u roku od 14 dana od primitka.' && sj_mask_numbers('torbu br 12 jer mi ne odgovara') === 'torbu br 12 jer mi ne odgovara' && sj_mask_numbers('IBAN HR12 1001 0051 8630 0016 0') === 'IBAN [IBAN skriven]', 'v95 (recenzija): maska skriva samo pravi IBAN');
$ok(!sj_has_link('Dostava nakon 17h, 1.kat/stan 4') && !sj_has_link('ulaz B, 2.kat/desno') && !sj_has_link('Hvala.do viđenja') && !sj_has_link('boja.red') && sj_has_link('bit.do/x') && sj_has_link('t.co/x'), 'v95 (recenzija): obične napomene prolaze, kratke poveznice s putanjom ne');
/* v99 (recenzija vijeća v98, B1/B3): maska preko svih razdjelnika i znamenki pune širine; filtar poveznica i lažno pozitivni */
$m99 = ['0912;345;678', '091|234|5678', '091*234*5678', '091–234–5678', "091\n234\n5678", '０９１２３４５６７８', "091\u{2009}234\u{2009}5678", '091,234,5678', '091~234~5678'];
$ok(count(array_filter($m99, fn($t) => sj_mask_numbers($t) === '[broj skriven]')) === count($m99) && sj_mask_numbers('Poslano 18. 11. 2026. 14:30') === 'Poslano 18. 11. 2026. 14:30' && sj_mask_numbers('SJ-2026-0009, 2026-0010') === 'SJ-2026-0009, 2026-0010' && sj_mask_numbers('NA 14 DANA') === 'NA 14 DANA',
  'v99 (B1): maska broja preko ; | * – ~ zareza, novog retka i uskog razmaka, i znamenke pune širine; datumi i brojevi narudžbi ostaju');
$l99 = ['prevara.pw', 'nesto.ovh', 'moja.art', 'zlo.blog', 'evil.Ru', 'ime.Eu', 'prevara . com', 'plati na prevara .eu hvala', 'crna.id', 'bit.do/x'];
$f99 = ['npr. crna', 't. 7', 'cca. 5', 'Hvala.do viđenja', 'boja.red', 'Tel.br.', 'kuć.br. 33', 'Pozdrav.Eu sam', 'Ulaz.se nalazi lijevo', 'Hvala.lp', 'točka 2. Uvjeta', 'Tel: 091 234 5678, zvono ne radi', 'tel:0912345678', 'npr.crna/smeđa', 'Ulaz.desno/lijevo', 'str.lijeva/desna', 'Zvono.ne/radi', 'hvala . to je sve', 'nema . me doma'];
$ok(count(array_filter($l99, 'sj_has_link')) === count($l99) && !array_filter($f99, 'sj_has_link'),
  'v99 (B3): filtar poveznica — pw/ovh/art/blog/id, miješana slova na kraju, razmak oko točke; obične napomene (i telefon, „npr.crna/smeđa”, „hvala . to je sve”) prolaze ' . json_encode([array_values(array_filter($l99, fn($t) => !sj_has_link($t))), array_values(array_filter($f99, 'sj_has_link'))], JSON_UNESCAPED_UNICODE));
$ok(sj_mask_numbers('Ilica 123/4, 10000 Zagreb') === 'Ilica 123/4, 10000 Zagreb' && sj_mask_numbers('091,234,5678') === '[broj skriven]', 'recenzija v99: „zarez i razmak” dijeli adresu, telefon sa zarezima bez razmaka i dalje se skriva');
/* recenzija v99: izjava samo u dnevniku (obrada prekinuta nakon odgovora) — upisuje se u glavni popis i vlasnica dobiva e-mail; jednom */
$jr99 = sj_raskid_journal_path(time()); $jb99 = (string)@file_get_contents($jr99); $rb99 = (string)@file_get_contents(SJ_DATA . '/raskidi.json'); $ml0 = strlen((string)@file_get_contents(SJ_DATA . '/mail-log.txt'));
file_put_contents($jr99, json_encode(['name' => 'Iz Dnevnika', 'no' => '2026-0042', 'email' => 'dn@primjer.hr', 'id' => 'dnevnik99a', 'at' => sj_iso(time() - 1800)]) . "\n" . json_encode(['name' => 'Svjeza', 'no' => '2026-0043', 'email' => 'sv@primjer.hr', 'id' => 'dnevnik99b', 'at' => sj_iso(time() - 60)]) . "\n", FILE_APPEND);
$rc1 = sj_raskid_reconcile(true); $rc2 = sj_raskid_reconcile(true);
$rl99 = array_values(array_filter((array)json_decode((string)file_get_contents(SJ_DATA . '/raskidi.json'), true), fn($x) => ($x['id'] ?? '') === 'dnevnik99a'));
$mlR = substr((string)@file_get_contents(SJ_DATA . '/mail-log.txt'), $ml0);
$ok($rc1 === ['dnevnik99a'] && $rc2 === [] && count($rl99) === 1 && !empty($rl99[0]['recovered']) && substr_count($mlR, 'izjava o raskidu nije do kraja obrađena') === 1 && str_contains($mlR, 'Iz Dnevnika'),
  'recenzija v99: izjava samo u dnevniku (starija od 15 min) upisuje se u glavni popis („obnovljeno”), vlasnica dobiva jedan e-mail; svježa se ne dira ' . json_encode([$rc1, $rc2]));
file_put_contents($jr99, $jb99); file_put_contents(SJ_DATA . '/raskidi.json', $rb99 === '' ? '[]' : $rb99); @unlink(SJ_DATA . '/raskidi-provjera.json');
/* v100 (recenzija vijeća v99, softver N2): dnevnik i dodatni popis od po ~20 MB — provjera čita redak po redak i ostaje u malo memorije */
$jr100 = sj_raskid_journal_path(time()); $of100 = sj_raskid_overflow_path(time()); $jb100 = (string)@file_get_contents($jr100); $ob100 = is_file($of100) ? (string)file_get_contents($of100) : null;
$fj = fopen($jr100, 'w'); $fo = fopen($of100, 'w'); $old = sj_iso(time() - 3600); $pad = str_repeat('x', 200);
for ($i = 0; $i < 62000; $i++) { $ln = json_encode(['name' => 'Kupac ' . $i, 'no' => 'opis ' . $pad, 'email' => 'k' . $i . '@primjer.hr', 'id' => sprintf('%032x', $i + 1), 'at' => $old], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; fwrite($fj, $ln); fwrite($fo, $ln); }
fclose($fj); fclose($fo); @unlink(SJ_DATA . '/raskidi-provjera.json');
$m0 = memory_get_usage(); if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
$rcBig = sj_raskid_reconcile(true); $peak = (memory_get_peak_usage() - $m0) / 1048576;
$stBig = json_decode((string)file_get_contents(SJ_DATA . '/raskidi-provjera.json'), true);
$ok($rcBig === [] && $peak < 40 && (int)($stBig['off'][basename($jr100)] ?? 0) === filesize($jr100), 'v100: provjera dnevnika od ' . round(filesize($jr100) / 1048576) . ' MB — ' . round($peak, 1) . ' MB memorije, mjesto u dnevniku zapamćeno');
file_put_contents($jr100, $jb100); if ($ob100 === null) @unlink($of100); else file_put_contents($of100, $ob100); @unlink(SJ_DATA . '/raskidi-provjera.json');
/* recenzija v100: 25 izjava samo u dnevniku + jedna premlada — prvi prolaz obnovi 20 i stane prije 21., drugi obnovi ostalih 5,
   premlada ostaje; e-mail koji ne prođe ponavlja se */
$jr = sj_raskid_journal_path(time()); $of = sj_raskid_overflow_path(time()); $jb = (string)@file_get_contents($jr); $ob = is_file($of) ? (string)file_get_contents($of) : null;
$rb = (string)@file_get_contents(SJ_DATA . '/raskidi.json'); @unlink($of); @unlink(SJ_DATA . '/raskidi-provjera.json');
$fj = fopen($jr, 'w'); for ($i = 0; $i < 25; $i++) fwrite($fj, json_encode(['name' => 'Dn ' . $i, 'no' => '2026-0' . (100 + $i), 'email' => 'd' . $i . '@primjer.hr', 'id' => sprintf('%032x', 9000 + $i), 'at' => sj_iso(time() - 3600)]) . "\n");
fwrite($fj, json_encode(['name' => 'Mlada', 'no' => '2026-0999', 'email' => 'm@primjer.hr', 'id' => sprintf('%032x', 99999), 'at' => sj_iso(time() - 30)]) . "\n"); fclose($fj);
$c0 = file_get_contents(SJ_DATA . '/config.json'); $cc = json_decode($c0, true); $cc['mail_mode'] = 'smtp'; $cc['smtp_host'] = '127.0.0.1'; $cc['smtp_port'] = 1; $cc['smtp_user'] = 'x'; $cc['smtp_pass'] = 'x';
file_put_contents(SJ_DATA . '/config.json', json_encode($cc)); sj_config(true);
$p1 = sj_raskid_reconcile(true); $st1 = json_decode((string)file_get_contents(SJ_DATA . '/raskidi-provjera.json'), true);
file_put_contents(SJ_DATA . '/config.json', $c0); sj_config(true); $mlA = strlen((string)@file_get_contents(SJ_DATA . '/mail-log.txt'));
$p2 = sj_raskid_reconcile(true); $st2 = json_decode((string)file_get_contents(SJ_DATA . '/raskidi-provjera.json'), true);
$mlR2 = substr((string)@file_get_contents(SJ_DATA . '/mail-log.txt'), $mlA);
$ids2 = array_column((array)json_decode((string)file_get_contents(SJ_DATA . '/raskidi.json'), true), 'id');
$ok(count($p1) === 20 && count((array)($st1['notify'] ?? [])) === 20 && count($p2) === 5 && (array)($st2['notify'] ?? []) === [] && substr_count($mlR2, 'nije do kraja obrađena') === 1 && str_contains($mlR2, 'Dn 0') && str_contains($mlR2, 'Dn 24')
  && !in_array(sprintf('%032x', 99999), $ids2, true) && (int)$st2['off'][basename($jr)] < filesize($jr),
  'recenzija v100: provjera dnevnika — 20 po prolazu, nastavak od zapamćenog mjesta, premlada izjava čeka, neuspio e-mail vlasnici ponovi se ' . json_encode([count($p1), count($p2), count((array)($st1['notify'] ?? []))]));
file_put_contents($jr, $jb); if ($ob === null) @unlink($of); else file_put_contents($of, $ob); file_put_contents(SJ_DATA . '/raskidi.json', $rb === '' ? '[]' : $rb); @unlink(SJ_DATA . '/raskidi-provjera.json');
$ok(sj_mask_numbers('Nazovite 98/12, 34567 Ivana') === 'Nazovite [broj skriven] Ivana' && sj_mask_numbers('Mob 98/12, 34567 Ivana') === 'Mob [broj skriven] Ivana' && sj_mask_numbers('Zvonimirova 12a, 49210 Zabok') === 'Zvonimirova 12a, 49210 Zabok',
  'recenzija v100: adresa se štiti samo uz naziv ulice, ne uz „Nazovite” ili „Mob”');
/* v101 (recenzija vijeća v100, sigurnost): nastavci-riječi su poveznica samo s putanjom; sj_unlink ne briše običan tekst, nego razdvaja „riječ.riječ” */
$f101 = ['Pozvonite Ana-Marija.to je sve', 'Ivana-Kos.at home', 'Prekrasne torbe, sve pohvale. Top', 'Super. top', 'Zovem se Ana. Info', 'Hvala.Me', 'super.in', 'Remen crna.it', 'Hvala.to je sve', 'Konzum Ilica 1, uplata na shivaj-pay.me prije slanja'];
$l101 = ['shivaj-pay.me/racun', 'naplata.онлайн', 'plati.москва', 'naplata.рф', 'naplata｡com', 'naplata. pw', 'naplata .pw', 't.me/abc'];
$ok(!array_filter($f101, 'sj_has_link') && count(array_filter($l101, 'sj_has_link')) === count($l101),
  'v101: filtar — imena s crticom, „pohvale. Top”, „Hvala.Me” prolaze; putanja, ćirilica i točka U+FF61 se hvataju ' . json_encode([array_values(array_filter($f101, 'sj_has_link')), array_values(array_filter($l101, fn($t) => !sj_has_link($t)))], JSON_UNESCAPED_UNICODE));
$u101 = ['naplata.ba danas' => 'naplata. ba danas', 'Hvala.to je sve' => 'Hvala. to je sve', 'Konzum Sv.Nedelja' => 'Konzum Sv. Nedelja', 'Ul.kralja Zvonimira 5' => 'Ul. kralja Zvonimira 5', 'shivaj-pay.me prije slanja' => 'shivaj-pay. me prije slanja',
  'cijena 5.50 eura' => 'cijena 5.50 eura', '3.kat/lijevo' => '3. kat/lijevo' /* v102: i jednoslovna oznaka pred kosom crtom dobiva razmak */, 'Naplata.Com odmah' => '[uklonjeno] odmah', 'idi na http://zlo.example sad' => 'idi na [uklonjeno] sad', 'Bit.Ly/x' => '[uklonjeno]', 'pisi na a@b.hr' => 'pisi na [uklonjeno]', 'molim naplata.рф/x hvala' => 'molim [uklonjeno] hvala'];
$bad101 = []; foreach ($u101 as $in => $want) if (sj_unlink($in) !== $want) $bad101[$in] = sj_unlink($in);
$ok(!$bad101, 'v101: sj_unlink uklanja samo prave poveznice, a „riječ.riječ” razdvaja razmakom (ništa klikabilno, ništa izbrisano) ' . json_encode($bad101, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$ok(sj_mask_numbers('Hrvatskih branitelja 123/4, 10000 Zagreb') === 'Hrvatskih branitelja 123/4, 10000 Zagreb' && sj_mask_numbers('Trg Ivana Zovka 12/3, 10000 Zagreb') === 'Trg Ivana Zovka 12/3, 10000 Zagreb'
  && sj_mask_numbers('Tel. 98/12, 34567 Ivana') === 'Tel. [broj skriven] Ivana' && sj_mask_numbers('091ʼ234ʼ5678') === '[broj skriven]',
  'v101: maska — „branitelja” i „Zovka” nisu „tel” ni „zov”; „Tel.” ispred i dalje skriva; slovo-modifikator kao razdjelnik');
/* v101 (recenzija vijeća v100, softver F): stari dnevnik izjava briše se tek kad je provjera prošla do kraja (25 izjava: prvi dan 20, dnevnik ostaje; drugi dan 5, briše se) */
$oj = SJ_DATA . '/raskidi-ulaz-2020-01.jsonl'; $rb101 = (string)@file_get_contents(SJ_DATA . '/raskidi.json'); @unlink(SJ_DATA . '/raskidi-provjera.json');
$fj = fopen($oj, 'w'); for ($i = 0; $i < 25; $i++) fwrite($fj, json_encode(['name' => 'Stari ' . $i, 'no' => '2020-0' . (100 + $i), 'email' => 's' . $i . '@primjer.hr', 'id' => sprintf('%032x', 77000 + $i), 'at' => '2020-01-15T10:00:00Z']) . "\n"); fclose($fj);
$td = SJ_DATA . '/kopije/' . date('Y-m-d'); @unlink($td . '/.gotovo'); sj_daily_backup();
$n1 = count(array_filter((array)json_decode((string)file_get_contents(SJ_DATA . '/raskidi.json'), true), fn($x) => str_starts_with((string)($x['name'] ?? ''), 'Stari '))); $left1 = is_file($oj);
@unlink($td . '/.gotovo'); sj_daily_backup();
$n2 = count(array_filter((array)json_decode((string)file_get_contents(SJ_DATA . '/raskidi.json'), true), fn($x) => str_starts_with((string)($x['name'] ?? ''), 'Stari '))); $left2 = is_file($oj);
$ok($n1 === 20 && $left1 && $n2 === 25 && !$left2, 'v101 (softver F): stari dnevnik izjava ostaje dok provjera ne obnovi sve (20 po prolazu), tek tada se briše ' . json_encode([$n1, $left1, $n2, $left2]));
/* recenzija v101: dnevnik koji provjera NIJE pročitala (dodatni popis istog mjeseca je prevelik, pa se preskače) ne smije se obrisati */
@unlink($oj); @unlink(SJ_DATA . '/raskidi-provjera.json'); file_put_contents($oj, json_encode(['name' => 'Preskočen', 'no' => '2020-0900', 'email' => 'p@primjer.hr', 'id' => sprintf('%032x', 78000), 'at' => '2020-01-15T10:00:00Z']) . "\n");
$ovOld = SJ_DATA . '/raskidi-visak-2020-01.jsonl'; $fo = fopen($ovOld, 'w'); ftruncate($fo, SJ_RASKID_OVERFLOW_MAX + 200000); fclose($fo);
@unlink($td . '/.gotovo'); sj_daily_backup(); clearstatcache(); $leftSkip = is_file($oj);
@unlink($ovOld); @unlink($td . '/.gotovo'); @unlink(SJ_DATA . '/raskidi-provjera.json'); sj_daily_backup(); clearstatcache(); $leftRead = is_file($oj);
$ok($leftSkip && !$leftRead, 'recenzija v101: stari dnevnik koji provjera nije mogla pročitati ostaje; briše se tek kad je pročitan do kraja ' . json_encode([$leftSkip, $leftRead]));
/* recenzija v101: maska — „broj”, „kontakt”, „mobilni” ispred brojeva nisu adresa; iza kose crte kućnog broja najviše dvije znamenke */
$ok(sj_mask_numbers('broj Ivana 385/912, 34567 Zadar') === 'broj Ivana [broj skriven] Zadar' && sj_mask_numbers('Uplatite Ivanu 385/912, 34567 hitno') === 'Uplatite Ivanu [broj skriven] hitno'
  && sj_mask_numbers('mobilni 98/12, 34567 Ivana') === 'mobilni [broj skriven] Ivana' && sj_mask_numbers('kontakt 98/12, 34567 Ivana') === 'kontakt [broj skriven] Ivana'
  && sj_mask_numbers('Kontakt adresa: Ilica 123/4, 10000 Zagreb') === 'Kontakt adresa: Ilica 123/4, 10000 Zagreb' && sj_mask_numbers('Ilica 123/45, 10000 Zagreb') === 'Ilica 123/45, 10000 Zagreb',
  'recenzija v101: maska — „broj/kontakt/mobilni” ispred brojeva skriva; „Kontakt adresa: …” i kućni broj „123/45” ostaju');
/* v102 (recenzija vijeća v101, sigurnost N2/N5): filtar — kombinirajući i nevidljivi znakovi ispred točke, jednoslovna oznaka, točke-dvojnici, druge sheme;
   riječi mom/plus/one/top/pro nisu poveznica u rečenici, jesu uz crticu, znamenku ili putanju */
$v102pos = ["uplata\u{0301}.com", "racun\u{FE0F}.hr", "naplata\u{034F}.com", 't.ly', 'shivaj-uplata.a.bg', "uplata\u{2024}com", "uplata\u{A4F8}com", 'tg://resolve?domain=x', 'viber://pa?chatURI=x', 'skype:shivaj-uplata', 'secure-pay.top', 'pay2.one', 'prevara.top/x', 'naplata.plus/racun'];
$v102neg = ['Poklon za mamu.mom bratu', 'Molim crnu.plus zlatni detalj', 'Super su vam torbe.one crne', 'Hvala.Top ste', 'hvala.pro račun molim', 'BOX NOW Konzum, Ul.hr.branitelja 12', 'viber: 091 234 567', 'viber:0912345678', 'Konzum: Ilica 1', 'npr. crna', 'd.o.o. Zabok', 'tel:091'];
$miss = array_values(array_filter($v102pos, fn($x) => !sj_has_link($x))); $false = array_values(array_filter($v102neg, fn($x) => sj_has_link($x)));
$ok(!$miss && !$false, 'v102 (sigurnost N2/N5): filtar hvata prikrivene domene i sheme, a ne odbija hrvatske rečenice ' . json_encode([$miss, $false], JSON_UNESCAPED_UNICODE));
$ok(sj_unlink('Sv.Duh/Novaki 4') === 'Sv. Duh/Novaki 4' && !str_contains(sj_unlink('Vukovarska 269, 12.kat/stan 45'), 'uklonjeno') && sj_unlink('Poklon za mamu.mom bratu') === 'Poklon za mamu. mom bratu'
  && sj_unlink('pisi na tg://resolve?domain=x odmah') === 'pisi na [uklonjeno] odmah' && sj_unlink("idi na uplata\u{0301}.com sad") === 'idi na [uklonjeno] sad' && sj_unlink('skype:shivaj-uplata') === '[uklonjeno]',
  'v102: sj_unlink — adrese s kosom crtom i riječi-nastavci dobivaju razmak, sheme i prikrivene domene se uklanjaju');
/* v102 (sigurnost N3/N5): maska — raspon datuma i mjere ostaju, „brojevi” koji nisu znamenke se skrivaju */
$ok(sj_mask_numbers('dostava 20.11.-25.11.2026. ako može') === 'dostava 20.11.-25.11.2026. ako može' && sj_mask_numbers('mjere 22 × 14 × 6, 28 × 22 × 8 cm') === 'mjere 22 × 14 × 6, 28 × 22 × 8 cm'
  && sj_mask_numbers('zovi ⓪⑨① ②③④ ⑤⑥⑦⑧') === 'zovi [broj skriven]' && sj_mask_numbers('zovi ⁰⁹¹ ²³⁴ ⁵⁶⁷⁸') === 'zovi [broj skriven]' && sj_mask_numbers('površina 12 m²') === 'površina 12 m²'
  && sj_mask_numbers('091 234 5678 × 2') !== '091 234 5678 × 2' && sj_mask_numbers('rok 20.11.-0912345678') !== 'rok 20.11.-0912345678',
  'v102: maska — raspon datuma i mjere torbe ostaju; ⓪⑨①… i ⁰⁹¹… se skrivaju; telefon uz „×” ili datum i dalje se skriva');
/* provjera v102 (krug 1, sigurnost N1–N5): kratke poveznice s jednoslovnom oznakom; nevidljivi znak iza točke ili u nastavku; „Viber:” kao riječ;
   paketomat s karte i raspon kućnih brojeva; obične napomene s vremenom i datumom; raspon „datuma” kao skrovište broja */
$ok(sj_has_link('Novi podaci: v.gd/abc123') && sj_has_link('j.mp/abc') && sj_has_link('rb.gy/x1') && sj_unlink('Novi podaci: v.gd/abc123') === 'Novi podaci: [uklonjeno]' && sj_unlink('v.xyzq/abc') === 'v. xyzq/abc'
  && sj_has_link("naplata.c\u{034F}om") && sj_has_link("racun.\u{FE0F}hr") && sj_unlink("Uplata na naplata.c\u{034F}om odmah") === 'Uplata na [uklonjeno] odmah'
  && !sj_has_link('Viber:isti broj kao gore') && !sj_has_link('Kontakt viber:isti broj kao gore') && sj_has_link('skype:shivaj') && sj_has_link('viber://pa?chatURI=x') && !sj_has_link('Zvono.ne/radi') && !sj_has_link('12.kat/stan 45'),
  'provjera v102 (sigurnost N1, N5): „v.gd/x”, „j.mp/x” i nevidljivi znak iza točke su poveznice; „Viber:isti broj”, „Zvono.ne/radi” nisu');
$m102 = ['BOX NOW Savska — Savska cesta 106/1, 10000 (ID 4051)', 'BOX NOW Ilica — Ilica 12-14, 10000 (ID 4051)', 'Tisak, Ilica 12-14, 10000 Zagreb', 'Doma sam 8:00-16:00, 20.11.-25.11.2026.', 'Dostava između 8:00 i 16:00, 20.11.2026.', 'od 8-16 h, 20.11.2026.', '25.11. u 9:00 ili 26.11. u 14:00', '20.11.2026. — 5 kom', 'u 14:30 5 kom']; /* krug 3: popis smjena „6-14, 14-22, 22-6” opet se skriva (kao v101) — tri raspona sati mogu nositi broj telefona */
$bad102 = array_values(array_filter($m102, fn($x) => sj_mask_numbers($x) !== $x));
$h102 = ['09-8 12-3 14-5', '09-1   23-4   5-6   7-8', '09:12-3:45, 6.7.-8.9.', '09-8 12:34-5:56', 'Radim u smjenama: 6-14, 14-22, 22-6', 'Novi broj (bez točaka): 09.12.3456.-7.8.', '3.8.5912.-3.4.5678', '09:12 345 678', '091 2345-6789', '09:12, 345 678', '09-12, 3-4, 5-6, 7-8', '09.12., 3.4., 5.6., 7.8.', 'Nazovi 091 234 5678 u 14:30', 'Tel. 98/12, 34567'];
$vis102 = array_values(array_filter($h102, fn($x) => !str_contains(sj_mask_numbers($x), '[broj skriven]')));
$ok(!$bad102 && !$vis102 && sj_mask_numbers('Nazovi 091 234 5678 u 14:30') === 'Nazovi [broj skriven] u 14:30', 'provjera v102 (sigurnost N2–N4): paketomat s karte, raspon kućnih brojeva i obične napomene s vremenom ostaju; brojevi skriveni u „datumima” se skrivaju ' . json_encode([$bad102, $vis102], JSON_UNESCAPED_UNICODE));
/* provjera v102 (krug 2, sigurnost): tri „vremena” i silazni „raspon kućnih brojeva” ne prenose broj; nevidljiva slova (U+3164 …) ne skrivaju telefon, IBAN ni domenu */
$h2 = ['+38:59 12:34 56:78', '09:12 3:45 6:07', 'Hitno: Signal 385-91/23, 45678', "091\u{3164}234\u{3164}5678", "091\u{115F}234\u{FFA0}5678", "091\u{2800}234\u{2800}5678"];
$v2 = array_values(array_filter($h2, fn($x) => !str_contains(sj_mask_numbers($x), '[broj skriven]')));
$k2 = ['8:00, 12:00', 'u 9:30 ili 14:45', 'Tisak, Ilica 12-14, 10000 Zagreb', 'BOX NOW Savska — Savska cesta 106/1, 10000 (ID 4051)', 'Ilica 123/4, 10000 Zagreb'];
$b2 = array_values(array_filter($k2, fn($x) => sj_mask_numbers($x) !== $x));
$ok(!$v2 && !$b2 && sj_mask_numbers("HR98\u{3164}2360\u{3164}0001\u{3164}1027\u{3164}9044\u{3164}2") === '[IBAN skriven]'
  && sj_clean("091\u{3164}234\u{3164}5678", 60) === '0912345678' && sj_clean("naplata.c\u{17B4}om", 60) === 'naplata.com' && sj_has_link("racun.\u{180F}hr") && sj_has_link("naplata.c\u{17B5}om"),
  'provjera v102 (krug 2, sigurnost N1/N2/P1): „vremena” i silazni raspon ne prenose broj; nevidljiva slova se brišu pri unosu i u maski ' . json_encode([$v2, $b2], JSON_UNESCAPED_UNICODE));
/* provjera v102 (krug 2, softver N3): točka na kraju rečenice ne kvari „sate od–do”; širi razmak ne skriva broj iza zaštićenog dijela; datum s količinom ostaje */
$ok(sj_mask_numbers('od 6-14 h.') === 'od 6-14 h.' && sj_mask_numbers('20.11.2026. — 5 kom') === '20.11.2026. — 5 kom'
  && str_contains(sj_mask_numbers('09:12 345   678'), '[broj skriven]') && str_contains(sj_mask_numbers('09:12 345 / 678'), '[broj skriven]') && str_contains(sj_mask_numbers('nazovi 0:91 23:45 6:78'), '[broj skriven]')
  && str_contains(sj_mask_numbers('Ulica 385-91/23, 45678 Zagreb'), '[broj skriven]') && str_contains(sj_mask_numbers('Ulica 91-23, 45678 Zagreb'), '[broj skriven]') /* silazni raspon bez kose crte */ && sj_mask_numbers('HR 12:10 01:00 51:86   30:00 1:60') === '[IBAN skriven]',
  'provjera v102 (krug 2, softver N3): maska — rečenica s točkom i datum s količinom ostaju; broj iza zaštićenog dijela skriva se i uz širi razmak');
/* provjera v102 (krug 4, sigurnost N1–N3, S1): kućni brojevi uz poštanski broj ostaju; telefon u obliku broja narudžbe se skriva; široki razmaci postaju jedan */
$adrOk = [['Ulica grada Vukovara 222/104', '10000 Zagreb'], ['Vukovarska 230 - 18', '10000 Zagreb'], ['Ilica 26 / 32', '10000 Zagreb'], ['Vukovarska 265-2', '10000 Zagreb'], ['Ilica 9', '10000 Zagreb']];
$adrBad = array_values(array_filter($adrOk, fn($x) => sj_echo_street_city($x[0], $x[1]) !== $x[0] . ', ' . $x[1]));
$telV = array_values(array_filter(['nazovite +44 2071-234567', 'WhatsApp +1 2025-550123', 'mob +36 2012-34567', 'Tel: (0)2012-3456', '09 2012-3456'], fn($x) => !str_contains(sj_echo_text($x), '[broj skriven]')));
$ok(!$adrBad && !$telV && str_contains(sj_echo_street_city('Za uplatu nazovite 09123', '45678 odmah, Zagreb'), '[broj skriven]') && sj_echo_text('Narudžba 2026-0001, 2 kom') === 'Narudžba 2026-0001, 2 kom'
  && sj_echo_text("hvala!" . str_repeat("\u{3000}", 70) . "·\u{2003}\u{2003}VAŽNA OBAVIJEST") === 'hvala! · VAŽNA OBAVIJEST' && sj_echo_addr('Ilica 5, 5.9.8.7/uplata') === 'Ilica 5, [uklonjeno]',
  'provjera v102 (krug 4, sigurnost): kućni brojevi ostaju; telefon kao „broj narudžbe” skriven; široki razmaci jedan razmak; IP s putanjom uklonjen ' . json_encode([$adrBad, $telV], JSON_UNESCAPED_UNICODE));
/* v104 (recenzija v103, sigurnost N2/N3): Revolut oznaka i telefon razlomljen riječima ili poštanskim brojem u kopiji za kupca; obične napomene ostaju */
$hv = ['uplatu pošaljite preko Revoluta na @anahorvat99', 'Revolut: anahorvat99', 'revtag anahorvat', 'revolut me/anahorvat99', 'PayPal ana.horvat99', 'Pozivni 091, broj 2345678', 'Pozovi 091 234 i nastavak 5678'];
$hvV = array_values(array_filter($hv, fn($x) => !preg_match('~\[(oznaka|broj) skriven[a]?\]~u', sj_echo_text($x))));
$hk = ['preko Revoluta ili kartice', 'Doma sam od 08 do 16 h, kat 3 stan 12', 'Dostava 03.12. ili 04.12. poslije 16', '01.12.2026. i 05.12.2026.', 'Narudžba 2026-0001, 2 kom', 'Hvala, 091 nema veze 12 komada', 'Ulaz 0 kat 2 stan 12', 'Treba mi do 20.11.2026., 2 komada'];
$hkB = array_values(array_filter($hk, fn($x) => sj_echo_text($x) !== $x));
$ok(!$hvV && !$hkB && sj_echo_street_city('Ilica 12, tel 091', '10000 Zagreb 2345678') === 'Ilica 12, tel 091, 10000 Zagreb [broj skriven]' && sj_echo_street_city('Trg 101. brigade 5', '10000 Zagreb, kat 12') === 'Trg 101. brigade 5, 10000 Zagreb, kat 12'
  && sj_echo_street_city('Vukovarska 269a', '10000 Zagreb - Novi Zagreb') === 'Vukovarska 269a, 10000 Zagreb - Novi Zagreb' && sj_quote_block("prvi „red”\n\ndrugi") === 'prvi "red" / drugi',
  'v104 (recenzija v103, sigurnost N2/N3): Revolut oznaka i razlomljen telefon skriveni u kopiji za kupca; obične napomene i adrese ostaju ' . json_encode([$hvV, $hkB], JSON_UNESCAPED_UNICODE));
/* krug provjere v104 (sigurnost 1/5, B7): razdjelnici, obrnut redoslijed, varijante oznake; vremena i datumi s nulom ostaju */
$kv = ['Pozivni 091. Broj 2345678 — javite se za promjenu računa', 'Pozivni 091 — ostatak 2345678', 'Ulica: pozivni 091 (mob) 2345678', 'broj 2345678, pozivni 091', 'BOX NOW Zabok. Zovite 091. Broj 2345678',
  'Revolut - anak', 'Revolut – anak', 'Revolut → anak', 'Revolut (anak)', 'Revolut tag: anak', '＠anak', 'Pozivni 091, kat 2345678'];
$kvV = array_values(array_filter($kv, fn($x) => !preg_match('~\[(oznaka|broj) skriven[a]?\]~u', sj_echo_text($x))));
$kk = ['Između 08:00 i 09:00 ili 17:00-19:00.', 'Mogu 08:00, 09:00 ili 10:00', 'Datum 05/12/2026, rok 09/12/2026', 'Dolazim 01/12, 02/12 ili 03/12',
  /* drugi krug provjere v104 (N4) */ 'PayPal (ako može)', 'Imam Revolut - hoće li biti problem', 'Revolut (instant)', 'Revolut → banka', 'Revolut tag nemam', 'PayPal - ali radije uplatnicom', 'Revolut — sve 5!', 'Ulaz 05 — kod 2468 — kat 3 — stan 12', 'Kat 01 (prizemlje) — stan 234, zgrada 5678'];
$kkB = array_values(array_filter($kk, fn($x) => sj_echo_text($x) !== $x));
$ok(!$kvV && !$kkB, 'krug provjere v104: telefon razlomljen točkom, crticom, zagradama i obrnutim redom te varijante Revolut oznake skriveni; vremena i datumi s nulom ostaju ' . json_encode([$kvV, $kkB], JSON_UNESCAPED_UNICODE));
/* v110 (recenzija v109, sigurnost N1): oznake plaćanja u padežima, gola oznaka, razdjelnici; obične rečenice o plaćanju ostaju */
$pv = ['Revolutom na ivanhorvat99', 'Revolutu ivanhorvat', 'PayPalom: ivanhorvat', 'PayPalu ivanhorvat', 'Keks Payem na ivanhorvat', 'Revolut ivanhorvat', 'Uplatite na Revolut ivanhorvat, hvala',
  'Revolut @ ivanhorvat', 'Revolut=ivanhorvat', 'Revolut/ivanhorvat', 'Revolut…ivanhorvat', 'Revolut račun ivanhorvat', 'PayPal ivan.horvat99'];
$pvV = array_values(array_filter($pv, fn($x) => !str_contains(sj_echo_text($x), '[oznaka skrivena]') || preg_match('~ivan|horvat~u', sj_echo_text($x))));
$pk = ['preko Revoluta ili kartice', 'Platit ću Revolutom sutra', 'Revolutom plaćam odmah', 'Može i Revolutom?', 'Imam Revolut, može?', 'Platit ću Revolutom na vrijeme', 'Ne koristim Revolut.', 'Plaćam PayPalom ili karticom', 'Revolut je ok', 'Može PayPal', 'Uplata preko Revoluta, hvala'];
$pkB = array_values(array_filter($pk, fn($x) => sj_echo_text($x) !== $x));
$ok(!$pvV && !$pkB && !str_contains(sj_echo_addr('Zabok Konzum, uplatite Revolutom na ivanhorvat'), 'ivanhorvat'),
  'v110 (recenzija v109, sigurnost N1): oznake plaćanja u padežima i bez dvotočke skrivene (i u paketomatu); obične rečenice ostaju ' . json_encode([$pvV, $pkB], JSON_UNESCAPED_UNICODE));
/* recenzija v102 (sigurnost N1–N5): maska ne kvari obične napomene; dvojnici crtice i miješane „znamenke” ne prenose broj; adresa se u e-mailu ne briše */
$kR = ['Treba mi do 20.11.2026., 2 komada', 'Doma sam 8:00-16:00, 12. kat', 'Dostava na Ilica 5, 20.11.2026.', 'Termin: 23.11., 24.11., 25.11., 26.11. poslije 17 h',
  'Narudžba 2026-0001, 2 kom', '20.11.2026. 5 kom', '20.11.2026. u 14:30, 2 kom', '22 m² i 3 kom', 'Ilica 123/4, 10000 Zagreb', 'BOX NOW Savska — Savska cesta 1060, 10000 (ID 4051)', 'Ilica 5, stan 1234, 10000 Zagreb'];
$bR = array_values(array_filter($kR, fn($x) => sj_mask_numbers($x) !== $x));
$hR = ["tel. 091\u{3161}234\u{3161}5678", "091\u{4E00}234\u{4E00}5678", "091\u{01C0}234\u{01C0}5678", "HR98 2360\u{3161}0001 1027\u{3161}9044 2", 'Nazovite ⓪⑨①234⑤⑥⑦8', '⓪⑨①②③④5678', '⁰⁹¹ 234 ⁵⁶⁷⁸',
  'Za uplatu nazovite 09123, 45678 odmah, Zagreb', '09:12-3:45, 67', '09.12., 3.4., 5.6., 7.8.', '09.12.3.4.5.6.7.8.', 'Telegram 385/91, 23456 (ID 78)', '8:00-16:00, 123', '20.11.2026., 123',
  '09.12., 34.56., 78.90.', '09:00-1:23, 45', '09-12 13-14 5-6', 'Ulica 9123, 45678 Zagreb', 'Ulica 0912, 34567 Zagreb', 'Ulica 12345, 10000 Zagreb'];
$vR = array_values(array_filter($hR, fn($x) => !str_contains(sj_mask_numbers($x), ' skriven]')));
$ok(!$bR && !$vR, 'recenzija v102 (sigurnost N1/N2/N4): datum s godinom, broj narudžbe i vrijeme od–do uz količinu ili kat ostaju, popis datuma ostaje; dvojnici crtice i miješane „znamenke” se skrivaju ' . json_encode([$bR, $vR], JSON_UNESCAPED_UNICODE));
$ok(sj_unlink_addr('Livada 2.red/5') === 'Livada 2. red/5' && sj_unlink_addr('Kneza Branimira 4, k.br/2') === 'Kneza Branimira 4, k. br/2' && sj_unlink_addr('Ul.hr branitelja 12') === 'Ul. hr branitelja 12'
  && sj_unlink_addr('Ilica 1, t.ly/abc') === 'Ilica 1, [uklonjeno]' && sj_unlink_addr('Ilica 1, www.zlo.example') === 'Ilica 1, [uklonjeno]' && sj_unlink('Livada 2.red/5') === 'Livada 2. red/5'
  && sj_unlink('Uplata preko x.de ili ž.to ili a.top ili t.me ili q.pro ili 1.at') === 'Uplata preko x. de ili ž. to ili a. top ili t. me ili q. pro ili 1. at'
  && sj_unlink('d.o.o. i j.d.o.o.') === 'd.o.o. i j.d.o.o.' && sj_unlink('A.Šenoe 5') === 'A. Šenoe 5' && sj_unlink('Naplata.Com') === '[uklonjeno]' && sj_unlink('pisite na bit.ly/abc') === 'pisite na [uklonjeno]'
  && !str_contains(sj_unlink("naplata.c\u{1BC9D}om"), 'naplata.c') && !str_contains(sj_unlink("racun.\u{2D7F}hr"), 'racun.' . "\u{2D7F}" . 'hr'),
  'recenzija v102 (sigurnost N3/N5): adresa koju je ulaz primio u e-mailu ostaje („Livada 2. red/5”); jednoslovna oznaka uz nastavak dobiva razmak („x. de”, „t. me”)');
$ok(sj_has_link(sj_clean("shivaj-uplata.\u{2065}com", 200)) && sj_has_link("uplata\u{2E3C}com") && sj_has_link('uplata★.com') && sj_has_link("shivaj-uplata\u{1F4B3}.ws")
  && !sj_has_link('Hvala 👍. Top ste!') && !sj_has_link('Lijepo 😊.Hvala') && !sj_has_link('Super ❤ torba.') && sj_no_digit(0x2468) === 9 && sj_no_digit(0x24EA) === 0 && sj_no_digit(0x2079) === 9 && sj_no_digit(0x00BD) === null,
  'recenzija v102 (sigurnost N5): nevidljivi nedodijeljeni znak, točka U+2E3C i simbol pred točkom ne skrivaju domenu; rečenice s emojijem prolaze');
/* v102 (sigurnost N4): zaglavlje Host s dijelom iza dvotočke koji nije port ne ulazi u link e-maila */
{ $h0 = $_SERVER['HTTP_HOST'] ?? null; $cfgH = sj_config();
  $_SERVER['HTTP_HOST'] = '127.0.0.1:8404@zlo.example'; $u1 = sj_site_url_trusted();
  $_SERVER['HTTP_HOST'] = '127.0.0.1:8404'; $u2 = sj_site_url_trusted();
  if ($h0 === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $h0;
  $ok(!empty($cfgH['site_url']) || ($u1 === '' && $u2 === 'http://127.0.0.1:8404'), 'v102 (sigurnost N4): Host „127.0.0.1:8404@zlo.example” ne daje adresu stranice; „127.0.0.1:8404” daje ' . json_encode([$u1, $u2])); }
@unlink($oj); file_put_contents(SJ_DATA . '/raskidi.json', $rb101 === '' ? '[]' : $rb101); @unlink(SJ_DATA . '/raskidi-provjera.json');
/* v99 (L4/L5): kopija dodatnog popisa izjava uspoređuje se s najnovijom kopijom koja ga sadrži; stare privremene datoteke se brišu */
$kr = SJ_DATA . '/kopije'; $ovf = sj_raskid_overflow_path(time()); file_put_contents($ovf, json_encode(['name' => 'Ov99']) . "\n");
$dA = date('Y-m-d', time() - 2 * 86400); $dB = date('Y-m-d', time() - 86400); $dT = date('Y-m-d');
foreach ([$dA, $dB, $dT] as $dd) { foreach (glob("$kr/$dd/*") ?: [] as $x) @unlink($x); @unlink("$kr/$dd/.gotovo"); @rmdir("$kr/$dd"); }
@mkdir("$kr/$dA", 0755, true); copy($ovf, "$kr/$dA/" . basename($ovf)); file_put_contents("$kr/$dA/.gotovo", 'x');
@mkdir("$kr/$dB", 0755, true); file_put_contents("$kr/$dB/.gotovo", 'x'); /* jučerašnja kopija bez dodatnog popisa */
$tOld = SJ_DATA . '/narudzbe.json.tmp-staro99'; $tNew = SJ_DATA . '/narudzbe.json.tmp-novo99'; file_put_contents($tOld, '{}'); touch($tOld, time() - 7200); file_put_contents($tNew, '{}');
sj_daily_backup(); $same99 = !is_file("$kr/$dT/" . basename($ovf));
file_put_contents($ovf, json_encode(['name' => 'Ov99b']) . "\n", FILE_APPEND); @unlink("$kr/$dT/.gotovo");
sj_daily_backup(); $chg99 = is_file("$kr/$dT/" . basename($ovf));
$ok($same99 && $chg99 && !is_file($tOld) && is_file($tNew), 'v99 (L4/L5): dodatni popis izjava ide u kopiju samo kad se promijenio (usporedba s najnovijom kopijom koja ga sadrži); privremene datoteke starije od sata brišu se');
@unlink($tNew); @unlink($ovf);
echo implode("\n", $R), "\n";
$fails = count(array_filter($R, fn($l) => str_starts_with($l, 'FAIL')));
echo $fails ? "NEUSPJELO: $fails\n" : "SVE PROŠLO (" . count($R) . ")\n";
exit($fails ? 1 : 0);
