<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * SafeTrade Inbox
 *
 * One inbox per account (buyers, sellers, admins, managers):
 *   - Transaction notifications (interest, messages, meet-up proposed /
 *     accepted / declined, item received, sale completed, quantity).
 *   - Account notifications (verified, banned, listing removed...).
 *   - Announcements, product updates and maintenance notices that an
 *     admin broadcasts to everybody.
 *
 * Tables are created automatically the first time anything touches
 * them (CREATE TABLE IF NOT EXISTS), and NO foreign keys are used, so
 * deleting users/products can never be blocked by inbox rows.
 *
 * Every helper here is wrapped in try/catch: an inbox problem must
 * never break a trade, a login or an admin action.
 * ---------------------------------------------------------------------
 */

function inbox_ensure(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $c = db();
        $c->query("CREATE TABLE IF NOT EXISTS inbox_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            category VARCHAR(24) NOT NULL DEFAULT 'trade',
            type VARCHAR(48) NOT NULL DEFAULT '',
            title VARCHAR(190) NOT NULL,
            body TEXT NULL,
            link VARCHAR(255) NULL,
            ref_id INT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_feed (user_id, is_read, created_at),
            KEY idx_user_cat (user_id, category),
            KEY idx_ref (type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $c->query("CREATE TABLE IF NOT EXISTS announcements (
            id INT NOT NULL AUTO_INCREMENT,
            author_id INT NULL,
            category VARCHAR(24) NOT NULL DEFAULT 'announcement',
            title VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            audience VARCHAR(12) NOT NULL DEFAULT 'users',
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            recipients INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_ensure failed: ' . $e->getMessage());
    }
}

/* ===================== Writing ===================== */

function inbox_push(int $userId, string $category, string $title, string $body = '', ?string $link = null, ?int $refId = null, string $type = '', bool $alreadyRead = false): void
{
    if ($userId <= 0) return;
    try {
        inbox_ensure();
        $title = mb_substr($title, 0, 190);
        $read  = $alreadyRead ? 1 : 0;
        $stmt  = db()->prepare('INSERT INTO inbox_messages (user_id, category, type, title, body, link, ref_id, is_read) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->bind_param('isssssii', $userId, $category, $type, $title, $body, $link, $refId, $read);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_push failed: ' . $e->getMessage());
    }
}

/** Like inbox_push, but folds repeats (e.g. chat messages) into one unread row. */
function inbox_push_collapsed(int $userId, string $type, int $refId, string $category, string $title, string $body, ?string $link): void
{
    try {
        inbox_ensure();
        $stmt = db()->prepare('SELECT id FROM inbox_messages WHERE user_id = ? AND type = ? AND ref_id = ? AND is_read = 0 LIMIT 1');
        $stmt->bind_param('isi', $userId, $type, $refId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $id   = (int) $row['id'];
            $stmt = db()->prepare('UPDATE inbox_messages SET title = ?, body = ?, created_at = NOW() WHERE id = ?');
            $stmt->bind_param('ssi', $title, $body, $id);
            $stmt->execute();
            $stmt->close();
        } else {
            inbox_push($userId, $category, $title, $body, $link, $refId, $type);
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_push_collapsed failed: ' . $e->getMessage());
    }
}

function inbox_staff_ids(array $exclude = []): array
{
    $ids = [];
    try {
        $res = db()->query("SELECT id FROM users WHERE role IN ('admin','manager') AND status = 'active'");
        while ($r = $res->fetch_assoc()) {
            $id = (int) $r['id'];
            if (!in_array($id, $exclude, true)) $ids[] = $id;
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_staff_ids failed: ' . $e->getMessage());
    }
    return $ids;
}

function inbox_push_staff(string $category, string $title, string $body = '', ?string $link = null, ?int $refId = null, string $type = '', array $exclude = []): void
{
    foreach (inbox_staff_ids($exclude) as $id) {
        inbox_push($id, $category, $title, $body, $link, $refId, $type);
    }
}

function inbox_fmt_date(?string $d, string $fmt = 'M j, Y'): string
{
    if (!$d) return '';
    $ts = strtotime($d);
    return $ts ? date($fmt, $ts) : $d;
}

function inbox_parse_dt(mixed $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') return null;
    $ts = strtotime(str_replace('T', ' ', $v));
    return $ts ? date('Y-m-d H:i:00', $ts) : null;
}

/**
 * Turns a transaction milestone into inbox rows for the buyer, the
 * seller, and (for key milestones) every admin/manager.
 *
 * Events: interest, message, quantity, meetup_proposed, meetup_confirmed,
 *         meetup_rejected, buyer_confirmed, sale_completed
 */
function inbox_trade_event(int $txId, string $event, array $x = []): void
{
    try {
        $stmt = db()->prepare(
            'SELECT t.*, p.title AS product_title,
                    bu.first_name AS bfn, bu.last_name AS bln,
                    se.first_name AS sfn, se.last_name AS sln
             FROM transactions t
             JOIN products p ON p.id = t.product_id
             JOIN users bu ON bu.id = t.buyer_id
             JOIN users se ON se.id = t.seller_id
             WHERE t.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $txId);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$t) return;

        $buyerId  = (int) $t['buyer_id'];
        $sellerId = (int) $t['seller_id'];
        $buyer    = trim($t['bfn'] . ' ' . $t['bln']);
        $seller   = trim($t['sfn'] . ' ' . $t['sln']);
        $item     = '“' . $t['product_title'] . '”';
        $qty      = max(1, (int) ($t['quantity'] ?? 1));
        $link     = '/dashboard.php?view=chat&t=' . $txId;
        $staffLnk = '/admin/dashboard.php?tab=monitor';

        $d     = inbox_fmt_date($t['meetup_date'] ?? null);
        $tm    = inbox_fmt_date($t['meetup_time'] ?? null, 'g:i A');
        $when  = trim($d . ' ' . ($tm !== '' ? 'at ' . $tm : ''));
        $where = trim((string) ($t['meetup_location'] ?? ''));
        $meet  = ($when !== '' ? $when : 'time to be agreed') . ($where !== '' ? ' · ' . $where : '');

        $staff = function (string $title, string $body, string $type) use ($txId, $staffLnk, $buyerId, $sellerId): void {
            inbox_push_staff('trade', $title, $body, $staffLnk, $txId, $type, [$buyerId, $sellerId]);
        };

        switch ($event) {
            case 'interest':
                inbox_push($sellerId, 'trade', "$buyer is interested in your item",
                    "$item — a buyer just opened a chat with you. Reply to keep the deal moving.", $link, $txId, 'trade_interest');
                break;

            case 'message': {
                $sender = (int) ($x['sender'] ?? 0);
                if ($sender === $buyerId) {
                    inbox_push_collapsed($sellerId, 'trade_message', $txId, 'trade', "New message from $buyer", "About $item", $link);
                } elseif ($sender === $sellerId) {
                    inbox_push_collapsed($buyerId, 'trade_message', $txId, 'trade', "New message from $seller", "About $item", $link);
                }
                break;
            }

            case 'quantity':
                inbox_push($sellerId, 'trade', "$buyer wants $qty unit" . ($qty === 1 ? '' : 's'),
                    "$item — the buyer updated how many they want to buy.", $link, $txId, 'trade_quantity');
                break;

            case 'meetup_proposed':
                inbox_push($sellerId, 'trade', "$buyer proposed a meet-up",
                    "$item × $qty\n📅 $meet\nConfirm it, or ask for a different time.", $link, $txId, 'trade_meetup_proposed');
                $staff("Meet-up proposed: $buyer → $seller", "$item × $qty · $meet", 'staff_trade_meetup_proposed');
                break;

            case 'meetup_confirmed':
                inbox_push($buyerId, 'trade', "$seller accepted your meet-up",
                    "$item\n📅 $meet\nAfter you receive the item, confirm it and leave feedback.", $link, $txId, 'trade_meetup_confirmed');
                $staff("Meet-up confirmed: $seller ↔ $buyer", "$item · $meet", 'staff_trade_meetup_confirmed');
                break;

            case 'meetup_rejected':
                inbox_push($buyerId, 'trade', "$seller declined your meet-up",
                    "$item — the seller asked for a different time or place. Propose a new one from the chat.", $link, $txId, 'trade_meetup_rejected');
                $staff("Meet-up declined: $seller ✕ $buyer", "$item", 'staff_trade_meetup_rejected');
                break;

            case 'buyer_confirmed': {
                $rating = (int) ($x['rating'] ?? 0);
                $stars  = $rating > 0 ? str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) : '';
                $review = trim((string) ($x['review'] ?? ''));
                inbox_push($sellerId, 'trade', "$buyer confirmed receiving the item",
                    "$item" . ($stars !== '' ? "\nRating: $stars" : '') . ($review !== '' ? "\n“" . mb_substr($review, 0, 160) . '”' : '')
                    . "\nConfirm the sale to mark it as sold.", $link, $txId, 'trade_buyer_confirmed');
                $staff("Item received: $buyer confirmed", "$item" . ($stars !== '' ? " · $stars" : ''), 'staff_trade_buyer_confirmed');
                break;
            }

            case 'sale_completed':
                inbox_push($buyerId, 'trade', 'Trade completed 🎉',
                    "$seller confirmed the sale of $item. Thanks for trading on SafeTrade!", $link, $txId, 'trade_completed');
                inbox_push($sellerId, 'trade', 'Sale recorded',
                    "You confirmed the sale of $item to $buyer × $qty.", '/dashboard.php?view=my-listings', $txId, 'trade_completed', true);
                $staff("Trade completed: $seller → $buyer", "$item × $qty", 'staff_trade_completed');
                break;
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_trade_event failed: ' . $e->getMessage());
    }
}

/** Tell a seller when a moderator removes or restores one of their listings. */
function inbox_product_moderated(int $productId, string $action): void
{
    try {
        $stmt = db()->prepare('SELECT seller_id, title FROM products WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$p) return;
        $title = '“' . $p['title'] . '”';
        if ($action === 'delete_product') {
            inbox_push((int) $p['seller_id'], 'account', 'Your listing was removed',
                "$title was removed by a moderator for breaking SafeTrade's community guidelines.", '/dashboard.php?view=my-listings', $productId, 'listing_removed');
        } else {
            inbox_push((int) $p['seller_id'], 'account', 'Your listing was restored',
                "$title is live on the marketplace again.", '/dashboard.php?view=my-listings', $productId, 'listing_restored');
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_product_moderated failed: ' . $e->getMessage());
    }
}

/* ===================== Announcements ===================== */

/**
 * Stores the announcement and drops a copy into every matching
 * account's inbox. Audience: users | staff | all. Returns the number
 * of inboxes reached. Throws on failure so the admin sees the reason.
 */
function inbox_broadcast(int $authorId, string $category, string $title, string $body, string $audience, ?string $startsAt, ?string $endsAt, bool $pin): int
{
    inbox_ensure();
    $conn   = db();
    $pinInt = $pin ? 1 : 0;
    $title  = mb_substr($title, 0, 190);

    $stmt = $conn->prepare('INSERT INTO announcements (author_id, category, title, body, audience, is_pinned, starts_at, ends_at) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->bind_param('issssiss', $authorId, $category, $title, $body, $audience, $pinInt, $startsAt, $endsAt);
    $stmt->execute();
    $aid = (int) $conn->insert_id;
    $stmt->close();

    $inboxBody = $body;
    if ($category === 'maintenance' && ($startsAt || $endsAt)) {
        $inboxBody .= "\n\n🕒 " . ($startsAt ? inbox_fmt_date($startsAt, 'M j, Y g:i A') : 'Now')
                    . ' → ' . ($endsAt ? inbox_fmt_date($endsAt, 'M j, Y g:i A') : 'until further notice');
    }

    $where = $audience === 'staff'
        ? "role IN ('admin','manager') AND status = 'active'"
        : ($audience === 'all' ? "status = 'active'" : "role = 'user' AND status = 'active'");

    $stmt = $conn->prepare(
        "INSERT INTO inbox_messages (user_id, category, type, title, body, link, ref_id, is_read, is_pinned)
         SELECT id, ?, 'announcement', ?, ?, NULL, ?, 0, ? FROM users WHERE $where"
    );
    $stmt->bind_param('sssii', $category, $title, $inboxBody, $aid, $pinInt);
    $stmt->execute();
    $n = max(0, (int) $stmt->affected_rows);
    $stmt->close();

    $stmt = $conn->prepare('UPDATE announcements SET recipients = ? WHERE id = ?');
    $stmt->bind_param('ii', $n, $aid);
    $stmt->execute();
    $stmt->close();

    return $n;
}

function inbox_delete_announcement(int $id): void
{
    try {
        inbox_ensure();
        $c = db();
        $stmt = $c->prepare("DELETE FROM inbox_messages WHERE type = 'announcement' AND ref_id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $stmt = $c->prepare('DELETE FROM announcements WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_delete_announcement failed: ' . $e->getMessage());
    }
}

function inbox_audience_counts(): array
{
    $out = ['users' => 0, 'staff' => 0, 'all' => 0];
    try {
        $r = db()->query("SELECT SUM(role='user' AND status='active') u, SUM(role IN ('admin','manager') AND status='active') s, SUM(status='active') a FROM users")->fetch_assoc();
        $out = ['users' => (int) $r['u'], 'staff' => (int) $r['s'], 'all' => (int) $r['a']];
    } catch (Throwable $e) {
    }
    return $out;
}

/* ===================== Reading ===================== */

function inbox_unread(int $userId): int
{
    try {
        inbox_ensure();
        $stmt = db()->prepare('SELECT COUNT(*) c FROM inbox_messages WHERE user_id = ? AND is_read = 0');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}

/** unread total, newest id and per-category counts — used by the API and the live badge. */
function inbox_summary(int $userId): array
{
    $out = ['unread' => 0, 'latest_id' => 0, 'cats' => new stdClass()];
    try {
        inbox_ensure();
        $c = db();
        $stmt = $c->prepare('SELECT category, COUNT(*) total, SUM(is_read = 0) unread FROM inbox_messages WHERE user_id = ? GROUP BY category');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res  = $stmt->get_result();
        $cats = [];
        $unread = 0;
        while ($r = $res->fetch_assoc()) {
            $cats[$r['category']] = ['total' => (int) $r['total'], 'unread' => (int) $r['unread']];
            $unread += (int) $r['unread'];
        }
        $stmt->close();

        $stmt = $c->prepare('SELECT COALESCE(MAX(id), 0) m FROM inbox_messages WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $latest = (int) ($stmt->get_result()->fetch_assoc()['m'] ?? 0);
        $stmt->close();

        $out = ['unread' => $unread, 'latest_id' => $latest, 'cats' => $cats ?: new stdClass()];
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_summary failed: ' . $e->getMessage());
    }
    return $out;
}

/* ===================== UI pieces ===================== */

function inbox_icon_svg(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
         . '<path d="M22 12h-6l-2 3h-4l-2-3H2"/>'
         . '<path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>';
}

function inbox_badge_css(): string
{
    static $printed = false;
    if ($printed) return '';
    $printed = true;
    return '<style>
.inbox-badge{margin-left:auto;background:#C6871F;color:#092C21;font-size:10.5px;font-weight:800;padding:2px 7px;border-radius:999px;flex-shrink:0;line-height:1.35;}
.app.sidebar-collapsed .side-link .inbox-badge{position:absolute;top:3px;right:3px;margin:0;padding:1px 5px;font-size:9px;}
@media(max-width:1100px){.side-link .inbox-badge{display:inline-block;position:absolute;top:3px;right:6px;margin:0;padding:1px 5px;font-size:9px;}}
.side-link .inbox-badge[data-count="0"]{display:none !important;}
</style>';
}

/** Sidebar link for the user dashboard, with a live unread badge. */
function inbox_nav_link_user(string $currentView): string
{
    $u = current_user();
    $n = $u ? inbox_unread((int) $u['id']) : 0;
    $active = $currentView === 'inbox' ? ' active' : '';
    $label  = $n > 99 ? '99+' : (string) $n;
    return inbox_badge_css()
        . '<a class="side-link' . $active . '" href="?view=inbox">' . inbox_icon_svg()
        . '<span>Inbox</span>'
        . '<span class="inbox-badge" data-inbox-badge data-count="' . $n . '"' . ($n > 0 ? '' : ' style="display:none;"') . '>' . $label . '</span></a>';
}

/**
 * Maintenance strip shown at the top of every dashboard page while a
 * maintenance notice is ongoing or starts within 3 days. Also starts
 * the lightweight badge poller so the sidebar count stays live.
 */
function inbox_maintenance_banner(string $mode = 'user'): string
{
    $html = '';
    try {
        inbox_ensure();
        $aud = $mode === 'admin' ? "('staff','all')" : "('users','all')";
        $res = db()->query(
            "SELECT id, title, starts_at, ends_at, (starts_at IS NULL OR starts_at <= NOW()) AS ongoing
             FROM announcements
             WHERE category = 'maintenance' AND audience IN $aud
               AND (starts_at IS NULL OR starts_at <= DATE_ADD(NOW(), INTERVAL 3 DAY))
               AND ((ends_at IS NOT NULL AND ends_at >= NOW()) OR (ends_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)))
             ORDER BY COALESCE(starts_at, created_at) ASC LIMIT 1"
        );
        $a = $res->fetch_assoc();
        if ($a) {
            $inboxHref = $mode === 'admin' ? '?tab=inbox' : '?view=inbox';
            $text = (int) $a['ongoing'] === 1
                ? 'Maintenance in progress'
                : 'Upcoming maintenance · ' . inbox_fmt_date($a['starts_at'], 'M j, g:i A');
            $html .= '<div id="ibx-banner" data-id="' . (int) $a['id'] . '" style="display:flex;align-items:center;gap:12px;background:#F5E6C2;color:#6B4608;border:1px solid rgba(198,135,31,.35);border-radius:14px;padding:11px 16px;margin-bottom:18px;font-size:13px;font-weight:600;">'
                  . '<span style="font-size:18px;">🛠️</span>'
                  . '<span style="flex:1;"><strong>' . e($text) . '</strong> — ' . e($a['title']) . '</span>'
                  . '<a href="' . e($inboxHref) . '" style="text-decoration:underline;white-space:nowrap;">Details</a>'
                  . '<button type="button" id="ibx-banner-x" aria-label="Dismiss" style="background:none;border:none;cursor:pointer;font-size:16px;color:inherit;line-height:1;">✕</button>'
                  . '</div>'
                  . '<script>(function(){var b=document.getElementById("ibx-banner");if(!b)return;var k="ibx_banner_"+b.getAttribute("data-id");try{if(sessionStorage.getItem(k)){b.style.display="none";}}catch(e){}'
                  . 'document.getElementById("ibx-banner-x").addEventListener("click",function(){b.style.display="none";try{sessionStorage.setItem(k,"1");}catch(e){}});})();</script>';
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] inbox_maintenance_banner failed: ' . $e->getMessage());
    }

    $api = rtrim(BASE_URL, '/') . '/inbox-api.php';
    $html .= '<script>(function(){if(window.__ibxPoll)return;window.__ibxPoll=1;var api=' . json_encode($api) . ';'
           . 'function set(n){document.querySelectorAll("[data-inbox-badge]").forEach(function(el){el.textContent=n>99?"99+":n;el.setAttribute("data-count",n);el.style.display=n>0?"":"none";});'
           . 'document.querySelectorAll("a.admin-link[href*=\'tab=inbox\']").forEach(function(a){var c=a.querySelector(".count");if(n>0){if(!c){c=document.createElement("span");c.className="count";a.appendChild(c);}c.textContent=n>99?"99+":n;}else if(c){c.remove();}});}'
           . 'function tick(){if(document.hidden)return;fetch(api+"?action=count",{credentials:"same-origin"}).then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&d.ok)set(d.unread);}).catch(function(){});}'
           . 'setInterval(tick,30000);})();</script>';
    return $html;
}

/**
 * The inbox itself. $mode = 'user' (dashboard.php?view=inbox) or
 * 'admin' (admin/dashboard.php?tab=inbox). Data loads through
 * inbox-api.php so the list stays live without page reloads.
 */
function inbox_render_panel(string $mode = 'user'): void
{
    $api  = rtrim(BASE_URL, '/') . '/inbox-api.php';
    $sub  = $mode === 'admin'
        ? 'Trade activity across SafeTrade, new registrations, and announcements.'
        : 'Your trade updates, meet-up replies, announcements and maintenance notices.';
    $actions = '<div class="ibx-actions"><button type="button" class="ibx-btn" id="ibx-readall">✓ Mark all read</button>'
             . '<button type="button" class="ibx-btn" id="ibx-clear">🗑 Clear read</button></div>';

    if ($mode === 'admin') {
        echo '<div class="admin-topbar"><div><h1>Inbox</h1><p class="sub" id="ibx-sub">' . e($sub) . '</p></div>' . $actions . '</div>';
    } else {
        echo '<div class="row-head"><h2>Inbox</h2>' . $actions . '</div><p id="ibx-sub" style="color:var(--ink-soft);font-size:13px;margin:-6px 0 18px;">' . e($sub) . '</p>';
    }
    echo '<div class="ibx" id="ibx" data-api="' . e($api) . '" data-csrf="' . e(csrf_token()) . '" data-mode="' . e($mode) . '">'
       . '<div class="ibx-chips" id="ibx-chips"></div><div id="ibx-list"></div>'
       . '<button type="button" class="ibx-btn" id="ibx-more" style="display:none;margin:8px auto 0;">Load older</button></div>';

    echo <<<'HTML'
<style>
.ibx{max-width:860px;}
.ibx-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.ibx-btn{border:1.5px solid var(--line-strong);background:var(--cream);color:var(--ink);border-radius:999px;padding:8px 15px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .15s ease;display:block;}
.ibx-btn:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}
.ibx-chips{display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 18px;}
.ibx-chip{border:1.5px solid var(--line-strong);background:var(--paper);color:var(--ink-soft);border-radius:999px;padding:7px 14px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .15s ease;}
.ibx-chip:hover{border-color:var(--brand);color:var(--brand);}
.ibx-chip.active{background:var(--brand);border-color:var(--brand);color:#fff;}
.ibx-chip .n{background:#C6871F;color:#092C21;font-size:10px;font-weight:800;padding:1px 6px;border-radius:99px;margin-left:6px;}
.ibx-item{display:flex;gap:14px;padding:15px 16px;border-radius:16px;background:var(--paper);border:1px solid var(--line);margin-bottom:10px;position:relative;transition:box-shadow .15s ease,transform .15s ease;}
.ibx-item.has-link{cursor:pointer;}
.ibx-item:hover{box-shadow:0 8px 22px -12px rgba(9,44,33,.3);}
.ibx-item.unread{background:#FBF7EF;border-left:4px solid var(--brand);padding-left:13px;}
.ibx-item.unread.cat-maintenance{border-left-color:#C6871F;}
.ibx-ico{width:42px;height:42px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;background:var(--brand-light);}
.tone-warn .ibx-ico{background:var(--gold-light);}
.tone-bad .ibx-ico{background:var(--danger-bg);}
.ibx-main{flex:1;min-width:0;}
.ibx-top{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.ibx-title{font-size:14.5px;font-weight:600;color:var(--ink);}
.ibx-item.unread .ibx-title{font-weight:700;}
.ibx-tag{font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--line);color:var(--ink-soft);}
.ibx-tag.announcement{background:var(--brand-light);color:var(--brand-dark);}
.ibx-tag.update{background:#E3E8F8;color:#2B3A7A;}
.ibx-tag.maintenance{background:var(--gold-light);color:#7A5308;}
.ibx-tag.pin{background:#C6871F;color:#fff;}
.ibx-body{font-size:13px;color:var(--ink-soft);line-height:1.6;margin-top:4px;white-space:pre-line;word-break:break-word;}
.ibx-foot{display:flex;align-items:center;gap:12px;margin-top:9px;font-size:11.5px;color:var(--ink-faint);}
.ibx-open{color:var(--brand);font-weight:700;text-decoration:none;}
.ibx-open:hover{text-decoration:underline;}
.ibx-side{display:flex;flex-direction:column;gap:6px;opacity:.55;transition:opacity .15s ease;}
.ibx-item:hover .ibx-side{opacity:1;}
.ibx-icon-btn{width:30px;height:30px;border-radius:9px;border:1.5px solid var(--line-strong);background:var(--cream);cursor:pointer;font-size:13px;line-height:1;color:var(--ink-soft);}
.ibx-icon-btn:hover{border-color:var(--brand);color:var(--brand);}
.ibx-icon-btn.del:hover{border-color:var(--danger);color:var(--danger);background:var(--danger-bg);}
.ibx-empty{text-align:center;padding:56px 20px;color:var(--ink-soft);font-size:14px;background:var(--paper);border-radius:18px;border:1px dashed var(--line-strong);}
.ibx-empty .big{font-size:44px;display:block;margin-bottom:8px;}
@media(max-width:560px){.ibx-item{padding:13px;gap:10px;}.ibx-ico{width:36px;height:36px;font-size:17px;}}
</style>
<script>
(function () {
  var root = document.getElementById('ibx');
  if (!root) return;
  var API = root.getAttribute('data-api');
  var CSRF = root.getAttribute('data-csrf');
  var listEl = document.getElementById('ibx-list');
  var chipsEl = document.getElementById('ibx-chips');
  var moreBtn = document.getElementById('ibx-more');
  var subEl = document.getElementById('ibx-sub');
  var defaultSub = subEl ? subEl.textContent : '';

  var FILTERS = [['all','All'],['unread','Unread'],['trade','Transactions'],['announcement','Announcements'],['update','Updates'],['maintenance','Maintenance'],['account','Account']];
  var ICONS = {interest:'💬',message:'✉️',quantity:'📦',meetup_proposed:'📅',meetup_confirmed:'✅',meetup_rejected:'↩️',buyer_confirmed:'📬',completed:'🎉',verified:'🪪',banned:'🚫',reactivated:'🔓',removed:'🗑️',restored:'♻️',new_registration:'🆕'};
  var CAT_ICONS = {trade:'🤝',announcement:'📢',update:'✨',maintenance:'🛠️',account:'🪪'};
  var CAT_LABEL = {announcement:'Announcement',update:'Update',maintenance:'Maintenance'};
  var state = { filter: 'all', page: 1, hasMore: false, items: [], unread: 0, latest: 0, cats: {} };

  function qs(o) { return Object.keys(o).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(o[k]); }).join('&'); }
  function get(o) { return fetch(API + '?' + qs(o), { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function post(o) {
    o.csrf_token = CSRF;
    return fetch(API, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: qs(o) }).then(function (r) { return r.json(); });
  }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

  function setBadges(n) {
    document.querySelectorAll('[data-inbox-badge]').forEach(function (b) {
      b.textContent = n > 99 ? '99+' : n; b.setAttribute('data-count', n); b.style.display = n > 0 ? '' : 'none';
    });
    document.querySelectorAll('a.admin-link[href*="tab=inbox"]').forEach(function (a) {
      var c = a.querySelector('.count');
      if (n > 0) { if (!c) { c = document.createElement('span'); c.className = 'count'; a.appendChild(c); } c.textContent = n > 99 ? '99+' : n; }
      else if (c) { c.remove(); }
    });
  }

  function absorb(d) {
    if (!d || !d.ok) return;
    state.unread = d.unread; state.latest = d.latest_id; state.cats = d.cats || {};
    setBadges(state.unread);
    renderChips();
    if (subEl) subEl.textContent = state.unread > 0 ? state.unread + ' unread · ' + defaultSub : defaultSub;
  }

  function renderChips() {
    chipsEl.innerHTML = '';
    FILTERS.forEach(function (f) {
      var b = el('button', 'ibx-chip' + (state.filter === f[0] ? ' active' : ''), f[1]);
      b.type = 'button';
      var n = f[0] === 'unread' ? state.unread : (state.cats[f[0]] ? state.cats[f[0]].unread : 0);
      if (n > 0) b.appendChild(el('span', 'n', n));
      b.addEventListener('click', function () { state.filter = f[0]; load(true); });
      chipsEl.appendChild(b);
    });
  }

  function ago(it) {
    var s = it.age_s;
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    if (s < 604800) return Math.floor(s / 86400) + 'd ago';
    return (it.created_at || '').slice(0, 10);
  }
  function tail(type) { return (type || '').replace(/^staff_/, '').replace(/^trade_/, '').replace(/^account_/, '').replace(/^listing_/, ''); }
  function iconOf(it) {
    var t = tail(it.type);
    if (it.type === 'listing_removed') return ICONS.removed;
    if (it.type === 'listing_restored') return ICONS.restored;
    return ICONS[t] || CAT_ICONS[it.category] || '🔔';
  }
  function toneOf(it) {
    var t = (it.type || '');
    if (/rejected|banned|removed/.test(t)) return 'bad';
    if (/proposed|buyer_confirmed|quantity/.test(t) || it.category === 'maintenance') return 'warn';
    return '';
  }

  function render() {
    listEl.innerHTML = '';
    if (!state.items.length) {
      var e = el('div', 'ibx-empty');
      e.appendChild(el('span', 'big', state.filter === 'unread' ? '🎉' : '📭'));
      e.appendChild(document.createTextNode(state.filter === 'unread' ? "You're all caught up." : 'Nothing here yet.'));
      listEl.appendChild(e);
    }
    state.items.forEach(function (it) { listEl.appendChild(renderItem(it)); });
    moreBtn.style.display = state.hasMore ? 'block' : 'none';
  }

  function renderItem(it) {
    var node = el('div', 'ibx-item' + (it.is_read ? '' : ' unread') + ' cat-' + it.category + ' tone-' + toneOf(it) + (it.link ? ' has-link' : ''));
    node.setAttribute('data-id', it.id);
    node.appendChild(el('div', 'ibx-ico', iconOf(it)));
    var main = el('div', 'ibx-main');
    var top = el('div', 'ibx-top');
    top.appendChild(el('span', 'ibx-title', it.title));
    if (CAT_LABEL[it.category]) top.appendChild(el('span', 'ibx-tag ' + it.category, CAT_LABEL[it.category]));
    if (it.is_pinned) top.appendChild(el('span', 'ibx-tag pin', '📌 Pinned'));
    main.appendChild(top);
    if (it.body) main.appendChild(el('div', 'ibx-body', it.body));
    var foot = el('div', 'ibx-foot');
    foot.appendChild(el('span', '', ago(it)));
    if (it.link) {
      var a = el('a', 'ibx-open', it.type.indexOf('trade_') !== -1 && it.type.indexOf('staff_') !== 0 ? 'Open chat →' : 'Open →');
      a.href = it.link;
      foot.appendChild(a);
    }
    main.appendChild(foot);
    node.appendChild(main);

    var side = el('div', 'ibx-side');
    var rb = el('button', 'ibx-icon-btn', it.is_read ? '○' : '✓');
    rb.type = 'button'; rb.title = it.is_read ? 'Mark as unread' : 'Mark as read';
    rb.addEventListener('click', function (ev) { ev.stopPropagation(); toggleRead(it); });
    var db = el('button', 'ibx-icon-btn del', '🗑');
    db.type = 'button'; db.title = 'Delete';
    db.addEventListener('click', function (ev) { ev.stopPropagation(); remove(it); });
    side.appendChild(rb); side.appendChild(db);
    node.appendChild(side);

    if (it.link) {
      node.addEventListener('click', function (ev) {
        if (ev.target.closest('button')) return;
        if (!it.is_read) post({ action: 'read', id: it.id });
        window.location.href = it.link;
      });
    }
    return node;
  }

  function toggleRead(it) {
    var act = it.is_read ? 'unread' : 'read';
    it.is_read = it.is_read ? 0 : 1;
    post({ action: act, id: it.id }).then(function (d) { absorb(d); if (state.filter === 'unread') load(true, true); else render(); });
    render();
  }
  function remove(it) {
    state.items = state.items.filter(function (x) { return x.id !== it.id; });
    render();
    post({ action: 'delete', id: it.id }).then(absorb);
  }

  function load(reset, silent) {
    if (reset) state.page = 1;
    get({ action: 'list', filter: state.filter, page: state.page }).then(function (d) {
      if (!d || !d.ok) return;
      state.items = reset ? d.items : state.items.concat(d.items);
      state.hasMore = d.has_more;
      absorb(d);
      render();
    }).catch(function () { if (!silent) listEl.innerHTML = '<div class="ibx-empty">Could not load your inbox. Please refresh.</div>'; });
  }

  moreBtn.addEventListener('click', function () { state.page++; load(false); });
  document.getElementById('ibx-readall').addEventListener('click', function () {
    post({ action: 'read_all' }).then(function (d) { absorb(d); load(true, true); });
  });
  document.getElementById('ibx-clear').addEventListener('click', function () {
    if (!confirm('Delete every message you have already read? (Pinned announcements stay.)')) return;
    post({ action: 'clear_read' }).then(function (d) { absorb(d); load(true, true); });
  });

  setInterval(function () {
    if (document.hidden) return;
    get({ action: 'count' }).then(function (d) {
      if (d && d.ok && (d.latest_id !== state.latest || d.unread !== state.unread)) load(true, true);
    }).catch(function () {});
  }, 20000);

  renderChips();
  load(true);
})();
</script>
HTML;
}

/** Admin tab: compose an announcement / update / maintenance notice + history. */
function inbox_render_announcements_admin(): void
{
    inbox_ensure();
    $counts = inbox_audience_counts();
    $rows   = [];
    try {
        $res = db()->query(
            "SELECT a.*, u.first_name, u.last_name,
                    (SELECT COUNT(*) FROM inbox_messages m WHERE m.type = 'announcement' AND m.ref_id = a.id AND m.is_read = 1) AS read_count
             FROM announcements a LEFT JOIN users u ON u.id = a.author_id
             ORDER BY a.created_at DESC LIMIT 50"
        );
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    } catch (Throwable $e) {
    }
    ?>
<div class="admin-topbar"><div><h1>Announcements</h1><p class="sub">Broadcast news, product updates and maintenance notices straight to everyone's inbox.</p></div></div>

<div class="panel" style="max-width:760px;">
  <h2>📢 New announcement</h2>
  <form method="post" id="ann-form">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="announcements">
    <input type="hidden" name="action" value="send_announcement">
    <div class="field-row">
      <div class="field" style="margin-top:0;">
        <label>Type</label>
        <select name="category" id="ann-cat">
          <option value="announcement">📢 Announcement</option>
          <option value="update">✨ Update (what's new)</option>
          <option value="maintenance">🛠️ Maintenance</option>
        </select>
      </div>
      <div class="field" style="margin-top:0;">
        <label>Send to</label>
        <select name="audience">
          <option value="users">All members (<?= (int) $counts['users'] ?>)</option>
          <option value="staff">Admins &amp; managers only (<?= (int) $counts['staff'] ?>)</option>
          <option value="all">Everyone (<?= (int) $counts['all'] ?>)</option>
        </select>
      </div>
    </div>
    <div class="field"><label>Title</label><input type="text" name="title" maxlength="190" required placeholder="e.g. Scheduled maintenance this Sunday"></div>
    <div class="field"><label>Message</label><textarea name="body" rows="5" required placeholder="Write what people need to know…"></textarea></div>
    <div class="field-row" id="ann-window" style="display:none;">
      <div class="field"><label>Maintenance starts</label><input type="datetime-local" name="starts_at"></div>
      <div class="field"><label>Maintenance ends</label><input type="datetime-local" name="ends_at"></div>
    </div>
    <label style="display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13px;font-weight:500;cursor:pointer;">
      <input type="checkbox" name="pin" value="1" style="width:auto;margin:0;"> 📌 Pin to the top of their inbox
    </label>
    <p id="ann-hint" style="font-size:12px;color:var(--ink-soft);margin-top:10px;">Maintenance notices also show a banner on every dashboard page while they're active or starting within 3 days.</p>
    <button type="submit" class="btn-primary" style="width:auto;padding:13px 28px;">Send announcement</button>
  </form>
</div>

<div class="panel">
  <h2>🗂 Sent announcements</h2>
  <table class="data-table">
    <thead><tr><th>Title</th><th>Type</th><th>Audience</th><th>Reach</th><th>Sent</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="6"><div class="empty-state">No announcements sent yet.</div></td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e($r['title']) ?></strong><?= (int) $r['is_pinned'] ? ' 📌' : '' ?><br>
          <span style="font-size:11.5px;color:var(--ink-soft);"><?= e(mb_substr((string) $r['body'], 0, 90)) ?><?= mb_strlen((string) $r['body']) > 90 ? '…' : '' ?></span></td>
        <td><span class="pill <?= $r['category'] === 'maintenance' ? 'pending' : 'active' ?>"><?= e(ucfirst($r['category'])) ?></span></td>
        <td><?= e(['users' => 'Members', 'staff' => 'Staff', 'all' => 'Everyone'][$r['audience']] ?? $r['audience']) ?></td>
        <td><?= (int) $r['recipients'] ?> sent<br><span style="font-size:11.5px;color:var(--ink-soft);"><?= (int) $r['read_count'] ?> read</span></td>
        <td style="font-size:12.5px;"><?= e(date('M j, Y g:i A', strtotime((string) $r['created_at']))) ?><br><span style="font-size:11.5px;color:var(--ink-soft);">by <?= e(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: 'Admin') ?></span></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete this announcement and remove it from every inbox?');"><?= csrf_field() ?>
            <input type="hidden" name="back" value="announcements">
            <input type="hidden" name="action" value="delete_announcement">
            <input type="hidden" name="announcement_id" value="<?= (int) $r['id'] ?>">
            <button class="btn-sm danger" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
(function () {
  var cat = document.getElementById('ann-cat');
  var win = document.getElementById('ann-window');
  function sync() { win.style.display = cat.value === 'maintenance' ? 'flex' : 'none'; }
  cat.addEventListener('change', sync); sync();
  document.getElementById('ann-form').addEventListener('submit', function (e) {
    if (!confirm('Send this to the selected audience now?')) e.preventDefault();
  });
})();
</script>
    <?php
}
