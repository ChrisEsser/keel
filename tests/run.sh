#!/usr/bin/env bash
# Runs every test file under tests/ and prints one line per file, then a summary. Exits non-zero
# if any file failed, so it can gate a commit or a deploy.
#
#   tests/run.sh            # everything
#   tests/run.sh php        # only tests/*.php
#   tests/run.sh js         # only tests/*.js
#   tests/run.sh auth       # only files whose name contains "auth"
#
# There is no framework here on purpose, and adding one is not an improvement. Each file is a
# standalone script with its own tiny ok() helper, run as `php tests/foo.php` or `node
# tests/foo.js`, printing a "N passed, M failed" line and exiting non-zero on failure. This runner
# only loops over them, so a test file stays runnable on its own -- which is how you actually
# debug one.

set -u
cd "$(dirname "$0")/.."

# A test that writes rows writes them to whatever database config/.env names. Production is the
# one place that must never be. The tripwire is APP_URL's host: a dev box lives on a reserved
# development name (.local, .test, .localhost, localhost, 127.0.0.1), production lives on a real
# domain. Production should not have tests/ checked out at all; this is the belt for when it does.
app_url=$(grep -E '^APP_URL=' config/.env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '[:space:]"'"'")
app_host=${app_url#*://}; app_host=${app_host%%[:/]*}
case "$app_host" in
    ""|localhost|127.0.0.1|*.local|*.test|*.localhost) ;;
    *)
        if [[ "${1:-}" != "--i-know-this-is-production" ]]; then
            echo "refusing: APP_URL is $app_url, which looks like production, and these tests write to its database."
            echo "Run against a dev config, or pass --i-know-this-is-production as the first argument."
            exit 2
        fi;;
esac
[[ "${1:-}" == "--i-know-this-is-production" ]] && shift

filter="${1:-}"
pass=0; fail=0; failed=()

run_one() {
    local file="$1" cmd="$2"
    local out rc
    out=$(timeout 300 $cmd "$file" 2>&1); rc=$?
    # The last line that looks like a tally, so the summary column says something useful without
    # this runner having to agree with each file about an output format.
    local summary
    summary=$(echo "$out" | grep -E 'passed|checks passed|PASSED|SKIP|OK -' | tail -1 | sed 's/^ *//' | cut -c1-80)
    if [ $rc -eq 0 ]; then
        pass=$((pass+1)); printf '  ok    %-32s %s\n' "$(basename "$file")" "$summary"
    else
        fail=$((fail+1)); failed+=("$file")
        printf '  FAIL  %-32s %s\n' "$(basename "$file")" "${summary:-exit $rc}"
    fi
}

for f in tests/*.php; do
    [ -e "$f" ] || continue
    case "$filter" in js) continue;; php|"") ;; *) [[ "$f" == *"$filter"* ]] || continue;; esac
    run_one "$f" php
done
for f in tests/*.js; do
    [ -e "$f" ] || continue
    case "$filter" in php) continue;; js|"") ;; *) [[ "$f" == *"$filter"* ]] || continue;; esac
    run_one "$f" node
done

echo
echo "files: $((pass+fail))   passed: $pass   failed: $fail"
if [ $fail -gt 0 ]; then
    printf '  %s\n' "${failed[@]}"
    exit 1
fi
