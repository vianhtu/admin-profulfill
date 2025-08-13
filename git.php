<?php
$out = shell_exec('git reset --hard origin/main 2>&1; git pull origin main 2>&1');
echo '<pre>'.$out.'</pre>';