<?php
/*
 * Pembaca RDF lokal untuk Asisten Vroom.
 *
 * Membaca langsung showroomSumut.ttl, model-kendaraan.ttl, dan wilayah-showroom.ttl
 * dari disk (tanpa Fuseki, tanpa HTTP), lalu membangun indeks di memori.
 * Hasil indeks disimpan di chatbot/cache/rdf-index.json dan dibangun ulang
 * otomatis jika salah satu file .ttl berubah.
 *
 * File ini juga berisi helper jam operasional, waktu WIB, dan jarak (Haversine)
 * khusus chatbot. jam-showroom.php sengaja tidak dipakai/diubah.
 */
declare(strict_types=1);

const VROOM_RDF_NS = 'http://www.example.org/showroom#';
const VROOM_RDF_TYPE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
const VROOM_RDFS_LABEL = 'http://www.w3.org/2000/01/rdf-schema#label';
const VROOM_SKOS = 'http://www.w3.org/2004/02/skos/core#';
const VROOM_GEO = 'http://www.w3.org/2003/01/geo/wgs84_pos#';
const VROOM_INDEX_VERSION = 3;
const VROOM_TTL_FILES = ['showroomSumut.ttl', 'model-kendaraan.ttl', 'wilayah-showroom.ttl'];

/* ------------------------------------------------------------------------
 * 1. Parser Turtle ringan
 * --------------------------------------------------------------------- */

/**
 * Pecah teks Turtle menjadi token [jenis, nilai, baris].
 */
function vroomTurtleTokens(string $text): array
{
    $pattern = '~\G(?:'
        . '(?<ws>\s+)'
        . '|(?<comment>\#[^\r\n]*)'
        . '|(?<iri><[^<>"{}|^`\\\\\s]*>)'
        . '|(?<lstr>"""(?:[^"\\\\]|\\\\.|"(?!""))*"""|\'\'\'(?:[^\'\\\\]|\\\\.|\'(?!\'\'))*\'\'\')'
        . '|(?<str>"(?:[^"\\\\\r\n]|\\\\.)*"|\'(?:[^\'\\\\\r\n]|\\\\.)*\')'
        . '|(?<lang>@[A-Za-z]+(?:-[A-Za-z0-9]+)*)'
        . '|(?<dt>\^\^)'
        . '|(?<num>[+-]?(?:\d+\.\d+|\.\d+|\d+)(?:[eE][+-]?\d+)?)'
        . '|(?<punct>[;,.\[\]()])'
        . '|(?<pname>(?:[A-Za-z][\w-]*)?:(?:[\w-]|%[0-9A-Fa-f]{2}|\.(?=[\w%-]))*|_:[\w-]+)'
        . '|(?<word>[A-Za-z]+)'
        . ')~su';

    $tokens = [];
    $offset = 0;
    $length = strlen($text);
    $line = 1;
    while ($offset < $length) {
        if (!preg_match($pattern, $text, $m, 0, $offset)) {
            throw new RuntimeException('Karakter Turtle tidak dikenal di baris ' . $line . ': ' . substr($text, $offset, 30));
        }
        $raw = $m[0];
        foreach (['ws', 'comment', 'iri', 'lstr', 'str', 'lang', 'dt', 'num', 'punct', 'pname', 'word'] as $type) {
            if (isset($m[$type]) && $m[$type] !== '') {
                if ($type === 'lang' && in_array(strtolower($raw), ['@prefix', '@base'], true)) {
                    $tokens[] = ['directive', strtolower($raw), $line];
                } elseif ($type === 'word' && in_array(strtoupper($raw), ['PREFIX', 'BASE'], true)) {
                    $tokens[] = ['directive', '@' . strtolower($raw), $line];
                } elseif ($type !== 'ws' && $type !== 'comment') {
                    $tokens[] = [$type, $raw, $line];
                }
                break;
            }
        }
        $line += substr_count($raw, "\n");
        $offset += strlen($raw);
    }
    return $tokens;
}

function vroomTurtleUnescape(string $raw): string
{
    $long = str_starts_with($raw, '"""') || str_starts_with($raw, "'''");
    $body = $long ? substr($raw, 3, -3) : substr($raw, 1, -1);

    return preg_replace_callback('~\\\\(u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)~su', static function (array $m): string {
        $c = $m[1];
        if ($c[0] === 'u' || $c[0] === 'U') {
            return mb_chr((int) hexdec(substr($c, 1)), 'UTF-8') ?: '';
        }
        return match ($c) {
            't' => "\t", 'n' => "\n", 'r' => "\r", 'b' => "\x08", 'f' => "\x0C",
            default => $c,
        };
    }, $body) ?? $body;
}

/**
 * Parse Turtle menjadi daftar triple [subjek, predikat, objek].
 * Objek berbentuk ['t' => 'iri'|'lit', 'v' => nilai].
 * Pernyataan yang rusak dilewati (sampai titik berikutnya) agar satu baris
 * yang salah tidak menghentikan seluruh chatbot.
 */
function vroomTurtleParse(string $text, array &$warnings = []): array
{
    $tokens = vroomTurtleTokens($text);
    $count = count($tokens);
    $prefixes = [];
    $base = '';
    $triples = [];
    $blank = 0;
    $i = 0;

    $peek = static fn (int $at): ?array => $tokens[$at] ?? null;
    $fail = static function (string $msg, ?array $tok): never {
        throw new UnexpectedValueException($msg . ' (baris ' . ($tok[2] ?? '?') . ')');
    };
    $expandName = static function (array $tok) use (&$prefixes, &$base, $fail): string {
        if ($tok[0] === 'iri') {
            $iri = substr($tok[1], 1, -1);
            return ($base !== '' && !preg_match('~^[a-z][a-z0-9+.-]*:~i', $iri)) ? $base . $iri : $iri;
        }
        if ($tok[0] === 'pname') {
            if (str_starts_with($tok[1], '_:')) {
                return $tok[1];
            }
            [$prefix, $local] = explode(':', $tok[1], 2);
            if (!array_key_exists($prefix, $prefixes)) {
                $fail('Prefix "' . $prefix . ':" belum dideklarasikan', $tok);
            }
            return $prefixes[$prefix] . $local;
        }
        $fail('Diharapkan IRI atau nama berprefix', $tok);
    };

    // Deklarasi fungsi rekursif untuk objek dan daftar predikat.
    $parsePredicateObjects = null;
    $parseObject = static function () use (&$i, &$tokens, &$triples, &$blank, $peek, $fail, $expandName, &$parsePredicateObjects): array {
        $tok = $peek($i);
        if ($tok === null) {
            $fail('Akhir file tak terduga', $tok);
        }
        if ($tok[0] === 'str' || $tok[0] === 'lstr') {
            $i++;
            $value = vroomTurtleUnescape($tok[1]);
            $next = $peek($i);
            if ($next !== null && $next[0] === 'lang') {
                $i++;
            } elseif ($next !== null && $next[0] === 'dt') {
                $i += 2; // lewati ^^ dan tipe datanya
            }
            return ['t' => 'lit', 'v' => $value];
        }
        if ($tok[0] === 'num') {
            $i++;
            return ['t' => 'lit', 'v' => $tok[1]];
        }
        if ($tok[0] === 'word' && in_array($tok[1], ['true', 'false'], true)) {
            $i++;
            return ['t' => 'lit', 'v' => $tok[1]];
        }
        if ($tok[0] === 'punct' && $tok[1] === '[') {
            $i++;
            $node = '_:b' . (++$blank);
            if (!($peek($i)[0] === 'punct' && $peek($i)[1] === ']')) {
                $parsePredicateObjects($node);
            }
            if (!($peek($i)[0] === 'punct' && $peek($i)[1] === ']')) {
                $fail('Diharapkan "]"', $peek($i));
            }
            $i++;
            return ['t' => 'iri', 'v' => $node];
        }
        $i++;
        return ['t' => 'iri', 'v' => $expandName($tok)];
    };

    $parsePredicateObjects = static function (string $subject) use (&$i, &$triples, $peek, $fail, $expandName, $parseObject): void {
        while (true) {
            $tok = $peek($i);
            if ($tok === null) {
                $fail('Akhir file tak terduga', $tok);
            }
            if ($tok[0] === 'word' && $tok[1] === 'a') {
                $predicate = VROOM_RDF_TYPE;
                $i++;
            } else {
                $predicate = $expandName($tok);
                $i++;
            }
            while (true) {
                $object = $parseObject();
                $triples[] = [$subject, $predicate, $object];
                $sep = $peek($i);
                if ($sep !== null && $sep[0] === 'punct' && $sep[1] === ',') {
                    $i++;
                    continue;
                }
                break;
            }
            $sep = $peek($i);
            if ($sep !== null && $sep[0] === 'punct' && $sep[1] === ';') {
                // Boleh ada beberapa ";" berturut-turut atau ";" sebelum "." / "]".
                while (($s = $peek($i)) !== null && $s[0] === 'punct' && $s[1] === ';') {
                    $i++;
                }
                $after = $peek($i);
                if ($after !== null && $after[0] === 'punct' && in_array($after[1], ['.', ']'], true)) {
                    return;
                }
                continue;
            }
            return;
        }
    };

    while ($i < $count) {
        $start = $i;
        try {
            $tok = $tokens[$i];
            if ($tok[0] === 'directive') {
                $i++;
                if ($tok[1] === '@prefix') {
                    $name = $peek($i);
                    $iri = $peek($i + 1);
                    if ($name === null || $name[0] !== 'pname' || !str_ends_with($name[1], ':') || $iri === null || $iri[0] !== 'iri') {
                        $fail('Deklarasi @prefix tidak valid', $tok);
                    }
                    $prefixes[substr($name[1], 0, -1)] = substr($iri[1], 1, -1);
                    $i += 2;
                } else {
                    $iri = $peek($i);
                    if ($iri === null || $iri[0] !== 'iri') {
                        $fail('Deklarasi @base tidak valid', $tok);
                    }
                    $base = substr($iri[1], 1, -1);
                    $i++;
                }
                $dot = $peek($i);
                if ($dot !== null && $dot[0] === 'punct' && $dot[1] === '.') {
                    $i++;
                }
                continue;
            }

            if ($tok[0] === 'punct' && $tok[1] === '[') {
                $subjectObj = $parseObject();
                $subject = $subjectObj['v'];
            } else {
                $subject = $expandName($tok);
                $i++;
            }
            $parsePredicateObjects($subject);
            $dot = $peek($i);
            if ($dot === null || $dot[0] !== 'punct' || $dot[1] !== '.') {
                $fail('Diharapkan "." di akhir pernyataan', $dot ?? $tokens[$count - 1]);
            }
            $i++;
        } catch (UnexpectedValueException $error) {
            $warnings[] = $error->getMessage();
            // Pulihkan: lompat ke titik berikutnya.
            $i = max($i, $start + 1);
            while ($i < $count && !($tokens[$i][0] === 'punct' && $tokens[$i][1] === '.')) {
                $i++;
            }
            $i++;
        }
    }

    return $triples;
}

/* ------------------------------------------------------------------------
 * 2. Pembangunan indeks
 * --------------------------------------------------------------------- */

function vroomRdfDir(): string
{
    return dirname(__DIR__);
}

function vroomRdfCacheFile(): string
{
    return __DIR__ . '/cache/rdf-index.json';
}

function vroomRdfSourceStamp(string $dir): array
{
    $stamp = [];
    foreach (VROOM_TTL_FILES as $file) {
        $path = $dir . '/' . $file;
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('File data ' . $file . ' tidak ditemukan di folder proyek.');
        }
        $stamp[$file] = filemtime($path) . ':' . filesize($path);
    }
    return $stamp;
}

function vroomRdfBuildIndex(string $dir): array
{
    $warnings = [];
    $triples = [];
    foreach (VROOM_TTL_FILES as $file) {
        $text = file_get_contents($dir . '/' . $file);
        if ($text === false) {
            throw new RuntimeException('File data ' . $file . ' tidak bisa dibaca.');
        }
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        foreach (vroomTurtleParse($text, $warnings) as $triple) {
            $triples[] = $triple;
        }
    }

    // Kelompokkan per subjek, simpan urutan nilai seperti di file.
    $subjects = [];
    foreach ($triples as [$s, $p, $o]) {
        $subjects[$s][$p][] = $o['v'];
    }
    $first = static fn (array $props, string $p): string => trim((string) ($props[$p][0] ?? ''));
    $isA = static fn (array $props, string $class): bool => in_array($class, $props[VROOM_RDF_TYPE] ?? [], true);

    // Merek
    $brands = [];        // label => ['label','iri','showroom'=>[]]
    $brandByIri = [];    // iri => label
    foreach ($subjects as $s => $props) {
        if ($isA($props, VROOM_RDF_NS . 'MerekKendaraan')) {
            $label = $first($props, VROOM_RDFS_LABEL);
            if ($label !== '') {
                $brands[$label] = ['label' => $label, 'iri' => $s, 'showroom' => []];
                $brandByIri[$s] = $label;
            }
        }
    }
    $brandByLower = [];
    foreach ($brands as $label => $_) {
        $brandByLower[mb_strtolower($label)] = $label;
    }

    // Model
    $models = [];
    foreach ($subjects as $s => $props) {
        if (!$isA($props, VROOM_RDF_NS . 'ModelKendaraan')) {
            continue;
        }
        $label = mb_strtolower($first($props, VROOM_RDFS_LABEL));
        if ($label === '') {
            continue;
        }
        $models[$label] ??= ['label' => $label, 'merek' => [], 'kategori' => ''];
        foreach ($props[VROOM_RDF_NS . 'diproduksiOleh'] ?? [] as $brandIri) {
            if (isset($brandByIri[$brandIri]) && !in_array($brandByIri[$brandIri], $models[$label]['merek'], true)) {
                $models[$label]['merek'][] = $brandByIri[$brandIri];
            }
        }
        $kategori = mb_strtolower($first($props, VROOM_RDF_NS . 'kategoriModel'));
        if (in_array($kategori, ['mobil', 'motor'], true)) {
            $models[$label]['kategori'] = $kategori;
        }
    }

    // Wilayah
    $regions = [];
    $regionAlias = [];
    foreach ($subjects as $s => $props) {
        if (!$isA($props, VROOM_RDF_NS . 'Wilayah')) {
            continue;
        }
        $pref = $first($props, VROOM_SKOS . 'prefLabel');
        if ($pref === '') {
            continue;
        }
        $regions[] = $pref;
        $regionAlias[mb_strtolower($pref)] = $pref;
        foreach ($props[VROOM_SKOS . 'altLabel'] ?? [] as $alt) {
            $regionAlias[mb_strtolower(trim($alt))] = $pref;
        }
    }

    // Showroom
    $showrooms = [];
    foreach ($subjects as $s => $props) {
        if (!$isA($props, VROOM_RDF_NS . 'Showroom')) {
            continue;
        }
        $displayBrands = [];
        $keyBrands = [];
        foreach ($props[VROOM_RDF_NS . 'merek'] ?? [] as $literal) {
            $name = trim((string) preg_replace('/^\s*dealer\s+/iu', '', trim($literal)));
            if ($name === '') {
                continue;
            }
            $lower = mb_strtolower($name);
            if (isset($brandByLower[$lower])) {
                $name = $brandByLower[$lower]; // "Aion" → "AION"
                $keyBrands[] = $name;
            } else {
                foreach ($brandByLower as $bLower => $bLabel) {
                    if (str_starts_with($lower, $bLower . ' ')) { // "Honda Wing" → Honda
                        $keyBrands[] = $bLabel;
                        break;
                    }
                }
            }
            if (!in_array(mb_strtolower($name), array_map('mb_strtolower', $displayBrands), true)) {
                $displayBrands[] = $name;
            }
        }
        foreach ($props[VROOM_RDF_NS . 'mewakiliMerek'] ?? [] as $brandIri) {
            if (isset($brandByIri[$brandIri])) {
                $keyBrands[] = $brandByIri[$brandIri];
            }
        }
        $keyBrands = array_values(array_unique($keyBrands));
        if ($displayBrands === []) {
            $displayBrands = $keyBrands;
        }

        $ratingText = str_replace(',', '.', $first($props, VROOM_RDF_NS . 'rating'));
        $lat = $first($props, VROOM_GEO . 'lat');
        $lon = $first($props, VROOM_GEO . 'long');
        $latF = is_numeric($lat) ? (float) $lat : null;
        $lonF = is_numeric($lon) ? (float) $lon : null;
        if ($latF === null || $lonF === null || abs($latF) > 90 || abs($lonF) > 180) {
            $latF = $lonF = null;
        }
        $kategori = mb_strtolower($first($props, VROOM_RDF_NS . 'kategori'));
        $wilayah = $first($props, VROOM_RDF_NS . 'berlokasiDi');

        $showrooms[$s] = [
            'id' => $s,
            'nama' => $first($props, VROOM_RDF_NS . 'namaShowroom') ?: preg_replace('~^.*#~', '', $s),
            'merek' => $displayBrands,
            'merek_kunci' => $keyBrands,
            'alamat' => $first($props, VROOM_RDF_NS . 'alamat'),
            'jam' => $first($props, VROOM_RDF_NS . 'jamOperasional'),
            'telepon' => $first($props, VROOM_RDF_NS . 'noTelepon'),
            'website' => $first($props, VROOM_RDF_NS . 'website'),
            'rating' => is_numeric($ratingText) ? round((float) $ratingText, 1) : null,
            'wilayah' => $regionAlias[mb_strtolower($wilayah)] ?? $wilayah,
            'kategori' => in_array($kategori, ['mobil', 'motor'], true) ? $kategori : '',
            'lat' => $latF,
            'lon' => $lonF,
        ];
        foreach ($keyBrands as $brand) {
            if (isset($brands[$brand])) {
                $brands[$brand]['showroom'][] = $s;
            }
        }
    }

    // Kategori per merek (dari showroom yang mewakilinya).
    foreach ($brands as $label => &$brand) {
        $cats = [];
        foreach ($brand['showroom'] as $id) {
            $cats[$showrooms[$id]['kategori']] = true;
        }
        $brand['kategori'] = array_values(array_filter(array_keys($cats)));
    }
    unset($brand);

    // Wilayah yang muncul di data showroom tapi tidak ada di wilayah-showroom.ttl.
    foreach ($showrooms as $row) {
        if ($row['wilayah'] !== '' && !in_array($row['wilayah'], $regions, true)) {
            $regions[] = $row['wilayah'];
            $regionAlias[mb_strtolower($row['wilayah'])] = $row['wilayah'];
        }
    }

    return [
        'versi' => VROOM_INDEX_VERSION,
        'sumber' => vroomRdfSourceStamp($dir),
        'showroom' => $showrooms,
        'merek' => $brands,
        'model' => $models,
        'wilayah' => $regions,
        'wilayah_alias' => $regionAlias,
        'peringatan' => array_slice($warnings, 0, 20),
    ];
}

/**
 * Ambil indeks RDF (memori → cache JSON → parse ulang).
 */
function vroomRdfIndex(?string $dir = null, bool $useCache = true): array
{
    static $memo = [];
    $dir ??= vroomRdfDir();
    if (isset($memo[$dir])) {
        return $memo[$dir];
    }

    $stamp = vroomRdfSourceStamp($dir);
    $cacheFile = vroomRdfCacheFile();
    if ($useCache && is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($cached) && ($cached['versi'] ?? 0) === VROOM_INDEX_VERSION && ($cached['sumber'] ?? null) === $stamp) {
            return $memo[$dir] = $cached;
        }
    }

    $index = vroomRdfBuildIndex($dir);
    if ($useCache) {
        vroomRdfWriteCache($cacheFile, $index);
    }
    return $memo[$dir] = $index;
}

function vroomRdfWriteCache(string $file, array $index): bool
{
    $folder = dirname($file);
    if (!is_dir($folder) && !@mkdir($folder, 0775, true)) {
        return false;
    }
    if (!is_writable($folder)) {
        return false;
    }
    $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        return false;
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) { // Windows: rename gagal jika file tujuan terkunci
        @unlink($file);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
    }
    return true;
}

/* ------------------------------------------------------------------------
 * 3. Jam operasional (fungsi sendiri, tidak mengubah jam-showroom.php)
 * --------------------------------------------------------------------- */

/**
 * Hasil: ['tipe' => 'rentang', 'buka' => menit, 'tutup' => menit]
 *        ['tipe' => '24jam'] | ['tipe' => 'kosong'] | ['tipe' => 'tutup-permanen']
 *        ['tipe' => 'tidak-dikenal', 'teks' => string]
 */
function vroomJamParse(?string $text): array
{
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    if ($text === '' || preg_match('/^[-–—]+$/u', $text)) {
        return ['tipe' => 'kosong'];
    }
    if (preg_match('/^(?:buka\s*)?24\s*jam(?:\s*\/?\s*(?:setiap|tiap)\s*hari)?$/iu', $text)
        || preg_match('/^(?:24\s*\/\s*7|00[.:]00\s*[-–—]\s*(?:24[.:]00|23[.:]59))$/u', $text)) {
        return ['tipe' => '24jam'];
    }
    if (preg_match('/^tutup(?:\s+permanen)?$/iu', $text)) {
        return ['tipe' => 'tutup-permanen'];
    }
    $time = '(\d{1,2})(?:\s*[.:]\s*([0-5]\d))?';
    if (preg_match('/^(?:buka\s*)?(?:pukul\s*|jam\s*)?' . $time . '\s*(?:wib)?\s*(?:[-–—]|s\.?\/?d\.?|sampai|hingga)\s*' . $time . '\s*(?:wib)?$/iu', $text, $m)) {
        $open = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        $close = (int) $m[3] * 60 + (int) ($m[4] ?? 0);
        if ($open < 1440 && $close <= 1440 && (int) $m[1] <= 24 && (int) $m[3] <= 24) {
            if ($open === $close || ($open === 0 && $close === 1440)) {
                return ['tipe' => '24jam'];
            }
            return ['tipe' => 'rentang', 'buka' => $open, 'tutup' => $close % 1440 === 0 && $close > 0 ? 1440 : $close];
        }
    }
    return ['tipe' => 'tidak-dikenal', 'teks' => $text];
}

function vroomFormatMenit(int $minute): string
{
    $minute = (($minute % 1440) + 1440) % 1440;
    return sprintf('%02d.%02d', intdiv($minute, 60), $minute % 60);
}

function vroomFormatDurasi(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    $parts = [];
    if ($h > 0) {
        $parts[] = $h . ' jam';
    }
    if ($m > 0 || $h === 0) {
        $parts[] = $m . ' menit';
    }
    return implode(' ', $parts);
}

function vroomJamLabel(array $jam): string
{
    return match ($jam['tipe']) {
        'rentang' => vroomFormatMenit($jam['buka']) . ' – ' . ($jam['tutup'] === 1440 ? '24.00' : vroomFormatMenit($jam['tutup'])),
        '24jam' => 'Buka 24 jam',
        'tidak-dikenal' => $jam['teks'],
        'tutup-permanen' => 'Tutup',
        default => '',
    };
}

/**
 * Status showroom pada menit tertentu (menit WIB sejak 00.00).
 * Hasil: ['status' => buka|segera-tutup|tutup|24jam|tidak-diketahui, 'teks' => ..., 'sisa' => ?int, 'tutup_menit' => ?int]
 */
function vroomJamStatus(array $jam, int $minute): array
{
    $minute = (($minute % 1440) + 1440) % 1440;
    if ($jam['tipe'] === '24jam') {
        return ['status' => '24jam', 'teks' => 'Buka 24 jam', 'sisa' => 1440, 'tutup_menit' => 1440 * 2];
    }
    if ($jam['tipe'] === 'tutup-permanen') {
        return ['status' => 'tutup', 'teks' => 'Tutup menurut data Vroom', 'sisa' => null, 'tutup_menit' => -1];
    }
    if ($jam['tipe'] !== 'rentang') {
        return ['status' => 'tidak-diketahui', 'teks' => 'Jam operasional belum tersedia', 'sisa' => null, 'tutup_menit' => -1];
    }

    $open = $jam['buka'];
    $close = $jam['tutup'];
    $overnight = $close <= $open;
    $isOpen = $overnight ? ($minute >= $open || $minute < $close % 1440) : ($minute >= $open && $minute < $close);
    // Untuk mengurutkan "tutup paling malam": jam tutup lewat tengah malam dihitung > 24.00.
    $closeSort = $overnight ? $close + 1440 : $close;

    if ($isOpen) {
        $left = ($close - $minute + 1440) % 1440;
        if ($left === 0) {
            $left = 1440;
        }
        $closeLabel = vroomFormatMenit($close);
        if ($left <= 30) {
            return ['status' => 'segera-tutup', 'teks' => 'Segera tutup, tutup ' . vroomFormatDurasi($left) . ' lagi (pukul ' . $closeLabel . ')', 'sisa' => $left, 'tutup_menit' => $closeSort];
        }
        return ['status' => 'buka', 'teks' => 'Buka, tutup ' . vroomFormatDurasi($left) . ' lagi', 'sisa' => $left, 'tutup_menit' => $closeSort];
    }

    $tomorrow = !$overnight && $minute >= $close;
    return [
        'status' => 'tutup',
        'teks' => 'Tutup, buka ' . ($tomorrow ? 'besok ' : '') . 'pukul ' . vroomFormatMenit($open),
        'sisa' => null,
        'tutup_menit' => $closeSort,
    ];
}

function vroomJamTerbuka(array $status): bool
{
    return in_array($status['status'], ['buka', 'segera-tutup', '24jam'], true);
}

/* ------------------------------------------------------------------------
 * 4. Waktu pengguna → WIB
 * --------------------------------------------------------------------- */

const VROOM_NAMA_HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

/**
 * Validasi field "waktu" dari klien. Jika tidak valid atau selisih > 24 jam
 * dari jam server, pakai jam server.
 */
function vroomWaktuPengguna(mixed $input, ?DateTimeImmutable $serverNow = null): array
{
    $wibZone = new DateTimeZone('Asia/Jakarta');
    $serverNow ??= new DateTimeImmutable('now', $wibZone);
    $instant = $serverNow;
    $source = 'server';
    $offset = -420;

    if (is_array($input)) {
        $iso = $input['iso'] ?? null;
        if (is_string($iso) && strlen($iso) <= 40
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})$/', $iso)) {
            try {
                $candidate = new DateTimeImmutable($iso);
                if (abs($candidate->getTimestamp() - $serverNow->getTimestamp()) <= 86400) {
                    $instant = $candidate;
                    $source = 'perangkat';
                }
            } catch (Exception) {
                // abaikan, pakai jam server
            }
        }
        $off = $input['offset_menit'] ?? null;
        if ($source === 'perangkat' && is_numeric($off) && (int) $off >= -840 && (int) $off <= 720 && (float) $off === floor((float) $off)) {
            $offset = (int) $off;
        }
    }

    $wib = $instant->setTimezone($wibZone);
    $minuteWib = (int) $wib->format('G') * 60 + (int) $wib->format('i');
    $local = $instant->setTimezone(new DateTimeZone(vroomOffsetToZone($offset)));
    $zoneLabel = match ($offset) {
        -420 => 'WIB',
        -480 => 'WITA',
        -540 => 'WIT',
        default => 'UTC' . vroomOffsetToZone($offset),
    };

    return [
        'sumber' => $source,
        'menit_wib' => $minuteWib,
        'jam_wib' => vroomFormatMenit($minuteWib),
        'hari_wib' => VROOM_NAMA_HARI[(int) $wib->format('w')],
        'offset_menit' => $offset,
        'zona_label' => $zoneLabel,
        'jam_lokal' => $local->format('H.i'),
        'bukan_wib' => $offset !== -420,
        'tanggal_wib' => $wib->format('Y-m-d'),
    ];
}

function vroomOffsetToZone(int $offsetMinutes): string
{
    $east = -$offsetMinutes; // getTimezoneOffset(): WIB = -420
    $sign = $east >= 0 ? '+' : '-';
    $east = abs($east);
    return sprintf('%s%02d:%02d', $sign, intdiv($east, 60), $east % 60);
}

/** "Sekarang pukul 17.31 WIB (Senin)" atau "Sekarang pukul 18.31 WITA (17.31 WIB, Senin)" */
function vroomKalimatWaktu(array $time): string
{
    if ($time['bukan_wib']) {
        return 'Sekarang pukul ' . $time['jam_lokal'] . ' ' . $time['zona_label'] . ' (' . $time['jam_wib'] . ' WIB, ' . $time['hari_wib'] . ')';
    }
    return 'Sekarang pukul ' . $time['jam_wib'] . ' WIB (' . $time['hari_wib'] . ')';
}

/* ------------------------------------------------------------------------
 * 5. Jarak dan format angka
 * --------------------------------------------------------------------- */

function vroomJarakKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(sqrt(min(1.0, max(0.0, $a))));
}

function vroomFormatJarak(float $km): string
{
    if ($km < 1) {
        $m = (int) max(10, round($km * 1000 / 10) * 10);
        return $m >= 1000 ? '1,0 km' : $m . ' m';
    }
    return number_format($km, 1, ',', '.') . ' km';
}

function vroomFormatRating(?float $rating): string
{
    return $rating === null ? '' : number_format($rating, 1, ',', '');
}
