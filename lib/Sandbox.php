<?php

/**
 * Sandbox class
 */

class Sandbox {
  /** @var ?string The path to the created sandbox directory. */
  private static $sandboxDir = null;

  /** @var ?string The program to execute */
  private static $program = null;

  /** @var bool True, if the sandbox should be cleaned at the end */
  private static $clean = false;

  function __construct(string $root, string $prg, bool $cln = false) {
    if ($root && $prg) {
      self::$program = $prg;
      self::$sandboxDir = $root;
      self::$clean = $cln;

      if (!is_dir(self::$sandboxDir)) {
        mkdir(self::$sandboxDir, 0755, true);
      }

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
      self::$program = './' . $programName; // Path relative to the new root
    } else {
      Util::dieWithHelp('You need to specify a root directory and a program to run.');
    }
  }

  function __destruct() {
    if (self::$clean && self::$sandboxDir && file_exists(self::$sandboxDir)) {
      Util::recursiveRemoveDirectory(self::$sandboxDir);
      self::$sandboxDir = null;
    }
  }

  /**
   * Changes the working directory to the sandbox directory
   */
  public function enter(): bool {
    return @chdir(self::$sandboxDir);
  }

  public function runSandboxed($programArgs) {
    pcntl_exec(self::$program, $programArgs);
  }

  private static function createSandboxFs(): void {
    mkdir(self::$sandboxDir . '/lib');
    mkdir(self::$sandboxDir . '/lib64');
    mkdir(self::$sandboxDir . '/dev');
    mkdir(self::$sandboxDir . '/bin');

    // Create essential device nodes
    shell_exec('mknod -m 666 ' . self::$sandboxDir . '/dev/null c 1 3');
    shell_exec('mknod -m 666 ' . self::$sandboxDir . '/dev/zero c 1 5');
    shell_exec('mknod -m 444 ' . self::$sandboxDir . '/dev/urandom c 1 9');
  }

  private static function copyDependencies(string $program): void {
    $ldd_output = shell_exec("ldd " . escapeshellarg($program));
    if ($ldd_output === null) {
      Util::die("Could not execute ldd on %s", $program);
    }
    
    $matches = [];
    
    // 1. Find and copy all standard libraries (e.g., libc.so.6)
    preg_match_all('/\s*=>\s*([^ ]+)\s*\(/i', $ldd_output, $matches);

    foreach ($matches[1] as $lib) {
      $lib = trim($lib);
      if (empty($lib) || !file_exists($lib)) continue;
      
      $libDir = dirname($lib);
      if (!is_dir(self::$sandboxDir . $libDir)) {
        mkdir(self::$sandboxDir . $libDir, 0755, true);
      }
      copy($lib, self::$sandboxDir . $lib);
    }

    // 2. Find and copy the dynamic linker (e.g., /lib64/ld-linux-x86-64.so.2)
    $matches = [];
    preg_match_all('/^\s*([^ ]+)\s+\(0x[0-9a-f]+\)$/im', $ldd_output, $matches);
    
    if (isset($matches[1][0])) {
      $linker = trim($matches[1][0]);
      if (file_exists($linker)) {
        $linkerDir = dirname($linker);
        if (!is_dir(self::$sandboxDir . $linkerDir)) {
          mkdir(self::$sandboxDir . $linkerDir, 0755, true);
        }
        copy($linker, self::$sandboxDir . $linker);
      }
    }
   }
}
