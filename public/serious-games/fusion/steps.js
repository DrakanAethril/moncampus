/* Fusion — the steps, on the game's side.

   The platform half (title, request, the steps and their right answers, the graph, the clues) is in
   host/scenario/. Here, what the game shows and checks by itself, for the steps where the student
   builds something (a « fix » step) or reads a view (a « line » step):

     FSSteps.add({
       id: 'E08', act: 1,
       tool: 'plan' | 'form',                 // what the workstation's right side holds for the « fix » step
       plan: { … },                           // a cutover plan: see sim.js (services, ops, checks, backs, windows…)
       form: { intro, fields: [ … ] },        // a configuration: fields, see below
       start: { … }, ref: { … },              // the answer at the start, and the reference (only the bench reads it)
       cases: [{ id, label, visible, flags }] (plan: a night) or [{ id, label, visible, check(answer, H) }] (form)
       views: { id: { title, lines: [...] } },  // what a « line » step shows
       hints: [{ title, body }],              // Hélène's file cards, after her guided questions; the last gives the solution
       intro: [{ who, expr, text }], done: [{ who, expr, text }]      // scenes
     });

   Form fields: { id, label, type: 'text'|'select'|'table'|'checks'|'matrix', help, … }
     text:   { placeholder, mono }                       → 'value'
     select: { options: [[value, label]] }               → 'value'
     checks: { options: [[value, label]] }               → ['value', …]
     table:  { columns: [{ id, label, type, options, placeholder, mono }], rows: n, fixedRows: [labels] } → [{ col: value }]
     matrix: { rows: [[id, label]], cols: [[id, label]], values: [[value, label]] }   → { row: { col: value } }

   A delivery runs every case: a plan is played night by night (FSSim.night), a form is checked case by
   case. Cases with visible: true are what the requester checks; the others are « nuits » or « contrôles
   que vous ne voyez pas »: when one fails, its label and what went wrong are told. */
(function (root) {
  'use strict';
  var N = root.FSNet, SIM = root.FSSim;
  var list = [], byId = {};

  function clean(s) { return String(s == null ? '' : s).trim(); }
  var H = {
    net: N,
    clean: clean,
    low: function (s) { return clean(s).toLowerCase().replace(/\s+/g, ' '); },
    /** Non-empty rows of a table answer. */
    rows: function (a) { return (a || []).filter(function (r) { return r && Object.keys(r).some(function (k) { return clean(r[k]) !== ''; }); }); },
    has: function (arr, v) { return (arr || []).indexOf(v) !== -1; }
  };

  function normalize(def) {
    def.cases = def.cases || []; def.hints = def.hints || []; def.views = def.views || {};
    if (def.tool === 'plan') {
      def.start = def.start || { window: '', prep: [], night: [], noReturn: null };
      def.plan.checks = def.plan.checks || []; def.plan.backs = def.plan.backs || [];
    }
    if (def.tool === 'form') def.start = def.start || {};
    return def;
  }

  function copy(o) { return JSON.parse(JSON.stringify(o)); }

  /** Runs every case with the student's answer. Returns { passed, results: [{ id, label, visible, ok, detail, timeline }], failed: [labels] }. */
  function runCases(def, answer) {
    var results = def.cases.map(function (c) {
      var r = { id: c.id, label: c.label, visible: !!c.visible, ok: false, detail: '' };
      try {
        if (def.tool === 'plan') {
          var n = SIM.night(def, copy(answer), c.flags || {});
          r.ok = c.visible ? n.outcome === 'done' : n.ok;
          if (c.visible && n.outcome === 'rolledback') n.detail = 'sur la nuit visible, rien ne devait tomber, et le plan a reculé : ' + n.detail;
          r.detail = r.ok ? (n.outcome === 'rolledback' ? 'le contrôle a vu l\'incident et le retour arrière a tenu' : '') : n.detail;
          r.timeline = n.timeline; r.outcome = n.outcome;
        } else {
          var v = c.check(copy(answer), H);
          r.ok = v === true; if (v !== true) r.detail = typeof v === 'string' ? v : '';
        }
      } catch (e) { r.ok = false; r.detail = 'erreur du simulateur : ' + (e && e.message); }
      return r;
    });
    var failed = results.filter(function (r) { return !r.ok; });
    return { passed: !failed.length, results: results, failed: failed.map(function (r) { return r.label; }) };
  }

  /** The lines a « line » step shows. */
  function view(def, id) {
    var v = def.views[id];
    if (!v) return null;
    return { title: v.title, lines: v.lines.slice(), mono: v.mono !== false };
  }

  root.FSSteps = {
    helpers: H,
    add: function (def) { if (byId[def.id]) throw new Error('Étape en double : ' + def.id); normalize(def); list.push(def); byId[def.id] = def; },
    get: function (id) { return byId[id]; }, all: function () { return list; },
    runCases: runCases, view: view
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
