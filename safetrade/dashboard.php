<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/image_helper.php';

$user   = require_login();
$userId = $user['id'];
$conn   = db();

/* =========================================================
   FIX: "Join via Code" always failed with "Could not look up that
   code" even for a correct code. The lookup_code AJAX handler used
   to live way down inside the ?view=groups HTML branch, AFTER the
   entire groups page (sidebar, group grid, modals, scripts) had
   already been echoed. So the fetch() response body wasn't pure
   JSON — it was the whole HTML page with JSON tacked onto the end —
   and the browser's r.json() call threw, which the modal reported
   as a generic failure regardless of whether the code was valid.
   Intercepting it here, before a single byte of HTML is output,
   guarantees the response is nothing but the JSON payload.
   ========================================================= */
if (($_GET['view'] ?? '') === 'groups' && isset($_GET['lookup_code'])) {
    header('Content-Type: application/json');
    $lookupCode = strtoupper(trim(clean_input($_GET['lookup_code'])));
    $lcStmt = $conn->prepare(
        'SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id=g.id AND m.status="approved") AS member_count
         FROM groups g WHERE g.join_code = ? LIMIT 1'
    );
    $lcStmt->bind_param('s', $lookupCode);
    $lcStmt->execute();
    $lcGroup = $lcStmt->get_result()->fetch_assoc();
    $lcStmt->close();

    if (!$lcGroup) {
        echo json_encode(['error' => 'No group found with that code.']);
        exit;
    }

    $lcGroupId = (int) $lcGroup['id'];
    $memChk = $conn->prepare('SELECT status FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
    $memChk->bind_param('ii', $lcGroupId, $userId);
    $memChk->execute();
    $memRow = $memChk->get_result()->fetch_assoc();
    $memChk->close();

    echo json_encode([
        'id'             => $lcGroupId,
        'name'           => $lcGroup['name'],
        'description'    => $lcGroup['description'] ?? '',
        'members'        => (int) $lcGroup['member_count'],
        'icon'           => $lcGroup['icon'],
        'photo'          => !empty($lcGroup['photo_mime']) ? rtrim(BASE_URL, '/') . '/image.php?type=group&id=' . $lcGroupId : null,
        'already_member' => $memRow && $memRow['status'] === 'approved',
    ]);
    exit;
}

/* =========================================================
   Guest -> logged-in hand-off. browse.php (public, no login
   required) sends a visitor to auth/login.php the moment they try
   to message a seller, WITHOUT writing anything to the database.
   Once they actually log in, auth/login.php redirects back here
   with ?want_chat=1&id=<product_id>, and THIS is the one and only
   place that "interested buyer" row actually gets created — the
   exact same insert the logged-in Chat/Message-seller button uses
   (see the 'express_interest' POST action further down, which this
   intentionally mirrors). Falls through to the normal view (e.g.
   the listing itself) if the product is gone, sold, or it's the
   visitor's own listing, instead of erroring.
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['want_chat'] ?? '') === '1') {
    $wantChatProductId = (int) ($_GET['id'] ?? 0);

    $stmt = $conn->prepare('SELECT id, seller_id, is_active FROM products WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $wantChatProductId);
    $stmt->execute();
    $wantChatProduct = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($wantChatProduct && (int) $wantChatProduct['is_active'] === 1 && (int) $wantChatProduct['seller_id'] !== $userId) {
        $stmt = $conn->prepare('SELECT id FROM transactions WHERE product_id=? AND buyer_id=? AND status NOT IN ("completed","cancelled") LIMIT 1');
        $stmt->bind_param('ii', $wantChatProductId, $userId);
        $stmt->execute();
        $existingWantChat = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existingWantChat) {
            $wantChatTxId = (int) $existingWantChat['id'];
        } else {
            $stmt = $conn->prepare("INSERT INTO transactions (product_id, buyer_id, seller_id, status) VALUES (?,?,?,'negotiating')");
            $stmt->bind_param('iii', $wantChatProductId, $userId, $wantChatProduct['seller_id']);
            $stmt->execute();
            $wantChatTxId = $conn->insert_id;
            $stmt->close();

            $wantChatMsg = 'Hi! Is this still available?';
            $stmt = $conn->prepare('INSERT INTO messages (transaction_id, sender_id, content) VALUES (?,?,?)');
            $stmt->bind_param('iis', $wantChatTxId, $userId, $wantChatMsg);
            $stmt->execute();
            $stmt->close();

            audit_log($userId, 'user.trade.interest_after_login', 'transaction', $wantChatTxId, 'product=' . $wantChatProductId);
            inbox_trade_event((int) $wantChatTxId, 'interest');
        }
        redirect('/dashboard.php?view=chat&t=' . $wantChatTxId);
    }
}

/* =========================================================
   Location: pull the user's last known coordinates (if any)
   ========================================================= */
$myLat = null;
$myLng = null;
$myLocationSource = null; // 'gps' | 'ip' | null
try {
    // location_source requires migration_add_location_source.sql — fall
    // back to the plain 2-column read below if that hasn't been run yet,
    // rather than fataling the whole page.
    $locStmt = $conn->prepare('SELECT latitude, longitude, location_source FROM users WHERE id = ?');
    $locStmt->bind_param('i', $userId);
    $locStmt->execute();
    $locRow = $locStmt->get_result()->fetch_assoc();
    $locStmt->close();
} catch (Throwable $e) {
    $locStmt = $conn->prepare('SELECT latitude, longitude FROM users WHERE id = ?');
    $locStmt->bind_param('i', $userId);
    $locStmt->execute();
    $locRow = $locStmt->get_result()->fetch_assoc();
    $locStmt->close();
}
if ($locRow && $locRow['latitude'] !== null && $locRow['longitude'] !== null) {
    $myLat = (float) $locRow['latitude'];
    $myLng = (float) $locRow['longitude'];
    $myLocationSource = $locRow['location_source'] ?? null;
}

/* =========================================================
   Small local helpers
   ========================================================= */
function tx_other_party(array $tx, int $me): array
{
    if ((int)$tx['buyer_id'] === $me) {
        return ['id' => (int)$tx['seller_id'], 'name' => $tx['seller_name'], 'role' => 'seller'];
    }
    return ['id' => (int)$tx['buyer_id'], 'name' => $tx['buyer_name'], 'role' => 'buyer'];
}

function fetch_transaction(mysqli $conn, int $txId, int $me): ?array
{
    // Scoped to (buyer_id = me OR seller_id = me) so only the two people
    // on this deal can ever fetch it — everyone else gets null.
    //
    // t.quantity / p.quantity require migration_add_product_quantity.sql
    // — fall back to the quantity-less select below if that hasn't been
    // run yet, instead of fataling every trade page.
    try {
        $stmt = $conn->prepare(
            'SELECT t.*, p.title AS product_title, p.icon AS product_icon, p.price AS product_price,
                    p.quantity AS product_quantity,
                    bu.first_name AS buyer_fn, bu.last_name AS buyer_ln,
                    se.first_name AS seller_fn, se.last_name AS seller_ln
             FROM transactions t
             JOIN products p ON p.id = t.product_id
             JOIN users bu ON bu.id = t.buyer_id
             JOIN users se ON se.id = t.seller_id
             WHERE t.id = ? AND (t.buyer_id = ? OR t.seller_id = ?) LIMIT 1'
        );
        $stmt->bind_param('iii', $txId, $me, $me);
        $stmt->execute();
        $tx = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (Throwable $e) {
        $stmt = $conn->prepare(
            'SELECT t.*, p.title AS product_title, p.icon AS product_icon, p.price AS product_price,
                    bu.first_name AS buyer_fn, bu.last_name AS buyer_ln,
                    se.first_name AS seller_fn, se.last_name AS seller_ln
             FROM transactions t
             JOIN products p ON p.id = t.product_id
             JOIN users bu ON bu.id = t.buyer_id
             JOIN users se ON se.id = t.seller_id
             WHERE t.id = ? AND (t.buyer_id = ? OR t.seller_id = ?) LIMIT 1'
        );
        $stmt->bind_param('iii', $txId, $me, $me);
        $stmt->execute();
        $tx = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$tx) return null;
    // Default to 1 whenever the migration hasn't been run, or a row
    // predates it — every listing behaves as single-unit until then.
    $tx['quantity']         = (int) ($tx['quantity'] ?? 1);
    $tx['product_quantity'] = (int) ($tx['product_quantity'] ?? $tx['quantity']);
    $tx['buyer_name']  = trim($tx['buyer_fn'] . ' ' . $tx['buyer_ln']);
    $tx['seller_name'] = trim($tx['seller_fn'] . ' ' . $tx['seller_ln']);
    return $tx;
}

/** Turn an "id|id|id" GROUP_CONCAT string (product_images.id) into a
 *  capped array of image.php URLs that serve the photo straight from
 *  the database — no filesystem path involved. */
function product_thumbs(?string $concat): array
{
    if (!$concat) return [];
    $ids = array_values(array_filter(explode('|', $concat), fn($p) => $p !== ''));
    $ids = array_slice($ids, 0, 6);
    return array_map(fn($id) => 'image.php?type=product&id=' . (int) $id, $ids);
}

/** Whether this PHP install can actually process uploaded photos (GD extension). */
function gd_available(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatetruecolor');
}

/** Whether $userId is an approved member of $groupId — gates posting/selling into a group. */
function user_is_group_member(mysqli $conn, int $groupId, int $userId): bool
{
    if ($groupId <= 0) return false;
    $stmt = $conn->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=? AND status='approved' LIMIT 1");
    $stmt->bind_param('ii', $groupId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool) $row;
}

/** Renders a group's photo if it has one (BLOB stored on the group row itself), else its emoji icon. */
function group_thumb_html(array $g, int $height = 80): string
{
    if (!empty($g['photo_mime'])) {
        return '<img src="image.php?type=group&id=' . (int) $g['id'] . '" alt="" style="width:100%;height:' . $height . 'px;object-fit:cover;border-radius:10px;">';
    }
    return '<div class="icon">' . e($g['icon'] ?? '👥') . '</div>';
}

/**
 * Validates, compresses, and stores uploaded product photos, inserting a
 * product_images row for each one that succeeds. Shared by post_item,
 * add_photos, and the vehicle listing flow so the upload rules (mime
 * type, size limit, compression) live in exactly one place.
 *
 * @param int $maxPhotos Total photo cap for this listing (existing item
 *                        flow uses 6; vehicle listings allow up to 20).
 * @return array{saved:int, errors:string[]}
 */
function save_uploaded_product_photos(mysqli $conn, int $productId, array $filesField, int $maxPhotos, int $alreadySaved = 0): array
{
    $errors = [];
    $saved  = 0;

    if (empty($filesField['name'][0])) {
        return ['saved' => 0, 'errors' => []];
    }

    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    $maxBytes    = MAX_UPLOAD_MB * 1024 * 1024;
    $fileCount   = count($filesField['name']);
    $slotsLeft   = max(0, $maxPhotos - $alreadySaved);

    for ($i = 0; $i < $fileCount && $saved < $slotsLeft; $i++) {
        $origName = $filesField['name'][$i] ?? ('photo ' . ($i + 1));
        if (($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = "$origName: upload failed (error code " . $filesField['error'][$i] . ')';
            continue;
        }

        $tmpName = $filesField['tmp_name'][$i];
        $size    = (int) $filesField['size'][$i];
        if ($size <= 0 || $size > $maxBytes) {
            $errors[] = "$origName: file is " . round($size / 1024 / 1024, 1) . 'MB, over the ' . MAX_UPLOAD_MB . 'MB limit';
            continue;
        }

        $info = @getimagesize($tmpName);
        if ($info === false || !in_array($info['mime'], $allowedMime, true)) {
            $errors[] = "$origName: not a recognized JPG/PNG/WEBP image";
            continue;
        }

        // Stored as a BLOB in product_images.image_data instead of a
        // file under uploads/products/ — this is what lets a real
        // deployment (no writable/persistent disk, or a fresh server
        // with an empty uploads folder) still have every photo, since
        // it travels with the database instead of the filesystem.
        $result = compress_image_to_blob($tmpName);

        if ($result['success']) {
            $emptyPath = ''; // path column kept for schema compatibility; unused for new uploads
            $imgStmt = $conn->prepare('INSERT INTO product_images (product_id, path, image_data, mime_type) VALUES (?, ?, ?, ?)');
            $imgStmt->bind_param('isss', $productId, $emptyPath, $result['data'], $result['mime']);
            $imgStmt->execute();
            $imgStmt->close();
            $saved++;
        } else {
            $errors[] = "$origName: " . ($result['error'] ?? 'could not be processed');
        }
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/**
 * Saves an uploaded group photo (single file), compressed the same way
 * product photos are. Returns ['data' => ..., 'mime' => ...] to store
 * as a BLOB on the group row itself, or null if no valid file was
 * uploaded — no file is written to disk.
 */
function save_uploaded_group_photo(array $file): ?array
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (!gd_available()) {
        return null;
    }
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    $maxBytes    = MAX_UPLOAD_MB * 1024 * 1024;
    $size        = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return null;
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info['mime'], $allowedMime, true)) {
        return null;
    }
    $result = compress_image_to_blob($file['tmp_name']);
    return $result['success'] ? ['data' => $result['data'], 'mime' => $result['mime']] : null;
}

/** Read-only lookup: which product_id/vehicle_type maps to which existing marketplace subcategory. */
function vehicle_type_to_category_id(array $categoriesFlat, string $vehicleType): int
{
    $map = [
        'Car' => 'Cars', 'SUV' => 'Cars',
        'Pickup Truck' => 'Trucks', 'Truck' => 'Trucks',
        'Van' => 'Vans', 'Minivan' => 'Vans',
        'Motorcycle' => 'Motorcycles',
        'Bus' => 'Other vehicles', 'RV/Camper' => 'Other vehicles',
        'Trailer' => 'Other vehicles', 'Other' => 'Other vehicles',
    ];
    $targetName = $map[$vehicleType] ?? 'Other vehicles';

    $vehiclesTop = null;
    foreach ($categoriesFlat as $c) {
        if ($c['parent_id'] === null && $c['name'] === 'Vehicles') { $vehiclesTop = $c; break; }
    }
    foreach ($categoriesFlat as $c) {
        if ($vehiclesTop && $c['parent_id'] === $vehiclesTop['id'] && $c['name'] === $targetName) {
            return $c['id'];
        }
    }
    // Migration not run yet / subcategories missing — fall back to the
    // top-level "Vehicles" category itself so posting still works.
    return $vehiclesTop ? $vehiclesTop['id'] : 0;
}

/** Pulls the structured vehicle_details fields (and free-form extras/features) out of $_POST. */
function extract_vehicle_fields_from_post(): array
{
    $get = fn(string $k) => clean_input($_POST[$k] ?? '');

    // Conditional fields (Electric / Pickup / Truck / Motorcycle-specific)
    // are stored together as JSON so new ones can be added later without
    // another migration.
    $extra = [];
    foreach (['battery_capacity', 'range', 'charging_type', 'charging_time', 'battery_health',
              'bed_length', 'cab_type', 'payload', 'towing_capacity', 'motorcycle_type'] as $k) {
        $v = clean_input($_POST[$k] ?? '');
        if ($v !== '') $extra[$k] = $v;
    }

    $features = [];
    if (!empty($_POST['features']) && is_array($_POST['features'])) {
        foreach ($_POST['features'] as $f) {
            $f = clean_input((string) $f);
            if ($f !== '') $features[] = $f;
        }
    }

    $mileageRaw = preg_replace('/[^\d]/', '', $get('mileage'));
    $year       = $get('year');

    return [
        'vehicle_type'         => $get('vehicle_type'),
        'make'                 => $get('make'),
        'model'                => $get('model'),
        'year'                 => $year !== '' ? (int) $year : null,
        'trim'                 => $get('trim'),
        'body_type'            => $get('body_type'),
        'mileage'              => $mileageRaw !== '' ? (int) $mileageRaw : null,
        'mileage_unit'         => in_array($get('mileage_unit'), ['km', 'mi'], true) ? $get('mileage_unit') : 'km',
        'fuel_type'            => $get('fuel_type'),
        'engine_size'          => $get('engine_size'),
        'cylinders'            => $get('cylinders'),
        'horsepower'           => $get('horsepower'),
        'transmission'         => $get('transmission'),
        'drivetrain'           => $get('drivetrain'),
        'exterior_color'       => $get('exterior_color'),
        'interior_color'       => $get('interior_color'),
        'ownership'            => $get('ownership'),
        'service_history'      => $get('service_history'),
        'num_keys'             => $get('num_keys') !== '' ? (int) $get('num_keys') : null,
        'accident_history'     => $get('accident_history'),
        'damage_status'        => $get('damage_status'),
        'vin'                  => $get('vin'),
        'registration_status'  => $get('registration_status'),
        'registration_expiry'  => $get('registration_expiry') !== '' ? $get('registration_expiry') : null,
        'negotiable'           => !empty($_POST['negotiable']) ? 1 : 0,
        'features'             => json_encode($features),
        'extra_fields'         => json_encode($extra),
    ];
}

/** Insert-or-update the vehicle_details row for a product (requires migration_add_vehicle_details.sql). */
function upsert_vehicle_details(mysqli $conn, int $productId, array $f): void
{
    $stmt = $conn->prepare(
        'INSERT INTO vehicle_details
            (product_id, vehicle_type, make, model, year, trim, body_type, mileage, mileage_unit,
             fuel_type, engine_size, cylinders, horsepower, transmission, drivetrain,
             exterior_color, interior_color, ownership, service_history, num_keys,
             accident_history, damage_status, vin, registration_status, registration_expiry,
             negotiable, features, extra_fields)
         VALUES (?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?)
         ON DUPLICATE KEY UPDATE
            vehicle_type=VALUES(vehicle_type), make=VALUES(make), model=VALUES(model), year=VALUES(year),
            trim=VALUES(trim), body_type=VALUES(body_type), mileage=VALUES(mileage), mileage_unit=VALUES(mileage_unit),
            fuel_type=VALUES(fuel_type), engine_size=VALUES(engine_size), cylinders=VALUES(cylinders), horsepower=VALUES(horsepower),
            transmission=VALUES(transmission), drivetrain=VALUES(drivetrain),
            exterior_color=VALUES(exterior_color), interior_color=VALUES(interior_color),
            ownership=VALUES(ownership), service_history=VALUES(service_history), num_keys=VALUES(num_keys),
            accident_history=VALUES(accident_history), damage_status=VALUES(damage_status), vin=VALUES(vin),
            registration_status=VALUES(registration_status), registration_expiry=VALUES(registration_expiry),
            negotiable=VALUES(negotiable), features=VALUES(features), extra_fields=VALUES(extra_fields)'
    );
    $stmt->execute([
        $productId, $f['vehicle_type'], $f['make'], $f['model'], $f['year'], $f['trim'], $f['body_type'],
        $f['mileage'], $f['mileage_unit'], $f['fuel_type'], $f['engine_size'], $f['cylinders'], $f['horsepower'],
        $f['transmission'], $f['drivetrain'], $f['exterior_color'], $f['interior_color'], $f['ownership'],
        $f['service_history'], $f['num_keys'], $f['accident_history'], $f['damage_status'], $f['vin'],
        $f['registration_status'], $f['registration_expiry'], $f['negotiable'], $f['features'], $f['extra_fields'],
    ]);
    $stmt->close();
}

/**
 * Links a just-posted product to a group (products.group_id) and drops
 * a group_posts row for it, so it shows up in that group's feed exactly
 * like a Facebook "for sale" group post. No-ops quietly if the group
 * feed migration hasn't been run yet, or the seller isn't actually an
 * approved member of that group.
 */
function link_product_to_group(mysqli $conn, int $productId, int $groupId, int $userId): void
{
    if ($groupId <= 0 || !user_is_group_member($conn, $groupId, $userId)) {
        return;
    }
    try {
        $stmt = $conn->prepare('UPDATE products SET group_id=? WHERE id=?');
        $stmt->bind_param('ii', $groupId, $productId);
        $stmt->execute();
        $stmt->close();

        // Avoid duplicate feed posts if this product was already linked
        // (e.g. re-posting a vehicle draft that was linked earlier).
        $chk = $conn->prepare('SELECT id FROM group_posts WHERE product_id=? LIMIT 1');
        $chk->bind_param('i', $productId);
        $chk->execute();
        $already = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$already) {
            $gp = $conn->prepare('INSERT INTO group_posts (group_id, user_id, product_id) VALUES (?,?,?)');
            $gp->bind_param('iii', $groupId, $userId, $productId);
            $gp->execute();
            $gp->close();
        }
    } catch (Throwable $e) {
        // group_posts / products.group_id require migration_add_group_feed.sql
        error_log('[SafeTrade] link_product_to_group skipped: ' . $e->getMessage());
    }
}

/** Render one Carousell-style product card (photo strip + price + seller + Chat). */
function product_card(array $it, int $userId): string
{
    $thumbs = product_thumbs($it['images_concat'] ?? null);
    $isOwn  = (int) $it['seller_id'] === $userId;
    // Defaults to 1 whenever migration_add_product_quantity.sql hasn't
    // run yet, or the row predates it — same single-unit behavior as
    // before quantity tracking existed.
    $qty    = isset($it['quantity']) ? (int) $it['quantity'] : 1;
    ob_start();
    ?>
    <div class="p-card">
      <a href="?view=item&id=<?= (int)$it['id'] ?>" class="p-media">
        <?php if ($thumbs): ?>
          <div class="photo-strip">
            <?php foreach ($thumbs as $src): ?>
              <img src="<?= e($src) ?>" alt="" loading="lazy">
            <?php endforeach; ?>
          </div>
          <?php if (count($thumbs) > 1): ?>
            <div class="dots">
              <?php foreach ($thumbs as $idx => $_): ?>
                <span class="dot<?= $idx === 0 ? ' active' : '' ?>"></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="p-media-fallback"><?= e($it['icon']) ?></div>
        <?php endif; ?>
      </a>
      <div class="p-body">
        <div class="p-price">₱<?= number_format((float)$it['price'], 2) ?></div>
        <div class="sub" style="font-size:11px;color:var(--ink-soft);margin-top:1px;"><?= $qty ?> left</div>
        <a class="p-title" href="?view=item&id=<?= (int)$it['id'] ?>"><?= e($it['title']) ?></a>
        <div class="p-meta">
          <?= e($it['location']) ?>
          <?php if (isset($it['distance_km']) && $it['distance_km'] !== null): ?>
            · <?= number_format((float)$it['distance_km'], 1) ?> km away
          <?php endif; ?>
        </div>
        <div class="p-seller">
          <div class="p-seller-info hp-trigger" data-uid="<?= (int)$it['seller_id'] ?>">
            <span class="p-avatar"><?= e(strtoupper(substr($it['first_name'], 0, 1) . substr($it['last_name'], 0, 1))) ?></span>
            <span><?= e($it['first_name']) ?><br><small><?= e(time_ago($it['created_at'])) ?></small></span>
          </div>
          <?php if (!$isOwn): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="express_interest">
              <input type="hidden" name="product_id" value="<?= (int)$it['id'] ?>">
              <button class="chat-pill" type="submit">💬 Chat</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/* =========================================================
   Categories: loaded as a flat array with parent_id + icon (added by
   migration_add_category_hierarchy.sql) so the Sell flow, the vehicle
   POST handlers, and Browse Market can all use the category tree.
   Loaded before POST handling since post_vehicle/save_vehicle_draft
   need it too. Falls back to a flat, icon-less list if that migration
   hasn't been run yet, instead of fataling the whole page.
   ========================================================= */
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

/* =========================================================
   POST actions (PRG pattern)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'create_group': {
            // Group creation now only asks for a name, an optional photo,
            // and an optional description — no category. The photo is
            // compressed the same way product photos are; if none is
            // uploaded (or GD isn't enabled), the group falls back to its
            // default emoji icon everywhere it's displayed.
            $name  = clean_input($_POST['name'] ?? '');
            $desc  = clean_input($_POST['description'] ?? '');
            $icon  = clean_input($_POST['icon'] ?? '👥');
            if ($name === '') {
                flash_set('error', 'Group name is required.');
                redirect('/dashboard.php?view=create-group');
            }

            $photo = null;
            if (!empty($_FILES['photo']['name'])) {
                $photo = save_uploaded_group_photo($_FILES['photo']);
            }

            try {
                // photo_data/photo_mime require migration_add_blob_image_storage.sql
                // — fall back to the photo-less insert below if that
                // hasn't run yet.
                $stmt = $conn->prepare('INSERT INTO groups (name, description, category, icon, photo_data, photo_mime, owner_id) VALUES (?,?,NULL,?,?,?,?)');
                $photoData = $photo['data'] ?? null;
                $photoMime = $photo['mime'] ?? null;
                $stmt->bind_param('sssssi', $name, $desc, $icon, $photoData, $photoMime, $userId);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $e) {
                $stmt = $conn->prepare("INSERT INTO groups (name, description, category, icon, owner_id) VALUES (?, ?, 'General', ?, ?)");
                $stmt->bind_param('sssi', $name, $desc, $icon, $userId);
                $stmt->execute();
                $stmt->close();
            }
            $groupId = $conn->insert_id;

            $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id, role, status, joined_at) VALUES (?, ?, 'owner', 'approved', NOW())");
            $stmt->bind_param('ii', $groupId, $userId);
            $stmt->execute();
            $stmt->close();

            audit_log($userId, 'user.group.created', 'group', $groupId);
            flash_set('success', 'Group created — it now shows up under Suggested Groups on Home.');
            redirect('/dashboard.php?view=group&id=' . $groupId);
        }

        case 'join_group': {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $stmt = $conn->prepare('SELECT id FROM group_members WHERE group_id=? AND user_id=?');
            $stmt->bind_param('ii', $groupId, $userId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists && $groupId) {
                $stmt = $conn->prepare("INSERT INTO group_members (group_id, user_id, role, status, joined_at) VALUES (?, ?, 'member', 'approved', NOW())");
                $stmt->bind_param('ii', $groupId, $userId);
                $stmt->execute();
                $stmt->close();
                flash_set('success', 'You have joined the group!');
            }
            redirect('/dashboard.php?view=group&id=' . $groupId);
        }

        case 'join_via_code': {
            $code = strtoupper(trim(clean_input($_POST['join_code'] ?? '')));
            if ($code === '') {
                flash_set('error', 'Please enter a group code.');
                redirect('/dashboard.php?view=groups');
            }
            $stmt = $conn->prepare('SELECT id, name FROM groups WHERE join_code = ? LIMIT 1');
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $codeGroup = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$codeGroup) {
                flash_set('error', 'Invalid group code. Please check and try again.');
                redirect('/dashboard.php?view=groups');
            }
            $gid = (int)$codeGroup['id'];
            // Check already member
            $chk = $conn->prepare('SELECT id, status FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
            $chk->bind_param('ii', $gid, $userId);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($existing && $existing['status'] === 'approved') {
                flash_set('success', 'You are already a member of "' . $codeGroup['name'] . '".');
                redirect('/dashboard.php?view=group&id=' . $gid);
            }
            if ($existing) {
                // Update existing rejected/pending row to approved
                $upd = $conn->prepare("UPDATE group_members SET status='approved', joined_at=NOW() WHERE group_id=? AND user_id=?");
                $upd->bind_param('ii', $gid, $userId);
                $upd->execute();
                $upd->close();
            } else {
                $ins = $conn->prepare("INSERT INTO group_members (group_id, user_id, role, status, joined_at) VALUES (?, ?, 'member', 'approved', NOW())");
                $ins->bind_param('ii', $gid, $userId);
                $ins->execute();
                $ins->close();
            }
            audit_log($userId, 'user.group.joined_via_code', 'group', $gid);
            flash_set('success', 'You joined "' . $codeGroup['name'] . '" via group code!');
            redirect('/dashboard.php?view=group&id=' . $gid);
        }

        case 'regenerate_code': {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            // Only members (including owner) can regenerate
            $chk = $conn->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=? AND status='approved' LIMIT 1");
            $chk->bind_param('ii', $groupId, $userId);
            $chk->execute();
            $isMember = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($isMember && $groupId) {
                $newCode = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
                try {
                    $upd = $conn->prepare('UPDATE groups SET join_code=? WHERE id=?');
                    $upd->bind_param('si', $newCode, $groupId);
                    $upd->execute();
                    $upd->close();
                    flash_set('success', 'New group code generated: ' . $newCode);
                } catch (Throwable $e) {
                    flash_set('error', 'Could not generate code — run migration_add_group_join_code.sql first.');
                }
            }
            redirect('/dashboard.php?view=group&id=' . $groupId);
        }

        case 'delete_group_by_owner': {
            // Only the group's creator can delete it. Deleting removes
            // every piece of group data (feed posts + their comments/
            // likes, memberships) and the group row itself. Because
            // groups.join_code has a UNIQUE index and lives only on this
            // row, deleting the group automatically frees that code up
            // to be issued to a future group — nothing extra to clean up.
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $own = $conn->prepare('SELECT id FROM groups WHERE id=? AND owner_id=? LIMIT 1');
            $own->bind_param('ii', $groupId, $userId);
            $own->execute();
            $ownsGroup = $own->get_result()->fetch_assoc();
            $own->close();

            if ($ownsGroup) {
                try {
                    // group_posts / group_post_comments / group_post_likes
                    // have no FK cascade defined, so clean those up by
                    // hand before removing the group row itself.
                    $postIds = [];
                    $pRes = $conn->prepare('SELECT id FROM group_posts WHERE group_id=?');
                    $pRes->bind_param('i', $groupId);
                    $pRes->execute();
                    $prows = $pRes->get_result();
                    while ($prow = $prows->fetch_assoc()) $postIds[] = (int) $prow['id'];
                    $pRes->close();

                    foreach ($postIds as $pid) {
                        $d1 = $conn->prepare('DELETE FROM group_post_comments WHERE post_id=?');
                        $d1->bind_param('i', $pid); $d1->execute(); $d1->close();
                        $d2 = $conn->prepare('DELETE FROM group_post_likes WHERE post_id=?');
                        $d2->bind_param('i', $pid); $d2->execute(); $d2->close();
                    }
                    $d3 = $conn->prepare('DELETE FROM group_posts WHERE group_id=?');
                    $d3->bind_param('i', $groupId); $d3->execute(); $d3->close();

                    $d4 = $conn->prepare('DELETE FROM group_members WHERE group_id=?');
                    $d4->bind_param('i', $groupId); $d4->execute(); $d4->close();

                    // products.group_id is ON DELETE SET NULL, so any
                    // listings posted into this group just detach from
                    // it instead of being deleted.
                    $d5 = $conn->prepare('DELETE FROM groups WHERE id=? AND owner_id=?');
                    $d5->bind_param('ii', $groupId, $userId);
                    $d5->execute();
                    $d5->close();

                    audit_log($userId, 'user.group.deleted', 'group', $groupId);
                    flash_set('success', 'Group deleted — its invite code is free to be reused.');
                } catch (Throwable $e) {
                    error_log('[SafeTrade] delete_group_by_owner failed: ' . $e->getMessage());
                    flash_set('error', 'Could not delete the group — please try again.');
                }
            } else {
                flash_set('error', 'Only the group creator can delete this group.');
            }
            redirect('/dashboard.php?view=groups');
        }

        case 'leave_group': {
            // A regular member leaving. Owners can't use this — they'd
            // leave the group without anyone in charge of it — so they
            // have to use "Delete this group" instead, which is the
            // explicit, all-or-nothing action.
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $mStmt = $conn->prepare('SELECT role FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
            $mStmt->bind_param('ii', $groupId, $userId);
            $mStmt->execute();
            $membership = $mStmt->get_result()->fetch_assoc();
            $mStmt->close();

            if ($membership && $membership['role'] === 'owner') {
                flash_set('error', "As the creator, you can't leave — delete the group instead if you want it gone.");
                redirect('/dashboard.php?view=group&id=' . $groupId);
            }

            if ($membership) {
                try {
                    // Remove this member's own posts from the group feed
                    // (and any comments/likes on THOSE posts, since
                    // group_posts/comments/likes have no FK cascade),
                    // then their membership row itself. Posts by other
                    // members, and this member's comments/likes on other
                    // people's posts, are left alone — only "their post"
                    // is what's supposed to go.
                    $postIds = [];
                    $pRes = $conn->prepare('SELECT id FROM group_posts WHERE group_id=? AND user_id=?');
                    $pRes->bind_param('ii', $groupId, $userId);
                    $pRes->execute();
                    $prows = $pRes->get_result();
                    while ($prow = $prows->fetch_assoc()) $postIds[] = (int) $prow['id'];
                    $pRes->close();

                    foreach ($postIds as $pid) {
                        $d1 = $conn->prepare('DELETE FROM group_post_comments WHERE post_id=?');
                        $d1->bind_param('i', $pid); $d1->execute(); $d1->close();
                        $d2 = $conn->prepare('DELETE FROM group_post_likes WHERE post_id=?');
                        $d2->bind_param('i', $pid); $d2->execute(); $d2->close();
                    }
                    if ($postIds) {
                        $d3 = $conn->prepare('DELETE FROM group_posts WHERE group_id=? AND user_id=?');
                        $d3->bind_param('ii', $groupId, $userId);
                        $d3->execute();
                        $d3->close();
                    }

                    $d4 = $conn->prepare('DELETE FROM group_members WHERE group_id=? AND user_id=?');
                    $d4->bind_param('ii', $groupId, $userId);
                    $d4->execute();
                    $d4->close();

                    audit_log($userId, 'user.group.left', 'group', $groupId, count($postIds) . ' post(s) removed');
                    flash_set('success', 'You left the group — your posts in it were removed.');
                } catch (Throwable $e) {
                    error_log('[SafeTrade] leave_group failed: ' . $e->getMessage());
                    flash_set('error', 'Could not leave the group — please try again.');
                }
            }
            redirect('/dashboard.php?view=groups');
        }

        case 'approve_member':
        case 'reject_member': {
            $memberId = (int) ($_POST['member_id'] ?? 0);
            $groupId  = (int) ($_POST['group_id'] ?? 0);
            $owner = $conn->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=? AND role='owner'");
            $owner->bind_param('ii', $groupId, $userId);
            $owner->execute();
            if ($owner->get_result()->fetch_assoc()) {
                $newStatus = $action === 'approve_member' ? 'approved' : 'rejected';
                $stmt = $conn->prepare("UPDATE group_members SET status=?, joined_at=IF(?='approved',NOW(),joined_at) WHERE id=? AND group_id=?");
                $stmt->bind_param('ssii', $newStatus, $newStatus, $memberId, $groupId);
                $stmt->execute();
                $stmt->close();
                flash_set('success', 'Membership request updated.');
            }
            $owner->close();
            redirect('/dashboard.php?view=group&id=' . $groupId);
        }

        case 'create_group_post': {
            // A plain text post to the group's feed (the Facebook-style
            // "Write something…" box). Only approved members can post.
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $content = trim(clean_input($_POST['content'] ?? ''));
            if ($groupId > 0 && $content !== '' && user_is_group_member($conn, $groupId, $userId)) {
                try {
                    $stmt = $conn->prepare('INSERT INTO group_posts (group_id, user_id, content) VALUES (?,?,?)');
                    $stmt->bind_param('iis', $groupId, $userId, $content);
                    $stmt->execute();
                    $stmt->close();
                    audit_log($userId, 'user.group.post', 'group', $groupId);
                } catch (Throwable $e) {
                    flash_set('error', 'Posting to groups isn\'t available yet — ask an admin to run migration_add_group_feed.sql.');
                }
            }
            redirect('/dashboard.php?view=group&id=' . $groupId);
        }

        case 'toggle_group_post_like': {
            // "Like" a group post — a second click un-likes it, same as
            // Facebook. Scoped so a member can only ever affect their own
            // like row (post_id, user_id) is a unique key.
            $postId  = (int) ($_POST['post_id'] ?? 0);
            $groupId = (int) ($_POST['group_id'] ?? 0);
            try {
                $chk = $conn->prepare('SELECT id FROM group_post_likes WHERE post_id=? AND user_id=?');
                $chk->bind_param('ii', $postId, $userId);
                $chk->execute();
                $existing = $chk->get_result()->fetch_assoc();
                $chk->close();

                if ($existing) {
                    $del = $conn->prepare('DELETE FROM group_post_likes WHERE id=?');
                    $del->bind_param('i', $existing['id']);
                    $del->execute();
                    $del->close();
                } else {
                    $ins = $conn->prepare('INSERT INTO group_post_likes (post_id, user_id) VALUES (?,?)');
                    $ins->bind_param('ii', $postId, $userId);
                    $ins->execute();
                    $ins->close();
                }
            } catch (Throwable $e) {
                // group_post_likes requires migration_add_group_feed.sql
            }
            redirect('/dashboard.php?view=group&id=' . $groupId . '#post-' . $postId);
        }

        case 'comment_group_post': {
            // Comments double as "messaging" the poster right on their
            // post — the same way replying in a Facebook group works.
            $postId  = (int) ($_POST['post_id'] ?? 0);
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $content = trim(clean_input($_POST['content'] ?? ''));
            if ($postId > 0 && $content !== '' && user_is_group_member($conn, $groupId, $userId)) {
                try {
                    $stmt = $conn->prepare('INSERT INTO group_post_comments (post_id, user_id, content) VALUES (?,?,?)');
                    $stmt->bind_param('iis', $postId, $userId, $content);
                    $stmt->execute();
                    $stmt->close();
                } catch (Throwable $e) {
                    flash_set('error', 'Comments aren\'t available yet — ask an admin to run migration_add_group_feed.sql.');
                }
            }
            redirect('/dashboard.php?view=group&id=' . $groupId . '#post-' . $postId);
        }

        case 'post_item': {
            $title = clean_input($_POST['title'] ?? '');
            $price = (float) ($_POST['price'] ?? 0);
            $qty   = max(1, (int) ($_POST['quantity'] ?? 1));
            $desc  = clean_input($_POST['description'] ?? '');
            $loc   = clean_input($_POST['location'] ?? '');
            $catId = (int) ($_POST['category_id'] ?? 0);
            $icon  = clean_input($_POST['icon'] ?? '📦');
            $groupId  = (int) ($_POST['group_id'] ?? 0);
            $sellType = in_array($_POST['sell_type'] ?? '', ['item', 'vehicle'], true) ? $_POST['sell_type'] : 'item';
            if ($title === '' || $catId <= 0) {
                flash_set('error', 'Please fill in a title and category.');
                redirect('/dashboard.php?view=sell&type=' . $sellType . ($groupId ? '&group_id=' . $groupId : ''));
            }
            try {
                // quantity requires migration_add_product_quantity.sql —
                // fall back to the quantity-less insert below (always
                // single-unit) if that hasn't been run yet.
                $stmt = $conn->prepare(
                    "INSERT INTO products (seller_id, category_id, title, description, price, quantity, `condition`, location, icon, is_active)
                     VALUES (?,?,?,?,?,?, 'used', ?, ?, 1)"
                );
                $stmt->bind_param('iissdiss', $userId, $catId, $title, $desc, $price, $qty, $loc, $icon);
                $stmt->execute();
            } catch (Throwable $e) {
                $stmt = $conn->prepare(
                    "INSERT INTO products (seller_id, category_id, title, description, price, `condition`, location, icon, is_active)
                     VALUES (?,?,?,?,?, 'used', ?, ?, 1)"
                );
                $stmt->bind_param('iissdss', $userId, $catId, $title, $desc, $price, $loc, $icon);
                $stmt->execute();
            }
            $productId = $conn->insert_id;
            $stmt->close();

            // If this was posted from inside a group's "Sell an item here"
            // link, tag the listing to the group and drop it into the
            // group's feed — same wizard, same listing, just also visible
            // to the group like a Facebook "for sale" post.
            link_product_to_group($conn, $productId, $groupId, $userId);

            /* ---- Photos (optional, up to 6, compressed before storage) ---- */
            $photoResult = save_uploaded_product_photos($conn, $productId, $_FILES['photos'] ?? [], 6);
            $photoErrors = $photoResult['errors'];

            audit_log($userId, 'user.product.posted', 'product', $productId);
            if ($photoErrors) {
                flash_set('error', 'Item posted, but some photos didn\'t save — ' . implode('; ', $photoErrors));
            } else {
                flash_set('success', $groupId ? 'Your item is live and posted to the group.' : 'Your item is live.');
            }
            redirect($groupId ? '/dashboard.php?view=group&id=' . $groupId : '/dashboard.php?view=listing&id=' . $productId);
        }

        case 'post_vehicle': {
            // Final "Post Vehicle" step from the preview screen — the
            // only action that actually publishes a vehicle listing.
            // Works both for a brand-new listing and for turning an
            // existing draft (draft_id) live.
            $draftId = (int) ($_POST['draft_id'] ?? 0);
            $groupId = (int) ($_POST['group_id'] ?? 0);

            $title = clean_input($_POST['title'] ?? '');
            $price = (float) ($_POST['price'] ?? 0);
            $desc  = clean_input($_POST['description'] ?? '');
            $loc   = clean_input($_POST['location'] ?? '');
            $condition = clean_input($_POST['condition'] ?? '') ?: 'used';
            $vf    = extract_vehicle_fields_from_post();
            $catId = $vf['vehicle_type'] !== '' ? vehicle_type_to_category_id($categoriesFlat, $vf['vehicle_type']) : 0;

            $missing = [];
            if ($vf['vehicle_type'] === '') $missing[] = 'vehicle type';
            if ($title === '') $missing[] = 'title';
            if ($price <= 0) $missing[] = 'price';
            if ($loc === '') $missing[] = 'location';
            if ($vf['make'] === '') $missing[] = 'make';
            if ($vf['model'] === '') $missing[] = 'model';
            if (!$vf['year']) $missing[] = 'year';
            if ($vf['mileage'] === null) $missing[] = 'mileage';

            if ($missing || $catId <= 0) {
                flash_set('error', 'Please fill in before publishing: ' . implode(', ', $missing));
                redirect('/dashboard.php?view=sell&type=vehicle' . ($draftId ? '&draft=' . $draftId : '') . ($groupId ? '&group_id=' . $groupId : ''));
            }

            if ($draftId) {
                $own = $conn->prepare('SELECT id FROM products WHERE id=? AND seller_id=? AND is_draft=1 LIMIT 1');
                $own->bind_param('ii', $draftId, $userId);
                $own->execute();
                $ownRow = $own->get_result()->fetch_assoc();
                $own->close();
                if (!$ownRow) {
                    flash_set('error', 'Draft not found.');
                    redirect('/dashboard.php?view=my-listings');
                }
                $stmt = $conn->prepare(
                    "UPDATE products SET category_id=?, title=?, description=?, price=?, `condition`=?, location=?, is_draft=0, is_active=1 WHERE id=?"
                );
                $stmt->execute([$catId, $title, $desc, $price, $condition, $loc, $draftId]);
                $stmt->close();
                $productId = $draftId;
            } else {
                $icon = clean_input($_POST['icon'] ?? '🚗');
                $stmt = $conn->prepare(
                    "INSERT INTO products (seller_id, category_id, title, description, price, `condition`, location, icon, is_active, is_draft)
                     VALUES (?,?,?,?,?,?,?,?,1,0)"
                );
                $stmt->execute([$userId, $catId, $title, $desc, $price, $condition, $loc, $icon]);
                $productId = $conn->insert_id;
                $stmt->close();
            }

            try { upsert_vehicle_details($conn, $productId, $vf); } catch (Throwable $e) { error_log('[SafeTrade] vehicle_details upsert skipped: ' . $e->getMessage()); }

            // Same group-linking as post_item — works whether this vehicle
            // started life as a draft or is brand new.
            link_product_to_group($conn, $productId, $groupId, $userId);

            $existingPhotoCount = 0;
            $cp = $conn->prepare('SELECT COUNT(*) c FROM product_images WHERE product_id=?');
            $cp->bind_param('i', $productId);
            $cp->execute();
            $existingPhotoCount = (int) ($cp->get_result()->fetch_assoc()['c'] ?? 0);
            $cp->close();

            $photoResult = save_uploaded_product_photos($conn, $productId, $_FILES['photos'] ?? [], 20, $existingPhotoCount);
            $photoErrors = $photoResult['errors'];

            if ($existingPhotoCount + $photoResult['saved'] === 0) {
                flash_set('error', 'Your vehicle needs at least one photo — add one from the listing page to finish.');
            } elseif ($photoErrors) {
                flash_set('error', 'Vehicle posted, but some photos didn\'t save — ' . implode('; ', $photoErrors));
            } else {
                flash_set('success', $groupId ? 'Your vehicle listing is live and posted to the group.' : 'Your vehicle listing is live.');
            }

            audit_log($userId, 'user.vehicle.posted', 'product', $productId);
            redirect($groupId ? '/dashboard.php?view=group&id=' . $groupId : '/dashboard.php?view=listing&id=' . $productId);
        }

        case 'save_vehicle_draft': {
            // Lets the seller leave and come back later without losing
            // progress — works from either step of the wizard since
            // both steps live in the same <form>. Only a title is
            // required so an early, half-filled draft can still save.
            $draftId = (int) ($_POST['draft_id'] ?? 0);
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $title   = clean_input($_POST['title'] ?? '');
            $price   = (float) ($_POST['price'] ?? 0);
            $desc    = clean_input($_POST['description'] ?? '');
            $loc     = clean_input($_POST['location'] ?? '');
            $condition = clean_input($_POST['condition'] ?? '') ?: 'used';
            $vf      = extract_vehicle_fields_from_post();
            $catId   = $vf['vehicle_type'] !== '' ? vehicle_type_to_category_id($categoriesFlat, $vf['vehicle_type']) : 0;

            if ($title === '') {
                flash_set('error', 'Give the listing a title before saving a draft.');
                redirect('/dashboard.php?view=sell&type=vehicle' . ($draftId ? '&draft=' . $draftId : '') . ($groupId ? '&group_id=' . $groupId : ''));
            }

            // No vehicle type chosen yet — fall back to the top-level
            // "Vehicles" category so the row is still valid; it'll be
            // corrected automatically once a vehicle type is picked.
            if ($catId <= 0) {
                foreach ($categoriesFlat as $c) {
                    if ($c['parent_id'] === null && $c['name'] === 'Vehicles') { $catId = $c['id']; break; }
                }
            }

            $icon = clean_input($_POST['icon'] ?? '🚗');

            if ($draftId) {
                $own = $conn->prepare('SELECT id FROM products WHERE id=? AND seller_id=? AND is_draft=1 LIMIT 1');
                $own->bind_param('ii', $draftId, $userId);
                $own->execute();
                $ownRow = $own->get_result()->fetch_assoc();
                $own->close();
                if (!$ownRow) {
                    flash_set('error', 'Draft not found.');
                    redirect('/dashboard.php?view=my-listings');
                }
                $stmt = $conn->prepare(
                    "UPDATE products SET category_id=?, title=?, description=?, price=?, `condition`=?, location=?, icon=? WHERE id=?"
                );
                $stmt->execute([$catId, $title, $desc, $price, $condition, $loc, $icon, $draftId]);
                $stmt->close();
                $productId = $draftId;
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO products (seller_id, category_id, title, description, price, `condition`, location, icon, is_active, is_draft)
                     VALUES (?,?,?,?,?,?,?,?,0,1)"
                );
                $stmt->execute([$userId, $catId, $title, $desc, $price, $condition, $loc, $icon]);
                $productId = $conn->insert_id;
                $stmt->close();
            }

            try { upsert_vehicle_details($conn, $productId, $vf); } catch (Throwable $e) { error_log('[SafeTrade] vehicle_details upsert skipped: ' . $e->getMessage()); }

            $existingPhotoCount = 0;
            $cp = $conn->prepare('SELECT COUNT(*) c FROM product_images WHERE product_id=?');
            $cp->bind_param('i', $productId);
            $cp->execute();
            $existingPhotoCount = (int) ($cp->get_result()->fetch_assoc()['c'] ?? 0);
            $cp->close();
            save_uploaded_product_photos($conn, $productId, $_FILES['photos'] ?? [], 20, $existingPhotoCount);

            audit_log($userId, 'user.vehicle.draft_saved', 'product', $productId);
            flash_set('success', 'Draft saved — pick up where you left off any time from My Listings.');
            redirect('/dashboard.php?view=sell&type=vehicle&draft=' . $productId . ($groupId ? '&group_id=' . $groupId : ''));
        }

        case 'delete_product_photo': {
            // AJAX-only: used by the vehicle photo uploader to remove an
            // already-saved photo while editing a draft (photos picked
            // but not yet uploaded are removed purely client-side).
            header('Content-Type: application/json');
            $photoId = (int) ($_POST['photo_id'] ?? 0);
            $stmt = $conn->prepare(
                'SELECT pi.id FROM product_images pi JOIN products p ON p.id = pi.product_id
                 WHERE pi.id=? AND p.seller_id=? LIMIT 1'
            );
            $stmt->bind_param('ii', $photoId, $userId);
            $stmt->execute();
            $owns = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($owns) {
                $del = $conn->prepare('DELETE FROM product_images WHERE id=?');
                $del->bind_param('i', $photoId);
                $del->execute();
                $del->close();
            }
            echo json_encode(['ok' => (bool) $owns]);
            exit;
        }

        case 'add_photos': {
            $productId = (int) ($_POST['product_id'] ?? 0);
            try {
                // parent_id/name require migration_add_category_hierarchy.sql —
                // fall back to a plain ownership check (6-photo cap) below if
                // that hasn't been run yet, instead of fataling the request.
                $stmt = $conn->prepare(
                    'SELECT p.id, pc.name AS parent_name
                     FROM products p
                     LEFT JOIN categories c ON c.id = p.category_id
                     LEFT JOIN categories pc ON pc.id = c.parent_id
                     WHERE p.id=? AND p.seller_id=? LIMIT 1'
                );
                $stmt->bind_param('ii', $productId, $userId);
                $stmt->execute();
                $owns = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } catch (Throwable $e) {
                $stmt = $conn->prepare('SELECT id, NULL AS parent_name FROM products WHERE id=? AND seller_id=? LIMIT 1');
                $stmt->bind_param('ii', $productId, $userId);
                $stmt->execute();
                $owns = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            if (!$owns) {
                flash_set('error', 'Listing not found.');
                redirect('/dashboard.php?view=my-listings');
            }

            // Vehicle listings (category is "Vehicles" itself, or a
            // subcategory of it) get the higher 20-photo cap; everything
            // else keeps the original 6-photo cap.
            $photoCap = ($owns['parent_name'] === 'Vehicles') ? 20 : 6;

            $countStmt = $conn->prepare('SELECT COUNT(*) c FROM product_images WHERE product_id=?');
            $countStmt->bind_param('i', $productId);
            $countStmt->execute();
            $existingCount = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
            $countStmt->close();

            if ($existingCount >= $photoCap) {
                flash_set('error', "This listing already has the maximum of $photoCap photos.");
            } elseif (!empty($_FILES['photos']['name'][0])) {
                $photoResult = save_uploaded_product_photos($conn, $productId, $_FILES['photos'], $photoCap, $existingCount);
                $saved       = $photoResult['saved'];
                $photoErrors = $photoResult['errors'];

                if ($saved > 0 && !$photoErrors) {
                    audit_log($userId, 'user.product.photos_added', 'product', $productId, $saved . ' photo(s)');
                    flash_set('success', $saved . ' photo(s) added.');
                } elseif ($saved > 0) {
                    audit_log($userId, 'user.product.photos_added', 'product', $productId, $saved . ' photo(s)');
                    flash_set('error', $saved . ' photo(s) added, but some failed — ' . implode('; ', $photoErrors));
                } else {
                    flash_set('error', implode('; ', $photoErrors) ?: 'No photos were saved — please try again.');
                }
            } else {
                flash_set('error', 'Choose at least one photo first.');
            }
            redirect('/dashboard.php?view=listing&id=' . $productId);
        }

        case 'express_interest': {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $stmt = $conn->prepare('SELECT id, seller_id, is_active FROM products WHERE id=? LIMIT 1');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$product || !$product['is_active'] || (int)$product['seller_id'] === $userId) {
                flash_set('error', 'That item is not available.');
                redirect('/dashboard.php?view=market');
            }
            $stmt = $conn->prepare('SELECT id FROM transactions WHERE product_id=? AND buyer_id=? AND status NOT IN ("completed","cancelled") LIMIT 1');
            $stmt->bind_param('ii', $productId, $userId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $txId = (int) $existing['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO transactions (product_id, buyer_id, seller_id, status) VALUES (?,?,?,'negotiating')");
                $stmt->bind_param('iii', $productId, $userId, $product['seller_id']);
                $stmt->execute();
                $txId = $conn->insert_id;
                $stmt->close();

                $msg = "Hi! Is this still available?";
                $stmt = $conn->prepare('INSERT INTO messages (transaction_id, sender_id, content) VALUES (?,?,?)');
                $stmt->bind_param('iis', $txId, $userId, $msg);
                $stmt->execute();
                $stmt->close();
                inbox_trade_event((int) $txId, 'interest');
            }
            redirect('/dashboard.php?view=chat&t=' . $txId);
        }

        case 'update_quantity': {
            // Lets the buyer set how many units of a multi-quantity
            // listing they want, right from the chat — capped to
            // whatever stock the seller currently has so this can never
            // ask for more than what's actually available. Only while
            // still negotiating (before a meet-up is proposed), same as
            // the point where the buyer proposes the meet-up itself.
            $txId = (int) ($_POST['transaction_id'] ?? 0);
            $tx = fetch_transaction($conn, $txId, $userId);
            $isBuyer = $tx && (int) $tx['buyer_id'] === $userId;

            if ($tx && $isBuyer && $tx['status'] === 'negotiating') {
                $available = max(1, (int) $tx['product_quantity']);
                $qty = (int) ($_POST['quantity'] ?? 1);
                $qty = max(1, min($qty, $available));

                try {
                    // quantity requires migration_add_product_quantity.sql
                    $stmt = $conn->prepare('UPDATE transactions SET quantity=? WHERE id=?');
                    $stmt->bind_param('ii', $qty, $txId);
                    $stmt->execute();
                    $stmt->close();
                    audit_log($userId, 'user.trade.quantity_updated', 'transaction', $txId, 'qty=' . $qty);
                    inbox_trade_event((int) $txId, 'quantity', ['qty' => $qty]);
                    flash_set('success', "Quantity updated to $qty.");
                } catch (Throwable $e) {
                    flash_set('error', 'Quantity tracking isn\'t available yet — ask an admin to run migration_add_product_quantity.sql.');
                }
            } elseif ($tx && !$isBuyer) {
                flash_set('error', 'Only the buyer can change the quantity.');
            }
            redirect('/dashboard.php?view=chat&t=' . $txId);
        }

        case 'send_message': {
            $txId = (int) ($_POST['transaction_id'] ?? 0);
            $content = clean_input($_POST['content'] ?? '');
            // fetch_transaction() re-checks that $userId is the buyer or
            // seller on this transaction — nobody else can post here.
            $tx = fetch_transaction($conn, $txId, $userId);
            if ($tx && $content !== '') {
                $stmt = $conn->prepare('INSERT INTO messages (transaction_id, sender_id, content) VALUES (?,?,?)');
                $stmt->bind_param('iis', $txId, $userId, $content);
                $stmt->execute();
                $stmt->close();
                inbox_trade_event((int) $txId, 'message', ['sender' => (int) $userId]);
            }
            redirect('/dashboard.php?view=chat&t=' . $txId);
        }

        case 'schedule_meetup': {
            // Buyer PROPOSES a time/place — this does not finalize the
            // meet-up on its own. The seller still has to confirm it
            // (see 'confirm_meetup' below) before the buyer is allowed
            // to mark the item received / leave feedback.
            $txId = (int) ($_POST['transaction_id'] ?? 0);
            $tx = fetch_transaction($conn, $txId, $userId);
            $isBuyer = $tx && (int) $tx['buyer_id'] === $userId;

            if ($tx && $isBuyer && $tx['status'] === 'negotiating') {
                $date = clean_input($_POST['meetup_date'] ?? '');
                $time = clean_input($_POST['meetup_time'] ?? '');
                $loc  = clean_input($_POST['meetup_location'] ?? '');

                // Quantity the buyer wants to buy, locked in right here at
                // proposal time — capped to whatever stock the seller
                // currently has, same clamp as the in-chat "update qty to
                // buy" control, so a valid amount is always saved even if
                // this is the first time quantity is set for this trade.
                $available = max(1, (int) $tx['product_quantity']);
                $qty = (int) ($_POST['quantity'] ?? $tx['quantity'] ?? 1);
                $qty = max(1, min($qty, $available));

                try {
                    // quantity requires migration_add_product_quantity.sql
                    // — fall back to the quantity-less update below if
                    // that hasn't been run yet.
                    $stmt = $conn->prepare("UPDATE transactions SET status='meetup_proposed', meetup_date=?, meetup_time=?, meetup_location=?, quantity=? WHERE id=?");
                    $stmt->bind_param('sssii', $date, $time, $loc, $qty, $txId);
                    $stmt->execute();
                    $stmt->close();
                } catch (Throwable $e) {
                    $stmt = $conn->prepare("UPDATE transactions SET status='meetup_proposed', meetup_date=?, meetup_time=?, meetup_location=? WHERE id=?");
                    $stmt->bind_param('sssi', $date, $time, $loc, $txId);
                    $stmt->execute();
                    $stmt->close();
                }

                audit_log($userId, 'user.trade.meetup_proposed', 'transaction', $txId, 'qty=' . $qty);
                inbox_trade_event((int) $txId, 'meetup_proposed');
                flash_set('success', 'Meet-up proposed — waiting for the seller to confirm.');
            } elseif ($tx && !$isBuyer) {
                flash_set('error', 'Only the buyer can propose a meet-up.');
            }
            redirect('/dashboard.php?view=chat&t=' . $txId);
        }

        case 'confirm_meetup': {
            // STEP 2: the SELLER confirms the buyer's proposed time/place.
            // Only after this does the trade move to 'meetup_scheduled',
            // which is what unlocks "Item received? Leave feedback" for
            // the buyer.
            $txId = (int) ($_POST['transaction_id'] ?? 0);
            $tx = fetch_transaction($conn, $txId, $userId);
            $isSeller = $tx && (int) $tx['seller_id'] === $userId;

            if ($tx && $isSeller && $tx['status'] === 'meetup_proposed') {
                $stmt = $conn->prepare("UPDATE transactions SET status='meetup_scheduled' WHERE id=?");
                $stmt->bind_param('i', $txId);
                $stmt->execute();
                $stmt->close();
                audit_log($userId, 'user.trade.meetup_confirmed', 'transaction', $txId);
                inbox_trade_event((int) $txId, 'meetup_confirmed');
                flash_set('success', "Meet-up confirmed — the buyer can now confirm once they've received the item.");
            } elseif ($tx && !$isSeller) {
                flash_set('error', 'Only the seller can confirm this meet-up.');
            }
            redirect('/dashboard.php?view=chat&t=' . $txId);
        }

        case 'reject_meetup': {
            // Seller isn't happy with the proposed time/place — send it
            // back to the buyer to propose something else, instead of
            // silently confirming a meet-up they didn't agree to.
            $txId = (int) ($_POST['transaction_id'] ?? 0);
            $tx = fetch_transaction($conn, $txId, $userId);
            $isSeller = $tx && (int) $tx['seller_id'] === $userId;

            if ($tx && $isSeller && $tx['status'] === 'meetup_proposed') {
                $stmt = $conn->prepare("UPDATE transactions SET status='negotiating', meetup_date=NULL, meetup_time=NULL, meetup_location=NULL WHERE id=?");
                $stmt->bind_param('i', $txId);
                $stmt->execute();
                $stmt->close();
                audit_log($userId, 'user.trade.meetup_rejected', 'transaction', $txId);
                inbox_trade_event((int) $txId, 'meetup_rejected');
                flash_set('success', 'Sent back to the buyer to propose a new time.');
            }
            redirect('/dashboard.php?view=chat&t=' . $txId);
        }

        case 'submit_feedback': {
            // STEP 1 of 2: the BUYER says the item was received AND leaves
            // a 1-5 star rating. That rating is what feeds the seller's
            // profile star average (feedback.to_user_id = seller). This
            // does NOT finish the trade yet — the seller still has to
            // confirm the sale (see 'confirm_sale' below) before the
            // listing is pulled from Market/Home and marked Sold.
            $txId       = (int) ($_POST['transaction_id'] ?? 0);
            $rating     = (int) ($_POST['rating'] ?? 0);
            $reviewText = trim(clean_input($_POST['review_text'] ?? ''));
            $tx         = fetch_transaction($conn, $txId, $userId);

            $isBuyer     = $tx && (int) $tx['buyer_id'] === $userId;
            $validRating = $rating >= 1 && $rating <= 5;

            // Feedback (and "item received") is only allowed once the
            // SELLER has confirmed the meet-up — not just when the buyer
            // proposed one. This is what "gives the buyer the privilege"
            // to close out the trade, per the confirm-then-unlock flow.
            if ($tx && $isBuyer && $validRating && $tx['status'] === 'meetup_scheduled') {
                $other = tx_other_party($tx, $userId);
                $reviewLabels = [5 => 'Excellent trade', 4 => 'Great trade', 3 => 'Good trade', 2 => 'Below average', 1 => 'Had an issue'];
                // Persist whatever the buyer typed; fall back to the
                // star-based label if they left the comment box blank.
                // Stored permanently in `feedback` so it always shows up
                // on the seller's profile, not just for this session.
                $review = mb_substr($reviewText !== '' ? $reviewText : $reviewLabels[$rating], 0, 500);

                $stmt = $conn->prepare('INSERT IGNORE INTO feedback (transaction_id, from_user_id, to_user_id, rating, review) VALUES (?,?,?,?,?)');
                $stmt->bind_param('iiiis', $txId, $userId, $other['id'], $rating, $review);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE transactions SET status='confirmed' WHERE id=?");
                $stmt->bind_param('i', $txId);
                $stmt->execute();
                $stmt->close();

                audit_log($userId, 'user.trade.buyer_confirmed', 'transaction', $txId, $rating . ' star(s) — ' . $review);
                inbox_trade_event((int) $txId, 'buyer_confirmed', ['rating' => $rating, 'review' => $review]);
                flash_set('success', "Thanks! We've let the seller know — the trade will be marked complete once they confirm the sale.");
                redirect('/dashboard.php?view=chat&t=' . $txId);
            }

            if ($tx && $isBuyer && !$validRating) {
                flash_set('error', 'Please select a star rating before submitting.');
                redirect('/dashboard.php?view=feedback&t=' . $txId);
            }

            if ($tx && !$isBuyer) {
                flash_set('error', 'Only the buyer can confirm an item was received.');
            }
            redirect('/dashboard.php?view=home');
        }

        case 'confirm_sale': {
            // STEP 2 of 2: the SELLER confirms the sale is final. This
            // reduces the listing's stock by however many units this
            // trade was for. If that empties the stock (or the listing
            // predates quantity tracking and is single-unit), it's
            // pulled from Market/Home and marked Sold, same as before —
            // otherwise it stays live with the reduced count so the next
            // buyer can still find and buy it.
            $txId = (int) ($_POST['transaction_id'] ?? 0);
            $tx = fetch_transaction($conn, $txId, $userId);
            $isSeller = $tx && (int) $tx['seller_id'] === $userId;

            if ($tx && $isSeller && $tx['status'] === 'confirmed') {
                $stmt = $conn->prepare("UPDATE transactions SET status='completed', completed_at=NOW() WHERE id=?");
                $stmt->bind_param('i', $txId);
                $stmt->execute();
                $stmt->close();

                $boughtQty = max(1, (int) $tx['quantity']);
                $remaining = 0;

                try {
                    // quantity requires migration_add_product_quantity.sql
                    $qStmt = $conn->prepare('SELECT quantity FROM products WHERE id=? LIMIT 1');
                    $qStmt->bind_param('i', $tx['product_id']);
                    $qStmt->execute();
                    $qRow = $qStmt->get_result()->fetch_assoc();
                    $qStmt->close();

                    $currentQty = $qRow ? (int) $qRow['quantity'] : 1;
                    $remaining  = max(0, $currentQty - $boughtQty);

                    if ($remaining > 0) {
                        // Stock left — keep the listing live, just with
                        // fewer units available for the next buyer.
                        $stmt = $conn->prepare('UPDATE products SET quantity=? WHERE id=?');
                        $stmt->bind_param('ii', $remaining, $tx['product_id']);
                    } else {
                        // Out of stock — pull the listing, same as the
                        // original single-item "Sold" behavior.
                        $stmt = $conn->prepare('UPDATE products SET quantity=0, is_active=0 WHERE id=?');
                        $stmt->bind_param('i', $tx['product_id']);
                    }
                    $stmt->execute();
                    $stmt->close();
                } catch (Throwable $e) {
                    // quantity column not migrated yet — fall back to the
                    // original single-unit behavior (always mark sold).
                    $stmt = $conn->prepare('UPDATE products SET is_active=0 WHERE id=?');
                    $stmt->bind_param('i', $tx['product_id']);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare('DELETE FROM messages WHERE transaction_id=?');
                $stmt->bind_param('i', $txId);
                $stmt->execute();
                $stmt->close();

                audit_log($userId, 'user.trade.seller_confirmed', 'transaction', $txId, 'qty=' . $boughtQty);
                inbox_trade_event((int) $txId, 'sale_completed');
                flash_set('success', $remaining > 0
                    ? "Sale confirmed — $boughtQty sold, $remaining still in stock."
                    : 'Sale confirmed — the listing is now marked Sold.');
                redirect('/dashboard.php?view=my-listings');
            }

            if ($tx && !$isSeller) {
                flash_set('error', 'Only the seller can confirm this sale.');
            }
            redirect('/dashboard.php?view=home');
        }

        default:
            redirect('/dashboard.php');
    }
}

/* =========================================================
   View data loading
   ========================================================= */
$view = $_GET['view'] ?? 'home';
$initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
$fullName = trim($user['first_name'] . ' ' . $user['last_name']);
$verified = $user['verification_status'] === 'approved';

// Star rating: starts at 0 stars with no reviews, and only grows once other
// users leave feedback for THIS user after a completed transaction
// (feedback.to_user_id = this user's id, rating 1-5 per review).
$ratingStmt = $conn->prepare('SELECT COUNT(*) AS c, AVG(rating) AS avg_rating FROM feedback WHERE to_user_id = ?');
$ratingStmt->bind_param('i', $userId);
$ratingStmt->execute();
$ratingRow = $ratingStmt->get_result()->fetch_assoc();
$ratingStmt->close();
$ratingCount = (int) ($ratingRow['c'] ?? 0);
$avgRating   = $ratingCount > 0 ? (float) $ratingRow['avg_rating'] : 0.0;

/** Render a 5-star rating bar. $avg is 0-5, $count is number of reviews behind it. */
function star_rating_html(float $avg, int $count): string
{
    $pct = $count > 0 ? max(0, min(100, ($avg / 5) * 100)) : 0;
    $label = $count > 0
        ? number_format($avg, 1) . ' out of 5 (' . $count . ' review' . ($count === 1 ? '' : 's') . ')'
        : 'No reviews yet';
    ob_start(); ?>
    <div class="star-rating" title="<?= e($label) ?>">
      <div class="star-rating-bg">★★★★★</div>
      <div class="star-rating-fg" style="width:<?= $pct ?>%;">★★★★★</div>
    </div>
    <div class="star-rating-label"><?= $count > 0 ? e(number_format($avg, 1)) . ' (' . (int)$count . ')' : 'No reviews yet' ?></div>
    <?php
    return ob_get_clean();
}

function category_children(array $flat, int $parentId): array
{
    return array_values(array_filter($flat, fn($c) => $c['parent_id'] === $parentId));
}

function category_by_id(array $flat, int $id): ?array
{
    foreach ($flat as $c) {
        if ($c['id'] === $id) return $c;
    }
    return null;
}

/** Builds a ?view=market&... URL, preserving cat/radius/q unless overridden. */
function market_query_string(array $overrides = []): string
{
    $params = [
        'cat'    => (int) ($_GET['cat'] ?? 0),
        'radius' => (int) ($_GET['radius'] ?? 0),
        'q'      => trim((string) ($_GET['q'] ?? '')),
        'posted' => (string) ($_GET['posted'] ?? ''),
    ];
    $params = array_merge($params, $overrides);

    $qs = 'view=market';
    if (!empty($params['cat']))      $qs .= '&cat=' . (int) $params['cat'];
    if (!empty($params['radius']))   $qs .= '&radius=' . (int) $params['radius'];
    if (($params['q'] ?? '') !== '') $qs .= '&q=' . urlencode($params['q']);
    if (($params['posted'] ?? '') !== '') $qs .= '&posted=' . urlencode($params['posted']);
    return '?' . $qs;
}

/** Renders <option> tags for a plain list of choices, marking $current as selected. */
function render_options(array $options, string $current, bool $includeBlank = true, string $blankLabel = 'Select…'): string
{
    $html = $includeBlank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($options as $opt) {
        $sel = ($opt === $current) ? ' selected' : '';
        $html .= '<option value="' . e($opt) . '"' . $sel . '>' . e($opt) . '</option>';
    }
    return $html;
}

$myGroupIds = [];
$gm = $conn->prepare('SELECT group_id FROM group_members WHERE user_id=? AND status="approved"');
$gm->bind_param('i', $userId);
$gm->execute();
$res = $gm->get_result();
while ($row = $res->fetch_assoc()) $myGroupIds[] = (int) $row['group_id'];
$gm->close();

$pageData = [];

// Reused everywhere we need a product's photos as "path|path|path".
$imagesSubquery = "(SELECT GROUP_CONCAT(pi.id ORDER BY pi.id ASC SEPARATOR '|') FROM product_images pi WHERE pi.product_id = p.id) AS images_concat";

if ($view === 'sell' && ($_GET['type'] ?? '') === 'vehicle' && !empty($_GET['draft'])) {
    $draftId = (int) $_GET['draft'];
    $dstmt = $conn->prepare('SELECT * FROM products WHERE id=? AND seller_id=? AND is_draft=1 LIMIT 1');
    $dstmt->bind_param('ii', $draftId, $userId);
    $dstmt->execute();
    $pageData['draftProduct'] = $dstmt->get_result()->fetch_assoc();
    $dstmt->close();

    if ($pageData['draftProduct']) {
        try {
            $vstmt = $conn->prepare('SELECT * FROM vehicle_details WHERE product_id=? LIMIT 1');
            $vstmt->bind_param('i', $draftId);
            $vstmt->execute();
            $pageData['draftVehicle'] = $vstmt->get_result()->fetch_assoc() ?: null;
            $vstmt->close();
        } catch (Throwable $e) {
            $pageData['draftVehicle'] = null; // migration_add_vehicle_details.sql not run yet
        }

        $pstmt = $conn->prepare('SELECT id, path FROM product_images WHERE product_id=? ORDER BY id ASC');
        $pstmt->bind_param('i', $draftId);
        $pstmt->execute();
        $pageData['draftPhotos'] = [];
        $pres = $pstmt->get_result();
        while ($row = $pres->fetch_assoc()) $pageData['draftPhotos'][] = $row;
        $pstmt->close();
    }
}

if ($view === 'home') {
    $pageData['suggested'] = $conn->query(
        "SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id=g.id AND m.status='approved') member_count
         FROM groups g ORDER BY g.created_at DESC LIMIT 6"
    );

    if ($myLat !== null && $myLng !== null) {
        // CASE ... END keeps listings from sellers who haven't shared a
        // location visible too (distance_km comes back NULL for them)
        // instead of dropping them from the feed entirely — they just
        // sort after everyone with a known distance.
        $stmt = $conn->prepare(
            "SELECT p.*, u.first_name, u.last_name,
                    CASE WHEN u.latitude IS NOT NULL AND u.longitude IS NOT NULL THEN
                      (6371 * acos(cos(radians(?)) * cos(radians(u.latitude)) *
                       cos(radians(u.longitude) - radians(?)) +
                       sin(radians(?)) * sin(radians(u.latitude))))
                    ELSE NULL END AS distance_km,
                    $imagesSubquery
             FROM products p JOIN users u ON u.id=p.seller_id
             WHERE p.is_active=1 AND p.is_deleted=0 AND p.seller_id<>?
             ORDER BY (distance_km IS NULL) ASC, distance_km ASC, p.created_at DESC LIMIT 9"
        );
        $stmt->bind_param('dddi', $myLat, $myLng, $myLat, $userId);
    } else {
        $stmt = $conn->prepare(
            "SELECT p.*, u.first_name, u.last_name, NULL AS distance_km, $imagesSubquery
             FROM products p JOIN users u ON u.id=p.seller_id
             WHERE p.is_active=1 AND p.is_deleted=0 AND p.seller_id<>?
             ORDER BY p.created_at DESC LIMIT 9"
        );
        $stmt->bind_param('i', $userId);
    }
    $stmt->execute();
    $pageData['items'] = $stmt->get_result();
    $stmt->close();
}

if ($view === 'market') {
    $catFilter    = (int) ($_GET['cat'] ?? 0);
    $searchQuery  = trim(clean_input($_GET['q'] ?? ''));

    // Facebook-Marketplace-style distance filter: 0 = any distance.
    // Only offered (and only ever applied) once we actually know the
    // buyer's own location.
    $allowedRadii = [5, 10, 25, 50];
    $radiusFilter = (int) ($_GET['radius'] ?? 0);
    if (!in_array($radiusFilter, $allowedRadii, true)) {
        $radiusFilter = 0;
    }

    // How recently the listing was posted — calendar-based, not a
    // rolling window, so "Today" really means today and "This week"
    // means the current Mon-Sun week, matching what the label says.
    $allowedPosted = ['today', 'week', 'month', 'year'];
    $postedFilter  = (string) ($_GET['posted'] ?? '');
    if (!in_array($postedFilter, $allowedPosted, true)) {
        $postedFilter = '';
    }

    // Filtering by a top-level category (e.g. "Vehicles") also matches
    // anything filed under its subcategories (Cars, Motorcycles, ...) —
    // otherwise clicking "Vehicles" would show nothing, since listings
    // are actually saved against the specific subcategory. Filtering by
    // a subcategory directly (e.g. "Laptops") just matches that one
    // category id, since subcategories don't have children of their own.
    $catIds = [];
    if ($catFilter) {
        $catIds[] = $catFilter;
        foreach ($categoriesFlat as $c) {
            if ($c['parent_id'] === $catFilter) $catIds[] = $c['id'];
        }
    }

    $where  = ['p.is_active=1', 'p.is_deleted=0', 'p.seller_id<>?'];
    $params = [$userId];

    if ($catIds) {
        $where[]  = 'p.category_id IN (' . implode(',', array_fill(0, count($catIds), '?')) . ')';
        foreach ($catIds as $cid) $params[] = $cid;
    }
    if ($searchQuery !== '') {
        $where[] = '(p.title LIKE ? OR p.description LIKE ?)';
        $like    = '%' . $searchQuery . '%';
        $params[] = $like;
        $params[] = $like;
    }
    switch ($postedFilter) {
        case 'today': $where[] = 'DATE(p.created_at) = CURDATE()'; break;
        case 'week':  $where[] = 'YEARWEEK(p.created_at, 1) = YEARWEEK(CURDATE(), 1)'; break;
        case 'month': $where[] = 'YEAR(p.created_at) = YEAR(CURDATE()) AND MONTH(p.created_at) = MONTH(CURDATE())'; break;
        case 'year':  $where[] = 'YEAR(p.created_at) = YEAR(CURDATE())'; break;
    }
    $whereSql = implode(' AND ', $where);

    if ($myLat !== null && $myLng !== null) {
        // CASE ... END keeps listings from sellers who haven't shared a
        // location visible too (distance_km comes back NULL for them)
        // instead of dropping them from the feed entirely — they just
        // sort after everyone with a known distance. A radius filter,
        // when chosen, only makes sense against a known distance, so it
        // uses HAVING against the computed alias and naturally excludes
        // unlocated listings while a specific radius is selected.
        $havingSql = $radiusFilter > 0 ? ' HAVING distance_km IS NOT NULL AND distance_km <= ' . $radiusFilter : '';
        $sql = "SELECT p.*, u.first_name, u.last_name,
                       CASE WHEN u.latitude IS NOT NULL AND u.longitude IS NOT NULL THEN
                         (6371 * acos(cos(radians(?)) * cos(radians(u.latitude)) *
                          cos(radians(u.longitude) - radians(?)) +
                          sin(radians(?)) * sin(radians(u.latitude))))
                       ELSE NULL END AS distance_km,
                       $imagesSubquery
                FROM products p JOIN users u ON u.id=p.seller_id
                WHERE $whereSql
                $havingSql
                ORDER BY (distance_km IS NULL) ASC, distance_km ASC, p.created_at DESC LIMIT 60";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$myLat, $myLng, $myLat], $params));
    } else {
        $sql = "SELECT p.*, u.first_name, u.last_name, NULL AS distance_km, $imagesSubquery
                FROM products p JOIN users u ON u.id=p.seller_id
                WHERE $whereSql
                ORDER BY p.created_at DESC LIMIT 60";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
    }
    $pageData['items'] = $stmt->get_result();
    $stmt->close();
}

if ($view === 'item') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT p.*, u.first_name, u.last_name, u.id AS seller_id2, $imagesSubquery
         FROM products p JOIN users u ON u.id=p.seller_id WHERE p.id=? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $pageData['item'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($view === 'my-listings') {
    $stmt = $conn->prepare(
        "SELECT p.*, (SELECT COUNT(*) FROM transactions t WHERE t.product_id=p.id AND t.status='negotiating') interested,
                (SELECT CONCAT(u.first_name, ' ', u.last_name) FROM transactions t
                   JOIN users u ON u.id = t.buyer_id
                  WHERE t.product_id = p.id AND t.status = 'completed'
                  ORDER BY t.completed_at DESC LIMIT 1) AS sold_to,
                (SELECT t.id FROM transactions t
                  WHERE t.product_id = p.id AND t.status = 'confirmed'
                  ORDER BY t.created_at DESC LIMIT 1) AS pending_tx_id,
                $imagesSubquery
         FROM products p WHERE p.seller_id=? ORDER BY p.is_deleted ASC, p.created_at DESC"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $pageData['listings'] = $stmt->get_result();
    $stmt->close();
}

if ($view === 'listing') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $conn->prepare(
        "SELECT products.*,
                (SELECT GROUP_CONCAT(id ORDER BY id ASC SEPARATOR '|') FROM product_images WHERE product_id = products.id) AS images_concat,
                (SELECT t.id FROM transactions t WHERE t.product_id = products.id AND t.status = 'confirmed' ORDER BY t.created_at DESC LIMIT 1) AS pending_tx_id
         FROM products WHERE id=? AND seller_id=? LIMIT 1"
    );
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $pageData['product'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($pageData['product']) {
        $stmt = $conn->prepare(
            "SELECT t.*, u.first_name, u.last_name FROM transactions t JOIN users u ON u.id=t.buyer_id
             WHERE t.product_id=? AND t.status<>'cancelled' ORDER BY t.created_at DESC"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $pageData['buyers'] = $stmt->get_result();
        $stmt->close();
    }
}

if ($view === 'chat' || $view === 'meetup' || $view === 'feedback') {
    $txId = (int) ($_GET['t'] ?? 0);
    $pageData['tx'] = fetch_transaction($conn, $txId, $userId);
    if ($pageData['tx']) {
        $stmt = $conn->prepare('SELECT * FROM messages WHERE transaction_id=? ORDER BY created_at ASC');
        $stmt->bind_param('i', $txId);
        $stmt->execute();
        $pageData['messages'] = $stmt->get_result();
        $stmt->close();
    }
}

if ($view === 'groups') {
    $pageData['groups'] = $conn->query(
        "SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id=g.id AND m.status='approved') member_count
         FROM groups g ORDER BY g.created_at DESC"
    );
}

if ($view === 'group') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $conn->prepare('SELECT * FROM groups WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $pageData['group'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($pageData['group']) {
        $stmt = $conn->prepare('SELECT * FROM group_members WHERE group_id=? AND user_id=? LIMIT 1');
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $pageData['membership'] = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $isOwner = (int) $pageData['group']['owner_id'] === $userId;
        $pageData['isOwner'] = $isOwner;
        if ($isOwner) {
            $stmt = $conn->prepare(
                "SELECT m.*, u.first_name, u.last_name FROM group_members m JOIN users u ON u.id=m.user_id
                 WHERE m.group_id=? AND m.status='pending' ORDER BY m.created_at"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $pageData['pending'] = $stmt->get_result();
            $stmt->close();
        }

        // ---- Facebook-style feed: posts (text and/or an attached item/
        // vehicle listing), each with like count + comments. Requires
        // migration_add_group_feed.sql — if it hasn't run yet, the group
        // page still works, it just shows a "not available yet" notice
        // instead of the feed.
        $pageData['posts'] = [];
        $pageData['feedAvailable'] = true;
        try {
            $pstmt = $conn->prepare(
                "SELECT gp.*, u.first_name, u.last_name,
                        p.title AS product_title, p.price AS product_price, p.icon AS product_icon, p.is_active AS product_active,
                        (SELECT COUNT(*) FROM group_post_likes l WHERE l.post_id = gp.id) AS like_count,
                        (SELECT COUNT(*) FROM group_post_likes l WHERE l.post_id = gp.id AND l.user_id = ?) AS liked_by_me,
                        $imagesSubquery
                 FROM group_posts gp
                 JOIN users u ON u.id = gp.user_id
                 LEFT JOIN products p ON p.id = gp.product_id
                 WHERE gp.group_id = ?
                 ORDER BY gp.created_at DESC LIMIT 50"
            );
            $pstmt->bind_param('ii', $userId, $id);
            $pstmt->execute();
            $postsRes = $pstmt->get_result();
            while ($row = $postsRes->fetch_assoc()) {
                $cstmt = $conn->prepare(
                    "SELECT c.*, u.first_name, u.last_name FROM group_post_comments c
                     JOIN users u ON u.id = c.user_id WHERE c.post_id = ? ORDER BY c.created_at ASC"
                );
                $cstmt->bind_param('i', $row['id']);
                $cstmt->execute();
                $row['comments'] = $cstmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $cstmt->close();
                $pageData['posts'][] = $row;
            }
            $pstmt->close();
        } catch (Throwable $e) {
            $pageData['feedAvailable'] = false; // migration_add_group_feed.sql not run yet
        }
    }
}

if ($view === 'inbox') {
    inbox_ensure();
}

if ($view === 'my-trades') {
    $stmt = $conn->prepare(
        "SELECT t.*, p.title AS product_title, p.icon AS product_icon,
                bu.first_name AS buyer_fn, bu.last_name AS buyer_ln,
                se.first_name AS seller_fn, se.last_name AS seller_ln
         FROM transactions t JOIN products p ON p.id=t.product_id
         JOIN users bu ON bu.id=t.buyer_id JOIN users se ON se.id=t.seller_id
         WHERE (t.buyer_id=? OR t.seller_id=?) AND t.status<>'completed'
         ORDER BY t.created_at DESC"
    );
    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $pageData['trades'] = $stmt->get_result();
    $stmt->close();
}

if ($view === 'profile') {
    // Defaults to your own profile; ?id= lets you view a trade partner's
    // public profile (feedback + listings + analytics are all public,
    // same as any marketplace reputation page).
    $profileId = (int) ($_GET['id'] ?? $userId);

    $stmt = $conn->prepare('SELECT id, first_name, last_name, created_at, verification_status FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $profileId);
    $stmt->execute();
    $pageData['profileUser'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($pageData['profileUser']) {
        // ---- Rating summary + breakdown (feedback is stored permanently
        // in the `feedback` table, so this always reflects everything
        // ever left for this user) ----
        $r = $conn->prepare('SELECT COUNT(*) c, AVG(rating) avg_rating FROM feedback WHERE to_user_id=?');
        $r->bind_param('i', $profileId);
        $r->execute();
        $rr = $r->get_result()->fetch_assoc();
        $r->close();
        $pageData['profileRatingCount'] = (int) ($rr['c'] ?? 0);
        $pageData['profileRatingAvg']   = $pageData['profileRatingCount'] > 0 ? (float) $rr['avg_rating'] : 0.0;

        $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $bstmt = $conn->prepare('SELECT rating, COUNT(*) c FROM feedback WHERE to_user_id=? GROUP BY rating');
        $bstmt->bind_param('i', $profileId);
        $bstmt->execute();
        $bres = $bstmt->get_result();
        while ($row = $bres->fetch_assoc()) { $breakdown[(int) $row['rating']] = (int) $row['c']; }
        $bstmt->close();
        $pageData['profileBreakdown'] = $breakdown;

        // "Posted" date filter (Today/This week/This month/This year) —
        // shared by both the Listings grid below AND the Feedback list,
        // since both are just "things dated on this profile" and the one
        // Date dropdown on the page controls both at once.
        $allowedProfilePosted = ['today', 'week', 'month', 'year'];
        $profilePostedFilter  = (string) ($_GET['posted'] ?? '');
        if (!in_array($profilePostedFilter, $allowedProfilePosted, true)) {
            $profilePostedFilter = '';
        }
        $profilePostedSqlFor = fn(string $col) => match ($profilePostedFilter) {
            'today' => " AND DATE($col) = CURDATE()",
            'week'  => " AND YEARWEEK($col, 1) = YEARWEEK(CURDATE(), 1)",
            'month' => " AND YEAR($col) = YEAR(CURDATE()) AND MONTH($col) = MONTH(CURDATE())",
            'year'  => " AND YEAR($col) = YEAR(CURDATE())",
            default => '',
        };

        // ---- Full feedback history, most recent first ----
        $fstmt = $conn->prepare(
            "SELECT f.rating, f.review, f.created_at, u.id AS reviewer_id, u.first_name, u.last_name, p.title AS product_title
             FROM feedback f
             JOIN users u ON u.id = f.from_user_id
             JOIN transactions t ON t.id = f.transaction_id
             JOIN products p ON p.id = t.product_id
             WHERE f.to_user_id = ?" . $profilePostedSqlFor('f.created_at') . "
             ORDER BY f.created_at DESC LIMIT 50"
        );
        $fstmt->bind_param('i', $profileId);
        $fstmt->execute();
        $pageData['profileFeedback'] = $fstmt->get_result();
        $fstmt->close();

        // ---- Every item this user has ever listed, with live status
        // (Selling / Sold / Removed), narrowed by the same Date filter. ----
        $lstmt = $conn->prepare(
            "SELECT p.*, $imagesSubquery,
                    (SELECT CONCAT(u2.first_name, ' ', u2.last_name) FROM transactions t2 JOIN users u2 ON u2.id = t2.buyer_id
                       WHERE t2.product_id = p.id AND t2.status = 'completed' ORDER BY t2.completed_at DESC LIMIT 1) AS sold_to
             FROM products p WHERE p.seller_id = ?" . $profilePostedSqlFor('p.created_at') . "
             ORDER BY p.is_deleted ASC, p.is_active ASC, p.created_at DESC"
        );
        $lstmt->bind_param('i', $profileId);
        $lstmt->execute();
        $pageData['profileListings'] = $lstmt->get_result();
        $lstmt->close();

        // ---- Analytics ----
        $analytics = [];

        $aq = $conn->prepare("SELECT COUNT(*) c FROM products WHERE seller_id=? AND is_deleted=0");
        $aq->bind_param('i', $profileId); $aq->execute();
        $analytics['total_listings'] = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
        $aq->close();

        $aq = $conn->prepare("SELECT COUNT(*) c FROM products WHERE seller_id=? AND is_active=1 AND is_deleted=0");
        $aq->bind_param('i', $profileId); $aq->execute();
        $analytics['active_listings'] = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
        $aq->close();

        $aq = $conn->prepare("SELECT COUNT(*) c FROM products WHERE seller_id=? AND is_active=0 AND is_deleted=0");
        $aq->bind_param('i', $profileId); $aq->execute();
        $analytics['sold_listings'] = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
        $aq->close();

        $aq = $conn->prepare("SELECT COUNT(*) c FROM transactions WHERE seller_id=? AND status='completed'");
        $aq->bind_param('i', $profileId); $aq->execute();
        $analytics['sold_as_seller'] = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
        $aq->close();

        $aq = $conn->prepare("SELECT COUNT(*) c FROM transactions WHERE buyer_id=? AND status='completed'");
        $aq->bind_param('i', $profileId); $aq->execute();
        $analytics['bought_as_buyer'] = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
        $aq->close();

        $aq = $conn->prepare("SELECT COUNT(*) c FROM transactions WHERE (seller_id=? OR buyer_id=?) AND status NOT IN ('completed','cancelled')");
        $aq->bind_param('ii', $profileId, $profileId); $aq->execute();
        $analytics['active_trades'] = (int) ($aq->get_result()->fetch_assoc()['c'] ?? 0);
        $aq->close();

        $analytics['sell_through_rate'] = $analytics['total_listings'] > 0
            ? (int) round(($analytics['sold_listings'] / $analytics['total_listings']) * 100)
            : 0;

        $pageData['profileAnalytics'] = $analytics;
    }
}

function nav_link(string $view, string $label, string $current, string $iconPath): string
{
    $active = $view === $current ? ' active' : '';
    return '<a class="side-link' . $active . '" href="?view=' . $view . '">'
         . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $iconPath . '</svg>'
         . '<span>' . e($label) . '</span></a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SafeTrade — Home</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<style>
:root{--cream:#FBF7EF;--paper:#F4EEE1;--paper-2:#ECE2CC;--ink:#161F16;--ink-soft:#5A6252;--ink-faint:#8B927F;--brand:#155943;--brand-dark:#092C21;--brand-light:#E4EFE7;--gold:#C6871F;--gold-light:#F5E6C2;--clay:#C97B52;--line:rgba(22,31,22,.10);--line-strong:rgba(22,31,22,.17);--radius:18px;--radius-lg:22px;--danger:#B3452F;--danger-bg:#F8E8DF;--shadow-xs:0 1px 2px rgba(9,44,33,.05);--shadow-sm:0 3px 12px rgba(9,44,33,.08);--shadow-md:0 12px 30px rgba(9,44,33,.13);--ease:cubic-bezier(.22,.75,.28,1);}
*{box-sizing:border-box;}
body{margin:0;background:var(--cream);color:var(--ink);font-family:'Inter',sans-serif;-webkit-font-smoothing:antialiased;}
h1,h2,h3{font-family:'Fraunces',serif;font-weight:600;margin:0;letter-spacing:-.01em;}
a{color:inherit;text-decoration:none;}
*{scrollbar-width:thin;scrollbar-color:var(--line-strong) transparent;}
*::-webkit-scrollbar{width:8px;height:8px;}
*::-webkit-scrollbar-thumb{background:var(--line-strong);border-radius:99px;}

/* ---------- App shell ---------- */
.app{display:grid;grid-template-columns:224px 1fr 284px;min-height:100vh;}
.side-main{
  background:linear-gradient(190deg,var(--brand) 0%,var(--brand-dark) 100%);
  color:var(--cream);padding:24px 14px;position:sticky;top:0;height:100vh;
  display:flex;flex-direction:column;box-shadow:2px 0 24px rgba(9,44,33,.14);z-index:5;
}
.side-logo{display:flex;align-items:center;gap:11px;font-family:'Fraunces',serif;font-weight:700;font-size:19.5px;padding:8px 10px 24px;letter-spacing:-.01em;}
.side-toggle{background:rgba(251,247,239,.10);border:none;color:var(--cream);width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;padding:0;transition:background .15s var(--ease);}
.side-toggle:hover{background:rgba(251,247,239,.20);}
.side-toggle svg{width:17px;height:17px;}
.side-logo-text{white-space:nowrap;}

/* Manual collapse (hamburger toggle) — independent of viewport width.
   Icons stay, labels hide, same visual language as the automatic
   narrow-screen collapse below. */
.app.sidebar-collapsed{grid-template-columns:78px 1fr 284px;}
.app.sidebar-collapsed .side-logo{justify-content:center;padding-left:0;padding-right:0;}
.app.sidebar-collapsed .side-logo-text,
.app.sidebar-collapsed .side-cta span,
.app.sidebar-collapsed .side-link span,
.app.sidebar-collapsed .side-foot{display:none;}
.app.sidebar-collapsed .side-cta,
.app.sidebar-collapsed .side-link{justify-content:center;padding:12px;}
.side-nav{display:flex;flex-direction:column;gap:3px;}
.side-link{display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:13px;font-size:14px;font-weight:500;color:rgba(251,247,239,.70);text-decoration:none;transition:background .15s var(--ease),color .15s var(--ease),transform .15s var(--ease);position:relative;}
.side-link svg{width:18px;height:18px;flex-shrink:0;}
.side-cta{display:flex;align-items:center;gap:10px;background:var(--gold);color:var(--brand-dark);font-weight:700;font-size:14px;padding:13px 16px;border-radius:14px;text-decoration:none;margin:4px 4px 18px;box-shadow:0 3px 10px rgba(0,0,0,.18);transition:background .15s var(--ease),transform .15s var(--ease),box-shadow .15s var(--ease);}
.side-cta svg{width:18px;height:18px;flex-shrink:0;}
.side-cta:hover{background:var(--gold-light);transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.22);}
.side-cta.active{background:var(--gold-light);}
.side-link:hover{background:rgba(251,247,239,.09);color:var(--cream);transform:translateX(1px);}
.side-link.active{background:rgba(251,247,239,.16);color:var(--cream);font-weight:600;}
.side-link.active::before{content:"";position:absolute;left:-14px;top:50%;transform:translateY(-50%);width:4px;height:20px;border-radius:0 4px 4px 0;background:var(--gold);box-shadow:0 0 10px rgba(198,135,31,.5);}
.side-foot{margin-top:auto;font-size:12px;color:rgba(251,247,239,.6);line-height:1.7;padding:8px;}
.side-foot a{color:var(--gold-light);font-weight:600;}
.side-foot a:hover{color:#fff;}
.main-col{padding:28px 32px 64px;overflow-y:auto;}
.side-right{padding:26px 20px;}
.rcard{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:18px;box-shadow:var(--shadow-xs);}

/* ---------- Ratings ---------- */
.star-rating{position:relative;display:inline-block;font-size:17px;line-height:1;letter-spacing:2px;margin-top:10px;}
.star-rating-bg{color:var(--line-strong);}
.star-rating-fg{position:absolute;top:0;left:0;overflow:hidden;white-space:nowrap;color:var(--gold);}
.star-rating-label{font-size:11px;color:var(--ink-soft);margin-top:5px;font-weight:600;}
.star-picker{display:inline-flex;flex-direction:row-reverse;gap:6px;font-size:40px;line-height:1;}
.star-picker input{position:absolute;opacity:0;width:0;height:0;}
.star-picker label{color:var(--line-strong);cursor:pointer;transition:color .12s ease,transform .12s ease;}
.star-picker input:checked ~ label,.star-picker label:hover,.star-picker label:hover ~ label{color:var(--gold);}
.star-picker label:hover{transform:scale(1.14);}
.star-picker input:focus-visible + label{outline:2px solid var(--brand);outline-offset:2px;border-radius:4px;}
.star-picker-hint{font-size:12.5px;color:var(--ink-soft);margin-top:9px;}

/* ---------- Headings / rows ---------- */
.crumb{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--ink-soft);margin-bottom:16px;transition:color .15s ease;}
.crumb:hover{color:var(--brand);}
.row-head{display:flex;align-items:baseline;justify-content:space-between;margin:26px 0 14px;}
.row-head:first-child{margin-top:0;}
.row-head h2{font-size:19px;}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}

/* ---------- Generic cards (groups / suggested) ---------- */
.card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);padding:16px;display:flex;flex-direction:column;gap:8px;transition:transform .18s var(--ease),box-shadow .18s var(--ease),border-color .18s var(--ease);}
.card .icon{font-size:30px;}
.card h4{font-size:14.5px;font-weight:600;}
.card .sub{font-size:11.5px;color:var(--ink-soft);}
.group-card{transition:transform .18s var(--ease),box-shadow .18s var(--ease);}
.group-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:rgba(21,89,67,.2);}

.price{font-family:'Fraunces',serif;font-weight:600;color:var(--brand);font-size:15.5px;}

/* ---------- Buttons ---------- */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 18px;border-radius:999px;font-weight:600;font-size:13.5px;border:none;cursor:pointer;background:var(--brand);color:var(--cream);transition:background .16s var(--ease),transform .16s var(--ease),box-shadow .16s var(--ease);box-shadow:var(--shadow-xs);}
.btn:hover{background:var(--brand-dark);transform:translateY(-1px);box-shadow:var(--shadow-sm);}
.btn:active{transform:translateY(0);}
.btn.ghost{background:transparent;border:1.5px solid var(--line-strong);color:var(--ink);box-shadow:none;}
.btn.ghost:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}
.btn.sm{padding:7px 14px;font-size:12px;}
.btn:disabled{opacity:.55;cursor:default;transform:none;box-shadow:none;}

/* ---------- Forms ---------- */
.field{margin-top:15px;}
.field label{font-size:12px;font-weight:600;color:var(--ink-soft);letter-spacing:.01em;}
.field input,.field select,.field textarea{width:100%;margin-top:6px;padding:11.5px 13px;border-radius:12px;border:1.5px solid var(--line-strong);background:var(--cream);font-family:inherit;font-size:13.5px;color:var(--ink);transition:border-color .15s ease,box-shadow .15s ease;}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(21,89,67,.15);}
.field-row{display:flex;gap:12px;} .field-row .field{flex:1;}
.form-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg);padding:26px;max-width:520px;box-shadow:var(--shadow-xs);}

.alert{border-radius:13px;padding:12px 15px;font-size:13px;margin-bottom:18px;font-weight:500;display:flex;align-items:flex-start;gap:8px;animation:dashAlertIn .2s var(--ease) both;}
@keyframes dashAlertIn{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:none;}}
.alert.error{background:var(--danger-bg);color:#8C331F;}
.alert.success{background:var(--brand-light);color:var(--brand-dark);}

.detail-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg);padding:26px;max-width:620px;box-shadow:var(--shadow-xs);}
.detail-hero{font-size:52px;margin-bottom:12px;}

/* ---------- Chat ---------- */
.chat-box{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg);max-width:580px;overflow:hidden;box-shadow:var(--shadow-sm);}
.chat-body{padding:20px;display:flex;flex-direction:column;gap:10px;max-height:360px;overflow-y:auto;}
.msg{max-width:75%;padding:10px 14px;border-radius:15px;font-size:13.5px;line-height:1.45;}
.msg.them{background:var(--cream);align-self:flex-start;border:1px solid var(--line);}
.msg.me{background:var(--brand);color:var(--cream);align-self:flex-end;}
.chat-input{display:flex;gap:9px;padding:15px;border-top:1px solid var(--line);}
.chat-input input{flex:1;border:1.5px solid var(--line-strong);border-radius:999px;padding:11px 15px;background:var(--cream);font-family:inherit;font-size:13.5px;transition:border-color .15s ease;}
.chat-input input:focus{outline:none;border-color:var(--brand);}

.pill{display:inline-block;font-size:11px;font-weight:700;padding:4.5px 11px;border-radius:999px;background:var(--gold-light);color:#7A5308;letter-spacing:.01em;}
.pill.ok{background:var(--brand-light);color:var(--brand-dark);}
.buyer-row{display:flex;align-items:center;gap:11px;background:var(--cream);border:1px solid var(--line);border-radius:13px;padding:12px 14px;margin-top:9px;transition:box-shadow .15s ease;}
.buyer-row:hover{box-shadow:var(--shadow-xs);}
.buyer-row .info{flex:1;font-size:13px;}
.status-banner{background:var(--brand-light);color:var(--brand-dark);border-radius:13px;padding:13px 16px;font-size:13.5px;font-weight:600;margin:15px 0;}
.status-banner.gold{background:var(--gold-light);color:#7A5308;}
.chip-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;}
.chip{padding:7px 14px;border-radius:999px;font-size:12.5px;font-weight:600;border:1.5px solid var(--line-strong);color:var(--ink-soft);transition:all .15s var(--ease);}
.chip:hover{border-color:var(--brand);color:var(--brand);}
.chip.active{background:var(--brand-light);border-color:var(--brand);color:var(--brand-dark);}
.loc-banner{display:flex;align-items:center;gap:10px;background:var(--gold-light);color:#7A5308;border-radius:13px;padding:11px 15px;font-size:12.5px;font-weight:600;margin-bottom:18px;}
.loc-banner.on{background:var(--brand-light);color:var(--brand-dark);}

/* ---------- Product cards ---------- */
.p-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;transition:transform .2s var(--ease),box-shadow .2s var(--ease);}
.p-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
.p-media{position:relative;display:block;aspect-ratio:4/3;background:var(--cream);overflow:hidden;}
.photo-strip{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;height:100%;scrollbar-width:none;-ms-overflow-style:none;}
.photo-strip::-webkit-scrollbar{display:none;}
.photo-strip img{flex:0 0 100%;width:100%;height:100%;object-fit:cover;scroll-snap-align:start;}
.p-media-fallback{display:flex;align-items:center;justify-content:center;height:100%;font-size:46px;}
.dots{position:absolute;bottom:9px;left:0;right:0;display:flex;justify-content:center;gap:5px;pointer-events:none;}
.dot{width:5px;height:5px;border-radius:50%;background:rgba(255,255,255,.55);transition:all .15s ease;}
.dot.active{background:#fff;width:6px;height:6px;}
.p-body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:4px;}
.p-price{font-family:'Fraunces',serif;font-weight:600;color:var(--brand);font-size:17px;}
.p-title{font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.p-meta{font-size:11.5px;color:var(--ink-soft);}
.p-seller{display:flex;align-items:center;justify-content:space-between;margin-top:9px;padding-top:11px;border-top:1px solid var(--line);gap:8px;}
.p-seller-info{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--ink-soft);line-height:1.5;}
.p-avatar{width:25px;height:25px;border-radius:50%;background:linear-gradient(160deg,var(--brand),var(--brand-dark));color:var(--cream);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;}
.chat-pill{border:1.5px solid var(--line-strong);background:transparent;border-radius:999px;padding:6.5px 13px;font-size:11.5px;font-weight:600;cursor:pointer;color:var(--ink);white-space:nowrap;transition:all .15s var(--ease);}
.chat-pill:hover{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}

.strip-hero{aspect-ratio:16/10;border-radius:var(--radius-lg);overflow:hidden;position:relative;margin-bottom:18px;box-shadow:var(--shadow-sm);}
.strip-hero .p-media-fallback{font-size:64px;background:var(--cream);}

/* ---------- Profile ---------- */
.profile-hero{display:flex;gap:24px;align-items:center;background:var(--paper);border:1px solid var(--line);border-radius:var(--radius-lg);padding:28px;margin-bottom:24px;flex-wrap:wrap;box-shadow:var(--shadow-xs);}
.profile-avatar{width:78px;height:78px;border-radius:50%;background:linear-gradient(160deg,var(--brand),var(--brand-dark));color:var(--cream);display:flex;align-items:center;justify-content:center;font-family:'Fraunces',serif;font-weight:600;font-size:27px;flex-shrink:0;box-shadow:var(--shadow-sm);}
.profile-hero-info{flex:1;min-width:220px;}
.profile-hero-info h2{font-size:22px;}
.profile-hero-meta{font-size:12.5px;color:var(--ink-soft);margin-top:7px;display:flex;align-items:center;gap:9px;flex-wrap:wrap;}
.rating-breakdown{margin-top:15px;display:flex;flex-direction:column;gap:5px;max-width:280px;}
.rating-breakdown-row{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--ink-soft);}
.rating-breakdown-row .stars-label{width:26px;flex-shrink:0;}
.rating-breakdown-bar{flex:1;height:7px;border-radius:99px;background:var(--line);overflow:hidden;}
.rating-breakdown-bar-fill{height:100%;background:var(--gold);}
.rating-breakdown-row .count{width:20px;text-align:right;flex-shrink:0;}
.analytics-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:26px;}
.analytics-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);padding:17px 19px;transition:transform .18s var(--ease),box-shadow .18s var(--ease);}
.analytics-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm);}
.analytics-card .label{font-family:'IBM Plex Mono',monospace;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft);}
.analytics-card .value{font-family:'Fraunces',serif;font-size:27px;margin-top:7px;color:var(--brand-dark);}
.feedback-card{background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:15px 17px;margin-bottom:11px;}
.feedback-card .fb-top{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--ink-soft);}
.feedback-card .fb-stars{color:var(--gold);letter-spacing:2px;font-size:14px;}
.feedback-card .fb-review{margin-top:7px;font-size:13.5px;color:var(--ink);line-height:1.55;}
.feedback-card .fb-item{margin-top:5px;font-size:11.5px;color:var(--ink-soft);}
@media(max-width:900px){.analytics-grid{grid-template-columns:repeat(2,1fr);}}

/* ---------- Hover profile card ---------- */
.hp-trigger{cursor:pointer;border-bottom:1px dashed transparent;transition:border-color .15s ease;}
.hp-trigger:hover{border-color:var(--ink-faint);}
.hp-card{
  position:absolute;z-index:300;width:254px;
  background:var(--cream);border:1px solid var(--line);border-radius:17px;padding:17px;
  box-shadow:0 18px 40px rgba(9,44,33,.22);
  opacity:0;visibility:hidden;transform:translateY(4px);
  transition:opacity .15s ease,transform .15s ease;
  pointer-events:none;
}
.hp-card.show{opacity:1;visibility:visible;transform:translateY(0);pointer-events:auto;}
.hp-loading{font-size:12.5px;color:var(--ink-soft);}
.hp-top{display:flex;align-items:center;gap:10px;}
.hp-avatar{width:41px;height:41px;border-radius:50%;background:linear-gradient(160deg,var(--brand),var(--brand-dark));color:var(--cream);display:flex;align-items:center;justify-content:center;font-family:'Fraunces',serif;font-weight:600;font-size:15px;flex-shrink:0;}
.hp-name{font-family:'Fraunces',serif;font-weight:600;font-size:15px;line-height:1.25;}
.hp-meta{font-size:11px;color:var(--ink-soft);margin-top:2px;}
.hp-badges{margin-top:10px;}
.hp-rating{margin-top:10px;}
.hp-rating .star-rating{font-size:14px;}
.hp-rating-label{font-size:11px;color:var(--ink-soft);display:block;margin-top:3px;}
.hp-stats{display:flex;justify-content:space-between;margin-top:13px;padding-top:13px;border-top:1px solid var(--line);}
.hp-stats div{text-align:center;flex:1;}
.hp-stats span{display:block;font-size:10.5px;color:var(--ink-soft);margin-top:2px;}
.hp-stats strong{display:block;font-family:'Fraunces',serif;font-size:17px;color:var(--brand-dark);font-weight:600;}
.hp-link{display:block;margin-top:14px;font-size:11.5px;font-weight:600;color:var(--brand);text-align:center;}

/* ---------- Market search ---------- */
.market-search{display:flex;align-items:center;gap:10px;background:var(--paper);border:1px solid var(--line);border-radius:999px;padding:6px 8px 6px 17px;margin-bottom:18px;max-width:520px;transition:border-color .15s ease,box-shadow .15s ease;}
.market-search:focus-within{border-color:var(--brand);box-shadow:0 0 0 3px rgba(21,89,67,.12);}
.market-search-icon{font-size:14px;color:var(--ink-soft);flex-shrink:0;}
.market-search input[type="text"]{flex:1;border:none;background:transparent;padding:10px 4px;font-family:inherit;font-size:14px;color:var(--ink);outline:none;}
.market-search-clear{flex-shrink:0;color:var(--ink-soft);text-decoration:none;font-size:13px;padding:4px;transition:color .15s ease;}
.market-search-clear:hover{color:var(--danger);}
.market-search .btn.sm{flex-shrink:0;}

/* ---------- Sell: type chooser ---------- */
.sell-choice-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;max-width:640px;}
.sell-choice-card{background:var(--paper);border:1.5px solid var(--line);border-radius:var(--radius-lg);padding:34px 28px;text-align:center;transition:border-color .18s var(--ease),transform .18s var(--ease),box-shadow .18s var(--ease);}
.sell-choice-card:hover{border-color:var(--brand);transform:translateY(-3px);box-shadow:var(--shadow-md);}
.sell-choice-icon{font-size:42px;margin-bottom:13px;}
.sell-choice-card h3{font-size:17.5px;}
.sell-choice-card p{margin-top:9px;font-size:12.5px;color:var(--ink-soft);line-height:1.55;}
@media(max-width:640px){.sell-choice-grid{grid-template-columns:1fr;}}

/* ---------- Vehicle wizard ---------- */
.wizard-steps{display:flex;align-items:center;gap:10px;margin-bottom:22px;max-width:760px;}
.wizard-step{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--ink-soft);}
.wizard-step-num{width:25px;height:25px;border-radius:50%;background:var(--line-strong);color:var(--ink-soft);display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;transition:background .2s ease,color .2s ease;}
.wizard-step.active{color:var(--brand-dark);}
.wizard-step.active .wizard-step-num{background:var(--brand);color:var(--cream);}
.wizard-step.done .wizard-step-num{background:var(--brand-light);color:var(--brand-dark);}
.wizard-step-line{flex:1;max-width:60px;height:2px;background:var(--line-strong);}
.wizard-panel{display:none;}
.wizard-panel.active{display:block;animation:dashPanelIn .2s var(--ease) both;}
@keyframes dashPanelIn{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}
.vehicle-wizard .form-section-label{font-family:'Fraunces',serif;font-weight:600;font-size:14.5px;color:var(--brand-dark);margin:28px 0 13px;padding-top:18px;border-top:1px solid var(--line);}
.vehicle-wizard .field:first-child .form-section-label,
.vehicle-wizard .form-section-label:first-child{margin-top:0;padding-top:0;border-top:none;}
.field-required{color:var(--danger);font-weight:700;}
.field-optional{font-weight:400;color:var(--ink-soft);font-size:11px;margin-left:6px;}
.field-hint{font-size:11.5px;color:var(--ink-soft);margin-top:7px;}
.field-inline{display:flex;gap:8px;}
.field-inline input{flex:1;}
.toggle-row{display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13.5px;font-weight:500;color:var(--ink);cursor:pointer;}
.toggle-row input{width:auto;margin:0;}
.wizard-buttons{display:flex;justify-content:space-between;gap:10px;margin-top:30px;padding-top:22px;border-top:1px solid var(--line);flex-wrap:wrap;}
.wizard-buttons .btn{flex-shrink:0;}

.photo-uploader{display:flex;flex-wrap:wrap;gap:11px;margin-top:9px;}
.photo-tile{position:relative;width:98px;height:98px;border-radius:13px;overflow:hidden;background:var(--cream);flex-shrink:0;box-shadow:var(--shadow-xs);}
.photo-tile img{width:100%;height:100%;object-fit:cover;display:block;}
.photo-tile-add{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;border:1.5px dashed var(--line-strong);color:var(--ink-soft);font-size:11px;font-weight:600;cursor:pointer;background:var(--cream);transition:border-color .15s ease,color .15s ease,background .15s ease;box-shadow:none;}
.photo-tile-add:hover,.photo-tile-add.drag-over{border-color:var(--brand);color:var(--brand);background:var(--brand-light);}
.photo-add-icon{font-size:22px;line-height:1;}
.photo-thumbs{display:contents;}
.photo-cover-badge{position:absolute;bottom:4px;left:4px;background:var(--brand);color:var(--cream);font-size:9px;font-weight:700;letter-spacing:.04em;padding:2px 6px;border-radius:5px;}
.photo-remove{position:absolute;top:4px;right:4px;width:21px;height:21px;border-radius:50%;border:none;background:rgba(22,31,22,0.65);color:#fff;font-size:11px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s ease;}
.photo-remove:hover{background:var(--danger);}
.photo-tile[draggable="true"]{cursor:grab;}
.photo-tile.dragging{opacity:.4;}

.conditional-field-group{display:none;}

.feature-groups{display:grid;grid-template-columns:repeat(2,1fr);gap:22px;margin-top:9px;}
.feature-group-title{font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:9px;}
.feature-checkbox{display:flex;align-items:center;gap:8px;font-size:13px;padding:5px 0;cursor:pointer;}
.feature-checkbox input{width:auto;margin:0;flex-shrink:0;}
@media(max-width:640px){.feature-groups{grid-template-columns:1fr;}}

.vehicle-preview-card{background:var(--cream);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px;}
.preview-photo-strip{display:flex;gap:9px;overflow-x:auto;border-radius:15px;}
.preview-photo-strip img{width:224px;height:164px;object-fit:cover;border-radius:13px;flex-shrink:0;}
.preview-photo-fallback{width:100%;height:164px;border-radius:13px;background:var(--paper);display:flex;align-items:center;justify-content:center;font-size:48px;}
.preview-detail-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px 22px;}
.preview-detail-row{display:flex;justify-content:space-between;font-size:13px;padding:7px 0;border-bottom:1px solid var(--line);gap:10px;}
.preview-detail-row span{color:var(--ink-soft);}
.preview-features{display:flex;flex-wrap:wrap;gap:8px 16px;font-size:13px;color:var(--brand-dark);}
@media(max-width:640px){.preview-detail-grid{grid-template-columns:1fr;}}

@media(max-width:640px){
  .vehicle-wizard{padding:20px;}
  .wizard-buttons{position:sticky;bottom:0;background:var(--paper);margin:26px -20px -20px;padding:15px 20px;box-shadow:0 -8px 20px rgba(0,0,0,.07);}
  .photo-tile{width:84px;height:84px;}
  .btn,.wizard-buttons .btn{min-height:44px;}
  .feature-checkbox{padding:8px 0;}
}

/* ---------- Group photo picker ---------- */
.group-photo-picker{margin-top:9px;}
.group-photo-preview{width:100%;max-width:360px;height:164px;border-radius:16px;background:var(--cream);border:1.5px dashed var(--line-strong);display:flex;align-items:center;justify-content:center;font-size:34px;color:var(--ink-soft);overflow:hidden;cursor:pointer;transition:border-color .15s ease;}
.group-photo-preview:hover{border-color:var(--brand);}
.group-photo-preview img{width:100%;height:100%;object-fit:cover;}
.group-photo-picker .field-hint{margin-top:9px;}
.group-photo-picker input[type=file]{display:none;}

/* ---------- Group feed ---------- */
.group-feed-composer{background:var(--cream);border:1px solid var(--line);border-radius:15px;padding:15px;margin:20px 0;}
.group-feed-composer textarea{width:100%;border-radius:12px;border:1.5px solid var(--line-strong);padding:11px 13px;font-family:inherit;font-size:13.5px;background:var(--paper);resize:vertical;}
.group-feed-actions{display:flex;gap:8px;margin-top:11px;flex-wrap:wrap;}
.group-feed{display:flex;flex-direction:column;gap:15px;margin-top:20px;}
.group-post{background:var(--cream);border:1px solid var(--line);border-radius:15px;padding:17px;}
.group-post-header{display:flex;align-items:center;gap:10px;margin-bottom:11px;}
.group-post-content{font-size:14px;line-height:1.62;color:var(--ink);margin-bottom:11px;white-space:pre-line;}
.group-post-product{display:flex;gap:12px;align-items:center;background:var(--paper);border-radius:13px;padding:11px;text-decoration:none;color:var(--ink);margin-bottom:11px;transition:box-shadow .15s ease;}
.group-post-product:hover{box-shadow:var(--shadow-xs);}
.group-post-product img{width:70px;height:70px;object-fit:cover;border-radius:9px;flex-shrink:0;}
.group-post-product-fallback{width:70px;height:70px;border-radius:9px;background:var(--cream);display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0;}
.group-post-actions{display:flex;gap:8px;border-top:1px solid var(--line);padding-top:11px;margin-top:4px;flex-wrap:wrap;}
.group-post-comments{margin-top:11px;padding-top:11px;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:6px;}
.group-post-comment{font-size:13px;background:var(--paper);border-radius:11px;padding:8px 12px;}
.group-post-comment-form{display:flex;gap:8px;margin-top:6px;}
.group-post-comment-form input[type=text]{flex:1;border:1.5px solid var(--line-strong);border-radius:999px;padding:9px 14px;background:var(--paper);font-family:inherit;font-size:13px;}

/* ---- Horizontal-scrolling rows (Suggested groups on Home, and
   Created by me / Joined / Other Groups on Browse Groups) instead of a
   grid that grows downward — cards scroll sideways, with prev/next
   arrows like a Marketplace rail. Reusable anywhere a row of cards
   should scroll instead of wrap. ---- */
.hscroll-wrap{position:relative;}
.hscroll-row{
  display:flex;gap:16px;overflow-x:auto;scroll-behavior:smooth;
  scroll-snap-type:x proximity;padding:2px 2px 10px;margin:0 -2px;
  -webkit-overflow-scrolling:touch;
}
.hscroll-row::-webkit-scrollbar{height:7px;}
.hscroll-row::-webkit-scrollbar-thumb{background:var(--line-strong);border-radius:99px;}
.hscroll-row .card,
.hscroll-row .group-card,
.hscroll-row .p-card{flex:0 0 240px;scroll-snap-align:start;}
.hscroll-nav{
  position:absolute;top:50%;transform:translateY(-50%);
  width:36px;height:36px;border-radius:50%;border:1px solid var(--line-strong);
  background:var(--cream);color:var(--brand-dark);font-size:20px;line-height:1;
  display:flex;align-items:center;justify-content:center;cursor:pointer;
  box-shadow:var(--shadow-sm);transition:background .15s ease,opacity .15s ease;
  opacity:0;pointer-events:none;
}
.hscroll-wrap:hover .hscroll-nav.can-scroll{opacity:1;pointer-events:auto;}
.hscroll-nav:hover{background:var(--brand-light);}
.hscroll-nav.prev{left:-6px;}
.hscroll-nav.next{right:-6px;}
@media(max-width:640px){
  .hscroll-nav{display:none;} /* touch users just swipe */
  .hscroll-row .card,
  .hscroll-row .group-card,
  .hscroll-row .p-card{flex-basis:76vw;}
}

@media(max-width:1100px){.app{grid-template-columns:78px 1fr;}.side-right{display:none;}.side-logo{justify-content:center;padding-left:0;padding-right:0;}.side-logo-text{display:none;}.side-link span,.side-cta span{display:none;}.side-cta{justify-content:center;padding:12px;}.side-link{justify-content:center;padding:12px;}}
@media(max-width:640px){.grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="app">
  <aside class="side-main">
    <div class="side-logo">
      <button type="button" class="side-toggle" id="side-toggle" aria-label="Toggle sidebar" aria-expanded="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
      </button>
      <span class="side-logo-text">SafeTrade</span>
    </div>
    <a class="side-cta<?= $view === 'sell' ? ' active' : '' ?>" href="?view=sell">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      <span>Sell an item</span>
    </a>
    <nav class="side-nav">
      <?= nav_link('home', 'Home', $view, '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>') ?>
      <?= nav_link('market', 'Browse Market', $view, '<path d="M4 4h16l1.4 5.2a1 1 0 01-1 1.3H3.6a1 1 0 01-1-1.3L4 4z"/><path d="M4.5 10.5V20h15v-9.5"/><path d="M9.5 20v-6.5h5V20"/>') ?>
      <?= nav_link('my-listings', 'My Listings', $view, '<path d="M7 4h10l3 5-8 11L2 9z"/><path d="M2 9h18M9.5 4L7 9l5 11 5-11-2.5-5"/>') ?>
      <?= nav_link('my-trades', 'My Trades', $view, '<path d="M3 11h13M12 6l4 5-4 5"/><path d="M21 13H8M12 18l-4-5 4-5"/>') ?>
      <?= inbox_nav_link_user($view) ?>
      <?= nav_link('groups', 'Groups', $view, '<circle cx="9" cy="8" r="3"/><path d="M2.5 19c0-3.3 2.9-5.8 6.5-5.8s6.5 2.5 6.5 5.8"/><path d="M16.5 6.5a3 3 0 010 5.8"/>') ?>
      <?= nav_link('profile', 'My Profile', $view, '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-7 8-7s8 2.6 8 7"/>') ?>
    </nav>
    <div class="side-foot" id="side-foot">
      Signed in as<br><strong style="color:var(--cream);"><?= e($fullName) ?></strong><br>
      <a href="auth/logout.php">Log out</a>
    </div>
  </aside>

  <script>
  (function () {
    var appEl = document.querySelector('.app');
    var toggleBtn = document.getElementById('side-toggle');
    if (!appEl || !toggleBtn) return;
    var STORAGE_KEY = 'safetrade_sidebar_collapsed';

    function applyState(collapsed) {
      appEl.classList.toggle('sidebar-collapsed', collapsed);
      toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    try {
      applyState(localStorage.getItem(STORAGE_KEY) === '1');
    } catch (e) { /* private browsing / storage disabled — just start expanded */ }

    toggleBtn.addEventListener('click', function () {
      var collapsed = !appEl.classList.contains('sidebar-collapsed');
      applyState(collapsed);
      try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
    });
  })();
  </script>

  <main class="main-col">
    <?php foreach (flash_get_all() as $f): ?>
      <div class="alert <?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?= inbox_maintenance_banner('user') ?>

    <?php if ($view === 'inbox'): ?>
      <?php inbox_render_panel('user'); ?>

    <?php endif; ?>

    <?php if (in_array($view, ['home', 'market'], true)): ?>
      <?php if ($myLat !== null && $myLng !== null && $myLocationSource === 'ip'): ?>
        <div class="loc-banner on">📍 Showing items sorted by approximate distance (based on your IP address) — <a href="#" id="loc-retry-gps" style="color:inherit;text-decoration:underline;">turn on precise location</a> for better accuracy</div>
      <?php elseif ($myLat !== null && $myLng !== null): ?>
        <div class="loc-banner on">📍 Showing items sorted by distance from your location</div>
      <?php else: ?>
        <div class="loc-banner" id="loc-banner">📍 Turn on location to see items sorted by distance — <a href="#" id="loc-use-ip" style="color:inherit;text-decoration:underline;">or use my approximate location</a></div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($view === 'home'): ?>
      <div class="row-head"><h2>Suggested groups</h2><a href="?view=groups" style="font-size:13px;font-weight:600;color:var(--brand);">See all</a></div>
      <div class="hscroll-wrap">
        <div class="hscroll-row">
          <?php while ($g = $pageData['suggested']->fetch_assoc()): $joined = in_array((int)$g['id'], $myGroupIds, true); ?>
            <a class="card group-card" href="?view=group&id=<?= (int)$g['id'] ?>">
              <?= group_thumb_html($g) ?>
              <h4><?= e($g['name']) ?></h4>
              <div class="sub"><?= (int)$g['member_count'] ?> members<?= !empty($g['category']) ? ' · ' . e($g['category']) : '' ?></div>
              <?php if ($joined): ?><span class="pill ok">Joined — tap to open</span><?php else: ?><span class="pill">Tap to view &amp; join</span><?php endif; ?>
            </a>
          <?php endwhile; ?>
        </div>
        <button type="button" class="hscroll-nav prev" aria-label="Scroll left">‹</button>
        <button type="button" class="hscroll-nav next" aria-label="Scroll right">›</button>
      </div>

      <div class="row-head"><h2>Near you</h2><a href="?view=market" style="font-size:13px;font-weight:600;color:var(--brand);">See all</a></div>
      <div class="grid">
        <?php while ($it = $pageData['items']->fetch_assoc()): ?>
          <?= product_card($it, $userId) ?>
        <?php endwhile; ?>
      </div>

    <?php elseif ($view === 'market'): ?>
      <div class="row-head"><h2>Browse market</h2></div>

      <form method="get" class="market-search" role="search">
        <input type="hidden" name="view" value="market">
        <?php if ($catFilter): ?><input type="hidden" name="cat" value="<?= (int) $catFilter ?>"><?php endif; ?>
        <?php if ($radiusFilter): ?><input type="hidden" name="radius" value="<?= (int) $radiusFilter ?>"><?php endif; ?>
        <?php if ($postedFilter !== ''): ?><input type="hidden" name="posted" value="<?= e($postedFilter) ?>"><?php endif; ?>
        <span class="market-search-icon">🔍</span>
        <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search Marketplace">
        <?php if ($searchQuery !== ''): ?>
          <a class="market-search-clear" href="<?= e(market_query_string(['q' => ''])) ?>" title="Clear search">✕</a>
        <?php endif; ?>
        <button type="submit" class="btn sm">Search</button>
      </form>

      <?php if ($myLat !== null): ?>
      <div class="chip-row" style="margin-top:-6px;">
        <a class="chip <?= $radiusFilter === 0 ? 'active' : '' ?>" href="<?= e(market_query_string(['radius' => 0])) ?>">📍 Any distance</a>
        <?php foreach ([5, 10, 25, 50] as $rad): ?>
          <a class="chip <?= $radiusFilter === $rad ? 'active' : '' ?>" href="<?= e(market_query_string(['radius' => $rad])) ?>">Within <?= $rad ?> km</a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- More specific filtering: exact subcategory (not just the
           top-level chips above) and how recently a listing was posted. -->
      <div class="field-row" style="max-width:620px;margin-bottom:20px;gap:14px;">
        <div class="field" style="margin-top:0;">
          <label>Category</label>
          <select onchange="if(this.value) window.location.href=this.value;">
            <option value="<?= e(market_query_string(['cat' => 0])) ?>" <?= empty($catFilter) ? 'selected' : '' ?>>All Categories</option>
            <?php foreach ($topCategories as $top):
              $subs = category_children($categoriesFlat, $top['id']); ?>
              <?php if ($subs): ?>
                <optgroup label="<?= e($top['icon'] . ' ' . $top['name']) ?>">
                  <option value="<?= e(market_query_string(['cat' => $top['id']])) ?>" <?= $catFilter === $top['id'] ? 'selected' : '' ?>><?= e($top['name']) ?> (general)</option>
                  <?php foreach ($subs as $sc): ?>
                    <option value="<?= e(market_query_string(['cat' => $sc['id']])) ?>" <?= $catFilter === $sc['id'] ? 'selected' : '' ?>><?= e($sc['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php else: ?>
                <option value="<?= e(market_query_string(['cat' => $top['id']])) ?>" <?= $catFilter === $top['id'] ? 'selected' : '' ?>><?= e($top['icon'] . ' ' . $top['name']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin-top:0;">
          <label>Posted</label>
          <select onchange="if(this.value) window.location.href=this.value;">
            <option value="<?= e(market_query_string(['posted' => ''])) ?>" <?= $postedFilter === '' ? 'selected' : '' ?>>Any time</option>
            <option value="<?= e(market_query_string(['posted' => 'today'])) ?>" <?= $postedFilter === 'today' ? 'selected' : '' ?>>Today</option>
            <option value="<?= e(market_query_string(['posted' => 'week'])) ?>" <?= $postedFilter === 'week' ? 'selected' : '' ?>>This week</option>
            <option value="<?= e(market_query_string(['posted' => 'month'])) ?>" <?= $postedFilter === 'month' ? 'selected' : '' ?>>This month</option>
            <option value="<?= e(market_query_string(['posted' => 'year'])) ?>" <?= $postedFilter === 'year' ? 'selected' : '' ?>>This year</option>
          </select>
        </div>
      </div>

      <div class="grid">
        <?php if ($pageData['items']->num_rows === 0): ?>
          <p style="color:var(--ink-soft);">
            <?php
            $postedLabels = ['today' => 'today', 'week' => 'this week', 'month' => 'this month', 'year' => 'this year'];
            ?>
            <?php if ($searchQuery !== ''): ?>
              No items match "<?= e($searchQuery) ?>"<?= $catFilter ? ' in this category' : '' ?><?= $postedFilter !== '' ? ' posted ' . $postedLabels[$postedFilter] : '' ?>.
            <?php elseif ($radiusFilter > 0): ?>
              No items within <?= $radiusFilter ?> km — try widening the distance.
            <?php elseif ($postedFilter !== ''): ?>
              No items posted <?= $postedLabels[$postedFilter] ?><?= $catFilter ? ' in this category' : '' ?> — try a wider time range.
            <?php else: ?>
              No items yet.
            <?php endif; ?>
          </p>
        <?php endif; ?>
        <?php while ($it = $pageData['items']->fetch_assoc()): ?>
          <?= product_card($it, $userId) ?>
        <?php endwhile; ?>
      </div>

    <?php elseif ($view === 'item'):
      $it = $pageData['item'];
      // Removed/deactivated listings are treated as gone for everyone
      // except the seller (who can still see it via My Listings/manage).
      if ($it && ((int)$it['is_deleted'] === 1 || (int)$it['is_active'] === 0) && (int)$it['seller_id2'] !== $userId) {
          $it = null;
      }
      $heroThumbs = $it ? product_thumbs($it['images_concat'] ?? null) : []; ?>
      <a class="crumb" href="?view=market">← Back to market</a>
      <?php if (!$it): ?><p>Item not found.</p><?php else: ?>
      <div class="detail-card">
        <div class="strip-hero">
          <?php if ($heroThumbs): ?>
            <div class="photo-strip">
              <?php foreach ($heroThumbs as $src): ?><img src="<?= e($src) ?>" alt=""><?php endforeach; ?>
            </div>
            <?php if (count($heroThumbs) > 1): ?>
              <div class="dots">
                <?php foreach ($heroThumbs as $idx => $_): ?><span class="dot<?= $idx === 0 ? ' active' : '' ?>"></span><?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="p-media-fallback"><?= e($it['icon']) ?></div>
          <?php endif; ?>
        </div>
        <h2><?= e($it['title']) ?></h2>
        <div class="sub" style="margin-top:6px;color:var(--ink-soft);"><?= e($it['location']) ?> · posted <?= e(time_ago($it['created_at'])) ?></div>
        <div class="price" style="font-size:24px;margin-top:12px;">₱<?= number_format((float)$it['price'], 2) ?></div>
        <?php $itQty = isset($it['quantity']) ? (int) $it['quantity'] : 1; ?>
        <div class="sub" style="margin-top:4px;color:var(--ink-soft);">📦 <?= $itQty ?> available</div>
        <p style="margin-top:14px;color:var(--ink-soft);line-height:1.6;"><?= nl2br(e($it['description'])) ?></p>
        <div class="status-banner">Seller: <span class="hp-trigger" data-uid="<?= (int)$it['seller_id2'] ?>"><?= e($it['first_name'] . ' ' . $it['last_name']) ?></span></div>
        <?php if ((int)$it['seller_id2'] === $userId): ?>
          <a class="btn" href="?view=listing&id=<?= (int)$it['id'] ?>">Manage this listing</a>
        <?php else: ?>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="express_interest">
            <input type="hidden" name="product_id" value="<?= (int)$it['id'] ?>">
            <button class="btn" type="submit">💬 Message seller</button>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    <?php elseif ($view === 'sell'):
      $sellType = in_array($_GET['type'] ?? '', ['item', 'vehicle'], true) ? $_GET['type'] : '';
      $sellGroupId = (int) ($_GET['group_id'] ?? 0); ?>

      <?php if ($sellType === ''): ?>
        <div class="row-head"><h2>What are you selling?</h2></div>
        <div class="sell-choice-grid">
          <a class="sell-choice-card" href="?view=sell&type=item<?= $sellGroupId ? '&group_id=' . $sellGroupId : '' ?>">
            <div class="sell-choice-icon">📦</div>
            <h3>Sell an Item</h3>
            <p>Electronics, home &amp; living, fashion, tools, hobbies, and more.</p>
          </a>
          <a class="sell-choice-card" href="?view=sell&type=vehicle<?= $sellGroupId ? '&group_id=' . $sellGroupId : '' ?>">
            <div class="sell-choice-icon">🚗</div>
            <h3>Sell a Vehicle</h3>
            <p>Cars, motorcycles, trucks, vans, and other vehicles.</p>
          </a>
        </div>

      <?php elseif ($sellType === 'item'): ?>
        <a class="crumb" href="?view=sell">← Choose a different type</a>
        <div class="row-head"><h2>📦 Sell an item<?= $sellGroupId ? ' in this group' : '' ?></h2></div>
        <?php if (!gd_available()): ?>
          <div class="alert error">Photo uploads are currently disabled on this server — the GD image library isn't enabled in PHP. You can still post the item, but photos won't save until an admin enables the <code>gd</code> extension in <code>php.ini</code> and restarts Apache.</div>
        <?php endif; ?>
        <form method="post" class="form-card" enctype="multipart/form-data" id="sell-form"><?= csrf_field() ?>
          <input type="hidden" name="action" value="post_item">
          <input type="hidden" name="sell_type" value="item">
          <input type="hidden" name="group_id" value="<?= $sellGroupId ?>">
          <input type="hidden" name="icon" id="sell-icon-field" value="📦">
          <div class="field"><label>Title</label><input type="text" name="title" placeholder="What are you selling?" required></div>
          <div class="field-row">
            <div class="field"><label>Price (₱)</label><input type="number" step="0.01" name="price"></div>
            <div class="field"><label>Quantity <span class="field-optional">how many you have</span></label><input type="number" min="1" step="1" name="quantity" value="1"></div>
          </div>
          <div class="field-row">
            <div class="field"><label>Category</label>
              <select name="category_id" id="sell-category-select" required>
                <option value="" disabled selected>Choose a category…</option>
                <?php foreach ($topCategories as $top):
                  if ($top['name'] === 'Vehicles') continue; // vehicles have their own flow
                  $subs = category_children($categoriesFlat, $top['id']); ?>
                  <?php if ($subs): ?>
                    <optgroup label="<?= e($top['icon'] . ' ' . $top['name']) ?>">
                      <option value="<?= $top['id'] ?>" data-icon="<?= e($top['icon']) ?>"><?= e($top['name']) ?> (general)</option>
                      <?php foreach ($subs as $sc): ?>
                        <option value="<?= $sc['id'] ?>" data-icon="<?= e($sc['icon']) ?>"><?= e($sc['name']) ?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php else: ?>
                    <option value="<?= $top['id'] ?>" data-icon="<?= e($top['icon']) ?>"><?= e($top['icon'] . ' ' . $top['name']) ?></option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field"><label>Description</label><textarea name="description" rows="3"></textarea></div>
          <div class="field"><label>Location</label><input type="text" name="location" placeholder="e.g. Robinsons Town Mall, Antipolo"></div>
          <div class="field">
            <label>Photos (up to 6)</label>
            <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
          </div>
          <button class="btn" style="margin-top:18px;" type="submit"><?= $sellGroupId ? 'Post item to group' : 'Post item' ?></button>
        </form>
        <script>
        (function () {
          var select = document.getElementById('sell-category-select');
          var iconField = document.getElementById('sell-icon-field');
          if (!select || !iconField) return;
          select.addEventListener('change', function () {
            var opt = select.options[select.selectedIndex];
            var icon = opt ? opt.getAttribute('data-icon') : null;
            if (icon) iconField.value = icon;
          });
        })();
        </script>

      <?php else: /* sellType === 'vehicle' — 2-step listing wizard */
        $dp   = $pageData['draftProduct'] ?? null;
        $dv   = $pageData['draftVehicle'] ?? null;
        $draftId = $dp ? (int) $dp['id'] : 0;
        $draftPhotos = $pageData['draftPhotos'] ?? [];
        $prefill = array_merge($dp ?: [], $dv ?: []);
        $val = fn(string $key, string $default = '') => isset($prefill[$key]) && $prefill[$key] !== null && $prefill[$key] !== '' ? (string) $prefill[$key] : $default;
        $selFeatures = ($dv && !empty($dv['features'])) ? (json_decode($dv['features'], true) ?: []) : [];
        $extra = ($dv && !empty($dv['extra_fields'])) ? (json_decode($dv['extra_fields'], true) ?: []) : [];
        $isChecked = fn(string $label) => in_array($label, $selFeatures, true) ? ' checked' : '';

        $vehicleTypeOptions   = ['Car', 'SUV', 'Pickup Truck', 'Van', 'Minivan', 'Motorcycle', 'Truck', 'Bus', 'RV/Camper', 'Trailer', 'Other'];
        $bodyTypeOptions      = ['Sedan', 'Coupe', 'Hatchback', 'Wagon', 'SUV', 'Crossover', 'Convertible', 'Pickup', 'Van', 'Minivan', 'Other'];
        $conditionOptions     = ['New', 'Used', 'Certified Pre-Owned', 'Salvage', 'Rebuilt', 'Damaged', 'For Parts'];
        $fuelTypeOptions      = ['Gasoline', 'Diesel', 'Hybrid', 'Plug-in Hybrid', 'Electric', 'LPG', 'CNG', 'Hydrogen', 'Other'];
        $cylinderOptions      = ['3', '4', '5', '6', '8', '10', '12', 'Other'];
        $transmissionOptions  = ['Automatic', 'Manual', 'CVT', 'Semi-Automatic', 'Dual-Clutch', 'Other'];
        $drivetrainOptions    = ['FWD', 'RWD', 'AWD', '4WD'];
        $colorOptions         = ['White', 'Black', 'Silver', 'Gray', 'Red', 'Blue', 'Green', 'Brown', 'Beige', 'Gold', 'Orange', 'Other'];
        $ownershipOptions     = ['First Owner', 'Second Owner', '3+ Owners', 'Unknown'];
        $serviceHistOptions   = ['Full Service History', 'Partial Service History', 'No Service History', 'Unknown'];
        $keysOptions          = ['1', '2', '3', '4+'];
        $accidentOptions      = ['No Known Accidents', 'Accident History', 'Major Accident', 'Minor Accident', 'Unknown'];
        $damageOptions        = ['No Known Damage', 'Minor Damage', 'Major Damage', 'Flood Damage', 'Fire Damage', 'Hail Damage', 'Other'];
        $registrationOptions  = ['Registered', 'Unregistered', 'Registration Expired'];
        $featureGroups = [
            'Comfort'    => ['Air Conditioning', 'Automatic Climate Control', 'Heated Seats', 'Ventilated Seats', 'Leather Seats', 'Power Seats', 'Memory Seats', 'Sunroof', 'Panoramic Roof'],
            'Technology' => ['Apple CarPlay', 'Android Auto', 'Navigation', 'Bluetooth', 'USB', 'Wireless Charging', 'Digital Dashboard', 'Touchscreen'],
            'Safety'     => ['ABS', 'Airbags', 'Blind Spot Monitoring', 'Lane Departure Warning', 'Lane Keeping Assist', 'Adaptive Cruise Control', 'Parking Sensors', 'Rear Camera', '360° Camera', 'Automatic Emergency Braking'],
            'Exterior'   => ['LED Headlights', 'Fog Lights', 'Roof Rack', 'Tow Hitch', 'Alloy Wheels', 'Premium Wheels'],
        ]; ?>
        <a class="crumb" href="?view=sell">← Choose a different type</a>
        <div class="row-head"><h2>🚗 Sell a vehicle<?= $sellGroupId ? ' in this group' : '' ?></h2></div>
        <?php if (!gd_available()): ?>
          <div class="alert error">Photo uploads are currently disabled on this server — the GD image library isn't enabled in PHP. You can still save/post the listing, but photos won't save until an admin enables the <code>gd</code> extension in <code>php.ini</code> and restarts Apache.</div>
        <?php endif; ?>

        <div class="wizard-steps" id="wizard-steps">
          <div class="wizard-step active" data-step="1"><span class="wizard-step-num">1</span> Listing</div>
          <div class="wizard-step-line"></div>
          <div class="wizard-step" data-step="2"><span class="wizard-step-num">2</span> Vehicle Details</div>
        </div>

        <div id="wizard-error" class="alert error" style="display:none;"></div>

        <form method="post" class="form-card vehicle-wizard" enctype="multipart/form-data" id="vehicle-form" style="max-width:760px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" id="vehicle-form-action" value="save_vehicle_draft">
          <input type="hidden" name="draft_id" value="<?= $draftId ?>">
          <input type="hidden" name="group_id" value="<?= $sellGroupId ?>">
          <input type="hidden" name="icon" id="vehicle-icon-field" value="<?= e($val('icon', '🚗')) ?>">

          <!-- ===================== STEP 1: LISTING ===================== -->
          <div class="wizard-panel active" id="wizard-panel-1">

            <div class="field">
              <label>Photos <span class="field-optional">Add up to 20 photos</span></label>
              <div class="photo-uploader" id="photo-uploader">
                <div class="photo-tile photo-tile-add" id="photo-add-tile">
                  <span class="photo-add-icon">+</span>
                  <span>Add photos</span>
                </div>
                <div id="photo-thumbs" class="photo-thumbs"></div>
              </div>
              <input type="file" id="photo-file-input" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple hidden>
              <div class="field-hint" id="photo-count-hint">0 photos added</div>
            </div>

            <div class="field">
              <label>Vehicle type <span class="field-required">*</span></label>
              <select name="vehicle_type" id="vf-vehicle_type">
                <?= render_options($vehicleTypeOptions, $val('vehicle_type'), true, 'Choose a vehicle type…') ?>
              </select>
            </div>

            <div class="form-section-label">Basic listing information</div>
            <div class="field">
              <label>Title <span class="field-required">*</span></label>
              <input type="text" name="title" id="vf-title" placeholder="e.g. 2018 Toyota Vios 1.3 E" value="<?= e($val('title')) ?>">
            </div>
            <div class="field-row">
              <div class="field">
                <label>Price (₱) <span class="field-required">*</span></label>
                <input type="number" step="0.01" min="0" name="price" id="vf-price" value="<?= e($val('price')) ?>">
              </div>
              <div class="field">
                <label>&nbsp;</label>
                <label class="toggle-row">
                  <input type="checkbox" name="negotiable" value="1"<?= !empty($prefill['negotiable']) ? ' checked' : '' ?>>
                  <span>Negotiable</span>
                </label>
              </div>
            </div>
            <div class="field">
              <label>Location <span class="field-required">*</span></label>
              <input type="text" name="location" id="vf-location" placeholder="e.g. Robinsons Town Mall, Antipolo" value="<?= e($val('location')) ?>">
            </div>
            <div class="field">
              <label>Description</label>
              <textarea name="description" rows="4" placeholder="Tell buyers about your vehicle…"><?= e($val('description')) ?></textarea>
            </div>

            <div class="wizard-buttons">
              <button type="submit" class="btn ghost" id="btn-save-draft">Save Draft</button>
              <button type="button" class="btn" id="btn-step1-next">Next →</button>
            </div>
          </div>

          <!-- ===================== STEP 2: VEHICLE DETAILS ===================== -->
          <div class="wizard-panel" id="wizard-panel-2">

            <div class="form-section-label">Basic vehicle information</div>
            <div class="field-row">
              <div class="field"><label>Make <span class="field-required">*</span></label><input type="text" name="make" id="vf-make" placeholder="e.g. Toyota" value="<?= e($val('make')) ?>"></div>
              <div class="field"><label>Model <span class="field-required">*</span></label><input type="text" name="model" id="vf-model" placeholder="e.g. Vios" value="<?= e($val('model')) ?>"></div>
            </div>
            <div class="field-row">
              <div class="field"><label>Year <span class="field-required">*</span></label><input type="number" name="year" id="vf-year" min="1950" max="<?= (int) date('Y') + 1 ?>" placeholder="e.g. 2018" value="<?= e($val('year')) ?>"></div>
              <div class="field"><label>Trim / Variant</label><input type="text" name="trim" placeholder="e.g. XLE, G, Sport" value="<?= e($val('trim')) ?>"></div>
            </div>
            <div class="field body-type-field">
              <label>Body type</label>
              <select name="body_type"><?= render_options($bodyTypeOptions, $val('body_type')) ?></select>
            </div>

            <div class="form-section-label">Mileage &amp; condition</div>
            <div class="field-row">
              <div class="field">
                <label>Mileage <span class="field-required">*</span></label>
                <div class="field-inline">
                  <input type="text" inputmode="numeric" name="mileage" id="vf-mileage" placeholder="45,000" value="<?= e($val('mileage')) ?>">
                  <select name="mileage_unit" style="max-width:90px;">
                    <option value="km"<?= $val('mileage_unit', 'km') === 'km' ? ' selected' : '' ?>>km</option>
                    <option value="mi"<?= $val('mileage_unit') === 'mi' ? ' selected' : '' ?>>mi</option>
                  </select>
                </div>
              </div>
              <div class="field"><label>Condition</label><select name="condition"><?= render_options($conditionOptions, $val('condition', 'Used'), false) ?></select></div>
            </div>

            <div class="form-section-label">Engine &amp; performance</div>
            <div class="field-row">
              <div class="field"><label>Fuel type</label><select name="fuel_type" id="vf-fuel_type"><?= render_options($fuelTypeOptions, $val('fuel_type')) ?></select></div>
              <div class="field"><label>Engine size</label><input type="text" name="engine_size" placeholder="e.g. 2.0 L" value="<?= e($val('engine_size')) ?>"></div>
            </div>
            <div class="field-row cylinders-field">
              <div class="field"><label>Cylinders</label><select name="cylinders"><?= render_options($cylinderOptions, $val('cylinders')) ?></select></div>
              <div class="field"><label>Horsepower</label><input type="text" name="horsepower" placeholder="e.g. 150 HP" value="<?= e($val('horsepower')) ?>"></div>
            </div>

            <div class="form-section-label transmission-field">Transmission</div>
            <div class="field-row transmission-field">
              <div class="field"><label>Transmission</label><select name="transmission"><?= render_options($transmissionOptions, $val('transmission')) ?></select></div>
              <div class="field drivetrain-field"><label>Drivetrain</label><select name="drivetrain"><?= render_options($drivetrainOptions, $val('drivetrain')) ?></select></div>
            </div>

            <div class="form-section-label">Appearance</div>
            <div class="field-row">
              <div class="field"><label>Exterior color</label><select name="exterior_color"><?= render_options($colorOptions, $val('exterior_color')) ?></select></div>
              <div class="field"><label>Interior color</label><select name="interior_color"><?= render_options($colorOptions, $val('interior_color')) ?></select></div>
            </div>

            <!-- Conditional: Electric fuel type -->
            <div class="conditional-field-group" data-show-field="fuel_type" data-show-value="Electric">
              <div class="form-section-label">Battery &amp; charging</div>
              <div class="field-row">
                <div class="field"><label>Battery capacity</label><input type="text" name="battery_capacity" placeholder="e.g. 58 kWh" value="<?= e($extra['battery_capacity'] ?? '') ?>"></div>
                <div class="field"><label>Range</label><input type="text" name="range" placeholder="e.g. 420 km" value="<?= e($extra['range'] ?? '') ?>"></div>
              </div>
              <div class="field-row">
                <div class="field"><label>Charging type</label><input type="text" name="charging_type" placeholder="e.g. CCS2" value="<?= e($extra['charging_type'] ?? '') ?>"></div>
                <div class="field"><label>Charging time</label><input type="text" name="charging_time" placeholder="e.g. 45 min (10-80%)" value="<?= e($extra['charging_time'] ?? '') ?>"></div>
              </div>
              <div class="field"><label>Battery health</label><input type="text" name="battery_health" placeholder="e.g. 96%" value="<?= e($extra['battery_health'] ?? '') ?>"></div>
            </div>

            <!-- Conditional: Pickup Truck / Truck vehicle type -->
            <div class="conditional-field-group" data-show-field="vehicle_type" data-show-value="Pickup Truck,Truck">
              <div class="form-section-label">Bed &amp; towing</div>
              <div class="field-row">
                <div class="field"><label>Bed length</label><input type="text" name="bed_length" placeholder="e.g. 1.8 m" value="<?= e($extra['bed_length'] ?? '') ?>"></div>
                <div class="field"><label>Cab type</label><input type="text" name="cab_type" placeholder="e.g. Double Cab" value="<?= e($extra['cab_type'] ?? '') ?>"></div>
              </div>
              <div class="field-row">
                <div class="field"><label>Payload</label><input type="text" name="payload" placeholder="e.g. 1,000 kg" value="<?= e($extra['payload'] ?? '') ?>"></div>
                <div class="field"><label>Towing capacity</label><input type="text" name="towing_capacity" placeholder="e.g. 3,500 kg" value="<?= e($extra['towing_capacity'] ?? '') ?>"></div>
              </div>
            </div>

            <!-- Conditional: Motorcycle vehicle type -->
            <div class="conditional-field-group" data-show-field="vehicle_type" data-show-value="Motorcycle">
              <div class="form-section-label">Motorcycle</div>
              <div class="field"><label>Motorcycle type</label>
                <select name="motorcycle_type">
                  <?= render_options(['Scooter', 'Underbone', 'Sport', 'Cruiser', 'Touring', 'Off-road', 'Naked', 'Other'], $extra['motorcycle_type'] ?? '') ?>
                </select>
              </div>
            </div>

            <div class="form-section-label">Vehicle history</div>
            <div class="field-row">
              <div class="field"><label>Ownership</label><select name="ownership"><?= render_options($ownershipOptions, $val('ownership')) ?></select></div>
              <div class="field"><label>Service history</label><select name="service_history"><?= render_options($serviceHistOptions, $val('service_history')) ?></select></div>
            </div>
            <div class="field-row">
              <div class="field"><label>Number of keys</label><select name="num_keys"><?= render_options($keysOptions, $val('num_keys')) ?></select></div>
              <div class="field"><label>Accident history</label><select name="accident_history"><?= render_options($accidentOptions, $val('accident_history')) ?></select></div>
            </div>
            <div class="field"><label>Current damage</label><select name="damage_status"><?= render_options($damageOptions, $val('damage_status')) ?></select></div>

            <div class="form-section-label">Vehicle identification</div>
            <div class="field">
              <label>VIN / Chassis number <span class="field-optional">kept private — not shown on your public listing</span></label>
              <input type="text" name="vin" placeholder="17-character VIN" value="<?= e($val('vin')) ?>" autocomplete="off">
            </div>
            <div class="field-row">
              <div class="field"><label>Registration status</label><select name="registration_status"><?= render_options($registrationOptions, $val('registration_status')) ?></select></div>
              <div class="field"><label>Registration expiry</label><input type="date" name="registration_expiry" value="<?= e($val('registration_expiry')) ?>"></div>
            </div>

            <div class="form-section-label">Features</div>
            <div class="feature-groups">
              <?php foreach ($featureGroups as $groupName => $labels): ?>
                <div class="feature-group">
                  <div class="feature-group-title"><?= e($groupName) ?></div>
                  <?php foreach ($labels as $label): ?>
                    <label class="feature-checkbox">
                      <input type="checkbox" name="features[]" value="<?= e($label) ?>"<?= $isChecked($label) ?>>
                      <span><?= e($label) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="wizard-buttons">
              <button type="button" class="btn ghost" id="btn-step2-back">← Back</button>
              <button type="submit" class="btn ghost" id="btn-save-draft-2">Save Draft</button>
              <button type="button" class="btn" id="btn-step2-preview">Preview Listing →</button>
            </div>
          </div>

          <!-- ===================== PREVIEW ===================== -->
          <div class="wizard-panel" id="wizard-panel-preview">
            <div class="form-section-label">Preview</div>
            <div class="vehicle-preview-card" id="vehicle-preview-card"><!-- filled by JS --></div>
            <div class="wizard-buttons">
              <button type="button" class="btn ghost" id="btn-preview-edit">Edit</button>
              <button type="submit" class="btn" id="btn-post-vehicle"><?= $sellGroupId ? 'Post Vehicle to Group' : 'Post Vehicle' ?></button>
            </div>
          </div>
        </form>

        <script>
        (function () {
          var form         = document.getElementById('vehicle-form');
          var actionField  = document.getElementById('vehicle-form-action');
          var iconField    = document.getElementById('vehicle-icon-field');
          var errorBox     = document.getElementById('wizard-error');
          var steps        = document.getElementById('wizard-steps');
          var panel1       = document.getElementById('wizard-panel-1');
          var panel2       = document.getElementById('wizard-panel-2');
          var panelPreview = document.getElementById('wizard-panel-preview');

          var vehicleTypeIcons = {
            'Car': '🚗', 'SUV': '🚙', 'Pickup Truck': '🛻', 'Van': '🚐', 'Minivan': '🚐',
            'Motorcycle': '🏍️', 'Truck': '🚚', 'Bus': '🚌', 'RV/Camper': '🚙', 'Trailer': '🚛', 'Other': '🚗'
          };

          function showError(msg) {
            errorBox.textContent = msg;
            errorBox.style.display = 'block';
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          function clearError() { errorBox.style.display = 'none'; }

          function goToStep(n) {
            panel1.classList.toggle('active', n === 1);
            panel2.classList.toggle('active', n === 2);
            panelPreview.classList.toggle('active', n === 'preview');
            steps.querySelectorAll('.wizard-step').forEach(function (el) {
              el.classList.toggle('active', String(n) === el.getAttribute('data-step'));
              el.classList.toggle('done', n === 2 || n === 'preview' ? el.getAttribute('data-step') === '1' : false);
            });
            steps.style.display = (n === 'preview') ? 'none' : 'flex';
            window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
          }

          /* ---------- Photo uploader ---------- */
          var addTile   = document.getElementById('photo-add-tile');
          var thumbsWrap = document.getElementById('photo-thumbs');
          var fileInput  = document.getElementById('photo-file-input');
          var countHint  = document.getElementById('photo-count-hint');
          var MAX_PHOTOS = 20;

          // existingPhotos: already-saved (draft resume). newPhotos: File objects picked this session.
          var existingPhotos = <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'path' => 'image.php?type=product&id=' . (int) $p['id']], $draftPhotos)) ?>;
          var newPhotos = [];
          var csrfToken = '<?= csrf_token() ?>';

          function totalPhotoCount() { return existingPhotos.length + newPhotos.length; }

          function renderThumbs() {
            thumbsWrap.innerHTML = '';
            var isFirst = true;

            existingPhotos.forEach(function (p) {
              var tile = document.createElement('div');
              tile.className = 'photo-tile';
              tile.draggable = false;
              var img = document.createElement('img');
              img.src = p.path;
              tile.appendChild(img);
              if (isFirst) { var cover = document.createElement('span'); cover.className = 'photo-cover-badge'; cover.textContent = 'COVER'; tile.appendChild(cover); isFirst = false; }
              var rm = document.createElement('button');
              rm.type = 'button'; rm.className = 'photo-remove'; rm.innerHTML = '✕';
              rm.addEventListener('click', function () {
                var body = new URLSearchParams({ action: 'delete_product_photo', photo_id: p.id, csrf_token: csrfToken });
                fetch('dashboard.php', { method: 'POST', body: body, credentials: 'same-origin' })
                  .then(function (r) { return r.json(); })
                  .then(function (data) {
                    if (data && data.ok) {
                      existingPhotos = existingPhotos.filter(function (x) { return x.id !== p.id; });
                      renderThumbs();
                    }
                  });
              });
              tile.appendChild(rm);
              thumbsWrap.appendChild(tile);
            });

            newPhotos.forEach(function (file, idx) {
              var tile = document.createElement('div');
              tile.className = 'photo-tile';
              tile.draggable = true;
              tile.dataset.idx = idx;
              var img = document.createElement('img');
              img.src = URL.createObjectURL(file);
              tile.appendChild(img);
              if (isFirst) { var cover = document.createElement('span'); cover.className = 'photo-cover-badge'; cover.textContent = 'COVER'; tile.appendChild(cover); isFirst = false; }
              var rm = document.createElement('button');
              rm.type = 'button'; rm.className = 'photo-remove'; rm.innerHTML = '✕';
              rm.addEventListener('click', function () { newPhotos.splice(idx, 1); renderThumbs(); });
              tile.appendChild(rm);

              tile.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', idx); tile.classList.add('dragging'); });
              tile.addEventListener('dragend', function () { tile.classList.remove('dragging'); });
              tile.addEventListener('dragover', function (e) { e.preventDefault(); });
              tile.addEventListener('drop', function (e) {
                e.preventDefault();
                var fromIdx = parseInt(e.dataTransfer.getData('text/plain'), 10);
                var toIdx = idx;
                if (isNaN(fromIdx) || fromIdx === toIdx) return;
                var moved = newPhotos.splice(fromIdx, 1)[0];
                newPhotos.splice(toIdx, 0, moved);
                renderThumbs();
              });

              thumbsWrap.appendChild(tile);
            });

            countHint.textContent = totalPhotoCount() + ' / ' + MAX_PHOTOS + ' photos added' + (existingPhotos.length && newPhotos.length ? ' (reorder new photos by dragging)' : '');
            addTile.style.display = totalPhotoCount() >= MAX_PHOTOS ? 'none' : 'flex';
            syncFileInput();
          }

          function syncFileInput() {
            var dt = new DataTransfer();
            newPhotos.forEach(function (f) { dt.items.add(f); });
            fileInput.files = dt.files;
          }

          addTile.addEventListener('click', function () { fileInput.click(); });
          fileInput.addEventListener('change', function () {
            var room = MAX_PHOTOS - totalPhotoCount();
            Array.prototype.slice.call(fileInput.files).slice(0, room).forEach(function (f) { newPhotos.push(f); });
            renderThumbs();
          });
          ['dragenter', 'dragover'].forEach(function (evt) {
            addTile.addEventListener(evt, function (e) { e.preventDefault(); addTile.classList.add('drag-over'); });
          });
          ['dragleave', 'drop'].forEach(function (evt) {
            addTile.addEventListener(evt, function (e) { e.preventDefault(); addTile.classList.remove('drag-over'); });
          });
          addTile.addEventListener('drop', function (e) {
            var files = Array.prototype.slice.call(e.dataTransfer.files).filter(function (f) { return f.type.indexOf('image/') === 0; });
            var room = MAX_PHOTOS - totalPhotoCount();
            files.slice(0, room).forEach(function (f) { newPhotos.push(f); });
            renderThumbs();
          });

          renderThumbs();

          /* ---------- Conditional fields ---------- */
          function updateConditionalFields() {
            var vType = document.getElementById('vf-vehicle_type').value;
            var fType = document.getElementById('vf-fuel_type') ? document.getElementById('vf-fuel_type').value : '';
            document.querySelectorAll('.conditional-field-group').forEach(function (grp) {
              var field = grp.getAttribute('data-show-field');
              var values = grp.getAttribute('data-show-value').split(',');
              var current = field === 'vehicle_type' ? vType : fType;
              grp.style.display = values.indexOf(current) !== -1 ? 'block' : 'none';
            });
            // Fields that don't apply to motorcycles.
            var isMoto = vType === 'Motorcycle';
            document.querySelectorAll('.body-type-field, .drivetrain-field, .cylinders-field').forEach(function (el) {
              el.style.display = isMoto ? 'none' : '';
            });
            var icon = vehicleTypeIcons[vType] || '🚗';
            iconField.value = icon;
          }
          document.getElementById('vf-vehicle_type').addEventListener('change', updateConditionalFields);
          if (document.getElementById('vf-fuel_type')) {
            document.getElementById('vf-fuel_type').addEventListener('change', updateConditionalFields);
          }
          updateConditionalFields();

          /* ---------- Step navigation & validation ---------- */
          document.getElementById('btn-step1-next').addEventListener('click', function () {
            clearError();
            var vType = document.getElementById('vf-vehicle_type').value.trim();
            var title = document.getElementById('vf-title').value.trim();
            var price = parseFloat(document.getElementById('vf-price').value);
            var loc   = document.getElementById('vf-location').value.trim();

            if (!vType) return showError('Please choose a vehicle type.');
            if (!title) return showError('Please give your listing a title.');
            if (!price || price <= 0) return showError('Please enter a price.');
            if (!loc) return showError('Please enter a meet-up location.');
            if (totalPhotoCount() < 1) return showError('Please add at least one photo.');

            goToStep(2);
          });

          document.getElementById('btn-step2-back').addEventListener('click', function () {
            clearError();
            goToStep(1);
          });

          function validateStep2ForPreview() {
            var make = document.getElementById('vf-make').value.trim();
            var model = document.getElementById('vf-model').value.trim();
            var year = document.getElementById('vf-year').value.trim();
            var mileage = document.getElementById('vf-mileage').value.trim();
            if (!make) return 'Please enter the make.';
            if (!model) return 'Please enter the model.';
            if (!year) return 'Please enter the year.';
            if (!mileage) return 'Please enter the mileage.';
            return null;
          }

          function buildPreview() {
            var fd = new FormData(form);
            var get = function (name) { return (fd.get(name) || '').toString(); };
            var photos = existingPhotos.map(function (p) { return p.path; })
              .concat(newPhotos.map(function (f) { return URL.createObjectURL(f); }));

            var metaBits = [];
            var mileage = get('mileage'), unit = get('mileage_unit') || 'km';
            if (mileage) metaBits.push(mileage + ' ' + unit);
            if (get('transmission')) metaBits.push(get('transmission'));
            if (get('fuel_type')) metaBits.push(get('fuel_type'));

            var detailRows = [
              ['Year', get('year')], ['Make', get('make')], ['Model', get('model')], ['Trim', get('trim')],
              ['Body type', get('body_type')], ['Mileage', mileage ? (mileage + ' ' + unit) : ''],
              ['Condition', get('condition')], ['Fuel', get('fuel_type')], ['Transmission', get('transmission')],
              ['Drivetrain', get('drivetrain')], ['Exterior color', get('exterior_color')], ['Interior color', get('interior_color')],
              ['Ownership', get('ownership')], ['Service history', get('service_history')],
              ['Accident history', get('accident_history')], ['Registration', get('registration_status')],
            ].filter(function (r) { return r[1]; });

            var features = fd.getAll('features[]');

            var html = '';
            html += '<div class="preview-photo-strip">' + (photos.length
              ? photos.map(function (src) { return '<img src="' + src + '">'; }).join('')
              : '<div class="preview-photo-fallback">' + (iconField.value || '🚗') + '</div>') + '</div>';
            html += '<h2 style="margin-top:14px;">' + escapeHtml(get('title') || 'Untitled vehicle') + '</h2>';
            html += '<div class="price" style="font-size:22px;margin-top:4px;">₱' + formatMoney(get('price')) + (get('negotiable') ? ' <span class="pill" style="background:var(--gold-light);color:#7A5308;">Negotiable</span>' : '') + '</div>';
            if (metaBits.length) html += '<div class="sub" style="margin-top:6px;color:var(--ink-soft);">' + metaBits.map(escapeHtml).join(' · ') + '</div>';
            html += '<div class="sub" style="margin-top:4px;color:var(--ink-soft);">📍 ' + escapeHtml(get('location')) + '</div>';
            if (get('description')) html += '<p style="margin-top:14px;color:var(--ink-soft);line-height:1.6;white-space:pre-line;">' + escapeHtml(get('description')) + '</p>';

            if (detailRows.length) {
              html += '<div class="form-section-label" style="margin-top:20px;">Vehicle details</div>';
              html += '<div class="preview-detail-grid">' + detailRows.map(function (r) {
                return '<div class="preview-detail-row"><span>' + escapeHtml(r[0]) + '</span><strong>' + escapeHtml(r[1]) + '</strong></div>';
              }).join('') + '</div>';
            }
            if (features.length) {
              html += '<div class="form-section-label" style="margin-top:20px;">Features</div>';
              html += '<div class="preview-features">' + features.map(function (f) { return '<span>✓ ' + escapeHtml(f) + '</span>'; }).join('') + '</div>';
            }

            document.getElementById('vehicle-preview-card').innerHTML = html;
          }

          function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
          function formatMoney(v) { var n = parseFloat(v); return isNaN(n) ? '0.00' : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

          document.getElementById('btn-step2-preview').addEventListener('click', function () {
            clearError();
            var err = validateStep2ForPreview();
            if (err) return showError(err);
            buildPreview();
            goToStep('preview');
          });

          document.getElementById('btn-preview-edit').addEventListener('click', function () {
            clearError();
            goToStep(2);
          });

          /* ---------- Submit intents ---------- */
          [document.getElementById('btn-save-draft'), document.getElementById('btn-save-draft-2')].forEach(function (btn) {
            btn.addEventListener('click', function () { actionField.value = 'save_vehicle_draft'; });
          });
          document.getElementById('btn-post-vehicle').addEventListener('click', function (e) {
            actionField.value = 'post_vehicle';
          });
        })();
        </script>
      <?php endif; ?>

    <?php elseif ($view === 'my-listings'): ?>
      <div class="row-head"><h2>My listings</h2><a class="btn sm" href="?view=sell">+ Post item</a></div>
      <?php if ($pageData['listings']->num_rows === 0): ?><p style="color:var(--ink-soft);">You haven't posted anything yet.</p><?php endif; ?>
      <div class="grid">
        <?php while ($p = $pageData['listings']->fetch_assoc()):
          $lThumbs = product_thumbs($p['images_concat'] ?? null);
          $isRemoved = (int) $p['is_deleted'] === 1;
          $isDraft   = (int) ($p['is_draft'] ?? 0) === 1;
          // Sold = not admin-removed, not a draft, but no longer active
          // — that only happens once the buyer has confirmed the item
          // was received.
          $isSold = !$isRemoved && !$isDraft && (int) $p['is_active'] === 0; ?>
          <div class="card" style="<?= ($isRemoved || $isSold) ? 'opacity:.7;' : '' ?>">
            <div class="icon" style="<?= $lThumbs ? 'font-size:0;' : '' ?>">
              <?php if ($lThumbs): ?>
                <img src="<?= e($lThumbs[0]) ?>" alt="" style="width:100%;height:80px;object-fit:cover;border-radius:10px;">
              <?php else: ?>
                <?= e($p['icon']) ?>
              <?php endif; ?>
            </div>
            <h4><?php if ($isDraft): ?><?= e($p['title'] !== '' ? $p['title'] : 'Untitled draft') ?><?php else: ?><a href="?view=listing&id=<?= (int)$p['id'] ?>"><?= e($p['title']) ?></a><?php endif; ?></h4>
            <div class="price">₱<?= number_format((float)$p['price'], 2) ?></div>
            <?php $lQty = isset($p['quantity']) ? (int) $p['quantity'] : 1; ?>
            <?php if (!$isDraft && !$isRemoved && !$isSold): ?>
              <div class="sub"><?= $lQty ?> in stock</div>
            <?php endif; ?>
            <?php if ($isDraft): ?>
              <span class="pill" style="background:var(--gold-light);color:#7A5308;">📝 Draft</span><br>
              <a href="?view=sell&type=vehicle&draft=<?= (int)$p['id'] ?>" style="font-size:11.5px;font-weight:600;color:var(--brand);">Continue editing →</a>
            <?php elseif ($isRemoved): ?>
              <span class="pill" style="background:var(--danger-bg);color:var(--danger);">Removed by admin</span>
            <?php elseif ($isSold): ?>
              <span class="pill ok">Sold<?= $p['sold_to'] ? ' to ' . e($p['sold_to']) : '' ?></span>
            <?php elseif (!empty($p['pending_tx_id'])): ?>
              <span class="pill" style="background:var(--gold-light);color:#7A5308;">⏳ Buyer confirmed — action needed</span><br>
              <a href="?view=chat&t=<?= (int)$p['pending_tx_id'] ?>" style="font-size:11.5px;font-weight:600;color:var(--brand);">Confirm the sale →</a>
            <?php else: ?>
              <div class="sub"><?= (int)$p['interested'] ?> interested buyer(s)</div>
              <?php if (!$lThumbs): ?>
                <a href="?view=listing&id=<?= (int)$p['id'] ?>" style="font-size:11.5px;font-weight:600;color:var(--danger);">+ Add photos</a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endwhile; ?>
      </div>

    <?php elseif ($view === 'listing'):
      $p = $pageData['product'] ?? null;
      $pThumbs = $p ? product_thumbs($p['images_concat'] ?? null) : []; ?>
      <a class="crumb" href="?view=my-listings">← My listings</a>
      <?php if (!$p): ?><p>Listing not found.</p><?php else: ?>
      <div class="detail-card">
        <?php if ($pThumbs): ?>
          <div class="strip-hero" style="max-width:340px;">
            <div class="photo-strip">
              <?php foreach ($pThumbs as $src): ?><img src="<?= e($src) ?>" alt=""><?php endforeach; ?>
            </div>
            <?php if (count($pThumbs) > 1): ?>
              <div class="dots"><?php foreach ($pThumbs as $idx => $_): ?><span class="dot<?= $idx === 0 ? ' active' : '' ?>"></span><?php endforeach; ?></div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="detail-hero"><?= e($p['icon']) ?></div>
        <?php endif; ?>
        <h2><?= e($p['title']) ?></h2>
        <div class="price" style="margin-top:8px;">₱<?= number_format((float)$p['price'], 2) ?></div>
        <?php $pQty = isset($p['quantity']) ? (int) $p['quantity'] : 1; ?>
        <div class="sub" style="margin-top:4px;color:var(--ink-soft);">📦 <?= $pQty ?> in stock</div>
        <?php if ((int) $p['is_deleted'] === 1): ?>
          <div class="status-banner" style="background:var(--danger-bg);color:var(--danger);">Removed by admin</div>
        <?php elseif (!empty($p['pending_tx_id'])): ?>
          <div class="status-banner gold">A buyer says they've received this item — confirm the sale to mark it Sold.</div>
          <form method="post" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_sale">
            <input type="hidden" name="transaction_id" value="<?= (int)$p['pending_tx_id'] ?>">
            <button class="btn" type="submit">Confirm item sold</button>
          </form>
        <?php elseif ((int) $p['is_active'] === 0): ?>
          <div class="status-banner">✅ Sold — no longer visible in the marketplace.</div>
        <?php else: ?>
          <div class="status-banner gold">Live — waiting for someone to message you</div>
        <?php endif; ?>

        <?php if (!$pThumbs): ?>
          <div class="status-banner" style="background:var(--danger-bg);color:var(--danger);">This listing has no photos yet — buyers see a placeholder instead of the item. Add some below.</div>
        <?php endif; ?>
        <?php if (!gd_available()): ?>
          <div class="alert error">Photo uploads are currently disabled on this server — the GD image library isn't enabled in PHP. Ask an admin to enable the <code>gd</code> extension in <code>php.ini</code> and restart Apache before trying again.</div>
        <?php elseif (count($pThumbs) < 6): ?>
          <form method="post" enctype="multipart/form-data" style="margin-top:14px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_photos">
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <div class="field" style="margin-top:0;flex:1;min-width:220px;">
              <label><?= $pThumbs ? 'Add more photos' : 'Add photos' ?> (<?= 6 - count($pThumbs) ?> slot<?= (6 - count($pThumbs)) === 1 ? '' : 's' ?> left)</label>
              <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
            </div>
            <button class="btn sm" type="submit">Upload</button>
          </form>
        <?php endif; ?>

        <h3 style="font-size:14px;margin-top:18px;">Interested buyers</h3>
        <?php if ($pageData['buyers']->num_rows === 0): ?><p style="color:var(--ink-soft);font-size:13px;">No messages yet.</p><?php endif; ?>
        <?php while ($b = $pageData['buyers']->fetch_assoc()): $bQty = isset($b['quantity']) ? (int) $b['quantity'] : 1; ?>
          <div class="buyer-row">
            <div class="info"><strong class="hp-trigger" data-uid="<?= (int)$b['buyer_id'] ?>"><?= e($b['first_name'] . ' ' . $b['last_name']) ?></strong><br>
              <span class="sub"><?= e(ucwords(str_replace('_', ' ', $b['status']))) ?><?= $bQty > 1 ? ' · wants ' . $bQty : '' ?></span></div>
            <a class="btn sm" href="?view=chat&t=<?= (int)$b['id'] ?>">Respond</a>
          </div>
        <?php endwhile; ?>
      </div>
      <?php endif; ?>

    <?php elseif ($view === 'chat'):
      $tx = $pageData['tx'] ?? null; ?>
      <a class="crumb" href="?view=home">← Back</a>
      <?php if (!$tx): ?><p>Conversation not found.</p><?php else:
        $other = tx_other_party($tx, $userId);
        $isBuyerHere = (int) $tx['buyer_id'] === $userId;
        $lastMsgId = 0; ?>
      <div class="row-head"><h2><?= e($tx['product_icon']) ?> <?= e($tx['product_title']) ?></h2></div>
      <div class="chat-box" id="chat-box" data-tx-id="<?= (int)$tx['id'] ?>" data-status="<?= e($tx['status']) ?>">
        <div style="padding:14px 18px;border-bottom:1px solid var(--line);font-size:13px;">
          Chatting with <a href="?view=profile&id=<?= (int)$other['id'] ?>" class="hp-trigger" data-uid="<?= (int)$other['id'] ?>" style="color:var(--brand);font-weight:700;text-decoration:none;"><?= e($other['name']) ?></a> (<?= e($other['role']) ?>)
        </div>
        <?php if ((int) $tx['product_quantity'] > 1): ?>
        <div style="padding:10px 18px;border-bottom:1px solid var(--line);font-size:13px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
          <span>📦 Quantity: <strong><?= (int) $tx['quantity'] ?></strong> of <?= (int) $tx['product_quantity'] ?> available · Total: <strong>₱<?= number_format((float) $tx['product_price'] * (int) $tx['quantity'], 2) ?></strong></span>
          <?php if ($isBuyerHere && $tx['status'] === 'negotiating'): ?>
            <form method="post" style="display:flex;align-items:center;gap:6px;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_quantity">
              <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
              <input type="number" name="quantity" min="1" max="<?= (int) $tx['product_quantity'] ?>" value="<?= (int) $tx['quantity'] ?>" style="width:64px;padding:6px 8px;border-radius:8px;border:1.5px solid var(--line);background:var(--cream);font-family:inherit;font-size:13px;">
              <button class="btn sm ghost" type="submit">Update qty to buy</button>
            </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="chat-body" id="chat-body">
          <?php while ($m = $pageData['messages']->fetch_assoc()): $lastMsgId = max($lastMsgId, (int)$m['id']); ?>
            <div class="msg <?= (int)$m['sender_id'] === $userId ? 'me' : 'them' ?>" data-id="<?= (int)$m['id'] ?>"><?= nl2br(e($m['content'])) ?></div>
          <?php endwhile; ?>
        </div>
        <form method="post" class="chat-input" id="chat-form"><?= csrf_field() ?>
          <input type="hidden" name="action" value="send_message">
          <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
          <input type="text" name="content" id="chat-input-field" placeholder="Type a message…" required autocomplete="off">
          <button class="btn sm" type="submit">Send</button>
        </form>
      </div>

      <div id="chat-status-area">
      <?php if ($tx['status'] === 'negotiating'): ?>
        <?php if ($isBuyerHere): ?>
          <a class="btn" style="margin-top:16px;" href="?view=meetup&t=<?= (int)$tx['id'] ?>">Propose a meet-up</a>
        <?php else: ?>
          <div class="status-banner gold" style="margin-top:16px;">Waiting for the buyer to propose a meet-up time and place.</div>
        <?php endif; ?>
      <?php elseif ($tx['status'] === 'meetup_proposed'): ?>
        <div class="status-banner gold">Proposed: <?= e($tx['meetup_date']) ?> <?= e($tx['meetup_time']) ?> — <?= e($tx['meetup_location']) ?></div>
        <?php if ($isBuyerHere): ?>
          <div class="status-banner gold">Waiting for the seller to confirm this meet-up before you can mark the item received.</div>
        <?php else: ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="confirm_meetup">
              <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
              <button class="btn" type="submit">Confirm meet-up</button>
            </form>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="reject_meetup">
              <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
              <button class="btn ghost" type="submit">Request a different time</button>
            </form>
          </div>
        <?php endif; ?>
      <?php elseif ($tx['status'] === 'meetup_scheduled'): ?>
        <div class="status-banner">✅ Meet-up confirmed for <?= e($tx['meetup_date']) ?> <?= e($tx['meetup_time']) ?> — <?= e($tx['meetup_location']) ?></div>
        <?php if ($isBuyerHere): ?>
          <a class="btn" href="?view=feedback&t=<?= (int)$tx['id'] ?>">Item received? Leave feedback</a>
        <?php else: ?>
          <div class="status-banner gold">Waiting for the buyer to confirm they received the item.</div>
        <?php endif; ?>
      <?php elseif ($tx['status'] === 'confirmed'): ?>
        <?php if ($isBuyerHere): ?>
          <div class="status-banner gold">You confirmed you received the item — waiting for the seller to confirm the sale.</div>
        <?php else: ?>
          <div class="status-banner gold">The buyer confirmed they received the item.</div>
          <form method="post" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_sale">
            <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
            <button class="btn" type="submit">Confirm item sold</button>
          </form>
        <?php endif; ?>
      <?php elseif ($tx['status'] === 'completed'): ?>
        <div class="status-banner">✅ Trade completed<?= $isBuyerHere ? '.' : ' — you confirmed the sale.' ?></div>
      <?php endif; ?>
      </div>

      <script>
      (function () {
        var box = document.getElementById('chat-box');
        if (!box) return;
        var txId = box.dataset.txId;
        var body = document.getElementById('chat-body');
        var form = document.getElementById('chat-form');
        var field = document.getElementById('chat-input-field');
        var lastId = <?= (int) $lastMsgId ?>;
        var knownStatus = box.dataset.status;
        var csrfToken = '<?= csrf_token() ?>';

        function scrollToBottom() { body.scrollTop = body.scrollHeight; }
        scrollToBottom();

        function appendMessage(m) {
          if (body.querySelector('[data-id="' + m.id + '"]')) return; // already shown
          var div = document.createElement('div');
          div.className = 'msg ' + (m.mine ? 'me' : 'them');
          div.dataset.id = m.id;
          div.textContent = m.content; // textContent = safe, no HTML injection
          body.appendChild(div);
          if (m.id > lastId) lastId = m.id;
        }

        function poll() {
          fetch('chat-api.php?t=' + encodeURIComponent(txId) + '&after=' + lastId, { credentials: 'same-origin' })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
              if (!data) return;
              var hadNew = false;
              (data.messages || []).forEach(function (m) { appendMessage(m); hadNew = true; });
              if (hadNew) scrollToBottom();
              // If the trade status changed on the server (e.g. the other
              // side scheduled a meetup or confirmed the sale), reload so
              // the action buttons below the chat stay in sync.
              if (data.status && data.status !== knownStatus) {
                location.reload();
              }
            })
            .catch(function () { /* silent — will retry on next tick */ });
        }

        var pollTimer = setInterval(poll, 3000);
        poll();

        if (form) {
          form.addEventListener('submit', function (e) {
            e.preventDefault();
            var content = field.value.trim();
            if (!content) return;
            field.disabled = true;
            fetch('chat-api.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: 'transaction_id=' + encodeURIComponent(txId) +
                    '&content=' + encodeURIComponent(content) +
                    '&csrf_token=' + encodeURIComponent(csrfToken)
            })
              .then(function (res) { return res.json(); })
              .then(function (data) {
                if (data && data.message) {
                  appendMessage(data.message);
                  scrollToBottom();
                  field.value = '';
                }
              })
              .catch(function () { /* leave the text so the user can retry */ })
              .finally(function () { field.disabled = false; field.focus(); });
          });
        }
      })();
      </script>
      <?php endif; ?>

    <?php elseif ($view === 'meetup'):
      $tx = $pageData['tx'] ?? null; ?>
      <a class="crumb" href="?view=chat&t=<?= (int)($_GET['t'] ?? 0) ?>">← Back to chat</a>
      <?php if (!$tx): ?>
        <p>Conversation not found.</p>
      <?php elseif ((int) $tx['buyer_id'] !== $userId): ?>
        <div class="row-head"><h2>Propose a meet-up</h2></div>
        <div class="detail-card"><p style="color:var(--ink-soft);">Only the buyer can propose a meet-up time and place. You'll be asked to confirm it here once they do.</p></div>
      <?php elseif ($tx['status'] !== 'negotiating'): ?>
        <div class="row-head"><h2>Propose a meet-up</h2></div>
        <div class="detail-card">
          <p style="color:var(--ink-soft);">A meet-up has already been proposed for this trade.</p>
          <a class="btn sm" style="margin-top:12px;" href="?view=chat&t=<?= (int)$tx['id'] ?>">Back to chat</a>
        </div>
      <?php else: ?>
      <div class="row-head"><h2>Propose a meet-up</h2></div>
      <form method="post" class="form-card" id="meetup-form"><?= csrf_field() ?>
        <input type="hidden" name="action" value="schedule_meetup">
        <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">

        <?php $meetupAvailable = (int) $tx['product_quantity']; ?>
        <?php if ($meetupAvailable > 1): ?>
        <div class="field">
          <label>Quantity <span class="field-optional">how many you want to buy — <?= $meetupAvailable ?> available</span></label>
          <input type="number" name="quantity" id="meetup-qty" min="1" max="<?= $meetupAvailable ?>" value="<?= (int) ($tx['quantity'] ?? 1) ?>">
        </div>
        <div id="meetup-total" style="margin-top:6px;font-size:13px;color:var(--ink-soft);font-weight:600;"></div>
        <?php else: ?>
          <input type="hidden" name="quantity" value="1">
        <?php endif; ?>

        <div class="field-row" style="margin-top:<?= $meetupAvailable > 1 ? '16px' : '0' ?>;">
          <div class="field"><label>Date</label><input type="date" name="meetup_date"></div>
          <div class="field"><label>Time</label><input type="time" name="meetup_time"></div>
        </div>
        <div class="field"><label>Meet-up spot</label>
          <input type="text" name="meetup_location" placeholder="e.g. Robinsons Town Mall — Atrium" required>
          <div class="field-hint">Suggest wherever works for both of you — the seller can confirm it or ask for a different spot.</div>
        </div>
        <p style="margin-top:14px;font-size:12.5px;color:var(--ink-soft);">The seller will need to confirm this before it's finalized.</p>
        <button class="btn" style="margin-top:10px;" type="submit">Send proposal to seller</button>
      </form>
      <?php if ($meetupAvailable > 1): ?>
      <script>
      (function () {
        var qtyInput = document.getElementById('meetup-qty');
        var totalEl  = document.getElementById('meetup-total');
        var unitPrice = <?= json_encode((float) $tx['product_price']) ?>;

        function fmtMoney(n) {
          return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function update() {
          var qty = parseInt(qtyInput.value, 10);
          if (!qty || qty < 1) qty = 1;
          totalEl.textContent = 'Total: ' + qty + ' item' + (qty === 1 ? '' : 's') + ' · ₱' + fmtMoney(unitPrice * qty);
        }
        qtyInput.addEventListener('input', update);
        update();
      })();
      </script>
      <?php endif; ?>
      <?php endif; ?>

    <?php elseif ($view === 'feedback'):
      $tx = $pageData['tx'] ?? null; ?>
      <?php if (!$tx): ?><p>Conversation not found.</p>
      <?php elseif ((int)$tx['buyer_id'] !== $userId): ?>
        <div class="row-head"><h2>Item received</h2></div>
        <div class="detail-card">
          <p style="color:var(--ink-soft);">Only the buyer can confirm that the item was received. You'll see a confirmation here once they do.</p>
        </div>
      <?php else: ?>
      <div class="row-head"><h2>How was the trade?</h2></div>
      <div class="detail-card">
        <p style="color:var(--ink-soft);">This lets the seller know you received the item. The trade will be marked complete — and the chat cleared — once the seller confirms the sale.</p>
        <form method="post" id="star-feedback-form" style="margin-top:18px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="submit_feedback">
          <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
          <div class="star-picker" id="star-picker">
            <input type="radio" id="fbstar5" name="rating" value="5"><label for="fbstar5" title="5 stars">★</label>
            <input type="radio" id="fbstar4" name="rating" value="4"><label for="fbstar4" title="4 stars">★</label>
            <input type="radio" id="fbstar3" name="rating" value="3"><label for="fbstar3" title="3 stars">★</label>
            <input type="radio" id="fbstar2" name="rating" value="2"><label for="fbstar2" title="2 stars">★</label>
            <input type="radio" id="fbstar1" name="rating" value="1"><label for="fbstar1" title="1 star">★</label>
          </div>
          <div class="star-picker-hint" id="star-picker-hint">Tap a star to rate this trade — this updates the seller's profile rating.</div>
          <div class="field">
            <label>Add a comment (optional)</label>
            <textarea name="review_text" rows="3" maxlength="500" placeholder="Tell others what the trade was like…"></textarea>
          </div>
          <button class="btn" type="submit" style="margin-top:16px;">Submit rating</button>
        </form>
      </div>
      <script>
      (function () {
        var labels = {1:'1 star — Had an issue',2:'2 stars — Below average',3:'3 stars — Good trade',4:'4 stars — Great trade',5:'5 stars — Excellent trade'};
        var hint = document.getElementById('star-picker-hint');
        var picker = document.getElementById('star-picker');
        if (!picker) return;
        picker.querySelectorAll('input[name="rating"]').forEach(function (input) {
          input.addEventListener('change', function () {
            hint.textContent = labels[input.value] || '';
          });
        });
        var form = document.getElementById('star-feedback-form');
        form.addEventListener('submit', function (e) {
          if (!picker.querySelector('input[name="rating"]:checked')) {
            e.preventDefault();
            hint.textContent = 'Please select a star rating first.';
            hint.style.color = 'var(--danger)';
          }
        });
      })();
      </script>
      <?php endif; ?>

    <?php elseif ($view === 'groups'): ?>
      <div class="row-head">
        <h2>Browse groups</h2>
        <div style="display:flex;gap:8px;">
          <button class="btn sm ghost" onclick="document.getElementById('join-code-modal').style.display='flex'">🔑 Join via Code</button>
          <a class="btn sm" href="?view=create-group">+ Make a group</a>
        </div>
      </div>

      <!-- Realtime search bar -->
      <div style="position:relative;max-width:480px;margin-bottom:20px;">
        <span style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--ink-faint);font-size:15px;pointer-events:none;">🔍</span>
        <input type="text" id="group-search" placeholder="Search groups…" autocomplete="off" style="
          width:100%;padding:12px 16px 12px 42px;border-radius:999px;
          border:1.5px solid var(--line-strong);background:var(--paper);
          font-family:inherit;font-size:14px;color:var(--ink);outline:none;
          transition:border-color .15s ease,box-shadow .15s ease;
        " onfocus="this.style.borderColor='var(--brand)';this.style.boxShadow='0 0 0 3px rgba(21,89,67,0.12)'"
           onblur="this.style.borderColor='var(--line-strong)';this.style.boxShadow='none'">
        <button id="group-search-clear" onclick="document.getElementById('group-search').value='';groupSearch();" style="
          display:none;position:absolute;right:14px;top:50%;transform:translateY(-50%);
          background:none;border:none;cursor:pointer;color:var(--ink-soft);font-size:18px;line-height:1;padding:4px;
        ">✕</button>
      </div>

      <div id="group-no-results" style="display:none;padding:32px;text-align:center;color:var(--ink-soft);font-size:14px;background:var(--paper);border-radius:var(--radius);">
        No groups match your search.
      </div>

      <?php
      // Split the full list into three buckets, most-personal first:
      //   1. Created by me     — groups this account owns
      //   2. Joined            — a member of, but someone else owns
      //   3. Other Groups      — everything not joined at all
      $createdGroupsList = [];
      $joinedGroupsList  = [];
      $otherGroupsList   = [];
      while ($g = $pageData['groups']->fetch_assoc()) {
          if ((int) $g['owner_id'] === $userId) {
              $createdGroupsList[] = $g;
          } elseif (in_array((int) $g['id'], $myGroupIds, true)) {
              $joinedGroupsList[] = $g;
          } else {
              $otherGroupsList[] = $g;
          }
      }

      /** Renders one group card — shared by every grid on this page. */
      function render_group_card(array $g, bool $joined): string
      {
          ob_start(); ?>
          <a class="card group-card" href="?view=group&id=<?= (int)$g['id'] ?>"
             data-name="<?= e(strtolower($g['name'])) ?>"
             data-desc="<?= e(strtolower($g['description'] ?? '')) ?>">
            <?= group_thumb_html($g) ?>
            <h4><?= e($g['name']) ?></h4>
            <div class="sub"><?= (int)$g['member_count'] ?> members<?= !empty($g['category']) ? ' · ' . e($g['category']) : '' ?></div>
            <?php if ($joined): ?><span class="pill ok">Joined — tap to open</span><?php else: ?><span class="pill">Tap to view &amp; join</span><?php endif; ?>
          </a>
          <?php
          return ob_get_clean();
      }
      ?>

      <div id="created-groups-section" style="<?= $createdGroupsList ? '' : 'display:none;' ?>">
        <div class="row-head" style="margin-top:0;"><h2 style="font-size:16px;">Created by me</h2></div>
        <div class="hscroll-wrap">
          <div class="hscroll-row" id="created-groups-grid">
            <?php foreach ($createdGroupsList as $g): echo render_group_card($g, true); endforeach; ?>
          </div>
          <button type="button" class="hscroll-nav prev" aria-label="Scroll left">‹</button>
          <button type="button" class="hscroll-nav next" aria-label="Scroll right">›</button>
        </div>
      </div>

      <div id="joined-groups-section" style="<?= $joinedGroupsList ? '' : 'display:none;' ?>">
        <div class="row-head"><h2 style="font-size:16px;">Joined</h2></div>
        <div class="hscroll-wrap">
          <div class="hscroll-row" id="joined-groups-grid">
            <?php foreach ($joinedGroupsList as $g): echo render_group_card($g, true); endforeach; ?>
          </div>
          <button type="button" class="hscroll-nav prev" aria-label="Scroll left">‹</button>
          <button type="button" class="hscroll-nav next" aria-label="Scroll right">›</button>
        </div>
      </div>

      <div id="other-groups-section" style="<?= $otherGroupsList ? '' : 'display:none;' ?>">
        <div class="row-head"><h2 style="font-size:16px;">Other Groups</h2></div>
        <div class="hscroll-wrap">
          <div class="hscroll-row" id="other-groups-grid">
            <?php foreach ($otherGroupsList as $g): echo render_group_card($g, false); endforeach; ?>
          </div>
          <button type="button" class="hscroll-nav prev" aria-label="Scroll left">‹</button>
          <button type="button" class="hscroll-nav next" aria-label="Scroll right">›</button>
        </div>
      </div>

      <!-- ===== Join via Code Modal ===== -->
      <div id="join-code-modal" style="display:none;position:fixed;inset:0;z-index:9999;
        background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);
        align-items:center;justify-content:center;padding:20px;">
        <div style="background:var(--cream);border-radius:22px;padding:34px 30px;
          max-width:440px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);
          animation:joinModalIn .22s cubic-bezier(.22,.75,.28,1) both;">

          <div style="width:52px;height:52px;border-radius:50%;background:var(--brand-light);
            display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 16px;">🔑</div>
          <h3 style="font-family:'Fraunces',serif;font-size:19px;text-align:center;margin:0 0 6px;">Join via Group Code</h3>
          <p style="text-align:center;font-size:13px;color:var(--ink-soft);margin:0 0 20px;">Paste the code shared by a group member to join instantly.</p>

          <!-- Code input -->
          <input type="text" id="code-input" maxlength="10" placeholder="e.g. A1B2C3D4"
            oninput="this.value=this.value.toUpperCase();previewGroup(this.value)"
            style="width:100%;padding:14px 16px;border-radius:13px;text-align:center;
            letter-spacing:.18em;font-size:20px;font-weight:700;font-family:'IBM Plex Mono',monospace;
            border:1.5px solid var(--line-strong);background:var(--paper);color:var(--ink);
            outline:none;transition:border-color .15s ease;">

          <!-- Group preview area -->
          <div id="code-preview" style="margin-top:14px;display:none;">
            <div style="background:var(--paper);border-radius:14px;padding:16px;display:flex;gap:14px;align-items:center;">
              <div id="cprev-photo" style="width:52px;height:52px;border-radius:10px;background:var(--brand-light);
                display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;overflow:hidden;"></div>
              <div style="flex:1;min-width:0;">
                <div id="cprev-name" style="font-family:'Fraunces',serif;font-weight:700;font-size:16px;color:var(--ink);"></div>
                <div id="cprev-members" style="font-size:12.5px;color:var(--ink-soft);margin-top:3px;"></div>
                <div id="cprev-desc" style="font-size:12.5px;color:var(--ink-soft);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
              </div>
              <span id="cprev-status" class="pill ok" style="flex-shrink:0;"></span>
            </div>
          </div>
          <div id="code-error" style="display:none;margin-top:10px;font-size:13px;color:var(--danger);text-align:center;font-weight:600;"></div>
          <div id="code-loading" style="display:none;margin-top:10px;font-size:13px;color:var(--ink-soft);text-align:center;">Looking up code…</div>

          <!-- Confirm join form (shown after valid preview) -->
          <form method="post" id="code-join-form" style="display:none;margin-top:16px;"><?= csrf_field() ?>
            <input type="hidden" name="action" value="join_via_code">
            <input type="hidden" name="join_code" id="code-join-value">
            <div style="display:flex;gap:10px;">
              <button type="button" onclick="closeCodeModal()" style="flex:1;padding:13px;border-radius:999px;
                font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);
                background:transparent;color:var(--ink-soft);cursor:pointer;">Cancel</button>
              <button type="submit" style="flex:1;padding:13px;border-radius:999px;font-weight:700;
                font-size:14px;border:none;background:var(--brand);color:var(--cream);cursor:pointer;
                box-shadow:0 2px 8px rgba(9,44,33,.25);">Join now</button>
            </div>
          </form>
          <div id="code-cancel-only" style="margin-top:16px;">
            <button type="button" onclick="closeCodeModal()" style="width:100%;padding:13px;border-radius:999px;
              font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);
              background:transparent;color:var(--ink-soft);cursor:pointer;">Close</button>
          </div>
        </div>
      </div>

      <script>
      // Group realtime search
      function groupSearch() {
        var q = (document.getElementById('group-search').value || '').trim().toLowerCase();
        var clear = document.getElementById('group-search-clear');
        var noRes = document.getElementById('group-no-results');
        clear.style.display = q ? 'block' : 'none';

        var totalShown = 0;
        [
          ['created-groups-section', 'created-groups-grid'],
          ['joined-groups-section', 'joined-groups-grid'],
          ['other-groups-section', 'other-groups-grid']
        ].forEach(function (pair) {
          var section = document.getElementById(pair[0]);
          var grid = document.getElementById(pair[1]);
          if (!section || !grid) return;
          var cards = grid.querySelectorAll('.group-card');
          var shownInSection = 0;
          cards.forEach(function (c) {
            var name = (c.getAttribute('data-name') || '');
            var desc = (c.getAttribute('data-desc') || '');
            var match = !q || name.indexOf(q) !== -1 || desc.indexOf(q) !== -1;
            c.style.display = match ? '' : 'none';
            if (match) shownInSection++;
          });
          // Hide the section (header + grid) whenever nothing in it is
          // currently visible — whether that's because the person has
          // no groups in that bucket at all, or the search excluded
          // everything in it.
          section.style.display = (shownInSection === 0) ? 'none' : '';
          totalShown += shownInSection;
        });

        noRes.style.display = (totalShown === 0 && q) ? 'block' : 'none';
      }
      document.getElementById('group-search').addEventListener('input', groupSearch);

      // Join via code modal
      //
      // FIX: the old version fired a new debounced fetch on every
      // keystroke without cancelling the previous one. If you typed
      // fast, two lookups could be in flight at once, and whichever
      // one's response happened to arrive LAST won — even if it was for
      // an earlier, incomplete/wrong code. That's exactly what made
      // typing a code "not work" sometimes: the final render could be
      // the stale result of a half-typed code instead of the real one.
      // Fixed with a request token: every keystroke gets its own id, and
      // a response is only ever applied if it's still the latest one
      // asked for. The debounce is also shortened and an exact-length
      // code (8 chars) looks up immediately instead of waiting, so this
      // now feels realtime.
      var _codeTimer   = null;
      var _codeReqId   = 0;
      var _codeAbort   = null;
      var _codeValid = false;

      function closeCodeModal() {
        document.getElementById('join-code-modal').style.display = 'none';
        document.getElementById('code-input').value = '';
        document.getElementById('code-preview').style.display = 'none';
        document.getElementById('code-error').style.display = 'none';
        document.getElementById('code-loading').style.display = 'none';
        document.getElementById('code-join-form').style.display = 'none';
        document.getElementById('code-cancel-only').style.display = 'block';
        _codeValid = false;
        clearTimeout(_codeTimer);
        if (_codeAbort) { _codeAbort.abort(); _codeAbort = null; }
        _codeReqId++; // orphan any in-flight request
      }

      function previewGroup(code) {
        code = code.trim().toUpperCase();
        var preview  = document.getElementById('code-preview');
        var errEl    = document.getElementById('code-error');
        var loadEl   = document.getElementById('code-loading');
        var joinForm = document.getElementById('code-join-form');
        var cancelOnly = document.getElementById('code-cancel-only');

        // A fresh keystroke invalidates whatever was in flight before it —
        // cancel the old timer AND the old network request so a slow,
        // stale response can never land after a newer, correct one.
        clearTimeout(_codeTimer);
        if (_codeAbort) { _codeAbort.abort(); _codeAbort = null; }
        var myReqId = ++_codeReqId;

        preview.style.display = 'none';
        errEl.style.display   = 'none';
        joinForm.style.display = 'none';
        cancelOnly.style.display = 'block';

        if (code.length < 6) { loadEl.style.display = 'none'; return; }

        loadEl.style.display = 'block';

        var doLookup = function () {
          // Stale by the time the timer fires (shouldn't happen, but
          // guards against any edge case).
          if (myReqId !== _codeReqId) return;

          _codeAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;

          fetch('?view=groups&lookup_code=' + encodeURIComponent(code), {
            credentials: 'same-origin',
            signal: _codeAbort ? _codeAbort.signal : undefined,
          })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              // Ignore this response if a newer lookup has since started.
              if (myReqId !== _codeReqId) return;

              loadEl.style.display = 'none';
              if (d.error) {
                errEl.textContent = d.error;
                errEl.style.display = 'block';
                return;
              }
              var photoEl = document.getElementById('cprev-photo');
              if (d.photo) {
                photoEl.innerHTML = '<img src="' + d.photo + '" style="width:100%;height:100%;object-fit:cover;">';
              } else {
                photoEl.textContent = d.icon || '👥';
              }
              document.getElementById('cprev-name').textContent = d.name;
              document.getElementById('cprev-members').textContent = d.members + ' members';
              document.getElementById('cprev-desc').textContent = d.description || '';
              var statusEl = document.getElementById('cprev-status');
              if (d.already_member) {
                statusEl.textContent = 'Already joined';
                statusEl.className = 'pill ok';
              } else {
                statusEl.textContent = 'Not joined';
                statusEl.className = 'pill';
              }
              preview.style.display = 'block';

              if (!d.already_member) {
                document.getElementById('code-join-value').value = code;
                joinForm.style.display = 'block';
                cancelOnly.style.display = 'none';
              }
            })
            .catch(function (err) {
              if (err && err.name === 'AbortError') return; // superseded by a newer keystroke — not a real failure
              if (myReqId !== _codeReqId) return;
              loadEl.style.display = 'none';
              errEl.textContent = 'Could not look up that code. Please try again.';
              errEl.style.display = 'block';
            });
        };

        // A full-length code (8 chars, the format every generated code
        // uses) looks up right away instead of waiting out the debounce,
        // so pasting or finishing typing a code feels instant. Shorter,
        // still-being-typed lengths use a short debounce so we're not
        // hammering the server on every keystroke.
        if (code.length >= 8) {
          doLookup();
        } else {
          _codeTimer = setTimeout(doLookup, 250);
        }
      }

      document.getElementById('join-code-modal').addEventListener('click', function(e){
        if (e.target === this) closeCodeModal();
      });
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeCodeModal();
      });
      document.getElementById('code-input').addEventListener('focus', function(){
        this.style.borderColor = 'var(--brand)';
        this.style.boxShadow = '0 0 0 3px rgba(21,89,67,0.12)';
      });
      document.getElementById('code-input').addEventListener('blur', function(){
        this.style.borderColor = 'var(--line-strong)';
        this.style.boxShadow = 'none';
      });
      </script>

    <?php elseif ($view === 'create-group'): ?>
      <div class="row-head"><h2>Make a group</h2></div>
      <form method="post" class="form-card" enctype="multipart/form-data"><?= csrf_field() ?>
        <input type="hidden" name="action" value="create_group">
        <input type="hidden" name="icon" value="👥">
        <div class="field group-photo-picker">
          <label>Upload group photo <span class="field-optional">optional — falls back to a default icon</span></label>
          <div class="group-photo-preview" id="group-photo-preview" onclick="document.getElementById('group-photo-input').click()">
            <span>📷 Click to upload</span>
          </div>
          <input type="file" name="photo" id="group-photo-input" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="field"><label>Group name</label><input type="text" name="name" required placeholder="e.g. Antipolo Buy &amp; Sell"></div>
        <div class="field"><label>Description</label><textarea name="description" rows="3" placeholder="What's this group about?"></textarea></div>
        <button class="btn" style="margin-top:18px;" type="submit">Create group</button>
      </form>
      <script>
      (function () {
        var input = document.getElementById('group-photo-input');
        var preview = document.getElementById('group-photo-preview');
        if (!input || !preview) return;
        input.addEventListener('change', function () {
          if (!input.files || !input.files[0]) return;
          var img = document.createElement('img');
          img.src = URL.createObjectURL(input.files[0]);
          preview.innerHTML = '';
          preview.appendChild(img);
        });
      })();
      </script>

    <?php elseif ($view === 'group'):
      $g = $pageData['group'] ?? null; ?>
      <a class="crumb" href="?view=groups">← Browse groups</a>
      <?php if (!$g): ?><p>Group not found.</p><?php else: ?>
      <div class="detail-card">
        <?php if (!empty($g['photo_mime'])): ?>
          <div style="border-radius:var(--radius);overflow:hidden;max-width:420px;margin-bottom:6px;">
            <img src="image.php?type=group&id=<?= (int) $g['id'] ?>" alt="" style="width:100%;height:200px;object-fit:cover;display:block;">
          </div>
        <?php else: ?>
          <div class="detail-hero"><?= e($g['icon']) ?></div>
        <?php endif; ?>
        <h2><?= e($g['name']) ?></h2>
        <?php if (!empty($g['category'])): ?><div class="sub" style="color:var(--ink-soft);margin-top:6px;"><?= e($g['category']) ?></div><?php endif; ?>
        <p style="margin-top:14px;color:var(--ink-soft);line-height:1.6;"><?= nl2br(e($g['description'])) ?></p>

        <?php $m = $pageData['membership'] ?? null; ?>
        <?php if (!$m): ?>
          <button type="button" class="btn" style="margin-top:16px;"
            onclick="openJoinModal(<?= (int)$g['id'] ?>, '<?= e(addslashes($g['name'])) ?>')">
            Join Group
          </button>

          <!-- Hidden join form submitted by modal -->
          <form method="post" id="join-form-<?= (int)$g['id'] ?>" style="display:none;"><?= csrf_field() ?>
            <input type="hidden" name="action" value="join_group">
            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
          </form>

        <?php elseif ($m['status'] === 'approved'): ?>
          <div class="status-banner">✅ You're a member of this group.</div>

          <!-- Invite code — visible to all approved members.
               groups.join_code is a permanent, unique column on the
               group row itself: it's issued once, never changes on its
               own, and is only ever freed up when the group is deleted
               (below) or the owner deliberately generates a new one. -->
          <?php
          $groupCode = $g['join_code'] ?? null;

          // Safety net only: covers older group rows created before this
          // column existed in an install's data, never a schema problem.
          if (!$groupCode) {
              $attempts = 0;
              do {
                  $tryCode = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
                  $attempts++;
                  try {
                      $upd = $conn->prepare('UPDATE `groups` SET join_code=? WHERE id=? AND (join_code IS NULL OR join_code="")');
                      $upd->bind_param('si', $tryCode, (int)$g['id']);
                      $upd->execute();
                      $upd->close();
                      $chk = $conn->prepare('SELECT join_code FROM `groups` WHERE id=? LIMIT 1');
                      $chk->bind_param('i', (int)$g['id']);
                      $chk->execute();
                      $groupCode = $chk->get_result()->fetch_assoc()['join_code'] ?? null;
                      $chk->close();
                  } catch (Throwable $e) {
                      error_log('[SafeTrade] join_code backfill: ' . $e->getMessage());
                      break;
                  }
              } while (!$groupCode && $attempts < 5);
          }
          ?>
          <div style="margin-top:18px;background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:16px 18px;">
            <div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:10px;">🔑 Invite people to this group</div>
            <?php if ($groupCode): ?>
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <div id="gcode-display" style="
                  font-family:'IBM Plex Mono',monospace;font-size:22px;font-weight:700;
                  letter-spacing:.18em;color:var(--brand-dark);background:var(--brand-light);
                  padding:10px 18px;border-radius:10px;border:1.5px dashed var(--brand);
                  user-select:all;cursor:pointer;flex-shrink:0;
                " title="Click to copy the code" onclick="copyGroupCode('<?= e($groupCode) ?>')"><?= e($groupCode) ?></div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                  <button onclick="copyGroupCode('<?= e($groupCode) ?>')" class="btn sm ghost" type="button">📋 Copy code</button>
                  <form method="post" style="margin:0;"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="regenerate_code">
                    <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                    <button class="btn sm ghost" type="submit"
                      onclick="return confirm('Generate a new code? The old code will stop working.')"
                      style="width:100%;">🔄 New code</button>
                  </form>
                </div>
              </div>

              <div id="gcode-copied" style="display:none;font-size:12px;color:var(--brand);margin-top:8px;font-weight:600;">✓ Copied to clipboard!</div>
              <div style="font-size:12px;color:var(--ink-soft);margin-top:10px;line-height:1.5;">
                Share this code with anyone. They enter it via <strong>Browse Groups → Join via Code</strong> and join instantly — no approval needed.
              </div>
            <?php endif; ?>
          </div>
          <script>
          function copyGroupCode(code) {
            var done = function () {
              var el = document.getElementById('gcode-copied');
              if (el) { el.style.display = 'block'; setTimeout(function () { el.style.display = 'none'; }, 2500); }
            };
            if (navigator.clipboard) {
              navigator.clipboard.writeText(code).then(done);
            } else {
              var ta = document.createElement('textarea');
              ta.value = code; document.body.appendChild(ta); ta.select();
              document.execCommand('copy'); document.body.removeChild(ta);
              done();
            }
          }
          </script>

          <?php if (!empty($pageData['isOwner'])): ?>
          <!-- Delete group — creator only, permanent, frees the invite code -->
          <div style="margin-top:14px;">
            <button type="button" class="btn sm ghost" style="color:var(--danger);border-color:var(--clay);"
              onclick="document.getElementById('delete-group-modal-<?= (int)$g['id'] ?>').style.display='flex';">
              🗑 Delete this group
            </button>
          </div>
          <div id="delete-group-modal-<?= (int)$g['id'] ?>" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;">
            <div style="background:var(--cream);border-radius:22px;padding:34px 30px;max-width:420px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);">
              <div style="width:52px;height:52px;border-radius:50%;background:var(--danger-bg);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 16px;">🗑</div>
              <h3 style="font-family:'Fraunces',serif;font-size:19px;text-align:center;margin:0 0 8px;">Delete "<?= e($g['name']) ?>"?</h3>
              <p style="text-align:center;font-size:13.5px;color:var(--ink-soft);line-height:1.6;margin:0 0 22px;">
                This permanently deletes the group, its feed posts, comments, likes, and membership list. Any listings posted into it stay live, just no longer linked to a group. The invite code <strong><?= e((string)$groupCode) ?></strong> is freed up and could be reused by a future group. This can't be undone.
              </p>
              <div style="display:flex;gap:10px;">
                <button type="button" onclick="document.getElementById('delete-group-modal-<?= (int)$g['id'] ?>').style.display='none';" style="flex:1;padding:13px;border-radius:999px;font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);background:transparent;color:var(--ink-soft);cursor:pointer;">Cancel</button>
                <form method="post" style="flex:1;margin:0;"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_group_by_owner">
                  <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                  <button type="submit" style="width:100%;padding:13px;border-radius:999px;font-weight:700;font-size:14px;border:none;background:var(--danger);color:#fff;cursor:pointer;">Yes, delete it</button>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <?php if (empty($pageData['isOwner'])): ?>
          <!-- Leave group — any regular member. Their own posts in this
               group's feed are removed as part of leaving. -->
          <div style="margin-top:14px;">
            <button type="button" class="btn sm ghost" style="color:var(--danger);border-color:var(--clay);"
              onclick="document.getElementById('leave-group-modal-<?= (int)$g['id'] ?>').style.display='flex';">
              🚪 Leave this group
            </button>
          </div>
          <div id="leave-group-modal-<?= (int)$g['id'] ?>" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;">
            <div style="background:var(--cream);border-radius:22px;padding:34px 30px;max-width:420px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);">
              <div style="width:52px;height:52px;border-radius:50%;background:var(--danger-bg);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 16px;">🚪</div>
              <h3 style="font-family:'Fraunces',serif;font-size:19px;text-align:center;margin:0 0 8px;">Leave "<?= e($g['name']) ?>"?</h3>
              <p style="text-align:center;font-size:13.5px;color:var(--ink-soft);line-height:1.6;margin:0 0 22px;">
                Do you really want to leave this group? Any posts you shared in its feed will be removed along with you. You can always rejoin later with the group's code.
              </p>
              <div style="display:flex;gap:10px;">
                <button type="button" onclick="document.getElementById('leave-group-modal-<?= (int)$g['id'] ?>').style.display='none';" style="flex:1;padding:13px;border-radius:999px;font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);background:transparent;color:var(--ink-soft);cursor:pointer;">Cancel</button>
                <form method="post" style="flex:1;margin:0;"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="leave_group">
                  <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                  <button type="submit" style="width:100%;padding:13px;border-radius:999px;font-weight:700;font-size:14px;border:none;background:var(--danger);color:#fff;cursor:pointer;">Yes, leave</button>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
        <?php elseif ($m['status'] === 'rejected'): ?>
          <div class="status-banner" style="background:var(--danger-bg);color:var(--danger);">Your previous request was declined. You may request to join again.</div>
          <button type="button" class="btn" style="margin-top:12px;"
            onclick="openJoinModal(<?= (int)$g['id'] ?>, '<?= e(addslashes($g['name'])) ?>')">
            Join Group
          </button>
          <form method="post" id="join-form-<?= (int)$g['id'] ?>" style="display:none;"><?= csrf_field() ?>
            <input type="hidden" name="action" value="join_group">
            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
          </form>
        <?php endif; ?>

        <!-- Join confirmation modal -->
        <div id="join-modal" style="display:none;position:fixed;inset:0;z-index:9999;
          background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);
          align-items:center;justify-content:center;padding:20px;">
          <div style="background:var(--cream);border-radius:22px;padding:34px 30px;
            max-width:400px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);
            animation:joinModalIn .22s cubic-bezier(.22,.75,.28,1) both;">
            <div style="width:52px;height:52px;border-radius:50%;background:var(--brand-light);
              display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 16px;">👥</div>
            <h3 style="font-family:'Fraunces',serif;font-size:19px;text-align:center;margin:0 0 8px;">
              Join this group?
            </h3>
            <p id="join-modal-name" style="text-align:center;font-weight:700;font-size:15px;
              color:var(--brand);margin:0 0 16px;"></p>
            <p style="text-align:center;font-size:13.5px;color:var(--ink-soft);line-height:1.6;margin:0 0 24px;">
              You will immediately become a member and can post, sell items, and chat in this group.
            </p>
            <div style="display:flex;gap:10px;">
              <button onclick="closeJoinModal()" style="flex:1;padding:13px;border-radius:999px;
                font-weight:600;font-size:14px;border:1.5px solid var(--line-strong);
                background:transparent;color:var(--ink-soft);cursor:pointer;transition:all .15s ease;"
                onmouseover="this.style.background='rgba(22,31,22,0.06)'"
                onmouseout="this.style.background='transparent'">
                Cancel
              </button>
              <button id="join-confirm-btn" onclick="confirmJoin()" style="flex:1;padding:13px;
                border-radius:999px;font-weight:700;font-size:14px;border:none;
                background:var(--brand);color:var(--cream);cursor:pointer;
                transition:all .15s ease;box-shadow:0 2px 8px rgba(9,44,33,.25);"
                onmouseover="this.style.background='var(--brand-dark)'"
                onmouseout="this.style.background='var(--brand)'">
                Yes, join now
              </button>
            </div>
          </div>
        </div>
        <style>@keyframes joinModalIn{from{opacity:0;transform:scale(.94) translateY(8px);}to{opacity:1;transform:none;}}</style>
        <script>
        var _joinGroupId = null;
        function openJoinModal(groupId, name) {
          _joinGroupId = groupId;
          document.getElementById('join-modal-name').textContent = '"' + name + '"';
          var modal = document.getElementById('join-modal');
          modal.style.display = 'flex';
        }
        function closeJoinModal() {
          document.getElementById('join-modal').style.display = 'none';
          _joinGroupId = null;
        }
        function confirmJoin() {
          if (_joinGroupId) {
            document.getElementById('join-form-' + _joinGroupId).submit();
          }
        }
        document.getElementById('join-modal').addEventListener('click', function(e) {
          if (e.target === this) closeJoinModal();
        });
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape') closeJoinModal();
        });
        </script>

      </div>

      <?php $isMember = $m && $m['status'] === 'approved'; ?>

      <?php if ($isMember): ?>
        <div class="group-feed-composer">
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="create_group_post">
            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
            <textarea name="content" rows="2" placeholder="Share something with the group…" required></textarea>
            <div class="group-feed-actions">
              <button class="btn sm" type="submit">Post</button>
              <a class="btn sm ghost" href="?view=sell&type=item&group_id=<?= (int)$g['id'] ?>">📦 Sell an item here</a>
              <a class="btn sm ghost" href="?view=sell&type=vehicle&group_id=<?= (int)$g['id'] ?>">🚗 Sell a vehicle here</a>
            </div>
          </form>
        </div>

        <?php if (!$pageData['feedAvailable']): ?>
          <div class="alert error">The group feed isn't available yet — ask an admin to run <code>migration_add_group_feed.sql</code>.</div>
        <?php elseif (empty($pageData['posts'])): ?>
          <p style="color:var(--ink-soft);">No posts yet — be the first to share something with the group.</p>
        <?php else: ?>
          <div class="group-feed">
            <?php foreach ($pageData['posts'] as $post):
              $postThumbs = product_thumbs($post['images_concat'] ?? null); ?>
              <div class="group-post" id="post-<?= (int)$post['id'] ?>">
                <div class="group-post-header">
                  <span class="p-avatar hp-trigger" data-uid="<?= (int)$post['user_id'] ?>"><?= e(strtoupper(substr($post['first_name'], 0, 1) . substr($post['last_name'], 0, 1))) ?></span>
                  <div>
                    <span class="hp-trigger" data-uid="<?= (int)$post['user_id'] ?>" style="font-weight:700;"><?= e($post['first_name'] . ' ' . $post['last_name']) ?></span>
                    <div class="sub" style="font-size:11.5px;color:var(--ink-soft);"><?= e(time_ago($post['created_at'])) ?></div>
                  </div>
                </div>

                <?php if (!empty($post['content'])): ?>
                  <p class="group-post-content"><?= nl2br(e($post['content'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($post['product_id'])): ?>
                  <a class="group-post-product" href="?view=item&id=<?= (int)$post['product_id'] ?>">
                    <?php if ($postThumbs): ?>
                      <img src="<?= e($postThumbs[0]) ?>" alt="">
                    <?php else: ?>
                      <div class="group-post-product-fallback"><?= e($post['product_icon'] ?? '📦') ?></div>
                    <?php endif; ?>
                    <div>
                      <div class="price">₱<?= number_format((float)($post['product_price'] ?? 0), 2) ?></div>
                      <div style="font-size:13px;font-weight:600;"><?= e($post['product_title'] ?? '') ?></div>
                      <?php if (empty($post['product_active'])): ?><span class="pill" style="background:var(--danger-bg);color:var(--danger);">Sold / unavailable</span><?php endif; ?>
                    </div>
                  </a>
                  <?php if ((int)$post['user_id'] !== $userId && !empty($post['product_active'])): ?>
                    <form method="post" style="margin-bottom:10px;"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="express_interest">
                      <input type="hidden" name="product_id" value="<?= (int)$post['product_id'] ?>">
                      <button class="chat-pill" type="submit">💬 Chat with seller — Buying this</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>

                <div class="group-post-actions">
                  <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_group_post_like">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                    <button class="btn sm ghost" type="submit"><?= !empty($post['liked_by_me']) ? '❤️' : '🤍' ?> Like<?= (int)$post['like_count'] > 0 ? ' (' . (int)$post['like_count'] . ')' : '' ?></button>
                  </form>
                </div>

                <div class="group-post-comments">
                  <?php foreach ($post['comments'] as $c): ?>
                    <div class="group-post-comment">
                      <strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?>:</strong> <?= nl2br(e($c['content'])) ?>
                    </div>
                  <?php endforeach; ?>
                  <form method="post" class="group-post-comment-form"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="comment_group_post">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                    <input type="text" name="content" placeholder="Write a comment… (this messages the poster)" required>
                    <button class="btn sm" type="submit">Send</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>

    <?php elseif ($view === 'my-trades'): ?>
      <div class="row-head"><h2>My trades</h2></div>
      <?php if ($pageData['trades']->num_rows === 0): ?><p style="color:var(--ink-soft);">No active trades.</p><?php endif; ?>
      <?php while ($t = $pageData['trades']->fetch_assoc()):
        $role = (int)$t['buyer_id'] === $userId ? 'Buying' : 'Selling'; ?>
        <div class="buyer-row">
          <div class="info"><?= e($t['product_icon']) ?> <strong><?= e($t['product_title']) ?></strong> — <?= $role ?>
            <br><span class="sub"><?= e(ucwords(str_replace('_', ' ', $t['status']))) ?></span></div>
          <a class="btn sm" href="?view=chat&t=<?= (int)$t['id'] ?>">Open</a>
        </div>
      <?php endwhile; ?>

    <?php elseif ($view === 'profile'):
      $pu = $pageData['profileUser'] ?? null; ?>
      <?php if (!$pu): ?>
        <p>User not found.</p>
      <?php else:
        $puInitials = strtoupper(substr($pu['first_name'], 0, 1) . substr($pu['last_name'], 0, 1));
        $puName     = trim($pu['first_name'] . ' ' . $pu['last_name']);
        $puVerified = $pu['verification_status'] === 'approved';
        $puIsMe     = $profileId === $userId;
        $avg        = $pageData['profileRatingAvg'];
        $count      = $pageData['profileRatingCount'];
        $breakdown  = $pageData['profileBreakdown'];
        $an         = $pageData['profileAnalytics']; ?>

      <div class="profile-hero">
        <div class="profile-avatar"><?= e($puInitials) ?></div>
        <div class="profile-hero-info">
          <h2><?= e($puName) ?><?= $puIsMe ? ' <span style="font-size:14px;color:var(--ink-soft);font-weight:400;">(you)</span>' : '' ?></h2>
          <div class="profile-hero-meta">
            <?php if ($puVerified): ?><span class="pill ok">ID verified</span><?php else: ?><span class="pill">Not yet verified</span><?php endif; ?>
            <span>Member since <?= e(date('M Y', strtotime($pu['created_at']))) ?></span>
          </div>
          <?= star_rating_html($avg, $count) ?>
          <?php if ($count > 0): ?>
          <div class="rating-breakdown">
            <?php for ($s = 5; $s >= 1; $s--): $c = $breakdown[$s] ?? 0; $pct = $count > 0 ? round(($c / $count) * 100) : 0; ?>
              <div class="rating-breakdown-row">
                <span class="stars-label"><?= $s ?>★</span>
                <span class="rating-breakdown-bar"><span class="rating-breakdown-bar-fill" style="width:<?= $pct ?>%;"></span></span>
                <span class="count"><?= $c ?></span>
              </div>
            <?php endfor; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="row-head"><h2>Analytics</h2></div>
      <div class="analytics-grid">
        <div class="analytics-card"><div class="label">Total listings</div><div class="value"><?= (int) $an['total_listings'] ?></div></div>
        <div class="analytics-card"><div class="label">Currently selling</div><div class="value"><?= (int) $an['active_listings'] ?></div></div>
        <div class="analytics-card"><div class="label">Sold</div><div class="value"><?= (int) $an['sold_listings'] ?></div></div>
        <div class="analytics-card"><div class="label">Completed sales</div><div class="value"><?= (int) $an['sold_as_seller'] ?></div></div>
        <div class="analytics-card"><div class="label">Completed purchases</div><div class="value"><?= (int) $an['bought_as_buyer'] ?></div></div>
        <div class="analytics-card"><div class="label">Sell-through rate</div><div class="value"><?= (int) $an['sell_through_rate'] ?>%</div></div>
      </div>

      <div class="row-head">
        <h2>Listings</h2>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <label style="font-size:12px;font-weight:600;color:var(--ink-soft);">Date</label>
            <select onchange="if(this.value) window.location.href=this.value;" style="padding:8px 12px;border-radius:10px;border:1.5px solid var(--line-strong);background:var(--cream);font-family:inherit;font-size:12.5px;color:var(--ink);">
              <?php
              $profileFilterBase = '?view=profile' . (!$puIsMe ? '&id=' . $profileId : '');
              $profilePostedLabels = ['' => 'Any time', 'today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'year' => 'This year'];
              foreach ($profilePostedLabels as $val => $label): ?>
                <option value="<?= e($profileFilterBase . ($val !== '' ? '&posted=' . $val : '')) ?>" <?= $profilePostedFilter === $val ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <span style="font-size:10.5px;color:var(--ink-soft);">Applies to Feedback below too</span>
        </div>
      </div>
      <?php if ($pageData['profileListings']->num_rows === 0): ?>
        <p style="color:var(--ink-soft);"><?= $profilePostedFilter !== '' ? 'No listings posted ' . strtolower($profilePostedLabels[$profilePostedFilter]) . '.' : 'No listings yet.' ?></p>
      <?php else: ?>
      <div class="grid">
        <?php while ($p = $pageData['profileListings']->fetch_assoc()):
          $lThumbs   = product_thumbs($p['images_concat'] ?? null);
          $isRemoved = (int) $p['is_deleted'] === 1;
          $isSold    = !$isRemoved && (int) $p['is_active'] === 0; ?>
          <div class="card" style="<?= ($isRemoved || $isSold) ? 'opacity:.75;' : '' ?>">
            <div class="icon" style="<?= $lThumbs ? 'font-size:0;' : '' ?>">
              <?php if ($lThumbs): ?>
                <img src="<?= e($lThumbs[0]) ?>" alt="" style="width:100%;height:80px;object-fit:cover;border-radius:10px;">
              <?php else: ?>
                <?= e($p['icon']) ?>
              <?php endif; ?>
            </div>
            <h4><?php if (!$isRemoved): ?><a href="?view=item&id=<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a><?php else: ?><?= e($p['title']) ?><?php endif; ?></h4>
            <div class="price">₱<?= number_format((float) $p['price'], 2) ?></div>
            <?php if ($isRemoved): ?>
              <span class="pill" style="background:var(--danger-bg);color:var(--danger);">Removed</span>
            <?php elseif ($isSold): ?>
              <span class="pill ok">Sold<?= $p['sold_to'] ? ' to ' . e($p['sold_to']) : '' ?></span>
            <?php else: ?>
              <span class="pill" style="background:var(--gold-light);color:#7A5308;">Selling</span>
            <?php endif; ?>
          </div>
        <?php endwhile; ?>
      </div>
      <?php endif; ?>

      <div class="row-head">
        <h2>Feedback (<?= $profilePostedFilter !== '' ? (int) $pageData['profileFeedback']->num_rows : (int) $count ?>)</h2>
        <?php if ($profilePostedFilter !== ''): ?>
          <span style="font-size:11.5px;color:var(--ink-soft);">Filtered: <?= e($profilePostedLabels[$profilePostedFilter]) ?> · <?= (int) $count ?> total</span>
        <?php endif; ?>
      </div>
      <?php if ($pageData['profileFeedback']->num_rows === 0): ?>
        <p style="color:var(--ink-soft);"><?= $profilePostedFilter !== '' ? 'No feedback posted ' . strtolower($profilePostedLabels[$profilePostedFilter]) . '.' : 'No feedback yet.' ?></p>
      <?php else: ?>
        <?php while ($f = $pageData['profileFeedback']->fetch_assoc()): ?>
          <div class="feedback-card">
            <div class="fb-top">
              <span class="fb-stars"><?= str_repeat('★', (int) $f['rating']) . str_repeat('☆', 5 - (int) $f['rating']) ?></span>
              <span><?= e(time_ago($f['created_at'])) ?></span>
            </div>
            <div class="fb-review"><?= nl2br(e($f['review'])) ?></div>
            <div class="fb-item">From <span class="hp-trigger" data-uid="<?= (int) $f['reviewer_id'] ?>"><?= e($f['first_name'] . ' ' . $f['last_name']) ?></span> · <?= e($f['product_title']) ?></div>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <aside class="side-right">
    <div class="rcard" style="text-align:center;">
      <a href="?view=profile" style="text-decoration:none;color:inherit;display:block;">
        <div style="width:54px;height:54px;border-radius:50%;margin:0 auto 8px;background:var(--brand);color:var(--cream);display:flex;align-items:center;justify-content:center;font-family:'Fraunces',serif;font-weight:600;"><?= e($initials) ?></div>
        <h4><?= e($fullName) ?></h4>
      </a>
      <?php if ($verified): ?><span class="pill ok">ID verified</span><?php else: ?><span class="pill">Not yet verified</span><?php endif; ?>
      <?= star_rating_html($avgRating, $ratingCount) ?>
      <div style="margin-top:10px;"><a href="?view=profile" style="font-size:12px;font-weight:600;color:var(--brand);">View full profile →</a></div>
    </div>
  </aside>
</div>

<script>
// Wires up prev/next arrows for every horizontally-scrolling row on the
// page (.hscroll-wrap) — used by "Suggested groups" on Home and the
// "Created by me" / "Joined" / "Other Groups" rows on Browse Groups.
// Lives here (outside any single view's markup) so it runs no matter
// which view rendered the row.
(function () {
  document.querySelectorAll('.hscroll-wrap').forEach(function (wrap) {
    var row  = wrap.querySelector('.hscroll-row');
    var prev = wrap.querySelector('.hscroll-nav.prev');
    var next = wrap.querySelector('.hscroll-nav.next');
    if (!row || !prev || !next) return;

    function updateArrows() {
      var maxScroll = row.scrollWidth - row.clientWidth - 2;
      prev.classList.toggle('can-scroll', row.scrollLeft > 4);
      next.classList.toggle('can-scroll', row.scrollLeft < maxScroll);
    }

    prev.addEventListener('click', function () {
      row.scrollBy({ left: -(row.clientWidth * 0.85), behavior: 'smooth' });
    });
    next.addEventListener('click', function () {
      row.scrollBy({ left: row.clientWidth * 0.85, behavior: 'smooth' });
    });
    row.addEventListener('scroll', updateArrows, { passive: true });
    window.addEventListener('resize', updateArrows);
    updateArrows();
  });
})();
</script>

<script>
// Location strategy, closest to how Facebook does it:
//   1. Try precise browser GPS first (navigator.geolocation).
//   2. If that's denied, unavailable, or times out, fall back
//      automatically to an approximate IP-based location instead of
//      leaving distance sorting off entirely.
// Either result is saved server-side so listings can be sorted by
// distance; the banner above says plainly which kind you're seeing.
(function () {
  var csrfToken = '<?= csrf_token() ?>';

  function postJSON(url, params) {
    var body = new URLSearchParams(params);
    body.set('csrf_token', csrfToken);
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (res) { return res.json(); });
  }

  function useIpFallback() {
    postJSON('ip-location.php', {})
      .then(function (data) {
        sessionStorage.setItem('locSynced', '1');
        if (data && data.ok) location.reload();
      })
      .catch(function () { sessionStorage.setItem('locSynced', '1'); });
  }

  function tryGps(force) {
    if (!navigator.geolocation) {
      useIpFallback();
      return;
    }
    navigator.geolocation.getCurrentPosition(function (pos) {
      postJSON('update-location.php', {
        lat: pos.coords.latitude,
        lng: pos.coords.longitude,
      })
        .then(function (data) {
          sessionStorage.setItem('locSynced', '1');
          if (data && data.ok) location.reload();
        })
        .catch(function () { sessionStorage.setItem('locSynced', '1'); });
    }, function () {
      // Permission denied, position unavailable, or timed out — fall
      // back to the approximate IP-based location automatically.
      useIpFallback();
    }, { timeout: 8000 });
  }

  if (!sessionStorage.getItem('locSynced')) {
    tryGps(false);
  }

  var ipLink = document.getElementById('loc-use-ip');
  if (ipLink) {
    ipLink.addEventListener('click', function (e) {
      e.preventDefault();
      useIpFallback();
    });
  }

  var gpsRetryLink = document.getElementById('loc-retry-gps');
  if (gpsRetryLink) {
    gpsRetryLink.addEventListener('click', function (e) {
      e.preventDefault();
      tryGps(true); // deliberate user action — re-prompt even if already synced this session
    });
  }
})();

// Keep each product card's dot indicators in sync with its photo strip
// as the user swipes/scrolls through the images.
document.querySelectorAll('.photo-strip').forEach(function (strip) {
  var wrap = strip.closest('.p-media, .strip-hero');
  var dotsWrap = wrap ? wrap.querySelector('.dots') : null;
  if (!dotsWrap) return;
  var dots = dotsWrap.querySelectorAll('.dot');
  var ticking = false;
  strip.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(function () {
      var idx = Math.round(strip.scrollLeft / strip.clientWidth);
      dots.forEach(function (d, i) { d.classList.toggle('active', i === idx); });
      ticking = false;
    });
  }, { passive: true });
});

/* ---- Hover profile card: point at any seller/buyer name to preview
   their star rating, member-since date, and listing stats without
   leaving the page. ---- */
(function () {
  var card = null;
  var hideTimer = null;
  var showTimer = null;
  var cache = {};

  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s === null || s === undefined ? '' : String(s);
    return d.innerHTML;
  }

  function ensureCard() {
    if (card) return card;
    card = document.createElement('div');
    card.className = 'hp-card';
    document.body.appendChild(card);
    card.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
    card.addEventListener('mouseleave', scheduleHide);
    return card;
  }

  function scheduleHide() {
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      if (card) card.classList.remove('show');
    }, 200);
  }

  function renderCard(data) {
    var pct = data.rating_count > 0 ? Math.max(0, Math.min(100, (data.avg_rating / 5) * 100)) : 0;
    var verifiedBadge = data.verified
      ? '<span class="pill ok">ID verified</span>'
      : '<span class="pill">Not yet verified</span>';
    var ratingLabel = data.rating_count > 0
      ? data.avg_rating.toFixed(1) + ' (' + data.rating_count + ' review' + (data.rating_count === 1 ? '' : 's') + ')'
      : 'No reviews yet';

    card.innerHTML =
      '<div class="hp-top">' +
        '<div class="hp-avatar">' + escHtml(data.initials) + '</div>' +
        '<div>' +
          '<div class="hp-name">' + escHtml(data.name) + '</div>' +
          '<div class="hp-meta">Member since ' + escHtml(data.member_since) + '</div>' +
        '</div>' +
      '</div>' +
      '<div class="hp-badges">' + verifiedBadge + '</div>' +
      '<div class="hp-rating">' +
        '<div class="star-rating"><div class="star-rating-bg">★★★★★</div><div class="star-rating-fg" style="width:' + pct + '%;">★★★★★</div></div>' +
        '<span class="hp-rating-label">' + escHtml(ratingLabel) + '</span>' +
      '</div>' +
      '<div class="hp-stats">' +
        '<div><strong>' + data.total_listings + '</strong><span>Listings</span></div>' +
        '<div><strong>' + data.active_listings + '</strong><span>Selling</span></div>' +
        '<div><strong>' + data.sold_listings + '</strong><span>Sold</span></div>' +
      '</div>' +
      '<a class="hp-link" href="?view=profile&id=' + encodeURIComponent(data.id) + '">View full profile →</a>';
  }

  function positionNear(trigger) {
    var rect = trigger.getBoundingClientRect();
    var top = window.scrollY + rect.bottom + 8;
    var left = window.scrollX + rect.left;
    // Keep the card on-screen if the trigger is near the right edge.
    var maxLeft = window.scrollX + document.documentElement.clientWidth - 266;
    if (left > maxLeft) left = Math.max(window.scrollX + 8, maxLeft);
    card.style.top = top + 'px';
    card.style.left = left + 'px';
  }

  function showFor(trigger) {
    var uid = trigger.getAttribute('data-uid');
    if (!uid) return;
    ensureCard();
    positionNear(trigger);
    card.classList.add('show');
    clearTimeout(hideTimer);

    if (cache[uid]) {
      renderCard(cache[uid]);
      return;
    }
    card.innerHTML = '<div class="hp-loading">Loading…</div>';
    fetch('mini-profile-api.php?id=' + encodeURIComponent(uid), { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (!data || data.error) {
          card.innerHTML = '<div class="hp-loading">Profile unavailable</div>';
          return;
        }
        cache[uid] = data;
        renderCard(data);
      })
      .catch(function () {
        card.innerHTML = '<div class="hp-loading">Profile unavailable</div>';
      });
  }

  document.addEventListener('mouseover', function (e) {
    var trigger = e.target.closest ? e.target.closest('.hp-trigger') : null;
    if (!trigger) return;
    clearTimeout(showTimer);
    showTimer = setTimeout(function () { showFor(trigger); }, 350);
  });

  document.addEventListener('mouseout', function (e) {
    var trigger = e.target.closest ? e.target.closest('.hp-trigger') : null;
    if (!trigger) return;
    clearTimeout(showTimer);
    var related = e.relatedTarget;
    if (card && related && card.contains(related)) return;
    scheduleHide();
  });

  // Also support keyboard/touch users: tap/focus shows it, tap elsewhere hides it.
  document.addEventListener('focusin', function (e) {
    var trigger = e.target.closest ? e.target.closest('.hp-trigger') : null;
    if (trigger) showFor(trigger);
  });
})();
</script>
</body>
</html>
