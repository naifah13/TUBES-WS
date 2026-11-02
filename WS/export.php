<?php require 'lib.php';
// File RDF: hasil CONSTRUCT dari Fuseki (satu showroom, atau seluruh dataset bila tanpa id)
$fm = ['ttl' => ['text/turtle', 'ttl'], 'rdf' => ['application/rdf+xml', 'rdf'], 'json' => ['application/ld+json', 'jsonld']];
$f = $fm[$_GET['format'] ?? 'ttl'] ?? $fm['ttl']; $id = (int)($_GET['id'] ?? 0);
$s = $id ? '<' . NS . 'Showroom' . $id . '>' : '?s';
try { $body = http(FUSEKI . '?query=' . urlencode(PFX . "CONSTRUCT { $s ?p ?o } WHERE { $s ?p ?o }"), $f[0]); }
catch (Throwable $e) { http_response_code(502); exit('Fuseki belum terjangkau: ' . $e->getMessage()); }
header('Content-Type: ' . $f[0] . '; charset=utf-8');
header('Content-Disposition: attachment; filename="showroom' . ($id ? "-$id" : '') . '.' . $f[1] . '"');
echo $body;
