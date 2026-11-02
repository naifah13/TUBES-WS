<?php require 'lib.php';
try {
    $c = array_column(sparql('SELECT ?kat (COUNT(?s) AS ?n) WHERE { ?s a ex:Showroom ; ex:kategori ?kat } GROUP BY ?kat'), 'n', 'kat');
    $top = sparql('SELECT ' . COLS . ' WHERE { ' . BGP . ' } ORDER BY RAND() LIMIT 6');
    $m = sparql('SELECT ?merek (COUNT(?s) AS ?n) WHERE { ?s ex:merek ?merek } GROUP BY ?merek');
} catch (Throwable $e) { fail($e); }
$b = []; foreach ($m as $x) $b[merek($x['merek'])] = ($b[merek($x['merek'])] ?? 0) + $x['n'];
arsort($b); $tot = array_sum($c);
top('Beranda'); ?>
<section class="wrap hero">
  <div>
    <h1>Temukan showroom mobil dan motor di Sumatera Utara.</h1>
    <p class="lead"><?= $tot ?> showroom dari <?= count($b) ?> merek, dibaca langsung dari dataset RDF lewat SPARQL.</p>
    <form class="search" action="daftar.php"><input name="q" placeholder="Nama, merek, atau alamat" aria-label="Cari showroom"><button class="btn">Cari</button></form>
  </div>
  <div class="cats">
    <a class="cat" href="daftar.php?kat=mobil"><small>Showroom mobil</small><b><?= $c['mobil'] ?? 0 ?></b></a>
    <a class="cat b" href="daftar.php?kat=motor"><small>Showroom motor</small><b><?= $c['motor'] ?? 0 ?></b></a>
  </div>
</section>
<section class="wrap sec">
  <div class="sechead"><h2>Rating tertinggi</h2><a class="btn o" href="daftar.php">Semua showroom</a></div>
  <div class="list"><?php thead(); foreach ($top as $r) row($r); ?></div>
</section>
<section class="wrap sec">
  <div class="sechead"><h2>Telusuri per merek</h2></div>
  <div class="chips"><?php foreach (array_slice($b, 0, 14, true) as $nm => $n): ?><a href="daftar.php?merek=<?= urlencode($nm) ?>"><?= h($nm) ?><span><?= $n ?></span></a><?php endforeach ?></div>
</section>
<?php bottom();