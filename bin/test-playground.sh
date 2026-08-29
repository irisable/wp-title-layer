#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
playground="${project_dir}/node_modules/.bin/wp-playground-cli"
plugin_mount="${project_dir}:/wordpress/wp-content/plugins/wp-title-layer"
mu_mount="${project_dir}/tests/fixtures/mu-plugins:/wordpress/wp-content/mu-plugins"
test_script="/wordpress/wp-content/plugins/wp-title-layer/tests/integration-smoke.php"
sequence_test_script="/wordpress/wp-content/plugins/wp-title-layer/tests/sequence-manager-smoke.php"
book_test_script="/wordpress/wp-content/plugins/wp-title-layer/tests/book-structure-smoke.php"
syntax_script="/wordpress/wp-content/plugins/wp-title-layer/tests/php-syntax-check.php"

if [[ ! -x "${playground}" ]]; then
	echo 'WordPress Playground is missing. Run npm install first.' >&2
	exit 1
fi

run_test() {
	local wp_source="$1"
	local php_version="$2"
	local label="$3"

	echo "Testing ${label} with PHP ${php_version}"
	"${playground}" php \
		--php="${php_version}" \
		--verbosity=quiet \
		--wordpress-install-mode=do-not-attempt-installing \
		--skip-sqlite-setup \
		--mount="${plugin_mount}" \
		-- "${syntax_script}"
	"${playground}" php \
		--wp="${wp_source}" \
		--php="${php_version}" \
		--verbosity=quiet \
		--mount="${plugin_mount}" \
		--mount="${mu_mount}" \
		-- "${test_script}"
	"${playground}" php \
		--wp="${wp_source}" \
		--php="${php_version}" \
		--verbosity=quiet \
		--mount="${plugin_mount}" \
		--mount="${mu_mount}" \
		-- "${sequence_test_script}"
	"${playground}" php \
		--wp="${wp_source}" \
		--php="${php_version}" \
		--verbosity=quiet \
		--mount="${plugin_mount}" \
		--mount="${mu_mount}" \
		-- "${book_test_script}"
}

minimum_wp_source="${WPTL_MIN_WP_SOURCE:-https://wordpress.org/wordpress-6.5.zip}"
current_wp_source="${WPTL_CURRENT_WP_SOURCE:-https://wordpress.org/latest.zip}"

run_test "${minimum_wp_source}" '7.4' 'WordPress 6.5'
run_test "${current_wp_source}" '8.3' 'current WordPress'
