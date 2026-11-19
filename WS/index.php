<?php
include 'lib.php';
require_once __DIR__ . '/pencarian-showroom.php';

// 1. Tangkap parameter dari URL (GET)
$parameter = array_filter($_GET, 'is_string');
$search_keyword = trim($parameter['keyword'] ?? '');
$filter_lokasi = trim($parameter['lokasi'] ?? '');
$sort_abjad = trim($parameter['abjad'] ?? '');
$sort_rating = trim($parameter['rating'] ?? '');
$filter_kategori = trim($parameter['kategori'] ?? '');
$filter_rating = $sort_rating;
$ada_filter = $filter_lokasi !== '' || $sort_abjad !== ''
    || $filter_rating !== '' || $filter_kategori !== '';
$pencarian_aktif = $search_keyword !== '' || $ada_filter;
$id_peta = $pencarian_aktif ? 'jelajahi' : 'hasil';
$url_tanpa_filter = 'index.php'
    . ($search_keyword !== '' ? '?keyword=' . urlencode($search_keyword) : '')
    . '#hasil';

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

// 3. Daftar model kendaraan untuk mengenali mereknya.
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

$encoding_literal = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
$filter_keyword = '';

if ($search_keyword !== '') {
    $keyword_lower = strtolower($search_keyword);
    $literal_keyword = json_encode($search_keyword, $encoding_literal);

    $syarat_pencarian = [
        'CONTAINS(LCASE(STR(?namaShowroom)), LCASE(' . $literal_keyword . '))',
        'CONTAINS(LCASE(STR(?merek)), LCASE(' . $literal_keyword . '))',
        'CONTAINS(LCASE(STR(?alamat)), LCASE(' . $literal_keyword . '))',
        'CONTAINS(LCASE(STR(?lokasi)), LCASE(' . $literal_keyword . '))'
    ];

    foreach ($model_ke_merek as $merek => $daftar_model) {
        if (!in_array($keyword_lower, $daftar_model, true)) {
            continue;
        }

        if ($merek === 'Honda Motor' || $merek === 'Honda Mobil') {
            $kategori_honda = $merek === 'Honda Motor' ? '"motor"' : '"mobil"';
            $syarat_pencarian[] = '(CONTAINS(LCASE(STR(?merek)), "honda")'
                . ' && CONTAINS(LCASE(STR(?kategori)), ' . $kategori_honda . '))';
        } else {
            $syarat_pencarian[] = 'CONTAINS(LCASE(STR(?merek)), LCASE(' . json_encode($merek) . '))';
        }
    }

    $filter_keyword = 'FILTER (' . implode(' || ', $syarat_pencarian) . ')';
}

$filter_clauses = [];
if ($filter_lokasi !== '') {
    $filter_clauses[] = 'str(?lokasi) = ' . json_encode($filter_lokasi, $encoding_literal);
}
if ($filter_kategori !== '') {
    $filter_clauses[] = 'LCASE(STR(?kategori)) = LCASE(' . json_encode($filter_kategori, $encoding_literal) . ')';
}
$rating_expr = 'xsd:float(replace(str(?rating), ",", "."))';
if ($filter_rating === 'terendah') {
    $filter_clauses[] = $rating_expr . ' < 4.3';
} elseif ($filter_rating === 'menengah') {
    $filter_clauses[] = $rating_expr . ' >= 4.3';
    $filter_clauses[] = $rating_expr . ' <= 4.7';
} elseif ($filter_rating === 'tertinggi') {
    $filter_clauses[] = $rating_expr . ' >= 4.8';
}

$filter_query = $filter_clauses ? 'FILTER ((' . implode(') && (', $filter_clauses) . '))' : '';
$query = <<<'SPARQL'
    PREFIX ex: <http://www.example.org/showroom#>
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

    SELECT ?showroom ?namaShowroom ?kategori ?merek ?lokasi ?rating ?jam ?alamat
    WHERE {
        ?showroom rdf:type ex:Showroom .
        ?showroom ex:namaShowroom ?namaShowroom .
        OPTIONAL { ?showroom ex:kategori ?kategori . }
        OPTIONAL { ?showroom ex:merek ?merek . }
        OPTIONAL { ?showroom ex:berlokasiDi ?lokasi . }
        OPTIONAL { ?showroom ex:rating ?rating . }
        OPTIONAL { ?showroom ex:jamOperasional ?jam . }
        OPTIONAL { ?showroom ex:alamat ?alamat . }
        %s
        %s
    }
    ORDER BY DESC(xsd:float(replace(str(?rating), ",", ".")))
SPARQL;

$data_katalog = executeSparqlQuery(sprintf($query, '', ''));
$katalog_error = !is_array($data_katalog['results']['bindings'] ?? null);
$daftar_showroom = $katalog_error ? [] : $data_katalog['results']['bindings'];

$bandingkan_rating = static function (array $a, array $b): int {
    $rating_a = (float) str_replace(',', '.', $a['rating']['value'] ?? '0');
    $rating_b = (float) str_replace(',', '.', $b['rating']['value'] ?? '0');
    return $rating_b <=> $rating_a;
};
usort($daftar_showroom, $bandingkan_rating);

$results = [];
$is_error = false;
$ada_saran = false;

if ($pencarian_aktif) {
    $data_sparql = $filter_query === '' && $filter_keyword === ''
        ? $data_katalog
        : executeSparqlQuery(sprintf($query, $filter_query, $filter_keyword));
    $is_error = !is_array($data_sparql['results']['bindings'] ?? null);
    $results = $is_error ? [] : $data_sparql['results']['bindings'];

    if (!$is_error && !$results && $search_keyword !== '') {
        // Pakai kembali katalog ketika pencarian tidak memakai filter SPARQL.
        $data_calon = $filter_query === ''
            ? $data_katalog
            : executeSparqlQuery(sprintf($query, $filter_query, ''));
        $is_error = !is_array($data_calon['results']['bindings'] ?? null);

        if (!$is_error) {
            [$results, $ada_saran] = cariShowroomMirip(
                $search_keyword,
                $data_calon['results']['bindings'],
                $model_ke_merek
            );
        }
    }

    usort($results, static function (array $a, array $b) use ($sort_rating, $sort_abjad, $bandingkan_rating): int {
        $urutan_rating = $bandingkan_rating($a, $b);

        if ($urutan_rating !== 0 && in_array($sort_rating, ['tertinggi', 'terendah'], true)) {
            return $sort_rating === 'tertinggi' ? $urutan_rating : -$urutan_rating;
        }

        if (in_array($sort_abjad, ['az', 'za'], true)) {
            $nama_a = trim($a['namaShowroom']['value'] ?? '');
            $nama_b = trim($b['namaShowroom']['value'] ?? '');
            return $sort_abjad === 'az' ? strcasecmp($nama_a, $nama_b) : strcasecmp($nama_b, $nama_a);
        }

        return $urutan_rating;
    });
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vroom - Direktori Showroom</title>
    <link rel="icon" type="image/svg+xml" href="images/vroom.svg">
    <link rel="stylesheet" href="style.css?v=6">
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
                            href="<?= htmlspecialchars($url_tanpa_filter) ?>"
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

        <a href="#<?= $id_peta ?>" class="vroom-scroll" aria-label="Lihat daftar showroom">
            <svg viewBox="0 0 32 32" fill="none" stroke="currentColor"
                stroke-width="2.2" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <path d="m7 10 9 9 9-9"></path>
                <path d="m7 17 9 9 9-9"></path>
            </svg>
        </a>

    </div>

</section>

<?php if ($pencarian_aktif): ?>
<section class="showroom-results showroom-results-search" id="hasil" aria-labelledby="showroom-results-title">
    <h2 id="showroom-results-title" class="showroom-results-title">
        <span class="showroom-results-search-icon" aria-hidden="true"></span>
        <span>Hasil Pencarian<?php if ($search_keyword !== ''): ?>
            <span class="showroom-results-keyword">“<?= htmlspecialchars($search_keyword) ?>”</span>
        <?php endif; ?></span>
    </h2>

    <?php if ($is_error || !$results || $ada_saran): ?>
        <div class="showroom-results-note<?= $is_error ? ' showroom-results-error' : '' ?>">
            <span class="showroom-results-info-icon" aria-hidden="true">i</span>
            <div>
                <?php if ($is_error): ?>
                    <p><strong>Data showroom sedang tidak bisa dimuat.</strong></p>
                    <p>Muat ulang halaman untuk mencoba lagi.</p>
                <?php else: ?>
                    <p><strong>
                        <?php if ($search_keyword !== ''): ?>
                            Tidak ada hasil yang cocok untuk “<?= htmlspecialchars($search_keyword) ?>”<?= $ada_filter ? ' dengan filter saat ini' : '' ?>.
                        <?php else: ?>
                            Tidak ada showroom yang cocok dengan filter ini.
                        <?php endif; ?>
                    </strong></p>
                    <?php if ($ada_saran): ?>
                        <p>Mungkin yang kamu maksud:</p>
                    <?php else: ?>
                        <p>Coba kata kunci lain<?= $ada_filter ? ' atau longgarkan filter' : '' ?>.</p>
                        <div class="showroom-results-actions">
                            <a href="#keywordSearch">Ubah pencarian</a>
                            <?php if ($ada_filter): ?>
                                <a href="<?= htmlspecialchars($url_tanpa_filter) ?>">Hapus filter</a>
                            <?php endif; ?>
                            <a href="#daftar-showroom">Lihat semua showroom</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($results): ?>
        <?php
        $daftar_kartu = $results;
        require __DIR__ . '/daftar-kartu-showroom.php';
        ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="showroom-map-section" id="<?= $id_peta ?>" aria-labelledby="map-title">
    <div class="showroom-map-heading">
        <h2 id="map-title">Peta Interaktif Showroom Kendaraan di Sumatera Utara</h2>
    </div>

    <div class="showroom-map">
        <iframe
            class="showroom-map-frame"
            title="Peta interaktif wilayah Sumatera Utara"
            src="https://www.openstreetmap.org/export/embed.html?bbox=96.8%2C1.0%2C100.4%2C4.8&amp;layer=mapnik"
            loading="lazy"
        ></iframe>
    </div>
</section>

<section class="showroom-results" id="daftar-showroom" aria-labelledby="showroom-catalog-title">
    <h2 id="showroom-catalog-title" class="showroom-results-title">Jelajahi Showroom</h2>

    <?php if ($daftar_showroom): ?>
        <?php
        $daftar_kartu = $daftar_showroom;
        require __DIR__ . '/daftar-kartu-showroom.php';
        ?>
    <?php else: ?>
        <div class="showroom-results-note<?= $katalog_error ? ' showroom-results-error' : '' ?>">
            <span class="showroom-results-info-icon" aria-hidden="true">i</span>
            <p><strong><?= $katalog_error
                ? 'Daftar showroom sedang tidak bisa dimuat.'
                : 'Belum ada data showroom yang tersedia.' ?></strong></p>
        </div>
    <?php endif; ?>
</section>

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