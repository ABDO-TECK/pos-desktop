# scripts/verify-shell-native-broker-binding.ps1
# Pure Native Broker Context Binding & Semantic Verification Runner
# Runs inside Windows GitHub Actions under PowerShell 5.1 Desktop x64.

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$FixtureFile
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

# --- Section 1: Strict Execution Environment Guards ---
if ($env:GITHUB_ACTIONS -ne 'true') { [Console]::Error.WriteLine("GUARD: GITHUB_ACTIONS=true required."); exit 1 }
if ($env:RUNNER_OS -ne 'Windows') { [Console]::Error.WriteLine("GUARD: RUNNER_OS must be 'Windows'."); exit 1 }
if ([System.Environment]::OSVersion.Platform -ne [System.PlatformID]::Win32NT) { [Console]::Error.WriteLine("GUARD: Win32NT required."); exit 1 }
if ($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) { [Console]::Error.WriteLine("GUARD: PS 5.1 Desktop required."); exit 1 }
if (-not [System.Environment]::Is64BitProcess -or -not [System.Environment]::Is64BitOperatingSystem) { [Console]::Error.WriteLine("GUARD: 64-bit required."); exit 1 }

# --- Section 2: Locate Fixture & Production C# Sources ---
$resolvedFixture = $FixtureFile
if (-not [System.IO.Path]::IsPathRooted($resolvedFixture)) {
    $resolvedFixture = Join-Path (Get-Location) $resolvedFixture
}
$resolvedFixture = [System.IO.Path]::GetFullPath($resolvedFixture)

if (-not (Test-Path -LiteralPath $resolvedFixture)) {
    [Console]::Error.WriteLine("ERROR: Fixture file not found at: $resolvedFixture")
    exit 1
}

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = (Resolve-Path "$scriptRoot\..").Path
$verifierCsPath = Join-Path $repoRoot "backend\Native\NativeBrokerManifestVerifier.cs"
$bindingCsPath = Join-Path $repoRoot "backend\Native\NativeBrokerContextBinding.cs"

if (-not (Test-Path -LiteralPath $verifierCsPath)) {
    [Console]::Error.WriteLine("ERROR: Verifier C# file not found at: $verifierCsPath")
    exit 1
}
if (-not (Test-Path -LiteralPath $bindingCsPath)) {
    [Console]::Error.WriteLine("ERROR: Binding C# file not found at: $bindingCsPath")
    exit 1
}

# --- Section 3: Compile Production C# and Test Harness via Add-Type ---
$harnessCsCode = @"
namespace PosDesktop.Updates.Native.Tests
{
    using System;
    using System.Reflection;
    using PosDesktop.Updates.Native;

    public static class BrokerBindingHarness
    {
        public static void AssertThrows<TException>(Action action, string label) where TException : Exception
        {
            if (action == null) throw new ArgumentNullException("action");
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
                throw new InvalidOperationException(
                    string.Format("Expected {0} for '{1}', but caught {2}: {3}",
                        typeof(TException).Name, label, ex.GetType().Name, ex.Message), ex);
            }
            throw new InvalidOperationException(
                string.Format("Expected {0} for '{1}', but no exception was thrown.",
                    typeof(TException).Name, label));
        }

        public static int RunNativeSemverGuards()
        {
            int assertCount = 0;
            string hex = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";

            // Valid length 128 boundary: "1.0.0+" (6 chars) + 122 chars = 128 chars
            string validLen128 = "1.0.0+" + new string('a', 122);
            // Invalid length 129 boundary: "1.0.0+" (6 chars) + 123 chars = 129 chars
            string invalidLen129 = "1.0.0+" + new string('a', 123);

            string[] badNatives = new string[]
            {
                "1.0.0\n",
                "1.0.0\r\n",
                "1.0.0 ",
                "1.0.0\0",
                "\u0661.0.0",
                "1.0.0-01",
                invalidLen129
            };

            for (int i = 0; i < badNatives.Length; i++)
            {
                string bad = badNatives[i];
                if (NativeBrokerContextBinding.IsValidSemver2(bad))
                {
                    throw new InvalidOperationException("Expected IsValidSemver2 to return false for bad native: " + bad);
                }
                assertCount++;

                AssertThrows<ArgumentException>(() => new NativeBrokerEndpointIdentity("1.0.0", "stable", bad, hex, hex), "Bad native in endpoint ctor: " + bad);
                assertCount++;
            }

            string[] validNatives = new string[]
            {
                "1.0.0-alpha.1+build.001",
                "1.0.0-0",
                "1.0.0+001",
                validLen128
            };

            for (int i = 0; i < validNatives.Length; i++)
            {
                string valid = validNatives[i];
                if (!NativeBrokerContextBinding.IsValidSemver2(valid))
                {
                    throw new InvalidOperationException("Expected IsValidSemver2 to return true for valid native: " + valid);
                }
                assertCount++;

                var ep = new NativeBrokerEndpointIdentity("1.0.0", "stable", valid, hex, hex);
                if (ep.NativeVersion != valid)
                {
                    throw new InvalidOperationException("Endpoint identity nativeVersion mismatch.");
                }
                assertCount++;
            }

            return assertCount;
        }

        public static void TestConstructorNullGuards()
        {
            string hex = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";
            var validEp = new NativeBrokerEndpointIdentity("1.0.0", "stable", "1.0.0", hex, hex);
            var validDc = new NativeBrokerDataContractContext(hex, hex);

            // NativeBrokerEndpointIdentity: 5 constructor parameters
            AssertThrows<ArgumentNullException>(() => new NativeBrokerEndpointIdentity(null, "stable", "1.0.0", hex, hex), "Endpoint appVersion null");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerEndpointIdentity("1.0.0", null, "1.0.0", hex, hex), "Endpoint channel null");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerEndpointIdentity("1.0.0", "stable", null, hex, hex), "Endpoint nativeVersion null");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerEndpointIdentity("1.0.0", "stable", "1.0.0", null, hex), "Endpoint inventorySha null");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerEndpointIdentity("1.0.0", "stable", "1.0.0", hex, null), "Endpoint nativeShellSha null");

            // NativeBrokerDataContractContext: 2 constructor parameters
            AssertThrows<ArgumentNullException>(() => new NativeBrokerDataContractContext(null, hex), "DataContract schemaSha null");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerDataContractContext(hex, null), "DataContract ledgerSha null");

            // NativeBrokerBindingContext: 4 and 5 parameter constructors
            AssertThrows<ArgumentNullException>(() => new NativeBrokerBindingContext(null, validEp, hex, validDc), "Context source null (4-arg)");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerBindingContext(validEp, null, hex, validDc), "Context target null (4-arg)");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerBindingContext(validEp, validEp, null, validDc), "Context expectedSha null (4-arg)");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerBindingContext(validEp, validEp, hex, null), "Context dataContract null (4-arg)");

            AssertThrows<ArgumentNullException>(() => new NativeBrokerBindingContext(null, validEp, hex, validDc, "key1"), "Context source null (5-arg)");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerBindingContext(validEp, null, hex, validDc, "key1"), "Context target null (5-arg)");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerBindingContext(validEp, validEp, null, validDc, "key1"), "Context expectedSha null (5-arg)");
            AssertThrows<ArgumentNullException>(() => new NativeBrokerBindingContext(validEp, validEp, hex, null, "key1"), "Context dataContract null (5-arg)");
            AssertThrows<ArgumentException>(() => new NativeBrokerBindingContext(validEp, validEp, hex, validDc, ""), "Context empty expectedTrustedKeyId");

            // ComputeCanonicalShellDigest null and lowercase hex validation
            AssertThrows<ArgumentNullException>(() => NativeBrokerContextBinding.ComputeCanonicalShellDigest(null, hex), "ComputeCanonicalShellDigest exeHash null");
            AssertThrows<ArgumentNullException>(() => NativeBrokerContextBinding.ComputeCanonicalShellDigest(hex, null), "ComputeCanonicalShellDigest asarHash null");
            AssertThrows<ArgumentException>(() => NativeBrokerContextBinding.ComputeCanonicalShellDigest("UPPER" + hex.Substring(5), hex), "ComputeCanonicalShellDigest upper exeHash");
            AssertThrows<ArgumentException>(() => NativeBrokerContextBinding.ComputeCanonicalShellDigest(hex, "UPPER" + hex.Substring(5)), "ComputeCanonicalShellDigest upper asarHash");
        }

        public static void TestReflectionImmutability()
        {
            Type[] types = new Type[]
            {
                typeof(NativeBrokerBindingContext),
                typeof(NativeBrokerEndpointIdentity),
                typeof(NativeBrokerDataContractContext),
                typeof(NativeBrokerBoundResult)
            };

            foreach (Type t in types)
            {
                if (!t.IsSealed)
                {
                    throw new InvalidOperationException("Type " + t.FullName + " must be sealed.");
                }

                PropertyInfo[] props = t.GetProperties(BindingFlags.Public | BindingFlags.Instance);
                foreach (PropertyInfo p in props)
                {
                    if (p.GetSetMethod() != null || p.GetSetMethod(true) != null)
                    {
                        throw new InvalidOperationException("Property " + t.FullName + "." + p.Name + " must not have any setter.");
                    }
                    if (p.GetGetMethod() == null)
                    {
                        throw new InvalidOperationException("Property " + t.FullName + "." + p.Name + " must have a public getter.");
                    }
                }

                FieldInfo[] fields = t.GetFields(BindingFlags.Public | BindingFlags.NonPublic | BindingFlags.Instance);
                foreach (FieldInfo f in fields)
                {
                    if (!f.IsInitOnly)
                    {
                        throw new InvalidOperationException("Field " + t.FullName + "." + f.Name + " must be init-only (readonly).");
                    }
                }
            }
        }
    }
}
"@

$verifierCsCode = [System.IO.File]::ReadAllText($verifierCsPath, [System.Text.Encoding]::UTF8)
$bindingCsCode = [System.IO.File]::ReadAllText($bindingCsPath, [System.Text.Encoding]::UTF8)
$combinedCode = $verifierCsCode + "`r`n" + $bindingCsCode + "`r`n" + $harnessCsCode

try {
    Add-Type -TypeDefinition $combinedCode -Language CSharp
    [Console]::WriteLine("SUCCESS: Production C# and test harness compiled successfully via Add-Type.")
}
catch {
    [Console]::Error.WriteLine("ERROR: Failed to compile C# sources:")
    [Console]::Error.WriteLine($_.Exception.ToString())
    exit 1
}

# --- Section 4: Parse Fixture JSON ---
$fixtureRaw = [System.IO.File]::ReadAllText($resolvedFixture, [System.Text.Encoding]::UTF8)
$fixture = ConvertFrom-Json -InputObject $fixtureRaw

$keyId = [string]$fixture.public_key.key_id
$modulusBytes = [System.Convert]::FromBase64String([string]$fixture.public_key.modulus_base64)
$exponentBytes = [System.Convert]::FromBase64String([string]$fixture.public_key.exponent_base64)

$trustedKey = New-Object PosDesktop.Updates.Native.NativeBrokerTrustedKey($keyId, $modulusBytes, $exponentBytes)
$verifier = New-Object PosDesktop.Updates.Native.NativeBrokerManifestVerifier([PosDesktop.Updates.Native.NativeBrokerTrustedKey[]]@($trustedKey))

$assertionCount = 0

function Assert-Condition {
    param([bool]$condition, [string]$message)
    if (-not $condition) {
        throw [System.InvalidOperationException]"ASSERTION FAILED: $message"
    }
    $script:assertionCount++
}

# --- Section 5: SemVer Golden Ordering Unit Checks ---
[Console]::WriteLine("--> Running Section 5: SemVer Golden Ordering Checks...")
foreach ($item in $fixture.semver_golden) {
    $srcVer = [string]$item.source
    $tgtVer = [string]$item.target
    $expectedNewer = [bool]$item.expected_strictly_newer
    $phpActualNewer = [bool]$item.php_actual_strictly_newer
    $isWideInt = [bool]$item.is_wide_int

    $srcChan = if ($srcVer.Contains("-beta.")) { "beta" } else { "stable" }
    $tgtChan = if ($tgtVer.Contains("-beta.")) { "beta" } else { "stable" }

    $actualNewer = [PosDesktop.Updates.Native.NativeBrokerContextBinding]::IsTargetStrictlyNewer($srcVer, $srcChan, $tgtVer, $tgtChan)
    Assert-Condition ($actualNewer -eq $expectedNewer) "SemVer Golden ordering '$srcVer' ($srcChan) -> '$tgtVer' ($tgtChan) expected newer=$expectedNewer, got $actualNewer"

    if (-not $isWideInt) {
        Assert-Condition ($actualNewer -eq $phpActualNewer) "SemVer PHP Golden match '$srcVer' ($srcChan) -> '$tgtVer' ($tgtChan) expected phpActualNewer=$phpActualNewer, got $actualNewer"
    }
    elseif ($actualNewer -ne $phpActualNewer) {
        [Console]::WriteLine("  [NOTICE] SemVer wide-int component divergence: Math/Native strictly newer=$actualNewer, PHP version_compare strictly newer=$phpActualNewer")
    }
}
[Console]::WriteLine("PASS: Section 5 SemVer Golden Ordering verified.")

# --- Section 6: Canonical Shell Digest Cross-Language Golden Verification ---
[Console]::WriteLine("--> Running Section 6: Canonical Shell Digest Golden Checks...")
$goldenExeSha = [string]$fixture.canonical_shell_golden.exe_sha
$goldenAsarSha = [string]$fixture.canonical_shell_golden.asar_sha
$goldenExpectedDigest = [string]$fixture.canonical_shell_golden.expected_digest

$csharpDigest = [PosDesktop.Updates.Native.NativeBrokerContextBinding]::ComputeCanonicalShellDigest($goldenExeSha, $goldenAsarSha)
Assert-Condition ($csharpDigest -eq $goldenExpectedDigest) "ComputeCanonicalShellDigest matches PHP golden digest ($csharpDigest == $goldenExpectedDigest)"
[Console]::WriteLine("PASS: Section 6 Canonical Shell Digest cross-language golden verified.")

# --- Section 7: C# Test Harness: Immutability & Constructor Null Guards ---
[Console]::WriteLine("--> Running Section 7: Harness Immutability & Constructor Guard Checks...")
$semverGuardAsserts = [PosDesktop.Updates.Native.Tests.BrokerBindingHarness]::RunNativeSemverGuards()
$script:assertionCount += $semverGuardAsserts
[Console]::WriteLine("  Native SemVer regex boundary & regression guards verified ($semverGuardAsserts assertions).")

[PosDesktop.Updates.Native.Tests.BrokerBindingHarness]::TestReflectionImmutability()
Assert-Condition $true "Reflection immutability verification passed"

[PosDesktop.Updates.Native.Tests.BrokerBindingHarness]::TestConstructorNullGuards()
Assert-Condition $true "C# constructor null guards verification passed"

# Additional PowerShell-level context constructor checks (cross-channel rejection)
$dummyExeSha = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
$srcId = New-Object PosDesktop.Updates.Native.NativeBrokerEndpointIdentity("1.0.0", "stable", "1.0.0", $dummyExeSha, $dummyExeSha)
$tgtId = New-Object PosDesktop.Updates.Native.NativeBrokerEndpointIdentity("1.0.1-beta.1", "beta", "1.0.1", $dummyExeSha, $dummyExeSha)
$dc = New-Object PosDesktop.Updates.Native.NativeBrokerDataContractContext($dummyExeSha, $dummyExeSha)

$channelCrossCaught = $false
try {
    $badCtx = New-Object PosDesktop.Updates.Native.NativeBrokerBindingContext($srcId, $tgtId, $dummyExeSha, $dc, "key1")
}
catch [System.Management.Automation.MethodInvocationException] {
    if ($_.Exception.InnerException -is [System.ArgumentException]) {
        $channelCrossCaught = $true
    }
}
catch [System.ArgumentException] {
    $channelCrossCaught = $true
}
Assert-Condition $channelCrossCaught "Cross-channel context binding throws ArgumentException"

[Console]::WriteLine("PASS: Section 7 Immutability and constructor guards verified.")

# --- Section 8: Execute All Fixture Test Cases ---
[Console]::WriteLine("--> Running Section 8: Executing $($fixture.cases.Count) Test Cases...")

$passedCases = 0
foreach ($case in $fixture.cases) {
    $caseId = [string]$case.id
    $group = [string]$case.group
    $desc = [string]$case.description
    $expectedOk = [bool]$case.expected_ok
    $expectedReasonCode = if ($case.expected_reason_code -ne $null) { [string]$case.expected_reason_code } else { $null }
    $expectedVerifierRejected = [bool]$case.expected_verifier_rejected

    $rawBytes = [System.Convert]::FromBase64String([string]$case.raw_manifest_base64)
    $sigBase64 = [string]$case.signature_base64

    $ctxData = $case.context
    $srcIdentity = New-Object PosDesktop.Updates.Native.NativeBrokerEndpointIdentity(
        [string]$ctxData.source.app_version,
        [string]$ctxData.source.channel,
        [string]$ctxData.source.native_version,
        [string]$ctxData.source.inventory_sha256,
        [string]$ctxData.source.native_shell_sha256
    )
    $tgtIdentity = New-Object PosDesktop.Updates.Native.NativeBrokerEndpointIdentity(
        [string]$ctxData.target.app_version,
        [string]$ctxData.target.channel,
        [string]$ctxData.target.native_version,
        [string]$ctxData.target.inventory_sha256,
        [string]$ctxData.target.native_shell_sha256
    )
    $dataContract = New-Object PosDesktop.Updates.Native.NativeBrokerDataContractContext(
        [string]$ctxData.data_contract.schema_sha256,
        [string]$ctxData.data_contract.ledger_sha256
    )
    $expectedKey = if ($ctxData.expected_key_id -ne $null) { [string]$ctxData.expected_key_id } else { $null }
    $bindingContext = New-Object PosDesktop.Updates.Native.NativeBrokerBindingContext(
        $srcIdentity,
        $tgtIdentity,
        [string]$ctxData.expected_manifest_sha256,
        $dataContract,
        $expectedKey
    )

    if ($expectedVerifierRejected) {
        $verifierThrew = $false
        try {
            $envelope = $verifier.VerifyAndParse($rawBytes, $sigBase64)
        }
        catch [PosDesktop.Updates.Native.NativeBrokerSignatureRejectedException] {
            $verifierThrew = $true
            Assert-Condition $true "Case '$caseId' signature verification failed as expected"
        }
        Assert-Condition $verifierThrew "Case '$caseId' expected NativeBrokerSignatureRejectedException but none was thrown"
    }
    else {
        # Signature verification MUST succeed
        $envelope = $verifier.VerifyAndParse($rawBytes, $sigBase64)
        Assert-Condition ($envelope -ne $null) "Case '$caseId' envelope parsed"
        Assert-Condition ($envelope.CanApply -eq $false) "Case '$caseId' envelope CanApply is false"

        if ($expectedOk) {
            # Context binding MUST succeed
            $result = [PosDesktop.Updates.Native.NativeBrokerContextBinding]::Bind($envelope, $bindingContext)
            Assert-Condition ($result -ne $null) "Case '$caseId' bound result returned"
            Assert-Condition ($result.CanApply -eq $false) "Case '$caseId' bound result CanApply is strictly false"
            Assert-Condition ($result.Envelope -eq $envelope) "Case '$caseId' bound result envelope matches input"
            Assert-Condition ($result.Context -eq $bindingContext) "Case '$caseId' bound result context matches input"
            Assert-Condition ($result.Envelope.CanApply -eq $false) "Case '$caseId' envelope immutability verified"

            if ($group -eq 'limit_boundary') {
                [Console]::WriteLine("  [LIMIT PROOF] ${caseId}: pure binding accepted with CanApply=$($result.CanApply) (honest layer boundary)")
            }
        }
        else {
            # Context binding MUST throw NativeBrokerBindingException with exact ReasonCode
            $bindingThrew = $false
            $actualReason = $null
            try {
                $result = [PosDesktop.Updates.Native.NativeBrokerContextBinding]::Bind($envelope, $bindingContext)
            }
            catch [PosDesktop.Updates.Native.NativeBrokerBindingException] {
                $bindingThrew = $true
                $actualReason = $_.Exception.ReasonCode
            }
            catch {
                $bindingThrew = $true
                $actualReason = "UNEXPECTED_EXCEPTION:" + $_.Exception.GetType().FullName
            }

            Assert-Condition $bindingThrew "Case '$caseId' expected NativeBrokerBindingException but none was thrown"
            Assert-Condition ($actualReason -eq $expectedReasonCode) "Case '$caseId' expected ReasonCode '$expectedReasonCode', got '$actualReason'"
        }
    }

    $passedCases++
    [Console]::WriteLine("  [PASS] Case $passedCases/$($fixture.cases.Count): $caseId ($group)")
}

[Console]::WriteLine("ALL $passedCases TEST CASES PASSED SUCCESSFULLY.")
[Console]::WriteLine("Total assertions verified: $assertionCount")
exit 0
