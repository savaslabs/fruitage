#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Siros\Console\Command\BackupCommand;

$application = new Application();
$application->add(new BackupCommand());
$application->run();
