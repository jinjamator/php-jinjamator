<?php
set_include_path('../');
require_once('jinjamatorclient.php');

$username = "root";
$password = "ciscocisco";

$session = new JinjamatorClient("http://localhost:5000/api");
$session->login($username, $password);

$task = $session->tasks('vendor/generic/ssh/collect_raw_output');
$task->configuration['command'] = "show run";
$task->configuration['output_plugin'] = "console";
$task->configuration['ssh_host'] = "100.76.0.1";
$task->configuration['ssh_username'] = "admin";
$task->configuration['ssh_password'] = "Cisco1Cisco2.";

$job = $task->run_sync();

print_r($job->results()->last()['message']);
