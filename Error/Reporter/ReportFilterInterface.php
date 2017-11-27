<?php

    namespace Error_Reporter;

    interface ReportFilterInterface extends FilterInterface
    {
        public function filter($errno, $errstr, $errfile, $errline, $errcontext);
    }