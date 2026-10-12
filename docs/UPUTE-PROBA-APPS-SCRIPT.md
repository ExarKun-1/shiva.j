# Probna stranica: GitHub Pages + Google Apps Script (v40)

Probna stranica služi da se potvrda narudžbe e-mailom isproba **s pravim mailovima**, prije objave na Croadriji.

- **Stranica** je statična i radi na GitHub Pagesu: `https://exarkun-1.github.io/shiva.j/`.
- **Narudžbe** prima mala skripta u Google tablici (Apps Script). Mailove šalje s Gmaila računa koji ju je postavio,
  npr. shivaj.handmade@gmail.com.
- Torbe, cijene i slike na probi su primjeri iz `torbe.primjer.json`. Na vrhu je crvena traka „PROBNA STRANICA”, a
  stranica je skrivena od tražilica.

Tok narudžbe je isti kao na pravoj stranici:
1. Kupac pošalje narudžbu i dobije e-mail „Potvrdite narudžbu” s linkom.
2. Tek kad klikne link, narudžba dobiva broj, a torba se rezervira.
3. Kupac tada vidi podatke za uplatu, a obje strane dobivaju e-mail.

## 1. Google tablica i skripta (račun shivaj.handmade@gmail.com)
1. Na <https://sheets.new> napravite novu tablicu, nazvanu npr. „Shiva.J proba — narudžbe”.
2. U izborniku odaberite **Proširenja → Apps Script**. Obrišite sve što piše u `Code.gs` i zalijepite cijeli sadržaj
   datoteke `proba/apps-script/Code.gs` iz repozitorija. Spremite (ikona diskete).
3. Lijevo kliknite **Project Settings** (zupčanik) i dolje **Script properties → Add script property**:
   - `SITE_URL` = `https://exarkun-1.github.io/shiva.j` (bez kose crte na kraju)
   - `OWNER_EMAIL` = adresa na koju stižu narudžbe. Ako je ne upišete, koristi se Gmail koji je postavio skriptu.
   - po želji `CONFIRM_MINUTES` (zadano 60) i `TTL_HOURS` (zadano 24)
4. Vratite se na **Editor** (`< >`). Gore u izborniku funkcija odaberite **sjPostavi** i kliknite **Run**.
   - Google traži dozvolu: **Review permissions**, zatim račun, pa **Advanced → Go to … (unsafe)** i **Allow**.
     Upozorenje se pojavljuje jer skriptu niste objavili u Googleovoj trgovini, a ovo je vaša vlastita skripta.
   - U tablici se pojave kartice **Narudžbe** i **Na čekanju**.
5. Gore desno kliknite **Deploy → New deployment**. Kod „Select type” (zupčanik) odaberite **Web app** i postavite:
   - **Execute as:** Me
   - **Who has access:** Anyone

   Zatim **Deploy** i kopirajte **Web app URL**. Završava na `/exec`.

## 2. GitHub (ExarKun-1/shiva.j)
1. **Settings → Pages → Build and deployment → Source:** odaberite **GitHub Actions**.
2. **Settings → Secrets and variables → Actions → Variables → New repository variable**:
   - ime: `PROBA_API_URL`
   - vrijednost: Web app URL iz koraka 1.5
3. **Actions → Probna stranica → Run workflow**. Nakon minute stranica je na `https://exarkun-1.github.io/shiva.j/`.

## 3. Proba
1. Na probnoj stranici naručite torbu sa svojom e-mail adresom.
2. Stiže mail „Shiva.J — potvrdite narudžbu”. Ako ga nema, pogledajte i neželjenu poštu.
3. Kliknite link. Otvaraju se podaci za uplatu s brojem narudžbe, a na `OWNER_EMAIL` stiže „Narudžba SJ-…”.
4. U tablici, na kartici **Narudžbe**, je novi red. Torba je na stranici „Rezervirano” do isteka.
5. Kad stigne uplata, upišite datum u stupac **Plaćeno**. Torba je tada na stranici prodana.

## Kasnije: promjena e-maila
- **Kamo stižu narudžbe:** promijenite `OWNER_EMAIL` u svojstvima skripte. Novo postavljanje nije potrebno.
- **S kojeg računa idu mailovi kupcima:** mailove uvijek šalje račun koji je postavio skriptu. Za drugu adresu
  pošiljatelja ponovite korak 1 na tom računu i u GitHubu zamijenite `PROBA_API_URL`.
- **Nakon izmjene `Code.gs`:** odaberite Deploy → **Manage deployments** → olovka → Version: **New version** →
  Deploy. Adresa ostaje ista.

## Na pravoj stranici (Croadria)
Isto radi PHP, pa Google tablica tamo nije potrebna:
- Potvrda je uključena zadano (`require_confirm: true` u `data/config.json`, rok `confirm_minutes: 60`).
- **Obavezno** upišite **adresu stranice** u admin → Postavke (npr. `https://shivaj.hr`): od nje se gradi link u
  e-mailu. Dok je nema, narudžbe se ne primaju (kupac vidi „pišite nam e-mailom”), a vlasnica dobiva upozorenje
  najviše jednom na sat.
- U nuždi se potvrda isključuje s `"require_confirm": false` u `data/config.json`.

## Ograničenja probe
- Proba ne zna IP adresu kupca. Granice su: najviše 3 nepotvrđene narudžbe i 2 maila za potvrdu na sat po e-mail
  adresi, jedna neplaćena narudžba sa šarenom torbom po adresi i najviše 200 mailova za potvrdu na sat ukupno. Na
  pravoj stranici (PHP) vrijedi i granica od 3 na sat po mrežnoj adresi.
- Gmail dopušta oko 100 poslanih mailova dnevno.
- Nema admina. Narudžbe se vide u tablici, a „Plaćeno” se upisuje ručno.
