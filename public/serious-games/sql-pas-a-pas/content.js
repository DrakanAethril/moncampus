/* SQL pas à pas — the game's half of the levels (design/validated/serious-games-sql-pas-a-pas.md §3).

   The server's half lives in config/serious_games/sql-pas-a-pas/tickets/*.yaml: each level's steps in
   order, the questions it corrects itself (« Comprendre »: an explanation to choose, each wrong one a
   named misconception), and how many written hints it may give. This half holds what the browser
   measures: the lessons, the queries the student predicts, completes, repairs and writes, the reference
   each one is checked against on the visible base and the hidden one, the written hints, and the pools
   of the timed challenges. A step is found by its level and its identifier, never by its rank.

   SPPContent.level(def) / chrono(def)   register a level or a timed challenge (the chapter files do)
   SPPContent.get(id) · all() · chapters · notions · chipsFor(notions)
*/
(function (root) {
  'use strict';
  var levels = [], byId = {};

  var CHAPTERS = [
    { n: 1, title: 'Lire une table', time: '1 h 15' },
    { n: 2, title: 'Combiner les conditions', time: '1 h' },
    { n: 3, title: 'Valeurs manquantes et calculs', time: '1 h' },
    { n: 4, title: 'Compter et regrouper', time: '1 h 15' },
    { n: 5, title: 'Relier les tables', time: '1 h 45' },
    { n: 6, title: 'Modifier les données', time: '50 min' },
    { n: 7, title: 'Créer sa base', time: '1 h 15' },
    { n: 8, title: 'Bonus : sécurité', time: '45 min', bonus: true }
  ];

  /* The notions of the showcase, chapter by chapter. A notion is « vue » once one of its lessons was
     opened, « pratiquée » once a level that teaches it is done, « maîtrisée » once the timed challenge
     that covers it is won. `chips` are the words it adds to the bar of words. */
  var NOTIONS = [
    { id: 'select', ch: 1, label: 'SELECT … FROM', g: 'S', chips: ['SELECT', 'FROM', '*'] },
    { id: 'colonnes', ch: 1, label: 'col1, col2', g: ',', chips: [','] },
    { id: 'where', ch: 1, label: 'WHERE', g: 'W', chips: ['WHERE', '=', '<>', '<', '>', '<=', '>='] },
    { id: 'texte', ch: 1, label: "'texte'", g: "'", chips: ["'…'"] },
    { id: 'orderby', ch: 1, label: 'ORDER BY', g: '↕', chips: ['ORDER BY', 'ASC', 'DESC'] },
    { id: 'limit', ch: 1, label: 'LIMIT', g: '3', chips: ['LIMIT', 'OFFSET'] },
    { id: 'andor', ch: 2, label: 'AND · OR', g: '&', chips: ['AND', 'OR', '(', ')'] },
    { id: 'not', ch: 2, label: 'NOT', g: '¬', chips: ['NOT'] },
    { id: 'in', ch: 2, label: 'IN · BETWEEN', g: '∈', chips: ['IN', 'BETWEEN'] },
    { id: 'like', ch: 2, label: 'LIKE', g: '%', chips: ['LIKE', "'%…%'"] },
    { id: 'null', ch: 3, label: 'IS NULL', g: '∅', chips: ['IS', 'NULL', 'IFNULL('] },
    { id: 'distinct', ch: 3, label: 'DISTINCT', g: '1', chips: ['DISTINCT'] },
    { id: 'calcul', ch: 3, label: 'calculs · AS', g: '+', chips: ['AS', '+', '-', '/', 'CONCAT('] },
    { id: 'fonctions', ch: 3, label: 'fonctions', g: 'ƒ', chips: ['UPPER(', 'LOWER(', 'ROUND(', 'CHAR_LENGTH(', 'YEAR(', 'CURDATE()', 'DATEDIFF('] },
    { id: 'count', ch: 4, label: 'COUNT', g: '#', chips: ['COUNT(*)', 'COUNT('] },
    { id: 'agregats', ch: 4, label: 'SUM · AVG · MIN · MAX', g: 'Σ', chips: ['SUM(', 'AVG(', 'MIN(', 'MAX('] },
    { id: 'groupby', ch: 4, label: 'GROUP BY', g: '▦', chips: ['GROUP BY'] },
    { id: 'having', ch: 4, label: 'HAVING', g: 'H', chips: ['HAVING'] },
    { id: 'cles', ch: 5, label: 'clés', g: '⚷', chips: [] },
    { id: 'join', ch: 5, label: 'JOIN … ON', g: '⋈', chips: ['JOIN', 'ON'] },
    { id: 'alias', ch: 5, label: 'alias de tables', g: 's.', chips: [] },
    { id: 'leftjoin', ch: 5, label: 'LEFT JOIN', g: '⟕', chips: ['LEFT JOIN'] },
    { id: 'sousreq', ch: 5, label: 'sous-requête', g: '()', chips: ['(SELECT'] },
    { id: 'insert', ch: 6, label: 'INSERT', g: '+', chips: ['INSERT INTO', 'VALUES'] },
    { id: 'update', ch: 6, label: 'UPDATE', g: '✎', chips: ['UPDATE', 'SET'] },
    { id: 'delete', ch: 6, label: 'DELETE', g: '−', chips: ['DELETE FROM'] },
    { id: 'create', ch: 7, label: 'CREATE TABLE', g: '⊞', chips: ['CREATE TABLE', 'INT', 'VARCHAR(', 'DATE', 'DECIMAL(', 'NOT NULL', 'DEFAULT'] },
    { id: 'pk', ch: 7, label: 'PRIMARY KEY', g: '⚿', chips: ['PRIMARY KEY', 'AUTO_INCREMENT', 'UNIQUE'] },
    { id: 'fk', ch: 7, label: 'FOREIGN KEY', g: '→', chips: ['FOREIGN KEY', 'REFERENCES'] },
    { id: 'alter', ch: 7, label: 'ALTER TABLE', g: '⟲', chips: ['ALTER TABLE', 'ADD'] },
    { id: 'transaction', ch: 8, label: 'transactions', g: '⇄', chips: ['START TRANSACTION', 'COMMIT', 'ROLLBACK'] },
    { id: 'droits', ch: 8, label: 'droits', g: '⚑', chips: [] },
    { id: 'injection', ch: 8, label: 'injection SQL', g: '!', chips: [] }
  ];
  var notionById = {};
  NOTIONS.forEach(function (n) { notionById[n.id] = n; });

  function level(def) {
    def.kind = def.kind || 'level';
    def.steps = def.steps || {};
    def.lessons = def.lessons || {};
    Object.keys(def.steps).forEach(function (k) { def.steps[k].id = k; });
    levels.push(def);
    byId[def.id] = def;
    return def;
  }
  function chrono(def) { def.kind = def.final ? 'final' : 'chrono'; levels.push(def); byId[def.id] = def; return def; }

  /* The words of the bar, for the notions seen: the level's own first, then the others, in the order
     the course meets them. */
  function chipsFor(seen, first) {
    var out = [], have = {};
    function add(w) { if (!have[w]) { have[w] = 1; out.push(w); } }
    (first || []).forEach(function (id) { if (seen[id] && notionById[id]) notionById[id].chips.forEach(add); });
    NOTIONS.forEach(function (n) { if (seen[n.id]) n.chips.forEach(add); });
    return out;
  }

  root.SPPContent = {
    level: level,
    chrono: chrono,
    get: function (id) { return byId[id] || null; },
    all: function () { return levels.slice(); },
    chapters: CHAPTERS,
    notions: NOTIONS,
    notion: function (id) { return notionById[id] || null; },
    chipsFor: chipsFor
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
