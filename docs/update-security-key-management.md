# POS Update Infrastructure — Cryptographic Key Management & Rotation Guide

## 1. Overview & Security Principles

The POS Update Infrastructure uses **Asymmetric RSA-2048 Cryptography** with **SHA-256 Digest Signatures** (`OPENSSL_ALGO_SHA256`) to guarantee that every release manifest and update package originates exclusively from authorized engineering signers.

```
                  ┌─────────────────────────────────────┐
                  │    Release Signing Environment      │
                  │   (GitHub Actions Secrets / HSM)   │
                  │        RSA-2048 Private Key         │
                  └──────────────────┬──────────────────┘
                                     │ Signs manifest.json
                                     ▼
                          ┌─────────────────────┐
                          │    manifest.sig     │
                          └──────────┬──────────┘
                                     │
             ┌───────────────────────┴───────────────────────┐
             │       POS Terminal Verification Engine        │
             │                                               │
             │   1. Primary Pinned Key (certs/public_key.pem)│
             │   2. Rotation Fallback (certs/public_key_v2)  │
             │                                               │
             │      openssl_verify(manifest, sig, pubKey)    │
             └───────────────────────────────────────────────┘
```

---

## 2. Key Architecture & Storage

| Component | Storage Location | Access Controls | Purpose |
| :--- | :--- | :--- | :--- |
| **Private Key** (`private_key.pem`) | CI/CD Encrypted Secrets (`MANIFEST_SIGNING_KEY`) | Signers only. **NEVER present on POS terminals**. | Cryptographically signs `manifest.json`. |
| **Primary Public Key** (`update_public_key.pem`) | `backend/certs/update_public_key.pem` | Read-only by POS runtime. | Verifies incoming release manifests. |
| **Secondary Rotation Keys** (`update_public_key_v*.pem`)| `backend/certs/` | Read-only by POS runtime. | Seamlessly enables multi-key zero-downtime key rotation. |

---

## 3. Cryptographic Requirements & Validations

1. **RSA Key Length**: Minimum **2048 bits** (enforced at verification time; keys < 2048 bits are rejected).
2. **Signature Algorithm**: Strictly **`OPENSSL_ALGO_SHA256`** (no weak MD5 or SHA-1 hashes permitted).
3. **Public Key Pinning**: Pinned locally inside the POS runtime directory structure. Terminals reject any dynamically injected, unpinned certificate authorities.
4. **Encoding**: Base64 encoded SHA-256 RSA signatures (`manifest.sig`).

---

## 4. Key Rotation Procedure (Zero-Downtime)

To rotate signing keys across thousands of production terminals without breaking ongoing update distribution:

### Phase 1: Generate New Keypair (V2)
```bash
# Generate new 2048-bit RSA private key
openssl genrsa -out certs/update_private_key_v2.pem 2048

# Extract public key
openssl rsa -in certs/update_private_key_v2.pem -pubout -out certs/update_public_key_v2.pem
```

### Phase 2: Deploy New Public Key via Delta Update
1. Include `backend/certs/update_public_key_v2.pem` in the next Delta Update release signed by the current **V1 Private Key**.
2. Terminals automatically discover all public keys in `backend/certs/*.pem`. Both V1 and V2 become trusted.

### Phase 3: Switch CI/CD Signing to V2
1. Update CI/CD secret `MANIFEST_SIGNING_KEY` with the **V2 Private Key**.
2. Releases signed with V2 will immediately verify on all updated terminals.
3. Legacy terminals will verify V1 updates until migrated to V2.

### Phase 4: Deprecate V1 Key
1. Once fleet telemetry confirms > 99.9% adoption, remove the deprecated V1 key file in a subsequent delta update.

---

## 5. Security Incident Response (Key Compromise)

If the release private key is suspected of being compromised:

1. **Revoke CI/CD Signing Access**: Immediately invalidate repository secrets and stop automated release pipelines.
2. **Generate Emergency V3 Keypair**.
3. **Emergency Recovery Push**:
   - Issue an out-of-band recovery update with the new pinned public key.
   - Self-healing update recovery service (`UpdateRecoveryService`) blocks unsigned manifests and escalates to system administrator.
