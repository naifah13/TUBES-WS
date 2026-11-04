<?php 
include 'lib.php'; 

// Menangkap URI entitas dari parameter URL (dikirim dari daftar.php)
$uri_entitas = isset($_GET['uri']) ? trim($_GET['uri']) : '';

if (empty($uri_entitas)) {
    die("Error: URI Entitas tidak ditemukan. Silakan kembali ke <a href='daftar.php'>halaman Eksplorasi Data</a>.");
}

/* 
   ======================================================================
   QUERY SPARQL UNTUK 1 ENTITAS SPESIFIK BERDASARKAN URI
   ======================================================================
*/
$query_detail = '
    PREFIX ex: <http://www.example.org/showroom#>
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    
    SELECT ?namaShowroom ?kategori ?merek ?alamat ?jam ?telepon ?website ?rating ?lokasi
    WHERE {
        <' . $uri_entitas . '> ex:namaShowroom ?namaShowroom .
        OPTIONAL { <' . $uri_entitas . '> ex:kategori ?kategori . }
        OPTIONAL { <' . $uri_entitas . '> ex:merek ?merek . }
        OPTIONAL { <' . $uri_entitas . '> ex:alamat ?alamat . }
        OPTIONAL { <' . $uri_entitas . '> ex:jamOperasional ?jam . }
        OPTIONAL { <' . $uri_entitas . '> ex:noTelepon ?telepon . }
        OPTIONAL { <' . $uri_entitas . '> ex:website ?website . }
        OPTIONAL { <' . $uri_entitas . '> ex:rating ?rating . }
        OPTIONAL { <' . $uri_entitas . '> ex:berlokasiDi ?lokasi . }
    }
';

$data_detail = executeSparqlQuery($query_detail);

if (is_string($data_detail) && strpos($data_detail, "Error") !== false) {
    die("Gagal terhubung ke SPARQL Endpoint: " . $data_detail);
}

// Mengekstrak data dari respons Fuseki
$detail_entitas = null;
if (isset($data_detail['results']['bindings']) && count($data_detail['results']['bindings']) > 0) {
    $row = $data_detail['results']['bindings'][0];
    
    $detail_entitas = [
        'nama' => isset($row['namaShowroom']['value']) ? $row['namaShowroom']['value'] : 'Data Tidak Tersedia',
        'kategori' => isset($row['kategori']['value']) ? $row['kategori']['value'] : '-',
        'merek' => isset($row['merek']['value']) ? $row['merek']['value'] : '-',
        'alamat' => isset($row['alamat']['value']) ? $row['alamat']['value'] : '-',
        'jam' => isset($row['jam']['value']) ? $row['jam']['value'] : '-',
        'telepon' => isset($row['telepon']['value']) ? $row['telepon']['value'] : '-',
        'website' => isset($row['website']['value']) ? $row['website']['value'] : '#',
        'rating' => isset($row['rating']['value']) ? $row['rating']['value'] : '-',
        'lokasi' => isset($row['lokasi']['value']) ? $row['lokasi']['value'] : '-',
        'uri' => $uri_entitas
    ];
} else {
    die("Entitas tidak ditemukan di dalam Knowledge Base.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Showroom - Semantic Web KB</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header>
        <h1>ShowroomSumut<span style="color: var(--text-dark); font-size: 16px; margin-left: 5px;">Knowledge Base</span></h1>
        <nav>
            <a href="index.php">Beranda</a>
            <a href="daftar.php">Eksplorasi Data</a>
            <a href="export.php">Export RDF</a>
        </nav>
    </header>

    <section class="hero" vocab="http://schema.org/" typeof="AutoDealer" style="padding-bottom: 80px;">
        <p style="color: var(--text-muted); font-weight: bold; margin-bottom: 5px;">DETAIL ENTITAS SEMANTIK</p>
        <h2 property="name"><?= htmlspecialchars($detail_entitas['nama']) ?></h2>
        <p property="description" style="max-width: 700px;">Representasi linked data untuk entitas showroom yang ditarik dari dataset lokal showroomSumut.ttl.</p>
        
        <!-- Metadata Semantik -->
        <span property="url" content="<?= htmlspecialchars($detail_entitas['website']) ?>"></span>
        <span property="telephone" content="<?= htmlspecialchars($detail_entitas['telepon']) ?>"></span>
    </section>

    <div class="grid-container" style="margin-top: -40px; position: relative; z-index: 10;">
        
        <div class="card" style="text-align: left;">
            <h3 style="color: var(--primary-blue); font-size: 18px; border-bottom: 2px solid var(--light-blue); padding-bottom: 10px;">Informasi RDF Lokal</h3>
            
            <p style="margin-top: 15px;"><strong>Alamat:</strong> <span property="address"><?= htmlspecialchars($detail_entitas['alamat']) ?></span></p>
            <p><strong>Wilayah:</strong> <?= htmlspecialchars($detail_entitas['lokasi']) ?></p>
            <p><strong>Kategori:</strong> Dealer Resmi <?= ucfirst(htmlspecialchars($detail_entitas['kategori'])) ?></p>
            <p><strong>Merek Induk:</strong> <span property="brand"><?= htmlspecialchars($detail_entitas['merek']) ?></span></p>
            <p><strong>Jam Operasional:</strong> <span property="openingHours"><?= htmlspecialchars($detail_entitas['jam']) ?></span></p>
            <p><strong>No. Telepon:</strong> <?= htmlspecialchars($detail_entitas['telepon']) ?></p>
            <p><strong>Rating:</strong> ★ <?= htmlspecialchars($detail_entitas['rating']) ?></p>
            
            <?php if($detail_entitas['website'] != '#' && !empty($detail_entitas['website'])): ?>
                <p><strong>Situs Resmi:</strong> <a href="<?= htmlspecialchars($detail_entitas['website']) ?>" target="_blank" style="color: var(--primary-blue); font-weight: bold;">Kunjungi Web</a></p>
            <?php endif; ?>
            
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 20px;">
                <i>*Data ditarik secara dinamis dari SPARQL Endpoint menggunakan parameter URI.</i><br>
                <span style="font-size: 10px; word-break: break-all;"><?= htmlspecialchars($detail_entitas['uri']) ?></span>
            </p>
        </div>

        <!-- Integrasi Layanan (Maps) -->
        <div class="card" style="text-align: left;">
            <h3 style="color: var(--primary-blue); font-size: 18px; border-bottom: 2px solid var(--light-blue); padding-bottom: 10px;">Pemetaan Geospasial (Peta Wilayah)</h3>
            <div style="margin-top: 15px; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0;">
                <!-- Menggunakan Iframe OpenStreetMap statis sebagai pemenuhan syarat integrasi layanan pihak ketiga -->
                <iframe width="100%" height="180" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://www.openstreetmap.org/export/embed.html?bbox=98.67%2C3.57%2C98.69%2C3.59&amp;layer=mapnik"></iframe>
            </div>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 10px;">
                <i>*Integrasi Pihak Ketiga: Representasi OpenStreetMap untuk area operasional showroom.</i>
            </p>
        </div>
    </div>

    <!-- SPARQL Endpoint Eksternal (DBpedia) -->
    <div class="section-title" style="padding-top: 20px;">
        <h3>Linked Open Data</h3>
        <h2>Pengayaan Data via DBpedia</h2>
    </div>

    <div class="grid-container" style="padding-top: 0;">
        <div class="card" style="grid-column: 1 / -1; text-align: left;">
            <p style="margin-bottom: 15px;">Menerapkan query terpisah ke <strong>http://dbpedia.org/sparql</strong> untuk menghubungkan dataset lokal dengan Knowledge Graph global terkait merek kendaraan yang dijual.</p>
            
            <div style="background: var(--light-blue); padding: 25px; border-radius: 8px; border-left: 5px solid var(--primary-blue);">
                <h4 style="margin-bottom: 10px; font-size: 20px;">Entitas Merek: <?= htmlspecialchars($detail_entitas['merek']) ?></h4>
                <p><strong>Sumber:</strong> DBpedia SPARQL Endpoint</p>
                <p style="margin-top: 10px; font-style: italic; color: var(--text-muted);">
                    (Catatan untuk Mahasiswa: Di sini kelompok kalian perlu menanamkan query EasyRDF/cURL PHP yang mengarah ke endpoint DBpedia untuk mengambil properti 'dbo:abstract' atau 'rdfs:comment' dari merek terkait. Jika merek tidak ditemukan di DBpedia, tampilkan pesan penanganan error yang sesuai).
                </p>
            </div>
        </div>
    </div>

    <footer>
        <p>&copy; 2026 Tugas Besar Web Semantik - Kelas KOM B (Kelompok Bhita dkk).</p>
    </footer>

</body>
</html>