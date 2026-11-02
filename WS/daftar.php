<?php require 'lib.php';
$q = trim($_GET['q'] ?? ''); $kat = in_array($_GET['kat'] ?? '', ['mobil', 'motor'], true) ? $_GET['kat'] : '';
$kt = trim($_GET['kota'] ?? ''); $mk = trim($_GET['merek'] ?? ''); $p = max(1, (int)($_GET['p'] ?? 1)); $per = 12;
$f = '';
if ($q !== '') $f .= ' FILTER(CONTAINS(LCASE(CONCAT(?nama," ",?merek," ",?alamat)),LCASE(' . lit($q) . ')))';
if ($kat) $f .= ' FILTER(?kat=' . lit($kat) . ')';
if ($kt) $f .= ' FILTER(?kota IN (' . implode(',', array_map('lit', variants($kt))) . '))';
if ($mk) $f .= ' FILTER(REGEX(?merek,' . lit('^Dealer\s+' . preg_quote($mk, '/') . '\s*$') . '))';
try {
    $n = (int)sparql('SELECT (COUNT(*) AS ?n) WHERE { ' . BGP . $f . ' }')[0]['n'];
    $rows = sparql('SELECT ' . COLS . ' WHERE { ' . BGP . $f . ' } ORDER BY DESC(xsd:decimal(REPLACE(?rating,",","."))) ?nama LIMIT ' . $per . ' OFFSET ' . (($p - 1) * $per));
    $kl = []; foreach (sparql('SELECT DISTINCT ?k WHERE { ?s ex:berlokasiDi ?k }') as $x) $kl[kota($x['k'])] = 1; ksort($kl);
    $ml = []; foreach (sparql('SELECT DISTINCT ?m WHERE { ?s ex:merek ?m }') as $x) $ml[merek($x['m'])] = 1; ksort($ml);
} catch (Throwable $e) { fail($e); }
$pages = max(1, (int)ceil($n / $per));
top($kat ? "Showroom $kat" : 'Semua showroom'); ?>
<section class="wrap top">
  <h1><?= $kat ? 'Showroom ' . h($kat) : 'Semua showroom' ?></h1>
  <p><?= $n ?> showroom ditemukan, diurutkan dari rating tertinggi.</p>
  <form class="filter">
    <input name="q" value="<?= h($q) ?>" placeholder="Nama, merek, atau alamat" aria-label="Kata kunci">
    <select name="kat" aria-label="Jenis"><option value="">Mobil dan motor</option><?php foreach (['mobil', 'motor'] as $o): ?><option <?= $kat === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach ?></select>
    <select name="kota" aria-label="Kota"><option value="">Semua kota</option><?php foreach ($kl as $o => $_): ?><option <?= $kt === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach ?></select>
    <select name="merek" aria-label="Merek"><option value="">Semua merek</option><?php foreach ($ml as $o => $_): ?><option <?= $mk === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach ?></select>
    <button class="btn">Terapkan</button>
  </form>
  <?php if (!$rows): ?>
    <div class="state"><h2>Tidak ada showroom yang cocok</h2><p>Coba kurangi filter atau ganti kata kunci, atau <a href="daftar.php"><u>tampilkan semua showroom</u></a>.</p></div>
  <?php else: ?>
    <div class="list"><?php thead(); foreach ($rows as $r) row($r); ?></div>
    <nav class="pager" aria-label="Halaman"><?php for ($i = 1; $i <= $pages; $i++) if ($i == 1 || $i == $pages || abs($i - $p) <= 2): ?>
      <a href="?<?= h(http_build_query(array_filter(['q' => $q, 'kat' => $kat, 'kota' => $kt, 'merek' => $mk, 'p' => $i > 1 ? $i : null]))) ?>"<?= $i == $p ? ' aria-current="page"' : '' ?>><?= $i ?></a><?php endif ?></nav>
  <?php endif ?>
</section>
<?php bottom();
