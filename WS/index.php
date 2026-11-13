<?php 
include 'lib.php'; 

$search_keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

$filter_query = '';
if (!empty($search_keyword)) {
    $keyword_clean = addslashes($search_keyword);
    // FITUR SEMANTIK TINGKAT LANJUT: 
    // Mencari 1 kata kunci ke 4 properti berbeda sekaligus (Nama, Merek, Alamat, Wilayah)
    $filter_query .= 'FILTER(
        contains(lcase(str(?namaShowroom)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?merek)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?alamat)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?lokasi)), lcase("' . $keyword_clean . '"))
    ) . ';
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
        
        # Properti alamat dipanggil agar bisa dibaca oleh mesin pencari
        OPTIONAL { ?showroom ex:alamat ?alamat . }
        
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
    <title>ShowroomSumut - Semantic Web KB</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header style="display: flex; justify-content: space-between; align-items: center; padding: 15px 5%;">
        
        <a href="index.php" style="text-decoration: none;">
            <h1>ShowroomSumut<span style="font-size: 14px; color: var(--text-dark); margin-left: 5px;">Knowledge Base</span></h1>
        </a>
        
        <form action="index.php" method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 600px; margin: 0 20px;">
            <!-- Placeholder diubah untuk memberi tahu user bahwa pencarian ini canggih -->
            <input type="text" name="keyword" placeholder="Cari nama, merek, atau jalan (misal: Auto2000, Honda, Gatot Subroto)..." value="<?= htmlspecialchars($search_keyword) ?>" style="flex: 1; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
            <button type="submit" style="background: var(--primary-blue); color: white; border: none; padding: 10px 24px; border-radius: 4px; font-weight: bold; cursor: pointer; transition: 0.3s;">Cari</button>
        </form>

        <nav>
            <a href="export.php" style="color: var(--text-dark); font-weight: 600;">Export RDF</a>
        </nav>
    </header>

    <section class="hero" style="padding: 60px 5%; background: linear-gradient(135deg, var(--light-blue) 0%, var(--white) 100%);">
        <h2 style="font-size: 36px; color: var(--primary-blue); margin-bottom: 10px;">Eksplorasi Data<br>Kendaraan Sumut.</h2>
        <p style="color: var(--text-muted); font-size: 16px; max-width: 500px;">Sistem basis pengetahuan (Knowledge Base) terintegrasi menggunakan teknologi Web Semantik untuk direktori showroom di Sumatera Utara.</p>
    </section>

    <div class="grid-container" vocab="http://schema.org/" style="padding: 40px 5%;">
        
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
        
        <div class="card" typeof="AutoDealer" style="border: 1px solid #eee; border-radius: 8px; padding: 20px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
            <h3 property="name" style="font-size: 18px; margin-bottom: 5px; color: var(--text-dark);"><?= htmlspecialchars($nama) ?></h3>
            <p property="brand" style="color: var(--text-muted); font-size: 14px; font-weight: bold; margin-bottom: 15px;"><?= htmlspecialchars($merek) ?></p>
            
            <p property="areaServed" style="color: var(--text-muted); font-size: 13px; margin-bottom: 5px;">📍 Lokasi: <?= htmlspecialchars($lokasi) ?></p>
            <p property="openingHours" style="color: <?= stripos($jam, '24 jam') !== false ? '#2ecc71' : 'var(--text-muted)' ?>; font-size: 13px; margin-bottom: 15px; font-weight: <?= stripos($jam, '24 jam') !== false ? 'bold' : 'normal' ?>;">
                🕒 Jam: <?= htmlspecialchars($jam) ?>
            </p>
            
            <div style="background: var(--bg-light); padding: 8px; border-radius: 4px; display: inline-block; margin-bottom: 15px;">
                <span property="aggregateRating" style="font-weight: bold; color: #f39c12;">★ <?= htmlspecialchars($rating) ?></span>
                <span style="font-size: 12px; color: var(--text-muted); margin-left: 5px; text-transform: uppercase;">(<?= htmlspecialchars($kategori) ?>)</span>
            </div>
            
            <a href="detail.php?uri=<?= urlencode($uri) ?>" style="display: block; padding: 10px; border: 1px solid var(--primary-blue); color: var(--primary-blue); text-decoration: none; border-radius: 4px; font-weight: bold; transition: 0.3s;">Lihat Detail Semantik</a>
        </div>
        
        <?php 
            endforeach; 
        } 
        else {
            echo "<div style='grid-column: 1 / -1; text-align: center; padding: 40px; background: var(--white); border-radius: 8px;'>
                    <h3 style='color: var(--text-dark); margin-bottom: 10px;'>Entitas Tidak Ditemukan</h3>
                    <p style='color: var(--text-muted);'>Coba gunakan kata kunci pencarian yang lain.</p>
                  </div>";
        }
        ?>
        
    </div>

    <div class="section-title" style="text-align: center; padding: 20px 5% 60px;">
        <h3 style="color: var(--primary-blue); font-size: 12px; text-transform: uppercase;">Tentang Proyek</h3>
        <h2 style="font-size: 24px; color: var(--text-dark);">Tugas Besar Web Semantik</h2>
        <p style="color: var(--text-muted); font-size: 14px;">Sistem terintegrasi (Knowledge Base) berbasis file RDF/Turtle, dikelola menggunakan Apache Jena Fuseki.</p>
    </div>

    <footer style="background: var(--dark-blue); color: white; text-align: center; padding: 20px; font-size: 14px;">
        <p>&copy; 2026 Tugas Besar Web Semantik - Kelas KOM B (Kelompok Bhita, Nadin, Nepah, Jepin, Dwik).</p>
    </footer>

</body>
</html>