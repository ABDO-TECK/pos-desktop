# Release signing

Current Windows Setup is unsigned Authenticode, as stated in release notes. verify-desktop-release.ps1 -RequireAuthenticode -ExpectedPublisher remains available for future certificate rollout; current CI does not claim trusted publisher status.

Delta updates require RSA-2048-or-stronger SHA-256 signatures. The existing update private key is stored as UPDATE_PRIVATE_KEY in Actions secrets on ABDO-TECK/pos-desktop and matches backend/certs/update_public_key.pem. No private key is committed or published. Repository migration does not rotate this key or remove verification.

The builder signs exact manifest bytes and verifies the signature immediately. The package verifier checks ZIP entries against signed SHA-256 hashes. The app binds signed target version/channel to the selected release and checks installed baseline hashes before changing files. Keep each baseline-specific manifest and signature together; see [release.md](release.md).
