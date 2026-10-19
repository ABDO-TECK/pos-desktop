# POS Desktop v1.1.47 — Customer Bootstrap Migration Guide

**Target Audience**: POS System Administrators & Technical Operations  
**Migration Path**: `v1.1.46` (or earlier) ➔ `v1.1.47 Bootstrap`  
**Purpose**: One-time transition to the modern cryptographic Delta Update Architecture.

---

## 1. Overview

POS Desktop **v1.1.47 Bootstrap Release** upgrades existing terminals to enable future **zero-downtime Delta Updates**, automated pre-update backups, cryptographic integrity checks, and remote fleet telemetry.

```
┌─────────────────┐       One-Time Full Bootstrap       ┌─────────────────┐       Lightweight Delta Updates       ┌─────────────────┐
│  POS v1.1.46    │ ───────────────────────────────────► │  POS v1.1.47    │ ───────────────────────────────────► │  POS v1.1.48+   │
│ (Legacy Client) │           (45.5 MB ZIP)              │ (Modern Engine) │           (~50–200 KB)               │  (Zero Downtime)│
└─────────────────┘                                      └─────────────────┘                                      └─────────────────┘
```

---

## 2. Pre-Migration Recommendations & Backup

> [!IMPORTANT]
> Although the update engine automatically creates a snapshot before modifying files, performing a manual database backup is recommended prior to any major release migration.

### Step-by-Step Pre-Update Backup:
1. Open POS Desktop and log in as an administrator.
2. Go to **Settings > System & Maintenance > Database Backup**.
3. Click **Download Backup** (or export `pos.sqlite` / MySQL database dump).
4. Save the backup file to an external drive or secure cloud storage.

---

## 3. Installation Methods

### Method A: Automated In-App Migration (Recommended)
1. Open POS Desktop and navigate to **Settings > System & Maintenance**.
2. Click **Check for Updates** (فحص التحديثات).
3. The system will detect **v1.1.47 Bootstrap Release**.
4. Click **Apply Update Now** (تطبيق التحديث الآن).
5. The system will automatically:
   - Verify the RSA-2048 digital signature.
   - Create a local rollback snapshot.
   - Extract and atomically replace runtime files.
   - Run database schema migrations.
   - Reload the application.

---

### Method B: Manual Offline Installation
For terminals with restricted internet access:
1. Download the release archive `release/1.1.47-bootstrap/full-package.zip`.
2. Extract the contents directly over your POS installation root (`C:\xampp\htdocs\pos` or application folder).
3. Confirm that `.env` and `storage/database/` are preserved.
4. Restart POS Desktop.

---

## 4. Verification Steps

After updating, verify the following:

1. **Active Version**:
   - Go to **Settings > System & Maintenance**.
   - Current Version must display: **`1.1.47`** (Update Engine: `1.0.0`, Channel: `stable`).
2. **Database Integrity**:
   - Verify that your user accounts, product catalog, inventory levels, and sales history are intact.
3. **Update Center**:
   - Check that the **Update History** and **Snapshot Management** tabs are active.
4. **Future Delta Updates**:
   - Click **Check for Updates**; the engine is now ready to receive future incremental updates without full downloads.

---

## 5. Rollback Procedure

If an unexpected issue occurs or you wish to revert to `v1.1.46`:

### Automatic Rollback:
If an error occurs during update extraction, the system automatically detects the failure, aborts the process, and restores the previous state immediately.

### Manual Rollback via Admin UI:
1. Navigate to **Settings > System & Maintenance > Snapshots**.
2. Locate the snapshot named `patch_1.1.46_to_1.1.47_...`.
3. Click **Rollback to Snapshot** (استعادة اللقطة).
4. Restart the application.

### Manual File Rollback:
1. Locate the snapshot directory at: `storage/backups/snapshots/patch_1.1.46_to_1.1.47_*`.
2. Copy the backed-up files from `files/` back to the application root.
3. Replace `version.json` with the backup copy inside the snapshot.

---

## 6. Cryptographic Release Assets

| Asset Name | Description | Verification Hash |
| :--- | :--- | :--- |
| **`full-package.zip`** | Complete migration package (45.5 MB) | `SHA-256: 08b3e3937cb9f7f8da9075dc1e068eaef2bb3a45aa1d874add900218a1c3f781` |
| **`manifest.json`** | Signed file manifest & checksums | Signed via `manifest.sig` |
| **`manifest.sig`** | RSA-2048 / SHA-256 Digital Signature | Validated against `update_public_key.pem` |
| **`release-notes.md`**| Changelog & technical release notes | Markdown format |

---

## 7. Support & Contact

If assistance is required during the migration:
- **Repository**: [https://github.com/ABDO-TECK/pos](https://github.com/ABDO-TECK/pos-desktop)
- **Technical Operations**: ABDO-TECK Engineering Team
