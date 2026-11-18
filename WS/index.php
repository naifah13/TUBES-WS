<?php 
include 'lib.php'; 

// 1. Tangkap parameter dari URL (GET)
$search_keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$filter_lokasi  = isset($_GET['lokasi']) ? trim($_GET['lokasi']) : '';
$sort_abjad     = isset($_GET['abjad']) ? trim($_GET['abjad']) : '';
$sort_rating    = isset($_GET['rating']) ? trim($_GET['rating']) : '';
$filter_kategori = isset($_GET['kategori']) ? trim($_GET['kategori']) : '';
$filter_rating = $sort_rating;

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



$model_ke_merek = [

    // HONDA - MOBIL DAN MOTOR
    'Honda Motor' => [
        'vario', 'beat', 'scoopy', 'pcx',
        'adv', 'supra', 'revo', 'genio',
        'stylo', 'sonic', 'cbr150r',
        'cb150r', 'crf150l', 'forza',
        'monkey', 'cbr1000rr', 'cbr650r',
        'cb650r', 'cb500x', 'rebel 500',
        'rebel 1100', 'africa twin',
        'gold wing', 'nt1100'
    ],

    // HONDA MOBIL
    'Honda Mobil' => [
        'brio', 'jazz', 'civic', 'city',
        'accord', 'crv', 'hrv', 'brv',
        'wrv', 'mobilio', 'freed',
        'odyssey', 'stepwgn'
    ],

    // YAMAHA - MOTOR
    'Yamaha' => [
        'nmax', 'aerox', 'mio', 'fazzio',
        'xmax', 'lexi', 'r15', 'r25',
        'mt15', 'mt25', 'vixion',
        'jupiter', 'vega', 'fino',
        'gear', 'freego', 'filano',
        'xsr155', 'wr155', 'rx king',
        'byson', 'soul gt', 'tmax'
    ],

    // TOYOTA - MOBIL
    'Toyota' => [
        'avanza', 'innova', 'fortuner',
        'rush', 'agya', 'calya',
        'alphard', 'veloz', 'yaris',
        'raize', 'hilux', 'camry',
        'corolla', 'altis', 'vios',
        'hiace', 'land cruiser',
        'prado', 'gr86', 'sienta',
        'kijang', 'starlet', 'voxy',
        'bz4x', 'crown', 'rav4'
    ],

    // DAIHATSU - MOBIL
    'Daihatsu' => [
        'xenia', 'sigra', 'ayla',
        'terios', 'rocky', 'sirion',
        'gran max', 'granmax',
        'luxio', 'taft', 'feroza',
        'charade', 'zebra',
        'hijet', 'copen'
    ],

    // SUZUKI - MOBIL DAN MOTOR
    'Suzuki' => [
        'ertiga', 'xl7', 'baleno',
        'ignis', 'jimny', 'karimun',
        'swift', 'apv', 'carry',
        'splash', 'sx4', 'vitara',
        'escudo', 'katana', 'ciaz',
        'nex', 'address', 'satria',
        'smash', 'shogun', 'burgman',
        'gsx', 'hayabusa', 'v-strom',
        'avenis'
    ],

    // MITSUBISHI - MOBIL
    'Mitsubishi' => [
        'xpander', 'xpander cross',
        'pajero', 'pajero sport',
        'triton', 'l300', 'colt',
        'outlander', 'eclipse cross',
        'xforce', 'destinator',
        'mirage', 'delica',
        'lancer', 'galant',
        'grandis', 'strada', 'kuda'
    ],

    // KAWASAKI - MOTOR
    'Kawasaki' => [
        'ninja', 'zx25r', 'zx4r',
        'zx6r', 'zx10r', 'klx',
        'w175', 'w250', 'w800',
        'versys', 'z125', 'z250',
        'z400', 'z650', 'z900',
        'z1000', 'vulcan',
        'eliminator', 'd-tracker',
        'kaze', 'athlete'
    ],

    // HYUNDAI - MOBIL
    'Hyundai' => [
        'stargazer', 'creta',
        'palisade', 'santa fe',
        'tucson', 'kona', 'ioniq',
        'ioniq 5', 'ioniq 6',
        'ioniq 9', 'venue',
        'accent', 'elantra',
        'sonata', 'h1', 'h100',
        'i10', 'i20', 'i30',
        'grand avega'
    ],

    // WULING - MOBIL
    'Wuling' => [
        'air ev', 'binguo', 'binguo ev',
        'cloud ev', 'alvez',
        'almaz', 'cortez',
        'confero', 'formo',
        'darion'
    ],

    // CHERY - MOBIL
    'Chery' => [
        'omoda', 'omoda 5', 'omoda e5',
        'tiggo', 'tiggo 5x',
        'tiggo 7', 'tiggo 8',
        'tiggo 9', 'j6', 'j6t',
        'c5'
    ],

    // BMW - MOBIL
    'BMW' => [
        '118i', '218i', '320i',
        '330i', '520i', '530i',
        '740i', '750i', 'x1',
        'x2', 'x3', 'x4',
        'x5', 'x6', 'x7',
        'm2', 'm3', 'm4',
        'm5', 'i4', 'i5',
        'i7', 'ix'
    ],

    // MERCEDES-BENZ - MOBIL
    'Mercedes-Benz' => [
        'a200', 'a250', 'c180',
        'c200', 'c250', 'c300',
        'e200', 'e250', 'e300',
        's400', 's450', 's500',
        'gla', 'glb', 'glc',
        'gle', 'gls', 'g63',
        'cla', 'cls', 'eqe',
        'eqs', 'amg gt'
    ],

    // NISSAN - MOBIL
    'Nissan' => [
        'livina', 'grand livina',
        'serena', 'xtrail', 'x-trail',
        'terra', 'navara',
        'magnite', 'juke',
        'march', 'evalia',
        'teana', 'elgrand',
        'skyline', 'gtr', 'gt-r',
        'fairlady', 'leaf', 'kicks'
    ],

    // DATSUN - MOBIL
    'Datsun' => [
        'datsun go', 'go plus',
        'go+', 'redi go'
    ],

    // CHEVROLET - MOBIL
    'Chevrolet' => [
        'captiva', 'spin',
        'trax', 'trailblazer',
        'colorado', 'spark',
        'aveo', 'cruze',
        'orlando', 'optra',
        'camaro', 'tahoe',
        'suburban'
    ],

    // MAZDA - MOBIL
    'Mazda' => [
        'mazda2', 'mazda3',
        'mazda6', 'cx3', 'cx30',
        'cx5', 'cx8', 'cx9',
        'cx60', 'cx80', 'cx90',
        'mx5', 'rx7', 'rx8',
        'biante', 'bt50'
    ],

    // FORD - MOBIL
    'Ford' => [
        'ranger', 'everest',
        'ecosport', 'fiesta',
        'focus', 'escape',
        'mustang', 'explorer',
        'bronco', 'raptor',
        'territory'
    ],

    // ISUZU - MOBIL DAN TRUK
    'Isuzu' => [
        'panther', 'mu-x', 'mux',
        'dmax', 'd-max',
        'traga', 'elf',
        'giga', 'nmr',
        'nkr', 'npr'
    ],

    // BYD - MOBIL LISTRIK
    'BYD' => [
        'atto 3', 'dolphin',
        'seal', 'sealion 7',
        'm6', 'denza d9',
        'seagull'
    ],

    // VESPA - MOTOR
    'Vespa' => [
        'primavera', 'sprint',
        'gts', 'gts super',
        'gts 300', 'lx',
        's 125', 's 150',
        '946', 'elettrica',
        'px', 'excel', 'et4'
    ],

    // MG - MOBIL
    'MG' => [
        'zs', 'hs', 'mg5',
        'mg4', 'mg4 ev',
        'zs ev', 'cyberster',
        'mg one', 'mg3'
    ],

    // DFSK - MOBIL
    'DFSK' => [
        'glory 560', 'glory 580',
        'glory i-auto',
        'gelora', 'gelora e',
        'super cab'
    ],

    // AION - MOBIL LISTRIK
    'Aion' => [
        'aion y', 'aion y plus',
        'aion v', 'aion es',
        'aion ut', 'hyptec ht'
    ]

];

if (!empty($search_keyword)) {

    $keyword_lower = strtolower($search_keyword);
    $merek_cocok = [];

    // Mencari merek berdasarkan nama kendaraan
    foreach ($model_ke_merek as $merek => $daftar_model) {
        foreach ($daftar_model as $model) {

            if (preg_match(
                '/(?<![a-z0-9])' . preg_quote($model, '/') . '(?![a-z0-9])/i',
                $keyword_lower
            )) {
                $merek_cocok[] = $merek;
                break;
            }
        }
    }

    $literal_keyword = json_encode(
        $search_keyword,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $syarat_pencarian = [
        'CONTAINS(LCASE(STR(?namaShowroom)), LCASE(' . $literal_keyword . '))',
        'CONTAINS(LCASE(STR(?merek)), LCASE(' . $literal_keyword . '))',
        'CONTAINS(LCASE(STR(?alamat)), LCASE(' . $literal_keyword . '))',
        'CONTAINS(LCASE(STR(?lokasi)), LCASE(' . $literal_keyword . '))'
    ];

    
    foreach ($merek_cocok as $merek) {

        if ($merek === 'Honda Motor') {

            $syarat_pencarian[] = '(
                CONTAINS(LCASE(STR(?merek)), "honda")
                &&
                CONTAINS(LCASE(STR(?kategori)), "motor")
            )';

        } elseif ($merek === 'Honda Mobil') {

            $syarat_pencarian[] = '(
                CONTAINS(LCASE(STR(?merek)), "honda")
                &&
                CONTAINS(LCASE(STR(?kategori)), "mobil")
            )';

        } else {

        $literal_merek = json_encode($merek);

        $syarat_pencarian[] =
            'CONTAINS(LCASE(STR(?merek)), LCASE(' .
            $literal_merek . '))';
    }
}


    $filter_clauses[] =
        '(' . implode(' || ', $syarat_pencarian) . ')';
}


if (!empty($filter_lokasi)) {
    $lok_clean = addslashes($filter_lokasi);
    $filter_clauses[] = 'str(?lokasi) = "' . $lok_clean . '"';
}


if (!empty($filter_kategori)) {
    $kat_clean = addslashes($filter_kategori);

    $filter_clauses[] =
        'LCASE(STR(?kategori)) = LCASE("' . $kat_clean . '")';
}

if (!empty($filter_rating)) {
    $rating_expr =
        'xsd:float(replace(str(?rating), ",", "."))';

    if ($filter_rating === 'terendah') {
        $filter_clauses[] = '(' . $rating_expr . ' < 4.3)';
    } elseif ($filter_rating === 'menengah') {
        $filter_clauses[] =
            '(' . $rating_expr . ' >= 4.3 && ' .
            $rating_expr . ' <= 4.7)';
    } elseif ($filter_rating === 'tertinggi') {
        $filter_clauses[] = '(' . $rating_expr . ' >= 4.8)';
    }
}


$filter_query = count($filter_clauses) > 0 ? 'FILTER( (' . implode(') && (', $filter_clauses) . ') ) .' : '';

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
        OPTIONAL { ?showroom ex:alamat ?alamat . }
        
        ' . $filter_query . '
    }
    ORDER BY ?showroom
';

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
    <title>Vroom - Direktori Showroom</title>
    <link rel="icon" type="image/svg+xml" href="images/vroom.svg">
    <link rel="stylesheet" href="style.css?v=4">
</head>



<body>

    <!-- NAVBAR -->
<header class="vroom-header">

    <a href="index.php" class="vroom-brand" aria-label="Vroom - Beranda">
        <img src="images/vroom.svg" alt="Logo Vroom">
        <span class="vroom-brand-text">
            <strong>vroom<span>.</span></strong>
        </span>
    </a>

    <nav class="vroom-nav" aria-label="Navigasi utama">
    <a href="index.php" class="vroom-nav-active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
            <path d="M3 10.5 12 3l9 7.5"></path>
            <path d="M5.5 9.5V21h13V9.5"></path>
            <path d="M9.5 21v-7h5v7"></path>
        </svg>
        <span>Beranda</span>
    </a>

    <a href="#hasil">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
            <path d="M3 10h18l-1.5-5h-15L3 10Z"></path>
            <path d="M5 10v9h14v-9"></path>
            <path d="M3 10c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2"></path>
            <path d="M9 19v-4h6v4"></path>
        </svg>
        <span>Showroom</span>
    </a>

    <a href="#tim">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="8" r="3"></circle>
            <path d="M3.5 19v-1a5.5 5.5 0 0 1 11 0v1"></path>
            <path d="M16 5.2a3 3 0 0 1 0 5.6"></path>
            <path d="M17 13a5 5 0 0 1 3.5 4.8V19"></path>
        </svg>
        <span>Tim</span>
    </a>

    <a href="#tentang">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 11v5"></path>
            <path d="M12 8h.01"></path>
        </svg>
        <span>Tentang</span>
    </a>
</nav>

</header>


<!-- HOME / HERO -->
<section class="vroom-hero">

    <div class="vroom-hero-content">

        <h2>
            VROOM<span>.</span>
        </h2>

        <p class="vroom-hero-description">
            Temukan showroom kendaraan
            <span>di seluruh Sumatera Utara.</span>
        </p>

        <p class="vroom-hero-caption">
            Satu tempat untuk mencari lokasi, menjelajahi pilihan,
            dan menemukan informasi yang kamu butuhkan.
        </p>

        <!-- FORM PENCARIAN DAN FILTER -->
        <div class="nav-search-container">

            <form
                action="index.php#hasil"
                method="GET"
                id="searchFilterForm"
            >

                <div class="search-bar-nav">

                    <div class="search-wrapper">
                    
                    <input
                        type="text"
                        id="keywordSearch"
                        name="keyword"
                        autocomplete="off"
                        aria-label="Cari showroom"
                        placeholder="Cari kendaraan, showroom, atau lokasi..."
                        value="<?= htmlspecialchars($search_keyword) ?>"
                    >

                    <div id="searchSuggestions"
                        class="search-suggestions"></div>

                    </div>

                    <button
                        type="button"
                        class="filter-toggle-btn"
                        id="filterToggleBtn"
                        title="Filter pencarian"
                        aria-controls="filterPanel"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M4 5h16l-6.5 7.5V19l-3-1.5v-5Z"></path>
                        </svg>
                        <span>Filter</span>
                    </button>

                    <button type="submit" class="submit-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round"
                            aria-hidden="true">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="m20 20-4-4"></path>
                        </svg>
                        <span>Cari</span>
                    </button>

                    <a href="index.php#hasil" class="search-reset" id="searchReset" hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="m9 9 6 6"></path>
                            <path d="m15 9-6 6"></path>
                        </svg>
                        <span>Reset</span>
                    </a>

                </div>

                <div class="filter-dropdown-panel" id="filterPanel">

                    <div class="filter-group">
                        <label for="abjad">Urutan Abjad</label>

                        <select name="abjad" id="abjad">
                            <option value="">Default</option>

                            <option
                                value="az"
                                <?= $sort_abjad === 'az' ? 'selected' : '' ?>
                            >
                                Dari A - Z
                            </option>

                            <option
                                value="za"
                                <?= $sort_abjad === 'za' ? 'selected' : '' ?>
                            >
                                Dari Z - A
                            </option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="rating">Rating</label>

                        <select name="rating" id="rating">
                            <option value="">Default</option>

                            <option
                                value="tertinggi"
                                <?= $sort_rating === 'tertinggi' ? 'selected' : '' ?>
                            >
                                Tertinggi ke Terendah
                            </option>

                            <option
                                value="terendah"
                                <?= $sort_rating === 'terendah' ? 'selected' : '' ?>
                            >
                                Terendah ke Tertinggi
                            </option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="lokasi">Lokasi</label>

                        <select name="lokasi" id="lokasi">
                            <option value="">Semua Lokasi</option>

                            <?php foreach ($daftar_lokasi as $lok): ?>
                                <option
                                    value="<?= htmlspecialchars($lok) ?>"
                                    <?= $filter_lokasi === $lok ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($lok) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <a
                            href="index.php<?= !empty($search_keyword) ? '?keyword=' . urlencode($search_keyword) : '' ?>#hasil"
                            class="btn-reset-filter"
                        >
                            Atur Ulang
                        </a>

                        <button type="submit" class="btn-apply-filter">
                            Terapkan
                        </button>
                    </div>

                </div>

            </form>

        </div>

        <a href="#hasil" class="vroom-scroll" aria-label="Lihat daftar showroom">
            <svg viewBox="0 0 32 32" fill="none" stroke="currentColor"
                stroke-width="2.2" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <path d="m7 10 9 9 9-9"></path>
                <path d="m7 17 9 9 9-9"></path>
            </svg>
        </a>

    </div>

</section>

<!-- PETA SHOWROOM: TAMPILAN SEMENTARA -->
<section
    class="showroom-map-section"
    id="hasil"
    aria-labelledby="map-title"
>

    <div class="showroom-map-heading">
        <div>

            <h2 id="map-title">Peta Showroom Sumatera Utara</h2>

        </div>

        <span class="showroom-map-badge">TAMPILAN CONTOH</span>
    </div>

    <div class="showroom-map">

        <!-- Peta dasar OpenStreetMap -->
        <iframe
            class="showroom-map-frame"
            title="Peta wilayah Sumatera Utara"
            src="https://www.openstreetmap.org/export/embed.html?bbox=96.8%2C1.0%2C100.4%2C4.8&amp;layer=mapnik"
            loading="lazy"
        ></iframe>

        <!-- Penanda berikut hanya ilustrasi, bukan lokasi showroom -->
        <div class="showroom-map-markers" aria-hidden="true">
            <span class="showroom-map-pin pin-one"></span>
            <span class="showroom-map-pin pin-two"></span>
            <span class="showroom-map-pin pin-three"></span>
            <span class="showroom-map-pin pin-four"></span>
            <span class="showroom-map-pin pin-five"></span>
        </div>

    </div>

    <h2 id="map-title">Jelajahi Showroom</h2>

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
        
        <?php
// Menentukan file logo berdasarkan merek dan kategori kendaraan.
$namaMerek = strtolower(trim($merek));
$kategoriKendaraan = strtolower(trim($kategori));
$logoFile = null;

$daftarLogo = [
    'mercedes-benz' => 'mercedes-benz.jpg',
    'mitsubishi'    => 'mitsubishi.jpg',
    'chevrolet'     => 'chevrolet.jpg',
    'daihatsu'      => 'daihatsu.jpg',
    'kawasaki'      => 'kawasaki.jpg',
    'hyundai'       => 'hyundai.jpg',
    'suzuki'        => 'suzuki.jpg',
    'toyota'        => 'toyota.jpg',
    'datsun'        => 'datsun.jpg',
    'yamaha'        => 'yamaha.jpg',
    'wuling'        => 'wuling.jpg',
    'mazda'         => 'mazda.jpg',
    'isuzu'         => 'isuzu.jpg',
    'chery'         => 'chery.jpg',
    'vespa'         => 'vespa.jpg',
    'dfsk'          => 'dfsk.jpg',
    'ford'          => 'ford.jpg',
    'aion'          => 'aion.jpg',
    'bmw'           => 'bmw.jpg',
    'byd'           => 'byd.jpg',
    'mg'            => 'mg.jpg',
];

// Honda memiliki logo berbeda untuk mobil dan motor.
if (strpos($namaMerek, 'honda') !== false) {
    if (
        strpos($kategoriKendaraan, 'motor') !== false ||
        strpos($namaMerek, 'wing') !== false
    ) {
        $logoFile = 'honda motor.jpg';
    } else {
        $logoFile = 'honda mobil.jpg';
    }
} else {
    foreach ($daftarLogo as $kataMerek => $namaFile) {
        if (
            preg_match(
                '/\b' . preg_quote($kataMerek, '/') . '\b/i',
                $namaMerek
            )
        ) {
            $logoFile = $namaFile;
            break;
        }
    }
}

// Jika file logo tidak tersedia, tampilkan simbol pengganti.
$logoTersedia = $logoFile !== null
    && is_file(__DIR__ . '/images/logo/' . $logoFile);
?>

<article class="showroom-card" typeof="AutoDealer">

    <div class="showroom-card-top">

        <div class="showroom-logo">
            <?php if ($logoTersedia): ?>
                <img
                    src="images/logo/<?= rawurlencode($logoFile) ?>"
                    alt="Logo <?= htmlspecialchars($merek) ?>"
                    loading="lazy"
                >
            <?php else: ?>
                <span aria-hidden="true">SS</span>
            <?php endif; ?>
        </div>

        <span class="showroom-category">
            <?= htmlspecialchars($kategori) ?>
        </span>

    </div>

    <div class="showroom-content">

        <p class="showroom-brand" property="brand">
            <?= htmlspecialchars($merek) ?>
        </p>

        <h3 property="name">
            <?= htmlspecialchars($nama) ?>
        </h3>

        <div class="showroom-information">

            <p class="showroom-info">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path>
                    <circle cx="12" cy="10" r="2.5"></circle>
                </svg>

                <span property="areaServed">
                    <?= htmlspecialchars($lokasi) ?>
                </span>
            </p>

            <p class="showroom-info">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M12 7v5l3.5 2"></path>
                </svg>

                <span property="openingHours">
                    <?= htmlspecialchars($jam) ?>
                </span>
            </p>

        </div>

        <div class="showroom-bottom">

            <div class="showroom-rating">
                <span class="showroom-rating-star" aria-hidden="true">★</span>
                <span property="aggregateRating">
                    <?= htmlspecialchars($rating) ?>
                </span>
            </div>

            <a
                href="detail.php?uri=<?= urlencode($uri) ?>"
                class="showroom-detail"
            >
                Lihat Detail
                <span aria-hidden="true">→</span>
            </a>

        </div>

    </div>

</article>
        
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
    
    <!-- BAGIAN TIM VROOM -->
<section class="team-section" id="tim">

    <div class="team-container">

        <div class="team-heading">
            <h2>Tim <span>Kami</span></h2>

            <div class="team-heading-line"></div>

            <p class="team-description">
                Empat orang di balik pengembangan Vroom.
            </p>
        </div>

        <div class="team-grid">

            <article class="team-card">
                <div class="team-photo">
                    <img
                        src="images/tim/tabhita.png"
                        alt="Foto Tabhita"
                        loading="lazy"
                    >
                </div>

                <h3>Tabhita</h3>
                <h4>251402023</h4>
            </article>

            <article class="team-card">
                <div class="team-photo">
                    <img
                        src="images/tim/nadine.png"
                        alt="Foto Nadine"
                        loading="lazy"
                    >
                </div>

                <h3>Nadine</h3>
                <h4>251402050</h4>
            </article>

            <article class="team-card">
                <div class="team-photo">
                    <img
                        src="images/tim/jevine.png"
                        alt="Foto Jevine"
                        loading="lazy"
                    >
                </div>

                <h3>Jevine</h3>
                <h4>251402085</h4>
            </article>

            <article class="team-card">
                <div class="team-photo">
                    <img
                        src="images/tim/dwi.png"
                        alt="Foto Dwi"
                        loading="lazy"
                    >
                </div>

                <h3>Dwi</h3>
                <h4>251402088</h4>
            </article>

        </div>

    </div>

</section>

<!-- BAGIAN TENTANG VROOM -->
<section class="about-section" id="tentang">

    <div class="about-container">

        <div class="about-logo">
            <img src="images/vroom.svg" alt="Logo Vroom">
        </div>

        <h2>Tentang <span>Vroom.</span></h2>

        <div class="about-heading-line"></div>

        <p class="about-intro">
            Temukan showroom kendaraan di Sumatera Utara
            dengan lebih mudah bersama Vroom.
        </p>

        <div class="about-description">

            <p>
                Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. 
            </p>

            <p>
                Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.
            </p>

        </div>

    </div>

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
                <a href="#hasil">Showroom</a>
                <a href="#tim">Tim Kami</a>
                <a href="#tentang">Tentang Kami</a>
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

                    <a href="#hasil">
                        Lihat Showroom <span aria-hidden="true">→</span>
                    </a>
                </div>

            </div>

        </div>

    </div>

    <div class="footer-bottom">
        <p>
            &copy; <?= date('Y') ?> Vroom.
            Web Semantik Showroom Kendaraan Sumatera Utara.
        </p>
    </div>

</footer>

<script src="js/filter.js" defer></script>
<script src="js/pencarian.js" defer></script>
<script src="js/navbar.js" defer></script>
<script src="js/layout.js" defer></script>
<script src="js/autocomplete.js" defer></script>
<script src="js/animasi-card.js" defer></script>

</body>
</html>