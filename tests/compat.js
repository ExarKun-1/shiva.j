/* v100: podrška starijim preglednicima — bez poslužitelja:  node tests/compat.js
   v105 (odluka korisnika): cilj je Safari na iOS-u 14.0 i noviji — vidi odjeljak 5 (CSS i JS koje Safari 14.0 ne zna).
   Cilj (upute, odjeljak o pregledniku): Safari na iOS-u 15.0 i noviji (svi iPhonei od 6s/SE, 2015.), Chrome 119 i noviji
   (zadnji za Android 7), Samsung Internet, noviji preglednici na računalu. Pogreška u sintaksi zaustavlja CIJELU skriptu
   stranice (jedan <script>), pa se ovdje zabranjuje sve što iOS 15.0 ili Chrome 119 ne razumiju (podaci: MDN/caniuse):
   - regex: lookbehind (?<= (?<! (Safari 16.4), zastavica v (Safari 17), zastavica d (ES2022) se ne koristi, jer parser radi do ES2021;
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
/* v101 (recenzija vijeća v100, softver C): i vanjska skripta stranice (barkod) prolazi iste provjere */
const extJs = process.env.SHIVAJ_COMPAT_FILE ? [] : fs.readdirSync(path.join(__dirname, '..', 'js')).filter(f => f.endsWith('.js')).map(f => [f, fs.readFileSync(path.join(__dirname, '..', 'js', f), 'utf8')]);
/* v102 (recenzija vijeća v101, softver N9): i admin (vlasnica ga može otvoriti na starijem iPhoneu) — njegov CSS prolazi iste provjere,
   a skripta (složena u PHP-u, pa se ne parsira) pretragu zabranjenih značajki */
const adminSrc = process.env.SHIVAJ_COMPAT_FILE ? '' : fs.readFileSync(path.join(__dirname, '..', 'admin', 'index.php'), 'utf8');
const adminCss = [...adminSrc.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n');
const adminJs = [...adminSrc.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]).join('\n');
const styles = [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n') + '\n' + adminCss;

/* 1) sintaksa do ES2021 (iOS 15.0 ima ES2021; ES2022 tek djelomično) i zabranjene novije značajke */
const banProps = [['AbortSignal', 'timeout'], ['AbortSignal', 'any'], ['window', 'structuredClone'], ['self', 'structuredClone'], ['globalThis', 'structuredClone'], ['Object', 'hasOwn'], ['Object', 'groupBy'], ['Map', 'groupBy'], ['Promise', 'withResolvers'], ['Array', 'fromAsync'], ['RegExp', 'escape']]
  .map(([object, property]) => ({ object, property, message: 'novije od iOS 15.0 / Chrome 119' }));
/* metode se zabranjuju samo kao poziv (polje narudžbe „at” je obično svojstvo, ne Array.prototype.at) */
const banMethods = ['at', 'findLast', 'findLastIndex', 'toSorted', 'toReversed', 'toSpliced', 'isWellFormed', 'toWellFormed',
  'union', 'intersection', 'difference', 'symmetricDifference', 'isSubsetOf', 'isSupersetOf', 'isDisjointFrom']
  .flatMap(name => [{ selector: `CallExpression > MemberExpression.callee[computed=false][property.name='${name}']`, message: `.${name}(): novije od iOS 15.0 / Chrome 119` },
    { selector: `CallExpression > MemberExpression.callee[computed=true][property.value='${name}']`, message: `["${name}"](): novije od iOS 15.0 / Chrome 119` }]);
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
extJs.forEach(([f, src]) => { msgs = msgs.concat(linter.verify(src, config).map(m => `js/${f}, redak ${m.line}: ${m.message}`)); });
ok(scripts.length > 0 && msgs.length === 0, 'JS stranice: sintaksa do ES2021, bez lookbehinda i metoda novijih od iOS 15.0 / Chrome 119 ' + JSON.stringify(msgs.slice(0, 6)));
/* i u tekstu koji se ne parsira (izvor Workera u predlošku, nizovi za new RegExp) */
const allJs = scripts.concat(extJs.map(x => x[1])).join('\n');
const rawLb = allJs.split('\n').map((l, i) => [i + 1, l]).filter(([, l]) => /\(\?<[=!]/.test(l));
ok(rawLb.length === 0, 'ni u nizovima ni predlošcima nema lookbehinda ' + JSON.stringify(rawLb.map(([n]) => n).slice(0, 5)));

/* v101: crypto.randomUUID (iOS 15.4) samo uz provjeru postojanja u istom retku */
const uuidBad = allJs.split('\n').map((l, i) => [i + 1, l]).filter(([, l]) => /crypto\.randomUUID\(/.test(l) && !/crypto\.randomUUID\s*(\?|&&)/.test(l));
ok(uuidBad.length === 0, 'JS: crypto.randomUUID samo uz provjeru postojanja ' + JSON.stringify(uuidBad.map(([n]) => n).slice(0, 5)));

/* v102: i zapis s uglatim zagradama; admin skripta bez novijih značajki */
const uuidBr = allJs.split('\n').map((l, i) => [i + 1, l]).filter(([, l]) => /crypto\s*\[\s*["']randomUUID["']\s*\]\s*\(/.test(l) && !/crypto\s*\[\s*["']randomUUID["']\s*\]\s*(\?|&&)/.test(l));
ok(uuidBr.length === 0, 'JS: crypto["randomUUID"] samo uz provjeru postojanja ' + JSON.stringify(uuidBr.map(([n]) => n)));
const adminBad = ['(?<=', '(?<!', 'structuredClone', '.at(', '.findLast(', '.toSorted(', 'Object.hasOwn', 'randomUUID', ':has(', '?.', '??='].filter(t => adminJs.includes(t));
ok((process.env.SHIVAJ_COMPAT_FILE || adminJs.length > 0) && adminBad.length === 0, 'admin: skripta bez značajki novijih od iOS 15.0 ' + JSON.stringify(adminBad));

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
const NEWSEL = /:focus-visible|:has\(|:user-invalid|:user-valid/;
const badSel = rules.filter(sel => NEWSEL.test(sel) && sel.split(',').length > 1 && sel.split(',').some(s => !NEWSEL.test(s)));
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

/* v101 (recenzija vijeća v100, softver C): noviji CSS koji iOS 15.0 ne zna */
const cssNc = styles.replace(/\/\*[\s\S]*?\*\//g, '');
const mediaRange = (cssNc.match(/@media[^{]*[<>]=?[^{]*\{/g) || []).map(x => x.slice(0, 50));
ok(mediaRange.length === 0, 'CSS: @media bez sintakse raspona (width >= …; Safari 16.4) ' + JSON.stringify(mediaRange));
ok(!/@layer\b|@container\b|@scope\b/.test(cssNc), 'CSS: bez @layer, @container i @scope');
const nest = (cssNc.match(/[;{}]\s*&\s*[.:#\[>+~ ]/g) || []).length;
ok(nest === 0, 'CSS: bez ugniježđenih pravila (&) ' + nest);
/* v102: ugniježđena pravila i BEZ „&” (Safari 17.2) — vitičasta zagrada unutar običnog pravila */
{ const bad = []; const st = []; let last = 0;
  for (let i = 0; i < cssNc.length; i++) { const ch = cssNc[i];
    if (ch === '{') { const pre = cssNc.slice(last, i).trim(); if (st.length && !st[st.length - 1].startsWith('@')) bad.push(pre.slice(0, 40)); st.push(pre); last = i + 1; }
    else if (ch === '}') { st.pop(); last = i + 1; } else if (ch === ';') last = i + 1; }
  ok(bad.length === 0, 'CSS: bez ugniježđenih pravila (ni bez &) ' + JSON.stringify(bad.slice(0, 4))); }
/* v102 (recenzija vijeća v101, softver N9): kratica text-decoration s više vrijednosti (stil, boja) — stariji Safari odbaci cijelu deklaraciju,
   pa poveznica ostane bez podcrte; koristiti text-decoration-style / -color */
const tdBad = decls.filter(b => /(?:^|;)\s*text-decoration:\s*[^;]*\b(?:dashed|dotted|solid|double|wavy)\b/.test(b) || /(?:^|;)\s*text-decoration:\s*(?:underline|line-through|overline)\s+[^;\s]/.test(b)).map(b => (b.match(/text-decoration:[^;]*/) || [''])[0].slice(0, 60));
ok(tdBad.length === 0, 'CSS: text-decoration samo s jednom vrijednošću (stil i boja zasebnim svojstvima) ' + JSON.stringify(tdBad.slice(0, 4)));
const badColor = [];
decls.forEach(body => { const parts = body.split(';').map(x => x.trim()).filter(Boolean); parts.forEach((d, k) => { if (!/\b(oklch|oklab|lch|lab|color-mix)\(/.test(d)) return; const prop = d.split(':')[0].trim(); const prev = parts.slice(0, k).reverse().find(x => x.split(':')[0].trim() === prop); if (!prev || /\b(oklch|oklab|lch|lab|color-mix)\(/.test(prev)) badColor.push(d.slice(0, 60)); }); });
ok(badColor.length === 0, 'CSS: oklch/lab/color-mix samo uz prethodnu rezervnu vrijednost ' + JSON.stringify(badColor.slice(0, 4)));

/* 5) v105: Safari 14.0 (iOS 14.0–14.4) — CSS i JS koje ne zna (MDN/caniuse: inset 14.1, flex gap 14.1, aspect-ratio 15, :has 15.4,
   backdrop-filter samo s prefiksom, Intl.ListFormat/Segmenter i WeakRef 14.1). Admin smije :has kao ukras (vlasničin uređaj), stranica ne smije. */
const siteCss = [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n').replace(/\/\*[\s\S]*?\*\//g, '');
const siteDecls = [...siteCss.matchAll(/\{([^{}]*)\}/g)].map(m => m[1]);
ok(!/(?:^|[;{\s])inset\s*:/.test(siteCss), 'Safari 14.0: bez svojstva inset (top/right/bottom/left)');
ok(!/:has\(/.test(siteCss), 'Safari 14.0: stranica bez :has (razred iz skripte)');
const arDecl = siteDecls.filter(b => /(?:^|;)\s*aspect-ratio\s*:\s*\d/.test(b)).length;
ok(arDecl === 0 || /@supports\s+not\s*\(\s*aspect-ratio\s*:\s*1\s*\/\s*1\s*\)/.test(siteCss), 'Safari 14.0: aspect-ratio uz zamjenu @supports not (aspect-ratio) (' + arDecl + ')');
const bdBad = siteDecls.filter(b => /(?:^|;)\s*backdrop-filter\s*:/.test(b) && !/-webkit-backdrop-filter\s*:/.test(b)).length;
ok(bdBad === 0, 'Safari 14.0: backdrop-filter uz -webkit-backdrop-filter (' + bdBad + ')');
const flexGap = siteDecls.filter(b => /display\s*:\s*(?:inline-)?flex/.test(b) && /(?:^|;)\s*(?:row-|column-)?gap\s*:/.test(b)).length;
ok(flexGap === 0 || /function flexGapFallback\(/.test(scripts[0] || ''), 'Safari 14.0: flexbox s gap uz zamjenu flexGapFallback (' + flexGap + ' pravila)');
const js14 = ['Intl.ListFormat', 'Intl.Segmenter', 'WeakRef', 'FinalizationRegistry', '.replaceChildren('].filter(t => allJs.includes(t));
ok(js14.length === 0, 'Safari 14.0: JS bez Intl.ListFormat, Intl.Segmenter, WeakRef ' + JSON.stringify(js14));
/* v106 (pravi iPhone, iOS 15): Safari od iOS-a 15 gumbu bez boje daje sistemsku plavu — osnovno pravilo za button mora zadati boju */
const btnRule = (cssNc.match(/(^|[}\s])button\{[^}]*\}/) || [''])[0];
ok(/color\s*:/.test(btnRule), 'Safari iOS 15+: osnovno pravilo button{…} zadaje boju teksta ' + JSON.stringify(btnRule.trim().slice(0, 80)));
/* v106 (pravi iPhone, iOS 14): opcije Intl koje Safari zna tek od 15.4 bacaju RangeError — demo je na tome pao pri slanju narudžbe */
const intl154 = ['shortOffset', 'longOffset', 'shortGeneric', 'longGeneric', 'formatRange(', 'roundingMode', 'trailingZeroDisplay'].filter(t => allJs.includes(t));
ok(intl154.length === 0, 'Safari 14.0: bez opcija Intl iz Safarija 15.4+ (shortOffset, formatRange…) ' + JSON.stringify(intl154));

/* 4) obavijest za prestare preglednike: skrivena dok druga skripta ne postavi html.js-fail; bez animacije i tajmera (v101) */
const obRule = (cssNc.match(/\.old-browser\{[^}]*\}/) || [''])[0];
ok(/class="old-browser"/.test(html) && /display:\s*none/.test(obRule) && !/animation/.test(obRule) && /\.js-fail\s+\.old-browser\s*\{[^}]*display:\s*block/.test(cssNc)
  && scripts.length === 2 && /classList\.add\(["']js-ok["']\)/.test(scripts[0]) && /js-fail/.test(scripts[1]) && scripts[1].length < 600,
  'obavijest za prestare preglednike: .old-browser skrivena, html.js-fail je pokazuje, druga mala skripta iza glavne');

console.log(R.join('\n'));
const fails = R.filter(l => l.startsWith('FAIL')).length;
console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + R.length + ')');
process.exit(fails ? 1 : 0);
