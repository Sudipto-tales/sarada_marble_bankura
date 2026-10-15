#!/usr/bin/env bash
# Collect metadata first so large galleries cannot delay catalog discovery.
set -euo pipefail
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
python3 -u "$script_dir/kajaria.py" --all "$@"
python3 -u "$script_dir/kajaria.py" --all --download-images "$@"
