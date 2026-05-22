<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require(__DIR__ . '/../vendor/autoload.php');

use local_archive\producer;

$courseid = (int)$argv[1];
$userid   = (int)$argv[2];
$mode     = (string)($argv[3] ?? 'multi'); 

if (!$courseid) {
    echo "Usage: php send_task.php <courseid> <userid>\n";
    exit(1);
}

$prod = new producer();
$result = $prod->send_task_auto_by_size($courseid, $userid, [
    'note' => 'Auto-priority based on course size',
], $mode);

echo $result . PHP_EOL;
