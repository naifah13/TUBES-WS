<?php require 'lib.php';
$id = (int)($_GET['id'] ?? 0); $uri = NS . 'Showroom' . $id;
try { $r = sparql('SELECT ' . COLS . ' WHERE { VALUES ?s { <' . $uri . '> } ' . BGP . ' }')[0] ?? null; } catch (Throwable $e) { fail($e); }
if (!$r) { http_response_code(404); top('Tidak ditemukan'); ?>
<section class="wrap state"><h1>Showroom tidak ditemukan</h1><p>Nomor <?= $id ?> tidak ada di dataset. <a href="daftar.php"><u>Lihat daftar showroom</u></a>.</p></section>
<?php bottom(); exit; }

// Integrasi dataset eksternal: DBpedia (profil merek)
$b = merek($r['merek']);
$DB = ['Mitsubishi' => 'Mitsubishi_Motors', 'Yamaha' => 'Yamaha_Motor_Company', 'Kawasaki' => 'Kawasaki_Heavy_Industries', 'Honda Wing' => 'Honda', 'BYD' => 'BYD_Company', 'MG' => 'MG_Motor', 'Wuling' => 'SAIC-GM-Wuling_Automobile'];
$iri = '<http://dbpedia.org/resource/' . rawurlencode(str_replace(' ', '_', $DB[$b] ?? $b)) . '>';
try { $d = sparql('PREFIX dbo:<http://dbpedia.org/ontology/> SELECT ?abs WHERE { { ' . $iri . ' dbo:abstract ?abs } UNION { ' . $iri . ' dbo:wikiPageRedirects ?x . ?x dbo:abstract ?abs } FILTER(lang(?abs)="en") } LIMIT 1', DBPEDIA)[0] ?? null; } catch (Throwable $e) { $d = null; }

// Integrasi layanan lain: OpenStreetMap (Nominatim + peta tersemat)
function geo($q) { try { return json_decode(http('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=id&q=' . urlencode($q)), true)[0] ?? null; } catch (Throwable $e) { return null; } }
$g = geo($r['alamat'] . ', ' . kota($r['kota'])) ?? geo($r['nama'] . ', ' . kota($r['kota']));
$w = preg_match('~^https?://~i', $r['web']) ? $r['web'] : '#';
top($r['nama'], ['type' => 'business.business', 'description' => merek($r['merek']) . ' di ' . kota($r['kota']) . ', ' . $r['alamat'] . '. Jam buka: ' . $r['jam'] . '.']); ?>
<article class="wrap" prefix="ex: <?= NS ?>" resource="<?= $uri ?>" typeof="ex:Showroom">
  <div class="top"><a class="crumb" href="daftar.php?kat=<?= h($r['kat']) ?>">Showroom <?= h($r['kat']) ?></a>
    <h1><?= rd('namaShowroom', $r['nama']) ?></h1>
    <p><?= rd('merek', $r['merek'], $b) ?>, <?= rd('berlokasiDi', $r['kota'], kota($r['kota'])) ?>. Rating <?= rd('rating', $r['rating'], rate($r['rating'])) ?></p></div>
  <div class="dgrid">
    <div>
      <dl>
        <div><dt>Alamat</dt><dd><?= rd('alamat', $r['alamat']) ?></dd></div>
        <div><dt>Jam buka</dt><dd><?= rd('jamOperasional', $r['jam']) ?></dd></div>
        <div><dt>Telepon</dt><dd><?= $r['telp'] === '' ? 'Tidak tercantum di data' : '<a href="tel:' . h(preg_replace('/[^\d+]/', '', $r['telp'])) . '">' . rd('noTelepon', $r['telp']) . '</a>' ?></dd></div>
        <div><dt>Website</dt><dd><a href="<?= h($w) ?>" target="_blank" rel="noopener"><?= rd('website', $r['web'], preg_replace('~^https?://(www\.)?|/$~', '', $r['web'])) ?></a></dd></div>
        <div><dt>Jenis</dt><dd><?= rd('kategori', $r['kat']) ?></dd></div>
      </dl>
      <div class="dl"><a class="btn" href="export.php?id=<?= $id ?>&amp;format=ttl">Unduh Turtle</a><a class="btn o" href="export.php?id=<?= $id ?>&amp;format=rdf">RDF/XML</a><a class="btn o" href="export.php?id=<?= $id ?>&amp;format=json">JSON-LD</a></div>
      <div class="box"><h3>Tentang <?= h($b) ?></h3>
        <?= $d ? '<p>' . h(mb_strimwidth($d['abs'], 0, 460, '...')) . '</p><p class="note">Sumber: <a href="' . h(trim($iri, '<>')) . '" target="_blank" rel="noopener"><u>DBpedia</u></a></p>'
               : '<p>Profil merek ini belum ditemukan di DBpedia, atau DBpedia sedang tidak terjangkau.</p>' ?></div>
    </div>
    <div>
      <?php if ($g): $la = (float)$g['lat']; $lo = (float)$g['lon']; ?>
        <iframe title="Peta lokasi <?= h($r['nama']) ?>" loading="lazy" src="https://www.openstreetmap.org/export/embed.html?bbox=<?= $lo - .006 ?>%2C<?= $la - .004 ?>%2C<?= $lo + .006 ?>%2C<?= $la + .004 ?>&amp;layer=mapnik&amp;marker=<?= $la ?>%2C<?= $lo ?>"></iframe>
        <p class="note">Posisi diperkirakan dari alamat lewat Nominatim, jadi bisa meleset. <a href="https://www.openstreetmap.org/?mlat=<?= $la ?>&amp;mlon=<?= $lo ?>#map=17/<?= $la ?>/<?= $lo ?>" target="_blank" rel="noopener"><u>Buka di OpenStreetMap</u></a></p>
      <?php else: ?>
        <div class="box"><h3>Peta</h3><p>Alamat ini belum bisa ditemukan otomatis. <a href="https://www.openstreetmap.org/search?query=<?= urlencode($r['nama'] . ' ' . kota($r['kota'])) ?>" target="_blank" rel="noopener"><u>Cari manual di OpenStreetMap</u></a>.</p></div>
      <?php endif ?>
    </div>
  </div>
</article>
<?php bottom();
