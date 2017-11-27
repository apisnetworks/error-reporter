<?php
    /**
     * Backtrace filter interface
     */

    namespace Error_Reporter;


    interface BacktraceFilterInterface extends FilterInterface
    {
        public function filter(array $caller);
    }