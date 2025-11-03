<?php

/**
 * Sandbox class
 *
 * Uses Linux namespaces via 'unshare' to isolate the program.
 * NOTE: Requires 'unshare' (from util-linux) to be in Config::UNSHAREBIN_PATH.
 */

class Sandbox {
  /** @var ?string The absolute path to the created sandbox directory. */
  private static $sandboxDir = null;

  /** @var ?string The program to execute (relative to sandbox root). */
  private static $program = null;

  /** @var bool True, if the sandbox should be cleaned at the end */
  private static $clean = false;

  /** @var string Path to the unshare utility */
  private static $unsharePath = Config::UNSHAREBIN_PATH;

  function __construct(string $root, string $prg, bool $cln = false) {
    if ($root && $prg) {
      // Check for the 'unshare' utility first
      if (!is_executable(self::$unsharePath)) {
        Util::dieWithHelp(
          'This tool requires the "unshare" command at ' . self::$unsharePath .
          ' (typically from the "util-linux" package).'
        );
      }

      self::$program = $prg;
      self::$sandboxDir = $root;
      self::$clean = $cln;

      // --- Ensure sandboxDir is an absolute path for 'unshare --root' ---
      if (!is_dir(self::$sandboxDir)) {
        // If it's a relative path, make it absolute before creating
        if (self::$sandboxDir[0] !== '/') {
          self::$sandboxDir = getcwd() . '/' . self::$sandboxDir;
        }
        mkdir(self::$sandboxDir, 0755, true);
      } else {
        // If it exists, resolve it to a clean, absolute path
        $real = realpath(self::$sandboxDir);
        if ($real) {
          self::$sandboxDir = $real;
        } else {
          // Fallback if realpath fails (e.g., perms)
          if (self::$sandboxDir[0] !== '/') {
            self::$sandboxDir = getcwd() . '/' . self::$sandboxDir;
          }
        }
      }
      // --- End absolute path logic ---

      // --- Create bind mount (REQUIRED for unshare --root) ---
      // This makes the sandbox directory a mount point.
      shell_exec('mount --bind ' . escapeshellarg(self::$sandboxDir) . ' ' . escapeshellarg(self::$sandboxDir));
      // Make the new mount private to avoid propagation issues
      shell_exec('mount --make-private ' . escapeshellarg(self::$sandboxDir));
      // Re-mount to ensure 'exec' (overriding 'noexec')
      shell_exec('mount -o remount,exec ' . escapeshellarg(self::$sandboxDir));
      // --- End mount logic ---

      if (!is_dir(self::$sandboxDir . '/lib')) {
        self::createSandboxFs();
      }
      
      $programName = basename(self::$program);
      $programPathInSandbox = self::$sandboxDir . '/' . $programName;
      
      if (!file_exists($programPathInSandbox)) {
        self::copyDependencies(self::$program);
        copy(self::$program, $programPathInSandbox);
        chmod($programPathInSandbox, 0755);
      }

      // Also copy /bin/sh and its dependencies, so it can be PID 1
      $shPath = '/bin/sh'; // Path to the shell on the host
      $shDestPath = self::$sandboxDir . $shPath;

      if (!file_exists($shDestPath)) {
        // Ensure the destination directory /bin exists
        $shDir = dirname($shDestPath);
        if (!is_dir($shDir)) {
          // We already created /bin in createSandboxFs(), but good to check
          mkdir($shDir, 0755, true);
        }
        
        // Copy dependencies for /bin/sh
        self::copyDependencies($shPath);
        
        // Copy the /bin/sh program itself
        copy($shPath, $shDestPath);
        chmod($shDestPath, 0755); // Make it executable
      } 

      self::$program = './' . $programName; // Path relative to the new root
    } else {
      Util::dieWithHelp('You need to specify a root directory and a program to run.');
    }
  }

  function __destruct() {
    @shell_exec('umount ' . escapeshellarg(self::$sandboxDir));
    if (self::$clean && self::$sandboxDir && file_exists(self::$sandboxDir)) {
      Util::recursiveRemoveDirectory(self::$sandboxDir);
      self::$sandboxDir = null;
    }
  }

  /**
   * Executes the program inside the namespace-isolated sandbox.
   * This function REPLACES the current PHP process with the 'unshare' command.
   *
   * @param array $programArgs Arguments to pass to the sandboxed program
   */
  public function runSandboxed($programArgs) {
    // Build the full command string to be executed by the shell
    // We must escape the program and its arguments for the shell
    $shellCommand = escapeshellarg(self::$program);
    
    if (is_array($programArgs) && !empty($programArgs)) {
      foreach ($programArgs as $arg) {
        $shellCommand .= ' ' . escapeshellarg($arg);
      }
    }

    // Arguments for 'unshare'
    // This creates a new user, mount, pid, uts, ipc, and net namespace.
    // It maps the current user to root inside the namespace.
    // It then chroots into the sandbox directory and mounts a new /proc.
    $exec_args = [
      '--map-root-user',
      '--fork',           // Fork before execing (required for PID namespace)
      '--pid',            // New PID namespace
      '--uts',            // New UTS namespace (hostname)
      '--ipc',            // New IPC namespace
      '--net',            // New network namespace (minimal loopback)
      '--mount-proc',     // Mount /proc in the new PID namespace
      '--root',           // Chroot into our sandbox directory
      self::$sandboxDir,  // The absolute path to the sandbox
      '/bin/sh',
      '-c',
      $shellCommand,      // The program to run
    ];

    // This will replace the current PHP process with the 'unshare' command.
    // 'unshare' will then set up the namespaces and execute the target program.
    if (!pcntl_exec(self::$unsharePath, $exec_args)) {
      // This will only be reached if pcntl_exec fails
      // We can't use Util::die here as it might not be loaded,
      // but we'll try.
      $error = "FATAL: pcntl_exec failed for " . self::$unsharePath .
             ". Check permissions, path, and subuid/subgid config.";
      error_log($error);
      exit(1); // Exit with an error
    }
  }

  private static function createSandboxFs(): void {
    // Ensure paths are created within the sandbox
    $base = self::$sandboxDir;
    mkdir($base . '/lib', 0755, true);
    mkdir($base . '/lib64', 0755, true);
    mkdir($base . '/dev', 0755, true);
    mkdir($base . '/bin', 0755, true);
    mkdir($base . '/proc', 0755, true); // For --mount-proc

    // Create essential device nodes
    // Note: This still requires privileges (e.g., run as root)
    // to execute mknod during the sandbox setup phase.
    shell_exec('mknod -m 666 ' . escapeshellarg($base . '/dev/null') . ' c 1 3');
    shell_exec('mknod -m 666 ' . escapeshellarg($base . '/dev/zero') . ' c 1 5');
    shell_exec('mknod -m 444 ' . escapeshellarg($base . '/dev/urandom') . ' c 1 9');
  }

  private static function copyDependencies(string $program): void {
    // 1. Get and copy dependencies
    $ldd_output = shell_exec("ldd " . escapeshellarg($program));
    if ($ldd_output === null) {
      Util::die("Could not execute ldd on %s", $program);
    }
    
    $libsToCopy = [];
    
    // Regex 1: Find standard libraries ---
    // Matches '=> /path/to/lib.so.6 ('
    preg_match_all('/\s*=>\s*(.+?)\s*\(/im', $ldd_output, $matches);
    if (!empty($matches[1])) {
      foreach ($matches[1] as $lib) {
        $libsToCopy[] = trim($lib);
      }
    }

    // Regex 2: Find the dynamic linker ---
    // Matches lines that START with a path, like:
    // '/lib64/ld-linux-x86-64.so.2 (0x...)'
    // This avoids 'linux-vdso' (no '/') and 'libc.so.6 => ...' (no '/')
    preg_match_all('/^\s*(\/.*?)\s+\(0x[0-9a-f]+\)$/im', $ldd_output, $matches);
    if (!empty($matches[1])) {
      foreach ($matches[1] as $lib) {
        $libsToCopy[] = trim($lib);
      }
    }
    
    // 2. Copy all found libraries
    $libsToCopy = array_unique($libsToCopy);
    foreach ($libsToCopy as $lib) {
      if (empty($lib) || !file_exists($lib)) {
        // Log a warning maybe? For now, just skip.
        continue;
      }
      
      $libDest = self::$sandboxDir . $lib;
      $libDir = dirname($libDest);
      if (!is_dir($libDir)) {
        mkdir($libDir, 0755, true);
      }
      if (!file_exists($libDest)) {
        copy($lib, $libDest);
        chmod($libDest, 0755);
      }
    }
  }
}
