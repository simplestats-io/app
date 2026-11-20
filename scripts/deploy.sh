#!/bin/bash

# =============================================================================
# SimpleStats Deploy Script (Zero-Downtime)
#
# Usage:
#   bash ~/simplestats.io/current/scripts/deploy.sh \
#     --keep-releases=10 \
#     --build-docs \
#     --blog-warm-cache \
#     --generate-sitemap \
#     --restart-horizon \
#     --restart-fpm \
#     --stop-ssr
#
# Base directory is derived from the script path:
#   <base>/current/scripts/deploy.sh -> <base>/
#
# Expected directory structure:
#   <base>/
#     current/        -> symlink to latest release
#     releases/       -> release directories
#     shared/
#       .env
#       storage/
#       packages/
#
# Required Forge variables: FORGE_SITE_BRANCH, FORGE_PHP, FORGE_COMPOSER
# =============================================================================

# Stop script on error signal (-e) and undefined variables (-u)
set -eu

# --- Parse arguments ----------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="$(dirname "$(dirname "$SCRIPT_DIR")")"
PROJECT_REPO="git@github.com:simplestats-io/app.git"
AMOUNT_KEEP_RELEASES=10
BUILD_DOCS=false
BLOG_WARM_CACHE=false
GENERATE_SITEMAP=false
RESTART_HORIZON=false
RESTART_FPM=false
STOP_SSR=false

for arg in "$@"; do
  case $arg in
    --keep-releases=*) AMOUNT_KEEP_RELEASES="${arg#*=}" ;;
    --build-docs) BUILD_DOCS=true ;;
    --blog-warm-cache) BLOG_WARM_CACHE=true ;;
    --generate-sitemap) GENERATE_SITEMAP=true ;;
    --restart-horizon) RESTART_HORIZON=true ;;
    --restart-fpm) RESTART_FPM=true ;;
    --stop-ssr) STOP_SSR=true ;;
    *) printf '\nError: Unknown argument %s\n' "$arg" && exit 1 ;;
  esac
done

RELEASE_NAME=$(date +%s--%Y_%m_%d--%H_%M_%S)
RELEASES_DIRECTORY="$BASE_DIR/releases"
DEPLOYMENT_DIRECTORY="$RELEASES_DIRECTORY/$RELEASE_NAME"

# --- Pull client package -----------------------------------------------------

cd "$BASE_DIR/shared/packages/simplestats-io/laravel-client"
PACKAGE_SHA_BEFORE=$(git rev-parse HEAD)
git pull
PACKAGE_SHA_AFTER=$(git rev-parse HEAD)

if [ "$PACKAGE_SHA_BEFORE" != "$PACKAGE_SHA_AFTER" ]; then
  printf '\nℹ️ Package updated (%s -> %s): auto-enabling FPM reload to refresh opcache for symlinked path\n' \
    "${PACKAGE_SHA_BEFORE:0:7}" "${PACKAGE_SHA_AFTER:0:7}"
  RESTART_FPM=true
fi

# --- Clone release ------------------------------------------------------------

printf '\nℹ️ Starting deployment %s\n' "$RELEASE_NAME"
mkdir -p "$RELEASES_DIRECTORY" && cd "$RELEASES_DIRECTORY"

printf '\nℹ️ Clone GIT project from %s and checkout branch %s\n' "$PROJECT_REPO" "$FORGE_SITE_BRANCH"
git clone "$PROJECT_REPO" "$RELEASE_NAME"
cd "$RELEASE_NAME"
git checkout "$FORGE_SITE_BRANCH"
git fetch origin "$FORGE_SITE_BRANCH"
git reset --hard FETCH_HEAD

# --- Link shared resources ----------------------------------------------------

printf '\nℹ️ Link env file\n'
ENV_FILE="$BASE_DIR/shared/.env"
if [ -f "$ENV_FILE" ]; then
  rm -rf ./env
  ln -s -n -f -T "$ENV_FILE" ./.env
else
  printf '\nError: env file is missing at %s.' "$ENV_FILE" && exit 1
fi

printf '\nℹ️ Link storage folder\n'
STORAGE_DIR="$BASE_DIR/shared/storage"
if [ -d "$STORAGE_DIR" ]; then
  rm -rf ./storage
  ln -s -n -f -T "$STORAGE_DIR" ./storage
  # Otherwise git status in the release lists every tracked storage file as deleted
  # and the symlink as untracked.
  git ls-files -z storage | git update-index -z --skip-worktree --stdin
  echo '/storage' >> .git/info/exclude
else
  printf '\nError: storage dir is missing at %s.' "$STORAGE_DIR" && exit 1
fi

printf '\nℹ️ Link packages folder\n'
PACKAGES_DIR="$BASE_DIR/shared/packages"
if [ -d "$PACKAGES_DIR" ]; then
  rm -rf ./packages
  ln -s -n -f -T "$PACKAGES_DIR" ./packages
else
  printf '\nError: packages dir is missing at %s.' "$PACKAGES_DIR" && exit 1
fi

# --- Install dependencies ----------------------------------------------------

printf '\nℹ️ Install Composer Dependency Updates\n'
COMPOSER=composer.saas.json $FORGE_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-dev

printf '\nℹ️ Installing NPM dependencies based on "./package-lock.json"\n'
npm ci
printf '\nℹ️ Generating JS App files\n'
npm run build
if [ "$BUILD_DOCS" = "true" ]; then
  printf '\nℹ️ Building Docs\n'
  npm run docs:build
fi

# --- Pre-deploy actions -------------------------------------------------------

printf '\nℹ️ Laravel Artisan commands\n'
if [ -f artisan ]; then
  printf '\nℹ️ Link ./public/storage\n'
  $FORGE_PHP artisan storage:link

  printf '\nℹ️ Clear and cache routes, config, views, events\n'
  $FORGE_PHP artisan optimize:clear

  printf '\nℹ️ Publish Filament assets\n'
  $FORGE_PHP artisan filament:assets

  printf '\nℹ️ Cache routes, config, views, events\n'
  $FORGE_PHP artisan optimize

  if [ "$BLOG_WARM_CACHE" = "true" ]; then
    printf '\nℹ️ Blog Warm Cache\n'
    # Re-highlight only posts whose markdown changed since the live release;
    # --missing additionally catches cold or expired cache entries.
    if PREVIOUS_SHA=$(git -C "$BASE_DIR/current" rev-parse HEAD 2>/dev/null) \
      && CHANGED_BLOG_FILES=$(git diff --name-only "$PREVIOUS_SHA" HEAD -- resources/markdown/blog/ 2>/dev/null); then
      CHANGED_BLOG_SLUGS=$(printf '%s\n' "$CHANGED_BLOG_FILES" | sed -e 's|.*/||' -e 's|\.md$||' | tr '\n' ' ')
      printf 'Blog posts changed since %s: %s\n' "${PREVIOUS_SHA:0:7}" "${CHANGED_BLOG_SLUGS:-none}"
      # word splitting of $CHANGED_BLOG_SLUGS is intended: one argument per slug
      $FORGE_PHP artisan blog:warm-cache --missing $CHANGED_BLOG_SLUGS
    else
      printf 'No previous release to compare against, warming all posts\n'
      $FORGE_PHP artisan blog:warm-cache
    fi
  fi

  if [ "$GENERATE_SITEMAP" = "true" ]; then
    printf '\nℹ️ Generate Sitemap\n'
    $FORGE_PHP artisan app:generate-sitemap
  fi

  printf '\nℹ️ Database Migrations\n'
  $FORGE_PHP artisan migrate --force
fi

# --- Activate release ---------------------------------------------------------

printf '\nℹ️ !!! Link Deployment Directory !!!\n'
echo "$RELEASE_NAME" >> "$RELEASES_DIRECTORY/.successes"
if [ -d "$BASE_DIR/current" ] && [ ! -L "$BASE_DIR/current" ]; then
  rm -rf "$BASE_DIR/current"
fi
ln -s -n -f -T "$DEPLOYMENT_DIRECTORY" "$BASE_DIR/current"

# --- Post-deploy actions (must run after symlink to load new code) ------------

cd "$DEPLOYMENT_DIRECTORY"

if [ "$RESTART_HORIZON" = "true" ]; then
  printf '\nℹ️ Restart Horizon Queue Workers\n'
  $FORGE_PHP artisan horizon:terminate
fi

# Only needed if your web server does not resolve the "current" symlink to the real path.
# For Nginx, use $realpath_root instead of $document_root in fastcgi_param SCRIPT_FILENAME.
# If configured correctly, PHP opcache picks up the new release automatically without a reload.
if [ "$RESTART_FPM" = "true" ]; then
  printf '\nℹ️ Restart PHP FPM\n'
  ( flock -w 10 9 || exit 1
      echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
fi

if [ "$STOP_SSR" = "true" ]; then
  $FORGE_PHP artisan inertia:stop-ssr || true
fi

# --- Clean up old releases ----------------------------------------------------

cd "$RELEASES_DIRECTORY"

printf '\nℹ️ Delete failed releases:\n'
if grep -qvf .successes <(ls -1)
then
  grep -vf .successes <(ls -1)
  grep -vf .successes <(ls -1) | xargs rm -rf
else
  echo "No failed releases found."
fi

printf '\nℹ️ Delete old successful releases:\n'
AMOUNT_KEEP_RELEASES=$((AMOUNT_KEEP_RELEASES-1))
LINES_STORED_RELEASES_TO_DELETE=$(find . -maxdepth 1 -mindepth 1 -type d ! -name "$RELEASE_NAME" -printf '%T@\t%f\n' | head -n -"$AMOUNT_KEEP_RELEASES" | wc -l)
if [ "$LINES_STORED_RELEASES_TO_DELETE" != 0 ]; then
  find . -maxdepth 1 -mindepth 1 -type d ! -name "$RELEASE_NAME" -printf '%T@\t%f\n' | sort -t $'\t' -g | head -n -"$AMOUNT_KEEP_RELEASES" | cut -d $'\t' -f 2-
  find . -maxdepth 1 -mindepth 1 -type d ! -name "$RELEASE_NAME" -printf '%T@\t%f\n' | sort -t $'\t' -g | head -n -"$AMOUNT_KEEP_RELEASES" | cut -d $'\t' -f 2- | xargs -I {} sed -i -e '/{}/d' .successes
  find . -maxdepth 1 -mindepth 1 -type d ! -name "$RELEASE_NAME" -printf '%T@\t%f\n' | sort -t $'\t' -g | head -n -"$AMOUNT_KEEP_RELEASES" | cut -d $'\t' -f 2- | xargs rm -rf
else
  AMOUNT_KEEP_RELEASES=$((AMOUNT_KEEP_RELEASES+1))
  LINES_STORED_RELEASES_TOTAL=$(find . -maxdepth 1 -mindepth 1 -type d -printf '%T@\t%f\n' | wc -l)
  printf 'There are only %s successfully stored releases, which is less than or equal to your\ndefined %s releases to keep, so none of them got deleted.' "$LINES_STORED_RELEASES_TOTAL" "$AMOUNT_KEEP_RELEASES"
fi

printf '\nℹ️ Status - stored releases:\n'
find . -maxdepth 1 -mindepth 1 -type d -printf '%T@\t%f\n' | sort -nr | cut -f 2-

printf '\n✅ Deployment DONE: %s\n' "$DEPLOYMENT_DIRECTORY"
