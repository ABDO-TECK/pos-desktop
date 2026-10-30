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
