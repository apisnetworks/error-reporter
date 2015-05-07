<?php
	$includes = ini_get('include_path');
	ini_set('include_path', dirname(__FILE__) . '../' . PATH_SEPARATOR . $includes);
	// enable backtraces
	define('DEBUG', 1);
	include("Error_Reporter.php");
	// error()/warn()/info()/deprecated() macros	
	include("Error_Reporter/wrapper.php");
	// compatibility stub for non-apnscp applications
	include("Error_Reporter/stub.php");

	try {
		throw new Exception("unhandled");
	}  catch (Exception $e) {
		error("Application error, msg: %s", $e->getMessage());
	}


?>