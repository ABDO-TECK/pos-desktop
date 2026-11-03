// backend/Native/NativeBrokerManifestVerifier.cs
//
// INTERNAL native broker trusted signed-envelope verifier and strict JSON parser.
//
// Architecture & Trust Boundaries:
// 1. Trusted Keys: Verification keys MUST be supplied exclusively by the trusted,
//    compiled host configuration. Keys are NEVER discovered or selected from the
//    wire, manifest content, key ID headers, filesystem, environment, or OS stores.
// 2. Cryptographic Authentication: The digital signature (RSA PKCS#1 v1.5 with SHA-256)
//    is validated over exact raw bytes BEFORE any UTF-8 decoding or JSON parsing occurs.
//    Invalid signatures fail closed immediately.
// 3. Envelope Auth Only: Successful verification proves only cryptographic authenticity
//    and minimum broker envelope parameters (format=2, minimum_updater_protocol=2,
//    operation=shell_delta, platform=win32, arch=x64). It does NOT constitute full shell
//    manifest authorization, source/target file inventory proof, staging proof, capability
//    receipt, or backup verification. CanApply is ALWAYS false.
// 4. No Native Mutation / I/O: This module performs zero filesystem operations, zero process
//    spawn or quiescence enforcement, zero database mutations, zero rollback journals,
//    zero ambient PowerShell or native compiler invocations, and zero runtime bootstrap.
// 5. Host Responsibility: The caller/host coordinator remains solely responsible for
//    full signed contract authorization, path traversal checks, file locking, transactional
//    durability, process tree termination, and state transitions.

namespace PosDesktop.Updates.Native
{
    using System;
    using System.Collections.Generic;
    using System.Collections.ObjectModel;
    using System.Globalization;
    using System.Security.Cryptography;
    using System.Text;

    /// <summary>
    /// Represents an immutable RSA public key configured exclusively by the trusted compiled host.
    /// </summary>
    public sealed class NativeBrokerTrustedKey
    {
        private readonly string _keyId;
        private readonly byte[] _modulus;
        private readonly byte[] _exponent;

        public NativeBrokerTrustedKey(string keyId, byte[] modulus, byte[] exponent)
        {
            if (keyId == null)
            {
                throw new ArgumentNullException("keyId", "Trusted keyId cannot be null.");
            }
            if (keyId.Length == 0 || keyId.Length > 64)
            {
                throw new ArgumentException("Trusted keyId length must be between 1 and 64 characters.", "keyId");
            }
            for (int i = 0; i < keyId.Length; i++)
            {
                char c = keyId[i];
                bool ok = (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || (c == '_');
                if (!ok)
                {
                    throw new ArgumentException("Trusted keyId must match canonical pattern ^[a-z0-9_]{1,64}$.", "keyId");
                }
            }

            if (modulus == null)
            {
                throw new ArgumentNullException("modulus", "Trusted modulus cannot be null.");
            }
            // Size bounds prior to clone (max 512 bytes / 4096 bits)
            if (modulus.Length != 256 && modulus.Length != 384 && modulus.Length != 512)
            {
                throw new ArgumentException("Trusted modulus length must be exactly 256, 384, or 512 bytes (2048, 3072, or 4096 bits).", "modulus");
            }

            // Clone array BEFORE validating content, store EXACT validated frozen clone
            byte[] frozenModulus = (byte[])modulus.Clone();

            // high bit set / no leading 0
            if ((frozenModulus[0] & 0x80) == 0)
            {
                throw new ArgumentException("Trusted modulus must have the high bit set (no leading 0 byte).", "modulus");
            }
            // odd modulus
            if ((frozenModulus[frozenModulus.Length - 1] & 1) == 0)
            {
                throw new ArgumentException("Trusted modulus must be an odd integer.", "modulus");
            }

            if (exponent == null)
            {
                throw new ArgumentNullException("exponent", "Trusted exponent cannot be null.");
            }
            // Size bounds prior to clone (canonical 3 or 65537: length 1 or 3)
            if (exponent.Length != 1 && exponent.Length != 3)
            {
                throw new ArgumentException("Trusted exponent length must be 1 or 3 bytes.", "exponent");
            }

            // Clone array BEFORE validating content, store EXACT validated frozen clone
            byte[] frozenExponent = (byte[])exponent.Clone();

            // canonical 3 or 65537: [3] or [1, 0, 1]
            bool isCanonical3 = frozenExponent.Length == 1 && frozenExponent[0] == 3;
            bool isCanonical65537 = frozenExponent.Length == 3 && frozenExponent[0] == 1 && frozenExponent[1] == 0 && frozenExponent[2] == 1;
            if (!isCanonical3 && !isCanonical65537)
            {
                throw new ArgumentException("Trusted exponent must be canonical 3 ([3]) or 65537 ([1, 0, 1]).", "exponent");
            }

            this._keyId = keyId;
            this._modulus = frozenModulus;
            this._exponent = frozenExponent;
        }

        public string KeyId { get { return this._keyId; } }
        public int ModulusLength { get { return this._modulus.Length; } }
        public byte[] GetModulusCopy() { return (byte[])this._modulus.Clone(); }
        public byte[] GetExponentCopy() { return (byte[])this._exponent.Clone(); }

        internal RSAParameters ToRSAParameters()
        {
            return new RSAParameters
            {
                Modulus = (byte[])this._modulus.Clone(),
                Exponent = (byte[])this._exponent.Clone()
            };
        }
    }

    /// <summary>
    /// Dedicated exception thrown when manifest digital signature verification fails closed.
    /// </summary>
    public sealed class NativeBrokerSignatureRejectedException : CryptographicException
    {
        public NativeBrokerSignatureRejectedException(string message)
            : base(message)
        {
        }

        public NativeBrokerSignatureRejectedException(string message, Exception innerException)
            : base(message, innerException)
        {
        }
    }

    /// <summary>
    /// Node kinds for immutable JSON representation.
    /// </summary>
    public enum BrokerJsonKind
    {
        Object,
        Array,
        String,
        Number,
        Boolean,
        Null
    }

    /// <summary>
    /// Base class for all immutable JSON nodes.
    /// </summary>
    public abstract class BrokerJsonNode
    {
        internal BrokerJsonNode() { }

        public abstract BrokerJsonKind Kind { get; }

        public virtual string GetString()
        {
            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Node of kind '{0}' cannot be accessed as String.", this.Kind));
        }

        public virtual string GetNumberLexeme()
        {
            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Node of kind '{0}' cannot be accessed as Number.", this.Kind));
        }

        public virtual long GetInt64()
        {
            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Node of kind '{0}' cannot be accessed as Int64.", this.Kind));
        }

        public virtual bool GetBoolean()
        {
            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Node of kind '{0}' cannot be accessed as Boolean.", this.Kind));
        }

        public virtual bool IsNull { get { return false; } }

        public virtual IReadOnlyDictionary<string, BrokerJsonNode> AsObject()
        {
            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Node of kind '{0}' is not an Object.", this.Kind));
        }

        public virtual IReadOnlyList<BrokerJsonNode> AsArray()
        {
            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Node of kind '{0}' is not an Array.", this.Kind));
        }
    }

    /// <summary>
    /// Sealed immutable JSON Object node.
    /// </summary>
    public sealed class BrokerJsonObject : BrokerJsonNode
    {
        private readonly ReadOnlyDictionary<string, BrokerJsonNode> _readOnlyProperties;
        private readonly Dictionary<string, BrokerJsonNode> _properties;

        internal BrokerJsonObject(Dictionary<string, BrokerJsonNode> properties)
        {
            this._properties = properties;
            this._readOnlyProperties = new ReadOnlyDictionary<string, BrokerJsonNode>(properties);
        }

        public override BrokerJsonKind Kind { get { return BrokerJsonKind.Object; } }

        public IReadOnlyDictionary<string, BrokerJsonNode> Properties
        {
            get { return this._readOnlyProperties; }
        }

        public override IReadOnlyDictionary<string, BrokerJsonNode> AsObject()
        {
            return this._readOnlyProperties;
        }

        public BrokerJsonNode this[string propertyName]
        {
            get
            {
                if (propertyName == null)
                {
                    throw new ArgumentNullException("propertyName");
                }
                BrokerJsonNode node;
                if (this._properties.TryGetValue(propertyName, out node))
                {
                    return node;
                }
                throw new KeyNotFoundException("Property not found in JSON object: " + propertyName);
            }
        }

        public bool TryGetProperty(string propertyName, out BrokerJsonNode node)
        {
            if (propertyName == null)
            {
                node = null;
                return false;
            }
            return this._properties.TryGetValue(propertyName, out node);
        }

        public bool ContainsKey(string propertyName)
        {
            if (propertyName == null) return false;
            return this._properties.ContainsKey(propertyName);
        }

        public int Count { get { return this._properties.Count; } }
    }

    /// <summary>
    /// Sealed immutable JSON Array node.
    /// </summary>
    public sealed class BrokerJsonArray : BrokerJsonNode
    {
        private readonly ReadOnlyCollection<BrokerJsonNode> _readOnlyElements;

        internal BrokerJsonArray(List<BrokerJsonNode> elements)
        {
            this._readOnlyElements = new ReadOnlyCollection<BrokerJsonNode>(elements);
        }

        public override BrokerJsonKind Kind { get { return BrokerJsonKind.Array; } }

        public IReadOnlyList<BrokerJsonNode> Elements
        {
            get { return this._readOnlyElements; }
        }

        public override IReadOnlyList<BrokerJsonNode> AsArray()
        {
            return this._readOnlyElements;
        }

        public BrokerJsonNode this[int index]
        {
            get { return this._readOnlyElements[index]; }
        }

        public int Count { get { return this._readOnlyElements.Count; } }
    }

    /// <summary>
    /// Sealed immutable JSON String node.
    /// </summary>
    public sealed class BrokerJsonString : BrokerJsonNode
    {
        private readonly string _value;

        internal BrokerJsonString(string value)
        {
            this._value = value;
        }

        public override BrokerJsonKind Kind { get { return BrokerJsonKind.String; } }
        public string Value { get { return this._value; } }
        public override string GetString() { return this._value; }
    }

    /// <summary>
    /// Sealed immutable JSON Number node, preserving exact lexeme without lossy double conversion.
    /// </summary>
    public sealed class BrokerJsonNumber : BrokerJsonNode
    {
        private readonly string _lexeme;

        internal BrokerJsonNumber(string lexeme)
        {
            this._lexeme = lexeme;
        }

        public override BrokerJsonKind Kind { get { return BrokerJsonKind.Number; } }
        public string Lexeme { get { return this._lexeme; } }
        public override string GetNumberLexeme() { return this._lexeme; }

        public override long GetInt64()
        {
            if (this._lexeme.IndexOf('.') >= 0 ||
                this._lexeme.IndexOf('e') >= 0 ||
                this._lexeme.IndexOf('E') >= 0)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Number lexeme '{0}' contains a fraction or exponent and cannot be accessed as Int64.", this._lexeme));
            }

            long result;
            if (!long.TryParse(this._lexeme, NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture, out result))
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Number lexeme '{0}' does not fit in signed Int64 range.", this._lexeme));
            }
            return result;
        }
    }

    /// <summary>
    /// Sealed immutable JSON Boolean node.
    /// </summary>
    public sealed class BrokerJsonBoolean : BrokerJsonNode
    {
        public static readonly BrokerJsonBoolean True = new BrokerJsonBoolean(true);
        public static readonly BrokerJsonBoolean False = new BrokerJsonBoolean(false);

        private readonly bool _value;

        private BrokerJsonBoolean(bool value)
        {
            this._value = value;
        }

        public override BrokerJsonKind Kind { get { return BrokerJsonKind.Boolean; } }
        public bool Value { get { return this._value; } }
        public override bool GetBoolean() { return this._value; }
    }

    /// <summary>
    /// Sealed immutable JSON Null node.
    /// </summary>
    public sealed class BrokerJsonNull : BrokerJsonNode
    {
        public static readonly BrokerJsonNull Instance = new BrokerJsonNull();

        private BrokerJsonNull() { }

        public override BrokerJsonKind Kind { get { return BrokerJsonKind.Null; } }
        public override bool IsNull { get { return true; } }
    }

    /// <summary>
    /// Sealed immutable envelope returned upon successful cryptographic verification and minimum AUTH-ONLY parsing.
    /// </summary>
    public sealed class VerifiedBrokerEnvelope
    {
        private readonly string _selectedTrustedKeyId;
        private readonly string _manifestSha256;
        private readonly BrokerJsonObject _root;
        private readonly byte[] _rawManifestCopy;
        private readonly long _format;
        private readonly long _minimumUpdaterProtocol;
        private readonly string _operation;
        private readonly string _platform;
        private readonly string _arch;

        internal VerifiedBrokerEnvelope(
            string selectedTrustedKeyId,
            string manifestSha256,
            BrokerJsonObject root,
            byte[] rawManifest,
            long format,
            long minimumUpdaterProtocol,
            string operation,
            string platform,
            string arch)
        {
            this._selectedTrustedKeyId = selectedTrustedKeyId;
            this._manifestSha256 = manifestSha256;
            this._root = root;
            this._rawManifestCopy = (byte[])rawManifest.Clone();
            this._format = format;
            this._minimumUpdaterProtocol = minimumUpdaterProtocol;
            this._operation = operation;
            this._platform = platform;
            this._arch = arch;
        }

        public string SelectedTrustedKeyId { get { return this._selectedTrustedKeyId; } }
        public string ManifestSha256 { get { return this._manifestSha256; } }
        public BrokerJsonObject Root { get { return this._root; } }
        public bool CanApply { get { return false; } }

        public long Format { get { return this._format; } }
        public long MinimumUpdaterProtocol { get { return this._minimumUpdaterProtocol; } }
        public string Operation { get { return this._operation; } }
        public string Platform { get { return this._platform; } }
        public string Arch { get { return this._arch; } }

        public byte[] GetRawManifestCopy()
        {
            return (byte[])this._rawManifestCopy.Clone();
        }
    }

    /// <summary>
    /// Internal verifier for trusted signed-envelopes and strict JSON parsing.
    /// </summary>
    public sealed class NativeBrokerManifestVerifier
    {
        public const int MaxRawManifestBytes = 16 * 1024 * 1024;
        public const int MaxCanonicalSignatureChars = 16 * 1024;
        public const int MaxJsonDepth = 64;
        public const int MaxJsonNodes = 300000;
        public const int MaxStringLengthChars = 1024 * 1024;
        public const int MaxPropertyNameLengthChars = 32768;

        private readonly ReadOnlyCollection<NativeBrokerTrustedKey> _trustedKeys;

        public NativeBrokerManifestVerifier(IEnumerable<NativeBrokerTrustedKey> trustedKeys)
        {
            if (trustedKeys == null)
            {
                throw new ArgumentNullException("trustedKeys", "Trusted keys sequence cannot be null.");
            }

            var list = new List<NativeBrokerTrustedKey>();
            var ids = new HashSet<string>(StringComparer.Ordinal);

            foreach (var key in trustedKeys)
            {
                if (key == null)
                {
                    throw new ArgumentException("Trusted key entry cannot be null.", "trustedKeys");
                }
                if (list.Count >= 8)
                {
                    throw new ArgumentException("Trusted key count must not exceed 8.", "trustedKeys");
                }
                if (!ids.Add(key.KeyId))
                {
                    throw new ArgumentException(string.Format(CultureInfo.InvariantCulture, "Duplicate trusted keyId '{0}' is not allowed.", key.KeyId), "trustedKeys");
                }
                list.Add(key);
            }

            if (list.Count < 1)
            {
                throw new ArgumentException("Trusted key count must be at least 1.", "trustedKeys");
            }

            this._trustedKeys = new ReadOnlyCollection<NativeBrokerTrustedKey>(list);
        }

        public NativeBrokerManifestVerifier(params NativeBrokerTrustedKey[] trustedKeys)
            : this((IEnumerable<NativeBrokerTrustedKey>)trustedKeys)
        {
        }

        public IReadOnlyList<NativeBrokerTrustedKey> TrustedKeys
        {
            get { return this._trustedKeys; }
        }

        public static VerifiedBrokerEnvelope VerifyAndParse(byte[] rawManifest, string canonicalSignature, IEnumerable<NativeBrokerTrustedKey> trustedKeys)
        {
            var verifier = new NativeBrokerManifestVerifier(trustedKeys);
            return verifier.VerifyAndParse(rawManifest, canonicalSignature);
        }

        public VerifiedBrokerEnvelope VerifyAndParse(byte[] rawManifest, string canonicalSignature)
        {
            if (rawManifest == null)
            {
                throw new ArgumentNullException("rawManifest", "Raw manifest byte array cannot be null.");
            }
            if (rawManifest.Length == 0)
            {
                throw new ArgumentException("Raw manifest cannot be empty.", "rawManifest");
            }
            if (rawManifest.Length > MaxRawManifestBytes)
            {
                throw new ArgumentException(string.Format(CultureInfo.InvariantCulture, "Raw manifest length {0} bytes exceeds maximum allowed {1} bytes (16 MiB).", rawManifest.Length, MaxRawManifestBytes), "rawManifest");
            }

            // Defensively clone BEFORE verification and retain same copy
            byte[] clonedRaw = (byte[])rawManifest.Clone();

            if (canonicalSignature == null)
            {
                throw new NativeBrokerSignatureRejectedException("Manifest signature cannot be null.");
            }
            if (canonicalSignature.Length == 0)
            {
                throw new NativeBrokerSignatureRejectedException("Manifest signature cannot be empty.");
            }
            if (canonicalSignature.Length > MaxCanonicalSignatureChars)
            {
                throw new NativeBrokerSignatureRejectedException(string.Format(CultureInfo.InvariantCulture, "Manifest signature length {0} exceeds maximum allowed {1} characters (16 KiB).", canonicalSignature.Length, MaxCanonicalSignatureChars));
            }

            // Strict canonical Base64 validation: no whitespace, exact length multiple of 4, valid charset, re-encode match
            byte[] sigBytes;
            try
            {
                if (canonicalSignature.Length % 4 != 0)
                {
                    throw new NativeBrokerSignatureRejectedException("Signature base64 length is not a multiple of 4.");
                }

                for (int i = 0; i < canonicalSignature.Length; i++)
                {
                    char c = canonicalSignature[i];
                    bool isB64 = (c >= 'A' && c <= 'Z') ||
                                 (c >= 'a' && c <= 'z') ||
                                 (c >= '0' && c <= '9') ||
                                 (c == '+') || (c == '/');
                    if (!isB64)
                    {
                        if (c == '=')
                        {
                            if (i < canonicalSignature.Length - 2)
                            {
                                throw new NativeBrokerSignatureRejectedException("Signature base64 contains '=' padding in non-terminal position.");
                            }
                        }
                        else
                        {
                            throw new NativeBrokerSignatureRejectedException(string.Format(CultureInfo.InvariantCulture, "Signature contains invalid base64 character 0x{0:X2}.", (int)c));
                        }
                    }
                    else
                    {
                        if (canonicalSignature.IndexOf('=', 0, i) >= 0)
                        {
                            throw new NativeBrokerSignatureRejectedException("Signature base64 contains character after '=' padding.");
                        }
                    }
                }

                sigBytes = Convert.FromBase64String(canonicalSignature);
                if (sigBytes == null || sigBytes.Length == 0)
                {
                    throw new NativeBrokerSignatureRejectedException("Signature decoded to empty byte array.");
                }

                string reencoded = Convert.ToBase64String(sigBytes);
                if (!string.Equals(canonicalSignature, reencoded, StringComparison.Ordinal))
                {
                    throw new NativeBrokerSignatureRejectedException("Signature is not canonical base64 (re-encoding mismatch or noncanonical padding bits).");
                }
            }
            catch (NativeBrokerSignatureRejectedException)
            {
                throw;
            }
            catch (Exception ex)
            {
                throw new NativeBrokerSignatureRejectedException("Signature base64 decoding failed.", ex);
            }

            // Verify RSA PKCS#1 v1.5 SHA256 signature BEFORE any UTF-8 decoding or JSON parsing
            byte[] manifestHash;
            SHA256 sha256 = null;
            Exception shaPrimary = null;
            Exception shaCleanup = null;
            try
            {
                sha256 = SHA256.Create();
                manifestHash = sha256.ComputeHash(clonedRaw);
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
                        sha256.Clear();
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

            string selectedTrustedKeyId = null;

            for (int i = 0; i < this._trustedKeys.Count; i++)
            {
                var candidateKey = this._trustedKeys[i];
                if (sigBytes.Length != candidateKey.ModulusLength)
                {
                    continue;
                }

                bool verified = false;
                RSACryptoServiceProvider rsa = null;
                Exception verifyPrimary = null;
                Exception verifyCleanup = null;

                try
                {
                    var cspParams = new CspParameters(24) // PROV_RSA_AES for SHA-256
                    {
                        Flags = CspProviderFlags.NoPrompt | CspProviderFlags.CreateEphemeralKey
                    };
                    rsa = new RSACryptoServiceProvider(cspParams);
                    rsa.PersistKeyInCsp = false;
                    rsa.ImportParameters(candidateKey.ToRSAParameters());
                    verified = rsa.VerifyHash(manifestHash, CryptoConfig.MapNameToOID("SHA256"), sigBytes);
                }
                catch (Exception ex)
                {
                    verifyPrimary = ex;
                }
                finally
                {
                    if (rsa != null)
                    {
                        var cleanupErrors = new List<Exception>();
                        try
                        {
                            rsa.PersistKeyInCsp = false;
                        }
                        catch (Exception ex)
                        {
                            cleanupErrors.Add(ex);
                        }

                        try
                        {
                            rsa.Clear();
                        }
                        catch (Exception ex)
                        {
                            cleanupErrors.Add(ex);
                        }

                        if (cleanupErrors.Count == 1)
                        {
                            verifyCleanup = cleanupErrors[0];
                        }
                        else if (cleanupErrors.Count > 1)
                        {
                            verifyCleanup = new AggregateException("RSA provider cleanup failed.", cleanupErrors);
                        }
                    }
                }

                if (verifyPrimary != null && verifyCleanup != null)
                {
                    throw new AggregateException("RSA signature verification and provider disposal failed.", verifyPrimary, verifyCleanup);
                }
                if (verifyPrimary != null)
                {
                    throw verifyPrimary;
                }
                if (verifyCleanup != null)
                {
                    throw verifyCleanup;
                }

                if (verified)
                {
                    selectedTrustedKeyId = candidateKey.KeyId;
                    break;
                }
            }

            if (selectedTrustedKeyId == null)
            {
                throw new NativeBrokerSignatureRejectedException("Manifest signature is invalid or not signed by any trusted key.");
            }

            // Lower-hex manifest SHA-256 after success
            var sbHash = new StringBuilder(manifestHash.Length * 2);
            for (int i = 0; i < manifestHash.Length; i++)
            {
                sbHash.Append(manifestHash[i].ToString("x2", CultureInfo.InvariantCulture));
            }
            string lowerHexSha256 = sbHash.ToString();

            // Strict UTF-8 decoding after signature verified
            if (clonedRaw.Length >= 3 && clonedRaw[0] == 0xEF && clonedRaw[1] == 0xBB && clonedRaw[2] == 0xBF)
            {
                throw new FormatException("UTF-8 Byte Order Mark (BOM) is not permitted in raw manifest.");
            }

            string jsonText;
            try
            {
                var utf8Strict = new UTF8Encoding(false, true);
                jsonText = utf8Strict.GetString(clonedRaw);
            }
            catch (Exception ex)
            {
                throw new FormatException("Raw manifest bytes are not valid UTF-8.", ex);
            }

            // Strict bounded JSON parsing
            var parser = new StrictJsonParser(jsonText);
            BrokerJsonObject root = parser.ParseRoot();

            // Validate root minimum AUTH-ONLY envelope fields
            long format;
            long minimumUpdaterProtocol;
            string operation;
            string platform;
            string arch;

            ValidateEnvelopeFields(root, out format, out minimumUpdaterProtocol, out operation, out platform, out arch);

            return new VerifiedBrokerEnvelope(
                selectedTrustedKeyId,
                lowerHexSha256,
                root,
                clonedRaw,
                format,
                minimumUpdaterProtocol,
                operation,
                platform,
                arch);
        }

        private static void ValidateEnvelopeFields(
            BrokerJsonObject root,
            out long format,
            out long minimumUpdaterProtocol,
            out string operation,
            out string platform,
            out string arch)
        {
            // 1. format
            BrokerJsonNode formatNode;
            if (!root.TryGetProperty("format", out formatNode))
            {
                throw new FormatException("Missing required envelope field 'format'.");
            }
            if (formatNode.Kind != BrokerJsonKind.Number)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Required envelope field 'format' must be a JSON integer number, but was kind '{0}'.", formatNode.Kind));
            }
            format = formatNode.GetInt64();
            if (format != 2)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unsupported envelope 'format' value {0}; expected exact integer 2.", format));
            }

            // 2. minimum_updater_protocol
            BrokerJsonNode protocolNode;
            if (!root.TryGetProperty("minimum_updater_protocol", out protocolNode))
            {
                throw new FormatException("Missing required envelope field 'minimum_updater_protocol'.");
            }
            if (protocolNode.Kind != BrokerJsonKind.Number)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Required envelope field 'minimum_updater_protocol' must be a JSON integer number, but was kind '{0}'.", protocolNode.Kind));
            }
            minimumUpdaterProtocol = protocolNode.GetInt64();
            if (minimumUpdaterProtocol != 2)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unsupported envelope 'minimum_updater_protocol' value {0}; expected exact integer 2.", minimumUpdaterProtocol));
            }

            // 3. operation
            BrokerJsonNode opNode;
            if (!root.TryGetProperty("operation", out opNode))
            {
                throw new FormatException("Missing required envelope field 'operation'.");
            }
            if (opNode.Kind != BrokerJsonKind.String)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Required envelope field 'operation' must be a JSON string, but was kind '{0}'.", opNode.Kind));
            }
            operation = opNode.GetString();
            if (!string.Equals(operation, "shell_delta", StringComparison.Ordinal))
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unsupported envelope 'operation' value '{0}'; expected exact string 'shell_delta'.", operation));
            }

            // 4. platform
            BrokerJsonNode platNode;
            if (!root.TryGetProperty("platform", out platNode))
            {
                throw new FormatException("Missing required envelope field 'platform'.");
            }
            if (platNode.Kind != BrokerJsonKind.String)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Required envelope field 'platform' must be a JSON string, but was kind '{0}'.", platNode.Kind));
            }
            platform = platNode.GetString();
            if (!string.Equals(platform, "win32", StringComparison.Ordinal))
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unsupported envelope 'platform' value '{0}'; expected exact string 'win32'.", platform));
            }

            // 5. arch
            BrokerJsonNode archNode;
            if (!root.TryGetProperty("arch", out archNode))
            {
                throw new FormatException("Missing required envelope field 'arch'.");
            }
            if (archNode.Kind != BrokerJsonKind.String)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Required envelope field 'arch' must be a JSON string, but was kind '{0}'.", archNode.Kind));
            }
            arch = archNode.GetString();
            if (!string.Equals(arch, "x64", StringComparison.Ordinal))
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unsupported envelope 'arch' value '{0}'; expected exact string 'x64'.", arch));
            }
        }

        private sealed class StrictJsonParser
        {
            private readonly string _source;
            private int _pos;
            private int _depth;
            private int _nodeCount;

            public StrictJsonParser(string source)
            {
                this._source = source;
                this._pos = 0;
                this._depth = 0;
                this._nodeCount = 0;
            }

            private void CountNode()
            {
                this._nodeCount++;
                if (this._nodeCount > MaxJsonNodes)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "JSON node count exceeded maximum limit of {0}.", MaxJsonNodes));
                }
            }

            private void EnterDepth()
            {
                this._depth++;
                if (this._depth > MaxJsonDepth)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "JSON nesting depth exceeded maximum limit of {0}.", MaxJsonDepth));
                }
            }

            private void ExitDepth()
            {
                this._depth--;
            }

            private void SkipWhitespace()
            {
                while (this._pos < this._source.Length)
                {
                    char c = this._source[this._pos];
                    if (c == ' ' || c == '\t' || c == '\n' || c == '\r')
                    {
                        this._pos++;
                    }
                    else
                    {
                        break;
                    }
                }
            }

            public BrokerJsonObject ParseRoot()
            {
                this.SkipWhitespace();
                if (this._pos >= this._source.Length)
                {
                    throw new FormatException("JSON source is empty or contains only whitespace.");
                }

                if (this._source[this._pos] != '{')
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Root JSON value must be an Object ('{{'), but found '{0}'.", this._source[this._pos]));
                }

                BrokerJsonObject root = this.ParseObject();

                this.SkipWhitespace();
                if (this._pos < this._source.Length)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unexpected trailing token after root JSON object at position {0}.", this._pos));
                }

                return root;
            }

            private BrokerJsonObject ParseObject()
            {
                this.CountNode();
                this.EnterDepth();
                try
                {
                    this._pos++; // skip '{'
                    this.SkipWhitespace();

                    var dict = new Dictionary<string, BrokerJsonNode>(StringComparer.Ordinal);

                    if (this._pos < this._source.Length && this._source[this._pos] == '}')
                    {
                        this._pos++;
                        return new BrokerJsonObject(dict);
                    }

                    while (true)
                    {
                        this.SkipWhitespace();
                        if (this._pos >= this._source.Length)
                        {
                            throw new FormatException("Unterminated JSON object: expected property name or '}'.");
                        }

                        if (this._source[this._pos] != '"')
                        {
                            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Expected string property name in object at position {0}, but found '{1}'.", this._pos, this._source[this._pos]));
                        }

                        string propName = this.ParseString(true);

                        if (dict.ContainsKey(propName))
                        {
                            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Duplicate property name '{0}' in JSON object.", propName));
                        }

                        this.SkipWhitespace();
                        if (this._pos >= this._source.Length || this._source[this._pos] != ':')
                        {
                            throw new FormatException("Expected ':' after property name in JSON object.");
                        }
                        this._pos++; // skip ':'

                        this.SkipWhitespace();
                        BrokerJsonNode valueNode = this.ParseValue();
                        dict.Add(propName, valueNode);

                        this.SkipWhitespace();
                        if (this._pos >= this._source.Length)
                        {
                            throw new FormatException("Unterminated JSON object: expected ',' or '}'.");
                        }

                        char nextChar = this._source[this._pos];
                        if (nextChar == '}')
                        {
                            this._pos++;
                            return new BrokerJsonObject(dict);
                        }
                        if (nextChar == ',')
                        {
                            this._pos++;
                            this.SkipWhitespace();
                            if (this._pos < this._source.Length && this._source[this._pos] == '}')
                            {
                                throw new FormatException("Trailing comma in JSON object is not permitted.");
                            }
                        }
                        else
                        {
                            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Expected ',' or '}}' in JSON object at position {0}, but found '{1}'.", this._pos, nextChar));
                        }
                    }
                }
                finally
                {
                    this.ExitDepth();
                }
            }

            private BrokerJsonArray ParseArray()
            {
                this.CountNode();
                this.EnterDepth();
                try
                {
                    this._pos++; // skip '['
                    this.SkipWhitespace();

                    var list = new List<BrokerJsonNode>();

                    if (this._pos < this._source.Length && this._source[this._pos] == ']')
                    {
                        this._pos++;
                        return new BrokerJsonArray(list);
                    }

                    while (true)
                    {
                        this.SkipWhitespace();
                        BrokerJsonNode item = this.ParseValue();
                        list.Add(item);

                        this.SkipWhitespace();
                        if (this._pos >= this._source.Length)
                        {
                            throw new FormatException("Unterminated JSON array: expected ',' or ']'.");
                        }

                        char nextChar = this._source[this._pos];
                        if (nextChar == ']')
                        {
                            this._pos++;
                            return new BrokerJsonArray(list);
                        }
                        if (nextChar == ',')
                        {
                            this._pos++;
                            this.SkipWhitespace();
                            if (this._pos < this._source.Length && this._source[this._pos] == ']')
                            {
                                throw new FormatException("Trailing comma in JSON array is not permitted.");
                            }
                        }
                        else
                        {
                            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Expected ',' or ']' in JSON array at position {0}, but found '{1}'.", this._pos, nextChar));
                        }
                    }
                }
                finally
                {
                    this.ExitDepth();
                }
            }

            private BrokerJsonNode ParseValue()
            {
                this.SkipWhitespace();
                if (this._pos >= this._source.Length)
                {
                    throw new FormatException("Unexpected end of JSON input while expecting a value.");
                }

                char c = this._source[this._pos];
                if (c == '{')
                {
                    return this.ParseObject();
                }
                if (c == '[')
                {
                    return this.ParseArray();
                }
                if (c == '"')
                {
                    this.CountNode();
                    string s = this.ParseString(false);
                    return new BrokerJsonString(s);
                }
                if (c == '-' || (c >= '0' && c <= '9'))
                {
                    this.CountNode();
                    string numLexeme = this.ParseNumberLexeme();
                    return new BrokerJsonNumber(numLexeme);
                }
                if (c == 't')
                {
                    this.CountNode();
                    this.ExpectLiteral("true");
                    return BrokerJsonBoolean.True;
                }
                if (c == 'f')
                {
                    this.CountNode();
                    this.ExpectLiteral("false");
                    return BrokerJsonBoolean.False;
                }
                if (c == 'n')
                {
                    this.CountNode();
                    this.ExpectLiteral("null");
                    return BrokerJsonNull.Instance;
                }

                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unexpected character '{0}' (0x{1:X2}) at position {2} while expecting JSON value.", c, (int)c, this._pos));
            }

            private void ExpectLiteral(string expected)
            {
                if (this._pos + expected.Length > this._source.Length)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unexpected end of input while expecting literal '{0}'.", expected));
                }
                for (int i = 0; i < expected.Length; i++)
                {
                    if (this._source[this._pos + i] != expected[i])
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unexpected token while expecting literal '{0}'.", expected));
                    }
                }
                this._pos += expected.Length;

                if (this._pos < this._source.Length)
                {
                    char next = this._source[this._pos];
                    if (char.IsLetterOrDigit(next) || next == '_')
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unexpected character '{0}' following literal '{1}'.", next, expected));
                    }
                }
            }

            private string ParseString(bool isPropertyName)
            {
                int maxAllowed = isPropertyName ? MaxPropertyNameLengthChars : MaxStringLengthChars;

                this._pos++; // skip opening '"'
                var sb = new StringBuilder();

                while (this._pos < this._source.Length)
                {
                    char c = this._source[this._pos];

                    if (c == '"')
                    {
                        this._pos++; // skip closing '"'
                        string result = sb.ToString();

                        ValidateSurrogates(result);

                        if (result.Length > maxAllowed)
                        {
                            string desc = isPropertyName ? "Property name" : "String value";
                            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "{0} length {1} exceeded maximum allowed limit of {2} characters.", desc, result.Length, maxAllowed));
                        }

                        return result;
                    }

                    if (c < 0x20)
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unescaped control character 0x{0:X2} is not permitted in JSON strings.", (int)c));
                    }

                    if (c == '\\')
                    {
                        this._pos++;
                        if (this._pos >= this._source.Length)
                        {
                            throw new FormatException("Unterminated escape sequence in JSON string.");
                        }

                        char esc = this._source[this._pos];
                        switch (esc)
                        {
                            case '"': sb.Append('"'); this._pos++; break;
                            case '\\': sb.Append('\\'); this._pos++; break;
                            case '/': sb.Append('/'); this._pos++; break;
                            case 'b':
                                if (isPropertyName) throw new FormatException("Escaped control character '\\b' is not permitted in property names.");
                                sb.Append('\b'); this._pos++; break;
                            case 'f':
                                if (isPropertyName) throw new FormatException("Escaped control character '\\f' is not permitted in property names.");
                                sb.Append('\f'); this._pos++; break;
                            case 'n':
                                if (isPropertyName) throw new FormatException("Escaped control character '\\n' is not permitted in property names.");
                                sb.Append('\n'); this._pos++; break;
                            case 'r':
                                if (isPropertyName) throw new FormatException("Escaped control character '\\r' is not permitted in property names.");
                                sb.Append('\r'); this._pos++; break;
                            case 't':
                                if (isPropertyName) throw new FormatException("Escaped control character '\\t' is not permitted in property names.");
                                sb.Append('\t'); this._pos++; break;
                            case 'u':
                                this._pos++;
                                char unicodeChar = this.ParseHex4();
                                if (isPropertyName && unicodeChar < 0x20)
                                {
                                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Control character 0x{0:X2} is not permitted in property names.", (int)unicodeChar));
                                }
                                sb.Append(unicodeChar);
                                break;
                            default:
                                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Invalid escape character '\\{0}' in JSON string.", esc));
                        }
                    }
                    else
                    {
                        sb.Append(c);
                        this._pos++;
                    }

                    if (sb.Length > maxAllowed + 2)
                    {
                        string desc = isPropertyName ? "Property name" : "String value";
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "{0} length exceeded maximum allowed limit of {1} characters.", desc, maxAllowed));
                    }
                }

                throw new FormatException("Unterminated JSON string: missing closing quote.");
            }

            private char ParseHex4()
            {
                if (this._pos + 4 > this._source.Length)
                {
                    throw new FormatException("Incomplete '\\uXXXX' escape sequence in JSON string.");
                }

                int val = 0;
                for (int i = 0; i < 4; i++)
                {
                    char h = this._source[this._pos + i];
                    val <<= 4;
                    if (h >= '0' && h <= '9') val |= (h - '0');
                    else if (h >= 'a' && h <= 'f') val |= (h - 'a' + 10);
                    else if (h >= 'A' && h <= 'F') val |= (h - 'A' + 10);
                    else
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Invalid hex character '{0}' in '\\uXXXX' escape sequence.", h));
                    }
                }
                this._pos += 4;
                return (char)val;
            }

            private static void ValidateSurrogates(string s)
            {
                for (int i = 0; i < s.Length; i++)
                {
                    char c = s[i];
                    if (char.IsHighSurrogate(c))
                    {
                        if (i + 1 >= s.Length)
                        {
                            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Lone high surrogate '\\u{0:X4}' at end of JSON string.", (int)c));
                        }
                        char next = s[i + 1];
                        if (!char.IsLowSurrogate(next))
                        {
                            throw new FormatException(string.Format(CultureInfo.InvariantCulture, "High surrogate '\\u{0:X4}' followed by invalid low surrogate '\\u{1:X4}'.", (int)c, (int)next));
                        }
                        i++; // skip verified low surrogate
                    }
                    else if (char.IsLowSurrogate(c))
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Lone low surrogate '\\u{0:X4}' in JSON string without preceding high surrogate.", (int)c));
                    }
                }
            }

            private string ParseNumberLexeme()
            {
                int start = this._pos;

                if (this._source[this._pos] == '-')
                {
                    this._pos++;
                    if (this._pos >= this._source.Length)
                    {
                        throw new FormatException("Unexpected end of JSON input after '-' in number.");
                    }
                }

                char firstDigit = this._source[this._pos];
                if (firstDigit == '0')
                {
                    this._pos++;
                    if (this._pos < this._source.Length && (this._source[this._pos] >= '0' && this._source[this._pos] <= '9'))
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Leading zero followed by digit '{0}' in JSON number is not permitted.", this._source[this._pos]));
                    }
                }
                else if (firstDigit >= '1' && firstDigit <= '9')
                {
                    this._pos++;
                    while (this._pos < this._source.Length && (this._source[this._pos] >= '0' && this._source[this._pos] <= '9'))
                    {
                        this._pos++;
                    }
                }
                else
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Expected digits in JSON number at position {0}, but found '{1}'.", this._pos, firstDigit));
                }

                if (this._pos < this._source.Length && this._source[this._pos] == '.')
                {
                    this._pos++;
                    if (this._pos >= this._source.Length || !(this._source[this._pos] >= '0' && this._source[this._pos] <= '9'))
                    {
                        throw new FormatException("JSON number fraction '.' must be followed by at least one digit.");
                    }
                    while (this._pos < this._source.Length && (this._source[this._pos] >= '0' && this._source[this._pos] <= '9'))
                    {
                        this._pos++;
                    }
                }

                if (this._pos < this._source.Length && (this._source[this._pos] == 'e' || this._source[this._pos] == 'E'))
                {
                    this._pos++;
                    if (this._pos < this._source.Length && (this._source[this._pos] == '+' || this._source[this._pos] == '-'))
                    {
                        this._pos++;
                    }
                    if (this._pos >= this._source.Length || !(this._source[this._pos] >= '0' && this._source[this._pos] <= '9'))
                    {
                        throw new FormatException("JSON number exponent 'e/E' must be followed by at least one digit.");
                    }
                    while (this._pos < this._source.Length && (this._source[this._pos] >= '0' && this._source[this._pos] <= '9'))
                    {
                        this._pos++;
                    }
                }

                if (this._pos < this._source.Length)
                {
                    char next = this._source[this._pos];
                    bool isDelimiter = next == ' ' || next == '\t' || next == '\n' || next == '\r' ||
                                       next == ',' || next == '}' || next == ']';
                    if (!isDelimiter)
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Unexpected trailing character '{0}' after JSON number.", next));
                    }
                }

                return this._source.Substring(start, this._pos - start);
            }
        }
    }
}
