/* Formatool — serious games — what keeps a player in the game, shared by every game.

   A classic script that sets globalThis.SGEngage:

     gauge(opts)         the gauge of a series or a comet study in progress: how many right answers
                         to the next level, to beat the record, to elucidate the comet. Drawn from
                         what the corrections already said - never during the final test, which
                         says nothing before its end.
     streak(results)     the right answers in a row at the end of a run's results.
     closest(units, ex)  the unit nearest to its next level, for « encore une » at the end of a series.
     sound               synthesised sounds (Web Audio, no file): on by default, the player mutes them
                         from the game's top bar; the choice is kept in the game's display state.
     burst(el, strong)   a burst of sparks around an element (nothing under prefers-reduced-motion).
     bestiaryArt(slug, id, size)  the drawing of an elucidated typical error, seeded by its id: the
                         same error always looks the same, two errors never quite alike.
     story               the game's story told page by page (the game's own story.js holds the text):
                         a page opens at each unit lit - counted, not tied to a unit, so the story
                         reads in order whatever order the units are lit in - the pages still to come
                         stay visible and blank, and the last one, which solves the mystery, opens
                         once the final test has been played to its end.

   Nothing here decides what a player acquires: the levels are the rule's (6 lit, then 8, 10, 12 -
   design/validated/serious-games.md §1.4), read here to say how far the next one is. */
(function (root) {
  'use strict';

  var STEPS = [6, 8, 10, 12];
  var reduceMotion = root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function plural(n, one, many) { return n + ' ' + (n > 1 ? many : one); }

  /* ---------- the gauge ---------- */

  function nextStep(best) {
    var b = best === null || best === undefined ? -1 : best;
    for (var i = 0; i < STEPS.length; i++) if (STEPS[i] > b) return STEPS[i];
    return null;
  }

  /* opts: {mode: 'series'|'comet', results: ['ok'|'ko'|'dk'|'past'], size, best (series: the best
     before this series, null if never played), solvedAt (comet: right answers that elucidate it),
     levels: {6: ['allumer l’étoile', 'L’étoile s’allume !'], 8: ['l’éclat nette', 'Éclat nette atteint !'], …}
     (per level: what reaching it is called, then the sentence once reached),
     solve: 'l’élucider', record: 'votre record'}.
     Answers already given before a resume ('past') are not known here: the gauge then stays quiet. */
  function gauge(opts) {
    var results = opts.results || [], size = opts.size || 12;
    var answered = 0, ok = 0, past = false;
    for (var k = 0; k < results.length; k++) {
      if (!results[k]) continue;
      answered++;
      if (results[k] === 'ok') ok++;
      if (results[k] === 'past') past = true;
    }
    if (past) return { html: '', text: '' };
    var left = Math.max(0, size - answered), msg, ticks = [];

    if (opts.mode === 'comet') {
      var at = opts.solvedAt || 2, need = at - ok;
      msg = need <= 0 ? 'C’est gagné : ' + ok + ' justes sur ' + size + '.'
        : need <= left ? 'Encore ' + plural(need, 'juste', 'justes') + ' pour ' + (opts.solve || 'l’élucider') + '.'
          : 'Elle restera en vue cette fois : rien n’est perdu, elle se rejoue.';
      ticks.push({ at: at, label: '' });
    } else {
      var best = opts.best === null || opts.best === undefined ? null : opts.best;
      var levels = opts.levels || {}, parts = [];
      // a level this very series has just reached
      var reached = null;
      STEPS.forEach(function (s) { if (s > (best === null ? -1 : best) && s <= ok) reached = s; });
      if (reached !== null) parts.push((levels[reached] || [])[1] || reached + '/' + size + ' atteint !');
      var target = null;
      STEPS.forEach(function (s) { if (target === null && s > ok && s > (best === null ? -1 : best)) target = s; });
      if (target !== null && target - ok <= left) {
        parts.push('Encore ' + plural(target - ok, 'juste', 'justes') + ' pour ' + ((levels[target] || [])[0] || target + '/' + size) + '.');
      } else if (best !== null && ok > best) {
        parts.push('Nouveau record : ' + ok + '/' + size + '.');
      } else if (best !== null && best + 1 - ok <= left && best < size) {
        parts.push('Encore ' + plural(best + 1 - ok, 'juste', 'justes') + ' pour battre ' + (opts.record || 'votre record') + ' (' + best + '/' + size + ').');
      } else if (best !== null && reached === null) {
        parts.push(best >= size ? 'Au plus haut : cette série ne peut rien retirer.' : cap(opts.record || 'votre record') + ' (' + best + '/' + size + ') reste acquis.');
      }
      msg = parts.join(' ');
      STEPS.forEach(function (s) { ticks.push({ at: s, label: String(s) }); });
      if (best !== null && best > 0) ticks.push({ at: best, label: '', record: true });
    }
    if (left > 0 && msg) msg += ' <span class="sg-gauge__left">Reste ' + plural(left, 'question', 'questions') + '.</span>';

    var pct = Math.round(ok / size * 100);
    var html = '<div class="sg-gauge"><div class="sg-gauge__track" aria-hidden="true"><span class="sg-gauge__fill" style="width:' + pct + '%"></span>' +
      ticks.map(function (t) {
        return '<span class="sg-gauge__tick' + (t.record ? ' sg-gauge__tick--record' : '') + (!t.record && ok >= t.at ? ' is-reached' : '') + '" style="left:' + (t.at / size * 100) + '%">' +
          (t.label ? '<i>' + esc(t.label) + '</i>' : '') + (t.record ? '<i>record</i>' : '') + '</span>';
      }).join('') + '</div>' +
      (msg ? '<p class="sg-gauge__msg">' + msg + '</p>' : '') + '</div>';
    return { html: html, text: msg.replace(/<[^>]+>/g, '') };
  }
  function cap(s) { s = String(s); return s.charAt(0).toUpperCase() + s.slice(1); }

  function streak(results) {
    var n = 0;
    for (var k = (results || []).length - 1; k >= 0; k--) {
      if (!results[k]) continue;
      if (results[k] !== 'ok') break;
      n++;
    }
    return n;
  }

  /* The unit nearest to its next level: the fewest right answers missing, then the highest best -
     a fact read on the progress, never a recommendation. Hidden and maxed-out units are left out. */
  function closest(units, exclude) {
    var bestUnit = null, bestNeed = 99;
    (units || []).forEach(function (u) {
      if (u.hidden || u.code === exclude) return;
      var step = nextStep(u.best);
      if (step === null) return;
      var need = step - (u.best || 0);
      if (need < bestNeed || (need === bestNeed && (u.best || 0) > (bestUnit.best || 0))) { bestUnit = u; bestNeed = need; }
    });
    return bestUnit ? { unit: bestUnit, step: nextStep(bestUnit.best), need: bestNeed } : null;
  }

  /* ---------- sounds ---------- */

  // Per game a voice: a waveform, its envelope, a register and an echo.
  var VOICES = {
    bell: { wave: 'sine', partial: 2.76, decay: 1.1, base: 659.25, echo: 0 },
    lyre: { wave: 'triangle', partial: 2, decay: .55, base: 392, echo: 0 },
    sonar: { wave: 'sine', partial: 0, decay: .7, base: 523.25, echo: .22, glide: true },
    keys: { wave: 'square', partial: 0, decay: .32, base: 440, echo: 0, filter: 1600 }
  };
  var PENTA = [0, 2, 4, 7, 9, 12, 14, 16, 19, 21, 24];

  var sound = (function () {
    var ctx = null, master = null, voice = VOICES.bell, muted = false;

    function context() {
      if (ctx) return ctx;
      var AC = root.AudioContext || root.webkitAudioContext;
      if (!AC) return null;
      try { ctx = new AC(); } catch (e) { return null; }
      master = ctx.createGain();
      master.gain.value = .16;
      master.connect(ctx.destination);
      return ctx;
    }
    // browsers start an audio context suspended until a gesture inside the frame
    function unlock() { var c = context(); if (c && c.state === 'suspended') c.resume(); }
    root.addEventListener('pointerdown', unlock, true);
    root.addEventListener('keydown', unlock, true);

    function note(semi, when, gain, length) {
      var c = context();
      if (!c || muted) return;
      var t = c.currentTime + (when || 0), f = voice.base * Math.pow(2, semi / 12), dur = (length || 1) * voice.decay;
      var out = c.createGain();
      out.gain.setValueAtTime(0, t);
      out.gain.linearRampToValueAtTime(gain || .8, t + .012);
      out.gain.exponentialRampToValueAtTime(.0008, t + dur);
      var dest = out;
      if (voice.filter) {
        var lp = c.createBiquadFilter();
        lp.type = 'lowpass';
        lp.frequency.value = voice.filter;
        out.connect(lp);
        dest = lp;
      }
      dest.connect(master);
      if (voice.echo) {
        var delay = c.createDelay(1), fb = c.createGain();
        delay.delayTime.value = voice.echo;
        fb.gain.value = .32;
        dest.connect(delay); delay.connect(fb); fb.connect(delay); fb.connect(master);
      }
      [[1, 1], [voice.partial, .18]].forEach(function (p) {
        if (!p[0]) return;
        var o = c.createOscillator();
        o.type = voice.wave;
        o.frequency.setValueAtTime(f * p[0], t);
        if (voice.glide) o.frequency.exponentialRampToValueAtTime(f * p[0] * .985, t + dur);
        var g = c.createGain();
        g.gain.value = p[1] * (voice.wave === 'square' ? .35 : 1);
        o.connect(g); g.connect(out);
        o.start(t); o.stop(t + dur + .05);
      });
    }
    function arpeggio(steps, gap, gain, length) { steps.forEach(function (s, i) { note(s, i * gap, gain, length); }); }

    var CUES = {
      // a right answer climbs the scale with the streak
      ok: function (n) { var i = Math.min(Math.max(1, n || 1) - 1, PENTA.length - 2); note(PENTA[i], 0, .7, .8); note(PENTA[i + 1], .07, .55, .9); },
      ko: function () { note(-5, 0, .32, .5); note(-8, .11, .26, .6); },
      combo: function (n) { arpeggio([0, 4, 7, 12, 16].slice(0, Math.min(5, 2 + Math.floor((n || 4) / 4))).map(function (s) { return s + 7; }), .06, .5, .7); },
      record: function () { arpeggio([0, 4, 7, 12], .09, .6, 1); },
      level: function () { arpeggio([0, 4, 7, 12, 16, 19, 24], .085, .6, 1.3); note(0, .7, .4, 2); note(7, .7, .3, 2); note(12, .7, .3, 2); },
      solved: function () { arpeggio([-5, 0, 4, 7], .12, .55, 1.2); note(12, .5, .4, 1.8); },
      reward: function () { arpeggio([12, 16, 19, 24, 28, 31], .07, .45, 1.1); },
      end: function () { note(0, 0, .5, 1.4); note(4, .02, .35, 1.4); note(7, .04, .35, 1.4); }
    };

    return {
      voice: function (name) { voice = VOICES[name] || VOICES.bell; },
      play: function (cue, n) { if (!muted && CUES[cue]) { try { CUES[cue](n); } catch (e) { /* a sound is never worth an error */ } } },
      muted: function () { return muted; },
      setMuted: function (m) { muted = !!m; },
      // the toggle of the top bar; the game handles data-act="sound"
      button: function (cls) {
        return '<button type="button" class="' + (cls || 'btn btn--small') + ' sg-sound" data-act="sound" aria-pressed="' + !muted + '" aria-label="Son ' + (muted ? 'coupé' : 'activé') + '">' +
          '<svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h3l4-3.5v11L6 12H3z"/>' +
          (muted ? '<path d="M13.5 7.5l4 5M17.5 7.5l-4 5"/>' : '<path d="M13.2 7.2a4 4 0 0 1 0 5.6M15.6 5a7 7 0 0 1 0 10"/>') + '</svg>' +
          '<span>' + (muted ? 'Muet' : 'Son') + '</span></button>';
      }
    };
  })();

  /* ---------- a burst of sparks ---------- */

  function burst(el, strong) {
    if (reduceMotion || !el) return;
    var r = el.getBoundingClientRect(), n = strong ? 28 : 16;
    var host = document.createElement('div');
    host.className = 'sg-burst';
    host.setAttribute('aria-hidden', 'true');
    host.style.left = (r.left + r.width / 2 + root.scrollX) + 'px';
    host.style.top = (r.top + r.height / 2 + root.scrollY) + 'px';
    for (var i = 0; i < n; i++) {
      var s = document.createElement('i'), a = (i / n) * Math.PI * 2 + Math.random() * .3, d = (strong ? 120 : 80) + Math.random() * (strong ? 110 : 60);
      s.style.setProperty('--dx', Math.round(Math.cos(a) * d) + 'px');
      s.style.setProperty('--dy', Math.round(Math.sin(a) * d) + 'px');
      s.style.animationDelay = Math.round(Math.random() * 120) + 'ms';
      if (i % 3 === 0) s.className = 'is-big';
      host.appendChild(s);
    }
    document.body.appendChild(host);
    setTimeout(function () { host.remove(); }, 1500);
  }

  /* ---------- the bestiary's drawings ---------- */

  function hash(s) {
    var h = 2166136261;
    for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); }
    return h >>> 0;
  }
  function rng(seed) {
    var a = seed || 1;
    return function () { a |= 0; a = a + 0x6D2B79F5 | 0; var t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; };
  }
  function f1(n) { return Math.round(n * 10) / 10; }

  var ART = {
    // a comet: its head, a tail of three strands at its own angle, a few stars around
    observatoire: function (r) {
      var ang = -15 - r() * 150, rad = ang * Math.PI / 180, len = 48 + r() * 22, hue = ['#F2CE7A', '#A9C1E8', '#EDE6D6'][Math.floor(r() * 3)];
      // the head and its tail centred in the frame, whatever the angle
      var hx = 50 - Math.cos(rad) * len / 2, hy = 50 - Math.sin(rad) * len / 2, out = '';
      for (var s = 0; s < 3; s++) {
        var spread = (s - 1) * (8 + r() * 6), tx = hx + Math.cos(rad) * len + Math.sin(rad) * spread, ty = hy + Math.sin(rad) * len - Math.cos(rad) * spread;
        var cx = hx + Math.cos(rad) * len * .5 + Math.sin(rad) * spread * .3, cy = hy + Math.sin(rad) * len * .5 - Math.cos(rad) * spread * .3;
        out += '<path d="M' + f1(hx) + ' ' + f1(hy) + 'Q' + f1(cx) + ' ' + f1(cy) + ' ' + f1(tx) + ' ' + f1(ty) + '" stroke="' + hue + '" stroke-width="' + (s === 1 ? 3 : 1.6) + '" stroke-linecap="round" opacity="' + (s === 1 ? .8 : .45) + '" fill="none"/>';
      }
      for (var k = 0; k < 6; k++) out += '<circle cx="' + f1(8 + r() * 84) + '" cy="' + f1(8 + r() * 84) + '" r="' + f1(.6 + r() * 1.1) + '" fill="#EDE6D6" opacity="' + f1(.3 + r() * .5) + '"/>';
      return '<rect width="100" height="100" rx="10" fill="#101833"/>' + out +
        '<circle cx="' + f1(hx) + '" cy="' + f1(hy) + '" r="9" fill="' + hue + '" opacity=".25"/><circle cx="' + f1(hx) + '" cy="' + f1(hy) + '" r="4.5" fill="#FFF6DD"/>';
    },
    // a tablet whose crack was mended with gold
    pompei: function (r) {
      var pts = [], x = 14 + r() * 20, y = 14;
      pts.push([x, y]);
      while (y < 84) { y += 9 + r() * 9; x = Math.max(16, Math.min(84, x + (r() - .5) * 30)); pts.push([x, Math.min(86, y)]); }
      var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + f1(p[0]) + ' ' + f1(p[1]); }).join('');
      var b = pts[Math.floor(pts.length / 2)], branch = 'M' + f1(b[0]) + ' ' + f1(b[1]) + 'l' + f1((r() > .5 ? 1 : -1) * (10 + r() * 12)) + ' ' + f1(4 + r() * 8);
      return '<rect width="100" height="100" rx="10" fill="#3A2619"/><rect x="10" y="10" width="80" height="80" rx="4" fill="#B9AE9C"/><rect x="14" y="14" width="72" height="72" rx="2" fill="none" stroke="#8F877D" stroke-width="1"/>' +
        '<path d="' + d + '" stroke="#5B4A3A" stroke-width="4" fill="none" stroke-linejoin="round"/><path d="' + d + '" stroke="#E8B85E" stroke-width="2" fill="none" stroke-linejoin="round"/>' +
        '<path d="' + branch + '" stroke="#E8B85E" stroke-width="1.4" fill="none" stroke-linecap="round"/>';
    },
    // a creature of the deep, of one of three families - a jellyfish, an anglerfish, a siphonophore -
    // in its own light
    abysse: function (r) {
      var hue = ['#7FF5E3', '#7FF5A0', '#B79CFF', '#FFB86B', '#FF8AC8', '#8EC5FF'][Math.floor(r() * 6)], kind = Math.floor(r() * 3), out = '', k;
      if (kind === 0) {
        var w = 22 + r() * 12, top = 22 + r() * 8, n = 3 + Math.floor(r() * 4);
        for (k = 0; k < n; k++) {
          var x = 50 - w * .8 + (k + .5) * (w * 1.6 / n), len = 26 + r() * 22, wv = 4 + r() * 6;
          out += '<path d="M' + f1(x) + ' ' + f1(top + 16) + 'q' + f1(wv) + ' ' + f1(len / 3) + ' 0 ' + f1(len / 1.5) + 't0 ' + f1(len / 3) + '" stroke="' + hue + '" stroke-width="1.5" fill="none" opacity=".7"/>';
        }
        out += '<path d="M' + f1(50 - w) + ' ' + f1(top + 16) + 'Q' + f1(50 - w) + ' ' + f1(top - 6) + ' 50 ' + f1(top - 6) + 'Q' + f1(50 + w) + ' ' + f1(top - 6) + ' ' + f1(50 + w) + ' ' + f1(top + 16) + 'Z" fill="' + hue + '" opacity=".35" stroke="' + hue + '" stroke-width="1.5"/>';
        for (k = 0; k < 4; k++) out += '<circle cx="' + f1(50 + (r() - .5) * w * 1.4) + '" cy="' + f1(top + 4 + r() * 10) + '" r="1.4" fill="#FFFFFF" opacity=".9"/>';
      } else if (kind === 1) {
        var bx = 42 + r() * 8, by = 60 + r() * 8, bw = 22 + r() * 8, bh = 14 + r() * 6, jaw = 4 + r() * 5;
        out += '<path d="M' + f1(bx - bw) + ' ' + f1(by) + 'Q' + f1(bx) + ' ' + f1(by - bh * 2) + ' ' + f1(bx + bw) + ' ' + f1(by) + 'Q' + f1(bx) + ' ' + f1(by + bh * 1.6) + ' ' + f1(bx - bw) + ' ' + f1(by) + 'Z" fill="#0A1A20" stroke="' + hue + '" stroke-width="1.5"/>' +
          '<path d="M' + f1(bx + bw) + ' ' + f1(by) + 'l10 -' + f1(jaw + 4) + 'v' + f1(jaw * 2 + 8) + 'z" fill="#0A1A20" stroke="' + hue + '" stroke-width="1.5"/>' +
          '<path d="M' + f1(bx - bw * .6) + ' ' + f1(by + 2) + 'l3 4l3 -4l3 4l3 -4" stroke="#FFFFFF" stroke-width="1" fill="none" opacity=".8"/>' +
          '<circle cx="' + f1(bx - bw * .45) + '" cy="' + f1(by - 4) + '" r="2" fill="#FFFFFF"/>';
        var lx = bx - bw * .9 - r() * 8, ly = by - bh * 2.2 - r() * 8;
        out += '<path d="M' + f1(bx - bw * .2) + ' ' + f1(by - bh * .9) + 'Q' + f1(bx - bw * .3) + ' ' + f1(ly - 6) + ' ' + f1(lx) + ' ' + f1(ly) + '" stroke="' + hue + '" stroke-width="1.2" fill="none"/>' +
          '<circle cx="' + f1(lx) + '" cy="' + f1(ly) + '" r="7" fill="' + hue + '" opacity=".25"/><circle cx="' + f1(lx) + '" cy="' + f1(ly) + '" r="3" fill="' + hue + '"/>';
      } else {
        var sx = 30 + r() * 40, sy = 10, seg = 5 + Math.floor(r() * 4), sway = (r() - .5) * 10;
        for (k = 0; k < seg; k++) {
          var px = sx + Math.sin(k * .9) * sway, py = sy + 8 + k * (70 / seg), rr = 4 + r() * 2.5;
          out += '<ellipse cx="' + f1(px) + '" cy="' + f1(py) + '" rx="' + f1(rr * 1.3) + '" ry="' + f1(rr) + '" fill="' + hue + '" opacity="' + f1(.3 + k / seg * .4) + '" stroke="' + hue + '" stroke-width="1"/>' +
            '<path d="M' + f1(px - rr) + ' ' + f1(py) + 'q-' + f1(6 + r() * 6) + ' ' + f1(4 + r() * 4) + ' -' + f1(10 + r() * 8) + ' ' + f1(2 + r() * 6) + 'M' + f1(px + rr) + ' ' + f1(py) + 'q' + f1(6 + r() * 6) + ' ' + f1(4 + r() * 4) + ' ' + f1(10 + r() * 8) + ' ' + f1(2 + r() * 6) + '" stroke="' + hue + '" stroke-width=".9" fill="none" opacity=".6"/>';
        }
      }
      for (k = 0; k < 5; k++) out += '<circle cx="' + f1(r() * 100) + '" cy="' + f1(r() * 100) + '" r=".8" fill="#CFF7F1" opacity=".35"/>';
      return '<rect width="100" height="100" rx="10" fill="#062A35"/>' + out;
    },
    // a card pinned on the board, a fingerprint of its own kind (whorl, loop or arch) and ink, the
    // red thread cut: the false lead is lifted
    irregulars: function (r) {
      var out = '', cx = 46 + r() * 8, cy = 52 + r() * 6, rot = f1((r() - .5) * 14);
      var ink = ['#5C6450', '#26261F', '#3B4A6B', '#7A3B2E'][Math.floor(r() * 4)], kind = Math.floor(r() * 3), k;
      var dash = function () { return f1(10 + r() * 30) + ' ' + f1(2 + r() * 5); };
      if (kind === 0) {
        for (k = 0; k < 6; k++) {
          var rx = 5 + k * 4.2, ry = rx * (1.2 + r() * .2);
          out += '<ellipse cx="' + f1(cx) + '" cy="' + f1(cy) + '" rx="' + f1(rx) + '" ry="' + f1(ry) + '" fill="none" stroke="' + ink + '" stroke-width="1.3" stroke-dasharray="' + dash() + '" transform="rotate(' + f1(r() * 360) + ' ' + f1(cx) + ' ' + f1(cy) + ')"/>';
        }
      } else if (kind === 1) {
        var lean = (r() - .5) * 14;
        for (k = 0; k < 7; k++) {
          var w = 6 + k * 3.6, h = 8 + k * 4.4;
          out += '<path d="M' + f1(cx - w + lean) + ' ' + f1(cy + h * .9) + 'C' + f1(cx - w) + ' ' + f1(cy - h) + ' ' + f1(cx + w) + ' ' + f1(cy - h) + ' ' + f1(cx + w + lean * .4) + ' ' + f1(cy + h * .9) + '" fill="none" stroke="' + ink + '" stroke-width="1.3" stroke-dasharray="' + dash() + '"/>';
        }
      } else {
        for (k = 0; k < 8; k++) {
          var y = cy + 20 - k * 5.2, lift = 3 + k * 1.6 + r() * 2;
          out += '<path d="M' + f1(cx - 24) + ' ' + f1(y) + 'Q' + f1(cx + (r() - .5) * 8) + ' ' + f1(y - lift * 2) + ' ' + f1(cx + 24) + ' ' + f1(y) + '" fill="none" stroke="' + ink + '" stroke-width="1.3" stroke-dasharray="' + dash() + '"/>';
        }
      }
      return '<rect width="100" height="100" rx="10" fill="#B08A5A"/><g transform="rotate(' + rot + ' 50 50)"><rect x="18" y="16" width="64" height="72" fill="' + (r() > .5 ? '#F4EEDC' : '#E6DCC6') + '"/>' + out + '</g>' +
        '<path d="M2 ' + f1(20 + r() * 20) + 'L44 12" stroke="#A3281C" stroke-width="1.6"/><path d="M58 11L98 ' + f1(24 + r() * 20) + '" stroke="#A3281C" stroke-width="1.6"/>' +
        '<circle cx="50" cy="12" r="4" fill="' + (r() > .5 ? '#C9A04E' : '#A3281C') + '" stroke="#26261F" stroke-width="1"/>';
    }
  };
  function bestiaryArt(slug, id, size) {
    var draw = ART[slug] || ART.observatoire;
    return '<svg viewBox="0 0 100 100" width="' + (size || 96) + '" height="' + (size || 96) + '" aria-hidden="true" focusable="false">' + draw(rng(hash(String(id)))) + '</svg>';
  }

  /* ---------- the story ---------- */

  /* text: {title, pages: [{title, text: [paragraphs]}], finale: {title, text}, lockedPage(n),
     lockedFinale}. Paragraphs are HTML written by the game; « {prenom} » becomes the player's first
     name. Page k of N opens at the lit count that is the same share of the units the player can be
     served - k itself when every unit is served. */
  function storyPages(text, progress) {
    var n = text.pages.length, total = progress.total || n, lit = progress.lit || 0;
    var out = text.pages.map(function (pg, i) {
      var need = Math.max(1, Math.ceil((i + 1) * total / n));
      return { n: i + 1, title: pg.title, text: pg.text, open: lit >= need, need: need };
    });
    if (text.finale) {
      // the final test played to its end, on a map complete (an older test played on a map that
      // since gained a unit does not count)
      var played = lit >= total && progress.final && progress.final.best !== null && progress.final.best !== undefined;
      out.push({ n: n + 1, title: text.finale.title, text: text.finale.text, open: played, finale: true });
    }
    return out;
  }
  function storyOpenCount(pages) { return pages.filter(function (p) { return p.open; }).length; }
  // the highest page open, which the game compares with the highest page read
  function storyLastOpen(pages) { var last = 0; pages.forEach(function (p) { if (p.open) last = p.n; }); return last; }

  function storyHtml(text, pages, current, firstName) {
    var pg = pages[Math.max(0, Math.min(pages.length, current) - 1)];
    var strip = pages.map(function (p) {
      return '<li><button type="button" class="sg-story__mark' + (p.open ? ' is-open' : '') + (p.finale ? ' is-finale' : '') + (p.n === pg.n ? ' is-current' : '') + '" data-act="story-page" data-arg="' + p.n + '"' +
        (p.n === pg.n ? ' aria-current="page"' : '') + ' aria-label="Page ' + p.n + (p.open ? ' : ' + esc(p.title) : ', encore blanche') + '">' + (p.finale ? '✦' : p.n) + '</button></li>';
    }).join('');
    var body;
    if (pg.open) {
      body = '<h3 class="sg-story__title">' + esc(pg.title) + '</h3>' + (pg.text || []).map(function (para) {
        return '<p>' + String(para).replace(/\{prenom\}/g, esc(firstName || '')) + '</p>';
      }).join('');
    } else {
      body = '<h3 class="sg-story__title is-blank">Page blanche</h3><p class="sg-story__locked">' +
        esc(pg.finale ? text.lockedFinale : text.lockedPage(pg.need)) + '</p>';
    }
    return '<div class="sg-story"><ol class="sg-story__strip" aria-label="Les pages">' + strip + '</ol>' +
      '<article class="sg-story__page' + (pg.open ? '' : ' is-blank') + (pg.finale ? ' is-finale' : '') + '" aria-live="polite"><span class="sg-story__num">' + (pg.finale ? 'Dernière page' : 'Page ' + pg.n + ' sur ' + (pages.length - (text.finale ? 1 : 0))) + '</span>' + body + '</article>' +
      '<div class="sg-story__nav"><button type="button" class="btn btn--small" data-act="story-page" data-arg="' + (pg.n - 1) + '"' + (pg.n <= 1 ? ' disabled' : '') + '>← Page précédente</button>' +
      '<span class="sg-story__count">' + plural(storyOpenCount(pages), 'page ouverte', 'pages ouvertes') + ' sur ' + pages.length + '</span>' +
      '<button type="button" class="btn btn--small" data-act="story-page" data-arg="' + (pg.n + 1) + '"' + (pg.n >= pages.length ? ' disabled' : '') + '>Page suivante →</button></div></div>';
  }

  root.SGEngage = {
    story: { pages: storyPages, html: storyHtml, openCount: storyOpenCount, lastOpen: storyLastOpen },
    STEPS: STEPS,
    nextStep: nextStep,
    gauge: gauge,
    streak: streak,
    closest: closest,
    sound: sound,
    burst: burst,
    bestiaryArt: bestiaryArt
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
