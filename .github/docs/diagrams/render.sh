#!/usr/bin/env bash
# Renders architecture.dot to PNG and SVG next to it. Requires Graphviz (`dot`).
set -euo pipefail
cd "$(dirname "$0")"
dot -Tpng -Gdpi=110 architecture.dot -o architecture.png
dot -Tsvg architecture.dot -o architecture.svg
echo "Rendered architecture.png and architecture.svg"
