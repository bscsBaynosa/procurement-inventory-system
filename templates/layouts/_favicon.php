<?php
$root = realpath(__DIR__ . '/../../');
$candidates = array(
    $root . DIRECTORY_SEPARATOR . 'logo.png',
    $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'logo.png',
    $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logo.png',
);
$printed = false;
foreach ($candidates as $cand) {
    if (is_file($cand)) {
        $data = @file_get_contents($cand);
        if ($data !== false) {
            $href = 'data:image/png;base64,' . base64_encode($data);
            echo '<link rel="icon" type="image/png" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
            echo '<link rel="apple-touch-icon" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
            $printed = true;
            break;
        }
    }
}
if (!$printed) {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
         . '<rect width="64" height="64" rx="12" ry="12" fill="#ffffff"/>'
         . '<rect x="28" y="12" width="8" height="40" rx="3" fill="#22c55e"/>'
         . '<rect x="12" y="28" width="40" height="8" rx="3" fill="#22c55e"/>'
         . '</svg>';
    $href = 'data:image/svg+xml;base64,' . base64_encode($svg);
    echo '<link rel="icon" type="image/svg+xml" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
    echo '<link rel="apple-touch-icon" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
}
