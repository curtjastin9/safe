<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/* browse.php — public, no-login-required marketplace browsing (guest only).
   Logged-in users are bounced to dashboard.php. No DB writes happen here;
   "Chat" / "Message seller" send guests to auth/login.php?redirect_product=ID. */

if (is_logged_in()) {
    $qs = $_GET;
    $qs['view'] = in_array($qs['view'] ?? '', ['item'], true) ? 'item' : 'market';
    redirect('/dashboard.php?' . http_build_query($qs));
}

$conn = db();

$categoriesFlat = [];
try {
    $catRes = $conn->query('SELECT id, name, parent_id, icon FROM categories ORDER BY (parent_id IS NULL) DESC, parent_id ASC, name ASC');
    while ($row = $catRes->fetch_assoc()) {
        $categoriesFlat[] = [
            'id'        => (int) $row['id'],
            'name'      => $row['name'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'icon'      => $row['icon'] !== null && $row['icon'] !== '' ? $row['icon'] : '📦',
        ];
    }
} catch (Throwable $e) {
    $catRes = $conn->query('SELECT id, name FROM categories ORDER BY name');
    while ($row = $catRes->fetch_assoc()) {
        $categoriesFlat[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'parent_id' => null, 'icon' => '📦'];
    }
}
$topCategories = array_values(array_filter($categoriesFlat, fn($c) => $c['parent_id'] === null));

function bp_thumbs(?string $concat): array
{
    if (!$concat) return [];
    $ids = array_values(array_filter(explode('|', $concat), fn($p) => $p !== ''));
    $ids = array_slice($ids, 0, 6);
    return array_map(fn($id) => 'image.php?type=product&id=' . (int) $id, $ids);
}

function bp_qty(array $row): int
{
    return isset($row['quantity']) ? (int) $row['quantity'] : 1;
}

function bp_query_string(array $overrides = []): string
{
    $params = [
        'cat' => (int) ($_GET['cat'] ?? 0),
        'q'   => trim((string) ($_GET['q'] ?? '')),
    ];
    $params = array_merge($params, $overrides);
    $qs = 'view=market';
    if (!empty($params['cat'])) $qs .= '&cat=' . (int) $params['cat'];
    if (($params['q'] ?? '') !== '') $qs .= '&q=' . urlencode($params['q']);
    return '?' . $qs;
}

$imagesSubquery = "(SELECT GROUP_CONCAT(pi.id ORDER BY pi.id ASC SEPARATOR '|') FROM product_images pi WHERE pi.product_id = p.id) AS images_concat";

$view = ($_GET['view'] ?? 'market') === 'item' ? 'item' : 'market';

$item        = null;
$items       = null;
$catFilter   = 0;
$searchQuery = '';

if ($view === 'item') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT p.*, u.first_name, u.last_name, u.id AS seller_id2, $imagesSubquery
         FROM products p JOIN users u ON u.id = p.seller_id WHERE p.id=? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($item && ((int) $item['is_deleted'] === 1 || (int) $item['is_active'] === 0)) {
        $item = null;
    }
} else {
    $catFilter   = (int) ($_GET['cat'] ?? 0);
    $searchQuery = trim(clean_input($_GET['q'] ?? ''));

    $catIds = [];
    if ($catFilter) {
        $catIds[] = $catFilter;
        foreach ($categoriesFlat as $c) {
            if ($c['parent_id'] === $catFilter) $catIds[] = $c['id'];
        }
    }

    $where  = ['COALESCE(p.is_active,1)=1', 'COALESCE(p.is_deleted,0)=0'];
    $params = [];
    $types  = '';
    if ($catIds) {
        $where[] = 'p.category_id IN (' . implode(',', array_fill(0, count($catIds), '?')) . ')';
        foreach ($catIds as $cid) { $params[] = $cid; $types .= 'i'; }
    }
    if ($searchQuery !== '') {
        $where[]  = '(p.title LIKE ? OR p.description LIKE ?)';
        $like     = '%' . $searchQuery . '%';
        $params[] = $like; $params[] = $like; $types .= 'ss';
    }
    $whereSql = implode(' AND ', $where);

    $sql = "SELECT p.*, u.first_name, u.last_name, $imagesSubquery
            FROM products p JOIN users u ON u.id=p.seller_id
            WHERE $whereSql ORDER BY p.created_at DESC LIMIT 60";
    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $items = $stmt->get_result();
    $stmt->close();
}

$pageTitle = ($view === 'item' && $item) ? ($item['title'] . ' · SafeTrade') : 'Browse Market · SafeTrade';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#FBF7EF;--card:#F4EEE1;--ink:#161F16;--soft:#5A6252;--faint:#8B927F;--brand:#155943;--dark:#092C21;--light:#E4EFE7;--tint:#F1F7F2;--gold:#C6871F;--goldl:#F5E6C2;--line:rgba(22,31,22,.10);--line2:rgba(22,31,22,.17);--ease:cubic-bezier(.22,.75,.28,1);--sh1:0 2px 8px rgba(10,50,38,.07);--sh2:0 14px 36px rgba(10,50,38,.14);}
*{box-sizing:border-box;margin:0;}
body{background:var(--bg);color:var(--ink);font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased;line-height:1.5;}
h1,h2,h3{font-family:'Fraunces',Georgia,serif;letter-spacing:-.02em;line-height:1.15;font-weight:600;}
a{color:inherit;text-decoration:none;}
.w{max-width:1280px;margin:0 auto;padding:0 32px;}

/* header */
header{position:sticky;top:0;z-index:60;background:rgba(251,247,239,.92);backdrop-filter:blur(16px) saturate(1.5);border-bottom:1px solid var(--line);}
.nav{display:flex;align-items:center;gap:22px;padding:12px 32px;}
.logo{display:flex;align-items:center;gap:10px;font-family:'Fraunces',serif;font-weight:700;font-size:22px;letter-spacing:-.01em;}
.logo img{width:40px;height:40px;object-fit:contain;}
.seal{width:36px;height:36px;border-radius:50%;background:linear-gradient(160deg,var(--brand),var(--dark));color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;}
.nav form{flex:1;max-width:560px;margin:0 auto 0 12px;}
.sbox{display:flex;align-items:center;background:var(--bg);border:1.5px solid var(--line);border-radius:999px;padding:4px 4px 4px 16px;transition:.2s;}
.sbox:focus-within{background:var(--card);border-color:var(--brand);box-shadow:0 0 0 4px rgba(21,89,67,.12);}
.sbox input{flex:1;min-width:0;border:0;background:transparent;outline:0;font:inherit;font-size:14px;padding:9px 6px;color:var(--ink);}
.sbox .clr{color:var(--faint);font-size:13px;padding:4px 8px;}
.sbox .clr:hover{color:var(--ink);}
.sbox button{border:0;background:var(--brand);color:#fff;border-radius:999px;padding:9px 20px;font:inherit;font-weight:700;font-size:13px;cursor:pointer;transition:.15s;}
.sbox button:hover{background:var(--dark);}
.links{display:flex;align-items:center;gap:4px;margin-left:auto;font-size:14px;font-weight:600;}
.links a{padding:10px 16px;border-radius:999px;color:var(--soft);transition:.15s;}
.links a:hover{background:var(--light);color:var(--brand);}
.links .cta{background:var(--brand);color:#fff;padding:11px 22px;box-shadow:var(--sh1);}
.links .cta:hover{background:var(--dark);color:#fff;transform:translateY(-1px);}

main{max-width:1280px;margin:0 auto;padding:26px 32px 80px;}
.note{display:flex;align-items:center;gap:12px;background:var(--goldl);color:#6B4608;border:1px solid rgba(217,148,42,.3);border-radius:14px;padding:12px 18px;font-size:13px;font-weight:600;margin-bottom:24px;}
.note a{text-decoration:underline;}

.head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:16px;flex-wrap:wrap;}
.head h1{font-size:30px;}
.head .count{font-size:13px;color:var(--soft);font-weight:500;}

.cats{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 14px;margin-bottom:10px;scrollbar-width:none;}
.cats::-webkit-scrollbar{display:none;}
.chip{flex:none;display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:999px;background:var(--card);border:1.5px solid var(--line);font-size:13.5px;font-weight:600;color:var(--soft);transition:.18s var(--ease);white-space:nowrap;}
.chip:hover{border-color:var(--brand);color:var(--brand);transform:translateY(-1px);box-shadow:var(--sh1);}
.chip.active{background:var(--brand);border-color:var(--brand);color:#fff;box-shadow:0 6px 16px rgba(21,89,67,.25);}

.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(236px,1fr));gap:20px;}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;overflow:hidden;display:flex;flex-direction:column;transition:transform .25s var(--ease),box-shadow .25s var(--ease),border-color .25s;}
.card:hover{transform:translateY(-5px);box-shadow:var(--sh2);border-color:rgba(21,89,67,.25);}
.media{position:relative;display:block;aspect-ratio:1/1;background:linear-gradient(145deg,var(--tint),#E4EFE7);overflow:hidden;}
.media img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;transition:transform .5s var(--ease);}
.card:hover .media img{transform:scale(1.06);}
.fb{display:flex;align-items:center;justify-content:center;height:100%;font-size:60px;}
.tag{position:absolute;left:12px;top:12px;background:rgba(255,255,255,.95);color:var(--dark);font-size:11px;font-weight:700;padding:5px 10px;border-radius:999px;box-shadow:var(--sh1);}
.body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:3px;flex:1;}
.price{font-weight:800;font-size:20px;color:var(--dark);letter-spacing:-.01em;}
.title{font-size:14.5px;font-weight:600;color:var(--ink);display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;}
.title:hover{color:var(--brand);}
.meta{font-size:12.5px;color:var(--soft);display:flex;align-items:center;gap:5px;}
.seller{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:auto;padding-top:12px;border-top:1px solid var(--line);}
.sinfo{display:flex;align-items:center;gap:9px;font-size:12px;color:var(--soft);line-height:1.35;min-width:0;}
.sinfo b{display:block;color:var(--ink);font-weight:600;font-size:12.5px;}
.av{width:30px;height:30px;border-radius:50%;background:linear-gradient(160deg,var(--brand),var(--dark));color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex:none;}
.chat{background:var(--brand);color:#fff;border-radius:999px;padding:8px 15px;font-size:12.5px;font-weight:700;white-space:nowrap;transition:.15s;}
.chat:hover{background:var(--dark);transform:translateY(-1px);}

.empty{grid-column:1/-1;text-align:center;background:var(--card);border:1px dashed var(--line2);border-radius:22px;padding:70px 20px;}
.empty .ei{font-size:54px;}
.empty h3{font-size:22px;margin-top:12px;}
.empty p{color:var(--soft);margin-top:6px;font-size:14px;}
.empty a{display:inline-block;margin-top:18px;background:var(--brand);color:#fff;padding:11px 24px;border-radius:999px;font-weight:700;font-size:13.5px;}

/* detail */
.crumb{display:inline-flex;align-items:center;gap:6px;font-size:13.5px;font-weight:600;color:var(--soft);margin-bottom:18px;}
.crumb:hover{color:var(--brand);}
.detail{display:grid;grid-template-columns:1.15fr 1fr;gap:36px;align-items:start;}
.gal{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:14px;box-shadow:var(--sh1);}
.hero{aspect-ratio:1/1;border-radius:16px;overflow:hidden;background:linear-gradient(145deg,var(--tint),#E4EFE7);}
.hero img{width:100%;height:100%;object-fit:cover;display:block;}
.hero .fb{font-size:110px;}
.thumbs{display:flex;gap:10px;margin-top:12px;overflow-x:auto;}
.thumbs img{width:76px;height:76px;object-fit:cover;border-radius:12px;border:2px solid transparent;cursor:pointer;opacity:.7;transition:.15s;flex:none;}
.thumbs img:hover,.thumbs img.on{opacity:1;border-color:var(--brand);}
.info{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:30px;box-shadow:var(--sh1);position:sticky;top:90px;}
.info h2{font-size:28px;}
.info .price{font-size:34px;margin-top:14px;display:block;}
.info .meta{margin-top:10px;font-size:13.5px;}
.pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;}
.pill{background:var(--light);color:var(--dark);font-size:12.5px;font-weight:700;padding:6px 13px;border-radius:999px;}
.desc{margin-top:20px;padding-top:20px;border-top:1px solid var(--line);color:var(--soft);line-height:1.75;font-size:15px;}
.desc h4{font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);margin-bottom:8px;font-weight:800;}
.sbar{display:flex;align-items:center;gap:12px;margin-top:22px;padding:14px;background:var(--bg);border-radius:16px;}
.sbar .av{width:44px;height:44px;font-size:15px;}
.sbar b{display:block;font-size:15px;}
.sbar span{font-size:12.5px;color:var(--brand);font-weight:700;}
.big{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin-top:18px;background:linear-gradient(135deg,var(--brand),var(--dark));color:#fff;padding:16px;border-radius:999px;font-weight:700;font-size:15px;box-shadow:0 10px 24px rgba(21,89,67,.3);transition:.2s var(--ease);}
.big:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(21,89,67,.38);}
.safe{margin-top:14px;font-size:12px;color:var(--faint);text-align:center;}

footer{border-top:1px solid var(--line);background:var(--card);padding:26px 0;text-align:center;font-size:12.5px;color:var(--faint);}

@media(max-width:900px){.detail{grid-template-columns:1fr;}.info{position:static;}.nav form{display:none;}.nav{padding:12px 20px;}}
@media(max-width:640px){main{padding:20px 16px 60px;}.grid{grid-template-columns:repeat(2,1fr);gap:12px;}.head h1{font-size:24px;}.links a:not(.cta){display:none;}.price{font-size:17px;}.seller{flex-direction:column;align-items:stretch;}.chat{text-align:center;}.info{padding:22px;}.info h2{font-size:23px;}}
@media(max-width:380px){.grid{grid-template-columns:1fr;}}
@media(prefers-reduced-motion:reduce){*{transition:none!important;}}
</style>
</head>
<body>

<header>
  <div class="nav">
    <a href="Index.html" class="logo"><img src="img/logo1.png" alt="" onerror="this.outerHTML='<span class=&quot;seal&quot;>✓</span>'">SafeTrade</a>
    <form method="get" role="search">
      <input type="hidden" name="view" value="market">
      <?php if ($view === 'market' && $catFilter): ?><input type="hidden" name="cat" value="<?= (int) $catFilter ?>"><?php endif; ?>
      <div class="sbox">
        <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search Marketplace">
        <?php if ($searchQuery !== ''): ?><a class="clr" href="<?= e(bp_query_string(['q' => ''])) ?>" title="Clear">✕</a><?php endif; ?>
        <button type="submit">Search</button>
      </div>
    </form>
    <nav class="links">
      <a href="Index.html">Home</a>
      <a href="auth/login.php">Log in</a>
      <a href="auth/register.php" class="cta">Get Verified</a>
    </nav>
  </div>
</header>

<main>

<?php if ($view === 'market'): ?>

  <div class="note">👋 Browsing as a guest — look around freely. <a href="auth/login.php">Log in</a> when you're ready to message a seller.</div>

  <div class="head">
    <h1><?= $searchQuery !== '' ? 'Results for “' . e($searchQuery) . '”' : 'Browse market' ?></h1>
    <span class="count"><?= (int) $items->num_rows ?> listing<?= $items->num_rows === 1 ? '' : 's' ?></span>
  </div>

  <div class="cats">
    <a class="chip <?= empty($catFilter) ? 'active' : '' ?>" href="<?= e(bp_query_string(['cat' => 0])) ?>">All</a>
    <?php foreach ($topCategories as $c): ?>
      <a class="chip <?= $catFilter === $c['id'] ? 'active' : '' ?>" href="<?= e(bp_query_string(['cat' => $c['id']])) ?>"><?= e($c['icon']) ?> <?= e($c['name']) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="grid">
    <?php if ($items->num_rows === 0): ?>
      <div class="empty">
        <div class="ei">🔍</div>
        <h3><?= $searchQuery !== '' ? 'No items match “' . e($searchQuery) . '”' : 'No items yet' ?></h3>
        <p><?= $catFilter ? 'Try another category or clear your search.' : 'Check back soon — new listings are posted every day.' ?></p>
        <a href="?view=market">View all listings</a>
      </div>
    <?php endif; ?>
    <?php while ($it = $items->fetch_assoc()):
      $thumbs = bp_thumbs($it['images_concat'] ?? null);
      $qty    = bp_qty($it); ?>
      <div class="card">
        <a href="?view=item&id=<?= (int) $it['id'] ?>" class="media">
          <div class="fb"><?= e($it['icon']) ?></div>
          <?php if ($thumbs): ?>
            <img src="<?= e($thumbs[0]) ?>" alt="" loading="lazy" onerror="this.remove()">
          <?php endif; ?>
          <span class="tag"><?= $qty ?> left</span>
        </a>
        <div class="body">
          <div class="price">₱<?= number_format((float) $it['price'], 2) ?></div>
          <a class="title" href="?view=item&id=<?= (int) $it['id'] ?>"><?= e($it['title']) ?></a>
          <div class="meta">📍 <?= e($it['location']) ?></div>
          <div class="seller">
            <div class="sinfo">
              <span class="av"><?= e(strtoupper(substr($it['first_name'], 0, 1) . substr($it['last_name'], 0, 1))) ?></span>
              <span><b><?= e($it['first_name']) ?></b><?= e(time_ago($it['created_at'])) ?></span>
            </div>
            <a class="chat" href="auth/login.php?redirect_product=<?= (int) $it['id'] ?>">💬 Chat</a>
          </div>
        </div>
      </div>
    <?php endwhile; ?>
  </div>

<?php else: /* item */ ?>

  <a class="crumb" href="?view=market">← Back to market</a>
  <?php if (!$item): ?>
    <div class="grid"><div class="empty"><div class="ei">😕</div><h3>Item not found</h3><p>This listing may have been sold or removed.</p><a href="?view=market">Browse market</a></div></div>
  <?php else:
    $heroThumbs = bp_thumbs($item['images_concat'] ?? null);
    $itQty      = bp_qty($item); ?>
    <div class="detail">
      <div class="gal">
        <div class="hero">
          <?php if ($heroThumbs): ?>
            <img id="heroImg" src="<?= e($heroThumbs[0]) ?>" alt="">
          <?php else: ?>
            <div class="fb"><?= e($item['icon']) ?></div>
          <?php endif; ?>
        </div>
        <?php if (count($heroThumbs) > 1): ?>
          <div class="thumbs">
            <?php foreach ($heroThumbs as $i => $t): ?>
              <img src="<?= e($t) ?>" alt="" class="<?= $i === 0 ? 'on' : '' ?>" onclick="document.getElementById('heroImg').src=this.src;document.querySelectorAll('.thumbs img').forEach(x=>x.classList.remove('on'));this.classList.add('on');">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="info">
        <h2><?= e($item['title']) ?></h2>
        <span class="price">₱<?= number_format((float) $item['price'], 2) ?></span>
        <div class="meta">📍 <?= e($item['location']) ?> · posted <?= e(time_ago($item['created_at'])) ?></div>
        <div class="pills"><span class="pill">📦 <?= $itQty ?> available</span><span class="pill">✓ Verified seller</span></div>
        <div class="desc"><h4>Description</h4><?= nl2br(e($item['description'])) ?></div>
        <div class="sbar">
          <span class="av"><?= e(strtoupper(substr($item['first_name'], 0, 1) . substr($item['last_name'], 0, 1))) ?></span>
          <div><b><?= e($item['first_name'] . ' ' . $item['last_name']) ?></b><span>ID-verified member</span></div>
        </div>
        <a class="big" href="auth/login.php?redirect_product=<?= (int) $item['id'] ?>">💬 Message seller</a>
        <div class="safe">🛡️ Log in to chat. Meet in public places &amp; confirm in-app.</div>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

</main>
<footer>© 2026 SafeTrade · List online. Meet safely. Trade with confidence.</footer>
</body>
</html>
