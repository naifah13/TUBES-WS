<?php
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
?>
<div class="grid-container showroom-results-grid" vocab="http://schema.org/">
    <?php foreach (($daftar_kartu ?? []) as $row):
        $nama = $row['namaShowroom']['value'] ?? 'Tanpa Nama';
        $kategori = $row['kategori']['value'] ?? '-';
        $merek = $row['merek']['value'] ?? '-';
        $lokasi = $row['lokasi']['value'] ?? 'Sumatera Utara';
        $rating = $row['rating']['value'] ?? '0';
        $jam = $row['jam']['value'] ?? '-';
        $uri = $row['showroom']['value'] ?? '';

        $namaMerek = strtolower(trim($merek));
        $kategoriKendaraan = strtolower(trim($kategori));
        $logoFile = null;

        if (str_contains($namaMerek, 'honda')) {
            $logoFile = str_contains($kategoriKendaraan, 'motor') || str_contains($namaMerek, 'wing')
                ? 'honda motor.jpg'
                : 'honda mobil.jpg';
        } else {
            foreach ($daftarLogo as $kataMerek => $namaFile) {
                if (preg_match('/\b' . preg_quote($kataMerek, '/') . '\b/i', $namaMerek)) {
                    $logoFile = $namaFile;
                    break;
                }
            }
        }

        $logoTersedia = $logoFile !== null && is_file(__DIR__ . '/images/logo/' . $logoFile);
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
    <?php endforeach; ?>
</div>
