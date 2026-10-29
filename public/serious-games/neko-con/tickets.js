/* Panique à la Neko-Con — the tickets, the game's half.

   The « platform » half of a ticket (title, request, choice steps and their right answers, graph,
   rewards) lives in config/serious_games/neko-con/tickets/. Here, what the game shows and checks itself:

     NekoTickets.add({
       id: 'T03',
       phase: 'html' | 'css',         // 'html': before the bascule, the style sheet is not loaded
       page: 'index.html',            // page opened in the preview
       edit: ['index.html'],          // files editable at the « réparer » step
       show: ['css/style.css'],       // files shown read-only (optional)
       bug: [{ file, find, replace }],// applied to the correct site (site.js); « *.html » = every page
       fix: [...],                    // optional: the reference repair (otherwise the bug reversed)
       devices: [375, 768, 1280],     // widths offered in the preview (otherwise per act)
       checks: [{ label, page, width, test: function (h) { return boolean | {ok, detail} } }],
       forbid: [{ file, pattern, label }],
       locked: [{ file, rule, label }],
       hints: ['where to look', 'which notion (and which course)', 'almost the answer'],
       intro: [{ who, expr, text }],  // the ticket's opening scene
       done:  [{ who, expr, text }]   // the reaction once the ticket is closed
     });

   The criteria are written in the requester's words: that is what they will check. The engine
   (engine.js) hands each test an object h (see helpers()): h.$, h.$$, h.rect, h.css, h.px,
   h.color, h.near, h.inside, h.overflowX, h.grid, h.reader, h.search, h.src, h.rules, h.hasSelector. */
(function (root) {
  'use strict';
  var list = [], byId = {};
  root.NekoTickets = {
    add: function (t) {
      if (byId[t.id]) throw new Error('Ticket en double : ' + t.id);
      t.phase = t.phase || 'css';
      t.page = t.page || 'index.html';
      t.edit = t.edit || [];
      t.checks = t.checks || [];
      t.hints = t.hints || [];
      list.push(t); byId[t.id] = t;
    },
    get: function (id) { return byId[id]; },
    all: function () { return list; }
  };
})(typeof globalThis !== 'undefined' ? globalThis : window);
