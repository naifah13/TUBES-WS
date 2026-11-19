<?php
require_once __DIR__ . '/jam-showroom.php';

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
$status_diminta = $status_diminta ?? '';
$menit_wib ??= menitWibShowroom();
$ada_kartu_tampil = false;
?>
<div class="grid-container showroom-results-grid" vocab="http://schema.org/"
    data-filter-status-showroom="<?= htmlspecialchars($status_diminta) ?>">
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
        $jadwal = jadwalHarianShowroom($jam);
        $status_kartu = statusShowroomPadaMenit($jadwal, $menit_wib);
        $sembunyikan = $status_diminta !== '' && $status_kartu !== $status_diminta;
        $ada_kartu_tampil = $ada_kartu_tampil || !$sembunyikan;
    ?>
    <article class="showroom-card" typeof="AutoDealer"
        data-jadwal-showroom="<?= htmlspecialchars(json_encode((object) $jadwal)) ?>"
        <?= $sembunyikan ? 'hidden' : '' ?>>

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

            <span class="showroom-status" data-status-showroom="<?= $status_kartu ?>"
                title="Berdasarkan jadwal operasional harian (WIB)">
                <?= LABEL_STATUS_SHOWROOM[$status_kartu] ?>
            </span>

        </div>

        <div class="showroom-content">

            <div class="showroom-identity">
                <p class="showroom-brand" property="brand">
                    <?= htmlspecialchars($merek) ?>
                </p>
                <?php if (in_array($kategoriKendaraan, ['mobil', 'motor'], true)): ?>
                    <span class="showroom-category"><?= htmlspecialchars(ucfirst($kategoriKendaraan)) ?></span>
                <?php endif; ?>
            </div>

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

                    <span>
                        <span property="openingHours"><?= htmlspecialchars($jam) ?></span><?= $status_kartu !== 'tidak-diketahui' ? '&nbsp;WIB' : '' ?>
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
<?php if ($status_diminta !== ''): ?>
    <div class="showroom-results-note showroom-live-empty" role="status" data-status-showroom-kosong
        <?= $ada_kartu_tampil ? 'hidden' : '' ?>>
        <span class="showroom-results-info-icon" aria-hidden="true">i</span>
        <div>
            <p><strong>Saat ini belum ada showroom dengan status “<?= LABEL_STATUS_SHOWROOM[$status_diminta] ?>”.</strong></p>
            <div class="showroom-results-actions">
                <a href="<?= htmlspecialchars($url_tanpa_status ?? 'index.php#hasil') ?>">Tampilkan semua status</a>
            </div>
        </div>
    </div>
<?php endif; ?>
