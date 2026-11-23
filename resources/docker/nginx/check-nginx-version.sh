#!/usr/bin/env bash
# Fails if the nginx in the given image is older than the minimum patched release
# (NGINX2Root HTTP/2 fixes: 1.30.7 stable / 1.31.9 mainline).
# Usage: check-nginx-version.sh <image> [min_stable] [min_mainline]
set -euo pipefail

image="${1:?usage: $0 <image> [min_stable] [min_mainline]}"
min_stable="${2:-1.30.7}"
min_mainline="${3:-1.31.9}"

version="$(docker run --rm --entrypoint nginx "$image" -v 2>&1 | sed -n 's|^nginx version: nginx/\([0-9.]*\).*|\1|p')"
if [ -z "$version" ]; then
    echo "ERROR: could not read nginx version from $image" >&2
    exit 1
fi

minor="$(echo "$version" | cut -d. -f2)"
if [ $((minor % 2)) -eq 0 ]; then min="$min_stable"; else min="$min_mainline"; fi
if [ "$(printf '%s\n%s\n' "$min" "$version" | sort -V | head -n1)" != "$min" ]; then
    echo "ERROR: $image has nginx $version; need >= $min" >&2
    exit 1
fi

echo "OK: $image has nginx $version (>= $min)"
