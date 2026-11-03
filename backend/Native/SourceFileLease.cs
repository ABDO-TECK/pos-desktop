// backend/Native/SourceFileLease.cs
//
// INTERNAL native leased-file reader / verified stream-copy primitive.
//
// Architecture & Trust Notice:
// Caller MUST authenticate expected metadata from signed SOURCE inventory,
// select trusted root/paths with ProductInventoryService, and enforce
// process quiescence, launch interlock, DB state, and health.
//
// This internal component does NOT verify RSA signatures, authorize paths,
// introspect process IDs (PID), or prove whole-process quiescence/durability.
// Memory data/view mappings may still change file bytes concurrently, so callers
// must verify bytes for each copy operation via CopyVerifiedTo.
//
// This component provides exclusive read/copy access through the SAME owned
// Win32 handle (share mode 0), blocking ordinary sharing-aware file opens and
// blocking EXE/DLL image mappings while leased. It does not prevent all concurrently
// mapped writable memory views, so callers must verify bytes for each copy.

namespace PosDesktop.Updates.Native
{
    using System;
    using System.ComponentModel;
    using System.IO;
    using System.Runtime.InteropServices;
    using System.Security.Cryptography;
    using System.Text;

    /// <summary>
    /// Captures immutable identity metadata for a leased source file.
    /// </summary>
    public sealed class SourceFileIdentity
    {
        private readonly uint _volumeSerialNumber;
        private readonly ulong _fileIndex;
        private readonly long _size;
        private readonly string _canonicalPath;

        public SourceFileIdentity(uint volumeSerialNumber, ulong fileIndex, long size, string canonicalPath)
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
    /// Result of a verified stream copy operation.
    /// </summary>
    public sealed class StreamCopyResult
    {
        private readonly long _bytesCopied;
        private readonly string _sha256;

        public StreamCopyResult(long bytesCopied, string sha256)
        {
            this._bytesCopied = bytesCopied;
            this._sha256 = sha256;
        }

        public long BytesCopied { get { return this._bytesCopied; } }
        public string Sha256 { get { return this._sha256; } }
    }

    /// <summary>
    /// Exception thrown when validation fails during lease acquisition and subsequent handle closure also fails,
    /// ensuring the retained unclosed lease is never lost or orphaned.
    /// </summary>
    public sealed class SourceFileLeaseAcquisitionException : Exception
    {
        private readonly SourceFileLease _retainedLease;
        private readonly int _closeError;

        public SourceFileLeaseAcquisitionException(string message, Exception innerException, SourceFileLease retainedLease, int closeError)
            : base(message, innerException)
        {
            this._retainedLease = retainedLease;
            this._closeError = closeError;
        }

        public SourceFileLease RetainedLease { get { return this._retainedLease; } }
        public int CloseError { get { return this._closeError; } }
    }

    /// <summary>
    /// Provides exclusive native file lease semantics and verified stream copying
    /// strictly through an owned Win32 file handle.
    /// </summary>
    public sealed class SourceFileLease : IDisposable
    {
        private readonly object _syncLock;
        private readonly string _expectedSha256;
        private IntPtr _handle;
        private SourceFileIdentity _identity;
        private bool _isClosed;
        private bool _ioInProgress;
        private bool _acquisitionValidated;

        private SourceFileLease(string expectedSha256)
        {
            this._syncLock = new object();
            this._handle = IntPtr.Zero;
            this._expectedSha256 = expectedSha256;
            this._isClosed = false;
            this._ioInProgress = false;
            this._acquisitionValidated = false;
        }

        public SourceFileIdentity Identity
        {
            get { return this._identity; }
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
        /// Acquires an exclusive file lease (share 0) on the target path, validating
        /// path structure, reparse safety, file identity, expected size, and expected SHA-256 digest.
        /// </summary>
        public static SourceFileLease Acquire(string absolutePath, long expectedSize, string expectedSha256)
        {
            // Fail closed if not running on Win32NT 64-bit
            if (Environment.OSVersion.Platform != PlatformID.Win32NT || IntPtr.Size != 8)
            {
                throw new PlatformNotSupportedException("SourceFileLease requires 64-bit Windows (Win32NT x64).");
            }

            // Expected size validation
            if (expectedSize < 0)
            {
                throw new ArgumentOutOfRangeException("expectedSize", "Expected size must be non-negative.");
            }

            // Expected SHA-256 validation: strict 64 lowercase hex characters
            if (string.IsNullOrEmpty(expectedSha256) || expectedSha256.Length != 64)
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

            // Path syntax and ancestor reparse validation
            ValidateDosPath(absolutePath);

            // Construct lease owner object BEFORE CreateFileW to minimize unowned handle allocation window
            SourceFileLease lease = new SourceFileLease(expectedSha256);

            try
            {
                // Open handle exclusively (share 0) with FILE_FLAG_OPEN_REPARSE_POINT so symlinks are not followed
                IntPtr handle = NativeMethods.CreateFileW(
                    absolutePath,
                    NativeMethods.EXCLUSIVE_ACCESS,
                    NativeMethods.FILE_SHARE_NONE,
                    IntPtr.Zero,
                    NativeMethods.OPEN_EXISTING,
                    NativeMethods.FILE_FLAG_OPEN_REPARSE_POINT,
                    IntPtr.Zero
                );

                if (handle == NativeMethods.INVALID_HANDLE_VALUE || handle == IntPtr.Zero)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("Failed to open source file with exclusive lease: Win32 error {0}.", err), new Win32Exception(err));
                }

                // Assign returned handle immediately to the owner lease before any post-open assertion
                lease._handle = handle;

                // Validate file attributes, link count, and expected size BEFORE hash
                lease.AssertFileInformation(expectedSize);

                // Retrieve and verify canonical DOS path
                string canonicalPath = GetCanonicalDosPath(handle);
                string expectedCanonical = Path.GetFullPath(absolutePath);
                if (!string.Equals(canonicalPath, expectedCanonical, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException(string.Format("Canonical path mismatch: expected '{0}', got '{1}'.", expectedCanonical, canonicalPath));
                }

                NativeMethods.BY_HANDLE_FILE_INFORMATION fi;
                if (!NativeMethods.GetFileInformationByHandle(handle, out fi))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("Failed to query file information by handle: Win32 error {0}.", err), new Win32Exception(err));
                }

                lease._identity = new SourceFileIdentity(
                    fi.dwVolumeSerialNumber,
                    ((ulong)fi.nFileIndexHigh << 32) | (ulong)fi.nFileIndexLow,
                    expectedSize,
                    canonicalPath
                );

                // Hash via handle and compare to expected caller-authenticated digest
                string actualSha256 = lease.ComputeSha256Internal();
                if (!string.Equals(actualSha256, expectedSha256, StringComparison.Ordinal))
                {
                    throw new InvalidOperationException(string.Format("SHA-256 digest mismatch: expected '{0}', computed '{1}'.", expectedSha256, actualSha256));
                }

                // Re-verify file identity/size AFTER full hash
                lease.AssertFileInformation(expectedSize);

                // Mark lease validated so public I/O methods become available
                lease._acquisitionValidated = true;

                return lease;
            }
            catch (Exception ex)
            {
                int closeErr;
                if (!lease.TryClose(out closeErr))
                {
                    throw new SourceFileLeaseAcquisitionException(
                        string.Format("Lease acquisition validation failed ({0}) and subsequent handle closure also failed with Win32 error {1}.", ex.Message, closeErr),
                        ex,
                        lease,
                        closeErr
                    );
                }
                throw;
            }
        }

        /// <summary>
        /// Reads an exact byte range through the owned native handle.
        /// Maximum allowed count is 1 MiB (1,048,576 bytes). Count of 0 returns an empty array.
        /// </summary>
        public byte[] ReadRange(long offset, int count)
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("SourceFileLease");
                }
                if (this._ioInProgress)
                {
                    throw new InvalidOperationException("Reentrant or concurrent I/O detected.");
                }
                if (!this._acquisitionValidated)
                {
                    throw new InvalidOperationException("Source file lease is not validated; only closure is permitted.");
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

                if (count == 0)
                {
                    return new byte[0];
                }

                this._ioInProgress = true;
                try
                {
                    long newPos;
                    if (!NativeMethods.SetFilePointerEx(this._handle, offset, out newPos, NativeMethods.FILE_BEGIN))
                    {
                        int err = Marshal.GetLastWin32Error();
                        throw new IOException(string.Format("SetFilePointerEx failed: Win32 error {0}.", err), new Win32Exception(err));
                    }

                    byte[] result = new byte[count];
                    IntPtr unmanagedBuf = Marshal.AllocHGlobal(count);
                    try
                    {
                        int totalRead = 0;
                        while (totalRead < count)
                        {
                            IntPtr target = new IntPtr(unmanagedBuf.ToInt64() + totalRead);
                            uint toRead = (uint)(count - totalRead);
                            uint bytesRead;
                            if (!NativeMethods.ReadFile(this._handle, target, toRead, out bytesRead, IntPtr.Zero))
                            {
                                int err = Marshal.GetLastWin32Error();
                                throw new IOException(string.Format("ReadFile failed: Win32 error {0}.", err), new Win32Exception(err));
                            }
                            if (bytesRead == 0)
                            {
                                throw new EndOfStreamException(string.Format("Unexpected EOF after reading {0} of {1} requested bytes.", totalRead, count));
                            }
                            totalRead += (int)bytesRead;
                        }

                        Marshal.Copy(unmanagedBuf, result, 0, count);
                        return result;
                    }
                    finally
                    {
                        Marshal.FreeHGlobal(unmanagedBuf);
                    }
                }
                finally
                {
                    this._ioInProgress = false;
                }
            }
        }

        /// <summary>
        /// Computes the SHA-256 hash by reading bounded 64 KiB chunks through the owned handle
        /// and verifies that the computed hash matches the expected metadata digest.
        /// </summary>
        public string ComputeSha256()
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("SourceFileLease");
                }
                if (this._ioInProgress)
                {
                    throw new InvalidOperationException("Reentrant or concurrent I/O detected.");
                }
                if (!this._acquisitionValidated)
                {
                    throw new InvalidOperationException("Source file lease is not validated; only closure is permitted.");
                }

                this.AssertFileInformation(this._identity.Size);
                string computed = this.ComputeSha256Internal();
                this.AssertFileInformation(this._identity.Size);
                return computed;
            }
        }

        /// <summary>
        /// Copies source bytes to destination stream in bounded 64 KiB chunks through the owned handle,
        /// hashing emitted bytes and verifying length and SHA-256 against expected metadata.
        /// Destination stream is caller-owned and is never flushed, closed, or disposed by this library.
        /// </summary>
        public StreamCopyResult CopyVerifiedTo(Stream destination)
        {
            if (destination == null)
            {
                throw new ArgumentNullException("destination");
            }

            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("SourceFileLease");
                }
                if (this._ioInProgress)
                {
                    throw new InvalidOperationException("Reentrant or concurrent I/O detected.");
                }
                if (!this._acquisitionValidated)
                {
                    throw new InvalidOperationException("Source file lease is not validated; only closure is permitted.");
                }

                this.AssertFileInformation(this._identity.Size);

                this._ioInProgress = true;
                try
                {
                    long newPos;
                    if (!NativeMethods.SetFilePointerEx(this._handle, 0, out newPos, NativeMethods.FILE_BEGIN))
                    {
                        int err = Marshal.GetLastWin32Error();
                        throw new IOException(string.Format("Failed to reset source file pointer: Win32 error {0}.", err), new Win32Exception(err));
                    }

                    long totalBytesCopied = 0;
                    string computedSha256;

                    using (SHA256 sha256 = SHA256.Create())
                    {
                        const int bufferSize = 65536; // 64 KiB
                        byte[] managedBuf = new byte[bufferSize];
                        IntPtr unmanagedBuf = Marshal.AllocHGlobal(bufferSize);
                        try
                        {
                            while (totalBytesCopied < this._identity.Size)
                            {
                                int toRead = (int)Math.Min((long)bufferSize, this._identity.Size - totalBytesCopied);
                                uint bytesRead;
                                if (!NativeMethods.ReadFile(this._handle, unmanagedBuf, (uint)toRead, out bytesRead, IntPtr.Zero))
                                {
                                    int err = Marshal.GetLastWin32Error();
                                    throw new IOException(string.Format("ReadFile failed: Win32 error {0}.", err), new Win32Exception(err));
                                }
                                if (bytesRead == 0)
                                {
                                    throw new EndOfStreamException(string.Format("Unexpected EOF after copying {0} of {1} expected bytes.", totalBytesCopied, this._identity.Size));
                                }

                                Marshal.Copy(unmanagedBuf, managedBuf, 0, (int)bytesRead);
                                sha256.TransformBlock(managedBuf, 0, (int)bytesRead, null, 0);

                                // Write to caller destination; any write failure propagates untouched
                                destination.Write(managedBuf, 0, (int)bytesRead);

                                totalBytesCopied += bytesRead;
                            }

                            sha256.TransformFinalBlock(new byte[0], 0, 0);
                            byte[] hashBytes = sha256.Hash;
                            StringBuilder sb = new StringBuilder(hashBytes.Length * 2);
                            for (int i = 0; i < hashBytes.Length; i++)
                            {
                                sb.Append(hashBytes[i].ToString("x2"));
                            }
                            computedSha256 = sb.ToString();
                        }
                        finally
                        {
                            Marshal.FreeHGlobal(unmanagedBuf);
                        }
                    }

                    if (totalBytesCopied != this._identity.Size)
                    {
                        throw new InvalidOperationException(string.Format("Copied byte length mismatch: expected {0}, got {1}.", this._identity.Size, totalBytesCopied));
                    }
                    if (!string.Equals(computedSha256, this._expectedSha256, StringComparison.Ordinal))
                    {
                        throw new InvalidOperationException(string.Format("Copied byte SHA-256 digest mismatch: expected '{0}', got '{1}'.", this._expectedSha256, computedSha256));
                    }

                    this.AssertFileInformation(this._identity.Size);

                    return new StreamCopyResult(totalBytesCopied, computedSha256);
                }
                finally
                {
                    this._ioInProgress = false;
                }
            }
        }

        /// <summary>
        /// Idempotently closes the owned native file handle. Returns true if successfully closed or already closed.
        /// If CloseHandle fails, returns false with nativeError and preserves handle ownership for caller retention.
        /// Available at all times, including on leases with failed acquisition.
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

                if (this._isClosed)
                {
                    nativeError = 0;
                    return true;
                }

                if (this._handle == IntPtr.Zero || this._handle == NativeMethods.INVALID_HANDLE_VALUE)
                {
                    this._isClosed = true;
                    nativeError = 0;
                    return true;
                }

                if (!NativeMethods.CloseHandle(this._handle))
                {
                    nativeError = Marshal.GetLastWin32Error();
                    return false;
                }

                this._handle = IntPtr.Zero;
                this._isClosed = true;
                nativeError = 0;
                return true;
            }
        }

        /// <summary>
        /// Closes the native file handle. Throws IOException if CloseHandle fails.
        /// Callers in teardown or cleanup paths should preserve any primary exception before disposing.
        /// </summary>
        public void Dispose()
        {
            int err;
            if (!this.TryClose(out err))
            {
                throw new IOException(string.Format("Failed to close native file handle: Win32 error {0}.", err), new Win32Exception(err));
            }
        }

        private void AssertFileInformation(long expectedSize)
        {
            uint fileType = NativeMethods.GetFileType(this._handle);
            if (fileType != NativeMethods.FILE_TYPE_DISK)
            {
                throw new InvalidOperationException(string.Format("Native handle is not a disk file: GetFileType returned {0}.", fileType));
            }

            NativeMethods.BY_HANDLE_FILE_INFORMATION fi;
            if (!NativeMethods.GetFileInformationByHandle(this._handle, out fi))
            {
                int err = Marshal.GetLastWin32Error();
                throw new IOException(string.Format("Failed to query file information by handle: Win32 error {0}.", err), new Win32Exception(err));
            }

            if ((fi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_DIRECTORY) != 0)
            {
                throw new InvalidOperationException("Target path is a directory, not a regular file.");
            }

            if ((fi.dwFileAttributes & NativeMethods.FILE_ATTRIBUTE_REPARSE_POINT) != 0)
            {
                throw new InvalidOperationException("Target file is a reparse point.");
            }

            if (fi.nNumberOfLinks != 1)
            {
                throw new InvalidOperationException(string.Format("Target file link count must be exactly 1, got {0}.", fi.nNumberOfLinks));
            }

            ulong actualSize = ((ulong)fi.nFileSizeHigh << 32) | (ulong)fi.nFileSizeLow;
            if (actualSize != (ulong)expectedSize)
            {
                throw new InvalidOperationException(string.Format("File size mismatch: expected {0} bytes, actual {1} bytes.", expectedSize, actualSize));
            }

            if (this._identity != null)
            {
                if (fi.dwVolumeSerialNumber != this._identity.VolumeSerialNumber)
                {
                    throw new InvalidOperationException(string.Format("File volume serial number changed: expected {0}, got {1}.", this._identity.VolumeSerialNumber, fi.dwVolumeSerialNumber));
                }

                ulong fileIndex = ((ulong)fi.nFileIndexHigh << 32) | (ulong)fi.nFileIndexLow;
                if (fileIndex != this._identity.FileIndex)
                {
                    throw new InvalidOperationException(string.Format("File index changed: expected {0}, got {1}.", this._identity.FileIndex, fileIndex));
                }
            }
        }

        private string ComputeSha256Internal()
        {
            this._ioInProgress = true;
            try
            {
                long newPos;
                if (!NativeMethods.SetFilePointerEx(this._handle, 0, out newPos, NativeMethods.FILE_BEGIN))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("Failed to reset source file pointer: Win32 error {0}.", err), new Win32Exception(err));
                }

                long totalBytesRead = 0;
                using (SHA256 sha256 = SHA256.Create())
                {
                    const int bufferSize = 65536; // 64 KiB
                    byte[] managedBuf = new byte[bufferSize];
                    IntPtr unmanagedBuf = Marshal.AllocHGlobal(bufferSize);
                    try
                    {
                        while (totalBytesRead < this._identity.Size)
                        {
                            int toRead = (int)Math.Min((long)bufferSize, this._identity.Size - totalBytesRead);
                            uint bytesRead;
                            if (!NativeMethods.ReadFile(this._handle, unmanagedBuf, (uint)toRead, out bytesRead, IntPtr.Zero))
                            {
                                int err = Marshal.GetLastWin32Error();
                                throw new IOException(string.Format("ReadFile failed: Win32 error {0}.", err), new Win32Exception(err));
                            }
                            if (bytesRead == 0)
                            {
                                throw new EndOfStreamException(string.Format("Unexpected EOF after reading {0} of {1} expected bytes.", totalBytesRead, this._identity.Size));
                            }

                            Marshal.Copy(unmanagedBuf, managedBuf, 0, (int)bytesRead);
                            sha256.TransformBlock(managedBuf, 0, (int)bytesRead, null, 0);
                            totalBytesRead += bytesRead;
                        }

                        sha256.TransformFinalBlock(new byte[0], 0, 0);
                        byte[] hashBytes = sha256.Hash;
                        StringBuilder sb = new StringBuilder(hashBytes.Length * 2);
                        for (int i = 0; i < hashBytes.Length; i++)
                        {
                            sb.Append(hashBytes[i].ToString("x2"));
                        }
                        string computed = sb.ToString();

                        if (!string.Equals(computed, this._expectedSha256, StringComparison.Ordinal))
                        {
                            throw new InvalidOperationException(string.Format("SHA-256 digest mismatch: expected '{0}', computed '{1}'.", this._expectedSha256, computed));
                        }

                        return computed;
                    }
                    finally
                    {
                        Marshal.FreeHGlobal(unmanagedBuf);
                    }
                }
            }
            finally
            {
                this._ioInProgress = false;
            }
        }

        private static void ValidateDosPath(string path)
        {
            if (string.IsNullOrWhiteSpace(path))
            {
                throw new ArgumentException("Path must not be null, empty, or whitespace.", "absolutePath");
            }

            if (path.Length < 3 || !((path[0] >= 'A' && path[0] <= 'Z') || (path[0] >= 'a' && path[0] <= 'z')) || path[1] != ':' || path[2] != '\\')
            {
                throw new ArgumentException("Path must be a fully qualified DOS path starting with drive letter and backslash (e.g. C:\\): " + path, "absolutePath");
            }

            if (path.IndexOf('/') != -1)
            {
                throw new ArgumentException("Path must not contain forward slashes: " + path, "absolutePath");
            }

            if (path.IndexOf(':', 2) != -1)
            {
                throw new ArgumentException("Path must not contain alternate data streams or extra colons: " + path, "absolutePath");
            }

            if (path.Contains(".."))
            {
                throw new ArgumentException("Path must not contain '..' segments: " + path, "absolutePath");
            }

            if (path.Length == 3)
            {
                throw new ArgumentException("Drive root is not a valid file target: " + path, "absolutePath");
            }

            if (path.EndsWith("\\"))
            {
                throw new ArgumentException("Path must not end with a trailing backslash: " + path, "absolutePath");
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
                if (seg.IndexOf('~') != -1)
                {
                    throw new ArgumentException("Path components must not contain 8.3 short-name aliases (~): " + path, "absolutePath");
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

            string parentDir = Path.GetDirectoryName(path);
            if (string.IsNullOrEmpty(parentDir))
            {
                throw new ArgumentException("Unable to determine directory for path: " + path, "absolutePath");
            }

            DirectoryInfo curr = new DirectoryInfo(parentDir);
            while (curr != null)
            {
                if (!curr.Exists)
                {
                    throw new DirectoryNotFoundException("Ancestor directory does not exist: " + curr.FullName);
                }
                if ((curr.Attributes & FileAttributes.ReparsePoint) != 0)
                {
                    throw new InvalidOperationException("Ancestor directory is a reparse point: " + curr.FullName);
                }
                curr = curr.Parent;
            }

            if (File.Exists(path))
            {
                FileInfo fi = new FileInfo(path);
                if ((fi.Attributes & FileAttributes.ReparsePoint) != 0)
                {
                    throw new InvalidOperationException("Target file is a reparse point: " + path);
                }
            }
        }

        private static bool IsReservedDosDeviceName(string name)
        {
            if (string.IsNullOrEmpty(name)) return false;
            string upper = name.ToUpperInvariant();
            if (upper == "CON" || upper == "PRN" || upper == "AUX" || upper == "NUL") return true;
            if (upper.Length == 4)
            {
                if (upper.StartsWith("COM") || upper.StartsWith("LPT"))
                {
                    char c = upper[3];
                    if (c >= '1' && c <= '9') return true;
                }
            }
            return false;
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
            internal const uint GENERIC_WRITE = 0x40000000;
            internal const uint DELETE = 0x00010000;
            internal const uint EXCLUSIVE_ACCESS = GENERIC_READ | GENERIC_WRITE | DELETE; // 3221291008
            internal const uint FILE_SHARE_NONE = 0;
            internal const uint OPEN_EXISTING = 3;
            internal const uint FILE_FLAG_OPEN_REPARSE_POINT = 0x00200000; // 2097152
            internal const uint FILE_TYPE_DISK = 0x0001;
            internal const uint FILE_ATTRIBUTE_DIRECTORY = 0x00000010;
            internal const uint FILE_ATTRIBUTE_REPARSE_POINT = 0x00000400;
            internal const uint FILE_BEGIN = 0;
            internal const uint VOLUME_NAME_DOS = 0x0;

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
                IntPtr lpBuffer,
                uint nNumberOfBytesToRead,
                out uint lpNumberOfBytesRead,
                IntPtr lpOverlapped
            );
        }
    }
}
