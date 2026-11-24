<?php
/*
=============================================================================
KONFIGURASI PETA & RUTE (config-maps.php)

Isi 'google_maps_api_key' dengan API key Google Maps MILIK KAMU SENDIRI.
Aktifkan 3 API ini di Google Cloud Console untuk key tersebut:
  1. Maps JavaScript API
  2. Routes API
  3. Places API (New)

Batasi key dengan "Website restrictions" (HTTP referrer), misalnya:
  http://localhost:8000/*

Kalau dibiarkan kosong, halaman detail otomatis memakai mode gratis
(peta OpenStreetMap + estimasi waktu lalu lintas kota).
=============================================================================
*/

return [
    'google_maps_api_key' => '',

    // Opsional: Map ID dari Google Cloud (Map Management). Kosongkan saja kalau belum punya.
    'google_map_id' => '',
];
