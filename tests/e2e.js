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
  const luna = pd.locator('.card:has(h3:text-is("Luna"))');
  ok(await luna.locator('img.alt').count() === 1, 'kartica ima drugu fotografiju');
  ok((await luna.locator('.count').textContent()).includes('1/2'), 'oznaka broja fotografija');
  ok(await luna.locator('.one').count() === 0, 'bez oznake 1/1');
  ok((await pd.textContent('nav')).includes('Radionica') && await pd.locator('#radionica').count() === 1, 'poveznica „Radionica” i odjeljak #radionica');
  ok(/Iz radionice/.test(await pd.textContent('#radionica h2')), 'naslov „Iz radionice u Zaboku.”');
  ok(await pd.isVisible('.hero-cta'), 'gumb „Pogledaj unikate” na prvom ekranu');
  ok(await pd.locator('.press a[target=_blank]').count() === 0 && (await pd.getAttribute('.press a', 'href')) === '#radionica' && (await pd.locator('.press-name').count()) === 3, 'traka bez vanjskih poveznica, vodi na Radionicu');
  const rad = await pd.textContent('#radionica');
  ok(/Tekstilno-tehnološkom/.test(rad) && /skaja/.test(rad) && /Matije Gupca 33/.test(rad) && /s torbom u ruci/.test(rad), 'Radionica: skaj, adresa, potvrđene rečenice');
  ok(/Šivalec\. Što radim, piše mi već u prezimenu/.test(rad) && /J od mene/.test(rad) && /rezom, a završava šavom/.test(rad) && /Ono što tako nastane/.test(rad) && /druge jednobojne/.test(rad) && /dana šivanja/.test(rad) && !/[{}]/.test(rad), 'Radionica v27: dorađene rečenice, bez {…}');
  ok(/Šarena se dogodi/.test(await pd.textContent('.about-quote')) && /Jasmina/.test(await pd.textContent('.about-quote small')) && !/po narudžbi: šarena se dogodi/.test(await pd.textContent('#radionica')), 'citat samo lijevo, s potpisom');
  ok(/klasične šijemo i po narudžbi/.test(await pd.getAttribute('meta[property="og:description"]', 'content')) && /ni raspored boja/.test(await pd.textContent('#hero')) && /osim artikala izrađenih po vašoj želji/.test(await pd.textContent('#uvjeti')), 'og opis, hero „raspored boja”, ograda u Ukratko');
  ok(/Ručno šivane torbe iz Zaboka/.test(await pd.title()) && /Ručno šivane torbe iz Zaboka/.test(await pd.textContent('footer')), 'slogan u naslovu i podnožju');
  ok(/šarenu torbu odmah rezerviramo/.test(await pd.textContent('#uvjeti')) && !/Time je unikatna torba/.test(await pd.textContent('#uvjeti')) && /Artikli po narudžbi ne rezerviraju se/.test(await pd.textContent('#uvjeti')), 'Uvjeti: Ukratko i t. 4 usklađeni');
  ok(/Klasične torbe/.test(await pd.textContent('.grid-sub span')) && /šijemo po narudžbi/.test(await pd.textContent('.grid-sub small')), 'podnaslov mreže kao naslov, „šijemo”');
  ok((await pd.getAttribute('#radionica a[href^="mailto:"]', 'href')) === 'mailto:shivaj.handmade@gmail.com', 'e-mail kao mailto');
  ok(!/Svaka torba postoji/.test(await pd.getAttribute('meta[name="description"]', 'content')), 'opis za tražilice bez „svaka torba”');
  ok((await pd.textContent('#shopTitle')).trim() === 'Torbe' && /unikata dostupn[oa] · klasične[\s\u00a0]po[\s\u00a0]narudžbi/.test(await pd.textContent('#availCount')), 'naslov „Torbe” i brojač unikata: ' + (await pd.textContent('#availCount')));
  const subIdx = await pd.evaluate(() => Array.from(document.querySelectorAll('#productGrid > *')).findIndex(e => e.classList.contains('grid-sub')));
  const firstMade = await pd.evaluate(() => Array.from(document.querySelectorAll('#productGrid > *')).findIndex(e => e.classList.contains('made')));
  const lastUnique = await pd.evaluate(() => { const a = Array.from(document.querySelectorAll('#productGrid > .card')).filter(e => !e.classList.contains('made')); return Array.from(document.querySelectorAll('#productGrid > *')).indexOf(a[a.length - 1]); });
  ok(subIdx > lastUnique && firstMade === subIdx + 1, `podnaslov „Klasične torbe” iza zadnjeg unikata (${lastUnique} < ${subIdx} < ${firstMade})`);
  ok(/skaja/.test(await pd.textContent('#hero')) && /po narudžbi/.test(await pd.textContent('#kako')), 'hero i korak 01 usklađeni (skaj, po narudžbi)');
  const kl = pd.locator('.card:has(h3:text-is("Klasik crna"))');
  ok((await kl.locator('.unikat-tag').textContent()) === 'Po narudžbi' && /5 radnih dana/.test(await kl.textContent()) && (await kl.locator('.add-btn').textContent()).trim() === 'Naruči izradu', 'klasična torba: „Po narudžbi”, rok, gumb „Naruči izradu”');
  // narudžba samo s klasičnom torbom (desktop): bez rezervacije, 14 dana s ogradom, redak uplate iz postavki
  await kl.locator('.add-btn').click();
  await pd.click('#openCart'); await pd.waitForTimeout(300);
  await pd.check('#shipOptions input[value="pickup"]');
  await pd.fill('#fName', 'Test Kupac'); await pd.fill('#fEmail', 'kupac@primjer.hr'); await pd.fill('#fPhone', '091 234 5678'); await pd.check('#fConsent');
  await pd.click('#orderBtn');
  await pd.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  const stepsM = await pd.textContent('.pay-steps').catch(() => '');
  ok(!/rezervacij/i.test(stepsM) && /torbu možete vratiti u roku 14 dana od primitka, osim artikala rađenih po vašoj boji ili mjeri \(Uvjeti kupnje, t\.\u00a06\)/.test(stepsM) && /u roku izrade \(5 radnih dana\), zatim ih pripremamo za preuzimanje u radionici; javit ćemo vam se/.test(stepsM) && /\(snimku zaslona\) na /.test(stepsM), 'narudžba bez unikata + preuzimanje: rok iz podatka, preuzimanje, 14 dana s ogradom');
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
  await pd.keyboard.press('Escape');
  const sticky = await pd.locator('.about-inner > div').first().evaluate(el => getComputedStyle(el).position);
  ok(sticky === 'sticky', 'lijevi stupac Radionice ljepljiv (' + sticky + ')');
  ok(await pd.locator('.press-list li a[target=_blank][rel=noopener]').count() === 3, 'popis članaka u Radionici, 3 vanjske poveznice');
  await pd.locator('.press-list a').first().evaluate(a => { a.setAttribute('href', '#radionica'); a.removeAttribute('target'); });
  await pd.locator('.press-list a').first().click(); await pd.waitForTimeout(400);
  await pd.waitForTimeout(300);
  ok(await pd.locator('.about-photo').isHidden(), 'okvir fotografije skriven bez datoteke');
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
  ok(!(await pd.isVisible('#lbDimsRow')), '{DIMENZIJE} se ne prikazuje');
  ok(await pd.locator('.lb-full').count() === 1, 'poveznica na punu veličinu');
  await pd.screenshot({ path: OUT + 'd-03-lightbox.png' });
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

  ok(/bit će rezervirana/.test(await pd.evaluate(() => document.documentElement.innerHTML)) && !/će biti rezervirana/.test(await pd.evaluate(() => document.documentElement.innerHTML)), 'ime torbe uz riječ „Torba”: „bit će rezervirana”');
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
  ok(cta && cta.y > mb.y + mb.height, 'mobitel: gumb ispod torbe, opis između ' + JSON.stringify(cta && { y: Math.round(cta.y) }));
  await pm.screenshot({ path: OUT + 'm-01-hero.png' });
  // order flow with timeline
  await pm.click('.card:has(h3:text-is("Luna")) .add-btn');
  await pm.waitForTimeout(150);
  ok(/U košarici: „Luna” ✓/.test(await pm.textContent('#toast').catch(() => '')), 'poruka „U košarici: „Luna” ✓”');
  await pm.click('#openCart'); await pm.waitForTimeout(300);
  ok(await pm.isVisible('.trust-line'), 'košarica: redak povjerenja');
  ok(await pm.isVisible('.next-step'), 'košarica: sljedeći korak ispod gumba');
  ok(/Torba „Luna” bit će rezervirana za vas 24 sata/.test(await pm.textContent('#orderHold')) && await pm.locator('#orderNoteHold').isHidden(), 'redak o rezervaciji bez slaganja roda; napomena ga ne ponavlja');
  await pm.check('#shipOptions input[value="pickup"]');
  await pm.fill('#fName', 'Test Kupac'); await pm.fill('#fEmail', 'kupac@primjer.hr'); await pm.fill('#fPhone', '091 234 5678');
  await pm.check('#fConsent');
  await pm.screenshot({ path: OUT + 'm-03-kosarica.png' });
  await pm.click('#orderBtn');
  await pm.waitForSelector('.pay-steps', { timeout: 8000 }).catch(() => {});
  const steps = await pm.textContent('.pay-steps').catch(() => '');
  ok(/1–2 radna dana/.test(steps) && /Do isteka rezervacije/.test(steps), 'vremenska crta s rokom slanja');
  ok(/Do isteka rezervacije/.test(steps) && /pripremamo za preuzimanje u radionici/.test(steps) && /javit ćemo vam se kad bude spremna i dogovoriti termin/.test(steps), 'novi tekst koraka (preuzimanje)');
  ok(/Torba „Luna” je rezervirana za vas/.test(await pm.textContent('.pay-thanks')) && !/✂/.test(await pm.textContent('.pay-thanks')), 'zahvala „Torba „Luna” je rezervirana”, bez emojija');
  ok(await pm.evaluate(() => document.activeElement && document.activeElement.classList.contains('pay-thanks')), 'fokus na zahvali nakon narudžbe');
  ok(/bez „SJ-”/.test(await pm.textContent('.pay-data')) && /neželjenu poštu/.test(await pm.textContent('.pay-note')) && /istom mobitelu/.test(await pm.textContent('.pay-barcode figcaption')), 'poziv na broj bez SJ-, neželjena pošta, barkod na istom mobitelu');
  ok(/Uplaćujete izravno obrtu/.test(await pm.textContent('.pay-who')) && await pm.locator('.copy-btn[data-label="Primatelj"]').count() === 1, 'redak o uplati i Kopiraj uz primatelja');
  const stepsU = await pm.textContent('.pay-steps');
  ok(/Do isteka rezervacije/.test(stepsU) && !/Sada/.test(stepsU) && /torbu možete vratiti u roku 14 dana od primitka \(Uvjeti/.test(stepsU) && !/osim ako/.test(stepsU) && /s potvrdom torbu čuvamo za vas i nakon isteka rezervacije/.test(stepsU), 'unikat + preuzimanje: bez dvostrukog koraka, čuvanje nakon isteka, 14 dana bez ograde');
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
  await pk.click('.card:has(h3:text-is("Kozmetička torbica")) .add-btn').catch(async () => { await pk.click('.card.made .add-btn'); });
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
  const shownNo = await pk.textContent('.pay-data').catch(() => '');
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
  const m3 = await browser.newContext({ viewport: { width: 360, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const p3 = await m3.newPage(); watch(p3);
  await p3.goto(URL, { waitUntil: 'networkidle' }); await p3.waitForTimeout(300);
  await p3.click('.card:has(h3:text-is("Tara")) .add-btn');
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
  const m5 = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const p5 = await m5.newPage(); watch(p5);
  await p5.goto(URL, { waitUntil: 'networkidle' }); await p5.waitForTimeout(300);
  await p5.click('.card:has(h3:text-is("Vela")) .add-btn'); await p5.click('#openCart'); await p5.waitForTimeout(300);
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
  await p4.goto(URL, { waitUntil: 'networkidle' }); await p4.waitForTimeout(300);
  await p4.click('.card:has(h3:text-is("Nera")) .add-btn'); await p4.click('#openCart'); await p4.waitForTimeout(300);
  await p4.check('#shipOptions input[value="pickup"]');
  await p4.fill('#fName', 'Bez Skripte'); await p4.fill('#fEmail', 'bez@primjer.hr'); await p4.fill('#fPhone', '091 777 1111'); await p4.check('#fConsent');
  await p4.click('#orderBtn'); await p4.waitForSelector('#payBarcode', { timeout: 8000 }).catch(() => {}); await p4.waitForTimeout(800);
  const fb = await p4.evaluate(() => { const i = document.querySelector('#payBarcode img'); return { hub3: i.dataset.hub3 || '', src: i.getAttribute('src'), vis: !document.getElementById('payBarcode').hidden }; });
  ok(!fb.hub3 && /barkod-uplata\.png$/.test(fb.src) && fb.vis, 'bez skripte ostaje opći barkod iz Postavki');
  await m4.close();

  ok(errors.length === 0, 'bez JS grešaka' + (errors.length ? ': ' + errors.join(' ; ') : ''));
  await browser.close();
  const cnt = JSON.parse(fs.readFileSync(SITE + 'data/brojac.json', 'utf8'));
  const day = Object.values(cnt)[0] || {};
  ok(day.visits === 10 && day.ig === 1 && day.orders === 6 && day.orders_m === 2 && day.press === 1 && day.rad >= 1 && day.cart === 6 && day.copy >= 1, 'brojač: ' + JSON.stringify(cnt));
  const ml = fs.readFileSync(SITE + 'data/mail-log.txt', 'utf8');
  ok(/Dobar dan, Test Kupac/.test(ml) && !/Draga\/Dragi/.test(ml) && /čeka vašu uplatu/.test(ml) && /Opis plaćanja: Shiva\.J 2026-/.test(ml) && /javit ćemo vam se i dogovoriti termin/.test(ml) && /jednostrano raskinuti u roku 14 dana/.test(ml) && /Rezervacija vrijedi 24 sata/.test(ml), 'e-mail kupcu: pozdrav, čeka uplatu, opis, preuzimanje, raskid, 24 sata');
  ok(/Narudžba bez unikata: kad kupac uplati/.test(ml), 'e-mail vlasnici: uputa za Plaćeno');
  const zj = JSON.parse(fs.readFileSync(SITE + 'data/zahtjevi.json', 'utf8'));
  ok(Object.keys(zj).every(k => !/visit\|127\.|count\|127\./.test(k)), 'ograničenje zahtjeva brojača bez prave adrese: ' + Object.keys(zj).join(','));
  ok(!/@|Kupac|127\.0/.test(JSON.stringify(cnt)), 'brojač bez osobnih podataka');
  console.log(log.join('\n'));
  const fails = log.filter(l => l.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + log.length + ')');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.log(log.join('\n')); console.error('SCRIPT ERROR', e); process.exit(1); });
