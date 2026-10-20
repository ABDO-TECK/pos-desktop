# Beta download reliability

Approved scope: keep Setup 0.0.1 unchanged; publish beta.2 for 0.0.1 and beta.1; preserve customer data.

1. Reproduce transport failures after HTTP 200 and slow/interrupted HTTPS streams in an isolated fixture. Implement one shared HTTPS downloader: 15-minute total budget, connection/stall limits, three bounded attempts, partial-file cleanup, precise transport diagnostics, progress heartbeat. Keep TLS verification mandatory.
2. Keep backend request and renderer budgets consistent; renew the existing coordination lease while downloading. Test that delta failures return their actual cause without entering the Git fallback, including packaged LAN mode.
3. Build a one-time offline launcher with a pinned RSA key and signed repair engine. Use the installed bundled PHP and a temporary copy of its PHAR. Require the app closed, reject reparse paths, wrong baselines, signatures, migrations and unexpected targets. Reuse verified staging/snapshot/rollback; never connect to the business database.
4. Run tests and independent review. Build both baseline packages in Actions. Gate publication on signed package and repair acceptance against real baseline artifacts. Include repair asset and instructions.
5. Publish beta.2 only in pos-desktop-updates. Verify actual GitHub assets, beta/stable routing, changed-only payloads, unchanged Setup and original data hashes. Record limits honestly.
