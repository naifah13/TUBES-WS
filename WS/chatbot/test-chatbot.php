<?php
/*
 * Tes skenario Asisten Vroom (tanpa HTTP).
 * Jalankan dari folder WS:  php chatbot/test-chatbot.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Skenario 15: matikan akses jaringan PHP sebelum apa pun dimuat.
// Jika chatbot mencoba HTTP (Fuseki/Gemini), panggilannya akan gagal dan tes ikut gagal.
foreach (['http', 'https', 'ftp'] as $wrapper) {
    if (in_array($wrapper, stream_get_wrappers(), true)) {
        stream_wrapper_unregister($wrapper);
    }
}

require_once __DIR__ . '/nlu.php';

const MEDAN = ['lat' => 3.5913, 'lon' => 98.6779, 'akurasi_m' => 25];

$pass = 0;
$fail = 0;

function waktuUji(string $hhmm, int $offset = -420): array
{
    $now = new DateTimeImmutable('today ' . $hhmm, new DateTimeZone('Asia/Jakarta'));
    return [
        'now' => $now,
        'waktu' => ['iso' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'), 'offset_menit' => $offset, 'zona' => 'Asia/Jakarta'],
    ];
}

function tanya(string $pesan, string $jam = '17:31', ?array $lokasi = MEDAN, ?array $konteks = null, int $offset = -420): array
{
    $t = waktuUji($jam, $offset);
    $request = ['pesan' => $pesan, 'waktu' => $t['waktu'], 'lokasi' => $lokasi];
    if ($konteks !== null) {
        $request['konteks'] = $konteks;
    }
    return vroomChatReply($request, $t['now']);
}

function cek(string $nama, callable $uji): void
{
    global $pass, $fail;
    try {
        $hasil = $uji();
        $ok = $hasil === true;
        $alasan = is_string($hasil) ? $hasil : '';
    } catch (Throwable $e) {
        $ok = false;
        $alasan = get_class($e) . ': ' . $e->getMessage();
    }
    if ($ok) {
        $pass++;
        echo "PASS  $nama\n";
    } else {
        $fail++;
        echo "FAIL  $nama" . ($alasan !== '' ? "\n      → $alasan" : '') . "\n";
    }
}

function nama(array $reply): array
{
    return array_column($reply['hasil'], 'nama');
}

function urutJarak(array $reply): bool
{
    $d = array_map(static fn (array $h): float => $h['jarak_km'] ?? INF, $reply['hasil']);
    $sorted = $d;
    sort($sorted);
    return $d === $sorted;
}

function terbuka(string $status): bool
{
    return in_array($status, ['buka', 'segera-tutup', '24jam'], true);
}

function ringkas(array $r): string
{
    return '[' . $r['intent'] . '] ' . $r['pesan'] . ' | ' . implode(' ; ', array_map(
        static fn (array $h): string => $h['nama'] . ' (' . $h['jarak_teks'] . ', ' . $h['jam'] . ', ' . $h['status_label'] . ')',
        $r['hasil']
    ));
}

/* ---------------------------------------------------------------------- */

echo "Asisten Vroom: tes skenario\n";
echo str_repeat('=', 60) . "\n";

cek('#1  showroom terdekat (lokasi aktif)', function () {
    $r = tanya('showroom terdekat');
    $expect = ['TOYOTA DELTAMAS BALAI KOTA', 'City Store Jaecoo Medan', 'Mitsubishi Sardana Medan - Putri Hijau', 'VESPARK Indonesia', 'Daihatsu Motor'];
    if (nama($r) !== $expect) {
        return 'urutan: ' . implode(', ', nama($r));
    }
    foreach ($r['hasil'] as $h) {
        if ($h['jam'] === '' || $h['status_teks'] === '' || $h['jarak_teks'] === '') {
            return 'kartu tanpa jam/status/jarak: ' . $h['nama'];
        }
    }
    $jarak = array_column($r['hasil'], 'jarak_teks');
    return $jarak[0] === '190 m' && $jarak[1] === '350 m' && $r['ada_lagi'] === true && in_array('Jarak dihitung garis lurus dari lokasimu.', $r['catatan'], true)
        ? true : 'jarak/catatan: ' . implode(', ', $jarak);
});

cek('#2  showroom yang masih buka pukul 21:30 WIB', function () {
    $r = tanya('showroom mana yang masih buka?', '21:30');
    $names = nama($r);
    foreach ($r['hasil'] as $h) {
        if (!terbuka($h['status'])) {
            return 'ada yang tutup: ' . $h['nama'];
        }
    }
    if (($names[0] ?? '') !== 'City Store Jaecoo Medan' || $r['hasil'][0]['status'] !== 'segera-tutup') {
        return ringkas($r);
    }
    foreach (['Hyundai Medan Official', 'BMW & MINI Cooper Official Medan Consultant Care', 'Honda IDK MEDAN'] as $n) {
        if (!in_array($n, $names, true)) {
            return "tidak ada $n: " . ringkas($r);
        }
    }
    return urutJarak($r) && str_starts_with($r['pesan'], 'Sekarang pukul 21.30 WIB (') ? true : ringkas($r);
});

cek('#3  rating tertinggi dekat saya', function () {
    $r = tanya('showroom rating tertinggi dekat saya');
    $names = nama($r);
    if (array_slice($names, 0, 3) !== ['City Store Jaecoo Medan', 'Hyundai Medan Official', 'BYD ARISTA THAMRIN']) {
        return ringkas($r);
    }
    foreach ($r['hasil'] as $h) {
        if ($h['rating'] !== 5.0 || $h['jarak_teks'] === '' || $h['jam'] === '') {
            return 'kartu: ' . $h['nama'];
        }
    }
    return str_contains($r['pesan'], 'radius 10 km') ? true : $r['pesan'];
});

cek('#4  showroom yang menjual mobil Ford (lokasi aktif)', function () {
    $r = tanya('dimana showroom yang ada menjual mobil ford');
    $h = $r['hasil'][0] ?? null;
    if ($r['total'] !== 1 || $h === null || $h['nama'] !== 'Ford Medan Gatot Subroto') {
        return ringkas($r);
    }
    return $h['jarak_teks'] === '4,8 km' && $h['jam'] === '08.30 – 16.30' && $h['rating_teks'] === '4,4'
        && str_starts_with($h['alamat'], 'Jl. Gatot Subroto No.107') && str_contains($r['pesan'], 'hanya ada 1')
        && str_contains($h['rute'], '&origin=3.5913,98.6779')
        ? true : ringkas($r);
});

cek('#5  showroom Ford (lokasi ditolak)', function () {
    $r = tanya('dimana showroom yang ada menjual mobil ford', '17:31', null);
    $h = $r['hasil'][0] ?? null;
    $info = implode(' ', $r['catatan']);
    return $h !== null && $h['nama'] === 'Ford Medan Gatot Subroto' && $h['jarak_km'] === null
        && str_contains($info, 'Aktifkan lokasi') && !str_contains((string) $h['rute'], 'origin=')
        ? true : ringkas($r) . ' | ' . $info;
});

cek('#6  beli ranger dimana?', function () {
    $r = tanya('beli ranger dimana?', '17:31', null);
    if (!str_starts_with($r['pesan'], 'Ranger adalah model Ford menurut data Vroom')) {
        return $r['pesan'];
    }
    foreach ($r['hasil'] as $h) {
        if (!in_array('Ford', $h['merek'], true)) {
            return 'bukan Ford: ' . $h['nama'];
        }
    }
    return $r['hasil'] !== [] && $r['intent'] === 'info_model_dari_rdf' ? true : ringkas($r);
});

cek('#7  dealer toyota di siantar yang buka sekarang (10:00)', function () {
    foreach ([MEDAN, null] as $lokasi) {
        $r = tanya('dealer toyota di siantar yang buka sekarang', '10:00', $lokasi);
        if ($r['hasil'] === [] || $r['konteks']['wilayah'] !== 'Pematangsiantar' || $r['konteks']['merek'] !== 'Toyota') {
            return ringkas($r);
        }
        foreach ($r['hasil'] as $h) {
            if ($h['wilayah'] !== 'Pematangsiantar' || !in_array('Toyota', $h['merek'], true) || !terbuka($h['status'])) {
                return 'kartu: ' . $h['nama'];
            }
        }
        if ($lokasi !== null && !urutJarak($r)) {
            return 'tidak urut jarak: ' . ringkas($r);
        }
        if ($lokasi === null) {
            $ratings = array_column($r['hasil'], 'rating');
            $sorted = $ratings;
            rsort($sorted);
            if ($ratings !== $sorted) {
                return 'tidak urut rating: ' . ringkas($r);
            }
        }
    }
    return true;
});

cek('#8  showroom motor honda buka jam 7 malam', function () {
    $r = tanya('showroom motor honda buka jam 7 malam', '12:00');
    $k = $r['konteks'];
    if ($k['kategori'] !== 'motor' || $k['merek'] !== 'Honda' || $k['jam'] !== '19:00' || !str_contains($k['mode'], 'buka_pada_jam')) {
        return 'konteks: ' . json_encode($k);
    }
    // Bandingkan dengan perhitungan manual dari data RDF.
    $ix = vroomRdfIndex();
    $manual = 0;
    foreach ($ix['showroom'] as $s) {
        if ($s['kategori'] === 'motor' && in_array('Honda', $s['merek_kunci'], true)
            && vroomJamTerbuka(vroomJamStatus(vroomJamParse($s['jam']), 19 * 60))) {
            $manual++;
        }
    }
    foreach ($r['hasil'] as $h) {
        if ($h['kategori'] !== 'motor' || !in_array('Honda', $h['merek'], true) || !terbuka($h['status'])) {
            return 'kartu: ' . $h['nama'];
        }
    }
    return $r['total'] === $manual && str_contains($r['pesan'], '19.00') && str_contains($r['pesan'], 'jadwal harian')
        ? true : "total {$r['total']} vs manual $manual: " . $r['pesan'];
});

cek('#9  jam buka Capella Medan Kisaran?', function () {
    $r = tanya('jam buka Capella Medan Kisaran?', '17:31', null);
    $h = $r['hasil'][0] ?? null;
    return $r['intent'] === 'info_showroom' && $h !== null && $h['nama'] === 'Capella Medan Kisaran'
        && $h['jam'] === '08.00 – 16.00' && str_contains($r['pesan'], '08.00 – 16.00 WIB')
        && str_contains($r['pesan'], 'tutup, buka besok pukul 08.00') && $h['alamat'] !== '' && $h['telepon'] !== ''
        ? true : ringkas($r);
});

cek('#10 "yang Toyota aja" setelah #1 (konteks)', function () {
    $first = tanya('showroom terdekat');
    $r = tanya('yang Toyota aja', '17:31', MEDAN, $first['konteks']);
    if ($r['konteks']['merek'] !== 'Toyota' || !str_contains($r['konteks']['mode'], 'terdekat') || $r['intent'] !== 'lanjutan') {
        return 'konteks: ' . json_encode($r['konteks']);
    }
    foreach ($r['hasil'] as $h) {
        if (!in_array('Toyota', $h['merek'], true)) {
            return 'bukan Toyota: ' . $h['nama'];
        }
    }
    $next = tanya('Tampilkan lainnya', '17:31', MEDAN, $r['konteks']);
    $overlap = array_intersect(nama($r), nama($next));
    return urutJarak($r) && $next['konteks']['halaman'] === 2 && $next['hasil'] !== [] && $overlap === []
        ? true : 'halaman 2: ' . ringkas($next);
});

foreach (['berapa harga Avanza?', 'spesifikasi mesin Ranger?'] as $q) {
    cek('#11 handler: "' . $q . '"', fn () => tanya($q)['pesan'] === VROOM_HANDLER ? true : tanya($q)['pesan']);
}
foreach (['siapa presiden Indonesia?', 'cuaca hari ini'] as $q) {
    cek('#12 handler: "' . $q . '"', fn () => tanya($q)['pesan'] === VROOM_HANDLER ? true : tanya($q)['pesan']);
}

cek('#13 Nusantara Sakti Group (tanpa jam operasional)', function () {
    $r = tanya('Nusantara Sakti Group');
    $h = $r['hasil'][0] ?? null;
    return $h !== null && $h['id'] === VROOM_RDF_NS . 'Showroom306' && $h['status'] === 'tidak-diketahui'
        && $h['status_teks'] === 'Jam operasional belum tersedia' && str_contains($r['pesan'], 'belum tersedia')
        ? true : ringkas($r);
});

cek('#14 showroom nissan (multi merek)', function () {
    $r = tanya('showroom nissan');
    foreach ($r['hasil'] as $h) {
        if ($h['id'] === VROOM_RDF_NS . 'Showroom69') {
            return implode(' · ', $h['merek']) === 'Nissan · Datsun · AION · KIA' ? true : implode(' · ', $h['merek']);
        }
    }
    return ringkas($r);
});

cek('#15 showroom mobil tanpa Fuseki & tanpa internet', function () {
    // a) Bangun ulang indeks langsung dari .ttl (tanpa cache), jaringan PHP dimatikan di atas.
    $fresh = vroomRdfBuildIndex(vroomRdfDir());
    if (count($fresh['showroom']) !== 279) {
        return 'jumlah showroom ' . count($fresh['showroom']);
    }
    $r = vroomChatReply(['pesan' => 'showroom mobil', 'waktu' => waktuUji('17:31')['waktu']], waktuUji('17:31')['now'], $fresh);
    if ($r['hasil'] === [] || $r['konteks']['kategori'] !== 'mobil') {
        return ringkas($r);
    }
    foreach ($r['hasil'] as $h) {
        if ($h['kategori'] !== 'mobil') {
            return 'kategori: ' . $h['nama'];
        }
    }
    // b) Tidak ada lib.php/Fuseki/Gemini/cURL di kode chatbot.
    foreach (get_included_files() as $file) {
        if (basename($file) === 'lib.php') {
            return 'lib.php ikut dimuat';
        }
    }
    $root = dirname(__DIR__);
    foreach (['vroom-chatbot.php', 'chatbot/nlu.php', 'chatbot/rdf-store.php'] as $file) {
        $code = (string) file_get_contents($root . '/' . $file);
        foreach (['curl_', 'executeSparqlQuery', 'localhost:3030', 'GEMINI', 'Gemini', 'lib.php', 'fsockopen', 'stream_socket_client'] as $bad) {
            if (str_contains($code, $bad)) {
                return "$file masih memuat \"$bad\"";
            }
        }
    }
    return true;
});

cek('#16 halo', function () {
    $r = tanya('halo');
    return $r['intent'] === 'sapaan' && count($r['saran']) === 3 && $r['hasil'] === [] ? true : ringkas($r);
});

echo str_repeat('-', 60) . "\nTes tambahan\n";

cek('parser jam: semua variasi format', function () {
    $cases = [
        '08.00-17.00' => [480, 1020], '08:00-17:00' => [480, 1020], '08.00 - 17.00' => [480, 1020], '08.00-17:00' => [480, 1020],
        '08.00–17.00' => [480, 1020], '08.00 — 17.00' => [480, 1020], '22.00-02.00' => [1320, 120],
    ];
    foreach ($cases as $text => [$open, $close]) {
        $j = vroomJamParse($text);
        if ($j['tipe'] !== 'rentang' || $j['buka'] !== $open || $j['tutup'] !== $close) {
            return "$text → " . json_encode($j);
        }
    }
    $night = vroomJamParse('22.00-02.00');
    return vroomJamParse('Buka 24 jam')['tipe'] === '24jam' && vroomJamParse('')['tipe'] === 'kosong'
        && vroomJamStatus($night, 60)['status'] === 'buka' && vroomJamStatus($night, 23 * 60)['status'] === 'buka'
        && vroomJamStatus($night, 12 * 60)['status'] === 'tutup' && vroomJamStatus($night, 105)['status'] === 'segera-tutup'
        ? true : 'lewat tengah malam';
});

cek('parser jam: semua 278 jam di data terbaca', function () {
    foreach (vroomRdfIndex()['showroom'] as $s) {
        $t = vroomJamParse($s['jam'])['tipe'];
        if (!in_array($t, ['rentang', '24jam'], true) && $s['id'] !== VROOM_RDF_NS . 'Showroom306') {
            return $s['id'] . ' "' . $s['jam'] . '"';
        }
    }
    return true;
});

cek('data: rating koma/titik, merek "Aion", "Honda Wing"', function () {
    $ix = vroomRdfIndex();
    $ratings = array_filter(array_column($ix['showroom'], 'rating'), static fn ($v): bool => $v === null);
    $aion = array_filter($ix['showroom'], static fn (array $s): bool => in_array('AION', $s['merek_kunci'], true));
    $wing = $ix['showroom'][VROOM_RDF_NS . 'Showroom191'];
    return $ratings === [] && count($aion) === 2 && in_array('Honda', $wing['merek_kunci'], true)
        && $ix['showroom'][VROOM_RDF_NS . 'Showroom1']['rating'] === 4.7 ? true : 'rating/merek';
});

cek('teks panjang + emoji dipotong per karakter (bukan dikosongkan)', function () {
    $text = str_repeat('🚗', 500);
    $cut = vroomChatText($text, 400);
    return mb_strlen($cut) === 400 && vroomChatText('  halo  ', 400) === 'halo' ? true : 'panjang ' . mb_strlen($cut);
});

cek('zona WITA disebut + konversi ke WIB', function () {
    $r = tanya('showroom yang masih buka', '17:31', MEDAN, null, -480);
    return str_starts_with($r['pesan'], 'Sekarang pukul 18.31 WITA (17.31 WIB') ? true : $r['pesan'];
});

cek('waktu klien > 24 jam dari server ditolak (pakai jam server)', function () {
    $server = new DateTimeImmutable('today 10:00', new DateTimeZone('Asia/Jakarta'));
    $t = vroomWaktuPengguna(['iso' => '2001-01-01T15:00:00Z', 'offset_menit' => -420], $server);
    return $t['sumber'] === 'server' && $t['jam_wib'] === '10.00' ? true : json_encode($t);
});

cek('radius: kosong di dalam radius → 3 terdekat di luar radius', function () {
    $r = tanya('showroom di sibolga dalam 5 km');
    return count($r['hasil']) === 3 && str_contains($r['pesan'], 'Tidak ada') && str_contains($r['pesan'], 'radius 5 km') ? true : ringkas($r);
});

cek('lokasi di luar kota tidak dibuang (titik Sibolga)', function () {
    $r = tanya('showroom terdekat', '10:00', ['lat' => 1.7427, 'lon' => 98.7792]);
    return count($r['hasil']) === 5 && $r['hasil'][0]['wilayah'] === 'Sibolga' ? true : ringkas($r);
});

cek('model pendek "city" tidak salah tangkap tanpa kata pemicu', function () {
    $e = vroomEkstrak(vroomNormalisasi('jalan-jalan ke city walk'), vroomRdfIndex());
    $e2 = vroomEkstrak(vroomNormalisasi('beli honda city dimana'), vroomRdfIndex());
    return $e['model'] === [] && $e2['model'] === ['city'] ? true : json_encode([$e['model'], $e2['model']]);
});

cek('ejaan: "dmn showroom yg dkt skrg"', function () {
    $r = tanya('dmn showroom yg dkt skrg');
    return str_contains($r['konteks']['mode'], 'terdekat') && $r['hasil'][0]['nama'] === 'TOYOTA DELTAMAS BALAI KOTA' ? true : ringkas($r);
});

cek('lanjutan "kalau yang motor?" membuang merek Toyota (tidak ada motor Toyota)', function () {
    $r = tanya('kalau yang motor?', '17:31', MEDAN, ['merek' => 'Toyota', 'mode' => 'terdekat']);
    return $r['konteks']['kategori'] === 'motor' && $r['konteks']['merek'] === '' && $r['hasil'] !== [] ? true : ringkas($r);
});

cek('merek tak ada di data → kalimat handler', fn () => tanya('showroom lexus')['pesan'] === VROOM_HANDLER ? true : tanya('showroom lexus')['pesan']);

cek('cache JSON ditulis & dibaca ulang', function () {
    $file = vroomRdfCacheFile();
    if (!is_file($file)) {
        return is_writable(dirname($file)) ? 'cache tidak terbentuk' : true; // folder read-only: tetap jalan tanpa cache
    }
    $cached = json_decode((string) file_get_contents($file), true);
    return is_array($cached) && count($cached['showroom']) === 279 ? true : 'cache rusak';
});

echo str_repeat('=', 60) . "\n";
echo "Hasil: $pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
