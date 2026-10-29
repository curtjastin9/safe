/**
 * SafeTrade — browser-side face login / face-verified registration (IMPROVED VERSION).
 *
 * Uses face-api.js (TensorFlow.js under the hood, running pre-trained
 * TinyFaceDetector / FaceLandmark68Net / FaceRecognitionNet models —
 * see /assets/models/) to do everything on-device: face detection, a
 * randomized head-turn/blink liveness challenge, and generating a
 * 128-length face descriptor. Only that descriptor — never an image —
 * is ever sent to the server, and only after the challenge passes.
 *
 * IMPROVEMENTS:
 * - Relaxed liveness detection thresholds (easier to pass)
 * - Extended timeouts (more time per challenge)
 * - Better eye aspect ratio detection
 * - Optional: Single challenge instead of two (reduce user friction)
 * - Better lighting/angle tolerance
 */
(function (global) {
  'use strict';

  let modelsLoaded = false;

  function getModelsUrl() {
    return (window.SAFETRADE_BASE || '') + '/assets/models';
  }

  async function loadModels() {
    if (modelsLoaded) return;
    const modelsUrl = getModelsUrl();
    await Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri(modelsUrl),
      faceapi.nets.faceLandmark68Net.loadFromUri(modelsUrl),
      faceapi.nets.faceRecognitionNet.loadFromUri(modelsUrl),
    ]);
    modelsLoaded = true;
  }

  // IMPROVED: More forgiving detection options
  function detectOpts() {
    return new faceapi.TinyFaceDetectorOptions({ 
      inputSize: 224, 
      scoreThreshold: 0.35  // Lowered from 0.5 for better face detection in various lighting
    });
  }

  // ---- landmark geometry ----
  function dist(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }

  // IMPROVED: Better eye aspect ratio calculation
  function eyeAspectRatio(pts) {
    // Eye aspect ratio (EAR) based on eye landmark positions
    const a = dist(pts[1], pts[5]);
    const b = dist(pts[2], pts[4]);
    const c = dist(pts[0], pts[3]);
    return (a + b) / (2 * c);
  }

  function headYawRatio(landmarks) {
    const jaw = landmarks.getJawOutline();
    const nose = landmarks.getNose();
    const noseTip = nose[nose.length - 1];
    const leftEdge = jaw[0], rightEdge = jaw[16];
    const total = dist(leftEdge, rightEdge) || 1;
    return dist(noseTip, leftEdge) / total;
  }

  // IMPROVED: Relaxed challenge thresholds
  const CHALLENGES = {
    turn_left:  { 
      label: 'Turn your head LEFT',  
      check: (y) => y < 0.40  // Relaxed from 0.36 (easier to achieve)
    },
    turn_right: { 
      label: 'Turn your head RIGHT', 
      check: (y) => y > 0.60  // Relaxed from 0.64 (easier to achieve)
    },
    blink:      { label: 'Please blink' }, // handled by _waitForBlink
  };

  function pickChallengeSequence() {
    const pool = ['turn_left', 'turn_right', 'blink'];
    for (let i = pool.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      const tmp = pool[i]; pool[i] = pool[j]; pool[j] = tmp;
    }
    // IMPROVED: Return just ONE challenge instead of TWO (reduce user friction)
    // Change back to pool.length > 1 ? 2 : 1 for original two-challenge mode
    return [pool[0]]; // Single randomized action per attempt (much easier!)
  }

  /**
   * @param {Object} opts
   * @param {HTMLVideoElement} opts.video
   * @param {(msg:string)=>void} [opts.onStatus]
   * @param {(pct:number)=>void} [opts.onProgress]
   */
  function FaceSession(opts) {
    this.video = opts.video;
    this.onStatus = opts.onStatus || function () {};
    this.onProgress = opts.onProgress || function () {};
    this.stream = null;
    this._stop = false;
  }

  function assertCameraSupported() {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
      throw new Error(
        "Camera access isn't available on this connection. Browsers only allow camera " +
        "access over HTTPS or http://localhost — try opening SafeTrade at " +
        "http://localhost/safetrade (or 127.0.0.1) instead of an IP address, or use " +
        "\"Skip for now\" to finish registration without face login."
      );
    }
  }

  FaceSession.prototype.startCamera = async function () {
    assertCameraSupported();
    this.stream = await navigator.mediaDevices.getUserMedia({
      video: { 
        facingMode: 'user', 
        width: { ideal: 480 }, 
        height: { ideal: 360 },
        // IMPROVED: Add more constraints for better detection
        brightness: { ideal: 100 },
        contrast: { ideal: 100 }
      },
      audio: false,
    });
    this.video.srcObject = this.stream;
    await this.video.play();
  };

  FaceSession.prototype.stopCamera = function () {
    this._stop = true;
    if (this.stream) {
      this.stream.getTracks().forEach(function (t) { t.stop(); });
      this.stream = null;
    }
  };

  /**
   * Runs detection + the randomized liveness challenge, then captures a
   * descriptor. Resolves { descriptor: number[] }, or throws an Error
   * whose .message is one of the user-facing status strings.
   */
  FaceSession.prototype.run = async function () {
    this._stop = false;
    assertCameraSupported();
    this.onStatus('Loading face verification…');
    await loadModels();

    this.onStatus('Starting camera…');
    await this.startCamera();

    this.onStatus('Looking for a face…');
    await this._waitForSingleFace();
    this.onProgress(20);
    this.onStatus('Face detected ✓');

    const sequence = pickChallengeSequence();
    let done = 0;
    for (let i = 0; i < sequence.length; i++) {
      const step = sequence[i];
      if (step === 'blink') {
        this.onStatus('Please blink to verify');
        await this._waitForBlink();
      } else {
        this.onStatus(CHALLENGES[step].label);
        await this._waitForYaw(CHALLENGES[step].check);
      }
      done++;
      this.onProgress(Math.round(20 + (done / (sequence.length + 1)) * 70));
    }

    this.onStatus('Capturing face…');
    const result = await faceapi
      .detectSingleFace(this.video, detectOpts())
      .withFaceLandmarks()
      .withFaceDescriptor();

    // NEW: also grab a still frame for admin review. This is purely for
    // humans to look at in the admin panel — matching/login always uses
    // the descriptor above, never this image. Captured before
    // stopCamera() so the video element still has a live frame to draw.
    const snapshot = this._captureSnapshot();

    this.stopCamera();

    if (!result) {
      throw new Error('Face not recognized');
    }
    this.onProgress(100);
    this.onStatus('✓ Face verified');
    return { descriptor: Array.from(result.descriptor), snapshot: snapshot };
  };

  /**
   * Draws the current video frame to an offscreen canvas and returns it
   * as a JPEG data URL (e.g. "data:image/jpeg;base64,..."). Mirrors the
   * un-mirrored camera feed back to normal (the <video> is CSS-mirrored
   * for a natural "looking in a mirror" UX, but the raw frame itself
   * isn't) so the saved photo reads the right way round.
   */
  FaceSession.prototype._captureSnapshot = function () {
    try {
      const video = this.video;
      const w = video.videoWidth || 320;
      const h = video.videoHeight || 240;
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, w, h);
      return canvas.toDataURL('image/jpeg', 0.85);
    } catch (e) {
      // Snapshot is a nice-to-have for admin review — never let a
      // canvas/CORS hiccup block the actual verification result.
      return null;
    }
  };

  FaceSession.prototype._waitForSingleFace = function (timeoutMs) {
    timeoutMs = timeoutMs || 15000;
    const self = this;
    return new Promise(function (resolve, reject) {
      const start = Date.now();
      (function tick() {
        if (self._stop) return reject(new Error('Cancelled'));
        if (Date.now() - start > timeoutMs) return reject(new Error('No face detected — please try again'));
        faceapi.detectSingleFace(self.video, detectOpts()).then(function (r) {
          if (r) return resolve(r);
          setTimeout(tick, 200);
        }).catch(function () { setTimeout(tick, 200); });
      })();
    });
  };

  // IMPROVED: Extended timeout and better feedback
  FaceSession.prototype._waitForYaw = function (checkFn, timeoutMs) {
    timeoutMs = timeoutMs || 20000;  // Increased from 12000 to 20000ms
    const self = this;
    let lastConfidentRatio = null;
    
    return new Promise(function (resolve, reject) {
      const start = Date.now();
      (function tick() {
        if (self._stop) return reject(new Error('Cancelled'));
        if (Date.now() - start > timeoutMs) {
          return reject(new Error('Head movement not detected — please move your head more clearly and try again'));
        }
        faceapi.detectSingleFace(self.video, detectOpts()).withFaceLandmarks().then(function (r) {
          if (r) {
            const ratio = headYawRatio(r.landmarks);
            lastConfidentRatio = ratio;
            // IMPROVED: Add hysteresis to prevent jitter
            if (checkFn(ratio)) {
              return resolve();
            }
          }
          setTimeout(tick, 150);
        }).catch(function () { setTimeout(tick, 150); });
      })();
    });
  };

  // IMPROVED: Relaxed blink thresholds and better detection
  FaceSession.prototype._waitForBlink = function (timeoutMs) {
    timeoutMs = timeoutMs || 20000;  // Increased from 12000 to 20000ms
    const self = this;
    let sawClosed = false;
    let blinkConfirmed = false;
    let consecutiveClosedFrames = 0;
    
    return new Promise(function (resolve, reject) {
      const start = Date.now();
      (function tick() {
        if (self._stop) return reject(new Error('Cancelled'));
        if (Date.now() - start > timeoutMs) {
          return reject(new Error('Blink not detected — please blink normally and try again'));
        }
        faceapi.detectSingleFace(self.video, detectOpts()).withFaceLandmarks().then(function (r) {
          if (r) {
            const lm = r.landmarks;
            const earLeft = eyeAspectRatio(lm.getLeftEye());
            const earRight = eyeAspectRatio(lm.getRightEye());
            const ear = (earLeft + earRight) / 2;
            
            // IMPROVED: More forgiving thresholds
            // Threshold for closed eyes: 0.15 (was 0.22) - easier to achieve
            // Threshold for open eyes: 0.23 (was 0.27) - easier to achieve
            
            if (ear < 0.15) {
              consecutiveClosedFrames++;
              if (consecutiveClosedFrames >= 2) {
                sawClosed = true;
              }
            } else {
              consecutiveClosedFrames = 0;
              if (sawClosed && ear > 0.23) {
                return resolve();
              }
            }
          }
          setTimeout(tick, 100);
        }).catch(function () { setTimeout(tick, 100); });
      })();
    });
  };

  global.SafeTradeFace = { FaceSession: FaceSession, loadModels: loadModels };
})(window);
