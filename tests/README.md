# Testovi

Svi testovi rade nad zasebnom probnom stranicom, složenom iz repozitorija i ispitnih podataka u `tests/fixtures/`
(izmišljene torbe i cijene, `mail_mode: log`, bez lozinke, `require_confirm: false` — staro ponašanje; potvrdu uključuju
`confirm.js` i dio `e2e.js`). Prava mapa `data/` se ne dira.

| Naredba | Što provjerava | Treba |
|---|---|---|
| `php tests/run.php` | biblioteka `admin/lib.php`: rezervacije, „Plaćeno”, „Isteklo”, siguran zapis JSON-a, oštećene datoteke, ograničenje zahtjeva, izmjene proizvoda pod bravom, linkovi u e-mailu, prijava | PHP 8.1+ |
| `php tests/admin.php` | admin preko pravog poslužitelja (port 8091) i prijave: „Plaćeno” za cijelu narudžbu, torba bez rezervacije pripada onome tko je prvi platio, upozorenja u Rezervacijama | PHP 8.1+, `curl` |
| `sh tests/serve.sh` | složi probnu stranicu u `/tmp/shivaj-test` i pokrene poslužitelj na http://127.0.0.1:8090/ | PHP 8.1+ |
| `node tests/api.js` | API preko HTTP-a: narudžba, ključ narudžbe, rezervacije i ključ rezervacije, 409/429/500, e-mailovi | Node 18+, pokrenut `serve.sh` |
| `node tests/confirm.js` | potvrda narudžbe e-mailom (PHP): nepotvrđena ne rezervira, link, stara/kriva tajna, istek, ista adresa, granica mailova, `require_confirm: false` | Node 18+, pokrenut `serve.sh` |
| `node tests/gas.js` | probni poslužitelj `proba/apps-script/Code.gs` u Nodeu (lažna tablica i pošta): narudžba, potvrda, granica pola ponude, „Plaćeno” iz tablice | Node 18+ |
| `node tests/proba.js` | probna stranica (`proba/build.py`) u pregledniku s lažnim Apps Scriptom: traka, noindex, text/plain, `_status`, potvrda linkom | Node 18+, Playwright, Python 3 |
| `node tests/objava.js [proizvodi.json]` | **prije objave, ručno** (ne u CI-ju): stranica bez vidljivih `{OZNAKA}` (npr. `{NAPOMENA O PDV-u}`), proizvodi bez djelomičnih oznaka; upozorenja za torbe bez mjera i unikate bez fotografije. Pada dok vlasnica ne upiše podatke | Node 18+ |
| `python3 tests/paket.py` | paket za prijenos (`tools/paket.py`): nadogradnja bez `data/`, `img/` i `torbe.json`; prvi paket s praznom ponudom i prvom prijavom; bez uputa i testova | Python 3 |
| `node tests/pdf417.js` | barkod za e-mail (`admin/pdf417.php`): isti tekst naloga kao stranica, matrica se dekodira (indikatori, Reed–Solomon, UTF-8), PNG nastaje | Node 18+, PHP s GD |
| `node tests/e2e.js` | stranica u pregledniku (Playwright): prvi ekran, košarica, narudžba, ploča za uplatu, HUB-3 barkod, prekid veze, mobitel 360 i 390 px | Node 18+, Playwright s Chromiumom, **svježe** pokrenut `serve.sh` |

`api.js` i `e2e.js` mijenjaju podatke probne stranice, pa prije svakog pokretanja ponovno pokrenite `serve.sh`
(on stranicu složi iznova). Varijable: `SHIVAJ_SITE` (mapa probne stranice), `SHIVAJ_URL`, `SHIVAJ_SHOTS` (snimke
iz `e2e.js`), `CHROMIUM` (putanja do preglednika). Svaka skripta na kraju ispiše „SVE PROŠLO” ili broj neuspjelih
i vrati izlazni kod 0 ili 1.
