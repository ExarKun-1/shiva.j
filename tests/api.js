/* API testovi preko HTTP-a:  sh tests/serve.sh &  pa  node tests/api.js
   Probna stranica: SHIVAJ_SITE (zadano /tmp/shivaj-test), poslužitelj http://127.0.0.1:8090/ */
const fs = require('fs');
const SITE = (process.env.SHIVAJ_SITE || '/tmp/shivaj-test') + '/';
const D = SITE + 'data/';
const U = (process.env.SHIVAJ_URL || 'http://127.0.0.1:8090/') + 'api/';
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
/* v78: Stalna kolekcija traži boju i duljinu remena iz ponude (i na poslužitelju) — narudžbe bez napomene ih dobivaju */
const strapFill = body => { const it = body && body._order && body._order.items; if (Array.isArray(it)) it.forEach(x => { if (x && /^(klasik|kol)\d*$/.test(x.id) && x.note === undefined) x.note = 'remen: crna, 140 cm'; }); return body; };
const post = async (a, body) => { const r = await fetch(U + a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(strapFill(body)) }); return { status: r.status, data: await r.json().catch(() => ({})) }; };
const cust = email => ({ name: 'API Test', email, phone: '091 111 2222', street: '', city: '' });
const pickup = { id: 'pickup', label: 'Osobno preuzimanje u radionici', price: 0, locker: '' };
const order = (key, email, items, total, hold) => ({ _gotcha: '', Napomena: '', _order: { key, hold: hold || '', customer: cust(email), items, shipping: pickup, goods: total, total } });
const hrs = iso => (Date.parse(iso) - Date.now()) / 3600000;
const read = f => JSON.parse(fs.readFileSync(D + f, 'utf8'));
const prods = () => read('proizvodi.json');
const setProd = (id, patch) => { const p = prods(); Object.assign(p.find(x => x.id === id), patch); fs.writeFileSync(D + 'proizvodi.json', JSON.stringify(p, null, 2)); };
const clearLimits = () => { try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {} };
const setLimits = l => { const c = read('config.json'); c.limits = Object.assign({}, c.limits, l); fs.writeFileSync(D + 'config.json', JSON.stringify(c, null, 2)); };
const clearRes = () => fs.writeFileSync(D + 'rezervacije.json', JSON.stringify({ res: {}, sold: {} }));
const expire = ids => { const r = read('rezervacije.json'); ids.forEach(id => { if (r.res[id]) r.res[id].until = '2000-01-01T00:00:00Z'; }); fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r)); };
(async () => {
  const orig = fs.readFileSync(D + 'proizvodi.json', 'utf8');
  /* ispitni config ima hold_min: 0 (staro pravilo „pola ponude”); v26 zadano (barem tri) ispituje se u 15b */
  try {
    // 1) idempotentnost i ishod e-mailova
    const remen = [{ id: 'remen', qty: 1, note: 'crna' }];
    const r1 = await post('order', order('api-key-0001-aaaa', 'ana@primjer.hr', remen, 15));
    const r2 = await post('order', order('api-key-0001-aaaa', 'ana@primjer.hr', remen, 15));
    const n1 = read('narudzbe.json');
    ok(r1.data.ok && r2.data.repeated && r1.data.orderNo === r2.data.orderNo && n1.length === 1, 'isti ključ dvaput: jedna narudžba ' + r1.data.orderNo);
    ok(n1[0].mail && n1[0].mail.customer === true && r2.data.mailCustomer === true, 'ishod e-mailova spremljen i vraćen u ponovljenom odgovoru');
    ok(!/ROK IZRADE/.test(fs.readFileSync(D + 'mail-log.txt', 'utf8')), 'nepopunjen {ROK IZRADE} ne ide u e-mail');
    { const ml0 = fs.readFileSync(D + 'mail-log.txt', 'utf8'); const cust = ml0.split(/^=== /m).filter(x => /potvrda narudžbe/.test(x)).pop() || '';
      const leftover = fs.existsSync(D + 'tmp') ? fs.readdirSync(D + 'tmp').filter(f => /\.png$/.test(f)) : [];
      ok(/Attachment: barkod-za-uplatu-\d{4}-\d{4}\.png/.test(cust) && /iznos, model, poziv na broj i opis popune se sami/.test(cust) && !leftover.length, 'v24/v25: e-mail kupcu nosi barkod narudžbe (sve popunjeno, prikazno ime privitka), privremena slika obrisana'); }
    // 2) kratka rezervacija, puni rok nakon narudžbe, tuđa torba
    const rs = await post('reserve', { ids: ['luna'], name: 'Ana', email: 'ana@primjer.hr' });
    ok(rs.data.ok && hrs(rs.data.until) > 1.9 && hrs(rs.data.until) <= 2.01 && /^[a-f0-9]{32}$/.test(rs.data.hold || ''), 'api/reserve: 2 sata i tajni ključ rezervacije');
    // 3) ispravljen e-mail s ključem rezervacije: narudžba prolazi
    const fixed = await post('order', order('api-key-0003-cccc', 'ana.ispravno@primjer.hr', [{ id: 'luna' }], 65, rs.data.hold));
    const resA = read('rezervacije.json');
    ok(fixed.data.ok && hrs(fixed.data.until) > 23.9 && resA.res.luna.email === 'ana.ispravno@primjer.hr' && resA.res.luna.ordered === true, 'ispravljen e-mail + ključ rezervacije: narudžba prolazi, puni rok, novi e-mail');
    const other = await post('order', order('api-key-0002-bbbb', 'drugi@primjer.hr', [{ id: 'luna' }], 65));
    ok(other.status === 409 && JSON.stringify(other.data.taken) === '["luna"]', 'torba iz zaprimljene narudžbe: drugi kupac dobiva 409 taken');
    const again = await post('order', order('api-key-0004-dddd', 'ana.ispravno@primjer.hr', [{ id: 'luna' }], 65));
    ok(again.status === 409 && /već je u vašoj ranijoj narudžbi/.test(again.data.error || '') && !/SJ-/.test(again.data.error || ''), 'ista torba u novoj narudžbi istog kupca: 409, bez broja narudžbe');
    // 4) narudžba bez rezervacije sama rezervira
    const direct = await post('order', order('api-key-0005-eeee', 'iva@primjer.hr', [{ id: 'vela' }], 70));
    ok(direct.data.ok && read('rezervacije.json').res.vela.email === 'iva@primjer.hr', 'narudžba bez rezervacije sama rezervira slobodnu torbu');
    // 5) nedostupno i ručno rezervirano
    setProd('remen', { sold: true });
    const ms = await post('order', order('api-key-0006-ffff', 'ana@primjer.hr', remen, 15));
    ok(ms.status === 409 && /nije dostupan/.test(ms.data.error || ''), 'artikl po narudžbi „nedostupno”: 409');
    setProd('tara', { reserved: true });
    const mr = await post('order', order('api-key-0007-gggg', 'ana@primjer.hr', [{ id: 'tara' }], 55));
    ok(mr.status === 409 && JSON.stringify(mr.data.taken) === '["tara"]', 'ručno rezervirani unikat: 409 taken');
    fs.writeFileSync(D + 'proizvodi.json', orig);
    // 6) odbijene narudžbe ne troše ograničenje
    clearLimits();
    for (let i = 0; i < 12; i++) await post('order', order('api-key-rej-' + i + '-xxxx', 'ana@primjer.hr', remen, 99));
    const after = await post('order', order('api-key-0008-hhhh', 'ana@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20));
    ok(after.data.ok, '12 odbijenih narudžbi (409) ne troši ograničenje; prava narudžba prolazi');
    // 7) zaključavanje ponude: najviše 2 rezervacije bez narudžbe s iste adrese
    clearLimits(); expire(['luna', 'vela']); await fetch(U + 'status'); setLimits({ holds_per_ip: 2 });
    const h1 = await post('reserve', { ids: ['luna'], name: 'X', email: 'x@primjer.hr' });
    const h2 = await post('reserve', { ids: ['nera'], name: 'X', email: 'x@primjer.hr' });
    const h3 = await post('reserve', { ids: ['vela'], name: 'X', email: 'x@primjer.hr' });
    ok(h1.data.ok && h2.data.ok && h3.status === 429 && /rezervacij/.test(h3.data.error || ''), 'treća rezervacija bez narudžbe s iste adrese: 429');
    const h3o = await post('order', order('api-key-0010-jjjj', 'x3@primjer.hr', [{ id: 'vela' }], 70));
    ok(h3o.data.ok, 'nakon 429 na rezervaciji narudžba iste torbe ipak prolazi (narudžba sama rezervira)');
    setLimits({ holds_per_ip: 20 });
    // 8) isteklo broji samo naručene
    const before = (Object.values(read('brojac.json'))[0] || {}).expired || 0;
    expire(['luna', 'nera']); await fetch(U + 'status');
    const exp = (Object.values(read('brojac.json'))[0] || {}).expired || 0;
    ok(exp === before, 'istekle rezervacije bez narudžbe ne broje se kao „isteklo” (' + before + ' → ' + exp + ')');
    // 9) upis narudžbe ne uspije: JSON 500, rezervacija vraćena
    clearLimits();
    fs.mkdirSync(D + 'narudzbe.json.tmp');
    const broken = await post('order', order('api-key-0009-iiii', 'pad@primjer.hr', [{ id: 'nera' }], 85));
    fs.rmdirSync(D + 'narudzbe.json.tmp');
    const resB = read('rezervacije.json');
    ok(broken.status === 500 && /greške na poslužitelju/.test(broken.data.error || '') && !resB.res.nera, 'pad upisa narudžbe: JSON 500, rezervacija vraćena (' + broken.status + ')');
    ok(/greske\.log/.test(fs.readdirSync(D).join(' ')) && !/pad@primjer/.test(fs.readFileSync(D + 'greske.log', 'utf8')), 'greška zapisana u data/greske.log, bez osobnih podataka');
    const retry = await post('order', order('api-key-0009-iiii', 'pad@primjer.hr', [{ id: 'nera' }], 85));
    ok(retry.data.ok && !retry.data.repeated, 'ponovni pokušaj nakon pada: narudžba prolazi');
    // 10) zaštita od sabotaže na narudžbi
    clearLimits(); clearRes();
    const three = await post('order', order('api-key-0011-kkkk', 's1@primjer.hr', [{ id: 'luna' }, { id: 'tara' }, { id: 'vela' }], 190));
    ok(three.status === 400 && /najviše dvije unikatne torbe/.test(three.data.error || ''), 'tri unikatne torbe u jednoj narudžbi: 400');
    const e1a = await post('order', order('api-key-0012-llll', 's1@primjer.hr', [{ id: 'tara' }], 55));
    const e1b = await post('order', order('api-key-0013-mmmm', 's1@primjer.hr', [{ id: 'vela' }], 70));
    ok(e1a.data.ok && e1b.status === 409 && /čeka (vašu )?uplatu/.test(e1b.data.error || ''), 'druga neplaćena narudžba sa šarenom torbom s istog e-maila: 409');
    const e2 = await post('order', order('api-key-0014-nnnn', 's2@primjer.hr', [{ id: 'vela' }], 70));
    const e3 = await post('order', order('api-key-0015-oooo', 's3@primjer.hr', [{ id: 'luna' }], 65));
    ok(e2.data.ok && e3.data.ok && e3.data.reserved === true && read('rezervacije.json').res.luna, 'v68: više neplaćenih narudžbi s iste veze — primaju se i rezerviraju (granica po vezi ukinuta)');
    clearLimits(); clearRes();
    const c1 = await post('order', order('api-key-0016-pppp', 'c1@primjer.hr', [{ id: 'luna' }], 65));
    const c2 = await post('order', order('api-key-0017-qqqq', 'c2@primjer.hr', [{ id: 'tara' }], 55));
    const c3 = await post('order', order('api-key-0018-rrrr', 'c3@primjer.hr', [{ id: 'vela' }], 70));
    ok(hrs(c1.data.until) > 23.9 && hrs(c2.data.until) > 23.9 && hrs(c3.data.until) > 23.9 && c3.data.reserved === true && read('rezervacije.json').res.vela, 'v68: svaka narudžba rezervira svoju torbu (nema granice „pola ponude”)');
    const c4 = await post('order', order('api-key-0018b-rrr', 'c4@primjer.hr', [{ id: 'vela' }], 70));
    ok(c4.status === 409 && JSON.stringify(c4.data.taken) === '["vela"]', 'v68: naručenu torbu drugi kupac ne može naručiti (409), nema narudžbi „bez rezervacije”');
    const c3r = await post('order', order('api-key-0018-rrrr', 'c3@primjer.hr', [{ id: 'vela' }], 70));
    ok(c3r.data.repeated && c3r.data.orderNo === c3.data.orderNo && c3r.data.reserved === true && typeof c3r.data.payUntil === 'string', 'ponovljeni ključ vraća istu narudžbu (s rokom za uplatu)');
    const mlc = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(!/mnogo rezervacija/.test(mlc) && !/tko prvi uplati/.test(mlc), 'v68: nema upozorenja „mnogo rezervacija” ni „tko prvi uplati”');
    // 11) tuđa košarica (rezervacija bez narudžbe) ne blokira
    clearLimits(); clearRes();
    const cart = await post('reserve', { ids: ['nera'], name: 'Košarica', email: 'kosarica@primjer.hr' });
    const st = await (await fetch(U + 'status')).json();
    ok(cart.data.ok && !st.ids.includes('nera'), 'rezervacija iz košarice se drugima ne prikazuje kao „Rezervirano”');
    const other2 = await post('reserve', { ids: ['nera'], name: 'Drugi', email: 'drugi2@primjer.hr' });
    const buy = await post('order', order('api-key-0019-ssss', 'kupac3@primjer.hr', [{ id: 'nera' }], 85));
    ok(other2.data.ok && buy.data.ok && read('rezervacije.json').res.nera.email === 'kupac3@primjer.hr', 'tuđa rezervacija bez narudžbe ne blokira: prva zaprimljena narudžba dobiva torbu');
    const late = await post('order', order('api-key-0020-tttt', 'kosarica@primjer.hr', [{ id: 'nera' }], 85, cart.data.hold));
    ok(late.status === 409 && JSON.stringify(late.data.taken) === '["nera"]', 'tko je samo rezervirao, a nije naručio, dobiva 409 kad je drugi već naručio');
    // 12) e-mail s +oznakom se broji kao isti
    setLimits({ orders_per_ip: 500 }); clearLimits(); clearRes();
    const p1 = await post('order', order('api-key-0021-uuuu', 'ana.kovac@gmail.com', [{ id: 'luna' }], 65));
    const p2 = await post('order', order('api-key-0022-vvvv', 'AnaKovac+2@gmail.com', [{ id: 'tara' }], 55));
    ok(p1.data.ok && p2.status === 409, 'ana.kovac@gmail i AnaKovac+2@gmail broje se kao isti e-mail: 409');
    // 13) najviše 30 potvrda kupcima na sat
    const z = read('zahtjevi.json'); z['sve|confirm'] = Array(30).fill(Math.floor(Date.now() / 1000)); fs.writeFileSync(D + 'zahtjevi.json', JSON.stringify(z));
    const capped = await post('order', order('api-key-0023-wwww', 'cap@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20));
    const ml2 = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(capped.data.ok && capped.data.mailCustomer === false && !/To: cap@primjer\.hr/.test(ml2) && /NIJE poslana automatska potvrda/.test(ml2), '31. potvrda u satu ne ide kupcu; vlasnica dobiva napomenu');
    // 13b) v68: rezervacija bez obzira na to koliko je torbi u neplaćenim narudžbama
    clearLimits(); clearRes();
    { const r = read('rezervacije.json'); r.sold = { luna: { id: 'luna', paidAt: '2026-10-11T00:00:00Z' }, tara: { id: 'tara', paidAt: '2026-10-11T00:00:00Z' } }; fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r)); }
    const hh2 = await post('order', order('api-key-0035-hhaa', 'h2@primjer.hr', [{ id: 'vela' }, { id: 'nera' }], 155));
    const f3 = await post('order', order('api-key-0030-ffcc', 'f3@primjer.hr', [{ id: 'nera' }], 85));
    const rh = read('rezervacije.json');
    ok(hh2.data.ok && hh2.data.reserved === true && rh.res.vela && rh.res.nera && f3.status === 409, 'v68: zadnje dvije slobodne torbe — obje rezervirane jednom kupcu, drugi dobiva 409');
    clearRes();
    // 14) ukupna granica novih narudžbi sa šarenom torbom na sat
    clearLimits(); clearRes(); setLimits({ unique_orders_per_hour: 2 });
    const u1 = await post('order', order('api-key-0024-xxxx', 'u1@primjer.hr', [{ id: 'luna' }], 65));
    const u2 = await post('order', order('api-key-0025-yyyy', 'u2@primjer.hr', [{ id: 'tara' }], 55));
    const u3 = await post('order', order('api-key-0026-zzzz', 'u3@primjer.hr', [{ id: 'vela' }], 70));
    const um = await post('order', order('api-key-0027-aaab', 'u4@primjer.hr', [{ id: 'remen', qty: 1, note: '' }], 15));
    const mlu = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(u1.data.ok && u2.data.ok && u3.data.ok && um.data.ok && /više od 2 narudžbi s unikatnim torbama/.test(mlu), 'ukupna granica na sat više ne odbija narudžbe (samo upozorenje vlasnici)');
    setLimits({ unique_orders_per_hour: 100 });
    // 15) najviše 3 potvrde na sat s iste adrese
    clearLimits(); setLimits({ confirm_per_ip: 3 });
    const cf = [];
    for (let i = 0; i < 4; i++) cf.push(await post('order', order('api-key-cf' + i + '-bbbb', 'cf' + i + '@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20)));
    ok(cf.slice(0, 3).every(r => r.data.mailCustomer === true) && cf[3].data.ok && cf[3].data.mailCustomer === false, '4. potvrda u satu s iste adrese ne ide kupcu');
    setLimits({ confirm_per_ip: 100 });
    // 15b) v68: granice po narudžbi — najviše 5 torbi i 10 artikala ukupno
    clearRes(); clearLimits(); setLimits({ unique_per_order: 2 });
    { const pl = prods(); const k = pl.find(x => x.id === 'klasik'); for (let i = 1; i <= 5; i++) pl.push(Object.assign({}, k, { id: 'kol' + i, name: 'Model ' + i, made_open_from: new Date(Date.now() - 1000).toISOString() })); fs.writeFileSync(D + 'proizvodi.json', JSON.stringify(pl, null, 2)); }
    const six = await post('order', order('api-key-six-ffff', 'six@primjer.hr', [{ id: 'luna' }, { id: 'kol1' }, { id: 'kol2' }, { id: 'kol3' }, { id: 'kol4' }, { id: 'kol5' }], 365));
    ok(six.status === 400 && /najviše 5 torbi i 10 artikala ukupno, od toga 2 unikatne torbe/.test(six.data.error || ''), 'v68: 6 torbi u narudžbi → 400 s porukom o granicama');
    const art = await post('order', order('api-key-art-ffff', 'art@primjer.hr', [{ id: 'remen', qty: 6 }, { id: 'kozmeticka', qty: 5 }], 190));
    ok(art.status === 400 && /10 artikala/.test(art.data.error || ''), 'v68: 11 artikala → 400');
    const okArt = await post('order', order('api-key-art2-ffff', 'art2@primjer.hr', [{ id: 'luna' }, { id: 'remen', qty: 5 }, { id: 'kozmeticka', qty: 4 }], 220));
    ok(okArt.data.ok, 'v68: 1 torba + 9 sitnica = 10 artikala prolazi');
    fs.writeFileSync(D + 'proizvodi.json', orig); clearRes(); clearLimits();
    // 15c) v68: besplatna dostava iznad praga, samo za odabrane načine; status nosi prag, granice i mjere
    { const c0 = read('config.json'); c0.free_ship = { min: 120, ids: ['boxnow'] }; c0.sizes = [{ mjera: '26 × 7 cm', opis: 'Stane mobitel.' }]; c0.size_all = 'Remen do 120 cm.'; fs.writeFileSync(D + 'config.json', JSON.stringify(c0, null, 2)); }
    const stF = await (await fetch(U + 'status')).json();
    ok(stF.freeShip && stF.freeShip.min === 120 && JSON.stringify(stF.freeShip.ids) === '["boxnow"]' && stF.limits.bags === 5 && stF.limits.articles === 10 && stF.sizes[0].opis === 'Stane mobitel.' && stF.sizeAll === 'Remen do 120 cm.', 'v68: status — besplatna dostava, granice, mjere i tekst za sve torbe');
    const box = { id: 'boxnow', label: 'BOX NOW paketomat', price: 4, locker: 'Zabok' }, gls = { id: 'gls', label: 'GLS', price: 6, locker: '' };
    const orderShip = (key, email, items, goods, ship, total) => ({ _gotcha: '', Napomena: '', _order: { key, customer: Object.assign(cust(email), { street: 'Ulica 1', city: 'Zabok' }), items, shipping: ship, goods, total } });
    const fsA = await post('order', orderShip('api-key-fs1-gggg', 'fs1@primjer.hr', [{ id: 'nera' }, { id: 'kozmeticka', qty: 2 }], 125, box, 125));
    const fsB = await post('order', orderShip('api-key-fs2-gggg', 'fs2@primjer.hr', [{ id: 'vela' }, { id: 'kozmeticka', qty: 3 }], 130, gls, 136));
    const fsC = await post('order', orderShip('api-key-fs3-gggg', 'fs3@primjer.hr', [{ id: 'luna' }, { id: 'remen', qty: 1 }], 80, box, 84));
    const fsW = await post('order', orderShip('api-key-fs4-gggg', 'fs4@primjer.hr', [{ id: 'tara' }, { id: 'remen', qty: 5 }], 130, box, 134));
    const mlF = fs.readFileSync(D + 'mail-log.txt', 'utf8').split(/^=== /m).filter(x => /fs1@primjer\.hr/.test(x.split('\n')[0]) && /potvrda narudžbe/.test(x)).pop() || '';
    ok(fsA.data.ok && fsA.data.total === 125 && fsB.data.ok && fsB.data.total === 136 && fsC.data.ok && fsC.data.total === 84 && fsW.status === 409 && /Dostava: BOX NOW paketomat — besplatno/.test(mlF), 'v68: BOX NOW besplatan od 120 € robe, GLS se plaća, ispod praga plaća se; krivi iznos (s dostavom) → 409');
    ok(/ROK ZA UPLATU: do (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje), \d/.test(mlF) && /instant uplatu/.test(mlF) && /Jasmina · Shiva\.J/.test(mlF) && typeof fsA.data.payUntil === 'string', 'v68: potvrda kupcu — točan rok za uplatu, savjet za instant uplatu, potpis; odgovor nosi payUntil');
    { const c0 = read('config.json'); c0.free_ship = { min: 0, ids: ['boxnow'] }; fs.writeFileSync(D + 'config.json', JSON.stringify(c0, null, 2)); }
    clearRes(); clearLimits();
    // 15d) v62/v63: Stalna kolekcija — najviše 3 zaprimljene narudžbe po modelu, 1 komad po narudžbi, jedna neplaćena po e-mailu
    clearRes(); clearLimits();
    setProd('klasik', { made_open_from: new Date().toISOString(), made_limit: 3 });
    const st0 = await (await fetch(U + 'status')).json();
    const k1 = await post('order', order('api-key-kol1-eeee', 'kol1@primjer.hr', [{ id: 'klasik', qty: 2 }], 60));
    const k1b = await post('order', order('api-key-kol1b-eeee', 'kol1@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const k2 = await post('order', order('api-key-kol2-eeee', 'kol2@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const k3 = await post('order', order('api-key-kol3-eeee', 'kol3@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const st1 = await (await fetch(U + 'status')).json();
    const k4 = await post('order', order('api-key-kol4-eeee', 'kol4@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const nK1 = read('narudzbe.json'); const o1 = nK1.find(x => x.no === k1.data.orderNo);
    ok(st0.madeLeft && st0.madeLeft.klasik === 3 && k1.data.ok && o1 && o1.order.items[0].qty === 1 && k2.data.ok && k3.data.ok && st1.madeLeft.klasik === 0 && k4.status === 409 && /Model „Klasik crna” je trenutno popunjen/.test(k4.data.error || ''),
      'v62/v63: limit 3 po modelu, 1 komad po narudžbi (količina 2 → 1), status madeLeft, 4. mjesto odbijeno');
    ok(k1b.status === 409 && /Prethodna narudžba iz Stalne kolekcije čeka vašu uplatu; podatke za uplatu imate u e-mailu/.test(k1b.data.error || ''), 'v63: isti e-mail — druga neplaćena narudžba iz Stalne kolekcije odbijena');
    const mlK = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(/Stalna kolekcija: „Klasik crna” — zauzeto 1 od 3 mjesta/.test(mlK) && /„Klasik crna” je sada popunjen \(3\/3 mjesta\)/.test(mlK), 'v63: e-mail vlasnici — zauzeto n od m, obavijest kad je popunjen');
    { const n = read('narudzbe.json'); const o = n.find(x => x.no === k1.data.orderNo); o.cancelledAt = new Date().toISOString(); o.ip = 'druga-veza'; o.ip_prev = ''; /* v78 (t1): storno s druge veze ne blokira ovu */ fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
    const k5 = await post('order', order('api-key-kol5-eeee', 'kol5@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    { const n = read('narudzbe.json'); n.unshift({ no: 'SJ-2026-9999', at: new Date().toISOString(), email: 'bez@primjer.hr', unconfirmed: true, order: { items: [{ id: 'klasik', qty: 1, made: true }] } }); fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
    const st2k = await (await fetch(U + 'status')).json();
    setProd('klasik', { made_open_from: new Date(Date.now() + 1000).toISOString() });
    await new Promise(r => setTimeout(r, 1200));
    const k6 = await post('order', order('api-key-kol6-eeee', 'kol6@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(k5.data.ok && st2k.madeLeft.klasik === 0 && k6.data.ok, 'v62/v63: stornirana i nepotvrđena narudžba se ne broje; „Otvori nova mjesta” otvara nova mjesta');
    // 15e) v64: ponovljena stavka, granica po vezi, kod greške, nepotvrđena ne blokira adresu
    ok(k1b.data.code === 'open_order', 'v64: „Već imate narudžbu” nosi kod open_order (stranica ne pogađa po tekstu)');
    setProd('klasik', { made_open_from: new Date(Date.now() + 1000).toISOString(), made_limit: 10 });
    await new Promise(r => setTimeout(r, 1200));
    const d1 = await post('order', order('api-key-dup1-eeee', 'dup1@primjer.hr', [{ id: 'klasik', qty: 1 }, { id: 'klasik', qty: 1 }, { id: 'klasik', qty: 1 }], 60));
    const stD = await (await fetch(U + 'status')).json();
    const oD = read('narudzbe.json').find(x => x.no === d1.data.orderNo);
    ok(d1.data.ok && stD.madeLeft.klasik === 9 && oD && oD.order.items.length === 1 && oD.order.items[0].qty === 1 && typeof oD.ip === 'string' && oD.ip !== '', 'v64: isti model triput u jednoj narudžbi → jedna stavka, jedno mjesto; narudžba pamti vezu: ' + (stD.madeLeft && stD.madeLeft.klasik));
    const d2 = await post('order', order('api-key-dup2-eeee', 'dup2@primjer.hr', [{ id: 'remen', qty: 2, note: 'crna' }, { id: 'remen', qty: 1 }], 45));
    const oD2 = read('narudzbe.json').find(x => x.no === d2.data.orderNo);
    ok(d2.data.ok && oD2 && oD2.order.items.length === 1 && oD2.order.items[0].qty === 3, 'v64: Ostalo dvaput u narudžbi → količine se zbrajaju u jednu stavku');
    { const n = read('narudzbe.json'); n.unshift({ no: 'SJ-2026-9998', at: new Date().toISOString(), email: 'blok@primjer.hr', unconfirmed: true, order: { items: [{ id: 'klasik', qty: 1, made: true }] } }); fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
    const bl = await post('order', order('api-key-blok-eeee', 'blok@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(bl.data.ok, 'v64: narudžba bez potvrde (preopterećenje) s nečijom adresom ne blokira tu adresu');
    const em = await post('order', order('api-key-email-eeee', 'nije-email', [{ id: 'klasik', qty: 1 }], 60));
    ok(em.status === 400 && em.data.field === 'email', 'v64: neispravan e-mail — field: email');
    try { fs.unlinkSync(D + 'odbijene.json'); } catch (e) {}
    const ip1 = await post('order', order('api-key-ipk1-eeee', 'ipk1@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(ip1.data.ok && !fs.existsSync(D + 'odbijene.json'), 'v68: više neplaćenih narudžbi iz kolekcije s iste veze — primaju se (granica po vezi ukinuta)');
    clearLimits();
    // 15f) v65: jedna veza ne zauzme sva mjesta modela; plaćena narudžba bez potvrde zauzima mjesto; želja kupca u e-mailovima
    { const pl = prods(); const k = pl.find(x => x.id === 'klasik'); pl.push(Object.assign({}, k, { id: 'klasik2', name: 'Klasik smeđa', made_limit: 3, made_open_from: new Date(Date.now() - 1000).toISOString() })); fs.writeFileSync(D + 'proizvodi.json', JSON.stringify(pl, null, 2)); }
    const w0 = await post('order', order('api-key-wis0-eeee', 'wis0@primjer.hr', [{ id: 'klasik2', qty: 1, note: 'smeđi remen' }], 60));
    ok(w0.status === 400 && /odaberite boju i duljinu remena/.test(w0.data.error || ''), 'v78: Stalna kolekcija bez boje i duljine remena iz ponude (slobodna želja) → 400');
    const w1 = await post('order', order('api-key-wish-eeee', 'wish@primjer.hr', [{ id: 'klasik2', qty: 1, note: 'remen: smeđa, 100 cm; inicijali AB' }], 60));
    const mlW = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(k1b.data.code === 'open_order' && k1b.data.kind === 'coll' && /Čim uplata bude vidljiva, šijemo vašu torbu \(rok izrade 5 radnih dana od primitka uplate\)/.test(mlW) && !/Torbu iz Stalne kolekcije šijemo za vas nakon uplate/.test(mlW), 'v66: otvorena narudžba nosi vrstu; e-mail kupcu — izrada, rok i slanje u jednom odlomku');
    ok(w1.data.ok && /Remen za „Klasik smeđa”: smeđa, 100 cm \(iz ponude\)\./.test(mlW) && /REMEN — Klasik smeđa: smeđa, 100 cm \(iz ponude\)/.test(mlW) && !/inicijali AB/.test(mlW) && !/ŽELJA KUPCA|posebna želja|Vaša želja/i.test(mlW), 'v65/v78: remen iz ponude u potvrdi kupcu i vlasnici; posebna želja se ne prima ni ne ponavlja');
    clearLimits();
    const m2 = await post('order', order('api-key-mod2-eeee', 'mod2@primjer.hr', [{ id: 'klasik2', qty: 1 }], 60));
    const m3 = await post('order', order('api-key-mod3-eeee', 'mod3@primjer.hr', [{ id: 'klasik2', qty: 1 }], 60));
    const stM = await (await fetch(U + 'status')).json();
    ok(m2.data.ok && m3.data.ok && stM.madeLeft.klasik2 === 0, 'v68: s iste veze mogu se zauzeti sva mjesta modela (granica po vezi ukinuta): ' + (stM.madeLeft && stM.madeLeft.klasik2));
    // v68: neplaćena narudžba iz kolekcije nakon roka za uplatu više ne drži mjesto
    { const n = read('narudzbe.json'); const o = n.find(x => x.no === m3.data.orderNo); o.at = new Date(Date.now() - 25 * 3600 * 1000).toISOString(); fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
    const stE = await (await fetch(U + 'status')).json();
    ok(stE.madeLeft.klasik2 === 1, 'v68: mjesto iz neplaćene narudžbe vraća se nakon roka za uplatu (24 h): ' + (stE.madeLeft && stE.madeLeft.klasik2));
    { const n = read('narudzbe.json'); n.unshift({ no: 'SJ-2026-9997', at: new Date().toISOString(), email: 'plac@primjer.hr', unconfirmed: true, paidAt: new Date().toISOString(), order: { items: [{ id: 'klasik2', qty: 1, made: true }] } }); fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
    const stN = await (await fetch(U + 'status')).json();
    ok(stN.madeLeft.klasik2 === 0, 'v65: plaćena narudžba bez potvrde (preopterećenje) zauzima mjesto: ' + (stN.madeLeft && stN.madeLeft.klasik2));
    // v67: rokovi za više artikala po narudžbi — s nazivima, isti rok jednom
    setProd('remen', { lead: '7 radnih dana' }); clearLimits();
    const two = await post('order', order('api-key-two-eeee', 'dva@primjer.hr', [{ id: 'klasik', qty: 1 }, { id: 'remen', qty: 1 }], 75));
    const mlT = fs.readFileSync(D + 'mail-log.txt', 'utf8').split(/^=== /m).filter(x => /dva@primjer\.hr/.test(x.split('\n')[0]) && /potvrda narudžbe/.test(x)).pop() || '';
    ok(two.data.ok && /izrađujemo „Klasik crna” i „Zamjenski remen” \(rok izrade od primitka uplate: „Klasik crna” 5 radnih dana, „Zamjenski remen” 7 radnih dana\)/.test(mlT), 'v67: e-mail — različiti rokovi s nazivima: ' + (mlT.match(/Čim uplata[^\n]*/) || [''])[0].slice(0, 200));
    ok(k2.data.payment && k2.data.payment.iban && typeof k2.data.ownerEmail === 'string', 'v62: odgovor narudžbe nosi podatke za uplatu iz Postavki');
    { const c0 = read('config.json'); c0.show_ostalo = false; fs.writeFileSync(D + 'config.json', JSON.stringify(c0, null, 2));
      const os = await post('order', order('api-key-ost1-eeee', 'ost@primjer.hr', [{ id: 'remen', qty: 1, note: 'crna' }], 15));
      const st2 = await (await fetch(U + 'status')).json();
      c0.show_ostalo = true; fs.writeFileSync(D + 'config.json', JSON.stringify(c0, null, 2));
      ok(os.status === 409 && /nije u ponudi/.test(os.data.error || '') && st2.showOstalo === false, 'v62: rubrika Ostalo isključena — artikl se ne naručuje, status showOstalo'); }
    setProd('klasik', { made_open_from: new Date(Date.now() + 1000).toISOString(), made_limit: 99 }); clearRes(); clearLimits();
    // 15f) v69: blaga granica po vezi (najviše 3 neplaćene narudžbe s iste veze) i „Uplatio/la sam”
    { const n0 = fs.readFileSync(D + 'narudzbe.json', 'utf8');
      fs.writeFileSync(D + 'narudzbe.json', '[]'); clearRes(); clearLimits(); setLimits({ orders_per_ip: 3 });
      const i1 = await post('order', order('api-key-ip1-hhhh', 'ip1@primjer.hr', [{ id: 'luna' }], 65));
      const i2 = await post('order', order('api-key-ip2-hhhh', 'ip2@primjer.hr', [{ id: 'tara' }], 55));
      const i3 = await post('order', order('api-key-ip3-hhhh', 'ip3@primjer.hr', [{ id: 'remen' }], 15));
      const i4 = await post('order', order('api-key-ip4-hhhh', 'ip4@primjer.hr', [{ id: 'nera' }], 85));
      const mlI = fs.readFileSync(D + 'mail-log.txt', 'utf8');
      ok(i1.data.ok && i2.data.ok && i3.data.ok && i4.status === 429 && i4.data.code === 'ip_limit' && /već čeka uplatu nekoliko narudžbi, pa novu trenutno ne možemo primiti/.test(i4.data.error || '') && /narudžba nije primljena \(ista veza\)/.test(mlI) && /ip4@primjer\.hr/.test(mlI),
        'v69: s iste veze najviše 3 neplaćene narudžbe — 4. dobiva poruku, vlasnica e-mail s podacima kupca');
      const nI = read('narudzbe.json'); const oI1 = nI.find(x => x.no === i1.data.orderNo);
      ok(oI1 && oI1.payUntil === i1.data.payUntil && /^[a-f0-9]{32}$/.test(oI1.claim || ''), 'v69: rok za uplatu i tajna za „Uplatio/la sam” zapisani uz narudžbu');
      // v69.1: klik nakon isteka ne oživljava narudžbu — granica od 3 po vezi ostaje (i1, i2, i3 aktivne)
      { const n = read('narudzbe.json'); const o = n.find(x => x.no === i2.data.orderNo); o.payUntil = '2000-01-01T00:00:00Z'; o.expiredAt = '2000-01-01T00:00:00Z'; if (o.reservation) o.reservation.until = '2000-01-01T00:00:00Z'; /* v81: i držanje do radnog dana je prošlo */ fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
      expire(['tara']); clearLimits();
      const i5 = await post('order', order('api-key-ip5-hhhh', 'ip5@primjer.hr', [{ id: 'vela' }], 70)); /* i2 je istekla: mjesto za novu */
      const oI2 = read('narudzbe.json').find(x => x.no === i2.data.orderNo);
      const late = await post('paidclaim', { no: i2.data.orderNo, t: oI2.claim });
      const oL = read('narudzbe.json').find(x => x.no === i2.data.orderNo);
      clearLimits();
      const i6 = await post('order', order('api-key-ip6-hhhh', 'ip6@primjer.hr', [{ id: 'nera' }], 85));
      const mlL = fs.readFileSync(D + 'mail-log.txt', 'utf8');
      ok(i5.data.ok && late.data.ok && late.data.state === 'late' && /javiti vam se/.test(late.data.message || '') && oL.claimedAt && oL.claimLate && oL.payUntil === '2000-01-01T00:00:00Z' && !read('rezervacije.json').res.tara
        && i6.status === 429 && /kupac javlja uplatu nakon isteka roka: SJ-/.test(mlL),
        'v69.1: „Uplatio/la sam” nakon isteka samo javlja vlasnici — rok se ne produžuje, torba se ne rezervira, granica od 3 po vezi ostaje');
      setLimits({ orders_per_ip: 500 });
      // „Uplatio/la sam” prije isteka: kriva tajna, GET, produženje, drugi put „već ste javili”
      const bad = await post('paidclaim', { no: i1.data.orderNo, t: 'f'.repeat(32) });
      const getR = await fetch(U + 'paidclaim');
      // v70: GET s ispravnim linkom samo čita datum (ništa ne mijenja); kriva tajna 403
      const pre = await (await fetch(U + 'paidclaim?no=' + i1.data.orderNo + '&t=' + oI1.claim)).json();
      const preBad = await fetch(U + 'paidclaim?no=' + i1.data.orderNo + '&t=' + 'e'.repeat(32));
      const oPre = read('narudzbe.json').find(x => x.no === i1.data.orderNo);
      const cl = await post('paidclaim', { no: i1.data.orderNo, t: oI1.claim });
      const oC = read('narudzbe.json').find(x => x.no === i1.data.orderNo); const rl = read('rezervacije.json').res.luna;
      const mlC = fs.readFileSync(D + 'mail-log.txt', 'utf8');
      const zg = iso => new Intl.DateTimeFormat('hr-HR', { timeZone: 'Europe/Zagreb', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
      const capT = Math.min(Date.now() + 96 * 3600e3, Date.parse(oC.at) + 120 * 3600e3);
      ok(pre.ok && pre.state === 'open' && pre.unique === 1 && pre.claimUntil && preBad.status === 403 && !oPre.claimedAt && Math.abs(Date.parse(pre.claimUntil) - Date.parse(cl.data.payUntil)) < 120e3
        && (zg(cl.data.payUntil) === '23:59' || Math.abs(Date.parse(cl.data.payUntil) - capT) < 120e3) && i1.data.claimUntil && Date.parse(i1.data.claimUntil) <= Date.parse(cl.data.payUntil) + 1000,
        'v70: GET paidclaim čita datum (do 23:59 radnog dana ili granica 96 h / 120 h) i ništa ne mijenja; narudžba vraća claimUntil koji klik ispunjava');
      ok(bad.status === 403 && getR.status === 400 && cl.data.ok && cl.data.state === 'extended' && hrs(cl.data.payUntil) > 23.9 && oC.claimedAt && oC.payUntil === cl.data.payUntil && rl && rl.until === cl.data.payUntil && /kupac javlja uplatu: SJ-/.test(mlC),
        'v69: „Uplatio/la sam” — kriva tajna 403, GET bez linka 400; rok i rezervacija produženi, vlasnica dobiva e-mail');
      const cl2 = await post('paidclaim', { no: i1.data.orderNo, t: oI1.claim });
      const pre2 = await (await fetch(U + 'paidclaim?no=' + i1.data.orderNo + '&t=' + oI1.claim)).json();
      ok(pre2.ok && pre2.state === 'already' && /Već ste nam javili da ste uplatili; torbu čuvamo za vas do/.test(pre2.message || '') && !/Hvala! /.test(cl.data.message || ''), 'v70 (D70-4, D70-7): ponovno otvoren link — „već ste javili” bez gumba; poruka nakon klika bez dvostrukog „Hvala!”');
      ok(cl2.data.ok && cl2.data.state === 'already' && /Već ste nam javili/.test(cl2.data.message || ''), 'v69: „Uplatio/la sam” vrijedi jednom');
      // v72 (vijeće, D72-1/2): rok istekao, torba se drži do storna — stranica kaže isto što i e-mail; klik nakon
      // „Potvrda primljena” ne upisuje claimLate (ne gasi automatski storno)
      { const fut = new Date(Date.now() + 40 * 3600e3).toISOString(), past = new Date(Date.now() - 3600e3).toISOString();
        const n = read('narudzbe.json'); const a = n.find(x => x.no === i1.data.orderNo), b = n.find(x => x.no === i5.data.orderNo);
        a.payUntil = past; a.expiredAt = past; b.payUntil = past; b.expiredAt = past; b.proofAt = [past];
        fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n));
        const r = read('rezervacije.json'); r.res.luna = Object.assign(r.res.luna || {}, { id: 'luna', token: a.reservation.token, until: fut, ordered: true });
        r.res.vela = { id: 'vela', token: b.reservation.token, until: fut, ordered: true }; fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r));
        clearLimits();
        const gA = await (await fetch(U + 'paidclaim?no=' + a.no + '&t=' + a.claim)).json();
        const gB = await (await fetch(U + 'paidclaim?no=' + b.no + '&t=' + b.claim)).json();
        const pB = await post('paidclaim', { no: b.no, t: b.claim });
        const oB = read('narudzbe.json').find(x => x.no === b.no);
        ok(gA.state === 'already' && /ali uplatu još ne vidimo na računu\. Torbu „Luna” i dalje čuvamo za vas do /.test(gA.message || '') && !/više ne možemo automatski čuvati/.test(gA.message || ''),
          'v72 (D72-1): ponovno otvoren link nakon roka, torba držana — „i dalje čuvamo do …”, kao u e-mailu');
        ok(gB.state === 'already' && pB.data.state === 'already' && /Vašu potvrdu o uplati već smo primili\. Torbu „Vela” i dalje čuvamo/.test(pB.data.message || '') && !oB.claimLate && !oB.claimedAt,
          'v72 (D72-2): nakon „Potvrda primljena” klik na stari link ne upisuje kasni klik (automatski storno ostaje)'); }
      fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(read('narudzbe.json').concat(JSON.parse(n0)))); clearRes(); clearLimits(); }
    // 16) e-mailovi
    const ml = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(/ROK ZA UPLATU: do [^\n]* u \d\d:\d\d\.\n/.test(ml) && /kliknite „Uplatio\/la sam”: torbu tada čuvamo do [^\n]* u \d\d:\d\d\./.test(ml) && /Link ne radi\? Odgovorite na ovaj e-mail sa snimkom potvrde o uplati ili proslijedite potvrdu iz banke\./.test(ml) && !/čuvamo za vas i nakon isteka roka|čuvamo dok je slobodna|čuvamo dok su slobodne/.test(ml) && /Uplata je pravodobna ako je vaša banka nalog za plaćanje zaprimila do isteka roka\. Torbu zato čuvamo još do [^;]+; ako ni tada ne vidimo uplatu, a niste nam javili da ste platili \(„Uplatio\/la sam”\), torba se vraća u prodaju\./.test(ml) && !/vaš klik|klik brži/.test(ml) && /popune se sami/.test(ml) && /na Instagramu \(@shiva\.j_handmade\)/.test(ml) && /preuzeli narudžbu/.test(ml) && /kako piše u točki 7\. Uvjeta kupnje na kraju ovog e-maila/.test(ml), 'e-mail kupcu: rok, jedno obećanje „Uplatio/la sam” s datumom (v70), bez riječi „klik” (v71), barkod popunjava sve, „na Instagramu”, preuzimanje, t. 7 (v73)');
    { const custs = ml.split(/^=== /m).filter(x => /\| Subject: [^|]*potvrda narudžbe SJ-/.test(x)).map(x => x.split('UVJETI KUPNJE (vrijede')[0]); /* v73: Uvjeti na kraju e-maila provjerava zaseban test */
      const uniq = custs.filter(x => !/rok izrade/i.test(x) && /čeka vašu uplatu/.test(x)), made = custs.filter(x => /rok izrade/i.test(x));
      ok(custs.length > 0 && custs.every(x => /Poziv na broj: \d{4}-\d{4} \(upišite točno ovako, bez „SJ-”\)/.test(x) && /hvala vam na narudžbi br\. SJ-\d{4}-\d{4}! Ova poruka je potvrda vaše narudžbe\./.test(x) && !/prepišite|rađene/.test(x)),
        'v25: e-mail kupcu — potvrda na vrhu, poziv na broj „bez SJ-”, „kopirajte”');
      ok(uniq.length > 0 && uniq.every(x => !/osobnim specifikacijama/.test(x) && /(je rezervirana za vas — postoji|su rezervirane za vas — svaka postoji)/.test(x) && /ROK ZA UPLATU: do (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje), \d/.test(x)), 'v25: narudžba samo s unikatima — rok u uvodu, bez rečenice o izradi po željama');
      ok(made.length > 0 && made.every(x => /bez navođenja razloga\. /.test(x) && !/osobnim specifikacijama|ne vrijedi pravo na jednostrani raskid/.test(x)), 'v25/v78 (vijeće V78-P1): narudžba s artiklom po narudžbi — raskid u 14 dana bez iznimke, kao Uvjeti'); }
    ok(/kliknite „Potvrda primljena”/.test(ml) && !/obećali da torbu tada čuvamo/.test(ml) && /Poziv na broj \(za usporedbu s izvodom banke\): \d{4}-\d{4}/.test(ml) && /- Luna — 65,00 €/.test(ml) && /api\/storno\?token=/.test(ml), 'e-mail vlasnici: uputa „Potvrda primljena” (v70), cijena, storno link (lokalni host je provjeren)');
    // 17) v73: Uvjeti u e-mailu s potvrdom (trajni medij) — doslovno kao stranica; funkcija za raskid (ZZP čl. 81.a)
    { const html = fs.readFileSync(SITE + 'index.html', 'utf8');
      const m = /<!-- UVJETI-START -->([\s\S]*?)<!-- UVJETI-END -->/.exec(html);
      const cfg = read('config.json'); const conf = !!cfg.require_confirm; const ttl = Number(cfg.ttl_hours || 24);
      const hrs = n => n === 1 ? '1 sat' : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? n + ' sata' : n + ' sati';
      const norm = x => x.replace(/&nbsp;|\u00a0/g, ' ').replace(/&amp;/g, '&').replace(/<\/?(p|h3|h4|pre|li|ul|details|summary)\b[^>]*>/g, ' ').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
      const want = norm((m ? m[1] : '').replace(conf ? /<span data-if-noconfirm>[\s\S]*?<\/span>/g : /<span data-if-confirm>[\s\S]*?<\/span>/g, '').replace(/<span data-ttl-text>[\s\S]*?<\/span>/g, hrs(ttl)).replace(/<span data-terms-ship-wrap>([\s\S]*?)<span data-terms-ship-lead>[\s\S]*?<\/span><\/span>/g, (m, a) => cfg.ship_lead ? a + cfg.ship_lead : '').replace(/<span data-terms-cheap-wrap>([\s\S]*?)<span data-terms-cheapest>[\s\S]*?<\/span><\/span>/g, (m, a) => { const pd = (cfg.shipping || []).filter(o => Number(o.price) > 0).sort((x, y) => x.price - y.price)[0]; return pd ? a + pd.label : ''; }).replace(/<span data-terms-auto-wrap>([\s\S]*?)<span data-terms-auto>[\s\S]*?<\/span>([\s\S]*?)<\/span>/g, (m, a, b) => { const ah = cfg.deadline_tick ? Math.max(0, Number(cfg.auto_cancel_hours) || 0) : 0; return ah ? a + hrs(ah) + b : ''; }).replace(/<li>/g, '- '));
      const last = ml.split(/^=== /m).filter(x => /\| Subject: [^|]*potvrda narudžbe SJ-/.test(x)).pop() || '';
      const got = norm((last.split('UVJETI KUPNJE (vrijede za ovu narudžbu, s obrascem za jednostrani raskid ugovora)')[1] || ''));
      ok(m && want.length > 3000 && got.startsWith(want) && /7\.3\. Kako raskinuti ugovor/.test(got) && /Ja ______________ ovim izjavljujem da jednostrano raskidam Ugovor o prodaji sljedeće robe/.test(got),
        'v73: e-mail s potvrdom sadrži cijele Uvjete i obrazac, doslovno kao stranica (grane potvrde i broj sati iz postavki)');
      ok(/Raskid ugovora: http[^\s]*\/#raskid\?no=\d{4}-\d{4}/.test(last), 'v73: e-mail s potvrdom ima poveznicu „Raskid ugovora” s brojem narudžbe');
      ok(/NJEGA TORBE\nBok, ja sam tvoja nova torba\.\n\nSašivena sam od umjetne kože[\s\S]*Uz malo brige dugo ćemo ići zajedno\.\n/.test(last) && last.indexOf('NJEGA TORBE') < last.indexOf('Srdačan pozdrav,') && /Spor možete pokušati riješiti pred tijelom za alternativno rješavanje potrošačkih sporova \(popis vodi Ministarstvo gospodarstva\)\./.test(last), 'v74: potvrda s torbom sadrži upute za njegu (tekst vlasnice) i t. 9 o izvansudskom rješavanju sporova'); }
    { clearLimits(); fs.writeFileSync(D + 'mail-log.txt', '');
      const o = read('narudzbe.json')[0];
      const good = await post('raskid', { name: 'Ana Kupac', no: o.no.replace(/^SJ-/, ''), email: o.email, iban: 'HR12 1001 0051 8630 0016 0', note: 'Ne odgovara boja.' });
      const ml3 = fs.readFileSync(D + 'mail-log.txt', 'utf8');
      const rr = read('raskidi.json'); const oo = read('narudzbe.json').find(x => x.no === o.no);
      ok(good.status === 200 && good.data.ok && good.data.mailed === true && /\d{1,2}\. \d{1,2}\. \d{4}\. u \d\d:\d\d/.test(good.data.at || ''),
        'v73: api/raskid — 200, potvrda poslana, datum i vrijeme u odgovoru');
      const cm = ml3.split(/^=== /m).find(x => /potvrda primitka izjave o raskidu ugovora/.test(x)) || '';
      const om = ml3.split(/^=== /m).find(x => /RASKID UGOVORA: SJ-/.test(x)) || '';
      ok(cm.includes(o.email) && /SADRŽAJ IZJAVE\nOvime izjavljujem da jednostrano raskidam ugovor o kupnji\.\nIme i prezime: Ana Kupac\nBroj narudžbe: \d{4}-\d{4}/.test(cm) && /IBAN za povrat novca: HR12 … 0160/.test(cm) && !/HR1210010051863000160/.test(cm) && /Napomena: Ne odgovara boja\./.test(cm) && /Datum i vrijeme podnošenja: \d{1,2}\. \d{1,2}\. \d{4}\. u \d\d:\d\d/.test(cm),
        'v73: potvrda kupcu na trajnom mediju — sadržaj izjave, datum i vrijeme (čl. 81.a st. 6.)');
      ok(/\(e-mail se podudara\)/.test(om) && /IBAN za povrat novca: HR1210010051863000160/.test(om) && /Na drugi IBAN samo ako ga kupac potvrdi s e-adrese iz narudžbe\./.test(om) && rr.length === 1 && rr[0].order === o.no && rr[0].emailMatch === true && (oo.withdrawals || []).length === 1,
        'v73: vlasnica dobiva kopiju s narudžbom; izjava spremljena (raskidi.json) i zabilježena uz narudžbu');
      const unk = await post('raskid', { name: 'Netko', no: 'kupila sam Lunu u listopadu', email: 'netko@primjer.hr' });
      const om2 = fs.readFileSync(D + 'mail-log.txt', 'utf8').split(/^=== /m).find(x => /RASKID UGOVORA: kupila/.test(x)) || '';
      ok(unk.status === 200 && unk.data.ok && /Broj narudžbe nije pronađen u sustavu — izjava ipak vrijedi/.test(om2),
        'v73: izjava bez pronađene narudžbe vrijedi (st. 7.), kupac ne saznaje postoji li narudžba, vlasnica je upozorena');
      const miss = await post('raskid', { name: '', no: '2026-0001', email: 'x' });
      const bIban = await post('raskid', { name: 'A', no: '2026-0001', email: 'a@primjer.hr', iban: '12345' });
      const hp = await post('raskid', { name: 'A', no: '2026-0001', email: 'a@primjer.hr', website: 'x' });
      const getR = await fetch(U + 'raskid');
      ok(miss.status === 400 && /ime i prezime, ispravan e-mail/.test(miss.data.error || '') && bIban.status === 400 && /IBAN/.test(bIban.data.error || '') && hp.status === 400 && getR.status === 405,
        'v73: api/raskid odbija prazna polja, krivi IBAN, mamac za botove i GET');
      clearLimits(); fs.writeFileSync(D + 'mail-log.txt', '');
      const lnk = await post('raskid', { name: 'Hitno platite', no: '2026-0001', email: 'b@primjer.hr', note: 'Uplatite na http://zlo.example' });
      const mlL = fs.readFileSync(D + 'mail-log.txt', 'utf8').split(/^=== /m);
      const cL = mlL.find(x => /potvrda primitka izjave o raskidu/.test(x)) || '', oL = mlL.find(x => /RASKID UGOVORA:/.test(x)) || '';
      ok(lnk.status === 200 && lnk.data.ok && /Napomena: Uplatite na \[uklonjeno\]/.test(cL) && !/zlo\.example/.test(cL) && /http:\/\/zlo\.example/.test(oL),
        'v77 (recenzija R76-P9, ZZP čl. 81.a st. 3.): izjava s poveznicom se prima; kupcu poveznica „[uklonjeno]”, vlasnici izvorni tekst');
      clearLimits(); fs.writeFileSync(D + 'mail-log.txt', '');
      await post('raskid', { name: 'Nazovite 060 123 456', no: '2026-0001', email: 'c@primjer.hr' });
      const cN = fs.readFileSync(D + 'mail-log.txt', 'utf8').split(/^=== /m).find(x => /potvrda primitka izjave o raskidu/.test(x)) || '';
      ok(/\nDobar dan,\n/.test(cN) && !/Dobar dan, Nazovite/.test(cN), 'v77 (vijeće V77-3): ime koje nije samo slovima ne ide u pozdrav potvrde raskida');
      clearLimits();
      const ordL = await post('order', { _order: { customer: { name: 'Dobili ste 500 EUR na www.prevara.example', email: 'z@primjer.hr', phone: '091 111 1111' }, items: [{ id: 'luna' }], shipping: { id: 'pickup' } } });
      ok(ordL.status === 400 && /poveznice/.test(ordL.data.error || ''), 'v77 (recenzija R76-S3): narudžba s poveznicom u imenu odbijena (e-mail ne nosi lažnu poruku)');
      clearLimits(); const same = [];
      for (let i = 0; i < 4; i++) same.push((await post('raskid', { name: 'Isti', no: '2026-0001', email: 'isti@primjer.hr' })).status);
      ok(same.join() === '200,200,200,429', 'v73: najviše 3 potvrde istom primatelju na sat, zatim uputa za e-mail (429)');
      clearLimits(); }
    // v78 (12. krug): t2 raskid neplaćene narudžbe je stornira; t1 ista torba 7 dana s istog e-maila ili veze; t5 e-mail nakon „Uplatio/la sam”
    { clearLimits(); setLimits({ orders_per_ip: 500 }); fs.writeFileSync(D + 'mail-log.txt', '');
      { const pl = prods(); const l = pl.find(x => x.id === 'luna'); pl.push(Object.assign({}, l, { id: 't78a', name: 'Proba 78A', sold: false, reserved: false }), Object.assign({}, l, { id: 't78b', name: 'Proba 78B', sold: false, reserved: false }), Object.assign({}, l, { id: 't78c', name: 'Proba 78C', sold: false, reserved: false })); fs.writeFileSync(D + 'proizvodi.json', JSON.stringify(pl, null, 2)); }
      const a1 = await post('order', order('api-key-v78a-aaaa', 'v78a@primjer.hr', [{ id: 't78a' }], 65));
      const rk = await post('raskid', { name: 'Ana Proba', no: (a1.data.orderNo || '').replace(/^SJ-/, ''), email: 'v78a@primjer.hr' });
      const oA = read('narudzbe.json').find(x => x.no === a1.data.orderNo) || {};
      const mlR = fs.readFileSync(D + 'mail-log.txt', 'utf8');
      const cR = mlR.split(/^=== /m).find(x => /potvrda primitka izjave o raskidu/.test(x)) || '';
      const oR = mlR.split(/^=== /m).find(x => /RASKID UGOVORA: SJ-/.test(x)) || '';
      const stR = await (await fetch(U + 'status')).json();
      ok(a1.data.ok && rk.data.ok && rk.data.cancelled === undefined && oA.cancelledAt && oA.cancelBy === 'kupac (raskid)' && oA.cancelReason === 'kupac odustao' && !read('rezervacije.json').res.t78a && !(stR.ids || []).includes('t78a')
        && /Narudžba još nije plaćena, pa smo je stornirali; ništa nam ne dugujete\.\nAko ste u međuvremenu ipak uplatili, cijeli iznos vraćamo najkasnije 14 dana od danas/.test(cR) && !/Što slijedi/.test(cR) && /automatski stornirana/.test(oR) && /vratite je najkasnije 14 dana/.test(oR),
        'v78 (t2, vijeće V78-S1/S4): raskid neplaćene narudžbe — storno, torba u prodaju, potvrda „ništa nam ne dugujete” uz povrat ako je ipak uplaćeno; odgovor ne otkriva stanje');
      clearLimits();
      const relT = () => { const r = read('rezervacije.json'); if (r.res) delete r.res.t78a; fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r)); };
      const cancelAs = (no, by, reason, ago) => { const n = read('narudzbe.json'); const o = n.find(x => x.no === no); o.cancelledAt = new Date(Date.now() - (ago || 0)).toISOString(); o.cancelBy = by; o.cancelReason = reason; fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); relT(); };
      const r1 = await post('order', order('api-key-v78r-aaaa', 'v78a@primjer.hr', [{ id: 't78a' }], 65));
      ok(r1.data.ok, 'v81 (13. krug, p1): raskid kupca (storno „kupac odustao”) ne blokira ponovnu narudžbu iste torbe');
      cancelAs(r1.data.orderNo, 'automatski', 'nije uplaćeno u roku'); clearLimits();
      const r2 = await post('order', order('api-key-v78s-aaaa', 'v78a@primjer.hr', [{ id: 't78a' }], 65));
      const r2b = await post('order', order('api-key-v78u-aaaa', 'drugi78@primjer.hr', [{ id: 't78a' }], 65));
      ok(r2.status === 409 && r2.data.code === 'recent_cancel' && /trenutno ne možemo primiti/.test(r2.data.error || '') && !/stornirana/.test(r2.data.error || '') && r2b.data.ok,
        'v81 (p1): nakon automatskog storna ista e-adresa 7 dana ne naručuje istu torbu, a druga e-adresa s iste veze može');
      cancelAs(r2b.data.orderNo, 'vlasnica', 'lažna narudžba'); clearLimits();
      const r2c = await post('order', order('api-key-v78v-aaaa', 'treci78@primjer.hr', [{ id: 't78a' }], 65));
      ok(r2c.status === 409 && r2c.data.code === 'recent_cancel', 'v81 (p1): nakon storna „lažna narudžba” ni ista internetska veza 7 dana ne naručuje istu torbu');
      { const n = read('narudzbe.json'); n.forEach(o => { if (o.cancelledAt) o.cancelledAt = new Date(Date.now() - 8 * 86400000).toISOString(); }); fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
      clearLimits();
      const r3 = await post('order', order('api-key-v78t-aaaa', 'v78a@primjer.hr', [{ id: 't78a' }], 65));
      ok(r3.data.ok, 'v78 (t1): nakon 7 dana ista torba se ponovno može naručiti');
      // paid order: raskid ne stornira
      { const n = read('narudzbe.json'); const o = n.find(x => x.no === r3.data.orderNo); o.paidAt = new Date().toISOString(); fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
      clearLimits();
      const rkP = await post('raskid', { name: 'Ana Proba', no: (r3.data.orderNo || '').replace(/^SJ-/, ''), email: 'v78a@primjer.hr' });
      ok(rkP.data.ok && !read('narudzbe.json').find(x => x.no === r3.data.orderNo).cancelledAt, 'v78 (t2): raskid plaćene narudžbe ne stornira (povrat novca po t. 7.5)');
      // t5
      clearLimits(); fs.writeFileSync(D + 'mail-log.txt', '');
      const b1 = await post('order', order('api-key-v78b-aaaa', 'v78b@primjer.hr', [{ id: 't78b' }], 65));
      const oB = read('narudzbe.json').find(x => x.no === b1.data.orderNo) || {};
      const cl1 = await post('paidclaim', { no: b1.data.orderNo, t: oB.claim });
      const cl2 = await post('paidclaim', { no: b1.data.orderNo, t: oB.claim });
      const mlC = fs.readFileSync(D + 'mail-log.txt', 'utf8').split(/^=== /m).filter(x => /zabilježili smo vašu obavijest o uplati, narudžba/.test(x));
      ok(cl1.data.ok && cl1.data.state === 'extended' && cl2.data.state === 'already' && mlC.length === 1 && mlC[0].includes('v78b@primjer.hr') && /Torbu čuvamo za vas do /.test(mlC[0]),
        'v78 (t5): nakon „Uplatio/la sam” kupac dobiva kratki e-mail s datumom do kojeg čuvamo torbu (samo jednom)');
      clearLimits(); }
    // v81 (recenzija R81-1): „Uplatio/la sam” nakon roka, a dok se torba još drži (do 23:59 radnog dana) — vrijedi kao na vrijeme
    { clearLimits();
      const c1 = await post('order', order('api-key-v81c-aaaa', 'v81c@primjer.hr', [{ id: 't78c' }], 65));
      { const n = read('narudzbe.json'); const o = n.find(x => x.no === c1.data.orderNo); o.payUntil = new Date(Date.now() - 3600000).toISOString(); o.expiredAt = o.payUntil; fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); }
      const oC = read('narudzbe.json').find(x => x.no === c1.data.orderNo) || {};
      const resC = (read('rezervacije.json').res || {}).t78c || {};
      const gC = await (await fetch(U + 'paidclaim?no=' + c1.data.orderNo + '&t=' + oC.claim)).json();
      const pC = await post('paidclaim', { no: c1.data.orderNo, t: oC.claim });
      const oC2 = read('narudzbe.json').find(x => x.no === c1.data.orderNo) || {};
      ok(c1.data.ok && Date.parse(resC.until) > Date.parse(c1.data.payUntil) && gC.state === 'open' && pC.data.state === 'extended' && !oC2.claimLate && Date.parse(oC2.payUntil) > Date.now(),
        'v81 (p2, R81-1): rezervacija traje dulje od roka; klik nakon roka dok se torba drži produljuje rok (ne „kasni klik”)'); clearLimits(); }
    // v81 (recenzija B1): klik nakon roka dok se torba drži, a mjesto u kolekciji je u međuvremenu popunjeno — „kasni klik”, ne lažno „produženo”
    { clearLimits();
      const pl0 = fs.readFileSync(D + 'proizvodi.json', 'utf8');
      const setLim = v => { const pl = JSON.parse(fs.readFileSync(D + 'proizvodi.json', 'utf8')); const k = pl.find(x => x.id === 'klasik'); k.made_limit = v; if (!pl.some(x => x.id === 't81d')) pl.push(Object.assign({}, pl.find(x => x.id === 'luna'), { id: 't81d', name: 'Proba 81D', sold: false, reserved: false })); fs.writeFileSync(D + 'proizvodi.json', JSON.stringify(pl, null, 2)); return pl; };
      const plD = setLim(99); const tot = Number(plD.find(x => x.id === 't81d').price) + Number(plD.find(x => x.id === 'klasik').price);
      const d1 = await post('order', strapFill(order('api-key-v81d-aaaa', 'v81d@primjer.hr', [{ id: 't81d' }, { id: 'klasik' }], tot)));
      { const n = read('narudzbe.json'); const o = n.find(x => x.no === d1.data.orderNo); if (o) { o.payUntil = new Date(Date.now() - 3600000).toISOString(); o.expiredAt = o.payUntil; fs.writeFileSync(D + 'narudzbe.json', JSON.stringify(n)); } }
      setLim(0);
      const oD = read('narudzbe.json').find(x => x.no === d1.data.orderNo) || {};
      const m0 = fs.existsSync(D + 'mail-log.txt') ? fs.readFileSync(D + 'mail-log.txt', 'utf8').length : 0;
      const pD = await post('paidclaim', { no: d1.data.orderNo, t: oD.claim });
      const oD2 = read('narudzbe.json').find(x => x.no === d1.data.orderNo) || {};
      const mlD = fs.readFileSync(D + 'mail-log.txt', 'utf8').slice(m0);
      ok(d1.data.ok && pD.data.state === 'late' && /^Mjesto u kolekciji za „/.test(pD.data.message || '') && !/Rok za uplatu je već istekao/.test(pD.data.message || '') && oD2.claimLate === true && !!oD2.claimedAt && /mjesto u kolekciji za „[^”]+” u međuvremenu je popunjeno/.test(mlD) && !/Rok za uplatu produžen je jednom/.test(mlD),
        'v81 (recenzija B1): popunjeno mjesto u kolekciji nakon roka — klik zabilježen kao kasni, vlasnica upozorena, bez lažnog „produženo” ' + JSON.stringify([d1.data.error, pD.data.state]));
      fs.writeFileSync(D + 'proizvodi.json', pl0); clearLimits(); }
    // v78 (t3): automatski storno u Uvjetima (status autoCancel) kad rokovi rade
    { const c = read('config.json'); const old = JSON.stringify(c); c.deadline_tick = true; c.auto_cancel_hours = 48; fs.writeFileSync(D + 'config.json', JSON.stringify(c, null, 2));
      const stA = await (await fetch(U + 'status')).json();
      fs.writeFileSync(D + 'config.json', old);
      const stB = await (await fetch(U + 'status')).json();
      ok(stA.autoCancel === 48 && stB.autoCancel === 0, 'v78 (t3): api/status — autoCancel iz Postavki samo kad rokovi rade'); }
  } finally { fs.writeFileSync(D + 'proizvodi.json', orig); }
  console.log(R.join('\n'));
  const fails = R.filter(l => l.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + R.length + ')');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.log(R.join('\n')); console.error('GREŠKA SKRIPTE', e); process.exit(1); });
