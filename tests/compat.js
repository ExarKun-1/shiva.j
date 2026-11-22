/* v100: podrška starijim preglednicima — bez poslužitelja:  node tests/compat.js
   Cilj (upute, odjeljak o pregledniku): Safari na iOS-u 15.0 i noviji (svi iPhonei od 6s/SE, 2015.), Chrome 119 i noviji
   (zadnji za Android 7), Samsung Internet, noviji preglednici na računalu. Pogreška u sintaksi zaustavlja CIJELU skriptu
   stranice (jedan <script>), pa se ovdje zabranjuje sve što iOS 15.0 ili Chrome 119 ne razumiju (podaci: MDN/caniuse):
   - regex: lookbehind (?<= (?<! (Safari 16.4), zastavica v (Safari 17), oznaka d je iOS 15 — dopuštena;
   - statički blok klase (Safari 16.4); .at() (15.4); Object.hasOwn (15.4); structuredClone (15.4); findLast/findLastIndex (15.4);
     toSorted/toReversed/toSpliced/with (16); Promise.withResolvers (17.4); nove metode Set-a (17); Array.fromAsync (16.4);
     Object.groupBy/Map.groupBy (17.4); String isWellFormed/toWellFormed (16.4); RegExp.escape (18.2).
   CSS: dvh/svh/lvh samo uz prethodnu vh vrijednost (iOS 15.0–15.3); :focus-visible i :has nikad u popisu selektora uz
   drugi selektor (preglednik koji ih ne zna odbaci cijelo pravilo). */
const fs = require('fs');
const path = require('path');
let Linter;
try { ({ Linter } = require('eslint')); } catch (e) { ({ Linter } = require('/opt/node22/lib/node_modules/eslint')); }
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const html = fs.readFileSync(process.env.SHIVAJ_COMPAT_FILE || path.join(__dirname, '..', 'index.html'), 'utf8');
const scripts = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]);
const styles = [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n');

/* 1) sintaksa do ES2021 (iOS 15.0 ima ES2021; ES2022 tek djelomično) i zabranjene novije značajke */
const banProps = [['window', 'structuredClone'], ['self', 'structuredClone'], ['globalThis', 'structuredClone'], ['Object', 'hasOwn'], ['Object', 'groupBy'], ['Map', 'groupBy'], ['Promise', 'withResolvers'], ['Array', 'fromAsync'], ['RegExp', 'escape']]
  .map(([object, property]) => ({ object, property, message: 'novije od iOS 15.0 / Chrome 119' }));
/* metode se zabranjuju samo kao poziv (polje narudžbe „at” je obično svojstvo, ne Array.prototype.at) */
const banMethods = ['at', 'findLast', 'findLastIndex', 'toSorted', 'toReversed', 'toSpliced', 'isWellFormed', 'toWellFormed',
  'union', 'intersection', 'difference', 'symmetricDifference', 'isSubsetOf', 'isSupersetOf', 'isDisjointFrom']
  .map(name => ({ selector: `CallExpression > MemberExpression.callee[computed=false][property.name='${name}']`, message: `.${name}(): novije od iOS 15.0 / Chrome 119` }));
const config = [{
  languageOptions: { ecmaVersion: 2021, sourceType: 'script' },
  rules: {
    'no-restricted-properties': ['error', ...banProps],
    'no-restricted-globals': ['error', { name: 'structuredClone', message: 'iOS 15.4' }],
    /* recenzija v100: i window./self./globalThis.structuredClone */
    'no-restricted-syntax': ['error',
      { selector: 'Literal[regex.pattern=/\\(\\?<[=!]/]', message: 'regex lookbehind: Safari 16.4' },
      { selector: 'Literal[regex.flags=/v/]', message: 'regex zastavica v: Safari 17' },
      { selector: "NewExpression[callee.name='RegExp'] > Literal[value=/\\(\\?<[=!]/]", message: 'lookbehind u new RegExp: Safari 16.4' },
      { selector: 'StaticBlock', message: 'statički blok klase: Safari 16.4' }, ...banMethods]
  }
}];
const linter = new Linter({ configType: 'flat' });
let msgs = [];
scripts.forEach((src, i) => { msgs = msgs.concat(linter.verify(src, config).map(m => `skripta ${i + 1}, redak ${m.line}: ${m.message}`)); });
ok(scripts.length > 0 && msgs.length === 0, 'JS stranice: sintaksa do ES2021, bez lookbehinda i metoda novijih od iOS 15.0 / Chrome 119 ' + JSON.stringify(msgs.slice(0, 6)));
/* i u tekstu koji se ne parsira (izvor Workera u predlošku, nizovi za new RegExp) */
const rawLb = scripts.join('\n').split('\n').map((l, i) => [i + 1, l]).filter(([, l]) => /\(\?<[=!]/.test(l));
ok(rawLb.length === 0, 'ni u nizovima ni predlošcima nema lookbehinda ' + JSON.stringify(rawLb.map(([n]) => n).slice(0, 5)));

/* 2) CSS: dvh/svh/lvh uz prethodnu vh vrijednost istog svojstva u istom pravilu */
const decls = [...styles.matchAll(/\{([^{}]*)\}/g)].map(m => m[1]);
const badVh = [];
decls.forEach(body => {
  const parts = body.split(';').map(s => s.trim()).filter(Boolean);
  parts.forEach((d, k) => {
    if (!/\d(dvh|svh|lvh)\b/.test(d)) return;
    const prop = d.split(':')[0].trim();
    const prev = parts.slice(0, k).reverse().find(x => x.split(':')[0].trim() === prop);
    if (!prev || /\d(dvh|svh|lvh)\b/.test(prev)) badVh.push(d.slice(0, 70));
  });
});
ok(badVh.length === 0, 'CSS: dvh/svh imaju prethodnu vrijednost za iOS 15.0–15.3 ' + JSON.stringify(badVh.slice(0, 6)));

/* 3) CSS: :focus-visible / :has nisu u popisu selektora s drugim selektorom (cijelo pravilo bi palo) */
const rules = [...styles.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}@;]+)\{[^{}]*\}/g)].map(m => m[1].trim());
const badSel = rules.filter(sel => /:focus-visible|:has\(/.test(sel) && sel.split(',').length > 1 && sel.split(',').some(s => !/:focus-visible|:has\(/.test(s)));
ok(badSel.length === 0, 'CSS: :focus-visible i :has u zasebnim pravilima ' + JSON.stringify(badSel.slice(0, 6)));
/* :not(:focus-visible) unutar bitnog pravila isto ga ruši u starijem Safariju */
const badNot = rules.filter(sel => /:not\([^)]*:focus-visible/.test(sel));
ok(badNot.length === 0, 'CSS: bitna pravila ne ovise o :not(:focus-visible) ' + JSON.stringify(badNot.slice(0, 4)));

/* recenzija v100: :has( i :focus-visible u nizovima za querySelector (iOS < 15.4 baci SyntaxError i prekine funkciju) */
const qsHas = scripts.join('\n').split('\n').map((l, i) => [i + 1, l]).filter(([, l]) => /querySelector(All)?\([^)]*:(has|focus-visible)\(?/.test(l) || /\.(matches|closest)\([^)]*:(has|focus-visible)/.test(l));
ok(qsHas.length === 0, 'JS: bez :has / :focus-visible u querySelector, matches i closest ' + JSON.stringify(qsHas.map(([n]) => n).slice(0, 5)));
/* recenzija v100: overflow-wrap:anywhere (Safari 15.4) samo uz prethodni break-word */
const badOw = decls.filter(b => /overflow-wrap:\s*anywhere/.test(b) && !/overflow-wrap:\s*break-word[^;]*;[^}]*overflow-wrap:\s*anywhere/.test(b)).map(b => b.trim().slice(0, 60));
ok(badOw.length === 0, 'CSS: overflow-wrap:anywhere uz prethodni break-word ' + JSON.stringify(badOw));

/* 4) obavijest za prestare preglednike: postoji, skriva je html.js-ok, a skripta tu klasu postavlja */
ok(/class="old-browser"/.test(html) && /\.js-ok\s+\.old-browser\s*\{[^}]*display:\s*none/.test(styles) && scripts.some(s => /classList\.add\(["']js-ok["']\)/.test(s)),
  'obavijest za prestare preglednike (.old-browser) i oznaka html.js-ok iz skripte');

console.log(R.join('\n'));
const fails = R.filter(l => l.startsWith('FAIL')).length;
console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + R.length + ')');
process.exit(fails ? 1 : 0);
