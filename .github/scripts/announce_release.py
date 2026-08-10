#!/usr/bin/env python3
"""Announce a production deploy on Discord: its start, its success, or its failure.

Reads config/changelog.yaml - the same file the /changelog page renders - and takes its most recent
entry. There is deliberately no second source: the announcement says what the page says, or it says
nothing.

Called three times from .github/workflows/deploy.yaml, with --phase start / success / failure. The
three messages are written together here rather than inlined in the workflow so they stay consistent
with one another and with the page.

Failing here must never change the outcome of a deploy - not the start message before it, and
certainly not the failure message after it. Every error path exits 0 with a line in the log.

Environment:
    DISCORD_WEBHOOK  the full webhook URL (required; the workflow skips this script without it).
                     Fed from BEAUP_DISCORD_NOTIFS_WEBHOOK, the webhook the project's CI already
                     posts to - one channel, one secret to rotate.
    APP_URL          public base URL of the app, e.g. https://moncampus.example.org (optional -
                     without it the message simply carries no link). An Actions *variable*, not a
                     secret: there is nothing to protect in a public address, and masking it in the
                     logs would only make a wrong value harder to spot.
    GITHUB_SERVER_URL / GITHUB_REPOSITORY / GITHUB_RUN_ID  set by Actions; used to link the failed
                     run so the failure message says where to look
"""

import argparse
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


def load_release():
    """The most recent release of the changelog, or None with a reason already printed."""
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
    #
    # The version breaks a tie on the date, exactly as App\Service\Changelog does. Two deploys in
    # one day is ordinary - the CalVer rank is monthly, not daily - and without this the order fell
    # back to the order of the file: on 2026-08-10 the page said 2026.08.11 and this script
    # announced 2026.08.10. Compared segment by segment as numbers, so 2026.08.9 sorts below
    # 2026.08.11 instead of above it.
    def sort_key(release):
        version = str(release.get("version", ""))
        parts = tuple(int(p) if p.isdigit() else 0 for p in version.split("."))

        return (str(release.get("date", "")), parts)

    release = sorted([r for r in releases if isinstance(r, dict)], key=sort_key)[-1]

    if not str(release.get("version", "")).strip():
        fail_soft("The most recent release carries no version - nothing announced.")

    return release


def product_entries(release):
    """Only what a member of staff can see: "interne" lines are the code's own business."""
    return [
        entry
        for entry in (release.get("entries") or [])
        if isinstance(entry, dict)
        and str(entry.get("type", "")).strip() in TYPE_LABELS
        and str(entry.get("title", "")).strip()
    ]


def changelog_link():
    app_url = os.environ.get("APP_URL", "").strip().rstrip("/")

    return f"{app_url}/changelog" if app_url else None


def run_link():
    server = os.environ.get("GITHUB_SERVER_URL", "").strip().rstrip("/")
    repository = os.environ.get("GITHUB_REPOSITORY", "").strip()
    run_id = os.environ.get("GITHUB_RUN_ID", "").strip()

    if not (server and repository and run_id):
        return None

    return f"{server}/{repository}/actions/runs/{run_id}"


def message_start(version, release):
    lines = [f"⏳ Déploiement de **MonCampus {version}** en cours…"]

    summary = str(release.get("summary", "")).strip()
    if summary:
        lines.append(summary)

    return lines


def message_success(version, release):
    lines = [f"🚀 **MonCampus {version}** est en ligne."]

    summary = str(release.get("summary", "")).strip()
    if summary:
        lines.append(summary)

    entries = product_entries(release)
    if entries:
        lines.append("")
        for entry in entries[:MAX_ENTRIES]:
            label = TYPE_LABELS[str(entry["type"]).strip()]
            lines.append(f"• **{label}** — {str(entry['title']).strip()}")

        remaining = len(entries) - MAX_ENTRIES
        if remaining > 0:
            lines.append(f"• … et {remaining} autre(s) point(s)")

    link = changelog_link()
    if link:
        lines.append("")
        lines.append(f"Le détail : {link}")

    return lines


def message_failure(version, _release):
    # Deliberately does not claim the previous version is still running: deploy-prod.sh pulls and
    # restarts, so a failure can leave production part-way. Saying where to look beats guessing.
    lines = [
        f"❌ Le déploiement de **MonCampus {version}** a échoué.",
        "La mise en production ne s'est pas terminée — vérifiez l'état du serveur avant de relancer.",
    ]

    link = run_link()
    if link:
        lines.append("")
        lines.append(f"Le journal : {link}")

    return lines


BUILDERS = {
    "start": message_start,
    "success": message_success,
    "failure": message_failure,
}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--phase", choices=sorted(BUILDERS), default="success")
    phase = parser.parse_args().phase

    webhook = os.environ.get("DISCORD_WEBHOOK", "").strip()
    if not webhook:
        fail_soft("No webhook configured - nothing announced.")

    release = load_release()
    version = str(release["version"]).strip()
    content = "\n".join(BUILDERS[phase](version, release))[:1990]

    request = urllib.request.Request(
        webhook,
        data=json.dumps({"content": content}).encode("utf-8"),
        headers={"Content-Type": "application/json", "User-Agent": "moncampus-release-announcer"},
    )

    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            print(f"Announced {version} ({phase}) on Discord (HTTP {response.status}).")
    except urllib.error.HTTPError as error:
        fail_soft(f"Discord refused the message (HTTP {error.code}) - deploy unaffected.")
    except urllib.error.URLError as error:
        fail_soft(f"Discord unreachable ({error.reason}) - deploy unaffected.")


if __name__ == "__main__":
    main()
