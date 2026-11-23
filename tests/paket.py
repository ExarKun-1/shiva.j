"""Paket za prijenos (tools/paket.py):  python3 tests/paket.py
Nadogradnja ne smije nositi ništa što na hostingu piše admin (data/, torbe.json, img/)."""
import os, re, subprocess, sys, tempfile, zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
R = []
def ok(c, m): R.append(('PASS ' if c else 'FAIL ') + m)

v = re.search(r'SHIVA\.J WEBSHOP — (v\d+)', open(os.path.join(ROOT, 'index.html'), encoding='utf-8').read()).group(1)
# v95 (recenzija vijeća v94, L5): paket se gradi u privremenu mapu — test ne prepisuje prave zipove ni kod prve prijave u dist/
DIST = tempfile.mkdtemp(prefix='shivaj-dist-')
subprocess.run([sys.executable, os.path.join(ROOT, 'tools', 'paket.py')], check=True, capture_output=True, env=dict(os.environ, SHIVAJ_DIST=DIST))
first = zipfile.ZipFile(os.path.join(DIST, f'shivaj-{v}-prvi.zip'))
upd = zipfile.ZipFile(os.path.join(DIST, f'shivaj-{v}-nadogradnja.zip'))
fn, un = first.namelist(), upd.namelist()
need = ['index.html', '.htaccess', 'api/index.php', 'admin/index.php', 'admin/lib.php', 'js/hub3-barcode.js']
ok(all(n in fn for n in need) and all(n in un for n in need), 'oba paketa imaju stranicu, servis, admin i barkod')
ok(not [n for n in un if (n.startswith(('data/', 'img/')) and n not in ('img/zakonsko-jamstvo-hr.jpg', 'img/logo.svg', 'img/ikona.svg', 'img/ikona-180.png', 'img/.htaccess')) or n == 'torbe.json'], 'nadogradnja bez data/, img/ (osim EU obavijesti, loga i ikona) i torbe.json')
ok('img/logo.svg' in fn and 'img/logo.svg' in un, 'v86: oba paketa nose logo')
_lg = open(os.path.join(ROOT, 'img', 'logo.svg'), encoding='utf-8').read()
ok(not re.search(r'<script|<image|<foreignObject|url\(|\son\w+=|(?:xlink:)?href="(?!#)', _lg, re.I), 'v86: logo.svg bez skripti i vanjskih poveznica')
ok('img/.htaccess' in fn and 'img/.htaccess' in un and b'Require all denied' in first.read('img/.htaccess'), 'v89: img/.htaccess (bez izvršavanja skripti) u oba paketa')
_ik = open(os.path.join(ROOT, 'img', 'ikona.svg'), encoding='utf-8').read()
ok(all(n in fn and n in un for n in ('img/ikona.svg', 'img/ikona-180.png', 'favicon.ico')) and not re.search(r'<script|<image|<foreignObject|url\(|\son\w+=|(?:xlink:)?href="(?!#)', _ik, re.I), 'v87: ikona u oba paketa, bez skripti i vanjskih poveznica')
ok('img/zakonsko-jamstvo-hr.jpg' in fn and 'img/zakonsko-jamstvo-hr.jpg' in un, 'v84: oba paketa nose službenu EU obavijest o zakonskom jamstvu')
ok('data/.htaccess' in fn and 'data/PRVA-PRIJAVA.php' in fn and first.read('torbe.json').strip() == b'[]', 'prvi paket: zaštita data/, prva prijava, prazna ponuda')
bad = re.compile(r'(^|/)(\.git|docs|tests|proba|tools)/|\.md$|primjer|router\.php|\.example$')
ok(not [n for n in fn + un if bad.search(n)], 'bez uputa, testova, primjera i lokalnog routera')
kod = re.search(r'^kod: (\S{12,})$', first.read('data/PRVA-PRIJAVA.php').decode(), re.M)
ok(kod and kod.group(1) in open(os.path.join(DIST, f'shivaj-{v}-KOD-PRVE-PRIJAVE.txt'), encoding='utf-8').read(), 'v51: prvi paket nosi nasumičan kod za prvu prijavu, kod je i u dist/')
pg = first.read('index.html').decode()
ok(all(m in pg for m in ('<!-- UVJETI-START -->', '<!-- UVJETI-END -->', '<!-- NJEGA-START -->', '<!-- NJEGA-END -->')) and 'SHIVA.J WEBSHOP — v' not in pg, 'v82: stranica u paketu bez bilješki, ali s oznakama za Uvjete i Njegu u e-mailu')
ok(v in first.read('PROCITAJ.txt').decode() and 'NEMA' in upd.read('PROCITAJ.txt').decode(), 'PROCITAJ.txt s verzijom ' + v)
ok(f'<meta name="shivaj-verzija" content="{v}">' in pg and f'Verzija stranice: {v}' in upd.read('PROCITAJ.txt').decode() and 'podnožju' not in upd.read('PROCITAJ.txt').decode(), 'v95 (L4): verzija ostaje u paketu (meta), upute za nadogradnju upućuju na admin')
ok(all(((first.getinfo(n).external_attr >> 16) & 0o444) == 0o444 for n in ('torbe.json', 'PROCITAJ.txt', 'data/PRVA-PRIJAVA.php')), 'v58: datoteke napisane u paketu čitljive su poslužitelju (0644)')

# v96 (recenzija): .htaccess u oba paketa nosi otisak svake ugrađene skripte iz istog index.html (v101: dvije — glavna i mala za obavijest starim preglednicima); admin prepoznaje nepodudaranje
import base64, hashlib
for zf in (first, upd):
    hp = zf.read('index.html').decode(); ht = zf.read('.htaccess').decode()
    bodies = [m.group(2) for m in re.finditer(r'<script\b([^>]*)>(.*?)</script>', hp, re.S | re.I) if not re.search(r'\bsrc\s*=|ld\+json', m.group(1), re.I)]
    hs = ["'sha256-" + base64.b64encode(hashlib.sha256(b.encode()).digest()).decode() + "'" for b in bodies]
    ok(len(hs) == 2 and all(h in ht for h in hs) and not re.search(r'\son[a-z]+\s*=\s*["\']', re.sub(r'<script\b.*?</script>', '', hp, flags=re.S | re.I)), 'v96: CSP otisak skripte u .htaccess paketa ' + zf.filename.split('/')[-1] + ', bez ugrađenih rukovatelja on…=')
with tempfile.TemporaryDirectory() as td:
    for n in ('index.html', '.htaccess'): open(os.path.join(td, n), 'wb').write(upd.read(n))
    php = "require '%s/admin/lib.php'; echo sj_csp_hash_problem($argv[1]) === '' ? 'A' : 'B';" % ROOT.replace("'", "")
    a = subprocess.run(['php', '-r', php, td], capture_output=True, text=True).stdout
    open(os.path.join(td, 'index.html'), 'a').write('<script>1</script>')
    b = subprocess.run(['php', '-r', php, td], capture_output=True, text=True).stdout
    ok(a.endswith('A') and b.endswith('B'), 'v96 (recenzija): admin prepoznaje index.html i .htaccess iz različitih paketa ' + repr((a[-80:], b[-80:])))
ok('ZAJEDNO' in upd.read('PROCITAJ.txt').decode(), 'v96 (recenzija): upute za nadogradnju — index.html i .htaccess zajedno')
# v102 (recenzija vijeća v101, softver N10): tests/serve.sh uz zauzet port staje PRIJE brisanja mape (stranica živog poslužitelja ostaje)
import socket
with tempfile.TemporaryDirectory() as td:
    marker = os.path.join(td, 'ostaje.txt'); open(marker, 'w').write('x')
    sk = socket.socket(); sk.bind(('127.0.0.1', 0)); sk.listen(1); port = sk.getsockname()[1]
    rc = subprocess.run(['sh', os.path.join(ROOT, 'tests', 'serve.sh'), td], capture_output=True, text=True, env=dict(os.environ, SHIVAJ_PORT=str(port)))
    sk.close()
    ok(rc.returncode == 3 and os.path.isfile(marker) and 'zauzet' in rc.stderr, 'v102: serve.sh uz zauzet port izlazi s 3 i ne briše mapu ' + repr((rc.returncode, os.path.isfile(marker))))
import shutil; shutil.rmtree(DIST, ignore_errors=True)
print('\n'.join(R))
f = sum(l.startswith('FAIL') for l in R)
print('NEUSPJELO: %d' % f if f else 'SVE PROŠLO (%d)' % len(R))
sys.exit(1 if f else 0)
