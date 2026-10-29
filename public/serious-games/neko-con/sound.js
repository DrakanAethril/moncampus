/* Panique à la Neko-Con — the sounds, synthesised (Web Audio, no file).

   On by default as in the other serious games, muted by the sound button of the bar.
   Whatever a sound tells is also shown on screen. */
(function (root) {
  'use strict';
  var ctx = null, muted = false;
  var VOICES = { maelle: 660, ines: 330, theo: 440, mika: 520, system: 500 };

  function ac() {
    if (muted) return null;
    if (!ctx) { try { ctx = new (root.AudioContext || root.webkitAudioContext)(); } catch (e) { return null; } }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }
  function tone(freq, start, dur, type, vol) {
    var c = ac(); if (!c) return;
    var o = c.createOscillator(), g = c.createGain(), t = c.currentTime + (start || 0);
    o.type = type || 'sine'; o.frequency.setValueAtTime(freq, t);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol || 0.12, t + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(c.destination); o.start(t); o.stop(t + dur + 0.02);
  }
  function noise(start, dur, vol) {
    var c = ac(); if (!c) return;
    var len = Math.floor(c.sampleRate * dur), buf = c.createBuffer(1, len, c.sampleRate), d = buf.getChannelData(0);
    for (var i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / len);
    var s = c.createBufferSource(), g = c.createGain(), t = c.currentTime + (start || 0);
    s.buffer = buf; g.gain.value = vol || 0.2; s.connect(g); g.connect(c.destination); s.start(t);
  }

  root.NekoSound = {
    setMuted: function (m) { muted = !!m; },
    isMuted: function () { return muted; },
    blip: function (who) { var f = VOICES[who] || 500; tone(f * (0.92 + Math.random() * 0.16), 0, 0.05, 'square', 0.025); },
    notify: function () { tone(880, 0, 0.12, 'sine', 0.1); tone(1320, 0.1, 0.18, 'sine', 0.08); },
    click: function () { tone(600, 0, 0.04, 'triangle', 0.05); },
    wrong: function () { tone(220, 0, 0.18, 'triangle', 0.08); tone(196, 0.12, 0.22, 'triangle', 0.07); },
    right: function () { tone(660, 0, 0.1, 'sine', 0.09); tone(990, 0.08, 0.16, 'sine', 0.08); },
    stamp: function () { noise(0, 0.12, 0.35); tone(110, 0, 0.2, 'sine', 0.2); },
    fragment: function () { [784, 988, 1175, 1568].forEach(function (f, i) { tone(f, i * 0.09, 0.3, 'sine', 0.07); }); },
    bascule: function () {
      noise(0, 0.6, 0.12);
      [523, 659, 784, 1047, 1319].forEach(function (f, i) { tone(f, 0.3 + i * 0.12, 0.6, 'triangle', 0.07); });
      [1047, 1319, 1568].forEach(function (f) { tone(f, 1.0, 1.2, 'sine', 0.05); });
    },
    fanfare: function () {
      [[523, 0], [659, 0.15], [784, 0.3], [1047, 0.45], [784, 0.65], [1047, 0.8]].forEach(function (n) { tone(n[0], n[1], 0.35, 'triangle', 0.08); });
    }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
