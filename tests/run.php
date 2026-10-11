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

echo implode("\n", $R), "\n";
$fails = count(array_filter($R, fn($l) => str_starts_with($l, 'FAIL')));
echo $fails ? "NEUSPJELO: $fails\n" : "SVE PROŠLO (" . count($R) . ")\n";
exit($fails ? 1 : 0);
