<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';

if (is_logged_in()) {
    $user = current_user();
    redirect(in_array($user['role'], ['admin', 'manager'], true) ? '/admin/index.php' : '/dashboard.php');
}

$error = null;
$lockoutSeconds = null;
$oldEmail = '';

// If a guest arrived here from browse.php after clicking "Message
// seller" / "Chat" on a listing, this carries that product id through
// the login form so it survives the POST. It never bypasses login or
// touches the database itself — it only decides where to send the
// person once attempt_login() actually succeeds (see the redirect
// below and the matching handler in dashboard.php).
$redirectProduct = (int) ($_GET['redirect_product'] ?? ($_POST['redirect_product'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $oldEmail = clean_input($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    $result = attempt_login($oldEmail, $password, $remember);

    if ($result['success']) {
        $user = current_user();
        if (in_array($user['role'], ['admin', 'manager'], true)) {
            redirect('/admin/index.php');
        }
        redirect($redirectProduct > 0
            ? '/dashboard.php?want_chat=1&id=' . $redirectProduct
            : '/dashboard.php');
    }
    $error = $result['error'];
    $lockoutSeconds = $result['lockout_seconds'] ?? null;
}

$pageTitle = 'Log in · SafeTrade';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="auth-shell">
  <div class="auth-card">
    <a href="../Index.html" class="auth-logo"><?= seal_svg() ?> SafeTrade</a>
    <h1>Welcome back</h1>
    <p class="auth-sub">Log in to browse, message, and manage your listings.</p>

    <?php if ($redirectProduct > 0): ?>
      <div class="alert success">Log in to message this seller — you'll be taken right back to the conversation.</div>
    <?php endif; ?>

    <!--
      FIX: this alert used to only exist in the markup when $lockoutSeconds
      was set by the PHP that just ran (i.e. only right after a blocked
      POST). On a plain page load / refresh, $lockoutSeconds is always
      null again, so the box — and the <script> that used to live inside
      its `if` block — never rendered at all. That's why the countdown
      never appeared and never reached 0: there was nothing left alive on
      the page to count it down or to re-check the server.

      Now the container always exists. Its initial visibility/text still
      comes from PHP (so a freshly-blocked POST shows the right message
      immediately, no flash of "unlocked"), but from here on JS below
      polls lockout-status.php (a live GET, no page reload) and is the
      single source of truth for whether it's shown, what it says, and
      whether the Log in button is enabled.
    -->
    <div class="alert error" id="lockout-alert"
         style="display:<?= ($error && $lockoutSeconds !== null && $lockoutSeconds > 0) ? 'flex' : 'none' ?>;">
      <span id="lockout-text"><?= e($error && $lockoutSeconds !== null && $lockoutSeconds > 0 ? $error : '') ?></span>
    </div>
    <?php if ($error && ($lockoutSeconds === null || $lockoutSeconds <= 0)): ?>
      <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php render_flashes(); ?>

    <button type="button" class="face-trigger-btn" id="face-login-btn" style="margin-top:22px;">
      📷 Log in with Face
    </button>
    <div class="auth-divider">or use email + password</div>

    <form method="post" novalidate id="login-form">
      <?= csrf_field() ?>
      <?php if ($redirectProduct > 0): ?><input type="hidden" name="redirect_product" value="<?= $redirectProduct ?>"><?php endif; ?>
      <div class="field"><label>Email</label><input type="email" name="email" id="login-email-field" value="<?= e($oldEmail) ?>" required autofocus></div>
      <div class="field">
        <label>Password</label>
        <div class="pw-field-wrap">
          <input type="password" name="password" id="pw-login" required>
          <button type="button" class="pw-toggle" data-target="pw-login" aria-label="Show password">👁</button>
        </div>
      </div>
      <div class="field" style="display:flex;align-items:center;gap:8px;margin-top:14px;">
        <input type="checkbox" name="remember" id="remember" style="width:auto;margin:0;">
        <label for="remember" style="font-weight:500;color:var(--ink);">Keep me signed in</label>
      </div>
      <button type="submit" class="btn-primary" id="login-submit-btn"<?= ($lockoutSeconds !== null && $lockoutSeconds > 0) ? ' disabled' : '' ?>>Log in</button>
    </form>
    <div class="auth-foot"><a href="forgot-password.php">Forgot your password?</a></div>
    <div class="auth-foot">New to SafeTrade? <a href="register.php">Create an account</a></div>
  </div>
</div>

<style>
  .pw-field-wrap{position:relative;}
  .pw-field-wrap input{padding-right:44px;}
  .pw-toggle{
    position:absolute;top:50%;right:6px;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;font-size:16px;line-height:1;
    padding:8px;border-radius:8px;opacity:.6;transition:opacity .15s ease,background .15s ease;
  }
  .pw-toggle:hover{opacity:1;background:rgba(21,89,67,.08);}
</style>
<script>
document.querySelectorAll('.pw-toggle').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.getAttribute('data-target'));
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.textContent = showing ? '👁' : '🙈';
    btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
  });
});
</script>

<script>
/*
 * FIX (root cause of the "stuck at the static message until you
 * navigate back and forth" bug): window.SAFETRADE_BASE used to only
 * get defined much further down the page, inside face-modal.php's own
 * <script> block. But the countdown script right below THIS one calls
 * check() synchronously the instant it runs — which is BEFORE that
 * later script has executed. At that moment window.SAFETRADE_BASE was
 * still undefined, so check() built the fetch URL as plain
 * '/lockout-status.php' instead of '<BASE_URL>/lockout-status.php'.
 * On an install where the app lives in a subfolder (e.g. /safetrade),
 * that hit the wrong path, 404'd, and failed silently — and because
 * the polling interval is only ever started from inside a SUCCESSFUL
 * response (renderLocked()), that one failed first call meant no
 * interval ever got created. The alert was then stuck showing
 * whatever static text PHP rendered, forever — until a full
 * navigation happened to re-run everything in a lucky order (e.g.
 * via back/forward cache), which is exactly what you were seeing.
 *
 * Defining it here, before the countdown script runs, means the very
 * first check() call already has the right base path — no more
 * needing to go back and forth for it to "catch up".
 */
window.SAFETRADE_BASE = '<?= e(BASE_URL) ?>';
window.SAFETRADE_CSRF = '<?= csrf_token() ?>';
window.SAFETRADE_REDIRECT_PRODUCT = <?= json_encode($redirectProduct) ?>;
</script>

<script>
// ---- Real, server-truth countdown, driven entirely by lockout-status.php ----
//
// Runs on EVERY page load (GET or POST, fresh visit or refresh) instead
// of only right after a blocked POST. It polls the API every second and
// renders exactly what the server currently thinks the remaining lockout
// time is for whatever email is in the box right now:
//   - locked with time left  -> shows the countdown, disables the button
//   - not locked / hit 0     -> hides the alert, re-enables the button
// This also re-checks whenever the email field changes, so switching to
// a different (non-locked) email clears the warning without a reload.
(function () {
  var alertEl   = document.getElementById('lockout-alert');
  var textEl    = document.getElementById('lockout-text');
  var submitBtn = document.getElementById('login-submit-btn');
  var emailFld  = document.getElementById('login-email-field');
  if (!alertEl || !textEl || !submitBtn || !emailFld) return;

  var pollTimer = null;
  var emailDebounce = null;

  function renderLocked(secondsLeft) {
    alertEl.style.display = 'flex';
    alertEl.classList.remove('success');
    alertEl.classList.add('error');
    var m = Math.floor(secondsLeft / 60);
    var s = secondsLeft % 60;
    textEl.textContent = 'Too many failed attempts. Please try again in '
      + m + ':' + (s < 10 ? '0' : '') + s + '.';
    submitBtn.disabled = true;
    if (!pollTimer) pollTimer = setInterval(check, 1000);
  }

  function renderCleared() {
    alertEl.style.display = 'none';
    submitBtn.disabled = false;
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function check() {
    var email = (emailFld.value || '').trim();
    if (!email) { renderCleared(); return; }

    fetch((window.SAFETRADE_BASE || '') + '/lockout-status.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (!data) return; // network hiccup — keep showing the last known value
        if (data.locked && data.seconds_left > 0) {
          renderLocked(data.seconds_left);
        } else {
          renderCleared();
        }
      })
      .catch(function () { /* silent — retry on next tick */ });
  }

  emailFld.addEventListener('input', function () {
    clearTimeout(emailDebounce);
    emailDebounce = setTimeout(check, 400);
  });

  check(); // ground truth immediately, on every load — no more stuck static message
})();
</script>
<?php require __DIR__ . '/../includes/partials/face-modal.php'; ?>
<script>
document.getElementById('face-login-btn').addEventListener('click', function () {
  openFaceModal('login', {
    onSuccess: function (data) {
      // Same guest -> logged-in hand-off as the password form: if this
      // login started from browse.php's "Message seller", go straight
      // to the want_chat step instead of the normal dashboard redirect.
      // Only ever applies to a regular user redirect, never the admin
      // panel path (data.redirect already reflects role from the server).
      var isAdminRedirect = (data.redirect || '').indexOf('/admin/') !== -1;
      if (!isAdminRedirect && window.SAFETRADE_REDIRECT_PRODUCT > 0) {
        window.location.href = (window.SAFETRADE_BASE || '') + '/dashboard.php?want_chat=1&id=' + window.SAFETRADE_REDIRECT_PRODUCT;
        return;
      }
      window.location.href = (window.SAFETRADE_BASE || '') + (data.redirect || '/dashboard.php');
    },
  });
});
</script>
</body>
</html>
