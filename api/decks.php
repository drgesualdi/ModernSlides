<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

ms_security_headers();
ms_require_https();
ms_start_session($config);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ms_json_error(405, 'POST required.');
}

ms_require_same_origin($config);
ms_require_authenticated($config);

if (($_SERVER['HTTP_X_MODERNSLIDES_INSTRUCTOR'] ?? '') !== '1') {
    ms_json_error(403, 'Missing ModernSlides instructor header.');
}

$root = realpath(dirname(__DIR__));

if ($root === false || !is_dir($root)) {
    ms_json_error(500, 'ModernSlides publishing directory is unavailable.');
}

$entries = scandir($root);

if ($entries === false) {
    ms_json_error(500, 'Could not inspect published decks.');
}

$decks = [];

foreach ($entries as $name) {
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $name)) {
        continue;
    }

    $directory = $root . DIRECTORY_SEPARATOR . $name;
    $marker = $directory . DIRECTORY_SEPARATOR . '.modernslides-managed';
    $file = $directory . DIRECTORY_SEPARATOR . $name . '.json';

    if (
        is_link($directory) ||
        !is_dir($directory) ||
        is_link($marker) ||
        !is_file($marker) ||
        is_link($file) ||
        !is_file($file)
    ) {
        continue;
    }

    $realDirectory = realpath($directory);
    $realFile = realpath($file);
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (
        $realDirectory === false ||
        $realFile === false ||
        !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, $rootPrefix) ||
        !str_starts_with($realFile, $realDirectory . DIRECTORY_SEPARATOR)
    ) {
        continue;
    }

    $size = filesize($realFile);

    if ($size === false || $size < 2 || $size > (int)$config['max_bytes']) {
        continue;
    }

    $body = file_get_contents($realFile);

    if ($body === false) {
        continue;
    }

    try {
        $deck = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        continue;
    }

    if (
        !is_array($deck) ||
        ($deck['format'] ?? null) !== 'modernslides' ||
        ($deck['version'] ?? null) !== 2 ||
        !isset($deck['slides']) ||
        !is_array($deck['slides'])
    ) {
        continue;
    }

    $meta = isset($deck['meta']) && is_array($deck['meta']) ? $deck['meta'] : [];
    $title = trim((string)($meta['title'] ?? $name));
    $modified = filemtime($realFile);
    $encoded = rawurlencode($name);
    $basePath = rtrim($config['base_path'], '/');

    $decks[] = [
        'name' => $name,
        'title' => $title !== '' ? $title : $name,
        'slides' => count($deck['slides']),
        'updated' => $modified !== false ? gmdate(DATE_ATOM, $modified) : null,
        'path' => $name . '/' . $name . '.json',
        'view_url' => $config['origin'] . $basePath . '/index.html?deck=' . $encoded
    ];
}

usort(
    $decks,
    static fn(array $left, array $right): int =>
        strcmp((string)($right['updated'] ?? ''), (string)($left['updated'] ?? ''))
);

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'ok' => true,
        'decks' => $decks
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
