<?php

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$query_peta = <<<'SPARQL'
    PREFIX ex: <http://www.example.org/showroom#>
    PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>

    SELECT ?showroom ?nama ?kategori ?lokasi ?alamat ?lat ?long
    WHERE {
        ?showroom a ex:Showroom ;
                  ex:namaShowroom ?nama ;
                  geo:lat ?lat ;
                  geo:long ?long .
        OPTIONAL { ?showroom ex:kategori ?kategori . }
        OPTIONAL { ?showroom ex:berlokasiDi ?lokasi . }
        OPTIONAL { ?showroom ex:alamat ?alamat . }
    }
    ORDER BY LCASE(STR(?nama)) ?showroom ?lat ?long
    SPARQL;

$data = executeSparqlQuery($query_peta);
$baris = $data['results']['bindings'] ?? null;

if (!is_array($baris)) {
    http_response_code(502);
    echo json_encode(['error' => 'Data lokasi showroom belum bisa diambil dari Fuseki.']);
    exit;
}

$titik = [];
$koordinat_ganda = [];
foreach ($baris as $row) {
    $uri = $row['showroom']['value'] ?? '';
    $lat = $row['lat']['value'] ?? null;
    $long = $row['long']['value'] ?? null;

    if ($uri === '' || !is_numeric($lat) || !is_numeric($long)) {
        continue;
    }

    $lat = (float) $lat;
    $long = (float) $long;
    if (!is_finite($lat) || !is_finite($long) || abs($lat) > 90 || abs($long) > 180) {
        continue;
    }

    // Lewati lokasi yang punya lebih dari satu pasangan koordinat berbeda.
    if (isset($titik[$uri])) {
        if ($titik[$uri]['lat'] !== $lat || $titik[$uri]['long'] !== $long) {
            $koordinat_ganda[$uri] = true;
        }
        continue;
    }

    $titik[$uri] = [
        'uri' => $uri,
        'nama' => $row['nama']['value'] ?? '',
        'kategori' => strtolower(trim($row['kategori']['value'] ?? '')),
        'lokasi' => $row['lokasi']['value'] ?? '',
        'alamat' => $row['alamat']['value'] ?? '',
        'lat' => $lat,
        'long' => $long,
    ];
}

echo json_encode(
    ['showrooms' => array_values(array_diff_key($titik, $koordinat_ganda))],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
