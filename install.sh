#!/usr/bin/env bash
#
# install.sh — Manage the VirtFusion Direct WHMCS module.
#
# Subcommands:
#   install   First-time install. Refuses if already present (use upgrade).
#   upgrade   Refresh an existing install. Refuses if nothing is installed.
#   check     Report installed version vs latest available. No changes.
#
# Flags (install/upgrade only):
#   --with-addon, -a       Also sync the PowerDNS rDNS addon.
#   --version, -v vX.Y.Z   Pin a specific release tag (default: latest).
#
# Exit codes for `check`:
#   0  installed and up-to-date
#   1  installed but outdated (or installed-version unknown)
#   2  not installed
#
# Pipeable:
#   curl -fsSL https://raw.githubusercontent.com/EZSCALE/virtfusion-whmcs-module/main/install.sh \
#     | sudo bash -s -- install /path/to/whmcs
#
#   wget -qO- https://raw.githubusercontent.com/EZSCALE/virtfusion-whmcs-module/main/install.sh \
#     | sudo bash -s -- upgrade --with-addon /path/to/whmcs
#
#   curl -fsSL https://raw.githubusercontent.com/EZSCALE/virtfusion-whmcs-module/main/install.sh \
#     | bash -s -- check /path/to/whmcs
#
# Why a script? rsync into a directory owned by the WHMCS web user (e.g.
# www-data, apache) lands files as root:root by default, which the web server
# can't read — the classic "module installed but invisible in WHMCS" symptom.
# This script reads the parent directory's owner and applies it via --chown, so
# a `sudo bash` install ends up with correct ownership. It also preserves any
# custom config/ConfigOptionMapping.php across --delete.

set -euo pipefail

REPO="EZSCALE/virtfusion-whmcs-module"
MARKER=".installed-version"

err()  { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; }
warn() { printf '\033[1;33mwarn:\033[0m  %s\n' "$*" >&2; }
info() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }

usage() {
  cat <<USAGE
Usage:
  install.sh install [--with-addon] [--version vX.Y.Z] /path/to/whmcs
  install.sh upgrade [--with-addon] [--version vX.Y.Z] /path/to/whmcs
  install.sh check                                     /path/to/whmcs

Examples:
  curl -fsSL https://raw.githubusercontent.com/$REPO/main/install.sh \\
    | sudo bash -s -- install /path/to/whmcs

  wget -qO- https://raw.githubusercontent.com/$REPO/main/install.sh \\
    | sudo bash -s -- upgrade --with-addon /path/to/whmcs

  curl -fsSL https://raw.githubusercontent.com/$REPO/main/install.sh \\
    | bash -s -- check /path/to/whmcs
USAGE
  exit 2
}

resolve_latest() {
  curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" \
    | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p'
}

read_installed_version() {
  local marker="$1/modules/servers/VirtFusionDirect/$MARKER"
  if [ -f "$marker" ]; then
    tr -d '[:space:]' < "$marker"
  else
    echo "unknown"
  fi
}

cmd_check() {
  local WHMCS="$1"
  if [ ! -d "$WHMCS/modules/servers/VirtFusionDirect" ]; then
    warn "Not installed at $WHMCS"
    exit 2
  fi
  local current latest
  current=$(read_installed_version "$WHMCS")
  latest=$(resolve_latest)
  [ -n "$latest" ] || { err "Could not resolve latest version from GitHub API"; exit 1; }
  printf '  installed: %s\n  latest:    %s\n' "$current" "$latest"
  if [ "$current" = "$latest" ]; then
    info "Up to date"
    exit 0
  fi
  warn "Update available: $current → $latest"
  exit 1
}

cmd_sync() {
  local mode="$1"; shift
  local WITH_ADDON=0 VERSION="${VERSION:-}" WHMCS=""

  while [ $# -gt 0 ]; do
    case "$1" in
      --with-addon|-a) WITH_ADDON=1; shift ;;
      --version|-v)    VERSION="${2:-}"; shift 2 ;;
      -h|--help)       usage ;;
      -*)              err "Unknown flag: $1"; usage ;;
      *)               WHMCS="$1"; shift ;;
    esac
  done

  [ -n "$WHMCS" ] || { err "Missing WHMCS path"; usage; }
  [ -d "$WHMCS/modules/servers" ] || {
    err "Not a WHMCS install: $WHMCS/modules/servers not found"; exit 1;
  }

  local target="$WHMCS/modules/servers/VirtFusionDirect"
  if [ "$mode" = "install" ] && [ -d "$target" ]; then
    err "Already installed at $target — use 'upgrade' to refresh."
    exit 1
  fi
  if [ "$mode" = "upgrade" ] && [ ! -d "$target" ]; then
    err "Not currently installed at $target — use 'install' instead."
    exit 1
  fi

  if [ -z "$VERSION" ]; then
    VERSION=$(resolve_latest)
    [ -n "$VERSION" ] || { err "Could not resolve latest version from GitHub API"; exit 1; }
  fi
  info "Target version: $VERSION"

  local OWNER
  OWNER=$(stat -c '%U:%G' "$WHMCS/modules/servers" 2>/dev/null || true)
  [ -n "$OWNER" ] || { err "Could not detect parent directory owner via stat"; exit 1; }
  info "Owner (from $WHMCS/modules/servers): $OWNER"

  # NOTE: TMP is intentionally NOT declared `local`. The EXIT trap fires when
  # the shell exits, not when this function returns — by then a function-local
  # would be out of scope and `set -u` would explode the trap body with
  # "TMP: unbound variable", masking the script's real exit code with 1.
  # The `${TMP:-}` expansion in the trap is belt-and-suspenders: harmless
  # if TMP somehow ends up unset, and prevents future regressions if anyone
  # moves the assignment back into a tighter scope.
  TMP=$(mktemp -d)
  trap 'rm -rf "${TMP:-}"' EXIT

  info "Downloading $VERSION..."
  curl -fsSL "https://github.com/$REPO/archive/refs/tags/$VERSION.tar.gz" -o "$TMP/src.tar.gz"
  mkdir -p "$TMP/src"
  tar -xzf "$TMP/src.tar.gz" -C "$TMP/src" --strip-components=1

  local SRC="$TMP/src/modules/servers/VirtFusionDirect"
  [ -d "$SRC" ] || { err "Tarball did not contain modules/servers/VirtFusionDirect"; exit 1; }

  # Preserve user's custom configurable-option mapping across --delete.
  local MAP_FILE="$target/config/ConfigOptionMapping.php"
  local MAP_BACKUP=""
  if [ -f "$MAP_FILE" ]; then
    MAP_BACKUP="$TMP/ConfigOptionMapping.php.bak"
    cp -p "$MAP_FILE" "$MAP_BACKUP"
    info "Backed up custom ConfigOptionMapping.php"
  fi

  info "Syncing server module → $target/"
  rsync -ahP --delete --chown="$OWNER" "$SRC/" "$target/"

  if [ -n "$MAP_BACKUP" ]; then
    cp -p "$MAP_BACKUP" "$MAP_FILE"
    chown "$OWNER" "$MAP_FILE"
    info "Restored custom ConfigOptionMapping.php"
  fi

  printf '%s\n' "$VERSION" > "$target/$MARKER"
  chown "$OWNER" "$target/$MARKER"

  if [ "$WITH_ADDON" = 1 ]; then
    local addon_src="$TMP/src/modules/addons/VirtFusionDns"
    local addon_target="$WHMCS/modules/addons/VirtFusionDns"
    [ -d "$addon_src" ] || { err "Tarball did not contain modules/addons/VirtFusionDns"; exit 1; }
    info "Syncing PowerDNS addon → $addon_target/"
    rsync -ahP --delete --chown="$OWNER" "$addon_src/" "$addon_target/"
    printf '%s\n' "$VERSION" > "$addon_target/$MARKER"
    chown "$OWNER" "$addon_target/$MARKER"
  fi

  info "$mode complete: $VERSION (owner $OWNER)"
}

case "${1:-}" in
  install) shift; cmd_sync install "$@" ;;
  upgrade) shift; cmd_sync upgrade "$@" ;;
  check)   shift; [ $# -eq 1 ] || usage; cmd_check "$1" ;;
  -h|--help|"") usage ;;
  *) err "Unknown command: $1"; usage ;;
esac
