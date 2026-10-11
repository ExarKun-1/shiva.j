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
  try {
    // 1) idempotentnost i ishod e-mailova
    const remen = [{ id: 'remen', qty: 1, note: 'crna' }];
    const r1 = await post('order', order('api-key-0001-aaaa', 'ana@primjer.hr', remen, 15));
    const r2 = await post('order', order('api-key-0001-aaaa', 'ana@primjer.hr', remen, 15));
    const n1 = read('narudzbe.json');
    ok(r1.data.ok && r2.data.repeated && r1.data.orderNo === r2.data.orderNo && n1.length === 1, 'isti ključ dvaput: jedna narudžba ' + r1.data.orderNo);
    ok(n1[0].mail && n1[0].mail.customer === true && r2.data.mailCustomer === true, 'ishod e-mailova spremljen i vraćen u ponovljenom odgovoru');
    ok(!/ROK IZRADE/.test(fs.readFileSync(D + 'mail-log.txt', 'utf8')), 'nepopunjen {ROK IZRADE} ne ide u e-mail');
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
    ok(again.status === 409 && /već je u vašoj narudžbi SJ-2026-0002/.test(again.data.error || ''), 'ista torba u novoj narudžbi istog kupca: 409 s brojem ranije narudžbe');
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
    ok(hrs(c1.data.until) > 23.9 && hrs(c2.data.until) > 23.9 && c3.data.ok && hrs(c3.data.until) < 2.01, 'više od pola ponude u neplaćenim narudžbama: nova narudžba drži torbu samo 2 sata (' + hrs(c3.data.until).toFixed(2) + ')');
    const mlc = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(/Subject: PAŽNJA, mnogo rezervacija — Narudžba/.test(mlc) && /Storniraj sve s ove adrese/.test(mlc) && /Rezervacija vrijedi 2 sata/.test(mlc), 'vlasnica dobiva upozorenje, kupac točan rok (2 sata)');
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
    // 14) e-mailovi
    const ml = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(/Rezervacija vrijedi 24 sata/.test(ml) && /upišite još iznos/.test(ml) && /na Instagramu \(@shiva\.j_handmade\)/.test(ml) && /preuzeli narudžbu/.test(ml) && /na način iz t\. 6/.test(ml), 'e-mail kupcu: sati, „upišite”, „na Instagramu”, preuzimanje, t. 6');
    ok(/kliknite „Plaćeno” prije isteka rezervacije/.test(ml) && /- Luna — 65,00 €/.test(ml) && /api\/storno\?token=/.test(ml), 'e-mail vlasnici: uputa prije isteka, cijena, storno link (lokalni host je provjeren)');
  } finally { fs.writeFileSync(D + 'proizvodi.json', orig); }
  console.log(R.join('\n'));
  const fails = R.filter(l => l.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + R.length + ')');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.log(R.join('\n')); console.error('GREŠKA SKRIPTE', e); process.exit(1); });
