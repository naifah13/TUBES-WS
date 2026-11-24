<?php

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/jam-showroom.php';
require_once __DIR__ . '/kendaraan-showroom.php';

$uri = is_string($_GET['uri'] ?? null) ? trim($_GET['uri']) : '';
$pesan_error = '';
$baris = [];

if (!preg_match('~\Ahttp://www\.example\.org/showroom#Showroom[0-9]+\z~', $uri)) {
    http_response_code(404);
    $pesan_error = 'Showroom tidak ditemukan.';
} else {
    $query = <<<SPARQL
        PREFIX ex: <http://www.example.org/showroom#>
        PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>

        SELECT ?nama ?kategori ?merek ?alamat ?jam ?telepon ?website ?rating ?lokasi ?lat ?long
        WHERE {
            VALUES ?showroom { <$uri> }
            ?showroom a ex:Showroom ;
                      ex:namaShowroom ?nama .
            OPTIONAL { ?showroom ex:kategori ?kategori . }
            OPTIONAL { ?showroom ex:merek ?merek . }
            OPTIONAL { ?showroom ex:alamat ?alamat . }
            OPTIONAL { ?showroom ex:jamOperasional ?jam . }
            OPTIONAL { ?showroom ex:noTelepon ?telepon . }
            OPTIONAL { ?showroom ex:website ?website . }
            OPTIONAL { ?showroom ex:rating ?rating . }
            OPTIONAL { ?showroom ex:berlokasiDi ?lokasi . }
            OPTIONAL { ?showroom geo:lat ?lat ; geo:long ?long . }
        }
        SPARQL;

    $data = executeSparqlQuery($query);
    $bindings = $data['results']['bindings'] ?? null;

    if (!is_array($bindings)) {
        http_response_code(503);
        $pesan_error = 'Informasi showroom sedang tidak bisa dimuat.';
    } elseif ($bindings === []) {
        http_response_code(404);
        $pesan_error = 'Showroom tidak ditemukan.';
    } else {
        $baris = $bindings;
    }
}

// Peta & rute: pakai Google Maps kalau API key diisi di config-maps.php, selain itu OpenStreetMap.
$konfigurasi_peta = is_file(__DIR__ . '/config-maps.php') ? (array) require __DIR__ . '/config-maps.php' : [];
$google_maps_key = trim((string) ($konfigurasi_peta['google_maps_api_key'] ?? ''));
$google_map_id = trim((string) ($konfigurasi_peta['google_map_id'] ?? ''));
$pakai_google = $google_maps_key !== '';

$tampil = static fn(string $teks): string => htmlspecialchars($teks, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if ($baris) {
    $row = $baris[0];
    $nilai = static function (string $kolom) use ($row): string {
        $teks = trim($row[$kolom]['value'] ?? '');
        return in_array($teks, ['', '-', '–', '—'], true) ? '' : $teks;
    };

    $nama = $nilai('nama') ?: 'Detail showroom';
    $kategori = strtolower($nilai('kategori'));
    $label_kategori = match ($kategori) {
        'mobil' => 'Showroom mobil',
        'motor' => 'Showroom motor',
        default => '',
    };
    $merek = $nilai('merek');
    $alamat = $nilai('alamat');
    $lokasi = $nilai('lokasi');
    $jam = $nilai('jam');
    $telepon = $nilai('telepon');
    $website = $nilai('website');
    $rating = $nilai('rating');

    $website_valid = filter_var($website, FILTER_VALIDATE_URL)
        && in_array(strtolower(parse_url($website, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true);
    $slug_logo = preg_replace('/^dealer\s+/i', '', strtolower($merek));
    if (in_array($slug_logo, ['honda', 'honda wing'], true)) {
        $slug_logo = $kategori === 'motor' || $slug_logo === 'honda wing' ? 'honda motor' : 'honda mobil';
    }
    $logo = preg_match('/\A[a-z0-9]+(?:[ -][a-z0-9]+)*\z/', $slug_logo)
        && is_file(__DIR__ . '/images/logo/' . $slug_logo . '.jpg')
        ? 'images/logo/' . rawurlencode($slug_logo . '.jpg')
        : '';

    $jadwal = jadwalHarianShowroom($jam);
    $status = statusShowroomPadaMenit($jadwal, menitWibShowroom());

    // Model kendaraan yang dijual: showroom -> ex:mewakiliMerek -> merek <- ex:diproduksiOleh <- model.
    $kendaraan = ambilKendaraanShowroom($uri, $kategori);

    // Pakai pin yang sama seperti katalog: satu pasangan koordinat valid per showroom.
    $pasangan = [];
    foreach ($baris as $calon) {
        $lat = $calon['lat']['value'] ?? '';
        $long = $calon['long']['value'] ?? '';
        if (!is_numeric($lat) || !is_numeric($long)) {
            continue;
        }
        $lat = (float) $lat;
        $long = (float) $long;
        if (is_finite($lat) && is_finite($long) && abs($lat) <= 90 && abs($long) <= 180) {
            $pasangan[$lat . ',' . $long] = [$lat, $long];
        }
    }
    $koordinat = count($pasangan) === 1 ? reset($pasangan) : null;
    $url_rute = $koordinat
        ? 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . rawurlencode(implode(',', $koordinat))
        : '';
    $url_lokasi = $koordinat
        ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(',', $koordinat))
        : '';
    $url_cari_alamat = !$koordinat && $alamat !== ''
        ? 'https://www.google.com/maps/search/?api=1&query='
            . rawurlencode(implode(', ', array_filter([$nama, $alamat, $lokasi, 'Sumatera Utara'])))
        : '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $tampil($nama ?? 'Detail showroom') ?> | Vroom</title>
    <link rel="icon" type="image/svg+xml" href="images/vroom.svg">
    <?php if (!empty($koordinat) && !$pakai_google): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"
            integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
    <?php endif; ?>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="css/status-showroom.css?v=1">
    <link rel="stylesheet" href="css/detail-showroom.css?v=4">
    <link rel="stylesheet" href="css/kendaraan-showroom.css?v=1">
    <link rel="stylesheet" href="css/riwayat-showroom.css?v=2">
    <link rel="stylesheet" href="css/rute-showroom.css?v=3">
</head>
<body>
<header class="vroom-header">
    <a href="index.php" class="vroom-brand" aria-label="Vroom, beranda">
        <img src="images/vroom.svg" alt="">
        <span class="vroom-brand-text"><strong>vroom<span>.</span></strong></span>
    </a>
    <div class="detail-header-aksi">
        <a href="index.php#daftar-showroom" class="detail-back-link">
            <span aria-hidden="true">←</span> Daftar showroom
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
    </div>
</header>

<?php if ($pesan_error !== ''): ?>
    <main class="vd-shell vd-error">
        <h1><?= $tampil($pesan_error) ?></h1>
        <a class="vd-button vd-button-primary" href="index.php#daftar-showroom">Lihat direktori showroom</a>
    </main>
<?php else: ?>
<main vocab="https://schema.org/" typeof="AutoDealer" about="<?= $tampil($uri) ?>"
    data-riwayat-showroom="<?= $tampil(json_encode([
        'uri' => $uri, 'nama' => $nama, 'merek' => $merek, 'kategori' => $kategori,
        'lokasi' => $lokasi, 'rating' => $rating, 'jam' => $jam, 'alamat' => $alamat,
        'telepon' => $telepon, 'logo' => $logo, 'jadwal' => (object) $jadwal,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <section class="vd-hero" aria-labelledby="vd-title">
        <div class="vd-shell vd-hero-grid">
            <div class="vd-heading">
                <div class="vd-hero-tags" data-filter-status-showroom="">
                    <?php if ($label_kategori !== ''): ?>
                        <span class="vd-type"><?= $tampil($label_kategori) ?></span>
                    <?php endif; ?>
                    <span data-jadwal-showroom="<?= $tampil(json_encode((object) $jadwal)) ?>">
                        <span class="showroom-status" data-status-showroom="<?= $status ?>"
                            title="Berdasarkan jadwal operasional harian (WIB)"><?= LABEL_STATUS_SHOWROOM[$status] ?></span>
                    </span>
                </div>
                <h1 id="vd-title" property="name"><?= $tampil($nama) ?></h1>
                <?php if ($lokasi !== '' || $rating !== ''): ?>
                    <div class="vd-hero-meta">
                        <?php if ($lokasi !== ''): ?>
                            <span property="areaServed"><?= $tampil($lokasi) ?>, Sumatera Utara</span>
                        <?php endif; ?>
                        <?php if ($rating !== ''): ?>
                            <span class="vd-rating"><span aria-hidden="true">★</span> <span class="vd-sr">Rating </span><?= $tampil($rating) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($logo !== ''): ?>
                <div class="vd-brand-card">
                    <img src="<?= $tampil($logo) ?>" alt="<?= $tampil('Logo ' . $merek) ?>">
                </div>
            <?php else: ?>
                <div class="vd-brand-card vd-brand-fallback">
                    <img src="images/vroom.svg" alt="Vroom">
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="vd-shell vd-content">
        <section class="vd-panel vd-location" aria-labelledby="vd-map-title">
    <div class="vr-kepala">
        <div class="vd-panel-heading">
            <h2 id="vd-map-title">Lokasi &amp; rute</h2>
        </div>
        <?php if ($koordinat || $url_cari_alamat !== ''): ?>
            <a class="vr-gmaps" href="<?= $tampil($koordinat ? $url_lokasi : $url_cari_alamat) ?>"
                target="_blank" rel="noopener noreferrer">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5Z"/></svg>
                <span>Buka di Google Maps</span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($koordinat): ?>
        <div class="vd-map-frame vr-peta">
            <div class="vd-map" id="peta-detail-showroom" role="region" tabindex="-1"
                aria-label="Peta lokasi <?= $tampil($nama) ?>"
                data-detail-map
                data-mesin="<?= $pakai_google ? 'google' : 'osm' ?>"
                <?php if ($pakai_google): ?>
                data-google-key="<?= $tampil($google_maps_key) ?>"
                data-google-map-id="<?= $tampil($google_map_id) ?>"
                <?php endif; ?>
                data-lat="<?= $tampil((string) $koordinat[0]) ?>"
                data-long="<?= $tampil((string) $koordinat[1]) ?>"
                data-nama="<?= $tampil($nama) ?>"
                data-alamat="<?= $tampil($alamat) ?>"
                data-kategori="<?= $tampil($kategori) ?>"></div>
            <p class="vd-map-placeholder" data-detail-map-placeholder aria-hidden="true">Menyiapkan peta…</p>
            <span class="vr-bidik" data-rute-bidik aria-hidden="true" hidden></span>
            <div class="vr-pilih-bar" data-rute-pilih-bar hidden>
                <span>Ketuk peta untuk menaruh titik berangkat</span>
                <div>
                    <button type="button" class="vr-pilih-pakai" data-rute-pilih-tengah>Pakai titik tengah</button>
                    <button type="button" class="vr-pilih-batal" data-rute-pilih-batal>Batal</button>
                </div>
            </div>
        </div>

        <p class="vd-map-message" role="status" data-detail-map-message hidden></p>
        <noscript><p class="vd-map-message">Aktifkan JavaScript untuk menghitung rute di Vroom.</p></noscript>

        <section class="vr-rute" aria-labelledby="vr-rute-judul" data-rute hidden>
            <div class="vr-rute-kepala">
                <h3 id="vr-rute-judul">Pilih titik berangkat</h3>
                <p>Rute mobil menuju <?= $tampil($nama) ?></p>
            </div>

            <div class="vr-opsi">
                <button type="button" class="vr-opsi-kartu vr-opsi-gps" data-rute-gps aria-controls="peta-detail-showroom">
                    <span class="vr-opsi-ikon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5" fill="currentColor"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                    </span>
                    <span class="vr-opsi-teks"><strong>Lokasi saya sekarang</strong><small>Pakai GPS perangkat</small></span>
                </button>
                <button type="button" class="vr-opsi-kartu vr-opsi-peta" data-rute-peta aria-controls="peta-detail-showroom" aria-pressed="false">
                    <span class="vr-opsi-ikon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6-5.6-6-11a6 6 0 0 1 12 0c0 5.4-6 11-6 11Z"/><circle cx="12" cy="10" r="2.2"/></svg>
                    </span>
                    <span class="vr-opsi-teks"><strong>Pilih di peta</strong><small>Ketuk titik berangkat</small></span>
                </button>
            </div>

            <div class="vr-baris-cari">
                <form class="vr-cari" data-rute-cari autocomplete="off">
                    <label class="vd-sr" for="vr-cari-input">Ketik alamat titik berangkat</label>
                    <svg class="vr-cari-ikon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>
                    <input id="vr-cari-input" type="text" inputmode="search" enterkeyhint="search"
                        placeholder="Atau ketik alamat awal…"
                        role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="vr-saran"
                        data-rute-cari-input>
                    <button type="submit" class="vr-cari-tombol" aria-label="Cari rute dari alamat ini">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </button>
                    <ul class="vr-saran" id="vr-saran" role="listbox" aria-label="Saran alamat" data-rute-saran hidden></ul>
                </form>
            </div>

            <p class="vr-status" role="status" data-rute-status></p>

            <div class="vr-hasil" data-rute-hasil hidden>
                <div class="vr-hasil-utama">
                    <div class="vr-metrik vr-metrik-waktu">
                        <span>Waktu tempuh</span>
                        <strong data-rute-durasi></strong>
                        <small data-rute-durasi-ket></small>
                    </div>
                    <div class="vr-metrik">
                        <span>Jarak</span>
                        <strong data-rute-jarak></strong>
                        <small data-rute-lewat></small>
                    </div>
                </div>

                <div class="vr-alternatif" role="radiogroup" aria-label="Pilihan rute" data-rute-alternatif hidden></div>

                <ol class="vr-titik" aria-label="Titik perjalanan">
                    <li><span>Dari</span><strong data-rute-asal></strong></li>
                    <li><span>Ke</span><strong><?= $tampil($nama) ?></strong></li>
                </ol>

                <div class="vr-hasil-aksi">
                    <a class="vr-navigasi" data-rute-navigasi href="<?= $tampil($url_rute) ?>" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.7 11.3 12.7 2.3a1 1 0 0 0-1.4 0l-9 9a1 1 0 0 0 0 1.4l9 9a1 1 0 0 0 1.4 0l9-9a1 1 0 0 0 0-1.4ZM14 14.5V12h-4v3H8v-4a1 1 0 0 1 1-1h5V7.5l3.5 3.5-3.5 3.5Z"/></svg>
                        Mulai navigasi di Google Maps
                    </a>
                    <button type="button" class="vr-ganti" data-rute-ganti>Ganti titik berangkat</button>
                </div>
                <p class="vr-sumber" data-rute-sumber></p>
            </div>
        </section>

        <div class="vd-map-footer">
            <p class="vd-coordinate">
                <span>Koordinat</span>
                <span property="geo" typeof="GeoCoordinates">
                    <span property="latitude"><?= $tampil((string) $koordinat[0]) ?></span>,
                    <span property="longitude"><?= $tampil((string) $koordinat[1]) ?></span>
                </span>
            </p>
        </div>
    <?php else: ?>
        <div class="vd-map-empty">
            <strong>Pin lokasi belum tersedia</strong>
            <p>Koordinat untuk cabang ini belum tercatat di Vroom.</p>
            <?php if ($url_cari_alamat !== ''): ?>
                <a href="<?= $tampil($url_cari_alamat) ?>" target="_blank" rel="noopener noreferrer">
                    Cari alamat di Google Maps <span aria-hidden="true">↗</span>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
        <section class="vd-panel vd-info" aria-labelledby="vd-info-title">
            <div class="vd-panel-heading">
                <h2 id="vd-info-title">Informasi showroom</h2>
            </div>
            <dl class="vd-facts">
                <div>
                    <dt>Alamat</dt>
                    <dd<?php if ($alamat !== ''): ?> property="address"<?php endif; ?>><?= $tampil($alamat ?: 'Belum tersedia') ?></dd>
                </div>
                <div>
                    <dt>Merek</dt>
                    <dd<?php if ($merek !== ''): ?> property="brand"<?php endif; ?>><?= $tampil($merek ?: 'Belum tersedia') ?></dd>
                </div>
                <div>
                    <dt>Jam operasional</dt>
                    <dd>
                        <?php if ($jam !== ''): ?>
                            <span property="openingHours"><?= $tampil($jam) ?></span><?= $status !== 'tidak-diketahui' && preg_match('/[.:]\d{2}\s*[-–—]/u', $jam) ? '&nbsp;WIB' : '' ?>
                        <?php else: ?>
                            Belum tersedia
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Telepon</dt>
                    <dd>
                        <?php if ($telepon !== ''): ?>
                            <span property="telephone"><?= $tampil($telepon) ?></span>
                        <?php else: ?>
                            Belum tersedia
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Situs resmi</dt>
                    <dd>
                        <?php if ($website_valid): ?>
                            <a href="<?= $tampil($website) ?>" property="url" target="_blank"
                                rel="noopener noreferrer">Kunjungi situs <span aria-hidden="true">↗</span></a>
                        <?php else: ?>
                            Belum tersedia
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
        </section>
    </div>

    <?php require __DIR__ . '/card-kendaraan-showroom.php'; ?>
</main>
<?php endif; ?>

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

<script src="js/status-showroom.js?v=1" defer></script>
<script src="js/kendaraan-showroom.js?v=1" defer></script>
<script src="js/riwayat-showroom.js?v=3" defer></script>
<?php if (!empty($koordinat)): ?>
    <?php if (!$pakai_google): ?>
        <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous" defer></script>
    <?php endif; ?>
    <script src="js/rute-showroom.js?v=2" defer></script>
<?php endif; ?>
</body>
</html>