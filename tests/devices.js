/* v105: cijeli put kupca na Androidu 12–15 (Chrome; pogon je današnji Chromium, a Chrome se na Androidu sam ažurira)
   i izgled sa zamjenama za Safari 14 (iOS 14.0–14.4: flex gap i aspect-ratio).
   Pokretanje (uz tests/serve.sh na 8090):  node tests/devices.js
   Na svakom profilu: ponuda → košarica → obrazac → „Naruči s obvezom plaćanja” → link iz e-maila → „Potvrdi narudžbu” → ploča za uplatu
   → „Uplatio/la sam” → izjava o raskidu. Provjerava se: nema vodoravnog pomaka, nema JS grešaka, glavni gumbi ≥ 44 px, polja i poruke na zaslonu.
   Ne može provjeriti: Safari na pravom iPhoneu, Samsung Internet, stari ugrađeni preglednik (WebView) — za to služi BrowserStack. */
const { chromium } = require('playwright');
const fs = require('fs');
const SITE = (process.env.SHIVAJ_SITE || '/tmp/shivaj-test') + '/';
const URL = process.env.SHIVAJ_URL || 'http://127.0.0.1:8090/';
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const chromeUA = (android, model) => `Mozilla/5.0 (Linux; Android ${android}; ${model}) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36`;
const PROFILES = [
  { name: 'Android 12 · Pixel 5', viewport: { width: 393, height: 851 }, dsf: 2.75, ua: chromeUA(12, 'Pixel 5') },
  { name: 'Android 12 · mali zaslon 360 × 640', viewport: { width: 360, height: 640 }, dsf: 2, ua: chromeUA(12, 'SM-A125F') },
  { name: 'Android 13 · Galaxy S22', viewport: { width: 360, height: 780 }, dsf: 3, ua: chromeUA(13, 'SM-S901B') },
  { name: 'Android 14 · Pixel 7', viewport: { width: 412, height: 915 }, dsf: 2.625, ua: chromeUA(14, 'Pixel 7') },
  { name: 'Android 15 · Pixel 9', viewport: { width: 412, height: 923 }, dsf: 2.625, ua: chromeUA(15, 'Pixel 9') },
  { name: 'Android 14 · položeno 915 × 412', viewport: { width: 915, height: 412 }, dsf: 2.625, ua: chromeUA(14, 'Pixel 7') },
  { name: 'Android 13 · veći tekst (130 %)', viewport: { width: 393, height: 851 }, dsf: 2.75, ua: chromeUA(13, 'Pixel 5'), bigText: true },
  { name: 'Safari 14 (zamjene) · 390 × 844', viewport: { width: 390, height: 844 }, dsf: 1, ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0.3 Mobile/15E148 Safari/604.1', safari14: true }
];
/* zamjene za Safari 14 koje novi preglednik inače ne bi pokazao: aspect-ratio ugašen, pa vrijede pravila iz @supports not (aspect-ratio) */
const SAFARI14_CSS = '.card .photo,.hero-bag .ring,.lb-photo,.about-photo img{aspect-ratio:auto!important} .card .photo{height:0;padding-top:125%} .card .photo > img{position:absolute;top:0;left:0} .hero-bag .ring{height:0;padding-top:100%} .hero-bag .ring img{position:absolute;top:0;left:0} @media (max-width:680px){ .lb-photo{height:calc((100vw - 40px) * 1.25)} }';

(async () => {
  const cfgPath = SITE + 'data/config.json', cfg0 = fs.readFileSync(cfgPath, 'utf8');
  fs.writeFileSync(cfgPath, JSON.stringify(Object.assign(JSON.parse(cfg0), { require_confirm: true })));
  /* početno stanje podataka (torbe, rezervacije, narudžbe) — svaki profil kreće od njega, pa svaki ima slobodnu torbu */
  const snap = {}; fs.readdirSync(SITE + 'data').filter(f => /\.(json|jsonl|txt)$/.test(f) && f !== 'config.json').forEach(f => { try { snap[f] = fs.readFileSync(SITE + 'data/' + f, 'utf8'); } catch (e) {} });
  const restore = () => { fs.readdirSync(SITE + 'data').filter(f => /\.(json|jsonl)$/.test(f) && f !== 'config.json' && !(f in snap)).forEach(f => { try { fs.unlinkSync(SITE + 'data/' + f); } catch (e) {} }); Object.entries(snap).forEach(([f, v]) => fs.writeFileSync(SITE + 'data/' + f, v)); };
  const browser = await chromium.launch();
  try {
    for (const [i, P] of PROFILES.entries()) {
      /* svaki profil počinje bez granica i bez otvorenih narudžbi iste veze (u testu su sve s 127.0.0.1) */
      try { fs.unlinkSync(SITE + 'data/zahtjevi.json'); } catch (e) {}
      restore();
      const cx = await browser.newContext({ viewport: P.viewport, deviceScaleFactor: P.dsf, isMobile: true, hasTouch: true, userAgent: P.ua, locale: 'hr-HR' });
      const pg = await cx.newPage(); const errs = []; pg.on('pageerror', e => errs.push(e.message));
      const prep = async () => { if (P.safari14) await pg.addStyleTag({ content: SAFARI14_CSS }); if (P.bigText) await pg.addStyleTag({ content: 'html{font-size:130%!important}' }); await pg.waitForTimeout(150); };
      const over = () => pg.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      const steps = []; const step = async (n, cond) => { steps.push([n, !!cond, await over()]); };
      const q = P.safari14 ? '?noflexgap=1' : '';
      await pg.goto(URL + q, { waitUntil: 'networkidle' }); await prep(); await pg.waitForTimeout(300);
      await step('naslovnica', true);
      /* v106 (pravi iPhone 12, iOS 14.1): ukras ::before uz „Jedan primjerak” bez flex gapa mora imati razmak do teksta */
      if (P.safari14) {
        const pg2 = await pg.evaluate(() => { const t = document.querySelector('.card .unikat-tag'); if (!t) return -1; const tn = [...t.childNodes].find(n => n.nodeType === 3 && n.textContent.trim());
          if (!tn) return -2; const r = document.createRange(); r.selectNodeContents(tn); const before = parseFloat(getComputedStyle(t, '::before').width) || 0;
          return Math.round(r.getBoundingClientRect().left - t.getBoundingClientRect().left - before); });
        ok(pg2 >= 7, `${P.name}: razmak između crtica i teksta „Jedan primjerak” bez flex gapa ${pg2} px (treba ≈ 8)`);
      }
      /* torba iz ponude (dodirom) i artikl iz rubrike Ostalo */
      const tapped = await pg.locator('.card .add-btn:not([disabled])').first().tap({ timeout: 5000 }).then(() => true).catch(() => false);
      await pg.evaluate(() => { const k = PRODUCTS.find(x => x.id === 'kozmeticka'); if (k && !cart.some(c => c.id === k.id)) { cart.push({ ...k, qty: 1, note: '' }); saveCart(); renderCart(); } });
      await pg.tap('#openCart'); await pg.waitForTimeout(400);
      const cartN = await pg.evaluate(() => cart.length);
      await step('košarica', cartN >= 1);
      await pg.check('#shipOptions input[value="pickup"]');
      await pg.fill('#fName', 'Android Kupac'); await pg.fill('#fEmail', 'android' + i + '@primjer.hr'); await pg.fill('#fPhone', '091 555 01' + String(i).padStart(2, '0'));
      await pg.locator('#fConsent').check();
      const btnH = await pg.evaluate(() => Math.round(document.getElementById('orderBtn').getBoundingClientRect().height));
      await pg.tap('#orderBtn');
      const pending = await pg.waitForFunction(() => document.getElementById('orderForm').classList.contains('is-pending'), null, { timeout: 20000 }).then(() => true).catch(() => false);
      await step('narudžba poslana', pending);
      const m = [...(fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8').matchAll(/\?potvrda=([a-f0-9]{32}\.[a-f0-9]{32})/g))].pop();
      await pg.goto(URL + '?potvrda=' + (m ? m[1] : 'x') + (P.safari14 ? '&noflexgap=1' : ''), { waitUntil: 'networkidle' }); await prep();
      const cb = await pg.waitForSelector('#confirmBtn', { timeout: 8000 }).then(() => true).catch(() => false);
      const cbH = cb ? await pg.evaluate(() => Math.round(document.getElementById('confirmBtn').getBoundingClientRect().height)) : 0;
      if (cb) await pg.tap('#confirmBtn');
      const pay = await pg.waitForSelector('.pay-steps', { timeout: 10000 }).then(() => true).catch(() => false);
      const payInfo = await pg.evaluate(() => { const p = document.querySelector('.pay'); return p ? { iban: !!p.querySelector('.iban'), claim: (p.querySelector('.claim-link') || {}).href || '', raskid: (storeGet('last') || { srv: {} }).srv.raskidLink || '' } : null; });
      await step('ploča za uplatu', pay && payInfo && payInfo.iban);
      /* „Uplatio/la sam” */
      let claimed = false;
      if (payInfo && payInfo.claim) { await pg.goto(payInfo.claim, { waitUntil: 'networkidle' }); await prep();
        const cbt = await pg.waitForSelector('#claimBtn', { timeout: 8000 }).then(() => true).catch(() => false);
        if (cbt) { await pg.tap('#claimBtn'); await pg.waitForTimeout(900); claimed = /Hvala|zabilježili|javili/i.test(await pg.textContent('#claimBox').catch(() => '')); } }
      await step('Uplatio/la sam', claimed);
      /* izjava o raskidu preko poveznice s ploče */
      let wd = false;
      if (payInfo && payInfo.raskid) { await pg.goto(URL + (P.safari14 ? '?noflexgap=1' : '') + payInfo.raskid, { waitUntil: 'networkidle' }); await prep(); await pg.waitForTimeout(400);
        await pg.fill('#rName', 'Android Kupac'); await pg.fill('#rEmail', 'android' + i + '@primjer.hr'); await pg.tap('#raskidNext'); await pg.waitForTimeout(300);
        await pg.tap('#raskidConfirm').catch(() => {}); wd = await pg.waitForFunction(() => !document.getElementById('raskidDone').hidden, null, { timeout: 8000 }).then(() => true).catch(() => false); }
      await step('izjava o raskidu', wd);
      const bad = steps.filter(([, c, o]) => !c || o > 0);
      ok(tapped && bad.length === 0 && errs.length === 0 && btnH >= 44 && cbH >= 44,
        `${P.name}: cijeli put kupca bez vodoravnog pomaka i JS grešaka; gumbi ${btnH}/${cbH} px ` + JSON.stringify({ bad, errs: errs.slice(0, 3), tapped }));
      await cx.close();
    }
  } finally { fs.writeFileSync(cfgPath, cfg0); restore(); await browser.close(); }
  console.log(R.join('\n'));
  const fails = R.filter(l => l.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + R.length + ')');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.log(R.join('\n')); console.error('SCRIPT ERROR', e); process.exit(1); });
