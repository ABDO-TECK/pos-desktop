# Update Channels, Packaging Artifacts, and Strict Isolation Architecture

## 1. Incident Analysis & Baseline Topology
- **Incident Order**: Operator installed **FULL Setup 0.0.13**, then transitioned to existing **Beta 0.0.12-beta.1** via a SIGNED carrier successfully. Subsequently, the normal Beta updater incorrectly offered Stable Setup 0.0.13.
- **Setup Is Not a Channel**: Setup 0.0.13 is a packaging artifact format (full NSIS installer), not a distinct release channel. The active channels are strictly `stable` and `beta` (with legacy `rc` retired and normalized to `stable`).
- **Published 13 Special Baseline**: Every Stable Delta release also ships a matching full Setup for fresh installation; compatible existing clients use Delta. Published 13 was a FULL baseline with NO ordinary 12 to 13 Delta update; delivering this native fix requires a new approved baseline release.
- **Explicit Cross-Channel Transition**: When a user explicitly requests a switch from Beta 0.0.12-beta.1 to Stable 0.0.13 on a compatible baseline, the system must prioritize a signed Transition Delta (`delta.zip`). If falling back to a full transition bundle, it must be a signed application archive (`full.zip`), never a downloaded Setup installer `.exe`.

## 2. Dual Version Authorities & Packaging Boundaries
- **Native vs Deployed Version Authority**:
  - `app.getVersion()` (native shell) remains the authority for physical packaging baseline and native runtime compatibility (Electron binary, Node.js bindings, OS architecture).
  - `readAppVersion()` (deployed payload in `version.json`) is the authority for application state, channel identity, and update newness. Both layers are necessary; neither is discarded.
- **Source-Only Native Scope**:
  - Modifications to `electron/services/auto-updater.js` and `electron/utils/update-channel-policy.js` reside inside the Electron main process (`app.asar`).
  - Standard in-place Delta updates patch `app.asar.unpacked/backend` and `frontend/dist`, but cannot safely patch `app.asar` itself.
  - Distributing native shell updates from 0.0.13 to this patched shell requires a future explicit native baseline release approved by release management, not an unauthorized delta.
- **Published Asset Immutability**:
  - Existing published tags (v0.0.13 on `pos-desktop`, v0.0.12-beta.1 on `pos-desktop-updates`) and their released PHP payloads remain immutable and unpatched on GitHub. Channel isolation controls are implemented in the Source repository verification and orchestration layers.

## 3. Optional binary transport for signed shell releases

Build the Format 2 shell release from verified source and target installations
with `scripts/build-shell-delta.php`. On Windows Actions, use the trusted portable
PHP from the source Setup for the builder, with `zip` and `openssl` enabled.
Matching the installed PHP and ZIP toolchain lets the client regenerate the exact
bytes of the canonical archive.

Stage `shell-manifest.json`, `shell-manifest.sig`, and `shell-delta.zip` under the
same release tag. For regular shell releases, the builder emits
`shell-binary.json`, `shell-binary.signature`, and `shell-binary.bin` when those
three files together are smaller than `shell-delta.zip` and reproduce it exactly.
Stage the optional files together alongside the required assets when present.
The source and target inventories provide the corresponding verification evidence.

The binary metadata has a detached signature under the shell release key and
binds the exact Format 2 manifest bytes and the payload hash and size. The payload
ZIP has one entry per changed-file index. An entry holds copy and literal commands
against the corresponding signed installed file or the whole changed file. The
client verifies each reconstructed file and the canonical archive before staging.
It downloads the separately signed canonical archive when the binary transport is
unavailable, invalid, or incompatible with its ZIP toolchain. Existing clients,
including 0.0.14, continue to use the three required assets.
