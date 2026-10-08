# Pregled dizajna stranice v19 (impeccable critique)

> Datum: 8. 10. 2026. Stranica testirana lokalno s 10 primjera torbi (2 s fotografijama), na širinama 390 px i 1440 px, u headless Chromiumu.
> Metoda: dvije neovisne procjene (dizajnerska recenzija + mehanički detektor i mjerenja u pregledniku), spojene u jedan izvještaj.
> **Stanje:** P0 i P1 riješeni u v20 stranice i api v03 (vidi odjeljak „Što je napravljeno”). P2 i P3 čekaju odluku vlasnice.

## Ocjena (Nielsenove heuristike, 0–4)

| # | Heuristika | v19 | Ključni problem u v19 |
|---|---|---|---|
| 1 | Vidljivost stanja | 3 | Prazna mreža dok se torbe učitavaju; kritične greške su kratki toast |
| 2 | Jezik stvarnog svijeta | 3 | „Poziv na broj = datum uplate” je neobično pravilo; validacija na jeziku preglednika |
| 3 | Kontrola kupca | 2 | Osvježavanje briše košaricu; kupac ne može otkazati ni urediti narudžbu |
| 4 | Dosljednost | 3 | Zoom kursor na mobitelu otvara sliku iste veličine; 16 veličina fonta |
| 5 | Sprječavanje grešaka | 2 | Bez ograničenja duljine, telefon prima bilo što, barkod bez iznosa |
| 6 | Prepoznavanje, ne pamćenje | 2 | Na predaji u bankovnu aplikaciju 4 vrijednosti, samo IBAN ima „Kopiraj” |
| 7 | Učinkovitost | 3 | Tipke, swipe, autocomplete rade; nema trajne košarice |
| 8 | Minimalizam | 3 | Košarica kao jedan dugi skrol; pravni tekst cijeli otvoren |
| 9 | Oporavak od grešaka | 1 | Samo nativni oblačići; poruke servisa nestaju za 2,6 s |
| 10 | Pomoć | 3 | Sva pomoć stoji ispod onoga što objašnjava |
| | **Ukupno** | **25/40** | Prihvatljivo (63 %) |

## Što radi dobro

- **Motiv šava kao sustav:** jedna boja konca i jedna crtkana linija nose razdjelnike, odabranu dostavu, podcrte, favicon, okvir barkoda i prazne pločice.
- **Tekstovi sa stavom:** „Torbe koje postoje samo jednom”, „Jedan primjerak”, „kad je vaša, samo je vaša”.
- **Pošteno inženjerstvo:** rok rezervacije s datumom i satom, server preračunava iznose, escHtml svuda.

## Prioritetni problemi

| Prioritet | Problem | Stanje |
|---|---|---|
| P0 | Odbijanja servera (400/409/429) samo kao toast od 2,6 s; kod kvara mreže mailto + „Narudžba je zaprimljena” s IBAN-om iako ništa nije zapisano | **Riješeno u v20** |
| P1 | Uplata kao test pamćenja: 4 vrijednosti, samo IBAN kopirljiv, poziv na broj = datum | **Riješeno u v20** (poziv = broj narudžbe, Kopiraj za IBAN/iznos/poziv, Kopiraj sve, Podijeli). Barkod s iznosom: vidi otvorene stavke |
| P1 | Osvježavanje ili odbačena kartica briše košaricu i podatke za uplatu | **Riješeno u v20** (košarica, dostava, zadnja narudžba 24 h; bez osobnih podataka) |
| P1 | Ciljevi dodira (× 13×22, Ukloni 49×14), ladica dostupna Tabom dok je zatvorena, fokus ne ulazi u dijaloge, nema aria-live | **Riješeno u v20** |
| P2 | Hero bez fotografije torbe (prva kartica na 669/769 px); checkout kao jedan skrol od 1 386 px; BOX NOW zadan pa dodaje 4 € prije odabira | Otvoreno |
| P2 | Uvjeti i privatnost cijeli otvoreni (4 613 od 11 590 px mobilne stranice) | Otvoreno (pravni tekst, vlasnica) |
| P3 | Kontrast boje „prodano” 2,3:1; mikrotipografija 9,9–11 px | Djelomično: copyright, Ukloni i obrubi polja popravljeni u v20; oznake 10,4 px otvorene |

Detektor: 5 upozorenja o kontrastu navigacije su lažna (gradijent podcrte uzet kao pozadina; stvarno 6,4:1).

## Što je napravljeno u v20 (uz api v03)

- Greška servisa ostaje vidljiva iznad gumba (role=alert) dok kupac ne pošalje ponovno.
- Kvar mreže ili servisa: stanje „Narudžba nije poslana automatski” s tri koraka i gumbom „Otvori e-mail ponovno”; košarica ostaje; nema IBAN-a dok vlasnica ne potvrdi narudžbu.
- Ponovno slanje nakon kvara koristi rezervaciju iz prvog pokušaja (prije bi servis javio da je torba zauzeta).
- Poziv na broj = broj narudžbe (SJ-2026-0007 → 2026-0007, model HR00), na stranici, u potvrdi kupcu i u Uvjetima kupnje točka 5.
- Kopiraj uz IBAN, iznos i poziv na broj; „Kopiraj sve podatke”; „Podijeli / spremi” na mobitelu.
- U pregledniku se pamte samo košarica, dostava, rezervacija i podaci za uplatu zadnje narudžbe (24 h). Ime, e-mail, telefon i adresa se ne pamte.
- Rečenica „Luna ostaje rezervirana za vas 24 sata od slanja narudžbe.” i napomena stoje iznad gumba. Gumb ostaje „Naruči s obvezom plaćanja” (Direktiva 2011/83/EU čl. 8. st. 2. i presuda Suda EU C-249/21: računaju se samo riječi na gumbu).
- Ciljevi dodira ≥ 44 px, zatvorena košarica inert, fokus ulazi u košaricu i lightbox i vraća se, Tab ostaje u otvorenom prozoru, vidljiv fokus u boji konca, toast i brojač s aria-live.
- Obrazac: ograničenja duljine kao na serveru, oblik telefona, poruke provjere na hrvatskom.

## Otvorene stavke iz pregleda

1. **Barkod s iznosom i pozivom na broj (HUB-3).** Sadašnji barkod nosi samo primatelja i IBAN. Puni barkod treba vanjsku biblioteku (npr. `pdf417-generator`, MIT/LGPL) na stranici ili u PHP-u. Nije dodana jer bi to bio tuđi kod na stranici za plaćanje; odluka vlasnice.
2. **Privatnost:** jedna rečenica o tome da preglednik pamti košaricu i podatke za uplatu na uređaju kupca (bez osobnih podataka). Tekst mijenja vlasnica.
3. **Uvjeti, točka 4:** piše „u roku 24 sata e-mailom vam šaljemo potvrdu”, a potvrda stiže automatski odmah. Pravni tekst, vlasnica.
4. P2 i P3 iz tablice gore.
