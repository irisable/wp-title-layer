#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_zip="${1:-${WPTL_RANK_MATH_ZIP:-}}"
playground="${project_dir}/node_modules/.bin/wp-playground-cli"
wp_source="${WPTL_CURRENT_WP_SOURCE:-https://wordpress.org/latest.zip}"
stage_dir="$(mktemp -d "${TMPDIR:-/tmp}/wptl-rank-math.XXXXXX")"

cleanup() {
	rm -rf "${stage_dir}"
}
trap cleanup EXIT

if [[ -z "${plugin_zip}" || ! -f "${plugin_zip}" ]]; then
	echo 'Pass the path to an official Rank Math plugin ZIP.' >&2
	exit 1
fi
if [[ ! -x "${playground}" ]]; then
	echo 'WordPress Playground is missing. Run npm install first.' >&2
	exit 1
fi

unzip -q "${plugin_zip}" -d "${stage_dir}"
if [[ ! -f "${stage_dir}/seo-by-rank-math/rank-math.php" ]]; then
	echo 'The ZIP does not contain the expected seo-by-rank-math plugin root.' >&2
	exit 1
fi

"${playground}" php \
	--wp="${wp_source}" \
	--php=8.3 \
	--verbosity=quiet \
	--define-bool WP_DEBUG true \
	--define-bool WP_DEBUG_DISPLAY true \
	--define-bool RANK_MATH_REGISTRATION_SKIP true \
	--mount="${project_dir}:/wordpress/wp-content/plugins/wp-title-layer" \
	--mount="${stage_dir}/seo-by-rank-math:/wordpress/wp-content/plugins/seo-by-rank-math" \
	--mount="${project_dir}/tests/fixtures/rank-math-mu-plugins:/wordpress/wp-content/mu-plugins" \
	-- /wordpress/wp-content/plugins/wp-title-layer/tests/rank-math-smoke.php
