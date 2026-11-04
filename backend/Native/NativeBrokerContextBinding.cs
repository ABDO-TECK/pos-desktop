// backend/Native/NativeBrokerContextBinding.cs
//
// INTERNAL pure native broker context binding after authenticating VerifiedBrokerEnvelope.
// C# 5.0 / .NET Framework 4.8 x64 compatible. Namespace PosDesktop.Updates.Native.
//
// Architecture & Trust Boundaries:
// 1. Context Authority: NativeBrokerBindingContext contains trusted inputs configured
//    or observed by the independent host (compiled-host independent installed observations,
//    independently consented target, pinned exact raw manifest SHA-256).
//    DISCLAIMER: Context strings are trusted host inputs, NOT physical verification,
//    NOT a hardware or filesystem receipt, and NOT an anti-replay ledger or one-shot
//    execution ownership token.
// 2. Pure In-Memory Verification: This module performs zero filesystem I/O, zero native
//    process operations, zero database queries, zero ambient environment/time/PID/nonce
//    queries, zero staging, and zero network operations.
// 3. Execution Limitation (CanApply ALWAYS False): The returned NativeBrokerBoundResult
//    always declares CanApply = false. Successful binding proves only consistency
//    between the cryptographically authenticated envelope and the trusted host context.
//    It does NOT authorize apply, does NOT execute migrations, and does NOT spawn processes.
// 4. Enforce Exact Purpose: Only purpose 'regular' is supported. Bootstrap completion
//    (purpose 'bootstrap_completion') is rejected unconditionally.
// 5. Explicit Architecture Limits: This binding engine does NOT perform:
//    - Full inventory canonical digest calculation across all files
//    - Full path set or cross-inventory path collision validation
//    - Package archive, delta diff, or ZIP payload validation
//    - Database schema inspection, cold backup, or migration transaction execution
//    - Process tree inspection, termination, or quiescence enforcement
//    - Physical filesystem integrity or directory lease authorization.

namespace PosDesktop.Updates.Native
{
    using System;
    using System.Collections.Generic;
    using System.Collections.ObjectModel;
    using System.Globalization;
    using System.Security.Cryptography;
    using System.Text;
    using System.Text.RegularExpressions;

    /// <summary>
    /// Dedicated exception thrown when broker envelope context binding fails.
    /// Carries a stable machine-readable ReasonCode.
    /// </summary>
    public sealed class NativeBrokerBindingException : Exception
    {
        private readonly string _reasonCode;

        public NativeBrokerBindingException(string reasonCode, string message)
            : base(message)
        {
            this._reasonCode = reasonCode ?? "unknown";
        }

        public NativeBrokerBindingException(string reasonCode, string message, Exception innerException)
            : base(message, innerException)
        {
            this._reasonCode = reasonCode ?? "unknown";
        }

        public string ReasonCode
        {
            get { return this._reasonCode; }
        }
    }

    /// <summary>
    /// Frozen sealed identity of an installation endpoint (source or target).
    /// </summary>
    public sealed class NativeBrokerEndpointIdentity
    {
        private readonly string _appVersion;
        private readonly string _channel;
        private readonly string _nativeVersion;
        private readonly string _inventorySha256;
        private readonly string _nativeShellSha256;

        public NativeBrokerEndpointIdentity(
            string appVersion,
            string channel,
            string nativeVersion,
            string inventorySha256,
            string nativeShellSha256)
        {
            if (appVersion == null)
            {
                throw new ArgumentNullException("appVersion");
            }
            if (channel == null)
            {
                throw new ArgumentNullException("channel");
            }
            if (nativeVersion == null)
            {
                throw new ArgumentNullException("nativeVersion");
            }
            if (inventorySha256 == null)
            {
                throw new ArgumentNullException("inventorySha256");
            }
            if (nativeShellSha256 == null)
            {
                throw new ArgumentNullException("nativeShellSha256");
            }

            if (channel != "stable" && channel != "beta")
            {
                throw new ArgumentException("Channel must be exact string 'stable' or 'beta'.", "channel");
            }

            if (appVersion.Length < 1 || appVersion.Length > 128 || !NativeBrokerContextBinding.IsAsciiString(appVersion))
            {
                throw new ArgumentException("App version must be ASCII between 1 and 128 characters.", "appVersion");
            }

            if (!NativeBrokerContextBinding.IsValidCanonicalAppVersion(appVersion, channel))
            {
                throw new ArgumentException(
                    string.Format(CultureInfo.InvariantCulture, "App version '{0}' does not match canonical form for channel '{1}'.", appVersion, channel),
                    "appVersion");
            }

            if (nativeVersion.Length < 1 || nativeVersion.Length > 128 || !NativeBrokerContextBinding.IsAsciiString(nativeVersion))
            {
                throw new ArgumentException("Native version must be ASCII between 1 and 128 characters.", "nativeVersion");
            }

            if (!NativeBrokerContextBinding.IsValidSemver2(nativeVersion))
            {
                throw new ArgumentException(
                    string.Format(CultureInfo.InvariantCulture, "Native version '{0}' is not a valid SemVer 2.0 version.", nativeVersion),
                    "nativeVersion");
            }

            if (!NativeBrokerContextBinding.IsLowerHex64(inventorySha256))
            {
                throw new ArgumentException("Inventory SHA-256 must be a 64-character lowercase ASCII hex string.", "inventorySha256");
            }

            if (!NativeBrokerContextBinding.IsLowerHex64(nativeShellSha256))
            {
                throw new ArgumentException("Native shell SHA-256 must be a 64-character lowercase ASCII hex string.", "nativeShellSha256");
            }

            this._appVersion = appVersion;
            this._channel = channel;
            this._nativeVersion = nativeVersion;
            this._inventorySha256 = inventorySha256;
            this._nativeShellSha256 = nativeShellSha256;
        }

        public string AppVersion { get { return this._appVersion; } }
        public string Channel { get { return this._channel; } }
        public string NativeVersion { get { return this._nativeVersion; } }
        public string InventorySha256 { get { return this._inventorySha256; } }
        public string NativeShellSha256 { get { return this._nativeShellSha256; } }
    }

    /// <summary>
    /// Frozen sealed expected data contract.
    /// </summary>
    public sealed class NativeBrokerDataContractContext
    {
        public const long ContractFormat = 1L;
        public const string FixedMetadataMigration = "059_add_update_compatibility_metadata.sql";

        private readonly string _schemaSha256;
        private readonly string _ledgerSha256;

        public NativeBrokerDataContractContext(string schemaSha256, string ledgerSha256)
        {
            if (schemaSha256 == null)
            {
                throw new ArgumentNullException("schemaSha256");
            }
            if (ledgerSha256 == null)
            {
                throw new ArgumentNullException("ledgerSha256");
            }

            if (!NativeBrokerContextBinding.IsLowerHex64(schemaSha256))
            {
                throw new ArgumentException("Schema SHA-256 must be a 64-character lowercase ASCII hex string.", "schemaSha256");
            }
            if (!NativeBrokerContextBinding.IsLowerHex64(ledgerSha256))
            {
                throw new ArgumentException("Ledger SHA-256 must be a 64-character lowercase ASCII hex string.", "ledgerSha256");
            }

            this._schemaSha256 = schemaSha256;
            this._ledgerSha256 = ledgerSha256;
        }

        public long Format { get { return ContractFormat; } }
        public string MetadataMigration { get { return FixedMetadataMigration; } }
        public string SchemaSha256 { get { return this._schemaSha256; } }
        public string LedgerSha256 { get { return this._ledgerSha256; } }
    }

    /// <summary>
    /// Frozen sealed context supplied exclusively by trusted compiled host observations and policies.
    /// NEVER construct this by copying fields from an incoming manifest request.
    /// </summary>
    public sealed class NativeBrokerBindingContext
    {
        private readonly NativeBrokerEndpointIdentity _source;
        private readonly NativeBrokerEndpointIdentity _target;
        private readonly string _expectedManifestSha256;
        private readonly NativeBrokerDataContractContext _expectedDataContract;
        private readonly string _expectedKeyId;

        public NativeBrokerBindingContext(
            NativeBrokerEndpointIdentity source,
            NativeBrokerEndpointIdentity target,
            string expectedManifestSha256,
            NativeBrokerDataContractContext expectedDataContract,
            string expectedKeyId = null)
        {
            if (source == null)
            {
                throw new ArgumentNullException("source");
            }
            if (target == null)
            {
                throw new ArgumentNullException("target");
            }
            if (expectedManifestSha256 == null)
            {
                throw new ArgumentNullException("expectedManifestSha256");
            }
            if (expectedDataContract == null)
            {
                throw new ArgumentNullException("expectedDataContract");
            }

            if (!NativeBrokerContextBinding.IsLowerHex64(expectedManifestSha256))
            {
                throw new ArgumentException("Expected manifest SHA-256 must be a 64-character lowercase ASCII hex string.", "expectedManifestSha256");
            }

            if (source.Channel != target.Channel)
            {
                throw new ArgumentException(
                    string.Format(CultureInfo.InvariantCulture, "Source channel '{0}' must equal target channel '{1}'.", source.Channel, target.Channel),
                    "target");
            }

            if (expectedKeyId != null)
            {
                if (expectedKeyId.Length < 1 || expectedKeyId.Length > 64)
                {
                    throw new ArgumentException("Expected key ID length must be between 1 and 64 characters.", "expectedKeyId");
                }
                for (int i = 0; i < expectedKeyId.Length; i++)
                {
                    char c = expectedKeyId[i];
                    bool ok = (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || (c == '_');
                    if (!ok)
                    {
                        throw new ArgumentException("Expected key ID must match canonical pattern ^[a-z0-9_]{1,64}$.", "expectedKeyId");
                    }
                }
            }

            this._source = source;
            this._target = target;
            this._expectedManifestSha256 = expectedManifestSha256;
            this._expectedDataContract = expectedDataContract;
            this._expectedKeyId = expectedKeyId;
        }

        public NativeBrokerBindingContext(
            string sourceAppVersion,
            string sourceChannel,
            string sourceNativeVersion,
            string sourceInventorySha256,
            string sourceNativeShellSha256,
            string targetAppVersion,
            string targetChannel,
            string targetNativeVersion,
            string targetInventorySha256,
            string targetNativeShellSha256,
            string expectedManifestSha256,
            string schemaSha256,
            string ledgerSha256,
            string expectedKeyId = null)
            : this(
                new NativeBrokerEndpointIdentity(sourceAppVersion, sourceChannel, sourceNativeVersion, sourceInventorySha256, sourceNativeShellSha256),
                new NativeBrokerEndpointIdentity(targetAppVersion, targetChannel, targetNativeVersion, targetInventorySha256, targetNativeShellSha256),
                expectedManifestSha256,
                new NativeBrokerDataContractContext(schemaSha256, ledgerSha256),
                expectedKeyId)
        {
        }

        public NativeBrokerEndpointIdentity Source { get { return this._source; } }
        public NativeBrokerEndpointIdentity Target { get { return this._target; } }
        public string ExpectedManifestSha256 { get { return this._expectedManifestSha256; } }
        public NativeBrokerDataContractContext ExpectedDataContract { get { return this._expectedDataContract; } }
        public string ExpectedKeyId { get { return this._expectedKeyId; } }
    }

    /// <summary>
    /// Sealed immutable result produced when a VerifiedBrokerEnvelope is successfully
    /// bound to trusted host NativeBrokerBindingContext. CanApply is ALWAYS false.
    /// </summary>
    public sealed class NativeBrokerBoundResult
    {
        private readonly VerifiedBrokerEnvelope _envelope;
        private readonly NativeBrokerBindingContext _context;

        internal NativeBrokerBoundResult(VerifiedBrokerEnvelope envelope, NativeBrokerBindingContext context)
        {
            this._envelope = envelope;
            this._context = context;
        }

        public VerifiedBrokerEnvelope Envelope { get { return this._envelope; } }
        public NativeBrokerBindingContext Context { get { return this._context; } }
        public bool CanApply { get { return false; } }
    }

    /// <summary>
    /// Pure memory context binding engine for native broker updates.
    /// </summary>
    public static class NativeBrokerContextBinding
    {
        public const string RequiredPlatform = "win32";
        public const string RequiredArch = "x64";
        public const string RequiredOperation = "shell_delta";
        public const string SupportedPurpose = "regular";
        public const long RequiredDescriptorFormat = 2L;
        public const long RequiredUpdaterProtocol = 2L;
        public const long RequiredInventoryFormat = 1L;
        public const long RequiredDataContractFormat = 1L;
        public const long MaxSafeInteger = 9007199254740991L;
        public const int MaxInventoryFilesCount = 20000;

        private static readonly string[] RequiredPhysicalFiles = new string[]
        {
            "POS Desktop.exe",
            "resources/app.asar",
            "resources/app.asar.unpacked/version.json"
        };

        private static readonly string[] MandatoryShellFileNames = new string[]
        {
            "POS Desktop.exe",
            "resources/app.asar"
        };

        private static readonly string[] RequiredDescriptorKeys = new string[]
        {
            "format",
            "operation",
            "minimum_updater_protocol",
            "platform",
            "arch",
            "from_channel",
            "channel",
            "from_version",
            "version",
            "from_native_version",
            "native_version",
            "prerelease",
            "purpose",
            "source_inventory_sha256",
            "source_inventory",
            "target_inventory_sha256",
            "target_inventory",
            "source_native_shell",
            "target_native_shell",
            "files",
            "deleted_files",
            "package",
            "data_compatibility",
            "source_data_compatibility",
            "migrations"
        };

        private static readonly string[] RequiredInventoryKeys = new string[]
        {
            "arch",
            "files",
            "format",
            "native_shell",
            "native_version",
            "platform",
            "version"
        };

        private static readonly string[] RequiredDataContractKeys = new string[]
        {
            "format",
            "ledger_sha256",
            "metadata_migration",
            "schema_sha256"
        };

        private static readonly Regex SemVer2Regex = new Regex(
            @"\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9a-zA-Z-]*))*)?(?:\+[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*)?\z",
            RegexOptions.CultureInvariant,
            TimeSpan.FromMilliseconds(250));

        /// <summary>
        /// Binds an authenticated VerifiedBrokerEnvelope against trusted host context.
        /// </summary>
        public static NativeBrokerBoundResult Bind(VerifiedBrokerEnvelope envelope, NativeBrokerBindingContext context)
        {
            if (envelope == null)
            {
                throw new ArgumentNullException("envelope");
            }
            if (context == null)
            {
                throw new ArgumentNullException("context");
            }

            // 1. PIN CHECK: Exact raw manifest SHA-256 match enforced BEFORE inspecting fields.
            if (!string.Equals(envelope.ManifestSha256, context.ExpectedManifestSha256, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "manifest_sha256_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Envelope manifest SHA-256 '{0}' does not match expected trusted pin '{1}'.", envelope.ManifestSha256, context.ExpectedManifestSha256));
            }

            // Optional trusted key ID match if context specifies expectedKeyId
            if (context.ExpectedKeyId != null &&
                !string.Equals(envelope.SelectedTrustedKeyId, context.ExpectedKeyId, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "trusted_key_id_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Envelope authenticated with key '{0}', expected trusted key '{1}'.", envelope.SelectedTrustedKeyId, context.ExpectedKeyId));
            }

            BrokerJsonObject root = envelope.Root;

            // 2. ROOT DESCRIPTOR KEYS: Exact 25 keys required (PHP regular knownKeys 145..164).
            if (root.Count != RequiredDescriptorKeys.Length)
            {
                throw new NativeBrokerBindingException(
                    "descriptor_keys_invalid",
                    string.Format(CultureInfo.InvariantCulture, "Root descriptor must contain exactly {0} keys, found {1}.", RequiredDescriptorKeys.Length, root.Count));
            }
            for (int i = 0; i < RequiredDescriptorKeys.Length; i++)
            {
                string key = RequiredDescriptorKeys[i];
                if (!root.ContainsKey(key))
                {
                    throw new NativeBrokerBindingException(
                        "descriptor_keys_invalid",
                        string.Format(CultureInfo.InvariantCulture, "Root descriptor is missing required key '{0}'.", key));
                }
            }

            // 3. MINIMUM ENVELOPE SCALARS & PURPOSE:
            long format = GetRequiredInt64(root, "format", "format_unsupported");
            if (format != RequiredDescriptorFormat)
            {
                throw new NativeBrokerBindingException(
                    "format_unsupported",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor format {0} is unsupported; expected {1}.", format, RequiredDescriptorFormat));
            }

            string op = GetRequiredString(root, "operation", "operation_invalid");
            if (op != RequiredOperation)
            {
                throw new NativeBrokerBindingException(
                    "operation_invalid",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor operation '{0}' is unsupported; expected '{1}'.", op, RequiredOperation));
            }

            long protocol = GetRequiredInt64(root, "minimum_updater_protocol", "protocol_unsupported");
            if (protocol != RequiredUpdaterProtocol)
            {
                throw new NativeBrokerBindingException(
                    "protocol_unsupported",
                    string.Format(CultureInfo.InvariantCulture, "Minimum updater protocol {0} is unsupported; expected {1}.", protocol, RequiredUpdaterProtocol));
            }

            string platform = GetRequiredString(root, "platform", "platform_unsupported");
            if (platform != RequiredPlatform)
            {
                throw new NativeBrokerBindingException(
                    "platform_unsupported",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor platform '{0}' is unsupported; expected '{1}'.", platform, RequiredPlatform));
            }

            string arch = GetRequiredString(root, "arch", "arch_unsupported");
            if (arch != RequiredArch)
            {
                throw new NativeBrokerBindingException(
                    "arch_unsupported",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor arch '{0}' is unsupported; expected '{1}'.", arch, RequiredArch));
            }

            string purpose = GetRequiredString(root, "purpose", "purpose_invalid");
            if (purpose != SupportedPurpose)
            {
                throw new NativeBrokerBindingException(
                    "purpose_unsupported",
                    string.Format(CultureInfo.InvariantCulture, "Purpose '{0}' is rejected; only '{1}' is supported.", purpose, SupportedPurpose));
            }

            // 4. CHANNELS & PRERELEASE:
            string fromChannel = GetRequiredString(root, "from_channel", "channel_invalid");
            string toChannel = GetRequiredString(root, "channel", "channel_invalid");

            if (fromChannel != "stable" && fromChannel != "beta")
            {
                throw new NativeBrokerBindingException("channel_invalid", string.Format(CultureInfo.InvariantCulture, "from_channel '{0}' is invalid.", fromChannel));
            }
            if (toChannel != "stable" && toChannel != "beta")
            {
                throw new NativeBrokerBindingException("channel_invalid", string.Format(CultureInfo.InvariantCulture, "channel '{0}' is invalid.", toChannel));
            }
            if (fromChannel != toChannel)
            {
                throw new NativeBrokerBindingException(
                    "channel_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Channel mismatch: from_channel '{0}' vs channel '{1}'.", fromChannel, toChannel));
            }
            if (fromChannel != context.Source.Channel)
            {
                throw new NativeBrokerBindingException(
                    "channel_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor from_channel '{0}' does not match context source channel '{1}'.", fromChannel, context.Source.Channel));
            }
            if (toChannel != context.Target.Channel)
            {
                throw new NativeBrokerBindingException(
                    "channel_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor channel '{0}' does not match context target channel '{1}'.", toChannel, context.Target.Channel));
            }

            BrokerJsonNode prereleaseNode = root["prerelease"];
            if (prereleaseNode.Kind != BrokerJsonKind.Boolean)
            {
                throw new NativeBrokerBindingException(
                    "prerelease_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "prerelease must be a boolean node, found '{0}'.", prereleaseNode.Kind));
            }
            bool prerelease = prereleaseNode.GetBoolean();
            bool expectedPrerelease = (toChannel == "beta");
            if (prerelease != expectedPrerelease)
            {
                throw new NativeBrokerBindingException(
                    "prerelease_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "prerelease {0} does not match channel '{1}' expectation ({2}).", prerelease, toChannel, expectedPrerelease));
            }

            // 5. APP VERSIONS & PROGRESSION:
            string fromVersion = GetRequiredString(root, "from_version", "semver_invalid");
            string toVersion = GetRequiredString(root, "version", "semver_invalid");

            if (fromVersion != context.Source.AppVersion)
            {
                throw new NativeBrokerBindingException(
                    "source_version_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor from_version '{0}' does not match context source appVersion '{1}'.", fromVersion, context.Source.AppVersion));
            }
            if (toVersion != context.Target.AppVersion)
            {
                throw new NativeBrokerBindingException(
                    "target_version_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor version '{0}' does not match context target appVersion '{1}'.", toVersion, context.Target.AppVersion));
            }

            CanonicalAppVersion parsedFromApp;
            if (!TryParseCanonicalAppVersion(fromVersion, fromChannel, out parsedFromApp))
            {
                throw new NativeBrokerBindingException("semver_invalid", string.Format(CultureInfo.InvariantCulture, "from_version '{0}' is not canonical for channel '{1}'.", fromVersion, fromChannel));
            }

            CanonicalAppVersion parsedToApp;
            if (!TryParseCanonicalAppVersion(toVersion, toChannel, out parsedToApp))
            {
                throw new NativeBrokerBindingException("semver_invalid", string.Format(CultureInfo.InvariantCulture, "version '{0}' is not canonical for channel '{1}'.", toVersion, toChannel));
            }

            // Strict progression check: target MUST be strictly newer than source
            if (CompareAppVersions(parsedFromApp, parsedToApp) <= 0)
            {
                throw new NativeBrokerBindingException(
                    "downgrade_rejected",
                    string.Format(CultureInfo.InvariantCulture, "Target app version '{0}' must be strictly newer than source app version '{1}'.", toVersion, fromVersion));
            }

            // 6. NATIVE VERSIONS:
            string fromNativeVer = GetRequiredString(root, "from_native_version", "semver_invalid");
            string toNativeVer = GetRequiredString(root, "native_version", "semver_invalid");

            if (fromNativeVer != context.Source.NativeVersion)
            {
                throw new NativeBrokerBindingException(
                    "source_native_version_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor from_native_version '{0}' does not match context source nativeVersion '{1}'.", fromNativeVer, context.Source.NativeVersion));
            }
            if (toNativeVer != context.Target.NativeVersion)
            {
                throw new NativeBrokerBindingException(
                    "target_native_version_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Descriptor native_version '{0}' does not match context target nativeVersion '{1}'.", toNativeVer, context.Target.NativeVersion));
            }

            if (!IsValidSemver2(fromNativeVer))
            {
                throw new NativeBrokerBindingException("semver_invalid", string.Format(CultureInfo.InvariantCulture, "from_native_version '{0}' is not valid SemVer 2.0.", fromNativeVer));
            }
            if (!IsValidSemver2(toNativeVer))
            {
                throw new NativeBrokerBindingException("semver_invalid", string.Format(CultureInfo.InvariantCulture, "native_version '{0}' is not valid SemVer 2.0.", toNativeVer));
            }

            // 7. INVENTORY HASH MATCHES:
            string srcInvHash = GetRequiredString(root, "source_inventory_sha256", "source_inventory_hash_invalid");
            string tgtInvHash = GetRequiredString(root, "target_inventory_sha256", "target_inventory_hash_invalid");

            if (!IsLowerHex64(srcInvHash))
            {
                throw new NativeBrokerBindingException("source_inventory_hash_invalid", "source_inventory_sha256 is not 64-character lowercase hex.");
            }
            if (!IsLowerHex64(tgtInvHash))
            {
                throw new NativeBrokerBindingException("target_inventory_hash_invalid", "target_inventory_sha256 is not 64-character lowercase hex.");
            }

            if (!string.Equals(srcInvHash, context.Source.InventorySha256, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "source_inventory_hash_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "source_inventory_sha256 '{0}' does not match context source '{1}'.", srcInvHash, context.Source.InventorySha256));
            }
            if (!string.Equals(tgtInvHash, context.Target.InventorySha256, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "target_inventory_hash_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "target_inventory_sha256 '{0}' does not match context target '{1}'.", tgtInvHash, context.Target.InventorySha256));
            }

            // 8. STRUCTURAL SHAPE OF FILES, DELETED_FILES, PACKAGE, MIGRATIONS:
            BrokerJsonNode filesNode = root["files"];
            if (filesNode.Kind != BrokerJsonKind.Array)
            {
                throw new NativeBrokerBindingException("files_invalid", "Descriptor 'files' must be an Array.");
            }

            BrokerJsonNode deletedFilesNode = root["deleted_files"];
            if (deletedFilesNode.Kind != BrokerJsonKind.Array)
            {
                throw new NativeBrokerBindingException("deleted_files_invalid", "Descriptor 'deleted_files' must be an Array.");
            }

            BrokerJsonNode packageNode = root["package"];
            if (packageNode.Kind != BrokerJsonKind.Object)
            {
                throw new NativeBrokerBindingException("package_invalid", "Descriptor 'package' must be an Object.");
            }

            BrokerJsonNode migrationsNode = root["migrations"];
            if (migrationsNode.Kind != BrokerJsonKind.Array)
            {
                throw new NativeBrokerBindingException("migrations_unsupported", "Descriptor 'migrations' must be an Array.");
            }
            if (migrationsNode.AsArray().Count != 0)
            {
                throw new NativeBrokerBindingException("migrations_unsupported", "Descriptor 'migrations' must be empty; database migrations are unsupported.");
            }

            // 9. SOURCE & TARGET INVENTORY PROOFS:
            BrokerJsonObject srcInv = GetRequiredObject(root, "source_inventory", "source_inventory_invalid");
            ValidateInventoryObject(srcInv, fromVersion, fromNativeVer, context.Source.NativeShellSha256, "source_inventory");

            BrokerJsonObject tgtInv = GetRequiredObject(root, "target_inventory", "target_inventory_invalid");
            ValidateInventoryObject(tgtInv, toVersion, toNativeVer, context.Target.NativeShellSha256, "target_inventory");

            // 10. ROOT NATIVE SHELL PROOF EXACT MATCH WITH INVENTORY NATIVE SHELL:
            BrokerJsonObject rootSrcShell = GetRequiredObject(root, "source_native_shell", "shell_proof_mismatch");
            BrokerJsonObject invSrcShell = GetRequiredObject(srcInv, "native_shell", "source_inventory_invalid");
            if (!MatchNativeShellObjects(rootSrcShell, invSrcShell))
            {
                throw new NativeBrokerBindingException("shell_proof_mismatch", "source_native_shell does not semantically match source_inventory.native_shell.");
            }

            BrokerJsonObject rootTgtShell = GetRequiredObject(root, "target_native_shell", "shell_proof_mismatch");
            BrokerJsonObject invTgtShell = GetRequiredObject(tgtInv, "native_shell", "target_inventory_invalid");
            if (!MatchNativeShellObjects(rootTgtShell, invTgtShell))
            {
                throw new NativeBrokerBindingException("shell_proof_mismatch", "target_native_shell does not semantically match target_inventory.native_shell.");
            }

            // 11. DATA COMPATIBILITY CONTRACT MATCHES:
            BrokerJsonObject srcDataCompat = GetRequiredObject(root, "source_data_compatibility", "source_data_contract_invalid");
            ValidateDataContract(srcDataCompat, context.ExpectedDataContract, "source_data_contract_invalid");

            BrokerJsonObject tgtDataCompat = GetRequiredObject(root, "data_compatibility", "data_contract_invalid");
            ValidateDataContract(tgtDataCompat, context.ExpectedDataContract, "data_contract_invalid");

            // Require both contracts to be identical (no schema/ledger mutation permitted)
            string srcSchemaSha = GetRequiredString(srcDataCompat, "schema_sha256", "source_data_contract_invalid");
            string tgtSchemaSha = GetRequiredString(tgtDataCompat, "schema_sha256", "data_contract_invalid");
            string srcLedgerSha = GetRequiredString(srcDataCompat, "ledger_sha256", "source_data_contract_invalid");
            string tgtLedgerSha = GetRequiredString(tgtDataCompat, "ledger_sha256", "data_contract_invalid");

            if (!string.Equals(srcSchemaSha, tgtSchemaSha, StringComparison.Ordinal) ||
                !string.Equals(srcLedgerSha, tgtLedgerSha, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException("data_contract_mismatch", "source_data_compatibility and data_compatibility must be identical.");
            }

            return new NativeBrokerBoundResult(envelope, context);
        }

        private static void ValidateInventoryObject(
            BrokerJsonObject inventory,
            string expectedAppVersion,
            string expectedNativeVersion,
            string expectedShellSha,
            string label)
        {
            // Exact Format 1 seven keys enforced
            if (inventory.Count != RequiredInventoryKeys.Length)
            {
                throw new NativeBrokerBindingException(
                    "inventory_keys_invalid",
                    string.Format(CultureInfo.InvariantCulture, "{0} must contain exactly {1} keys, found {2}.", label, RequiredInventoryKeys.Length, inventory.Count));
            }
            for (int i = 0; i < RequiredInventoryKeys.Length; i++)
            {
                string key = RequiredInventoryKeys[i];
                if (!inventory.ContainsKey(key))
                {
                    throw new NativeBrokerBindingException(
                        "inventory_keys_invalid",
                        string.Format(CultureInfo.InvariantCulture, "{0} is missing required key '{1}'.", label, key));
                }
            }

            long format = GetRequiredInt64(inventory, "format", "inventory_format_unsupported");
            if (format != RequiredInventoryFormat)
            {
                throw new NativeBrokerBindingException(
                    "inventory_format_unsupported",
                    string.Format(CultureInfo.InvariantCulture, "{0} format {1} is unsupported; expected {2}.", label, format, RequiredInventoryFormat));
            }

            string platform = GetRequiredString(inventory, "platform", "inventory_platform_unsupported");
            if (platform != RequiredPlatform)
            {
                throw new NativeBrokerBindingException("inventory_platform_unsupported", string.Format(CultureInfo.InvariantCulture, "{0} platform '{1}' must be '{2}'.", label, platform, RequiredPlatform));
            }

            string arch = GetRequiredString(inventory, "arch", "inventory_arch_unsupported");
            if (arch != RequiredArch)
            {
                throw new NativeBrokerBindingException("inventory_arch_unsupported", string.Format(CultureInfo.InvariantCulture, "{0} arch '{1}' must be '{2}'.", label, arch, RequiredArch));
            }

            string appVer = GetRequiredString(inventory, "version", "inventory_version_invalid");
            if (!IsValidSemver2(appVer))
            {
                throw new NativeBrokerBindingException("inventory_version_invalid", string.Format(CultureInfo.InvariantCulture, "{0} version '{1}' is not valid SemVer.", label, appVer));
            }
            if (!string.Equals(appVer, expectedAppVersion, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "inventory_version_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "{0} version '{1}' does not match expected '{2}'.", label, appVer, expectedAppVersion));
            }

            string nativeVer = GetRequiredString(inventory, "native_version", "inventory_native_version_invalid");
            if (!IsValidSemver2(nativeVer))
            {
                throw new NativeBrokerBindingException("inventory_native_version_invalid", string.Format(CultureInfo.InvariantCulture, "{0} native_version '{1}' is not valid SemVer.", label, nativeVer));
            }
            if (!string.Equals(nativeVer, expectedNativeVersion, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "inventory_native_version_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "{0} native_version '{1}' does not match expected '{2}'.", label, nativeVer, expectedNativeVersion));
            }

            // Validate native_shell proof
            BrokerJsonObject nativeShell = GetRequiredObject(inventory, "native_shell", "native_shell_invalid");
            ValidateNativeShellProof(nativeShell, expectedShellSha, label + ".native_shell");

            // Validate inventory files object
            BrokerJsonObject filesObj = GetRequiredObject(inventory, "files", "inventory_files_invalid");
            if (filesObj.Count < 1 || filesObj.Count > MaxInventoryFilesCount)
            {
                throw new NativeBrokerBindingException(
                    "inventory_files_invalid",
                    string.Format(CultureInfo.InvariantCulture, "{0} files count {1} must be between 1 and {2}.", label, filesObj.Count, MaxInventoryFilesCount));
            }

            // Required physical files exist in files
            for (int i = 0; i < RequiredPhysicalFiles.Length; i++)
            {
                string req = RequiredPhysicalFiles[i];
                if (!filesObj.ContainsKey(req))
                {
                    throw new NativeBrokerBindingException(
                        "inventory_missing_required_file",
                        string.Format(CultureInfo.InvariantCulture, "{0} files is missing required physical file '{1}'.", label, req));
                }
            }

            // Retained shell entries in files must match native_shell.files
            BrokerJsonObject shellFiles = GetRequiredObject(nativeShell, "files", "native_shell_invalid");
            for (int i = 0; i < MandatoryShellFileNames.Length; i++)
            {
                string fname = MandatoryShellFileNames[i];
                BrokerJsonObject fEntry = GetRequiredObject(filesObj, fname, "native_shell_file_mismatch");
                if (fEntry.Count != 2 || !fEntry.ContainsKey("sha256") || !fEntry.ContainsKey("size"))
                {
                    throw new NativeBrokerBindingException(
                        "native_shell_file_mismatch",
                        string.Format(CultureInfo.InvariantCulture, "{0} files['{1}'] must contain exactly 2 keys: 'sha256' and 'size'.", label, fname));
                }
                BrokerJsonObject sEntry = GetRequiredObject(shellFiles, fname, "native_shell_invalid");

                string fHash = GetRequiredString(fEntry, "sha256", "native_shell_file_mismatch");
                string sHash = GetRequiredString(sEntry, "sha256", "native_shell_invalid");
                long fSize = GetRequiredSafeInteger(fEntry, "size", "native_shell_file_mismatch");
                long sSize = GetRequiredSafeInteger(sEntry, "size", "native_shell_invalid");

                if (!string.Equals(fHash, sHash, StringComparison.Ordinal) || fSize != sSize)
                {
                    throw new NativeBrokerBindingException(
                        "native_shell_file_mismatch",
                        string.Format(CultureInfo.InvariantCulture, "{0} files['{1}'] metadata does not match native_shell.files['{1}'].", label, fname));
                }
            }
        }

        private static void ValidateNativeShellProof(BrokerJsonObject nativeShell, string expectedShellSha, string label)
        {
            if (nativeShell.Count != 2)
            {
                throw new NativeBrokerBindingException("native_shell_invalid", string.Format(CultureInfo.InvariantCulture, "{0} must contain exactly 2 keys (files, sha256).", label));
            }
            if (!nativeShell.ContainsKey("files") || !nativeShell.ContainsKey("sha256"))
            {
                throw new NativeBrokerBindingException("native_shell_invalid", string.Format(CultureInfo.InvariantCulture, "{0} must contain keys 'files' and 'sha256'.", label));
            }

            string declaredSha = GetRequiredString(nativeShell, "sha256", "native_shell_invalid");
            if (!IsLowerHex64(declaredSha))
            {
                throw new NativeBrokerBindingException("native_shell_invalid", string.Format(CultureInfo.InvariantCulture, "{0} sha256 is not 64-character lowercase hex.", label));
            }

            BrokerJsonObject shellFiles = GetRequiredObject(nativeShell, "files", "native_shell_invalid");
            if (shellFiles.Count != MandatoryShellFileNames.Length)
            {
                throw new NativeBrokerBindingException(
                    "native_shell_invalid",
                    string.Format(CultureInfo.InvariantCulture, "{0} files must contain exactly {1} shell entries.", label, MandatoryShellFileNames.Length));
            }
            for (int i = 0; i < MandatoryShellFileNames.Length; i++)
            {
                string fname = MandatoryShellFileNames[i];
                if (!shellFiles.ContainsKey(fname))
                {
                    throw new NativeBrokerBindingException(
                        "native_shell_invalid",
                        string.Format(CultureInfo.InvariantCulture, "{0} files is missing required shell file '{1}'.", label, fname));
                }
            }

            string exeSha = null;
            string asarSha = null;

            for (int i = 0; i < MandatoryShellFileNames.Length; i++)
            {
                string fname = MandatoryShellFileNames[i];
                BrokerJsonObject fmeta = GetRequiredObject(shellFiles, fname, "native_shell_invalid");
                if (fmeta.Count != 2 || !fmeta.ContainsKey("sha256") || !fmeta.ContainsKey("size"))
                {
                    throw new NativeBrokerBindingException("native_shell_invalid", string.Format(CultureInfo.InvariantCulture, "{0} files['{1}'] must contain exact keys 'sha256' and 'size'.", label, fname));
                }

                string hash = GetRequiredString(fmeta, "sha256", "native_shell_invalid");
                if (!IsLowerHex64(hash))
                {
                    throw new NativeBrokerBindingException("native_shell_invalid", string.Format(CultureInfo.InvariantCulture, "{0} files['{1}'] sha256 is not 64-character lowercase hex.", label, fname));
                }

                // Validates integer 0..9007199254740991 without fractions or exponents
                GetRequiredSafeInteger(fmeta, "size", "native_shell_invalid");

                if (fname == "POS Desktop.exe")
                {
                    exeSha = hash;
                }
                else if (fname == "resources/app.asar")
                {
                    asarSha = hash;
                }
            }

            string computedDigest = ComputeCanonicalShellDigest(exeSha, asarSha);
            if (!string.Equals(declaredSha, computedDigest, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "native_shell_digest_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "{0} declared digest '{1}' does not match computed '{2}'.", label, declaredSha, computedDigest));
            }

            if (!string.Equals(declaredSha, expectedShellSha, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "native_shell_digest_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "{0} digest '{1}' does not match expected trusted context '{2}'.", label, declaredSha, expectedShellSha));
            }
        }

        private static bool MatchNativeShellObjects(BrokerJsonObject a, BrokerJsonObject b)
        {
            if (a == null || b == null)
            {
                return false;
            }
            if (a.Count != 2 || b.Count != 2)
            {
                return false;
            }
            if (!a.ContainsKey("files") || !a.ContainsKey("sha256") ||
                !b.ContainsKey("files") || !b.ContainsKey("sha256"))
            {
                return false;
            }

            string aSha = a["sha256"].Kind == BrokerJsonKind.String ? a["sha256"].GetString() : null;
            string bSha = b["sha256"].Kind == BrokerJsonKind.String ? b["sha256"].GetString() : null;
            if (!string.Equals(aSha, bSha, StringComparison.Ordinal))
            {
                return false;
            }

            if (a["files"].Kind != BrokerJsonKind.Object || b["files"].Kind != BrokerJsonKind.Object)
            {
                return false;
            }

            BrokerJsonObject aFiles = (BrokerJsonObject)a["files"];
            BrokerJsonObject bFiles = (BrokerJsonObject)b["files"];
            if (aFiles.Count != MandatoryShellFileNames.Length || bFiles.Count != MandatoryShellFileNames.Length)
            {
                return false;
            }

            for (int i = 0; i < MandatoryShellFileNames.Length; i++)
            {
                string fname = MandatoryShellFileNames[i];
                if (!aFiles.ContainsKey(fname) || !bFiles.ContainsKey(fname))
                {
                    return false;
                }
                if (aFiles[fname].Kind != BrokerJsonKind.Object || bFiles[fname].Kind != BrokerJsonKind.Object)
                {
                    return false;
                }

                BrokerJsonObject aMeta = (BrokerJsonObject)aFiles[fname];
                BrokerJsonObject bMeta = (BrokerJsonObject)bFiles[fname];
                if (aMeta.Count != 2 || bMeta.Count != 2 ||
                    !aMeta.ContainsKey("sha256") || !aMeta.ContainsKey("size") ||
                    !bMeta.ContainsKey("sha256") || !bMeta.ContainsKey("size"))
                {
                    return false;
                }

                string aHash = aMeta["sha256"].Kind == BrokerJsonKind.String ? aMeta["sha256"].GetString() : null;
                string bHash = bMeta["sha256"].Kind == BrokerJsonKind.String ? bMeta["sha256"].GetString() : null;
                if (!string.Equals(aHash, bHash, StringComparison.Ordinal))
                {
                    return false;
                }

                if (aMeta["size"].Kind != BrokerJsonKind.Number || bMeta["size"].Kind != BrokerJsonKind.Number)
                {
                    return false;
                }

                string aLex = aMeta["size"].GetNumberLexeme();
                string bLex = bMeta["size"].GetNumberLexeme();
                if (!string.Equals(aLex, bLex, StringComparison.Ordinal))
                {
                    return false;
                }
            }

            return true;
        }

        private static void ValidateDataContract(BrokerJsonObject contract, NativeBrokerDataContractContext expectedContract, string reasonCode)
        {
            if (contract.Count != RequiredDataContractKeys.Length)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Data contract must contain exactly {0} keys, found {1}.", RequiredDataContractKeys.Length, contract.Count));
            }
            for (int i = 0; i < RequiredDataContractKeys.Length; i++)
            {
                string key = RequiredDataContractKeys[i];
                if (!contract.ContainsKey(key))
                {
                    throw new NativeBrokerBindingException(
                        reasonCode,
                        string.Format(CultureInfo.InvariantCulture, "Data contract is missing required key '{0}'.", key));
                }
            }

            long format = GetRequiredInt64(contract, "format", reasonCode);
            if (format != expectedContract.Format)
            {
                throw new NativeBrokerBindingException(reasonCode, string.Format(CultureInfo.InvariantCulture, "Data contract format {0} is unsupported; expected {1}.", format, expectedContract.Format));
            }

            string metaMigration = GetRequiredString(contract, "metadata_migration", reasonCode);
            if (!string.Equals(metaMigration, expectedContract.MetadataMigration, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Data contract metadata_migration '{0}' does not match expected '{1}'.", metaMigration, expectedContract.MetadataMigration));
            }

            string schemaSha = GetRequiredString(contract, "schema_sha256", reasonCode);
            if (!IsLowerHex64(schemaSha))
            {
                throw new NativeBrokerBindingException(reasonCode, "Data contract schema_sha256 is not 64-character lowercase hex.");
            }
            if (!string.Equals(schemaSha, expectedContract.SchemaSha256, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "data_contract_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Data contract schema_sha256 '{0}' does not match expected '{1}'.", schemaSha, expectedContract.SchemaSha256));
            }

            string ledgerSha = GetRequiredString(contract, "ledger_sha256", reasonCode);
            if (!IsLowerHex64(ledgerSha))
            {
                throw new NativeBrokerBindingException(reasonCode, "Data contract ledger_sha256 is not 64-character lowercase hex.");
            }
            if (!string.Equals(ledgerSha, expectedContract.LedgerSha256, StringComparison.Ordinal))
            {
                throw new NativeBrokerBindingException(
                    "data_contract_mismatch",
                    string.Format(CultureInfo.InvariantCulture, "Data contract ledger_sha256 '{0}' does not match expected '{1}'.", ledgerSha, expectedContract.LedgerSha256));
            }
        }

        /// <summary>
        /// Computes canonical retained-shell combined digest.
        /// Formula: sha256(JSON{"POS Desktop.exe":"<exeHash>","resources/app.asar":"<asarHash>"} keys ordered).
        /// Follows auth module pattern: primary captured (no immediate rethrow), cleanup captured independently,
        /// throws AggregateException if both fail, exact primary if only, cleanup if only.
        /// </summary>
        public static string ComputeCanonicalShellDigest(string exeHash, string asarHash)
        {
            if (exeHash == null)
            {
                throw new ArgumentNullException("exeHash");
            }
            if (!IsLowerHex64(exeHash))
            {
                throw new ArgumentException("exeHash must be a 64-character lowercase ASCII hex string.", "exeHash");
            }
            if (asarHash == null)
            {
                throw new ArgumentNullException("asarHash");
            }
            if (!IsLowerHex64(asarHash))
            {
                throw new ArgumentException("asarHash must be a 64-character lowercase ASCII hex string.", "asarHash");
            }

            string payload = string.Format(
                CultureInfo.InvariantCulture,
                "{{\"POS Desktop.exe\":\"{0}\",\"resources/app.asar\":\"{1}\"}}",
                exeHash,
                asarHash);

            byte[] payloadBytes = Encoding.UTF8.GetBytes(payload);

            SHA256 sha256 = null;
            Exception shaPrimary = null;
            Exception shaCleanup = null;
            byte[] hashBytes = null;

            try
            {
                sha256 = SHA256.Create();
                hashBytes = sha256.ComputeHash(payloadBytes);
            }
            catch (Exception ex)
            {
                shaPrimary = ex;
            }
            finally
            {
                if (sha256 != null)
                {
                    try
                    {
                        ((IDisposable)sha256).Dispose();
                    }
                    catch (Exception ex)
                    {
                        shaCleanup = ex;
                    }
                }
            }

            if (shaPrimary != null && shaCleanup != null)
            {
                throw new AggregateException("SHA-256 computation and cleanup failed.", shaPrimary, shaCleanup);
            }
            if (shaPrimary != null)
            {
                throw shaPrimary;
            }
            if (shaCleanup != null)
            {
                throw shaCleanup;
            }
            if (hashBytes == null)
            {
                throw new CryptographicException("SHA-256 computation produced null output.");
            }

            var sb = new StringBuilder(64);
            for (int i = 0; i < hashBytes.Length; i++)
            {
                sb.Append(hashBytes[i].ToString("x2", CultureInfo.InvariantCulture));
            }
            return sb.ToString();
        }

        public static bool IsLowerHex64(string s)
        {
            if (s == null || s.Length != 64)
            {
                return false;
            }
            for (int i = 0; i < 64; i++)
            {
                char c = s[i];
                bool ok = (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f');
                if (!ok)
                {
                    return false;
                }
            }
            return true;
        }

        public static bool IsAsciiString(string s)
        {
            if (s == null)
            {
                return false;
            }
            for (int i = 0; i < s.Length; i++)
            {
                if (s[i] > 127)
                {
                    return false;
                }
            }
            return true;
        }

        public static bool IsValidSemver2(string version)
        {
            if (string.IsNullOrEmpty(version) || version.Length > 128 || !IsAsciiString(version))
            {
                return false;
            }
            return SemVer2Regex.IsMatch(version);
        }

        public static bool IsValidCanonicalAppVersion(string version, string expectedChannel)
        {
            CanonicalAppVersion parsed;
            return TryParseCanonicalAppVersion(version, expectedChannel, out parsed);
        }

        public static bool IsTargetStrictlyNewer(string sourceVersion, string sourceChannel, string targetVersion, string targetChannel)
        {
            if (sourceChannel != targetChannel)
            {
                return false;
            }
            CanonicalAppVersion src;
            if (!TryParseCanonicalAppVersion(sourceVersion, sourceChannel, out src))
            {
                return false;
            }
            CanonicalAppVersion tgt;
            if (!TryParseCanonicalAppVersion(targetVersion, targetChannel, out tgt))
            {
                return false;
            }
            return CompareAppVersions(src, tgt) > 0;
        }

        private struct CanonicalAppVersion
        {
            public readonly string Major;
            public readonly string Minor;
            public readonly string Patch;
            public readonly bool IsBeta;
            public readonly string BetaNumber;

            public CanonicalAppVersion(string major, string minor, string patch, bool isBeta, string betaNumber)
            {
                this.Major = major;
                this.Minor = minor;
                this.Patch = patch;
                this.IsBeta = isBeta;
                this.BetaNumber = betaNumber;
            }
        }

        private static bool IsCanonicalDecimalString(string s)
        {
            if (string.IsNullOrEmpty(s))
            {
                return false;
            }
            if (s.Length > 1 && s[0] == '0')
            {
                return false; // Leading zero forbidden
            }
            for (int i = 0; i < s.Length; i++)
            {
                if (s[i] < '0' || s[i] > '9')
                {
                    return false;
                }
            }
            return true;
        }

        private static bool TryParseCanonicalAppVersion(string version, string expectedChannel, out CanonicalAppVersion parsed)
        {
            parsed = default(CanonicalAppVersion);
            if (string.IsNullOrEmpty(version) || version.Length > 128 || !IsAsciiString(version))
            {
                return false;
            }

            if (expectedChannel == "stable")
            {
                // Must be exact X.Y.Z with no prerelease/build
                if (version.IndexOf('-') >= 0 || version.IndexOf('+') >= 0)
                {
                    return false;
                }
                string[] parts = version.Split('.');
                if (parts.Length != 3)
                {
                    return false;
                }
                if (!IsCanonicalDecimalString(parts[0]) ||
                    !IsCanonicalDecimalString(parts[1]) ||
                    !IsCanonicalDecimalString(parts[2]))
                {
                    return false;
                }
                parsed = new CanonicalAppVersion(parts[0], parts[1], parts[2], false, null);
                return true;
            }
            else if (expectedChannel == "beta")
            {
                // Must be exact X.Y.Z-beta.N
                const string betaMarker = "-beta.";
                int markerIdx = version.IndexOf(betaMarker, StringComparison.Ordinal);
                if (markerIdx <= 0)
                {
                    return false;
                }
                // No build metadata
                if (version.IndexOf('+') >= 0)
                {
                    return false;
                }

                string core = version.Substring(0, markerIdx);
                string betaNum = version.Substring(markerIdx + betaMarker.Length);

                string[] coreParts = core.Split('.');
                if (coreParts.Length != 3)
                {
                    return false;
                }
                if (!IsCanonicalDecimalString(coreParts[0]) ||
                    !IsCanonicalDecimalString(coreParts[1]) ||
                    !IsCanonicalDecimalString(coreParts[2]))
                {
                    return false;
                }
                if (!IsCanonicalDecimalString(betaNum))
                {
                    return false;
                }

                parsed = new CanonicalAppVersion(coreParts[0], coreParts[1], coreParts[2], true, betaNum);
                return true;
            }

            return false;
        }

        private static int CompareNumericComponents(string a, string b)
        {
            if (a.Length != b.Length)
            {
                return a.Length.CompareTo(b.Length);
            }
            return string.CompareOrdinal(a, b);
        }

        private static int CompareAppVersions(CanonicalAppVersion source, CanonicalAppVersion target)
        {
            int cmp = CompareNumericComponents(target.Major, source.Major);
            if (cmp != 0) return cmp;
            cmp = CompareNumericComponents(target.Minor, source.Minor);
            if (cmp != 0) return cmp;
            cmp = CompareNumericComponents(target.Patch, source.Patch);
            if (cmp != 0) return cmp;

            if (source.IsBeta && target.IsBeta)
            {
                return CompareNumericComponents(target.BetaNumber, source.BetaNumber);
            }

            return 0;
        }

        private static string GetRequiredString(BrokerJsonObject obj, string propertyName, string reasonCode)
        {
            BrokerJsonNode node;
            if (!obj.TryGetProperty(propertyName, out node) || node.Kind != BrokerJsonKind.String)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Property '{0}' must be a JSON string.", propertyName));
            }
            return node.GetString();
        }

        private static long GetRequiredInt64(BrokerJsonObject obj, string propertyName, string reasonCode)
        {
            BrokerJsonNode node;
            if (!obj.TryGetProperty(propertyName, out node) || node.Kind != BrokerJsonKind.Number)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Property '{0}' must be a JSON integer number.", propertyName));
            }
            try
            {
                return node.GetInt64();
            }
            catch (FormatException ex)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Property '{0}' contains an invalid integer lexeme.", propertyName),
                    ex);
            }
        }

        private static long GetRequiredSafeInteger(BrokerJsonObject obj, string propertyName, string reasonCode)
        {
            BrokerJsonNode node;
            if (!obj.TryGetProperty(propertyName, out node) || node.Kind != BrokerJsonKind.Number)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Property '{0}' must be a JSON integer number.", propertyName));
            }

            string lexeme = node.GetNumberLexeme();
            if (lexeme.IndexOf('.') >= 0 || lexeme.IndexOf('e') >= 0 || lexeme.IndexOf('E') >= 0)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Property '{0}' must not contain fractional or exponent notation.", propertyName));
            }

            long val;
            try
            {
                val = node.GetInt64();
            }
            catch (FormatException ex)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Property '{0}' integer is out of range or malformed.", propertyName),
                    ex);
            }

            if (val < 0L || val > MaxSafeInteger)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Property '{0}' value {1} is out of safe range (0..{2}).", propertyName, val, MaxSafeInteger));
            }

            return val;
        }

        private static BrokerJsonObject GetRequiredObject(BrokerJsonObject obj, string propertyName, string reasonCode)
        {
            BrokerJsonNode node;
            if (!obj.TryGetProperty(propertyName, out node) || node.Kind != BrokerJsonKind.Object)
            {
                throw new NativeBrokerBindingException(
                    reasonCode,
                    string.Format(CultureInfo.InvariantCulture, "Property '{0}' must be a JSON object.", propertyName));
            }
            return (BrokerJsonObject)node;
        }
    }
}
