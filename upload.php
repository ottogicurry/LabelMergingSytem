<?php
if(isset($_POST['f_s'])) {
    $errors = array();
    $have_fedex = 0;
    include 'vendor/autoload.php';

    if(isset($_FILES["ucc"]["tmp_name"]) && $_FILES["ucc"]["tmp_name"] != '') {
        $parser = new \Smalot\PdfParser\Parser();
        try {
            $pdf = $parser->parseFile($_FILES["ucc"]["tmp_name"]);
        } catch(Exception $e) {

        }

        if(!isset($pdf)) {
            $no_ucc = 1;
            $errors['error'][] = 'UCC file not valid pdf';
        } else {
            $no_ucc = 1;
            $pages  = $pdf->getPages();
            if(strlen($pages[0]->getText()) < 1) {
                $errors['error'][] = 'UCC file not valid or has no text';
            }
        }
    } else {
        $errors['error'][] = 'No UCC Labels attached';
    }
        

    if(isset($_FILES["ps"]["tmp_name"]) && $_FILES["ps"]["tmp_name"] != '') {
        $parser = new \Smalot\PdfParser\Parser();
        try {
            $pdf = $parser->parseFile($_FILES["ps"]["tmp_name"]);
        } catch(Exception $e) {
        }

        if(!isset($pdf)) {
            $errors['error'][] = 'Packing Slip file not valid pdf';
        } else {
            $pages  = $pdf->getPages();
            if(strlen($pages[0]->getText()) < 1) {
                $errors['error'][] = 'Packing Slip file not valid or has no text';
            }
        }
    } else {
        $errors['error'][] = 'No Packing Slips attached';
    }


    if(isset($_FILES["fedex"]["tmp_name"]) && $_FILES["fedex"]["tmp_name"] != '') {
        $parser = new \Smalot\PdfParser\Parser();
        try {
            $pdf    = $parser->parseFile($_FILES["fedex"]["tmp_name"]);
        } catch(Exception $e) {
        }
        if(!isset($pdf)) {
            $errors['error'][] = 'Fedex file not valid pdf';
        } else {
            $pages  = $pdf->getPages();
            if(strlen($pages[0]->getText()) > 1) {
                $errors['error'][] = 'Fedex file unexpectedly contains text';
            }
            $have_fedex = 1;
        }

    } else {
        $errors['notice'][] = 'No Fedex Labels attached';
    }

    if(isset($errors['error']) && count($errors['error'] > 0)) {
        //print_r($errors);
    } else {
        
        $mysqli = new mysqli("127.0.0.1", "root", "T1nyN4m$", "fulfillment");
        if ($mysqli->connect_errno) {
            echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
            die();
        }

        $mysqli->query("
            INSERT INTO jobs (ucc_file, ps_file, fedex_file, create_date)
            VALUES (
                '"  . $_FILES["ucc"]["name"] . "',
                '"  . $_FILES["ps"]["name"] . "',
                '"  . $_FILES["fedex"]["name"] . "',
                now()
            )");
        $id = $mysqli->insert_id;
        mkdir($id);
        if($have_fedex) 
            $mysqli->query("INSERT INTO job_tasks VALUES (null, $id, 'Extract Fedex Data', now(), null, null, null, null, null)");
        
        $mysqli->query("INSERT INTO job_tasks VALUES (null, $id, 'Extract UCC Data', now(), null, null, null, null, null)");
        $mysqli->query("INSERT INTO job_tasks VALUES (null, $id, 'Extract PS Data', now(), null, null, null, null, null)");
        

        copy($_FILES["ucc"]["tmp_name"], $id . '/ucc.pdf');
        copy($_FILES["ps"]["tmp_name"], $id . '/ps.pdf');
        copy($_FILES["fedex"]["tmp_name"], $id . '/fedex.pdf');
        header("Location: processing.php?id=$id");
    }
    

}

?><!DOCTYPE HTML>
<!--
	Editorial by HTML5 UP
	html5up.net | @ajlkn
	Free for personal and commercial use under the CCA 3.0 license (html5up.net/license)
-->
<html>
	<head>
		<title>Label Merging System</title>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no" />
		<!--[if lte IE 8]><script src="assets/js/ie/html5shiv.js"></script><![endif]-->
		<link rel="stylesheet" href="assets/css/main.css" />
		<!--[if lte IE 9]><link rel="stylesheet" href="assets/css/ie9.css" /><![endif]-->
		<!--[if lte IE 8]><link rel="stylesheet" href="assets/css/ie8.css" /><![endif]-->
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
											<h1>Upload</h1>
											<p>Add your files here</p>
										</header>
                                        <p>This system supports multi-page PDF documents containing UCC Labels, Packing Slips 
                                        and FedEx Labels contained in separate files. The files are read in a specific way
                                        that may not accept newer formatting. On the next page you will be prompted with 
                                        any issues that arise when the robot attempts to read the files.</p>
										<p>When you click submit, the next page will show you the progress of the breakdown
                                        and reconstruction of the data.  This can take a while!  FedEx labels take a particularly
                                        long time. The more pages the files have the longer it will take.</p>
                                        <?php 
if((isset($errors['error']) && count($errors['error']) > 0) || (isset($errors['notice']) && count($errors['notice']) > 0)) {
    echo "<b>There were issues submitting your files:</b><br />";
}

if(isset($errors['error']) && count($errors['error']) > 0) {
    foreach($errors['error'] as $error) {
        echo "Error: $error<br />";
    }
}

if(isset($errors['notice']) && count($errors['notice']) > 0) {
    foreach($errors['notice'] as $error) {
        echo "Notice: $error<br />";
    }
}
echo "<br />";
?>

                                        <form action="index.php" method="post" enctype="multipart/form-data">
                                            <ul class="actions">    
                                                <li><strong>UCC Labels</strong><br /><input type="file" name="ucc" /></li>
                                                <li><strong>Packing Slips</strong><br /><input type="file" name="ps" /></li>
                                                <li><strong>Fedex Labels</strong><br /><input type="file" name="fedex" /></li>
                                            </ul>
                                            <input type="submit" value="Create Label Sheets" name="f_s">
                                        </form>
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