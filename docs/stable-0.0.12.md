# Stable 0.0.12 Release and Dual-Baseline Delta Guide

## Overview

Release `0.0.12` is a targeted Stable delta release for Windows POS Desktop installations, published via `ABDO-TECK/pos-desktop-updates`.
It preserves the immutable Setup 0.0.11 desktop shell runtime (commit `aa14fce3dc5c0ec15b3a38c8ae83010430a50cf7`) while deploying updated backend application code, frontend production assets, and verified release metadata across two supported baselines:
- Historical Windows Setup `0.0.11` (commit `aa14fce3dc5c0ec15b3a38c8ae83010430a50cf7`)
- Published Beta `0.0.12-beta.1`

---

## Technical Architecture & Operational Realities

### 1. Dual Baseline Delta Delivery
This release packages two canonical delta archives alongside primary aliases:
- `delta-0.0.11-to-0.0.12.zip` with `manifest-0.0.11.json` and `manifest-0.0.11.sig` (for Setup `0.0.11` baselines)
- `delta-0.0.12-beta.1-to-0.0.12.zip` with `manifest-0.0.12-beta.1.json` and `manifest-0.0.12-beta.1.sig` (for Beta `0.0.12-beta.1` baselines)
- Generic aliases (`manifest.json`, `manifest.sig`, `delta.zip`) are byte-identical copies anchored strictly to the primary `0.0.11` baseline.

### 2. Setup 0.0.11 Bootstrap Limitation
In historical Setup `0.0.11`, the asynchronous background update apply pipeline (`BackgroundUpdateApplyService`) failed to report `result.ok: true` upon completed apply jobs. This protocol defect causes the update UI on unpatched Setup 0.0.11 clients to remain blocked or fail to reconcile completion.
- **Publishing Stable Delta 12 does not patch frozen historical Setup 11 clients**: A delta package distributed through GitHub Releases cannot alter the frozen client binaries of already-installed Setup 11 instances that drop completion DTOs.
- **Customer Bootstrap Path**: The official and direct customer bootstrap path for Setup 0.0.11 installations is the forthcoming **Full Setup 0.0.13 in-place installer** (handled by a parallel release stream).
- **No Production Repair ZIP**: No separate customer-facing repair archive (`POS-Update-Repair-*.zip`) is shipped with Stable 12.

### 3. Data Integrity & State Preservation
- **No Database Migrations**: `database/pos_schema.sql` is byte-identical between baselines and Stable 12. The release contains zero database migrations (`migrations: []`).
- **Data Preservation**: Local SQLite/MariaDB databases, transaction ledgers, customer records, application settings, device private keys, and QZ Tray printing certificates are strictly preserved and untouched.
- **Environment Configuration (`.env`)**: Existing client `.env` configuration files are protected and never overwritten or deleted.

---

## Release Payload Specification

The Stable `0.0.12` release payload contains exactly 13 payload assets plus `SHA256SUMS.txt` (14 total files):

1. `manifest-0.0.11.json` — Canonical RSA-signed manifest for baseline 0.0.11
2. `manifest-0.0.11.sig` — RSA-2048 cryptographic signature for baseline 0.0.11 manifest
3. `delta-0.0.11-to-0.0.12.zip` — Delta payload archive from 0.0.11 to 0.0.12
4. `manifest-0.0.12-beta.1.json` — Canonical RSA-signed manifest for baseline 0.0.12-beta.1
5. `manifest-0.0.12-beta.1.sig` — RSA-2048 cryptographic signature for baseline 0.0.12-beta.1 manifest
6. `delta-0.0.12-beta.1-to-0.0.12.zip` — Delta payload archive from 0.0.12-beta.1 to 0.0.12
7. `manifest.json` — Generic alias (byte-identical to `manifest-0.0.11.json`)
8. `manifest.sig` — Generic alias signature (byte-identical to `manifest-0.0.11.sig`)
9. `delta.zip` — Generic alias archive (byte-identical to `delta-0.0.11-to-0.0.12.zip`)
10. `deployment-inventory.json` — Target application deployment inventory
11. `release-notes.md` — Customer-safe release notes
12. `source-provenance.json` — Cryptographic provenance metadata
13. `stable12-delta-acceptance.json` — Automated dual-baseline acceptance proof
14. `SHA256SUMS.txt` — SHA-256 digest catalog covering all 13 payload files

---

## Security and Verification Boundaries

- **Cryptographic Signatures**: Delta manifests carry RSA-2048 signatures verified against `backend/certs/update_public_key.pem`.
- **Integrity Bounds**: All assets are bound to the release via exact SHA-256 digests in `SHA256SUMS.txt`.
- **Authenticode Notice**: Binaries in this update package are not signed with a commercial Windows Authenticode certificate; trust is anchored in git provenance and RSA manifest signatures.
- **Physical Hardware Output**: Physical ESC/POS printer hardware output is not exercised in automated CI; certificate classification is validated by automated acceptance tests.
