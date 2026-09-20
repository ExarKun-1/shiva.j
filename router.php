<?php
/* Samo za lokalno testiranje s ugrađenim PHP poslužiteljem:
     php -S 127.0.0.1:8090 -t <mapa> router.php
   Na hostingu ovo radi .htaccess, pa se router.php ne prenosi. */
$uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if (preg_match('#^/api/(status|reserve|order|storno)/?$#', $uri, $m)) { $_GET['a'] = $m[1]; require __DIR__ . '/api/index.php'; return true; }
if ($uri === '/admin' || $uri === '/admin/') { require __DIR__ . '/admin/index.php'; return true; }
if (preg_match('#^/data/#', $uri)) { http_response_code(403); echo 'Forbidden'; return true; }
if ($uri === '/torbe.json') { header('Cache-Control: no-cache'); }
return false;
