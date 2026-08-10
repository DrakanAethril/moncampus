#!/usr/bin/env python3
"""Announce the release that was just deployed, on Discord.

Reads config/changelog.yaml - the same file the /changelog page renders - takes its most recent
entry, and posts a short message. There is deliberately no second source: the announcement says
what the page says, or it says nothing.

Called from .github/workflows/deploy.yaml as the last step, and only when the deploy succeeded.
Failing here must never turn a successful deploy red, so every error path exits 0 with a message in
the log - production is already up by then, and a missing Discord post is not worth a red run.

Environment:
    DISCORD_WEBHOOK  the full webhook URL (required; the workflow skips this script without it)
    APP_URL          public base URL of the app, e.g. https://moncampus.example.org (optional -
                     without it the message simply carries no link)
"""

import json
import os
import sys
import urllib.error
import urllib.request

CHANGELOG = "config/changelog.yaml"

# Discord caps a message at 2000 characters. The summary plus a handful of entries is nowhere near
# that, but a release with forty entries would be - so the list is capped and the remainder is
# counted rather than truncated silently.
MAX_ENTRIES = 6

TYPE_LABELS = {
    "nouveaute": "Nouveauté",
    "modification": "Modification",
    "fix": "Correction",
    "autre": "Autre",
}


def fail_soft(message):
    print(message)
    sys.exit(0)


def main():
    webhook = os.environ.get("DISCORD_WEBHOOK", "").strip()
    if not webhook:
        fail_soft("No webhook configured - nothing announced.")

    try:
        import yaml
    except ImportError:
        fail_soft("PyYAML is unavailable - nothing announced.")

    try:
        with open(CHANGELOG, encoding="utf-8") as handle:
            data = yaml.safe_load(handle) or {}
    except OSError as error:
        fail_soft(f"{CHANGELOG} could not be read ({error}) - nothing announced.")

    releases = data.get("releases") or []
    if not isinstance(releases, list) or not releases:
        fail_soft("No release in the changelog - nothing announced.")

    # The file is written newest-first, but sort anyway: the page does, and the two must agree.
    def sort_key(release):
        return str(release.get("date", "")) if isinstance(release, dict) else ""

    release = sorted([r for r in releases if isinstance(r, dict)], key=sort_key)[-1]
    version = str(release.get("version", "")).strip()
    if not version:
        fail_soft("The most recent release carries no version - nothing announced.")

    lines = [f"🚀 **MonCampus {version}** est en ligne."]

    summary = str(release.get("summary", "")).strip()
    if summary:
        lines.append(summary)

    # Only what a member of staff can see: "interne" entries are the code's own business and have
    # no place in an announcement read by teachers.
    entries = [
        entry
        for entry in (release.get("entries") or [])
        if isinstance(entry, dict)
        and str(entry.get("type", "")).strip() in TYPE_LABELS
        and str(entry.get("title", "")).strip()
    ]

    if entries:
        lines.append("")
        for entry in entries[:MAX_ENTRIES]:
            label = TYPE_LABELS[str(entry["type"]).strip()]
            lines.append(f"• **{label}** — {str(entry['title']).strip()}")

        remaining = len(entries) - MAX_ENTRIES
        if remaining > 0:
            lines.append(f"• … et {remaining} autre(s) point(s)")

    app_url = os.environ.get("APP_URL", "").strip().rstrip("/")
    if app_url:
        lines.append("")
        lines.append(f"Le détail : {app_url}/changelog")

    payload = json.dumps({"content": "\n".join(lines)[:1990]}).encode("utf-8")
    request = urllib.request.Request(
        webhook,
        data=payload,
        headers={"Content-Type": "application/json", "User-Agent": "moncampus-release-announcer"},
    )

    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            print(f"Announced {version} on Discord (HTTP {response.status}).")
    except urllib.error.HTTPError as error:
        fail_soft(f"Discord refused the message (HTTP {error.code}) - deploy unaffected.")
    except urllib.error.URLError as error:
        fail_soft(f"Discord unreachable ({error.reason}) - deploy unaffected.")


if __name__ == "__main__":
    main()
