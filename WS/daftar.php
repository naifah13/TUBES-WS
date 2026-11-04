<?php 
include 'lib.php'; 

// MENANGKAP SEMUA INPUT DARI FORM PENCARIAN
$search_keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$search_kategori = isset($_GET['kategori']) ? trim($_GET['kategori']) : '';
$search_merek = isset($_GET['merek']) ? trim($_GET['merek']) : '';
$search_lokasi = isset($_GET['lokasi']) ? trim($_GET['lokasi']) : '';
$search_24jam = isset($_GET['buka24jam']) ? trim($_GET['buka24jam']) : '';
$search_rating = isset($_GET['rating_filter']) ? trim($_GET['rating_filter']) : '';

// MENYUSUN LOGIKA FILTER UNTUK SPARQL
$filter_query = '';

if (!empty($search_keyword)) {
    $filter_query .= 'FILTER(contains(lcase(str(?namaShowroom)), lcase("' . addslashes($search_keyword) . '"))) . ';
}
if (!empty($search_kategori)) {
    $filter_query .= 'FILTER(str(?kategori) = "' . addslashes($search_kategori) . '") . ';
}
if (!empty($search_merek)) {
    $filter_query .= 'FILTER(str(?merek) = "' . addslashes($search_merek) . '") . ';
}
if (!empty($search_lokasi)) {
    // Agar pencarian Labuhanbatu tidak salah ambil Labuhanbatu Selatan, filter dibuat eksak jika memungkinkan
    // Namun karena dataset kadang bervariasi pengetikannya, `contains` tetap aman asalkan string-nya utuh
    $filter_query .= 'FILTER(contains(lcase(str(?lokasi)), lcase("' . addslashes($search_lokasi) . '"))) . ';
}
if (!empty($search_24jam)) {
    $filter_query .= 'FILTER(contains(lcase(str(?jam)), "24 jam")) . ';
}
if (!empty($search_rating)) {
    // Memilah kondisi berdasarkan pilihan dropdown rating (atas/bawah 4.5)
    if ($search_rating === 'atas') {
        $filter_query .= 'FILTER(xsd:float(replace(str(?rating), ",", ".")) >= 4.5) . ';
    } elseif ($search_rating === 'bawah') {
        $filter_query .= 'FILTER(xsd:float(replace(str(?rating), ",", ".")) < 4.5) . ';
    }
}

$query = '
    PREFIX ex: <http://www.example.org/showroom#>
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
    
    SELECT ?showroom ?namaShowroom ?kategori ?merek ?lokasi ?rating ?jam
    WHERE {
        ?showroom rdf:type ex:Showroom .
        ?showroom ex:namaShowroom ?namaShowroom .
        OPTIONAL { ?showroom ex:kategori ?kategori . }
        OPTIONAL { ?showroom ex:merek ?merek . }
        OPTIONAL { ?showroom ex:berlokasiDi ?lokasi . }
        OPTIONAL { ?showroom ex:rating ?rating . }
        OPTIONAL { ?showroom ex:jamOperasional ?jam . }
        
        ' . $filter_query . '
    }
    ORDER BY DESC(xsd:float(replace(str(?rating), ",", ".")))
';

$data_sparql = executeSparqlQuery($query);
$is_error = (is_string($data_sparql) && strpos($data_sparql, "Error") !== false);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Penelusuran - Semantic Web KB</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header>
        <h1>ShowroomSumut<span style="color: var(--text-dark); font-size: 16px; margin-left: 5px;">Knowledge Base</span></h1>
        <nav>
            <a href="index.php">Beranda</a>
            <a href="daftar.php" style="color: var(--primary-blue);">Eksplorasi Data</a>
            <a href="export.php">Export RDF</a>
        </nav>
    </header>

    <div class="section-title">
        <h3>Direktori Entitas</h3>
        <h2>Data Kendaraan & Showroom</h2>
        <?php if(!empty($search_keyword) || !empty($search_merek) || !empty($search_lokasi) || !empty($search_24jam) || !empty($search_rating) || !empty($search_kategori)): ?>
            <p style="color: var(--primary-blue); font-weight: bold; background: var(--light-blue); display: inline-block; padding: 5px 15px; border-radius: 20px;">
                Menampilkan hasil berdasarkan filter khusus.
            </p>
        <?php endif; ?>
    </div>

    <!-- LAMAN RDFa -->
    <div class="grid-container" vocab="http://schema.org/">
        
        <?php 
        if ($is_error) {
            echo "<div style='grid-column: 1 / -1; text-align: center; color: red; padding: 20px; border: 1px solid red; border-radius: 8px;'>
                    <h3>Gagal terhubung ke SPARQL Endpoint!</h3>
                    <p>{$data_sparql}</p>
                  </div>";
        } 
        elseif (isset($data_sparql['results']['bindings']) && count($data_sparql['results']['bindings']) > 0) {
            
            $results = $data_sparql['results']['bindings'];

            foreach ($results as $row): 
                $nama = isset($row['namaShowroom']['value']) ? $row['namaShowroom']['value'] : 'Tanpa Nama';
                $kategori = isset($row['kategori']['value']) ? $row['kategori']['value'] : '-';
                $merek = isset($row['merek']['value']) ? $row['merek']['value'] : '-';
                $lokasi = isset($row['lokasi']['value']) ? $row['lokasi']['value'] : 'Sumatera Utara';
                $rating = isset($row['rating']['value']) ? $row['rating']['value'] : '0';
                $jam = isset($row['jam']['value']) ? $row['jam']['value'] : '-';
                $uri = isset($row['showroom']['value']) ? $row['showroom']['value'] : '';
        ?>
        
        <div class="card" typeof="AutoDealer">
            <h3 property="name" style="font-size: 18px; margin-bottom: 5px;"><?= htmlspecialchars($nama) ?></h3>
            <p property="brand" style="color: var(--text-muted); font-size: 14px; font-weight: bold; margin-bottom: 10px;"><?= htmlspecialchars($merek) ?></p>
            
            <p property="areaServed" style="color: var(--text-muted); font-size: 13px; margin-bottom: 5px;">
                📍 Lokasi: <?= htmlspecialchars($lokasi) ?>
            </p>
            <p property="openingHours" style="color: <?= stripos($jam, '24 jam') !== false ? '#2ecc71' : 'var(--text-muted)' ?>; font-size: 13px; margin-bottom: 15px; font-weight: <?= stripos($jam, '24 jam') !== false ? 'bold' : 'normal' ?>;">
                🕒 Jam: <?= htmlspecialchars($jam) ?>
            </p>
            
            <div style="background: var(--bg-light); padding: 8px; border-radius: 4px; display: inline-block; margin-bottom: 15px;">
                <span property="aggregateRating" style="font-weight: bold; color: #f39c12;">★ <?= htmlspecialchars($rating) ?></span>
                <span style="font-size: 12px; color: var(--text-muted); margin-left: 5px; text-transform: uppercase;">(<?= htmlspecialchars($kategori) ?>)</span>
            </div>
            
            <a href="detail.php?uri=<?= urlencode($uri) ?>" class="btn-outline" style="width: 100%; box-sizing: border-box;">Lihat Detail Semantik</a>
        </div>
        
        <?php 
            endforeach; 
        } 
        else {
            echo "<div style='grid-column: 1 / -1; text-align: center; padding: 40px; background: var(--white); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);'>
                    <h3 style='color: var(--text-dark); margin-bottom: 10px;'>Entitas Tidak Ditemukan</h3>
                    <p style='color: var(--text-muted);'>Coba kurangi atau ubah filter pencarian untuk memperluas hasil kueri SPARQL.</p>
                    <a href='index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: var(--primary-blue); color: white; text-decoration: none; border-radius: 4px;'>Reset Pencarian</a>
                  </div>";
        }
        ?>
        
    </div>

    <footer>
        <p>&copy; 2026 Tugas Besar Web Semantik - Kelas KOM B (Kelompok Bhita, Nadin, Nepah, Jepin, Dwik).</p>
    </footer>

</body>
</html>