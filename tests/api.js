/* API testovi preko HTTP-a:  sh tests/serve.sh &  pa  node tests/api.js
   Probna stranica: SHIVAJ_SITE (zadano /tmp/shivaj-test), poslužitelj http://127.0.0.1:8090/ */
const fs = require('fs');
const SITE = (process.env.SHIVAJ_SITE || '/tmp/shivaj-test') + '/';
const D = SITE + 'data/';
const U = (process.env.SHIVAJ_URL || 'http://127.0.0.1:8090/') + 'api/';
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const post = async (a, body) => { const r = await fetch(U + a, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); return { status: r.status, data: await r.json().catch(() => ({})) }; };
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
    ok(three.status === 400 && /najviše dvije šarene torbe/.test(three.data.error || ''), 'tri šarene torbe u jednoj narudžbi: 400');
    setLimits({ orders_per_ip: 2 });
    const e1a = await post('order', order('api-key-0012-llll', 's1@primjer.hr', [{ id: 'tara' }], 55));
    const e1b = await post('order', order('api-key-0013-mmmm', 's1@primjer.hr', [{ id: 'vela' }], 70));
    ok(e1a.data.ok && e1b.status === 409 && /čeka uplatu/.test(e1b.data.error || ''), 'druga neplaćena narudžba sa šarenom torbom s istog e-maila: 409');
    const e2 = await post('order', order('api-key-0014-nnnn', 's2@primjer.hr', [{ id: 'vela' }], 70));
    const e3 = await post('order', order('api-key-0015-oooo', 's3@primjer.hr', [{ id: 'luna' }], 65));
    ok(e2.data.ok && e3.status === 429 && !read('rezervacije.json').res.luna, 'treća neplaćena narudžba s iste adrese: 429, torba ostaje slobodna');
    setLimits({ orders_per_ip: 20 });
    clearLimits(); clearRes();
    const c1 = await post('order', order('api-key-0016-pppp', 'c1@primjer.hr', [{ id: 'luna' }], 65));
    const c2 = await post('order', order('api-key-0017-qqqq', 'c2@primjer.hr', [{ id: 'tara' }], 55));
    const c3 = await post('order', order('api-key-0018-rrrr', 'c3@primjer.hr', [{ id: 'vela' }], 70));
    ok(hrs(c1.data.until) > 23.9 && hrs(c2.data.until) > 23.9 && c3.data.ok && c3.data.until === null && c3.data.reserved === false && !read('rezervacije.json').res.vela, 'pola ponude u neplaćenim narudžbama: nova narudžba prima se BEZ rezervacije, torba ostaje slobodna');
    const c4 = await post('order', order('api-key-0018b-rrr', 'c4@primjer.hr', [{ id: 'vela' }], 70));
    ok(c4.data.ok && c4.data.reserved === false, 'tu torbu i dalje može naručiti drugi kupac (napadač ne drži više od pola ponude)');
    const c3r = await post('order', order('api-key-0018-rrrr', 'c3@primjer.hr', [{ id: 'vela' }], 70));
    ok(c3r.data.repeated && c3r.data.orderNo === c3.data.orderNo && c3r.data.reserved === false, 'ponovljeni ključ vraća istu narudžbu bez rezervacije');
    const mlc = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(/Subject: PAŽNJA, mnogo rezervacija — Narudžba/.test(mlc) && /bez rezervacije \(dobiva ih tko prvi uplati\): Vela/.test(mlc) && /dobiva je tko prvi uplati/.test(mlc), 'vlasnica dobiva upozorenje, kupac objašnjenje „tko prvi uplati”');
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
    setLimits({ orders_per_ip: 20 }); clearLimits(); clearRes();
    const p1 = await post('order', order('api-key-0021-uuuu', 'ana.kovac@gmail.com', [{ id: 'luna' }], 65));
    const p2 = await post('order', order('api-key-0022-vvvv', 'AnaKovac+2@gmail.com', [{ id: 'tara' }], 55));
    ok(p1.data.ok && p2.status === 409, 'ana.kovac@gmail i AnaKovac+2@gmail broje se kao isti e-mail: 409');
    // 13) najviše 30 potvrda kupcima na sat
    const z = read('zahtjevi.json'); z['sve|confirm'] = Array(30).fill(Math.floor(Date.now() / 1000)); fs.writeFileSync(D + 'zahtjevi.json', JSON.stringify(z));
    const capped = await post('order', order('api-key-0023-wwww', 'cap@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20));
    const ml2 = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(capped.data.ok && capped.data.mailCustomer === false && !/To: cap@primjer\.hr/.test(ml2) && /NIJE poslana automatska potvrda/.test(ml2), '31. potvrda u satu ne ide kupcu; vlasnica dobiva napomenu');
    // 13b) pola STVARNO slobodnih: torbe označene „Plaćeno” ne broje se kao slobodne; mala ponuda
    clearLimits(); clearRes();
    { const r = read('rezervacije.json'); r.sold = { luna: { id: 'luna', paidAt: '2026-10-11T00:00:00Z' }, tara: { id: 'tara', paidAt: '2026-10-11T00:00:00Z' } }; fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r)); }
    const f1 = await post('order', order('api-key-0028-ffaa', 'f1@primjer.hr', [{ id: 'vela' }], 70));
    const f2 = await post('order', order('api-key-0029-ffbb', 'f2@primjer.hr', [{ id: 'nera' }], 85));
    ok(f1.data.reserved === true && f2.data.ok && f2.data.reserved === false, '2 od 4 torbe plaćene: slobodne su 2, rezervirati se smije samo 1 (druga narudžba bez rezervacije)');
    { const r = read('rezervacije.json'); r.res = {}; r.sold.vela = { id: 'vela', paidAt: '2026-10-11T00:00:00Z' }; fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r)); }
    const f3 = await post('order', order('api-key-0030-ffcc', 'f3@primjer.hr', [{ id: 'nera' }], 85));
    ok(f3.data.ok && f3.data.reserved === false && !read('rezervacije.json').res.nera, 'zadnja slobodna torba ne rezervira se nikome (dobiva je tko prvi uplati)');
    clearRes();
    // 13c) dodijeljena rezervacija vrijedi do isteka i kad se poslije proda druga torba (obećanje kupcu)
    clearLimits(); clearRes();
    const g1 = await post('order', order('api-key-0031-ggaa', 'g1@primjer.hr', [{ id: 'luna' }], 65));
    const g2 = await post('order', order('api-key-0032-ggbb', 'g2@primjer.hr', [{ id: 'tara' }], 55));
    { const r = read('rezervacije.json'); r.sold = { vela: { id: 'vela', paidAt: '2026-10-11T00:00:00Z' } }; fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r)); }
    const stG = await (await fetch(U + 'status')).json();
    const g3 = await post('order', order('api-key-0033-ggcc', 'g3@primjer.hr', [{ id: 'tara' }], 55));
    ok(g1.data.reserved && g2.data.reserved && stG.ids.includes('luna') && stG.ids.includes('tara') && g3.status === 409, 'nakon prodaje treće torbe obje dodijeljene rezervacije i dalje vrijede (Tara: 409 za drugog kupca)');
    const g4 = await post('order', order('api-key-0034-ggdd', 'g4@primjer.hr', [{ id: 'nera' }], 85));
    ok(g4.data.ok && g4.data.reserved === false, 'nova narudžba ne dobiva rezervaciju dok neplaćene drže više od pola (Nera bez rezervacije)');
    // 13d) rezervira se koliko stane
    clearLimits(); clearRes();
    { const r = read('rezervacije.json'); r.sold = { luna: { id: 'luna' }, tara: { id: 'tara' } }; fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r)); }
    const hh2 = await post('order', order('api-key-0035-hhaa', 'h2@primjer.hr', [{ id: 'vela' }, { id: 'nera' }], 155));
    const rh = read('rezervacije.json');
    ok(hh2.data.ok && hh2.data.until && JSON.stringify(hh2.data.unheldIds) === '["nera"]' && rh.res.vela && !rh.res.nera, 'slobodne 2, granica 1: narudžba s dvije torbe rezervira Velu, Nera bez rezervacije');
    // 13e) torba koja nije na prodaji ne sužava granicu
    clearLimits(); clearRes();
    { const r = read('rezervacije.json'); r.res = { luna: { id: 'luna', token: 'tx', email: 'x@p.hr', ordered: true, at: '2026-10-11T00:00:00Z', until: '2099-01-01T00:00:00Z' } }; fs.writeFileSync(D + 'rezervacije.json', JSON.stringify(r)); }
    setProd('luna', { hidden: true });
    const hd = await post('order', order('api-key-0036-iiaa', 'hd@primjer.hr', [{ id: 'tara' }], 55));
    fs.writeFileSync(D + 'proizvodi.json', orig);
    ok(hd.data.ok && hd.data.reserved === true, 'skrivena torba s rezervacijom ne sužava granicu: Tara dobiva rezervaciju');
    clearRes();
    // 14) ukupna granica novih narudžbi sa šarenom torbom na sat
    clearLimits(); clearRes(); setLimits({ unique_orders_per_hour: 2 });
    const u1 = await post('order', order('api-key-0024-xxxx', 'u1@primjer.hr', [{ id: 'luna' }], 65));
    const u2 = await post('order', order('api-key-0025-yyyy', 'u2@primjer.hr', [{ id: 'tara' }], 55));
    const u3 = await post('order', order('api-key-0026-zzzz', 'u3@primjer.hr', [{ id: 'vela' }], 70));
    const um = await post('order', order('api-key-0027-aaab', 'u4@primjer.hr', [{ id: 'remen', qty: 1, note: '' }], 15));
    const mlu = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(u1.data.ok && u2.data.ok && u3.data.ok && um.data.ok && /više od 2 narudžbi sa šarenim torbama/.test(mlu), 'ukupna granica na sat više ne odbija narudžbe (samo upozorenje vlasnici)');
    setLimits({ unique_orders_per_hour: 100 });
    // 15) najviše 3 potvrde na sat s iste adrese
    clearLimits(); setLimits({ confirm_per_ip: 3 });
    const cf = [];
    for (let i = 0; i < 4; i++) cf.push(await post('order', order('api-key-cf' + i + '-bbbb', 'cf' + i + '@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20)));
    ok(cf.slice(0, 3).every(r => r.data.mailCustomer === true) && cf[3].data.ok && cf[3].data.mailCustomer === false, '4. potvrda u satu s iste adrese ne ide kupcu');
    setLimits({ confirm_per_ip: 100 });
    // 15b) v26: u maloj ponudi rezervaciju dobivaju barem tri narudžbe (zadano hold_min = 3)
    clearRes(); clearLimits(); setLimits({ hold_min: 3 });
    const hm1 = await post('order', order('api-key-hm1-cccc', 'hm1@primjer.hr', [{ id: 'luna', qty: 1 }], 65));
    const hm2 = await post('order', order('api-key-hm2-cccc', 'hm2@primjer.hr', [{ id: 'tara', qty: 1 }], 55));
    const hm3 = await post('order', order('api-key-hm3-cccc', 'hm3@primjer.hr', [{ id: 'vela', qty: 1 }], 70));
    /* 4 slobodne: staro pravilo (pola) dalo bi rezervaciju samo dvjema, novo trima */
    ok([hm1, hm2, hm3].every(r => r.data.ok && r.data.reserved && !r.data.unheld), 'v26: od 4 slobodne torbe rezervaciju dobivaju tri kupca (granica barem 3, ne samo pola)');
    setLimits({ hold_min: 0, orders_per_ip: 20, unique_orders_per_hour: 100 }); clearRes();
    // 16) e-mailovi
    const ml = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(/Rezervacija vrijedi 24 sata/.test(ml) && /popune se sami/.test(ml) && /na Instagramu \(@shiva\.j_handmade\)/.test(ml) && /preuzeli narudžbu/.test(ml) && /na način iz t\. 6/.test(ml), 'e-mail kupcu: sati, barkod popunjava sve, „na Instagramu”, preuzimanje, t. 6');
    { const custs = ml.split(/^=== /m).filter(x => /\| Subject: [^|]*potvrda narudžbe SJ-/.test(x));
      const uniq = custs.filter(x => !/Rok izrade/.test(x) && /čeka vašu uplatu/.test(x)), made = custs.filter(x => /Rok izrade/.test(x));
      ok(custs.length > 0 && custs.every(x => /Poziv na broj: \d{4}-\d{4} \(upišite točno ovako, bez „SJ-”\)/.test(x) && /hvala vam na narudžbi br\. SJ-\d{4}-\d{4}! Ova poruka je potvrda vaše narudžbe\./.test(x) && !/prepišite|rađene/.test(x)),
        'v25: e-mail kupcu — potvrda na vrhu, poziv na broj „bez SJ-”, „kopirajte”');
      ok(uniq.length > 0 && uniq.every(x => !/posebnim željama/.test(x) && /rezervirana za vas do \d/.test(x)), 'v25: narudžba samo s unikatima — rok u uvodu, bez rečenice o izradi po željama');
      ok(made.length > 0 && made.every(x => /raskinuti \(osim artikala izrađenih po vašim posebnim željama\) u roku 14 dana/.test(x) && !/ne vrijedi pravo na jednostrani raskid/.test(x)), 'v25: narudžba s artiklom po narudžbi — jedna iznimka, istim riječima kao Uvjeti'); }
    ok(/kliknite „Označi plaćenom” prije isteka rezervacije/.test(ml) && /Poziv na broj \(za usporedbu s izvodom banke\): \d{4}-\d{4}/.test(ml) && /- Luna — 65,00 €/.test(ml) && /api\/storno\?token=/.test(ml), 'e-mail vlasnici: uputa prije isteka, cijena, storno link (lokalni host je provjeren)');
  } finally { fs.writeFileSync(D + 'proizvodi.json', orig); }
  console.log(R.join('\n'));
  const fails = R.filter(l => l.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + R.length + ')');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.log(R.join('\n')); console.error('GREŠKA SKRIPTE', e); process.exit(1); });
