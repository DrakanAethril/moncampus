/* Service de nuit — the code editor: a plain textarea over a highlighted copy of the code, with line
   numbers. No library: the highlighting comes from the game's PHP tokenizer (SDNPhp.tokens), and a line
   can be lit from outside (the cut lights the line that wrote what the mouse points at).

     var ed = SDNEditor.create(parent, { value, readOnly, label, onChange(value) });
     ed.value() · ed.set(value) · ed.mark(line | null) · ed.focus() */
(function (root) {
  'use strict';
  function esc(s) { return String(s).replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }

  function highlight(src) {
    var P = root.SDNPhp, toks;
    try { toks = P && P.tokens ? P.tokens(src) : null; } catch (e) { toks = null; }
    if (!toks) return esc(src);
    return toks.map(function (t) { return t.type === 'space' || t.type === 'html' && !/\S/.test(t.text) ? esc(t.text) : '<span class="tk-' + t.type + '">' + esc(t.text) + '</span>'; }).join('');
  }

  function create(parent, opts) {
    opts = opts || {};
    var wrap = document.createElement('div');
    wrap.className = 'ed' + (opts.readOnly ? ' lecture' : '');
    wrap.innerHTML = '<div class="ed-gouttiere" aria-hidden="true"><div class="ed-nums"></div></div><div class="ed-zone"><pre class="ed-couleurs" aria-hidden="true"><code></code></pre><div class="ed-marque" aria-hidden="true"></div><textarea spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off"></textarea></div>';
    var ta = wrap.querySelector('textarea'), code = wrap.querySelector('code'), pre = wrap.querySelector('pre'), nums = wrap.querySelector('.ed-nums'), mk = wrap.querySelector('.ed-marque');
    ta.setAttribute('aria-label', opts.label || 'Code');
    if (opts.readOnly) ta.readOnly = true;
    ta.value = opts.value || '';
    var marked = null;

    function paint() {
      var v = ta.value;
      code.innerHTML = highlight(v) + '\n';
      var n = v.split('\n').length, html = '';
      for (var i = 1; i <= n; i++) html += '<div' + (i === marked ? ' class="on"' : '') + '>' + i + '</div>';
      nums.innerHTML = html;
      placeMark();
    }
    function sync() { pre.scrollTop = ta.scrollTop; pre.scrollLeft = ta.scrollLeft; nums.style.transform = 'translateY(' + (-ta.scrollTop) + 'px)'; placeMark(); }
    function lineHeight() { return parseFloat(getComputedStyle(ta).lineHeight) || 22; }
    function placeMark() {
      if (!marked) { mk.style.display = 'none'; return; }
      mk.style.display = 'block';
      mk.style.top = (parseFloat(getComputedStyle(ta).paddingTop) + (marked - 1) * lineHeight() - ta.scrollTop) + 'px';
      mk.style.height = lineHeight() + 'px';
    }

    ta.addEventListener('input', function () { paint(); if (opts.onChange) opts.onChange(ta.value); });
    ta.addEventListener('scroll', sync);
    ta.addEventListener('keydown', function (e) {
      if (ta.readOnly) return;
      if (e.key === 'Tab' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        // Tab writes four spaces; the keyboard user leaves the editor with Échap then Tab.
        if (wrap.dataset.libre) return;
        e.preventDefault();
        insert('    ');
      } else if (e.key === 'Escape') {
        wrap.dataset.libre = '1';
        setTimeout(function () { delete wrap.dataset.libre; }, 1500);
      } else if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
        // Keep the indentation of the line, one level more after an opening brace.
        var before = ta.value.slice(0, ta.selectionStart), line = before.slice(before.lastIndexOf('\n') + 1);
        var indent = (/^\s*/.exec(line) || [''])[0];
        if (/[{:]\s*$/.test(line)) indent += '    ';
        e.preventDefault();
        insert('\n' + indent);
      }
    });
    function insert(text) {
      var s = ta.selectionStart, e = ta.selectionEnd;
      ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
      ta.selectionStart = ta.selectionEnd = s + text.length;
      paint(); if (opts.onChange) opts.onChange(ta.value);
    }
    paint();
    parent.appendChild(wrap);
    setTimeout(sync, 0);
    return {
      el: wrap,
      value: function () { return ta.value; },
      set: function (v) { ta.value = v; paint(); sync(); },
      mark: function (line) {
        marked = line || null; paint();
        if (marked) { var top = (marked - 1) * lineHeight(); if (top < ta.scrollTop || top > ta.scrollTop + ta.clientHeight - 40) { ta.scrollTop = Math.max(0, top - 60); sync(); } }
      },
      focus: function () { ta.focus(); }
    };
  }

  root.SDNEditor = { create: create, highlight: highlight };
})(typeof globalThis !== 'undefined' ? globalThis : window);
