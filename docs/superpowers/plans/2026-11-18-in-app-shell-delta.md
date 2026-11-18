# In-app Windows shell delta implementation plan

The Windows acceptance at `afd1a9c` verified a signed whole-file update and rollback on a stopped per-user POS installation. Extend that path through the admin Update Center. Exercise the exact packaged application and compare binary patch sizes only after the restart scenarios pass.

## Delivery and user action

- Discover the signed Format 2 manifest, signature and ZIP in the existing updates repository. Bind channel, source, target, physical inventory and data compatibility to the installed client before offering installation.
- Reuse authenticated admin discovery and staging. The renderer supplies a version selector. The host derives paths, trust and operation identity. Preserve ordinary app deltas and channel transitions.
- Check client capability. The immutable public Setup 0.0.13 predates the new supervisor. Report separately how that client obtains its first updater-capable build.

## Apply, restart and recovery

- Acquire the existing update interlock, refuse an active sale, quiesce product-owned processes and take a cold backup of database state. Keep writes fenced until the result is final.
- A supervisor outside the replaceable installation holds ownership through exit, application of the signed plan, restart and recovery. Authenticate source runtime files before execution.
- Reuse the whole-file transaction service. Commit only after verifying target identity, application UI readiness, the PHP backend and a live database query. Recover interrupted transactions idempotently.
- On failed apply, unresponsive startup or failed health checks, stop the owned target, restore the prior verified files and database state as required, restart the prior version and verify its health. Bound retries and preserve evidence when ownership or recovery cannot be proven.

## GitHub Actions acceptance

- Start the packaged application, create a disposable business record, sign in and trigger the update using the real Update Center. Use runner-only test trust and real signed assets.
- For a successful update, observe a new process, the target version, healthy services, a usable interface, the original business record and a committed transaction.
- Induce a genuine startup or health failure in a separate candidate. Observe unattended rollback, a healthy source version and intact business data without calling rollback from the harness.
- Exercise duplicate clicks, active sales, signed-package rejection and restart or crash boundaries in focused regressions. Record run ID, commit and exact coverage.

## Binary delta measurement

- Use the same source and target for every size comparison. Record their identities and hashes. Compare actual compressed whole-file delivery with generated binary patches and a hybrid that selects the smaller representation per file.
- Include the Electron executable, app.asar, backend.phar, other changed files and transport metadata. Reconstruct the target from the source and verify hashes. Report total download size, savings and measured costs with the tool version.
- Keep production delivery on whole files. The benchmark produces evidence for a subsequent deployment decision.
