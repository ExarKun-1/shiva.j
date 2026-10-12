/* Provjera prije objave (v46): stranica ne smije pokazati kupcu nepopunjenu {OZNAKU} — npr. {NAPOMENA O PDV-u}.
   Pokreće se ručno prije objave, ne u CI-ju (pada dok vlasnica ne upiše podatke):
     node tests/objava.js [popis-proizvoda.json]
   Bez argumenta čita torbe.json; na poslužitelju zadajte stvarni popis (npr. data/proizvodi.json). */
const fs = require('fs'), path = require('path');
const root = path.join(__dirname, '..');
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const warn = m => R.push('UPOZORENJE ' + m);

/* vidljivi tekst index.html: bez komentara, skripti, stilova i atributa (pattern="{6,20}" nije tekst) */
let html = fs.readFileSync(path.join(root, 'index.html'), 'utf8')
  .replace(/<!--[\s\S]*?-->/g, '').replace(/<(script|style)\b[\s\S]*?<\/\1>/gi, '').replace(/<[^>]*>/g, ' ');
const tags = [...html.matchAll(/\{[^{}]{1,200}\}/g)].map(m => m[0].replace(/\s+/g, ' '));
ok(!tags.length, 'stranica bez nepopunjenih oznaka' + (tags.length ? ': ' + tags.join(' | ') : ''));

const file = process.argv[2] || path.join(root, 'torbe.json');
let list = [];
try { const j = JSON.parse(fs.readFileSync(file, 'utf8')); list = Array.isArray(j) ? j : (j.products || []); }
catch (e) { ok(false, 'popis proizvoda se ne može pročitati: ' + file); }
/* polje koje je cijelo {OZNAKA} stranica skriva; djelomična oznaka („Torba {BOJA}”) bi se vidjela */
const shown = ['name', 'desc', 'tagline', 'story', 'material', 'dims', 'lead'];
list.forEach(p => {
  shown.forEach(k => {
    const v = String(p[k] ?? '').trim();
    if (/\{[^}]*\}/.test(v) && !/^\{.*\}$/.test(v)) ok(false, `${p.id}: ${k} sadrži oznaku: ${v}`);
  });
  if (p.hidden || p.sold) return;
  const empty = v => !String(v ?? '').trim() || /^\{.*\}$/.test(String(v).trim());
  if ((p.cat || 'torbe') === 'torbe' && empty(p.dims)) warn(`${p.id}: nema mjera (kupac vidi „na upit”)`);
  if (!p.made && !(Array.isArray(p.imgs) && p.imgs.length)) warn(`${p.id}: unikat bez fotografije (ne može se naručiti)`);
});
ok(true, `pregledano proizvoda: ${list.length} (${path.relative(root, file) || file})`);

console.log(R.join('\n'));
const f = R.filter(l => l.startsWith('FAIL')).length;
console.log(f ? 'NE OBJAVLJIVATI: ' + f : 'SPREMNO ZA OBJAVU');
process.exit(f ? 1 : 0);
