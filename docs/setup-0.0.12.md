# POS Desktop Setup 0.0.12 — Release Specification & Upgrade Guide

## Overview

`POS-Desktop-Setup-0.0.12.exe` is the planned standalone Windows x64 Setup installer for the Stable release channel of POS Desktop. Based upon commit `9124ce7e2a338662611c11d903c12b99d6168999`, Setup 0.0.12 incorporates published stability, channel-transition, and native runtime reconciliation fixes as a self-contained bootstrap installer that refreshes frozen `0.0.11` environments in-place without requiring an external closed-app repair tool.

---

## 1. Release Metadata & Architecture Decisions

| Property | Value | Notes |
| :--- | :--- | :--- |
| **Release Version** | `0.0.12` | Application, installer, and package versions aligned |
| **Release Channel** | `stable` | Primary production release channel |
| **Release Type** | `setup` | Full Windows x64 NSIS installer (`POS-Desktop-Setup-0.0.12.exe`) |
| **Release Date** | `2026-10-27` | Synchronized release timestamp |
| **App ID** | `com.abdoteck.pos` | Identical to existing installations |
| **Product Name** | `POS Desktop` | Preserves existing shortcuts and desktop identity |
| **Base Heritage** | `9124ce7` | Published Beta 12 commit baseline; zero production source drift |

---

## 2. In-Place Upgrade vs. Uninstallation (Critical Rule)

> **IMPORTANT:** Always install Setup 0.0.12 **directly over** the existing installation. **NEVER uninstall first.**

- **In-Place Upgrade:** Installing Setup 0.0.12 over an existing installation (e.g., 0.0.1 or 0.0.11) is designed to preserve all local SQLite/MariaDB business databases, customer records, settings, admin accounts, printer trust certificates, and device encryption keys.
- **Uninstallation Impact:** In accordance with Windows application lifecycle standards, running `Uninstall POS Desktop.exe` intentionally purges all user data and credentials (`%APPDATA%\POS System`, `%LOCALAPPDATA%\POS System`, `%PROGRAMDATA%\POS System`). If an uninstallation occurs prior to reinstalling, the initial administrator onboarding wizard will be displayed.

---

## 3. Native Shell vs. Delta Update Comparison

Understanding the structural difference between delta updates and full setup installers: both packages target application release 0.0.12, while Delta retains baseline native 0.0.11 and full Setup refreshes native 0.0.12.

| Feature | Delta Release 0.0.12 (Stable Delta) | Full Setup 0.0.12 |
| :--- | :--- | :--- |
| **Delivery Mechanism** | Signed ZIP delta archive via background update service | Standalone executable installer (`.exe`) |
| **Native Shell (`POS Desktop.exe`, `app.asar`)** | Retains existing native shell (`0.0.11`) | Upgrades native runtime directly to `0.0.12` |
| **Application Payload (`backend.phar`, `frontend/dist`)** | Updated to `0.0.12` | Packaged with `0.0.12` |
| **Target Audience** | Running applications capable of background delta updates | Fresh installations and frozen `0.0.11` environments requiring full native refresh |
| **Package Composition** | Changed application payload files and manifest | Complete installer containing native shell, packaged app, and embedded portable runtimes |

---

## 4. Planned Upgrade Verification Gates

Automated end-to-end acceptance gates configured for execution exclusively on ephemeral GitHub Actions runners are designed to verify upgrade preservation against both original `0.0.1` and `0.0.11` installations prior to publication:

1. **Database & Schema Preservation:**
   - Raw database schema SHA256 and migration ledger are strictly compared before and after in-place upgrade.
   - Seeded test fixture customer records and settings are asserted identical via canonical JSON normalization.
2. **Administrator Identity & Credentials:**
   - Admin user identity digest (SHA256 of identifier, name, email, role, and password hash) is verified unchanged without exposing secret values.
   - Database credentials (`db_credentials.json`) remain byte-for-byte identical.
   - `.env` preservation is verified when present; when absent in standard desktop deployments, it is truthfully recorded as `not_exercised` rather than fabricating synthetic counts.
3. **Printing Trust & Device Keys:**
   - Enterprise root certificate thumbprint in Windows certificate store is preserved.
   - QZ Tray shared ownership receipt (`qz-shared-install.json`) is verified.
   - Runtime device keys (`userData/qz-certs/private-key.pem` and `digital-certificate.pem`) and active LAN HTTPS certificates are asserted intact.
4. **Future Delta Upgrade Smoke Gate:**
   - The planned isolated-lab gate must verify that packaged Setup 0.0.12 accepts a future signed delta update (target 0.0.14) via background worker and native handoff reload while retaining the native 0.0.12 shell.

---

## 5. Security & Environment Boundaries

- **Code Signing Notice:** This installer has no commercial Windows Authenticode publisher certificate configured. Standard SmartScreen notices may appear on initial download until enterprise reputation is established.
- **Physical Printing:** Automated CI verification asserts certificate enrollment, Java runtime presence, and QZ Tray service execution. Physical paper output from thermal printers is not tested in automated headless environments.
- **Isolation Boundary:** All test executions, cryptographic operations with private keys, database migrations, and installer lifecycles are restricted to ephemeral GitHub Actions runners. Local development environments perform static syntax and structural parser checks only.
