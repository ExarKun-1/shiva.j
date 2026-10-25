/* Potvrda narudžbe e-mailom (v40):  sh tests/serve.sh &  pa  node tests/confirm.js
   Uključi require_confirm u probnoj stranici, provjeri tok narudžba → e-mail s linkom → potvrda, pa vrati postavke. */
const fs = require('fs');
const SITE = (process.env.SHIVAJ_SITE || '/tmp/shivaj-test') + '/';
const D = SITE + 'data/';
const U = (process.env.SHIVAJ_URL || 'http://127.0.0.1:8090/') + 'api/';
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const post = async (a, body, type) => { const r = await fetch(U + a, { method: 'POST', headers: { 'Content-Type': type || 'application/json' }, body: JSON.stringify(body) }); return { status: r.status, data: await r.json().catch(() => ({})) }; };
const cust = email => ({ name: 'Potvrda Test', email, phone: '', street: '', city: '' });
const pickup = { id: 'pickup', label: 'Osobno preuzimanje u radionici', price: 0, locker: '' };
const order = (key, email, items, total) => ({ _gotcha: '', Napomena: '', _order: { key, customer: cust(email), items, shipping: pickup, goods: total, total } });
const read = (f, d) => { try { return JSON.parse(fs.readFileSync(D + f, 'utf8')); } catch (e) { return d; } };
const write = (f, v) => fs.writeFileSync(D + f, JSON.stringify(v, null, 2));
const mailLog = () => { try { return fs.readFileSync(D + 'mail-log.txt', 'utf8'); } catch (e) { return ''; } };
const lastLink = () => { const m = [...mailLog().matchAll(/\?potvrda=([a-f0-9]{32})\.([a-f0-9]{32})/g)]; const x = m[m.length - 1]; return x ? { id: x[1], t: x[2] } : null; };
(async () => {
  const cfg0 = read('config.json');
  try {
    write('config.json', Object.assign({}, cfg0, { require_confirm: true, confirm_minutes: 60 }));
    write('rezervacije.json', { res: {}, sold: {} }); write('narudzbe.json', []);
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    try { fs.unlinkSync(D + 'narudzbe-na-cekanju.json'); } catch (e) {}
    const mail0 = mailLog().length;

    // 1) narudžba čeka potvrdu: nema broja, nema rezervacije, vlasnica nije dobila e-mail
    const r1 = await post('order', order('potvrda-key-0001', 'kupac@primjer.hr', [{ id: 'luna' }], 65), 'text/plain');
    const st = await (await fetch(U + 'status')).json();
    const newMail = mailLog().slice(mail0);
    ok(r1.data.ok && r1.data.pending && !r1.data.orderNo && r1.data.mailCustomer === true, 'narudžba (text/plain) → čeka potvrdu, bez broja');
    ok(!(st.ids || []).includes('luna') && !read('rezervacije.json', {}).res?.luna && read('narudzbe.json', []).length === 0, 'nepotvrđena narudžba ne rezervira i ne ulazi u Narudžbe');
    ok(/potvrdite narudžbu/i.test(newMail) && /kupac@primjer\.hr/.test(newMail) && !/shivaj\.handmade@gmail\.com\nSubject: =\?UTF-8\?B\?.*Narud/.test(newMail) && !/Nova narudžba/.test(newMail), 'kupac dobiva e-mail s linkom, vlasnica ne');
    ok(/Link vrijedi 1 sat \(do /.test(newMail), 'v21: e-mail kaže koliko link vrijedi');
    const L1 = lastLink();
    // 2) ponovno slanje istim ključem: isti zapis, nova tajna, stari link ne vrijedi
    const r2 = await post('order', order('potvrda-key-0001', 'kupac@primjer.hr', [{ id: 'luna' }], 65));
    const L2 = lastLink();
    ok(r2.data.pending && L2 && L1 && L2.id === L1.id && L2.t !== L1.t && read('narudzbe-na-cekanju.json', []).length === 1, 'ponovno slanje: isti zapis, novi link');
    const old = await post('confirm', { id: L1.id, t: L1.t });
    ok(old.status === 403, 'stari link (stara tajna) → 403');
    const bad = await post('confirm', { id: L2.id, t: 'f'.repeat(32) });
    ok(bad.status === 403, 'kriva tajna → 403');
    // 3) potvrda: broj, rezervacija, e-mail vlasnici i kupcu, podaci za ploču
    const c1 = await post('confirm', { id: L2.id, t: L2.t }, 'text/plain');
    const res = read('rezervacije.json', {}).res || {};
    const all = mailLog().slice(mail0);
    ok(c1.data.ok && /^SJ-\d{4}-\d{4}$/.test(c1.data.orderNo || '') && res.luna && res.luna.ordered === true, 'potvrda → broj narudžbe ' + c1.data.orderNo + ' i rezervacija');
    ok(Array.isArray(c1.data.items) && c1.data.items[0].id === 'luna' && c1.data.ship && c1.data.ship.id === 'pickup', 'odgovor nosi stavke i dostavu (ploča i na drugom uređaju)');
    ok(/Nova narudžba/.test(all) && /potvrdio narudžbu linkom/.test(all) && /PODACI ZA UPLATU/.test(all), 'nakon potvrde: e-mail vlasnici i kupcu s podacima za uplatu');
    const c2 = await post('confirm', { id: L2.id, t: L2.t });
    ok(c2.data.ok && c2.data.repeated && c2.data.orderNo === c1.data.orderNo && read('narudzbe.json', []).length === 1, 'ponovni klik na link: ista narudžba');
    // 4) torba iz potvrđene narudžbe: drugi kupac odmah dobiva 409
    const r3 = await post('order', order('potvrda-key-0002', 'drugi@primjer.hr', [{ id: 'luna' }], 65));
    ok(r3.status === 409 && JSON.stringify(r3.data.taken) === '["luna"]', 'torba iz potvrđene narudžbe: drugi kupac 409 već pri narudžbi');
    // 5) istekao link
    const r4 = await post('order', order('potvrda-key-0003', 'treci@primjer.hr', [{ id: 'vela' }], 70));
    const L4 = lastLink();
    const pend = read('narudzbe-na-cekanju.json', []); pend.forEach(x => { if (x.id === L4.id) x.until = new Date(Date.now() - 60000).toISOString(); }); write('narudzbe-na-cekanju.json', pend);
    const c4 = await post('confirm', { id: L4.id, t: L4.t });
    ok(r4.data.pending && c4.status === 410 && c4.data.expired && !(read('rezervacije.json', {}).res || {}).vela, 'istekao link → 410, bez rezervacije');
    // 6) lažne narudžbe bez potvrde ne zatvaraju ponudu: 5 nepotvrđenih na 5 adresa, status prazan, pravi kupac rezervira
    for (let i = 0; i < 5; i++) await post('order', order('potvrda-lazna-' + i + '-xx', 'lazni' + i + '@primjer.hr', [{ id: 'vela' }], 70));
    const st2 = await (await fetch(U + 'status')).json();
    const real = await post('order', order('potvrda-key-0004', 'pravi@primjer.hr', [{ id: 'vela' }], 70));
    const Lr = lastLink(); const cr = await post('confirm', { id: Lr.id, t: Lr.t });
    ok(!(st2.ids || []).includes('vela') && cr.data.ok && (read('rezervacije.json', {}).res || {}).vela?.email === 'pravi@primjer.hr', 'nepotvrđene lažne narudžbe ne drže torbu; pravi kupac je potvrdi i dobiva');
    // 7) v41: tuđi e-mail ne briše pravu nepotvrđenu narudžbu; po adresi najviše 3 nepotvrđene
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    await post('order', order('potvrda-zrtva-0001', 'zrtva@primjer.hr', [{ id: 'tara' }], 55));
    const Lz = lastLink();
    await post('order', order('potvrda-napad-0001', 'zrtva@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const cz = await post('confirm', { id: Lz.id, t: Lz.t });
    ok(cz.data.ok && /^SJ-/.test(cz.data.orderNo || ''), 'narudžba s tuđim e-mailom ne poništava žrtvin link (potvrda radi)');
    const many = read('narudzbe-na-cekanju.json', []); for (let i = 0; i < 3; i++) many.unshift({ id: 'f'.repeat(31) + i, h: 'x', key: 'stari-' + i, at: '', until: new Date(Date.now() + 3e6).toISOString(), ek: 'puno@primjer.hr', P: {} }); write('narudzbe-na-cekanju.json', many);
    await post('order', order('potvrda-puno-0001', 'puno@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const pu = read('narudzbe-na-cekanju.json', []).filter(x => !x.no && x.ek === 'puno@primjer.hr');
    ok(pu.length === 3 && pu[0].key === 'potvrda-puno-0001' && !pu.some(x => x.key === 'stari-0'), 'po adresi najviše 3 nepotvrđene, najstarija ispada');
    // 7b) najviše 2 e-maila za potvrdu na sat na istu adresu
    const r3x = await post('order', order('potvrda-puno-0002', 'puno@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const r4x = await post('order', order('potvrda-puno-0003', 'puno@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(r3x.status === 200 && r4x.status === 429 && /već smo poslali/.test(r4x.data.error || ''), 'treći e-mail za potvrdu na istu adresu u satu → 429');
    // 7c) 100 e-mailova za potvrdu u satu (s mnogo adresa): narudžba se i dalje prima, vlasnica je upozorena
    const nowS = Math.floor(Date.now() / 1000);
    write('zahtjevi.json', { 'sve|confirm': Array(100).fill(nowS), 'sve|confirm_warn': Array(100).fill(nowS) });
    const r100 = await post('order', order('potvrda-mnogo-0001', 'mnogo@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(r100.status === 200 && r100.data.pending && /mnogo narudžbi/.test(mailLog()), 'nakon 100 e-mailova za potvrdu u satu: narudžba primljena, vlasnica upozorena');
    // 7d) potvrda ponovno čita katalog: promijenjena cijena ili ručno prodana torba → 409, bez rezervacije
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    const prods0 = fs.readFileSync(D + 'proizvodi.json', 'utf8');
    await post('order', order('potvrda-cijena-01', 'cijena@primjer.hr', [{ id: 'klasik', qty: 1 }], 60)); const Lc = lastLink();
    const pp = JSON.parse(prods0); pp.find(x => x.id === 'klasik').price = 61; write('proizvodi.json', pp);
    const cc = await post('confirm', { id: Lc.id, t: Lc.t });
    fs.writeFileSync(D + 'proizvodi.json', prods0);
    await post('order', order('potvrda-prod-0001', 'prodano@primjer.hr', [{ id: 'nera' }], JSON.parse(prods0).find(x => x.id === 'nera').price));
    const Lp = lastLink();
    const pp2 = JSON.parse(prods0); pp2.find(x => x.id === 'nera').sold = true; write('proizvodi.json', pp2);
    const cpx = await post('confirm', { id: Lp.id, t: Lp.t });
    fs.writeFileSync(D + 'proizvodi.json', prods0);
    ok(cc.status === 409 && /Cijene/.test(cc.data.error || '') && cpx.status === 409 && JSON.stringify(cpx.data.taken) === '["nera"]' && !(read('rezervacije.json', {}).res || {}).nera, 'potvrda nakon promjene cijene ili prodaje torbe → 409, bez rezervacije');
    // 7e) v44: granica po paru adresa+primatelj (2/h) i po primatelju (5/h); tuđa adresa ne blokira pravog kupca
    const crypto = require('crypto');
    const ekHash = e => crypto.createHash('sha256').update(e).digest('hex').slice(0, 20);
    const nowE = Math.floor(Date.now() / 1000);
    write('zahtjevi.json', { ['mailto-' + ekHash('par@primjer.hr') + '|nekaDrugaAdresa']: [nowE, nowE] });
    const rPar = await post('order', order('potvrda-par-0001', 'par@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    write('zahtjevi.json', { ['sve|mailto-' + ekHash('pun@primjer.hr')]: Array(5).fill(nowE) });
    const rPun = await post('order', order('potvrda-pun-0001', 'pun@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(rPar.status === 200 && rPun.status === 429, 'napadač s druge adrese ne blokira kupca (par 2/h); po primatelju najviše 5/h');
    // 7f) odbijena narudžba ne troši granicu po adresi
    write('config.json', Object.assign({}, read('config.json'), { limits: Object.assign({}, cfg0.limits, { confirm_per_ip: 3 }) }));
    write('zahtjevi.json', { ['sve|mailto-' + ekHash('pun@primjer.hr')]: Array(5).fill(nowE) });
    await post('order', order('potvrda-pun-0002', 'pun@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const c3 = [];
    for (let i = 0; i < 3; i++) c3.push((await post('order', order('potvrda-nova-' + i + '-xx', 'nova' + i + '@primjer.hr', [{ id: 'klasik', qty: 1 }], 60))).status);
    ok(c3.every(c => c === 200), 'odbijena narudžba ne troši granicu po adresi: ' + c3.join(','));
    write('config.json', Object.assign({}, cfg0, { require_confirm: true, confirm_minutes: 60 }));
    // 7g) preopterećenje (200 e-mailova za potvrdu u satu): v68 — narudžba se odbija (iznimka „bez rezervacije” je ukinuta); vlasnica dobiva jedno upozorenje
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    write('zahtjevi.json', { 'sve|confirm': Array(200).fill(nowE) });
    const prodsO = JSON.parse(fs.readFileSync(D + 'proizvodi.json', 'utf8'));
    const mailBefore = mailLog().length;
    const free1 = ['nera', 'vela', 'luna', 'tara'].find(id => !(read('rezervacije.json', {}).res || {})[id] && !(read('rezervacije.json', {}).sold || {})[id]) || 'nera';
    const pr1 = prodsO.find(x => x.id === free1).price;
    const o1 = await post('order', order('potvrda-preopt-01', 'preopt1@primjer.hr', [{ id: free1 }], pr1));
    const o2 = await post('order', order('potvrda-preopt-02', 'preopt2@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const newMails = mailLog().slice(mailBefore);
    const nOwner = (newMails.match(/narudžbe bez potvrde e-mailom/g) || []).length;
    const ent = read('narudzbe.json', []).find(x => x.no === o1.data.orderNo) || {};
    const nOwner2 = (newMails.match(/PAŽNJA: previše narudžbi/g) || []).length;
    ok(o1.status === 503 && /previše narudžbi odjednom/.test(o1.data.error || '') && !o1.data.orderNo && !((read('rezervacije.json', {}).res || {})[free1]) && !ent.no, 'v68: preopterećenje — narudžba se ne prima (503 s porukom), ništa se ne rezervira ni ne zapisuje');
    ok(o2.status === 503 && !/To: preopt1@primjer\.hr/.test(newMails) && nOwner2 === 1, 'v68: preopterećenje — kupcima nema e-maila, vlasnica dobiva jedno upozorenje (' + nOwner2 + ')');
    // 8) granica e-mailova za potvrdu po adresi
    write('config.json', Object.assign({}, read('config.json'), { limits: Object.assign({}, cfg0.limits, { confirm_per_ip: 3 }) }));
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    const codes = [];
    for (let i = 0; i < 4; i++) codes.push((await post('order', order('potvrda-lim-' + i + '-xxxx', 'lim' + i + '@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20))).status);
    ok(codes.slice(0, 3).every(c => c === 200) && codes[3] === 429, 'najviše 3 e-maila za potvrdu na sat s iste adrese: ' + codes.join(','));
    // 8b) bez provjerene adrese stranice: 503 (potvrda ne otpada tiho)
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    const body = JSON.stringify(order('potvrda-host-0001', 'host@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const out503 = require('child_process').execFileSync('curl', ['-s', '-w', '\n%{http_code}', '-H', 'Host: zli.primjer.hr', '-X', 'POST', '--data-binary', body, U + 'order']).toString();
    ok(/\n503$/.test(out503) && /trenutno nedostupne/.test(out503) && /narudžbe ne rade/.test(mailLog()), 'nepoznat Host bez upisane adrese stranice → 503 i upozorenje vlasnici');
    // 9) prekidač: require_confirm=false vraća staro ponašanje
    write('config.json', Object.assign({}, cfg0, { require_confirm: false }));
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    const r9 = await post('order', order('potvrda-key-0009', 'staro@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20));
    ok(r9.data.ok && !r9.data.pending && /^SJ-/.test(r9.data.orderNo || ''), 'require_confirm=false: narudžba odmah zaprimljena (staro ponašanje)');
  } catch (e) { R.push('FAIL iznimka: ' + e.message); }
  finally { write('config.json', cfg0); }
  console.log(R.join('\n'));
  const f = R.filter(l => l.startsWith('FAIL')).length;
  console.log(f ? 'NEUSPJELO: ' + f : 'SVE PROŠLO (' + R.length + ')');
  process.exit(f ? 1 : 0);
})();
