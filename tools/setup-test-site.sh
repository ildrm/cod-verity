#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
task_wp_root="${CODVERITY_WP_ROOT:-$PWD/build/wordpress}"
task_db_host="${CODVERITY_DB_HOST:-127.0.0.1:33379}"
task_db_name="${CODVERITY_DB_NAME:-codverity}"
task_db_user="${CODVERITY_DB_USER:-root}"
task_db_pass="${CODVERITY_DB_PASS:-codverity-local-only}"
mkdir -p build
if [ ! -f "$task_wp_root/wp-load.php" ]; then
  curl --fail --location --silent --show-error https://wordpress.org/wordpress-6.6.2.tar.gz -o build/wordpress.tar.gz
  tar -xzf build/wordpress.tar.gz -C build
fi
if [ ! -d "$task_wp_root/wp-content/plugins/woocommerce" ]; then
  curl --fail --location --silent --show-error https://downloads.wordpress.org/plugin/woocommerce.9.5.2.zip -o build/woocommerce.zip
  unzip -q build/woocommerce.zip -d "$task_wp_root/wp-content/plugins"
fi
if [ ! -f build/wp-cli.phar ]; then
  curl --fail --location --silent --show-error https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o build/wp-cli.phar
fi
wp_test() { php -d error_reporting=24575 build/wp-cli.phar --allow-root --path="$task_wp_root" "$@"; }
if [ ! -f "$task_wp_root/wp-config.php" ]; then
  wp_test config create --dbname="$task_db_name" --dbuser="$task_db_user" --dbpass="$task_db_pass" --dbhost="$task_db_host" --skip-check
fi
wp_test config set DISABLE_WP_CRON true --raw
if ! wp_test core is-installed; then
  wp_test core install --url=http://127.0.0.1:8097 --title='CODVerity Test Store' --admin_user=codv-admin --admin_password="${CODVERITY_TEST_ADMIN_PASSWORD:-local-test-only-7QpH}" --admin_email=merchant@example.test --skip-email
fi
mkdir -p "$task_wp_root/wp-content/mu-plugins"
cat > "$task_wp_root/wp-content/mu-plugins/codverity-test-controls.php" <<'PHP'
<?php
// Test fixture only: explicit jobs and no real outgoing emails.
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );
PHP
wp_test plugin activate woocommerce
if [ ! -e "$task_wp_root/wp-content/plugins/codverity-order-verification-for-woocommerce" ]; then
  ln -s "$PWD" "$task_wp_root/wp-content/plugins/codverity-order-verification-for-woocommerce"
fi
wp_test plugin activate codverity-order-verification-for-woocommerce
echo "Disposable test store ready at $task_wp_root"
