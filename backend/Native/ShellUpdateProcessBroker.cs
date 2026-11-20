namespace PosDesktop.Updates.Native
{
    using System;
    using System.Collections;
    using System.Collections.Generic;
    using System.IO;
    using System.Text;
    using System.Threading;
    using System.Web.Script.Serialization;

    internal static class ShellUpdateProcessBroker
    {
        private static readonly JavaScriptSerializer Json = new JavaScriptSerializer();

        private static void Send(object frame)
        {
            Console.WriteLine(Json.Serialize(frame));
        }

        private static string Describe(Exception error)
        {
            var message = error.GetType().Name + ": " + error.Message;
            return message.Substring(0, Math.Min(message.Length, 600));
        }

        private static int Main(string[] args)
        {
            Console.InputEncoding = new UTF8Encoding(false);
            Console.OutputEncoding = new UTF8Encoding(false);

            OwnedChildProcess owner = null;
            string phase = "initializing";
            bool released = false;
            int result = 0;
            try
            {
                Send(new { @event = "phase", phase });
                if (args.Length != 1)
                    throw new ArgumentException("Expected the verified POS installation directory.");
                var root = Path.GetFullPath(args[0]);
                var executable = Path.Combine(root, "POS Desktop.exe");
                var environment = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                // The supervisor supplied a fresh allowlisted environment to
                // this broker. Forward its exact operating context to POS.
                foreach (DictionaryEntry entry in Environment.GetEnvironmentVariables())
                    environment.Add((string)entry.Key, (string)entry.Value);

                phase = "creating_job";
                Send(new { @event = "phase", phase });
                owner = OwnedChildProcess.Start(executable, new string[0], root, environment);
                phase = "running";
                Send(new { @event = "started", phase, pid = owner.ProcessId });

                using (var input = new StreamReader(Console.OpenStandardInput(), Console.InputEncoding))
                {
                    var next = input.ReadLineAsync();
                    bool exitReported = false;
                    while (!next.IsCompleted)
                    {
                        if (!exitReported && owner.Observe().RootState == ChildRootState.Dead)
                        {
                            Send(new { @event = "exited", code = owner.GetExitedRootCode() });
                            exitReported = true;
                        }
                        Thread.Sleep(100);
                    }

                    if (next.Result == "release")
                    {
                        phase = "releasing";
                        Send(new { @event = "phase", phase });
                        owner.ReleaseHealthyRuntime();
                        released = true;
                        Send(new { @event = "released" });
                    }
                }
            }
            catch (Exception error)
            {
                var failedStart = error as OwnedChildProcessStartException;
                if (failedStart != null && failedStart.RetainedOwner != null)
                    owner = failedStart.RetainedOwner;
                Send(new { @event = "error", phase, error = Describe(error) });
                result = 1;
            }
            finally
            {
                if (!released)
                {
                    phase = "stopping";
                    Send(new { @event = "phase", phase });
                    int error;
                    if (owner == null || owner.TryStopAndClose(15000, out error))
                    {
                        Send(new { @event = "stopped" });
                    }
                    else
                    {
                        Send(new { @event = "error", phase, error = "Candidate job termination was not proved (" + error + ")." });
                        result = 1;
                    }
                }
            }
            return result;
        }
    }
}

