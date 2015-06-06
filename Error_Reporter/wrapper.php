<?php
Error_Reporter::init();

function is_debug()
{
    return defined('DEBUG') && DEBUG;
}

function is_ajax()
{
    return defined('AJAX') && AJAX;
}

/**
 * Trigger fatal error
 * @param $msg
 * @param mixed $args
 * @return null
 */
function fatal($msg, $args = array())
{
    $args = func_get_args();
    array_shift($args);
    Error_Reporter::trigger_fatal($msg, $args);

}

/**
 * Raise error
 *
 * @param string $msg
 * @param mixed $args
 * @return bool
 */
function error($msg, $args = array())
{
    $args = func_get_args();
    array_shift($args);
    return Error_Reporter::add_error($msg, $args);

}

/**
 * Raise non-fatal warning
 *
 * @param string $msg
 * @param mixed $args
 * @return bool
 */
function warn($msg, $args = array())
{
    $args = func_get_args();
    array_shift($args);
    return Error_Reporter::add_warning($msg, $args);
}

/**
 * Raise info
 * @param $msg
 * @param mixed $args
 * @return bool
 */
function info($msg, $args = array())
{
    $args = func_get_args();
    array_shift($args);
    return Error_Reporter::add_info($msg, $args);
}

/**
 * Log debugging message, not used in production
 *
 * @param string $msg
 * @param mixed  $args
 * @return bool
 */
function debug($msg, $args = array())
{
    if (!is_debug()) return false;
    $args = func_get_args();
    array_shift($args);
    return Error_Reporter::add_debug($msg, $args);
}

/**
 * Warn deprecated feature
 *
 * @param string $msg
 * @param mixed $args
 * @return bool
 */
function deprecated($msg, $args = array())
{
    if (!is_debug())
        return true;
    $args = func_get_args();
    array_shift($args);

    if (Error_Reporter::get_caller(1) == 'deprecated_func')
        return Error_Reporter::add_deprecated($msg, $args);
    $caller = Error_Reporter::get_caller(1, '/Module_Skeleton/');
    if (substr($msg, 0, strlen($caller)) != $caller)
        $msg = sprintf("%s(): %s", Error_Reporter::get_caller(1, '/Module_Skeleton/'), $msg);

    return Error_Reporter::add_deprecated($msg, $args);

}

/**
 * Warn deprecated function, log calling method
 *
 * @param string $msg
 * @param mixed $args
 * @return bool
 */
function deprecated_func($msg = "", $args = array())
{
    $args = func_get_args();
    array_shift($args);
    if (is_debug()) {
        $tmp = "%s(): is deprecated - called from %s(): %s";
        array_unshift($args, $tmp, Error_Reporter::get_caller(1), Error_Reporter::get_caller(2), $msg);
        return call_user_func_array('deprecated', $args);
    } else {
        Error_Reporter::report("deprecated func: %s", Error_Reporter::get_caller(1));
        $tmp = "%s() is deprecated";
        if ($msg) {
            $tmp .= ": " . $msg;
        }
        array_unshift($args, $tmp, Error_Reporter::get_caller(1));
        return call_user_func_array('deprecated', $args);
    }
}

function report($msg = "", $args = array())
{
    $args = func_get_args();
    array_shift($args);
    return Error_Reporter::report(vsprintf($msg, $args));
}

function mute_warn($mute_php = false)
{
    return Error_Reporter::mute_warning($mute_php);
}

function mute($func)
{
    if (!is_callable($func))
        return error('argument must be a function, given %s', gettype($func));
    Error_Reporter::mute_warning();
    $ret = call_user_func($func);
    Error_Reporter::unmute_warning();
    return $ret;
}

function unmute_warn()
{
    return Error_Reporter::unmute_warning();
}

function silence($func)
{
    return Error_Reporter::silence($func);
}

function dlog($msg, $args = array())
{
    $args = func_get_args();
    $msg = $args[0];
    $args = array_slice($args, 1);
    Error_Reporter::log($msg, $args);
    if (is_debug()) {
        if ($args)
            $msg = vsprintf($msg, $args);
        fwrite(STDERR, $msg . "\n");
    }
    return true;
}

/**
 * Print unique object identifier
 *
 * @param Object $obj
 * @return null
 */
function print_object_hash($obj)
{
    if (!is_object($obj)) {
        fatal("object is not object, got %s", gettype($obj));
    }
    print object_hash($obj);
}


/**
 * Compute unique hash for object
 *
 * @param Object $obj
 * @return string object hash
 */
function object_hash($obj)
{
    if (!is_object($obj)) {
        fatal("object is not object, got %s", gettype($obj));
    }
    return spl_object_hash($obj);
}