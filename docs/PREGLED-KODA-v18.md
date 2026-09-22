# Pregled koda v18: greške, sigurnost, mrtvi kod (22. 9. 2026.)

> Stanje 22. 9. 2026.: odjeljak 1 (sigurnost) riješen u v19 stranice, api v02 i admin v02 (S1–S9). Odjeljci 2–4 čekaju sljedeći krug.


Korisnik traži analizu stranice shiva.j (index.html v18, admin/, api/) na greške i mrtvi kod.
Nalazi dolje su provjereni čitanjem koda (vlastito čitanje + dva agenta, JS i PHP; `php -l`
prolazi na svim PHP datotekama). Popravci se rade tek nakon odobrenja, kao v19 stranice i
v02 admina/API-ja, na novoj grani s `main`, s pull requestom.

Ukupno: 9 sigurnosnih, 20 funkcionalnih grešaka, 15 stavki mrtvog koda, 12 nedosljednosti
teksta i dokumentacije. Ništa od toga ne ruši stranicu danas (ponuda je prazna, servis nije
objavljen), ali sigurnosne stavke treba riješiti prije objave.

---

## 1. Sigurnost (riješiti prije objave)

| # | Gdje | Što | Popravak |
|---|---|---|---|
| S1 | `api/index.php:83`, `index.html:1270,1479,1509` | Tajni storno token (gumbi Plaćeno/Storniraj bez prijave) vraća se u preglednik kupca i u mailto rezervi ulazi u poruku koju kupac sam šalje. Kupac može torbu označiti plaćenom bez uplate ili osloboditi tuđu rezervaciju. | Radnje `sold`/`unsold` na `api/storno` zahtijevaju admin sesiju (vlasnica klikne link iz e-maila i prijavi se); `release` ostaje tokenom. Storno link ne vraćati klijentu ni u mailto tijelo; servis ga sam stavlja u e-mail vlasnici. |
| S2 | `api/index.php:96,123`, `admin/lib.php:239` | Reply-To u e-mailu vlasnici je kupčev e-mail bez provjere; `sj_clean` ne uklanja CR/LF, pa je moguće ubaciti `Bcc:` zaglavlje (spam relay). | Reply-To samo uz `FILTER_VALIDATE_EMAIL`; u `sj_send_mail` iz svih zaglavlja ukloniti `[\r\n]`. |
| S3 | `api/index.php:94-110,189-218` | Nazivi, cijene, količine i ukupni iznos u „službenoj” potvrdi kupcu dolaze iz preglednika. Netko može sam sebi poslati potvrdu s krivom cijenom, a Uvjeti kažu da je ugovor sklopljen primitkom potvrde. | Servis preračuna stavke i iznos iz `data/proizvodi.json` po id-u + cijena dostave iz popisa; odbije narudžbu za nepostojeći/prodani/skriveni proizvod. |
| S4 | `api/index.php:56-84,87-133` | `reserve` ne provjerava postoji li proizvod, je li vidljiv, unikat ili prodan; `order` nema ograničenje broja zahtjeva ni veličine `_order` (sprema se cijeli, do `post_max_size`, 300 puta). Bilo tko može puniti rezervacije lažnim id-jevima, slati „Shiva.J” e-mailove na tuđe adrese i puniti disk. | Rezervirati samo vidljive, ne-`made`, neprodane proizvode; ograničiti zahtjeve po IP-u (npr. 10/h u `data/`); spremati samo očekivana polja `_order` s granicama duljine. |
| S5 | `admin/index.php:16-35`, `admin/lib.php:82-86` | Prvi tko otvori `/admin/` postavlja lozinku; isto vrijedi kad god `config.json` nedostaje ili je neispravan (hash prazan). | Postavljanje lozinke dopustiti samo uz jednokratnu datoteku `data/setup.txt` koju vlasnica stavi FTP-om (ili token iz uputa); neispravan `config.json` → greška, ne novi setup. |
| S6 | `index.html:1539→1298→1336` vs `:667,676` | BOX NOW skripta (`widget-cdn.boxnow.hr`) učitava se svakom posjetitelju odmah pri otvaranju, jer je paketomat zadana dostava. Politika privatnosti tvrdi „tek na vaš klik”. | Skriptu učitati tek na klik `#lockerMapBtn` (pa programski ponoviti klik). |
| S7 | `admin/index.php:238,278` | `e()` pretvara `'` u `&#039;`, što preglednik u atributu vrati u `'` → naziv s apostrofom razbija `confirm()` (brisanje bez potvrde) i omogućuje ubacivanje JS-a u admin. | `confirm(' . e(json_encode($confirm, JSON_HEX_APOS\|JSON_HEX_QUOT)) . ')`. |
| S8 | `index.html:1002-1016,1065,1092,1186` | Polja iz `torbe.json` (`name`, `desc`, `lead`, `imgs`) idu u `innerHTML` bez escapea; `desc` koji nedostaje ispiše „undefined”. | Sve interpolacije kroz postojeći `escHtml()`; `p.desc \|\| ""`. |
| S9 | `.htaccess`, `.gitignore`, `ODLUKE-I-PODACI.md:22,172` | Ništa ne brani `docs/`, `*.md`, `torbe.primjer.json`, `.git/` ako se repozitorij prenese cijeli; `torbe.json` (`[]`) je u gitu pa svaki prijenos iz gita isprazni živu ponudu; u dokumentu je testna lozinka. | U `.htaccess` `RedirectMatch 404` za `\.git`, `docs/`, `\.md$`, `torbe\.primjer\.json`; `torbe.json` maknuti iz gita (ostaviti `torbe.json.example`); lozinku iz dokumenta ukloniti. |

## 2. Funkcionalne greške

| # | Gdje | Što | Popravak |
|---|---|---|---|
| F1 | `admin/index.php:345` + `:155-165` | „Trajno prodano (torba)” zove `toggle`: ako je torba već prodana, vraća je u prodaju; ne briše oznaku „plaćeno”. | Nova radnja `set_sold` (postavi `sold=true`, ukloni `$d['sold'][$id]`). |
| F2 | `admin/index.php:134-139` | Brisanje proizvoda ostavlja zapise u `rezervacije.json`; novi proizvod istog imena dobije isti id i odmah je „Prodano/Rezervirano”. Javlja „Obrisano” i za nepostojeći id. | Pri brisanju, pod bravom, ukloniti `res`/`sold` za taj id; provjeriti da id postoji. |
| F3 | `admin/lib.php:140-146` vs `sj_clean(…,40)` svuda | `sj_slug` nema granicu (naziv od 80 znakova → id od 77), a sve radnje režu id na 40: uređivanje pravi duplikat, brisanje/redoslijed ne rade, rezervacija ide na skraćeni id pa se torba može prodati dvaput. | `sj_slug` ograničiti na 40 znakova. |
| F4 | `api/index.php:52,61-62`, `index.html:1228` | Numerički id (naziv „2024”) PHP vrati kao broj; `Set.has("2024")` je lažno → rezervacija/prodano se ne prikažu. | Slug koji počinje znamenkom dobiva prefiks `t-`; u `status` `array_map('strval', …)`; u JS `map(String)`. |
| F5 | `api/index.php:63` vs `index.html:1260` | Više od 6 torbi u košarici → `reserve` vrati 400, JS to proguta (`rez=null`), narudžba prođe bez rezervacije, a stranica i e-mail tvrde „rezervirano”. | Istu granicu u JS-u (ili je ukloniti) i tekst vezati uz stvarnu rezervaciju (F8). |
| F6 | `index.html:1486,1521` | Mailto rezerva bez rezervacije (`rez` null: servis ne radi, samo artikli „ostalo”) ne prikaže panel s podacima za uplatu; košarica ostane puna. | U obje rezervne grane uvijek `showOrderSuccess(rez, true)`. |
| F7 | `index.html:1223-1237` | Utrka: `refreshReservations` provjerava `submitting` samo prije `await`; odgovor `/status` koji stigne nakon vlastitog `reserve` izbaci kupčevu torbu iz košarice usred slanja. | Nakon `await` ponovno `if(submitting) return;`; raditi sa snimkom košarice od početka submita. |
| F8 | `index.html:1433`, `api/index.php:214` | „Torba je rezervirana za vas” piše i kad rezervacije nema (`rez`/`$res` null, samo „ostalo”). | Tekst samo uz `rez && rez.until` / `$res`; za „ostalo” „izrađujemo po narudžbi”. |
| F9 | `api/index.php:141-153` | Storno: id-jevi se biraju izvan brave, token se u bravi ne provjerava → nakon isteka može djelovati na tuđu novu rezervaciju. | U bravi raditi samo nad zapisima čiji `token === $token`. |
| F10 | `admin/index.php:87-130`, `admin/lib.php:119-138` | Spremanje proizvoda bez brave (dvije kartice admina gube promjene); `sj_products()` piše `proizvodi.json` izvan brave; neispravan `proizvodi.json` tiho se zamijeni javnim `torbe.json` (gube se skriveni). | Promjene proizvoda kroz `sj_with_lock(sj_products_path(), …)`; uvoz samo kad datoteke nema. |
| F11 | `admin/lib.php:88-94` | `json_encode` može vratiti `false` → zapiše se `"\n"` i podaci nestanu; neuspjeli zapis se svejedno preimenuje preko dobre datoteke. | `JSON_THROW_ON_ERROR`, provjera zapisanih bajtova, `rename` samo uz uspjeh, jedinstveno ime `.tmp`. |
| F12 | `admin/index.php:117-119` | Fotografija veća od `upload_max_filesize` tiho se preskoči uz „Spremljeno”; POST veći od `post_max_size` daje krivu poruku „Sesija je istekla”. | Provjeriti `$_FILES[…]['error']`, javiti granicu; prazan `$_POST` uz `CONTENT_LENGTH>0` → poruka o prevelikom prijenosu. |
| F13 | `admin/lib.php:280-301` | Nakon `imagerotate` stari GD resurs se ne oslobađa; zrcalne EXIF orijentacije (2,4,5,7) se ignoriraju; `imagejpeg` bez provjere rezultata; utrka pri odabiru imena datoteke. | `imagedestroy` starog; obraditi svih 8 orijentacija; `fopen($file,'x')`. |
| F14 | `admin/index.php:91-92` | Cijena 0 ili tekst prolazi kao 0 € (torba se može naručiti besplatno); „1.234,50” se čita kao 1.234. | Zahtijevati broj > 0; normalizirati tisućice. |
| F15 | `api/index.php:122` | Predmet e-maila vlasnici: „Narudžba SJ-… — Narudžba — Luna” (JS šalje `_subject` koji već počinje s „Narudžba”). | Predmet iz `_order` (ime kupca + nazivi). |
| F16 | `api/index.php:100-105` | Broj narudžbe: brojač se ne vraća s novom godinom; nigdje se ne postavlja vremenska zona (CLI je UTC → narudžbe 00–01 h 1. siječnja dobiju staru godinu); brojač raste i za spam. | Spremiti `order_year`, resetirati; `date_default_timezone_set('Europe/Zagreb')` u lib.php. |
| F17 | `admin/lib.php:197-213` | Brojač neuspjelih prijava: čita se izvan brave (paralelni pokušaji zaobilaze 8), nikad se ne čisti (raste s IP-ovima), za vrijeme blokade i točna lozinka javlja „Pogrešna lozinka”. | Uvećavati unutar `sj_config_update`; brisati zapise starije od 15 min; posebna poruka za blokadu. |
| F18 | `admin/lib.php:186`, `admin/index.php:39` | Kolačić sesije nije `secure` iza proxyja (ne gleda `X-Forwarded-Proto` kao `sj_site_url`); odjava GET-om (CSRF) i preusmjerava na `./` što je s `/admin` bez kose crte naslovnica. | Zajednička HTTPS provjera; odjava POST-om s CSRF-om; `Location: /admin/`. |
| F19 | `index.html:1262,1494` | `reserve` i `order` nemaju timeout; gumb može zauvijek ostati „Šaljem…”. | `AbortController` 15 s. |
| F20 | `index.html:1514` | Odgovor `api/order` se ne čita: `orderNo` se nikad ne prikaže kupcu, a panel obećava e-mail i kad `mailCustomer === false`. | Prikazati broj narudžbe u panelu; drukčija napomena kad e-mail nije poslan. |
| F21 | `index.html:1175,1281,1351,1301,1240,1022,941` | Sitno: brojač košarice broji retke, ne komade; `closest("div")` vraća sam `#shipOptions`; bez opcija dostave piše „osobno preuzimanje”; promjena dostave regenerira radio gumbe (gubi fokus); `lbTag` se ne osvježava; gramatika „2 komada dostupno”; nema ponovnog pokušaja učitavanja `torbe.json` nakon 4 s. | Redom: `reduce(qtyOf)`; `parentElement`; „po dogovoru”; samo klasa `.on`; postaviti `lbTag`; deklinacija; jedan ponovni pokušaj. |
| F22 | `api/index.php:203-205`, `admin/index.php:356-363` | E-mail: „Dostava: po dogovoru — besplatno”; admin Narudžbe prikazuje 100 od 300 i bez telefona, adrese, paketomata i napomene iako se spremaju. | Bez cijene kad nema opcije; prikazati `fields` i svih 300. |

## 3. Mrtvi kod i ostaci ranijih verzija

| # | Gdje | Što |
|---|---|---|
| M1 | `index.html:1407` | `fmtDate()` nikad pozvana. |
| M2 | `index.html:1541` | `dataset.products` nitko ne čita („ugradjeno” više ne postoji). |
| M3 | `index.html:1484-1490,813-827` | Grana `FORMSPREE_ENDPOINT.includes("VAS_KOD_OVDJE")` uvijek lažna; `""` nije obrađen (`fetch("")` POST-a na samu stranicu). Preimenovati u `ORDER_ENDPOINT`, grana `!ORDER_ENDPOINT → mailto`. |
| M4 | `index.html:947` | `data.products` oblik: admin uvijek piše polje. |
| M5 | `api/index.php:92` | Honeypot `_gotcha` nikad ne stiže (stranica provjerava `fWeb` lokalno). Slati `_gotcha` iz JS-a ili ukloniti. |
| M6 | `api/index.php:96-97,108-109` | Formspree ravna polja (`_replyto`, `Kupac`, `Ukupno`, `Naručene torbe`, `Dostava`) dupliraju `_order`. Koristiti samo `_order`. |
| M7 | `api/index.php:20,50-52,83` | `ltrim($a,'/')` ne radi ništa; `until` u `status`, `ids`/`ttlHours` u `reserve`: klijent ne koristi. |
| M8 | `admin/index.php:158,284` | `toggle` polje `reserved` nema gumb; parametar `$c` u `sj_view_form` neiskorišten. |
| M9 | `admin/lib.php:24,31,37` | `shop`, `allowed_origins`, `payment.barcode` nisu u Postavkama (samo ručno u JSON-u). Dodati u obrazac ili dokumentirati. |
| M10 | `index.html:858-863,893-900,936,954,1220,1243,1342,1483,58,825,895,30` | Komentari o Formspreeju, Cloudflare Workeru, „ugrađenom popisu PRODUCTS”, mapi `hosting/`, `shivaj_upute_rezervacije_v01.md` (postoji v03), `shivaj_worker_v01.js`. Prepisati. |
| M11 | `index.html:851` | Ćirilična slova „ту” u riječi „karту” (komentar). |
| M12 | `index.html:205` | `.card.sold .photo{cursor:default}`, a JS otvara lightbox i za prodane. Uskladiti (lightbox i za prodane je korisno → ukloniti CSS). |
| M13 | `images/` | 8,7 MB originala iz v09, ništa ih ne koristi (`images/1784356525545.jpg` = duplikat `tara-1.jpg`). Obrisati iz repozitorija. |
| M14 | Dupli podaci | `PAYMENT` i tekst preuzimanja i e-mail vlasnice tvrdo su u `index.html` (885-891, 844, 1378, 1416, 1443) i odvojeno u `config.json`: promjena IBAN-a u adminu mijenja samo e-mail, ne stranicu. Prijedlog: `api/status` vraća `payment`, `pickup`, `ownerEmail`, JS ih koristi kad postoje. |
| M15 | `api/index.php:153` = `admin/index.php:185` | Isti zapis „plaćeno” gradi se na dva mjesta; dvije različite HTTPS provjere (lib 75 vs 186). Jedan pomoćnik `sj_mark_paid()`, jedna `sj_is_https()`. |

## 4. Nedosljednosti teksta i dokumentacije (prijaviti; pravni tekst mijenja vlasnica)

| # | Gdje | Što |
|---|---|---|
| T1 | `index.html:640` | `{NAPOMENA O PDV-u …}` javno vidljiv nacrt (poznato, čeka knjigovođu). |
| T2 | `index.html:631,643` | Sažetak Uvjeta: „e-mailom vam pošaljemo podatke za uplatu”, „u roku 24 sata potvrdu; ugovor sklopljen kad primite potvrdu”, „na vašu adresu”. Stvarno: podaci odmah, potvrda automatska i trenutna, dostava i paketomat/preuzimanje. Pravno pitanje (uz S3). |
| T3 | `index.html:676,679` vs `api:153`, `admin:185`, `narudzbe.json` | Privatnost obećava brisanje podataka rezervacije po isteku i nerealiziranih narudžbi u 30 dana; „Plaćeno” trajno čuva ime/e-mail, narudžbe se čuvaju bez roka, `login_fails` čuva IP-ove, admin nema brisanje. Dodati automatsko čišćenje (narudžbe > 30 dana bez oznake plaćeno; ime/e-mail iz `sold` kod „Trajno prodano”) ili promijeniti tekst. |
| T4 | `index.html:596,780` | „24 sata rezervirana, nitko je drugi ne može naručiti” i za košaricu samo s artiklima „ostalo”. |
| T5 | `index.html:1366,1503` | „Naručene torbe” i za remene/torbice. |
| T6 | `index.html:95-96` | `og:url`/`og:image` na `shivaj-webshop`, `img/og.jpg` ne postoji (čeka domenu). |
| T7 | `docs/shivaj_upute_rezervacije_v03.md` (cijela) | Opisuje Cloudflare Worker (`TTL_HOURS`, `workers.dev/admin?key=`, KV, Formspree). Zastarjela od v16. Označiti zastarjelom ili prepisati. |
| T8 | `docs/shivaj_upute_objava_croadria_v02.md:55`, `docs/shivaj_upute_admin_v02.md:3,21-27,51,61,63,10`, `docs/shivaj_sablona_potvrda_narudzbe_v04.md` | „Dok admin nije gotov… Cloudflare/Formspree”; putanje `shivaj_webshop_v17.html`, `hosting/`, zip; „Trajno prodano daje trajnu oznaku” (F1); „zaključano 15 min” (F17); „admin samo preko HTTPS-a” (ništa ne preusmjerava); „300 narudžbi” (prikazuje 100); šablona ima „Kurir vas može nazvati na {TELEFON}” koje kod nema. |
| T9 | Nazivi stanja | `sold` u `rezervacije.json` znači „plaćeno”, u proizvodu „trajno prodano”; admin piše „rezervirano (ručno)”, stranica „Rezervirano” za oboje. Preimenovati ključ u `paid`. |
| T10 | `index.html:1443` vs `config.owner_email` | Stranica upućuje potvrdu uplate na Gmail, e-mail kaže „odgovorom na ovaj e-mail” (Reply-To iz postavki). Razilaze se čim se promijeni e-mail na domeni (M14). |
| T11 | `admin/lib.php:3`, `api:3`, `SJ_VERSION` | Verzije „v01” bez veze sa stranicom v18. Kozmetika. |
| T12 | CSS/HTML | Provjereno: nema nekorištenih CSS selektora; svi id-jevi u JS-u postoje u HTML-u; putanje `api/*` odgovaraju `.htaccess` i `router.php`; oblik `torbe.json` iz admina odgovara onome što stranica čita. |

---

## Što napraviti nakon odobrenja (redoslijed)

1. **Grana** `claude/code-review-fixes-v19` s `main`.
2. **api v02 + admin v02** (`api/index.php`, `admin/index.php`, `admin/lib.php`, `.htaccess`, `.gitignore`):
   S1–S5, S7, S9; F1–F5, F9–F18, F22; M5–M9, M15; T3 (čišćenje), T9 (ključ `paid` uz kompatibilno čitanje starog `sold`).
3. **Stranica v19** (`index.html`; po pravilu projekta kopija kao `shivaj_webshop_v19.html` u arhivi radi lokalna sesija, ovdje samo `index.html` s changelogom i „· v19”):
   S1 (mailto), S6, S8; F5–F8, F19–F21; M1–M4, M10–M12, M14 (čitanje `payment` iz `api/status` s tvrdim vrijednostima kao rezervom); T4, T5.
4. **Repozitorij i dokumenti**: obrisati `images/` (M13), `torbe.json` → `torbe.json.example` (S9), lozinku iz `ODLUKE-I-PODACI.md`, `docs/` označiti zastarjele dijelove (T7, T8), ODLUKE-I-PODACI.md dopuniti (T1, T2, T6 kao otvoreno za vlasnicu).
5. **Commit, push, PR** s popisom nalaza i što je popravljeno.

Ne diram: pravni tekst Uvjeta (T1, T2) osim ne-pravnih rečenica (T4, T5); OG oznake (T6).

## Provjera

- `php -l` na sve PHP datoteke; lokalni poslužitelj `php -S 127.0.0.1:8090 router.php` u kontejneru (PHP je dostupan, agent ga je koristio), `mail_mode=log`.
- Skripta s `curl`: narudžba s krivim `total` → potvrda nosi preračunati iznos; e-mail s `\r\nBcc:` → odbijen; `reserve` s lažnim id-om → 400; 7 torbi → jasna greška; storno `sold` bez prijave → 403; prvi setup bez `data/setup.txt` → odbijen.
- Admin: naziv s apostrofom i `<b>` → ispravno prikazano, brisanje traži potvrdu; naziv od 80 znakova → id ≤ 40, uređivanje ne pravi duplikat; „Trajno prodano” na već prodanoj torbi ostaje prodano; cijena 0 odbijena.
- Stranica u headless Chromiumu (Playwright): prazna i primjer `torbe.json`, bez JS grešaka; BOX NOW skripta nije učitana prije klika; narudžba s ugašenim servisom → mailto grana prikazuje panel bez storno linka; brojač košarice s količinama; id „2024” prikazuje „Rezervirano”.
