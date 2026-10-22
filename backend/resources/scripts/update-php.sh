#!/usr/bin/env bash
set -euo pipefail
export LC_ALL=C
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

branch=${1:-}
[[ "$branch" =~ ^[0-9]+\.[0-9]+$ ]] || { echo 'Invalid PHP branch.'; exit 1; }
. /etc/os-release
case "$ID" in ubuntu|debian) ;; *) echo 'PHP updates require Ubuntu or Debian.'; exit 1;; esac

# Keep this lock through service verification, including when two panel
# installations happen to manage the same host.
exec 9>/run/lock/shipyard-php-update.lock
flock -n 9 || { echo 'Another PHP update is running on this host.'; exit 1; }

inventory=$(dpkg-query -W -f='${binary:Package}\t${Version}\t${db:Status-Status}\n')
packages=()
primary=''
has_fpm=false
has_apache=false
has_cli=false
escaped_branch=${branch//./\\.}
while IFS=$'\t' read -r package version status; do
    [[ "$status" == installed ]] || continue
    if [[ "$package" =~ ^php${escaped_branch}(-[a-z0-9.+-]+)?(:[a-z0-9]+)?$ || "$package" =~ ^libapache2-mod-php${escaped_branch}(:[a-z0-9]+)?$ ]]; then
        packages+=("$package")
        echo "Installed: $package $version"
        case "${package%%:*}" in
            "php$branch-common") primary=$package ;;
            "php$branch-fpm") has_fpm=true ;;
            "php$branch-cli") has_cli=true ;;
            "libapache2-mod-php$branch") has_apache=true ;;
        esac
    fi
done <<< "$inventory"
[[ ${#packages[@]} -gt 0 && -n "$primary" ]] || { echo "PHP $branch is not an installed, supported APT runtime."; exit 1; }

services=()
if $has_fpm; then
    "/usr/sbin/php-fpm$branch" -t
    if systemctl is-active --quiet "php$branch-fpm.service"; then services+=("php$branch-fpm.service"); fi
fi
if $has_apache && systemctl is-active --quiet apache2.service; then
    apache2ctl configtest
    services+=(apache2.service)
fi

# A managed worker can have a shell wrapper as MainPID. Inspect its process
# tree to find the actual interpreter, including an already deleted binary.
php_processes() {
    local pid=$1 executable children child
    executable=$(readlink "/proc/$pid/exe" || true)
    if [[ "${executable% (deleted)}" == "/usr/bin/php$branch" ]]; then echo "$pid"; fi
    if [[ -r "/proc/$pid/task/$pid/children" ]]; then
        children=$(cat "/proc/$pid/task/$pid/children")
        for child in $children; do php_processes "$child"; done
    fi
    return 0
}

units=$(systemctl list-units --type=service --state=running --no-legend --plain 'shipyard-daemon-*@*.service')
while read -r unit rest; do
    [[ "$unit" =~ ^shipyard-daemon-[0-9]+@[0-9]+\.service$ ]] || continue
    pid=$(systemctl show "$unit" --property=MainPID --value)
    if [[ -n "$(php_processes "$pid")" ]]; then services+=("$unit"); fi
done <<< "$units"

echo 'Refreshing package repositories...'
apt-get -o APT::Update::Error-Mode=any -o DPkg::Lock::Timeout=120 update

# Keep the exact candidates for verification; extension packages can have
# independent version numbers, and distro security fixes carry revisions.
candidates=()
targets=()
for package in "${packages[@]}"; do
    policy=$(apt-cache policy "$package")
    candidate=$(awk '$1 == "Candidate:" { print $2 }' <<< "$policy")
    [[ -n "$candidate" && "$candidate" != '(none)' ]] || { echo "No repository candidate for $package."; exit 1; }
    case "${package%%:*}" in
        "php$branch-common"|"php$branch-cli"|"php$branch-fpm"|"php$branch-cgi"|"php$branch-phpdbg"|"libapache2-mod-php$branch")
            [[ "${candidate#*:}" == "$branch."* ]] || { echo "Candidate $candidate would change PHP branch."; exit 1; } ;;
    esac
    candidates+=("$candidate")
    targets+=("$package=$candidate")
    echo "Candidate: $package $candidate"
done

echo 'Checking the upgrade plan...'
plan=$(apt-get --simulate --only-upgrade --no-remove install "${targets[@]}")
echo "$plan"
while read -r action package rest; do
    [[ "$action" == Inst ]] || continue
    if [[ "$package" =~ ^(php|libapache2-mod-php)([0-9]+\.[0-9]+)($|[-:]) && "${BASH_REMATCH[2]}" != "$branch" ]]; then
        echo "The upgrade would also change $package. Resolve repository dependencies before updating."
        exit 1
    fi
done <<< "$plan"

echo "Upgrading installed PHP $branch packages..."
DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l apt-get -y --only-upgrade --no-remove \
    -o DPkg::Lock::Timeout=120 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
    install "${targets[@]}"

for i in "${!packages[@]}"; do
    installed=$(dpkg-query -W -f='${Version}' "${packages[$i]}")
    [[ "$installed" == "${candidates[$i]}" ]] || { echo "Package verification failed for ${packages[$i]}: expected ${candidates[$i]}, got $installed."; exit 1; }
done

if $has_fpm; then "/usr/sbin/php-fpm$branch" -t; fi
if $has_apache && systemctl is-active --quiet apache2.service; then apache2ctl configtest; fi
for service in "${services[@]}"; do
    echo "Restarting $service..."
    systemctl restart "$service"
    systemctl is-active --quiet "$service"
    pid=$(systemctl show "$service" --property=MainPID --value)
    [[ "$pid" =~ ^[1-9][0-9]*$ ]] || { echo "No running process for $service."; exit 1; }
    if [[ "$service" == "php$branch-fpm.service" ]]; then
        [[ "/proc/$pid/exe" -ef "/usr/sbin/php-fpm$branch" ]] || { echo 'FPM is not running the updated executable.'; exit 1; }
    elif [[ "$service" == shipyard-daemon-* ]]; then
        verified=false
        for attempt in {1..10}; do
            pid=$(systemctl show "$service" --property=MainPID --value)
            pids=$(php_processes "$pid")
            if [[ -n "$pids" ]]; then
                verified=true
                for php_pid in $pids; do
                    if [[ ! "/proc/$php_pid/exe" -ef "/usr/bin/php$branch" ]]; then verified=false; fi
                done
                if $verified; then break; fi
            fi
            sleep 1
        done
        $verified || { echo "Worker $service is not running the updated PHP executable."; exit 1; }
    fi
    systemctl is-active --quiet "$service"
    echo "Verified active: $service (PID $pid)"
done

if $has_cli; then "/usr/bin/php$branch" -r 'echo "CLI runtime: ", PHP_VERSION, PHP_EOL;'; fi
if $has_fpm; then "/usr/sbin/php-fpm$branch" -v; fi
echo 'Restart any PHP workers managed outside ShipYard separately.'
echo "SHIPYARD_PHP_UPDATED=$(dpkg-query -W -f='${Version}' "$primary")"
