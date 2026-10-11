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

/* upozorenje u Rezervacijama kad neplaćene drže više od pola slobodnih */
file_put_contents($D . '/rezervacije.json', json_encode(['res' => [
  'luna' => ['id' => 'luna', 'token' => 't1', 'name' => 'X', 'email' => 'x@p.hr', 'ordered' => true, 'since' => '2026-10-11T00:00:00Z', 'until' => '2099-01-01T00:00:00Z'],
  'tara' => ['id' => 'tara', 'token' => 't2', 'name' => 'Y', 'email' => 'y@p.hr', 'ordered' => true, 'since' => '2026-10-11T00:00:00Z', 'until' => '2099-01-01T00:00:00Z']], 'sold' => ['vela' => ['id' => 'vela', 'paidAt' => '2026-10-11T00:00:00Z']]]));
$ok(str_contains($req('GET', ['s' => 'rez']), 'više od pola'), 'Rezervacije: upozorenje kad neplaćene drže više od pola slobodnih');

proc_terminate($srv); @unlink($jar);
echo implode("\n", $R), "\n";
$f = count(array_filter($R, fn($l) => str_starts_with($l, 'FAIL')));
echo $f ? "NEUSPJELO: $f\n" : "SVE PROŠLO (" . count($R) . ")\n";
exit($f ? 1 : 0);
