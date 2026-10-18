#!/usr/bin/env bash
set -Eeuo pipefail
plugin_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/shared/storage/framework" "$fixture/releases" "$fixture/public"
printf '<?php\n' > "$fixture/public/index.php"
printf 'APP_ENV=preview\n' > "$fixture/shared/.env"
printf 'test-secret' > "$fixture/shared/.maintenance-secret"
cat > "$fixture/deploy-fixture.sh" <<'SCRIPT'
set -euo pipefail
[[ $DEPLOY_PREVIEW == 1 ]]
[[ -z ${GH_TOKEN:-}${GITHUB_TOKEN:-}${GIT_HTTP_TOKEN:-} ]]
[[ ! -f $PREVIEW_APP_ROOT/../../shared/force-fail ]]
SCRIPT
cat > "$fixture/fake-php" <<'SCRIPT'
#!/usr/bin/env bash
set -euo pipefail
case $2 in
    storage:link) ln -s ../storage/app/public public/storage ;;
    down) printf 'protected' > storage/framework/down ;;
    up) rm -f storage/framework/down ;;
    optimize) : ;;
    *) exit 1 ;;
esac
SCRIPT
chmod +x "$fixture/fake-php"
git -C "$fixture" init -q
git -C "$fixture" add deploy-fixture.sh public
git -C "$fixture" -c user.email=tests@example.invalid -c user.name=Tests commit -qm Fixture
sha=$(git -C "$fixture" rev-parse HEAD)
run_deploy() {
    GH_TOKEN=must-not-reach-pr-code bash "$plugin_root/scripts/deploy.sh" "$fixture" 1 "$sha" "$1" "$fixture/fake-php" 0 deploy-fixture.sh https://pr-1.example.com
}
run_deploy first
touch "$fixture/shared/force-fail"
if run_deploy failed; then
    echo 'A failed custom script must fail the deployment.' >&2
    exit 1
fi
[[ $(readlink "$fixture/current") == "$fixture/releases/$sha-first" ]]
rm "$fixture/shared/force-fail"
run_deploy updated
[[ $(readlink "$fixture/current") == "$fixture/releases/$sha-updated" ]]
[[ $(readlink "$fixture/previous") == "$fixture/releases/$sha-first" ]]
[[ -d $fixture/previous ]]
[[ ! -d $fixture/releases/$sha-failed ]]
[[ -f $fixture/current/storage/framework/down ]]
printf 'Failed builds preserve the current release; updates retain a valid rollback release.\n'
