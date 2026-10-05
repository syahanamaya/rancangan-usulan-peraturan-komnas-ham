<?php
declare(strict_types=1);

$manifestPath = __DIR__ . '/frontend/dist/.vite/manifest.json';

if (!is_file($manifestPath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Frontend React belum dibangun. Jalankan `npm install` lalu `npm run build`.";
    exit;
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
$entry = $manifest['index.html'] ?? null;

if (!is_array($entry) || empty($entry['file'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Manifest frontend tidak valid. Jalankan ulang `npm run build`.';
    exit;
}

?><!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102d28">
    <meta name="description" content="Sistem pemantauan usulan rancangan peraturan Komnas HAM">
    <title>Ruang Regulasi — Komnas HAM</title>
    <link rel="icon" type="image/png" href="Logo_Komnas_HAM.png">
    <?php foreach (($entry['css'] ?? []) as $stylesheet): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars('frontend/dist/' . $stylesheet, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
</head>
<body>
    <div id="root"></div>
    <script type="module" src="<?= htmlspecialchars('frontend/dist/' . $entry['file'], ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
