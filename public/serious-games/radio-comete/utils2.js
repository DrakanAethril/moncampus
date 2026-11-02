/* Radio-Comète — more commands: find, xargs, tar, gzip, diff, ssh, scp, crontab, systemctl,
   journalctl, flock. */
(function (root) {
  'use strict';
  var V = root.RCVfs, R = root.RCRegex, U = root.RCUtils, S = root.RCShell, def = U.def, abs = U.abs, errText = U.errText;
  var Stream = S.Stream, Buf = S.Buf;

  // ——— find ———
  function findCmd(sh, args, io) {
    var vfs = sh.vfs, starts = [], i = 0;
    for (; i < args.length && args[i][0] !== '-' && args[i] !== '!' && args[i] !== '('; i++) starts.push(args[i]);
    if (!starts.length) starts = ['.'];
    var exprArgs = args.slice(i), maxdepth = Infinity, mindepth = 0, depthFirst = false, p = 0, hasAction = false, execPlus = [];
    // global options may appear anywhere: pull them out
    var rest = [];
    for (var k = 0; k < exprArgs.length; k++) {
      var a = exprArgs[k];
      if (a === '-maxdepth') maxdepth = parseInt(exprArgs[++k], 10); else if (a === '-mindepth') mindepth = parseInt(exprArgs[++k], 10); else if (a === '-depth') depthFirst = true; else if (a === '-xdev' || a === '-mount' || a === '-noleaf') { } else rest.push(a);
    }
    exprArgs = rest;
    function err(msg) { throw { find: msg }; }
    function parseOr() { var l = parseAnd(); while (exprArgs[p] === '-o' || exprArgs[p] === '-or') { p++; var r = parseAnd(); l = { t: 'or', l: l, r: r }; } return l; }
    function parseAnd() {
      var l = parseNot();
      while (p < exprArgs.length && exprArgs[p] !== '-o' && exprArgs[p] !== '-or' && exprArgs[p] !== ')') { if (exprArgs[p] === '-a' || exprArgs[p] === '-and') p++; var r = parseNot(); l = { t: 'and', l: l, r: r }; }
      return l;
    }
    function parseNot() { if (exprArgs[p] === '!' || exprArgs[p] === '-not') { p++; return { t: 'not', e: parseNot() }; } return parsePrim(); }
    function need() { if (p >= exprArgs.length) err('missing argument to `' + exprArgs[p - 1] + "'"); return exprArgs[p++]; }
    function parsePrim() {
      var a = exprArgs[p++];
      if (a === undefined) err('invalid expression');
      if (a === '(') { var e = parseOr(); if (exprArgs[p] !== ')') err('unmatched ( or invalid expression'); p++; return e; }
      switch (a) {
        case '-name': return { t: 'name', re: R.globRe(need()) };
        case '-iname': return { t: 'name', re: R.globRe(need(), 'i') };
        case '-path': case '-wholename': return { t: 'path', re: R.globRe(need()) };
        case '-ipath': return { t: 'path', re: R.globRe(need(), 'i') };
        case '-regex': return { t: 'regex', re: R.re('^(?:' + need() + ')$', 'ere') };
        case '-type': return { t: 'type', c: need() };
        case '-mtime': case '-atime': case '-ctime': return { t: 'time', unit: 86400, arg: need() };
        case '-mmin': case '-amin': case '-cmin': return { t: 'time', unit: 60, arg: need() };
        case '-newer': return { t: 'newer', f: need() };
        case '-size': return { t: 'size', arg: need() };
        case '-empty': return { t: 'empty' };
        case '-user': case '-group': return { t: 'user', u: need() };
        case '-perm': return { t: 'perm', arg: need() };
        case '-readable': case '-writable': case '-executable': return { t: 'true' };
        case '-true': return { t: 'true' }; case '-false': return { t: 'false' };
        case '-prune': return { t: 'prune' };
        case '-print': hasAction = true; return { t: 'print', nul: false };
        case '-print0': hasAction = true; return { t: 'print', nul: true };
        case '-quit': hasAction = true; return { t: 'quit' };
        case '-ls': hasAction = true; return { t: 'ls' };
        case '-delete': hasAction = true; depthFirst = true; return { t: 'delete' };
        case '-printf': hasAction = true; return { t: 'printf', fmt: need() };
        case '-exec': case '-ok': {
          hasAction = true; var cmd = [];
          while (p < exprArgs.length && exprArgs[p] !== ';' && exprArgs[p] !== '+') cmd.push(exprArgs[p++]);
          if (p >= exprArgs.length) err('missing argument to `-exec\'');
          var plus = exprArgs[p++] === '+';
          return { t: 'exec', cmd: cmd, plus: plus };
        }
      }
      err('unknown predicate `' + a + "'");
    }
    var tree;
    try { tree = exprArgs.length ? parseOr() : null; if (p < exprArgs.length) err('paths must precede expression: `' + exprArgs[p] + "'"); }
    catch (e) { if (e && e.find) { io.err.w('find: ' + e.find + '\n'); return 1; } throw e; }
    var status = 0, quit = false, now = vfs.now;
    var plusBatches = [];
    function numCmp(arg, v) { var m = /^([+-]?)(\d+)$/.exec(arg); if (!m) err('invalid argument `' + arg + "' to"); var n = +m[2]; return m[1] === '+' ? v > n : (m[1] === '-' ? v < n : v === n); }
    function evalNode(n, c) {
      switch (n.t) {
        case 'and': return evalNode(n.l, c) && evalNode(n.r, c);
        case 'or': return evalNode(n.l, c) || evalNode(n.r, c);
        case 'not': return !evalNode(n.e, c);
        case 'true': return true; case 'false': return false;
        case 'name': return n.re.test(c.name);
        case 'path': return n.re.test(c.shown);
        case 'regex': return n.re.test(c.shown);
        case 'type': { var t = c.node.t; return n.c === 'f' ? t === 'f' : (n.c === 'd' ? t === 'd' : (n.c === 'l' ? t === 'l' : false)); }
        case 'time': return numCmp(n.arg.replace(/^[+-]?/, function (s) { return s; }), Math.floor((now - c.node.mtime) / n.unit));
        case 'newer': { var r = vfs.get(abs(sh, n.f), true); return !!r && c.node.mtime > r.mtime; }
        case 'size': {
          var m = /^([+-]?)(\d+)([ckMGbw]?)$/.exec(n.arg); if (!m) err('invalid argument `' + n.arg + "' to `-size'");
          var unit = { c: 1, k: 1024, M: 1048576, G: 1073741824, b: 512, w: 2, '': 512 }[m[3]], sz = Math.ceil(vfs.size(c.node) / unit), v = +m[2];
          return m[1] === '+' ? sz > v : (m[1] === '-' ? sz < v : sz === v);
        }
        case 'empty': return c.node.t === 'f' ? vfs.size(c.node) === 0 : (c.node.t === 'd' ? vfs.list(c.path).length === 0 : false);
        case 'user': return (c.node.owner || 'admin') === n.u;
        case 'perm': { var pm = parseInt(n.arg.replace(/^[-\/]/, ''), 8), mode = c.node.mode & 0xfff; return n.arg[0] === '-' ? (mode & pm) === pm : (n.arg[0] === '/' ? (mode & pm) !== 0 : mode === pm); }
        case 'prune': c.prune = true; return true;
        case 'print': io.out.w(c.shown + (n.nul ? '\0' : '\n')); return true;
        case 'quit': quit = true; return true;
        case 'ls': io.out.w('  ' + (vfs.size(c.node)) + ' ' + U.modeString(c.node) + ' 1 admin admin ' + vfs.size(c.node) + ' ' + U.strftime('%b %e %H:%M', c.node.mtime) + ' ' + c.shown + '\n'); return true;
        case 'delete': try { if (c.path !== '/' ) vfs.remove(c.path, false); return true; } catch (e) { io.err.w("find: cannot delete '" + c.shown + "': " + errText(e) + '\n'); status = 1; return false; }
        case 'printf': io.out.w(n.fmt.replace(/%(T@|[pfhsmuyg%])|\\([nt0])/g, function (mm, d, esc) {
          if (esc) return { n: '\n', t: '\t', 0: '\0' }[esc];
          switch (d) { case 'p': return c.shown; case 'f': return c.name; case 'h': return c.shown.indexOf('/') === -1 ? '.' : c.shown.slice(0, c.shown.lastIndexOf('/')); case 's': return String(vfs.size(c.node)); case 'm': return (c.node.mode & 0xfff).toString(8); case 'u': case 'g': return c.node.owner || 'admin'; case 'y': return c.node.t === 'd' ? 'd' : (c.node.t === 'l' ? 'l' : 'f'); case 'T@': return String(c.node.mtime) + '.0000000000'; default: return '%'; }
        })); return true;
        case 'exec': {
          if (n.plus) { var b = n.batch || (n.batch = []); b.push(c.shown); if (plusBatches.indexOf(n) === -1) plusBatches.push(n); return true; }
          var argv = n.cmd.map(function (x) { return x.replace(/\{\}/g, c.shown); });
          var st = sh.dispatch(argv, { in: null, out: io.out, err: io.err });
          return st === 0;
        }
      }
      return true;
    }
    function visit(path, shown, depth, name) {
      if (quit) return;
      var node = vfs.get(path, false);
      if (!node) { io.err.w("find: '" + shown + "': No such file or directory\n"); status = 1; return; }
      var c = { path: path, shown: shown, node: node, name: name, prune: false }, isDir = node.t === 'd';
      var doTest = function () { if (depth < mindepth) return; var ok; if (tree === null) ok = true; else ok = evalNode(tree, c); if (tree === null || (!hasAction && ok)) { if (ok) io.out.w(shown + '\n'); } };
      if (!depthFirst) doTest();
      if (isDir && !c.prune && depth < maxdepth) {
        vfs.list(path).forEach(function (child) { visit(V.normalize(path + '/' + child), (shown === '/' ? '' : shown.replace(/\/$/, '')) + '/' + child, depth + 1, child); });
      }
      if (depthFirst) doTest();
    }
    starts.forEach(function (s) {
      var full = abs(sh, s);
      if (!vfs.lexists(full)) { io.err.w("find: '" + s + "': No such file or directory\n"); status = 1; return; }
      try { visit(full, s, 0, V.basename(s) === '/' ? '/' : V.basename(s.replace(/\/+$/, '')) || '.'); } catch (e) { if (e && e.find) { io.err.w('find: ' + e.find + '\n'); status = 1; } else throw e; }
    });
    plusBatches.forEach(function (n) { var argv = []; n.cmd.forEach(function (x) { if (x === '{}') argv.push.apply(argv, n.batch); else argv.push(x); }); if (n.batch && n.batch.length) { if (sh.dispatch(argv, { in: null, out: io.out, err: io.err }) !== 0) status = 1; } });
    return status;
  }
  def('find', findCmd);

  // ——— xargs ———
  def('xargs', function (sh, args, io) {
    var nul = false, per = Infinity, repl = null, noRun = false, delim = null, i = 0, verbose = false;
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '-0' || a === '--null') nul = true; else if (a === '-r' || a === '--no-run-if-empty') noRun = true; else if (a === '-t') verbose = true;
      else if (a === '-n') per = parseInt(args[++i], 10); else if (/^-n./.test(a)) per = parseInt(a.slice(2), 10);
      else if (a === '-L') per = parseInt(args[++i], 10);
      else if (a === '-I') repl = args[++i]; else if (/^-I./.test(a)) repl = a.slice(2); else if (a === '-i') repl = '{}';
      else if (a === '-d') delim = args[++i].replace(/\\n/g, '\n'); else if (a === '-P') i++; else if (a === '-s') i++; else if (a === '-a') i++;
      else if (a === '-p' || a === '--') { if (a === '--') { i++; break; } } else if (a[0] === '-' && a.length > 1) { io.err.w("xargs: invalid option -- '" + a.slice(1) + "'\n"); return 1; }
      else break;
    }
    var cmd = args.slice(i); if (!cmd.length) cmd = ['echo'];
    var data = io.in ? io.in.readAll() : '', items = [];
    if (nul) items = data.split('\0').filter(function (x, k, all) { return x !== '' || k < all.length - 1 && false; }).filter(function (x) { return x !== ''; });
    else if (delim !== null) { items = data.split(delim); if (items.length && items[items.length - 1] === '') items.pop(); }
    else if (repl !== null) items = data.split('\n').filter(function (x) { return x.trim() !== ''; }).map(function (x) { return x.replace(/^\s+/, ''); });
    else {
      var cur = '', q = null, has = false;
      for (var k = 0; k < data.length; k++) {
        var ch = data[k];
        if (q) { if (ch === q) q = null; else cur += ch; continue; }
        if (ch === '"' || ch === "'") { q = ch; has = true; continue; }
        if (ch === '\\' && k + 1 < data.length) { cur += data[++k]; has = true; continue; }
        if (/\s/.test(ch)) { if (cur !== '' || has) { items.push(cur); cur = ''; has = false; } continue; }
        cur += ch;
      }
      if (cur !== '' || has) items.push(cur);
    }
    if (!items.length) { if (noRun) return 0; return sh.dispatch(cmd, { in: null, out: io.out, err: io.err }) === 0 ? 0 : 123; }
    var failed = false, status127 = false;
    function runOne(argv) {
      if (verbose) io.err.w(argv.join(' ') + '\n');
      var st = sh.dispatch(argv, { in: new Stream(''), out: io.out, err: io.err });
      if (st === 127) status127 = true; else if (st !== 0) failed = true;
    }
    if (repl !== null) items.forEach(function (it) { runOne(cmd.map(function (c) { return c.split(repl).join(it); })); });
    else for (var g = 0; g < items.length; g += per) runOne(cmd.concat(items.slice(g, g + per)));
    return status127 ? 127 : (failed ? 123 : 0);
  });

  // ——— tar, gzip ———
  var TAR = 'TAR1\n', GZ = 'GZ1\n';
  function tarEntries(sh, paths, cwd, excludes) {
    var entries = [], vfs = sh.vfs, total = 0;
    paths.forEach(function (p) {
      var full = V.resolve(cwd, p);
      if (!vfs.lexists(full)) { entries.push({ missing: p }); return; }
      vfs.walk(full).forEach(function (f) {
        var rel = f === full ? p.replace(/^\/+/, '').replace(/\/+$/, '') : p.replace(/^\/+/, '').replace(/\/+$/, '') + f.slice(full.length);
        if (excludes.some(function (re) { return re.test(rel) || re.test(V.basename(f)); })) return;
        var n = vfs.get(f, false);
        var e = { p: rel + (n.t === 'd' ? '/' : ''), t: n.t, m: n.mode, mt: n.mtime };
        if (n.t === 'f') { e.d = n.data; if (n.sparse) { e.sp = n.sparse; e.seed = n.seed; } total += vfs.size(n); }
        if (n.t === 'l') e.l = n.target;
        entries.push(e);
      });
    });
    return { entries: entries, total: total };
  }
  function readArchive(sh, file, gz, io, name) {
    var full = abs(sh, file), d;
    try { d = sh.vfs.read(full); } catch (e) { io.err.w(name + ': ' + file + ': Cannot open: ' + errText(e) + '\n' + name + ': Error is not recoverable: exiting now\n'); return null; }
    if (gz && d.indexOf(GZ) === 0) d = d.slice(GZ.length);
    else if (gz && d.indexOf(TAR) !== 0) { io.err.w('gzip: stdin: not in gzip format\n' + name + ': Child returned status 1\n' + name + ': Error is not recoverable: exiting now\n'); return null; }
    if (d.indexOf(TAR) !== 0) { io.err.w(name + ': This does not look like a tar archive\n' + name + ': Exiting with failure status due to previous errors\n'); return null; }
    var j;
    try { j = JSON.parse(d.slice(TAR.length)); } catch (e2) { io.err.w((gz ? 'gzip: stdin: unexpected end of file\n' : '') + name + ': Unexpected EOF in archive\n' + name + ': Error is not recoverable: exiting now\n'); return null; }
    return j;
  }
  def('tar', function (sh, args, io) {
    var mode = null, gz = false, file = null, dir = sh.cwd, verbose = false, excludes = [], paths = [], i = 0, strip = 0, keepOld = false;
    if (args.length && args[0][0] !== '-' ) args = ['-' + args[0]].concat(args.slice(1));
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '--exclude') { excludes.push(R.globRe(args[++i])); continue; }
      if (/^--exclude=/.test(a)) { excludes.push(R.globRe(a.slice(10))); continue; }
      if (a === '--strip-components') { strip = parseInt(args[++i], 10); continue; }
      if (/^--strip-components=/.test(a)) { strip = parseInt(a.slice(19), 10); continue; }
      if (a === '--gzip') { gz = true; continue; } if (a === '--create') { mode = 'c'; continue; } if (a === '--extract') { mode = 'x'; continue; } if (a === '--list') { mode = 't'; continue; }
      if (a === '--file') { file = args[++i]; continue; } if (/^--file=/.test(a)) { file = a.slice(7); continue; }
      if (a === '--directory') { dir = V.resolve(sh.cwd, args[++i]); continue; }
      if (a === '-C') { dir = V.resolve(sh.cwd, args[++i]); continue; }
      if (a[0] === '-' && a[1] !== '-') {
        for (var j = 1; j < a.length; j++) {
          var ch = a[j];
          if (ch === 'c' || ch === 'x' || ch === 't') mode = ch; else if (ch === 'z') gz = true; else if (ch === 'v') verbose = true; else if (ch === 'p' || ch === 'k' || ch === 'h' || ch === 'a' || ch === 'j' || ch === 'J' || ch === 'P') { }
          else if (ch === 'f') { file = a.slice(j + 1) || args[++i]; break; } else if (ch === 'C') { dir = V.resolve(sh.cwd, a.slice(j + 1) || args[++i]); break; }
          else { io.err.w("tar: Unknown option '" + ch + "'\n"); return 2; }
        }
        continue;
      }
      paths.push(a);
    }
    if (!mode) { io.err.w("tar: You must specify one of the '-Acdtrux', '--delete' or '--test-label' options\n"); return 2; }
    if (!file) { io.err.w('tar: Refusing to read archive contents from terminal (missing -f option?)\ntar: Error is not recoverable: exiting now\n'); return 2; }
    if (mode === 'c') {
      if (!paths.length) { io.err.w('tar: Cowardly refusing to create an empty archive\n'); return 2; }
      var fullOut = V.resolve(sh.cwd, file), status = 0;
      var made = tarEntries(sh, paths, dir, excludes), entries = [];
      made.entries.forEach(function (e) { if (e.missing) { io.err.w('tar: ' + e.missing + ': Cannot stat: No such file or directory\n'); status = 2; } else { entries.push(e); if (verbose) io.out.w(e.p + '\n'); } });
      if (status && !entries.length) { io.err.w('tar: Exiting with failure status due to previous errors\n'); return 2; }
      var payload = (gz ? GZ : '') + TAR + JSON.stringify(entries);
      try { sh.vfs.write(fullOut, payload); var nn = sh.vfs.get(fullOut, true); nn.sparse = Math.max(V.byteLength(payload), made.total + 512); var m = sh.vfs.mountOf(fullOut); if (m && sh.vfs.used(m) > m.cap) { nn.sparse = 0; throw { code: 'ENOSPC' }; } }
      catch (e) {
        if (e.code === 'ENOSPC') {
          // a truncated archive stays on the disk, as with the real tar
          var node = sh.vfs.nodes[sh.vfs.real(fullOut, true)] || (sh.vfs.nodes[V.normalize(fullOut)] = { t: 'f', mode: 420, mtime: sh.vfs.now, owner: 'admin' });
          node.data = (gz ? GZ : '') + TAR + JSON.stringify(entries).slice(0, 40); delete node.sparse;
          io.err.w('tar: ' + file + ': Wrote only 4096 of 10240 bytes\ntar: Error is not recoverable: exiting now\n'); return 2;
        }
        io.err.w('tar: ' + file + ': Cannot open: ' + errText(e) + '\ntar: Error is not recoverable: exiting now\n'); return 2;
      }
      if (status) io.err.w('tar: Exiting with failure status due to previous errors\n');
      return status;
    }
    var list = readArchive(sh, file, gz, io, 'tar');
    if (list === null) return 2;
    if (mode === 't') { list.forEach(function (e) { io.out.w(e.p + '\n'); }); return 0; }
    var st = 0, wanted = paths.length ? paths.map(function (x) { return x.replace(/\/+$/, ''); }) : null;
    list.forEach(function (e) {
      var pth = e.p.replace(/\/$/, '');
      if (wanted && !wanted.some(function (w) { return pth === w || pth.indexOf(w + '/') === 0; })) return;
      if (excludes.some(function (re) { return re.test(pth) || re.test(V.basename(pth)); })) return;
      if (strip) { var parts = pth.split('/'); if (parts.length <= strip) return; pth = parts.slice(strip).join('/'); }
      var dest = V.resolve(dir, pth);
      try {
        if (e.t === 'd') { sh.vfs.mkdir(dest, true); sh.vfs.get(dest, true).mode = e.m; }
        else if (e.t === 'l') { if (sh.vfs.lexists(dest)) sh.vfs.remove(dest); sh.vfs.mkdir(V.dirname(dest), true); sh.vfs.symlink(e.l, dest); }
        else { sh.vfs.mkdir(V.dirname(dest), true); sh.vfs.write(dest, e.d || ''); var nd = sh.vfs.get(dest, true); nd.mode = e.m; nd.mtime = e.mt; if (e.sp) { nd.sparse = e.sp; nd.seed = e.seed; var mm = sh.vfs.mountOf(dest); if (mm && sh.vfs.used(mm) > mm.cap) { sh.vfs.remove(dest); throw { code: 'ENOSPC' }; } } }
        if (verbose) io.out.w(e.p + '\n');
      } catch (er) { io.err.w('tar: ' + e.p + ': Cannot write: ' + errText(er) + '\n'); st = 2; }
    });
    if (st) io.err.w('tar: Exiting with failure status due to previous errors\n');
    return st;
  });
  function gzipCmd(sh, name, args, io) {
    var dec = name === 'gunzip' || name === 'zcat' || args.indexOf('-d') !== -1, keep = args.indexOf('-k') !== -1, toOut = args.indexOf('-c') !== -1 || name === 'zcat', st = 0;
    var files = args.filter(function (a) { return a[0] !== '-'; });
    if (!files.length) { var d0 = io.in ? io.in.readAll() : ''; io.out.w(dec ? (d0.indexOf(GZ) === 0 ? d0.slice(GZ.length) : d0) : GZ + d0); return 0; }
    files.forEach(function (f) {
      var full = abs(sh, f), n = sh.vfs.get(full, true);
      if (!n) { io.err.w('gzip: ' + f + ': No such file or directory\n'); st = 1; return; }
      if (n.t !== 'f') { io.err.w('gzip: ' + f + ' is a directory -- ignored\n'); st = 1; return; }
      if (dec) {
        if (n.data.indexOf(GZ) !== 0) { io.err.w('gzip: ' + f + ': not in gzip format\n'); st = 1; return; }
        var plain = n.data.slice(GZ.length);
        if (toOut) { io.out.w(plain); return; }
        var to = full.replace(/\.(gz|tgz)$/, function (m) { return m === '.tgz' ? '.tar' : ''; });
        if (to === full) { io.err.w('gzip: ' + f + ': unknown suffix -- ignored\n'); st = 1; return; }
        try { sh.vfs.write(to, plain); var nn = sh.vfs.get(to, true); nn.mtime = n.mtime; nn.mode = n.mode; if (n.sparse) { nn.sparse = n.sparse; nn.seed = n.seed; } if (!keep) sh.vfs.remove(full); } catch (e) { io.err.w('gzip: ' + f + ': ' + errText(e) + '\n'); st = 1; }
      } else {
        if (/\.gz$/.test(f)) { io.err.w('gzip: ' + f + ' already has .gz suffix -- unchanged\n'); st = 1; return; }
        if (toOut) { io.out.w(GZ + n.data); return; }
        try { sh.vfs.write(full + '.gz', GZ + n.data); var gn = sh.vfs.get(full + '.gz', true); gn.mtime = n.mtime; gn.mode = n.mode; if (n.sparse) { gn.sparse = Math.ceil(n.sparse / 3); gn.seed = n.seed; } if (!keep) sh.vfs.remove(full); } catch (e2) { io.err.w('gzip: ' + f + '.gz: ' + errText(e2) + '\n'); st = 1; }
      }
    });
    return st;
  }
  def('gzip', function (sh, a, io) { return gzipCmd(sh, 'gzip', a, io); });
  def('gunzip', function (sh, a, io) { return gzipCmd(sh, 'gunzip', a, io); });
  def('zcat', function (sh, a, io) { return gzipCmd(sh, 'zcat', a, io); });
  def('zgrep', function (sh, args, io) {
    var pat = args.filter(function (a) { return a[0] !== '-'; }), flags = args.filter(function (a) { return a[0] === '-'; });
    var buf = new Buf(); gzipCmd(sh, 'zcat', pat.slice(1), { in: io.in, out: buf, err: io.err });
    return U.get('grep')(sh, flags.concat([pat[0]]), { in: new Stream(buf.s), out: io.out, err: io.err });
  });

  // ——— diff ———
  function lcsDiff(a, b) { // list of edit ops: { t: ' '|'-'|'+', s }
    var n = a.length, m = b.length, dp = [], i, j;
    for (i = 0; i <= n; i++) { dp.push(new Array(m + 1).fill(0)); }
    for (i = n - 1; i >= 0; i--) for (j = m - 1; j >= 0; j--) dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
    var ops = []; i = 0; j = 0;
    while (i < n && j < m) { if (a[i] === b[j]) { ops.push({ t: ' ', s: a[i] }); i++; j++; } else if (dp[i + 1][j] >= dp[i][j + 1]) { ops.push({ t: '-', s: a[i++] }); } else { ops.push({ t: '+', s: b[j++] }); } }
    while (i < n) ops.push({ t: '-', s: a[i++] }); while (j < m) ops.push({ t: '+', s: b[j++] });
    return ops;
  }
  def('diff', function (sh, args, io) {
    var p = U.parseOpts('diff', args, 'uqrNibBwcy', 'U', io, { 'unified': { key: 'u' }, 'brief': { key: 'q' }, 'recursive': { key: 'r' }, 'ignore-case': { key: 'i' } }); if (!p) return 2;
    if (p.rest.length !== 2) { io.err.w("diff: missing operand after '" + (p.rest[0] || 'diff') + "'\n"); return 2; }
    var A = p.rest[0], B = p.rest[1], fa = abs(sh, A), fb = abs(sh, B);
    if (p.o.r && sh.vfs.isDir(fa) && sh.vfs.isDir(fb)) {
      var names = {}; sh.vfs.walk(fa).forEach(function (x) { if (sh.vfs.isFile(x)) names[x.slice(fa.length + 1)] = 1; }); sh.vfs.walk(fb).forEach(function (x) { if (sh.vfs.isFile(x)) names[x.slice(fb.length + 1)] = 1; });
      var st = 0; Object.keys(names).sort().forEach(function (nm) {
        var ea = sh.vfs.isFile(fa + '/' + nm), eb = sh.vfs.isFile(fb + '/' + nm);
        if (!ea) { io.out.w('Only in ' + B + ': ' + nm + '\n'); st = 1; } else if (!eb) { io.out.w('Only in ' + A + ': ' + nm + '\n'); st = 1; }
        else if (sh.vfs.read(fa + '/' + nm) !== sh.vfs.read(fb + '/' + nm)) { st = 1; if (p.o.q) io.out.w('Files ' + A + '/' + nm + ' and ' + B + '/' + nm + ' differ\n'); else { io.out.w('diff -r ' + A + '/' + nm + ' ' + B + '/' + nm + '\n'); var sub = sh.fork(); sub.runArgs(['diff'].concat(p.o.u ? ['-u'] : []).concat([A + '/' + nm, B + '/' + nm]), io); } }
      });
      return st;
    }
    var da = A === '-' ? (io.in ? io.in.readAll() : '') : U.readFileArg(sh, 'diff', A, io), db = B === '-' ? (io.in ? io.in.readAll() : '') : U.readFileArg(sh, 'diff', B, io);
    if (da === null || db === null) return 2;
    var la = U.lines(da), lb = U.lines(db), cmpA = p.o.i ? la.map(function (x) { return x.toLowerCase(); }) : la, cmpB = p.o.i ? lb.map(function (x) { return x.toLowerCase(); }) : lb;
    if (cmpA.join('\n') === cmpB.join('\n')) return 0;
    if (p.o.q) { io.out.w('Files ' + A + ' and ' + B + ' differ\n'); return 1; }
    var ops = lcsDiff(cmpA, cmpB), ia = 0, ib = 0;
    ops.forEach(function (o) { if (o.t === ' ') { o.s = la[ia]; ia++; ib++; } else if (o.t === '-') { o.s = la[ia++]; } else { o.s = lb[ib++]; } });
    if (p.o.u || p.o.U !== undefined) {
      var ctx = p.o.U !== undefined ? parseInt(p.o.U, 10) : 3, tm = function (f) { var n = sh.vfs.get(abs(sh, f), true); return U.strftime('%F %T.000000000 +0000', n ? n.mtime : 0); };
      io.out.w('--- ' + A + '\t' + tm(A) + '\n+++ ' + B + '\t' + tm(B) + '\n');
      var idx = [], k;
      ops.forEach(function (o, n2) { if (o.t !== ' ') idx.push(n2); });
      var hunks = [], cur = null;
      idx.forEach(function (n2) { if (cur && n2 - cur.last <= 2 * ctx) cur.last = n2; else { if (cur) hunks.push(cur); cur = { first: n2, last: n2 }; } });
      if (cur) hunks.push(cur);
      hunks.forEach(function (h) {
        var from = Math.max(0, h.first - ctx), to = Math.min(ops.length - 1, h.last + ctx), sa = 0, sb = 0, ca = 0, cb = 0;
        for (k = 0; k < from; k++) { if (ops[k].t !== '+') sa++; if (ops[k].t !== '-') sb++; }
        for (k = from; k <= to; k++) { if (ops[k].t !== '+') ca++; if (ops[k].t !== '-') cb++; }
        io.out.w('@@ -' + (ca ? sa + 1 : sa) + (ca === 1 ? '' : ',' + ca) + ' +' + (cb ? sb + 1 : sb) + (cb === 1 ? '' : ',' + cb) + ' @@\n');
        for (k = from; k <= to; k++) io.out.w(ops[k].t + ops[k].s + '\n');
      });
      return 1;
    }
    // normal format
    var i2 = 0, la1 = 0, lb1 = 0;
    while (i2 < ops.length) {
      if (ops[i2].t === ' ') { la1++; lb1++; i2++; continue; }
      var dels = [], adds = [], sA = la1 + 1, sB = lb1 + 1;
      while (i2 < ops.length && ops[i2].t !== ' ') { if (ops[i2].t === '-') { dels.push(ops[i2].s); la1++; } else { adds.push(ops[i2].s); lb1++; } i2++; }
      var rng = function (s, n3) { return n3 === 1 ? String(s) : s + ',' + (s + n3 - 1); };
      var head = dels.length && adds.length ? rng(sA, dels.length) + 'c' + rng(sB, adds.length) : (dels.length ? rng(sA, dels.length) + 'd' + (sB - 1) : (sA - 1) + 'a' + rng(sB, adds.length));
      io.out.w(head + '\n'); dels.forEach(function (d) { io.out.w('< ' + d + '\n'); }); if (dels.length && adds.length) io.out.w('---\n'); adds.forEach(function (d) { io.out.w('> ' + d + '\n'); });
    }
    return 1;
  });

  // ——— ssh, scp ———
  function resolveHost(sh, target) {
    var h = target.replace(/^[^@]*@/, '').replace(/\.(lan|local|radio-comete\.lan)$/, '');
    return { name: h, machine: sh.m.fleet ? sh.m.fleet.get(h) : null };
  }
  function reach(sh, host, io, cmdName) {
    if (!host.machine) { io.err.w(cmdName + ': Could not resolve hostname ' + host.name + ': Name or service not known\n'); return null; }
    var d = host.machine.down;
    if (d === 'timeout') { io.err.w(cmdName + ': connect to host ' + host.name + ' port 22: Connection timed out\n'); return null; }
    if (d === 'refused') { io.err.w(cmdName + ': connect to host ' + host.name + ' port 22: Connection refused\n'); return null; }
    if (d === 'noroute') { io.err.w(cmdName + ': connect to host ' + host.name + ' port 22: No route to host\n'); return null; }
    if (d === 'denied') { io.err.w('admin@' + host.name + ': Permission denied (publickey).\n'); return null; }
    return host.machine;
  }
  def('ssh', function (sh, args, io) {
    var i = 0, target = null;
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '-o' || a === '-i' || a === '-p' || a === '-l' || a === '-F' || a === '-J') { i++; continue; }
      if (/^-[A-Za-z]+$/.test(a)) continue;
      target = a; i++; break;
    }
    if (!target) { io.err.w('usage: ssh [-options] destination [command]\n'); return 255; }
    var m = reach(sh, resolveHost(sh, target), io, 'ssh');
    if (!m) return 255;
    var cmd = args.slice(i).join(' ');
    if (!cmd) return 0;
    var r = m.exec(cmd, { in: io.in, out: io.out, err: io.err });
    return r;
  });
  def('scp', function (sh, args, io) {
    var p = U.parseOpts('scp', args, 'rqpCB', 'oiPF', io); if (!p) return 1;
    if (p.rest.length < 2) { io.err.w('usage: scp [-options] source ... target\n'); return 1; }
    var parts = p.rest.map(function (x) { var m = /^(?:[^@:\/]+@)?([A-Za-z0-9._-]+):(.*)$/.exec(x); return m ? { host: m[1], path: m[2] || '.' } : { host: null, path: x }; });
    var dst = parts[parts.length - 1], srcs = parts.slice(0, -1), st = 0;
    function mach(part) { if (!part.host) return sh.m; var mm = reach(sh, resolveHost(sh, part.host), io, 'ssh'); return mm; }
    var dm = mach(dst); if (!dm) return 1;
    srcs.forEach(function (s) {
      var sm = mach(s); if (!sm) { st = 1; return; }
      var sfull = V.resolve(s.host ? sm.home : sh.cwd, s.path), dfull = V.resolve(dst.host ? dm.home : sh.cwd, dst.path);
      if (!sm.vfs.lexists(sfull)) { io.err.w('scp: ' + s.path + ': No such file or directory\n'); st = 1; return; }
      if (dm.vfs.isDir(dfull)) dfull = V.normalize(dfull + '/' + V.basename(sfull));
      if (sm.vfs.isDir(sfull) && !p.o.r) { io.err.w('scp: ' + s.path + ': not a regular file\n'); st = 1; return; }
      try {
        sm.vfs.walk(sfull).forEach(function (f) {
          var n = sm.vfs.get(f, false), to = dfull + f.slice(sfull.length);
          if (n.t === 'd') dm.vfs.mkdir(to, true); else { dm.vfs.mkdir(V.dirname(to), true); dm.vfs.write(to, n.data); var dn = dm.vfs.get(to, true); dn.mode = n.mode; if (n.sparse) { dn.sparse = n.sparse; dn.seed = n.seed; } }
        });
      } catch (e) { io.err.w('scp: ' + dst.path + ': ' + errText(e) + '\n'); st = 1; }
    });
    return st;
  });

  // ——— crontab ———
  def('crontab', function (sh, args, io) {
    var user = 'admin', i = 0, op = null, file = null;
    for (; i < args.length; i++) { var a = args[i]; if (a === '-u') user = args[++i]; else if (a === '-l') op = 'l'; else if (a === '-r') op = 'r'; else if (a === '-e') op = 'e'; else file = a; }
    var path = '/var/spool/cron/crontabs/' + user;
    if (op === 'l') { if (!sh.vfs.isFile(path)) { io.err.w('no crontab for ' + user + '\n'); return 1; } io.out.w(sh.vfs.read(path)); return 0; }
    if (op === 'r') { if (sh.vfs.isFile(path)) sh.vfs.remove(path); return 0; }
    if (op === 'e') { io.err.w('crontab: no editor available in this terminal\n'); return 1; }
    var src = file && file !== '-' ? U.readFileArg(sh, 'crontab', file, io) : (io.in ? io.in.readAll() : '');
    if (src === null) return 1;
    var bad = U.lines(src).findIndex(function (l) { return l.trim() !== '' && l.trim()[0] !== '#' && !/^[A-Za-z_]+=/.test(l.trim()) && l.trim().split(/\s+/).length < 6 && !/^@/.test(l.trim()); });
    if (bad !== -1) { io.err.w('"-":' + (bad + 1) + ': bad minute\nerrors in crontab file, can\'t install.\n'); return 1; }
    sh.vfs.mkdir('/var/spool/cron/crontabs', true); sh.vfs.write(path, src); return 0;
  });

  // ——— systemd ———
  function unitPath(sh, name) { var cands = ['/etc/systemd/system/' + name, '/lib/systemd/system/' + name]; for (var i = 0; i < cands.length; i++) if (sh.vfs.isFile(cands[i])) return cands[i]; return null; }
  function parseUnit(text) {
    var sec = null, out = {};
    U.lines(text).forEach(function (l) { var t = l.trim(); if (!t || t[0] === '#' || t[0] === ';') return; var m = /^\[(.+)\]$/.exec(t); if (m) { sec = m[1]; out[sec] = out[sec] || {}; return; } var kv = /^([A-Za-z]+)=(.*)$/.exec(t); if (kv && sec) (out[sec][kv[1]] = out[sec][kv[1]] || []).push(kv[2]); });
    return out;
  }
  function calendarNext(spec, now) {
    var s = spec.trim(), dow = null, m;
    var named = { minutely: '*-*-* *:*:00', hourly: '*-*-* *:00:00', daily: '*-*-* 00:00:00', weekly: 'Mon *-*-* 00:00:00', monthly: '*-*-01 00:00:00' };
    if (named[s]) s = named[s];
    if ((m = /^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+(.*)$/.exec(s))) { dow = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(m[1]); s = m[2]; }
    var dm = /^(\*|\d{4})-(\*|\d{2})-(\*|\d{2})\s+(.*)$/.exec(s); var date = ['*', '*', '*'];
    if (dm) { date = [dm[1], dm[2], dm[3]]; s = dm[4]; }
    var tm = /^(\*|\d{1,2}):(\*|\d{1,2})(?::(\d{1,2}))?$/.exec(s);
    if (!tm) return null;
    var H = tm[1], M = tm[2], Sx = tm[3] === undefined ? 0 : +tm[3];
    for (var k = 0; k < 60 * 24 * 40; k++) {
      var t = Math.floor(now / 60) * 60 + (k + 1) * 60 - (Sx ? 0 : 0), d = new Date(t * 1000);
      if (H !== '*' && d.getUTCHours() !== +H) continue; if (M !== '*' && d.getUTCMinutes() !== +M) continue;
      if (date[2] !== '*' && d.getUTCDate() !== +date[2]) continue; if (date[1] !== '*' && d.getUTCMonth() + 1 !== +date[1]) continue; if (dow !== null && d.getUTCDay() !== dow) continue;
      return t + Sx;
    }
    return null;
  }
  def('systemctl', function (sh, args, io) {
    var m = sh.m; m.units = m.units || {};
    var a = args.filter(function (x) { return x[0] !== '-'; }), flags = args.filter(function (x) { return x[0] === '-'; }), verb = a[0], names = a.slice(1), now = flags.indexOf('--now') !== -1, st = 0;
    function full(n) { return /\.(service|timer|socket|target|mount|path)$/.test(n) ? n : n + '.service'; }
    function state(n) { return m.units[n] || (m.units[n] = { enabled: false, active: false }); }
    if (!verb) { io.err.w('systemctl: missing command\n'); return 1; }
    if (verb === 'daemon-reload') { m.daemonStale = false; return 0; }
    if (verb === 'list-timers') {
      io.out.w('NEXT                         LEFT          LAST PASSED UNIT                         ACTIVATES\n');
      var rows = 0;
      Object.keys(m.units).forEach(function (u) {
        if (!/\.timer$/.test(u) || !m.units[u].active) return;
        var p = unitPath(sh, u); if (!p) return;
        var cal = (parseUnit(sh.vfs.read(p)).Timer || {}).OnCalendar; if (!cal) return;
        var nx = calendarNext(cal[0], sh.vfs.now); if (!nx) return;
        var left = nx - sh.vfs.now, lt = left >= 3600 ? Math.floor(left / 3600) + 'h' : Math.floor(left / 60) + 'min';
        io.out.w(U.strftime('%a %F %T UTC', nx) + ' ' + lt + ' left n/a n/a ' + u + ' ' + u.replace(/\.timer$/, '.service') + '\n'); rows++;
      });
      io.out.w('\n' + rows + ' timers listed.\n'); return 0;
    }
    if (verb === 'list-unit-files' || verb === 'list-units') { sh.vfs.list('/etc/systemd/system').forEach(function (f) { if (/\.(service|timer)$/.test(f)) io.out.w(f + (verb === 'list-unit-files' ? ' ' + (state(f).enabled ? 'enabled' : 'disabled') : '') + '\n'); }); return 0; }
    if (!names.length) { io.err.w('systemctl: too few arguments\n'); return 1; }
    names.forEach(function (raw) {
      var n = full(raw), p = unitPath(sh, n), s;
      if (!p && ['start', 'stop', 'restart', 'enable', 'disable', 'status', 'cat', 'is-enabled', 'is-active', 'reload'].indexOf(verb) !== -1) {
        if (verb === 'is-enabled' || verb === 'is-active') { io.out.w((verb === 'is-active' ? 'inactive' : 'not-found') + '\n'); st = verb === 'is-active' ? 3 : 1; return; }
        io.err.w((verb === 'enable' || verb === 'disable' ? 'Failed to ' + verb + ' unit: Unit file ' + n + ' does not exist.' : (verb === 'status' ? 'Unit ' + n + ' could not be found.' : 'Failed to ' + verb + ' ' + n + ': Unit ' + n + ' not found.')) + '\n'); st = verb === 'status' ? 4 : 5; return;
      }
      s = p ? state(n) : null;
      switch (verb) {
        case 'cat': io.out.w('# ' + p + '\n' + sh.vfs.read(p)); break;
        case 'is-enabled': io.out.w(s.enabled ? 'enabled\n' : 'disabled\n'); if (!s.enabled) st = 1; break;
        case 'is-active': io.out.w(s.active ? 'active\n' : 'inactive\n'); if (!s.active) st = 3; break;
        case 'enable': {
          var u = parseUnit(sh.vfs.read(p));
          if (!u.Install || !(u.Install.WantedBy || u.Install.RequiredBy)) io.err.w('The unit files have no installation config (WantedBy=, RequiredBy=, Also=,\nAlias= settings in the [Install] section, and DefaultInstance= for template\nunits). This means they are not meant to be enabled or started manually.\n');
          else { s.enabled = true; io.err.w('Created symlink /etc/systemd/system/' + u.Install.WantedBy[0] + '.wants/' + n + ' → ' + p + '.\n'); }
          if (now) {
            if (/\.timer$/.test(n) && (!u.Timer || !u.Timer.OnCalendar || calendarNext(u.Timer.OnCalendar[0], sh.vfs.now) === null)) { io.err.w('Failed to enable unit: Unit ' + n + ' has a bad unit file setting (OnCalendar).\n'); st = 1; break; }
            s.active = true;
          }
          break;
        }
        case 'disable': s.enabled = false; if (now) s.active = false; io.err.w('Removed /etc/systemd/system/timers.target.wants/' + n + '.\n'); break;
        case 'stop': s.active = false; break;
        case 'start': case 'restart': case 'reload': {
          if (m.daemonStale) io.err.w('Warning: The unit file, source configuration file or drop-ins of ' + n + ' changed on disk. Run \'systemctl daemon-reload\' to reload units.\n');
          var unit = parseUnit(sh.vfs.read(p));
          if (/\.timer$/.test(n)) { if (!unit.Timer || !unit.Timer.OnCalendar || calendarNext(unit.Timer.OnCalendar[0], sh.vfs.now) === null) { io.err.w('Failed to start ' + n + ': Unit ' + n + ' has a bad unit file setting.\n'); st = 1; break; } s.active = true; break; }
          var exec = unit.Service && unit.Service.ExecStart;
          if (!exec) { io.err.w('Failed to start ' + n + ': Unit ' + n + ' has a bad unit file setting.\n'); st = 1; break; }
          if (exec[0].replace(/^[-@+!]+/, '').charAt(0) !== '/') { io.err.w('Failed to start ' + n + ': Unit ' + n + ' has a bad unit file setting.\n'); st = 1; break; }
          if (unit.Service.Type && unit.Service.Type[0] === 'forking' && !unit.Service.PIDFile) { io.err.w('Job for ' + n + ' failed because the service did not take the steps required by its unit configuration.\nSee "systemctl status ' + n + '" and "journalctl -xeu ' + n + '" for details.\n'); st = 1; break; }
          var cmd = exec[0].replace(/^[-@+!]+/, ''), buf = new Buf(), r = m.exec(cmd, { in: null, out: buf, err: buf });
          try { sh.vfs.write('/var/log/journal.txt', U.strftime('%b %e %H:%M:%S', sh.vfs.now) + ' ' + m.name + ' systemd[1]: Starting ' + n + '...\n' + U.lines(buf.s).map(function (l) { return U.strftime('%b %e %H:%M:%S', sh.vfs.now) + ' ' + m.name + ' ' + n.replace(/\.service$/, '') + '[4242]: ' + l + '\n'; }).join('') + U.strftime('%b %e %H:%M:%S', sh.vfs.now) + ' ' + m.name + ' systemd[1]: ' + (r === 0 ? 'Finished ' + n + '.' : n + ': Main process exited, code=exited, status=' + r + '/FAILURE') + '\n', true); } catch (e) { /* the journal may be full */ }
          if (r !== 0) { io.err.w('Job for ' + n + ' failed because the control process exited with error code.\nSee "systemctl status ' + n + '" and "journalctl -xeu ' + n + '" for details.\n'); st = 1; }
          else s.active = unit.Service.Type && unit.Service.Type[0] === 'oneshot' ? false : true;
          break;
        }
        case 'status': {
          io.out.w('● ' + n + '\n     Loaded: loaded (' + p + '; ' + (s.enabled ? 'enabled' : 'disabled') + '; preset: enabled)\n     Active: ' + (s.active ? 'active (waiting)' : 'inactive (dead)') + '\n');
          if (!s.active) st = 3; break;
        }
        default: io.err.w('Unknown command verb ' + verb + '.\n'); st = 1;
      }
    });
    return st;
  });
  def('journalctl', function (sh, args, io) {
    var unit = null, n = Infinity, tag = null;
    for (var i = 0; i < args.length; i++) { if (args[i] === '-u') unit = args[++i]; else if (args[i] === '-n') n = parseInt(args[++i], 10); else if (args[i] === '-t') tag = args[++i]; }
    var d = sh.vfs.isFile('/var/log/journal.txt') ? sh.vfs.read('/var/log/journal.txt') : '', ls = U.lines(d);
    if (unit) { var base = unit.replace(/\.service$/, ''); ls = ls.filter(function (l) { return l.indexOf(' ' + base + '[') !== -1 || l.indexOf(base + '.service') !== -1; }); }
    if (tag) ls = ls.filter(function (l) { return l.indexOf(' ' + tag + '[') !== -1; });
    if (!ls.length) { io.out.w('-- No entries --\n'); return 0; }
    io.out.w(ls.slice(-n).join('\n') + '\n'); return 0;
  });
  def('flock', function (sh, args, io) {
    var nb = false, i = 0, cmdStr = null, unlock = false;
    for (; i < args.length; i++) { var a = args[i]; if (a === '-n' || a === '--nonblock') nb = true; else if (a === '-w') i++; else if (a === '-x' || a === '-s' || a === '-e' || a === '--exclusive') { } else if (a === '-u') unlock = true; else if (a === '-c') { cmdStr = args[++i]; i++; break; } else if (a === '-E') i++; else break; }
    var target = args[i], rest = args.slice(i + 1), m = sh.m;
    if (target === undefined) { io.err.w('flock: requires file descriptor, file or directory\n'); return 64; }
    var path, fdForm = /^[0-9]+$/.test(target);
    if (fdForm) { var fd = sh.fds[target]; if (!fd || !fd.path) { io.err.w('flock: ' + target + ': Bad file descriptor\n'); return 1; } path = fd.path; } else path = abs(sh, target);
    if (unlock) { m.locks.delete(path); return 0; }
    var owner = m.locks.get(path);
    if (owner !== undefined && owner !== sh.rootId) { if (!nb) io.err.w('flock: the lock is held by another process and would be waited for forever\n'); return 1; }
    if (!fdForm && !sh.vfs.exists(path)) { try { sh.vfs.write(path, ''); } catch (e) { io.err.w('flock: ' + target + ': ' + errText(e) + '\n'); return 1; } }
    m.locks.set(path, sh.rootId);
    if (fdForm) return 0;
    var st;
    try { st = cmdStr !== null ? sh.fork().runString(cmdStr, io) : (rest.length ? sh.dispatch(rest, io) : 0); }
    finally { m.locks.delete(path); }
    return st;
  });
})(typeof globalThis !== 'undefined' ? globalThis : window);
