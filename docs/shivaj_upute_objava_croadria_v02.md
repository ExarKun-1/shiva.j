# Shiva.J — objava stranice na Croadria hostingu · upute v02
> **Dopuna 22. 9. 2026.:** na hosting se prenose `index.html` (v19), `img/`, `api/`, `admin/`, `.htaccess`, mapa `data/` s datotekama `.htaccess` i `PRVA-PRIJAVA.txt`, te `torbe.json` (kopija `torbe.json.example`, prazna `[]`; kasnije je piše admin, zato više nije u gitu). Ne prenose se `docs/`, `*.md`, `torbe.primjer.json`, `router.php` (a `.htaccess` ih svejedno skriva). Odjeljak 7 dolje je zastario: rezervacije i narudžbe radi PHP servis u `api/`, Cloudflare i Formspree se ne koriste.


Datum: 2026-09-08 · vrijedi uz `shivaj_webshop_v17.html` i novije

Nazivi u kontrolnom panelu hostinga s vremenom se mijenjaju, ali redoslijed je isti. Pojmovi: **domena** je adresa (npr. shivaj.hr), **hosting** je prostor na kojem stranica živi, **DNS** je popis koji domenu usmjerava na hosting i e-mail.

## 1. Zakup

1. Croadria → **Linux web hosting → Business** paket, godišnji zakup. Ne Windows paket: naša stranica i budući admin (PHP) su Linux svijet.
2. Domena, dvije mogućnosti koje se mogu i kombinirati:
   - **Besplatna .hr domena za obrt** preko CARNET-a (domains.hr): jedna po OIB-u, ime izvedeno iz naziva obrta (npr. shiva-j.hr ili shivaj.hr, CARNET odobrava), obnavlja se besplatno svake godine. Kod prijave se traže podaci obrta, a za DNS poslužitelje upisuju se Croadrijini (piše u e-mailu dobrodošlice).
   - **Domena uz paket** (jedna .com, .eu, .com.hr i sl. besplatno uz godišnji zakup), registrira se pri zakupu.
3. Nakon zakupa stiže e-mail dobrodošlice s pristupom kontrolnom panelu i FTP podacima. Spremiti ga na sigurno, nikome ne slati.

## 2. Što se prenosi na hosting

U mapu `public_html` (ili kako je Croadria nazove, „korijen web stranice“):

| Datoteka / mapa | Što je |
|---|---|
| `index.html` | stranica, tj. `shivaj_webshop_vNN.html` preimenovana u `index.html` |
| `torbe.json` | prazna datoteka (`[]`) iz projektne mape; torbe se unose u adminu, koji je zapisuje. `torbe.primjer.json` služi samo za lokalnu probu i ne prenosi se |
| `img/` | fotografije torbi i `barkod-uplata.png` |

Uz to mape `admin/`, `api/` i `data/` te datoteka `.htaccess` iz `shivaj_hosting_v02.zip` (vidi upute za admin).

## 3. Kako prenijeti

- **Kontrolni panel → File Manager**: otvoriti `public_html`, Upload, odabrati datoteke. Mapu `img` napraviti gumbom New Folder pa u nju prenijeti slike.
- **Ili FTP program** (FileZilla): poslužitelj, korisničko ime i lozinka iz e-maila dobrodošlice; lijevo je računalo, desno hosting, povući datoteke.
- Nova verzija stranice: prenijeti novu datoteku preko stare `index.html`. Stare verzije čuvati na računalu, ne na hostingu.

## 4. SSL (https)

Kontrolni panel → SSL → **Let's Encrypt** za domenu (i www). Uključiti preusmjeravanje na https ako panel to nudi. Bez toga preglednici prikazuju upozorenje, a barkod i narudžbe idu nezaštićeno.

## 5. E-mail na domeni

1. Kontrolni panel → E-mail računi → novi račun, npr. `info@shivaj.hr` (i po želji `narudzbe@shivaj.hr`). Lozinka barem 12 znakova.
2. Na telefonu i računalu dodati račun (panel prikazuje IMAP/SMTP postavke; Gmail aplikacija može primati i slati s te adrese).
3. U Gmailu po želji postaviti prosljeđivanje s `shivaj.handmade@gmail.com` na novu adresu dok traje prijelaz.
4. Novu adresu upisati u stranicu (kontakt, impressum, uvjeti, privatnost, obrazac za raskid), na Instagram i u Formspree, ako se još koristi. Adresa se u stranici pojavljuje na više mjesta, pa to napraviti u jednoj verziji (vNN) i provjeriti pretragom.

## 6. Nakon prijenosa

1. Otvoriti domenu u pregledniku, na računalu i telefonu. Dok nema torbi piše „Ponuda se upravo priprema“; nakon unosa u adminu: fotografije, kategorije Torbe i Ostalo, košarica.
2. Provjeriti da stranica čita `torbe.json`: u pregledniku desni klik → Inspect → Console → upisati `document.documentElement.dataset.products` → mora pisati `torbe.json`, ne `ugradjeno`.
3. Probna narudžba do kraja: rezervacija, panel s podacima za uplatu, e-mail narudžbe, storno.
4. U stranici zamijeniti `og:url` i `og:image` (dijeljenje linka) domenom, i prenijeti `img/og.jpg` (1200×630 px).
5. Politika privatnosti od v16 već navodi hosting Croadria (Hrvatski Telekom d.d., EU); samo provjeriti da tekst odgovara stvarnom stanju.
6. Link na domenu staviti u Instagram bio. GitHub ostaje samo za testiranje ili se ugasi.

## 7. Rezervacije i narudžbe na hostingu

Dok admin nije gotov, rezervacije rade preko Cloudflare workera (upute v03), a narudžbe preko Formspreeja. Plan je oboje zamijeniti PHP servisom na istom Croadria hostingu, s adminom za torbe i fotografije; tada se u stranici mijenja samo adresa u konstanti RESERVATION_ENDPOINT, a Formspree se više ne koristi.

## 8. Sigurnosne kopije

- Sve verzije stranice ostaju u `C:\SHIVAJ.HANDMADE\WEB STRANCA.1`.
- `torbe.json` i mapu `img` jednom mjesečno preuzeti s hostinga na računalo (File Manager → Download), jer će ih admin mijenjati izravno na hostingu.
- Lozinke (panel, FTP, e-mail, admin) čuvati u upravitelju lozinki, ne u e-mailu.
