<?php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/' || $uri === '') {
    readfile(__DIR__ . '/templates/main.html');
    exit;
}

$apiRoutes = ['/api/parse', '/api/sessions', '/api/load-session'];
if (in_array($uri, $apiRoutes, true)) {
    header('Content-Type: application/json');
    require __DIR__ . '/../src/index.php';
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not Found']);
