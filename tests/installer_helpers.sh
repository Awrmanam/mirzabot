#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
export MIRZA_LOG_FILE="${TMPDIR:-/tmp}/mirzabot-installer-helper-test.log"
# shellcheck source=../scripts/installer/common.sh
# shellcheck disable=SC1091
. "$ROOT/scripts/installer/common.sh"

failures=0

check() {
    local description="$1"
    shift
    if "$@"; then
        printf '[PASS] %s\n' "$description"
    else
        printf '[FAIL] %s\n' "$description"
        failures=$((failures + 1))
    fi
}

reject() {
    ! "$@"
}

check 'valid FQDN is accepted' validate_domain 'bot.example.com'
check 'uppercase FQDN is normalized for validation' validate_domain 'BOT.EXAMPLE.COM'
check 'single-label domain is rejected' reject validate_domain 'localhost'
check 'leading hyphen is rejected' reject validate_domain '-bot.example.com'
check 'double dot is rejected' reject validate_domain 'bot..example.com'
check 'shell metacharacters are rejected' reject validate_domain 'bot.example.com;id'
check 'missing release checksum is rejected' reject bash -c "
    export MIRZA_LOG_FILE='${MIRZA_LOG_FILE}'
    . '${ROOT}/scripts/installer/common.sh'
    MIRZA_RELEASE_REF='v1.2.3'
    MIRZA_RELEASE_SHA256=''
    validate_release_input >/dev/null 2>&1
"
check 'valid tag plus checksum is accepted' bash -c "
    export MIRZA_LOG_FILE='${MIRZA_LOG_FILE}'
    . '${ROOT}/scripts/installer/common.sh'
    MIRZA_RELEASE_REF='v1.2.3'
    MIRZA_RELEASE_SHA256='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    validate_release_input >/dev/null 2>&1
"

if [[ "$failures" -gt 0 ]]; then
    printf '\n%d installer helper test(s) failed.\n' "$failures"
    exit 1
fi

printf '\nAll installer helper tests passed.\n'
