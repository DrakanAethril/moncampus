/* Radio-Comète — the commands: the coreutils and text tools a Bash script of the game calls.

   Every command is fn(sh, args, io) → exit status, registered with RCUtils.def(). sh is the running
   shell (file system, working directory, machine); io = { in, out, err }. Output is plain text.
   Messages follow GNU's, in English, because that is what the students will meet. */
(function (root) {
  'use strict';
  var V = root.RCVfs, R = root.RCRegex, S = root.RCShell, Fail = S.Fail, Stream = S.Stream;
  var table = Object.create(null);

  function def(names, fn) { names.split(' ').forEach(function (n) { table[n] = fn; }); }

  /** Option parser: withArg lists the short letters that take a value; known lists every accepted letter. */
  function parseOpts(name, args, known, withArg, io, longs) {
    var o = {}, rest = [], i = 0;
    longs = longs || {};
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '--') { rest.push.apply(rest, args.slice(i + 1)); break; }
      if (a.slice(0, 2) === '--') {
        var eq = a.indexOf('='), nm = eq === -1 ? a.slice(2) : a.slice(2, eq), spec = longs[nm];
        if (!spec) { io.err.w(name + ": unrecognized option '" + a + "'\n"); return null; }
        if (spec.arg) o[spec.key] = eq === -1 ? args[++i] : a.slice(eq + 1); else o[spec.key] = true;
        continue;
      }
      if (a[0] === '-' && a.length > 1) {
        for (var j = 1; j < a.length; j++) {
          var ch = a[j];
          if (/[0-9]/.test(ch) && known.indexOf('#') !== -1) { o['#'] = a.slice(j); break; }
          if (known.indexOf(ch) === -1 && withArg.indexOf(ch) === -1) { io.err.w(name + ": invalid option -- '" + ch + "'\n"); return null; }
          if (withArg.indexOf(ch) !== -1) { o[ch] = j + 1 < a.length ? a.slice(j + 1) : args[++i]; break; }
          o[ch] = (o[ch] || 0) + 1;
        }
        continue;
      }
      rest.push(a);
    }
    return { o: o, rest: rest };
  }
  function errText(e) { return e && e.code === 'ENOENT' ? 'No such file or directory' : (e && e.code === 'EISDIR' ? 'Is a directory' : (e && e.code === 'ENOSPC' ? 'No space left on device' : (e && e.code === 'ENOTDIR' ? 'Not a directory' : (e && e.code === 'EEXIST' ? 'File exists' : (e && e.code === 'ENOTEMPTY' ? 'Directory not empty' : (e && e.message) || 'error'))))); }
  function abs(sh, p) { return V.resolve(sh.cwd, p); }
  function readFileArg(sh, name, p, io) {
    try { return sh.vfs.read(abs(sh, p)); } catch (e) { io.err.w(name + ': ' + p + ': ' + errText(e) + '\n'); return null; }
  }
  /** The inputs of a filter: the files named, or stdin. Returns [{ name, data }] and a failure flag. */
  function inputs(sh, name, files, io) {
    var out = [], bad = false;
    if (!files.length) files = ['-'];
    files.forEach(function (f) {
      if (f === '-') { out.push({ name: '-', data: io.in ? io.in.readAll() : '' }); return; }
      var d = readFileArg(sh, name, f, io);
      if (d === null) bad = true; else out.push({ name: f, data: d });
    });
    return { list: out, bad: bad };
  }
  function lines(data) { if (data === '') return []; var l = data.split('\n'); if (l[l.length - 1] === '') l.pop(); return l; }
  function human(n) {
    var u = ['', 'K', 'M', 'G', 'T'], i = 0, v = n;
    while (v >= 1024 && i < 4) { v /= 1024; i++; }
    return i === 0 ? String(n) : (v < 10 ? v.toFixed(1) : String(Math.ceil(v))) + u[i];
  }
  function two(n) { return n < 10 ? '0' + n : String(n); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  var MONL = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var DOWL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  function strftime(fmt, t) {
    var d = new Date(t * 1000);
    var Y = d.getUTCFullYear(), mo = d.getUTCMonth(), da = d.getUTCDate(), H = d.getUTCHours(), Mi = d.getUTCMinutes(), Se = d.getUTCSeconds(), w = d.getUTCDay();
    var doy = Math.floor((Date.UTC(Y, mo, da) - Date.UTC(Y, 0, 1)) / 86400000) + 1;
    return fmt.replace(/%([-_0^#]?)([a-zA-Z%])/g, function (m, flag, c) {
      var r;
      switch (c) {
        case 'Y': r = String(Y); break; case 'y': r = two(Y % 100); break; case 'm': r = two(mo + 1); break; case 'd': r = two(da); break; case 'e': r = (da < 10 ? ' ' : '') + da; break;
        case 'H': r = two(H); break; case 'M': r = two(Mi); break; case 'S': r = two(Se); break; case 'I': r = two(H % 12 || 12); break; case 'p': r = H < 12 ? 'AM' : 'PM'; break;
        case 'F': r = Y + '-' + two(mo + 1) + '-' + two(da); break; case 'T': r = two(H) + ':' + two(Mi) + ':' + two(Se); break; case 'R': r = two(H) + ':' + two(Mi); break;
        case 'D': r = two(mo + 1) + '/' + two(da) + '/' + two(Y % 100); break;
        case 's': r = String(t); break; case 'a': r = DOW[w]; break; case 'A': r = DOWL[w]; break; case 'b': case 'h': r = MON[mo]; break; case 'B': r = MONL[mo]; break;
        case 'j': r = ('00' + doy).slice(-3); break; case 'u': r = String(w || 7); break; case 'w': r = String(w); break; case 'Z': r = 'UTC'; break; case 'z': r = '+0000'; break;
        case 'n': r = '\n'; break; case 't': r = '\t'; break; case '%': r = '%'; break;
        case 'U': r = two(Math.floor((doy + 6 - w) / 7)); break; case 'V': r = two(Math.max(1, Math.ceil((doy - ((w + 6) % 7) + 3) / 7))); break;
        case 'c': r = DOW[w] + ' ' + MON[mo] + ' ' + (da < 10 ? ' ' : '') + da + ' ' + two(H) + ':' + two(Mi) + ':' + two(Se) + ' ' + Y; break;
        case 'x': r = two(mo + 1) + '/' + two(da) + '/' + two(Y % 100); break; case 'X': r = two(H) + ':' + two(Mi) + ':' + two(Se); break;
        default: return m;
      }
      if (flag === '-') r = r.replace(/^[0 ]+(?=.)/, ''); else if (flag === '_') r = r.replace(/^0+(?=.)/, function (z) { return Array(z.length + 1).join(' '); });
      if (flag === '^') r = r.toUpperCase();
      return r;
    });
  }
  /** GNU date -d strings: returns epoch seconds or null. */
  function parseDate(str, now) {
    var s = str.trim().toLowerCase(), m;
    if (s === '' || s === 'now' || s === 'today') return now;
    if (s === 'yesterday') return now - 86400; if (s === 'tomorrow') return now + 86400;
    if ((m = /^@(-?\d+)$/.exec(s))) return parseInt(m[1], 10);
    if ((m = /^(\d+)\s*(second|sec|minute|min|hour|day|week|month|year)s?\s+ago$/.exec(s))) return now - parseInt(m[1], 10) * unit(m[2]);
    if ((m = /^(?:\+?(\d+))\s*(second|sec|minute|min|hour|day|week|month|year)s?(?:\s+(?:from now|hence))?$/.exec(s))) return now + parseInt(m[1], 10) * unit(m[2]);
    if ((m = /^last\s+(week|month|year|day)$/.exec(s))) return now - unit(m[1]);
    if ((m = /^(\d{4})-(\d{2})-(\d{2})(?:[ t](\d{2}):(\d{2})(?::(\d{2}))?)?(?:z| utc)?$/.exec(s))) return Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)) / 1000;
    if ((m = /^(\d{4})(\d{2})(\d{2})$/.exec(s))) return Date.UTC(+m[1], +m[2] - 1, +m[3]) / 1000;
    if ((m = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(s))) { var d0 = Math.floor(now / 86400) * 86400; return d0 + (+m[1]) * 3600 + (+m[2]) * 60 + (+(m[3] || 0)); }
    return null;
  }
  function unit(u) { return { second: 1, sec: 1, minute: 60, min: 60, hour: 3600, day: 86400, week: 604800, month: 2592000, year: 31536000 }[u]; }

  function modeString(node) {
    var t = node.t === 'd' ? 'd' : (node.t === 'l' ? 'l' : '-'), m = node.mode, s = t, i;
    for (i = 8; i >= 0; i--) s += (m & (1 << i)) ? 'rwxrwxrwx'[8 - i] : '-';
    return s;
  }

  // ——— cat, tac, rev, nl ———
  def('cat', function (sh, args, io) {
    var p = parseOpts('cat', args, 'nbsAEvT', '', io); if (!p) return 1;
    var inp = inputs(sh, 'cat', p.rest, io), n = 0;
    inp.list.forEach(function (f) {
      var d = f.data;
      if (p.o.n || p.o.b) { d = lines(d).map(function (l) { if (p.o.b && l === '') return l; n++; return ('     ' + n).slice(-6) + '\t' + l; }).join('\n') + (d === '' ? '' : '\n'); }
      if (p.o.E) d = d.replace(/\n/g, '$\n');
      io.out.w(d);
    });
    return inp.bad ? 1 : 0;
  });
  def('tac', function (sh, args, io) { var inp = inputs(sh, 'tac', args, io); inp.list.forEach(function (f) { var l = lines(f.data).reverse(); if (l.length) io.out.w(l.join('\n') + '\n'); }); return inp.bad ? 1 : 0; });
  def('rev', function (sh, args, io) { var inp = inputs(sh, 'rev', args, io); inp.list.forEach(function (f) { lines(f.data).forEach(function (l) { io.out.w(Array.from(l).reverse().join('') + '\n'); }); }); return inp.bad ? 1 : 0; });
  def('nl', function (sh, args, io) {
    var inp = inputs(sh, 'nl', args.filter(function (a) { return a[0] !== '-'; }), io), n = 0;
    inp.list.forEach(function (f) { lines(f.data).forEach(function (l) { if (l === '') { io.out.w('\n'); return; } n++; io.out.w(('     ' + n).slice(-6) + '\t' + l + '\n'); }); });
    return inp.bad ? 1 : 0;
  });

  // ——— head, tail, wc ———
  function headTail(sh, name, args, io, isHead) {
    var p = parseOpts(name, args, 'qv#', 'nc', io); if (!p) return 1;
    var n = 10, bytes = null, fromStart = false;
    if (p.o.n !== undefined) { var v = String(p.o.n); if (v[0] === '+') { fromStart = true; v = v.slice(1); } if (v[0] === '-') v = v.slice(1), fromStart = !isHead ? fromStart : fromStart; n = parseInt(v, 10); if (isNaN(n)) { io.err.w(name + ': invalid number of lines: ' + p.o.n + '\n'); return 1; } if (isHead && String(p.o.n)[0] === '-') n = -n; }
    else if (p.o['#'] !== undefined) n = parseInt(p.o['#'], 10);
    if (p.o.c !== undefined) bytes = parseInt(p.o.c, 10);
    var inp = inputs(sh, name, p.rest, io), multi = inp.list.length > 1 && !p.o.q || p.o.v;
    inp.list.forEach(function (f, idx) {
      if (multi) io.out.w((idx ? '\n' : '') + '==> ' + (f.name === '-' ? 'standard input' : f.name) + ' <==\n');
      var d = f.data;
      if (bytes !== null) { io.out.w(isHead ? d.slice(0, bytes) : d.slice(Math.max(0, d.length - bytes))); return; }
      var l = d.split('\n'), trailing = l[l.length - 1] === '';
      if (trailing) l.pop();
      var sel;
      if (isHead) sel = n < 0 ? l.slice(0, Math.max(0, l.length + n)) : l.slice(0, n);
      else sel = fromStart ? l.slice(Math.max(0, n - 1)) : (n === 0 ? [] : l.slice(-n));
      if (sel.length) io.out.w(sel.join('\n') + (trailing || sel.length < l.length || !isHead ? '\n' : (trailing ? '\n' : '')));
    });
    return inp.bad ? 1 : 0;
  }
  def('head', function (sh, args, io) { return headTail(sh, 'head', args, io, true); });
  def('tail', function (sh, args, io) { return headTail(sh, 'tail', args, io, false); });
  def('wc', function (sh, args, io) {
    var p = parseOpts('wc', args, 'lwcmL', '', io); if (!p) return 1;
    var all = !(p.o.l || p.o.w || p.o.c || p.o.m);
    var inp = inputs(sh, 'wc', p.rest, io), rows = [], tot = [0, 0, 0];
    inp.list.forEach(function (f) {
      var d = f.data, l = (d.match(/\n/g) || []).length, w = (d.trim() === '' ? 0 : d.trim().split(/\s+/).length), c = V.byteLength(d);
      tot[0] += l; tot[1] += w; tot[2] += c; rows.push([l, w, c, f.name === '-' ? '' : f.name]);
    });
    if (rows.length > 1) rows.push([tot[0], tot[1], tot[2], 'total']);
    var single = rows.length === 1;
    var width = single ? 0 : Math.max(1, String(Math.max.apply(null, rows.map(function (r) { return Math.max(r[0], r[1], r[2]); }))).length);
    rows.forEach(function (r) {
      var cols = [];
      if (all || p.o.l) cols.push(r[0]); if (all || p.o.w) cols.push(r[1]); if (all || p.o.c || p.o.m) cols.push(r[2]);
      io.out.w(cols.map(function (c) { return single ? String(c) : ('          ' + c).slice(-Math.max(width, 1)); }).join(' ') + (r[3] ? ' ' + r[3] : '') + '\n');
    });
    return inp.bad ? 1 : 0;
  });

  // ——— grep ———
  function grepRun(sh, name, args, io, mode) {
    var p = parseOpts(name, args, 'ivcnqlLEFGoswxrRHhPa', 'emA' + 'BC', io, { 'color': { key: 'color' }, 'include': { key: 'include', arg: true }, 'exclude': { key: 'exclude', arg: true }, 'regexp': { key: 'e', arg: true }, 'ignore-case': { key: 'i' }, 'invert-match': { key: 'v' }, 'count': { key: 'c' }, 'quiet': { key: 'q' }, 'files-with-matches': { key: 'l' }, 'extended-regexp': { key: 'E' }, 'fixed-strings': { key: 'F' }, 'word-regexp': { key: 'w' }, 'line-number': { key: 'n' }, 'only-matching': { key: 'o' }, 'recursive': { key: 'r' }, 'no-messages': { key: 's' } });
    if (!p) return 2;
    var pats = [], rest = p.rest;
    if (p.o.e !== undefined) pats.push(String(p.o.e)); else { if (!rest.length) { io.err.w('Usage: grep [OPTION]... PATTERNS [FILE]...\n'); return 2; } pats.push(rest.shift()); }
    if (Array.isArray(p.o.e)) pats = p.o.e;
    // several -e: re-scan args
    var eIdx = []; args.forEach(function (a, i) { if (a === '-e' && args[i + 1] !== undefined) eIdx.push(args[i + 1]); });
    if (eIdx.length > 1) { pats = eIdx; }
    var fixed = !!p.o.F || mode === 'F', ere = !!p.o.E || !!p.o.P || mode === 'E';
    var flags = p.o.i ? 'i' : '', res = [];
    var allPats = [];
    pats.forEach(function (x) { String(x).split('\n').forEach(function (y) { allPats.push(y); }); });
    try {
      allPats.forEach(function (x) {
        var src = fixed ? x.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&') : R.reSrc(x, ere ? 'ere' : 'bre');
        if (p.o.x) src = '^(?:' + src + ')$'; else if (p.o.w) src = '(?<![A-Za-z0-9_])(?:' + src + ')(?![A-Za-z0-9_])';
        res.push(src);
      });
    } catch (e) { io.err.w(name + ': invalid regular expression\n'); return 2; }
    var re, reG;
    try { re = new RegExp(res.join('|'), flags); reG = new RegExp(res.join('|'), flags + 'g'); } catch (e2) { io.err.w(name + ': Unmatched ( or \\(\n'); return 2; }
    var files = rest, recursive = p.o.r || p.o.R;
    if (recursive) {
      var exp = [];
      (files.length ? files : ['.']).forEach(function (f) {
        var full = abs(sh, f);
        if (sh.vfs.isDir(full)) sh.vfs.walk(full).forEach(function (x) { if (sh.vfs.isFile(x)) exp.push(f === '.' && !files.length ? x.slice(full.length + 1) : (f.replace(/\/$/, '') + x.slice(full.length))); });
        else exp.push(f);
      });
      files = exp;
    }
    if (p.o.include) { var inc = R.globRe(p.o.include); files = files.filter(function (f) { return inc.test(V.basename(f)); }); }
    if (p.o.exclude) { var exc = R.globRe(p.o.exclude); files = files.filter(function (f) { return !exc.test(V.basename(f)); }); }
    var multi = files.length > 1 || recursive, bad = false, any = false, after = p.o.A !== undefined ? parseInt(p.o.A, 10) : (p.o.C !== undefined ? parseInt(p.o.C, 10) : 0), before = p.o.B !== undefined ? parseInt(p.o.B, 10) : (p.o.C !== undefined ? parseInt(p.o.C, 10) : 0);
    var inp = { list: [] };
    if (!files.length) inp.list.push({ name: '(standard input)', data: io.in ? io.in.readAll() : '' });
    else files.forEach(function (f) {
      if (f === '-') { inp.list.push({ name: '(standard input)', data: io.in ? io.in.readAll() : '' }); return; }
      var full = abs(sh, f);
      if (sh.vfs.isDir(full)) { if (!p.o.s) io.err.w(name + ': ' + f + ': Is a directory\n'); return; }
      try { inp.list.push({ name: f, data: sh.vfs.read(full) }); } catch (e3) { bad = true; if (!p.o.s) io.err.w(name + ': ' + f + ': ' + errText(e3) + '\n'); }
    });
    var stop = false;
    inp.list.forEach(function (f) {
      if (stop) return;
      var ls = lines(f.data), count = 0, printedUntil = -1;
      for (var i = 0; i < ls.length && !stop; i++) {
        var hit = re.test(ls[i]) !== !!p.o.v;
        if (!hit) continue;
        count++; any = true;
        if (p.o.q) { stop = true; break; }
        if (p.o.l || p.o.L || p.o.c) { if (p.o.l) break; continue; }
        var pre = (multi && !p.o.h ? f.name + ':' : '');
        for (var b = Math.max(printedUntil + 1, i - before); b < i; b++) io.out.w((multi && !p.o.h ? f.name + '-' : '') + (p.o.n ? (b + 1) + '-' : '') + ls[b] + '\n');
        if (p.o.o && !p.o.v) { var mm; reG.lastIndex = 0; while ((mm = reG.exec(ls[i])) && mm[0] !== '') { io.out.w(pre + (p.o.n ? (i + 1) + ':' : '') + mm[0] + '\n'); } }
        else io.out.w(pre + (p.o.n ? (i + 1) + ':' : '') + ls[i] + '\n');
        printedUntil = i;
        for (var a = 1; a <= after && i + a < ls.length; a++) { if (re.test(ls[i + a]) !== !!p.o.v) break; }
        for (var a2 = 1; a2 <= after && i + a2 < ls.length; a2++) { if (re.test(ls[i + a2]) !== !!p.o.v) { io.out.w((multi && !p.o.h ? f.name + '-' : '') + (p.o.n ? (i + a2 + 1) + '-' : '') + ls[i + a2] + '\n'); printedUntil = i + a2; } else break; }
      }
      if (p.o.c) io.out.w((multi && !p.o.h ? f.name + ':' : '') + count + '\n');
      if (p.o.l && count) io.out.w(f.name + '\n');
      if (p.o.L && !count) io.out.w(f.name + '\n');
    });
    if (p.o.q && any) return 0;
    return any ? 0 : (bad ? 2 : 1);
  }
  def('grep', function (sh, a, io) { return grepRun(sh, 'grep', a, io, ''); });
  def('egrep', function (sh, a, io) { return grepRun(sh, 'egrep', a, io, 'E'); });
  def('fgrep', function (sh, a, io) { return grepRun(sh, 'fgrep', a, io, 'F'); });

  // ——— sort, uniq, cut, tr, paste, comm, tee ———
  def('sort', function (sh, args, io) {
    var p = parseOpts('sort', args, 'nrufbhVsMcC', 'ktTo', io); if (!p) return 2;
    var keys = [], ks = [];
    args.forEach(function (a, i) { if (a === '-k' && args[i + 1]) ks.push(args[i + 1]); else if (/^-k./.test(a)) ks.push(a.slice(2)); });
    ks.forEach(function (k) {
      var m = /^(\d+)(?:\.(\d+))?(?:,(\d+)(?:\.(\d+))?)?([bnrfhV]*)$/.exec(k);
      if (m) keys.push({ from: +m[1], to: m[3] ? +m[3] : null, flags: m[5] || '' });
    });
    var sep = p.o.t !== undefined ? p.o.t : (p.o.T !== undefined ? p.o.T : null);
    if (p.o.t !== undefined && p.o.t === '\\t') sep = '\t';
    var inp = inputs(sh, 'sort', p.rest, io);
    var ls = []; inp.list.forEach(function (f) { ls = ls.concat(lines(f.data)); });
    function field(line, k) {
      var parts = sep !== null ? line.split(sep) : line.split(/(?<=\S)(?=\s)|(?<=\s)(?=\S)/).reduce(function (acc, tk) { if (/^\s+$/.test(tk) && acc.length) { acc[acc.length - 1] += tk; } else acc.push(tk); return acc; }, []);
      var f = k.from - 1, t = k.to === null ? parts.length : k.to;
      var sel = parts.slice(f, t).join(sep !== null ? sep : '');
      if (sep === null && !/b/.test(k.flags) && false) return sel;
      return sep === null ? sel.replace(/^\s+/, function (m) { return /b/.test(k.flags) || p.o.b ? '' : m; }) : sel;
    }
    function cmpVals(a, b, fl) {
      var rev = /r/.test(fl), r;
      if (/n/.test(fl) || /h/.test(fl)) {
        var x = parseFloat(/h/.test(fl) ? humanNum(a) : a), y = parseFloat(/h/.test(fl) ? humanNum(b) : b);
        x = isNaN(x) ? 0 : x; y = isNaN(y) ? 0 : y; r = x - y;
      } else if (/V/.test(fl)) { r = a.localeCompare(b, 'en', { numeric: true }); }
      else { if (/f/.test(fl)) { a = a.toUpperCase(); b = b.toUpperCase(); } r = a < b ? -1 : (a > b ? 1 : 0); }
      return rev ? -r : r;
    }
    function humanNum(s) { var m = /^\s*([0-9.]+)\s*([KMGT]?)/i.exec(s); if (!m) return '0'; return String(parseFloat(m[1]) * Math.pow(1024, 'KMGT'.indexOf(m[2].toUpperCase()) + 1)); }
    var global = (p.o.n ? 'n' : '') + (p.o.r ? 'r' : '') + (p.o.f ? 'f' : '') + (p.o.h ? 'h' : '') + (p.o.V ? 'V' : '');
    var cmp = function (a, b) {
      if (keys.length) { for (var i = 0; i < keys.length; i++) { var fl = keys[i].flags || global, r = cmpVals(field(a, keys[i]), field(b, keys[i]), fl); if (r) return r; } if (global.indexOf('r') !== -1 && !keys[0].flags) return 0; return p.o.s ? 0 : cmpVals(a, b, p.o.r ? 'r' : ''); }
      return cmpVals(a, b, global);
    };
    if (p.o.c || p.o.C) { for (var i = 1; i < ls.length; i++) if (cmp(ls[i - 1], ls[i]) > 0) { io.err.w('sort: -:' + (i + 1) + ': disorder: ' + ls[i] + '\n'); return 1; } return 0; }
    ls.sort(cmp);
    if (p.o.u) ls = ls.filter(function (l, i) { return i === 0 || cmp(ls[i - 1], l) !== 0; });
    var out = ls.length ? ls.join('\n') + '\n' : '';
    if (p.o.o) { try { sh.vfs.write(abs(sh, p.o.o), out); } catch (e) { io.err.w('sort: open failed: ' + p.o.o + ': ' + errText(e) + '\n'); return 2; } } else io.out.w(out);
    return inp.bad ? 2 : 0;
  });
  def('uniq', function (sh, args, io) {
    var p = parseOpts('uniq', args, 'cduiD', 'fsw', io); if (!p) return 1;
    var inp = inputs(sh, 'uniq', p.rest.slice(0, 1), io), ls = lines(inp.list.length ? inp.list[0].data : ''), groups = [];
    ls.forEach(function (l) {
      var key = p.o.i ? l.toLowerCase() : l, g = groups[groups.length - 1];
      if (g && g.key === key) g.n++; else groups.push({ key: key, line: l, n: 1 });
    });
    groups.forEach(function (g) {
      if (p.o.d && g.n < 2) return; if (p.o.u && g.n > 1) return;
      io.out.w((p.o.c ? ('       ' + g.n).slice(-7) + ' ' : '') + g.line + '\n');
    });
    return inp.bad ? 1 : 0;
  });
  function ranges(spec) {
    var out = [];
    spec.split(',').forEach(function (r) {
      var m = /^(\d*)-(\d*)$/.exec(r);
      if (m) out.push([m[1] === '' ? 1 : +m[1], m[2] === '' ? Infinity : +m[2]]);
      else if (/^\d+$/.test(r)) out.push([+r, +r]);
    });
    return out;
  }
  def('cut', function (sh, args, io) {
    var p = parseOpts('cut', args, 'sn', 'dfcb', io, { 'output-delimiter': { key: 'od', arg: true } }); if (!p) return 1;
    var d = p.o.d !== undefined ? p.o.d : '\t', spec = p.o.f !== undefined ? p.o.f : (p.o.c !== undefined ? p.o.c : p.o.b);
    if (spec === undefined) { io.err.w('cut: you must specify a list of bytes, characters, or fields\n'); return 1; }
    var rg = ranges(String(spec)), inp = inputs(sh, 'cut', p.rest, io), outd = p.o.od !== undefined ? p.o.od : (p.o.f !== undefined ? d : '');
    var inRange = function (i) { return rg.some(function (r) { return i >= r[0] && i <= r[1]; }); };
    inp.list.forEach(function (f) {
      lines(f.data).forEach(function (l) {
        if (p.o.f !== undefined) {
          if (l.indexOf(d) === -1) { if (!p.o.s) io.out.w(l + '\n'); return; }
          io.out.w(l.split(d).filter(function (x, i) { return inRange(i + 1); }).join(outd) + '\n');
        } else io.out.w(Array.from(l).filter(function (x, i) { return inRange(i + 1); }).join(outd) + '\n');
      });
    });
    return inp.bad ? 1 : 0;
  });
  function expandSet(s) {
    var out = [], i = 0;
    s = s.replace(/\\(.)/g, function (m, c) { return { n: '\n', t: '\t', r: '\r', '\\': '\\', 0: '\0' }[c] !== undefined ? { n: '\n', t: '\t', r: '\r', '\\': '\\', 0: '\0' }[c] : '\u0001' + c; });
    while (i < s.length) {
      if (s[i] === '[' && s[i + 1] === ':') { var e = s.indexOf(':]', i); var nm = s.slice(i + 2, e); var set = { upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', lower: 'abcdefghijklmnopqrstuvwxyz', digit: '0123456789', alpha: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', alnum: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', space: ' \t\n\r\f\v', blank: ' \t', punct: '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~' }[nm] || ''; out = out.concat(set.split('')); i = e + 2; continue; }
      if (s[i] === '\u0001') { out.push(s[i + 1]); i += 2; continue; }
      if (s[i + 1] === '-' && i + 2 < s.length) { var a = s.charCodeAt(i), b = s.charCodeAt(i + 2); for (var c = a; c <= b; c++) out.push(String.fromCharCode(c)); i += 3; continue; }
      out.push(s[i]); i++;
    }
    return out;
  }
  def('tr', function (sh, args, io) {
    var p = parseOpts('tr', args, 'dsc', '', io); if (!p) return 1;
    var data = io.in ? io.in.readAll() : '', set1 = expandSet(p.rest[0] || ''), set2 = expandSet(p.rest[1] || ''), out = '';
    if (p.o.c) { var all = []; for (var c0 = 0; c0 < 256; c0++) all.push(String.fromCharCode(c0)); set1 = all.filter(function (ch) { return set1.indexOf(ch) === -1; }); }
    var last = null;
    for (var i = 0; i < data.length; i++) {
      var ch = data[i], k = set1.indexOf(ch);
      if (p.o.d) { if (k !== -1) continue; out += ch; last = null; continue; }
      if (k !== -1 && set2.length) ch = set2[Math.min(k, set2.length - 1)];
      if (p.o.s && (k !== -1 || (!set2.length && false)) && last === ch) continue;
      if (p.o.s && k === -1 && false) continue;
      out += ch; last = k !== -1 ? ch : null;
    }
    io.out.w(out); return 0;
  });
  def('tee', function (sh, args, io) {
    var p = parseOpts('tee', args, 'ai', '', io); if (!p) return 1;
    var data = io.in ? io.in.readAll() : '', st = 0;
    io.out.w(data);
    p.rest.forEach(function (f) { try { sh.vfs.write(abs(sh, f), data, !!p.o.a); } catch (e) { io.err.w('tee: ' + f + ': ' + errText(e) + '\n'); st = 1; } });
    return st;
  });
  def('paste', function (sh, args, io) {
    var p = parseOpts('paste', args, 's', 'd', io); if (!p) return 1;
    var d = p.o.d !== undefined ? p.o.d : '\t', inp = inputs(sh, 'paste', p.rest, io), cols = inp.list.map(function (f) { return lines(f.data); });
    if (p.o.s) { cols.forEach(function (c) { io.out.w(c.join(d) + '\n'); }); return 0; }
    var n = Math.max.apply(null, cols.map(function (c) { return c.length; }).concat([0]));
    for (var i = 0; i < n; i++) io.out.w(cols.map(function (c) { return c[i] === undefined ? '' : c[i]; }).join(d) + '\n');
    return inp.bad ? 1 : 0;
  });
  def('comm', function (sh, args, io) {
    var p = parseOpts('comm', args, '123', '', io); if (!p) return 1;
    if (p.rest.length !== 2) { io.err.w('comm: missing operand\n'); return 1; }
    var inp = inputs(sh, 'comm', p.rest, io); if (inp.bad) return 1;
    var a = lines(inp.list[0].data), b = lines(inp.list[1].data), i = 0, j = 0, t1 = p.o[1] ? '' : '\t', t2 = (p.o[1] ? '' : '\t') + (p.o[2] ? '' : '\t');
    while (i < a.length || j < b.length) {
      if (j >= b.length || (i < a.length && a[i] < b[j])) { if (!p.o[1]) io.out.w(a[i] + '\n'); i++; }
      else if (i >= a.length || b[j] < a[i]) { if (!p.o[2]) io.out.w((p.o[1] ? '' : '\t') + b[j] + '\n'); j++; }
      else { if (!p.o[3]) io.out.w(t2 + a[i] + '\n'); i++; j++; }
    }
    return 0;
  });
  def('column', function (sh, args, io) {
    var p = parseOpts('column', args, 't', 'sn', io); if (!p) return 1;
    var inp = inputs(sh, 'column', p.rest, io), ls = []; inp.list.forEach(function (f) { ls = ls.concat(lines(f.data)); });
    if (!p.o.t) { io.out.w(ls.join('\n') + '\n'); return 0; }
    var sep = p.o.s !== undefined ? new RegExp('[' + p.o.s.replace(/[\]\\^-]/g, '\\$&') + ']') : /\s+/, rows = ls.map(function (l) { return l.split(sep); });
    var w = []; rows.forEach(function (r) { r.forEach(function (c, i) { w[i] = Math.max(w[i] || 0, c.length); }); });
    rows.forEach(function (r) { io.out.w(r.map(function (c, i) { return i === r.length - 1 ? c : c + Array(w[i] - c.length + 3).join(' '); }).join('') + '\n'); });
    return 0;
  });
  def('seq', function (sh, args, io) {
    var p = parseOpts('seq', args.map(function (a) { return /^-[0-9]/.test(a) ? '\u0002' + a : a; }), 'w', 's', io); if (!p) return 1;
    var nums = p.rest.map(function (a) { return parseFloat(a.replace('\u0002', '')); }), a = 1, st = 1, b;
    if (nums.length === 1) b = nums[0]; else if (nums.length === 2) { a = nums[0]; b = nums[1]; } else if (nums.length === 3) { a = nums[0]; st = nums[1]; b = nums[2]; } else { io.err.w('seq: missing operand\n'); return 1; }
    var out = [], width = p.o.w ? Math.max(String(a).length, String(b).length) : 0;
    for (var x = a; st > 0 ? x <= b : x >= b; x += st) { var s = String(x); while (s.length < width) s = '0' + s; out.push(s); if (out.length > 100000) break; }
    io.out.w(out.join(p.o.s !== undefined ? p.o.s : '\n') + (out.length ? '\n' : ''));
    return 0;
  });
  def('basename', function (sh, args, io) {
    var a = args.filter(function (x) { return x !== '-a' && x !== '--'; }), s = a[0] === undefined ? '' : a[0].replace(/\/+$/, ''), suf = a[1];
    var b = s === '' && a[0] !== undefined ? '/' : s.slice(s.lastIndexOf('/') + 1);
    if (suf && b.length > suf.length && b.slice(-suf.length) === suf) b = b.slice(0, -suf.length);
    io.out.w(b + '\n'); return 0;
  });
  def('dirname', function (sh, args, io) { args.forEach(function (a) { var s = a.replace(/\/+$/, ''); var i = s.lastIndexOf('/'); io.out.w((i === -1 ? '.' : (i === 0 ? '/' : s.slice(0, i))) + '\n'); }); return 0; });
  def('realpath readlink', function (sh, args, io) {
    var st = 0; args.filter(function (a) { return a[0] !== '-'; }).forEach(function (a) { var full = abs(sh, a); try { io.out.w(sh.vfs.real(full, true) + '\n'); } catch (e) { st = 1; } }); return st;
  });
  def('expr', function (sh, args, io) {
    var a = args;
    if (a[0] === 'length') { io.out.w(String(Array.from(a[1] || '').length) + '\n'); return Array.from(a[1] || '').length ? 0 : 1; }
    if (a.length === 3 && a[1] === ':') { var m = R.re('^' + a[2], 'bre').exec(a[0]); var out = m ? (m[1] !== undefined ? m[1] : String(m[0].length)) : (/\\\(/.test(a[2]) ? '' : '0'); io.out.w(out + '\n'); return out === '' || out === '0' ? 1 : 0; }
    if (a.length === 3) {
      var x = a[0], op = a[1], y = a[2], nx = parseFloat(x), ny = parseFloat(y), num = /^-?\d+$/.test(x) && /^-?\d+$/.test(y), r;
      switch (op) {
        case '+': r = nx + ny; break; case '-': r = nx - ny; break; case '*': r = nx * ny; break;
        case '/': if (ny === 0) { io.err.w('expr: division by zero\n'); return 2; } r = Math.trunc(nx / ny); break;
        case '%': if (ny === 0) { io.err.w('expr: division by zero\n'); return 2; } r = nx % ny; break;
        case '=': r = (num ? nx === ny : x === y) ? 1 : 0; break; case '!=': r = (num ? nx !== ny : x !== y) ? 1 : 0; break;
        case '<': r = (num ? nx < ny : x < y) ? 1 : 0; break; case '>': r = (num ? nx > ny : x > y) ? 1 : 0; break;
        case '<=': r = (num ? nx <= ny : x <= y) ? 1 : 0; break; case '>=': r = (num ? nx >= ny : x >= y) ? 1 : 0; break;
        default: io.err.w('expr: syntax error: unexpected argument \'' + op + '\'\n'); return 2;
      }
      io.out.w(r + '\n'); return r === 0 ? 1 : 0;
    }
    if (a.length === 1) { io.out.w(a[0] + '\n'); return a[0] === '' || a[0] === '0' ? 1 : 0; }
    io.err.w('expr: syntax error\n'); return 2;
  });

  // ——— The file system ———
  def('touch', function (sh, args, io) {
    var p = parseOpts('touch', args, 'cam', 'dtr', io); if (!p) return 1;
    var when = sh.vfs.now, st = 0;
    if (p.o.d !== undefined) { var t = parseDate(p.o.d, sh.vfs.now); if (t === null) { io.err.w("touch: invalid date format '" + p.o.d + "'\n"); return 1; } when = t; }
    if (p.o.r !== undefined) { var rn = sh.vfs.get(abs(sh, p.o.r), true); if (rn) when = rn.mtime; }
    p.rest.forEach(function (f) {
      var full = abs(sh, f), n = sh.vfs.get(full, true);
      if (n) { n.mtime = when; return; }
      if (p.o.c) return;
      try { sh.vfs.write(full, ''); sh.vfs.get(full, true).mtime = when; } catch (e) { io.err.w("touch: cannot touch '" + f + "': " + errText(e) + '\n'); st = 1; }
    });
    return st;
  });
  def('mkdir', function (sh, args, io) {
    var p = parseOpts('mkdir', args, 'pv', 'm', io); if (!p) return 1, st = 0;
    var st = 0;
    p.rest.forEach(function (d) {
      try { sh.vfs.mkdir(abs(sh, d), !!p.o.p); if (p.o.m) sh.vfs.get(abs(sh, d), true).mode = parseInt(p.o.m, 8); if (p.o.v) io.out.w("mkdir: created directory '" + d + "'\n"); }
      catch (e) { io.err.w("mkdir: cannot create directory '" + d + "': " + errText(e) + '\n'); st = 1; }
    });
    return st;
  });
  def('rmdir', function (sh, args, io) { var st = 0; args.filter(function (a) { return a[0] !== '-'; }).forEach(function (d) { try { if (!sh.vfs.isDir(abs(sh, d))) throw { code: 'ENOTDIR' }; sh.vfs.remove(abs(sh, d), false); } catch (e) { io.err.w("rmdir: failed to remove '" + d + "': " + errText(e) + '\n'); st = 1; } }); return st; });
  def('rm', function (sh, args, io) {
    var p = parseOpts('rm', args, 'rfRvdi', '', io); if (!p) return 1;
    var st = 0, rec = p.o.r || p.o.R;
    if (!p.rest.length && !p.o.f) { io.err.w('rm: missing operand\n'); return 1; }
    p.rest.forEach(function (f) {
      var full = abs(sh, f);
      if (full === '/' ) { io.err.w("rm: it is dangerous to operate recursively on '/'\n"); st = 1; return; }
      if (!sh.vfs.lexists(full)) { if (!p.o.f) { io.err.w("rm: cannot remove '" + f + "': No such file or directory\n"); st = 1; } return; }
      if (sh.vfs.isDir(full) && !sh.vfs.get(full, false).target && sh.vfs.get(full, false).t === 'd' && !rec && !p.o.d) { io.err.w("rm: cannot remove '" + f + "': Is a directory\n"); st = 1; return; }
      try { sh.vfs.remove(full, !!rec); if (p.o.v) io.out.w("removed '" + f + "'\n"); } catch (e) { io.err.w("rm: cannot remove '" + f + "': " + errText(e) + '\n'); st = 1; }
    });
    return st;
  });
  function cpmv(sh, name, args, io, move) {
    var p = parseOpts(name, args, 'rRafvnpuiT', 't', io, { 'no-clobber': { key: 'n' }, 'recursive': { key: 'r' } }); if (!p) return 1;
    var rest = p.rest, st = 0;
    if (p.o.t) rest = rest.concat([p.o.t]);
    if (rest.length < 2) { io.err.w(name + ": missing destination file operand after '" + (rest[0] || '') + "'\n"); return 1; }
    var dest = rest[rest.length - 1], srcs = rest.slice(0, -1), dfull = abs(sh, dest), destIsDir = sh.vfs.isDir(dfull);
    if (srcs.length > 1 && !destIsDir) { io.err.w(name + ": target '" + dest + "' is not a directory\n"); return 1; }
    srcs.forEach(function (s) {
      var sfull = abs(sh, s);
      if (!sh.vfs.lexists(sfull)) { io.err.w(name + ": cannot stat '" + s + "': No such file or directory\n"); st = 1; return; }
      var target = destIsDir ? V.normalize(dfull + '/' + V.basename(sfull)) : dfull;
      if (p.o.n && sh.vfs.lexists(target)) return;
      try {
        if (move) sh.vfs.rename(sfull, target);
        else { if (sh.vfs.isDir(sfull) && !(p.o.r || p.o.R || p.o.a)) { io.err.w(name + ": -r not specified; omitting directory '" + s + "'\n"); st = 1; return; } sh.vfs.copy(sfull, target, !!(p.o.r || p.o.R || p.o.a)); if (p.o.p || p.o.a) { var sn = sh.vfs.get(sfull, true), tn = sh.vfs.get(target, true); if (sn && tn) { tn.mtime = sn.mtime; } } }
        if (p.o.v) io.out.w("'" + s + "' -> '" + dest + (destIsDir ? '/' + V.basename(sfull) : '') + "'\n");
      } catch (e) { io.err.w(name + ": cannot " + (move ? 'move' : 'create regular file') + " '" + s + "' to '" + dest + "': " + errText(e) + '\n'); st = 1; }
    });
    return st;
  }
  def('cp', function (sh, a, io) { return cpmv(sh, 'cp', a, io, false); });
  def('mv', function (sh, a, io) { return cpmv(sh, 'mv', a, io, true); });
  def('ln', function (sh, args, io) {
    var p = parseOpts('ln', args, 'sfvn', '', io); if (!p) return 1;
    if (p.rest.length < 2) { io.err.w('ln: missing file operand\n'); return 1; }
    var t = p.rest[0], l = abs(sh, p.rest[1]);
    if (sh.vfs.isDir(l)) l = V.normalize(l + '/' + V.basename(t));
    try { if (sh.vfs.lexists(l) && p.o.f) sh.vfs.remove(l); if (p.o.s) sh.vfs.symlink(t, l); else sh.vfs.copy(abs(sh, t), l); } catch (e) { io.err.w("ln: failed to create link '" + p.rest[1] + "': " + errText(e) + '\n'); return 1; }
    return 0;
  });
  def('chmod', function (sh, args, io) {
    var p = parseOpts('chmod', args.map(function (a) { return /^-[rwxXst]/.test(a) ? '\u0002' + a : a; }), 'Rv', '', io); if (!p) return 1;
    var mode = (p.rest.shift() || '').replace('\u0002', ''), st = 0;
    p.rest.forEach(function (f) {
      var walk = p.o.R ? sh.vfs.walk(abs(sh, f)) : [abs(sh, f)];
      walk.forEach(function (full) {
        var n = sh.vfs.get(full, true);
        if (!n) { io.err.w("chmod: cannot access '" + f + "': No such file or directory\n"); st = 1; return; }
        if (/^[0-7]+$/.test(mode)) n.mode = parseInt(mode, 8);
        else {
          var m = /^([ugoa]*)([-+=])([rwxXst]*)$/.exec(mode);
          if (!m) { io.err.w("chmod: invalid mode: '" + mode + "'\n"); st = 1; return; }
          var who = m[1] || 'a', bits = 0;
          if (m[3].indexOf('r') !== -1) bits |= 4; if (m[3].indexOf('w') !== -1) bits |= 2; if (m[3].indexOf('x') !== -1 || (m[3].indexOf('X') !== -1 && (n.t === 'd' || (n.mode & 0x49)))) bits |= 1;
          var mask = 0;
          if (/u|a/.test(who)) mask |= bits << 6; if (/g|a/.test(who)) mask |= bits << 3; if (/o|a/.test(who)) mask |= bits;
          var all = ((/u|a/.test(who) ? 7 << 6 : 0) | (/g|a/.test(who) ? 7 << 3 : 0) | (/o|a/.test(who) ? 7 : 0));
          if (m[2] === '+') n.mode |= mask; else if (m[2] === '-') n.mode &= ~mask; else n.mode = (n.mode & ~all) | mask;
        }
      });
    });
    return st;
  });
  def('chown chgrp sync nice ionice renice setsid', function (sh, args, io) { return 0; });
  def('stat', function (sh, args, io) {
    var p = parseOpts('stat', args, 'L', 'c', io, { 'format': { key: 'c', arg: true } }); if (!p) return 1;
    var st = 0;
    p.rest.forEach(function (f) {
      var n = sh.vfs.get(abs(sh, f), p.o.L ? true : false);
      if (!n) { io.err.w("stat: cannot statx '" + f + "': No such file or directory\n"); st = 1; return; }
      var size = sh.vfs.size(n);
      if (p.o.c !== undefined) {
        io.out.w(p.o.c.replace(/%([nsaAUGyYFmdxXzZ%])/g, function (m, c) {
          switch (c) { case 'n': return f; case 's': return String(size); case 'a': return (n.mode & 0xfff).toString(8); case 'A': return modeString(n); case 'U': return n.owner || 'admin'; case 'G': return n.owner || 'admin';
            case 'y': return strftime('%F %T.000000000 +0000', n.mtime); case 'Y': return String(n.mtime); case 'F': return n.t === 'd' ? 'directory' : (n.t === 'l' ? 'symbolic link' : (size ? 'regular file' : 'regular empty file')); case '%': return '%'; default: return m; }
        }) + '\n');
      } else io.out.w('  File: ' + f + '\n  Size: ' + size + '\t\tBlocks: ' + Math.ceil(size / 512) + '\nAccess: (0' + (n.mode & 0xfff).toString(8) + '/' + modeString(n) + ')  Uid: ( 1000/ ' + (n.owner || 'admin') + ')\nModify: ' + strftime('%F %T.000000000 +0000', n.mtime) + '\n');
    });
    return st;
  });
  function sizeOf(sh, full, apparent) {
    var vfs = sh.vfs, total = 0;
    vfs.walk(full).forEach(function (p) { var n = vfs.get(p, false); if (n) total += n.t === 'd' ? (apparent ? 0 : 4096) : vfs.size(n); });
    return total;
  }
  def('du', function (sh, args, io) {
    var p = parseOpts('du', args, 'shkacbmx', 'd', io, { 'max-depth': { key: 'd', arg: true }, 'apparent-size': { key: 'A' }, 'summarize': { key: 's' }, 'human-readable': { key: 'h' } }); if (!p) return 1;
    var targets = p.rest.length ? p.rest : ['.'], st = 0, grand = 0, fmt = function (n) { return p.o.h ? human(n) : (p.o.b ? String(n) : String(Math.ceil(n / 1024))); };
    targets.forEach(function (t) {
      var full = abs(sh, t);
      if (!sh.vfs.lexists(full)) { io.err.w("du: cannot access '" + t + "': No such file or directory\n"); st = 1; return; }
      var apparent = !!(p.o.b || p.o.A);
      var depthMax = p.o.s ? 0 : (p.o.d !== undefined ? parseInt(p.o.d, 10) : Infinity);
      var rows = [];
      (function rec(path, shown, depth) {
        var n = sh.vfs.get(path, false), total = 0;
        if (n && n.t === 'd') { sh.vfs.list(path).forEach(function (c) { total += rec(V.normalize(path + '/' + c), shown + '/' + c, depth + 1); }); total += apparent ? 0 : 4096; if (depth <= depthMax) rows.push([total, shown]); }
        else if (n) { total = sh.vfs.size(n); if (p.o.a && depth <= depthMax) rows.push([total, shown]); }
        return total;
      })(full, t.replace(/\/$/, '') || t, 0);
      var rootTotal = rows.length ? rows[rows.length - 1][0] : sizeOf(sh, full, apparent);
      if (!rows.length) rows.push([rootTotal, t]);
      grand += rootTotal;
      rows.forEach(function (r) { io.out.w(fmt(r[0]) + '\t' + r[1] + '\n'); });
    });
    if (p.o.c) io.out.w(fmt(grand) + '\ttotal\n');
    return st;
  });
  def('df', function (sh, args, io) {
    var p = parseOpts('df', args, 'hkPTi', '', io, { 'output': { key: 'out', arg: true } }); if (!p) return 1;
    var vfs = sh.vfs, mounts = vfs.mounts.slice(), want = p.rest.length ? p.rest : [];
    var rootM = { path: '/', cap: 20 * 1024 * 1024 * 1024, root: true };
    var info = function (m) { var used = m.root ? 6 * 1024 * 1024 * 1024 : vfs.used(m); return { fs: m.root ? '/dev/sda1' : '/dev/' + m.path.split('/').pop(), path: m.path, size: m.cap, used: used, avail: Math.max(0, m.cap - used) }; };
    var rows = [];
    if (want.length) want.forEach(function (w) { var full = abs(sh, w); if (!vfs.exists(full)) { io.err.w("df: " + w + ': No such file or directory\n'); return; } rows.push(info(vfs.mountOf(full) || rootM)); });
    else { rows.push(info(rootM)); mounts.forEach(function (m) { rows.push(info(m)); }); }
    var fmt = function (n) { return p.o.h ? human(n) : String(Math.floor(n / 1024)); };
    if (p.o.out) {
      var cols = p.o.out.split(',');
      io.out.w(cols.map(function (c) { return { avail: 'Avail', pcent: 'Use%', used: 'Used', size: 'Size', target: 'Mounted on', source: 'Filesystem' }[c] || c; }).join(' ') + '\n');
      rows.forEach(function (r) { io.out.w(cols.map(function (c) { return { avail: fmt(r.avail), used: fmt(r.used), size: fmt(r.size), pcent: Math.ceil(100 * r.used / r.size) + '%', target: r.path, source: r.fs }[c]; }).join(' ') + '\n'); });
      return 0;
    }
    io.out.w('Filesystem' + (p.o.h ? '' : '     1K-blocks') + '    Used Available Use% Mounted on\n'.replace(/^/, p.o.h ? '      Size' : ''));
    rows.forEach(function (r) { io.out.w(r.fs + ' ' + fmt(r.size) + ' ' + fmt(r.used) + ' ' + fmt(r.avail) + ' ' + Math.ceil(100 * r.used / r.size) + '% ' + r.path + '\n'); });
    return 0;
  });
  def('ls', function (sh, args, io) {
    var p = parseOpts('ls', args, 'laA1trSdhRFp', '', io, { 'color': { key: 'color' } }); if (!p) return 2;
    var vfs = sh.vfs, st = 0, targets = p.rest.length ? p.rest : ['.'], multi = targets.length > 1 || p.o.R;
    function entry(dir, name) { var path = V.normalize(dir + '/' + name), n = vfs.get(path, false); return { name: name, path: path, n: n }; }
    function show(e) {
      var n = e.n, label = e.name + (p.o.F ? (n.t === 'd' ? '/' : (n.t === 'l' ? '@' : ((n.mode & 0x49) ? '*' : ''))) : (p.o.p && n.t === 'd' ? '/' : ''));
      if (!p.o.l) return label;
      var d = new Date(n.mtime * 1000), recent = vfs.now - n.mtime < 15552000 && n.mtime <= vfs.now + 3600;
      var when = MON[d.getUTCMonth()] + ' ' + ('  ' + d.getUTCDate()).slice(-2) + ' ' + (recent ? two(d.getUTCHours()) + ':' + two(d.getUTCMinutes()) : ' ' + d.getUTCFullYear());
      var size = vfs.size(n);
      return modeString(n) + ' 1 ' + (n.owner || 'admin') + ' ' + (n.owner || 'admin') + ' ' + (p.o.h ? human(size) : String(size)) + ' ' + when + ' ' + label + (n.t === 'l' ? ' -> ' + n.target : '');
    }
    function sortEntries(es) {
      es.sort(function (a, b) { if (p.o.S) return vfs.size(b.n) - vfs.size(a.n) || (a.name < b.name ? -1 : 1); if (p.o.t) return b.n.mtime - a.n.mtime || (a.name < b.name ? -1 : 1); return a.name < b.name ? -1 : (a.name > b.name ? 1 : 0); });
      if (p.o.r) es.reverse();
      return es;
    }
    function listDir(dirShown, full, header) {
      if (header) io.out.w(dirShown + ':\n');
      var names = vfs.list(full).filter(function (nm) { return p.o.a || p.o.A || nm[0] !== '.'; });
      var es = names.map(function (nm) { return entry(full, nm); });
      if (p.o.a) es = [entry(full, '.'), entry(full, '..')].map(function (e) { if (!e.n) e.n = vfs.get(full, true); return e; }).concat(es);
      es = sortEntries(es);
      if (p.o.l) io.out.w('total ' + es.reduce(function (s, e) { return s + Math.ceil(vfs.size(e.n) / 1024) * 1; }, 0) + '\n');
      es.forEach(function (e) { io.out.w(show(e) + '\n'); });
      if (p.o.R) es.forEach(function (e) { if (e.n.t === 'd' && e.name !== '.' && e.name !== '..') { io.out.w('\n'); listDir(dirShown + '/' + e.name, e.path, true); } });
    }
    var files = [], dirs = [];
    targets.forEach(function (t) {
      var full = abs(sh, t), n = vfs.get(full, false);
      if (!n) { io.err.w("ls: cannot access '" + t + "': No such file or directory\n"); st = 2; return; }
      if (n.t === 'l' && !p.o.l && vfs.isDir(full) && !p.o.d) n = vfs.get(full, true);
      if (n.t === 'd' && !p.o.d) dirs.push([t, vfs.real(full, true)]); else files.push({ name: t, path: full, n: n });
    });
    sortEntries(files).forEach(function (e) { io.out.w(show(e) + '\n'); });
    dirs.sort(function (a, b) { return a[0] < b[0] ? -1 : 1; }).forEach(function (d, i) { if ((files.length || i) && multi) io.out.w('\n'); listDir(d[0], d[1], multi); });
    return st;
  });

  // ——— The system ———
  def('date', function (sh, args, io) {
    var t = sh.vfs.now, fmt = '%a %b %e %H:%M:%S UTC %Y', i = 0;
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '-d' || a === '--date') { var v = parseDate(args[++i] || '', sh.vfs.now); if (v === null) { io.err.w("date: invalid date '" + args[i] + "'\n"); return 1; } t = v; }
      else if (/^--date=/.test(a)) { var v2 = parseDate(a.slice(7), sh.vfs.now); if (v2 === null) { io.err.w("date: invalid date '" + a.slice(7) + "'\n"); return 1; } t = v2; }
      else if (a === '-u' || a === '--utc') { }
      else if (a === '-r') { var n = sh.vfs.get(abs(sh, args[++i] || ''), true); if (n) t = n.mtime; }
      else if (a === '-I' || a === '--iso-8601') fmt = '%F';
      else if (a[0] === '+') fmt = a.slice(1);
    }
    io.out.w(strftime(fmt, t) + '\n'); return 0;
  });
  def('sleep', function (sh, args, io) { return 0; });
  def('id', function (sh, args, io) {
    if (args.indexOf('-u') !== -1) io.out.w('1000\n'); else if (args.indexOf('-un') !== -1 || args.indexOf('-nu') !== -1) io.out.w('admin\n'); else if (args.indexOf('-g') !== -1) io.out.w('1000\n');
    else io.out.w('uid=1000(admin) gid=1000(admin) groups=1000(admin),27(sudo)\n');
    return 0;
  });
  def('whoami logname', function (sh, args, io) { io.out.w('admin\n'); return 0; });
  def('hostname', function (sh, args, io) { io.out.w((sh.m.name || 'localhost') + '\n'); return 0; });
  def('uname', function (sh, args, io) { io.out.w(args.indexOf('-n') !== -1 ? (sh.m.name || 'localhost') + '\n' : (args.indexOf('-r') !== -1 ? '6.12.0-amd64\n' : (args.indexOf('-a') !== -1 ? 'Linux ' + sh.m.name + ' 6.12.0-amd64 #1 SMP Debian x86_64 GNU/Linux\n' : 'Linux\n'))); return 0; });
  def('uptime', function (sh, args, io) { io.out.w(' 03:07:00 up 41 days,  2:11,  1 user,  load average: 0.12, 0.09, 0.05\n'); return 0; });
  def('env', function (sh, args, io) {
    var i = 0, extra = {};
    for (; i < args.length; i++) { var m = /^([A-Za-z_][A-Za-z0-9_]*)=([\s\S]*)$/.exec(args[i]); if (m) extra[m[1]] = m[2]; else if (args[i] === '-i') { } else break; }
    if (i < args.length) {
      var sub = sh.fork(); Object.keys(extra).forEach(function (k) { sub.vars[k] = { kind: 's', v: extra[k], exp: true }; });
      return sub.dispatch(args.slice(i), io);
    }
    var e = sh.exported(); Object.keys(extra).forEach(function (k) { e[k] = extra[k]; });
    Object.keys(e).forEach(function (k) { io.out.w(k + '=' + e[k] + '\n'); }); return 0;
  });
  def('printenv', function (sh, args, io) { var e = sh.exported(), st = 0; if (!args.length) Object.keys(e).forEach(function (k) { io.out.w(k + '=' + e[k] + '\n'); }); else args.forEach(function (k) { if (e[k] !== undefined) io.out.w(e[k] + '\n'); else st = 1; }); return st; });
  def('sudo', function (sh, args, io) {
    var i = 0; while (i < args.length && args[i][0] === '-') { if (args[i] === '-u' || args[i] === '-g') i++; i++; }
    if (i >= args.length) return 0;
    return sh.dispatch(args.slice(i), io);
  });
  def('nohup', function (sh, args, io) { return args.length ? sh.dispatch(args, io) : 0; });
  def('timeout', function (sh, args, io) {
    var i = 0; while (i < args.length && args[i][0] === '-') { if (args[i] === '-k' || args[i] === '-s') i++; i++; }
    var dur = parseFloat(args[i] || '0'); i++;
    if (args[i] === 'sleep' && parseFloat(args[i + 1]) > dur) return 124;
    return args[i] ? sh.dispatch(args.slice(i), io) : 0;
  });
  def('pgrep pidof pkill', function () { return 1; });
  def('ps', function (sh, args, io) { io.out.w('    PID TTY          TIME CMD\n   4100 pts/0    00:00:00 bash\n'); return 0; });
  def('clear tput reset', function () { return 0; });
  def('less more', function (sh, args, io) { return table.cat(sh, args, io); });
  def('logger', function (sh, args, io) {
    var tag = 'admin', i = 0, toErr = false;
    for (; i < args.length; i++) { if (args[i] === '-t') tag = args[++i]; else if (args[i] === '-p') i++; else if (args[i] === '-s') toErr = true; else if (args[i] === '-i') { } else break; }
    var msg = args.slice(i).join(' '), line = strftime('%b %e %H:%M:%S', sh.vfs.now) + ' ' + sh.m.name + ' ' + tag + ': ' + msg + '\n';
    try { sh.vfs.write('/var/log/syslog', line, true); } catch (e) { io.err.w('logger: cannot write the system log\n'); return 1; }
    if (toErr) io.err.w(tag + ': ' + msg + '\n');
    return 0;
  });
  def('mail mailx', function (sh, args, io) {
    var subject = '', i = 0, to = [];
    for (; i < args.length; i++) { if (args[i] === '-s') subject = args[++i]; else if (args[i][0] !== '-') to.push(args[i]); }
    if (sh.m.mailDown) { io.err.w('mail: cannot send message: the mail server does not answer\n'); return 1; }
    if (!to.length) { io.err.w('mail: no recipients specified\n'); return 1; }
    var body = io.in ? io.in.readAll() : '';
    try { sh.vfs.write('/var/spool/mail-outbox', 'To: ' + to.join(', ') + '\nSubject: ' + subject + '\n\n' + body + '\n--\n', true); } catch (e) { io.err.w('mail: cannot write the outbox\n'); return 1; }
    return 0;
  });
  def('mktemp', function (sh, args, io) {
    var dir = args.indexOf('-d') !== -1, tpl = args.filter(function (a) { return a[0] !== '-'; })[0];
    sh.m.tmpN = (sh.m.tmpN || 0) + 1;
    var suffix = ('AbCdEf0123' + sh.m.tmpN).slice(-8), path = tpl ? (tpl.indexOf('/') === -1 ? sh.cwd + '/' : '') + tpl.replace(/X+$/, suffix) : '/tmp/tmp.' + suffix;
    try { if (dir) sh.vfs.mkdir(abs(sh, path), true); else sh.vfs.write(abs(sh, path), ''); } catch (e) { io.err.w('mktemp: failed to create ' + (dir ? 'directory' : 'file') + ' via template ‘' + path + '’: ' + errText(e) + '\n'); return 1; }
    io.out.w(path + '\n'); return 0;
  });
  // sha256
  function sha256(str) {
    var bytes = []; for (var i = 0; i < str.length; i++) { var c = str.charCodeAt(i); if (c < 128) bytes.push(c); else if (c < 2048) bytes.push(192 | c >> 6, 128 | c & 63); else bytes.push(224 | c >> 12, 128 | (c >> 6) & 63, 128 | c & 63); }
    var K = [0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070, 0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2];
    var H = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19], l = bytes.length * 8;
    bytes.push(0x80); while (bytes.length % 64 !== 56) bytes.push(0);
    for (var k = 7; k >= 0; k--) bytes.push(k > 3 ? 0 : (l >>> (k * 8)) & 255);
    var rotr = function (x, n) { return (x >>> n) | (x << (32 - n)); };
    for (var o = 0; o < bytes.length; o += 64) {
      var w = [];
      for (var t = 0; t < 16; t++) w[t] = (bytes[o + 4 * t] << 24) | (bytes[o + 4 * t + 1] << 16) | (bytes[o + 4 * t + 2] << 8) | bytes[o + 4 * t + 3];
      for (t = 16; t < 64; t++) { var s0 = rotr(w[t - 15], 7) ^ rotr(w[t - 15], 18) ^ (w[t - 15] >>> 3), s1 = rotr(w[t - 2], 17) ^ rotr(w[t - 2], 19) ^ (w[t - 2] >>> 10); w[t] = (w[t - 16] + s0 + w[t - 7] + s1) | 0; }
      var a = H[0], b = H[1], c2 = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
      for (t = 0; t < 64; t++) {
        var S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25), ch = (e & f) ^ (~e & g), t1 = (h + S1 + ch + K[t] + w[t]) | 0, S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22), mj = (a & b) ^ (a & c2) ^ (b & c2), t2 = (S0 + mj) | 0;
        h = g; g = f; f = e; e = (d + t1) | 0; d = c2; c2 = b; b = a; a = (t1 + t2) | 0;
      }
      H[0] = (H[0] + a) | 0; H[1] = (H[1] + b) | 0; H[2] = (H[2] + c2) | 0; H[3] = (H[3] + d) | 0; H[4] = (H[4] + e) | 0; H[5] = (H[5] + f) | 0; H[6] = (H[6] + g) | 0; H[7] = (H[7] + h) | 0;
    }
    return H.map(function (x) { return ('00000000' + (x >>> 0).toString(16)).slice(-8); }).join('');
  }
  function contentHash(sh, full) { var n = sh.vfs.get(full, true); return sha256((n.sparse ? 'SPARSE:' + n.sparse + ':' + (n.seed || '') : n.data)); }
  def('sha256sum md5sum sha1sum cksum', function (sh, args, io) {
    var check = args.indexOf('-c') !== -1, files = args.filter(function (a) { return a[0] !== '-'; }), st = 0;
    if (check) {
      var bad = 0, miss = 0, src = files.length ? [files[0]] : ['-'];
      var inp = inputs(sh, 'sha256sum', src, io); if (inp.bad) return 1;
      lines(inp.list[0].data).forEach(function (l) {
        var m = /^([0-9a-f]{64}) [ *](.*)$/.exec(l); if (!m) return;
        var full = abs(sh, m[2]);
        if (!sh.vfs.isFile(full)) { io.out.w(m[2] + ': FAILED open or read\n'); io.err.w('sha256sum: ' + m[2] + ': No such file or directory\n'); miss++; return; }
        if (contentHash(sh, full) === m[1]) io.out.w(m[2] + ': OK\n'); else { io.out.w(m[2] + ': FAILED\n'); bad++; }
      });
      if (miss) io.err.w('sha256sum: WARNING: ' + miss + ' listed file' + (miss > 1 ? 's' : '') + ' could not be read\n');
      if (bad) io.err.w('sha256sum: WARNING: ' + bad + ' computed checksum' + (bad > 1 ? 's' : '') + ' did NOT match\n');
      return bad || miss ? 1 : 0;
    }
    if (!files.length) { io.out.w(sha256(io.in ? io.in.readAll() : '') + '  -\n'); return 0; }
    files.forEach(function (f) { var full = abs(sh, f); if (!sh.vfs.isFile(full)) { io.err.w('sha256sum: ' + f + ': No such file or directory\n'); st = 1; return; } io.out.w(contentHash(sh, full) + '  ' + f + '\n'); });
    return st;
  });
  def('mountpoint', function (sh, args, io) { var p = abs(sh, args[args.length - 1] || '/'); var m = sh.vfs.mounts.some(function (x) { return x.path === p; }); io.out.w(args[args.length - 1] + (m ? ' is a mountpoint\n' : ' is not a mountpoint\n')); return m ? 0 : 1; });

  root.RCUtils = { def: def, get: function (n) { return table[n]; }, table: table, parseOpts: parseOpts, inputs: inputs, lines: lines, abs: abs, errText: errText, strftime: strftime, parseDate: parseDate, human: human, modeString: modeString, readFileArg: readFileArg, sha256: sha256, contentHash: contentHash, two: two, MON: MON };
})(typeof globalThis !== 'undefined' ? globalThis : window);
