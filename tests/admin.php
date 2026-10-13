<?php
/* Testovi admina (Plaćeno za narudžbu, Rezervacije):  php tests/admin.php */
$site = getenv('SHIVAJ_ADMIN_SITE') ?: sys_get_temp_dir() . '/shivaj-admin';
passthru('SHIVAJ_NO_SERVE=1 sh ' . escapeshellarg(__DIR__ . '/serve.sh') . ' ' . escapeshellarg($site) . ' >/dev/null', $rc);
if ($rc !== 0) { fwrite(STDERR, "serve.sh nije uspio\n"); exit(2); }
$D = $site . '/data';
$c = json_decode(file_get_contents($D . '/config.json'), true); $c['password_hash'] = password_hash('proba-lozinka-123', PASSWORD_DEFAULT);
file_put_contents($D . '/config.json', json_encode($c));
/* pravi poslužitelj na 8091 i prava prijava (kolačić, CSRF iz stranice), da se vide i poruke iz preusmjeravanja */
$port = 8091; $jar = tempnam(sys_get_temp_dir(), 'sjck');
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $site, "$site/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(700000);
$curl = fn(array $args) => (string)shell_exec('curl -s -i -c ' . escapeshellarg($jar) . ' -b ' . escapeshellarg($jar) . ' ' . implode(' ', array_map('escapeshellarg', $args)));
$curl(['-d', 'password=proba-lozinka-123', "http://127.0.0.1:$port/admin/"]);
$req = function (string $m, array $f) use ($curl, $port) {
  $url = "http://127.0.0.1:$port/admin/";
  if ($m === 'GET') return $curl([$url . '?' . http_build_query($f)]);
  preg_match('/name="csrf" value="([^"]+)"/', $curl([$url . '?s=narudzbe']), $mm);
  $args = []; foreach ($f + ['csrf' => $mm[1] ?? ''] as $k => $v) { $args[] = '--data-urlencode'; $args[] = "$k=$v"; }
  $out = $curl(array_merge($args, [$url]));
  return preg_match('/^Location: (.*)$/mi', $out, $l) ? urldecode(trim($l[1])) : $out;
};
$R = []; $ok = function ($c, $m) use (&$R) { $R[] = ($c ? 'PASS ' : 'FAIL ') . $m; };
$rd = fn() => json_decode(file_get_contents($D . '/rezervacije.json'), true);

/* narudžba B: Nera bez rezervacije; narudžba A kasnije dobila rezervaciju na Neri; B plati prvi */
file_put_contents($D . '/narudzbe.json', json_encode([
  ['no' => 'SJ-2026-0402', 'at' => '2026-10-11T11:00:00Z', 'name' => 'Ana A', 'email' => 'a@p.hr', 'reservation' => ['token' => 'tA'], 'order' => ['items' => [['id' => 'nera', 'made' => false]], 'unheld' => []]],
  ['no' => 'SJ-2026-0401', 'at' => '2026-10-11T10:00:00Z', 'name' => 'Bruno B', 'email' => 'b@p.hr', 'reservation' => null, 'order' => ['items' => [['id' => 'nera', 'made' => false]], 'unheld' => ['nera']]],
  ['no' => 'SJ-2026-0403', 'at' => '2026-10-11T12:00:00Z', 'name' => 'Cvita C', 'email' => 'c@p.hr', 'reservation' => ['token' => 'tC'], 'order' => ['items' => [['id' => 'tara', 'made' => false]], 'unheld' => []]],
]));
file_put_contents($D . '/rezervacije.json', json_encode(['res' => [
  'nera' => ['id' => 'nera', 'token' => 'tA', 'name' => 'Ana A', 'email' => 'a@p.hr', 'ordered' => true, 'until' => '2099-01-01T00:00:00Z'],
  'tara' => ['id' => 'tara', 'token' => 'tC', 'name' => 'Cvita C', 'email' => 'c@p.hr', 'ordered' => true, 'until' => '2099-01-01T00:00:00Z']], 'sold' => []]));
@unlink($D . '/brojac.json');

$view = $req('GET', ['s' => 'narudzbe']);
$ok(preg_match('#SJ-2026-0401.*?bez rezervacije: nera.*?order_paid#s', $view) === 1 && preg_match('#SJ-2026-0403.*?order_paid#s', $view) === 1, 'Narudžbe: gumb „Plaćeno” i za narudžbu bez rezervacije i za aktivnu');
$out = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0401', 'back' => '?s=narudzbe']);
$r = $rd();
$ok(($r['sold']['nera']['email'] ?? '') === 'b@p.hr' && !isset($r['res']['nera']) && str_contains($out, 'Ana A'), 'B platio prvi: Nera prodana B-u, A-ova rezervacija uklonjena, vlasnica upozorena');
$out = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0402', 'back' => '?s=narudzbe']);
$ok(($rd()['sold']['nera']['email'] ?? '') === 'b@p.hr' && str_contains($out, 'već plaćena za drugog kupca'), 'A plati kasnije: Nera ostaje B-u, vlasnica upozorena');
$req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0403', 'back' => '?s=narudzbe']);
$r = $rd(); $cnt = array_values(json_decode((string)@file_get_contents($D . '/brojac.json'), true) ?: [[]])[0];
$ok(($r['sold']['tara']['token'] ?? '') === 'tC' && !isset($r['res']['tara']) && (int)($cnt['paid'] ?? 0) === 3, 'aktivna rezervacija plaćena iz Narudžbi: Tara prodana C-u, plaćeno se broji jednom po narudžbi (3)');

/* v39: torba bez rezervacije koja u katalogu više nije na prodaji (zora ručno prodana, mira ručno rezervirana) se ne upisuje */
file_put_contents($D . '/narudzbe.json', json_encode([
  ['no' => 'SJ-2026-0410', 'at' => '2026-10-11T13:00:00Z', 'name' => 'Dora D', 'email' => 'd@p.hr', 'reservation' => null, 'order' => ['items' => [['id' => 'zora', 'made' => false], ['id' => 'mira', 'made' => false], ['id' => 'luna', 'made' => false]], 'unheld' => ['zora', 'mira', 'luna']]],
]));
file_put_contents($D . '/rezervacije.json', json_encode(['res' => [], 'sold' => []]));
$out = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0410', 'back' => '?s=narudzbe']);
$r = $rd();
$ok(!isset($r['sold']['zora']) && !isset($r['sold']['mira']) && ($r['sold']['luna']['no'] ?? '') === 'SJ-2026-0410' && str_contains($out, 'pa nisu označene: zora, mira'), 'Plaćeno: ručno prodana/rezervirana torba se ne upisuje i navodi se u upozorenju, slobodna (luna) se upisuje');
/* v39: „Ukloni oznaku” vraća torbe ove narudžbe u prodaju, tuđe ne dira */
$r['sold']['tara'] = ['id' => 'tara', 'token' => 'tX', 'email' => 'x@p.hr', 'paidAt' => '2026-10-11T00:00:00Z'];
file_put_contents($D . '/rezervacije.json', json_encode($r));
$out = $req('POST', ['act' => 'order_unpaid', 'no' => 'SJ-2026-0410', 'back' => '?s=narudzbe']);
$r = $rd(); $o = json_decode(file_get_contents($D . '/narudzbe.json'), true)[0];
$ok(!isset($r['sold']['luna']) && isset($r['sold']['tara']) && empty($o['paidAt']) && !empty($o['paidCounted']) && str_contains($out, 'luna vraćene su u prodaju'), 'Ukloni oznaku: luna vraćena u prodaju, tuđa tara ostaje, brojač se ne dira');
/* v39: narudžba iz godišnje arhive (starija od zadnjih 300) */
file_put_contents($D . '/narudzbe-arhiva-2026.json', json_encode([
  ['no' => 'SJ-2026-0001', 'at' => '2026-01-11T10:00:00Z', 'name' => 'Ema E', 'email' => 'e@p.hr', 'reservation' => null, 'order' => ['items' => [['id' => 'vela', 'made' => false]], 'unheld' => ['vela']]],
]));
$out = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0001', 'back' => '?s=narudzbe']);
$a = json_decode(file_get_contents($D . '/narudzbe-arhiva-2026.json'), true)[0];
$ok(($rd()['sold']['vela']['email'] ?? '') === 'e@p.hr' && !empty($a['paidAt']) && !str_contains($out, 'nije nađena'), 'Plaćeno radi i za narudžbu iz arhive');

/* upozorenje u Rezervacijama kad neplaćene drže više od pola slobodnih */
file_put_contents($D . '/rezervacije.json', json_encode(['res' => [
  'luna' => ['id' => 'luna', 'token' => 't1', 'name' => 'X', 'email' => 'x@p.hr', 'ordered' => true, 'since' => '2026-10-11T00:00:00Z', 'until' => '2099-01-01T00:00:00Z'],
  'tara' => ['id' => 'tara', 'token' => 't2', 'name' => 'Y', 'email' => 'y@p.hr', 'ordered' => true, 'since' => '2026-10-11T00:00:00Z', 'until' => '2099-01-01T00:00:00Z']], 'sold' => ['vela' => ['id' => 'vela', 'paidAt' => '2026-10-11T00:00:00Z']]]));
$ok(str_contains($req('GET', ['s' => 'rez']), 'više od pola'), 'Rezervacije: upozorenje kad neplaćene drže više od pola slobodnih');

/* v20 (admin): promjena lozinke odjavljuje postojeće sesije */
$c = json_decode(file_get_contents($D . '/config.json'), true); $c['password_hash'] = password_hash('nova-lozinka-456', PASSWORD_DEFAULT);
file_put_contents($D . '/config.json', json_encode($c));
$ok(str_contains($curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]), 'name="password"'), 'v20: nakon promjene lozinke stara sesija više ne vrijedi');
/* v20: prva lozinka traži kod iz PRVA-PRIJAVA.txt */
$c['password_hash'] = ''; file_put_contents($D . '/config.json', json_encode($c));
file_put_contents($D . '/PRVA-PRIJAVA.txt', "tekst\n");
$ok(str_contains($curl(["http://127.0.0.1:$port/admin/"]), 'nema koda za prvu prijavu'), 'v20: bez koda u datoteci admin ne nudi postavljanje lozinke');
file_put_contents($D . '/PRVA-PRIJAVA.txt', "tekst\nkod: tajni-kod-12345\n");
$bad = $curl(['--data-urlencode', 'setup_code=krivi-kod-0000', '--data-urlencode', 'setup_password=napadac-1234567', '--data-urlencode', 'setup_password2=napadac-1234567', "http://127.0.0.1:$port/admin/"]);
$c2 = json_decode(file_get_contents($D . '/config.json'), true);
$ok(str_contains($bad, 'Kod nije točan') && ($c2['password_hash'] ?? '') === '' && is_file($D . '/PRVA-PRIJAVA.txt'), 'v20: krivi kod — lozinka se ne postavlja');
$good = $curl(['--data-urlencode', 'setup_code=tajni-kod-12345', '--data-urlencode', 'setup_password=vlasnica-123456', '--data-urlencode', 'setup_password2=vlasnica-123456', "http://127.0.0.1:$port/admin/"]);
$c2 = json_decode(file_get_contents($D . '/config.json'), true);
$ok(($c2['password_hash'] ?? '') !== '' && password_verify('vlasnica-123456', $c2['password_hash']) && !is_file($D . '/PRVA-PRIJAVA.txt'), 'v20: točan kod — lozinka postavljena, datoteka obrisana');
file_put_contents($D . '/PRVA-PRIJAVA.txt', "kod: zaostali-kod-999\n");
$curl(["http://127.0.0.1:$port/admin/"]);
$ok(!is_file($D . '/PRVA-PRIJAVA.txt'), 'v20: zaostala PRVA-PRIJAVA.txt se briše kad lozinka postoji');

proc_terminate($srv); @unlink($jar);
echo implode("\n", $R), "\n";
$f = count(array_filter($R, fn($l) => str_starts_with($l, 'FAIL')));
echo $f ? "NEUSPJELO: $f\n" : "SVE PROŠLO (" . count($R) . ")\n";
exit($f ? 1 : 0);
