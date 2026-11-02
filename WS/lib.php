<?php
// Ubah FUSEKI bila port/nama dataset berbeda.
const FUSEKI = 'http://localhost:3030/showroom/sparql';
const DBPEDIA = 'https://dbpedia.org/sparql';
const NS = 'http://www.example.org/showroom#';
const PFX = 'PREFIX ex: <http://www.example.org/showroom#> PREFIX xsd: <http://www.w3.org/2001/XMLSchema#> ';
const COLS = '?s ?nama ?merek ?alamat ?jam ?telp ?web ?rating ?kota ?kat';
const BGP = '?s a ex:Showroom ; ex:namaShowroom ?nama ; ex:merek ?merek ; ex:alamat ?alamat ; ex:jamOperasional ?jam ; ex:noTelepon ?telp ; ex:website ?web ; ex:rating ?rating ; ex:berlokasiDi ?kota ; ex:kategori ?kat .';
// Penulisan kota di data tidak seragam; digabung ke satu nama.
const ALIAS = ['Siantar' => 'Pematangsiantar', 'Pematang Siantar' => 'Pematangsiantar', 'Padang Sidempuan' => 'Padangsidimpuan', 'Gunung Sitoli' => 'Gunungsitoli'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function lit($s) { return '"' . addcslashes($s, "\"\\\r\n") . '"'; }
function kota($k) { return ALIAS[$k] ?? $k; }
function variants($k) { return array_merge([$k], array_keys(array_filter(ALIAS, fn($v) => $v === $k))); }
function merek($m) { return trim(preg_replace('/^Dealer\s+/i', '', $m)); }
function rate($r) { return number_format((float)str_replace(',', '.', $r), 1); }
function sid($u) { return (int)preg_replace('/\D/', '', substr(strrchr($u, '#'), 1)); }
function rd($p, $raw, $shown = null) { return '<span property="ex:' . $p . '" content="' . h($raw) . '">' . h($shown ?? $raw) . '</span>'; }

function http($url, $accept = 'application/json') {
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true,
        'header' => "Accept: $accept\r\nUser-Agent: ShowroomSumut/1.0 (tugas web semantik USU)\r\n"]]);
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) throw new RuntimeException('Tidak bisa terhubung ke ' . strtok($url, '?'));
    return $r;
}
function sparql($q, $ep = FUSEKI) {
    $j = json_decode(http($ep . '?query=' . urlencode($ep === FUSEKI ? PFX . $q : $q), 'application/sparql-results+json'), true);
    if (!isset($j['results'])) throw new RuntimeException('Respons SPARQL tidak valid dari ' . $ep);
    return array_map(fn($b) => array_map(fn($v) => $v['value'], $b), $j['results']['bindings']);
}

function top($title, $og = []) {
    $u = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
    $og += ['title' => $title, 'type' => 'website', 'url' => $u,
        'description' => 'Direktori showroom mobil dan motor di Sumatera Utara, dibaca langsung dari dataset RDF di Apache Jena Fuseki.'];
    $cur = basename($_SERVER['SCRIPT_NAME']); $k = $_GET['kat'] ?? '';
    $here = $cur . ($cur === 'daftar.php' && in_array($k, ['mobil', 'motor'], true) ? '?kat=' . $k : '');
    $nav = ['index.php' => 'Beranda', 'daftar.php?kat=mobil' => 'Mobil', 'daftar.php?kat=motor' => 'Motor', 'daftar.php' => 'Semua showroom', 'export.php' => 'Unduh RDF'];
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> | Showroom Sumut</title>
<?php foreach ($og as $p => $v): ?><meta property="og:<?= $p ?>" content="<?= h($v) ?>">
<?php endforeach ?><meta property="og:site_name" content="Showroom Sumut"><meta property="og:locale" content="id_ID">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css"></head><body>
<header class="wrap"><a class="logo" href="index.php">Showroom Sumut</a><ul>
<?php foreach ($nav as $u => $t): ?><li><a href="<?= $u ?>"<?= $u === $here ? ' aria-current="page"' : '' ?>><?= $t ?></a></li>
<?php endforeach ?></ul></header><main>
<?php }

function bottom() { ?></main>
<footer class="wrap"><p>Data dibaca dengan SPARQL dari Apache Jena Fuseki. Profil merek dari DBpedia, peta dari OpenStreetMap.</p>
<a href="export.php?format=ttl">Unduh seluruh dataset (Turtle)</a></footer></body></html>
<?php }

function fail($e) {
    top('Data belum bisa dimuat'); ?>
<section class="wrap state"><h1>Fuseki belum terjangkau</h1><p><?= h($e->getMessage()) ?></p>
<p>Jalankan Apache Jena Fuseki, pastikan dataset <b>showroom</b> sudah berisi <code>showroomSumut.ttl</code>, lalu cek alamat endpoint di <code>lib.php</code> (sekarang <code><?= h(FUSEKI) ?></code>).</p></section>
<?php bottom(); exit; }

function row($r) { ?><a class="row" href="detail.php?id=<?= sid($r['s']) ?>"><span class="nm"><b><?= h($r['nama']) ?></b><small><?= h($r['alamat']) ?></small></span>
<span><?= h(merek($r['merek'])) ?></span><span><?= h(kota($r['kota'])) ?></span><span><?= ucfirst(h($r['kat'])) ?></span><span><?= h($r['jam']) ?></span><span class="rt"><?= rate($r['rating']) ?></span></a>
<?php }
function thead() { ?><div class="row th"><span>Showroom</span><span>Merek</span><span>Kota</span><span>Kategori</span><span>Jam buka</span><span class="rt">Rating</span></div><?php }