# Windows release signing checklist

Production Windows publishing uses electron-builder's supported `CSC_LINK` and
`CSC_KEY_PASSWORD` environment contract. CI receives those as GitHub Secrets;
the expected certificate subject is supplied as the non-secret
`WINDOWS_SIGNING_PUBLISHER` repository variable. No certificate file, password,
or key is stored in this repository.

1. Build frontend, PHAR, and desktop runtime.
2. Require signing configuration before packaging or publishing.
3. Let electron-builder sign the unpacked application executable and NSIS
   installer with SHA-256 and its timestamp-capable Windows signing flow.
4. Validate the final signed installer against `latest.yml` size and SHA-512.
5. Verify installer and application Authenticode status, publisher, and
   timestamp before upload.
6. Validate Delta `manifest.json` and `manifest.sig` independently with the
   RSA update key before upload.

`npm run electron:build` is intentionally available for unsigned local builds.
`npm run electron:publish` and the release workflow fail before packaging when
the production signing inputs are unavailable.
