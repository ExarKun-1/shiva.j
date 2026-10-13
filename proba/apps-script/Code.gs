/* =========================================================
   SHIVA.J — PROBNI poslužitelj na Google Apps Scriptu (v43)
   Za probnu stranicu na GitHub Pagesu (statična, bez PHP-a). Isti API kao api/index.php:
     GET  ?r=status   rezervirane i prodane torbe, dostava, podaci za uplatu
     POST ?r=reserve  (proba ne drži košaricu; stranica tada samo šalje narudžbu)
     POST ?r=order    narudžba → e-mail kupcu s linkom „potvrdite narudžbu” (ništa se ne rezervira)
     POST ?r=confirm  klik na link → broj narudžbe, rezervacija, e-mail vlasnici i kupcu
   Podaci su u Google tablici na koju je skripta vezana:
     „Narudžbe”    jedna narudžba po retku; u stupac „Plaćeno” vlasnica upisuje datum kad stigne uplata
     „Na čekanju”  nepotvrđene narudžbe (brišu se dan nakon isteka linka)
   Svojstva skripte (Project Settings → Script properties):
     SITE_URL         adresa probne stranice, npr. https://exarkun-1.github.io/shiva.j  (obavezno)
     OWNER_EMAIL      kamo stižu narudžbe (zadano: račun koji je postavio skriptu)
     CONFIRM_MINUTES  koliko vrijedi link za potvrdu (zadano 60)
     TTL_HOURS        koliko traje rezervacija nakon potvrde (zadano 24)
   E-mailove šalje Gmail račun koji je postavio skriptu (Gmail dopušta oko 100 dnevno).
   Proba ne zna IP adresu kupca: granice su po e-mail adresi i ukupno na sat.
   ========================================================= */

/* Dostava i podaci za uplatu: isto kao zadane postavke admina (admin/lib.php, sj_defaults) */
var SJ = {
  shop: 'Shiva.J',
  maxItems: 6, maxQty: 10, maxUnique: 2,
  confirmMailsPerHour: 200,   /* krajnja zaštita; po primatelju najviše 2 na sat */
  mailsPerRecipient: 2,
  shipping: [
    { id: 'boxnow', label: 'BOX NOW paketomat', price: 4.00, address: false, locker: true,
      note: 'Paket stiže u paketomat po vašem izboru; kod za preuzimanje dobivate SMS-om ili e-mailom.' },
    { id: 'gls', label: 'GLS dostava na adresu', price: 6.00, address: true, locker: false,
      note: 'Kurir dostavlja na vašu adresu, obično sljedeći radni dan nakon slanja.' },
    { id: 'pickup', label: 'Osobno preuzimanje u radionici', price: 0, address: false, locker: false,
      note: 'Matije Gupca 33, Zabok — radnim danom od 8 do 15 sati, nakon uplate i uz dogovor termina.' }
  ],
  payment: { recipient: 'SHIVA. J, obrt za dizajn', address: 'Matije Gupca 33, 49210 Zabok', iban: 'HR9823600001102790442', model: 'HR00', barcode: 'img/barkod-uplata.png' },
  pickup: 'Matije Gupca 33, Zabok — radnim danom od 8 do 15 sati; kad je narudžba spremna, javit ćemo vam se i dogovoriti termin.'
};
var ORDER_HEAD = ['Broj', 'Vrijeme', 'Ime', 'E-mail', 'Telefon', 'Stavke', 'Dostava', 'Ukupno', 'Rezervirano do', 'Plaćeno', 'Napomena', 'Ključ', 'Podaci (ne mijenjati)'];
var PEND_HEAD = ['id', 'do', 'podaci'];

/* ---------------- logika (bez Google servisa; testira se u Nodeu: tests/gas.js) ---------------- */

function sjOut(status, obj) { obj._status = status; return obj; }
function sjEur(n) { return (Math.round(n * 100) / 100).toFixed(2).replace('.', ',') + ' €'; }
function sjIso(ms) { return new Date(ms).toISOString().replace(/\.\d{3}Z$/, 'Z'); }
/* v42: „1 sat”, „2 sata”, „90 minuta” — koliko link vrijedi (kao na stranici) */
function sjForText(min) {
  if (min % 60) return min + ' minuta';
  var n = min / 60, m10 = n % 10, m100 = n % 100;
  return n + ' ' + (m10 === 1 && m100 !== 11 ? 'sat' : (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? 'sata' : 'sati'));
}
function sjHr(ms) {
  var d = new Date(ms), p = function (n) { return (n < 10 ? '0' : '') + n; };
  /* vrijeme u Hrvatskoj (CET/CEST) bez ovisnosti o postavkama računa */
  var s = Utilities_formatDate(d);
  return s || (p(d.getUTCDate()) + '. ' + p(d.getUTCMonth() + 1) + '. ' + d.getUTCFullYear() + '. ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ' UTC');
}
function Utilities_formatDate(d) {
  try { return Utilities.formatDate(d, 'Europe/Zagreb', 'dd. MM. yyyy. HH:mm'); } catch (e) { return ''; }
}
function sjEmailKey(e) {
  e = String(e || '').trim().toLowerCase();
  var at = e.lastIndexOf('@'); if (at < 1) return e;
  var u = e.slice(0, at).replace(/\+.*$/, ''), d = e.slice(at + 1);
  if (d === 'gmail.com' || d === 'googlemail.com') { u = u.replace(/\./g, ''); d = 'gmail.com'; }
  return u + '@' + d;
}
function sjClean(v, max) { return (typeof v === 'string' || typeof v === 'number') ? String(v).replace(/[\u0000-\u001f\u007f]/g, ' ').trim().slice(0, max) : ''; }
function sjValidEmail(v) { v = sjClean(v, 120); return /^[^\s@<>()",;:]+@[^\s@<>()",;:]+\.[a-z]{2,}$/i.test(v) ? v : null; }
function sjRef(no) { return String(no).replace(/^SJ-/, ''); }

/* stanje torbi iz Narudžbi: plaćene su prodane, neplaćene s „Rezervirano do” u budućnosti drže svoje torbe */
function sjState(env) {
  var now = env.now(), held = {}, sold = {};
  env.orders().forEach(function (o) {
    var data = o.data || {};
    (data.ids || []).forEach(function (id) { if (o.paid) sold[id] = o; });
    if (!o.paid && o.until && Date.parse(o.until) > now) (data.held || []).forEach(function (id) { if (!sold[id]) held[id] = o; });
  });
  return { held: held, sold: sold };
}
function sjProducts(env) {
  var by = {};
  (env.products() || []).forEach(function (p) { if (p && p.id && !p.hidden) by[String(p.id)] = p; });
  return by;
}
function sjFreeIds(prods, st) {
  return Object.keys(prods).filter(function (id) { var p = prods[id]; return !p.made && !p.sold && !p.reserved && !st.sold[id]; });
}

function sjStatus(env) {
  var st = sjState(env);
  return sjOut(200, { ok: true, ttlHours: env.cfg.TTL_HOURS, ids: Object.keys(st.held), sold: Object.keys(st.sold), shipping: SJ.shipping,
    payment: SJ.payment, pickup: SJ.pickup, ownerEmail: env.cfg.OWNER_EMAIL, shipLead: '' });
}

/* provjera narudžbe iz preglednika: cijene, dostava i iznos iz vlastitog popisa */
function sjParseOrder(env, b) {
  var inp = (b && typeof b._order === 'object' && b._order) || {};
  var ic = inp.customer || {};
  var cust = { name: sjClean(ic.name, 80), email: sjValidEmail(ic.email), phone: sjClean(ic.phone, 40), street: sjClean(ic.street, 120), city: sjClean(ic.city, 80) };
  if (!cust.name || !cust.email) return { err: sjOut(400, { ok: false, error: 'Upišite ime i ispravnu e-mail adresu.' }) };
  var prods = sjProducts(env), items = [], goods = 0, uniqueIds = [];
  var list = Array.isArray(inp.items) ? inp.items : [];
  for (var i = 0; i < list.length; i++) {
    var id = sjClean(list[i] && list[i].id, 40), p = prods[id];
    if (!p) return { err: sjOut(409, { ok: false, error: 'Proizvod više nije u ponudi: ' + (id || '?') + '. Osvježite stranicu.' }) };
    var made = !!p.made;
    if (!made && (p.sold || p.reserved)) return { err: sjOut(409, { ok: false, taken: [id] }) };
    if (made && p.sold) return { err: sjOut(409, { ok: false, error: '„' + p.name + '” trenutno nije dostupan za narudžbu. Uklonite ga iz košarice i pošaljite ostatak.' }) };
    var qty = made ? Math.max(1, Math.min(SJ.maxQty, parseInt(list[i].qty, 10) || 1)) : 1;
    var price = Math.round(Number(p.price || 0) * 100) / 100;
    items.push({ id: id, name: sjClean(p.name || id, 80), qty: qty, note: made ? sjClean(list[i].note, 120) : '', price: price, made: made, lead: made ? String(p.lead || '').replace(/^\{.*\}$/, '') : '' });
    goods += price * qty;
    if (!made) uniqueIds.push(id);
  }
  if (!items.length) return { err: sjOut(400, { ok: false, error: 'Košarica je prazna.' }) };
  if (items.length > SJ.maxItems) return { err: sjOut(400, { ok: false, error: 'Najviše ' + SJ.maxItems + ' stavki u jednoj narudžbi.' }) };
  if (uniqueIds.length > SJ.maxUnique) return { err: sjOut(400, { ok: false, error: 'U jednoj narudžbi mogu biti najviše dvije šarene torbe. Za više nam se javite e-mailom ili na Instagramu.' }) };
  var is = inp.shipping || {}, ship = null;
  SJ.shipping.forEach(function (o) { if (o.id === sjClean(is.id, 40)) ship = o; });
  if (!ship) return { err: sjOut(400, { ok: false, error: 'Odaberite način dostave.' }) };
  var locker = ship.locker ? sjClean(is.locker, 160) : '';
  if (ship.locker && !locker) return { err: sjOut(400, { ok: false, error: 'Odaberite ili upišite paketomat.' }) };
  if (ship.address && (!cust.street || !cust.city)) return { err: sjOut(400, { ok: false, error: 'Upišite adresu za dostavu.' }) };
  if (!ship.address) { cust.street = ''; cust.city = ''; }
  var total = Math.round((goods + ship.price) * 100) / 100;
  if (typeof inp.total === 'number' && Math.abs(inp.total - total) > 0.005) return { err: sjOut(409, { ok: false, error: 'Cijene su se u međuvremenu promijenile. Osvježite stranicu i pošaljite narudžbu ponovno.' }) };
  var key = sjClean(inp.key, 64); if (!/^[A-Za-z0-9-]{8,64}$/.test(key)) key = '';
  var note = sjClean(b.Napomena != null ? b.Napomena : inp.note, 500); if (note === '—') note = '';
  return { P: { key: key, cust: cust, items: items, uniqueIds: uniqueIds, ship: { id: ship.id, label: ship.label, price: ship.price, locker: locker }, goods: Math.round(goods * 100) / 100, total: total, note: note } };
}
function sjItemsText(P) {
  return P.items.map(function (it) { return '- ' + it.name + (it.qty > 1 ? ' × ' + it.qty : '') + (it.note ? ' (' + it.note + ')' : '') + ' — ' + sjEur(it.price * it.qty); }).join('\n');
}
function sjShipText(P) { return P.ship.label + ' — ' + (P.ship.price > 0 ? sjEur(P.ship.price) : 'besplatno') + (P.ship.locker ? ' — paketomat: ' + P.ship.locker : ''); }
function sjView(o) { var d = o.data || {}; return { items: d.items || [], ship: d.ship || null }; }
function sjRepeat(o) {
  var d = o.data || {};
  var res = { ok: true, orderNo: o.no, reference: sjRef(o.no), total: d.total, until: (d.held || []).length ? o.until : null, reserved: !(d.unheld || []).length, unheld: !!(d.unheld || []).length,
    unheldIds: d.unheld || [], mailOwner: true, mailCustomer: true, repeated: true };
  var v = sjView(o); res.items = v.items; res.ship = v.ship;
  return sjOut(200, res);
}

/* POST order: narudžba čeka potvrdu; kupac dobiva link */
function sjOrder(env, b) {
  if (!b) return sjOut(400, { ok: false, error: 'Neispravan zahtjev.' });
  if (b._gotcha || b.website) return sjOut(200, { ok: true });
  var parsed = sjParseOrder(env, b);
  if (parsed.err) return parsed.err;
  var P = parsed.P, ek = sjEmailKey(P.cust.email);
  var orders = env.orders();
  for (var i = 0; i < orders.length; i++) if (P.key && orders[i].key === P.key) return sjRepeat(orders[i]);
  if (P.uniqueIds.length) {
    var st = sjState(env), taken = [];
    P.uniqueIds.forEach(function (id) { var h = st.held[id]; if (st.sold[id] || (h && sjEmailKey(h.email) !== ek)) taken.push(id); });
    if (taken.length) return sjOut(409, { ok: false, taken: taken });
    for (var id in st.held) if (P.uniqueIds.indexOf(id) < 0 && sjEmailKey(st.held[id].email) === ek)
      return sjOut(409, { ok: false, error: 'Već imate narudžbu sa šarenom torbom koja čeka uplatu. Kad je uplatite, možete naručiti sljedeću; za više torbi odjednom javite nam se e-mailom ili na Instagramu.' });
  }
  if (!env.rate('mailto_' + env.sha256(ek).slice(0, 20), SJ.mailsPerRecipient, 3600)) return sjOut(429, { ok: false, error: 'Na ovu adresu već smo poslali e-mail za potvrdu. Pogledajte i neželjenu poštu (spam) ili pokušajte ponovno za sat vremena.' });
  if (!env.rate('confirm', SJ.confirmMailsPerHour, 3600)) return sjOut(429, { ok: false, error: 'Previše narudžbi u kratkom vremenu. Pokušajte ponovno za sat vremena ili nam pišite e-mailom.' });
  var now = env.now(), sec = env.token(), until = now + env.cfg.CONFIRM_MINUTES * 60000;
  var list = env.pending(), keep = [], pid = '';
  list.forEach(function (x) {
    if (Date.parse(x.until) < now - 86400000) return;
    if (!x.no && P.key && x.key === P.key) { pid = x.id; return; }
    keep.push(x);
  });
  /* tuđa nepotvrđena narudžba s istim e-mailom se ne briše; po adresi najviše 3 nepotvrđene, najstarija ispada */
  var same = 0;
  keep = keep.filter(function (x) { return x.no || x.ek !== ek || ++same <= 2; });
  if (!pid) pid = env.token();
  keep.unshift({ id: pid, h: env.sha256(sec), key: P.key, at: sjIso(now), until: sjIso(until), ek: ek, P: P });
  env.savePending(keep.slice(0, 500));
  var link = env.cfg.SITE_URL + '/?potvrda=' + pid + '.' + sec;
  var t = ['Dobar dan, ' + P.cust.name + ',', '',
    'primili smo vašu narudžbu. Da bismo je zaprimili' + (P.uniqueIds.length ? ' i rezervirali torbu za vas' : '') + ', potvrdite je klikom na link:', '',
    link, '', 'Link vrijedi ' + sjForText(env.cfg.CONFIRM_MINUTES) + ' (do ' + sjHr(until) + '). Nakon potvrde otvara se stranica s podacima za uplatu, a iste podatke šaljemo vam i e-mailom.', '',
    'NARUDŽBA', sjItemsText(P), '- Dostava: ' + sjShipText(P), 'Ukupno: ' + sjEur(P.total), '',
    'Ako niste naručili vi, zanemarite ovaj e-mail: bez potvrde narudžba se ne zaprima i ništa se ne rezervira.', '',
    'Srdačan pozdrav,', SJ.shop];
  var sent = env.mail(P.cust.email, SJ.shop + ' — potvrdite narudžbu', t.join('\n'), env.cfg.OWNER_EMAIL);
  return sjOut(200, { ok: true, pending: true, confirmUntil: sjIso(until), confirmMinutes: env.cfg.CONFIRM_MINUTES, mailCustomer: sent });
}

/* POST confirm: provjera tajne i roka, zatim rezervacija (najviše pola slobodne ponude), broj, upis i e-mailovi */
function sjConfirm(env, b) {
  var pid = sjClean(b && b.id, 40), sec = sjClean(b && b.t, 40);
  if (!/^[a-f0-9]{32}$/.test(pid) || !/^[a-f0-9]{32}$/.test(sec)) return sjOut(400, { ok: false, error: 'Neispravan link za potvrdu. Kopirajte cijeli link iz e-maila.' });
  var list = env.pending(), e = null;
  list.forEach(function (x) { if (x.id === pid) e = x; });
  var expired = sjOut(410, { ok: false, expired: true, error: 'Link za potvrdu je istekao, pa narudžba nije zaprimljena i torba nije rezervirana. Pošaljite narudžbu ponovno.' });
  if (!e) return expired;
  if (env.sha256(sec) !== e.h) return sjOut(403, { ok: false, error: 'Neispravan link za potvrdu. Otvorite najnoviji e-mail „Potvrdite narudžbu”.' });
  var orders = env.orders();
  if (e.no) { for (var i = 0; i < orders.length; i++) if (orders[i].no === e.no) return sjRepeat(orders[i]); }
  if (Date.parse(e.until) < env.now()) return expired;
  var P = e.P, ek = sjEmailKey(P.cust.email);
  for (var j = 0; j < orders.length; j++) if (P.key && orders[j].key === P.key) return sjRepeat(orders[j]);
  var st = sjState(env), prods = sjProducts(env), taken = [], mine = [];
  /* od narudžbe je moglo proći do sat vremena: ponuda i cijene provjeravaju se ponovno */
  for (var k = 0; k < P.items.length; k++) {
    var it = P.items[k], cp = prods[it.id];
    if (!cp) return sjOut(409, { ok: false, error: 'Proizvod „' + it.name + '” više nije u ponudi. Narudžba nije potvrđena; pošaljite je ponovno bez njega.' });
    if (it.made && cp.sold) return sjOut(409, { ok: false, error: '„' + it.name + '” trenutno nije dostupan za narudžbu. Narudžba nije potvrđena; pošaljite je ponovno bez njega.' });
    if (Math.abs(Math.round(Number(cp.price || 0) * 100) / 100 - it.price) > 0.005) return sjOut(409, { ok: false, error: 'Cijene su se u međuvremenu promijenile, pa narudžba nije potvrđena. Osvježite stranicu i pošaljite je ponovno.' });
  }
  P.uniqueIds.forEach(function (id) {
    var h = st.held[id], p = prods[id];
    if (st.sold[id] || !p || p.sold || p.reserved) taken.push(id);
    else if (h && sjEmailKey(h.email) !== ek) taken.push(id);
    else if (h) mine.push(id);
  });
  if (taken.length) return sjOut(409, { ok: false, taken: taken });
  if (mine.length) return sjOut(409, { ok: false, error: 'Torba je već u vašoj ranijoj narudžbi; podatke za uplatu poslali smo vam e-mailom.' });
  for (var id in st.held) if (sjEmailKey(st.held[id].email) === ek)
    return sjOut(409, { ok: false, error: 'Već imate narudžbu sa šarenom torbom koja čeka uplatu. Kad je uplatite, možete naručiti sljedeću.' });
  /* granica: neplaćene narudžbe drže najviše pola stvarno slobodnih unikata; ostatak bez rezervacije (tko prvi uplati) */
  var free = sjFreeIds(prods, st), freeSet = {};
  free.forEach(function (f) { freeSet[f] = true; });
  var cap = free.length >= 2 ? Math.max(1, Math.floor(free.length / 2)) : 0;
  var heldN = Object.keys(st.held).filter(function (h) { return freeSet[h]; }).length;
  var room = Math.max(0, cap - heldN), held = [], unheld = [];
  P.uniqueIds.forEach(function (u) { if (room > 0) { held.push(u); room--; } else unheld.push(u); });
  var now = env.now(), until = held.length ? sjIso(now + env.cfg.TTL_HOURS * 3600000) : '';
  var no = 'SJ-' + new Date(now).getUTCFullYear() + '-' + ('000' + env.nextNo()).slice(-4);
  var data = { ids: P.uniqueIds, held: held, unheld: unheld, total: P.total, items: P.items.map(function (it) { return { id: it.id, name: it.name, qty: it.qty, price: it.price, made: it.made, lead: it.lead, note: it.note || '' }; }), ship: P.ship };
  var row = { no: no, at: sjIso(now), name: P.cust.name, email: P.cust.email, phone: P.cust.phone, items: sjItemsText(P), shipping: sjShipText(P) + (P.cust.street ? ' — ' + P.cust.street + ', ' + P.cust.city : ''),
    total: P.total, until: until, paid: '', note: P.note, key: P.key, data: data };
  env.appendOrder(row);
  list.forEach(function (x) { if (x.id === pid) { x.no = no; x.confirmedAt = sjIso(now); } });
  env.savePending(list);

  var names = function (ids) { return P.items.filter(function (it) { return ids.indexOf(it.id) >= 0; }).map(function (it) { return '„' + it.name + '”'; }).join(', '); };
  var lo = ['Nova narudžba ' + no + ' — ' + SJ.shop + ' (PROBNA STRANICA)', '', 'Kupac: ' + P.cust.name, 'E-mail: ' + P.cust.email];
  if (P.cust.phone) lo.push('Telefon: ' + P.cust.phone);
  if (P.cust.street) lo.push('Adresa: ' + P.cust.street + ', ' + P.cust.city);
  lo.push('', 'Naručeno:', sjItemsText(P), 'Dostava: ' + sjShipText(P), 'Ukupno: ' + sjEur(P.total));
  if (P.note) lo.push('', 'Napomena: ' + P.note);
  lo.push('', 'Kupac je prihvatio Uvjete kupnje (narudžba s obvezom plaćanja) i potvrdio narudžbu linkom iz e-maila.');
  if (held.length) lo.push('Rezervacija (' + names(held) + ') vrijedi do: ' + sjHr(Date.parse(until)) + '. Kad stigne uplata, u tablici upišite datum u stupac „Plaćeno”.');
  if (unheld.length) lo.push('PAŽNJA: bez rezervacije (dobiva ih tko prvi uplati): ' + names(unheld) + '. Neplaćene narudžbe već drže pola slobodne ponude.');
  var sentOwner = env.mail(env.cfg.OWNER_EMAIL, 'Narudžba ' + no + ' — ' + P.cust.name + ' — ' + P.items.map(function (it) { return it.name; }).join(', '), lo.join('\n'), P.cust.email);

  var pay = SJ.payment, ref = sjRef(no);
  var lc = ['Dobar dan, ' + P.cust.name + ',', '', 'hvala vam na narudžbi br. ' + no + '!' + (held.length ? ' ' + (held.length > 1 ? 'Torbe su rezervirane' : 'Torba je rezervirana') + ' za vas do ' + sjHr(Date.parse(until)) + '.' : ''), '',
    'SAŽETAK NARUDŽBE', sjItemsText(P), '- Dostava: ' + sjShipText(P), 'Ukupno za uplatu: ' + sjEur(P.total), '',
    'PODACI ZA UPLATU', '- Primatelj: ' + pay.recipient + ', ' + pay.address, '- IBAN: ' + pay.iban.replace(/(.{4})/g, '$1 ').trim(), '- Iznos: ' + sjEur(P.total), '- Model: ' + pay.model,
    '- Poziv na broj: ' + ref + ' (broj vaše narudžbe)', '- Opis plaćanja: ' + ('Shiva.J ' + ref).slice(0, 35), ''];
  if (unheld.length) lc.push((unheld.length > 1 ? 'Torbe ' : 'Torbu ') + names(unheld) + ' trenutno ne možemo rezervirati jer je mnogo narudžbi na čekanju: dobiva ' + (unheld.length > 1 ? 'ih' : 'je') + ' tko prvi uplati.', '');
  lc.push('Molimo vas da uplatite odmah i pošaljete nam potvrdu uplate (snimku zaslona) odgovorom na ovaj e-mail.', '',
    'Ovo je PROBNA stranica: narudžba nije prava i ništa ne trebate uplatiti.', '', 'Srdačan pozdrav,', SJ.shop);
  var sentCust = env.mail(P.cust.email, SJ.shop + ' — potvrda narudžbe ' + no, lc.join('\n'), env.cfg.OWNER_EMAIL);
  var out = { ok: true, orderNo: no, reference: ref, total: P.total, until: until || null, reserved: !unheld.length, unheld: !!unheld.length, unheldIds: unheld,
    mailOwner: sentOwner, mailCustomer: sentCust, items: data.items, ship: P.ship };
  return sjOut(200, out);
}

function sjHandle(env, route, body) {
  if (route === 'status') return sjStatus(env);
  if (route === 'reserve') return sjOut(429, { ok: false, error: 'Proba ne drži košaricu; narudžba ide izravno.' });
  if (route === 'order') return sjOrder(env, body);
  if (route === 'confirm') return sjConfirm(env, body);
  return sjOut(404, { ok: false, error: 'Nepoznata radnja.' });
}

/* ---------------- Google servisi ---------------- */

function sjSheet(name, head) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sh = ss.getSheetByName(name) || ss.insertSheet(name);
  if (sh.getLastRow() === 0) { sh.appendRow(head); sh.setFrozenRows(1); }
  return sh;
}
function sjEnv() {
  var props = PropertiesService.getScriptProperties();
  var site = String(props.getProperty('SITE_URL') || '').replace(/\/+$/, '');
  if (!site) throw new Error('U svojstva skripte upišite SITE_URL (adresu probne stranice).');
  var cfg = { SITE_URL: site, OWNER_EMAIL: props.getProperty('OWNER_EMAIL') || Session.getEffectiveUser().getEmail(),
    CONFIRM_MINUTES: Math.max(10, parseInt(props.getProperty('CONFIRM_MINUTES') || '60', 10) || 60), TTL_HOURS: Math.max(1, parseInt(props.getProperty('TTL_HOURS') || '24', 10) || 24) };
  var cache = CacheService.getScriptCache();
  var ordersCache = null;
  return {
    cfg: cfg,
    now: function () { return Date.now(); },
    token: function () { return Utilities.getUuid().replace(/-/g, ''); },
    sha256: function (s) { return Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, s, Utilities.Charset.UTF_8).map(function (b) { return ('0' + (b & 255).toString(16)).slice(-2); }).join(''); },
    products: function () {
      var c = cache.get('torbe');
      if (c) return JSON.parse(c);
      var r = UrlFetchApp.fetch(site + '/torbe.json?t=' + Date.now(), { muteHttpExceptions: true });
      var d = JSON.parse(r.getContentText() || '[]'); d = Array.isArray(d) ? d : (d.products || []);
      try { cache.put('torbe', JSON.stringify(d), 300); } catch (e) {}
      return d;
    },
    orders: function () {
      if (ordersCache) return ordersCache;
      var v = sjSheet('Narudžbe', ORDER_HEAD).getDataRange().getValues().slice(1);
      ordersCache = v.filter(function (r) { return r[0]; }).map(function (r) {
        var data = {}; try { data = JSON.parse(r[12] || '{}'); } catch (e) {}
        var until = r[8] instanceof Date ? r[8].toISOString() : String(r[8] || '');
        return { no: String(r[0]), email: String(r[3]), until: until, paid: r[9] !== '' && r[9] != null, key: String(r[11] || ''), data: data };
      });
      return ordersCache;
    },
    appendOrder: function (o) {
      /* tekst kupca nikad kao formula: vrijednost koja počinje s = + - @ dobiva ' ispred */
      var t = function (v) { v = String(v == null ? '' : v); return /^[=+\-@\t\r]/.test(v) ? "'" + v : v; };
      sjSheet('Narudžbe', ORDER_HEAD).appendRow([o.no, o.at, t(o.name), t(o.email), t(o.phone), t(o.items), t(o.shipping), o.total, o.until, '', t(o.note), o.key, JSON.stringify(o.data)]);
      ordersCache = null;
    },
    pending: function () {
      return sjSheet('Na čekanju', PEND_HEAD).getDataRange().getValues().slice(1).filter(function (r) { return r[0]; }).map(function (r) { try { return JSON.parse(r[2]); } catch (e) { return null; } }).filter(Boolean);
    },
    savePending: function (list) {
      var sh = sjSheet('Na čekanju', PEND_HEAD);
      if (sh.getLastRow() > 1) sh.getRange(2, 1, sh.getLastRow() - 1, 3).clearContent();
      if (list.length) sh.getRange(2, 1, list.length, 3).setValues(list.map(function (x) { return [x.id, x.until, JSON.stringify(x)]; }));
    },
    nextNo: function () { var n = parseInt(props.getProperty('ORDER_SEQ') || '0', 10) + 1; props.setProperty('ORDER_SEQ', String(n)); return n; },
    rate: function (key, max, win) {
      var k = 'rate_' + key, n = parseInt(cache.get(k) || '0', 10);
      if (n >= max) return false;
      cache.put(k, String(n + 1), win);
      return true;
    },
    mail: function (to, subject, body, replyTo) {
      try { MailApp.sendEmail({ to: to, subject: subject, body: body, replyTo: replyTo || undefined, name: SJ.shop }); return true; } catch (e) { console.error(e); return false; }
    }
  };
}
function sjJson(obj) { return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON); }
function sjRun(e, body) {
  var route = String((e && e.parameter && e.parameter.r) || 'status');
  var lock = LockService.getScriptLock();
  try {
    lock.waitLock(20000); /* narudžbe i potvrde idu jedna po jedna (kao brava u PHP-u) */
    return sjJson(sjHandle(sjEnv(), route, body));
  } catch (err) {
    console.error(err);
    return sjJson({ ok: false, _status: 500, error: 'Narudžba trenutno nije moguća zbog greške na poslužitelju. Pokušajte ponovno za nekoliko minuta ili nam pišite e-mailom.' });
  } finally {
    try { lock.releaseLock(); } catch (x) {}
  }
}
function doGet(e) { return sjRun(e, null); }
function doPost(e) {
  var body = null;
  try { body = JSON.parse((e && e.postData && e.postData.contents) || 'null'); } catch (x) {}
  return sjRun(e, body);
}
/* pokrenite jednom iz uređivača (Run → sjPostavi): napravi kartice i zatraži dozvole za Gmail i tablicu */
function sjPostavi() {
  sjSheet('Narudžbe', ORDER_HEAD); sjSheet('Na čekanju', PEND_HEAD);
  console.log('Kartice su spremne. Sad Deploy → New deployment → Web app.');
}
