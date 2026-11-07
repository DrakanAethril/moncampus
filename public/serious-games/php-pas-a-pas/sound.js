/* PHP pas à pas — the sounds, synthesised (Web Audio): on by default, muted from the game's bar into the
   display state (`mute`). Nothing rests on them: every success is also seen, and felt by a short
   vibration where the phone allows it. */
(function (root) {
  'use strict';
  var ctx = null, muted = false;
  function tone(freq, start, length, type, gain) {
    if (muted) return;
    try {
      ctx = ctx || new (root.AudioContext || root.webkitAudioContext)();
      var o = ctx.createOscillator(), g = ctx.createGain(), t = ctx.currentTime + start;
      o.type = type || 'sine'; o.frequency.value = freq;
      g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(gain || 0.12, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + length);
      o.connect(g); g.connect(ctx.destination); o.start(t); o.stop(t + length + 0.02);
    } catch (e) { /* no audio: nothing to say */ }
  }
  function buzz(ms) { try { if (root.navigator && root.navigator.vibrate) root.navigator.vibrate(ms); } catch (e) { /* not allowed */ } }
  root.PPPSound = {
    setMuted: function (m) { muted = !!m; },
    muted: function () { return muted; },
    click: function () { tone(660, 0, 0.06, 'triangle', 0.05); },
    ok: function () { tone(784, 0, 0.12); tone(1175, 0.09, 0.18); buzz(30); },
    ko: function () { tone(220, 0, 0.18, 'triangle', 0.08); },
    level: function () { [523, 659, 784, 1047].forEach(function (f, i) { tone(f, i * 0.09, 0.22); }); buzz([40, 60, 40]); },
    tick: function () { tone(1400, 0, 0.03, 'square', 0.03); }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
