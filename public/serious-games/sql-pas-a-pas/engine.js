/* SQL pas à pas — the engine: sql.js bases, the student's queries, and the checks of a step.

   SPPEngine.ready(locate)        → Promise, once sql.js is loaded (browser or Node)
   SPPEngine.open(which)          → a Session on a fresh base, 'visible' or 'hidden'
   session.run(text)              → { steps: [{ sql, kind, columns, rows, changes }], error }
   SPPEngine.check(def, input)    → { passed, failed, criteria, shown } - what a step checks
   SPPEngine.result(text)         → the last result of a query on a fresh visible base, for the console

   Every run starts from a fresh base: what the student tried before counts for nothing, and nothing
   they type can break the base of the next query. Only the labels of the failed criteria leave the
   game ({passed, failed}); the query itself never does.

   The game speaks MySQL 8 over SQLite. Where SQLite would answer differently from MySQL on what the
   course teaches, the engine makes it answer like MySQL: `/` is a real division, UPPER/LOWER know the
   accents, LENGTH counts bytes and CHAR_LENGTH characters, an UPDATE or a DELETE without WHERE is
   stopped before it runs, a column neither grouped nor aggregated is refused as ONLY_FULL_GROUP_BY
   refuses it, and the errors carry MySQL's code and message before their French explanation. */
(function (root) {
  'use strict';
  var SQLC = root.PapSQL, BASE = root.SPPBase;
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
  function day(d) { if (d === null || d === undefined) return null; var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d)); return m || null; }
  function bytes(s) { var n = 0; for (var i = 0; i < s.length; i++) { var c = s.charCodeAt(i); n += c < 0x80 ? 1 : c < 0x800 ? 2 : (c >= 0xD800 && c <= 0xDBFF) ? (i++, 4) : 3; } return n; }
  function functions(db) {
    var today = BASE.TODAY;
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
    db.create_function('UPPER', function (s) { return s === null ? null : String(s).toUpperCase(); });
    db.create_function('LOWER', function (s) { return s === null ? null : String(s).toLowerCase(); });
    db.create_function('UCASE', function (s) { return s === null ? null : String(s).toUpperCase(); });
    db.create_function('LCASE', function (s) { return s === null ? null : String(s).toLowerCase(); });
    db.create_function('LENGTH', function (s) { return s === null ? null : bytes(String(s)); });
    db.create_function('CHAR_LENGTH', function (s) { return s === null ? null : Array.from(String(s)).length; });
    db.create_function('LEFT', function (s, n) { return s === null ? null : Array.from(String(s)).slice(0, n).join(''); });
    db.create_function('RIGHT', function (s, n) { return s === null ? null : (n > 0 ? Array.from(String(s)).slice(-n).join('') : ''); });
  }

  // MySQL's `/` is a real division; SQLite's divides integers as integers. Every `/` operator becomes
  // `* 1.0 /`, which gives MySQL's value without changing the precedence.
  function realDivision(sql) {
    var toks = SQLC.tokenize(sql), out = '', last = 0;
    toks.forEach(function (t) { if (t.t === 'p' && t.v === '/') { out += sql.slice(last, t.s) + '* 1.0 /'; last = t.e; } });
    return out + sql.slice(last);
  }

  function Session(db) { this.db = db; this.inTx = false; }
  Session.prototype.query = function (sql) {
    var r = this.db.exec(sql);
    return r.length ? { columns: r[r.length - 1].columns, rows: r[r.length - 1].values } : { columns: [], rows: [] };
  };
  Session.prototype.tables = function () {
    return this.query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE '\\_%' ESCAPE '\\' AND name NOT LIKE 'sqlite%' ORDER BY name").rows.map(function (r) { return r[0]; });
  };
  Session.prototype.columns = function (table) {
    var fks = {};
    try { this.query("SELECT \"from\", \"table\", \"to\" FROM pragma_foreign_key_list('" + table.replace(/'/g, "''") + "')").rows.forEach(function (r) { fks[String(r[0]).toLowerCase()] = { table: String(r[1]).toLowerCase(), column: r[2] === null ? null : String(r[2]).toLowerCase() }; }); } catch (e) { /* no table */ }
    var uniques = {};
    try {
      this.query("SELECT name, \"unique\" FROM pragma_index_list('" + table.replace(/'/g, "''") + "')").rows.forEach(function (ix) {
        if (!ix[1]) return;
        var cols = this.query("SELECT name FROM pragma_index_info('" + String(ix[0]).replace(/'/g, "''") + "')").rows;
        if (cols.length === 1) uniques[String(cols[0][0]).toLowerCase()] = true;
      }, this);
    } catch (e) { /* no index */ }
    return this.query("SELECT name, type, \"notnull\", pk, dflt_value FROM pragma_table_info('" + table.replace(/'/g, "''") + "')").rows.map(function (r) {
      var name = String(r[0]).toLowerCase();
      return { name: name, type: String(r[1] || ''), notNull: !!r[2] || r[3] > 0, pk: r[3] > 0, fk: fks[name] || null, unique: !!uniques[name] || r[3] > 0, dflt: r[4] };
    });
  };
  Session.prototype.schemaInfo = function () {
    var self = this;
    return {
      columns: function (t) { return self.columns(t).map(function (c) { return c.name; }); },
      pk: function (t) { return self.columns(t).filter(function (c) { return c.pk; }).map(function (c) { return c.name; }); }
    };
  };
  Session.prototype.statement = function (sql) {
    var up = sql.replace(/^\s+/, '').toUpperCase();
    if (/^(UPDATE|DELETE)\b/.test(up) && !/\bWHERE\b/.test(up)) {
      throw SQLC.Refused(/^UPDATE/.test(up)
        ? 'Arrêté avant l\'exécution : un UPDATE sans WHERE modifie toutes les lignes de la table. Ajoute la condition qui désigne les lignes à changer.'
        : 'Arrêté avant l\'exécution : un DELETE sans WHERE efface toutes les lignes de la table. Ajoute la condition qui désigne les lignes à supprimer.');
    }
    var tr = SQLC.translate(sql);
    var step = { sql: sql, kind: tr.kind, columns: null, rows: null, changes: 0 };
    if (tr.kind === 'select') aliasInWhere(sql, this);
    if (tr.kind === 'select' || tr.kind === 'modify') {
      var gb = SQLC.groupByProblem(tr.sqlite[0], this.schemaInfo());
      if (gb) { var e = SQLC.Refused(gb); e.code = 'ERROR 1055 (42000): Expression of SELECT list is not in GROUP BY clause and contains nonaggregated column; this is incompatible with sql_mode=only_full_group_by'; throw e; }
    }
    if (tr.rebuild) { this.rebuild(tr.rebuild.table, tr.rebuild.constraint); return step; }
    var self = this;
    tr.sqlite.forEach(function (s, i) {
      var res = self.db.exec(tr.kind === 'select' || tr.kind === 'modify' ? realDivision(s) : s);
      if (tr.kind === 'select' && i === tr.sqlite.length - 1) {
        var last = res[res.length - 1];
        step.columns = last ? last.columns : [];
        step.rows = last ? last.values : [];
      }
      if (tr.kind === 'modify') step.changes += self.db.getRowsModified();
    });
    if (tr.kind === 'tx') this.inTx = /BEGIN/.test(tr.sqlite[0]);
    return step;
  };
  /* MySQL evaluates WHERE before the SELECT list: an alias defined there does not exist yet, and MySQL
     answers « Unknown column ». SQLite accepts it, which would teach the opposite. */
  function aliasInWhere(sql, session) {
    var toks = SQLC.tokenize(sql), depth = 0, aliases = {}, inSelect = false, where = -1, end = toks.length;
    toks.forEach(function (t, i) {
      if (t.v === '(') depth++; else if (t.v === ')') depth--;
      if (depth !== 0 || t.t !== 'w') return;
      if (t.up === 'SELECT' && where < 0) inSelect = true;
      else if (t.up === 'FROM') inSelect = false;
      else if (inSelect && t.up === 'AS' && toks[i + 1] && toks[i + 1].t !== 'p') aliases[toks[i + 1].v.replace(/[`"]/g, '').toLowerCase()] = toks[i + 1].v;
      else if (t.up === 'WHERE' && where < 0) where = i;
      else if (where >= 0 && end === toks.length && ['GROUP', 'ORDER', 'HAVING', 'LIMIT'].indexOf(t.up) !== -1) end = i;
    });
    if (where < 0 || !Object.keys(aliases).length) return;
    var known = {};
    session.tables().forEach(function (t) { session.columns(t).forEach(function (c) { known[c.name] = 1; }); });
    for (var i = where + 1; i < end; i++) {
      var w = toks[i];
      if (w.t !== 'w' && w.t !== 'id') continue;
      var name = w.v.replace(/[`"]/g, '').toLowerCase();
      if (aliases[name] && !known[name] && !(toks[i + 1] && toks[i + 1].v === '(') && !(toks[i - 1] && toks[i - 1].v === '.')) {
        var e = SQLC.Refused('« ' + aliases[name] + ' » est un alias du SELECT : WHERE est évalué avant le SELECT, l\'alias n\'existe pas encore. Répète le calcul dans le WHERE.');
        e.code = "ERROR 1054 (42S22): Unknown column '" + aliases[name] + "' in 'where clause'";
        throw e;
      }
    }
  }

  // ALTER TABLE … ADD CONSTRAINT: SQLite rebuilds the table with the constraint, and refuses when a row
  // already breaks it - as MySQL does.
  Session.prototype.rebuild = function (table, constraint) {
    if (this.inTx) throw SQLC.Refused('Ajoute la contrainte hors d\'une transaction : MySQL valide de toute façon la transaction en cours avant un ALTER TABLE.');
    var t = this.query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '" + table.replace(/'/g, "''") + "'").rows[0];
    if (!t) { var e = SQLC.Refused('La table ' + table + ' n\'existe pas.'); e.code = "ERROR 1146 (42S02): Table 'collection." + table + "' doesn't exist"; throw e; }
    var create = t[0], close = create.lastIndexOf(')');
    var next = create.slice(0, close).replace(/^(\s*CREATE\s+TABLE\s+)("?\w+"?|`\w+`)/i, '$1_spp_rebuild') + ',\n  ' + constraint + '\n)';
    this.db.run('PRAGMA foreign_keys = OFF');
    this.db.run('PRAGMA legacy_alter_table = ON');
    try {
      this.db.run('SAVEPOINT spp_rebuild');
      try {
        this.db.run(next);
        this.db.run('INSERT INTO _spp_rebuild SELECT * FROM "' + table + '"');
        this.db.run('DROP TABLE "' + table + '"');
        this.db.run('ALTER TABLE _spp_rebuild RENAME TO "' + table + '"');
        var bad = this.query('PRAGMA foreign_key_check("' + table + '")').rows;
        if (bad.length) throw new Error('FOREIGN KEY constraint failed');
        this.db.run('RELEASE spp_rebuild');
      } catch (err) {
        this.db.run('ROLLBACK TO spp_rebuild'); this.db.run('RELEASE spp_rebuild');
        var refused = SQLC.Refused(SQLC.frenchError(err.message) + (/CHECK|UNIQUE|FOREIGN|NOT NULL/.test(err.message) ? ' Des lignes déjà dans la table ne respectent pas la contrainte : corrige-les d\'abord.' : ''));
        if (/FOREIGN KEY/.test(err.message)) refused.code = 'ERROR 1452 (23000): Cannot add or update a child row: a foreign key constraint fails';
        throw refused;
      }
    } finally { this.db.run('PRAGMA legacy_alter_table = OFF'); this.db.run('PRAGMA foreign_keys = ON'); }
  };
  Session.prototype.run = function (text) {
    var out = { steps: [], error: null, rolledBack: false };
    var stmts;
    try { stmts = SQLC.split(text); } catch (e) { out.error = explain(e, text, this); return out; }
    if (!stmts.length) { out.error = { mysql: '', fr: 'La console est vide : écris une requête, puis exécute-la.' }; return out; }
    for (var i = 0; i < stmts.length; i++) {
      try { out.steps.push(this.statement(stmts[i].sql)); } catch (e) {
        out.error = explain(e, stmts[i].sql, this);
        out.error.index = i;
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
  Session.prototype.close = function () { try { this.db.close(); } catch (e) { /* closed */ } };

  var scripts = {};
  function open(which) {
    if (!SQL) throw new Error('sql.js n\'est pas chargé.');
    var w = which === 'hidden' ? 'hidden' : 'visible';
    var db = new SQL.Database();
    db.run('PRAGMA foreign_keys = ON');
    functions(db);
    var s = new Session(db);
    if (!scripts[w]) scripts[w] = BASE.script(w);
    var base = s.run(scripts[w]);
    if (base.error) throw new Error('Base ' + w + ' : ' + base.error.fr);
    return s;
  }

  // ——— Errors: MySQL's code and message, then the explanation ———
  // The words of the SQL the course meets: a mistyped one is recognised by its nearness to one of them,
  // and none of them is ever taken for a typo.
  var KEYWORDS = ['SELECT', 'FROM', 'WHERE', 'ORDER', 'GROUP', 'BY', 'HAVING', 'LIMIT', 'OFFSET', 'ASC', 'DESC', 'AND', 'OR', 'NOT', 'IN', 'IS', 'AS',
    'JOIN', 'INNER', 'LEFT', 'RIGHT', 'OUTER', 'ON', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'CREATE', 'TABLE', 'ALTER', 'ADD', 'DROP', 'COLUMN',
    'DISTINCT', 'BETWEEN', 'LIKE', 'NULL', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'ROUND', 'UPPER', 'LOWER', 'CONCAT', 'IFNULL', 'COALESCE', 'YEAR', 'MONTH',
    'CURDATE', 'DATEDIFF', 'LENGTH', 'PRIMARY', 'FOREIGN', 'KEY', 'REFERENCES', 'UNIQUE', 'DEFAULT', 'AUTO_INCREMENT', 'CONSTRAINT', 'INT', 'INTEGER',
    'VARCHAR', 'CHAR', 'TEXT', 'DATE', 'DECIMAL', 'START', 'TRANSACTION', 'COMMIT', 'ROLLBACK', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'EXISTS', 'TRUE', 'FALSE'];
  function lev(a, b) {
    a = a.toLowerCase(); b = b.toLowerCase();
    var d = [], i, j;
    for (i = 0; i <= a.length; i++) { d[i] = [i]; }
    for (j = 1; j <= b.length; j++) d[0][j] = j;
    for (i = 1; i <= a.length; i++) for (j = 1; j <= b.length; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    return d[a.length][b.length];
  }
  function nearest(word, list) {
    var best = null, bd = 99;
    list.forEach(function (w) { var x = lev(word, w); if (x < bd) { bd = x; best = w; } });
    return bd <= (word.length >= 4 ? 2 : 1) && bd > 0 ? best : null;
  }
  function allColumns() { var o = []; BASE.TABLES.forEach(function (t) { t.columns.forEach(function (c) { if (o.indexOf(c) === -1) o.push(c); }); }); return o; }
  function valueColumn(word) {
    var w = String(word).toLowerCase(), found = null;
    var cols = BASE.TABLES[0].columns;
    BASE.SPECIMENS.visible.forEach(function (r) { r.forEach(function (v, i) { if (!found && typeof v === 'string' && v.toLowerCase() === w) found = cols[i]; }); });
    return found;
  }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function code(s) { return '<code>' + esc(s) + '</code>'; }
  function explain(e, sql, session) {
    var msg = String(e && e.message || e).replace(/^Error: /, '');
    if (e && e.refused) return { mysql: e.code || '', fr: esc(msg) };
    var m, up = String(sql || '').trim().toUpperCase();
    if ((m = /no such column: ([\w.]+)/i.exec(msg))) {
      var col = m[1].replace(/^\w+\./, ''), wi = up.search(/\bWHERE\b/), pos = up.indexOf(col.toUpperCase());
      var clause = wi >= 0 && pos > wi ? 'where clause' : (/\bON\b/.test(up) && pos > up.search(/\bON\b/) ? 'on clause' : 'field list');
      var v = valueColumn(col), near = nearest(col, allColumns()), tables = session ? session.tables() : [];
      var fr = v ? '« ' + esc(col) + ' » est une valeur de la colonne ' + code(v) + ', pas une colonne. Un texte s\'écrit entre apostrophes : ' + code("'" + col + "'") + '.'
        : 'Aucune table de la requête n\'a de colonne « ' + esc(col) + ' ».' + (near ? ' Tu voulais dire ' + code(near) + ' ?' : ' Ouvre la carte de la base pour voir les colonnes.');
      if (!v && tables.indexOf(col.toLowerCase()) !== -1) fr = '« ' + esc(col) + ' » est une table, pas une colonne : elle se nomme après FROM.';
      return { mysql: "ERROR 1054 (42S22): Unknown column '" + m[1] + "' in '" + clause + "'", fr: fr };
    }
    if ((m = /no such table: ([\w.]+)/i.exec(msg))) {
      var t = m[1], nt = nearest(t, BASE.TABLES.map(function (x) { return x.name; }));
      return { mysql: "ERROR 1146 (42S02): Table 'collection." + t + "' doesn't exist", fr: 'Il n\'y a pas de table « ' + esc(t) + ' » dans la base.' + (nt ? ' Tu voulais dire ' + code(nt) + ' ?' : ' Ouvre la carte de la base.') };
    }
    if ((m = /ambiguous column name: ([\w.]+)/i.exec(msg))) {
      return { mysql: "ERROR 1052 (23000): Column '" + m[1] + "' in field list is ambiguous", fr: 'Plusieurs tables de la requête ont une colonne ' + code(m[1]) + ' : précise laquelle, avec le nom de la table ou son alias devant (' + code('s.' + m[1]) + ').' };
    }
    if ((m = /near "([^"]*)": syntax error/i.exec(msg))) {
      var tok = m[1], idx = String(sql).indexOf(tok), around = idx >= 0 ? String(sql).slice(idx, idx + 30) : tok;
      var words = (String(sql).replace(/'[^']*'/g, ' ').match(/[A-Za-z_]+/g) || []);
      var typo = null;
      words.forEach(function (w) { if (!typo && w.length >= 4 && KEYWORDS.indexOf(w.toUpperCase()) === -1 && allColumns().indexOf(w.toLowerCase()) === -1 && nearest(w.toUpperCase(), KEYWORDS)) typo = w; });
      var kw = nearest(tok.toUpperCase(), KEYWORDS), fr;
      if (typo) fr = '« ' + esc(typo) + ' » n\'est pas un mot de SQL : MySQL l\'a pris pour un nom, puis s\'est perdu sur « ' + esc(tok) + ' ». Tu voulais dire ' + code(nearest(typo.toUpperCase(), KEYWORDS)) + ' ?';
      else if (/^from$/i.test(tok) && /,\s*from\b/i.test(sql)) fr = 'Une virgule de trop juste avant ' + code('FROM') + ' : la virgule sépare deux colonnes, la dernière n\'en a pas.';
      else if (kw && kw !== tok.toUpperCase()) fr = 'MySQL s\'est arrêté sur « ' + esc(tok) + ' ». Tu voulais dire ' + code(kw) + ' ?';
      else fr = 'MySQL s\'est arrêté sur « ' + esc(tok) + ' ». Vérifie l\'ordre des mots (' + code('SELECT … FROM … WHERE … GROUP BY … HAVING … ORDER BY … LIMIT') + '), les virgules et les parenthèses.';
      return { mysql: "ERROR 1064 (42000): You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near '" + around + "' at line 1", fr: fr };
    }
    if (/incomplete input/i.test(msg)) return { mysql: "ERROR 1064 (42000): You have an error in your SQL syntax; … near '' at line 1", fr: 'La requête s\'arrête au milieu : il manque la fin (une table après FROM ? une condition après WHERE ? une parenthèse ?).' };
    if ((m = /unrecognized token: "?([^"]*)"?/i.exec(msg))) return { mysql: "ERROR 1064 (42000): You have an error in your SQL syntax; … near '" + m[1] + "'", fr: 'Une apostrophe ouverte n\'est jamais refermée : ' + code(m[1]) + '. Un texte s\'écrit entre deux apostrophes droites, et une apostrophe dans le texte se double (' + code("'L''Aigle'") + ').' };
    if ((m = /UNIQUE constraint failed: (\S+)/i.exec(msg))) return { mysql: "ERROR 1062 (23000): Duplicate entry for key '" + m[1] + "'", fr: 'Doublon refusé : ' + code(m[1]) + ' est une clé (primaire ou UNIQUE), deux lignes ne peuvent pas y avoir la même valeur.' };
    if (/FOREIGN KEY constraint failed/i.test(msg)) {
      if (/^(DELETE|UPDATE)\b/.test(up) && !/^UPDATE\s+PRET\b/.test(up)) return { mysql: 'ERROR 1451 (23000): Cannot delete or update a parent row: a foreign key constraint fails', fr: 'Refusé par une clé étrangère : d\'autres lignes (dans une autre table) font référence à celle-ci. Il faut d\'abord supprimer ou modifier ces lignes-là.' };
      return { mysql: 'ERROR 1452 (23000): Cannot add or update a child row: a foreign key constraint fails', fr: 'Refusé par une clé étrangère : la valeur que tu écris doit exister dans la table référencée. Vérifie qu\'elle y est.' };
    }
    if ((m = /NOT NULL constraint failed: (\S+)/i.exec(msg))) return { mysql: "ERROR 1048 (23000): Column '" + m[1].replace(/^\w+\./, '') + "' cannot be null", fr: 'La colonne ' + code(m[1]) + ' est NOT NULL : elle doit recevoir une valeur.' };
    if (/misuse of aggregate/i.test(msg)) return { mysql: 'ERROR 1111 (HY000): Invalid use of group function', fr: 'Une fonction d\'agrégat (COUNT, SUM, AVG…) ne s\'utilise pas dans un ' + code('WHERE') + ' : WHERE filtre les lignes avant le calcul. Une condition sur le résultat d\'un groupe s\'écrit avec ' + code('HAVING') + '.' };
    if ((m = /table (\S+) already exists/i.exec(msg))) return { mysql: "ERROR 1050 (42S01): Table '" + m[1] + "' already exists", fr: 'La table ' + code(m[1]) + ' existe déjà.' };
    if ((m = /table (\S+) has (\d+) columns but (\d+) values were supplied/i.exec(msg))) return { mysql: "ERROR 1136 (21S01): Column count doesn't match value count at row 1", fr: 'La table ' + code(m[1]) + ' a ' + m[2] + ' colonnes et tu donnes ' + m[3] + ' valeurs. Nomme les colonnes que tu remplis : ' + code('INSERT INTO t (col1, col2) VALUES (…)') + '.' };
    if ((m = /(\d+) values for (\d+) columns/i.exec(msg))) return { mysql: "ERROR 1136 (21S01): Column count doesn't match value count at row 1", fr: 'Tu nommes ' + m[2] + ' colonnes mais tu donnes ' + m[1] + ' valeurs : il en faut autant que de colonnes.' };
    if ((m = /no such function: (\w+)/i.exec(msg))) return { mysql: 'ERROR 1305 (42000): FUNCTION collection.' + m[1] + ' does not exist', fr: 'La fonction ' + code(m[1]) + ' n\'existe pas.' };
    if (/sub-select returns (\d+) columns - expected 1/i.test(msg)) return { mysql: 'ERROR 1241 (21000): Operand should contain 1 column(s)', fr: 'La sous-requête doit renvoyer une seule colonne.' };
    return { mysql: '', fr: esc(SQLC.frenchError(msg)) };
  }

  // ——— Comparing results ———
  function norm(v) {
    if (v === null || v === undefined) return null;
    if (typeof v === 'number') return Math.round(v * 100) / 100;
    if (typeof v === 'string' && /^-?\d+(\.\d+)?$/.test(v)) return Math.round(parseFloat(v) * 100) / 100;
    return String(v);
  }
  function key(r) { return JSON.stringify(r.map(norm)); }
  function colKey(rows, j) { return rows.map(function (r) { return JSON.stringify(norm(r[j])); }).sort().join('\u0001'); }
  /* The columns of `got` matched to those of `exp`: by name and values, by values, by name, then by the
     best overlap - so that a student's alias, or another order of the columns, is not an error. */
  function mapColumns(exp, got) {
    var used = {}, map = [];
    exp.columns.forEach(function (c, j) {
      var ek = colKey(exp.rows, j), k = -1, i;
      for (i = 0; i < got.columns.length && k < 0; i++) if (!used[i] && got.columns[i].toLowerCase() === c.toLowerCase() && colKey(got.rows, i) === ek) k = i;
      for (i = 0; i < got.columns.length && k < 0; i++) if (!used[i] && colKey(got.rows, i) === ek) k = i;
      for (i = 0; i < got.columns.length && k < 0; i++) if (!used[i] && got.columns[i].toLowerCase().replace(/^\w+\./, '') === c.toLowerCase().replace(/^\w+\./, '')) k = i;
      if (k < 0) {
        var ev = {}, best = -1, bs = 0;
        exp.rows.forEach(function (r) { ev[JSON.stringify(norm(r[j]))] = 1; });
        got.columns.forEach(function (g, i2) {
          if (used[i2]) return;
          var gv = {}, inter = 0, all = {};
          got.rows.forEach(function (r) { gv[JSON.stringify(norm(r[i2]))] = 1; });
          Object.keys(gv).forEach(function (x) { all[x] = 1; if (ev[x]) inter++; });
          Object.keys(ev).forEach(function (x) { all[x] = 1; });
          var s = inter / Math.max(1, Object.keys(all).length);
          if (s > bs) { bs = s; best = i2; }
        });
        if (bs >= 0.3) k = best;
      }
      if (k >= 0) used[k] = true;
      map.push(k);
    });
    return map;
  }
  /* The ORDER BY of a reference, as the output columns it sorts on: equal values there may come in any
     order (a tie is not an error). null when the reference does not sort; [] when it sorts on something
     the result does not show - then the whole order must match. */
  function orderKeys(ref, columns) {
    var toks = SQLC.tokenize(ref), depth = 0, at = -1;
    toks.forEach(function (t, i) {
      if (t.v === '(') depth++; else if (t.v === ')') depth--;
      else if (depth === 0 && t.t === 'w' && t.up === 'ORDER' && toks[i + 1] && toks[i + 1].up === 'BY') at = i + 2;
    });
    if (at < 0) return null;
    var items = [], cur = [], d = 0;
    for (var i = at; i < toks.length; i++) {
      var t = toks[i];
      if (d === 0 && t.t === 'w' && (t.up === 'LIMIT' || t.up === 'OFFSET')) break;
      if (t.v === ';') break;
      if (t.v === '(') d++; else if (t.v === ')') d--;
      if (d === 0 && t.v === ',') { items.push(cur); cur = []; continue; }
      cur.push(t);
    }
    if (cur.length) items.push(cur);
    var keys = [];
    for (var k = 0; k < items.length; k++) {
      var it = items[k].filter(function (t) { return !(t.t === 'w' && (t.up === 'ASC' || t.up === 'DESC')); });
      if (it.length === 1 && it[0].t === 'num') { keys.push(+it[0].v - 1); continue; }
      var name = it.map(function (t) { return t.v; }).join('').replace(/[`"]/g, '').toLowerCase().replace(/^\w+\./, '');
      var idx = columns.map(function (c) { return c.toLowerCase(); }).indexOf(name);
      if (idx < 0) return [];
      keys.push(idx);
    }
    return keys;
  }
  function multiset(rows) { var m = {}; rows.forEach(function (r) { var k = key(r); m[k] = (m[k] || 0) + 1; }); return m; }
  function rowDiff(expRows, gotRows) {
    var pool = multiset(expRows), extra = [], missing = [];
    gotRows.forEach(function (r) { var k = key(r); if (pool[k]) pool[k]--; else extra.push(r); });
    var pool2 = multiset(gotRows);
    expRows.forEach(function (r) { var k = key(r); if (pool2[k]) pool2[k]--; else missing.push(r); });
    return { extra: extra, missing: missing };
  }
  /* Compares a result to the expected one: { colsOk, rowsOk, orderOk, mapped, proj, extra, missing }. */
  function compare(exp, got, ordering) {
    var res = { colsOk: false, rowsOk: false, orderOk: true, mapped: null, proj: null, extra: [], missing: [], expCols: exp.columns.length, gotCols: got.columns.length };
    var map = mapColumns(exp, got);
    res.colsOk = got.columns.length === exp.columns.length && map.every(function (k) { return k >= 0; });
    if (!map.every(function (k) { return k >= 0; })) return res;
    res.mapped = map;
    res.proj = got.rows.map(function (r) { return map.map(function (k) { return r[k]; }); });
    var d = rowDiff(exp.rows, res.proj);
    res.extra = d.extra; res.missing = d.missing;
    res.rowsOk = !d.extra.length && !d.missing.length;
    if (res.rowsOk && ordering) {
      var keysOf = function (rows) { return rows.map(function (r) { return JSON.stringify((ordering.length ? ordering : r.map(function (_, i) { return i; })).map(function (i) { return norm(r[i]); })); }); };
      res.orderOk = JSON.stringify(keysOf(exp.rows)) === JSON.stringify(keysOf(res.proj));
    }
    return res;
  }
  function lastSelect(report) {
    for (var i = report.steps.length - 1; i >= 0; i--) if (report.steps[i].kind === 'select') return report.steps[i];
    return null;
  }
  function names(rows, n) {
    n = n || 3;
    return rows.slice(0, n).map(function (r) { return '« ' + r.map(function (v) { return v === null ? 'NULL' : String(norm(v)); }).join(' · ') + ' »'; }).join(', ') + (rows.length > n ? ' et ' + (rows.length - n) + ' autre' + (rows.length - n > 1 ? 's' : '') : '');
  }
  function plural(n, one, many) { return n + ' ' + (n > 1 ? many : one); }

  // ——— Misconceptions recognised in the text of a query ———
  function misconception(text, def, cmp) {
    var t = ' ' + String(text).replace(/'(?:[^']|'')*'/g, "''") + ' ', up = t.toUpperCase();
    var sel = /SELECT\s+([\s\S]*?)\s+FROM\b/i.exec(text);
    if (cmp && cmp.gotCols < cmp.expCols && sel && sel[1].split(',').some(function (p) { return /^\s*[a-z_][\w.]*\s+(?!AS\b)[a-z_]\w*\s*$/i.test(p); }))
      return '<b>La virgule.</b> Sans virgule entre deux colonnes, MySQL lit « la première colonne, renommée comme la seconde » : tu n\'obtiens qu\'une colonne.';
    if (/(=|<>|!=)\s*NULL\b/.test(up)) return '<b>Rien n\'est égal à NULL</b>, pas même NULL : ' + code('= NULL') + ' n\'est jamais vrai. On écrit ' + code('IS NULL') + ' (ou ' + code('IS NOT NULL') + ').';
    var same = /\b([A-Z_][\w.]*)\s*=\s*''\s+AND\s+\1\s*=\s*''/.exec(up);
    if (same) return '<b>ET ou OU ?</b> Une ligne ne peut pas avoir deux valeurs à la fois dans ' + code(same[1].toLowerCase()) + ' : « les uns et les autres », c\'est ' + code('OR') + ' (ou ' + code('IN (…)') + ').';
    if (cmp && !cmp.rowsOk && /\bOR\b/.test(up) && /\bAND\b/.test(up) && !/\(/.test(t.replace(/\w+\s*\(/g, ''))) return '<b>AND passe avant OR.</b> Sans parenthèses, ' + code('a OR b AND c') + ' se lit ' + code('a OR (b AND c)') + '. Entoure ce qui va ensemble : ' + code('(a OR b) AND c') + '.';
    var like = /LIKE\s+'([^'%_]*)'/i.exec(text);
    if (like && cmp && !cmp.rowsOk) return '<b>LIKE sans %.</b> Sans ' + code('%') + ', ' + code("LIKE '" + like[1] + "'") + ' compare le texte entier, comme ' + code('=') + '. ' + code("'%" + like[1] + "%'") + ' cherche le mot n\'importe où.';
    if (cmp && !cmp.rowsOk && /COUNT\s*\(\s*(?!\*|DISTINCT)\w/i.test(text) && def && /COUNT\s*\(\s*\*\s*\)/i.test(def.ref || '')) return '<b>COUNT(colonne) ne compte pas les NULL.</b> Pour compter les lignes, c\'est ' + code('COUNT(*)') + '.';
    if (cmp && cmp.extra.length && /FROM\s+\w+(\s+\w+)?\s*,\s*\w+/i.test(text) && !/\bWHERE\b/i.test(text)) return '<b>Le produit cartésien.</b> Deux tables séparées par une virgule, sans condition, associent chaque ligne de l\'une à chaque ligne de l\'autre. Il manque la condition de jointure (' + code('JOIN … ON …') + ').';
    if (cmp && cmp.missing.length && /\bJOIN\b/i.test(text) && !/\bLEFT\s+(OUTER\s+)?JOIN\b/i.test(text) && def && /\bLEFT\s+JOIN\b/i.test(def.ref || '')) return '<b>INNER ou LEFT ?</b> Une jointure interne (' + code('JOIN') + ') ne garde que les lignes qui ont une correspondance. Pour garder aussi les autres, c\'est ' + code('LEFT JOIN') + '.';
    if (cmp && cmp.missing.length && /\bNOT\s+IN\s*\(\s*SELECT\b/i.test(text)) return '<b>NOT IN et NULL.</b> Si la sous-requête renvoie un NULL, ' + code('NOT IN') + ' n\'est jamais vrai. Écarte les NULL de la sous-requête, ou passe par ' + code('LEFT JOIN … IS NULL') + '.';
    return '';
  }

  // ——— The checks of a step ———
  function runOn(which, text) { var s = open(which); try { return { report: s.run(text), session: s }; } catch (e) { s.close(); throw e; } }

  /* A query that must return what the reference returns, on the visible base and on the hidden one. */
  function checkSelect(def, text) {
    var vis = runOn('visible', text), crit = [], shown = { kind: 'select' };
    try {
      if (vis.report.error) return { passed: false, failed: ['La requête s\'exécute sans erreur'], criteria: [{ label: 'La requête s\'exécute sans erreur', ok: false }], error: vis.report.error };
      var got = lastSelect(vis.report);
      if (!got) return { passed: false, failed: ['Une requête SELECT'], criteria: [{ label: 'Une requête SELECT', ok: false, detail: 'Il faut une requête qui lit la base : elle commence par ' + code('SELECT') + '.' }] };
      var refRun = runOn('visible', def.ref), exp = lastSelect(refRun.report); refRun.session.close();
      var ordering = def.unordered ? null : orderKeys(def.ref, exp.columns);
      var cmp = compare({ columns: exp.columns, rows: exp.rows }, { columns: got.columns, rows: got.rows }, ordering);
      shown = { kind: 'select', exp: exp, got: got, cmp: cmp };
      var ir = misconception(text, def, cmp);
      // Columns
      var colMsg = 'Les colonnes demandées : ' + exp.columns.join(', ') + '.';
      if (!cmp.colsOk) colMsg = cmp.gotCols < cmp.expCols ? 'Il manque une colonne : ' + cmp.expCols + ' attendues, ' + cmp.gotCols + ' obtenue' + (cmp.gotCols > 1 ? 's' : '') + '.' : (cmp.gotCols > cmp.expCols ? 'Colonnes en trop : seules ' + exp.columns.join(', ') + ' sont demandées.' : 'Ce ne sont pas les colonnes demandées : ' + exp.columns.join(', ') + '.');
      crit.push({ label: 'Les bonnes colonnes', ok: cmp.colsOk, detail: colMsg, ir: cmp.colsOk ? '' : ir });
      // Rows
      var rowMsg;
      if (!cmp.mapped) rowMsg = 'Les lignes seront comparées quand les colonnes seront les bonnes.';
      else if (cmp.rowsOk) rowMsg = 'Toutes les lignes attendues, et seulement elles (' + exp.rows.length + ').';
      else rowMsg = [cmp.missing.length ? 'Il manque ' + plural(cmp.missing.length, 'ligne', 'lignes') + ' : ' + names(cmp.missing) + '.' : '', cmp.extra.length ? plural(cmp.extra.length, 'ligne', 'lignes') + ' en trop : ' + names(cmp.extra) + '.' : ''].join(' ');
      crit.push({ label: 'Les bonnes lignes', ok: !!cmp.mapped && cmp.rowsOk, wait: !cmp.mapped, detail: rowMsg, ir: cmp.mapped && !cmp.rowsOk ? ir : '' });
      if (ordering) crit.push({ label: 'Dans le bon ordre', ok: cmp.rowsOk && cmp.orderOk, wait: !cmp.rowsOk, detail: !cmp.rowsOk ? 'L\'ordre sera vérifié quand les lignes seront les bonnes.' : (cmp.orderOk ? 'Dans l\'ordre demandé.' : 'Les bonnes lignes, mais pas dans l\'ordre demandé : relis ton ' + code('ORDER BY') + ' (' + code('ASC') + ' croissant, ' + code('DESC') + ' décroissant).') });
      // Hidden base
      var visibleOk = crit.every(function (c) { return c.ok; });
      if (visibleOk) {
        var hid = runOn('hidden', text), refH = runOn('hidden', def.ref);
        var gotH = hid.report.error ? null : lastSelect(hid.report), expH = lastSelect(refH.report);
        hid.session.close(); refH.session.close();
        var okH = false, msgH = '';
        if (!gotH) msgH = 'Sur une autre base (mêmes tables, autres lignes), la requête provoque une erreur.';
        else {
          var cH = compare(expH, gotH, def.unordered ? null : orderKeys(def.ref, expH.columns));
          okH = cH.colsOk && cH.rowsOk && cH.orderOk;
          if (!okH) msgH = (def.hidden && def.hidden(cH)) || (cH.rowsOk && !cH.orderOk ? 'Sur une autre base, l\'ordre n\'est plus le bon : trie sur la colonne demandée, pas sur ce qui tombe juste ici.' : 'Sur une autre base (mêmes tables, autres lignes), ta requête se trompe' + (cH.extra.length ? ' : ' + plural(cH.extra.length, 'ligne', 'lignes') + ' en trop' : '') + (cH.missing.length ? (cH.extra.length ? ', ' : ' : ') + plural(cH.missing.length, 'ligne', 'lignes') + ' manquante' + (cH.missing.length > 1 ? 's' : '') : '') + '. As-tu écrit des valeurs en dur (des id, des noms) au lieu d\'une condition, ou une borne approximative ?');
        }
        crit.push({ label: 'Juste aussi sur la base cachée', ok: okH, detail: okH ? 'Sur une autre base, que tu ne vois pas : juste aussi.' : msgH });
      } else crit.push({ label: 'Juste aussi sur la base cachée', ok: false, wait: true, detail: 'Testée quand la base visible est juste.' });
      return finish(crit, shown);
    } finally { vis.session.close(); }
  }

  function snapshot(session, tables) {
    var out = {};
    tables.forEach(function (t) {
      try { var q = session.query('SELECT * FROM "' + t + '"'); out[t] = { columns: q.columns, rows: q.rows }; } catch (e) { out[t] = null; }
    });
    return out;
  }
  /* A modification (INSERT, UPDATE, DELETE, a transaction): the tables it touches must end as the
     reference leaves them, on both bases. */
  function checkModify(def, text) {
    var tables = def.tables, crit = [], shown = { kind: 'modify', tables: tables };
    var vis = runOn('visible', text);
    try {
      if (vis.report.error) return { passed: false, failed: ['La requête s\'exécute sans erreur'], criteria: [{ label: 'La requête s\'exécute sans erreur', ok: false }], error: vis.report.error, shown: { kind: 'modify', tables: tables, after: snapshot(vis.session, tables) } };
      var ref = runOn('visible', def.ref), after = snapshot(vis.session, tables), expected = snapshot(ref.session, tables); ref.session.close();
      shown.after = after; shown.expected = expected; shown.changes = vis.report.steps.reduce(function (a, s) { return a + (s.changes || 0); }, 0);
      tables.forEach(function (t) {
        var d = rowDiff(expected[t].rows, after[t] ? after[t].rows : []);
        var ok = !d.extra.length && !d.missing.length;
        shown['diff_' + t] = d;
        crit.push({ label: 'La table ' + t + ' telle qu\'attendue', ok: ok, detail: ok ? 'La table ' + t + ' est exactement dans l\'état attendu.' : [d.missing.length ? plural(d.missing.length, 'ligne attendue manque', 'lignes attendues manquent') + ' : ' + names(d.missing, 2) + '.' : '', d.extra.length ? plural(d.extra.length, 'ligne ne devrait pas être là', 'lignes ne devraient pas être là') + ' (ou n\'ont pas les bonnes valeurs) : ' + names(d.extra, 2) + '.' : ''].join(' '), ir: ok ? '' : modifyIr(text) });
      });
      (def.requires || []).forEach(function (r) {
        var ok = new RegExp(r.re, 'i').test(text);
        crit.push({ label: r.label, ok: ok, detail: ok ? r.label + ' : oui.' : r.missing });
      });
      if (vis.report.rolledBack && def.commit) crit.push({ label: 'La transaction est validée', ok: false, detail: 'La transaction est restée ouverte : sans ' + code('COMMIT') + ', MySQL l\'annule à la déconnexion, et rien n\'est enregistré.' });
      if (def.hiddenless) return finish(crit, shown);
      if (crit.every(function (c) { return c.ok; })) {
        var hid = runOn('hidden', text), refH = runOn('hidden', def.ref), okH = !hid.report.error;
        if (okH) { var a = snapshot(hid.session, tables), e = snapshot(refH.session, tables); tables.forEach(function (t) { var d = rowDiff(e[t].rows, a[t] ? a[t].rows : []); if (d.extra.length || d.missing.length) okH = false; }); }
        hid.session.close(); refH.session.close();
        crit.push({ label: 'Juste aussi sur la base cachée', ok: okH, detail: okH ? 'Sur une autre base, que tu ne vois pas : juste aussi.' : 'Sur une autre base (mêmes tables, autres lignes), ta requête ne laisse pas les tables dans l\'état attendu. As-tu désigné les lignes par une valeur en dur (un id) au lieu de la condition demandée ?' });
      } else crit.push({ label: 'Juste aussi sur la base cachée', ok: false, wait: true, detail: 'Testée quand la base visible est juste.' });
      return finish(crit, shown);
    } finally { vis.session.close(); }
  }
  function modifyIr(text) {
    if (/^\s*UPDATE\b/i.test(text) && /\bSET\b[\s\S]*\bAND\b[\s\S]*\bWHERE\b/i.test(text)) return '<b>SET a = 1 AND b = 2.</b> Dans un ' + code('SET') + ', les colonnes se séparent par des virgules : ' + code('SET a = 1, b = 2') + '. Avec AND, MySQL calcule une condition.';
    return '';
  }

  var TYPES = { int: /^(INT|INTEGER|SMALLINT|TINYINT|MEDIUMINT|BIGINT)\b/i, text: /^(VARCHAR|CHAR|TEXT)\b/i, date: /^(DATE|DATETIME)\b/i, decimal: /^(REAL|DECIMAL|NUMERIC|FLOAT|DOUBLE)\b/i };
  var TYPE_LABELS = { int: 'un entier (INT)', text: 'un texte (VARCHAR)', date: 'une date (DATE)', decimal: 'un nombre à virgule (DECIMAL)' };
  /* A structure (CREATE TABLE, ALTER TABLE): the tables and their columns, keys and constraints, then
     probes - statements that must pass or be refused once the structure is in place. */
  function checkCreate(def, text) {
    var vis = runOn('visible', text), crit = [];
    try {
      if (vis.report.error) return { passed: false, failed: ['La requête s\'exécute sans erreur'], criteria: [{ label: 'La requête s\'exécute sans erreur', ok: false }], error: vis.report.error };
      var s = vis.session, tables = s.tables();
      def.expect.forEach(function (t) {
        if (tables.indexOf(t.table) === -1) { crit.push({ label: 'La table ' + t.table + ' existe', ok: false, detail: 'La table ' + code(t.table) + ' n\'existe pas après ta requête.' }); return; }
        var cols = s.columns(t.table), byName = {};
        cols.forEach(function (c) { byName[c.name] = c; });
        t.columns.forEach(function (c) {
          var got = byName[c.name], label = 'La colonne ' + t.table + '.' + c.name, problems = [];
          if (!got) { crit.push({ label: label, ok: false, detail: 'Il manque la colonne ' + code(c.name) + ' dans ' + code(t.table) + '.' }); return; }
          if (c.type && !TYPES[c.type].test(got.type)) problems.push('elle doit contenir ' + TYPE_LABELS[c.type]);
          if (c.pk && !got.pk) problems.push('elle doit être la clé primaire (PRIMARY KEY)');
          if (c.auto && !(got.pk && /^INTEGER$/i.test(got.type))) problems.push('elle doit se numéroter toute seule (AUTO_INCREMENT)');
          if (c.notNull && !got.notNull) problems.push('elle doit être obligatoire (NOT NULL)');
          if (c.unique && !got.unique) problems.push('ses valeurs doivent être uniques (UNIQUE)');
          if (c.fk && (!got.fk || got.fk.table !== c.fk.split('.')[0])) problems.push('elle doit faire référence à ' + c.fk + ' (FOREIGN KEY … REFERENCES)');
          if (c.dflt !== undefined && String(got.dflt).replace(/^'|'$/g, '') !== String(c.dflt)) problems.push('sa valeur par défaut doit être ' + c.dflt + ' (DEFAULT ' + c.dflt + ')');
          crit.push({ label: label, ok: !problems.length, detail: problems.length ? 'La colonne ' + code(t.table + '.' + c.name) + ' : ' + problems.join(', ') + '.' : 'La colonne ' + code(t.table + '.' + c.name) + ' est comme demandé.' });
        });
      });
      if (crit.every(function (c) { return c.ok; }) && def.probes) {
        def.probes.forEach(function (p) {
          var r = s.run(p.sql), refused = !!r.error, ok = p.expect === 'refused' ? refused : !refused;
          crit.push({ label: p.label, ok: ok, detail: ok ? p.label + ' : comme attendu.' : (p.expect === 'refused' ? 'Ta structure accepte ' + code(p.sql) + ', qu\'elle devrait refuser.' : 'Ta structure refuse ' + code(p.sql) + ', qu\'elle devrait accepter' + (r.error ? ' (' + r.error.fr + ')' : '') + '.') });
        });
      }
      return finish(crit, { kind: 'create', tables: s.tables().filter(function (t) { return def.expect.some(function (e) { return e.table === t; }); }).map(function (t) { return { name: t, columns: s.columns(t) }; }) });
    } finally { vis.session.close(); }
  }

  /* A prediction: the rows touched (their ids, picked on the visible base), or the value a query gives. */
  function checkPick(def, picked) {
    var s = open('visible');
    try {
      var ids = s.query(def.pick).rows.map(function (r) { return r[0]; }), set = {};
      ids.forEach(function (id) { set[id] = 1; });
      var got = {}; (picked || []).forEach(function (id) { got[id] = 1; });
      var good = ids.filter(function (id) { return got[id]; }).length, extra = Object.keys(got).filter(function (id) { return !set[id]; }).length, miss = ids.length - good;
      var ok = !extra && !miss;
      return { passed: ok, failed: ok ? [] : ['La bonne prédiction'], criteria: [{ label: 'La bonne prédiction', ok: ok, detail: ok ? 'Exactement les ' + plural(ids.length, 'ligne', 'lignes') + '.' : plural(good, 'juste', 'justes') + ', ' + extra + ' en trop, ' + plural(miss, 'oubliée', 'oubliées') + '.' }], shown: { kind: 'pick', ids: ids, good: good, extra: extra, miss: miss } };
    } finally { s.close(); }
  }
  function checkValue(def, typed) {
    var s = open('visible');
    try {
      var v = s.query(def.value).rows[0], want = v ? v[0] : null;
      var a = String(typed == null ? '' : typed).trim().replace(',', '.'), ok;
      if (typeof want === 'number') ok = a !== '' && !isNaN(+a) && Math.abs(+a - want) < 0.01;
      else ok = a.toLowerCase() === String(want).toLowerCase();
      return { passed: ok, failed: ok ? [] : ['La bonne valeur'], criteria: [{ label: 'La bonne valeur', ok: ok, detail: ok ? 'C\'est bien ' + norm(want) + '.' : 'Ce n\'est pas ' + (a || 'vide') + '. Exécute la requête dans ta tête, ligne par ligne.' }], shown: { kind: 'value', want: want } };
    } finally { s.close(); }
  }

  function finish(crit, shown) {
    var failed = crit.filter(function (c) { return !c.ok; }).map(function (c) { return c.label; });
    return { passed: !failed.length, failed: failed.slice(0, 30), criteria: crit, shown: shown };
  }

  function check(def, input) {
    switch (def.check) {
      case 'modify': return checkModify(def, input);
      case 'create': return checkCreate(def, input);
      case 'pick': return checkPick(def, input);
      case 'value': return checkValue(def, input);
      default: return checkSelect(def, input);
    }
  }

  /* The console: a query on a fresh visible base, its last result or its error. After a modification,
     the tables it changed are shown, since a modification shows nothing by itself. */
  function result(text, tables) {
    var r = runOn('visible', text);
    try {
      var rep = r.report, sel = lastSelect(rep);
      var out = { error: rep.error, select: sel, changes: rep.steps.reduce(function (a, st) { return a + (st.changes || 0); }, 0), modified: rep.steps.some(function (st) { return st.kind === 'modify'; }), rolledBack: rep.rolledBack };
      if (!sel && tables && tables.length) out.after = snapshot(r.session, tables);
      if (!sel && !tables) out.tables = r.session.tables();
      return out;
    } finally { r.session.close(); }
  }

  root.SPPEngine = { ready: ready, open: open, check: check, result: result, compare: compare, orderKeys: orderKeys, explain: explain, norm: norm, rowDiff: rowDiff };
})(typeof globalThis !== 'undefined' ? globalThis : window);
