// backend/Native/OwnedWorkerPipeEndpoint.cs
//
// INTERNAL Win32 LOCAL-ONLY owned worker named pipe connection primitive.
//
// Architecture & Trust Notice:
// 1. Process identity is NOT code, image, or payload authentication.
//    Verification of kernel peer PID proves only that the connected pipe client is the
//    identical OS process created by the caller via OwnedChildProcess. It does NOT prove
//    that the process has not loaded untrusted modules, that memory has not been tampered
//    with by administrative or same-user tools, or that application code is benign.
// 2. Caller compiled host MUST authenticate PHP/PHAR/snapshot/native gates before launch.
//    All executable binaries, PHAR packages, CLI scripts, and configuration must be
//    independently validated and signed before initiating child process execution.
// 3. Connection proof is last-known bounded call-time identity, NOT an ongoing permit
//    and NOT an anti-replay token. Subsequent frame operations must re-verify root/peer state.
// 4. This primitive provides NO database quiescence proof, NO apply proof, NO schema proof,
//    and NO file transaction proof. CanApply is unconditionally false.
// 5. No frame transport, payload read/write, plan file I/O, PHP invocation, snapshot/image
//    signature verification, or bootstrap is performed by this class.
// 6. Security boundaries:
//    - lpSecurityAttributes is NULL (default creator DACL). This does NOT isolate against
//      arbitrary attackers running with the same user privileges or elevated administrator tokens.
//    - Pipe basename is generated inside the factory using a random GUID. Basename uniqueness
//      prevents collisions; it is NOT a shared secret, capability nonce, or receipt.
//    - PIPE_REJECT_REMOTE_CLIENTS (0x8) is MANDATORY to enforce local-only access. Remote PIDs
//      or machine hostnames cannot be compared against local PIDs and are never used as fallbacks.
//    - FILE_FLAG_FIRST_PIPE_INSTANCE (0x00080000) fails closed if the pipe name already exists.
//      No fallback or retry to pre-existing pipe namespaces is permitted.
// 7. Resource lifecycle & kernel rundown:
//    - The endpoint owns native handles (_hPipe, _hEvent) and an unmanaged 32-byte OVERLAPPED
//      buffer allocated on the process heap via HeapAlloc(HEAP_ZERO_MEMORY).
//    - Incomplete asynchronous I/O must be cancelled via CancelIoEx and proven completed via
//      GetOverlappedResult (or bounded rundown wait) BEFORE freeing buffers or closing handles.
//    - Prematurely freeing native memory while I/O is pending causes kernel memory corruption;
//      if rundown cannot be proven, resources are retained in FaultedCloseOnly state.
//    - Dropping a retained endpoint leaks rather than prematurely freeing; callers MUST retain,
//      recover, and retry close.
// 8. Verification limits & fault injection boundaries:
//    - Network rejection flag PIPE_REJECT_REMOTE_CLIENTS is asserted structurally and verified via
//      local Win32 pipe semantics; multi-machine remote network E2E rejection is not tested in local harness.
//    - Defensive kernel fault branches (such as CancelIoEx failing with non-advisory errors,
//      GetOverlappedResult returning unrecoverable errors, or HeapFree returning false) are guarded by
//      transitioning to FaultedCloseOnly, recording cleanup diagnostics, and retaining the endpoint;
//      these specific fault branches are NOT runtime exercised in the test suite because
//      synthetic kernel driver fault injection has not been implemented. We state this boundary honestly.

namespace PosDesktop.Updates.Native
{
    using System;
    using System.Collections.Generic;
    using System.ComponentModel;
    using System.Diagnostics;
    using System.Runtime.InteropServices;
    using System.Threading;

    /// <summary>
    /// Lifecycle state of the owned worker named pipe endpoint.
    /// </summary>
    public enum PipeEndpointState
    {
        Created = 0,
        Connecting = 1,
        Bound = 2,
        FaultedCloseOnly = 3,
        Closed = 4
    }

    /// <summary>
    /// Immutable call-time observation snapshot of an owned worker pipe peer.
    /// Represents bounded last-known identity only; not an ongoing permit or anti-replay token.
    /// </summary>
    public sealed class OwnedWorkerPeerObservation
    {
        private readonly int _processId;
        private readonly long _creationFileTimeTicks;
        private readonly DateTime _observedAtUtc;

        internal OwnedWorkerPeerObservation(int processId, long creationFileTimeTicks, DateTime observedAtUtc)
        {
            this._processId = processId;
            this._creationFileTimeTicks = creationFileTimeTicks;
            this._observedAtUtc = observedAtUtc;
        }

        public int ProcessId { get { return this._processId; } }
        public long CreationFileTimeTicks { get { return this._creationFileTimeTicks; } }
        public bool CanApply { get { return false; } }
        public DateTime ObservedAtUtc { get { return this._observedAtUtc; } }
    }

    /// <summary>
    /// Base exception thrown when an owned worker pipe endpoint operation fails and owned native
    /// resources require retention or cannot be proven completely cleaned up.
    /// </summary>
    public class OwnedWorkerPipeEndpointException : Exception
    {
        private readonly OwnedWorkerPipeEndpoint _retainedEndpoint;
        private readonly int _nativeError;

        public OwnedWorkerPipeEndpointException(string message, Exception innerException, OwnedWorkerPipeEndpoint retainedEndpoint, int nativeError)
            : base(message, innerException)
        {
            this._retainedEndpoint = retainedEndpoint;
            this._nativeError = nativeError;
        }

        public OwnedWorkerPipeEndpoint RetainedEndpoint { get { return this._retainedEndpoint; } }
        public int NativeError { get { return this._nativeError; } }
    }

    /// <summary>
    /// Exception thrown when IDisposable.Dispose or TryClose fails to prove closure of all owned native handles.
    /// Retained endpoint is preserved on the exception for caller recovery.
    /// </summary>
    public sealed class OwnedWorkerPipeEndpointCloseException : OwnedWorkerPipeEndpointException
    {
        public OwnedWorkerPipeEndpointCloseException(string message, Exception innerException, OwnedWorkerPipeEndpoint retainedEndpoint, int nativeError)
            : base(message, innerException, retainedEndpoint, nativeError)
        {
        }
    }

    /// <summary>
    /// Manages the lifecycle of a single-instance, local-only Win32 named pipe server endpoint
    /// and verifies that the connected client process matches an owned root child process.
    /// </summary>
    public sealed class OwnedWorkerPipeEndpoint : IDisposable
    {
        private enum OverlappedCompletionStatus
        {
            Pending = 0,
            CompletedSuccess = 1,
            Aborted = 2,
            UncertainError = 3
        }

        private readonly object _syncLock;
        private readonly string _endpointName;

        private IntPtr _hPipe;
        private IntPtr _hEvent;
        private IntPtr _pOverlapped;
        private IntPtr _hHeap;

        private bool _connectPending;
        private bool _isBusy;
        private PipeEndpointState _state;
        private OwnedChildProcess _boundWorker;

        private List<Exception> _cleanupFailures;
        private AggregateException _lastCleanupFailure;

        private OwnedWorkerPipeEndpoint(string endpointName)
        {
            this._syncLock = new object();
            this._endpointName = endpointName;
            this._hPipe = IntPtr.Zero;
            this._hEvent = IntPtr.Zero;
            this._pOverlapped = IntPtr.Zero;
            this._hHeap = IntPtr.Zero;
            this._connectPending = false;
            this._isBusy = false;
            this._state = PipeEndpointState.Created;
            this._boundWorker = null;
            this._cleanupFailures = null;
            this._lastCleanupFailure = null;
        }

        public string EndpointName
        {
            get
            {
                return this._endpointName;
            }
        }

        public PipeEndpointState State
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._state;
                }
            }
        }

        public bool IsClosed
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._state == PipeEndpointState.Closed;
                }
            }
        }

        public bool IsBound
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._state == PipeEndpointState.Bound;
                }
            }
        }

        public OwnedChildProcess BoundWorker
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._boundWorker;
                }
            }
        }

        /// <summary>
        /// Gets the immutable snapshot of any accumulated native cleanup or cancellation failure diagnostics,
        /// or null if no cleanup failures occurred.
        /// </summary>
        public AggregateException LastCleanupFailure
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._lastCleanupFailure;
                }
            }
        }

        private void RecordCleanupFailure(Exception ex)
        {
            if (ex == null)
            {
                return;
            }
            lock (this._syncLock)
            {
                if (this._cleanupFailures == null)
                {
                    this._cleanupFailures = new List<Exception>();
                }
                this._cleanupFailures.Add(ex);
                this._lastCleanupFailure = new AggregateException(
                    "One or more native resource cleanup operations failed.",
                    this._cleanupFailures.ToArray()
                );
            }
        }

        /// <summary>
        /// Factory method that creates a single-instance, local-only named pipe server endpoint
        /// with an internal random GUID basename.
        /// </summary>
        public static OwnedWorkerPipeEndpoint Create()
        {
            if (Environment.OSVersion.Platform != PlatformID.Win32NT || IntPtr.Size != 8)
            {
                throw new PlatformNotSupportedException("OwnedWorkerPipeEndpoint requires 64-bit Windows (Win32NT x64).");
            }

            string basename = "pos-worker-" + Guid.NewGuid().ToString("N");
            OwnedWorkerPipeEndpoint endpoint = new OwnedWorkerPipeEndpoint(basename);

            IntPtr hHeap = NativeMethods.GetProcessHeap();
            if (hHeap == IntPtr.Zero)
            {
                int err = Marshal.GetLastWin32Error();
                if (err == 0) err = NativeMethods.ERROR_NOT_ENOUGH_MEMORY;
                throw new Win32Exception(err, string.Format("GetProcessHeap failed: Win32 error {0}.", err));
            }
            endpoint._hHeap = hHeap;

            string fullPipePath = @"\\.\pipe\" + basename;

            uint openMode = NativeMethods.PIPE_ACCESS_DUPLEX |
                            NativeMethods.FILE_FLAG_FIRST_PIPE_INSTANCE |
                            NativeMethods.FILE_FLAG_OVERLAPPED;

            uint pipeMode = NativeMethods.PIPE_TYPE_BYTE |
                            NativeMethods.PIPE_READMODE_BYTE |
                            NativeMethods.PIPE_WAIT |
                            NativeMethods.PIPE_REJECT_REMOTE_CLIENTS;

            uint maxInstances = 1;
            uint bufferSize = 4096;

            try
            {
                IntPtr hPipe = NativeMethods.CreateNamedPipe(
                    fullPipePath,
                    openMode,
                    pipeMode,
                    maxInstances,
                    bufferSize,
                    bufferSize,
                    0,
                    IntPtr.Zero
                );

                if (hPipe == IntPtr.Zero || hPipe == NativeMethods.INVALID_HANDLE_VALUE)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("CreateNamedPipeW failed for '{0}': Win32 error {1}.", fullPipePath, err));
                }
                endpoint._hPipe = hPipe;

                uint pipeFlags, outBuf, inBuf, maxInst;
                if (!NativeMethods.GetNamedPipeInfo(hPipe, out pipeFlags, out outBuf, out inBuf, out maxInst))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("GetNamedPipeInfo failed: Win32 error {0}.", err));
                }
                if ((pipeFlags & NativeMethods.PIPE_SERVER_END) == 0)
                {
                    throw new InvalidOperationException("CreateNamedPipe handle is not server end.");
                }

                IntPtr pOverlapped = NativeMethods.HeapAlloc(hHeap, NativeMethods.HEAP_ZERO_MEMORY, (UIntPtr)32);
                if (pOverlapped == IntPtr.Zero)
                {
                    int err = NativeMethods.ERROR_NOT_ENOUGH_MEMORY; // 8 (application fallback: HeapAlloc does not set Win32 last-error)
                    throw new Win32Exception(err, "HeapAlloc returned NULL allocating 32-byte OVERLAPPED buffer (application fallback error 8: ERROR_NOT_ENOUGH_MEMORY; Win32 last-error not set by HeapAlloc).");
                }
                endpoint._pOverlapped = pOverlapped;

                IntPtr hEvent = NativeMethods.CreateEvent(IntPtr.Zero, true, false, null);
                if (hEvent == IntPtr.Zero)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("CreateEventW failed: Win32 error {0}.", err));
                }
                endpoint._hEvent = hEvent;

                Marshal.WriteIntPtr(pOverlapped, 24, hEvent);

                return endpoint;
            }
            catch (Exception ex)
            {
                int cleanupErr = 0;
                bool cleaned = false;
                Exception cleanupEx = null;
                try
                {
                    cleaned = endpoint.TryClose(3000, out cleanupErr);
                }
                catch (Exception cEx)
                {
                    cleanupEx = cEx;
                }

                if (!cleaned || endpoint.State != PipeEndpointState.Closed || cleanupEx != null || endpoint.LastCleanupFailure != null)
                {
                    Exception wrap = ex;
                    if (cleanupEx != null || endpoint.LastCleanupFailure != null)
                    {
                        List<Exception> inners = new List<Exception>();
                        inners.Add(ex);
                        if (cleanupEx != null) inners.Add(cleanupEx);
                        if (endpoint.LastCleanupFailure != null && endpoint.LastCleanupFailure != cleanupEx)
                        {
                            inners.Add(endpoint.LastCleanupFailure);
                        }
                        wrap = new AggregateException("Endpoint creation failed and subsequent cleanup recorded failures.", inners.ToArray());
                    }
                    throw new OwnedWorkerPipeEndpointException(
                        string.Format("Pipe endpoint creation failed ({0}) and owned resource cleanup was uncertain (error {1}). Retained endpoint must be recovered and closed.", ex.Message, cleanupErr),
                        wrap,
                        endpoint,
                        cleanupErr
                    );
                }
                throw;
            }
        }

        private OverlappedCompletionStatus QueryOverlappedStatus(out int win32Error)
        {
            win32Error = 0;
            if (this._hPipe == IntPtr.Zero || this._pOverlapped == IntPtr.Zero)
            {
                win32Error = NativeMethods.ERROR_INVALID_HANDLE;
                return OverlappedCompletionStatus.UncertainError;
            }

            uint bytesTransferred;
            bool gor = NativeMethods.GetOverlappedResult(this._hPipe, this._pOverlapped, out bytesTransferred, false);
            if (gor)
            {
                return OverlappedCompletionStatus.CompletedSuccess;
            }

            int err = Marshal.GetLastWin32Error();
            win32Error = err;
            if (err == NativeMethods.ERROR_IO_INCOMPLETE) // 996
            {
                return OverlappedCompletionStatus.Pending;
            }
            if (err == NativeMethods.ERROR_OPERATION_ABORTED) // 995
            {
                return OverlappedCompletionStatus.Aborted;
            }

            // Unknown or invalid-handle errors: conservative, NOT completion proof!
            return OverlappedCompletionStatus.UncertainError;
        }

        private bool TryDrainOrCancelPendingIo(long timeoutMilliseconds, out int drainError)
        {
            drainError = 0;
            if (!this._connectPending)
            {
                return true;
            }

            if (this._hPipe == IntPtr.Zero || this._pOverlapped == IntPtr.Zero || this._hEvent == IntPtr.Zero)
            {
                drainError = NativeMethods.ERROR_INVALID_HANDLE;
                this.RecordCleanupFailure(new Win32Exception(NativeMethods.ERROR_INVALID_HANDLE, "Cannot drain pending I/O: pipe, event, or overlapped buffer handle is invalid."));
                return false;
            }

            // Step 1: Check if already completed before issuing CancelIoEx
            int queryErr;
            OverlappedCompletionStatus initialSt = this.QueryOverlappedStatus(out queryErr);
            if (initialSt == OverlappedCompletionStatus.CompletedSuccess || initialSt == OverlappedCompletionStatus.Aborted)
            {
                this._connectPending = false;
                drainError = 0;
                return true;
            }
            if (initialSt == OverlappedCompletionStatus.UncertainError)
            {
                int qErr = queryErr != 0 ? queryErr : NativeMethods.ERROR_INVALID_PARAMETER;
                this.RecordCleanupFailure(new Win32Exception(qErr, string.Format("Pre-cancel GetOverlappedResult returned uncertain error {0}.", qErr)));
            }

            // Step 2: Request cancellation via CancelIoEx
            int cancelErr = 0;
            bool cancelSuccess = NativeMethods.CancelIoEx(this._hPipe, this._pOverlapped);
            if (!cancelSuccess)
            {
                cancelErr = Marshal.GetLastWin32Error();
                // ERROR_NOT_FOUND (1168) indicates operation already finished or not found; advisory only.
                if (cancelErr != 0 && cancelErr != NativeMethods.ERROR_NOT_FOUND)
                {
                    this.RecordCleanupFailure(new Win32Exception(cancelErr, string.Format("CancelIoEx failed with non-advisory error {0}.", cancelErr)));
                }
            }

            // Step 3: Check completion immediately (nonblocking poll)
            OverlappedCompletionStatus postCancelSt = this.QueryOverlappedStatus(out queryErr);
            if (postCancelSt == OverlappedCompletionStatus.CompletedSuccess || postCancelSt == OverlappedCompletionStatus.Aborted)
            {
                this._connectPending = false;
                drainError = 0;
                return true;
            }
            if (postCancelSt == OverlappedCompletionStatus.UncertainError)
            {
                int qErr = queryErr != 0 ? queryErr : NativeMethods.ERROR_INVALID_PARAMETER;
                this.RecordCleanupFailure(new Win32Exception(qErr, string.Format("Post-cancel GetOverlappedResult returned uncertain error {0}.", qErr)));
            }

            if (timeoutMilliseconds <= 0L)
            {
                drainError = cancelErr != 0 && cancelErr != NativeMethods.ERROR_NOT_FOUND ? cancelErr : NativeMethods.ERROR_IO_INCOMPLETE;
                return false;
            }

            // Step 4: Bounded wait up to timeoutMilliseconds
            Stopwatch swRundown = Stopwatch.StartNew();
            while (swRundown.ElapsedMilliseconds < timeoutMilliseconds)
            {
                long remaining = timeoutMilliseconds - swRundown.ElapsedMilliseconds;
                if (remaining <= 0L)
                {
                    break;
                }

                uint slice = (uint)Math.Min(50L, remaining);
                uint waitRes = NativeMethods.WaitForSingleObject(this._hEvent, slice);

                if (waitRes == NativeMethods.WAIT_OBJECT_0)
                {
                    OverlappedCompletionStatus st = this.QueryOverlappedStatus(out queryErr);
                    if (st == OverlappedCompletionStatus.CompletedSuccess || st == OverlappedCompletionStatus.Aborted)
                    {
                        this._connectPending = false;
                        drainError = 0;
                        return true;
                    }
                    if (st == OverlappedCompletionStatus.UncertainError)
                    {
                        int qErr = queryErr != 0 ? queryErr : NativeMethods.ERROR_INVALID_PARAMETER;
                        this.RecordCleanupFailure(new Win32Exception(qErr, string.Format("Wait signaled GetOverlappedResult returned uncertain error {0}.", qErr)));
                        drainError = qErr;
                        return false;
                    }
                }
                else if (waitRes == NativeMethods.WAIT_TIMEOUT)
                {
                    continue;
                }
                else
                {
                    // WAIT_FAILED or unexpected wait status: fail closed with actual native error
                    int waitErr = Marshal.GetLastWin32Error();
                    if (waitErr == 0) waitErr = NativeMethods.ERROR_INVALID_HANDLE;
                    this.RecordCleanupFailure(new Win32Exception(waitErr, string.Format("WaitForSingleObject on event handle failed with error {0} (waitRes={1}).", waitErr, waitRes)));
                    drainError = waitErr;
                    return false;
                }
            }

            // Final post-loop check
            OverlappedCompletionStatus finalSt = this.QueryOverlappedStatus(out queryErr);
            if (finalSt == OverlappedCompletionStatus.CompletedSuccess || finalSt == OverlappedCompletionStatus.Aborted)
            {
                this._connectPending = false;
                drainError = 0;
                return true;
            }
            if (finalSt == OverlappedCompletionStatus.UncertainError)
            {
                int qErr = queryErr != 0 ? queryErr : NativeMethods.ERROR_INVALID_PARAMETER;
                this.RecordCleanupFailure(new Win32Exception(qErr, string.Format("Final GetOverlappedResult returned uncertain error {0}.", qErr)));
                drainError = qErr;
                return false;
            }

            drainError = cancelErr != 0 && cancelErr != NativeMethods.ERROR_NOT_FOUND ? cancelErr : NativeMethods.ERROR_IO_INCOMPLETE;
            return false;
        }

        /// <summary>
        /// Accepts a single connection on the local pipe, verifies that the connecting client's
        /// kernel PID strictly matches the expected owned root child process (not a descendant or foreign process),
        /// and returns an immutable peer observation.
        /// </summary>
        public OwnedWorkerPeerObservation ConnectOwnedWorker(OwnedChildProcess expectedWorker, int timeoutMilliseconds)
        {
            if (expectedWorker == null)
            {
                throw new ArgumentNullException("expectedWorker");
            }
            if (timeoutMilliseconds < 1 || timeoutMilliseconds > 30000)
            {
                throw new ArgumentOutOfRangeException("timeoutMilliseconds", "Timeout must be between 1 and 30000 ms.");
            }

            if (expectedWorker.IsClosed)
            {
                throw new InvalidOperationException("Expected worker is already closed.");
            }

            ChildProcessObservation preObs = expectedWorker.Observe();
            if (preObs.RootState != ChildRootState.Live || preObs.IsClosed)
            {
                throw new InvalidOperationException(
                    string.Format("Expected worker root process is not Live before connect (RootState={0}, IsClosed={1}).",
                        preObs.RootState, preObs.IsClosed)
                );
            }

            int expectedPid = expectedWorker.ProcessId;
            if (expectedPid <= 0)
            {
                throw new InvalidOperationException("Expected worker process ID is invalid.");
            }

            lock (this._syncLock)
            {
                if (this._isBusy)
                {
                    throw new InvalidOperationException("Pipe endpoint is busy with another operation.");
                }
                if (this._state != PipeEndpointState.Created)
                {
                    throw new InvalidOperationException(
                        string.Format("Cannot connect on pipe endpoint in state {0}. Only freshly Created endpoints can connect.", this._state)
                    );
                }
                if (this._hPipe == IntPtr.Zero || this._hEvent == IntPtr.Zero || this._pOverlapped == IntPtr.Zero)
                {
                    this._state = PipeEndpointState.FaultedCloseOnly;
                    throw new InvalidOperationException("Pipe endpoint handles or buffers are invalid.");
                }
                this._isBusy = true;
                this._state = PipeEndpointState.Connecting;
            }

            try
            {
                if (!NativeMethods.ResetEvent(this._hEvent))
                {
                    int resetErr = Marshal.GetLastWin32Error();
                    if (resetErr == 0) resetErr = NativeMethods.ERROR_INVALID_HANDLE;
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new Win32Exception(resetErr, string.Format("ResetEvent failed before connect: Win32 error {0}.", resetErr));
                }

                for (int i = 0; i < 32; i++)
                {
                    Marshal.WriteByte(this._pOverlapped, i, 0);
                }
                Marshal.WriteIntPtr(this._pOverlapped, 24, this._hEvent);

                bool connectedImmediately = false;

                // Pre-mark potential pending BEFORE ConnectNamedPipe call so unexpected managed
                // failure after kernel queue cannot leave _connectPending as false.
                this._connectPending = true;

                bool connectCallSuccess = NativeMethods.ConnectNamedPipe(this._hPipe, this._pOverlapped);
                int connectCallErr = Marshal.GetLastWin32Error();

                if (connectCallSuccess)
                {
                    this._connectPending = false;
                    connectedImmediately = true;
                }
                else if (connectCallErr == NativeMethods.ERROR_PIPE_CONNECTED)
                {
                    this._connectPending = false;
                    connectedImmediately = true;
                }
                else if (connectCallErr == NativeMethods.ERROR_IO_PENDING)
                {
                    // Asynchronous I/O successfully queued; _connectPending remains true
                }
                else
                {
                    this._connectPending = false;
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new Win32Exception(connectCallErr, string.Format("ConnectNamedPipe failed immediately: Win32 error {0}.", connectCallErr));
                }

                bool ioCompletedSuccessfully = connectedImmediately;
                string failureReason = null;
                int failureError = 0;

                if (!connectedImmediately)
                {
                    Stopwatch sw = Stopwatch.StartNew();

                    while (true)
                    {
                        long elapsed = sw.ElapsedMilliseconds;
                        long remaining = (long)timeoutMilliseconds - elapsed;
                        if (remaining <= 0L)
                        {
                            failureReason = string.Format("Connect timed out after {0}ms waiting for worker connection.", timeoutMilliseconds);
                            failureError = NativeMethods.ERROR_IO_INCOMPLETE;
                            break;
                        }

                        ChildProcessObservation loopObs = expectedWorker.Observe();
                        if (loopObs.RootState != ChildRootState.Live || loopObs.IsClosed)
                        {
                            failureReason = string.Format("Expected worker exited or is no longer Live during connect (RootState={0}, IsClosed={1}).",
                                loopObs.RootState, loopObs.IsClosed);
                            failureError = NativeMethods.ERROR_OPERATION_ABORTED;
                            break;
                        }

                        uint sliceMs = (uint)Math.Min(200L, Math.Max(1L, remaining));
                        uint waitRes = NativeMethods.WaitForSingleObject(this._hEvent, sliceMs);

                        if (waitRes == NativeMethods.WAIT_OBJECT_0)
                        {
                            int qErr;
                            OverlappedCompletionStatus st = this.QueryOverlappedStatus(out qErr);
                            if (st == OverlappedCompletionStatus.CompletedSuccess)
                            {
                                ioCompletedSuccessfully = true;
                                this._connectPending = false;
                                break;
                            }
                            else if (st == OverlappedCompletionStatus.Pending)
                            {
                                continue;
                            }
                            else
                            {
                                // UncertainError or Aborted: NOT success! Do NOT clear _connectPending!
                                failureReason = string.Format("GetOverlappedResult failed after event signal: Win32 error {0}.", qErr);
                                failureError = qErr != 0 ? qErr : NativeMethods.ERROR_IO_INCOMPLETE;
                                break;
                            }
                        }
                        else if (waitRes == NativeMethods.WAIT_TIMEOUT)
                        {
                            continue;
                        }
                        else
                        {
                            int waitErr = Marshal.GetLastWin32Error();
                            failureReason = string.Format("WaitForSingleObject on connect event failed (waitRes={0}): Win32 error {1}.", waitRes, waitErr);
                            failureError = waitErr != 0 ? waitErr : NativeMethods.ERROR_INVALID_HANDLE;
                            break;
                        }
                    }
                }

                if (!ioCompletedSuccessfully)
                {
                    if (failureReason != null && failureReason.IndexOf("timed out", StringComparison.OrdinalIgnoreCase) != -1)
                    {
                        throw new TimeoutException(failureReason);
                    }
                    throw new InvalidOperationException(failureReason ?? "Pipe connect failed.");
                }

                // Verify kernel client PID with flanking live checks
                ChildProcessObservation flankBefore = expectedWorker.Observe();
                if (flankBefore.RootState != ChildRootState.Live || flankBefore.IsClosed)
                {
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new InvalidOperationException(
                        string.Format("Expected worker was not Live immediately before kernel client PID query (RootState={0}, IsClosed={1}).",
                            flankBefore.RootState, flankBefore.IsClosed)
                    );
                }

                uint clientPid;
                if (!NativeMethods.GetNamedPipeClientProcessId(this._hPipe, out clientPid))
                {
                    int err = Marshal.GetLastWin32Error();
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new Win32Exception(err, string.Format("GetNamedPipeClientProcessId failed: Win32 error {0}.", err));
                }

                ChildProcessObservation flankAfter = expectedWorker.Observe();
                if (flankAfter.RootState != ChildRootState.Live || flankAfter.IsClosed)
                {
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new InvalidOperationException(
                        string.Format("Expected worker was not Live immediately after kernel client PID query (RootState={0}, IsClosed={1}).",
                            flankAfter.RootState, flankAfter.IsClosed)
                    );
                }

                if ((int)clientPid != expectedWorker.ProcessId)
                {
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new InvalidOperationException(
                        string.Format("Security violation: connected pipe client PID {0} does not match expected root worker PID {1}. Foreign and descendant clients are rejected.",
                            clientPid, expectedWorker.ProcessId)
                    );
                }

                lock (this._syncLock)
                {
                    this._boundWorker = expectedWorker;
                    this._state = PipeEndpointState.Bound;
                }

                return new OwnedWorkerPeerObservation(
                    (int)clientPid,
                    expectedWorker.CreationFileTimeTicks,
                    DateTime.UtcNow
                );
            }
            catch (Exception ex)
            {
                lock (this._syncLock)
                {
                    this._state = PipeEndpointState.FaultedCloseOnly;
                }

                Exception cleanupEx = null;
                int drainErr = 0;
                bool drained = false;

                if (this._connectPending)
                {
                    try
                    {
                        drained = this.TryDrainOrCancelPendingIo(5000L, out drainErr);
                    }
                    catch (Exception cEx)
                    {
                        cleanupEx = cEx;
                        drained = false;
                        this.RecordCleanupFailure(cEx);
                    }

                    if (!drained)
                    {
                        Exception combined = ex;
                        if (cleanupEx != null || this.LastCleanupFailure != null)
                        {
                            List<Exception> inners = new List<Exception>();
                            inners.Add(ex);
                            if (cleanupEx != null) inners.Add(cleanupEx);
                            if (this.LastCleanupFailure != null && this.LastCleanupFailure != cleanupEx)
                            {
                                inners.Add(this.LastCleanupFailure);
                            }
                            combined = new AggregateException("Connect encountered an exception and overlapped I/O cancellation could not be proven complete.", inners.ToArray());
                        }

                        int finalErr = drainErr != 0 ? drainErr : NativeMethods.ERROR_IO_INCOMPLETE;
                        throw new OwnedWorkerPipeEndpointException(
                            string.Format("Connect encountered an exception ({0}) and overlapped I/O cancellation could not be proven complete within 5000ms (drainError={1}). Retained endpoint must be recovered and closed.", ex.Message, finalErr),
                            combined,
                            this,
                            finalErr
                        );
                    }
                }

                if (cleanupEx != null || this.LastCleanupFailure != null)
                {
                    List<Exception> inners = new List<Exception>();
                    inners.Add(ex);
                    if (cleanupEx != null) inners.Add(cleanupEx);
                    if (this.LastCleanupFailure != null && this.LastCleanupFailure != cleanupEx)
                    {
                        inners.Add(this.LastCleanupFailure);
                    }
                    throw new AggregateException("Connect failed and recorded cleanup diagnostics.", inners.ToArray());
                }

                throw;
            }
            finally
            {
                lock (this._syncLock)
                {
                    this._isBusy = false;
                }
            }
        }

        /// <summary>
        /// Rechecks the bound owned root child process and verifies that the connected pipe client
        /// PID continues to match the bound owner while that owner remains LIVE.
        /// </summary>
        public OwnedWorkerPeerObservation VerifyOwnedPeer()
        {
            OwnedChildProcess bound;
            lock (this._syncLock)
            {
                if (this._isBusy)
                {
                    throw new InvalidOperationException("Pipe endpoint is busy with another operation.");
                }
                if (this._state != PipeEndpointState.Bound || this._boundWorker == null)
                {
                    throw new InvalidOperationException(
                        string.Format("Cannot verify peer: endpoint is not in Bound state (State={0}, HasBoundWorker={1}).",
                            this._state, this._boundWorker != null)
                    );
                }
                if (this._hPipe == IntPtr.Zero)
                {
                    this._state = PipeEndpointState.FaultedCloseOnly;
                    throw new InvalidOperationException("Pipe handle is closed or invalid.");
                }
                bound = this._boundWorker;
                this._isBusy = true;
            }

            try
            {
                if (bound.IsClosed)
                {
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new InvalidOperationException("Bound worker root process is closed.");
                }

                ChildProcessObservation flankBefore = bound.Observe();
                if (flankBefore.RootState != ChildRootState.Live || flankBefore.IsClosed)
                {
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new InvalidOperationException(
                        string.Format("Bound worker root process is not Live before verification query (RootState={0}, IsClosed={1}).",
                            flankBefore.RootState, flankBefore.IsClosed)
                    );
                }

                uint clientPid;
                if (!NativeMethods.GetNamedPipeClientProcessId(this._hPipe, out clientPid))
                {
                    int err = Marshal.GetLastWin32Error();
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new Win32Exception(err, string.Format("GetNamedPipeClientProcessId failed during re-verification: Win32 error {0}.", err));
                }

                ChildProcessObservation flankAfter = bound.Observe();
                if (flankAfter.RootState != ChildRootState.Live || flankAfter.IsClosed)
                {
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new InvalidOperationException(
                        string.Format("Bound worker root process is not Live after verification query (RootState={0}, IsClosed={1}).",
                            flankAfter.RootState, flankAfter.IsClosed)
                    );
                }

                if ((int)clientPid != bound.ProcessId)
                {
                    lock (this._syncLock)
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                    }
                    throw new InvalidOperationException(
                        string.Format("Re-verification failed: kernel client PID {0} does not match bound root PID {1}.",
                            clientPid, bound.ProcessId)
                    );
                }

                return new OwnedWorkerPeerObservation(
                    (int)clientPid,
                    bound.CreationFileTimeTicks,
                    DateTime.UtcNow
                );
            }
            catch
            {
                lock (this._syncLock)
                {
                    this._state = PipeEndpointState.FaultedCloseOnly;
                }
                throw;
            }
            finally
            {
                lock (this._syncLock)
                {
                    this._isBusy = false;
                }
            }
        }

        /// <summary>
        /// Checked closure of the endpoint. Proves completion of any pending overlapped I/O
        /// before closing native handles and freeing unmanaged heap memory.
        /// </summary>
        public bool TryClose(int timeoutMilliseconds, out int error)
        {
            error = 0;
            if (timeoutMilliseconds < 0 || timeoutMilliseconds > 60000)
            {
                error = NativeMethods.ERROR_INVALID_PARAMETER; // 87
                return false;
            }

            lock (this._syncLock)
            {
                if (this._isBusy)
                {
                    error = NativeMethods.ERROR_BUSY; // 170
                    return false;
                }

                if (this._state == PipeEndpointState.Closed &&
                    this._hPipe == IntPtr.Zero &&
                    this._hEvent == IntPtr.Zero &&
                    this._pOverlapped == IntPtr.Zero)
                {
                    return true;
                }

                this._isBusy = true;
            }

            try
            {
                if (this._connectPending)
                {
                    if (this._hPipe == IntPtr.Zero || this._pOverlapped == IntPtr.Zero || this._hEvent == IntPtr.Zero)
                    {
                        lock (this._syncLock)
                        {
                            this._state = PipeEndpointState.FaultedCloseOnly;
                        }
                        error = NativeMethods.ERROR_INVALID_HANDLE;
                        this.RecordCleanupFailure(new Win32Exception(NativeMethods.ERROR_INVALID_HANDLE, "Cannot close endpoint: pending I/O exists but pipe, event, or overlapped buffer handle is missing."));
                        return false;
                    }

                    int drainErr = 0;
                    bool drained = false;
                    try
                    {
                        long drainBudget = (long)timeoutMilliseconds;
                        drained = this.TryDrainOrCancelPendingIo(drainBudget, out drainErr);
                    }
                    catch (Exception dex)
                    {
                        this.RecordCleanupFailure(dex);
                        drained = false;
                    }

                    if (!drained)
                    {
                        lock (this._syncLock)
                        {
                            this._state = PipeEndpointState.FaultedCloseOnly;
                        }
                        error = drainErr != 0 ? drainErr : NativeMethods.ERROR_IO_INCOMPLETE;
                        return false;
                    }
                }

                int firstCloseError = 0;

                // Independent Resource 1: _hPipe
                if (this._hPipe != IntPtr.Zero)
                {
                    try
                    {
                        if (NativeMethods.CloseHandle(this._hPipe))
                        {
                            this._hPipe = IntPtr.Zero;
                        }
                        else
                        {
                            int err = Marshal.GetLastWin32Error();
                            if (err == 0) err = NativeMethods.ERROR_INVALID_HANDLE;
                            if (firstCloseError == 0) firstCloseError = err;
                            this.RecordCleanupFailure(new Win32Exception(err, string.Format("CloseHandle(_hPipe) failed: Win32 error {0}.", err)));
                        }
                    }
                    catch (Exception ex)
                    {
                        if (firstCloseError == 0) firstCloseError = NativeMethods.ERROR_INVALID_HANDLE;
                        this.RecordCleanupFailure(ex);
                    }
                }

                // Independent Resource 2: _hEvent
                if (this._hEvent != IntPtr.Zero)
                {
                    try
                    {
                        if (NativeMethods.CloseHandle(this._hEvent))
                        {
                            this._hEvent = IntPtr.Zero;
                        }
                        else
                        {
                            int err = Marshal.GetLastWin32Error();
                            if (err == 0) err = NativeMethods.ERROR_INVALID_HANDLE;
                            if (firstCloseError == 0) firstCloseError = err;
                            this.RecordCleanupFailure(new Win32Exception(err, string.Format("CloseHandle(_hEvent) failed: Win32 error {0}.", err)));
                        }
                    }
                    catch (Exception ex)
                    {
                        if (firstCloseError == 0) firstCloseError = NativeMethods.ERROR_INVALID_HANDLE;
                        this.RecordCleanupFailure(ex);
                    }
                }

                // Independent Resource 3: _pOverlapped
                if (this._pOverlapped != IntPtr.Zero && this._hHeap != IntPtr.Zero)
                {
                    try
                    {
                        if (NativeMethods.HeapFree(this._hHeap, 0, this._pOverlapped))
                        {
                            this._pOverlapped = IntPtr.Zero;
                        }
                        else
                        {
                            int err = NativeMethods.ERROR_INVALID_PARAMETER; // 87 (application fallback: HeapFree does not set Win32 last-error)
                            if (firstCloseError == 0) firstCloseError = err;
                            this.RecordCleanupFailure(new Win32Exception(err, "HeapFree returned FALSE deallocating OVERLAPPED buffer (application fallback error 87: ERROR_INVALID_PARAMETER; Win32 last-error not set by HeapFree)."));
                        }
                    }
                    catch (Exception ex)
                    {
                        if (firstCloseError == 0) firstCloseError = NativeMethods.ERROR_INVALID_PARAMETER;
                        this.RecordCleanupFailure(ex);
                    }
                }

                lock (this._syncLock)
                {
                    if (this._hPipe == IntPtr.Zero && this._hEvent == IntPtr.Zero && this._pOverlapped == IntPtr.Zero)
                    {
                        this._state = PipeEndpointState.Closed;

                        if (firstCloseError == 0 && this._cleanupFailures == null)
                        {
                            error = 0;
                            return true;
                        }
                        else
                        {
                            // All handles/pointers proven released, but cleanup failures were recorded
                            error = firstCloseError != 0 ? firstCloseError : NativeMethods.ERROR_GEN_FAILURE;
                            return false;
                        }
                    }
                    else
                    {
                        this._state = PipeEndpointState.FaultedCloseOnly;
                        error = firstCloseError != 0 ? firstCloseError : NativeMethods.ERROR_INVALID_HANDLE;
                        return false;
                    }
                }
            }
            catch (Exception ex)
            {
                lock (this._syncLock)
                {
                    this._state = PipeEndpointState.FaultedCloseOnly;
                }
                this.RecordCleanupFailure(ex);
                throw;
            }
            finally
            {
                lock (this._syncLock)
                {
                    this._isBusy = false;
                }
            }
        }

        /// <summary>
        /// Disposes owned resources. Throws OwnedWorkerPipeEndpointCloseException if closure
        /// could not be proven, preserving the retained endpoint on the exception.
        /// </summary>
        public void Dispose()
        {
            int error = 0;
            bool closed = false;
            Exception innerEx = null;

            try
            {
                closed = this.TryClose(5000, out error);
            }
            catch (Exception ex)
            {
                innerEx = ex;
            }

            if (!closed || this.State != PipeEndpointState.Closed || this.LastCleanupFailure != null)
            {
                Exception combined = innerEx;
                if (this.LastCleanupFailure != null)
                {
                    if (innerEx != null)
                    {
                        combined = new AggregateException("Dispose failed to close endpoint and recorded cleanup failures.", innerEx, this.LastCleanupFailure);
                    }
                    else
                    {
                        combined = this.LastCleanupFailure;
                    }
                }

                throw new OwnedWorkerPipeEndpointCloseException(
                    string.Format("Failed to safely close OwnedWorkerPipeEndpoint (Win32 error {0}). Retained endpoint must be recovered and closed.", error),
                    combined,
                    this,
                    error
                );
            }
        }

        private static class NativeMethods
        {
            public static readonly IntPtr INVALID_HANDLE_VALUE = new IntPtr(-1);

            public const uint PIPE_ACCESS_DUPLEX = 0x00000003;
            public const uint FILE_FLAG_FIRST_PIPE_INSTANCE = 0x00080000;
            public const uint FILE_FLAG_OVERLAPPED = 0x40000000;

            public const uint PIPE_TYPE_BYTE = 0x00000000;
            public const uint PIPE_READMODE_BYTE = 0x00000000;
            public const uint PIPE_WAIT = 0x00000000;
            public const uint PIPE_REJECT_REMOTE_CLIENTS = 0x00000008;

            public const uint PIPE_SERVER_END = 0x00000001;

            public const uint HEAP_ZERO_MEMORY = 0x00000008;

            public const uint WAIT_OBJECT_0 = 0x00000000;
            public const uint WAIT_TIMEOUT = 0x00000102;
            public const uint WAIT_FAILED = 0xFFFFFFFF;

            public const int ERROR_SUCCESS = 0;
            public const int ERROR_INVALID_HANDLE = 6;
            public const int ERROR_NOT_ENOUGH_MEMORY = 8;
            public const int ERROR_GEN_FAILURE = 31;
            public const int ERROR_INVALID_PARAMETER = 87;
            public const int ERROR_BUSY = 170;
            public const int ERROR_PIPE_CONNECTED = 535;
            public const int ERROR_OPERATION_ABORTED = 995;
            public const int ERROR_IO_INCOMPLETE = 996;
            public const int ERROR_IO_PENDING = 997;
            public const int ERROR_NOT_FOUND = 1168;

            [DllImport("kernel32.dll", EntryPoint = "CreateNamedPipeW", CharSet = CharSet.Unicode, SetLastError = true)]
            public static extern IntPtr CreateNamedPipe(
                string lpName,
                uint dwOpenMode,
                uint dwPipeMode,
                uint nMaxInstances,
                uint nOutBufferSize,
                uint nInBufferSize,
                uint nDefaultTimeOut,
                IntPtr lpSecurityAttributes
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            public static extern bool ConnectNamedPipe(IntPtr hNamedPipe, IntPtr lpOverlapped);

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            public static extern bool CancelIoEx(IntPtr hFile, IntPtr lpOverlapped);

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            public static extern bool GetOverlappedResult(
                IntPtr hFile,
                IntPtr lpOverlapped,
                out uint lpNumberOfBytesTransferred,
                [MarshalAs(UnmanagedType.Bool)] bool bWait
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            public static extern bool GetNamedPipeClientProcessId(IntPtr Pipe, out uint ClientProcessId);

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            public static extern bool GetNamedPipeInfo(
                IntPtr hNamedPipe,
                out uint lpFlags,
                out uint lpOutBufferSize,
                out uint lpInBufferSize,
                out uint lpMaxInstances
            );

            [DllImport("kernel32.dll", EntryPoint = "CreateEventW", CharSet = CharSet.Unicode, SetLastError = true)]
            public static extern IntPtr CreateEvent(
                IntPtr lpEventAttributes,
                [MarshalAs(UnmanagedType.Bool)] bool bManualReset,
                [MarshalAs(UnmanagedType.Bool)] bool bInitialState,
                string lpName
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            public static extern bool ResetEvent(IntPtr hEvent);

            [DllImport("kernel32.dll", SetLastError = true)]
            public static extern uint WaitForSingleObject(IntPtr hHandle, uint dwMilliseconds);

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            public static extern bool CloseHandle(IntPtr hObject);

            [DllImport("kernel32.dll", SetLastError = true)]
            public static extern IntPtr GetProcessHeap();

            [DllImport("kernel32.dll", SetLastError = false)]
            public static extern IntPtr HeapAlloc(IntPtr hHeap, uint dwFlags, UIntPtr dwBytes);

            [DllImport("kernel32.dll", SetLastError = false)]
            [return: MarshalAs(UnmanagedType.Bool)]
            public static extern bool HeapFree(IntPtr hHeap, uint dwFlags, IntPtr lpMem);
        }
    }
}
