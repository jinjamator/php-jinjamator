<?php
set_include_path('../');
require_once('jinjamatorclient.php');

$username = "root";
$password = "ciscocisco";

$session = new JinjamatorClient("http://localhost:5000/api");
$session->login($username, $password);

$task = $session->tasks('vendor/generic/ssh/collect_parsed_output');
$task->configuration['command'] = "show inventory";
$task->configuration['ssh_device_type'] = 'cisco_nxos';
//let's generate an excel for additional output_plugin parameters see jinjamator -o excel --help, as the php client does not have explicit output_plugin support.
$task->configuration['output_plugin'] = "excel";
$task->configuration['excel_file_name'] = "show_inventory";
$task->configuration['ssh_host'] = "100.76.0.1";
$task->configuration['ssh_username'] = "admin";
$task->configuration['ssh_password'] = "Cisco1Cisco2.";

$job = $task->run_sync();

// the python data output is still there as log message
print_r($job->results()->last()['message']);

// let's get the generated excel file.

$filename=$job->results()->files()[0];
$job->results()->save_file_to($filename,$filename);