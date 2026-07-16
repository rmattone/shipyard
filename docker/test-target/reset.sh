#!/usr/bin/env bash
# Tears down and recreates the disposable SSH test-target container.
# Reuses the same keypair across runs so the Server record in ShipYard
# doesn't need to be re-added after each recreate.
set -euo pipefail
cd "$(dirname "$0")"

docker compose down -v 2>/dev/null || true

if [ ! -f id_rsa ]; then
  ssh-keygen -t rsa -b 4096 -f id_rsa -N "" -C "shipyard-test-target"
  cp id_rsa.pub authorized_keys
fi

docker compose up -d --build

echo
echo "Test target is up. In ShipYard's 'Add Server' form use:"
echo "  Host:     host.docker.internal"
echo "  Port:     2222"
echo "  Username: root"
echo
echo "Private key (paste as-is):"
echo "----------------------------------------"
cat id_rsa
echo "----------------------------------------"
