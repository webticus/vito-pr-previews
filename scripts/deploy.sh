#!/usr/bin/env bash
# Executed inline by the Vito workflow as the isolated site user.
set -Eeuo pipefail
app_root=$1 pr=$2 sha=$3 request_id=$4 php_bin=$5 environment_base64=$6 seed=$7
cd "$app_root"
umask 077
exec 9>"$app_root/.preview-deploy.lock"
flock -w 900 9
mkdir -p shared/storage/app/public shared/storage/framework/cache/data \
    shared/storage/framework/sessions shared/storage/framework/views shared/storage/logs releases
chmod 0755 shared shared/storage shared/storage/app shared/storage/app/public releases
if [[ ! -f shared/.env ]]; then
    printf '%s' "$environment_base64" | base64 --decode > shared/.env
    printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" >> shared/.env
fi
unset environment_base64
umask 022
release="$app_root/releases/$sha-$request_id"
mkdir -m 0755 "$release"
git archive "$sha" | tar -x -C "$release"
cd "$release"
rm -rf storage
ln -s "$app_root/shared/storage" storage
ln -s "$app_root/shared/.env" .env
mkdir -p bootstrap/cache
"$php_bin" /usr/local/bin/composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
if [[ -f package-lock.json ]]; then
    npm ci --no-audit --no-fund
    npm run build
    rm -rf node_modules
fi
if [[ ! -f $app_root/shared/database.sqlite ]]; then
    install -m 0600 /dev/null "$app_root/shared/database.sqlite"
fi
"$php_bin" artisan migrate --force --no-interaction
if [[ $seed == 1 && ! -f $app_root/shared/.preview-seeded ]]; then
    "$php_bin" artisan db:seed --force --no-interaction
    touch "$app_root/shared/.preview-seeded"
fi
"$php_bin" artisan storage:link --no-interaction
"$php_bin" artisan optimize --no-interaction
printf '{"sha":"%s","pr":%s}\n' "$sha" "$pr" > public/__preview.json
previous=$(readlink "$app_root/current" || true)
if [[ -n $previous ]]; then
    ln -s "$previous" "$app_root/previous.next"
    mv -Tf "$app_root/previous.next" "$app_root/previous"
fi
ln -s "$release" "$app_root/current.next"
mv -Tf "$app_root/current.next" "$app_root/current"
kept=0
while IFS= read -r directory; do
    [[ $directory == "$release" ]] && continue
    kept=$((kept + 1))
    if (( kept > 1 )); then
        rm -rf -- "$directory"
    fi
done < <(find "$app_root/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2-)
