<?php

function tokenPencarianShowroom(string $teks): array
{
    $teks = function_exists('mb_strtolower') ? mb_strtolower($teks, 'UTF-8') : strtolower($teks);
    return preg_split('/[^\p{L}\p{N}]+/u', $teks, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function jarakKataShowroom(string $kata, string $calon): int
{
    if ($kata === $calon) {
        return 0;
    }

    // Kode pendek seperti MG atau R15 mudah tertukar dengan model lain.
    $panjang = min(strlen($kata), strlen($calon));
    if ($panjang < 3 || !ctype_alnum($kata) || !ctype_alnum($calon)) {
        return -1;
    }

    // Dua huruf bersebelahan yang tertukar dihitung sebagai satu typo.
    if (strlen($kata) === strlen($calon)) {
        $beda = 0;
        while ($kata[$beda] === $calon[$beda]) {
            $beda++;
        }

        if ($beda + 1 < $panjang
            && $kata[$beda] === $calon[$beda + 1]
            && $kata[$beda + 1] === $calon[$beda]
            && substr($kata, $beda + 2) === substr($calon, $beda + 2)) {
            return 1;
        }
    }

    $batas = $panjang >= 7 ? 2 : (max(strlen($kata), strlen($calon)) >= 4 ? 1 : 0);
    $jarak = levenshtein($kata, $calon);
    return $jarak <= $batas ? $jarak : -1;
}

function cariShowroomMirip(string $keyword, array $baris, array $model_ke_merek): array
{
    $kata_kunci = tokenPencarianShowroom($keyword);
    if (!$kata_kunci || strlen($keyword) > 160) {
        return [[], false];
    }

    $cache_jarak = [];
    $jarak_kata = static function (string $kata, string $calon) use (&$cache_jarak): int {
        return $cache_jarak[$kata][$calon] ??= jarakKataShowroom($kata, $calon);
    };
    $skor_model = [];

    // Untuk model dengan beberapa kata, cocokkan frasanya secara utuh.
    foreach ($model_ke_merek as $merek => $daftar_model) {
        $kunci_merek = implode(' ', tokenPencarianShowroom($merek));
        foreach ($daftar_model as $model) {
            $token_model = tokenPencarianShowroom($model);
            $batas_awal = count($kata_kunci) - count($token_model);
            for ($awal = 0; $awal <= $batas_awal; $awal++) {
                $jarak_frasa = [];
                foreach ($token_model as $offset => $kata_model) {
                    $posisi = $awal + $offset;
                    $jarak = $jarak_kata($kata_kunci[$posisi], $kata_model);
                    if ($jarak < 0) {
                        continue 2;
                    }
                    $jarak_frasa[$posisi] = $jarak;
                }
                foreach ($jarak_frasa as $posisi => $jarak) {
                    $skor_model[$kunci_merek][$posisi] = min(
                        $skor_model[$kunci_merek][$posisi] ?? PHP_INT_MAX,
                        $jarak
                    );
                }
            }
        }
    }

    $hasil = [];
    $skor_terbaik = min(3, (int) ceil(strlen(implode('', $kata_kunci)) / 5));
    // Istilah umum boleh cocok persis, tetapi tidak dijadikan koreksi ejaan.
    $kata_umum = ['dealer', 'showroom', 'mobil', 'motor'];

    foreach ($baris as $row) {
        $nama = $row['namaShowroom']['value'] ?? '';
        $merek = $row['merek']['value'] ?? '';
        $lokasi = $row['lokasi']['value'] ?? '';
        $kategori = $row['kategori']['value'] ?? '';
        $alamat = $row['alamat']['value'] ?? '';
        $calon_kata = array_unique(tokenPencarianShowroom("$nama $merek $lokasi"));
        $cocok_persis = array_fill_keys(tokenPencarianShowroom("$kategori $alamat"), true);
        $kunci_merek = implode(' ', array_diff(tokenPencarianShowroom($merek), ['dealer']));
        if (in_array($kunci_merek, ['honda', 'honda wing'], true)) {
            $kunci_merek = 'honda ' . strtolower(trim($kategori));
        }

        $skor = 0;
        foreach ($kata_kunci as $posisi => $kata) {
            if (isset($cocok_persis[$kata]) || in_array($kata, $calon_kata, true)) {
                continue;
            }

            $jarak_terbaik = $skor_model[$kunci_merek][$posisi] ?? PHP_INT_MAX;
            foreach ($calon_kata as $calon) {
                if (in_array($calon, $kata_umum, true)) {
                    continue;
                }
                $jarak = $jarak_kata($kata, $calon);
                if ($jarak >= 0) {
                    $jarak_terbaik = min($jarak_terbaik, $jarak);
                }
            }

            if ($jarak_terbaik > $skor_terbaik - $skor) {
                continue 2;
            }
            $skor += $jarak_terbaik;
        }

        if ($skor < $skor_terbaik) {
            $hasil = [];
            $skor_terbaik = $skor;
        }
        $hasil[] = $row;
    }

    return [$hasil, $hasil !== [] && $skor_terbaik > 0];
}
