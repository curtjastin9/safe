<?php
/**
 * check_suggestions.php — open: http://192.168.1.7/safetrade/check_suggestions.php
 * Shows exactly which Suggestions pieces are (or aren't) in your files.
 * Delete it when done.
 */
header('Content-Type: text/plain; charset=utf-8');
if (function_exists('opcache_reset')) { @opcache_reset(); echo "(opcache cleared)\n"; }

echo "This script is running from: " . __DIR__ . "\n\n";

$files = [
    'includes/suggestions.php' => ['sg_ensure', 'suggestions_render', 'suggestions_handle_post'],
    'admin/suggestions.php'    => ['render_admin_sidebar(\'suggestions\''],
    'dashboard.php'            => ['includes/suggestions.php', 'suggestions_handle_post(', 'suggestions_render(', 'sg-link', 'view=suggestions'],
    'admin/dashboard.php'      => ['sg-link', 'suggestions.php'],
    'admin/partials.php'       => ["'suggestions'", 'sg-link'],
];

foreach ($files as $rel => $needles) {
    $path = __DIR__ . '/' . $rel;
    echo $rel . "\n";
    if (!is_file($path)) { echo "   !! FILE NOT FOUND at $path\n\n"; continue; }
    $src = file_get_contents($path);
    echo "   modified: " . date('Y-m-d H:i:s', filemtime($path)) . "  size: " . strlen($src) . " bytes\n";
    foreach ($needles as $n) {
        echo '   ' . (strpos($src, $n) !== false ? '[OK]      ' : '[MISSING] ') . $n . "\n";
    }
    echo "\n";
}

echo "Any [MISSING] in dashboard.php / admin files = the patcher did not change that file.\n";
echo "Run patch_suggestions_v2.php from THIS same folder, then reload this page.\n";
