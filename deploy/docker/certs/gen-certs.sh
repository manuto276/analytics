#!/usr/bin/env bash
# Generates the local development/test certificate authority and the two leaf
# certificates used by the dev and test compose stacks:
#
#   ca.pem / ca.key              local CA (import it to browse https://analytics.test)
#   analytics.test.pem / .key    the service host
#   fixtures.pem / .key          tracked-site fixtures: site.test, *.site.test, other.test
#
# Everything lands in this directory, which is git-ignored for keys and certificates.
# Re-running only regenerates what is missing unless --force is given.
#
# Usage: deploy/docker/certs/gen-certs.sh [--force] [--days N]
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
force=0
days=825

while [ "$#" -gt 0 ]; do
  case "$1" in
    --force) force=1 ;;
    --days) shift; days="${1:?--days needs a number}" ;;
    --days=*) days="${1#--days=}" ;;
    -h|--help) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "ERROR: unknown argument: $1" >&2; exit 2 ;;
  esac
  shift
done

command -v openssl >/dev/null 2>&1 || { echo "ERROR: openssl is required" >&2; exit 1; }
cd "$here"

if [ "$force" -eq 1 ]; then
  rm -f ./*.pem ./*.key ./*.csr ./*.srl
fi

if [ ! -f ca.key ] || [ ! -f ca.pem ]; then
  echo "==> Local CA"
  openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 3650 \
    -keyout ca.key -out ca.pem \
    -subj "/CN=analytics local test CA/O=analytics" \
    -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
    -addext "keyUsage=critical,keyCertSign,cRLSign" 2>/dev/null
  chmod 0600 ca.key
fi

# leaf <name> <SAN list>
leaf() {
  local name="$1" san="$2"
  if [ -f "${name}.pem" ] && [ -f "${name}.key" ]; then
    echo "==> ${name}.pem (kept)"
    return 0
  fi
  echo "==> ${name}.pem"
  openssl req -newkey rsa:2048 -sha256 -nodes \
    -keyout "${name}.key" -out "${name}.csr" \
    -subj "/CN=${name}/O=analytics test" 2>/dev/null
  openssl x509 -req -in "${name}.csr" -CA ca.pem -CAkey ca.key -CAcreateserial \
    -out "${name}.pem" -days "$days" -sha256 \
    -extfile <(printf 'basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=%s\n' "$san") 2>/dev/null
  rm -f "${name}.csr"
  chmod 0600 "${name}.key"
}

leaf analytics.test "DNS:analytics.test,DNS:*.analytics.test,DNS:localhost,IP:127.0.0.1"
leaf fixtures "DNS:site.test,DNS:*.site.test,DNS:other.test,DNS:*.other.test"

echo
echo "Certificates in ${here}:"
ls -1 ./*.pem ./*.key
cat <<'EOF'

Trust the CA if you want a browser without warnings:
  macOS   sudo security add-trusted-cert -d -r trustRoot \
            -k /Library/Keychains/System.keychain deploy/docker/certs/ca.pem
  Linux   sudo cp deploy/docker/certs/ca.pem /usr/local/share/ca-certificates/analytics-test.crt \
            && sudo update-ca-certificates

Host names for the dev stack (/etc/hosts):
  127.0.0.1 analytics.test www.site.test app.site.test other.test proxy.site.test
EOF
