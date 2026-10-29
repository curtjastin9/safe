<?php
/**
 * Brand mark. Now renders the SafeTrade logo image (img/logo1.png)
 * instead of the old star seal, everywhere seal_svg() is used.
 */
function seal_svg(int $size = 30, string $ring = '', string $star = ''): string
{
    $src = rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/img/logo1.png';
    return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="SafeTrade" width="' . $size . '" height="' . $size . '" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:contain;display:block;">';
}

function render_flashes(): void
{
    foreach (flash_get_all() as $f) {
        $type = $f['type'] === 'error' ? 'error' : 'success';
        echo '<div class="alert ' . $type . '">' . e($f['message']) . '</div>';
    }
}
