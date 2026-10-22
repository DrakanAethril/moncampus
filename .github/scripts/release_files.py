#!/usr/bin/env python3
"""Check the two files a release commit writes: config/changelog.yaml and config/tech_profile.yaml.

/beaup-deploy writes them on staging just before opening the deploy pull request, and nothing else
goes into that commit. CI therefore does not run the whole suite again for it - the code is the code
of the parent commit, which has just passed - and checks these two files instead, more strictly than
the application reads them: App\\Service\\Changelog drops a line it cannot read rather than break the
page, which is right for the page and wrong for a gate.

Also run on every full CI run, where it costs nothing.

Run: python3 .github/scripts/release_files.py
Exit 1 with one line per problem.
"""

import datetime
import re
import sys

CHANGELOG = "config/changelog.yaml"
TECH_PROFILE = "config/tech_profile.yaml"

TYPES = {"nouveaute", "modification", "fix", "interne", "autre"}
VERSION = re.compile(r"^\d{4}\.\d{2}\.\d{2}(\.\d+)?$")
DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def is_date(value):
    """YAML reads an unquoted 2026-10-22 as a date and a quoted one as a string: both are fine."""
    if isinstance(value, datetime.date):
        return True
    if not isinstance(value, str) or not DATE.match(value):
        return False
    try:
        datetime.date.fromisoformat(value)
    except ValueError:
        return False
    return True


def is_text(value):
    return isinstance(value, str) and value.strip() != ""


def changelog_problems(data):
    if not isinstance(data, dict) or not isinstance(data.get("releases"), list) or not data["releases"]:
        return [f"{CHANGELOG}: no `releases` list"]

    problems = []
    seen = set()
    for index, release in enumerate(data["releases"]):
        where = f"{CHANGELOG}: release #{index + 1}"
        if not isinstance(release, dict):
            problems.append(f"{where} is not a mapping")
            continue

        version = release.get("version")
        if not isinstance(version, str) or not VERSION.match(version):
            problems.append(f"{where}: version {version!r} is not année.mois.jour[.rang], quoted")
        elif version in seen:
            problems.append(f"{where}: version {version} appears twice")
        else:
            seen.add(version)
            where = f"{CHANGELOG}: {version}"

        if not is_date(release.get("date")):
            problems.append(f"{where}: date {release.get('date')!r} is not a date")
        if not is_text(release.get("summary")):
            problems.append(f"{where}: no summary")

        entries = release.get("entries")
        if not isinstance(entries, list) or not entries:
            problems.append(f"{where}: no entries")
            continue
        for number, entry in enumerate(entries, start=1):
            if not isinstance(entry, dict):
                problems.append(f"{where}: entry {number} is not a mapping")
                continue
            if entry.get("type") not in TYPES:
                problems.append(f"{where}: entry {number} has type {entry.get('type')!r}, expected one of {sorted(TYPES)}")
            if not is_text(entry.get("title")):
                problems.append(f"{where}: entry {number} has no title")
            if "detail" in entry and not is_text(entry.get("detail")):
                problems.append(f"{where}: entry {number} has an empty detail")

    return problems


def tech_profile_problems(data):
    if not isinstance(data, dict):
        return [f"{TECH_PROFILE}: not a mapping"]

    problems = []
    if not is_date(data.get("measured_at")):
        problems.append(f"{TECH_PROFILE}: measured_at {data.get('measured_at')!r} is not a date")
    for key in ("commits", "tests"):
        value = data.get(key)
        if isinstance(value, bool) or not isinstance(value, int) or value <= 0:
            problems.append(f"{TECH_PROFILE}: {key} {value!r} is not a positive integer")
    return problems


def main():
    import yaml

    problems = []
    for path, check in ((CHANGELOG, changelog_problems), (TECH_PROFILE, tech_profile_problems)):
        try:
            with open(path, encoding="utf-8") as handle:
                problems += check(yaml.safe_load(handle))
        except (OSError, yaml.YAMLError) as error:
            problems.append(f"{path}: {error}")

    for problem in problems:
        print(problem)
    if problems:
        sys.exit(1)
    print(f"{CHANGELOG} and {TECH_PROFILE} are well-formed.")


if __name__ == "__main__":
    main()
