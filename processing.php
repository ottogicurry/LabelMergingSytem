<?php
$id = preg_replace("/[^0-9]/", "", $_GET['id']);
$mysqli = new mysqli("127.0.0.1", "root", "T1nyN4m$", "fulfillment");
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
    Echo '<br /><a hred="index.php">Return home</a>.';
    die();
}

if(!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    Echo 'No id provided.  <a href="index.php">Return home</a>.';
    die();
}
?>
<html>
	<head>
		<title>Label Merging System</title>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
		<!--[if lte IE 8]><script src="assets/js/ie/html5shiv.js"></script><![endif]-->
		<link rel="stylesheet" href="assets/css/main.css" />
		<!--[if lte IE 9]><link rel="stylesheet" href="assets/css/ie9.css" /><![endif]-->
		<!--[if lte IE 8]><link rel="stylesheet" href="assets/css/ie8.css" /><![endif]-->

<?
$finished = 0;
$result = $mysqli->query("select distinct status from job_tasks where job_id = $id");
if($result->num_rows == 1) {
    $row = $result->fetch_object();
    if($row->status != 'Finished') {
        echo '<meta http-equiv="refresh" content="5">';
    } else {
        $finished = 1;
    }
} else {
    echo '<meta http-equiv="refresh" content="5">';
}
?>
    </head>
    <body>
        <!-- Wrapper -->
			<div id="wrapper">

				<!-- Main -->
					<div id="main">
						<div class="inner">

							<!-- Header -->
								<header id="header">
									<a href="index.html" class="logo"><strong>Label Merging System</strong></a>
								</header>

							<!-- Banner -->
								<section id="banner">
									<div class="content">
                                        <header>
											<h1>Processing</h1>
											<p>Monitor status/Check for errors</p>
										</header>
                                        <p>Once processing is completed, check the QA reports below.  Go back to correct errors. Create PDF button will appear once processing is completed.  The PDF creation process can also take several minutes, mostly depending on the number of pages.<p>
<a href="index.php"><button>Go back</button></a>
<? if($finished) echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="pdf_label.php?id=' . $id . '"><button>Create PDF</button></a>'; ?><br /><br />
<table border="0" width="90%" cellpadding="20px">
<tr>
<td style="vertical-align: top;" width="30%"> 
FedEx Labels:<br />-
<?
$result = $mysqli->query("SELECT * from job_tasks WHERE name = 'Extract Fedex Data' AND job_id = $id");
$fedex_exists = 1;
if($result->num_rows ==  1) {
    while ($row = $result->fetch_object()){
        //echo '<tr><td>'.$row->job_id.'</td><td>'.$row->ucc_file.'</td><td>'.$row->ps_file.'</td><td>'.$row->fedex_file.'</td><td>'.$row->create_date.'</td><td>'.$row->name.'</td><td>'.$row->status.'</td><td>'.$row->pages_processed.' of '.$row->total_pages.'</td></tr>';
        echo $row->status . " (" . $row->pages_processed . " of " . $row->total_pages . ")";
    }
    
    if($finished) {
        echo "<br /><br />QA Report:<br />";
        $qar = '';
        
        //COUNTS
        $result = $mysqli->query("select page from job_label_fedex where job_id = $id and (inv = '' or inv is null)");
        if($result->num_rows > 0) {
            $qar.="-Missing " . $result->num_rows . " invoice/carton numbers on pages (";
                $missing_arr = array();
                while ($row = $result->fetch_object()){
                    $missing_arr[] = $row->page;
                }
                $qar.= implode(', ', $missing_arr);
            $qar.= ")<br /><br />";
        }
        $result = $mysqli->query("select page from job_label_fedex where job_id = $id and (po = '' or po is null)");
        if($result->num_rows > 0) {
            $qar.= "-Missing " . $result->num_rows . " PO numbers on pages (";
                $missing_arr = array();
                while ($row = $result->fetch_object()){
                    $missing_arr[] = $row->page;
                }
                $qar.= implode(', ', $missing_arr);
            $qar.= ")<br /><br />";
        }
        $result = $mysqli->query("select page from job_label_fedex where job_id = $id and (pt = '' or pt is null)");
        if($result->num_rows > 0) {
            $qar.= "-Missing " . $result->num_rows . " pick ticket numbers on pages (";
                $missing_arr = array();
                while ($row = $result->fetch_object()){
                    $missing_arr[] = $row->page;
                }
                $qar.= implode(', ', $missing_arr);
            $qar.= ")<br /><br />";
        }
        
        
        if($fedex_exists) {
            $result = $mysqli->query("select distinct inv from job_label_ucc where job_id = $id and inv not in (select inv from job_label_fedex where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-UCC invoice/carton exists, but not found in FedEx labels-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= "--" . $row->inv . "<br />";
                }
            $qar.= "<br />";
            }
            $result = $mysqli->query("select distinct po from job_label_ucc where job_id = $id and po != '' and po not in (select po from job_label_fedex where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-Fedex PO exists, but not found in UCC labels-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= "--" . $row->po . "<br />";
                }
            $qar.= "<br />";
            }
            $result = $mysqli->query("select distinct pt from job_label_ucc where job_id = $id and pt not in (select pt from job_label_fedex where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-Fedex pick ticket exists, but not found in UCC labels-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= "--" . $row->pt . "<br />";
                }
            $qar.= "<br />";
            }
        }
        
        $result = $mysqli->query("select distinct inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $id and inv not in (select inv from job_label_fedex where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-Packing slip invoice/carton exists, but not found in Fedex labels-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->inv . "<br />";
            }
            $qar.= "<br />";
        }
        
        $result = $mysqli->query("select distinct po from job_label_ps where job_id = $id and po not in (select po from job_label_fedex where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-Packing slip PO exists, but not found in Fedex labels-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->po . "<br />";
            }
            $qar.= "<br />";
        }
        $result = $mysqli->query("select distinct pt from job_label_ps where job_id = $id and pt not in (select pt from job_label_fedex where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-Packing slip pick ticket exists, but not found in Fedex labels-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->pt . "<br />";
            }
            $qar.= "<br />";
        }
        if($qar == '') $qar = 'Nothing to report';
        echo $qar;
    }
    
} else {
    $fedex_exists = 0;
    echo 'No file found. If you intended to have FedEx labels, please try again (<a href="index.php">Go back</a>)';
}

?>
</td>
<td style="vertical-align: top;" width="30%"> 
UCC:<br />-
<?
$result = $mysqli->query("SELECT * from job_tasks WHERE name = 'Extract UCC Data' AND job_id = $id");
if($result->num_rows ==  1) {
    while ($row = $result->fetch_object()){
        //echo '<tr><td>'.$row->job_id.'</td><td>'.$row->ucc_file.'</td><td>'.$row->ps_file.'</td><td>'.$row->fedex_file.'</td><td>'.$row->create_date.'</td><td>'.$row->name.'</td><td>'.$row->status.'</td><td>'.$row->pages_processed.' of '.$row->total_pages.'</td></tr>';
        echo $row->status . " (" . $row->pages_processed . " of " . $row->total_pages . ")";   
    }
    
    if($finished) {
        echo "<br /><br />QA Report:<br />";
        $qar='';
        //COUNTS
        $result = $mysqli->query("select page from job_label_ucc where job_id = $id and (inv = '' or inv is null)");
        if($result->num_rows > 0) {
            $qar.= "-Missing " . $result->num_rows . " invoice/carton numbers on pages (";
                $missing_arr = array();
                while ($row = $result->fetch_object()){
                    $missing_arr[] = $row->page;
                }
                $qar.= implode(', ', $missing_arr);
            $qar.= ")<br /><br />";
        }
        $result = $mysqli->query("select page from job_label_ucc where job_id = $id and (po = '' or po is null)");
        if($result->num_rows > 0) {
            $qar.= "-Missing " . $result->num_rows . " PO numbers on pages (";
                $missing_arr = array();
                while ($row = $result->fetch_object()){
                    $missing_arr[] = $row->page;
                }
                $qar.= implode(', ', $missing_arr);
            $qar.= ")<br /><br />";
        }
        $result = $mysqli->query("select page from job_label_ucc where job_id = $id and (pt = '' or pt is null)");
        if($result->num_rows > 0) {
            $qar.= "-Missing " . $result->num_rows . " pick ticket numbers on pages (";
                $missing_arr = array();
                while ($row = $result->fetch_object()){
                    $missing_arr[] = $row->page;
                }
                $qar.= implode(', ', $missing_arr);
            $qar.= ")<br /><br />";
        }
        
        if($fedex_exists) {
            $result = $mysqli->query("select distinct inv from job_label_fedex where job_id = $id and inv not in (select inv from job_label_ucc where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-Fedex invoice/carton exists, but not found in UCC labels-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= "--" . $row->inv . "<br />";
                }
            $qar.= "<br />";
            }
            $result = $mysqli->query("select distinct po from job_label_fedex where job_id = $id and po not in (select po from job_label_ucc where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-Fedex PO exists, but not found in UCC labels-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= "--" . $row->po . "<br />";
                }
            $qar.= "<br />";
            }
            $result = $mysqli->query("select distinct pt from job_label_fedex where job_id = $id and pt not in (select pt from job_label_ucc where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-Fedex pick ticket exists, but not found in UCC labels-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= "--" . $row->pt . "<br />";
                }
            $qar.= "<br />";
            }
        }
        
        $result = $mysqli->query("select distinct inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $id and inv not in (select inv from job_label_ucc where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-Packing slip invoice/carton exists, but not found in UCC labels-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->inv . "<br />";
            }
            $qar.= "<br />";
        }
        
        $result = $mysqli->query("select distinct po from job_label_ps where job_id = $id and po not in (select po from job_label_ucc where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-Packing slip PO exists, but not found in UCC labels-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->po . "<br />";
            }
            $qar.= "<br />";
        }
        $result = $mysqli->query("select distinct pt from job_label_ps where job_id = $id and pt not in (select pt from job_label_ucc where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-Packing slip pick ticket exists, but not found in UCC labels-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->pt . "<br />";
            }
            $qar.= "<br />";
        }
        if($qar == '') $qar = 'Nothing to report';
        echo $qar;
    }
} else {
    echo 'No file found. Yaaa, we\'re going to need this one.  Please try again (<a href="index.php">Go back</a>)';
}
?>
</td>
<td style="vertical-align: top;" width="30%"> 
Packing Slip:<br />-
<?
$result = $mysqli->query("SELECT * from job_tasks WHERE name = 'Extract PS Data' AND job_id = $id");
if($result->num_rows ==  1) {
    while ($row = $result->fetch_object()){
        //echo '<tr><td>'.$row->job_id.'</td><td>'.$row->ucc_file.'</td><td>'.$row->ps_file.'</td><td>'.$row->fedex_file.'</td><td>'.$row->create_date.'</td><td>'.$row->name.'</td><td>'.$row->status.'</td><td>'.$row->pages_processed.' of '.$row->total_pages.'</td></tr>';
        echo $row->status . " (" . $row->pages_processed . " of " . $row->total_pages . ")";
    }
        
    if($finished) {
        echo "<br /><br />QA Report:<br />";
        $qar='';
        //COUNTS
        
        $result = $mysqli->query("select page from job_label_ps where job_id = $id and (po = '' or po is null)");
        if($result->num_rows > 0) {
            $qar.= "-Missing " . $result->num_rows . " PO numbers on pages (";
                $missing_arr = array();
                while ($row = $result->fetch_object()){
                    $missing_arr[] = $row->page;
                }
                $qar.= implode(', ', $missing_arr);
            $qar.= ")<br /><br />";
        }
        $result = $mysqli->query("select page from job_label_ps where job_id = $id and (pt = '' or pt is null)");
        if($result->num_rows > 0) {
            $qar.= "-Missing " . $result->num_rows . " pick ticket numbers on pages (";
                $missing_arr = array();
                while ($row = $result->fetch_object()){
                    $missing_arr[] = $row->page;
                }
                $qar.= implode(', ', $missing_arr);
            $qar.= ")<br /><br />";
        }
        
        if($fedex_exists) {
            $result = $mysqli->query("select distinct inv from job_label_fedex where job_id = $id and inv not in (select distinct inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-Fedex invoice/carton exists, but not found in Packing slips-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= "--" . $row->inv . "<br />";
                }
            $qar.= "<br />";
            }
            $result = $mysqli->query("select distinct po from job_label_fedex where job_id = $id and po not in (select po from job_label_ps where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-Fedex PO exists, but not found in Packing slips-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= "--" . $row->po . "<br />";
                }
            $qar.= "<br />";
            }
            $result = $mysqli->query("select distinct pt from job_label_fedex where job_id = $id and pt not in (select pt from job_label_ps where job_id = $id)");
            if($result->num_rows > 0) {
                $qar.= "-Fedex pick ticket exists, but not found in Packing slips-<br />";
                while ($row = $result->fetch_object()){
                    $qar.= $row->pt . "<br />";
                }
            $qar.= "<br />";
            }
        }
        $result = $mysqli->query("select distinct inv from job_label_ucc where job_id = $id and inv not in (select distinct inv from job_label_ps join job_label_ps_detail using (label_ps_id) where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-UCC invoice/carton exists, but not found in Packing slips-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->inv . "<br />";
            }
            $qar.= "<br />";
        }
        
        $result = $mysqli->query("select distinct po from job_label_ucc where job_id = $id and po != '' and po not in (select po from job_label_ps where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-UCC PO exists, but not found in Packing slips-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->po . "<br />";
            }
            $qar.= "<br />";
        }
        
        $result = $mysqli->query("select distinct pt from job_label_ucc where job_id = $id and pt not in (select pt from job_label_ps where job_id = $id)");
        if($result->num_rows > 0) {
            $qar.= "-UCC Pick Ticket exists, but not found in Packing slips-<br />";
            while ($row = $result->fetch_object()){
                $qar.= "--" . $row->pt . "<br />";
            }
            $qar.= "<br />";
        }
        if($qar == '') $qar = 'Nothing to report';
        echo $qar;
    }
} else {
    echo 'No file found. Yaaa, we\'re going to need this one.  Please try again (<a href="upload.php">Go back</a>)';
}

?>
</td>

</tr></table>



									</div>
								
								</section>



						</div>
					</div>

		<!-- Scripts -->
			<script src="assets/js/jquery.min.js"></script>
			<script src="assets/js/skel.min.js"></script>
			<script src="assets/js/util.js"></script>
			<!--[if lte IE 8]><script src="assets/js/ie/respond.min.js"></script><![endif]-->
			<script src="assets/js/main.js"></script>

	</body>
</html>












