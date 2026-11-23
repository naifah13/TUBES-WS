<?php

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responsRute(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responsRute(405, ['code' => 'MethodNotAllowed']);
}

$jenis = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($jenis !== 'application/json') {
    responsRute(415, ['code' => 'UnsupportedMediaType']);
}

function koordinatRute(mixed $lat, mixed $lng): bool
{
    return (is_int($lat) || is_float($lat))
        && (is_int($lng) || is_float($lng))
        && abs($lat) <= 90 && abs($lng) <= 180;
}

$input = json_decode(file_get_contents('php://input', false, null, 0, 2048), true);
$asal = $input['asal'] ?? [];
$tujuan = $input['tujuan'] ?? [];

foreach ([$asal, $tujuan] as $titik) {
    if (!is_array($titik) || !koordinatRute($titik[0] ?? null, $titik[1] ?? null)) {
        responsRute(400, ['code' => 'InvalidCoordinates']);
    }
}

$key = trim((string) getenv('TOMTOM_API_KEY'));
if ($key === '' || !function_exists('curl_init')) {
    responsRute(503, ['code' => 'NotConfigured']);
}

$lokasi = sprintf('%.6F,%.6F:%.6F,%.6F', $asal[0], $asal[1], $tujuan[0], $tujuan[1]);
$query = http_build_query([
    'key' => $key,
    'travelMode' => 'car',
    'routeType' => 'fastest',
    'traffic' => 'true',
    'departAt' => 'now',
    'routeRepresentation' => 'polyline',
], '', '&', PHP_QUERY_RFC3986);

$curl = curl_init('https://api.tomtom.com/routing/1/calculateRoute/' . $lokasi . '/json?' . $query);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 9,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

$data = is_string($body) ? json_decode($body, true) : null;
$kode = $data['detailedError']['code'] ?? '';
if (in_array($kode, ['NO_ROUTE_FOUND', 'MAP_MATCHING_FAILURE'], true)) {
    responsRute(404, ['code' => 'NoRoute']);
}
if ($status === 401 || $status === 403) {
    responsRute(503, ['code' => 'NotConfigured']);
}
if ($status !== 200) {
    responsRute(502, ['code' => 'TrafficUnavailable']);
}

$rute = $data['routes'][0] ?? [];
$jarak = $rute['summary']['lengthInMeters'] ?? null;
$durasi = $rute['summary']['travelTimeInSeconds'] ?? null;
$titikRute = $rute['legs'][0]['points'] ?? [];

if (!is_array($titikRute)) {
    responsRute(502, ['code' => 'TrafficUnavailable']);
}

$garis = [];
foreach ($titikRute as $titik) {
    $lat = $titik['latitude'] ?? null;
    $lng = $titik['longitude'] ?? null;
    if (!koordinatRute($lat, $lng)) {
        responsRute(502, ['code' => 'TrafficUnavailable']);
    }
    $garis[] = [$lng, $lat];
}

if (!is_numeric($jarak) || $jarak < 0 || !is_numeric($durasi) || $durasi < 0 || count($garis) < 2) {
    responsRute(502, ['code' => 'TrafficUnavailable']);
}

responsRute(200, ['code' => 'Ok', 'routes' => [[
    'distance' => (float) $jarak,
    'duration' => (float) $durasi,
    'geometry' => ['type' => 'LineString', 'coordinates' => $garis],
]]]);