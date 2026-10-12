#!/usr/bin/env python3
"""Složi PROBNU stranicu za GitHub Pages:  python3 proba/build.py _site
Kopira stranicu, skripte i slike, torbe.primjer.json kao torbe.json, i u index.html ubacuje:
  - adresu probnog poslužitelja (Google Apps Script) iz varijable okoline PROBA_API_URL,
  - <meta name="robots" content="noindex"> (proba ne smije u tražilice),
  - traku „PROBNA STRANICA”.
PHP, admin/ i data/ se ne objavljuju."""
import json, os, shutil, sys

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(root, '_site')
api = os.environ.get('PROBA_API_URL', '').strip()
if api and not (api.startswith('https://script.google.com/') and api.endswith('/exec')):
    sys.exit('PROBA_API_URL mora biti adresa Apps Script web-aplikacije (https://script.google.com/.../exec)')

shutil.rmtree(out, ignore_errors=True)
os.makedirs(out)
for d in ('js', 'img', 'images'):
    if os.path.isdir(os.path.join(root, d)):
        shutil.copytree(os.path.join(root, d), os.path.join(out, d))
shutil.copy(os.path.join(root, 'torbe.primjer.json'), os.path.join(out, 'torbe.json'))
open(os.path.join(out, '.nojekyll'), 'w').close()

html = open(os.path.join(root, 'index.html'), encoding='utf-8').read()
head = '<meta name="robots" content="noindex, nofollow">\n'
if api:
    head += '<script>window.SJ_API = ' + json.dumps(api) + ';</script>\n'
assert html.count('<head>') == 1 and html.count('<body>') == 1
html = html.replace('<head>', '<head>\n' + head, 1)
msg = ('PROBNA STRANICA — narudžbe nisu prave i ništa ne treba uplatiti' if api
       else 'PROBNA STRANICA — poslužitelj za narudžbe još nije postavljen (PROBA_API_URL)')
banner = ('<div role="note" style="position:sticky;top:0;z-index:9999;background:#9B2C1F;color:#fff;'
          'font:600 13px/1.4 system-ui,sans-serif;text-align:center;padding:6px 12px">' + msg + '</div>\n')
html = html.replace('<body>', '<body>\n' + banner, 1)
open(os.path.join(out, 'index.html'), 'w', encoding='utf-8').write(html)
print('probna stranica:', out, '| poslužitelj:', api or '(nije postavljen)')
