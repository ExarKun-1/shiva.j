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
    const other = await post('order', order('api-key-0002-bbbb', 'drugi@primjer.hr', [{ id: 'luna' }], 65));
    ok(other.status === 409 && JSON.stringify(other.data.taken) === '["luna"]', 'torba koju drži drugi kupac: 409 taken');
    // 3) ispravljen e-mail s ključem rezervacije: narudžba prolazi
    const fixed = await post('order', order('api-key-0003-cccc', 'ana.ispravno@primjer.hr', [{ id: 'luna' }], 65, rs.data.hold));
    const resA = read('rezervacije.json');
    ok(fixed.data.ok && hrs(fixed.data.until) > 23.9 && resA.res.luna.email === 'ana.ispravno@primjer.hr' && resA.res.luna.ordered === true, 'ispravljen e-mail + ključ rezervacije: narudžba prolazi, puni rok, novi e-mail');
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
    clearLimits(); expire(['luna', 'vela']); await fetch(U + 'status');
    const h1 = await post('reserve', { ids: ['luna'], name: 'X', email: 'x@primjer.hr' });
    const h2 = await post('reserve', { ids: ['nera'], name: 'X', email: 'x@primjer.hr' });
    const h3 = await post('reserve', { ids: ['vela'], name: 'X', email: 'x@primjer.hr' });
    ok(h1.data.ok && h2.data.ok && h3.status === 429 && /rezervacij/.test(h3.data.error || ''), 'treća rezervacija bez narudžbe s iste adrese: 429');
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
    // 10) e-mailovi
    const ml = fs.readFileSync(D + 'mail-log.txt', 'utf8');
    ok(/Rezervacija vrijedi 24 sata/.test(ml) && /upišite još iznos/.test(ml) && /na Instagramu \(@shiva\.j_handmade\)/.test(ml) && /preuzeli narudžbu/.test(ml) && /na način iz t\. 6/.test(ml), 'e-mail kupcu: sati, „upišite”, „na Instagramu”, preuzimanje, t. 6');
    ok(/kliknite „Plaćeno” prije isteka rezervacije/.test(ml) && /- Luna — 65,00 €/.test(ml) && /api\/storno\?token=/.test(ml), 'e-mail vlasnici: uputa prije isteka, cijena, storno link (lokalni host je provjeren)');
  } finally { fs.writeFileSync(D + 'proizvodi.json', orig); }
  console.log(R.join('\n'));
  const fails = R.filter(l => l.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + R.length + ')');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.log(R.join('\n')); console.error('GREŠKA SKRIPTE', e); process.exit(1); });
