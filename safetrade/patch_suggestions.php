<?php
/**
 * patch_suggestions.php — one-shot patcher.
 * Put it in safetrade/ (next to dashboard.php) and open once:
 *   http://localhost/safetrade/patch_suggestions.php
 * It wires the Suggestions feature into the user dashboard, the admin
 * dashboard sidebar, and admin/partials.php (backups saved as .bak2).
 * DELETE THIS FILE afterwards.
 *
 * Before running, make sure these two files are in place:
 *   includes/suggestions.php   (the first file: sg_ensure / suggestions_render)
 *   admin/suggestions.php      (the second file: the admin page)
 */
header('Content-Type: text/plain; charset=utf-8');

foreach (['includes/suggestions.php', 'admin/suggestions.php', 'dashboard.php', 'admin/dashboard.php', 'admin/partials.php'] as $need) {
    if (!is_file(__DIR__ . '/' . $need)) exit("Missing $need\n");
}

$ICON = '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 00-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0012 3z"/>';
$log = [];

/* ---------------- 1. user dashboard.php ---------------- */
$f = __DIR__ . '/dashboard.php';
$s = file_get_contents($f);
if (strpos($s, 'includes/suggestions.php') !== false) {
    $log[] = 'dashboard.php: already patched';
} else {
    $ok = true;
    $a = "require_once __DIR__ . '/includes/group_logs.php';";
    if (substr_count($s, $a) === 1) $s = str_replace($a, $a . "\nrequire_once __DIR__ . '/includes/suggestions.php';", $s);
    else { $log[] = 'dashboard.php FAILED: group_logs require anchor'; $ok = false; }

    $a = 'switch ($action) {';
    if ($ok && substr_count($s, $a) === 1) $s = str_replace($a, "suggestions_handle_post(\$conn, \$userId, (string) \$action);\n\n    " . $a, $s);
    elseif ($ok) { $log[] = 'dashboard.php FAILED: switch anchor (' . substr_count($s, $a) . ')'; $ok = false; }

    $a = '<?= inbox_nav_link_user($view) ?>';
    if ($ok && substr_count($s, $a) === 1) $s = str_replace($a, $a . "\n      <?= nav_link('suggestions', 'Suggestions', \$view, '" . $ICON . "') ?>", $s);
    elseif ($ok) { $log[] = 'dashboard.php FAILED: sidebar anchor'; $ok = false; }

    $a = "<?php if (in_array(\$view, ['home', 'market'], true)): ?>";
    if ($ok && substr_count($s, $a) === 1) $s = str_replace($a, "<?php if (\$view === 'suggestions') { suggestions_render(\$conn, \$userId); } ?>\n\n    " . $a, $s);
    elseif ($ok) { $log[] = 'dashboard.php FAILED: view anchor'; $ok = false; }

    if ($ok) { copy($f, $f . '.bak2'); file_put_contents($f, $s); $log[] = 'dashboard.php: ok'; }
}

/* ---------------- 2. admin/dashboard.php sidebar ---------------- */
$f = __DIR__ . '/admin/dashboard.php';
$s = file_get_contents($f);
if (strpos($s, 'suggestions.php') !== false) {
    $log[] = 'admin/dashboard.php: already patched';
} elseif (preg_match('/^[^\n]*tab_link\(\'groups\'[^\n]*$/m', $s, $m)) {
    $link = '      <a class="admin-link" href="suggestions.php" title="Suggestions"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $ICON . '</svg><span>Suggestions</span></a>';
    $s = str_replace($m[0], $m[0] . "\n" . $link, $s);
    copy($f, $f . '.bak2'); file_put_contents($f, $s);
    $log[] = 'admin/dashboard.php: ok';
} else {
    $log[] = 'admin/dashboard.php FAILED: groups tab_link line not found';
}

/* ---------------- 3. admin/partials.php sidebar ---------------- */
$f = __DIR__ . '/admin/partials.php';
$s = file_get_contents($f);
if (strpos($s, "'suggestions'") !== false) {
    $log[] = 'admin/partials.php: already patched';
} elseif (preg_match('/^[^\n]*\'groups\'\s*=>\s*\[[^\n]*$/m', $s, $m)) {
    $entry = "        'suggestions'    => ['Suggestions',     'suggestions.php',     '" . $ICON . "'],";
    $s = str_replace($m[0], $m[0] . "\n" . $entry, $s);
    copy($f, $f . '.bak2'); file_put_contents($f, $s);
    $log[] = 'admin/partials.php: ok';
} else {
    $log[] = 'admin/partials.php FAILED: groups entry not found';
}

echo implode("\n", $log) . "\n\nHard refresh (Ctrl+F5) the dashboard and admin panel, then delete patch_suggestions.php.\n";
