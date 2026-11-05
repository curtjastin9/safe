<?php
/**
 * patch_suggestions_v2.php — run once:  http://localhost/safetrade/patch_suggestions_v2.php
 * Puts "Suggestions" at the BOTTOM of the sidebar (below My Profile / below the
 * last admin link) with its own purple colour, for both user and admin.
 * Safe to run even if patch_suggestions.php (v1) already ran: it removes the
 * v1 link first. Backups are saved as .bak3. DELETE THIS FILE afterwards.
 */
header('Content-Type: text/plain; charset=utf-8');

foreach (['includes/suggestions.php', 'admin/suggestions.php', 'dashboard.php', 'admin/dashboard.php', 'admin/partials.php'] as $need) {
    if (!is_file(__DIR__ . '/' . $need)) exit("Missing $need (put the suggestion files in place first)\n");
}

$ICON = '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 00-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0012 3z"/>';
$log  = [];

$USER_BLOCK = <<<'HTML'

      <!--SG-LINK-->
      <a class="side-link sg-link<?= $view === 'suggestions' ? ' active' : '' ?>" href="?view=suggestions"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">@@ICON@@</svg><span>Suggestions</span></a>
      <style>
      body .side-nav .side-link.sg-link{background:linear-gradient(135deg,#7B5CFF,#3F7CF4) !important;color:#fff !important;margin-top:10px;box-shadow:0 6px 16px rgba(91,63,208,.45);}
      body .side-nav .side-link.sg-link:hover{filter:brightness(1.1);transform:translateX(1px);}
      body .side-nav .side-link.sg-link svg{background:#fff !important;color:#5B3FD0 !important;}
      body .side-nav .side-link.sg-link.active{box-shadow:0 0 0 2px #fff,0 6px 16px rgba(91,63,208,.55);}
      body .side-nav .side-link.sg-link.active::before{display:none;}
      </style>
      <!--/SG-LINK-->
HTML;
$USER_BLOCK = str_replace('@@ICON@@', $ICON, $USER_BLOCK);

$ADMIN_BLOCK = <<<'HTML'

      <!--SG-LINK-->
      <a class="admin-link sg-link" href="suggestions.php" title="Suggestions"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">@@ICON@@</svg><span>Suggestions</span></a>
      <style>
      body .admin-nav .admin-link.sg-link{background:linear-gradient(135deg,#7B5CFF,#3F7CF4) !important;color:#fff !important;margin-top:10px;box-shadow:0 6px 16px rgba(91,63,208,.45);}
      body .admin-nav .admin-link.sg-link:hover{filter:brightness(1.1);}
      body .admin-nav .admin-link.sg-link svg{background:#fff !important;color:#5B3FD0 !important;}
      </style>
      <!--/SG-LINK-->
HTML;
$ADMIN_BLOCK = str_replace('@@ICON@@', $ICON, $ADMIN_BLOCK);

function strip_old(string $s): string
{
    $s = preg_replace('/\s*<!--SG-LINK-->.*?<!--\/SG-LINK-->/s', '', $s);          // v2 blocks
    $s = preg_replace('/^[^\n]*nav_link\(\'suggestions\'[^\n]*\n?/m', '', $s);      // v1 user link
    $s = preg_replace('/^[^\n]*<a class="admin-link" href="suggestions\.php"[^\n]*\n?/m', '', $s); // v1 admin link
    return $s;
}

/* ---------------- user dashboard.php ---------------- */
$f = __DIR__ . '/dashboard.php';
$s = strip_old(file_get_contents($f));
$ok = true;

if (strpos($s, 'includes/suggestions.php') === false) {
    $a = "require_once __DIR__ . '/includes/group_logs.php';";
    if (substr_count($s, $a) === 1) $s = str_replace($a, $a . "\nrequire_once __DIR__ . '/includes/suggestions.php';", $s);
    else { $log[] = 'dashboard.php FAILED: require anchor'; $ok = false; }
}
if ($ok && strpos($s, 'suggestions_handle_post(') === false) {
    $a = 'switch ($action) {';
    if (substr_count($s, $a) === 1) $s = str_replace($a, "suggestions_handle_post(\$conn, \$userId, (string) \$action);\n\n    " . $a, $s);
    else { $log[] = 'dashboard.php FAILED: switch anchor (' . substr_count($s, $a) . ')'; $ok = false; }
}
if ($ok && strpos($s, 'suggestions_render(') === false) {
    $a = "<?php if (in_array(\$view, ['home', 'market'], true)): ?>";
    if (substr_count($s, $a) === 1) $s = str_replace($a, "<?php if (\$view === 'suggestions') { suggestions_render(\$conn, \$userId); } ?>\n\n    " . $a, $s);
    else { $log[] = 'dashboard.php FAILED: view anchor'; $ok = false; }
}
if ($ok) {
    // below "My Profile"
    if (preg_match('/^[^\n]*nav_link\(\'profile\'[^\n]*$/m', $s, $m)) {
        $s = str_replace($m[0], $m[0] . $USER_BLOCK, $s);
    } else { $log[] = 'dashboard.php FAILED: My Profile link line not found'; $ok = false; }
}
if ($ok) { copy($f, $f . '.bak3'); file_put_contents($f, $s); $log[] = 'dashboard.php: ok'; }

/* ---------------- admin/dashboard.php ---------------- */
$f = __DIR__ . '/admin/dashboard.php';
$s = strip_old(file_get_contents($f));
// below the LAST sidebar link (System Analytics)
if (preg_match('/^[^\n]*tab_link\(\'sys_analytics\'[^\n]*$/m', $s, $m)) {
    $s = str_replace($m[0], $m[0] . $ADMIN_BLOCK, $s);
    copy($f, $f . '.bak3'); file_put_contents($f, $s);
    $log[] = 'admin/dashboard.php: ok';
} else {
    $log[] = 'admin/dashboard.php FAILED: sys_analytics link line not found';
}

/* ---------------- admin/partials.php ---------------- */
$f = __DIR__ . '/admin/partials.php';
$s = file_get_contents($f);
$s = preg_replace('/^[^\n]*\'suggestions\'\s*=>[^\n]*\n?/m', '', $s);   // remove v1 entry
$s = str_replace(" . (\$key === 'suggestions' ? ' sg-link' : '')", '', $s);
$s = preg_replace('/echo \'<style>\/\*SG\*\/.*?<\/style>\';\s*/s', '', $s);
$ok = true;

if (preg_match('/^[^\n]*\'manage-admins\'\s*=>\s*\[[^\n]*$/m', $s, $m)) {
    $s = str_replace($m[0], $m[0] . "\n        'suggestions'    => ['Suggestions',     'suggestions.php',     '" . $ICON . "'],", $s);
} else { $log[] = 'admin/partials.php FAILED: manage-admins entry not found'; $ok = false; }

$cls = "class=\"admin-link' . \$active . '\"";
if ($ok && substr_count($s, $cls) === 1) {
    $s = str_replace($cls, "class=\"admin-link' . \$active . (\$key === 'suggestions' ? ' sg-link' : '') . '\"", $s);
} elseif ($ok) { $log[] = 'admin/partials.php FAILED: link class anchor (' . substr_count($s, $cls) . ')'; $ok = false; }

$a = "echo '<aside class=\"admin-side\">';";
if ($ok && substr_count($s, $a) === 1) {
    $css = "echo '<style>/*SG*/body .admin-nav .admin-link.sg-link{background:linear-gradient(135deg,#7B5CFF,#3F7CF4) !important;color:#fff !important;margin-top:10px;box-shadow:0 6px 16px rgba(91,63,208,.45);}body .admin-nav .admin-link.sg-link:hover{filter:brightness(1.1);}body .admin-nav .admin-link.sg-link svg{background:#fff !important;color:#5B3FD0 !important;}</style>';\n    ";
    $s = str_replace($a, $css . $a, $s);
} elseif ($ok) { $log[] = 'admin/partials.php FAILED: aside anchor'; $ok = false; }

if ($ok) { copy($f, $f . '.bak3'); file_put_contents($f, $s); $log[] = 'admin/partials.php: ok'; }

echo implode("\n", $log) . "\n\nIf every line says ok: press Ctrl+F5 on the dashboard and admin pages, then delete this file.\nIf something says FAILED, copy that line to me.\n";
