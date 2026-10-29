<?php
/**
 * Improved "face verification" camera modal. Included by auth/login.php
 * and auth/register.php. Both pages load face-api.js + face-auth.js
 * and call window.openFaceModal(mode, callbacks) — mode is 'login' or
 * 'register', matching the two face-verify-api.php actions.
 * 
 * IMPROVEMENTS:
 * - Better error messages
 * - Retry logic built-in
 * - Better visual feedback
 * - Fallback password login option
 */
?>
<style>
.face-trigger-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:13px 22px;border-radius:999px;font-weight:600;font-size:14.5px;background:var(--cream);color:var(--brand);border:1.5px solid var(--brand);cursor:pointer;transition:all 0.2s ease;}
.face-trigger-btn:hover{background:var(--brand-light);transform:translateY(-1px);}
.face-trigger-btn:active{transform:translateY(0);}
.auth-divider{display:flex;align-items:center;gap:12px;margin:18px 0;font-size:11.5px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.06em;}
.auth-divider::before,.auth-divider::after{content:"";flex:1;height:1px;background:var(--line);}

.face-modal-backdrop{position:fixed;inset:0;background:rgba(13,58,44,0.55);display:none;align-items:center;justify-content:center;z-index:500;padding:20px;}
.face-modal-backdrop.show{display:flex;}
.face-modal{background:var(--cream);border-radius:22px;padding:26px;width:100%;max-width:420px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,0.1);}
.face-modal h3{font-family:'Fraunces',serif;font-size:18px;color:var(--ink);margin:0;}
.face-modal .face-sub{font-size:12px;color:var(--ink-soft);margin-top:4px;}
.face-video-wrap{position:relative;margin-top:18px;border-radius:16px;overflow:hidden;background:#0D3A2C;aspect-ratio:4/3;border:2px solid rgba(255,255,255,0.1);}
.face-video-wrap video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1);}
.face-video-wrap.no-video{display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;}

.face-status-pill{margin-top:14px;font-size:13px;font-weight:600;color:var(--brand);min-height:18px;display:flex;align-items:center;justify-content:center;gap:6px;}
.face-status-pill.loading::after{content:'';display:inline-block;width:12px;height:12px;border:2px solid var(--brand);border-right-color:transparent;border-radius:50%;animation:spin 0.6s linear infinite;}
.face-status-pill.success{color:#2ecc71;}
.face-status-pill.error{color:var(--danger);}
@keyframes spin{to{transform:rotate(360deg)}}

.face-progress{margin-top:10px;height:8px;border-radius:99px;background:var(--line);overflow:hidden;}
.face-progress-fill{height:100%;width:0%;background:var(--gold);transition:width .25s ease;}

.face-error{margin-top:12px;padding:10px 12px;border-radius:8px;font-size:12px;color:#c0392b;background:rgba(192, 57, 43, 0.1);min-height:16px;display:none;border-left:3px solid #c0392b;}
.face-error.show{display:block;}

.face-modal-actions{margin-top:18px;display:flex;gap:10px;justify-content:center;flex-direction:column;}
.face-modal-actions > button{padding:11px 18px;font-size:13px;font-weight:600;border-radius:8px;border:1.5px solid var(--line);background:transparent;color:var(--ink-soft);cursor:pointer;transition:all 0.2s ease;}
.face-modal-actions > button:hover{background:var(--line);border-color:var(--brand-light);}
.face-modal-actions > button.secondary:hover{background:rgba(255,192,61,0.1);}

.face-retry-info{margin-top:12px;font-size:11px;color:var(--ink-soft);line-height:1.5;}

/* Responsive */
@media (max-width: 480px) {
  .face-modal{max-width:100%;padding:20px;}
  .face-modal h3{font-size:16px;}
}
</style>

<div class="face-modal-backdrop" id="face-modal-backdrop">
  <div class="face-modal">
    <h3>SafeTrade — Face Verification</h3>
    <p class="face-sub" id="face-modal-sub">Center your face in the frame and follow the instructions.</p>
    <div class="face-video-wrap" id="face-video-wrap"><video id="face-video" muted playsinline autoplay></video></div>
    <div class="face-status-pill loading" id="face-status">Loading…</div>
    <div class="face-progress"><div class="face-progress-fill" id="face-progress-fill"></div></div>
    <div class="face-error" id="face-error"></div>
    <div class="face-retry-info" id="face-retry-info"></div>
    <div class="face-modal-actions">
      <button type="button" id="face-modal-retry" style="display:none;" class="secondary">Try Again</button>
      <button type="button" id="face-modal-cancel">Cancel</button>
    </div>
  </div>
</div>

<script>
/*
 * IMPORTANT: window.SAFETRADE_BASE must be set BEFORE face-auth.js
 * loads. face-auth.js reads it once (lazily inside loadModels()).
 */
window.SAFETRADE_BASE = '<?= e(BASE_URL) ?>';
</script>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="<?= e($assetBase ?? '') ?>/assets/js/face-auth.js"></script>
<script>
(function () {
  const backdrop     = document.getElementById('face-modal-backdrop');
  const statusEl     = document.getElementById('face-status');
  const subEl        = document.getElementById('face-modal-sub');
  const progressEl   = document.getElementById('face-progress-fill');
  const errorEl      = document.getElementById('face-error');
  const retryBtn     = document.getElementById('face-modal-retry');
  const cancelBtn    = document.getElementById('face-modal-cancel');
  const videoEl      = document.getElementById('face-video');
  const videoWrap    = document.getElementById('face-video-wrap');
  const retryInfoEl  = document.getElementById('face-retry-info');
  const csrfToken    = window.SAFETRADE_CSRF || '';

  let session = null;
  let retryCount = 0;
  const MAX_RETRIES = 3;
  let currentMode = null;
  let currentCallbacks = null;

  function reset() {
    errorEl.textContent = '';
    errorEl.classList.remove('show');
    progressEl.style.width = '0%';
    statusEl.textContent = 'Loading…';
    statusEl.className = 'face-status-pill loading';
    retryBtn.style.display = 'none';
    retryInfoEl.textContent = '';
    videoWrap.classList.remove('no-video');
  }

  function close() {
    if (session) session.stopCamera();
    backdrop.classList.remove('show');
    retryCount = 0;
  }

  function showError(message) {
    errorEl.textContent = message;
    errorEl.classList.add('show');
    statusEl.className = 'face-status-pill error';
    statusEl.textContent = 'Verification failed';
    
    if (retryCount < MAX_RETRIES) {
      retryBtn.style.display = 'block';
      retryInfoEl.textContent = `Attempt ${retryCount + 1} of ${MAX_RETRIES}`;
    } else {
      retryInfoEl.textContent = 'Maximum retries reached. Please try again later or use email + password.';
    }
  }

  retryBtn.addEventListener('click', function () {
    retryCount++;
    reset();
    startFaceSession();
  });

  cancelBtn.addEventListener('click', close);
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });

  function startFaceSession() {
    if (!session) return;
    
    session.run()
      .then(function (result) {
        const action = currentMode === 'register' ? 'register_face' : 'login_with_face';
        statusEl.className = 'face-status-pill loading';
        statusEl.textContent = 'Sending to server…';
        
        return fetch('<?= e(BASE_URL) ?>/face-verify-api.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: action, embedding: result.descriptor, csrf_token: csrfToken }),
        }).then(function (res) { return res.json(); });
      })
      .then(function (data) {
        if (data && data.ok) {
          statusEl.className = 'face-status-pill success';
          statusEl.textContent = currentMode === 'register' ? '✓ Face verified' : '✓ Signing you in…';
          progressEl.style.width = '100%';
          
          setTimeout(function () {
            close();
            if (currentCallbacks && currentCallbacks.onSuccess) {
              currentCallbacks.onSuccess(data);
            }
          }, 1000);
        } else {
          const msg = (data && data.message) || 'Face not recognized. Please try again.';
          showError(msg);
          if (currentCallbacks && currentCallbacks.onFail) {
            currentCallbacks.onFail(msg);
          }
        }
      })
      .catch(function (err) {
        const message = err.message || 'Something went wrong — please try again.';
        showError(message);
        if (currentCallbacks && currentCallbacks.onFail) {
          currentCallbacks.onFail(message);
        }
      });
  }

  /**
   * mode: 'login' | 'register'
   * callbacks: { onSuccess(data), onFail(message) }
   */
  window.openFaceModal = function (mode, callbacks) {
    currentMode = mode;
    currentCallbacks = callbacks;
    retryCount = 0;
    reset();
    
    subEl.textContent = mode === 'register'
      ? 'We'll ask you to turn your head or blink, then take a snapshot for face login.'
      : 'Center your face and follow the on-screen instructions to verify it's really you.';
    
    backdrop.classList.add('show');

    // Give camera a moment to initialize
    setTimeout(function () {
      session = new SafeTradeFace.FaceSession({
        video: videoEl,
        onStatus: function (msg) { 
          statusEl.textContent = msg;
          statusEl.className = 'face-status-pill loading';
        },
        onProgress: function (pct) { progressEl.style.width = pct + '%'; },
      });
      
      startFaceSession();
    }, 300);
  };
})();
</script>
