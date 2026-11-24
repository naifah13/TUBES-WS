<?php
/*
 * Daftar kendaraan yang dijual di sebuah showroom (halaman detail).
 *
 * Alur semantiknya:
 *   showroom --ex:mewakiliMerek--> merek <--ex:diproduksiOleh-- model kendaraan
 * Relasi ini diambil dari Fuseki (named graph urn:vroom:relasi-kendaraan),
 * lalu tiap model dicocokkan dengan fotonya di images/mobil/<Merek>/ atau images/motor/<Merek>/.
 */

const KENDARAAN_TAMPIL_AWAL = 8;

/** Kunci pembanding: huruf kecil, hanya huruf dan angka ("go+" -> "goplus", "D-Max" -> "dmax"). */
function kunciKendaraan(string $teks): string
{
    $teks = str_replace('+', 'plus', mb_strtolower($teks, 'UTF-8'));
    return preg_replace('/[^a-z0-9]/', '', $teks) ?? '';
}

/** Ubah label RDF ("cx5", "xpander cross", "santa fe") menjadi nama tampilan ("CX-5", "Xpander Cross", "Santa Fe"). */
function namaModelKendaraan(string $label): string
{
    $label = trim(mb_strtolower($label, 'UTF-8'));
    $utuh = [
        'crv' => 'CR-V', 'hrv' => 'HR-V', 'brv' => 'BR-V', 'wrv' => 'WR-V', 'gtr' => 'GT-R',
        'xtrail' => 'X-Trail', 'stepwgn' => 'Step WGN', 'bz4x' => 'bZ4X', 'ix' => 'iX',
        'glory i-auto' => 'Glory i-Auto', 'rx king' => 'RX-King', 'd-max' => 'D-Max', 'dmax' => 'D-Max',
        'mux' => 'MU-X', 'xl7' => 'XL7', 'mg one' => 'MG ONE',
        'nmax' => 'NMAX', 'xmax' => 'XMAX', 'tmax' => 'TMAX', 'ecosport' => 'EcoSport',
    ];
    if (isset($utuh[$label])) {
        return $utuh[$label];
    }
    $kataBiasa = ['elf', 'mio', 'nex', 'one', 'air', 'fe', 'max', 'cab', 'lexi', 'fino'];
    $rapikan = static function (string $kata) use ($kataBiasa): string {
        if ($kata === 'aion') {
            return 'AION';
        }
        if (preg_match('/^mazda(\d)$/', $kata, $m)) {
            return 'Mazda' . $m[1];                          // mazda2 -> Mazda2
        }
        if (preg_match('/^(cx|bt|mx|rx)(\d+)$/', $kata, $m)) {
            return strtoupper($m[1]) . '-' . $m[2];          // cx5 -> CX-5
        }
        if (preg_match('/^\d+i$|^i\d+$/', $kata)) {
            return $kata;                                    // 118i, i10
        }
        if (preg_match('/\d/', $kata) || (mb_strlen($kata) <= 3 && !in_array($kata, $kataBiasa, true))) {
            return mb_strtoupper($kata, 'UTF-8');            // A200, GLC, PCX
        }
        return mb_strtoupper(mb_substr($kata, 0, 1)) . mb_substr($kata, 1);
    };
    $hasil = [];
    foreach (explode(' ', $label) as $kata) {
        $hasil[] = implode('-', array_map($rapikan, explode('-', $kata)));
    }
    return implode(' ', $hasil);
}

/**
 * Indeks foto: [kunciMerek => [kunciModel => ['kategori' => 'mobil'|'motor', 'path' => 'images/...']]].
 */
function indeksFotoKendaraan(): array
{
    static $indeks = null;
    if ($indeks !== null) {
        return $indeks;
    }
    $indeks = [];
    foreach (['mobil', 'motor'] as $kategori) {
        $dasar = __DIR__ . '/images/' . $kategori;
        foreach (is_dir($dasar) ? scandir($dasar) : [] as $folderMerek) {
            if ($folderMerek[0] === '.' || !is_dir($dasar . '/' . $folderMerek)) {
                continue;
            }
            foreach (scandir($dasar . '/' . $folderMerek) as $file) {
                if (!preg_match('/\.(jpe?g|png|webp|avif)$/i', $file)) {
                    continue;
                }
                // Lewati format yang tidak bisa ditampilkan browser (mis. JPEG XL yang diberi nama .jpg).
                $kepala = (string) file_get_contents($dasar . '/' . $folderMerek . '/' . $file, false, null, 0, 12);
                if (str_starts_with($kepala, "\xFF\x0A") || str_contains($kepala, 'JXL ')) {
                    continue;
                }
                $kunci = kunciKendaraan(pathinfo($file, PATHINFO_FILENAME));
                $indeks[kunciKendaraan($folderMerek)][$kunci] ??= [
                    'kategori' => $kategori,
                    'path' => 'images/' . $kategori . '/' . rawurlencode($folderMerek) . '/' . rawurlencode($file),
                ];
            }
        }
    }
    return $indeks;
}

/** Logo merek untuk kartu tanpa foto. */
function logoMerekKendaraan(string $merek, string $kategori): string
{
    $slug = mb_strtolower($merek, 'UTF-8');
    if ($slug === 'honda') {
        $slug = $kategori === 'motor' ? 'honda motor' : 'honda mobil';
    }
    return is_file(__DIR__ . '/images/logo/' . $slug . '.jpg') ? 'images/logo/' . rawurlencode($slug . '.jpg') : '';
}

/**
 * Ambil model kendaraan yang dijual showroom.
 *
 * @return array|null null jika Fuseki gagal; [] jika memang tidak ada data model.
 */
function ambilKendaraanShowroom(string $uriShowroom, string $kategoriShowroom): ?array
{
    if (!preg_match('~\Ahttp://www\.example\.org/showroom#Showroom[0-9]+\z~', $uriShowroom)) {
        return [];
    }

    $query = <<<SPARQL
        PREFIX ex: <http://www.example.org/showroom#>
        PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

        SELECT DISTINCT ?model ?labelModel ?namaMerek ?kategoriModel
        WHERE {
            GRAPH <urn:vroom:relasi-kendaraan> {
                <$uriShowroom> ex:mewakiliMerek ?merek .
                ?merek rdfs:label ?namaMerek .
                ?model a ex:ModelKendaraan ;
                       rdfs:label ?labelModel ;
                       ex:diproduksiOleh ?merek .
                OPTIONAL { ?model ex:kategoriModel ?kategoriModel . }
            }
        }
        ORDER BY ?namaMerek ?labelModel
        SPARQL;

    $data = executeSparqlQuery($query);
    $bindings = $data['results']['bindings'] ?? null;
    if (!is_array($bindings)) {
        return null;
    }

    // 1. Kumpulkan model unik per merek ("dmax" dan "d-max" dihitung satu).
    $model = [];
    foreach ($bindings as $row) {
        $merek = $row['namaMerek']['value'];
        $label = $row['labelModel']['value'];
        $kunci = kunciKendaraan($merek) . '|' . kunciKendaraan($label);
        $model[$kunci] ??= [
            'uri' => $row['model']['value'],
            'label' => $label,
            'kunci' => kunciKendaraan($label),
            'merek' => $merek,
            'kunciMerek' => kunciKendaraan($merek),
            'kategori' => strtolower($row['kategoriModel']['value'] ?? ''),
            'foto' => null,
        ];
    }

    // 2. Cocokkan foto: nama persis dulu, lalu ejaan mirip ("jimny" -> jimn.jpg) untuk foto yang belum terpakai.
    $foto = indeksFotoKendaraan();
    $terpakai = [];
    foreach ($model as &$m) {
        $calon = $foto[$m['kunciMerek']][$m['kunci']] ?? null;
        if ($calon && !isset($terpakai[$calon['path']])) {
            $m['foto'] = $calon;
            $terpakai[$calon['path']] = true;
        }
    }
    unset($m);
    foreach ($model as &$m) {
        if ($m['foto'] || strlen($m['kunci']) < 4) {
            continue;
        }
        $batas = strlen($m['kunci']) >= 6 ? 2 : 1;
        $terbaik = [];
        foreach ($foto[$m['kunciMerek']] ?? [] as $kunciFoto => $calon) {
            if (isset($terpakai[$calon['path']])) {
                continue;
            }
            $jarak = levenshtein($m['kunci'], (string) $kunciFoto);
            if ($jarak <= $batas) {
                $terbaik[$jarak][] = $calon;
            }
        }
        ksort($terbaik);
        $calon = reset($terbaik);
        if ($calon && count($calon) === 1) {
            $m['foto'] = $calon[0];
            $terpakai[$calon[0]['path']] = true;
        }
    }
    unset($m);

    // 3. Tentukan kategori, saring sesuai showroom, buang label umum tanpa foto ("tiggo" sudah diwakili "tiggo 7").
    $kategoriMerek = [];
    foreach ($foto as $kunciMerek => $daftar) {
        foreach ($daftar as $f) {
            $kategoriMerek[$kunciMerek][$f['kategori']] = true;
        }
    }
    $hasil = [];
    foreach ($model as $m) {
        if ($m['kategori'] === '') {
            $m['kategori'] = $m['foto']['kategori']
                ?? (count($kategoriMerek[$m['kunciMerek']] ?? []) === 1 ? array_key_first($kategoriMerek[$m['kunciMerek']]) : $kategoriShowroom);
        }
        if ($kategoriShowroom !== '' && $m['kategori'] !== '' && $m['kategori'] !== $kategoriShowroom) {
            continue;
        }
        if (!$m['foto']) {
            foreach ($model as $lain) {
                if ($lain['kunciMerek'] === $m['kunciMerek'] && $lain['kunci'] !== $m['kunci'] && str_starts_with($lain['kunci'], $m['kunci'])) {
                    continue 2;
                }
            }
        }
        $hasil[] = [
            'uri' => $m['uri'],
            'nama' => namaModelKendaraan($m['label']),
            'merek' => $m['merek'],
            'kategori' => $m['kategori'],
            'foto' => $m['foto']['path'] ?? '',
            'logo' => logoMerekKendaraan($m['merek'], $m['kategori']),
        ];
    }

    // Yang punya foto tampil lebih dulu, lalu urut abjad.
    usort($hasil, static fn(array $a, array $b): int =>
        [$a['foto'] === '', $a['merek']] <=> [$b['foto'] === '', $b['merek']] ?: strnatcasecmp($a['nama'], $b['nama']));
    return $hasil;
}
