#!/usr/bin/env bash
# Select a compatible deployment runtime without changing account-wide PHP settings.
select_deploy_php() {
    local candidate
    for candidate in "$@"; do
        [[ -n "$candidate" && -x "$candidate" ]] || continue
        if "$candidate" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_INT_SIZE === 8 ? 0 : 1);' >/dev/null 2>&1; then
            # Composer uses /usr/bin/env php; it must use the migration runtime too.
            export PATH="$(dirname "$candidate"):$PATH"
            return 0
        fi
    done
    echo 'PHP 8.3+ 64-bit CLI required; no compatible deployment runtime found.' >&2
    return 1
}
