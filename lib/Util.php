<?php

/**
 * Miscellaneous functions for cgroup-based sandboxing.
 **/

class Util {
  /** @var CGroup The Control Group object */
  private static $cgroup = null;

  /** @var Sandbox The Sandbox object */
  private static $sandbox = null;

  public static function recursiveRemoveDirectory($dir): void {
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it,
                 RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()){
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($dir);
  }

  private static function cleanup(): void {
    self::$cgroup = null;
    self::$sandbox = null;
  }
  
  /**
   * Main run function, using the helper methods.
   */
  static function run() {
    // 1. Set up limits and get time offset
    self::$cgroup = new CGroup();
    self::$cgroup->setLimits(Opt::$time, (int)Opt::$memory, Opt::$cpus, Opt::$mems);

    self::$sandbox = new Sandbox(Opt::$root, Opt::$program, Opt::$cleanup);
    
    $startTime = microtime(true);
    $pid = pcntl_fork();

    if ($pid < 0) {
      self::die('Failed to fork a new process.');
    }

    if ($pid === 0) {
      // --- Child Process ---
      // 2. Enter the cgroup
      self::$cgroup->enter(getmypid());
      
      // 3. Chdir to sandbox
      self::$sandbox->enter();

      // 4. Exec the program
      self::$sandbox->runSandboxed(Opt::$programArgs);
      self::die(sprintf('Failed to exec program. (%s)', Opt::$program));
    }

    // --- Parent Process ---
    $endTime = (Opt::$wallTime)
      ? ($startTime + Opt::$wallTime)
      : null;
      
    $cpuTimeLimitMs = (Opt::$time)
      ? (int)(Opt::$time * 1000)
      : null;
      
    $result = [
      'status' => 'UNKNOWN',
      'stats' => [],
    ];

    while (true) {
      $status = null;
      $res = pcntl_waitpid($pid, $status, WNOHANG);

      if ($res === $pid) {
        // --- Process Exited ---
        if (pcntl_wifexited($status)) {
          $exitCode = pcntl_wexitstatus($status);
          $result['status'] = "Exited with code $exitCode";
        } else if (pcntl_wifsignaled($status)) {
          $signal = pcntl_wtermsig($status);
          $result['status'] = "Terminated by signal $signal";
        }
        break; // Exit the loop
      }

      // --- Process Still Running - Check Limits ---

      // 6. Check Wall Time Limit
      if ($endTime && (microtime(true) > $endTime)) {
        posix_kill($pid, SIGKILL);
        $result['status'] = 'Wall time limit exceeded';
        break; // Exit the loop
      }
      
      // 7. Check CPU Time Limit
      if ($cpuTimeLimitMs !== null) {
        $currentCpuTimeMs = self::$cgroup->getRunTimeMs();
        if ($currentCpuTimeMs > $cpuTimeLimitMs) {
          posix_kill($pid, SIGKILL);
          $result['status'] = 'CPU time limit exceeded';
          break; // Exit the loop
        }
      }

      usleep(10000); // sleep for 10ms to avoid busy-waiting
    }
    
    // 8. Get stats
    $result['stats'] = self::$cgroup->getStats();

    // 9. Clean up
    self::cleanup();

    // --- Format and return results ---
    $statLines = [ $result['status'] ];
    foreach ($result['stats'] as $key => $val) {
      $statLines[] = "$key: " . var_export($val, true);
    }
    return implode("\n", $statLines);
  }


  /**
   * Prints an error message and exists.
   *
   * @param string $message The message to print, in printf syntax.
   * @param $arguments Arguments for $message.
   */
  static function die(string $message, ...$arguments) {
    vfprintf(STDERR, $message, $arguments);
    fprintf(STDERR, "\n");
    // Ensure cleanup is attempted even on die
    self::cleanup();
    exit(1);
  }

  /**
   * Same as die(), but also shows how to get additional help.
   */
  static function dieWithHelp(string $message, ...$arguments) {
    vfprintf(STDERR, $message, $arguments);
    fprintf(STDERR, " Please run '%s --help' for usage info.", Opt::$myself);
    fprintf(STDERR, "\n");
    // Ensure cleanup is attempted even on die
    self::cleanup();
    exit(1);
  }
}
