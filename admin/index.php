<?php
/* =========================================================
   SHIVA.J — ADMIN   (admin/index.php v02)
   Prijava lozinkom (postavlja se pri prvom otvaranju), torbe i
   artikli s fotografijama, rezervacije (Plaćeno / Storniraj),
   narudžbe, postavke. Sprema u data/ i objavljuje torbe.json.
   ========================================================= */
declare(strict_types=1);
require __DIR__ . '/lib.php';
sj_session_start();
$c = sj_config();
/* v22: poruka nakon radnje dolazi iz sesije (jednokratno), ne iz adrese — link s ?m= ne može podmetnuti lažnu poruku */
$msg = sj_clean((string)($_SESSION['sj_flash'] ?? ''), 2000); /* v62: dulji popisi (npr. Storniraj sve s ove adrese) */ unset($_SESSION['sj_flash']);
$err = '';

/* ---------- prvo pokretanje: lozinka ---------- */
if ($c['password_hash'] === '') {
  /* oštećen config.json ne smije otvoriti novo postavljanje lozinke (preuzimanje admina) */
  if (sj_config_corrupt()) {
    sj_layout('Greška', '<p>Datoteka <code>data/config.json</code> postoji, ali nije čitljiva. Vratite je iz sigurnosne kopije ili je (ako ste sigurni) obrišite uz novu datoteku <code>data/PRVA-PRIJAVA.txt</code>, pa ponovno otvorite admin.</p>', $c, '', '', false);
    exit;
  }
  /* lozinka se smije postaviti samo dok postoji data/PRVA-PRIJAVA.txt (u paketu za prijenos);
     briše se čim je lozinka postavljena */
  if (!is_file(sj_setup_path())) {
    sj_layout('Admin nije postavljen', '<p>Lozinka još nije postavljena, a datoteka <code>data/PRVA-PRIJAVA.txt</code> ne postoji. Prenesite je iz paketa „prvi” ili je napravite sami u mapi <code>data/</code>: upišite redak <code>kod: </code> i iza njega barem 16 nasumičnih slova i brojki (npr. iz generatora lozinki), spremite i osvježite ovu stranicu.</p>', $c, '', '', false);
    exit;
  }
  $code = sj_setup_code();
  if ($code === '') {
    sj_layout('Admin nije postavljen', '<p>U datoteci <code>data/PRVA-PRIJAVA.txt</code> nema koda za prvu prijavu. Otvorite je u File Manageru i dodajte redak <code>kod: </code> i iza njega barem 16 nasumičnih znakova (slova i brojke, ne riječi koje se mogu pogoditi), spremite i osvježite ovu stranicu.</p>', $c, '', '', false);
    exit;
  }
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['setup_password'])) {
    $p1 = (string)$_POST['setup_password']; $p2 = (string)($_POST['setup_password2'] ?? '');
    usleep(300000);
    /* v22: ograničen broj promašaja koda (po adresi i ukupno); broje se samo promašaji */
    /* v25: bez obzira na velika i mala slova (mobilna tipkovnica prvo slovo sama pretvori u veliko) */
    $codeOk = hash_equals(strtolower($code), strtolower(trim((string)($_POST['setup_code'] ?? ''))));
    /* v23: ukupna granica samo usporava (napadač s mnogo adresa ne smije zaključati i vlasnicu), po adresi blokira */
    if (!sj_global_limit('setup', 50, 3600, false)) sleep(3);
    if (!sj_rate_limit('setup', 10, 3600, false, false)) $err = 'Previše pogrešnih pokušaja koda s ove veze. Pričekajte sat vremena i pokušajte ponovno.';
    elseif (!$codeOk) { sj_rate_limit('setup', 10, 3600); sj_global_limit('setup', 50, 3600); $err = 'Kod nije točan. Prepišite ga iz datoteke data/PRVA-PRIJAVA.txt.'; }
    elseif (strlen($p1) < 10) $err = 'Lozinka mora imati barem 10 znakova.';
    elseif ($p1 !== $p2) $err = 'Lozinke se ne podudaraju.';
    else {
      $c['password_hash'] = password_hash($p1, PASSWORD_DEFAULT);
      sj_save_config($c);
      @unlink(sj_setup_path());
      sj_login($p1);
      $_SESSION['sj_flash'] = 'Lozinka je postavljena. Dobrodošli!';
      header('Location: ?s=torbe'); exit;
    }
  }
  sj_layout('Prva prijava', '
    <p>Ovo je prvo otvaranje admina. Upišite kod za prvu prijavu: isti je u datoteci <code>…-KOD-PRVE-PRIJAVE.txt</code> koja je stigla uz paket i u <code>data/PRVA-PRIJAVA.txt</code> (redak „kod: …”). Zatim odaberite lozinku (barem 10 znakova) i zapišite je na sigurno. Nakon toga se datoteka <code>data/PRVA-PRIJAVA.txt</code> briše i ovaj se korak više ne može ponoviti bez nje.</p>
    <form method="post" class="card form">
      <label>Kod iz datoteke data/PRVA-PRIJAVA.txt <input name="setup_code" required autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
      <label>Nova lozinka <input type="password" name="setup_password" required minlength="10" autocomplete="new-password"></label>
      <label>Ponovite lozinku <input type="password" name="setup_password2" required minlength="10" autocomplete="new-password"></label>
      <button>Spremi lozinku</button>
    </form>', $c, '', $err, false);
  exit;
}

/* v20: lozinka postoji — zaostala PRVA-PRIJAVA.txt (npr. paket „prvi” raspakiran preko žive stranice) se briše */
if (is_file(sj_setup_path())) @unlink(sj_setup_path());

/* ---------- prijava / odjava ---------- */
if (isset($_GET['odjava'])) { sj_logout(); header('Location: ./'); exit; }
if (!sj_logged_in()) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    if (sj_login((string)$_POST['password'])) { header('Location: ?s=torbe'); exit; }
    $left = sj_login_left();
    $err = sj_login_locked() ? 'Previše pogrešnih pokušaja s ove veze. Pričekajte 15 minuta i pokušajte ponovno (i točna lozinka do tada ne prolazi).'
      : 'Pogrešna lozinka.' . ($left <= 3 ? ' Još ' . $left . ($left === 1 ? ' pokušaj' : ' pokušaja') . ', zatim prijava 15 minuta čeka.' : '');
  }
  sj_layout('Prijava', '
    <form method="post" class="card form">
      <label>Lozinka <input type="password" name="password" required autocomplete="current-password" autofocus></label>
      <button>Prijava</button>
    </form>
    <p class="muted">Zaboravljena lozinka: u File Manageru otvorite <code>data/config.json</code>, vrijednost <code>"password_hash"</code> promijenite u <code>""</code>, zatim u mapi <code>data/</code> napravite <code>PRVA-PRIJAVA.txt</code> s retkom <code>kod: </code> i barem 16 nasumičnih slova i brojki, pa ponovno otvorite admin. Ako vas je admin odjavio dok ste nešto upisivali, prijavite se i upišite ponovno (sesija istječe nakon oko pola sata bez rada).</p>', $c, '', $err, false);
  exit;
}

/* v68: rokovi za uplatu (podsjetnik, rok istekao, automatski storno) i pri otvaranju admina */
try { sj_deadline_tick(); } catch (Throwable $e) { @file_put_contents(SJ_DATA . '/greske.log', gmdate('c') . ' rokovi: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX); }

/* ---------- radnje ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!sj_check_csrf()) {
    $err = 'Sesija je istekla ili je obrazac zastario. Pokušajte ponovno.';
  } else {
    try {
      $msg = sj_handle_action((string)($_POST['act'] ?? ''), $c);
      $back = (string)($_POST['back'] ?? '?s=torbe');
      if (!preg_match('/^\?[A-Za-z0-9=&_-]*$/', $back)) $back = '?s=torbe';
      $_SESSION['sj_flash'] = $msg;
      header('Location: ' . $back); exit;
    } catch (Throwable $ex) {
      $err = $ex->getMessage();
      $c = sj_config(true); /* v26: ništa nije spremljeno */
      /* v28: obrazac Postavki pokazuje ono što je vlasnica upisala (samo za prikaz; ništa se ne sprema) */
      if (($_POST['act'] ?? '') === 'save_settings') $c = sj_settings_draft($c, $_POST);
    }
  }
}

$s = (string)($_GET['s'] ?? 'torbe');
if ($err !== '' && ($_POST['act'] ?? '') === 'save_settings') $s = 'postavke'; /* v28: greška u Postavkama ostaje na Postavkama */
switch ($s) {
  case 'nova':
  case 'uredi':    sj_layout($s === 'nova' ? 'Nova torba / artikl' : 'Uredi', sj_view_form($c, $s === 'uredi' ? (string)($_GET['id'] ?? '') : ''), $c, $msg, $err); break;
  case 'rez':      sj_layout('Rezervacije', sj_view_reservations($c), $c, $msg, $err); break;
  case 'narudzbe': sj_layout('Narudžbe', sj_view_orders(), $c, $msg, $err); break;
  case 'postavke': sj_layout('Postavke', sj_view_settings($c), $c, $msg, $err); break;
  case 'mjerenje': sj_layout('Mjerenje', sj_view_stats(), $c, $msg, $err); break;
  default:         sj_layout('Torbe i artikli', sj_view_products(), $c, $msg, $err);
}
exit;

/* =========================================================
   RADNJE
   ========================================================= */
function sj_handle_action(string $act, array &$c): string {
  switch ($act) {

    case 'save_product': {
      /* cijela izmjena pod bravom popisa proizvoda (sj_products_update) */
      /* v23: isključeno „Prodano” u obrascu briše i zapis „plaćeno” (kao „Vrati u prodaju”) */
      $unsold = '';
      $out = sj_products_update(function (array &$list) use (&$unsold) {
      $oldId = sj_clean((string)($_POST['id'] ?? ''), 40);
      $name = sj_clean((string)($_POST['name'] ?? ''), 80);
      if ($name === '') throw new RuntimeException('Naziv je obavezan.');
      $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
      if ($price < 0) $price = 0.0;
      $made = !empty($_POST['made']);
      $catIn = (($_POST['cat'] ?? 'torbe') === 'ostalo') ? 'ostalo' : 'torbe';
      /* v63: jedan izbor „Vrsta” (unikat / Stalna kolekcija / Ostalo) umjesto kategorije i kvačice */
      $vrsta = (string)($_POST['vrsta'] ?? '');
      if ($vrsta === 'unikat') { $catIn = 'torbe'; $made = false; }
      elseif ($vrsta === 'kolekcija') { $catIn = 'torbe'; $made = true; }
      elseif ($vrsta === 'ostalo') { $catIn = 'ostalo'; $made = true; }
      $p = [
        'id'       => '',
        'cat'      => $catIn,
        'name'     => $name,
        'desc'     => sj_clean((string)($_POST['desc'] ?? ''), 200),
        'price'    => round($price, 2),
        'dims'     => sj_clean((string)($_POST['dims'] ?? ''), 80),
        'material' => sj_clean((string)($_POST['material'] ?? ''), 160),
        'tagline'  => sj_clean((string)($_POST['tagline'] ?? ''), 60),
        'story'    => sj_clean((string)($_POST['story'] ?? ''), 400, true),
        'imgs'     => [],
        'sold'     => !empty($_POST['sold']),
      ];
      if ($made) { $p['made'] = true; $p['lead'] = sj_clean((string)($_POST['lead'] ?? ''), 60);
        /* v62: Stalna kolekcija — najviše toliko narudžbi dok vlasnica ne otvori nova mjesta */
        $ml = trim((string)($_POST['made_limit'] ?? '')); $p['made_limit'] = $ml === '' ? 3 : max(0, min(99, (int)$ml)); } /* v63: prazno = 3 */
      /* v68: mjera iz kućica (Postavke → Mjere i opisi) ili „Druga mjera” (slobodan upis u Dimenzije) */
      $sz = sj_clean((string)($_POST['size'] ?? ''), 40);
      if ($sz !== '' && $sz !== 'druga' && in_array($sz, array_column(sj_sizes(), 'mjera'), true)) { $p['size'] = $sz; $p['dims'] = $sz; }
      /* v68: podrubrika Ostalog */
      $sub = (string)($_POST['sub'] ?? '');
      if ($catIn === 'ostalo' && isset(sj_subs()[$sub])) $p['sub'] = $sub;
      if (!empty($_POST['reserved']) && !$made) $p['reserved'] = true;
      if (!empty($_POST['hidden'])) $p['hidden'] = true;
      if (!empty($_POST['hero'])) $p['hero'] = true;
      if ($p['tagline'] === '') unset($p['tagline']);
      if ($p['story'] === '') unset($p['story']);

      $idx = null;
      foreach ($list as $i => $x) if (($x['id'] ?? '') === $oldId && $oldId !== '') $idx = $i;
      if ($idx !== null) { $p['id'] = $oldId; $p['imgs'] = array_values((array)($list[$idx]['imgs'] ?? [])); if ($made && !empty($list[$idx]['made_open_from'])) $p['made_open_from'] = $list[$idx]['made_open_from']; }
      else $p['id'] = sj_unique_id(sj_slug($name), $list);

      $remove = array_map('strval', (array)($_POST['remove_img'] ?? []));
      $p['imgs'] = array_values(array_filter($p['imgs'], fn($im) => !in_array($im, $remove, true)));

      if (!empty($_FILES['photos']['tmp_name']) && is_array($_FILES['photos']['tmp_name'])) {
        foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
          if (!$tmp || !is_uploaded_file($tmp)) continue;
          if ((int)($_FILES['photos']['size'][$i] ?? 0) > 20 * 1024 * 1024) throw new RuntimeException('Fotografija je veća od 20 MB.');
          $path = sj_store_image($tmp, $p['id']);
          if (!$path) throw new RuntimeException('Fotografija nije prepoznata (JPG, PNG ili WEBP).');
          $p['imgs'][] = $path;
        }
      }
      $cover = (string)($_POST['cover'] ?? '');
      if ($cover !== '' && in_array($cover, $p['imgs'], true)) $p['imgs'] = array_values(array_merge([$cover], array_values(array_diff($p['imgs'], [$cover]))));

      if ($idx !== null && !empty($list[$idx]['sold']) && empty($p['sold']) && empty($p['made'])) $unsold = $p['id'];
      if ($idx !== null) $list[$idx] = $p; else $list[] = $p;
      /* naslovnica: samo jedna torba */
      if (!empty($p['hero'])) foreach ($list as $i => $x) if (($x['id'] ?? '') !== $p['id']) unset($list[$i]['hero']);
      return 'Spremljeno: „' . $name . '”.';
      });
      if ($unsold !== '') sj_with_lock(sj_res_path(), function (array &$d) use ($unsold) {
        $d = array_replace(sj_res_default(), $d); unset($d['sold'][$unsold]); return true; }, sj_res_default());
      return $out;
    }

    case 'delete_product': {
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      $nm = $id; foreach (sj_products() as $p) if (($p['id'] ?? '') === $id) $nm = (string)($p['name'] ?? $id);
      sj_products_update(function (array &$list) use ($id) { $list = array_values(array_filter($list, fn($p) => ($p['id'] ?? '') !== $id)); });
      return 'Obrisano: „' . $nm . '” (fotografije ostaju u mapi img).';
    }

    case 'move': {
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      $dir = ((string)($_POST['dir'] ?? '') === 'up') ? -1 : 1;
      sj_products_update(function (array &$list) use ($id, $dir) {
        foreach ($list as $i => $p) {
          if (($p['id'] ?? '') === $id) {
            $j = $i + $dir;
            if ($j >= 0 && $j < count($list)) [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            break;
          }
        }
      });
      return 'Redoslijed je promijenjen.';
    }

    case 'made_open': {
      /* v62: vlasnica otvara nova mjesta za model Stalne kolekcije — brojanje narudžbi kreće od sada */
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      $nm = '';
      sj_products_update(function (array &$list) use ($id, &$nm) {
        foreach ($list as $i => $p) if (($p['id'] ?? '') === $id) { $list[$i]['made_open_from'] = sj_iso(time()); $nm = (string)($p['name'] ?? $id); return true; }
        throw new RuntimeException('Torba nije nađena.');
      });
      $pp = null; foreach (sj_products() as $p) if (($p['id'] ?? '') === $id) $pp = $p;
      return 'Otvorena su nova mjesta za „' . $nm . '”: ' . sj_count_words($pp ? sj_made_limit($pp) : 3, 'narudžba', 'narudžbe', 'narudžbi') . ' od sada.';
    }

    case 'toggle': {
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      $field = (string)($_POST['field'] ?? '');
      if (!in_array($field, ['sold', 'hidden', 'reserved'], true)) throw new RuntimeException('Nepoznato polje.');
      /* v21: izričita vrijednost (to=1/0) — ponovljeni klik (vlasnica nije sigurna je li uspjelo) ništa ne mijenja */
      $to = isset($_POST['to']) ? ($_POST['to'] === '1') : null;
      /* v22: „Vrati u prodaju” (unikat) briše i zapis „plaćeno” — inače torba na stranici i dalje ne bi bila u prodaji */
      if ($field === 'sold' && $to === false) sj_with_lock(sj_res_path(), function (array &$d) use ($id) {
        $d = array_replace(sj_res_default(), $d); unset($d['sold'][$id]); return true; }, sj_res_default());
      return sj_products_update(function (array &$list) use ($id, $field, $to) {
        foreach ($list as $i => $p) {
          if (($p['id'] ?? '') === $id) {
            $on = $to ?? empty($p[$field]);
            if ($on) $list[$i][$field] = true; else unset($list[$i][$field]);
            if ($field === 'sold' && !$on) $list[$i]['sold'] = false;
            $n = (string)($p['name'] ?? $id); $made = !empty($p['made']);
            [$kn, $f] = sj_kind($p); $a = $f ? 'a' : ''; /* rod slaže imenica: „Torba … skrivena”, „Artikl … skriven” */
            return [
              'sold' => $on ? ($made ? "{$kn} „{$n}” je privremeno isključen{$a}: kupci " . ($f ? 'je' : 'ga') . " vide s oznakom „Trenutno nedostupno”, ali ga ne mogu naručiti." : "{$kn} „{$n}” je trajno prodan{$a} i više se ne nudi kupcima.")
                            : ($made ? "{$kn} „{$n}” je ponovno dostup" . ($f ? 'na' : 'an') . " za narudžbu." : "{$kn} „{$n}” je vraćen{$a} u prodaju."),
              'hidden' => $on ? "{$kn} „{$n}” je skriven{$a} i ne vidi se na stranici." : "{$kn} „{$n}” se ponovno vidi na stranici.",
              'reserved' => $on ? "{$kn} „{$n}” je ručno označen{$a} kao rezerviran{$a}." : "Ručna rezervacija za „{$n}” je uklonjena.",
            ][$field];
          }
        }
        throw new RuntimeException('Torba nije nađena.');
      });
    }

    case 'res_release_ip': {
      /* sve rezervacije s iste skraćene adrese odjednom (sumnja na lažne narudžbe) */
      $ip = sj_clean((string)($_POST['ip'] ?? ''), 32);
      if (!preg_match('/^[a-f0-9]{16}$/', $ip)) throw new RuntimeException('Neispravna adresa.');
      $n = sj_with_lock(sj_res_path(), function (array &$d) use ($ip) {
        $d = array_replace(sj_res_default(), $d);
        $n = []; /* v29: tko je storniran — da se vlasnica može javiti */
        foreach ($d['res'] as $id => $r) if (sj_same_ip($r, [$ip])) { unset($d['res'][$id]); $n[] = [(string)$id, (string)($r['email'] ?? ''), (string)($r['token'] ?? '')]; sj_order_cancelled((string)($r['token'] ?? '')); } /* i zapisi s prošlotjednom oznakom iste adrese */
        return $n;
      }, sj_res_default());
      $who = [];
      foreach ($n as [$id, $em, $tk]) { $on = $tk !== '' ? (string)(sj_order_find(['token' => $tk])['no'] ?? '') : ''; $who[] = sj_product_names([$id]) . ($on !== '' ? ' ' . $on : '') . ($em !== '' ? ' — ' . $em : ''); }
      return 'Stornirano s iste adrese: ' . sj_count_words(count($n), 'rezervacija', 'rezervacije', 'rezervacija') . '. Torbe su ponovno u prodaji.' . ($who ? ' Kupci ne dobivaju automatsku poruku; javite im se: ' . implode('; ', $who) . '.' : '');
    }

    case 'res_release':
    case 'res_sold':
    case 'res_unsold':
    case 'res_sold_manual': {
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      if ($act === 'res_sold_manual' && $id === '') throw new RuntimeException('Odaberite torbu.');
      if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) throw new RuntimeException('Neispravna oznaka torbe.');
      if ($act === 'res_sold_manual') {
        $pm = null; foreach (sj_products() as $p) if (($p['id'] ?? '') === $id) $pm = $p;
        if (!$pm) throw new RuntimeException('Torba nije nađena.');
        if (!empty($pm['made'])) throw new RuntimeException('Artikli po narudžbi ne prodaju se ručno; označite ih nedostupnima u popisu Torbe.');
      }
      if ($act === 'res_unsold') foreach (sj_products() as $p) if (($p['id'] ?? '') === $id && !empty($p['sold']))
        throw new RuntimeException('Torba je trajno prodana. Ako je zaista treba vratiti, kliknite „Vrati u prodaju”.');
      $ok = sj_with_lock(sj_res_path(), function (array &$d) use ($act, $id) {
        $d = array_replace(sj_res_default(), $d);
        if ($act === 'res_sold' && isset($d['res'][$id]) && empty($d['res'][$id]['ordered'])) return 'unconfirmed';
        if ($act === 'res_release') { $tk = (string)($d['res'][$id]['token'] ?? ''); $em = (string)($d['res'][$id]['email'] ?? ''); unset($d['res'][$id]); if (!empty($tk)) sj_order_cancelled($tk); return ['email' => $em, 'token' => $tk]; }
        elseif ($act === 'res_unsold') {
          /* v27: kao „Ukloni oznaku” u Narudžbama — narudžba kojoj više nijedna torba nije plaćena gubi oznaku plaćeno */
          $sd = $d['sold'][$id] ?? []; unset($d['sold'][$id]);
          sj_order_unpaid_if_empty($d['sold'], (string)($sd['token'] ?? ''), (string)($sd['no'] ?? ''));
          return ['email' => (string)($sd['email'] ?? ''), 'token' => (string)($sd['token'] ?? ''), 'no' => (string)($sd['no'] ?? '')];
        }
        else return sj_mark_paid($d, $id, $act === 'res_sold_manual');
        return true;
      }, sj_res_default());
      if ($ok === 'unconfirmed') throw new RuntimeException('Ova torba je samo u nečijoj košarici (narudžba nije potvrđena), pa se ne može označiti plaćenom.');
      $relEmail = is_array($ok) ? (string)($ok['email'] ?? '') : '';
      /* v62: broj narudžbe i e-mail kupca i u ovim porukama (kao na linku iz e-maila) */
      $relNo = is_array($ok) ? (string)($ok['no'] ?? '') : '';
      if ($relNo === '' && is_array($ok) && ($ok['token'] ?? '') !== '') $relNo = (string)(sj_order_find(['token' => (string)$ok['token']])['no'] ?? '');
      $relTxt = ($relNo !== '' ? " (narudžba {$relNo})" : '');
      $callTxt = $relEmail !== '' ?  " Kupac ne dobiva automatsku poruku — javite se kupcu: {$relEmail}." : '';
      /* v63: storno rezervacije stornira cijelu narudžbu — reći ako se time oslobađa i mjesto u Stalnoj kolekciji */
      $collFree = '';
      if ($act === 'res_release' && is_array($ok) && ($ok['token'] ?? '') !== '') {
        $oR = sj_order_find(['token' => (string)$ok['token']]); $cIds = [];
        if ($oR) { $byIdR = []; foreach (sj_products() as $pp) $byIdR[(string)($pp['id'] ?? '')] = $pp;
          foreach ((array)($oR['order']['items'] ?? []) as $itR) { $pp = $byIdR[(string)($itR['id'] ?? '')] ?? null; if ($pp && sj_is_collection($pp)) $cIds[] = (string)$itR['id']; } }
        if ($cIds) $collFree = ' Narudžba je stornirana cijela: oslobođeno je i mjesto za ' . sj_product_names($cIds) . '.';
      }
      if ($act === 'res_sold_manual' && !$ok) throw new RuntimeException('Torba je rezervirana za kupca ili već označena kao plaćena. Rezerviranu torbu označite gumbom „Plaćeno” u tablici iznad ili je prvo stornirajte.');
      /* v22: prodana izvan stranice = odmah i trajno prodana (torba je već predana) */
      if ($act === 'res_sold_manual') sj_products_update(function (array &$list) use ($id) {
        foreach ($list as $i => $p) if (($p['id'] ?? '') === $id) { $list[$i]['sold'] = true; break; } return true; });
      $nm = $id; foreach (sj_products() as $p) if (($p['id'] ?? '') === $id) $nm = (string)($p['name'] ?? $id);
      return ['res_release' => "Rezervacija za „{$nm}”{$relTxt} je stornirana; torba je ponovno u prodaji." . $collFree . $callTxt, 'res_sold' => "„{$nm}” je označena kao plaćena" . (($soldNo = (string)(sj_res_read()['sold'][$id]['no'] ?? '')) !== '' ? " (narudžba {$soldNo})" : '') . '.',
        'res_unsold' => "Oznaka plaćeno za „{$nm}”{$relTxt} je uklonjena (i na narudžbi); torba je ponovno u prodaji. Ako je kupac platio, vratite mu novac." . $callTxt . sj_other_paid($id, $relNo), 'res_sold_manual' => "„{$nm}” je označena kao prodana izvan stranice i više se ne nudi kupcima."][$act];
    }

    case 'order_cancel': {
      /* v63: storno bilo koje neplaćene narudžbe (i one samo iz Stalne kolekcije, bez rezervacije) — oslobađa mjesta */
      $no = sj_clean((string)($_POST['no'] ?? ''), 20);
      if (!preg_match('/^SJ-\d{4}-\d{4}$/', $no)) throw new RuntimeException('Neispravan broj narudžbe.');
      $ord = sj_order_find(['no' => $no]);
      if (!$ord) throw new RuntimeException('Narudžba nije nađena.');
      if (!empty($ord['paidAt'])) throw new RuntimeException('Narudžba je označena plaćenom: prvo uklonite oznaku (i vratite novac kupcu).');
      if (!empty($ord['cancelledAt'])) return 'Narudžba ' . $no . ' je već stornirana.';
      $tok = (string)($ord['reservation']['token'] ?? '');
      if ($tok !== '') sj_with_lock(sj_res_path(), function (array &$d) use ($tok) { $d = array_replace(sj_res_default(), $d); foreach ($d['res'] as $id => $r) if (($r['token'] ?? '') === $tok) unset($d['res'][$id]); return true; }, sj_res_default());
      /* v68: razlog storna (izbor + slobodan tekst za „drugo”) ide uz narudžbu */
      $why = (string)($_POST['reason'] ?? '');
      if (!in_array($why, sj_cancel_reasons(), true)) $why = '';
      $whyTxt = sj_clean((string)($_POST['reason_text'] ?? ''), 120);
      if ($why === 'drugo' && $whyTxt !== '') $why = 'drugo: ' . $whyTxt;
      sj_order_edit(['no' => $no], function (array &$o) use ($why) { if (empty($o['cancelledAt'])) { $o['cancelledAt'] = sj_iso(time()); $o['cancelReason'] = $why; $o['cancelBy'] = 'vlasnica'; } });
      $coll = []; $byId = []; foreach (sj_products() as $p) $byId[(string)($p['id'] ?? '')] = $p;
      foreach ((array)($ord['order']['items'] ?? []) as $it) { $p = $byId[(string)($it['id'] ?? '')] ?? null; if ($p && sj_is_collection($p)) $coll[] = (string)$it['id']; }
      return 'Narudžba ' . $no . ' je stornirana' . ($tok !== '' ? '; njezine torbe su ponovno u prodaji' : '') . ($coll ? '; oslobođeno je mjesto za ' . sj_product_names($coll) : '') . '. Kupac ne dobiva automatsku poruku — javite se kupcu: ' . (string)($ord['email'] ?? '') . '.';
    }

    case 'order_paid':
    case 'order_unpaid': {
      /* „Plaćeno” / „Ukloni oznaku” za cijelu narudžbu (i staru, iz arhive) */
      $no = sj_clean((string)($_POST['no'] ?? ''), 20);
      if (!preg_match('/^SJ-\d{4}-\d{4}$/', $no)) throw new RuntimeException('Neispravan broj narudžbe.');
      $ord = sj_order_find(['no' => $no]); /* i u arhivi (starije od zadnjih 300) */
      if (!$ord) throw new RuntimeException('Narudžba nije nađena.');
      /* v64: stornirana narudžba više ne drži torbu ni mjesto — „plaćeno” bi isto mjesto prodalo dvaput */
      if ($act === 'order_paid' && !empty($ord['cancelledAt'])) throw new RuntimeException('Narudžba ' . $no . ' je stornirana, pa se ne može označiti plaćenom (njezina torba i mjesto su oslobođeni). Ako je kupac ipak uplatio, javite mu se: neka naruči ponovno ili mu vratite uplatu.');
      $tok = (string)($ord['reservation']['token'] ?? '');
      $ids = [];
      foreach ((array)($ord['order']['items'] ?? []) as $it) if (empty($it['made']) && !empty($it['id'])) $ids[] = (string)$it['id'];
      /* zapis „plaćeno” pripada ovoj narudžbi: isti token rezervacije ili broj narudžbe (torbe bez rezervacije) */
      $isOurs = function (array $sd) use ($tok, $no) { return ($tok !== '' && ($sd['token'] ?? '') === $tok) || ($sd['no'] ?? '') === $no; };
      if ($act === 'order_paid') {
        /* „Plaćeno” za cijelu narudžbu: torbe s rezervacijom ove narudžbe postaju plaćene (kao kod rezervacija);
           torbe bez rezervacije (narudžba je stigla kad je pola ponude već čekalo uplatu) ili s isteklom rezervacijom
           pripadaju ovom kupcu ako je uplatio prvi — tuđa rezervacija na njima se uklanja i vlasnica dobiva upozorenje.
           Takva torba koja u katalogu više nije na prodaji (ručno prodana ili rezervirana, skrivena, obrisana) se ne
           upisuje, nego se navodi u upozorenju. */
        $cat = [];
        foreach (sj_products() as $p) if (!empty($p['id'])) $cat[(string)$p['id']] = $p;
        $out = sj_with_lock(sj_res_path(), function (array &$d) use ($ids, $tok, $ord, $no, $cat, $isOurs) {
          $d = array_replace(sj_res_default(), $d);
          $taken = []; $other = []; $offsale = [];
          foreach ($ids as $id) {
            if (isset($d['sold'][$id])) {
              $sd = $d['sold'][$id];
              $ours = $isOurs($sd) || strcasecmp((string)($sd['email'] ?? ''), (string)($ord['email'] ?? '')) === 0;
              if (!$ours) $taken[] = $id; /* već plaćena za drugog kupca */
              continue;
            }
            $r = $d['res'][$id] ?? null;
            if ($r && $tok !== '' && ($r['token'] ?? '') === $tok) { sj_mark_paid($d, $id); continue; }
            $p = $cat[$id] ?? null;
            if (!$p || !empty($p['hidden']) || !empty($p['sold']) || !empty($p['reserved'])) { $offsale[] = $id; continue; }
            if ($r && !empty($r['ordered'])) $other[] = sj_product_names([$id]) . ' (' . ($r['name'] ?? '') . ', ' . ($r['email'] ?? '') . ')';
            unset($d['res'][$id]);
            $d['sold'][$id] = ['id' => $id, 'token' => $tok, 'no' => $no, 'name' => (string)($ord['name'] ?? ''), 'email' => (string)($ord['email'] ?? ''), 'since' => (string)($ord['at'] ?? ''), 'paidAt' => sj_iso(time())];
          }
          return ['taken' => $taken, 'other' => $other, 'offsale' => $offsale];
        }, sj_res_default());
        if (sj_order_paid_once(['no' => $no])) sj_count('paid');
        $warn = [];
        $nmOf = function (array $ids) { $n = []; foreach (sj_products() as $p) $n[(string)($p['id'] ?? '')] = (string)($p['name'] ?? ''); return implode(', ', array_map(fn($i) => '„' . (($n[$i] ?? '') !== '' ? $n[$i] : $i) . '”', $ids)); };
        if ($out['taken']) $warn[] = $nmOf($out['taken']) . ' je već plaćena za drugog kupca — javite se kupcu iz ove narudžbe';
        if ($out['offsale']) $warn[] = 'u katalogu nisu na prodaji (prodane, rezervirane, skrivene ili obrisane) pa nisu označene: ' . $nmOf($out['offsale']) . ' — provjerite i javite se kupcu';
        if ($out['other']) $warn[] = 'uklonjena je neplaćena rezervacija drugog kupca: ' . implode('; ', $out['other']) . ' — javite mu se da je torba prodana';
        if ($warn) return 'Označeno kao plaćeno: ' . $no . '. PAŽNJA: ' . implode('. ', $warn) . '.';
      } else {
        /* „Ukloni oznaku”: narudžba više nije plaćena, a njezine torbe s oznakom „plaćeno” vraćaju se u prodaju
           (bez rezervacije). paidCounted ostaje: brojač se ne umanjuje ni ne udvostručuje. */
        $back = sj_with_lock(sj_res_path(), function (array &$d) use ($ids, $isOurs) {
          $d = array_replace(sj_res_default(), $d);
          $out = [];
          foreach ($ids as $id) if (isset($d['sold'][$id]) && $isOurs($d['sold'][$id])) { unset($d['sold'][$id]); $out[] = $id; }
          return $out;
        }, []);
        sj_order_edit(['no' => $no], function (array &$o) { unset($o['paidAt']); });
        if ($back) return 'Oznaka uklonjena: ' . $no . '. ' . (count($back) > 1 ? 'Torbe ' . sj_product_names($back) . ' vraćene su' : 'Torba ' . sj_product_names($back) . ' vraćena je') . ' u prodaju bez rezervacije.' . implode('', array_map(fn($b) => sj_other_paid((string)$b, $no), $back));
      }
      return ($act === 'order_paid' ? 'Označeno kao plaćeno: ' : 'Oznaka uklonjena: ') . $no;
    }

    case 'save_settings': {
      $owner = sj_clean((string)($_POST['owner_email'] ?? ''), 120);
      if (!filter_var($owner, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail vlasnice nije ispravan.');
      $from = sj_clean((string)($_POST['from_email'] ?? ''), 120);
      if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresa pošiljatelja nije ispravna.');
      $ttl = (int)($_POST['ttl_hours'] ?? 24);
      if ($ttl < 1 || $ttl > 168) throw new RuntimeException('Rok rezervacije: 1 do 168 sati.');
      $iban = strtoupper(preg_replace('/\s+/', '', (string)($_POST['iban'] ?? '')) ?? '');
      if (!preg_match('/^HR\d{19}$/', $iban)) throw new RuntimeException('IBAN mora imati oblik HR + 19 znamenki.');
      $c['owner_email'] = $owner;
      $c['from_email'] = $from;
      $site = rtrim(sj_clean((string)($_POST['site_url'] ?? ''), 120), '/');
      /* v26: adresa bez https:// dobiva ga; neispravna se ne sprema (linkovi u e-mailovima ne bi radili) */
      if ($site !== '' && !preg_match('#^https?://#i', $site)) $site = 'https://' . $site;
      if ($site !== '' && !filter_var($site, FILTER_VALIDATE_URL)) throw new RuntimeException('Adresa stranice nije ispravna (npr. https://shivaj.hr).');
      $c['site_url'] = $site;
      $oib = preg_replace('/\s+/', '', (string)($_POST['oib'] ?? '')) ?? '';
      if ($oib !== '' && !sj_oib_ok($oib)) throw new RuntimeException('OIB nije ispravan (11 znamenki, zadnja je kontrolna) — provjerite ga ili ostavite prazno.');
      $c['oib'] = $oib;
      $c['ttl_hours'] = $ttl;
      $c['confirm_customer'] = !empty($_POST['confirm_customer']);
      $c['show_ostalo'] = !empty($_POST['show_ostalo']);
      $c['mail_mode'] = (($_POST['mail_mode'] ?? 'mail') === 'log') ? 'log' : 'mail';
      $c['payment']['recipient'] = sj_clean((string)($_POST['recipient'] ?? ''), 80);
      $c['payment']['address'] = sj_clean((string)($_POST['address'] ?? ''), 120);
      $c['payment']['iban'] = $iban;
      $c['payment']['model'] = sj_clean((string)($_POST['model'] ?? 'HR00'), 4) ?: 'HR00';
      $c['pickup_info'] = sj_clean((string)($_POST['pickup_info'] ?? ''), 200);
      $c['ship_lead'] = sj_clean((string)($_POST['ship_lead'] ?? ''), 60);
      /* dostava: jedan način po retku  id | naziv | cijena | adresa (da/ne) | paketomat (da/ne) | napomena */
      $ship = [];
      foreach (preg_split('/\R/', (string)($_POST['shipping'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $f = array_map('trim', explode('|', $line));
        $id = sj_clean($f[0] ?? '', 40);
        if (!preg_match('/^[a-z0-9_-]+$/', $id)) throw new RuntimeException('Dostava: oznaka „' . $id . '” smije imati samo mala slova, znamenke, - i _.');
        $price = (float)str_replace(',', '.', $f[2] ?? '0');
        if ($price < 0 || !is_numeric(str_replace(',', '.', $f[2] ?? '0'))) throw new RuntimeException('Dostava: cijena za „' . $id . '” nije broj.');
        $yes = fn($v) => in_array(mb_strtolower(trim((string)$v)), ['da', 'yes', '1', 'true'], true);
        $ship[] = ['id' => $id, 'label' => sj_clean($f[1] ?? $id, 80) ?: $id, 'price' => round($price, 2), 'address' => $yes($f[3] ?? ''), 'locker' => $yes($f[4] ?? ''), 'note' => sj_clean($f[5] ?? '', 200)];
      }
      $c['shipping'] = $ship;
      /* v68: rokovi, potpis, potvrda e-mailom, besplatna dostava, mjere i opisi */
      $rem = (int)($_POST['remind_hours'] ?? 12); $auto = (int)($_POST['auto_cancel_hours'] ?? 48);
      if ($rem < 0 || $rem >= $ttl) throw new RuntimeException('Podsjetnik: 0 (bez podsjetnika) ili manje sati od roka za uplatu (' . $ttl . ').');
      if ($auto < 0 || $auto > 336) throw new RuntimeException('Automatski storno: 0 (nikad) do 336 sati nakon isteka roka.');
      $c['remind_hours'] = $rem; $c['auto_cancel_hours'] = $auto;
      $c['signature'] = sj_clean((string)($_POST['signature'] ?? ''), 60);
      $c['require_confirm'] = !empty($_POST['require_confirm']);
      $fmin = (float)str_replace(',', '.', (string)($_POST['free_min'] ?? '0'));
      if ($fmin < 0) throw new RuntimeException('Prag besplatne dostave ne može biti negativan.');
      $ids = array_column($ship, 'id');
      $c['free_ship'] = ['min' => round($fmin, 2), 'ids' => array_values(array_filter(array_map('strval', (array)($_POST['free_ids'] ?? [])), fn($x) => in_array($x, $ids, true)))];
      $sizes = [];
      foreach (preg_split('/\R/', (string)($_POST['sizes'] ?? '')) as $line) {
        if (trim($line) === '') continue;
        $f = array_map('trim', explode('|', $line, 2));
        $m = sj_clean($f[0] ?? '', 40); if ($m === '') continue;
        $sizes[] = ['mjera' => $m, 'opis' => sj_clean($f[1] ?? '', 600)];
      }
      $c['sizes'] = array_slice($sizes, 0, 20);
      $c['size_all'] = sj_clean((string)($_POST['size_all'] ?? ''), 600, true);
      sj_save_config($c);
      return 'Postavke su spremljene.';
    }

    case 'change_password': {
      $old = (string)($_POST['old_password'] ?? ''); $n1 = (string)($_POST['new_password'] ?? ''); $n2 = (string)($_POST['new_password2'] ?? '');
      if (!password_verify($old, $c['password_hash'])) throw new RuntimeException('Stara lozinka nije točna.');
      if (strlen($n1) < 10) throw new RuntimeException('Nova lozinka mora imati barem 10 znakova.');
      if ($n1 !== $n2) throw new RuntimeException('Nove lozinke se ne podudaraju.');
      $c['password_hash'] = password_hash($n1, PASSWORD_DEFAULT);
      sj_save_config($c);
      /* v20: ovaj preglednik ostaje prijavljen, svi ostali se odjavljuju; v22: i novi ID sesije */
      session_regenerate_id(true);
      $_SESSION['sj_pw'] = hash('sha256', 'sj-sesija|' . $c['password_hash']);
      return 'Lozinka promijenjena (ostali preglednici su odjavljeni)';
    }

    case 'test_mail': {
      $ok = sj_send_mail($c['owner_email'], $c['shop'] . ' — probna poruka iz admina', "Ovo je probna poruka. Ako je stigla, slanje e-maila s hostinga radi.\n\n" . sj_site_url() . '/admin/');
      return $ok ? 'Probna poruka poslana na ' . $c['owner_email'] . ($c['mail_mode'] === 'log' ? ' (način: log, vidi data/mail-log.txt)' : '') : 'Slanje nije uspjelo — provjerite postavke e-maila na hostingu.';
    }
  }
  throw new RuntimeException('Nepoznata radnja.');
}

/* =========================================================
   PRIKAZI
   ========================================================= */
function sj_btn(string $act, array $fields, string $label, string $back, bool $alt = false, string $confirm = ''): string {
  /* tekst potvrde ide kao JSON literal: apostrof ili navodnik u nazivu ne mogu razbiti JS ni ubaciti kod */
  /* v23: potvrda kao data-confirm (čita je jedna skripta s nonceom), bez inline JS-a — CSP bez 'unsafe-inline' za skripte */
  $h = '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>';
  $h .= '<input type="hidden" name="csrf" value="' . e(sj_csrf()) . '"><input type="hidden" name="act" value="' . e($act) . '"><input type="hidden" name="back" value="' . e($back) . '">';
  foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
  $h .= '<button' . ($alt ? ' class="alt"' : '') . '>' . e($label) . '</button></form>';
  return $h;
}

/* v68: „Storniraj” s razlogom (izbor iz sj_cancel_reasons; za „drugo” kratak tekst) */
function sj_cancel_form(string $no, string $back, string $confirm, string $label = 'Storniraj narudžbu'): string {
  $o = '<option value="">— razlog storna —</option>'; foreach (sj_cancel_reasons() as $r) $o .= '<option value="' . e($r) . '">' . e($r) . '</option>';
  return '<form method="post" class="inline cancelf" data-confirm="' . e($confirm) . '"><input type="hidden" name="csrf" value="' . e(sj_csrf()) . '"><input type="hidden" name="act" value="order_cancel"><input type="hidden" name="back" value="' . e($back) . '"><input type="hidden" name="no" value="' . e($no) . '">'
    . '<select name="reason" required aria-label="Razlog storna">' . $o . '</select><input name="reason_text" maxlength="120" placeholder="ako „drugo”: koji razlog" aria-label="Drugi razlog"><button class="alt">' . e($label) . '</button></form>';
}
/* v68: neplaćene narudžbe kojima je prošao rok za uplatu (popis „Rok istekao”) */
function sj_expired_orders(): array {
  return array_values(array_filter(sj_read_json(SJ_DATA . '/narudzbe.json', []), fn($o) => empty($o['unconfirmed']) && sj_order_expired($o)));
}

function sj_state_badges(array $p, bool $busy = false): string {
  $b = [];
  if (!empty($p['hidden'])) $b[] = '<span class="badge grey">skriveno</span>';
  if (!empty($p['sold'])) $b[] = '<span class="badge ink">' . (!empty($p['made']) ? 'privremeno isključeno' : 'prodano') . '</span>';
  elseif (!empty($p['reserved'])) $b[] = '<span class="badge">rezervirano (ručno)</span>';
  if (!empty($p['made'])) $b[] = '<span class="badge green">po narudžbi</span>';
  /* v26: unikat bez fotografije kupci ne mogu naručiti; plaćena / rezervirana torba nije „u prodaji” */
  $noPhoto = empty($p['made']) && empty($p['imgs']);
  if (empty($p['sold']) && $noPhoto) $b[] = '<span class="badge grey" title="Dodajte fotografiju (Uredi)">bez fotografije — nije u prodaji</span>';
  elseif (!$busy && empty($p['sold']) && empty($p['reserved']) && empty($p['hidden']) && empty($p['made'])) $b[] = '<span class="badge green">u prodaji</span>';
  return implode(' ', $b);
}

function sj_view_products(): string {
  $list = sj_products();
  $res = sj_res_read();
  $h = '<p class="muted">Ono što je ovdje spremljeno odmah je na stranici (datoteka torbe.json). Skrivene torbe ostaju ovdje, ali nisu na stranici.</p>';
  $h .= '<p><a class="btn" href="?s=nova">+ Nova torba / artikl</a></p>';
  if (!$list) return $h . '<p class="muted">Još nema torbi.</p>';
  $h .= '<div class="tablewrap"><table class="cards t-tor"><thead><tr><th></th><th>Naziv</th><th>Vrsta</th><th>Cijena</th><th>Stanje</th><th></th></tr></thead><tbody>';
  $n = count($list);
  foreach ($list as $i => $p) {
    $id = (string)($p['id'] ?? '');
    $img = !empty($p['imgs'][0]) ? '<img src="../' . e($p['imgs'][0]) . '" alt="">' : '<span class="noimg">' . e(mb_substr((string)($p['name'] ?? '?'), 0, 1)) . '</span>';
    $live = !empty($p['sold']) ? '' : (isset($res['sold'][$id]) ? '<span class="badge ink">plaćeno (rez.)</span>' : (isset($res['res'][$id]) ? '<span class="badge">rezervirano do ' . e(sj_fmt_hr($res['res'][$id]['until'])) . '</span>' : ''));
    $back = '?s=torbe';
    $h .= '<tr' . (!empty($p['hidden']) ? ' class="dim"' : '') . '>';
    $h .= '<td class="thumb">' . $img . '</td>';
    $h .= '<td><b>' . e($p['name'] ?? '') . '</b><br><span class="muted">' . e($p['desc'] ?? '') . '</span><br><span class="muted mono">' . e($id) . '</span></td>';
    $h .= '<td>' . e(($p['cat'] ?? 'torbe') === 'ostalo' ? 'Ostalo' : (sj_is_collection($p) ? 'Stalna kolekcija' : 'Unikat')) . '</td>'; /* v65: isti nazivi kao izbor „Vrsta” */
    $h .= '<td class="mono">' . e(sj_fmt_eur((float)($p['price'] ?? 0))) . '</td>';
    $noDims = empty($p['made']) && (($p['cat'] ?? 'torbe') === 'torbe') && !preg_match('/\S/', preg_replace('/^\{.*\}$/', '', trim((string)($p['dims'] ?? ''))));
    /* v62: Stalna kolekcija — iskorištena mjesta od zadnjeg otvaranja; Ostalo skriveno dok je prekidač isključen */
    $collP = sj_is_collection($p) && empty($p['sold']);
    $usedP = $collP ? sj_made_used($id, (string)($p['made_open_from'] ?? '')) : 0;
    $collB = $collP ? ' <span class="badge' . ($usedP >= sj_made_limit($p) ? ' ink' : '') . '" title="Zaprimljene narudžbe od zadnjeg otvaranja mjesta">mjesta ' . $usedP . '/' . sj_made_limit($p) . ($usedP >= sj_made_limit($p) ? ' — popunjeno' : '') . '</span>' : '';
    $ostB = (($p['cat'] ?? 'torbe') === 'ostalo' && empty(sj_config()['show_ostalo'])) ? ' <span class="badge grey" title="Uključite u Postavkama">Ostalo — skriveno na stranici</span>' : '';
    $h .= '<td>' . sj_state_badges($p, isset($res['sold'][$id]) || isset($res['res'][$id])) . ' ' . $live . $collB . $ostB . ($noDims && empty($p['sold']) ? ' <span class="badge grey" title="Kupci traže mjere prije uplate">bez mjera</span>' : '') . '</td>';
    $h .= '<td class="acts"><a class="btn small" href="?s=uredi&id=' . e($id) . '">Uredi</a> ';
    /* v22: isti nazivi kao u Rezervacijama, oba smjera uz potvrdu */
    $nmP = (string)($p['name'] ?? $id); $madeP = !empty($p['made']); $soldP = !empty($p['sold']);
    $h .= sj_btn('toggle', ['id' => $id, 'field' => 'sold', 'to' => $soldP ? '0' : '1'],
      $soldP ? ($madeP ? 'Ponovno uključi' : 'Vrati u prodaju') : ($madeP ? 'Privremeno isključi' : 'Označi trajno prodanom') /* v66: radnja, ne stanje */, $back, true,
      $soldP ? ($madeP ? "„{$nmP}” ponovno dostupan za narudžbu?" : "Vratiti „{$nmP}” u prodaju? Torba će se ponovno nuditi kupcima." . (!empty($res['sold'][$id]['name']) ? ' Plaćena je za kupca ' . $res['sold'][$id]['name'] . ($res['sold'][$id]['no'] ?? '' ? ' (' . $res['sold'][$id]['no'] . ')' : '') . ' — ako mu vraćate novac, uklonite i oznaku u Narudžbama.' : ''))
             : ($madeP ? "Privremeno isključiti „{$nmP}”? Ostaje na stranici s oznakom „Trenutno nedostupno”." : "Označiti „{$nmP}” trajno prodanom? Više se neće nuditi kupcima."));
    if ($collP && $usedP >= sj_made_limit($p)) $h .= sj_btn('made_open', ['id' => $id], 'Otvori nova mjesta', $back, true, 'Otvoriti ' . sj_count_words(sj_made_limit($p), 'novo mjesto', 'nova mjesta', 'novih mjesta') . ' za „' . $nmP . '”? Dosadašnje narudžbe ostaju, brojanje kreće od sada.');
    $h .= sj_btn('toggle', ['id' => $id, 'field' => 'hidden', 'to' => !empty($p['hidden']) ? '0' : '1'], !empty($p['hidden']) ? 'Prikaži' : 'Sakrij', $back, true);
    /* v66: prazno mjesto umjesto izostavljene strelice — gumbi ne „skaču” od retka do retka */
    $h .= $i > 0 ? sj_btn('move', ['id' => $id, 'dir' => 'up'], '↑', $back, true) : '<span class="arrow-gap" aria-hidden="true"></span>';
    $h .= $i < $n - 1 ? sj_btn('move', ['id' => $id, 'dir' => 'down'], '↓', $back, true) : '<span class="arrow-gap" aria-hidden="true"></span>';
    $h .= str_replace('<button class="alt">', '<button class="alt danger">', sj_btn('delete_product', ['id' => $id], 'Obriši', $back, true, 'Obrisati „' . ($p['name'] ?? $id) . '“? Fotografije ostaju u mapi img.'));
    $h .= '</td></tr>';
  }
  return $h . '</tbody></table></div>';
}

function sj_view_form(array $c, string $id): string {
  $p = ['id' => '', 'cat' => 'torbe', 'name' => '', 'desc' => '', 'price' => '', 'dims' => '', 'material' => 'umjetna koža', 'tagline' => '', 'story' => '', 'imgs' => [], 'sold' => false, 'lead' => ''];
  if ($id !== '') {
    foreach (sj_products() as $x) if (($x['id'] ?? '') === $id) $p = array_replace($p, $x);
    if ($p['id'] === '') return '<p>Torba nije nađena.</p>';
  }
  $made = !empty($p['made']);
  $h = '<form method="post" enctype="multipart/form-data" class="card form">';
  $h .= '<input type="hidden" name="csrf" value="' . e(sj_csrf()) . '"><input type="hidden" name="act" value="save_product"><input type="hidden" name="id" value="' . e($p['id']) . '"><input type="hidden" name="back" value="?s=torbe">';
  $h .= '<div class="grid2">';
  $h .= '<label>Naziv * <input name="name" required maxlength="80" value="' . e($p['name']) . '"></label>';
  $h .= '<label>Cijena (€) * <input name="price" required inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,2})?" title="Samo broj, npr. 65 ili 65,50 (bez znaka €)" placeholder="npr. 65" value="' . e($p['price'] === '' ? '' : number_format((float)$p['price'], 2, ',', '')) . '"></label>';
  $vr = ($p['cat'] ?? 'torbe') === 'ostalo' ? 'ostalo' : ($made ? 'kolekcija' : 'unikat');
  $h .= '<label>Vrsta <select name="vrsta" id="vrsta">'
    . '<option value="unikat"' . ($vr === 'unikat' ? ' selected' : '') . '>Unikat — rubrika Torbe (jedan primjerak)</option>'
    . '<option value="kolekcija"' . ($vr === 'kolekcija' ? ' selected' : '') . '>Stalna kolekcija — šije se po narudžbi</option>'
    . '<option value="ostalo"' . ($vr === 'ostalo' ? ' selected' : '') . '>Ostalo — remeni, torbice, platnene (po narudžbi)</option></select></label>';
  $h .= '</div>';
  $h .= '<label>Kratki opis <input name="desc" maxlength="200" value="' . e($p['desc']) . '" placeholder="npr. Okrugla torba · patchwork u pastelnim tonovima"></label>';
  $h .= '<div class="grid2">';
  /* v68: kućice s mjerama iz Postavki; „Druga mjera” = slobodan upis */
  $sizes = sj_sizes(); $curSz = (string)($p['size'] ?? '');
  if ($curSz === '' && $p['dims'] !== '' && in_array((string)$p['dims'], array_column($sizes, 'mjera'), true)) $curSz = (string)$p['dims'];
  if ($sizes) {
    $h .= '<fieldset class="sizes" style="grid-column:1/-1;border:0;padding:0;margin:0 0 12px"><legend class="muted" style="font-size:.85rem;margin-bottom:6px">Mjera (kućice i opisi: Postavke → Mjere i opisi)</legend><div class="row" style="margin-top:0">';
    foreach ($sizes as $sx) $h .= '<label class="check chip"><input type="radio" name="size" value="' . e($sx['mjera']) . '"' . ($curSz === $sx['mjera'] ? ' checked' : '') . '> ' . e($sx['mjera']) . '</label>';
    $h .= '<label class="check chip"><input type="radio" name="size" value="druga"' . ($curSz === '' ? ' checked' : '') . '> Druga mjera</label></div></fieldset>';
  }
  $h .= '<label id="dimsWrap"' . ($sizes && $curSz !== '' ? ' hidden' : '') . '>' . ($sizes ? 'Druga mjera' : 'Dimenzije') . ' <input name="dims" maxlength="80" value="' . e($curSz !== '' ? '' : $p['dims']) . '" placeholder="npr. 30 × 30 × 10 cm"></label>';
  $subSel = '<option value="">— bez podrubrike —</option>'; foreach (sj_subs() as $k => $v) $subSel .= '<option value="' . e($k) . '"' . (($p['sub'] ?? '') === $k ? ' selected' : '') . '>' . e($v) . '</option>';
  $h .= '<label data-vrsta="ostalo">Podrubrika (Ostalo) <select name="sub">' . $subSel . '</select></label>';
  $h .= '<label>Materijal <input name="material" maxlength="160" placeholder="npr. umjetna koža" value="' . e($p['material']) . '"></label>';
  $h .= '<label data-vrsta="kolekcija ostalo">Rok izrade <input name="lead" maxlength="60" value="' . e($p['lead'] ?? '') . '" placeholder="npr. 5 radnih dana"></label>';
  $h .= '<label data-vrsta="kolekcija">Najviše mjesta (1 mjesto = 1 torba; prazno = 3; 0 = ne prima narudžbe; nova mjesta otvarate u popisu Torbe) <input name="made_limit" type="number" min="0" max="99" inputmode="numeric" value="' . e((string)($p['made_limit'] ?? 3)) . '"></label>';
  $h .= '<label>Karakter u jednom retku (na kartici, do 60 znakova) <input name="tagline" maxlength="60" value="' . e($p['tagline'] ?? '') . '" placeholder="npr. Mirna, s jednim zlatnim odsjajem."><small class="muted">Jedna rečenica o karakteru torbe; ne ponavljajte vrstu torbe (okrugla, ruksak…), ona je već u opisu.</small></label>';
  $h .= '<div class="stack">';
  $h .= '<label class="check"><input type="checkbox" name="sold" value="1"' . (!empty($p['sold']) ? ' checked' : '') . '> Prodano / trenutno nedostupno</label>';
  $h .= '<label class="check"><input type="checkbox" name="reserved" value="1"' . (!empty($p['reserved']) ? ' checked' : '') . '> Rezervirano ručno (npr. dogovor porukom)</label>';
  $h .= '<label class="check"><input type="checkbox" name="hidden" value="1"' . (!empty($p['hidden']) ? ' checked' : '') . '> Skriveno (nije na stranici)</label>';
  $h .= '<label class="check"><input type="checkbox" name="hero" value="1"' . (!empty($p['hero']) ? ' checked' : '') . '> Na naslovnici (velika fotografija na vrhu stranice; samo jedna torba)</label>';
  $h .= '</div></div>';
  $h .= '<label>Priča torbe (u povećanom prikazu, 2–3 rečenice, do 400 znakova) <textarea name="story" maxlength="400" rows="3" style="display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid var(--line);border-radius:2px;font:inherit">' . e($p['story'] ?? '') . '</textarea></label>';
  $h .= '<p class="muted">Upute za fotografije: docs/UPUTE-FOTOGRAFIRANJE.md (5 kadrova: sprijeda, u prostoru, na osobi, detalj s rukom, unutrašnjost).</p>';

  $h .= '<h3>Fotografije</h3>';
  if (!empty($p['imgs'])) {
    $h .= '<div class="photos">';
    foreach ($p['imgs'] as $k => $im) {
      $h .= '<div class="photo"><img src="../' . e($im) . '" alt=""><label class="check"><input type="radio" name="cover" value="' . e($im) . '"' . ($k === 0 ? ' checked' : '') . '> naslovna</label><label class="check"><input type="checkbox" name="remove_img[]" value="' . e($im) . '"> ukloni</label></div>';
    }
    $h .= '</div>';
  }
  $h .= '<label>Dodaj fotografije (JPG, PNG ili WEBP; smanjuju se automatski) <input type="file" name="photos[]" accept="image/*" multiple></label>';
  $h .= '<p class="muted">Najbolje uspravne fotografije, omjer oko 4:5, torba na mirnoj pozadini. Prva fotografija je naslovna.</p>';
  $h .= '<div class="row"><button>Spremi</button> <a class="btn alt" href="?s=torbe">Odustani</a></div>';
  return $h . '</form>';
}

function sj_view_reservations(array $c): string {
  $d = sj_res_read();
  $names = []; $soldFlag = [];
  foreach (sj_products() as $p) { $names[$p['id'] ?? ''] = $p['name'] ?? ''; if (!empty($p['sold'])) $soldFlag[(string)($p['id'] ?? '')] = true; }
  $back = '?s=rez';
  $h = '<h3>Aktivne rezervacije — istječu nakon ' . (int)$c['ttl_hours'] . ' h (rok za uplatu)</h3>';
  if (!$d['res']) $h .= '<p class="muted">Trenutno nema rezervacija.</p>';
  else {
    $h .= '<div class="tablewrap"><table class="cards t-rez"><thead><tr><th>Torba</th><th>Kupac</th><th>Rezervirano</th><th>Vrijedi do</th><th>Veza (oznaka)</th><th></th></tr></thead><tbody>';
    $perIp = []; $ipList = []; foreach ($d['res'] as $rid => $r) foreach (array_unique(array_filter([$r['ip'] ?? '', $r['ip_prev'] ?? ''])) as $k) { $perIp[$k] = ($perIp[$k] ?? 0) + 1; $ipList[$k][] = [(string)$rid, (string)($r['name'] ?? '')]; }
    /* v27: broj narudžbe uz rezervaciju (usporedba s izvodom banke) */
    $noOf = []; foreach (sj_read_json(SJ_DATA . '/narudzbe.json', []) as $o) if (!empty($o['reservation']['token'])) $noOf[(string)$o['reservation']['token']] = (string)($o['no'] ?? '');
    foreach ($d['res'] as $id => $r) {
      $noR = $noOf[(string)($r['token'] ?? '')] ?? '';
      $h .= '<tr><td><b>' . e($names[$id] ?? $id) . '</b><br><span class="muted mono">' . e($noR !== '' ? $noR : $id) . '</span></td><td>' . ((string)($r['name'] ?? '') . (string)($r['email'] ?? '') === '' ? '—' : e($r['name']) . '<br><span class="muted">' . e($r['email']) . '</span>') . '</td><td>' . e(sj_fmt_hr($r['since'])) . '</td><td>' . e(sj_fmt_hr($r['until']))  . '</td>';
      $ipc = (string)($r['ip'] ?? '');
      $h .= '<td class="mono">' . ($ipc !== '' ? e(substr($ipc, 0, 6)) . ($perIp[$ipc] > 1 ? '<br><span class="muted">' . (int)$perIp[$ipc] . ' torbe</span>' : '') : '—') . '</td>';
      /* v21: rezervacija bez potvrđene narudžbe (košarica) nema „Plaćeno” — kupac nije ni potvrdio narudžbu */
      $h .= '<td class="acts">' . (empty($r['ordered'])
        ? '<span class="muted">košarica, nije narudžba</span><br>' . sj_btn('res_release', ['id' => $id], 'Ukloni', $back, true)
        : sj_btn('res_sold', ['id' => $id], 'Označi plaćenom', $back, false, 'Označiti „' . ($names[$id] ?? $id) . '” plaćenom (' . (string)($r['name'] ?? '') . ')?') . sj_btn('res_release', ['id' => $id], 'Storniraj', $back, true, 'Stornirati rezervaciju za „' . ($names[$id] ?? $id) . '”? Torba se vraća u prodaju.'))
        . ($ipc !== '' && $perIp[$ipc] > 1 ? sj_btn('res_release_ip', ['ip' => $ipc], 'Storniraj sve s ove adrese', $back, true, 'Stornirati sve rezervacije s ove adrese (' . $perIp[$ipc] . ': ' . implode('; ', array_map(fn($x) => ($names[$x[0]] ?? $x[0]) . ' — ' . $x[1], $ipList[$ipc] ?? [])) . ')? Torbe se odmah vraćaju u prodaju. Na istoj mreži mogu biti i pravi kupci.') : '') . '</td></tr>';
    }
    $h .= '</tbody></table></div>';
  }
  $h .= '<h3>Označeno kao plaćeno</h3>';
  if (!$d['sold']) $h .= '<p class="muted">Nema torbi označenih kao plaćeno.</p>';
  else {
    $h .= '<div class="tablewrap"><table class="cards t-sold"><thead><tr><th>Torba</th><th>Kupac</th><th>Označeno</th><th></th></tr></thead><tbody>';
    foreach ($d['sold'] as $id => $r) {
      $perm = !empty($soldFlag[$id]);
      $who = ($r['name'] ?? '') === '' && ($r['email'] ?? '') === '' ? '<span class="muted">prodano izvan stranice</span>' : e($r['name']) . '<br><span class="muted">' . e($r['email']) . '</span>';
      $h .= '<tr><td><b>' . e($names[$id] ?? $id) . '</b><br><span class="muted mono">' . e(($r['no'] ?? '') !== '' ? $r['no'] : $id) . '</span></td><td>' . $who . '</td><td>' . e(sj_fmt_hr($r['paidAt'] ?? '')) . '</td>';
      /* v21: jednosmjerno — „Označi trajno prodanom”, zatim samo „Vrati u prodaju” uz potvrdu; „Ukloni oznaku” ne za trajno prodanu */
      $h .= '<td class="acts">' . ($perm
        ? '<span class="badge ink">Trajno prodana ✓</span><br>' . sj_btn('toggle', ['id' => $id, 'field' => 'sold', 'to' => '0'], 'Vrati u prodaju', $back, true, 'Vratiti „' . ($names[$id] ?? $id) . '” u prodaju?' . (($r['name'] ?? '') !== '' ? ' Plaćena je za kupca ' . $r['name'] . (($r['no'] ?? '') !== '' ? ' (' . $r['no'] . ')' : '') . '.' : '') . ' Torba će se ponovno nuditi kupcima.')
        : sj_btn('toggle', ['id' => $id, 'field' => 'sold', 'to' => '1'], 'Označi trajno prodanom', $back) . sj_btn('res_unsold', ['id' => $id], 'Ukloni oznaku', $back, true, 'Ukloniti oznaku plaćeno za „' . ($names[$id] ?? $id) . '”' . (($r['name'] ?? '') !== '' ? ' (kupac: ' . $r['name'] . (($r['no'] ?? '') !== '' ? ', ' . $r['no'] : '') . ')' : '') . '? Torba se vraća u prodaju. Ako je kupac platio, vratite mu novac.'))
        . '</td></tr>';
    }
    $h .= '</tbody></table></div>';
  }
  $opts = '';
  $madeIds = []; foreach (sj_products() as $p) if (!empty($p['made'])) $madeIds[(string)($p['id'] ?? '')] = true;
  /* artikli po narudžbi se ne „prodaju” ručno; rezervirane i već plaćene torbe nisu u popisu */
  foreach ($names as $id => $n) if (empty($madeIds[$id]) && !isset($d['res'][$id]) && !isset($d['sold'][$id])) $opts .= '<option value="' . e($id) . '">' . e($n) . '</option>';
  /* v21: prazan prvi izbor, jasan gumb i potvrda — na mobitelu se ne smije slučajno prodati prva torba s popisa */
  $h .= '<h3>Ručno: torba prodana izvan stranice</h3><p class="muted">Samo za torbu prodanu bez narudžbe na stranici (npr. na sajmu). Plaćene narudžbe označite gumbom „Označi plaćenom” u Narudžbama ili gore.</p><form method="post" class="inline form" data-confirm-sel="Označiti „%s” prodanom izvan stranice? Više se neće nuditi kupcima."><input type="hidden" name="csrf" value="' . e(sj_csrf()) . '"><input type="hidden" name="act" value="res_sold_manual"><input type="hidden" name="back" value="' . e($back) . '"><select name="id" required><option value="">— odaberite torbu —</option>' . $opts . '</select> <button>Označi odabranu torbu prodanom</button></form>';
  $h .= '<p class="muted">„Veza (oznaka)” je skraćena, neprepoznatljiva oznaka veze s koje je stigla rezervacija (mijenja se svaki tjedan), a ne adresa kupca; „košarica, nije narudžba” je torba u nečijoj košarici, koja druge kupce ne blokira. Ako jedna adresa drži više torbi, a kupci se ne javljaju, gumb „Storniraj sve s ove adrese” vraća ih u prodaju odjednom.</p>';
  $h .= '<p class="muted">Tijek: narudžba → kupac uplati i pošalje potvrdu → „Označi plaćenom” (ovdje ili iz e-maila) → „Označi trajno prodanom” kad torbu pošaljete ili predate. „Storniraj” i „Ukloni oznaku” vraćaju torbu u prodaju; trajno prodana vraća se samo gumbom „Vrati u prodaju”.</p>';
  return $h;
}

function sj_view_orders(): string {
  $list = sj_read_json(SJ_DATA . '/narudzbe.json', []);
  /* v28: narudžbe koje servis nije zaprimio (granica po vezi) — da se vlasnica može javiti kupcu */
  $rej = sj_read_json(SJ_DATA . '/odbijene.json', []);
  $rejHtml = '';
  if ($rej) {
    $rejHtml = '<h3>Odbijene (ista veza)</h3><p class="muted">Ove narudžbe NISU zaprimljene: s iste internetske veze već je čekalo uplatu nekoliko narudžbi (zaštita od lažnih narudžbi). Ako je kupac pravi, javite mu se.</p><div class="tablewrap"><table><thead><tr><th>Vrijeme</th><th>Kupac</th><th>Artikli</th></tr></thead><tbody>';
    foreach (array_slice($rej, 0, 30) as $x) $rejHtml .= '<tr><td>' . e(sj_fmt_hr((string)($x['at'] ?? ''))) . '</td><td>' . e((string)($x['name'] ?? '')) . '<br><span class="muted">' . e((string)($x['email'] ?? '')) . (($x['phone'] ?? '') !== '' ? ' · ' . e((string)$x['phone']) : '') . '</span></td><td>' . e((string)($x['items'] ?? '')) . '</td></tr>';
    $rejHtml .= '</tbody></table></div>';
  }
  if (!$list) return '<p class="muted">Još nema narudžbi.</p>' . $rejHtml;
  $rd = sj_res_read();
  $rokHtml = sj_view_expired($rd);
  $active = [];
  foreach ($rd['res'] as $r) if (!empty($r['token'])) $active[(string)$r['token']] = true;
  $pn = []; $pById = []; foreach (sj_products() as $p) { $pn[(string)($p['id'] ?? '')] = (string)($p['name'] ?? ''); $pById[(string)($p['id'] ?? '')] = $p; }
  $h = '<div class="tablewrap"><table class="cards t-nar"><thead><tr><th>Broj</th><th>Vrijeme</th><th>Kupac</th><th>Artikli</th><th>Dostava</th><th>Ukupno</th><th>Uplata</th></tr></thead><tbody>';
  /* v66: uz zadnjih 100 uvijek i starije neplaćene narudžbe iz Stalne kolekcije (i iz arhive tekuće godine) —
     inače bi ih mnogo novih narudžbi „sakrilo”, a mjesta bi ostala zauzeta */
  $show = array_slice($list, 0, 100); $seenNo = []; foreach ($show as $o) $seenNo[(string)($o['no'] ?? '')] = true;
  /* v67: isti uvjet kao brojanje mjesta (sj_made_counts): samo narudžbe koje stvarno drže mjesto (nepotvrđena neplaćena
     ne drži; prije zadnjeg „Otvori nova mjesta” ne broji se), sve arhive od najstarijeg otvaranja; najviše 300 redaka */
  $fromC = []; $minT = PHP_INT_MAX;
  foreach ($pById as $pid => $pp) if (sj_is_collection($pp)) { $t = (int)strtotime((string)($pp['made_open_from'] ?? '')) ?: 0; $fromC[$pid] = $t; $minT = min($minT, $t); }
  $minY = $minT > 0 && $minT < PHP_INT_MAX ? (int)date('Y', $minT) : 0;
  $older = []; $olderMore = 0;
  if ($fromC) foreach (sj_order_files() as $fi => $f) {
    if (preg_match('/narudzbe-arhiva-(\d{4})/', $f, $m) && (int)$m[1] < $minY) continue;
    foreach (($fi === 0 ? array_slice($list, 100) : sj_read_json($f, [])) as $o) {
      $nn = (string)($o['no'] ?? '');
      if ($nn === '' || isset($seenNo[$nn]) || !empty($o['paidAt']) || !empty($o['cancelledAt']) || !empty($o['unconfirmed'])) continue;
      $at = (int)strtotime((string)($o['at'] ?? ''));
      if (!array_filter((array)($o['order']['items'] ?? []), fn($it) => isset($fromC[(string)($it['id'] ?? '')]) && $at >= $fromC[(string)($it['id'] ?? '')])) continue;
      $seenNo[$nn] = true;
      if (count($older) >= 300) { $olderMore++; continue; }
      $o['_older'] = true; $older[] = $o;
    }
  }
  $hOld = '';
  $show = array_merge($older, $show);
  foreach ($show as $o) {
    $no = (string)($o['no'] ?? '');
    $tok = (string)($o['reservation']['token'] ?? '');
    /* aktivna rezervacija se označuje kod rezervacija (torba ode iz ponude); istekla ima gumb ovdje */
    /* v26: plaćena narudžba čija torba nije (više) zapisana kao plaćena za nju — prodana drugome ili vraćena u prodaju */
    $clash = [];
    if (!empty($o['paidAt'])) foreach ((array)($o['order']['items'] ?? []) as $it) {
      if (!empty($it['made']) || empty($it['id'])) continue;
      $sd = $rd['sold'][(string)$it['id']] ?? null;
      if (!$sd || !(($tok !== '' && ($sd['token'] ?? '') === $tok) || ($sd['no'] ?? '') === $no)) $clash[] = (string)($it['name'] ?? $it['id']);
    }
    $clashHtml = $clash ? '<br><span class="badge ink">SUKOB</span> <span style="color:#9B2C1F">„' . e(implode('”, „', $clash)) . '” nije zapisana kao plaćena za ovu narudžbu (prodana drugom kupcu ili vraćena u prodaju). Provjerite i po potrebi vratite novac kupcu.</span>' : '';
    if (!empty($o['paidAt'])) $paid = '<b>plaćeno</b> ' . e(sj_fmt_hr($o['paidAt'])) . $clashHtml . '<br>' . sj_btn('order_unpaid', ['no' => $no], 'Ukloni oznaku', '?s=narudzbe', true, 'Ukloniti oznaku plaćeno za narudžbu ' . $no . ' (kupac: ' . ($o['name'] ?? '') . ')? ' . ($clash ? 'Torbe zapisane kao plaćene za ovu narudžbu vraćaju se u prodaju; one prodane drugom kupcu ostaju njegove.' : 'Njezine torbe vraćaju se u prodaju.'));
    else $paid = (!empty($o['unconfirmed']) ? '<span class="muted">bez potvrde e-mailom</span><br>' : '') . (!empty($o['cancelledAt']) ? '<b>stornirano</b> ' . e(sj_fmt_hr($o['cancelledAt'])) . (($o['cancelBy'] ?? '') === 'automatski' ? ' <span class="muted">(automatski)</span>' : '') . (trim((string)($o['cancelReason'] ?? '')) !== '' ? '<br><span class="muted">razlog: ' . e((string)$o['cancelReason']) . '</span>' : '') . '<br>' : ($tok !== '' && !isset($active[$tok]) ? '<span class="muted">' . (strtotime((string)($o['reservation']['until'] ?? '')) > time() ? 'rezervacija uklonjena (torba prodana ili oznaka uklonjena)' : 'rezervacija istekla') . '</span><br>' : '')) . (!empty($o['cancelledAt']) ? '' : '<b>nije plaćeno</b><br>' . sj_btn('order_paid', ['no' => $no], 'Označi plaćenom', '?s=narudzbe', false, 'Označiti narudžbu ' . $no . ' plaćenom?')); /* v64: stornirana se ne označuje plaćenom */
    /* v63: neplaćenu, nestorniranu narudžbu vlasnica može stornirati (i onu samo iz Stalne kolekcije) */
    if (empty($o['paidAt']) && empty($o['cancelledAt'])) $paid .= sj_cancel_form($no, '?s=narudzbe', 'Stornirati narudžbu ' . $no . ' (kupac: ' . ($o['name'] ?? '') . ', ' . ($o['email'] ?? '') . ')? Torbe i mjesta u Stalnoj kolekciji se oslobađaju; kupcu se javite sami.');
    /* v64: telefon, adresa ili paketomat, napomena i vrsta stavke (unikat / Stalna kolekcija / po narudžbi) */
    /* v65: jedna linija po stavki — naziv, cijena, vrsta i želja kupca (umjesto drugog popisa) */
    $kinds = [];
    foreach ((array)($o['order']['items'] ?? []) as $it) {
      $pk = $pById[(string)($it['id'] ?? '')] ?? null; $q = max(1, (int)($it['qty'] ?? 1));
      $kinds[] = e((string)($it['name'] ?? '')) . ($q > 1 ? ' × ' . $q : '') . ' — <span class="nw">' . e(sj_fmt_eur((float)($it['price'] ?? 0) * $q)) . '</span> <span class="muted">· ' . (empty($it['made']) ? 'unikat' : ($pk && sj_is_collection($pk) ? 'Stalna kolekcija' : 'po narudžbi')) . '</span>'
        . (trim((string)($it['note'] ?? '')) !== '' ? ' · <b>želja: ' . e(trim((string)$it['note'])) . '</b>' : '');
    }
    $locker = (string)($o['order']['shipping']['locker'] ?? '');
    $addrO = trim((string)($o['address'] ?? ''));
    $canc = !empty($o['cancelledAt']);
    /* v65: aktivna rezervacija — do kada */
    if (empty($o['paidAt']) && !$canc && $tok !== '' && isset($active[$tok]) && !empty($o['reservation']['until'])) $paid = '<span class="muted">rezervirano do ' . e(sj_fmt_hr((string)$o['reservation']['until'])) . '</span><br>' . $paid;
    /* v67: poveznice samo za običnu adresu i pravi broj (bez podmetnutih ?subject=/cc= ili spojenih brojeva) */
    $em = (string)($o['email'] ?? ''); $ph = (string)($o['phone'] ?? ''); $phD = preg_replace('/[^0-9+]/', '', $ph);
    $emH = preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $em) ? '<a class="muted" href="mailto:' . e($em) . '">' . e($em) . '</a>' : '<span class="muted">' . e($em) . '</span>';
    $phH = $ph === '' ? '' : '<br>' . (preg_match('/^\+?[0-9]{6,15}$/', $phD) && !preg_match('/[A-Za-z()]/', $ph) ? '<a class="muted nw" href="tel:' . e($phD) . '">' . e($ph) . '</a>' : '<span class="muted nw">' . e($ph) . '</span>');
    $row = '<tr' . ($canc ? ' class="is-cancelled"' : '') . '><td class="mono">' . e($no) . ($canc ? '<br><span class="badge grey">stornirano</span>' : '') . '</td><td>' . e(sj_fmt_hr($o['at'] ?? '')) . '</td><td>' . e($o['name'] ?? '') . '<br>' . $emH . $phH . '</td><td>' . ($kinds ? implode('<br>', $kinds) : '<pre>' . e($o['items'] ?? '') . '</pre>') . (($o['note'] ?? '') !== '' ? '<br><b>Napomena:</b> ' . e((string)$o['note']) : '') . '</td><td>' . e($o['shipping'] ?? '') . ($locker !== '' && mb_stripos((string)($o['shipping'] ?? ''), $locker) === false ? '<br><span class="muted">Paketomat: ' . e($locker) . '</span>' : ($locker !== '' ? ''  : ($addrO !== '' ? '<br><span class="muted">' . e($addrO) . '</span>' : ''))) . '</td><td class="mono">' . e($o['total'] ?? '') . '</td><td>' . $paid . '</td></tr>';
    if (!empty($o['_older'])) $hOld .= $row; else $h .= $row;
  }
  $h .= '</tbody></table></div>';
  if ($hOld !== '') $h = '<h3 id="starije">Starije neplaćene (Stalna kolekcija) — drže mjesto</h3><p class="muted">Ove narudžbe nisu među zadnjih 100, a još drže mjesto u kolekciji. Ako kupac ne uplati, stornirajte ih.</p><div class="tablewrap"><table class="cards t-nar"><thead><tr><th>Broj</th><th>Vrijeme</th><th>Kupac</th><th>Artikli</th><th>Dostava</th><th>Ukupno</th><th>Uplata</th></tr></thead><tbody>' . $hOld . '</tbody></table></div>'
    . ($olderMore ? '<p class="muted">… i još ' . $olderMore . '.</p>' : '') . '<h3>Narudžbe</h3>' . $h;
  return $rokHtml . $h . $rejHtml . '<p class="muted">Čuva se zadnjih 300 narudžbi. Puni sadržaj svake narudžbe stiže i e-mailom. „Plaćeno” ovdje označuje cijelu narudžbu: rezervirane torbe postaju plaćene, a torba kojoj je rok istekao pripada ovom kupcu ako je u međuvremenu nitko drugi nije platio ili naručio; ako jest, admin to javi.</p>';
}

/* v68: „Rok istekao” — kartice na vrhu Narudžbi: poziv na broj (usporedba s izvodom), je li komad još slobodan,
   koliko do automatskog storna, Plaćeno i Storniraj (s razlogom) */
function sj_view_expired(array $rd): string {
  $ex = sj_expired_orders();
  if (!$ex) return '';
  $c = sj_config(); $auto = max(0, (int)($c['auto_cancel_hours'] ?? 0)); $now = time();
  $h = '<h3 id="rok">Rok istekao (' . count($ex) . ')</h3><p class="muted">Neplaćene narudžbe kojima je prošao rok za uplatu. Komad je već vraćen u prodaju. Usporedite poziv na broj s izvodom banke: ako je uplata stigla, kliknite „Označi plaćenom”; ako nije, „Storniraj”.' . ($auto > 0 ? ' Ako ne kliknete ništa, narudžba se automatski stornira ' . sj_hours_text($auto) . ' nakon isteka roka i kupac dobiva e-mail.' : '') . '</p><div class="rokl">';
  foreach ($ex as $o) {
    $no = (string)($o['no'] ?? ''); $tok = (string)($o['reservation']['token'] ?? '');
    $u = (int)strtotime(sj_pay_until($o)); $ago = max(0, intdiv($now - $u, 3600));
    $left = $auto > 0 ? max(0, intdiv($u + $auto * 3600 - $now + 3599, 3600)) : 0;
    /* komad: unikat koji je u međuvremenu rezervirao ili platio drugi kupac */
    $other = [];
    foreach ((array)($o['order']['items'] ?? []) as $it) { if (!empty($it['made']) || empty($it['id'])) continue; $id = (string)$it['id'];
      $r = $rd['res'][$id] ?? null; $sd = $rd['sold'][$id] ?? null;
      if (($r && !empty($r['ordered']) && ($r['token'] ?? '') !== $tok) || ($sd && ($sd['no'] ?? '') !== $no && ($tok === '' || ($sd['token'] ?? '') !== $tok))) $other[] = (string)($it['name'] ?? $id); }
    $state = $other ? '<span class="badge ink">prodano ili rezervirano drugome: ' . e(implode(', ', $other)) . '</span>' : '<span class="badge green">komad slobodan</span>';
    $h .= '<div class="card rok"><div class="row" style="justify-content:space-between;margin:0"><b class="mono">' . e($no) . '</b>' . $state . '</div>'
      . '<div>' . e((string)($o['name'] ?? '')) . ' · <b>' . e((string)($o['total'] ?? '')) . '</b> · ' . e(implode(', ', array_map(fn($l) => preg_replace('/^- /', '', trim($l)), array_filter(explode("\n", (string)($o['items'] ?? '')), 'strlen')))) . '</div>'
      . '<div class="muted">poziv na broj <b class="mono">' . e(sj_payment_ref($no)) . '</b> · rok istekao prije ' . $ago . ' h' . ($auto > 0 ? ' · automatski storno za ' . $left . ' h' : '') . '</div>'
      . '<div class="row">' . sj_btn('order_paid', ['no' => $no], 'Označi plaćenom', '?s=narudzbe', false, 'Označiti narudžbu ' . $no . ' plaćenom?' . ($other ? ' PAŽNJA: ' . implode(', ', $other) . ' je u međuvremenu prodana ili rezervirana drugom kupcu — admin će to javiti; kupcu ćete vratiti novac za nju.' : ''))
      . sj_cancel_form($no, '?s=narudzbe', 'Stornirati narudžbu ' . $no . '? Kupac ne dobiva automatsku poruku; javite mu se.', 'Storniraj') . '</div></div>';
  }
  return $h . '</div>';
}

/* tjedni zbrojevi iz data/brojac.json: A = narudžbe / posjeti, B = plaćeno / narudžbe */
function sj_view_stats(): string {
  $d = sj_read_json(SJ_DATA . '/brojac.json', []);
  if (!$d) return '<p class="muted">Još nema podataka. Brojanje počinje s prvim posjetom stranici.</p>';
  $weeks = [];
  foreach ($d as $day => $v) {
    $t = strtotime($day . ' 12:00');
    if (!$t) continue;
    $wk = date('o-W', $t);
    $weeks[$wk] = $weeks[$wk] ?? ['from' => $day, 'visits' => 0, 'ig' => 0, 'orders' => 0, 'paid' => 0, 'expired' => 0, 'press' => 0, 'rad' => 0, 'cart' => 0, 'copy' => 0, 'orders_m' => 0];
    if ($day < $weeks[$wk]['from']) $weeks[$wk]['from'] = $day;
    foreach (['visits', 'ig', 'orders', 'paid', 'expired', 'press', 'rad', 'cart', 'copy', 'orders_m'] as $k) $weeks[$wk][$k] += (int)($v[$k] ?? 0);
  }
  krsort($weeks);
  $pct = fn($a, $b) => $b > 0 ? number_format(100 * $a / $b, 1, ',', '') . ' %' : '—';
  $h = '<div class="tablewrap"><table><thead><tr><th>Tjedan od</th><th>Posjeti</th><th>s Instagrama</th><th>Narudžbe</th><th>Plaćeno</th><th>Isteklo neplaćeno</th><th>Radionica</th><th>Košarica</th><th>Kopiraj</th><th>Klik na članke</th><th>Bez unikata</th><th>A: narudžbe / posjeti</th><th>B: plaćeno / narudžbe</th></tr></thead><tbody>';
  foreach (array_slice($weeks, 0, 30) as $w) {
    $h .= '<tr><td>' . e(date('j. n. Y.', strtotime($w['from']))) . '</td><td class="mono">' . $w['visits'] . '</td><td class="mono">' . $w['ig'] . '</td><td class="mono">' . $w['orders'] . '</td><td class="mono">' . $w['paid'] . '</td><td class="mono">' . $w['expired'] . '</td><td class="mono">' . $w['rad'] . '</td><td class="mono">' . $w['cart'] . '</td><td class="mono">' . $w['copy'] . '</td><td class="mono">' . $w['press'] . '</td><td class="mono">' . $w['orders_m'] . '</td><td class="mono">' . $pct($w['orders'], $w['visits']) . '</td><td class="mono">' . $pct($w['paid'], $w['orders']) . '</td></tr>';
  }
  $h .= '</tbody></table></div>';
  $h .= '<p class="muted">„Plaćeno” se broji prvi put kad je narudžba označena plaćenom; uklanjanje oznake ga ne umanjuje (ispravci ne mijenjaju brojke). Samo zbrojevi po danu, bez imena, e-maila i IP adrese. Posjet = jedno otvaranje stranice. Narudžbe, plaćeno i isteklo broje se po narudžbi, ne po torbi (narudžba s dvije torbe je jedna). „s Instagrama” broji posjete preko linka koji završava s <span class="mono">?izvor=ig</span> (taj link stavite u Instagram bio). „Radionica” = posjeti koji su došli do odjeljka Radionica, „Košarica” = posjeti s barem jednim dodavanjem u košaricu, „Kopiraj” = posjeti koji su kopirali podatke za uplatu, „Klik na članke” = klikovi na članke u Radionici (usporedite s „Radionica”, ne s posjetima), „Bez unikata” = narudžbe samo s artiklima po narudžbi. Promjenu na stranici mjerite tako da usporedite nekoliko tjedana prije i poslije; mijenjajte jednu stvar odjednom i zapišite datum.</p>';
  return $h;
}

function sj_settings_draft(array $c, array $in): array {
  foreach (['owner_email', 'from_email', 'site_url', 'ttl_hours', 'oib', 'ship_lead', 'pickup_info'] as $k) if (isset($in[$k])) $c[$k] = sj_clean((string)$in[$k], 200);
  foreach (['recipient', 'address', 'iban', 'model'] as $k) if (isset($in[$k])) $c['payment'][$k] = sj_clean((string)$in[$k], 120);
  $c['confirm_customer'] = !empty($in['confirm_customer']);
  $c['show_ostalo'] = !empty($in['show_ostalo']);
  $c['mail_mode'] = (($in['mail_mode'] ?? '') === 'log') ? 'log' : 'mail';
  if (isset($in['shipping'])) $c['_ship_draft'] = (string)$in['shipping'];
  foreach (['remind_hours', 'auto_cancel_hours', 'signature'] as $k) if (isset($in[$k])) $c[$k] = sj_clean((string)$in[$k], 60);
  $c['require_confirm'] = !empty($in['require_confirm']);
  if (isset($in['free_min'])) $c['free_ship'] = ['min' => (float)str_replace(',', '.', (string)$in['free_min']), 'ids' => array_map('strval', (array)($in['free_ids'] ?? []))];
  if (isset($in['sizes'])) $c['_sizes_draft'] = (string)$in['sizes'];
  if (isset($in['size_all'])) $c['size_all'] = sj_clean((string)$in['size_all'], 600, true);
  return $c;
}

function sj_view_settings(array $c): string {
  $p = $c['payment'];
  $csrf = e(sj_csrf());
  $h = '<form method="post" class="card form"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="save_settings"><input type="hidden" name="back" value="?s=postavke">';
  $h .= '<h3>E-mail i stranica</h3><div class="grid2">';
  $h .= '<label>E-mail vlasnice (narudžbe stižu ovdje) * <input name="owner_email" required value="' . e($c['owner_email']) . '"></label>';
  $h .= '<label>Adresa pošiljatelja na domeni (npr. info@shivaj.hr) <input name="from_email" value="' . e($c['from_email']) . '"></label>';
  /* v29: upozorenje prema prikazanim vrijednostima (i nakon greške, kad obrazac pokazuje upisano) */
  $mailFrom = trim((string)($c['from_email'] ?? '')) !== '' ? (string)$c['from_email'] : (string)($c['owner_email'] ?? '');
  $siteHost = strtolower(preg_replace('/^www\./', '', (string)parse_url(trim((string)($c['site_url'] ?? '')) !== '' ? (string)$c['site_url'] : sj_site_url(), PHP_URL_HOST)));
  $fromHost = strtolower((string)substr(strrchr($mailFrom, '@') ?: '', 1));
  if ($fromHost === '' || ($siteHost !== '' && $fromHost !== $siteHost && !str_ends_with($fromHost, '.' . $siteHost)))
    $h .= '<p class="muted" style="grid-column:1/-1;color:#9B2C1F">Pažnja: e-mailovi odlaze s adrese ' . e($mailFrom) . ', koja nije na domeni stranice (' . e($siteHost) . '). Potvrde kupcima s podacima za uplatu tada često završe u neželjenoj pošti. Upišite adresu na domeni (npr. info@' . e($siteHost) . ') i na hostingu uključite SPF i DKIM.</p>';
  $h .= '<label>Adresa stranice (npr. https://shivaj.hr) <input name="site_url" value="' . e($c['site_url']) . '" placeholder="prazno = automatski"></label>';
  if (trim((string)$c['site_url']) === '')
    $h .= '<p class="muted" style="grid-column:1/-1;color:#9B2C1F">Pažnja: adresa stranice nije upisana. Dok je prazna, linkovi u e-mailovima (Plaćeno/Storniraj, Uvjeti kupnje, admin) šalju se samo ako se domena stranice slaže s adresom pošiljatelja. Upišite punu adresu, npr. https://shivaj.hr.</p>';
  $h .= '</div><h3>Rok za uplatu i e-mailovi kupcu</h3><div class="grid2">';
  $h .= '<label>Rok za uplatu = rezervacija (sati) <input name="ttl_hours" type="number" min="1" max="168" value="' . e((string)$c['ttl_hours']) . '"></label>';
  $h .= '<label>Podsjetnik kupcu nakon (sati; 0 = bez) <input name="remind_hours" type="number" min="0" max="167" value="' . e((string)($c['remind_hours'] ?? 12)) . '"><small class="muted">Bez barkoda i podataka za uplatu; samo ako narudžba još nije označena plaćenom.</small></label>';
  $h .= '<label>Automatski storno nakon isteka roka (sati; 0 = nikad) <input name="auto_cancel_hours" type="number" min="0" max="336" value="' . e((string)($c['auto_cancel_hours'] ?? 48)) . '"><small class="muted">Do tada narudžba čeka vaš klik pod Narudžbe → „Rok istekao”; kupac tada dobiva e-mail.</small></label>';
  $h .= '<label>Potpis e-mailova kupcima <input name="signature" maxlength="60" value="' . e((string)($c['signature'] ?? '')) . '" placeholder="npr. Jasmina · Shiva.J"></label>';
  $h .= '</div><div class="stack">';
  $h .= '<label class="check"><input type="checkbox" name="require_confirm" value="1"' . (!empty($c['require_confirm']) ? ' checked' : '') . '> Narudžba vrijedi tek kad je kupac potvrdi linkom iz e-maila (zaštita od robota i lažnih narudžbi)</label>';
  $h .= '<label class="check"><input type="checkbox" name="confirm_customer" value="1"' . (!empty($c['confirm_customer']) ? ' checked' : '') . '> Automatski pošalji kupcu potvrdu narudžbe s podacima za uplatu i barkodom</label>';
  $h .= '<label class="check"><input type="checkbox" name="show_ostalo" value="1"' . (!empty($c['show_ostalo']) ? ' checked' : '') . '> Prikaži rubriku „Ostalo” (remeni, torbice, platnene torbe po narudžbi) na stranici</label>';
  $h .= '<label class="check"><input type="checkbox" name="mail_mode" value="log"' . ($c['mail_mode'] === 'log' ? ' checked' : '') . '> Testni način: e-mailove ne šalji, nego zapiši u data/mail-log.txt</label>';
  $h .= '</div>';
  $h .= '<h3>Podaci za uplatu</h3><p class="muted">Ploča za uplatu i e-mailovi čitaju podatke odavde. Uvjeti kupnje i Impressum na stranici imaju OIB, IBAN i e-mail upisane u tekstu: kad ih mijenjate ovdje, javite Claudeu da ih uskladi i na stranici.</p><div class="grid2">';
  $h .= '<label>Primatelj <input name="recipient" value="' . e($p['recipient']) . '"></label>';
  $h .= '<label>Adresa primatelja <input name="address" value="' . e($p['address']) . '"></label>';
  $h .= '<label>IBAN <input name="iban" value="' . e($p['iban']) . '"></label>';
  $h .= '<label>Model <input name="model" value="' . e($p['model']) . '" maxlength="4"></label>';
  $h .= '<label>OIB obrta (u potpisu potvrde kupcu; prazno = ne prikazuje se) <input name="oib" inputmode="numeric" maxlength="11" value="' . e((string)($c['oib'] ?? '')) . '"></label>';
  $h .= '</div>';
  $h .= '<label>Osobno preuzimanje (tekst u potvrdi) <input name="pickup_info" value="' . e($c['pickup_info']) . '"></label>';
  $h .= '<label>Rok slanja nakon uplate (prikazuje se kupcu nakon narudžbe; prazno = ne prikazuje se) <input name="ship_lead" maxlength="60" value="' . e((string)($c['ship_lead'] ?? '')) . '" placeholder="npr. 1–2 radna dana"></label>';
  $shipLines = '';
  if (isset($c['_ship_draft'])) $shipLines = $c['_ship_draft'];
  else foreach (sj_shipping() as $o) $shipLines .= $o['id'] . ' | ' . $o['label'] . ' | ' . number_format($o['price'], 2, ',', '') . ' | ' . ($o['address'] ? 'da' : 'ne') . ' | ' . ($o['locker'] ? 'da' : 'ne') . ' | ' . $o['note'] . "\n";
  /* v68: besplatna dostava */
  $fs = sj_free_ship(); $fsBox = '';
  foreach (sj_shipping() as $o) $fsBox .= '<label class="check"><input type="checkbox" name="free_ids[]" value="' . e($o['id']) . '"' . (in_array($o['id'], $fs['ids'], true) ? ' checked' : '') . '> ' . e($o['label']) . '</label>';
  $h .= '<h3>Besplatna dostava</h3><div class="grid2"><label>Besplatna kad roba iznosi barem (€; 0 = isključeno) <input name="free_min" inputmode="decimal" value="' . e(number_format($fs['min'], 2, ',', '')) . '"></label><div class="stack"><span class="muted">Vrijedi za:</span>' . $fsBox . '</div></div>';
  /* v68: mjere i univerzalni opisi */
  $szLines = $c['_sizes_draft'] ?? implode("\n", array_map(fn($x) => $x['mjera'] . ' | ' . $x['opis'], sj_sizes()));
  $h .= '<h3>Mjere i opisi</h3><label>Jedna mjera po retku: <span class="mono">mjera | univerzalni opis</span> (npr. <span class="mono">26 × 7 cm | Stane mobitel, novčanik i ključevi.</span>). Svaka mjera je kućica u obrascu torbe, a opis se kupcu prikazuje u bloku „Veličina i nošenje”.<textarea name="sizes" rows="4" style="display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid var(--line);border-radius:2px;font:inherit">' . e($szLines) . '</textarea></label>';
  $h .= '<label>Za sve torbe (npr. duljina i podešavanje remena; prikazuje se uz svaku torbu) <textarea name="size_all" rows="2" maxlength="600" style="display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid var(--line);border-radius:2px;font:inherit">' . e((string)($c['size_all'] ?? '')) . '</textarea></label>';
  $h .= '<h3>Načini dostave</h3><label>Jedan način po retku: <span class="mono">oznaka | naziv | cijena € | traži adresu (da/ne) | BOX NOW paketomat (da/ne) | napomena</span>. Prvi redak je zadani. Stranica i potvrde kupcu čitaju cijene odavde.<textarea name="shipping" rows="4" style="display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid var(--line);border-radius:2px;font:inherit;font-size:.85rem">' . e($shipLines) . '</textarea></label>';
  $h .= '<div class="row"><button>Spremi postavke</button></div></form>';

  $h .= '<form method="post" class="card form"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="change_password"><input type="hidden" name="back" value="?s=postavke"><h3>Promjena lozinke</h3><div class="grid2">';
  $h .= '<label>Stara lozinka <input type="password" name="old_password" required autocomplete="current-password"></label>';
  $h .= '<label>Nova lozinka (barem 10 znakova) <input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>';
  $h .= '<label>Ponovite novu lozinku <input type="password" name="new_password2" required minlength="10" autocomplete="new-password"></label>';
  $h .= '</div><div class="row"><button class="alt">Promijeni lozinku</button></div></form>';

  $h .= '<div class="card"><h3>Provjera</h3><p class="muted">Pošalje probnu poruku na e-mail vlasnice. Stranica: <a href="' . e(sj_site_url()) . '/">' . e(sj_site_url()) . '</a> · API: <a href="' . e(sj_site_url()) . '/api/status">status</a> · ' . e(SJ_VERSION) . ' · PHP ' . e(PHP_VERSION) . ' · GD ' . (function_exists('imagecreatefromjpeg') ? 'da' : 'NE') . '</p>';
  $h .= sj_btn('test_mail', [], 'Pošalji probni e-mail', '?s=postavke', true) . '<p class="muted">Probni e-mail koristi spremljene postavke: nakon izmjena prvo kliknite „Spremi postavke”.</p></div>';
  return $h;
}

/* ---------- okvir stranice ---------- */
function sj_layout(string $title, string $content, array $c, string $msg, string $err, bool $nav = true): void {
  /* v22: CSP — admin ne učitava ništa izvana i ne smije se otvarati u okviru tuđe stranice */
  $nonce = bin2hex(random_bytes(12));
  if (!headers_sent()) header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'nonce-{$nonce}'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  header('Referrer-Policy: no-referrer');
  header('X-Frame-Options: DENY');
  $s = (string)($_GET['s'] ?? 'torbe');
  $navHtml = '';
  if ($nav) {
    $nEx = count(sj_expired_orders()); /* v68: broj narudžbi s isteklim rokom uz „Narudžbe” */
    $items = ['torbe' => 'Torbe', 'rez' => 'Rezervacije', 'narudzbe' => 'Narudžbe' . ($nEx ? ' (' . $nEx . ')' : ''), 'mjerenje' => 'Mjerenje', 'postavke' => 'Postavke'];
    foreach ($items as $k => $v) $navHtml .= '<a href="?s=' . $k . '"' . (($s === $k || ($k === 'torbe' && in_array($s, ['nova', 'uredi'], true))) ? ' class="on"' : '') . '>' . $v . '</a>';
    $navHtml .= '<a href="../" target="_blank" rel="noopener">Stranica ↗</a><a href="?odjava=1">Odjava</a>';
  }
  echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' — ' . e($c['shop']) . ' admin</title>
<style>
  :root{--paper:#F6F6F3;--ink:#1B1A17;--soft:#5C5A53;--thread:#2E4A3A;--line:#DDDCD5}
  *{box-sizing:border-box}[hidden]{display:none!important}body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
  header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px 20px;border-bottom:1.5px dashed var(--thread);flex-wrap:wrap}
  .brand{font-size:1.1rem;letter-spacing:.14em}.brand span{color:var(--thread)}
  nav{display:flex;gap:14px;flex-wrap:wrap}nav a{color:var(--soft);text-decoration:none;font-size:.85rem;letter-spacing:.06em;text-transform:uppercase;padding-bottom:2px;border-bottom:1.5px dashed transparent}nav a.on,nav a:hover{color:var(--thread);border-bottom-color:var(--thread)}
  main{max-width:1100px;margin:0 auto;padding:24px 20px 60px}h1{font-weight:400;font-size:1.5rem;margin:0 0 14px}h3{font-weight:400;font-size:1.05rem;margin:22px 0 8px;letter-spacing:.03em}
  .msg{background:#E4EAE5;border:1.5px dashed var(--thread);padding:10px 14px;border-radius:2px;margin:0 0 16px}.err{background:#F3E1DC;border:1.5px dashed #8A3B2E;padding:10px 14px;border-radius:2px;margin:0 0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:2px;padding:18px;margin:0 0 18px}
  .form label{display:block;margin:0 0 12px;font-size:.85rem;color:var(--soft)}.form input:not([type=checkbox]):not([type=radio]),.form select{display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid var(--line);border-radius:2px;font:inherit;font-size:16px;color:var(--ink);background:#fff}
  /* v25: polja 16 px (iOS ne uvećava stranicu pri upisu), na mobitelu gumbi dovoljno veliki za prst */
  .form textarea{font-size:16px!important}
  @media (max-width:700px){button,.btn{min-height:44px}td.acts button,.btn.small,button.small{min-height:40px;padding:8px 12px;font-size:.8rem}}
  .form input[type=file]{padding:6px 0}.form input:focus,.form select:focus{outline:2px solid var(--thread);outline-offset:-1px}
  label.check{display:flex;gap:8px;align-items:center;font-size:.9rem;color:var(--ink)}label.check input{margin:0}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}.stack{display:flex;flex-direction:column;gap:8px;margin:8px 0 12px}
  @media (max-width:700px){.grid2{grid-template-columns:1fr}}
  button,.btn{background:var(--thread);color:var(--paper);border:1.5px solid var(--thread);border-radius:2px;padding:8px 13px;font:inherit;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;text-decoration:none;display:inline-block}
  button.alt,.btn.alt{background:none;color:var(--thread);border-style:dashed}.btn.small,button.small{padding:5px 9px;font-size:.72rem}
  .inline{display:inline-block;margin:0 4px 4px 0}.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
  /* v21: na mobitelu rezervacije i narudžbe kao kartice — gumbi su ispod imena, ne izvan zaslona */
  /* v22: na karticama natpis polja ispred vrijednosti (zaglavlje tablice je skriveno) */
  @media (max-width:700px){.cards td::before{display:block;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:var(--soft)}
  .t-tor td:nth-child(3)::before{content:"Vrsta"}.t-tor td:nth-child(4)::before{content:"Cijena"}.t-tor td:nth-child(5)::before{content:"Stanje"}
  .t-rez td:nth-child(2)::before,.t-sold td:nth-child(2)::before,.t-nar td:nth-child(3)::before{content:"Kupac"}
  .t-rez td:nth-child(3)::before{content:"Rezervirano"}.t-rez td:nth-child(4)::before{content:"Vrijedi do"}.t-rez td:nth-child(5)::before{content:"Veza (oznaka)"}
  .t-sold td:nth-child(3)::before{content:"Označeno"}
  .t-nar td:nth-child(2)::before{content:"Vrijeme"}.t-nar td:nth-child(4)::before{content:"Artikli"}.t-nar td:nth-child(5)::before{content:"Dostava"}.t-nar td:nth-child(6)::before{content:"Ukupno"}.t-nar td:nth-child(7)::before{content:"Uplata"}
  .t-tor td.thumb{width:auto}}
  @media (max-width:700px){.cards thead{display:none}.cards tr{display:block;border:1px solid var(--line);margin:0 0 12px;padding:6px 0;background:#fff}.cards td{display:block;border:0;padding:4px 12px}.cards td.acts{white-space:normal;padding-top:8px}}
  .tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.9rem;background:#fff}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--soft)}
  td.thumb{width:64px}td.thumb img{width:56px;height:70px;object-fit:cover;border-radius:2px}.noimg{display:inline-flex;width:56px;height:70px;align-items:center;justify-content:center;background:#E4EAE5;color:var(--thread);border-radius:2px;font-size:1.3rem}
  td.acts{white-space:nowrap}td.acts form{margin-bottom:4px}td.acts button{padding:5px 9px;font-size:.72rem}tr.dim td{opacity:.55}
  /* v65 */
  .t-nar td.mono,.t-tor td.mono,.nw{white-space:nowrap}
  .t-nar tr.is-cancelled td{color:#8a887f}.t-nar tr.is-cancelled .badge{color:#5C5A53;border-color:#5C5A53;border-style:solid}
  .t-nar td:nth-child(2){white-space:nowrap}
  button.alt.danger{margin-left:8px}.arrow-gap{display:inline-block;width:32px}
  /* v67: gumbi u Torbama smiju u drugi redak — tablica ne smije biti šira od stranice („Obriši” je bio odrezan) */
  @media (min-width:701px){.t-tor td.acts{white-space:normal;width:300px}.t-tor td.acts form{display:inline-block;margin:0 4px 6px 0}}
  .t-nar .badge{white-space:normal}.t-nar tr.is-cancelled .muted,.t-nar tr.is-cancelled a{color:#8a887f}
  @media (min-width:701px){.t-tor td:nth-child(5){min-width:180px}}
  button.alt.danger{color:#9B2C1F;border-color:#9B2C1F}
  a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid var(--thread);outline-offset:2px}
  @media (min-width:701px){.t-tor td:nth-child(2){min-width:220px}}
  .badge{display:inline-block;font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;padding:2px 7px;border:1.5px dashed var(--thread);color:var(--thread);border-radius:2px;margin:0 3px 3px 0}.badge.ink{border-style:solid;background:var(--ink);color:var(--paper);border-color:var(--ink)}.badge.grey{border-color:#A8A69C;color:#7a786f}.badge.green{background:#E4EAE5}
  .muted{color:var(--soft);font-size:.85rem}.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.82rem}pre{margin:0;white-space:pre-wrap;font:inherit;font-size:.85rem}
  /* v68: Rok istekao, storno s razlogom, kućice s mjerama */
  .rokl{display:flex;flex-direction:column;gap:10px;margin-bottom:18px}.card.rok{margin:0;display:flex;flex-direction:column;gap:6px;border-left:3px solid #9B2C1F}
  form.cancelf select,form.cancelf input{display:inline-block;width:auto;max-width:100%;margin:0 4px 4px 0;padding:6px 8px;border:1px solid var(--line);border-radius:2px;font:inherit;font-size:16px;background:#fff}
  label.chip{border:1px solid var(--line);border-radius:2px;padding:8px 12px;background:#fff;margin:0}label.chip:has(input:checked){border-color:var(--thread);background:#E4EAE5}
  .photos{display:flex;gap:14px;flex-wrap:wrap;margin:6px 0 14px}.photo{width:120px}.photo img{width:120px;height:150px;object-fit:cover;border-radius:2px;display:block;margin-bottom:6px}
</style></head><body>
<header><div class="brand">SHIVA<span>.</span>J <span class="muted" style="letter-spacing:0;font-size:.8rem">admin</span></div><nav>' . $navHtml . '</nav></header>
<main><h1>' . e($title) . '</h1>';
  if ($msg !== '') echo '<div class="msg">' . e($msg) . '</div>';
  if ($err !== '') echo '<div class="err">' . e($err) . '</div>';
  /* v25: upozorenje na svakom ekranu dok adresa stranice ili adresa pošiljatelja nisu upisane */
  if ($nav && $s !== 'postavke') {
    $miss = [];
    if (trim((string)($c['site_url'] ?? '')) === '') $miss[] = 'adresa stranice';
    if (trim((string)($c['from_email'] ?? '')) === '') $miss[] = 'adresa pošiljatelja na domeni';
    if ($miss) echo '<div class="err">Nije upisano: ' . e(implode(' i ', $miss)) . '. Bez toga narudžbe ili potvrde kupcima mogu zapeti. <a href="?s=postavke">Postavke →</a></div>';
  }
  /* v23: jedna skripta za sve potvrde (data-confirm, data-confirm-sel s imenom odabrane torbe) */
  echo $content . '</main><script nonce="' . $nonce . '">document.addEventListener("submit",function(e){var f=e.target,m=f.getAttribute("data-confirm");'
    . 'var ms=f.getAttribute("data-confirm-sel");if(ms){var sel=f.querySelector("select");if(!sel||!sel.value){e.preventDefault();return;}m=ms.replace("%s",sel.options[sel.selectedIndex].text);}'
    . 'if(m&&!window.confirm(m))e.preventDefault();},true);'
    /* v63: polja koja vrijede samo za Stalnu kolekciju / Ostalo */
    . 'var dw=document.getElementById("dimsWrap");document.querySelectorAll("input[name=size]").forEach(function(r){r.addEventListener("change",function(){if(dw)dw.hidden=r.value!=="druga";});});'
    . 'var vs=document.getElementById("vrsta");function sv(){if(!vs)return;document.querySelectorAll("[data-vrsta]").forEach(function(el){el.hidden=el.getAttribute("data-vrsta").split(" ").indexOf(vs.value)<0;});}if(vs){vs.addEventListener("change",sv);sv();}'
    . '</script></body></html>';
}
