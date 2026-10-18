#!/usr/bin/env bash
set -Eeuo pipefail
app_root=$1 environment_base64=$2 database_driver=$3 maintenance_secret=${4:-} php_bin=${5:-php}
cd "$app_root"
umask 077
mkdir -p shared/storage/app/public shared/storage/framework/cache/data \
    shared/storage/framework/sessions shared/storage/framework/views shared/storage/logs releases
chmod 0755 shared shared/storage shared/storage/app shared/storage/app/public releases
printf '%s' "$environment_base64" | base64 --decode > shared/.env.next
if ! grep -q '^APP_KEY=' shared/.env.next; then
    key_line=''
    if [[ -f shared/.env ]]; then
        key_line=$(sed -n '/^APP_KEY=/p' shared/.env)
    fi
    if [[ -z $key_line ]]; then
        key_line="APP_KEY=base64:$(openssl rand -base64 32)"
    fi
    printf '%s\n' "$key_line" >> shared/.env.next
fi
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
