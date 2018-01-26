#!/usr/bin/php
<?php
exec("ps aux | grep 'final_pdf'", $out);
$instances = 0;
foreach($out as $line) {
    if(strpos($line, 'final_pdf.php')) {
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
    $result = $mysqli->query("SELECT * from job_tasks where name = 'Create PDF' and start_date is null");
    if($result->num_rows > 0) {
        $row = $result->fetch_object();
        Echo "Found one! Starting";
        $job_id = $row->job_id;

        $page_count = getPageCount($job_id);
        $mysqli->query("UPDATE job_tasks SET start_date = now(), status = 'Processing', total_pages = $page_count WHERE job_task_id = " . $row->job_task_id);
        
        


        createBins($job_id);
        $html = getBatchHeader($job_id);
        $html .= getBatchLines($job_id);
        //$html .= getBatchHeader($job_id);
        file_put_contents($job_id . '/batch_header.html', $html);
        echo "<br />batch_header.html written..";
        exec('xvfb-run -a -s "-screen 0 640x480x16" wkhtmltopdf ' . $job_id . '/batch_header.html ' . $job_id . '/batch_header.pdf');
        unlink($job_id . '/batch_header.html');
        echo "<br />batch_header.pdf written..";

        $inv_arr = getInvs($job_id);
        $usePs = hasOneInvPerPage($job_id);
        $useFedex = hasFedex($job_id);


        $merge = array($job_id . '/batch_header.pdf');
        $i = 0;
        foreach($inv_arr as $inv) {
            $i++;
            if($useFedex) {
                $fedex_page = getFedexPage($job_id, $inv);
            } else {
                $fedex_page = 0;
            }
            
            if($usePs) {
                $ps_page = getPsPage($job_id, $inv);
            } else {
                $ps_page = 0;
            }
            $ucc_page = getUccPage($job_id, $inv);
            $pt_pdf = getPtPage($job_id, $inv);
            
            $pdf = new FPDI('P','in',array(8.5,14));
            $pdf->AddFont('Courier');
            $pdf->SetFont('Courier','', 10.5);

            $pdf->setSourceFile("$job_id/ucc.pdf");
            $tplUcc = $pdf->importPage($ucc_page, '/MediaBox');
            $size = $pdf->getTemplateSize($tplUcc);
            $ucc_height = $size['h'];
            
            $pdf->addPage();
            
            if($ucc_height > 7) {
                $pdf->useTemplate($tplUcc, 0, .5, 4, $ucc_height, false);// ucc left (ALL OF IT)
                
                if($useFedex && $fedex_page !== false) {
                    $pdf->Image($job_id . '/fedex_images/converted.'.$fedex_page . '.png',4,.5,-200);// fedex top right
                }
                $pdf->setSourceFile($pt_pdf);
                $tplPt = $pdf->importPage(1, '/MediaBox');
                $pdf->useTemplate($tplPt, 4, 7.5, 4, 7, true);// pt bottom right
                

            } elseif($ucc_height <= 7 && !$useFedex && $usePs) {
                $pdf->setSourceFile("$job_id/ps.pdf");
                $tplPs = $pdf->importPage($ps_page, '/MediaBox');

                $pdf->useTemplate($tplUcc, 4, 7.5, 4, $ucc_height, false);// ucc bottom right
                $pdf->useTemplate($tplPs, 4, 0.5, 4, 7, true);// PS upper left
                
                $pdf->setSourceFile($pt_pdf);
                $tplPt = $pdf->importPage(1, '/MediaBox');
                $pdf->useTemplate($tplPt, 0, 7.5, 4, 7, true);// pt bottom left
                
                // nothing upper right
            } elseif($ucc_height <= 7 && $useFedex && $usePs) {
                $pdf->setSourceFile("$job_id/ps.pdf");
                $tplPs = $pdf->importPage($ps_page, '/MediaBox');
                $pdf->useTemplate($tplPs, 0, .5, 4, 7, true);// PS upper left
                $pdf->useTemplate($tplUcc, 4, 7.5, 4, $ucc_height, false);// ucc bottom right
                if($fedex_page !== false) {
                    $pdf->Image($job_id . '/fedex_images/converted.'.$fedex_page . '.png',0,7.5,-200);// Fedex bottom left
                }
                // nothing upper right
            } else {
                $pdf->useTemplate($tplUcc, 4, 7.5, 4, $ucc_height, false);// ucc bottom right
                $pdf->setSourceFile($pt_pdf);
                $tplPt = $pdf->importPage(1, '/MediaBox');
                $pdf->useTemplate($tplPt, 4, 0.5, 4, 7, true);// PT upper left
                
                if($useFedex && $fedex_page !== false) {
                    $pdf->Image($job_id . '/fedex_images/converted.'.$fedex_page . '.png',4,.5,-200);//fedex top right
                }
            }
            
            
            
            
            // place the imported page of the snd document:
            
            //if(file_exists("$job_id/final.pdf")) unlink("$job_id/final.pdf");

            $pdf->Output('F', "$job_id/final.$inv.pdf");
            unlink($pt_pdf);
            $merge[] = "$job_id/final.$inv.pdf";
            $mysqli->query("UPDATE job_tasks SET pages_processed = " . $i . " WHERE job_task_id = " . $row->job_task_id);

        }
        $mysqli->query("UPDATE job_tasks SET status='Merging Pages' WHERE job_task_id = " . $row->job_task_id);
        $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile=$job_id/final.pdf ";
        //Add each pdf file to the end of the command
        foreach($merge as $file) {
            $cmd .= $file." ";
        }
        $result = shell_exec($cmd);
        foreach($merge as $file) {
            unlink($file);
        }

        if(file_exists("$job_id/fedex.pdf")) {
            unlink("$job_id/fedex.pdf");
            $fedex_files = scandir($job_id . '/fedex_images');
            foreach($fedex_files as $fn) {
                if(substr($fn, 0, 1) != '.') {
                    unlink($job_id . '/fedex_images/'. $fn);
                }
            }
            rmdir("$job_id/fedex_images");
        }
        if(file_exists("$job_id/ps.pdf")) unlink("$job_id/ps.pdf");
        if(file_exists("$job_id/ucc.pdf")) unlink("$job_id/ucc.pdf");

        echo '<br />Done..<a href="' . $job_id. '/final.pdf" download>download</a>';
        
        echo "\nFinished!\n";
        $mysqli->query("UPDATE job_tasks SET status = 'Finished', end_date=now() WHERE job_task_id = " . $row->job_task_id);
    } else {
        $cur_time = mktime();
        if(date('G') == 20 && ($cur_time - $start_time) > 4000) {
            echo "Dying for maintenance";
        }
        echo "Nothing to do :<\n";
        sleep(5);
    }
}





function hasFedex($job_id) {
    global $mysqli;
    $result = $mysqli->query("select count(*) as count from job_label_fedex where job_id = $job_id");
    $row = $result->fetch_object();
    if($row->count > 0) {
        return true;
    }
    return false;
}

function hasOneInvPerPage($job_id) {
    global $mysqli;
    $result = $mysqli->query("select count(*) as count from (select page, count(*) ct from (select distinct page, inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id) asd group by page) dsa where ct > 1");
    $row = $result->fetch_object();
    if($row->count == 0) {
        $result = $mysqli->query("select count(*) as count from (select inv, count(*) ct from (select distinct page, inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id) asd group by inv) dsa where ct > 1");
        $row = $result->fetch_object();
        if($row->count == 0) {
            return true;
        }
    }
    return false;
}


function getInvs($job_id) {
    global $mysqli;
    $invs = array();
    $result = $mysqli->query("select distinct inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id order by inv");
    while($row = $result->fetch_object()) {
        $invs[] = $row->inv;
    }
    return $invs;
}

function getPsPage($job_id, $inv) {
    global $mysqli;
    $result = $mysqli->query("select distinct page from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id and inv = '$inv'");
    $row = $result->fetch_object();
    return $row->page;
    
}

function getFedexPage($job_id, $inv) {
    global $mysqli;
    $result = $mysqli->query("select page from job_label_fedex where job_id = $job_id and inv = '$inv'");
    if($result->num_rows > 0) {
        $row = $result->fetch_object();
        return $row->page;
    }
    return false;
    
}

function getUccPage($job_id, $inv) {
    global $mysqli;
    $result = $mysqli->query("select page from job_label_ucc where job_id = $job_id and inv = '$inv'");
    $row = $result->fetch_object();
    return $row->page;
}

function getPtPage($job_id, $inv) {
    global $mysqli;
    // Need PT, PO
    $result = $mysqli->query("select distinct po, pt from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id and inv = '$inv' order by inv");
    $row = $result->fetch_object();
    $po = $row->po;
    $pt = $row->pt;
    $text = '<br /><br /><br /><table border="0" width="400px">
<tr><td style="font-family:Arial; font-size:14pt;">'.$inv.'</td><td style="text-align:right;font-family:Arial; font-size:14pt;">Batch ID: ' . ($job_id + 50000) . '</td></tr>
<tr><td>Order # '.$pt.'</td><td style="text-align:right;">PO# ' . $po . '</td></tr></table>
<table border="1" cellspacing="0" width="400px"><tr><td style="text-align:center;width:75;">BIN</td><td style="text-align:center;width:75;">QTY</td><td style="text-align:center;">Style</td><td style="width:25px;"></td></tr>';

    $result = $mysqli->query("select bin, job_label_ps_detail.qty, style, color_cd  from job_label_ps join job_label_ps_detail using (label_ps_id) join job_bins using (style, color_cd, job_id) where job_id = $job_id and inv = '$inv' order by bin");
    $color = '#FFFFFF';
    while($row = $result->fetch_object()) {
        if($color == '#FFFFFF') {
            $color = '#CFCFCF';
        } else {
            $color = '#FFFFFF';
        }
        
        
        $bin = $row->bin;
        $qty = $row->qty;
        $style = $row->style . ' ' . $row->color_cd;
        $text.='<tr><td style="text-align:center;background-color:'.$color.';">' . $bin . '</td><td style="text-align:center;background-color:'.$color.';">' . $qty . '</td><td style="text-align:right;background-color:'.$color.';">' . $style . '&nbsp;</td><td style="background-color:'.$color.';"><input type="checkbox" /></td></tr>';
    }

    file_put_contents($job_id . '/inv_' . $inv . '.html', $text);
    exec('xvfb-run -a -s "-screen 0 640x480x16" wkhtmltopdf -s A6 ' . $job_id . '/inv_' . $inv . '.html ' . $job_id . '/inv_' . $inv . '.pdf');
    unlink($job_id . '/inv_' . $inv . '.html');
    return $job_id . '/inv_' . $inv . '.pdf';
}




function createBins($job_id) {
        global $mysqli;
        $result = $mysqli->query("select * from job_bins where job_id = $job_id");
        if($result->num_rows > 0) {
            echo "Bins already exist.";
            return true;
        } else {
            echo "Creating Bins.";
            $result = $mysqli->query("select * from (select style, color_cd, sum(qty) qty from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id group by style, color_cd) ads order by qty desc, style, color_cd asc");
            $i=0;
            while($row = $result->fetch_object()) {
                $i++;
                $style = $row->style;
                $color_cd = $row->color_cd;
                $qty = $row->qty;
                $bin = 100 + $i;            
                $mysqli->query("INSERT INTO job_bins (job_id, style, color_cd, bin, qty) VALUES ($job_id, '$style', '$color_cd', $bin, $qty)");
            }
            return true;
        }
    return false;
}

function getBatchHeader($job_id) {
    global $mysqli;
    
    //po_num, client, carton_count
    $result = $mysqli->query("select distinct client, po  from job_label_ps where job_id = $job_id");
    $row = $result->fetch_object();
    $client = $row->client;
    $po_num = $row->po;
    
    echo "select count(*) as ct from (select distinct inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id) asdf";
    $result = $mysqli->query("select count(*) as ct from (select distinct inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $job_id) asdf");
    $row = $result->fetch_object();
    $carton_count = $row->ct;
    
    $batch_id = $job_id + 50000;
    $text = '<body>
<center>
<br />
<table cellspacing="0" cellpadding="5" border="0">
<tr><td style="width: 5in; font-family:Arial; font-size:25pt;font-weight:bold;border: 1px solid;">BATCH RECAP REPORT</td><td style="border: 1px solid;"><table><tr><td style="font-family:Arial; font-size:12.5pt;font-weight:bold;width:1in;">BatchId:</td><td style="font-family:Arial; font-size:12.5pt;font-weight:bold;width:1in;">' . $batch_id . '</td></tr></table></td></tr></table><br />
<table cellspacing="0" cellpadding="5" border="0">
<tr><td style="width:1in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid;">Batch Date:</td><td style="font-family:Arial; font-size:11pt;font-weight:bold;width:3in;text-align:center;border: 1px solid;">Tuesday, January 24, 2017</td><td style="width:1in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid;">Batch Name:</td><td style="font-family:Arial; font-size:12.5pt;font-weight:bold;width:3in;text-align:center;border: 1px solid;">AMX_' . strtoupper(substr($client, 0, 2)) . '_' . $po_num . '_' . date('His') . '</td></tr></table><br />
<table cellspacing="0" cellpadding="5" border="0">
<tr><td style="width:1in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid;">Ship Date:</td><td style="font-family:Arial; font-size:11pt;font-weight:bold;width:3in;text-align:center;border: 1px solid;"></td><td style="width:1in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid;">Cancel Date:</td><td style="font-family:Arial; font-size:12.5pt;font-weight:bold;width:3in;text-align:center;border: 1px solid;"></td></tr></table>
<table cellspacing="0" cellpadding="5" border="0">
<tr><td style="width:1.5in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid;">PickTicket Count:</td><td style="font-family:Arial; font-size:11pt;font-weight:bold;width:2.5in;border: 1px solid;">' .$carton_count . '</td><td style="width:4.13in;"></td></tr></table><br />
<table cellspacing="0" cellpadding="0" border="0">
<tr>
<td style="width:2.75in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; text-align: center;">ProductCode</td>
<td style="width:1in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; text-align: center;">Bin#</td>
<td style="width:1in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; text-align: right;padding-right:2px;">Qty</td>
<td style="width:1in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; text-align: center;">Confirmed<br />Qty</td>
<td style="width:2.75in; font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; text-align: center;">CTNS / Routing</td>
</tr>';
    return $text;
    
}

function getBatchLines($job_id) {
    global $mysqli;
    
    //po_num, client, carton_count
    $result = $mysqli->query("select distinct style, color_cd, bin, job_bins.qty  from job_label_ps join job_label_ps_detail using (label_ps_id) join job_bins using (job_id, style, color_cd) where job_id = $job_id");
    $text = '';
    $total_qty = 0;
    $total_style = 0;
    while($row = $result->fetch_object()) {
        $total_style++;
        $style = $row->style;
        $color_cd = $row->color_cd;
        $bin = $row->bin;
        $qty = $row->qty;
        $total_qty += $qty;
        $text .= '
<tr>
<td style="font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; padding-bottom:3px;padding-left:2px;">' . $style . '_' . $color_cd . '</td>
<td style="font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; padding-bottom:3px;padding-left:2px;text-align:center;">' . $bin . '</td>
<td style="font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; padding-bottom:3px;padding-left:2px;padding-right:2px;text-align:right;">' . $qty . '</td>
<td style="font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; padding-bottom:3px;padding-left:2px;"></td>
<td style="font-family:Arial; font-size:11pt;font-weight:bold;border: 1px solid; padding-bottom:3px;padding-left:2px;"></td>
</tr>';
    }
    $text .= '
<tr>
<td colspan="2" style="font-family:Arial; font-size:14pt;font-weight:bold;border: 1px solid; padding-bottom:10px;padding-top:10px;padding-left:2px;text-align:center;">Totals: ' . $total_qty . ' Pcs</td>
<td colspan="2" style="font-family:Arial; font-size:14pt;font-weight:bold;border: 1px solid; padding-bottom:10px;padding-top:10px;padding-left:2px;text-align:center;">0 Ctns</td>
<td style="font-family:Arial; font-size:14pt;font-weight:bold;border: 1px solid; padding-bottom:10px;padding-top:10px;padding-left:2px;text-align:center;">'. $total_style.' Styles</td>
</tr>';

    return $text;
    
}

function getPageCount($job_id) {
    global $mysqli;
    $result = $mysqli->query("select total_pages from job_tasks where job_id = $job_id and name = 'Extract UCC Data'");
    $row = $result->fetch_object();
    return $row->total_pages;
}
