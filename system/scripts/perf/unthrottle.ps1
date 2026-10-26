# Developer tool (Step 7g, docs/developer-guide.md "Performance"). Never used on the server.
# Opts ONE process (the php -S listening on -Port, or -ProcessId) out of Windows power
# throttling (EcoQoS), so a measurement runs on the fast cores. Changes no system setting.
param([int]$Port = 0, [int]$ProcessId = 0)

Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
public static class PerfThrottle {
    [StructLayout(LayoutKind.Sequential)]
    public struct State { public uint Version; public uint ControlMask; public uint StateMask; }
    [DllImport("kernel32.dll", SetLastError = true)] public static extern IntPtr OpenProcess(uint access, bool inherit, int pid);
    [DllImport("kernel32.dll", SetLastError = true)] public static extern bool SetProcessInformation(IntPtr handle, int infoClass, ref State info, uint size);
    [DllImport("kernel32.dll")] public static extern bool CloseHandle(IntPtr handle);
    public static bool Off(int pid) {
        IntPtr handle = OpenProcess(0x0200, false, pid);
        if (handle == IntPtr.Zero) return false;
        State s = new State { Version = 1, ControlMask = 0x1 | 0x4, StateMask = 0 };
        bool ok = SetProcessInformation(handle, 4, ref s, (uint)Marshal.SizeOf(s));
        CloseHandle(handle);
        return ok;
    }
}
"@

if ($ProcessId -le 0) {
    $c = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $c) { "no server on port $Port"; exit 1 }
    $ProcessId = $c.OwningProcess
}
"pid $ProcessId unthrottled: $([PerfThrottle]::Off($ProcessId))"
