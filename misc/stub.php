<?php
	// control inline stack prints
	if (!defined('DEBUG')) {
		define('DEBUG', 0);
	}
	// backtrace html formatting
	if (!defined('IS_ISAPI')) {
		// this can also be adjusted to php_sapi_name() !== "cli"
		define('IS_ISAPI', !defined('STDIN'));
	}

	if (!defined('IS_CLI')) {
		define('IS_CLI', IS_ISAPI^1);
	}

	// whether this request is an AJAX or browser request
	// affects bt formatting
	if (!defined('AJAX')) {
		define('AJAX', IS_ISAPI);
	}

	if (!defined('SERVER_NAME')) {
		define('SERVER_NAME', $_SERVER['HOSTNAME']);
	}

	// pathing used by apnscp control panel
	if (!defined('APNSCP_INSTALL_PATH')) {
		define('APNSCP_INSTALL_PATH', dirname(dirname(__FILE__)));
	}
?>