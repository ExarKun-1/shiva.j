/* Izvlači tablice uzoraka PDF417 (3 klastera × 929 kodnih riječi) iz js/hub3-barcode.js (bwip-js 4.5.1, MIT)
   i zapisuje ih u admin/pdf417_tablice.php za PHP verziju barkoda (admin/pdf417.php).
     node tools/pdf417_tablice.js                                                                     */
const fs = require('fs'), path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'js', 'hub3-barcode.js'), 'utf8');
const i = src.indexOf('e.clusters=d([d([');
if (i < 0) { console.error('tablice nisu pronađene'); process.exit(1); }
let k = i + 'e.clusters=d('.length, depth = 0; const j = k;
for (; k < src.length; k++) { if (src[k] === '(') depth++; if (src[k] === ')') { if (!depth) break; depth--; } }
const cl = [...src.slice(j, k).matchAll(/d\(\[([^\]]*)\]\)/g)].map(x => x[1].split(',').map(Number));
if (cl.length !== 3 || !cl.every(c => c.length === 929)) { console.error('kriva duljina: ' + cl.map(c => c.length)); process.exit(1); }
const out = `<?php
/* Tablice uzoraka PDF417 (klasteri 0, 3, 6; 929 kodnih riječi, 17-bitni uzorci, najviši bit = prvi modul).
   Izvučeno iz bwip-js 4.5.1 (https://github.com/metafloor/bwip-js) — The MIT License, Copyright (c) 2011-2024
   Mark Warren; licenca u js/hub3-barcode.js. Ne uređivati ručno: node tools/pdf417_tablice.js */
return [
${cl.map(c => '  [' + c.join(',') + ']').join(",\n")}
];
`;
fs.writeFileSync(path.join(__dirname, '..', 'admin', 'pdf417_tablice.php'), out);
console.log('admin/pdf417_tablice.php: ' + cl.map(c => c.length).join(' + '));
