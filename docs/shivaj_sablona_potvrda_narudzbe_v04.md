# Shiva.J — šablona e-maila: potvrda narudžbe + podaci za uplatu · v04

Datum: 2026-09-07 · zamjenjuje v03

Što je novo u v04: način dostave (BOX NOW paketomat, GLS na adresu, osobno preuzimanje u radionici), količine i napomene za artikle iz kategorije „Ostalo“ (po narudžbi) i rok izrade.

Polja u vitičastim zagradama {OVAKO} zamijeniti podacima iz e-maila narudžbe. Sve ostalo može ostati kako jest. Retke koji se ne odnose na narudžbu obrisati (npr. paketomat kad je dostava GLS-om). Kupac je iste podatke već vidio na stranici odmah nakon narudžbe; ovaj e-mail je službena potvrda narudžbe i podsjetnik za uplatu.

---

**Predmet:** Shiva.J — potvrda narudžbe · {NAZIV TORBE / ARTIKLA}

**Privitak:** barkod-uplata.png (slika barkoda iz mape `img` uz stranicu)

---

Draga/Dragi {IME},

hvala vam na narudžbi! {TORBA JE REZERVIRANA ZA VAS — kao i sve naše torbe, postoji samo u jednom primjerku, i sada je vaša. / ARTIKLE IZRAĐUJEMO PO NARUDŽBI, posebno za vas.}

**Sažetak narudžbe**

- {ARTIKL} — {CIJENA} €
- {ARTIKL} × {KOM} ({BOJA, DULJINA ILI NAPOMENA}) — {CIJENA UKUPNO ZA TAJ ARTIKL} €
- Dostava: {BOX NOW paketomat / GLS dostava na adresu / Osobno preuzimanje u radionici} — {IZNOS DOSTAVE} €
- **Ukupno za uplatu: {UKUPNO} €**

{Samo za paketomat:} Paketomat: {NAZIV I ADRESA PAKETOMATA}. Kod za preuzimanje dobit ćete SMS-om ili e-mailom od BOX NOW-a kad paket stigne.
{Samo za GLS:} Adresa za dostavu: {ADRESA IZ NARUDŽBE}. Kurir vas može nazvati na {TELEFON}.
{Samo za preuzimanje:} Preuzimanje u radionici: Matije Gupca 33, Zabok, radnim danom od 8 do 15 sati. Nakon uplate javite nam se za termin.

**Podaci za uplatu**

- Primatelj: SHIVA. J, obrt za dizajn, Matije Gupca 33, 49210 Zabok
- IBAN: HR98 2360 0001 1027 9044 2
- Iznos: {UKUPNO} €
- Model: HR00
- Poziv na broj: datum vaše uplate u obliku DDMMGGGG (npr. za 7. 9. 2026. upišite 07092026)
- Opis plaćanja: Shiva.J — {NAZIV TORBE / ARTIKLA}

Najbrže: u aplikaciji svoje banke skenirajte barkod iz privitka. Primatelj i IBAN popune se sami, a vi upišete još iznos, model HR00 i poziv na broj (datum uplate).

**Rezervacija vrijedi 24 sata**, do {DATUM I VRIJEME ISTEKA}. Molimo vas da uplatu izvršite odmah i da nam čim uplatite pošaljete potvrdu uplate (snimku zaslona) odgovorom na ovaj e-mail — torbu tada odmah označavamo kao vašu, bez obzira na to kad banka proknjiži uplatu. Ako u tom roku ne primimo uplatu ni potvrdu, rezervacija automatski istječe i torba se ponovno nudi drugim kupcima.

{Samo za artikle po narudžbi:} Rok izrade je {ROK IZRADE} od primitka uplate. Artikli izrađeni po vašim posebnim željama (boja, duljina, mjera) izrađuju se samo za vas, pa se na njih ne odnosi pravo na jednostrani raskid ugovora.

Čim uplata bude vidljiva, {torbu pažljivo pakiramo i šaljemo / krećemo s izradom, a po završetku artikl pakiramo i šaljemo} na adresu:

{ADRESA IZ NARUDŽBE / PAKETOMAT / preuzimanje u radionici}

Poslat ćemo vam poruku s potvrdom slanja.

Ova poruka je potvrda vaše narudžbe. Uvjeti kupnje i pravo na jednostrani raskid u roku 14 dana nalaze se na našoj stranici: {ADRESA STRANICE}#uvjeti

Za bilo kakva pitanja slobodno odgovorite na ovaj e-mail ili nam se javite porukom na Instagram @shiva.j_handmade.

Srdačan pozdrav,
Shiva.J
Unikatne ručno rađene torbe

---

## Kako koristiti (po narudžbi, manje od minute)

1. E-mail narudžbe sadrži sve što treba: ime, e-mail, telefon, artikle s količinama i napomenama, način dostave (i paketomat), iznos, rok rezervacije i link „Plaćeno / storno“.
2. Nova poruka → ⋮ → Predlošci → „Potvrda + uplata“ → zamijeniti {POLJA}, obrisati retke koji ne vrijede → priložiti `barkod-uplata.png` → poslati na e-mail kupca.
3. Kad kupac pošalje potvrdu uplate ili kad uplata sjedne: link iz e-maila narudžbe → **Plaćeno**. Unikatna torba ostaje „Prodano“ i nakon isteka rezervacije. Za artikle po narudžbi nema rezervacije, samo krenite s izradom.
4. Kad stignete: torbu označiti prodanom u adminu (dok admina nema: `sold: true` u torbe.json).
5. Ako kupac odustane: isti link → **Storniraj**. Torba je odmah opet u prodaji.

## Predložak u Gmailu (jednokratno)

1. Gmail → zupčanik ⚙ → Prikaži sve postavke → kartica **Napredno** → uključiti **Predlošci** → Spremi.
2. Nova poruka, zalijepiti gornji tekst od „Draga/Dragi“ do potpisa.
3. ⋮ u dnu poruke → Predlošci → **Spremi skicu kao predložak** → nazvati „Potvrda + uplata“.
4. Gmail predlošci ne pamte privitke, pa `barkod-uplata.png` treba priložiti pri svakom slanju. Ako sliku zalijepite u tijelo poruke (Ctrl+V), trebala bi ostati u predlošku; provjerite jednom nakon spremanja.
