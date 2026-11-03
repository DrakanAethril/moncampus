/* Fusion — the simulators: the night of a cutover plan (FSSim.night) and the network arithmetic the
   configuration steps check with (FSNet).

   A plan is what the student builds in the plan editor:
     { window: 'sam', prep: ['bail'], night: [{ op: 'gel', check: 'ecrit', back: 'rouvrir' }, …], noReturn: 3 }
   `prep` runs days before the night (J-8) and costs no time in the window; `night` runs in order from
   the start of the window; `noReturn` is the index of the first night operation past the point of no
   return (night.length when the student placed none: every operation can still be undone).

   A plan step (FSSteps, plan: {...}) describes its catalogue and its world:
     aim: 'the objective, as the student reads it'
     services: { id: 'label as the requester says it' }   — what must work on Monday at 8 h
     init(st, flags)                                      — the world before the plan (st.svc[id] = true…)
     ops: [{ id, label, dur, run(st, ctx), undo: ['backId'] | safe: true, irreversible: true }]
          run reads ctx.phase ('prep' | 'night'), ctx.flags (the night's incident) and may break services
     checks: [{ id, label, sees: ['svc'] }]                — a check sees the broken services it covers
     backs: [{ id, label, dur, needs: ['opId'] }]          — a rollback, possible only after `needs`
     goal(st) → true | 'what is missing'                  — the migration went to the end
     windows: [{ id, label, start, end, problem }]        — minutes from Saturday 0 h; a problem refuses it
     repair: 45                                           — minutes to repair forward past the point

   A night (one case of the step: the visible one, then the hidden ones) runs the plan with the case's
   flags. The information system survives it (ok) when, at the end of the window, the migration is done
   and every service works — straight away (done) or after a check saw a breakage past the point of no
   return and it was repaired forward (repaired) — or when a check saw the incident before the point of
   no return and the rollback brought the world back (rolledback); always without data lost and without
   running past the window. Which of these a case accepts is runCases' rule (steps.js): the visible
   night and a foreseeable incident must end done, only an unavoidable one may be repaired or rolled
   back. */
(function (root) {
  'use strict';

  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  var DAYS = ['samedi', 'dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'];
  /** « samedi 21 h 30 » for a minute counted from Saturday 0 h (negative: the week before). */
  function when(min) {
    var d = Math.floor(min / 1440), m = ((min % 1440) + 1440) % 1440, hh = Math.floor(m / 60), mm = m % 60;
    return DAYS[((d % 7) + 7) % 7] + ' ' + hh + ' h' + (mm ? ' ' + (mm < 10 ? '0' : '') + mm : '');
  }
  function hour(min) { var m = ((min % 1440) + 1440) % 1440, hh = Math.floor(m / 60), mm = m % 60; return (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm; }

  function find(list, id) { for (var i = 0; i < (list || []).length; i++) if (list[i].id === id) return list[i]; return null; }

  /** Structural problems of a plan, before any night: unknown ids, an operation twice, no window. */
  function shape(def, plan) {
    var p = def.plan, seen = {}, problems = [];
    if (!plan || !find(p.windows, plan.window)) problems.push('aucune fenêtre de maintenance choisie');
    (plan && plan.prep || []).concat((plan && plan.night || []).map(function (r) { return r && r.op; })).forEach(function (id) {
      if (!find(p.ops, id)) problems.push('opération inconnue : ' + id);
      else if (seen[id]) problems.push('« ' + find(p.ops, id).label + ' » apparaît deux fois');
      seen[id] = 1;
    });
    (plan && plan.night || []).forEach(function (r) {
      if (r.check && !find(p.checks, r.check)) problems.push('contrôle inconnu : ' + r.check);
      if (r.back && !find(p.backs, r.back)) problems.push('retour arrière inconnu : ' + r.back);
    });
    return problems;
  }

  /** Plays one night. Returns { ok, outcome: 'done'|'repaired'|'rolledback'|'broken'|'stuck'|'overrun'|'refused'|'unfinished', detail, timeline: [{ t, text, kind }] }. */
  function night(def, plan, flags) {
    var p = def.plan, tl = [], st = { svc: {}, lost: [] }, repaired = [];
    flags = flags || {};
    var res = function (outcome, detail) { return { ok: outcome === 'done' || outcome === 'repaired' || outcome === 'rolledback', outcome: outcome, detail: detail || '', timeline: tl }; };
    var bad = shape(def, plan);
    if (bad.length) return res('refused', bad[0]);
    var win = find(p.windows, plan.window);
    if (win.problem) return res('refused', 'fenêtre refusée : ' + win.problem);
    Object.keys(p.services).forEach(function (s) { st.svc[s] = true; });
    if (p.init) p.init(st, flags);
    var done = {};
    (plan.prep || []).forEach(function (id) {
      var op = find(p.ops, id);
      op.run(st, { phase: 'prep', flags: flags, done: done });
      done[id] = 'prep';
      tl.push({ t: win.start - 8 * 1440, text: op.label, kind: 'prep' });
    });
    var before = clone(st), t = win.start, rows = plan.night || [], nr = plan.noReturn === undefined || plan.noReturn === null ? rows.length : plan.noReturn;
    tl.push({ t: t, text: 'Début de la fenêtre', kind: 'window' });
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i], op = find(p.ops, row.op);
      if (i === nr) tl.push({ t: t, text: 'Point de non-retour', kind: 'noreturn' });
      op.run(st, { phase: 'night', flags: flags, done: done, t: t });
      done[op.id] = 'night';
      t += op.dur;
      tl.push({ t: t, text: op.label, kind: 'op' });
      if (st.event) { tl.push({ t: t, text: st.event, kind: 'incident' }); st.event = null; }
      if (t > win.end) { tl.push({ t: t, text: 'La fenêtre est dépassée', kind: 'ko' }); return res('overrun', 'à ' + when(t) + ', « ' + op.label + ' » déborde de la fenêtre, qui se fermait ' + when(win.end)); }
      if (!row.check) continue;
      var check = find(p.checks, row.check);
      var broken = check.sees.filter(function (s) { return st.svc[s] === false; });
      tl.push({ t: t, text: 'Contrôle : ' + check.label + (broken.length ? ' — en échec' : ' — vert'), kind: broken.length ? 'detect' : 'check' });
      if (!broken.length) continue;
      var what = broken.map(function (s) { return p.services[s]; }).join(', ');
      if (i < nr) {
        // Before the point of no return: undo every operation done, the last first.
        for (var j = i; j >= 0; j--) {
          var r = rows[j], o = find(p.ops, r.op);
          if (o.safe) continue;
          if (o.irreversible) return res('stuck', 'à ' + when(t) + ', le contrôle voit : ' + what + '. « ' + o.label + ' » ne se défait pas : il devait venir après le point de non-retour');
          if (!r.back) return res('stuck', 'à ' + when(t) + ', le contrôle voit : ' + what + '. Retour arrière impossible : « ' + o.label + ' » n\'a pas de retour prévu');
          var b = find(p.backs, r.back);
          if ((o.undo || []).indexOf(b.id) === -1) return res('stuck', 'à ' + when(t) + ', le contrôle voit : ' + what + '. « ' + b.label + ' » ne défait pas « ' + o.label + ' »');
          var missing = (b.needs || []).filter(function (n) { return !done[n]; });
          if (missing.length) return res('stuck', 'à ' + when(t) + ', « ' + b.label + ' » demande « ' + find(p.ops, missing[0]).label + ' », jamais fait');
          t += b.dur || 15;
          tl.push({ t: t, text: 'Retour arrière : ' + b.label, kind: 'back' });
        }
        if (t > win.end) return res('overrun', 'le retour arrière se termine ' + when(t) + ', après la fin de la fenêtre (' + when(win.end) + ')');
        if (st.lost.length > before.lost.length) return res('broken', 'données perdues pendant la nuit : ' + st.lost.slice(before.lost.length).join(', '));
        st = clone(before);
        tl.push({ t: t, text: 'Le SI est revenu à son état de départ ; la bascule sera rejouée', kind: 'ok' });
        return res('rolledback', 'à ' + when(t) + ', retour à l\'état de départ après : ' + what);
      }
      var stuck = broken.filter(function (s) { return (flags.unrepairable || []).indexOf(s) !== -1; });
      if (stuck.length) return res('broken', 'à ' + when(t) + ', après le point de non-retour, le contrôle voit : ' + stuck.map(function (s) { return p.services[s]; }).join(', ') + '. Plus de retour possible, et cela ne se répare pas dans la nuit');
      t += p.repair || 45;
      broken.forEach(function (s) { st.svc[s] = true; });
      repaired.push('à ' + when(t) + ', ' + what);
      tl.push({ t: t, text: 'Réparé en avant : ' + what, kind: 'repair' });
      if (t > win.end) return res('overrun', 'la réparation de « ' + what + ' » se termine ' + when(t) + ', après la fin de la fenêtre');
    }
    tl.push({ t: win.end, text: 'Fin de la fenêtre', kind: 'window' });
    if (st.lost.length) return res('broken', 'données perdues : ' + st.lost.join(', '));
    var down = Object.keys(p.services).filter(function (s) { return st.svc[s] === false; });
    if (down.length) {
      tl.push({ t: win.end + 60, text: 'Constaté le matin : ' + down.map(function (s) { return p.services[s]; }).join(', '), kind: 'ko' });
      return res('broken', 'constaté le matin, à l\'ouverture : ' + down.map(function (s) { return p.services[s]; }).join(', ') + ' ; aucun contrôle ne l\'a vu pendant la nuit');
    }
    var g = p.goal ? p.goal(st, done) : true;
    if (g !== true) return res('unfinished', 'la migration ne va pas au bout : ' + (typeof g === 'string' ? g : 'il manque une opération'));
    tl.push({ t: win.end, text: 'Tout fonctionne à l\'ouverture', kind: 'ok' });
    return repaired.length ? res('repaired', 'réparé en avant ' + repaired.join(' ; ')) : res('done', '');
  }

  // ——— Network arithmetic, for the configuration steps ———
  function ip2n(s) {
    var m = /^\s*(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\s*$/.exec(String(s || ''));
    if (!m) return null;
    var p = [m[1], m[2], m[3], m[4]].map(Number);
    if (p.some(function (x) { return x > 255; })) return null;
    return ((p[0] * 256 + p[1]) * 256 + p[2]) * 256 + p[3];
  }
  function n2ip(n) { return [Math.floor(n / 16777216) % 256, Math.floor(n / 65536) % 256, Math.floor(n / 256) % 256, n % 256].join('.'); }
  /** « 10.20.20.0/24 » → { net, prefix, size, first, last, text } or null (a host address with a mask is refused: net must be aligned). */
  function cidr(s) {
    var m = /^\s*([\d.]+)\s*\/\s*(\d{1,2})\s*$/.exec(String(s || ''));
    if (!m) return null;
    var n = ip2n(m[1]), pre = Number(m[2]);
    if (n === null || pre > 32 || pre < 0) return null;
    var size = Math.pow(2, 32 - pre);
    if (n % size !== 0) return null;
    return { net: n, prefix: pre, size: size, first: n, last: n + size - 1, text: n2ip(n) + '/' + pre };
  }
  function overlap(a, b) { return a.first <= b.last && b.first <= a.last; }
  function contains(net, ip) { var n = typeof ip === 'number' ? ip : ip2n(ip); return n !== null && n >= net.first && n <= net.last; }
  /** Usable hosts of a network (the network and broadcast addresses excluded). */
  function hosts(net) { return net.prefix >= 31 ? net.size : net.size - 2; }
  function isPrivate(net) {
    return [cidr('10.0.0.0/8'), cidr('172.16.0.0/12'), cidr('192.168.0.0/16')].some(function (p) { return net.first >= p.first && net.last <= p.last; });
  }

  root.FSSim = { night: night, shape: shape, when: when, hour: hour, find: find };
  root.FSNet = { ip2n: ip2n, n2ip: n2ip, cidr: cidr, overlap: overlap, contains: contains, hosts: hosts, isPrivate: isPrivate };
})(typeof globalThis !== 'undefined' ? globalThis : window);
