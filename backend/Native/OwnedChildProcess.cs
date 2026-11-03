// backend/Native/OwnedChildProcess.cs
//
// INTERNAL Win32 owned child process and Job Object primitive.
//
// Architecture & Trust Notice:
// Caller MUST authenticate SOURCE executable/runtime, arguments, working directory,
// and environment from a verified private SOURCE snapshot and enforce execution phase
// gating before launching ANY target.
//
// This internal primitive does NOT verify RSA signatures, database consistency,
// whole-system quiescence, loaded DLL origins, or absolute TOCTOU bounds.
// It cannot attach to or terminate pre-existing installed, Beta, or unowned processes.
// Job Object containment owns ONLY new direct normal descendants, not external processes
// brokered through ShellExecute, COM, or Windows services.
//
// Process handle ownership vs Job accounting vs storage rundown:
// The class owns the ROOT process handle and Job Object, NOT each individual descendant
// process handle. Root death is proved separately through that owned handle; Job accounting
// (ActiveProcesses == 0) proves only that the Job Object active process count has reached zero.
// However, Job accounting zero does NOT prove that all descendant kernel object rundown or
// asynchronous file handle releases are complete, nor does it guarantee database quiescence
// or cold backup safety.
// IsQuiescent strictly proves that the owned root process is DEAD (or NotStarted) and the known
// Job active count is 0 (native accounting proof only). It does NOT guarantee that all member
// kernel rundown has completed, that all file handles have been released, or that storage is free.
// Callers must independently acquire exclusive leases/file locks and appropriate database proof
// before touching, mutating, or cleaning files.
//
// Native child process termination is not database backup or health proof.

namespace PosDesktop.Updates.Native
{
    using System;
    using System.Collections.Generic;
    using System.ComponentModel;
    using System.Diagnostics;
    using System.IO;
    using System.Runtime.InteropServices;
    using System.Text;
    using System.Threading;

    /// <summary>
    /// Lifecycle state of the root child process handle.
    /// </summary>
    public enum ChildRootState
    {
        NotStarted = 0,
        Live = 1,
        Dead = 2,
        Unknown = 3
    }

    /// <summary>
    /// Immutable observation snapshot of owned child and Job Object state.
    /// </summary>
    public sealed class ChildProcessObservation
    {
        private readonly ChildRootState _rootState;
        private readonly bool _jobCountKnown;
        private readonly int _activeProcesses;
        private readonly bool _isQuiescent;
        private readonly int _lastError;
        private readonly string _errorMessage;
        private readonly bool _isClosed;
        private readonly int _processId;

        public ChildProcessObservation(
            ChildRootState rootState,
            bool jobCountKnown,
            int activeProcesses,
            bool isQuiescent,
            int lastError,
            string errorMessage,
            bool isClosed,
            int processId)
        {
            this._rootState = rootState;
            this._jobCountKnown = jobCountKnown;
            this._activeProcesses = activeProcesses;
            this._isQuiescent = isQuiescent;
            this._lastError = lastError;
            this._errorMessage = errorMessage ?? string.Empty;
            this._isClosed = isClosed;
            this._processId = processId;
        }

        public ChildRootState RootState { get { return this._rootState; } }
        public bool JobCountKnown { get { return this._jobCountKnown; } }
        public int ActiveProcesses { get { return this._activeProcesses; } }

        /// <summary>
        /// Indicates whether the owned root process is Dead (or NotStarted) and the Job Object active
        /// process count is known and 0 (native accounting proof only).
        /// This does NOT guarantee that all member kernel handle rundowns or asynchronous file handle releases
        /// are complete, nor does it guarantee storage freedom or database cold backup quiescence.
        /// Callers must acquire exclusive file leases and appropriate database proof before touching files.
        /// </summary>
        public bool IsQuiescent { get { return this._isQuiescent; } }
        public int LastError { get { return this._lastError; } }
        public string ErrorMessage { get { return this._errorMessage; } }
        public bool IsClosed { get { return this._isClosed; } }
        public int ProcessId { get { return this._processId; } }
    }

    /// <summary>
    /// Exception thrown when process creation or job assignment fails and owned native resources
    /// require retention or could not be completely and cleanly stopped/closed.
    /// </summary>
    public sealed class OwnedChildProcessStartException : Exception
    {
        private readonly OwnedChildProcess _retainedOwner;
        private readonly int _nativeError;

        public OwnedChildProcessStartException(string message, Exception innerException, OwnedChildProcess retainedOwner, int nativeError)
            : base(message, innerException)
        {
            this._retainedOwner = retainedOwner;
            this._nativeError = nativeError;
        }

        public OwnedChildProcess RetainedOwner { get { return this._retainedOwner; } }
        public int NativeError { get { return this._nativeError; } }
    }

    /// <summary>
    /// Manages the lifecycle of an owned Win32 child process and its descendant tree
    /// strictly through kernel handles and a dedicated Win32 Job Object.
    /// </summary>
    public sealed class OwnedChildProcess : IDisposable
    {
        private readonly object _syncLock;
        private IntPtr _hJob;
        private IntPtr _hProcess;
        private IntPtr _hThread;
        private int _processId;
        private long _creationFileTimeTicks;
        private string _imagePath;

        private bool _created;
        private bool _rootAssignedToJob;
        private bool _rootDead;
        private bool _jobEmpty;
        private bool _threadClosed;
        private bool _processClosed;
        private bool _jobClosed;
        private bool _isClosed;

        private OwnedChildProcess()
        {
            this._syncLock = new object();
            this._hJob = IntPtr.Zero;
            this._hProcess = IntPtr.Zero;
            this._hThread = IntPtr.Zero;
            this._processId = 0;
            this._creationFileTimeTicks = 0;
            this._imagePath = string.Empty;

            this._created = false;
            this._rootAssignedToJob = false;
            this._rootDead = false;
            this._jobEmpty = false;
            this._threadClosed = false;
            this._processClosed = false;
            this._jobClosed = false;
            this._isClosed = false;
        }

        public int ProcessId
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._processId;
                }
            }
        }

        public long CreationFileTimeTicks
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._creationFileTimeTicks;
                }
            }
        }

        public string ImagePath
        {
            get
            {
                lock (this._syncLock)
                {
                    return this._imagePath;
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
        /// Gets whether the current observation of the owned process and Job Object is quiescent
        /// (owned root process is Dead or NotStarted, and known Job active process count is 0).
        /// Native accounting zero does NOT guarantee that all member kernel handle rundowns or file handles
        /// are released; callers must acquire exclusive leases and appropriate DB proof before touching files.
        /// </summary>
        public bool IsQuiescent
        {
            get
            {
                return this.Observe().IsQuiescent;
            }
        }

        /// <summary>
        /// Validates paths, arguments, and environment, creates an unnamed Job Object with KILL_ON_JOB_CLOSE,
        /// creates the child process suspended with a sparse explicit environment, assigns it to the Job Object,
        /// and resumes the main thread.
        /// </summary>
        public static OwnedChildProcess Start(
            string executablePath,
            string[] arguments,
            string workingDirectory,
            IDictionary<string, string> environment)
        {
            // Win32NT x64 check
            if (Environment.OSVersion.Platform != PlatformID.Win32NT || IntPtr.Size != 8)
            {
                throw new PlatformNotSupportedException("OwnedChildProcess requires 64-bit Windows (Win32NT x64).");
            }

            // Path validations
            ValidateDosExecutablePath(executablePath);
            ValidateDosWorkingDirectory(workingDirectory);

            // Argument vector validation and Windows CRT quoting
            string commandLine = BuildCommandLine(executablePath, arguments);

            // Environment block validation and serialization
            byte[] envBlockBytes = BuildEnvironmentBlock(environment);

            // Allocate owner BEFORE creating any native handles
            OwnedChildProcess owner = new OwnedChildProcess();

            IntPtr pEnv = IntPtr.Zero;
            try
            {
                // 1. Create unnamed, noninheritable Job Object
                IntPtr hJob = NativeMethods.CreateJobObjectW(IntPtr.Zero, null);
                if (hJob == IntPtr.Zero || hJob == NativeMethods.INVALID_HANDLE_VALUE)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("CreateJobObjectW failed: Win32 error {0}.", err));
                }
                owner._hJob = hJob;

                // 2. Set KILL_ON_JOB_CLOSE (0x2000) and ensure no breakaway
                NativeMethods.JOBOBJECT_EXTENDED_LIMIT_INFORMATION limits = new NativeMethods.JOBOBJECT_EXTENDED_LIMIT_INFORMATION();
                limits.BasicLimitInformation.LimitFlags = NativeMethods.JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
                uint limitSize = (uint)Marshal.SizeOf(typeof(NativeMethods.JOBOBJECT_EXTENDED_LIMIT_INFORMATION));
                if (limitSize != 144)
                {
                    throw new InvalidOperationException(string.Format("JOBOBJECT_EXTENDED_LIMIT_INFORMATION struct size mismatch: expected 144, got {0}.", limitSize));
                }

                if (!NativeMethods.SetInformationJobObject(hJob, NativeMethods.JobObjectExtendedLimitInformation, ref limits, limitSize))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("SetInformationJobObject failed: Win32 error {0}.", err));
                }

                // Query back limits to prove applied configuration
                NativeMethods.JOBOBJECT_EXTENDED_LIMIT_INFORMATION queriedLimits = new NativeMethods.JOBOBJECT_EXTENDED_LIMIT_INFORMATION();
                uint retLen;
                if (!NativeMethods.QueryInformationJobObject(hJob, NativeMethods.JobObjectExtendedLimitInformation, ref queriedLimits, limitSize, out retLen))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("QueryInformationJobObject for extended limits failed: Win32 error {0}.", err));
                }

                if ((queriedLimits.BasicLimitInformation.LimitFlags & NativeMethods.JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE) == 0)
                {
                    throw new InvalidOperationException("KILL_ON_JOB_CLOSE limit flag verification failed.");
                }
                if ((queriedLimits.BasicLimitInformation.LimitFlags & (NativeMethods.JOB_OBJECT_LIMIT_BREAKAWAY_OK | NativeMethods.JOB_OBJECT_LIMIT_SILENT_BREAKAWAY_OK)) != 0)
                {
                    throw new InvalidOperationException("Breakaway flags unexpectedly present on Job Object.");
                }

                // 3. Prepare environment unmanaged buffer
                if (envBlockBytes != null && envBlockBytes.Length > 0)
                {
                    pEnv = Marshal.AllocHGlobal(envBlockBytes.Length);
                    Marshal.Copy(envBlockBytes, 0, pEnv, envBlockBytes.Length);
                }

                // 4. Create suspended child process
                NativeMethods.STARTUPINFO si = new NativeMethods.STARTUPINFO();
                si.cb = Marshal.SizeOf(typeof(NativeMethods.STARTUPINFO));

                NativeMethods.PROCESS_INFORMATION pi = new NativeMethods.PROCESS_INFORMATION();
                uint flags = NativeMethods.CREATE_SUSPENDED | NativeMethods.CREATE_UNICODE_ENVIRONMENT | NativeMethods.CREATE_NO_WINDOW;

                StringBuilder cmdBuilder = new StringBuilder(commandLine);
                bool cpSuccess = NativeMethods.CreateProcessW(
                    executablePath,
                    cmdBuilder,
                    IntPtr.Zero,
                    IntPtr.Zero,
                    false, // bInheritHandles = false
                    flags,
                    pEnv,
                    workingDirectory,
                    ref si,
                    out pi
                );

                if (!cpSuccess)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("CreateProcessW failed: Win32 error {0}.", err));
                }

                // Immediately track handles, process ID, and created state before any assertion
                owner._hProcess = pi.hProcess;
                owner._hThread = pi.hThread;
                owner._processId = pi.dwProcessId;
                owner._created = true;

                // 4b. Enforce child architecture x64 after Create SUSPENDED and BEFORE Assign/Resume
                ushort processMachine;
                ushort nativeMachine;
                if (!NativeMethods.IsWow64Process2(pi.hProcess, out processMachine, out nativeMachine))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("IsWow64Process2 failed to query child process architecture: Win32 error {0}.", err));
                }

                bool isAmd64 = (processMachine == NativeMethods.IMAGE_FILE_MACHINE_AMD64) ||
                               (processMachine == NativeMethods.IMAGE_FILE_MACHINE_UNKNOWN && nativeMachine == NativeMethods.IMAGE_FILE_MACHINE_AMD64);

                if (!isAmd64)
                {
                    throw new PlatformNotSupportedException(
                        string.Format("Child process architecture (processMachine=0x{0:X4}, nativeMachine=0x{1:X4}) is not x64 (AMD64). Only x64 child processes are supported.",
                            processMachine, nativeMachine)
                    );
                }

                // Capture creation FILETIME ticks from OWN process handle (throw captured native error on failure)
                NativeMethods.FILETIME ftCreation, ftExit, ftKernel, ftUser;
                if (!NativeMethods.GetProcessTimes(pi.hProcess, out ftCreation, out ftExit, out ftKernel, out ftUser))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("GetProcessTimes failed: Win32 error {0}.", err));
                }
                owner._creationFileTimeTicks = ((long)ftCreation.dwHighDateTime << 32) | (long)ftCreation.dwLowDateTime;

                // Capture full process image name from OWN process handle with bounded buffer
                StringBuilder sbImg = new StringBuilder(1024);
                uint imgLen = (uint)sbImg.Capacity;
                if (!NativeMethods.QueryFullProcessImageNameW(pi.hProcess, 0, sbImg, ref imgLen) || imgLen == 0)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("QueryFullProcessImageNameW failed: Win32 error {0}.", err));
                }
                string actualImagePath = sbImg.ToString();
                if (string.IsNullOrEmpty(actualImagePath))
                {
                    throw new InvalidOperationException("QueryFullProcessImageNameW returned empty image path.");
                }
                string canonicalExpected = Path.GetFullPath(executablePath);
                if (!string.Equals(actualImagePath, canonicalExpected, StringComparison.OrdinalIgnoreCase))
                {
                    throw new InvalidOperationException(
                        string.Format("Child process image path '{0}' does not match expected canonical path '{1}'.", actualImagePath, canonicalExpected)
                    );
                }
                owner._imagePath = actualImagePath;

                // 5. Assign child to Job Object BEFORE ResumeThread
                if (!NativeMethods.AssignProcessToJobObject(hJob, pi.hProcess))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("AssignProcessToJobObject failed: Win32 error {0}.", err));
                }
                owner._rootAssignedToJob = true;

                // Verify process is actually in job using OWN handles
                bool inJob;
                if (!NativeMethods.IsProcessInJob(pi.hProcess, hJob, out inJob) || !inJob)
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new InvalidOperationException(string.Format("IsProcessInJob verification failed (inJob={0}): Win32 error {1}.", inJob, err));
                }

                // 6. Resume main thread
                uint suspendCount = NativeMethods.ResumeThread(pi.hThread);
                if (suspendCount == unchecked((uint)-1))
                {
                    int err = Marshal.GetLastWin32Error();
                    throw new Win32Exception(err, string.Format("ResumeThread failed: Win32 error {0}.", err));
                }
                if (suspendCount != 1)
                {
                    throw new InvalidOperationException(string.Format("ResumeThread returned unexpected suspend count {0} (expected 1).", suspendCount));
                }

                // Thread handle must close after successful resume; if CloseHandle fails, record native error and throw to preserve Owner
                if (!NativeMethods.CloseHandle(pi.hThread))
                {
                    int err = Marshal.GetLastWin32Error();
                    owner._threadClosed = false;
                    throw new Win32Exception(err, string.Format("CloseHandle on resumed thread handle failed: Win32 error {0}.", err));
                }
                owner._hThread = IntPtr.Zero;
                owner._threadClosed = true;

                return owner;
            }
            catch (Exception ex)
            {
                // Attempt bounded stop/close of ONLY owned resources, preserving primary exception even if cleanup throws
                int stopError = 0;
                bool stopped = false;
                Exception cleanupEx = null;
                try
                {
                    stopped = owner.TryStopAndClose(3000, out stopError);
                }
                catch (Exception cEx)
                {
                    cleanupEx = cEx;
                }

                if (!stopped || !owner.IsClosed || cleanupEx != null)
                {
                    Exception innerForWrap = ex;
                    if (cleanupEx != null)
                    {
                        innerForWrap = new AggregateException("Process start failed and subsequent cleanup threw an exception.", ex, cleanupEx);
                    }

                    throw new OwnedChildProcessStartException(
                        string.Format("Child process start failed ({0}) and owned resource cleanup was uncertain (error {1}).", ex.Message, stopError),
                        innerForWrap,
                        owner,
                        stopError
                    );
                }
                throw;
            }
            finally
            {
                if (pEnv != IntPtr.Zero)
                {
                    Marshal.FreeHGlobal(pEnv);
                }
            }
        }

        /// <summary>
        /// Produces an immutable observation snapshot of the root process and Job Object.
        /// </summary>
        public ChildProcessObservation Observe()
        {
            lock (this._syncLock)
            {
                return this.ObserveInternal();
            }
        }

        private ChildProcessObservation ObserveInternal()
        {
            ChildRootState rootState;
            int lastErr = 0;
            string lastErrMsg = string.Empty;

            if (!this._created)
            {
                rootState = ChildRootState.NotStarted;
            }
            else if (this._rootDead)
            {
                rootState = ChildRootState.Dead;
            }
            else if (this._processClosed || this._hProcess == IntPtr.Zero)
            {
                // Created root missing handle without cached DEAD = UNKNOWN
                rootState = ChildRootState.Unknown;
            }
            else
            {
                uint waitRes = NativeMethods.WaitForSingleObject(this._hProcess, 0);
                if (waitRes == NativeMethods.WAIT_OBJECT_0)
                {
                    this._rootDead = true;
                    rootState = ChildRootState.Dead;
                }
                else if (waitRes == NativeMethods.WAIT_TIMEOUT)
                {
                    rootState = ChildRootState.Live;
                }
                else
                {
                    rootState = ChildRootState.Unknown;
                    lastErr = Marshal.GetLastWin32Error();
                    lastErrMsg = string.Format("WaitForSingleObject on process handle returned {0}: Win32 error {1}.", waitRes, lastErr);
                }
            }

            bool jobCountKnown = false;
            int activeProcesses = -1;

            if (this._jobEmpty)
            {
                jobCountKnown = true;
                activeProcesses = 0;
            }
            else if (this._jobClosed || this._hJob == IntPtr.Zero)
            {
                if (this._hJob == IntPtr.Zero && !this._created)
                {
                    jobCountKnown = true;
                    activeProcesses = 0;
                }
                else
                {
                    jobCountKnown = false;
                    activeProcesses = -1;
                }
            }
            else
            {
                NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION acc = new NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION();
                uint accSize = (uint)Marshal.SizeOf(typeof(NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION));
                uint retLen;
                if (NativeMethods.QueryInformationJobObject(this._hJob, NativeMethods.JobObjectBasicAccountingInformation, ref acc, accSize, out retLen))
                {
                    jobCountKnown = true;
                    activeProcesses = (int)acc.ActiveProcesses;
                    // Cache job empty ONLY after valid 0 query and root death/noFutureadmissions, never empty because startup failed
                    if (activeProcesses == 0 && (this._rootDead || !this._created))
                    {
                        this._jobEmpty = true;
                    }
                }
                else
                {
                    jobCountKnown = false;
                    activeProcesses = -1;
                    if (lastErr == 0)
                    {
                        lastErr = Marshal.GetLastWin32Error();
                        lastErrMsg = string.Format("QueryInformationJobObject for accounting failed: Win32 error {0}.", lastErr);
                    }
                }
            }

            // IsQuiescent defines native process accounting quiescence ONLY (root Dead + known Job Active 0).
            // It does not guarantee all member kernel rundown, file handle closure, or storage freedom.
            bool isQuiescent = (rootState == ChildRootState.Dead || rootState == ChildRootState.NotStarted)
                && jobCountKnown
                && (activeProcesses == 0);

            return new ChildProcessObservation(
                rootState,
                jobCountKnown,
                activeProcesses,
                isQuiescent,
                lastErr,
                lastErrMsg,
                this._isClosed,
                this._processId
            );
        }

        /// <summary>
        /// Explicitly terminates the root child process using its own process handle and waits for exit.
        /// Does NOT terminate the Job Object, claim descendants dead, or close handles.
        /// </summary>
        public bool TryTerminateRootAndWait(int timeoutMilliseconds, out int error)
        {
            lock (this._syncLock)
            {
                error = 0;

                // Validate timeout 0..60000 before any operation; never INFINITE
                if (timeoutMilliseconds < 0 || timeoutMilliseconds > 60000)
                {
                    error = 87; // ERROR_INVALID_PARAMETER
                    return false;
                }

                if (this._rootDead)
                {
                    return true;
                }

                if (this._processClosed || this._hProcess == IntPtr.Zero)
                {
                    error = 6; // ERROR_INVALID_HANDLE
                    return false;
                }

                Stopwatch sw = Stopwatch.StartNew();

                // Check if already dead
                uint waitNow = NativeMethods.WaitForSingleObject(this._hProcess, 0);
                if (waitNow == NativeMethods.WAIT_OBJECT_0)
                {
                    this._rootDead = true;
                    return true;
                }
                else if (waitNow != NativeMethods.WAIT_TIMEOUT)
                {
                    // WAIT_FAILED or unknown => false + error/retain, not blind terminate
                    error = Marshal.GetLastWin32Error();
                    if (error == 0) error = 1;
                    return false;
                }

                // Request termination of root process handle only
                if (!NativeMethods.TerminateProcess(this._hProcess, 1))
                {
                    int termErr = Marshal.GetLastWin32Error();
                    // Fresh wait to check if already dead
                    uint waitCheck = NativeMethods.WaitForSingleObject(this._hProcess, 0);
                    if (waitCheck == NativeMethods.WAIT_OBJECT_0)
                    {
                        this._rootDead = true;
                        return true;
                    }
                    else if (termErr == 5 && waitCheck == NativeMethods.WAIT_TIMEOUT)
                    {
                        int remainingMs = Math.Max(0, timeoutMilliseconds - (int)sw.ElapsedMilliseconds);
                        uint waitRes = NativeMethods.WaitForSingleObject(this._hProcess, (uint)remainingMs);
                        if (waitRes == NativeMethods.WAIT_OBJECT_0)
                        {
                            this._rootDead = true;
                            return true;
                        }
                        else if (waitRes == NativeMethods.WAIT_TIMEOUT)
                        {
                            error = 258; // WAIT_TIMEOUT
                            return false;
                        }
                        else
                        {
                            error = Marshal.GetLastWin32Error();
                            if (error == 0) error = 1;
                            return false;
                        }
                    }
                    else
                    {
                        if (termErr == 5 && waitCheck != NativeMethods.WAIT_TIMEOUT)
                        {
                            error = Marshal.GetLastWin32Error();
                            if (error == 0) error = 5;
                        }
                        else
                        {
                            error = termErr != 0 ? termErr : 1;
                        }
                        return false;
                    }
                }

                int remaining = Math.Max(0, timeoutMilliseconds - (int)sw.ElapsedMilliseconds);
                uint waitSuccess = NativeMethods.WaitForSingleObject(this._hProcess, (uint)remaining);
                if (waitSuccess == NativeMethods.WAIT_OBJECT_0)
                {
                    this._rootDead = true;
                    error = 0;
                    return true;
                }
                else if (waitSuccess == NativeMethods.WAIT_TIMEOUT)
                {
                    error = 258; // WAIT_TIMEOUT
                    return false;
                }
                else
                {
                    error = Marshal.GetLastWin32Error();
                    if (error == 0) error = 1;
                    return false;
                }
            }
        }

        /// <summary>
        /// Terminates the Job Object and root process if not already dead/empty, waits within a monotonic deadline
        /// until the root is proven DEAD and job active processes reach 0, then independently closes all handles.
        /// </summary>
        public bool TryStopAndClose(int timeoutMilliseconds, out int error)
        {
            lock (this._syncLock)
            {
                error = 0;

                // Validate timeout 0..60000 before any operation; never INFINITE
                if (timeoutMilliseconds < 0 || timeoutMilliseconds > 60000)
                {
                    error = 87; // ERROR_INVALID_PARAMETER
                    return false;
                }

                if (this._isClosed)
                {
                    return true;
                }

                Stopwatch sw = Stopwatch.StartNew();

                // Step 1: Ensure Job Object is stopped and proven EMPTY (Active 0)
                if (!this._jobEmpty)
                {
                    if (this._jobClosed || this._hJob == IntPtr.Zero)
                    {
                        if (this._hJob == IntPtr.Zero && !this._created)
                        {
                            this._jobEmpty = true;
                        }
                        else
                        {
                            error = 6; // ERROR_INVALID_HANDLE
                            return false;
                        }
                    }
                    else
                    {
                        // Terminate all processes currently associated with the Job Object
                        if (!NativeMethods.TerminateJobObject(this._hJob, 1))
                        {
                            int tErr = Marshal.GetLastWin32Error();
                            // Fresh query to check if already empty
                            NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION checkAcc = new NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION();
                            uint chkSize = (uint)Marshal.SizeOf(typeof(NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION));
                            uint retChk;
                            if (NativeMethods.QueryInformationJobObject(this._hJob, NativeMethods.JobObjectBasicAccountingInformation, ref checkAcc, chkSize, out retChk) && checkAcc.ActiveProcesses == 0)
                            {
                                this._jobEmpty = true;
                            }
                            else
                            {
                                error = tErr != 0 ? tErr : 1;
                                return false;
                            }
                        }

                        // Bounded query loop until active processes count is proven zero
                        if (!this._jobEmpty)
                        {
                            while (true)
                            {
                                NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION acc = new NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION();
                                uint accSize = (uint)Marshal.SizeOf(typeof(NativeMethods.JOBOBJECT_BASIC_ACCOUNTING_INFORMATION));
                                uint retLen;
                                if (!NativeMethods.QueryInformationJobObject(this._hJob, NativeMethods.JobObjectBasicAccountingInformation, ref acc, accSize, out retLen))
                                {
                                    error = Marshal.GetLastWin32Error();
                                    return false;
                                }

                                if (acc.ActiveProcesses == 0)
                                {
                                    this._jobEmpty = true;
                                    break;
                                }

                                int remainingMs = timeoutMilliseconds - (int)sw.ElapsedMilliseconds;
                                if (remainingMs <= 0)
                                {
                                    error = 258; // WAIT_TIMEOUT
                                    return false;
                                }

                                Thread.Sleep(Math.Min(25, remainingMs));
                            }
                        }
                    }
                }

                // Step 2: Ensure root process is proven DEAD (explicit OWN process HANDLE stop)
                if (!this._rootDead)
                {
                    if (this._processClosed || this._hProcess == IntPtr.Zero)
                    {
                        if (!this._created)
                        {
                            this._rootDead = true;
                        }
                        else
                        {
                            // Created process missing handle without proof of death
                            error = 6; // ERROR_INVALID_HANDLE
                            return false;
                        }
                    }
                    else
                    {
                        uint waitNow = NativeMethods.WaitForSingleObject(this._hProcess, 0);
                        if (waitNow == NativeMethods.WAIT_OBJECT_0)
                        {
                            this._rootDead = true;
                        }
                        else if (waitNow != NativeMethods.WAIT_TIMEOUT)
                        {
                            error = Marshal.GetLastWin32Error();
                            if (error == 0) error = 1;
                            return false;
                        }
                        else
                        {
                            if (this._rootAssignedToJob)
                            {
                                // Root process was assigned to Job Object, which was already terminated and proven empty.
                                // Do not redundantly TerminateProcess; wait for own root handle signal within remaining deadline.
                                int remainingMs = Math.Max(0, timeoutMilliseconds - (int)sw.ElapsedMilliseconds);
                                uint waitRes = NativeMethods.WaitForSingleObject(this._hProcess, (uint)remainingMs);
                                if (waitRes == NativeMethods.WAIT_OBJECT_0)
                                {
                                    this._rootDead = true;
                                }
                                else if (waitRes == NativeMethods.WAIT_TIMEOUT)
                                {
                                    error = 258; // WAIT_TIMEOUT
                                    return false;
                                }
                                else
                                {
                                    error = Marshal.GetLastWin32Error();
                                    if (error == 0) error = 1;
                                    return false;
                                }
                            }
                            else
                            {
                                // Root process was NOT assigned to Job Object (e.g. startup failed before job assignment).
                                // Must explicitly terminate own root process handle and wait.
                                if (!NativeMethods.TerminateProcess(this._hProcess, 1))
                                {
                                    int termErr = Marshal.GetLastWin32Error();
                                    uint waitCheck = NativeMethods.WaitForSingleObject(this._hProcess, 0);
                                    if (waitCheck == NativeMethods.WAIT_OBJECT_0)
                                    {
                                        this._rootDead = true;
                                    }
                                    else if (termErr == 5 && waitCheck == NativeMethods.WAIT_TIMEOUT)
                                    {
                                        int remainingMs = Math.Max(0, timeoutMilliseconds - (int)sw.ElapsedMilliseconds);
                                        uint waitRes = NativeMethods.WaitForSingleObject(this._hProcess, (uint)remainingMs);
                                        if (waitRes == NativeMethods.WAIT_OBJECT_0)
                                        {
                                            this._rootDead = true;
                                        }
                                        else if (waitRes == NativeMethods.WAIT_TIMEOUT)
                                        {
                                            error = 258; // WAIT_TIMEOUT
                                            return false;
                                        }
                                        else
                                        {
                                            error = Marshal.GetLastWin32Error();
                                            if (error == 0) error = 1;
                                            return false;
                                        }
                                    }
                                    else
                                    {
                                        if (termErr == 5 && waitCheck != NativeMethods.WAIT_TIMEOUT)
                                        {
                                            error = Marshal.GetLastWin32Error();
                                            if (error == 0) error = 5;
                                        }
                                        else
                                        {
                                            error = termErr != 0 ? termErr : 1;
                                        }
                                        return false;
                                    }
                                }
                                else
                                {
                                    int remainingMs = Math.Max(0, timeoutMilliseconds - (int)sw.ElapsedMilliseconds);
                                    uint waitRes = NativeMethods.WaitForSingleObject(this._hProcess, (uint)remainingMs);
                                    if (waitRes == NativeMethods.WAIT_OBJECT_0)
                                    {
                                        this._rootDead = true;
                                    }
                                    else if (waitRes == NativeMethods.WAIT_TIMEOUT)
                                    {
                                        error = 258; // WAIT_TIMEOUT
                                        return false;
                                    }
                                    else
                                    {
                                        error = Marshal.GetLastWin32Error();
                                        if (error == 0) error = 1;
                                        return false;
                                    }
                                }
                            }
                        }
                    }
                }

                // Step 3: Must prove both root DEAD and Job empty before handle closure
                if (!this._rootDead || !this._jobEmpty)
                {
                    if (error == 0) error = -1;
                    return false;
                }

                // Step 4: Independently close each remaining handle; first failure does not prevent other closes
                int firstCloseError = 0;

                // Close thread handle if still open
                if (!this._threadClosed && this._hThread != IntPtr.Zero)
                {
                    if (NativeMethods.CloseHandle(this._hThread))
                    {
                        this._hThread = IntPtr.Zero;
                        this._threadClosed = true;
                    }
                    else
                    {
                        int cErr = Marshal.GetLastWin32Error();
                        if (firstCloseError == 0) firstCloseError = cErr;
                    }
                }
                else
                {
                    this._threadClosed = true;
                }

                // Close process handle if still open
                if (!this._processClosed && this._hProcess != IntPtr.Zero)
                {
                    if (NativeMethods.CloseHandle(this._hProcess))
                    {
                        this._hProcess = IntPtr.Zero;
                        this._processClosed = true;
                    }
                    else
                    {
                        int cErr = Marshal.GetLastWin32Error();
                        if (firstCloseError == 0) firstCloseError = cErr;
                    }
                }
                else
                {
                    this._processClosed = true;
                }

                // Close job handle if still open
                if (!this._jobClosed && this._hJob != IntPtr.Zero)
                {
                    if (NativeMethods.CloseHandle(this._hJob))
                    {
                        this._hJob = IntPtr.Zero;
                        this._jobClosed = true;
                    }
                    else
                    {
                        int cErr = Marshal.GetLastWin32Error();
                        if (firstCloseError == 0) firstCloseError = cErr;
                    }
                }
                else
                {
                    this._jobClosed = true;
                }

                if (this._threadClosed && this._processClosed && this._jobClosed)
                {
                    this._isClosed = true;
                    error = 0;
                    return true;
                }

                error = firstCloseError != 0 ? firstCloseError : -1;
                return false;
            }
        }

        /// <summary>
        /// Disposes owned resources ONLY when already proven quiescent.
        /// Throws InvalidOperationException if processes remain active or state is uncertain.
        /// </summary>
        public void Dispose()
        {
            lock (this._syncLock)
            {
                if (this._isClosed)
                {
                    return;
                }

                ChildProcessObservation obs = this.ObserveInternal();
                if (!obs.IsQuiescent || (this._created && obs.RootState != ChildRootState.Dead))
                {
                    throw new InvalidOperationException("OwnedChildProcess cannot be disposed while processes are still active or unproven; call TryStopAndClose first.");
                }

                // Quiescent: close remaining handles without forced kill
                int firstCloseError = 0;
                if (!this._threadClosed && this._hThread != IntPtr.Zero)
                {
                    if (NativeMethods.CloseHandle(this._hThread))
                    {
                        this._hThread = IntPtr.Zero;
                        this._threadClosed = true;
                    }
                    else
                    {
                        firstCloseError = Marshal.GetLastWin32Error();
                    }
                }
                else
                {
                    this._threadClosed = true;
                }

                if (!this._processClosed && this._hProcess != IntPtr.Zero)
                {
                    if (NativeMethods.CloseHandle(this._hProcess))
                    {
                        this._hProcess = IntPtr.Zero;
                        this._processClosed = true;
                    }
                    else
                    {
                        if (firstCloseError == 0) firstCloseError = Marshal.GetLastWin32Error();
                    }
                }
                else
                {
                    this._processClosed = true;
                }

                if (!this._jobClosed && this._hJob != IntPtr.Zero)
                {
                    if (NativeMethods.CloseHandle(this._hJob))
                    {
                        this._hJob = IntPtr.Zero;
                        this._jobClosed = true;
                    }
                    else
                    {
                        if (firstCloseError == 0) firstCloseError = Marshal.GetLastWin32Error();
                    }
                }
                else
                {
                    this._jobClosed = true;
                }

                if (this._threadClosed && this._processClosed && this._jobClosed)
                {
                    this._isClosed = true;
                }
                else
                {
                    throw new InvalidOperationException(string.Format("Failed to close quiescent handles during Dispose: Win32 error {0}.", firstCloseError));
                }
            }
        }

        #region Validation and Serialization Helpers

        private static void ValidateDosExecutablePath(string path)
        {
            if (string.IsNullOrWhiteSpace(path))
            {
                throw new ArgumentException("Executable path must not be null or whitespace.", "executablePath");
            }

            if (path.IndexOf('\0') != -1)
            {
                throw new ArgumentException("Executable path must not contain NUL characters.", "executablePath");
            }

            if (path.Length < 4)
            {
                throw new ArgumentException("Executable path is too short: " + path, "executablePath");
            }

            char drive = path[0];
            if (!((drive >= 'A' && drive <= 'Z') || (drive >= 'a' && drive <= 'z')) || path[1] != ':' || path[2] != '\\')
            {
                throw new ArgumentException("Executable path must be a fully-qualified DOS path starting with drive letter: " + path, "executablePath");
            }

            if (path.IndexOf('/') != -1)
            {
                throw new ArgumentException("Executable path must not contain forward slashes: " + path, "executablePath");
            }

            if (path.IndexOf(':', 2) != -1)
            {
                throw new ArgumentException("Executable path must not contain alternate data streams or extra colons: " + path, "executablePath");
            }

            if (path.Contains(".."))
            {
                throw new ArgumentException("Executable path must not contain '..' segments: " + path, "executablePath");
            }

            if (path.EndsWith("\\"))
            {
                throw new ArgumentException("Executable path must not end with a trailing backslash: " + path, "executablePath");
            }

            string[] segments = path.Split('\\');
            for (int i = 1; i < segments.Length; i++)
            {
                string seg = segments[i];
                if (string.IsNullOrEmpty(seg))
                {
                    throw new ArgumentException("Executable path must not contain consecutive backslashes: " + path, "executablePath");
                }
                if (seg == "." || seg == "..")
                {
                    throw new ArgumentException("Executable path must not contain '.' or '..' segments: " + path, "executablePath");
                }
                if (seg.EndsWith(".") || seg.EndsWith(" "))
                {
                    throw new ArgumentException("Path components must not end with trailing dots or spaces: " + path, "executablePath");
                }
                if (seg.IndexOf('~') != -1)
                {
                    throw new ArgumentException("Path components must not contain 8.3 short-name aliases (~): " + path, "executablePath");
                }

                string nameWithoutExt = seg;
                int dotIdx = seg.IndexOf('.');
                if (dotIdx >= 0)
                {
                    nameWithoutExt = seg.Substring(0, dotIdx);
                }
                if (IsReservedDosDeviceName(nameWithoutExt))
                {
                    throw new ArgumentException(string.Format("Path component '{0}' uses a reserved DOS device name: {1}", seg, path), "executablePath");
                }
            }

            string parentDir = Path.GetDirectoryName(path);
            if (string.IsNullOrEmpty(parentDir))
            {
                throw new ArgumentException("Unable to determine directory for path: " + path, "executablePath");
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

            if (!File.Exists(path))
            {
                throw new FileNotFoundException("Executable file does not exist: " + path, path);
            }

            FileInfo fi = new FileInfo(path);
            if ((fi.Attributes & FileAttributes.ReparsePoint) != 0)
            {
                throw new InvalidOperationException("Executable file is a reparse point: " + path);
            }
            if ((fi.Attributes & FileAttributes.Directory) != 0)
            {
                throw new InvalidOperationException("Executable target must be a regular file, not a directory: " + path);
            }

            string expectedCanonical = Path.GetFullPath(path);
            if (!string.Equals(path, expectedCanonical, StringComparison.OrdinalIgnoreCase))
            {
                throw new ArgumentException(string.Format("Executable path is not canonical: expected '{0}', got '{1}'.", expectedCanonical, path), "executablePath");
            }
        }

        private static void ValidateDosWorkingDirectory(string workingDirectory)
        {
            if (string.IsNullOrWhiteSpace(workingDirectory))
            {
                throw new ArgumentException("Working directory must not be null or whitespace.", "workingDirectory");
            }

            if (workingDirectory.IndexOf('\0') != -1)
            {
                throw new ArgumentException("Working directory must not contain NUL characters.", "workingDirectory");
            }

            if (workingDirectory.Length < 4)
            {
                throw new ArgumentException("Working directory is too short or is a drive root: " + workingDirectory, "workingDirectory");
            }

            char drive = workingDirectory[0];
            if (!((drive >= 'A' && drive <= 'Z') || (drive >= 'a' && drive <= 'z')) || workingDirectory[1] != ':' || workingDirectory[2] != '\\')
            {
                throw new ArgumentException("Working directory must be a fully-qualified DOS path starting with drive letter: " + workingDirectory, "workingDirectory");
            }

            if (workingDirectory.IndexOf('/') != -1)
            {
                throw new ArgumentException("Working directory must not contain forward slashes: " + workingDirectory, "workingDirectory");
            }

            if (workingDirectory.IndexOf(':', 2) != -1)
            {
                throw new ArgumentException("Working directory must not contain alternate data streams or extra colons: " + workingDirectory, "workingDirectory");
            }

            if (workingDirectory.Contains(".."))
            {
                throw new ArgumentException("Working directory must not contain '..' segments: " + workingDirectory, "workingDirectory");
            }

            string trimmed = workingDirectory.TrimEnd('\\');
            if (trimmed.Length <= 2)
            {
                throw new ArgumentException("Drive root is not an allowed working directory: " + workingDirectory, "workingDirectory");
            }

            string[] segments = trimmed.Split('\\');
            for (int i = 1; i < segments.Length; i++)
            {
                string seg = segments[i];
                if (string.IsNullOrEmpty(seg))
                {
                    throw new ArgumentException("Working directory must not contain consecutive backslashes: " + workingDirectory, "workingDirectory");
                }
                if (seg == "." || seg == "..")
                {
                    throw new ArgumentException("Working directory must not contain '.' or '..' segments: " + workingDirectory, "workingDirectory");
                }
                if (seg.EndsWith(".") || seg.EndsWith(" "))
                {
                    throw new ArgumentException("Path components must not end with trailing dots or spaces: " + workingDirectory, "workingDirectory");
                }
                if (seg.IndexOf('~') != -1)
                {
                    throw new ArgumentException("Path components must not contain 8.3 short-name aliases (~): " + workingDirectory, "workingDirectory");
                }

                if (IsReservedDosDeviceName(seg))
                {
                    throw new ArgumentException(string.Format("Path component '{0}' uses a reserved DOS device name: {1}", seg, workingDirectory), "workingDirectory");
                }
            }

            if (!Directory.Exists(workingDirectory))
            {
                throw new DirectoryNotFoundException("Working directory does not exist: " + workingDirectory);
            }

            DirectoryInfo curr = new DirectoryInfo(workingDirectory);
            while (curr != null)
            {
                if (!curr.Exists)
                {
                    throw new DirectoryNotFoundException("Ancestor directory does not exist: " + curr.FullName);
                }
                if ((curr.Attributes & FileAttributes.ReparsePoint) != 0)
                {
                    throw new InvalidOperationException("Working directory or ancestor is a reparse point: " + curr.FullName);
                }
                curr = curr.Parent;
            }

            string canonical = Path.GetFullPath(workingDirectory).TrimEnd('\\');
            if (!string.Equals(trimmed, canonical, StringComparison.OrdinalIgnoreCase))
            {
                throw new ArgumentException(string.Format("Working directory is not canonical: expected '{0}', got '{1}'.", canonical, trimmed), "workingDirectory");
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

        /// <summary>
        /// Quotes an argument according to standard Windows CRT rules (CommandLineToArgvW inverse).
        /// </summary>
        public static string QuoteArgumentForWindowsCrt(string arg)
        {
            if (arg == null)
            {
                arg = string.Empty;
            }

            if (arg.IndexOf('\0') != -1)
            {
                throw new ArgumentException("Argument must not contain NUL characters.", "arg");
            }

            StringBuilder sb = new StringBuilder();
            sb.Append('"');

            for (int i = 0; i < arg.Length; i++)
            {
                int backslashCount = 0;
                while (i < arg.Length && arg[i] == '\\')
                {
                    backslashCount++;
                    i++;
                }

                if (i == arg.Length)
                {
                    sb.Append('\\', backslashCount * 2);
                    break;
                }
                else if (arg[i] == '"')
                {
                    sb.Append('\\', backslashCount * 2 + 1);
                    sb.Append('"');
                }
                else
                {
                    sb.Append('\\', backslashCount);
                    sb.Append(arg[i]);
                }
            }

            sb.Append('"');
            return sb.ToString();
        }

        private static string BuildCommandLine(string executablePath, string[] arguments)
        {
            StringBuilder sb = new StringBuilder();
            sb.Append(QuoteArgumentForWindowsCrt(executablePath));

            if (arguments != null)
            {
                for (int i = 0; i < arguments.Length; i++)
                {
                    string arg = arguments[i];
                    if (arg == null)
                    {
                        throw new ArgumentException(string.Format("Argument at index {0} is null.", i), "arguments");
                    }
                    sb.Append(' ');
                    sb.Append(QuoteArgumentForWindowsCrt(arg));
                }
            }

            if (sb.Length > 32766)
            {
                throw new ArgumentException(string.Format("Total command line length ({0}) exceeds maximum allowed 32766 characters.", sb.Length));
            }

            return sb.ToString();
        }

        private static byte[] BuildEnvironmentBlock(IDictionary<string, string> environment)
        {
            if (environment == null || environment.Count == 0)
            {
                // Double NUL terminated empty environment block
                return Encoding.Unicode.GetBytes("\0\0");
            }

            HashSet<string> seenKeys = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            List<KeyValuePair<string, string>> sorted = new List<KeyValuePair<string, string>>(environment.Count);

            foreach (KeyValuePair<string, string> kvp in environment)
            {
                string key = kvp.Key;
                if (string.IsNullOrEmpty(key))
                {
                    throw new ArgumentException("Environment variable name must not be null or empty.");
                }
                if (key.IndexOf('=') != -1)
                {
                    throw new ArgumentException("Environment variable name must not contain '=': " + key);
                }
                if (key.IndexOf('\0') != -1)
                {
                    throw new ArgumentException("Environment variable name must not contain NUL characters: " + key);
                }

                string val = kvp.Value;
                if (val == null)
                {
                    throw new ArgumentException("Environment variable value must not be null for key: " + key);
                }
                if (val.IndexOf('\0') != -1)
                {
                    throw new ArgumentException("Environment variable value must not contain NUL characters for key: " + key);
                }

                if (!seenKeys.Add(key))
                {
                    throw new ArgumentException("Duplicate environment variable name (case-insensitive): " + key);
                }

                sorted.Add(new KeyValuePair<string, string>(key, val));
            }

            sorted.Sort((a, b) => string.Compare(a.Key, b.Key, StringComparison.OrdinalIgnoreCase));

            StringBuilder sb = new StringBuilder();
            for (int i = 0; i < sorted.Count; i++)
            {
                sb.Append(sorted[i].Key);
                sb.Append('=');
                sb.Append(sorted[i].Value);
                sb.Append('\0');
            }
            sb.Append('\0'); // Final terminating NUL

            if (sb.Length > 32767)
            {
                throw new ArgumentException(string.Format("Environment block length ({0} characters) exceeds maximum allowed 32767 characters.", sb.Length));
            }

            return Encoding.Unicode.GetBytes(sb.ToString());
        }

        #endregion

        #region Native Structs and Methods

        private static class NativeMethods
        {
            internal static readonly IntPtr INVALID_HANDLE_VALUE = new IntPtr(-1);

            internal const uint CREATE_SUSPENDED = 0x00000004;
            internal const uint CREATE_UNICODE_ENVIRONMENT = 0x00000400;
            internal const uint CREATE_NO_WINDOW = 0x08000000;

            internal const uint JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x00002000;
            internal const uint JOB_OBJECT_LIMIT_BREAKAWAY_OK = 0x00000800;
            internal const uint JOB_OBJECT_LIMIT_SILENT_BREAKAWAY_OK = 0x00001000;

            internal const int JobObjectBasicAccountingInformation = 1;
            internal const int JobObjectExtendedLimitInformation = 9;

            internal const uint WAIT_OBJECT_0 = 0x00000000;
            internal const uint WAIT_TIMEOUT = 0x00000102;
            internal const uint WAIT_FAILED = 0xFFFFFFFF;
            internal const uint INFINITE = 0xFFFFFFFF;

            internal const ushort IMAGE_FILE_MACHINE_UNKNOWN = 0x0000;
            internal const ushort IMAGE_FILE_MACHINE_AMD64 = 0x8664;

            [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
            internal struct STARTUPINFO
            {
                public int cb;
                public string lpReserved;
                public string lpDesktop;
                public string lpTitle;
                public int dwX;
                public int dwY;
                public int dwXSize;
                public int dwYSize;
                public int dwXCountChars;
                public int dwYCountChars;
                public int dwFillAttribute;
                public int dwFlags;
                public short wShowWindow;
                public short cbReserved2;
                public IntPtr lpReserved2;
                public IntPtr hStdInput;
                public IntPtr hStdOutput;
                public IntPtr hStdError;
            }

            [StructLayout(LayoutKind.Sequential)]
            internal struct PROCESS_INFORMATION
            {
                public IntPtr hProcess;
                public IntPtr hThread;
                public int dwProcessId;
                public int dwThreadId;
            }

            [StructLayout(LayoutKind.Sequential)]
            internal struct FILETIME
            {
                public uint dwLowDateTime;
                public uint dwHighDateTime;
            }

            [StructLayout(LayoutKind.Sequential)]
            internal struct IO_COUNTERS
            {
                public ulong ReadOperationCount;
                public ulong WriteOperationCount;
                public ulong OtherOperationCount;
                public ulong ReadTransferCount;
                public ulong WriteTransferCount;
                public ulong OtherTransferCount;
            }

            [StructLayout(LayoutKind.Sequential)]
            internal struct JOBOBJECT_BASIC_LIMIT_INFORMATION
            {
                public long PerProcessUserTimeLimit;
                public long PerJobUserTimeLimit;
                public uint LimitFlags;
                public UIntPtr MinimumWorkingSetSize;
                public UIntPtr MaximumWorkingSetSize;
                public uint ActiveProcessLimit;
                public UIntPtr Affinity;
                public uint PriorityClass;
                public uint SchedulingClass;
            }

            [StructLayout(LayoutKind.Sequential)]
            internal struct JOBOBJECT_EXTENDED_LIMIT_INFORMATION
            {
                public JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation;
                public IO_COUNTERS IoInfo;
                public UIntPtr ProcessMemoryLimit;
                public UIntPtr JobMemoryLimit;
                public UIntPtr PeakProcessMemoryLimit;
                public UIntPtr PeakJobMemoryLimit;
            }

            [StructLayout(LayoutKind.Sequential)]
            internal struct JOBOBJECT_BASIC_ACCOUNTING_INFORMATION
            {
                public long TotalUserTime;
                public long TotalKernelTime;
                public long ThisPeriodTotalUserTime;
                public long ThisPeriodTotalKernelTime;
                public uint TotalPageFaultCount;
                public uint TotalProcesses;
                public uint ActiveProcesses;
                public uint TotalTerminatedProcesses;
            }

            [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
            internal static extern IntPtr CreateJobObjectW(
                IntPtr lpJobAttributes,
                string lpName
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool SetInformationJobObject(
                IntPtr hJob,
                int JobObjectInformationClass,
                ref JOBOBJECT_EXTENDED_LIMIT_INFORMATION lpJobObjectInformation,
                uint cbJobObjectInformationLength
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool QueryInformationJobObject(
                IntPtr hJob,
                int JobObjectInformationClass,
                ref JOBOBJECT_EXTENDED_LIMIT_INFORMATION lpJobObjectInformation,
                uint cbJobObjectInformationLength,
                out uint lpReturnLength
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool QueryInformationJobObject(
                IntPtr hJob,
                int JobObjectInformationClass,
                ref JOBOBJECT_BASIC_ACCOUNTING_INFORMATION lpJobObjectInformation,
                uint cbJobObjectInformationLength,
                out uint lpReturnLength
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool AssignProcessToJobObject(
                IntPtr hJob,
                IntPtr hProcess
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool IsProcessInJob(
                IntPtr ProcessHandle,
                IntPtr JobHandle,
                [MarshalAs(UnmanagedType.Bool)] out bool Result
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool TerminateJobObject(
                IntPtr hJob,
                uint uExitCode
            );

            [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool CreateProcessW(
                string lpApplicationName,
                StringBuilder lpCommandLine,
                IntPtr lpProcessAttributes,
                IntPtr lpThreadAttributes,
                [MarshalAs(UnmanagedType.Bool)] bool bInheritHandles,
                uint dwCreationFlags,
                IntPtr lpEnvironment,
                string lpCurrentDirectory,
                [In] ref STARTUPINFO lpStartupInfo,
                out PROCESS_INFORMATION lpProcessInformation
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            internal static extern uint ResumeThread(IntPtr hThread);

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool TerminateProcess(
                IntPtr hProcess,
                uint uExitCode
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            internal static extern uint WaitForSingleObject(
                IntPtr hHandle,
                uint dwMilliseconds
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool CloseHandle(IntPtr hObject);

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool GetProcessTimes(
                IntPtr hProcess,
                out FILETIME lpCreationTime,
                out FILETIME lpExitTime,
                out FILETIME lpKernelTime,
                out FILETIME lpUserTime
            );

            [DllImport("kernel32.dll", SetLastError = true)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool IsWow64Process2(
                IntPtr hProcess,
                out ushort pProcessMachine,
                out ushort pNativeMachine
            );

            [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
            [return: MarshalAs(UnmanagedType.Bool)]
            internal static extern bool QueryFullProcessImageNameW(
                IntPtr hProcess,
                uint dwFlags,
                [Out] StringBuilder lpExeName,
                ref uint lpdwSize
            );
        }

        #endregion
    }
}
