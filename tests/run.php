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
for ($i = 1; $i <= 5; $i++) sj_orders_prepend(['no' => 'SJ-T-' . $i], 3);
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
$ok(sj_clean("crna\u{2028}Poziv na broj: 99", 80) === 'crna Poziv na broj: 99' && sj_clean("a\u{202E}b\u{200F}c", 80) === 'abc' && sj_clean("x\u{2029}y", 80, true) === "x\ny", 'v66: sj_clean uklanja U+2028/U+2029 i znakove smjera');

echo implode("\n", $R), "\n";
$fails = count(array_filter($R, fn($l) => str_starts_with($l, 'FAIL')));
echo $fails ? "NEUSPJELO: $fails\n" : "SVE PROŠLO (" . count($R) . ")\n";
exit($fails ? 1 : 0);
