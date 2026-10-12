/* Probna stranica (GitHub Pages + Apps Script) u pregledniku, s lažnim Apps Scriptom u Nodeu:  node tests/proba.js
   Složi stranicu s proba/build.py, posluži je na 8092, a Code.gs (sjHandle, podaci u memoriji) na 8093/…/exec.
   Kao pravi Apps Script, poslužitelj uvijek vraća HTTP 200, a status nosi u polju _status. */
const { chromium } = require('playwright');
const fs = require('fs'), vm = require('vm'), http = require('http'), path = require('path'), crypto = require('crypto'), cp = require('child_process');
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const ROOT = path.join(__dirname, '..'), OUT = '/tmp/shivaj-proba';
const SITE = 'http://127.0.0.1:8092', API = 'http://127.0.0.1:8093/macros/s/TEST/exec';
cp.execFileSync('python3', [path.join(ROOT, 'proba', 'build.py'), OUT], { env: Object.assign({}, process.env, { PROBA_API_URL: 'https://script.google.com/macros/s/TEST/exec' }) });
/* u ispitu stranica zove lokalni lažni Apps Script umjesto Googlea */
fs.writeFileSync(OUT + '/index.html', fs.readFileSync(OUT + '/index.html', 'utf8').replace('https://script.google.com/macros/s/TEST/exec', API));

const ctx = {}; vm.createContext(ctx); vm.runInContext(fs.readFileSync(path.join(ROOT, 'proba', 'apps-script', 'Code.gs'), 'utf8'), ctx);
const products = JSON.parse(fs.readFileSync(OUT + '/torbe.json', 'utf8'));
let seq = 0; const rows = [], mails = []; let pend = [];
const env = { cfg: { SITE_URL: SITE, OWNER_EMAIL: 'vlasnica@primjer.hr', CONFIRM_MINUTES: 60, TTL_HOURS: 24 }, now: () => Date.now(),
  token: () => crypto.randomBytes(16).toString('hex'), sha256: s => crypto.createHash('sha256').update(s).digest('hex'), products: () => products,
  orders: () => rows.map(r => JSON.parse(JSON.stringify({ no: r.no, email: r.email, until: r.until, paid: !!r.paid, key: r.key, data: r.data }))),
  appendOrder: o => rows.push(JSON.parse(JSON.stringify(o))), pending: () => JSON.parse(JSON.stringify(pend)), savePending: l => { pend = JSON.parse(JSON.stringify(l)); },
  nextNo: () => ++seq, rate: () => true, mail: (to, subject, body) => { mails.push({ to, subject, body }); return true; } };
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg' };
const posts = [];
const site = http.createServer((q, s) => { const f = path.join(OUT, decodeURIComponent(new URL(q.url, SITE).pathname).replace(/\/$/, '/index.html')); fs.readFile(f, (e, b) => { if (e) { s.writeHead(404); s.end(); return; } s.writeHead(200, { 'Content-Type': types[path.extname(f)] || 'application/octet-stream' }); s.end(b); }); }).listen(8092);
const api = http.createServer((q, s) => {
  let body = ''; q.on('data', c => body += c); q.on('end', () => {
    const u = new URL(q.url, API);
    if (q.method === 'OPTIONS') { s.writeHead(405); s.end(); return; } /* Apps Script ne odgovara na CORS predzahtjev */
    if (q.method === 'POST' && body) posts.push(q.headers['content-type'] || ''); /* brojač (sendBeacon) šalje prazno tijelo */
    let b = null; try { b = JSON.parse(body || 'null'); } catch (e) {}
    const out = ctx.sjHandle(env, u.searchParams.get('r') || 'status', b);
    s.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' }); s.end(JSON.stringify(out));
  });
}).listen(8093);

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium' });
  const errors = [];
  try {
    const m = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const p = await m.newPage();
    p.on('pageerror', e => errors.push(e.message)); p.on('console', x => { if (x.type() === 'error' && !/bunny|ERR_TUNNEL|Failed to load resource/.test(x.text())) errors.push(x.text()); });
    await p.goto(SITE + '/', { waitUntil: 'networkidle' }); await p.waitForTimeout(300);
    ok(/PROBNA STRANICA/.test(await p.textContent('body')) && await p.locator('meta[name=robots][content*=noindex]').count() === 1, 'traka „PROBNA STRANICA” i noindex');
    await p.click('.card:has(h3:text-is("Luna")) .add-btn'); await p.click('#openCart'); await p.waitForTimeout(300);
    await p.check('#shipOptions input[value="pickup"]');
    await p.fill('#fName', 'Proba Kupac'); await p.fill('#fEmail', 'proba@primjer.hr'); await p.fill('#fPhone', '091 111 2222'); await p.check('#fConsent');
    await p.click('#orderBtn'); await p.waitForSelector('.order-error.order-info', { timeout: 8000 }).catch(() => {});
    ok(/potvrdite narudžbu/.test(await p.textContent('#orderError').catch(() => '')) && mails.length === 1, 'narudžba preko Apps Scripta: „potvrdite narudžbu”, e-mail s linkom');
    ok(posts.length && posts.every(t => /^text\/plain/.test(t)), 'POST kao text/plain (bez CORS predzahtjeva): ' + [...new Set(posts)].join(','));
    const mm = /\?potvrda=([a-f0-9.]+)/.exec(mails[0].body);
    await p.goto(SITE + '/?potvrda=' + (mm && mm[1]), { waitUntil: 'networkidle' }); await p.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
    const pay = await p.textContent('.pay').catch(() => '');
    ok(/SJ-2026-0001|SJ-\d{4}-0001/.test(pay) && /Torba „Luna” je rezervirana/.test(pay) && rows.length === 1, 'link: podaci za uplatu, Luna rezervirana, red u tablici');
    const p2 = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    await p2.goto(SITE + '/', { waitUntil: 'networkidle' }); await p2.waitForTimeout(500);
    ok(/Rezervirano/i.test(await p2.textContent('.card:has(h3:text-is("Luna"))')), 'drugi posjetitelj vidi Lunu kao rezerviranu');
    await p2.click('.card:has(h3:text-is("Tara")) .add-btn'); await p2.click('#openCart'); await p2.waitForTimeout(300);
    await p2.check('#shipOptions input[value="pickup"]');
    await p2.fill('#fName', 'Proba Dva'); await p2.fill('#fEmail', 'proba@primjer.hr'); await p2.fill('#fPhone', '091 111 3333'); await p2.check('#fConsent');
    await p2.click('#orderBtn'); await p2.waitForTimeout(1200);
    ok(/Već imate narudžbu/.test(await p2.textContent('#orderError').catch(() => '')) && !(await p2.locator('.order-error.order-info').count()), '409 iz tijela odgovora (_status): „Već imate narudžbu…” kao greška');
  } catch (e) { R.push('FAIL iznimka: ' + e.message); }
  ok(errors.length === 0, 'bez JS grešaka' + (errors.length ? ': ' + errors.join(' ; ') : ''));
  await browser.close(); site.close(); api.close();
  console.log(R.join('\n'));
  const f = R.filter(l => l.startsWith('FAIL')).length;
  console.log(f ? 'NEUSPJELO: ' + f : 'SVE PROŠLO (' + R.length + ')');
  process.exit(f ? 1 : 0);
})();
