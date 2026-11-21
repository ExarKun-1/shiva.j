<?php
/* Samo za lokalno testiranje s ugrađenim PHP poslužiteljem:
     php -S 127.0.0.1:8090 -t <mapa> router.php
   Na hostingu ovo radi .htaccess, pa se router.php ne prenosi. */
$uri = explode('?', (string)($_SERVER['REQUEST_URI'] ?? '/'), 2)[0]; /* v91: bez parse_url („//data/…” bi pročitao kao poslužitelj „data”) */
/* v91 (recenzija): normalizirana putanja (%64ata, //data, /./data, /img/../data) — inače bi zaobišla zabranu za /data/ */
$uri = rawurldecode($uri);
if (str_contains($uri, '..') || str_contains($uri, "\0")) { http_response_code(400); echo 'Bad Request'; return true; }
$uri = '/' . ltrim(preg_replace('#/(\./)+#', '/', preg_replace('#/+#', '/', $uri)), '/');
if (preg_match('#^/api/(status|reserve|order|confirm|storno|paidclaim|raskid)/?$#', $uri, $m)) { $_GET['a'] = $m[1]; require __DIR__ . '/api/index.php'; return true; }
/* v69 (vijeće na v68, N69-1): kao na hostingu — nepoznata adresa pod /api/ je 404 (inače bi lokalni testovi prošli i
   za radnju koju .htaccess ne propušta) */
if (preg_match('#^/api/(?!index\.php$)#', $uri)) { http_response_code(404); echo 'Not Found'; return true; }
if ($uri === '/admin' || $uri === '/admin/') { require __DIR__ . '/admin/index.php'; return true; }
if (preg_match('#^/data(/|$)#i', $uri)) { http_response_code(403); echo 'Forbidden'; return true; }
if ($uri === '/torbe.json') { header('Cache-Control: no-cache'); }
/* v99 (recenzija vijeća v98, C7): stranica dobiva isti CSP kao iz .htaccess (ako je uz router), da testovi u pregledniku vide
   kršenja pravila (default-src 'self') prije objave */
if (($uri === '/' || $uri === '/index.html') && is_file(__DIR__ . '/.htaccess') && is_file(__DIR__ . '/index.html')
    && preg_match('/^\s*Header\s+setifempty\s+Content-Security-Policy\s+"([^"]+)"/mi', (string)file_get_contents(__DIR__ . '/.htaccess'), $csp)) {
  header('Content-Type: text/html; charset=utf-8'); header('Content-Security-Policy: ' . $csp[1]); header('Cache-Control: no-cache');
  readfile(__DIR__ . '/index.html'); return true;
}
return false;
