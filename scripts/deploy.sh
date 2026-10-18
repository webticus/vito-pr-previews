#!/usr/bin/env bash
# Executed inline by the Vito workflow as the isolated site user.
set -Eeuo pipefail
app_root=$1 pr=$2 sha=$3 request_id=$4 php_bin=$5 seed=$6 deploy_script=$7 preview_url=$8
cd "$app_root"
umask 022
exec 9>"$app_root/.preview-deploy.lock"
flock -w 900 9
unset GIT_HTTP_TOKEN GITHUB_TOKEN GH_TOKEN
release="$app_root/releases/$sha-$request_id"
mkdir -m 0755 "$release"
git archive "$sha" | tar -x -C "$release"
cd "$release"
rm -rf storage
ln -s "$app_root/shared/storage" storage
ln -s "$app_root/shared/.env" .env
mkdir -p bootstrap/cache
if [[ -n $deploy_script ]]; then
    [[ -f $deploy_script ]] || { echo 'Configured deployment script is missing from the PR.' >&2; exit 1; }
    DEPLOY_PREVIEW=1 PHP_BIN="$php_bin" PREVIEW_APP_ROOT="$release" PREVIEW_PR_NUMBER="$pr" \
        PREVIEW_SHA="$sha" PREVIEW_URL="$preview_url" bash "$deploy_script"
else
    "$php_bin" /usr/local/bin/composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
    if [[ -f package-lock.json ]]; then
        npm ci --no-audit --no-fund
        npm run build
    fi
    "$php_bin" artisan migrate --force --no-interaction
fi
rm -rf node_modules
if [[ $seed == 1 && ! -f $app_root/shared/.preview-seeded ]]; then
    "$php_bin" artisan db:seed --force --no-interaction
    touch "$app_root/shared/.preview-seeded"
fi
if [[ ! -L public/storage ]]; then
    "$php_bin" artisan storage:link --no-interaction
fi
"$php_bin" artisan optimize --no-interaction
maintenance_secret=$(cat "$app_root/shared/.maintenance-secret")
if [[ -n $maintenance_secret ]]; then
    "$php_bin" artisan down --secret="$maintenance_secret" --no-interaction --quiet
else
    "$php_bin" artisan up --no-interaction --quiet
fi
unset maintenance_secret
printf '{"sha":"%s","pr":%s}\n' "$sha" "$pr" > public/__preview.json
previous=$(readlink "$app_root/current" || true)
if [[ -n $previous ]]; then
    ln -s "$previous" "$app_root/previous.next"
    mv -Tf "$app_root/previous.next" "$app_root/previous"
fi
ln -s "$release" "$app_root/current.next"
mv -Tf "$app_root/current.next" "$app_root/current"
while IFS= read -r directory; do
    [[ $directory == "$release" || $directory == "$previous" ]] && continue
    rm -rf -- "$directory"
done < <(find "$app_root/releases" -mindepth 1 -maxdepth 1 -type d)
