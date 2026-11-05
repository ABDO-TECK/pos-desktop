// backend/Native/WorkerImageReadLease.cs
//
// INTERNAL Win32 launch-readable native worker image read lease and namespace pinning primitive.
//
// Architecture & Trust Notice:
// Caller MUST independently authenticate expected image size and SHA-256 digest from a trusted,
// SIGNED snapshot or inventory BEFORE calling Acquire. The expected hash passed to this primitive
// is an integrity expectation, NOT itself an RSA/code signature or authenticity receipt.
//
// Caller MUST retain ALL image, DLL, and PHAR leases until the owned child worker process is
// confirmed DEAD and its Job Object active count is known and 0 (Root DEAD + Job 0).
// Process ID (PID) introspection alone is NOT code authentication.
//
// Pinning selected file paths and ancestor directories does NOT prove complete DLL/module closure;
// compiled host and PHAR trust bootstrap integration remains future scope.
// This component provides file and directory namespace protection ONLY, denying writers,
// deleters, and path replacements while permitting concurrent loader reads (FILE_SHARE_READ).
//
// This internal primitive does NOT verify RSA signatures, manage coordinator journals, or provide
// absolute user-space TOCTOU / writable memory-view exclusion / kernel corruption / power loss guarantees.
// Actor own-process memory tampering and administrator actions are excluded as before.
// All CanApply is false; this primitive provides NO mutation or apply authority.

namespace PosDesktop.Updates.Native
{
    using System;
    using System.Collections.Generic;
    using System.ComponentModel;
    using System.IO;
    using System.Runtime.InteropServices;
    using System.Security.Cryptography;
    using System.Text;

    /// <summary>
    /// Captures immutable identity metadata for a leased worker image file.
    /// </summary>
    public sealed class WorkerImageIdentity
    {
        private readonly uint _volumeSerialNumber;
        private readonly ulong _fileIndex;
        private readonly long _size;
        private readonly string _canonicalPath;

        public WorkerImageIdentity(uint volumeSerialNumber, ulong fileIndex, long size, string canonicalPath)
        {
            this._volumeSerialNumber = volumeSerialNumber;
            this._fileIndex = fileIndex;
            this._size = size;
            this._canonicalPath = canonicalPath;
        }

        public uint VolumeSerialNumber { get { return this._volumeSerialNumber; } }
        public ulong FileIndex { get { return this._fileIndex; } }
        public long Size { get { return this._size; } }
        public string CanonicalPath { get { return this._canonicalPath; } }
    }

    /// <summary>
    /// Exception thrown when validation fails during lease acquisition and subsequent handle closure also fails,
    /// ensuring the retained unclosed lease is never lost or orphaned.
    /// </summary>
    public sealed class WorkerImageReadLeaseAcquisitionException : Exception
    {
        private readonly WorkerImageReadLease _retainedLease;
        private readonly int _closeError;

        public WorkerImageReadLeaseAcquisitionException(string message, Exception innerException, WorkerImageReadLease retainedLease, int closeError)
            : base(message, innerException)
        {
            this._retainedLease = retainedLease;
            this._closeError = closeError;
        }

        public WorkerImageReadLease RetainedLease { get { return this._retainedLease; } }
        public int CloseError { get { return this._closeError; } }
    }

    /// <summary>
    /// Exception thrown when handle cleanup or disposal fails, preserving the retained unclosed lease
    /// and diagnostic error context for caller retention.
    /// </summary>
    public sealed class WorkerImageReadLeaseCleanupException : Exception
    {
        private readonly WorkerImageReadLease _retainedLease;
        private readonly int _nativeError;

        public WorkerImageReadLeaseCleanupException(string message, Exception innerException, WorkerImageReadLease retainedLease, int nativeError)
            : base(message, innerException)
        {
            this._retainedLease = retainedLease;
            this._nativeError = nativeError;
        }

        public WorkerImageReadLease RetainedLease { get { return this._retainedLease; } }
        public int NativeError { get { return this._nativeError; } }
    }

    /// <summary>
    /// Provides launch-readable native image read lease semantics and ancestor directory namespace locking
    /// strictly through owned Win32 handles.
    /// Permits loader reads (FILE_SHARE_READ) while denying all writers, deleters, and path replacements.
    /// </summary>
    public sealed class WorkerImageReadLease : IDisposable
    {
        private sealed class DirectoryHandleRecord
        {
            public readonly string ExpectedCanonicalPath;
            public IntPtr Handle;
            public uint VolumeSerialNumber;
            public ulong FileIndex;

            public DirectoryHandleRecord(string expectedCanonicalPath)
            {
                this.ExpectedCanonicalPath = expectedCanonicalPath;
                this.Handle = IntPtr.Zero;
                this.VolumeSerialNumber = 0;
                this.FileIndex = 0;
            }
        }

        private readonly object _syncLock;
        private readonly string _expectedSha256;
        private readonly long _expectedSize;
        private IntPtr _fileHandle;
        private readonly List<DirectoryHandleRecord> _directoryRecords;
        private WorkerImageIdentity _identity;
        private bool _isClosed;
        private bool _ioInProgress;
        private bool _acquisitionValidated;
        private Exception _lastCleanupFailure;

        private WorkerImageReadLease(long expectedSize, string expectedSha256)
        {
            this._syncLock = new object();
            this._expectedSize = expectedSize;
            this._expectedSha256 = expectedSha256;
            this._fileHandle = IntPtr.Zero;
            this._directoryRecords = new List<DirectoryHandleRecord>();
            this._identity = null;
            this._isClosed = false;
            this._ioInProgress = false;
            this._acquisitionValidated = false;
            this._lastCleanupFailure = null;
        }

        public WorkerImageIdentity Identity
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._identity;
                }
            }
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

        /// <summary>
        /// Gets whether this lease provides mutation or apply capability.
        /// Always false; WorkerImageReadLease is strictly read-only and namespace-locking.
        /// </summary>
        public bool CanApply
        {
            get
            {
                return false;
            }
        }

        /// <summary>
        /// Gets an immutable snapshot of the last cleanup failure, if any occurred during handle closure.
        /// </summary>
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

        private static void ValidateCommonMetadata(string absolutePath, long expectedSize, string expectedSha256)
        {
            // Fail closed if not running on Win32NT 64-bit
            if (Environment.OSVersion.Platform != PlatformID.Win32NT || IntPtr.Size != 8)
            {
                throw new PlatformNotSupportedException("WorkerImageReadLease requires 64-bit Windows (Win32NT x64).");
            }

            // Expected size validation: 0 .. 9007199254740991 (Number.MAX_SAFE_INTEGER)
            if (expectedSize < 0 || expectedSize > 9007199254740991L)
            {
                throw new ArgumentOutOfRangeException("expectedSize", "Expected size must be between 0 and 9,007,199,254,740,991 bytes.");
            }

            // Expected SHA-256 validation: strict 64 lowercase hex characters
            if (expectedSha256 == null)
            {
                throw new ArgumentNullException("expectedSha256");
            }
            if (expectedSha256.Length != 64)
            {
                throw new ArgumentException("Expected SHA-256 must be exactly 64 lowercase hex characters.", "expectedSha256");
            }
            for (int i = 0; i < 64; i++)
            {
                char c = expectedSha256[i];
                if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f')))
                {
                    throw new ArgumentException("Expected SHA-256 must contain only lowercase hex characters [0-9a-f].", "expectedSha256");
                }
            }

            // Path syntax, canonicalization, and existence validation
            ValidateDosPath(absolutePath);
        }

        private static void ValidateDosPath(string path)
        {
            if (path == null)
            {
                throw new ArgumentNullException("absolutePath");
            }
            if (string.IsNullOrWhiteSpace(path))
            {
                throw new ArgumentException("Path must not be null, empty, or whitespace.", "absolutePath");
            }
            if (path.IndexOf('\0') != -1)
            {
                throw new ArgumentException("Path must not contain NUL characters.", "absolutePath");
            }
            if (path.Length < 4 || !((path[0] >= 'A' && path[0] <= 'Z') || (path[0] >= 'a' && path[0] <= 'z')) || path[1] != ':' || path[2] != '\\')
            {
                throw new ArgumentException("Path must be a fully qualified DOS path starting with drive letter and backslash (e.g. C:\\): " + path, "absolutePath");
            }
            if (path.StartsWith(@"\\") || path.StartsWith("//"))
            {
                throw new ArgumentException("Path must not be a UNC or network device path: " + path, "absolutePath");
            }
            if (path.IndexOf('/') != -1)
            {
                throw new ArgumentException("Path must not contain forward slashes: " + path, "absolutePath");
            }
            if (path.IndexOf(':', 2) != -1)
            {
                throw new ArgumentException("Path must not contain alternate data streams or extra colons: " + path, "absolutePath");
            }
            if (path.IndexOfAny(new char[] { '*', '?', '"', '<', '>', '|' }) != -1)
            {
                throw new ArgumentException("Path must not contain wildcard or forbidden characters: " + path, "absolutePath");
            }
            if (path.Length == 3 || path.EndsWith("\\"))
            {
                throw new ArgumentException("Drive root is not a valid file target, and path must not end with a trailing backslash: " + path, "absolutePath");
            }
            if (path.Contains(".."))
            {
                throw new ArgumentException("Path must not contain '..' segments: " + path, "absolutePath");
            }

            string[] segments = path.Split('\\');
            for (int i = 1; i < segments.Length; i++)
            {
                string seg = segments[i];
                if (string.IsNullOrEmpty(seg))
                {
                    throw new ArgumentException("Path must not contain consecutive backslashes: " + path, "absolutePath");
                }
                if (seg == "." || seg == "..")
                {
                    throw new ArgumentException("Path must not contain '.' or '..' segments: " + path, "absolutePath");
                }
                if (seg.EndsWith(".") || seg.EndsWith(" "))
                {
                    throw new ArgumentException("Path components must not end with trailing dots or spaces: " + path, "absolutePath");
                }

                string nameWithoutExt = seg;
                int dotIdx = seg.IndexOf('.');
                if (dotIdx >= 0)
                {
                    nameWithoutExt = seg.Substring(0, dotIdx);
                }
                if (IsReservedDosDeviceName(nameWithoutExt))
                {
                    throw new ArgumentException(string.Format("Path component '{0}' uses a reserved DOS device name: {1}", seg, path), "absolutePath");
                }
            }

            string expectedCanonical = Path.GetFullPath(path);
            if (!string.Equals(expectedCanonical, path, StringComparison.OrdinalIgnoreCase))
            {
                throw new ArgumentException(string.Format("Path is not canonical: expected '{0}', got '{1}'.", expectedCanonical, path), "absolutePath");
            }

            if (Directory.Exists(path))
            {
                throw new ArgumentException("Target path is a directory, not a regular file: " + path, "absolutePath");
            }

            if (!File.Exists(path))
            {
                throw new FileNotFoundException("Target file does not exist: " + path, path);
            }
        }

        private static bool IsReservedDosDeviceName(string name)
        {
            if (string.IsNullOrEmpty(name)) return false;
            string upper = name.ToUpperInvariant();
            if (upper == "CON" || upper == "PRN" || upper == "AUX" || upper == "NUL" || upper == "CONIN$" || upper == "CONOUT$") return true;
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

        /// <summary>
        /// Acquires a launch-readable image read lease on the target file and locks the full ancestor directory chain.
        /// Ancestor directories are opened with FILE_LIST_DIRECTORY | FILE_READ_ATTRIBUTES (0x0081) and FILE_SHARE_READ (denying WRITE and DELETE).
        /// Per Win32 CreateFileW specifications, FILE_READ_ATTRIBUTES alone is not affected by share flags;
        /// requesting FILE_LIST_DIRECTORY establishes true directory read access and enforces FILE_SHARE_READ sharing restrictions,
        /// preventing directory renames, file deletions, and conflicting writes. No directory enumeration is actually performed.
        /// Target file is opened with GENERIC_READ and FILE_SHARE_READ (denying WRITE and DELETE).
        /// Validates disk type, non-directory, non-reparse, single hardlink, delete-pending 0, canonical path, size, and SHA-256 digest.
        /// </summary>
        public static WorkerImageReadLease Acquire(string absolutePath, long expectedSize, string expectedSha256)
        {
            ValidateCommonMetadata(absolutePath, expectedSize, expectedSha256);

            string expectedFileCanonical = Path.GetFullPath(absolutePath);

            // Collect ancestor directories from drive root down to parent
            string parentDir = Path.GetDirectoryName(expectedFileCanonical);
            if (string.IsNullOrEmpty(parentDir))
            {
                throw new ArgumentException("Unable to determine parent directory for path: " + absolutePath, "absolutePath");
            }

            List<string> ancestorPaths = new List<string>();
            string curr = parentDir;
            while (!string.IsNullOrEmpty(curr))
            {
                ancestorPaths.Add(curr);
                string next = Path.GetDirectoryName(curr);
                if (string.IsNullOrEmpty(next) || string.Equals(next, curr, StringComparison.OrdinalIgnoreCase))
                {
                    break;
                }
                curr = next;
            }
            ancestorPaths.Reverse();

            // Construct lease owner object BEFORE any native handles
            WorkerImageReadLease lease = new WorkerImageReadLease(expectedSize, expectedSha256);

            try
            {
                uint rootVolumeSerialNumber = 0;
                bool hasRootVolume = false;

                // Acquire ancestor directory handles from DOS DRIVE ROOT down to parent
                for (int i = 0; i < ancestorPaths.Count; i++)
                {
                    string dirPath = ancestorPaths[i];
                    bool isDriveRoot = (dirPath.Length == 3 && dirPath[1] == ':' && dirPath[2] == '\\');
                    string expectedCanonicalDir = isDriveRoot ? dirPath : dirPath.TrimEnd('\\');
                    string dirOpenPath = isDriveRoot ? dirPath : dirPath.TrimEnd('\\');

                    // Allocate and register record under owner BEFORE CreateFileW
                    DirectoryHandleRecord rec = new DirectoryHandleRecord(expectedCanonicalDir);
                    lease._directoryRecords.Add(rec);

                    IntPtr hDir = NativeMethods.CreateFileW(
                        dirOpenPath,
                        NativeMethods.FILE_LIST_DIRECTORY | NativeMethods.FILE_READ_ATTRIBUTES,
                        NativeMethods.FILE_SHARE_READ,
                        IntPtr.Zero,
                        NativeMethods.OPEN_EXISTING,
                        NativeMethods.FILE_FLAG_BACKUP_SEMANTICS | NativeMethods.FILE_FLAG_OPEN_REPARSE_POINT,
                        IntPtr.Zero
                    );

                    if (hDir == NativeMethods.INVALID_HANDLE_VALUE || hDir == IntPtr.Zero)
                    {
                        int err = Marshal.GetLastWin32Error();
                        throw new IOException(string.Format("Failed to open ancestor directory '{0}' with share READ: Win32 error {1}.", dirOpenPath, err), new Win32Exception(err));
                    }

                    // Assign returned handle immediately to owner record
                    rec.Handle = hDir;

                    // Post-open checks on ancestor directory handle
                    uint fileType = NativeMethods.GetFileType(hDir);
                    if (fileType != NativeMethods.FILE_TYPE_DISK)
                    {
                        throw new InvalidOperationException(string.Format("Ancestor directory handle for '{0}' is not a disk object: GetFileType returned {1}.", dirOpenPath, fileType));
                    }

                    NativeMethods.BY_HANDLE_FILE_INFORMATION fi;
                    if (!NativeMethods.GetFileInformationByHandle(hDir, out fi))
                    {
                        int err = Marshal.GetLastWin32Error();
                        throw new IOException(string.Format("Failed to query ancestor directory information for '{0}': Win32 error {1}.", dirOpenPath, err), new Win32Exception(err));
                    }

                    if ((fi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_DIRECTORY) == 0)
                    {
                        throw new InvalidOperationException(string.Format("Ancestor path '{0}' is not a directory.", dirOpenPath));
                    }

                    if ((fi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_REPARSE_POINT) != 0)
                    {
                        throw new InvalidOperationException(string.Format("Ancestor directory '{0}' is a reparse point.", dirOpenPath));
                    }

                    if (!hasRootVolume)
                    {
                        rootVolumeSerialNumber = fi.dwVolumeSerialNumber;
                        hasRootVolume = true;
                    }
                    else
                    {
                        if (fi.dwVolumeSerialNumber != rootVolumeSerialNumber)
                        {
                            throw new InvalidOperationException(string.Format("Ancestor directory '{0}' volume serial number {1} does not match root volume serial number {2}.", dirOpenPath, fi.dwVolumeSerialNumber, rootVolumeSerialNumber));
                        }
                    }

                    rec.VolumeSerialNumber = fi.dwVolumeSerialNumber;
                    rec.FileIndex = ((ulong)fi.nFileIndexHigh << 32) | (ulong)fi.nFileIndexLow;

                    NativeMethods.FILE_STANDARD_INFO stdInfo;
                    if (!NativeMethods.GetFileInformationByHandleEx(hDir, NativeMethods.FileStandardInfo, out stdInfo, (uint)Marshal.SizeOf(typeof(NativeMethods.FILE_STANDARD_INFO))))
                    {
                        int err = Marshal.GetLastWin32Error();
                        throw new IOException(string.Format("GetFileInformationByHandleEx(FileStandardInfo) failed on ancestor directory '{0}': Win32 error {1}.", dirOpenPath, err), new Win32Exception(err));
                    }

                    if (stdInfo.Directory == 0)
                    {
                        throw new InvalidOperationException(string.Format("Ancestor path '{0}' standard info reports non-directory.", dirOpenPath));
                    }

                    if (stdInfo.DeletePending != 0)
                    {
                        throw new InvalidOperationException(string.Format("Ancestor directory '{0}' has deletion pending.", dirOpenPath));
                    }

                    string actualCanonicalDir = GetCanonicalDosPath(hDir);
                    string normActual = isDriveRoot ? (actualCanonicalDir.TrimEnd('\\') + "\\") : actualCanonicalDir.TrimEnd('\\');
                    string normExpected = isDriveRoot ? (expectedCanonicalDir.TrimEnd('\\') + "\\") : expectedCanonicalDir.TrimEnd('\\');
                    if (!string.Equals(normActual, normExpected, StringComparison.OrdinalIgnoreCase))
                    {
                        throw new InvalidOperationException(string.Format("Ancestor directory canonical path mismatch: expected '{0}', got '{1}'.", normExpected, normActual));
                    }
                }

                // Open target file handle: GENERIC_READ, FILE_SHARE_READ, OPEN_EXISTING, FILE_FLAG_OPEN_REPARSE_POINT
                IntPtr hFile = NativeMethods.CreateFileW(
                    expectedFileCanonical,
                    NativeMethods.GENERIC_READ,
                    NativeMethods.FILE_SHARE_READ,
                    IntPtr.Zero,
                    NativeMethods.OPEN_EXISTING,
                    NativeMethods.FILE_FLAG_OPEN_REPARSE_POINT,
                    IntPtr.Zero
                );

                if (hFile == NativeMethods.INVALID_HANDLE_VALUE || hFile == IntPtr.Zero)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("Failed to open target worker image file '{0}' with read lease: Win32 error {1}.", expectedFileCanonical, err), new Win32Exception(err));
                }

                // Assign returned handle immediately to owner
                lease._fileHandle = hFile;

                // Post-open checks on target file handle
                uint targetFileType = NativeMethods.GetFileType(hFile);
                if (targetFileType != NativeMethods.FILE_TYPE_DISK)
                {
                    throw new InvalidOperationException(string.Format("Target file handle is not a disk object: GetFileType returned {0}.", targetFileType));
                }

                NativeMethods.BY_HANDLE_FILE_INFORMATION targetFi;
                if (!NativeMethods.GetFileInformationByHandle(hFile, out targetFi))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("Failed to query target file information by handle: Win32 error {0}.", err), new Win32Exception(err));
                }

                if ((targetFi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_DIRECTORY) != 0)
                {
                    throw new InvalidOperationException("Target file is a directory, not a regular file.");
                }

                if ((targetFi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_REPARSE_POINT) != 0)
                {
                    throw new InvalidOperationException("Target file is a reparse point.");
                }

                if (targetFi.nNumberOfLinks != 1)
                {
                    throw new InvalidOperationException(string.Format("Target file link count must be exactly 1, got {0}.", targetFi.nNumberOfLinks));
                }

                if (hasRootVolume && targetFi.dwVolumeSerialNumber != rootVolumeSerialNumber)
                {
                    throw new InvalidOperationException(string.Format("Target file volume serial number {0} does not match ancestor volume serial number {1}.", targetFi.dwVolumeSerialNumber, rootVolumeSerialNumber));
                }

                ulong actualSize = ((ulong)targetFi.nFileSizeHigh << 32) | (ulong)targetFi.nFileSizeLow;
                if (actualSize != (ulong)expectedSize)
                {
                    throw new InvalidOperationException(string.Format("Target file size mismatch: expected {0} bytes, actual {1} bytes.", expectedSize, actualSize));
                }

                NativeMethods.FILE_STANDARD_INFO targetStdInfo;
                if (!NativeMethods.GetFileInformationByHandleEx(hFile, NativeMethods.FileStandardInfo, out targetStdInfo, (uint)Marshal.SizeOf(typeof(NativeMethods.FILE_STANDARD_INFO))))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("GetFileInformationByHandleEx(FileStandardInfo) failed on target file: Win32 error {0}.", err), new Win32Exception(err));
                }

                if (targetStdInfo.Directory != 0)
                {
                    throw new InvalidOperationException("Target file standard info reports directory, not a regular file.");
                }

                if (targetStdInfo.DeletePending != 0)
                {
                    throw new InvalidOperationException("Target file has deletion pending.");
                }

                if (targetStdInfo.NumberOfLinks != 1)
                {
                    throw new InvalidOperationException(string.Format("Target file standard info link count must be exactly 1, got {0}.", targetStdInfo.NumberOfLinks));
                }

                if (targetStdInfo.EndOfFile != expectedSize)
                {
                    throw new InvalidOperationException(string.Format("Target file EndOfFile mismatch: expected {0} bytes, actual {1} bytes.", expectedSize, targetStdInfo.EndOfFile));
                }

                string actualCanonicalFile = GetCanonicalDosPath(hFile);
                if (!string.Equals(actualCanonicalFile, expectedFileCanonical, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException(string.Format("Canonical path mismatch: expected '{0}', got '{1}'.", expectedFileCanonical, actualCanonicalFile));
                }

                ulong targetFileIndex = ((ulong)targetFi.nFileIndexHigh << 32) | (ulong)targetFi.nFileIndexLow;
                lease._identity = new WorkerImageIdentity(
                    targetFi.dwVolumeSerialNumber,
                    targetFileIndex,
                    expectedSize,
                    actualCanonicalFile
                );

                // Compute and verify SHA-256 strictly on THIS handle
                string actualSha256 = lease.ComputeSha256Internal();
                if (!string.Equals(actualSha256, expectedSha256, StringComparison.Ordinal))
                {
                    throw new InvalidOperationException(string.Format("SHA-256 digest mismatch: expected '{0}', computed '{1}'.", expectedSha256, actualSha256));
                }

                // Post-hash revalidation of target file and all ancestors
                lease.RevalidateInternal();

                lease._acquisitionValidated = true;
                return lease;
            }
            catch (Exception ex)
            {
                int closeErr;
                if (!lease.TryClose(out closeErr))
                {
                    throw new WorkerImageReadLeaseAcquisitionException(
                        string.Format("Worker image read lease acquisition validation failed ({0}) and subsequent handle closure also failed with Win32 error {1}.", ex.Message, closeErr),
                        ex,
                        lease,
                        closeErr
                    );
                }
                throw;
            }
        }

        /// <summary>
        /// Reads an exact byte range through the owned native file handle in bounded 64 KiB chunks.
        /// Maximum allowed count is 1 MiB (1,048,576 bytes).
        /// Performs full handle and namespace state revalidation before and after reading.
        /// Count of 0 executes pre- and post-revalidation and returns an empty array.
        /// </summary>
        public byte[] ReadRange(long offset, int count)
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("WorkerImageReadLease");
                }
                if (this._ioInProgress)
                {
                    throw new InvalidOperationException("Reentrant or concurrent I/O detected.");
                }
                if (!this._acquisitionValidated)
                {
                    throw new InvalidOperationException("Worker image read lease is not validated or has failed validation; only closure is permitted.");
                }

                if (offset < 0)
                {
                    throw new ArgumentOutOfRangeException("offset", "Offset must be non-negative.");
                }
                if (count < 0 || count > 1048576)
                {
                    throw new ArgumentOutOfRangeException("count", "Count must be between 0 and 1,048,576 bytes (1 MiB).");
                }
                if (offset > this._identity.Size - count)
                {
                    throw new ArgumentOutOfRangeException("count", "Requested range extends beyond file size.");
                }

                this._ioInProgress = true;
                try
                {
                    this.RevalidateInternal();

                    if (count == 0)
                    {
                        this.RevalidateInternal();
                        return new byte[0];
                    }

                    long newPos;
                    if (!NativeMethods.SetFilePointerEx(this._fileHandle, offset, out newPos, NativeMethods.FILE_BEGIN))
                    {
                        int err = Marshal.GetLastWin32Error();
                        throw new IOException(string.Format("SetFilePointerEx failed: Win32 error {0}.", err), new Win32Exception(err));
                    }

                    byte[] result = new byte[count];
                    const int chunkLimit = 65536; // 64 KiB bounded chunks
                    byte[] scratch = new byte[chunkLimit];
                    int totalRead = 0;
                    while (totalRead < count)
                    {
                        int toRead = Math.Min(count - totalRead, chunkLimit);
                        uint bytesRead;
                        if (!NativeMethods.ReadFile(this._fileHandle, scratch, (uint)toRead, out bytesRead, IntPtr.Zero))
                        {
                            int err = Marshal.GetLastWin32Error();
                            throw new IOException(string.Format("ReadFile failed: Win32 error {0}.", err), new Win32Exception(err));
                        }
                        if (bytesRead == 0)
                        {
                            throw new EndOfStreamException(string.Format("Unexpected EOF after reading {0} of {1} requested bytes.", totalRead, count));
                        }
                        if (bytesRead > (uint)toRead)
                        {
                            throw new InvalidOperationException(string.Format("ReadFile returned more bytes ({0}) than requested ({1}).", bytesRead, toRead));
                        }

                        Buffer.BlockCopy(scratch, 0, result, totalRead, (int)bytesRead);
                        totalRead += (int)bytesRead;
                    }

                    this.RevalidateInternal();
                    return result;
                }
                catch
                {
                    this._acquisitionValidated = false;
                    throw;
                }
                finally
                {
                    this._ioInProgress = false;
                }
            }
        }

        /// <summary>
        /// Computes the SHA-256 digest by reading bounded 64 KiB chunks strictly through the owned handle
        /// and verifies that the computed digest matches the expected metadata digest.
        /// Revalidates directory and file handles before and after hash computation.
        /// Throws InvalidOperationException if the computed hash does not exactly match the expected hash,
        /// and latches the lease as close-only.
        /// </summary>
        public string ComputeSha256()
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("WorkerImageReadLease");
                }
                if (this._ioInProgress)
                {
                    throw new InvalidOperationException("Reentrant or concurrent I/O detected.");
                }
                if (!this._acquisitionValidated)
                {
                    throw new InvalidOperationException("Worker image read lease is not validated or has failed validation; only closure is permitted.");
                }

                this._ioInProgress = true;
                try
                {
                    this.RevalidateInternal();
                    string computed = this.ComputeSha256Internal();
                    if (!string.Equals(computed, this._expectedSha256, StringComparison.Ordinal))
                    {
                        throw new InvalidOperationException(string.Format("SHA-256 digest mismatch: expected '{0}', computed '{1}'.", this._expectedSha256, computed));
                    }
                    this.RevalidateInternal();
                    return computed;
                }
                catch
                {
                    this._acquisitionValidated = false;
                    throw;
                }
                finally
                {
                    this._ioInProgress = false;
                }
            }
        }

        /// <summary>
        /// Revalidates all ancestor directory handles and the target file handle.
        /// Proves disk type, non-directory/directory, non-reparse, single hardlink on file,
        /// volume serial number, file index, delete-pending 0, size, and canonical path stability.
        /// NOTE: Revalidate verifies handle, path, and namespace identity metadata ONLY; it does NOT
        /// perform a full SHA-256 byte re-hash of the file content. Callers requiring full byte re-verification
        /// before process launch must explicitly call ComputeSha256(). Revalidation does not imply a false byte
        /// authenticity receipt.
        /// If any validation check fails, marks the lease close-only to prevent further operations.
        /// </summary>
        public void Revalidate()
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("WorkerImageReadLease");
                }
                if (this._ioInProgress)
                {
                    throw new InvalidOperationException("Reentrant or concurrent I/O detected.");
                }
                if (!this._acquisitionValidated)
                {
                    throw new InvalidOperationException("Worker image read lease is not validated or has failed validation; only closure is permitted.");
                }

                try
                {
                    this.RevalidateInternal();
                }
                catch
                {
                    this._acquisitionValidated = false;
                    throw;
                }
            }
        }

        private void RevalidateInternal()
        {
            // 1. Revalidate all ancestor directory handles
            for (int i = 0; i < this._directoryRecords.Count; i++)
            {
                DirectoryHandleRecord rec = this._directoryRecords[i];
                if (rec == null || rec.Handle == IntPtr.Zero || rec.Handle == NativeMethods.INVALID_HANDLE_VALUE)
                {
                    throw new InvalidOperationException("Ancestor directory handle is missing or uninitialized.");
                }

                uint fileType = NativeMethods.GetFileType(rec.Handle);
                if (fileType != NativeMethods.FILE_TYPE_DISK)
                {
                    throw new InvalidOperationException(string.Format("Ancestor directory handle for '{0}' is not a disk object: GetFileType returned {1}.", rec.ExpectedCanonicalPath, fileType));
                }

                NativeMethods.BY_HANDLE_FILE_INFORMATION fi;
                if (!NativeMethods.GetFileInformationByHandle(rec.Handle, out fi))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("Failed to query ancestor directory information for '{0}': Win32 error {1}.", rec.ExpectedCanonicalPath, err), new Win32Exception(err));
                }

                if ((fi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_DIRECTORY) == 0)
                {
                    throw new InvalidOperationException(string.Format("Ancestor path '{0}' is no longer a directory.", rec.ExpectedCanonicalPath));
                }

                if ((fi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_REPARSE_POINT) != 0)
                {
                    throw new InvalidOperationException(string.Format("Ancestor directory '{0}' became a reparse point.", rec.ExpectedCanonicalPath));
                }

                if (fi.dwVolumeSerialNumber != rec.VolumeSerialNumber)
                {
                    throw new InvalidOperationException(string.Format("Ancestor directory '{0}' volume serial number changed from {1} to {2}.", rec.ExpectedCanonicalPath, rec.VolumeSerialNumber, fi.dwVolumeSerialNumber));
                }

                ulong fileIndex = ((ulong)fi.nFileIndexHigh << 32) | (ulong)fi.nFileIndexLow;
                if (fileIndex != rec.FileIndex)
                {
                    throw new InvalidOperationException(string.Format("Ancestor directory '{0}' file index changed from {1} to {2}.", rec.ExpectedCanonicalPath, rec.FileIndex, fileIndex));
                }

                NativeMethods.FILE_STANDARD_INFO stdInfo;
                if (!NativeMethods.GetFileInformationByHandleEx(rec.Handle, NativeMethods.FileStandardInfo, out stdInfo, (uint)Marshal.SizeOf(typeof(NativeMethods.FILE_STANDARD_INFO))))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("GetFileInformationByHandleEx(FileStandardInfo) failed on ancestor directory '{0}': Win32 error {1}.", rec.ExpectedCanonicalPath, err), new Win32Exception(err));
                }

                if (stdInfo.Directory == 0)
                {
                    throw new InvalidOperationException(string.Format("Ancestor path '{0}' standard info reports non-directory.", rec.ExpectedCanonicalPath));
                }

                if (stdInfo.DeletePending != 0)
                {
                    throw new InvalidOperationException(string.Format("Ancestor directory '{0}' has deletion pending.", rec.ExpectedCanonicalPath));
                }

                string actualCanonical = GetCanonicalDosPath(rec.Handle);
                bool isDriveRoot = (rec.ExpectedCanonicalPath.Length == 3 && rec.ExpectedCanonicalPath[1] == ':' && rec.ExpectedCanonicalPath[2] == '\\');
                string normActual = isDriveRoot ? (actualCanonical.TrimEnd('\\') + "\\") : actualCanonical.TrimEnd('\\');
                string normExpected = isDriveRoot ? (rec.ExpectedCanonicalPath.TrimEnd('\\') + "\\") : rec.ExpectedCanonicalPath.TrimEnd('\\');
                if (!string.Equals(normActual, normExpected, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException(string.Format("Ancestor directory '{0}' canonical path changed to '{1}'.", rec.ExpectedCanonicalPath, actualCanonical));
                }
            }

            // 2. Revalidate target file handle
            if (this._fileHandle == IntPtr.Zero || this._fileHandle == NativeMethods.INVALID_HANDLE_VALUE)
            {
                throw new InvalidOperationException("Target file handle is missing or uninitialized.");
            }

            uint targetFileType = NativeMethods.GetFileType(this._fileHandle);
            if (targetFileType != NativeMethods.FILE_TYPE_DISK)
            {
                throw new InvalidOperationException(string.Format("Target file handle is not a disk object: GetFileType returned {0}.", targetFileType));
            }

            NativeMethods.BY_HANDLE_FILE_INFORMATION targetFi;
            if (!NativeMethods.GetFileInformationByHandle(this._fileHandle, out targetFi))
            {
                int err = Marshal.GetLastWin32Error();
                throw new IOException(string.Format("Failed to query target file information by handle: Win32 error {0}.", err), new Win32Exception(err));
            }

            if ((targetFi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_DIRECTORY) != 0)
            {
                throw new InvalidOperationException("Target file is a directory, not a regular file.");
            }

            if ((targetFi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_REPARSE_POINT) != 0)
            {
                throw new InvalidOperationException("Target file is a reparse point.");
            }

            if (targetFi.nNumberOfLinks != 1)
            {
                throw new InvalidOperationException(string.Format("Target file link count must be exactly 1, got {0}.", targetFi.nNumberOfLinks));
            }

            if (this._identity != null)
            {
                if (targetFi.dwVolumeSerialNumber != this._identity.VolumeSerialNumber)
                {
                    throw new InvalidOperationException(string.Format("Target file volume serial number changed: expected {0}, got {1}.", this._identity.VolumeSerialNumber, targetFi.dwVolumeSerialNumber));
                }

                ulong fileIndex = ((ulong)targetFi.nFileIndexHigh << 32) | (ulong)targetFi.nFileIndexLow;
                if (fileIndex != this._identity.FileIndex)
                {
                    throw new InvalidOperationException(string.Format("Target file index changed: expected {0}, got {1}.", this._identity.FileIndex, fileIndex));
                }
            }

            ulong actualSize = ((ulong)targetFi.nFileSizeHigh << 32) | (ulong)targetFi.nFileSizeLow;
            if (actualSize != (ulong)this._expectedSize)
            {
                throw new InvalidOperationException(string.Format("Target file size changed: expected {0} bytes, actual {1} bytes.", this._expectedSize, actualSize));
            }

            NativeMethods.FILE_STANDARD_INFO targetStdInfo;
            if (!NativeMethods.GetFileInformationByHandleEx(this._fileHandle, NativeMethods.FileStandardInfo, out targetStdInfo, (uint)Marshal.SizeOf(typeof(NativeMethods.FILE_STANDARD_INFO))))
            {
                int err = Marshal.GetLastWin32Error();
                throw new IOException(string.Format("GetFileInformationByHandleEx(FileStandardInfo) failed on target file: Win32 error {0}.", err), new Win32Exception(err));
            }

            if (targetStdInfo.Directory != 0)
            {
                throw new InvalidOperationException("Target file standard info reports directory, not a regular file.");
            }

            if (targetStdInfo.DeletePending != 0)
            {
                throw new InvalidOperationException("Target file has deletion pending.");
            }

            if (targetStdInfo.NumberOfLinks != 1)
            {
                throw new InvalidOperationException(string.Format("Target file standard info link count must be exactly 1, got {0}.", targetStdInfo.NumberOfLinks));
            }

            if (targetStdInfo.EndOfFile != this._expectedSize)
            {
                throw new InvalidOperationException(string.Format("Target file EndOfFile changed: expected {0} bytes, actual {1} bytes.", this._expectedSize, targetStdInfo.EndOfFile));
            }

            if (this._identity != null)
            {
                string actualCanonicalFile = GetCanonicalDosPath(this._fileHandle);
                if (!string.Equals(actualCanonicalFile, this._identity.CanonicalPath, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException(string.Format("Target file canonical path changed: expected '{0}', got '{1}'.", this._identity.CanonicalPath, actualCanonicalFile));
                }
            }
        }

        private string ComputeSha256Internal()
        {
            long newPos;
            if (!NativeMethods.SetFilePointerEx(this._fileHandle, 0, out newPos, NativeMethods.FILE_BEGIN))
            {
                int err = Marshal.GetLastWin32Error();
                throw new IOException(string.Format("Failed to reset file pointer: Win32 error {0}.", err), new Win32Exception(err));
            }

            long totalBytesRead = 0;
            const int bufferSize = 65536; // 64 KiB bounded chunks
            byte[] scratch = new byte[bufferSize];

            using (SHA256 sha256 = SHA256.Create())
            {
                while (totalBytesRead < this._expectedSize)
                {
                    int toRead = (int)Math.Min((long)bufferSize, this._expectedSize - totalBytesRead);
                    uint bytesRead;
                    if (!NativeMethods.ReadFile(this._fileHandle, scratch, (uint)toRead, out bytesRead, IntPtr.Zero))
                    {
                        int err = Marshal.GetLastWin32Error();
                        throw new IOException(string.Format("ReadFile failed: Win32 error {0}.", err), new Win32Exception(err));
                    }
                    if (bytesRead == 0)
                    {
                        throw new EndOfStreamException(string.Format("Unexpected EOF after reading {0} of {1} expected bytes.", totalBytesRead, this._expectedSize));
                    }
                    if (bytesRead > (uint)toRead)
                    {
                        throw new InvalidOperationException(string.Format("ReadFile returned more bytes ({0}) than requested ({1}).", bytesRead, toRead));
                    }

                    sha256.TransformBlock(scratch, 0, (int)bytesRead, null, 0);
                    totalBytesRead += bytesRead;
                }

                sha256.TransformFinalBlock(new byte[0], 0, 0);
                byte[] hashBytes = sha256.Hash;
                StringBuilder sb = new StringBuilder(hashBytes.Length * 2);
                for (int i = 0; i < hashBytes.Length; i++)
                {
                    sb.Append(hashBytes[i].ToString("x2"));
                }
                return sb.ToString();
            }
        }

        /// <summary>
        /// Idempotently closes the target file handle and all ancestor directory handles in reverse order (leaf to root).
        /// Returns true if all handles were successfully closed or were already closed.
        /// If any CloseHandle call fails, returns false with the first native error, preserves unclosed handles,
        /// and records the failure in LastCleanupFailure.
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

                // Latch validated state to false BEFORE closing any handles so a partial failure
                // permanently leaves the lease close-only; once close-only, always close-only.
                this._acquisitionValidated = false;

                if (this._isClosed)
                {
                    nativeError = 0;
                    return true;
                }

                int firstError = 0;
                List<Exception> closeExceptions = new List<Exception>();

                // 1. Close target file handle first
                if (this._fileHandle != IntPtr.Zero && this._fileHandle != NativeMethods.INVALID_HANDLE_VALUE)
                {
                    try
                    {
                        if (!NativeMethods.CloseHandle(this._fileHandle))
                        {
                            int err = Marshal.GetLastWin32Error();
                            if (firstError == 0) firstError = err;
                            closeExceptions.Add(new Win32Exception(err, string.Format("CloseHandle failed on target file handle: Win32 error {0}.", err)));
                        }
                        else
                        {
                            this._fileHandle = IntPtr.Zero;
                        }
                    }
                    catch (Exception ex)
                    {
                        if (firstError == 0) firstError = -1;
                        closeExceptions.Add(ex);
                    }
                }
                else
                {
                    this._fileHandle = IntPtr.Zero;
                }

                // 2. Close ALL ancestor directory handles in REVERSE order (parent down to root)
                for (int i = this._directoryRecords.Count - 1; i >= 0; i--)
                {
                    DirectoryHandleRecord rec = this._directoryRecords[i];
                    if (rec != null && rec.Handle != IntPtr.Zero && rec.Handle != NativeMethods.INVALID_HANDLE_VALUE)
                    {
                        try
                        {
                            if (!NativeMethods.CloseHandle(rec.Handle))
                            {
                                int err = Marshal.GetLastWin32Error();
                                if (firstError == 0) firstError = err;
                                closeExceptions.Add(new Win32Exception(err, string.Format("CloseHandle failed on ancestor directory handle for '{0}': Win32 error {1}.", rec.ExpectedCanonicalPath, err)));
                            }
                            else
                            {
                                rec.Handle = IntPtr.Zero;
                            }
                        }
                        catch (Exception ex)
                        {
                            if (firstError == 0) firstError = -1;
                            closeExceptions.Add(ex);
                        }
                    }
                }

                if (closeExceptions.Count > 0)
                {
                    nativeError = firstError != 0 ? firstError : -1;
                    this._lastCleanupFailure = closeExceptions.Count == 1
                        ? closeExceptions[0]
                        : new AggregateException("One or more handles failed to close during TryClose.", closeExceptions);
                    return false;
                }

                this._isClosed = true;
                nativeError = 0;
                return true;
            }
        }

        /// <summary>
        /// Closes the native file handle and all ancestor directory handles.
        /// Throws WorkerImageReadLeaseCleanupException if any handle fails to close.
        /// </summary>
        public void Dispose()
        {
            int err;
            if (!this.TryClose(out err))
            {
                Exception failure = this.LastCleanupFailure;
                if (failure != null)
                {
                    throw new WorkerImageReadLeaseCleanupException(
                        string.Format("Failed to close worker image read lease handles: Win32 error {0}.", err),
                        failure,
                        this,
                        err
                    );
                }
                throw new WorkerImageReadLeaseCleanupException(
                    string.Format("Failed to close worker image read lease handles: Win32 error {0}.", err),
                    new Win32Exception(err),
                    this,
                    err
                );
            }
        }

        private static string GetCanonicalDosPath(IntPtr handle)
        {
            uint capacity = 1024;
            const uint maxCapacity = 32768;
            StringBuilder sb = new StringBuilder((int)capacity);
            uint ret = NativeMethods.GetFinalPathNameByHandleW(handle, sb, capacity, NativeMethods.VOLUME_NAME_DOS);
            if (ret == 0)
            {
                int err = Marshal.GetLastWin32Error();
                throw new IOException(string.Format("Failed to obtain canonical path from native handle: Win32 error {0}.", err), new Win32Exception(err));
            }

            if (ret >= capacity)
            {
                if (ret >= maxCapacity)
                {
                    throw new InvalidOperationException(string.Format("Final path required capacity ({0}) exceeds maximum supported capacity ({1}).", ret, maxCapacity));
                }
                capacity = ret + 1;
                sb = new StringBuilder((int)capacity);
                ret = NativeMethods.GetFinalPathNameByHandleW(handle, sb, capacity, NativeMethods.VOLUME_NAME_DOS);
                if (ret == 0)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("Failed to obtain canonical path on retry: Win32 error {0}.", err), new Win32Exception(err));
                }
                if (ret >= capacity || ret >= maxCapacity)
                {
                    throw new InvalidOperationException(string.Format("Final path retry buffer insufficient or exceeded max capacity (ret={0}, capacity={1}).", ret, capacity));
                }
            }

            string raw = sb.ToString();
            if (raw.Length != (int)ret)
            {
                throw new InvalidOperationException(string.Format("Canonical path length mismatch: API returned {0} chars, but StringBuilder has {1} chars.", ret, raw.Length));
            }

            if (raw.StartsWith(@"\\?\UNC\", StringComparison.OrdinalIgnoreCase))
            {
                throw new NotSupportedException("UNC paths are not supported: " + raw);
            }
            if (raw.StartsWith(@"\\?\", StringComparison.OrdinalIgnoreCase))
            {
                raw = raw.Substring(4);
            }

            if (raw.Length < 3 || !((raw[0] >= 'A' && raw[0] <= 'Z') || (raw[0] >= 'a' && raw[0] <= 'z')) || raw[1] != ':' || raw[2] != '\\')
            {
                throw new InvalidOperationException("Canonical path is not a valid DOS path: " + raw);
            }

            return raw;
        }

        private static class NativeMethods
        {
            internal static readonly IntPtr INVALID_HANDLE_VALUE = new IntPtr(-1);

            internal const uint GENERIC_READ = 0x80000000;
            internal const uint FILE_LIST_DIRECTORY = 0x0001;
            internal const uint FILE_READ_ATTRIBUTES = 0x0080;
            internal const uint FILE_SHARE_READ = 0x00000001;
            internal const uint OPEN_EXISTING = 3;
            internal const uint FILE_FLAG_BACKUP_SEMANTICS = 0x02000000;
            internal const uint FILE_FLAG_OPEN_REPARSE_POINT = 0x00200000;
            internal const uint FILE_TYPE_DISK = 0x0001;
            internal const uint FILE_ATTRIBUTE_DIRECTORY = 0x00000010;
            internal const uint FILE_ATTRIBUTE_REPARSE_POINT = 0x00000400;
            internal const uint FILE_BEGIN = 0;
            internal const uint VOLUME_NAME_DOS = 0x0;
            internal const int FileStandardInfo = 1;

            [StructLayout(LayoutKind.Explicit, Size = 24)]
            internal struct FILE_STANDARD_INFO
            {
                [FieldOffset(0)]
                public long AllocationSize;

                [FieldOffset(8)]
                public long EndOfFile;

                [FieldOffset(16)]
                public uint NumberOfLinks;

                [FieldOffset(20)]
                public byte DeletePending;

                [FieldOffset(21)]
                public byte Directory;
            }

            [StructLayout(LayoutKind.Sequential)]
            internal struct FILETIME
            {
                public uint dwLowDateTime;
                public uint dwHighDateTime;
            }

            [StructLayout(LayoutKind.Sequential)]
            internal struct BY_HANDLE_FILE_INFORMATION
            {
                public uint dwFileAttributes;
                public FILETIME ftCreationTime;
                public FILETIME ftLastAccessTime;
                public FILETIME ftLastWriteTime;
                public uint dwVolumeSerialNumber;
                public uint nFileSizeHigh;
                public uint nFileSizeLow;
                public uint nNumberOfLinks;
                public uint nFileIndexHigh;
                public uint nFileIndexLow;
            }

            [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
            internal static extern IntPtr CreateFileW(
                string lpFileName,
                uint dwDesiredAccess,
                uint dwShareMode,
                IntPtr lpSecurityAttributes,
                uint dwCreationDisposition,
                uint dwFlagsAndAttributes,
                IntPtr hTemplateFile
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool CloseHandle(IntPtr hObject);

            [DllImport("kernel32.dll", SetLastError = true)]
            internal static extern uint GetFileType(IntPtr hFile);

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool GetFileInformationByHandle(
                IntPtr hFile,
                out BY_HANDLE_FILE_INFORMATION lpFileInformation
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool GetFileInformationByHandleEx(
                IntPtr hFile,
                int FileInformationClass,
                out FILE_STANDARD_INFO lpFileInformation,
                uint dwBufferSize
            );

            [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
            internal static extern uint GetFinalPathNameByHandleW(
                IntPtr hFile,
                [Out] StringBuilder lpszFilePath,
                uint cchFilePath,
                uint dwFlags
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool SetFilePointerEx(
                IntPtr hFile,
                long liDistanceToMove,
                out long lpNewFilePointer,
                uint dwMoveMethod
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool ReadFile(
                IntPtr hFile,
                [Out] byte[] lpBuffer,
                uint nNumberOfBytesToRead,
                out uint lpNumberOfBytesRead,
                IntPtr lpOverlapped
            );
        }
    }
}
