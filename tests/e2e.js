const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
/* Testovi u pregledniku (Playwright):  sh tests/serve.sh &  pa  node tests/e2e.js
   SHIVAJ_SITE = probna stranica (zadano /tmp/shivaj-test), SHIVAJ_SHOTS = mapa za snimke,
   CHROMIUM = putanja do preglednika ako Playwright nema svoj. Testovi pretpostavljaju svježu probnu stranicu. */
const SITE = (process.env.SHIVAJ_SITE || '/tmp/shivaj-test') + '/';
const OUT = (process.env.SHIVAJ_SHOTS || SITE + 'snimke') + '/';
const URL = process.env.SHIVAJ_URL || 'http://127.0.0.1:8090/';
/* v77: kod Stalne kolekcije boja i duljina remena su obavezne — testovi koji naručuju model odaberu prve ponuđene */
const pickStrap = async pg => { await pg.evaluate(() => document.querySelectorAll('.strap-sel').forEach(sl => { if(!sl.value){ sl.value = sl.options[1].value; sl.dispatchEvent(new Event('change', { bubbles: true })); } })).catch(() => {}); };
const log = [];
const ok = (c, m) => log.push((c ? 'PASS ' : 'FAIL ') + m);

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  /* v93: cijeli e2e radi s pravom provjerom „niste robot” (14 bitova, brzo) — izračun u Workeru */
  { const cf = SITE + 'data/config.json'; const c0 = JSON.parse(fs.readFileSync(cf, 'utf8')); c0.pow_bits = 14; fs.writeFileSync(cf, JSON.stringify(c0, null, 2)); }
  let browser;
  try { browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {}); } catch (e) { browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); }
  const errors = [];
  const watch = pg => { pg.on('pageerror', e => errors.push(e.message)); pg.on('console', m => { if (m.type() === 'error' && !/bunny|ERR_TUNNEL|Failed to load resource/.test(m.text())) errors.push(m.text()); }); };

  { /* v99 (recenzija vijeća v98, C7): stranica stiže sa zaglavljem CSP iz .htaccess (router.php) i ništa ga ne krši — fotografije,
       povećani prikaz, košarica, izračun „niste robot” (Worker) */
    const cc = await browser.newContext({ viewport: { width: 1440, height: 900 } }); const pc = await cc.newPage();
    await pc.addInitScript(() => { window.__csp = []; document.addEventListener('securitypolicyviolation', e => window.__csp.push(e.violatedDirective + ' ' + e.blockedURI)); });
    const rsp = await pc.goto(URL, { waitUntil: 'networkidle' }); const hdr = (rsp && rsp.headers()['content-security-policy']) || '';
    await pc.locator('#productGrid .card .photo[data-view]').first().click().catch(() => {}); await pc.waitForTimeout(400);
    await pc.keyboard.press('Escape'); await pc.evaluate(() => { try { openCart(); } catch (e) {} }); await pc.waitForTimeout(1500);
    const vio = await pc.evaluate(() => window.__csp.filter(x => !/bunny|boxnow/.test(x)));
    ok(/default-src 'self'/.test(hdr) && /object-src 'none'/.test(hdr) && vio.length === 0, "v99: CSP iz .htaccess stiže uz stranicu i nema kršenja " + JSON.stringify(vio).slice(0, 300));
    await cc.close(); }

  // desktop
  const d = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const pd = await d.newPage(); watch(pd);
  await pd.goto(URL + '?izvor=ig', { waitUntil: 'networkidle' });
  await pd.waitForTimeout(400);
  const heroName = await pd.textContent('#heroBag .hb-name').catch(() => '');
  ok(heroName === 'Tara', 'hero prikazuje torbu označenu „na naslovnici”: ' + heroName);
  const heroBox = await pd.locator('#heroBag img').boundingBox();
  ok(heroBox && heroBox.y < 900 && heroBox.width > 300, 'fotografija na prvom ekranu (desktop) ' + JSON.stringify(heroBox && { y: Math.round(heroBox.y), w: Math.round(heroBox.width) }));
  await pd.screenshot({ path: OUT + 'd-01-hero.png' });
  // card: second image + 1/1 + tagline + ship-from
  const luna = pd.locator('.card:has(h3:has(.card-name:text-is("Luna")))');
  ok(await luna.locator('img.alt').count() === 1, 'kartica ima drugu fotografiju');
  ok((await luna.locator('.count').textContent()).includes('1/2'), 'oznaka broja fotografija');
  ok(await luna.locator('.one').count() === 0, 'bez oznake 1/1');
  ok((await pd.textContent('nav')).includes('Radionica') && await pd.locator('#radionica').count() === 1, 'poveznica „Radionica” i odjeljak #radionica');
  ok(/Iz radionice/.test(await pd.textContent('#radionica h2')), 'naslov „Iz radionice u Zaboku.”');
  ok(await pd.isVisible('.hero-cta'), 'gumb „Pogledaj unikate” na prvom ekranu');
  ok(await pd.locator('.press a[target=_blank]').count() === 0 && (await pd.getAttribute('.press a', 'href')) === '#radionica' && (await pd.locator('.press-name').count()) === 3, 'traka bez vanjskih poveznica, vodi na Radionicu');
  const rad = await pd.textContent('#radionica');
  ok(/Tekstilno-tehnološkom/.test(rad) && /umjetne kože/.test(rad) && !/skaj/i.test(rad) && /Matije Gupca\s33/.test(rad) && /Zovem se Jasmina Šivalec\. Što radim, piše mi u prezimenu/.test(rad), 'Radionica: umjetna koža, adresa, tekst vlasnice (v97)');
  ok(/a J sam ja/.test(rad) && /otvorila sam obrt/.test(rad) && /Šarenu torbu počnem rezom/.test(rad) && /nikad ne sjednu baš isto/.test(rad) && /Crne i ostale jednobojne šijem po narudžbi/.test(rad) && /dan, nekad dva ili tri/.test(rad) && /shivaj\.handmade@gmail\.com\./.test(rad) && !/[{}]/.test(rad) && !/Ja sam Jasmina|s torbom u ruci/.test(rad), 'Radionica v97: doslovni tekst vlasnice, e-adresa vidljiva, bez starih odlomaka');
  ok(/Šarena se dogodi/.test(await pd.textContent('.about-quote')) && /Jasmina/.test(await pd.textContent('.about-quote small')) && !/po narudžbi: šarena se dogodi/.test(await pd.textContent('#radionica')), 'citat samo lijevo, s potpisom');
  ok(/modele iz Stalne kolekcije šijemo po narudžbi/.test(await pd.getAttribute('meta[property="og:description"]', 'content')) && /ni raspored boja/.test(await pd.textContent('#hero')) && /možete je vratiti u roku 14 dana od preuzimanja\. Detalji/.test(await pd.textContent('#uvjeti')) && !/posebn\S* želj|Kada pravo na raskid nemate/.test(await pd.textContent('#uvjeti')), 'og opis, hero „raspored boja”, Ukratko bez iznimke (v78: bez posebnih želja)');
  ok(/Ručno šivane torbe iz Zaboka/.test(await pd.title()) && /Ručno šivane torbe iz Zaboka/.test(await pd.textContent('footer')), 'slogan u naslovu i podnožju');
  ok(/potvrdite narudžbu preko linka iz e-maila; tada unikatnu torbu odmah rezerviramo/.test(await pd.textContent('#uvjeti')) && /potvrdite je preko linka iz e-maila, imate 24 sata za uplatu \(rok koji istječe vikendom ili blagdanom traje do 23:59 prvoga sljedećeg radnog dana\)\. Do tada je unikat rezerviran samo za vas\./.test(await pd.textContent('#kako')) && !/Time je unikatna torba/.test(await pd.textContent('#uvjeti')) && /Artikli po narudžbi ne rezerviraju se/.test(await pd.textContent('#uvjeti')), 'Uvjeti: Ukratko i t. 4 usklađeni');
  ok(!/šaren/i.test(await pd.evaluate(() => [...document.querySelectorAll('#kako, #torbe, #emptyCart, #orderForm, meta')].map(e => e.textContent + (e.getAttribute('content') || '')).join(' '))), 'v62: bez riječi „šarena” u tekstovima kupnje');
  ok((await pd.getAttribute('#radionica a[href^="mailto:"]', 'href')) === 'mailto:shivaj.handmade@gmail.com', 'e-mail kao mailto');
  ok(!/Svaka torba postoji/.test(await pd.getAttribute('meta[name="description"]', 'content')), 'opis za tražilice bez „svaka torba”');
  ok((await pd.textContent('#shopTitle')).trim() === 'Unikati' && /unikata dostupn[oa]$/.test((await pd.textContent('#availCount')).trim()), 'naslov „Torbe” i brojač unikata: ' + (await pd.textContent('#availCount')));
  const order48 = await pd.evaluate(() => Array.from(document.querySelectorAll('#productGrid > *')).flatMap(e => e.classList.contains('grid-gone') ? [...e.children] : [e]).map(e => e.classList.contains('grid-sub') ? (e.classList.contains('gone') ? 'G' : 'S') : e.classList.contains('made') ? 'm' : e.classList.contains('sold') ? 'x' : 'u').join(''));
  ok(/^u+Gx+$/.test(order48), 'v48/v62: u Torbama samo unikati, „Rezervirane i prodane” na kraju: ' + order48);
  ok(await pd.isVisible('nav a.navlink[href="#kolekcija"]') && await pd.locator('#catTabs a[href="#kolekcija"]').count() === 1 && await pd.locator('#catTabs a[href="#ostalo"]').count() === 1,
    'v62: izbornik „Stalna kolekcija”; Ostalo vidljiv samo uz prekidač (ispitni config ga uključuje — kartica u rubrikama)');
  ok(/umjetne kože/.test(await pd.textContent('#hero')) && !/skaj/i.test(await pd.evaluate(() => document.documentElement.innerHTML)) && /po narudžbi/.test(await pd.textContent('#kako')), 'hero i korak 01 usklađeni (umjetna koža, po narudžbi); nigdje „skaj”');
  const rowBtns = await pd.evaluate(() => { const cs = [...document.querySelectorAll('#productGrid > .card')]; const top = cs[0].getBoundingClientRect().top; return cs.filter(c => Math.abs(c.getBoundingClientRect().top - top) < 2).map(c => Math.round(c.querySelector('.add-btn').getBoundingClientRect().top)); });
  ok(rowBtns.length > 1 && new Set(rowBtns).size === 1, 'v43: gumbi u istom redu kartica poravnati ' + rowBtns.join(','));
  ok(await pd.evaluate(() => noPhoto({ id: 'x', price: 1 }) && noPhoto({ id: 'y', made: true }) && !noPhoto({ id: 'o', made: true, cat: 'ostalo' }) && !noPhoto({ id: 'z', imgs: ['a.jpg'] })), 'v45/v63: unikat i model Stalne kolekcije bez fotografije se ne naručuju, Ostalo po narudžbi da');
  const np = await pd.evaluate(() => { PRODUCTS.push({ id: 'proba-bez', name: 'Proba', price: 10, desc: 'x' }); renderGrid(); const b = document.querySelector('.add-btn[data-id="proba-bez"]'); const r = { dis: b.disabled, txt: b.textContent.trim(), ig: !!b.closest('.card').querySelector('.ask-ig a[href*="instagram"]') }; PRODUCTS.pop(); renderGrid(); return r; });
  ok(np.dis && np.txt === 'Fotografija uskoro' && np.ig, 'v45: kartica unikata bez fotografije — „Fotografija uskoro”, neaktivno, poveznica na Instagram');
  ok(/popune se sami/.test(await pd.textContent('#uvjeti')) && !/upisujete sami/.test(await pd.textContent('#uvjeti')), 'v45: Uvjeti t. 5 usklađeni s barkodom');
  await pd.evaluate(() => { location.hash = '#kolekcija'; }); await pd.waitForTimeout(300);
  ok((await pd.textContent('#shopTitle')).trim() === 'Stalna kolekcija' && await pd.locator('#productGrid .card.made').count() > 0 && await pd.locator('#productGrid .card:not(.made)').count() === 0, 'v62: rubrika Stalna kolekcija samo s modelima po narudžbi');
  const kl = pd.locator('.card:has(h3:has(.card-name:text-is("Klasik crna")))');
  ok((await kl.locator('.unikat-tag').textContent()) === 'Po narudžbi' && /5 radnih dana/.test(await kl.textContent()) && /Još 20 mjesta za narudžbu/.test(await kl.textContent()) && (await kl.locator('.add-btn').textContent()).trim() === 'Naruči izradu', 'klasična torba: „Po narudžbi”, rok, „Još N mjesta”, gumb „Naruči izradu”');
  // narudžba samo s klasičnom torbom (desktop): bez rezervacije, 14 dana s ogradom, redak uplate iz postavki
  await kl.locator('.add-btn').click();
  await pd.click('#openCart'); await pd.waitForTimeout(300);
  await pd.check('#shipOptions input[value="pickup"]');
  await pd.fill('#fName', 'Test Kupac'); await pd.fill('#fEmail', 'kupac@primjer.hr'); await pd.fill('#fPhone', '091 234 5678'); await pd.check('#fConsent');
  await pickStrap(pd); await pd.click('#orderBtn');
  await pd.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  const stepsM = await pd.textContent('.pay-steps').catch(() => '');
  ok(/Narudžba je zaprimljena — mjesto za „Klasik crna” je vaše\./.test(await pd.textContent('.pay-thanks').catch(() => '')), 'v66: ploča samo za kolekciju — „mjesto za „Klasik crna” je vaše”');
  ok(!/rezervacij/i.test(stepsM) && /Ugovor možete raskinuti od narudžbe pa do isteka 14 dana od preuzimanja, i to ovdje na stranici; trošak povrata snosite vi \(Uvjeti kupnje, točka\u00a07\.\)/.test(stepsM) && /Vašu torbu šijemo u roku od 5 radnih dana, zatim narudžbu pripremamo za preuzimanje u radionici; javit ćemo vam se/.test(stepsM) && /Čim uplata bude vidljiva, krećemo s izradom\./.test(stepsM) && !/snimku zaslona/.test(stepsM), 'narudžba bez unikata + preuzimanje: rok iz podatka, preuzimanje, 14 dana s ogradom; v65: bez traženja snimke uplate');
  const noteM = await pd.textContent('.pay-note').catch(() => '');
  ok(!/pripremamo za preuzimanje/.test(noteM) && !/pošaljite nam potvrdu/.test(noteM), 'napomena ispod uplate samo o e-mailu');
  const shareTxt = await pd.getAttribute('.copy-btn[data-label="Podaci za uplatu"]', 'data-copy').catch(() => '');
  const descLine = (shareTxt.split('\n').find(l => l.startsWith('Opis:')) || '').replace('Opis: ', '');
  ok(descLine.length > 0 && descLine.length <= 35 && /^Shiva\.J 2026-/.test(descLine), 'opis plaćanja do 35 znakova: ' + descLine);
  // idempotentna narudžba: isti ključ dvaput → isti broj, bez nove narudžbe
  const rep = await pd.evaluate(async () => { const key = JSON.parse(localStorage.getItem('shivaj.okey') || 'null'); return { key }; });
  ok(rep.key === null, 'ključ narudžbe obrisan nakon uspjeha');
  const who = await pd.textContent('.pay-who').catch(() => '');
  ok(/Uplaćujete izravno obrtu, bez posrednika/.test(who) && !/o radionici/.test(who), 'redak uplate iz postavki: ' + who.slice(0, 70));

  await pd.locator('.copy-btn').first().click().catch(() => {}); await pd.waitForTimeout(300);
  await pd.screenshot({ path: OUT + 'd-04-uplata-klasik.png' });
  ok(await pd.evaluate(() => { const b = document.getElementById('payBarcode'), d = document.querySelector('.pay-data'); return !!(b && d && (b.compareDocumentPosition(d) & Node.DOCUMENT_POSITION_FOLLOWING)); }), 'v42: na računalu barkod iznad podataka za uplatu');
  await pd.keyboard.press('Escape');
  const sticky = await pd.locator('.about-inner > div').first().evaluate(el => getComputedStyle(el).position);
  ok(sticky === 'sticky', 'lijevi stupac Radionice ljepljiv (' + sticky + ')');
  ok(await pd.locator('.press-list li a[target=_blank][rel=noopener]').count() === 3, 'popis članaka u Radionici, 3 vanjske poveznice');
  await pd.locator('.press-list a').first().evaluate(a => { a.setAttribute('href', '#radionica'); a.removeAttribute('target'); });
  await pd.locator('.press-list a').first().click(); await pd.waitForTimeout(400);
  await pd.waitForTimeout(300);
  ok(await pd.locator('.about-photo').isHidden(), 'okvir fotografije skriven bez datoteke');
  await pd.evaluate(() => { location.hash = '#torbe'; }); await pd.waitForTimeout(300); /* v62: natrag u Torbe (unikati) */
  const tagCol = await luna.locator('.tagline').evaluate(el => getComputedStyle(el).color + ' ' + getComputedStyle(el).fontSize);
  ok(/46, 74, 58/.test(tagCol), 'redak karaktera u boji konca: ' + tagCol);
  ok(!/Bez registracije/.test(await pd.textContent('.order-note')), '„bez kartice” nije ponovljen u napomeni');
  ok((await luna.locator('.tagline').textContent()).startsWith('Okrugla'), 'redak karaktera na kartici');
  ok(/dostava od 4,00\u00a0€/.test(await luna.locator('.ship-from').textContent()), 'dostava od 4,00 €');
  await luna.hover();
  await pd.waitForTimeout(500);
  const altOp = await luna.locator('img.alt').evaluate(el => getComputedStyle(el).opacity);
  ok(altOp === '1', 'druga fotografija na prijelaz mišem (opacity ' + altOp + ')');
  await pd.locator('#torbe').scrollIntoViewIfNeeded();
  await pd.screenshot({ path: OUT + 'd-02-grid-hover.png' });
  // lightbox story + hidden placeholder dims
  await luna.locator('.photo').click();
  await pd.waitForTimeout(300);
  ok(await pd.isVisible('#lbStory') && /Krug složen/.test(await pd.textContent('#lbStory')), 'priča u povećanom prikazu');
  ok(await pd.isVisible('#lbDimsRow') && /^26 × 7 cm$/.test((await pd.textContent('#lbDims')).trim()) && !/na upit|\{/.test(await pd.textContent('#lightbox')), 'v46/v83: mjere u povećanom prikazu, bez „na upit” i bez {…}');
  ok(await pd.locator('.lb-full').count() === 1, 'poveznica na punu veličinu');
  await pd.screenshot({ path: OUT + 'd-03-lightbox.png' });
  const dimsTxt = await pd.$$eval('.card .ship-from', els => els.map(e => e.textContent).filter(t => /^Mjere/.test(t)));
  ok(!dimsTxt.some(t => /na upit/.test(t)) && /Mjere: 26 × 7 cm/.test(await pd.textContent('.card:has(h3:has(.card-name:text-is("Luna")))')), 'v83: nigdje „Mjere: na upit” (artikl bez mjera nije u ponudi)');
  ok(/Unikate šaljemo u roku 1\u2060?–\u2060?2 radna dana nakon uplate/.test(await pd.textContent('#kako')), 'v50/v83: rok slanja u „Kako naručiti” (unikati; po narudžbi kad ih sašijemo)');
  { const imp = await pd.textContent('#impresum'), t1 = await pd.textContent('#uvjeti');
    const lg = await pd.evaluate(async () => { const r = await fetch('img/zakonsko-jamstvo-hr.jpg'); return [r.status, document.querySelectorAll('.lg-notice img[src="img/zakonsko-jamstvo-hr.jpg"], .lg-notice img[data-later="img/zakonsko-jamstvo-hr.jpg"]').length, document.querySelectorAll('.lg-badge[href="img/zakonsko-jamstvo-hr.jpg"]').length, !!document.querySelector('#impresum .lg-badge')]; });
    ok(/MBO: 98020200/.test(imp) && /Obrtni registar, MBO: 98020200/.test(t1) && lg[0] === 200 && lg[1] === 1 && lg[2] === 1 && lg[3] === true, 'v84/v88: MBO u impresumu i Uvjetima t. 1; EU obavijest o zakonskom jamstvu u Uvjetima t. 8 i u impresumu ' + JSON.stringify(lg)); }
  { const j88 = await pd.evaluate(() => { const a = document.querySelector('#orderForm .trust-line a[href="#jamstvo"]'); return [!!a && a.textContent.trim(), document.querySelectorAll('#orderForm .lg-badge, #orderForm img[src*="zakonsko"]').length, !!document.querySelector('details#jamstvo .lg-notice')]; });
    await pd.evaluate(() => { document.getElementById('jamstvo').open = false; });
    await pd.keyboard.press('Escape'); await pd.waitForTimeout(300);
    if(!(await pd.evaluate(() => JSON.parse(localStorage.getItem('shivaj_cart') || '[]').length))) { await pd.locator('.card:not(.sold) .add-btn').first().click(); await pd.waitForTimeout(300); }
    if(!(await pd.evaluate(() => document.body.classList.contains('cart-open')))) { await pd.click('#openCart'); await pd.waitForTimeout(400); }
    const vis88 = await pd.isVisible('#orderForm .trust-line a[href="#jamstvo"]');
    if(vis88) await pd.click('#orderForm .trust-line a[href="#jamstvo"]'); else await pd.evaluate(() => { location.hash = 'jamstvo'; openJamstvo(); });
    await pd.waitForTimeout(1200);
    const st88 = await pd.evaluate(() => [document.body.classList.contains('cart-open'), document.getElementById('jamstvo').open, Math.round(document.querySelector('#jamstvo summary').getBoundingClientRect().top - document.querySelector('header').getBoundingClientRect().bottom)]);
    await pd.evaluate(() => { document.getElementById('jamstvo').open = false; history.replaceState(null, '', location.pathname); window.scrollTo(0, 0); });
    ok(JSON.stringify(j88) === '["Zakonsko jamstvo 2 godine",0,true]' && st88[0] === false && st88[1] === true && st88[2] >= 0 && st88[2] < 40, 'v88: u košarici kratka poveznica bez sličice; klik zatvara košaricu i otvara Uvjete t. 8 ispod zaglavlja ' + JSON.stringify([j88, vis88, st88])); }
  { const ik = await pd.evaluate(async () => { const l = document.querySelector('link[rel="icon"]'), a = document.querySelector('link[rel="apple-touch-icon"]');
      const r = await fetch(l.getAttribute('href')); const im = new Image(); im.src = a.getAttribute('href'); await im.decode().catch(() => {});
      return [l.getAttribute('href'), r.status, (r.headers.get('content-type') || '').split(';')[0], im.naturalWidth, im.naturalHeight]; });
    ok(JSON.stringify(ik) === '["img/ikona.svg",200,"image/svg+xml",180,180]', 'v87: ikona stranice .J (SVG) i ikona za iPhone 180×180 ' + JSON.stringify(ik)); }
  { const lg86 = await pd.evaluate(() => [...document.querySelectorAll('img[src="img/logo.svg"]')].map(i => [i.closest('header') ? 'h' : i.closest('footer') ? 'f' : '?', i.alt]));
    const hl = await pd.evaluate(async () => { const i = document.querySelector('header .wordmark img'); await i.decode().catch(() => {}); const r = i.getBoundingClientRect(); return [i.naturalWidth > 0, Math.round(r.height), Math.round(r.width)]; });
    ok(JSON.stringify(lg86) === '[["h","Shiva.J"],["f","Shiva.J"]]' && hl[0] && hl[1] === 28 && hl[2] > 90 && hl[2] < 130, 'v86: logo u zaglavlju i podnožju, učitan, 28 px visok ' + JSON.stringify([lg86, hl])); }
  { const tel = await pd.evaluate(() => [...document.querySelectorAll('a[href="tel:+385998866877"]')].map(a => (a.closest('#impresum') ? 'imp' : a.closest('footer') ? 'foot' : a.closest('#privatnost') ? 'priv' : a.closest('#uvjeti') ? 'uvj' : '?') + ':' + a.textContent.trim()));
    const form = await pd.textContent('.raskid-form');
    ok(['uvj', 'priv', 'imp', 'foot'].every(k => tel.includes(k + ':099 886 6877')) && /Prima: .*tel\. 099 886 6877/.test(form), 'v85: telefon obrta u Uvjetima t. 1, Izjavi t. 1, impresumu, podnožju i obrascu za raskid ' + JSON.stringify(tel)); }
  ok(!/na upit/.test(await pd.textContent('#uvjeti')) && /navedeni su uz svaki artikl/.test(await pd.textContent('#uvjeti')) && !/tkanin/.test(await pd.textContent('#uvjeti')), 'v83: Uvjeti t. 2 — mjere i materijal uz svaki artikl, bez „na upit” i „tkanine”');
  await pd.evaluate(() => { location.hash = '#kolekcija'; }); await pd.waitForTimeout(300);
  await pd.evaluate(() => { location.hash = '#ostalo'; }); await pd.waitForTimeout(300);
  ok(/Fotografija modela uskoro/.test(await pd.textContent('.card:has(h3:has(.card-name:text-is("Zamjenski remen")))')), 'v50/v63: artikl iz Ostalo bez fotografije — pitajte za izgled');
  await pd.evaluate(() => { location.hash = '#kolekcija'; }); await pd.waitForTimeout(300);
  await pd.evaluate(() => { location.hash = '#torbe'; }); await pd.waitForTimeout(300);
  ok(dimsTxt.length > 0 && dimsTxt.every(t => /^Mjere: \d/.test(t)) && dimsTxt.some(t => /^Mjere: \d/.test(t)), 'v46/v51: upisane mjere na kartici, s dostavom u istom retku (' + dimsTxt.length + ')');
  await pd.keyboard.press('Escape');
  // legal collapsed
  const openCount = await pd.locator('.legal details[open]').count();
  const total = await pd.locator('.legal details').count();
  ok(total === 21 && openCount === 0, `uvjeti i privatnost sklopljeni (${total} odjeljaka)`);
  const hBefore = await pd.evaluate(() => document.documentElement.scrollHeight);
  await pd.locator('.legal summary').first().click();
  ok(await pd.locator('.legal details[open]').count() === 1, 'odjeljak se otvara klikom');
  // hero click opens lightbox
  await pd.evaluate(() => scrollTo(0, 0));
  await pd.click('#heroBag .ring');
  await pd.waitForTimeout(200);
  ok((await pd.textContent('#lbName')) === 'Tara', 'klik na hero otvara torbu');
  await pd.keyboard.press('Escape');

  ok(/rezerviramo za vas/.test(await pd.evaluate(() => document.documentElement.innerHTML)) && !/će biti rezervirana/.test(await pd.evaluate(() => document.documentElement.innerHTML)), 'ime torbe uz riječ „Torba”: „bit će rezervirana”');
  // izravna poveznica na torbu i pri promjeni adrese
  const ph = await d.newPage(); await ph.goto(URL, { waitUntil: 'networkidle' }); await ph.evaluate(() => { location.hash = '#torba-luna'; }); await ph.waitForTimeout(400);
  ok(await ph.isVisible('#lightbox') && (await ph.textContent('#lbName')) === 'Luna', 'hashchange #torba-luna otvara povećani prikaz');
  await ph.close();
  // izravna poveznica na torbu
  const pl = await d.newPage(); await pl.goto(URL + '#torba-tara', { waitUntil: 'networkidle' }); await pl.waitForTimeout(500);
  ok(await pl.isVisible('#lightbox') && (await pl.textContent('#lbName')) === 'Tara', 'poveznica #torba-tara otvara povećani prikaz');
  await pl.close();
  // mobile
  const m = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const pm = await m.newPage(); watch(pm);
  await pm.goto(URL, { waitUntil: 'networkidle' });
  // v83 (završno vijeće): naslovnica rezervira mjesto za torbu od početka (bez skakanja), Escape zatvara izbornik, gumbi ≥ 44 px
  { const raw = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
    ok(/<section class="hero has-bag" id="hero">/.test(raw) && /<figure class="hero-bag" id="heroBag" aria-hidden="true"><span class="ring ring-ph">/.test(raw), 'v83: mjesto za torbu na naslovnici rezervirano u HTML-u (CLS)'); }
  { const pf = await m.newPage(); let fails = 1;
    await pf.addInitScript(() => { try { localStorage.setItem('shivaj.cart', JSON.stringify([{ id: 'luna', qty: 1 }])); } catch (e) {} });
    await pf.route('**/torbe.json*', r => fails-- > 0 ? r.fulfill({ status: 500, body: 'x' }) : r.continue());
    await pf.goto(URL, { waitUntil: 'networkidle' }); await pf.waitForTimeout(300);
    const f1 = await pf.evaluate(() => [document.querySelector('.grid-empty-title')?.textContent, !!document.getElementById('retryProducts'), localStorage.getItem('shivaj.cart')]);
    await pf.click('#retryProducts'); await pf.waitForTimeout(800);
    const f2 = await pf.evaluate(() => [document.querySelectorAll('#productGrid .card').length, document.getElementById('cartCount')?.textContent]);
    ok(f1[0] === 'Ponudu trenutačno ne možemo učitati' && f1[1] && /luna/.test(f1[2] || '') && f2[0] > 0, 'v89: ponuda se nije učitala — posebna poruka, košarica sačuvana, „Pokušaj ponovno” učita torbe ' + JSON.stringify([f1, f2])); await pf.close(); }
  { const pt = await m.newPage(); await pt.goto(URL, { waitUntil: 'networkidle' });
    const t1 = await pt.evaluate(() => { const b = document.createElement('button'); b.className = 'copy-btn'; b.textContent = 'Kopiraj'; document.body.appendChild(b); const h = b.getBoundingClientRect().height; b.remove();
      const head = document.head.innerHTML; return [h, /github\.io/.test(head), document.querySelector('link[rel=canonical]')?.href, document.querySelector('meta[property="og:image"]')?.content, !!document.querySelector('noscript')]; });
    await pt.click('#menuBtn').catch(() => {}); await pt.waitForTimeout(200);
    const mh = await pt.evaluate(() => Math.min(...[...document.querySelectorAll('.mobile-menu a')].map(a => a.getBoundingClientRect().height).filter(x => x > 0)));
    ok(t1[0] >= 44 && !t1[1] && t1[2] === 'https://shivaj.hr/' && t1[3] === 'https://shivaj.hr/img/og.jpg' && t1[4] && mh >= 44, 'v89: gumbi „Kopiraj” i izbornik 44 px, og/canonical na shivaj.hr, noscript ' + JSON.stringify([t1, mh])); await pt.close(); }
  { const ps = await d.newPage(); await ps.route(/\/img\/(?!logo|ikona).*\.(jpg|png)$/, r => r.abort()); await ps.goto(URL, { waitUntil: 'load' }); await ps.waitForTimeout(1200);
    await ps.keyboard.press('Tab'); const sk = await ps.evaluate(() => [document.activeElement.className, document.activeElement.getAttribute('href'), Math.round(document.activeElement.getBoundingClientRect().top)]);
    const off = await ps.evaluate(() => [document.querySelectorAll('.img-off').length, [...document.querySelectorAll('#productGrid img:not([hidden])')].filter(i => i.complete && i.naturalWidth === 0).length]);
    ok(sk[0] === 'skip' && sk[1] === '#torbe' && sk[2] >= 0 && off[0] > 0 && off[1] === 0, 'v92 (recenzija): prvi Tab je „Preskoči na ponudu”; slike koje se ne učitaju dobivaju okvir bez slomljene ikone ' + JSON.stringify([sk, off])); await ps.close(); }
  { const pb = await d.newPage(); await pb.goto(URL + 'img/logo.svg'); await pb.goto(URL, { waitUntil: 'networkidle' }); await pb.waitForTimeout(600);
    await pb.click('#productGrid .card .photo'); await pb.waitForTimeout(300); const lbOpen = await pb.isVisible('#lightbox.open');
    await pb.goBack(); await pb.waitForTimeout(400); const r1 = [await pb.isVisible('#lightbox.open'), pb.url()];
    await pb.click('#openCart'); await pb.waitForTimeout(300); const cOpen = await pb.evaluate(() => document.body.classList.contains('cart-open'));
    await pb.goBack(); await pb.waitForTimeout(400); const r2 = [await pb.evaluate(() => document.body.classList.contains('cart-open')), pb.url()];
    await pb.click('#productGrid .card .photo'); await pb.waitForTimeout(300); await pb.click('#lbClose'); await pb.waitForTimeout(400);
    await pb.goBack(); await pb.waitForTimeout(400); const r3 = pb.url();
    ok(lbOpen && !r1[0] && !/logo\.svg/.test(r1[1]) && cOpen && !r2[0] && !/logo\.svg/.test(r2[1]) && /logo\.svg/.test(r3), 'v94 (recenzija vijeća, mobitel): „natrag” zatvara povećani prikaz i košaricu, ne izlazi iz trgovine; nakon zatvaranja gumbom „natrag” ide na prethodnu stranicu ' + JSON.stringify([lbOpen, r1, cOpen, r2, r3])); await pb.close(); }
  { const pn = await d.newPage(); await pn.goto(URL + '#torba-nepostoji', { waitUntil: 'load' }); await pn.waitForTimeout(1200);
    const tn = await pn.evaluate(() => [document.getElementById('toast').textContent, Math.round(document.getElementById('torbe').getBoundingClientRect().top)]);
    ok(/više nije u ponudi/.test(tn[0]) && Math.abs(tn[1]) < 200, 'v91 (recenzija): poveznica na nepostojeću torbu — poruka i skok na ponudu ' + JSON.stringify(tn)); await pn.close(); }
  { const pu = await d.newPage(); await pu.goto(URL + '#uvjeti', { waitUntil: 'load' }); await pu.waitForTimeout(1200);
    const uv = await pu.evaluate(() => Math.round(document.getElementById('uvjeti').getBoundingClientRect().top));
    const fd = await pu.evaluate(() => [fmtDeadline('2026-11-11T23:01:00Z'), fmtDeadline('2026-11-12T10:00:00Z')]);
    ok(uv >= 0 && uv < 160 && /12\.\s11\.\s2026\.\su\s00:01 \(u noći sa srijede na četvrtak\)$/.test(fd[0]) && !/noći/.test(fd[1]), 'v90: /#uvjeti nakon učitavanja na Uvjetima; noćni rok s „u noći sa srijede na četvrtak” ' + JSON.stringify([uv, fd])); await pu.close(); }
  { /* v96 (recenzija vijeća v95, hitno): na računalu fotografija u povećanom prikazu ne prekriva tekst */
    const ov = [];
    for (const w of [1440, 1024, 820, 700]) {
      const cx = await browser.newContext({ viewport: { width: w, height: 800 } }); const po = await cx.newPage();
      await po.goto(URL + '#torba-luna', { waitUntil: 'networkidle' }); await po.waitForTimeout(700);
      const r = await po.evaluate(() => { const ph = document.querySelector('#lightbox .lb-photo').getBoundingClientRect(), inf = document.querySelector('#lightbox .lb-info').getBoundingClientRect(); return Math.round(ph.right - inf.left); });
      ov.push(r); await cx.close();
    }
    ok(ov.every(x => x <= 0), 'v96: povećani prikaz bez preklapanja fotografije i teksta (1440/1024/820/700) ' + JSON.stringify(ov)); }
  { /* v96 (recenzija vijeća v95, mobitel): Uvjeti otvoreni iz košarice → „natrag” ponovno otvara košaricu */
    const pc = await m.newPage(); await pc.goto(URL, { waitUntil: 'networkidle' }); await pc.waitForTimeout(300);
    await pc.locator('#productGrid .card:not(.made) .add-btn:not([disabled])').first().click(); await pc.waitForTimeout(200);
    if(!(await pc.evaluate(() => document.body.classList.contains('cart-open')))) { await pc.evaluate(() => openCart()); await pc.waitForTimeout(400); }
    await pc.locator('#orderForm a.drawer-link[href="#uvjeti"]').scrollIntoViewIfNeeded().catch(() => {});
    await pc.locator('#orderForm a.drawer-link[href="#uvjeti"]').click();
    /* v99 (C8): čeka stanje (zatvorena košarica, Uvjeti pri vrhu) umjesto fiksnog čekanja — glatko pomicanje traje različito */
    await pc.waitForFunction(() => !document.body.classList.contains('cart-open') && Math.abs(document.getElementById('uvjeti').getBoundingClientRect().top) < 200, null, { timeout: 4000 }).catch(() => {});
    const at = await pc.evaluate(() => [document.body.classList.contains('cart-open'), Math.round(document.getElementById('uvjeti').getBoundingClientRect().top)]);
    await pc.goBack(); await pc.waitForFunction(() => document.body.classList.contains('cart-open'), null, { timeout: 4000 }).catch(() => {});
    const back = await pc.evaluate(() => [document.body.classList.contains('cart-open'), location.href]);
    ok(!at[0] && Math.abs(at[1]) < 200 && back[0] && !/logo\.svg|about:blank/.test(back[1]), 'v96: Uvjeti iz košarice, pa „natrag” — košarica ponovno otvorena ' + JSON.stringify([at, back])); await pc.close(); }
  { /* v98 (recenzija vijeća v97): ostatak košarice, vlastita torba, „natrag” vraća rubriku, prazna košarica bez praznog koraka, zbijena mreža, datum */
    const mv = await browser.newContext({ viewport: { width: 390, height: 844 } }); const pv = await mv.newPage(); await pv.goto(URL, { waitUntil: 'networkidle' }); await pv.waitForTimeout(300);
    await pv.evaluate(() => { storeSet('cart', null); storeSet('carry', [{ id: 'kozmeticka', qty: 1, note: '' }]); });
    await pv.reload({ waitUntil: 'networkidle' }); await pv.waitForTimeout(300);
    const carried = await pv.evaluate(() => [cart.map(c => c.id), storeGet('carry')]);
    ok(carried[0].includes('kozmeticka') && carried[1] === null, 'v98 (kupac N1): artikl izvan potvrđene narudžbe vraća se u košaricu nakon ponovnog otvaranja ' + JSON.stringify(carried));
    const own = await pv.evaluate(() => { storeSet('cart', null); cart = []; const p = PRODUCTS.find(x => x.id === 'luna'); p.srvReserved = true; storeSet('last', { at: Date.now(), items: [{ id: 'luna', name: 'Luna', qty: 1, price: 65 }], srv: { orderNo: 'SJ-2026-0042' } });
      openLightbox('luna'); const t = document.getElementById('lbGoneText').textContent; closeLightbox(); storeSet('last', null); p.srvReserved = false; return t; });
    ok(/rezervirana za vas \(narudžba SJ-2026-0042\)/.test(own) && !/drugog kupca/.test(own), 'v98 (mobitel M1): vlastita rezervirana torba — „rezervirana za vas”, ne „za drugog kupca” ' + JSON.stringify(own));
    const fd = await pv.evaluate(() => fmtDateTime('2026-01-08T07:05:00Z'));
    ok(fd === '8. 1. 2026. u 08:05', 'v98 (dizajn): datum u obliku „8. 1. 2026. u 08:05” ' + JSON.stringify(fd));
    await pv.goto(URL, { waitUntil: 'networkidle' }); await pv.waitForTimeout(300);
    await pv.evaluate(() => document.getElementById('kako').scrollIntoView()); await pv.waitForTimeout(200);
    await pv.evaluate(() => { location.hash = 'kolekcija'; }); await pv.waitForTimeout(400);
    const c1 = await pv.evaluate(() => currentCat);
    await pv.goBack(); await pv.waitForTimeout(500);
    const c2 = await pv.evaluate(() => [currentCat, CATEGORIES[0].id]);
    ok(c1 === 'kolekcija' && c2[0] === c2[1], 'v98 (mobitel M2): „natrag” nakon rubrike vraća prethodnu rubriku ' + JSON.stringify([c1, c2]));
    await pv.evaluate(() => { storeSet('cart', null); cart = []; renderCart(); openCart(); }); await pv.waitForTimeout(300);
    await pv.click('#emptyCart .empty-go'); await pv.waitForTimeout(500);
    const eg = await pv.evaluate(() => [document.body.classList.contains('cart-open'), history.state && history.state.sj || '', location.hash]);
    ok(!eg[0] && eg[1] !== 'cart' && eg[2] === '#torbe', 'v98 (mobitel L2): „Pogledaj torbe” iz prazne košarice ne ostavlja prazan korak povijesti ' + JSON.stringify(eg));
    const allGone = await pv.evaluate(() => { currentCat = CATEGORIES[0].id; const keep = PRODUCTS.map(p => [p, p.srvSold]); PRODUCTS.forEach(p => { if(!p.made) p.srvSold = true; }); renderGrid();
      const r = [document.querySelectorAll('#productGrid .grid-gone .card').length, document.querySelectorAll('#productGrid > .card').length]; keep.forEach(([p, v]) => { p.srvSold = v; }); renderGrid(); return r; });
    ok(allGone[0] > 0 && allGone[1] === 0, 'v98 (mobitel L7): kad su otišli svi unikati — zbijena mreža, ne pune sive kartice ' + JSON.stringify(allGone));
    await mv.close(); }
  { const pq = await m.newPage(); await pq.goto(URL + '#torba-luna', { waitUntil: 'networkidle' }); await pq.waitForTimeout(700);
    const lb = await pq.evaluate(() => { const a = document.getElementById('lbAdd').getBoundingClientRect(); return [Math.round(a.bottom), innerHeight, getComputedStyle(document.querySelector('.lb-photo img')).objectFit, getComputedStyle(document.getElementById('lbAdd')).backgroundColor]; });
    const cs = await pq.evaluate(() => { const c = document.getElementById('fConsent'); const r = c.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height), document.getElementById('fName').getAttribute('autocapitalize')]; });
    ok(lb[0] <= lb[1] && lb[2] === 'contain' && lb[3] !== 'rgba(0, 0, 0, 0)' && cs[0] >= 24 && cs[2] === 'words', 'v90: mobitel — „Dodaj u košaricu” vidljiv u povećanom prikazu, cijela fotografija, kvačica 24 px, ime velikim slovom ' + JSON.stringify([lb, cs])); await pq.close(); }
  { const pe = await m.newPage(); await pe.route('**/torbe.json*', r => r.fulfill({ status: 200, contentType: 'application/json', body: '[]' }));
    await pe.goto(URL, { waitUntil: 'networkidle' }); await pe.waitForTimeout(300);
    const he = await pe.evaluate(() => [document.getElementById('hero').classList.contains('has-bag'), document.getElementById('heroBag').hidden]);
    ok(he[0] === false && he[1] === true, 'v83 (recenzija): prazna ponuda — rezervirano mjesto na naslovnici nestaje ' + JSON.stringify(he)); await pe.close(); }
  await pm.click('#menuBtn'); await pm.waitForTimeout(150); await pm.keyboard.press('Escape'); await pm.waitForTimeout(150);
  const mEsc = await pm.evaluate(() => [document.body.classList.contains('menu-open'), document.getElementById('menuBtn').getAttribute('aria-expanded'), document.activeElement && document.activeElement.id]);
  ok(mEsc[0] === false && mEsc[1] === 'false' && mEsc[2] === 'menuBtn', 'v83: Escape zatvara mobilni izbornik i vraća fokus na gumb ' + JSON.stringify(mEsc));
  const tapH = await pm.evaluate(() => [Math.round(document.getElementById('menuBtn').getBoundingClientRect().height), Math.round(document.querySelector('.cat-tab').getBoundingClientRect().height)]);
  ok(tapH[0] >= 44 && tapH[1] >= 44, 'v83: izbornik i kartice kategorija barem 44 px visine ' + JSON.stringify(tapH));
  await pm.waitForTimeout(400);
  const mb = await pm.locator('#heroBag img').boundingBox();
  ok(mb && mb.y + mb.height < 844, 'mobitel: torba cijela na prvom ekranu ' + JSON.stringify(mb && { y: Math.round(mb.y), h: Math.round(mb.height) }));
  const h1b = await pm.locator('#hero h1').boundingBox();
  ok(h1b && mb && h1b.y + h1b.height <= mb.y, 'mobitel: naslov iznad torbe (' + Math.round(h1b.y) + ' < ' + Math.round(mb.y) + ')');
  const overflow = await pm.evaluate(() => document.documentElement.scrollWidth > innerWidth);
  ok(!overflow, 'mobitel: bez vodoravnog pomicanja');
  const H = await pm.evaluate(() => document.documentElement.scrollHeight);
  ok(H < 12500, 'mobitel: visina stranice ' + H + ' px (v21: 9 173; v90: veći naslovi odjeljaka)');
  const pb = await pm.locator('.press-inner').boundingBox();
  ok(pb && pb.height < 120, 'mobitel: traka kompaktna (' + Math.round(pb.height) + ' px)');
  const cta = await pm.locator('.hero-cta').boundingBox();
  ok(cta && cta.y > mb.y + mb.height && cta.y + cta.height <= 844, 'mobitel v42: gumb „Pogledaj torbe” odmah ispod torbe, na prvom ekranu ' + JSON.stringify(cta && { y: Math.round(cta.y) }));
  await pm.screenshot({ path: OUT + 'm-01-hero.png' });
  const hbn = await pm.locator('#heroBag .hb-name').boundingBox(), hbp = await pm.locator('#heroBag .price').boundingBox(), hbt = await pm.locator('#heroBag .hb-tag').boundingBox();
  ok(hbn && hbp && hbt && Math.abs(hbn.y - hbp.y) < 12 && hbt.y + hbt.height <= hbn.y + 2, 'v44: naslovnica — „Jedan primjerak” u svom redu, ime i cijena zajedno');
  // order flow with timeline
  await pm.click('.card:has(h3:has(.card-name:text-is("Luna"))) .add-btn');
  await pm.waitForTimeout(150);
  ok(/U košarici: „Luna”\u00a0✓/.test(await pm.textContent('#toast').catch(() => '')), 'poruka „U košarici: „Luna” ✓” (kvačica uz tekst)');
  await pm.click('#openCart'); await pm.waitForTimeout(300);
  ok(!(await pm.evaluate(() => document.getElementById('toast').classList.contains('show'))), 'v44: otvaranje košarice sakrije obavijest „U košarici”');
  ok(await pm.isVisible('.trust-line'), 'košarica: redak povjerenja');
  ok(await pm.isVisible('.next-step'), 'košarica: sljedeći korak ispod gumba');
  const sum = await pm.textContent('#orderSum'), tot = await pm.textContent('#totalAmount');
  ok(/Slanje u roku 1\u2060?–\u2060?2 radna dana/.test(await pm.textContent('.order-lead')) && await pm.isVisible('.order-lead'), 'v50: rok slanja uz gumb');
  ok(sum.startsWith('Ukupno ' + tot) && await pm.isVisible('#orderSum'), 'v46: ukupno uz gumb: ' + sum);
  ok((await pm.locator('#fName').evaluate(el => getComputedStyle(el).fontSize)) === '16px', 'v46: polja 16 px (iPhone ne uvećava)');
  ok(await pm.isVisible('.cart-ask a[href*="instagram"]'), 'v46: kontakt prije narudžbe u košarici');
  ok(await pm.evaluate(() => document.body.classList.contains('no-confirm')) && !(await pm.isVisible('.next-step [data-if-confirm]')) && /Odmah nakon narudžbe vidite IBAN/.test(await pm.evaluate(() => document.querySelector('#kako [data-if-noconfirm]').textContent)), 'v56: bez potvrde e-mailom (postavka) stranica ne obećava link');
  { const v = await pm.evaluate(() => { const q = s => [...document.querySelectorAll(s)]; const shown = el => getComputedStyle(el).display !== 'none';
      return { conf: q('#uvjeti [data-if-confirm], #privatnost [data-if-confirm], footer [data-if-confirm]').map(shown), nc: q('#uvjeti [data-if-noconfirm], #privatnost [data-if-noconfirm], footer [data-if-noconfirm]').map(shown) }; });
    ok(v.conf.length >= 5 && v.conf.every(x => !x) && v.nc.length >= 3 && v.nc.every(x => x), 'v57: bez potvrde e-mailom Uvjeti, Privatnost i podnožje ne spominju link (' + v.conf.length + '/' + v.nc.length + ')'); }
  await pm.click('#closeCart'); await pm.waitForTimeout(300);
  const lunaBtn = pm.locator('.card:has(h3:has(.card-name:text-is("Luna"))) .add-btn');
  ok(/U košarici ✓ · otvori/.test(await lunaBtn.textContent()), 'v46: gumb „U košarici ✓ · otvori”');
  await lunaBtn.click(); await pm.waitForTimeout(300);
  ok(await pm.evaluate(() => document.body.classList.contains('cart-open')) && /Luna/.test(await pm.textContent('#cartItems')), 'v46: drugi dodir otvara košaricu, torba ostaje u njoj');
  ok(/Torbu „Luna” rezerviramo za vas 24 sata od narudžbe \(preko vikenda i blagdana do 23:59 prvoga sljedećeg radnog dana\); u tom roku uplatite\./.test(await pm.textContent('#orderHold')) && await pm.locator('#orderHold .hold-exc').count() === 0 && await pm.locator('#orderNoteHold').isHidden(), 'redak o rezervaciji bez slaganja roda; napomena ga ne ponavlja');
  await pm.check('#shipOptions input[value="pickup"]');
  await pm.fill('#fName', 'Test Kupac'); await pm.fill('#fEmail', 'kupac@primjer.hr'); await pm.fill('#fPhone', '091 234 5678');
  await pm.check('#fConsent');
  await pm.screenshot({ path: OUT + 'm-03-kosarica.png' });
  await pickStrap(pm); await pm.click('#orderBtn');
  await pm.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  const stepsOnly = await pm.textContent('.pay-steps').catch(() => '');
  const steps = (await pm.textContent('.pay-sum').catch(() => '')) + ' ' + stepsOnly;
  ok(await pm.evaluate(() => !!(powDone && /^\d+$/.test(powDone.nonce) && powOffer && powOffer.bits === 14)), 'v93: narudžba je poslana uz provjeru „niste robot” izračunatu u pregledniku (14 bitova)'); /* v90: točan rok u sažetku na vrhu, korak kaže „Do roka” */
  ok(/^Do roka/.test(stepsOnly.trim()) && !/\d{2}:\d{2}/.test(stepsOnly.split('Uplatite')[0]), 'v90 (recenzija): rok samo jednom — u sažetku „Uplatite … do …”, korak 1 „Do roka”');
  ok(/1–2 radna dana/.test(steps) && /[Dd]o (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje),\s\d{1,2}\.\s\d{1,2}\.\s\d{4}\.\su\s\d{2}:\d{2}/.test(steps), 'vremenska crta s rokom slanja (v68: „Do” + točan rok za uplatu)');
  ok(/[Dd]o (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje),\s\d{1,2}\.\s\d{1,2}\.\s\d{4}\.\su\s\d{2}:\d{2}/.test(steps) && /pripremamo za preuzimanje u radionici/.test(steps) && /javit ćemo vam se kad bude spremna i dogovoriti termin/.test(steps), 'novi tekst koraka (preuzimanje)');
  ok(/Torba „Luna” rezervirana je za vas/.test(await pm.textContent('.pay-thanks')) && !/✂/.test(await pm.textContent('.pay-thanks')), 'zahvala „Torba „Luna” je rezervirana”, bez emojija');
  ok(await pm.evaluate(() => document.activeElement && document.activeElement.classList.contains('pay-thanks')), 'fokus na zahvali nakon narudžbe');
  ok((await pm.textContent('.pay-hello')).startsWith('Hvala vam!') && /Narudžba SJ-\d{4}-\d{4}/.test(await pm.textContent('.pay-hello')) && await pm.locator('.pay-thanks b').count() === 0 && !/Rok za uplatu/.test(await pm.textContent('.pay-thanks')) && /Ako se predomislite/.test(await pm.textContent('.pay-steps')) && /navečer ili vikendom/.test(await pm.textContent('.pay-steps')), 'v42/v69: „Hvala vam!” kao naslov; rok samo jednom (u koraku), uplata navečer ili vikendom, korak „Ako se predomislite”');
  const cbh = await pm.locator('.pay-data .copy-btn').first().boundingBox();
  ok(cbh && cbh.height >= 44, 'v42: gumb „Kopiraj” u podacima ' + Math.round(cbh && cbh.height) + ' px');
  const ibanRow = await pm.evaluate(() => { const v = document.querySelector('.pay-data .iban'), b = v && v.parentElement.querySelector('.copy-btn'); if(!b) return null; const a = v.getBoundingClientRect(), c = b.getBoundingClientRect(); return c.top >= a.bottom - 1 && Math.abs(c.left - a.left) < 4; });
  ok(ibanRow === true, 'v96 (recenzija vijeća v95): „Kopiraj” uvijek ispod vrijednosti, poravnat lijevo (390 px)');
  ok(await pm.evaluate(() => { const b = document.getElementById('payBarcode'), d = document.querySelector('.pay-data'); return !b || !!(d.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING); }), 'v42: na mobitelu barkod ispod podataka');
  ok(/bez „SJ-”/.test(await pm.textContent('.pay-data')) && /neželjenu poštu/.test(await pm.textContent('.pay-note')) && /istom mobitelu/.test(await pm.textContent('.pay-barcode figcaption')), 'poziv na broj bez SJ-, neželjena pošta, barkod na istom mobitelu');
  ok(/Uplaćujete izravno obrtu/.test(await pm.textContent('.pay-who')) && await pm.locator('.copy-btn[data-label="Primatelj"]').count() === 1, 'redak o uplati i Kopiraj uz primatelja');
  const stepsU = (await pm.textContent('.pay-sum')) + ' ' + await pm.textContent('.pay-steps');
  ok(/[Dd]o (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje),\s\d{1,2}\.\s\d{1,2}\.\s\d{4}\.\su\s\d{2}:\d{2}/.test(stepsU) && !/Sada/.test(stepsU) && /instant uplatu/.test(stepsU) && /Ugovor možete raskinuti od narudžbe pa do isteka 14 dana od preuzimanja, i to ovdje na stranici; trošak povrata snosite vi \(Uvjeti/.test(stepsU) && !/osim ako/.test(stepsU) && /kliknite „Uplatio\/la sam”: više vam ne šaljemo podsjetnike, a rok za uplatu produljujemo do (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje),\s\d{1,2}\.\s\d{1,2}\.\s\d{4}\.\su\s\d{2}:\d{2}/.test(stepsU) && !/nakon isteka roka/.test(stepsU), 'unikat + preuzimanje: bez dvostrukog koraka, v70: jedno obećanje „Uplatio/la sam” s točnim datumom, 14 dana bez ograde');
    ok(!(await pm.locator('#toast.show').count()), 'nakon narudžbe nema obavijesti preko ploče');
  const prim = await pm.locator('.pay-data div:has(dt:text-is("Primatelj")) dd').innerHTML();
  ok(prim.indexOf('Matije Gupca') < prim.indexOf('copy-btn'), 'Kopiraj primatelja ispod adrese');
  await pm.waitForFunction(() => { const i = document.querySelector('#payBarcode img'); return i && i.dataset.hub3 === '1'; }, null, { timeout: 8000 }).catch(() => {});
  const bc = await pm.evaluate(() => { const i = document.querySelector('#payBarcode img'); return { hub3: i && i.dataset.hub3, src: i ? i.src : '', cap: document.querySelector('#payBarcode figcaption').textContent, text: pendingHub3 }; });
  ok(bc.hub3 === '1' && bc.src.startsWith('data:image/png') && /samo potvrdite/.test(bc.cap), 'barkod za ovu narudžbu nacrtan');
  fs.writeFileSync(OUT + 'hub3.png', Buffer.from(bc.src.split(',')[1], 'base64'));
  fs.writeFileSync(OUT + 'hub3.txt', bc.text);
  await pm.locator('#payBarcode').scrollIntoViewIfNeeded();
  await pm.screenshot({ path: OUT + 'm-06-barkod.png' });
  await pm.evaluate(() => document.querySelector('.drawer-body').scrollTop = 0);
  await pm.screenshot({ path: OUT + 'm-02-uplata.png' });


  // ---------- v29: statično ----------
  ok((await pm.getAttribute('meta[property="og:image:width"]', 'content')) === '1200' && (await pm.getAttribute('meta[property="og:image:height"]', 'content')) === '630' && /Torbe koje postoje/.test(await pm.getAttribute('meta[property="og:image:alt"]', 'content')), 'og:image širina, visina i opis');
  ok((await pm.textContent('label[for="fPhone"]')).trim() === 'Telefon' && /za dostavu ili dogovor preuzimanja/.test(await pm.textContent('#fPhoneHint')) && await pm.locator('fieldset.ship-field legend').count() === 1 && /pričekajte torbu/.test(await pm.textContent('#kako')), 'telefon, fieldset/legend, „pričekajte torbu”');
  ok(/nije u sustavu PDV-a/.test(await pm.textContent('#uvjeti')) && !/\{NAPOMENA/.test(await pm.textContent('#uvjeti')), 'v49: Uvjeti t. 3 — nije u sustavu PDV-a, bez {oznake}');
  ok(/bez nepotrebnog odgađanja/.test(await pm.textContent('#uvjeti')) && /gotovo u istom trenutku/.test(await pm.textContent('#uvjeti')) && /se unikatna torba/.test(await pm.textContent('#uvjeti')), 'Uvjeti: pravopis');
  const soldCol = await pd.locator('.card.sold .add-btn').first().evaluate(el => getComputedStyle(el).color).catch(() => '');
  ok(soldCol === 'rgb(112, 110, 102)', 'tekst „Prodano” na gumbu tamniji (kontrast 4,7:1): ' + soldCol);

  // ---------- v29: povećani prikaz bez praznih podataka ----------
  const pr = await d.newPage(); watch(pr);
  await pr.goto(URL + '#torba-remen', { waitUntil: 'networkidle' }); await pr.waitForTimeout(500);
  const rowsHidden = await pr.evaluate(() => ['lbDimsRow', 'lbMaterialRow', 'lbLeadRow'].every(id => document.getElementById(id).hidden));
  const specsHidden = await pr.locator('.lb-specs').isHidden();
  ok(rowsHidden === specsHidden, 'crtkana linija podataka samo kad ima podataka (redovi skriveni: ' + rowsHidden + ', linija skrivena: ' + specsHidden + ')');
  ok(!/ROK IZRADE/.test(await pr.textContent('#lightbox')), 'nepopunjen rok izrade se ne prikazuje');
  await pr.close();

  { /* v100 (recenzija vijeća v99): odbijanje 503 s porukom servisa nije „veza je prekinuta”; obavijest za prestare preglednike;
       datum bez prijeloma i u tekstu s poslužitelja; „Zakonsko jamstvo 2 godine” s razmakom na dodirnom zaslonu; traka ispod × */
    const p5 = await d.newPage(); watch(p5);
    await p5.goto(URL + '#ostalo', { waitUntil: 'networkidle' }); await p5.waitForTimeout(300);
    await p5.click('.card:has(h3:has(.card-name:text-is("Kozmetička torbica"))) .add-btn').catch(async () => { await p5.click('.card.made .add-btn'); });
    await p5.click('#openCart'); await p5.waitForTimeout(300);
    await p5.check('#shipOptions input[value="pickup"]');
    await p5.fill('#fName', 'Mreza Puna'); await p5.fill('#fEmail', 'mreza@primjer.hr'); await p5.fill('#fPhone', '091 555 7777'); await p5.check('#fConsent');
    await p5.route('**/api/order', route => route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Trenutačno primamo previše narudžbi odjednom. Pokušajte ponovno za sat vremena.' }) }));
    await pickStrap(p5); await p5.click('#orderBtn');
    await p5.waitForFunction(() => /Narudžba nije prihvaćena|Veza je prekinuta/.test(document.getElementById('orderError').textContent), null, { timeout: 8000 }).catch(() => {});
    const e503 = await p5.textContent('#orderError');
    ok(/Narudžba nije prihvaćena\. Trenutačno primamo previše narudžbi/.test(e503) && !/Veza je prekinuta/.test(e503) && !(await p5.isVisible('#retrySend')), 'v100 (kupac N1): 503 s porukom servisa — „Narudžba nije prihvaćena”, bez „Pošalji ponovno” ' + e503.slice(0, 90));
    await p5.unroute('**/api/order');
    const dt = await p5.evaluate(async () => { const el = document.createElement('p'); el.textContent = 'Torbu čuvamo do utorka, 24. 11. 2026. u 23:59. Što slijedi: u roku 1–2 radna dana, 10000 Zagreb. Šaljemo u 5 radnih dana, s 24. 11. 2026. u 10:00, i 21000 Split; pišite i ana-marija@primjer.hr'; document.body.appendChild(el); await new Promise(r => setTimeout(r, 50)); const out = [...el.querySelectorAll('.nw')].map(x => x.textContent); el.remove(); return out; });
    ok(dt.includes('24. 11. 2026. u 23:59') && dt.includes('1–2 radna dana') && dt.includes('10000 Zagreb') && dt.includes('u 5 radnih dana') && dt.includes('s 24. 11. 2026. u 10:00') && dt.includes('i 21000 Split') && !dt.some(x => /@/.test(x)), 'v100 (mobitel 6, dizajn N9): datum, raspon i poštanski broj u tekstu s poslužitelja bez prijeloma ' + JSON.stringify(dt));
    ok(await p5.evaluate(() => getComputedStyle(document.querySelector('.old-browser')).display === 'none' && document.documentElement.classList.contains('js-ok')), 'v100: obavijest za prestare preglednike se ne vidi kad skripta radi');
    await p5.close();
    /* stranica s namjerno pokvarenom skriptom (kao stari Safari) — obavijest se pojavi */
    const broken = fs.readFileSync(SITE + 'index.html', 'utf8').replace('<script>\n/* =========', '<script>\nconst = ;\n/* =========');
    fs.writeFileSync(SITE + 'stari-preglednik.html', broken);
    /* v101 (recenzija vijeća v100, softver B): bez tajmera i animacije — odmah nakon učitavanja, i uz „smanjeno kretanje” */
    const rm = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    const pb = await rm.newPage(); await pb.goto(URL + 'stari-preglednik.html', { waitUntil: 'load' });
    const vis = await pb.evaluate(() => { const o = document.querySelector('.old-browser'); return o ? getComputedStyle(o).visibility + '|' + getComputedStyle(o).display + '|' + document.documentElement.className.trim() : 'nema'; });
    ok(vis === 'visible|block|js-fail' && /e-mailom na shivaj\.handmade@gmail\.com/.test(await pb.textContent('.old-browser')), 'v100/v101: kad se skripta ne pokrene, obavijest s e-adresom i Instagramom vidi se odmah, i uz smanjeno kretanje ' + vis);
    await rm.close(); fs.unlinkSync(SITE + 'stari-preglednik.html');
    const mt = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }); const pm = await mt.newPage();
    await pm.goto(URL, { waitUntil: 'networkidle' }); await pm.waitForTimeout(300);
    await pm.locator('#productGrid .card:not(.made) .add-btn:not([disabled])').first().click(); await pm.waitForTimeout(200);
    await pm.evaluate(() => { if(!document.body.classList.contains('cart-open')) openCart(); }); await pm.waitForTimeout(400);
    const jam = await pm.evaluate(() => { const a = document.querySelector('.trust-line a[href="#jamstvo"]'); const r = a.getBoundingClientRect(); return [a.textContent, getComputedStyle(a).display, a.querySelectorAll('.nw').length, Math.round(r.width)]; });
    ok(/jamstvo 2\u00a0godine/.test(jam[0]) && /flex/.test(jam[1]) && jam[2] === 0, /* v101 (softver D): baš neprelomivi razmak — test više ne prolazi bez popravka */ 'v100 (mobitel 2): „Zakonsko jamstvo 2 godine” s razmakom i u flex poveznici ' + JSON.stringify(jam));
    await pm.evaluate(() => closeCart && closeCart(false)); await pm.waitForTimeout(300);
    await pm.locator('#productGrid .card .photo[data-view]').first().click(); await pm.waitForTimeout(500);
    const bar = await pm.evaluate(async () => { const p = document.querySelector('#lightbox .lb-panel'); const care = document.getElementById('lbCare'); if(care && !care.hidden) care.open = true; else { const f = document.createElement('div'); f.style.height = '1200px'; document.querySelector('#lightbox .lb-info').appendChild(f); } /* dugačak tekst kao uz otvorenu „Njegu torbe” */ const before = p.classList.contains('lb-scrolled'); p.scrollTop = p.scrollHeight; p.dispatchEvent(new Event('scroll')); await new Promise(r => setTimeout(r, 50)); return [before, p.classList.contains('lb-scrolled')]; });
    ok(bar[0] === false && bar[1] === true, 'v100 (mobitel 5): traka ispod × pojavi se tek kad fotografija prođe ' + JSON.stringify(bar));
    await mt.close(); }

  { /* v101 (recenzija vijeća v100): plaćena narudžba i starije ploče; vlastita starija narudžba; „Uplatio/la sam” i izjava bez poveznice na ploči;
       poveznica za raskid s ploče; stari link (withdrawn, mine); prazna košarica dok narudžba čeka; jednoslovna riječ uz span.nw; bez slobodnih unikata */
    const c1 = await browser.newContext({ viewport: { width: 1440, height: 900 } }); const pg = await c1.newPage(); watch(pg);
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(300);
    const mkLast = (no, id, name) => ({ at: Date.now(), items: [{ id, name, qty: 1, price: 65, made: false, cat: 'torbe' }], ship: { id: 'pickup', label: 'Osobno preuzimanje u radionici', price: 0 }, pay: null,
      srv: { orderNo: no, reference: no.replace('SJ-', ''), total: 65, until: new Date(Date.now() + 2 * 86400e3).toISOString(), payUntil: new Date(Date.now() + 86400e3).toISOString(), claimUntil: new Date(Date.now() + 2 * 86400e3).toISOString(),
        claimLink: '?uplata=' + no + '&t=' + 'a'.repeat(32), raskidLink: '#raskid?no=' + no.replace('SJ-', '') + '&t=' + 'b'.repeat(24), check: 'c'.repeat(24) } });
    /* 1) ploča: „Uplatio/la sam” zapamćen, izjava bez poveznice u zasebnom okviru iznad iznosa, poveznica za raskid s brojem */
    const pan = await pg.evaluate(L => { storeSet('cart', null); cart = []; storeSet('last', L); markClaimed(L.srv.orderNo, { state: 'extended', payUntil: new Date(Date.now() + 3 * 86400e3).toISOString(), message: 'Torbu čuvamo za vas do srijede.' });
      const l2 = storeGet('last'); l2.wdU = true; storeSet('last', l2); openCart(); renderPaymentPanel(storeGet('last'));
      const e = document.getElementById('emptyCart'), wdu = e.querySelector('.pay-wdu'), sum = e.querySelector('.pay-sum'), a = [...e.querySelectorAll('a')].find(x => /ovdje na stranici/.test(x.textContent));
      return { said: (e.querySelector('.pay-late .said') || {}).textContent || '', claimLinkShown: !!e.querySelector('.claim-link'), wduBefore: !!(wdu && sum && (wdu.compareDocumentPosition(sum) & 4)), wduInLate: /bez poveznice/.test((e.querySelector('.pay-late') || {}).textContent || ''), href: a ? a.getAttribute('href') : '' }; }, mkLast('SJ-2026-0101', 'luna', 'Luna'));
    ok(pan.said === 'Javili ste nam da ste uplatili.' && !pan.claimLinkShown && pan.wduBefore && !pan.wduInLate && pan.href === '#raskid?no=2026-0101&t=' + 'b'.repeat(24),
      'v101: ploča pamti „Uplatio/la sam”; izjava bez poveznice u zasebnom okviru iznad iznosa; „ovdje na stranici” nosi broj i oznaku ' + JSON.stringify(pan));
    await pg.locator('#emptyCart a', { hasText: 'ovdje na stranici' }).click();
    await pg.waitForFunction(() => !document.body.classList.contains('cart-open') && document.getElementById('rNo').value === 'SJ-2026-0101', null, { timeout: 4000 }).catch(() => {});
    ok(await pg.evaluate(() => !document.body.classList.contains('cart-open') && document.getElementById('rNo').value === 'SJ-2026-0101'), 'v101 (kupac N2): klik zatvara košaricu i otvara obrazac za raskid s brojem „SJ-…”');
    /* 1b) ploča je na zaslonu u trenutku izjave preko poveznice (ista kartica) — odmah nestaje „Uplatite” (dizajn 6) */
    const gone = await pg.evaluate(L => { storeSet('last', L); openCart(); renderPaymentPanel(L); const before = /Uplatite/.test(document.getElementById('emptyCart').textContent);
      const l = storeGet('last'); l.withdrawn = true; storeSet('last', l); lastStateChanged(); const t = document.getElementById('emptyCart').textContent; closeCart(false); return [before, /Uplatite/.test(t), /izjavu o raskidu ugovora; ne trebate je platiti/.test(t)]; }, mkLast('SJ-2026-0104', 'luna', 'Luna'));
    ok(gone[0] && !gone[1] && gone[2], 'v101 (dizajn 6): izjava o raskidu dok je ploča na zaslonu — „Uplatite” nestaje bez osvježavanja ' + JSON.stringify(gone));
    /* recenzija v101: ploča na zaslonu kad stigne „izjava bez poveznice” (okvir se pojavi) i kad stigne „plaćeno” (ploča nestaje) */
    const st2 = await pg.evaluate(L => { storeSet('last', L); openCart(); renderPaymentPanel(L); const e = document.getElementById('emptyCart'); const a = !!e.querySelector('.pay-wdu');
      let l = storeGet('last'); l.wdU = true; storeSet('last', l); lastStateChanged(); const b = !!e.querySelector('.pay-wdu') && e.classList.contains('pay-mode');
      l = storeGet('last'); l.paid = true; storeSet('last', l); lastStateChanged(); const c = e.classList.contains('pay-mode') || /Uplatite/.test(e.textContent); closeCart(false); return [a, b, c]; }, mkLast('SJ-2026-0105', 'luna', 'Luna'));
    ok(!st2[0] && st2[1] && !st2[2], 'recenzija v101: ploča na zaslonu — okvir „izjava bez poveznice” se pojavi, a nakon „plaćeno” ploča nestaje ' + JSON.stringify(st2));
    /* recenzija v101: „vaša” torba samo dok narudžba traje — ne nakon izjave o raskidu ni nakon isteka roka starije narudžbe */
    const own3 = await pg.evaluate(([L, P]) => { const r = []; storeSet('lastPrev', null); storeSet('own', null); storeSet('last', L); r.push(ownOrderOf('luna'));
      const l = storeGet('last'); l.withdrawn = true; storeSet('last', l); r.push(ownOrderOf('luna'));
      storeSet('own', { at: Date.now(), ids: ['luna'], no: L.srv.orderNo }); r.push(ownOrderOf('luna')); storeSet('own', null); storeSet('last', null);
      storeSet('lastPrev', [P]); r.push(ownOrderOf('tara'));
      P.at = Date.now() - 2 * 86400e3; P.srv.payUntil = new Date(Date.now() - 3600e3).toISOString(); storeSet('lastPrev', [P]); r.push(ownOrderOf('tara'));
      P.at = Date.now(); P.withdrawn = true; storeSet('lastPrev', [P]); r.push(ownOrderOf('tara')); storeSet('lastPrev', null); return r; }, [mkLast('SJ-2026-0106', 'luna', 'Luna'), mkLast('SJ-2026-0107', 'tara', 'Tara')]);
    ok(JSON.stringify(own3) === JSON.stringify(['SJ-2026-0106', '', '', 'SJ-2026-0107', '', '']), 'recenzija v101: torba je „vaša” samo dok narudžba traje (raskid, istek roka starije narudžbe → nije) ' + JSON.stringify(own3));
    /* recenzija v101: ista narudžba potvrđena ponovno — ploča zadržava što već zna; druga narudžba ne nasljeđuje ništa */
    const carry = await pg.evaluate(L => { Object.assign(L, { claimed: true, claimedUntil: new Date(Date.now() + 3 * 86400e3).toISOString(), claimMsg: 'Torbu čuvamo za vas.', wdU: true }); storeSet('last', L);
      const srv = no => ({ ok: true, orderNo: no, reference: no.replace('SJ-', ''), total: 65, until: L.srv.until, payUntil: L.srv.payUntil, items: [{ id: 'luna', name: 'Luna', qty: 1, price: 65, made: false }], ship: { id: 'pickup', label: 'Osobno preuzimanje u radionici', price: 0 } });
      showOrderSuccess(srv(L.srv.orderNo)); const a = storeGet('last'); const r = [a.claimed === true, a.claimMsg, a.wdU === true, a.srv.claimLink === L.srv.claimLink, a.srv.raskidLink === L.srv.raskidLink, a.srv.check === L.srv.check, (storeGet('lastPrev') || []).length];
      showOrderSuccess(srv('SJ-2026-0109')); const b = storeGet('last'); r.push(b.claimed === undefined && b.wdU === undefined && !b.srv.claimLink, (storeGet('lastPrev') || []).map(x => x.srv.orderNo).join());
      PRODUCTS.find(x => x.id === 'luna').reserved = false; storeSet('last', null); storeSet('lastPrev', null); document.getElementById('emptyCart').classList.remove('pay-mode'); renderCart(); closeCart(false); return r; }, mkLast('SJ-2026-0108', 'luna', 'Luna'));
    ok(JSON.stringify(carry) === JSON.stringify([true, 'Torbu čuvamo za vas.', true, true, true, true, 0, true, 'SJ-2026-0108']), 'recenzija v101: ponovljena potvrda iste narudžbe zadržava „Uplatio/la sam”, izjavu bez poveznice i poveznice; nova narudžba ne nasljeđuje ' + JSON.stringify(carry));
    /* 2) servis javi „paid” za zadnju i „cancelled” za stariju narudžbu — ploča više ne nudi uplatu; vlastita starija narudžba nije „drugog kupca” */
    await pg.evaluate(([L, P]) => { storeSet('last', L); storeSet('lastPrev', [Object.assign(P, { open: true })]); }, [mkLast('SJ-2026-0102', 'luna', 'Luna'), mkLast('SJ-2026-0103', 'tara', 'Tara')]);
    const ownPrev = await pg.evaluate(() => { const p = PRODUCTS.find(x => x.id === 'tara'); p.srvReserved = true; openLightbox('tara'); const t = document.getElementById('lbGoneText').textContent; closeLightbox(); p.srvReserved = false; return t; });
    ok(/rezervirana za vas \(narudžba SJ-2026-0103\)/.test(ownPrev) && !/drugog kupca/.test(ownPrev), 'v101 (kupac N1): torba iz starije vlastite narudžbe — „rezervirana za vas”, ne „za drugog kupca” ' + ownPrev.slice(0, 70));
    let sawPrev = '';
    await pg.route('**/api/status*', async route => { try { const u = route.request().url(); if (/prev=/.test(u)) sawPrev = decodeURIComponent(u); const rsp = await route.fetch(); const j = await rsp.json(); await route.fulfill({ response: rsp, json: Object.assign(j, { orderState: 'paid', prevStates: { 'SJ-2026-0103': 'cancelled' } }) }); } catch (e) { /* stranica je otišla dalje */ } });
    await pg.goto(URL + '?v101=1', { waitUntil: 'networkidle' }); /* pravo učitavanje (promjena samo sidra ne pita servis) */
    await pg.waitForFunction(() => { const l = storeGet('last'); return l && l.paid; }, null, { timeout: 5000 }).catch(() => {});
    const paid = await pg.evaluate(() => { openCart(); const e = document.getElementById('emptyCart'); return { paid: !!(storeGet('last') || {}).paid, prev: (storeGet('lastPrev') || []).length, btn: !!e.querySelector('#showLast, .last-order'), text: e.textContent.slice(0, 80) }; });
    ok(paid.paid && paid.prev === 0 && !paid.btn && /stanje=SJ-2026-0102/.test(sawPrev) && /prev=SJ-2026-0103:/.test(sawPrev), 'v101 (softver A): plaćena zadnja i stornirana starija narudžba — bez gumba „Podaci za uplatu” ' + JSON.stringify(paid));
    /* recenzija v101: oznaka „plaćeno” nije trajna — servis javi „open” (vlasnica poništila evidentiranu uplatu), ploča opet nudi podatke za uplatu */
    await pg.unroute('**/api/status*');
    await pg.route('**/api/status*', async route => { try { const rsp = await route.fetch(); const j = await rsp.json(); await route.fulfill({ response: rsp, json: Object.assign(j, { orderState: 'open' }) }); } catch (e) { /* stranica je otišla dalje */ } });
    await pg.goto(URL + '?v101=2', { waitUntil: 'networkidle' });
    await pg.waitForFunction(() => { const l = storeGet('last'); return l && !l.paid; }, null, { timeout: 5000 }).catch(() => {});
    ok(await pg.evaluate(() => { const l = storeGet('last'); return !!l && !l.paid && !!lastValid(); }), 'recenzija v101: nakon „open” oznaka plaćeno se briše, podaci za uplatu su opet dostupni');
    await pg.unroute('**/api/status*'); await pg.evaluate(() => { storeSet('last', null); storeSet('lastPrev', null); closeCart(false); });
    /* 3) stari link za potvrdu: raskid → obavijest; „mine” → torba izlazi iz košarice */
    await pg.route('**/api/confirm', route => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'withdrawn', error: 'Za ovu narudžbu primili smo vašu izjavu o raskidu ugovora, pa je ne trebate platiti.' }) }));
    await pg.goto(URL + '?potvrda=' + 'a'.repeat(32) + '.' + 'b'.repeat(32), { waitUntil: 'networkidle' });
    await pg.waitForFunction(() => /Izjava o raskidu je zaprimljena/.test(document.getElementById('emptyCart').textContent), null, { timeout: 6000 }).catch(() => {});
    const wdBox = await pg.evaluate(() => { const b = document.querySelector('#emptyCart .order-error'); return b ? [b.className, b.getAttribute('role'), b.textContent.slice(0, 60)] : []; });
    ok(/order-info/.test(wdBox[0] || '') && wdBox[1] === 'status' && /Izjava o raskidu je zaprimljena\./.test(wdBox[2] || ''), 'v100/v101: stari link nakon raskida — obavijest „Izjava o raskidu je zaprimljena”, ne greška ' + JSON.stringify(wdBox));
    await pg.unroute('**/api/confirm');
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    const freeId = await pg.evaluate(() => { const p = PRODUCTS.find(x => !x.made && !isGone(x) && !noPhoto(x)); storeSet('cart', [{ id: p.id, qty: 1, note: '' }]); return p.id; });
    await pg.route('**/api/confirm', route => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'mine', mine: [freeId], error: 'Torba „X” već je u vašoj ranijoj narudžbi; podatke za uplatu poslali smo vam e-mailom.' }) }));
    await pg.goto(URL + '?potvrda=' + 'c'.repeat(32) + '.' + 'd'.repeat(32), { waitUntil: 'networkidle' });
    await pg.waitForFunction(() => /Ova je torba već vaša/.test(document.querySelector('.drawer-body').textContent), null, { timeout: 6000 }).catch(() => {});
    const mine = await pg.evaluate(id => ({ inCart: cart.some(c => c.id === id), txt: /Ova je torba već vaša/.test(document.querySelector('.drawer-body').textContent) }), freeId);
    ok(!mine.inCart && mine.txt, 'v100/v101 (kupac 6): „mine” — torba izlazi iz košarice uz „Ova torba je već vaša” ' + JSON.stringify(mine));
    await pg.unroute('**/api/confirm'); await pg.evaluate(() => { storeSet('own', null); });
    /* 4) prazna košarica dok narudžba čeka potvrdu */
    const pendTxt = await pg.evaluate(() => { cart = []; saveCart(); document.getElementById('emptyCart').classList.remove('pay-mode'); /* obavijest od „mine” je u načinu ploče */ storeSet('pend', { until: new Date(Date.now() + 3600e3).toISOString(), sig: 'x', ids: [], all: [] }); renderCart(); const t = document.getElementById('emptyCart').textContent; storeSet('pend', null); renderCart(); return [t, document.getElementById('emptyCart').textContent]; });
    ok(/Narudžba koju ste poslali nije otkazana\./.test(pendTxt[0]) && /nemojte je potvrditi/.test(pendTxt[0]) && !/nije otkazana/.test(pendTxt[1]), 'v101 (kupac N3): prazna košarica dok narudžba čeka potvrdu — obavijest da link i dalje vrijedi');
    /* 5) jednoslovna riječ ispred postojećeg span.nw; e-adresa u okviru kao inline-block */
    const glue = await pg.evaluate(async () => { const el = document.createElement('p'); el.innerHTML = 'poveznica je i u <span class="nw">e-mailu</span>, a <span class="nw">e-mailom</span> šaljemo'; document.body.appendChild(el); await new Promise(r => setTimeout(r, 60));
      const out = [...el.querySelectorAll('span.nw')].filter(x => /^e-mail/.test(x.textContent)).map(x => (x.previousSibling.nodeValue || '').slice(-1)); el.remove();
      /* v102 (recenzija vijeća v101, dizajn 3 / mobitel 5): adresa od dva neprelomiva dijela, prijelom samo ispred „@”, točka ostaje uz adresu (uski okvir) */
      const b = document.createElement('div'); b.className = 'order-error'; b.style.cssText = 'width:200px;font-size:16px'; b.innerHTML = 'Poslali smo e-mail na ' + addrHtml('marija.horvat-kovacevic@primjer.hr', '.') + ' Otvorite ga.'; document.body.appendChild(b);
      const sp = [...b.querySelectorAll('.addr .nw')], rc = sp.map(x => x.getClientRects().length), wb = !!b.querySelector('.addr wbr'), lastTxt = sp.length ? sp[sp.length - 1].textContent : '';
      const long = addrHtml('a'.repeat(30) + '@primjer.hr'); b.remove(); return [out, [sp.length, rc.join(), wb, lastTxt, /class="nw"/.test(long)]]; });
    ok(glue[0].length === 2 && glue[0].every(c => c === '\u00a0') && JSON.stringify(glue[1]) === JSON.stringify([2, '1,1', true, '@primjer.hr.', false]), 'v101/v102 (dizajn 3 i 9): jednoslovna riječ ostaje uz „e-mailu”; e-adresa se lomi samo ispred „@”, točka ostaje uz nju ' + JSON.stringify(glue));
    /* 6) bez slobodnih unikata: gumb i poziv na naslovnici vode u Stalnu kolekciju */
    const noFree = await pg.evaluate(() => { const un = PRODUCTS.filter(x => !x.made); un.forEach(p => { p.srvReserved = true; }); renderHero(); openLightbox(un[0].id); const mb = document.getElementById('lbMore'), cta = document.querySelector('.hero-cta');
      const out = [mb.textContent, mb.dataset.go, cta.textContent, cta.getAttribute('href')]; closeLightbox(); un.forEach(p => { p.srvReserved = false; }); renderHero(); return out.concat([document.querySelector('.hero-cta').getAttribute('href')]); });
    ok(noFree[0] === 'Pogledaj Stalnu kolekciju' && noFree[1] === 'kolekcija' && /Stalnu kolekciju/.test(noFree[2]) && noFree[3] === '#kolekcija' && noFree[4] === '#torbe', 'v101 (kupac N6): bez slobodnih unikata gumb i poziv vode u Stalnu kolekciju ' + JSON.stringify(noFree));
    await c1.close(); }

  { /* v102 (recenzija vijeća v101): polje s greškom vidljivo na mobitelu; starije ploče prate raskid; obavijest za plaćenu narudžbu; oznaka storna
       nije trajna; stanje i pri otvaranju košarice; druga izjava o raskidu u istoj kartici; 503/429 dok narudžba čeka; „mine” u množini; greška na
       izboru remena; broj narudžbe se ne lomi; okrnjen link za potvrdu; istekla oznaka čekanja; prazna košarica bez slobodnih unikata */
    const mkLast = (no, id, name) => ({ at: Date.now(), items: [{ id, name, qty: 1, price: 65, made: false, cat: 'torbe' }], ship: { id: 'pickup', label: 'Osobno preuzimanje u radionici', price: 0 }, pay: null,
      srv: { orderNo: no, reference: no.replace('SJ-', ''), total: 65, until: new Date(Date.now() + 2 * 86400e3).toISOString(), payUntil: new Date(Date.now() + 86400e3).toISOString(), claimUntil: new Date(Date.now() + 2 * 86400e3).toISOString(),
        claimLink: '?uplata=' + no + '&t=' + 'a'.repeat(32), raskidLink: '#raskid?no=' + no.replace('SJ-', '') + '&t=' + 'b'.repeat(24), check: 'c'.repeat(24) } });
    /* A) mobitel: nakon slanja s greškom polje koje treba ispraviti je u vidnom polju košarice (v101 ga je odnosio izvan zaslona) */
    const vis = [];
    for (const [w, h] of [[390, 844], [320, 568], [844, 390]]) {
      const cx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: true, hasTouch: true }); const pm = await cx.newPage(); watch(pm);
      await pm.goto(URL, { waitUntil: 'networkidle' });
      await pm.evaluate(() => { const p = PRODUCTS.find(x => !x.made && !isGone(x) && !noPhoto(x)); toggleCartItem(p.id); openCart(); });
      await pm.waitForSelector('#fName', { state: 'visible', timeout: 5000 }).catch(() => {});
      await pm.check('#shipOptions input[value="gls"]');
      await pm.fill('#fName', 'Mob Kupac'); await pm.fill('#fEmail', 'mob102@primjer.hr'); await pm.fill('#fPhone', '091 234 5678');
      const inView = id => pm.evaluate(i => { const sc = document.querySelector('.drawer-body').getBoundingClientRect(), r = document.getElementById(i).getBoundingClientRect(); return r.top >= sc.top - 1 && r.bottom <= sc.bottom + 1; }, id);
      await pm.evaluate(() => { document.querySelector('.drawer-body').scrollTop = 99999; }); await pm.click('#orderBtn'); await pm.waitForTimeout(500);
      const addrIn = await inView('fAddr'), fixTxt = await pm.textContent('#orderFix');
      await pm.fill('#fAddr', 'Ilica 1'); await pm.fill('#fZip', '10000'); await pm.fill('#fCity', 'Zagreb');
      await pm.evaluate(() => { document.querySelector('.drawer-body').scrollTop = 99999; }); await pm.click('#orderBtn'); await pm.waitForTimeout(500);
      vis.push([w, addrIn, await inView('fConsent'), /ulica i broj/.test(fixTxt)]);
      await cx.close();
    }
    ok(vis.every(v => v[1] && v[2] && v[3]), 'v102 (mobitel 2): nakon slanja s greškom polje adrese, pa privola, ostaju u vidnom polju košarice (390×844, 320×568, položeno) ' + JSON.stringify(vis));
    const c2 = await browser.newContext({ viewport: { width: 1440, height: 900 } }); const pg = await c2.newPage(); watch(pg);
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(300);
    /* B) ploča STARIJE narudžbe na zaslonu: izjava bez poveznice → okvir; izjava preko poveznice → ploča nestaje */
    const prevP = await pg.evaluate(([L, P]) => { storeSet('cart', null); cart = []; storeSet('last', L); storeSet('lastPrev', [Object.assign(P, { open: true })]); openCart(); renderCart();
      document.querySelector('.last-order[data-prev="SJ-2026-0202"]').click(); const e = document.getElementById('emptyCart'); const r = [(e.querySelector('.pay-no') || {}).textContent || ''];
      let lp = storeGet('lastPrev'); lp[0].wdU = true; storeSet('lastPrev', lp); lastStateChanged(); r.push(!!e.querySelector('.pay-wdu') && /SJ-2026-0202/.test((e.querySelector('.pay-no') || {}).textContent || ''));
      lp = storeGet('lastPrev'); lp[0].withdrawn = true; storeSet('lastPrev', lp); lastStateChanged(); r.push(e.classList.contains('pay-mode'), /Uplatite/.test(e.textContent), !!e.querySelector('#showLast')); closeCart(false); return r; },
      [mkLast('SJ-2026-0201', 'luna', 'Luna'), mkLast('SJ-2026-0202', 'tara', 'Tara')]);
    ok(/SJ-2026-0202/.test(prevP[0]) && prevP[1] && !prevP[2] && !prevP[3] && prevP[4], 'v102 (kupac N4): ploča starije narudžbe — okvir „izjava bez poveznice” se pojavi, nakon raskida ploča nestaje, zadnja narudžba ostaje ' + JSON.stringify(prevP));
    /* C) cijeli put: izjava o raskidu za stariju narudžbu u istoj kartici označi je u lastPrev; druga izjava vraća obrazac na prvi korak */
    await pg.evaluate(([L, P]) => { storeSet('last', L); storeSet('lastPrev', [Object.assign(P, { open: true })]); }, [mkLast('SJ-2026-0203', 'luna', 'Luna'), mkLast('SJ-2026-0204', 'tara', 'Tara')]);
    await pg.route('**/api/raskid', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, at: '22. 11. 2026. u 20:00' }) }));
    await pg.evaluate(() => { PRODUCTS.find(x => x.id === 'tara').reserved = true; location.hash = '#raskid?no=2026-0204&t=' + 'b'.repeat(24); }); await pg.waitForTimeout(300);
    await pg.fill('#rName', 'Ana Kupac'); await pg.fill('#rEmail', 'ana102@primjer.hr'); await pg.click('#raskidNext'); await pg.waitForTimeout(200);
    await pg.locator('#raskidStep2 button[type="button"]:not(#raskidBack), #raskidStep2 button[type="submit"]').first().click(); await pg.waitForFunction(() => !document.getElementById('raskidDone').hidden, null, { timeout: 5000 }).catch(() => {});
    await pg.waitForTimeout(200);
    const rk1 = await pg.evaluate(() => ({ done: !document.getElementById('raskidDone').hidden, wd: !!((storeGet('lastPrev') || [])[0] || {}).withdrawn, lastWd: !!(storeGet('last') || {}).withdrawn, semi: /@primjer\.hr;$/.test((document.querySelector('#raskidDone .addr .nw:last-child') || {}).textContent || '') }));
    await pg.evaluate(() => { location.hash = '#raskid?no=2026-0203&t=' + 'b'.repeat(24); }); await pg.waitForTimeout(300);
    const rk2 = await pg.evaluate(() => ({ done: !document.getElementById('raskidDone').hidden, s1: !document.getElementById('raskidStep1').hidden, no: document.getElementById('rNo').value, name: document.getElementById('rName').value, email: document.getElementById('rEmail').value, resv: !!PRODUCTS.find(x => x.id === 'tara').reserved }));
    /* provjera v102 (krug 1, kupac N1): druga izjava se stvarno može poslati (gumb nije ostao onemogućen) */
    await pg.click('#raskidNext'); await pg.waitForTimeout(200);
    const btn2 = await pg.evaluate(() => document.getElementById('raskidConfirm').disabled);
    await pg.click('#raskidConfirm').catch(() => {}); await pg.waitForFunction(() => !document.getElementById('raskidDone').hidden, null, { timeout: 5000 }).catch(() => {});
    const rk3 = await pg.evaluate(() => ({ done: !document.getElementById('raskidDone').hidden, lastWd: !!(storeGet('last') || {}).withdrawn }));
    ok(!btn2 && rk3.done && rk3.lastWd && !rk2.resv, 'provjera v102 (kupac N1, softver N4): druga izjava o raskidu u istoj kartici se šalje; torba prve više nije lokalno „rezervirana” ' + JSON.stringify([btn2, rk3, rk2.resv]));
    await pg.unroute('**/api/raskid');
    ok(rk1.done && rk1.wd && !rk1.lastWd && rk1.semi && !rk2.done && rk2.s1 && rk2.no === 'SJ-2026-0203' && rk2.name === 'Ana Kupac' && rk2.email === 'ana102@primjer.hr',
      'v102 (kupac N1/N4): izjava za stariju narudžbu označi nju (ne zadnju); druga poveznica za raskid vraća obrazac na prvi korak s novim brojem ' + JSON.stringify([rk1, rk2]));
    await pg.evaluate(() => { history.replaceState(null, '', location.pathname); storeSet('last', null); storeSet('lastPrev', null); window.scrollTo(0, 0); });
    /* D) plaćena narudžba: obavijest umjesto tihog nestanka; E) oznaka storna nije trajna; F) stanje se pita i pri otvaranju košarice (najviše jednom u minuti) */
    const paidN = await pg.evaluate(L => { L.paid = true; storeSet('last', L); openCart(); renderCart(); const t = document.getElementById('emptyCart').textContent; closeCart(false); return t; }, mkLast('SJ-2026-0205', 'luna', 'Luna'));
    ok(/Uplatu za narudžbu SJ-2026-0205 već smo zabilježili; nemojte plaćati ponovno\./.test(paidN) && !/Podaci za uplatu narudžbe SJ-2026-0205/.test(paidN), 'v102 (mobitel 4): plaćena narudžba — obavijest u košarici, bez gumba za uplatu ' + paidN.slice(0, 80));
    await pg.evaluate(L => { L.cancelled = true; storeSet('last', L); }, mkLast('SJ-2026-0206', 'luna', 'Luna'));
    let asked = [];
    await pg.route('**/api/status*', async route => { try { const u = decodeURIComponent(route.request().url()); asked.push(/stanje=SJ-2026-0206/.test(u)); const rsp = await route.fetch(); const j = await rsp.json(); await route.fulfill({ response: rsp, json: Object.assign(j, { orderState: 'paid' }) }); } catch (e) { /* stranica je otišla dalje */ } });
    await pg.goto(URL + '?v102=1', { waitUntil: 'networkidle' });
    await pg.waitForFunction(() => { const l = storeGet('last'); return l && l.paid && !l.cancelled; }, null, { timeout: 5000 }).catch(() => {});
    const unc = await pg.evaluate(() => { const l = storeGet('last') || {}; return [!!l.paid, !!l.cancelled]; });
    ok(asked.some(Boolean) && unc[0] && !unc[1], 'v102 (softver N1): stornirana ploča i dalje pita stanje; nakon „paid” oznaka storna se briše ' + JSON.stringify([asked, unc]));
    asked = [];
    await pg.evaluate(() => { const l = storeGet('last'); delete l.paid; storeSet('last', l); stateAsked = 0; openCart(); }); await pg.waitForTimeout(700);
    const a1 = asked.slice(); asked = [];
    await pg.evaluate(() => { closeCart(false); openCart(); }); await pg.waitForTimeout(700);
    const a2 = asked.slice(); await pg.evaluate(() => closeCart(false));
    ok(a1.some(Boolean) && a2.length > 0 && !a2.some(Boolean), 'v102 (softver N3): otvaranje košarice pita i stanje narudžbe, ali najviše jednom u minuti ' + JSON.stringify([a1, a2]));
    await pg.unroute('**/api/status*'); await pg.evaluate(() => { storeSet('last', null); storeSet('lastPrev', null); });
    /* G) „mine” za dvije torbe: naslov u množini */
    await pg.route('**/api/confirm', route => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'mine', mine: ['vela', 'nera'], error: 'Torbe „Vela” i „Nera” već su u vašoj ranijoj narudžbi; podatke za uplatu poslali smo vam e-mailom.' }) }));
    await pg.goto(URL + '?potvrda=' + 'e'.repeat(32) + '.' + 'f'.repeat(32), { waitUntil: 'networkidle' });
    await pg.waitForFunction(() => /već vaš/.test(document.querySelector('.drawer-body').textContent), null, { timeout: 6000 }).catch(() => {});
    const mine2 = await pg.textContent('.drawer-body');
    await pg.unroute('**/api/confirm'); await pg.evaluate(() => { storeSet('own', null); });
    /* H) okrnjen link za potvrdu daje poruku */
    await pg.goto(URL + '?potvrda=abc', { waitUntil: 'networkidle' }); await pg.waitForTimeout(300);
    const trunc = await pg.evaluate(() => [document.querySelector('.drawer-body').textContent, location.search]);
    ok(/Ove su torbe već vaše\./.test(mine2) && /Link za potvrdu nije potpun\./.test(trunc[0]) && trunc[1] === '', 'v102 (dizajn 1, kupac N8): „Ove torbe su već vaše.” za dvije torbe; okrnjen link za potvrdu daje jasnu poruku ' + JSON.stringify([/Ove torbe/.test(mine2), trunc[1]]));
    /* I) izbor remena: greška na samom izboru (aria-invalid, poruka), nestaje nakon odabira */
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    await pg.evaluate(() => { document.getElementById('emptyCart').classList.remove('pay-mode'); const p = PRODUCTS.find(x => x.made && catOf(x) === 'kolekcija' && !isGone(x)); cart = []; saveCart(); toggleCartItem(p.id); openCart(); });
    await pg.waitForSelector('.strap-sel', { timeout: 4000 }).catch(() => {});
    await pg.check('#shipOptions input[value="pickup"]'); await pg.fill('#fName', 'Remen Test'); await pg.fill('#fEmail', 'remen102@primjer.hr'); await pg.fill('#fPhone', '091 234 5678'); await pg.check('#fConsent');
    await pg.click('#orderBtn'); await pg.waitForTimeout(400);
    const st1 = await pg.evaluate(() => { const s = [...document.querySelectorAll('.strap-sel')]; return [s.map(x => x.getAttribute('aria-invalid')), !!document.querySelector('.strap-row .strap-err'), getComputedStyle(s[0]).borderTopColor]; });
    await pickStrap(pg); await pg.waitForTimeout(200);
    const st2 = await pg.evaluate(() => [...document.querySelectorAll('.strap-sel')].map(x => x.getAttribute('aria-invalid')).concat(document.querySelector('.strap-err') ? ['poruka ostala'] : []));
    ok(st1[0].every(x => x === 'true') && st1[1] && st1[2] === 'rgb(155, 44, 31)' && st2.every(x => x === null), 'v102 (dizajn 8): bez odabranog remena — crveni obrub, aria-invalid i poruka na samom izboru; nakon odabira oznaka nestaje ' + JSON.stringify([st1, st2]));
    /* J) privola: vidljivo stanje greške */
    await pg.uncheck('#fConsent'); await pg.click('#orderBtn'); await pg.waitForTimeout(300);
    const cons = await pg.evaluate(() => { const c = document.getElementById('fConsent'); return [c.getAttribute('aria-invalid'), getComputedStyle(c).outlineColor, getComputedStyle(c).outlineStyle]; });
    ok(cons[0] === 'true' && cons[1] === 'rgb(155, 44, 31)' && cons[2] === 'solid', 'v102 (dizajn 8): neoznačena privola ima vidljiv crveni obrub ' + JSON.stringify(cons));
    /* J2) ključ narudžbe bez crypto.randomUUID (iOS < 15.4): 32 heksadekadske znamenke iz getRandomValues */
    let sentKey = '';
    await pg.route('**/api/order', route => { try { sentKey = JSON.parse(route.request().postData() || '{}')._order.key; } catch (e) {} route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'proba' }) }); });
    await pg.evaluate(() => { Object.defineProperty(crypto, 'randomUUID', { value: undefined, configurable: true }); storeSet('okey', null); });
    await pg.check('#fConsent'); await pg.click('#orderBtn'); await pg.waitForFunction(() => !document.getElementById('orderError').hidden, null, { timeout: 8000 }).catch(() => {});
    await pg.unroute('**/api/order');
    ok(/^[0-9a-f]{32}$/.test(sentKey), 'v101/v102 (sigurnost S3): bez crypto.randomUUID ključ narudžbe je 32 heksadekadske znamenke ' + JSON.stringify(sentKey));
    /* J3) grane koje dosad nisu imale test: markClaimed za „withdrawn”/„paid”, prevStates „open-wdu”, pendState „limited” */
    const mc = await pg.evaluate(([L, P]) => { storeSet('last', L); storeSet('lastPrev', [Object.assign(P, { open: true })]); markClaimed(L.srv.orderNo, { state: 'withdrawn' }); markClaimed(P.srv.orderNo, { state: 'paid' });
      const a = storeGet('last'), b = storeGet('lastPrev')[0]; return [a.withdrawn === true, a.claimed === undefined, b.paid === true, b.claimed === undefined]; }, [mkLast('SJ-2026-0207', 'luna', 'Luna'), mkLast('SJ-2026-0208', 'tara', 'Tara')]);
    await pg.evaluate(([L, P]) => { storeSet('last', L); storeSet('lastPrev', [Object.assign(P, { open: true })]); storeSet('pend', { until: new Date(Date.now() + 3600e3).toISOString(), sig: 'x', ids: [], all: [], pt: 'a'.repeat(24), stale: true }); }, [mkLast('SJ-2026-0209', 'luna', 'Luna'), mkLast('SJ-2026-0210', 'tara', 'Tara')]);
    let pendState = 'limited';
    await pg.route('**/api/status*', async route => { try { const rsp = await route.fetch(); const j = await rsp.json(); await route.fulfill({ response: rsp, json: Object.assign(j, { orderState: 'open', prevStates: { 'SJ-2026-0210': 'open-wdu' }, pendState }) }); } catch (e) { /* stranica je otišla dalje */ } });
    await pg.goto(URL + '?v102=3', { waitUntil: 'networkidle' }); await pg.waitForFunction(() => ((storeGet('lastPrev') || [])[0] || {}).wdU === true, null, { timeout: 5000 }).catch(() => {});
    const lim = await pg.evaluate(() => [((storeGet('lastPrev') || [])[0] || {}).wdU === true, !!storeGet('pend')]);
    pendState = 'waiting';
    await pg.goto(URL + '?v102=4', { waitUntil: 'networkidle' }); await pg.waitForFunction(() => !storeGet('pend'), null, { timeout: 5000 }).catch(() => {});
    const lim2 = await pg.evaluate(() => !storeGet('pend'));
    await pg.unroute('**/api/status*'); await pg.evaluate(() => { storeSet('last', null); storeSet('lastPrev', null); storeSet('pend', null); });
    ok(mc.every(Boolean) && lim[0] && lim[1] && lim2, 'v102 (softver N8): markClaimed za „withdrawn”/„paid” ne bilježi klik; „open-wdu” označi stariju ploču; oznaka čekanja ostaje uz „limited”, briše se uz „waiting” ' + JSON.stringify([mc, lim, lim2]));
    await pg.evaluate(() => { cart = []; saveCart(); renderCart(); closeCart(false); });
    /* K) broj narudžbe se ne lomi; podcrta poveznica zasebnim svojstvima; istekla oznaka čekanja; prazna košarica bez slobodnih unikata */
    const misc = await pg.evaluate(async () => { const el = document.createElement('p'); el.textContent = 'Torba je rezervirana za vas (narudžba SJ-2026-0002).'; document.body.appendChild(el); await new Promise(r => setTimeout(r, 80));
      const nw = [...el.querySelectorAll('span.nw')].map(x => x.textContent); el.remove();
      const a = document.querySelector('.foot-links a'), cs = getComputedStyle(a);
      storeSet('pend', { until: new Date(Date.now() - 3 * 86400e3).toISOString(), sig: 'x', ids: [], all: [], pt: 'a'.repeat(24) }); showPendingAfterReload(); const pendGone = storeGet('pend') === null || storeGet('pend') === undefined;
      const keep = PRODUCTS.filter(x => !x.made).map(x => [x, x.sold]); keep.forEach(([x]) => { x.sold = true; }); renderHero(); renderCart(); openCart();
      const eg = document.querySelector('#emptyCart .empty-go'), r1 = [eg.textContent, eg.getAttribute('href')];
      keep.forEach(([x, s]) => { x.sold = s; }); renderHero(); renderCart(); const r2 = [eg.isConnected ? eg.textContent : document.querySelector('#emptyCart .empty-go').textContent, document.querySelector('#emptyCart .empty-go').getAttribute('href')]; closeCart(false);
      return [nw, cs.textDecorationLine, cs.textDecorationStyle, pendGone, r1, r2]; });
    ok(misc[0].includes('SJ-2026-0002') && misc[1] === 'underline' && misc[2] === 'dashed' && misc[3] && misc[4].join() === 'Pogledaj Stalnu kolekciju,#kolekcija' && misc[5].join() === 'Pogledaj torbe,#torbe',
      'v102: broj narudžbe u neprelomivom rasponu; podcrta poveznica isprekidana; istekla oznaka čekanja briše se i uz praznu košaricu; prazna košarica bez slobodnih unikata vodi na Stalnu kolekciju ' + JSON.stringify(misc));
    /* L) provjera v102, krug 1: potvrda gumbom — rubovi; druga kartica; povratak u karticu; „već javljeno” na drugom uređaju */
    await pg.evaluate(() => { storeSet('last', null); storeSet('lastPrev', null); storeSet('pend', { until: new Date(Date.now() + 3600e3).toISOString(), sig: 'x', ids: [], all: [], pt: 'a'.repeat(24) }); });
    let mode = 'peek-ok', posts = 0;
    const until1 = new Date(Date.now() + 3600e3).toISOString();
    await pg.route('**/api/confirm', route => { let b = {}; try { b = JSON.parse(route.request().postData() || '{}'); } catch (e) {}
      if (b.peek) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, peek: 'waiting', items: [{ name: 'Luna', qty: 1 }], total: 65, until: until1 }) });
      posts++;
      if (mode === 'peek-ok') { mode = 'taken'; return route.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Previše pokušaja. Pokušajte ponovno za sat vremena.' }) }); }
      return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, taken: ['luna'], pt: 'a'.repeat(24) }) }); });
    await pg.goto(URL + '?potvrda=' + '1'.repeat(32) + '.' + '2'.repeat(32), { waitUntil: 'networkidle' }); await pg.waitForSelector('#confirmBtn', { timeout: 6000 }).catch(() => {});
    const noClose = await pg.evaluate(() => !document.querySelector('#claimBox .claim-close'));
    await pg.click('#confirmBtn', { timeout: 5000 }).catch(() => {}); await pg.waitForFunction(() => { const h = document.getElementById('confirmHint'); return h && /Previše pokušaja/.test(h.textContent); }, null, { timeout: 5000 }).catch(() => {});
    const after429 = await pg.evaluate(() => { const b = document.getElementById('confirmBtn'); return [!!b && !b.disabled, b ? b.textContent : '', /potvrda=/.test(location.search), (document.getElementById('confirmHint') || {}).textContent || '']; });
    await pg.click('#confirmBtn', { timeout: 5000 }).catch(() => {}); await pg.waitForFunction(() => /Narudžba nije potvrđena/.test(document.querySelector('.drawer-body').textContent), null, { timeout: 5000 }).catch(() => {});
    const afterTaken = await pg.evaluate(() => [!document.getElementById('confirmBtn'), !/potvrda=/.test(location.search), !storeGet('pend'), /drugi kupac/.test(document.querySelector('.drawer-body').textContent)]);
    await pg.unroute('**/api/confirm');
    ok(noClose && after429[0] && after429[1] === 'Potvrdi narudžbu' && after429[2] && /Previše pokušaja/.test(after429[3]) && posts === 2 && afterTaken.every(Boolean),
      'provjera v102 (kupac N5–N7): bez košarice okvir nema „Zatvori”; 429 pri kliku ostavlja gumb i link; „torbu je uzeo drugi kupac” briše oznaku čekanja ' + JSON.stringify([noClose, after429, posts, afterTaken]));
    /* isti preglednik (košarica puna): ispod okvira nema obrasca; „Zatvori” ga vraća */
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    await pg.evaluate(() => { document.getElementById('emptyCart').classList.remove('pay-mode'); const p = PRODUCTS.find(x => !x.made && !isGone(x) && !noPhoto(x)); cart = []; saveCart(); toggleCartItem(p.id); });
    await pg.route('**/api/confirm', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, peek: 'waiting', items: [{ name: 'Luna', qty: 1 }], total: 65, until: until1 }) }));
    await pg.goto(URL + '?potvrda=' + '3'.repeat(32) + '.' + '4'.repeat(32), { waitUntil: 'networkidle' }); await pg.waitForSelector('#confirmBtn', { timeout: 6000 }).catch(() => {});
    await pg.waitForTimeout(600); /* i nakon odgovora o stanju (renderCart) obrazac ostaje skriven */
    const full1 = await pg.evaluate(() => [document.getElementById('orderForm').offsetParent === null, document.getElementById('cartItems').offsetParent === null, !!document.querySelector('#claimBox .claim-close')]);
    await pg.click('#claimBox .claim-close', { timeout: 5000 }).catch(() => {}); await pg.waitForTimeout(200);
    const full2 = await pg.evaluate(() => [document.getElementById('orderForm').offsetParent === null, document.getElementById('cartItems').offsetParent === null, !!document.getElementById('claimBox')]);
    ok(full1.every(Boolean) && !full2[0] && !full2[1] && !full2[2], 'provjera v102 (softver N6): u pregledniku s košaricom ispod okvira za potvrdu nema obrasca; „Zatvori” vraća košaricu ' + JSON.stringify([full1, full2]));
    /* druga kartica: narudžba potvrđena drugdje → gumb ovdje nestaje; košarica ove kartice se NE prepisuje u pohranu */
    await pg.goto(URL + '?potvrda=' + '3'.repeat(32) + '.' + '4'.repeat(32), { waitUntil: 'networkidle' }); await pg.waitForSelector('#confirmBtn', { timeout: 6000 }).catch(() => {});
    const pgB = await c2.newPage(); watch(pgB); await pgB.goto(URL, { waitUntil: 'networkidle' });
    /* krug 2 (softver/kupac N1): u drugoj kartici promijeni se stanje DRUGE narudžbe (druga torba) — gumb ovdje ostaje */
    await pgB.evaluate(L => { storeSet('last', L); }, mkLast('SJ-2026-0210', 'tara', 'Tara')); await pg.waitForTimeout(400);
    await pgB.evaluate(() => { const l = storeGet('last'); l.claimed = true; storeSet('last', l); }); await pg.waitForTimeout(400);
    const keepBtn = await pg.evaluate(() => [!!document.getElementById('confirmBtn'), /potvrda=/.test(location.search)]);
    ok(keepBtn[0] && keepBtn[1], 'provjera v102 (krug 2, N1): promjena druge narudžbe u drugoj kartici ne miče gumb „Potvrdi narudžbu” ni link ' + JSON.stringify(keepBtn));
    await pgB.evaluate(L => { storeSet('last', L); }, mkLast('SJ-2026-0211', 'luna', 'Luna'));
    await pg.waitForFunction(() => !document.getElementById('confirmBtn'), null, { timeout: 4000 }).catch(() => {}); await pg.waitForTimeout(200);
    /* zatim druga kartica doda u košaricu i promijeni stanje iste narudžbe (raskid) — ova kartica osvježi ploču, ali NE sprema svoju košaricu */
    await pgB.evaluate(() => { storeSet('cart', [{ id: 'kozmeticka', qty: 2, note: '', sc: '', sl: '' }]); const l = storeGet('last'); l.withdrawn = true; storeSet('last', l); });
    await pg.waitForTimeout(400);
    const tabA = await pg.evaluate(() => [!document.getElementById('confirmBtn'), !/potvrda=/.test(location.search), JSON.stringify(storeGet('cart'))]);
    await pg.unroute('**/api/confirm');
    ok(tabA[0] && tabA[1] && /kozmeticka/.test(tabA[2]) && /"qty":2/.test(tabA[2]), 'provjera v102 (softver N3/N4): potvrda u drugoj kartici miče gumb ovdje, a spremljena košarica druge kartice ostaje netaknuta ' + JSON.stringify(tabA));
    await pgB.close();
    /* krug 2: stari (zamijenjeni ili istekli) link ne briše oznaku čekanja novije narudžbe; promijenjena ponuda iste narudžbe (isti pt) je briše */
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    await pg.evaluate(() => { cart = []; storeSet('cart', null); storeSet('last', null); storeSet('pend', { until: new Date(Date.now() + 3600e3).toISOString(), sig: 'x', ids: ['tara'], all: ['tara'], pt: 'a'.repeat(24) }); });
    await pg.route('**/api/confirm', route => route.fulfill({ status: 410, contentType: 'application/json', body: JSON.stringify({ ok: false, expired: true, replaced: true, error: 'Ovaj link je zamijenjen novijim.' }) }));
    await pg.goto(URL + '?potvrda=' + '5'.repeat(32) + '.' + '6'.repeat(32), { waitUntil: 'networkidle' }); await pg.waitForFunction(() => /zamijenjen/.test(document.querySelector('.drawer-body').textContent), null, { timeout: 5000 }).catch(() => {});
    const pendOld = await pg.evaluate(() => !!storeGet('pend'));
    await pg.unroute('**/api/confirm');
    await pg.route('**/api/confirm', route => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'changed', pt: 'a'.repeat(24), error: 'Cijene su se u međuvremenu promijenile, pa narudžba nije potvrđena.' }) }));
    await pg.goto(URL + '?potvrda=' + '7'.repeat(32) + '.' + '8'.repeat(32), { waitUntil: 'networkidle' }); await pg.waitForFunction(() => /Cijene su se/.test(document.querySelector('.drawer-body').textContent), null, { timeout: 5000 }).catch(() => {});
    const pendSame = await pg.evaluate(() => !!storeGet('pend'));
    await pg.unroute('**/api/confirm');
    /* 429 pri OTVARANJU linka: link ostaje u adresi */
    await pg.route('**/api/confirm', route => route.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Previše pokušaja. Pokušajte ponovno za sat vremena.' }) }));
    await pg.goto(URL + '?potvrda=' + '9'.repeat(32) + '.' + '0'.repeat(32), { waitUntil: 'networkidle' }); await pg.waitForFunction(() => /Previše pokušaja/.test(document.querySelector('.drawer-body').textContent), null, { timeout: 5000 }).catch(() => {});
    const keepUrl = await pg.evaluate(() => /potvrda=/.test(location.search));
    await pg.unroute('**/api/confirm');
    ok(pendOld && !pendSame && keepUrl, 'provjera v102 (krug 2, N2): stari link ne briše oznaku čekanja novije narudžbe; odbijanje iste narudžbe (isti pt) je briše; 429 pri otvaranju ostavlja link ' + JSON.stringify([pendOld, pendSame, keepUrl]));
    /* krug 2 (softver N4): okvir otvoren bez košarice, kupac zatim doda torbu — obrazac je skriven, okvir dobiva „Zatvori” */
    await pg.evaluate(() => { storeSet('pend', null); });
    await pg.route('**/api/confirm', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, peek: 'waiting', pt: 'b'.repeat(24), items: [{ name: 'Luna', qty: 1 }], total: 65, until: until1 }) }));
    await pg.goto(URL + '?potvrda=' + '3'.repeat(32) + '.' + '4'.repeat(32), { waitUntil: 'networkidle' }); await pg.waitForSelector('#confirmBtn', { timeout: 6000 }).catch(() => {});
    const late = await pg.evaluate(() => { const r = [!document.querySelector('#claimBox .claim-close')]; const p = PRODUCTS.find(x => !x.made && !isGone(x) && !noPhoto(x)); toggleCartItem(p.id);
      r.push(!!document.querySelector('#claimBox .claim-close'), document.getElementById('orderForm').offsetParent === null); { const cl = document.querySelector('#claimBox .claim-close'); if (cl) cl.click(); }
      r.push(!document.getElementById('claimBox'), document.getElementById('orderForm').offsetParent !== null); cart = []; saveCart(); renderCart(); return r; });
    await pg.unroute('**/api/confirm');
    ok(late.every(Boolean), 'provjera v102 (krug 2, softver N4): torba dodana dok je okvir otvoren — obrazac skriven, „Zatvori” vraća košaricu ' + JSON.stringify(late));
    /* krug 3 (zamjenjuje zajedničku košaricu): kartica u pozadini ne prepisuje košaricu koju je promijenila druga kartica, i ne briše joj torbu;
       svoje promjene sprema; nakon osvježavanja preuzima spremljenu */
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    const pgC = await c2.newPage(); watch(pgC); await pgC.goto(URL, { waitUntil: 'networkidle' });
    const addId = await pgC.evaluate(() => { const p = PRODUCTS.find(x => !x.made && !isGone(x) && !noPhoto(x)); toggleCartItem(p.id); return p.id; });
    await pg.waitForTimeout(300);
    /* stara kartica „ne poznaje” tu torbu (kao nova torba u ponudi ili torba koja je u njoj još „rezervirana”) i vraća se u prvi plan */
    const bg = await pg.evaluate(id => { const p = PRODUCTS.find(x => x.id === id); p.srvReserved = true; document.dispatchEvent(new Event('visibilitychange')); renderCart(); p.srvReserved = false; return [cart.length, JSON.stringify(storeGet('cart') || []).includes(id)]; }, addId);
    await pgC.waitForTimeout(300);
    const stillC = await pgC.evaluate(id => [cart.some(c => c.id === id), JSON.stringify(storeGet('cart') || []).includes(id)], addId);
    await pg.reload({ waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    const afterReload = await pg.evaluate(id => cart.some(c => c.id === id), addId);
    /* vlastita promjena se sprema kao i dosad */
    const own = await pg.evaluate(() => { cart = []; saveCart(); return (storeGet('cart') || []).length; });
    await pgC.close();
    ok(bg[0] === 0 && bg[1] && stillC[0] && stillC[1] && afterReload && own === 0, 'provjera v102 (krug 3, N1; kupac 3a): kartica u pozadini ne briše ni ne prepisuje torbu dodanu u drugoj kartici; nakon osvježavanja je preuzima; vlastitu promjenu sprema ' + JSON.stringify([bg, stillC, afterReload, own]));
    /* krug 2 (kupac 3b): nova poveznica za raskid dok je otvoren pregled izjave — natrag na prvi korak, IBAN i napomena prve ne prelaze */
    await pg.evaluate(() => { location.hash = '#raskid?no=2026-0301&t=' + 'b'.repeat(24); }); await pg.waitForTimeout(300);
    await pg.fill('#rName', 'Ana Kupac'); await pg.fill('#rEmail', 'ana102@primjer.hr'); await pg.fill('#rIban', 'HR1210010051863000160'); await pg.fill('#rNote', 'napomena prve');
    await pg.click('#raskidNext'); await pg.waitForTimeout(200);
    const stepA = await pg.evaluate(() => !document.getElementById('raskidStep2').hidden);
    await pg.evaluate(() => { location.hash = '#raskid?no=2026-0302&t=' + 'c'.repeat(24); }); await pg.waitForTimeout(300);
    const stepB = await pg.evaluate(() => [document.getElementById('raskidStep2').hidden, !document.getElementById('raskidStep1').hidden, document.getElementById('rNo').value, document.getElementById('rIban').value, document.getElementById('rNote').value, document.getElementById('rName').value]);
    await pg.evaluate(() => { history.replaceState(null, '', location.pathname); document.getElementById('raskidForm').reset(); window.scrollTo(0, 0); });
    ok(stepA && stepB[0] && stepB[1] && stepB[2] === 'SJ-2026-0302' && stepB[3] === '' && stepB[4] === '' && stepB[5] === 'Ana Kupac', 'provjera v102 (krug 2, kupac 3b): druga poveznica za raskid zatvara pregled prve izjave; IBAN i napomena se ne prenose ' + JSON.stringify(stepB));
    /* krug 2 (K10): stanje najviše jednom u dvije minute */
    await pg.evaluate(L => { storeSet('last', L); }, mkLast('SJ-2026-0215', 'luna', 'Luna'));
    let nState = 0;
    await pg.route('**/api/status*', async route => { try { if (/stanje=SJ-2026-0215/.test(decodeURIComponent(route.request().url()))) nState++; await route.continue(); } catch (e) {} });
    await pg.evaluate(() => { stateAsked = Date.now() - 119000; document.dispatchEvent(new Event('visibilitychange')); }); await pg.waitForTimeout(600); const n119 = nState;
    await pg.evaluate(() => { stateAsked = Date.now() - 121000; document.dispatchEvent(new Event('visibilitychange')); }); await pg.waitForTimeout(800); const n121 = nState;
    await pg.unroute('**/api/status*'); await pg.evaluate(() => { storeSet('last', null); });
    ok(n119 === 0 && n121 === 1, 'provjera v102 (krug 2, K10): stanje narudžbe pita se tek nakon dvije minute ' + JSON.stringify([n119, n121]));
    /* recenzija v102 (kupac N1, softver 1): druga kartica istog preglednika mijenja košaricu — ploča za uplatu na zaslonu ostaje */
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    const pan1 = await pg.evaluate(L => { cart = []; storeSet('cart', null); storeSet('last', L); openCart(); renderPaymentPanel(L); return /SJ-2026-0220/.test((document.querySelector('#emptyCart .pay-no') || {}).textContent || ''); }, mkLast('SJ-2026-0220', 'luna', 'Luna'));
    const pgD = await c2.newPage(); watch(pgD); await pgD.goto(URL, { waitUntil: 'networkidle' });
    await pgD.evaluate(() => { const p = PRODUCTS.find(x => !x.made && !isGone(x) && !noPhoto(x) && x.id !== 'luna'); cart = []; toggleCartItem(p.id); });
    await pg.waitForTimeout(500);
    const pan2 = await pg.evaluate(() => { const e = document.getElementById('emptyCart'); document.dispatchEvent(new Event('visibilitychange')); return [e.classList.contains('pay-mode') && /SJ-2026-0220/.test((e.querySelector('.pay-no') || {}).textContent || ''), document.getElementById('orderForm').hidden, (storeGet('cart') || []).length]; });
    await pgD.evaluate(() => { cart = []; saveCart(); }); await pgD.close(); await pg.evaluate(() => { storeSet('cart', null); storeSet('last', null); document.getElementById('emptyCart').classList.remove('pay-mode'); renderCart(); closeCart(false); });
    ok(pan1 && pan2[0] && pan2[1] && pan2[2] === 1, 'recenzija v102 (kupac N1): torba dodana u drugoj kartici ne miče ploču za uplatu i ostaje spremljena ' + JSON.stringify([pan1, pan2]));
    /* recenzija v102 (kupac N2, mobitel 4): „Zatvori” u okviru za potvrdu ostavlja redak koji vraća gumb; greška pri kliku ima stil greške */
    await pg.evaluate(() => { const p = PRODUCTS.find(x => !x.made && !isGone(x) && !noPhoto(x)); toggleCartItem(p.id); storeSet('pend', { until: new Date(Date.now() + 3600e3).toISOString(), sig: cartSig(), ids: [p.id], all: [p.id], pt: 'c'.repeat(24) }); });
    let failOnce = true;
    await pg.route('**/api/confirm', route => { let b = {}; try { b = JSON.parse(route.request().postData() || '{}'); } catch (e) {}
      if (b.peek) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, peek: 'waiting', pt: 'c'.repeat(24), items: [{ name: 'Luna', qty: 1 }], total: 65, until: until1 }) });
      return route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Zbog tehničke smetnje narudžbe trenutačno ne primamo.' }) }); });
    await pg.goto(URL + '?potvrda=' + '3'.repeat(32) + '.' + '4'.repeat(32), { waitUntil: 'networkidle' }); await pg.waitForSelector('#confirmBtn', { timeout: 6000 }).catch(() => {}); await pg.waitForTimeout(150);
    const foc = await pg.evaluate(() => document.activeElement && document.activeElement.id);
    await pg.click('#confirmBtn', { timeout: 5000 }).catch(() => {}); await pg.waitForFunction(() => { const h = document.getElementById('confirmHint'); return h && h.classList.contains('is-error'); }, null, { timeout: 5000 }).catch(() => {});
    const errSt = await pg.evaluate(() => { const h = document.getElementById('confirmHint'); return [h.classList.contains('is-error'), getComputedStyle(h).color, document.activeElement && document.activeElement.id]; });
    await pg.click('#claimBox .claim-close', { timeout: 5000 }).catch(() => {}); await pg.waitForTimeout(200);
    const closed = await pg.evaluate(() => [!document.getElementById('claimBox'), !!document.getElementById('confirmBack'), /Ovu narudžbu ste već poslali/.test(document.getElementById('orderError').textContent), /potvrda=/.test(location.search)]);
    await pg.click('#confirmBack button', { timeout: 5000 }).catch(() => {}); await pg.waitForSelector('#confirmBtn', { timeout: 6000 }).catch(() => {});
    const back = await pg.evaluate(() => [!!document.getElementById('confirmBtn'), !document.getElementById('confirmBack')]);
    await pg.unroute('**/api/confirm'); await pg.evaluate(() => { storeSet('pend', null); cart = []; saveCart(); });
    ok(foc === 'claimBox' && errSt[0] && errSt[1] === 'rgb(122, 36, 24)' && errSt[2] === 'confirmBtn' && closed.every(Boolean) && back.every(Boolean),
      'recenzija v102 (kupac N2, dizajn N2/N5): fokus na okviru; greška pri kliku crvena, fokus na gumbu; „Zatvori” pokazuje napomenu i redak koji vraća gumb ' + JSON.stringify([foc, errSt, closed, back]));
    /* recenzija v102 (mobitel 2, 5, 7; dizajn N4, N6): povećani prikaz počinje od vrha; poštanski broj; poruka uz remen preko cijele širine; raskid — crveni obrub; „Rezervirane i prodane” */
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    await pg.evaluate(() => { const ids = PRODUCTS.filter(x => !x.made).map(x => x.id); openLightbox(ids[0]); lightbox.querySelector('.lb-panel').scrollTop = 300; lightbox.querySelector('.lb-info').scrollTop = 300; });
    await pg.keyboard.press('Escape'); await pg.waitForTimeout(300); /* zatvaranje ide kroz povijest preglednika — čekamo da završi */
    const lbTop = await pg.evaluate(() => { const ids = PRODUCTS.filter(x => !x.made).map(x => x.id); openLightbox(ids[1]); return lightbox.querySelector('.lb-panel').scrollTop === 0 && lightbox.querySelector('.lb-info').scrollTop === 0; });
    await pg.keyboard.press('Escape'); await pg.waitForTimeout(300);
    const misc2 = [lbTop];
    await pg.evaluate(() => { const kp = PRODUCTS.find(x => x.made && catOf(x) === 'kolekcija' && !isGone(x)); cart = []; toggleCartItem(kp.id); openCart(); });
    await pg.check('#shipOptions input[value="gls"]'); await pg.fill('#fName', 'Proba Kupac'); await pg.fill('#fEmail', 'proba102@primjer.hr'); await pg.fill('#fPhone', '091 234 5678'); await pg.fill('#fAddr', 'Ilica 1'); await pg.fill('#fZip', '10000'); await pg.fill('#fCity', ''); await pg.check('#fConsent');
    const zipMsg = await pg.evaluate(() => { zipCheck(); return [document.getElementById('fZip').validationMessage, document.getElementById('fZip').checkValidity()]; });
    await pg.fill('#fCity', 'Zagreb'); await pg.click('#orderBtn'); await pg.waitForSelector('.strap-err', { timeout: 4000 }).catch(() => {});
    const strapW = await pg.evaluate(() => { const e = document.querySelector('.strap-err'), row = e && e.closest('.strap-row'); return e ? [Math.round(e.getBoundingClientRect().width), Math.round(row.getBoundingClientRect().width)] : [0, 1]; });
    await pg.evaluate(() => { cart = []; saveCart(); renderCart(); closeCart(false); location.hash = '#raskid'; }); await pg.waitForTimeout(200);
    await pg.click('#raskidNext'); await pg.waitForTimeout(200);
    const rsk = await pg.evaluate(() => { const n = document.getElementById('rName'); const g = [...document.querySelectorAll('.grid-sub > span')].map(x => x.getClientRects().length); return [n.getAttribute('aria-invalid'), getComputedStyle(n).borderTopColor, g.length === 0 || g.every(x => x === 1)]; });
    await pg.evaluate(() => { history.replaceState(null, '', location.pathname); document.getElementById('raskidForm').reset(); window.scrollTo(0, 0); });
    ok(misc2[0] && zipMsg[0] === '' && zipMsg[1] && strapW[0] >= strapW[1] - 2 && rsk[0] === 'true' && rsk[1] === 'rgb(155, 44, 31)' && rsk[2],
      'recenzija v102 (mobitel 2/5/7, dizajn N4/N6): prikaz počinje od vrha; ispravan poštanski broj nema poruku; poruka uz remen preko cijele širine; raskid ima crveni obrub ' + JSON.stringify([misc2, zipMsg, strapW, rsk]));
    /* povratak u karticu pita stanje; ploča STARIJE narudžbe na zaslonu nestaje kad servis javi storno */
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200);
    await pg.evaluate(([L, P]) => { document.getElementById('emptyCart').classList.remove('pay-mode'); cart = []; storeSet('cart', null); storeSet('last', L); storeSet('lastPrev', [Object.assign(P, { open: true })]); openCart(); renderCart(); document.querySelector('.last-order[data-prev="SJ-2026-0213"]').click(); }, [mkLast('SJ-2026-0212', 'luna', 'Luna'), mkLast('SJ-2026-0213', 'tara', 'Tara')]);
    let sawState = false;
    await pg.route('**/api/status*', async route => { try { if (/stanje=SJ-2026-0212/.test(decodeURIComponent(route.request().url()))) sawState = true; const rsp = await route.fetch(); const j = await rsp.json(); await route.fulfill({ response: rsp, json: Object.assign(j, { orderState: 'open', prevStates: { 'SJ-2026-0213': 'cancelled' } }) }); } catch (e) {} });
    const before = await pg.evaluate(() => { const t = (document.querySelector('#emptyCart .pay-no') || {}).textContent || ''; stateAsked = 0; document.dispatchEvent(new Event('visibilitychange')); return t; });
    await pg.waitForFunction(() => !(storeGet('lastPrev') || []).length, null, { timeout: 5000 }).catch(() => {}); await pg.waitForTimeout(150);
    const vis2 = await pg.evaluate(() => { const e = document.getElementById('emptyCart'); return [(storeGet('lastPrev') || []).length, e.classList.contains('pay-mode'), !!e.querySelector('#showLast')]; });
    await pg.unroute('**/api/status*');
    ok(/SJ-2026-0213/.test(before) && sawState && vis2[0] === 0 && !vis2[1] && vis2[2], 'provjera v102 (softver, grane bez testa): povratak u karticu pita stanje; ploča starije narudžbe nestaje nakon storna, zadnja ostaje ' + JSON.stringify([before, sawState, vis2]));
    /* ponovljena potvrda na drugom uređaju nakon „Uplatio/la sam”: ploča zna da je već javljeno; rok „Uplatio/la sam” se prenosi iz stare ploče; „i u košarici” samo uz ploču */
    const rep = await pg.evaluate(L => { storeSet('lastPrev', null); storeSet('last', null);
      const srv = extra => Object.assign({ ok: true, repeated: true, orderNo: L.srv.orderNo, reference: L.srv.reference, total: 65, until: L.srv.until, payUntil: L.srv.payUntil, items: [{ id: 'luna', name: 'Luna', qty: 1, price: 65, made: false }], ship: { id: 'pickup', label: 'Osobno preuzimanje u radionici', price: 0 } }, extra);
      showOrderSuccess(srv({ claimed: true, claimMsg: 'Već ste nam javili da ste uplatili; torbu čuvamo.', claimLink: null, claimUntil: null })); const a = storeGet('last'); const e = document.getElementById('emptyCart');
      const r = [a.claimed === true, a.claimMsg, !e.querySelector('.claim-link'), /Javili ste nam da ste uplatili\./.test(e.textContent)];
      storeSet('last', L); showOrderSuccess(srv({})); const b = storeGet('last'); r.push(b.srv.claimUntil === L.srv.claimUntil, b.srv.claimLink === L.srv.claimLink);
      const p = PRODUCTS.find(x => x.id === 'luna'); p.srvReserved = true; openLightbox('luna'); r.push(/i u košarici\./.test(document.getElementById('lbGoneText').textContent)); closeLightbox();
      storeSet('last', null); storeSet('own', { at: Date.now(), ids: ['luna'], no: 'SJ-2026-0214' }); openLightbox('luna'); r.push(/rezervirana za vas/.test(document.getElementById('lbGoneText').textContent) && !/i u košarici/.test(document.getElementById('lbGoneText').textContent)); closeLightbox();
      p.srvReserved = false; p.reserved = false; storeSet('own', null); document.getElementById('emptyCart').classList.remove('pay-mode'); renderCart(); closeCart(false); return r; }, mkLast('SJ-2026-0214', 'luna', 'Luna'));
    ok(rep[0] && rep[1] === 'Torbu čuvamo.' /* krug 2: bez ponavljanja „Već ste nam javili…” iza naslova */ && rep[2] && rep[3] && rep[4] && rep[5] && rep[6] && rep[7], 'provjera v102 (kupac N2; grane bez testa): „Uplatio/la sam” već iskorišten — ploča ga ne nudi ponovno; rok i poveznica prenose se iz stare ploče; „i u košarici” samo kad ploča postoji ' + JSON.stringify(rep));
    /* poruka nove narudžbe kad prethodna čeka obradu izjave o raskidu (naslov bez „čeka uplatu”) */
    await pg.evaluate(() => { storeSet('last', null); storeSet('lastPrev', null); storeSet('cart', null); storeSet('pend', null); });
    await pg.goto(URL, { waitUntil: 'networkidle' }); await pg.waitForTimeout(200); /* svježa stranica: bez ostataka ploče iz prethodnog koraka */
    await pg.evaluate(() => { const p = PRODUCTS.find(x => !x.made && !isGone(x) && !noPhoto(x)); toggleCartItem(p.id); openCart(); });
    await pg.waitForSelector('#fName', { state: 'visible', timeout: 4000 }).catch(() => {});
    await pg.check('#shipOptions input[value="pickup"]'); await pg.fill('#fName', 'Raskid Test'); await pg.fill('#fEmail', 'wd102@primjer.hr'); await pg.fill('#fPhone', '091 234 5678'); await pg.check('#fConsent');
    await pg.route('**/api/order', route => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, code: 'open_order', kind: 'unique', error: 'Za prethodnu narudžbu s unikatnom torbom zaprimili smo vašu izjavu o raskidu ugovora i još je obrađujemo. Za novu narudžbu javite nam se e-mailom ili na Instagramu.' }) }));
    await pg.click('#orderBtn'); await pg.waitForFunction(() => !document.getElementById('orderError').hidden, null, { timeout: 8000 }).catch(() => {});
    const wdTxt = await pg.textContent('#orderError').catch(() => '');
    await pg.unroute('**/api/order'); await pg.evaluate(() => { cart = []; saveCart(); renderCart(); closeCart(false); });
    ok(/Prethodnu narudžbu još obrađujemo\./.test(wdTxt) && /zaprimili smo vašu izjavu o raskidu/.test(wdTxt) && !/čeka uplatu/.test(wdTxt), 'provjera v102 (kupac N3): prethodna narudžba u obradi zbog izjave — naslov i tekst bez „čeka uplatu” ' + wdTxt.slice(0, 70));
    await c2.close(); }

  // ---------- v29: prekid veze, osvježavanje, ponovno slanje = ista narudžba ----------
  const pk = await d.newPage(); watch(pk);
  await pk.goto(URL + '#ostalo', { waitUntil: 'networkidle' }); await pk.waitForTimeout(300);
  await pk.click('.card:has(h3:has(.card-name:text-is("Kozmetička torbica"))) .add-btn').catch(async () => { await pk.click('.card.made .add-btn'); });
  const fillForm = async pg => {
    await pg.click('#openCart'); await pg.waitForTimeout(300);
    await pg.check('#shipOptions input[value="pickup"]');
    await pg.fill('#fName', 'Prekid Veze'); await pg.fill('#fEmail', 'prekid@primjer.hr'); await pg.fill('#fPhone', '091 555 6666'); await pg.check('#fConsent');
  };
  await fillForm(pk);
  const nBefore = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8')).length;
  await pk.route('**/api/order', async route => { await route.fetch(); await route.abort('connectionreset'); });
  let mailtoOpened = false; pk.on('framenavigated', f => { if (/^mailto:/.test(f.url())) mailtoOpened = true; });
  await pickStrap(pk); await pk.click('#orderBtn');
  await pk.waitForSelector('#retrySend', { timeout: 8000 }).catch(() => {});
  const nAfter1 = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8'));
  ok(await pk.isVisible('#retrySend') && await pk.isVisible('#retryMail') && !mailtoOpened, 'prekid veze: „Pošalji ponovno” i „Pošalji e-mailom”, e-mail se ne otvara sam');
  ok(nAfter1.length === nBefore + 1, 'poslužitelj je zaprimio prvi pokušaj');
  const keyAfterFail = await pk.evaluate(() => JSON.parse(localStorage.getItem('shivaj.okey') || 'null'));
  ok(keyAfterFail && !/@|primjer/.test(JSON.stringify(keyAfterFail)), 'v83: u pregledniku se ne čuvaju ime ni e-mail (ključ narudžbe nosi samo sažetak) ' + JSON.stringify(keyAfterFail));
  await pk.unroute('**/api/order');
  await pk.reload({ waitUntil: 'networkidle' }); await pk.waitForTimeout(400);
  const keyAfterReload = await pk.evaluate(() => JSON.parse(localStorage.getItem('shivaj.okey') || 'null'));
  ok(keyAfterFail && keyAfterReload && keyAfterFail.key === keyAfterReload.key, 'ključ narudžbe preživi osvježavanje stranice');
  await fillForm(pk);
  await pickStrap(pk); await pk.click('#orderBtn');
  await pk.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  const nAfter2 = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8'));
  const shownNo = await pk.textContent('.pay').catch(() => '');
  ok(nAfter2.length === nAfter1.length && shownNo.includes(nAfter1[0].no), 'ponovno slanje nakon osvježavanja: ista narudžba ' + nAfter1[0].no + ', bez nove');
  // promjena e-maila = novi ključ
  const keys = await pk.evaluate(() => {
    const base = { customer: { name: 'A', email: 'a@p.hr' }, items: [{ id: 'remen', qty: 1, note: '' }], shipping: { id: 'pickup', locker: '' } };
    const k1 = orderKey(base, ''), k2 = orderKey(base, ''), k3 = orderKey({ ...base, customer: { name: 'A', email: 'b@p.hr' } }, '');
    localStorage.removeItem('shivaj.okey');
    return [k1, k2, k3];
  });
  ok(keys[0] === keys[1] && keys[2] !== keys[0], 'isti sadržaj = isti ključ, drugi e-mail = novi ključ');
  await pk.close();

  // ---------- v29: uski mobitel, IBAN unutar ploče ----------
  // tuđa košarica: netko drugi rezervira Taru (bez narudžbe), ja je ipak vidim i naručim
  await fetch(URL + 'api/reserve', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids: ['tara'], name: 'Drugi', email: 'drugi-kosarica@primjer.hr' }) });
  const m3 = await browser.newContext({ viewport: { width: 360, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const p3 = await m3.newPage(); watch(p3);
  await p3.goto(URL, { waitUntil: 'networkidle' }); await p3.waitForTimeout(300);
  ok(!(await p3.locator('.card:has(h3:has(.card-name:text-is("Tara"))) .add-btn').isDisabled()), 'torba u tuđoj košarici nije „Rezervirano”');
  await p3.click('.card:has(h3:has(.card-name:text-is("Tara"))) .add-btn');
  await p3.click('#openCart'); await p3.waitForTimeout(300);
  const radio = await p3.locator('#shipOptions .ship-opt').first().locator('input').boundingBox();
  const lbl = await p3.locator('#shipOptions .ship-opt').first().locator('.ship-main').boundingBox();
  ok(radio && lbl && Math.abs((radio.y + radio.height / 2) - (lbl.y + lbl.height / 2)) < 12 && radio.x < lbl.x, 'kružić dostave u istom retku s nazivom');
  await p3.evaluate(() => showToast('proba'));
  const tb = await p3.locator('#toast').boundingBox();
  ok(tb && tb.y < 100, 'obavijest na vrhu dok je košarica otvorena (y ' + Math.round(tb.y) + ')');
  await p3.check('#shipOptions input[value="pickup"]');
  await p3.fill('#fName', 'Uski Mobitel'); await p3.fill('#fEmail', 'uski@primjer.hr'); await p3.fill('#fPhone', '091 777 8888'); await p3.check('#fConsent');
  await pickStrap(p3); await p3.click('#orderBtn');
  await p3.waitForSelector('.pay-data .iban', { timeout: 8000 }).catch(() => {});
  const ib = await p3.locator('.pay-data .iban').boundingBox();
  const body = await p3.locator('.drawer-body').boundingBox();
  const hscroll = await p3.evaluate(() => { const b = document.querySelector('.drawer-body'); return b.scrollWidth - b.clientWidth; });
  ok(ib && body && ib.x + ib.width <= body.x + body.width && hscroll <= 0, `360 px: IBAN unutar ploče (desni rub ${ib && Math.round(ib.x + ib.width)} ≤ ${body && Math.round(body.x + body.width)}), bez vodoravnog pomicanja`);
  await p3.screenshot({ path: OUT + 'm-04-uplata-360.png' });
  await p3.locator('.pay-data').scrollIntoViewIfNeeded();
  await p3.screenshot({ path: OUT + 'm-05-uplata-360-podaci.png' });
  await m3.close();
  // ---------- v31: krivi e-mail, prekid prije poslužitelja, ispravak → narudžba prolazi (ključ rezervacije) ----------
  /* ispitna ponuda ima 4 slobodne torbe, a neplaćene narudžbe smiju držati pola: prije sljedećih scenarija rezervacije se prazne */
  fs.writeFileSync(SITE + 'data/rezervacije.json', JSON.stringify({ res: {}, sold: {} }));
  const m5 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p5 = await m5.newPage(); watch(p5);
  await p5.goto(URL, { waitUntil: 'networkidle' }); await p5.waitForTimeout(300);
  await p5.click('.card:has(h3:has(.card-name:text-is("Vela"))) .add-btn'); await p5.click('#openCart'); await p5.waitForTimeout(300);
  await p5.check('#shipOptions input[value="pickup"]');
  await p5.fill('#fName', 'Krivi Mail'); await p5.fill('#fEmail', 'krivi@primjer.hr'); await p5.fill('#fPhone', '091 777 2222'); await p5.check('#fConsent');
  await p5.route('**/api/order', r => r.abort('connectionreset'));
  await pickStrap(p5); await p5.click('#orderBtn'); await p5.waitForSelector('#retrySend', { timeout: 8000 }).catch(() => {});
  await p5.unroute('**/api/order');
  await p5.fill('#fEmail', 'ispravan@primjer.hr');
  await pickStrap(p5); await p5.click('#orderBtn'); await p5.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  ok(/Torba „Vela” rezervirana je/.test(await p5.textContent('.pay-thanks').catch(() => '')), 'krivi e-mail, prekid, ispravak: narudžba prolazi, torba nije blokirana');
  await m5.close();
  const m4 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p4 = await m4.newPage();
  await p4.route('**/js/hub3-barcode.js', r => r.abort());
  await p4.route('**/api/reserve', r => r.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'previše' }) }));
  await p4.goto(URL, { waitUntil: 'networkidle' }); await p4.waitForTimeout(300);
  await p4.click('.card:has(h3:has(.card-name:text-is("Nera"))) .add-btn'); await p4.click('#openCart'); await p4.waitForTimeout(300);
  await p4.check('#shipOptions input[value="pickup"]');
  await p4.fill('#fName', 'Bez Skripte'); await p4.fill('#fEmail', 'bez@primjer.hr'); await p4.fill('#fPhone', '091 777 1111'); await p4.check('#fConsent');
  await pickStrap(p4); await p4.click('#orderBtn'); await p4.waitForSelector('#payBarcode', { timeout: 8000 }).catch(() => {}); await p4.waitForTimeout(800);
  const fb = await p4.evaluate(() => { const i = document.querySelector('#payBarcode img'); return { hub3: i.dataset.hub3 || '', src: i.getAttribute('src'), vis: !document.getElementById('payBarcode').hidden }; });
  ok(!fb.hub3 && /barkod-uplata\.png$/.test(fb.src) && fb.vis, 'bez skripte ostaje opći barkod iz Postavki');
  ok(/Torba „Nera” rezervirana je/.test(await p4.textContent('.pay-thanks').catch(() => '')), 'rezervacija vrati 429, narudžba ipak prolazi');
  await m4.close();
  // v68: naručena torba je rezervirana za kupca — drugi je ne može dodati u košaricu (nema narudžbi „bez rezervacije”)
  const m6 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p6 = await m6.newPage(); watch(p6);
  await p6.goto(URL, { waitUntil: 'networkidle' }); await p6.waitForTimeout(300);
  const resName = await p6.evaluate(() => { const p = PRODUCTS.find(x => !x.made && x.srvReserved && !x.srvSold && !x.sold); return p ? p.name : ''; });
  const resCard = '.card:has(h3:has(.card-name:text-is("' + resName + '")))';
  ok(resName !== '' && await p6.locator(resCard + ' .add-btn').isDisabled() && /Rezervirano/.test(await p6.textContent(resCard)), 'v68: naručena torba („' + resName + '”) drugom kupcu stoji kao „Rezervirano” i ne može se dodati');
  await m6.close();
  // v40: potvrda e-mailom — ploča „potvrdite narudžbu”, link iz e-maila otvara podatke za uplatu i na drugom uređaju
  const cfgPath = SITE + 'data/config.json', cfg0 = fs.readFileSync(cfgPath, 'utf8');
  fs.writeFileSync(cfgPath, JSON.stringify(Object.assign(JSON.parse(cfg0), { require_confirm: true })));
  const m7 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p7 = await m7.newPage(); watch(p7);
  await p7.goto(URL + '#kolekcija', { waitUntil: 'networkidle' }); await p7.waitForTimeout(300);
  await p7.click('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn'); await p7.click('#openCart'); await p7.waitForTimeout(300);
  ok(/e-mail s linkom za potvrdu/.test(await p7.textContent('.next-step')), 'obrazac najavljuje e-mail s linkom za potvrdu');
  ok(await p7.evaluate(() => [...document.querySelectorAll('#uvjeti [data-if-noconfirm], #privatnost [data-if-noconfirm], footer [data-if-noconfirm]')].every(el => getComputedStyle(el).display === 'none'))
    && /nakon potvrde narudžbe/.test(await p7.evaluate(() => document.querySelector('footer').innerText)), 'v57: s potvrdom e-mailom podnožje i Uvjeti govore o potvrdi');
  await p7.check('#shipOptions input[value="pickup"]');
  await p7.fill('#fName', 'Potvrda Mailom'); await p7.fill('#fEmail', 'potvrda@primjer.hr'); await p7.fill('#fPhone', '091 777 4444'); await p7.check('#fConsent');
  await pickStrap(p7); await p7.click('#orderBtn'); await p7.waitForSelector('.order-error.order-info', { timeout: 8000 }).catch(() => {});
  const pend7 = await p7.textContent('#orderError').catch(() => '');
  ok(/potvrdite narudžbu/.test(pend7) && /potvrda@primjer\.hr/.test(pend7) && await p7.locator('.pay-steps').count() === 0 && await p7.isVisible('#retrySend'), 'nakon slanja: „potvrdite narudžbu”, bez podataka za uplatu, gumb za ponovno slanje');
  ok(/vrijedi 1 sat \(do /.test(pend7) && /Otvorite ga i slijedite upute/.test(pend7) && !/s naslovom/.test(pend7), 'v45/v65: okvir potvrde — pravi naslov e-maila i koliko link vrijedi');
  ok(/možete zatvoriti/.test(pend7) && /zatražite ponovno:/.test(pend7) && (await p7.getAttribute('#orderError', 'role')) === 'status' && /čeka vašu potvrdu/.test(await p7.textContent('.next-step')), 'v41: okvir kaže da se stranica može zatvoriti, role=status, „Sljedeći korak” u čekanju');
  await p7.screenshot({ path: OUT + 'm-40-potvrda.png' });
  ok(!(await p7.isVisible('#orderBtn')) && !(await p7.isVisible('#fName')) && await p7.isVisible('#editOrder'), 'v46: dok narudžba čeka potvrdu, obrazac i gumb su skriveni');
  await p7.click('#editOrder'); await p7.waitForTimeout(200);
  ok(await p7.isVisible('#orderBtn') && await p7.isVisible('#fName') && !(await p7.isVisible('#orderError')), 'v46: „Promijeni podatke narudžbe” vraća obrazac');
  await pickStrap(p7); await p7.click('#orderBtn'); await p7.waitForSelector('#editOrder', { timeout: 8000 }).catch(() => {});
  // v53 G: četvrti e-mail na istu adresu (429; v95: 3 na sat) dok narudžba čeka — okvir „čeka potvrdu” ostaje
  await p7.click('#retrySend'); await p7.waitForTimeout(1500);
  ok(/Poslali smo novi e-mail\. Link iz ranijeg e-maila više ne vrijedi/.test(await p7.textContent('#orderError')) , 'v95: ponovno poslan e-mail — okvir kaže da stari link više ne vrijedi');
  await p7.click('#retrySend'); await p7.waitForTimeout(1500);
  ok(await p7.isVisible('.pend-note') && /već smo poslali više puta/.test(await p7.textContent('#orderError')) && !/nije prihvaćena/.test(await p7.textContent('#orderError')) && await p7.isVisible('#editOrder'), 'v53 G: ponovno slanje u čekanju (429) — okvir ostaje, zadnji link vrijedi');
  /* v102 (recenzija vijeća v101, kupac N2): 503 dok narudžba čeka potvrdu — okvir „Još jedan korak” ostaje (ne „Narudžba nije prihvaćena”); isto za 429 bez razloga mail_rate */
  { await p7.route('**/api/order', route => route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Trenutačno primamo previše narudžbi odjednom. Pokušajte ponovno za sat vremena.' }) }));
    await p7.click('#retrySend', { timeout: 5000 }).catch(() => {}); await p7.waitForTimeout(800);
    const t503 = await p7.textContent('#orderError').catch(() => ''), v503 = await p7.isVisible('#editOrder').catch(() => false);
    await p7.unroute('**/api/order');
    await p7.route('**/api/order', route => route.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'Previše pokušaja s ove veze. Pokušajte ponovno za sat vremena.' }) }));
    await p7.click('#retrySend', { timeout: 5000 }).catch(() => {}); await p7.waitForTimeout(800);
    const t429 = await p7.textContent('#orderError').catch(() => '');
    await p7.unroute('**/api/order');
    ok(/Ovo slanje nismo mogli primiti\./.test(t503) && /previše narudžbi odjednom/.test(t503) && /i dalje vrijedi/.test(t503) && !/nije prihvaćena/.test(t503) && v503
      && /Ovo slanje nismo mogli primiti\./.test(t429) && /Previše pokušaja s ove veze/.test(t429) && !/već smo poslali više puta/.test(t429),
      'v102 (kupac N2): 503 i 429 bez razloga dok narudžba čeka — okvir ostaje, poruka poslužitelja, zadnji link vrijedi ' + JSON.stringify([t503.slice(0, 60), t429.slice(0, 60)])); }
  // v53 H: nova kartica / osvježavanje s istom košaricom — napomena da narudžba čeka potvrdu
  const p7b = await m7.newPage(); watch(p7b);
  await p7b.goto(URL, { waitUntil: 'networkidle' }); await p7b.waitForTimeout(400); await p7b.click('#openCart'); await p7b.waitForTimeout(300);
  ok(/Ovu narudžbu ste već poslali/.test(await p7b.textContent('#orderError').catch(() => '')) && /poništava prethodni link/.test(await p7b.textContent('#orderError').catch(() => '')), 'v53/v54 H: nakon ponovnog učitavanja — „Ovu narudžbu ste već poslali”, novi e-mail poništava link');
  await p7b.close();
  const strap0 = await p7.inputValue('.strap-sel[data-k="sc"]');
  await p7.selectOption('.strap-sel[data-k="sc"]', 'smeđa'); await p7.waitForTimeout(200); /* v78: kolekcija nema slobodnu napomenu — mijenja se boja remena */
  ok(await p7.isVisible('#orderBtn') && !(await p7.isVisible('#editOrder')), 'v51: promjena napomene dok narudžba čeka potvrdu vraća obrazac');
  ok(/Promijenili ste košaricu\. Link iz e-maila potvrđuje prethodnu narudžbu/.test(await p7.textContent('#orderError').catch(() => '')), 'v92 (recenzija): promjena košarice dok narudžba čeka potvrdu — objašnjenje da link vrijedi za prethodnu narudžbu');
  { await p7.selectOption('.strap-sel[data-k="sc"]', strap0); await p7.waitForTimeout(200); /* ista košarica kao poslana narudžba */
    const nm0 = await p7.inputValue('#fName'); await p7.reload({ waitUntil: 'networkidle' }); await p7.waitForTimeout(400); await p7.click('#openCart'); await p7.waitForTimeout(300);
    const nm1 = await p7.inputValue('#fName').catch(() => '');
    ok(nm0 !== '' && nm1 === nm0 && /Ovu narudžbu ste već poslali/.test(await p7.textContent('#orderError').catch(() => '')), 'v92 (recenzija): nakon osvježavanja dok narudžba čeka potvrdu podaci kupca ostaju u obrascu (samo ova kartica) ' + JSON.stringify([nm0, nm1])); }
  const links = [...fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8').matchAll(/\?potvrda=([a-f0-9]{32}\.[a-f0-9]{32})/g)];
  const link = links.length ? links[links.length - 1][1] : '';
  const m8 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p8 = await m8.newPage(); watch(p8);
  /* v102: otvaranje linka samo provjerava — narudžba nastaje tek klikom na „Potvrdi narudžbu” */
  const ordBefore8 = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8')).length;
  await p8.goto(URL + '?potvrda=' + link, { waitUntil: 'networkidle' }); await p8.waitForSelector('#confirmBtn', { timeout: 8000 }).catch(() => {});
  const ask8 = await p8.evaluate(() => ({ txt: (document.getElementById('claimBox') || {}).textContent || '', pay: !!document.querySelector('.pay-steps'), url: location.search }));
  const ordMid8 = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8')).length;
  await p8.reload({ waitUntil: 'networkidle' }); await p8.waitForSelector('#confirmBtn', { timeout: 8000 }).catch(() => {});
  ok(/Još jedan klik: potvrdite narudžbu\./.test(ask8.txt) && /„Klasik crna”/.test(ask8.txt) && /ukupno 60,00/.test(ask8.txt) && /Nemojte je potvrditi/.test(ask8.txt) && !ask8.pay && /potvrda=/.test(ask8.url) && ordMid8 === ordBefore8 && await p8.isVisible('#confirmBtn'),
    'v102 (odluka korisnika; sigurnost N1): otvaranje linka NE potvrđuje narudžbu — sažetak i gumb „Potvrdi narudžbu”; link preživi osvježavanje ' + JSON.stringify([ask8.txt.slice(0, 90), ordBefore8, ordMid8]));
  await p8.click('#confirmBtn', { timeout: 5000 }).catch(() => {}); await p8.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  ok(JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8')).length === ordBefore8 + 1 && !(await p8.$('#claimBox')), 'v102: tek klik na gumb potvrđuje narudžbu; okvir s gumbom nestaje');
  const pay8 = await p8.textContent('.pay').catch(() => '');
  ok(/Narudžba SJ-/.test(await p8.textContent('.pay-hello').catch(() => '')) && !/Broj narudžbe/.test(await p8.textContent('.pay-data').catch(() => '')) && /Naručeno/.test(pay8) && /Klasik crna/.test(await p8.textContent('.pay-data').catch(() => '')), 'v53: ploča za uplatu navodi stavke narudžbe');
  ok(link && /SJ-\d{4}-\d{4}/.test(pay8) && /Iznos: 60,00/.test(await p8.getAttribute('.copy-btn[data-label="Podaci za uplatu"]', 'data-copy').catch(() => '')) && !/potvrda=/.test(p8.url()), 'link na drugom uređaju: podaci za uplatu s brojem narudžbe, adresa očišćena');
  await p8.goto(URL + '?potvrda=' + 'a'.repeat(32) + '.' + 'b'.repeat(32), { waitUntil: 'networkidle' }); await p8.waitForTimeout(600);
  ok(/nije potvrđena/.test(await p8.textContent('#emptyCart').catch(() => '')), 'nepostojeći link: jasna poruka u praznoj košarici');
  await m7.close(); await m8.close();
  fs.writeFileSync(cfgPath, cfg0);

  // v47: brojač broji komade; „↓” u traci na mobitelu; poveznice u podnožju 44 px
  const m9 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p9 = await m9.newPage(); watch(p9);
  await p9.goto(URL + '#kolekcija', { waitUntil: 'networkidle' }); await p9.waitForTimeout(300);
  await p9.click('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn'); await p9.click('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn');
  ok((await p9.textContent('#cartCount')).trim() === '1' && /U košarici ✓ · otvori/.test(await p9.textContent('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn')), 'v63: model Stalne kolekcije — najviše 1 komad; drugi dodir otvara košaricu');
  ok(await p9.isVisible('.press-more') && !(await p9.isVisible('.press-more .pm-t')), 'v47: na mobitelu traka „Pisali su o nama” pokazuje „↓”');
  const fh = await p9.$$eval('.foot-links a', els => Math.min(...els.map(e => e.getBoundingClientRect().height)));
  ok(fh >= 44, 'v47: poveznice u podnožju barem 44 px (' + fh + ')');
  await m9.close();
  // v48: probni korisnik — prodana torba s Instagrama, popis ispravaka, fokus, dostava, e-mail s točkom
  const m10 = await browser.newContext({ viewport: { width: 1366, height: 768 } });
  const p10 = await m10.newPage(); watch(p10);
  await p10.goto(URL + '#torba-zora', { waitUntil: 'networkidle' }); await p10.waitForTimeout(500);
  ok(await p10.isVisible('#lbGone') && /otišla/.test(await p10.textContent('#lbGoneText')) && !(await p10.isVisible('#lbAsk')), 'v48: prodana torba s Instagrama: „otišla” i gumb na slobodne torbe');
  await p10.click('#lbMore'); await p10.waitForTimeout(800);
  ok(!(await p10.evaluate(() => document.getElementById('lightbox').classList.contains('open'))), 'v48: „Pogledajte slobodne torbe” zatvara prikaz');
  ok(/Dostava:.*besplatno/.test(await p10.textContent('#shipList')), 'v48: cijene dostave u „Kako naručiti”: ' + (await p10.textContent('#shipList')));
  await p10.evaluate(() => { location.hash = '#kolekcija'; }); await p10.waitForTimeout(300);
  const addB = p10.locator('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn');
  await addB.focus(); await p10.keyboard.press('Enter'); await p10.waitForTimeout(200);
  ok(await p10.evaluate(() => document.activeElement && document.activeElement.matches('.add-btn') && /Klasik/.test(document.activeElement.closest('.card').textContent)), 'v48: fokus ostaje na gumbu nakon dodavanja');
  await p10.evaluate(() => { location.hash = '#torbe'; }); await p10.waitForTimeout(300);
  await p10.click('.card-name:text-is("Luna")'); await p10.waitForTimeout(300);
  ok(await p10.evaluate(() => document.getElementById('lightbox').classList.contains('open')), 'v48: klik na ime torbe otvara prikaz');
  await p10.keyboard.press('Escape');
  await p10.click('#openCart'); await p10.waitForTimeout(300);
  ok(/1\skom\. · najviše jedan po narudžbi/.test(await p10.textContent('#cartItems')) && !(await p10.isVisible('#cartItems .q')) && await p10.isVisible('.strap-sel') && (await p10.locator('.strap-wish, .cart-note').count()) === 0 && /Odaberite boju i duljinu remena iz ponude — i za takvu torbu imate pravo na raskid ugovora u roku 14 dana\./.test(await p10.textContent('#cartItems')) && !/želj/.test(await p10.textContent('#cartItems')) && /Zatvori/.test(await p10.textContent('#closeCart')), 'v48/v64: model kolekcije u košarici — „1 kom”, bez +/−; oznaka napomene i „ako želju ne možemo ispuniti”; „Zatvori ×”');
  await p10.fill('#fEmail', 'ana.probic@primjer');
  await pickStrap(p10); await p10.click('#orderBtn'); await p10.waitForTimeout(300);
  const fix = await p10.textContent('#orderFix');
  ok(/ime i prezime/.test(fix) && /e-adresa/.test(fix) && /telefon/.test(fix) && /Uvjeta/.test(fix), 'v48: popis polja za ispravak: ' + fix);
  ok(!(await p10.locator('#fEmail').evaluate(el => el.checkValidity())), 'v48: e-mail bez točke u domeni ne prolazi');
  const ariaInv = await p10.evaluate(() => ['fName', 'fEmail'].map(id => [document.getElementById(id).getAttribute('aria-invalid'), (document.getElementById(id).getAttribute('aria-describedby') || '').includes('orderFix')]));
  ok(ariaInv.every(([a, d]) => a === 'true' && d), 'v83: neispravna polja imaju aria-invalid i upućuju na poruku ' + JSON.stringify(ariaInv));
  await p10.fill('#fName', 'Ana Probić'); await p10.waitForTimeout(100);
  ok(await p10.evaluate(() => !document.getElementById('fName').hasAttribute('aria-invalid')), 'v83: ispravljeno polje gubi aria-invalid');
  await m10.close();
  // v51: povećani prikaz artikla po narudžbi bez „14 dana”; prodana bez drugog gumba; napomena u čekanju
  const m11 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p11 = await m11.newPage(); watch(p11);
  await p11.goto(URL + '#torba-remen', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  const sh11 = await p11.textContent('#lbShip');
  ok(/Dostava od/.test(sh11) && /14 dana za raskid/.test(sh11) && !/Ručni rad ·/.test(sh11) && /Fotografija modela uskoro/.test(await p11.textContent('#lbAsk')), 'v51/v77: artikl po narudžbi — 14 dana za raskid (Uvjeti t. 7.1), bez ponovljenog „Ručni rad”, napomena o fotografiji modela: ' + sh11);
  await p11.goto(URL + '#torba-luna', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok(/14 dana za raskid/.test(await p11.textContent('#lbShip')) || await p11.isVisible('#lbGone'), 'v51: unikat — „14 dana za raskid”');
  await p11.goto(URL + '#torba-zora', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok(await p11.isVisible('#lbGone') && !(await p11.isVisible('#lbAdd')) && !/na upit/.test(await p11.textContent('#lightbox')), 'v51: prodana torba — jedan gumb, bez „na upit”');
  ok(/Rezervirane i prodane/.test(await p11.textContent('#productGrid')), 'v51: podnaslov „Rezervirane i prodane”');
  await p11.goto(URL + '#torba-tara', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok((await p11.textContent('#lbMaterial')).trim() === 'kvalitetna umjetna koža' && await p11.isVisible('#lbMaterialRow'), 'v52/v64: torba bez upisanog materijala — „umjetna koža” (odluka vlasnika), ne „na upit”');
  await p11.goto(URL + '#torba-remen', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok(await p11.isVisible('#lbLeadRow') && /^5 radnih dana od uplate$/.test((await p11.textContent('#lbLead')).trim()), 'v52/v83: artikl po narudžbi — rok izrade: ' + (await p11.textContent('#lbLead')));
  await p11.keyboard.press('Escape');
  await p11.goto(URL + '#ostalo', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok((await p11.$$eval('.card.made:not(.sold) .lead', els => els.length)) === (await p11.$$eval('.card.made:not(.sold)', els => els.length)), 'v52: svaki artikl po narudžbi ima redak „Izrada”');
  await p11.click('.card.made:not(.sold) .add-btn'); await p11.click('#openCart'); await p11.waitForTimeout(300);
  ok(JSON.stringify(await p11.$$eval('.trust-line li', l => l.map(x => x.textContent.trim()))) === JSON.stringify(['Bez registracije', 'Plaćanje uplatom', '14 dana za raskid', 'Zakonsko jamstvo 2 godine']) && !(await p11.$('.trust-line a[href="#uvjeti"]')) && await p11.$('label[for=fConsent] a[href="#uvjeti"]') && /14 dana/.test(await p11.textContent('label[for=fConsent]')), 'v95 (recenzija vijeća v94): traka u košarici bez ponovljenih Uvjeta (Uvjeti jednom, uz kvačicu)');
  await m11.close();
  await m10.close().catch(() => {});
  // v53 E: torba iz košarice u međuvremenu otišla — poruka ostaje (ne nestaje za 2,5 s)
  const m12 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p12 = await m12.newPage(); watch(p12);
  await p12.goto(URL, { waitUntil: 'networkidle' }); await p12.waitForTimeout(400);
  const freeBtn = p12.locator('.card:not(.sold):not(.made) .add-btn:not([disabled])').first();
  const fid = await freeBtn.getAttribute('data-id').catch(() => null);
  if (fid) {
    const fname = await p12.textContent(`.card:has(.add-btn[data-id="${fid}"]) .card-name`);
    await freeBtn.click(); await p12.waitForTimeout(200);
    const rp = SITE + 'data/rezervacije.json', r0 = fs.existsSync(rp) ? fs.readFileSync(rp, 'utf8') : null;
    const rj = r0 ? JSON.parse(r0) : { res: {}, sold: {} }; rj.res = rj.res || {};
    rj.res[fid] = { id: fid, token: 'tE2E', name: 'Drugi', email: 'd@p.hr', ordered: true, since: new Date().toISOString(), until: '2099-01-01T00:00:00Z' };
    fs.writeFileSync(rp, JSON.stringify(rj));
    await p12.click('#openCart'); await p12.waitForTimeout(3500);
    ok(new RegExp(fname + '.*više nije dostupna').test(await p12.textContent('#emptyCart')), 'v53 E: nestala torba — poruka ostaje u košarici (' + fname + ')');
    if (r0 !== null) fs.writeFileSync(rp, r0); else fs.unlinkSync(rp);
  } else ok(false, 'v53 E: nema slobodnog unikata u ispitnim podacima');
  await m12.close();
  // v62: Ostalo isključen, popunjen model, ploča za uplatu iz odgovora potvrde kad api/status padne
  {
    const cfgP = SITE + 'data/config.json', c62 = fs.readFileSync(cfgP, 'utf8'), pp = SITE + 'data/proizvodi.json', p62 = fs.readFileSync(pp, 'utf8');
    const cj = JSON.parse(c62); cj.show_ostalo = false; cj.require_confirm = true; cj.owner_email = 'vlasnica@drugi-e2e.hr'; cj.payment = Object.assign({}, cj.payment, { iban: 'HR1210010051863000160' });
    fs.writeFileSync(cfgP, JSON.stringify(cj));
    const pj = JSON.parse(p62); pj.forEach(x => { if (x.id === 'klasik') { x.made_limit = 0; } }); fs.writeFileSync(pp, JSON.stringify(pj));
    const m13 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p13 = await m13.newPage(); watch(p13);
    await p13.goto(URL + '#ostalo', { waitUntil: 'networkidle' }); await p13.waitForTimeout(400);
    ok(!(await p13.isVisible('#catTabs a[href="#ostalo"]')) && (await p13.textContent('#shopTitle')).trim() === 'Unikati', 'v62: Ostalo isključen — nema kartice, #ostalo vodi u Torbe');
    await p13.evaluate(() => { location.hash = '#kolekcija'; }); await p13.waitForTimeout(400);
    const k13 = p13.locator('.card:has(h3:has(.card-name:text-is("Klasik crna")))');
    ok(/Sva mjesta za ovaj model su zauzeta/.test(await k13.textContent()) && (await k13.locator('.add-btn').textContent()).trim() === 'Popunjeno' && await k13.locator('.add-btn').isDisabled() && await k13.locator('.ask-ig a[href^="mailto:"]').count() === 1, 'v62/v63: popunjen model — „Sva mjesta su zauzeta”, gumb „Popunjeno” neaktivan, e-mail poveznica');
    pj.forEach(x => { if (x.id === 'klasik') { x.made_limit = 20; } }); fs.writeFileSync(pp, JSON.stringify(pj));
    // narudžba unikata s potvrdom linkom; status pada pri otvaranju linka na drugom uređaju
    await p13.goto(URL + '#torbe', { waitUntil: 'networkidle' }); await p13.waitForTimeout(300);
    const free13 = p13.locator('#productGrid .card:not(.sold):not(.made) .add-btn:not([disabled])').first();
    await free13.click(); await p13.click('#openCart'); await p13.waitForTimeout(300);
    await p13.check('#shipOptions input[value="pickup"]');
    await p13.fill('#fName', 'Status Pada'); await p13.fill('#fEmail', 'status.pada@primjer.hr'); await p13.fill('#fPhone', '091 777 1313'); await p13.check('#fConsent');
    await pickStrap(p13); await p13.click('#orderBtn'); await p13.waitForSelector('.order-error.order-info', { timeout: 8000 }).catch(() => {});
    const l13 = [...fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8').matchAll(/\?potvrda=([a-f0-9]{32}\.[a-f0-9]{32})/g)].pop();
    const m14 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p14 = await m14.newPage(); watch(p14);
    await p14.route('**/api/status*', r => r.fulfill({ status: 500, body: '{}' }));
    await p14.route('**/api/confirm*', async r => { await new Promise(z => setTimeout(z, 800)); await r.continue(); });
    await p14.goto(URL + '?potvrda=' + (l13 ? l13[1] : 'x'), { waitUntil: 'domcontentloaded' }); await p14.waitForTimeout(300);
    const wait14 = await p14.textContent('#emptyCart').catch(() => '');
    await p14.waitForSelector('#confirmBtn', { timeout: 10000 }).catch(() => {}); await p14.click('#confirmBtn').catch(() => {}); /* v102: potvrda gumbom */
    await p14.waitForSelector('.pay-steps', { timeout: 10000 }).catch(() => {});
    const pay14 = await p14.textContent('.drawer-body').catch(() => '');
    ok(/Provjeravamo narudžbu/.test(wait14) && !/Košarica je prazna/.test(wait14), 'v62/v102: dok se link provjerava — „Provjeravamo narudžbu…”');
    ok(/vlasnica@drugi-e2e\.hr/.test(pay14) && /HR12 1001 0051 8630 0016 0/.test(pay14) && !/shivaj\.handmade@gmail\.com/.test(pay14), 'v62: api/status pada — ploča ipak s podacima iz Postavki (iz odgovora potvrde)');
    await m14.close(); await m13.close();
    fs.writeFileSync(cfgP, c62); fs.writeFileSync(pp, p62);
  }
  // v63: uvod u Torbe, skriveno Ostalo i stara košarica, popunjen model u košarici, izbornik na 900 px
  {
    const cfgP = SITE + 'data/config.json', c63 = fs.readFileSync(cfgP, 'utf8'), pp = SITE + 'data/proizvodi.json', p63 = fs.readFileSync(pp, 'utf8');
    const m15 = await browser.newContext({ viewport: { width: 1280, height: 800 } }); const p15 = await m15.newPage(); watch(p15);
    await p15.goto(URL + '#torbe', { waitUntil: 'networkidle' }); await p15.waitForTimeout(300);
    ok(/^Svaka torba postoji samo jednom/.test(await p15.textContent('#shopIntro')) && /Stalnoj kolekciji/.test(await p15.textContent('#shopIntro')), 'v63: uvod u rubriku Torbe (unikati, upućuje na Stalnu kolekciju)');
    await p15.evaluate(() => { location.hash = '#kolekcija'; }); await p15.waitForTimeout(300);
    ok(/na fotografiji je primjer/.test(await p15.textContent('#shopIntro')) && /Jasmina šije ponovno/.test(await p15.textContent('#shopIntro')) && /jedan komad po narudžbi/.test(await p15.textContent('#shopIntro')) && /rok izrade teče od primitka uplate/.test(await p15.textContent('#shopIntro')), 'v63: uvod u Stalnu kolekciju — primjer, Jasmina šije sama, rok od uplate');
    await m15.close();
    const cj = JSON.parse(c63); cj.show_ostalo = false; fs.writeFileSync(cfgP, JSON.stringify(cj));
    const m16 = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await m16.addInitScript(() => { if (!sessionStorage.getItem('v63')) { sessionStorage.setItem('v63', '1'); localStorage.setItem('shivaj.cart', JSON.stringify([{ id: 'remen', qty: 2, note: '' }, { id: 'klasik', qty: 3, note: 'crna' }])); } });
    const p16 = await m16.newPage(); watch(p16);
    await p16.goto(URL + '#torba-remen', { waitUntil: 'networkidle' }); await p16.waitForTimeout(600);
    ok(!(await p16.evaluate(() => document.getElementById('lightbox').classList.contains('open'))), 'v63: #torba- iz isključenog Ostalo ne otvara artikl');
    await p16.click('#openCart'); await p16.waitForTimeout(300);
    const c16 = await p16.textContent('.drawer-body');
    ok((await p16.textContent('#cartCount')).trim() === '1' && /Trenutačno nije u ponudi/.test(c16) && /Zamjenski remen/.test(c16), 'v63: stara košarica — artikl iz isključenog Ostalo izlazi uz obavijest, model kolekcije količina 1: ' + (await p16.textContent('#cartCount')).trim());
    await m16.close();
    fs.writeFileSync(cfgP, c63);
    const pj = JSON.parse(p63); pj.forEach(x => { if (x.id === 'klasik') x.made_limit = 0; }); fs.writeFileSync(pp, JSON.stringify(pj));
    const m17 = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await m17.addInitScript(() => { if (!sessionStorage.getItem('v63')) { sessionStorage.setItem('v63', '1'); localStorage.setItem('shivaj.cart', JSON.stringify([{ id: 'klasik', qty: 1, note: '' }])); } });
    const p17 = await m17.newPage(); watch(p17);
    await p17.goto(URL + '#kolekcija', { waitUntil: 'networkidle' }); await p17.waitForTimeout(600);
    await p17.click('#openCart'); await p17.waitForTimeout(300);
    const c17 = await p17.textContent('.drawer-body');
    ok((await p17.textContent('#cartCount')).trim() === '0' && /Klasik crna/.test(c17) && /Sva mjesta za izradu u međuvremenu su zauzeta/.test(c17), 'v63: model u košarici se popunio — izlazi iz košarice uz točan razlog');
    await m17.close();
    fs.writeFileSync(pp, p63);
    const m18 = await browser.newContext({ viewport: { width: 900, height: 800 } }); const p18 = await m18.newPage(); watch(p18);
    await p18.goto(URL, { waitUntil: 'networkidle' }); await p18.waitForTimeout(300);
    const nav900 = await p18.evaluate(() => ({ btn: getComputedStyle(document.getElementById('menuBtn')).display !== 'none', links: [...document.querySelectorAll('nav a.navlink')].filter(a => a.offsetParent).length }));
    await p18.setViewportSize({ width: 1024, height: 800 }); await p18.waitForTimeout(200);
    const nav1024 = await p18.evaluate(() => { const l = [...document.querySelectorAll('nav a.navlink')].filter(a => a.offsetParent); return { tops: new Set(l.map(a => Math.round(a.getBoundingClientRect().top))).size, h: Math.max(...l.map(a => a.getBoundingClientRect().height)), over: document.documentElement.scrollWidth > innerWidth }; });
    ok(nav900.btn && nav900.links === 0 && nav1024.tops === 1 && nav1024.h < 40 && !nav1024.over, 'v63: izbornik — hamburger na 900 px, na 1024 px jedan redak bez prijeloma: ' + JSON.stringify([nav900, nav1024]));
    await m18.close();
  }
  // v64: obrazac se ne zaglavi nakon promjene dostave; mješovita narudžba poimence; otvorena narudžba nije „greška e-maila”
  {
    { const zp = SITE + 'data/zahtjevi.json', zj = JSON.parse(fs.readFileSync(zp, 'utf8')); Object.keys(zj).forEach(k => { if (/^order\|/.test(k)) delete zj[k]; }); fs.writeFileSync(zp, JSON.stringify(zj)); } /* granica 10 narudžbi na sat s iste veze */
    { const rp = SITE + 'data/rezervacije.json', rj = JSON.parse(fs.readFileSync(rp, 'utf8')); rj.res = {}; fs.writeFileSync(rp, JSON.stringify(rj)); } /* v68: svaka narudžba rezervira — oslobodi unikate za nastavak */
    const m19 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p19 = await m19.newPage(); watch(p19);
    await p19.goto(URL + '#torbe', { waitUntil: 'networkidle' }); await p19.waitForTimeout(300);
    await p19.locator('#productGrid .card:not(.sold):not(.made) .add-btn:not([disabled])').first().click();
    await p19.evaluate(() => { location.hash = '#kolekcija'; }); await p19.waitForTimeout(300);
    await p19.click('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn');
    await p19.click('#openCart'); await p19.waitForTimeout(300);
    await p19.check('#shipOptions input[value="boxnow"]');
    await p19.fill('#fName', 'Dostava Promjena'); await p19.fill('#fEmail', 'dostava.promjena@primjer.hr'); await p19.fill('#fPhone', '091 191 1919'); await p19.check('#fConsent');
    await pickStrap(p19); await p19.click('#orderBtn'); await p19.waitForTimeout(300);
    let sent19 = 0; p19.on('request', r => { if (/\/api\/order/.test(r.url())) sent19++; });
    await p19.check('#shipOptions input[value="pickup"]'); await p19.waitForTimeout(200);
    await pickStrap(p19); await p19.click('#orderBtn');
    await p19.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
    ok(sent19 > 0, 'v64: BOX NOW bez paketomata → preuzimanje → „Naruči” šalje narudžbu (obrazac se ne zaglavi)');
    const st19 = await p19.textContent('.pay-steps').catch(() => '');
    ok(/Torbu „Klasik crna” šijemo u roku od 5 radnih dana/.test(st19) && /cijelu narudžbu pripremamo za preuzimanje/.test(st19), 'v64: mješovita narudžba — model kolekcije poimence: ' + st19.slice(0, 200));
    await m19.close();
    const m20 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p20 = await m20.newPage(); watch(p20);
    await p20.goto(URL + '#kolekcija', { waitUntil: 'networkidle' }); await p20.waitForTimeout(300);
    await p20.click('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn'); await p20.click('#openCart'); await p20.waitForTimeout(300);
    await p20.check('#shipOptions input[value="pickup"]');
    await p20.fill('#fName', 'Dostava Promjena'); await p20.fill('#fEmail', 'dostava.promjena@primjer.hr'); await p20.fill('#fPhone', '091 191 1919'); await p20.check('#fConsent');
    await pickStrap(p20); await p20.click('#orderBtn'); await p20.waitForSelector('.order-error', { timeout: 8000 }).catch(() => {});
    const e20 = await p20.textContent('.order-error').catch(() => '');
    const foc20 = await p20.evaluate(() => document.activeElement && document.activeElement.id);
    ok(await p20.locator('#orderBtn.is-blocked').count() === 1, 'v66: gumb za narudžbu izgleda neaktivno dok prethodna narudžba čeka uplatu');
    ok(/Prethodna narudžba iz Stalne kolekcije čeka uplatu\. Podatke za uplatu imate u e-\u2060?mailu s potvrdom narudžbe/.test(e20) && await p20.locator('.order-error.order-info').count() === 1 && !/Provjerite e-mail adresu/.test(e20) && foc20 !== 'fEmail', 'v64: druga narudžba iz kolekcije s istog e-maila — „Već imate otvorenu narudžbu”, ne „Provjerite e-mail adresu”: ' + e20.slice(0, 120));
    await m20.close();
  }
  // v65: košarica u dva stupca, ukupno čekanje prije narudžbe, napomena, „od uplate”, e-mail mješovite narudžbe, povratak na karticu
  {
    { const zp = SITE + 'data/zahtjevi.json', zj = JSON.parse(fs.readFileSync(zp, 'utf8')); Object.keys(zj).forEach(k => { if (/^order\|/.test(k)) delete zj[k]; }); fs.writeFileSync(zp, JSON.stringify(zj)); }
    const m21 = await browser.newContext({ viewport: { width: 1280, height: 900 } }); const p21 = await m21.newPage(); watch(p21);
    await p21.goto(URL + '#kolekcija', { waitUntil: 'networkidle' }); await p21.waitForTimeout(300);
    const kc21 = p21.locator('.card:has(h3:has(.card-name:text-is("Klasik crna")))');
    ok(/Izrada: 5 radnih dana od uplate/.test(await kc21.textContent()), 'v65: kartica — „Izrada: 5 radnih dana od uplate”');
    await kc21.locator('.add-btn').click();
    await p21.evaluate(() => { location.hash = '#torbe'; }); await p21.waitForTimeout(300);
    const u21 = p21.locator('#productGrid .card:not(.sold):not(.made) .add-btn:not([disabled])').first();
    const u21name = (await p21.locator('#productGrid .card:not(.sold):not(.made):has(.add-btn:not([disabled])) .card-name').first().textContent()).trim();
    await u21.click(); await p21.click('#openCart'); await p21.waitForTimeout(300);
    await p21.check('#shipOptions input[value="gls"]'); await p21.waitForTimeout(200);
    const edges = await p21.evaluate(() => [...document.querySelectorAll('#cartItems .price'), document.getElementById('totalAmount')].map(e => Math.round(e.getBoundingClientRect().right)));
    ok(edges.length >= 4 && Math.max(...edges) - Math.min(...edges) <= 2, 'v65: košarica na 1280 — sve cijene uz isti desni rub: ' + edges.join(','));
    const lead21 = await p21.textContent('.order-lead');
    ok(new RegExp('Sve šaljemo zajedno, u jednom paketu: „' + u21name + '” čeka da sašijemo model „Klasik crna” \\(izrada 5 radnih dana od uplate\\), zatim slanje u roku 1\u2060–\u20602 radna dana\\. Želite li torbu „' + u21name + '” ranije, naručite je zasebno \\(tada plaćate dvije dostave\\)\\.').test(lead21), 'v65: mješovita košarica — prije narudžbe piše da sve ide zajedno i koliko traje: ' + lead21);
    ok((await p21.textContent('#fNoteLabel')).trim() === 'Napomena uz dostavu (neobvezno)' && await p21.isVisible('#fNoteHint') && /Boju i duljinu remena odaberite gore, uz model\./.test(await p21.textContent('#fNoteHint')) && await p21.locator('.cart-wish').count() === 1 && await p21.locator('#orderNoteMade').isHidden(), 'v65: opća napomena upućuje na polje uz model');
    await p21.fill('#fCity', 'Zagreb'); const pcG = await p21.evaluate(() => { zipCheck(); return document.getElementById('fZip').checkValidity(); });
    await p21.check('#shipOptions input[value="boxnow"]'); await p21.waitForTimeout(150); const pcB = await p21.evaluate(() => document.getElementById('fZip').checkValidity());
    const zipAc = await p21.evaluate(() => [document.getElementById('fZip').autocomplete, document.getElementById('fCity').autocomplete]);
    ok(zipAc[0] === 'postal-code' && zipAc[1] === 'address-level2', 'v98 (recenzija vijeća v97, mobitel L5): poštanski broj i mjesto u dva polja s automatskim popunjavanjem ' + JSON.stringify(zipAc));
    await p21.check('#shipOptions input[value="gls"]'); await p21.waitForTimeout(150);
    ok(pcG === false && pcB === true, 'v82: poštanski broj obavezan samo uz dostavu na adresu — skriveno polje ne blokira slanje (GLS ' + pcG + ', BOX NOW ' + pcB + ')');
    await p21.fill('#fName', 'Mješovita Gls'); await p21.fill('#fEmail', 'mjesovita.gls@primjer.hr'); await p21.fill('#fPhone', '091 212 1212');
    await p21.fill('#fAddr', 'Ilica 1'); await p21.fill('#fZip', '10000'); await p21.fill('#fCity', 'Zagreb'); await p21.check('#fConsent');
    await pickStrap(p21); await p21.click('#orderBtn'); await p21.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
    const ml21 = fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8').split(/^=== /m).filter(x => /potvrda narudžbe SJ-/.test(x.split('\n')[0]) && /Mješovita Gls/.test(x)).pop()?.split('UVJETI KUPNJE (vrijede')[0] || '';
    ok(new RegExp('cijelu narudžbu — „(' + u21name + '|Klasik crna)” i „(' + u21name + '|Klasik crna)” — šaljemo zajedno').test(ml21) && /Čim uplata bude vidljiva, šijemo model „Klasik crna” \(rok izrade 5 radnih dana od primitka uplate\), zatim cijelu narudžbu — /.test(ml21) && /u jednom paketu; slanje je u roku 1–2 radna dana nakon izrade\./.test(ml21) && !/Rok izrade/.test(ml21), 'v65: e-mail mješovite narudžbe — nazivi s „i”, rok uz model, slanje nakon izrade');
    await m21.close();
    const m22 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p22 = await m22.newPage(); watch(p22);
    await p22.goto(URL + '#torba-tara', { waitUntil: 'networkidle' }); await p22.waitForTimeout(500);
    await p22.keyboard.press('Escape'); await p22.waitForTimeout(500);
    const vis22 = await p22.evaluate(() => { const c = [...document.querySelectorAll('#productGrid .card')].find(x => /Tara/.test(x.querySelector('.card-name')?.textContent || '')); if (!c) return false; const r = c.getBoundingClientRect(); return r.bottom > 0 && r.top < innerHeight && scrollY > 200; });
    const dbg22 = await p22.evaluate(() => { const c = [...document.querySelectorAll('#productGrid .card')].find(x => /Tara/.test(x.querySelector('.card-name')?.textContent || '')); const r = c && c.getBoundingClientRect(); return JSON.stringify({ found: !!c, top: r && Math.round(r.top), bottom: r && Math.round(r.bottom), sy: scrollY, gone: !!(c && c.closest('.grid-gone')), lb: document.getElementById('lightbox').classList.contains('open') }); });
    ok(vis22, 'v65: zatvaranje povećanog prikaza otvorenog linkom vraća na karticu torbe ' + dbg22);
    ok(!/#torba-/.test(await p22.evaluate(() => location.hash)), 'v66: zatvaranje povećanog prikaza briše #torba- iz adrese');
    await m22.close();
    const m23 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p23 = await m23.newPage(); watch(p23);
    await p23.goto(URL + '#ostalo', { waitUntil: 'networkidle' }); await p23.waitForTimeout(300);
    const rem = p23.locator('#productGrid .card.made .add-btn:not([disabled])');
    if (await rem.count() > 0) {
      await rem.first().click(); await p23.click('#openCart'); await p23.waitForTimeout(300);
      const c23 = await p23.textContent('.drawer-body');
      ok(!/Stalne kolekcije|torbu bez te želje/i.test(c23) && /za takav artikl imate pravo na raskid ugovora u roku 14 dana/.test(c23), 'v66/v73: Ostalo u košarici — bez teksta za torbe iz kolekcije, napomena o boji iz ponude i povratu');
      const ph23 = await p23.getAttribute('.cart-note', 'placeholder');
      ok(/Boja( i duljina)? iz opisa artikla \(neobvezno\)/.test(c23) && !/želj/i.test(c23) && (/Boja i duljina iz opisa/.test(c23) ? ph23 === 'npr. crna, 100 cm' : ph23 === 'npr. crna'), 'v78/v96: Ostalo u košarici — polje „Boja (i duljina) iz opisa artikla”, duljina samo za remene, bez riječi „želja”');
      const ut = await p23.textContent('#uvjeti');
      ok(await p23.locator('[data-terms-auto-wrap]').evaluate(el => el.hidden) && /Zasebnu isporuku prihvatljivu za okoliš ne nudimo\./.test(ut) && /ništa nam ne dugujete/.test(ut),
        'v78: Uvjeti — bez rokova (ispitni podaci) sati automatskog storna skriveni; t. 6 pakiranje i isporuka za okoliš; t. 7.3 raskid prije uplate');
    } else ok(false, 'v66: nema artikla iz Ostalo s fotografijom u ispitnim podacima');
    await m23.close();
  }
  // v68: besplatna dostava (prag iz Postavki, samo BOX NOW), granice narudžbe odmah kod dodavanja, „Veličina i nošenje”, podrubrike Ostalog
  {
    const cp = SITE + 'data/config.json', cj = JSON.parse(fs.readFileSync(cp, 'utf8'));
    cj.free_ship = { min: 120, ids: ['boxnow'] }; cj.sizes = [{ mjera: '26 × 7 cm', opis: 'Stane mobitel, novčanik i ključevi.' }]; cj.size_all = 'Remen je podesiv, do 120 cm.';
    fs.writeFileSync(cp, JSON.stringify(cj, null, 2));
    { const rp = SITE + 'data/rezervacije.json', rj = JSON.parse(fs.readFileSync(rp, 'utf8')); rj.res = {}; rj.sold = {}; fs.writeFileSync(rp, JSON.stringify(rj)); }
    for (const f of [SITE + 'torbe.json', SITE + 'data/proizvodi.json']) {
      const pl = JSON.parse(fs.readFileSync(f, 'utf8'));
      pl.forEach(x => { if (x.id === 'nera') { x.size = '26 × 7 cm'; x.dims = '26 × 7 cm'; x.reserved = false; x.sold = false; } if (x.id === 'remen') x.sub = 'remeni'; if (x.id === 'platnena') x.sub = 'platnene'; });
      fs.writeFileSync(f, JSON.stringify(pl, null, 2));
    }
    const m68 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p68 = await m68.newPage(); watch(p68);
    await p68.goto(URL + '#torbe', { waitUntil: 'networkidle' }); await p68.waitForTimeout(400);
    await p68.evaluate(() => { localStorage.clear(); }); await p68.reload({ waitUntil: 'networkidle' }); await p68.waitForTimeout(400);
    await p68.click('.card:has(h3:has(.card-name:text-is("Nera"))) .photo');
    await p68.waitForTimeout(300);
    ok(await p68.isVisible('#lbSize') && /Veličina i nošenje/.test(await p68.textContent('#lbSize')) && (await p68.textContent('#lbSizeText')) === 'Stane mobitel, novčanik i ključevi.' && (await p68.textContent('#lbSizeAll')) === 'Remen je podesiv, do 120 cm.' && /BOX NOW paketomat besplatno od 120,00\s€/.test(await p68.textContent('#lbShip')), 'v68/v69: povećani prikaz — „Veličina i nošenje” u dva odlomka (opis mjere, tekst za sve torbe); prag besplatne dostave uz dostavu');
    await p68.click('#lbAdd'); await p68.waitForTimeout(300);
    await p68.evaluate(() => { const lb = document.getElementById('lightbox'); if (lb && lb.classList.contains('open')) closeLightbox(); });
    await p68.evaluate(() => { location.hash = '#ostalo'; }); await p68.waitForTimeout(400);
    const subs = await p68.$$eval('#productGrid .grid-sub span', l => l.map(x => x.textContent));
    ok(subs[0] === 'Remeni' && subs.includes('Platnene torbe') && subs.indexOf('Remeni') < subs.indexOf('Platnene torbe'), 'v68: Ostalo po podrubrikama redom (' + subs.join(', ') + ')');
    await p68.click('.card:has(h3:has(.card-name:text-is("Platnena torba za plažu"))) .add-btn'); await p68.waitForTimeout(200);
    await p68.click('#openCart'); await p68.waitForTimeout(300);
    await p68.check('#shipOptions input[value="boxnow"]'); await p68.waitForTimeout(200);
    const fs1 = await p68.textContent('#cartItems');
    ok(/Još 10,00\s€ do besplatne dostave \(BOX NOW paketomat, od 120,00\s€\)/.test(fs1) && /4,00\s€/.test(await p68.textContent('#shipOptions')) && await p68.isVisible('#shipOptions [data-free-hint="boxnow"]') && /besplatno od 120,00\s€/.test(await p68.textContent('#shipOptions [data-free-hint="boxnow"]')), 'v68/v69: košarica 110 € — „Još 10,00 € do besplatne dostave (… od 120 €)”, BOX NOW 4 € i „besplatno od 120 €” uz opciju');
    await p68.evaluate(() => toggleCartItem('kozmeticka')); await p68.waitForTimeout(300);
    const fs2 = await p68.textContent('#cartItems'), tot2 = await p68.textContent('#totalAmount');
    ok(/Ostvarili ste besplatnu dostavu \(BOX NOW paketomat\)/.test(fs2) && !(await p68.isVisible('#shipOptions [data-free-hint="boxnow"]')) && /130,00/.test(tot2) && /besplatno/.test(await p68.textContent('#shipOptions label:has(input[value="boxnow"])')) && /6,00\s€/.test(await p68.textContent('#shipOptions label:has(input[value="gls"])')), 'v68: 130 € — BOX NOW besplatno (ukupno 130 €), GLS i dalje 6 €');
    await p68.check('#shipOptions input[value="gls"]'); await p68.waitForTimeout(200);
    ok(/136,00/.test(await p68.textContent('#totalAmount')) && /Uz BOX NOW paketomat dostava je besplatna/.test(await p68.textContent('#cartItems')), 'v68: uz GLS dostava se plaća, a košarica kaže da je uz BOX NOW besplatna');
    await p68.evaluate(() => { closeCart && closeCart(); toggleCartItem('luna'); });
    await p68.waitForTimeout(200);
    await p68.evaluate(() => toggleCartItem('tara')); await p68.waitForTimeout(200);
    const toastLim = await p68.textContent('#toast');
    ok(/mogu biti najviše 2 unikatne torbe; u košarici su već „Nera” i „Luna”/.test(toastLim) && !(await p68.evaluate(() => cart.some(c => c.id === 'tara'))), 'v68/v69: treća unikatna torba — poruka odmah kaže koja je granica i koje unikate kupac već ima; torba nije dodana');
    /* v69: rok za uplatu iznad kvačice i kad u košarici nema unikata; „+” onemogućen na granici količine */
    await p68.evaluate(() => { cart = []; saveCart(); renderGrid(); renderCart(); toggleCartItem('remen'); });
    await p68.evaluate(() => { const r = cart.find(c => c.id === 'remen'); r.qty = 10; saveCart(); renderCart(); openCart(); }); await p68.waitForTimeout(300);
    ok(await p68.isVisible('#orderHold') && /Rok za uplatu je 24 sata od narudžbe \(preko vikenda i blagdana do 23:59 prvoga sljedećeg radnog dana\); ono što šijemo po narudžbi izrađujemo nakon uplate\./.test(await p68.textContent('#orderHold')) && !(await p68.isVisible('#orderNoteMade'))
      && await p68.isDisabled('.cart-item .q[data-d="1"]') && /najviše 10 artikala — za više nam se javite/.test(await p68.textContent('.cart-item .qty-max')) && await p68.isVisible('.cart-item .qty-max'), 'v69: bez unikata rok za uplatu iznad kvačice; „+” onemogućen na granici od 10 artikala');
    /* v69: Ostalo — veze na podrubrike i dva stupca na mobitelu */
    await p68.evaluate(() => { closeCart(); location.hash = '#ostalo'; }); await p68.waitForTimeout(400);
    const cols = await p68.evaluate(() => getComputedStyle(document.getElementById('productGrid')).gridTemplateColumns.split(' ').length);
    ok(await p68.locator('#productGrid .sub-links a').count() >= 2 && cols === 2, 'v69: Ostalo — veze na podrubrike, na mobitelu dva stupca (' + cols + ')');
    await m68.close();
  }
  // v69: „Uplatio/la sam” iz e-maila — stranica pita, tek gumb šalje; rok produžen jednom
  {
    const oc = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8')).find(o => o.claim && !o.paidAt && !o.cancelledAt);
    const mc = await browser.newContext({ viewport: { width: 390, height: 844 } }); const pc = await mc.newPage(); watch(pc);
    await pc.goto(URL + '?uplata=' + encodeURIComponent(oc.no) + '&t=' + oc.claim, { waitUntil: 'networkidle' }); await pc.waitForTimeout(500);
    const ask = await pc.textContent('#claimBox').catch(() => '');
    const urlClean = !/uplata=/.test(pc.url());
    const before = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8')).find(o => o.no === oc.no);
    ok(/jeste li već uplatili/.test(ask) && new RegExp('rok za uplatu produljujemo do (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje),\\s\\d{1,2}\\.\\s\\d{1,2}\\.\\s\\d{4}\\.\\su\\s\\d{2}:\\d{2} i više vam ne šaljemo podsjetnike \\(torb[ue] ionako čuvamo do tada\\)').test(ask) && /Nemojte klikati/.test(ask) && urlClean && !before.claimedAt, 'v69: link „Uplatio/la sam” otvara pitanje u košarici; bez klika se ništa ne mijenja, adresa se čisti');
    await pc.click('#claimBtn'); await pc.waitForTimeout(600);
    const after = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8')).find(o => o.no === oc.no);
    ok(/Torbu čuvamo za vas do/.test(await pc.textContent('#claimBox')) && !!after.claimedAt, 'v69/v70: klik „Da, uplatio/la sam” produžuje rok i zahvaljuje kupcu');
    await mc.close();
  }
  { const m76 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p76 = await m76.newPage(); watch(p76);
    await p76.goto(URL + '#kolekcija', { waitUntil: 'networkidle' }); await p76.waitForTimeout(300);
    await p76.click('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn'); await p76.click('#openCart'); await p76.waitForTimeout(300);
    await p76.check('#shipOptions input[value="pickup"]'); await p76.fill('#fName', 'Remen Test'); await p76.fill('#fEmail', 'remen.test@primjer.hr'); await p76.fill('#fPhone', '091 767 7676'); await p76.check('#fConsent');
    await p76.click('#orderBtn'); await p76.waitForTimeout(200);
    const fix76 = await p76.textContent('#orderFix'), foc76 = await p76.evaluate(() => document.activeElement && document.activeElement.dataset.k);
    ok(/Za „Klasik crna” odaberite boju i duljinu remena\./.test(fix76) && foc76 === 'sc' && !(await p76.isVisible('.pay-steps')), 'v77 (recenzija R76-D1): bez boje i duljine remena narudžba iz Stalne kolekcije ne ide, fokus na izbornik');
    const nC = await p76.locator('.strap-sel[data-k="sc"] option').count(), nL = await p76.locator('.strap-sel[data-k="sl"] option').count();
    await p76.selectOption('.strap-sel[data-k="sc"]', 'tamno zelena'); await p76.selectOption('.strap-sel[data-k="sl"]', '100 cm');
    ok(await p76.locator('#orderFix').isHidden(), 'v77 (vijeće V77-5): poruka o remenu nestaje kad su boja i duljina odabrane');
    const note76 = await p76.evaluate(() => (cart.find(c => c.id === 'klasik') || {}).note);
    await p76.reload({ waitUntil: 'networkidle' }); await p76.click('#openCart'); await p76.waitForTimeout(300);
    const keep = await p76.evaluate(() => [document.querySelector('.strap-sel[data-k="sc"]').value, document.querySelector('.strap-sel[data-k="sl"]').value, document.querySelectorAll('.strap-wish').length]);
    const over76 = await p76.evaluate(() => document.querySelector('.drawer-body').scrollWidth - document.querySelector('.drawer-body').clientWidth);
    ok(nC === 9 && nL === 3 && note76 === 'remen: tamno zelena, 100 cm' && keep.join('|') === 'tamno zelena|100 cm|0' && over76 <= 0 && /Odaberite boju i duljinu remena iz ponude/.test(await p76.textContent('#cartWish')),
      'v76: Stalna kolekcija — izbornici boje (8) i duljine remena (140/100 cm), v78: bez polja za posebnu želju; napomena „remen: …”, pamti se, bez klizanja (390 px)');
    await m76.close(); }
  ok(errors.length === 0, 'bez JS grešaka' + (errors.length ? ': ' + errors.join(' ; ') : ''));
  // ---------- v73: funkcija za raskid (ZZP čl. 81.a), redak uz gumb, ručni rad u prikazu torbe ----------
  { const m73 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p73 = await m73.newPage(); watch(p73);
    await p73.goto(URL, { waitUntil: 'networkidle' });
    const fl = p73.locator('footer a.foot-raskid');
    ok(await fl.count() === 1 && (await fl.textContent()).trim() === 'Raskid ugovora' && (await fl.getAttribute('href')) === '#raskid', 'v73: „Raskid ugovora” istaknuto u podnožju (čl. 81.a st. 2.)');
    ok(/Izjava o zaštiti osobnih podataka/.test(await p73.textContent('#privatnost h2')) && /Izjava\so\szaštiti osobnih podataka/.test(await p73.textContent('footer')) /* v100: u flex poveznici neprelomivi razmak */, 'v73: Izjava o zaštiti osobnih podataka — naslov i podnožje');
    { const u73 = await p73.textContent('#uvjeti');
      ok(/nakon primitka uplate, u roku 1–2 radna dana\. Modele/.test(u73) && !/Slanje u roku/.test(u73) && /najkasnije u roku 14 dana od primitka uplate/.test(u73), 'v75 (vijeće V75-1): Uvjeti t. 6 — rok slanja iz Postavki, bez rečenice iz košarice; Stalna kolekcija 14 dana'); }
    await p73.click('footer a.foot-raskid'); await p73.waitForTimeout(300);
    await p73.click('#raskidNext'); await p73.waitForTimeout(100);
    ok(/Molimo upišite: ime i prezime, broj narudžbe .*ispravnu e-adresu za potvrdu/.test(await p73.textContent('#raskidError')) && await p73.isVisible('#raskidStep1'), 'v73: raskid — prazna polja ostaju na prvom koraku s porukom');
    await p73.fill('#rName', 'Ana Raskid'); await p73.fill('#rNo', '2026-0999'); await p73.fill('#rEmail', 'ana.raskid@primjer.hr'); await p73.fill('#rIban', 'HR12 1001 0051 8630 0016 0');
    await p73.click('#raskidNext'); await p73.waitForTimeout(100);
    const prev = await p73.textContent('#raskidPreview');
    ok(await p73.isVisible('#raskidStep2') && /Ovim izjavljujem da jednostrano raskidam ugovor o prodaji sljedeće robe:\nBroj narudžbe: 2026-0999\nIme i prezime: Ana Raskid\nE-adresa: /.test(prev) && (await p73.textContent('#raskidConfirm')).trim() === 'Potvrditi raskid ugovora', 'v73: raskid — drugi korak: pregled izjave i „Potvrditi raskid ugovora” (st. 4.–5.)');
    const over73 = await p73.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    await p73.screenshot({ path: OUT + 'm-73-raskid.png' });
    await p73.click('#raskidConfirm'); await p73.waitForSelector('#raskidDone:not([hidden])', { timeout: 8000 }).catch(() => {});
    const done = await p73.textContent('#raskidDone').catch(() => '');
    const mlR = fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8');
    ok(/Vaša izjava o raskidu zaprimljena je \d{1,2}\. \d{1,2}\. \d{4}\. u \d\d:\d\d\./.test(done) && /ana\.raskid@primjer\.hr/.test(done) && /potvrda primitka izjave o raskidu ugovora/.test(mlR) && !over73, 'v73: raskid — potvrda na zaslonu s datumom i vremenom, e-mail kupcu, bez vodoravnog klizanja (390 px)');
    await p73.goto(URL + '#raskid?no=2026-0042', { waitUntil: 'networkidle' }); await p73.reload({ waitUntil: 'networkidle' }); await p73.waitForTimeout(300);
    ok((await p73.inputValue('#rNo')) === 'SJ-2026-0042', 'v73: poveznica iz e-maila popunjava broj narudžbe');
    await p73.click('.card .card-name >> nth=0'); await p73.waitForTimeout(300);
    ok(/Ručni rad — moguća su mala odstupanja u šavu i nijansi u odnosu na fotografiju\./.test(await p73.textContent('#lightbox .lb-handmade')), 'v73: prikaz torbe — napomena o ručnom radu');
    await p73.click('#lbCare summary'); await p73.waitForTimeout(100);
    ok(await p73.isVisible('#lbCare') && /Bok, ja sam tvoja nova torba\./.test(await p73.textContent('#lbCare')) && await p73.isVisible('#lbCare .lb-care-t'), 'v74: „Njega torbe” u prikazu torbe od umjetne kože, otvara se klikom');
    await p73.keyboard.press('Escape');
    await p73.click('.card .add-btn:not([disabled]) >> nth=0'); await p73.click('#openCart'); await p73.waitForTimeout(300);
    const terms = await p73.textContent('label[for=fConsent]').catch(() => '');
    const pos = await p73.evaluate(() => { const t = document.querySelector('label[for=fConsent]'), b = document.getElementById('orderBtn'); return !!(t && b && (t.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)); });
    ok(/Prihvaćam Uvjete kupnje, upoznat\/a sam s pravom na jednostrani raskid u roku 14 dana i s Izjavom o zaštiti osobnih podataka\./.test(terms) && pos && !(await p73.$('.order-terms')) && (await p73.textContent('#orderBtn')).trim() === 'Naruči s obvezom plaćanja', 'v73/v95: iznad gumba „Naruči s obvezom plaćanja” kvačica s Uvjetima i Izjavom (bez ponovljenog retka)');
    await m73.close(); }
  await browser.close();
  const cnt = JSON.parse(fs.readFileSync(SITE + 'data/brojac.json', 'utf8'));
  const day = Object.values(cnt)[0] || {};
  ok(day.visits === 30 && day.ig === 1 && day.orders === 10 && day.orders_m === 3 && day.press === 1 && day.rad >= 1 && day.cart === 31 && day.copy >= 1, 'brojač (v73, v76: +1 košarica u testovima raskida i remena; v100: +1 test 503; v102: +11 košarica u testovima mobitela, remena, potvrde i kartica): ' + JSON.stringify(cnt));
  const ml = fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8');
  ok(/Dobar dan, Test Kupac/.test(ml) && !/Draga\/Dragi/.test(ml) && /čeka vašu uplatu/.test(ml) && /Opis plaćanja: Shiva\.J 2026-/.test(ml) && /javit ćemo vam se i dogovoriti termin/.test(ml) && /jednostrano raskinuti u roku 14 dana/.test(ml) && /ROK ZA UPLATU: do [^\n]* u \d\d:\d\d( \(u noći [^)]*\))?\./.test(ml), 'e-mail kupcu: pozdrav, čeka uplatu, opis, preuzimanje, raskid, rok');
  ok(/Narudžba bez unikata: kad kupac uplati/.test(ml), 'e-mail vlasnici: uputa za Plaćeno');
  const zj = JSON.parse(fs.readFileSync(SITE + 'data/zahtjevi.json', 'utf8'));
  ok(Object.keys(zj).every(k => !/visit\|127\.|count\|127\./.test(k)), 'ograničenje zahtjeva brojača bez prave adrese: ' + Object.keys(zj).join(','));
  ok(!/@|Kupac|127\.0/.test(JSON.stringify(cnt)), 'brojač bez osobnih podataka');
  console.log(log.join('\n'));
  const fails = log.filter(l => l.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + log.length + ')');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.log(log.join('\n')); console.error('SCRIPT ERROR', e); process.exit(1); });
