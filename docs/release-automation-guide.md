# POS Update Infrastructure: Release Automation Guide (CI/CD)

## 1. Overview

The POS Update Engine uses two separate GitHub Actions workflows:
[`release.yml`](file:///c:/xampp/htdocs/pos/.github/workflows/release.yml) is
read-only verification, while
[`publish-release.yml`](file:///c:/xampp/htdocs/pos/.github/workflows/publish-release.yml)
is a manual, protected publication workflow.

No tag push automatically publishes a release. Verification requires an
explicit tag, immutable baseline ref, baseline version, and channel. Publication
also requires explicit confirmation, protected approval, a version-matched tag,
and a release that does not already exist.

Before using the publication workflow, configure the repository environment
`github-release-approval` under **Settings → Environments** with required
reviewers. The environment name in YAML is not, by itself, an approval policy.

---

## 2. Release Flow Diagram

```mermaid
graph TD
    A[Deploy corrected workflows] --> B[Create approved source commit]
    B --> C[Create version-matched tag]
    C --> D[Manual verification: release.yml]
    D --> E[1. Run Backend & Frontend Unit Tests]
    E --> F{Tests Pass?}
    F -->|NO| G[FAIL: Abort Pipeline with Error]
    F -->|YES| H[2. Validate tag, source, and immutable baseline]
    H --> I{Validation passes?}
    I -->|NO| G
    I -->|YES| J[3. Build and verify signed package]
    J --> K[Protected publication approval]
    K --> L[Manual publish-release.yml]
    L --> M[Create one new release and verify public asset hashes]
    M --> N[POS clients detect compatible updates]
```

---

## 3. Required GitHub Secrets

To enable automated cryptographic signing in GitHub Actions:

1. Open your GitHub repository &rarr; **Settings** &rarr; **Secrets and variables** &rarr; **Actions**.
2. Click **New repository secret**.
3. Create the secret:
   - **Name**: `UPDATE_PRIVATE_KEY`
   - **Secret**: Paste the full content of your private key (including `-----BEGIN RSA PRIVATE KEY-----` and `-----END RSA PRIVATE KEY-----`).

> [!CAUTION]
> Never commit `release/private_key.pem` to the Git repository. The workflow reads the key strictly from `secrets.UPDATE_PRIVATE_KEY` into an ephemeral file and wipes it immediately after signing.

---

## 4. Standard Developer Release Workflow

### Step 1: Implement & Test Changes
Make your code changes, run quality checks and tests:
```bash
# Run backend tests
php backend/vendor/bin/phpunit

# Run frontend tests
npm --prefix frontend test
```

### Step 2: Update `version.json`
Bump the version and update the changelog in `version.json`:
```json
{
    "version": "1.1.49",
    "application_version": "1.1.49",
    "update_engine_version": "1.0.0",
    "released_at": "2026-08-28",
    "changelog": [
        "إصلاح: تحسين سرعة معالجة الفواتير في وضع عدم الاتصال.",
        "تحسين: إضافة خيار تصدير التقارير بتنسيق Excel مُحسّن."
    ],
    "requires_npm_install": false
}
```

### Step 3: Deploy the corrected workflows and commit changes
```bash
git add .github/workflows scripts docs version.json package.json frontend/package.json
git commit -m "ci: gate release publication and require explicit Delta baselines"
```

Merge/push this workflow correction through the authorized repository process
before creating a release tag. A local workflow edit does not protect the
remote repository until the corrected workflow exists on the default branch.

### Step 4: Create and push the version-matched Git release tag

#### For an Incremental Delta Release (Default):
```bash
git tag -a v1.1.49 -m "Release v1.1.49: POS Incremental Delta Release"
git push origin v1.1.49
```

#### For a Full Bootstrap Migration Release:
```bash
git tag -a v1.1.49-bootstrap -m "Release v1.1.49-bootstrap: POS Bootstrap Release"
git push origin v1.1.49-bootstrap
```

For a Delta, supply `--from-ref=<approved immutable baseline commit>` and
`--from-version=<baseline version>`. The builder no longer guesses a previous
tag. The v0.0.1 baseline tag is absent in the current repository, so do not
invent one merely to satisfy the command.

### Step 5: GitHub Actions Verification and Explicit Publication
The verification workflow does not publish or modify GitHub releases. After the
workflow has been deployed to the default branch, run it manually with the
version-matched tag, immutable baseline ref, baseline version, and channel. The
separate publication workflow requires explicit confirmation and protected
approval before creating a new release. It never overwrites an existing release
or asset. Required assets are:
- `delta-1.1.48-to-1.1.49.zip` (or `full-package.zip`)
- `delta.zip` (the generic Delta alias used by the backend provider)
- `manifest.json`
- `manifest.sig`
- `release-notes.md`

For the current v0.0.4 test fixture, use `v0.0.4` as a prerelease only. Supply
the approved immutable v0.0.1 baseline commit and `--from-version=0.0.1`; the
repository has no v0.0.1 tag, so no replacement tag may be invented. The
version-only fixture exercises discovery, download, RSA verification, hash
verification, version transition, restart, channel persistence, and `CURRENT`
status. It does not by itself prove backend.phar replacement or database
migrations; those require the already-recorded packaged Sandbox evidence.

---

## 5. Local Release Simulation & Testing

You can test the release generation and signing logic locally before pushing:

```bash
# Test Delta release packaging locally with an explicit immutable baseline
php scripts/build-release-package.php --tag=v1.1.48 --from-ref=<approved-baseline-commit> --from-version=1.1.47 --private-key=release/private_key.pem --output-dir=release/1.1.48

# Verify the signed package before any authorized publication
php scripts/verify-release-package.php --release-dir=release/1.1.48 --target-version=1.1.48 --minimum-version=1.1.47

# Run full release automation validation test suite
php scripts/test-release-workflow.php
```
