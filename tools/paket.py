#!/usr/bin/env python3
"""Paket za prijenos na hosting (Croadria):  python3 tools/paket.py  →  dist/

  shivaj-vNN-prvi.zip         prvo postavljanje: stranica, servis, admin, prazna ponuda i data/ za prvu prijavu
  shivaj-vNN-nadogradnja.zip  nova verzija: samo kod, BEZ data/, torbe.json i img/ — prave narudžbe, lozinka,
                              ponuda i fotografije koje je admin spremio u img/ na hostingu ostaju netaknute

Raspakirati u public_html (File Manager → Upload → Extract, ili FileZilla nakon raspakiravanja na računalu).
"""
import os, re, sys, zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'dist')
CODE = ['index.html', '.htaccess', 'js', 'api', 'admin']          # uvijek
# samo prvi put (+ prazna torbe.json): slika za dijeljenje linka i barkod iz banke; fotografije torbi dodaje admin
FIRST = ['img/og.jpg', 'img/barkod-uplata.png', 'data/.htaccess', 'data/PRVA-PRIJAVA.txt']
NEVER = re.compile(r'(^|/)(\.git|docs|tests|proba|dist|tools)(/|$)|\.md$|torbe\.primjer\.json$|torbe\.json\.example$|router\.php$')

README = {
    'prvi': """Shiva.J {v} — PRVO POSTAVLJANJE
1. Raspakirajte sve u public_html (korijen web stranice).
2. Otvorite https://vasa-domena.hr/admin i postavite lozinku.
3. Admin → Postavke: upišite adresu stranice (npr. https://shivaj.hr), e-mail, primatelja i IBAN. Bez adrese stranice
   narudžbe se ne primaju.
Upute: docs/UPUTE-OBJAVA-CROADRIA-v03.md u repozitoriju.
""",
    'nadogradnja': """Shiva.J {v} — NADOGRADNJA
1. Raspakirajte u public_html i dopustite zamjenu postojećih datoteka.
2. Paket namjerno NEMA mapa data/ i img/ ni datoteke torbe.json: narudžbe, lozinka, postavke, ponuda i fotografije
   ostaju kakve jesu. Ako changelog kaže da je nova i slika, nju prenesite zasebno.
3. Otvorite stranicu i u podnožju provjerite da piše {v}.
""",
}


def version():
    html = open(os.path.join(ROOT, 'index.html'), encoding='utf-8').read()
    m = re.search(r'Sva prava pridržana · (v\d+)', html)
    if not m:
        sys.exit('verzija nije pronađena u podnožju index.html')
    return m.group(1)


def files(paths):
    for p in paths:
        full = os.path.join(ROOT, p)
        if os.path.isdir(full):
            for d, _, fs in os.walk(full):
                for f in sorted(fs):
                    rel = os.path.relpath(os.path.join(d, f), ROOT).replace(os.sep, '/')
                    if not NEVER.search(rel):
                        yield rel
        elif os.path.isfile(full):
            yield p
        else:
            sys.exit('nedostaje: ' + p)


def build(kind, v):
    os.makedirs(OUT, exist_ok=True)
    path = os.path.join(OUT, f'shivaj-{v}-{kind}.zip')
    names = list(files(CODE + (FIRST if kind == 'prvi' else [])))
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        for n in names:
            z.write(os.path.join(ROOT, n), n)
        if kind == 'prvi':
            z.writestr('torbe.json', '[]\n')
        z.writestr('PROCITAJ.txt', README[kind].format(v=v))
    return path, len(names)


if __name__ == '__main__':
    v = version()
    for kind in ('prvi', 'nadogradnja'):
        path, n = build(kind, v)
        print(f'{os.path.relpath(path, ROOT)}  ({n} datoteka)')
