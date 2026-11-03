# scripts/verify-shell-native-broker-auth.ps1
# Native Broker Trusted Signed-Envelope Verifier & Strict JSON Parser Regression Test
# Runs inside Windows GitHub Actions under PowerShell 5.1 Desktop x64.

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

# --- Section 1: Strict Execution Environment Guards ---
if ($env:GITHUB_ACTIONS -ne 'true') { [Console]::Error.WriteLine("GUARD: GITHUB_ACTIONS=true required."); exit 1 }
if ($env:RUNNER_OS -ne 'Windows') { [Console]::Error.WriteLine("GUARD: RUNNER_OS must be 'Windows'."); exit 1 }
if ([System.Environment]::OSVersion.Platform -ne [System.PlatformID]::Win32NT) { [Console]::Error.WriteLine("GUARD: Win32NT required."); exit 1 }
if ($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { [Console]::Error.WriteLine("GUARD: PS 5.1 Desktop required."); exit 1 }
if (-not [System.Environment]::Is64BitProcess -or -not [System.Environment]::Is64BitOperatingSystem) { [Console]::Error.WriteLine("GUARD: 64-bit required."); exit 1 }

# --- Section 2: Locate Production C# Source ---
$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = (Resolve-Path "$scriptRoot\..").Path
$prodCsPath = Join-Path $repoRoot "backend\Native\NativeBrokerManifestVerifier.cs"

if (-not (Test-Path -LiteralPath $prodCsPath)) {
    [Console]::Error.WriteLine("ERROR: Production C# file not found at: $prodCsPath")
    exit 1
}

$prodCsCode = [System.IO.File]::ReadAllText($prodCsPath, [System.Text.Encoding]::UTF8)

# --- Section 3: In-Memory Pure Test C# Harness ---
$testHarnessCode = @'
namespace PosDesktop.Updates.Native.Tests
{
    using System;
    using System.Collections.Generic;
    using System.Globalization;
    using System.Security.Cryptography;
    using System.Text;
    using PosDesktop.Updates.Native;

    public sealed class GroupContext
    {
        private readonly List<RSACryptoServiceProvider> _signers = new List<RSACryptoServiceProvider>();

        public IReadOnlyList<RSACryptoServiceProvider> Signers
        {
            get { return this._signers; }
        }

        public RSACryptoServiceProvider CreateKey(string keyId, out NativeBrokerTrustedKey trustedKey)
        {
            var cspParams = new CspParameters(24)
            {
                Flags = CspProviderFlags.NoPrompt | CspProviderFlags.CreateEphemeralKey
            };
            var rsa = new RSACryptoServiceProvider(2048, cspParams);
            // Assign immediately to registry so owner is reachable even on subsequent error
            this._signers.Add(rsa);

            rsa.PersistKeyInCsp = false;

            RSAParameters pub = rsa.ExportParameters(false);
            // Assert shape or fail (RSA 2048 key generated always high bit odd), do not silently retry
            BrokerVerifierHarness.Assert((pub.Modulus[0] & 0x80) != 0, "Modulus high bit must be set");
            BrokerVerifierHarness.Assert((pub.Modulus[pub.Modulus.Length - 1] & 1) != 0, "Modulus must be an odd integer");

            trustedKey = new NativeBrokerTrustedKey(keyId, pub.Modulus, pub.Exponent);
            return rsa;
        }
    }

    public sealed class BoundedKeyEnumerable : IEnumerable<NativeBrokerTrustedKey>
    {
        private readonly NativeBrokerTrustedKey _prototype;
        public int MoveNextCallCount = 0;

        public BoundedKeyEnumerable(NativeBrokerTrustedKey prototype)
        {
            this._prototype = prototype;
        }

        public IEnumerator<NativeBrokerTrustedKey> GetEnumerator()
        {
            while (true)
            {
                this.MoveNextCallCount++;
                if (this.MoveNextCallCount > 9)
                {
                    throw new InvalidOperationException("SENTINEL: MoveNext called 10th time on bounded enumerable!");
                }
                yield return new NativeBrokerTrustedKey(
                    "key_" + this.MoveNextCallCount.ToString(CultureInfo.InvariantCulture),
                    this._prototype.GetModulusCopy(),
                    this._prototype.GetExponentCopy());
            }
        }

        System.Collections.IEnumerator System.Collections.IEnumerable.GetEnumerator()
        {
            return this.GetEnumerator();
        }
    }

    public static class BrokerVerifierHarness
    {
        public static void Assert(bool condition, string message)
        {
            if (!condition)
            {
                throw new InvalidOperationException("ASSERTION FAILED: " + message);
            }
        }

        public static void AssertThrows<TException>(Action action, string message) where TException : Exception
        {
            try
            {
                action();
            }
            catch (TException)
            {
                return;
            }
            catch (Exception ex)
            {
                throw new InvalidOperationException(string.Format(CultureInfo.InvariantCulture,
                    "ASSERTION FAILED: Expected exception {0} but caught {1}: {2} ({3})",
                    typeof(TException).Name, ex.GetType().Name, ex.Message, message));
            }

            throw new InvalidOperationException(string.Format(CultureInfo.InvariantCulture,
                "ASSERTION FAILED: Expected exception {0} but no exception was thrown ({1})",
                typeof(TException).Name, message));
        }

        public static int RunOwnedGroup(Func<GroupContext, int> groupFunc)
        {
            var ctx = new GroupContext();
            Exception primaryEx = null;
            int checks = 0;

            try
            {
                checks = groupFunc(ctx);
            }
            catch (Exception ex)
            {
                primaryEx = ex;
            }
            finally
            {
                var cleanupExceptions = new List<Exception>();
                for (int i = 0; i < ctx.Signers.Count; i++)
                {
                    var signer = ctx.Signers[i];
                    if (signer != null)
                    {
                        try
                        {
                            signer.PersistKeyInCsp = false;
                        }
                        catch (Exception ex)
                        {
                            cleanupExceptions.Add(ex);
                        }

                        try
                        {
                            signer.Clear();
                        }
                        catch (Exception ex)
                        {
                            cleanupExceptions.Add(ex);
                        }
                    }
                }

                if (primaryEx != null && cleanupExceptions.Count > 0)
                {
                    var all = new List<Exception>();
                    all.Add(primaryEx);
                    all.AddRange(cleanupExceptions);
                    throw new AggregateException("Group execution and signer cleanup failed.", all);
                }
                if (primaryEx != null)
                {
                    throw primaryEx;
                }
                if (cleanupExceptions.Count > 0)
                {
                    if (cleanupExceptions.Count == 1)
                    {
                        throw cleanupExceptions[0];
                    }
                    throw new AggregateException("Signer cleanup failed.", cleanupExceptions);
                }
            }

            return checks;
        }

        public static string SignBytes(RSACryptoServiceProvider signer, byte[] data)
        {
            byte[] hash;
            SHA256 sha = null;
            Exception shaPrimary = null;
            Exception shaCleanup = null;
            try
            {
                sha = SHA256.Create();
                hash = sha.ComputeHash(data);
            }
            catch (Exception ex)
            {
                shaPrimary = ex;
            }
            finally
            {
                if (sha != null)
                {
                    try
                    {
                        sha.Clear();
                    }
                    catch (Exception ex)
                    {
                        shaCleanup = ex;
                    }
                }
            }

            if (shaPrimary != null && shaCleanup != null)
            {
                throw new AggregateException("SHA-256 error and cleanup failed.", shaPrimary, shaCleanup);
            }
            if (shaPrimary != null)
            {
                throw shaPrimary;
            }
            if (shaCleanup != null)
            {
                throw shaCleanup;
            }

            byte[] sig = signer.SignHash(hash, CryptoConfig.MapNameToOID("SHA256"));
            return Convert.ToBase64String(sig);
        }

        public static string ComputeSha256LowerHex(byte[] data)
        {
            byte[] hash;
            SHA256 sha = null;
            Exception shaPrimary = null;
            Exception shaCleanup = null;
            try
            {
                sha = SHA256.Create();
                hash = sha.ComputeHash(data);
            }
            catch (Exception ex)
            {
                shaPrimary = ex;
            }
            finally
            {
                if (sha != null)
                {
                    try
                    {
                        sha.Clear();
                    }
                    catch (Exception ex)
                    {
                        shaCleanup = ex;
                    }
                }
            }

            if (shaPrimary != null && shaCleanup != null)
            {
                throw new AggregateException("SHA-256 error and cleanup failed.", shaPrimary, shaCleanup);
            }
            if (shaPrimary != null)
            {
                throw shaPrimary;
            }
            if (shaCleanup != null)
            {
                throw shaCleanup;
            }

            var sb = new StringBuilder(hash.Length * 2);
            for (int i = 0; i < hash.Length; i++)
            {
                sb.Append(hash[i].ToString("x2", CultureInfo.InvariantCulture));
            }
            return sb.ToString();
        }

        // Group 1: Valid A + immutable tree / int64 lexeme > 2^53 / UTF8 Unicode + escapes / readonly / raw mutation isolation / CanApply=false
        public static int RunGroup1()
        {
            return RunOwnedGroup(delegate(GroupContext ctx)
            {
                int checks = 0;
                NativeBrokerTrustedKey keyA;
                var signerA = ctx.CreateKey("key_a", out keyA);

                string validJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"safe_int\":9007199254740993,\"unicode_text\":\"Hello \\u0057orld \\uD83D\\uDE00 \\u00E9\",\"nested\":{\"items\":[1,2,\"three\",null,true,false]}}";
                byte[] raw = Encoding.UTF8.GetBytes(validJson);
                string sig = SignBytes(signerA, raw);

                var verifier = new NativeBrokerManifestVerifier(keyA);
                var envelope = verifier.VerifyAndParse(raw, sig);

                Assert(!envelope.CanApply, "CanApply must always be false"); checks++;
                Assert(envelope.SelectedTrustedKeyId == "key_a", "SelectedTrustedKeyId must match key_a"); checks++;
                Assert(envelope.Format == 2, "Format must be 2"); checks++;
                Assert(envelope.MinimumUpdaterProtocol == 2, "MinimumUpdaterProtocol must be 2"); checks++;
                Assert(envelope.Operation == "shell_delta", "Operation must be shell_delta"); checks++;
                Assert(envelope.Platform == "win32", "Platform must be win32"); checks++;
                Assert(envelope.Arch == "x64", "Arch must be x64"); checks++;

                string expectedSha = ComputeSha256LowerHex(raw);
                Assert(envelope.ManifestSha256 == expectedSha, "ManifestSha256 must match computed raw sha"); checks++;

                // Lexeme > 2^53 preserved without double precision loss
                Assert(envelope.Root["safe_int"].GetNumberLexeme() == "9007199254740993", "Safe int lexeme preserved"); checks++;
                Assert(envelope.Root["safe_int"].GetInt64() == 9007199254740993L, "Safe int Int64 value preserved"); checks++;

                // Unicode decoding
                string uText = envelope.Root["unicode_text"].GetString();
                Assert(uText.Contains("Hello World"), "Escaped \\u0057 decoded to W"); checks++;
                Assert(uText.Contains("\uD83D\uDE00"), "Surrogate pair decoded to emoji"); checks++;

                // Readonly collections
                Assert(envelope.Root["nested"].AsObject()["items"].AsArray().Count == 6, "Nested array count 6"); checks++;

                // Original raw array mutation after construct/Verify cannot mutate envelope
                byte orig0 = raw[0];
                raw[0] = 0x00;
                Assert(envelope.GetRawManifestCopy()[0] == orig0, "Original raw byte mutation must not alter envelope raw copy"); checks++;
                raw[0] = orig0;

                // Mutating returned raw copy cannot alter envelope
                byte[] copy = envelope.GetRawManifestCopy();
                copy[0] = 0x00;
                Assert(envelope.GetRawManifestCopy()[0] == orig0, "GetRawManifestCopy mutation must not alter envelope"); checks++;

                // Modulus array mutation after key construction
                byte[] mod = keyA.GetModulusCopy();
                byte m0 = mod[0];
                mod[0] = 0x00;
                Assert(keyA.GetModulusCopy()[0] == m0, "Modulus array mutation must not alter trusted key"); checks++;

                return checks;
            });
        }

        // Group 2: Real crypto tamper 1 byte, wrong sig, key B untrusted, wire key B cannot override, rotation config [A,B], duplicate/0/>8 keys, sig before parse
        public static int RunGroup2()
        {
            return RunOwnedGroup(delegate(GroupContext ctx)
            {
                int checks = 0;
                NativeBrokerTrustedKey keyA;
                var signerA = ctx.CreateKey("key_a", out keyA);
                NativeBrokerTrustedKey keyB;
                var signerB = ctx.CreateKey("key_b", out keyB);

                string validJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}";
                byte[] raw = Encoding.UTF8.GetBytes(validJson);
                string sigA = SignBytes(signerA, raw);
                string sigB = SignBytes(signerB, raw);

                var verifierA = new NativeBrokerManifestVerifier(keyA);

                // Tamper 1 byte of raw
                byte[] tamperedRaw = (byte[])raw.Clone();
                tamperedRaw[5] ^= 0x01;
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifierA.VerifyAndParse(tamperedRaw, sigA); }, "Tampered raw byte must reject"); checks++;

                // Wrong signature
                string wrongSig = SignBytes(signerA, Encoding.UTF8.GetBytes("{\"other\":1}"));
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifierA.VerifyAndParse(raw, wrongSig); }, "Wrong signature must reject"); checks++;

                // Key B untrusted
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifierA.VerifyAndParse(raw, sigB); }, "Untrusted key B must reject"); checks++;

                // Wire embedded key B cannot override trusted configuration
                string wireJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"key_id\":\"key_b\"}";
                byte[] wireRaw = Encoding.UTF8.GetBytes(wireJson);
                string wireSigB = SignBytes(signerB, wireRaw);
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifierA.VerifyAndParse(wireRaw, wireSigB); }, "Wire key cannot override trusted config"); checks++;

                // Rotation configuration [Key A, Key B]
                var rotVerifier = new NativeBrokerManifestVerifier(keyA, keyB);
                var envB = rotVerifier.VerifyAndParse(raw, sigB);
                Assert(envB.SelectedTrustedKeyId == "key_b", "Rotated key B selected"); checks++;
                var envA = rotVerifier.VerifyAndParse(raw, sigA);
                Assert(envA.SelectedTrustedKeyId == "key_a", "Rotated key A selected"); checks++;

                // Bounded test enumerable that yields canonical keys 1..9 and throws SENTINEL if MoveNext called 10th
                var bounded = new BoundedKeyEnumerable(keyA);
                AssertThrows<ArgumentException>(delegate { new NativeBrokerManifestVerifier(bounded); }, "Constructor throws ArgumentException on 9th key"); checks++;
                Assert(bounded.MoveNextCallCount == 9, "MoveNext called exactly 9 times before cap 9 throws"); checks++;

                // Existing array 9 tests remain
                var nineKeys = new List<NativeBrokerTrustedKey>();
                for (int i = 0; i < 9; i++)
                {
                    nineKeys.Add(new NativeBrokerTrustedKey(
                        "k" + i.ToString(CultureInfo.InvariantCulture),
                        keyA.GetModulusCopy(),
                        keyA.GetExponentCopy()));
                }
                AssertThrows<ArgumentException>(delegate { new NativeBrokerManifestVerifier(nineKeys); }, "9 trusted keys in array rejected"); checks++;

                // Trusted key configuration limits
                AssertThrows<ArgumentException>(delegate { new NativeBrokerManifestVerifier(new NativeBrokerTrustedKey[0]); }, "0 trusted keys rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerManifestVerifier(keyA, keyA); }, "Duplicate keyId rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerManifestVerifier(new NativeBrokerTrustedKey[] { null }); }, "Null key element rejected"); checks++;
                AssertThrows<ArgumentNullException>(delegate { new NativeBrokerManifestVerifier((IEnumerable<NativeBrokerTrustedKey>)null); }, "Null keys sequence rejected"); checks++;

                // Signature verification BEFORE JSON parse
                byte[] badJsonBytes = Encoding.UTF8.GetBytes("{ not valid json at all");
                string badJsonValidSig = SignBytes(signerA, badJsonBytes);
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifierA.VerifyAndParse(badJsonBytes, sigA); }, "Bad JSON + INVALID sig => SignatureRejected"); checks++;
                AssertThrows<FormatException>(delegate { verifierA.VerifyAndParse(badJsonBytes, badJsonValidSig); }, "Bad JSON + VALID sig => FormatException"); checks++;

                // Signature verification BEFORE UTF-8 decode
                byte[] badUtf8Bytes = new byte[] { 0xC0, 0xAF, 0x01, 0x02 };
                string badUtf8ValidSig = SignBytes(signerA, badUtf8Bytes);
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifierA.VerifyAndParse(badUtf8Bytes, sigA); }, "Bad UTF-8 + INVALID sig => SignatureRejected"); checks++;
                AssertThrows<FormatException>(delegate { verifierA.VerifyAndParse(badUtf8Bytes, badUtf8ValidSig); }, "Bad UTF-8 + VALID sig => FormatException"); checks++;

                return checks;
            });
        }

        // Group 3: Canonical base64 whitespace/padding/bits/bounds and key constructor pins
        public static int RunGroup3()
        {
            return RunOwnedGroup(delegate(GroupContext ctx)
            {
                int checks = 0;
                NativeBrokerTrustedKey keyA;
                var signerA = ctx.CreateKey("key_a", out keyA);

                string validJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}";
                byte[] raw = Encoding.UTF8.GetBytes(validJson);
                string sig = SignBytes(signerA, raw);
                var verifier = new NativeBrokerManifestVerifier(keyA);

                // Canonical base64 checks
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, sig.Insert(10, " ")); }, "Space in signature rejected"); checks++;
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, sig.Insert(10, "\n")); }, "Newline in signature rejected"); checks++;
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, sig.Insert(10, "\r")); }, "CR in signature rejected"); checks++;
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, sig.Insert(10, "\t")); }, "Tab in signature rejected"); checks++;
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, sig + "=="); }, "Trailing garbage rejected"); checks++;
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, null); }, "Null signature rejected"); checks++;
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, ""); }, "Empty signature rejected"); checks++;
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, new string('A', 16388)); }, "Signature > 16KiB rejected"); checks++;

                // Wrong length signature
                string shortSig = Convert.ToBase64String(new byte[128]);
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, shortSig); }, "Wrong width signature rejected"); checks++;

                // Noncanonical padding bits
                byte[] testBytes = new byte[] { 1, 2, 3, 4, 5 };
                string b64 = Convert.ToBase64String(testBytes);
                int padPos = b64.IndexOf('=');
                if (padPos > 0)
                {
                    char[] b64chars = b64.ToCharArray();
                    b64chars[padPos - 1] = (char)(b64chars[padPos - 1] ^ 1);
                    string nonCanonicalSig = new string(b64chars);
                    AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(raw, nonCanonicalSig); }, "Noncanonical padding bits rejected"); checks++;
                }

                // Key constructor pins
                byte[] validMod = keyA.GetModulusCopy();
                byte[] validExp = keyA.GetExponentCopy();

                AssertThrows<ArgumentNullException>(delegate { new NativeBrokerTrustedKey(null, validMod, validExp); }, "Null keyId rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("", validMod, validExp); }, "Empty keyId rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey(new string('a', 65), validMod, validExp); }, "KeyId > 64 rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("KEY_A", validMod, validExp); }, "Uppercase keyId rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("key-a", validMod, validExp); }, "Hyphen keyId rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("key a", validMod, validExp); }, "Space keyId rejected"); checks++;

                AssertThrows<ArgumentNullException>(delegate { new NativeBrokerTrustedKey("k", null, validExp); }, "Null modulus rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("k", new byte[128], validExp); }, "Modulus 128 bytes rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("k", new byte[255], validExp); }, "Modulus 255 bytes rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("k", new byte[513], validExp); }, "Modulus 513 bytes rejected"); checks++;

                byte[] evenMod = (byte[])validMod.Clone();
                evenMod[evenMod.Length - 1] = 0xFE;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("k", evenMod, validExp); }, "Even modulus rejected"); checks++;

                byte[] zeroFirstMod = (byte[])validMod.Clone();
                zeroFirstMod[0] = 0x7F;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("k", zeroFirstMod, validExp); }, "Modulus zero high-bit rejected"); checks++;

                AssertThrows<ArgumentNullException>(delegate { new NativeBrokerTrustedKey("k", validMod, null); }, "Null exponent rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("k", validMod, new byte[] { 5 }); }, "Bad exponent 5 rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("k", validMod, new byte[] { 0, 3 }); }, "Noncanonical exponent [0, 3] rejected"); checks++;
                AssertThrows<ArgumentException>(delegate { new NativeBrokerTrustedKey("k", validMod, new byte[] { 1, 0, 0 }); }, "Even exponent 65536 rejected"); checks++;

                // Defensive cloning
                byte[] modClone = (byte[])validMod.Clone();
                byte[] expClone = (byte[])validExp.Clone();
                var tk = new NativeBrokerTrustedKey("tk_test", modClone, expClone);
                modClone[0] = 0x00; expClone[0] = 0x00;
                Assert(tk.GetModulusCopy()[0] != 0x00 && tk.GetExponentCopy()[0] != 0x00, "Key constructor defensively clones arrays"); checks++;

                return checks;
            });
        }

        // Group 4: Strict JSON syntax, duplicate keys, number lexemes, comments/commas, format 2 checks
        public static int RunGroup4()
        {
            return RunOwnedGroup(delegate(GroupContext ctx)
            {
                int checks = 0;
                NativeBrokerTrustedKey keyA;
                var signerA = ctx.CreateKey("key_a", out keyA);
                var verifier = new NativeBrokerManifestVerifier(keyA);

                Action<string> assertValidSigThrowsFormat = delegate(string json)
                {
                    byte[] b = Encoding.UTF8.GetBytes(json);
                    string s = SignBytes(signerA, b);
                    AssertThrows<FormatException>(delegate { verifier.VerifyAndParse(b, s); }, "Expected FormatException for JSON: " + json);
                    checks++;
                };

                // Duplicate keys
                assertValidSigThrowsFormat("{\"format\":2,\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"sub\":{\"x\":1,\"x\":2}}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"a\":1,\"\\u0061\":2}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"k\":null,\"k\":1}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"arr\":[{\"y\":1,\"y\":2}]}");

                // Distinct case allowed
                string caseJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"foo\":1,\"Foo\":2}";
                byte[] caseBytes = Encoding.UTF8.GetBytes(caseJson);
                var caseEnv = verifier.VerifyAndParse(caseBytes, SignBytes(signerA, caseBytes));
                Assert(caseEnv.Root.Count == 7, "Distinct case keys allowed"); checks++;

                // Malformed JSON
                assertValidSigThrowsFormat("{\"format\":2,//comment\n\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,/*comment*/\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"a\":[1,]}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"} trailing");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"val\":\"bad\u0005char\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"val\":\"bad\\hchar\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\" \"x64\"}");

                // Bad numbers
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":+0}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":01}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":-01}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":1.}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":1e}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":1e+}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":NaN}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":Infinity}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"n\":0x10}");

                // Legal numbers in unknown fields
                string numJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"neg\":-123,\"frac\":0.456,\"exp\":1.23e-4,\"big\":-9876543210123456789.98765e+200}";
                byte[] numBytes = Encoding.UTF8.GetBytes(numJson);
                var numEnv = verifier.VerifyAndParse(numBytes, SignBytes(signerA, numBytes));
                Assert(numEnv.Root["neg"].GetNumberLexeme() == "-123", "Negative number lexeme"); checks++;
                Assert(numEnv.Root["frac"].GetNumberLexeme() == "0.456", "Fraction number lexeme"); checks++;
                Assert(numEnv.Root["exp"].GetNumberLexeme() == "1.23e-4", "Exponent number lexeme"); checks++;
                Assert(numEnv.Root["big"].GetNumberLexeme() == "-9876543210123456789.98765e+200", "Big number lexeme"); checks++;

                // Format field checks
                assertValidSigThrowsFormat("{\"format\":\"2\",\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2.0,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2e0,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":true,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");

                return checks;
            });
        }

        // Group 5: Strict Unicode, surrogates, UTF-8 validity
        public static int RunGroup5()
        {
            return RunOwnedGroup(delegate(GroupContext ctx)
            {
                int checks = 0;
                NativeBrokerTrustedKey keyA;
                var signerA = ctx.CreateKey("key_a", out keyA);
                var verifier = new NativeBrokerManifestVerifier(keyA);

                Action<string> assertValidSigThrowsFormat = delegate(string json)
                {
                    byte[] b = Encoding.UTF8.GetBytes(json);
                    string s = SignBytes(signerA, b);
                    AssertThrows<FormatException>(delegate { verifier.VerifyAndParse(b, s); }, "Expected FormatException for JSON: " + json);
                    checks++;
                };

                // Overlong UTF-8
                byte[] overlong = new byte[] { (byte)'{', 0xC0, 0xAF, (byte)'}' };
                string overlongSig = SignBytes(signerA, overlong);
                AssertThrows<FormatException>(delegate { verifier.VerifyAndParse(overlong, overlongSig); }, "Overlong UTF-8 rejected"); checks++;

                // Truncated UTF-8
                byte[] truncated = new byte[] { (byte)'{', 0xE2, 0x82, (byte)'}' };
                string truncSig = SignBytes(signerA, truncated);
                AssertThrows<FormatException>(delegate { verifier.VerifyAndParse(truncated, truncSig); }, "Truncated UTF-8 rejected"); checks++;

                // BOM at start
                byte[] bom = new byte[] { 0xEF, 0xBB, 0xBF, (byte)'{', (byte)'}' };
                string bomSig = SignBytes(signerA, bom);
                AssertThrows<FormatException>(delegate { verifier.VerifyAndParse(bom, bomSig); }, "BOM at start rejected"); checks++;

                // CESU-8 surrogate bytes
                byte[] cesu8 = new byte[] { (byte)'{', (byte)'"', (byte)'a', (byte)'"', (byte)':', (byte)'"', 0xED, 0xA0, 0x80, (byte)'"', (byte)'}' };
                string cesu8Sig = SignBytes(signerA, cesu8);
                AssertThrows<FormatException>(delegate { verifier.VerifyAndParse(cesu8, cesu8Sig); }, "CESU-8 surrogate bytes rejected"); checks++;

                // Escaped lone surrogates
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\\uD800\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\\uDC00\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\\uD800\\uD800\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\\uDC00\\uD800\"}");

                // Valid surrogate pair escaped
                string pairJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\\uD83D\\uDE00\"}";
                byte[] pairBytes = Encoding.UTF8.GetBytes(pairJson);
                var pairEnv = verifier.VerifyAndParse(pairBytes, SignBytes(signerA, pairBytes));
                Assert(pairEnv.Root["s"].GetString() == "\uD83D\uDE00", "Valid surrogate pair decoded"); checks++;

                // Raw UTF-8 emoji
                string rawEmojiJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\uD83D\uDE00\"}";
                byte[] rawEmojiBytes = Encoding.UTF8.GetBytes(rawEmojiJson);
                var rawEmojiEnv = verifier.VerifyAndParse(rawEmojiBytes, SignBytes(signerA, rawEmojiBytes));
                Assert(rawEmojiEnv.Root["s"].GetString() == "\uD83D\uDE00", "Raw UTF-8 emoji decoded"); checks++;

                // Positive MIX: RAW whole emoji (PAIRED \uD83D\uDE00 source) PLUS another ESCAPED whole pair \\uD83D\\uDE00 => expect TWO emojis
                string mixedJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\uD83D\uDE00\\uD83D\\uDE00\"}";
                byte[] mixedBytes = Encoding.UTF8.GetBytes(mixedJson);
                var mixedEnv = verifier.VerifyAndParse(mixedBytes, SignBytes(signerA, mixedBytes));
                Assert(mixedEnv.Root["s"].GetString() == "\uD83D\uDE00\uD83D\uDE00", "Raw paired emoji + escaped paired emoji decoded to two emojis"); checks++;

                // Explicit strict UTF-8 encoder rejects invalid raw half surrogate BEFORE signing
                var strictUtf8 = new UTF8Encoding(false, true);
                string loneHighRawJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\uD83D\"}";
                AssertThrows<EncoderFallbackException>(delegate { strictUtf8.GetBytes(loneHighRawJson); }, "Strict UTF-8 encoder rejects raw lone high surrogate before signing"); checks++;

                // Escaped lone low surrogate rejected by verifier
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"\\uDE00\"}");

                // Escaped control characters allowed in values
                string escJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"s\":\"tab\\there\\nnewline\\rcarriage\"}";
                byte[] escBytes = Encoding.UTF8.GetBytes(escJson);
                var escEnv = verifier.VerifyAndParse(escBytes, SignBytes(signerA, escBytes));
                Assert(escEnv.Root["s"].GetString() == "tab\there\nnewline\rcarriage", "Escaped control chars in value decoded"); checks++;

                return checks;
            });
        }

        // Group 6: Depth, node, string, property limits and raw/signature size bounds
        public static int RunGroup6()
        {
            return RunOwnedGroup(delegate(GroupContext ctx)
            {
                int checks = 0;
                NativeBrokerTrustedKey keyA;
                var signerA = ctx.CreateKey("key_a", out keyA);
                var verifier = new NativeBrokerManifestVerifier(keyA);

                Action<string> assertValidSigThrowsFormat = delegate(string json)
                {
                    byte[] b = Encoding.UTF8.GetBytes(json);
                    string s = SignBytes(signerA, b);
                    AssertThrows<FormatException>(delegate { verifier.VerifyAndParse(b, s); }, "Expected FormatException for JSON");
                    checks++;
                };

                // Nesting depth 64 accepted
                var sb64 = new StringBuilder();
                sb64.Append("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"wrap\":");
                for (int i = 0; i < 62; i++) sb64.Append("{\"w\":");
                sb64.Append("1");
                for (int i = 0; i < 62; i++) sb64.Append("}");
                sb64.Append("}");
                byte[] d64Bytes = Encoding.UTF8.GetBytes(sb64.ToString());
                var d64Env = verifier.VerifyAndParse(d64Bytes, SignBytes(signerA, d64Bytes));
                Assert(d64Env.Root.Count == 6, "Depth 64 accepted"); checks++;

                // Nesting depth 65 rejected
                var sb65 = new StringBuilder();
                sb65.Append("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"wrap\":");
                for (int i = 0; i < 63; i++) sb65.Append("{\"w\":");
                sb65.Append("1");
                for (int i = 0; i < 63; i++) sb65.Append("}");
                sb65.Append("}");
                assertValidSigThrowsFormat(sb65.ToString());

                // Node count: 300,000 accepted
                var sbNodesOk = new StringBuilder(1500000);
                sbNodesOk.Append("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"items\":[");
                for (int i = 0; i < 299993; i++) { if (i > 0) sbNodesOk.Append(","); sbNodesOk.Append("null"); }
                sbNodesOk.Append("]}");
                byte[] nodesOkBytes = Encoding.UTF8.GetBytes(sbNodesOk.ToString());
                var nodesOkEnv = verifier.VerifyAndParse(nodesOkBytes, SignBytes(signerA, nodesOkBytes));
                Assert(nodesOkEnv.Root["items"].AsArray().Count == 299993, "300000 nodes accepted"); checks++;

                // Node count: 300,001 rejected
                var sbNodesOver = new StringBuilder(1500000);
                sbNodesOver.Append("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"items\":[");
                for (int i = 0; i < 299994; i++) { if (i > 0) sbNodesOver.Append(","); sbNodesOver.Append("null"); }
                sbNodesOver.Append("]}");
                assertValidSigThrowsFormat(sbNodesOver.ToString());

                // String length 1,048,576 accepted
                string strMax = new string('x', 1048576);
                string strMaxJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"val\":\"" + strMax + "\"}";
                byte[] strMaxBytes = Encoding.UTF8.GetBytes(strMaxJson);
                var strMaxEnv = verifier.VerifyAndParse(strMaxBytes, SignBytes(signerA, strMaxBytes));
                Assert(strMaxEnv.Root["val"].GetString().Length == 1048576, "1MiB string accepted"); checks++;

                // String length 1,048,577 rejected
                string strOver = new string('x', 1048577);
                string strOverJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"val\":\"" + strOver + "\"}";
                assertValidSigThrowsFormat(strOverJson);

                // Property name length 32,768 accepted
                string propMax = new string('k', 32768);
                string propMaxJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"" + propMax + "\":1}";
                byte[] propMaxBytes = Encoding.UTF8.GetBytes(propMaxJson);
                var propMaxEnv = verifier.VerifyAndParse(propMaxBytes, SignBytes(signerA, propMaxBytes));
                Assert(propMaxEnv.Root.ContainsKey(propMax), "32768 property name accepted"); checks++;

                // Property name length 32,769 rejected
                string propOver = new string('k', 32769);
                string propOverJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"" + propOver + "\":1}";
                assertValidSigThrowsFormat(propOverJson);

                // Raw bound > 16MiB
                string dummySig = SignBytes(signerA, Encoding.UTF8.GetBytes("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}"));
                byte[] rawOver = new byte[16 * 1024 * 1024 + 1];
                AssertThrows<ArgumentException>(delegate { verifier.VerifyAndParse(rawOver, dummySig); }, "Raw > 16MiB rejected"); checks++;

                // Signature bound > 16KiB
                string sigOver = new string('A', 16 * 1024 + 4);
                byte[] smallRaw = Encoding.UTF8.GetBytes("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                AssertThrows<NativeBrokerSignatureRejectedException>(delegate { verifier.VerifyAndParse(smallRaw, sigOver); }, "Signature > 16KiB rejected"); checks++;

                // Int64 overflow
                string ovJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"ov\":9223372036854775808}";
                byte[] ovBytes = Encoding.UTF8.GetBytes(ovJson);
                var ovEnv = verifier.VerifyAndParse(ovBytes, SignBytes(signerA, ovBytes));
                Assert(ovEnv.Root["ov"].GetNumberLexeme() == "9223372036854775808", "Overflow lexeme preserved"); checks++;
                AssertThrows<FormatException>(delegate { ovEnv.Root["ov"].GetInt64(); }, "GetInt64 throws on overflow"); checks++;

                // Root array and scalar rejection
                assertValidSigThrowsFormat("[1, 2, 3]");
                assertValidSigThrowsFormat("\"scalar\"");
                assertValidSigThrowsFormat("123");
                assertValidSigThrowsFormat("true");

                // Missing critical fields
                assertValidSigThrowsFormat("{\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\"}");

                return checks;
            });
        }

        // Group 7: Signed full-realistic 2 inventory-shaped manifest with 20k source + 20k target files
        public static int RunGroup7()
        {
            return RunOwnedGroup(delegate(GroupContext ctx)
            {
                int checks = 0;
                NativeBrokerTrustedKey keyA;
                var signerA = ctx.CreateKey("key_a", out keyA);
                var verifier = new NativeBrokerManifestVerifier(keyA);

                var sbInv = new StringBuilder(2000000);
                sbInv.Append("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",");
                sbInv.Append("\"source_inventory\":[");
                for (int i = 0; i < 20000; i++)
                {
                    if (i > 0) sbInv.Append(",");
                    sbInv.Append("{\"p\":\"bin/f");
                    sbInv.Append(i.ToString(CultureInfo.InvariantCulture));
                    sbInv.Append(".dll\",\"s\":");
                    sbInv.Append((i * 10).ToString(CultureInfo.InvariantCulture));
                    sbInv.Append("}");
                }
                sbInv.Append("],\"target_inventory\":[");
                for (int i = 0; i < 20000; i++)
                {
                    if (i > 0) sbInv.Append(",");
                    sbInv.Append("{\"p\":\"bin/f");
                    sbInv.Append(i.ToString(CultureInfo.InvariantCulture));
                    sbInv.Append(".dll\",\"s\":");
                    sbInv.Append((i * 20).ToString(CultureInfo.InvariantCulture));
                    sbInv.Append("}");
                }
                sbInv.Append("]}");

                byte[] invBytes = Encoding.UTF8.GetBytes(sbInv.ToString());
                string invSig = SignBytes(signerA, invBytes);

                var invEnv = verifier.VerifyAndParse(invBytes, invSig);

                Assert(invEnv.Root["source_inventory"].AsArray().Count == 20000, "Source inventory has 20000 entries"); checks++;
                Assert(invEnv.Root["target_inventory"].AsArray().Count == 20000, "Target inventory has 20000 entries"); checks++;
                Assert(!invEnv.CanApply, "CanApply is false"); checks++;
                Assert(invEnv.SelectedTrustedKeyId == "key_a", "Trusted key key_a matched"); checks++;

                string invHash = ComputeSha256LowerHex(invBytes);
                Assert(invEnv.ManifestSha256 == invHash, "ManifestSha256 matches computed raw SHA"); checks++;
                Assert(invEnv.Format == 2, "Format is 2"); checks++;

                return checks;
            });
        }

        // Group 8: Pure component guard: CanApply=false, invalid values for protocol/platform/arch/format/operation, unverified fields not authorized
        public static int RunGroup8()
        {
            return RunOwnedGroup(delegate(GroupContext ctx)
            {
                int checks = 0;
                NativeBrokerTrustedKey keyA;
                var signerA = ctx.CreateKey("key_a", out keyA);
                var verifier = new NativeBrokerManifestVerifier(keyA);

                Action<string> assertValidSigThrowsFormat = delegate(string json)
                {
                    byte[] b = Encoding.UTF8.GetBytes(json);
                    string s = SignBytes(signerA, b);
                    AssertThrows<FormatException>(delegate { verifier.VerifyAndParse(b, s); }, "Expected FormatException for JSON: " + json);
                    checks++;
                };

                // Critical field values invalid
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":3,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"linux\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"arm\"}");
                assertValidSigThrowsFormat("{\"format\":1,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\"}");
                assertValidSigThrowsFormat("{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"full_update\",\"platform\":\"win32\",\"arch\":\"x64\"}");

                // Unknown root data strictly parsed but NOT authorized
                string unkJson = "{\"format\":2,\"minimum_updater_protocol\":2,\"operation\":\"shell_delta\",\"platform\":\"win32\",\"arch\":\"x64\",\"channel\":\"nightly\",\"backup_proof\":{\"receipt\":\"fake\"}}";
                byte[] unkBytes = Encoding.UTF8.GetBytes(unkJson);
                var unkEnv = verifier.VerifyAndParse(unkBytes, SignBytes(signerA, unkBytes));

                Assert(!unkEnv.CanApply, "CanApply remains strictly false"); checks++;
                Assert(unkEnv.Root.ContainsKey("backup_proof"), "Unknown backup_proof field parsed"); checks++;
                Assert(unkEnv.Root["backup_proof"].AsObject().ContainsKey("receipt"), "Nested receipt field parsed"); checks++;
                Assert(unkEnv.Root["backup_proof"].AsObject()["receipt"].GetString() == "fake", "Nested receipt value matches"); checks++;

                return checks;
            });
        }
    }
}
'@

# --- Section 4: Compile Production C# + Test Harness Single Assembly ---
$combinedCode = $prodCsCode + "`r`n" + $testHarnessCode

Add-Type -TypeDefinition $combinedCode -Language CSharp

# --- Section 5: Execute Test Groups ---
try {
    $g1 = [PosDesktop.Updates.Native.Tests.BrokerVerifierHarness]::RunGroup1()
    [Console]::WriteLine("PASS: Group 1 - Valid key, immutable tree, int64 lexeme > 2^53, raw isolation ($g1 checks)")

    $g2 = [PosDesktop.Updates.Native.Tests.BrokerVerifierHarness]::RunGroup2()
    [Console]::WriteLine("PASS: Group 2 - Real crypto tamper, untrusted key, rotation, sig before parse ($g2 checks)")

    $g3 = [PosDesktop.Updates.Native.Tests.BrokerVerifierHarness]::RunGroup3()
    [Console]::WriteLine("PASS: Group 3 - Canonical Base64 rules and key constructor pins ($g3 checks)")

    $g4 = [PosDesktop.Updates.Native.Tests.BrokerVerifierHarness]::RunGroup4()
    [Console]::WriteLine("PASS: Group 4 - Strict JSON grammar, duplicate keys, number lexemes ($g4 checks)")

    $g5 = [PosDesktop.Updates.Native.Tests.BrokerVerifierHarness]::RunGroup5()
    [Console]::WriteLine("PASS: Group 5 - Strict Unicode, surrogates, UTF-8 validity ($g5 checks)")

    $g6 = [PosDesktop.Updates.Native.Tests.BrokerVerifierHarness]::RunGroup6()
    [Console]::WriteLine("PASS: Group 6 - Depth, node, string limits and raw/signature bounds ($g6 checks)")

    $g7 = [PosDesktop.Updates.Native.Tests.BrokerVerifierHarness]::RunGroup7()
    [Console]::WriteLine("PASS: Group 7 - Representative 40k file inventory signed envelope ($g7 checks)")

    $g8 = [PosDesktop.Updates.Native.Tests.BrokerVerifierHarness]::RunGroup8()
    [Console]::WriteLine("PASS: Group 8 - Pure component guard, CanApply=false, envelope pins ($g8 checks)")

    $totalChecks = $g1 + $g2 + $g3 + $g4 + $g5 + $g6 + $g7 + $g8
    [Console]::WriteLine("ALL TESTS PASSED: $totalChecks assertions verified successfully across 8 groups.")
    exit 0
}
catch {
    $ex = $_.Exception
    while ($ex -is [System.Reflection.TargetInvocationException] -or $ex -is [System.Management.Automation.MethodInvocationException]) {
        if ($ex.InnerException -ne $null) {
            $ex = $ex.InnerException
        } else {
            break
        }
    }
    [Console]::Error.WriteLine("TEST FAILURE: $($ex.GetType().FullName): $($ex.Message)")
    [Console]::Error.WriteLine($ex.StackTrace)
    exit 1
}
