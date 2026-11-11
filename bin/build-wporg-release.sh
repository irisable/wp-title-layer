#!/usr/bin/env bash
set -euo pipefail
project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
release_ref="${WPTL_RELEASE_REF:-HEAD}"
version="$("${project_dir}/bin/release-version.sh" package-version-git-ref "${project_dir}" "${release_ref}")"
"${project_dir}/bin/release-version.sh" check-git-ref "${version}" "${project_dir}" "${release_ref}"
archive_path="${project_dir}/irisable-title-layer-${version}.zip"
git -C "${project_dir}" archive --format=zip --prefix=irisable-title-layer/ --output="${archive_path}" "${release_ref}"
echo "Built ${archive_path} from ${release_ref}"
