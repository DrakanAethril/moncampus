#!/usr/bin/env python3
"""Raise and lower the « une mise à jour est en cours » banner on the deployed application.

Two calls, at the two instants the Discord announcement already marks: the banner goes up when the
channel says the deploy started, and comes down when it says how it ended. Between them the
platform restarts, so anybody looking at a screen gets a warning instead of a page that suddenly
stops answering.

Why HTTP and not a console command over the deploy's SSH session: at neither of those two instants
is there a session open. The start is announced before the VPN is dialled, the end after it has
been dropped and the site polled from the open internet. The application's public address is the
one thing available at both.

Failing here must never change the outcome of a deploy, exactly like announce_release.py next to
it: every error path exits 0 with a line in the log. A platform that shows no banner is a small
loss; a deploy that fails because a banner could not be raised is a large one.

Environment:
    APP_URL                  public base URL of the app (required - without it there is nothing to
                             call). The same Actions *variable* announce_release.py uses.
    DEPLOYMENT_NOTICE_TOKEN  the shared secret the application checks, from the
                             BEAUP_DEPLOYMENT_NOTICE_TOKEN repository secret. It has to be the same
                             string as the DEPLOYMENT_NOTICE_TOKEN in the server's .env.prod.local.
"""

import argparse
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

TIMEOUT_SECONDS = 15


def fail_soft(message):
    print(message)
    sys.exit(0)


def version_of_the_release():
    """The version being deployed, or None.

    Read through announce_release.py rather than by parsing the changelog a second time: two
    readers of one file is how the banner would come to name a different release than the channel.
    Decorative either way - the banner does not print it, the row keeps it as a record.

    SystemExit is caught, and that is the whole point of the guard: announce_release's own error
    paths end in fail_soft(), which exits 0. Letting that through here would mean an unreadable
    changelog silently skipping the banner it was only ever going to decorate.
    """
    try:
        sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
        import announce_release

        release = announce_release.load_release()
    except SystemExit:
        print("The changelog could not be read - announcing without a version.")
        return None
    except Exception as error:  # noqa: BLE001 - a version is never worth failing over
        print(f"The changelog could not be read ({error}) - announcing without a version.")
        return None

    version = str((release or {}).get("version", "")).strip()

    return version or None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--phase", choices=("start", "success", "failure"), required=True)
    phase = parser.parse_args().phase

    base = os.environ.get("APP_URL", "").strip().rstrip("/")
    token = os.environ.get("DEPLOYMENT_NOTICE_TOKEN", "").strip()

    if not base:
        fail_soft("APP_URL is not set - no banner to raise or lower.")
    if not token:
        fail_soft("DEPLOYMENT_NOTICE_TOKEN is not set - no banner to raise or lower.")

    fields = {"phase": phase}
    if "start" == phase:
        version = version_of_the_release()
        if version:
            fields["version"] = version

    request = urllib.request.Request(
        f"{base}/deployment/notice",
        data=urllib.parse.urlencode(fields).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "application/json",
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT_SECONDS) as response:
            print(f"Deployment banner ({phase}): HTTP {response.status}.")
    except urllib.error.HTTPError as error:
        # 404 is the answer of a deployed version that does not know this route yet - which is
        # exactly what production is until the release introducing it has landed. Not an incident.
        print(f"Deployment banner ({phase}) refused: HTTP {error.code}.")
    except Exception as error:  # noqa: BLE001 - see the module docstring
        print(f"Deployment banner ({phase}) could not be sent: {error}")

    sys.exit(0)


if __name__ == "__main__":
    main()
