/* Radio-Comète — Bash, second half: the interpreter.

   A Shell runs on one machine (a virtual file system, see vfs.js and fleet.js). It follows Bash
   where the game teaches it: word splitting and quoting, expansions, pipelines run in subshells,
   set -e / -u / -o pipefail, traps, functions with local variables, here-documents, getopts.
   Builtins are in builtins.js, the other commands in utils.js. */
(function (root) {
  'use strict';
  var V = root.RCVfs, P = root.RCParse, R = root.RCRegex, A = root.RCArith;

  // ——— Control flow and fatal conditions ———
  function Signal(kind, n) { this.kind = kind; this.n = n; }
  function Fail(msg, status) { this.msg = msg; this.status = status === undefined ? 1 : status; }

  // ——— Streams ———
  function Buf() { this.s = ''; }
  Buf.prototype.w = function (x) { this.s += x; };
  function Null() { }
  Null.prototype.w = function () { };
  function Stream(buf) { this.buf = buf; this.pos = 0; }
  Stream.prototype.eof = function () { return this.pos >= this.buf.length; };
  Stream.prototype.readLine = function (delim) {
    if (this.eof()) return null;
    var d = delim === undefined ? '\n' : delim, j = this.buf.indexOf(d, this.pos), line;
    if (j === -1) { line = this.buf.slice(this.pos); this.pos = this.buf.length; return { s: line, partial: true }; }
    line = this.buf.slice(this.pos, j); this.pos = j + d.length;
    return { s: line, partial: false };
  };
  Stream.prototype.readAll = function () { var s = this.buf.slice(this.pos); this.pos = this.buf.length; return s; };
  function FileOut(sh, path, append) { this.sh = sh; this.path = path; this.failed = null; }
  FileOut.prototype.w = function (x) {
    try { this.sh.vfs.write(this.path, x, true); } catch (e) { this.failed = e.code || 'EIO'; }
  };

  function Shell(m) {
    this.m = m; this.vfs = m.vfs;
    this.vars = Object.create(null); this.funcs = Object.create(null);
    this.cwd = m.home || '/home/admin';
    this.opts = { e: false, u: false, pipefail: false, x: false, f: false };
    this.shopt = { nullglob: false, dotglob: false, inherit_errexit: false, lastpipe: false, nocasematch: false };
    this.pos = []; this.status = 0; this.traps = {}; this.name0 = 'bash';
    this.line = 0; this.noerr = 0; this.fds = {}; this.depth = 0; this.callDepth = 0; this.frames = [];
    this.optpos = 0; this.subStatus = 0; this.interactive = false; this.rootId = m.nextPid ? m.nextPid() : 1;
    this.inTrap = {}; this.pipeStatus = [0]; this.sourced = 0;
    var self = this;
    var env = { HOME: m.home || '/home/admin', USER: 'admin', LOGNAME: 'admin', SHELL: '/bin/bash', HOSTNAME: m.name || 'localhost', TERM: 'xterm', LANG: 'C.UTF-8',
      PATH: '/home/admin/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin', PWD: this.cwd, OLDPWD: '' };
    Object.keys(env).forEach(function (k) { self.vars[k] = { kind: 's', v: env[k], exp: true }; });
    if (m.env) Object.keys(m.env).forEach(function (k) { self.vars[k] = { kind: 's', v: m.env[k], exp: true }; });
    this.vars.PWD.v = this.cwd;
    this.vars.OPTIND = { kind: 's', v: '1' };
  }

  // ——— Variables ———
  Shell.prototype.clean = function (name) { return name; };
  Shell.prototype.get = function (name) {
    switch (name) {
      case 'RANDOM': this.m.rand = (this.m.rand * 1103515245 + 12345) & 0x7fffffff; return String(this.m.rand % 32768);
      case 'LINENO': return String(this.line);
      case 'SECONDS': return '0';
      case 'EPOCHSECONDS': return String(this.vfs.now);
      case 'BASHPID': return String(this.rootId);
      case 'PPID': return '1';
    }
    var v = this.vars[name];
    if (!v || v.unset) return undefined;
    if (v.kind === 's') return v.v;
    if (v.kind === 'a') return v.v[0];
    return v.v['0'];
  };
  Shell.prototype.isSet = function (name) { var v = this.vars[name]; return !!v && !v.unset; };
  Shell.prototype.check = function (name) {
    var v = this.vars[name];
    if (v && v.ro) throw new Fail(name + ': readonly variable', 1);
  };
  Shell.prototype.set = function (name, val, exp) {
    this.check(name);
    var v = this.vars[name];
    if (v && v.int) val = String(this.arith(String(val)));
    if (v && v.upper) val = String(val).toUpperCase();
    if (v && v.lower) val = String(val).toLowerCase();
    if (!v) { this.vars[name] = { kind: 's', v: String(val), exp: !!exp }; return; }
    delete v.unset;
    if (v.kind === 's') v.v = String(val);
    else if (v.kind === 'a') v.v[0] = String(val);
    else v.v['0'] = String(val);
    if (exp) v.exp = true;
  };
  Shell.prototype.setIndex = function (name, sub, val, append) {
    this.check(name);
    var v = this.vars[name];
    if (!v || v.unset) { v = this.vars[name] = { kind: 'a', v: [], exp: false }; }
    if (v.kind === 's') { var old = v.v; v.kind = 'a'; v.v = old === '' ? [] : [old]; }
    if (v.kind === 'a') { var k = Math.trunc(Number(sub)); if (k < 0) k += v.v.length; v.v[k] = (append && v.v[k] ? v.v[k] : '') + String(val); }
    else v.v[sub] = (append && v.v[sub] ? v.v[sub] : '') + String(val);
  };
  Shell.prototype.indexGet = function (name, sub) {
    var v = this.vars[name];
    if (!v || v.unset) return undefined;
    if (v.kind === 'A') return v.v[sub];
    if (v.kind === 'a') { var k = Math.trunc(Number(sub)); if (k < 0) k += v.v.length; return v.v[k]; }
    return Number(sub) === 0 ? v.v : undefined;
  };
  Shell.prototype.listOf = function (name) {
    var v = this.vars[name];
    if (!v || v.unset) return [];
    if (v.kind === 's') return [v.v];
    if (v.kind === 'a') return v.v.filter(function (x) { return x !== undefined; });
    return Object.keys(v.v).map(function (k) { return v.v[k]; });
  };
  Shell.prototype.keysOf = function (name) {
    var v = this.vars[name];
    if (!v || v.unset) return [];
    if (v.kind === 's') return ['0'];
    if (v.kind === 'a') { var ks = []; v.v.forEach(function (x, i) { if (x !== undefined) ks.push(String(i)); }); return ks; }
    return Object.keys(v.v);
  };
  Shell.prototype.exported = function () {
    var out = {}, self = this;
    Object.keys(this.vars).forEach(function (k) { var v = self.vars[k]; if (v.exp && !v.unset && v.kind === 's') out[k] = v.v; });
    return out;
  };

  // ——— Messages ———
  Shell.prototype.prefix = function () { return this.interactive ? 'bash: ' : this.name0 + ': line ' + this.line + ': '; };
  Shell.prototype.complain = function (io, text) { io.err.w(this.prefix() + text + '\n'); };
  Shell.prototype.tick = function () {
    if (--this.m.budget < 0) { var e = new Signal('timeout', 124); throw e; }
  };

  // ——— Arithmetic bridge ———
  Shell.prototype.arith = function (expr) {
    var self = this;
    try {
      return A.evaluate({
        get: function (n) { return self.get(n); },
        set: function (n, v) { self.set(n, String(v)); },
        index: function (n, s) { return self.indexGet(n, s); },
        setIndex: function (n, s, v) { self.setIndex(n, s, String(v)); },
        isAssoc: function (n) { var v = self.vars[n]; return !!v && v.kind === 'A'; },
        expand: function (s) { return self.expandStr(s.replace(/\$\(\(/g, '$(('), self.curIo || { err: new Null(), in: null }); }
      }, expr);
    } catch (e) {
      if (e && e.arith) throw new Fail(expr.trim() + ': ' + e.message, 1);
      throw e;
    }
  };

  // ——— Expansion ———
  function ansiC(s) {
    return s.replace(/\\(x[0-9a-fA-F]{1,2}|u[0-9a-fA-F]{1,4}|[0-7]{1,3}|.)/g, function (m, c) {
      if (c[0] === 'x') return String.fromCharCode(parseInt(c.slice(1), 16));
      if (c[0] === 'u') return String.fromCharCode(parseInt(c.slice(1), 16));
      if (/^[0-7]+$/.test(c)) return String.fromCharCode(parseInt(c, 8));
      return { n: '\n', t: '\t', r: '\r', a: '\x07', b: '\b', e: '\x1b', f: '\f', v: '\v', '\\': '\\', "'": "'", '"': '"' }[c] || m;
    });
  }
  function braceExpand(s) {
    for (var i = 0; i < s.length; i++) {
      var c = s[i];
      if (c === '\\') { i++; continue; }
      if (c === "'") { i = s.indexOf("'", i + 1); if (i === -1) return [s]; continue; }
      if (c === '"') { try { i = P.skipQuoted(s, i + 1, '"') - 1; } catch (e) { return [s]; } continue; }
      if (c === '$' && s[i + 1] === '{') { try { i = P.skipBrace(s, i + 1) - 1; } catch (e2) { return [s]; } continue; }
      if (c === '$' && s[i + 1] === '(') { try { i = P.skipParen(s, i + 1) - 1; } catch (e3) { return [s]; } continue; }
      if (c !== '{') continue;
      var depth = 0, j = i, commas = [];
      for (; j < s.length; j++) {
        var d = s[j];
        if (d === '\\') { j++; continue; }
        if (d === "'" ) { j = s.indexOf("'", j + 1); if (j === -1) break; continue; }
        if (d === '"') { try { j = P.skipQuoted(s, j + 1, '"') - 1; } catch (e4) { j = s.length; } continue; }
        if (d === '{') depth++;
        else if (d === '}') { depth--; if (depth === 0) break; }
        else if (d === ',' && depth === 1) commas.push(j);
      }
      if (j >= s.length) continue;
      var pre = s.slice(0, i), post = s.slice(j + 1), body = s.slice(i + 1, j), out = [];
      if (commas.length) {
        var parts = [], last = i + 1;
        commas.forEach(function (k) { parts.push(s.slice(last, k)); last = k + 1; });
        parts.push(s.slice(last, j));
        parts.forEach(function (pt) { braceExpand(pre + pt + post).forEach(function (x) { out.push(x); }); });
        return out;
      }
      var m = /^(-?\d+|[A-Za-z])\.\.(-?\d+|[A-Za-z])(?:\.\.(-?\d+))?$/.exec(body);
      if (m) {
        var seq = [], step = m[3] ? Math.abs(parseInt(m[3], 10)) || 1 : 1;
        if (/^-?\d+$/.test(m[1]) && /^-?\d+$/.test(m[2])) {
          var a = parseInt(m[1], 10), b = parseInt(m[2], 10), width = (/^-?0\d/.test(m[1]) || /^-?0\d/.test(m[2])) ? Math.max(m[1].length, m[2].length) : 0;
          for (var x = a; a <= b ? x <= b : x >= b; x += a <= b ? step : -step) { var t = String(Math.abs(x)); while (t.length < width - (x < 0 ? 1 : 0)) t = '0' + t; seq.push((x < 0 ? '-' : '') + t); }
        } else {
          var ca = m[1].charCodeAt(0), cb = m[2].charCodeAt(0);
          for (var y = ca; ca <= cb ? y <= cb : y >= cb; y += ca <= cb ? step : -step) seq.push(String.fromCharCode(y));
        }
        seq.forEach(function (sv) { braceExpand(pre + sv + post).forEach(function (x2) { out.push(x2); }); });
        return out;
      }
    }
    return [s];
  }

  function coalesce(frags) {
    var out = [];
    frags.forEach(function (f) {
      var l = out[out.length - 1];
      if (l && !f.sep && !l.sep && l.t === f.t) l.s += f.s; else out.push({ s: f.s, t: f.t, sep: f.sep });
    });
    return out;
  }

  Shell.prototype.expandFrags = function (raw, io, o) {
    o = o || {};
    var frags = [], i = 0, n = raw.length, dq = !!o.heredoc;
    function push(s, t) { frags.push({ s: s, t: t }); }
    while (i < n) {
      var c = raw[i];
      if (dq) {
        if (c === '"' && !o.heredoc) { dq = false; i++; continue; }
        if (c === '\\') {
          var d = raw[i + 1];
          if (d === '$' || d === '`' || d === '\\' || (d === '"' && !o.heredoc)) { push(d, 'q'); i += 2; }
          else if (d === '\n') i += 2;
          else { push('\\', 'q'); i++; }
          continue;
        }
        if (c === '$') { i = this.expandDollar(raw, i, true, io, frags); continue; }
        if (c === '`') { i = this.expandTick(raw, i, true, io, frags); continue; }
        push(c, 'q'); i++; continue;
      }
      if (c === '\\') { if (raw[i + 1] === '\n') { i += 2; continue; } push(raw[i + 1] === undefined ? '\\' : raw[i + 1], 'q'); i += 2; continue; }
      if (c === "'") { var j = raw.indexOf("'", i + 1); if (j === -1) j = n; push(raw.slice(i + 1, j), 'q'); i = j + 1; continue; }
      if (c === '"') { dq = true; push('', 'q'); i++; continue; }
      if (c === '$') { i = this.expandDollar(raw, i, false, io, frags); continue; }
      if (c === '`') { i = this.expandTick(raw, i, false, io, frags); continue; }
      if ((c === '<' || c === '>') && raw[i + 1] === '(') {
        var e = P.skipParen(raw, i + 1);
        if (c === '<') push(this.procSub(raw.slice(i + 2, e - 1), io), 'q'); else push(raw.slice(i, e), 'q');
        i = e; continue;
      }
      if (c === '~' && (i === 0 || (o.assign && raw[i - 1] === ':'))) {
        var m = /^~([A-Za-z0-9_.-]*)(?=\/|:|$)/.exec(raw.slice(i));
        if (m) {
          var home = m[1] === '' ? this.get('HOME') : (m[1] === '+' ? this.get('PWD') : '/home/' + m[1]);
          if (home !== undefined) { push(home, 'q'); i += m[0].length; continue; }
        }
      }
      push(c, 'lit'); i++;
    }
    return coalesce(frags);
  };

  Shell.prototype.expandTick = function (raw, i, dq, io, frags) {
    var e = P.skipTick(raw, i + 1);
    var body = raw.slice(i + 1, e - 1).replace(/\\([$`\\])/g, '$1');
    frags.push({ s: this.cmdSub(body, io), t: dq ? 'q' : 'exp' });
    return e;
  };
  Shell.prototype.procSub = function (body, io) {
    var sub = this.fork(), buf = new Buf();
    sub.runBody(body, { in: io.in, out: buf, err: io.err });
    this.m.procN = (this.m.procN || 62) + 1;
    var path = '/dev/fd/' + this.m.procN;
    this.vfs.mkdir('/dev/fd', true);
    this.vfs.nodes[path] = { t: 'f', data: buf.s, mode: 420, mtime: this.vfs.now, owner: 'admin' };
    return path;
  };
  Shell.prototype.cmdSub = function (body, io) {
    var sub = this.fork(), buf = new Buf();
    if (!this.shopt.inherit_errexit) sub.opts.e = false;
    this.subStatus = sub.runBody(body, { in: io.in, out: buf, err: io.err });
    return buf.s.replace(/\n+$/, '');
  };

  Shell.prototype.special = function (c) {
    switch (c) {
      case '?': return String(this.status);
      case '#': return String(this.pos.length);
      case '$': return String(this.rootId + 3000);
      case '!': return String(this.m.lastBg || '');
      case '-': return (this.opts.e ? 'e' : '') + (this.opts.u ? 'u' : '') + (this.opts.x ? 'x' : '') + 'hB';
    }
    return '';
  };

  Shell.prototype.expandDollar = function (raw, i, dq, io, frags) {
    var c = raw[i + 1], t = dq ? 'q' : 'exp', self = this;
    if (c === '(') {
      if (raw[i + 2] === '(') {
        var depth = 0, k = i + 3, end = -1;
        for (; k < raw.length; k++) {
          if (raw[k] === '(') depth++;
          else if (raw[k] === ')') { if (depth === 0 && raw[k + 1] === ')') { end = k; break; } depth--; }
        }
        if (end !== -1) {
          this.curIo = io;
          frags.push({ s: String(this.arith(raw.slice(i + 3, end))), t: t });
          return end + 2;
        }
      }
      var e = P.skipParen(raw, i + 1);
      frags.push({ s: this.cmdSub(raw.slice(i + 2, e - 1), io), t: t });
      return e;
    }
    if (c === '{') { var e2 = P.skipBrace(raw, i + 1); this.paramExpr(raw.slice(i + 2, e2 - 1), dq, io, frags); return e2; }
    if (c === "'" && !dq) { var j = raw.indexOf("'", i + 2); while (j !== -1 && raw[j - 1] === '\\' && raw[j - 2] !== '\\') j = raw.indexOf("'", j + 1); if (j === -1) j = raw.length; frags.push({ s: ansiC(raw.slice(i + 2, j)), t: 'q' }); return j + 1; }
    if (c !== undefined && /[A-Za-z_]/.test(c)) {
      var m = /^[A-Za-z_][A-Za-z0-9_]*/.exec(raw.slice(i + 1))[0];
      this.simpleParam(m, null, dq, frags);
      return i + 1 + m.length;
    }
    if (c !== undefined && /[0-9]/.test(c)) { this.simpleParam(c, null, dq, frags); return i + 2; }
    if (c !== undefined && '@*?#$!-'.indexOf(c) !== -1) { this.simpleParam(c, null, dq, frags); return i + 2; }
    frags.push({ s: '$', t: 'lit' });
    return i + 1;
  };

  /** The value of a parameter: { set, list (for @ and *), str }. */
  Shell.prototype.paramValue = function (name, sub) {
    if (name === '@' || name === '*') return { set: this.pos.length > 0, list: this.pos.slice(), at: name === '@', str: this.pos.join(' ') };
    if (sub === '@' || sub === '*') { var l = this.listOf(name); return { set: l.length > 0, list: l, at: sub === '@', str: l.join(' ') }; }
    if (sub !== null && sub !== undefined) {
      var v0 = this.vars[name], key = sub;
      if (!v0 || v0.kind !== 'A') key = String(this.arith(sub)); else key = this.expandStr(sub, this.curIo || { err: new Null() });
      var val = this.indexGet(name, key);
      return { set: val !== undefined, str: val === undefined ? '' : val };
    }
    if (/^[0-9]+$/.test(name)) { if (name === '0') return { set: true, str: this.name0 }; var pv = this.pos[parseInt(name, 10) - 1]; return { set: pv !== undefined, str: pv === undefined ? '' : pv }; }
    if ('?#$!-'.indexOf(name) !== -1 && name.length === 1) return { set: true, str: this.special(name) };
    var g = this.get(name);
    return { set: g !== undefined, str: g === undefined ? '' : g };
  };

  Shell.prototype.unbound = function (name) {
    if (this.opts.u) throw new Fail(name + ': unbound variable', 1).fatal();
  };
  Fail.prototype.fatal = function () { this.isFatal = true; return this; };

  Shell.prototype.simpleParam = function (name, sub, dq, frags) {
    var pv = this.paramValue(name, sub);
    if (!pv.set && name !== '@' && name !== '*' && sub !== '@' && sub !== '*') this.unbound(name);
    this.pushValue(pv, dq, frags);
  };
  Shell.prototype.pushValue = function (pv, dq, frags) {
    if (pv.list) {
      if (dq && pv.at) {
        pv.list.forEach(function (x, k) { if (k) frags.push({ sep: true, s: '', t: 'q' }); frags.push({ s: x, t: 'q' }); });
      } else if (dq) {
        var ifs = this.get('IFS'); frags.push({ s: pv.list.join(ifs === undefined ? ' ' : (ifs[0] || '')), t: 'q' });
      } else frags.push({ s: pv.list.join(' '), t: 'exp' });
      return;
    }
    frags.push({ s: pv.str, t: dq ? 'q' : 'exp' });
  };

  function removePattern(val, pat, mode, nocase) {
    var re = R.globRe(pat, nocase ? 'i' : ''), k;
    if (mode === '#') { for (k = 0; k <= val.length; k++) if (re.test(val.slice(0, k))) return val.slice(k); }
    else if (mode === '##') { for (k = val.length; k >= 0; k--) if (re.test(val.slice(0, k))) return val.slice(k); }
    else if (mode === '%') { for (k = val.length; k >= 0; k--) if (re.test(val.slice(k))) return val.slice(0, k); }
    else { for (k = 0; k <= val.length; k++) if (re.test(val.slice(k))) return val.slice(0, k); }
    return val;
  }

  Shell.prototype.paramExpr = function (c, dq, io, frags) {
    var self = this, t = dq ? 'q' : 'exp';
    this.curIo = io;
    var indirect = false, lenOp = false, keys = false;
    if (c[0] === '#' && c.length > 1 && /^#[A-Za-z_0-9@*?$!-]/.test(c)) { lenOp = true; c = c.slice(1); }
    else if (c[0] === '!' && c.length > 1 && /^![A-Za-z_]/.test(c)) { indirect = true; c = c.slice(1); }
    var m = /^([A-Za-z_][A-Za-z0-9_]*|[0-9]+|[@*#?$!-])(?:\[((?:[^\]\\]|\\.)*)\])?/.exec(c);
    if (!m) throw new Fail('${' + c + '}: bad substitution', 1).fatal();
    var name = m[1], sub = m[2] === undefined ? null : m[2], rest = c.slice(m[0].length);
    if (indirect) {
      if (sub === '@' || sub === '*') { keys = true; }
      else { var tgt = this.paramValue(name, sub).str; name = tgt; sub = null; var mm = /^([A-Za-z_][A-Za-z0-9_]*)\[(.*)\]$/.exec(tgt); if (mm) { name = mm[1]; sub = mm[2]; } }
    }
    if (keys) { var ks = this.keysOf(name); this.pushValue({ set: ks.length > 0, list: ks, at: sub === '@' }, dq, frags); return; }
    var pv = this.paramValue(name, sub);
    if (lenOp) { frags.push({ s: String(pv.list ? pv.list.length : Array.from(pv.str).length), t: t }); return; }
    if (rest === '') {
      if (!pv.set && !pv.list) this.unbound(sub !== null && sub !== '@' && sub !== '*' ? name + '[' + sub + ']' : name);
      this.pushValue(pv, dq, frags); return;
    }
    var op = rest[0], arg;
    var isNullOrUnset = function () { return !pv.set || (pv.list ? pv.list.length === 0 : pv.str === ''); };
    if (op === ':' && /^:[-=?+]/.test(rest)) {
      var o2 = rest[1]; arg = rest.slice(2);
      this.applyDefault(o2, true, name, sub, pv, arg, dq, io, frags, isNullOrUnset());
      return;
    }
    if (/^[-=?+]/.test(op)) { this.applyDefault(op, false, name, sub, pv, rest.slice(1), dq, io, frags, !pv.set); return; }
    if (!pv.set && !pv.list) this.unbound(name);
    var map = function (fn) { if (pv.list) { self.pushValue({ set: true, list: pv.list.map(fn), at: pv.at }, dq, frags); } else frags.push({ s: fn(pv.str), t: t }); };
    var nocase = this.shopt.nocasematch;
    if (op === '#' || op === '%') {
      var dbl = rest[1] === op, pat = this.expandPat(rest.slice(dbl ? 2 : 1), io);
      map(function (x) { return removePattern(x, pat, dbl ? op + op : op, nocase); }); return;
    }
    if (op === '/') {
      var body = rest.slice(1), mode = '';
      if (body[0] === '/' || body[0] === '#' || body[0] === '%') { mode = body[0]; body = body.slice(1); }
      var idx = -1;
      for (var k = 0; k < body.length; k++) { if (body[k] === '\\') { k++; continue; } if (body[k] === '/') { idx = k; break; } }
      var patRaw = idx === -1 ? body : body.slice(0, idx), repRaw = idx === -1 ? '' : body.slice(idx + 1);
      var src = R.globSrc(this.expandPat(patRaw, io)), rep = this.expandStr(repRaw, io);
      var re2;
      if (mode === '#') re2 = new RegExp('^' + src); else if (mode === '%') re2 = new RegExp(src + '$'); else re2 = new RegExp(src, mode === '/' ? 'g' : '');
      map(function (x) { return src === '' ? x : x.replace(re2, function () { return rep; }); }); return;
    }
    if (op === '^' || op === ',') {
      var all = rest[1] === op;
      map(function (x) { return all ? (op === '^' ? x.toUpperCase() : x.toLowerCase()) : (op === '^' ? x.charAt(0).toUpperCase() + x.slice(1) : x.charAt(0).toLowerCase() + x.slice(1)); }); return;
    }
    if (op === ':') {
      var parts = rest.slice(1), colon = parts.indexOf(':'), offS = colon === -1 ? parts : parts.slice(0, colon), lenS = colon === -1 ? null : parts.slice(colon + 1);
      var off = this.arith(offS), len = lenS === null ? null : this.arith(lenS);
      if (pv.list) {
        var base = (name === '@' || name === '*') ? [this.name0].concat(this.pos) : pv.list;
        var from = off < 0 ? Math.max(0, base.length + off) : off;
        var sl = len === null ? base.slice(from) : base.slice(from, len < 0 ? base.length + len : from + len);
        this.pushValue({ set: true, list: sl, at: pv.at }, dq, frags); return;
      }
      var chars = Array.from(pv.str), f = off < 0 ? Math.max(0, chars.length + off) : off;
      frags.push({ s: (len === null ? chars.slice(f) : chars.slice(f, len < 0 ? chars.length + len : f + len)).join(''), t: t }); return;
    }
    if (op === '@') { map(function (x) { return rest[1] === 'Q' ? "'" + x.replace(/'/g, "'\\''") + "'" : (rest[1] === 'U' ? x.toUpperCase() : (rest[1] === 'L' ? x.toLowerCase() : x)); }); return; }
    throw new Fail('${' + c + '}: bad substitution', 1).fatal();
  };
  Shell.prototype.applyDefault = function (op, colon, name, sub, pv, argRaw, dq, io, frags, empty) {
    var t = dq ? 'q' : 'exp';
    if (op === '-') { if (empty) { this.expandInto(argRaw, dq, io, frags); } else this.pushValue(pv, dq, frags); }
    else if (op === '+') { if (!empty) this.expandInto(argRaw, dq, io, frags); }
    else if (op === '=') {
      if (empty) {
        var val = this.expandStr(argRaw, io);
        if (sub !== null) this.setIndex(name, sub === '@' ? '0' : sub, val); else this.set(name, val);
        frags.push({ s: val, t: t });
      } else this.pushValue(pv, dq, frags);
    } else if (op === '?') {
      if (empty) {
        var msg = argRaw === '' ? (colon ? 'parameter null or not set' : 'parameter not set') : this.expandStr(argRaw, io);
        throw new Fail(name + ': ' + msg, 1).fatal();
      }
      this.pushValue(pv, dq, frags);
    }
  };
  Shell.prototype.expandInto = function (raw, dq, io, frags) {
    var inner = this.expandFrags(raw, io, {});
    inner.forEach(function (f) { frags.push(dq && f.t !== 'q' ? { s: f.s, t: 'q', sep: f.sep } : f); });
  };

  Shell.prototype.expandStr = function (raw, io, o) {
    return this.expandFrags(raw, io, o).map(function (f) { return f.sep ? ' ' : f.s; }).join('');
  };
  Shell.prototype.expandPat = function (raw, io) {
    return this.expandFrags(raw, io).map(function (f) { return f.t === 'q' ? f.s.replace(/[\\*?[\]]/g, '\\$&') : f.s; }).join('');
  };
  Shell.prototype.expandHeredoc = function (body, io) { return this.expandFrags(body, io, { heredoc: true }).map(function (f) { return f.s; }).join(''); };

  Shell.prototype.fieldsFromFrags = function (frags) {
    var ifs = this.get('IFS'); if (ifs === undefined) ifs = ' \t\n';
    var fields = [], cur = null;
    function newCur() { return { lit: '', pat: '', q: false }; }
    function flush() { if (cur) fields.push(cur); cur = null; }
    var isWs = function (ch) { return ifs.indexOf(ch) !== -1 && /[ \t\n]/.test(ch); };
    var isDelim = function (ch) { return ifs.indexOf(ch) !== -1; };
    frags.forEach(function (f) {
      if (f.sep) { flush(); return; }
      if (f.t === 'q') { cur = cur || newCur(); cur.q = true; cur.lit += f.s; cur.pat += f.s.replace(/[\\*?[\]]/g, '\\$&'); return; }
      if (f.t === 'lit') { cur = cur || newCur(); cur.lit += f.s; cur.pat += f.s; return; }
      // exp: split on IFS
      if (ifs === '') { if (f.s !== '') { cur = cur || newCur(); cur.lit += f.s; cur.pat += f.s; } return; }
      var s = f.s;
      for (var k = 0; k < s.length; k++) {
        var ch = s[k];
        if (isDelim(ch)) {
          if (isWs(ch)) { if (cur) flush(); }
          else { if (!cur) cur = newCur(); flush(); }
        } else { cur = cur || newCur(); cur.lit += ch; cur.pat += ch; }
      }
    });
    flush();
    return fields;
  };

  Shell.prototype.expandFields = function (raw, io) {
    this.curIo = io;
    if (raw === '"$@"') return this.pos.slice();
    var m = /^"\$\{([A-Za-z_][A-Za-z0-9_]*)\[@\]\}"$/.exec(raw);
    if (m) return this.listOf(m[1]);
    var out = [], self = this, raws = (raw.indexOf('{') !== -1 && !this.opts.braceoff) ? braceExpand(raw) : [raw];
    raws.forEach(function (r) {
      var fields = self.fieldsFromFrags(self.expandFrags(r, io));
      fields.forEach(function (f) {
        if (f.lit === '' && !f.q) return;
        if (!self.opts.f && R.hasGlob(f.pat.replace(/\\./g, ''))) {
          var hits = self.globExpand(f.pat);
          if (hits.length) { hits.forEach(function (h) { out.push(h); }); return; }
          if (self.shopt.nullglob) return;
        }
        out.push(f.lit);
      });
    });
    return out;
  };

  Shell.prototype.globExpand = function (pat) {
    var abs = pat[0] === '/', segs = [], cur = '';
    for (var i = 0; i < pat.length; i++) { if (pat[i] === '\\') { cur += pat[i] + (pat[i + 1] || ''); i++; continue; } if (pat[i] === '/') { segs.push(cur); cur = ''; } else cur += pat[i]; }
    segs.push(cur);
    var trailing = segs.length > 1 && segs[segs.length - 1] === '';
    segs = segs.filter(function (s, k) { return s !== '' || false; });
    var results = [abs ? '/' : ''], self = this, unesc = function (s) { return s.replace(/\\(.)/g, '$1'); };
    var join = function (base, name) { return base === '' ? name : (base === '/' ? '/' + name : base + '/' + name); };
    segs.forEach(function (seg, k) {
      var last = k === segs.length - 1, next = [];
      results.forEach(function (base) {
        if (!R.hasGlob(seg.replace(/\\./g, ''))) {
          var path = join(base, unesc(seg));
          if (self.vfs.lexists(V.resolve(self.cwd, path))) next.push(path);
          return;
        }
        var names;
        try { names = self.vfs.list(V.resolve(self.cwd, base === '' ? '.' : base)); } catch (e) { return; }
        var re = R.globRe(seg);
        names.forEach(function (nm) {
          if (nm[0] === '.' && seg[0] !== '.' && !self.shopt.dotglob) return;
          if (!re.test(nm)) return;
          var path2 = join(base, nm);
          if (!last && !self.vfs.isDir(V.resolve(self.cwd, path2))) return;
          next.push(path2);
        });
      });
      results = next;
    });
    if (trailing) results = results.filter(function (p) { return self.vfs.isDir(V.resolve(self.cwd, p)); }).map(function (p) { return p + '/'; });
    return results.sort(function (a, b) { return a < b ? -1 : (a > b ? 1 : 0); });
  };

  // ——— Execution ———
  Shell.prototype.fork = function () {
    var s = new Shell(this.m), self = this;
    s.vars = Object.create(null);
    Object.keys(this.vars).forEach(function (k) {
      var v = self.vars[k], c = { kind: v.kind, exp: v.exp, ro: v.ro, int: v.int, unset: v.unset, upper: v.upper, lower: v.lower };
      c.v = v.kind === 'a' ? v.v.slice() : (v.kind === 'A' ? Object.assign(Object.create(null), v.v) : v.v);
      s.vars[k] = c;
    });
    s.funcs = Object.assign(Object.create(null), this.funcs);
    s.cwd = this.cwd; s.opts = Object.assign({}, this.opts); s.shopt = Object.assign({}, this.shopt);
    s.pos = this.pos.slice(); s.status = this.status; s.name0 = this.name0; s.noerr = this.noerr;
    s.fds = Object.assign({}, this.fds); s.depth = this.depth + 1; s.line = this.line; s.rootId = this.rootId;
    s.callDepth = this.callDepth; s.interactive = this.interactive; s.frames = this.frames.slice();
    if (s.depth > 60) throw new Fail('maximum nesting level exceeded', 1);
    return s;
  };

  Shell.prototype.runTrap = function (name, io) {
    var body = this.traps[name];
    if (!body || this.inTrap[name]) return;
    this.inTrap[name] = true;
    var saved = this.status;
    try { this.runString(body, io); } finally { this.inTrap[name] = false; }
    this.status = saved;
  };

  Shell.prototype.parseSafe = function (src, io) {
    try { return P.parse(src); }
    catch (e) {
      if (!e.syntax) throw e;
      io.err.w(this.name0 + ': line ' + (e.line || 0) + ': ' + e.message + '\n');
      return null;
    }
  };
  /** Runs source text in this very shell (eval, source, traps). */
  Shell.prototype.runString = function (src, io) {
    var prog = this.parseSafe(src, io);
    if (!prog) { this.status = 2; return 2; }
    return this.execSeq(prog, io);
  };
  /** Runs a body in a (sub)shell and returns its status: exit and fatal errors end it. */
  Shell.prototype.runBody = function (src, io) {
    var st;
    try {
      var prog = this.parseSafe(src, io);
      if (!prog) return 2;
      st = this.execSeq(prog, io);
    } catch (e) {
      if (e instanceof Signal) { if (e.kind === 'exit' || e.kind === 'return') st = e.n; else if (e.kind === 'timeout') throw e; else st = 0; }
      else if (e instanceof Fail) { io.err.w(this.prefix() + e.msg + '\n'); st = e.status; }
      else throw e;
    }
    try { this.runTrap('EXIT', io); } catch (e2) { if (!(e2 instanceof Signal)) throw e2; }
    return st;
  };
  /** The top of a script: like runBody, plus the locks the script holds are released. */
  Shell.prototype.runTop = function (src, io) {
    var st;
    try { st = this.runBody(src, io); }
    finally { this.releaseLocks(); }
    this.status = st;
    return st;
  };
  Shell.prototype.releaseLocks = function () {
    var m = this.m, id = this.rootId;
    m.locks.forEach(function (owner, path) { if (owner === id) m.locks.delete(path); });
  };
  /** One command line typed in the terminal: state persists, errors do not end the shell. */
  Shell.prototype.runInteractive = function (src, io) {
    this.interactive = true; this.line = 1;
    var st;
    try {
      var prog = this.parseSafe(src, io);
      if (!prog) { this.status = 2; return 2; }
      this.m.budget = this.m.budgetMax;
      st = this.execSeq(prog, io);
    } catch (e) {
      if (e instanceof Signal) { st = e.kind === 'timeout' ? (io.err.w('bash: the command ran too long and was stopped (infinite loop?)\n'), 124) : e.n; }
      else if (e instanceof Fail) { io.err.w(this.prefix() + e.msg + '\n'); st = e.status; }
      else throw e;
    }
    this.status = st;
    return st;
  };

  Shell.prototype.execSeq = function (seq, io) {
    var st = this.status;
    for (var i = 0; i < seq.items.length; i++) {
      var it = seq.items[i];
      if (it.bg) {
        var sub = this.fork();
        try { sub.execAndOr(it.node, io); } catch (e) { if (!(e instanceof Signal)) throw e; }
        this.m.lastBg = (this.m.lastBg || 5000) + 1; st = 0;
      } else st = this.execAndOr(it.node, io);
      this.status = st;
    }
    return st;
  };
  Shell.prototype.execAndOr = function (node, io) {
    var st = this.execPipe(node.first, io, node.rest.length > 0);
    for (var k = 0; k < node.rest.length; k++) {
      var r = node.rest[k];
      if ((r.op === '&&' && st === 0) || (r.op === '||' && st !== 0)) { this.status = st; st = this.execPipe(r.p, io, k < node.rest.length - 1); }
    }
    return st;
  };
  Shell.prototype.execPipe = function (node, io, notLast) {
    this.line = node.line;
    var ignore = node.neg || notLast, st;
    if (ignore) this.noerr++;
    try {
      st = node.cmds.length === 1 ? this.execCmd(node.cmds[0], io) : this.execPipeline(node, io);
    } finally { if (ignore) this.noerr--; }
    if (node.neg) st = st === 0 ? 1 : 0;
    this.status = st;
    if (st !== 0 && !ignore && this.noerr === 0 && !(node.cmds.length === 1 && this.isCompound(node.cmds[0]))) {
      this.runTrap('ERR', io);
      if (this.opts.e) throw new Signal('exit', st);
    }
    return st;
  };
  Shell.prototype.isCompound = function (c) { return c.t !== 'simple' && c.t !== 'func'; };
  Shell.prototype.execPipeline = function (node, io) {
    var n = node.cmds.length, inStream = io.in, statuses = [];
    for (var i = 0; i < n; i++) {
      var last = i === n - 1, outW = last ? io.out : new Buf();
      var errW = (i + 1 < n && node.pipeErr[i + 1]) ? outW : io.err;
      var sub = (last && this.shopt.lastpipe) ? this : this.fork();
      var st;
      try { st = sub.execCmd(node.cmds[i], { in: inStream, out: outW, err: errW }); }
      catch (e) {
        if (e instanceof Signal && (e.kind === 'exit' || e.kind === 'return')) st = e.n;
        else if (e instanceof Signal && (e.kind === 'break' || e.kind === 'continue')) st = 0;
        else if (e instanceof Fail) { io.err.w(this.prefix() + e.msg + '\n'); st = e.status; }
        else throw e;
      }
      statuses.push(st);
      inStream = last ? null : new Stream(outW.s);
    }
    this.pipeStatus = statuses;
    this.vars.PIPESTATUS = { kind: 'a', v: statuses.map(String), exp: false };
    if (this.opts.pipefail) { for (var k = statuses.length - 1; k >= 0; k--) if (statuses[k] !== 0) return statuses[k]; return 0; }
    return statuses[statuses.length - 1];
  };

  Shell.prototype.execCmd = function (c, io) {
    this.tick();
    switch (c.t) {
      case 'simple': return this.execSimple(c, io);
      case 'func': this.funcs[c.name] = c.body; return 0;
      default: break;
    }
    this.line = c.line || this.line;
    var ctx = this.openRedirs(c.redirs, io);
    if (!ctx) return 1;
    try {
      switch (c.t) {
        case 'group': return this.execSeq(c.body, ctx.io);
        case 'subshell': {
          var sub = this.fork(), st;
          try { st = sub.execSeq(c.body, ctx.io); } catch (e) { if (e instanceof Signal && (e.kind === 'exit' || e.kind === 'return')) st = e.n; else throw e; }
          try { sub.runTrap('EXIT', ctx.io); } catch (e2) { if (!(e2 instanceof Signal)) throw e2; }
          return st;
        }
        case 'if': return this.execIf(c, ctx.io);
        case 'for': return this.execFor(c, ctx.io);
        case 'cfor': return this.execCFor(c, ctx.io);
        case 'while': return this.execWhile(c, ctx.io);
        case 'case': return this.execCase(c, ctx.io);
        case 'arith': return this.execArith(c, ctx.io);
        case 'dbrack': return this.execDbrack(c, ctx.io);
      }
      throw new Error('unknown node ' + c.t);
    } catch (e) {
      if (e instanceof Fail && !e.isFatal) { this.complain(ctx.io, e.msg); return e.status; }
      throw e;
    } finally { ctx.close(); }
  };

  Shell.prototype.cond = function (seq, io) { this.noerr++; try { return this.execSeq(seq, io); } finally { this.noerr--; } };
  Shell.prototype.execIf = function (c, io) {
    for (var i = 0; i < c.conds.length; i++) if (this.cond(c.conds[i].cond, io) === 0) return this.execSeq(c.conds[i].body, io);
    if (c.els) return this.execSeq(c.els, io);
    return 0;
  };
  Shell.prototype.loopBody = function (body, io) { // returns 'break' | 'continue' | null
    try { this.execSeq(body, io); }
    catch (e) {
      if (e instanceof Signal && (e.kind === 'break' || e.kind === 'continue')) {
        this.status = 0;
        if (e.n > 1) { e.n--; throw e; }
        return e.kind;
      }
      throw e;
    }
    return null;
  };
  Shell.prototype.execFor = function (c, io) {
    var items = [], self = this;
    if (c.words === null) items = this.pos.slice();
    else c.words.forEach(function (w) { self.expandFields(w, io).forEach(function (x) { items.push(x); }); });
    var st = 0;
    for (var i = 0; i < items.length; i++) {
      this.tick(); this.set(c.name, items[i]);
      var r = this.loopBody(c.body, io);
      st = this.status;
      if (r === 'break') break;
    }
    return st;
  };
  Shell.prototype.execCFor = function (c, io) {
    this.curIo = io;
    var st = 0;
    this.arith(c.init);
    for (;;) {
      this.tick();
      if (c.cond.trim() !== '' && this.arith(c.cond) === 0) break;
      var r = this.loopBody(c.body, io);
      st = this.status;
      if (r === 'break') break;
      this.arith(c.step);
    }
    return st;
  };
  Shell.prototype.execWhile = function (c, io) {
    var st = 0;
    for (;;) {
      this.tick();
      var cs = this.cond(c.cond, io);
      if (c.until ? cs === 0 : cs !== 0) break;
      var r = this.loopBody(c.body, io);
      st = this.status;
      if (r === 'break') break;
    }
    return st;
  };
  Shell.prototype.execCase = function (c, io) {
    var w = this.expandStr(c.word, io), nocase = this.shopt.nocasematch;
    for (var i = 0; i < c.items.length; i++) {
      var it = c.items[i];
      for (var k = 0; k < it.pats.length; k++) {
        if (R.globRe(this.expandPat(it.pats[k], io), nocase ? 'i' : '').test(w)) return this.execSeq(it.body, io);
      }
    }
    return 0;
  };
  Shell.prototype.execArith = function (c, io) {
    this.curIo = io;
    return this.arith(c.expr) !== 0 ? 0 : 1;
  };

  // [[ ]]
  Shell.prototype.execDbrack = function (c, io) {
    var self = this, toks = c.toks, p = 0;
    function isOp(v) { return toks[p] && toks[p].k === 'op' && toks[p].v === v; }
    function word() { var t = toks[p++]; if (!t || t.k !== 'word') throw new Fail('syntax error in conditional expression', 2); return t; }
    function or() { var v = and(); while (isOp('||')) { p++; var r = and(); v = v || r; } return v; }
    function and() { var v = not(); while (isOp('&&')) { p++; var r = not(); v = v && r; } return v; }
    function not() { if (toks[p] && toks[p].k === 'word' && toks[p].v === '!' && !toks[p].q) { p++; return !not(); } return primary(); }
    function primary() {
      if (isOp('(')) { p++; var v = or(); if (!isOp(')')) throw new Fail('syntax error in conditional expression', 2); p++; return v; }
      var t = toks[p];
      if (t && t.k === 'word' && /^-[a-zA-Z]$/.test(t.v) && !t.q && toks[p + 1] && toks[p + 1].k === 'word' && !(toks[p + 2] && toks[p + 2].k === 'word' && !isBinW(toks[p + 1].v) && false)) {
        // unary only when followed by an operand and not by a binary operator
        var nx2 = toks[p + 2];
        if (!(nx2 && ((nx2.k === 'word' && /^(==|=|!=|=~|-eq|-ne|-lt|-le|-gt|-ge|-nt|-ot|-ef)$/.test(nx2.v)) || (nx2.k === 'op' && (nx2.v === '<' || nx2.v === '>'))))) {
          p++; var operand = self.expandStr(word().v, io);
          return self.unaryTest(t.v, operand);
        }
      }
      var left = word(), lv = self.expandStr(left.v, io), op = toks[p];
      if (op && ((op.k === 'word' && /^(==|=|!=|=~|-eq|-ne|-lt|-le|-gt|-ge|-nt|-ot|-ef)$/.test(op.v)) || (op.k === 'op' && (op.v === '<' || op.v === '>')))) {
        p++;
        var rt = word(), o = op.v;
        if (o === '==' || o === '=' || o === '!=') {
          var pat = self.expandPat(rt.v, io), hit = R.globRe(pat, self.shopt.nocasematch ? 'i' : '').test(lv);
          return o === '!=' ? !hit : hit;
        }
        if (o === '=~') {
          var frags = self.expandFrags(rt.v, io), src = frags.map(function (f) { return f.t === 'q' ? f.s.replace(/[\\^$.*+?()[\]{}|]/g, '\\$&') : f.s; }).join('');
          var re, m;
          try { re = R.re(src, 'ere'); } catch (e) { throw new Fail('syntax error in conditional expression: invalid regular expression', 2); }
          m = re.exec(lv);
          self.vars.BASH_REMATCH = { kind: 'a', v: m ? Array.prototype.slice.call(m).map(function (x) { return x === undefined ? '' : x; }) : [], exp: false };
          return !!m;
        }
        var rv = self.expandStr(rt.v, io);
        if (o === '<') return lv < rv;
        if (o === '>') return lv > rv;
        return self.binaryTest(o, lv, rv);
      }
      return lv !== '';
    }
    function isBinW(v) { return /^(==|=|!=|=~)$/.test(v); }
    this.curIo = io;
    try {
      var r = or();
      if (p < toks.length) throw new Fail('syntax error in conditional expression: unexpected token', 2);
      return r ? 0 : 1;
    } catch (e) {
      if (e instanceof Fail && e.status === 2) { this.complain(io, e.msg); return 2; }
      throw e;
    }
  };

  Shell.prototype.fileMode = function (path) { var n = this.vfs.get(V.resolve(this.cwd, path), true); return n; };
  Shell.prototype.unaryTest = function (op, a) {
    var n;
    switch (op) {
      case '-n': return a !== '';
      case '-z': return a === '';
      case '-e': case '-a': return this.vfs.exists(V.resolve(this.cwd, a));
      case '-f': return this.vfs.isFile(V.resolve(this.cwd, a));
      case '-d': return this.vfs.isDir(V.resolve(this.cwd, a));
      case '-L': case '-h': n = this.vfs.get(V.resolve(this.cwd, a), false); return !!n && n.t === 'l';
      case '-s': n = this.fileMode(a); return !!n && n.t === 'f' && this.vfs.size(n) > 0;
      case '-r': n = this.fileMode(a); return !!n && (n.mode & 0x100) !== 0;
      case '-w': n = this.fileMode(a); return !!n && (n.mode & 0x80) !== 0;
      case '-x': n = this.fileMode(a); return !!n && (n.t === 'd' || (n.mode & 0x40) !== 0);
      case '-v': return this.isSet(a);
      case '-t': return false;
      case '-p': case '-S': case '-b': case '-c': return false;
      case '-O': case '-G': return this.vfs.exists(V.resolve(this.cwd, a));
    }
    throw new Fail('conditional binary operator expected', 2);
  };
  Shell.prototype.binaryTest = function (op, a, b) {
    var self = this;
    if (/^-(eq|ne|lt|le|gt|ge)$/.test(op)) {
      var x = this.intArg(a), y = this.intArg(b);
      switch (op) { case '-eq': return x === y; case '-ne': return x !== y; case '-lt': return x < y; case '-le': return x <= y; case '-gt': return x > y; default: return x >= y; }
    }
    var na = this.fileMode(a), nb = this.fileMode(b);
    switch (op) {
      case '-nt': return !!na && (!nb || na.mtime > nb.mtime);
      case '-ot': return !!nb && (!na || na.mtime < nb.mtime);
      case '-ef': return V.resolve(self.cwd, a) === V.resolve(self.cwd, b);
    }
    throw new Fail('unknown operator ' + op, 2);
  };
  Shell.prototype.intArg = function (s) {
    if (!/^\s*[-+]?[0-9]+\s*$/.test(s)) throw new Fail(s + ': integer expression expected', 2);
    return parseInt(s, 10);
  };

  // ——— Redirections ———
  Shell.prototype.openOut = function (path, append, io) {
    var full = V.resolve(this.cwd, path);
    if (full === '/dev/null' || full === '/dev/tty') return new Null();
    if (full === '/dev/stdout') return io.out;
    if (full === '/dev/stderr') return io.err;
    var m = /^\/dev\/fd\/([0-9]+)$/.exec(full);
    if (m && this.fds[m[1]] && this.fds[m[1]].w) return this.fds[m[1]].w;
    if (!append) this.vfs.write(full, '', false); else if (!this.vfs.exists(full)) this.vfs.write(full, '', false);
    var w = new FileOut(this, full, true); w.path = full;
    return w;
  };
  Shell.prototype.openRedirs = function (redirs, io) {
    if (!redirs || !redirs.length) return { io: io, close: function () { } };
    var nio = { in: io.in, out: io.out, err: io.err }, self = this, writers = [];
    function fdw(n) {
      if (n === 1) return nio.out; if (n === 2) return nio.err;
      if (self.fds[n] && self.fds[n].w) return self.fds[n].w;
      throw new Fail(n + ': Bad file descriptor', 1);
    }
    function target(r) { var f = self.expandFields(r.target, io); if (f.length !== 1) throw new Fail(r.target + ': ambiguous redirect', 1); return f[0]; }
    function setW(fd, w) { if (fd === 1) nio.out = w; else if (fd === 2) nio.err = w; else self.fds[fd] = { w: w, path: w.path }; }
    try {
      for (var i = 0; i < redirs.length; i++) {
        var r = redirs[i], fd = r.fd !== null ? parseInt(r.fd, 10) : (/^</.test(r.op) ? 0 : 1);
        if (r.op === '<<' || r.op === '<<-') {
          var body = r.hd.quoted ? r.hd.body : this.expandHeredoc(r.hd.body, io);
          nio.in = new Stream(body); continue;
        }
        if (r.op === '<<<') { nio.in = new Stream(this.expandStr(r.target, io) + '\n'); continue; }
        if (r.op === '<') {
          var p = target(r), full = V.resolve(this.cwd, p), data;
          if (full === '/dev/null') data = ''; else if (full === '/dev/stdin') data = io.in ? io.in.readAll() : '';
          else { try { data = this.vfs.read(full); } catch (e) { throw new Fail(p + ': ' + (e.message || 'No such file or directory'), 1); } }
          var st = new Stream(data);
          if (fd === 0) nio.in = st; else this.fds[fd] = { r: st };
          continue;
        }
        if (r.op === '>' || r.op === '>|' || r.op === '>>') {
          var pp = target(r), w;
          try { w = this.openOut(pp, r.op === '>>', io); } catch (e2) { throw new Fail(pp + ': ' + (e2.message || 'cannot create'), 1); }
          if (w instanceof FileOut) writers.push(w);
          setW(fd, w); continue;
        }
        if (r.op === '&>' || r.op === '&>>') {
          var p3 = target(r), w3;
          try { w3 = this.openOut(p3, r.op === '&>>', io); } catch (e3) { throw new Fail(p3 + ': ' + (e3.message || 'cannot create'), 1); }
          if (w3 instanceof FileOut) writers.push(w3);
          nio.out = w3; nio.err = w3; continue;
        }
        if (r.op === '>&' || r.op === '<&') {
          var tg = this.expandStr(r.target, io);
          if (tg === '-') { if (fd >= 3) delete this.fds[fd]; continue; }
          if (/^[0-9]+$/.test(tg)) {
            var n = parseInt(tg, 10);
            if (r.op === '<&') { var src = n === 0 ? nio.in : (this.fds[n] && this.fds[n].r); if (fd === 0) nio.in = src; else this.fds[fd] = { r: src }; }
            else setW(fd, fdw(n));
            continue;
          }
          var w4 = this.openOut(tg, false, io); nio.out = w4; nio.err = w4; continue;
        }
      }
    } catch (e) {
      if (e instanceof Fail) { this.complain(io, e.msg); return null; }
      throw e;
    }
    return {
      io: nio,
      close: function () {
        var bad = false;
        writers.forEach(function (w) { if (w.failed) { io.err.w(self.prefix() + 'write error: ' + (w.failed === 'ENOSPC' ? 'No space left on device' : w.failed) + '\n'); bad = true; } });
        return bad;
      }
    };
  };

  // ——— Simple commands ———
  Shell.prototype.assignValue = function (a, io) {
    var raw = a.value;
    if (raw[0] === '(' && raw[raw.length - 1] === ')' && a.idx === null) {
      var inner = raw.slice(1, -1), toks = P.lex(inner).filter(function (t) { return t.k === 'word'; }), self = this, out = [], assoc = null;
      toks.forEach(function (t) {
        var m = /^\[([^\]]*)\]=([\s\S]*)$/.exec(t.v);
        if (m) { (assoc = assoc || []).push([self.expandStr(m[1], io), self.expandStr(m[2], io)]); return; }
        self.expandFields(t.v, io).forEach(function (x) { out.push(x); });
      });
      return { list: out, pairs: assoc };
    }
    return { str: this.expandStr(raw, io, { assign: true }) };
  };
  Shell.prototype.applyAssign = function (a, val) {
    if (val.list !== undefined) {
      this.check(a.name);
      var cur = this.vars[a.name];
      if (val.pairs) {
        var map = (cur && cur.kind === 'A' && a.append) ? cur.v : Object.create(null);
        val.pairs.forEach(function (kv) { map[kv[0]] = kv[1]; });
        this.vars[a.name] = { kind: 'A', v: map, exp: cur ? cur.exp : false };
      } else if (cur && cur.kind === 'A') {
        var m2 = cur.v, ls = val.list;
        for (var i = 0; i + 1 < ls.length; i += 2) m2[ls[i]] = ls[i + 1];
      } else {
        var base = (a.append && cur && cur.kind === 'a') ? cur.v.slice() : [];
        this.vars[a.name] = { kind: 'a', v: base.concat(val.list), exp: cur ? cur.exp : false };
      }
      return;
    }
    if (a.idx !== null) { this.setIndex(a.name, a.idx, val.str, a.append); return; }
    if (a.append) {
      var v = this.vars[a.name];
      if (v && v.int) { this.set(a.name, String(this.arith(String(this.get(a.name) || 0)) + this.arith(val.str))); return; }
      if (v && v.kind === 'a') { v.v.push(val.str); return; }
      this.set(a.name, (this.get(a.name) || '') + val.str); return;
    }
    this.set(a.name, val.str);
  };

  Shell.prototype.xtrace = function (words, io) {
    var q = function (w) { return w === '' ? "''" : (/^[A-Za-z0-9_@%+=:,.\/-]+$/.test(w) ? w : "'" + w.replace(/'/g, "'\\''") + "'"); };
    io.err.w('+ ' + words.map(q).join(' ') + '\n');
  };

  Shell.prototype.execSimple = function (node, io) {
    this.tick();
    this.line = node.line; this.curIo = io;
    var words = [], self = this, st;
    this.subStatus = 0;
    try {
      node.words.forEach(function (raw) { self.expandFields(raw, io).forEach(function (x) { words.push(x); }); });
      var vals = node.assigns.map(function (a) { return self.assignValue(a, io); });
    } catch (e) {
      if (e instanceof Fail) {
        io.err.w(this.prefix() + e.msg + '\n');
        if (e.isFatal && !this.interactive) throw new Signal('exit', e.status);
        return e.status;
      }
      throw e;
    }
    if (!words.length) {
      var ctx0 = this.openRedirs(node.redirs, io);
      if (!ctx0) return 1;
      try {
        node.assigns.forEach(function (a, i) { self.applyAssign(a, vals[i]); });
      } catch (e2) {
        if (e2 instanceof Fail) { this.complain(io, e2.msg); ctx0.close(); return e2.status; }
        throw e2;
      }
      ctx0.close();
      return this.subStatus;
    }
    if (words[0] === 'exec' && words.length === 1) { // exec with redirections only: they stay for the rest of the shell
      var cx = this.openRedirs(node.redirs, io);
      if (!cx) return 1;
      io.in = cx.io.in; io.out = cx.io.out; io.err = cx.io.err;
      return 0;
    }
    var ctx = this.openRedirs(node.redirs, io);
    if (!ctx) return 1;
    // temporary assignments, visible to the command only
    var saved = [];
    try {
      node.assigns.forEach(function (a, i) {
        saved.push([a.name, self.vars[a.name]]);
        if (self.vars[a.name]) self.vars[a.name] = Object.assign({}, self.vars[a.name], { v: self.vars[a.name].kind === 'a' ? self.vars[a.name].v.slice() : self.vars[a.name].v });
        self.applyAssign(a, vals[i]);
        if (self.vars[a.name]) self.vars[a.name].exp = true;
      });
      if (this.opts.x) this.xtrace(words, ctx.io);
      st = this.dispatch(words, ctx.io);
    } catch (e3) {
      if (e3 instanceof Fail) {
        ctx.io.err.w(this.prefix() + e3.msg + '\n');
        if (e3.isFatal && !this.interactive) { ctx.close(); throw new Signal('exit', e3.status); }
        st = e3.status;
      } else throw e3;
    } finally {
      saved.forEach(function (s) { if (s[1] === undefined) delete self.vars[s[0]]; else self.vars[s[0]] = s[1]; });
    }
    if (ctx.close() && st === 0) st = 1;
    return st;
  };

  Shell.prototype.dispatch = function (words, io) {
    var name = words[0], args = words.slice(1);
    if (this.funcs[name]) return this.callFunc(name, args, io);
    var b = Shell.builtins[name];
    if (b) return b.call(this, args, io);
    var u = root.RCUtils && root.RCUtils.get(name);
    if (u) return u(this, args, io);
    return this.runPath(name, args, io);
  };
  /** Runs an argument vector as the shell would, without parsing (xargs, find -exec, timeout…). */
  Shell.prototype.runArgs = function (words, io) { return this.dispatch(words, io); };

  Shell.prototype.callFunc = function (name, args, io) {
    if (++this.callDepth > 150) { this.callDepth--; throw new Fail('maximum function nesting level exceeded', 1).fatal(); }
    var savedPos = this.pos, frame = { locals: Object.create(null), name: name }, st;
    this.pos = args; this.frames.push(frame);
    try { st = this.execCmd(this.funcs[name], io); }
    catch (e) { if (e instanceof Signal && e.kind === 'return') st = e.n; else throw e; }
    finally {
      var self = this;
      Object.keys(frame.locals).forEach(function (k) { if (frame.locals[k] === undefined) delete self.vars[k]; else self.vars[k] = frame.locals[k]; });
      this.pos = savedPos; this.frames.pop(); this.callDepth--;
    }
    return st;
  };

  Shell.prototype.findInPath = function (name) {
    var path = this.get('PATH') || '', dirs = path.split(':'), self = this;
    for (var i = 0; i < dirs.length; i++) {
      var p = V.resolve(this.cwd, (dirs[i] || '.') + '/' + name);
      if (self.vfs.isFile(p)) return p;
    }
    return null;
  };

  /** Executes a script file of the machine, in a new shell (a child process). */
  Shell.prototype.runScriptFile = function (fullPath, shown, args, io, viaBash) {
    var src;
    try { src = this.vfs.read(fullPath); } catch (e) { io.err.w((viaBash ? 'bash: ' + shown : this.prefix() + shown) + ': ' + (e.message || 'No such file or directory') + '\n'); return 127; }
    var child = new Shell(this.m);
    var env = this.exported();
    Object.keys(env).forEach(function (k) { child.vars[k] = { kind: 's', v: env[k], exp: true }; });
    child.cwd = this.cwd; child.vars.PWD.v = this.cwd; child.name0 = shown; child.pos = args.slice(); child.depth = this.depth + 1;
    child.rootId = this.m.nextPid();
    if (child.depth > 30) { io.err.w('bash: fork: retry: Resource temporarily unavailable\n'); return 254; }
    if (/^#!.*python/.test(src)) { io.err.w(shown + ': python is not installed on this machine\n'); return 127; }
    return child.runTop(src, { in: io.in, out: io.out, err: io.err });
  };

  Shell.prototype.runPath = function (name, args, io) {
    var full;
    if (name.indexOf('/') !== -1) {
      full = V.resolve(this.cwd, name);
      var node = this.vfs.get(full, true);
      if (!node) { this.complain(io, name + ': No such file or directory'); return 127; }
      if (node.t === 'd') { this.complain(io, name + ': Is a directory'); return 126; }
      if (!(node.mode & 0x40)) { this.complain(io, name + ': Permission denied'); return 126; }
      return this.runScriptFile(full, name, args, io, false);
    }
    full = this.findInPath(name);
    if (!full) { this.complain(io, name + ': command not found'); return 127; }
    var nd = this.vfs.get(full, true);
    if (!(nd.mode & 0x40)) { this.complain(io, name + ': Permission denied'); return 126; }
    return this.runScriptFile(full, name, args, io, false);
  };

  Shell.builtins = Object.create(null);
  root.RCShell = { Shell: Shell, Signal: Signal, Fail: Fail, Buf: Buf, Null: Null, Stream: Stream, FileOut: FileOut, braceExpand: braceExpand, ansiC: ansiC, removePattern: removePattern };
})(typeof globalThis !== 'undefined' ? globalThis : window);
