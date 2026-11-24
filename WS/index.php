<?php
include 'lib.php';
require_once __DIR__ . '/pencarian-showroom.php';
require_once __DIR__ . '/pencarian-semantik.php';
require_once __DIR__ . '/jam-showroom.php';

// 1. Tangkap parameter dari URL (GET)
$parameter = array_filter($_GET, 'is_string');
$search_keyword = trim($parameter['keyword'] ?? '');
$filter_lokasi = trim($parameter['lokasi'] ?? '');
$sort_abjad = trim($parameter['abjad'] ?? '');
$filter_status = trim($parameter['status'] ?? '');
if (!in_array($filter_status, ['buka', 'segera-tutup', 'tutup', '24-jam'], true)) {
    $filter_status = '';
}
$filter_24_jam = $filter_status === '24-jam';
$filter_kategori = strtolower(trim($parameter['kategori'] ?? ''));
if (!in_array($filter_kategori, ['mobil', 'motor'], true)) {
    $filter_kategori = '';
}
$ada_filter = $filter_lokasi !== '' || $sort_abjad !== ''
    || $filter_status !== '' || $filter_kategori !== '';
$pencarian_aktif = $search_keyword !== '' || $ada_filter;
$id_peta = $pencarian_aktif ? 'jelajahi' : 'hasil';
$url_tanpa_filter = 'index.php'
    . ($search_keyword !== '' ? '?keyword=' . urlencode($search_keyword) : '')
    . '#hasil';

$parameter_tanpa_status = http_build_query(array_filter([
    'keyword' => $search_keyword,
    'abjad' => $sort_abjad,
    'lokasi' => $filter_lokasi,
    'kategori' => $filter_kategori,
], static fn(string $nilai): bool => $nilai !== ''));
$url_tanpa_status = 'index.php'
    . ($parameter_tanpa_status !== '' ? '?' . $parameter_tanpa_status : '')
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

// 3. Cari teks showroom dan telusuri relasi model dan merek di Fuseki.
$encoding_literal = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
$token_keyword = tokenPencarianShowroom($search_keyword);
$kata_ringkas = implode('', $token_keyword);

$kunci_wilayah = array_map(
    static fn(string $lokasi): string => implode('', tokenPencarianShowroom($lokasi)),
    $daftar_lokasi
);

$ejaan_wilayah_persis = $kata_ringkas !== ''
    && in_array($kata_ringkas, $kunci_wilayah, true);
$filter_keyword = '';
$filter_model_dalam_kalimat = '';

// Pencarian semantik: "showroom daihatsu di siantar" -> merek Daihatsu + wilayah Pematangsiantar.
// Merek, model, dan wilayah dikenali dari RDF (rdfs:label, skos:prefLabel/altLabel, ex:diproduksiOleh).
$tafsiran = tafsirkanPencarianShowroom($search_keyword, bolehKoreksi: true);

if ($tafsiran !== null) {
    $filter_keyword = sparqlValuesShowroom($tafsiran['uri']);
} elseif ($search_keyword !== '') {
    $literal_keyword = json_encode($search_keyword, $encoding_literal);
    $kolom_teks = ['?namaShowroom', '?merek', '?alamat', '?lokasi', '?kategori'];
    $syarat_pencarian = [];

    // Cari potongan teks di informasi showroom.
    foreach ($kolom_teks as $kolom) {
        $syarat_pencarian[] = "CONTAINS(LCASE(STR($kolom)), LCASE($literal_keyword))";
    }

    if ($kata_ringkas !== '' && !$ejaan_wilayah_persis) {
        // Abaikan pemisah saat mencocokkan variasi ejaan selain nama wilayah lengkap.
        $pola = json_encode('[^\p{L}\p{N}]+');
        $ringkas = static fn(string $nilai): string =>
            "REPLACE(LCASE(STR($nilai)), $pola, \"\")";
        $kunci = 'LCASE(' . json_encode($kata_ringkas, $encoding_literal) . ')';

        $lokasi_ringkas = $ringkas('?lokasi');
        $label_model = $ringkas('?labelModel');
        $cocok_model = "$label_model = $kunci";

        // Potongan minimal tiga karakter bisa mencocokkan nama model.
        if (preg_match('/^.{3}/u', $kata_ringkas)) {
            $cocok_model = "CONTAINS($label_model, $kunci)";
            foreach ($kolom_teks as $kolom) {
                $syarat_pencarian[] = 'CONTAINS(' . $ringkas($kolom) . ", $kunci)";
            }
        }

        // Telusuri model → merek → showroom yang mewakili merek tersebut.
        $relasi_model = <<<'SPARQL'
            EXISTS {
                GRAPH <urn:vroom:relasi-kendaraan> {
                    ?model a ex:ModelKendaraan ;
                           rdfs:label ?labelModel ;
                           ex:diproduksiOleh ?merekModel .
                    ?showroom ex:mewakiliMerek ?merekModel .
                    OPTIONAL { ?model ex:kategoriModel ?kategoriModel . }
                    FILTER(%s)
                }
                FILTER(!BOUND(?kategoriModel) || LCASE(STR(?kategori)) = LCASE(STR(?kategoriModel)))
            }
        SPARQL;

        $syarat_pencarian[] = sprintf($relasi_model, $cocok_model);

        // Cari nama model sebagai kata atau frasa utuh di dalam kalimat.
        if (count($token_keyword) > 1) {
            $kalimat = json_encode(' ' . implode(' ', $token_keyword) . ' ', $encoding_literal);
            $frasa_model = "REPLACE(LCASE(STR(?labelModel)), $pola, \" \")";
            $frasa_model = "REPLACE($frasa_model, \"^ +| +$\", \"\")";
            $model_dalam_kalimat = "STRLEN($frasa_model) >= 2"
                . " && CONTAINS(LCASE($kalimat), CONCAT(\" \", $frasa_model, \" \"))";
            $filter_model_dalam_kalimat = 'FILTER (' . sprintf($relasi_model, $model_dalam_kalimat) . ')';
        }

        // Tetap gunakan alias wilayah yang didefinisikan di RDF.
        $label_utama = $ringkas('?namaWilayahUtama');
        $label_alias = $ringkas('?namaWilayahAlternatif');
        $syarat_pencarian[] = <<<SPARQL
            EXISTS {
                GRAPH <urn:vroom:wilayah> {
                    ?wilayah a ex:Wilayah ;
                             skos:prefLabel ?namaWilayahUtama ;
                             skos:altLabel ?namaWilayahAlternatif .
                    FILTER($label_alias = $kunci)
                }
                FILTER($lokasi_ringkas = $label_utama)
            }
        SPARQL;
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
// Filter ini dikirim sebagai REGEX SPARQL dan dijalankan oleh Fuseki.
if ($filter_24_jam) {
    $filter_clauses[] = 'REGEX(STR(?jam), "^(buka +)?24 *jam$", "i")';
}
$filter_query = $filter_clauses ? 'FILTER ((' . implode(') && (', $filter_clauses) . '))' : '';
$query = <<<'SPARQL'
    PREFIX ex: <http://www.example.org/showroom#>
    PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
    PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
    PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
    PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

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

// Indeks per URI untuk tampilan. Baris asli Fuseki tetap dipakai saat mencocokkan kata kunci.
$kelompokkan_showroom = static function (array $baris): array {
    $showroom_per_uri = [];
    $merek_per_uri = [];

    foreach ($baris as $row) {
        $uri = $row['showroom']['value'];
        $showroom_per_uri[$uri] ??= $row;
        $merek = trim($row['merek']['value'] ?? '');
        if ($merek !== '') {
            $merek_per_uri[$uri][$merek] = true;
        }
    }

    foreach ($merek_per_uri as $uri => $merek) {
        $daftar_merek = array_keys($merek);
        natcasesort($daftar_merek);
        $showroom_per_uri[$uri]['merek']['value'] = implode(' / ', $daftar_merek);
    }

    return $showroom_per_uri;
};

$data_katalog = executeSparqlQuery(sprintf($query, '', ''));
$katalog_error = !is_array($data_katalog['results']['bindings'] ?? null);
$katalog_per_uri = $kelompokkan_showroom($katalog_error ? [] : $data_katalog['results']['bindings']);
$daftar_showroom = array_values($katalog_per_uri);

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
    $ada_saran = $results !== [] && isset($tafsiran['saran']);

    if (!$is_error && !$results && $search_keyword !== '' && !$ejaan_wilayah_persis && $tafsiran === null) {
        // Pakai kembali katalog ketika pencarian tidak memakai filter SPARQL.
        $data_calon = $filter_query === ''
            ? $data_katalog
            : executeSparqlQuery(sprintf($query, $filter_query, ''));
        $is_error = !is_array($data_calon['results']['bindings'] ?? null);

        if (!$is_error && $data_calon['results']['bindings']) {
            // Ambil kosakata model dari RDF saat pencarian memerlukan saran ejaan.
            $query_model = <<<'SPARQL'
                PREFIX ex: <http://www.example.org/showroom#>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                SELECT DISTINCT ?namaModel ?namaMerek ?kategoriModel
                WHERE {
                    GRAPH <urn:vroom:relasi-kendaraan> {
                        ?model a ex:ModelKendaraan ;
                               rdfs:label ?namaModel ;
                               ex:diproduksiOleh ?merekModel .
                        ?merekModel rdfs:label ?namaMerek .
                        OPTIONAL { ?model ex:kategoriModel ?kategoriModel . }
                    }
                }
            SPARQL;
            $data_model = executeSparqlQuery($query_model);
            $model_ke_merek = [];
            foreach ($data_model['results']['bindings'] ?? [] as $row_model) {
                $merek = $row_model['namaMerek']['value'];
                if (isset($row_model['kategoriModel'])) {
                    $merek .= ' ' . $row_model['kategoriModel']['value'];
                }
                $model_ke_merek[$merek][] = $row_model['namaModel']['value'];
            }

            [$results, $ada_saran] = cariShowroomMirip(
                $search_keyword,
                $data_calon['results']['bindings'],
                $model_ke_merek
            );
        }
    }

    // Pertahankan hasil pencarian biasa. Gunakan model yang disebut jika seluruh kalimat tidak cocok.
    if (!$is_error && !$results && $filter_model_dalam_kalimat !== '') {
        $data_model_kalimat = executeSparqlQuery(sprintf($query, $filter_query, $filter_model_dalam_kalimat));
        $is_error = !is_array($data_model_kalimat['results']['bindings'] ?? null);
        $results = $is_error ? [] : $data_model_kalimat['results']['bindings'];
    }

    $hasil_per_uri = $kelompokkan_showroom($results);
    foreach ($hasil_per_uri as $uri => $row) {
        // Pencarian dapat menemukan satu merek saja. Kartu tetap menampilkan merek lengkap dari katalog.
        if (isset($katalog_per_uri[$uri]['merek'])) {
            $hasil_per_uri[$uri]['merek'] = $katalog_per_uri[$uri]['merek'];
        }
    }
    $results = array_values($hasil_per_uri);

    usort($results, static function (array $a, array $b) use ($sort_abjad, $bandingkan_rating): int {
        $urutan_rating = $bandingkan_rating($a, $b);

        if (in_array($sort_abjad, ['az', 'za'], true)) {
            $nama_a = trim($a['namaShowroom']['value'] ?? '');
            $nama_b = trim($b['namaShowroom']['value'] ?? '');
            return $sort_abjad === 'az' ? strcasecmp($nama_a, $nama_b) : strcasecmp($nama_b, $nama_a);
        }

        return $urutan_rating;
    });
}

$data_saran = executeSparqlQuery(<<<'SPARQL'
    PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

    SELECT DISTINCT ?label WHERE {
        GRAPH <urn:vroom:relasi-kendaraan> {
            ?entitas rdfs:label ?label .
        }
    }
SPARQL);

$daftar_saran = $daftar_lokasi;
foreach ($data_saran['results']['bindings'] ?? [] as $baris_saran) {
    $daftar_saran[] = ucfirst($baris_saran['label']['value']);
}
$daftar_saran = array_values(array_unique($daftar_saran));

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>vroom. - Direktori Showroom</title>
    <link rel="icon" type="image/svg+xml" href="images/vroom.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
    <link rel="stylesheet" href="style.css?v=8">
    <link rel="stylesheet" href="chatbot/vroom-bot.css?v=2">
    <link rel="stylesheet" href="css/status-showroom.css?v=1">
    <link rel="stylesheet" href="css/riwayat-showroom.css?v=2">
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

    <button type="button" class="vroom-nav-riwayat" data-riwayat-buka aria-haspopup="dialog">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path>
            <path d="M3 3v5h5"></path>
            <path d="M12 7v5l3 2"></path>
        </svg>
        <span>Riwayat</span>
        <span class="rw-badge" data-riwayat-jumlah hidden>0</span>
    </button>
</nav>

</header>


<!-- HOME / HERO -->
<section class="vroom-hero">

    <div class="vroom-hero-content">

        <h2>
            vroom<span>.</span>
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

                    <div id="searchSuggestions" class="search-suggestions"
     data-saran="<?= htmlspecialchars(json_encode($daftar_saran, $encoding_literal)) ?>"></div>

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
                        <label for="status">Status operasional</label>
                        <select name="status" id="status">
                            <option value="">Semua status</option>
                            <option value="24-jam" <?= $filter_24_jam ? 'selected' : '' ?>>Buka 24 jam</option>
                            <?php foreach (['buka', 'segera-tutup', 'tutup'] as $status): ?>
                                <option value="<?= $status ?>" <?= $filter_status === $status ? 'selected' : '' ?>>
                                    <?= LABEL_STATUS_SHOWROOM[$status] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="kategori">Kategori kendaraan</label>
                        <select name="kategori" id="kategori">
                            <option value="">Semua kategori</option>
                            <option value="mobil" <?= $filter_kategori === 'mobil' ? 'selected' : '' ?>>Mobil</option>
                            <option value="motor" <?= $filter_kategori === 'motor' ? 'selected' : '' ?>>Motor</option>
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

<!-- TERAKHIR KAMU LIHAT (diisi oleh js/riwayat-showroom.js dari localStorage) -->
<section class="rw-beranda" aria-labelledby="rw-beranda-judul" data-riwayat-terakhir hidden>
    <div class="rw-beranda-kepala">
        <div>
            <p class="rw-beranda-eyebrow">Riwayat</p>
            <h2 id="rw-beranda-judul">Terakhir kamu lihat</h2>
            <p>Lanjutkan dari showroom yang sudah dilihat.</p>
        </div>
        <button type="button" class="rw-beranda-lihat" data-riwayat-buka>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path>
                <path d="M3 3v5h5"></path>
                <path d="M12 7v5l3 2"></path>
            </svg>
            Lihat semua riwayat
        </button>
    </div>
    <div class="rw-beranda-isi" data-riwayat-terakhir-isi></div>
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
                        <?php if (isset($tafsiran['saran'])): ?>
                            <p>Mungkin yang kamu maksud “<?= htmlspecialchars($tafsiran['saran']) ?>”.</p>
                        <?php else: ?>
                            <p>Mungkin yang kamu maksud:</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (isset($tafsiran['pesan'])): ?>
                            <p><?= htmlspecialchars($tafsiran['pesan']) ?></p>
                        <?php else: ?>
                            <p>Coba kata kunci lain<?= $ada_filter ? ' atau longgarkan filter' : '' ?>.</p>
                        <?php endif; ?>
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
        $status_diminta = $filter_24_jam ? '' : $filter_status;
        require __DIR__ . '/daftar-kartu-showroom.php';
        ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="showroom-map-section" id="<?= $id_peta ?>" aria-labelledby="map-title"
    data-showroom-map="peta-showroom-data.php">
    <div class="showroom-map-panel">
        <div class="showroom-map-heading">
            <div>
                <h2 id="map-title">Peta Showroom Kendaraan Sumatera Utara</h2>
                <p>Klik pin untuk melihat detail. Perbesar peta jika pin berdekatan.</p>
            </div>

            <div class="showroom-map-filters" role="group" aria-label="Filter kategori pada peta">
                <button type="button" data-map-category="semua" aria-pressed="true" aria-controls="peta-showroom" disabled>
                    Semua <span class="showroom-map-count" data-map-count></span>
                </button>
                <button type="button" data-map-category="mobil" aria-pressed="false" aria-controls="peta-showroom" disabled>
                    Mobil <span class="showroom-map-count" data-map-count></span>
                </button>
                <button type="button" data-map-category="motor" aria-pressed="false" aria-controls="peta-showroom" disabled>
                    Motor <span class="showroom-map-count" data-map-count></span>
                </button>
            </div>
        </div>

        <div class="showroom-map-stage">
            <div id="peta-showroom" class="showroom-map" role="region" aria-label="Peta lokasi showroom"></div>
            <div class="showroom-map-placeholder" data-map-placeholder>Menyiapkan peta…</div>
        </div>

        <div class="showroom-map-footer">
            <p role="status" data-map-status></p>
            <a href="#daftar-showroom">Ke daftar showroom <span aria-hidden="true">↓</span></a>
        </div>
        <p class="showroom-map-tile-status" role="status" data-map-tile-status hidden>
            Peta dasar belum tersedia. Periksa koneksi ke OpenStreetMap.
        </p>
        <noscript><p class="showroom-map-tile-status">Aktifkan JavaScript untuk membuka peta.</p></noscript>
    </div>
</section>

<section class="showroom-results" id="daftar-showroom" aria-labelledby="showroom-catalog-title">
    <h2 id="showroom-catalog-title" class="showroom-results-title">Jelajahi Showroom</h2>

    <?php if ($daftar_showroom): ?>
        <?php
        $daftar_kartu = $daftar_showroom;
        $status_diminta = '';
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
                Empat orang di balik pengembangan vroom.
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

        <h2>Tentang <span>vroom.</span></h2>

        <div class="about-heading-line"></div>

        <p class="about-intro">
            Temukan showroom kendaraan di Sumatera Utara
            dengan lebih mudah bersama vroom.
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
            &copy; <?= date('Y') ?> vroom.
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

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous" defer></script>
<script src="js/peta-showroom.js?v=2" defer></script>
<script src="js/status-showroom.js?v=1" defer></script>
<script src="js/riwayat-showroom.js?v=3" defer></script>
<?php
$vroomBotEmbedded = true;
require __DIR__ . '/vroom-chatbot.php';
?>
</body>
</html>