#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version_gate="${project_dir}/bin/release-version.sh"
default_version="$("${version_gate}" package-version-directory "${project_dir}")"
archive_path="${1:-${project_dir}/wp-title-layer-${default_version}.zip}"
playground="${project_dir}/node_modules/.bin/wp-playground-cli"
stage_dir="$(mktemp -d "${TMPDIR:-/tmp}/wptl-package-test.XXXXXX")"

cleanup() {
	rm -rf "${stage_dir}"
}
trap cleanup EXIT

fail_archive_inventory() {
	echo "Release ZIP inventory check failed: $*" >&2
	exit 1
}

validate_archive_inventory() {
	local archive="$1"
	local archive_listing
	local entry
	local relative
	local entry_count=0
	local root_count=0

	if ! archive_listing="$(unzip -Z1 -- "${archive}")"; then
		fail_archive_inventory "could not read ${archive}."
	fi
	[[ -n "${archive_listing}" ]] || fail_archive_inventory 'the archive is empty.'

	while IFS= read -r entry || [[ -n "${entry}" ]]; do
		entry_count=$(( entry_count + 1 ))

		case "${entry}" in
			*\\*)
				fail_archive_inventory "backslashes are not allowed in entry names: ${entry}"
				;;
		esac

		case "${entry}" in
			wp-title-layer/)
				root_count=$(( root_count + 1 ))
				continue
				;;
			wp-title-layer/*)
				relative="${entry#wp-title-layer/}"
				;;
			*)
				fail_archive_inventory "entry is outside the unique wp-title-layer/ root: ${entry}"
				;;
		esac

		if [[ -z "${relative}" || "${relative}" == /* || "${relative}" == *//* ]]; then
			fail_archive_inventory "entry has an unsafe path: ${entry}"
		fi
		case "/${relative}/" in
			*/../*|*/./*)
				fail_archive_inventory "entry traverses a directory boundary: ${entry}"
				;;
		esac

		# Keep this denylist aligned with release export-ignore rules and private
		# audit inputs. The package gate must fail closed if a future commit
		# accidentally makes one of these paths archive-visible.
		case "${relative}" in
			.gitattributes|.gitignore|.github|.github/*|bin|bin/*|composer.json|composer.lock|CONTRIBUTING.md|docs|docs/*|package.json|package-lock.json|phpcs.xml.dist|phpunit.xml.dist|SECURITY.md|tests|tests/*|tests-js|tests-js/*|node_modules|node_modules/*|vendor|vendor/*|secondary-title.2.2.1.zip|wp-title-layer设想.md|wp-title-layer初始实例.md)
				fail_archive_inventory "release-excluded path is present: ${relative}"
				;;
		esac
	done <<< "${archive_listing}"

	[[ "${entry_count}" -gt 1 ]] || fail_archive_inventory 'the archive contains no plugin payload.'
	[[ "${root_count}" == '1' ]] || fail_archive_inventory "expected exactly one wp-title-layer/ root entry, found ${root_count}."
}

if [[ ! -f "${archive_path}" ]]; then
	echo "Release ZIP not found: ${archive_path}" >&2
	exit 1
fi

archive_name="$(basename "${archive_path}")"
case "${archive_name}" in
	wp-title-layer-*.zip)
		archive_version="${archive_name#wp-title-layer-}"
		archive_version="${archive_version%.zip}"
		;;
	*)
		echo "Release ZIP must use the name wp-title-layer-VERSION.zip: ${archive_name}" >&2
		exit 1
		;;
esac

validate_archive_inventory "${archive_path}"
unzip -q "${archive_path}" -d "${stage_dir}"
plugin_dir="${stage_dir}/wp-title-layer"
if [[ ! -f "${plugin_dir}/wp-title-layer.php" ]]; then
	echo 'The ZIP does not contain the expected wp-title-layer plugin root.' >&2
	exit 1
fi

# Check the artifact itself before starting either WordPress runtime. A renamed
# or partially versioned ZIP must never pass the package gate.
"${version_gate}" check-package-directory "${archive_version}" "${plugin_dir}"

if [[ ! -x "${playground}" ]]; then
	echo 'WordPress Playground is missing. Run npm install first.' >&2
	exit 1
fi

# Test files remain outside production packages. Copy them only into this
# disposable extracted tree so the installed artifact, not the worktree, boots.
cp -R "${project_dir}/tests" "${plugin_dir}/tests"

plugin_mount="${plugin_dir}:/wordpress/wp-content/plugins/wp-title-layer"
mu_mount="${project_dir}/tests/fixtures/mu-plugins:/wordpress/wp-content/mu-plugins"
test_script='/wordpress/wp-content/plugins/wp-title-layer/tests/integration-smoke.php'
sequence_test_script='/wordpress/wp-content/plugins/wp-title-layer/tests/sequence-manager-smoke.php'
book_test_script='/wordpress/wp-content/plugins/wp-title-layer/tests/book-structure-smoke.php'
syntax_script='/wordpress/wp-content/plugins/wp-title-layer/tests/php-syntax-check.php'

run_test() {
	local wp_source="$1"
	local php_version="$2"
	local label="$3"

	echo "Testing packaged plugin on ${label} with PHP ${php_version}"
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
