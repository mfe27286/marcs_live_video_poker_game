<?php
// Shared HTML head/footer for pages
function page_head(string $title, array $extraScripts = []): void {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23F59E0B'%3E%3Cpath d='M12 2C9 7 4 9 4 13.5A4.5 4.5 0 0 0 11 17v3h2v-3a4.5 4.5 0 0 0 7-3.5C20 9 15 7 12 2z'/%3E%3C/svg%3E">
<title>{$title} · Royal Hold'em</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<script>window.BASE_PATH = location.pathname.replace(/\/[^/]*$/, "");</script>
</head>
<body class="bg-noise">
HTML;
}

function page_foot(array $scripts): void {
    echo '<div id="toasts" class="toasts" aria-live="polite"></div>';
    echo '<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>';
    echo "<script src=\"assets/js/api.js\"></script>";
    echo "<script src=\"assets/js/ui.js\"></script>";
    foreach ($scripts as $s) echo "<script src=\"assets/js/{$s}\"></script>";
    echo '</body></html>';
}
