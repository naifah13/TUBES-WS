<?php 
include 'lib.php'; 

// 1. Tangkap parameter dari URL (GET)
$search_keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$filter_lokasi  = isset($_GET['lokasi']) ? trim($_GET['lokasi']) : '';
$sort_abjad     = isset($_GET['abjad']) ? trim($_GET['abjad']) : '';
$sort_rating    = isset($_GET['rating']) ? trim($_GET['rating']) : '';

// 2. Ambil daftar lokasi unik yang ADA di file TTL saja
$query_lokasi = '
    PREFIX ex: <http://www.example.org/showroom#>
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    SELECT DISTINCT ?lokasi WHERE {
        ?showroom rdf:type ex:Showroom .
        ?showroom ex:berlokasiDi ?lokasi .
    } ORDER BY ASC(?lokasi)
';
$data_lokasi_sparql = executeSparqlQuery($query_lokasi);
$daftar_lokasi = [];
if (isset($data_lokasi_sparql['results']['bindings'])) {
    foreach ($data_lokasi_sparql['results']['bindings'] as $row_lok) {
        if (!empty($row_lok['lokasi']['value'])) {
            $daftar_lokasi[] = trim($row_lok['lokasi']['value']);
        }
    }
}

// 3. Bangun Query Filter SPARQL Utama (Kata Kunci & Lokasi)
$filter_clauses = [];

if (!empty($search_keyword)) {
    $keyword_clean = addslashes($search_keyword);
    $filter_clauses[] = '
        contains(lcase(str(?namaShowroom)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?merek)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?alamat)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?lokasi)), lcase("' . $keyword_clean . '"))
    ';
}

if (!empty($filter_lokasi)) {
    $lok_clean = addslashes($filter_lokasi);
    $filter_clauses[] = 'str(?lokasi) = "' . $lok_clean . '"';
}

$filter_query = count($filter_clauses) > 0 ? 'FILTER( (' . implode(') && (', $filter_clauses) . ') ) .' : '';

$query = '
    PREFIX ex: <http://www.example.org/showroom#>
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    
    SELECT ?showroom ?namaShowroom ?kategori ?merek ?lokasi ?rating ?jam
    WHERE {
        ?showroom rdf:type ex:Showroom .
        ?showroom ex:namaShowroom ?namaShowroom .
        OPTIONAL { ?showroom ex:kategori ?kategori . }
        OPTIONAL { ?showroom ex:merek ?merek . }
        OPTIONAL { ?showroom ex:berlokasiDi ?lokasi . }
        OPTIONAL { ?showroom ex:rating ?rating . }
        OPTIONAL { ?showroom ex:jamOperasional ?jam . }
        OPTIONAL { ?showroom ex:alamat ?alamat . }
        
        ' . $filter_query . '
    }
    ORDER BY ?showroom
';

// 2. Ambil daftar lokasi unik yang ADA di file TTL saja
$query_lokasi = '
    PREFIX ex: <http://www.example.org/showroom#>
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    SELECT DISTINCT ?lokasi WHERE {
        ?showroom rdf:type ex:Showroom .
        ?showroom ex:berlokasiDi ?lokasi .
    } ORDER BY ASC(?lokasi)
';
$data_lokasi_sparql = executeSparqlQuery($query_lokasi);
$daftar_lokasi = [];
if (isset($data_lokasi_sparql['results']['bindings'])) {
    foreach ($data_lokasi_sparql['results']['bindings'] as $row_lok) {
        if (!empty($row_lok['lokasi']['value'])) {
            $daftar_lokasi[] = trim($row_lok['lokasi']['value']);
        }
    }
}

// 3. Bangun Query Filter SPARQL Utama secara dinamis
$filter_clauses = [];

if (!empty($search_keyword)) {
    $keyword_clean = addslashes($search_keyword);
    $filter_clauses[] = '
        contains(lcase(str(?namaShowroom)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?merek)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?alamat)), lcase("' . $keyword_clean . '")) || 
        contains(lcase(str(?lokasi)), lcase("' . $keyword_clean . '"))
    ';
}
if (!empty($filter_kategori)) {
    $kat_clean = addslashes($filter_kategori);
    $filter_clauses[] = 'lcase(str(?kategori)) = lcase("' . $kat_clean . '")';
}
if (!empty($filter_lokasi)) {
    $lok_clean = addslashes($filter_lokasi);
    $filter_clauses[] = 'str(?lokasi) = "' . $lok_clean . '"';
}
if (!empty($filter_rating)) {
    $rating_expr = 'xsd:float(replace(str(?rating), ",", "."))';
    if ($filter_rating === 'terendah') {
        $filter_clauses[] = '(' . $rating_expr . ' < 4.3)';
    } elseif ($filter_rating === 'menengah') {
        $filter_clauses[] = '(' . $rating_expr . ' >= 4.3 && ' . $rating_expr . ' <= 4.7)';
    } elseif ($filter_rating === 'tertinggi') {
        $filter_clauses[] = '(' . $rating_expr . ' >= 4.8)';
    }
}

$filter_query = count($filter_clauses) > 0 ? 'FILTER( (' . implode(') && (', $filter_clauses) . ') ) .' : '';

$active_filter_count = 0;
if (!empty($filter_kategori)) $active_filter_count++;
if (!empty($filter_lokasi)) $active_filter_count++;
if (!empty($filter_rating)) $active_filter_count++;

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
    <link rel="stylesheet" href="style.css?v=3">
</head>
<body>

    <header style="display: flex; justify-content: space-between; align-items: center; padding: 15px 5%;">
        
        <a href="index.php" style="text-decoration: none;">
            <h1>ShowroomSumut<span style="font-size: 14px; color: var(--text-dark); margin-left: 5px;">Knowledge Base</span></h1>
        </a>
        
       <div class="nav-search-container">
            <form action="index.php" method="GET" id="searchFilterForm">
                <div class="search-bar-nav">
                    <input type="text" name="keyword" placeholder="Cari nama, merek, atau jalan (misal: Auto2000, Honda)..." value="<?= htmlspecialchars($search_keyword) ?>">
                    
                    <button type="button" class="filter-toggle-btn" id="filterToggleBtn" title="Filter Pencarian">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                        </svg>
                        <span>Filter</span>
                    </button>

                    <button type="submit" class="submit-search">Cari</button>
                </div>

                <div class="filter-dropdown-panel" id="filterPanel">
                    <div class="filter-group">
                        <label for="abjad">Urutan Abjad</label>
                        <select name="abjad" id="abjad">
                            <option value="">Default</option>
                            <option value="az" <?= $sort_abjad === 'az' ? 'selected' : '' ?>>Dari A - Z</option>
                            <option value="za" <?= $sort_abjad === 'za' ? 'selected' : '' ?>>Dari Z - A</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="rating">Rating</label>
                        <select name="rating" id="rating">
                            <option value="">Default</option>
                            <option value="tertinggi" <?= $sort_rating === 'tertinggi' ? 'selected' : '' ?>>Tertinggi ke Terendah</option>
                            <option value="terendah" <?= $sort_rating === 'terendah' ? 'selected' : '' ?>>Terendah ke Tertinggi</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="lokasi">Lokasi</label>
                        <select name="lokasi" id="lokasi">
                            <option value="">Semua Lokasi</option>
                            <?php foreach ($daftar_lokasi as $lok): ?>
                                <option value="<?= htmlspecialchars($lok) ?>" <?= $filter_lokasi === $lok ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($lok) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <a href="index.php<?= !empty($search_keyword) ? '?keyword=' . urlencode($search_keyword) : '' ?>" class="btn-reset-filter">Atur Ulang</a>
                        <button type="submit" class="btn-apply-filter">Terapkan</button>
                    </div>
                </div>
            </form>
        </div>

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

// PENGURUTAN DATA (RATING & ABJAD) MENGGUNAKAN PHP AGAR 100% AKURAT
usort($results, function($a, $b) use ($sort_rating, $sort_abjad) {
    // Ambil nilai rating & ubah koma menjadi titik agar jadi angka float
    $ratingA = isset($a['rating']['value']) ? (float) str_replace(',', '.', $a['rating']['value']) : 0;
    $ratingB = isset($b['rating']['value']) ? (float) str_replace(',', '.', $b['rating']['value']) : 0;

    // Ambil nama showroom & bersihkan spasi berlebih
    $namaA = isset($a['namaShowroom']['value']) ? trim($a['namaShowroom']['value']) : '';
    $namaB = isset($b['namaShowroom']['value']) ? trim($b['namaShowroom']['value']) : '';

    // 1. Jika user memilih filter Rating
    if ($sort_rating === 'tertinggi') {
        if ($ratingA != $ratingB) {
            return ($ratingB <=> $ratingA); // Besar ke kecil (5.0 -> 3.0)
        }
    } elseif ($sort_rating === 'terendah') {
        if ($ratingA != $ratingB) {
            return ($ratingA <=> $ratingB); // Kecil ke besar (3.0 -> 5.0)
        }
    }

    // 2. Jika user memilih filter Urutan Abjad (atau jika ratingnya sama)
    if ($sort_abjad === 'az') {
        return strcasecmp($namaA, $namaB); // A ke Z (tidak peduli huruf besar/kecil)
    } elseif ($sort_abjad === 'za') {
        return strcasecmp($namaB, $namaA); // Z ke A
    }

    // 3. Default jika tidak memilih apa-apa: urutkan rating tertinggi
    return ($ratingB <=> $ratingA);
});

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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const filterBtn = document.getElementById('filterToggleBtn');
            const filterPanel = document.getElementById('filterPanel');

            filterBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                filterPanel.classList.toggle('active');
            });

            document.addEventListener('click', function(e) {
                if (!filterPanel.contains(e.target) && !filterBtn.contains(e.target)) {
                    filterPanel.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>