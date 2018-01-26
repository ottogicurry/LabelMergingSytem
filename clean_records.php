#!/usr/bin/php
<?php
exec("ps aux | grep '_records'", $out);
$instances = 0;
foreach($out as $line) {
    if(strpos($line, '_records.php')) {
        $instances++;
        if($instances > 2) {
            echo "Already running\n";
            die();
        }
    }
}
$mysqli = new mysqli("127.0.0.1", "root", "T1nyN4m$", "fulfillment");
$result = $mysqli->query("SELECT * from job_tasks where name = 'Create PDF' and start_date is null");
?>
