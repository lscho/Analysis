<?php
require_once __DIR__ . '/../../autoload.php';
use Analysis\MysqlBinLog;
$MysqlBinLog = MysqlBinLog::getInstance();
$data = $MysqlBinLog->getLogs();
//print_r($data);
