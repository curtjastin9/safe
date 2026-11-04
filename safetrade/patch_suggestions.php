<?php
/**
 * patch_suggestions.php — one-shot patcher. Open it once in the browser:
 *   http://localhost/safetrade/patch_suggestions.php
 * Backs up dashboard.php to dashboard.php.bak2, adds the "Suggestions &
 * Feedback" sidebar link (below My Profile), the page, and the POST handler.
 * DELETE THIS FILE afterwards.
 */
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/dashboard.php';
if (!is_file($file)) { exit("dashboard.php not found next to this script.\n"); }
if (!is_file(__DIR__ . '/includes/suggestions.php')) { exit("Missing includes/suggestions.php — copy it into includes/ first.\n"); }

$src = file_get_contents($file);
if (strpos($src, 'suggestions.php') !== false) { exit("Already patched — nothing to do.\n"); }

$log = [];
$ok  = true;

function once(string $src, string $anchor): bool { return substr_count($src, $anchor) === 1; }

/* 1. require the include */
$a = "require_once __DIR__ . '/includes/group_logs.php';";
if (once($src, $a)) {
    $src = str_replace($a, $a . "\nrequire_once __DIR__ . '/includes/suggestions.php';", $src);
    $log[] = 'ok      1 require include';
} else { $log[] = 'FAILED  1 require anchor (group_logs require not found exactly once)'; $ok = false; }

/* 2. POST handler, right before the action switch */
$a = '    switch ($action) {';
if (once($src, $a)) {
    $src = str_replace($a, "    suggestions_handle_post(\$conn, \$userId, \$action);\n\n" . $a, $src);
    $log[] = 'ok      2 POST handler';
} else { $log[] = 'FAILED  2 switch anchor (found ' . substr_count($src, $a) . ' times)'; $ok = false; }

/* 3. sidebar link below "My Profile" */
$icon = '<path d="M21 15a2 2 0 01-2 2H8l-5 4V5a2 2 0 012-2h14a2 2 0 012 2z"/><path d="M12 7v5M12 15h.01"/>';
if (preg_match("/^[ \\t]*<\\?= nav_link\\('profile'.*\\?>[ \\t]*\\R/m", $src, $m, PREG_OFFSET_CAPTURE) && substr_count($src, "nav_link('profile'") === 1) {
    $end  = $m[0][1] + strlen($m[0][0]);
    $line = "      <?= nav_link('suggestions', 'Suggestions & Feedback', \$view, '" . $icon . "') ?>\n";
    $src  = substr($src, 0, $end) . $line . substr($src, $end);
    $log[] = 'ok      3 sidebar link';
} else { $log[] = 'FAILED  3 nav_link profile line not found'; $ok = false; }

/* 4. the page itself, right after the maintenance banner */
$a = "<?= inbox_maintenance_banner('user') ?>";
if (once($src, $a)) {
    $src = str_replace($a, $a . "\n\n    <?php if (\$view === 'suggestions') { suggestions_render(\$conn, \$userId); } ?>", $src);
    $log[] = 'ok      4 page render';
} else { $log[] = 'FAILED  4 banner anchor'; $ok = false; }

echo implode("\n", $log) . "\n\n";

if (!$ok) { echo "Nothing was written — dashboard.php is unchanged. Send me the FAILED lines.\n"; exit; }

copy($file, $file . '.bak2');
file_put_contents($file, $src);
echo "Done. Backup saved as dashboard.php.bak2.\nNow delete patch_suggestions.php.\n";
