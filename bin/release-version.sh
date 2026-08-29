#!/usr/bin/env bash

set -euo pipefail

fail() {
	echo "Release version check failed: $*" >&2
	exit 1
}

validate_version() {
	local version="$1"
	local semver='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$'

	[[ "${version}" =~ ${semver} ]] || fail "'${version}' is not a valid release version."
}

extract_one() {
	local label="$1"
	local pattern="$2"
	local source="$3"
	local matches
	local count

	matches="$(printf '%s\n' "${source}" | sed -nE "${pattern}")"
	count="$(printf '%s\n' "${matches}" | awk 'NF { count++ } END { print count + 0 }')"
	[[ "${count}" == '1' ]] || fail "expected exactly one ${label}, found ${count}."
	printf '%s\n' "${matches}"
}

plugin_header_version() {
	extract_one 'plugin Version header' 's/^[[:space:]]*\*[[:space:]]+Version:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p' "$1"
}

plugin_constant_version() {
	extract_one 'WPTL_VERSION constant' "s/.*define\([[:space:]]*'WPTL_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'[[:space:]]*\);.*/\1/p" "$1"
}

package_version() {
	extract_one 'package.json version' 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"([^"]+)"[[:space:]]*,?[[:space:]]*$/\1/p' "$1"
}

package_lock_version() {
	local location="$1"
	local source="$2"

	printf '%s\n' "${source}" | node -e '
let input = "";
process.stdin.setEncoding( "utf8" );
process.stdin.on( "data", ( chunk ) => { input += chunk; } );
process.stdin.on( "end", () => {
	try {
		const lock = JSON.parse( input );
		const value = process.argv[ 1 ] === "root" ? lock.packages?.[ "" ]?.version : lock.version;
		if ( typeof value !== "string" || ! value ) {
			throw new Error( "missing version" );
		}
		process.stdout.write( value );
	} catch ( error ) {
		console.error( `Release version check failed: invalid package-lock.json ${ process.argv[ 1 ] } version.` );
		process.exitCode = 1;
	}
} );
' "${location}"
}

stable_tag_version() {
	extract_one 'readme Stable tag' 's/^Stable tag:[[:space:]]*([^[:space:]]+)[[:space:]]*$/\1/p' "$1"
}

project_id_version() {
	extract_one 'Project-Id-Version header' 's/^"Project-Id-Version: WP Title Layer ([^\\]+)\\n"$/\1/p' "$1"
}

assert_version() {
	local label="$1"
	local actual="$2"
	local expected="$3"

	[[ "${actual}" == "${expected}" ]] || fail "${label} is '${actual}', expected '${expected}'."
}

read_directory_file() {
	local directory="$1"
	local path="$2"
	local file="${directory}/${path}"

	[[ -f "${file}" ]] || fail "missing ${path} in ${directory}."
	command cat "${file}"
}

read_git_file() {
	local project_dir="$1"
	local ref="$2"
	local path="$3"

	git -C "${project_dir}" show "${ref}:${path}" 2>/dev/null || fail "cannot read ${path} from ${ref}."
}

check_sources() {
	local expected="$1"
	local plugin_source="$2"
	local readme_source="$3"
	local package_source="${4:-}"
	local package_lock_source="${5:-}"
	local po_source="${6:-}"
	local pot_source="${7:-}"

	validate_version "${expected}"
	assert_version 'plugin Version header' "$(plugin_header_version "${plugin_source}")" "${expected}"
	assert_version 'WPTL_VERSION' "$(plugin_constant_version "${plugin_source}")" "${expected}"
	assert_version 'readme Stable tag' "$(stable_tag_version "${readme_source}")" "${expected}"
	if [[ -n "${package_source}" ]]; then
		assert_version 'package.json version' "$(package_version "${package_source}")" "${expected}"
		assert_version 'package-lock.json top-level version' "$(package_lock_version 'top-level' "${package_lock_source}")" "${expected}"
		assert_version "package-lock.json packages[''].version" "$(package_lock_version 'root' "${package_lock_source}")" "${expected}"
		assert_version 'Simplified Chinese PO Project-Id-Version' "$(project_id_version "${po_source}")" "${expected}"
		assert_version 'POT Project-Id-Version' "$(project_id_version "${pot_source}")" "${expected}"
	fi
}

usage() {
	cat >&2 <<'EOF'
Usage:
  release-version.sh package-version-directory DIRECTORY
  release-version.sh package-version-git-ref PROJECT_DIR REF
  release-version.sh check-directory VERSION DIRECTORY
  release-version.sh check-git-ref VERSION PROJECT_DIR REF
  release-version.sh check-package-directory VERSION DIRECTORY
EOF
	exit 2
}

command_name="${1:-}"
case "${command_name}" in
	package-version-directory)
		[[ "$#" == '2' ]] || usage
		package_version "$(read_directory_file "$2" 'package.json')"
		;;
	package-version-git-ref)
		[[ "$#" == '3' ]] || usage
		git -C "$2" rev-parse --verify "$3^{commit}" >/dev/null 2>&1 || fail "cannot resolve Git ref '$3'."
		package_version "$(read_git_file "$2" "$3" 'package.json')"
		;;
	check-directory)
		[[ "$#" == '3' ]] || usage
		check_sources \
			"$2" \
			"$(read_directory_file "$3" 'wp-title-layer.php')" \
			"$(read_directory_file "$3" 'readme.txt')" \
			"$(read_directory_file "$3" 'package.json')" \
			"$(read_directory_file "$3" 'package-lock.json')" \
			"$(read_directory_file "$3" 'languages/wp-title-layer-zh_CN.po')" \
			"$(read_directory_file "$3" 'languages/wp-title-layer.pot')"
		echo "Release version contract verified: $2"
		;;
	check-git-ref)
		[[ "$#" == '4' ]] || usage
		git -C "$3" rev-parse --verify "$4^{commit}" >/dev/null 2>&1 || fail "cannot resolve Git ref '$4'."
		check_sources \
			"$2" \
			"$(read_git_file "$3" "$4" 'wp-title-layer.php')" \
			"$(read_git_file "$3" "$4" 'readme.txt')" \
			"$(read_git_file "$3" "$4" 'package.json')" \
			"$(read_git_file "$3" "$4" 'package-lock.json')" \
			"$(read_git_file "$3" "$4" 'languages/wp-title-layer-zh_CN.po')" \
			"$(read_git_file "$3" "$4" 'languages/wp-title-layer.pot')"
		echo "Release version contract verified: $2 at $4"
		;;
	check-package-directory)
		[[ "$#" == '3' ]] || usage
		check_sources \
			"$2" \
			"$(read_directory_file "$3" 'wp-title-layer.php')" \
			"$(read_directory_file "$3" 'readme.txt')"
		echo "Packaged release version verified: $2"
		;;
	*)
		usage
		;;
esac
