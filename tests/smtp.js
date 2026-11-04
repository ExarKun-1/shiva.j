/* v79: slanje e-maila preko SMTP-a s prijavom (admin/lib.php sj_smtp_send) — lažni SMTP poslužitelj na 127.0.0.1.
   Treba pokrenut serve.sh (probna stranica na :8090). Provjerava prijavu (PLAIN i LOGIN), omotnicu, zaglavlja, privitak,
   točku na početku reda, odbijenu prijavu (bez lozinke u zapisima), nedostupan poslužitelj i odbijanje bez šifriranja. */
const fs = require('fs');
const net = require('net');
const { execFile } = require('child_process');
const SITE = (process.env.SHIVAJ_SITE || '/tmp/shivaj-test') + '/';
const D = SITE + 'data/';
const U = (process.env.SHIVAJ_URL || 'http://127.0.0.1:8090/') + 'api/';
const R = []; const ok = (c, m) => R.push((c ? 'PASS ' : 'FAIL ') + m);
const PORT = 2526, USER = 'info@shivaj.hr', PASS = 'Tajna-Lozinka-79';
const read = (f, d) => { try { return JSON.parse(fs.readFileSync(D + f, 'utf8')); } catch (e) { return d; } };
const sessions = [];
let mechs = 'PLAIN LOGIN';
const server = net.createServer(sock => {
  const s = { auth: null, from: '', to: [], data: '', cmds: [] }; sessions.push(s);
  let buf = '', inData = false, login = 0, lu = '';
  const say = t => sock.write(t + '\r\n');
  say('220 lazni.smtp ESMTP');
  sock.on('data', ch => {
    buf += ch.toString('binary');
    let i;
    while (true) {
      if (inData) {
        const end = buf.indexOf('\r\n.\r\n'); if (end < 0) return;
        s.data = Buffer.from(buf.slice(0, end), 'binary').toString('utf8'); buf = buf.slice(end + 5); inData = false; say('250 primljeno'); continue;
      }
      if ((i = buf.indexOf('\r\n')) < 0) return;
      const line = buf.slice(0, i); buf = buf.slice(i + 2); s.cmds.push(line);
      if (login === 1) { lu = Buffer.from(line, 'base64').toString(); login = 2; say('334 UGFzc3dvcmQ6'); continue; }
      if (login === 2) { login = 0; const p = Buffer.from(line, 'base64').toString(); s.auth = { mech: 'LOGIN', user: lu, pass: p }; say(lu === USER && p === PASS ? '235 ok' : '535 5.7.8 Authentication failed'); continue; }
      const up = line.toUpperCase();
      if (up.startsWith('EHLO')) say('250-lazni.smtp\r\n250-SIZE 10000000\r\n250-8BITMIME\r\n250 AUTH ' + mechs);
      else if (up.startsWith('AUTH PLAIN ')) { const [, u, p] = Buffer.from(line.slice(11), 'base64').toString().split('\0'); s.auth = { mech: 'PLAIN', user: u, pass: p }; say(u === USER && p === PASS ? '235 ok' : '535 5.7.8 Authentication failed'); }
      else if (up === 'AUTH LOGIN') { login = 1; say('334 VXNlcm5hbWU6'); }
      else if (up.startsWith('MAIL FROM:')) { s.from = line.slice(10); say('250 ok'); }
      else if (up.startsWith('RCPT TO:')) { if (/odbij@/.test(line)) say('550 5.1.1 no such user'); else { s.to.push(line.slice(8)); say('250 ok'); } }
      else if (up === 'DATA') { inData = true; say('354 kraj s <CRLF>.<CRLF>'); }
      else if (up === 'QUIT') { say('221 bok'); sock.end(); }
      else say('502 ne znam');
    }
  });
  sock.on('error', () => {});
});
const setCfg = patch => { const c = read('config.json', {}); Object.assign(c, patch); fs.writeFileSync(D + 'config.json', JSON.stringify(c, null, 2)); };
const php = code => new Promise(res => execFile('php', ['-r', "require '" + SITE + "admin/lib.php'; " + code], { timeout: 40000 }, (e, out, err) => res(String(out || '') + String(err || ''))));
(async () => {
  await new Promise(r => server.listen(PORT, '127.0.0.1', r));
  const cfg0 = fs.readFileSync(D + 'config.json', 'utf8');
  const prod0 = fs.readFileSync(D + 'proizvodi.json', 'utf8');
  { const pl = JSON.parse(prod0); const l = pl.find(x => x.id === 'luna'); pl.push(Object.assign({}, l, { id: 'smtp79', name: 'Proba SMTP', price: 48, sold: false, reserved: false })); fs.writeFileSync(D + 'proizvodi.json', JSON.stringify(pl, null, 2)); }
  try {
    const smtpCfg = { mail_mode: 'smtp', smtp_host: '127.0.0.1', smtp_port: PORT, smtp_secure: 'none', smtp_user: USER, smtp_pass: PASS, from_email: USER, confirm_customer: true, require_confirm: false };
    setCfg(smtpCfg);
    // 1) prava narudžba: potvrda kupcu (s barkodom) i e-mail vlasnici idu preko SMTP-a
    const body = { _gotcha: '', Napomena: '', _order: { key: 'smtp-v79-0001', customer: { name: 'Šime Čupić', email: 'kupac79@primjer.hr', phone: '091 000 0079', street: '', city: '' }, items: [{ id: 'smtp79' }], shipping: { id: 'pickup' }, total: 48 } };
    const r = await fetch(U + 'order', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const d = await r.json().catch(() => ({}));
    const cs = sessions.find(s => s.to.some(t => /kupac79@primjer\.hr/.test(t)));
    ok(d.ok && d.mailCustomer === true && cs && cs.auth && cs.auth.mech === 'PLAIN' && cs.auth.user === USER && cs.auth.pass === PASS && cs.from === '<' + USER + '> BODY=8BITMIME',
      'v79: narudžba — potvrda kupcu preko SMTP-a, prijava AUTH PLAIN, omotnica MAIL FROM = sandučić');
    const msg = cs ? cs.data : '';
    ok(/^Date: /m.test(msg) && /^Message-ID: <[a-f0-9]{24}@shivaj\.hr>$/m.test(msg) && /^To: <kupac79@primjer\.hr>$/m.test(msg) && /^Subject: =\?UTF-8\?B\?/m.test(msg) && /^From: =\?UTF-8\?B\?[^?]+\?= <info@shivaj\.hr>$/m.test(msg) && !/^Return-Path:/m.test(msg),
      'v79: zaglavlja — Date, Message-ID na domeni, To, UTF-8 naslov i pošiljatelj; bez Return-Path');
    ok(/Content-Type: multipart\/mixed/.test(msg) && /filename="barkod-za-uplatu-[\d-]+\.png"/.test(msg) && /Dobar dan, Šime Čupić/.test(msg) && !/[^\r]\n/.test(msg.replace(/\r\n/g, '')) && msg.split('\n').every(l => l === '' || l.endsWith('\r') || l === msg.split('\n').slice(-1)[0]),
      'v79: tijelo — privitak barkoda, UTF-8 tekst, redovi završavaju s CRLF');
    ok(sessions.some(s => s.to.some(t => /shivaj|vlasnica|@/.test(t)) && s !== cs) && read('smtp-zadnja-greska.json', {}).ok === true, 'v79: e-mail vlasnici također preko SMTP-a; zadnji ishod zabilježen kao uspjeh');
    // 2) točka na početku reda i AUTH LOGIN (izravno sj_send_mail)
    mechs = 'LOGIN'; const n0 = sessions.length;
    const o2 = await php("var_export(sj_send_mail('tocka@primjer.hr', 'Proba', \"prvi red\\n.drugi red s točkom\\n..treći\\nkraj\"));");
    const s2 = sessions[n0] || {};
    ok(/true/.test(o2) && s2.auth && s2.auth.mech === 'LOGIN' && s2.auth.pass === PASS && /\r\n\.\.drugi red s točkom\r\n\.\.\.treći\r\n/.test(s2.data),
      'v79: AUTH LOGIN kad poslužitelj ne nudi PLAIN; red koji počinje točkom dobiva dodatnu (RFC 5321)');
    mechs = 'PLAIN LOGIN';
    // 2b) odbijena adresa jednog kupca: false, ali bez upozorenja za cijelu trgovinu (vijeće V79-2)
    const o2b = await php("var_export(sj_send_mail('odbij@primjer.hr', 'Proba', 'tekst'));");
    ok(/false/.test(o2b) && read('smtp-zadnja-greska.json', {}).ok === true && /primatelj odbij@primjer\.hr odbijen/.test(fs.readFileSync(D + 'greske.log', 'utf8')), 'v79 (vijeće V79-2): odbijena adresa kupca — zapis u greske.log, bez upozorenja „e-mail se ne šalje”');
    // 3) kriva lozinka: false, razlog u zapisu, lozinka nigdje
    try { fs.unlinkSync(D + 'greske.log'); } catch (e) {}
    setCfg({ smtp_pass: 'kriva-lozinka-XYZ' });
    const o3 = await php("var_export(sj_send_mail('kriva@primjer.hr', 'Proba', 'tekst'));");
    const st3 = read('smtp-zadnja-greska.json', {}); const gl = fs.existsSync(D + 'greske.log') ? fs.readFileSync(D + 'greske.log', 'utf8') : '';
    ok(/false/.test(o3) && st3.ok === false && /prijava odbijena/.test(st3.msg || '') && /535/.test(st3.msg || '') && /SMTP: prijava odbijena/.test(gl) && !/kriva-lozinka-XYZ|Tajna-Lozinka/.test(JSON.stringify(st3) + gl),
      'v79: odbijena prijava (535) — slanje ne uspijeva, razlog zabilježen, lozinka nije u zapisima');
    // 4) nedostupan poslužitelj: brzo false s razlogom
    setCfg({ smtp_pass: PASS, smtp_port: 2599 });
    const t0 = Date.now(); const o4 = await php("var_export(sj_send_mail('x@primjer.hr', 'Proba', 'tekst'));");
    ok(/false/.test(o4) && /nije dostupan/.test(read('smtp-zadnja-greska.json', {}).msg || '') && Date.now() - t0 < 20000, 'v79: nedostupan poslužitelj — false s razlogom, bez dugog čekanja');
    // 5) bez šifriranja prema vanjskom poslužitelju: odbija prije spajanja (lozinka ne putuje otvoreno)
    setCfg({ smtp_port: PORT, smtp_host: 'smtp.primjer.hr' });
    const n5 = sessions.length; const o5 = await php("var_export(sj_send_mail('x@primjer.hr', 'Proba', 'tekst'));");
    ok(/false/.test(o5) && /bez šifriranja/.test(read('smtp-zadnja-greska.json', {}).msg || '') && sessions.length === n5, 'v79: bez šifriranja samo prema 127.0.0.1 — inače odbijeno prije spajanja');
    // 6) nepotpune postavke
    setCfg({ smtp_host: '127.0.0.1', smtp_pass: '' });
    const o6 = await php("var_export(sj_send_mail('x@primjer.hr', 'Proba', 'tekst'));");
    ok(/false/.test(o6) && /nedostaje: lozinka/.test(read('smtp-zadnja-greska.json', {}).msg || ''), 'v79: bez lozinke — jasna poruka „nedostaje: lozinka”');
  } catch (e) { ok(false, 'GREŠKA SKRIPTE ' + e); }
  finally { fs.writeFileSync(D + 'config.json', cfg0); fs.writeFileSync(D + 'proizvodi.json', prod0); server.close(); }
  R.forEach(x => console.log(x));
  const fails = R.filter(x => x.startsWith('FAIL')).length;
  console.log(fails ? 'NEUSPJELO: ' + fails : 'SVE PROŠLO (' + R.length + ')');
  process.exit(fails ? 1 : 0);
})();
