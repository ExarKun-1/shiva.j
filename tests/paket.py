"""Paket za prijenos (tools/paket.py):  python3 tests/paket.py
Nadogradnja ne smije nositi ništa što na hostingu piše admin (data/, torbe.json, img/)."""
import os, re, subprocess, sys, tempfile, zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
R = []
def ok(c, m): R.append(('PASS ' if c else 'FAIL ') + m)

v = re.search(r'Sva prava pridržana · (v\d+)', open(os.path.join(ROOT, 'index.html'), encoding='utf-8').read()).group(1)
subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'paket.py')], check=True, capture_output=True)
first = zipfile.ZipFile(os.path.join(ROOT, 'dist', f'shivaj-{v}-prvi.zip'))
upd = zipfile.ZipFile(os.path.join(ROOT, 'dist', f'shivaj-{v}-nadogradnja.zip'))
fn, un = first.namelist(), upd.namelist()
need = ['index.html', '.htaccess', 'api/index.php', 'admin/index.php', 'admin/lib.php', 'js/hub3-barcode.js']
ok(all(n in fn for n in need) and all(n in un for n in need), 'oba paketa imaju stranicu, servis, admin i barkod')
ok(not [n for n in un if (n.startswith(('data/', 'img/')) and n not in ('img/zakonsko-jamstvo-hr.jpg', 'img/logo.svg')) or n == 'torbe.json'], 'nadogradnja bez data/, img/ (osim EU obavijesti i loga) i torbe.json')
ok('img/logo.svg' in fn and 'img/logo.svg' in un, 'v86: oba paketa nose logo')
_lg = open(os.path.join(ROOT, 'img', 'logo.svg'), encoding='utf-8').read()
ok(not re.search(r'<script|<image|<foreignObject|url\(|\son\w+=|(?:xlink:)?href="(?!#)', _lg, re.I), 'v86: logo.svg bez skripti i vanjskih poveznica')
ok('img/zakonsko-jamstvo-hr.jpg' in fn and 'img/zakonsko-jamstvo-hr.jpg' in un, 'v84: oba paketa nose službenu EU obavijest o zakonskom jamstvu')
ok('data/.htaccess' in fn and 'data/PRVA-PRIJAVA.txt' in fn and first.read('torbe.json').strip() == b'[]', 'prvi paket: zaštita data/, prva prijava, prazna ponuda')
bad = re.compile(r'(^|/)(\.git|docs|tests|proba|tools)/|\.md$|primjer|router\.php|\.example$')
ok(not [n for n in fn + un if bad.search(n)], 'bez uputa, testova, primjera i lokalnog routera')
kod = re.search(r'^kod: (\S{12,})$', first.read('data/PRVA-PRIJAVA.txt').decode(), re.M)
ok(kod and kod.group(1) in open(os.path.join(ROOT, 'dist', f'shivaj-{v}-KOD-PRVE-PRIJAVE.txt'), encoding='utf-8').read(), 'v51: prvi paket nosi nasumičan kod za prvu prijavu, kod je i u dist/')
pg = first.read('index.html').decode()
ok(all(m in pg for m in ('<!-- UVJETI-START -->', '<!-- UVJETI-END -->', '<!-- NJEGA-START -->', '<!-- NJEGA-END -->')) and 'SHIVA.J WEBSHOP — v' not in pg, 'v82: stranica u paketu bez bilješki, ali s oznakama za Uvjete i Njegu u e-mailu')
ok(v in first.read('PROCITAJ.txt').decode() and 'NEMA' in upd.read('PROCITAJ.txt').decode(), 'PROCITAJ.txt s verzijom ' + v)
ok(all(((first.getinfo(n).external_attr >> 16) & 0o444) == 0o444 for n in ('torbe.json', 'PROCITAJ.txt', 'data/PRVA-PRIJAVA.txt')), 'v58: datoteke napisane u paketu čitljive su poslužitelju (0644)')

print('\n'.join(R))
f = sum(l.startswith('FAIL') for l in R)
print('NEUSPJELO: %d' % f if f else 'SVE PROŠLO (%d)' % len(R))
sys.exit(1 if f else 0)
