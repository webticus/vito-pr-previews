#!/usr/bin/env bash
set -Eeuo pipefail
plugin_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf -- "$fixture"' EXIT
initial=$(printf 'APP_ENV="preview"\nAPP_URL="https://pr-1.example.com"\nDB_CONNECTION="sqlite"\n' | base64)
bash "$plugin_root/scripts/prepare.sh" "$fixture" "$initial" sqlite test-secret
key_before=$(sed -n 's/^APP_KEY=//p' "$fixture/shared/.env")
[[ -f $fixture/shared/database.sqlite ]]
updated=$(printf 'APP_ENV="preview"\nAPP_URL="http://pr-1.example.com"\nDB_CONNECTION="sqlite"\n' | base64)
bash "$plugin_root/scripts/prepare.sh" "$fixture" "$updated" sqlite test-secret
key_after=$(sed -n 's/^APP_KEY=//p' "$fixture/shared/.env")
[[ $key_before == "$key_after" ]]
grep -q 'APP_URL="http://pr-1.example.com"' "$fixture/shared/.env"
# shellcheck disable=SC2016 # The quoted expression is PHP, not shell interpolation.
php -r 'exit((fileperms($argv[1]) & 0777) === 0600 ? 0 : 1);' "$fixture/shared/.env"
[[ $(cat "$fixture/shared/.maintenance-secret") == test-secret ]]
printf 'Environment refresh preserves the key and private permissions.\n'
custom=$(printf 'APP_ENV="preview"\nAPP_KEY="custom-key"\n' | base64)
bash "$plugin_root/scripts/prepare.sh" "$fixture" "$custom" external test-secret
[[ $(sed -n '/^APP_KEY=/p' "$fixture/shared/.env") == 'APP_KEY="custom-key"' ]]
printf 'Explicit APP_KEY overrides are respected.\n'
