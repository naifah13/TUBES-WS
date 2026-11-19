<?php 
include 'lib.php'; 

// Menangkap URI entitas dari parameter URL
$uri_entitas = isset($_GET['uri']) ? trim($_GET['uri']) : '';

if (empty($uri_entitas)) {
    die("Error: URI Entitas tidak ditemukan. Silakan kembali ke <a href='index.php'>halaman Beranda</a>.");
}

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

    <title><?= htmlspecialchars($detail_entitas['nama']) ?> | Vroom</title>

    <link rel="icon" type="image/svg+xml" href="images/vroom.svg">
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <!-- NAVBAR DIPERBARUI (Judul bisa diklik, menu kanan dibersihkan) -->
    <header class="vroom-header">

        <a href="index.php" class="vroom-brand"
        aria-label="Vroom - Beranda">

            <img src="images/vroom.svg" alt="Logo Vroom">

            <span class="vroom-brand-text">
                <strong>vroom<span>.</span></strong>
            </span>
        </a>

        <a href="index.php" class="detail-back-link">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
        <path d="M19 12H5"></path>
        <path d="m12 19-7-7 7-7"></path>
    </svg>

    <span>Kembali ke Beranda</span>
        </a>

    </header>
    
    <section class="hero" vocab="http://schema.org/" typeof="AutoDealer" style="padding-bottom: 80px;">
        <p style="color: var(--text-muted); font-weight: bold; margin-bottom: 5px;">DETAIL ENTITAS SEMANTIK</p>
        <h2 property="name" style="font-size: 36px;"><?= htmlspecialchars($detail_entitas['nama']) ?></h2>
        <p property="description" style="max-width: 700px; font-size: 16px;">Representasi linked data untuk entitas showroom yang ditarik dari dataset lokal showroomSumut.ttl.</p>
        
        <!-- Metadata Semantik -->
        <span property="url" content="<?= htmlspecialchars($detail_entitas['website']) ?>"></span>
        <span property="telephone" content="<?= htmlspecialchars($detail_entitas['telepon']) ?>"></span>
    </section>

    <div class="grid-container detail-info-layout" style="margin-top: -40px; position: relative; z-index: 10;">
        
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
            <h3 style="color: var(--primary-blue); font-size: 18px; border-bottom: 2px solid var(--light-blue); padding-bottom: 10px;">Peta Wilayah</h3>
            <div style="margin-top: 15px; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0;">
                <!-- Menggunakan Iframe OpenStreetMap statis sebagai pemenuhan syarat integrasi layanan pihak ketiga -->
                <iframe width="100%" height="180" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://www.openstreetmap.org/export/embed.html?bbox=98.67%2C3.57%2C98.69%2C3.59&amp;layer=mapnik"></iframe>
            </div>
            <p style="font-size: 12px; color: var(--text-muted); margin-top: 10px;">
                <i>*Integrasi Pihak Ketiga: Representasi OpenStreetMap untuk area operasional showroom.</i>
            </p>
        </div>
    </div>
    
    
<!-- KATALOG KENDARAAN SHOWROOM -->
<section class="detail-katalog" id="katalog-kendaraan">

    <div class="detail-katalog-heading">
        <span class="detail-katalog-label">
            KATALOG SHOWROOM
        </span>

        <h2>Kendaraan yang Tersedia</h2>

        <p>
            Jelajahi pilihan kendaraan dari showroom ini.
            Informasi kendaraan akan segera ditambahkan.
        </p>
    </div>

    <div class="detail-katalog-grid">

        <!-- CARD KENDARAAN 1 -->
        <article class="detail-kendaraan-card">

            <div class="detail-kendaraan-foto">
                <span>Foto Kendaraan</span>
            </div>

            <div class="detail-kendaraan-info">
                <span class="detail-kendaraan-tag">
                    Katalog Kendaraan
                </span>

                <h3>Nama Kendaraan</h3>

                <p>
                    Deskripsi kendaraan akan ditambahkan
                    oleh tim Vroom.
                </p>

                <div class="detail-kendaraan-harga">
                    Harga belum tersedia
                </div>
            </div>

        </article>

        <!-- CARD KENDARAAN 2 -->
        <article class="detail-kendaraan-card">

            <div class="detail-kendaraan-foto">
                <span>Foto Kendaraan</span>
            </div>

            <div class="detail-kendaraan-info">
                <span class="detail-kendaraan-tag">
                    Katalog Kendaraan
                </span>

                <h3>Nama Kendaraan</h3>

                <p>
                    Deskripsi kendaraan akan ditambahkan
                    oleh tim Vroom.
                </p>

                <div class="detail-kendaraan-harga">
                    Harga belum tersedia
                </div>
            </div>

        </article>

        <!-- CARD KENDARAAN 3 -->
        <article class="detail-kendaraan-card">

            <div class="detail-kendaraan-foto">
                <span>Foto Kendaraan</span>
            </div>

            <div class="detail-kendaraan-info">
                <span class="detail-kendaraan-tag">
                    Katalog Kendaraan
                </span>

                <h3>Nama Kendaraan</h3>

                <p>
                    Deskripsi kendaraan akan ditambahkan
                    oleh tim Vroom.
                </p>

                <div class="detail-kendaraan-harga">
                    Harga belum tersedia
                </div>
            </div>

        </article>

    </div>

    <p class="detail-katalog-catatan">
        *Katalog kendaraan masih dalam tahap pengembangan.
    </p>

</section>


    <!-- FOOTER VROOM -->
<footer class="vroom-footer">

    <div class="footer-container">

        <!-- LOGO DAN DESKRIPSI -->
        <div class="footer-column footer-about">

            <a href="index.php" class="footer-brand" aria-label="Vroom - Beranda">
                <img src="images/vroom.svg" alt="Logo Vroom">
                <strong>vroom<span>.</span></strong>
            </a>

            <div class="footer-heading-line"></div>

            <p>
                Direktori showroom mobil dan motor di Sumatera Utara
                berbasis teknologi web semantik.
            </p>

        </div>

        <!-- LINK CEPAT -->
        <div class="footer-column footer-links">

            <h3>Link Cepat</h3>

            <div class="footer-heading-line"></div>

            <nav aria-label="Link cepat footer">
                <a href="index.php">Beranda</a>
                <a href="index.php#hasil">Showroom</a>
                <a href="index.php#tim">Tim Kami</a>
                <a href="index.php#tentang">Tentang Kami</a>
            </nav>

        </div>

        <!-- JELAJAHI SHOWROOM -->
        <div class="footer-column footer-explore">

            <h3>Jelajahi Showroom</h3>

            <div class="footer-heading-line"></div>

            <div class="footer-explore-card">

                <img
                    src="images/banner.jpg"
                    alt="Deretan kendaraan di dalam showroom"
                    loading="lazy"
                >

                <div class="footer-explore-content">

                    <p>Temukan showroom pilihanmu di Sumatera Utara.</p>

                    <a href="index.php#hasil">
                        Lihat Showroom <span aria-hidden="true">→</span>
                    </a>

                </div>

            </div>

        </div>

    </div>

    <div class="footer-bottom">
        <p>
            &copy; <?= date('Y') ?> vroom.
            Web Semantik Showroom Kendaraan Sumatera Utara.
        </p>
    </div>

</footer>

</body>
</html>