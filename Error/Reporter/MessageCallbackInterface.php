<?php
	/**
	 * Message callback interface
	 */

	namespace Error_Reporter;

	interface MessageCallbackInterface {
		public function display(int $errno, string $errstr, ?string $errfile, ?int $errline, ?string $errcontext, array $bt);
	}