# Shiva.J — rezervacije u stvarnom vremenu · upute v03

Datum: 2026-09-06 · vrijedi uz `shivaj_webshop_v12.html` i `shivaj_worker_v03.js` · v03: rezervacija traje 24 sata, gumb „Plaćeno“

## Što se dobiva

- Kad kupac pošalje narudžbu, torba je u istom trenutku označena „Rezervirano“ za sve posjetitelje. Nitko je drugi ne može staviti u košaricu ni naručiti.
- Rezervacija traje **24 sata** (`TTL_HOURS` u workeru). Ako se u tom roku ništa ne napravi, torba je automatski opet u prodaji, kao u drugim webshopovima.
- Ako dvoje kupaca pošalje narudžbu za istu torbu gotovo istodobno, drugi dobiva poruku „Nažalost, upravo je rezervirano“ i torba mu se makne iz košarice.
- E-mail narudžbe (Formspree) sadrži link s dva gumba: **Plaćeno** (torba ostaje prodana i nakon isteka rezervacije) i **Storniraj** (torba odmah opet u prodaji).
- Admin stranica prikazuje rezervacije i torbe označene kao plaćene, s istim gumbima, plus ručno označavanje prodane torbe po oznaci.
- Stranica sama preuzima broj sati iz workera i prikazuje ga u tekstovima („rezervirana 24 sata“), pa se rok mijenja na jednom mjestu.

## Zašto gumb „Plaćeno“

Uplata na račun banci stiže tek idući radni dan, a preko vikenda i kasnije. Rezervacija od 24 sata istekla bi prije nego što vlasnica vidi novac. Zato kupac odmah nakon uplate šalje potvrdu uplate (snimku zaslona), a vlasnica klikne „Plaćeno“: torba od tog trena piše „Prodano“ sve dok se u stranici ne stavi `sold: true`. Ako uplata ipak ne stigne, gumb „Ukloni oznaku“ vraća torbu u prodaju.

## Postavljanje (jednom, oko 15 minuta)

Nazivi u Cloudflareovu sučelju s vremenom se malo mijenjaju, ali redoslijed je isti.

1. Otvorite https://dash.cloudflare.com i napravite besplatan račun.
2. Lijevi izbornik: **Workers & Pages → Create → Create Worker** (ponekad „Start with Hello World“). Naziv: `shivaj-rezervacije`. Kliknite **Deploy**.
3. Kliknite **Edit code**, obrišite sav ponuđeni kod, zalijepite cijeli sadržaj datoteke `shivaj_worker_v03.js` i kliknite **Deploy**.
4. Lijevi izbornik: **Storage & Databases → KV → Create namespace**. Naziv: `shivaj-rezervacije`.
5. Natrag u Worker: **Settings → Bindings → Add → KV namespace**. Variable name: `RESERVATIONS` (točno tako, velikim slovima). Namespace: `shivaj-rezervacije`. Spremite.
6. **Settings → Variables and Secrets → Add**. Type: **Secret**. Name: `ADMIN_KEY`. Value: dugačka nasumična lozinka, barem 20 znakova. Zapišite je na sigurno. Spremite i po potrebi ponovno **Deploy**.
7. Adresa workera piše na njegovoj stranici, npr. `https://shivaj-rezervacije.ime-racuna.workers.dev`. Otvorite je u pregledniku: mora pisati „Shiva.J — rezervacije rade.“ Dodajte `/status` na kraj adrese: mora se vidjeti `{"ok":true,"ttlHours":24,"ids":[]...}`.
8. U `shivaj_webshop_vNN.html`, na vrhu skripte, upišite adresu bez kose crte na kraju:

   ```js
   const RESERVATION_ENDPOINT = "https://shivaj-rezervacije.ime-racuna.workers.dev";
   ```

   Objavite stranicu.
9. Ako stranica ne živi na `https://exarkun-1.github.io` (npr. vlastita domena), dodajte njezinu adresu u `ALLOWED_ORIGINS` na vrhu workera i ponovno **Deploy**.

## Provjera

1. Otvorite stranicu u dva različita preglednika, ili jedan u anonimnom prozoru.
2. U prvom naručite torbu. Nakon slanja vidi se panel s podacima za uplatu i rokom rezervacije (datum i sat).
3. U drugom osvježite stranicu: torba mora biti „Rezervirano“ i ne može se dodati u košaricu.
4. Otvorite link iz e-maila narudžbe i kliknite „Plaćeno“. U drugom pregledniku osvježite: torba je „Prodano“.
5. U adminu kliknite „Ukloni oznaku“. Osvježite: torba je opet dostupna.

## Svakodnevno korištenje

- **Admin:** `https://…workers.dev/admin?key=VAŠ_ADMIN_KEY`. Spremite u oznake. Ne dijelite, ključ je u adresi.
- **Narudžba stigla:** poslati potvrdu iz Gmail predloška (šablona v03).
- **Kupac poslao potvrdu uplate ili je uplata sjela:** link iz e-maila narudžbe → **Plaćeno**. Kad stignete, u PRODUCTS staviti `sold: true`; oznaku u adminu po želji ukloniti.
- **Kupac odustao:** link iz e-maila → **Storniraj**. Ili ništa: rezervacija sama istekne nakon 24 sata.
- **Uplata stigla nakon isteka rezervacije, a torbu nitko drugi nije naručio:** admin → „Označi prodano ručno“ → upisati oznaku torbe (npr. luna) → Prodano.
- **Promjena roka:** `TTL_HOURS` u workeru (npr. 12 ili 48) i Deploy. Stranica sama prikazuje novi broj sati; u šabloni e-maila promijenite ga ručno.

## Ograničenja, sigurnost, trošak

- Cloudflare besplatni plan: 100.000 zahtjeva dnevno i 1.000 KV upisa dnevno. Višestruko više od potrebnog, bez kartice.
- Ako je worker nedostupan, stranica radi kao prije: narudžba prolazi, ali bez blokade za druge.
- KV je „eventualno konzistentan“: u iznimnim slučajevima promjena je u drugim dijelovima svijeta vidljiva s odgodom do 60 sekundi. Za ovu trgovinu zanemarivo.
- Zlonamjeran posjetitelj mogao bi lažnim narudžbama rezervirati sve torbe. Rezervacije se vide u adminu, storniraju jednim klikom i same istječu nakon 24 sata. Ako se to dogodi, javiti Zlatku.
- Osobni podaci (ime, e-mail) čuvaju se u KV-u dok traje rezervacija, odnosno dok traje oznaka „plaćeno“; Cloudflare je naveden u politici privatnosti na stranici (certificiran u okviru EU–U.S. Data Privacy Frameworka).

## Datoteke

- `shivaj_worker_v03.js` — kod koji se zalijepi u Cloudflare
- `shivaj_webshop_v12.html` — stranica s konstantom `RESERVATION_ENDPOINT`
- `shivaj_sablona_potvrda_narudzbe_v03.md` — e-mail kupcu s rokom od 24 sata
