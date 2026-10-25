# Beta 0.0.12-beta.1 Release and Signed One-Time Repair Guide

## Overview

Release `0.0.12-beta.1` is a targeted beta delta update for Windows Setup `0.0.11` installations, published via `ABDO-TECK/pos-desktop-updates`.
It preserves the immutable Setup 0.0.11 desktop shell runtime (commit `aa14fce3dc5c0ec15b3a38c8ae83010430a50cf7`) while deploying updated backend application code, frontend production assets, and verified release metadata.

Per human authorization, this release delivers a **signed one-time repair tool** (`POS-Update-Repair-0.0.12-beta.1.zip`) alongside ordinary delta packages and bidirectional channel transition carriers. This repair tool provides a fast, offline transition without downloading the full 322MB Setup installer executable.

---

## Short Usage Instructions

1. **Close POS Desktop and All Background Services**:
   - Exit the POS Desktop application completely.
   - Verify that all background child processes (`php.exe`, `mariadbd.exe`, and QZ Tray `qz-tray.exe`) under the application directory have terminated.

2. **Download the Signed Repair Tool**:
   - Download `POS-Update-Repair-0.0.12-beta.1.zip` from the official GitHub Release in `ABDO-TECK/pos-desktop-updates`.
   - Verify that the downloaded archive hash matches `SHA256SUMS.txt`.

3. **Extract to an Independent Directory**:
   - Extract the repair archive into an isolated temporary folder (e.g., `C:\Temp\POS-Repair`), never directly into the application directory or user data roots.

4. **Run the Repair Launcher as the Installation Owner**:
   - Run the launcher as the **same user** who originally installed and runs POS Desktop.
   - Do **not** elevate to Administrator by default: default installations are per-user (e.g., `%LOCALAPPDATA%\Programs\pos-desktop`). Unnecessary elevation can point user profile guards and application data paths to the wrong account (`Administrator` instead of the POS user).
   - Elevate only if the target directory permissions strictly require administrative privileges (e.g., `%ProgramFiles%\POS Desktop`), ensuring the user identity and profile paths are preserved.
   - Execute via PowerShell:
     ```powershell
     powershell -NoProfile -ExecutionPolicy Bypass -File .\Repair-POS.ps1 -InstallRoot "$env:LOCALAPPDATA\Programs\pos-desktop"
     ```
   - Alternatively, execute the bundled `Repair-POS.cmd` launcher.

5. **Integrity and Data Preservation**:
   - The tool operates strictly while the application is closed.
   - It validates installed native binaries (Electron shell, MariaDB, PHP portable, QZ Tray) against pinned Setup 0.0.11 fingerprints.
   - It verifies RSA-2048 cryptographic signatures against `backend/certs/update_public_key.pem`.
   - It applies updated code files (`backend/backend.phar`, frontend assets, `version.json`) while preserving local database tables, transaction ledgers, device private keys, and printer certificates.
   - **Do NOT uninstall the application**: Upgrading or running this repair tool preserves local data; uninstallation deletes local database stores and point-of-sale business data.

6. **Post-Repair Channel Selection**:
   - Launch POS Desktop after repair completes successfully.
   - The user's previously followed channel setting is preserved. If the client was tracking `stable`, it remains on `stable`.
   - To track beta releases going forward, the operator can explicitly navigate to **Settings -> Admin Update Center** and select the **Beta** channel.

---

## Technical Architecture & Operational Boundaries

### 1. Setup 0.0.11 Ordinary Queued Updater Defect
In Setup 0.0.11, the asynchronous background update apply pipeline (`BackgroundUpdateApplyService`) failed to report `result.ok: true` upon completed apply jobs. This protocol defect caused the update UI to remain blocked or fail to reconcile completion. The signed one-time repair tool bypasses this broken asynchronous protocol entirely by executing while the application is offline and verifying file hashes directly.

### 2. Supported Baseline and Refusal of Active Operations
- **Baseline Support**: Strictly Windows Setup `0.0.11` native runtime shell (commit `aa14fce3dc5c0ec15b3a38c8ae83010430a50cf7`).
- **Refusal of Active Operations**: The repair tool strictly refuses execution if an active, pending, or recovery operation is detected in progress.

### 3. Historical Stable 11 Downgrade Boundary
Downgrading from `0.0.12-beta.1` back to historical `0.0.11` re-introduces the original Setup 0.0.11 application defects (including the queued updater defect). The repair tool can be reused to recover or transition installations only when there is no active, pending, or recovery operation in progress.

### 4. Environment Configuration (`.env`) Realities
The absence of a local `.env` file in previous clean CI runs represents an ephemeral runner artifact rather than universal customer reality. Production environments may maintain local `.env` overrides. The repair tool treats `.env` as protected configuration: it never executes, overwrites, or weakens client environment files.

### 5. Direct UI Channel Transition Route (Architectural Scope)
Setup 0.0.11 source code (commit `aa14fce3dc5c0ec15b3a38c8ae83010430a50cf7`) implements the synchronous `ChannelTransitionService.prepare` and frontend `useChannelTransition` explicit API hook. This explicit transition mechanism is architecturally separate from the broken asynchronous background updater. Consequently, a direct in-app upgrade from Setup 11 to Beta 12 via the Admin Update Center channel transition UI is theoretically viable without the offline repair tool. However, this direct route remains unproven in live CI until confirmed by Task C / GHA acceptance runs; operators should not rely on direct transitions until acceptance tests have confirmed them.

---

## Security and Verification Boundaries

- **Cryptographic Signatures vs. Checksummed Public Set**:
  - Ordinary delta manifests (`manifest.json`, `manifest-0.0.11.json`) and delta archives (`delta.zip`, `delta-0.0.11-to-0.0.12-beta.1.zip`) carry RSA-2048 application signatures (`*.sig`) verified against `backend/certs/update_public_key.pem` via `verify-release-package.php`.
  - The signed repair tool engine, channel transition index (`channel-transitions.json`), and descriptors (`transition-*.json`) are verified in-pipeline during Phase 4 and Phase 5 acceptance, and cryptographically bound to the downloaded draft and published release via exact byte hash checks in `SHA256SUMS.txt`; not every individual file is asserted as individually RSA-signed.
  - Informational and metadata artifacts (`release-notes.md`, `source-provenance.json`, `setup11-beta-repair-acceptance.json`) are part of the exact public artifact set verified by `SHA256SUMS.txt`.
- **No Authenticode Publisher Assertion**: Binaries and scripts in this update package are not signed with a commercial Windows Authenticode certificate; trust is anchored in the release provenance and RSA manifest signature.
- **Physical Printer Output**: Physical ESC/POS printer hardware output is untested in CI; protected certificate classification is validated in tooling and automated acceptance tests.
- **Strict Isolation**: Repair snapshots and work directories are stored in runner/system temporary locations, never inside customer POS user data roots.
