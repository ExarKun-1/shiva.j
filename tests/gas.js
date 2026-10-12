/* Probni poslužitelj (proba/apps-script/Code.gs) u Nodeu, s lažnom tablicom i poštom:  node tests/gas.js */
const fs = require('fs'), vm = require('vm'), crypto = require('crypto'), path = require('path');
const code = fs.readFileSync(path.join(__dirname, '..', 'proba', 'apps-script', 'Code.gs'), 'utf8');
const ctx = {}; vm.createContext(ctx); vm.runInContext(code, ctx);
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const products = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'torbe.json'), 'utf8'));
const list = Array.isArray(products) ? products : products.products;

function makeEnv() {
  let t = Date.parse('2026-10-12T10:00:00Z'), seq = 0, rate = {};
  const env = {
    rows: [], pend: [], mails: [],
    cfg: { SITE_URL: 'https://primjer.github.io/shiva.j', OWNER_EMAIL: 'vlasnica@primjer.hr', CONFIRM_MINUTES: 60, TTL_HOURS: 24 },
    now: () => t, tick: ms => { t += ms; },
    token: () => crypto.randomBytes(16).toString('hex'),
    sha256: s => crypto.createHash('sha256').update(s).digest('hex'),
    products: () => list,
    orders: () => env.rows.map(r => ({ no: r.no, email: r.email, until: r.until, paid: !!r.paid, key: r.key, data: JSON.parse(JSON.stringify(r.data)) })),
    appendOrder: o => env.rows.push(JSON.parse(JSON.stringify(o))),
    pending: () => JSON.parse(JSON.stringify(env.pend)),
    savePending: l => { env.pend = JSON.parse(JSON.stringify(l)); },
    nextNo: () => ++seq,
    rate: (k, max) => { rate[k] = (rate[k] || 0) + 1; return rate[k] <= max; },
    mail: (to, subject, body) => { env.mails.push({ to, subject, body }); return true; }
  };
  return env;
}
const pickup = { id: 'pickup' };
const order = (key, email, items, total) => ({ _gotcha: '', Napomena: '', _order: { key, customer: { name: 'Gas Test', email }, items, shipping: pickup, total } });
const link = env => { const m = [...env.mails.map(x => x.body).join('\n').matchAll(/\?potvrda=([a-f0-9]{32})\.([a-f0-9]{32})/g)].pop(); return m && { id: m[1], t: m[2] }; };
const H = (env, r, b) => JSON.parse(JSON.stringify(ctx.sjHandle(env, r, b)));

let env = makeEnv();
const s0 = H(env, 'status');
ok(s0.ok && Array.isArray(s0.shipping) && s0.payment.iban && s0.ids.length === 0, 'status: dostava, uplata, prazne rezervacije');
const o1 = H(env, 'order', order('gas-key-00001', 'ana@primjer.hr', [{ id: 'luna' }], 65));
ok(o1.ok && o1.pending && o1._status === 200 && env.rows.length === 0 && env.mails.length === 1 && /potvrdite narudžbu/.test(env.mails[0].subject), 'narudžba čeka potvrdu, e-mail kupcu s linkom, bez upisa');
ok(env.mails[0].body.includes('https://primjer.github.io/shiva.j/?potvrda='), 'link vodi na probnu stranicu');
const bad = H(env, 'order', order('gas-key-00002', 'ana@primjer.hr', [{ id: 'luna' }], 1));
ok(bad._status === 409 && /Cijene/.test(bad.error), 'kriva cijena iz preglednika → 409');
const L = link(env);
ok(H(env, 'confirm', { id: L.id, t: 'f'.repeat(32) })._status === 403, 'kriva tajna → 403');
const c1 = H(env, 'confirm', L);
ok(c1.ok && c1.orderNo === 'SJ-2026-0001' && c1.until && env.rows.length === 1 && env.mails.some(m => m.to === 'vlasnica@primjer.hr' && /Narudžba SJ-2026-0001/.test(m.subject)), 'potvrda: broj, rezervacija, e-mail vlasnici');
ok(c1.items[0].id === 'luna' && c1.ship.id === 'pickup' && env.mails.some(m => m.to === 'ana@primjer.hr' && /PODACI ZA UPLATU/.test(m.body)), 'odgovor sa stavkama; kupac dobiva podatke za uplatu');
ok(H(env, 'status').ids.includes('luna'), 'status: Luna rezervirana');
const c2 = H(env, 'confirm', L);
ok(c2.repeated && c2.orderNo === c1.orderNo && env.rows.length === 1, 'ponovni klik: ista narudžba');
const o2 = H(env, 'order', order('gas-key-00003', 'drugi@primjer.hr', [{ id: 'luna' }], 65));
ok(o2._status === 409 && JSON.stringify(o2.taken) === '["luna"]', 'drugi kupac: Luna zauzeta (409)');
const o3 = H(env, 'order', order('gas-key-00004', 'a.n.a+x@primjer.hr', [{ id: 'vela' }], 70));
ok(o3.ok && o3.pending, 'drugačiji e-mail (nije gmail): nova narudžba čeka potvrdu');
// lažne narudžbe bez potvrde ne drže ništa
for (let i = 0; i < 5; i++) H(env, 'order', order('gas-lazna-' + i + '-xx', 'lazni' + i + '@primjer.hr', [{ id: 'vela' }], 70));
ok(!H(env, 'status').ids.includes('vela'), 'nepotvrđene narudžbe ne rezerviraju');
// istek linka
const o4 = H(env, 'order', order('gas-key-00005', 'kasni@primjer.hr', [{ id: 'tara' }], 55)); const L4 = link(env);
env.tick(61 * 60000);
const c4 = H(env, 'confirm', L4);
ok(o4.pending && c4._status === 410 && c4.expired, 'istekao link → 410');
// plaćeno u tablici → prodano
env.rows[0].paid = '12.10.2026.';
const s2 = H(env, 'status');
ok(s2.sold.includes('luna') && !s2.ids.includes('luna'), 'upisan datum u „Plaćeno” → torba prodana');
// granica pola ponude: slobodni unikati iz ispitnog popisa
env = makeEnv();
const free = list.filter(p => !p.made && !p.sold && !p.reserved && !p.hidden).map(p => String(p.id));
const cap = free.length >= 2 ? Math.max(1, Math.floor(free.length / 2)) : 0;
const results = [];
free.forEach((id, i) => { const p = list.find(x => String(x.id) === id); H(env, 'order', order('gas-cap-' + i + '-xxxx', 'kup' + i + '@primjer.hr', [{ id }], p.price)); results.push(H(env, 'confirm', link(env))); });
const heldN = results.filter(r => r.ok && r.reserved).length, unheldN = results.filter(r => r.ok && r.unheld).length;
ok(heldN === cap && unheldN === free.length - cap && results.every(r => r.ok), `granica: od ${free.length} slobodnih rezervirano ${heldN} (pola), ostalo bez rezervacije`);
// granica e-mailova za potvrdu (30 na sat ukupno)
env = makeEnv(); let last;
for (let i = 0; i < 31; i++) last = H(env, 'order', order('gas-lim-' + i + '-xxxx', 'l' + i + '@primjer.hr', [{ id: 'kozmeticka', qty: 1 }], list.find(p => p.id === 'kozmeticka').price));
ok(last._status === 429, '31. e-mail za potvrdu u satu → 429');

console.log(R.join('\n'));
const f = R.filter(l => l.startsWith('FAIL')).length;
console.log(f ? 'NEUSPJELO: ' + f : 'SVE PROŠLO (' + R.length + ')');
process.exit(f ? 1 : 0);
