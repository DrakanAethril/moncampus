#!/usr/bin/env python3
"""A stand-in Proxmox VE API for the development stack.

There is no Proxmox in this compose file and there cannot be one: Proxmox VE is a bare-metal
hypervisor, not an image. What the console actually needs to be developed against is not a
hypervisor, it is *an endpoint that speaks /api2/json* - so that is what this serves, over TLS,
with a small fixed inventory that behaves: machines start, stop and reboot, tasks report a UPID
that later reports OK, and the wrong credentials get a 401.

Deliberately dependency-free (Python standard library only) and deliberately not clever. It exists
so that the connection test, the machine list, the power actions and the task polling can be
exercised end to end on a laptop; it is not a Proxmox emulator and must never be mistaken for one.
Anything the console asks for that is not implemented answers 501 with a message saying so, rather
than a plausible lie.

The certificate is generated at every start (see entrypoint.sh) and is self-signed, which makes
this the right place to exercise all four TLS modes - including the CA one, since the generated CA
is printed into /certs/ca.pem for pasting into the form.
"""

import http.server
import json
import os
import ssl
import time
import urllib.parse

REALM = "pve"
OPERATE_USER = "svc-moncampus"
OPERATE_TOKEN_NAME = "moncampus"
# Fixed on purpose: it goes into .env.dev.local.example and into the host form. A random secret
# would mean re-reading a container log every time the stack restarts.
OPERATE_TOKEN_SECRET = "1e5b4d2a-0c37-4f81-9a6e-7d3b2c8f5a10"
OPERATE_PASSWORD = "moncampus-dev"

PROVISION_USER = "svc-moncampus-provision"
PROVISION_TOKEN_NAME = "moncampus"
PROVISION_TOKEN_SECRET = "9f2c7a41-6b8d-4e05-83af-1c6d9e4b7205"
PROVISION_PASSWORD = "moncampus-dev-provision"

POOL = "moncampus"
VERSION = "8.3.2"

NODES = [
    {"node": "pve1", "status": "online", "maxcpu": 16, "cpu": 0.14, "maxmem": 68719476736, "mem": 30064771072, "uptime": 912345},
    {"node": "pve2", "status": "online", "maxcpu": 8, "cpu": 0.05, "maxmem": 34359738368, "mem": 8589934592, "uptime": 512345},
]

# vmid -> the row /cluster/resources hands back. Mutated in place by the power actions, which is
# the whole point: a start really does turn the row's status to "running".
GUESTS = {
    204: {"vmid": 204, "name": "srv-tp-04", "node": "pve1", "type": "qemu", "status": "running", "template": 0, "pool": POOL, "maxcpu": 2, "cpu": 0.031, "maxmem": 4294967296, "mem": 1073741824, "maxdisk": 34359738368, "uptime": 8123},
    205: {"vmid": 205, "name": "srv-tp-05", "node": "pve1", "type": "qemu", "status": "stopped", "template": 0, "pool": POOL, "maxcpu": 2, "cpu": 0.0, "maxmem": 4294967296, "mem": 0, "maxdisk": 34359738368},
    206: {"vmid": 206, "name": "srv-tp-06", "node": "pve2", "type": "qemu", "status": "running", "template": 0, "pool": POOL, "maxcpu": 4, "cpu": 0.22, "maxmem": 8589934592, "mem": 5368709120, "maxdisk": 68719476736, "uptime": 41231},
    210: {"vmid": 210, "name": "ct-web-01", "node": "pve1", "type": "lxc", "status": "running", "template": 0, "pool": POOL, "maxcpu": 1, "cpu": 0.01, "maxmem": 1073741824, "mem": 268435456, "maxdisk": 8589934592, "uptime": 122333},
    # Outside the declared VMID range on purpose: the scope guard has to have something to refuse.
    401: {"vmid": 401, "name": "pfsense-lab", "node": "pve2", "type": "qemu", "status": "running", "template": 0, "pool": None, "maxcpu": 2, "cpu": 0.09, "maxmem": 2147483648, "mem": 1073741824, "maxdisk": 21474836480, "uptime": 700000},
    900: {"vmid": 900, "name": "debian12-base", "node": "pve1", "type": "qemu", "status": "stopped", "template": 1, "pool": POOL, "maxcpu": 2, "cpu": 0.0, "maxmem": 2147483648, "mem": 0, "maxdisk": 17179869184},
    901: {"vmid": 901, "name": "debian12-lamp", "node": "pve1", "type": "qemu", "status": "stopped", "template": 1, "pool": POOL, "maxcpu": 2, "cpu": 0.0, "maxmem": 4294967296, "mem": 0, "maxdisk": 34359738368},
    902: {"vmid": 902, "name": "ubuntu2404-base", "node": "pve2", "type": "qemu", "status": "stopped", "template": 1, "pool": POOL, "maxcpu": 2, "cpu": 0.0, "maxmem": 2147483648, "mem": 0, "maxdisk": 17179869184},
}

# vmid -> its /config. Real shapes, including the ones that are easy to get wrong: ipconfig0 on a
# QEMU guest, net0 on an LXC one, a VLAN tag on one of them, and a guest left on DHCP.
CONFIGS = {
    204: {"name": "srv-tp-04", "cores": 2, "memory": 4096, "ide2": "local:cloudinit,media=cdrom", "net0": "virtio=02:4D:43:11:22:04,bridge=vmbr0", "ipconfig0": "ip=10.30.20.54/24,gw=10.30.20.1", "ciuser": "admin"},
    205: {"name": "srv-tp-05", "cores": 2, "memory": 4096, "ide2": "local:cloudinit,media=cdrom", "net0": "virtio=02:4D:43:11:22:05,bridge=vmbr0,tag=30", "ipconfig0": "ip=10.30.20.55/24,gw=10.30.20.1"},
    206: {"name": "srv-tp-06", "cores": 4, "memory": 8192, "net0": "virtio=02:4D:43:11:22:06,bridge=vmbr0", "ipconfig0": "ip=dhcp"},
    210: {"hostname": "ct-web-01", "cores": 1, "memory": 1024, "net0": "name=eth0,bridge=vmbr0,hwaddr=02:4D:43:11:22:10,ip=10.30.20.61/24,gw=10.30.20.1"},
    401: {"name": "pfsense-lab", "cores": 2, "memory": 2048, "net0": "virtio=02:4D:43:99:00:01,bridge=vmbr0", "ipconfig0": "ip=10.30.20.61/24,gw=10.30.20.1"},
    900: {"name": "debian12-base", "cores": 2, "memory": 2048, "ide2": "local:cloudinit,media=cdrom", "net0": "virtio=02:4D:43:00:09:00,bridge=vmbr0"},
    901: {"name": "debian12-lamp", "cores": 2, "memory": 4096, "ide2": "local:cloudinit,media=cdrom", "net0": "virtio=02:4D:43:00:09:01,bridge=vmbr0"},
    902: {"name": "ubuntu2404-base", "cores": 2, "memory": 2048, "ide2": "local:cloudinit,media=cdrom", "net0": "virtio=02:4D:43:00:09:02,bridge=vmbr0"},
}

STORAGES = {
    "pve1": [
        {"storage": "local", "type": "dir", "content": "iso,vztmpl,backup", "total": 107374182400, "used": 42949672960, "avail": 64424509440, "active": 1},
        {"storage": "local-lvm", "type": "lvmthin", "content": "images,rootdir", "total": 429496729600, "used": 214748364800, "avail": 214748364800, "active": 1},
    ],
    "pve2": [
        {"storage": "local", "type": "dir", "content": "iso,vztmpl,backup", "total": 107374182400, "used": 21474836480, "avail": 85899345920, "active": 1},
        {"storage": "local-zfs", "type": "zfspool", "content": "images,rootdir", "total": 858993459200, "used": 214748364800, "avail": 644245094400, "active": 1},
    ],
}

ISOS = {
    "pve1": [
        {"volid": "local:iso/debian-12.7.0-amd64-netinst.iso", "size": 660602880, "ctime": 1723000000, "format": "iso"},
        {"volid": "local:iso/ubuntu-24.04.1-live-server-amd64.iso", "size": 2724884480, "ctime": 1722000000, "format": "iso"},
        # A Windows ISO on purpose: there is no Windows template in the real fleet either, so the
        # "I reserve the address but cannot configure the machine" path is not an edge case here.
        {"volid": "local:iso/Win11_24H2_French_x64.iso", "size": 5825156096, "ctime": 1721000000, "format": "iso"},
    ],
    "pve2": [
        {"volid": "local:iso/pfSense-CE-2.7.2-RELEASE-amd64.iso", "size": 806354944, "ctime": 1720000000, "format": "iso"},
    ],
}

# upid -> when it was created. Every task reports "running" for a second and then "OK", so the
# Stimulus polling has something real to poll rather than an instantly-finished task.
TASKS = {}
TASK_DURATION_SECONDS = 2.0

TICKETS = {}


def now():
    return time.time()


def upid_for(node, kind, vmid, user):
    return "UPID:%s:0000ABCD:0000FFFF:%08X:%s:%s:%s@%s:" % (node, int(now()), kind, vmid, user, REALM)


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "pve-api-daemon/mock"

    # --- plumbing ---------------------------------------------------------------------------

    def log_message(self, fmt, *args):
        print("[proxmox-mock] %s - %s" % (self.address_string(), fmt % args), flush=True)

    def send_json(self, payload, status=200):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json;charset=UTF-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def send_data(self, data, status=200):
        self.send_json({"data": data}, status)

    def send_error_json(self, status, message):
        self.send_json({"data": None, "message": message}, status)

    def read_body(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length).decode() if length else ""

        return dict(urllib.parse.parse_qsl(raw))

    # --- authentication ---------------------------------------------------------------------

    def authenticate(self):
        """Answers the credential set in use, or None. Mirrors the two real modes exactly:
        a token needs no session and no CSRF header, a ticket needs both."""
        header = self.headers.get("Authorization", "")

        if header.startswith("PVEAPIToken="):
            value = header[len("PVEAPIToken="):]
            for user, name, secret in (
                (OPERATE_USER, OPERATE_TOKEN_NAME, OPERATE_TOKEN_SECRET),
                (PROVISION_USER, PROVISION_TOKEN_NAME, PROVISION_TOKEN_SECRET),
            ):
                if value == "%s@%s!%s=%s" % (user, REALM, name, secret):
                    return user

            return None

        cookie = self.headers.get("Cookie", "")
        ticket = None
        for part in cookie.split(";"):
            part = part.strip()
            if part.startswith("PVEAuthCookie="):
                ticket = part[len("PVEAuthCookie="):]

        if ticket is None or ticket not in TICKETS:
            return None

        user, csrf = TICKETS[ticket]

        # The trap this mock exists to reproduce: Proxmox refuses every non-GET made with a ticket
        # unless the CSRFPreventionToken header is present, and says nothing about CSRF when it
        # does. A client that forgets it works perfectly until the first write.
        if self.command != "GET" and self.headers.get("CSRFPreventionToken") != csrf:
            return None

        return user

    # --- routing ----------------------------------------------------------------------------

    def do_GET(self):
        self.route("GET")

    def do_POST(self):
        self.route("POST")

    def do_PUT(self):
        self.route("PUT")

    def do_DELETE(self):
        # The console never sends one, and this makes sure it stays that way: if a DELETE ever
        # appears in the logs of this mock, something has been added that must not exist.
        self.log_message("REFUSED DELETE %s - this application never destroys a machine", self.path)
        self.send_error_json(403, "Permission check failed (this mock grants no destroy privilege)")

    def route(self, method):
        parsed = urllib.parse.urlparse(self.path)
        query = dict(urllib.parse.parse_qsl(parsed.query))
        path = parsed.path

        if not path.startswith("/api2/json"):
            self.send_error_json(404, "Not found")
            return

        path = path[len("/api2/json"):] or "/"
        parts = [p for p in path.split("/") if p != ""]

        if method == "POST" and parts == ["access", "ticket"]:
            self.handle_ticket()
            return

        user = self.authenticate()
        if user is None:
            self.send_error_json(401, "authentication failure")
            return

        try:
            self.dispatch(method, parts, query, user)
        except Exception as exception:  # noqa: BLE001 - a mock must never take the stack down
            self.log_message("ERROR %s", exception)
            self.send_error_json(500, str(exception))

    def handle_ticket(self):
        body = self.read_body()
        username = body.get("username", "")
        password = body.get("password", "")

        expected = {
            "%s@%s" % (OPERATE_USER, REALM): OPERATE_PASSWORD,
            "%s@%s" % (PROVISION_USER, REALM): PROVISION_PASSWORD,
        }

        if expected.get(username) != password:
            self.send_error_json(401, "authentication failure")
            return

        ticket = "PVE:%s:%08X::mock" % (username, int(now()))
        csrf = "%08X:mockcsrf" % int(now())
        TICKETS[ticket] = (username.split("@")[0], csrf)

        self.send_data({"ticket": ticket, "CSRFPreventionToken": csrf, "username": username})

    def dispatch(self, method, parts, query, user):
        if method == "GET" and parts == ["version"]:
            self.send_data({"version": VERSION, "release": "8.3", "repoid": "mock"})
            return

        if method == "GET" and parts == ["nodes"]:
            self.send_data(NODES)
            return

        if method == "GET" and parts == ["cluster", "resources"]:
            if query.get("type") == "vm":
                self.send_data(list(GUESTS.values()))
            else:
                self.send_data(list(GUESTS.values()) + NODES)
            return

        if method == "GET" and parts == ["cluster", "nextid"]:
            self.send_data(str(max(GUESTS) + 1))
            return

        if method == "GET" and len(parts) == 2 and parts[0] == "pools":
            if parts[1] != POOL:
                self.send_error_json(500, "pool '%s' does not exist" % parts[1])
                return
            self.send_data({"members": [
                {"vmid": guest["vmid"], "type": guest["type"], "node": guest["node"]}
                for guest in GUESTS.values() if guest.get("pool") == POOL
            ]})
            return

        if method == "GET" and len(parts) == 3 and parts[0] == "nodes" and parts[2] == "storage":
            self.send_data(STORAGES.get(parts[1], []))
            return

        if method == "GET" and len(parts) == 5 and parts[0] == "nodes" and parts[2] == "storage" and parts[4] == "content":
            if query.get("content") == "iso":
                self.send_data(ISOS.get(parts[1], []))
            else:
                self.send_data([])
            return

        if method == "GET" and len(parts) == 5 and parts[0] == "nodes" and parts[2] in ("qemu", "lxc") and parts[4] == "config":
            vmid = int(parts[3])
            if vmid not in CONFIGS:
                self.send_error_json(500, "Configuration file 'nodes/%s/%s/%d.conf' does not exist" % (parts[1], parts[2], vmid))
                return
            self.send_data(CONFIGS[vmid])
            return

        if method == "PUT" and len(parts) == 5 and parts[0] == "nodes" and parts[2] in ("qemu", "lxc") and parts[4] == "config":
            vmid = int(parts[3])
            CONFIGS.setdefault(vmid, {}).update(self.read_body())
            if "name" in CONFIGS[vmid] and vmid in GUESTS:
                GUESTS[vmid]["name"] = CONFIGS[vmid]["name"]
            self.send_data(None)
            return

        if method == "POST" and len(parts) == 6 and parts[0] == "nodes" and parts[2] in ("qemu", "lxc") and parts[4] == "status":
            self.handle_power(parts[1], parts[2], int(parts[3]), parts[5], user)
            return

        if method == "POST" and len(parts) == 5 and parts[0] == "nodes" and parts[2] == "qemu" and parts[4] == "clone":
            self.handle_clone(parts[1], int(parts[3]), user)
            return

        if method == "POST" and len(parts) == 3 and parts[0] == "nodes" and parts[2] in ("qemu", "lxc"):
            self.handle_create(parts[1], parts[2], user)
            return

        if method == "GET" and len(parts) == 5 and parts[0] == "nodes" and parts[2] == "tasks" and parts[4] == "status":
            self.handle_task_status(urllib.parse.unquote(parts[3]))
            return

        self.send_error_json(501, "This endpoint is not implemented by the development mock: %s /%s" % (method, "/".join(parts)))

    # --- actions ----------------------------------------------------------------------------

    def handle_power(self, node, kind, vmid, action, user):
        if vmid not in GUESTS:
            self.send_error_json(500, "Configuration file 'nodes/%s/%s/%d.conf' does not exist" % (node, kind, vmid))
            return

        guest = GUESTS[vmid]

        if action == "start":
            guest["status"] = "running"
            guest["uptime"] = 1
        elif action in ("shutdown", "stop"):
            guest["status"] = "stopped"
            guest["uptime"] = 0
            guest["mem"] = 0
            guest["cpu"] = 0.0
        elif action == "reboot":
            guest["status"] = "running"
            guest["uptime"] = 1
        else:
            self.send_error_json(501, "Unknown power action: %s" % action)
            return

        upid = upid_for(node, "qm" + action, vmid, user)
        TASKS[upid] = {"started": now(), "node": node, "fail": False}
        self.send_data(upid)

    def handle_clone(self, node, source, user):
        body = self.read_body()
        newid = int(body.get("newid", max(GUESTS) + 1))
        template = GUESTS.get(source, {})

        GUESTS[newid] = {
            "vmid": newid,
            "name": body.get("name", "clone-%d" % newid),
            "node": node,
            "type": "qemu",
            "status": "stopped",
            "template": 0,
            "pool": body.get("pool") or template.get("pool"),
            "maxcpu": template.get("maxcpu", 2),
            "cpu": 0.0,
            "maxmem": template.get("maxmem", 2147483648),
            "mem": 0,
            "maxdisk": template.get("maxdisk", 17179869184),
        }
        CONFIGS[newid] = dict(CONFIGS.get(source, {}))
        CONFIGS[newid]["name"] = GUESTS[newid]["name"]

        upid = upid_for(node, "qmclone", newid, user)
        TASKS[upid] = {"started": now(), "node": node, "fail": False}
        self.send_data(upid)

    def handle_create(self, node, kind, user):
        body = self.read_body()
        vmid = int(body.get("vmid", max(GUESTS) + 1))

        GUESTS[vmid] = {
            "vmid": vmid,
            "name": body.get("name") or body.get("hostname") or "vm-%d" % vmid,
            "node": node,
            "type": kind,
            "status": "stopped",
            "template": 0,
            "pool": body.get("pool"),
            "maxcpu": int(body.get("cores", 1)),
            "cpu": 0.0,
            "maxmem": int(body.get("memory", 512)) * 1024 * 1024,
            "mem": 0,
            "maxdisk": 17179869184,
        }
        CONFIGS[vmid] = dict(body)

        upid = upid_for(node, "qmcreate", vmid, user)
        TASKS[upid] = {"started": now(), "node": node, "fail": False}
        self.send_data(upid)

    def handle_task_status(self, upid):
        task = TASKS.get(upid)

        if task is None:
            self.send_error_json(500, "no such task")
            return

        finished = (now() - task["started"]) >= TASK_DURATION_SECONDS

        payload = {"upid": upid, "node": task["node"], "status": "stopped" if finished else "running"}
        if finished:
            payload["exitstatus"] = "task failed" if task["fail"] else "OK"

        self.send_data(payload)


def main():
    port = int(os.environ.get("MOCK_PORT", "8006"))
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain("/certs/server.pem", "/certs/server.key")

    server = http.server.ThreadingHTTPServer(("0.0.0.0", port), Handler)
    server.socket = context.wrap_socket(server.socket, server_side=True)

    print("[proxmox-mock] listening on https://0.0.0.0:%d/api2/json" % port, flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
