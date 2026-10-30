<?php
/**
 * Renders the admin sidebar for all admin pages (users, verifications,
 * listings, reports, groups, audit-log, settings, user-detail).
 * Matches the user dashboard sidebar exactly: gradient background,
 * seal logo + burger toggle, icons + labels, gold active indicator.
 */
function render_admin_sidebar(string $activeNav, array $adminUser): void
{
    $links = [
        'inbox'          => ['Inbox',           'dashboard.php?tab=inbox', '<path d="M3 13l3-8h12l3 8"/><path d="M3 13v6a1 1 0 001 1h16a1 1 0 001-1v-6h-5l-1 2h-6l-1-2z"/>'],
        'announcements'  => ['Announcements',   'dashboard.php?tab=announcements', '<path d="M3 11v2a1 1 0 001 1h3l5 4V6L7 10H4a1 1 0 00-1 1z"/><path d="M16 8a5 5 0 010 8"/>'],
        'overview'       => ['Overview',       'dashboard.php',       '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>'],
        'users'          => ['Users',           'users.php',           '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6.3 6.5-6.3s6.5 2.7 6.5 6.3"/><path d="M16 4.3a3.2 3.2 0 010 6.3"/><path d="M21.5 20c0-2.9-1.9-5.2-4.5-6"/>'],
        'verifications'  => ['Verifications',   'verifications.php',   '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>'],
        'listings'       => ['Listings',        'listings.php',        '<path d="M7 4h10l3 5-8 11L2 9z"/><path d="M2 9h18M9.5 4L7 9l5 11 5-11-2.5-5"/>'],
        'groups'         => ['Groups',          'groups.php',          '<circle cx="9" cy="8" r="3"/><path d="M2.5 19c0-3.3 2.9-5.8 6.5-5.8s6.5 2.5 6.5 5.8"/><path d="M16.5 6.5a3 3 0 010 5.8"/>'],
        'reports'        => ['Reports',         'reports.php',         '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L2.7 17a2 2 0 001.7 3h15.2a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>'],
        'audit'          => ['Audit Log',       'audit-log.php',       '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>'],
        'settings'       => ['Settings',        'settings.php',        '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>'],
        'manage-admins'  => ['Manage Admins',   'manage-admins.php',   '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],
    ];

    $pendingVerifications = count_pending_verifications();
    $openReports          = count_open_reports();

    $initials = strtoupper(
        substr($adminUser['first_name'], 0, 1) .
        substr($adminUser['last_name'],  0, 1)
    );
    $fullName = e(trim($adminUser['first_name'] . ' ' . $adminUser['last_name']));

    echo '
<style>
/* ---- Admin sidebar — matches user dashboard exactly ---- */
.admin-app{display:grid;grid-template-columns:224px 1fr;min-height:100vh;}
.admin-side{
  background:linear-gradient(190deg,#155943 0%,#092C21 100%);
  color:#FBF7EF;padding:24px 14px;position:sticky;top:0;height:100vh;
  display:flex;flex-direction:column;box-shadow:2px 0 24px rgba(9,44,33,.14);z-index:5;
  overflow:hidden;
}
.ad-logo-row{display:flex;align-items:center;gap:11px;padding:8px 10px 24px;flex-shrink:0;}
.ad-burger{
  background:rgba(251,247,239,.10);border:none;color:#FBF7EF;
  width:32px;height:32px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;flex-shrink:0;padding:0;
  transition:background .15s ease;
}
.ad-burger:hover{background:rgba(251,247,239,.20);}
.ad-burger svg{width:17px;height:17px;}
.ad-logo-text{
  display:flex;align-items:center;gap:9px;
  font-family:\'Fraunces\',serif;font-weight:700;font-size:18.5px;
  text-decoration:none;color:#FBF7EF;white-space:nowrap;letter-spacing:-.01em;
}
.ad-logo-text .seal{
  width:30px;height:30px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(160deg,rgba(251,247,239,.25),rgba(251,247,239,.10));
  border:1.5px solid rgba(251,247,239,.30);
  display:flex;align-items:center;justify-content:center;font-size:13px;
}
.admin-nav{display:flex;flex-direction:column;gap:3px;flex:1;}
.admin-link{
  display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:13px;
  font-size:14px;font-weight:500;color:rgba(251,247,239,.70);
  text-decoration:none;position:relative;white-space:nowrap;
  transition:background .15s ease,color .15s ease,transform .15s ease;
}
.admin-link svg{width:18px;height:18px;flex-shrink:0;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
.admin-link:hover{background:rgba(251,247,239,.09);color:#FBF7EF;transform:translateX(1px);}
.admin-link.active{background:rgba(251,247,239,.16);color:#FBF7EF;font-weight:600;}
.admin-link.active::before{
  content:"";position:absolute;left:-14px;top:50%;transform:translateY(-50%);
  width:4px;height:20px;border-radius:0 4px 4px 0;
  background:#C6871F;box-shadow:0 0 10px rgba(198,135,31,.5);
}
.admin-link .count{
  margin-left:auto;background:#C6871F;color:#092C21;
  font-size:10.5px;font-weight:800;padding:2.5px 7.5px;border-radius:999px;flex-shrink:0;
}
.admin-divider{height:1px;background:rgba(251,247,239,.13);margin:14px 4px;}
.admin-foot{font-size:12px;color:rgba(251,247,239,.58);padding:8px;line-height:1.65;flex-shrink:0;}
.admin-foot a{color:#F5E6C2;font-weight:600;text-decoration:none;}
.admin-foot a:hover{color:#fff;}
.admin-main{padding:32px 36px 68px;overflow-y:auto;}

/* Collapsed */
.admin-app.sidebar-collapsed{grid-template-columns:72px 1fr;}
.admin-app.sidebar-collapsed .admin-side{padding:24px 8px;}
.admin-app.sidebar-collapsed .ad-logo-row{justify-content:center;padding-left:0;padding-right:0;}
.admin-app.sidebar-collapsed .ad-logo-text{display:none;}
.admin-app.sidebar-collapsed .admin-link{justify-content:center;padding:12px;}
.admin-app.sidebar-collapsed .admin-link span:not(.count){display:none;}
.admin-app.sidebar-collapsed .admin-link.active::before{display:none;}
.admin-app.sidebar-collapsed .admin-link .count{position:absolute;top:5px;right:5px;margin-left:0;padding:2px 5px;font-size:9px;}
.admin-app.sidebar-collapsed .admin-foot{display:none;}

@media(max-width:900px){
  .admin-app{grid-template-columns:72px 1fr;}
  .admin-side{padding:24px 8px;}
  .ad-logo-row{justify-content:center;padding-left:0;padding-right:0;}
  .ad-logo-text{display:none;}
  .admin-link{justify-content:center;padding:12px;}
  .admin-link span:not(.count){display:none;}
  .admin-link.active::before{display:none;}
  .admin-foot{display:none;}
}
</style>';

    echo '<aside class="admin-side">';
    echo '  <div class="ad-logo-row">';
    echo '    <button type="button" class="ad-burger" id="ad-burger-btn" aria-label="Toggle sidebar">';
    echo '      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>';
    echo '    </button>';
    echo '    <a href="dashboard.php" class="ad-logo-text"><span class="seal">✓</span> SafeTrade</a>';
    echo '  </div>';

    echo '  <nav class="admin-nav">';
    foreach ($links as $key => [$label, $href, $iconPath]) {
        // Manage Admins is only visible to the super-admin role
        if ($key === 'manage-admins' && $adminUser['role'] !== 'admin') {
            continue;
        }
        $active = $key === $activeNav ? ' active' : '';
        $badge  = '';
        if ($key === 'verifications' && $pendingVerifications > 0) {
            $badge = '<span class="count">' . $pendingVerifications . '</span>';
        }
        if ($key === 'reports' && $openReports > 0) {
            $badge = '<span class="count">' . $openReports . '</span>';
        }
        echo '<a href="' . e($href) . '" class="admin-link' . $active . '" title="' . e($label) . '">'
           . '<svg viewBox="0 0 24 24">' . $iconPath . '</svg>'
           . '<span>' . e($label) . '</span>'
           . $badge
           . '</a>';
    }
    echo '  </nav>';

    echo '  <div class="admin-divider"></div>';
    echo '  <div class="admin-foot">Signed in as<br><strong style="color:#FBF7EF;">' . $fullName . '</strong><br><a href="../auth/logout.php">Log out</a></div>';
    echo '</aside>';

    echo '<script>
(function(){
  var STORAGE_KEY = "safetrade_admin_sidebar_collapsed";
  var app = document.querySelector(".admin-app");
  var btn = document.getElementById("ad-burger-btn");
  function apply(c){ if(app) app.classList.toggle("sidebar-collapsed", c); }
  try{ apply(localStorage.getItem(STORAGE_KEY)==="1"); }catch(e){}
  if(btn) btn.addEventListener("click", function(){
    var c = !app.classList.contains("sidebar-collapsed");
    apply(c);
    try{ localStorage.setItem(STORAGE_KEY, c?"1":"0"); }catch(e){}
  });
})();
</script>';
}

function count_pending_verifications(): int
{
    // FIX: this used to query a table called `identity_verifications`,
    // which doesn't exist anywhere in this installation's schema (see
    // the same fix already applied in dashboard.php's tab data). The
    // query threw an uncaught mysqli_sql_exception, which killed every
    // page that renders the sidebar via render_admin_sidebar() —
    // output stopped right after <head>, leaving a blank body. The
    // account's own users.verification_status IS the verification
    // record, so read from there instead, same as dashboard.php does.
    $res = db()->query("SELECT COUNT(*) c FROM users WHERE verification_status = 'pending'");
    return (int) ($res->fetch_assoc()['c'] ?? 0);
}

function count_open_reports(): int
{
    $res = db()->query("SELECT COUNT(*) c FROM reports WHERE status = 'open'");
    return (int) ($res->fetch_assoc()['c'] ?? 0);
}
