<?php

/**
 * Control Groups class 
 **/

class CGroup{
  /** @var ?string The path to the created cgroup directory. */
  private static $cgroupDir = null;

  /** @var int The initial CPU time (in usec) to offset against. */
  private static $cgTimeOffset = 0;

  /**
   * Creates the unique cgroup directory.
   */
  function __construct() {
    $cgroupParent = Config::CGOUP_PARENT_DIR;
    if (!is_dir($cgroupParent)) {
      Util::die('Cgroup filesystem not found at %s', $cgroupParent);
    }
    if (!is_writable($cgroupParent)) {
      Util::die('Cgroup filesystem not writable at %s. Please run as root.', $cgroupParent);
    }
    
    self::$cgroupDir = $cgroupParent . '/' . Config::CGROUP_DIR_PREFIX . uniqid(rand(), true);
    
    if (!@mkdir(self::$cgroupDir)) {
      Util::die('Failed to create cgroup directory at %s', self::$cgroupDir);
    }
    
    // In cgroup v2, we must enable controllers from the parent
    $parentControl = dirname(self::$cgroupDir) . DIRECTORY_SEPARATOR . 'cgroup.subtree_control';
    if (file_exists($parentControl)) {
      // This is a "best-effort" enable for v2
      @file_put_contents($parentControl, '+cpu +cpuset +memory');
    }
  }

  function __destruct() {
    if (self::$cgroupDir && file_exists(self::$cgroupDir)) {
      // Cgroup cleanup logic
      self::cgWrite('cgroup.kill', '1');
      for ($i = 0; $i < 10; $i++) { //TODO: BAD PRACTICE
        if (@rmdir(self::$cgroupDir)) {
          self::$cgroupDir = null;
          break;
        }
        usleep(10000);
      }
    }
  }

  /**
   * Helper to write to a cgroup file.
   * Silently fails if the file doesn't exist (e.g., cgroup.kill on v1).
   */
  private static function cgWrite(string $file, string $value): bool {
    if (self::$cgroupDir === null) return false;
    $path = self::$cgroupDir . DIRECTORY_SEPARATOR . $file;
    // @ to suppress warnings for optional files (like cgroup.kill)
    return @file_put_contents($path, $value) !== false;
  }

  /**
   * Helper to read from a cgroup file.
   */
  private static function cgRead(string $file): ?string {
    if (self::$cgroupDir === null) return null;
    $path = self::$cgroupDir . DIRECTORY_SEPARATOR . $file;
    if (!file_exists($path) || !is_readable($path)) {
      return null;
    }
    $content = @file_get_contents($path);
    return ($content === false) ? null : trim($content);
  }

  /**
   * Reads the current 'usage_usec' from cpu.stat.
   */
  private static function getRawCpuUsec(): int {
    $stat = self::cgRead('cpu.stat');
    if ($stat === null) {
      // This can happen if the cpu controller isn't enabled
      return 0;
    }
    
    foreach (explode("\n", $stat) as $line) {
      $parts = preg_split('/\s+/', $line, 2);
      if (count($parts) === 2 && $parts[0] === 'usage_usec') {
        return (int)$parts[1];
      }
    }
    trigger_error("Missing usage_usec in cpu.stat", E_USER_WARNING);
    return 0;
  }

  /**
   * Gets the CPU time used by the process, adjusted by the initial offset.
   */
  public function getRunTimeMs(): int {
    if (self::$cgroupDir === null) return 0;
    $usec = self::getRawCpuUsec();
    return (int)(($usec - self::$cgTimeOffset) / 1000);
  }

  /**
   * Gathers all stats from the cgroup files after process exit.
   */
  public function getStats(): array {
    if (self::$cgroupDir === null) return [];
    
    $stats = [
      'time_ms' => null,
      'memory_kib' => null,
      'oom_killed' => false,
    ];

    // Get final CPU time
    if (Opt::$time) {
      $stats['time_ms'] = self::getRunTimeMs();
    }

    // Get peak memory
    $memPeak = self::cgRead('memory.peak');
    if ($memPeak !== null) {
      $stats['memory_kib'] = (int)((int)$memPeak / 1024);
    }

    // Check for OOM kill
    $memEvents = self::cgRead('memory.events');
    if ($memEvents !== null) {
      foreach (explode("\n", $memEvents) as $line) {
        $parts = preg_split('/\s+/', $line, 2);
        if (count($parts) === 2 && $parts[0] === 'oom_kill') {
          if ((int)$parts[1] > 0) {
            $stats['oom_killed'] = true;
          }
          break;
        }
      }
    }
    
    return $stats;
  }

  /**
   * Called by the child process to enter the cgroup.
   */
  public function enter(int $pid): void {
    if (self::$cgroupDir) {
      if (!self::cgWrite('cgroup.procs', (string)$pid)) {
        Util::die("Child failed to enter cgroup %s", self::$cgroupDir);
      }
    }
  }

  /**
   * Sets up the sandbox limits *before* forking.
   */
  public function setLimits(int $time, int $memory, ?string $cpus, ?string $mems): void {
    // Set cgroup limits
    if (self::$cgroupDir) {
      // Set Memory Limits
      if ($memory) {
        self::cgWrite('memory.max', (string)$memory);
        self::cgWrite('memory.swap.max', '0'); // Disable swap
      }

      // Set CPUSet Limits
      if ($cpus) {
        self::cgWrite('cpuset.cpus', $cpus);
      }
      if ($mems) {
        self::cgWrite('cpuset.mems', $mems);
      }
      
      // Get initial CPU time offset
      if ($time) {
        self::$cgTimeOffset = self::getRawCpuUsec();
      }
    }
  }

  public function setTimeOffset(): void {
    self::$cgTimeOffset = self::getRawCpuUsec();
  }
}
