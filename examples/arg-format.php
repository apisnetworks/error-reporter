<?php
	$includes = ini_get('include_path');
	ini_set('include_path', dirname(__FILE__) . '../' . PATH_SEPARATOR . $includes);
	// enable backtraces
	define('DEBUG', 1);
	include("Error_Reporter.php");
	// error()/warn()/info()/deprecated() macros	
	include("misc/wrapper.php");
	// compatibility stub for non-apnscp applications
	include("misc/stub.php");


	warn("Application error, msg: %s at %d",
		"unhandled exception",
		time()
	);


?>
