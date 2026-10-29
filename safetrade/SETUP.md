# SafeTrade — Face Verification add-on: setup

This adds an **optional** face-login/face-verified-registration layer on top
of your existing email+password auth. Nothing about `register_user()`,
`attempt_login()`'s password path, sessions, or the admin approval flow was
removed — everything here is additive.

## 1. Where each file goes

Copy these into your project at the **same relative paths**:

```
sql/migration_add_face_verification.sql   <- run this once (see step 2)
includes/face_recognition.php             <- new
includes/auth.php                         <- REPLACES your existing one
includes/partials/face-modal.php          <- new (shared camera UI)
face-verify-api.php                       <- new, sits next to chat-api.php
auth/login.php                            <- REPLACES your existing one
auth/register.php                         <- REPLACES your existing one
auth/register-skip-face.php               <- new
assets/js/face-auth.js                    <- new
assets/models/                            <- put the model files here (step 3)
```

`includes/auth.php` and `auth/login.php`/`auth/register.php` are full
replacements, but every original function and code path is still there —
I only split `attempt_login()`'s "check status" and "start session" steps
into two small reusable functions (`login_status_error()`,
`login_start_session()`) so face-login runs through the *exact* same
suspend/ban/pending gate as password login, instead of duplicating that
logic. Diff them against your originals if you want to confirm nothing
else changed.

## 2. Run the DB migration

Your `users.sql` already has a `face_verifications` table (built for a
different, image-based approach). This migration adapts it instead of
creating a new table:

```bash
mysql -u root -p safetrade < sql/migration_add_face_verification.sql
```

or paste its contents into phpMyAdmin's SQL tab. It's safe to re-run.

What it does: makes `selfie_path` nullable (we don't store images), adds
an `embedding` column (JSON array of 128 floats), adds `match_distance`,
adds a unique key on `user_id`, and adds `users.face_verified_at`.

## 3. Get the pre-trained models (no training, no paid API)

This uses **face-api.js** — a JS wrapper around TensorFlow.js shipping
pre-trained, open-source weights (MIT licensed) for face detection,
68-point landmarks, and a 128-d face-recognition descriptor. Everything
runs in the browser; your PHP backend never sees an image, only numbers.

1. Download these three model sets from the face-api.js weights repo:
   `https://github.com/justadudewhohacks/face-api.js/tree/master/weights`
   - `tiny_face_detector_model-weights_manifest.json` + `tiny_face_detector_model-shard1`
   - `face_landmark_68_model-weights_manifest.json` + `face_landmark_68_model-shard1`
   - `face_recognition_model-weights_manifest.json` + `face_recognition_model-shard1` + `face_recognition_model-shard2`
2. Put all of those files directly in `assets/models/` (flat, no subfolders).
3. The face-api.js **library itself** is loaded from a CDN in
   `includes/partials/face-modal.php`:
   ```html
   <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
   ```
   If you'd rather self-host it too (no external request at all), download
   `face-api.min.js` from the same jsdelivr URL, drop it in `assets/js/`,
   and swap that `<script src>` for your local path.

## 4. How it fits your existing flow

**Login** (`auth/login.php`): a "📷 Log in with Face" button opens a
camera modal above your untouched email/password form. On success the
server (`face-verify-api.php` → `login_with_face`) matches the submitted
descriptor against every stored embedding, runs the match through
`login_status_error()` (same suspended/banned/pending checks as password
login), and only then calls `login_start_session()` — the same session
setup password login uses.

**Registration** (`auth/register.php`): your existing form is untouched
and still calls `register_user()` exactly as before (same validation,
same `pending_verification` status). On success it now redirects to a
second step (`?step=face`) instead of straight to the login page. That
step runs the camera + liveness challenge, then calls
`face-verify-api.php` → `register_face`, which:
- re-checks for a duplicate face server-side,
- if found, **deletes the just-created account** and shows *"Face already
  registered. This face is already associated with another SafeTrade
  account."*
- if not, stores the embedding and stamps `users.face_verified_at`.

A **"Skip for now"** link is included on that step — if someone doesn't
have/want to use a camera, their account still finishes registration
exactly as it did before this feature existed; they just won't have
"Log in with Face" available until they add it later.

## 5. Security notes (read this)

- **The browser is never trusted for the verification result.** The JS
  liveness challenge (randomized turn-left/turn-right/blink) is a UX
  deterrent against someone holding up a printed photo — it is *not*
  cryptographically provable server-side. The actual authentication
  decision is 100% server-side: PHP recomputes the Euclidean distance
  between the submitted descriptor and the stored one and only starts a
  session if it's under threshold (`FACE_MATCH_THRESHOLD` in
  `includes/face_recognition.php`, default `0.50`).
- Face descriptors, not images, are stored. No selfie file ever touches
  disk in this flow.
- Face-login attempts share your existing `login_attempts` rate-limit
  table (keyed by `face:<ip>`), so the same 5-attempts/15-minute lockout
  in `includes/auth.php` applies to it too.
- `register_face` only ever acts on `$_SESSION['pending_face_user_id']`,
  which the server itself set right after `register_user()` succeeded —
  the client can never pass in a `user_id` to attach a face to someone
  else's account.
- **This is a basic/medium-strength liveness and matching system**, good
  enough to deter casual spoofing and be a genuine convenience feature.
  It is **not** government-grade KYC or eGov identity verification —
  don't represent it as such to users or regulators. If you need that
  bar later, this is the seam where you'd swap in a vendor liveness API
  without touching the rest of the app (everything vendor-specific is
  isolated in `face-verify-api.php` + `assets/js/face-auth.js`).

## 6. Tuning

- `FACE_MATCH_THRESHOLD` in `includes/face_recognition.php` — lower
  = stricter (fewer false accepts, more false rejects). 0.5 is a
  reasonable starting point; face-api.js's own docs suggest ~0.6 as an
  upper bound for "same person."
- Liveness sensitivity (blink EAR cutoffs, head-yaw ratios) lives in
  `assets/js/face-auth.js` near the top (`eyeAspectRatio`,
  `headYawRatio`, and the `CHALLENGES` object) if lighting/camera
  differences need it adjusted for your users.
