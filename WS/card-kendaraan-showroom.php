<?php
/*
 * Bagian "Kendaraan yang tersedia" di detail.php.
 * Butuh: $kendaraan (hasil ambilKendaraanShowroom), $nama, $kategori, $tampil.
 */

/** @var array|null $kendaraan */
/** @var string $nama */
/** @var string $kategori */
/** @var \Closure $tampil */

if ($kendaraan === null) {
    return; // Fuseki tidak bisa dihubungi: bagian ini disembunyikan, halaman tetap tampil.
}

$daftar_merek_kendaraan = array_values(array_unique(array_column($kendaraan, 'merek')));
$jenis_kendaraan = $kategori === 'motor' ? 'motor' : ($kategori === 'mobil' ? 'mobil' : 'kendaraan');
$jumlah_kendaraan = count($kendaraan);
?>
<section class="vk-section" id="kendaraan" aria-labelledby="vk-title" data-kendaraan>
    <div class="vd-shell">
        <div class="vk-head">
            <div class="vk-head-text">
                <p class="vk-eyebrow">Katalog showroom</p>
                <h2 id="vk-title"><?= $jenis_kendaraan === 'motor' ? 'Motor' : ($jenis_kendaraan === 'mobil' ? 'Mobil' : 'Kendaraan') ?> yang tersedia</h2>
                <p class="vk-sub">
                    <?php if ($jumlah_kendaraan > 0): ?>
                        <strong><?= $jumlah_kendaraan ?> model <?= $tampil(implode(' & ', $daftar_merek_kendaraan)) ?></strong>
                        yang bisa kamu temukan di <?= $tampil($nama) ?>.
                    <?php else: ?>
                        Daftar <?= $jenis_kendaraan ?> untuk showroom ini belum tersedia.
                    <?php endif; ?>
                </p>
            </div>

            <?php if (count($daftar_merek_kendaraan) > 1): ?>
                <div class="vk-filter" role="group" aria-label="Saring berdasarkan merek">
                    <button type="button" class="vk-chip-filter" aria-pressed="true" data-kendaraan-merek="">Semua</button>
                    <?php foreach ($daftar_merek_kendaraan as $merek_filter): ?>
                        <button type="button" class="vk-chip-filter" aria-pressed="false"
                            data-kendaraan-merek="<?= $tampil($merek_filter) ?>"><?= $tampil($merek_filter) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($jumlah_kendaraan > 0): ?>
            <div class="vk-grid">
                <?php foreach ($kendaraan as $i => $unit):
                    $tipe_schema = $unit['kategori'] === 'motor' ? 'Motorcycle' : 'Car';
                ?>
                    <article class="vk-card" property="makesOffer" typeof="Offer"
                        data-kendaraan-kartu data-merek="<?= $tampil($unit['merek']) ?>"
                        style="--vk-urutan: <?= $i % KENDARAAN_TAMPIL_AWAL ?>"
                        <?= $i >= KENDARAAN_TAMPIL_AWAL ? 'hidden data-kendaraan-lebih' : '' ?>>
                        <div property="itemOffered" typeof="<?= $tipe_schema ?>" resource="<?= $tampil($unit['uri']) ?>">
                            <div class="vk-media<?= $unit['foto'] === '' ? ' vk-media-kosong' : '' ?>">
                                <?php if ($unit['foto'] !== ''): ?>
                                    <img src="<?= $tampil($unit['foto']) ?>" property="image"
                                        alt="<?= $tampil($unit['merek'] . ' ' . $unit['nama']) ?>"
                                        loading="lazy">
                                <?php else: ?>
                                    <?php if ($unit['logo'] !== ''): ?>
                                        <span class="vk-logo-bulat"><img src="<?= $tampil($unit['logo']) ?>" alt=""></span>
                                    <?php endif; ?>
                                    <span class="vk-foto-segera">Foto segera hadir</span>
                                <?php endif; ?>
                                <span class="vk-kategori"><?= $unit['kategori'] === 'motor' ? 'Motor' : 'Mobil' ?></span>
                            </div>

                            <div class="vk-body">
                                <p class="vk-brand" property="brand"><?= $tampil($unit['merek']) ?></p>
                                <h3 class="vk-name" property="name"><?= $tampil($unit['nama']) ?></h3>
                                <p class="vk-status">
                                    <span class="vk-dot" aria-hidden="true"></span>
                                    Tersedia<span class="vk-status-ekstra"> di showroom ini</span>
                                </p>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($jumlah_kendaraan > KENDARAAN_TAMPIL_AWAL): ?>
                <div class="vk-more">
                    <button type="button" class="vk-more-btn" aria-expanded="false" data-kendaraan-tombol
                        data-teks-buka="Tampilkan semua <?= $jumlah_kendaraan ?> model"
                        data-teks-tutup="Tampilkan lebih sedikit">
                        <span data-kendaraan-tombol-teks>Tampilkan semua <?= $jumlah_kendaraan ?> model</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
                    </button>
                </div>
            <?php endif; ?>

            <p class="vk-note">Daftar model diambil dari relasi merek showroom pada data RDF Vroom. Ketersediaan unit bisa berbeda, hubungi showroom untuk memastikan.</p>
        <?php endif; ?>
    </div>
</section>
