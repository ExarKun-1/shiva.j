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
  /* v22: poruka je u sesiji, pa se preusmjerenje otvara i vraća stranica s porukom */
  return preg_match('/^Location: (.*)$/mi', $out, $l) ? $curl([$url . trim($l[1])]) : $out;
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
$ok(preg_match('#SJ-2026-0401.*?bez rezervacije: Nera.*?order_paid#s', $view) === 1 && preg_match('#SJ-2026-0403.*?order_paid#s', $view) === 1, 'Narudžbe: gumb „Plaćeno” i za narudžbu bez rezervacije i za aktivnu');
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
$ok(!isset($r['sold']['zora']) && !isset($r['sold']['mira']) && ($r['sold']['luna']['no'] ?? '') === 'SJ-2026-0410' && str_contains($out, 'pa nisu označene: „Zora”, „Mira”'), 'Plaćeno: ručno prodana/rezervirana torba se ne upisuje i navodi se u upozorenju, slobodna (luna) se upisuje');
/* v39: „Ukloni oznaku” vraća torbe ove narudžbe u prodaju, tuđe ne dira */
$r['sold']['tara'] = ['id' => 'tara', 'token' => 'tX', 'email' => 'x@p.hr', 'paidAt' => '2026-10-11T00:00:00Z'];
file_put_contents($D . '/rezervacije.json', json_encode($r));
$out = $req('POST', ['act' => 'order_unpaid', 'no' => 'SJ-2026-0410', 'back' => '?s=narudzbe']);
$r = $rd(); $o = json_decode(file_get_contents($D . '/narudzbe.json'), true)[0];
$ok(!isset($r['sold']['luna']) && isset($r['sold']['tara']) && empty($o['paidAt']) && !empty($o['paidCounted']) && str_contains($out, 'Torba „Luna” vraćena je u prodaju'), 'Ukloni oznaku: luna vraćena u prodaju, tuđa tara ostaje, brojač se ne dira');
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
$ok(str_contains($req('GET', ['s' => 'rez']), 'Pažnja: neplaćene narudžbe drže'), 'Rezervacije: upozorenje kad neplaćene drže više od pola slobodnih');

/* v21: A — „Označi trajno prodanom” je jednosmjerno; dvostruki klik ne vraća torbu u prodaju */
file_put_contents($D . '/rezervacije.json', json_encode(['res' => [
  'tara' => ['id' => 'tara', 'token' => 'tK', 'name' => 'Kosara K', 'email' => 'k@p.hr', 'ordered' => false, 'since' => '2026-10-11T00:00:00Z', 'until' => '2099-01-01T00:00:00Z']],
  'sold' => ['luna' => ['id' => 'luna', 'token' => 'tL', 'name' => 'Lea L', 'email' => 'l@p.hr', 'paidAt' => '2026-10-11T00:00:00Z']]]));
$prodSold = function (string $id) use ($D) { foreach (json_decode(file_get_contents($D . '/proizvodi.json'), true) as $p) if (($p['id'] ?? '') === $id) return !empty($p['sold']); return null; };
$req('POST', ['act' => 'toggle', 'id' => 'luna', 'field' => 'sold', 'to' => '1', 'back' => '?s=rez']);
$out2 = $req('POST', ['act' => 'toggle', 'id' => 'luna', 'field' => 'sold', 'to' => '1', 'back' => '?s=rez']);
$ok($prodSold('luna') === true && str_contains($out2, 'trajno prodana'), 'v21 A: dvaput „Označi trajno prodanom” — torba ostaje prodana, poruka običnom rečenicom');
$rez = $req('GET', ['s' => 'rez']);
$ok(str_contains($rez, 'Trajno prodana ✓') && preg_match('#luna.*?res_unsold#s', $rez) !== 1, 'v21 A: za trajno prodanu nema „Ukloni oznaku”, samo „Vrati u prodaju”');
$out3 = $req('POST', ['act' => 'res_unsold', 'id' => 'luna', 'back' => '?s=rez']);
$ok(isset($rd()['sold']['luna']) && str_contains($out3, 'trajno prodana'), 'v21 A: „Ukloni oznaku” za trajno prodanu poslužitelj odbija');
/* B — ručni obrazac: prazan prvi izbor, bez odabira ništa */
$ok(str_contains($rez, '— odaberite torbu —') && str_contains($rez, 'Označi odabranu torbu prodanom'), 'v21 B: ručni obrazac s praznim prvim izborom i jasnim gumbom');
$before = json_encode($rd()['sold']);
$req('POST', ['act' => 'res_sold_manual', 'id' => '', 'back' => '?s=rez']);
$ok(json_encode($rd()['sold']) === $before, 'v21 B: bez odabira torbe ništa se ne označava');
/* C — rezervacija bez narudžbe nema „Plaćeno”, poslužitelj ga odbija */
$ok(preg_match('#Tara.*?košarica, nije narudžba.*?res_release#s', $rez) === 1 && preg_match('#tara</span></td>(?:(?!</tr>).)*res_sold"#s', $rez) !== 1, 'v21 C: rezervacija bez narudžbe — samo „Ukloni”');
$out4 = $req('POST', ['act' => 'res_sold', 'id' => 'tara', 'back' => '?s=rez']);
$ok(!isset($rd()['sold']['tara']) && str_contains($out4, 'nije potvrđena'), 'v21 C: „Plaćeno” za nepotvrđenu poslužitelj odbija');
$ok(str_contains($rez, 'class="cards t-rez"'), 'v21 B: tablice na mobitelu kao kartice');

/* v22: „Vrati u prodaju” briše i zapis „plaćeno”; ručna prodaja je odmah trajno prodana */
$req('POST', ['act' => 'toggle', 'id' => 'luna', 'field' => 'sold', 'to' => '0', 'back' => '?s=rez']);
$ok($prodSold('luna') === false && !isset($rd()['sold']['luna']), 'v22: „Vrati u prodaju” — torba stvarno u prodaji (bez zapisa plaćeno)');
$out5 = $req('POST', ['act' => 'res_sold_manual', 'id' => '', 'back' => '?s=rez']);
$ok(str_contains($out5, 'Odaberite torbu'), 'v22: prazan izbor — „Odaberite torbu.”');
$out6 = $req('POST', ['act' => 'res_sold_manual', 'id' => 'luna', 'back' => '?s=rez']);
$ok($prodSold('luna') === true && isset($rd()['sold']['luna']) && str_contains($out6, 'izvan stranice') && str_contains($out6, 'prodano izvan stranice'), 'v22: ručna prodaja — odmah trajno prodana, „prodano izvan stranice”');
/* v22: poruka iz adrese (?m=) se ne prikazuje; Torbe na mobitelu kao kartice; CSP */
$fake = $curl(["http://127.0.0.1:$port/admin/?s=torbe&m=" . rawurlencode('Pošaljite lozinku na zlo@primjer.hr')]);
$ok(!str_contains($fake, 'zlo@primjer.hr'), 'v22: poruka iz adrese (?m=) se ne prikazuje');
$ok(str_contains($fake, 'class="cards t-tor"') && str_contains($fake, 'Označi trajno prodanom') || str_contains($fake, 'Vrati u prodaju'), 'v22: Torbe kao kartice, isti nazivi gumba');
$ok(preg_match('/^Content-Security-Policy: .*frame-ancestors \'none\'/mi', $fake) === 1, 'v22: CSP zaglavlje u adminu');

/* v23: obrazac Uredi bez „Prodano” briše i zapis plaćeno; ručna prodaja odbija artikl po narudžbi; potvrde bez inline JS-a */
$form = $req('GET', ['s' => 'uredi', 'id' => 'luna']);
preg_match('/name="csrf" value="([^"]+)"/', $form, $cm);
$curl(['--data-urlencode', 'csrf=' . ($cm[1] ?? ''), '--data-urlencode', 'act=save_product', '--data-urlencode', 'id=luna', '--data-urlencode', 'name=Luna', '--data-urlencode', 'price=65', '--data-urlencode', 'cat=torbe', '--data-urlencode', 'back=?s=torbe', "http://127.0.0.1:$port/admin/"]);
$ok($prodSold('luna') === false && !isset($rd()['sold']['luna']), 'v23: Uredi bez „Prodano” — briše i zapis plaćeno');
$out7 = $req('POST', ['act' => 'res_sold_manual', 'id' => 'klasik', 'back' => '?s=rez']);
$ok(str_contains($out7, 'po narudžbi ne prodaju se ručno') && !isset($rd()['sold']['klasik']), 'v23: ručna prodaja odbija artikl po narudžbi');
$pg = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
$ok(preg_match("/^Content-Security-Policy: .*script-src 'nonce-[a-f0-9]+';/mi", $pg) === 1 && !str_contains($pg, 'onsubmit=') && str_contains($pg, 'data-confirm='), 'v23: CSP s nonceom, potvrde bez inline JS-a');
$ok(str_contains($pg, '<th>Uplata</th>') && (str_contains($pg, 'nije plaćeno') || str_contains($pg, '<b>plaćeno</b>')), 'v23: Narudžbe — „Uplata”, „nije plaćeno” / „Označi plaćenom”');
$tg = $req('POST', ['act' => 'toggle', 'id' => 'kozmeticka', 'field' => 'hidden', 'to' => '1', 'back' => '?s=torbe']);
$ok(str_contains($tg, 'Artikl „Kozmetička torbica” je skriven i'), 'v23: poruka s imenicom i ispravnim rodom');
$req('POST', ['act' => 'toggle', 'id' => 'kozmeticka', 'field' => 'hidden', 'to' => '0', 'back' => '?s=torbe']);

/* v26: SUKOB — plaćena narudžba čija torba je zapisana kao plaćena za drugog kupca; storno upisuje „stornirano” */
file_put_contents($D . '/narudzbe.json', json_encode([
  ['no' => 'SJ-2026-0502', 'at' => '2026-10-12T11:00:00Z', 'name' => 'Iva I', 'email' => 'i@p.hr', 'paidAt' => '2026-10-12T12:00:00Z', 'reservation' => ['token' => 'tI'], 'items' => 'Tara', 'order' => ['items' => [['id' => 'tara', 'name' => 'Tara', 'made' => false]], 'unheld' => []]],
  ['no' => 'SJ-2026-0501', 'at' => '2026-10-12T10:00:00Z', 'name' => 'Marko M', 'email' => 'm@p.hr', 'paidAt' => '2026-10-12T11:30:00Z', 'reservation' => ['token' => 'tM'], 'items' => 'Tara', 'order' => ['items' => [['id' => 'tara', 'name' => 'Tara', 'made' => false]], 'unheld' => []]],
  ['no' => 'SJ-2026-0503', 'at' => '2026-10-12T13:00:00Z', 'name' => 'Lea L', 'email' => 'lea@p.hr', 'reservation' => ['token' => 'tL'], 'items' => 'Luna', 'order' => ['items' => [['id' => 'luna', 'name' => 'Luna', 'made' => false]], 'unheld' => []]],
]));
file_put_contents($D . '/rezervacije.json', json_encode(['res' => ['luna' => ['id' => 'luna', 'token' => 'tL', 'name' => 'Lea L', 'email' => 'lea@p.hr', 'since' => '2026-10-12T13:00:00Z', 'at' => '2026-10-12T13:00:00Z', 'until' => '2099-01-01T00:00:00Z', 'ordered' => true]],
  'sold' => ['tara' => ['id' => 'tara', 'token' => 'tM', 'name' => 'Marko M', 'email' => 'm@p.hr', 'paidAt' => '2026-10-12T11:30:00Z']]]));
$nar = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
$okI = preg_match('/SJ-2026-0502.*?<\/tr>/s', $nar, $mI) && str_contains($mI[0], 'SUKOB');
$okM = preg_match('/SJ-2026-0501.*?<\/tr>/s', $nar, $mM) && !str_contains($mM[0], 'SUKOB');
$ok($okI && $okM, 'v26: Narudžbe trajno označuju SUKOB samo kod narudžbe za koju torba nije zapisana kao plaćena');
$rz = $req('GET', ['s' => 'rez']);
$ok(str_contains($rz, 'SJ-2026-0503'), 'v27: Rezervacije pokazuju broj narudžbe');
/* v27: „Ukloni oznaku” u Rezervacijama skida i oznaku plaćeno s narudžbe (nema lažnog SUKOB-a) */
$un62 = $req('POST', ['act' => 'res_unsold', 'id' => 'tara', 'back' => '?s=rez']);
$ok(str_contains($un62, '(narudžba SJ-2026-0501)') && str_contains($un62, 'javite mu se (m@p.hr)') && str_contains($un62, 'plaćena je i narudžba SJ-2026-0502 (Iva I)'), 'v62: „Ukloni oznaku” — broj narudžbe, e-mail kupca i druga plaćena narudžba za istu torbu');
$nl = json_decode(file_get_contents($D . '/narudzbe.json'), true); $byNo = []; foreach ($nl as $o) $byNo[$o['no']] = $o;
$ok(empty($byNo['SJ-2026-0501']['paidAt']) && !empty($byNo['SJ-2026-0502']['paidAt']), 'v27: „Ukloni oznaku” u Rezervacijama uklanja oznaku plaćeno i s narudžbe tog kupca');
$rel = $req('POST', ['act' => 'res_release', 'id' => 'luna', 'back' => '?s=rez']);
$nar = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
$ok(str_contains($rel, 'javite mu se (lea@p.hr)') && preg_match('/SJ-2026-0503.*?<\/tr>/s', $nar, $mL) && str_contains($mL[0], '<b>stornirano</b>') && !str_contains($mL[0], 'rezervacija istekla'), 'v26: storno — poruka „javite se kupcu”, u Narudžbama „stornirano”');
/* v28: „Označi plaćenom” u Rezervacijama upisuje broj narudžbe; „Storniraj sve s ove adrese” navodi kupce; odbijene narudžbe */
$nl = json_decode(file_get_contents($D . '/narudzbe.json'), true);
array_unshift($nl, ['no' => 'SJ-2026-0504', 'at' => '2026-10-12T14:00:00Z', 'name' => 'Vid V', 'email' => 'vid@p.hr', 'reservation' => ['token' => 'tV'], 'items' => 'Vela', 'order' => ['items' => [['id' => 'vela', 'name' => 'Vela', 'made' => false]], 'unheld' => []]]);
file_put_contents($D . '/narudzbe.json', json_encode($nl));
$rr = $rd(); $rr['res']['vela'] = ['id' => 'vela', 'token' => 'tV', 'name' => 'Vid V', 'email' => 'vid@p.hr', 'ip' => 'abcdef0123456789', 'since' => '2026-10-12T14:00:00Z', 'at' => '2026-10-12T14:00:00Z', 'until' => '2099-01-01T00:00:00Z', 'ordered' => true];
$rr['res']['nera'] = ['id' => 'nera', 'token' => 'tN', 'name' => 'Nina N', 'email' => 'nina@p.hr', 'ip' => 'abcdef0123456789', 'since' => '2026-10-12T14:00:00Z', 'at' => '2026-10-12T14:00:00Z', 'until' => '2099-01-01T00:00:00Z', 'ordered' => true];
file_put_contents($D . '/rezervacije.json', json_encode($rr));
$ok(str_contains($req('GET', ['s' => 'rez']), 'Vela — Vid V'), 'v28: „Storniraj sve s ove adrese” u potvrdi navodi torbe i kupce');
$rr2 = $rd(); $rr2['res']['tara2'] = $rr2['res']['nera']; file_put_contents($D . '/rezervacije.json', json_encode($rr2));
$soldMsg = $req('POST', ['act' => 'res_sold', 'id' => 'vela', 'back' => '?s=rez']);
$ok(str_contains($soldMsg, '„Vela” je označena kao plaćena (narudžba SJ-2026-0504)'), 'v29: poruka nakon „Označi plaćenom” ima broj narudžbe');
$relIp = $req('POST', ['act' => 'res_release_ip', 'ip' => 'abcdef0123456789', 'back' => '?s=rez']);
$ok(str_contains($relIp, 'javite im se') && str_contains($relIp, 'nina@p.hr'), 'v29: „Storniraj sve s ove adrese” navodi e-mailove kupaca');
$ok(($rd()['sold']['vela']['no'] ?? '') === 'SJ-2026-0504' && str_contains($req('GET', ['s' => 'rez']), 'SJ-2026-0504'), 'v28: plaćeno iz Rezervacija upisuje broj narudžbe (i prikazuje ga)');
file_put_contents($D . '/odbijene.json', json_encode([['at' => '2026-10-12T15:00:00Z', 'name' => 'Odbijena O', 'email' => 'odb@p.hr', 'items' => 'Tara', 'why' => 'ista veza']]));
$ok(str_contains($curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]), 'Odbijene (ista veza)') , 'v28: Narudžbe pokazuju odbijene narudžbe (ista veza)');
/* v26: adresa stranice bez https:// dobiva ga; neispravna se ne sprema */
$cfg0 = file_get_contents($D . '/config.json');
$set = ['act' => 'save_settings', 'back' => '?s=postavke', 'owner_email' => 'v@p.hr', 'from_email' => '', 'ttl_hours' => '24', 'iban' => 'HR1210010051863000160', 'recipient' => 'R', 'address' => 'A', 'model' => 'HR00', 'shipping' => "pickup | Osobno | 0 | ne | ne |\n"];
$req('POST', $set + ['site_url' => 'shivaj.hr']);
$ok((json_decode(file_get_contents($D . '/config.json'), true)['site_url'] ?? '') === 'https://shivaj.hr', 'v26: adresa stranice bez https:// sprema se s https://');
$ob = $req('POST', $set + ['site_url' => 'https://shivaj.hr', 'oib' => '12345678901', 'ship_lead' => '3 radna dana']);
$ok(str_contains($ob, 'value="3 radna dana"'), 'v28: pri grešci u Postavkama obrazac zadrži upisano');
$ob2 = $req('POST', array_merge($set, ['site_url' => 'https://shivaj.hr', 'oib' => '12345678901', 'from_email' => 'info@drugi.hr']));
$ok(str_contains($ob2, 'odlaze s adrese info@drugi.hr'), 'v29: upozorenje u Postavkama prati upisanu adresu pošiljatelja');
/* v62: prekidač „Ostalo”, Stalna kolekcija (narudžbe X/limit, Otvori nova mjesta), dulja poruka */
$req('POST', array_merge($set, ['site_url' => 'https://shivaj.hr', 'oib' => '12345678903', 'show_ostalo' => '1']));
$on = !empty(json_decode(file_get_contents($D . '/config.json'), true)['show_ostalo']);
$req('POST', array_merge($set, ['site_url' => 'https://shivaj.hr', 'oib' => '12345678903']));
$ok($on && empty(json_decode(file_get_contents($D . '/config.json'), true)['show_ostalo']) && str_contains($req('GET', ['s' => 'torbe']), 'Ostalo — skriveno na stranici'), 'v62: prekidač „Ostalo” se sprema; isključen — oznaka u popisu Torbe');
$tv = $req('GET', ['s' => 'torbe']);
$ok(preg_match('/mjesta \d+\/20/', $tv) === 1 && !str_contains($tv, 'Otvori nova mjesta') && str_contains($tv, '<td>Stalna kolekcija</td>'), 'v63: „mjesta X/20”, stupac „Stalna kolekcija”, „Otvori nova mjesta” tek kad je popunjeno');
$pl = json_decode(file_get_contents($D . '/proizvodi.json'), true); foreach ($pl as &$x) if ($x['id'] === 'klasik') $x['made_limit'] = 0; unset($x); file_put_contents($D . '/proizvodi.json', json_encode($pl));
$tv0 = $req('GET', ['s' => 'torbe']);
$open = $req('POST', ['act' => 'made_open', 'id' => 'klasik', 'back' => '?s=torbe']);
$pk = null; foreach (json_decode(file_get_contents($D . '/proizvodi.json'), true) as $x) if ($x['id'] === 'klasik') $pk = $x;
$ok(str_contains($tv0, 'Otvori nova mjesta') && str_contains($open, 'Otvorena su nova mjesta za „Klasik crna”') && !empty($pk['made_open_from']), 'v62: popunjen model — „Otvori nova mjesta” postavlja početak brojanja');
$pl = json_decode(file_get_contents($D . '/proizvodi.json'), true); foreach ($pl as &$x) if ($x['id'] === 'klasik') $x['made_limit'] = 20; unset($x); file_put_contents($D . '/proizvodi.json', json_encode($pl));
/* v63: Storniraj narudžbu — i narudžba samo iz Stalne kolekcije (bez rezervacije) */
$nl = json_decode(file_get_contents($D . '/narudzbe.json'), true);
array_unshift($nl, ['no' => 'SJ-2026-0601', 'at' => date('c'), 'name' => 'Kol K', 'email' => 'kol@p.hr', 'phone' => '091 601 0601', 'note' => 'remen kraći', 'reservation' => null, 'items' => 'Klasik crna', 'order' => ['items' => [['id' => 'klasik', 'name' => 'Klasik crna', 'made' => true, 'qty' => 1]], 'unheld' => []]]);
file_put_contents($D . '/narudzbe.json', json_encode($nl));
$nv = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
$cx = $req('POST', ['act' => 'order_cancel', 'no' => 'SJ-2026-0601', 'back' => '?s=narudzbe']);
$o601 = null; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) if ($x['no'] === 'SJ-2026-0601') $o601 = $x;
$ok(preg_match('/SJ-2026-0601.*?Storniraj narudžbu/s', $nv) === 1 && !empty($o601['cancelledAt']) && str_contains($cx, 'oslobođeno je mjesto za „Klasik crna”') && str_contains($cx, 'javite mu se (kol@p.hr)'), 'v63: „Storniraj narudžbu” — i samo iz Stalne kolekcije; oslobađa mjesto, poruka „javite se kupcu”');
$ok(str_contains($nv, '091 601 0601') && str_contains($nv, 'Klasik crna: Stalna kolekcija') && str_contains($nv, 'remen kraći'), 'v64: Narudžbe — telefon, napomena i vrsta stavke');
$px = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0601', 'back' => '?s=narudzbe']);
$o601b = null; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) if ($x['no'] === 'SJ-2026-0601') $o601b = $x;
$nv2 = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
preg_match('/SJ-2026-0601.*?<\/tr>/s', $nv2, $row601);
$ok(str_contains($px, 'je stornirana, pa se ne može označiti plaćenom') && empty($o601b['paidAt']) && isset($row601[0]) && !str_contains($row601[0], 'Označi plaćenom'), 'v64: stornirana narudžba — bez „Označi plaćenom”, a radnja se odbija');
/* v63: izbor „Vrsta” postavlja kategoriju i „po narudžbi” */
preg_match('/name="csrf" value="([^"]+)"/', $curl(["http://127.0.0.1:$port/admin/?s=nova"]), $cv);
$curl(['--data-urlencode', 'csrf=' . ($cv[1] ?? ''), '--data-urlencode', 'act=save_product', '--data-urlencode', 'name=Proba kolekcija', '--data-urlencode', 'price=50', '--data-urlencode', 'vrsta=kolekcija', '--data-urlencode', 'made_limit=', '--data-urlencode', 'back=?s=torbe', "http://127.0.0.1:$port/admin/"]);
$pv = null; foreach (json_decode(file_get_contents($D . '/proizvodi.json'), true) as $x) if (($x['name'] ?? '') === 'Proba kolekcija') $pv = $x;
$ok($pv && ($pv['cat'] ?? '') === 'torbe' && !empty($pv['made']) && (int)$pv['made_limit'] === 3, 'v63: „Vrsta: Stalna kolekcija” — kategorija torbe, po narudžbi; prazno „Najviše mjesta” = 3');
$rr3 = $rd(); $many = [];
for ($k = 0; $k < 9; $k++) { $rr3['res']['dug' . $k] = ['id' => 'dug' . $k, 'token' => 'tD' . $k, 'name' => 'Kupac ' . $k, 'email' => 'vrlo.dugacka.adresa.kupca.' . $k . '@primjer-dugacke-domene.hr', 'ip' => 'fedcba9876543210', 'since' => '2026-10-12T14:00:00Z', 'at' => '2026-10-12T14:00:00Z', 'until' => '2099-01-01T00:00:00Z', 'ordered' => true]; }
file_put_contents($D . '/rezervacije.json', json_encode($rr3));
$long = $req('POST', ['act' => 'res_release_ip', 'ip' => 'fedcba9876543210', 'back' => '?s=rez']);
$ok(str_contains($long, 'vrlo.dugacka.adresa.kupca.8@primjer-dugacke-domene.hr'), 'v62: poruka „Storniraj sve s ove adrese” nije odrezana (svih 9 kupaca)');
$ok(str_contains($ob, 'OIB nije ispravan') && ($req('POST', $set + ['site_url' => 'https://shivaj.hr', 'oib' => '12345678903']) && (json_decode(file_get_contents($D . '/config.json'), true)['oib'] ?? '') === '12345678903'), 'v27: OIB s pogrešnom kontrolnom znamenkom se odbija, ispravan sprema');
$bad = $req('POST', $set + ['site_url' => 'https://ne valja', 'oib' => '123']);
$ok(str_contains($bad, 'nije ispravna') && (json_decode(file_get_contents($D . '/config.json'), true)['site_url'] ?? '') === 'https://shivaj.hr', 'v26: neispravna adresa se ne sprema');
file_put_contents($D . '/config.json', $cfg0);

/* v20 (admin): promjena lozinke odjavljuje postojeće sesije */
$c = json_decode(file_get_contents($D . '/config.json'), true); $c['password_hash'] = password_hash('nova-lozinka-456', PASSWORD_DEFAULT);
file_put_contents($D . '/config.json', json_encode($c));
$ok(str_contains($curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]), 'name="password"'), 'v20: nakon promjene lozinke stara sesija više ne vrijedi');
/* v20: prva lozinka traži kod iz PRVA-PRIJAVA.txt */
$c['password_hash'] = ''; file_put_contents($D . '/config.json', json_encode($c));
file_put_contents($D . '/PRVA-PRIJAVA.txt', "tekst\n");
$ok(str_contains($curl(["http://127.0.0.1:$port/admin/"]), 'nema koda za prvu prijavu'), 'v20: bez koda u datoteci admin ne nudi postavljanje lozinke');
file_put_contents($D . '/PRVA-PRIJAVA.txt', "tekst\nkod: tajni-kod-1234567\n");
$bad = $curl(['--data-urlencode', 'setup_code=krivi-kod-0000', '--data-urlencode', 'setup_password=napadac-1234567', '--data-urlencode', 'setup_password2=napadac-1234567', "http://127.0.0.1:$port/admin/"]);
$c2 = json_decode(file_get_contents($D . '/config.json'), true);
$ok(str_contains($bad, 'Kod nije točan') && ($c2['password_hash'] ?? '') === '' && is_file($D . '/PRVA-PRIJAVA.txt'), 'v20: krivi kod — lozinka se ne postavlja');
/* v22: ograničen broj promašaja koda */
for ($k = 0; $k < 9; $k++) $curl(['--data-urlencode', 'setup_code=krivi-kod-0000' . $k, '--data-urlencode', 'setup_password=napadac-1234567', '--data-urlencode', 'setup_password2=napadac-1234567', "http://127.0.0.1:$port/admin/"]);
$lim = $curl(['--data-urlencode', 'setup_code=tajni-kod-1234567', '--data-urlencode', 'setup_password=vlasnica-123456', '--data-urlencode', 'setup_password2=vlasnica-123456', "http://127.0.0.1:$port/admin/"]);
$ok(str_contains($lim, 'Previše pogrešnih pokušaja koda') && (json_decode(file_get_contents($D . '/config.json'), true)['password_hash'] ?? '') === '', 'v22: nakon 10 promašaja koda i točan kod čeka (ograničenje)');
@unlink($D . '/zahtjevi.json');
$setupPg = $curl(["http://127.0.0.1:$port/admin/"]);
$ok(str_contains($setupPg, 'autocapitalize="none"') && str_contains($setupPg, 'KOD-PRVE-PRIJAVE.txt'), 'v25: polje koda bez automatskog velikog slova, uputa na datoteku iz paketa');
/* v25: kod s velikim prvim slovom (mobilna tipkovnica) vrijedi jednako */
$good = $curl(['--data-urlencode', 'setup_code=Tajni-kod-1234567', '--data-urlencode', 'setup_password=vlasnica-123456', '--data-urlencode', 'setup_password2=vlasnica-123456', "http://127.0.0.1:$port/admin/"]);
$c2 = json_decode(file_get_contents($D . '/config.json'), true);
$ok(($c2['password_hash'] ?? '') !== '' && password_verify('vlasnica-123456', $c2['password_hash']) && !is_file($D . '/PRVA-PRIJAVA.txt'), 'v20/v25: točan kod (i s velikim prvim slovom) — lozinka postavljena, datoteka obrisana');
file_put_contents($D . '/PRVA-PRIJAVA.txt', "kod: zaostali-kod-99999\n");
$curl(["http://127.0.0.1:$port/admin/"]);
$ok(!is_file($D . '/PRVA-PRIJAVA.txt'), 'v20: zaostala PRVA-PRIJAVA.txt se briše kad lozinka postoji');

/* v25: upozorenje dok adresa stranice / pošiljatelja nisu upisani (probni config: obje prazne) */
$ok(str_contains($curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]), 'Nije upisano: adresa stranice i adresa pošiljatelja'), 'v25: upozorenje za praznu adresu stranice i pošiljatelja');
/* v25: za vrijeme blokade (8 promašaja) i točna lozinka dobiva poruku o blokadi, ne „Pogrešna lozinka” */
$anon = fn(string $pw) => (string)shell_exec('curl -s -i --data-urlencode ' . escapeshellarg('password=' . $pw) . ' ' . escapeshellarg("http://127.0.0.1:$port/admin/"));
$first = $anon('kriva-1');
$ok(!str_contains($first, 'Još '), 'v26: nakon prvog promašaja bez odbrojavanja');
for ($k = 0; $k < 5; $k++) $anon('kriva-x' . $k);
$ok(str_contains($anon('kriva-y'), 'Još 1 pokušaj'), 'v26: prije blokade admin kaže koliko je pokušaja ostalo');
$anon('kriva-z');
$locked = $anon('vlasnica-123456');
$ok(str_contains($first, 'Pogrešna lozinka.') && str_contains($locked, 'Previše pogrešnih pokušaja s ove veze') && !str_contains($locked, 'Pogrešna lozinka'), 'v25: blokada prijave ima vlastitu poruku');

proc_terminate($srv); @unlink($jar);
echo implode("\n", $R), "\n";
$f = count(array_filter($R, fn($l) => str_starts_with($l, 'FAIL')));
echo $f ? "NEUSPJELO: $f\n" : "SVE PROŠLO (" . count($R) . ")\n";
exit($f ? 1 : 0);
