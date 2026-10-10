# Mjerenje: kako čitati brojeve u adminu (Mjerenje) i što odlučiti

Stanje 10. 10. 2026. (v28, api v08, admin v08). Brojač je anoniman: samo zbrojevi po danu, bez imena, e-maila i IP adrese. Ograničenje zahtjeva po adresi štiti ga od napuhavanja (30 posjeta i 60 događaja na sat).

## Što se broji

| Ključ u adminu | Što znači | Čemu služi |
|---|---|---|
| Posjeti | jedno otvaranje stranice | nazivnik za A |
| s Instagrama | posjeti preko linka `?izvor=ig` (Instagram bio) | koliko promet dolazi s Instagrama |
| Narudžbe | poslane narudžbe (jedna narudžba = 1, bez obzira na broj torbi) | brojnik za A, nazivnik za B |
| Plaćeno | narudžbe označene „Plaćeno” (kod rezervacija ili u popisu narudžbi) | brojnik za B |
| Isteklo neplaćeno | narudžbe s unikatom kojima je rezervacija istekla bez uplate (ako kupac uplati naknadno, narudžba ulazi i u „Plaćeno”, a „Isteklo” se ne umanjuje) | koči li uplata unaprijed |
| Radionica | posjeti koji su došli do odjeljka Radionica | domet priče; nazivnik za „Klik na članke” |
| Košarica | posjeti s barem jednim dodavanjem u košaricu | razdvaja „ne sviđa mi se torba” od „odustao na blagajni” |
| Kopiraj | posjeti koji su kopirali IBAN, iznos ili poziv na broj | najbliži znak namjere uplate |
| Klik na članke | klikovi na članke u Radionici | odvode li članci kupce |
| Bez unikata | narudžbe samo s artiklima po narudžbi | razvodnjava li „po narudžbi” poruku o unikatima |

**A** = Narudžbe podijeljene s Posjetima (admin računa sam). **B** = Plaćeno podijeljeno s Narudžbama (admin računa sam).
Ostale omjere računate sami, dijeljenjem dvaju stupaca: Košarica / Posjeti, Kopiraj / Narudžbe, Klik na članke / Radionica,
Bez unikata / Narudžbe. Primjer: 120 posjeta, 18 s košaricom, 3 narudžbe, 2 plaćene → Košarica/Posjeti = 18/120 = 15 %, A = 3/120 = 2,5 %, B = 2/3 = 67 %.

**Tjedni u adminu** su kalendarski tjedni od ponedjeljka („Tjedan od” je prvi dan s podacima). Tjedan 1 iz tablice ispod je
tjedan koji počinje ponedjeljkom nakon objave; upišite datum: tjedan 1 = ponedjeljak ____.

**Prije objave:** u upravitelju datoteka hostinga (cPanel → File Manager → `data/`) obrisati `brojac.json` i `zahtjevi.json`
(probne narudžbe i posjeti ne smiju ući u brojeve), a u adminu ukloniti probne oznake „Plaćeno” kod rezervacija. Vaši posjeti se
ne broje dok ste prijavljeni u admin u istom pregledniku; na mobitelu se prijavite jednom u admin pa će i tamo biti tako.
Iznimka: kad otvorite vlastiti link iz Instagram profila unutar Instagramove aplikacije, taj se posjet broji (drugi preglednik).

**Narudžbe izvan stranice** (Instagram poruke, e-mail): gumb „Ručno označi plaćeno” samo skida torbu iz ponude; ne ulazi u
„Plaćeno” i ne dira tuđu rezervaciju ako je ima. B zato opisuje samo narudžbe sa stranice. Artikli po narudžbi nisu u tom popisu.

**Narudžba kojoj je rezervacija istekla, a kupac ipak uplatio:** označite je u Narudžbama gumbom „Plaćeno” (ne kod rezervacija, jer
rezervacije više nema) i torbu ručno označite prodanom. Tako ulazi u „Plaćeno”; u „Isteklo” ostaje, pa je stvarni gubitak zbog
uplate unaprijed = Isteklo − naknadno plaćene (brojite ih u dnevniku).

**B čitajte kumulativno** za 3–4 tjedna, ne po tjednu: uplata se bilježi na dan kad je označite, a narudžba na dan narudžbe.

## Pravila čitanja (procjene istraživača konverzije, ne podaci iz literature)

- A ne ocjenjivati prije otprilike 300 posjeta, B prije otprilike 20 narudžbi.
- Nula događaja u n pokušaja: gornja granica 95 % je približno 3/n (pravilo trojke, Hanley i Lippman-Hand 1983).
- Pri 50–300 posjeta tjedno razlike se ne mogu statistički testirati. Pragovi ispod su unaprijed dogovorena pravila, ne testovi.
- Studeni i prosinac (Black Friday 27. 11. 2026., Božić) dižu prodaju sami od sebe. Rast u tim tjednima ne pripisivati promjenama na stranici.
- Svaki tjedan zapisati: broj objava na Instagramu, broj dostupnih torbi, nove torbe. Unikati znače da ponuda svaki tjedan mijenja A.
- Mijenjati jednu stvar odjednom i zapisati datum.
- Black Friday je 27. 11. 2026. Tjedan koji ga sadrži i dva tjedna poslije (do 13. 12.) ne ulaze ni u jednu odluku, bez obzira na to koji je to tjedan po redu. Ako objava bude sredinom listopada, to je tjedan 6 ili 7; odluka o Promjeni 2 tada čeka siječanj.
- Nijedna odluka na manje od 10 narudžbi u bloku. Ako ih je manje, piše se „nema odluke” i blok se produljuje.
- Ako fotografija za Radionicu nije spremna za tjedan 4, promjene 1 i 2 zamjenjuju mjesta.

## Prvih 8 tjedana nakon objave

| Tjedan | Što je uključeno | Što se gleda | Odluka |
|---|---|---|---|
| 0 (prije objave) | Probna narudžba svakog tipa: unikat, samo po narudžbi, kombinacija | Stižu li oba e-maila; je li ploča nakon narudžbe točna za sva tri tipa; primatelj doslovno kao u banci | Bez ijedne greške se objavljuje. S greškom nema objave. |
| 1 | Objava; link u Instagram profilu s `?izvor=ig`; normalan ritam objava | Posjeti, udio s Instagrama, Radionica/posjeti, Košarica/posjeti | Ako je „s Instagrama” 0 uz objave, provjeriti link. |
| 2 | Bez promjena (osnovica) | Isto, plus Isteklo / (Narudžbe − Bez unikata) | Ako je nakon 4 ili više narudžbi s unikatom više od polovice isteklo: ručni podsjetnik e-mailom 12 h nakon narudžbe (predložak dolje). |
| 3 | Bez promjena (osnovica, tjedni 1–3) | Zbrojevi: A, B, Kopiraj/Narudžbe, Bez unikata/Narudžbe | Zapisati osnovicu s datumom u ODLUKE-I-PODACI.md. |
| 4 | Promjena 1: fotografija u Radionici | Klik na članke/Radionica, Košarica/posjeti | — |
| 5 | Isto (blok od dva tjedna) | Isto | Zadržati ako Košarica/posjeti nije pao više od trećine prema osnovici. |
| 6 | Promjena 2: klasične torbe pod podnaslovom (v26) s rokom izrade | Bez unikata/Narudžbe, narudžbe unikata/posjeti | — |
| 7 | Isto | Isto | Ako udio unikata u narudžbama padne ispod polovice osnovice, a posjeti nisu pali: klasične torbe na zasebnu karticu kategorije. |
| 8 | Bez promjena; pregled | Kumulativ za 8 tjedana, napomene o blagdanima | Odlučiti tri sljedeće promjene i zapisati ih s datumima. |

Odluka o pouzeću ili kartici (otvoreno od v21): nakon 6 tjedana pogledati Isteklo / (Narudžbe − Bez unikata), umanjeno za naknadno plaćene. Prag 25 % je procjena istraživača, ne podatak iz literature. Iznad praga: uvesti pouzeće ili karticu. Ispod: ostati na uplati još 6 tjedana i ponovno pogledati.

**Predložak ručnog podsjetnika** (12 h nakon narudžbe bez uplate): „Dobar dan, {ime}, hvala na narudžbi {broj}. Torba je još rezervirana za vas do {datum i sat}. Ako ste već uplatili, pošaljite nam potvrdu (snimku zaslona) odgovorom na ovaj e-mail. Ako se predomislite, samo javite, bez pitanja. Jasmina, Shiva.J”

## Tjedni dnevnik (ispunite svaki ponedjeljak)

| Tjedan od | Objave na Instagramu | Dostupnih unikata | Novih torbi | Promjena na stranici | Napomena |
|---|---|---|---|---|---|
| | | | | | |
| | | | | | |
| | | | | | |

## Što brojač ne zna

Ne zna tko je kupac ni odakle je došao osim Instagram linka, ne broji klikove srednjom tipkom, a ista osoba koja više puta otvori stranicu broji se više puta. „Kopiraj” može biti veći od broja narudžbi: ploča s podacima ostaje 24 sata, pa svako ponovno otvaranje stranice s kopiranjem broji ponovno. Kupci koji se vraćaju preko linka iz e-maila (Uvjeti kupnje) dižu broj posjeta.

**Link na pojedinu torbu za Instagram objavu:** `https://vaša-domena/?izvor=ig#torba-ID`, gdje je ID oznaka torbe iz admina (Torbe, sivi mono tekst ispod naziva, npr. `luna`). Link otvara stranicu i odmah povećani prikaz te torbe, a posjet se broji kao „s Instagrama”. Uz očekivane jednoznamenkaste tjedne brojeve šum prevladava; zato pravila gore traže više tjedana prije odluke. Za kvalitativne podatke dodajte u potvrdni e-mail jedno neobavezno pitanje: „Što vas je uvjerilo?”
