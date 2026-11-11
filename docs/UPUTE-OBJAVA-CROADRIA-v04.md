# Shiva.J — postavljanje i održavanje stranice na CroAdriji · upute v04

Ove upute zamjenjuju v03 (i v02). Hosting je CroAdria **Plesk** (Linux, Apache), mapa web stranice je **`httpdocs`**,
PHP 8.4. Dopunjeno nakon pregleda prije objave (10. 11. 2026.): PHP postavke za fotografije s fotoaparata, obavezni
https prije prvog otvaranja admina, pošta na domeni (SPF, DKIM, DMARC), cron i provjera da se `data/` ne vidi s interneta.

**Ukratko.** Stranica se postavlja jednom. Poslije se sve svakodnevno radi u adminu. Kad stigne nova verzija, prenosi
se paket „nadogradnja”, koji nikad ne dira narudžbe, ponudu ni fotografije.

---

## A. Prvo postavljanje (jednom)

### 1. Zakup
- [ ] Croadria → **Linux web hosting** (paket Business), godišnji zakup. Nemojte uzeti Windows paket.
- [ ] Domena. Može biti besplatna .hr za obrt preko CARNET-a (domains.hr) ili domena uz paket.
- [ ] Spremite e-mail dobrodošlice s pristupom panelu i FTP-u na sigurno mjesto i nikome ga ne šaljite.

### 2. PHP postavke (Plesk → Websites & Domains → shivaj.hr → PHP)
- [ ] Verzija **8.4**, način rada „FPM application served by Apache” ako se nudi.
- [ ] Upišite (fotografije s Jasmininog Fujifilma imaju 3–6 MB i 4000 × 4000 px):

  | postavka | vrijednost |
  |---|---|
  | `upload_max_filesize` | `20M` |
  | `post_max_size` | `64M` (više fotografija u jednom spremanju) |
  | `max_file_uploads` | `20` |
  | `memory_limit` | `256M` |
  | `display_errors` | `Off` |
  | `log_errors` | `On` |
  | `date.timezone` | `Europe/Zagreb` |
- [ ] Proširenje **gd** mora biti uključeno (bez njega se fotografije ne spremaju).
- Ako su ograničenja preniska, admin to sada javlja imenom fotografije i razlogom (v89), ništa se ne gubi tiho.

### 3. Sigurna veza (https) — prije prvog otvaranja admina
- [ ] Čekajte da CARNET aktivira domenu (provjera: domene.hr → WHOIS).
- [ ] Plesk → **SSL/TLS Certificates → Let's Encrypt**: domena, `www`, i **kvačice za poštu** („Secure webmail”, „Secure
  mail”, „Assign the certificate to the mail domain”).
- [ ] Plesk → **Hosting Settings → „Permanent SEO-safe 301 redirect from HTTP to HTTPS”**. To pravilo je u postavkama
  poslužitelja, pa ga paket (koji zamjenjuje `.htaccess`) ne briše.
- [ ] **Admin ne otvarajte dok preglednik ne pokazuje lokot** (adresa počinje s `https://`). Lozinka i kod prve prijave
  inače putuju nezaštićeni.
- [ ] HSTS uključite tek na kraju, kad sve radi barem tjedan dana.

### 4. Pošta na domeni (prije prve narudžbe)
Pošta poslana s Gmail adrese s CroAdrijina poslužitelja pada na provjeri SPF i završava u spamu ili se odbija —
uključujući e-mail s podacima za uplatu. Zato:
- [ ] Plesk → **Mail → Create Email Address**: `info@shivaj.hr` (lozinku upisuje Jasmina sama, nikome je ne šalje).
- [ ] Plesk → **Mail Settings → DKIM** uključiti.
- [ ] Plesk → **DNS Settings**: provjeriti da postoji SPF zapis (TXT `v=spf1 … -all` ili `~all` s poslužiteljem
  CroAdrije) i dodati DMARC: TXT `_dmarc` = `v=DMARC1; p=none; rua=mailto:info@shivaj.hr`.
- [ ] Admin → Postavke → **adresa pošiljatelja** `info@shivaj.hr` (ili SMTP način, s istom adresom).
- [ ] Javite Claudeu da Gmail adresu zamijeni s `info@shivaj.hr` i u tekstu stranice (12 mjesta, uključujući Uvjete).
- [ ] Probni e-mail iz admina (Postavke → Pošalji probni e-mail) na Gmail i na jednu drugu adresu; mora stići u
  „Primljeno”, ne u spam.

### 5. Paket za prijenos
- [ ] U repozitoriju pokrenite `python3 tools/paket.py`. U mapi `dist/` nastaju dva paketa:
  - `shivaj-vNN-prvi.zip`: **samo za prvo postavljanje**;
  - `shivaj-vNN-nadogradnja.zip`: za svaku sljedeću verziju (odjeljak C);
  - `shivaj-vNN-KOD-PRVE-PRIJAVE.txt`: **kod za prvu prijavu** u admin. Zapišite ga, a datoteku ne prenosite na hosting.

  Paket vam može pripremiti i Claude.
- [ ] Plesk → **Files** → mapa **`httpdocs`** → **Upload** paketa `…-prvi.zip` → odaberite ga → **Extract Files**.
  Zatim obrišite zip i datoteku `PROCITAJ.txt`. Zadanu Pleskovu `index.html`/`index.php` iz `httpdocs` paket zamjenjuje.
  - Bez File Managera: raspakirajte zip na računalu i u FileZilli prenesite sadržaj u `httpdocs`.
- [ ] **Provjera zaštite** — svaka od ovih adresa mora vratiti grešku 403 ili 404, nikako sadržaj:
  `https://shivaj.hr/data/config.json`, `…/data/PRVA-PRIJAVA.txt`, `…/data/narudzbe.json`, `…/admin/lib.php`,
  `…/docs/`, `…/img/.htaccess`, `…/data/mail-log.txt`, `…/data/greske.log`, `…/data/zahtjevi.json`, `…/data/kopije/`.
  Najlakše: Admin → Postavke → **Provjeri zaštitu podataka** (v90) radi to sam i crveno upozorava. Ako se bilo što otvori: Plesk → Apache & nginx Settings → isključiti „Serve static files
  directly by nginx” (ili mu maknuti `json`/`txt`), pa provjeriti ponovno.

### 6. Admin
- [ ] Tek kad je korak 3 gotov: otvorite `https://shivaj.hr/admin`, upišite **kod za prvu prijavu** i postavite lozinku (barem 10 znakova).
  Spremite je u upravitelj lozinki. Kod sprječava da netko drugi prvi postavi lozinku dok datoteka čeka na hostingu;
  nakon postavljanja datoteka se sama briše.
- [ ] **Postavke** (obavezno prije prve narudžbe):
  - **Adresa stranice**, npr. `https://shivaj.hr`. Bez nje se narudžbe ne primaju, jer se od nje gradi link
    „potvrdite narudžbu”.
  - **E-mail vlasnice**: ovamo stižu narudžbe.
  - **Adresa pošiljatelja**: `info@shivaj.hr` (korak 4).
  - **Primatelj** i **IBAN**. Primatelja upišite točno kako ga vodi banka, jer banke provjeravaju ime primatelja.
  - Načini dostave i rok slanja nakon uplate.
  - **OIB obrta** (neobavezno): ispisuje se u potpisu potvrde kupcu.
  - Dok adresa stranice ili adresa pošiljatelja nisu upisane, admin na vrhu svakog ekrana to upozorava.
- [ ] **Torbe → + Nova torba / artikl**: naziv, cijena, kratki opis, **dimenzije**, materijal i fotografije. Unikat
  bez fotografije ne može se naručiti.

### 7. Automatski rokovi (cron)
- [ ] Plesk → **Scheduled Tasks → Add Task → Fetch a URL**: `https://shivaj.hr/api/status` (bez `?v=1`), svakih
  **15 minuta**. Bez toga se podsjetnici i automatsko storniranje neplaćenih narudžbi pokreću tek kad netko otvori
  stranicu.

### 8. Provjera prije objave
- [ ] `node tests/objava.js` mora javiti „SPREMNO ZA OBJAVU”. Dok u Uvjetima stoji `{NAPOMENA O PDV-u}`, provjera
  pada.
- [ ] **Probna narudžba do kraja**, na mobitelu i na računalu:
  1. naručite torbu sa svojim e-mailom;
  2. stiže e-mail „potvrdite narudžbu”;
  3. kliknite link;
  4. otvaraju se podaci za uplatu;
  5. vlasnica dobiva e-mail „Narudžba SJ-…”.

  Zatim u adminu probnu narudžbu **stornirajte**.
- [ ] Ako e-mail ne stiže, pogledajte neželjenu poštu i odjeljak E.

### 9. Nakon objave
- [ ] Link na domenu stavite u Instagram bio.
- [ ] Za tjedan dana, ako sve radi: HSTS (korak 3).
- [ ] Podijelite poveznicu na WhatsAppu/Facebooku i provjerite da se prikazuje nova slika (logo i torba).

---

## B. Svakodnevno: samo admin

| Što | Gdje u adminu |
|---|---|
| Nova torba, cijena, fotografije, mjere | **Torbe** → + Nova torba / Uredi |
| Stigla je uplata | **Narudžbe** → „Plaćeno” (ili link iz e-maila narudžbe) |
| Kupac je odustao | **Rezervacije** → „Storniraj” (torba se vraća u prodaju) |
| Dostava, IBAN, rok slanja, e-mail | **Postavke** |
| Koliko ljudi dolazi i naručuje | **Mjerenje** |

Datoteke na hostingu pritom se ne diraju.

---

## C. Nova verzija stranice (v48, v49 …)

1. Claude javlja da je nova verzija spojena u `main`.
2. `python3 tools/paket.py` → `dist/shivaj-vNN-nadogradnja.zip`.
3. Plesk → Files → `httpdocs` → Upload → Extract → **dopustite zamjenu** postojećih datoteka. Zatim obrišite zip i
   `PROCITAJ.txt`.
4. Otvorite stranicu: u podnožju mora pisati nova verzija (`· vNN`). Ako se ne vidi, osvježite stranicu (Ctrl+F5).

> **Nikad ne prenosite `data/`, `torbe.json` ni `img/` s računala na hosting.** U njima su prave narudžbe, lozinka,
> postavke, ponuda i fotografije koje je spremio admin. Paket „nadogradnja” ih zato namjerno nema.

---

## D. Sigurnosne kopije i vraćanje
- **Dnevno, samo od sebe (v90):** jednom dnevno (cron `api/status` ili otvaranje admina) sve `data/*.json` kopiraju se u
  `data/kopije/GGGG-MM-DD/`; čuva se zadnjih 14 dana. Admin → Postavke → Provjera pokazuje datum zadnje kopije.
- **Jednom mjesečno, na računalo:** Plesk → Files → `httpdocs` → označite mapu `data/`, mapu `img/` i datoteku
  `torbe.json` → **Compress** (zip) → **Download**. Spremite npr. `C:\SHIVAJ.HANDMADE\kopije\2026-11.zip`.
- **Vraćanje** (npr. admin javlja „HITNO: datoteka … je oštećena”): u `data/kopije/` otvorite zadnji dan prije
  kvara, kopirajte samo tu datoteku natrag u `data/` (zamijeni). Narudžbe se zatim ponovno primaju. Brojač narudžbi
  sam nastavlja od najvećeg postojećeg broja, pa se poziv na broj ne ponavlja ni kad se vrati starija `config.json`.

---

## E. Kad nešto ne radi

| Simptom | Najčešći uzrok | Što napraviti |
|---|---|---|
| Kupac vidi „pišite nam e-mailom”, narudžbe se ne primaju | adresa stranice nije upisana | Admin → Postavke → Adresa stranice |
| E-mail „potvrdite narudžbu” ne stiže | neželjena pošta ili hosting ne šalje poštu | provjerite spam; u panelu provjerite slanje e-maila; adresa pošiljatelja na domeni |
| Preglednik upozorava „nije sigurno” | SSL nije uključen | Plesk → SSL/TLS → Let's Encrypt; uključite 301 preusmjeravanje na https |
| Admin javlja da fotografija „nije spremljena: veća je od ograničenja” ili „Poslano je previše odjednom” | PHP ograničenja preniska | Plesk → PHP: postavke iz koraka 2 |
| E-mailovi završavaju u spamu | pošiljatelj nije na domeni ili nema DKIM/SPF | korak 4 |
| Nakon nadogradnje i dalje stara verzija | preglednik pamti staru stranicu | Ctrl+F5; na mobitelu zatvorite i ponovno otvorite karticu |
| Admin piše „Previše pogrešnih pokušaja” | 8 pogrešnih lozinki s iste veze | pričekajte 15 minuta, pa upišite lozinku pažljivo |
| Admin ne prima lozinku | zaboravljena lozinka | File Manager → `data/config.json` → Edit: vrijednost `"password_hash"` promijenite u `""` i spremite. Zatim u `data/` stvorite datoteku `PRVA-PRIJAVA.txt` s retkom `kod: ` i iza njega barem 16 nasumičnih slova i brojki (npr. iz generatora lozinki, ne riječi koje se mogu pogoditi), otvorite admin, upišite taj kod i novu lozinku. Ostale postavke ostaju, a svi preglednici se odjavljuju. |
| Ponuda je prazna | `torbe.json` je prepisana | vratite `torbe.json` i `data/` iz sigurnosne kopije |
| Fotografija torbe se ne vidi ili se ne sprema | (1) na hostingu nema PHP proširenja **GD** — PHP 8.4.27 testiran 6. 11. 2026. bez GD-a, sve ostalo radi, ali spremanje fotografije bez GD-a javlja grešku; (2) fotografija nije JPG, PNG ili WEBP (npr. HEIC s iPhonea); (3) mapa `img/` nema dozvolu pisanja | Admin → Postavke → Provjera: mora pisati „GD da”; ako piše „NE”, Plesk → PHP → uključiti proširenje gd (ili zamoliti podršku). Fotografiju s iPhonea spremiti kao JPG. U File Manageru provjeriti da `img/` postoji i da je zapisiva (755). |

Za sve ostalo otvorite Claude Code sesiju u repozitoriju `ExarKun-1/shiva.j` i opišite što vidite.
