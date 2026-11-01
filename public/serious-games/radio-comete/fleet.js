/* Radio-Comète — machines and the fleet.

   A Machine is a virtual file system plus what happens on it: locks, mail, units, the state of its
   network side (reachable or not). run() starts a shell on it, as a script would, and returns what
   was printed. The Fleet is the set of machines the console can ssh to. */
(function (root) {
  'use strict';
  var V = root.RCVfs, S = root.RCShell;

  function Machine(name, vfs, opts) {
    this.name = name; this.vfs = vfs; this.home = '/home/admin'; this.locks = new Map(); this.rand = 1234; this.down = null;
    this.budgetMax = 600000; this.budget = this.budgetMax; this.pid = 100; this.fleet = null; this.mailDown = false; this.units = {};
    if (opts) for (var k in opts) this[k] = opts[k];
  }
  Machine.prototype.nextPid = function () { return ++this.pid; };
  Machine.prototype.shell = function () { return new S.Shell(this); };
  Machine.prototype.clone = function () {
    var m = new Machine(this.name, this.vfs.clone(), { home: this.home, down: this.down, mailDown: this.mailDown, budgetMax: this.budgetMax });
    m.units = JSON.parse(JSON.stringify(this.units));
    return m;
  };
  /** Runs a command line like « bash -c » on this machine, with the given streams. Returns the status. */
  Machine.prototype.exec = function (cmd, io) {
    var sh = this.shell(); sh.name0 = 'bash'; sh.line = 1;
    return sh.runTop(cmd, io);
  };
  /** Collects what a command prints: { status, out, err, all, ev }. */
  Machine.prototype.run = function (cmd, o) {
    o = o || {};
    this.budget = this.budgetMax;
    var ev = [], out = '', err = '';
    var io = { in: o.stdin !== undefined ? new S.Stream(o.stdin) : null, out: { w: function (s) { ev.push({ fd: 1, s: s }); out += s; } }, err: { w: function (s) { ev.push({ fd: 2, s: s }); err += s; } } };
    var sh = this.shell(); sh.name0 = o.name0 || 'bash'; sh.line = 1;
    if (o.cwd) { sh.cwd = o.cwd; sh.vars.PWD.v = o.cwd; }
    if (o.env) Object.keys(o.env).forEach(function (k) { sh.vars[k] = { kind: 's', v: o.env[k], exp: true }; });
    var st;
    try { st = sh.runTop(cmd, io); }
    catch (e) { if (e instanceof S.Signal && e.kind === 'timeout') { err += 'timeout: the script ran too long and was stopped (infinite loop?)\n'; ev.push({ fd: 2, s: 'timeout: the script ran too long and was stopped (infinite loop?)\n' }); st = 124; } else throw e; }
    return { status: st, out: out, err: err, all: ev.map(function (x) { return x.s; }).join(''), ev: ev };
  };
  /** A terminal session: one persistent shell (variables, directory) typed into. */
  Machine.prototype.session = function () {
    var self = this, sh = this.shell();
    return {
      sh: sh,
      cwd: function () { return sh.cwd; },
      run: function (line) {
        self.budget = self.budgetMax;
        var ev = [];
        var io = { in: null, out: { w: function (s) { ev.push({ fd: 1, s: s }); } }, err: { w: function (s) { ev.push({ fd: 2, s: s }); } } };
        var st;
        try { st = sh.runInteractive(line, io); } catch (e) { if (e instanceof S.Signal && e.kind === 'timeout') { ev.push({ fd: 2, s: 'bash: the command ran too long and was stopped (infinite loop?)\n' }); st = 124; } else throw e; }
        return { status: st, ev: ev };
      }
    };
  };

  function Fleet() { this.hosts = Object.create(null); }
  Fleet.prototype.add = function (m) { this.hosts[m.name] = m; m.fleet = this; return m; };
  Fleet.prototype.get = function (n) { return this.hosts[n] || null; };
  Fleet.prototype.clone = function () { var f = new Fleet(), self = this; Object.keys(this.hosts).forEach(function (k) { f.add(self.hosts[k].clone()); }); return f; };

  root.RCFleet = { Machine: Machine, Fleet: Fleet };
})(typeof globalThis !== 'undefined' ? globalThis : window);
