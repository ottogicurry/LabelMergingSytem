#!/usr/bin/env php
<?php
exec("ps aux | grep 'parse_ucc'", $out);
$instances = 0;
foreach($out as $line) {
    if(strpos($line, '_ucc.php')) {
        $instances++;
        if($instances > 2) {
            echo "Already running\n";
            die();
        }
    }
}
$start_time = mktime();

chdir('/var/www/public/');
// Include Composer autoloader if not already done.
include 'vendor/autoload.php';
$mysqli = new mysqli("127.0.0.1", "root", "T1nyN4m$", "fulfillment");
    
// Parse pdf file and build necessary objects.
$parser = new \Smalot\PdfParser\Parser();

while(1 == 1) {
    $result = $mysqli->query("SELECT * from job_tasks where name = 'Extract UCC Data' and start_date is null");
    if($result->num_rows > 0) {
        Echo "Found one! Starting";
        $row = $result->fetch_object();
        $pdf = $parser->parseFile($row->job_id . '/ucc.pdf');
 
        $pages  = $pdf->getPages();
        $page_count = count($pages);
        $mysqli->query("UPDATE job_tasks SET start_date = now(), status = 'Processing', total_pages = $page_count WHERE job_task_id = " . $row->job_task_id);
        $i = 0;
        $prevline = '';
        foreach ($pages as $page) {
            if(isset($po_num)) unset($po_num);
            if(isset($inv_num)) unset($inv_num);
            if(isset($pt_num)) unset($pt_num);
            if(isset($order_num)) unset($order_num);
            echo '.';
            $i++;
            $pagetext = explode("\n", $page->getText());
            

            $client = $pagetext[1];
            foreach($pagetext as $line) {
                
                
                if(substr($line, 0, 4) == 'FOR:') {
                    $pt_num = $prevline;
                }
                
                if(substr($line, 0, 4) == 'PO#:' && !isset($po_num))  {
                    $po_num = preg_replace("/[^0-9]/", "", $line);
                }
                
                if(substr($prevline, 0, 6) == 'ORDER#')  {
                    $order_num = $line;
                }

                
                $inv_num_clean = str_replace('(', '', $line);
                $inv_num_clean = str_replace(')', '', $inv_num_clean);
                if(strlen($inv_num_clean) == 20 && is_numeric($inv_num_clean))  {
                    $inv_num = $inv_num_clean;
                    
                }
                $prevline = $line;

            }
            if(!isset($po_num)) {
                $inv_num = $pagetext[8];
                $pt_num = substr($pagetext[18], 3);
                if(!isset($order_num)) {
                    $po_num = '';
                } else {
                    $po_num = $order_num;
                }
            }
            if(!isset($inv_num)) {
                print_r($pagetext);
            }

            $mysqli->query("INSERT INTO job_label_ucc VALUES (null, " . $row->job_id. ", '$client', " . $i . ", '".ltrim($inv_num,'0')."', '$po_num', '$pt_num')");
            $mysqli->query("UPDATE job_tasks SET pages_processed = " . $i . " WHERE job_task_id = " . $row->job_task_id);
        }
        echo "done!\nBeginning validation.";
        $mysqli->query("UPDATE job_tasks SET status = 'Validating' WHERE job_task_id = " . $row->job_task_id);
        echo "SELECT * from job_label_ucc where job_id = " . $row->job_id . " order by page";
        $result2 = $mysqli->query("SELECT * from job_label_ucc where job_id = " . $row->job_id . " order by page");
        $prev_page = 0;
        while($row2 = $result->fetch_object()) {
            $diff = $row2->page - $prev_page;
            if($diff > 1) {
                for($i = 1; $i < $diff; $i++) {
                    $mysqli->query("INSERT INTO job_label_ucc_missing_pages VALUES (" . $row->job_id. ", " . ($row2->page + $i) . ")");
                    echo "\nMissing page " . ($row2->page + $i) . "\n";
                }
            }
            $prev_page = $row2->page;
        }
        echo "\nFinished!\n";
        $mysqli->query("UPDATE job_tasks SET status = 'Finished', end_date=now() WHERE job_task_id = " . $row->job_task_id);
    } else {
        $cur_time = mktime();
        if(date('G') == 20 && ($cur_time - $start_time) > 4000) {
            echo "Dying for maintenance";
        }
        echo "Nothing to do :<\n";
        sleep(10);
    }
}

