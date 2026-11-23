<?php
/*
 * Pemahaman pertanyaan berbasis aturan untuk Asisten Vroom.
 *
 * Tanpa AI dan tanpa API: normalisasi teks + kamus yang dibangun dari RDF
 * (merek, model, wilayah), lalu pencarian di indeks lokal dari rdf-store.php.
 * Semua jawaban hanya memakai data di file .ttl.
 */
declare(strict_types=1);

require_once __DIR__ . '/rdf-store.php';

const VROOM_HANDLER = 'Wah, untuk informasi itu saya belum bisa memberikan jawaban yang tepat. Namun, saya tetap bisa membantu pertanyaan lainnya.';
const VROOM_PER_HALAMAN = 5;
const VROOM_MODE_URUT = ['terdekat', 'rating_tertinggi', 'paling_lama_buka'];
const VROOM_MODE_SARING = ['buka_sekarang', 'buka_pada_jam', 'buka_24jam', 'tutup_sekarang', 'tutup_pada_jam'];

/* ------------------------------------------------------------------------
 * 1. Validasi input
 * --------------------------------------------------------------------- */

/** Rapikan teks: UTF-8 valid, tanpa karakter kontrol, dipotong per karakter (bukan dikosongkan). */
function vroomChatText(mixed $value, int $limit): string
{
    if (is_int($value) || is_float($value)) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return '';
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_scrub($value, 'UTF-8');
    }
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $value) ?? '');
    return mb_strlen($value, 'UTF-8') > $limit ? rtrim(mb_substr($value, 0, $limit, 'UTF-8')) : $value;
}

function vroomLokasi(mixed $input): ?array
{
    if (!is_array($input) || !is_numeric($input['lat'] ?? null) || !is_numeric($input['lon'] ?? null)) {
        return null;
    }
    $lat = (float) $input['lat'];
    $lon = (float) $input['lon'];
    if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) {
        return null;
    }
    $acc = is_numeric($input['akurasi_m'] ?? null) ? max(0.0, (float) $input['akurasi_m']) : null;
    return ['lat' => $lat, 'lon' => $lon, 'akurasi_m' => $acc];
}

function vroomKonteksKosong(): array
{
    return ['kategori' => '', 'wilayah' => '', 'merek' => '', 'model' => '', 'mode' => '', 'jam' => '', 'radius_km' => null, 'halaman' => 1];
}

/** Konteks dari klien hanya dipercaya jika nilainya ada di data RDF. */
function vroomKonteks(mixed $input, array $ix): array
{
    $k = vroomKonteksKosong();
    if (!is_array($input)) {
        return $k;
    }
    $kategori = vroomChatText($input['kategori'] ?? '', 10);
    $k['kategori'] = in_array($kategori, ['mobil', 'motor'], true) ? $kategori : '';

    $regions = [];
    foreach (explode('|', vroomChatText($input['wilayah'] ?? '', 200)) as $region) {
        if (in_array($region, $ix['wilayah'], true)) {
            $regions[] = $region;
        }
    }
    $k['wilayah'] = implode('|', array_unique($regions));

    $merek = vroomChatText($input['merek'] ?? '', 60);
    $k['merek'] = isset($ix['merek'][$merek]) ? $merek : '';
    $model = mb_strtolower(vroomChatText($input['model'] ?? '', 60));
    $k['model'] = isset($ix['model'][$model]) ? $model : '';

    $modes = array_intersect(explode(',', vroomChatText($input['mode'] ?? '', 120)), array_merge(VROOM_MODE_URUT, VROOM_MODE_SARING));
    $k['mode'] = implode(',', array_values(array_unique($modes)));

    $jam = vroomChatText($input['jam'] ?? '', 5);
    $k['jam'] = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $jam) ? $jam : '';
    if (!array_intersect($modes, ['buka_pada_jam', 'tutup_pada_jam'])) {
        $k['jam'] = '';
    }

    $radius = $input['radius_km'] ?? null;
    $k['radius_km'] = is_numeric($radius) && (float) $radius >= 0.1 && (float) $radius <= 500 ? round((float) $radius, 1) : null;
    $page = filter_var($input['halaman'] ?? 1, FILTER_VALIDATE_INT);
    $k['halaman'] = is_int($page) && $page >= 1 && $page <= 60 ? $page : 1;
    return $k;
}

function vroomKonteksAktif(array $k): bool
{
    return $k['kategori'] !== '' || $k['wilayah'] !== '' || $k['merek'] !== '' || $k['model'] !== ''
        || $k['mode'] !== '' || $k['radius_km'] !== null;
}

function vroomModes(array $k): array
{
    return $k['mode'] === '' ? [] : explode(',', $k['mode']);
}

/* ------------------------------------------------------------------------
 * 2. Normalisasi teks
 * --------------------------------------------------------------------- */

const VROOM_EJAAN = [
    'ddengan' => 'dengan', 'dgn' => 'dengan', 'dg' => 'dengan',
    'dmn' => 'dimana', 'dmna' => 'dimana', 'dimn' => 'dimana', 'dimanakah' => 'dimana',
    'yg' => 'yang', 'yng' => 'yang',
    'skrg' => 'sekarang', 'skrng' => 'sekarang', 'skg' => 'sekarang', 'skr' => 'sekarang',
    'dkt' => 'dekat', 'deket' => 'dekat', 'dekat2' => 'dekat', 'terdeket' => 'terdekat', 'trdekat' => 'terdekat', 'terdkt' => 'terdekat',
    'brp' => 'berapa', 'jm' => 'jam',
    'sy' => 'saya', 'aq' => 'aku', 'utk' => 'untuk', 'dr' => 'dari', 'kpn' => 'kapan',
    'gmn' => 'gimana', 'gmna' => 'gimana', 'gk' => 'tidak', 'ga' => 'tidak', 'gak' => 'tidak', 'nggak' => 'tidak',
    'ngga' => 'tidak', 'enggak' => 'tidak', 'tdk' => 'tidak', 'aj' => 'aja', 'ajah' => 'aja',
    'mtr' => 'motor', 'mbl' => 'mobil',
    'showrom' => 'showroom', 'sorum' => 'showroom', 'sowrum' => 'showroom', 'showroon' => 'showroom', 'shoowroom' => 'showroom',
    'showroomnya' => 'showroom', 'dealernya' => 'dealer', 'diler' => 'dealer', 'deler' => 'dealer', 'dealler' => 'dealer', 'dilernya' => 'dealer',
    'bka' => 'buka', 'bukak' => 'buka', 'msh' => 'masih', 'msih' => 'masih', 'tutp' => 'tutup', 'lg' => 'lagi', 'bs' => 'bisa',
    'tlp' => 'telepon', 'telp' => 'telepon', 'tlpn' => 'telepon', 'telpon' => 'telepon', 'no' => 'nomor', 'almt' => 'alamat',
    'mksh' => 'makasih', 'makasi' => 'makasih', 'trims' => 'makasih', 'tq' => 'makasih', 'merk' => 'merek',
    'pkl' => 'pukul', 'mlm' => 'malam', 'sr' => 'sore',
];

function vroomNormalisasi(string $text): string
{
    $t = mb_strtolower($text, 'UTF-8');
    $t = strtr($t, [
        'ë' => 'e', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ö' => 'o', 'ô' => 'o', 'ú' => 'u', 'ü' => 'u',
        '–' => '-', '—' => '-', '’' => '', "'" => '', '`' => '',
    ]);
    // Buang tanda baca, kecuali + dan - pada label model (go+, mu-x, gt-r) serta . : , di antara angka.
    $t = preg_replace('/[^\p{L}\p{N}\s+\-.:,]/u', ' ', $t) ?? '';
    $t = preg_replace('/(?<!\d)[.:,]|[.:,](?!\d)/u', ' ', $t) ?? '';
    $t = preg_replace('/(?<![\p{L}\p{N}])-|-(?![\p{L}\p{N}])/u', ' ', $t) ?? '';
    $t = preg_replace('/(?<![\p{L}\p{N}])\+|\+(?=[\p{L}\p{N}])/u', ' ', $t) ?? '';
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    if ($t === '') {
        return '';
    }
    $tokens = array_map(static fn (string $w): string => VROOM_EJAAN[$w] ?? $w, explode(' ', $t));
    $t = ' ' . implode(' ', $tokens) . ' ';
    $t = strtr($t, [' di mana ' => ' dimana ', ' dmana ' => ' dimana ', ' terima kasih ' => ' makasih ', ' trima kasih ' => ' makasih ']);
    return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
}

/* ------------------------------------------------------------------------
 * 3. Kamus dari RDF
 * --------------------------------------------------------------------- */

const VROOM_ALIAS_MEREK = [
    'merci' => 'Mercedes-Benz', 'mercy' => 'Mercedes-Benz', 'mercedes' => 'Mercedes-Benz', 'benz' => 'Mercedes-Benz',
    'mercedes benz' => 'Mercedes-Benz', 'mini' => 'MINI Cooper', 'mini cooper' => 'MINI Cooper', 'wuling' => 'Wuling',
    'chevy' => 'Chevrolet', 'mitsu' => 'Mitsubishi', 'hyundae' => 'Hyundai', 'daihatsu' => 'Daihatsu',
];

const VROOM_ALIAS_WILAYAH = [
    'siantar' => ['Pematangsiantar'], 'pematang siantar' => ['Pematangsiantar'], 'p siantar' => ['Pematangsiantar'],
    'tebing' => ['Tebing Tinggi'], 'sidimpuan' => ['Padangsidimpuan'], 'sidempuan' => ['Padangsidimpuan'],
    'padangsidempuan' => ['Padangsidimpuan'], 'padang sidempuan' => ['Padangsidimpuan'], 'psp' => ['Padangsidimpuan'],
    'labura' => ['Labuhanbatu Utara'], 'labusel' => ['Labuhanbatu Selatan'], 'labuhan batu' => ['Labuhanbatu'],
    'labuhan batu utara' => ['Labuhanbatu Utara'], 'labuhan batu selatan' => ['Labuhanbatu Selatan'],
    'sergai' => ['Serdang Bedagai'], 'madina' => ['Mandailing Natal'], 'deli' => ['Deli Serdang'],
    'kisaran' => ['Asahan'], 'rantauprapat' => ['Labuhanbatu'], 'rantau prapat' => ['Labuhanbatu'], 'rantau' => ['Labuhanbatu'],
    'kabanjahe' => ['Karo'], 'berastagi' => ['Karo'], 'brastagi' => ['Karo'], 'balige' => ['Toba'], 'tarutung' => ['Tapanuli Utara'],
    'taput' => ['Tapanuli Utara'], 'tapteng' => ['Tapanuli Tengah'], 'palas' => ['Padang Lawas'], 'paluta' => ['Padang Lawas Utara'],
    'tanjungbalai' => ['Tanjung Balai'], 'gunung sitoli' => ['Gunungsitoli'], 'nias' => ['Gunungsitoli', 'Nias Selatan'],
    'lubuk pakam' => ['Deli Serdang'], 'lubukpakam' => ['Deli Serdang'], 'stabat' => ['Langkat'], 'binjei' => ['Binjai'],
    'sidikalang' => ['Dairi'], 'panyabungan' => ['Mandailing Natal'], 'pangururan' => ['Samosir'], 'perbaungan' => ['Serdang Bedagai'],
    'aek kanopan' => ['Labuhanbatu Utara'], 'kotapinang' => ['Labuhanbatu Selatan'], 'kota pinang' => ['Labuhanbatu Selatan'],
    'limapuluh' => ['Batu Bara'], 'batubara' => ['Batu Bara'], 'pematang raya' => ['Simalungun'],
];

/** Model pendek / mirip kata umum: butuh kata pemicu kendaraan atau nama mereknya. */
const VROOM_MODEL_UMUM = [
    'city', 'gear', 'seal', 'spin', 'rush', 'jazz', 'leaf', 'beat', 'sonic', 'supra', 'revo', 'elf', 'giga', 'zebra', 'rocky',
    'terra', 'panther', 'athlete', 'monkey', 'freed', 'forza', 'genio', 'crown', 'march', 'focus', 'escape', 'explorer',
    'venue', 'address', 'smash', 'carry', 'swift', 'katana', 'satria', 'shogun', 'sprint', 'excel', 'kaze', 'fino', 'lexi',
    'jupiter', 'vega', 'glory', 'cortez', 'formo', 'dolphin', 'seagull', 'spark', 'trax', 'colt', 'mirage', 'galant', 'kijang',
    'starlet', 'taft', 'kicks', 'juke', 'ninja', 'kuda', 'gts', 'primavera', 'elettrica', 'go+', 'go plus', 'air ev', 'cloud ev',
    'mio', 'nex', 'stylo', 'scoopy', 'vario', 'byson', 'versys', 'vulcan', 'eliminator', 'raptor', 'mustang', 'everest',
    'territory', 'santa fe', 'creta', 'kona', 'venue', 'palisade', 'tucson', 'sonata', 'accent', 'ioniq', 'aveo', 'optra',
    'orlando', 'captiva', 'colorado', 'tahoe', 'suburban', 'trailblazer', 'cruze', 'omoda', 'tiggo', 'gelora', 'hyptec ht',
    'atto 3', 'super cab', 'charade', 'copen', 'feroza', 'hijet', 'luxio', 'grandis', 'delica', 'lancer', 'pajero', 'kuda',
    'evalia', 'fairlady', 'skyline', 'teana', 'livina', 'serena', 'magnite', 'navara', 'elgrand', 'grand livina', 'apv', 'ciaz',
    'baleno', 'escudo', 'ignis', 'jimny', 'karimun', 'splash', 'vitara', 'burgman', 'avenis', 'hayabusa', 'alphard', 'altis',
    'camry', 'corolla', 'hiace', 'hilux', 'innova', 'land cruiser', 'prado', 'sienta', 'voxy', 'yaris', 'vios', 'veloz', 'raize',
    'binguo', 'almaz', 'alvez', 'confero', 'darion', 'lexi', 'filano', 'freego', 'fazzio', 'aerox', 'soul gt', 'rx king',
];

const VROOM_PEMICU = '/\b(mobil|motor|beli|membeli|dibeli|pembelian|jual|dijual|menjual|penjual|jualan|dealer|showroom|unit|tipe|type|model|merek|brand|matic|matik|skuter|skutik)\b/u';

function vroomVarianFrasa(string $phrase): array
{
    $out = [$phrase];
    if (str_contains($phrase, '-')) {
        $out[] = str_replace('-', ' ', $phrase);
        $out[] = str_replace('-', '', $phrase);
    }
    if (str_contains($phrase, ' ') && mb_strlen(str_replace(' ', '', $phrase)) >= 4) {
        $out[] = str_replace([' ', '-'], '', $phrase);
    }
    return array_values(array_unique(array_filter($out, static fn (string $p): bool => trim($p) !== '')));
}

function vroomModelAmbigu(string $label): bool
{
    $compact = str_replace([' ', '-', '+'], '', $label);
    if (mb_strlen($compact) <= 3 || in_array($label, VROOM_MODEL_UMUM, true) || preg_match('/\+$/', $label)) {
        return true;
    }
    if (preg_match('/^[a-z]{0,3}\d+[a-z]?$/', $compact) && mb_strlen($compact) <= 5) {
        return true; // x1, m3, i10, cx5, z250, w175, 118i
    }
    foreach (explode(' ', $label) as $part) {
        if (mb_strlen($part) <= 1) {
            return true; // "s 125"
        }
    }
    return false;
}

function vroomKamus(array $ix): array
{
    static $memo = [];
    $key = json_encode($ix['sumber']);
    if (isset($memo[$key])) {
        return $memo[$key];
    }

    $entries = [];
    $add = static function (string $phrase, string $type, mixed $value, bool $ambiguous = false) use (&$entries): void {
        $phrase = vroomNormalisasi($phrase);
        if ($phrase === '') {
            return;
        }
        foreach (vroomVarianFrasa($phrase) as $variant) {
            $entries[$type . '|' . $variant] ??= ['frasa' => $variant, 'jenis' => $type, 'nilai' => $value, 'ambigu' => $ambiguous];
        }
    };

    $brandNorms = [];
    foreach ($ix['merek'] as $label => $_) {
        $label = (string) $label;
        $add($label, 'merek', $label);
        $brandNorms[vroomNormalisasi($label)] = true;
    }
    foreach (VROOM_ALIAS_MEREK as $alias => $label) {
        if (isset($ix['merek'][$label])) {
            $add($alias, 'merek', $label);
        }
    }
    foreach ($ix['model'] as $label => $model) {
        $label = (string) $label; // label angka seperti "946" menjadi kunci int di PHP
        if (isset($brandNorms[vroomNormalisasi($label)]) || $model['merek'] === []) {
            continue;
        }
        $add($label, 'model', $label, vroomModelAmbigu($label));
        // Label bernama dua kata+ ("sealion 7", "santa fe"): kata pertama saja juga dikenali sebagai model,
        // asal bukan nama merek/model lain dan panjang >= 4. Kata umum/pendek tetap butuh kata pemicu.
        $words = explode(' ', $label);
        if (count($words) > 1 && mb_strlen($words[0]) >= 4 && !preg_match('/\d/', $words[0])
            && !isset($brandNorms[vroomNormalisasi($words[0])]) && !isset($ix['model'][$words[0]])) {
            $add($words[0], 'model', $label, vroomModelAmbigu($words[0]));
        }
    }
    foreach ($ix['wilayah_alias'] as $alias => $pref) {
        $add((string) $alias, 'wilayah', [$pref]);
    }
    foreach (VROOM_ALIAS_WILAYAH as $alias => $prefs) {
        $prefs = array_values(array_intersect($prefs, $ix['wilayah']));
        if ($prefs !== []) {
            $add($alias, 'wilayah', $prefs);
        }
    }

    $entries = array_values($entries);
    usort($entries, static fn (array $a, array $b): int =>
        (substr_count($b['frasa'], ' ') <=> substr_count($a['frasa'], ' ')) ?: (strlen($b['frasa']) <=> strlen($a['frasa'])));

    // Token yang tidak khas untuk pencocokan nama showroom.
    $nonDistinct = array_fill_keys([
        'motor', 'motors', 'mobil', 'auto', 'autos', 'group', 'grup', 'center', 'centre', 'sales', 'store', 'mall', 'point',
        'indonesia', 'sumatera', 'sumatra', 'utara', 'sumut', 'cabang', 'dealer', 'showroom', 'official', 'resmi', 'pt', 'cv',
        'tbk', 'kota', 'kabupaten', 'jl', 'jalan', 'no', 'nomor', 'dan', 'bag', 'penjualan', 'trans', 'jaya', 'baru',
    ], true);
    foreach ($entries as $entry) {
        if ($entry['jenis'] !== 'model') {
            foreach (explode(' ', $entry['frasa']) as $tok) {
                $nonDistinct[$tok] = true;
            }
        }
    }

    return $memo[$key] = ['entri' => $entries, 'tidak_khas' => $nonDistinct];
}

/* ------------------------------------------------------------------------
 * 4. Ekstraksi entitas
 * --------------------------------------------------------------------- */

const VROOM_STOPWORDS = [
    'yang', 'di', 'ke', 'dari', 'dan', 'atau', 'ada', 'adakah', 'apa', 'apakah', 'mana', 'dimana', 'kemana', 'saja', 'aja', 'dong', 'deh',
    'sih', 'ya', 'yah', 'nih', 'tuh', 'kah', 'lah', 'pun', 'juga', 'itu', 'ini', 'tersebut', 'saya', 'aku', 'gue', 'gw', 'kamu',
    'anda', 'kak', 'kakak', 'min', 'admin', 'bang', 'bro', 'sis', 'mas', 'mbak', 'pak', 'bu', 'tolong', 'mohon', 'minta', 'mau',
    'ingin', 'pengen', 'pingin', 'cari', 'carikan', 'mencari', 'cariin', 'tunjukkan', 'tunjukin', 'tampilkan', 'lihat', 'liat',
    'kasih', 'beri', 'berikan', 'info', 'informasi', 'tahu', 'tau', 'tanya', 'nanya', 'bisa', 'boleh', 'tidak', 'bukan', 'untuk',
    'buat', 'dengan', 'sama', 'pada', 'oleh', 'bagi', 'kalau', 'kalo', 'klo', 'jika', 'kapan', 'bagaimana', 'gimana', 'berapa',
    'siapa', 'sekarang', 'saat', 'hari', 'nanti', 'besok', 'lagi', 'masih', 'sedang', 'lagi', 'sudah', 'belum', 'udah', 'akan',
    'terus', 'lalu', 'trus', 'showroom', 'dealer', 'toko', 'cabang', 'outlet', 'gerai', 'tempat', 'lokasi', 'alamat', 'alamatnya',
    'nomor', 'telepon', 'hp', 'wa', 'whatsapp', 'kontak', 'website', 'web', 'situs', 'jam', 'pukul', 'buka', 'tutup', 'operasional',
    'beroperasi', 'menjual', 'jual', 'dijual', 'jualan', 'penjual', 'beli', 'membeli', 'dibeli', 'pembelian', 'unit', 'tipe', 'type',
    'model', 'merek', 'brand', 'kendaraan', 'terdekat', 'dekat', 'sekitar', 'sini', 'sana', 'situ', 'paling', 'lebih', 'rating',
    'bintang', 'terbaik', 'tertinggi', 'bagus', 'terbagus', 'rekomendasi', 'rekomen', 'recommended', 'direkomendasikan', 'top',
    'banyak', 'semua', 'seluruh', 'sumut', 'sumatera', 'sumatra', 'utara', 'wilayah', 'kota', 'kabupaten', 'kab', 'daerah', 'area',
    'kawasan', 'km', 'meter', 'radius', 'jarak', 'dalam', 'sampai', 'hingga', 'malam', 'pagi', 'siang', 'sore', 'resmi', 'official',
    'baru', 'the', 'a', 'an', 'in', 'near', 'me', 'open', 'now', 'mobil', 'motor', 'sepeda', 'matic', 'matik', 'skuter', 'skutik', 'lain', 'lainnya',
    'selain', 'cuma', 'hanya', 'khusus', 'kira', 'tadi', 'yuk', 'ayo', 'oke', 'ok', 'punya', 'milik', 'produksi', 'buatan',
    'pabrikan', 'keluaran', 'adalah', 'termasuk', 'list', 'daftar', 'terpercaya', 'sekitarnya', 'jalan', 'jl', 'skrg', 'nya',
    'terdekatnya', 'dekatnya', 'terdekatku', 'dekatku', 'sekitarku', 'kini', 'masa', 'perlu', 'butuh', 'tempatnya', 'diler',
    'makasih', 'tolongin', 'coba', 'nah', 'oh', 'eh', 'loh', 'kok', 'tapi', 'tetapi', 'bagian', 'jualnya', 'belinya', 'beroperasional',
    'pas', 'kan', 'pak', 'gan', 'agan', 'hey', 'hai', 'halo', 'bot', 'vroom', 'asisten', 'besar', 'kecil', 'sebutkan',
];

function vroomEkstrak(string $norm, array $ix): array
{
    $e = [
        'merek' => [], 'model' => [], 'wilayah' => [], 'kategori' => '', 'jam' => '', 'radius' => null, 'mode' => [],
        'kata_showroom' => false, 'pemicu' => false, 'sisa' => [], 'semua_wilayah' => false, 'semua_jam' => false,
        'token' => $norm === '' ? [] : explode(' ', $norm), 'tak_dikenal_setelah_showroom' => false,
        'token_entitas' => [], 'wilayah_tidak_dikenal' => false,
    ];
    $text = ' ' . $norm . ' ';

    // Jam spesifik: "jam 7 malam", "pukul 19.00", "jam 18:30", "19.00".
    $period = '(?:\s+(pagi|siang|sore|malam))?';
    if (preg_match('/\b(?:jam|pukul)\s+(\d{1,2})(?:[.:](\d{2}))?' . $period . '\b(?!\s*(?:km|kilo|menit))/u', $text, $m)
        || preg_match('/(?<![\d.,])(\d{1,2})[.:](\d{2})' . $period . '\b(?!\s*(?:km|kilo))/u', $text, $m)) {
        $hour = (int) $m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $hour = vroomJam24($hour, $m[3] ?? '');
        if ($hour !== null && $minute < 60) {
            $e['jam'] = sprintf('%02d:%02d', $hour, $minute);
            $text = str_replace($m[0], ' ', $text);
        }
    } elseif (preg_match('/\bbesok pagi\b/u', $text, $m)) {
        $e['jam'] = '09:00';
    }

    // Radius: "dalam 10 km", "radius 20 km", "5km".
    if (preg_match('/\b(?:(?:dalam|radius|jarak|sekitar|maksimal|maks|max|kurang dari|di bawah|dibawah)\s+)?(\d+(?:[.,]\d+)?)\s*(?:km|kilo|kilometer)\b/u', $text, $m)
        || preg_match('/\bradius\s+(\d+(?:[.,]\d+)?)\b/u', $text, $m)) {
        $radius = (float) str_replace(',', '.', $m[1]);
        if ($radius >= 0.1 && $radius <= 500) {
            $e['radius'] = round($radius, 1);
        }
        $text = str_replace($m[0], ' ', $text);
    }

    // Kategori
    $isMotor = preg_match('/\b(sepeda motor|motor|motornya|matic|matik|skuter|skutik|moge|roda dua|roda 2|vespa)\b/u', $text) === 1;
    $isMobil = preg_match('/\b(mobil|mobilnya|roda empat|roda 4)\b/u', $text) === 1;
    if ($isMotor xor $isMobil) {
        $e['kategori'] = $isMotor ? 'motor' : 'mobil';
    }

    // Modifier
    if (preg_match('/\b(24 jam|non ?stop|nonstop|24\/7)\b/u', $text)) {
        $e['mode'][] = 'buka_24jam';
        $text = preg_replace('/\b24 jam\b/u', ' ', $text) ?? $text;
    }
    if (preg_match('/\b(terdekat|dekat|paling dekat|lebih dekat|sekitar saya|sekitarku|sekitar sini|dekat sini|dekatku|di sekitar|terdekatku|nearby|near me|sekitar lokasi)\b/u', $text)) {
        $e['mode'][] = 'terdekat';
    }
    if (preg_match('/\b(rating|bintang|terbaik|paling bagus|terbagus|bagus|rekomendasi|rekomen|recommended|direkomendasikan|terpercaya|top|favorit|terfavorit|populer|terpopuler)\b/u', $text)) {
        $e['mode'][] = 'rating_tertinggi';
    }
    if (preg_match('/\b(tutup paling (malam|lama|akhir|larut)|paling (malam|larut|lama buka|akhir tutup)|buka (sampai|hingga) (malam|larut)|buka terlama|sampai malam|tutupnya paling|paling lama)\b/u', $text)) {
        $e['mode'][] = 'paling_lama_buka';
    }
    $mintaTutup = preg_match(
        '/\b(?:(?:tidak|belum)\s+(?:(?:lagi|sedang|masih)\s+)?(?:buka|beroperasi|open)(?!\s+24\s+jam)|(?:sudah|udah|sedang|lagi)\s+tutup|yang\s+tutup(?!\s+(?:paling|jam|pukul)\b))\b/u',
        $norm
    ) === 1;
    if ($mintaTutup) {
        $e['mode'] = array_values(array_diff($e['mode'], ['buka_24jam']));
        $e['mode'][] = $e['jam'] !== '' ? 'tutup_pada_jam' : 'tutup_sekarang';
    } elseif ($e['jam'] !== '') {
        $e['mode'][] = 'buka_pada_jam';
    } elseif (preg_match('/\b(masih buka|yang buka|lagi buka|sedang buka|buka sekarang|sekarang buka|belum tutup|open now|masih open|buka saat ini|saat ini buka|buka hari ini|lagi open)\b/u', $text)
        || (preg_match('/\bbuka\b/u', $text) && !preg_match('/\bjam buka\b|\bbuka jam\b|\bbuka (sampai|hingga)\b/u', $text))) {
        $e['mode'][] = 'buka_sekarang';
    }
    if (preg_match('/\b(semua jam|jam berapa saja|kapan saja|tidak harus buka|tanpa filter jam|abaikan jam)\b/u', $text)) {
        $e['semua_jam'] = true;
        $e['mode'] = array_values(array_diff($e['mode'], VROOM_MODE_SARING));
    }
    if (preg_match('/\b(semua wilayah|seluruh wilayah|seluruh sumut|semua kota|se ?sumut|wilayah lain|kota lain|dimana saja|di mana saja)\b/u', $text)) {
        $e['semua_wilayah'] = true;
    }
    $e['mode'] = array_values(array_unique($e['mode']));

    $e['kata_showroom'] = preg_match('/\b(showroom|dealer|toko|cabang|outlet|gerai|diler)\b/u', $text) === 1;
    $e['pemicu'] = preg_match(VROOM_PEMICU, $text) === 1;

    // Merek, model, wilayah (frasa terpanjang dulu, kata utuh).
    $kamus = vroomKamus($ix);
    $hay = $text;
    $modelCandidates = [];
    $brandPos = [];
    foreach ($kamus['entri'] as $entry) {
        $needle = ' ' . $entry['frasa'] . ' ';
        $pos = strpos($hay, $needle);
        if ($pos === false) {
            continue;
        }
        $hay = substr_replace($hay, ' ' . str_repeat('#', strlen($entry['frasa'])) . ' ', $pos, strlen($needle));
        if ($entry['jenis'] === 'merek') {
            $brandPos[$entry['nilai']] ??= $pos;
            array_push($e['token_entitas'], ...explode(' ', $entry['frasa']));
        } elseif ($entry['jenis'] === 'wilayah') {
            array_push($e['wilayah'], ...$entry['nilai']);
            array_push($e['token_entitas'], ...explode(' ', $entry['frasa']));
        } else {
            $modelCandidates[] = $entry;
        }
    }
    asort($brandPos);
    $e['merek'] = array_keys($brandPos); // urut sesuai kemunculan di kalimat
    foreach ($modelCandidates as $entry) {
        $model = $ix['model'][$entry['nilai']];
        if (!$entry['ambigu'] || $e['pemicu'] || array_intersect($model['merek'], $e['merek'])) {
            $e['model'][] = $entry['nilai'];
            array_push($e['token_entitas'], ...explode(' ', $entry['frasa']));
        } else {
            // Bukan model: kembalikan kata ke teks agar dihitung sebagai kata sisa.
            $hay = str_replace(' ' . str_repeat('#', strlen($entry['frasa'])) . ' ', ' ' . $entry['frasa'] . ' ', $hay);
        }
    }
    $e['merek'] = array_values(array_unique($e['merek']));
    $e['model'] = array_values(array_unique($e['model']));
    $e['wilayah'] = array_values(array_unique($e['wilayah']));
    if (in_array('Vespa', $e['merek'], true) && $e['kategori'] === '') {
        $e['kategori'] = 'motor';
    }

    // Kata sisa yang tidak dikenali (untuk mendeteksi pertanyaan di luar data).
    $stop = array_fill_keys(VROOM_STOPWORDS, true);
    if ($e['wilayah'] === [] && !$e['semua_wilayah']) {
        // Periksa frasa lokasi yang tersisa sesudah alias dari RDF ditandai.
        preg_match_all(
            '/\b(?:di|ke|sekitar|dekat|kota|kabupaten|kab|wilayah)\s+(?:(?:area|wilayah|kota|kabupaten|kab|daerah|kawasan)\s+)*([\p{L}][\p{L}\p{N}-]*)/u',
            $hay,
            $tempat
        );
        foreach ($tempat[1] ?? [] as $kata) {
            if (!isset($stop[$kata]) && !in_array($kata, ['data', 'database', 'rdf', 'atas', 'bawah', 'luar', 'dalam', 'sebelah', 'samping'], true)) {
                $e['wilayah_tidak_dikenal'] = true;
                break;
            }
        }
    }
    $words = preg_split('/\s+/u', trim($hay)) ?: [];
    foreach ($words as $idx => $word) {
        if ($word === '' || str_contains($word, '#') || isset($stop[$word]) || preg_match('/^\d+([.,:]\d+)?$/', $word)) {
            continue;
        }
        $e['sisa'][] = $word;
        $prev = $words[$idx - 1] ?? '';
        if (in_array($prev, ['showroom', 'dealer', 'merek', 'brand', 'mobil', 'motor', 'diler'], true)) {
            $e['tak_dikenal_setelah_showroom'] = true;
        }
    }
    return $e;
}

function vroomJam24(int $hour, string $period): ?int
{
    if ($hour > 24) {
        return null;
    }
    if ($hour === 24) {
        return 0;
    }
    return match ($period) {
        'pagi' => $hour === 12 ? 0 : ($hour <= 11 ? $hour : null),
        'siang' => $hour >= 11 && $hour <= 12 ? $hour : ($hour >= 1 && $hour <= 3 ? $hour + 12 : ($hour >= 13 && $hour <= 15 ? $hour : null)),
        'sore' => $hour >= 1 && $hour <= 6 ? $hour + 12 : ($hour >= 15 && $hour <= 18 ? $hour : null),
        'malam' => $hour === 12 ? 0 : ($hour >= 6 && $hour <= 11 ? $hour + 12 : ($hour >= 18 && $hour <= 23 ? $hour : ($hour <= 3 ? $hour : null))),
        default => $hour,
    };
}

function vroomAdaEntitas(array $e): bool
{
    return $e['merek'] || $e['model'] || $e['wilayah'] || $e['kategori'] !== '' || $e['mode'] || $e['radius'] !== null
        || $e['jam'] !== '' || $e['semua_wilayah'] || $e['semua_jam'];
}

/* ------------------------------------------------------------------------
 * 5. Pencocokan nama showroom (info_showroom)
 * --------------------------------------------------------------------- */

function vroomTokenMirip(string $a, string $b): bool
{
    if ($a === $b) {
        return true;
    }
    $len = min(strlen($a), strlen($b));
    if ($len < 5) {
        return false;
    }
    return levenshtein($a, $b) <= ($len >= 8 ? 2 : 1);
}

function vroomCocokNama(string $norm, array $ix, array $entityTokens = []): array
{
    $entityTokens = array_fill_keys($entityTokens, true);
    $asksDetail = preg_match('/\b(jam|buka|tutup|operasional|alamat|alamatnya|lokasi|letak|telepon|nomor|kontak|hp|wa|whatsapp|website|web|situs|rating|kapan)\b/u', $norm) === 1;
    $kamus = vroomKamus($ix);
    $stop = array_fill_keys(VROOM_STOPWORDS, true);
    $nameStop = ['pt' => 1, 'cv' => 1, 'tbk' => 1, 'ud' => 1, 'dealer' => 1, 'showroom' => 1, 'dan' => 1, 'the' => 1, 'of' => 1, 'di' => 1];
    $queryAll = $norm === '' ? [] : explode(' ', $norm);
    $query = array_values(array_unique(array_filter($queryAll, static fn (string $t): bool =>
        !isset($stop[$t]) || in_array($t, ['motor', 'mobil', 'baru', 'utara', 'jaya', 'group'], true))));
    // Kata kerja/kata tanya yang masuk filter umum tetap dibuang dari query.
    $query = array_values(array_diff($query, ['buka', 'jam', 'tutup', 'alamat', 'nomor', 'telepon']));
    if ($query === []) {
        return [];
    }

    $best = [];
    foreach ($ix['showroom'] as $id => $row) {
        $tokens = array_values(array_unique(array_filter(
            explode(' ', vroomNormalisasi($row['nama'])),
            static fn (string $t): bool => $t !== '' && !isset($nameStop[$t])
        )));
        if ($tokens === []) {
            continue;
        }
        $matched = 0;
        $distinct = 0;
        $usedQuery = [];
        foreach ($tokens as $t) {
            foreach ($query as $qi => $q) {
                if (!isset($usedQuery[$qi]) && vroomTokenMirip($t, $q)) {
                    $matched++;
                    $usedQuery[$qi] = true;
                    // Kata khas hanya dihitung jika bukan bagian dari merek/wilayah/model yang dikenali.
                    if (!isset($kamus['tidak_khas'][$t]) && !isset($entityTokens[$q]) && !preg_match('/^\d+$/', $t)) {
                        $distinct++;
                    }
                    break;
                }
            }
        }
        // Nama tanpa kata khas (mis. "Daihatsu Motor") cocok jika disebut utuh dan yang ditanya detailnya.
        $fullName = count($tokens) >= 2 && $asksDetail && str_contains(' ' . $norm . ' ', ' ' . implode(' ', $tokens) . ' ');
        if ($matched === 0 || ($distinct === 0 && !$fullName)) {
            continue;
        }
        $p = $matched / count($tokens);
        $r = $matched / count($query);
        $score = 2 * $p * $r / ($p + $r);
        similar_text(implode(' ', $query), implode(' ', $tokens), $pct);
        if ($fullName) {
            $score = max($score, 0.95);
        }
        if (($score >= 0.7 && $p >= 0.5) || ($pct >= 70 && $p >= 0.5)) {
            $best[] = ['id' => $id, 'skor' => round($score, 3), 'mirip' => $pct, 'rating' => $row['rating'] ?? 0];
        }
    }
    usort($best, static fn (array $a, array $b): int =>
        ($b['skor'] <=> $a['skor']) ?: ($b['mirip'] <=> $a['mirip']) ?: ($b['rating'] <=> $a['rating']));
    if ($best === []) {
        return [];
    }
    $top = $best[0]['skor'];
    return array_slice(array_map(
        static fn (array $b): string => $b['id'],
        array_filter($best, static fn (array $b): bool => $b['skor'] >= $top - 0.001)
    ), 0, 3);
}

/* ------------------------------------------------------------------------
 * 6. Pencarian di indeks lokal
 * --------------------------------------------------------------------- */

function vroomMenitDariJam(string $jam): int
{
    return (int) substr($jam, 0, 2) * 60 + (int) substr($jam, 3, 2);
}

function vroomSaringDasar(array $filter, array $ix): array
{
    $regions = $filter['wilayah'] === '' ? [] : explode('|', $filter['wilayah']);
    $modelBrands = [];
    $modelKategori = '';
    if ($filter['model'] !== '' && isset($ix['model'][$filter['model']])) {
        $modelBrands = $ix['model'][$filter['model']]['merek'];
        $modelKategori = $ix['model'][$filter['model']]['kategori'];
    }

    $rows = [];
    foreach ($ix['showroom'] as $row) {
        if ($filter['kategori'] !== '' && $row['kategori'] !== $filter['kategori']) {
            continue;
        }
        if ($filter['merek'] !== '' && !in_array(mb_strtolower($filter['merek']), array_map('mb_strtolower', $row['merek_kunci']), true)) {
            continue;
        }
        if ($modelBrands !== [] && !array_intersect($modelBrands, $row['merek_kunci'])) {
            continue;
        }
        if ($modelKategori !== '' && $row['kategori'] !== '' && $row['kategori'] !== $modelKategori) {
            continue;
        }
        if ($regions !== [] && !in_array($row['wilayah'], $regions, true)) {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

function vroomCari(array $filter, ?array $lok, array $time, array $ix): array
{
    $modes = vroomModes($filter);
    $has = static fn (string $m): bool => in_array($m, $modes, true);
    $jamMinute = ($has('buka_pada_jam') || $has('tutup_pada_jam')) && $filter['jam'] !== ''
        ? vroomMenitDariJam($filter['jam']) : null;

    $base = vroomSaringDasar($filter, $ix);
    $rows = [];
    foreach ($base as $row) {
        $jam = vroomJamParse($row['jam']);
        $row['_jam'] = $jam;
        $row['_status'] = vroomJamStatus($jam, $time['menit_wib']);
        $row['_status_jam'] = $jamMinute === null ? null : vroomJamStatus($jam, $jamMinute);
        $row['_jarak'] = ($lok !== null && $row['lat'] !== null) ? vroomJarakKm($lok['lat'], $lok['lon'], $row['lat'], $row['lon']) : null;
        if ($has('buka_sekarang') && !vroomJamTerbuka($row['_status'])) {
            continue;
        }
        if ($has('tutup_sekarang') && $row['_status']['status'] !== 'tutup') {
            continue;
        }
        if ($jamMinute !== null && ($has('tutup_pada_jam')
            ? $row['_status_jam']['status'] !== 'tutup'
            : !vroomJamTerbuka($row['_status_jam']))) {
            continue;
        }
        if ($has('buka_24jam') && $jam['tipe'] !== '24jam') {
            continue;
        }
        $rows[] = $row;
    }

    $info = ['jumlah_dasar' => count($base), 'jumlah_saring' => count($rows), 'radius_dipakai' => null, 'di_luar_radius' => false, 'jumlah_10km' => null];
    $byDistance = static fn (array $a, array $b): int => ($a['_jarak'] ?? INF) <=> ($b['_jarak'] ?? INF);
    $byRating = static fn (array $a, array $b): int => ($b['rating'] ?? -1) <=> ($a['rating'] ?? -1);
    $byName = static fn (array $a, array $b): int => strcasecmp($a['nama'], $b['nama']);

    if ($lok !== null) {
        $info['jumlah_10km'] = count(array_filter($rows, static fn (array $r): bool => $r['_jarak'] !== null && $r['_jarak'] <= 10));
        if ($filter['radius_km'] !== null) {
            $inside = array_values(array_filter($rows, static fn (array $r): bool => $r['_jarak'] !== null && $r['_jarak'] <= $filter['radius_km']));
            $info['radius_dipakai'] = $filter['radius_km'];
            if ($inside === [] && $rows !== []) {
                usort($rows, $byDistance);
                $rows = array_slice($rows, 0, 3);
                $info['di_luar_radius'] = true;
            } else {
                $rows = $inside;
            }
        } elseif ($has('rating_tertinggi') && $filter['wilayah'] === '') {
            foreach ([10, 25, 50] as $step) {
                $inside = array_values(array_filter($rows, static fn (array $r): bool => $r['_jarak'] !== null && $r['_jarak'] <= $step));
                if (count($inside) >= 3 || $step === 50) {
                    if (count($inside) >= 3) {
                        $rows = $inside;
                        $info['radius_dipakai'] = $step;
                    }
                    break;
                }
            }
        }
    }

    usort($rows, static function (array $a, array $b) use ($has, $lok, $byDistance, $byRating, $byName): int {
        if ($has('paling_lama_buka')) {
            $cmp = $b['_status']['tutup_menit'] <=> $a['_status']['tutup_menit'];
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        if ($has('rating_tertinggi')) {
            return $byRating($a, $b) ?: ($lok !== null ? $byDistance($a, $b) : 0) ?: $byName($a, $b);
        }
        if ($lok !== null) {
            return $byDistance($a, $b) ?: $byRating($a, $b) ?: $byName($a, $b);
        }
        return $byRating($a, $b) ?: $byName($a, $b);
    });

    $total = count($rows);
    $page = max(1, (int) $filter['halaman']);
    $slice = array_slice($rows, ($page - 1) * VROOM_PER_HALAMAN, VROOM_PER_HALAMAN);
    return $info + [
        'total' => $total,
        'semua' => $rows,
        'hasil' => array_map(static fn (array $r): array => vroomKartu($r, $lok, $filter), $slice),
        'ada_lagi' => $total > $page * VROOM_PER_HALAMAN,
        'dari' => $slice === [] ? 0 : ($page - 1) * VROOM_PER_HALAMAN + 1,
        'sampai' => ($page - 1) * VROOM_PER_HALAMAN + count($slice),
    ];
}

function vroomKartu(array $row, ?array $lok, array $filter, ?array $time = null): array
{
    $jam = $row['_jam'] ?? vroomJamParse($row['jam']);
    $status = $row['_status'] ?? vroomJamStatus($jam, $time['menit_wib'] ?? 0);
    $distance = $row['_jarak'] ?? (($lok !== null && $row['lat'] !== null) ? vroomJarakKm($lok['lat'], $lok['lon'], $row['lat'], $row['lon']) : null);

    $label = match ($status['status']) {
        'buka' => 'Buka', 'segera-tutup' => 'Segera tutup', 'tutup' => 'Tutup', '24jam' => 'Buka 24 jam', default => 'Jam belum ada',
    };
    $text = $status['teks'];
    $code = $status['status'];
    if (($row['_status_jam'] ?? null) !== null && $filter['jam'] !== '') {
        $at = $row['_status_jam'];
        $jamLabel = str_replace(':', '.', $filter['jam']);
        $code = $at['status'];
        $label = match ($at['status']) {
            '24jam' => 'Buka 24 jam',
            'tutup' => 'Tutup ' . $jamLabel,
            default => 'Buka ' . $jamLabel,
        };
        $text = $at['status'] === '24jam'
            ? 'Buka 24 jam, jadi tetap buka pukul ' . $jamLabel
            : 'Pukul ' . $jamLabel . ' (jadwal harian): ' . lcfirst($at['teks']);
    }

    $route = null;
    if ($row['lat'] !== null) {
        $route = 'https://www.google.com/maps/dir/?api=1&destination=' . vroomKoordinat($row['lat'], $row['lon']);
        if ($lok !== null) {
            $route .= '&origin=' . vroomKoordinat($lok['lat'], $lok['lon']);
        }
    }

    return [
        'id' => $row['id'],
        'nama' => $row['nama'],
        'merek' => $row['merek'],
        'kategori' => $row['kategori'],
        'wilayah' => $row['wilayah'],
        'alamat' => $row['alamat'],
        'telepon' => $row['telepon'],
        'rating' => $row['rating'],
        'rating_teks' => vroomFormatRating($row['rating']),
        'jarak_km' => $distance === null ? null : round($distance, 2),
        'jarak_teks' => $distance === null ? '' : vroomFormatJarak($distance),
        'jam' => vroomJamLabel($jam),
        'jam_tersedia' => in_array($jam['tipe'], ['rentang', '24jam'], true),
        'status' => $code,
        'status_label' => $label,
        'status_teks' => $text,
        'detail' => 'detail.php?uri=' . rawurlencode($row['id']),
        'rute' => $route,
    ];
}

function vroomKoordinat(float $lat, float $lon): string
{
    return rtrim(rtrim(sprintf('%.6F', $lat), '0'), '.') . ',' . rtrim(rtrim(sprintf('%.6F', $lon), '0'), '.');
}

/* ------------------------------------------------------------------------
 * 7. Kalimat jawaban
 * --------------------------------------------------------------------- */

function vroomGabungKata(array $items, string $last = 'dan'): string
{
    $items = array_values($items);
    if (count($items) <= 1) {
        return $items[0] ?? '';
    }
    return implode(', ', array_slice($items, 0, -1)) . ' ' . $last . ' ' . end($items);
}

function vroomLabelModel(string $label): string
{
    return implode(' ', array_map(static function (string $w): string {
        return preg_match('/\d/', $w) && mb_strlen($w) <= 5 ? mb_strtoupper($w) : mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
    }, explode(' ', $label)));
}

function vroomFrasaFilter(array $filter, array $ix): array
{
    $noun = 'showroom';
    if ($filter['kategori'] !== '') {
        $noun .= ' ' . $filter['kategori'];
    }
    $brand = '';
    if ($filter['model'] !== '' && isset($ix['model'][$filter['model']])) {
        $brand = implode('/', $ix['model'][$filter['model']]['merek']);
    } elseif ($filter['merek'] !== '') {
        $brand = $filter['merek'];
    }
    if ($brand !== '') {
        $noun .= ' ' . $brand;
    }
    $regions = $filter['wilayah'] === '' ? [] : explode('|', $filter['wilayah']);
    $where = $regions === [] ? '' : ' di ' . vroomGabungKata($regions);
    return [$noun, $where, $brand];
}

function vroomSaran(array $filter, ?array $lok, int $total, ?array $ix = null): array
{
    $modes = vroomModes($filter);
    $out = [];
    if (!in_array('buka_sekarang', $modes, true)) {
        $out[] = 'Yang masih buka?';
    }
    if (!in_array('rating_tertinggi', $modes, true)) {
        $out[] = 'Rating tertinggi';
    }
    if ($lok !== null && !in_array('terdekat', $modes, true) && in_array('rating_tertinggi', $modes, true)) {
        $out[] = 'Yang lebih dekat';
    }
    if ($filter['merek'] === '' && $filter['model'] === '') {
        $out[] = $lok !== null ? 'Showroom Toyota terdekat' : 'Showroom Toyota di Medan';
    }
    $brandCats = ($ix !== null && $filter['merek'] !== '') ? ($ix['merek'][$filter['merek']]['kategori'] ?? []) : ['mobil', 'motor'];
    if ($filter['model'] === '' && count($brandCats) > 1) {
        $out[] = $filter['kategori'] === 'motor' ? 'Kalau yang mobil?' : 'Kalau yang motor?';
    }
    if ($filter['merek'] !== '' || $filter['model'] !== '') {
        $out[] = 'Showroom terdekat lainnya';
    }
    if (in_array('buka_sekarang', $modes, true)) {
        $out[] = 'Yang tutup paling malam';
    }
    return array_slice(array_values(array_unique($out)), 0, 3);
}

function vroomJawabCari(array $filter, ?array $lok, array $time, array $ix, string $intent): array
{
    $res = vroomCari($filter, $lok, $time, $ix);
    $modes = vroomModes($filter);
    $has = static fn (string $m): bool => in_array($m, $modes, true);
    [$noun, $where, $brand] = vroomFrasaFilter($filter, $ix);
    $shown = count($res['hasil']);
    $total = $res['total'];
    $timeRelated = $has('buka_sekarang') || $has('tutup_sekarang') || $has('buka_24jam') || $has('paling_lama_buka');
    $parts = [];
    $saran = vroomSaran($filter, $lok, $total, $ix);
    $catatan = [];
    $mintaLokasi = false;

    if ($filter['model'] !== '' && isset($ix['model'][$filter['model']]) && $filter['halaman'] === 1) {
        $m = $ix['model'][$filter['model']];
        $parts[] = vroomLabelModel($m['label']) . ' adalah model ' . ($m['kategori'] !== '' ? $m['kategori'] . ' ' : '')
            . vroomGabungKata($m['merek'], 'atau') . ' menurut data Vroom.';
    }
    if ($timeRelated && $filter['halaman'] === 1) {
        $parts[] = vroomKalimatWaktu($time) . '.';
    }

    $orderText = $lok !== null ? 'yang terdekat' : 'dengan rating tertinggi';
    $radiusNote = ($lok !== null && $res['radius_dipakai'] !== null && !$res['di_luar_radius'] && !$has('rating_tertinggi'))
        ? ' dalam radius ' . vroomAngka((float) $res['radius_dipakai']) . ' km' : '';
    $where .= $radiusNote;

    if ($filter['halaman'] > 1) {
        $parts[] = $shown > 0
            ? 'Ini lanjutan hasilnya (' . $res['dari'] . '–' . $res['sampai'] . ' dari ' . $total . ').'
            : 'Semua hasil sudah ditampilkan (' . $total . ' showroom).';
    } elseif ($total === 0) {
        $jamLabel = str_replace(':', '.', $filter['jam']);
        if ($res['jumlah_dasar'] === 0) {
            if ($brand !== '' && ($filter['wilayah'] !== '' || $filter['kategori'] !== '')) {
                $parts[] = 'Belum ada ' . $noun . $where . ' di data Vroom. Mau lihat showroom ' . $brand . ' di wilayah lain?';
                $saran = array_merge(['Showroom ' . $brand], array_slice($saran, 0, 2));
            } else {
                $parts[] = 'Belum ada ' . $noun . $where . ' di data Vroom. Coba ubah merek, kategori, atau wilayahnya.';
            }
        } elseif ($has('tutup_pada_jam') || $has('tutup_sekarang')) {
            $kapan = $has('tutup_pada_jam') ? 'pukul ' . $jamLabel . ' WIB' : 'sekarang';
            $parts[] = 'Tidak ada ' . $noun . $where . ' yang tercatat tutup ' . $kapan . ' menurut jadwal harian.';
            $saran = ['Yang masih buka?', 'Semua jam'];
        } elseif ($has('buka_pada_jam')) {
            $latest = vroomTutupTerakhir(vroomSaringDasar($filter, $ix));
            if ($latest !== null) {
                $parts[] = 'Belum ada ' . $noun . $where . ' yang buka pukul ' . $jamLabel . ' WIB menurut jadwal harian. Yang paling malam tutup pukul '
                    . $latest . '. Mau lihat yang tutup paling malam?';
                $saran = ['Yang tutup paling malam', 'Buka jam 10 pagi'];
            } else {
                $parts[] = 'Belum ada ' . $noun . $where . ' yang buka pukul ' . $jamLabel . ' WIB menurut jadwal harian. Mau lihat yang buka besok pagi?';
                $saran = ['Buka jam 10 pagi', 'Semua jam'];
            }
        } elseif ($has('buka_24jam')) {
            $parts[] = 'Belum ada ' . $noun . $where . ' yang buka 24 jam di data Vroom. Mau lihat semua jamnya?';
            $saran = ['Semua jam', 'Yang tutup paling malam'];
        } elseif ($has('buka_sekarang')) {
            $parts[] = 'Belum ada ' . $noun . $where . ' yang masih buka sekarang menurut jadwal harian. Mau lihat yang buka besok pagi?';
            $saran = ['Buka jam 10 pagi', 'Semua jam'];
        } else {
            $parts[] = 'Belum ada ' . $noun . $where . ' yang cocok dalam radius ' . vroomAngka((float) $filter['radius_km']) . ' km. Coba radius yang lebih luas?';
            $saran = ['Dalam 25 km', 'Semua wilayah'];
        }
    } elseif ($res['di_luar_radius']) {
        $parts[] = 'Tidak ada ' . $noun . $where . ' dalam radius ' . vroomAngka((float) $res['radius_dipakai']) . ' km, ini yang terdekat.';
    } elseif ($has('tutup_pada_jam') || $has('tutup_sekarang')) {
        $kapan = $has('tutup_pada_jam') ? 'pukul ' . str_replace(':', '.', $filter['jam']) . ' WIB' : 'sekarang';
        $parts[] = 'Menurut jadwal harian, ada ' . $total . ' ' . $noun . $where . ' yang tutup ' . $kapan
            . '. Ini ' . $shown . ' ' . ($has('rating_tertinggi') ? 'dengan rating tertinggi' : $orderText) . '.';
    } elseif ($has('buka_pada_jam')) {
        $parts[] = 'Menurut jadwal harian, ada ' . $total . ' ' . $noun . $where . ' yang buka pukul ' . str_replace(':', '.', $filter['jam'])
            . ' WIB. Ini ' . $shown . ' ' . ($has('rating_tertinggi') ? 'dengan rating tertinggi' : $orderText) . ':';
    } elseif ($has('buka_sekarang')) {
        if ($lok !== null && $filter['wilayah'] === '' && $filter['radius_km'] === null && ($res['jumlah_10km'] ?? 0) > 0) {
            $parts[] = 'Ada ' . $res['jumlah_10km'] . ' ' . $noun . ' yang masih buka dalam 10 km, ini ' . min($shown, $res['jumlah_10km']) . ' yang terdekat.';
        } else {
            $radiusText = $res['radius_dipakai'] !== null ? ' dalam ' . vroomAngka((float) $res['radius_dipakai']) . ' km' : '';
            $parts[] = 'Ada ' . $total . ' ' . $noun . ' yang masih buka' . $where . $radiusText . ', ini ' . $shown . ' '
                . ($has('rating_tertinggi') ? 'dengan rating tertinggi' : $orderText) . '.';
        }
    } elseif ($has('buka_24jam')) {
        $parts[] = 'Ada ' . $total . ' ' . $noun . $where . ' yang buka 24 jam menurut data Vroom. Ini ' . $shown . ' ' . $orderText . '.';
    } elseif ($has('paling_lama_buka')) {
        $parts[] = 'Ini ' . $noun . $where . ' yang tutup paling malam menurut jadwal harian.';
    } elseif ($has('rating_tertinggi')) {
        if ($lok !== null && $res['radius_dipakai'] !== null) {
            $parts[] = 'Ini ' . $noun . ' dengan rating tertinggi di dekatmu (radius ' . vroomAngka((float) $res['radius_dipakai']) . ' km).';
        } elseif ($lok !== null && $where !== '') {
            $parts[] = 'Ini ' . $noun . ' dengan rating tertinggi' . $where . ', lalu diurutkan dari yang terdekat.';
        } else {
            $parts[] = 'Ini ' . $noun . ' dengan rating tertinggi' . ($where !== '' ? $where : ' di Sumatera Utara') . '.';
        }
    } elseif ($filter['model'] !== '') {
        $parts[] = 'Ini showroom yang mewakili ' . $brand . $where . ($lok !== null ? ', diurutkan dari yang terdekat:' : ', diurutkan berdasarkan rating:');
    } elseif ($filter['merek'] !== '' && $total === 1) {
        $first = $res['hasil'][0];
        $parts[] = 'Showroom ' . $filter['merek'] . $where . ' di data Vroom hanya ada 1: ' . $first['nama']
            . ($first['jarak_teks'] !== '' ? ', ' . $first['jarak_teks'] . ' dari lokasimu' : '') . '.';
    } elseif ($has('terdekat') && $lok !== null) {
        $parts[] = 'Ini ' . $shown . ' ' . $noun . ' terdekat dari lokasimu' . $where . ', diurutkan dari yang paling dekat.';
    } elseif ($has('terdekat')) {
        $parts[] = 'Aku belum tahu lokasimu, jadi ini ' . $noun . ($where !== '' ? $where : ' di Sumatera Utara')
            . ' dengan rating tertinggi. Aktifkan lokasi atau sebut nama kota supaya hasilnya diurutkan dari yang terdekat.';
        $mintaLokasi = true;
    } elseif ($lok !== null) {
        $parts[] = 'Ada ' . $total . ' ' . $noun . $where . ' di data Vroom. Ini ' . $shown . ' yang terdekat dari lokasimu.';
    } else {
        $parts[] = 'Ada ' . $total . ' ' . $noun . $where . ' di data Vroom. Ini ' . $shown . ' dengan rating tertinggi.';
    }

    if ($has('terdekat') && $lok === null) {
        $mintaLokasi = true;
    }
    if ($lok !== null && array_filter($res['hasil'], static fn (array $h): bool => $h['jarak_km'] !== null)) {
        $catatan[] = 'Jarak dihitung garis lurus dari lokasimu.';
    }
    if ($lok === null && $total > 0 && !$mintaLokasi) {
        $catatan[] = 'Lokasi belum aktif. Aktifkan lokasi supaya hasil diurutkan dari yang terdekat, atau sebut nama kota.';
    }
    if ($lok === null && $filter['radius_km'] !== null) {
        $catatan[] = 'Radius ' . vroomAngka((float) $filter['radius_km']) . ' km baru bisa dipakai setelah lokasi aktif.';
    }
    if ($timeRelated || $has('buka_pada_jam') || $has('tutup_pada_jam')) {
        $catatan[] = 'Status buka/tutup dihitung menurut jadwal harian (WIB). Hari libur tidak tercatat di data.';
    }

    return [
        'pesan' => implode(' ', $parts),
        'intent' => $intent,
        'hasil' => $res['hasil'],
        'total' => $total,
        'ada_lagi' => $res['ada_lagi'],
        'minta_lokasi' => $mintaLokasi,
        'konteks' => $filter,
        'saran' => $saran,
        'catatan' => $catatan,
    ];
}

/** Jam tutup paling malam (format HH.MM) di antara showroom berjadwal rentang. */
function vroomTutupTerakhir(array $rows): ?string
{
    $best = null;
    foreach ($rows as $row) {
        $jam = vroomJamParse($row['jam']);
        if ($jam['tipe'] === 'rentang') {
            $close = $jam['tutup'] <= $jam['buka'] ? $jam['tutup'] + 1440 : $jam['tutup'];
            $best = max($best ?? 0, $close);
        }
    }
    return $best === null ? null : ($best === 1440 ? '24.00' : vroomFormatMenit($best));
}

function vroomAngka(float $n): string
{
    return floor($n) === $n ? (string) (int) $n : number_format($n, 1, ',', '');
}

function vroomJawabInfo(array $ids, string $norm, ?array $lok, array $time, array $ix, array $konteks): array
{
    $filter = vroomKonteksKosong();
    $cards = [];
    foreach ($ids as $id) {
        $row = $ix['showroom'][$id];
        $row['_jam'] = vroomJamParse($row['jam']);
        $row['_status'] = vroomJamStatus($row['_jam'], $time['menit_wib']);
        $cards[] = vroomKartu($row, $lok, $filter, $time);
    }
    $row = $ix['showroom'][$ids[0]];
    $card = $cards[0];
    $name = $row['nama'];

    $askPhone = preg_match('/\b(telepon|telpon|nomor|kontak|hp|wa|whatsapp|hubungi|call|cs)\b/u', $norm) === 1;
    $askAddress = preg_match('/\b(alamat|alamatnya|lokasi|lokasinya|dimana|letak|letaknya|rute|arah)\b/u', $norm) === 1;
    $askWeb = preg_match('/\b(website|web|situs|link|url)\b/u', $norm) === 1;
    $askRating = preg_match('/\b(rating|bintang|nilai)\b/u', $norm) === 1;
    $askHours = preg_match('/\b(jam|buka|tutup|operasional|beroperasi|kapan)\b/u', $norm) === 1;
    $nothing = !$askPhone && !$askAddress && !$askWeb && !$askRating && !$askHours;

    $parts = [];
    if (count($ids) > 1) {
        $parts[] = 'Ada ' . count($ids) . ' showroom dengan nama mirip. Ini info ' . $name . ':';
    }
    if ($askHours || $nothing) {
        $jam = vroomJamParse($row['jam']);
        $status = vroomJamStatus($jam, $time['menit_wib']);
        if ($jam['tipe'] === 'rentang') {
            $parts[] = vroomKalimatWaktu($time) . '. ' . $name . ' buka pukul ' . vroomJamLabel($jam) . ' WIB menurut jadwal harian. Statusnya sekarang: ' . lcfirst($status['teks']) . '.';
        } elseif ($jam['tipe'] === '24jam') {
            $parts[] = $name . ' buka 24 jam menurut data Vroom.';
        } elseif ($jam['tipe'] === 'tidak-dikenal') {
            $parts[] = 'Jam operasional ' . $name . ' di data Vroom tertulis "' . $jam['teks'] . '", jadi statusnya belum bisa dihitung.';
        } else {
            $parts[] = 'Jam operasional ' . $name . ' belum tersedia di data Vroom.';
        }
    }
    if ($askAddress || $nothing) {
        $prefix = $nothing || $askHours ? 'Alamat: ' : 'Alamat ' . $name . ': ';
        $parts[] = $row['alamat'] !== '' ? $prefix . $row['alamat'] . ' (' . $row['wilayah'] . ').' : 'Alamat ' . $name . ' belum tersedia di data Vroom (wilayah ' . $row['wilayah'] . ').';
        if ($askAddress && $card['jarak_teks'] !== '') {
            $parts[] = 'Jaraknya ' . $card['jarak_teks'] . ' dari lokasimu (garis lurus).';
        }
    }
    if ($askPhone || $nothing) {
        $prefix = $nothing || $askHours || $askAddress ? 'Telepon: ' : 'Nomor telepon ' . $name . ': ';
        $parts[] = $row['telepon'] !== '' ? $prefix . $row['telepon'] . '.' : 'Nomor telepon ' . $name . ' belum tersedia di data Vroom.';
    }
    if ($askWeb) {
        $parts[] = $row['website'] !== '' ? 'Website ' . $name . ' menurut data Vroom: ' . $row['website'] : 'Website ' . $name . ' belum tersedia di data Vroom.';
    }
    if ($askRating) {
        $parts[] = $row['rating'] !== null ? 'Rating ' . $name . ': ★ ' . vroomFormatRating($row['rating']) . '.' : 'Rating ' . $name . ' belum tersedia di data Vroom.';
    }

    $brand = $row['merek_kunci'][0] ?? '';
    return [
        'pesan' => implode(' ', $parts),
        'intent' => 'info_showroom',
        'hasil' => $cards,
        'total' => count($cards),
        'ada_lagi' => false,
        'minta_lokasi' => false,
        'konteks' => $konteks,
        'saran' => array_values(array_filter([
            $brand !== '' ? 'Showroom ' . $brand . ' lainnya' : '',
            'Showroom terdekat yang masih buka',
            'Rating tertinggi di ' . $row['wilayah'],
        ])),
        'catatan' => ['Status buka/tutup dihitung menurut jadwal harian (WIB).'],
    ];
}

function vroomJawabanDasar(string $pesan, string $intent, array $konteks, array $saran = []): array
{
    return [
        'pesan' => $pesan, 'intent' => $intent, 'hasil' => [], 'total' => 0, 'ada_lagi' => false,
        'minta_lokasi' => false, 'konteks' => $konteks, 'saran' => $saran, 'catatan' => [],
    ];
}

function vroomContohPertanyaan(?array $lok): array
{
    return ['Showroom terdekat yang masih buka', 'Dimana dealer Ford?', $lok !== null ? 'Rating tertinggi dekat saya' : 'Dealer motor Honda di Binjai'];
}

/* ------------------------------------------------------------------------
 * 8. Penentuan intent dan penggabungan konteks
 * --------------------------------------------------------------------- */

function vroomFilterDariEntitas(array $base, array $e, array $ix): array
{
    $f = $base;
    $f['halaman'] = 1;
    if ($e['semua_wilayah']) {
        $f['wilayah'] = '';
    }
    if ($e['wilayah']) {
        $f['wilayah'] = implode('|', $e['wilayah']);
    }
    if ($e['model']) {
        $f['model'] = $e['model'][0];
        $f['merek'] = '';
    } elseif ($e['merek']) {
        $f['merek'] = $e['merek'][0];
        $f['model'] = '';
    }
    if ($e['kategori'] !== '') {
        $f['kategori'] = $e['kategori'];
        if ($f['model'] !== '' && ($ix['model'][$f['model']]['kategori'] ?? '') !== '' && $ix['model'][$f['model']]['kategori'] !== $e['kategori']) {
            $f['model'] = '';
        }
        if ($f['merek'] !== '' && !in_array($e['kategori'], $ix['merek'][$f['merek']]['kategori'] ?? [], true)) {
            if ($e['merek']) {
                $f['kategori'] = ''; // merek disebut di pesan yang sama: "daihatsu motor" ≠ kategori motor
            } else {
                $f['merek'] = '';
            }
        }
    }

    $modes = vroomModes($f);
    $newSort = array_values(array_intersect($e['mode'], VROOM_MODE_URUT));
    $newFilter = array_values(array_intersect($e['mode'], VROOM_MODE_SARING));
    if ($newSort) {
        $modes = array_merge(array_diff($modes, VROOM_MODE_URUT), $newSort);
    }
    if ($newFilter) {
        $modes = array_merge(array_diff($modes, VROOM_MODE_SARING), $newFilter);
    } elseif (in_array('paling_lama_buka', $newSort, true)) {
        $modes = array_diff($modes, ['buka_pada_jam', 'tutup_pada_jam']); // "yang tutup paling malam" tidak terikat jam tertentu
    }
    if ($e['semua_jam']) {
        $modes = array_diff($modes, VROOM_MODE_SARING);
    }
    $modes = array_values(array_unique($modes));
    $f['mode'] = implode(',', $modes);
    if (array_intersect($modes, ['buka_pada_jam', 'tutup_pada_jam'])) {
        if ($e['jam'] !== '') {
            $f['jam'] = $e['jam'];
        }
    } else {
        $f['jam'] = '';
    }
    if ($e['radius'] !== null) {
        $f['radius_km'] = $e['radius'];
    }
    return $f;
}

function vroomPertanyaanLanjutan(string $norm, array $e): bool
{
    $tokens = $e['token'];
    if (preg_match('/^(yang|kalau|kalo|klo|kl|gimana|bagaimana|terus|lalu|trus|selain|cuma|hanya|khusus|tapi|bagian|nah|oh|eh|coba|sekarang yang|kalau yang)\b/u', $norm)) {
        return true;
    }
    if (preg_match('/\b(aja|saja|doang)\b/u', $norm) && !$e['kata_showroom']) {
        return true;
    }
    if ($e['semua_wilayah'] || $e['semua_jam']) {
        return true;
    }
    return count($tokens) <= 4 && !$e['kata_showroom'] && vroomAdaEntitas($e);
}

const VROOM_POLA_HALAMAN = '/^(tampilkan |lihat |yang |ada |kasih |tunjukkan )?(lainnya|yang lain|lain|lagi|berikutnya|selanjutnya|lanjut|lanjutkan|more|next|sisanya)( dong| ya| lagi| nya| juga)*$/u';
const VROOM_POLA_BANTUAN = '/\b(bisa apa|bisa bantu apa|bisa bantu|kamu siapa|siapa kamu|cara pakai|cara menggunakan|bantuan|help|apa yang bisa|fungsi kamu|tugas kamu|menu)\b/u';
const VROOM_POLA_SAPAAN = '/^((halo|hallo|helo|hai|hi|hello|hey|hei|pagi|siang|sore|malam|selamat (pagi|siang|sore|malam)|assalamualaikum|assalamu alaikum|salam|permisi|p|tes|test|ping|apa kabar|makasih|thanks|thank you|thx|oke|ok|okay|sip|mantap|baik|good)( |$))+(min|kak|bang|bot|vroom|ya|banyak|sekali|asisten|admin|semua|dong|gan)?( (min|kak|bang|bot|vroom|ya|banyak|sekali|asisten))*$/u';
const VROOM_POLA_LUAR = '/\b(harga|harganya|biaya|ongkos|tarif|murah|termurah|mahal|cicilan|kredit|angsuran|dp|uang muka|leasing|tenor|bunga|stok|stock|stoknya|ready|inden|indent|promo|promonya|diskon|cashback|potongan|bonus|hadiah|spesifikasi|spek|spec|specs|speknya|mesin|mesinnya|cc|tenaga|torsi|horsepower|bbm|bensin|irit|boros|konsumsi|top speed|kecepatan|akselerasi|dimensi|bagasi|fitur|fiturnya|warna|varian|transmisi|kapasitas|test drive|testdrive|tes drive|servis|service|bengkel|sparepart|suku cadang|onderdil|asuransi|pajak|stnk|bpkb|garansi|tukar tambah|trade in|bekas|second|seken|review|kelebihan|kekurangan|perbandingan|bandingkan|banding|vs|versus|bagus mana|lebih bagus|mana yang lebih|cuaca|hujan|presiden|menteri|gubernur|walikota|berita|politik|resep|film|lagu|musik|sepak ?bola|bola|saham|kurs|dolar|bitcoin|crypto|matematika|pr|tugas sekolah|cerita|puisi|lelucon|jokes|game)\b/u';

/**
 * Titik masuk utama chatbot. $now dan $ix bisa diisi dari tes.
 */
function vroomChatReply(array $request, ?DateTimeImmutable $now = null, ?array $ix = null): array
{
    $ix ??= vroomRdfIndex();
    $konteks = vroomKonteks($request['konteks'] ?? null, $ix);
    $lok = vroomLokasi($request['lokasi'] ?? null);
    $time = vroomWaktuPengguna($request['waktu'] ?? null, $now);
    $message = vroomChatText($request['pesan'] ?? null, 400);

    if ($message === '') {
        return vroomJawabanDasar('Tulis pertanyaan singkat tentang showroom, misalnya "showroom terdekat yang masih buka".', 'kosong', $konteks, vroomContohPertanyaan($lok));
    }
    $norm = vroomNormalisasi($message);
    if ($norm === '') {
        return vroomJawabanDasar(VROOM_HANDLER, 'di_luar_cakupan', $konteks, vroomContohPertanyaan($lok));
    }

    // Halaman berikutnya dari hasil yang sama.
    if (preg_match(VROOM_POLA_HALAMAN, $norm)) {
        if (!vroomKonteksAktif($konteks) && $konteks['halaman'] === 1 && !isset($request['konteks']['halaman'])) {
            return vroomJawabanDasar('Cari showroom dulu, nanti aku bisa tampilkan hasil berikutnya.', 'lanjutan', $konteks, vroomContohPertanyaan($lok));
        }
        $konteks['halaman']++;
        return vroomJawabCari($konteks, $lok, $time, $ix, 'lanjutan');
    }

    if (preg_match(VROOM_POLA_BANTUAN, $norm)) {
        return vroomJawabanDasar(
            'Aku Asisten Vroom. Aku bisa mencarikan showroom mobil dan motor di Sumatera Utara dari data Vroom: yang terdekat, yang masih buka, yang buka pada jam tertentu, rating tertinggi, atau berdasarkan merek, model, dan kota. Aku juga bisa memberi jam buka, alamat, dan telepon showroom tertentu.',
            'bantuan', $konteks, vroomContohPertanyaan($lok)
        );
    }
    if (preg_match(VROOM_POLA_SAPAAN, $norm)) {
        $thanks = preg_match('/\b(makasih|thanks|thank you|thx)\b/u', $norm) === 1;
        return vroomJawabanDasar(
            $thanks ? 'Sama-sama! Kalau mau cari showroom lagi, tinggal tanya ya.' : 'Halo! Aku Asisten Vroom. Aku bisa bantu cari showroom mobil dan motor di Sumatera Utara, misalnya yang terdekat, yang masih buka, atau yang menjual merek tertentu.',
            'sapaan', $konteks, vroomContohPertanyaan($lok)
        );
    }
    if (preg_match(VROOM_POLA_LUAR, $norm)) {
        return vroomJawabanDasar(VROOM_HANDLER, 'di_luar_cakupan', $konteks, vroomContohPertanyaan($lok));
    }

    $e = vroomEkstrak($norm, $ix);

    // Detail showroom tertentu berdasarkan nama.
    $names = vroomCocokNama($norm, $ix, $e['token_entitas']);
    if ($names !== []) {
        return vroomJawabInfo($names, $norm, $lok, $time, $ix, $konteks);
    }

    if ($e['wilayah_tidak_dikenal']) {
        return vroomJawabanDasar(VROOM_HANDLER, 'di_luar_cakupan', $konteks, ['Showroom Toyota di Medan', 'Dealer motor di Binjai']);
    }

    if (vroomKonteksAktif($konteks) && vroomPertanyaanLanjutan($norm, $e) && (vroomAdaEntitas($e) || $e['sisa'] === [])) {
        if (!vroomAdaEntitas($e)) {
            return vroomJawabanDasar(VROOM_HANDLER, 'di_luar_cakupan', $konteks, vroomSaran($konteks, $lok, 0));
        }
        $filter = vroomFilterDariEntitas($konteks, $e, $ix);
        return vroomJawabCari($filter, $lok, $time, $ix, 'lanjutan');
    }

    $known = vroomAdaEntitas($e);
    if (!$known && !$e['kata_showroom']) {
        return vroomJawabanDasar(VROOM_HANDLER, 'di_luar_cakupan', $konteks, vroomContohPertanyaan($lok));
    }
    // Kata tak dikenal tepat setelah "showroom/dealer/mobil" (mis. merek yang tidak ada di data).
    if (!$e['merek'] && !$e['model'] && $e['sisa'] !== [] && ($e['tak_dikenal_setelah_showroom'] || !$known)) {
        return vroomJawabanDasar(VROOM_HANDLER, 'di_luar_cakupan', $konteks, vroomContohPertanyaan($lok));
    }

    $filter = vroomFilterDariEntitas(vroomKonteksKosong(), $e, $ix);
    return vroomJawabCari($filter, $lok, $time, $ix, $filter['model'] !== '' ? 'info_model_dari_rdf' : 'cari_showroom');
}