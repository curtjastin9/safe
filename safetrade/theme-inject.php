<?php
/* Auto-loads assets/css/theme.css + assets/css/marketplace-ui.css into every HTML page (user dashboard, admin, auth).
   Wired in via .htaccess (auto_prepend_file). No other file needs editing. */
$__skip = ['image.php','browse.php','chat-api.php','face-verify-api.php','mini-profile-api.php','lockout-status.php','update-location.php','ip-location.php'];
if (PHP_SAPI !== 'cli' && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $__skip, true)) {
    $__root = rtrim(str_replace('\\', '/', (string) substr(str_replace('\\', '/', __DIR__), strlen(rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/')))), '/');
    $__links = '';
    foreach (['theme.css', 'marketplace-ui.css'] as $__css) {
        $__file = __DIR__ . '/assets/css/' . $__css;
        $__links .= '<link rel="stylesheet" href="' . $__root . '/assets/css/' . $__css . '?v=' . (is_file($__file) ? filemtime($__file) : 1) . '">';
    }
    ob_start(function (string $buf) use ($__links): string {
        if (stripos($buf, '</head>') === false || stripos($buf, 'assets/css/marketplace-ui.css') !== false) return $buf;
        // Drop any previously injected/hard-coded theme.css link so order is theme -> marketplace-ui.
        $buf = preg_replace('~<link[^>]+assets/css/theme\.css[^>]*>~i', '', $buf);
        return preg_replace('~</head>~i', $__links . '</head>', $buf, 1);
    });
}
