<?php
set_time_limit(0);
ignore_user_abort(true);

$descriptors = [
	1 => ['pipe', 'w'],
	2 => ['pipe', 'w']
];
$process = proc_open('git pull origin master', $descriptors, $pipes);
if (is_resource($process)) {
	while (!feof($pipes[1])) {
		echo '<pre>' . fgets($pipes[1]) . '</pre>';
		flush();
	}
	fclose($pipes[1]);
	proc_close($process);
}
?>