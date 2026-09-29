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

function ms_library_file(array $config): string
{
    $identity = $config['origin'] . '|' . $config['base_path'];

    return
        ms_account_root() .
        DIRECTORY_SEPARATOR .
        '.modernslides-library-' .
        substr(hash('sha256', $identity), 0, 16) .
        '.json';
}

function ms_default_library(): array
{
    return [
        'version' => 1,
        'courses' => [],
        'assignments' => []
    ];
}

function ms_normalize_library(mixed $value): array
{
    if (!is_array($value)) {
        return ms_default_library();
    }

    $courses = [];

    $inputCourses = isset($value['courses']) && is_array($value['courses']) ? $value['courses'] : [];
    foreach ($inputCourses as $course) {
        if (
            !is_array($course) ||
            !preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', (string)($course['id'] ?? ''))
        ) {
            continue;
        }

        $name = trim((string)($course['name'] ?? ''));

        if ($name === '') {
            continue;
        }

        $courses[] = [
            'id' => (string)$course['id'],
            'name' => substr($name, 0, 80),
            'templateDeck' => isset($course['templateDeck']) && $course['templateDeck'] !== ''
                ? (string)$course['templateDeck']
                : null
        ];
    }

    $assignments = [];

    $inputAssignments = isset($value['assignments']) && is_array($value['assignments']) ? $value['assignments'] : [];
    foreach ($inputAssignments as $deck => $courseId) {
        if (
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', (string)$deck) &&
            preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', (string)$courseId)
        ) {
            $assignments[(string)$deck] = (string)$courseId;
        }
    }

    return [
        'version' => 1,
        'courses' => $courses,
        'assignments' => $assignments
    ];
}

function ms_library_slug(string $name): string
{
    $source = strtolower(trim($name));
    $source = preg_replace('/[^a-z0-9]+/', '-', $source) ?? '';
    $source = trim($source, '-');

    return substr($source !== '' ? $source : 'course', 0, 54);
}

function ms_scan_decks(array $config): array
{
    $root = realpath(dirname(__DIR__));

    if ($root === false || !is_dir($root)) {
        ms_json_error(500, 'ModernSlides publishing directory is unavailable.');
    }

    $entries = scandir($root);

    if ($entries === false) {
        ms_json_error(500, 'Could not inspect published decks.');
    }

    $decks = [];
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    foreach ($entries as $name) {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $name)) {
            continue;
        }

        $directory = $root . DIRECTORY_SEPARATOR . $name;
        $marker = $directory . DIRECTORY_SEPARATOR . '.modernslides-managed';
        $file = $directory . DIRECTORY_SEPARATOR . $name . '.json';

        if (
            is_link($directory) || !is_dir($directory) ||
            is_link($marker) || !is_file($marker) ||
            is_link($file) || !is_file($file)
        ) {
            continue;
        }

        $realDirectory = realpath($directory);
        $realFile = realpath($file);

        if (
            $realDirectory === false || $realFile === false ||
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
            !isset($deck['slides']) || !is_array($deck['slides'])
        ) {
            continue;
        }

        $meta = isset($deck['meta']) && is_array($deck['meta']) ? $deck['meta'] : [];
        $title = trim((string)($meta['title'] ?? $name));
        $modified = filemtime($realFile);
        $encoded = rawurlencode($name);
        $basePath = rtrim($config['base_path'], '/');

        $decks[$name] = [
            'name' => $name,
            'title' => $title !== '' ? $title : $name,
            'theme' => (string)($meta['theme'] ?? ''),
            'slides' => count($deck['slides']),
            'updated' => $modified !== false ? gmdate(DATE_ATOM, $modified) : null,
            'path' => $name . '/' . $name . '.json',
            'viewUrl' => $config['origin'] . $basePath . '/index.html?deck=' . $encoded,
            'editUrl' => $config['origin'] . $basePath . '/index.html?deck=' . $encoded . '&edit=1'
        ];
    }

    uasort(
        $decks,
        static fn(array $left, array $right): int =>
            strcmp((string)($right['updated'] ?? ''), (string)($left['updated'] ?? ''))
    );

    return $decks;
}

function ms_read_request(): array
{
    $body = file_get_contents('php://input');

    if ($body === false || strlen($body) > 65536) {
        ms_json_error(400, 'Invalid instructor request.');
    }

    if (trim($body) === '') {
        return ['action' => 'list'];
    }

    try {
        $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        ms_json_error(400, 'Instructor request contains invalid JSON.');
    }

    return is_array($request) ? $request : ['action' => 'list'];
}

$request = ms_read_request();
$action = (string)($request['action'] ?? 'list');
$decks = ms_scan_decks($config);
$libraryFile = ms_library_file($config);
$handle = fopen($libraryFile, 'c+');

if ($handle === false || !flock($handle, LOCK_EX)) {
    ms_json_error(500, 'Could not open the instructor library.');
}

@chmod($libraryFile, 0600);

rewind($handle);
$stored = stream_get_contents($handle);
$library = ms_default_library();

if (is_string($stored) && trim($stored) !== '') {
    try {
        $library = ms_normalize_library(json_decode($stored, true, 64, JSON_THROW_ON_ERROR));
    } catch (JsonException $error) {
        $library = ms_default_library();
    }
}

$changed = false;
$courseIds = array_column($library['courses'], 'id');

if ($action === 'create_course') {
    $name = trim((string)($request['name'] ?? ''));

    if ($name === '' || strlen($name) > 80) {
        ms_json_error(400, 'Course name must contain 1 to 80 characters.');
    }

    $base = ms_library_slug($name);
    $id = $base;
    $suffix = 2;

    while (in_array($id, $courseIds, true)) {
        $id = substr($base, 0, 54) . '-' . $suffix;
        $suffix++;
    }

    $templateDeck = (string)($request['templateDeck'] ?? '');

    if ($templateDeck !== '' && !isset($decks[$templateDeck])) {
        ms_json_error(400, 'The selected template deck does not exist.');
    }

    $library['courses'][] = [
        'id' => $id,
        'name' => $name,
        'templateDeck' => $templateDeck !== '' ? $templateDeck : null
    ];
    $changed = true;

} elseif ($action === 'update_course') {
    $id = (string)($request['courseId'] ?? '');
    $index = array_search($id, $courseIds, true);

    if ($index === false) {
        ms_json_error(404, 'Course not found.');
    }

    if (array_key_exists('name', $request)) {
        $name = trim((string)$request['name']);
        if ($name === '' || strlen($name) > 80) ms_json_error(400, 'Course name must contain 1 to 80 characters.');
        $library['courses'][$index]['name'] = $name;
    }

    if (array_key_exists('templateDeck', $request)) {
        $templateDeck = (string)$request['templateDeck'];
        if ($templateDeck !== '' && !isset($decks[$templateDeck])) ms_json_error(400, 'The selected template deck does not exist.');
        $library['courses'][$index]['templateDeck'] = $templateDeck !== '' ? $templateDeck : null;
    }

    $changed = true;

} elseif ($action === 'assign_deck') {
    $deckName = (string)($request['deckName'] ?? '');
    $courseId = (string)($request['courseId'] ?? '');

    if (!isset($decks[$deckName])) {
        ms_json_error(404, 'Deck not found.');
    }

    if ($courseId === '') {
        unset($library['assignments'][$deckName]);
    } else {
        $index = array_search($courseId, $courseIds, true);
        if ($index === false) ms_json_error(404, 'Course not found.');
        $library['assignments'][$deckName] = $courseId;
        if (empty($library['courses'][$index]['templateDeck'])) {
            $library['courses'][$index]['templateDeck'] = $deckName;
        }
    }

    $changed = true;

} elseif ($action !== 'list') {
    ms_json_error(400, 'Unknown instructor action.');
}

if ($changed) {
    $encoded = json_encode($library, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false || !ftruncate($handle, 0) || rewind($handle) === false || fwrite($handle, $encoded . "\n") === false || !fflush($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        ms_json_error(500, 'Could not save the instructor library.');
    }

    @chmod($libraryFile, 0600);
}

flock($handle, LOCK_UN);
fclose($handle);

$validCourseIds = array_column($library['courses'], 'id');

foreach ($library['assignments'] as $deckName => $courseId) {
    if (!isset($decks[$deckName]) || !in_array($courseId, $validCourseIds, true)) {
        unset($library['assignments'][$deckName]);
    }
}

foreach ($decks as $name => &$deck) {
    $deck['courseId'] = $library['assignments'][$name] ?? null;
}
unset($deck);

header('Content-Type: application/json; charset=utf-8');

echo json_encode(
    [
        'ok' => true,
        'courses' => array_values($library['courses']),
        'decks' => array_values($decks)
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
