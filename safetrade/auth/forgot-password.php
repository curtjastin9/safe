<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';

/**
 * ---------------------------------------------------------------------
 * Forgot password — OTP code flow.
 *
 *   Step 1 "email"  -> person enters their email. If it matches an
 *                      account, generate a 6-digit code, store its hash
 *                      in password_reset_otps (3-minute expiry), email
 *                      it, and move to step 2. Behaves identically
 *                      whether or not the email exists, so this can't
 *                      be used to check which emails have accounts.
 *
 *   Step 2 "verify" -> person enters the 6-digit code. They get up to
 *                      5 wrong guesses inside the 3-minute window —
 *                      hitting either limit sends them back to step 1
 *                      to request a brand new code (per spec: "if the
 *                      chance is used the user needs to do a forgot
 *                      password again"). A correct code creates a
 *                      normal password_resets token — the SAME
 *                      mechanism reset-password.php already validates —
 *                      so that page needed no changes to finish the
 *                      job of actually setting a new password.
 *
 * Session keys used (all cleared once the flow finishes or restarts):
 *   pwreset_user_id, pwreset_email, pwreset_expires_at, pwreset_attempts
 * ---------------------------------------------------------------------
 */

const OTP_LENGTH       = 6;
const OTP_TTL_SECONDS  = 180; // 3 minutes, per spec
const OTP_MAX_ATTEMPTS = 5;   // per spec

function pwreset_clear_session(): void
{
    unset(
        $_SESSION['pwreset_user_id'],
        $_SESSION['pwreset_email'],
        $_SESSION['pwreset_expires_at'],
        $_SESSION['pwreset_attempts'],
        $_SESSION['pwreset_dev_code'],
        $_SESSION['pwreset_dev_error']
    );
}

$step = (($_GET['step'] ?? '') === 'verify' && !empty($_SESSION['pwreset_user_id'])) ? 'verify' : 'email';

// Landing on the verify page after the 3-minute window has already
// passed (they wandered off and came back) sends them to start over
// instead of showing a countdown stuck at zero.
if ($step === 'verify' && time() >= (int) ($_SESSION['pwreset_expires_at'] ?? 0)) {
    pwreset_clear_session();
    flash_set('error', 'That code expired. Please request a new one.');
    redirect('/auth/forgot-password.php');
}

$errors = [];
$emailForDisplay = $_SESSION['pwreset_email'] ?? '';

/* ===================== Step 1: request a code ===================== */
if ($step === 'email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = strtolower(clean_input($_POST['email'] ?? ''));

    if (!is_valid_email($email)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $conn = db();
        $stmt = $conn->prepare('SELECT id, first_name FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $code    = str_pad((string) random_int(0, (10 ** OTP_LENGTH) - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
            $otpHash = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);

            // FIX: expires_at used to be computed in PHP (date('Y-m-d
            // H:i:s', time() + OTP_TTL_SECONDS)) and then compared
            // against MySQL's own NOW() later when verifying. If the
            // web server's clock/timezone and the DB server's
            // clock/timezone don't exactly agree (extremely common on
            // XAMPP, where PHP defaults to UTC but Windows/MySQL are on
            // local time), that PHP-computed timestamp can land before
            // MySQL's NOW() the instant it's inserted — so the code
            // looks "already expired" even seconds after being created,
            // and every attempt (even the correct code) fails. Letting
            // MySQL compute expires_at itself, entirely on its own
            // clock, makes the later "expires_at > NOW()" check
            // internally consistent no matter what PHP's clock says.
            $stmt = $conn->prepare('INSERT INTO password_reset_otps (user_id, otp_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
            $ttlSeconds = OTP_TTL_SECONDS; // bind_param() requires a by-reference variable — a constant can't be passed directly
            $stmt->bind_param('isi', $user['id'], $otpHash, $ttlSeconds);
            $stmt->execute();
            $stmt->close();

            audit_log((int) $user['id'], 'user.password_reset_otp_requested', 'user', (int) $user['id']);

            $emailed = send_branded_email(
                $email,
                $user['first_name'],
                'Your SafeTrade password reset code',
                'Your reset code',
                'Hi ' . e($user['first_name']) . ',<br><br>'
                    . 'Use this code to reset your password. It expires in <strong>3 minutes</strong> and can be '
                    . 'entered up to <strong>' . OTP_MAX_ATTEMPTS . ' times</strong>:<br><br>'
                    . '<div style="font-family:monospace;font-size:30px;font-weight:700;letter-spacing:.3em;color:#155943;margin:14px 0;">'
                    . e($code) . '</div>'
                    . 'If you didn\'t request this, you can safely ignore this email.'
            );

            $_SESSION['pwreset_user_id']    = (int) $user['id'];
            $_SESSION['pwreset_email']      = $email;
            $_SESSION['pwreset_expires_at'] = time() + OTP_TTL_SECONDS;
            $_SESSION['pwreset_attempts']   = 0;

            // Dev/diagnostic only: if the email didn't actually go out
            // (send_branded_email() returned false), show the code AND
            // the specific reason on-screen, so setting up SMTP doesn't
            // require digging through the server's error_log. Once
            // email is actually working, neither branch ever fires.
            if (!$emailed) {
                $_SESSION['pwreset_dev_code']  = $code;
                $_SESSION['pwreset_dev_error'] = smtp_last_error();
            }
        }
        redirect('/auth/forgot-password.php?step=verify');
    }
}

/* ===================== Step 2: enter the code ===================== */
if ($step === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $submitted = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
    $userId    = (int) $_SESSION['pwreset_user_id'];

    $conn = db();
    $stmt = $conn->prepare(
        'SELECT id, otp_hash FROM password_reset_otps
         WHERE user_id = ? AND used = 0 AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $otpRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $ok = $otpRow && strlen($submitted) === OTP_LENGTH && password_verify($submitted, $otpRow['otp_hash']);
    $serverError = false;

    if ($ok) {
        try {
            $stmt = $conn->prepare('UPDATE password_reset_otps SET used = 1 WHERE id = ?');
            $stmt->bind_param('i', $otpRow['id']);
            $stmt->execute();
            $stmt->close();

            // Hand off to the existing token-based reset-password.php —
            // it already knows how to validate a token and let someone
            // set a new password, so there's no reason to duplicate
            // that here.
            //
            // FIX: this expiry used to be computed in PHP (date(...,
            // time() + 600)) and then compared against MySQL's own
            // NOW() a moment later in reset-password.php. Same
            // PHP/MySQL clock-skew problem as the OTP code itself —
            // if the two servers' clocks don't exactly agree, the
            // token can look "already expired" within a second of
            // being created, which is exactly the "This reset session
            // is invalid or has expired" seen immediately after a
            // correct code. Letting MySQL compute expires_at itself
            // makes the later comparison internally consistent no
            // matter what PHP's clock says.
            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $tokenTtlSeconds = 600; // 10 minutes to actually pick a new password
            $stmt = $conn->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
            $stmt->bind_param('isi', $userId, $tokenHash, $tokenTtlSeconds);
            $stmt->execute();
            $stmt->close();

            audit_log($userId, 'user.password_reset_otp_verified', 'user', $userId);
            pwreset_clear_session();
            redirect('/auth/reset-password.php?token=' . $token . '&uid=' . $userId);
        } catch (Throwable $e) {
            // FIX: a database problem here (most likely: the
            // password_resets table doesn't exist yet, or its columns
            // don't match what this query expects) used to crash the
            // WHOLE PAGE with a raw 500 the instant a correct code was
            // entered — this app runs mysqli in MYSQLI_REPORT_STRICT
            // mode, so a bad query throws an uncaught exception instead
            // of just failing quietly. Now it's caught, logged with the
            // real reason, and shown on-screen instead of a blank 500 —
            // and since this is OUR problem, not the person having
            // mistyped something, it does NOT burn one of their 5
            // attempts.
            error_log('[SafeTrade] OTP verified correctly but the reset-token step failed: ' . $e->getMessage());
            $errors[] = "Your code was correct, but something went wrong on our end finishing this step. Please try again in a moment.";
            $serverError = true;
        }
    }

    if ($serverError) {
        // Don't touch the attempts counter or clear the session — the
        // code they just typed was right, so let them simply retry.
    } else {
        // Wrong code — either they're out of guesses, or the code
        // itself is no longer valid (expired/already used at the DB
        // level, even if the session clock hadn't caught up yet) —
        // both send them back to request a fresh one rather than let
        // them keep guessing pointlessly.
        $_SESSION['pwreset_attempts'] = (int) ($_SESSION['pwreset_attempts'] ?? 0) + 1;

        if (!$otpRow || $_SESSION['pwreset_attempts'] >= OTP_MAX_ATTEMPTS) {
            pwreset_clear_session();
            flash_set('error', 'Too many incorrect attempts. Please request a new code.');
            redirect('/auth/forgot-password.php');
        }

        $errors[] = 'That code is incorrect. ' . (OTP_MAX_ATTEMPTS - $_SESSION['pwreset_attempts']) . ' attempt(s) left.';
    }
}

$attemptsLeft = OTP_MAX_ATTEMPTS - (int) ($_SESSION['pwreset_attempts'] ?? 0);
$secondsLeft  = max(0, (int) ($_SESSION['pwreset_expires_at'] ?? 0) - time());

$pageTitle = 'Forgot password · SafeTrade';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="auth-shell">
  <div class="auth-card">
    <a href="../Index.html" class="auth-logo"><?= seal_svg() ?> SafeTrade</a>

    <?php if ($step === 'email'): ?>

      <h1>Reset your password</h1>
      <p class="auth-sub">Enter your account email and we'll send you a 6-digit code.</p>

      <?php if ($errors): ?>
        <div class="alert error">
          <ul class="err-list"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <?php render_flashes(); ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="field"><label>Email</label><input type="email" name="email" required autofocus placeholder="you@gmail.com" autocomplete="email"></div>
        <button type="submit" class="btn-primary">Send code</button>
      </form>

    <?php else: /* step === 'verify' */ ?>

      <h1>Enter your code</h1>
      <p class="auth-sub">We sent a <?= OTP_LENGTH ?>-digit code to <strong><?= e($emailForDisplay) ?></strong>.</p>

      <?php if ($errors): ?>
        <div class="alert error">
          <ul class="err-list"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['pwreset_dev_code'])): ?>
        <div class="alert success">Dev fallback — no email actually went out, so your code is shown here: <strong style="letter-spacing:.15em;"><?= e($_SESSION['pwreset_dev_code']) ?></strong></div>
        <?php if (!empty($_SESSION['pwreset_dev_error'])): ?>
          <div class="alert error">SMTP error: <?= e($_SESSION['pwreset_dev_error']) ?></div>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post" novalidate id="otp-form">
        <?= csrf_field() ?>
        <input type="hidden" name="code" id="otp-hidden-code">

        <div class="otp-inputs" id="otp-inputs">
          <?php for ($i = 0; $i < OTP_LENGTH; $i++): ?>
            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-box" autocomplete="one-time-code" aria-label="Digit <?= $i + 1 ?>">
          <?php endfor; ?>
        </div>

        <div class="otp-meta">
          <span id="otp-attempts-left"><?= (int) $attemptsLeft ?> attempt<?= $attemptsLeft === 1 ? '' : 's' ?> left</span>
        </div>

        <button type="submit" class="btn-primary" id="otp-confirm-btn" data-seconds-left="<?= (int) $secondsLeft ?>" data-label="Confirm">Confirm</button>
      </form>

      <div class="auth-foot" id="otp-expired-note" style="display:none;">
        That code has expired. <a href="forgot-password.php">Request a new one</a>
      </div>

      <style>
        .otp-inputs{display:flex;gap:10px;justify-content:center;margin-top:24px;}
        .otp-box{
          width:44px;height:54px;text-align:center;font-size:22px;font-weight:700;
          border-radius:12px;border:1.5px solid var(--line-strong);background:var(--cream);
          font-family:'IBM Plex Mono',monospace;color:var(--ink);outline:none;
          transition:border-color .15s ease,box-shadow .15s ease;
        }
        .otp-box:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(21,89,67,.15);}
        .otp-meta{display:flex;justify-content:center;margin-top:12px;font-size:12.5px;color:var(--ink-soft);font-weight:600;}
        #otp-confirm-btn:disabled{opacity:.55;cursor:not-allowed;}
      </style>

      <script>
      (function () {
        var boxes  = Array.prototype.slice.call(document.querySelectorAll('.otp-box'));
        var hidden = document.getElementById('otp-hidden-code');
        var form   = document.getElementById('otp-form');

        function syncHidden() {
          hidden.value = boxes.map(function (b) { return b.value; }).join('');
        }

        boxes.forEach(function (box, idx) {
          box.addEventListener('input', function () {
            var digits = box.value.replace(/\D/g, '');

            if (digits.length > 1) {
              // FIX: some browsers/keyboards autofill the ENTIRE code
              // into whichever box is focused, as a single 'input'
              // event — not a 'paste' event, so the old handler here
              // never caught it, and its old behavior (keep only the
              // first character) silently dropped the other 5 digits.
              // That meant a correctly-autofilled code could still get
              // submitted wrong without the person noticing. Spread the
              // extra digits across this box and the ones after it,
              // same as the explicit paste handler below already does.
              var chars = digits.split('');
              for (var j = idx; j < boxes.length && chars.length; j++) {
                boxes[j].value = chars.shift();
              }
              syncHidden();
              var lastFilled = Math.min(idx + digits.length, boxes.length) - 1;
              boxes[Math.max(0, lastFilled)].focus();
              return;
            }

            box.value = digits.slice(0, 1);
            if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
            syncHidden();
          });
          box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !box.value && idx > 0) {
              boxes[idx - 1].focus();
            }
          });
          box.addEventListener('paste', function (e) {
            e.preventDefault();
            var digits = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '').split('');
            boxes.forEach(function (b, i) { b.value = digits[i] || ''; });
            syncHidden();
            var nextEmpty = boxes.findIndex(function (b) { return !b.value; });
            (nextEmpty === -1 ? boxes[boxes.length - 1] : boxes[nextEmpty]).focus();
          });
        });
        if (boxes[0]) boxes[0].focus();
        form.addEventListener('submit', syncHidden);

        // ---- Live countdown, shown right on the Confirm button itself,
        // counting down the 3-minute window this code is valid for. ----
        var btn          = document.getElementById('otp-confirm-btn');
        var expiredNote  = document.getElementById('otp-expired-note');
        var defaultLabel = btn.getAttribute('data-label') || 'Confirm';
        var secondsLeft  = parseInt(btn.getAttribute('data-seconds-left'), 10) || 0;

        function render() {
          if (secondsLeft <= 0) {
            btn.disabled = true;
            btn.textContent = 'Code expired';
            form.style.display = 'none';
            expiredNote.style.display = 'block';
            return;
          }
          var m = Math.floor(secondsLeft / 60);
          var s = secondsLeft % 60;
          btn.textContent = defaultLabel + ' (' + m + ':' + (s < 10 ? '0' : '') + s + ')';
        }
        render();
        var timer = setInterval(function () {
          secondsLeft--;
          render();
          if (secondsLeft <= 0) clearInterval(timer);
        }, 1000);
      })();
      </script>

    <?php endif; ?>

    <div class="auth-foot"><a href="login.php">Back to log in</a></div>
  </div>
</div>
</body>
</html>
