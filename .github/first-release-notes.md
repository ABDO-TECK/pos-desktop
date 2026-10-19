## POS Desktop 0.0.1

First release from the clean `ABDO-TECK/pos-desktop` repository.

- Windows x64 Setup built by GitHub Actions from the tagged source.
- Embedded PHP 8.2.30, MariaDB 10.4.32, Java and QZ Tray 2.2.6.
- Includes fixes for catalog trigger diagnostics, session CSRF refresh, branch API paths and receipt printing.
- Existing business data and local credentials are not included in this installer or source repository.
- Local isolated acceptance covered cash/credit/reserved sales, stock and debt reversals, backups/restores and A4/80mm receipt PDFs. Physical paper output was not tested because no physical printer was installed.

Download `POS-Desktop-Setup-0.0.1.exe`. `SHA256SUMS.txt` contains the release asset checksums.

This initial v0 release is a prerelease. The Windows installer is **unsigned** (no Authenticode publisher certificate is configured); Windows may show an unknown-publisher or SmartScreen warning. No trusted-publisher claim is made.
