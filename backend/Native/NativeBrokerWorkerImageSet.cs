// backend/Native/NativeBrokerWorkerImageSet.cs
//
// INTERNAL Win32 signed source-worker image map projection and multi-file set lease primitive.
// C# 5.0 / .NET Framework 4.8 x64 compatible. Namespace PosDesktop.Updates.Native.
//
// Architecture & Trust Notice:
// 1. Projection Authority: NativeBrokerWorkerImageMap projects an immutable, strictly validated subset
//    derived ONLY from signed bound.Envelope.Root['source_inventory']['files']. The expected SHA-256
//    digests and sizes within the map represent signed integrity expectations authorized by the
//    trusted SIGNER envelope, NOT a hardware physical receipt or metadata-source proof of presence.
// 2. Execution Limitations (CanApply and CanLaunch ALWAYS False): Both NativeBrokerWorkerImageMap and
//    WorkerImageSetLease declare CanApply = false and CanLaunch = false. Neither this map projection
//    nor this set lease grants process execution or launch authority. NO statement is made that
//    "all code is authenticated" or "ready to launch".
// 3. Namespace & PE Closure Limits: Leasing selected expected files and locking ancestor directory
//    names (FILE_SHARE_READ, denying WRITE and DELETE) prevents writers, deleters, and path replacements
//    of the pinned files and directories. However, leasing existing files and directory names does NOT
//    prevent arbitrary NEW file names from being created inside those directories, and does NOT prove
//    complete OS, PE, or dynamic loader (LoadLibrary) dependency closure. Compiled host keys,
//    OS metadata, full PKI bootstrap pinning, and sandbox containment remain future integration scope.
// 4. Scope Limitation: This projection selects ONLY the required worker runtime subset (PHP executable,
//    core DLLs, required extensions, backend PHAR, and update certificate). It does NOT enumerate the
//    whole product or user data for unknown files, does NOT calculate full source/target canonical digests,
//    does NOT validate package diffs or database state, and does NOT replace the full PHP manifest validator.
// 5. Lifecycle Contract (Root DEAD + Job 0): The caller MUST retain the acquired WorkerImageSetLease
//    until the owned worker child process is confirmed DEAD and its Job Object active count is 0
//    (Root DEAD + Job 0). The set lease does NOT own the child process.
//
// All operations fail closed. No mutable state or simulated trust accepted.

namespace PosDesktop.Updates.Native
{
    using System;
    using System.Collections.Generic;
    using System.Collections.ObjectModel;
    using System.ComponentModel;
    using System.Globalization;
    using System.IO;
    using System.Runtime.ExceptionServices;
    using System.Text;

    /// <summary>
    /// Captures immutable metadata for a single selected worker image file entry.
    /// </summary>
    public sealed class WorkerImageEntry
    {
        private readonly string _relativePath;
        private readonly long _size;
        private readonly string _sha256;

        internal WorkerImageEntry(string relativePath, long size, string sha256)
        {
            this._relativePath = relativePath;
            this._size = size;
            this._sha256 = sha256;
        }

        public string RelativePath { get { return this._relativePath; } }
        public long Size { get { return this._size; } }
        public string Sha256 { get { return this._sha256; } }

        public override string ToString()
        {
            return string.Format(CultureInfo.InvariantCulture, "{0} ({1} bytes, sha256={2})", this._relativePath, this._size, this._sha256);
        }
    }

    /// <summary>
    /// Exception thrown when validation fails during worker image set lease acquisition and subsequent
    /// handle cleanup is uncertain or leaves unclosed leases, preserving the retained unclosed leases.
    /// </summary>
    public sealed class WorkerImageSetLeaseAcquisitionException : Exception
    {
        private readonly WorkerImageSetLease _retainedSet;
        private readonly ReadOnlyCollection<WorkerImageReadLease> _retainedLeases;
        private readonly ReadOnlyCollection<Exception> _cleanupExceptions;
        private readonly int _firstNativeError;

        public WorkerImageSetLeaseAcquisitionException(
            string message,
            Exception innerException,
            WorkerImageSetLease retainedSet,
            int firstNativeError)
            : this(message, innerException, retainedSet, null, null, firstNativeError)
        {
        }

        public WorkerImageSetLeaseAcquisitionException(
            string message,
            Exception innerException,
            WorkerImageSetLease retainedSet,
            ReadOnlyCollection<WorkerImageReadLease> retainedLeases,
            ReadOnlyCollection<Exception> cleanupExceptions,
            int firstNativeError)
            : base(message, innerException)
        {
            this._retainedSet = retainedSet;
            this._retainedLeases = retainedLeases ?? new List<WorkerImageReadLease>().AsReadOnly();
            this._cleanupExceptions = cleanupExceptions ?? new List<Exception>().AsReadOnly();
            this._firstNativeError = firstNativeError;
        }

        public WorkerImageSetLease RetainedSet { get { return this._retainedSet; } }
        public ReadOnlyCollection<WorkerImageReadLease> RetainedLeases { get { return this._retainedLeases; } }
        public WorkerImageReadLease RetainedLease { get { return (this._retainedLeases != null && this._retainedLeases.Count > 0) ? this._retainedLeases[0] : null; } }
        public ReadOnlyCollection<Exception> CleanupExceptions { get { return this._cleanupExceptions; } }
        public int FirstNativeError { get { return this._firstNativeError; } }
        public int CloseError { get { return this._firstNativeError; } }
    }

    /// <summary>
    /// Exception thrown when disposal or handle cleanup fails on a worker image set lease,
    /// preserving the retained set and diagnostic error context for caller retention.
    /// </summary>
    public sealed class WorkerImageSetLeaseCleanupException : Exception
    {
        private readonly WorkerImageSetLease _retainedSet;
        private readonly ReadOnlyCollection<WorkerImageReadLease> _retainedLeases;
        private readonly int _nativeError;

        public WorkerImageSetLeaseCleanupException(
            string message,
            Exception innerException,
            WorkerImageSetLease retainedSet,
            int nativeError)
            : this(message, innerException, retainedSet, null, nativeError)
        {
        }

        public WorkerImageSetLeaseCleanupException(
            string message,
            Exception innerException,
            WorkerImageSetLease retainedSet,
            ReadOnlyCollection<WorkerImageReadLease> retainedLeases,
            int nativeError)
            : base(message, innerException)
        {
            this._retainedSet = retainedSet;
            this._retainedLeases = retainedLeases ?? new List<WorkerImageReadLease>().AsReadOnly();
            this._nativeError = nativeError;
        }

        public WorkerImageSetLease RetainedSet { get { return this._retainedSet; } }
        public ReadOnlyCollection<WorkerImageReadLease> RetainedLeases { get { return this._retainedLeases; } }
        public WorkerImageReadLease RetainedLease { get { return (this._retainedLeases != null && this._retainedLeases.Count > 0) ? this._retainedLeases[0] : null; } }
        public int NativeError { get { return this._nativeError; } }
    }

    /// <summary>
    /// Immutable projection of selected worker runtime images derived strictly from a cryptographically
    /// verified broker envelope (source_inventory.files). Pure memory, zero filesystem I/O.
    /// </summary>
    public sealed class NativeBrokerWorkerImageMap
    {
        public const string RequiredPhpExe = "resources/portable/php/php.exe";
        public const string RequiredPhpOpensslDll = "resources/portable/php/ext/php_openssl.dll";
        public const string RequiredPhpZipDll = "resources/portable/php/ext/php_zip.dll";
        public const string RequiredPhpMbstringDll = "resources/portable/php/ext/php_mbstring.dll";
        public const string RequiredBackendPhar = "resources/app.asar.unpacked/backend/backend.phar";
        public const string RequiredUpdatePublicKey = "resources/app.asar.unpacked/backend/certs/update_public_key.pem";
        public const string OptionalCacertPem = "resources/app.asar.unpacked/backend/certs/cacert.pem";
        public const string PhpPrefix = "resources/portable/php/";
        public const string Php8Dll = "resources/portable/php/php8.dll";
        public const string Php8TsDll = "resources/portable/php/php8ts.dll";

        public const int MaxSourceInventoryFiles = 20000;
        public const int MaxSelectedFiles = 1024;
        public const long MaxSingleFileSize = 262144000L; // 250 MB (250 * 1024 * 1024)
        public const long MaxTotalPayloadSize = 1073741824L; // 1 GiB (1024 * 1024 * 1024)
        public const long MaxSafeInteger = 9007199254740991L;
        public const int MaxPathLength = 512;
        public const int MaxPathDepth = 32;

        private readonly string _manifestSha256;
        private readonly string _selectedTrustedKeyId;
        private readonly string _sourceInventorySha256;
        private readonly long _totalBytes;
        private readonly ReadOnlyCollection<WorkerImageEntry> _entries;

        internal NativeBrokerWorkerImageMap(
            string manifestSha256,
            string selectedTrustedKeyId,
            string sourceInventorySha256,
            long totalBytes,
            ReadOnlyCollection<WorkerImageEntry> entries)
        {
            this._manifestSha256 = manifestSha256;
            this._selectedTrustedKeyId = selectedTrustedKeyId;
            this._sourceInventorySha256 = sourceInventorySha256;
            this._totalBytes = totalBytes;
            this._entries = entries;
        }

        public string ManifestSha256 { get { return this._manifestSha256; } }
        public string SelectedTrustedKeyId { get { return this._selectedTrustedKeyId; } }
        public string SourceInventorySha256 { get { return this._sourceInventorySha256; } }
        public long TotalBytes { get { return this._totalBytes; } }
        public ReadOnlyCollection<WorkerImageEntry> Entries { get { return this._entries; } }
        public int Count { get { return this._entries.Count; } }
        public bool CanApply { get { return false; } }
        public bool CanLaunch { get { return false; } }

        /// <summary>
        /// Projects a strictly authenticated worker image map from a bound broker context result.
        /// Selection is derived ONLY from signed bound.Envelope.Root['source_inventory']['files'].
        /// Pure in-memory factory; performs no filesystem I/O.
        /// </summary>
        public static NativeBrokerWorkerImageMap FromBoundResult(NativeBrokerBoundResult bound)
        {
            if (bound == null)
            {
                throw new ArgumentNullException("bound");
            }
            if (bound.Envelope == null)
            {
                throw new ArgumentException("Bound result has null Envelope.", "bound");
            }
            if (bound.Context == null)
            {
                throw new ArgumentException("Bound result has null Context.", "bound");
            }
            if (bound.Context.Source == null)
            {
                throw new ArgumentException("Bound result has null Context.Source.", "bound");
            }
            if (bound.Envelope.Root == null)
            {
                throw new ArgumentException("Bound result has null Envelope.Root.", "bound");
            }

            string manifestSha256 = bound.Envelope.ManifestSha256;
            string selectedTrustedKeyId = bound.Envelope.SelectedTrustedKeyId;
            string sourceInventorySha256 = bound.Context.Source.InventorySha256;

            if (string.IsNullOrEmpty(manifestSha256) || manifestSha256.Length != 64 || !IsLowerHexAscii(manifestSha256))
            {
                throw new ArgumentException("Manifest SHA-256 must be 64 lowercase hex ASCII characters.", "bound");
            }
            if (string.IsNullOrEmpty(sourceInventorySha256) || sourceInventorySha256.Length != 64 || !IsLowerHexAscii(sourceInventorySha256))
            {
                throw new ArgumentException("Source inventory SHA-256 must be 64 lowercase hex ASCII characters.", "bound");
            }
            if (string.IsNullOrEmpty(selectedTrustedKeyId) || !IsAsciiString(selectedTrustedKeyId))
            {
                throw new ArgumentException("Selected trusted key ID must be a non-empty ASCII string.", "bound");
            }

            // Extract source_inventory object from envelope root
            BrokerJsonNode sourceInvNode;
            if (!bound.Envelope.Root.TryGetProperty("source_inventory", out sourceInvNode) || sourceInvNode.Kind != BrokerJsonKind.Object)
            {
                throw new FormatException("Envelope Root is missing required object property 'source_inventory'.");
            }
            BrokerJsonObject sourceInvObj = (BrokerJsonObject)sourceInvNode;

            // Extract files object from source_inventory
            BrokerJsonNode filesNode;
            if (!sourceInvObj.TryGetProperty("files", out filesNode) || filesNode.Kind != BrokerJsonKind.Object)
            {
                throw new FormatException("source_inventory is missing required object property 'files'.");
            }
            BrokerJsonObject filesObj = (BrokerJsonObject)filesNode;

            if (filesObj.Count == 0)
            {
                throw new FormatException("Source inventory files map is empty.");
            }
            if (filesObj.Count > MaxSourceInventoryFiles)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Source inventory files count {0} exceeds limit of {1}.", filesObj.Count, MaxSourceInventoryFiles));
            }

            // Classify paths into selected candidates and non-selected paths
            List<KeyValuePair<string, BrokerJsonNode>> selectedCandidates = new List<KeyValuePair<string, BrokerJsonNode>>();
            List<string> nonSelectedPaths = new List<string>();

            foreach (KeyValuePair<string, BrokerJsonNode> kvp in filesObj.Properties)
            {
                string rawPath = kvp.Key;
                BrokerJsonNode entryNode = kvp.Value;

                if (string.IsNullOrEmpty(rawPath))
                {
                    throw new FormatException("Path in source inventory files must not be null or empty.");
                }

                // Check case-insensitive collisions with PHP prefix and fixed files
                bool isPhpPrefixMatch = rawPath.StartsWith(PhpPrefix, StringComparison.OrdinalIgnoreCase);
                bool isPharMatch = string.Equals(rawPath, RequiredBackendPhar, StringComparison.OrdinalIgnoreCase);
                bool isKeyMatch = string.Equals(rawPath, RequiredUpdatePublicKey, StringComparison.OrdinalIgnoreCase);
                bool isCacertMatch = string.Equals(rawPath, OptionalCacertPem, StringComparison.OrdinalIgnoreCase);

                if (isPhpPrefixMatch)
                {
                    if (!rawPath.StartsWith(PhpPrefix, StringComparison.Ordinal))
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Case-insensitive alias detected for PHP prefix: '{0}' does not match exact '{1}'.", rawPath, PhpPrefix));
                    }
                    selectedCandidates.Add(kvp);
                    continue;
                }

                if (isPharMatch)
                {
                    if (!string.Equals(rawPath, RequiredBackendPhar, StringComparison.Ordinal))
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Case-insensitive alias detected for backend.phar: '{0}' does not match exact '{1}'.", rawPath, RequiredBackendPhar));
                    }
                    selectedCandidates.Add(kvp);
                    continue;
                }

                if (isKeyMatch)
                {
                    if (!string.Equals(rawPath, RequiredUpdatePublicKey, StringComparison.Ordinal))
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Case-insensitive alias detected for update_public_key.pem: '{0}' does not match exact '{1}'.", rawPath, RequiredUpdatePublicKey));
                    }
                    selectedCandidates.Add(kvp);
                    continue;
                }

                if (isCacertMatch)
                {
                    if (!string.Equals(rawPath, OptionalCacertPem, StringComparison.Ordinal))
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Case-insensitive alias detected for cacert.pem: '{0}' does not match exact '{1}'.", rawPath, OptionalCacertPem));
                    }
                    selectedCandidates.Add(kvp);
                    continue;
                }

                // Non-selected path
                nonSelectedPaths.Add(rawPath);
            }

            if (selectedCandidates.Count == 0)
            {
                throw new FormatException("No worker image files were selected from source inventory.");
            }
            if (selectedCandidates.Count > MaxSelectedFiles)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected worker image files count {0} exceeds limit of {1}.", selectedCandidates.Count, MaxSelectedFiles));
            }

            // Validate each selected entry (path, exact keys, size lexeme, sha256)
            Dictionary<string, WorkerImageEntry> selectedMap = new Dictionary<string, WorkerImageEntry>(StringComparer.Ordinal);
            long totalBytes = 0;

            for (int i = 0; i < selectedCandidates.Count; i++)
            {
                KeyValuePair<string, BrokerJsonNode> kvp = selectedCandidates[i];
                string relPath = kvp.Key;
                BrokerJsonNode entryNode = kvp.Value;

                ValidateSelectedRelativePath(relPath);

                if (entryNode == null || entryNode.Kind != BrokerJsonKind.Object)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected file entry '{0}' is not a JSON object.", relPath));
                }
                BrokerJsonObject entryObj = (BrokerJsonObject)entryNode;

                if (entryObj.Count != 2 || !entryObj.ContainsKey("sha256") || !entryObj.ContainsKey("size"))
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected file entry '{0}' must contain exact keys 'sha256' and 'size'.", relPath));
                }

                BrokerJsonNode shaNode = entryObj["sha256"];
                if (shaNode.Kind != BrokerJsonKind.String)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected file entry '{0}' sha256 must be a string.", relPath));
                }
                string sha256 = shaNode.GetString();
                if (sha256.Length != 64 || !IsLowerHexAscii(sha256))
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected file entry '{0}' sha256 must be 64 lowercase hex ASCII characters.", relPath));
                }

                BrokerJsonNode sizeNode = entryObj["size"];
                if (sizeNode.Kind != BrokerJsonKind.Number)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected file entry '{0}' size must be a number.", relPath));
                }
                BrokerJsonNumber sizeNum = (BrokerJsonNumber)sizeNode;
                string lexeme = sizeNum.Lexeme;

                ValidateIntegerLexeme(lexeme, relPath);
                long size = sizeNum.GetInt64();
                if (size < 0 || size > MaxSafeInteger)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected file entry '{0}' size {1} exceeds safe integer bounds.", relPath, size));
                }
                if (size > MaxSingleFileSize)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected file entry '{0}' size {1} exceeds 250 MiB limit ({2} bytes).", relPath, size, MaxSingleFileSize));
                }

                checked
                {
                    totalBytes += size;
                }

                WorkerImageEntry entry = new WorkerImageEntry(relPath, size, sha256);
                selectedMap.Add(relPath, entry);
            }

            if (totalBytes > MaxTotalPayloadSize)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Total selected payload size {0} bytes exceeds 1 GiB limit ({1} bytes).", totalBytes, MaxTotalPayloadSize));
            }

            // Assert required non-empty payload files
            AssertRequiredPayloadFile(selectedMap, RequiredPhpExe);
            AssertRequiredPayloadFile(selectedMap, RequiredPhpOpensslDll);
            AssertRequiredPayloadFile(selectedMap, RequiredPhpZipDll);
            AssertRequiredPayloadFile(selectedMap, RequiredPhpMbstringDll);
            AssertRequiredPayloadFile(selectedMap, RequiredBackendPhar);
            AssertRequiredPayloadFile(selectedMap, RequiredUpdatePublicKey);

            // Assert required PHP core runtime DLL (at least one of php8.dll or php8ts.dll)
            bool hasPhp8 = selectedMap.ContainsKey(Php8Dll);
            bool hasPhp8ts = selectedMap.ContainsKey(Php8TsDll);
            if (!hasPhp8 && !hasPhp8ts)
            {
                throw new FormatException(string.Format(
                    CultureInfo.InvariantCulture,
                    "Signed source inventory missing required PHP core runtime DLL: must contain at least one of '{0}' or '{1}'.",
                    Php8Dll,
                    Php8TsDll));
            }
            if (hasPhp8 && selectedMap[Php8Dll].Size == 0)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Required PHP core DLL '{0}' must not be empty.", Php8Dll));
            }
            if (hasPhp8ts && selectedMap[Php8TsDll].Size == 0)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Required PHP core DLL '{0}' must not be empty.", Php8TsDll));
            }

            // Windows case & ancestor collision detection: O(N * depth)
            Dictionary<string, string> selectedCaseMap = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            HashSet<string> selectedDirSet = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

            foreach (KeyValuePair<string, WorkerImageEntry> kvp in selectedMap)
            {
                string path = kvp.Key;
                string existing;
                if (selectedCaseMap.TryGetValue(path, out existing))
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Case collision among selected files: '{0}' and '{1}'.", existing, path));
                }
                selectedCaseMap.Add(path, path);

                string[] segs = path.Split('/');
                StringBuilder sb = new StringBuilder();
                for (int s = 0; s < segs.Length - 1; s++)
                {
                    if (s > 0) sb.Append('/');
                    sb.Append(segs[s]);
                    selectedDirSet.Add(sb.ToString());
                }
            }

            // Check collision between selected files and selected directory ancestors
            foreach (string selectedPath in selectedCaseMap.Keys)
            {
                if (selectedDirSet.Contains(selectedPath))
                {
                    throw new FormatException("Selected file path collides with an ancestor directory: " + selectedPath);
                }
            }

            // Check collisions with signed non-selected files
            for (int i = 0; i < nonSelectedPaths.Count; i++)
            {
                string nonSelPath = nonSelectedPaths[i];
                string normNonSel = nonSelPath.Replace('\\', '/');

                if (selectedDirSet.Contains(normNonSel))
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Signed non-selected file '{0}' aliases an ancestor directory of a selected file.", nonSelPath));
                }

                if (selectedCaseMap.ContainsKey(normNonSel))
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Signed non-selected file '{0}' aliases a selected file.", nonSelPath));
                }

                string[] segs = normNonSel.Split('/');
                if (segs.Length > MaxPathDepth)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Non-selected path depth {0} exceeds maximum allowed depth {1}: {2}", segs.Length, MaxPathDepth, nonSelPath));
                }
                StringBuilder sb = new StringBuilder();
                for (int s = 0; s < segs.Length - 1; s++)
                {
                    if (s > 0) sb.Append('/');
                    sb.Append(segs[s]);
                    string ancestor = sb.ToString();
                    if (selectedCaseMap.ContainsKey(ancestor))
                    {
                        throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Ancestor '{0}' of non-selected file '{1}' collides with a selected file.", ancestor, nonSelPath));
                    }
                }
            }

            // Sort entries Ordinal
            List<WorkerImageEntry> entryList = new List<WorkerImageEntry>(selectedMap.Values);
            entryList.Sort(new Comparison<WorkerImageEntry>(delegate(WorkerImageEntry a, WorkerImageEntry b)
            {
                return string.Compare(a.RelativePath, b.RelativePath, StringComparison.Ordinal);
            }));

            return new NativeBrokerWorkerImageMap(
                manifestSha256,
                selectedTrustedKeyId,
                sourceInventorySha256,
                totalBytes,
                entryList.AsReadOnly()
            );
        }

        private static void AssertRequiredPayloadFile(Dictionary<string, WorkerImageEntry> map, string relPath)
        {
            WorkerImageEntry entry;
            if (!map.TryGetValue(relPath, out entry))
            {
                throw new FormatException("Signed source inventory missing required payload file: " + relPath);
            }
            if (entry.Size <= 0)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Required payload file '{0}' must not be empty (size was {1}).", relPath, entry.Size));
            }
        }

        private static void ValidateIntegerLexeme(string lexeme, string contextPath)
        {
            if (string.IsNullOrEmpty(lexeme))
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Size lexeme for '{0}' is null or empty.", contextPath));
            }
            if (lexeme.StartsWith("-") || lexeme.StartsWith("+"))
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Size lexeme for '{0}' has leading sign: '{1}'.", contextPath, lexeme));
            }
            if (lexeme.IndexOf('.') >= 0 || lexeme.IndexOf('e') >= 0 || lexeme.IndexOf('E') >= 0)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Size lexeme for '{0}' contains fraction or exponent: '{1}'.", contextPath, lexeme));
            }
            for (int i = 0; i < lexeme.Length; i++)
            {
                char c = lexeme[i];
                if (c < '0' || c > '9')
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Size lexeme for '{0}' contains non-digit character '{1}': '{2}'.", contextPath, c, lexeme));
                }
            }
            if (lexeme.Length > 1 && lexeme[0] == '0')
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Size lexeme for '{0}' contains leading zero: '{1}'.", contextPath, lexeme));
            }
        }

        private static void ValidateSelectedRelativePath(string relPath)
        {
            if (string.IsNullOrEmpty(relPath))
            {
                throw new FormatException("Selected relative path is null or empty.");
            }
            if (relPath.Length > MaxPathLength)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected relative path length {0} exceeds limit of {1}: {2}", relPath.Length, MaxPathLength, relPath));
            }
            for (int i = 0; i < relPath.Length; i++)
            {
                char c = relPath[i];
                if (c < 0x20 || c > 0x7E)
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected relative path contains non-ASCII or control character at index {0}: {1}", i, relPath));
                }
            }
            if (relPath.IndexOf('\\') >= 0)
            {
                throw new FormatException("Selected relative path must not contain backslashes: " + relPath);
            }
            if (relPath.IndexOf(':') >= 0)
            {
                throw new FormatException("Selected relative path must not contain colons: " + relPath);
            }
            if (relPath.IndexOf('\0') >= 0)
            {
                throw new FormatException("Selected relative path must not contain NUL characters: " + relPath);
            }
            if (relPath.IndexOf('~') >= 0)
            {
                throw new FormatException("Selected relative path must not contain '~' (8.3 alias prevention): " + relPath);
            }
            if (relPath.IndexOfAny(new char[] { '*', '?', '"', '<', '>', '|' }) >= 0)
            {
                throw new FormatException("Selected relative path contains forbidden characters: " + relPath);
            }
            if (relPath.StartsWith("/") || relPath.EndsWith("/"))
            {
                throw new FormatException("Selected relative path must not start or end with a slash: " + relPath);
            }
            if (relPath.Contains("//"))
            {
                throw new FormatException("Selected relative path must not contain consecutive slashes: " + relPath);
            }

            string[] segments = relPath.Split('/');
            if (segments.Length > MaxPathDepth)
            {
                throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected relative path depth {0} exceeds maximum allowed depth {1}: {2}", segments.Length, MaxPathDepth, relPath));
            }

            for (int i = 0; i < segments.Length; i++)
            {
                string seg = segments[i];
                if (string.IsNullOrEmpty(seg))
                {
                    throw new FormatException("Selected relative path contains an empty segment: " + relPath);
                }
                if (seg == "." || seg == "..")
                {
                    throw new FormatException("Selected relative path contains '.' or '..' segment: " + relPath);
                }
                if (seg.EndsWith(".") || seg.EndsWith(" "))
                {
                    throw new FormatException("Selected relative path segment must not end with trailing dot or space: " + relPath);
                }
                if (seg.StartsWith(" "))
                {
                    throw new FormatException("Selected relative path segment must not start with leading space: " + relPath);
                }

                string nameWithoutExt = seg;
                int dotIdx = seg.IndexOf('.');
                if (dotIdx >= 0)
                {
                    nameWithoutExt = seg.Substring(0, dotIdx);
                }

                if (IsReservedDosDeviceName(nameWithoutExt) || IsReservedDosDeviceName(seg))
                {
                    throw new FormatException(string.Format(CultureInfo.InvariantCulture, "Selected relative path segment '{0}' uses a reserved DOS device name: {1}", seg, relPath));
                }
            }
        }

        private static bool IsReservedDosDeviceName(string name)
        {
            if (string.IsNullOrEmpty(name)) return false;
            string upper = name.ToUpperInvariant();
            if (upper == "CON" || upper == "PRN" || upper == "AUX" || upper == "NUL" || upper == "CONIN$" || upper == "CONOUT$")
            {
                return true;
            }
            if (upper.Length == 4)
            {
                if (upper.StartsWith("COM") || upper.StartsWith("LPT"))
                {
                    char c = upper[3];
                    if (c >= '1' && c <= '9') return true;
                    if (c == '\u00B9' || c == '\u00B2' || c == '\u00B3') return true;
                }
            }
            return false;
        }

        private static bool IsLowerHexAscii(string s)
        {
            if (s == null) return false;
            for (int i = 0; i < s.Length; i++)
            {
                char c = s[i];
                if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f')))
                {
                    return false;
                }
            }
            return true;
        }

        private static bool IsAsciiString(string s)
        {
            if (s == null) return false;
            for (int i = 0; i < s.Length; i++)
            {
                char c = s[i];
                if (c < 0x20 || c > 0x7E)
                {
                    return false;
                }
            }
            return true;
        }

        public bool Contains(string relativePath)
        {
            WorkerImageEntry entry;
            return this.TryGetEntry(relativePath, out entry);
        }

        public bool TryGetEntry(string relativePath, out WorkerImageEntry entry)
        {
            if (relativePath == null)
            {
                entry = null;
                return false;
            }
            int low = 0;
            int high = this._entries.Count - 1;
            while (low <= high)
            {
                int mid = (low + high) / 2;
                int cmp = string.Compare(this._entries[mid].RelativePath, relativePath, StringComparison.Ordinal);
                if (cmp == 0)
                {
                    entry = this._entries[mid];
                    return true;
                }
                if (cmp < 0)
                {
                    low = mid + 1;
                }
                else
                {
                    high = mid - 1;
                }
            }
            entry = null;
            return false;
        }

        public WorkerImageEntry this[string relativePath]
        {
            get
            {
                WorkerImageEntry entry;
                if (this.TryGetEntry(relativePath, out entry))
                {
                    return entry;
                }
                throw new KeyNotFoundException("Relative path not found in worker image map: " + relativePath);
            }
        }

        public WorkerImageEntry this[int index]
        {
            get
            {
                return this._entries[index];
            }
        }
    }

    /// <summary>
    /// Acquires and manages a multi-file set of launch-readable native image read leases
    /// and ancestor directory namespace locks strictly through owned Win32 handles.
    /// Caller MUST independently authenticate the map and retain this set lease until the
    /// owned worker process is DEAD and its Job active count is 0 (Root DEAD + Job 0).
    /// </summary>
    public sealed class WorkerImageSetLease : IDisposable
    {
        private readonly object _syncLock;
        private readonly string _payloadRoot;
        private readonly NativeBrokerWorkerImageMap _map;
        private readonly List<WorkerImageReadLease> _leases;
        private bool _isClosed;
        private bool _isValidated;
        private bool _ioInProgress;
        private Exception _lastCleanupFailure;

        private WorkerImageSetLease(string payloadRoot, NativeBrokerWorkerImageMap map)
        {
            this._syncLock = new object();
            this._payloadRoot = payloadRoot;
            this._map = map;
            this._leases = new List<WorkerImageReadLease>(map.Entries.Count);
            this._isClosed = false;
            this._isValidated = false;
            this._ioInProgress = false;
            this._lastCleanupFailure = null;
        }

        public bool IsClosed
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._isClosed;
                }
            }
        }

        public bool CanApply { get { return false; } }
        public bool CanLaunch { get { return false; } }
        public NativeBrokerWorkerImageMap ImageMap { get { return this._map; } }
        public string PayloadRoot { get { return this._payloadRoot; } }

        public int Count
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._leases.Count;
                }
            }
        }

        public Exception LastCleanupFailure
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._lastCleanupFailure;
                }
            }
        }

        public ReadOnlyCollection<WorkerImageReadLease> RetainedLeases
        {
            get
            {
                return this.GetRetainedLeases();
            }
        }

        /// <summary>
        /// Acquires launch-readable image read leases and locks all ancestor directories for all entries
        /// in the provided worker image map under the trusted private payload root.
        /// </summary>
        public static WorkerImageSetLease Acquire(string privatePayloadRoot, NativeBrokerWorkerImageMap map)
        {
            if (map == null)
            {
                throw new ArgumentNullException("map");
            }
            if (map.Entries == null || map.Entries.Count == 0)
            {
                throw new ArgumentException("Worker image map entries must not be null or empty.", "map");
            }

            string canonicalRoot = ValidatePayloadRoot(privatePayloadRoot);

            // Construct owner record BEFORE acquiring any native handles
            WorkerImageSetLease setLease = new WorkerImageSetLease(canonicalRoot, map);

            try
            {
                for (int i = 0; i < map.Entries.Count; i++)
                {
                    WorkerImageEntry entry = map.Entries[i];
                    string fullDosPath = Path.Combine(canonicalRoot, entry.RelativePath.Replace('/', '\\'));
                    string canonicalTarget = Path.GetFullPath(fullDosPath);

                    if (!canonicalTarget.StartsWith(canonicalRoot + "\\", StringComparison.OrdinalIgnoreCase))
                    {
                        throw new InvalidOperationException("Target file path escapes private payload root: " + entry.RelativePath);
                    }

                    try
                    {
                        WorkerImageReadLease lease = WorkerImageReadLease.Acquire(canonicalTarget, entry.Size, entry.Sha256);
                        setLease.RegisterLease(lease);
                    }
                    catch (WorkerImageReadLeaseAcquisitionException acqEx)
                    {
                        if (acqEx.RetainedLease != null)
                        {
                            setLease.RegisterLease(acqEx.RetainedLease);
                        }
                        throw;
                    }
                }

                // After all acquired, perform full SHA-256 byte revalidation across all handles before return
                lock (setLease._syncLock)
                {
                    setLease._ioInProgress = true;
                    try
                    {
                        setLease.RevalidateAllInternal(true);
                        setLease._isValidated = true;
                    }
                    finally
                    {
                        setLease._ioInProgress = false;
                    }
                }
            }
            catch (Exception ex)
            {
                setLease.UnwindAcquisitionFailure(ex);
            }

            return setLease;
        }

        private void RegisterLease(WorkerImageReadLease lease)
        {
            lock (this._syncLock)
            {
                this._leases.Add(lease);
            }
        }

        private void UnwindAcquisitionFailure(Exception primaryEx)
        {
            this._isValidated = false;
            List<WorkerImageReadLease> unclosed = new List<WorkerImageReadLease>();
            List<Exception> cleanupExceptions = new List<Exception>();
            int firstCloseErr = 0;
            if (primaryEx is WorkerImageReadLeaseAcquisitionException)
            {
                firstCloseErr = ((WorkerImageReadLeaseAcquisitionException)primaryEx).CloseError;
            }

            for (int i = this._leases.Count - 1; i >= 0; i--)
            {
                WorkerImageReadLease lease = this._leases[i];
                if (lease != null && !lease.IsClosed)
                {
                    int closeErr = 0;
                    bool closed = false;
                    try
                    {
                        closed = lease.TryClose(out closeErr);
                    }
                    catch (Exception ex)
                    {
                        closeErr = -1;
                        cleanupExceptions.Add(ex);
                    }

                    if (!closed)
                    {
                        unclosed.Add(lease);
                        if (firstCloseErr == 0 && closeErr != 0)
                        {
                            firstCloseErr = closeErr;
                        }
                        if (lease.LastCleanupFailure != null)
                        {
                            cleanupExceptions.Add(lease.LastCleanupFailure);
                        }
                    }
                }
            }

            if (unclosed.Count > 0 || cleanupExceptions.Count > 0)
            {
                if (cleanupExceptions.Count == 0 && firstCloseErr != 0)
                {
                    cleanupExceptions.Add(new Win32Exception(firstCloseErr, string.Format(CultureInfo.InvariantCulture, "CloseHandle failed with native error {0}.", firstCloseErr)));
                }

                this._lastCleanupFailure = new AggregateException("One or more worker image read leases failed to close during unwinding.", cleanupExceptions);

                throw new WorkerImageSetLeaseAcquisitionException(
                    string.Format(
                        CultureInfo.InvariantCulture,
                        "Worker image set lease acquisition failed ({0}) and subsequent handle closure was uncertain ({1} unclosed lease(s), first native error {2}).",
                        primaryEx.Message,
                        unclosed.Count,
                        firstCloseErr),
                    primaryEx,
                    this,
                    unclosed.AsReadOnly(),
                    cleanupExceptions.AsReadOnly(),
                    firstCloseErr
                );
            }

            this._isClosed = true;

            // Preserve primary exception type and stack trace
            ExceptionDispatchInfo.Capture(primaryEx).Throw();
        }

        /// <summary>
        /// Revalidates all acquired leases in the set.
        /// When verifyHashes is true, computes SHA-256 byte digests strictly through owned handles and verifies against expected.
        /// When verifyHashes is false, revalidates handle and namespace identity metadata only.
        /// </summary>
        public void RevalidateAll(bool verifyHashes)
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("WorkerImageSetLease");
                }
                if (this._ioInProgress)
                {
                    throw new InvalidOperationException("Concurrent or reentrant operation detected.");
                }
                if (!this._isValidated)
                {
                    throw new InvalidOperationException("Worker image set lease is not validated or has failed validation; only closure is permitted.");
                }

                this._ioInProgress = true;
                try
                {
                    this.RevalidateAllInternal(verifyHashes);
                }
                catch
                {
                    this._isValidated = false;
                    throw;
                }
                finally
                {
                    this._ioInProgress = false;
                }
            }
        }

        private void RevalidateAllInternal(bool verifyHashes)
        {
            for (int i = 0; i < this._leases.Count; i++)
            {
                WorkerImageReadLease lease = this._leases[i];
                if (lease == null || lease.IsClosed)
                {
                    throw new InvalidOperationException(string.Format(CultureInfo.InvariantCulture, "Worker image read lease at index {0} is null or closed.", i));
                }

                if (verifyHashes)
                {
                    lease.ComputeSha256();
                }
                else
                {
                    lease.Revalidate();
                }
            }
        }

        /// <summary>
        /// Idempotently closes all acquired worker image read leases in REVERSE order.
        /// Returns true if all leases closed successfully or were already closed.
        /// If any close fails, records the failure, preserves unclosed leases, and returns false with the first native error.
        /// </summary>
        public bool TryClose(out int nativeError)
        {
            lock (this._syncLock)
            {
                if (this._ioInProgress)
                {
                    nativeError = 32; // ERROR_SHARING_VIOLATION: fail-closed during active I/O
                    return false;
                }

                // Latch validated state to false BEFORE closing any handles
                this._isValidated = false;

                if (this._isClosed)
                {
                    nativeError = 0;
                    return true;
                }

                int firstError = 0;
                List<Exception> closeExceptions = new List<Exception>();
                List<WorkerImageReadLease> unclosed = new List<WorkerImageReadLease>();

                for (int i = this._leases.Count - 1; i >= 0; i--)
                {
                    WorkerImageReadLease lease = this._leases[i];
                    if (lease != null && !lease.IsClosed)
                    {
                        int leaseErr = 0;
                        bool closed = false;
                        try
                        {
                            closed = lease.TryClose(out leaseErr);
                        }
                        catch (Exception ex)
                        {
                            leaseErr = -1;
                            closeExceptions.Add(ex);
                        }

                        if (!closed)
                        {
                            unclosed.Add(lease);
                            if (firstError == 0 && leaseErr != 0)
                            {
                                firstError = leaseErr;
                            }
                            if (lease.LastCleanupFailure != null)
                            {
                                closeExceptions.Add(lease.LastCleanupFailure);
                            }
                        }
                    }
                }

                if (unclosed.Count > 0 || closeExceptions.Count > 0)
                {
                    nativeError = firstError != 0 ? firstError : -1;
                    if (closeExceptions.Count == 0 && nativeError != 0)
                    {
                        closeExceptions.Add(new Win32Exception(nativeError, string.Format(CultureInfo.InvariantCulture, "CloseHandle failed with native error {0}.", nativeError)));
                    }
                    this._lastCleanupFailure = new AggregateException("One or more worker image read leases failed to close during TryClose.", closeExceptions);
                    return false;
                }

                this._isClosed = true;
                nativeError = 0;
                return true;
            }
        }

        /// <summary>
        /// Disposes the worker image set lease by closing all owned leases.
        /// Throws WorkerImageSetLeaseCleanupException if any lease fails to close.
        /// </summary>
        public void Dispose()
        {
            int nativeError;
            if (!this.TryClose(out nativeError))
            {
                Exception failure = this.LastCleanupFailure;
                List<WorkerImageReadLease> unclosed = new List<WorkerImageReadLease>();
                lock (this._syncLock)
                {
                    for (int i = 0; i < this._leases.Count; i++)
                    {
                        if (this._leases[i] != null && !this._leases[i].IsClosed)
                        {
                            unclosed.Add(this._leases[i]);
                        }
                    }
                }

                throw new WorkerImageSetLeaseCleanupException(
                    string.Format(CultureInfo.InvariantCulture, "Failed to close worker image set lease handles: native error {0}.", nativeError),
                    failure ?? new Win32Exception(nativeError),
                    this,
                    unclosed.AsReadOnly(),
                    nativeError
                );
            }
        }

        public ReadOnlyCollection<WorkerImageReadLease> GetRetainedLeases()
        {
            lock (this._syncLock)
            {
                List<WorkerImageReadLease> unclosed = new List<WorkerImageReadLease>();
                for (int i = 0; i < this._leases.Count; i++)
                {
                    if (this._leases[i] != null && !this._leases[i].IsClosed)
                    {
                        unclosed.Add(this._leases[i]);
                    }
                }
                return unclosed.AsReadOnly();
            }
        }

        public WorkerImageIdentity GetIdentity(int index)
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("WorkerImageSetLease");
                }
                if (index < 0 || index >= this._leases.Count)
                {
                    throw new ArgumentOutOfRangeException("index");
                }
                return this._leases[index].Identity;
            }
        }

        public WorkerImageIdentity GetEntryIdentity(int index)
        {
            return this.GetIdentity(index);
        }

        public WorkerImageIdentity GetIdentity(string relativePath)
        {
            if (relativePath == null)
            {
                throw new ArgumentNullException("relativePath");
            }
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("WorkerImageSetLease");
                }
                for (int i = 0; i < this._map.Entries.Count; i++)
                {
                    if (string.Equals(this._map.Entries[i].RelativePath, relativePath, StringComparison.Ordinal))
                    {
                        return this._leases[i].Identity;
                    }
                }
                throw new KeyNotFoundException("Relative path not found in leased set: " + relativePath);
            }
        }

        public WorkerImageIdentity GetEntryIdentity(string relativePath)
        {
            return this.GetIdentity(relativePath);
        }

        private static string ValidatePayloadRoot(string root)
        {
            if (root == null)
            {
                throw new ArgumentNullException("privatePayloadRoot");
            }
            if (string.IsNullOrWhiteSpace(root))
            {
                throw new ArgumentException("Private payload root must not be empty or whitespace.", "privatePayloadRoot");
            }
            if (root.IndexOf('\0') != -1)
            {
                throw new ArgumentException("Private payload root must not contain NUL characters.", "privatePayloadRoot");
            }
            if (root.Length < 4 || !((root[0] >= 'A' && root[0] <= 'Z') || (root[0] >= 'a' && root[0] <= 'z')) || root[1] != ':' || root[2] != '\\')
            {
                throw new ArgumentException("Private payload root must be a fully qualified DOS path starting with drive letter and backslash (e.g. C:\\): " + root, "privatePayloadRoot");
            }
            if (root.StartsWith(@"\\") || root.StartsWith("//"))
            {
                throw new ArgumentException("Private payload root must not be a UNC or network device path: " + root, "privatePayloadRoot");
            }
            if (root.IndexOf('/') != -1)
            {
                throw new ArgumentException("Private payload root must not contain forward slashes: " + root, "privatePayloadRoot");
            }
            if (root.IndexOf(':', 2) != -1)
            {
                throw new ArgumentException("Private payload root must not contain alternate data streams or extra colons: " + root, "privatePayloadRoot");
            }
            if (root.IndexOfAny(new char[] { '*', '?', '"', '<', '>', '|' }) != -1)
            {
                throw new ArgumentException("Private payload root must not contain wildcard or forbidden characters: " + root, "privatePayloadRoot");
            }
            if (root.Contains(".."))
            {
                throw new ArgumentException("Private payload root must not contain '..' segments: " + root, "privatePayloadRoot");
            }

            string trimmedRoot = (root.Length > 3 && root.EndsWith("\\")) ? root.TrimEnd('\\') : root;
            if (trimmedRoot.Length <= 2)
            {
                throw new ArgumentException("Drive root is not permitted as private payload root: " + root, "privatePayloadRoot");
            }

            string canonical = Path.GetFullPath(trimmedRoot);
            if (!string.Equals(canonical, trimmedRoot, StringComparison.OrdinalIgnoreCase))
            {
                throw new ArgumentException(string.Format(CultureInfo.InvariantCulture, "Private payload root path is not canonical: expected '{0}', got '{1}'.", canonical, trimmedRoot), "privatePayloadRoot");
            }

            string pathRoot = Path.GetPathRoot(canonical);
            if (string.Equals(canonical, pathRoot.TrimEnd('\\'), StringComparison.OrdinalIgnoreCase))
            {
                throw new ArgumentException("Drive root is not permitted as private payload root: " + root, "privatePayloadRoot");
            }

            string[] segments = canonical.Split('\\');
            for (int i = 1; i < segments.Length; i++)
            {
                string seg = segments[i];
                if (string.IsNullOrEmpty(seg))
                {
                    throw new ArgumentException("Private payload root must not contain consecutive backslashes: " + root, "privatePayloadRoot");
                }
                if (seg == "." || seg == "..")
                {
                    throw new ArgumentException("Private payload root must not contain '.' or '..' segments: " + root, "privatePayloadRoot");
                }
                if (seg.EndsWith(".") || seg.EndsWith(" "))
                {
                    throw new ArgumentException("Private payload root components must not end with trailing dots or spaces: " + root, "privatePayloadRoot");
                }
            }

            if (File.Exists(canonical))
            {
                throw new ArgumentException("Private payload root is a file, not a directory: " + canonical, "privatePayloadRoot");
            }
            if (!Directory.Exists(canonical))
            {
                throw new DirectoryNotFoundException("Private payload root directory does not exist: " + canonical);
            }

            DirectoryInfo curr = new DirectoryInfo(canonical);
            while (curr != null)
            {
                if ((curr.Attributes & FileAttributes.ReparsePoint) != 0)
                {
                    throw new InvalidOperationException("Private payload root or ancestor has a reparse point: " + curr.FullName);
                }
                curr = curr.Parent;
            }

            return canonical;
        }
    }
}
