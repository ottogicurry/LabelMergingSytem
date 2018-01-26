#!/usr/bin/php
<?php

// TO FUTURE DEV
// Yes, this is procedural and follows virtually no best practices
// This is supposed to be a tech demo and was never intended to be permanent
// My recommendation if you are debugging this is to convince your boss
// to merge this with other scripts into a larger system and refactor it.
// I'm going to overcomment this to make it as easy as possible.


exec("ps aux | grep 'parse_ps'", $out);
$instances = 0;
foreach($out as $line) {
    if(strpos($line, '_ps.php')) {
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

// Parse pdf file and build necessary objects.
$parser = new \Smalot\PdfParser\Parser();

// Connect to local db
$mysqli = new mysqli("127.0.0.1", "root", "T1nyN4m$", "fulfillment");

// Infinite loop here is coo because it's actually supposed to be infinite
while(1 == 1) {
    echo ".";
    // look for a job
    $result = $mysqli->query("SELECT * from job_tasks where name = 'Extract PS Data' and start_date is null");
    if($result->num_rows > 0) {
        $row = $result->fetch_object();
        echo "Found a job! Starting...\n"; // We have a job!
        
        // Wait for file to completely upload before starting.
        if(!waitForFile($row)) {
            $mysqli->query("INSERT INTO job_errors (job_id, page, desc) values (".$row->job_id.",0,'Files not uploading')");
            die("Files are not uploading. Check permissions and stuff.\n");
        }

        // Load PDF info
        $pdf = $parser->parseFile($row->job_id . '/ps.pdf');
        $pdf_pages  = $pdf->getPages();
        $page_count = count($pdf_pages);
        
        // We have begun.
        $mysqli->query("UPDATE job_tasks SET start_date = now(), status = 'Processing', total_pages = $page_count WHERE job_task_id = " . $row->job_task_id);
        
        $current_page = 0;
        echo "Starting process.\n";
        foreach ($pdf_pages as $pdf_page) { // Loop through pages and log data per page
            $current_page++;
            echo "\n\nProcessing page $current_page...";
            $lines = $pdf_page->getTextArray();  // grab all text into an array
            
            // This gets the number of unique occurences in the line data
            // This is how we end up knowing which pages are complete and divided. 
            $counts = array_count_values($lines);  
            
            $new_carton_arr = array(); // Reseting array that contains the carton data
            
            // These two scenarios account for two that do not log carton data
            if(!isset($counts['TOTAL FOR CARTON  ']) && !isset($counts['Carton ID'])) { // Nothing, only log page data
                echo "\n$current_page: Page does not contain any carton data\n";
            } elseif(!isset($counts['Carton ID'])) { 
                // I didn't code for this to save time on a scenario that will likely never occur
                // The only way this will happen is if the carton start on the previous page
                // and there are so many lines that it completely fills this page.
                echo "\n$current_page: This scenario was not accounted for. This is likely a major issue.\n";
                $mysqli->query("INSERT INTO job_errors (job_id, page, desc) values (".$row->job_id.",$current_page,'Carton data spans 3 pages')");
            } else {
                
            //The remaining scenarios all log carton data

                // Puts carton seq and carton num into array
                $carton_arr = getCartons($lines);
                $cartons = count($carton_arr);
                
                
                if(!isset($counts['TOTAL FOR CARTON  '])) {
                    echo "\n$current_page: Page has only partial carton.\n";
                    // THIS BLOCK IS ALL ABOUT FINDING THE RELEVANT CARTON LINES
                    // Get the last of the itemized lines
                    $lastline = count($lines);
                    // Get the total amount of itemized lines
                    $numlines = getNumLines($lines, $lastline);
                    // Get the length of the itemized lines
                    $lines_block_len  = ($numlines * 5);
                    // Get the first line of the itemized lines
                    $firstline = $lastline - $lines_block_len;
                    
                    // Determine total qty on this page
                    $totalqty=0;
                    for($startat = $lastline - $numlines; $startat < $lastline; $startat++) {
                        $totalqty+=$lines[$startat];
                    }

                    // Set variables
                    $scc = 0;
                    $scc2 = 0;
                    $new_carton_arr = array();

                    // Loop through carton details
                    foreach($carton_arr as $carton => $carton_num) {
                        echo "C";
                        $new_carton_arr[$carton]['carton_num'] = $carton_num;
                        $new_carton_arr[$carton]['total_qty'] = $totalqty;
                        $line=0;
                        $o_qty=0;
                        while($new_carton_arr[$carton]['total_qty'] > $o_qty) {
                            echo "l";
                            $line++;
                            $new_carton_arr[$carton]['lines'][$line]['style'] = $lines[$firstline + $scc2];
                            $new_carton_arr[$carton]['lines'][$line]['color_cd'] = $lines[$firstline + $scc2 + $numlines];
                            $new_carton_arr[$carton]['lines'][$line]['color'] = $lines[$firstline + $scc2 + ($numlines * 2)];
                            $new_carton_arr[$carton]['lines'][$line]['size'] = $lines[$firstline + $scc2 + ($numlines * 3)];
                            $new_carton_arr[$carton]['lines'][$line]['qty'] = $lines[$firstline + $scc2  + ($numlines * 4)];
                            $new_carton_arr[$carton]['lines'][$line]['pick_qty'] = $new_carton_arr[$carton]['lines'][$line]['qty'];
                            $o_qty += $new_carton_arr[$carton]['lines'][$line]['qty'];
                            $scc2++;
                        }
                        $scc++;
                    }

                } elseif($counts['TOTAL FOR CARTON  '] > $cartons) {
                    echo "\n$current_page: Page has remainder of carton from previous page and ends normally.\n";
                    // THIS BLOCK IS ALL ABOUT FINDING THE RELEVANT CARTON LINES
                    // Get the last of the itemized lines
                    $lastline = count($lines) - ($counts['TOTAL FOR CARTON  '] * 3);
                    // Get the total amount of itemized lines
                    $numlines = getNumLines($lines, $lastline);
                    // Get the length of the itemized lines
                    $lines_block_len  = ($numlines * 5) + $counts['TOTAL FOR CARTON  '];
                    // Get the first line of the itemized lines
                    $firstline = $lastline - $lines_block_len + $counts['TOTAL FOR CARTON  '];

                    // Determine total qty on this page
                    $totalqty=0;
                    for($startat = $lastline - $numlines; $startat < $lastline; $startat++) {
                        $totalqty+=$lines[$startat];
                    }
                    $accounted_for_qty = 0;
                    $qty_count = $firstline - 1;
                    while(is_numeric($lines[$qty_count])) {
                        $accounted_for_qty+=$lines[$qty_count];
                        $qty_count--;
                    }
                    $accounted_for_qty-=$lines[$qty_count + 1];


                    // Set variables
                    $scc = 0;
                    $scc2 = 0;
                    $new_carton_arr = array();

                    $first_key = key($carton_arr);
                    $carton_arr = array(($first_key - 1)=>'fill_later') + $carton_arr;



                    // Loop through carton details
                    $running_qty = 0;
                    foreach($carton_arr as $carton => $carton_num) {
                        echo "C";
                        $new_carton_arr[$carton]['carton_num'] = $carton_num;
                        if($scc==0) {
                            $new_carton_arr[$carton]['total_qty'] = $totalqty - $accounted_for_qty;
                        } else {
                            $new_carton_arr[$carton]['total_qty'] = $lines[($lastline - $lines_block_len + $scc)];
                            $running_qty += $lines[($lastline - $lines_block_len + $scc)];
                        }
                        $line=0;
                        $o_qty=0;
                        while($new_carton_arr[$carton]['total_qty'] > $o_qty || $new_carton_arr[$carton]['total_qty'] < 0) {
                            echo "l";
                            $line++;
                            $new_carton_arr[$carton]['lines'][$line]['style'] = $lines[$firstline + $scc2];
                            $new_carton_arr[$carton]['lines'][$line]['color_cd'] = $lines[$firstline + $scc2 + $numlines];
                            $new_carton_arr[$carton]['lines'][$line]['color'] = $lines[$firstline + $scc2 + ($numlines * 2)];
                            $new_carton_arr[$carton]['lines'][$line]['size'] = $lines[$firstline + $scc2 + ($numlines * 3)];
                            $new_carton_arr[$carton]['lines'][$line]['qty'] = $lines[$firstline + $scc2  + ($numlines * 4)];
                            $new_carton_arr[$carton]['lines'][$line]['pick_qty'] = $new_carton_arr[$carton]['lines'][$line]['qty'];
                            $o_qty += $new_carton_arr[$carton]['lines'][$line]['qty'];
                            $scc2++;
                        }
                        $scc++;
                    }

                } elseif($counts['TOTAL FOR CARTON  '] < $cartons) {
                    echo "\n$current_page: Page has remainder of carton on next page.\n";
                    // Get the last of the itemized lines
                    $lastline = count($lines) - ($counts['TOTAL FOR CARTON  '] * 3);
                    // Get the total amount of itemized lines
                    $numlines = getNumLines($lines, $lastline);
                    // Get the length of the itemized lines
                    $lines_block_len  = ($numlines * 5) + $counts['TOTAL FOR CARTON  '];
                    // Get the first line of the itemized lines
                    $firstline = $lastline - $lines_block_len + $counts['TOTAL FOR CARTON  '];

                    $totalqty=0;
                    for($startat = $lastline - $numlines; $startat < $lastline; $startat++) {
                        $totalqty+=$lines[$startat];
                    }


                    // Set variables
                    $scc = 0;
                    $scc2 = 0;
                    $new_carton_arr = array();

                    // Loop through carton details
                    $running_qty = 0;
                    foreach($carton_arr as $carton => $carton_num) {
                        echo "C";
                        $new_carton_arr[$carton]['carton_num'] = $carton_num;
                        if($counts['TOTAL FOR CARTON  '] == $scc) {
                            $new_carton_arr[$carton]['total_qty'] = $totalqty - $running_qty;
                        } else {
                            $new_carton_arr[$carton]['total_qty'] = $lines[($lastline - $lines_block_len + $scc)];
                            $running_qty += $lines[($lastline - $lines_block_len + $scc)];
                        }
                        $line=0;
                        $o_qty=0;
                        while($new_carton_arr[$carton]['total_qty'] > $o_qty || $new_carton_arr[$carton]['total_qty'] < 0) {
                            echo "l";
                            $line++;

                            $new_carton_arr[$carton]['lines'][$line]['style'] = $lines[$firstline + $scc2];
                            $new_carton_arr[$carton]['lines'][$line]['color_cd'] = $lines[$firstline + $scc2 + $numlines];
                            $new_carton_arr[$carton]['lines'][$line]['color'] = $lines[$firstline + $scc2 + ($numlines * 2)];
                            $new_carton_arr[$carton]['lines'][$line]['size'] = $lines[$firstline + $scc2 + ($numlines * 3)];
                            $new_carton_arr[$carton]['lines'][$line]['qty'] = $lines[$firstline + $scc2  + ($numlines * 4)];
                            $new_carton_arr[$carton]['lines'][$line]['pick_qty'] = $new_carton_arr[$carton]['lines'][$line]['qty'];
                            $o_qty += $new_carton_arr[$carton]['lines'][$line]['qty'];
                            $scc2++;
                        }
                        $scc++;
                    }

                } elseif($counts['TOTAL FOR CARTON  '] == $cartons) { // Pages contain all data
                    echo "\n$current_page: Has matching carton lines.\n";
                    // Get the last of the itemized lines
                    $lastline = count($lines) - ($counts['TOTAL FOR CARTON  '] * 3);
                    // Get the total amount of itemized lines
                    $numlines = getNumLines($lines, $lastline);
                    // Get the length of the itemized lines
                    $lines_block_len  = ($numlines * 5) + $counts['TOTAL FOR CARTON  '];
                    // Get the first line of the itemized lines
                    $firstline = $lastline - $lines_block_len + $counts['TOTAL FOR CARTON  '];

                    // Set variables
                    $scc = 0;
                    $scc2 = 0;
                    $new_carton_arr = array();

                    // Loop through carton details
                    foreach($carton_arr as $carton => $carton_num) {
                        echo "C";
                        $new_carton_arr[$carton]['carton_num'] = $carton_num;
                        $new_carton_arr[$carton]['total_qty'] = $lines[($lastline - $lines_block_len + $scc)];
                        $line=0;
                        $o_qty=0;
                        while($new_carton_arr[$carton]['total_qty'] > $o_qty) {
                            echo "l";
                            $line++;
                            $new_carton_arr[$carton]['lines'][$line]['style'] = $lines[$firstline + $scc2];
                            $new_carton_arr[$carton]['lines'][$line]['color_cd'] = $lines[$firstline + $scc2 + $numlines];
                            $new_carton_arr[$carton]['lines'][$line]['color'] = $lines[$firstline + $scc2 + ($numlines * 2)];
                            $new_carton_arr[$carton]['lines'][$line]['size'] = $lines[$firstline + $scc2 + ($numlines * 3)];
                            $new_carton_arr[$carton]['lines'][$line]['qty'] = $lines[$firstline + $scc2  + ($numlines * 4)];
                            $new_carton_arr[$carton]['lines'][$line]['pick_qty'] = $new_carton_arr[$carton]['lines'][$line]['qty'];
                            $o_qty += $new_carton_arr[$carton]['lines'][$line]['qty'];
                            $scc2++;
                        }
                        $scc++;
                    }
                } else {
                }
            }
            $psInfo = getPsData($lines);
            logData($current_page, $new_carton_arr, $psInfo, $row->job_id);
            $mysqli->query("UPDATE job_tasks SET pages_processed = " . $current_page . " WHERE job_task_id = " . $row->job_task_id);
        }
        fixData($row->job_id);
        $mysqli->query("UPDATE job_tasks SET status = 'Finished', end_date=now() WHERE job_task_id = " . $row->job_task_id);
        echo "\nJob completed\n\n";
    }
    $cur_time = mktime();
    if(date('G') == 20 && ($cur_time - $start_time) > 4000) {
        echo "Dying for maintenance";
    }
    sleep(5);
}

echo "\n";

function getPsData($lines) {
    $psInfo = array();
    $bt_line = array_search('BILL TO:', $lines);
    if($bt_line) {
        $psInfo['client'] = $lines[$bt_line - 1];
        $psInfo['pt'] = $lines[$bt_line - 2];
        //echo "---Client: " . $psInfo['client'] . "\n";
        //echo "---PT: " . $psInfo['pt'] . "\n";
    } else {
        //echo "-=-=-=ERROR. NO BILL TO LINE.=-=-=-\n";
    }


    $po_line = array_search('P.O.:', $lines);
    if($po_line) {
        $psInfo['po'] = $lines[$po_line - 1];
        //echo "---PO: " . $psInfo['po'] . "\n";
    } else {
        //echo "-=-=-=ERROR. NO P.O. LINE.=-=-=-\n";
    }

    return $psInfo;

}


function getCartons($lines) {
    $CID_line = array_search('Carton ID', $lines);
    $carton_arr = array();
    for($cartons = 1; $cartons < 10000; $cartons++) {
        if($lines[$CID_line - $cartons] == 'Carton') {
            break;
        } else {
            $carton_arr[]=$lines[$CID_line - $cartons];
        }
    }
    $carton_arr = array_reverse($carton_arr);

    $i = 0;
    $new_carton_arr = array();
    foreach($carton_arr as $carton) {
        $i++;
        $new_carton_arr[$carton] = $lines[$CID_line + $i];
    }
    return $new_carton_arr;
}


function getNumLines($lines, $lastline) {
    $numlines = 1;
    while($numlines++) {
        if(!is_numeric($lines[$lastline - $numlines])) {
            $numlines--;
            break;
        }
    }
    return $numlines;
}



function display_array($ar) {
    foreach($ar as $carton_seq => $carton) {
        echo $carton_seq . ' (' . $carton['carton_num'] . '): Total Qty: ' . $carton['total_qty'] . "\n";
        foreach($carton['lines'] as $carton_line_seq => $carton_line) {
            echo $carton_line['style'] . ': ';
            echo $carton_line['color'] . ' (';
            echo $carton_line['color_cd'] . ') / ';
            echo $carton_line['size'] . ' - Qty: ';
            echo $carton_line['qty'] . ':';
            echo $carton_line['pick_qty'] . "\n";
        }
        echo "\n";
    }
}

function logData($page, $ar, $ps, $job_id) {
    
    Echo "\nSaving data.\n";
    echo "C";
    global $mysqli;
    if(!isset($ps['po'])) $ps['po'] = '';
    if(!isset($ps['client'])) $ps['client'] = '';
    if(!isset($ps['pt'])) $ps['pt'] = '';
    $query = "INSERT INTO job_label_ps (job_id, client, page, po, pt) VALUES ($job_id, '" . $ps['client'] . "', $page, '" . $ps['po'] . "', '" . $ps['pt'] . "')";
    $mysqli->query($query);
    $label_ps_id = $mysqli->insert_id;
    foreach($ar as $carton_seq => $carton) {
        foreach($carton['lines'] as $carton_line_seq => $carton_line) {
            echo "l";
            $query = "INSERT INTO job_label_ps_detail
                        (label_ps_id, carton_seq, inv, carton_line_seq, style, color_cd, color, size, qty, pick_qty)
                      VALUES
                        ($label_ps_id,
                         $carton_seq,
                         '" . ltrim($carton['carton_num'], '0') . "',
                         $carton_line_seq,
                         '" . $carton_line['style'] . "',
                         '" . $carton_line['color_cd'] . "',
                         '" . $carton_line['color'] . "',
                         '" . $carton_line['size'] . "',
                         '" . $carton_line['qty'] . "',
                         '" . $carton_line['pick_qty'] . "')";
            $mysqli->query($query);
        }

    }
}

function fixData($job_id) {
    global $mysqli;
    $query = "select distinct pt, page  from job_label_ps join job_label_ps_detail using (label_ps_id)  where inv = 'fill_later' and job_id = $job_id order by page";
    $result = $mysqli->query($query);
    while ($row = $result->fetch_object()){
         // get max carton from prev page
         $max_carton_q  = "select max(carton_seq) as carton_seq from job_label_ps join job_label_ps_detail using (label_ps_id)  where job_id = $job_id and pt = '". $row->pt ."' and page = " . ($row->page -1);
         $max_carton  = $mysqli->query($max_carton_q)->fetch_object()->carton_seq;
         
         // get max line from that carton
         $max_carton_line_q = "select max(carton_line_seq) as carton_line_seq from job_label_ps join job_label_ps_detail using (label_ps_id)  where job_id = $job_id and pt = '". $row->pt ."' and page = " . ($row->page -1) . " and carton_seq = $max_carton";
         $max_carton_line = $mysqli->query($max_carton_line_q)->fetch_object()->carton_line_seq;
         
         // get carton num using above params
         $carton_num_q = "select inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id and pt = '". $row->pt ."' and page = " . ($row->page -1) . " and carton_seq = $max_carton and carton_line_seq = $max_carton_line";
         $carton_num = $mysqli->query($carton_num_q)->fetch_object()->inv;
         
         $uq = "UPDATE job_label_ps_detail join job_label_ps using (label_ps_id) SET inv = $carton_num, carton_line_seq = carton_line_seq + $max_carton_line WHERE job_id = $job_id AND pt = '" . $row->pt . "' and inv = 'fill_later' and page = " . $row->page;
         $mysqli->query($uq);
    }
    
    $blanks_exist_q = "select count(distinct po) as blanks_exist from job_label_ps where job_id = $job_id and (po = '' or po is null)";
    $blanks_exist = $mysqli->query($blanks_exist_q)->fetch_object()->blanks_exist;
    if($blanks_exist > 0) {
        echo "We have blank POs!\n";
        $how_many_po_q = "select count(distinct po) as how_many_po from job_label_ps where job_id = $job_id and po != '' and po is not null";
        $how_many_po = $mysqli->query($how_many_po_q)->fetch_object()->how_many_po;
        if($how_many_po == 1) {
            $po_q = "select distinct po as po from job_label_ps where job_id = $job_id and po != '' and po is not null";
            $po = $mysqli->query($po_q)->fetch_object()->po;
            echo "We have only one possible PO ($po)!\n";
            $mysqli->query("UPDATE job_label_ps SET po = '$po' WHERE (po is null or po = '') AND job_id = $job_id");
        } else {
            echo "Error finding PO. Have to update manually.\n";
        }
    }
    
}

function waitForFile($row) {
    $i = 0;
    while(!file_exists($row->job_id . '/ps.pdf')) {
        $i++;
        sleep(1);
        echo "File (" . $row->job_id . '/ps.pdf' . ") doesn't exist yet...\n";
        if($i >= 600) {
            echo "Been waiting on this file for 10 min and nothing. Something's not right..\n";
            return false;
        }
    }
    
    $filesize = filesize($row->job_id . '/ps.pdf');
    echo "File exists. Waiting for upload.\n";
    sleep(1);
    $i = 0;
    while($filesize != filesize($row->job_id . '/ps.pdf')) {
        $i++;
        echo "File still uploading...\n";
        sleep(1);
        if($i >= 600) {
            echo "Umm this file keeps getting bigger for like 10 minutes now. Something's not right..\n";
            return false;
        }
    }
    echo "File done uploading.\n";
    return true;
}
