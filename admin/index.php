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
$msg = sj_clean((string)($_GET['m'] ?? ''), 200);
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
    sj_layout('Admin nije postavljen', '<p>Lozinka još nije postavljena, a datoteka <code>data/PRVA-PRIJAVA.txt</code> ne postoji. Prenesite je iz paketa (ili napravite praznu datoteku tog imena u mapi <code>data/</code>) i osvježite ovu stranicu.</p>', $c, '', '', false);
    exit;
  }
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['setup_password'])) {
    $p1 = (string)$_POST['setup_password']; $p2 = (string)($_POST['setup_password2'] ?? '');
    if (strlen($p1) < 10) $err = 'Lozinka mora imati barem 10 znakova.';
    elseif ($p1 !== $p2) $err = 'Lozinke se ne podudaraju.';
    else {
      $c['password_hash'] = password_hash($p1, PASSWORD_DEFAULT);
      sj_save_config($c);
      @unlink(sj_setup_path());
      sj_login($p1);
      header('Location: ?s=torbe&m=' . urlencode('Lozinka je postavljena. Dobrodošli!')); exit;
    }
  }
  sj_layout('Prva prijava', '
    <p>Ovo je prvo otvaranje admina. Odaberite lozinku (barem 10 znakova) i zapišite je na sigurno. Nakon toga se datoteka <code>data/PRVA-PRIJAVA.txt</code> briše i ovaj se korak više ne može ponoviti bez nje.</p>
    <form method="post" class="card">
      <label>Nova lozinka <input type="password" name="setup_password" required minlength="10" autocomplete="new-password"></label>
      <label>Ponovite lozinku <input type="password" name="setup_password2" required minlength="10" autocomplete="new-password"></label>
      <button>Spremi lozinku</button>
    </form>', $c, '', $err, false);
  exit;
}

/* ---------- prijava / odjava ---------- */
if (isset($_GET['odjava'])) { sj_logout(); header('Location: ./'); exit; }
if (!sj_logged_in()) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    if (sj_login((string)$_POST['password'])) { header('Location: ?s=torbe'); exit; }
    $err = 'Pogrešna lozinka.';
  }
  sj_layout('Prijava', '
    <form method="post" class="card">
      <label>Lozinka <input type="password" name="password" required autocomplete="current-password" autofocus></label>
      <button>Prijava</button>
    </form>', $c, '', $err, false);
  exit;
}

/* ---------- radnje ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!sj_check_csrf()) {
    $err = 'Sesija je istekla ili je obrazac zastario. Pokušajte ponovno.';
  } else {
    try {
      $msg = sj_handle_action((string)($_POST['act'] ?? ''), $c);
      $back = (string)($_POST['back'] ?? '?s=torbe');
      if (!preg_match('/^\?[A-Za-z0-9=&_-]*$/', $back)) $back = '?s=torbe';
      header('Location: ' . $back . '&m=' . urlencode($msg)); exit;
    } catch (Throwable $ex) {
      $err = $ex->getMessage();
    }
  }
}

$s = (string)($_GET['s'] ?? 'torbe');
switch ($s) {
  case 'nova':
  case 'uredi':    sj_layout($s === 'nova' ? 'Nova torba / artikl' : 'Uredi', sj_view_form($c, $s === 'uredi' ? (string)($_GET['id'] ?? '') : ''), $c, $msg, $err); break;
  case 'rez':      sj_layout('Rezervacije', sj_view_reservations($c), $c, $msg, $err); break;
  case 'narudzbe': sj_layout('Narudžbe', sj_view_orders(), $c, $msg, $err); break;
  case 'postavke': sj_layout('Postavke', sj_view_settings($c), $c, $msg, $err); break;
  default:         sj_layout('Torbe i artikli', sj_view_products(), $c, $msg, $err);
}
exit;

/* =========================================================
   RADNJE
   ========================================================= */
function sj_handle_action(string $act, array &$c): string {
  switch ($act) {

    case 'save_product': {
      $list = sj_products();
      $oldId = sj_clean((string)($_POST['id'] ?? ''), 40);
      $name = sj_clean((string)($_POST['name'] ?? ''), 80);
      if ($name === '') throw new RuntimeException('Naziv je obavezan.');
      $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
      if ($price < 0) $price = 0.0;
      $made = !empty($_POST['made']);
      $p = [
        'id'       => '',
        'cat'      => (($_POST['cat'] ?? 'torbe') === 'ostalo') ? 'ostalo' : 'torbe',
        'name'     => $name,
        'desc'     => sj_clean((string)($_POST['desc'] ?? ''), 200),
        'price'    => round($price, 2),
        'dims'     => sj_clean((string)($_POST['dims'] ?? ''), 80),
        'material' => sj_clean((string)($_POST['material'] ?? ''), 160),
        'imgs'     => [],
        'sold'     => !empty($_POST['sold']),
      ];
      if ($made) { $p['made'] = true; $p['lead'] = sj_clean((string)($_POST['lead'] ?? ''), 60); }
      if (!empty($_POST['reserved']) && !$made) $p['reserved'] = true;
      if (!empty($_POST['hidden'])) $p['hidden'] = true;

      $idx = null;
      foreach ($list as $i => $x) if (($x['id'] ?? '') === $oldId && $oldId !== '') $idx = $i;
      if ($idx !== null) { $p['id'] = $oldId; $p['imgs'] = array_values((array)($list[$idx]['imgs'] ?? [])); }
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

      if ($idx !== null) $list[$idx] = $p; else $list[] = $p;
      sj_save_products($list);
      return 'Spremljeno: ' . $name;
    }

    case 'delete_product': {
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      $list = array_values(array_filter(sj_products(), fn($p) => ($p['id'] ?? '') !== $id));
      sj_save_products($list);
      return 'Obrisano: ' . $id . ' (fotografije ostaju u mapi img)';
    }

    case 'move': {
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      $dir = ((string)($_POST['dir'] ?? '') === 'up') ? -1 : 1;
      $list = sj_products();
      foreach ($list as $i => $p) {
        if (($p['id'] ?? '') === $id) {
          $j = $i + $dir;
          if ($j >= 0 && $j < count($list)) { [$list[$i], $list[$j]] = [$list[$j], $list[$i]]; sj_save_products($list); }
          break;
        }
      }
      return 'Redoslijed promijenjen';
    }

    case 'toggle': {
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      $field = (string)($_POST['field'] ?? '');
      if (!in_array($field, ['sold', 'hidden', 'reserved'], true)) throw new RuntimeException('Nepoznato polje.');
      $list = sj_products();
      foreach ($list as $i => $p) {
        if (($p['id'] ?? '') === $id) {
          $on = empty($p[$field]);
          if ($on) $list[$i][$field] = true; else unset($list[$i][$field]);
          if ($field === 'sold' && !$on) $list[$i]['sold'] = false;
          sj_save_products($list);
          return ($on ? 'Uključeno: ' : 'Isključeno: ') . ['sold' => 'prodano', 'hidden' => 'skriveno', 'reserved' => 'rezervirano'][$field] . ' — ' . ($p['name'] ?? $id);
        }
      }
      throw new RuntimeException('Torba nije nađena.');
    }

    case 'res_release':
    case 'res_sold':
    case 'res_unsold':
    case 'res_sold_manual': {
      $id = sj_clean((string)($_POST['id'] ?? ''), 40);
      if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) throw new RuntimeException('Neispravna oznaka torbe.');
      sj_with_lock(sj_res_path(), function (array &$d) use ($act, $id) {
        $d = array_replace(sj_res_default(), $d);
        if ($act === 'res_release') unset($d['res'][$id]);
        elseif ($act === 'res_unsold') unset($d['sold'][$id]);
        else sj_mark_paid($d, $id);
        return true;
      }, sj_res_default());
      return ['res_release' => 'Rezervacija stornirana: ', 'res_sold' => 'Označeno kao plaćeno: ', 'res_unsold' => 'Oznaka uklonjena: ', 'res_sold_manual' => 'Označeno kao prodano: '][$act] . $id;
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
      $c['site_url'] = rtrim(sj_clean((string)($_POST['site_url'] ?? ''), 120), '/');
      $c['ttl_hours'] = $ttl;
      $c['confirm_customer'] = !empty($_POST['confirm_customer']);
      $c['mail_mode'] = (($_POST['mail_mode'] ?? 'mail') === 'log') ? 'log' : 'mail';
      $c['payment']['recipient'] = sj_clean((string)($_POST['recipient'] ?? ''), 80);
      $c['payment']['address'] = sj_clean((string)($_POST['address'] ?? ''), 120);
      $c['payment']['iban'] = $iban;
      $c['payment']['model'] = sj_clean((string)($_POST['model'] ?? 'HR00'), 4) ?: 'HR00';
      $c['pickup_info'] = sj_clean((string)($_POST['pickup_info'] ?? ''), 200);
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
      sj_save_config($c);
      return 'Postavke spremljene';
    }

    case 'change_password': {
      $old = (string)($_POST['old_password'] ?? ''); $n1 = (string)($_POST['new_password'] ?? ''); $n2 = (string)($_POST['new_password2'] ?? '');
      if (!password_verify($old, $c['password_hash'])) throw new RuntimeException('Stara lozinka nije točna.');
      if (strlen($n1) < 10) throw new RuntimeException('Nova lozinka mora imati barem 10 znakova.');
      if ($n1 !== $n2) throw new RuntimeException('Nove lozinke se ne podudaraju.');
      $c['password_hash'] = password_hash($n1, PASSWORD_DEFAULT);
      sj_save_config($c);
      return 'Lozinka promijenjena';
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
  $h = '<form method="post" class="inline"' . ($confirm ? ' onsubmit="return confirm(' . e(json_encode($confirm, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>';
  $h .= '<input type="hidden" name="csrf" value="' . e(sj_csrf()) . '"><input type="hidden" name="act" value="' . e($act) . '"><input type="hidden" name="back" value="' . e($back) . '">';
  foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
  $h .= '<button' . ($alt ? ' class="alt"' : '') . '>' . e($label) . '</button></form>';
  return $h;
}

function sj_state_badges(array $p): string {
  $b = [];
  if (!empty($p['hidden'])) $b[] = '<span class="badge grey">skriveno</span>';
  if (!empty($p['sold'])) $b[] = '<span class="badge ink">' . (!empty($p['made']) ? 'nedostupno' : 'prodano') . '</span>';
  elseif (!empty($p['reserved'])) $b[] = '<span class="badge">rezervirano (ručno)</span>';
  if (!empty($p['made'])) $b[] = '<span class="badge green">po narudžbi</span>';
  return implode(' ', $b);
}

function sj_view_products(): string {
  $list = sj_products();
  $res = sj_res_read();
  $h = '<p class="muted">Ono što je ovdje spremljeno odmah je na stranici (datoteka torbe.json). Skrivene torbe ostaju ovdje, ali nisu na stranici.</p>';
  $h .= '<p><a class="btn" href="?s=nova">+ Nova torba / artikl</a></p>';
  if (!$list) return $h . '<p class="muted">Još nema torbi.</p>';
  $h .= '<div class="tablewrap"><table><thead><tr><th></th><th>Naziv</th><th>Kategorija</th><th>Cijena</th><th>Stanje</th><th></th></tr></thead><tbody>';
  $n = count($list);
  foreach ($list as $i => $p) {
    $id = (string)($p['id'] ?? '');
    $img = !empty($p['imgs'][0]) ? '<img src="../' . e($p['imgs'][0]) . '" alt="">' : '<span class="noimg">' . e(mb_substr((string)($p['name'] ?? '?'), 0, 1)) . '</span>';
    $live = isset($res['sold'][$id]) ? '<span class="badge ink">plaćeno (rez.)</span>' : (isset($res['res'][$id]) ? '<span class="badge">rezervirano do ' . e(sj_fmt_hr($res['res'][$id]['until'])) . '</span>' : '');
    $back = '?s=torbe';
    $h .= '<tr' . (!empty($p['hidden']) ? ' class="dim"' : '') . '>';
    $h .= '<td class="thumb">' . $img . '</td>';
    $h .= '<td><b>' . e($p['name'] ?? '') . '</b><br><span class="muted">' . e($p['desc'] ?? '') . '</span><br><span class="muted mono">' . e($id) . '</span></td>';
    $h .= '<td>' . e(($p['cat'] ?? 'torbe') === 'ostalo' ? 'Ostalo' : 'Torbe') . '</td>';
    $h .= '<td class="mono">' . e(sj_fmt_eur((float)($p['price'] ?? 0))) . '</td>';
    $h .= '<td>' . sj_state_badges($p) . ' ' . $live . '</td>';
    $h .= '<td class="acts"><a class="btn small" href="?s=uredi&id=' . e($id) . '">Uredi</a> ';
    $h .= sj_btn('toggle', ['id' => $id, 'field' => 'sold'], !empty($p['sold']) ? (!empty($p['made']) ? 'Dostupno' : 'Vrati u prodaju') : (!empty($p['made']) ? 'Nedostupno' : 'Prodano'), $back, true);
    $h .= sj_btn('toggle', ['id' => $id, 'field' => 'hidden'], !empty($p['hidden']) ? 'Prikaži' : 'Sakrij', $back, true);
    if ($i > 0) $h .= sj_btn('move', ['id' => $id, 'dir' => 'up'], '↑', $back, true);
    if ($i < $n - 1) $h .= sj_btn('move', ['id' => $id, 'dir' => 'down'], '↓', $back, true);
    $h .= sj_btn('delete_product', ['id' => $id], 'Obriši', $back, true, 'Obrisati „' . ($p['name'] ?? $id) . '“? Fotografije ostaju u mapi img.');
    $h .= '</td></tr>';
  }
  return $h . '</tbody></table></div>';
}

function sj_view_form(array $c, string $id): string {
  $p = ['id' => '', 'cat' => 'torbe', 'name' => '', 'desc' => '', 'price' => '', 'dims' => '', 'material' => '', 'imgs' => [], 'sold' => false, 'lead' => ''];
  if ($id !== '') {
    foreach (sj_products() as $x) if (($x['id'] ?? '') === $id) $p = array_replace($p, $x);
    if ($p['id'] === '') return '<p>Torba nije nađena.</p>';
  }
  $made = !empty($p['made']);
  $h = '<form method="post" enctype="multipart/form-data" class="card form">';
  $h .= '<input type="hidden" name="csrf" value="' . e(sj_csrf()) . '"><input type="hidden" name="act" value="save_product"><input type="hidden" name="id" value="' . e($p['id']) . '"><input type="hidden" name="back" value="?s=torbe">';
  $h .= '<div class="grid2">';
  $h .= '<label>Naziv * <input name="name" required maxlength="80" value="' . e($p['name']) . '"></label>';
  $h .= '<label>Cijena (€) * <input name="price" required inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,2})?" value="' . e($p['price'] === '' ? '' : number_format((float)$p['price'], 2, ',', '')) . '"></label>';
  $h .= '<label>Kategorija <select name="cat"><option value="torbe"' . ($p['cat'] !== 'ostalo' ? ' selected' : '') . '>Torbe (unikati)</option><option value="ostalo"' . ($p['cat'] === 'ostalo' ? ' selected' : '') . '>Ostalo (po narudžbi)</option></select></label>';
  $h .= '<label class="check"><input type="checkbox" name="made" value="1"' . ($made ? ' checked' : '') . '> Artikl po narudžbi (nije unikat, kupac bira količinu)</label>';
  $h .= '</div>';
  $h .= '<label>Kratki opis <input name="desc" maxlength="200" value="' . e($p['desc']) . '" placeholder="npr. Okrugla torba · patchwork u pastelnim tonovima"></label>';
  $h .= '<div class="grid2">';
  $h .= '<label>Dimenzije <input name="dims" maxlength="80" value="' . e($p['dims']) . '" placeholder="npr. 30 × 30 × 10 cm"></label>';
  $h .= '<label>Materijal <input name="material" maxlength="160" value="' . e($p['material']) . '"></label>';
  $h .= '<label>Rok izrade (samo po narudžbi) <input name="lead" maxlength="60" value="' . e($p['lead'] ?? '') . '" placeholder="npr. 5 radnih dana"></label>';
  $h .= '<div class="stack">';
  $h .= '<label class="check"><input type="checkbox" name="sold" value="1"' . (!empty($p['sold']) ? ' checked' : '') . '> Prodano / trenutno nedostupno</label>';
  $h .= '<label class="check"><input type="checkbox" name="reserved" value="1"' . (!empty($p['reserved']) ? ' checked' : '') . '> Rezervirano ručno (npr. dogovor porukom)</label>';
  $h .= '<label class="check"><input type="checkbox" name="hidden" value="1"' . (!empty($p['hidden']) ? ' checked' : '') . '> Skriveno (nije na stranici)</label>';
  $h .= '</div></div>';

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
  $names = [];
  foreach (sj_products() as $p) $names[$p['id'] ?? ''] = $p['name'] ?? '';
  $back = '?s=rez';
  $h = '<h3>Aktivne rezervacije — istječu nakon ' . (int)$c['ttl_hours'] . ' h</h3>';
  if (!$d['res']) $h .= '<p class="muted">Trenutno nema rezervacija.</p>';
  else {
    $h .= '<div class="tablewrap"><table><thead><tr><th>Torba</th><th>Kupac</th><th>Rezervirano</th><th>Vrijedi do</th><th></th></tr></thead><tbody>';
    foreach ($d['res'] as $id => $r) {
      $h .= '<tr><td><b>' . e($names[$id] ?? $id) . '</b><br><span class="muted mono">' . e($id) . '</span></td><td>' . e($r['name']) . '<br><span class="muted">' . e($r['email']) . '</span></td><td>' . e(sj_fmt_hr($r['since'])) . '</td><td>' . e(sj_fmt_hr($r['until'])) . '</td>';
      $h .= '<td class="acts">' . sj_btn('res_sold', ['id' => $id], 'Plaćeno', $back) . sj_btn('res_release', ['id' => $id], 'Storniraj', $back, true) . '</td></tr>';
    }
    $h .= '</tbody></table></div>';
  }
  $h .= '<h3>Označeno kao plaćeno</h3>';
  if (!$d['sold']) $h .= '<p class="muted">Nema torbi označenih kao plaćeno.</p>';
  else {
    $h .= '<div class="tablewrap"><table><thead><tr><th>Torba</th><th>Kupac</th><th>Označeno</th><th></th></tr></thead><tbody>';
    foreach ($d['sold'] as $id => $r) {
      $h .= '<tr><td><b>' . e($names[$id] ?? $id) . '</b><br><span class="muted mono">' . e($id) . '</span></td><td>' . e($r['name']) . '<br><span class="muted">' . e($r['email']) . '</span></td><td>' . e(sj_fmt_hr($r['paidAt'] ?? '')) . '</td>';
      $h .= '<td class="acts">' . sj_btn('toggle', ['id' => $id, 'field' => 'sold'], 'Trajno prodano (torba)', $back) . sj_btn('res_unsold', ['id' => $id], 'Ukloni oznaku', $back, true) . '</td></tr>';
    }
    $h .= '</tbody></table></div>';
  }
  $opts = '';
  foreach ($names as $id => $n) $opts .= '<option value="' . e($id) . '">' . e($n) . ' (' . e($id) . ')</option>';
  $h .= '<h3>Ručno označi plaćeno</h3><form method="post" class="inline"><input type="hidden" name="csrf" value="' . e(sj_csrf()) . '"><input type="hidden" name="act" value="res_sold_manual"><input type="hidden" name="back" value="' . e($back) . '"><select name="id">' . $opts . '</select> <button>Plaćeno</button></form>';
  $h .= '<p class="muted">Tijek: narudžba → kupac uplati i pošalje potvrdu → „Plaćeno“ (ovdje ili iz e-maila) → „Trajno prodano“ na torbi kad stignete. „Storniraj“ i „Ukloni oznaku“ vraćaju torbu u prodaju.</p>';
  return $h;
}

function sj_view_orders(): string {
  $list = sj_read_json(SJ_DATA . '/narudzbe.json', []);
  if (!$list) return '<p class="muted">Još nema narudžbi.</p>';
  $h = '<div class="tablewrap"><table><thead><tr><th>Broj</th><th>Vrijeme</th><th>Kupac</th><th>Artikli</th><th>Dostava</th><th>Ukupno</th></tr></thead><tbody>';
  foreach (array_slice($list, 0, 100) as $o) {
    $h .= '<tr><td class="mono">' . e($o['no'] ?? '') . '</td><td>' . e(sj_fmt_hr($o['at'] ?? '')) . '</td><td>' . e($o['name'] ?? '') . '<br><span class="muted">' . e($o['email'] ?? '') . '</span></td><td><pre>' . e($o['items'] ?? '') . '</pre></td><td>' . e($o['shipping'] ?? '') . '</td><td class="mono">' . e($o['total'] ?? '') . '</td></tr>';
  }
  return $h . '</tbody></table></div><p class="muted">Čuva se zadnjih 300 narudžbi. Puni sadržaj svake narudžbe stiže i e-mailom.</p>';
}

function sj_view_settings(array $c): string {
  $p = $c['payment'];
  $csrf = e(sj_csrf());
  $h = '<form method="post" class="card form"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="save_settings"><input type="hidden" name="back" value="?s=postavke">';
  $h .= '<h3>E-mail i stranica</h3><div class="grid2">';
  $h .= '<label>E-mail vlasnice (narudžbe stižu ovdje) * <input name="owner_email" required value="' . e($c['owner_email']) . '"></label>';
  $h .= '<label>Adresa pošiljatelja na domeni (npr. info@shivaj.hr) <input name="from_email" value="' . e($c['from_email']) . '"></label>';
  $h .= '<label>Adresa stranice (npr. https://shivaj.hr) <input name="site_url" value="' . e($c['site_url']) . '" placeholder="prazno = automatski"></label>';
  $h .= '<label>Rezervacija traje (sati) <input name="ttl_hours" type="number" min="1" max="168" value="' . e((string)$c['ttl_hours']) . '"></label>';
  $h .= '</div><div class="stack">';
  $h .= '<label class="check"><input type="checkbox" name="confirm_customer" value="1"' . (!empty($c['confirm_customer']) ? ' checked' : '') . '> Automatski pošalji kupcu potvrdu narudžbe s podacima za uplatu i barkodom</label>';
  $h .= '<label class="check"><input type="checkbox" name="mail_mode" value="log"' . ($c['mail_mode'] === 'log' ? ' checked' : '') . '> Testni način: e-mailove ne šalji, nego zapiši u data/mail-log.txt</label>';
  $h .= '</div>';
  $h .= '<h3>Podaci za uplatu</h3><div class="grid2">';
  $h .= '<label>Primatelj <input name="recipient" value="' . e($p['recipient']) . '"></label>';
  $h .= '<label>Adresa primatelja <input name="address" value="' . e($p['address']) . '"></label>';
  $h .= '<label>IBAN <input name="iban" value="' . e($p['iban']) . '"></label>';
  $h .= '<label>Model <input name="model" value="' . e($p['model']) . '" maxlength="4"></label>';
  $h .= '</div>';
  $h .= '<label>Osobno preuzimanje (tekst u potvrdi) <input name="pickup_info" value="' . e($c['pickup_info']) . '"></label>';
  $shipLines = '';
  foreach (sj_shipping() as $o) $shipLines .= $o['id'] . ' | ' . $o['label'] . ' | ' . number_format($o['price'], 2, ',', '') . ' | ' . ($o['address'] ? 'da' : 'ne') . ' | ' . ($o['locker'] ? 'da' : 'ne') . ' | ' . $o['note'] . "\n";
  $h .= '<h3>Načini dostave</h3><label>Jedan način po retku: <span class="mono">oznaka | naziv | cijena € | traži adresu (da/ne) | BOX NOW paketomat (da/ne) | napomena</span>. Prvi redak je zadani. Stranica i potvrde kupcu čitaju cijene odavde.<textarea name="shipping" rows="4" style="display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid var(--line);border-radius:2px;font:inherit;font-size:.85rem">' . e($shipLines) . '</textarea></label>';
  $h .= '<div class="row"><button>Spremi postavke</button></div></form>';

  $h .= '<form method="post" class="card form"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="act" value="change_password"><input type="hidden" name="back" value="?s=postavke"><h3>Promjena lozinke</h3><div class="grid2">';
  $h .= '<label>Stara lozinka <input type="password" name="old_password" required autocomplete="current-password"></label>';
  $h .= '<label>Nova lozinka (barem 10 znakova) <input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>';
  $h .= '<label>Ponovite novu lozinku <input type="password" name="new_password2" required minlength="10" autocomplete="new-password"></label>';
  $h .= '</div><div class="row"><button class="alt">Promijeni lozinku</button></div></form>';

  $h .= '<div class="card"><h3>Provjera</h3><p class="muted">Pošalje probnu poruku na e-mail vlasnice. Stranica: <a href="' . e(sj_site_url()) . '/">' . e(sj_site_url()) . '</a> · API: <a href="' . e(sj_site_url()) . '/api/status">status</a> · ' . e(SJ_VERSION) . ' · PHP ' . e(PHP_VERSION) . ' · GD ' . (function_exists('imagecreatefromjpeg') ? 'da' : 'NE') . '</p>';
  $h .= sj_btn('test_mail', [], 'Pošalji probni e-mail', '?s=postavke', true) . '</div>';
  return $h;
}

/* ---------- okvir stranice ---------- */
function sj_layout(string $title, string $content, array $c, string $msg, string $err, bool $nav = true): void {
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  header('Referrer-Policy: no-referrer');
  header('X-Frame-Options: DENY');
  $s = (string)($_GET['s'] ?? 'torbe');
  $navHtml = '';
  if ($nav) {
    $items = ['torbe' => 'Torbe', 'rez' => 'Rezervacije', 'narudzbe' => 'Narudžbe', 'postavke' => 'Postavke'];
    foreach ($items as $k => $v) $navHtml .= '<a href="?s=' . $k . '"' . (($s === $k || ($k === 'torbe' && in_array($s, ['nova', 'uredi'], true))) ? ' class="on"' : '') . '>' . $v . '</a>';
    $navHtml .= '<a href="../" target="_blank" rel="noopener">Stranica ↗</a><a href="?odjava=1">Odjava</a>';
  }
  echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' — ' . e($c['shop']) . ' admin</title>
<style>
  :root{--paper:#F6F6F3;--ink:#1B1A17;--soft:#5C5A53;--thread:#2E4A3A;--line:#DDDCD5}
  *{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
  header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px 20px;border-bottom:1.5px dashed var(--thread);flex-wrap:wrap}
  .brand{font-size:1.1rem;letter-spacing:.14em}.brand span{color:var(--thread)}
  nav{display:flex;gap:14px;flex-wrap:wrap}nav a{color:var(--soft);text-decoration:none;font-size:.85rem;letter-spacing:.06em;text-transform:uppercase;padding-bottom:2px;border-bottom:1.5px dashed transparent}nav a.on,nav a:hover{color:var(--thread);border-bottom-color:var(--thread)}
  main{max-width:1100px;margin:0 auto;padding:24px 20px 60px}h1{font-weight:400;font-size:1.5rem;margin:0 0 14px}h3{font-weight:400;font-size:1.05rem;margin:22px 0 8px;letter-spacing:.03em}
  .msg{background:#E4EAE5;border:1.5px dashed var(--thread);padding:10px 14px;border-radius:2px;margin:0 0 16px}.err{background:#F3E1DC;border:1.5px dashed #8A3B2E;padding:10px 14px;border-radius:2px;margin:0 0 16px}
  .card{background:#fff;border:1px solid var(--line);border-radius:2px;padding:18px;margin:0 0 18px}
  .form label{display:block;margin:0 0 12px;font-size:.85rem;color:var(--soft)}.form input:not([type=checkbox]):not([type=radio]),.form select{display:block;width:100%;margin-top:4px;padding:9px 10px;border:1px solid var(--line);border-radius:2px;font:inherit;color:var(--ink);background:#fff}
  .form input[type=file]{padding:6px 0}.form input:focus,.form select:focus{outline:2px solid var(--thread);outline-offset:-1px}
  label.check{display:flex;gap:8px;align-items:center;font-size:.9rem;color:var(--ink)}label.check input{margin:0}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}.stack{display:flex;flex-direction:column;gap:8px;margin:8px 0 12px}
  @media (max-width:700px){.grid2{grid-template-columns:1fr}}
  button,.btn{background:var(--thread);color:var(--paper);border:1.5px solid var(--thread);border-radius:2px;padding:8px 13px;font:inherit;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;text-decoration:none;display:inline-block}
  button.alt,.btn.alt{background:none;color:var(--thread);border-style:dashed}.btn.small,button.small{padding:5px 9px;font-size:.72rem}
  .inline{display:inline-block;margin:0 4px 4px 0}.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
  .tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:.9rem;background:#fff}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--soft)}
  td.thumb{width:64px}td.thumb img{width:56px;height:70px;object-fit:cover;border-radius:2px}.noimg{display:inline-flex;width:56px;height:70px;align-items:center;justify-content:center;background:#E4EAE5;color:var(--thread);border-radius:2px;font-size:1.3rem}
  td.acts{white-space:nowrap}td.acts form{margin-bottom:4px}td.acts button{padding:5px 9px;font-size:.72rem}tr.dim td{opacity:.55}
  .badge{display:inline-block;font-size:.7rem;letter-spacing:.06em;text-transform:uppercase;padding:2px 7px;border:1.5px dashed var(--thread);color:var(--thread);border-radius:2px;margin:0 3px 3px 0}.badge.ink{border-style:solid;background:var(--ink);color:var(--paper);border-color:var(--ink)}.badge.grey{border-color:#A8A69C;color:#7a786f}.badge.green{background:#E4EAE5}
  .muted{color:var(--soft);font-size:.85rem}.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.82rem}pre{margin:0;white-space:pre-wrap;font:inherit;font-size:.85rem}
  .photos{display:flex;gap:14px;flex-wrap:wrap;margin:6px 0 14px}.photo{width:120px}.photo img{width:120px;height:150px;object-fit:cover;border-radius:2px;display:block;margin-bottom:6px}
</style></head><body>
<header><div class="brand">SHIVA<span>.</span>J <span class="muted" style="letter-spacing:0;font-size:.8rem">admin</span></div><nav>' . $navHtml . '</nav></header>
<main><h1>' . e($title) . '</h1>';
  if ($msg !== '') echo '<div class="msg">' . e($msg) . '</div>';
  if ($err !== '') echo '<div class="err">' . e($err) . '</div>';
  echo $content . '</main></body></html>';
}
