#!/bin/sh
# Fails when Ghostscript is older than the Debian build that fixes
# CVE-2026-125396 (Upload2Shell, DSA-6516-1).
set -eu

min="${GHOSTSCRIPT_MIN_VERSION:-10.05.1~dfsg-1+deb13u2}"
status=0

for pkg in ghostscript libgs10; do
    installed="$(dpkg-query -W -f='${Version}' "$pkg" 2>/dev/null || true)"
    if [ -z "$installed" ]; then
        echo "check-ghostscript: $pkg is not installed" >&2
        status=1
    elif dpkg --compare-versions "$installed" lt "$min"; then
        echo "check-ghostscript: $pkg $installed is older than $min (CVE-2026-125396)" >&2
        status=1
    else
        echo "check-ghostscript: $pkg $installed >= $min"
    fi
done

exit "$status"
