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

	function test_error()
	{
		return error("error happened");
	}

	function test_info()
	{
		return info("just a friendly reminder");
	}

	if (!test_error()) {
		// expunge the buffer, reset reporter to "OK"
		var_dump(Error_Reporter::flush_buffer());
	}

	if (!test_info()) {
		// nothing
	} else {
		// will report E_OK (0)
		printf("Severity: %d\n", Error_Reporter::get_severity());
	}

?>