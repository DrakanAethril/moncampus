/* Radio-Comète — the virtual file system of one machine.

   Files, directories and symbolic links, modes, modification times (epoch seconds), and mount
   points with a capacity, so that « disk full » is a state a script can meet. Paths are always
   absolute inside; callers resolve against a working directory with resolve(). */
(function (root) {
  'use strict';

  var NOW = Date.UTC(2026, 2, 12, 10, 20, 0) / 1000; // the day the game is set in: Thursday 12 March 2026, 10:20
  var DAY = 86400;

  function VfsError(code, msg, path) { var e = new Error(msg); e.code = code; e.path = path; return e; }
  var MSG = { ENOENT: 'No such file or directory', EEXIST: 'File exists', ENOTDIR: 'Not a directory', EISDIR: 'Is a directory', ENOTEMPTY: 'Directory not empty', ENOSPC: 'No space left on device', EACCES: 'Permission denied', ELOOP: 'Too many levels of symbolic links' };
  function err(code, path) { return VfsError(code, MSG[code], path); }

  function Vfs() {
    this.nodes = { '/': { t: 'd', mode: 493, mtime: NOW - 400 * DAY, owner: 'root' } };
    this.mounts = []; // { path, cap } — cap in bytes
    this.now = NOW;
  }

  function normalize(p) {
    var parts = [], out = String(p).split('/');
    for (var i = 0; i < out.length; i++) {
      var s = out[i];
      if (s === '' || s === '.') continue;
      if (s === '..') parts.pop(); else parts.push(s);
    }
    return '/' + parts.join('/');
  }
  function resolve(cwd, p) { return p.charAt(0) === '/' ? normalize(p) : normalize(cwd + '/' + p); }
  function dirname(p) { var n = normalize(p); if (n === '/') return '/'; var i = n.lastIndexOf('/'); return i === 0 ? '/' : n.slice(0, i); }
  function basename(p) { var n = normalize(p); return n === '/' ? '/' : n.slice(n.lastIndexOf('/') + 1); }

  Vfs.prototype.clone = function () {
    var v = new Vfs(), k;
    for (k in this.nodes) { var n = this.nodes[k], c = {}; for (var f in n) c[f] = n[f]; v.nodes[k] = c; }
    v.mounts = this.mounts.map(function (m) { return { path: m.path, cap: m.cap }; });
    v.now = this.now;
    return v;
  };

  /** Follows symbolic links on the way (not on the last component unless follow). */
  Vfs.prototype.real = function (p, follow, depth) {
    depth = depth || 0;
    if (depth > 20) throw err('ELOOP', p);
    var n = normalize(p), parts = n.split('/').filter(Boolean), cur = '';
    for (var i = 0; i < parts.length; i++) {
      cur += '/' + parts[i];
      var node = this.nodes[cur];
      if (node && node.t === 'l' && (i < parts.length - 1 || follow)) {
        var target = resolve(dirname(cur), node.target);
        var rest = parts.slice(i + 1).join('/');
        return this.real(rest ? target + '/' + rest : target, follow, depth + 1);
      }
    }
    return n;
  };
  Vfs.prototype.get = function (p, follow) { var r = this.real(p, follow !== false); return this.nodes[r] || null; };
  Vfs.prototype.exists = function (p) { return !!this.get(p, true); };
  Vfs.prototype.lexists = function (p) { return !!this.get(p, false); };
  Vfs.prototype.isDir = function (p) { var n = this.get(p, true); return !!n && n.t === 'd'; };
  Vfs.prototype.isFile = function (p) { var n = this.get(p, true); return !!n && n.t === 'f'; };

  Vfs.prototype.mount = function (path, cap) { this.mounts.push({ path: normalize(path), cap: cap }); return this; };
  Vfs.prototype.mountOf = function (p) {
    var best = null, n = normalize(p);
    this.mounts.forEach(function (m) { if ((n === m.path || n.indexOf(m.path + '/') === 0) && (!best || m.path.length > best.path.length)) best = m; });
    return best;
  };
  Vfs.prototype.used = function (m) {
    var total = 0, prefix = m.path === '/' ? '/' : m.path + '/';
    for (var k in this.nodes) if ((k === m.path || k.indexOf(prefix) === 0) && this.nodes[k].t === 'f' && !this.nodes[k].sparse) total += this.size(this.nodes[k]);
    for (var j in this.nodes) if ((j === m.path || j.indexOf(prefix) === 0) && this.nodes[j].t === 'f' && this.nodes[j].sparse) total += this.nodes[j].sparse;
    return total;
  };
  Vfs.prototype.size = function (node) { return node.t === 'f' ? (node.sparse || byteLength(node.data)) : (node.t === 'l' ? node.target.length : 4096); };
  function byteLength(s) { var n = 0; for (var i = 0; i < s.length; i++) { var c = s.charCodeAt(i); n += c < 0x80 ? 1 : (c < 0x800 ? 2 : 3); } return n; }

  Vfs.prototype.mkdir = function (p, parents) {
    var n = normalize(p);
    if (this.lexists(n)) { if (parents && this.isDir(n)) return; throw err('EEXIST', p); }
    var parent = dirname(n);
    if (!this.isDir(parent)) {
      if (!parents) throw err(this.lexists(parent) ? 'ENOTDIR' : 'ENOENT', p);
      this.mkdir(parent, true);
    }
    this.nodes[n] = { t: 'd', mode: 493, mtime: this.now, owner: 'admin' };
  };
  /** Writes a whole file. Creates it (mode 644) or keeps its mode. */
  Vfs.prototype.write = function (p, data, append) {
    var r = this.real(p, true), node = this.nodes[r];
    if (node && node.t === 'd') throw err('EISDIR', p);
    var parent = dirname(r);
    if (!this.isDir(parent)) throw err(this.lexists(parent) ? 'ENOTDIR' : 'ENOENT', p);
    var next = (append && node ? node.data : '') + data;
    var m = this.mountOf(r);
    if (m) {
      var grow = byteLength(next) - (node ? this.size(node) : 0);
      if (grow > 0 && this.used(m) + grow > m.cap) throw err('ENOSPC', p);
    }
    if (node) { node.data = next; node.mtime = this.now; delete node.sparse; }
    else this.nodes[r] = { t: 'f', data: next, mode: 420, mtime: this.now, owner: 'admin' };
  };
  Vfs.prototype.read = function (p) {
    var node = this.get(p, true);
    if (!node) throw err('ENOENT', p);
    if (node.t === 'd') throw err('EISDIR', p);
    return node.data;
  };
  Vfs.prototype.list = function (p) {
    var n = this.real(p, true);
    if (!this.nodes[n]) throw err('ENOENT', p);
    if (this.nodes[n].t !== 'd') throw err('ENOTDIR', p);
    var prefix = n === '/' ? '/' : n + '/', out = [];
    for (var k in this.nodes) if (k !== n && k.indexOf(prefix) === 0 && k.indexOf('/', prefix.length) === -1) out.push(k.slice(prefix.length));
    return out.sort();
  };
  Vfs.prototype.symlink = function (target, p) {
    var n = normalize(p);
    if (this.lexists(n)) throw err('EEXIST', p);
    this.nodes[n] = { t: 'l', target: target, mode: 511, mtime: this.now, owner: 'admin' };
  };
  Vfs.prototype.remove = function (p, recursive) {
    var n = this.real(p, false), node = this.nodes[n];
    if (!node) throw err('ENOENT', p);
    if (node.t === 'd') {
      var kids = this.list(n);
      if (kids.length && !recursive) throw err('ENOTEMPTY', p);
      var prefix = n === '/' ? '/' : n + '/';
      for (var k in this.nodes) if (k.indexOf(prefix) === 0) delete this.nodes[k];
    }
    if (n !== '/') delete this.nodes[n];
  };
  Vfs.prototype.rename = function (from, to) {
    var a = this.real(from, false), b = normalize(to);
    if (!this.nodes[a]) throw err('ENOENT', from);
    if (this.isDir(b)) b = normalize(b + '/' + basename(a));
    if (!this.isDir(dirname(b))) throw err('ENOENT', to);
    var moved = {}, k, prefix = a + '/';
    for (k in this.nodes) if (k === a || k.indexOf(prefix) === 0) moved[k] = this.nodes[k];
    for (k in moved) delete this.nodes[k];
    for (k in moved) this.nodes[b + k.slice(a.length)] = moved[k];
  };
  Vfs.prototype.copy = function (from, to, recursive) {
    var a = this.real(from, true), node = this.nodes[a];
    if (!node) throw err('ENOENT', from);
    var b = normalize(to);
    if (this.isDir(b)) b = normalize(b + '/' + basename(a));
    if (node.t === 'd') {
      if (!recursive) throw err('EISDIR', from);
      this.mkdir(b, true);
      var self = this;
      this.list(a).forEach(function (c) { self.copy(a + '/' + c, b + '/' + c, true); });
      return;
    }
    this.write(b, node.data);
    var dn = this.nodes[this.real(b, true)];
    dn.mode = node.mode;
    if (node.sparse) { dn.sparse = node.sparse; dn.seed = node.seed; var m = this.mountOf(b); if (m && this.used(m) > m.cap) { delete this.nodes[this.real(b, true)]; throw err('ENOSPC', b); } }
  };
  /** Every path under p (depth-first, p included), as the find command walks them. */
  Vfs.prototype.walk = function (p) {
    var out = [], self = this;
    (function rec(path) {
      out.push(path);
      var node = self.nodes[self.real(path, false)];
      if (node && node.t === 'd') self.list(path).forEach(function (c) { rec(path === '/' ? '/' + c : path + '/' + c); });
    })(normalize(p));
    return out;
  };
  Vfs.prototype.utimes = function (p, t) { var n = this.get(p, true); if (n) n.mtime = t; };

  // Builders, to write the world's images compactly.
  Vfs.prototype.file = function (p, data, opts) {
    var n = normalize(p);
    if (!this.isDir(dirname(n))) this.mkdir(dirname(n), true);
    this.nodes[n] = { t: 'f', data: data, mode: 420, mtime: this.now - DAY, owner: 'admin' };
    if (opts) for (var k in opts) this.nodes[n][k] = opts[k];
    return this;
  };
  Vfs.prototype.dir = function (p) { if (!this.isDir(p)) this.mkdir(p, true); return this; };
  /** A file with no content stored, but a size: for the audio archives. */
  Vfs.prototype.big = function (p, bytes, ageDays, opts) {
    this.file(p, '', { sparse: bytes, mtime: this.now - ageDays * DAY });
    if (opts) for (var k in opts) this.nodes[normalize(p)][k] = opts[k];
    return this;
  };

  root.RCVfs = { Vfs: Vfs, normalize: normalize, resolve: resolve, dirname: dirname, basename: basename, byteLength: byteLength, NOW: NOW, DAY: DAY };
})(typeof globalThis !== 'undefined' ? globalThis : window);
