A fast, out-of-band error reporting facility for PHP.
======

Error Reporter ("ER") evolved from [apnscp](http://apisnetworks.com/apnscp), a hosting control panel developed for Apis Networks. It's built for performance and adaptability. Errors can be raised without terminating control flow. 

* Deduplication: serial errors are ignored
* Fast: in production mode, 60% faster than try-catch
* E-mail PHP errors: any PHP warnings/notices that slip through production can be e-mailed
* Macros: fatal()/error()/warn()/info()/deprecated() wrap clunky ER functions


# Usage
```php
<?php
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
	  // will report "E_INFO"
	  print Error_Reporter::get_severity();
	}
	
?>
```

# Argument formatting
All macros support variable argument formatting following typical [sprintf](http://php.net/sprintf) formatting.

# On-the-fly verbosity
`Error_Reporter::mute_warning()` can be used before entering a block of code that may elicit a PHP warning. Any PHP warnings or notices generated within will be ignored. Once the block is done, enable with `Error_Reporter::unmute_warning()`.

# Using stacks
Debug mode is enabled by defining `DEBUG` in stub.php. Enabling debug mode will print stacks as errors/warnings/info messages are reported. This will impact performance three-fold, so use wisely. `Error_Reporter::print_debug_bt()` displays the current backtrace.

# Evaluating state
* `Error_Reporter::is_error()` checks if the current state is of level error (degraded) 
* `Error_Reporter::get_severity()` is the maximal severity reported: OK (info/debug/deprecated), warning (warn), and error (error)
* `Error_Reporter::downgrade(severity)` downgrade all messages exceeding _severity_ to new _severity_

# E-mailing bug reports
Set `BUG_COPY` to a valid e-mail address
