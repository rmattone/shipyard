#!/usr/bin/env bash
# Invoked by ApplicationFileService with separately quoted positional arguments.
set -euo pipefail
export LC_ALL=C
umask 077

action=$1
base=$2
relative=$3
owner=$4
atomic=$5
app_type=$6
private_relative=$7
name=${8:-}
source_file=${9:-}
overwrite=${10:-0}
allow_public=${11:-0}

fail() { printf '%s' "$2" >&2; exit "$1"; }
inside() { [[ "$1" == "$root" || "$1" == "$root/"* ]]; }
within() { [[ "$1" == "$2" || "$1" == "$2/"* ]]; }
root=$(realpath -e -- "$base") || fail 44 'Deploy this application before uploading files.'
[[ "$root" != / && -d "$root" ]] || fail 42 'Invalid application directory.'
directory=$(realpath -m -- "$root/$relative")
inside "$directory" || fail 42 'The selected folder points outside this application.'
[[ ! -e "$directory" || -d "$directory" ]] || fail 42 'The destination is not a folder.'

# Compare physical paths as well as the visible selection: storage symlinks
# and aliases to public directories must retain their visibility warning.
release_root="$root"
if [[ "$atomic" == 1 ]]; then release_root=$(realpath -m -- "$root/current"); fi
public_root=$(realpath -m -- "$release_root/public")
if [[ "$app_type" == static ]]; then public_root=$(realpath -m -- "$release_root/dist"); fi
public_storage=$(realpath -m -- "$release_root/storage/app/public")
shared_public=$(realpath -m -- "$root/shared/storage/app/public")
classify() {
    public=0
    ephemeral=0
    if within "$1" "$public_root" || within "$1" "$public_storage" || { [[ "$atomic" == 1 ]] && within "$1" "$shared_public"; }; then public=1; fi
    if [[ "$atomic" == 1 ]] && within "$1" "$root/releases"; then
        ephemeral=1
        # Old releases can also contain publicly exposed folders.
        release_tail=${1#"$root/releases/"}
        release_tail=${release_tail#*/}
        case "$release_tail" in public|public/*|dist|dist/*|storage/app/public|storage/app/public/*) public=1 ;; esac
    fi
}
classify "$directory"

# Create only missing components; never change permissions on existing folders.
ensure_directory() {
    local target=$1 parent
    inside "$target" || fail 42 'The selected folder points outside this application.'
    if [[ ! -d "$target" ]]; then
        parent=$(dirname -- "$target")
        ensure_directory "$parent"
        mkdir -m 755 -- "$target"
        chown -- "$owner:" "$target"
    fi
}

case "$action" in
    browse)
        exists=0
        [[ ! -d "$directory" ]] || exists=1
        printf '%s\0' "$directory" "$exists" "$public" "$ephemeral"
        if [[ "$exists" == 1 ]]; then
            [[ -r "$directory" && -x "$directory" ]] || fail 42 'This folder cannot be read.'
            shopt -s nullglob dotglob
            count=0
            for child in "$directory"/*; do
                [[ -d "$child" ]] || continue
                child_name=${child##*/}
                [[ "$child_name" != .git && ! "$child_name" =~ [[:cntrl:]] ]] || continue
                resolved=$(realpath -e -- "$child") || continue
                inside "$resolved" || continue
                child_relative="${relative:+$relative/}$child_name"
                classify "$resolved"
                printf '%s\0' "$child_name" "$child_relative" "$public" "$ephemeral"
                count=$((count + 1))
                if [[ "$count" -ge 1000 ]]; then break; fi
            done
        fi
        ;;
    mkdir)
        [[ "$public" == 0 || "$allow_public" == 1 ]] || fail 42 'Confirm that this folder may be web-accessible.'
        target=$(realpath -m -- "$directory/$name")
        inside "$target" || fail 42 'The new folder points outside this application.'
        [[ ! -e "$directory/$name" && ! -L "$directory/$name" ]] || fail 43 'A file or folder with this name already exists.'
        ensure_directory "$directory"
        mkdir -m 755 -- "$target"
        chown -- "$owner:" "$target"
        printf '%s' "$target"
        ;;
    upload)
        [[ "$public" == 0 || "$allow_public" == 1 ]] || fail 42 'Confirm that this folder may be web-accessible.'
        # Only the recommended private folder may be created implicitly.
        if [[ ! -d "$directory" ]]; then
            [[ "$relative" == "$private_relative" ]] || fail 42 'The selected folder no longer exists. Refresh the folder picker.'
            ensure_directory "$directory"
        fi
        cd -- "$directory"
        target="./$name"
        [[ ! -L "$target" ]] || fail 42 'Replacing a symbolic link is not allowed.'
        [[ ! -e "$target" || -f "$target" ]] || fail 42 'The destination is not a regular file.'
        if [[ -e "$target" && "$overwrite" != 1 ]]; then fail 43 'A file with this name already exists. Confirm replacement to continue.'; fi
        staged=$(mktemp './.shipyard-upload-XXXXXXXXXXXX')
        trap 'rm -f -- "$staged"' EXIT
        cat -- "$source_file" > "$staged"
        chown -- "$owner:" "$staged"
        mode=640
        [[ "$public" == 0 ]] || mode=644
        chmod "$mode" -- "$staged"
        if [[ "$overwrite" == 1 ]]; then
            mv -fT -- "$staged" "$target"
        else
            # A hard link publishes the complete file without a check/write
            # race: EEXIST never overwrites another concurrent upload.
            ln -T -- "$staged" "$target" 2>/dev/null || fail 43 'A file with this name already exists. Confirm replacement to continue.'
        fi
        printf '%s' "$directory/$name"
        ;;
    *) fail 42 'Unsupported file operation.' ;;
esac
