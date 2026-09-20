# SHIVA.J web stranica — podaci i odluke

Sažetak svega što je dogovoreno i ugrađeno u stranicu do verzije **v09 (6. rujna 2026.)**.
Izvučeno iz samog koda stranice (`index.html`) i povijesti repozitorija, jer izvorni
razgovor („Fotografije ranijih torbi”, 6.–8. rujna 2026.) nije dostupan iz oblaka.
Ovaj dokument je polazna točka za svaku sljedeću sesiju.

---

## 1. Gdje se što nalazi

| Što | Gdje |
|---|---|
| Kod stranice | GitHub repozitorij `ExarKun-1/shiva.j`, datoteka `index.html` (jedna datoteka: HTML + CSS + JS) |
| Fotografije | mapa `images/` (`luna-1.jpg`, `tara-1.jpg`, `okrugla-1.jpg`, `okrugla-2.jpg`) |
| Objava | GitHub Pages (planirano; adresa u OG oznakama još pokazuje na `exarkun-1.github.io/shivaj-webshop/`) |
| Stari razgovor | Claude Code sesija „SHIVA.J web stranica — Fotografije ranijih torbi”, vidljiva samo u pregledniku na claude.ai/code; nastavak zahtijeva Claude Code na istom računalu |

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

## 5. Postavke u kodu (vrh skripte u `index.html`)

| Konstanta | Trenutna vrijednost | Značenje |
|---|---|---|
| `FORMSPREE_ENDPOINT` | `https://formspree.io/f/VAS_KOD_OVDJE` | **Još nije postavljen.** Dok je tako, narudžba ide preko mailto. |
| `SHIPPING` | `null` | Dostava „po dogovoru”. Broj (npr. 5) bi dodao fiksnu cijenu dostave. |
| `PAYMENT.recipient` | SHIVA. J, obrt za dizajn | Primatelj uplate |
| `PAYMENT.iban` | HR9823600001102790442 | IBAN |
| `PAYMENT.model` | HR00 | Model plaćanja |
| `PAYMENT.barcode` | `img/barkod-uplata.png` | Slika barkoda iz bankarske aplikacije. **Datoteka ne postoji u repozitoriju**, pa se barkod ne prikazuje (stranica to tiho preskače). |

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

## 8. Otvorene stavke (što još treba napraviti)

1. **Formspree:** registrirati se na formspree.io sa shivaj.handmade@gmail.com, napraviti obrazac „Narudžbe” i zalijepiti adresu u `FORMSPREE_ENDPOINT`.
2. **Barkod za uplatu:** iz bankarske aplikacije („Podijeli barkod”) spremiti sliku i prenijeti je kao `img/barkod-uplata.png` (ili promijeniti putanju u `PAYMENT.barcode`).
3. **PDV napomena** u Uvjetima, točka 3: zamijeniti `{NAPOMENA O PDV-u}` (npr. „Shiva.J nije u sustavu PDV-a, pa se PDV ne obračunava”). Provjeriti s knjigovođom.
4. **Rok uplate** u Uvjetima, točka 5: zamijeniti `{ROK UPLATE, npr. 3 radna dana}`.
5. **Luna i Tara:** upisati stvarne dimenzije i materijal umjesto `{DIMENZIJE}` i `{MATERIJAL}`.
6. **Primjeri proizvoda** (Vela, Mira, Nera, Zora): zamijeniti stvarnim torbama ili obrisati.
7. **OG oznake:** `og:url` i `og:image` pokazuju na `exarkun-1.github.io/shivaj-webshop/`, a repozitorij se zove `shiva.j`. Uskladiti nakon objave na GitHub Pages. Prenijeti `img/og.jpg` (1200×630 px).
8. **Fotografije okrugla-1.jpg i okrugla-2.jpg** postoje u mapi `images/`, ali se ne koriste na stranici. Odlučiti kojoj torbi pripadaju ili ih dodati kao druge fotografije Lune/Tare.
9. **Višak datoteka:** u korijenu repozitorija su velike originalne fotografije `1784356525336.jpg` (1,7 MB) i `1784356525545.jpg` (3,4 MB), a `images/1784356525545.jpg` je kopija `tara-1.jpg`. Mogu se obrisati. Fotografije od 1,7 do 3,4 MB su prevelike za web i vrijedilo bi ih smanjiti (npr. na 1600 px širine, ispod 400 KB).
10. **Pravna provjera:** Uvjeti kupnje su označeni kao nacrt. Prije objave dati knjigovođi (PDV, rok uplate) i po potrebi pravniku.
11. **Objava:** uključiti GitHub Pages za repozitorij i provjeriti da se stranica otvara.

---

## 9. Što se dogodilo NAKON v09 (iz razgovora 6.–8. rujna 2026.)

Ovo je rekonstruirano iz sažetka starog razgovora koji je vlasnik zalijepio. **Ništa od
ovoga nije u GitHub repozitoriju**, gdje je i dalje v09. Zadnja verzija stranice je **v18**.

| Verzija / paket | Što je napravljeno |
|---|---|
| v15 | Popis torbi seli u zasebnu datoteku `torbe.json` koju stranica čita pri otvaranju. |
| v16 | Stranica spojena na vlastiti PHP servis (zamjena za Formspree): narudžba nosi strukturirane podatke, politika privatnosti prepisana za hosting kod Croadrije (brend Hrvatskog Telekoma). |
| v17 | Ugrađeni primjeri torbi uklonjeni. `torbe.json` je jedini izvor ponude. Prazna ili nedostajuća datoteka prikazuje „Ponuda se upravo priprema” s linkom na Instagram. Cijene se upisuju u adminu uz svaku torbu, kad se torba objavljuje. |
| v18 | BOX NOW paketomat: widget radi bez `autoclose`, pa klik na paketomat odmah prenosi odabir u polje (naziv, adresa, ID). Karta se zatvara sama, gumb postaje „Promijeni paketomat na karti”. |
| Hosting paket `shivaj_hosting_v01.zip` | Mapa `hosting/` s PHP adminom i API-jem za Croadriju. **Admin** (`/admin/`): lozinka pri prvom otvaranju, torbe s prijenosom fotografija (automatski smanjene na 1400 px), uređivanje, skrivanje, redoslijed, Prodano, brisanje; rezervacije (Plaćeno, Storniraj, Ukloni oznaku, Trajno prodano); narudžbe; postavke (e-mail, rok rezervacije, IBAN, podaci za uplatu, probni e-mail, promjena lozinke). **API** (`/api/`): rezervacija sa zaključavanjem, broj narudžbe, e-mail vlasnici, automatska potvrda kupcu s podacima za uplatu i barkodom u privitku, link Plaćeno/Storno. Testirano lokalno s PHP 8.3. |
| Dokumenti | `shivaj_sablona_potvrda_narudzbe_v04.md` (šablona potvrde: način dostave, količine, rok izrade), `shivaj_upute_objava_croadria_v02.md`, `shivaj_popis_za_objavu_v02.md`, `shivaj_upute_admin_v02.md`, `torbe.json` (prazna, za objavu), `torbe.primjer.json` (primjeri za probu, ne prenosi se). |

**Odluke iz tog razdoblja**

- Hosting: Croadria (Hrvatski Telekom), s PHP-om. Vlastiti servis umjesto Formspreeja i Cloudflarea.
- Dostava: BOX NOW paketomat kao opcija, uz količine i napomene u narudžbi.
- Cijene: ne čekaju se unaprijed. Upisuju se u adminu za svaku torbu pri objavi.
- Prije objave ostaje: zakup hostinga i domene, prijenos po uputama, prva prijava u admin, e-mail na domeni umjesto Gmaila, napomena o PDV-u u Uvjetima (knjigovođa).

**Gdje su datoteke v10–v18 i hosting paket**

1. Kao privitci u starom razgovoru (vidljivo u pregledniku na claude.ai/code). Preuzeti: `shivaj_webshop_v18.html`, `shivaj_hosting_v01.zip`, `torbe.json`, `torbe.primjer.json` i četiri `.md` dokumenta.
2. Na računalu, u **privremenoj mapi**: `C:\Users\baric\AppData\Local\Temp\shivaj_site` (radna kopija s PHP testnim poslužiteljem) i `C:\Users\baric\AppData\Local\Temp\php83` (PHP 8.3).
   **Upozorenje:** Windows i alati za čišćenje brišu sadržaj mape Temp. Kopirati `shivaj_site` odmah na sigurno mjesto (npr. `Dokumenti\shiva.j`) i prenijeti u GitHub repozitorij.
3. Bilješke starog razgovora na računalu (Claude Code memorija): `shivaj-webshop-project.md`, `shivaj-tooling-quirks.md`, `MEMORY.md`.

**Lokalni testni poslužitelj** (radi samo dok je prozor otvoren), adresa `http://127.0.0.1:8090/`, admin `/admin/` s lozinkom `test-lozinka-123`:

```
"/c/Users/baric/AppData/Local/Temp/php83/php.exe" -S 127.0.0.1:8090 -t "/c/Users/baric/AppData/Local/Temp/shivaj_site" -d display_errors=1 -d upload_max_filesize=20M -d post_max_size=20M -d memory_limit=256M "/c/Users/baric/AppData/Local/Temp/shivaj_site/router.php"
```
