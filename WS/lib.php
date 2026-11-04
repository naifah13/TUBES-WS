<?php
/*
=============================================================================
FILE KONFIGURASI WEB SEMANTIK (lib.php)
Tim: Bhita, Jepin, Fadsa, Kersa, Reynald
Fungsi: Koneksi ke SPARQL Endpoint (Native PHP)
=============================================================================
*/

// Nama dataset sudah diganti menjadi 'showroom'
// Jika 'localhost' masih error, ganti kata 'localhost' di bawah menjadi '127.0.0.1'
$sparql_endpoint = 'http://localhost:3030/showroom/query';

function executeSparqlQuery($query_string) {
    global $sparql_endpoint;
    
    // Membentuk URL untuk request ke Endpoint Fuseki
    $url = $sparql_endpoint . '?query=' . urlencode($query_string) . '&format=json';
    
    // Menarik data langsung pakai fungsi bawaan PHP
    $response = @file_get_contents($url);
    
    if ($response === FALSE) {
        return "Error: Tidak dapat terhubung ke SPARQL Endpoint. Pastikan Fuseki/Virtuoso sudah menyala.";
    }
    
    return json_decode($response, true);
}
?>