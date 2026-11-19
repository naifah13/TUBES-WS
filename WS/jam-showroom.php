<?php

const LABEL_STATUS_SHOWROOM = [
    'buka' => 'Buka',
    'segera-tutup' => 'Segera tutup',
    'tutup' => 'Tutup',
    'tidak-diketahui' => 'Jam belum pasti',
];

function menitWibShowroom(): int
{
    $waktu = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    return (int) $waktu->format('G') * 60 + (int) $waktu->format('i');
}

function jadwalHarianShowroom(string $jam): array
{
    $jam = trim($jam);
    if (preg_match('/^(?:buka\s+)?24\s*jam$/iu', $jam)) {
        return [0 => 'buka'];
    }
    if (preg_match('/^(?:tutup|tutup permanen)$/iu', $jam)) {
        return [0 => 'tutup'];
    }

    // Data menyimpan rentang harian, misalnya 08.00-17.00 atau 08:00 – 17:00.
    if (!preg_match('/^(\d{1,2})[.:]([0-5]\d)\s*[-–—]\s*(\d{1,2})[.:]([0-5]\d)$/u', $jam, $angka)) {
        return [0 => 'tidak-diketahui'];
    }

    $buka = (int) $angka[1] * 60 + (int) $angka[2];
    $tutup = (int) $angka[3] * 60 + (int) $angka[4];
    if ($buka >= 1440 || $tutup > 1440 || $buka === $tutup) {
        return [0 => 'tidak-diketahui'];
    }

    $durasi = $tutup > $buka ? $tutup - $buka : $tutup + 1440 - $buka;
    if ($durasi === 1440) {
        return [0 => 'buka'];
    }

    // Simpan perubahan status menurut menit WIB sejak tengah malam.
    $status = [$buka => $durasi <= 30 ? 'segera-tutup' : 'buka'];
    if ($durasi > 30) {
        $status[($tutup + 1440 - 30) % 1440] = 'segera-tutup';
    }
    $status[$tutup % 1440] = 'tutup';
    ksort($status, SORT_NUMERIC);

    // Untuk jam yang melewati tengah malam, lanjutkan status dari hari sebelumnya.
    if (!isset($status[0])) {
        $status = [0 => end($status)] + $status;
    }

    return $status;
}

function statusShowroomPadaMenit(array $jadwal, int $menit): string
{
    $status = $jadwal[0];
    foreach ($jadwal as $awal => $status_baru) {
        if ($awal > $menit) {
            break;
        }
        $status = $status_baru;
    }
    return $status;
}
