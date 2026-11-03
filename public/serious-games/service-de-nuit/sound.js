/* Service de nuit — synthesised sounds (Web Audio). No file, one voice: the hum and click of a neon tube,
   the brass bell of the desk. On by default; muted from the bar, kept in the progress. */
(function (root) {
  'use strict';
  var ctx = null, muted = false;
  function ac() {
    if (muted) return null;
    try { ctx = ctx || new (root.AudioContext || root.webkitAudioContext)(); } catch (e) { return null; }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }
  function tone(freq, start, dur, type, vol, slide) {
    var a = ac();
    if (!a) return;
    var o = a.createOscillator(), g = a.createGain(), t = a.currentTime + (start || 0);
    o.type = type || 'square';
    o.frequency.setValueAtTime(freq, t);
    if (slide) o.frequency.exponentialRampToValueAtTime(slide, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol || 0.06, t + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(a.destination);
    o.start(t); o.stop(t + dur + 0.02);
  }
  function noise(dur, vol) {
    var a = ac();
    if (!a) return;
    var n = Math.floor(a.sampleRate * dur), buf = a.createBuffer(1, n, a.sampleRate), d = buf.getChannelData(0);
    for (var i = 0; i < n; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / n);
    var s = a.createBufferSource(), g = a.createGain();
    s.buffer = buf; g.gain.value = vol || 0.08;
    s.connect(g); g.connect(a.destination); s.start();
  }
  root.SDNSound = {
    setMuted: function (m) { muted = !!m; },
    muted: function () { return muted; },
    click: function () { tone(880, 0, 0.05, 'triangle', 0.05, 620); },
    run: function () { tone(520, 0, 0.06, 'triangle', 0.05, 780); },
    right: function () { tone(660, 0, 0.08, 'triangle', 0.08); tone(990, 0.08, 0.12, 'triangle', 0.08); },
    wrong: function () { tone(240, 0, 0.2, 'triangle', 0.07, 170); },
    // The desk bell: a struck brass note and its overtone.
    bell: function () { tone(1568, 0, 0.9, 'sine', 0.07); tone(3136, 0, 0.5, 'sine', 0.025); tone(2350, 0.01, 0.6, 'sine', 0.02); },
    // A neon tube catching: a buzz, then the light.
    neon: function () { for (var i = 0; i < 4; i++) noise(0.03, 0.05); tone(120, 0, 0.35, 'sawtooth', 0.03); tone(880, 0.3, 0.25, 'triangle', 0.05); },
    clue: function () { [523, 659, 784, 1046].forEach(function (f, i) { tone(f, i * 0.09, 0.14, 'square', 0.04); }); },
    act: function () { tone(110, 0, 0.5, 'sawtooth', 0.05, 220); tone(440, 0.35, 0.12, 'square', 0.05); tone(660, 0.47, 0.25, 'square', 0.05); },
    stamp: function () { noise(0.08, 0.12); tone(140, 0, 0.2, 'square', 0.06, 70); tone(880, 0.12, 0.1, 'triangle', 0.06); tone(1320, 0.22, 0.16, 'triangle', 0.06); },
    page: function () { noise(0.12, 0.05); tone(300, 0, 0.1, 'sine', 0.03, 500); }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
