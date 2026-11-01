/* Radio-Comète — Bash builtins: echo, printf, cd, read, getopts, set, trap, test, declare… */
(function (root) {
  'use strict';
  var S = root.RCShell, V = root.RCVfs, P = root.RCParse, R = root.RCRegex;
  var Shell = S.Shell, B = Shell.builtins, Signal = S.Signal, Fail = S.Fail, Stream = S.Stream;

  function def(names, fn) { names.split(' ').forEach(function (n) { B[n] = fn; }); }
  function num(sh, s, what) { if (!/^-?[0-9]+$/.test(s)) throw new Fail(what + ': ' + s + ': numeric argument required', 2); return parseInt(s, 10); }

  // ——— printf ———
  function escapes(s, stopAtC) {
    var out = '', i = 0;
    while (i < s.length) {
      var c = s[i];
      if (c !== '\\') { out += c; i++; continue; }
      var d = s[i + 1];
      if (d === undefined) { out += '\\'; break; }
      i += 2;
      switch (d) {
        case 'n': out += '\n'; break; case 't': out += '\t'; break; case 'r': out += '\r'; break; case 'a': out += '\x07'; break;
        case 'b': out += '\b'; break; case 'f': out += '\f'; break; case 'v': out += '\v'; break; case 'e': case 'E': out += '\x1b'; break;
        case '\\': out += '\\'; break; case '"': out += '"'; break; case "'": out += "'"; break;
        case 'c': if (stopAtC) return { s: out, stop: true }; break;
        case 'x': { var m = /^[0-9a-fA-F]{1,2}/.exec(s.slice(i)); if (m) { out += String.fromCharCode(parseInt(m[0], 16)); i += m[0].length; } else out += '\\x'; break; }
        case 'u': case 'U': { var m2 = /^[0-9a-fA-F]{1,8}/.exec(s.slice(i)); if (m2) { out += String.fromCodePoint(parseInt(m2[0], 16)); i += m2[0].length; } else out += '\\' + d; break; }
        case '0': case '1': case '2': case '3': case '4': case '5': case '6': case '7': { var m3 = /^[0-7]{0,2}/.exec(s.slice(i)); out += String.fromCharCode(parseInt(d + m3[0], 8)); i += m3[0].length; break; }
        default: out += '\\' + d;
      }
    }
    return stopAtC ? { s: out, stop: false } : out;
  }
  function pad(s, width, left, zero) {
    if (width === undefined || s.length >= width) return s;
    var fill = Array(width - s.length + 1).join(zero && !left ? '0' : ' ');
    if (left) return s + fill;
    if (zero && /^[-+]/.test(s)) return s[0] + fill + s.slice(1);
    return fill + s;
  }
  function printfFormat(sh, fmt, args, io) {
    var out = '', ai = 0, used;
    var re = /%([-+ #0]*)(\*|\d+)?(?:\.(\*|\d+))?([a-zA-Z%])|%\(([^)]*)\)T/g;
    do {
      used = 0;
      var last = 0, m, stopped = false;
      re.lastIndex = 0;
      while ((m = re.exec(fmt))) {
        out += escapes(fmt.slice(last, m.index)); last = re.lastIndex;
        if (m[5] !== undefined) { out += sh.vfs.now; used++; ai++; continue; }
        var flags = m[1] || '', w = m[2], pr = m[3], conv = m[4];
        if (conv === '%') { out += '%'; continue; }
        if (w === '*') w = String(args[ai++] || 0);
        if (pr === '*') pr = String(args[ai++] || 0);
        var width = w === undefined ? undefined : parseInt(w, 10), prec = pr === undefined ? undefined : parseInt(pr, 10);
        var left = flags.indexOf('-') !== -1, zero = flags.indexOf('0') !== -1, plus = flags.indexOf('+') !== -1, space = flags.indexOf(' ') !== -1;
        var arg = args[ai], has = ai < args.length; ai++; if (has) used++;
        var s;
        switch (conv) {
          case 's': s = has ? String(arg) : ''; if (prec !== undefined) s = s.slice(0, prec); out += pad(s, width, left, false); break;
          case 'b': { var e = escapes(has ? String(arg) : '', true); s = e.s; out += pad(s, width, left, false); if (e.stop) stopped = true; break; }
          case 'q': s = has ? String(arg) : ''; s = /^[A-Za-z0-9_@%+=:,.\/-]+$/.test(s) ? s : "'" + s.replace(/'/g, "'\\''") + "'"; out += pad(s, width, left, false); break;
          case 'c': s = has ? String(arg).charAt(0) : ''; out += pad(s, width, left, false); break;
          case 'd': case 'i': case 'u': {
            var n = has ? toInt(arg, sh, io) : 0; s = String(Math.abs(n)); if (prec !== undefined) while (s.length < prec) s = '0' + s;
            s = (n < 0 ? '-' : (plus ? '+' : (space ? ' ' : ''))) + s; out += pad(s, width, left, zero && prec === undefined); break;
          }
          case 'x': case 'X': case 'o': {
            var n2 = has ? toInt(arg, sh, io) : 0; if (n2 < 0) n2 = n2 >>> 0;
            s = n2.toString(conv === 'o' ? 8 : 16); if (conv === 'X') s = s.toUpperCase();
            if (flags.indexOf('#') !== -1 && n2) s = (conv === 'o' ? '0' : (conv === 'X' ? '0X' : '0x')) + s; out += pad(s, width, left, zero); break;
          }
          case 'f': case 'F': case 'e': case 'E': case 'g': case 'G': {
            var f = has ? parseFloat(arg) : 0; if (isNaN(f)) f = 0;
            var p = prec === undefined ? 6 : prec;
            if (conv === 'f' || conv === 'F') s = Math.abs(f).toFixed(p);
            else if (conv === 'e' || conv === 'E') { s = Math.abs(f).toExponential(p).replace(/e([+-])(\d)$/, 'e$10$2'); if (conv === 'E') s = s.toUpperCase(); }
            else { s = String(parseFloat(Math.abs(f).toPrecision(p || 1))); }
            s = (f < 0 ? '-' : (plus ? '+' : (space ? ' ' : ''))) + s; out += pad(s, width, left, zero); break;
          }
          default: out += m[0];
        }
        if (stopped) break;
      }
      out += escapes(fmt.slice(last));
      if (stopped) break;
    } while (ai < args.length && used > 0);
    return out;
  }
  function toInt(a, sh, io) {
    var s = String(a);
    if (/^[-+]?[0-9]+$/.test(s)) return parseInt(s, 10);
    if (/^[-+]?0[xX][0-9a-fA-F]+$/.test(s)) return parseInt(s, 16);
    if (/^['"].$/.test(s)) return s.charCodeAt(1);
    sh.complain(io, 'printf: ' + s + ': invalid number'); sh.status = 1; return 0;
  }

  // ——— The simple ones ———
  def(': true', function () { return 0; });
  def('false', function () { return 1; });
  def('echo', function (args, io) {
    var nl = true, esc = false, i = 0;
    for (; i < args.length; i++) {
      var a = args[i];
      if (/^-[neE]+$/.test(a)) { if (a.indexOf('n') !== -1) nl = false; if (a.indexOf('e') !== -1) esc = true; if (a.indexOf('E') !== -1) esc = false; } else break;
    }
    var s = args.slice(i).join(' ');
    if (esc) { var e = escapes(s, true); s = e.s; if (e.stop) nl = false; }
    io.out.w(s + (nl ? '\n' : ''));
    return 0;
  });
  def('printf', function (args, io) {
    var varName = null;
    if (args[0] === '-v') { varName = args[1]; args = args.slice(2); }
    if (!args.length) { this.complain(io, 'printf: usage: printf [-v var] format [arguments]'); return 2; }
    var s = printfFormat(this, args[0], args.slice(1), io);
    if (varName) this.set(varName, s); else io.out.w(s);
    return this.status === 1 && /invalid number/.test('') ? 1 : 0;
  });
  def('pwd', function (args, io) { io.out.w(this.cwd + '\n'); return 0; });
  def('cd', function (args, io) {
    var t = args.filter(function (a) { return a !== '-L' && a !== '-P' && a !== '--'; })[0];
    if (t === undefined) t = this.get('HOME');
    if (t === '-') { t = this.get('OLDPWD'); io.out.w(t + '\n'); }
    if (args.filter(function (a) { return a[0] !== '-' || a === '-'; }).length > 1) { this.complain(io, 'cd: too many arguments'); return 1; }
    var full = V.resolve(this.cwd, t);
    var node = this.vfs.get(full, true);
    if (!node) { this.complain(io, 'cd: ' + t + ': No such file or directory'); return 1; }
    if (node.t !== 'd') { this.complain(io, 'cd: ' + t + ': Not a directory'); return 1; }
    this.set('OLDPWD', this.cwd, true); this.cwd = this.vfs.real(full, true); this.set('PWD', this.cwd, true);
    return 0;
  });
  def('exit', function (args, io) {
    var n = args.length ? num(this, args[0], 'exit') & 255 : this.status;
    throw new Signal('exit', n);
  });
  def('return', function (args, io) {
    if (!this.frames.length && !this.sourced) { this.complain(io, "return: can only `return' from a function or sourced script"); return 1; }
    throw new Signal('return', args.length ? num(this, args[0], 'return') & 255 : this.status);
  });
  def('break continue', function () { throw new Error('replaced below'); });
  B['break'] = function (args) { throw new Signal('break', args.length ? parseInt(args[0], 10) || 1 : 1); };
  B['continue'] = function (args) { throw new Signal('continue', args.length ? parseInt(args[0], 10) || 1 : 1); };
  def('shift', function (args, io) {
    var n = args.length ? num(this, args[0], 'shift') : 1;
    if (n > this.pos.length) { this.complain(io, 'shift: shift count out of range'); return 1; }
    this.pos = this.pos.slice(n); return 0;
  });
  def('let', function (args, io) {
    var v = 0, self = this;
    args.forEach(function (a) { self.curIo = io; v = self.arith(a); });
    return v !== 0 ? 0 : 1;
  });
  def('wait jobs disown hash umask ulimit alias unalias bind enable times fg bg stty', function () { return 0; });
  def('kill', function (args, io) { return 0; });
  def('builtin', function (args, io) { var b = B[args[0]]; if (!b) { this.complain(io, 'builtin: ' + args[0] + ': not a shell builtin'); return 1; } return b.call(this, args.slice(1), io); });
  def('eval', function (args, io) { return args.length ? this.runString(args.join(' '), io) : 0; });
  def('time', function (args, io) { return this.dispatch(args, io); });

  def('export', function (args, io) {
    var self = this, st = 0, unexport = false;
    if (args[0] === '-p' || !args.length) { Object.keys(this.vars).forEach(function (k) { var v = self.vars[k]; if (v.exp && v.kind === 's') io.out.w('declare -x ' + k + '="' + v.v + '"\n'); }); return 0; }
    args.forEach(function (a) {
      if (a === '-n') { unexport = true; return; }
      if (a[0] === '-') return;
      var m = /^([A-Za-z_][A-Za-z0-9_]*)(?:=([\s\S]*))?$/.exec(a);
      if (!m) { self.complain(io, "export: `" + a + "': not a valid identifier"); st = 1; return; }
      if (m[2] !== undefined) self.set(m[1], m[2], true);
      else if (!self.vars[m[1]]) self.vars[m[1]] = { kind: 's', v: '', exp: !unexport, unset: true };
      if (self.vars[m[1]]) self.vars[m[1]].exp = !unexport;
    });
    return st;
  });
  function declare(sh, args, io, isLocal) {
    var flags = '', names = [], st = 0, listFns = false, print = false;
    args.forEach(function (a) {
      if (/^[-+][a-zA-Z]+$/.test(a)) { flags += a; if (/p/.test(a)) print = true; return; }
      names.push(a);
    });
    var neg = function (f) { return flags.indexOf('-') !== -1 && new RegExp('-[a-zA-Z]*' + f).test(flags); };
    if (print) {
      names.forEach(function (n) {
        var nm = n.split('=')[0], v = sh.vars[nm];
        if (!v || v.unset) { sh.complain(io, 'declare: ' + nm + ': not found'); st = 1; return; }
        var f = (v.kind === 'a' ? 'a' : (v.kind === 'A' ? 'A' : '-')) + (v.exp ? 'x' : '') + (v.ro ? 'r' : '') + (v.int ? 'i' : '');
        var val = v.kind === 's' ? '"' + v.v + '"' : (v.kind === 'a' ? '(' + v.v.map(function (x, i) { return x === undefined ? '' : '[' + i + ']="' + x + '"'; }).filter(Boolean).join(' ') + ')' : '(' + Object.keys(v.v).map(function (k) { return '[' + k + ']="' + v.v[k] + '"'; }).join(' ') + ')');
        io.out.w('declare -' + (f.length > 1 ? f.replace(/^-/, '') : '-') + ' ' + nm + '=' + val + '\n');
      });
      return st;
    }
    if (!names.length) return 0;
    names.forEach(function (n) {
      var m = /^([A-Za-z_][A-Za-z0-9_]*)(?:\[([^\]]*)\])?(\+?)(?:=([\s\S]*))?$/.exec(n);
      if (!m) { sh.complain(io, 'declare: `' + n + "': not a valid identifier"); st = 1; return; }
      var name = m[1];
      if (isLocal) {
        var fr = sh.frames[sh.frames.length - 1];
        if (!fr) { sh.complain(io, 'local: can only be used in a function'); st = 1; return; }
        if (!(name in fr.locals)) fr.locals[name] = sh.vars[name];
        sh.vars[name] = { kind: 's', v: '', exp: false, unset: true };
      } else if (!sh.vars[name] && true) sh.vars[name] = { kind: 's', v: '', exp: false, unset: true };
      var v = sh.vars[name];
      if (neg('A')) { v.kind = 'A'; v.v = Object.create(null); delete v.unset; }
      else if (neg('a')) { v.kind = 'a'; v.v = []; delete v.unset; }
      if (neg('i')) v.int = true; if (neg('x')) v.exp = true; if (neg('u')) v.upper = true; if (neg('l')) v.lower = true;
      if (m[4] !== undefined) {
        var raw = m[4];
        try {
          if (raw[0] === '(' && raw[raw.length - 1] === ')') sh.applyAssign({ name: name, idx: null, append: m[3] === '+' }, sh.assignValue({ value: raw, idx: null }, io));
          else if (m[2] !== undefined) sh.setIndex(name, m[2], raw, m[3] === '+');
          else sh.applyAssign({ name: name, idx: null, append: m[3] === '+' }, { str: raw });
        } catch (e) { if (e instanceof Fail) { sh.complain(io, e.msg); st = 1; } else throw e; }
      }
      if (neg('r')) sh.vars[name].ro = true;
    });
    return st;
  }
  def('declare typeset', function (args, io) { return declare(this, args, io, false); });
  def('local', function (args, io) { return declare(this, args, io, true); });
  def('readonly', function (args, io) { return declare(this, ['-r'].concat(args), io, false); });
  def('unset', function (args, io) {
    var self = this, st = 0, fn = false;
    args.forEach(function (a) {
      if (a === '-f') { fn = true; return; } if (a === '-v') { fn = false; return; }
      if (fn) { delete self.funcs[a]; return; }
      var m = /^([A-Za-z_][A-Za-z0-9_]*)\[(.*)\]$/.exec(a);
      if (m) {
        var v = self.vars[m[1]];
        if (v && v.kind === 'a') { var k = parseInt(self.arith(m[2]), 10); delete v.v[k]; }
        else if (v && v.kind === 'A') delete v.v[self.expandStr(m[2], io)];
        return;
      }
      if (self.vars[a] && self.vars[a].ro) { self.complain(io, 'unset: ' + a + ': cannot unset: readonly variable'); st = 1; return; }
      var fr = self.frames[self.frames.length - 1];
      if (fr && !(a in fr.locals) === false) { self.vars[a] = { kind: 's', v: '', exp: false, unset: true }; return; }
      delete self.vars[a];
    });
    return st;
  });
  def('set', function (args, io) {
    var self = this, i = 0;
    if (!args.length) { Object.keys(this.vars).sort().forEach(function (k) { var v = self.vars[k]; if (v.kind === 's' && !v.unset) io.out.w(k + '=' + v.v + '\n'); }); return 0; }
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '--') { this.pos = args.slice(i + 1); return 0; }
      if (a === '-o' || a === '+o') {
        var on = a === '-o', nm = args[++i];
        if (nm === 'pipefail') this.opts.pipefail = on; else if (nm === 'errexit') this.opts.e = on; else if (nm === 'nounset') this.opts.u = on; else if (nm === 'xtrace') this.opts.x = on; else if (nm === 'noglob') this.opts.f = on;
        else if (nm === undefined) { io.out.w('pipefail\t' + (this.opts.pipefail ? 'on' : 'off') + '\nerrexit\t' + (this.opts.e ? 'on' : 'off') + '\nnounset\t' + (this.opts.u ? 'on' : 'off') + '\n'); }
        continue;
      }
      if (/^[-+][a-zA-Z]+$/.test(a)) {
        var onf = a[0] === '-', letters = a.slice(1).split('');
        for (var li = 0; li < letters.length; li++) {
          var f = letters[li];
          if (f === 'e') self.opts.e = onf; else if (f === 'u') self.opts.u = onf; else if (f === 'x') self.opts.x = onf; else if (f === 'f') self.opts.f = onf;
          else if (f === 'o') { var nm2 = args[++i]; if (nm2 === 'pipefail') self.opts.pipefail = onf; else if (nm2 === 'errexit') self.opts.e = onf; else if (nm2 === 'nounset') self.opts.u = onf; else if (nm2 === 'xtrace') self.opts.x = onf; }
        }
        continue;
      }
      this.pos = args.slice(i); return 0;
    }
    return 0;
  });
  def('shopt', function (args, io) {
    var on = null, names = [];
    args.forEach(function (a) { if (a === '-s') on = true; else if (a === '-u') on = false; else if (a === '-q' || a === '-p' || a === '-o') { } else names.push(a); });
    var st = 0, self = this;
    names.forEach(function (n) { if (n in self.shopt) { if (on !== null) self.shopt[n] = on; else if (!self.shopt[n]) st = 1; } else if (['extglob', 'globstar', 'expand_aliases', 'nocaseglob', 'checkwinsize', 'huponexit', 'cmdhist', 'histappend'].indexOf(n) === -1) { self.complain(io, 'shopt: ' + n + ': invalid shell option name'); st = 1; } });
    return st;
  });
  def('trap', function (args, io) {
    var self = this;
    if (!args.length || args[0] === '-p') { Object.keys(this.traps).forEach(function (k) { io.out.w("trap -- '" + self.traps[k] + "' " + k + '\n'); }); return 0; }
    var action = args[0], sigs = args.slice(1);
    if (/^[0-9]+$/.test(action) || action === '-') { if (action !== '-') { sigs = args; action = '-'; } }
    sigs.forEach(function (s) {
      var n = String(s).replace(/^SIG/, '').toUpperCase(); if (n === '0') n = 'EXIT';
      if (action === '-') delete self.traps[n]; else self.traps[n] = action;
    });
    return 0;
  });
  def('source .', function (args, io) {
    if (!args.length) { this.complain(io, 'source: filename argument required'); return 2; }
    var p = args[0], full;
    if (p.indexOf('/') === -1) { var f = this.findInPath(p); full = f || V.resolve(this.cwd, p); } else full = V.resolve(this.cwd, p);
    var src;
    try { src = this.vfs.read(full); } catch (e) { this.complain(io, p + ': ' + (e.code === 'EISDIR' ? 'Is a directory' : 'No such file or directory')); return 1; }
    var savedPos = this.pos, savedName = this.name0, keep = args.length > 1;
    if (keep) this.pos = args.slice(1);
    this.sourced++;
    var st;
    try {
      var prog = this.parseSafe(src, io);
      if (!prog) return 2;
      st = this.execSeq(prog, io);
    } catch (e2) { if (e2 instanceof Signal && e2.kind === 'return') st = e2.n; else throw e2; }
    finally { this.sourced--; if (keep) this.pos = savedPos; }
    return st;
  });
  def('type', function (args, io) {
    var self = this, st = 0, t = args.indexOf('-t') !== -1;
    args.filter(function (a) { return a[0] !== '-'; }).forEach(function (n) {
      if (self.funcs[n]) io.out.w(t ? 'function\n' : n + ' is a function\n');
      else if (B[n]) io.out.w(t ? 'builtin\n' : n + ' is a shell builtin\n');
      else if (root.RCUtils.get(n)) io.out.w(t ? 'file\n' : n + ' is /usr/bin/' + n + '\n');
      else { var p = self.findInPath(n); if (p) io.out.w(t ? 'file\n' : n + ' is ' + p + '\n'); else { if (!t) io.err.w('bash: type: ' + n + ': not found\n'); st = 1; } }
    });
    return st;
  });
  def('command', function (args, io) {
    if (args[0] === '-v' || args[0] === '-V') {
      var self = this, st = 0;
      args.slice(1).forEach(function (n) {
        if (self.funcs[n] || B[n]) io.out.w(n + '\n');
        else if (root.RCUtils.get(n)) io.out.w('/usr/bin/' + n + '\n');
        else { var p = self.findInPath(n); if (p) io.out.w(p + '\n'); else st = 1; }
      });
      return st;
    }
    if (args[0] === '--') args = args.slice(1);
    var name = args[0];
    if (B[name]) return B[name].call(this, args.slice(1), io);
    var u = root.RCUtils.get(name);
    if (u) return u(this, args.slice(1), io);
    return this.runPath(name, args.slice(1), io);
  });
  def('which', function (args, io) {
    var st = 0, self = this;
    args.forEach(function (n) { if (root.RCUtils.get(n)) io.out.w('/usr/bin/' + n + '\n'); else { var p = self.findInPath(n); if (p) io.out.w(p + '\n'); else st = 1; } });
    return st;
  });
  def('exec', function (args, io) {
    var st = this.dispatch(args, io);
    throw new Signal('exit', st);
  });
  def('bash sh', function (args, io) {
    var self = this, i = 0, check = false, cmd = null, x = false;
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '-n') check = true; else if (a === '-x') x = true; else if (a === '-e' || a === '-u' || a === '-eu' || a === '-ue' || a === '-l' || a === '--norc' || a === '--noprofile') { }
      else if (a === '-c') { cmd = args[++i]; i++; break; } else if (a === '-o') i++;
      else break;
    }
    var child, env = this.exported();
    function mk(name0, pos) {
      var c = new Shell(self.m);
      Object.keys(env).forEach(function (k) { c.vars[k] = { kind: 's', v: env[k], exp: true }; });
      c.cwd = self.cwd; c.vars.PWD.v = self.cwd; c.name0 = name0; c.pos = pos; c.depth = self.depth + 1; c.rootId = self.m.nextPid();
      if (x) c.opts.x = true;
      if (c.depth > 30) throw new Fail('fork: retry: Resource temporarily unavailable', 254).fatal();
      return c;
    }
    if (cmd !== null) { child = mk(args[i] || 'bash', args.slice(i + 1)); child.line = 1; return child.runTop(cmd, io); }
    if (i >= args.length) { return this.runTop ? mk('bash', []).runTop(io.in ? io.in.readAll() : '', io) : 0; }
    var file = args[i], full = V.resolve(this.cwd, file), src;
    try { src = this.vfs.read(full); } catch (e) { io.err.w('bash: ' + file + ': ' + (e.code === 'EISDIR' ? 'Is a directory' : 'No such file or directory') + '\n'); return 127; }
    if (check) { try { P.parse(src); return 0; } catch (e2) { if (e2.syntax) { io.err.w(file + ': line ' + (e2.line || 0) + ': ' + e2.message + '\n'); return 2; } throw e2; } }
    child = mk(file, args.slice(i + 1));
    return child.runTop(src, io);
  });

  // ——— read, mapfile ———
  function ifsSplit(str, ifs, max) {
    if (ifs === '') return [str];
    var wsSet = ifs.replace(/[^ \t\n]/g, ''), n = str.length, i = 0, out = [];
    var isIfs = function (c) { return ifs.indexOf(c) !== -1; }, isWs = function (c) { return wsSet.indexOf(c) !== -1; };
    while (i < n && isWs(str[i])) i++;
    while (i < n) {
      if (out.length === max - 1) { var e = n; while (e > i && isWs(str[e - 1])) e--; out.push(str.slice(i, e)); return out; }
      var j = i; while (j < n && !isIfs(str[j])) j++;
      out.push(str.slice(i, j));
      if (j >= n) return out;
      var k = j; while (k < n && isWs(str[k])) k++;
      if (k < n && isIfs(str[k]) && !isWs(str[k])) { k++; while (k < n && isWs(str[k])) k++; }
      i = k;
    }
    return out;
  }

  def('read', function (args, io) {
    var raw = false, arr = null, delim = '\n', nchars = null, names = [], i = 0, prompt = null;
    for (; i < args.length; i++) {
      var a = args[i];
      if (a === '-r') raw = true; else if (a === '-s') { } else if (a === '-a') arr = args[++i]; else if (a === '-d') { delim = args[++i]; if (delim === '') delim = '\0'; } else if (a === '-n' || a === '-N') nchars = parseInt(args[++i], 10);
      else if (a === '-p') prompt = args[++i]; else if (a === '-t' || a === '-u') i++;
      else if (/^-[rs]+$/.test(a)) raw = raw || a.indexOf('r') !== -1;
      else names.push(a);
    }
    if (prompt !== null && !io.in) { /* prompts only matter on a terminal */ }
    var stream = io.in;
    if (!stream) return 1;
    var line;
    if (nchars !== null) { var chunk = stream.buf.slice(stream.pos, stream.pos + nchars); stream.pos += chunk.length; line = chunk.length ? { s: chunk, partial: chunk.length < nchars } : null; }
    else {
      line = stream.readLine(delim);
      while (line && !raw && !line.partial && /\\$/.test(line.s)) { var more = stream.readLine(delim); if (!more) break; line = { s: line.s.slice(0, -1) + more.s, partial: more.partial }; }
    }
    if (!line) { if (arr) this.vars[arr] = { kind: 'a', v: [], exp: false }; else (names.length ? names : ['REPLY']).forEach(function (n) { /* unchanged on EOF in bash: set empty */ }); (names.length ? names : ['REPLY']).forEach(function (n) { if (!arr) this.set(n, ''); }, this); return 1; }
    var s = line.s;
    if (!raw) s = s.replace(/\\(.)/g, '$1');
    var ifs = this.get('IFS'); if (ifs === undefined) ifs = ' \t\n';
    var self = this;
    if (!names.length && !arr) { this.set('REPLY', line.s); return line.partial ? 1 : 0; }
    if (arr) { this.vars[arr] = { kind: 'a', v: ifsSplit(s, ifs, 1e9), exp: false }; return line.partial ? 1 : 0; }
    var fields = ifsSplit(s, ifs, names.length);
    names.forEach(function (n, k) { self.set(n, fields[k] === undefined ? '' : fields[k]); });
    return line.partial ? 1 : 0;
  });
  def('mapfile readarray', function (args, io) {
    var trim = false, name = 'MAPFILE', rest = [];
    args.forEach(function (a) { if (a === '-t') trim = true; else if (a[0] !== '-') rest.push(a); });
    if (rest.length) name = rest[0];
    var data = io.in ? io.in.readAll() : '', lines = data === '' ? [] : data.split('\n');
    if (lines.length && lines[lines.length - 1] === '') lines.pop();
    this.vars[name] = { kind: 'a', v: lines.map(function (l) { return trim ? l : l + '\n'; }), exp: false };
    return 0;
  });

  // ——— getopts ———
  def('getopts', function (args, io) {
    var spec = args[0], name = args[1], list = args.length > 2 ? args.slice(2) : this.pos;
    if (spec === undefined || name === undefined) { this.complain(io, 'getopts: usage: getopts optstring name [arg ...]'); return 2; }
    var silent = spec[0] === ':', opts = silent ? spec.slice(1) : spec;
    var optind = parseInt(this.get('OPTIND') || '1', 10);
    if (optind === 1 && this.optpos !== 0 && this._lastInd !== 1) this.optpos = 0;
    if (optind < 1) optind = 1;
    var setv = this;
    function done() { setv.set(name, '?'); setv.set('OPTIND', String(optind)); setv.vars.OPTARG = { kind: 's', v: '', exp: false, unset: true }; return 1; }
    var cur = list[optind - 1];
    if (cur === undefined || cur === '--' || cur[0] !== '-' || cur === '-') { if (cur === '--') optind++; return done(); }
    var pos = this.optpos || 1, ch = cur[pos], idx = opts.indexOf(ch);
    var advance = function () { if (pos + 1 >= cur.length) { optind++; setv.optpos = 0; } else setv.optpos = pos + 1; };
    if (idx === -1 || ch === ':') {
      advance();
      if (silent) { this.set('OPTARG', ch); } else { io.err.w(this.name0 + ': illegal option -- ' + ch + '\n'); this.vars.OPTARG = { kind: 's', v: '', exp: false, unset: true }; }
      this.set(name, '?'); this.set('OPTIND', String(optind)); this._lastInd = optind; return 0;
    }
    if (opts[idx + 1] === ':') {
      var arg;
      if (pos + 1 < cur.length) { arg = cur.slice(pos + 1); optind++; this.optpos = 0; }
      else if (list[optind] !== undefined) { arg = list[optind]; optind += 2; this.optpos = 0; }
      else {
        optind++; this.optpos = 0;
        if (silent) { this.set(name, ':'); this.set('OPTARG', ch); } else { io.err.w(this.name0 + ': option requires an argument -- ' + ch + '\n'); this.set(name, '?'); this.vars.OPTARG = { kind: 's', v: '', exp: false, unset: true }; }
        this.set('OPTIND', String(optind)); this._lastInd = optind; return 0;
      }
      this.set('OPTARG', arg); this.set(name, ch); this.set('OPTIND', String(optind)); this._lastInd = optind; return 0;
    }
    advance();
    this.set(name, ch); this.set('OPTIND', String(optind)); this._lastInd = optind;
    this.vars.OPTARG = { kind: 's', v: '', exp: false, unset: true };
    return 0;
  });

  // ——— test / [ ———
  function testEval(sh, a, io) {
    var p = 0;
    function peek() { return a[p]; }
    function or() { var v = and(); while (a[p] === '-o') { p++; var r = and(); v = v || r; } return v; }
    function and() { var v = not(); while (a[p] === '-a') { p++; var r = not(); v = v && r; } return v; }
    function not() { if (a[p] === '!' && p < a.length - 1) { p++; return !not(); } return prim(); }
    function prim() {
      var t = a[p];
      if (t === undefined) throw new Fail('argument expected', 2);
      if (t === '(' && a.indexOf(')', p) !== -1) { p++; var v = or(); if (a[p] !== ')') throw new Fail('`)\' expected', 2); p++; return v; }
      var rest = a.length - p;
      if (rest >= 3 && /^(=|==|!=|-eq|-ne|-lt|-le|-gt|-ge|-nt|-ot|-ef|<|>)$/.test(a[p + 1])) {
        var l = a[p], op = a[p + 1], r2 = a[p + 2]; p += 3;
        if (op === '=' || op === '==') return l === r2;
        if (op === '!=') return l !== r2;
        if (op === '<') return l < r2; if (op === '>') return l > r2;
        return sh.binaryTest(op, l, r2);
      }
      if (/^-[a-zA-Z]$/.test(t) && rest >= 2) { p += 2; return sh.unaryTest(t, a[p - 1]); }
      p++; return t !== '';
    }
    var r = or();
    if (p < a.length) throw new Fail('too many arguments', 2);
    return r;
  }
  def('test', function (args, io) {
    try { return testEval(this, args, io) ? 0 : 1; }
    catch (e) { if (e instanceof Fail) { this.complain(io, 'test: ' + e.msg); return e.status; } throw e; }
  });
  def('[', function (args, io) {
    if (args[args.length - 1] !== ']') { this.complain(io, '[: missing `]\''); return 2; }
    try { return testEval(this, args.slice(0, -1), io) ? 0 : 1; }
    catch (e) { if (e instanceof Fail) { this.complain(io, '[: ' + e.msg); return e.status; } throw e; }
  });

  root.RCBuiltins = { printfFormat: printfFormat, escapes: escapes };
})(typeof globalThis !== 'undefined' ? globalThis : window);
