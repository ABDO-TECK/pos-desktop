# Stage a native shell release

The stable **Signed delta release** workflow in `ABDO-TECK/pos-desktop-updates` calls `scripts/build-delta-release.ps1` from the selected source commit. Without native release configuration, it follows the existing application Delta workflow.

For an ordinary same-channel native shell update, first publish the target Setup through the Setup workflow. In a separate reviewed source commit for the feed build, set `release_type` to `delta` in `version.json`, keep the target application version, and set `delta_baseline_tags` to the single source Setup tag. Add `release-config/native-shell-delta.json` with four string fields.

| Field | Value |
| --- | --- |
| `source_setup_version` | Exact installed Setup version, without the `v` prefix |
| `source_setup_sha256` | Reviewed lowercase SHA-256 of the official source Setup EXE |
| `target_setup_version` | Exact published target Setup version, without the `v` prefix |
| `target_setup_sha256` | Reviewed lowercase SHA-256 of the official target Setup EXE |

Use the feed metadata commit SHA as the workflow's `source_ref` and the published target Setup tag as `tag`. The opt-in file lives outside the packaged product so that you can enter the target checksum after Setup is published. The native staging path is available for stable releases with one exact baseline.

Run the workflow with `publish` off and inspect its staged assets before using the normal publication step. The runner checks the published installer hashes, expands the x64 payloads without running Setup, and measures both packaged MariaDB contracts. It stages `shell-manifest.json`, `shell-manifest.sig`, and `shell-delta.zip`, the verified target deployment inventory for subsequent application releases, and the release notes. When the builder proves the binary transport is smaller, staging also includes `shell-binary.json`, `shell-binary.signature`, and `shell-binary.bin`.

Setup 0.0.14 introduces the native updater. Older installed versions need Setup to bootstrap it. The first update from Setup 0.0.14 uses the canonical signed Format 2 ZIP to install a client that understands the binary transport. The read-only staging proof on the source repository can use the historical 0.0.13 to 0.0.14 pair with a disposable signer to exercise generation without a publication.
