# Mjerenje: kako čitati brojeve u adminu (Mjerenje) i što odlučiti

Stanje 9. 10. 2026. (v27, api v07, admin v07). Brojač je anoniman: samo zbrojevi po danu, bez imena, e-maila i IP adrese. Ograničenje zahtjeva po adresi štiti ga od napuhavanja (30 posjeta i 60 događaja na sat).

## Što se broji

| Ključ u adminu | Što znači | Čemu služi |
|---|---|---|
| Posjeti | jedno otvaranje stranice | nazivnik za A |
| s Instagrama | posjeti preko linka `?izvor=ig` (Instagram bio) | koliko promet dolazi s Instagrama |
| Narudžbe | poslane narudžbe (jedna narudžba = 1, bez obzira na broj torbi) | brojnik za A, nazivnik za B |
| Plaćeno | narudžbe označene „Plaćeno” (kod rezervacija ili u popisu narudžbi) | brojnik za B |
| Isteklo neplaćeno | narudžbe s unikatom kojima je rezervacija istekla bez uplate | koči li uplata unaprijed |
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

**Prije objave:** obrisati `data/brojac.json` na hostingu (probne narudžbe i posjeti ne smiju ući u brojeve). Vaši posjeti se
ne broje dok ste prijavljeni u admin u istom pregledniku; na mobitelu se prijavite jednom u admin pa će i tamo biti tako.

**Narudžbe izvan stranice** (Instagram poruke, e-mail): ne označavajte ih gumbom „Ručno označi plaćeno” radi brojača; taj gumb
samo skida torbu iz ponude i ne ulazi u „Plaćeno”. B zato opisuje samo narudžbe sa stranice.

**B čitajte kumulativno** za 3–4 tjedna, ne po tjednu: uplata se bilježi na dan kad je označite, a narudžba na dan narudžbe.

## Pravila čitanja (procjene istraživača konverzije, ne podaci iz literature)

- A ne ocjenjivati prije otprilike 300 posjeta, B prije otprilike 20 narudžbi.
- Nula događaja u n pokušaja: gornja granica 95 % je približno 3/n (pravilo trojke, Hanley i Lippman-Hand 1983).
- Pri 50–300 posjeta tjedno razlike se ne mogu statistički testirati. Pragovi ispod su unaprijed dogovorena pravila, ne testovi.
- Studeni i prosinac (Black Friday 27. 11. 2026., Božić) dižu prodaju sami od sebe. Rast u tim tjednima ne pripisivati promjenama na stranici.
- Svaki tjedan zapisati: broj objava na Instagramu, broj dostupnih torbi, nove torbe. Unikati znače da ponuda svaki tjedan mijenja A.
- Mijenjati jednu stvar odjednom i zapisati datum.
- Ako je objava sredinom listopada, tjedni 7 i 8 padaju oko Black Fridaya: tada se odluka iz tjedna 7 odgađa dok ne prođu dva mirna tjedna.
- Ako fotografija za Radionicu nije spremna za tjedan 4, promjene 1 i 2 zamjenjuju mjesta.

## Prvih 8 tjedana nakon objave

| Tjedan | Što je uključeno | Što se gleda | Odluka |
|---|---|---|---|
| 0 (prije objave) | Probna narudžba svakog tipa: unikat, samo po narudžbi, kombinacija | Stižu li oba e-maila; je li ploča nakon narudžbe točna za sva tri tipa; primatelj doslovno kao u banci | Bez ijedne greške se objavljuje. S greškom nema objave. |
| 1 | Objava; link u Instagram profilu s `?izvor=ig`; normalan ritam objava | Posjeti, udio s Instagrama, Radionica/posjeti, Košarica/posjeti | Ako je „s Instagrama” 0 uz objave, provjeriti link. |
| 2 | Bez promjena (osnovica) | Isto, plus Isteklo/Narudžbe | Ako je nakon 4 ili više narudžbi više od polovice isteklo: podsjetnik e-mailom nakon 12 h. |
| 3 | Bez promjena (osnovica, tjedni 1–3) | Zbrojevi: A, B, Kopiraj/Narudžbe, Bez unikata/Narudžbe | Zapisati osnovicu s datumom u ODLUKE-I-PODACI.md. |
| 4 | Promjena 1: fotografija u Radionici | Klik na članke/Radionica, Košarica/posjeti | — |
| 5 | Isto (blok od dva tjedna) | Isto | Zadržati ako Košarica/posjeti nije pao više od trećine prema osnovici. |
| 6 | Promjena 2: klasične torbe pod podnaslovom (v26) s rokom izrade | Bez unikata/Narudžbe, narudžbe unikata/posjeti | — |
| 7 | Isto | Isto | Ako udio unikata u narudžbama padne ispod polovice osnovice, a posjeti nisu pali: klasične torbe na zasebnu karticu kategorije. |
| 8 | Bez promjena; pregled | Kumulativ za 8 tjedana, napomene o blagdanima | Odlučiti tri sljedeće promjene i zapisati ih s datumima. |

Odluka o pouzeću ili kartici (otvoreno od v21): nakon 6 tjedana pogledati Isteklo/Narudžbe. Prag 25 % je procjena istraživača, ne podatak iz literature.

## Tjedni dnevnik (ispunite svaki ponedjeljak)

| Tjedan od | Objave na Instagramu | Dostupnih unikata | Novih torbi | Promjena na stranici | Napomena |
|---|---|---|---|---|---|
| | | | | | |
| | | | | | |
| | | | | | |

## Što brojač ne zna

Ne zna tko je kupac ni odakle je došao osim Instagram linka, ne broji klikove srednjom tipkom, a ista osoba koja više puta otvori stranicu broji se više puta. Uz očekivane jednoznamenkaste tjedne brojeve šum prevladava; zato pravila gore traže više tjedana prije odluke. Za kvalitativne podatke dodajte u potvrdni e-mail jedno neobavezno pitanje: „Što vas je uvjerilo?”
