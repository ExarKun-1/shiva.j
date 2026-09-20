<?php
/* =========================================================
   SHIVA.J — javni API na hostingu   (api/index.php v01)
   Zamjenjuje Cloudflare worker i Formspree:
     GET  api/status          rezervirane i prodane torbe, ttlHours
     POST api/reserve         rezervacija torbi iz narudžbe (409 ako zauzeto)
     POST api/order           zaprimanje narudžbe: sprema, e-mail vlasnici,
                              automatska potvrda kupcu s barkodom
     GET/POST api/storno      link iz e-maila: Plaćeno / Storniraj / Ukloni oznaku
   ========================================================= */
declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';

$c = sj_config();
$a = (string)($_GET['a'] ?? '');
if ($a === '') {
  $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
  if (preg_match('#/(status|reserve|order|storno)/?$#', $path, $m)) $a = $m[1];
}
$a = ltrim($a, '/');

/* ---------- CORS: vlastita domena uvijek, dodatni izvori iz postavki ---------- */
function sj_origin_allowed(string $origin, array $c): bool {
  if ($origin === '') return true;
  $oh = (string)(parse_url($origin, PHP_URL_HOST) ?? '');
  $host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0];
  if ($oh !== '' && strcasecmp($oh, $host) === 0) return true;
  if (in_array($origin, $c['allowed_origins'] ?? [], true)) return true;
  if (in_array($host, ['localhost', '127.0.0.1'], true) && in_array($oh, ['localhost', '127.0.0.1'], true)) return true;
  return false;
}
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$originOk = sj_origin_allowed($origin, $c);
if ($origin !== '' && $originOk) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function sj_body_json(): ?array {
  $raw = file_get_contents('php://input');
  $d = json_decode((string)$raw, true);
  return is_array($d) ? $d : null;
}

function sj_storno_url(string $token): string { return sj_site_url() . '/api/storno?token=' . $token; }

/* ---------- status ---------- */
if ($a === 'status') {
  $d = sj_res_read();
  $until = new stdClass();
  foreach ($d['res'] as $id => $r) $until->$id = $r['until'];
  sj_json_out(['ok' => true, 'ttlHours' => (int)$c['ttl_hours'], 'ids' => array_keys($d['res']), 'until' => $until, 'sold' => array_keys($d['sold'])]);
}

/* ---------- reserve ---------- */
if ($a === 'reserve') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sj_json_out(['ok' => false, 'error' => 'POST only'], 405);
  if (!$originOk) sj_json_out(['ok' => false, 'error' => 'origin not allowed'], 403);
  $b = sj_body_json();
  $ids = [];
  foreach ((array)($b['ids'] ?? []) as $id) { $id = sj_clean((string)$id, 40); if ($id !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $id)) $ids[$id] = true; }
  $ids = array_keys($ids);
  if (!$ids || count($ids) > 6) sj_json_out(['ok' => false, 'error' => 'bad request'], 400);

  $ttl = max(1, (int)$c['ttl_hours']);
  $name = sj_clean((string)($b['name'] ?? ''), 80);
  $email = sj_clean((string)($b['email'] ?? ''), 120);

  $result = sj_with_lock(sj_res_path(), function (array &$d) use ($ids, $ttl, $name, $email) {
    $d = array_replace(sj_res_default(), $d);
    sj_res_prune($d);
    $taken = [];
    foreach ($ids as $id) if (isset($d['res'][$id]) || isset($d['sold'][$id])) $taken[] = $id;
    if ($taken) return ['taken' => $taken];
    $token = sj_token();
    $now = time();
    $rec = ['token' => $token, 'name' => $name, 'email' => $email, 'since' => sj_iso($now), 'until' => sj_iso($now + $ttl * 3600)];
    foreach ($ids as $id) $d['res'][$id] = $rec + ['id' => $id];
    return ['token' => $token, 'until' => $rec['until']];
  }, sj_res_default());

  if (isset($result['taken'])) sj_json_out(['ok' => false, 'taken' => $result['taken']], 409);
  sj_json_out(['ok' => true, 'ids' => $ids, 'token' => $result['token'], 'until' => $result['until'], 'ttlHours' => $ttl, 'storno' => sj_storno_url($result['token'])]);
}

/* ---------- order ---------- */
if ($a === 'order') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sj_json_out(['ok' => false, 'error' => 'POST only'], 405);
  if (!$originOk) sj_json_out(['ok' => false, 'error' => 'origin not allowed'], 403);
  $b = sj_body_json();
  if (!$b) sj_json_out(['ok' => false, 'error' => 'bad request'], 400);
  if (!empty($b['_gotcha'])) sj_json_out(['ok' => true]); /* bot: tiho ignoriraj */

  $o = is_array($b['_order'] ?? null) ? $b['_order'] : [];
  $cust = is_array($o['customer'] ?? null) ? $o['customer'] : [];
  $custEmail = sj_clean((string)($cust['email'] ?? ($b['_replyto'] ?? '')), 120);
  $custName = sj_clean((string)($cust['name'] ?? ($b['Kupac'] ?? '')), 80);

  /* broj narudžbe */
  $seq = 0;
  sj_config_update(function (array &$cfg) use (&$seq) {
    $cfg['order_seq'] = (int)($cfg['order_seq'] ?? 0) + 1;
    $seq = (int)$cfg['order_seq'];
  });
  $no = 'SJ-' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

  /* spremi narudžbu (zadnjih 300) */
  $entry = ['no' => $no, 'at' => sj_iso(time()), 'name' => $custName, 'email' => $custEmail, 'total' => sj_clean((string)($b['Ukupno'] ?? ($o['total'] ?? '')), 30),
            'items' => sj_clean((string)($b['Naručene torbe'] ?? ''), 2000), 'shipping' => sj_clean((string)($b['Dostava'] ?? ''), 300), 'order' => $o, 'fields' => []];
  foreach ($b as $k => $v) { if (is_string($k) && $k !== '' && $k[0] !== '_' && is_scalar($v)) $entry['fields'][sj_clean($k, 60)] = sj_clean((string)$v, 2000); }
  sj_with_lock(SJ_DATA . '/narudzbe.json', function (array &$list) use ($entry) {
    array_unshift($list, $entry);
    if (count($list) > 300) $list = array_slice($list, 0, 300);
    return true;
  }, []);

  /* e-mail vlasnici */
  $lines = ["Nova narudžba $no — " . $c['shop'], ""];
  foreach ($entry['fields'] as $k => $v) $lines[] = "$k: $v";
  $lines[] = "";
  $lines[] = "Narudžbe i rezervacije: " . sj_site_url() . "/admin/";
  $subject = "Narudžba $no — " . sj_clean((string)($b['_subject'] ?? $custName), 120);
  $sentOwner = sj_send_mail($c['owner_email'], $subject, implode("\n", $lines), null, $custEmail ?: null);

  /* automatska potvrda kupcu */
  $sentCust = false;
  if (!empty($c['confirm_customer']) && $custEmail && filter_var($custEmail, FILTER_VALIDATE_EMAIL) && $o) {
    $text = sj_customer_mail($o, $no, $c);
    $bar = SJ_ROOT . '/' . ltrim((string)($c['payment']['barcode'] ?? ''), '/');
    $sentCust = sj_send_mail($custEmail, $c['shop'] . " — potvrda narudžbe $no", $text, is_file($bar) ? $bar : null, $c['owner_email']);
  }
  sj_json_out(['ok' => true, 'orderNo' => $no, 'mailOwner' => $sentOwner, 'mailCustomer' => $sentCust]);
}

/* ---------- storno / plaćeno (link iz e-maila) ---------- */
if ($a === 'storno') {
  $token = (string)(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
  $action = (string)($_POST['action'] ?? '');
  if (!preg_match('/^[a-f0-9]{32}$/', $token)) sj_html_page('Rezervacija', '<p>Nevažeći link.</p>', 400);

  $d = sj_res_read();
  $mine = array_filter($d['res'], fn($r) => ($r['token'] ?? '') === $token);
  $sold = array_filter($d['sold'], fn($r) => ($r['token'] ?? '') === $token);

  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ids = array_keys($mine); $soldIds = array_keys($sold);
    if ($action === 'release') {
      sj_with_lock(sj_res_path(), function (array &$d) use ($ids) { foreach ($ids as $id) unset($d['res'][$id]); return true; }, sj_res_default());
      sj_html_page('Rezervacija', $ids ? '<p>Rezervacija je stornirana: <b>' . e(implode(', ', $ids)) . '</b>. Torba je ponovno dostupna na stranici.</p>' : '<p>Rezervacija je već stornirana ili je istekla.</p>');
    }
    if ($action === 'sold') {
      sj_with_lock(sj_res_path(), function (array &$d) use ($ids) {
        foreach ($ids as $id) { $r = $d['res'][$id] ?? ['id' => $id]; unset($d['res'][$id]); $d['sold'][$id] = ['id' => $id, 'token' => $r['token'] ?? '', 'name' => $r['name'] ?? '', 'email' => $r['email'] ?? '', 'since' => $r['since'] ?? '', 'paidAt' => sj_iso(time())]; }
        return true;
      }, sj_res_default());
      sj_html_page('Rezervacija', $ids ? '<p>Označeno kao plaćeno: <b>' . e(implode(', ', $ids)) . '</b>. Na stranici piše „Prodano“ i kad rezervacija istekne. Torbu trajno označite prodanom u adminu.</p>' : '<p>Rezervacija je već stornirana ili je istekla. Ako je kupac ipak platio, torbu označite prodanom u adminu.</p>');
    }
    if ($action === 'unsold') {
      sj_with_lock(sj_res_path(), function (array &$d) use ($soldIds) { foreach ($soldIds as $id) unset($d['sold'][$id]); return true; }, sj_res_default());
      sj_html_page('Rezervacija', $soldIds ? '<p>Oznaka „prodano“ uklonjena: <b>' . e(implode(', ', $soldIds)) . '</b>.</p>' : '<p>Nema oznake za uklanjanje.</p>');
    }
    sj_html_page('Rezervacija', '<p>Nepoznata radnja.</p>', 400);
  }

  if ($mine) {
    $rows = '';
    foreach ($mine as $r) $rows .= '<li><b>' . e($r['id']) . '</b> — ' . e($r['name']) . ' (' . e($r['email']) . '), rezervirano ' . e(sj_fmt_hr($r['since'])) . ', vrijedi do ' . e(sj_fmt_hr($r['until'])) . '</li>';
    sj_html_page('Rezervacija', '<p>Ova narudžba drži rezervaciju:</p><ul>' . $rows . '</ul>
      <div class="row">
        <form method="post"><input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="sold"><button>Plaćeno — torba ostaje prodana</button></form>
        <form method="post"><input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="release"><button class="alt">Storniraj — vrati u prodaju</button></form>
      </div>
      <p class="muted">„Plaćeno“ drži torbu kao prodanu i nakon isteka rezervacije. „Storniraj“ odmah vraća torbu u prodaju. Sve to vidite i u <a href="' . e(sj_site_url()) . '/admin/">adminu</a>.</p>');
  }
  if ($sold) {
    sj_html_page('Rezervacija', '<p>Označeno kao plaćeno / prodano: <b>' . e(implode(', ', array_keys($sold))) . '</b>.</p>
      <form method="post"><input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="action" value="unsold"><button class="alt">Ukloni oznaku</button></form>
      <p class="muted">Uklanjanje oznake vraća torbu u prodaju ako u adminu nije označena prodanom.</p>');
  }
  sj_html_page('Rezervacija', '<p>Rezervacija je već stornirana ili je istekla. Ako je kupac ipak platio, torbu označite prodanom u adminu.</p>');
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found";
exit;

/* ---------- potvrda kupcu ---------- */
function sj_customer_mail(array $o, string $no, array $c): string {
  $cust = $o['customer'] ?? [];
  $items = is_array($o['items'] ?? null) ? $o['items'] : [];
  $ship = is_array($o['shipping'] ?? null) ? $o['shipping'] : [];
  $res = is_array($o['reservation'] ?? null) ? $o['reservation'] : null;
  $names = []; $lines = []; $anyMade = false; $anyUnique = false; $leads = [];
  foreach ($items as $it) {
    $q = max(1, (int)($it['qty'] ?? 1));
    $n = sj_clean((string)($it['name'] ?? ''), 80);
    $names[] = $n . ($q > 1 ? " × $q" : '');
    $line = '- ' . $n . ($q > 1 ? " × $q" : '') . (!empty($it['note']) ? ' (' . sj_clean((string)$it['note'], 120) . ')' : '') . ' — ' . sj_fmt_eur((float)($it['price'] ?? 0) * $q);
    $lines[] = $line;
    if (!empty($it['made'])) { $anyMade = true; if (!empty($it['lead'])) $leads[] = sj_clean((string)$it['lead'], 60); } else $anyUnique = true;
  }
  $shipLabel = sj_clean((string)($ship['label'] ?? 'po dogovoru'), 80);
  $shipPrice = (float)($ship['price'] ?? 0);
  $lines[] = '- Dostava: ' . $shipLabel . ' — ' . ($shipPrice > 0 ? sj_fmt_eur($shipPrice) : 'besplatno');
  $total = (float)($o['total'] ?? 0);
  $p = $c['payment'];
  $ibanPretty = trim(chunk_split(preg_replace('/\s+/', '', (string)$p['iban']) ?? '', 4, ' '));
  $today = new DateTime('now', new DateTimeZone('Europe/Zagreb'));

  $t = [];
  $t[] = 'Draga/Dragi ' . sj_clean((string)($cust['name'] ?? ''), 80) . ',';
  $t[] = '';
  $t[] = "hvala vam na narudžbi br. $no!" . ($anyUnique ? ' Torba je rezervirana za vas — kao i sve naše torbe, postoji samo u jednom primjerku, i sada je vaša.' : ' Artikle izrađujemo po narudžbi, posebno za vas.');
  $t[] = '';
  $t[] = 'SAŽETAK NARUDŽBE';
  foreach ($lines as $l) $t[] = $l;
  $t[] = 'Ukupno za uplatu: ' . sj_fmt_eur($total);
  $t[] = '';
  $sid = (string)($ship['id'] ?? '');
  if (!empty($ship['locker'])) $t[] = 'Paketomat: ' . sj_clean((string)$ship['locker'], 160) . '. Kod za preuzimanje dobit ćete SMS-om ili e-mailom od BOX NOW-a kad paket stigne.';
  elseif ($sid === 'pickup') $t[] = 'Preuzimanje u radionici: ' . $c['pickup_info'];
  elseif (!empty($cust['street'])) $t[] = 'Adresa za dostavu: ' . sj_clean((string)$cust['street'], 120) . ', ' . sj_clean((string)($cust['city'] ?? ''), 80) . '.';
  $t[] = '';
  $t[] = 'PODACI ZA UPLATU';
  $t[] = '- Primatelj: ' . $p['recipient'] . ', ' . $p['address'];
  $t[] = '- IBAN: ' . $ibanPretty;
  $t[] = '- Iznos: ' . sj_fmt_eur($total);
  $t[] = '- Model: ' . $p['model'];
  $t[] = '- Poziv na broj: datum vaše uplate u obliku DDMMGGGG (npr. za ' . $today->format('j. n. Y.') . ' upišite ' . $today->format('dmY') . ')';
  $t[] = '- Opis plaćanja: ' . $c['shop'] . ' — ' . implode(', ', $names);
  $t[] = '';
  $t[] = 'Najbrže: u aplikaciji svoje banke skenirajte barkod iz privitka. Primatelj i IBAN popune se sami, a vi upišete još iznos, model ' . $p['model'] . ' i poziv na broj (datum uplate).';
  $t[] = '';
  if ($res && !empty($res['until'])) {
    $t[] = 'Rezervacija vrijedi ' . (int)$c['ttl_hours'] . ' h, do ' . sj_fmt_hr((string)$res['until']) . '. Molimo vas da uplatu izvršite odmah i da nam čim uplatite pošaljete potvrdu uplate (snimku zaslona) odgovorom na ovaj e-mail — torbu tada odmah označavamo kao vašu, bez obzira na to kad banka proknjiži uplatu. Ako u tom roku ne primimo uplatu ni potvrdu, rezervacija automatski istječe i torba se ponovno nudi drugim kupcima.';
    $t[] = '';
  }
  if ($anyMade) {
    $t[] = 'Rok izrade' . ($leads ? ' (' . implode(', ', array_unique($leads)) . ')' : '') . ' teče od primitka uplate. Artikli izrađeni po vašim posebnim željama (boja, duljina, mjera) izrađuju se samo za vas, pa se na njih ne odnosi pravo na jednostrani raskid ugovora.';
    $t[] = '';
  }
  $t[] = 'Čim uplata bude vidljiva, ' . ($anyMade ? 'krećemo s izradom, a gotove artikle pažljivo pakiramo i šaljemo.' : 'torbu pažljivo pakiramo i šaljemo.') . ' Poslat ćemo vam poruku s potvrdom slanja.';
  $t[] = '';
  $t[] = 'Ova poruka je potvrda vaše narudžbe. Uvjeti kupnje i pravo na jednostrani raskid u roku 14 dana: ' . sj_site_url() . '/#uvjeti';
  $t[] = 'Za bilo kakva pitanja odgovorite na ovaj e-mail ili nam se javite porukom na Instagram @shiva.j_handmade.';
  $t[] = '';
  $t[] = 'Srdačan pozdrav,';
  $t[] = $c['shop'];
  $t[] = 'Unikatne ručno rađene torbe';
  return implode("\n", $t);
}

/* ---------- HTML stranica (storno) ---------- */
function sj_html_page(string $title, string $body, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store');
  header('Referrer-Policy: no-referrer');
  echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' — Shiva.J</title>
<style>body{margin:0;background:#F6F6F3;color:#1B1A17;font:16px/1.6 system-ui,sans-serif}main{max-width:760px;margin:0 auto;padding:40px 20px}h1{font-weight:400;font-size:1.5rem;letter-spacing:.04em;border-bottom:1.5px dashed #2E4A3A;padding-bottom:12px}button{background:#2E4A3A;color:#F6F6F3;border:1.5px solid #2E4A3A;border-radius:2px;padding:9px 14px;font-size:.85rem;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}button.alt{background:none;color:#2E4A3A;border-style:dashed}.row{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.muted{color:#5C5A53;font-size:.85rem}a{color:#2E4A3A}</style></head><body><main><h1>' . e($title) . '</h1>' . $body . '</main></body></html>';
  exit;
}
