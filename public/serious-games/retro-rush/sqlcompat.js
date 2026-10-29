/* Rétro Rush — the MySQL the exam writes, run by SQLite (sql.js).

   The student types MySQL, the syntax of the BTS written exam; the game's engine is SQLite in the
   browser. This module closes the gap on what the exam asks, and refuses with a clear message what
   it cannot translate - it never guesses:

   - split(text): the statements of a script, `DELIMITER` included, a trigger's BEGIN … END kept whole;
   - translate(stmt): one MySQL statement as the SQLite statements that do the same
     (START TRANSACTION, CREATE TABLE types and AUTO_INCREMENT, ENUM, GROUP_CONCAT … SEPARATOR,
     SHOW TABLES, DESCRIBE, ALTER TABLE … ADD CONSTRAINT through a table rebuild, CREATE TRIGGER);
   - groupByProblem(sql, schema): what MySQL 8's ONLY_FULL_GROUP_BY would refuse, which SQLite accepts;
   - frenchError(message): SQLite's error in the words of the game.

   A MySQL trigger becomes one or two SQLite triggers. Its variables, its IF conditions and the values
   it gives NEW live in the hidden table _rr_vars, evaluated once, in order, as MySQL would: SQLite
   triggers have no variables, and re-evaluating a condition after an UPDATE of the same body would
   read a changed row. `SIGNAL` becomes RAISE(ABORT) behind its condition; `SET NEW.col` of a BEFORE
   trigger is kept in _rr_vars and written by a companion AFTER trigger (SQLite cannot change NEW). */
(function (root) {
  'use strict';

  function Refused(message) { var e = new Error(message); e.refused = true; return e; }

  // ——— Tokens ———
  var WORD = /[A-Za-z_À-ÿ][A-Za-z0-9_$À-ÿ]*/y;
  function tokenize(text) {
    var out = [], i = 0, n = text.length;
    while (i < n) {
      var c = text[i];
      if (/\s/.test(c)) { i++; continue; }
      if (c === '-' && text[i + 1] === '-') { while (i < n && text[i] !== '\n') i++; continue; }
      if (c === '#') { while (i < n && text[i] !== '\n') i++; continue; }
      if (c === '/' && text[i + 1] === '*') { var e = text.indexOf('*/', i + 2); i = e === -1 ? n : e + 2; continue; }
      var s = i;
      if (c === "'" || c === '"' || c === '`') {
        i++;
        while (i < n) {
          if (text[i] === '\\' && c !== '`') { i += 2; continue; }
          if (text[i] === c) { if (text[i + 1] === c) { i += 2; continue; } i++; break; }
          i++;
        }
        out.push({ t: c === '`' ? 'id' : 'str', v: text.slice(s, i), s: s, e: i });
        continue;
      }
      if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(text[i + 1] || ''))) {
        while (i < n && /[0-9.eE]/.test(text[i])) i++;
        out.push({ t: 'num', v: text.slice(s, i), s: s, e: i });
        continue;
      }
      WORD.lastIndex = i;
      var m = WORD.exec(text);
      if (m) { i += m[0].length; out.push({ t: 'w', v: m[0], up: m[0].toUpperCase(), s: s, e: i }); continue; }
      var two = text.substr(i, 2);
      if (['<=', '>=', '<>', '!=', '||', '::'].indexOf(two) !== -1) { i += 2; out.push({ t: 'p', v: two, s: s, e: i }); continue; }
      i++;
      out.push({ t: 'p', v: c, s: s, e: i });
    }
    return out;
  }
  function join(tokens) {
    var out = '';
    tokens.forEach(function (tk, k) {
      var prev = tokens[k - 1];
      var tight = !prev || tk.v === '.' || prev.v === '.' || tk.v === ',' || tk.v === ')' || prev.v === '(' || (tk.v === '(' && prev.t === 'w' && !KEYWORDS[prev.up]);
      out += (tight ? '' : ' ') + tk.v;
    });
    return out;
  }
  var KEYWORDS = {};
  ('SELECT FROM WHERE AND OR NOT IN IS NULL AS ON JOIN LEFT RIGHT INNER OUTER FULL CROSS GROUP BY HAVING ORDER ASC DESC LIMIT OFFSET ' +
    'UNION ALL DISTINCT CASE WHEN THEN ELSE END EXISTS BETWEEN LIKE ESCAPE INSERT INTO VALUES UPDATE SET DELETE CREATE TABLE VIEW ' +
    'INDEX TRIGGER BEFORE AFTER FOR EACH ROW BEGIN IF ELSEIF DECLARE DEFAULT SIGNAL SQLSTATE PRIMARY KEY FOREIGN REFERENCES ' +
    'CONSTRAINT CHECK UNIQUE DROP ALTER ADD COLUMN TRUE FALSE NEW OLD WITH RECURSIVE USING NATURAL REPLACE IGNORE TO').split(' ').forEach(function (k) { KEYWORDS[k] = true; });
  var AGGREGATES = { COUNT: 1, SUM: 1, AVG: 1, MIN: 1, MAX: 1, GROUP_CONCAT: 1 };

  // ——— Splitting a script ———
  function split(text) {
    var stmts = [], delim = ';', buf = '', line = 1, startLine = 1, i = 0, n = text.length;
    var quote = null, depth = 0, trigger = false, atLineStart = true;
    function flush() {
      var s = buf.trim();
      if (s) stmts.push({ sql: s, line: startLine });
      buf = ''; depth = 0; trigger = false;
    }
    while (i < n) {
      var c = text[i];
      if (!quote && atLineStart) {
        var rest = text.slice(i), lm = /^[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/i.exec(rest);
        if (lm) { if (buf.trim()) flush(); delim = lm[1]; i += lm[0].length; line++; atLineStart = true; continue; }
      }
      if (!buf.trim()) startLine = line;
      if (quote) {
        buf += c;
        if (c === '\\' && quote !== '`') { buf += text[i + 1] || ''; i += 2; continue; }
        if (c === quote) quote = null;
        if (c === '\n') line++;
        i++; atLineStart = c === '\n'; continue;
      }
      if (c === "'" || c === '"' || c === '`') { quote = c; buf += c; i++; atLineStart = false; continue; }
      if (c === '-' && text[i + 1] === '-') { while (i < n && text[i] !== '\n') i++; continue; }
      if (c === '#') { while (i < n && text[i] !== '\n') i++; continue; }
      if (c === '/' && text[i + 1] === '*') { var e = text.indexOf('*/', i + 2); var chunk = text.slice(i, e === -1 ? n : e + 2); line += (chunk.match(/\n/g) || []).length; i = e === -1 ? n : e + 2; continue; }
      if (/[A-Za-z_]/.test(c) && !/[A-Za-z0-9_]/.test(buf.slice(-1))) {
        var w = /^[A-Za-z_][A-Za-z0-9_]*/.exec(text.slice(i))[0], up = w.toUpperCase();
        buf += w; i += w.length; atLineStart = false;
        if (!trigger && /^\s*CREATE\s+(DEFINER\s*=\s*\S+\s+)?TRIGGER\b/i.test(buf)) trigger = true;
        if (trigger && up === 'BEGIN') depth++;
        if (trigger && up === 'END') {
          var after = /^\s*([A-Za-z_]+)/.exec(text.slice(i));
          if (!after || ['IF', 'LOOP', 'WHILE', 'CASE', 'REPEAT'].indexOf(after[1].toUpperCase()) === -1) depth = Math.max(0, depth - 1);
        }
        continue;
      }
      if (text.substr(i, delim.length) === delim && !(delim === ';' && trigger && depth > 0)) {
        i += delim.length; flush(); atLineStart = false; continue;
      }
      buf += c;
      if (c === '\n') { line++; atLineStart = true; } else if (!/[ \t\r]/.test(c)) atLineStart = false;
      i++;
    }
    flush();
    return stmts;
  }

  // ——— One statement ———
  var MYSQL_ONLY = [
    [/^\s*CREATE\s+(DEFINER\s*=\s*\S+\s+)?(PROCEDURE|FUNCTION)\b/i, 'Les procédures et fonctions stockées sont du MySQL que SQLite, le moteur du jeu, n\'exécute pas : ce ticket se lit et s\'explique.'],
    [/^\s*(CALL)\b/i, 'Pas de procédure stockée dans le moteur du jeu : CALL ne s\'exécute pas ici.'],
    [/^\s*(GRANT|REVOKE)\b/i, 'Les droits (GRANT, REVOKE) se lisent et s\'expliquent dans ce jeu : SQLite n\'a pas de comptes.'],
    [/^\s*(CREATE|DROP|ALTER)\s+USER\b/i, 'Les comptes MySQL n\'existent pas dans le moteur du jeu.'],
    [/^\s*USE\b/i, 'Une seule base dans le jeu : pas besoin de USE.'],
    [/^\s*(CREATE|DROP)\s+(DATABASE|SCHEMA)\b/i, 'Une seule base dans le jeu : on ne la crée ni ne la supprime.'],
    [/^\s*ALTER\s+TABLE\s+\S+\s+(MODIFY|CHANGE)\b/i, 'ALTER TABLE … MODIFY / CHANGE n\'est pas pris en charge par le moteur du jeu.'],
    [/^\s*LOCK\s+TABLES?\b/i, 'LOCK TABLES n\'existe pas dans le moteur du jeu : une transaction suffit ici.']
  ];

  function translate(stmt) {
    var sql = stmt.replace(/;\s*$/, '').trim();
    for (var k = 0; k < MYSQL_ONLY.length; k++) if (MYSQL_ONLY[k][0].test(sql)) throw Refused(MYSQL_ONLY[k][1]);
    var head = (/^\s*([A-Za-z]+)/.exec(sql) || [, ''])[1].toUpperCase();
    if (/^START\s+TRANSACTION$/i.test(sql) || /^BEGIN(\s+WORK)?$/i.test(sql)) return { kind: 'tx', sqlite: ['BEGIN'] };
    if (/^(COMMIT|ROLLBACK)(\s+WORK)?$/i.test(sql)) return { kind: 'tx', sqlite: [sql.split(/\s+/)[0].toUpperCase()] };
    if (/^SET\s+(AUTOCOMMIT|NAMES|FOREIGN_KEY_CHECKS|SQL_MODE)\b/i.test(sql)) return { kind: 'other', sqlite: [] };
    if (/^SHOW\s+TABLES$/i.test(sql)) return { kind: 'select', sqlite: ["SELECT name AS Tables_in_boutique FROM sqlite_master WHERE type = 'table' AND name NOT LIKE '\\_%' ESCAPE '\\' AND name NOT LIKE 'sqlite%' ORDER BY name"] };
    var d = /^(DESCRIBE|DESC|SHOW\s+COLUMNS\s+FROM)\s+`?(\w+)`?$/i.exec(sql);
    if (d) return { kind: 'select', sqlite: ["SELECT name AS Field, type AS Type, CASE WHEN \"notnull\" = 1 OR pk > 0 THEN 'NO' ELSE 'YES' END AS \"Null\", CASE WHEN pk > 0 THEN 'PRI' ELSE '' END AS \"Key\", dflt_value AS \"Default\" FROM pragma_table_info('" + d[2] + "')"] };
    if (/^SHOW\s+TRIGGERS$/i.test(sql)) return { kind: 'select', sqlite: ["SELECT name AS \"Trigger\", tbl_name AS \"Table\" FROM sqlite_master WHERE type = 'trigger' AND name NOT LIKE '%\\_\\_rr\\_after' ESCAPE '\\' ORDER BY name"] };
    if (/^CREATE\s+(DEFINER\s*=\s*\S+\s+)?TRIGGER\b/i.test(sql)) return translateTrigger(sql);
    var dt = /^DROP\s+TRIGGER\s+(IF\s+EXISTS\s+)?`?(\w+)`?$/i.exec(sql);
    if (dt) return { kind: 'ddl', sqlite: [sql, 'DROP TRIGGER IF EXISTS ' + dt[2] + '__rr_after'] };
    var orv = /^CREATE\s+OR\s+REPLACE\s+VIEW\s+`?(\w+)`?/i.exec(sql);
    if (orv) return { kind: 'ddl', sqlite: ['DROP VIEW IF EXISTS ' + orv[1], expressions(sql.replace(/^CREATE\s+OR\s+REPLACE\s+VIEW/i, 'CREATE VIEW'))] };
    if (/^CREATE\s+(TEMPORARY\s+)?TABLE\b/i.test(sql)) return { kind: 'ddl', sqlite: [createTable(sql)] };
    var alter = /^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(CONSTRAINT\s+`?(\w+)`?\s+)?(CHECK|UNIQUE|FOREIGN\s+KEY|PRIMARY\s+KEY)\b([\s\S]*)$/i.exec(sql);
    if (alter) {
      var kw = alter[4].toUpperCase().replace(/\s+/g, ' ');
      if (kw === 'UNIQUE') {
        var cols = /^\s*(KEY|INDEX)?\s*`?\w*`?\s*\(([^)]*)\)\s*$/i.exec(alter[5]);
        if (!cols) throw Refused('Écris la contrainte sous la forme : ALTER TABLE t ADD CONSTRAINT nom UNIQUE (colonne, …).');
        return { kind: 'ddl', sqlite: ['CREATE UNIQUE INDEX ' + (alter[3] || ('uq_' + alter[1] + '_' + cols[2].replace(/\W+/g, '_'))) + ' ON ' + alter[1] + ' (' + cols[2] + ')'] };
      }
      return { kind: 'ddl', rebuild: { table: alter[1], constraint: (alter[3] ? 'CONSTRAINT ' + alter[3] + ' ' : '') + alter[4] + alter[5] } };
    }
    var idx = /^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(UNIQUE\s+)?(INDEX|KEY)\s+`?(\w+)`?\s*\(([^)]*)\)$/i.exec(sql);
    if (idx) return { kind: 'ddl', sqlite: ['CREATE ' + (idx[2] ? 'UNIQUE ' : '') + 'INDEX ' + idx[4] + ' ON ' + idx[1] + ' (' + idx[5] + ')'] };
    var dropIdx = /^DROP\s+INDEX\s+`?(\w+)`?\s+ON\s+`?\w+`?$/i.exec(sql);
    if (dropIdx) return { kind: 'ddl', sqlite: ['DROP INDEX ' + dropIdx[1]] };
    var body = expressions(sql);
    var kind = head === 'SELECT' || head === 'WITH' || head === 'VALUES' || head === 'EXPLAIN' ? 'select' : (['INSERT', 'UPDATE', 'DELETE', 'REPLACE'].indexOf(head) !== -1 ? 'modify' : 'ddl');
    return { kind: kind, sqlite: [body] };
  }

  // MySQL functions and spellings inside an expression or a query.
  function expressions(sql) {
    return sql
      .replace(/GROUP_CONCAT\s*\(([\s\S]*?)\s+SEPARATOR\s+('(?:[^']|'')*')\s*\)/gi, 'GROUP_CONCAT($1, $2)')
      .replace(/\bCURRENT_DATE\b(?!\s*\()/gi, 'CURDATE()')
      .replace(/\bCURRENT_TIMESTAMP\b(?!\s*\()/gi, 'NOW()')
      .replace(/\bLOCK\s+IN\s+SHARE\s+MODE\b|\bFOR\s+UPDATE\b\s*$/gi, '');
  }

  function createTable(sql) {
    var out = sql;
    out = out.replace(/\)\s*(ENGINE|DEFAULT\s+CHARSET|CHARSET|COLLATE|AUTO_INCREMENT)\b[\s\S]*$/i, ')');
    out = out.replace(/`?(\w+)`?\s+ENUM\s*\(([^)]*)\)/gi, function (m, col, list) { return col + ' TEXT COLLATE NOCASE CHECK (' + col + ' IN (' + list + '))'; });
    out = out.replace(/\b(TINYINT|SMALLINT|MEDIUMINT|BIGINT|INT|INTEGER)(\s*\(\s*\d+\s*\))?(\s+UNSIGNED)?(?=[^,()]*\bAUTO_INCREMENT\b)/gi, 'INTEGER');
    out = out.replace(/\s+AUTO_INCREMENT\b/gi, '');
    out = out.replace(/\s+UNSIGNED\b/gi, '');
    out = out.replace(/\b(DECIMAL|NUMERIC|DOUBLE(\s+PRECISION)?|FLOAT)\s*(\(\s*\d+\s*(,\s*\d+\s*)?\))?/gi, 'REAL');
    out = out.replace(/\b(VARCHAR|CHAR)\s*\(\s*\d+\s*\)(?!\s+COLLATE)/gi, '$& COLLATE NOCASE');
    out = out.replace(/\bTEXT\b(?!\s+COLLATE)/gi, 'TEXT COLLATE NOCASE');
    return out;
  }

  // ——— Triggers ———
  function translateTrigger(sql) {
    var tk = tokenize(sql), p = 0;
    function peek(k) { return tk[p + (k || 0)]; }
    function up(k) { var t = peek(k); return t && t.t === 'w' ? t.up : (t ? t.v : ''); }
    function expect(word) { if (up() !== word) throw Refused('Trigger : « ' + word + ' » attendu près de « ' + (peek() ? peek().v : 'la fin') + ' ».'); p++; }
    function name() { var t = tk[p++]; if (!t || (t.t !== 'w' && t.t !== 'id')) throw Refused('Trigger : un nom est attendu.'); return t.v.replace(/`/g, ''); }
    expect('CREATE');
    if (up() === 'DEFINER') { while (p < tk.length && up() !== 'TRIGGER') p++; }
    expect('TRIGGER');
    var trg = name();
    var timing = up(); if (timing !== 'BEFORE' && timing !== 'AFTER') throw Refused('Trigger : BEFORE ou AFTER attendu après le nom.'); p++;
    var event = up(); if (['INSERT', 'UPDATE', 'DELETE'].indexOf(event) === -1) throw Refused('Trigger : INSERT, UPDATE ou DELETE attendu après ' + timing + '.'); p++;
    expect('ON');
    var table = name();
    expect('FOR'); expect('EACH'); expect('ROW');
    if (up() === 'FOLLOWS' || up() === 'PRECEDES') { p += 2; }
    var bodyTokens = tk.slice(p);
    if (bodyTokens.length && bodyTokens[0].up === 'BEGIN') {
      if (!bodyTokens.length || bodyTokens[bodyTokens.length - 1].up !== 'END') throw Refused('Trigger : le BEGIN n\'est pas fermé par END.');
      bodyTokens = bodyTokens.slice(1, -1);
    }
    var usesOld = bodyTokens.some(function (t, i) { return t.up === 'OLD' && bodyTokens[i + 1] && bodyTokens[i + 1].v === '.'; });
    var usesNew = bodyTokens.some(function (t, i) { return t.up === 'NEW' && bodyTokens[i + 1] && bodyTokens[i + 1].v === '.'; });
    if (event === 'INSERT' && usesOld) throw Refused('Il n\'y a pas de ligne OLD dans un trigger d\'insertion : la ligne n\'existait pas avant. Utilise NEW.');
    if (event === 'DELETE' && usesNew) throw Refused('Il n\'y a pas de ligne NEW dans un trigger de suppression : la ligne disparaît. Utilise OLD.');

    var ctx = { trg: trg, vars: {}, assigned: {}, seq: 0, out: [], timing: timing, event: event, table: table };
    var stmts = parseBody(bodyTokens);
    // Every variable starts at its DEFAULT (or NULL), every assigned NEW column at the inserted value.
    collectDeclares(stmts, ctx);
    collectAssigned(stmts, ctx);
    if (Object.keys(ctx.assigned).length && timing === 'AFTER') throw Refused('On ne peut plus modifier NEW dans un trigger AFTER : la ligne est déjà écrite. Il faut un trigger BEFORE.');
    Object.keys(ctx.vars).forEach(function (v) { ctx.out.push(setVar(ctx, 'var:' + v, ctx.vars[v].dflt ? rewrite(ctx.vars[v].dflt, ctx) : 'NULL')); });
    Object.keys(ctx.assigned).forEach(function (c) { ctx.out.push(setVar(ctx, 'new:' + c, 'NEW.' + c)); });
    var declared = {};
    Object.keys(ctx.vars).forEach(function (v) { declared[v] = true; });
    ctx.live = { vars: declared, assigned: {} };
    emit(stmts, null, ctx);
    var main = 'CREATE TRIGGER ' + trg + ' ' + timing + ' ' + event + ' ON ' + table + ' FOR EACH ROW BEGIN ' + (ctx.out.length ? ctx.out.join('; ') + ';' : 'SELECT 1;') + ' END';
    var list = [main];
    var cols = Object.keys(ctx.assigned);
    if (cols.length) {
      list.push('CREATE TRIGGER ' + trg + '__rr_after AFTER ' + event + ' ON ' + table + ' FOR EACH ROW BEGIN UPDATE ' + table + ' SET ' +
        cols.map(function (c) { return c + ' = ' + lookup(ctx, 'new:' + c); }).join(', ') + ' WHERE rowid = NEW.rowid; END');
    }
    return { kind: 'trigger', sqlite: list, trigger: { name: trg, timing: timing, event: event, table: table } };
  }

  function key(ctx, k) { return "'" + ctx.trg + ':' + k.replace(/'/g, "''") + "'"; }
  function lookup(ctx, k) { return '(SELECT v FROM _rr_vars WHERE k = ' + key(ctx, k) + ')'; }
  function setVar(ctx, k, expr, guard) { return 'INSERT OR REPLACE INTO _rr_vars (k, v) SELECT ' + key(ctx, k) + ', (' + expr + ')' + (guard ? ' WHERE ' + guard : ''); }

  // Body statements: split on top-level ';', IF … END IF nested.
  function parseBody(tokens) {
    var i = 0;
    function until(stops) {
      var list = [];
      while (i < tokens.length) {
        var t = tokens[i];
        if (t.v === ';') { i++; continue; }
        if (t.up !== 'END' && stops.indexOf(t.up) !== -1) return list;
        if (stops.indexOf('ENDIF') !== -1 && t.up === 'END' && tokens[i + 1] && tokens[i + 1].up === 'IF') return list;
        list.push(statement());
      }
      return list;
    }
    function statement() {
      var t = tokens[i];
      if (t.up === 'IF') {
        i++;
        var branches = [], cond = upTo('THEN');
        i++;
        var body = until(['ELSEIF', 'ELSE', 'ENDIF']);
        branches.push({ cond: cond, body: body });
        var other = null;
        while (i < tokens.length) {
          var u = tokens[i].up;
          if (u === 'ELSEIF') { i++; var c2 = upTo('THEN'); i++; branches.push({ cond: c2, body: until(['ELSEIF', 'ELSE', 'ENDIF']) }); continue; }
          if (u === 'ELSE') { i++; other = until(['ENDIF']); continue; }
          if (u === 'END' && tokens[i + 1] && tokens[i + 1].up === 'IF') { i += 2; break; }
          throw Refused('Trigger : IF sans END IF.');
        }
        if (tokens[i] && tokens[i].v === ';') i++;
        return { type: 'if', branches: branches, other: other };
      }
      if (t.up === 'BEGIN') {
        i++;
        var inner = [];
        while (i < tokens.length && tokens[i].up !== 'END') { if (tokens[i].v === ';') { i++; continue; } inner.push(statement()); }
        i++;
        if (tokens[i] && tokens[i].v === ';') i++;
        return { type: 'block', body: inner };
      }
      var start = i, depth = 0;
      while (i < tokens.length) {
        var v = tokens[i].v;
        if (v === '(') depth++;
        if (v === ')') depth--;
        if (v === ';' && depth === 0) break;
        i++;
      }
      var toks = tokens.slice(start, i);
      if (tokens[i] && tokens[i].v === ';') i++;
      return simple(toks);
    }
    function upTo(word) {
      var start = i, depth = 0;
      while (i < tokens.length && !(depth === 0 && tokens[i].up === word)) { if (tokens[i].v === '(') depth++; if (tokens[i].v === ')') depth--; i++; }
      if (i >= tokens.length) throw Refused('Trigger : « ' + word + ' » manquant après une condition IF.');
      return tokens.slice(start, i);
    }
    return until(['__NONE__']);
  }

  function simple(toks) {
    var u = toks[0].up;
    if (u === 'DECLARE') {
      var names = [], j = 1;
      while (j < toks.length && (toks[j].t === 'w' || toks[j].t === 'id')) { names.push(toks[j].v.replace(/`/g, '')); j++; if (toks[j] && toks[j].v === ',') j++; else break; }
      var dIdx = -1;
      for (var k = j; k < toks.length; k++) if (toks[k].up === 'DEFAULT') { dIdx = k; break; }
      return { type: 'declare', names: names, dflt: dIdx === -1 ? null : toks.slice(dIdx + 1) };
    }
    if (u === 'SET') {
      var parts = splitTop(toks.slice(1), ','), sets = [];
      parts.forEach(function (pt) {
        var eq = -1;
        for (var q = 0; q < pt.length; q++) if (pt[q].v === '=' || pt[q].v === ':=') { eq = q; break; }
        if (eq === -1) throw Refused('Trigger : SET sans « = ».');
        var target = pt.slice(0, eq);
        var tgt = target.length === 3 && target[0].up === 'NEW' && target[1].v === '.' ? { col: target[2].v.replace(/`/g, '') } :
          (target.length === 1 ? { variable: target[0].v.replace(/^@/, '').replace(/`/g, '') } : null);
        if (target.length === 3 && target[0].up === 'OLD') throw Refused('OLD est en lecture seule : on ne peut pas modifier l\'ancienne ligne.');
        if (!tgt) throw Refused('Trigger : on ne peut affecter qu\'une variable ou NEW.colonne.');
        sets.push({ target: tgt, expr: pt.slice(eq + 1) });
      });
      return { type: 'set', sets: sets };
    }
    if (u === 'SIGNAL') {
      var msg = null;
      for (var m = 0; m < toks.length; m++) if (toks[m].up === 'MESSAGE_TEXT') { msg = toks.slice(m + 2); break; }
      return { type: 'signal', message: msg };
    }
    if (u === 'SELECT') {
      var into = -1, depth = 0;
      for (var a = 0; a < toks.length; a++) { if (toks[a].v === '(') depth++; if (toks[a].v === ')') depth--; if (depth === 0 && toks[a].up === 'INTO') { into = a; break; } }
      if (into === -1) throw Refused('Trigger : un SELECT dans un trigger doit ranger son résultat dans une variable (SELECT … INTO variable).');
      var exprs = splitTop(toks.slice(1, into), ','), b = into + 1, vars = [];
      while (b < toks.length && (toks[b].t === 'w' || toks[b].t === 'id') && toks[b].up !== 'FROM') { vars.push(toks[b].v.replace(/^@/, '').replace(/`/g, '')); b++; if (toks[b] && toks[b].v === ',') b++; else break; }
      if (vars.length !== exprs.length) throw Refused('Trigger : autant de variables que de valeurs dans SELECT … INTO.');
      return { type: 'selectinto', exprs: exprs, vars: vars, rest: toks.slice(b) };
    }
    if (u === 'UPDATE' || u === 'DELETE' || u === 'INSERT' || u === 'REPLACE') return { type: 'dml', verb: u, tokens: toks };
    if (u === 'LEAVE' || u === 'ITERATE' || u === 'LOOP' || u === 'WHILE' || u === 'REPEAT' || u === 'CURSOR' || u === 'OPEN' || u === 'FETCH' || u === 'CLOSE')
      throw Refused('Les boucles et curseurs (' + u + ') ne sont pas pris en charge par le traducteur de triggers du jeu.');
    throw Refused('Trigger : instruction non prise en charge, « ' + toks.map(function (x) { return x.v; }).slice(0, 4).join(' ') + ' … ».');
  }
  function splitTop(toks, sep) {
    var parts = [[]], depth = 0;
    toks.forEach(function (t) {
      if (t.v === '(') depth++;
      if (t.v === ')') depth--;
      if (depth === 0 && t.v === sep) { parts.push([]); return; }
      parts[parts.length - 1].push(t);
    });
    return parts.filter(function (x) { return x.length; });
  }
  function walk(stmts, fn) {
    stmts.forEach(function (s) {
      fn(s);
      if (s.type === 'if') { s.branches.forEach(function (b) { walk(b.body, fn); }); if (s.other) walk(s.other, fn); }
      if (s.type === 'block') walk(s.body, fn);
    });
  }
  function collectDeclares(stmts, ctx) {
    walk(stmts, function (s) { if (s.type === 'declare') s.names.forEach(function (n) { ctx.vars[n.toUpperCase()] = { name: n, dflt: s.dflt }; }); });
  }
  function collectAssigned(stmts, ctx) {
    walk(stmts, function (s) { if (s.type === 'set') s.sets.forEach(function (x) { if (x.target.col) ctx.assigned[x.target.col] = true; }); });
  }
  // Variables and assigned NEW columns read from _rr_vars; MySQL functions as for any query.
  function rewrite(toks, ctx) {
    var out = [];
    for (var i = 0; i < toks.length; i++) {
      var t = toks[i], prev = toks[i - 1];
      if (t.up === 'NEW' && toks[i + 1] && toks[i + 1].v === '.' && toks[i + 2] && ctx.live && ctx.live.assigned[toks[i + 2].v.replace(/`/g, '')]) {
        out.push({ t: 'x', v: lookup(ctx, 'new:' + toks[i + 2].v.replace(/`/g, '')) }); i += 2; continue;
      }
      if (t.t === 'w' && !(prev && prev.v === '.') && ctx.live && ctx.live.vars[t.up.replace(/^@/, '')] && !(toks[i + 1] && toks[i + 1].v === '(')) {
        out.push({ t: 'x', v: lookup(ctx, 'var:' + t.up) }); continue;
      }
      if (t.v === '@' && toks[i + 1] && ctx.live && ctx.live.vars[toks[i + 1].up]) { out.push({ t: 'x', v: lookup(ctx, 'var:' + toks[i + 1].up) }); i++; continue; }
      out.push(t);
    }
    return expressions(join(out));
  }
  function emit(stmts, guard, ctx) {
    stmts.forEach(function (s) {
      if (s.type === 'declare') return;
      if (s.type === 'block') { emit(s.body, guard, ctx); return; }
      if (s.type === 'set') {
        s.sets.forEach(function (x) {
          if (x.target.col) { ctx.out.push(setVar(ctx, 'new:' + x.target.col, rewrite(x.expr, ctx), guard)); ctx.live.assigned[x.target.col] = true; }
          else {
            var vn = x.target.variable.toUpperCase();
            if (!ctx.vars[vn]) { ctx.vars[vn] = { name: x.target.variable, dflt: null }; ctx.out.unshift(setVar(ctx, 'var:' + vn, 'NULL')); ctx.live.vars[vn] = true; }
            ctx.out.push(setVar(ctx, 'var:' + vn, rewrite(x.expr, ctx), guard));
          }
        });
        return;
      }
      if (s.type === 'selectinto') {
        var rest = rewrite(s.rest, ctx);
        s.vars.forEach(function (v, k) {
          var vn = v.toUpperCase();
          if (!ctx.vars[vn]) throw Refused('Trigger : la variable ' + v + ' n\'est pas déclarée (DECLARE ' + v + ' …).');
          ctx.out.push(setVar(ctx, 'var:' + vn, 'SELECT ' + rewrite(s.exprs[k], ctx) + ' ' + rest, guard));
        });
        return;
      }
      if (s.type === 'signal') {
        var msg = s.message ? rewrite(s.message, ctx) : "'Refusé par le trigger " + ctx.trg + "'";
        ctx.out.push('SELECT RAISE(ABORT, ' + (/^'(?:[^']|'')*'$/.test(msg) ? msg : "'Refusé par le trigger " + ctx.trg + "'") + ')' + (guard ? ' WHERE ' + guard : ''));
        return;
      }
      if (s.type === 'dml') { ctx.out.push(guardDml(s, guard, ctx)); return; }
      if (s.type === 'if') {
        var taken = [];
        s.branches.forEach(function (b) {
          var g = 'g' + (++ctx.seq);
          var cond = '(COALESCE((' + rewrite(b.cond, ctx) + '), 0) <> 0)';
          var full = [guard ? guard : null].concat(taken.map(function (x) { return 'NOT ' + lookup(ctx, x); })).concat([cond]).filter(Boolean).join(' AND ');
          ctx.out.push(setVar(ctx, g, 'CASE WHEN ' + full + ' THEN 1 ELSE 0 END'));
          taken.push(g);
          emit(b.body, lookup(ctx, g) + ' = 1', ctx);
        });
        if (s.other) {
          var ge = 'g' + (++ctx.seq);
          var none = [guard ? guard : null].concat(taken.map(function (x) { return lookup(ctx, x) + ' = 0'; })).filter(Boolean).join(' AND ');
          ctx.out.push(setVar(ctx, ge, 'CASE WHEN ' + none + ' THEN 1 ELSE 0 END'));
          emit(s.other, lookup(ctx, ge) + ' = 1', ctx);
        }
      }
    });
  }
  function guardDml(s, guard, ctx) {
    var toks = s.tokens, text;
    if (s.verb === 'UPDATE' || s.verb === 'DELETE') {
      var w = -1, depth = 0, tail = toks.length;
      for (var i = 0; i < toks.length; i++) {
        if (toks[i].v === '(') depth++; if (toks[i].v === ')') depth--;
        if (depth === 0 && toks[i].up === 'WHERE' && w === -1) w = i;
        if (depth === 0 && (toks[i].up === 'ORDER' || toks[i].up === 'LIMIT')) { tail = i; break; }
      }
      if (!guard) return rewrite(toks, ctx);
      if (w === -1) text = rewrite(toks.slice(0, tail), ctx) + ' WHERE ' + guard + (tail < toks.length ? ' ' + rewrite(toks.slice(tail), ctx) : '');
      else text = rewrite(toks.slice(0, w + 1), ctx) + ' (' + guard + ') AND (' + rewrite(toks.slice(w + 1, tail), ctx) + ')' + (tail < toks.length ? ' ' + rewrite(toks.slice(tail), ctx) : '');
      return text;
    }
    // INSERT: VALUES (…), (…) becomes SELECT … WHERE guard UNION ALL …
    if (!guard) return rewrite(toks, ctx);
    var vIdx = -1, d2 = 0;
    for (var j = 0; j < toks.length; j++) { if (toks[j].v === '(') d2++; if (toks[j].v === ')') d2--; if (d2 === 0 && (toks[j].up === 'VALUES' || toks[j].up === 'VALUE' || toks[j].up === 'SELECT')) { vIdx = j; break; } }
    if (vIdx === -1) throw Refused('Trigger : INSERT sans VALUES ni SELECT.');
    var headText = rewrite(toks.slice(0, vIdx), ctx);
    if (toks[vIdx].up === 'SELECT') return headText + ' SELECT * FROM (' + rewrite(toks.slice(vIdx), ctx) + ') WHERE ' + guard;
    var rows = splitTop(toks.slice(vIdx + 1), ',').map(function (r) {
      var inner = r[0].v === '(' && r[r.length - 1].v === ')' ? r.slice(1, -1) : r;
      return 'SELECT ' + rewrite(inner, ctx) + ' WHERE ' + guard;
    });
    return headText + ' ' + rows.join(' UNION ALL ');
  }

  // ——— ONLY_FULL_GROUP_BY ———
  /* schema: { columns(table) -> [names], pk(table) -> [names] }. Returns null, or the message of the
     refusal MySQL 8 would give. Lenient where MySQL is: a column named in the GROUP BY under any
     qualifier, a column of a table whose primary key is grouped (functional dependency), a select
     item whose alias or whole expression is grouped. */
  function groupByProblem(sql, schema) {
    var toks = tokenize(sql);
    var problems = [];
    blocks(toks).forEach(function (b) { var pb = checkBlock(b, schema); if (pb) problems.push(pb); });
    return problems[0] || null;
  }
  // Every SELECT block of a statement, nested ones included, as its own token list (depth-relative).
  function blocks(toks) {
    var out = [], stack = [{ start: 0, toks: [] }];
    var levels = [[]];
    toks.forEach(function (t) {
      if (t.v === '(') { levels[levels.length - 1].push(t); levels.push([]); return; }
      if (t.v === ')') {
        var inner = levels.pop();
        if (inner.length && inner[0].up === 'SELECT') unionParts(inner).forEach(function (u) { out.push(u); });
        if (!levels.length) levels.push([]);
        levels[levels.length - 1].push({ t: 'sub', v: '(…)' });
        return;
      }
      levels[levels.length - 1].push(t);
    });
    var top = levels[0], s = 0;
    while (s < top.length && top[s].up !== 'SELECT') s++;
    if (s < top.length) unionParts(top.slice(s)).forEach(function (u) { out.push(u); });
    void stack;
    return out;
  }
  function unionParts(toks) {
    var parts = [[]];
    toks.forEach(function (t) { if (t.up === 'UNION' || t.up === 'INTERSECT' || t.up === 'EXCEPT') { parts.push([]); return; } if (t.up === 'ALL' && !parts[parts.length - 1].length) return; parts[parts.length - 1].push(t); });
    return parts.filter(function (p) { return p.length && p[0].up === 'SELECT'; });
  }
  function clause(toks, from, stops) {
    var s = -1;
    for (var i = 0; i < toks.length; i++) if (toks[i].up === from[0] && (!from[1] || (toks[i + 1] && toks[i + 1].up === from[1]))) { s = i + from.length; break; }
    if (s === -1) return null;
    var e = s;
    while (e < toks.length && !stops.some(function (st) { return toks[e].up === st[0] && (!st[1] || (toks[e + 1] && toks[e + 1].up === st[1])); })) e++;
    return toks.slice(s, e);
  }
  var STOPS = [['FROM'], ['WHERE'], ['GROUP', 'BY'], ['HAVING'], ['ORDER', 'BY'], ['LIMIT'], ['WINDOW']];
  function refs(toks) {
    var out = [];
    for (var i = 0; i < toks.length; i++) {
      var t = toks[i];
      if (t.t === 'w' && AGGREGATES[t.up] && toks[i + 1] && toks[i + 1].v === '(') {
        var depth = 0;
        for (i = i + 1; i < toks.length; i++) { if (toks[i].v === '(') depth++; if (toks[i].v === ')') { depth--; if (depth === 0) break; } }
        continue;
      }
      if (t.t === 'w' && toks[i + 1] && toks[i + 1].v === '(') continue;
      if ((t.t === 'w' && !KEYWORDS[t.up]) || t.t === 'id') {
        if (toks[i + 1] && toks[i + 1].v === '.' && toks[i + 2] && (toks[i + 2].t === 'w' || toks[i + 2].t === 'id' || toks[i + 2].v === '*')) {
          out.push({ q: clean(t.v), c: clean(toks[i + 2].v) }); i += 2; continue;
        }
        if (toks[i - 1] && toks[i - 1].v === '.') continue;
        out.push({ q: null, c: clean(t.v) });
      }
      if (t.v === '*' && !(toks[i - 1] && toks[i - 1].v === '.') && i === 0) out.push({ q: null, c: '*' });
    }
    return out;
  }
  function clean(v) { return String(v).replace(/[`"]/g, '').toLowerCase(); }
  function hasAggregate(toks) { return toks.some(function (t, i) { return t.t === 'w' && AGGREGATES[t.up] && toks[i + 1] && toks[i + 1].v === '('; }); }
  function checkBlock(toks, schema) {
    var start = 1;
    if (toks[1] && (toks[1].up === 'DISTINCT' || toks[1].up === 'ALL')) start = 2;
    var e = start;
    while (e < toks.length && toks[e].up !== 'FROM') e++;
    var items = splitTop(toks.slice(start, e), ',');
    var group = clause(toks, ['GROUP', 'BY'], STOPS.filter(function (s) { return s[0] !== 'GROUP'; })) || [];
    var aggregated = items.some(hasAggregate);
    if (!group.length && !aggregated) return null;
    // Aliases of the FROM clause, to their tables.
    var from = clause(toks, ['FROM'], STOPS.filter(function (s) { return s[0] !== 'FROM'; })) || [];
    var alias = {}, tables = [];
    for (var i = 0; i < from.length; i++) {
      var t = from[i];
      if ((t.t === 'w' || t.t === 'id') && !KEYWORDS[t.up] && (i === 0 || from[i - 1].v === ',' || from[i - 1].up === 'JOIN')) {
        var tn = clean(t.v), al = tn, nx = from[i + 1];
        if (nx && nx.up === 'AS') nx = from[i + 2];
        if (nx && (nx.t === 'w' || nx.t === 'id') && !KEYWORDS[nx.up]) al = clean(nx.v);
        alias[al] = tn; alias[tn] = tn; tables.push(tn);
      }
    }
    var groupParts = splitTop(group, ',');
    var groupRefs = refs(group);
    var groupTexts = groupParts.map(function (g) { return join(g).toLowerCase().replace(/\s+/g, ''); });
    function tableOf(r) {
      if (r.q) return alias[r.q] || r.q;
      var found = tables.filter(function (tb) { try { return (schema.columns(tb) || []).map(function (x) { return x.toLowerCase(); }).indexOf(r.c) !== -1; } catch (err) { return false; } });
      return found.length === 1 ? found[0] : null;
    }
    function grouped(r) {
      if (groupRefs.some(function (g) { return g.c === r.c && (!g.q || !r.q || (alias[g.q] || g.q) === (alias[r.q] || r.q)); })) return true;
      var tb = tableOf(r);
      if (!tb) return false;
      var pk = (schema.pk(tb) || []).map(function (x) { return x.toLowerCase(); });
      return pk.length > 0 && pk.every(function (k) { return groupRefs.some(function (g) { return g.c === k && (!g.q || (alias[g.q] || g.q) === tb); }); });
    }
    for (var k = 0; k < items.length; k++) {
      var item = items[k];
      var al2 = null, body = item;
      if (item.length >= 2 && (item[item.length - 1].t === 'w' || item[item.length - 1].t === 'id' || item[item.length - 1].t === 'str') && (item[item.length - 2].up === 'AS' || (item[item.length - 2].t !== 'p' && item[item.length - 2].v !== '.'))) {
        al2 = clean(item[item.length - 1].v).replace(/'/g, '');
        body = item[item.length - 2].up === 'AS' ? item.slice(0, -2) : item.slice(0, -1);
      }
      if (al2 && groupTexts.indexOf(al2) !== -1) continue;
      if (groupTexts.indexOf(join(body).toLowerCase().replace(/\s+/g, '')) !== -1) continue;
      var rs = refs(body);
      for (var r = 0; r < rs.length; r++) {
        if (rs[r].c === '*') return 'MySQL refuserait cette requête : SELECT * avec un regroupement affiche des colonnes qui ne sont ni dans le GROUP BY ni dans une fonction d\'agrégat (ONLY_FULL_GROUP_BY).';
        if (!grouped(rs[r])) {
          var label = (rs[r].q ? rs[r].q + '.' : '') + rs[r].c;
          return group.length ?
            'MySQL refuserait cette requête : la colonne ' + label + ' n\'est ni dans le GROUP BY ni dans une fonction d\'agrégat (ONLY_FULL_GROUP_BY).' :
            'MySQL refuserait cette requête : elle mélange une fonction d\'agrégat et la colonne ' + label + ' sans GROUP BY (ONLY_FULL_GROUP_BY).';
        }
      }
    }
    return null;
  }

  // ——— Errors in French ———
  var ERRORS = [
    [/no such table: (?:main\.)?(\S+)/, function (m) { return 'La table ' + m[1] + ' n\'existe pas.'; }],
    [/no such column: (\S+)/, function (m) { return 'La colonne ' + m[1] + ' n\'existe pas (ou son alias n\'est pas défini).'; }],
    [/ambiguous column name: (\S+)/, function (m) { return 'La colonne ' + m[1] + ' est ambiguë : plusieurs tables l\'ont, précise laquelle (alias.colonne).'; }],
    [/near "([^"]*)": syntax error/, function (m) { return 'Erreur de syntaxe près de « ' + m[1] + ' ».'; }],
    [/incomplete input/, function () { return 'La requête est incomplète : il manque la fin.'; }],
    [/unrecognized token: "?([^"]*)"?/, function (m) { return 'Symbole inconnu : ' + m[1] + ' (une apostrophe non fermée ?).'; }],
    [/UNIQUE constraint failed: (.+)/, function (m) { return 'Doublon refusé : ' + m[1] + ' doit être unique.'; }],
    [/FOREIGN KEY constraint failed/, function () { return 'Refusé par une clé étrangère : la ligne référencée n\'existe pas, ou d\'autres lignes dépendent de celle-ci.'; }],
    [/CHECK constraint failed: (.+)/, function (m) { return 'Refusé par la contrainte CHECK : ' + m[1] + '.'; }],
    [/NOT NULL constraint failed: (.+)/, function (m) { return m[1] + ' ne peut pas être vide (NOT NULL).'; }],
    [/misuse of aggregate(?: function)?:? (\w+)?/, function (m) { return 'Fonction d\'agrégat ' + (m[1] || '') + ' mal placée : on ne l\'utilise pas dans un WHERE (il faut un HAVING, ou une sous-requête).'; }],
    [/no such function: (\w+)/, function (m) { return 'La fonction ' + m[1] + ' n\'existe pas dans le moteur du jeu.'; }],
    [/wrong number of arguments to function (\w+)/, function (m) { return 'Mauvais nombre d\'arguments pour ' + m[1] + '.'; }],
    [/(table|index|view|trigger) (\S+) already exists/, function (m) { var w = { table: 'La table', index: 'L\'index', view: 'La vue', trigger: 'Le trigger' }[m[1]]; return w + ' ' + m[2] + ' existe déjà.'; }],
    [/do not have the same number of result columns/, function () { return 'Les SELECT d\'un UNION doivent avoir le même nombre de colonnes.'; }],
    [/sub-select returns (\d+) columns - expected 1/, function (m) { return 'La sous-requête renvoie ' + m[1] + ' colonnes au lieu d\'une seule.'; }],
    [/cannot start a transaction within a transaction/, function () { return 'Une transaction est déjà ouverte : termine-la (COMMIT ou ROLLBACK) avant d\'en ouvrir une autre.'; }],
    [/cannot (commit|rollback) - no transaction is active/, function (m) { return 'Aucune transaction ouverte : ' + m[1].toUpperCase() + ' n\'a rien à faire.'; }],
    [/view (\S+) may not be modified|cannot modify (\S+) because it is a view/, function () { return 'On ne modifie pas une vue directement : on modifie ses tables.'; }],
    [/datatype mismatch/, function () { return 'Type de valeur incompatible avec la colonne.'; }],
    [/1st ORDER BY term out of range|ORDER BY term out of range/, function () { return 'ORDER BY : ce numéro de colonne n\'existe pas.'; }],
    [/GROUP BY term out of range/, function () { return 'GROUP BY : ce numéro de colonne n\'existe pas.'; }],
    [/a GROUP BY clause is required before HAVING/, function () { return 'Un HAVING s\'utilise après un GROUP BY.'; }]
  ];
  function frenchError(message) {
    var msg = String(message || '').replace(/^Error: /, '');
    for (var i = 0; i < ERRORS.length; i++) { var m = ERRORS[i][0].exec(msg); if (m) return ERRORS[i][1](m); }
    return msg.replace(/^SQLITE_\w+: /, '');
  }

  root.RetroSQL = { split: split, translate: translate, groupByProblem: groupByProblem, frenchError: frenchError, tokenize: tokenize, Refused: Refused };
})(typeof globalThis !== 'undefined' ? globalThis : window);
