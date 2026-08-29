#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
theme_zip="${1:-${WPTL_KADENCE_ZIP:-}}"
playground="${project_dir}/node_modules/.bin/wp-playground-cli"
wp_source="${WPTL_CURRENT_WP_SOURCE:-https://wordpress.org/latest.zip}"
stage_dir="$(mktemp -d "${TMPDIR:-/tmp}/wptl-kadence.XXXXXX")"

cleanup() {
	rm -rf "${stage_dir}"
}
trap cleanup EXIT

if [[ -z "${theme_zip}" || ! -f "${theme_zip}" ]]; then
	echo 'Pass the path to an official Kadence theme ZIP.' >&2
	exit 1
fi

unzip -q "${theme_zip}" -d "${stage_dir}"

"${playground}" php \
	--wp="${wp_source}" \
	--php=8.3 \
	--verbosity=quiet \
	--mount="${project_dir}:/wordpress/wp-content/plugins/wp-title-layer" \
	--mount="${project_dir}/tests/fixtures/kadence-mu-plugins:/wordpress/wp-content/mu-plugins" \
	--mount="${stage_dir}/kadence:/wordpress/wp-content/themes/kadence" \
	-- /wordpress/wp-content/plugins/wp-title-layer/tests/kadence-theme-smoke.php
