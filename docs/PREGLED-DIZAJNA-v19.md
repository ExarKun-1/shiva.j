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

## Vijeće recenzenata i v21 (8. 10. 2026.)

Četiri neovisna recenzenta pregledala su v20: umjetnički direktor, istraživač konverzije (s izvorima),
kupac s Instagrama na mobitelu i pisac brenda. Zajednički zaključak: torbe se premalo vide, nedostaju
lice i priča, pravni tekst zauzima previše mjesta, oskudicu treba reći mirno, a plaćanje samo uplatom
moglo bi kočiti prodaju (odluka vlasnice). Upute za fotografiranje s izvorima: `docs/UPUTE-FOTOGRAFIRANJE.md`.

**Napravljeno u v21 (uz admin v03, api v04):**
- velika fotografija torbe u krugu sa šavom na prvom ekranu (u adminu kvačica „Na naslovnici”, inače prva dostupna torba s fotografijom);
- kartica: druga fotografija na prijelaz mišem, broj fotografija, „1/1”, redak karaktera, „Ručni rad · dostava od X €”;
- povećani prikaz: priča torbe, poveznica na punu veličinu; nepopunjene `{…}` vrijednosti se ne prikazuju;
- Uvjeti i privatnost na sklapanje (tekst neizmijenjen); mobilna stranica 9 173 px umjesto 11 590 px;
- košarica: „Bez registracije · Plaćanje uplatom · 14 dana za raskid ugovora”, „Sljedeći korak” ispod gumba;
- nakon narudžbe vremenska crta, s rokom slanja iz Postavki („Rok slanja nakon uplate”);
- uklonjeno „nemojte predugo čekati”;
- admin „Mjerenje”: tjedni zbrojevi posjeta, posjeta s Instagrama (`?izvor=ig`), narudžbi, plaćenih i isteklih rezervacija, s omjerima A i B; bez osobnih podataka.

**Ostaje vlasnici:** fotografije po uputama, dimenzije, materijal, redak karaktera i priča za svaku torbu,
„O nama” u prvom licu s fotografijom, rok slanja, odluka o pouzeću ili kartici, Instagram link s `?izvor=ig`.

## Vijeće o v21 i v22 (8. 10. 2026.)

Ista četiri recenzenta pregledala su v21. Umjetnički direktor: v20 6/10 → v21 7,5/10; kupac s Instagrama:
„Da, Lunu bih kupila, uz malo nelagode” (nakon v20 ne bi). Hvale torbu u krugu na prvom ekranu, drugu
fotografiju i redak karaktera, „Sljedeći korak” i vremensku crtu, cijenu dostave uz torbu i sklopljene uvjete.

**Napravljeno u v22 (uz admin v04):** poveznica „Radionica” i naslov „Iz radionice u Zaboku.” umjesto
„O nama” (Zabok je adresa obrta iz impressuma); uklonjena oznaka „1/1” (zbunjivala uz „1/2 foto”, a
„Jedan primjerak” već stoji ispod); redak karaktera na kartici manji i u boji konca; gumb „Pogledaj
unikate” na prvom ekranu; tekst: „bit će rezervirana”, korak „Do isteka rezervacije”, „javit ćemo vam
kad paket krene”, „bez kartice” samo jednom u košarici, poruke košarice bez roda („U košarici: „Luna” ✓”);
mjerenje: plaćeno i isteklo broje se po narudžbi, ne po torbi (omjer B više ne može prijeći 100 %).

**Ostaje vlasnici (vijeće smatra da to najviše koči prodaju):** dimenzije i materijal uz svaku torbu i
fotografija na ramenu; prave fotografije 4–6 po torbi (`docs/UPUTE-FOTOGRAFIRANJE.pdf`); tekst
„Radionica” u prvom licu s licem i imenom; odluka o pouzeću ili kartici (prijedlog istraživača: 6 tjedana
mjeriti istekle neplaćene rezervacije; prag 25 % je njegova procjena, ne podatak iz literature); HUB-3
barkod s iznosom traži vanjsku biblioteku (nije uvedeno).

**v23 (9. 10. 2026.):** tražilica je našla članke o Jasmini i Shiva.J (Dnevnik.hr/Zadovoljna 2019., Zagorje.com,
Stilueta, pregled poduzetnica KZŽ). Dodana traka „Pisali su o nama” ispod prvog ekrana i nacrt teksta
„Radionica” u prvom licu iz činjenica koje se poklapaju u više izvora, s popisom članaka i mjestom za
fotografiju. Vlasnica potvrđuje tekst prije objave.

## Vijeće o v24 i v25 (9. 10. 2026.)

Treći krug: umjetnički direktor v24 8/10 (v22 7,5); kupac s Instagrama „Da, kupila bih Lunu” (nelagoda
zbog nepoznate osobe pala na pola, zbog uplate unaprijed ostala); pisac brenda tekstu Radionice 6/10
(izmišljeni osjećaji, materijali i „ne radim serije” nisu iz članaka, „torba traje” umjesto „izrada traje”,
unikatnost ponovljena četiri puta, ime brenda neobjašnjeno); istraživač: tri vanjske poveznice na
najskupljem mjestu, brojač pri ovom prometu ne razlikuje učinak trake od šuma, predlaže brojanje klikova
i signal identiteta uz IBAN. Umjetnički direktor našao mrtvo CSS pravilo (mobilni blok za traku stajao
prije osnovnih pravila).

Vlasnik projekta dodao činjenice: skaj umjesto kože; šarene torbe od izrezanih komada su unikati,
klasične u jednoj boji šiju se i po narudžbi; „s torbom u ruci” i „Zadatak sam predala, torbu zadržala.”
ostaju.

**Napravljeno u v25 (api v05, admin v05):** tekstovi usklađeni s istinom o torbama (hero, korak 01,
Uvjeti točka 2 prva rečenica, Radionica); traka bez vanjskih poveznica, vodi na Radionicu; popravljeno
mobilno pravilo; Radionica: konačni tekst, adresa, poziv da kupac piše prije narudžbe, ljepljivi lijevi
stupac, veći naslov, uvodna rečenica u Marcellusu, e-mail vodi na Kontakt; redak kome se uplaćuje ispod
„Podaci za uplatu”; klik na članak broji se anonimno (stupac „Klik na članke” u Mjerenju).

**Ostaje Jasmini:** fotografija u radionici (sva četiri recenzenta: jedino što još fali), fotografije torbi,
dimenzije i materijal, potvrda „J od Jasmine”, godine članaka, Uvjeti točka 2 s knjigovođom, odluka o
pouzeću ili kartici.
