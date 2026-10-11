# Testovi

Svi testovi rade nad zasebnom probnom stranicom, složenom iz repozitorija i ispitnih podataka u `tests/fixtures/`
(izmišljene torbe i cijene, `mail_mode: log`, bez lozinke). Prava mapa `data/` se ne dira.

| Naredba | Što provjerava | Treba |
|---|---|---|
| `php tests/run.php` | biblioteka `admin/lib.php`: rezervacije, „Plaćeno”, „Isteklo”, siguran zapis JSON-a, oštećene datoteke, ograničenje zahtjeva, izmjene proizvoda pod bravom, linkovi u e-mailu, prijava | PHP 8.1+ |
| `sh tests/serve.sh` | složi probnu stranicu u `/tmp/shivaj-test` i pokrene poslužitelj na http://127.0.0.1:8090/ | PHP 8.1+ |
| `node tests/api.js` | API preko HTTP-a: narudžba, ključ narudžbe, rezervacije i ključ rezervacije, 409/429/500, e-mailovi | Node 18+, pokrenut `serve.sh` |
| `node tests/e2e.js` | stranica u pregledniku (Playwright): prvi ekran, košarica, narudžba, ploča za uplatu, HUB-3 barkod, prekid veze, mobitel 360 i 390 px | Node 18+, Playwright s Chromiumom, **svježe** pokrenut `serve.sh` |

`api.js` i `e2e.js` mijenjaju podatke probne stranice, pa prije svakog pokretanja ponovno pokrenite `serve.sh`
(on stranicu složi iznova). Varijable: `SHIVAJ_SITE` (mapa probne stranice), `SHIVAJ_URL`, `SHIVAJ_SHOTS` (snimke
iz `e2e.js`), `CHROMIUM` (putanja do preglednika). Svaka skripta na kraju ispiše „SVE PROŠLO” ili broj neuspjelih
i vrati izlazni kod 0 ili 1.
