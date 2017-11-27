<?php

	/*
	 * Error Reporter:
	 * A fast, out-of-band error reporting facility
	 *
	 * MIT License
	 *
	 * @author  Matt Saladna <matt@apisnetworks.com>
	 * @license http://opensource.org/licenses/MIT
	 * @version $Rev $Date
	 */

	class Error_Reporter
	{
		/** @link restore_buffer() */
		const E_ALL = 0xFF;
		const E_REPORT = 0x80;
		const E_EXCEPTION = 0x40;
		const E_FATAL = 0x20;
		const E_ERROR = 0x10;
		const E_WARNING = 0x08;
		const E_INFO = 0x04;
		const E_DEPRECATED = 0x02;
		const E_DEBUG = 0x01;
		const E_OK = 0x00;
		// error/warning/info/debug
		const VERBOSE_LEVELS = 4;
		const REPORT_LIMIT = 10;
		// @param string send a copy of trapped bugs
		static private $bug_copy;
		// @param array filters to check before reporting
		static private $filters = ['backtrace' => [], 'report' => []];
		static private $callbacks = [];
		// @var int internal counter to prevent spamming bug reports
		static private $report_counter = 0;

		/**
		 * @var int bitwise addition of registered classes
		 */
		static private $callbackBitmask = 0;
		static private $error_ring;
		static private $error_severity;
		static private $error_idx;
		static private $error_mapping = array(
			self::E_FATAL      => 'fatal',
			self::E_ERROR      => 'error',
			self::E_WARNING    => 'warning',
			self::E_INFO       => 'info',
			self::E_DEPRECATED => 'deprecated',
			self::E_DEBUG      => 'debug',
			self::E_OK         => ''
		);
		static private $do_warn = true;
		static private $max_emit = 0xFF;

		static private $old_php_level = null;
		static private $warn_locker;
		static private $last_php_err;
		static private $error_ctr;
		static private $last_error;
		static private $suppressed_errors = array();
		static private $verbosity = 1;

		/**
		 * Initialize error reporting system & bind handler
		 *
		 * @return bool initialization occurred
		 */
		public static function init(): bool
		{
			static $inited;
			if (null !== $inited) {
				return false;
			}
			if (null === self::$error_severity) {
				self::init_ring();
			}
			set_error_handler(__CLASS__ . '::handle_error', E_ALL);
			set_exception_handler(__CLASS__ . '::handle_exception');
			$inited = true;

			return true;
		}

		private static function init_ring()
		{
			self::$error_ring = array();
			self::$error_idx = null;
			self::$error_severity = self::E_OK;
			self::$report_counter = 0;

			return true;
		}

		/**
		 * Enable bug reports
		 *
		 * @param string $email
		 */
		public static function set_report($email)
		{
			self::$bug_copy = $email;
		}

		/**
		 * Attach a callback for each message logged
		 *
		 * Each callback takes preemption other previously registered callbacks.
		 *
		 * A registered callback that returns true halts normal reporting
		 *
		 * @param int                                      $error_class
		 * @param \Error_Reporter\MessageCallbackInterface $callback
		 */
		public static function add_message_callback(
			int $error_class,
			\Error_Reporter\MessageCallbackInterface $callback
		) {
			self::$callbackBitmask |= $error_class;
			// most recent takes precedence
			array_unshift(self::$callbacks, ['class' => $error_class, 'callback' => $callback]);
		}

		/**
		 * Register a filter to check before reporting
		 *
		 * @param \Error_Reporter\FilterInterface $filter
		 */
		public static function add_filter(\Error_Reporter\FilterInterface $filter)
		{
			if ($filter instanceof \Error_Reporter\ReportFilterInterface) {
				$key = 'report';
			} else {
				if (
					$filter instanceof \Error_Reporter\BacktraceFilterInterface
				) {
					$key = 'backtrace';
				}
			}
			self::$filters[$key][] = $filter;
		}

		public static function handle_exception($ex)
		{
			if (!self::handle_error(self::E_EXCEPTION,
				$ex->getMessage(),
				$ex->getFile(),
				$ex->getLine(),
				$ex
			)) {
				fatal("Unhandled exception (%s:%d) `%s'",
					$ex->getFile(),
					$ex->getLine(),
					$ex->getMessage()
				);

			}
		}

		public static function handle_error(
			$errno,
			$errstr,
			$errfile = null,
			$errline = null,
			$errcontext = null
		) {
			// repeated errors
			$last = self::$last_error;
			if ((error_reporting() & $errno) === 0) {
				return true;
			}

			if (isset($last['errctr'])) {
				if ($errline === $last['errline'] && $errfile === $last['errfile'] &&
					$errno === $last['errno']) {
					self::$last_error['errctr']++;

					return true;
				} else {
					if ($last['errctr']) {
						self::log('[last message repeated %d times]', $last['errctr']);
					}
				}

			}

			$last_error = array(
				'errline' => $errline,
				'errfile' => $errfile,
				'errno'   => $errno,
				'errstr'  => $errstr,
				'errctr'  => 0
			);

			// duplicate error
			if (self::$last_error === $last_error) {
				return false;
			}

			self::$last_error = $last_error;

			$nlbr = "\n";

			// @TODO segfaults - get the error before seg fault
			if ($errno === self::E_EXCEPTION && is_object($errcontext)) {
				$stackpos = 2;
				$bt = self::parse_debug_bt(0, -1, $errcontext->getTrace());
			} else {
				$stackpos = 1;
				$bt = self::get_debug_bt(1);
			}

			$method = self::get_caller($stackpos);
			if (!self::_report_error($method, $errno, $errstr, $errfile, $errline)) {
				return true;
			}
			foreach (self::$filters['report'] as $filter) {
				if ($filter->filter($errno, $errstr, $errfile, $errline, $errcontext)) {
					return true;
				}
			}

			if (self::$callbackBitmask & $errno) {
				$cbbt = debug_backtrace(false);
				foreach (self::$callbacks as $cb) {
					if (!$cb['class'] & $errno) {
						continue;
					}
					if ($cb['callback']->display($errno, $errstr, $errfile, $errline, $errcontext, $cbbt)) {
						return true;
					}
				}
			}

			$repstr = self::errno2str($errno) . ': ' . $errstr . ' ' . $nlbr . ($errfile ? '[' . $errfile . ':' . $errline . ']' . $nlbr : '');
			if ($errno === self::E_FATAL || self::is_verbose($errno)) {

				/**
				 * Disallow squealching fatal errors
				 */
				if (IS_ISAPI) {
					$repstr = '<pre>' . $repstr . '</pre>' . '<br /><br />';
				}
				if (IS_CLI && self::is_verbose($errno)) {
					self::log($repstr . "\n\n%s", array(self::get_debug_bt()));
				}
				print $repstr;
				if (self::is_verbose($errno)) {
					// don't print backtrace to public
					self::print_debug_bt($bt);
				}
			}

			if (self::$bug_copy && self::$report_counter < self::REPORT_LIMIT) {
				self::$report_counter++;
				$msg = self::errno2str($errno) . ": $errstr [$errfile:$errline]";

				$auth = Auth::profile();
				$subject = basename($errfile) . ': ' . $method . '()';
				if (null !== $errline) {
					$subject .= ':' . $errline;
				}
				$body = SERVER_NAME . ":\n\n" . $msg . "\n" . $bt .
					"\nMODE: " . (IS_CLI ? 'CLI' : 'SAPI') . "\n\n---\n";
				Mail::send(self::$bug_copy,
					$subject,
					$body .
					"POST:\n" . var_export($_POST, true) . "\n\nSERVER:\n" . var_export($_SERVER, true) . "\n\n" .
					(IS_DAV ? "SERVER:\n" . var_export($_SERVER, true) . "\n\n" : '') .
					"AUTH:\n" . var_export($auth, true),
					'Precedence: bulk'
				);
			}

			self::$last_php_err = $errstr;

			return true;
		}

		public static function log($msg, $args = array())
		{
			if ($args) {
				$msg = vsprintf($msg, $args);
			}
			$msg = '[' . date('D M d H:i:s Y') . '] ' . $msg;
			file_put_contents(
				INCLUDE_PATH . '/storage/logs/start.log',
				$msg . "\n",
				FILE_APPEND
			);
		}

		public static function parse_debug_bt($offset = 0, $max = -1, $bt = null)
		{
			$debug = function_exists('is_debug') && is_debug();
			$strip = 0;
			$eol = "\n";
			$btdump = array();
			if (null === $bt) {
				$bt = debug_backtrace(false);
				array_shift($bt);
			}

			if (!is_int($offset)) {
				fatal("non-integer argument passed to offset, `%s'", $offset);
			} else {
				if ($offset < 0) {
					fatal("non-positive integer passed to offset, `%d'", $offset);
				}
			}
			if ($max < 1) {
				$max = 99;
			}

			for ($i = $offset, $szbt = min(sizeof($bt), $max); $i < $szbt; $i++) {
				$str = '';

				$ptr = $bt[$i];
				$str .= sprintf('%2d%s. ', $i - $offset, (IS_CLI ? 'B' : ''));
				if (isset($ptr['class'])) {
					$str .= $ptr['class'];
				}
				if (isset($ptr['type'])) {
					$str .= $ptr['type'];
				}
				if (isset($ptr['function'])) {
					$str .= $ptr['function'];
				}
				$str .= '(';
				$args = isset($ptr['args']) ? $ptr['args'] : array();
				if ($args) {
					$n = 0;
					$prevDepth = 0;
					$outer = new RecursiveArrayIterator($args);
					$itr = new RecursiveIteratorIterator(
						$outer,
						RecursiveIteratorIterator::SELF_FIRST
					);
					// duplicate first arg (bug?)
					$itr->next();

					$nesting = 0;
					while ($itr->valid()) {
						$n++;
						$key = $itr->key();
						$depth = $itr->getDepth();

						$val = /*$depth != $prevDepth ?
						$itr->getSubIterator()->current() :*/
							$itr->current();
						if ($n > 20) {
							break;
						}
						if ($prevDepth > $depth) {
							$str .= '], ';
							$nesting--;
						}
						if (($depth == 1 && is_array($val)) || ($prevDepth < $depth && $depth <= 3)) {
							$str .= '[';
							$nesting++;
						}
						$prevDepth = $depth;
						$var = '';
						if (is_array($val)) {
							if ($depth > 3) {
								$str .= 'Array';
								$nesting--;
							}
						} else {
							if ($key && !is_int($key)) {
								$str .= $key . ':';
							}
							if (is_string($val)) {
								if (isset($val[1]) && ord($val) < 10) {
									$var = '<binary>';
								} else {
									if ($debug && strlen($val) > 512) {
										$var = '"' . substr($val, 0, 512) . '..."';
									} else {
										$var = '"' . $val . '"';
									}
								}
							} else {
								if (is_bool($val)) {
									$var = $var ? 'true' : 'false';
								} else {
									if (is_numeric($val)) {
										$var = intval($val);
										if (is_object($itr->getSubIterator()->current())) {
											$var = 'Array(' . get_class($itr->getSubIterator()->current()) . ')';
										}
									} else {
										if (is_object($val) && is_callable($val)) {
											// closure
											break;
										} else {
											if (is_object($val)) {

												// prevent class/object enumeration
												$var = get_class($val);
												if ($itr->hasChildren()) {
													$nchildren = 0;
													$subdepth = $itr->getDepth();
													while ($itr->valid()) {
														if ($itr->hasChildren()) {
															try {
																$children = $itr->getChildren();
																$nchildren += $children->count();
															} catch (InvalidArgumentException $e) {
																$nchildren++;
															}
														}
														$nchildren--;
														if ($nchildren < 0) {
															break;
														}
														$itr->next();
													}
												}
											} else {
												if (is_null($val)) {
													$var = 'null';
												} else {
													$var = 'undefined';
													if (is_resource($val)) {
														$var = get_resource_type($val);
													}
												}
											}
										}
									}
								}
							}

							$str .= $var;
							$n++;
						}
						// handle "Overloaded object of type SimpleXMLElement..."
						$itr->next();
						if (!$itr->valid()) {
							break;
						} else {
							if ($n > 50) {
								$str .= ', ...';
								break;
							}
						}
						if ($var && !is_array($val) && $itr->getDepth() >= $depth) {
							$str .= ', ';
						}

					}
					if ($nesting > 0) {
						$str .= str_repeat(']', $nesting);
					}
				}
				$str .= ")\n\t";

				if (isset($ptr['file'])) {
					$str .= '[' . substr($ptr['file'], $strip) . ':' . $ptr['line'] . ']';
				} else {
					$str .= '[n/a]';
				}

				$str .= $eol;
				$btdump[] = $str;
			}

			//$btdump[] = $eol;
			return implode('', $btdump);
		}

		public static function get_debug_bt($offset = 0, $max = 0)
		{
			//$bt   = debug_backtrace(false);
			// +1 -> exclude get_debug_bt
			return self::parse_debug_bt($offset, $max);
		}

		/**
		 * Fetch method from stack
		 *
		 * Look up the callstack and return the n-th caller
		 * where n = 0 is the current function.  afi
		 * methods are ignored
		 *
		 * @param  int    $stack_pos initial stack position
		 * @param  string $filter    optional pcre filter to not match
		 * @return string            method name
		 */
		public static function get_caller($stack_pos = 1, $filter = null)
		{
			// 0 is get_caller
			$stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $stack_pos + 2);
			$szstack = sizeof($stack);
			$caller = $method = 'unknown';

			$i = $stack_pos + 1;
			if ($stack_pos > $szstack) {
				return self::add_error($stack_pos . ': stack depth out of bounds');
			}
			// while stack_pos > 0 iterate stack
			// stack_pos == 0 -> caller
			while ($stack_pos >= 0 && $i < $szstack) {

				$caller = $stack[$i];
				// call wraps around two functions typically
				if ($caller['function'] == '__call') {
					$i += 2;
					continue;
					//skip afi proxy
				}
				$class = isset($caller['class']) ? $caller['class'] . '::' : '';
				$func = isset($caller['function']) ? $caller['function'] : $caller;
				$method = $class . $func;

				if ($filter && preg_match($filter, $method)) {
					$i++;
					continue;
				}

				foreach (self::$filters['backtrace'] as $cb) {
					if ($cb->filter($caller)) {
						$i++;
						continue;
					}
				}


				$stack_pos--;
			}

			return $method;
		}

		public static function add_error($message, $fmt = array()): bool
		{
			if (self::is_verbose(self::E_ERROR)) {
				$caller = self::get_caller(2);
				$message = $caller . ': ' . $message;
			}

			return self::append_msg($message, $fmt, self::E_ERROR);
		}

		/**
		 * Print backtrace on message class
		 *
		 * @param int $errno
		 * @return bool
		 */
		protected static function is_verbose(int $errno): bool
		{
			if (!self::$verbosity) {
				return false;
			}

			return self::VERBOSE_LEVELS - self::$verbosity < log($errno, 2);
		}

		/**
		 * handle error ring insertion
		 *
		 * @param string $message reporting message
		 * @param array  $fmt     optional format variables
		 * @param int    $class   message class
		 *
		 * @return bool  false when error logged, true when omitted
		 */
		private static function append_msg($message, $fmt, $class): bool
		{
			if (!is_int($class) || $class > self::E_FATAL) {
				fatal('invalid error class ' . $class);
			}
			if (!self::$do_warn && ($class === E_WARNING || $class === self::E_WARNING)) {
				return true;
			} else if ($class > self::$max_emit) {
				return true;
			}
			if ($fmt) {
				$message = vsprintf($message, $fmt);
			}
			// avoid duplicate error messages
			// @TODO use deprecated() macro
			if (null !== self::$error_idx &&
				self::$error_ring[self::$error_idx]['message'] === $message &&
				self::$error_ring[self::$error_idx]['severity'] === $class
			) {
				return false;
			}

			$error = array(
				'message'  => $message,
				'severity' => $class,
				'caller'   => self::get_caller(),
				'bt'       => null
			);

			// @TODO - silence all debug output?
			if (self::is_verbose($class)) {
				printf("%-8s: %s\n", strtoupper(self::errno2str($class)), $message);
			}
			/**
			 * Print the backtrace,
			 * but not for AJAX requests since this affects parsing
			 */
			if (self::is_verbose($class)) {
				$bt = self::get_debug_bt(2);
				$error['bt'] = $bt;
				if (IS_ISAPI) {
					print '<pre><code class="backtrace">' . htmlentities($bt, ENT_QUOTES) . '</code></pre>';
				} else {
					print $bt . PHP_EOL;
				}
			}

			self::$error_ring[] = $error;

			if (!isset(self::$error_ctr[$class])) {
				self::$error_ctr[$class] = 0;
			}

			self::$error_ctr[$class]++;
			self::$error_idx = null === self::$error_idx ? 0 : self::$error_idx++;
			self::$error_severity |= $class;

			return false;

		}

		/**
		 * Convert error constant into string
		 *
		 * @param int $errno
		 * @return string
		 */
		public static function errno2str($errno)
		{
			switch ($errno) {
				case E_USER_WARNING:
				case E_WARNING:
				case self::E_WARNING:
					return 'WARNING';
				case E_USER_NOTICE:
				case E_NOTICE:
					return 'NOTICE';
				case E_STRICT:
					return 'STRICT';
				case E_RECOVERABLE_ERROR:
					return 'NONFATAL ERROR';
				case E_USER_DEPRECATED:
				case E_DEPRECATED:
					return 'DEPRECATED';
				case self::E_EXCEPTION:
					return 'EXCEPTION';
				case self::E_FATAL:
					return 'FATAL';
				case self::E_INFO:
					return 'INFO';
				case self::E_ERROR:
					return 'ERROR';
				case self::E_REPORT:
					return 'INTERNAL REPORT';
				default:
					return 'UNKNOWN (' . dechex($errno) . ')';
			}
		}

		/**
		 * Check if PHP error should be reported
		 *
		 * @param string $method
		 * @param int    $errno
		 * @param string $errstr
		 * @param string $errfile
		 * @param int    $errline
		 * @return bool
		 */
		private static function _report_error($method, $errno, $errstr, $errfile, $errline)
		{
			if (!isset(self::$suppressed_errors[$method])) {
				return true;
			}

			$filters = self::$suppressed_errors[$method];
			foreach ($filters as $filter) {
				if (~$filter['errno'] & $errno) {
					continue;
				}
				if ($filter['errstr'] &&
					!preg_match('/' . preg_quote($filter['errstr'], '/') . '/', $errstr)
				) {
					continue;
				}
				if ($filter['errfile'] && !fnmatch($filter['errfile'], $errfile)) {
					continue;
				}
				if ($filter['errline'] && $errline != $filter['errline']) {
					continue;
				}

				return false;
			}

			return true;
		}

		public static function print_debug_bt($bt = null)
		{
			if (!$bt) {
				$bt = self::get_debug_bt();
			}

			if (IS_ISAPI) {
				print '<code class="backtrace monospace"><pre>' . $bt . '</pre></code>';
			} else {
				print $bt;
			}

		}

		public static function add_success($message, $fmt = array())
		{
			self::append_msg($message, $fmt, self::E_OK);

			return true;
		}

		/**
		 * Withold a PHP error signature from reporting
		 *
		 * @param string $errfunc function
		 * @param int    $errno   PHP error constant
		 * @param string $errstr  string to match against errstr
		 * @param string $errfile basename of file name
		 * @param int    $errline line of occurrence
		 * @return bool
		 */
		public static function suppress_php_error(
			$errfunc,
			$errno = E_ALL,
			$errstr = null,
			$errfile = null,
			$errline = null
		) {
			if (!isset(self::$suppressed_errors[$errfunc])) {
				self::$suppressed_errors[$errfunc] = array();
			}
			self::$suppressed_errors[$errfunc][] = array(
				'errno'   => $errno,
				'errstr'  => $errstr,
				'errfile' => $errfile,
				'errline' => $errline
			);

			return true;
		}

		public static function set_verbose($incr = null)
		{
			if (null === $incr) {
				self::$verbosity++;
			} else {
				self::$verbosity = $incr;
			}

			return self::$verbosity;

		}

		public static function get_last_php_msg()
		{
			$msg = self::$last_php_err;
			self::$last_php_err = null;

			return $msg;
		}

		/**
		 * Print stack
		 *
		 * @return void
		 */
		public static function print_stack()
		{
			$stack = self::get_stack(true);
			$szstack = sizeof($stack);
			if (IS_ISAPI) {
				print '<code><pre>';
			}
			// 2 - get_stack() -> print_stack()
			for ($i = 2; $i < $szstack; $i++) {
				print ($i - 2) . ': ' . $stack[$i] . "\n";
			}
			if (IS_ISAPI) {
				print '</pre></code>';
			}
		}

		/**
		 * Get current callstack
		 *
		 * @param int $lines last n lines
		 * @return array
		 */
		public static function get_stack($lines = false)
		{
			$stack = debug_backtrace();
			$szstack = sizeof($stack);
			$prettystack = array();
			for ($i = 0; $i < $szstack; $i++) {
				$prettystack[] = (isset($stack[$i]['class']) ? $stack[$i]['class'] . '::' : '') .
					$stack[$i]['function'] . '()' .
					($lines && isset($stack[$i]['line']) ? ':' . $stack[$i]['line'] : '');
			}

			return $prettystack;
		}

		/**
		 * Disable warning generation
		 *
		 * @return bool
		 */
		public static function mute_warning($mute_php = false)
		{
			if ($mute_php) {
				self::$old_php_level = error_reporting();
				error_reporting(self::$old_php_level ^ (E_NOTICE | E_WARNING));
			}
			if (!self::$do_warn) {
				return false;
			}

			self::$do_warn = false;
			self::$warn_locker = self::get_muter();

			return true;
		}

		/**
		 * Determine the function directly responsible for invoking mute()
		 *
		 * @return string
		 */
		private static function get_muter()
		{
			// 1: Error_Reporter::mute_warning()
			// 2: mute_warn()
			// 3: caller
			return self::get_caller(3);

		}

		/**
		 * Set maximum emit level
		 *
		 * @param int level
		 * @return int previous level
		 */
		public static function limit_emit($level)
		{
			$oldlevel = self::$max_emit;
			self::$max_emit = (int)$level;

			return $oldlevel;
		}

		/**
		 * Call a function without any error reporting
		 * `*` USE SPARINGLY `*`
		 *
		 * @param \Closure $func
		 * @return mixed
		 */
		public static function silence(\Closure $func)
		{

			//$buffer = self::flush_buffer();
			$tmp = error_reporting(E_ERROR | E_COMPILE_ERROR | E_CORE_ERROR);
			$ret = $func();
			error_reporting($tmp);

			//self::set_buffer($buffer);
			return $ret;
		}

		/**
		 * Re-enable warning generation
		 *
		 * @return bool
		 */
		public static function unmute_warning()
		{
			if (!is_null(self::$old_php_level)) {
				error_reporting(self::$old_php_level);
				self::$old_php_level = null;
			}
			if (self::get_muter() != self::$warn_locker) {
				return false;
			}
			self::$do_warn = true;

			return true;
		}

		public static function error_type($err_const)
		{
			return isset(self::$error_mapping[$err_const]) ? self::$error_mapping[$err_const] :
				self::add_warning('invalid error type const ' . $err_const);
		}

		public static function add_warning($message, $fmt = array())
		{
			if (!self::$do_warn) {
				return true;
			}
			if (self::$verbosity > 0) {
				$caller = self::get_caller(2);
				$message = $caller . '(): ' . $message;
			}
			self::append_msg($message, $fmt, self::E_WARNING);

			return true;
		}

		public static function trigger_fatal($message, $fmt = array())
		{
			if ($fmt) {
				$message = vsprintf($message, $fmt);
			}
			$caller = self::get_caller();
			self::handle_error(self::E_FATAL, $caller . '(): ' . $message);
			if (IS_CLI) {
				fwrite(STDERR, $message);
			} else {
				error_log($message);
			}
			exit(255);
		}

		public static function add_debug($message, $fmt = array())
		{
			return self::append_msg($message, $fmt, self::E_DEBUG);
		}

		public static function add_deprecated($message, $fmt = array())
		{
			if (!self::$verbosity) {
				self::report('Deprecated: ' . vsprintf($message, $fmt));
			}

			return self::add_info($message, $fmt);
		}

		// @TODO do something with deprecated checks

		/**
		 * Report critical error message
		 *
		 * @param  string $msg
		 * @return bool
		 */
		public static function report($msg)
		{
			$msg = vsprintf($msg, array_slice(func_get_args(), 1));

			return self::handle_error(self::E_REPORT, $msg, null, null, new \Exception($msg));
		}

		public static function add_info($message, $fmt = array())
		{
			self::append_msg($message, $fmt, self::E_INFO);

			// always return true as info is not a fatal message
			return true;
		}

		/**
		 * Downgrade severity to maximal level
		 *
		 * @param int $class error class
		 */
		public static function downgrade($class)
		{
			$new = array();
			foreach (self::flush_buffer() as $b) {
				if ($b['severity'] > $class) {
					$b['severity'] = $class;
				}
				$new[] = $b;
			}
			self::merge_buffer($new);
		}

		/**
		 * fetch and purge $error_ring buffer
		 *
		 * @param int $class error type reference
		 */
		public static function flush_buffer($class = null)
		{
			$buffer = self::get_buffer($class);
			if ($buffer) {
				self::clear_buffer($class);
			}

			return $buffer;
		}

		/**
		 * fetch and preserve $error_ring buffer
		 *
		 * @param int $class error type reference
		 */
		public static function get_buffer($class = null)
		{
			if ($class) {
				$buffer = array();
				foreach (self::$error_ring as $error) {
					if ($error['severity'] & $class) {
						$buffer[] = $error;
					}
				}

				return $buffer;
			} else {
				return self::$error_ring;
			}
		}

		// {{{ get_last_msg()

		/**
		 * purge $error_ring buffer
		 *
		 * @param int $class error type reference
		 */
		public static function clear_buffer($class = '')
		{
			if ($class) {
				for ($i = 0, $n = sizeof(self::$error_ring); $i < $n; $i++) {
					if (self::$error_ring[$i]['severity'] == $class) {
						unset(self::$error_ring[$i]);
					}
				}
				self::$error_severity &= ~$class;
			}

			return self::init_ring();
		}

		// }}}

		/**
		 * Merge error buffer into current state
		 *
		 * @param array $buffer
		 * @return bool
		 */
		public static function merge_buffer(array $buffer)
		{
			if (empty($buffer)) {
				return true;
			}
			foreach ($buffer as $msg) {
				switch ($msg['severity']) {
					case self::E_ERROR:
					case self::E_DEPRECATED:
					case self::E_DEBUG:
					case self::E_INFO:
					case self::E_WARNING:
					case self::E_EXCEPTION:
					case self::E_OK:
						self::append_msg($msg['message'], array(), $msg['severity']);
						break;
					default:
						self::append_msg('%s: invalid error class', $msg['severity'], E_WARNING);
				}

			}

			return true;
		}

		public static function is($class)
		{
			return (self::$error_severity & $class) == $class;
		}

		/**
		 * Returns last message from the buffer
		 *
		 * @return string
		 */
		public static function get_last_msg()
		{
			return isset(self::$error_idx) ?
				self::$error_ring[self::$error_idx]['message'] :
				null;
		}

		/**
		 * Return all messages of type E_ERROR
		 *
		 * @return array messages
		 */
		public static function get_errors()
		{

			if (!self::is_error()) {
				return array();
			}
			$errors = array();
			$errbuf = self::get_buffer(self::E_ERROR);
			for ($i = 0, $sz = sizeof($errbuf); $i < $sz; $i++) {
				$errors[] = $errbuf[$i]['message'];
			}

			return $errors;
		}

		/**
		 * State contains errors
		 *
		 * @return bool
		 */
		public static function is_error()
		{
			return (self::get_severity() & self::E_ERROR) == self::E_ERROR;
		}

		public static function get_severity()
		{
			if (self::$error_severity & self::E_ERROR) {
				return self::E_ERROR;
			}

			if (self::$error_severity & self::E_WARNING) {
				return self::E_WARNING;
			}

			return self::E_OK;
		}

		/**
		 * Get number of messages in a class
		 *
		 * @param  int $class
		 * @return int
		 */
		public static function get_msg_count($class)
		{
			if (!isset(self::$error_ctr[$class])) {
				return 0;
			}

			return self::$error_ctr[$class];
		}

		/**
		 * Clear buffer and set buffer
		 *
		 * @param array $buffer message ring
		 * @return bool
		 */
		public static function set_buffer(array $buffer)
		{
			self::clear_buffer();

			return self::merge_buffer($buffer);
		}

		public static function print_buffer()
		{
			$buffer = self::get_buffer();
			foreach ($buffer as $e) {
				printf('%-8s: %s' . PHP_EOL,
					'(' . strtoupper(self::errno2str($e['severity'])) . ')',
					$e['message']
				);
			}
		}

		public static function truncate($str, $len = 80)
		{
			if (is_object($str) || is_array($str)) {
				fatal('cannot truncate complex objects');
			}
			if (!isset($str[$len])) {
				return $str;
			}

			return substr($str, 0, $len) . '...';
		}

		// wrapper to squelch strict error calling non-static function statically in PEAR::isError
		// oh when will they ever fix it?
		public static function isPEARError($data, $code = null)
		{
			if (!($data instanceof PEAR_Error)) {
				return false;
			}

			if (is_null($code)) {
				return true;
			} else if (is_string($code)) {
				return $data->getMessage() == $code;
			}

			return $data->getCode() == $code;
		}

		public static function sort(array $messages = null)
		{
			if (null === $messages) {
				$messages = static::get_buffer();
			}
			$sorter = function ($a, $b) {
				if ($a['severity'] === $b['severity']) {
					return 0;
				}

				return $a['severity'] < $b['severity'];
			};

			return uksort($messages, $sorter);
		}
	}