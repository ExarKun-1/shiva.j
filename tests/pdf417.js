/* PDF417 / HUB-3 barkod iz PHP-a (admin/pdf417.php), v24/v25:  node tests/pdf417.js
   1) PHP i stranica (hub3Text u index.html) daju isti tekst naloga;
   2) matrica iz PHP-a se dekodira: uzorci → kodne riječi (obrnute tablice), indikatori redaka, Reed–Solomon
      (sindromi = 0), bajtovi = UTF-8 tekst naloga. Provjereno i neovisnim čitačem (ZXing) pri izradi v24. */
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const ROOT = path.join(__dirname, '..');
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const php = code => execFileSync('php', ['-r', 'require "' + ROOT + '/admin/pdf417.php"; ' + code], { encoding: 'utf8' });

// hub3Text iz stranice
const html = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8');
const fnSrc = html.slice(html.indexOf('function hub3Text(d){'), html.indexOf('function loadHub3Lib'));
const hub3Text = new Function(fnSrc + '; return hub3Text;')();
const cases = [
  { amount: 69, recipient: 'SHIVA. J, obrt za dizajn', address: 'Matije Gupca 33, 49210 Zabok', iban: 'HR98 2360 0001 1027 9044 2', model: 'HR00', ref: '2026-0007', desc: 'Shiva.J 2026-0007' },
  { amount: 1234.5, recipient: 'Šivalec Đurđa Žač ćć', address: 'Ulica Čađavca 7, 10000 Zagreb', iban: 'HR1210010051863000160', model: 'HR00', ref: '2026-0123', desc: 'Shiva.J 2026-0123 čćžšđ' },
  { amount: 99999.99, recipient: 'W'.repeat(40), address: 'Y'.repeat(40) + ',' + 'Z'.repeat(40), iban: 'HR1210010051863000160', model: 'HR99', ref: '9'.repeat(30), desc: 'Q'.repeat(50) },
  /* v25: NBSP na rubovima i znakovi izvan BMP-a (emoji) na granici reza — PHP i stranica moraju rezati jednako */
  { amount: 45, recipient: '\u00a0Đurđa 🧵\u00a0', address: '\u00a0Ulica 1,\u00a010000 Zagreb\u00a0', iban: 'HR1210010051863000160', model: 'HR00', ref: '2026-0009', desc: 'Shiva.J ' + '🧵'.repeat(40) },
];
const T = JSON.parse(php('echo json_encode(require "' + ROOT + '/admin/pdf417_tablice.php");'));
const rev = T.map(t => { const m = new Map(); t.forEach((v, i) => m.set(v.toString(2).padStart(17, '0'), i)); return m; });

function decode(rows) {
  const r = rows.length, cw = [];
  let cols = null;
  for (let i = 0; i < r; i++) {
    const row = rows[i], cl = i % 3;
    if (!row.startsWith('11111111010101000') || !row.endsWith('111111101000101001')) throw new Error('start/stop ' + i);
    const body = row.slice(17, -18);
    if (cols === null) cols = body.length / 17 - 2;
    const sym = [];
    for (let j = 0; j < body.length; j += 17) { const v = rev[cl].get(body.slice(j, j + 17)); if (v === undefined) throw new Error('uzorak r' + i); sym.push(v); }
    const base = 30 * Math.floor(i / 3), left = sym[0] - base, right = sym[sym.length - 1] - base;
    const want = cl === 0 ? [Math.floor((r - 1) / 3), cols - 1] : cl === 1 ? [4 * 3 + (r - 1) % 3, Math.floor((r - 1) / 3)] : [cols - 1, 4 * 3 + (r - 1) % 3];
    if (left !== want[0] || right !== want[1]) throw new Error('indikator r' + i);
    cw.push(...sym.slice(1, -1));
  }
  // Reed–Solomon: C(3^i) = 0 za i = 1..32 (kodne riječi kao polinom, najviši stupanj prvi)
  const pw = (b, e) => { let x = 1; for (let k = 0; k < e; k++) x = x * b % 929; return x; };
  for (let i = 1; i <= 32; i++) { const a = pw(3, i); let s = 0; for (const c of cw) s = (s * a + c) % 929; if (s !== 0) throw new Error('RS sindrom ' + i); }
  const n = cw[0], data = cw.slice(1, n);
  if (data[0] !== 901 && data[0] !== 924) throw new Error('nije byte compaction');
  const out = [];
  const real = data.slice(1);
  while (real.length && real[real.length - 1] === 900) real.pop(); /* popuna; riječi bajtova su uvijek < 900 */
  /* 924: sve u skupinama (5 riječi = 6 bajtova); 901: zadnjih 1–5 riječi su pojedinačni bajtovi */
  const tail = data[0] === 924 ? 0 : (real.length % 5 || 5);
  let pos = 0;
  for (; pos + 5 <= real.length - tail; pos += 5) {
    let v = 0n; for (let q = 0; q < 5; q++) v = v * 900n + BigInt(real[pos + q]);
    const b = []; for (let q = 0; q < 6; q++) { b.unshift(Number(v % 256n)); v /= 256n; }
    out.push(...b);
  }
  for (; pos < real.length; pos++) out.push(real[pos]);
  return { cols, rows: r, bytes: Buffer.from(out) };
}

cases.forEach((c, i) => {
  const t = hub3Text(c);
  const p = php('echo sj_hub3_text(json_decode(base64_decode("' + Buffer.from(JSON.stringify(c)).toString('base64') + '"), true));');
  ok(p === t, `slučaj ${i + 1}: PHP i stranica daju isti tekst naloga`);
  const rows = JSON.parse(php('echo json_encode(sj_pdf417_matrix(base64_decode("' + Buffer.from(t, 'utf8').toString('base64') + '")));'));
  try {
    const d = decode(rows);
    ok(d.cols === 9 && d.bytes.toString('utf8') === t, `slučaj ${i + 1}: matrica ${d.rows}×${d.cols} se dekodira (RS ispravan) u isti UTF-8 tekst`);
  } catch (e) { ok(false, `slučaj ${i + 1}: dekodiranje — ${e.message}`); }
});
ok(php('try { sj_hub3_text(["amount"=>0,"iban"=>"HR1210010051863000160"]); echo "0"; } catch (RangeException $e) { echo "1"; }') === '1'
  && php('try { sj_hub3_text(["amount"=>1e14,"iban"=>"HR1210010051863000160"]); echo "0"; } catch (RangeException $e) { echo "1"; }') === '1',
  'iznos 0 ili predug baca RangeException (e-mail tada šalje opći barkod)');
const png = path.join(require('os').tmpdir(), 'sj-pdf417-test.png');
ok(php('echo sj_pdf417_png(sj_hub3_text(["amount"=>69,"iban"=>"HR1210010051863000160","ref"=>"2026-0001"]), "' + png + '") ? "1" : "0";') === '1' && fs.readFileSync(png).slice(1, 4).toString() === 'PNG', 'PNG barkoda nastaje (GD)');

console.log(R.join('\n'));
const f = R.filter(l => l.startsWith('FAIL')).length;
console.log(f ? 'NEUSPJELO: ' + f : 'SVE PROŠLO (' + R.length + ')');
process.exit(f ? 1 : 0);
