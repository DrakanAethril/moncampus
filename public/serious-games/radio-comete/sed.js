/* Radio-Comète — sed: addresses (line, $, /re/, ranges, !), blocks, and the commands
   s y p d q Q n N a i c = h H g G x b t, with -n -e -f -E -r -i -s. */
(function (root) {
  'use strict';
  var R = root.RCRegex, U = root.RCUtils, V = root.RCVfs;

  function SedError(msg) { var e = new Error(msg); e.sed = true; return e; }

  function parseScript(script, ere) {
    var cmds = [], i = 0, n = script.length, stack = [];
    function skipWs() { while (i < n && /[ \t]/.test(script[i])) i++; }
    function readDelimited(d) { // up to the next unescaped d; returns raw text (escapes kept)
      var out = '';
      while (i < n && script[i] !== d) {
        if (script[i] === '\\' && i + 1 < n) { if (script[i + 1] === d) { out += d === '/' || d === '|' || d === ',' || d === '#' ? d : '\\' + d; i += 2; continue; } out += script[i] + script[i + 1]; i += 2; continue; }
        out += script[i++];
      }
      if (i >= n) throw SedError('unterminated `s\' command');
      i++;
      return out;
    }
    function address() {
      skipWs();
      var c = script[i];
      if (c === undefined) return null;
      if (/[0-9]/.test(c)) { var m = /^[0-9]+/.exec(script.slice(i))[0]; i += m.length; if (script[i] === '~') { i++; var st = /^[0-9]+/.exec(script.slice(i)); i += st[0].length; return { t: 'step', first: +m, step: +st[0] }; } return { t: 'line', n: +m }; }
      if (c === '$') { i++; return { t: 'last' }; }
      if (c === '/' || c === '\\') {
        var d = '/';
        if (c === '\\') { i++; d = script[i]; } i++;
        var src = readDelimited(d), flags = '';
        while (script[i] === 'I' || script[i] === 'M') { if (script[i] === 'I') flags += 'i'; i++; }
        return { t: 're', re: R.re(src, ere ? 'ere' : 'bre', flags) };
      }
      return null;
    }
    function readText() { // a, i, c: "a\" newline text, or "a text"
      skipWs();
      if (script[i] === '\\') { i++; if (script[i] === '\n') i++; else skipWs(); }
      var out = '';
      while (i < n && script[i] !== '\n') { if (script[i] === '\\' && i + 1 < n) { out += script[i + 1]; i += 2; continue; } out += script[i++]; }
      return out;
    }
    while (i < n) {
      while (i < n && /[ \t\n;]/.test(script[i])) i++;
      if (i >= n) break;
      var a1 = address(), a2 = null, neg = false;
      skipWs();
      if (a1 && script[i] === ',') { i++; skipWs(); if (script[i] === '+') { i++; var pn = /^[0-9]+/.exec(script.slice(i))[0]; i += pn.length; a2 = { t: 'plus', n: +pn }; } else a2 = address(); }
      skipWs();
      while (script[i] === '!') { neg = !neg; i++; skipWs(); }
      var c = script[i++];
      if (c === undefined) throw SedError('missing command');
      var cmd = { a1: a1, a2: a2, neg: neg, c: c, active: false, end: 0 };
      switch (c) {
        case '{': stack.push(cmds.length); break;
        case '}': { var o = stack.pop(); if (o === undefined) throw SedError("unexpected `}'"); cmds[o].end = cmds.length; cmd.c = '}'; break; }
        case 's': {
          var d = script[i++], pat = readDelimited(d), rep = readDelimited(d), flags = '', g = false, p = false, nth = 1, ic = false;
          while (i < n && /[gpiIwe0-9]/.test(script[i])) {
            var f = script[i++];
            if (f === 'g') g = true; else if (f === 'p') p = true; else if (f === 'i' || f === 'I') ic = true; else if (/[0-9]/.test(f)) { var num = f; while (/[0-9]/.test(script[i] || '')) num += script[i++]; nth = +num; } else if (f === 'w') { while (i < n && script[i] !== '\n') i++; }
          }
          cmd.re = R.re(pat, ere ? 'ere' : 'bre', (ic ? 'i' : '') + 'g'); cmd.rep = rep; cmd.g = g; cmd.p = p; cmd.nth = nth; break;
        }
        case 'y': { var yd = script[i++], from = readDelimited(yd), to = readDelimited(yd); cmd.from = from.replace(/\\(.)/g, '$1'); cmd.to = to.replace(/\\(.)/g, '$1'); if (cmd.from.length !== cmd.to.length) throw SedError("strings for `y' command are different lengths"); break; }
        case 'a': case 'i': case 'c': cmd.text = readText(); break;
        case 'b': case 't': case 'T': { skipWs(); var lb = ''; while (i < n && !/[\n;}]/.test(script[i])) lb += script[i++]; cmd.label = lb.trim(); break; }
        case ':': { var lab = ''; while (i < n && !/[\n;]/.test(script[i])) lab += script[i++]; cmd.label = lab.trim(); break; }
        case 'p': case 'd': case 'D': case 'q': case 'Q': case 'n': case 'N': case '=': case 'h': case 'H': case 'g': case 'G': case 'x': case 'l': case 'P': break;
        case '#': while (i < n && script[i] !== '\n') i++; continue;
        default: throw SedError('unknown command: `' + c + "'");
      }
      cmds.push(cmd);
    }
    if (stack.length) throw SedError('unmatched `{\'');
    return cmds;
  }

  function expandRep(rep, m) {
    var out = '', caseMode = null, nextCase = null;
    function add(s) { if (nextCase) { s = s.length ? (nextCase === 'u' ? s[0].toUpperCase() : s[0].toLowerCase()) + s.slice(1) : s; nextCase = null; } if (caseMode === 'U') s = s.toUpperCase(); else if (caseMode === 'L') s = s.toLowerCase(); out += s; }
    for (var i = 0; i < rep.length; i++) {
      var c = rep[i];
      if (c === '\\' && i + 1 < rep.length) {
        var d = rep[++i];
        if (/[0-9]/.test(d)) add(m[+d] === undefined ? '' : m[+d]);
        else if (d === 'n') add('\n'); else if (d === 't') add('\t');
        else if (d === 'U' || d === 'L') caseMode = d; else if (d === 'E') caseMode = null; else if (d === 'u' || d === 'l') nextCase = d;
        else add(d);
      } else if (c === '&') add(m[0]);
      else add(c);
    }
    return out;
  }

  function run(sh, args, io) {
    var o = { n: false, E: false, i: null, s: false }, scripts = [], files = [], i = 0;
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '--') { files = files.concat(args.slice(i + 1)); break; }
      if (a === '-n' || a === '--quiet' || a === '--silent') o.n = true;
      else if (a === '-E' || a === '-r' || a === '--regexp-extended') o.E = true;
      else if (a === '-s' || a === '--separate') o.s = true;
      else if (a === '-e') scripts.push(args[++i]);
      else if (a === '-f') { var sf = U.readFileArg(sh, 'sed', args[++i], io); if (sf === null) return 1; scripts.push(sf.replace(/\n$/, '')); }
      else if (/^--expression=/.test(a)) scripts.push(a.slice(13));
      else if (/^-i/.test(a) || a === '--in-place') o.i = a === '--in-place' ? '' : a.slice(2);
      else if (/^-[nEr]+$/.test(a)) { if (a.indexOf('n') !== -1) o.n = true; if (/[Er]/.test(a)) o.E = true; }
      else if (a[0] === '-' && a.length > 1 && !/^-[0-9]/.test(a)) { io.err.w("sed: invalid option -- '" + a.slice(1) + "'\n"); return 1; }
      else files.push(a);
    }
    if (!scripts.length) { if (!files.length) { io.err.w('Usage: sed [OPTION]... {script-only-if-no-other-script} [input-file]...\n'); return 1; } scripts.push(files.shift()); }
    var cmds;
    try { cmds = parseScript(scripts.join('\n'), o.E); } catch (e) { if (e.sed || e.regex) { io.err.w('sed: -e expression #1, char 0: ' + e.message + '\n'); return 1; } throw e; }
    var labels = {}; cmds.forEach(function (c, k) { if (c.c === ':') labels[c.label] = k; });
    var hold = '', status = 0;

    function runStream(data, out, ctx) { // ctx: { lineNo, lastFile }
      var ls = data === '' ? [] : data.split('\n'), endsNl = true;
      if (ls.length && ls[ls.length - 1] === '') ls.pop(); else if (ls.length) endsNl = false;
      var idx = 0, quit = false, appendQ = [];
      function emit(s, nl) { out.push(s + (nl === false ? '' : '\n')); }
      while (idx < ls.length && !quit) {
        var ps = ls[idx++], isLast = idx >= ls.length && ctx.last;
        ctx.lineNo++;
        var subst = false, pc = 0, deleted = false, newCycle = false;
        while (pc < cmds.length) {
          var c = cmds[pc];
          var m = matches(c, ps, isLast, ctx.lineNo);
          if (c.neg) m = !m;
          if (c.c === '}' || c.c === ':') { pc++; continue; }
          if (c.c === '{') { if (!m) pc = c.end; pc++; continue; }
          if (!m) { pc++; continue; }
          switch (c.c) {
            case 's': {
              var count = 0, did = false;
              var res = ps.replace(c.re, function () {
                var args2 = Array.prototype.slice.call(arguments), lastArg = args2[args2.length - 1];
                var mm = args2.slice(0, args2.length - (typeof lastArg === 'object' && lastArg !== null ? 3 : 2));
                count++;
                if (c.g ? count >= c.nth : count === c.nth) { did = true; return expandRep(c.rep, mm); }
                return mm[0];
              });
              if (did) { ps = res; subst = true; if (c.p) emit(ps); }
              break;
            }
            case 'y': ps = ps.replace(/[\s\S]/g, function (ch) { var k = c.from.indexOf(ch); return k === -1 ? ch : c.to[k]; }); break;
            case 'p': emit(ps); break;
            case 'P': emit(ps.split('\n')[0]); break;
            case 'd': deleted = true; break;
            case 'D': { var nl = ps.indexOf('\n'); if (nl === -1) deleted = true; else { ps = ps.slice(nl + 1); newCycle = true; } break; }
            case 'q': if (!o.n) emit(ps); flushAppend(); quit = true; deleted = true; ctx.quit = true; break;
            case 'Q': quit = true; deleted = true; ctx.quit = true; break;
            case 'n': if (!o.n) emit(ps); if (idx >= ls.length) { deleted = true; quit = true; } else { ps = ls[idx++]; ctx.lineNo++; } break;
            case 'N': if (idx >= ls.length) { if (!o.n) emit(ps); deleted = true; quit = true; } else { ps += '\n' + ls[idx++]; ctx.lineNo++; } break;
            case '=': out.push(ctx.lineNo + '\n'); break;
            case 'a': appendQ.push(c.text); break;
            case 'i': emit(c.text); break;
            case 'c': if (!c.a2 || !c.active) emit(c.text); deleted = true; break;
            case 'h': hold = ps; break; case 'H': hold += '\n' + ps; break; case 'g': ps = hold; break; case 'G': ps += '\n' + hold; break;
            case 'x': { var t = ps; ps = hold; hold = t; break; }
            case 'b': if (c.label === '') { pc = cmds.length; continue; } pc = labels[c.label] === undefined ? cmds.length : labels[c.label]; continue;
            case 't': if (subst) { subst = false; if (c.label === '') { pc = cmds.length; continue; } pc = labels[c.label] === undefined ? cmds.length : labels[c.label]; continue; } break;
            case 'T': if (!subst) { if (c.label === '') { pc = cmds.length; continue; } pc = labels[c.label] === undefined ? cmds.length : labels[c.label]; continue; } subst = false; break;
            case 'l': emit(ps.replace(/\\/g, '\\\\').replace(/\t/g, '\\t') + '$'); break;
          }
          if (deleted || newCycle) break;
          pc++;
        }
        if (!deleted && !o.n) emit(ps, (idx >= ls.length && ctx.last && !endsNl) ? false : true);
        flushAppend();
        if (newCycle) { idx--; ls[idx] = ps; ctx.lineNo--; }
      }
      function flushAppend() { appendQ.forEach(function (t) { emit(t); }); appendQ = []; }
    }
    function matches(c, ps, isLast, ln) {
      if (!c.a1) return true;
      if (!c.a2) return test(c.a1, ps, isLast, ln);
      if (c.active) {
        if (c.a2.t === 'line') { if (ln >= c.a2.n) c.active = false; }
        else if (c.a2.t === 'plus') { if (ln >= c.endLine) c.active = false; }
        else if (c.a2.t === 'last') { if (isLast) c.active = false; }
        else if (test(c.a2, ps, isLast, ln)) c.active = false;
        return true;
      }
      if (test(c.a1, ps, isLast, ln)) {
        if (c.a2.t === 'line' && c.a2.n <= ln) return true;
        if (c.a2.t === 'plus') { c.endLine = ln + c.a2.n; if (c.a2.n === 0) return true; }
        c.active = true; return true;
      }
      return false;
    }
    function test(a, ps, isLast, ln) {
      switch (a.t) { case 'line': return ln === a.n; case 'last': return isLast; case 're': return a.re.test(ps); case 'step': return a.step === 0 ? ln === a.first : (ln >= a.first && (ln - a.first) % a.step === 0); }
      return false;
    }

    if (o.i !== null) {
      if (!files.length) { io.err.w('sed: no input files\n'); return 1; }
      files.forEach(function (f) {
        var d = U.readFileArg(sh, 'sed', f, io); if (d === null) { status = 4; return; }
        cmds.forEach(function (c) { c.active = false; });
        var out = [], ctx = { lineNo: 0, last: true };
        runStream(d, out, ctx);
        try {
          if (o.i) sh.vfs.write(U.abs(sh, f + o.i), d);
          sh.vfs.write(U.abs(sh, f), out.join(''));
        } catch (e) { io.err.w("sed: couldn't write " + f + ': ' + U.errText(e) + '\n'); status = 4; }
      });
      return status;
    }
    var inp = U.inputs(sh, 'sed', files, io), ctx = { lineNo: 0, last: false, quit: false };
    if (inp.bad) status = 2;
    inp.list.forEach(function (f, k) {
      if (ctx.quit) return;
      if (o.s) ctx.lineNo = 0;
      ctx.last = o.s || k === inp.list.length - 1;
      var out = []; runStream(f.data, out, ctx); io.out.w(out.join(''));
    });
    return status;
  }

  U.def('sed', run);
})(typeof globalThis !== 'undefined' ? globalThis : window);
