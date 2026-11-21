/* Potvrda narudžbe e-mailom (v40):  sh tests/serve.sh &  pa  node tests/confirm.js
   Uključi require_confirm u probnoj stranici, provjeri tok narudžba → e-mail s linkom → potvrda, pa vrati postavke. */
const fs = require('fs');
const SITE = (process.env.SHIVAJ_SITE || '/tmp/shivaj-test') + '/';
const D = SITE + 'data/';
const U = (process.env.SHIVAJ_URL || 'http://127.0.0.1:8090/') + 'api/';
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
/* v78: Stalna kolekcija traži boju i duljinu remena iz ponude (i na poslužitelju) — narudžbe bez napomene ih dobivaju */
const strapFill = body => { const it = body && body._order && body._order.items; if (Array.isArray(it)) it.forEach(x => { if (x && /^(klasik|kol)\d*$/.test(x.id) && x.note === undefined) x.note = 'remen: crna, 140 cm'; }); return body; };
const post = async (a, body, type) => { const r = await fetch(U + a, { method: 'POST', headers: { 'Content-Type': type || 'application/json' }, body: JSON.stringify(strapFill(body)) }); return { status: r.status, data: await r.json().catch(() => ({})) }; };
const cust = email => ({ name: 'Potvrda Test', email, phone: '', street: '', city: '' });
const pickup = { id: 'pickup', label: 'Osobno preuzimanje u radionici', price: 0, locker: '' };
const order = (key, email, items, total, series) => ({ _gotcha: '', Napomena: '', _order: Object.assign({ key, customer: cust(email), items, shipping: pickup, goods: total, total }, series ? { series } : {}) });
const SER = 'serija-preglednika-0001'; /* v96: stalna oznaka preglednika (ispravak zamjenjuje raniji link samo iz istog preglednika) */
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
    ok(c1.data.ok && /^SJ-\d{4}-\d{4,6}$/.test(c1.data.orderNo || '') && res.luna && res.luna.ordered === true, 'potvrda → broj narudžbe ' + c1.data.orderNo + ' i rezervacija');
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
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    for (let i = 0; i < 5; i++) await post('order', order('potvrda-lazna-' + i + '-xx', 'lazni' + i + '@primjer.hr', [{ id: 'vela' }], 70));
    const st2 = await (await fetch(U + 'status')).json();
    /* v99 (recenzija vijeća v98, N5): iz iste mreže /24 najviše 5 e-mailova za potvrdu na sat i ispod meke granice */
    const net6 = await post('order', order('potvrda-lazna-5-xx', 'lazni5@primjer.hr', [{ id: 'vela' }], 70));
    ok(net6.status === 503, 'v99: šesti e-mail za potvrdu iz iste mreže /24 u satu → 503 (' + net6.status + ')');
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    const real = await post('order', order('potvrda-key-0004', 'pravi@primjer.hr', [{ id: 'vela' }], 70));
    const Lr = lastLink(); const cr = await post('confirm', { id: Lr.id, t: Lr.t });
    ok(!(st2.ids || []).includes('vela') && cr.data.ok && (read('rezervacije.json', {}).res || {}).vela?.email === 'pravi@primjer.hr', 'nepotvrđene lažne narudžbe ne drže torbu; pravi kupac je potvrdi i dobiva');
    // 7) v41: tuđi e-mail ne briše pravu nepotvrđenu narudžbu; po adresi najviše 3 nepotvrđene
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    await post('order', order('potvrda-zrtva-0001', 'zrtva@primjer.hr', [{ id: 'tara' }], 55));
    const Lz = lastLink();
    /* v95: ispravak s iste veze zamjenjuje raniji link — napadač je na drugoj vezi (testni poslužitelj vidi samo 127.0.0.1) */
    { const pz = read('narudzbe-na-cekanju.json', []); pz.forEach(x => { if (x.id === Lz.id && x.P) { x.P.ip = 'zrtvinaVeza'; x.P.ipPrev = ''; } }); write('narudzbe-na-cekanju.json', pz); }
    await post('order', order('potvrda-napad-0001', 'zrtva@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const cz = await post('confirm', { id: Lz.id, t: Lz.t });
    ok(cz.data.ok && /^SJ-/.test(cz.data.orderNo || ''), 'narudžba s tuđim e-mailom ne poništava žrtvin link (potvrda radi)');
    const many = read('narudzbe-na-cekanju.json', []); for (let i = 0; i < 3; i++) many.unshift({ id: 'f'.repeat(31) + i, h: 'x', key: 'stari-' + i, at: '', until: new Date(Date.now() + 3e6).toISOString(), ek: 'puno@primjer.hr', P: {} }); write('narudzbe-na-cekanju.json', many);
    await post('order', order('potvrda-puno-0001', 'puno@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const pu = read('narudzbe-na-cekanju.json', []).filter(x => !x.no && x.ek === 'puno@primjer.hr');
    ok(pu.length === 3 && pu[0].key === 'potvrda-puno-0001' && !pu.some(x => x.key === 'stari-0'), 'po adresi najviše 3 nepotvrđene, najstarija ispada');
    // 7b) najviše 3 e-maila za potvrdu na sat na istu adresu (v95: 2 → 3, narudžba i dva ispravka)
    const r3x = await post('order', order('potvrda-puno-0002', 'puno@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const r4x = await post('order', order('potvrda-puno-0003', 'puno@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    const r5x = await post('order', order('potvrda-puno-0004', 'puno@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(r3x.status === 200 && r4x.status === 200 && r5x.status === 429 && /već smo poslali/.test(r5x.data.error || ''), 'v95: četvrti e-mail za potvrdu na istu adresu u satu → 429 (treći prolazi: drugi ispravak)');
    // 7c) 40 e-mailova (v90: granica 50/h, upozorenje od 30) za potvrdu u satu (s mnogo adresa): narudžba se i dalje prima, vlasnica je upozorena
    const nowS = Math.floor(Date.now() / 1000);
    write('zahtjevi.json', { 'sve|confirm': Array(40).fill(nowS), 'sve|confirm_warn': Array(40).fill(nowS) });
    const r100 = await post('order', order('potvrda-mnogo-0001', 'mnogo@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(r100.status === 200 && r100.data.pending && /mnogo narudžbi/.test(mailLog()), 'nakon 40 e-mailova za potvrdu u satu (v90, granica 50): narudžba primljena, vlasnica upozorena');
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
    write('zahtjevi.json', { 'sve|confirm': Array(100).fill(nowE) }); /* v99: između meke (50) i tvrde granice (150) */
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
    /* v95 (recenzija vijeća v94, sigurnost 1): iznad 50 na sat nova veza i dalje naručuje (uz teži zadatak), veza koja je
       ovaj sat već dobila e-mail za potvrdu dobiva 503; iznad tvrde granice (v99: 150) svi dobivaju 503 */
    ok(o1.status === 200 && o1.data.pending && o2.status === 503 && /previše narudžbi odjednom/.test(o2.data.error || '') && !((read('rezervacije.json', {}).res || {})[free1]) && !ent.no,
      'v95: preopterećenje — nova veza i dalje naručuje (200, čeka potvrdu), ista veza drugi put 503; ništa se ne rezervira ' + o1.status + '/' + o2.status);
    ok(!/To: preopt2@primjer\.hr/.test(newMails) && nOwner2 === 1, 'v68/v95: preopterećenje — odbijenoj vezi nema e-maila, vlasnica dobiva jedno upozorenje (' + nOwner2 + ')');
    write('zahtjevi.json', { 'sve|confirm': Array(300).fill(nowE) });
    const o3 = await post('order', order('potvrda-preopt-03', 'preopt3@primjer.hr', [{ id: 'klasik', qty: 1 }], 60));
    ok(o3.status === 503, 'v95: tvrda granica (300 e-mailova za potvrdu na sat) — nova narudžba 503 i s nove veze');
    // 7h) v94: ispravljena narudžba (novi ključ, isti e-mail, iste stavke, ista veza) zamjenjuje stari link
    { try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {} write('rezervacije.json', { res: {}, sold: {} }); write('narudzbe.json', []);
      const a1 = await post('order', order('zamjena-key-0001', 'ispravak@primjer.hr', [{ id: 'luna' }], 65, SER)); const LA = lastLink();
      const a2 = await post('order', order('zamjena-key-0002', 'ispravak@primjer.hr', [{ id: 'luna' }], 65, SER)); const LB = lastLink();
      const ca = await post('confirm', { id: LA.id, t: LA.t }); const cb = await post('confirm', { id: LB.id, t: LB.t });
      ok(a1.data.pending && a2.data.pending && LA.id !== LB.id && ca.status === 410 && ca.data.replaced && /zamijenjen novijim/.test(ca.data.error || '') && cb.data.ok && cb.data.orderNo,
        'v94 (recenzija vijeća): ispravljena narudžba poništava raniji link (410 „zamijenjen novijim”), novi link potvrđuje ' + JSON.stringify([ca.status, cb.status])); }
    // 7i) v95 (recenzija vijeća v94, kupac 1): ispravak s DRUGIM stavkama (dodan artikl) također poništava raniji link; e-mail kaže „novi link”
    { try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {} write('rezervacije.json', { res: {}, sold: {} }); write('narudzbe.json', []);
      const b1 = await post('order', order('zamjena-key-0101', 'ispravak2@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20, SER)); const L1 = lastLink();
      const b2 = await post('order', order('zamjena-key-0102', 'ispravak2@primjer.hr', [{ id: 'kozmeticka', qty: 1 }, { id: 'klasik', qty: 1 }], 80, SER)); const L2 = lastLink();
      const ml = mailLog(); const lastM = ml.split(/^=== /m).filter(x => /To: ispravak2@/.test(x)).pop() || '';
      const c1 = await post('confirm', { id: L1.id, t: L1.t }); const c2 = await post('confirm', { id: L2.id, t: L2.t });
      ok(b1.data.pending && b2.data.pending && b2.data.replaced === true && /potvrdite narudžbu \(novi link\)/.test(lastM) && /NOVI link za potvrdu narudžbe\. Link iz ranijeg e-maila više ne vrijedi/.test(lastM)
        && c1.status === 410 && c1.data.replaced && c2.data.ok && read('narudzbe.json', []).length === 1,
        'v95: ispravak s drugim artiklima poništava raniji link (samo jedna narudžba), e-mail „(novi link)” ' + JSON.stringify([c1.status, c2.status, b2.data.replaced])); }
    // 7k) v96 (recenzija vijeća v95, sigurnost N6): netko na ISTOJ vezi (CGNAT) s tuđim e-mailom, ali iz drugog preglednika, ne zamjenjuje pravi link
    { try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {} write('rezervacije.json', { res: {}, sold: {} }); write('narudzbe.json', []);
      await post('order', order('cgnat-key-0001', 'cgnat@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20, 'serija-zrtve-0000000001')); const LV = lastLink();
      const at = await post('order', order('cgnat-key-0002', 'cgnat@primjer.hr', [{ id: 'kozmeticka', qty: 2 }], 40, 'serija-napadaca-000000001'));
      const cv = await post('confirm', { id: LV.id, t: LV.t });
      ok(at.data.pending && at.data.replaced === false && cv.data.ok && /^SJ-/.test(cv.data.orderNo || ''), 'v96: s iste veze, drugi preglednik — pravi link i dalje potvrđuje (bez otmice) ' + JSON.stringify([at.data.replaced, cv.status]));
      const pnd = read('narudzbe-na-cekanju.json', []).find(x => x.id === LV.id) || {};
      const st = await (await fetch(U + 'status?pt=' + (pnd.pt || ''))).json();
      ok(/^[0-9a-f]{24}$/.test(pnd.pt || '') && st.pendState === 'confirmed' && st.pendNo === cv.data.orderNo, 'v96: api/status?pt= javlja da je narudžba potvrđena (drugi uređaj) ' + JSON.stringify([st.pendState, st.pendNo])); }
    // 7l) v98 (recenzija vijeća v97, sigurnost M3/M4): tri narudžbe s tuđim e-mailom iz drugog preglednika ne istiskuju pravi link;
    //     otvorena neplaćena narudžba javlja se odmah samo istom pregledniku, drugom odgovor je kao za svaku narudžbu, a vlasniku adrese e-mail
    { try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {} write('rezervacije.json', { res: {}, sold: {} }); write('narudzbe.json', []);
      try { fs.unlinkSync(D + 'narudzbe-na-cekanju.json'); } catch (e) {}
      await post('order', order('evict-key-0001', 'evict@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20, 'serija-zrtve-00000000002')); const LV = lastLink();
      for (let i = 2; i <= 4; i++) { try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {} await post('order', order('evict-key-000' + i, 'evict@primjer.hr', [{ id: 'kozmeticka', qty: i }], 20 * i, 'serija-napadaca-0000000' + i)); }
      const cv = await post('confirm', { id: LV.id, t: LV.t });
      ok(cv.data.ok && /^SJ-/.test(cv.data.orderNo || ''), 'v98: tri tuđe nepotvrđene narudžbe ne istiskuju pravi link ' + cv.status);
      try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
      await post('order', order('open-key-0001', 'openo@primjer.hr', [{ id: 'luna' }], 65, 'serija-kupca-0000000003')); const LO = lastLink(); const co = await post('confirm', { id: LO.id, t: LO.t });
      try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
      const same = await post('order', order('open-key-0002', 'openo@primjer.hr', [{ id: 'vela' }], 70, 'serija-kupca-0000000003'));
      try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
      const m0 = mailLog().length;
      const other = await post('order', order('open-key-0003', 'openo@primjer.hr', [{ id: 'vela' }], 70, 'serija-druga-00000000003'));
      const mm = mailLog().slice(m0);
      const stO = await (await fetch(U + 'status?pt=' + (other.data.pt || ''))).json();
      ok(/^[0-9a-f]{24}$/.test(other.data.pt || '') && stO.pendState === 'waiting', 'recenzija v98: odgovor za otvorenu narudžbu iz drugog preglednika i api/status?pt= kao za pravu nepotvrđenu (čeka) ' + JSON.stringify(stO.pendState));
      ok(co.data.ok && same.status === 409 && same.data.code === 'open_order' && other.status === 200 && other.data.pending && /nova narudžba nije zaprimljena/.test(mm) && /Prethodna narudžba s unikatnom torbom čeka vašu uplatu/.test(mm) && !/potvrda=/.test(mm),
        'v98: otvorena narudžba — isti preglednik 409, drugi preglednik 200 (bez otkrivanja), vlasnik adrese dobiva objašnjenje e-mailom ' + JSON.stringify([same.status, other.status])); }
    // 7j) v95 (recenzija vijeća v94, sigurnost 3): e-mail „potvrdite narudžbu” bez teksta koji je kupac upisao (paketomat, boja i duljina);
    //     nakon potvrde ploča dobiva adresu za dostavu (kupac 3)
    { try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {} write('rezervacije.json', { res: {}, sold: {} }); write('narudzbe.json', []);
      const b = order('lockmail-key-0001', 'lockmail@primjer.hr', [{ id: 'kozmeticka', qty: 1, note: 'crna Posebna Napomena' }], 24);
      b._order.shipping = { id: 'boxnow', label: 'BOX NOW paketomat', price: 4, locker: 'BOX NOW Konzum Zabok (ID 4321)' };
      const r = await post('order', b); const L = lastLink();
      const m = mailLog().split(/^=== /m).filter(x => /To: lockmail@/.test(x)).pop() || '';
      ok(r.data.pending && /BOX NOW paketomat — 4,00 €/.test(m) && !/Konzum Zabok|ID 4321|Posebna Napomena/.test(m) && /vidjet ćete na stranici nakon potvrde/.test(m), 'v95: e-mail za potvrdu ne ponavlja paketomat ni boju (lažne poruke na tuđu adresu)');
      const g = order('gls-key-0001', 'glsaddr@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 26);
      g._order.customer.street = 'Ilica 10'; g._order.customer.city = '10000 Zagreb'; g._order.shipping = { id: 'gls', label: 'GLS', price: 6, locker: '' };
      const rg = await post('order', g); const LG = lastLink(); const cg = await post('confirm', { id: LG.id, t: LG.t });
      ok(rg.data.pending && cg.data.ok && cg.data.ship && cg.data.ship.address === 'Ilica 10, 10000 Zagreb' && /^\?uplata=SJ-\d{4}-\d+&t=[0-9a-f]{32}$/.test(cg.data.claimLink || ''),
        'v95: potvrda vraća adresu za dostavu (ploča za uplatu) i poveznicu „Uplatio/la sam” ' + JSON.stringify([cg.data.ship && cg.data.ship.address, cg.data.claimLink])); }
    // 8) granica e-mailova za potvrdu po adresi
    write('config.json', Object.assign({}, read('config.json'), { limits: Object.assign({}, cfg0.limits, { confirm_per_ip: 3 }) }));
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    const codes = [];
    for (let i = 0; i < 4; i++) codes.push((await post('order', order('potvrda-lim-' + i + '-xxxx', 'lim' + i + '@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], 20))).status);
    ok(codes.slice(0, 3).every(c => c === 200) && codes[3] === 429, 'najviše 3 e-maila za potvrdu na sat s iste adrese: ' + codes.join(','));
    // 8b) bez provjerene adrese stranice: 503 (potvrda ne otpada tiho)
    try { fs.unlinkSync(D + 'zahtjevi.json'); } catch (e) {}
    const body = JSON.stringify(strapFill(order('potvrda-host-0001', 'host@primjer.hr', [{ id: 'klasik', qty: 1 }], 60)));
    const out503 = require('child_process').execFileSync('curl', ['-s', '-w', '\n%{http_code}', '-H', 'Host: zli.primjer.hr', '-X', 'POST', '--data-binary', body, U + 'order']).toString();
    ok(/\n503$/.test(out503) && /trenutačno nedostupne/.test(out503) && /narudžbe ne rade/.test(mailLog()), 'nepoznat Host bez upisane adrese stranice → 503 i upozorenje vlasnici');
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
