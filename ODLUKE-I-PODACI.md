# SHIVA.J web stranica — podaci i odluke

Sažetak svega što je dogovoreno i ugrađeno u stranicu. Ažurirano **20. rujna 2026.**: v18 je na
GitHubu (grana main) zajedno s PHP adminom, API-jem, torbe.json i uputama. Odjeljci 3, 4 i 6 opisuju
put do v09 (povijest); odjeljak 9 opisuje v15–v18; odjeljci 1, 5 i 8 opisuju **trenutno stanje**.
Ovaj dokument je polazna točka za svaku sljedeću sesiju.

---

## 1. Gdje se što nalazi (ažurirano 10. 10. 2026.)

| Što | Gdje |
|---|---|
| Kod | GitHub repozitorij `ExarKun-1/shiva.j`, grana `main`, verzija **v28** stranice + **api v08 / admin v08** (10. 10. 2026.; v20–v28 iz pregleda dizajna i vijeća recenzenata, popravci P0–P1 iz pregleda dizajna, `docs/PREGLED-DIZAJNA-v19.md`; prije toga v19 i api v02 22. 9. 2026., sigurnosni popravci iz `docs/PREGLED-KODA-v18.md`) |
| Stranica | `index.html` (HTML + CSS + JS u jednoj datoteci), `torbe.json` (ponuda; piše je admin na hostingu, nije u gitu; predložak `torbe.json.example`), `torbe.primjer.json` (primjeri za probu, ne objavljuje se) |
| Admin i servis | `admin/index.php`, `admin/lib.php`, `api/index.php`, `router.php` (lokalni test), `.htaccess` (preusmjeravanja, zaštita mape `data/`) |
| Podaci admina | mapa `data/` na hostingu (lozinka, postavke, rezervacije, narudžbe, brojač zahtjeva); u `.gitignore`, ne ide u git. `data/PRVA-PRIJAVA.txt` dopušta prvo postavljanje lozinke i briše se sama. |
| Fotografije | `img/` (barkod-uplata.png, luna-1.jpg, tara-1.jpg), `images/` (originali, 8,7 MB) |
| Upute | `docs/`: popis za objavu v02, šablona potvrde narudžbe v04, upute admin v02, upute objava Croadria v02, upute rezervacije v03 |
| Objava | planirano: hosting Croadria (PHP). OG oznake još pokazuju na `exarkun-1.github.io/shivaj-webshop/` |
| Radna kopija na računalu | Glavna git kopija **`C:\SHIVAJ.HANDMADE\shiva.j`** (klon GitHub repozitorija, grana main). Zrcalo: `C:\Users\baric\Documents\shiva.j`. Arhiva svih verzija: `C:\SHIVAJ.HANDMADE\WEB STRANCA.1`. Lokalne Claude Code sesije otvarati na `C:\SHIVAJ.HANDMADE\shiva.j`. |
| PHP za lokalni test | **Nije instaliran** (Temp mapa s php83 obrisana). Instalacija: `winget install PHP.PHP.8.3`, zatim launch konfiguracija `shivaj-docs-php` (port 8090), admin lozinka `(lozinka iz lokalnih bilješki)`. Admin i API nisu lokalno testirani nakon prijenosa; statička stranica jest. |
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
| nera | Nera | Ruksak · voskirano platno | 85 € | 30 × 40 × 14 cm | {MATERIJAL} | nema | dostupna (primjer) |
| zora | Zora | Shopper · tkanina s ručnim printom | 60 € | 40 × 36 × 12 cm | Pamuk s ručnim printom | nema | prodano (primjer) |

Luna i Tara su stvarne ranije torbe s probnim fotografijama. Ostale četiri su **izmišljeni primjeri** radi prikaza i treba ih zamijeniti stvarnom ponudom.

Stanja torbe: `sold: true` = „Prodano” (ostaje vidljiva), `reserved: true` = „Rezervirano” dok se čeka uplata. Kad uplata stigne: obrisati `reserved` i staviti `sold: true`.

---

## 7. Dizajn (odluke o izgledu)

- **Motiv:** prošiveni šav, isprekidana crta u boji konca kao potpis kroz cijelu stranicu (razdjelnici, podcrtavanja, favicon sa „S”).
- **Boje:** papir `#F6F6F3`, tinta `#1B1A17`, meka tinta `#5C5A53`, konac (botanički zelena) `#2E4A3A`, svijetlozelena `#E4EAE5`, linija `#DDDCD5`, prodano `#A8A69C`.
- **Fontovi (Bunny Fonts, EU):** Marcellus za naslove, Karla za tekst, Space Mono za oznake, cijene i tehničke podatke.
- **Ton teksta:** „Torbe koje postoje samo jednom.” Svaka torba je unikat, ne ponavlja se ni kroj ni tkanina. Kratke, tople rečenice.
- **Struktura stranice:** Hero → Dostupni unikati (mreža) → Kako naručiti (3 koraka) → Radionica (prije „O nama”) → Uvjeti kupnje → Politika privatnosti → Impressum → Kontakt/podnožje. Košarica je klizna ladica s desne strane.
- **Mobitel:** hamburger izbornik, jedan stupac, prelazak prstom kroz fotografije u lightboxu.

---

## 8. Otvorene stavke (ažurirano 10. 10. 2026.; brojevi redaka se mijenjaju sa svakom verzijom, tražite po tekstu)

Riješeno od v09: Formspree (zamijenjen PHP servisom), primjeri proizvoda (uklonjeni iz stranice), višak fotografija u korijenu (uklonjen).

1. **Hosting i domena:** zakup kod Croadrije, prijenos po `docs/shivaj_upute_objava_croadria_v02.md` (dopuna 22. 9.), prva prijava u admin (lozinka se postavlja pri prvom otvaranju, uz `data/PRVA-PRIJAVA.txt`).
1a. **Preostali popravci iz pregleda koda** (funkcionalne greške, mrtvi kod, dokumentacija): `docs/PREGLED-KODA-v18.md`, odjeljci 2–4; sigurnosni odjeljak 1 je riješen u v19 / api v02.
1b. **Pregled dizajna, otvoreno** (`docs/PREGLED-DIZAJNA-v19.md`): barkod s iznosom i pozivom na broj (treba vanjsku biblioteku, odluka vlasnice); rečenica u privatnosti o pamćenju košarice na uređaju kupca; Uvjeti točka 4 („potvrda u roku 24 sata”, a stiže odmah); fotografija torbe u heroju; kraći checkout; Uvjeti i privatnost na sklapanje.
2. **E-mail na domeni** umjesto shivaj.handmade@gmail.com (u stranici 11 mjesta; u adminu postavka e-maila).
3. **PDV napomena** u Uvjetima kupnje, točka 3: `{NAPOMENA O PDV-u}` (knjigovođa). **Rok uplate** u točki 5 za artikle po narudžbi (bez rezervacije); **IBAN i primatelj** u točki 5 i Impressumu upisani su fiksno, a ploča i e-mail ih čitaju iz Postavki: pri promjeni računa uskladiti oboje.
4. **Torbe:** vlasnica unosi u adminu (naziv, opis, cijena, dimenzije, materijal, fotografije uspravne 4:5). Stranica kreće prazna. Artikli iz kategorije Ostalo isto u adminu, s rokom izrade. Vitičaste zagrade `{DIMENZIJE}`, `{MATERIJAL}`, `{ROK IZRADE}` ostale su samo u `torbe.primjer.json`.
5. **Tekst „Radionica”**: v25 ima konačni tekst u prvom licu (članci + podaci vlasnika projekta 9. 10. 2026.). Jasmina još provjerava „J od Jasmine” i godine članaka (Zagorje.com, Stilueta) te dodaje fotografiju `img/radionica.jpg` (uspravna 4:5). **Uvjeti kupnje, točka 2:** prva rečenica promijenjena u v25 (unikati = torbe označene „Jedan primjerak”); **točke 4 i 5:** u v26 umetnuta riječ „unikatna” (rezervacija vrijedi samo za unikate); Jasmina i knjigovođa potvrđuju. Otvoreno za knjigovođu/pravnika: rok uplate za narudžbe bez rezervacije (artikli po narudžbi) i pravo na raskid za klasičnu torbu po narudžbi bez posebnih želja (Direktiva 2011/83/EU, čl. 16(c): izuzeće vrijedi samo za robu po specifikaciji potrošača). **Vijeće o v26 ostavilo vlasniku/knjigovođi:** PDV napomena u t. 3 (`{NAPOMENA O PDV-u}`) i dalje nepopunjena; t. 5 i Impressum imaju IBAN upisan fiksno, a ploča i e-mail čitaju postavke (uskladiti ili uputiti na postavke); Politika privatnosti: nakon „Plaćeno” ime i e-mail ostaju u zapisu prodanog, a „nerealizirane brišemo u roku 30 dana” nema mehanizam (knjigovođa/pravnik: ili upisati stvarnu praksu ili dodati brisanje); og:url i og:image čekaju domenu (`img/og.jpg` postoji od v28, u fontovima branda od v29); citat iz Dnevnika u traci (Jasmina bira); plaćanje na licu mjesta kod preuzimanja (kupac pita zašto uplata unaprijed; poslovna odluka). Jasmina još: škare ili nož (4. odlomak Radionice), želi li rečenice „od svake koja nije uspjela nešto sam naučila” i „odgovaram sama” (u HTML komentaru), primatelj u Postavkama doslovno kao u banci (banke od listopada 2025. provjeravaju ime primatelja).
6. **OG oznake:** `og:url` i `og:image` pokazuju na `exarkun-1.github.io/shivaj-webshop/`. Zamijeniti domenom nakon zakupa. Prenijeti `img/og.jpg` (1200×630 px).
6a. **Barkod za uplatu:** `img/barkod-uplata.png` postoji u repozitoriju (PNG 596×152 px). Vlasnica potvrđuje da je to barkod iz njezine bankarske aplikacije za IBAN HR98 2360 0001 1027 9044 2, a ne probni.
7. **BOX NOW:** ručna proba karte u pravom pregledniku (klik na paketomat treba popuniti polje). `partnerId` upisati ako vlasnica dobije partnerski račun.
8. **Obrisati mapu `images/`** iz repozitorija (8,7 MB originala iz v09, stranica v18 je ne koristi; koristi `img/`). Originale zadržati u `WEB STRANCA.1`.
9. **Pravna provjera** Uvjeta kupnje prije objave (knjigovođa, po potrebi pravnik).
10. **Lokalni test admina i API-ja** nakon instalacije PHP-a (vidi odjeljak 1).
11. Cijeli popis s kvačicama: `docs/shivaj_popis_za_objavu_v02.md`.
12. **Iz šestog kruga vijeća (v29), za vlasnicu:** mješovita narudžba (šarena torba + artikl po narudžbi) šalje li se odjednom kad je sve gotovo ili šarena odmah (ploča i e-mail to zasad ne kažu); potpis e-maila „Jasmina · Shiva.J” umjesto samo „Shiva.J” (uz „odgovaram sama”); obveza „potvrdu smo primili” odgovorom kupcu; rezervacija prije narudžbe 2 sata (ako narudžba ne stigne, torba se tada vraća u ponudu; promjena je u `api/index.php`, konstanta `SJ_PREHOLD_HOURS`).
13. **Iz šestog kruga vijeća (v29), za pravnika (Uvjeti se nisu mijenjali osim pravopisa):** t. 4 kaže da je ugovor sklopljen kad kupac primi potvrdu e-mailom, a t. 5 traži uplatu odmah po narudžbi (prije ili nakon e-maila?); „Ukratko” bez „od primitka” uz 14 dana; t. 2 i korak 01 „u više primjeraka” (kupac može čitati kao „dobit ću više komada”); Politika privatnosti ne spominje da preglednik 24 sata pamti košaricu i podatke za uplatu (localStorage) ni da `zahtjevi.json` 24 sata čuva IP adresu kod narudžbe i rezervacije; kolačić `sjadmin` vrijedi do zatvaranja preglednika (vlasnica se na mobitelu broji kad otvori stranicu u novom pregledniku).

---

## 9. Što se dogodilo NAKON v09 (iz razgovora 6.–8. rujna 2026.)

Rekonstruirano iz sažetka starog razgovora. Od 20. 9. 2026. sve navedeno **jest na GitHubu** (grana main, commit „v18: stranica, PHP admin/API, torbe.json, upute”).

| Verzija / paket | Što je napravljeno |
|---|---|
| v15 | Popis torbi seli u zasebnu datoteku `torbe.json` koju stranica čita pri otvaranju. |
| v16 | Stranica spojena na vlastiti PHP servis (zamjena za Formspree): narudžba nosi strukturirane podatke, politika privatnosti prepisana za hosting kod Croadrije (brend Hrvatskog Telekoma). |
| v17 | Ugrađeni primjeri torbi uklonjeni. `torbe.json` je jedini izvor ponude. Prazna ili nedostajuća datoteka prikazuje „Ponuda se upravo priprema” s linkom na Instagram. Cijene se upisuju u adminu uz svaku torbu, kad se torba objavljuje. |
| v18 | BOX NOW paketomat: widget radi bez `autoclose`, pa klik na paketomat odmah prenosi odabir u polje (naziv, adresa, ID). Karta se zatvara sama, gumb postaje „Promijeni paketomat na karti”. |
| v19 + api v02 | Sigurnosni popravci iz pregleda koda (22. 9. 2026.), vidi `docs/PREGLED-KODA-v18.md`. |
| v20 + api v03 | Popravci iz pregleda dizajna (8. 10. 2026.), vidi `docs/PREGLED-DIZAJNA-v19.md`. **Odluka: poziv na broj = broj narudžbe** (SJ-2026-0007 → 2026-0007, model HR00) umjesto datuma uplate; promijenjeno na stranici, u potvrdi kupcu i u Uvjetima točka 5. Greške servisa ostaju vidljive iznad gumba; kod kvara kupac ne vidi „zaprimljeno” ni IBAN nego korake. Kopiraj uz IBAN, iznos i poziv, „Kopiraj sve”, „Podijeli”. Preglednik pamti košaricu, dostavu i podatke za uplatu zadnje narudžbe 24 h, bez osobnih podataka. Gumb ostaje „Naruči s obvezom plaćanja” (zakonska fraza; rečenica o rezervaciji stoji iznad gumba). Veći ciljevi dodira, fokus i čitač zaslona. |
| v21 + admin v03 + api v04 | „Vau i prodaja” prema vijeću recenzenata (8. 10. 2026.): torba na naslovnici, druga fotografija i priča torbe, Uvjeti i privatnost na sklapanje, vremenska crta nakon narudžbe, anonimno mjerenje u adminu (Mjerenje). Upute za fotografiranje: `docs/UPUTE-FOTOGRAFIRANJE.md`. Novo u adminu: polja „Karakter u jednom retku”, „Priča torbe”, kvačica „Na naslovnici”; u Postavkama „Rok slanja nakon uplate”. Instagram bio link: adresa stranice + `?izvor=ig`. |
| v22 + admin v04 | Drugi krug vijeća (8. 10. 2026.; umjetnički direktor v20 6/10 → v21 7,5/10, kupac bi kupio): poveznica „Radionica” i naslov „Iz radionice u Zaboku.” umjesto „O nama”; bez oznake „1/1”; manji redak karaktera u boji konca; gumb „Pogledaj unikate” na prvom ekranu; ispravci teksta (bit će rezervirana, Do isteka rezervacije, javit ćemo vam kad paket krene, „bez kartice” jednom, rodno neutralne poruke košarice). Mjerenje: plaćeno i isteklo po narudžbi, ne po torbi. |
| v23 | Lice i priča (9. 10. 2026.): traka „Pisali su o nama” ispod prvog ekrana s poveznicama na članke (Dnevnik.hr, Zagorje.com, Stilueta); „Radionica” u prvom licu kao nacrt iz tih članaka, popis članaka i mjesto za fotografiju `img/radionica.jpg`. Članci se iz radnog okruženja nisu mogli otvoriti; podaci su iz sažetaka tražilice i vlasnica ih provjerava. |
| v24 | Tekst „Radionica” ispričan življe (faks, sestra, torbe), i dalje samo iz činjenica u člancima; dvije stilske rečenice označene u HTML komentaru da ih vlasnica može izbaciti. |
| v25 + api v05 + admin v05 | **Odluka (9. 10. 2026., vlasnik projekta prema Jasmininim podacima): torbe se šiju od skaja, ne kože; šarene torbe od izrezanih komada su unikati i ne ponavljaju se; klasične torbe u jednoj boji (crne i slične) šiju se i po narudžbi.** Na stranici: hero odlomak, korak 01, Uvjeti točka 2 (prva rečenica: unikati su torbe označene „Jedan primjerak”, artikli „po narudžbi” u više primjeraka) i Radionica. U adminu se takva torba označi kvačicom „Artikl po narudžbi” i ostavi u kategoriji Torbe. Treći krug vijeća: traka „Pisali su o nama” bez vanjskih poveznica (vodi na Radionicu), popravljeno mobilno CSS pravilo, konačni tekst Radionice s adresom i pozivom da kupac piše prije narudžbe, redak „Uplaćujete obrtu Shiva.J, vl. Jasmina Šivalec, Zabok” ispod naslova podataka za uplatu, brojanje klikova na članke (Mjerenje, stupac „Klik na članke”). |
| v26 + api v06 + admin v06 | Radionica u konačnom obliku (vlasnik projekta + pisac brenda, nakon istraživanja stila `reports/Stil pisanja koji prodaje modu.md`): aforizam u prvoj rečenici, „J od mene”, mozaik, „šarena se dogodi, crna se dogovori”. Popravci po trećem krugu vijeća: „svaka torba postoji samo jednom” usklađeno na svih sedam mjesta (opis za tražilice i Instagram, prazna košarica, naslov kategorije „Torbe”, korak 02, **Uvjeti t. 4 i 5 samo riječ „unikatna”**, ploča nakon narudžbe); klasične torbe po narudžbi u mreži iza unikata pod podnaslovom, brojač „N unikata dostupno”, gumb „Naruči izradu”; redak kome se uplaćuje iz postavki admina, bez podataka za uplatu ako primatelj ili IBAN nisu popunjeni; „Plaćeno” za narudžbe bez rezervacije u popisu narudžbi (Mjerenje broji po narudžbi); ograničenje zahtjeva na brojaču; novi brojači Radionica, Košarica, Kopiraj, Bez unikata; plan čitanja brojeva `docs/MJERENJE.md`. |
| v27 + api v07 + admin v07 | Četvrti krug vijeća (dir. 8,5/10, pisac 8,5/10, kupac „Možda, skoro da”, istraživač): slogan „Ručno šivane torbe iz Zaboka” (naslov, OG, podnožje, e-mail); statični naslov „Torbe”; **Uvjeti: sažetak „Ukratko” prepisan, t. 4 bez dvostruke rečenice + rečenica „Artikli po narudžbi ne rezerviraju se”, navodnici; Politika privatnosti: rečenica o anonimnom brojanju i skraćenoj adresi (sat vremena)**; koraci 01–03 i napomena iznad gumba točni za obje vrste; ploča nakon narudžbe (rok izrade, preuzimanje, 14 dana običnim jezikom, „Uplaćujete izravno na račun primatelja …”, bez kopiranja/barkoda kad podaci nisu popunjeni); mreža: podnaslov „Klasične torbe” kao naslov, „Naruči izradu” i u povećanom prikazu; Radionica dorađena (dvije uvodne rečenice, „rezom … šavom”, citat u lijevom stupcu); izravna poveznica `#torba-ID` za Instagram objave; brojač: vlasnica se ne broji, `orders_m` = bez unikata, „plaćeno” jednom po narudžbi (oznaka u narudžbi), ručno „prodano” bez narudžbe ne ulazi u brojač; e-mail kupcu bez „sve naše torbe postoje samo jednom”, preuzimanje umjesto slanja. |
| v28 + api v08 + admin v08 | Peti krug vijeća (dir. 8,5, pisac 9 / Radionica 9,5, kupac „Da”, istraživač), sve što ide bez vlasnice (10. 10. 2026.): pošta s adresom pošiljatelja na omotnici (SPF/DMARC); narudžba se ne može poslati dvaput (ključ iz preglednika); preuzimanje usklađeno „javit ćemo vam se i dogovoriti termin” (ploča, e-mail, zadana postavka `pickup_info`; **postojeće instalacije: ažurirati tekst u Postavkama**); e-mail kupcu: „Dobar dan”, „čeka vašu uplatu” umjesto „sada je vaša”, barkod spomenut samo kad ide privitak, „24 sata”, pravo na raskid u tekstu, opis plaćanja do 35 znakova (HUB-3); e-mail vlasnici: uputa za „Plaćeno” kod narudžbe bez unikata; ploča: korak 1 sa snimkom zaslona i čuvanjem nakon isteka, korak 2 s rokom izrade iz podatka torbe, rod u koraku 3, napomena samo o e-mailu, iznos istaknut; brojač: istekla pa plaćena narudžba ulazi u „Plaćeno” (u Narudžbama), ručno „prodano” ne preuzima tuđu rezervaciju i ne broji se, artikli po narudžbi izvan ručnog „prodano”, nasumična dnevna tajna za skraćenu adresu (`data/sol.json`), skraćena adresa i za neuspjele prijave; tekst: „Luna će biti rezervirana”, „ni raspored boja”, ograda u sažetku Uvjeta, citat u Radionici samo lijevo s potpisom, pravi navodnici u porukama; `#torba-ID` i pri promjeni adrese; `img/og.jpg` (1200×630, iz fotografije Tare) za dijeljenje linka; dokumenti: `docs/MJERENJE.md` dopunjen, uputa za Instagram link u uputama za admin. |
| v29 + api v09 + admin v09 | Šesti krug vijeća (10. 10. 2026.; dir. 8,5, pisac 9 / Radionica 9,5, istraživač 7 za lansiranje, kupac „Da, uz prave fotografije”). Dva popravka iz v28 bila su samo napola gotova i sada su dovršena: **ključ narudžbe** preživi osvježavanje stranice (mijenja se samo kad se promijeni sadržaj narudžbe), a nakon prekida veze kupac dobiva „Pošalji ponovno” (isti ključ) umjesto automatskog e-maila; **istekla pa plaćena narudžba** ima gumb „Plaćeno” u Narudžbama, koji torbu, ako je slobodna, označi plaćenom za tog kupca. Poslužitelj: narudžba sama rezervira unikate pod istom bravom i odbija torbu koju drži drugi kupac, koja je prodana ili ručno rezervirana (409); **`api/reserve` drži torbu najviše 2 sata**, tek zaprimljena narudžba puni rok; „Isteklo” broji samo zaprimljene narudžbe; ključ se provjerava i upisuje pod istom bravom, ponovljeni odgovor kaže stvarni ishod e-mailova; artikl po narudžbi označen nedostupnim se ne prima; ručno „Plaćeno” ne dira tuđu rezervaciju (rezervirane i plaćene torbe nisu u popisu); zapis JSON-a ne prazni datoteku kad disk ili sadržaj zakaže, oštećena datoteka dobiva kopiju `.osteceno-datum`, a oštećen `config.json` se ne prepisuje zadanima; neuspjele prijave u admin broje se u bravi i brišu nakon dana; u Postavkama upozorenje kad adresa pošiljatelja nije na domeni stranice. E-mail kupcu: trajanje rezervacije u ispravnom obliku („1 sat”, „48 sati”), „upišite”, „na Instagramu”, torbu/torbe po broju, preuzimanje spomenuto jednom, uputa za barkod na mobitelu, povrat novca „u roku i na način iz t. 6”. E-mail vlasnici: „kliknite Plaćeno prije isteka rezervacije”. Stranica: vidi changelog v29 u `index.html` (ploča, brojač, nbsp, fieldset, kontrast, `img/og.jpg` u fontovima branda s `og:image:width/height/alt`). **Uvjeti: samo pravopis** („što prije”, „gotovo u istom trenutku”, „unikatna se torba”) i neprelomivi razmak u nazivu obrta i IBAN-u. Radionica ostaje kao u v28 (odluka vlasnika projekta: završetak 4. odlomka samo kao citat s potpisom). `torbe.primjer.json`: „kožni remeni” zamijenjeno s `{MATERIJAL}`. |
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
3. Bilješke na računalu (Claude Code memorija): `shivaj-webshop-project.md`, `shivaj-tooling-quirks.md`, `shivaj-save-location.md`.

**Lokalni testni poslužitelj** (nakon `winget install PHP.PHP.8.3`; radi samo dok je prozor otvoren), adresa `http://127.0.0.1:8090/`, admin `/admin/` s lozinkom `(lozinka iz lokalnih bilješki)`:

```
cd C:\SHIVAJ.HANDMADE\shiva.j
php -S 127.0.0.1:8090 -t . -d display_errors=1 -d upload_max_filesize=20M -d post_max_size=20M -d memory_limit=256M router.php
```
