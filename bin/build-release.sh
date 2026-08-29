#!/usr/bin/env bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
release_ref="${WPTL_RELEASE_REF:-HEAD}"
version_gate="${project_dir}/bin/release-version.sh"

git -C "${project_dir}" rev-parse --verify "${release_ref}^{commit}" >/dev/null
version="${1:-$("${version_gate}" package-version-git-ref "${project_dir}" "${release_ref}")}"
archive_path="${project_dir}/wp-title-layer-${version}.zip"

# Refuse to name an archive until every public version field in the exact Git
# tree being archived agrees with the requested version.
"${version_gate}" check-git-ref "${version}" "${project_dir}" "${release_ref}"

rm -f "${archive_path}"
git -C "${project_dir}" archive \
  --format=zip \
  --prefix=wp-title-layer/ \
  --output="${archive_path}" \
  "${release_ref}"

echo "Built ${archive_path} from $(git -C "${project_dir}" rev-parse --short "${release_ref}^{commit}")"
