#!/usr/bin/env bash
# Run from the repository root in a disposable container (no actual APT/SSH):
# docker run --rm --network none -e SHIPYARD_PHP_TEST_CONTAINER=1 \
#   -v "$PWD/backend:/source:ro" php:8.4-cli bash /source/tests/Shell/php-update.sh
set -euo pipefail
[[ -f /.dockerenv && ${SHIPYARD_PHP_TEST_CONTAINER:-} == 1 ]] || {
    echo 'Run this fixture only in its disposable test container.'; exit 1;
}
export STATE=/php-update-test
mkdir -p "$STATE"

# Actual processes let the updater exercise /proc executable checks and a
# shell-wrapped worker. APT and systemd are replaced by exported functions.
cat > "$STATE/runtime.c" <<'C'
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
int main(int argc, char **argv) {
    if (argc > 1 && strcmp(argv[1], "serve") == 0) {
        for (;;) pause();
    }
    if (argc > 1 && strcmp(argv[1], "-t") == 0) {
        return strcmp(getenv("SCENARIO"), "invalid-config") == 0 ? 1 : 0;
    }
    puts("PHP 8.4.27 (test runtime)");
    return 0;
}
C
cc "$STATE/runtime.c" -o "$STATE/runtime"

package_version() {
    local package=$1
    if [[ "$package" == php8.4-redis ]]; then
        if [[ -f "$STATE/updated" ]]; then echo '6.2.1-1'; else echo '6.2.0-1'; fi
    elif [[ -f "$STATE/updated" ]]; then
        if [[ "$SCENARIO" == backport ]]; then echo '8.4.24-1ubuntu2'; else echo '8.4.27-1ubuntu1'; fi
    else
        echo '8.4.24-1ubuntu1'
    fi
}

dpkg-query() {
    if [[ "$*" == *'${binary:Package}'* ]]; then
        printf 'php8.3-fpm\t8.3.36-1\tinstalled\nphp8.1-fpm\t8.1.2-1\tconfig-files\n'
        [[ "$SCENARIO" == missing-branch ]] && return 0
        for package in php8.4-common:amd64 php8.4-fpm php8.4-cli php8.4-redis; do
            printf '%s\t%s\tinstalled\n' "$package" "$(package_version "$package")"
        done
        if [[ "$SCENARIO" == apache ]]; then printf 'libapache2-mod-php8.4\t8.4.24-1ubuntu1\tinstalled\n'; fi
    else
        package_version "${@: -1}"
    fi
}

apt-cache() {
    local candidate='8.4.27-1ubuntu1'
    [[ "$SCENARIO" == backport ]] && candidate='8.4.24-1ubuntu2'
    [[ "${@: -1}" == php8.4-redis ]] && candidate='6.2.1-1'
    [[ "$SCENARIO" == wrong-candidate ]] && candidate='8.5.12-1'
    [[ "$SCENARIO" == missing-candidate ]] && candidate='(none)'
    printf '  Candidate: %s\n' "$candidate"
}

apt-get() {
    echo "$*" >> "$STATE/apt-calls"
    if [[ "${@: -1}" == update ]]; then
        [[ "$SCENARIO" != repository-failure ]]
        return
    fi
    if [[ "$*" == *--simulate* ]]; then
        echo 'Inst php8.4-fpm [8.4.24-1] (8.4.27-1 test)'
        if [[ "$SCENARIO" == branch-dependency ]]; then echo 'Inst php8.5-cli (8.5.12-1 test)'; fi
        return 0
    fi
    [[ "$*" == *--only-upgrade* && "$*" == *--no-remove* && "$*" == *'php8.4-redis=6.2.1-1'* ]] || return 90
    [[ "$*" != *php8.3* && "$*" != *--allow-downgrades* ]] || return 91
    [[ "$SCENARIO" != held-package ]] || { echo 'Held package cannot be changed.'; return 100; }
    touch "$STATE/installed"
    [[ "$SCENARIO" == package-mismatch ]] || touch "$STATE/updated"
    # Replace the binaries by inode, like dpkg, while old processes remain alive.
    cp "$STATE/runtime" "$STATE/new-fpm"
    mv "$STATE/new-fpm" /usr/sbin/php-fpm8.4
    cp "$STATE/runtime" "$STATE/new-cli"
    mv "$STATE/new-cli" /usr/bin/php8.4
}

stop_service() {
    local file="$STATE/$1.pid" pid children
    if [[ -f "$file" ]]; then
        pid=$(cat "$file")
        children=$(cat "/proc/$pid/task/$pid/children" 2>/dev/null || true)
        for child in $children; do kill "$child" 2>/dev/null || true; done
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
        rm -f "$file"
    fi
}

start_service() {
    local unit=$1
    case "$unit" in
        php8.4-fpm.service) /usr/sbin/php-fpm8.4 serve > /dev/null 2>&1 9>&- & ;;
        shipyard-daemon-7@1.service) bash -c '/usr/bin/php8.4 serve & wait' > /dev/null 2>&1 9>&- & ;;
        apache2.service) sleep 300 > /dev/null 2>&1 9>&- & ;;
    esac
    echo $! > "$STATE/$unit.pid"
}

systemctl() {
    case "$1" in
        list-units) echo 'shipyard-daemon-7@1.service loaded active running Test worker' ;;
        show) cat "$STATE/$2.pid" ;;
        is-active)
            local file="$STATE/${@: -1}.pid"
            [[ -f "$file" ]] && kill -0 "$(cat "$file")" 2>/dev/null ;;
        restart)
            echo "$2" >> "$STATE/restarts"
            [[ "$SCENARIO" != restart-failure ]] || return 1
            [[ "$SCENARIO" == stale-fpm && "$2" == php8.4-fpm.service ]] && return 0
            stop_service "$2"
            start_service "$2" ;;
        *) echo "Unexpected systemctl command: $*"; return 92 ;;
    esac
}

apache2ctl() { echo 'Apache config OK'; }
export -f package_version dpkg-query apt-cache apt-get stop_service start_service systemctl apache2ctl

cleanup() {
    for unit in php8.4-fpm.service shipyard-daemon-7@1.service apache2.service; do stop_service "$unit"; done
}
trap cleanup EXIT

run_case() {
    export SCENARIO=$1
    local expected=$2 branch=${3:-8.4} status=0
    cleanup
    rm -f "$STATE/updated" "$STATE/installed" "$STATE/apt-calls" "$STATE/restarts"
    cp "$STATE/runtime" /usr/sbin/php-fpm8.4
    cp "$STATE/runtime" /usr/bin/php8.4
    if [[ "$SCENARIO" != inactive-fpm ]]; then start_service php8.4-fpm.service; fi
    start_service shipyard-daemon-7@1.service
    if [[ "$SCENARIO" == apache ]]; then start_service apache2.service; fi
    # Wait for the shell wrapper's child to exist before taking the snapshot.
    local pid
    pid=$(cat "$STATE/shipyard-daemon-7@1.service.pid")
    for attempt in {1..100}; do
        if [[ -s "/proc/$pid/task/$pid/children" ]] || [[ -n "$(cat "/proc/$pid/task/$pid/children")" ]]; then break; fi
        sleep 0.01
    done
    if [[ "$SCENARIO" == locked ]]; then
        exec 8>/run/lock/shipyard-php-update.lock
        flock -n 8
    fi
    bash /source/resources/scripts/update-php.sh "$branch" > "$STATE/output" 2>&1 || status=$?
    if [[ "$SCENARIO" == locked ]]; then flock -u 8; exec 8>&-; fi
    if [[ "$expected" == success ]]; then
        if [[ $status != 0 ]]; then cat "$STATE/output"; return 1; fi
        grep -q '^SHIPYARD_PHP_UPDATED=8.4.' "$STATE/output"
        grep -q 'shipyard-daemon-7@1.service' "$STATE/restarts"
        if [[ "$SCENARIO" == inactive-fpm ]]; then
            ! grep -q 'php8.4-fpm.service' "$STATE/restarts"
        else
            grep -q 'php8.4-fpm.service' "$STATE/restarts"
        fi
        if [[ "$SCENARIO" == apache ]]; then grep -q apache2.service "$STATE/restarts"; fi
        if [[ "$SCENARIO" == backport ]]; then grep -q '^SHIPYARD_PHP_UPDATED=8.4.24-1ubuntu2$' "$STATE/output"; fi
    else
        if [[ $status == 0 ]]; then cat "$STATE/output"; echo "Expected failure: $SCENARIO"; return 1; fi
        ! grep -q '^SHIPYARD_PHP_UPDATED=' "$STATE/output"
        if [[ "$expected" == preflight-failure ]]; then [[ ! -f "$STATE/installed" ]]; fi
    fi
    echo "PASS: $SCENARIO"
}

run_case standard success
run_case backport success
run_case apache success
run_case inactive-fpm success
run_case invalid-branch preflight-failure '8.4;id'
run_case missing-branch preflight-failure
run_case invalid-config preflight-failure
run_case repository-failure preflight-failure
run_case missing-candidate preflight-failure
run_case wrong-candidate preflight-failure
run_case branch-dependency preflight-failure
run_case held-package preflight-failure
run_case locked preflight-failure
run_case package-mismatch failure
run_case restart-failure failure
run_case stale-fpm failure
