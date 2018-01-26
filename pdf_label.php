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
$result = $mysqli->query("select status from job_tasks where job_id = $id and name = 'Create PDF'");
if($result->num_rows == 1) {
    $row = $result->fetch_object();
    if($row->status != 'Finished') {
        echo '<meta http-equiv="refresh" content="5">';
    } else {
        $finished = 1;
    }
} else {
    $mysqli->query("INSERT INTO job_tasks VALUES (null, $id, 'Create PDF', now(), null, null, null, null, null)");
    echo '<meta http-equiv="refresh" content="2">';
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
											<h1>Creating and Merging PDF</h1>
											<p>Download once merged</p>
										</header>
                                        <p>Creating file.  Download link will appear once the file is created.  This may take several minutes. You cannot come back to the page once clicking "Start Over" without waiting for the entire process. Only click if you really mean it.</p>
<a href="index.php"><button>Start Over</button></a>
<? if($finished) echo '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="'. $id . '/final.pdf" download><button>Download PDF</button></a>'; ?><br /><br />
<?
$result = $mysqli->query("select * from job_tasks where job_id = $id and name = 'Create PDF'");
if($result->num_rows == 1) {
    $row = $result->fetch_object();
    echo $row->status . ': ' . $row->pages_processed . ' out of ' . $row->total_pages;
}

?>
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





