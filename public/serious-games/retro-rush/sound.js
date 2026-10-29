/* Rétro Rush — synthesised sounds (Web Audio). No file, one voice: round sine and triangle bubbles,
   like the menu of a translucent console of 1999. On by default; muted from the bar, kept in the progress. */
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
    o.type = type || 'sine';
    o.frequency.setValueAtTime(freq, t);
    if (slide) o.frequency.exponentialRampToValueAtTime(slide, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol || 0.07, t + 0.012);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(a.destination);
    o.start(t); o.stop(t + dur + 0.02);
  }
  root.RetroSound = {
    setMuted: function (m) { muted = !!m; },
    muted: function () { return muted; },
    click: function () { tone(880, 0, 0.05, 'sine', 0.05, 1320); },
    type: function () { tone(1400 + Math.random() * 300, 0, 0.02, 'sine', 0.015); },
    run: function () { tone(520, 0, 0.06, 'triangle', 0.05); tone(780, 0.05, 0.08, 'triangle', 0.05); },
    right: function () { tone(660, 0, 0.09, 'sine', 0.08); tone(990, 0.09, 0.14, 'sine', 0.08); },
    wrong: function () { tone(330, 0, 0.16, 'triangle', 0.06, 200); },
    deliver: function () { [392, 523, 659].forEach(function (f, i) { tone(f, i * 0.06, 0.1, 'triangle', 0.05); }); },
    stamp: function () { tone(220, 0, 0.12, 'sine', 0.08, 440); [784, 988, 1175, 1568].forEach(function (f, i) { tone(f, 0.12 + i * 0.07, 0.16, 'sine', 0.06); }); },
    clue: function () { [523, 659, 784, 1046].forEach(function (f, i) { tone(f, i * 0.09, 0.16, 'triangle', 0.05); }); },
    act: function () { tone(196, 0, 0.5, 'sine', 0.06, 392); tone(587, 0.35, 0.14, 'triangle', 0.05); tone(784, 0.48, 0.28, 'triangle', 0.05); }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
