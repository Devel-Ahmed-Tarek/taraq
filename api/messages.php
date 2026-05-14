<?php
/**
 * Booking log API — reads/writes ../data/messages.json
 * Shared hosting: ensure the data/ folder is writable by the web server.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$dataFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'messages.json';
$dataDir = dirname($dataFile);

function clip(string $s, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    return strlen($s) > $max ? substr($s, 0, $max) : $s;
}

function read_messages(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    header('Cache-Control: no-store');
    echo json_encode(read_messages($dataFile), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    header('Access-Control-Allow-Origin: *');

    $maxBytes = 1_000_000;
    $body = file_get_contents('php://input');
    if ($body === false) {
        $body = '';
    }
    if (strlen($body) > $maxBytes) {
        http_response_code(413);
        echo json_encode(['error' => 'payload_too_large']);
        exit;
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json']);
        exit;
    }

    $patientName = trim((string) ($payload['patientName'] ?? ''));
    $phone = trim((string) ($payload['phone'] ?? ''));
    if ($patientName === '' || $phone === '') {
        http_response_code(400);
        echo json_encode(['error' => 'missing_fields']);
        exit;
    }

    $entry = [
        'id' => bin2hex(random_bytes(9)),
        'at' => gmdate('c'),
        'patientName' => clip($patientName, 200),
        'phone' => clip($phone, 50),
        'service' => clip(trim((string) ($payload['service'] ?? '')), 200),
        'consulto' => clip(trim((string) ($payload['consulto'] ?? '')), 200),
        'lang' => (($payload['lang'] ?? '') === 'ar') ? 'ar' : 'en',
    ];

    if (!is_dir($dataDir)) {
        if (!@mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            http_response_code(500);
            echo json_encode(['error' => 'mkdir_failed']);
            exit;
        }
    }

    $lockPath = $dataFile . '.lock';
    $lockFh = fopen($lockPath, 'cb');
    if ($lockFh === false) {
        http_response_code(500);
        echo json_encode(['error' => 'lock_open_failed']);
        exit;
    }
    if (!flock($lockFh, LOCK_EX)) {
        fclose($lockFh);
        http_response_code(500);
        echo json_encode(['error' => 'lock_failed']);
        exit;
    }

    try {
        $arr = read_messages($dataFile);
        $arr[] = $entry;
        $json = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            http_response_code(500);
            echo json_encode(['error' => 'encode_failed']);
            exit;
        }
        $tmp = $dataFile . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['error' => 'write_failed']);
            exit;
        }
        if (!rename($tmp, $dataFile)) {
            @unlink($tmp);
            http_response_code(500);
            echo json_encode(['error' => 'rename_failed']);
            exit;
        }
    } finally {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
    }

    http_response_code(201);
    echo json_encode(['ok' => true, 'id' => $entry['id']]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed']);
