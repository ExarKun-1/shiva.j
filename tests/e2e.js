const { chromium } = require('playwright');
const fs = require('fs');
/* Testovi u pregledniku (Playwright):  sh tests/serve.sh &  pa  node tests/e2e.js
   SHIVAJ_SITE = probna stranica (zadano /tmp/shivaj-test), SHIVAJ_SHOTS = mapa za snimke,
   CHROMIUM = putanja do preglednika ako Playwright nema svoj. Testovi pretpostavljaju svježu probnu stranicu. */
const SITE = (process.env.SHIVAJ_SITE || '/tmp/shivaj-test') + '/';
const OUT = (process.env.SHIVAJ_SHOTS || SITE + 'snimke') + '/';
const URL = process.env.SHIVAJ_URL || 'http://127.0.0.1:8090/';
const log = [];
const ok = (c, m) => log.push((c ? 'PASS ' : 'FAIL ') + m);

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  let browser;
  try { browser = await chromium.launch(process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {}); } catch (e) { browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); }
  const errors = [];
  const watch = pg => { pg.on('pageerror', e => errors.push(e.message)); pg.on('console', m => { if (m.type() === 'error' && !/bunny|ERR_TUNNEL|Failed to load resource/.test(m.text())) errors.push(m.text()); }); };

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
  ok(/Tekstilno-tehnološkom/.test(rad) && /umjetne kože/.test(rad) && !/skaj/i.test(rad) && /Matije Gupca 33/.test(rad) && /s torbom u ruci/.test(rad), 'Radionica: umjetna koža, adresa, potvrđene rečenice');
  ok(/Šivalec\. Što radim, piše mi već u prezimenu/.test(rad) && /J od mene/.test(rad) && /rezom, a završava šavom/.test(rad) && /Ono što tako nastane/.test(rad) && /druge jednobojne/.test(rad) && /dana šivanja/.test(rad) && !/[{}]/.test(rad), 'Radionica v27: dorađene rečenice, bez {…}');
  ok(/Šarena se dogodi/.test(await pd.textContent('.about-quote')) && /Jasmina/.test(await pd.textContent('.about-quote small')) && !/po narudžbi: šarena se dogodi/.test(await pd.textContent('#radionica')), 'citat samo lijevo, s potpisom');
  ok(/modele iz Stalne kolekcije šijemo po narudžbi/.test(await pd.getAttribute('meta[property="og:description"]', 'content')) && /ni raspored boja/.test(await pd.textContent('#hero')) && /osim artikala izrađenih po vašoj želji/.test(await pd.textContent('#uvjeti')), 'og opis, hero „raspored boja”, ograda u Ukratko');
  ok(/Ručno šivane torbe iz Zaboka/.test(await pd.title()) && /Ručno šivane torbe iz Zaboka/.test(await pd.textContent('footer')), 'slogan u naslovu i podnožju');
  ok(/potvrdite narudžbu linkom iz e-maila; tada unikatnu torbu odmah rezerviramo/.test(await pd.textContent('#uvjeti')) && /potvrdite linkom iz e-maila, torbu rezerviramo za vas 24 sata i nitko je drugi ne može naručiti\. To je ujedno rok za uplatu; ako uplata ne stigne, torba se vraća u ponudu/.test(await pd.textContent('#kako')) && !/Time je unikatna torba/.test(await pd.textContent('#uvjeti')) && /Artikli po narudžbi ne rezerviraju se/.test(await pd.textContent('#uvjeti')), 'Uvjeti: Ukratko i t. 4 usklađeni');
  ok(!/šaren/i.test(await pd.evaluate(() => [...document.querySelectorAll('#kako, #torbe, #emptyCart, #orderForm, meta')].map(e => e.textContent + (e.getAttribute('content') || '')).join(' '))), 'v62: bez riječi „šarena” u tekstovima kupnje');
  ok((await pd.getAttribute('#radionica a[href^="mailto:"]', 'href')) === 'mailto:shivaj.handmade@gmail.com', 'e-mail kao mailto');
  ok(!/Svaka torba postoji/.test(await pd.getAttribute('meta[name="description"]', 'content')), 'opis za tražilice bez „svaka torba”');
  ok((await pd.textContent('#shopTitle')).trim() === 'Torbe' && /unikata dostupn[oa]$/.test((await pd.textContent('#availCount')).trim()), 'naslov „Torbe” i brojač unikata: ' + (await pd.textContent('#availCount')));
  const order48 = await pd.evaluate(() => Array.from(document.querySelectorAll('#productGrid > *')).map(e => e.classList.contains('grid-sub') ? (e.classList.contains('gone') ? 'G' : 'S') : e.classList.contains('made') ? 'm' : e.classList.contains('sold') ? 'x' : 'u').join(''));
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
  await pd.click('#orderBtn');
  await pd.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  const stepsM = await pd.textContent('.pay-steps').catch(() => '');
  ok(/Narudžba je zaprimljena — mjesto za „Klasik crna” je vaše\./.test(await pd.textContent('.pay-thanks').catch(() => '')), 'v66: ploča samo za kolekciju — „mjesto za „Klasik crna” je vaše”');
  ok(!/rezervacij/i.test(stepsM) && /Ugovor možete raskinuti u roku 14 dana od preuzimanja \(osim izrade po vašim posebnim željama\); trošak povrata snosite vi \(Uvjeti kupnje, t\.\u00a06\)/.test(stepsM) && /Vašu torbu šijemo u roku izrade \(5 radnih dana\), zatim narudžbu pripremamo za preuzimanje u radionici; javit ćemo vam se/.test(stepsM) && /Čim uplata bude vidljiva, krećemo s izradom\./.test(stepsM) && !/snimku zaslona/.test(stepsM), 'narudžba bez unikata + preuzimanje: rok iz podatka, preuzimanje, 14 dana s ogradom; v65: bez traženja snimke uplate');
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
  ok(await pd.isVisible('#lbDimsRow') && /na upit/.test(await pd.textContent('#lbDims')) && !/\{/.test(await pd.textContent('#lightbox')), 'v46: {DIMENZIJE} se ne prikazuje, nego „na upit”');
  ok(await pd.locator('.lb-full').count() === 1, 'poveznica na punu veličinu');
  await pd.screenshot({ path: OUT + 'd-03-lightbox.png' });
  const dimsTxt = await pd.$$eval('.card .ship-from', els => els.map(e => e.textContent).filter(t => /^Mjere/.test(t)));
  ok(dimsTxt.some(t => /^Mjere: na upit · dostava od/.test(t)) && /Mjere: na upit/.test(await pd.textContent('.card:has(h3:has(.card-name:text-is("Luna")))')), 'v50: torba bez mjera — „Mjere: na upit”');
  ok(/Slanje obično 1\u2060?–\u2060?2 radna dana nakon uplate/.test(await pd.textContent('#kako')), 'v50: rok slanja u „Kako naručiti”');
  ok(/javljamo ih na upit/.test(await pd.textContent('#uvjeti')), 'v50: Uvjeti t. 2 — mjere na upit');
  await pd.evaluate(() => { location.hash = '#kolekcija'; }); await pd.waitForTimeout(300);
  await pd.evaluate(() => { location.hash = '#ostalo'; }); await pd.waitForTimeout(300);
  ok(/Fotografija modela uskoro/.test(await pd.textContent('.card:has(h3:has(.card-name:text-is("Zamjenski remen")))')), 'v50/v63: artikl iz Ostalo bez fotografije — pitajte za izgled');
  await pd.evaluate(() => { location.hash = '#kolekcija'; }); await pd.waitForTimeout(300);
  await pd.evaluate(() => { location.hash = '#torbe'; }); await pd.waitForTimeout(300);
  ok(dimsTxt.length > 0 && dimsTxt.filter(t => !/na upit/.test(t)).every(t => /^Mjere: \d/.test(t)) && dimsTxt.some(t => /^Mjere: \d/.test(t)), 'v46/v51: upisane mjere na kartici, s dostavom u istom retku (' + dimsTxt.length + ')');
  await pd.keyboard.press('Escape');
  // legal collapsed
  const openCount = await pd.locator('.legal details[open]').count();
  const total = await pd.locator('.legal details').count();
  ok(total === 12 && openCount === 0, `uvjeti i privatnost sklopljeni (${total} odjeljaka)`);
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
  await pm.waitForTimeout(400);
  const mb = await pm.locator('#heroBag img').boundingBox();
  ok(mb && mb.y + mb.height < 844, 'mobitel: torba cijela na prvom ekranu ' + JSON.stringify(mb && { y: Math.round(mb.y), h: Math.round(mb.height) }));
  const h1b = await pm.locator('#hero h1').boundingBox();
  ok(h1b && mb && h1b.y + h1b.height <= mb.y, 'mobitel: naslov iznad torbe (' + Math.round(h1b.y) + ' < ' + Math.round(mb.y) + ')');
  const overflow = await pm.evaluate(() => document.documentElement.scrollWidth > innerWidth);
  ok(!overflow, 'mobitel: bez vodoravnog pomicanja');
  const H = await pm.evaluate(() => document.documentElement.scrollHeight);
  ok(H < 12000, 'mobitel: visina stranice ' + H + ' px (v21: 9 173)');
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
  ok(/Slanje obično 1\u2060?–\u2060?2 radna dana/.test(await pm.textContent('.order-lead')) && await pm.isVisible('.order-lead'), 'v50: rok slanja uz gumb');
  ok(sum.startsWith('Ukupno ' + tot) && await pm.isVisible('#orderSum'), 'v46: ukupno uz gumb: ' + sum);
  ok((await pm.locator('#fName').evaluate(el => getComputedStyle(el).fontSize)) === '16px', 'v46: polja 16 px (iPhone ne uvećava)');
  ok(await pm.isVisible('.cart-ask a[href*="instagram"]'), 'v46: kontakt prije narudžbe u košarici');
  ok(await pm.evaluate(() => document.body.classList.contains('no-confirm')) && !(await pm.isVisible('.next-step [data-if-confirm]')) && /Odmah nakon narudžbe vidite IBAN/.test(await pm.evaluate(() => document.querySelector('#kako [data-if-noconfirm]').textContent)), 'v56: bez potvrde e-mailom (postavka) stranica ne obećava link');
  { const v = await pm.evaluate(() => { const q = s => [...document.querySelectorAll(s)]; const shown = el => getComputedStyle(el).display !== 'none';
      return { conf: q('#uvjeti [data-if-confirm], #privatnost [data-if-confirm], footer [data-if-confirm]').map(shown), nc: q('#uvjeti [data-if-noconfirm], #privatnost [data-if-noconfirm], footer [data-if-noconfirm]').map(shown) }; });
    ok(v.conf.length >= 6 && v.conf.every(x => !x) && v.nc.length >= 4 && v.nc.every(x => x), 'v57: bez potvrde e-mailom Uvjeti, Privatnost i podnožje ne spominju link (' + v.conf.length + '/' + v.nc.length + ')'); }
  await pm.click('#closeCart'); await pm.waitForTimeout(300);
  const lunaBtn = pm.locator('.card:has(h3:has(.card-name:text-is("Luna"))) .add-btn');
  ok(/U košarici ✓ · otvori/.test(await lunaBtn.textContent()), 'v46: gumb „U košarici ✓ · otvori”');
  await lunaBtn.click(); await pm.waitForTimeout(300);
  ok(await pm.evaluate(() => document.body.classList.contains('cart-open')) && /Luna/.test(await pm.textContent('#cartItems')), 'v46: drugi dodir otvara košaricu, torba ostaje u njoj');
  ok(/Torbu „Luna” rezerviramo za vas 24 sata od narudžbe; u tom roku uplatite\./.test(await pm.textContent('#orderHold')) && await pm.locator('#orderHold .hold-exc').count() === 0 && await pm.locator('#orderNoteHold').isHidden(), 'redak o rezervaciji bez slaganja roda; napomena ga ne ponavlja');
  await pm.check('#shipOptions input[value="pickup"]');
  await pm.fill('#fName', 'Test Kupac'); await pm.fill('#fEmail', 'kupac@primjer.hr'); await pm.fill('#fPhone', '091 234 5678');
  await pm.check('#fConsent');
  await pm.screenshot({ path: OUT + 'm-03-kosarica.png' });
  await pm.click('#orderBtn');
  await pm.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  const steps = await pm.textContent('.pay-steps').catch(() => '');
  ok(/1–2 radna dana/.test(steps) && /Do (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje),\s\d{1,2}\.\s\d{1,2}\.\su\s\d{2}:\d{2}/.test(steps), 'vremenska crta s rokom slanja (v68: „Do” + točan rok za uplatu)');
  ok(/Do (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje),\s\d{1,2}\.\s\d{1,2}\.\su\s\d{2}:\d{2}/.test(steps) && /pripremamo za preuzimanje u radionici/.test(steps) && /javit ćemo vam se kad bude spremna i dogovoriti termin/.test(steps), 'novi tekst koraka (preuzimanje)');
  ok(/Torba „Luna” je rezervirana za vas/.test(await pm.textContent('.pay-thanks')) && !/✂/.test(await pm.textContent('.pay-thanks')), 'zahvala „Torba „Luna” je rezervirana”, bez emojija');
  ok(await pm.evaluate(() => document.activeElement && document.activeElement.classList.contains('pay-thanks')), 'fokus na zahvali nakon narudžbe');
  ok((await pm.textContent('.pay-hello')).startsWith('Hvala vam!') && /Narudžba SJ-\d{4}-\d{4}/.test(await pm.textContent('.pay-hello')) && await pm.locator('.pay-thanks b').count() === 0 && !/Rok za uplatu/.test(await pm.textContent('.pay-thanks')) && /Ako se predomislite/.test(await pm.textContent('.pay-steps')) && /navečer, vikendom ili iz druge banke/.test(await pm.textContent('.pay-steps')), 'v42/v69: „Hvala vam!” kao naslov; rok samo jednom (u koraku), uplata navečer ili vikendom, korak „Ako se predomislite”');
  const cbh = await pm.locator('.pay-data .copy-btn').first().boundingBox();
  ok(cbh && cbh.height >= 44, 'v42: gumb „Kopiraj” u podacima ' + Math.round(cbh && cbh.height) + ' px');
  const ibanRow = await pm.evaluate(() => { const v = document.querySelector('.pay-data .iban'), b = v && v.parentElement.querySelector('.copy-btn'); if(!b) return null; const a = v.getBoundingClientRect(), c = b.getBoundingClientRect(); return Math.abs((a.top + a.bottom) / 2 - (c.top + c.bottom) / 2) < 14; });
  ok(ibanRow === true, 'v44: „Kopiraj” u istom redu s IBAN-om (390 px)');
  ok(await pm.evaluate(() => { const b = document.getElementById('payBarcode'), d = document.querySelector('.pay-data'); return !b || !!(d.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING); }), 'v42: na mobitelu barkod ispod podataka');
  ok(/bez „SJ-”/.test(await pm.textContent('.pay-data')) && /neželjenu poštu/.test(await pm.textContent('.pay-note')) && /istom mobitelu/.test(await pm.textContent('.pay-barcode figcaption')), 'poziv na broj bez SJ-, neželjena pošta, barkod na istom mobitelu');
  ok(/Uplaćujete izravno obrtu/.test(await pm.textContent('.pay-who')) && await pm.locator('.copy-btn[data-label="Primatelj"]').count() === 1, 'redak o uplati i Kopiraj uz primatelja');
  const stepsU = await pm.textContent('.pay-steps');
  ok(/Do (ponedjeljka|utorka|srijede|četvrtka|petka|subote|nedjelje),\s\d{1,2}\.\s\d{1,2}\.\su\s\d{2}:\d{2}/.test(stepsU) && !/Sada/.test(stepsU) && /instant uplatu/.test(stepsU) && /Ugovor možete raskinuti u roku 14 dana od preuzimanja; trošak povrata snosite vi \(Uvjeti/.test(stepsU) && !/osim ako/.test(stepsU) && /s potvrdom torbu čuvamo za vas i nakon isteka roka/.test(stepsU), 'unikat + preuzimanje: bez dvostrukog koraka, čuvanje nakon isteka, 14 dana bez ograde');
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
  ok(/što prije/.test(await pm.textContent('#uvjeti')) && /gotovo u istom trenutku/.test(await pm.textContent('#uvjeti')) && /unikatna se torba/.test(await pm.textContent('#uvjeti')), 'Uvjeti: pravopis');
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
  await pk.click('#orderBtn');
  await pk.waitForSelector('#retrySend', { timeout: 8000 }).catch(() => {});
  const nAfter1 = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8'));
  ok(await pk.isVisible('#retrySend') && await pk.isVisible('#retryMail') && !mailtoOpened, 'prekid veze: „Pošalji ponovno” i „Pošalji e-mailom”, e-mail se ne otvara sam');
  ok(nAfter1.length === nBefore + 1, 'poslužitelj je zaprimio prvi pokušaj');
  const keyAfterFail = await pk.evaluate(() => JSON.parse(localStorage.getItem('shivaj.okey') || 'null'));
  await pk.unroute('**/api/order');
  await pk.reload({ waitUntil: 'networkidle' }); await pk.waitForTimeout(400);
  const keyAfterReload = await pk.evaluate(() => JSON.parse(localStorage.getItem('shivaj.okey') || 'null'));
  ok(keyAfterFail && keyAfterReload && keyAfterFail.key === keyAfterReload.key, 'ključ narudžbe preživi osvježavanje stranice');
  await fillForm(pk);
  await pk.click('#orderBtn');
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
  await p3.click('#orderBtn');
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
  await p5.click('#orderBtn'); await p5.waitForSelector('#retrySend', { timeout: 8000 }).catch(() => {});
  await p5.unroute('**/api/order');
  await p5.fill('#fEmail', 'ispravan@primjer.hr');
  await p5.click('#orderBtn'); await p5.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  ok(/Torba „Vela” je rezervirana/.test(await p5.textContent('.pay-thanks').catch(() => '')), 'krivi e-mail, prekid, ispravak: narudžba prolazi, torba nije blokirana');
  await m5.close();
  const m4 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p4 = await m4.newPage();
  await p4.route('**/js/hub3-barcode.js', r => r.abort());
  await p4.route('**/api/reserve', r => r.fulfill({ status: 429, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'previše' }) }));
  await p4.goto(URL, { waitUntil: 'networkidle' }); await p4.waitForTimeout(300);
  await p4.click('.card:has(h3:has(.card-name:text-is("Nera"))) .add-btn'); await p4.click('#openCart'); await p4.waitForTimeout(300);
  await p4.check('#shipOptions input[value="pickup"]');
  await p4.fill('#fName', 'Bez Skripte'); await p4.fill('#fEmail', 'bez@primjer.hr'); await p4.fill('#fPhone', '091 777 1111'); await p4.check('#fConsent');
  await p4.click('#orderBtn'); await p4.waitForSelector('#payBarcode', { timeout: 8000 }).catch(() => {}); await p4.waitForTimeout(800);
  const fb = await p4.evaluate(() => { const i = document.querySelector('#payBarcode img'); return { hub3: i.dataset.hub3 || '', src: i.getAttribute('src'), vis: !document.getElementById('payBarcode').hidden }; });
  ok(!fb.hub3 && /barkod-uplata\.png$/.test(fb.src) && fb.vis, 'bez skripte ostaje opći barkod iz Postavki');
  ok(/Torba „Nera” je rezervirana/.test(await p4.textContent('.pay-thanks').catch(() => '')), 'rezervacija vrati 429, narudžba ipak prolazi');
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
  await p7.click('#orderBtn'); await p7.waitForSelector('.order-error.order-info', { timeout: 8000 }).catch(() => {});
  const pend7 = await p7.textContent('#orderError').catch(() => '');
  ok(/potvrdite narudžbu/.test(pend7) && /potvrda@primjer\.hr/.test(pend7) && await p7.locator('.pay-steps').count() === 0 && await p7.isVisible('#retrySend'), 'nakon slanja: „potvrdite narudžbu”, bez podataka za uplatu, gumb za ponovno slanje');
  ok(/vrijedi 1 sat \(do /.test(pend7) && /s naslovom „Shiva\.J — potvrdite narudžbu”/.test(pend7), 'v45/v65: okvir potvrde — pravi naslov e-maila i koliko link vrijedi');
  ok(/možete zatvoriti/.test(pend7) && /zatražite ponovno:/.test(pend7) && (await p7.getAttribute('#orderError', 'role')) === 'status' && /čeka vašu potvrdu/.test(await p7.textContent('.next-step')), 'v41: okvir kaže da se stranica može zatvoriti, role=status, „Sljedeći korak” u čekanju');
  await p7.screenshot({ path: OUT + 'm-40-potvrda.png' });
  ok(!(await p7.isVisible('#orderBtn')) && !(await p7.isVisible('#fName')) && await p7.isVisible('#editOrder'), 'v46: dok narudžba čeka potvrdu, obrazac i gumb su skriveni');
  await p7.click('#editOrder'); await p7.waitForTimeout(200);
  ok(await p7.isVisible('#orderBtn') && await p7.isVisible('#fName') && !(await p7.isVisible('#orderError')), 'v46: „Promijeni podatke narudžbe” vraća obrazac');
  await p7.click('#orderBtn'); await p7.waitForSelector('#editOrder', { timeout: 8000 }).catch(() => {});
  // v53 G: treći e-mail na istu adresu (429) dok narudžba čeka — okvir „čeka potvrdu” ostaje
  await p7.click('#retrySend'); await p7.waitForTimeout(1500);
  ok(await p7.isVisible('.pend-note') && /već smo poslali više puta/.test(await p7.textContent('#orderError')) && !/nije prihvaćena/.test(await p7.textContent('#orderError')) && await p7.isVisible('#editOrder'), 'v53 G: ponovno slanje u čekanju (429) — okvir ostaje, zadnji link vrijedi');
  // v53 H: nova kartica / osvježavanje s istom košaricom — napomena da narudžba čeka potvrdu
  const p7b = await m7.newPage(); watch(p7b);
  await p7b.goto(URL, { waitUntil: 'networkidle' }); await p7b.waitForTimeout(400); await p7b.click('#openCart'); await p7b.waitForTimeout(300);
  ok(/Ovu narudžbu ste već poslali/.test(await p7b.textContent('#orderError').catch(() => '')) && /poništava prethodni link/.test(await p7b.textContent('#orderError').catch(() => '')), 'v53/v54 H: nakon ponovnog učitavanja — „Ovu narudžbu ste već poslali”, novi e-mail poništava link');
  await p7b.close();
  await p7.fill('.cart-note', 'crna, kraći remen'); await p7.waitForTimeout(200);
  ok(await p7.isVisible('#orderBtn') && !(await p7.isVisible('#editOrder')), 'v51: promjena napomene dok narudžba čeka potvrdu vraća obrazac');
  const links = [...fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8').matchAll(/\?potvrda=([a-f0-9]{32}\.[a-f0-9]{32})/g)];
  const link = links.length ? links[links.length - 1][1] : '';
  const m8 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p8 = await m8.newPage(); watch(p8);
  await p8.goto(URL + '?potvrda=' + link, { waitUntil: 'networkidle' }); await p8.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
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
  ok(/Dostava: .*besplatno/.test(await p10.textContent('#shipList')), 'v48: cijene dostave u „Kako naručiti”: ' + (await p10.textContent('#shipList')));
  await p10.evaluate(() => { location.hash = '#kolekcija'; }); await p10.waitForTimeout(300);
  const addB = p10.locator('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn');
  await addB.focus(); await p10.keyboard.press('Enter'); await p10.waitForTimeout(200);
  ok(await p10.evaluate(() => document.activeElement && document.activeElement.matches('.add-btn') && /Klasik/.test(document.activeElement.closest('.card').textContent)), 'v48: fokus ostaje na gumbu nakon dodavanja');
  await p10.evaluate(() => { location.hash = '#torbe'; }); await p10.waitForTimeout(300);
  await p10.click('.card-name:text-is("Luna")'); await p10.waitForTimeout(300);
  ok(await p10.evaluate(() => document.getElementById('lightbox').classList.contains('open')), 'v48: klik na ime torbe otvara prikaz');
  await p10.keyboard.press('Escape');
  await p10.click('#openCart'); await p10.waitForTimeout(300);
  ok(/1 kom · najviše jedan po narudžbi/.test(await p10.textContent('#cartItems')) && !(await p10.isVisible('#cartItems .q')) && await p10.isVisible('.cart-note-l') && /Želju ne morate čekati da potvrdimo: uplatite odmah nakon narudžbe\. Ako je ne možemo ispuniti, javit ćemo vam se prije izrade, a vi birate: izradu bez te želje ili povrat cijelog iznosa\./.test(await p10.textContent('#cartItems')) && /Zatvori/.test(await p10.textContent('#closeCart')), 'v48/v64: model kolekcije u košarici — „1 kom”, bez +/−; oznaka napomene i „ako želju ne možemo ispuniti”; „Zatvori ×”');
  await p10.fill('#fEmail', 'ana.probic@primjer');
  await p10.click('#orderBtn'); await p10.waitForTimeout(300);
  const fix = await p10.textContent('#orderFix');
  ok(/ime i prezime/.test(fix) && /e-mail/.test(fix) && /telefon/.test(fix) && /Uvjeta/.test(fix), 'v48: popis polja za ispravak: ' + fix);
  ok(!(await p10.locator('#fEmail').evaluate(el => el.checkValidity())), 'v48: e-mail bez točke u domeni ne prolazi');
  await m10.close();
  // v51: povećani prikaz artikla po narudžbi bez „14 dana”; prodana bez drugog gumba; napomena u čekanju
  const m11 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p11 = await m11.newPage(); watch(p11);
  await p11.goto(URL + '#torba-remen', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  const sh11 = await p11.textContent('#lbShip');
  ok(/dostava od/.test(sh11) && !/14 dana/.test(sh11) && /Fotografija modela uskoro/.test(await p11.textContent('#lbAsk')), 'v51: artikl po narudžbi — bez „14 dana”, napomena o fotografiji modela: ' + sh11);
  await p11.goto(URL + '#torba-luna', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok(/14 dana za raskid/.test(await p11.textContent('#lbShip')) || await p11.isVisible('#lbGone'), 'v51: unikat — „14 dana za raskid”');
  await p11.goto(URL + '#torba-zora', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok(await p11.isVisible('#lbGone') && !(await p11.isVisible('#lbAdd')) && !/na upit/.test(await p11.textContent('#lightbox')), 'v51: prodana torba — jedan gumb, bez „na upit”');
  ok(/Rezervirane i prodane/.test(await p11.textContent('#productGrid')), 'v51: podnaslov „Rezervirane i prodane”');
  await p11.goto(URL + '#torba-tara', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok((await p11.textContent('#lbMaterial')).trim() === 'umjetna koža' && await p11.isVisible('#lbMaterialRow'), 'v52/v64: torba bez upisanog materijala — „umjetna koža” (odluka vlasnika), ne „na upit”');
  await p11.goto(URL + '#torba-remen', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok(await p11.isVisible('#lbLeadRow') && /rok na upit|\d/.test(await p11.textContent('#lbLead')), 'v52: artikl po narudžbi — rok izrade ili „rok na upit”: ' + (await p11.textContent('#lbLead')));
  await p11.keyboard.press('Escape');
  await p11.goto(URL + '#ostalo', { waitUntil: 'networkidle' }); await p11.waitForTimeout(500);
  ok((await p11.$$eval('.card.made:not(.sold) .lead', els => els.length)) === (await p11.$$eval('.card.made:not(.sold)', els => els.length)), 'v52: svaki artikl po narudžbi ima redak „Izrada”');
  await p11.click('.card.made:not(.sold) .add-btn'); await p11.click('#openCart'); await p11.waitForTimeout(300);
  ok(/Prihvaćaju se Uvjeti kupnje/.test(await p11.textContent('.trust-line')) && !/14 dana/.test(await p11.textContent('.trust-line')) && await p11.$('.trust-line a[href="#uvjeti"]') && /14 dana/.test(await p11.textContent('label[for=fConsent]')), 'v68: traka u košarici „Prihvaćaju se Uvjeti kupnje” (rok za raskid u Uvjetima i uz kvačicu)');
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
    ok(!(await p13.isVisible('#catTabs a[href="#ostalo"]')) && (await p13.textContent('#shopTitle')).trim() === 'Torbe', 'v62: Ostalo isključen — nema kartice, #ostalo vodi u Torbe');
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
    await p13.click('#orderBtn'); await p13.waitForSelector('.order-error.order-info', { timeout: 8000 }).catch(() => {});
    const l13 = [...fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8').matchAll(/\?potvrda=([a-f0-9]{32}\.[a-f0-9]{32})/g)].pop();
    const m14 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p14 = await m14.newPage(); watch(p14);
    await p14.route('**/api/status*', r => r.fulfill({ status: 500, body: '{}' }));
    await p14.route('**/api/confirm*', async r => { await new Promise(z => setTimeout(z, 800)); await r.continue(); });
    await p14.goto(URL + '?potvrda=' + (l13 ? l13[1] : 'x'), { waitUntil: 'domcontentloaded' }); await p14.waitForTimeout(300);
    const wait14 = await p14.textContent('#emptyCart').catch(() => '');
    await p14.waitForSelector('.pay-steps', { timeout: 10000 }).catch(() => {});
    const pay14 = await p14.textContent('.drawer-body').catch(() => '');
    ok(/Potvrđujemo narudžbu/.test(wait14) && !/Košarica je prazna/.test(wait14), 'v62: dok se potvrđuje — „Potvrđujemo narudžbu…”');
    ok(/vlasnica@drugi-e2e\.hr/.test(pay14) && /HR12 1001 0051 8630 0016 0/.test(pay14) && !/shivaj\.handmade@gmail\.com/.test(pay14), 'v62: api/status pada — ploča ipak s podacima iz Postavki (iz odgovora potvrde)');
    await m14.close(); await m13.close();
    fs.writeFileSync(cfgP, c62); fs.writeFileSync(pp, p62);
  }
  // v63: uvod u Torbe, skriveno Ostalo i stara košarica, popunjen model u košarici, izbornik na 900 px
  {
    const cfgP = SITE + 'data/config.json', c63 = fs.readFileSync(cfgP, 'utf8'), pp = SITE + 'data/proizvodi.json', p63 = fs.readFileSync(pp, 'utf8');
    const m15 = await browser.newContext({ viewport: { width: 1280, height: 800 } }); const p15 = await m15.newPage(); watch(p15);
    await p15.goto(URL + '#torbe', { waitUntil: 'networkidle' }); await p15.waitForTimeout(300);
    ok(/Unikati: svaka torba postoji samo jednom/.test(await p15.textContent('#shopIntro')) && /Stalnoj kolekciji/.test(await p15.textContent('#shopIntro')), 'v63: uvod u rubriku Torbe (unikati, upućuje na Stalnu kolekciju)');
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
    ok((await p16.textContent('#cartCount')).trim() === '1' && /Trenutno nije u ponudi/.test(c16) && /Zamjenski remen/.test(c16), 'v63: stara košarica — artikl iz isključenog Ostalo izlazi uz obavijest, model kolekcije količina 1: ' + (await p16.textContent('#cartCount')).trim());
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
    await p19.click('#orderBtn'); await p19.waitForTimeout(300);
    let sent19 = 0; p19.on('request', r => { if (/\/api\/order/.test(r.url())) sent19++; });
    await p19.check('#shipOptions input[value="pickup"]'); await p19.waitForTimeout(200);
    await p19.click('#orderBtn');
    await p19.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
    ok(sent19 > 0, 'v64: BOX NOW bez paketomata → preuzimanje → „Naruči” šalje narudžbu (obrazac se ne zaglavi)');
    const st19 = await p19.textContent('.pay-steps').catch(() => '');
    ok(/Torbu „Klasik crna” šijemo u roku izrade \(5 radnih dana\)/.test(st19) && /cijelu narudžbu pripremamo za preuzimanje/.test(st19), 'v64: mješovita narudžba — model kolekcije poimence: ' + st19.slice(0, 200));
    await m19.close();
    const m20 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p20 = await m20.newPage(); watch(p20);
    await p20.goto(URL + '#kolekcija', { waitUntil: 'networkidle' }); await p20.waitForTimeout(300);
    await p20.click('.card:has(h3:has(.card-name:text-is("Klasik crna"))) .add-btn'); await p20.click('#openCart'); await p20.waitForTimeout(300);
    await p20.check('#shipOptions input[value="pickup"]');
    await p20.fill('#fName', 'Dostava Promjena'); await p20.fill('#fEmail', 'dostava.promjena@primjer.hr'); await p20.fill('#fPhone', '091 191 1919'); await p20.check('#fConsent');
    await p20.click('#orderBtn'); await p20.waitForSelector('.order-error', { timeout: 8000 }).catch(() => {});
    const e20 = await p20.textContent('.order-error').catch(() => '');
    const foc20 = await p20.evaluate(() => document.activeElement && document.activeElement.id);
    ok(await p20.locator('#orderBtn.is-blocked').count() === 1, 'v66: gumb za narudžbu izgleda neaktivno dok prethodna narudžba čeka uplatu');
    ok(/Prethodna narudžba iz Stalne kolekcije čeka uplatu\. Podatke za uplatu imate u e-mailu s potvrdom narudžbe/.test(e20) && await p20.locator('.order-error.order-info').count() === 1 && !/Provjerite e-mail adresu/.test(e20) && foc20 !== 'fEmail', 'v64: druga narudžba iz kolekcije s istog e-maila — „Već imate otvorenu narudžbu”, ne „Provjerite e-mail adresu”: ' + e20.slice(0, 120));
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
    ok(new RegExp('Sve šaljemo zajedno, u jednom paketu: „' + u21name + '” čeka da sašijemo „Klasik crna” \\(izrada 5 radnih dana od uplate\\), zatim slanje obično 1\u2060–\u20602 radna dana\\. Želite li torbu „' + u21name + '” prije, naručite je zasebno \\(tada plaćate dvije dostave\\)\\.').test(lead21), 'v65: mješovita košarica — prije narudžbe piše da sve ide zajedno i koliko traje: ' + lead21);
    ok((await p21.textContent('#fNoteLabel')).trim() === 'Napomena uz dostavu (neobavezno)' && await p21.isVisible('#fNoteHint') && /Boju i duljinu upišite gore, uz model\./.test(await p21.textContent('#fNoteHint')) && await p21.locator('.cart-wish').count() === 1 && await p21.locator('#orderNoteMade').isHidden(), 'v65: opća napomena upućuje na polje uz model');
    await p21.fill('#fName', 'Mješovita Gls'); await p21.fill('#fEmail', 'mjesovita.gls@primjer.hr'); await p21.fill('#fPhone', '091 212 1212');
    await p21.fill('#fAddr', 'Ilica 1'); await p21.fill('#fCity', '10000 Zagreb'); await p21.check('#fConsent');
    await p21.click('#orderBtn'); await p21.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
    const ml21 = fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8').split(/^=== /m).filter(x => /potvrda narudžbe SJ-/.test(x.split('\n')[0]) && /Mješovita Gls/.test(x)).pop() || '';
    ok(new RegExp('cijelu narudžbu — „(' + u21name + '|Klasik crna)” i „(' + u21name + '|Klasik crna)” — šaljemo zajedno').test(ml21) && /Čim uplata bude vidljiva, šijemo „Klasik crna” \(rok izrade 5 radnih dana od primitka uplate\), zatim cijelu narudžbu — /.test(ml21) && /u jednom paketu; slanje je obično 1–2 radna dana nakon izrade\./.test(ml21) && !/Rok izrade/.test(ml21), 'v65: e-mail mješovite narudžbe — nazivi s „i”, rok uz model, slanje nakon izrade');
    await m21.close();
    const m22 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p22 = await m22.newPage(); watch(p22);
    await p22.goto(URL + '#torba-tara', { waitUntil: 'networkidle' }); await p22.waitForTimeout(500);
    await p22.keyboard.press('Escape'); await p22.waitForTimeout(500);
    const vis22 = await p22.evaluate(() => { const c = [...document.querySelectorAll('#productGrid .card')].find(x => /Tara/.test(x.querySelector('.card-name')?.textContent || '')); if (!c) return false; const r = c.getBoundingClientRect(); return r.bottom > 0 && r.top < innerHeight && scrollY > 200; });
    ok(vis22, 'v65: zatvaranje povećanog prikaza otvorenog linkom vraća na karticu torbe');
    ok(!/#torba-/.test(await p22.evaluate(() => location.hash)), 'v66: zatvaranje povećanog prikaza briše #torba- iz adrese');
    await m22.close();
    const m23 = await browser.newContext({ viewport: { width: 390, height: 844 } }); const p23 = await m23.newPage(); watch(p23);
    await p23.goto(URL + '#ostalo', { waitUntil: 'networkidle' }); await p23.waitForTimeout(300);
    const rem = p23.locator('#productGrid .card.made .add-btn:not([disabled])');
    if (await rem.count() > 0) {
      await rem.first().click(); await p23.click('#openCart'); await p23.waitForTimeout(300);
      const c23 = await p23.textContent('.drawer-body');
      ok(!/Stalne kolekcije|torbu bez te želje/i.test(c23) && /izradu bez te želje/.test(c23), 'v66: Ostalo u košarici — bez teksta za torbe iz kolekcije, „izradu bez te želje”');
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
    ok(/mogu biti najviše 2 unikatne torbe; u košarici već su „Nera” i „Luna”/.test(toastLim) && !(await p68.evaluate(() => cart.some(c => c.id === 'tara'))), 'v68/v69: treća unikatna torba — poruka odmah kaže koja je granica i koje unikate kupac već ima; torba nije dodana');
    /* v69: rok za uplatu iznad kvačice i kad u košarici nema unikata; „+” onemogućen na granici količine */
    await p68.evaluate(() => { cart = []; saveCart(); renderGrid(); renderCart(); toggleCartItem('remen'); });
    await p68.evaluate(() => { const r = cart.find(c => c.id === 'remen'); r.qty = 10; saveCart(); renderCart(); openCart(); }); await p68.waitForTimeout(300);
    ok(await p68.isVisible('#orderHold') && /Rok za uplatu je 24 sata od narudžbe; ono što šijemo po narudžbi izrađujemo nakon uplate\./.test(await p68.textContent('#orderHold')) && !(await p68.isVisible('#orderNoteMade'))
      && await p68.isDisabled('.cart-item .q[data-d="1"]') && /najviše 10 artikala/.test(await p68.getAttribute('.cart-item .q[data-d="1"]', 'title') || ''), 'v69: bez unikata rok za uplatu iznad kvačice; „+” onemogućen na granici od 10 artikala');
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
    ok(/jeste li uplatili/.test(ask) && urlClean && !before.claimedAt, 'v69: link „Uplatio/la sam” otvara pitanje u košarici; bez klika se ništa ne mijenja, adresa se čisti');
    await pc.click('#claimBtn'); await pc.waitForTimeout(600);
    const after = JSON.parse(fs.readFileSync(SITE + 'data/narudzbe.json', 'utf8')).find(o => o.no === oc.no);
    ok(/Rok za uplatu produžen je do/.test(await pc.textContent('#claimBox')) && !!after.claimedAt, 'v69: klik „Da, uplatio/la sam” produžuje rok i zahvaljuje kupcu');
    await mc.close();
  }
  ok(errors.length === 0, 'bez JS grešaka' + (errors.length ? ': ' + errors.join(' ; ') : ''));
  await browser.close();
  const cnt = JSON.parse(fs.readFileSync(SITE + 'data/brojac.json', 'utf8'));
  const day = Object.values(cnt)[0] || {};
  ok(day.visits === 30 && day.ig === 1 && day.orders === 10 && day.orders_m === 3 && day.press === 1 && day.rad >= 1 && day.cart === 17 && day.copy >= 1, 'brojač: ' + JSON.stringify(cnt));
  const ml = fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8');
  ok(/Dobar dan, Test Kupac/.test(ml) && !/Draga\/Dragi/.test(ml) && /čeka vašu uplatu/.test(ml) && /Opis plaćanja: Shiva\.J 2026-/.test(ml) && /javit ćemo vam se i dogovoriti termin/.test(ml) && /jednostrano raskinuti u roku 14 dana/.test(ml) && /ROK ZA UPLATU: do [^\n]*\(24 sata\)/.test(ml), 'e-mail kupcu: pozdrav, čeka uplatu, opis, preuzimanje, raskid, 24 sata');
  ok(/Narudžba bez unikata: kad kupac uplati/.test(ml), 'e-mail vlasnici: uputa za Plaćeno');
  const zj = JSON.parse(fs.readFileSync(SITE + 'data/zahtjevi.json', 'utf8'));
  ok(Object.keys(zj).every(k => !/visit\|127\.|count\|127\./.test(k)), 'ograničenje zahtjeva brojača bez prave adrese: ' + Object.keys(zj).join(','));
  ok(!/@|Kupac|127\.0/.test(JSON.stringify(cnt)), 'brojač bez osobnih podataka');
  console.log(log.join('\n'));
  const fails = log.filter(l => l.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + log.length + ')');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.log(log.join('\n')); console.error('SCRIPT ERROR', e); process.exit(1); });
