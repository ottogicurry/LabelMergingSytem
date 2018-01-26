#!/usr/bin/php
<?php

exec("ps aux | grep 'parse_fedex'", $out);
$instances = 0;
print_r($out);
foreach($out as $line) {
    if(strpos($line, '_fedex.php')) {
        $instances++;
        if($instances > 2) {
            echo "Already running\n";
            die();
        }
    }
}
$start_time = mktime(); 
chdir('/var/www/public/');
$mysqli = new mysqli("127.0.0.1", "root", "T1nyN4m$", "fulfillment");
    
while(1 == 1) {
    $result = $mysqli->query("SELECT * from job_tasks where name = 'Extract Fedex Data' and start_date is null");
    if($result->num_rows > 0) {
        echo "\nFound task...\n";
        $row = $result->fetch_object();
        echo "Waiting for UCC...";
        $mysqli->query("UPDATE job_tasks SET status = 'Waiting on UCC' WHERE job_task_id = " . $row->job_task_id);

        $ucc = false;
        while(!$ucc) {
            $ucc_result = $mysqli->query("SELECT status from job_tasks where name = 'Extract UCC Data' and job_id = " . $row->job_id);
            if($ucc_result->num_rows > 0) {
                $ucc_row = $ucc_result->fetch_object();
                if($ucc_row->status == 'Finished') $ucc = true;
            }
            sleep(3);
            echo ".";
        }
        echo "UCC complete\n";
        
        mkdir($row->job_id . "/fedex_images/");
        $page_count = exec("pdfinfo " . $row->job_id . "/fedex.pdf | grep Pages: | awk '{print $2}'");
        echo $page_count . " pages.  Starting";
        $mysqli->query("UPDATE job_tasks SET start_date = now(), status = 'Processing', total_pages = $page_count WHERE job_task_id = " . $row->job_task_id);
        
        $imagick = new Imagick();
        for($i = 0; $i < $page_count; $i++) {
            echo ".";
            $imagick->readImage($row->job_id . '/fedex.pdf[' . $i . ']');
            $inv = $imagick->clone(); // x = 66 to 226; y = 371 to 400
            $inv->cropImage(226, 29, 66, 371);
            $po = $imagick->clone(); // x = 453 to 530; y = 371 to 400
            $po->cropImage(150, 29, 453, 371);
            $trk = $imagick->clone(); // x = 78 to 340; y = 720 to 775
            $trk->cropImage(262, 49, 78, 726);
            $client = $imagick->clone(); // x = 78 to 340; y = 720 to 775
            $client->cropImage(713, 25, 38, 139);
            $pt = $imagick->clone(); // x = 78 to 340; y = 720 to 775
            $pt->cropImage(75, 21, 363, 353);
            
            
            
            $imagick->writeImages($row->job_id . '/fedex_images/converted.' . $i . '.png', false); 
            $inv->writeImages($row->job_id . '/fedex_images/inv.' . $i . '.png', false); 
            $po->writeImages($row->job_id . '/fedex_images/po.' . $i . '.png', false); 
            $trk->writeImages($row->job_id . '/fedex_images/trk.' . $i . '.png', false); 
            $client->writeImages($row->job_id . '/fedex_images/client.' . $i . '.png', false); 
            $pt->writeImages($row->job_id . '/fedex_images/pt.' . $i . '.png', false); 
            
            
            exec("tesseract " . $row->job_id . "/fedex_images/inv.$i.png -psm 7 " . $row->job_id . "/fedex_images/inv.$i digits 2>&1 >/dev/null");
            exec("tesseract " . $row->job_id . "/fedex_images/po.$i.png -psm 7 " . $row->job_id . "/fedex_images/po.$i digits 2>&1 >/dev/null");
            exec("tesseract " . $row->job_id . "/fedex_images/trk.$i.png -psm 7 " . $row->job_id . "/fedex_images/trk.$i digits 2>&1 >/dev/null");
            exec("tesseract " . $row->job_id . "/fedex_images/client.$i.png -psm 7 " . $row->job_id . "/fedex_images/client.$i alphanum 2>&1 >/dev/null");
            exec("tesseract " . $row->job_id . "/fedex_images/pt.$i.png -psm 7 " . $row->job_id . "/fedex_images/pt.$i digits 2>&1 >/dev/null");
            $inv_num = file_get_contents( $row->job_id . "/fedex_images/inv.$i.txt");
            $inv_num = str_replace('4 ','1', $inv_num);
            $inv_num = str_replace(' ','', $inv_num);
            $inv_num = str_replace("\n",'', $inv_num);
            $inv_num = ltrim($inv_num, '0');
            $po_num = file_get_contents( $row->job_id . "/fedex_images/po.$i.txt");
            $po_num = str_replace(' ','', $po_num);
            $po_num = str_replace("\n",'', $po_num);
            $trk_num = file_get_contents( $row->job_id . "/fedex_images/trk.$i.txt");
            $trk_num = str_replace(' ','', $trk_num);
            $trk_num = str_replace("\n",'', $trk_num);
            $client = file_get_contents( $row->job_id . "/fedex_images/client.$i.txt");
            $client = str_replace(' ','', $client);
            $client = str_replace("\n",'', $client);
            $pt = file_get_contents( $row->job_id . "/fedex_images/pt.$i.txt");
            $pt = str_replace(' ','', $pt);
            $pt = str_replace("\n",'', $pt);
            
            $inv_pt_result = $mysqli->query("select inv, pt from job_label_ucc where (inv = '$inv_num' or pt = '$pt') and job_id = " . $row->job_id);
            if($inv_pt_result->num_rows > 0) {
                $inv_pt_row = $inv_pt_result->fetch_object();
                if($inv_pt_row->inv == $inv_num && $inv_pt_row->pt == $pt) {
                    echo "inv and pt # verified.\n";
                } elseif($inv_pt_row->inv == $inv_num) {
                    echo "inv found but pt mismatch, using UCC pt #.\n";
                    $pt = $inv_pt_row->pt;
                } elseif($inv_pt_row->pt == $pt) {
                    echo "pt found but inv mismatch, using UCC inv #.\n";
                    $inv_num = $inv_pt_row->inv;
                } 
            }
            
            $mysqli->query("INSERT INTO job_label_fedex VALUES (null, " . $row->job_id. ", '$client', " . $i . ", '$inv_num', '$po_num', '$trk_num', '$pt')");
            
            
            $mysqli->query("UPDATE job_tasks SET pages_processed = " . ($i + 1) . " WHERE job_task_id = " . $row->job_task_id);
            $imagick = new Imagick();
        }
        echo "done!\nBeginning validation.";
        $mysqli->query("UPDATE job_tasks SET status = 'Validating' WHERE job_task_id = " . $row->job_task_id);
        //echo "SELECT * from job_label_fedex where job_id = " . $row->job_id . " order by page";
        $result2 = $mysqli->query("SELECT * from job_label_fedex where job_id = " . $row->job_id . " order by page");
        $prev_page = 0;
        while($row2 = $result2->fetch_object()) {
            $diff = $row2->page - $prev_page;
            if($diff > 1) {
                for($i = 1; $i < $diff; $i++) {
                    $mysqli->query("INSERT INTO job_label_fedex_missing_pages VALUES (" . $row->job_id. ", " . ($row2->page + $i) . ")");
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
