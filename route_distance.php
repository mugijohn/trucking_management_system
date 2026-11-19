<?php
require 'config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    http_response_code(419);
    echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$pickup = trim((string)($input['pickup'] ?? ''));
$delivery = trim((string)($input['delivery'] ?? ''));
if ($pickup === '' || $delivery === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Enter both pickup and delivery locations.']);
    exit;
}
if (strlen($pickup) > 250 || strlen($delivery) > 250) {
    http_response_code(422);
    echo json_encode(['error' => 'Locations must be 250 characters or fewer.']);
    exit;
}

function route_http_get(string $url): array {
    $userAgent = 'FleetFlowLoadDistance/1.0 (load distance lookup)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('Distance service connection failed: ' . $error);
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "User-Agent: {$userAgent}\r\nAccept: application/json\r\n"
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) $status = (int)$m[1];
        }
        if ($body === false) throw new RuntimeException('Distance service connection failed.');
    }
    if ($status < 200 || $status >= 300) throw new RuntimeException('The mapping service is temporarily unavailable. Please try again or enter miles manually.');
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) throw new RuntimeException('The mapping service returned an invalid response.');
    return $decoded;
}

try {
    $geocode = static function (string $address): array {
        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode($address);
        $results = route_http_get($url);
        if (empty($results[0]['lat']) || empty($results[0]['lon'])) {
            throw new RuntimeException('Could not find this location: ' . $address . '. Add the city, state, or ZIP/postal code.');
        }
        return [(float)$results[0]['lon'], (float)$results[0]['lat']];
    };

    $from = $geocode($pickup);
    // Respect the public Nominatim service's one-request-per-second usage policy.
    usleep(1100000);
    $to = $geocode($delivery);

    $routeUrl = 'https://router.project-osrm.org/route/v1/driving/' .
        $from[0] . ',' . $from[1] . ';' . $to[0] . ',' . $to[1] . '?overview=false&steps=false';
    $route = route_http_get($routeUrl);
    if (($route['code'] ?? '') !== 'Ok' || empty($route['routes'][0]['distance'])) {
        throw new RuntimeException('A driving route could not be found for these locations. Please check the addresses or enter miles manually.');
    }

    $meters = (float)$route['routes'][0]['distance'];
    echo json_encode([
        'miles' => round($meters / 1609.344, 1),
        'distance_meters' => $meters,
        'pickup' => $pickup,
        'delivery' => $delivery
    ]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
}
