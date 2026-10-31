# Shiva.J — admin na hostingu · upute v02
> **Dopuna 22. 9. 2026. (admin v02, api v02, stranica v19)**
> - Prva prijava radi samo dok u mapi `data/` postoji datoteka `PRVA-PRIJAVA.txt` (u paketu je). Briše se sama čim postavite lozinku, pa nitko drugi ne može naknadno „postaviti” lozinku ni ako se `config.json` ošteti. Zaboravljena lozinka: u `data/config.json` vrijednost `"password_hash"` promijeniti u `""` **i** napraviti `data/PRVA-PRIJAVA.txt` s retkom `kod: ` i iza njega barem 16 nasumičnih slova i brojki, pa otvoriti admin i upisati taj kod (velika i mala slova nisu važna). Nakon 8 pogrešnih lozinki prijava s iste veze čeka 15 minuta; admin tada to i napiše.
> - Link „Plaćeno / storno” iz e-maila narudžbe radi tek nakon prijave u admin (u istom pregledniku). Iste radnje su i pod Rezervacije.
> - Postavke → **Načini dostave**: stranica i potvrde kupcu čitaju cijene dostave odavde, a servis po njima računa iznos narudžbe. Isto vrijedi za podatke za uplatu i e-mail vlasnice: stranica ih preuzima iz postavki pri otvaranju.
> - Servis zaprima najviše 6 stavki po narudžbi, 10 narudžbi i 20 rezervacija na sat s iste adrese; rezervirati se mogu samo stvarne, vidljive, neprodane torbe.


Datum: 2026-09-08 · vrijedi uz `shivaj_webshop_v17.html` i mapu `hosting/` (admin v01)

## Što je to

Admin je dio stranice na istom hostingu (Croadria, Linux paket) kroz koji vlasnica bez dodirivanja koda:
- dodaje, uređuje i skriva torbe i artikle te im prenosi fotografije s telefona (smanjuju se same),
- vidi rezervacije i klikom označava „Označi plaćenom“, „Storniraj“ ili „Označi trajno prodanom“ (od admin v21 jednosmjerno; natrag samo „Vrati u prodaju“ uz potvrdu),
- vidi zadnjih 300 narudžbi,
- mijenja rok rezervacije, e-mail adrese i podatke za uplatu.

Isti PHP servis zaprima narudžbe sa stranice, šalje ih vlasnici e-mailom i **automatski šalje kupcu potvrdu narudžbe s podacima za uplatu i barkodom**. Cloudflare i Formspree tada nisu potrebni.

## Što se prenosi na hosting

U `public_html` (korijen stranice):

| Što | Odakle |
|---|---|
| `index.html` | `shivaj_webshop_v16.html` (ili novija), preimenovana |
| `torbe.json` | prazna datoteka (`[]`) iz projektne mape; admin je zapisuje pri svakom spremanju |
| `img/` | fotografije i `barkod-uplata.png` |
| `.htaccess` | iz `hosting/` |
| `admin/` (index.php, lib.php) | iz `hosting/admin/` |
| `api/` (index.php) | iz `hosting/api/` |
| `data/` (samo `.htaccess`) | iz `hosting/data/`; ostale datoteke nastaju same |

`router.php` je samo za lokalno testiranje i ne prenosi se.

Mapa `data/` mora biti zapisiva (na Croadriji jest). Provjera zaštite: `https://vasa-domena/data/config.json` mora vratiti grešku 403, ne sadržaj.

## Prvo otvaranje (jednom)

1. Otvoriti `https://vasa-domena/admin/`. Prvi put traži da se **odabere lozinka** (barem 10 znakova). Spremiti je u upravitelj lozinki.
2. **Postavke**: e-mail vlasnice (kamo stižu narudžbe), adresa pošiljatelja na domeni (npr. `info@shivaj.hr`, mora postojati kao e-mail račun na hostingu), adresa stranice, rok rezervacije (24 h), podaci za uplatu. Spremiti.
3. Kliknuti **Pošalji probni e-mail**. Ako stigne, slanje radi. Ako ne, u Croadrijinu panelu provjeriti da adresa pošiljatelja postoji i da PHP `mail()` nije blokiran (podrška to riješi u minutu).
4. Na stranici provjeriti `https://vasa-domena/api/status`: mora pisati `{"ok":true,...}`.
5. **Torbe** → unijeti prve torbe, svaku sa svojom cijenom prema modelu. Do tada stranica prikazuje „Ponuda se upravo priprema“; primjera torbi u stranici nema.

## Svakodnevno

**Nova torba**: Torbe → + Nova torba / artikl → naziv, cijena, kategorija, opis, dimenzije, materijal → dodati fotografije → Spremi. Odmah je na stranici.

**Vrsta** (v63): u obrascu birate jednu od tri mogućnosti. **Unikat**: torba postoji samo jednom, prikazuje se u rubrici Torbe i rezervira se kad je netko naruči. **Stalna kolekcija**: model koji šijete ponovno, po narudžbi (npr. crna klasična torba). Upišite rok izrade i „Najviše mjesta” (prazno = 3; 0 = trenutno ne prima narudžbe). Kupac naručuje jedan komad po narudžbi i boju ili duljinu upisuje u napomenu. **Ostalo**: remeni, torbice, platnene torbe po narudžbi, s rokom izrade; vidljivo samo kad je rubrika Ostalo uključena u Postavkama.

**Fotografije**: uspravne, omjer oko 4:5, mirna pozadina. Prva je naslovna; naslovnu se mijenja u „Uredi“. Prenose se s telefona ravno iz galerije, smanjuju se na 1400 px same.

**Narudžba je stigla** (e-mail + Narudžbe u adminu): kupac je već dobio automatsku potvrdu s IBAN-om, iznosom i barkodom. Ništa ne treba slati ručno, osim ako želite dodati osobnu poruku.

**Kupac poslao potvrdu uplate ili uplata sjela**: Rezervacije ili Narudžbe → **Označi plaćenom** (ili link iz e-maila narudžbe). U Narudžbama stupac „Uplata” pokazuje „nije plaćeno” ili „plaćeno” s datumom. Torba ostaje „Prodano“ i nakon isteka rezervacije. Kad torbu pošaljete ili predate: **Označi trajno prodanom**. Torba tada ima oznaku „Trajno prodana ✓“, a ponovni klik ništa ne mijenja. Oznaku „Plaćeno“ tada **ne uklanjate** (gumb „Ukloni oznaku“ se za trajno prodanu torbu i ne prikazuje). Ako je torba greškom označena, vraća se samo gumbom **Vrati u prodaju**, uz potvrdu.

**Rezervacija „košarica, nije narudžba“**: kupac je torbu stavio u košaricu, ali narudžbu nije potvrdio. Za nju nema „Označi plaćenom“, samo „Ukloni“; inače sama istekne.

**Torba prodana izvan stranice** (npr. na sajmu): Rezervacije → „Ručno: torba prodana izvan stranice“ → odaberite torbu → **Označi odabranu torbu prodanom** (uz potvrdu).

**Kupac odustao**: Rezervacije → **Storniraj**. Ili ništa: rezervacija sama istekne.

**Sumnjive narudžbe (od v32)**: ako stigne e-mail „PAŽNJA, mnogo rezervacija” ili u Rezervacijama jedna adresa drži više torbi, a kupci se ne javljaju, kliknite **Storniraj sve s ove adrese**. Torbe se odmah vraćaju u prodaju. Stupac „Adresa” je skraćena, neprepoznatljiva oznaka veze (mijenja se svaki tjedan), ne prava IP adresa. Redak s oznakom „bez narudžbe” je samo torba u nečijoj košarici: ne blokira druge kupce i sama nestaje za najviše 2 sata.

**Torba prodana uživo ili na Instagramu**: Torbe → **Prodano** (ostaje vidljiva kao prodana) ili **Sakrij** (nestaje sa stranice, ostaje u adminu).

**Redoslijed** na stranici: strelice ↑ ↓ u popisu.

## Sigurnost

- Lozinka se čuva samo kao hash; nakon 8 pogrešnih pokušaja s iste adrese prijava je zaključana 15 minuta.
- Obrasci su zaštićeni od tuđih poziva (CSRF), fotografije se provjeravaju i ponovno kodiraju, `data/` nije javno dostupan.
- Admin radi samo preko HTTPS-a (Let's Encrypt u panelu). Adresa admina se ne dijeli; nije indeksirana.
- Lozinku mijenjate u Postavkama. Ako je zaboravite: u File Manageru otvoriti `data/config.json` i obrisati vrijednost `password_hash` (ostaviti `""`); sljedeće otvaranje admina opet traži novu lozinku.

## Sigurnosne kopije

Jednom mjesečno preuzeti `data/` i `img/` s hostinga (File Manager → Download). Datoteke su obične JSON i JPG, čitljive bez ikakvog programa.

## Testni način (za Zlatka)

U Postavkama kvačica „Testni način: e-mailove ne šalji, nego zapiši u data/mail-log.txt“. Lokalno: `php -S 127.0.0.1:8090 -t <mapa> router.php` u mapi koja sadrži `index.html`, `torbe.json`, `img/`, `admin/`, `api/`, `data/`.

**Link na torbu za Instagram objavu**: svaka torba ima oznaku (ID) koja se u adminu vidi ispod naziva sivim slovima (npr. `luna`). Link `https://vaša-domena/?izvor=ig#torba-luna` otvara stranicu i odmah povećani prikaz te torbe; `?izvor=ig` kaže Mjerenju da je posjet s Instagrama. U Instagram profil (bio) stavite `https://vaša-domena/?izvor=ig`, a u pojedinu objavu ili priču link s `#torba-ID`.

## Stalna kolekcija i rubrika „Ostalo” (v62, v63)
- **Stalna kolekcija**: modeli s vrstom „Stalna kolekcija” prikazuju se u istoimenoj rubrici. Jedno mjesto je jedna torba. U popisu Torbe piše npr. „mjesta 2/3”. Mjesto zauzima tek potvrđena narudžba; nepotvrđene i stornirane se ne broje. Kad je model popunjen, kupci vide „Popunjeno” i poziv da vam se jave na Instagramu ili e-mailom, a vi dobivate redak u e-mailu nove narudžbe.
- Mjesta se ne oslobađaju sama: kad ste spremni za nove narudžbe, u popisu Torbe kliknite **„Otvori nova mjesta”** (brojanje kreće od tog trenutka; stare narudžbe ostaju).
- Gumb **„Otvori nova mjesta”** pojavljuje se tek kad je model popunjen (v63).
- **Storniraj narudžbu** (Narudžbe, v63): uz svaku neplaćenu narudžbu. Narudžba se označi storniranom, unikatna torba se oslobađa, a mjesto u Stalnoj kolekciji se vraća. Kupcu se javite sami (poruka vam kaže na koju adresu). Stornirana narudžba više se ne može označiti plaćenom (v64); ako je kupac ipak uplatio, neka naruči ponovno ili mu vratite uplatu.
- **Narudžbe** (v64): uz kupca stoje e-mail i telefon, uz dostavu adresa ili paketomat, a uz artikle napomena kupca i vrsta stavke (unikat / Stalna kolekcija / po narudžbi).
- ~~Granica po internetskoj vezi (v65)~~ ukinuta je u v68: vrijede granice po narudžbi (5 torbi, 10 artikala, 2 unikata) i jedna neplaćena narudžba iz kolekcije po e-mailu.
- **Želja kupca** (boja, duljina) stoji u Narudžbama podebljano uz stavku i u e-mailu kao „ŽELJA KUPCA”. Ako je ne možete ispuniti, javite se kupcu prije šivanja: bira torbu bez te želje ili povrat cijelog iznosa (v65).
- **Rubrika „Ostalo”** (remeni, torbice, platnene torbe) uključuje se u Postavkama: „Prikaži rubriku Ostalo”. Dok je isključena, artikli ostaju u adminu, ali se ne vide i ne mogu naručiti.
- **v66:** gumb za privremeno isključenje modela zove se **„Privremeno isključi”** (natrag: „Ponovno uključi”). U Narudžbama se uz zadnjih 100 uvijek vide i **starije neplaćene narudžbe iz Stalne kolekcije** (oznaka „starija neplaćena (kolekcija)”), da ih možete stornirati. Telefon i e-mail kupca su poveznice.
- **v67:** starije neplaćene narudžbe iz Stalne kolekcije koje još drže mjesto stoje u zasebnoj tablici **iznad** popisa Narudžbi („Starije neplaćene (Stalna kolekcija) — drže mjesto”). Ako kupac ne uplati, stornirajte ih.

## Rokovi za uplatu, storno i nove postavke (v68)
- **Rok za uplatu = rezervacija** (Postavke, zadano 24 h). Kupac ga vidi na ploči i u e-mailu.
- **Podsjetnik** (zadano nakon 12 h): ide samo ako narudžbu niste označili plaćenom; bez barkoda, s rečenicom „nemojte plaćati ponovno”.
- **Rok istekao**: u Narudžbama, iznad popisa, kartice „Rok istekao (n)” s pozivom na broj i stanjem komada („komad slobodan” ili „prodano ili rezervirano drugome”). Ako je uplata stigla: **Označi plaćenom**. Ako nije: **Storniraj**. Ako ne kliknete ništa, narudžba se automatski stornira nakon još 48 h (Postavke: „Automatski storno nakon”; 0 = nikad) i kupac dobiva e-mail.
- **Storno s razlogom**: uz Storniraj birate razlog (nije uplaćeno u roku, kupac odustao, lažna narudžba, torba oštećena ili nedostupna, drugo + kratki tekst). Razlog se vidi u Narudžbama; automatski storno ima oznaku „(automatski)”.
- **Potpis u e-mailovima** (Postavke): zadano „Jasmina · Shiva.J”.
- **Besplatna dostava** (Postavke): prag (zadano 120 €; 0 = isključeno) i načini na koje vrijedi (zadano samo BOX NOW). Stranica i e-mail računaju isto.
- **Mjere i opisi** (Postavke): jedna mjera po retku, „mjera | opis”, npr. `26 × 7 cm | Stane mobitel, novčanik i ključevi.`. Ispod: tekst za sve torbe (npr. duljina remena). U obrascu torbe mjeru birate klikom na kućicu; „druga” otvara slobodan upis. Kupac u povećanom prikazu vidi blok „Veličina i nošenje”.
- **Podrubrika za Ostalo** (obrazac artikla): remeni, more, kozmetičke torbice, mini novčanici, platnene torbe. Na stranici se Ostalo prikazuje po podrubrikama.
- **Granice po narudžbi**: 5 torbi, 10 artikala, od toga 2 unikata. Kupac poruku vidi odmah u košarici.
- Rokovi se provjeravaju kad netko otvori stranicu ili admin (najviše jednom u 5 minuta).

## v69: „Uplatio/la sam”, storno s e-mailom, ista veza
- **„Kupac javio uplatu”**: kupac je u e-mailu kliknuo „Uplatio/la sam”. Rok mu je jednom produžen (v70: do 23:59 sljedećeg radnog dana), a vi ste dobili e-mail. Provjerite izvod banke (poziv na broj) i kliknite **Označi plaćenom**.
- **Storniraj narudžbu…**: otvara izbor razloga i kvačicu **„Pošalji kupcu e-mail o stornu”** (zadano uključena). Za razlog „lažna narudžba” e-mail se ne šalje.
- **Automatski storno**: dobivate jedan sažetak e-mailom. Ako ipak vidite uplatu, **Označi plaćenom** poništava automatski storno (ako je torba još slobodna, ostaje kupcu).
- **Ukloni oznaku** na plaćenoj narudžbi daje joj novi rok, ali je ne stornira automatski: nakon roka je pod „Rok istekao” i odlučujete sami.
- Narudžbe primljene **prije v69** rokovi ne diraju (nema podsjetnika ni automatskog storna); riješite ih ručno.
- **Ista internetska veza**: najviše 3 neplaćene narudžbe; četvrtu kupac ne može poslati, a vi dobivate e-mail s njegovim podacima. **Storniraj sve s ove adrese** (Rezervacije) stornira i narudžbe samo iz Stalne kolekcije s te veze.
- Kvačica **„Narudžba vrijedi tek kad je kupac potvrdi…”** ostaje kakva jest i kad spremite Postavke iz stare, dugo otvorene kartice.

## v70: „Potvrda primljena”
- Kupac vam je poslao **snimku potvrde o uplati** (ili proslijedio potvrdu iz banke), a uplata još nije na izvodu? U Narudžbama, u retku narudžbe ili na kartici **Rok istekao**, kliknite **Potvrda primljena**. Prije klika piše točan datum do kojeg se torba čuva (23:59 sljedećeg radnog dana; vikend i blagdani se preskaču).
- Kvačica **„Pošalji kupcu kratku potvrdu e-mailom”** je zadano uključena.
- Gumb radi najviše **dvaput** i najkasnije **7 dana** od narudžbe. Ako je torba u međuvremenu otišla drugom kupcu, admin to javi (kupcu vraćate iznos za nju).
- Kad uplata stigne na izvod: **Označi plaćenom**, kao i prije.

