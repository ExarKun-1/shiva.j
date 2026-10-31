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
$ok(preg_match('#SJ-2026-0401.*?order_paid#s', $view) === 1 && preg_match('#SJ-2026-0403.*?order_paid#s', $view) === 1 && !str_contains($view, 'bez rezervacije:'), 'Narudžbe: gumb „Plaćeno” i za staru narudžbu bez rezervacije i za aktivnu (v68: bez oznake „bez rezervacije”)');
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
$ok(!str_contains($req('GET', ['s' => 'rez']), 'Pažnja: neplaćene narudžbe drže'), 'v68: Rezervacije bez upozorenja „više od pola” (granica je ukinuta)');

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
$ok(str_contains($un62, '(narudžba SJ-2026-0501)') && str_contains($un62, 'javite se kupcu: m@p.hr') && str_contains($un62, 'plaćena je i narudžba SJ-2026-0502 (Iva I)'), 'v62: „Ukloni oznaku” — broj narudžbe, e-mail kupca i druga plaćena narudžba za istu torbu');
$nl = json_decode(file_get_contents($D . '/narudzbe.json'), true); $byNo = []; foreach ($nl as $o) $byNo[$o['no']] = $o;
$ok(empty($byNo['SJ-2026-0501']['paidAt']) && !empty($byNo['SJ-2026-0502']['paidAt']), 'v27: „Ukloni oznaku” u Rezervacijama uklanja oznaku plaćeno i s narudžbe tog kupca');
$rel = $req('POST', ['act' => 'res_release', 'id' => 'luna', 'back' => '?s=rez']);
$nar = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
$ok(str_contains($rel, 'javite se kupcu: lea@p.hr') && preg_match('/SJ-2026-0503.*?<\/tr>/s', $nar, $mL) && str_contains($mL[0], '<b>stornirano</b>') && !str_contains($mL[0], 'rezervacija istekla'), 'v26: storno — poruka „javite se kupcu”, u Narudžbama „stornirano”');
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
$ok(str_contains($relIp, 'javite mu se') && str_contains($relIp, 'nina@p.hr'), 'v29: „Storniraj sve s ove adrese” navodi e-mailove kupaca');
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
array_unshift($nl, ['no' => 'SJ-2026-0601', 'at' => date('c'), 'name' => 'Kol K', 'email' => 'kol@p.hr', 'phone' => '091 601 0601', 'note' => 'remen kraći', 'reservation' => null, 'items' => 'Klasik crna', 'order' => ['items' => [['id' => 'klasik', 'name' => 'Klasik crna', 'made' => true, 'qty' => 1, 'price' => 60, 'note' => 'crna']], 'unheld' => []]]);
file_put_contents($D . '/narudzbe.json', json_encode($nl));
$nv = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
$cx = $req('POST', ['act' => 'order_cancel', 'no' => 'SJ-2026-0601', 'back' => '?s=narudzbe']);
$o601 = null; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) if ($x['no'] === 'SJ-2026-0601') $o601 = $x;
$ok(preg_match('/SJ-2026-0601.*?Storniraj narudžbu/s', $nv) === 1 && !empty($o601['cancelledAt']) && str_contains($cx, 'oslobođeno je mjesto za „Klasik crna”') && str_contains($cx, 'javite mu se: kol@p.hr'), 'v63: „Storniraj narudžbu” — i samo iz Stalne kolekcije; oslobađa mjesto, poruka „javite se kupcu”');
$ok(str_contains($nv, '091 601 0601') && str_contains($nv, 'Klasik crna — <span class="nw">60,00') && str_contains($nv, '· Stalna kolekcija') && str_contains($nv, '<b>želja: crna</b>') && str_contains($nv, 'remen kraći'), 'v64/v65: Narudžbe — telefon, napomena; stavka u jednoj liniji s vrstom i željom');
$px = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0601', 'back' => '?s=narudzbe']);
$o601b = null; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) if ($x['no'] === 'SJ-2026-0601') $o601b = $x;
$nv2 = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
preg_match('/SJ-2026-0601.*?<\/tr>/s', $nv2, $row601);
$ok(str_contains($px, 'je stornirana, pa se ne može označiti plaćenom') && empty($o601b['paidAt']) && isset($row601[0]) && !str_contains($row601[0], 'Označi plaćenom'), 'v64: stornirana narudžba — bez „Označi plaćenom”, a radnja se odbija');
/* v66: starija neplaćena narudžba iz kolekcije (u arhivi) uvijek vidljiva; tel: poveznica; „Privremeno isključi” */
file_put_contents($D . '/narudzbe-arhiva-' . date('Y') . '.json', json_encode([['no' => 'SJ-2026-0500', 'at' => date('c', time() + 5), 'name' => 'Stara Kol', 'email' => 'stara@p.hr', 'phone' => '091 500 0500', 'reservation' => null, 'items' => 'Klasik crna', 'order' => ['items' => [['id' => 'klasik', 'name' => 'Klasik crna', 'made' => true, 'qty' => 1, 'price' => 60]], 'unheld' => []]]]));
$nv3 = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
$tv3 = $req('GET', ['s' => 'torbe']);
$ok(preg_match('/<h3 id="starije">Starije neplaćene \(Stalna kolekcija\) — drže mjesto<\/h3>.*?SJ-2026-0500.*?<h3>Narudžbe<\/h3>/s', $nv3) === 1 && str_contains($nv3, 'href="tel:0915000500"') && str_contains($tv3, 'Privremeno isključi') && !str_contains($tv3, '>Nedostupno<'), 'v66: starija neplaćena narudžba iz kolekcije vidljiva; tel: poveznica; „Privremeno isključi”');
/* v67: nepotvrđena neplaćena ne drži mjesto — nije među starijima; poveznice samo za običnu adresu i broj */
$nl7 = json_decode(file_get_contents($D . '/narudzbe.json'), true);
for ($k = 0; $k < 101; $k++) $nl7[] = ['no' => 'SJ-2026-' . (7000 + $k), 'at' => date('c', time() - 60), 'name' => 'Pun', 'email' => 'p@p.hr', 'items' => 'Luna', 'paidAt' => date('c'), 'order' => ['items' => [['id' => 'luna', 'name' => 'Luna', 'qty' => 1, 'price' => 65]]]];
$nl7[] = ['no' => 'SJ-2026-7900', 'at' => date('c', time() + 5), 'name' => 'Nepotvr', 'email' => 'x?subject=Hitno&cc=evil@evil.com', 'phone' => '091 123 (ili 098 765)', 'unconfirmed' => true, 'items' => 'Klasik crna', 'order' => ['items' => [['id' => 'klasik', 'name' => 'Klasik crna', 'made' => true, 'qty' => 1, 'price' => 60]]]];
array_unshift($nl7, ['no' => 'SJ-2026-7901', 'at' => date('c', time() + 6), 'name' => 'Link', 'email' => 'x?subject=Hitno&cc=evil@evil.com', 'phone' => '091 123 (ili 098 765)', 'items' => 'Luna', 'order' => ['items' => [['id' => 'luna', 'name' => 'Luna', 'qty' => 1, 'price' => 65]]]]);
file_put_contents($D . '/narudzbe.json', json_encode($nl7));
$nv7 = $curl(["http://127.0.0.1:$port/admin/?s=narudzbe"]);
preg_match('/SJ-2026-7901.*?<\/tr>/s', $nv7, $r7);
$tg7 = $req('POST', ['act' => 'toggle', 'id' => 'klasik', 'field' => 'sold', 'to' => '1', 'back' => '?s=torbe']);
$req('POST', ['act' => 'toggle', 'id' => 'klasik', 'field' => 'sold', 'to' => '0', 'back' => '?s=torbe']);
$ok(!str_contains($nv7, 'SJ-2026-7900') && isset($r7[0]) && !str_contains($r7[0], 'mailto:') && !str_contains($r7[0], 'tel:') && str_contains($tg7, 'je privremeno isključen'), 'v67: nepotvrđena neplaćena nije među starijima; bez mailto:/tel: za čudnu adresu i broj; „privremeno isključen”');
$ok(preg_match('/<tr class="is-cancelled"><td class="mono">SJ-2026-0601<br><span class="badge grey">stornirano<\/span>/', $nv2) === 1 && str_contains($req('GET', ['s' => 'torbe']), '<th>Vrsta</th>'), 'v65: stornirana narudžba označena i blijeda; Torbe — stupac „Vrsta”');
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

/* ---------- v68: Rok istekao, razlog storna, Postavke, mjere i podrubrika, rokovi za uplatu ---------- */
$isoAgo = fn(int $h) => gmdate('Y-m-d\TH:i:s\Z', time() - $h * 3600);
file_put_contents($D . '/rezervacije.json', json_encode(['res' => [], 'sold' => []]));
file_put_contents($D . '/narudzbe.json', json_encode([
  ['no' => 'SJ-2026-0901', 'at' => $isoAgo(30), 'name' => 'Rok R', 'email' => 'rok@p.hr', 'total' => '69,00 €', 'items' => "- Luna — 65,00 €\n- Remen — 15,00 €", 'payUntil' => $isoAgo(6), 'reservation' => ['token' => 'tR', 'until' => $isoAgo(6)], 'order' => ['items' => [['id' => 'luna', 'name' => 'Luna', 'qty' => 1, 'price' => 65]]]],
  ['no' => 'SJ-2026-0902', 'at' => $isoAgo(2), 'name' => 'Svjez S', 'email' => 's@p.hr', 'total' => '59,00 €', 'payUntil' => gmdate('Y-m-d\TH:i:s\Z', time() + 22 * 3600), 'reservation' => ['token' => 'tS', 'until' => gmdate('Y-m-d\TH:i:s\Z', time() + 22 * 3600)], 'order' => ['items' => [['id' => 'tara', 'name' => 'Tara', 'qty' => 1, 'price' => 55]]]],
]));
$v = $req('GET', ['s' => 'narudzbe']);
$rokSeg = preg_match('#id="rok"(.*?)<div class="tablewrap">#s', $v, $mr) ? $mr[1] : '';
$ok(str_contains($v, 'Rok istekao (1)') && preg_match('#SJ-2026-0901.*?komad slobodan.*?2026-0901#s', $rokSeg) === 1 && !str_contains($rokSeg, 'SJ-2026-0902') && str_contains($v, 'Narudžbe (1)') && str_contains($rokSeg, '· Luna — 65,00 €, Remen — 15,00 €'), 'v68: Rok istekao — samo narudžba kojoj je prošao rok, s pozivom na broj, „komad slobodan”, artiklima bez crtica i brojem uz Narudžbe');
$out = $req('POST', ['act' => 'order_cancel', 'no' => 'SJ-2026-0901', 'reason' => 'kupac odustao', 'reason_text' => '', 'back' => '?s=narudzbe']);
$o9 = json_decode(file_get_contents($D . '/narudzbe.json'), true)[0];
$ok(($o9['cancelReason'] ?? '') === 'kupac odustao' && ($o9['cancelBy'] ?? '') === 'vlasnica' && str_contains($out, 'razlog: kupac odustao') && !str_contains($out, 'Rok istekao ('), 'v68: storno s razlogom — razlog i tko je stornirao zapisani i prikazani; narudžba više nije pod „Rok istekao”');
$req('POST', ['act' => 'order_cancel', 'no' => 'SJ-2026-0902', 'reason' => 'drugo', 'reason_text' => 'dupla narudžba', 'back' => '?s=narudzbe']);
$ok((json_decode(file_get_contents($D . '/narudzbe.json'), true)[1]['cancelReason'] ?? '') === 'drugo: dupla narudžba', 'v68: razlog „drugo” s upisanim tekstom');
/* Postavke */
$set68 = array_replace($set, ['mail_mode' => 'log', 'shipping' => "boxnow | BOX NOW | 4 | ne | da |\ngls | GLS | 6 | da | ne |\n", 'remind_hours' => '12', 'auto_cancel_hours' => '48', 'signature' => 'Jasmina · Shiva.J', 'has_require_confirm' => '1', 'require_confirm' => '1', 'free_min' => '120', 'free_ids[]' => 'boxnow',
  'sizes' => "22 × 7 cm | Stane mobitel.\n26 × 7 cm | Stane mobitel, novčanik i ključevi.\n", 'size_all' => 'Remen je podesiv, do 120 cm.']);
$req('POST', $set68);
$c68 = json_decode(file_get_contents($D . '/config.json'), true);
$ok(($c68['remind_hours'] ?? 0) === 12 && ($c68['auto_cancel_hours'] ?? 0) === 48 && ($c68['signature'] ?? '') === 'Jasmina · Shiva.J' && !empty($c68['require_confirm'])
  && ($c68['free_ship'] ?? []) == ['min' => 120, 'ids' => ['boxnow']] && count($c68['sizes'] ?? []) === 2 && ($c68['sizes'][1]['opis'] ?? '') === 'Stane mobitel, novčanik i ključevi.' && ($c68['size_all'] ?? '') === 'Remen je podesiv, do 120 cm.', 'v68: Postavke spremaju rokove, potpis, potvrdu e-mailom, besplatnu dostavu, mjere i tekst za sve torbe');
$bad = $req('POST', array_replace($set68, ['remind_hours' => '24']));
$ok(str_contains($bad, 'Podsjetnik: 0 (bez podsjetnika) ili manje sati od roka') && (json_decode(file_get_contents($D . '/config.json'), true)['remind_hours'] ?? 0) === 12, 'v68: podsjetnik ne smije biti nakon roka za uplatu (ništa se ne sprema)');
$c68['require_confirm'] = false; file_put_contents($D . '/config.json', json_encode($c68)); /* ostali testovi bez potvrde e-mailom */
/* obrazac torbe: kućice s mjerama, podrubrika */
$fv = $req('GET', ['s' => 'nova']);
$ok(str_contains($fv, 'name="size" value="26 × 7 cm"') && str_contains($fv, 'value="druga"') && str_contains($fv, 'name="sub"') && str_contains($fv, 'Mini novčanici'), 'v68: obrazac torbe ima kućice s mjerama, „Druga mjera” i podrubriku');
$req('POST', ['act' => 'save_product', 'id' => '', 'name' => 'Okrugla proba', 'price' => '90', 'vrsta' => 'unikat', 'size' => '26 × 7 cm', 'dims' => '', 'back' => '?s=torbe']);
$req('POST', ['act' => 'save_product', 'id' => '', 'name' => 'Novcanic proba', 'price' => '18', 'vrsta' => 'ostalo', 'sub' => 'novcanici', 'size' => 'druga', 'dims' => '12 × 9 cm', 'back' => '?s=torbe']);
$pp = []; foreach (json_decode(file_get_contents($D . '/proizvodi.json'), true) as $x) $pp[$x['name']] = $x;
$ok(($pp['Okrugla proba']['size'] ?? '') === '26 × 7 cm' && ($pp['Okrugla proba']['dims'] ?? '') === '26 × 7 cm' && ($pp['Novcanic proba']['sub'] ?? '') === 'novcanici' && ($pp['Novcanic proba']['dims'] ?? '') === '12 × 9 cm' && !isset($pp['Novcanic proba']['size']), 'v68: torba s kućicom mjere (dims = mjera) i artikl s podrubrikom i drugom mjerom');
/* rokovi za uplatu pri otvaranju admina: podsjetnik, rok istekao, automatski storno */
$c68['deadline_tick'] = true; file_put_contents($D . '/config.json', json_encode($c68)); @unlink($D . '/rokovi.json'); @unlink($D . '/mail-log.txt');
file_put_contents($D . '/rezervacije.json', json_encode(['res' => ['vela' => ['id' => 'vela', 'token' => 'tA80', 'name' => 'A', 'email' => 'a80@p.hr', 'ordered' => true, 'until' => '2099-01-01T00:00:00Z']], 'sold' => []]));
file_put_contents($D . '/narudzbe.json', json_encode([
  ['no' => 'SJ-2026-0911', 'at' => $isoAgo(13), 'payUntil' => $isoAgo(-11), 'name' => 'Pod P', 'email' => 'p13@p.hr', 'total' => '59,00 €', 'reservation' => ['token' => 'tP'], 'order' => ['items' => [['id' => 'tara', 'name' => 'Tara', 'qty' => 1, 'price' => 55]]]],
  ['no' => 'SJ-2026-0912', 'at' => $isoAgo(30), 'payUntil' => $isoAgo(6), 'name' => 'Ist I', 'email' => 'i30@p.hr', 'total' => '64,00 €', 'reservation' => null, 'order' => ['items' => [['id' => 'klasik', 'name' => 'Klasik crna', 'qty' => 1, 'price' => 60, 'made' => true]]]],
  ['no' => 'SJ-2026-0913', 'at' => $isoAgo(80), 'payUntil' => $isoAgo(56), 'expiredAt' => $isoAgo(50), 'name' => 'Auto A', 'email' => 'a80@p.hr', 'total' => '74,00 €', 'reservation' => ['token' => 'tA80'], 'order' => ['items' => [['id' => 'vela', 'name' => 'Vela', 'qty' => 1, 'price' => 70]]]],
  ['no' => 'SJ-2026-0914', 'at' => $isoAgo(13), 'payUntil' => $isoAgo(-11), 'name' => 'Placen P', 'email' => 'pl@p.hr', 'paidAt' => $isoAgo(12), 'total' => '59,00 €', 'order' => ['items' => [['id' => 'mira', 'name' => 'Mira', 'qty' => 1, 'price' => 48]]]],
]));
$req('GET', ['s' => 'narudzbe']);
$nn = []; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) $nn[$x['no']] = $x;
$log = (string)@file_get_contents($D . '/mail-log.txt');
$ok(!empty($nn['SJ-2026-0911']['remindedAt']) && empty($nn['SJ-2026-0911']['expiredAt']) && !empty($nn['SJ-2026-0912']['expiredAt']) && empty($nn['SJ-2026-0912']['cancelledAt'])
  && ($nn['SJ-2026-0913']['cancelBy'] ?? '') === 'automatski' && ($nn['SJ-2026-0913']['cancelReason'] ?? '') === 'nije uplaćeno u roku' && empty($nn['SJ-2026-0914']['remindedAt'])
  && !isset($rd()['res']['vela']), 'v68: rokovi — podsjetnik nakon 12 h, „rok istekao” nakon 24 h, automatski storno 48 h nakon roka (rezervacija oslobođena); plaćena narudžba ništa ne dobiva');
$remSeg = preg_match('/podsjetnik za uplatu, narudžba SJ-2026-0911(.*?)(?=\n=====|\z)/s', $log, $mm) ? $mm[1] : '';
$ok(substr_count($log, 'podsjetnik za uplatu') === 1 && str_contains($remSeg, 'IBAN') && str_contains($remSeg, 'Poziv na broj: 2026-0911') && !str_contains($remSeg, 'image/png') && str_contains($log, 'rok za uplatu je prošao') && preg_match('/prošao je u (ponedjeljak|utorak|srijedu|četvrtak|petak|subotu|nedjelju), \d/u', $log) === 1 && str_contains($log, 'Već ste platili? Nemojte plaćati ponovno') && str_contains($log, 'Ako ste još uvijek zainteresirani za narudžbu, molimo vas da izvršite uplatu i pošaljete potvrdu o plaćanju') && str_contains($log, 'rok za uplatu prošao: SJ-2026-0912') && str_contains($log, 'SJ-2026-0913 je stornirana') && str_contains($log, 'automatski stornirano: SJ-2026-0913') && str_contains($log, 'Jasmina · Shiva.J'), 'v69: e-mailovi rokova (podsjetnik s IBAN-om i pozivom na broj, bez barkoda; „rok je prošao” s tekstom vlasnice; storno kupcu i sažetak vlasnici) s potpisom iz Postavki');
@unlink($D . '/rokovi.json'); $before = strlen((string)@file_get_contents($D . '/mail-log.txt'));
$req('GET', ['s' => 'narudzbe']);
$ok(strlen((string)@file_get_contents($D . '/mail-log.txt')) === $before, 'v68: ponovno otvaranje ne šalje iste poruke dvaput');
/* ---------- v69: nalazi vijeća na v68 (N68-1, N68-3, N68-5, N68-6) i odluke vlasnice ---------- */
$isoIn = fn(int $h) => gmdate('Y-m-d\TH:i:s\Z', time() + $h * 3600);
$mk = fn(string $no, array $x) => array_replace(['no' => $no, 'at' => $isoAgo(30), 'name' => 'K ' . $no, 'email' => strtolower(str_replace('-', '', $no)) . '@p.hr', 'total' => '64,00 €', 'reservation' => null, 'order' => ['total' => 64, 'items' => [['id' => 'klasik', 'name' => 'Klasik crna', 'qty' => 1, 'price' => 60, 'made' => true]]]], $x);
@unlink($D . '/rokovi.json'); @unlink($D . '/mail-log.txt');
file_put_contents($D . '/rezervacije.json', json_encode(['res' => [], 'sold' => []]));
file_put_contents($D . '/narudzbe.json', json_encode([
  $mk('SJ-2026-0920', ['at' => $isoAgo(24 * 40)]),                                                     /* stara, bez payUntil */
  $mk('SJ-2026-0921', ['payUntil' => $isoAgo(60), 'expiredAt' => $isoAgo(50)]),                       /* storno dospio */
  $mk('SJ-2026-0922', ['payUntil' => $isoAgo(60), 'expiredAt' => $isoAgo(50), 'cancelledAt' => $isoAgo(1), 'cancelReason' => 'nije uplaćeno u roku', 'cancelBy' => 'automatski']),
]));
$c68['deadline_tick'] = true; file_put_contents($D . '/config.json', json_encode($c68));
/* klik „Označi plaćenom” ima prednost: POST ne pokreće rokove prije radnje */
$req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0921', 'back' => '?s=narudzbe']);
$req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0922', 'back' => '?s=narudzbe']);
$nn = []; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) $nn[$x['no']] = $x;
$ok(!empty($nn['SJ-2026-0921']['paidAt']) && empty($nn['SJ-2026-0921']['cancelledAt']) && !empty($nn['SJ-2026-0922']['paidAt']) && empty($nn['SJ-2026-0922']['cancelledAt']) && !empty($nn['SJ-2026-0922']['uncancelledAt']),
  'v69 (N68-3): „Označi plaćenom” pobjeđuje dospjeli automatski storno i poništava već upisani automatski storno');
$req('GET', ['s' => 'narudzbe']);
$nn = []; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) $nn[$x['no']] = $x;
$ok(empty($nn['SJ-2026-0920']['cancelledAt']) && empty($nn['SJ-2026-0920']['expiredAt']) && empty($nn['SJ-2026-0920']['remindedAt']) && !str_contains((string)@file_get_contents($D . '/mail-log.txt'), 'SJ-2026-0920'),
  'v69 (N68-1): stara narudžba bez zapisanog roka — rokovi je ne diraju i nitko ne dobiva e-mail');
/* v69.1: kupac je nakon isteka javio uplatu (claimLate) — bez automatskog storna */
$l = json_decode(file_get_contents($D . '/narudzbe.json'), true);
$l[] = $mk('SJ-2026-0923', ['payUntil' => $isoAgo(60), 'expiredAt' => $isoAgo(50), 'claimedAt' => $isoAgo(49), 'claimLate' => true]);
file_put_contents($D . '/narudzbe.json', json_encode($l)); @unlink($D . '/rokovi.json');
$req('GET', ['s' => 'narudzbe']);
$o923 = null; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) if ($x['no'] === 'SJ-2026-0923') $o923 = $x;
$ok($o923 && empty($o923['cancelledAt']), 'v69.1: narudžba za koju je kupac nakon isteka javio uplatu ne stornira se automatski');
/* „Ukloni oznaku”: novi rok, bez automatskog storna */
$req('POST', ['act' => 'order_unpaid', 'no' => 'SJ-2026-0921', 'back' => '?s=narudzbe']);
$l = json_decode(file_get_contents($D . '/narudzbe.json'), true); foreach ($l as &$x) if ($x['no'] === 'SJ-2026-0921') { $x['payUntil'] = $isoAgo(60); } unset($x);
file_put_contents($D . '/narudzbe.json', json_encode($l)); @unlink($D . '/rokovi.json');
$v69 = $req('GET', ['s' => 'narudzbe']);
$nn = []; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) $nn[$x['no']] = $x;
$ok(!empty($nn['SJ-2026-0921']['unpaidAt']) && empty($nn['SJ-2026-0921']['cancelledAt']) && str_contains($v69, 'bez automatskog storna (oznaka plaćeno je uklonjena)'),
  'v69 (N68-3): nakon „Ukloni oznaku” narudžba ostaje pod „Rok istekao” za ručnu odluku, bez automatskog storna');
$ok(substr_count($v69, 'SJ-2026-0921') >= 2 && preg_match('#<table.*?SJ-2026-0921.*?odluka na kartici „Rok istekao” gore#s', $v69) === 1 && str_contains($v69, '<details class="cancelbox"><summary>Storniraj narudžbu…</summary>'),
  'v69 (D68-9): istekla narudžba u tablici upućuje na karticu; storno se otvara tek na „Storniraj narudžbu…”');
/* najviše 20 e-mailova po prolazu */
$many = []; for ($k = 30; $k < 55; $k++) $many[] = $mk('SJ-2026-09' . $k, ['payUntil' => $isoAgo(2)]);
file_put_contents($D . '/narudzbe.json', json_encode($many)); @unlink($D . '/rokovi.json');
$req('GET', ['s' => 'narudzbe']);
$ne = count(array_filter(json_decode(file_get_contents($D . '/narudzbe.json'), true), fn($x) => !empty($x['expiredAt'])));
@unlink($D . '/rokovi.json'); $req('GET', ['s' => 'narudzbe']);
$ne2 = count(array_filter(json_decode(file_get_contents($D . '/narudzbe.json'), true), fn($x) => !empty($x['expiredAt'])));
$ok($ne === 20 && $ne2 === 25, 'v69 (N68-6): u jednom prolazu najviše 20 e-mailova kupcima, ostatak u sljedećem (' . $ne . ', ' . $ne2 . ')');
$c68['deadline_tick'] = false; file_put_contents($D . '/config.json', json_encode($c68));
/* storno s e-mailom kupcu (kvačica), bez e-maila za „lažna narudžba” */
@unlink($D . '/mail-log.txt');
file_put_contents($D . '/narudzbe.json', json_encode([$mk('SJ-2026-0960', ['payUntil' => $isoIn(10)]), $mk('SJ-2026-0961', ['payUntil' => $isoIn(10)])]));
$m1 = $req('POST', ['act' => 'order_cancel', 'no' => 'SJ-2026-0960', 'reason' => 'kupac odustao', 'mail' => '1', 'back' => '?s=narudzbe']);
$m2 = $req('POST', ['act' => 'order_cancel', 'no' => 'SJ-2026-0961', 'reason' => 'lažna narudžba', 'mail' => '1', 'back' => '?s=narudzbe']);
$log = (string)@file_get_contents($D . '/mail-log.txt');
$ok(str_contains($log, 'SJ-2026-0960 je stornirana') && str_contains($log, 'stornirana na vaš zahtjev') && !str_contains($log, 'SJ-2026-0961') && str_contains($m1, 'Kupac je dobio e-mail o stornu') && str_contains($m2, '(lažna narudžba)'),
  'v69: ručni storno — kupac dobiva e-mail kad je kvačica uključena, nikad za „lažna narudžba”');
/* „Storniraj sve s ove adrese” i narudžbe samo iz Stalne kolekcije */
file_put_contents($D . '/narudzbe.json', json_encode([$mk('SJ-2026-0970', ['payUntil' => $isoIn(10), 'ip' => 'feedfacecafe0001'])]));
$relC = $req('POST', ['act' => 'res_release_ip', 'ip' => 'feedfacecafe0001', 'back' => '?s=rez']);
$o70 = json_decode(file_get_contents($D . '/narudzbe.json'), true)[0];
$ok(!empty($o70['cancelledAt']) && ($o70['cancelReason'] ?? '') === 'lažna narudžba' && str_contains($relC, '1 narudžba'), 'v69 (N68-2): „Storniraj sve s ove adrese” stornira i narudžbu samo iz Stalne kolekcije (razlog „lažna narudžba”)');
file_put_contents($D . '/narudzbe.json', json_encode([$mk('SJ-2026-0971', ['payUntil' => $isoIn(20), 'ip' => 'feedfacecafe0002', 'claimedAt' => $isoAgo(1)]), $mk('SJ-2026-0972', ['payUntil' => $isoIn(20), 'ip' => 'feedfacecafe0002'])]));
$relD = $req('POST', ['act' => 'res_release_ip', 'ip' => 'feedfacecafe0002', 'back' => '?s=rez']);
$nn = []; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) $nn[$x['no']] = $x;
$ok(empty($nn['SJ-2026-0971']['cancelledAt']) && !empty($nn['SJ-2026-0972']['cancelledAt']) && str_contains($relD, 'Nisu stornirane narudžbe za koje je kupac javio uplatu: SJ-2026-0971'), 'v69 (N69-7): skupni storno ne dira narudžbu za koju je kupac javio uplatu');
$vC = $req('GET', ['s' => 'narudzbe']);
$ok(preg_match('#kupac javio uplatu</span> <span class="muted">[^<]*— provjerite izvod</span><br><span class="muted">rok za uplatu do [^<]*\(još \d+ h\)#u', $vC) === 1, 'v69 (D69-9): otvorena narudžba — „kupac javio uplatu” prvi, zatim rok za uplatu s preostalim satima');
/* v70: „Potvrda primljena” — rok do 23:59 sljedećeg radnog dana (najviše 96 h), najviše dvaput, 7 dana od narudžbe;
   komad koji je otišao drugome se ne uzima; trag proofAt[]; kratki e-mail kupcu (kvačica) */
@unlink($D . '/mail-log.txt');
$uq = fn(string $no, array $x) => $mk($no, array_replace(['reservation' => ['token' => 't' . $no, 'until' => $isoAgo(2)], 'order' => ['total' => 65, 'items' => [['id' => 'luna', 'name' => 'Luna', 'qty' => 1, 'price' => 65]]]], $x));
file_put_contents($D . '/rezervacije.json', json_encode(['res' => ['tara' => ['token' => 'drugi', 'until' => $isoIn(10), 'ordered' => true, 'id' => 'tara']], 'sold' => []]));
file_put_contents($D . '/narudzbe.json', json_encode([
  $uq('SJ-2026-0980', ['payUntil' => $isoAgo(2), 'expiredAt' => $isoAgo(2), 'claimLate' => true, 'claimedAt' => $isoAgo(1)]),
  $uq('SJ-2026-0981', ['at' => $isoAgo(24 * 8), 'payUntil' => $isoAgo(24 * 7)]),
  $uq('SJ-2026-0982', ['payUntil' => $isoAgo(2), 'order' => ['total' => 55, 'items' => [['id' => 'tara', 'name' => 'Tara', 'qty' => 1, 'price' => 55]]]]),
  $mk('SJ-2026-0983', ['payUntil' => $isoIn(5), 'paidAt' => $isoAgo(1)]),
]));
$vP = $req('GET', ['s' => 'narudzbe']);
$ok(preg_match('#data-confirm="Kupac je poslao snimku potvrde za SJ-2026-0980, a uplata još nije na izvodu\? Torbu čuvamo do [^"]+ u \d\d:\d\d\.[^"]*"#u', $vP) === 1 && str_contains($vP, 'Pošalji kupcu kratku potvrdu e-mailom') && substr_count($vP, '<input type="hidden" name="act" value="order_proof">') === 2,
  'v70: „Potvrda primljena” na kartici „Rok istekao” s točnim datumom u potvrdi; nema je za staru (8 dana) ni za plaćenu');
$p1 = $req('POST', ['act' => 'order_proof', 'no' => 'SJ-2026-0980', 'mail' => '1', 'back' => '?s=narudzbe']);
$nn = []; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) $nn[$x['no']] = $x;
$o80 = $nn['SJ-2026-0980']; $pu = (int)strtotime($o80['payUntil']); $rz = json_decode(file_get_contents($D . '/rezervacije.json'), true);
$zg = (new DateTimeImmutable('@' . $pu))->setTimezone(new DateTimeZone('Europe/Zagreb'));
$ok(str_contains($p1, 'SJ-2026-0980: potvrda zabilježena') && str_contains($p1, 'Kupac je dobio kratku potvrdu') && $pu > time() && ($zg->format('H:i') === '23:59' || abs($pu - (time() + 96 * 3600)) < 120) && $pu <= time() + 96 * 3600 + 5
  && count($o80['proofAt'] ?? []) === 1 && empty($o80['expiredAt']) && empty($o80['claimLate']) && ($rz['res']['luna']['until'] ?? '') === $o80['payUntil'] && ($rz['res']['luna']['token'] ?? '') === 'tSJ-2026-0980'
  && str_contains((string)@file_get_contents($D . '/mail-log.txt'), 'primili smo vašu potvrdu o uplati za narudžbu br. SJ-2026-0980. Torbu čuvamo za vas do'),
  'v70: „Potvrda primljena” — rok do 23:59 radnog dana (najviše 96 h), torba ponovno rezervirana, trag proofAt, e-mail kupcu');
$p2 = $req('POST', ['act' => 'order_proof', 'no' => 'SJ-2026-0980', 'back' => '?s=narudzbe']);
$p3 = $req('POST', ['act' => 'order_proof', 'no' => 'SJ-2026-0980', 'back' => '?s=narudzbe']);
$p4 = $req('POST', ['act' => 'order_proof', 'no' => 'SJ-2026-0981', 'back' => '?s=narudzbe']);
$p5 = $req('POST', ['act' => 'order_proof', 'no' => 'SJ-2026-0983', 'back' => '?s=narudzbe']);
$p6 = $req('POST', ['act' => 'order_proof', 'no' => 'SJ-2026-0982', 'back' => '?s=narudzbe']);
$nn = []; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) $nn[$x['no']] = $x;
$rz = json_decode(file_get_contents($D . '/rezervacije.json'), true);
$ok(str_contains($p2, 'Kupac nije dobio e-mail') && str_contains($p3, 'dvaput') && count($nn['SJ-2026-0980']['proofAt']) === 2 && str_contains($p4, 'više od 7 dana') && empty($nn['SJ-2026-0981']['proofAt']) && str_contains($p5, 'već plaćena'),
  'v70: „Potvrda primljena” najviše dvaput, ne nakon 7 dana od narudžbe ni za plaćenu');
$ok(str_contains($p6, 'PAŽNJA: „Tara”') && ($rz['res']['tara']['token'] ?? '') === 'drugi', 'v70: „Potvrda primljena” ne uzima torbu rezerviranu za drugog kupca (upozorenje)');
/* N70-1: popunjeno mjesto u Stalnoj kolekciji — „Potvrda primljena” ne produžuje istekle narudžbe */
$pr = json_decode(file_get_contents($D . '/proizvodi.json'), true); $prOld = $pr;
foreach ($pr as &$pp) if (($pp['id'] ?? '') === 'klasik') $pp['made_limit'] = 1; unset($pp);
file_put_contents($D . '/proizvodi.json', json_encode($pr));
$nAll = json_decode(file_get_contents($D . '/narudzbe.json'), true);
$nAll[] = $mk('SJ-2026-0990', ['payUntil' => $isoIn(10)]); $nAll[] = $mk('SJ-2026-0991', ['payUntil' => $isoAgo(2)]);
file_put_contents($D . '/narudzbe.json', json_encode($nAll)); @unlink($D . '/made-counts.json');
$pF = $req('POST', ['act' => 'order_proof', 'no' => 'SJ-2026-0991', 'back' => '?s=narudzbe']);
$o91 = null; foreach (json_decode(file_get_contents($D . '/narudzbe.json'), true) as $x) if ($x['no'] === 'SJ-2026-0991') $o91 = $x;
file_put_contents($D . '/proizvodi.json', json_encode($prOld));
$ok(str_contains($pF, 'je u međuvremenu popunjeno') && empty($o91['proofAt']) && (int)strtotime($o91['payUntil']) < time(), 'v70 (N70-1): „Potvrda primljena” ne produžuje narudžbu kad je mjesto u kolekciji popunjeno');
$vP2 = $req('GET', ['s' => 'narudzbe']);
$ok(str_contains($vP2, 'potvrda primljena</span>'), 'v70: u retku narudžbe oznaka „potvrda primljena” s rokom');
/* v71: „Označi plaćenom” s kvačicom — kupac dobiva e-mail „uplata je stigla” (jednom), rok slanja iz Postavki */
@unlink($D . '/mail-log.txt');
$cP = json_decode(file_get_contents($D . '/config.json'), true); $cP['ship_lead'] = '1–2 radna dana'; file_put_contents($D . '/config.json', json_encode($cP));
file_put_contents($D . '/rezervacije.json', json_encode(['res' => [], 'sold' => []]));
file_put_contents($D . '/narudzbe.json', json_encode([
  $uq('SJ-2026-0995', ['payUntil' => $isoIn(10), 'order' => ['total' => 65, 'shipping' => ['id' => 'boxnow'], 'items' => [['id' => 'luna', 'name' => 'Luna', 'qty' => 1, 'price' => 65]]]]),
  $mk('SJ-2026-0996', ['payUntil' => $isoIn(10)]),
]));
$vM = $req('GET', ['s' => 'narudzbe']);
$q1 = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0995', 'mail' => '1', 'back' => '?s=narudzbe']);
$req('POST', ['act' => 'order_unpaid', 'no' => 'SJ-2026-0995', 'back' => '?s=narudzbe']);
$q1b = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0995', 'mail' => '1', 'back' => '?s=narudzbe']);
$q2 = $req('POST', ['act' => 'order_paid', 'no' => 'SJ-2026-0996', 'back' => '?s=narudzbe']);
$logP = (string)@file_get_contents($D . '/mail-log.txt');
$ok(substr_count($vM, 'Pošalji kupcu e-mail da je uplata stigla') >= 2 && str_contains($q1, 'Kupac je dobio e-mail da je uplata stigla') && substr_count($logP, 'uplata je stigla, narudžba SJ-2026-0995') === 1
  && str_contains($logP, 'uplata za narudžbu br. SJ-2026-0995 je stigla, hvala! Paket obično šaljemo za 1–2 radna dana; kad krene, poslat ćemo vam poruku s potvrdom slanja') && str_contains($q1b, 'već ranije dobio e-mail') && str_contains($q2, 'Kupac nije dobio e-mail') && !str_contains($logP, 'SJ-2026-0996'),
  'v71: „Označi plaćenom” s kvačicom šalje „uplata je stigla” jednom (rok slanja iz Postavki); bez kvačice ne šalje');
/* zastarjela kartica Postavki ne isključuje potvrdu e-mailom */
$c68['require_confirm'] = true; file_put_contents($D . '/config.json', json_encode($c68));
$old = $set68; unset($old['has_require_confirm'], $old['require_confirm']);
$req('POST', $old);
$ok(!empty(json_decode(file_get_contents($D . '/config.json'), true)['require_confirm']), 'v69 (N68-6): Postavke iz zastarjele kartice (bez kućice) ne isključuju potvrdu e-mailom');
$c68 = json_decode(file_get_contents($D . '/config.json'), true); $c68['require_confirm'] = false; $c68['deadline_tick'] = false; file_put_contents($D . '/config.json', json_encode($c68));

$c68['deadline_tick'] = false; file_put_contents($D . '/config.json', json_encode($c68));

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
