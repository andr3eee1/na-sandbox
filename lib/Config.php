<?php

/**
 * Config class.
 * THIS IS NOT DONE, WE NEED A CONFIG FILE PARSER
 * TODO
 **/

class Config {
  public const PIVOT_ROOT_HELPER_PATH = __DIR__ . '/pivot_root_helper';
  public const CGROUP_DIR_PREFIX = 'na-sandbox-';
  public const CGOUP_PARENT_DIR = '/sys/fs/cgroup';
  public const UNSHAREBIN_PATH = '/usr/bin/unshare';
}
