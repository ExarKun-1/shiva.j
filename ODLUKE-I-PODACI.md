# SHIVA.J web stranica — podaci i odluke

Sažetak svega što je dogovoreno i ugrađeno u stranicu. Ažurirano **20. rujna 2026.**: v18 je na
GitHubu (grana main) zajedno s PHP adminom, API-jem, torbe.json i uputama. Odjeljci 3, 4 i 6 opisuju
put do v09 (povijest); odjeljak 9 opisuje v15–v18; odjeljci 1, 5 i 8 opisuju **trenutno stanje**.
Ovaj dokument je polazna točka za svaku sljedeću sesiju.

---

## 1. Gdje se što nalazi (stanje 20. 9. 2026.)

| Što | Gdje |
|---|---|
| Kod | GitHub repozitorij `ExarKun-1/shiva.j`, grana `main`, verzija **v18** (8. 9. 2026.) |
| Stranica | `index.html` (HTML + CSS + JS u jednoj datoteci), `torbe.json` (ponuda, na mainu prazna `[]`), `torbe.primjer.json` (primjeri za probu, ne objavljuje se) |
| Admin i servis | `admin/index.php`, `admin/lib.php`, `api/index.php`, `router.php` (lokalni test), `.htaccess` (preusmjeravanja, zaštita mape `data/`) |
| Podaci admina | mapa `data/` na hostingu (lozinka, postavke, rezervacije, narudžbe); u `.gitignore`, ne ide u git |
| Fotografije | `img/` (barkod-uplata.png, luna-1.jpg, tara-1.jpg), `images/` (originali, 8,7 MB) |
| Upute | `docs/`: popis za objavu v02, šablona potvrde narudžbe v04, upute admin v02, upute objava Croadria v02, upute rezervacije v03 |
| Objava | planirano: hosting Croadria (PHP). OG oznake još pokazuju na `exarkun-1.github.io/shivaj-webshop/` |
| Radna kopija na računalu | Glavna git kopija **`C:\SHIVAJ.HANDMADE\shiva.j`** (klon GitHub repozitorija, grana main). Zrcalo: `C:\Users\baric\Documents\shiva.j`. Arhiva svih verzija: `C:\SHIVAJ.HANDMADE\WEB STRANCA.1`. Lokalne Claude Code sesije otvarati na `C:\SHIVAJ.HANDMADE\shiva.j`. |
| PHP za lokalni test | **Nije instaliran** (Temp mapa s php83 obrisana). Instalacija: `winget install PHP.PHP.8.3`, zatim launch konfiguracija `shivaj-docs-php` (port 8090), admin lozinka `test-lozinka-123`. Admin i API nisu lokalno testirani nakon prijenosa; statička stranica jest. |
| Claude memorija (lokalno) | `shivaj-webshop-project.md`, `shivaj-tooling-quirks.md`, `shivaj-save-location.md` |
| Stari razgovor | Claude Code sesija „SHIVA.J web stranica — Fotografije ranijih torbi”, čitljiva u pregledniku na claude.ai/code |

---

## 1a. Pravila rada (vrijede za svaku sesiju, lokalnu i oblačnu)

- Nova verzija stranice = kopija prethodne pod imenom `shivaj_webshop_vNN.html`, s dopunom changeloga na vrhu datoteke i oznakom „· vNN” u podnožju. Jedna jasna promjena po verziji.
- Sprema se na tri mjesta: u `shiva.j` kao `index.html`, u `Documents\shiva.j` i u `WEB STRANCA.1`, pa commit i push na main.
- Proizvodi se ne izmišljaju. Za podatke vlasnice ostaju vitičaste zagrade `{…}`. Sve na hrvatskom.
- Pravno i porezno samo činjenice; za PDV, rokove i uvjete uputiti na knjigovođu.
- Prije rada povući s GitHuba, nakon rada pushati, da lokalna mapa i GitHub ostanu isti.

---

## 2. Podaci obrta (ugrađeni u impressum, uvjete, privatnost, uplatu)

| Podatak | Vrijednost |
|---|---|
| Naziv | SHIVA. J, obrt za dizajn |
| Vlasnica | Jasmina Šivalec |
| Adresa | Matije Gupca 33, 49210 Zabok, Hrvatska |
| OIB | 50934176320 |
| IBAN | HR98 2360 0001 1027 9044 2 |
| E-mail | shivaj.handmade@gmail.com |
| Instagram | @shiva.j_handmade |

---

## 3. Odluke po verzijama

| Verzija | Odluka |
|---|---|
| v01 | Osnovna stranica: mreža torbi, košarica, narudžba putem e-maila (mailto). |
| v02 | Slanje narudžbi kroz Formspree, mailto ostaje kao rezerva ako Formspree ne radi. |
| v03 | Lightbox s više fotografija, dimenzije i materijali uz torbu, OG oznake za Instagram/WhatsApp, mobilni izbornik, favicon, honeypot protiv botova, dostava u košarici. |
| v04 | Nakon narudžbe torba automatski postaje „Rezervirano” i ne može se ponovno naručiti. |
| v05 | Tijek plaćanja: potvrda i IBAN e-mailom po šabloni, usklađeni tekstovi. |
| v06 | GDPR: Bunny Fonts (EU) umjesto Google Fonts, politika privatnosti, impressum, pravo na raskid. |
| v07 | Spremno za prodaju: odjeljak „Uvjeti kupnje” (narudžba, plaćanje, dostava, raskid s obrascem, prigovori), gumb „Naruči s obvezom plaćanja” s kvačicom prihvaćanja uvjeta, telefon i poštanski broj u obrascu, odjeljak „Kako naručiti”. |
| v08 | Probne fotografije dviju ranijih torbi; primjeri Luna i Tara dobili fotografije i opis. |
| v09 | Stvarni podaci obrta na svim mjestima. Podaci za uplatu odmah nakon narudžbe: IBAN, iznos, model HR00, poziv na broj = datum uplate (DDMMGGGG), barkod za m-bankarstvo, gumb „Kopiraj”. Ispravak: obrazac i „Ukupno” više se ne vide u praznoj košarici. |

---

## 4. Kako radi narudžba i plaćanje (poslovna logika)

1. Kupac odabere torbu i doda je u košaricu. Svaka torba je unikat, količina je uvijek 1.
2. Popuni obrazac: ime i prezime, e-mail, telefon (za dostavnu službu), ulica, poštanski broj i mjesto, napomena. Mora označiti prihvaćanje Uvjeta kupnje.
3. Klik na „Naruči s obvezom plaćanja” šalje narudžbu kroz Formspree na shivaj.handmade@gmail.com. Ako Formspree nije postavljen ili ne radi, otvara se kupčev e-mail program s gotovom porukom.
4. Torba odmah postaje „Rezervirano” (samo u kupčevom pregledniku; trajno se označava ručno u kodu, vidi točku 6).
5. Kupac odmah vidi podatke za uplatu: primatelj, IBAN s gumbom „Kopiraj”, iznos, model HR00, poziv na broj (današnji datum DDMMGGGG), opis „Narudžba — ime torbe”, barkod.
6. U roku 24 sata vlasnica šalje potvrdu narudžbe e-mailom. Ugovor je sklopljen primitkom potvrde.
7. Nakon uplate torba se pakira i šalje. Dostava je „po dogovoru”: iznos se javlja u potvrdi prije uplate.
8. Plaćanje karticom nije moguće.

**Pravni okvir ugrađen u uvjete:** pravo na jednostrani raskid u 14 dana od preuzimanja (ne vrijedi za torbe po mjeri), obrazac za raskid, povrat novca u 14 dana od povrata torbe, trošak povrata snosi kupac, prigovori s odgovorom u 15 dana, odgovornost za materijalne nedostatke prema ZOO i ZZP.

**Privatnost:** bez kolačića, analitike i praćenja. Podaci samo za obradu narudžbe. Izvršitelji obrade: Formspree (SAD, SCC), Gmail, dostavna služba, GitHub Pages, Bunny Fonts (EU). Neizvršene narudžbe brišu se u 30 dana.

---

## 5. Postavke u kodu v18 (vrh skripte u `index.html`)

| Konstanta | Vrijednost | Značenje |
|---|---|---|
| `FORMSPREE_ENDPOINT` | `api/order` | Narudžba ide na vlastiti PHP servis. Formspree se više ne koristi. |
| `RESERVATION_ENDPOINT` | `api` | Rezervacije u stvarnom vremenu preko PHP servisa (zamjena za Cloudflare Worker). |
| `PRODUCTS_URL` | `torbe.json` | Jedini izvor ponude. Prazna ili nedostajuća datoteka daje poruku „Ponuda se upravo priprema”. |
| `SHIPPING_OPTIONS` | BOX NOW paketomat 4,00 €; GLS na adresu 6,00 €; osobno preuzimanje 0 € (Matije Gupca 33, radnim danom 8–15 h) | Kupac bira u košarici. Prva opcija je zadana. |
| `BOXNOW.script` | službeni widget v5, `partnerId` prazan | Karta paketomata; radi bez `autoclose`. |
| `CATEGORIES` | `torbe` (Dostupni unikati) i `ostalo` (remeni, torbice, platnene torbe po narudžbi, s rokom izrade) | Svaka kategorija ima svoj link i tekst za praznu ponudu. |
| `PAYMENT` | SHIVA. J, obrt za dizajn; IBAN HR9823600001102790442; model HR00; barkod `img/barkod-uplata.png` (datoteka postoji) | Prikaz odmah nakon narudžbe. |

---

## 6. Proizvodi (popis `PRODUCTS` u kodu)

| Id | Ime | Opis | Cijena | Dimenzije | Materijal | Fotografija | Stanje |
|---|---|---|---|---|---|---|---|
| luna | Luna | Okrugla torba · patchwork u pastelnim tonovima | 65 € | {DIMENZIJE} | {MATERIJAL} | images/luna-1.jpg | dostupna |
| tara | Tara | Okrugla torba · patchwork u toplim tonovima | 55 € | {DIMENZIJE} | {MATERIJAL} | images/tara-1.jpg | dostupna |
| vela | Vela | Torba preko ramena · lan i pluto | 70 € | 28 × 22 × 8 cm | Lan, pluto, metalna kopča | nema | dostupna (primjer) |
| mira | Mira | Mala večernja torbica · vez ručnim koncem | 48 € | 22 × 14 × 6 cm | Saten, ručni vez, magnet | nema | rezervirano (primjer) |
| nera | Nera | Ruksak · voskirano platno | 85 € | 30 × 40 × 14 cm | Voskirano platno, koža, mesing | nema | dostupna (primjer) |
| zora | Zora | Shopper · tkanina s ručnim printom | 60 € | 40 × 36 × 12 cm | Pamuk s ručnim printom | nema | prodano (primjer) |

Luna i Tara su stvarne ranije torbe s probnim fotografijama. Ostale četiri su **izmišljeni primjeri** radi prikaza i treba ih zamijeniti stvarnom ponudom.

Stanja torbe: `sold: true` = „Prodano” (ostaje vidljiva), `reserved: true` = „Rezervirano” dok se čeka uplata. Kad uplata stigne: obrisati `reserved` i staviti `sold: true`.

---

## 7. Dizajn (odluke o izgledu)

- **Motiv:** prošiveni šav, isprekidana crta u boji konca kao potpis kroz cijelu stranicu (razdjelnici, podcrtavanja, favicon sa „S”).
- **Boje:** papir `#F6F6F3`, tinta `#1B1A17`, meka tinta `#5C5A53`, konac (botanički zelena) `#2E4A3A`, svijetlozelena `#E4EAE5`, linija `#DDDCD5`, prodano `#A8A69C`.
- **Fontovi (Bunny Fonts, EU):** Marcellus za naslove, Karla za tekst, Space Mono za oznake, cijene i tehničke podatke.
- **Ton teksta:** „Torbe koje postoje samo jednom.” Svaka torba je unikat, ne ponavlja se ni kroj ni tkanina. Kratke, tople rečenice.
- **Struktura stranice:** Hero → Dostupni unikati (mreža) → Kako naručiti (3 koraka) → O nama → Uvjeti kupnje → Politika privatnosti → Impressum → Kontakt/podnožje. Košarica je klizna ladica s desne strane.
- **Mobitel:** hamburger izbornik, jedan stupac, prelazak prstom kroz fotografije u lightboxu.

---

## 8. Otvorene stavke (stanje 20. 9. 2026.)

Riješeno od v09: Formspree (zamijenjen PHP servisom), primjeri proizvoda (uklonjeni iz stranice), višak fotografija u korijenu (uklonjen).

1. **Hosting i domena:** zakup kod Croadrije, prijenos po `docs/shivaj_upute_objava_croadria_v02.md`, prva prijava u admin (lozinka se postavlja pri prvom otvaranju).
2. **E-mail na domeni** umjesto shivaj.handmade@gmail.com (u stranici 11 mjesta; u adminu postavka e-maila).
3. **PDV napomena** u Uvjetima kupnje, točka 3 (redak 640): `{NAPOMENA O PDV-u}` (knjigovođa). **Rok uplate** u točki 5.
4. **Torbe:** vlasnica unosi u adminu (naziv, opis, cijena, dimenzije, materijal, fotografije uspravne 4:5). Stranica kreće prazna. Artikli iz kategorije Ostalo isto u adminu, s rokom izrade. Vitičaste zagrade `{DIMENZIJE}`, `{MATERIJAL}`, `{ROK IZRADE}` ostale su samo u `torbe.primjer.json`.
5. **Tekst „O nama”** u riječima vlasnice (sada opći tekst).
6. **OG oznake:** `og:url` i `og:image` (redci 95–96) pokazuju na `exarkun-1.github.io/shivaj-webshop/`. Zamijeniti domenom nakon zakupa. Prenijeti `img/og.jpg` (1200×630 px).
6a. **Barkod za uplatu:** `img/barkod-uplata.png` postoji u repozitoriju (PNG 596×152 px). Vlasnica potvrđuje da je to barkod iz njezine bankarske aplikacije za IBAN HR98 2360 0001 1027 9044 2, a ne probni.
7. **BOX NOW:** ručna proba karte u pravom pregledniku (klik na paketomat treba popuniti polje). `partnerId` upisati ako vlasnica dobije partnerski račun.
8. **Obrisati mapu `images/`** iz repozitorija (8,7 MB originala iz v09, stranica v18 je ne koristi; koristi `img/`). Originale zadržati u `WEB STRANCA.1`.
9. **Pravna provjera** Uvjeta kupnje prije objave (knjigovođa, po potrebi pravnik).
10. **Lokalni test admina i API-ja** nakon instalacije PHP-a (vidi odjeljak 1).
11. Cijeli popis s kvačicama: `docs/shivaj_popis_za_objavu_v02.md`.

---

## 9. Što se dogodilo NAKON v09 (iz razgovora 6.–8. rujna 2026.)

Rekonstruirano iz sažetka starog razgovora. Od 20. 9. 2026. sve navedeno **jest na GitHubu** (grana main, commit „v18: stranica, PHP admin/API, torbe.json, upute”).

| Verzija / paket | Što je napravljeno |
|---|---|
| v15 | Popis torbi seli u zasebnu datoteku `torbe.json` koju stranica čita pri otvaranju. |
| v16 | Stranica spojena na vlastiti PHP servis (zamjena za Formspree): narudžba nosi strukturirane podatke, politika privatnosti prepisana za hosting kod Croadrije (brend Hrvatskog Telekoma). |
| v17 | Ugrađeni primjeri torbi uklonjeni. `torbe.json` je jedini izvor ponude. Prazna ili nedostajuća datoteka prikazuje „Ponuda se upravo priprema” s linkom na Instagram. Cijene se upisuju u adminu uz svaku torbu, kad se torba objavljuje. |
| v18 | BOX NOW paketomat: widget radi bez `autoclose`, pa klik na paketomat odmah prenosi odabir u polje (naziv, adresa, ID). Karta se zatvara sama, gumb postaje „Promijeni paketomat na karti”. |
| Hosting paket `shivaj_hosting_v01.zip` | Mapa `hosting/` s PHP adminom i API-jem za Croadriju. **Admin** (`/admin/`): lozinka pri prvom otvaranju, torbe s prijenosom fotografija (automatski smanjene na 1400 px), uređivanje, skrivanje, redoslijed, Prodano, brisanje; rezervacije (Plaćeno, Storniraj, Ukloni oznaku, Trajno prodano); narudžbe; postavke (e-mail, rok rezervacije, IBAN, podaci za uplatu, probni e-mail, promjena lozinke). **API** (`/api/`): rezervacija sa zaključavanjem, broj narudžbe oblika SJ-GGGG-NNNN, e-mail vlasnici, automatska potvrda kupcu s podacima za uplatu i barkodom u privitku, link Plaćeno/Storno. Testirano lokalno s PHP 8.3. |
| Dokumenti | `shivaj_sablona_potvrda_narudzbe_v04.md` (šablona potvrde: način dostave, količine, rok izrade), `shivaj_upute_objava_croadria_v02.md`, `shivaj_popis_za_objavu_v02.md`, `shivaj_upute_admin_v02.md`, `torbe.json` (prazna, za objavu), `torbe.primjer.json` (primjeri za probu, ne prenosi se). |

**Odluke iz tog razdoblja**

- Hosting: Croadria (Hrvatski Telekom), Linux, PHP. Vlastiti servis umjesto Formspreeja i Cloudflarea. Politika privatnosti u v18 već navodi taj hosting.
- Dostava: BOX NOW paketomat kao opcija, uz količine i napomene u narudžbi.
- Cijene: ne čekaju se unaprijed. Upisuju se u adminu za svaku torbu pri objavi.
- Prije objave ostaje: zakup hostinga i domene, prijenos po uputama, prva prijava u admin, e-mail na domeni umjesto Gmaila, napomena o PDV-u u Uvjetima (knjigovođa).

**Gdje su datoteke v10–v18 i hosting paket**

1. Kao privitci u starom razgovoru (vidljivo u pregledniku na claude.ai/code). Preuzeti: `shivaj_webshop_v18.html`, `shivaj_hosting_v01.zip`, `torbe.json`, `torbe.primjer.json` i četiri `.md` dokumenta.
2. Na računalu u `C:\SHIVAJ.HANDMADE` (od 20. 9. 2026.; ranije privremena mapa `Temp\shivaj_site`, koja se više ne koristi).
3. Bilješke starog razgovora na računalu (Claude Code memorija): `shivaj-webshop-project.md`, `shivaj-tooling-quirks.md`, `MEMORY.md`.

**Lokalni testni poslužitelj** (radi samo dok je prozor otvoren), adresa `http://127.0.0.1:8090/`, admin `/admin/` s lozinkom `test-lozinka-123`:

```
"/c/Users/baric/AppData/Local/Temp/php83/php.exe" -S 127.0.0.1:8090 -t "/c/Users/baric/AppData/Local/Temp/shivaj_site" -d display_errors=1 -d upload_max_filesize=20M -d post_max_size=20M -d memory_limit=256M "/c/Users/baric/AppData/Local/Temp/shivaj_site/router.php"
```
