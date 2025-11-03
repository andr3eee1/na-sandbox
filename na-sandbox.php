#!/usr/bin/php
<?php

require_once 'lib/Config.php';
require_once 'lib/CGroup.php';
require_once 'lib/Sandbox.php';
require_once 'lib/Opt.php';
require_once 'lib/Str.php';
require_once 'lib/Util.php';

Opt::parse($argv);
$out = Util::run();
print_r($out);
printf("\n");

