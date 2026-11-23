<?php
/*
 * Pencarian semantik untuk daftar showroom.
 *
 * Kata kunci bebas seperti "showroom daihatsu di siantar" ditafsirkan memakai
 * kamus yang dibangun dari RDF (sama dengan Asisten Vroom):
 *   - merek   : rdfs:label dari ex:MerekKendaraan (+ ex:mewakiliMerek di showroom)
 *   - model   : ex:ModelKendaraan + ex:diproduksiOleh (mis. "avanza" -> Toyota)
 *   - wilayah : skos:prefLabel / skos:altLabel dari ex:Wilayah (mis. "siantar" -> Pematangsiantar)
 *   - kategori: "mobil" / "motor"
 * Hasilnya berupa daftar URI showroom yang lalu dipakai di SPARQL lewat VALUES.
 */
require_once __DIR__ . '/chatbot/nlu.php';
require_once __DIR__ . '/pencarian-showroom.php';

// Kata tentang harga memberi konteks kendaraan dalam pencarian direktori.
const VROOM_KATA_HARGA_SHOWROOM = [
    'harga', 'harganya', 'murah', 'termurah', 'terjangkau', 'ekonomis', 'hemat', 'mahal', 'termahal',
];

/**
 * Cari padanan satu edit pada nama dari RDF dan alias yang sudah dikenal.
 * Alias dengan entitas sama dihitung sebagai satu calon.
 */
function koreksiNamaEntitasShowroom(string $nama, string $jenis, array $kamus): ?string
{
    $kunci = implode('', tokenPencarianShowroom($nama));
    if ($kunci === '' || strlen($kunci) > 64) {
        return null;
    }

    $calon = [];
    foreach ($kamus as $entri) {
        if ($entri['jenis'] !== $jenis) {
            continue;
        }
        $kunciCalon = implode('', tokenPencarianShowroom($entri['frasa']));
        if ($kunciCalon === $kunci) {
            return null;
        }
        // Untuk merek dua huruf, hanya koreksi huruf yang terketik dua kali.
        $hurufGanda = strlen($kunciCalon) === 2 && strlen($kunci) === 3
            && preg_replace('/(.)\\1/', '$1', $kunci, 1) === $kunciCalon;
        if ($hurufGanda || jarakKataShowroom($kunci, $kunciCalon) === 1) {
            $idEntitas = json_encode($entri['nilai']);
            $calon[$idEntitas] ??= $entri['frasa'];
        }
    }
    return count($calon) === 1 ? reset($calon) : null;
}

/** Koreksi frasa merek dan wilayah secara terpisah untuk bentuk “merek di wilayah”. */
function koreksiFrasaShowroom(string $norm, array $kamus): ?string
{
    $bagian = explode(' di ', $norm, 2);
    if (count($bagian) !== 2) {
        return null;
    }

    $kataUmum = array_fill_keys(array_merge(VROOM_STOPWORDS, VROOM_KATA_HARGA_SHOWROOM), true);
    foreach (['merek', 'wilayah'] as $posisi => $jenis) {
        $token = explode(' ', $bagian[$posisi]);
        $awal = 0;
        $akhir = count($token);
        while ($awal < $akhir && isset($kataUmum[$token[$awal]])) {
            $awal++;
        }
        while ($akhir > $awal && isset($kataUmum[$token[$akhir - 1]])) {
            $akhir--;
        }
        $panjang = $akhir - $awal;
        $nama = implode(' ', array_slice($token, $awal, $panjang));
        $koreksi = koreksiNamaEntitasShowroom($nama, $jenis, $kamus);
        if ($koreksi !== null) {
            array_splice($token, $awal, $panjang, [$koreksi]);
            $bagian[$posisi] = implode(' ', $token);
        }
    }

    $saran = implode(' di ', $bagian);
    return $saran === $norm ? null : $saran;
}

/** Koreksi dengan bentuk kalimat yang dikenali, lalu coba format kata kunci sederhana. */
function koreksiIstilahShowroom(string $norm, array $entitas, array $sisa, array $ix): ?string
{
    $kamus = vroomKamus($ix)['entri'];
    if (str_contains($norm, ' di ')) {
        return koreksiFrasaShowroom($norm, $kamus);
    }
    if (count($sisa) !== 1) {
        return null;
    }
    if ($entitas['wilayah_tidak_dikenal']) {
        $jenis = 'wilayah';
    } elseif (!$entitas['merek'] && !$entitas['model']) {
        $jenis = 'merek';
    } else {
        return null;
    }

    $kata = $sisa[0];
    $token = explode(' ', $norm);
    $posisi = array_keys($token, $kata, true);
    if (count($posisi) !== 1) {
        return null;
    }

    $koreksi = koreksiNamaEntitasShowroom($kata, $jenis, $kamus);
    if ($koreksi === null) {
        return null;
    }
    $token[$posisi[0]] = $koreksi;
    return implode(' ', $token);
}

/**
 * Tafsirkan kata kunci. Mengembalikan null jika tidak ada merek/model/wilayah/kategori
 * yang dikenali (halaman lalu memakai pencarian nama showroom seperti biasa).
 *
 * @return array{uri: string[], label: string, entitas: array, saran?: string, pesan?: string}|null
 */
function tafsirkanPencarianShowroom(string $keyword, ?array $ix = null, bool $bolehKoreksi = false): ?array
{
    $keyword = trim($keyword);
    if ($keyword === '') {
        return null;
    }
    try {
        $ix ??= vroomRdfIndex();
    } catch (Throwable $err) {
        return null; // file .ttl tidak terbaca: pakai pencarian nama biasa
    }
 
    $norm = vroomNormalisasi($keyword);
    if ($norm === '') {
        return null;
    }
    // Konteks kendaraan membantu mengenali model yang juga berupa kata umum, seperti Beat.
    $adaKataHarga = array_intersect(explode(' ', $norm), VROOM_KATA_HARGA_SHOWROOM) !== [];
    $e = vroomEkstrak($adaKataHarga ? 'unit ' . $norm : $norm, $ix);
 
    $merek = $e['merek'];
    $model = $e['model'][0] ?? '';
    $wilayah = $e['wilayah'];
    $kategori = $e['kategori'];
    $hanya24jam = in_array('buka_24jam', $e['mode'], true);
 
    $wilayahTidakDikenal = $e['wilayah_tidak_dikenal'];
    if (!$merek && $model === '' && !$wilayah && $kategori === '' && !$wilayahTidakDikenal) {
        return null;
    }

    // Merek pembuat model (ex:diproduksiOleh), mis. "avanza" -> Toyota.
    $merekModel = $model !== '' ? ($ix['model'][$model]['merek'] ?? []) : [];
    $kategoriModel = $model !== '' ? ($ix['model'][$model]['kategori'] ?? '') : '';
 
    $hasil = [];
    foreach ($ix['showroom'] as $uri => $row) {
        if ($merek && !array_intersect($merek, $row['merek_kunci'])) {
            continue;
        }
        if ($merekModel && !array_intersect($merekModel, $row['merek_kunci'])) {
            continue;
        }
        if ($kategoriModel !== '' && $row['kategori'] !== '' && $row['kategori'] !== $kategoriModel) {
            continue;
        }
        if ($wilayah && !in_array($row['wilayah'], $wilayah, true)) {
            continue;
        }
        if ($kategori !== '' && $row['kategori'] !== $kategori) {
            continue;
        }
        if ($hanya24jam && stripos($row['jam'], '24 jam') === false) {
            continue;
        }
        $hasil[$uri] = $row;
    }
 
    // Setelah kendaraan dikenali, kata harga tidak menjadi syarat nama atau alamat showroom.
    $kataSisa = $merek || $model !== '' ? array_diff($e['sisa'], VROOM_KATA_HARGA_SHOWROOM) : $e['sisa'];
    $sisa = array_values(array_filter($kataSisa, static fn (string $w): bool => mb_strlen($w) >= 3));
    if ($wilayahTidakDikenal) {
        $hasil = [];
    } elseif ($sisa) {
        $hasil = array_filter($hasil, static function (array $row) use ($sisa): bool {
            $teks = vroomNormalisasi($row['nama'] . ' ' . ($row['alamat'] ?? ''));
            foreach ($sisa as $kata) {
                if (!str_contains($teks, $kata)) {
                    return false;
                }
            }
            return true;
        });
    }

    if (!$hasil && $bolehKoreksi) {
        $saran = koreksiIstilahShowroom($norm, $e, $sisa, $ix);
        if ($saran !== null) {
            $hasilSaran = tafsirkanPencarianShowroom($saran, $ix);
            if (!empty($hasilSaran['uri'])) {
                $hasilSaran['saran'] = $saran;
                return $hasilSaran;
            }
        }
    }

    // Kalimat ringkas untuk ditampilkan, mis. "showroom Daihatsu di Pematangsiantar".
    $label = 'showroom';
    if ($kategori !== '') {
        $label .= ' ' . $kategori;
    }
    if ($model !== '') {
        $label .= ' yang menjual ' . vroomLabelModel($model);
    } elseif ($merek) {
        $label .= ' ' . vroomGabungKata($merek, 'atau');
    }
    if ($hanya24jam) {
        $label .= ' yang buka 24 jam';
    }
    if ($wilayah) {
        $label .= ' di ' . vroomGabungKata($wilayah, 'atau');
    }
 
    $tafsiran = [
        'uri' => array_keys($hasil),
        'label' => $label,
        'entitas' => ['merek' => $merek, 'model' => $model, 'wilayah' => $wilayah, 'kategori' => $kategori],
    ];
    if ($wilayahTidakDikenal) {
        $tafsiran['pesan'] = 'Wilayahnya belum dikenali. Coba cek ejaan nama wilayah yang kamu ketik.';
    }
    return $tafsiran;
}
 
/** Ubah daftar URI showroom menjadi potongan SPARQL VALUES yang aman. */
function sparqlValuesShowroom(array $uris): string
{
    $aman = array_filter($uris, static fn ($u): bool =>
        is_string($u) && preg_match('~\Ahttp://www\.example\.org/showroom#Showroom[0-9]+\z~', $u) === 1);
    if (!$aman) {
        return 'FILTER(false) . '; // tidak ada showroom yang cocok
    }
    return 'VALUES ?showroom { <' . implode('> <', $aman) . '> } ';
}
 