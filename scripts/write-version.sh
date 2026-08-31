#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "write-version.sh: not a git repository." >&2
    exit 1
fi

DATE="$(git log -1 --format=%cs HEAD)"
REVISION="$(git rev-list --count HEAD)"
SHORT="$(git rev-parse --short HEAD)"
FULL="$(git rev-parse HEAD)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
# Orderable id committed to git. Short SHA is appended at runtime (see BuildInfo)
# because embedding the hash in committed files would change on every amend.
BUILD_ID="${DATE}.${REVISION}"

write_files() {
    printf '%s\n' "$BUILD_ID" > VERSION

    cat > version.json <<EOF
{
  "build_id": "${BUILD_ID}",
  "date": "${DATE}",
  "revision": ${REVISION},
  "branch": "${BRANCH}"
}
EOF
}

if [[ "${1:-}" == "--check" ]]; then
    if [[ ! -f VERSION ]]; then
        echo "write-version.sh: VERSION file is missing." >&2
        exit 1
    fi
    ACTUAL="$(tr -d '\n' < VERSION)"
    if [[ "$ACTUAL" != "$BUILD_ID" ]]; then
        echo "write-version.sh: VERSION mismatch." >&2
        echo "  expected: ${BUILD_ID}" >&2
        echo "  actual:   ${ACTUAL}" >&2
        exit 1
    fi
    if [[ ! -f version.json ]]; then
        echo "write-version.sh: version.json is missing." >&2
        exit 1
    fi
    if ! EXPECTED_BUILD_ID="$BUILD_ID" EXPECTED_SHORT="$SHORT" EXPECTED_FULL="$FULL" php -r '
        $data = json_decode(file_get_contents("version.json"), true);
        if (!is_array($data)) {
            fwrite(STDERR, "write-version.sh: version.json is invalid.\n");
            exit(1);
        }
        if (($data["build_id"] ?? "") !== getenv("EXPECTED_BUILD_ID")) {
            fwrite(STDERR, "write-version.sh: version.json build_id mismatch.\n");
            exit(1);
        }
        if ((int) ($data["revision"] ?? -1) !== (int) substr(strrchr(getenv("EXPECTED_BUILD_ID"), "."), 1)) {
            fwrite(STDERR, "write-version.sh: version.json revision mismatch.\n");
            exit(1);
        }
    '; then
        exit 1
    fi
    echo "VERSION matches HEAD (${BUILD_ID}+${SHORT})."
    exit 0
fi

write_files
echo "Wrote ${BUILD_ID} (display: ${BUILD_ID}+${SHORT})"
