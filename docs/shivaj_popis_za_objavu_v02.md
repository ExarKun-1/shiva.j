# Shiva.J — popis za objavu · v02

Datum: 2026-09-08 (v02: torbe i cijene unose se u adminu, stranica kreće prazna). Označiti kvačicom kad je riješeno. Redoslijed nije obvezan, ali sve mora biti gotovo prije nego što link ode u Instagram bio.

## Podaci koje daje vlasnica

- [ ] Stvarne torbe unosi vlasnica u adminu, svaku sa svojom cijenom prema modelu: naziv, opis, cijena, dimenzije, materijal, fotografije (uspravne, omjer oko 4:5, bez teksta na slici). Stranica na hostingu kreće prazna (poruka „Ponuda se upravo priprema“) dok se ne unese prva torba; primjeri Luna, Tara, Vela, Mira, Nera, Zora od v17 više nisu u stranici.
- [ ] Artikli iz kategorije Ostalo isto u adminu (kvačica „Artikl po narudžbi“): naziv, opis, cijena, rok izrade, što se može birati (boja, duljina), fotografije.
- [ ] Napomena o PDV-u u Uvjetima kupnje (sada `{NAPOMENA O PDV-u}`): je li obrt u sustavu PDV-a. Pitanje za knjigovođu.
- [ ] Tekst „O nama“ u njezinim riječima (sada opći tekst).
- [ ] Fotografija za dijeljenje linka `img/og.jpg`, 1200×630 px, najbolja torba s logom.
- [ ] Potvrda da je radionica na adresi Matije Gupca 33 i da preuzimanje vrijedi radnim danom 8 do 15 h (potvrđeno 6. 9. 2026.).

## Mjesta u stranici koja još nose vitičaste zagrade

Provjera: otvoriti datoteku u uređivaču teksta i pretražiti znak `{`.

- [ ] `{DIMENZIJE}`, `{MATERIJAL}` kod torbi Luna i Tara i artikala u kategoriji Ostalo
- [ ] `{ROK IZRADE, npr. 5 radnih dana}` kod artikala po narudžbi
- [ ] `{NAPOMENA O PDV-u — ...}` u Uvjetima kupnje, točka 3
- [ ] u šabloni e-maila `{ADRESA STRANICE}` (adresa domene)

## Pravna i porezna pitanja (knjigovođa)

- [ ] Fiskalizacija: od 1. 1. 2026. fiskaliziraju se svi računi u krajnjoj potrošnji bez obzira na način plaćanja, dakle i uplate na račun. Kako obrt izdaje i fiskalizira račun za web narudžbu?
- [ ] PDV status i tekst na računu.
- [ ] Uvjeti kupnje: pročitati kao nacrt, potvrditi rok uplate (24 h) i pravo na raskid.
- [ ] Kartice i KEKS Pay ostaju za budućnost; odluka kad knjigovođa odgovori.

## Tehnički koraci

- [ ] Hosting i domena zakupljeni (upute: objava na Croadriji).
- [ ] `index.html` (v17 ili novija), prazna `torbe.json` (`[]`) i `img/` preneseni; SSL uključen.
- [ ] E-mail na domeni napravljen i upisan u stranicu umjesto Gmail adrese (više mjesta: kontakt, impressum, uvjeti, privatnost, obrazac za raskid, JavaScript za mailto).
- [ ] Rezervacije: Cloudflare worker postavljen (upute v03) ili PHP servis na hostingu; adresa upisana u `RESERVATION_ENDPOINT`.
- [ ] Narudžbe: Formspree kod upisan u `FORMSPREE_ENDPOINT` ili PHP zaprimanje na hostingu.
- [ ] `og:url` i `og:image` zamijenjeni domenom.
- [ ] Politika privatnosti: hosting Croadria umjesto GitHub Pages; provjeriti popis usluga (Formspree, Cloudflare, BOX NOW, GLS, Bunny Fonts).
- [ ] `ALLOWED_ORIGINS` u workeru sadrži domenu (ako se worker koristi).
- [ ] Testna narudžba na živoj stranici do kraja: rezervacija, panel za uplatu s barkodom, e-mail narudžbe, potvrda kupcu iz predloška, Plaćeno, Storniraj.
- [ ] Skeniranje barkoda iz panela i iz e-maila stvarnom bankarskom aplikacijom.
- [ ] Provjera na telefonu: kategorije, karta paketomata, košarica, obrazac.

## Vlasnica zna raditi

- [ ] Probna narudžba: automatska potvrda kupcu stigla s barkodom (šablona v04 služi samo za ručno slanje ako zatreba).
- [ ] Admin (`/admin/`) otvoren, lozinka postavljena i spremljena u upravitelj lozinki.
- [ ] Zna u adminu dodati torbu s fotografijama i cijenom te je označiti prodanom (gumb Prodano).
- [ ] Zna kome se javiti kad nešto ne radi (Zlatko).

## Nakon objave

- [ ] Link u Instagram bio.
- [ ] Prva prava narudžba prati se do kraja, pa se po potrebi doradi tekst potvrde.
- [ ] Mjesečno: sigurnosna kopija `torbe.json` i mape `img`; pregled rezervacija koje vise.
