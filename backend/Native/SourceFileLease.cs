// backend/Native/SourceFileLease.cs
//
// INTERNAL native leased-file reader / verified stream-copy / same-directory rename / verified staging primitive.
//
// Architecture & Trust Notice:
// Caller MUST authenticate expected metadata from signed SOURCE or TARGET inventory,
// select trusted root/paths with ProductInventoryService, authorize old, new, and staging names,
// and enforce process quiescence, launch interlock, DB state, and storage proof.
//
// For staging operations (CreateNewVerified), the caller MUST authenticate target
// expected size/SHA-256 and old/new root/names from signed TARGET inventory, authorize a fresh
// nonce-staging namespace, control ancestor directories, and manage process/launch interlocks,
// DB state, and transaction journals.
//
// This internal component does NOT verify RSA signatures, authorize path inventory,
// introspect process IDs (PID), record journal/DB state, provide transactional durability
// or staging journals, or prove absolute ancestor TOCTOU / power loss guarantees. Directory traversal
// and path checks cannot be claimed absolute against concurrent attacker directory swaps; private
// authorized root verification is the caller's responsibility. Current library is INTERNAL and unwired.
//
// Memory data/view mappings may still change file bytes concurrently, so callers
// must verify bytes for each copy operation via CopyVerifiedTo.
//
// This component provides exclusive read/copy/rename/write access through the SAME owned
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
    /// Note: CanonicalPath represents the confirmed canonical path snapshot at creation
    /// or after the most recent successful verified rename. If IsMutationUncertain is true,
    /// the on-disk path may have changed or failed in an intermediate state, and CanonicalPath
    /// must not be assumed current.
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
    /// Exception thrown when a file rename operation enters an uncertain mutation state,
    /// preserving handle ownership and diagnostic context for caller retention.
    /// </summary>
    public sealed class SourceFileLeaseRenameException : Exception
    {
        private readonly SourceFileLease _retainedLease;
        private readonly int _nativeError;
        private readonly bool _mayHaveMoved;
        private readonly string _observedCanonicalPath;

        public SourceFileLeaseRenameException(
            string message,
            SourceFileLease retainedLease,
            int nativeError,
            bool mayHaveMoved,
            string observedCanonicalPath)
            : this(message, null, retainedLease, nativeError, mayHaveMoved, observedCanonicalPath)
        {
        }

        public SourceFileLeaseRenameException(
            string message,
            Exception innerException,
            SourceFileLease retainedLease,
            int nativeError,
            bool mayHaveMoved,
            string observedCanonicalPath)
            : base(message, innerException)
        {
            this._retainedLease = retainedLease;
            this._nativeError = nativeError;
            this._mayHaveMoved = mayHaveMoved;
            this._observedCanonicalPath = observedCanonicalPath;
        }

        public SourceFileLease RetainedLease { get { return this._retainedLease; } }
        public int NativeError { get { return this._nativeError; } }
        public bool MayHaveMoved { get { return this._mayHaveMoved; } }
        public string ObservedCanonicalPath { get { return this._observedCanonicalPath; } }
    }

    /// <summary>
    /// Exception thrown when staging or post-creation validation fails after a native handle has been created,
    /// retaining handle ownership and staging path diagnostic context for caller retention.
    /// </summary>
    public sealed class SourceFileLeaseCreationException : Exception
    {
        private readonly SourceFileLease _retainedLease;
        private readonly string _stagePath;

        public SourceFileLeaseCreationException(string message, Exception innerException, SourceFileLease retainedLease, string stagePath)
            : base(message, innerException)
        {
            this._retainedLease = retainedLease;
            this._stagePath = stagePath;
        }

        public SourceFileLease RetainedLease { get { return this._retainedLease; } }
        public string StagePath { get { return this._stagePath; } }
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
        private bool _mutationUncertain;

        private SourceFileLease(string expectedSha256)
        {
            this._syncLock = new object();
            this._handle = IntPtr.Zero;
            this._expectedSha256 = expectedSha256;
            this._isClosed = false;
            this._ioInProgress = false;
            this._acquisitionValidated = false;
            this._mutationUncertain = false;
        }

        public SourceFileIdentity Identity
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

        public bool IsMutationUncertain
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._mutationUncertain;
                }
            }
        }

        private static void ValidateCommonMetadata(string absolutePath, long expectedSize, string expectedSha256)
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

            // Path syntax and ancestor reparse validation
            ValidateDosPath(absolutePath);
        }

        /// <summary>
        /// Acquires an exclusive file lease (share 0) on the target path, validating
        /// path structure, reparse safety, file identity, expected size, and expected SHA-256 digest.
        /// </summary>
        public static SourceFileLease Acquire(string absolutePath, long expectedSize, string expectedSha256)
        {
            ValidateCommonMetadata(absolutePath, expectedSize, expectedSha256);

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
        /// Creates a new staging file exclusively (share 0, CREATE_NEW) and streams verified bytes
        /// strictly through the owned Win32 handle. Never opens or overwrites an existing file.
        /// Destination must not exist.
        ///
        /// Caller owns the input stream on both success and failure; this method never seeks,
        /// reads Length, mutates Position, or disposes the stream, and trusts finite Read completion
        /// (does not enforce stream timeout). On failure, StagePath and Identity on the retained
        /// lease represent last-known state and are not guaranteed to be current.
        ///
        /// Architecture & Trust Notice:
        /// Caller MUST authenticate target expected size and SHA-256 from signed TARGET inventory,
        /// authorize a fresh nonce-staging namespace, control ancestor directories, and enforce
        /// process/launch interlocks and journal state. This method does NOT verify signatures,
        /// record journals, or guarantee absolute power-loss or TOCTOU durability.
        /// </summary>
        public static SourceFileLease CreateNewVerified(string absolutePath, long expectedSize, string expectedSha256, Stream input)
        {
            ValidateCommonMetadata(absolutePath, expectedSize, expectedSha256);

            if (input == null)
            {
                throw new ArgumentNullException("input");
            }
            if (!input.CanRead)
            {
                throw new ArgumentException("Input stream must be readable (CanRead must be true).", "input");
            }

            // Construct lease owner object BEFORE CreateFileW to minimize unowned handle allocation window
            SourceFileLease lease = new SourceFileLease(expectedSha256);

            // Open new handle exclusively (share 0) with CREATE_NEW and FILE_FLAG_OPEN_REPARSE_POINT
            IntPtr handle = NativeMethods.CreateFileW(
                absolutePath,
                NativeMethods.EXCLUSIVE_ACCESS,
                NativeMethods.FILE_SHARE_NONE,
                IntPtr.Zero,
                NativeMethods.CREATE_NEW,
                NativeMethods.FILE_FLAG_OPEN_REPARSE_POINT,
                IntPtr.Zero
            );

            if (handle == NativeMethods.INVALID_HANDLE_VALUE || handle == IntPtr.Zero)
            {
                int err = Marshal.GetLastWin32Error();
                throw new IOException(string.Format("Failed to create new staging file with exclusive lease: Win32 error {0}.", err), new Win32Exception(err));
            }

            // Assign returned handle immediately to the owner lease before any post-create assertion
            lease._handle = handle;

            // Factory phase: keep _ioInProgress true, _acquisitionValidated false until ALL proof
            lease._ioInProgress = true;
            try
            {
                // Verify initial empty state before writing: regular disk file, size 0, links 1, non-reparse
                lease.AssertFileInformation(0);

                // Retrieve and verify canonical DOS path matches requested
                string canonicalPath = GetCanonicalDosPath(handle);
                string expectedCanonical = Path.GetFullPath(absolutePath);
                if (!string.Equals(canonicalPath, expectedCanonical, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException(string.Format("Canonical path mismatch: expected '{0}', got '{1}'.", expectedCanonical, canonicalPath));
                }

                // Query initial handle file info to snapshot volume serial number and file ID
                NativeMethods.BY_HANDLE_FILE_INFORMATION fi;
                if (!NativeMethods.GetFileInformationByHandle(handle, out fi))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("Failed to query file information by handle: Win32 error {0}.", err), new Win32Exception(err));
                }

                // Set initial identity with size 0; future asserts bind this volume and file index
                lease._identity = new SourceFileIdentity(
                    fi.dwVolumeSerialNumber,
                    ((ulong)fi.nFileIndexHigh << 32) | (ulong)fi.nFileIndexLow,
                    0,
                    canonicalPath
                );

                // Copy exact expectedSize bytes from input stream using bounded 64 KiB buffers while computing SHA-256
                const int bufferSize = 65536; // 64 KiB
                byte[] readBuffer = new byte[bufferSize];
                IntPtr unmanagedBuf = Marshal.AllocHGlobal(bufferSize);
                try
                {
                    long totalBytesWritten = 0;
                    SHA256 copySha = null;
                    Exception primaryCopyEx = null;
                    Exception hashDisposeEx = null;
                    try
                    {
                        copySha = SHA256.Create();
                        while (totalBytesWritten < expectedSize)
                        {
                            int toRead = (int)Math.Min((long)bufferSize, expectedSize - totalBytesWritten);
                            int bytesRead = input.Read(readBuffer, 0, toRead);
                            if (bytesRead < 0 || bytesRead > toRead)
                            {
                                throw new InvalidOperationException(string.Format("Input stream Read returned invalid byte count {0} (requested {1}).", bytesRead, toRead));
                            }
                            if (bytesRead == 0)
                            {
                                throw new EndOfStreamException(string.Format("Unexpected EOF after reading {0} of {1} expected bytes from input stream.", totalBytesWritten, expectedSize));
                            }

                            copySha.TransformBlock(readBuffer, 0, bytesRead, null, 0);

                            Marshal.Copy(readBuffer, 0, unmanagedBuf, bytesRead);

                            int chunkWritten = 0;
                            while (chunkWritten < bytesRead)
                            {
                                IntPtr writePtr = new IntPtr(unmanagedBuf.ToInt64() + chunkWritten);
                                uint toWrite = (uint)(bytesRead - chunkWritten);
                                uint written;
                                if (!NativeMethods.WriteFile(handle, writePtr, toWrite, out written, IntPtr.Zero))
                                {
                                    int err = Marshal.GetLastWin32Error();
                                    throw new IOException(string.Format("WriteFile failed: Win32 error {0}.", err), new Win32Exception(err));
                                }
                                if (written == 0 || written > toWrite)
                                {
                                    throw new IOException(string.Format("WriteFile returned success but wrote invalid byte count {0} (requested {1}).", written, toWrite));
                                }
                                chunkWritten += (int)written;
                            }

                            totalBytesWritten += bytesRead;
                        }

                        // Probe for extra trailing bytes: exactly ONE Read(..., 1) MUST return 0
                        byte[] probeTrailing = new byte[1];
                        int trailingCount = input.Read(probeTrailing, 0, 1);
                        if (trailingCount < 0 || trailingCount > 1)
                        {
                            throw new InvalidOperationException(string.Format("Input stream Read returned invalid trailing byte count {0} (expected 0).", trailingCount));
                        }
                        if (trailingCount != 0)
                        {
                            throw new InvalidOperationException(string.Format("Input stream contains extra trailing bytes beyond expected size {0}.", expectedSize));
                        }

                        copySha.TransformFinalBlock(new byte[0], 0, 0);
                        byte[] hashBytes = copySha.Hash;
                        StringBuilder sb = new StringBuilder(hashBytes.Length * 2);
                        for (int i = 0; i < hashBytes.Length; i++)
                        {
                            sb.Append(hashBytes[i].ToString("x2"));
                        }
                        string copyComputedSha = sb.ToString();

                        if (totalBytesWritten != expectedSize)
                        {
                            throw new InvalidOperationException(string.Format("Written byte count mismatch: expected {0}, got {1}.", expectedSize, totalBytesWritten));
                        }
                        if (!string.Equals(copyComputedSha, expectedSha256, StringComparison.Ordinal))
                        {
                            throw new InvalidOperationException(string.Format("Staged stream SHA-256 digest mismatch: expected '{0}', computed '{1}'.", expectedSha256, copyComputedSha));
                        }
                    }
                    catch (Exception ex)
                    {
                        primaryCopyEx = ex;
                        throw;
                    }
                    finally
                    {
                        if (copySha != null)
                        {
                            try
                            {
                                copySha.Dispose();
                            }
                            catch (Exception dEx)
                            {
                                hashDisposeEx = dEx;
                            }
                        }

                        if (primaryCopyEx != null && hashDisposeEx != null)
                        {
                            throw new AggregateException("Copy operation failed and SHA-256 disposal also failed.", primaryCopyEx, hashDisposeEx);
                        }
                        if (primaryCopyEx == null && hashDisposeEx != null)
                        {
                            throw hashDisposeEx;
                        }
                    }
                }
                finally
                {
                    Marshal.FreeHGlobal(unmanagedBuf);
                }

                // Checked FlushFileBuffers through owned handle
                if (!NativeMethods.FlushFileBuffers(handle))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new IOException(string.Format("FlushFileBuffers failed: Win32 error {0}.", err), new Win32Exception(err));
                }

                // Verify handle identity, disk type, size, links, and canonical path after writes and flush
                lease.AssertFileInformation(expectedSize);
                string postWriteCanonical = GetCanonicalDosPath(handle);
                if (!string.Equals(postWriteCanonical, expectedCanonical, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException(string.Format("Post-write canonical path mismatch: expected '{0}', got '{1}'.", expectedCanonical, postWriteCanonical));
                }

                // Update immutable identity to actual proven expected size and canonical path for handle re-read
                lease._identity = new SourceFileIdentity(
                    lease._identity.VolumeSerialNumber,
                    lease._identity.FileIndex,
                    expectedSize,
                    postWriteCanonical
                );

                // Full independent re-read and hash through the SAME owned handle
                string recomputedSha = lease.ComputeSha256Internal();
                if (!string.Equals(recomputedSha, expectedSha256, StringComparison.Ordinal))
                {
                    throw new InvalidOperationException(string.Format("Re-read SHA-256 digest mismatch: expected '{0}', computed '{1}'.", expectedSha256, recomputedSha));
                }

                // Assert metadata and canonical path again after hash
                lease.AssertFileInformation(expectedSize);
                string postHashCanonical = GetCanonicalDosPath(handle);
                if (!string.Equals(postHashCanonical, expectedCanonical, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException(string.Format("Post-hash canonical path mismatch: expected '{0}', got '{1}'.", expectedCanonical, postHashCanonical));
                }

                // All proofs succeeded: mark lease validated and mutation certain
                lease._acquisitionValidated = true;
                lease._mutationUncertain = false;
                return lease;
            }
            catch (Exception ex)
            {
                lease._acquisitionValidated = false;
                lease._mutationUncertain = true;
                throw new SourceFileLeaseCreationException(
                    string.Format("Staging file creation or verification failed for '{0}': {1}", absolutePath, ex.Message),
                    ex,
                    lease,
                    absolutePath
                );
            }
            finally
            {
                lease._ioInProgress = false;
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
                if (this._mutationUncertain)
                {
                    throw new InvalidOperationException("Source file lease is in an uncertain mutation state; only closure is permitted.");
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
                if (this._mutationUncertain)
                {
                    throw new InvalidOperationException("Source file lease is in an uncertain mutation state; only closure is permitted.");
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
                if (this._mutationUncertain)
                {
                    throw new InvalidOperationException("Source file lease is in an uncertain mutation state; only closure is permitted.");
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
        /// Renames the leased file atomically to a new leaf name within the SAME existing parent directory,
        /// operating strictly through the owned Win32 handle without reopening or releasing the lease.
        /// Destination must not exist; non-replacing collision semantics are enforced.
        ///
        /// Architecture & Trust Notice:
        /// Caller MUST authenticate SOURCE expected SHA/size, authorize root and old/new names,
        /// and enforce process quiescence and storage proof. This method does NO signature verification,
        /// journaling, DB state updates, or absolute ancestor TOCTOU guarantees.
        /// </summary>
        public SourceFileIdentity RenameWithinDirectory(string destinationLeafName)
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    throw new ObjectDisposedException("SourceFileLease");
                }
                if (this._mutationUncertain)
                {
                    throw new InvalidOperationException("Source file lease is in an uncertain mutation state; only closure is permitted.");
                }
                if (this._ioInProgress)
                {
                    throw new InvalidOperationException("Reentrant or concurrent I/O detected.");
                }
                if (!this._acquisitionValidated)
                {
                    throw new InvalidOperationException("Source file lease is not validated; only closure is permitted.");
                }

                // Strict destination leaf name validation
                ValidateDestinationLeafName(destinationLeafName);

                string originalCanonicalPath = this._identity.CanonicalPath;
                string parentDir = Path.GetDirectoryName(originalCanonicalPath);
                if (string.IsNullOrEmpty(parentDir))
                {
                    throw new InvalidOperationException("Unable to determine directory for current canonical path: " + originalCanonicalPath);
                }

                string destCanonicalPath = Path.Combine(parentDir, destinationLeafName);

                // Enforce same existing parent directory
                string destParentDir = Path.GetDirectoryName(destCanonicalPath);
                if (!string.Equals(parentDir, destParentDir, StringComparison.OrdinalIgnoreCase))
                {
                    throw new ArgumentException("Destination leaf name must remain within the same parent directory.", "destinationLeafName");
                }

                // Reject identical or case-only alias BEFORE native call
                string sourceLeaf = Path.GetFileName(originalCanonicalPath);
                if (string.Equals(sourceLeaf, destinationLeafName, StringComparison.OrdinalIgnoreCase))
                {
                    throw new ArgumentException("Destination leaf name cannot be identical or a case-only alias of the source file name.", "destinationLeafName");
                }

                // DOS path and ancestor validation on destination
                ValidateDosPath(destCanonicalPath);

                // Strict UTF-16 encoding validation (throw on unpaired surrogates)
                UnicodeEncoding strictEncoding = new UnicodeEncoding(false, false, true);
                byte[] pathBytes;
                try
                {
                    pathBytes = strictEncoding.GetBytes(destCanonicalPath);
                }
                catch (EncoderFallbackException ex)
                {
                    throw new ArgumentException("Destination canonical path contains invalid UTF-16 sequences: " + ex.Message, "destinationLeafName", ex);
                }

                if (destCanonicalPath.Length > 32767 || pathBytes.Length > 65534)
                {
                    throw new ArgumentException("Destination canonical path exceeds maximum supported path length.", "destinationLeafName");
                }

                this._ioInProgress = true;
                try
                {
                    // Preflight verification through OWN handle
                    string preflightPath = null;
                    try
                    {
                        this.AssertFileInformation(this._identity.Size);
                        preflightPath = GetCanonicalDosPath(this._handle);
                        if (!string.Equals(preflightPath, originalCanonicalPath, StringComparison.OrdinalIgnoreCase))
                        {
                            throw new InvalidOperationException(string.Format("Preflight canonical path mismatch: expected '{0}', got '{1}'.", originalCanonicalPath, preflightPath));
                        }
                        string preflightSha256 = this.ComputeSha256Internal();
                        if (!string.Equals(preflightSha256, this._expectedSha256, StringComparison.Ordinal))
                        {
                            throw new InvalidOperationException(string.Format("Preflight SHA-256 digest mismatch: expected '{0}', computed '{1}'.", this._expectedSha256, preflightSha256));
                        }
                        this.AssertFileInformation(this._identity.Size);
                        string preflightPathEnd = GetCanonicalDosPath(this._handle);
                        if (!string.Equals(preflightPathEnd, originalCanonicalPath, StringComparison.OrdinalIgnoreCase))
                        {
                            throw new InvalidOperationException(string.Format("Preflight canonical path changed during hash: expected '{0}', got '{1}'.", originalCanonicalPath, preflightPathEnd));
                        }
                    }
                    catch (Exception ex)
                    {
                        this._mutationUncertain = true;
                        this._acquisitionValidated = false;
                        string observedPreflight = null;
                        try { observedPreflight = GetCanonicalDosPath(this._handle); } catch { }
                        throw new SourceFileLeaseRenameException(
                            string.Format("Preflight verification of source file failed through owned handle: {0}", ex.Message),
                            ex,
                            this,
                            0,
                            false,
                            observedPreflight
                        );
                    }

                    // Construct FILE_RENAME_INFO buffer (x64)
                    const int headerSize = 20;
                    int nameByteLength = pathBytes.Length;
                    const int nulTerminatorSize = 2;
                    int allocSize = headerSize + nameByteLength + nulTerminatorSize;
                    if (allocSize != 20 + pathBytes.Length + 2 || nameByteLength != destCanonicalPath.Length * 2)
                    {
                        throw new InvalidOperationException("FILE_RENAME_INFO buffer size calculation mismatch.");
                    }
                    if (allocSize > 65558)
                    {
                        throw new InvalidOperationException("FILE_RENAME_INFO buffer size exceeds 32k character limit.");
                    }

                    IntPtr buffer = Marshal.AllocHGlobal(allocSize);
                    bool nativeSuccess = false;
                    int nativeError = 0;
                    try
                    {
                        for (int i = 0; i < allocSize; i++) Marshal.WriteByte(buffer, i, 0);
                        // ReplaceIfExists = FALSE (0) at offset 0
                        Marshal.WriteInt32(buffer, 0, 0);
                        // RootDirectory = IntPtr.Zero at offset 8 (x64)
                        Marshal.WriteIntPtr(buffer, 8, IntPtr.Zero);
                        // FileNameLength (DWORD) at offset 16 (UTF-16 byte count excluding NUL)
                        Marshal.WriteInt32(buffer, 16, nameByteLength);
                        // FileName (WCHAR[]) at offset 20
                        Marshal.Copy(pathBytes, 0, new IntPtr(buffer.ToInt64() + 20), pathBytes.Length);
                        // Explicit two zero bytes trailer
                        Marshal.WriteInt16(buffer, 20 + nameByteLength, (short)0);

                        // Verification of buffer layout and trailer
                        int readLength = Marshal.ReadInt32(buffer, 16);
                        if (readLength != nameByteLength)
                        {
                            throw new InvalidOperationException(string.Format("FILE_RENAME_INFO length mismatch: expected {0}, got {1}.", nameByteLength, readLength));
                        }
                        short trailer = Marshal.ReadInt16(buffer, 20 + nameByteLength);
                        if (trailer != 0)
                        {
                            throw new InvalidOperationException("FILE_RENAME_INFO UTF-16 null trailer is non-zero.");
                        }
                        string roundtrip = Marshal.PtrToStringUni(new IntPtr(buffer.ToInt64() + 20), nameByteLength / 2);
                        string nullTerminated = Marshal.PtrToStringUni(new IntPtr(buffer.ToInt64() + 20));
                        if (!string.Equals(roundtrip, destCanonicalPath, StringComparison.Ordinal) ||
                            roundtrip.Length != destCanonicalPath.Length ||
                            !string.Equals(nullTerminated, destCanonicalPath, StringComparison.Ordinal))
                        {
                            throw new InvalidOperationException("FILE_RENAME_INFO embedded UTF-16 roundtrip mismatch; silent trimming detected.");
                        }

                        // Mark mutation uncertain IMMEDIATELY before mutating native call
                        this._mutationUncertain = true;

                        // Mutating native call: exactly ONE call per attempt
                        nativeSuccess = NativeMethods.SetFileInformationByHandle(this._handle, NativeMethods.FileRenameInfo, buffer, (uint)allocSize);
                        if (!nativeSuccess)
                        {
                            nativeError = Marshal.GetLastWin32Error();
                        }
                    }
                    finally
                    {
                        Marshal.FreeHGlobal(buffer);
                    }

                    if (!nativeSuccess)
                    {
                        // Native call failed. Prove original file state through OWN handle before clearing uncertainty.
                        bool reversionVerified = false;
                        Exception reversionException = null;
                        try
                        {
                            string currentPath = GetCanonicalDosPath(this._handle);
                            if (!string.Equals(currentPath, originalCanonicalPath, StringComparison.OrdinalIgnoreCase))
                            {
                                throw new InvalidOperationException(string.Format("Reversion proof failed: canonical path changed from '{0}' to '{1}'.", originalCanonicalPath, currentPath));
                            }
                            this.AssertFileInformation(this._identity.Size);

                            string currentSha256 = this.ComputeSha256Internal();
                            if (!string.Equals(currentSha256, this._expectedSha256, StringComparison.Ordinal))
                            {
                                throw new InvalidOperationException(string.Format("Reversion proof failed: SHA-256 changed from '{0}' to '{1}'.", this._expectedSha256, currentSha256));
                            }

                            this.AssertFileInformation(this._identity.Size);
                            string currentPathAfter = GetCanonicalDosPath(this._handle);
                            if (!string.Equals(currentPathAfter, originalCanonicalPath, StringComparison.OrdinalIgnoreCase))
                            {
                                throw new InvalidOperationException(string.Format("Reversion proof failed: canonical path changed after hash from '{0}' to '{1}'.", originalCanonicalPath, currentPathAfter));
                            }

                            reversionVerified = true;
                        }
                        catch (Exception ex)
                        {
                            reversionException = ex;
                        }

                        if (reversionVerified)
                        {
                            this._mutationUncertain = false;
                            throw new IOException(
                                string.Format("Native rename failed with Win32 error {0}; source file verified unchanged at '{1}'.", nativeError, originalCanonicalPath),
                                new Win32Exception(nativeError)
                            );
                        }
                        else
                        {
                            string observed = null;
                            try { observed = GetCanonicalDosPath(this._handle); } catch { }
                            throw new SourceFileLeaseRenameException(
                                string.Format("Native rename failed with Win32 error {0}, and post-failure verification of original file state also failed: {1}", nativeError, reversionException != null ? reversionException.Message : "unknown"),
                                reversionException,
                                this,
                                nativeError,
                                true,
                                observed
                            );
                        }
                    }
                    else
                    {
                        // Native call reported TRUE. Verify destination state through OWN handle.
                        Exception postVerifyException = null;
                        try
                        {
                            string postPath = GetCanonicalDosPath(this._handle);
                            if (!string.Equals(postPath, destCanonicalPath, StringComparison.OrdinalIgnoreCase))
                            {
                                throw new InvalidOperationException(string.Format("Post-rename canonical path mismatch: expected '{0}', got '{1}'.", destCanonicalPath, postPath));
                            }
                            this.AssertFileInformation(this._identity.Size);
                            string postSha256 = this.ComputeSha256Internal();
                            if (!string.Equals(postSha256, this._expectedSha256, StringComparison.Ordinal))
                            {
                                throw new InvalidOperationException(string.Format("Post-rename SHA-256 digest mismatch: expected '{0}', computed '{1}'.", this._expectedSha256, postSha256));
                            }
                            this.AssertFileInformation(this._identity.Size);
                            string postPath2 = GetCanonicalDosPath(this._handle);
                            if (!string.Equals(postPath2, destCanonicalPath, StringComparison.OrdinalIgnoreCase))
                            {
                                throw new InvalidOperationException(string.Format("Post-rename canonical path not stable: first '{0}', second '{1}'.", postPath, postPath2));
                            }

                            this._identity = new SourceFileIdentity(
                                this._identity.VolumeSerialNumber,
                                this._identity.FileIndex,
                                this._identity.Size,
                                postPath2
                            );
                            this._mutationUncertain = false;
                            return this._identity;
                        }
                        catch (Exception ex)
                        {
                            postVerifyException = ex;
                        }

                        string observedAfterTrue = null;
                        try { observedAfterTrue = GetCanonicalDosPath(this._handle); } catch { }
                        throw new SourceFileLeaseRenameException(
                            string.Format("Native rename reported success, but post-rename verification failed: {0}", postVerifyException != null ? postVerifyException.Message : "unknown"),
                            postVerifyException,
                            this,
                            0,
                            true,
                            observedAfterTrue
                        );
                    }
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
            bool priorIoInProgress = this._ioInProgress;
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
                this._ioInProgress = priorIoInProgress;
            }
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

        private static void ValidateDestinationLeafName(string leaf)
        {
            if (leaf == null)
            {
                throw new ArgumentNullException("destinationLeafName");
            }
            if (leaf.Length == 0 || leaf.Length > 255)
            {
                throw new ArgumentException("Destination leaf name length must be between 1 and 255 characters.", "destinationLeafName");
            }
            if (string.IsNullOrWhiteSpace(leaf))
            {
                throw new ArgumentException("Destination leaf name must not be empty or whitespace only.", "destinationLeafName");
            }
            if (leaf == "." || leaf == "..")
            {
                throw new ArgumentException("Destination leaf name must not be '.' or '..'.", "destinationLeafName");
            }
            if (leaf.EndsWith(".") || leaf.EndsWith(" "))
            {
                throw new ArgumentException("Destination leaf name must not end with a trailing dot or space.", "destinationLeafName");
            }
            if (leaf.IndexOf('~') != -1)
            {
                throw new ArgumentException("Destination leaf name must not contain 8.3 short-name aliases (~).", "destinationLeafName");
            }

            for (int i = 0; i < leaf.Length; i++)
            {
                char c = leaf[i];
                if (c < 0x20 || c == 0x7F)
                {
                    throw new ArgumentException(string.Format("Destination leaf name contains control character 0x{0:X2}.", (int)c), "destinationLeafName");
                }
                if (c == '/' || c == '\\' || c == ':' || c == '*' || c == '?' || c == '"' || c == '<' || c == '>' || c == '|')
                {
                    throw new ArgumentException(string.Format("Destination leaf name contains forbidden character '{0}'.", c), "destinationLeafName");
                }
                if (char.IsSurrogate(c))
                {
                    if (char.IsHighSurrogate(c))
                    {
                        if (i + 1 < leaf.Length && char.IsLowSurrogate(leaf[i + 1]))
                        {
                            i++;
                            continue;
                        }
                        throw new ArgumentException("Destination leaf name contains an unpaired high surrogate.", "destinationLeafName");
                    }
                    else
                    {
                        throw new ArgumentException("Destination leaf name contains an unpaired low surrogate.", "destinationLeafName");
                    }
                }
            }

            UnicodeEncoding strictEncoding = new UnicodeEncoding(false, false, true);
            byte[] testBytes;
            try
            {
                testBytes = strictEncoding.GetBytes(leaf);
            }
            catch (EncoderFallbackException ex)
            {
                throw new ArgumentException("Destination leaf name is not valid UTF-16: " + ex.Message, "destinationLeafName", ex);
            }
            if (testBytes.Length != leaf.Length * 2)
            {
                throw new ArgumentException("Destination leaf name byte length mismatch in UTF-16.", "destinationLeafName");
            }

            string nameWithoutExt = leaf;
            int dotIdx = leaf.IndexOf('.');
            if (dotIdx >= 0)
            {
                nameWithoutExt = leaf.Substring(0, dotIdx);
            }
            if (IsReservedDosDeviceName(leaf) || IsReservedDosDeviceName(nameWithoutExt))
            {
                throw new ArgumentException(string.Format("Destination leaf name '{0}' uses a reserved DOS device name.", leaf), "destinationLeafName");
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
            internal const uint CREATE_NEW = 1;
            internal const uint OPEN_EXISTING = 3;
            internal const uint FILE_FLAG_OPEN_REPARSE_POINT = 0x00200000; // 2097152
            internal const uint FILE_TYPE_DISK = 0x0001;
            internal const uint FILE_ATTRIBUTE_DIRECTORY = 0x00000010;
            internal const uint FILE_ATTRIBUTE_REPARSE_POINT = 0x00000400;
            internal const uint FILE_BEGIN = 0;
            internal const uint VOLUME_NAME_DOS = 0x0;
            internal const int FileRenameInfo = 3;

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

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool WriteFile(
                IntPtr hFile,
                IntPtr lpBuffer,
                uint nNumberOfBytesToWrite,
                out uint lpNumberOfBytesWritten,
                IntPtr lpOverlapped
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool FlushFileBuffers(IntPtr hFile);

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool SetFileInformationByHandle(
                IntPtr hFile,
                int FileInformationClass,
                IntPtr lpFileInformation,
                uint dwBufferSize
            );
        }
    }
}
