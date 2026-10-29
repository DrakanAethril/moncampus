/* Rétro Rush — the engine: sql.js databases, the student's scripts, and the checks of a delivery.

   RetroEngine.ready()                     → Promise, once sql.js is loaded (browser or Node)
   RetroEngine.open(act, which, setup)     → a Session on a fresh base: 'visible' or 'hidden', with the
                                              ticket's setup (its bug) applied
   session.run(text)                       → { steps: [{ sql, kind, columns, rows, changes, error }], error }
   session.tables() / triggers() / views() / columns(t) / plan(sql) / count(t)
   RetroEngine.check(ticket, answers)      → [{ deliver, label, ok, detail }] - what the requester checks

   A script runs as `mysql < script.sql` would: statement after statement, stopping at the first
   error; a transaction left open (no COMMIT, or an error inside it) is rolled back, as MySQL does at
   disconnection. Every check starts from a fresh base, visible and hidden: nothing the student
   tried in the sandbox counts. */
(function (root) {
  'use strict';
  var SQLC = root.RetroSQL, BASE = root.RetroBase;
  var SQL = null, loading = null;

  function ready(locate) {
    if (SQL) return Promise.resolve(SQL);
    if (loading) return loading;
    var init = root.initSqlJs || (typeof require === 'function' ? require('./vendor/sql-wasm.js') : null);
    var where = locate || (typeof __dirname !== 'undefined' && typeof window === 'undefined' ? function (f) { return __dirname + '/vendor/' + f; } : function (f) { return 'vendor/' + f; });
    loading = init({ locateFile: where }).then(function (s) { SQL = s; return s; });
    return loading;
  }

  // ——— MySQL's functions, MySQL's way ———
  function day(d) { if (d === null || d === undefined) return null; var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d)); return m ? m : null; }
  function functions(db, today) {
    db.create_function('YEAR', function (d) { var m = day(d); return m ? +m[1] : null; });
    db.create_function('MONTH', function (d) { var m = day(d); return m ? +m[2] : null; });
    db.create_function('DAY', function (d) { var m = day(d); return m ? +m[3] : null; });
    db.create_function('DAYOFMONTH', function (d) { var m = day(d); return m ? +m[3] : null; });
    db.create_function('DATEDIFF', function (a, b) {
      var x = day(a), y = day(b);
      if (!x || !y) return null;
      return Math.round((Date.UTC(+x[1], +x[2] - 1, +x[3]) - Date.UTC(+y[1], +y[2] - 1, +y[3])) / 86400000);
    });
    db.create_function('CURDATE', function () { return today; });
    db.create_function('NOW', function () { return today + ' 10:00:00'; });
    db.create_function('LCASE', function (s) { return s === null ? null : String(s).toLowerCase(); });
    db.create_function('UCASE', function (s) { return s === null ? null : String(s).toUpperCase(); });
    db.create_function('LEFT', function (s, n) { return s === null ? null : String(s).slice(0, n); });
    db.create_function('RIGHT', function (s, n) { return s === null ? null : (n > 0 ? String(s).slice(-n) : ''); });
  }

  function Session(db, act) { this.db = db; this.act = act; this.inTx = false; }
  Session.prototype.query = function (sql) {
    var r = this.db.exec(sql);
    return r.length ? { columns: r[r.length - 1].columns, rows: r[r.length - 1].values } : { columns: [], rows: [] };
  };
  Session.prototype.tables = function () {
    return this.query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE '\\_%' ESCAPE '\\' AND name NOT LIKE 'sqlite%' ORDER BY name").rows.map(function (r) { return r[0]; });
  };
  Session.prototype.views = function () {
    return this.query("SELECT name, sql FROM sqlite_master WHERE type = 'view' ORDER BY name").rows.map(function (r) { return { name: r[0], sql: r[1] }; });
  };
  Session.prototype.indexes = function () {
    return this.query("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL ORDER BY name").rows.map(function (r) { return { name: r[0], table: r[1], sql: r[2] }; });
  };
  Session.prototype.triggers = function () {
    var src = {};
    try { this.query('SELECT name, source FROM _rr_triggers').rows.forEach(function (r) { src[r[0]] = r[1]; }); } catch (e) { /* none yet */ }
    return this.query("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'trigger' AND name NOT LIKE '%\\_\\_rr\\_after' ESCAPE '\\' ORDER BY name").rows.map(function (r) {
      return { name: r[0], table: r[1], source: src[r[0]] || r[2] };
    });
  };
  Session.prototype.columns = function (table) {
    var fks = {};
    try { this.query("SELECT \"from\", \"table\", \"to\" FROM pragma_foreign_key_list('" + table + "')").rows.forEach(function (r) { fks[r[0]] = r[1] + '(' + r[2] + ')'; }); } catch (e) { /* no table */ }
    return this.query("SELECT name, type, \"notnull\", pk, dflt_value FROM pragma_table_info('" + table + "')").rows.map(function (r) {
      return { name: r[0], type: r[1], notNull: !!r[2], pk: r[3] > 0, fk: fks[r[0]] || null, dflt: r[4] };
    });
  };
  Session.prototype.count = function (table) { try { return this.query('SELECT COUNT(*) FROM "' + table + '"').rows[0][0]; } catch (e) { return null; } };
  Session.prototype.schemaInfo = function () {
    var self = this;
    return {
      columns: function (t) { return self.columns(t).map(function (c) { return c.name; }); },
      pk: function (t) { return self.columns(t).filter(function (c) { return c.pk; }).map(function (c) { return c.name; }); }
    };
  };
  Session.prototype.plan = function (sql) {
    var tr = SQLC.translate(sql);
    if (tr.kind !== 'select') throw SQLC.Refused('Le plan d\'exécution se lit sur un SELECT.');
    return this.query('EXPLAIN QUERY PLAN ' + tr.sqlite[0]).rows.map(function (r) { return r[3]; });
  };

  // One statement of the student's (or of the base): translated, checked, run.
  Session.prototype.statement = function (sql) {
    var tr = SQLC.translate(sql);
    var step = { sql: sql, kind: tr.kind, columns: null, rows: null, changes: 0, error: null };
    if (tr.kind === 'select' || tr.kind === 'modify' || /^\s*CREATE\s+VIEW\b/i.test(sql)) {
      var gb = SQLC.groupByProblem(tr.sqlite[0], this.schemaInfo());
      if (gb) throw SQLC.Refused(gb);
    }
    if (tr.rebuild) { this.rebuild(tr.rebuild.table, tr.rebuild.constraint); return step; }
    var self = this;
    tr.sqlite.forEach(function (s, i) {
      var res = self.db.exec(s);
      if (tr.kind === 'select' && i === tr.sqlite.length - 1) {
        var last = res[res.length - 1];
        step.columns = last ? last.columns : [];
        step.rows = last ? last.values : [];
      }
      if (tr.kind === 'modify') step.changes += self.db.getRowsModified();
    });
    if (tr.kind === 'tx') this.inTx = /BEGIN/.test(tr.sqlite[0]);
    if (tr.trigger) this.db.run('INSERT OR REPLACE INTO _rr_triggers (name, source) VALUES (?, ?)', [tr.trigger.name, sql.replace(/;\s*$/, '')]);
    var dropped = /^\s*DROP\s+TRIGGER\s+(IF\s+EXISTS\s+)?`?(\w+)`?/i.exec(sql);
    if (dropped) this.db.run('DELETE FROM _rr_triggers WHERE name = ?', [dropped[2]]);
    return step;
  };
  // ALTER TABLE … ADD CONSTRAINT: SQLite rebuilds the table with the constraint, and refuses if a
  // row already breaks it - as MySQL does.
  Session.prototype.rebuild = function (table, constraint) {
    if (this.inTx) throw SQLC.Refused('Ajoute la contrainte hors d\'une transaction : MySQL valide de toute façon la transaction en cours avant un ALTER TABLE.');
    var t = this.query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '" + table.replace(/'/g, "''") + "'").rows[0];
    if (!t) throw SQLC.Refused('La table ' + table + ' n\'existe pas.');
    var create = t[0], close = create.lastIndexOf(')');
    var others = this.query("SELECT sql FROM sqlite_master WHERE tbl_name = '" + table + "' AND type IN ('index', 'trigger') AND sql IS NOT NULL").rows.map(function (r) { return r[0]; });
    var next = create.slice(0, close).replace(/^(\s*CREATE\s+TABLE\s+)("?\w+"?|`\w+`)/i, '$1_rr_rebuild') + ',\n  ' + constraint + '\n)';
    this.db.run('PRAGMA foreign_keys = OFF');
    // The other tables' triggers name this table: without the legacy mode, the rename would check
    // them while the table is gone.
    this.db.run('PRAGMA legacy_alter_table = ON');
    try {
      this.db.run('SAVEPOINT rr_rebuild');
      try {
        this.db.run(next);
        this.db.run('INSERT INTO _rr_rebuild SELECT * FROM "' + table + '"');
        this.db.run('DROP TABLE "' + table + '"');
        this.db.run('ALTER TABLE _rr_rebuild RENAME TO "' + table + '"');
        var self = this;
        others.forEach(function (s) { self.db.run(s); });
        this.db.run('RELEASE rr_rebuild');
      } catch (e) {
        this.db.run('ROLLBACK TO rr_rebuild'); this.db.run('RELEASE rr_rebuild');
        var msg = SQLC.frenchError(e.message);
        throw SQLC.Refused(/CHECK|UNIQUE|FOREIGN|NOT NULL/.test(e.message) ? msg + ' Des lignes déjà dans la table ne respectent pas la contrainte : corrige-les d\'abord.' : msg);
      }
    } finally { this.db.run('PRAGMA legacy_alter_table = OFF'); this.db.run('PRAGMA foreign_keys = ON'); }
  };
  Session.prototype.run = function (text) {
    var out = { steps: [], error: null, rolledBack: false };
    var stmts;
    try { stmts = SQLC.split(text); } catch (e) { out.error = { line: 1, message: SQLC.frenchError(e.message) }; return out; }
    for (var i = 0; i < stmts.length; i++) {
      try { out.steps.push(this.statement(stmts[i].sql)); } catch (e) {
        var message = e.refused ? e.message : SQLC.frenchError(e.message);
        out.steps.push({ sql: stmts[i].sql, kind: 'error', error: message });
        out.error = { line: stmts[i].line, message: message, sql: stmts[i].sql };
        break;
      }
    }
    if (this.inTx) {
      try { this.db.run('ROLLBACK'); } catch (e) { /* already closed by the error */ }
      this.inTx = false;
      out.rolledBack = true;
    }
    return out;
  };

  function open(act, which, setup) {
    if (!SQL) throw new Error('sql.js n\'est pas chargé.');
    var db = new SQL.Database();
    db.run('PRAGMA foreign_keys = ON');
    functions(db, BASE.today(act));
    db.run('CREATE TABLE _rr_vars (k TEXT PRIMARY KEY, v)');
    db.run('CREATE TABLE _rr_triggers (name TEXT PRIMARY KEY, source TEXT)');
    var s = new Session(db, act);
    var base = s.run(BASE.script(act, which || 'visible'));
    if (base.error) throw new Error('Base de l\'acte ' + act + ' : ' + base.error.message + ' (' + (base.error.sql || '').slice(0, 80) + ')');
    if (setup) {
      var st = s.run(setup);
      if (st.error) throw new Error('Mise en place du ticket : ' + st.error.message + ' (' + (st.error.sql || '').slice(0, 80) + ')');
    }
    return s;
  }

  // ——— Comparing results ———
  function norm(v) {
    if (v === null || v === undefined) return null;
    if (typeof v === 'number') return Math.round(v * 100) / 100;
    if (typeof v === 'string' && /^-?\d+(\.\d+)?$/.test(v)) return Math.round(parseFloat(v) * 100) / 100;
    return String(v);
  }
  function rowKey(r) { return JSON.stringify(r.map(norm)); }
  function show(r) { return r.map(function (v) { return v === null ? 'NULL' : (typeof v === 'number' ? String(norm(v)) : String(v)); }).join(' · '); }
  function multiset(rows) { var m = {}; rows.forEach(function (r) { var k = rowKey(r); m[k] = (m[k] || 0) + 1; }); return m; }
  function diff(expected, got) {
    var me = multiset(expected), mg = multiset(got), missing = [], extra = [];
    expected.forEach(function (r) { var k = rowKey(r); if ((mg[k] || 0) < me[k]) { missing.push(r); mg[k] = (mg[k] || 0) + 1; } });
    var me2 = multiset(expected);
    got.forEach(function (r) { var k = rowKey(r); if ((me2[k] || 0) > 0) me2[k]--; else extra.push(r); });
    return { missing: missing, extra: extra };
  }
  function lastSelect(report) {
    for (var i = report.steps.length - 1; i >= 0; i--) if (report.steps[i].kind === 'select') return report.steps[i];
    return null;
  }
  function list(rows, n) { return rows.slice(0, n || 3).map(function (r) { return '« ' + show(r) + ' »'; }).join(', ') + (rows.length > (n || 3) ? ' et ' + (rows.length - (n || 3)) + ' autre' + (rows.length - (n || 3) > 1 ? 's' : '') : ''); }

  // The table contents a modification is judged on, keyed by rowid.
  function snapshot(s, tables) {
    var out = {};
    tables.forEach(function (t) {
      var q = s.query('SELECT rowid AS _rr_rowid, * FROM "' + t + '"'), m = {};
      q.rows.forEach(function (r) { m[r[0]] = r.slice(1); });
      out[t] = { columns: q.columns.slice(1), rows: m };
    });
    return out;
  }
  // What differs between the expected and the obtained version of a row, column by column.
  function cells(columns, r, g) {
    var out = [];
    columns.forEach(function (c, i) {
      if (JSON.stringify(norm(r[i])) !== JSON.stringify(norm(g[i]))) out.push(c + ' attendu ' + show([r[i]]) + ', obtenu ' + show([g[i]]));
    });
    return out.slice(0, 2).join(' ; ');
  }
  function compareStates(before, ref, got, tables) {
    var wrongChange = [], unwanted = [];
    tables.forEach(function (t) {
      var keys = {}, columns = (ref[t] || got[t]).columns;
      [before[t], ref[t], got[t]].forEach(function (x) { Object.keys(x.rows).forEach(function (k) { keys[k] = true; }); });
      Object.keys(keys).forEach(function (k) {
        var b = before[t].rows[k], r = ref[t].rows[k], g = got[t].rows[k];
        var same = function (x, y) { return (x ? rowKey(x) : '∅') === (y ? rowKey(y) : '∅'); };
        if (same(r, g)) return;
        var label = t + ' ' + (r || g || b)[0];
        if (same(b, r)) unwanted.push(label + ' ' + (g ? (b ? 'modifiée (' + cells(columns, b, g) + ')' : 'ajoutée') : 'supprimée') + ' alors qu\'elle ne devait pas bouger');
        else wrongChange.push(label + ' : ' + (!g ? (b ? 'supprimée à tort' : 'manquante') : (!r ? 'ajoutée en trop' : cells(columns, r, g))));
      });
    });
    return { wrong: wrongChange, unwanted: unwanted };
  }

  // ——— The checks of a delivery ———
  /* A deliverable of the game's half of a ticket:
       { id, label, kind: 'select', ref, order: false, say: { columns, missing, extra, order, hidden } }
       { id, label, kind: 'modify', ref, tables: ['Article'], say: { changed, untouched, hidden } }
       { id, label, kind: 'trigger', ref, trial: [{ label, sql, refused: bool }], tables: [...] }
       { id, label, kind: 'script', checks: [{ label, test: function (h) { return bool | { ok, detail } } }] }
     `ref` is the reference answer, which the checks compare with; `say` the requester's words. */
  var SAY = {
    columns: 'Les colonnes demandées, dans l\'ordre',
    missing: 'Il ne manque aucune ligne',
    extra: 'Aucune ligne en trop',
    order: 'Dans l\'ordre demandé',
    hidden: 'Ça marche aussi sur le stock complet, pas seulement sur les lignes à l\'écran',
    changed: 'Les bonnes lignes ont changé, comme demandé',
    untouched: 'Rien d\'autre n\'a bougé',
    script: 'Le script passe sans erreur',
    tx: 'Tout passe, ou rien : une seule transaction'
  };
  function said(d, k) { return (d.say && d.say[k]) || SAY[k]; }
  function failAll(labels, detail) { return labels.map(function (l) { return { label: l, ok: false, detail: detail }; }); }

  function checkSelect(t, d, text) {
    var labels = [said(d, 'columns'), said(d, 'missing'), said(d, 'extra')].concat(d.order ? [said(d, 'order')] : []).concat([said(d, 'hidden')]);
    var out = [];
    function one(which) {
      var s = open(t.act, which, t.setup), r = open(t.act, which, t.setup);
      var mine = s.run(text), ref = r.run(d.ref);
      if (ref.error) throw new Error(t.id + '/' + d.id + ' : la référence échoue (' + which + ') : ' + ref.error.message);
      var rs = lastSelect(ref);
      if (mine.error) return { error: 'Ligne ' + mine.error.line + ' : ' + mine.error.message };
      var ms = lastSelect(mine);
      if (!ms) return { error: 'Il faut une requête SELECT.' };
      return { mine: ms, ref: rs };
    }
    var v;
    try { v = one('visible'); } catch (e) { return failAll(labels, e.message); }
    if (v.error) return failAll(labels, v.error);
    var cols = v.mine.columns.length === v.ref.columns.length;
    out.push({ label: labels[0], ok: cols, detail: cols ? '' : v.mine.columns.length + ' colonne' + (v.mine.columns.length > 1 ? 's' : '') + ' au lieu de ' + v.ref.columns.length + (d.columnsHint ? ' : ' + d.columnsHint : '') });
    if (!cols) return out.concat(failAll(labels.slice(1), 'à revoir une fois les colonnes justes'));
    var df = diff(v.ref.rows, v.mine.rows);
    out.push({ label: labels[1], ok: !df.missing.length, detail: df.missing.length ? df.missing.length + ' ligne' + (df.missing.length > 1 ? 's' : '') + ' manquante' + (df.missing.length > 1 ? 's' : '') + ', par exemple ' + list(df.missing, 2) : '' });
    var dups = df.extra.filter(function (r) { return multiset(v.ref.rows)[rowKey(r)]; });
    out.push({ label: labels[2], ok: !df.extra.length, detail: df.extra.length ? (dups.length ? 'des lignes en double, par exemple ' + list(dups, 1) : df.extra.length + ' ligne' + (df.extra.length > 1 ? 's' : '') + ' en trop, par exemple ' + list(df.extra, 2)) : '' });
    if (d.order) {
      var same = !df.missing.length && !df.extra.length && v.ref.rows.every(function (r, i) { return rowKey(r) === rowKey(v.mine.rows[i]); });
      out.push({ label: labels[3], ok: same, detail: same ? '' : (df.missing.length || df.extra.length ? 'à revoir une fois les lignes justes' : 'les lignes y sont, mais pas dans l\'ordre demandé') });
    }
    var h;
    try { h = one('hidden'); } catch (e) { h = { error: e.message }; }
    var hok = !h.error && h.mine.columns.length === h.ref.columns.length && (function () { var x = diff(h.ref.rows, h.mine.rows); return !x.missing.length && !x.extra.length; })() &&
      (!d.order || h.ref.rows.every(function (r, i) { return rowKey(r) === rowKey(h.mine.rows[i]); }));
    var visibleOk = out.every(function (x) { return x.ok; });
    out.push({ label: labels[labels.length - 1], ok: hok, detail: hok ? '' : (h.error ? h.error : (visibleOk ? 'la requête colle aux lignes affichées mais se trompe sur le reste du stock : évite les identifiants écrits en dur' : 'à revoir une fois le reste juste')) });
    return out;
  }

  function checkModify(t, d, text) {
    var labels = [said(d, 'changed'), said(d, 'untouched'), said(d, 'hidden')];
    function one(which) {
      var s = open(t.act, which, t.setup), r = open(t.act, which, t.setup);
      var before = snapshot(s, d.tables);
      var ref = r.run(d.ref);
      if (ref.error) throw new Error(t.id + '/' + d.id + ' : la référence échoue (' + which + ') : ' + ref.error.message);
      var mine = s.run(text);
      if (mine.error) return { error: 'Ligne ' + mine.error.line + ' : ' + mine.error.message + (mine.rolledBack ? ' (la transaction a été annulée)' : '') };
      if (mine.rolledBack && !ref.rolledBack) return { error: 'La transaction n\'a jamais été validée (pas de COMMIT) : MySQL l\'annule à la déconnexion, rien n\'a été enregistré.' };
      return compareStates(before, snapshot(r, d.tables), snapshot(s, d.tables), d.tables);
    }
    var v;
    try { v = one('visible'); } catch (e) { return failAll((d.requireTx ? [said(d, 'tx')] : []).concat(labels), e.message); }
    if (v.error) return failAll((d.requireTx ? [said(d, 'tx')] : []).concat(labels), v.error);
    var out = [];
    if (d.requireTx) {
      var tx = /\b(START\s+TRANSACTION|BEGIN)\b/i.test(text) && /\bCOMMIT\b/i.test(text);
      out.push({ label: said(d, 'tx'), ok: tx, detail: tx ? '' : 'il faut START TRANSACTION au début et COMMIT à la fin' });
    }
    out = out.concat([
      { label: labels[0], ok: !v.wrong.length, detail: v.wrong.length ? v.wrong.length + ' ligne' + (v.wrong.length > 1 ? 's' : '') + ' pas comme attendu, par exemple ' + v.wrong[0] : '' },
      { label: labels[1], ok: !v.unwanted.length, detail: v.unwanted.length ? v.unwanted.length + ' ligne' + (v.unwanted.length > 1 ? 's' : '') + ' touchée' + (v.unwanted.length > 1 ? 's' : '') + ' à tort, par exemple ' + v.unwanted[0] : '' }
    ]);
    var h;
    try { h = one('hidden'); } catch (e) { h = { error: e.message }; }
    var hok = !h.error && !h.wrong.length && !h.unwanted.length;
    out.push({ label: labels[2], ok: hok, detail: hok ? '' : (h.error || ((out[0].ok && out[1].ok) ? 'le script colle aux lignes affichées mais se trompe sur le reste du stock : évite les identifiants écrits en dur' : 'à revoir une fois le reste juste')) });
    return out;
  }

  /* Each trial runs on its own fresh base, the student's script then the trial's statements, against
     the same with the reference: one failed trial does not fail the next. A trial that needs a
     history (reserve, then cancel) carries it in its own `sql`. */
  function checkTrigger(t, d, text) {
    var labels = [said(d, 'script')].concat(d.trial.map(function (x) { return x.label; }));
    var probe = open(t.act, 'visible', t.setup).run(text);
    if (probe.error) return failAll(labels, 'Ligne ' + probe.error.line + ' : ' + probe.error.message);
    var out = [{ label: labels[0], ok: true, detail: '' }];
    d.trial.forEach(function (step) {
      var r = open(t.act, 'visible', t.setup), s = open(t.act, 'visible', t.setup);
      var ref = r.run(d.ref);
      if (ref.error) throw new Error(t.id + '/' + d.id + ' : la référence échoue : ' + ref.error.message);
      s.run(text);
      var before = snapshot(r, d.tables);
      var a = r.run(step.sql), b = s.run(step.sql);
      var refRefused = !!a.error, mineRefused = !!b.error;
      if (refRefused !== !!step.refused) throw new Error(t.id + '/' + d.id + ' : l\'essai « ' + step.label + ' » ne se passe pas comme prévu avec la référence (' + (a.error ? a.error.message : 'accepté') + ').');
      var st = compareStates(before, snapshot(r, d.tables), snapshot(s, d.tables), d.tables);
      var stateOk = !st.wrong.length && !st.unwanted.length;
      var detail = '';
      if (refRefused && !mineRefused) detail = 'l\'opération est passée alors qu\'elle devait être refusée';
      else if (!refRefused && mineRefused) detail = 'refusé à tort : ' + b.error.message;
      else if (!stateOk) detail = st.wrong.concat(st.unwanted)[0];
      out.push({ label: step.label, ok: refRefused === mineRefused && stateOk, detail: detail });
    });
    return out;
  }

  function checkScript(t, d, text) {
    var labels = [said(d, 'script')].concat(d.checks.map(function (c) { return c.label; }));
    var s = open(t.act, 'visible', t.setup);
    var mine = s.run(text);
    if (mine.error) return failAll(labels, 'Ligne ' + mine.error.line + ' : ' + mine.error.message);
    var h = helpers(s, t);
    return [{ label: labels[0], ok: true, detail: '' }].concat(d.checks.map(function (c) {
      var r;
      try { r = c.test(h, mine); } catch (e) { r = { ok: false, detail: e.refused ? e.message : SQLC.frenchError(e.message) }; }
      if (typeof r === 'boolean') r = { ok: r };
      return { label: c.label, ok: !!r.ok, detail: r.ok ? '' : (r.detail || '') };
    }));
  }
  // What a check of a script may ask the base it produced.
  function helpers(s, t) {
    return {
      session: s,
      columns: function (tb) { return s.columns(tb); },
      column: function (tb, c) { return s.columns(tb).filter(function (x) { return x.name.toLowerCase() === c.toLowerCase(); })[0] || null; },
      hasTable: function (tb) { return s.tables().map(function (x) { return x.toLowerCase(); }).indexOf(tb.toLowerCase()) !== -1; },
      hasView: function (v) { return s.views().some(function (x) { return x.name.toLowerCase() === v.toLowerCase(); }); },
      triggers: function () { return s.triggers(); },
      rows: function (sql) { return s.query(SQLC.translate(sql).sqlite[0]).rows; },
      tryRun: function (sql) {
        s.db.run('SAVEPOINT rr_try');
        var r = s.run(sql);
        s.db.run('ROLLBACK TO rr_try'); s.db.run('RELEASE rr_try');
        return r.error ? { ok: false, error: r.error.message } : { ok: true, report: r };
      },
      plan: function (sql) { return s.plan(sql).join(' / '); },
      sameAs: function (sql, refSql) {
        var a = s.query(SQLC.translate(sql).sqlite[0]).rows, b = s.query(SQLC.translate(refSql).sqlite[0]).rows, x = diff(b, a);
        return { ok: !x.missing.length && !x.extra.length, detail: x.missing.length ? 'il manque ' + list(x.missing, 2) : (x.extra.length ? 'en trop : ' + list(x.extra, 2) : '') };
      },
      ticket: t
    };
  }

  function check(t, answers) {
    var out = [];
    (t.deliver || []).forEach(function (d) {
      var text = String(answers[d.id] || '').trim(), res;
      if (!text) res = failAll(labelsOf(d), 'rien n\'a été écrit pour « ' + d.label + ' »');
      else if (d.kind === 'select') res = checkSelect(t, d, text);
      else if (d.kind === 'modify') res = checkModify(t, d, text);
      else if (d.kind === 'trigger') res = checkTrigger(t, d, text);
      else res = checkScript(t, d, text);
      res.forEach(function (x) { x.deliver = d.id; out.push(x); });
    });
    return out;
  }
  function labelsOf(d) {
    if (d.kind === 'select') return [said(d, 'columns'), said(d, 'missing'), said(d, 'extra')].concat(d.order ? [said(d, 'order')] : []).concat([said(d, 'hidden')]);
    if (d.kind === 'modify') return (d.requireTx ? [said(d, 'tx')] : []).concat([said(d, 'changed'), said(d, 'untouched'), said(d, 'hidden')]);
    if (d.kind === 'trigger') return [said(d, 'script')].concat(d.trial.map(function (x) { return x.label; }));
    return [said(d, 'script')].concat((d.checks || []).map(function (c) { return c.label; }));
  }

  root.RetroEngine = { ready: ready, open: open, check: check, labels: labelsOf, show: show, norm: norm };
})(typeof globalThis !== 'undefined' ? globalThis : window);
