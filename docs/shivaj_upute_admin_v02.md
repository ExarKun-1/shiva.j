# Shiva.J — admin na hostingu · upute v02

Datum: 2026-09-08 · vrijedi uz `shivaj_webshop_v17.html` i mapu `hosting/` (admin v01)

## Što je to

Admin je dio stranice na istom hostingu (Croadria, Linux paket) kroz koji vlasnica bez dodirivanja koda:
- dodaje, uređuje i skriva torbe i artikle te im prenosi fotografije s telefona (smanjuju se same),
- vidi rezervacije i klikom označava „Plaćeno“, „Storniraj“ ili „Trajno prodano“,
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

**Artikl po narudžbi** (remeni, torbice, platnene torbe): kategorija Ostalo + kvačica „Artikl po narudžbi“ + rok izrade. Kupac tada bira količinu i upisuje boju ili duljinu.

**Fotografije**: uspravne, omjer oko 4:5, mirna pozadina. Prva je naslovna; naslovnu se mijenja u „Uredi“. Prenose se s telefona ravno iz galerije, smanjuju se na 1400 px same.

**Narudžba je stigla** (e-mail + Narudžbe u adminu): kupac je već dobio automatsku potvrdu s IBAN-om, iznosom i barkodom. Ništa ne treba slati ručno, osim ako želite dodati osobnu poruku.

**Kupac poslao potvrdu uplate ili uplata sjela**: Rezervacije → **Plaćeno** (ili link iz e-maila narudžbe). Torba ostaje „Prodano“ i nakon isteka rezervacije. Kad stignete: **Trajno prodano (torba)**, čime torba dobiva trajnu oznaku, a rezervacija se može ukloniti.

**Kupac odustao**: Rezervacije → **Storniraj**. Ili ništa: rezervacija sama istekne.

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
