# Shiva.J — postavljanje i održavanje stranice na Croadriji · upute v03

Ove upute zamjenjuju `shivaj_upute_objava_croadria_v02.md`. Nazivi u Croadrijinu kontrolnom panelu mogu se
razlikovati, ali redoslijed koraka je isti. Upute ćemo dopuniti stvarnim nazivima nakon zakupa.

**Ukratko.** Stranica se postavlja jednom. Poslije se sve svakodnevno radi u adminu. Kad stigne nova verzija, prenosi
se paket „nadogradnja”, koji nikad ne dira narudžbe, ponudu ni fotografije.

---

## A. Prvo postavljanje (jednom)

### 1. Zakup
- [ ] Croadria → **Linux web hosting** (paket Business), godišnji zakup. Nemojte uzeti Windows paket.
- [ ] Domena. Može biti besplatna .hr za obrt preko CARNET-a (domains.hr) ili domena uz paket.
- [ ] Spremite e-mail dobrodošlice s pristupom panelu i FTP-u na sigurno mjesto i nikome ga ne šaljite.

### 2. Sigurna veza (https)
- [ ] Panel → **SSL → Let's Encrypt** za domenu i za `www`. Ako panel nudi preusmjeravanje na https, uključite ga.

### 3. Paket za prijenos
- [ ] U repozitoriju pokrenite `python3 tools/paket.py`. U mapi `dist/` nastaju dva paketa:
  - `shivaj-vNN-prvi.zip`: **samo za prvo postavljanje**;
  - `shivaj-vNN-nadogradnja.zip`: za svaku sljedeću verziju (odjeljak C);
  - `shivaj-vNN-KOD-PRVE-PRIJAVE.txt`: **kod za prvu prijavu** u admin. Zapišite ga, a datoteku ne prenosite na hosting.

  Paket vam može pripremiti i Claude.
- [ ] Panel → **File Manager** → mapa `public_html` (korijen web stranice) → **Upload** paketa `…-prvi.zip` →
  desni klik → **Extract**. Zatim obrišite zip i datoteku `PROCITAJ.txt`.
  - Bez File Managera: raspakirajte zip na računalu i u FileZilli prenesite sadržaj u `public_html`.

### 4. Admin
- [ ] Otvorite `https://vasa-domena.hr/admin`, upišite **kod za prvu prijavu** i postavite lozinku (barem 10 znakova).
  Spremite je u upravitelj lozinki. Kod sprječava da netko drugi prvi postavi lozinku dok datoteka čeka na hostingu;
  nakon postavljanja datoteka se sama briše.
- [ ] **Postavke** (obavezno prije prve narudžbe):
  - **Adresa stranice**, npr. `https://shivaj.hr`. Bez nje se narudžbe ne primaju, jer se od nje gradi link
    „potvrdite narudžbu”.
  - **E-mail vlasnice**: ovamo stižu narudžbe.
  - **Primatelj** i **IBAN**. Primatelja upišite točno kako ga vodi banka, jer banke provjeravaju ime primatelja.
  - Načini dostave i rok slanja nakon uplate.
  - **OIB obrta** (neobavezno): ispisuje se u potpisu potvrde kupcu.
  - Dok adresa stranice ili adresa pošiljatelja nisu upisane, admin na vrhu svakog ekrana to upozorava.
- [ ] **Torbe → + Nova torba / artikl**: naziv, cijena, kratki opis, **dimenzije**, materijal i fotografije. Unikat
  bez fotografije ne može se naručiti.

### 5. Provjera prije objave
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

### 6. Nakon objave
- [ ] Link na domenu stavite u Instagram bio.
- [ ] Kad dođe e-mail na domeni (npr. `info@shivaj.hr`, panel → E-mail računi), upišite ga u Postavke kao adresu
  pošiljatelja. Javite Claudeu da ga zamijeni i u tekstu stranice.

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
3. File Manager → `public_html` → Upload → Extract → **dopustite zamjenu** postojećih datoteka. Zatim obrišite zip i
   `PROCITAJ.txt`.
4. Otvorite stranicu: u podnožju mora pisati nova verzija (`· vNN`). Ako se ne vidi, osvježite stranicu (Ctrl+F5).

> **Nikad ne prenosite `data/`, `torbe.json` ni `img/` s računala na hosting.** U njima su prave narudžbe, lozinka,
> postavke, ponuda i fotografije koje je spremio admin. Paket „nadogradnja” ih zato namjerno nema.

---

## D. Sigurnosna kopija (jednom mjesečno)
File Manager → označite mapu `data/`, mapu `img/` i datoteku `torbe.json` → **Compress** (zip) → **Download**.
Spremite kopiju na računalo, npr. `C:\SHIVAJ.HANDMADE\kopije\2026-11.zip`.

---

## E. Kad nešto ne radi

| Simptom | Najčešći uzrok | Što napraviti |
|---|---|---|
| Kupac vidi „pišite nam e-mailom”, narudžbe se ne primaju | adresa stranice nije upisana | Admin → Postavke → Adresa stranice |
| E-mail „potvrdite narudžbu” ne stiže | neželjena pošta ili hosting ne šalje poštu | provjerite spam; u panelu provjerite slanje e-maila; adresa pošiljatelja na domeni |
| Preglednik upozorava „nije sigurno” | SSL nije uključen | Panel → SSL → Let's Encrypt |
| Nakon nadogradnje i dalje stara verzija | preglednik pamti staru stranicu | Ctrl+F5; na mobitelu zatvorite i ponovno otvorite karticu |
| Admin piše „Previše pogrešnih pokušaja” | 8 pogrešnih lozinki s iste veze | pričekajte 15 minuta, pa upišite lozinku pažljivo |
| Admin ne prima lozinku | zaboravljena lozinka | File Manager → `data/config.json` → Edit: vrijednost `"password_hash"` promijenite u `""` i spremite. Zatim u `data/` stvorite datoteku `PRVA-PRIJAVA.txt` s retkom `kod: ` i iza njega barem 16 nasumičnih slova i brojki (npr. iz generatora lozinki, ne riječi koje se mogu pogoditi), otvorite admin, upišite taj kod i novu lozinku. Ostale postavke ostaju, a svi preglednici se odjavljuju. |
| Ponuda je prazna | `torbe.json` je prepisana | vratite `torbe.json` i `data/` iz sigurnosne kopije |

Za sve ostalo otvorite Claude Code sesiju u repozitoriju `ExarKun-1/shiva.j` i opišite što vidite.
