#!/usr/bin/env bash
set -Eeuo pipefail
app_root=$1 environment_base64=$2 database_driver=$3 maintenance_secret=${4:-} php_bin=${5:-php}
cd "$app_root"
umask 077
mkdir -p shared/storage/app/public shared/storage/framework/cache/data \
    shared/storage/framework/sessions shared/storage/framework/views shared/storage/logs releases
chmod 0755 shared shared/storage shared/storage/app shared/storage/app/public releases
if [[ -f shared/.env ]]; then
    app_key=$(sed -n 's/^APP_KEY=//p' shared/.env)
    [[ $app_key =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || { echo 'Existing preview APP_KEY is invalid.' >&2; exit 1; }
else
    app_key="base64:$(openssl rand -base64 32)"
fi
printf '%s' "$environment_base64" | base64 --decode > shared/.env.next
printf 'APP_KEY=%s\n' "$app_key" >> shared/.env.next
chmod 600 shared/.env.next
mv -f shared/.env.next shared/.env
if [[ $database_driver == sqlite && ! -f shared/database.sqlite ]]; then
    install -m 0600 /dev/null shared/database.sqlite
fi

printf '%s' "$maintenance_secret" > shared/.maintenance-secret.next
chmod 600 shared/.maintenance-secret.next
mv -f shared/.maintenance-secret.next shared/.maintenance-secret
if [[ -n $maintenance_secret && -f current/artisan ]]; then
    "$php_bin" current/artisan down --secret="$maintenance_secret" --no-interaction --quiet
fi
