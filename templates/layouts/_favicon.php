<?php
// Output favicon and apple-touch-icon links using repo root logo.png when available
$root = realpath(__DIR__ . '/../../');
$candidates = [
    $root . DIRECTORY_SEPARATOR . 'logo.png',
    $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'logo.png',
    $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logo.png',
];
foreach ($candidates as $cand) {
    if (is_file($cand)) {
        $data = @file_get_contents($cand);
        if ($data !== false) {
            $href = 'data:image/png;base64,' . base64_encode($data);
            echo '<link rel="icon" type="image/png" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
            echo '<link rel="apple-touch-icon" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
            break;
        }
    }
}
