<?PHP

/**
 * PEAR, the PHP Extension and Application Repository
 *
 * PEAR class and PEAR_Error class
 *
 * PHP versions 4 and 5
 *
 * @category   pear
 * @package    PEAR
 * @author     Sterling Hughes <sterling@php.net>
 * @author     Stig Bakken <ssb@php.net>
 * @author     Tomas V.V.Cox <cox@idecnet.com>
 * @author     Greg Beaver <cellog@php.net>
 * @copyright  1997-2010 The Authors
 * @license    http://opensource.org/licenses/bsd-license.php New BSD License
 * @link       http://pear.php.net/package/PEAR
 * @since      File available since Release 0.1
 */

/**#@+
 * ERROR constants
 */
define('PEAR_ERROR_RETURN',     1);
define('PEAR_ERROR_PRINT',      2);
define('PEAR_ERROR_TRIGGER',    4);
define('PEAR_ERROR_DIE',        8);
define('PEAR_ERROR_CALLBACK',  16);
/**
 * WARNING: obsolete
 * @deprecated
 */
define('PEAR_ERROR_EXCEPTION', 32);
/**#@-*/

if (substr(PHP_OS, 0, 3) == 'WIN') {
    define('OS_WINDOWS', true);
    define('OS_UNIX',    false);
    define('PEAR_OS',    'Windows');
} else {
    define('OS_WINDOWS', false);
    define('OS_UNIX',    true);
    define('PEAR_OS',    'Unix'); // blatant assumption
}

$GLOBALS['_PEAR_default_error_mode']     = PEAR_ERROR_RETURN;
$GLOBALS['_PEAR_default_error_options']  = E_USER_NOTICE;
$GLOBALS['_PEAR_destructor_object_list'] = array();
$GLOBALS['_PEAR_shutdown_funcs']         = array();
$GLOBALS['_PEAR_error_handler_stack']    = array();

@ini_set('track_errors', true);

/**
 * Base class for other PEAR classes.  Provides rudimentary
 * emulation of destructors.
 *
 * If you want a destructor in your class, inherit PEAR and make a
 * destructor method called _yourclassname (same name as the
 * constructor, but with a "_" prefix).  Also, in your constructor you
 * have to call the PEAR constructor: $this->PEAR();.
 * The destructor method will be called without parameters.  Note that
 * at in some SAPI implementations (such as Apache), any output during
 * the request shutdown (in which destructors are called) seems to be
 * discarded.  If you need to get any debug information from your
 * destructor, use error_log(), syslog() or something similar.
 *
 * IMPORTANT! To use the emulated destructors you need to create the
 * objects by reference: $obj =& new PEAR_child;
 *
 * @category   pear
 * @package    PEAR
 * @author     Stig Bakken <ssb@php.net>
 * @author     Tomas V.V. Cox <cox@idecnet.com>
 * @author     Greg Beaver <cellog@php.net>
 * @copyright  1997-2006 The PHP Group
 * @license    http://opensource.org/licenses/bsd-license.php New BSD License
 * @version    Release: 1.10.1
 * @link       http://pear.php.net/package/PEAR
 * @see        PEAR_Error
 * @since      Class available since PHP 4.0.2
 * @link        http://pear.php.net/manual/en/core.pear.php#core.pear.pear
 */
class PEAR
{
    /**
     * Whether to enable internal debug messages.
     *
     * @var     bool
     * @access  private
     */
    var $_debug = false;

    /**
     * Default error mode for this object.
     *
     * @var     int
     * @access  private
     */
    var $_default_error_mode = null;

    /**
     * Default error options used for this object when error mode
     * is PEAR_ERROR_TRIGGER.
     *
     * @var     int
     * @access  private
     */
    var $_default_error_options = null;

    /**
     * Default error handler (callback) for this object, if error mode is
     * PEAR_ERROR_CALLBACK.
     *
     * @var     string
     * @access  private
     */
    var $_default_error_handler = '';

    /**
     * Which class to use for error objects.
     *
     * @var     string
     * @access  private
     */
    var $_error_class = 'PEAR_Error';

    /**
     * An array of expected errors.
     *
     * @var     array
     * @access  private
     */
    var $_expected_errors = array();

    /**
     * List of methods that can be called both statically and non-statically.
     * @var array
     */
    protected static $bivalentMethods = array(
        'setErrorHandling' => true,
        'raiseError' => true,
        'throwError' => true,
        'pushErrorHandling' => true,
        'popErrorHandling' => true,
    );

    /**
     * Constructor.  Registers this object in
     * $_PEAR_destructor_object_list for destructor emulation if a
     * destructor object exists.
     *
     * @param string $error_class  (optional) which class to use for
     *        error objects, defaults to PEAR_Error.
     * @access public
     * @return void
     */
    function __construct($error_class = null)
    {
        $classname = strtolower(get_class($this));
        if ($this->_debug) {
            print "PEAR constructor called, class=$classname\n";
        }

        if ($error_class !== null) {
            $this->_error_class = $error_class;
        }

        while ($classname && strcasecmp($classname, "pear")) {
            $destructor = "_$classname";
            if (method_exists($this, $destructor)) {
                global $_PEAR_destructor_object_list;
                $_PEAR_destructor_object_list[] = &$this;
                if (!isset($GLOBALS['_PEAR_SHUTDOWN_REGISTERED'])) {
                    register_shutdown_function("_PEAR_call_destructors");
                    $GLOBALS['_PEAR_SHUTDOWN_REGISTERED'] = true;
                }
                break;
            } else {
                $classname = get_parent_class($classname);
            }
        }
    }

    /**
     * Only here for backwards compatibility.
     * E.g. Archive_Tar calls $this->PEAR() in its constructor.
     *
     * @param string $error_class Which class to use for error objects,
     *                            defaults to PEAR_Error.
     */
    public function PEAR($error_class = null)
    {
        self::__construct($error_class);
    }

    /**
     * Destructor (the emulated type of...).  Does nothing right now,
     * but is included for forward compatibility, so subclass
     * destructors should always call it.
     *
     * See the note in the class desciption about output from
     * destructors.
     *
     * @access public
     * @return void
     */
    function _PEAR() {
        if ($this->_debug) {
            printf("PEAR destructor called, class=%s\n", strtolower(get_class($this)));
        }
    }

    public function __call($method, $arguments)
    {
        if (!isset(self::$bivalentMethods[$method])) {
            trigger_error(
                'Call to undefined method PEAR::' . $method . '()', E_USER_ERROR
            );
        }
        return call_user_func_array(
            array(get_class(), '_' . $method),
            array_merge(array($this), $arguments)
        );
    }

    public static function __callStatic($method, $arguments)
    {
        if (!isset(self::$bivalentMethods[$method])) {
            trigger_error(
                'Call to undefined method PEAR::' . $method . '()', E_USER_ERROR
            );
        }
        return call_user_func_array(
            array(get_class(), '_' . $method),
            array_merge(array(null), $arguments)
        );
    }

    /**
    * If you have a class that's mostly/entirely static, and you need static
    * properties, you can use this method to simulate them. Eg. in your method(s)
    * do this: $myVar = &PEAR::getStaticProperty('myclass', 'myVar');
    * You MUST use a reference, or they will not persist!
    *
    * @param  string $class  The calling classname, to prevent clashes
    * @param  string $var    The variable to retrieve.
    * @return mixed   A reference to the variable. If not set it will be
    *                 auto initialised to NULL.
    */
    public static function &getStaticProperty($class, $var)
    {
        static $properties;
        if (!isset($properties[$class])) {
            $properties[$class] = array();
        }

        if (!array_key_exists($var, $properties[$class])) {
            $properties[$class][$var] = null;
        }

        return $properties[$class][$var];
    }

    /**
    * Use this function to register a shutdown method for static
    * classes.
    *
    * @param  mixed $func  The function name (or array of class/method) to call
    * @param  mixed $args  The arguments to pass to the function
    *
    * @return void
    */
    public static function registerShutdownFunc($func, $args = array())
    {
        // if we are called statically, there is a potential
        // that no shutdown func is registered.  Bug #6445
        if (!isset($GLOBALS['_PEAR_SHUTDOWN_REGISTERED'])) {
            register_shutdown_function("_PEAR_call_destructors");
            $GLOBALS['_PEAR_SHUTDOWN_REGISTERED'] = true;
        }
        $GLOBALS['_PEAR_shutdown_funcs'][] = array($func, $args);
    }

    /**
     * Tell whether a value is a PEAR error.
     *
     * @param   mixed $data   the value to test
     * @param   int   $code   if $data is an error object, return true
     *                        only if $code is a string and
     *                        $obj->getMessage() == $code or
     *                        $code is an integer and $obj->getCode() == $code
     *
     * @return  bool    true if parameter is an error
     */
    public static function isError($data, $code = null)
    {
        if (!is_a($data, 'PEAR_Error')) {
            return false;
        }

        if (is_null($code)) {
            return true;
        } elseif (is_string($code)) {
            return $data->getMessage() == $code;
        }

        return $data->getCode() == $code;
    }

    /**
     * Sets how errors generated by this object should be handled.
     * Can be invoked both in objects and statically.  If called
     * statically, setErrorHandling sets the default behaviour for all
     * PEAR objects.  If called in an object, setErrorHandling sets
     * the default behaviour for that object.
     *
     * @param object $object
     *        Object the method was called on (non-static mode)
     *
     * @param int $mode
     *        One of PEAR_ERROR_RETURN, PEAR_ERROR_PRINT,
     *        PEAR_ERROR_TRIGGER, PEAR_ERROR_DIE,
     *        PEAR_ERROR_CALLBACK or PEAR_ERROR_EXCEPTION.
     *
     * @param mixed $options
     *        When $mode is PEAR_ERROR_TRIGGER, this is the error level (one
     *        of E_USER_NOTICE, E_USER_WARNING or E_USER_ERROR).
     *
     *        When $mode is PEAR_ERROR_CALLBACK, this parameter is expected
     *        to be the callback function or method.  A callback
     *        function is a string with the name of the function, a
     *        callback method is an array of two elements: the element
     *        at index 0 is the object, and the element at index 1 is
     *        the name of the method to call in the object.
     *
     *        When $mode is PEAR_ERROR_PRINT or PEAR_ERROR_DIE, this is
     *        a printf format string used when printing the error
     *        message.
     *
     * @access public
     * @return void
     * @see PEAR_ERROR_RETURN
     * @see PEAR_ERROR_PRINT
     * @see PEAR_ERROR_TRIGGER
     * @see PEAR_ERROR_DIE
     * @see PEAR_ERROR_CALLBACK
     * @see PEAR_ERROR_EXCEPTION
     *
     * @since PHP 4.0.5
     */
    protected static function _setErrorHandling(
        $object, $mode = null, $options = null
    ) {
        if ($object !== null) {
            $setmode     = &$object->_default_error_mode;
            $setoptions  = &$object->_default_error_options;
        } else {
            $setmode     = &$GLOBALS['_PEAR_default_error_mode'];
            $setoptions  = &$GLOBALS['_PEAR_default_error_options'];
        }

        switch ($mode) {
            case PEAR_ERROR_EXCEPTION:
            case PEAR_ERROR_RETURN:
            case PEAR_ERROR_PRINT:
            case PEAR_ERROR_TRIGGER:
            case PEAR_ERROR_DIE:
            case null:
                $setmode = $mode;
                $setoptions = $options;
                break;

            case PEAR_ERROR_CALLBACK:
                $setmode = $mode;
                // class/object method callback
                if (is_callable($options)) {
                    $setoptions = $options;
                } else {
                    trigger_error("invalid error callback", E_USER_WARNING);
                }
                break;

            default:
                trigger_error("invalid error mode", E_USER_WARNING);
                break;
        }
    }

    /**
     * This method is used to tell which errors you expect to get.
     * Expected errors are always returned with error mode
     * PEAR_ERROR_RETURN.  Expected error codes are stored in a stack,
     * and this method pushes a new element onto it.  The list of
     * expected errors are in effect until they are popped off the
     * stack with the popExpect() method.
     *
     * Note that this method can not be called statically
     *
     * @param mixed $code a single error code or an array of error codes to expect
     *
     * @return int     the new depth of the "expected errors" stack
     * @access public
     */
    function expectError($code = '*')
    {
        if (is_array($code)) {
            array_push($this->_expected_errors, $code);
        } else {
            array_push($this->_expected_errors, array($code));
        }
        return count($this->_expected_errors);
    }

    /**
     * This method pops one element off the expected error codes
     * stack.
     *
     * @return array   the list of error codes that were popped
     */
    function popExpect()
    {
        return array_pop($this->_expected_errors);
    }

    /**
     * This method checks unsets an error code if available
     *
     * @param mixed error code
     * @return bool true if the error code was unset, false otherwise
     * @access private
     * @since PHP 4.3.0
     */
    function _checkDelExpect($error_code)
    {
        $deleted = false;
        foreach ($this->_expected_errors as $key => $error_array) {
            if (in_array($error_code, $error_array)) {
                unset($this->_expected_errors[$key][array_search($error_code, $error_array)]);
                $deleted = true;
            }

            // clean up empty arrays
            if (0 == count($this->_expected_errors[$key])) {
                unset($this->_expected_errors[$key]);
            }
        }

        return $deleted;
    }

    /**
     * This method deletes all occurences of the specified element from
     * the expected error codes stack.
     *
     * @param  mixed $error_code error code that should be deleted
     * @return mixed list of error codes that were deleted or error
     * @access public
     * @since PHP 4.3.0
     */
    function delExpect($error_code)
    {
        $deleted = false;
        if ((is_array($error_code) && (0 != count($error_code)))) {
            // $error_code is a non-empty array here; we walk through it trying
            // to unset all values
            foreach ($error_code as $key => $error) {
                $deleted =  $this->_checkDelExpect($error) ? true : false;
            }

            return $deleted ? true : PEAR::raiseError("The expected error you submitted does not exist"); // IMPROVE ME
        } elseif (!empty($error_code)) {
            // $error_code comes alone, trying to unset it
            if ($this->_checkDelExpect($error_code)) {
                return true;
            }

            return PEAR::raiseError("The expected error you submitted does not exist"); // IMPROVE ME
        }

        // $error_code is empty
        return PEAR::raiseError("The expected error you submitted is empty"); // IMPROVE ME
    }

    /**
     * This method is a wrapper that returns an instance of the
     * configured error class with this object's default error
     * handling applied.  If the $mode and $options parameters are not
     * specified, the object's defaults are used.
     *
     * @param mixed $message a text error message or a PEAR error object
     *
     * @param int $code      a numeric error code (it is up to your class
     *                  to define these if you want to use codes)
     *
     * @param int $mode      One of PEAR_ERROR_RETURN, PEAR_ERROR_PRINT,
     *                  PEAR_ERROR_TRIGGER, PEAR_ERROR_DIE,
     *                  PEAR_ERROR_CALLBACK, PEAR_ERROR_EXCEPTION.
     *
     * @param mixed $options If $mode is PEAR_ERROR_TRIGGER, this parameter
     *                  specifies the PHP-internal error level (one of
     *                  E_USER_NOTICE, E_USER_WARNING or E_USER_ERROR).
     *                  If $mode is PEAR_ERROR_CALLBACK, this
     *                  parameter specifies the callback function or
     *                  method.  In other error modes this parameter
     *                  is ignored.
     *
     * @param string $userinfo If you need to pass along for example debug
     *                  information, this parameter is meant for that.
     *
     * @param string $error_class The returned error object will be
     *                  instantiated from this class, if specified.
     *
     * @param bool $skipmsg If true, raiseError will only pass error codes,
     *                  the error message parameter will be dropped.
     *
     * @return object   a PEAR error object
     * @see PEAR::setErrorHandling
     * @since PHP 4.0.5
     */
    protected static function _raiseError($object,
                         $message = null,
                         $code = null,
                         $mode = null,
                         $options = null,
                         $userinfo = null,
                         $error_class = null,
                         $skipmsg = false)
    {
        // The error is yet a PEAR error object
        if (is_object($message)) {
            $code        = $message->getCode();
            $userinfo    = $message->getUserInfo();
            $error_class = $message->getType();
            $message->error_message_prefix = '';
            $message     = $message->getMessage();
        }

        if (
            $object !== null &&
            isset($object->_expected_errors) &&
            count($object->_expected_errors) > 0 &&
            count($exp = end($object->_expected_errors))
        ) {
            if ($exp[0] == "*" ||
                (is_int(reset($exp)) && in_array($code, $exp)) ||
                (is_string(reset($exp)) && in_array($message, $exp))
            ) {
                $mode = PEAR_ERROR_RETURN;
            }
        }

        // No mode given, try global ones
        if ($mode === null) {
            // Class error handler
            if ($object !== null && isset($object->_default_error_mode)) {
                $mode    = $object->_default_error_mode;
                $options = $object->_default_error_options;
            // Global error handler
            } elseif (isset($GLOBALS['_PEAR_default_error_mode'])) {
                $mode    = $GLOBALS['_PEAR_default_error_mode'];
                $options = $GLOBALS['_PEAR_default_error_options'];
            }
        }

        if ($error_class !== null) {
            $ec = $error_class;
        } elseif ($object !== null && isset($object->_error_class)) {
            $ec = $object->_error_class;
        } else {
            $ec = 'PEAR_Error';
        }

        if ($skipmsg) {
            $a = new $ec($code, $mode, $options, $userinfo);
        } else {
            $a = new $ec($message, $code, $mode, $options, $userinfo);
        }

        return $a;
    }

    /**
     * Simpler form of raiseError with fewer options.  In most cases
     * message, code and userinfo are enough.
     *
     * @param mixed $message a text error message or a PEAR error object
     *
     * @param int $code      a numeric error code (it is up to your class
     *                  to define these if you want to use codes)
     *
     * @param string $userinfo If you need to pass along for example debug
     *                  information, this parameter is meant for that.
     *
     * @return object   a PEAR error object
     * @see PEAR::raiseError
     */
    protected static function _throwError($object, $message = null, $code = null, $userinfo = null)
    {
        if ($object !== null) {
            $a = &$object->raiseError($message, $code, null, null, $userinfo);
            return $a;
        }

        $a = &PEAR::raiseError($message, $code, null, null, $userinfo);
        return $a;
    }

    public static function staticPushErrorHandling($mode, $options = null)
    {
        $stack       = &$GLOBALS['_PEAR_error_handler_stack'];
        $def_mode    = &$GLOBALS['_PEAR_default_error_mode'];
        $def_options = &$GLOBALS['_PEAR_default_error_options'];
        $stack[] = array($def_mode, $def_options);
        switch ($mode) {
            case PEAR_ERROR_EXCEPTION:
            case PEAR_ERROR_RETURN:
            case PEAR_ERROR_PRINT:
            case PEAR_ERROR_TRIGGER:
            case PEAR_ERROR_DIE:
            case null:
                $def_mode = $mode;
                $def_options = $options;
                break;

            case PEAR_ERROR_CALLBACK:
                $def_mode = $mode;
                // class/object method callback
                if (is_callable($options)) {
                    $def_options = $options;
                } else {
                    trigger_error("invalid error callback", E_USER_WARNING);
                }
                break;

            default:
                trigger_error("invalid error mode", E_USER_WARNING);
                break;
        }
        $stack[] = array($mode, $options);
        return true;
    }

    public static function staticPopErrorHandling()
    {
        $stack = &$GLOBALS['_PEAR_error_handler_stack'];
        $setmode     = &$GLOBALS['_PEAR_default_error_mode'];
        $setoptions  = &$GLOBALS['_PEAR_default_error_options'];
        array_pop($stack);
        list($mode, $options) = $stack[sizeof($stack) - 1];
        array_pop($stack);
        switch ($mode) {
            case PEAR_ERROR_EXCEPTION:
            case PEAR_ERROR_RETURN:
            case PEAR_ERROR_PRINT:
            case PEAR_ERROR_TRIGGER:
            case PEAR_ERROR_DIE:
            case null:
                $setmode = $mode;
                $setoptions = $options;
                break;

            case PEAR_ERROR_CALLBACK:
                $setmode = $mode;
                // class/object method callback
                if (is_callable($options)) {
                    $setoptions = $options;
                } else {
                    trigger_error("invalid error callback", E_USER_WARNING);
                }
                break;

            default:
                trigger_error("invalid error mode", E_USER_WARNING);
                break;
        }
        return true;
    }

    /**
     * Push a new error handler on top of the error handler options stack. With this
     * you can easily override the actual error handler for some code and restore
     * it later with popErrorHandling.
     *
     * @param mixed $mode (same as setErrorHandling)
     * @param mixed $options (same as setErrorHandling)
     *
     * @return bool Always true
     *
     * @see PEAR::setErrorHandling
     */
    protected static function _pushErrorHandling($object, $mode, $options = null)
    {
        $stack = &$GLOBALS['_PEAR_error_handler_stack'];
        if ($object !== null) {
            $def_mode    = &$object->_default_error_mode;
            $def_options = &$object->_default_error_options;
        } else {
            $def_mode    = &$GLOBALS['_PEAR_default_error_mode'];
            $def_options = &$GLOBALS['_PEAR_default_error_options'];
        }
        $stack[] = array($def_mode, $def_options);

        if ($object !== null) {
            $object->setErrorHandling($mode, $options);
        } else {
            PEAR::setErrorHandling($mode, $options);
        }
        $stack[] = array($mode, $options);
        return true;
    }

    /**
    * Pop the last error handler used
    *
    * @return bool Always true
    *
    * @see PEAR::pushErrorHandling
    */
    protected static function _popErrorHandling($object)
    {
        $stack = &$GLOBALS['_PEAR_error_handler_stack'];
        array_pop($stack);
        list($mode, $options) = $stack[sizeof($stack) - 1];
        array_pop($stack);
        if ($object !== null) {
            $object->setErrorHandling($mode, $options);
        } else {
            PEAR::setErrorHandling($mode, $options);
        }
        return true;
    }

    /**
    * OS independent PHP extension load. Remember to take care
    * on the correct extension name for case sensitive OSes.
    *
    * @param string $ext The extension name
    * @return bool Success or not on the dl() call
    */
    public static function loadExtension($ext)
    {
        if (extension_loaded($ext)) {
            return true;
        }

        // if either returns true dl() will produce a FATAL error, stop that
        if (
            function_exists('dl') === false ||
            ini_get('enable_dl') != 1
        ) {
            return false;
        }

        if (OS_WINDOWS) {
            $suffix = '.dll';
        } elseif (PHP_OS == 'HP-UX') {
            $suffix = '.sl';
        } elseif (PHP_OS == 'AIX') {
            $suffix = '.a';
        } elseif (PHP_OS == 'OSX') {
            $suffix = '.bundle';
        } else {
            $suffix = '.so';
        }

        return @dl('php_'.$ext.$suffix) || @dl($ext.$suffix);
    }
}

function _PEAR_call_destructors()
{
    global $_PEAR_destructor_object_list;
    if (is_array($_PEAR_destructor_object_list) &&
        sizeof($_PEAR_destructor_object_list))
    {
        reset($_PEAR_destructor_object_list);

        $destructLifoExists = PEAR::getStaticProperty('PEAR', 'destructlifo');

        if ($destructLifoExists) {
            $_PEAR_destructor_object_list = array_reverse($_PEAR_destructor_object_list);
        }

        while (list($k, $objref) = each($_PEAR_destructor_object_list)) {
            $classname = get_class($objref);
            while ($classname) {
                $destructor = "_$classname";
                if (method_exists($objref, $destructor)) {
                    $objref->$destructor();
                    break;
                } else {
                    $classname = get_parent_class($classname);
                }
            }
        }
        // Empty the object list to ensure that destructors are
        // not called more than once.
        $_PEAR_destructor_object_list = array();
    }

    // Now call the shutdown functions
    if (
        isset($GLOBALS['_PEAR_shutdown_funcs']) &&
        is_array($GLOBALS['_PEAR_shutdown_funcs']) &&
        !empty($GLOBALS['_PEAR_shutdown_funcs'])
    ) {
        foreach ($GLOBALS['_PEAR_shutdown_funcs'] as $value) {
            call_user_func_array($value[0], $value[1]);
        }
    }
}

/**
 * Standard PEAR error class for PHP 4
 *
 * This class is supserseded by {@link PEAR_Exception} in PHP 5
 *
 * @category   pear
 * @package    PEAR
 * @author     Stig Bakken <ssb@php.net>
 * @author     Tomas V.V. Cox <cox@idecnet.com>
 * @author     Gregory Beaver <cellog@php.net>
 * @copyright  1997-2006 The PHP Group
 * @license    http://opensource.org/licenses/bsd-license.php New BSD License
 * @version    Release: 1.10.1
 * @link       http://pear.php.net/manual/en/core.pear.pear-error.php
 * @see        PEAR::raiseError(), PEAR::throwError()
 * @since      Class available since PHP 4.0.2
 */
class PEAR_Error
{
    var $error_message_prefix = '';
    var $mode                 = PEAR_ERROR_RETURN;
    var $level                = E_USER_NOTICE;
    var $code                 = -1;
    var $message              = '';
    var $userinfo             = '';
    var $backtrace            = null;

    /**
     * PEAR_Error constructor
     *
     * @param string $message  message
     *
     * @param int $code     (optional) error code
     *
     * @param int $mode     (optional) error mode, one of: PEAR_ERROR_RETURN,
     * PEAR_ERROR_PRINT, PEAR_ERROR_DIE, PEAR_ERROR_TRIGGER,
     * PEAR_ERROR_CALLBACK or PEAR_ERROR_EXCEPTION
     *
     * @param mixed $options   (optional) error level, _OR_ in the case of
     * PEAR_ERROR_CALLBACK, the callback function or object/method
     * tuple.
     *
     * @param string $userinfo (optional) additional user/debug info
     *
     * @access public
     *
     */
    function __construct($message = 'unknown error', $code = null,
                        $mode = null, $options = null, $userinfo = null)
    {
        if ($mode === null) {
            $mode = PEAR_ERROR_RETURN;
        }
        $this->message   = $message;
        $this->code      = $code;
        $this->mode      = $mode;
        $this->userinfo  = $userinfo;

        $skiptrace = PEAR::getStaticProperty('PEAR_Error', 'skiptrace');

        if (!$skiptrace) {
            $this->backtrace = debug_backtrace();
            if (isset($this->backtrace[0]) && isset($this->backtrace[0]['object'])) {
                unset($this->backtrace[0]['object']);
            }
        }

        if ($mode & PEAR_ERROR_CALLBACK) {
            $this->level = E_USER_NOTICE;
            $this->callback = $options;
        } else {
            if ($options === null) {
                $options = E_USER_NOTICE;
            }

            $this->level = $options;
            $this->callback = null;
        }

        if ($this->mode & PEAR_ERROR_PRINT) {
            if (is_null($options) || is_int($options)) {
                $format = "%s";
            } else {
                $format = $options;
            }

            printf($format, $this->getMessage());
        }

        if ($this->mode & PEAR_ERROR_TRIGGER) {
            trigger_error($this->getMessage(), $this->level);
        }

        if ($this->mode & PEAR_ERROR_DIE) {
            $msg = $this->getMessage();
            if (is_null($options) || is_int($options)) {
                $format = "%s";
                if (substr($msg, -1) != "\n") {
                    $msg .= "\n";
                }
            } else {
                $format = $options;
            }
            die(sprintf($format, $msg));
        }

        if ($this->mode & PEAR_ERROR_CALLBACK && is_callable($this->callback)) {
            call_user_func($this->callback, $this);
        }

        if ($this->mode & PEAR_ERROR_EXCEPTION) {
            trigger_error("PEAR_ERROR_EXCEPTION is obsolete, use class PEAR_Exception for exceptions", E_USER_WARNING);
            eval('$e = new Exception($this->message, $this->code);throw($e);');
        }
    }

    /**
     * Only here for backwards compatibility.
     *
     * Class "Cache_Error" still uses it, among others.
     *
     * @param string $message  Message
     * @param int    $code     Error code
     * @param int    $mode     Error mode
     * @param mixed  $options  See __construct()
     * @param string $userinfo Additional user/debug info
     */
    public function PEAR_Error(
        $message = 'unknown error', $code = null, $mode = null,
        $options = null, $userinfo = null
    ) {
        self::__construct($message, $code, $mode, $options, $userinfo);
    }

    /**
     * Get the error mode from an error object.
     *
     * @return int error mode
     * @access public
     */
    function getMode()
    {
        return $this->mode;
    }

    /**
     * Get the callback function/method from an error object.
     *
     * @return mixed callback function or object/method array
     * @access public
     */
    function getCallback()
    {
        return $this->callback;
    }

    /**
     * Get the error message from an error object.
     *
     * @return  string  full error message
     * @access public
     */
    function getMessage()
    {
        return ($this->error_message_prefix . $this->message);
    }

    /**
     * Get error code from an error object
     *
     * @return int error code
     * @access public
     */
     function getCode()
     {
        return $this->code;
     }

    /**
     * Get the name of this error/exception.
     *
     * @return string error/exception name (type)
     * @access public
     */
    function getType()
    {
        return get_class($this);
    }

    /**
     * Get additional user-supplied information.
     *
     * @return string user-supplied information
     * @access public
     */
    function getUserInfo()
    {
        return $this->userinfo;
    }

    /**
     * Get additional debug information supplied by the application.
     *
     * @return string debug information
     * @access public
     */
    function getDebugInfo()
    {
        return $this->getUserInfo();
    }

    /**
     * Get the call backtrace from where the error was generated.
     * Supported with PHP 4.3.0 or newer.
     *
     * @param int $frame (optional) what frame to fetch
     * @return array Backtrace, or NULL if not available.
     * @access public
     */
    function getBacktrace($frame = null)
    {
        if (defined('PEAR_IGNORE_BACKTRACE')) {
            return null;
        }
        if ($frame === null) {
            return $this->backtrace;
        }
        return $this->backtrace[$frame];
    }

    function addUserInfo($info)
    {
        if (empty($this->userinfo)) {
            $this->userinfo = $info;
        } else {
            $this->userinfo .= " ** $info";
        }
    }

    function __toString()
    {
        return $this->getMessage();
    }

    /**
     * Make a string representation of this object.
     *
     * @return string a string with an object summary
     * @access public
     */
    function toString()
    {
        $modes = array();
        $levels = array(E_USER_NOTICE  => 'notice',
                        E_USER_WARNING => 'warning',
                        E_USER_ERROR   => 'error');
        if ($this->mode & PEAR_ERROR_CALLBACK) {
            if (is_array($this->callback)) {
                $callback = (is_object($this->callback[0]) ?
                    strtolower(get_class($this->callback[0])) :
                    $this->callback[0]) . '::' .
                    $this->callback[1];
            } else {
                $callback = $this->callback;
            }
            return sprintf('[%s: message="%s" code=%d mode=callback '.
                           'callback=%s prefix="%s" info="%s"]',
                           strtolower(get_class($this)), $this->message, $this->code,
                           $callback, $this->error_message_prefix,
                           $this->userinfo);
        }
        if ($this->mode & PEAR_ERROR_PRINT) {
            $modes[] = 'print';
        }
        if ($this->mode & PEAR_ERROR_TRIGGER) {
            $modes[] = 'trigger';
        }
        if ($this->mode & PEAR_ERROR_DIE) {
            $modes[] = 'die';
        }
        if ($this->mode & PEAR_ERROR_RETURN) {
            $modes[] = 'return';
        }
        return sprintf('[%s: message="%s" code=%d mode=%s level=%s '.
                       'prefix="%s" info="%s"]',
                       strtolower(get_class($this)), $this->message, $this->code,
                       implode("|", $modes), $levels[$this->level],
                       $this->error_message_prefix,
                       $this->userinfo);
    }
}

/*
 * Local Variables:
 * mode: php
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 */
/**
 * PHPMailer - PHP email creation and transport class.
 * PHP Version 5
 * @package PHPMailer
 * @link https://github.com/PHPMailer/PHPMailer/ The PHPMailer GitHub project
 * @author Marcus Bointon (Synchro/coolbru) <phpmailer@synchromedia.co.uk>
 * @author Jim Jagielski (jimjag) <jimjag@gmail.com>
 * @author Andy Prevost (codeworxtech) <codeworxtech@users.sourceforge.net>
 * @author Brent R. Matzelle (original founder)
 * @copyright 2012 - 2014 Marcus Bointon
 * @copyright 2010 - 2012 Jim Jagielski
 * @copyright 2004 - 2009 Andy Prevost
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 * @note This program is distributed in the hope that it will be useful - WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
 * PHPMailer - PHP email creation and transport class.
 * @package PHPMailer
 * @author Marcus Bointon (Synchro/coolbru) <phpmailer@synchromedia.co.uk>
 * @author Jim Jagielski (jimjag) <jimjag@gmail.com>
 * @author Andy Prevost (codeworxtech) <codeworxtech@users.sourceforge.net>
 * @author Brent R. Matzelle (original founder)
 */
class PHPMailer
{
    /**
     * The PHPMailer Version number.
     * @var string
     */
    public $Version = '5.2.16';

    /**
     * Email priority.
     * Options: null (default), 1 = High, 3 = Normal, 5 = low.
     * When null, the header is not set at all.
     * @var integer
     */
    public $Priority = null;

    /**
     * The character set of the message.
     * @var string
     */
    public $CharSet = 'iso-8859-1';

    /**
     * The MIME Content-type of the message.
     * @var string
     */
    public $ContentType = 'text/plain';

    /**
     * The message encoding.
     * Options: "8bit", "7bit", "binary", "base64", and "quoted-printable".
     * @var string
     */
    public $Encoding = '8bit';

    /**
     * Holds the most recent mailer error message.
     * @var string
     */
    public $ErrorInfo = '';

    /**
     * The From email address for the message.
     * @var string
     */
    public $From = 'root@localhost';

    /**
     * The From name of the message.
     * @var string
     */
    public $FromName = 'Root User';

    /**
     * The Sender email (Return-Path) of the message.
     * If not empty, will be sent via -f to sendmail or as 'MAIL FROM' in smtp mode.
     * @var string
     */
    public $Sender = '';

    /**
     * The Return-Path of the message.
     * If empty, it will be set to either From or Sender.
     * @var string
     * @deprecated Email senders should never set a return-path header;
     * it's the receiver's job (RFC5321 section 4.4), so this no longer does anything.
     * @link https://tools.ietf.org/html/rfc5321#section-4.4 RFC5321 reference
     */
    public $ReturnPath = '';

    /**
     * The Subject of the message.
     * @var string
     */
    public $Subject = '';

    /**
     * An HTML or plain text message body.
     * If HTML then call isHTML(true).
     * @var string
     */
    public $Body = '';

    /**
     * The plain-text message body.
     * This body can be read by mail clients that do not have HTML email
     * capability such as mutt & Eudora.
     * Clients that can read HTML will view the normal Body.
     * @var string
     */
    public $AltBody = '';

    /**
     * An iCal message part body.
     * Only supported in simple alt or alt_inline message types
     * To generate iCal events, use the bundled extras/EasyPeasyICS.php class or iCalcreator
     * @link http://sprain.ch/blog/downloads/php-class-easypeasyics-create-ical-files-with-php/
     * @link http://kigkonsult.se/iCalcreator/
     * @var string
     */
    public $Ical = '';

    /**
     * The complete compiled MIME message body.
     * @access protected
     * @var string
     */
    protected $MIMEBody = '';

    /**
     * The complete compiled MIME message headers.
     * @var string
     * @access protected
     */
    protected $MIMEHeader = '';

    /**
     * Extra headers that createHeader() doesn't fold in.
     * @var string
     * @access protected
     */
    protected $mailHeader = '';

    /**
     * Word-wrap the message body to this number of chars.
     * Set to 0 to not wrap. A useful value here is 78, for RFC2822 section 2.1.1 compliance.
     * @var integer
     */
    public $WordWrap = 0;

    /**
     * Which method to use to send mail.
     * Options: "mail", "sendmail", or "smtp".
     * @var string
     */
    public $Mailer = 'mail';

    /**
     * The path to the sendmail program.
     * @var string
     */
    public $Sendmail = '/usr/sbin/sendmail';

    /**
     * Whether mail() uses a fully sendmail-compatible MTA.
     * One which supports sendmail's "-oi -f" options.
     * @var boolean
     */
    public $UseSendmailOptions = true;

    /**
     * Path to PHPMailer plugins.
     * Useful if the SMTP class is not in the PHP include path.
     * @var string
     * @deprecated Should not be needed now there is an autoloader.
     */
    public $PluginDir = '';

    /**
     * The email address that a reading confirmation should be sent to, also known as read receipt.
     * @var string
     */
    public $ConfirmReadingTo = '';

    /**
     * The hostname to use in the Message-ID header and as default HELO string.
     * If empty, PHPMailer attempts to find one with, in order,
     * $_SERVER['SERVER_NAME'], gethostname(), php_uname('n'), or the value
     * 'localhost.localdomain'.
     * @var string
     */
    public $Hostname = '';

    /**
     * An ID to be used in the Message-ID header.
     * If empty, a unique id will be generated.
     * @var string
     */
    public $MessageID = '';

    /**
     * The message Date to be used in the Date header.
     * If empty, the current date will be added.
     * @var string
     */
    public $MessageDate = '';

    /**
     * SMTP hosts.
     * Either a single hostname or multiple semicolon-delimited hostnames.
     * You can also specify a different port
     * for each host by using this format: [hostname:port]
     * (e.g. "smtp1.example.com:25;smtp2.example.com").
     * You can also specify encryption type, for example:
     * (e.g. "tls://smtp1.example.com:587;ssl://smtp2.example.com:465").
     * Hosts will be tried in order.
     * @var string
     */
    public $Host = 'localhost';

    /**
     * The default SMTP server port.
     * @var integer
     * @TODO Why is this needed when the SMTP class takes care of it?
     */
    public $Port = 25;

    /**
     * The SMTP HELO of the message.
     * Default is $Hostname. If $Hostname is empty, PHPMailer attempts to find
     * one with the same method described above for $Hostname.
     * @var string
     * @see PHPMailer::$Hostname
     */
    public $Helo = '';

    /**
     * What kind of encryption to use on the SMTP connection.
     * Options: '', 'ssl' or 'tls'
     * @var string
     */
    public $SMTPSecure = '';

    /**
     * Whether to enable TLS encryption automatically if a server supports it,
     * even if `SMTPSecure` is not set to 'tls'.
     * Be aware that in PHP >= 5.6 this requires that the server's certificates are valid.
     * @var boolean
     */
    public $SMTPAutoTLS = true;

    /**
     * Whether to use SMTP authentication.
     * Uses the Username and Password properties.
     * @var boolean
     * @see PHPMailer::$Username
     * @see PHPMailer::$Password
     */
    public $SMTPAuth = false;

    /**
     * Options array passed to stream_context_create when connecting via SMTP.
     * @var array
     */
    public $SMTPOptions = array();

    /**
     * SMTP username.
     * @var string
     */
    public $Username = '';

    /**
     * SMTP password.
     * @var string
     */
    public $Password = '';

    /**
     * SMTP auth type.
     * Options are CRAM-MD5, LOGIN, PLAIN, NTLM, XOAUTH2, attempted in that order if not specified
     * @var string
     */
    public $AuthType = '';

    /**
     * SMTP realm.
     * Used for NTLM auth
     * @var string
     */
    public $Realm = '';

    /**
     * SMTP workstation.
     * Used for NTLM auth
     * @var string
     */
    public $Workstation = '';

    /**
     * The SMTP server timeout in seconds.
     * Default of 5 minutes (300sec) is from RFC2821 section 4.5.3.2
     * @var integer
     */
    public $Timeout = 300;

    /**
     * SMTP class debug output mode.
     * Debug output level.
     * Options:
     * * `0` No output
     * * `1` Commands
     * * `2` Data and commands
     * * `3` As 2 plus connection status
     * * `4` Low-level data output
     * @var integer
     * @see SMTP::$do_debug
     */
    public $SMTPDebug = 0;

    /**
     * How to handle debug output.
     * Options:
     * * `echo` Output plain-text as-is, appropriate for CLI
     * * `html` Output escaped, line breaks converted to `<br>`, appropriate for browser output
     * * `error_log` Output to error log as configured in php.ini
     *
     * Alternatively, you can provide a callable expecting two params: a message string and the debug level:
     * <code>
     * $mail->Debugoutput = function($str, $level) {echo "debug level $level; message: $str";};
     * </code>
     * @var string|callable
     * @see SMTP::$Debugoutput
     */
    public $Debugoutput = 'echo';

    /**
     * Whether to keep SMTP connection open after each message.
     * If this is set to true then to close the connection
     * requires an explicit call to smtpClose().
     * @var boolean
     */
    public $SMTPKeepAlive = false;

    /**
     * Whether to split multiple to addresses into multiple messages
     * or send them all in one message.
     * Only supported in `mail` and `sendmail` transports, not in SMTP.
     * @var boolean
     */
    public $SingleTo = false;

    /**
     * Storage for addresses when SingleTo is enabled.
     * @var array
     * @TODO This should really not be public
     */
    public $SingleToArray = array();

    /**
     * Whether to generate VERP addresses on send.
     * Only applicable when sending via SMTP.
     * @link https://en.wikipedia.org/wiki/Variable_envelope_return_path
     * @link http://www.postfix.org/VERP_README.html Postfix VERP info
     * @var boolean
     */
    public $do_verp = false;

    /**
     * Whether to allow sending messages with an empty body.
     * @var boolean
     */
    public $AllowEmpty = false;

    /**
     * The default line ending.
     * @note The default remains "\n". We force CRLF where we know
     *        it must be used via self::CRLF.
     * @var string
     */
    public $LE = "\n";

    /**
     * DKIM selector.
     * @var string
     */
    public $DKIM_selector = '';

    /**
     * DKIM Identity.
     * Usually the email address used as the source of the email.
     * @var string
     */
    public $DKIM_identity = '';

    /**
     * DKIM passphrase.
     * Used if your key is encrypted.
     * @var string
     */
    public $DKIM_passphrase = '';

    /**
     * DKIM signing domain name.
     * @example 'example.com'
     * @var string
     */
    public $DKIM_domain = '';

    /**
     * DKIM private key file path.
     * @var string
     */
    public $DKIM_private = '';

    /**
     * Callback Action function name.
     *
     * The function that handles the result of the send email action.
     * It is called out by send() for each email sent.
     *
     * Value can be any php callable: http://www.php.net/is_callable
     *
     * Parameters:
     *   boolean $result        result of the send action
     *   string  $to            email address of the recipient
     *   string  $cc            cc email addresses
     *   string  $bcc           bcc email addresses
     *   string  $subject       the subject
     *   string  $body          the email body
     *   string  $from          email address of sender
     * @var string
     */
    public $action_function = '';

    /**
     * What to put in the X-Mailer header.
     * Options: An empty string for PHPMailer default, whitespace for none, or a string to use
     * @var string
     */
    public $XMailer = '';

    /**
     * Which validator to use by default when validating email addresses.
     * May be a callable to inject your own validator, but there are several built-in validators.
     * @see PHPMailer::validateAddress()
     * @var string|callable
     * @static
     */
    public static $validator = 'auto';

    /**
     * An instance of the SMTP sender class.
     * @var SMTP
     * @access protected
     */
    protected $smtp = null;

    /**
     * The array of 'to' names and addresses.
     * @var array
     * @access protected
     */
    protected $to = array();

    /**
     * The array of 'cc' names and addresses.
     * @var array
     * @access protected
     */
    protected $cc = array();

    /**
     * The array of 'bcc' names and addresses.
     * @var array
     * @access protected
     */
    protected $bcc = array();

    /**
     * The array of reply-to names and addresses.
     * @var array
     * @access protected
     */
    protected $ReplyTo = array();

    /**
     * An array of all kinds of addresses.
     * Includes all of $to, $cc, $bcc
     * @var array
     * @access protected
     * @see PHPMailer::$to @see PHPMailer::$cc @see PHPMailer::$bcc
     */
    protected $all_recipients = array();

    /**
     * An array of names and addresses queued for validation.
     * In send(), valid and non duplicate entries are moved to $all_recipients
     * and one of $to, $cc, or $bcc.
     * This array is used only for addresses with IDN.
     * @var array
     * @access protected
     * @see PHPMailer::$to @see PHPMailer::$cc @see PHPMailer::$bcc
     * @see PHPMailer::$all_recipients
     */
    protected $RecipientsQueue = array();

    /**
     * An array of reply-to names and addresses queued for validation.
     * In send(), valid and non duplicate entries are moved to $ReplyTo.
     * This array is used only for addresses with IDN.
     * @var array
     * @access protected
     * @see PHPMailer::$ReplyTo
     */
    protected $ReplyToQueue = array();

    /**
     * The array of attachments.
     * @var array
     * @access protected
     */
    protected $attachment = array();

    /**
     * The array of custom headers.
     * @var array
     * @access protected
     */
    protected $CustomHeader = array();

    /**
     * The most recent Message-ID (including angular brackets).
     * @var string
     * @access protected
     */
    protected $lastMessageID = '';

    /**
     * The message's MIME type.
     * @var string
     * @access protected
     */
    protected $message_type = '';

    /**
     * The array of MIME boundary strings.
     * @var array
     * @access protected
     */
    protected $boundary = array();

    /**
     * The array of available languages.
     * @var array
     * @access protected
     */
    protected $language = array();

    /**
     * The number of errors encountered.
     * @var integer
     * @access protected
     */
    protected $error_count = 0;

    /**
     * The S/MIME certificate file path.
     * @var string
     * @access protected
     */
    protected $sign_cert_file = '';

    /**
     * The S/MIME key file path.
     * @var string
     * @access protected
     */
    protected $sign_key_file = '';

    /**
     * The optional S/MIME extra certificates ("CA Chain") file path.
     * @var string
     * @access protected
     */
    protected $sign_extracerts_file = '';

    /**
     * The S/MIME password for the key.
     * Used only if the key is encrypted.
     * @var string
     * @access protected
     */
    protected $sign_key_pass = '';

    /**
     * Whether to throw exceptions for errors.
     * @var boolean
     * @access protected
     */
    protected $exceptions = false;

    /**
     * Unique ID used for message ID and boundaries.
     * @var string
     * @access protected
     */
    protected $uniqueid = '';

    /**
     * Error severity: message only, continue processing.
     */
    const STOP_MESSAGE = 0;

    /**
     * Error severity: message, likely ok to continue processing.
     */
    const STOP_CONTINUE = 1;

    /**
     * Error severity: message, plus full stop, critical error reached.
     */
    const STOP_CRITICAL = 2;

    /**
     * SMTP RFC standard line ending.
     */
    const CRLF = "\r\n";

    /**
     * The maximum line length allowed by RFC 2822 section 2.1.1
     * @var integer
     */
    const MAX_LINE_LENGTH = 998;

    /**
     * Constructor.
     * @param boolean $exceptions Should we throw external exceptions?
     */
    public function __construct($exceptions = null)
    {
        if ($exceptions !== null) {
            $this->exceptions = (boolean)$exceptions;
        }
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        //Close any open SMTP connection nicely
        $this->smtpClose();
    }

    /**
     * Call mail() in a safe_mode-aware fashion.
     * Also, unless sendmail_path points to sendmail (or something that
     * claims to be sendmail), don't pass params (not a perfect fix,
     * but it will do)
     * @param string $to To
     * @param string $subject Subject
     * @param string $body Message Body
     * @param string $header Additional Header(s)
     * @param string $params Params
     * @access private
     * @return boolean
     */
    private function mailPassthru($to, $subject, $body, $header, $params)
    {
        //Check overloading of mail function to avoid double-encoding
        if (ini_get('mbstring.func_overload') & 1) {
            $subject = $this->secureHeader($subject);
        } else {
            $subject = $this->encodeHeader($this->secureHeader($subject));
        }
        //Can't use additional_parameters in safe_mode
        //@link http://php.net/manual/en/function.mail.php
        if (ini_get('safe_mode') or !$this->UseSendmailOptions) {
            $result = @mail($to, $subject, $body, $header);
        } else {
            $result = @mail($to, $subject, $body, $header, $params);
        }
        return $result;
    }

    /**
     * Output debugging info via user-defined method.
     * Only generates output if SMTP debug output is enabled (@see SMTP::$do_debug).
     * @see PHPMailer::$Debugoutput
     * @see PHPMailer::$SMTPDebug
     * @param string $str
     */
    protected function edebug($str)
    {
        if ($this->SMTPDebug <= 0) {
            return;
        }
        //Avoid clash with built-in function names
        if (!in_array($this->Debugoutput, array('error_log', 'html', 'echo')) and is_callable($this->Debugoutput)) {
            call_user_func($this->Debugoutput, $str, $this->SMTPDebug);
            return;
        }
        switch ($this->Debugoutput) {
            case 'error_log':
                //Don't output, just log
                error_log($str);
                break;
            case 'html':
                //Cleans up output a bit for a better looking, HTML-safe output
                echo htmlentities(
                    preg_replace('/[\r\n]+/', '', $str),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . "<br>\n";
                break;
            case 'echo':
            default:
                //Normalize line breaks
                $str = preg_replace('/\r\n?/ms', "\n", $str);
                echo gmdate('Y-m-d H:i:s') . "\t" . str_replace(
                    "\n",
                    "\n                   \t                  ",
                    trim($str)
                ) . "\n";
        }
    }

    /**
     * Sets message type to HTML or plain.
     * @param boolean $isHtml True for HTML mode.
     * @return void
     */
    public function isHTML($isHtml = true)
    {
        if ($isHtml) {
            $this->ContentType = 'text/html';
        } else {
            $this->ContentType = 'text/plain';
        }
    }

    /**
     * Send messages using SMTP.
     * @return void
     */
    public function isSMTP()
    {
        $this->Mailer = 'smtp';
    }

    /**
     * Send messages using PHP's mail() function.
     * @return void
     */
    public function isMail()
    {
        $this->Mailer = 'mail';
    }

    /**
     * Send messages using $Sendmail.
     * @return void
     */
    public function isSendmail()
    {
        $ini_sendmail_path = ini_get('sendmail_path');

        if (!stristr($ini_sendmail_path, 'sendmail')) {
            $this->Sendmail = '/usr/sbin/sendmail';
        } else {
            $this->Sendmail = $ini_sendmail_path;
        }
        $this->Mailer = 'sendmail';
    }

    /**
     * Send messages using qmail.
     * @return void
     */
    public function isQmail()
    {
        $ini_sendmail_path = ini_get('sendmail_path');

        if (!stristr($ini_sendmail_path, 'qmail')) {
            $this->Sendmail = '/var/qmail/bin/qmail-inject';
        } else {
            $this->Sendmail = $ini_sendmail_path;
        }
        $this->Mailer = 'qmail';
    }

    /**
     * Add a "To" address.
     * @param string $address The email address to send to
     * @param string $name
     * @return boolean true on success, false if address already used or invalid in some way
     */
    public function addAddress($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('to', $address, $name);
    }

    /**
     * Add a "CC" address.
     * @note: This function works with the SMTP mailer on win32, not with the "mail" mailer.
     * @param string $address The email address to send to
     * @param string $name
     * @return boolean true on success, false if address already used or invalid in some way
     */
    public function addCC($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('cc', $address, $name);
    }

    /**
     * Add a "BCC" address.
     * @note: This function works with the SMTP mailer on win32, not with the "mail" mailer.
     * @param string $address The email address to send to
     * @param string $name
     * @return boolean true on success, false if address already used or invalid in some way
     */
    public function addBCC($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('bcc', $address, $name);
    }

    /**
     * Add a "Reply-To" address.
     * @param string $address The email address to reply to
     * @param string $name
     * @return boolean true on success, false if address already used or invalid in some way
     */
    public function addReplyTo($address, $name = '')
    {
        return $this->addOrEnqueueAnAddress('Reply-To', $address, $name);
    }

    /**
     * Add an address to one of the recipient arrays or to the ReplyTo array. Because PHPMailer
     * can't validate addresses with an IDN without knowing the PHPMailer::$CharSet (that can still
     * be modified after calling this function), addition of such addresses is delayed until send().
     * Addresses that have been added already return false, but do not throw exceptions.
     * @param string $kind One of 'to', 'cc', 'bcc', or 'ReplyTo'
     * @param string $address The email address to send, resp. to reply to
     * @param string $name
     * @throws phpmailerException
     * @return boolean true on success, false if address already used or invalid in some way
     * @access protected
     */
    protected function addOrEnqueueAnAddress($kind, $address, $name)
    {
        $address = trim($address);
        $name = trim(preg_replace('/[\r\n]+/', '', $name)); //Strip breaks and trim
        if (($pos = strrpos($address, '@')) === false) {
            // At-sign is misssing.
            $error_message = $this->lang('invalid_address') . " (addAnAddress $kind): $address";
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new phpmailerException($error_message);
            }
            return false;
        }
        $params = array($kind, $address, $name);
        // Enqueue addresses with IDN until we know the PHPMailer::$CharSet.
        if ($this->has8bitChars(substr($address, ++$pos)) and $this->idnSupported()) {
            if ($kind != 'Reply-To') {
                if (!array_key_exists($address, $this->RecipientsQueue)) {
                    $this->RecipientsQueue[$address] = $params;
                    return true;
                }
            } else {
                if (!array_key_exists($address, $this->ReplyToQueue)) {
                    $this->ReplyToQueue[$address] = $params;
                    return true;
                }
            }
            return false;
        }
        // Immediately add standard addresses without IDN.
        return call_user_func_array(array($this, 'addAnAddress'), $params);
    }

    /**
     * Add an address to one of the recipient arrays or to the ReplyTo array.
     * Addresses that have been added already return false, but do not throw exceptions.
     * @param string $kind One of 'to', 'cc', 'bcc', or 'ReplyTo'
     * @param string $address The email address to send, resp. to reply to
     * @param string $name
     * @throws phpmailerException
     * @return boolean true on success, false if address already used or invalid in some way
     * @access protected
     */
    protected function addAnAddress($kind, $address, $name = '')
    {
        if (!in_array($kind, array('to', 'cc', 'bcc', 'Reply-To'))) {
            $error_message = $this->lang('Invalid recipient kind: ') . $kind;
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new phpmailerException($error_message);
            }
            return false;
        }
        if (!$this->validateAddress($address)) {
            $error_message = $this->lang('invalid_address') . " (addAnAddress $kind): $address";
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new phpmailerException($error_message);
            }
            return false;
        }
        if ($kind != 'Reply-To') {
            if (!array_key_exists(strtolower($address), $this->all_recipients)) {
                array_push($this->$kind, array($address, $name));
                $this->all_recipients[strtolower($address)] = true;
                return true;
            }
        } else {
            if (!array_key_exists(strtolower($address), $this->ReplyTo)) {
                $this->ReplyTo[strtolower($address)] = array($address, $name);
                return true;
            }
        }
        return false;
    }

    /**
     * Parse and validate a string containing one or more RFC822-style comma-separated email addresses
     * of the form "display name <address>" into an array of name/address pairs.
     * Uses the imap_rfc822_parse_adrlist function if the IMAP extension is available.
     * Note that quotes in the name part are removed.
     * @param string $addrstr The address list string
     * @param bool $useimap Whether to use the IMAP extension to parse the list
     * @return array
     * @link http://www.andrew.cmu.edu/user/agreen1/testing/mrbs/web/Mail/RFC822.php A more careful implementation
     */
    public function parseAddresses($addrstr, $useimap = true)
    {
        $addresses = array();
        if ($useimap and function_exists('imap_rfc822_parse_adrlist')) {
            //Use this built-in parser if it's available
            $list = imap_rfc822_parse_adrlist($addrstr, '');
            foreach ($list as $address) {
                if ($address->host != '.SYNTAX-ERROR.') {
                    if ($this->validateAddress($address->mailbox . '@' . $address->host)) {
                        $addresses[] = array(
                            'name' => (property_exists($address, 'personal') ? $address->personal : ''),
                            'address' => $address->mailbox . '@' . $address->host
                        );
                    }
                }
            }
        } else {
            //Use this simpler parser
            $list = explode(',', $addrstr);
            foreach ($list as $address) {
                $address = trim($address);
                //Is there a separate name part?
                if (strpos($address, '<') === false) {
                    //No separate name, just use the whole thing
                    if ($this->validateAddress($address)) {
                        $addresses[] = array(
                            'name' => '',
                            'address' => $address
                        );
                    }
                } else {
                    list($name, $email) = explode('<', $address);
                    $email = trim(str_replace('>', '', $email));
                    if ($this->validateAddress($email)) {
                        $addresses[] = array(
                            'name' => trim(str_replace(array('"', "'"), '', $name)),
                            'address' => $email
                        );
                    }
                }
            }
        }
        return $addresses;
    }

    /**
     * Set the From and FromName properties.
     * @param string $address
     * @param string $name
     * @param boolean $auto Whether to also set the Sender address, defaults to true
     * @throws phpmailerException
     * @return boolean
     */
    public function setFrom($address, $name = '', $auto = true)
    {
        $address = trim($address);
        $name = trim(preg_replace('/[\r\n]+/', '', $name)); //Strip breaks and trim
        // Don't validate now addresses with IDN. Will be done in send().
        if (($pos = strrpos($address, '@')) === false or
            (!$this->has8bitChars(substr($address, ++$pos)) or !$this->idnSupported()) and
            !$this->validateAddress($address)) {
            $error_message = $this->lang('invalid_address') . " (setFrom) $address";
            $this->setError($error_message);
            $this->edebug($error_message);
            if ($this->exceptions) {
                throw new phpmailerException($error_message);
            }
            return false;
        }
        $this->From = $address;
        $this->FromName = $name;
        if ($auto) {
            if (empty($this->Sender)) {
                $this->Sender = $address;
            }
        }
        return true;
    }

    /**
     * Return the Message-ID header of the last email.
     * Technically this is the value from the last time the headers were created,
     * but it's also the message ID of the last sent message except in
     * pathological cases.
     * @return string
     */
    public function getLastMessageID()
    {
        return $this->lastMessageID;
    }

    /**
     * Check that a string looks like an email address.
     * @param string $address The email address to check
     * @param string|callable $patternselect A selector for the validation pattern to use :
     * * `auto` Pick best pattern automatically;
     * * `pcre8` Use the squiloople.com pattern, requires PCRE > 8.0, PHP >= 5.3.2, 5.2.14;
     * * `pcre` Use old PCRE implementation;
     * * `php` Use PHP built-in FILTER_VALIDATE_EMAIL;
     * * `html5` Use the pattern given by the HTML5 spec for 'email' type form input elements.
     * * `noregex` Don't use a regex: super fast, really dumb.
     * Alternatively you may pass in a callable to inject your own validator, for example:
     * PHPMailer::validateAddress('user@example.com', function($address) {
     *     return (strpos($address, '@') !== false);
     * });
     * You can also set the PHPMailer::$validator static to a callable, allowing built-in methods to use your validator.
     * @return boolean
     * @static
     * @access public
     */
    public static function validateAddress($address, $patternselect = null)
    {
        if (is_null($patternselect)) {
            $patternselect = self::$validator;
        }
        if (is_callable($patternselect)) {
            return call_user_func($patternselect, $address);
        }
        //Reject line breaks in addresses; it's valid RFC5322, but not RFC5321
        if (strpos($address, "\n") !== false or strpos($address, "\r") !== false) {
            return false;
        }
        if (!$patternselect or $patternselect == 'auto') {
            //Check this constant first so it works when extension_loaded() is disabled by safe mode
            //Constant was added in PHP 5.2.4
            if (defined('PCRE_VERSION')) {
                //This pattern can get stuck in a recursive loop in PCRE <= 8.0.2
                if (version_compare(PCRE_VERSION, '8.0.3') >= 0) {
                    $patternselect = 'pcre8';
                } else {
                    $patternselect = 'pcre';
                }
            } elseif (function_exists('extension_loaded') and extension_loaded('pcre')) {
                //Fall back to older PCRE
                $patternselect = 'pcre';
            } else {
                //Filter_var appeared in PHP 5.2.0 and does not require the PCRE extension
                if (version_compare(PHP_VERSION, '5.2.0') >= 0) {
                    $patternselect = 'php';
                } else {
                    $patternselect = 'noregex';
                }
            }
        }
        switch ($patternselect) {
            case 'pcre8':
                /**
                 * Uses the same RFC5322 regex on which FILTER_VALIDATE_EMAIL is based, but allows dotless domains.
                 * @link http://squiloople.com/2009/12/20/email-address-validation/
                 * @copyright 2009-2010 Michael Rushton
                 * Feel free to use and redistribute this code. But please keep this copyright notice.
                 */
                return (boolean)preg_match(
                    '/^(?!(?>(?1)"?(?>\\\[ -~]|[^"])"?(?1)){255,})(?!(?>(?1)"?(?>\\\[ -~]|[^"])"?(?1)){65,}@)' .
                    '((?>(?>(?>((?>(?>(?>\x0D\x0A)?[\t ])+|(?>[\t ]*\x0D\x0A)?[\t ]+)?)(\((?>(?2)' .
                    '(?>[\x01-\x08\x0B\x0C\x0E-\'*-\[\]-\x7F]|\\\[\x00-\x7F]|(?3)))*(?2)\)))+(?2))|(?2))?)' .
                    '([!#-\'*+\/-9=?^-~-]+|"(?>(?2)(?>[\x01-\x08\x0B\x0C\x0E-!#-\[\]-\x7F]|\\\[\x00-\x7F]))*' .
                    '(?2)")(?>(?1)\.(?1)(?4))*(?1)@(?!(?1)[a-z0-9-]{64,})(?1)(?>([a-z0-9](?>[a-z0-9-]*[a-z0-9])?)' .
                    '(?>(?1)\.(?!(?1)[a-z0-9-]{64,})(?1)(?5)){0,126}|\[(?:(?>IPv6:(?>([a-f0-9]{1,4})(?>:(?6)){7}' .
                    '|(?!(?:.*[a-f0-9][:\]]){8,})((?6)(?>:(?6)){0,6})?::(?7)?))|(?>(?>IPv6:(?>(?6)(?>:(?6)){5}:' .
                    '|(?!(?:.*[a-f0-9]:){6,})(?8)?::(?>((?6)(?>:(?6)){0,4}):)?))?(25[0-5]|2[0-4][0-9]|1[0-9]{2}' .
                    '|[1-9]?[0-9])(?>\.(?9)){3}))\])(?1)$/isD',
                    $address
                );
            case 'pcre':
                //An older regex that doesn't need a recent PCRE
                return (boolean)preg_match(
                    '/^(?!(?>"?(?>\\\[ -~]|[^"])"?){255,})(?!(?>"?(?>\\\[ -~]|[^"])"?){65,}@)(?>' .
                    '[!#-\'*+\/-9=?^-~-]+|"(?>(?>[\x01-\x08\x0B\x0C\x0E-!#-\[\]-\x7F]|\\\[\x00-\xFF]))*")' .
                    '(?>\.(?>[!#-\'*+\/-9=?^-~-]+|"(?>(?>[\x01-\x08\x0B\x0C\x0E-!#-\[\]-\x7F]|\\\[\x00-\xFF]))*"))*' .
                    '@(?>(?![a-z0-9-]{64,})(?>[a-z0-9](?>[a-z0-9-]*[a-z0-9])?)(?>\.(?![a-z0-9-]{64,})' .
                    '(?>[a-z0-9](?>[a-z0-9-]*[a-z0-9])?)){0,126}|\[(?:(?>IPv6:(?>(?>[a-f0-9]{1,4})(?>:' .
                    '[a-f0-9]{1,4}){7}|(?!(?:.*[a-f0-9][:\]]){8,})(?>[a-f0-9]{1,4}(?>:[a-f0-9]{1,4}){0,6})?' .
                    '::(?>[a-f0-9]{1,4}(?>:[a-f0-9]{1,4}){0,6})?))|(?>(?>IPv6:(?>[a-f0-9]{1,4}(?>:' .
                    '[a-f0-9]{1,4}){5}:|(?!(?:.*[a-f0-9]:){6,})(?>[a-f0-9]{1,4}(?>:[a-f0-9]{1,4}){0,4})?' .
                    '::(?>(?:[a-f0-9]{1,4}(?>:[a-f0-9]{1,4}){0,4}):)?))?(?>25[0-5]|2[0-4][0-9]|1[0-9]{2}' .
                    '|[1-9]?[0-9])(?>\.(?>25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}))\])$/isD',
                    $address
                );
            case 'html5':
                /**
                 * This is the pattern used in the HTML5 spec for validation of 'email' type form input elements.
                 * @link http://www.whatwg.org/specs/web-apps/current-work/#e-mail-state-(type=email)
                 */
                return (boolean)preg_match(
                    '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}' .
                    '[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/sD',
                    $address
                );
            case 'noregex':
                //No PCRE! Do something _very_ approximate!
                //Check the address is 3 chars or longer and contains an @ that's not the first or last char
                return (strlen($address) >= 3
                    and strpos($address, '@') >= 1
                    and strpos($address, '@') != strlen($address) - 1);
            case 'php':
            default:
                return (boolean)filter_var($address, FILTER_VALIDATE_EMAIL);
        }
    }

    /**
     * Tells whether IDNs (Internationalized Domain Names) are supported or not. This requires the
     * "intl" and "mbstring" PHP extensions.
     * @return bool "true" if required functions for IDN support are present
     */
    public function idnSupported()
    {
        // @TODO: Write our own "idn_to_ascii" function for PHP <= 5.2.
        return function_exists('idn_to_ascii') and function_exists('mb_convert_encoding');
    }

    /**
     * Converts IDN in given email address to its ASCII form, also known as punycode, if possible.
     * Important: Address must be passed in same encoding as currently set in PHPMailer::$CharSet.
     * This function silently returns unmodified address if:
     * - No conversion is necessary (i.e. domain name is not an IDN, or is already in ASCII form)
     * - Conversion to punycode is impossible (e.g. required PHP functions are not available)
     *   or fails for any reason (e.g. domain has characters not allowed in an IDN)
     * @see PHPMailer::$CharSet
     * @param string $address The email address to convert
     * @return string The encoded address in ASCII form
     */
    public function punyencodeAddress($address)
    {
        // Verify we have required functions, CharSet, and at-sign.
        if ($this->idnSupported() and
            !empty($this->CharSet) and
            ($pos = strrpos($address, '@')) !== false) {
            $domain = substr($address, ++$pos);
            // Verify CharSet string is a valid one, and domain properly encoded in this CharSet.
            if ($this->has8bitChars($domain) and @mb_check_encoding($domain, $this->CharSet)) {
                $domain = mb_convert_encoding($domain, 'UTF-8', $this->CharSet);
                if (($punycode = defined('INTL_IDNA_VARIANT_UTS46') ?
                    idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46) :
                    idn_to_ascii($domain)) !== false) {
                    return substr($address, 0, $pos) . $punycode;
                }
            }
        }
        return $address;
    }

    /**
     * Create a message and send it.
     * Uses the sending method specified by $Mailer.
     * @throws phpmailerException
     * @return boolean false on error - See the ErrorInfo property for details of the error.
     */
    public function send()
    {
        try {
            if (!$this->preSend()) {
                return false;
            }
            return $this->postSend();
        } catch (phpmailerException $exc) {
            $this->mailHeader = '';
            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }
    }

    /**
     * Prepare a message for sending.
     * @throws phpmailerException
     * @return boolean
     */
    public function preSend()
    {
        try {
            $this->error_count = 0; // Reset errors
            $this->mailHeader = '';

            // Dequeue recipient and Reply-To addresses with IDN
            foreach (array_merge($this->RecipientsQueue, $this->ReplyToQueue) as $params) {
                $params[1] = $this->punyencodeAddress($params[1]);
                call_user_func_array(array($this, 'addAnAddress'), $params);
            }
            if ((count($this->to) + count($this->cc) + count($this->bcc)) < 1) {
                throw new phpmailerException($this->lang('provide_address'), self::STOP_CRITICAL);
            }

            // Validate From, Sender, and ConfirmReadingTo addresses
            foreach (array('From', 'Sender', 'ConfirmReadingTo') as $address_kind) {
                $this->$address_kind = trim($this->$address_kind);
                if (empty($this->$address_kind)) {
                    continue;
                }
                $this->$address_kind = $this->punyencodeAddress($this->$address_kind);
                if (!$this->validateAddress($this->$address_kind)) {
                    $error_message = $this->lang('invalid_address') . ' (punyEncode) ' . $this->$address_kind;
                    $this->setError($error_message);
                    $this->edebug($error_message);
                    if ($this->exceptions) {
                        throw new phpmailerException($error_message);
                    }
                    return false;
                }
            }

            // Set whether the message is multipart/alternative
            if ($this->alternativeExists()) {
                $this->ContentType = 'multipart/alternative';
            }

            $this->setMessageType();
            // Refuse to send an empty message unless we are specifically allowing it
            if (!$this->AllowEmpty and empty($this->Body)) {
                throw new phpmailerException($this->lang('empty_message'), self::STOP_CRITICAL);
            }

            // Create body before headers in case body makes changes to headers (e.g. altering transfer encoding)
            $this->MIMEHeader = '';
            $this->MIMEBody = $this->createBody();
            // createBody may have added some headers, so retain them
            $tempheaders = $this->MIMEHeader;
            $this->MIMEHeader = $this->createHeader();
            $this->MIMEHeader .= $tempheaders;

            // To capture the complete message when using mail(), create
            // an extra header list which createHeader() doesn't fold in
            if ($this->Mailer == 'mail') {
                if (count($this->to) > 0) {
                    $this->mailHeader .= $this->addrAppend('To', $this->to);
                } else {
                    $this->mailHeader .= $this->headerLine('To', 'undisclosed-recipients:;');
                }
                $this->mailHeader .= $this->headerLine(
                    'Subject',
                    $this->encodeHeader($this->secureHeader(trim($this->Subject)))
                );
            }

            // Sign with DKIM if enabled
            if (!empty($this->DKIM_domain)
                && !empty($this->DKIM_private)
                && !empty($this->DKIM_selector)
                && file_exists($this->DKIM_private)) {
                $header_dkim = $this->DKIM_Add(
                    $this->MIMEHeader . $this->mailHeader,
                    $this->encodeHeader($this->secureHeader($this->Subject)),
                    $this->MIMEBody
                );
                $this->MIMEHeader = rtrim($this->MIMEHeader, "\r\n ") . self::CRLF .
                    str_replace("\r\n", "\n", $header_dkim) . self::CRLF;
            }
            return true;
        } catch (phpmailerException $exc) {
            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }
    }

    /**
     * Actually send a message.
     * Send the email via the selected mechanism
     * @throws phpmailerException
     * @return boolean
     */
    public function postSend()
    {
        try {
            // Choose the mailer and send through it
            switch ($this->Mailer) {
                case 'sendmail':
                case 'qmail':
                    return $this->sendmailSend($this->MIMEHeader, $this->MIMEBody);
                case 'smtp':
                    return $this->smtpSend($this->MIMEHeader, $this->MIMEBody);
                case 'mail':
                    return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
                default:
                    $sendMethod = $this->Mailer.'Send';
                    if (method_exists($this, $sendMethod)) {
                        return $this->$sendMethod($this->MIMEHeader, $this->MIMEBody);
                    }

                    return $this->mailSend($this->MIMEHeader, $this->MIMEBody);
            }
        } catch (phpmailerException $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
        }
        return false;
    }

    /**
     * Send mail using the $Sendmail program.
     * @param string $header The message headers
     * @param string $body The message body
     * @see PHPMailer::$Sendmail
     * @throws phpmailerException
     * @access protected
     * @return boolean
     */
    protected function sendmailSend($header, $body)
    {
        if ($this->Sender != '') {
            if ($this->Mailer == 'qmail') {
                $sendmail = sprintf('%s -f%s', escapeshellcmd($this->Sendmail), escapeshellarg($this->Sender));
            } else {
                $sendmail = sprintf('%s -oi -f%s -t', escapeshellcmd($this->Sendmail), escapeshellarg($this->Sender));
            }
        } else {
            if ($this->Mailer == 'qmail') {
                $sendmail = sprintf('%s', escapeshellcmd($this->Sendmail));
            } else {
                $sendmail = sprintf('%s -oi -t', escapeshellcmd($this->Sendmail));
            }
        }
        if ($this->SingleTo) {
            foreach ($this->SingleToArray as $toAddr) {
                if (!@$mail = popen($sendmail, 'w')) {
                    throw new phpmailerException($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
                }
                fputs($mail, 'To: ' . $toAddr . "\n");
                fputs($mail, $header);
                fputs($mail, $body);
                $result = pclose($mail);
                $this->doCallback(
                    ($result == 0),
                    array($toAddr),
                    $this->cc,
                    $this->bcc,
                    $this->Subject,
                    $body,
                    $this->From
                );
                if ($result != 0) {
                    throw new phpmailerException($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
                }
            }
        } else {
            if (!@$mail = popen($sendmail, 'w')) {
                throw new phpmailerException($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
            }
            fputs($mail, $header);
            fputs($mail, $body);
            $result = pclose($mail);
            $this->doCallback(
                ($result == 0),
                $this->to,
                $this->cc,
                $this->bcc,
                $this->Subject,
                $body,
                $this->From
            );
            if ($result != 0) {
                throw new phpmailerException($this->lang('execute') . $this->Sendmail, self::STOP_CRITICAL);
            }
        }
        return true;
    }

    /**
     * Send mail using the PHP mail() function.
     * @param string $header The message headers
     * @param string $body The message body
     * @link http://www.php.net/manual/en/book.mail.php
     * @throws phpmailerException
     * @access protected
     * @return boolean
     */
    protected function mailSend($header, $body)
    {
        $toArr = array();
        foreach ($this->to as $toaddr) {
            $toArr[] = $this->addrFormat($toaddr);
        }
        $to = implode(', ', $toArr);

        $params = null;
        //This sets the SMTP envelope sender which gets turned into a return-path header by the receiver
        if (!empty($this->Sender)) {
            $params = sprintf('-f%s', $this->Sender);
        }
        if ($this->Sender != '' and !ini_get('safe_mode')) {
            $old_from = ini_get('sendmail_from');
            ini_set('sendmail_from', $this->Sender);
        }
        $result = false;
        if ($this->SingleTo and count($toArr) > 1) {
            foreach ($toArr as $toAddr) {
                $result = $this->mailPassthru($toAddr, $this->Subject, $body, $header, $params);
                $this->doCallback($result, array($toAddr), $this->cc, $this->bcc, $this->Subject, $body, $this->From);
            }
        } else {
            $result = $this->mailPassthru($to, $this->Subject, $body, $header, $params);
            $this->doCallback($result, $this->to, $this->cc, $this->bcc, $this->Subject, $body, $this->From);
        }
        if (isset($old_from)) {
            ini_set('sendmail_from', $old_from);
        }
        if (!$result) {
            throw new phpmailerException($this->lang('instantiate'), self::STOP_CRITICAL);
        }
        return true;
    }

    /**
     * Get an instance to use for SMTP operations.
     * Override this function to load your own SMTP implementation
     * @return SMTP
     */
    public function getSMTPInstance()
    {
        if (!is_object($this->smtp)) {
            $this->smtp = new SMTP;
        }
        return $this->smtp;
    }

    /**
     * Send mail via SMTP.
     * Returns false if there is a bad MAIL FROM, RCPT, or DATA input.
     * Uses the PHPMailerSMTP class by default.
     * @see PHPMailer::getSMTPInstance() to use a different class.
     * @param string $header The message headers
     * @param string $body The message body
     * @throws phpmailerException
     * @uses SMTP
     * @access protected
     * @return boolean
     */
    protected function smtpSend($header, $body)
    {
        $bad_rcpt = array();
        if (!$this->smtpConnect($this->SMTPOptions)) {
            throw new phpmailerException($this->lang('smtp_connect_failed'), self::STOP_CRITICAL);
        }
        if ('' == $this->Sender) {
            $smtp_from = $this->From;
        } else {
            $smtp_from = $this->Sender;
        }
        if (!$this->smtp->mail($smtp_from)) {
            $this->setError($this->lang('from_failed') . $smtp_from . ' : ' . implode(',', $this->smtp->getError()));
            throw new phpmailerException($this->ErrorInfo, self::STOP_CRITICAL);
        }

        // Attempt to send to all recipients
        foreach (array($this->to, $this->cc, $this->bcc) as $togroup) {
            foreach ($togroup as $to) {
                if (!$this->smtp->recipient($to[0])) {
                    $error = $this->smtp->getError();
                    $bad_rcpt[] = array('to' => $to[0], 'error' => $error['detail']);
                    $isSent = false;
                } else {
                    $isSent = true;
                }
                $this->doCallback($isSent, array($to[0]), array(), array(), $this->Subject, $body, $this->From);
            }
        }

        // Only send the DATA command if we have viable recipients
        if ((count($this->all_recipients) > count($bad_rcpt)) and !$this->smtp->data($header . $body)) {
            throw new phpmailerException($this->lang('data_not_accepted'), self::STOP_CRITICAL);
        }
        if ($this->SMTPKeepAlive) {
            $this->smtp->reset();
        } else {
            $this->smtp->quit();
            $this->smtp->close();
        }
        //Create error message for any bad addresses
        if (count($bad_rcpt) > 0) {
            $errstr = '';
            foreach ($bad_rcpt as $bad) {
                $errstr .= $bad['to'] . ': ' . $bad['error'];
            }
            throw new phpmailerException(
                $this->lang('recipients_failed') . $errstr,
                self::STOP_CONTINUE
            );
        }
        return true;
    }

    /**
     * Initiate a connection to an SMTP server.
     * Returns false if the operation failed.
     * @param array $options An array of options compatible with stream_context_create()
     * @uses SMTP
     * @access public
     * @throws phpmailerException
     * @return boolean
     */
    public function smtpConnect($options = null)
    {
        if (is_null($this->smtp)) {
            $this->smtp = $this->getSMTPInstance();
        }

        //If no options are provided, use whatever is set in the instance
        if (is_null($options)) {
            $options = $this->SMTPOptions;
        }

        // Already connected?
        if ($this->smtp->connected()) {
            return true;
        }

        $this->smtp->setTimeout($this->Timeout);
        $this->smtp->setDebugLevel($this->SMTPDebug);
        $this->smtp->setDebugOutput($this->Debugoutput);
        $this->smtp->setVerp($this->do_verp);
        $hosts = explode(';', $this->Host);
        $lastexception = null;

        foreach ($hosts as $hostentry) {
            $hostinfo = array();
            if (!preg_match('/^((ssl|tls):\/\/)*([a-zA-Z0-9\.-]*):?([0-9]*)$/', trim($hostentry), $hostinfo)) {
                // Not a valid host entry
                continue;
            }
            // $hostinfo[2]: optional ssl or tls prefix
            // $hostinfo[3]: the hostname
            // $hostinfo[4]: optional port number
            // The host string prefix can temporarily override the current setting for SMTPSecure
            // If it's not specified, the default value is used
            $prefix = '';
            $secure = $this->SMTPSecure;
            $tls = ($this->SMTPSecure == 'tls');
            if ('ssl' == $hostinfo[2] or ('' == $hostinfo[2] and 'ssl' == $this->SMTPSecure)) {
                $prefix = 'ssl://';
                $tls = false; // Can't have SSL and TLS at the same time
                $secure = 'ssl';
            } elseif ($hostinfo[2] == 'tls') {
                $tls = true;
                // tls doesn't use a prefix
                $secure = 'tls';
            }
            //Do we need the OpenSSL extension?
            $sslext = defined('OPENSSL_ALGO_SHA1');
            if ('tls' === $secure or 'ssl' === $secure) {
                //Check for an OpenSSL constant rather than using extension_loaded, which is sometimes disabled
                if (!$sslext) {
                    throw new phpmailerException($this->lang('extension_missing').'openssl', self::STOP_CRITICAL);
                }
            }
            $host = $hostinfo[3];
            $port = $this->Port;
            $tport = (integer)$hostinfo[4];
            if ($tport > 0 and $tport < 65536) {
                $port = $tport;
            }
            if ($this->smtp->connect($prefix . $host, $port, $this->Timeout, $options)) {
                try {
                    if ($this->Helo) {
                        $hello = $this->Helo;
                    } else {
                        $hello = $this->serverHostname();
                    }
                    $this->smtp->hello($hello);
                    //Automatically enable TLS encryption if:
                    // * it's not disabled
                    // * we have openssl extension
                    // * we are not already using SSL
                    // * the server offers STARTTLS
                    if ($this->SMTPAutoTLS and $sslext and $secure != 'ssl' and $this->smtp->getServerExt('STARTTLS')) {
                        $tls = true;
                    }
                    if ($tls) {
                        if (!$this->smtp->startTLS()) {
                            throw new phpmailerException($this->lang('connect_host'));
                        }
                        // We must resend EHLO after TLS negotiation
                        $this->smtp->hello($hello);
                    }
                    if ($this->SMTPAuth) {
                        if (!$this->smtp->authenticate(
                            $this->Username,
                            $this->Password,
                            $this->AuthType,
                            $this->Realm,
                            $this->Workstation
                        )
                        ) {
                            throw new phpmailerException($this->lang('authenticate'));
                        }
                    }
                    return true;
                } catch (phpmailerException $exc) {
                    $lastexception = $exc;
                    $this->edebug($exc->getMessage());
                    // We must have connected, but then failed TLS or Auth, so close connection nicely
                    $this->smtp->quit();
                }
            }
        }
        // If we get here, all connection attempts have failed, so close connection hard
        $this->smtp->close();
        // As we've caught all exceptions, just report whatever the last one was
        if ($this->exceptions and !is_null($lastexception)) {
            throw $lastexception;
        }
        return false;
    }

    /**
     * Close the active SMTP session if one exists.
     * @return void
     */
    public function smtpClose()
    {
        if (is_a($this->smtp, 'SMTP')) {
            if ($this->smtp->connected()) {
                $this->smtp->quit();
                $this->smtp->close();
            }
        }
    }

    /**
     * Set the language for error messages.
     * Returns false if it cannot load the language file.
     * The default language is English.
     * @param string $langcode ISO 639-1 2-character language code (e.g. French is "fr")
     * @param string $lang_path Path to the language file directory, with trailing separator (slash)
     * @return boolean
     * @access public
     */
    public function setLanguage($langcode = 'en', $lang_path = '')
    {
        // Define full set of translatable strings in English
        $PHPMAILER_LANG = array(
            'authenticate' => 'SMTP Error: Could not authenticate.',
            'connect_host' => 'SMTP Error: Could not connect to SMTP host.',
            'data_not_accepted' => 'SMTP Error: data not accepted.',
            'empty_message' => 'Message body empty',
            'encoding' => 'Unknown encoding: ',
            'execute' => 'Could not execute: ',
            'file_access' => 'Could not access file: ',
            'file_open' => 'File Error: Could not open file: ',
            'from_failed' => 'The following From address failed: ',
            'instantiate' => 'Could not instantiate mail function.',
            'invalid_address' => 'Invalid address: ',
            'mailer_not_supported' => ' mailer is not supported.',
            'provide_address' => 'You must provide at least one recipient email address.',
            'recipients_failed' => 'SMTP Error: The following recipients failed: ',
            'signing' => 'Signing Error: ',
            'smtp_connect_failed' => 'SMTP connect() failed.',
            'smtp_error' => 'SMTP server error: ',
            'variable_set' => 'Cannot set or reset variable: ',
            'extension_missing' => 'Extension missing: '
        );
        if (empty($lang_path)) {
            // Calculate an absolute path so it can work if CWD is not here
            $lang_path = dirname(__FILE__). DIRECTORY_SEPARATOR . 'language'. DIRECTORY_SEPARATOR;
        }
        $foundlang = true;
        $lang_file = $lang_path . 'phpmailer.lang-' . $langcode . '.php';
        // There is no English translation file
        if ($langcode != 'en') {
            // Make sure language file path is readable
            if (!is_readable($lang_file)) {
                $foundlang = false;
            } else {
                // Overwrite language-specific strings.
                // This way we'll never have missing translation keys.
                $foundlang = include $lang_file;
            }
        }
        $this->language = $PHPMAILER_LANG;
        return (boolean)$foundlang; // Returns false if language not found
    }

    /**
     * Get the array of strings for the current language.
     * @return array
     */
    public function getTranslations()
    {
        return $this->language;
    }

    /**
     * Create recipient headers.
     * @access public
     * @param string $type
     * @param array $addr An array of recipient,
     * where each recipient is a 2-element indexed array with element 0 containing an address
     * and element 1 containing a name, like:
     * array(array('joe@example.com', 'Joe User'), array('zoe@example.com', 'Zoe User'))
     * @return string
     */
    public function addrAppend($type, $addr)
    {
        $addresses = array();
        foreach ($addr as $address) {
            $addresses[] = $this->addrFormat($address);
        }
        return $type . ': ' . implode(', ', $addresses) . $this->LE;
    }

    /**
     * Format an address for use in a message header.
     * @access public
     * @param array $addr A 2-element indexed array, element 0 containing an address, element 1 containing a name
     *      like array('joe@example.com', 'Joe User')
     * @return string
     */
    public function addrFormat($addr)
    {
        if (empty($addr[1])) { // No name provided
            return $this->secureHeader($addr[0]);
        } else {
            return $this->encodeHeader($this->secureHeader($addr[1]), 'phrase') . ' <' . $this->secureHeader(
                $addr[0]
            ) . '>';
        }
    }

    /**
     * Word-wrap message.
     * For use with mailers that do not automatically perform wrapping
     * and for quoted-printable encoded messages.
     * Original written by philippe.
     * @param string $message The message to wrap
     * @param integer $length The line length to wrap to
     * @param boolean $qp_mode Whether to run in Quoted-Printable mode
     * @access public
     * @return string
     */
    public function wrapText($message, $length, $qp_mode = false)
    {
        if ($qp_mode) {
            $soft_break = sprintf(' =%s', $this->LE);
        } else {
            $soft_break = $this->LE;
        }
        // If utf-8 encoding is used, we will need to make sure we don't
        // split multibyte characters when we wrap
        $is_utf8 = (strtolower($this->CharSet) == 'utf-8');
        $lelen = strlen($this->LE);
        $crlflen = strlen(self::CRLF);

        $message = $this->fixEOL($message);
        //Remove a trailing line break
        if (substr($message, -$lelen) == $this->LE) {
            $message = substr($message, 0, -$lelen);
        }

        //Split message into lines
        $lines = explode($this->LE, $message);
        //Message will be rebuilt in here
        $message = '';
        foreach ($lines as $line) {
            $words = explode(' ', $line);
            $buf = '';
            $firstword = true;
            foreach ($words as $word) {
                if ($qp_mode and (strlen($word) > $length)) {
                    $space_left = $length - strlen($buf) - $crlflen;
                    if (!$firstword) {
                        if ($space_left > 20) {
                            $len = $space_left;
                            if ($is_utf8) {
                                $len = $this->utf8CharBoundary($word, $len);
                            } elseif (substr($word, $len - 1, 1) == '=') {
                                $len--;
                            } elseif (substr($word, $len - 2, 1) == '=') {
                                $len -= 2;
                            }
                            $part = substr($word, 0, $len);
                            $word = substr($word, $len);
                            $buf .= ' ' . $part;
                            $message .= $buf . sprintf('=%s', self::CRLF);
                        } else {
                            $message .= $buf . $soft_break;
                        }
                        $buf = '';
                    }
                    while (strlen($word) > 0) {
                        if ($length <= 0) {
                            break;
                        }
                        $len = $length;
                        if ($is_utf8) {
                            $len = $this->utf8CharBoundary($word, $len);
                        } elseif (substr($word, $len - 1, 1) == '=') {
                            $len--;
                        } elseif (substr($word, $len - 2, 1) == '=') {
                            $len -= 2;
                        }
                        $part = substr($word, 0, $len);
                        $word = substr($word, $len);

                        if (strlen($word) > 0) {
                            $message .= $part . sprintf('=%s', self::CRLF);
                        } else {
                            $buf = $part;
                        }
                    }
                } else {
                    $buf_o = $buf;
                    if (!$firstword) {
                        $buf .= ' ';
                    }
                    $buf .= $word;

                    if (strlen($buf) > $length and $buf_o != '') {
                        $message .= $buf_o . $soft_break;
                        $buf = $word;
                    }
                }
                $firstword = false;
            }
            $message .= $buf . self::CRLF;
        }

        return $message;
    }

    /**
     * Find the last character boundary prior to $maxLength in a utf-8
     * quoted-printable encoded string.
     * Original written by Colin Brown.
     * @access public
     * @param string $encodedText utf-8 QP text
     * @param integer $maxLength Find the last character boundary prior to this length
     * @return integer
     */
    public function utf8CharBoundary($encodedText, $maxLength)
    {
        $foundSplitPos = false;
        $lookBack = 3;
        while (!$foundSplitPos) {
            $lastChunk = substr($encodedText, $maxLength - $lookBack, $lookBack);
            $encodedCharPos = strpos($lastChunk, '=');
            if (false !== $encodedCharPos) {
                // Found start of encoded character byte within $lookBack block.
                // Check the encoded byte value (the 2 chars after the '=')
                $hex = substr($encodedText, $maxLength - $lookBack + $encodedCharPos + 1, 2);
                $dec = hexdec($hex);
                if ($dec < 128) {
                    // Single byte character.
                    // If the encoded char was found at pos 0, it will fit
                    // otherwise reduce maxLength to start of the encoded char
                    if ($encodedCharPos > 0) {
                        $maxLength = $maxLength - ($lookBack - $encodedCharPos);
                    }
                    $foundSplitPos = true;
                } elseif ($dec >= 192) {
                    // First byte of a multi byte character
                    // Reduce maxLength to split at start of character
                    $maxLength = $maxLength - ($lookBack - $encodedCharPos);
                    $foundSplitPos = true;
                } elseif ($dec < 192) {
                    // Middle byte of a multi byte character, look further back
                    $lookBack += 3;
                }
            } else {
                // No encoded character found
                $foundSplitPos = true;
            }
        }
        return $maxLength;
    }

    /**
     * Apply word wrapping to the message body.
     * Wraps the message body to the number of chars set in the WordWrap property.
     * You should only do this to plain-text bodies as wrapping HTML tags may break them.
     * This is called automatically by createBody(), so you don't need to call it yourself.
     * @access public
     * @return void
     */
    public function setWordWrap()
    {
        if ($this->WordWrap < 1) {
            return;
        }

        switch ($this->message_type) {
            case 'alt':
            case 'alt_inline':
            case 'alt_attach':
            case 'alt_inline_attach':
                $this->AltBody = $this->wrapText($this->AltBody, $this->WordWrap);
                break;
            default:
                $this->Body = $this->wrapText($this->Body, $this->WordWrap);
                break;
        }
    }

    /**
     * Assemble message headers.
     * @access public
     * @return string The assembled headers
     */
    public function createHeader()
    {
        $result = '';

        if ($this->MessageDate == '') {
            $this->MessageDate = self::rfcDate();
        }
        $result .= $this->headerLine('Date', $this->MessageDate);

        // To be created automatically by mail()
        if ($this->SingleTo) {
            if ($this->Mailer != 'mail') {
                foreach ($this->to as $toaddr) {
                    $this->SingleToArray[] = $this->addrFormat($toaddr);
                }
            }
        } else {
            if (count($this->to) > 0) {
                if ($this->Mailer != 'mail') {
                    $result .= $this->addrAppend('To', $this->to);
                }
            } elseif (count($this->cc) == 0) {
                $result .= $this->headerLine('To', 'undisclosed-recipients:;');
            }
        }

        $result .= $this->addrAppend('From', array(array(trim($this->From), $this->FromName)));

        // sendmail and mail() extract Cc from the header before sending
        if (count($this->cc) > 0) {
            $result .= $this->addrAppend('Cc', $this->cc);
        }

        // sendmail and mail() extract Bcc from the header before sending
        if ((
                $this->Mailer == 'sendmail' or $this->Mailer == 'qmail' or $this->Mailer == 'mail'
            )
            and count($this->bcc) > 0
        ) {
            $result .= $this->addrAppend('Bcc', $this->bcc);
        }

        if (count($this->ReplyTo) > 0) {
            $result .= $this->addrAppend('Reply-To', $this->ReplyTo);
        }

        // mail() sets the subject itself
        if ($this->Mailer != 'mail') {
            $result .= $this->headerLine('Subject', $this->encodeHeader($this->secureHeader($this->Subject)));
        }

        if ('' != $this->MessageID and preg_match('/^<.*@.*>$/', $this->MessageID)) {
            $this->lastMessageID = $this->MessageID;
        } else {
            $this->lastMessageID = sprintf('<%s@%s>', $this->uniqueid, $this->serverHostname());
        }
        $result .= $this->headerLine('Message-ID', $this->lastMessageID);
        if (!is_null($this->Priority)) {
            $result .= $this->headerLine('X-Priority', $this->Priority);
        }
        if ($this->XMailer == '') {
            $result .= $this->headerLine(
                'X-Mailer',
                'PHPMailer ' . $this->Version . ' (https://github.com/PHPMailer/PHPMailer)'
            );
        } else {
            $myXmailer = trim($this->XMailer);
            if ($myXmailer) {
                $result .= $this->headerLine('X-Mailer', $myXmailer);
            }
        }

        if ($this->ConfirmReadingTo != '') {
            $result .= $this->headerLine('Disposition-Notification-To', '<' . $this->ConfirmReadingTo . '>');
        }

        // Add custom headers
        foreach ($this->CustomHeader as $header) {
            $result .= $this->headerLine(
                trim($header[0]),
                $this->encodeHeader(trim($header[1]))
            );
        }
        if (!$this->sign_key_file) {
            $result .= $this->headerLine('MIME-Version', '1.0');
            $result .= $this->getMailMIME();
        }

        return $result;
    }

    /**
     * Get the message MIME type headers.
     * @access public
     * @return string
     */
    public function getMailMIME()
    {
        $result = '';
        $ismultipart = true;
        switch ($this->message_type) {
            case 'inline':
                $result .= $this->headerLine('Content-Type', 'multipart/related;');
                $result .= $this->textLine("\tboundary=\"" . $this->boundary[1] . '"');
                break;
            case 'attach':
            case 'inline_attach':
            case 'alt_attach':
            case 'alt_inline_attach':
                $result .= $this->headerLine('Content-Type', 'multipart/mixed;');
                $result .= $this->textLine("\tboundary=\"" . $this->boundary[1] . '"');
                break;
            case 'alt':
            case 'alt_inline':
                $result .= $this->headerLine('Content-Type', 'multipart/alternative;');
                $result .= $this->textLine("\tboundary=\"" . $this->boundary[1] . '"');
                break;
            default:
                // Catches case 'plain': and case '':
                $result .= $this->textLine('Content-Type: ' . $this->ContentType . '; charset=' . $this->CharSet);
                $ismultipart = false;
                break;
        }
        // RFC1341 part 5 says 7bit is assumed if not specified
        if ($this->Encoding != '7bit') {
            // RFC 2045 section 6.4 says multipart MIME parts may only use 7bit, 8bit or binary CTE
            if ($ismultipart) {
                if ($this->Encoding == '8bit') {
                    $result .= $this->headerLine('Content-Transfer-Encoding', '8bit');
                }
                // The only remaining alternatives are quoted-printable and base64, which are both 7bit compatible
            } else {
                $result .= $this->headerLine('Content-Transfer-Encoding', $this->Encoding);
            }
        }

        if ($this->Mailer != 'mail') {
            $result .= $this->LE;
        }

        return $result;
    }

    /**
     * Returns the whole MIME message.
     * Includes complete headers and body.
     * Only valid post preSend().
     * @see PHPMailer::preSend()
     * @access public
     * @return string
     */
    public function getSentMIMEMessage()
    {
        return rtrim($this->MIMEHeader . $this->mailHeader, "\n\r") . self::CRLF . self::CRLF . $this->MIMEBody;
    }

    /**
     * Assemble the message body.
     * Returns an empty string on failure.
     * @access public
     * @throws phpmailerException
     * @return string The assembled message body
     */
    public function createBody()
    {
        $body = '';
        //Create unique IDs and preset boundaries
        $this->uniqueid = md5(uniqid(time()));
        $this->boundary[1] = 'b1_' . $this->uniqueid;
        $this->boundary[2] = 'b2_' . $this->uniqueid;
        $this->boundary[3] = 'b3_' . $this->uniqueid;

        if ($this->sign_key_file) {
            $body .= $this->getMailMIME() . $this->LE;
        }

        $this->setWordWrap();

        $bodyEncoding = $this->Encoding;
        $bodyCharSet = $this->CharSet;
        //Can we do a 7-bit downgrade?
        if ($bodyEncoding == '8bit' and !$this->has8bitChars($this->Body)) {
            $bodyEncoding = '7bit';
            //All ISO 8859, Windows codepage and UTF-8 charsets are ascii compatible up to 7-bit
            $bodyCharSet = 'us-ascii';
        }
        //If lines are too long, and we're not already using an encoding that will shorten them,
        //change to quoted-printable transfer encoding for the body part only
        if ('base64' != $this->Encoding and self::hasLineLongerThanMax($this->Body)) {
            $bodyEncoding = 'quoted-printable';
        }

        $altBodyEncoding = $this->Encoding;
        $altBodyCharSet = $this->CharSet;
        //Can we do a 7-bit downgrade?
        if ($altBodyEncoding == '8bit' and !$this->has8bitChars($this->AltBody)) {
            $altBodyEncoding = '7bit';
            //All ISO 8859, Windows codepage and UTF-8 charsets are ascii compatible up to 7-bit
            $altBodyCharSet = 'us-ascii';
        }
        //If lines are too long, and we're not already using an encoding that will shorten them,
        //change to quoted-printable transfer encoding for the alt body part only
        if ('base64' != $altBodyEncoding and self::hasLineLongerThanMax($this->AltBody)) {
            $altBodyEncoding = 'quoted-printable';
        }
        //Use this as a preamble in all multipart message types
        $mimepre = "This is a multi-part message in MIME format." . $this->LE . $this->LE;
        switch ($this->message_type) {
            case 'inline':
                $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('inline', $this->boundary[1]);
                break;
            case 'attach':
                $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'inline_attach':
                $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', 'multipart/related;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[2] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, '', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('inline', $this->boundary[2]);
                $body .= $this->LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'alt':
                $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $altBodyCharSet, 'text/plain', $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->getBoundary($this->boundary[1], $bodyCharSet, 'text/html', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                if (!empty($this->Ical)) {
                    $body .= $this->getBoundary($this->boundary[1], '', 'text/calendar; method=REQUEST', '');
                    $body .= $this->encodeString($this->Ical, $this->Encoding);
                    $body .= $this->LE . $this->LE;
                }
                $body .= $this->endBoundary($this->boundary[1]);
                break;
            case 'alt_inline':
                $body .= $mimepre;
                $body .= $this->getBoundary($this->boundary[1], $altBodyCharSet, 'text/plain', $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', 'multipart/related;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[2] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, 'text/html', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('inline', $this->boundary[2]);
                $body .= $this->LE;
                $body .= $this->endBoundary($this->boundary[1]);
                break;
            case 'alt_attach':
                $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', 'multipart/alternative;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[2] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $altBodyCharSet, 'text/plain', $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $bodyCharSet, 'text/html', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->endBoundary($this->boundary[2]);
                $body .= $this->LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            case 'alt_inline_attach':
                $body .= $mimepre;
                $body .= $this->textLine('--' . $this->boundary[1]);
                $body .= $this->headerLine('Content-Type', 'multipart/alternative;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[2] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[2], $altBodyCharSet, 'text/plain', $altBodyEncoding);
                $body .= $this->encodeString($this->AltBody, $altBodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->textLine('--' . $this->boundary[2]);
                $body .= $this->headerLine('Content-Type', 'multipart/related;');
                $body .= $this->textLine("\tboundary=\"" . $this->boundary[3] . '"');
                $body .= $this->LE;
                $body .= $this->getBoundary($this->boundary[3], $bodyCharSet, 'text/html', $bodyEncoding);
                $body .= $this->encodeString($this->Body, $bodyEncoding);
                $body .= $this->LE . $this->LE;
                $body .= $this->attachAll('inline', $this->boundary[3]);
                $body .= $this->LE;
                $body .= $this->endBoundary($this->boundary[2]);
                $body .= $this->LE;
                $body .= $this->attachAll('attachment', $this->boundary[1]);
                break;
            default:
                // Catch case 'plain' and case '', applies to simple `text/plain` and `text/html` body content types
                //Reset the `Encoding` property in case we changed it for line length reasons
                $this->Encoding = $bodyEncoding;
                $body .= $this->encodeString($this->Body, $this->Encoding);
                break;
        }

        if ($this->isError()) {
            $body = '';
        } elseif ($this->sign_key_file) {
            try {
                if (!defined('PKCS7_TEXT')) {
                    throw new phpmailerException($this->lang('extension_missing') . 'openssl');
                }
                // @TODO would be nice to use php://temp streams here, but need to wrap for PHP < 5.1
                $file = tempnam(sys_get_temp_dir(), 'mail');
                if (false === file_put_contents($file, $body)) {
                    throw new phpmailerException($this->lang('signing') . ' Could not write temp file');
                }
                $signed = tempnam(sys_get_temp_dir(), 'signed');
                //Workaround for PHP bug https://bugs.php.net/bug.php?id=69197
                if (empty($this->sign_extracerts_file)) {
                    $sign = @openssl_pkcs7_sign(
                        $file,
                        $signed,
                        'file://' . realpath($this->sign_cert_file),
                        array('file://' . realpath($this->sign_key_file), $this->sign_key_pass),
                        null
                    );
                } else {
                    $sign = @openssl_pkcs7_sign(
                        $file,
                        $signed,
                        'file://' . realpath($this->sign_cert_file),
                        array('file://' . realpath($this->sign_key_file), $this->sign_key_pass),
                        null,
                        PKCS7_DETACHED,
                        $this->sign_extracerts_file
                    );
                }
                if ($sign) {
                    @unlink($file);
                    $body = file_get_contents($signed);
                    @unlink($signed);
                    //The message returned by openssl contains both headers and body, so need to split them up
                    $parts = explode("\n\n", $body, 2);
                    $this->MIMEHeader .= $parts[0] . $this->LE . $this->LE;
                    $body = $parts[1];
                } else {
                    @unlink($file);
                    @unlink($signed);
                    throw new phpmailerException($this->lang('signing') . openssl_error_string());
                }
            } catch (phpmailerException $exc) {
                $body = '';
                if ($this->exceptions) {
                    throw $exc;
                }
            }
        }
        return $body;
    }

    /**
     * Return the start of a message boundary.
     * @access protected
     * @param string $boundary
     * @param string $charSet
     * @param string $contentType
     * @param string $encoding
     * @return string
     */
    protected function getBoundary($boundary, $charSet, $contentType, $encoding)
    {
        $result = '';
        if ($charSet == '') {
            $charSet = $this->CharSet;
        }
        if ($contentType == '') {
            $contentType = $this->ContentType;
        }
        if ($encoding == '') {
            $encoding = $this->Encoding;
        }
        $result .= $this->textLine('--' . $boundary);
        $result .= sprintf('Content-Type: %s; charset=%s', $contentType, $charSet);
        $result .= $this->LE;
        // RFC1341 part 5 says 7bit is assumed if not specified
        if ($encoding != '7bit') {
            $result .= $this->headerLine('Content-Transfer-Encoding', $encoding);
        }
        $result .= $this->LE;

        return $result;
    }

    /**
     * Return the end of a message boundary.
     * @access protected
     * @param string $boundary
     * @return string
     */
    protected function endBoundary($boundary)
    {
        return $this->LE . '--' . $boundary . '--' . $this->LE;
    }

    /**
     * Set the message type.
     * PHPMailer only supports some preset message types, not arbitrary MIME structures.
     * @access protected
     * @return void
     */
    protected function setMessageType()
    {
        $type = array();
        if ($this->alternativeExists()) {
            $type[] = 'alt';
        }
        if ($this->inlineImageExists()) {
            $type[] = 'inline';
        }
        if ($this->attachmentExists()) {
            $type[] = 'attach';
        }
        $this->message_type = implode('_', $type);
        if ($this->message_type == '') {
            //The 'plain' message_type refers to the message having a single body element, not that it is plain-text
            $this->message_type = 'plain';
        }
    }

    /**
     * Format a header line.
     * @access public
     * @param string $name
     * @param string $value
     * @return string
     */
    public function headerLine($name, $value)
    {
        return $name . ': ' . $value . $this->LE;
    }

    /**
     * Return a formatted mail line.
     * @access public
     * @param string $value
     * @return string
     */
    public function textLine($value)
    {
        return $value . $this->LE;
    }

    /**
     * Add an attachment from a path on the filesystem.
     * Returns false if the file could not be found or read.
     * @param string $path Path to the attachment.
     * @param string $name Overrides the attachment name.
     * @param string $encoding File encoding (see $Encoding).
     * @param string $type File extension (MIME) type.
     * @param string $disposition Disposition to use
     * @throws phpmailerException
     * @return boolean
     */
    public function addAttachment($path, $name = '', $encoding = 'base64', $type = '', $disposition = 'attachment')
    {
        try {
            if (!@is_file($path)) {
                throw new phpmailerException($this->lang('file_access') . $path, self::STOP_CONTINUE);
            }

            // If a MIME type is not specified, try to work it out from the file name
            if ($type == '') {
                $type = self::filenameToType($path);
            }

            $filename = basename($path);
            if ($name == '') {
                $name = $filename;
            }

            $this->attachment[] = array(
                0 => $path,
                1 => $filename,
                2 => $name,
                3 => $encoding,
                4 => $type,
                5 => false, // isStringAttachment
                6 => $disposition,
                7 => 0
            );

        } catch (phpmailerException $exc) {
            $this->setError($exc->getMessage());
            $this->edebug($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }
        return true;
    }

    /**
     * Return the array of attachments.
     * @return array
     */
    public function getAttachments()
    {
        return $this->attachment;
    }

    /**
     * Attach all file, string, and binary attachments to the message.
     * Returns an empty string on failure.
     * @access protected
     * @param string $disposition_type
     * @param string $boundary
     * @return string
     */
    protected function attachAll($disposition_type, $boundary)
    {
        // Return text of body
        $mime = array();
        $cidUniq = array();
        $incl = array();

        // Add all attachments
        foreach ($this->attachment as $attachment) {
            // Check if it is a valid disposition_filter
            if ($attachment[6] == $disposition_type) {
                // Check for string attachment
                $string = '';
                $path = '';
                $bString = $attachment[5];
                if ($bString) {
                    $string = $attachment[0];
                } else {
                    $path = $attachment[0];
                }

                $inclhash = md5(serialize($attachment));
                if (in_array($inclhash, $incl)) {
                    continue;
                }
                $incl[] = $inclhash;
                $name = $attachment[2];
                $encoding = $attachment[3];
                $type = $attachment[4];
                $disposition = $attachment[6];
                $cid = $attachment[7];
                if ($disposition == 'inline' && array_key_exists($cid, $cidUniq)) {
                    continue;
                }
                $cidUniq[$cid] = true;

                $mime[] = sprintf('--%s%s', $boundary, $this->LE);
                //Only include a filename property if we have one
                if (!empty($name)) {
                    $mime[] = sprintf(
                        'Content-Type: %s; name="%s"%s',
                        $type,
                        $this->encodeHeader($this->secureHeader($name)),
                        $this->LE
                    );
                } else {
                    $mime[] = sprintf(
                        'Content-Type: %s%s',
                        $type,
                        $this->LE
                    );
                }
                // RFC1341 part 5 says 7bit is assumed if not specified
                if ($encoding != '7bit') {
                    $mime[] = sprintf('Content-Transfer-Encoding: %s%s', $encoding, $this->LE);
                }

                if ($disposition == 'inline') {
                    $mime[] = sprintf('Content-ID: <%s>%s', $cid, $this->LE);
                }

                // If a filename contains any of these chars, it should be quoted,
                // but not otherwise: RFC2183 & RFC2045 5.1
                // Fixes a warning in IETF's msglint MIME checker
                // Allow for bypassing the Content-Disposition header totally
                if (!(empty($disposition))) {
                    $encoded_name = $this->encodeHeader($this->secureHeader($name));
                    if (preg_match('/[ \(\)<>@,;:\\"\/\[\]\?=]/', $encoded_name)) {
                        $mime[] = sprintf(
                            'Content-Disposition: %s; filename="%s"%s',
                            $disposition,
                            $encoded_name,
                            $this->LE . $this->LE
                        );
                    } else {
                        if (!empty($encoded_name)) {
                            $mime[] = sprintf(
                                'Content-Disposition: %s; filename=%s%s',
                                $disposition,
                                $encoded_name,
                                $this->LE . $this->LE
                            );
                        } else {
                            $mime[] = sprintf(
                                'Content-Disposition: %s%s',
                                $disposition,
                                $this->LE . $this->LE
                            );
                        }
                    }
                } else {
                    $mime[] = $this->LE;
                }

                // Encode as string attachment
                if ($bString) {
                    $mime[] = $this->encodeString($string, $encoding);
                    if ($this->isError()) {
                        return '';
                    }
                    $mime[] = $this->LE . $this->LE;
                } else {
                    $mime[] = $this->encodeFile($path, $encoding);
                    if ($this->isError()) {
                        return '';
                    }
                    $mime[] = $this->LE . $this->LE;
                }
            }
        }

        $mime[] = sprintf('--%s--%s', $boundary, $this->LE);

        return implode('', $mime);
    }

    /**
     * Encode a file attachment in requested format.
     * Returns an empty string on failure.
     * @param string $path The full path to the file
     * @param string $encoding The encoding to use; one of 'base64', '7bit', '8bit', 'binary', 'quoted-printable'
     * @throws phpmailerException
     * @access protected
     * @return string
     */
    protected function encodeFile($path, $encoding = 'base64')
    {
        try {
            if (!is_readable($path)) {
                throw new phpmailerException($this->lang('file_open') . $path, self::STOP_CONTINUE);
            }
            $magic_quotes = get_magic_quotes_runtime();
            if ($magic_quotes) {
                if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                    set_magic_quotes_runtime(false);
                } else {
                    //Doesn't exist in PHP 5.4, but we don't need to check because
                    //get_magic_quotes_runtime always returns false in 5.4+
                    //so it will never get here
                    ini_set('magic_quotes_runtime', false);
                }
            }
            $file_buffer = file_get_contents($path);
            $file_buffer = $this->encodeString($file_buffer, $encoding);
            if ($magic_quotes) {
                if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                    set_magic_quotes_runtime($magic_quotes);
                } else {
                    ini_set('magic_quotes_runtime', $magic_quotes);
                }
            }
            return $file_buffer;
        } catch (Exception $exc) {
            $this->setError($exc->getMessage());
            return '';
        }
    }

    /**
     * Encode a string in requested format.
     * Returns an empty string on failure.
     * @param string $str The text to encode
     * @param string $encoding The encoding to use; one of 'base64', '7bit', '8bit', 'binary', 'quoted-printable'
     * @access public
     * @return string
     */
    public function encodeString($str, $encoding = 'base64')
    {
        $encoded = '';
        switch (strtolower($encoding)) {
            case 'base64':
                $encoded = chunk_split(base64_encode($str), 76, $this->LE);
                break;
            case '7bit':
            case '8bit':
                $encoded = $this->fixEOL($str);
                // Make sure it ends with a line break
                if (substr($encoded, -(strlen($this->LE))) != $this->LE) {
                    $encoded .= $this->LE;
                }
                break;
            case 'binary':
                $encoded = $str;
                break;
            case 'quoted-printable':
                $encoded = $this->encodeQP($str);
                break;
            default:
                $this->setError($this->lang('encoding') . $encoding);
                break;
        }
        return $encoded;
    }

    /**
     * Encode a header string optimally.
     * Picks shortest of Q, B, quoted-printable or none.
     * @access public
     * @param string $str
     * @param string $position
     * @return string
     */
    public function encodeHeader($str, $position = 'text')
    {
        $matchcount = 0;
        switch (strtolower($position)) {
            case 'phrase':
                if (!preg_match('/[\200-\377]/', $str)) {
                    // Can't use addslashes as we don't know the value of magic_quotes_sybase
                    $encoded = addcslashes($str, "\0..\37\177\\\"");
                    if (($str == $encoded) && !preg_match('/[^A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]/', $str)) {
                        return ($encoded);
                    } else {
                        return ("\"$encoded\"");
                    }
                }
                $matchcount = preg_match_all('/[^\040\041\043-\133\135-\176]/', $str, $matches);
                break;
            /** @noinspection PhpMissingBreakStatementInspection */
            case 'comment':
                $matchcount = preg_match_all('/[()"]/', $str, $matches);
                // Intentional fall-through
            case 'text':
            default:
                $matchcount += preg_match_all('/[\000-\010\013\014\016-\037\177-\377]/', $str, $matches);
                break;
        }

        //There are no chars that need encoding
        if ($matchcount == 0) {
            return ($str);
        }

        $maxlen = 75 - 7 - strlen($this->CharSet);
        // Try to select the encoding which should produce the shortest output
        if ($matchcount > strlen($str) / 3) {
            // More than a third of the content will need encoding, so B encoding will be most efficient
            $encoding = 'B';
            if (function_exists('mb_strlen') && $this->hasMultiBytes($str)) {
                // Use a custom function which correctly encodes and wraps long
                // multibyte strings without breaking lines within a character
                $encoded = $this->base64EncodeWrapMB($str, "\n");
            } else {
                $encoded = base64_encode($str);
                $maxlen -= $maxlen % 4;
                $encoded = trim(chunk_split($encoded, $maxlen, "\n"));
            }
        } else {
            $encoding = 'Q';
            $encoded = $this->encodeQ($str, $position);
            $encoded = $this->wrapText($encoded, $maxlen, true);
            $encoded = str_replace('=' . self::CRLF, "\n", trim($encoded));
        }

        $encoded = preg_replace('/^(.*)$/m', ' =?' . $this->CharSet . "?$encoding?\\1?=", $encoded);
        $encoded = trim(str_replace("\n", $this->LE, $encoded));

        return $encoded;
    }

    /**
     * Check if a string contains multi-byte characters.
     * @access public
     * @param string $str multi-byte text to wrap encode
     * @return boolean
     */
    public function hasMultiBytes($str)
    {
        if (function_exists('mb_strlen')) {
            return (strlen($str) > mb_strlen($str, $this->CharSet));
        } else { // Assume no multibytes (we can't handle without mbstring functions anyway)
            return false;
        }
    }

    /**
     * Does a string contain any 8-bit chars (in any charset)?
     * @param string $text
     * @return boolean
     */
    public function has8bitChars($text)
    {
        return (boolean)preg_match('/[\x80-\xFF]/', $text);
    }

    /**
     * Encode and wrap long multibyte strings for mail headers
     * without breaking lines within a character.
     * Adapted from a function by paravoid
     * @link http://www.php.net/manual/en/function.mb-encode-mimeheader.php#60283
     * @access public
     * @param string $str multi-byte text to wrap encode
     * @param string $linebreak string to use as linefeed/end-of-line
     * @return string
     */
    public function base64EncodeWrapMB($str, $linebreak = null)
    {
        $start = '=?' . $this->CharSet . '?B?';
        $end = '?=';
        $encoded = '';
        if ($linebreak === null) {
            $linebreak = $this->LE;
        }

        $mb_length = mb_strlen($str, $this->CharSet);
        // Each line must have length <= 75, including $start and $end
        $length = 75 - strlen($start) - strlen($end);
        // Average multi-byte ratio
        $ratio = $mb_length / strlen($str);
        // Base64 has a 4:3 ratio
        $avgLength = floor($length * $ratio * .75);

        for ($i = 0; $i < $mb_length; $i += $offset) {
            $lookBack = 0;
            do {
                $offset = $avgLength - $lookBack;
                $chunk = mb_substr($str, $i, $offset, $this->CharSet);
                $chunk = base64_encode($chunk);
                $lookBack++;
            } while (strlen($chunk) > $length);
            $encoded .= $chunk . $linebreak;
        }

        // Chomp the last linefeed
        $encoded = substr($encoded, 0, -strlen($linebreak));
        return $encoded;
    }

    /**
     * Encode a string in quoted-printable format.
     * According to RFC2045 section 6.7.
     * @access public
     * @param string $string The text to encode
     * @param integer $line_max Number of chars allowed on a line before wrapping
     * @return string
     * @link http://www.php.net/manual/en/function.quoted-printable-decode.php#89417 Adapted from this comment
     */
    public function encodeQP($string, $line_max = 76)
    {
        // Use native function if it's available (>= PHP5.3)
        if (function_exists('quoted_printable_encode')) {
            return quoted_printable_encode($string);
        }
        // Fall back to a pure PHP implementation
        $string = str_replace(
            array('%20', '%0D%0A.', '%0D%0A', '%'),
            array(' ', "\r\n=2E", "\r\n", '='),
            rawurlencode($string)
        );
        return preg_replace('/[^\r\n]{' . ($line_max - 3) . '}[^=\r\n]{2}/', "$0=\r\n", $string);
    }

    /**
     * Backward compatibility wrapper for an old QP encoding function that was removed.
     * @see PHPMailer::encodeQP()
     * @access public
     * @param string $string
     * @param integer $line_max
     * @param boolean $space_conv
     * @return string
     * @deprecated Use encodeQP instead.
     */
    public function encodeQPphp(
        $string,
        $line_max = 76,
        /** @noinspection PhpUnusedParameterInspection */ $space_conv = false
    ) {
        return $this->encodeQP($string, $line_max);
    }

    /**
     * Encode a string using Q encoding.
     * @link http://tools.ietf.org/html/rfc2047
     * @param string $str the text to encode
     * @param string $position Where the text is going to be used, see the RFC for what that means
     * @access public
     * @return string
     */
    public function encodeQ($str, $position = 'text')
    {
        // There should not be any EOL in the string
        $pattern = '';
        $encoded = str_replace(array("\r", "\n"), '', $str);
        switch (strtolower($position)) {
            case 'phrase':
                // RFC 2047 section 5.3
                $pattern = '^A-Za-z0-9!*+\/ -';
                break;
            /** @noinspection PhpMissingBreakStatementInspection */
            case 'comment':
                // RFC 2047 section 5.2
                $pattern = '\(\)"';
                // intentional fall-through
                // for this reason we build the $pattern without including delimiters and []
            case 'text':
            default:
                // RFC 2047 section 5.1
                // Replace every high ascii, control, =, ? and _ characters
                $pattern = '\000-\011\013\014\016-\037\075\077\137\177-\377' . $pattern;
                break;
        }
        $matches = array();
        if (preg_match_all("/[{$pattern}]/", $encoded, $matches)) {
            // If the string contains an '=', make sure it's the first thing we replace
            // so as to avoid double-encoding
            $eqkey = array_search('=', $matches[0]);
            if (false !== $eqkey) {
                unset($matches[0][$eqkey]);
                array_unshift($matches[0], '=');
            }
            foreach (array_unique($matches[0]) as $char) {
                $encoded = str_replace($char, '=' . sprintf('%02X', ord($char)), $encoded);
            }
        }
        // Replace every spaces to _ (more readable than =20)
        return str_replace(' ', '_', $encoded);
    }

    /**
     * Add a string or binary attachment (non-filesystem).
     * This method can be used to attach ascii or binary data,
     * such as a BLOB record from a database.
     * @param string $string String attachment data.
     * @param string $filename Name of the attachment.
     * @param string $encoding File encoding (see $Encoding).
     * @param string $type File extension (MIME) type.
     * @param string $disposition Disposition to use
     * @return void
     */
    public function addStringAttachment(
        $string,
        $filename,
        $encoding = 'base64',
        $type = '',
        $disposition = 'attachment'
    ) {
        // If a MIME type is not specified, try to work it out from the file name
        if ($type == '') {
            $type = self::filenameToType($filename);
        }
        // Append to $attachment array
        $this->attachment[] = array(
            0 => $string,
            1 => $filename,
            2 => basename($filename),
            3 => $encoding,
            4 => $type,
            5 => true, // isStringAttachment
            6 => $disposition,
            7 => 0
        );
    }

    /**
     * Add an embedded (inline) attachment from a file.
     * This can include images, sounds, and just about any other document type.
     * These differ from 'regular' attachments in that they are intended to be
     * displayed inline with the message, not just attached for download.
     * This is used in HTML messages that embed the images
     * the HTML refers to using the $cid value.
     * @param string $path Path to the attachment.
     * @param string $cid Content ID of the attachment; Use this to reference
     *        the content when using an embedded image in HTML.
     * @param string $name Overrides the attachment name.
     * @param string $encoding File encoding (see $Encoding).
     * @param string $type File MIME type.
     * @param string $disposition Disposition to use
     * @return boolean True on successfully adding an attachment
     */
    public function addEmbeddedImage($path, $cid, $name = '', $encoding = 'base64', $type = '', $disposition = 'inline')
    {
        if (!@is_file($path)) {
            $this->setError($this->lang('file_access') . $path);
            return false;
        }

        // If a MIME type is not specified, try to work it out from the file name
        if ($type == '') {
            $type = self::filenameToType($path);
        }

        $filename = basename($path);
        if ($name == '') {
            $name = $filename;
        }

        // Append to $attachment array
        $this->attachment[] = array(
            0 => $path,
            1 => $filename,
            2 => $name,
            3 => $encoding,
            4 => $type,
            5 => false, // isStringAttachment
            6 => $disposition,
            7 => $cid
        );
        return true;
    }

    /**
     * Add an embedded stringified attachment.
     * This can include images, sounds, and just about any other document type.
     * Be sure to set the $type to an image type for images:
     * JPEG images use 'image/jpeg', GIF uses 'image/gif', PNG uses 'image/png'.
     * @param string $string The attachment binary data.
     * @param string $cid Content ID of the attachment; Use this to reference
     *        the content when using an embedded image in HTML.
     * @param string $name
     * @param string $encoding File encoding (see $Encoding).
     * @param string $type MIME type.
     * @param string $disposition Disposition to use
     * @return boolean True on successfully adding an attachment
     */
    public function addStringEmbeddedImage(
        $string,
        $cid,
        $name = '',
        $encoding = 'base64',
        $type = '',
        $disposition = 'inline'
    ) {
        // If a MIME type is not specified, try to work it out from the name
        if ($type == '' and !empty($name)) {
            $type = self::filenameToType($name);
        }

        // Append to $attachment array
        $this->attachment[] = array(
            0 => $string,
            1 => $name,
            2 => $name,
            3 => $encoding,
            4 => $type,
            5 => true, // isStringAttachment
            6 => $disposition,
            7 => $cid
        );
        return true;
    }

    /**
     * Check if an inline attachment is present.
     * @access public
     * @return boolean
     */
    public function inlineImageExists()
    {
        foreach ($this->attachment as $attachment) {
            if ($attachment[6] == 'inline') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an attachment (non-inline) is present.
     * @return boolean
     */
    public function attachmentExists()
    {
        foreach ($this->attachment as $attachment) {
            if ($attachment[6] == 'attachment') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if this message has an alternative body set.
     * @return boolean
     */
    public function alternativeExists()
    {
        return !empty($this->AltBody);
    }

    /**
     * Clear queued addresses of given kind.
     * @access protected
     * @param string $kind 'to', 'cc', or 'bcc'
     * @return void
     */
    public function clearQueuedAddresses($kind)
    {
        $RecipientsQueue = $this->RecipientsQueue;
        foreach ($RecipientsQueue as $address => $params) {
            if ($params[0] == $kind) {
                unset($this->RecipientsQueue[$address]);
            }
        }
    }

    /**
     * Clear all To recipients.
     * @return void
     */
    public function clearAddresses()
    {
        foreach ($this->to as $to) {
            unset($this->all_recipients[strtolower($to[0])]);
        }
        $this->to = array();
        $this->clearQueuedAddresses('to');
    }

    /**
     * Clear all CC recipients.
     * @return void
     */
    public function clearCCs()
    {
        foreach ($this->cc as $cc) {
            unset($this->all_recipients[strtolower($cc[0])]);
        }
        $this->cc = array();
        $this->clearQueuedAddresses('cc');
    }

    /**
     * Clear all BCC recipients.
     * @return void
     */
    public function clearBCCs()
    {
        foreach ($this->bcc as $bcc) {
            unset($this->all_recipients[strtolower($bcc[0])]);
        }
        $this->bcc = array();
        $this->clearQueuedAddresses('bcc');
    }

    /**
     * Clear all ReplyTo recipients.
     * @return void
     */
    public function clearReplyTos()
    {
        $this->ReplyTo = array();
        $this->ReplyToQueue = array();
    }

    /**
     * Clear all recipient types.
     * @return void
     */
    public function clearAllRecipients()
    {
        $this->to = array();
        $this->cc = array();
        $this->bcc = array();
        $this->all_recipients = array();
        $this->RecipientsQueue = array();
    }

    /**
     * Clear all filesystem, string, and binary attachments.
     * @return void
     */
    public function clearAttachments()
    {
        $this->attachment = array();
    }

    /**
     * Clear all custom headers.
     * @return void
     */
    public function clearCustomHeaders()
    {
        $this->CustomHeader = array();
    }

    /**
     * Add an error message to the error container.
     * @access protected
     * @param string $msg
     * @return void
     */
    protected function setError($msg)
    {
        $this->error_count++;
        if ($this->Mailer == 'smtp' and !is_null($this->smtp)) {
            $lasterror = $this->smtp->getError();
            if (!empty($lasterror['error'])) {
                $msg .= $this->lang('smtp_error') . $lasterror['error'];
                if (!empty($lasterror['detail'])) {
                    $msg .= ' Detail: '. $lasterror['detail'];
                }
                if (!empty($lasterror['smtp_code'])) {
                    $msg .= ' SMTP code: ' . $lasterror['smtp_code'];
                }
                if (!empty($lasterror['smtp_code_ex'])) {
                    $msg .= ' Additional SMTP info: ' . $lasterror['smtp_code_ex'];
                }
            }
        }
        $this->ErrorInfo = $msg;
    }

    /**
     * Return an RFC 822 formatted date.
     * @access public
     * @return string
     * @static
     */
    public static function rfcDate()
    {
        // Set the time zone to whatever the default is to avoid 500 errors
        // Will default to UTC if it's not set properly in php.ini
        date_default_timezone_set(@date_default_timezone_get());
        return date('D, j M Y H:i:s O');
    }

    /**
     * Get the server hostname.
     * Returns 'localhost.localdomain' if unknown.
     * @access protected
     * @return string
     */
    protected function serverHostname()
    {
        $result = 'localhost.localdomain';
        if (!empty($this->Hostname)) {
            $result = $this->Hostname;
        } elseif (isset($_SERVER) and array_key_exists('SERVER_NAME', $_SERVER) and !empty($_SERVER['SERVER_NAME'])) {
            $result = $_SERVER['SERVER_NAME'];
        } elseif (function_exists('gethostname') && gethostname() !== false) {
            $result = gethostname();
        } elseif (php_uname('n') !== false) {
            $result = php_uname('n');
        }
        return $result;
    }

    /**
     * Get an error message in the current language.
     * @access protected
     * @param string $key
     * @return string
     */
    protected function lang($key)
    {
        if (count($this->language) < 1) {
            $this->setLanguage('en'); // set the default language
        }

        if (array_key_exists($key, $this->language)) {
            if ($key == 'smtp_connect_failed') {
                //Include a link to troubleshooting docs on SMTP connection failure
                //this is by far the biggest cause of support questions
                //but it's usually not PHPMailer's fault.
                return $this->language[$key] . ' https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting';
            }
            return $this->language[$key];
        } else {
            //Return the key as a fallback
            return $key;
        }
    }

    /**
     * Check if an error occurred.
     * @access public
     * @return boolean True if an error did occur.
     */
    public function isError()
    {
        return ($this->error_count > 0);
    }

    /**
     * Ensure consistent line endings in a string.
     * Changes every end of line from CRLF, CR or LF to $this->LE.
     * @access public
     * @param string $str String to fixEOL
     * @return string
     */
    public function fixEOL($str)
    {
        // Normalise to \n
        $nstr = str_replace(array("\r\n", "\r"), "\n", $str);
        // Now convert LE as needed
        if ($this->LE !== "\n") {
            $nstr = str_replace("\n", $this->LE, $nstr);
        }
        return $nstr;
    }

    /**
     * Add a custom header.
     * $name value can be overloaded to contain
     * both header name and value (name:value)
     * @access public
     * @param string $name Custom header name
     * @param string $value Header value
     * @return void
     */
    public function addCustomHeader($name, $value = null)
    {
        if ($value === null) {
            // Value passed in as name:value
            $this->CustomHeader[] = explode(':', $name, 2);
        } else {
            $this->CustomHeader[] = array($name, $value);
        }
    }

    /**
     * Returns all custom headers.
     * @return array
     */
    public function getCustomHeaders()
    {
        return $this->CustomHeader;
    }

    /**
     * Create a message from an HTML string.
     * Automatically makes modifications for inline images and backgrounds
     * and creates a plain-text version by converting the HTML.
     * Overwrites any existing values in $this->Body and $this->AltBody
     * @access public
     * @param string $message HTML message string
     * @param string $basedir baseline directory for path
     * @param boolean|callable $advanced Whether to use the internal HTML to text converter
     *    or your own custom converter @see PHPMailer::html2text()
     * @return string $message
     */
    public function msgHTML($message, $basedir = '', $advanced = false)
    {
        preg_match_all('/(src|background)=["\'](.*)["\']/Ui', $message, $images);
        if (array_key_exists(2, $images)) {
            foreach ($images[2] as $imgindex => $url) {
                // Convert data URIs into embedded images
                if (preg_match('#^data:(image[^;,]*)(;base64)?,#', $url, $match)) {
                    $data = substr($url, strpos($url, ','));
                    if ($match[2]) {
                        $data = base64_decode($data);
                    } else {
                        $data = rawurldecode($data);
                    }
                    $cid = md5($url) . '@phpmailer.0'; // RFC2392 S 2
                    if ($this->addStringEmbeddedImage($data, $cid, 'embed' . $imgindex, 'base64', $match[1])) {
                        $message = str_replace(
                            $images[0][$imgindex],
                            $images[1][$imgindex] . '="cid:' . $cid . '"',
                            $message
                        );
                    }
                } elseif (substr($url, 0, 4) !== 'cid:' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
                    // Do not change urls for absolute images (thanks to corvuscorax)
                    // Do not change urls that are already inline images
                    $filename = basename($url);
                    $directory = dirname($url);
                    if ($directory == '.') {
                        $directory = '';
                    }
                    $cid = md5($url) . '@phpmailer.0'; // RFC2392 S 2
                    if (strlen($basedir) > 1 && substr($basedir, -1) != '/') {
                        $basedir .= '/';
                    }
                    if (strlen($directory) > 1 && substr($directory, -1) != '/') {
                        $directory .= '/';
                    }
                    if ($this->addEmbeddedImage(
                        $basedir . $directory . $filename,
                        $cid,
                        $filename,
                        'base64',
                        self::_mime_types((string)self::mb_pathinfo($filename, PATHINFO_EXTENSION))
                    )
                    ) {
                        $message = preg_replace(
                            '/' . $images[1][$imgindex] . '=["\']' . preg_quote($url, '/') . '["\']/Ui',
                            $images[1][$imgindex] . '="cid:' . $cid . '"',
                            $message
                        );
                    }
                }
            }
        }
        $this->isHTML(true);
        // Convert all message body line breaks to CRLF, makes quoted-printable encoding work much better
        $this->Body = $this->normalizeBreaks($message);
        $this->AltBody = $this->normalizeBreaks($this->html2text($message, $advanced));
        if (!$this->alternativeExists()) {
            $this->AltBody = 'To view this email message, open it in a program that understands HTML!' .
                self::CRLF . self::CRLF;
        }
        return $this->Body;
    }

    /**
     * Convert an HTML string into plain text.
     * This is used by msgHTML().
     * Note - older versions of this function used a bundled advanced converter
     * which was been removed for license reasons in #232
     * Example usage:
     * <code>
     * // Use default conversion
     * $plain = $mail->html2text($html);
     * // Use your own custom converter
     * $plain = $mail->html2text($html, function($html) {
     *     $converter = new MyHtml2text($html);
     *     return $converter->get_text();
     * });
     * </code>
     * @param string $html The HTML text to convert
     * @param boolean|callable $advanced Any boolean value to use the internal converter,
     *   or provide your own callable for custom conversion.
     * @return string
     */
    public function html2text($html, $advanced = false)
    {
        if (is_callable($advanced)) {
            return call_user_func($advanced, $html);
        }
        return html_entity_decode(
            trim(strip_tags(preg_replace('/<(head|title|style|script)[^>]*>.*?<\/\\1>/si', '', $html))),
            ENT_QUOTES,
            $this->CharSet
        );
    }

    /**
     * Get the MIME type for a file extension.
     * @param string $ext File extension
     * @access public
     * @return string MIME type of file.
     * @static
     */
    public static function _mime_types($ext = '')
    {
        $mimes = array(
            'xl'    => 'application/excel',
            'js'    => 'application/javascript',
            'hqx'   => 'application/mac-binhex40',
            'cpt'   => 'application/mac-compactpro',
            'bin'   => 'application/macbinary',
            'doc'   => 'application/msword',
            'word'  => 'application/msword',
            'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xltx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'potx'  => 'application/vnd.openxmlformats-officedocument.presentationml.template',
            'ppsx'  => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'sldx'  => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
            'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'dotx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'xlam'  => 'application/vnd.ms-excel.addin.macroEnabled.12',
            'xlsb'  => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
            'class' => 'application/octet-stream',
            'dll'   => 'application/octet-stream',
            'dms'   => 'application/octet-stream',
            'exe'   => 'application/octet-stream',
            'lha'   => 'application/octet-stream',
            'lzh'   => 'application/octet-stream',
            'psd'   => 'application/octet-stream',
            'sea'   => 'application/octet-stream',
            'so'    => 'application/octet-stream',
            'oda'   => 'application/oda',
            'pdf'   => 'application/pdf',
            'ai'    => 'application/postscript',
            'eps'   => 'application/postscript',
            'ps'    => 'application/postscript',
            'smi'   => 'application/smil',
            'smil'  => 'application/smil',
            'mif'   => 'application/vnd.mif',
            'xls'   => 'application/vnd.ms-excel',
            'ppt'   => 'application/vnd.ms-powerpoint',
            'wbxml' => 'application/vnd.wap.wbxml',
            'wmlc'  => 'application/vnd.wap.wmlc',
            'dcr'   => 'application/x-director',
            'dir'   => 'application/x-director',
            'dxr'   => 'application/x-director',
            'dvi'   => 'application/x-dvi',
            'gtar'  => 'application/x-gtar',
            'php3'  => 'application/x-httpd-php',
            'php4'  => 'application/x-httpd-php',
            'php'   => 'application/x-httpd-php',
            'phtml' => 'application/x-httpd-php',
            'phps'  => 'application/x-httpd-php-source',
            'swf'   => 'application/x-shockwave-flash',
            'sit'   => 'application/x-stuffit',
            'tar'   => 'application/x-tar',
            'tgz'   => 'application/x-tar',
            'xht'   => 'application/xhtml+xml',
            'xhtml' => 'application/xhtml+xml',
            'zip'   => 'application/zip',
            'mid'   => 'audio/midi',
            'midi'  => 'audio/midi',
            'mp2'   => 'audio/mpeg',
            'mp3'   => 'audio/mpeg',
            'mpga'  => 'audio/mpeg',
            'aif'   => 'audio/x-aiff',
            'aifc'  => 'audio/x-aiff',
            'aiff'  => 'audio/x-aiff',
            'ram'   => 'audio/x-pn-realaudio',
            'rm'    => 'audio/x-pn-realaudio',
            'rpm'   => 'audio/x-pn-realaudio-plugin',
            'ra'    => 'audio/x-realaudio',
            'wav'   => 'audio/x-wav',
            'bmp'   => 'image/bmp',
            'gif'   => 'image/gif',
            'jpeg'  => 'image/jpeg',
            'jpe'   => 'image/jpeg',
            'jpg'   => 'image/jpeg',
            'png'   => 'image/png',
            'tiff'  => 'image/tiff',
            'tif'   => 'image/tiff',
            'eml'   => 'message/rfc822',
            'css'   => 'text/css',
            'html'  => 'text/html',
            'htm'   => 'text/html',
            'shtml' => 'text/html',
            'log'   => 'text/plain',
            'text'  => 'text/plain',
            'txt'   => 'text/plain',
            'rtx'   => 'text/richtext',
            'rtf'   => 'text/rtf',
            'vcf'   => 'text/vcard',
            'vcard' => 'text/vcard',
            'xml'   => 'text/xml',
            'xsl'   => 'text/xml',
            'mpeg'  => 'video/mpeg',
            'mpe'   => 'video/mpeg',
            'mpg'   => 'video/mpeg',
            'mov'   => 'video/quicktime',
            'qt'    => 'video/quicktime',
            'rv'    => 'video/vnd.rn-realvideo',
            'avi'   => 'video/x-msvideo',
            'movie' => 'video/x-sgi-movie'
        );
        if (array_key_exists(strtolower($ext), $mimes)) {
            return $mimes[strtolower($ext)];
        }
        return 'application/octet-stream';
    }

    /**
     * Map a file name to a MIME type.
     * Defaults to 'application/octet-stream', i.e.. arbitrary binary data.
     * @param string $filename A file name or full path, does not need to exist as a file
     * @return string
     * @static
     */
    public static function filenameToType($filename)
    {
        // In case the path is a URL, strip any query string before getting extension
        $qpos = strpos($filename, '?');
        if (false !== $qpos) {
            $filename = substr($filename, 0, $qpos);
        }
        $pathinfo = self::mb_pathinfo($filename);
        return self::_mime_types($pathinfo['extension']);
    }

    /**
     * Multi-byte-safe pathinfo replacement.
     * Drop-in replacement for pathinfo(), but multibyte-safe, cross-platform-safe, old-version-safe.
     * Works similarly to the one in PHP >= 5.2.0
     * @link http://www.php.net/manual/en/function.pathinfo.php#107461
     * @param string $path A filename or path, does not need to exist as a file
     * @param integer|string $options Either a PATHINFO_* constant,
     *      or a string name to return only the specified piece, allows 'filename' to work on PHP < 5.2
     * @return string|array
     * @static
     */
    public static function mb_pathinfo($path, $options = null)
    {
        $ret = array('dirname' => '', 'basename' => '', 'extension' => '', 'filename' => '');
        $pathinfo = array();
        if (preg_match('%^(.*?)[\\\\/]*(([^/\\\\]*?)(\.([^\.\\\\/]+?)|))[\\\\/\.]*$%im', $path, $pathinfo)) {
            if (array_key_exists(1, $pathinfo)) {
                $ret['dirname'] = $pathinfo[1];
            }
            if (array_key_exists(2, $pathinfo)) {
                $ret['basename'] = $pathinfo[2];
            }
            if (array_key_exists(5, $pathinfo)) {
                $ret['extension'] = $pathinfo[5];
            }
            if (array_key_exists(3, $pathinfo)) {
                $ret['filename'] = $pathinfo[3];
            }
        }
        switch ($options) {
            case PATHINFO_DIRNAME:
            case 'dirname':
                return $ret['dirname'];
            case PATHINFO_BASENAME:
            case 'basename':
                return $ret['basename'];
            case PATHINFO_EXTENSION:
            case 'extension':
                return $ret['extension'];
            case PATHINFO_FILENAME:
            case 'filename':
                return $ret['filename'];
            default:
                return $ret;
        }
    }

    /**
     * Set or reset instance properties.
     * You should avoid this function - it's more verbose, less efficient, more error-prone and
     * harder to debug than setting properties directly.
     * Usage Example:
     * `$mail->set('SMTPSecure', 'tls');`
     *   is the same as:
     * `$mail->SMTPSecure = 'tls';`
     * @access public
     * @param string $name The property name to set
     * @param mixed $value The value to set the property to
     * @return boolean
     * @TODO Should this not be using the __set() magic function?
     */
    public function set($name, $value = '')
    {
        if (property_exists($this, $name)) {
            $this->$name = $value;
            return true;
        } else {
            $this->setError($this->lang('variable_set') . $name);
            return false;
        }
    }

    /**
     * Strip newlines to prevent header injection.
     * @access public
     * @param string $str
     * @return string
     */
    public function secureHeader($str)
    {
        return trim(str_replace(array("\r", "\n"), '', $str));
    }

    /**
     * Normalize line breaks in a string.
     * Converts UNIX LF, Mac CR and Windows CRLF line breaks into a single line break format.
     * Defaults to CRLF (for message bodies) and preserves consecutive breaks.
     * @param string $text
     * @param string $breaktype What kind of line break to use, defaults to CRLF
     * @return string
     * @access public
     * @static
     */
    public static function normalizeBreaks($text, $breaktype = "\r\n")
    {
        return preg_replace('/(\r\n|\r|\n)/ms', $breaktype, $text);
    }

    /**
     * Set the public and private key files and password for S/MIME signing.
     * @access public
     * @param string $cert_filename
     * @param string $key_filename
     * @param string $key_pass Password for private key
     * @param string $extracerts_filename Optional path to chain certificate
     */
    public function sign($cert_filename, $key_filename, $key_pass, $extracerts_filename = '')
    {
        $this->sign_cert_file = $cert_filename;
        $this->sign_key_file = $key_filename;
        $this->sign_key_pass = $key_pass;
        $this->sign_extracerts_file = $extracerts_filename;
    }

    /**
     * Quoted-Printable-encode a DKIM header.
     * @access public
     * @param string $txt
     * @return string
     */
    public function DKIM_QP($txt)
    {
        $line = '';
        for ($i = 0; $i < strlen($txt); $i++) {
            $ord = ord($txt[$i]);
            if (((0x21 <= $ord) && ($ord <= 0x3A)) || $ord == 0x3C || ((0x3E <= $ord) && ($ord <= 0x7E))) {
                $line .= $txt[$i];
            } else {
                $line .= '=' . sprintf('%02X', $ord);
            }
        }
        return $line;
    }

    /**
     * Generate a DKIM signature.
     * @access public
     * @param string $signHeader
     * @throws phpmailerException
     * @return string
     */
    public function DKIM_Sign($signHeader)
    {
        if (!defined('PKCS7_TEXT')) {
            if ($this->exceptions) {
                throw new phpmailerException($this->lang('extension_missing') . 'openssl');
            }
            return '';
        }
        $privKeyStr = file_get_contents($this->DKIM_private);
        if ($this->DKIM_passphrase != '') {
            $privKey = openssl_pkey_get_private($privKeyStr, $this->DKIM_passphrase);
        } else {
            $privKey = openssl_pkey_get_private($privKeyStr);
        }
        if (openssl_sign($signHeader, $signature, $privKey, 'sha256WithRSAEncryption')) { //sha1WithRSAEncryption
            openssl_pkey_free($privKey);
            return base64_encode($signature);
        }
        openssl_pkey_free($privKey);
        return '';
    }

    /**
     * Generate a DKIM canonicalization header.
     * @access public
     * @param string $signHeader Header
     * @return string
     */
    public function DKIM_HeaderC($signHeader)
    {
        $signHeader = preg_replace('/\r\n\s+/', ' ', $signHeader);
        $lines = explode("\r\n", $signHeader);
        foreach ($lines as $key => $line) {
            list($heading, $value) = explode(':', $line, 2);
            $heading = strtolower($heading);
            $value = preg_replace('/\s{2,}/', ' ', $value); // Compress useless spaces
            $lines[$key] = $heading . ':' . trim($value); // Don't forget to remove WSP around the value
        }
        $signHeader = implode("\r\n", $lines);
        return $signHeader;
    }

    /**
     * Generate a DKIM canonicalization body.
     * @access public
     * @param string $body Message Body
     * @return string
     */
    public function DKIM_BodyC($body)
    {
        if ($body == '') {
            return "\r\n";
        }
        // stabilize line endings
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        // END stabilize line endings
        while (substr($body, strlen($body) - 4, 4) == "\r\n\r\n") {
            $body = substr($body, 0, strlen($body) - 2);
        }
        return $body;
    }

    /**
     * Create the DKIM header and body in a new message header.
     * @access public
     * @param string $headers_line Header lines
     * @param string $subject Subject
     * @param string $body Body
     * @return string
     */
    public function DKIM_Add($headers_line, $subject, $body)
    {
        $DKIMsignatureType = 'rsa-sha256'; // Signature & hash algorithms
        $DKIMcanonicalization = 'relaxed/simple'; // Canonicalization of header/body
        $DKIMquery = 'dns/txt'; // Query method
        $DKIMtime = time(); // Signature Timestamp = seconds since 00:00:00 - Jan 1, 1970 (UTC time zone)
        $subject_header = "Subject: $subject";
        $headers = explode($this->LE, $headers_line);
        $from_header = '';
        $to_header = '';
        $date_header = '';
        $current = '';
        foreach ($headers as $header) {
            if (strpos($header, 'From:') === 0) {
                $from_header = $header;
                $current = 'from_header';
            } elseif (strpos($header, 'To:') === 0) {
                $to_header = $header;
                $current = 'to_header';
            } elseif (strpos($header, 'Date:') === 0) {
                $date_header = $header;
                $current = 'date_header';
            } else {
                if (!empty($$current) && strpos($header, ' =?') === 0) {
                    $$current .= $header;
                } else {
                    $current = '';
                }
            }
        }
        $from = str_replace('|', '=7C', $this->DKIM_QP($from_header));
        $to = str_replace('|', '=7C', $this->DKIM_QP($to_header));
        $date = str_replace('|', '=7C', $this->DKIM_QP($date_header));
        $subject = str_replace(
            '|',
            '=7C',
            $this->DKIM_QP($subject_header)
        ); // Copied header fields (dkim-quoted-printable)
        $body = $this->DKIM_BodyC($body);
        $DKIMlen = strlen($body); // Length of body
        $DKIMb64 = base64_encode(pack('H*', hash('sha256', $body))); // Base64 of packed binary SHA-256 hash of body
        if ('' == $this->DKIM_identity) {
            $ident = '';
        } else {
            $ident = ' i=' . $this->DKIM_identity . ';';
        }
        $dkimhdrs = 'DKIM-Signature: v=1; a=' .
            $DKIMsignatureType . '; q=' .
            $DKIMquery . '; l=' .
            $DKIMlen . '; s=' .
            $this->DKIM_selector .
            ";\r\n" .
            "\tt=" . $DKIMtime . '; c=' . $DKIMcanonicalization . ";\r\n" .
            "\th=From:To:Date:Subject;\r\n" .
            "\td=" . $this->DKIM_domain . ';' . $ident . "\r\n" .
            "\tz=$from\r\n" .
            "\t|$to\r\n" .
            "\t|$date\r\n" .
            "\t|$subject;\r\n" .
            "\tbh=" . $DKIMb64 . ";\r\n" .
            "\tb=";
        $toSign = $this->DKIM_HeaderC(
            $from_header . "\r\n" .
            $to_header . "\r\n" .
            $date_header . "\r\n" .
            $subject_header . "\r\n" .
            $dkimhdrs
        );
        $signed = $this->DKIM_Sign($toSign);
        return $dkimhdrs . $signed . "\r\n";
    }

    /**
     * Detect if a string contains a line longer than the maximum line length allowed.
     * @param string $str
     * @return boolean
     * @static
     */
    public static function hasLineLongerThanMax($str)
    {
        //+2 to include CRLF line break for a 1000 total
        return (boolean)preg_match('/^(.{'.(self::MAX_LINE_LENGTH + 2).',})/m', $str);
    }

    /**
     * Allows for public read access to 'to' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getToAddresses()
    {
        return $this->to;
    }

    /**
     * Allows for public read access to 'cc' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getCcAddresses()
    {
        return $this->cc;
    }

    /**
     * Allows for public read access to 'bcc' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getBccAddresses()
    {
        return $this->bcc;
    }

    /**
     * Allows for public read access to 'ReplyTo' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getReplyToAddresses()
    {
        return $this->ReplyTo;
    }

    /**
     * Allows for public read access to 'all_recipients' property.
     * @note: Before the send() call, queued addresses (i.e. with IDN) are not yet included.
     * @access public
     * @return array
     */
    public function getAllRecipientAddresses()
    {
        return $this->all_recipients;
    }

    /**
     * Perform a callback.
     * @param boolean $isSent
     * @param array $to
     * @param array $cc
     * @param array $bcc
     * @param string $subject
     * @param string $body
     * @param string $from
     */
    protected function doCallback($isSent, $to, $cc, $bcc, $subject, $body, $from)
    {
        if (!empty($this->action_function) && is_callable($this->action_function)) {
            $params = array($isSent, $to, $cc, $bcc, $subject, $body, $from);
            call_user_func_array($this->action_function, $params);
        }
    }
}

/**
 * PHPMailer exception handler
 * @package PHPMailer
 */
class phpmailerException extends Exception
{
    /**
     * Prettify error message output
     * @return string
     */
    public function errorMessage()
    {
        $errorMsg = '<strong>' . $this->getMessage() . "</strong><br />\n";
        return $errorMsg;
    }
}
/**
 * PHPMailer RFC821 SMTP email transport class.
 * PHP Version 5
 * @package PHPMailer
 * @link https://github.com/PHPMailer/PHPMailer/ The PHPMailer GitHub project
 * @author Marcus Bointon (Synchro/coolbru) <phpmailer@synchromedia.co.uk>
 * @author Jim Jagielski (jimjag) <jimjag@gmail.com>
 * @author Andy Prevost (codeworxtech) <codeworxtech@users.sourceforge.net>
 * @author Brent R. Matzelle (original founder)
 * @copyright 2014 Marcus Bointon
 * @copyright 2010 - 2012 Jim Jagielski
 * @copyright 2004 - 2009 Andy Prevost
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 * @note This program is distributed in the hope that it will be useful - WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
 * PHPMailer RFC821 SMTP email transport class.
 * Implements RFC 821 SMTP commands and provides some utility methods for sending mail to an SMTP server.
 * @package PHPMailer
 * @author Chris Ryan
 * @author Marcus Bointon <phpmailer@synchromedia.co.uk>
 */
class SMTP
{
    /**
     * The PHPMailer SMTP version number.
     * @var string
     */
    const VERSION = '5.2.16';

    /**
     * SMTP line break constant.
     * @var string
     */
    const CRLF = "\r\n";

    /**
     * The SMTP port to use if one is not specified.
     * @var integer
     */
    const DEFAULT_SMTP_PORT = 25;

    /**
     * The maximum line length allowed by RFC 2822 section 2.1.1
     * @var integer
     */
    const MAX_LINE_LENGTH = 998;

    /**
     * Debug level for no output
     */
    const DEBUG_OFF = 0;

    /**
     * Debug level to show client -> server messages
     */
    const DEBUG_CLIENT = 1;

    /**
     * Debug level to show client -> server and server -> client messages
     */
    const DEBUG_SERVER = 2;

    /**
     * Debug level to show connection status, client -> server and server -> client messages
     */
    const DEBUG_CONNECTION = 3;

    /**
     * Debug level to show all messages
     */
    const DEBUG_LOWLEVEL = 4;

    /**
     * The PHPMailer SMTP Version number.
     * @var string
     * @deprecated Use the `VERSION` constant instead
     * @see SMTP::VERSION
     */
    public $Version = '5.2.16';

    /**
     * SMTP server port number.
     * @var integer
     * @deprecated This is only ever used as a default value, so use the `DEFAULT_SMTP_PORT` constant instead
     * @see SMTP::DEFAULT_SMTP_PORT
     */
    public $SMTP_PORT = 25;

    /**
     * SMTP reply line ending.
     * @var string
     * @deprecated Use the `CRLF` constant instead
     * @see SMTP::CRLF
     */
    public $CRLF = "\r\n";

    /**
     * Debug output level.
     * Options:
     * * self::DEBUG_OFF (`0`) No debug output, default
     * * self::DEBUG_CLIENT (`1`) Client commands
     * * self::DEBUG_SERVER (`2`) Client commands and server responses
     * * self::DEBUG_CONNECTION (`3`) As DEBUG_SERVER plus connection status
     * * self::DEBUG_LOWLEVEL (`4`) Low-level data output, all messages
     * @var integer
     */
    public $do_debug = self::DEBUG_OFF;

    /**
     * How to handle debug output.
     * Options:
     * * `echo` Output plain-text as-is, appropriate for CLI
     * * `html` Output escaped, line breaks converted to `<br>`, appropriate for browser output
     * * `error_log` Output to error log as configured in php.ini
     *
     * Alternatively, you can provide a callable expecting two params: a message string and the debug level:
     * <code>
     * $smtp->Debugoutput = function($str, $level) {echo "debug level $level; message: $str";};
     * </code>
     * @var string|callable
     */
    public $Debugoutput = 'echo';

    /**
     * Whether to use VERP.
     * @link http://en.wikipedia.org/wiki/Variable_envelope_return_path
     * @link http://www.postfix.org/VERP_README.html Info on VERP
     * @var boolean
     */
    public $do_verp = false;

    /**
     * The timeout value for connection, in seconds.
     * Default of 5 minutes (300sec) is from RFC2821 section 4.5.3.2
     * This needs to be quite high to function correctly with hosts using greetdelay as an anti-spam measure.
     * @link http://tools.ietf.org/html/rfc2821#section-4.5.3.2
     * @var integer
     */
    public $Timeout = 300;

    /**
     * How long to wait for commands to complete, in seconds.
     * Default of 5 minutes (300sec) is from RFC2821 section 4.5.3.2
     * @var integer
     */
    public $Timelimit = 300;

    /**
     * The socket for the server connection.
     * @var resource
     */
    protected $smtp_conn;

    /**
     * Error information, if any, for the last SMTP command.
     * @var array
     */
    protected $error = array(
        'error' => '',
        'detail' => '',
        'smtp_code' => '',
        'smtp_code_ex' => ''
    );

    /**
     * The reply the server sent to us for HELO.
     * If null, no HELO string has yet been received.
     * @var string|null
     */
    protected $helo_rply = null;

    /**
     * The set of SMTP extensions sent in reply to EHLO command.
     * Indexes of the array are extension names.
     * Value at index 'HELO' or 'EHLO' (according to command that was sent)
     * represents the server name. In case of HELO it is the only element of the array.
     * Other values can be boolean TRUE or an array containing extension options.
     * If null, no HELO/EHLO string has yet been received.
     * @var array|null
     */
    protected $server_caps = null;

    /**
     * The most recent reply received from the server.
     * @var string
     */
    protected $last_reply = '';

    /**
     * Output debugging info via a user-selected method.
     * @see SMTP::$Debugoutput
     * @see SMTP::$do_debug
     * @param string $str Debug string to output
     * @param integer $level The debug level of this message; see DEBUG_* constants
     * @return void
     */
    protected function edebug($str, $level = 0)
    {
        if ($level > $this->do_debug) {
            return;
        }
        //Avoid clash with built-in function names
        if (!in_array($this->Debugoutput, array('error_log', 'html', 'echo')) and is_callable($this->Debugoutput)) {
            call_user_func($this->Debugoutput, $str, $this->do_debug);
            return;
        }
        switch ($this->Debugoutput) {
            case 'error_log':
                //Don't output, just log
                error_log($str);
                break;
            case 'html':
                //Cleans up output a bit for a better looking, HTML-safe output
                echo htmlentities(
                    preg_replace('/[\r\n]+/', '', $str),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . "<br>\n";
                break;
            case 'echo':
            default:
                //Normalize line breaks
                $str = preg_replace('/(\r\n|\r|\n)/ms', "\n", $str);
                echo gmdate('Y-m-d H:i:s') . "\t" . str_replace(
                    "\n",
                    "\n                   \t                  ",
                    trim($str)
                )."\n";
        }
    }

    /**
     * Connect to an SMTP server.
     * @param string $host SMTP server IP or host name
     * @param integer $port The port number to connect to
     * @param integer $timeout How long to wait for the connection to open
     * @param array $options An array of options for stream_context_create()
     * @access public
     * @return boolean
     */
    public function connect($host, $port = null, $timeout = 30, $options = array())
    {
        static $streamok;
        //This is enabled by default since 5.0.0 but some providers disable it
        //Check this once and cache the result
        if (is_null($streamok)) {
            $streamok = function_exists('stream_socket_client');
        }
        // Clear errors to avoid confusion
        $this->setError('');
        // Make sure we are __not__ connected
        if ($this->connected()) {
            // Already connected, generate error
            $this->setError('Already connected to a server');
            return false;
        }
        if (empty($port)) {
            $port = self::DEFAULT_SMTP_PORT;
        }
        // Connect to the SMTP server
        $this->edebug(
            "Connection: opening to $host:$port, timeout=$timeout, options=".var_export($options, true),
            self::DEBUG_CONNECTION
        );
        $errno = 0;
        $errstr = '';
        if ($streamok) {
            $socket_context = stream_context_create($options);
            //Suppress errors; connection failures are handled at a higher level
            $this->smtp_conn = @stream_socket_client(
                $host . ":" . $port,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $socket_context
            );
        } else {
            //Fall back to fsockopen which should work in more places, but is missing some features
            $this->edebug(
                "Connection: stream_socket_client not available, falling back to fsockopen",
                self::DEBUG_CONNECTION
            );
            $this->smtp_conn = fsockopen(
                $host,
                $port,
                $errno,
                $errstr,
                $timeout
            );
        }
        // Verify we connected properly
        if (!is_resource($this->smtp_conn)) {
            $this->setError(
                'Failed to connect to server',
                $errno,
                $errstr
            );
            $this->edebug(
                'SMTP ERROR: ' . $this->error['error']
                . ": $errstr ($errno)",
                self::DEBUG_CLIENT
            );
            return false;
        }
        $this->edebug('Connection: opened', self::DEBUG_CONNECTION);
        // SMTP server can take longer to respond, give longer timeout for first read
        // Windows does not have support for this timeout function
        if (substr(PHP_OS, 0, 3) != 'WIN') {
            $max = ini_get('max_execution_time');
            // Don't bother if unlimited
            if ($max != 0 && $timeout > $max) {
                @set_time_limit($timeout);
            }
            stream_set_timeout($this->smtp_conn, $timeout, 0);
        }
        // Get any announcement
        $announce = $this->get_lines();
        $this->edebug('SERVER -> CLIENT: ' . $announce, self::DEBUG_SERVER);
        return true;
    }

    /**
     * Initiate a TLS (encrypted) session.
     * @access public
     * @return boolean
     */
    public function startTLS()
    {
        if (!$this->sendCommand('STARTTLS', 'STARTTLS', 220)) {
            return false;
        }

        //Allow the best TLS version(s) we can
        $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        //PHP 5.6.7 dropped inclusion of TLS 1.1 and 1.2 in STREAM_CRYPTO_METHOD_TLS_CLIENT
        //so add them back in manually if we can
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        // Begin encrypted connection
        if (!stream_socket_enable_crypto(
            $this->smtp_conn,
            true,
            $crypto_method
        )) {
            return false;
        }
        return true;
    }

    /**
     * Perform SMTP authentication.
     * Must be run after hello().
     * @see hello()
     * @param string $username The user name
     * @param string $password The password
     * @param string $authtype The auth type (PLAIN, LOGIN, NTLM, CRAM-MD5, XOAUTH2)
     * @param string $realm The auth realm for NTLM
     * @param string $workstation The auth workstation for NTLM
     * @param null|OAuth $OAuth An optional OAuth instance (@see PHPMailerOAuth)
     * @return bool True if successfully authenticated.* @access public
     */
    public function authenticate(
        $username,
        $password,
        $authtype = null,
        $realm = '',
        $workstation = '',
        $OAuth = null
    ) {
        if (!$this->server_caps) {
            $this->setError('Authentication is not allowed before HELO/EHLO');
            return false;
        }

        if (array_key_exists('EHLO', $this->server_caps)) {
        // SMTP extensions are available. Let's try to find a proper authentication method

            if (!array_key_exists('AUTH', $this->server_caps)) {
                $this->setError('Authentication is not allowed at this stage');
                // 'at this stage' means that auth may be allowed after the stage changes
                // e.g. after STARTTLS
                return false;
            }

            self::edebug('Auth method requested: ' . ($authtype ? $authtype : 'UNKNOWN'), self::DEBUG_LOWLEVEL);
            self::edebug(
                'Auth methods available on the server: ' . implode(',', $this->server_caps['AUTH']),
                self::DEBUG_LOWLEVEL
            );

            if (empty($authtype)) {
                foreach (array('CRAM-MD5', 'LOGIN', 'PLAIN', 'NTLM', 'XOAUTH2') as $method) {
                    if (in_array($method, $this->server_caps['AUTH'])) {
                        $authtype = $method;
                        break;
                    }
                }
                if (empty($authtype)) {
                    $this->setError('No supported authentication methods found');
                    return false;
                }
                self::edebug('Auth method selected: '.$authtype, self::DEBUG_LOWLEVEL);
            }

            if (!in_array($authtype, $this->server_caps['AUTH'])) {
                $this->setError("The requested authentication method \"$authtype\" is not supported by the server");
                return false;
            }
        } elseif (empty($authtype)) {
            $authtype = 'LOGIN';
        }
        switch ($authtype) {
            case 'PLAIN':
                // Start authentication
                if (!$this->sendCommand('AUTH', 'AUTH PLAIN', 334)) {
                    return false;
                }
                // Send encoded username and password
                if (!$this->sendCommand(
                    'User & Password',
                    base64_encode("\0" . $username . "\0" . $password),
                    235
                )
                ) {
                    return false;
                }
                break;
            case 'LOGIN':
                // Start authentication
                if (!$this->sendCommand('AUTH', 'AUTH LOGIN', 334)) {
                    return false;
                }
                if (!$this->sendCommand("Username", base64_encode($username), 334)) {
                    return false;
                }
                if (!$this->sendCommand("Password", base64_encode($password), 235)) {
                    return false;
                }
                break;
            case 'XOAUTH2':
                //If the OAuth Instance is not set. Can be a case when PHPMailer is used
                //instead of PHPMailerOAuth
                if (is_null($OAuth)) {
                    return false;
                }
                $oauth = $OAuth->getOauth64();

                // Start authentication
                if (!$this->sendCommand('AUTH', 'AUTH XOAUTH2 ' . $oauth, 235)) {
                    return false;
                }
                break;
            case 'NTLM':
                /*
                 * ntlm_sasl_client.php
                 * Bundled with Permission
                 *
                 * How to telnet in windows:
                 * http://technet.microsoft.com/en-us/library/aa995718%28EXCHG.65%29.aspx
                 * PROTOCOL Docs http://curl.haxx.se/rfc/ntlm.html#ntlmSmtpAuthentication
                 */
                require_once 'extras/ntlm_sasl_client.php';
                $temp = new stdClass;
                $ntlm_client = new ntlm_sasl_client_class;
                //Check that functions are available
                if (!$ntlm_client->Initialize($temp)) {
                    $this->setError($temp->error);
                    $this->edebug(
                        'You need to enable some modules in your php.ini file: '
                        . $this->error['error'],
                        self::DEBUG_CLIENT
                    );
                    return false;
                }
                //msg1
                $msg1 = $ntlm_client->TypeMsg1($realm, $workstation); //msg1

                if (!$this->sendCommand(
                    'AUTH NTLM',
                    'AUTH NTLM ' . base64_encode($msg1),
                    334
                )
                ) {
                    return false;
                }
                //Though 0 based, there is a white space after the 3 digit number
                //msg2
                $challenge = substr($this->last_reply, 3);
                $challenge = base64_decode($challenge);
                $ntlm_res = $ntlm_client->NTLMResponse(
                    substr($challenge, 24, 8),
                    $password
                );
                //msg3
                $msg3 = $ntlm_client->TypeMsg3(
                    $ntlm_res,
                    $username,
                    $realm,
                    $workstation
                );
                // send encoded username
                return $this->sendCommand('Username', base64_encode($msg3), 235);
            case 'CRAM-MD5':
                // Start authentication
                if (!$this->sendCommand('AUTH CRAM-MD5', 'AUTH CRAM-MD5', 334)) {
                    return false;
                }
                // Get the challenge
                $challenge = base64_decode(substr($this->last_reply, 4));

                // Build the response
                $response = $username . ' ' . $this->hmac($challenge, $password);

                // send encoded credentials
                return $this->sendCommand('Username', base64_encode($response), 235);
            default:
                $this->setError("Authentication method \"$authtype\" is not supported");
                return false;
        }
        return true;
    }

    /**
     * Calculate an MD5 HMAC hash.
     * Works like hash_hmac('md5', $data, $key)
     * in case that function is not available
     * @param string $data The data to hash
     * @param string $key  The key to hash with
     * @access protected
     * @return string
     */
    protected function hmac($data, $key)
    {
        if (function_exists('hash_hmac')) {
            return hash_hmac('md5', $data, $key);
        }

        // The following borrowed from
        // http://php.net/manual/en/function.mhash.php#27225

        // RFC 2104 HMAC implementation for php.
        // Creates an md5 HMAC.
        // Eliminates the need to install mhash to compute a HMAC
        // by Lance Rushing

        $bytelen = 64; // byte length for md5
        if (strlen($key) > $bytelen) {
            $key = pack('H*', md5($key));
        }
        $key = str_pad($key, $bytelen, chr(0x00));
        $ipad = str_pad('', $bytelen, chr(0x36));
        $opad = str_pad('', $bytelen, chr(0x5c));
        $k_ipad = $key ^ $ipad;
        $k_opad = $key ^ $opad;

        return md5($k_opad . pack('H*', md5($k_ipad . $data)));
    }

    /**
     * Check connection state.
     * @access public
     * @return boolean True if connected.
     */
    public function connected()
    {
        if (is_resource($this->smtp_conn)) {
            $sock_status = stream_get_meta_data($this->smtp_conn);
            if ($sock_status['eof']) {
                // The socket is valid but we are not connected
                $this->edebug(
                    'SMTP NOTICE: EOF caught while checking if connected',
                    self::DEBUG_CLIENT
                );
                $this->close();
                return false;
            }
            return true; // everything looks good
        }
        return false;
    }

    /**
     * Close the socket and clean up the state of the class.
     * Don't use this function without first trying to use QUIT.
     * @see quit()
     * @access public
     * @return void
     */
    public function close()
    {
        $this->setError('');
        $this->server_caps = null;
        $this->helo_rply = null;
        if (is_resource($this->smtp_conn)) {
            // close the connection and cleanup
            fclose($this->smtp_conn);
            $this->smtp_conn = null; //Makes for cleaner serialization
            $this->edebug('Connection: closed', self::DEBUG_CONNECTION);
        }
    }

    /**
     * Send an SMTP DATA command.
     * Issues a data command and sends the msg_data to the server,
     * finializing the mail transaction. $msg_data is the message
     * that is to be send with the headers. Each header needs to be
     * on a single line followed by a <CRLF> with the message headers
     * and the message body being separated by and additional <CRLF>.
     * Implements rfc 821: DATA <CRLF>
     * @param string $msg_data Message data to send
     * @access public
     * @return boolean
     */
    public function data($msg_data)
    {
        //This will use the standard timelimit
        if (!$this->sendCommand('DATA', 'DATA', 354)) {
            return false;
        }

        /* The server is ready to accept data!
         * According to rfc821 we should not send more than 1000 characters on a single line (including the CRLF)
         * so we will break the data up into lines by \r and/or \n then if needed we will break each of those into
         * smaller lines to fit within the limit.
         * We will also look for lines that start with a '.' and prepend an additional '.'.
         * NOTE: this does not count towards line-length limit.
         */

        // Normalize line breaks before exploding
        $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $msg_data));

        /* To distinguish between a complete RFC822 message and a plain message body, we check if the first field
         * of the first line (':' separated) does not contain a space then it _should_ be a header and we will
         * process all lines before a blank line as headers.
         */

        $field = substr($lines[0], 0, strpos($lines[0], ':'));
        $in_headers = false;
        if (!empty($field) && strpos($field, ' ') === false) {
            $in_headers = true;
        }

        foreach ($lines as $line) {
            $lines_out = array();
            if ($in_headers and $line == '') {
                $in_headers = false;
            }
            //Break this line up into several smaller lines if it's too long
            //Micro-optimisation: isset($str[$len]) is faster than (strlen($str) > $len),
            while (isset($line[self::MAX_LINE_LENGTH])) {
                //Working backwards, try to find a space within the last MAX_LINE_LENGTH chars of the line to break on
                //so as to avoid breaking in the middle of a word
                $pos = strrpos(substr($line, 0, self::MAX_LINE_LENGTH), ' ');
                //Deliberately matches both false and 0
                if (!$pos) {
                    //No nice break found, add a hard break
                    $pos = self::MAX_LINE_LENGTH - 1;
                    $lines_out[] = substr($line, 0, $pos);
                    $line = substr($line, $pos);
                } else {
                    //Break at the found point
                    $lines_out[] = substr($line, 0, $pos);
                    //Move along by the amount we dealt with
                    $line = substr($line, $pos + 1);
                }
                //If processing headers add a LWSP-char to the front of new line RFC822 section 3.1.1
                if ($in_headers) {
                    $line = "\t" . $line;
                }
            }
            $lines_out[] = $line;

            //Send the lines to the server
            foreach ($lines_out as $line_out) {
                //RFC2821 section 4.5.2
                if (!empty($line_out) and $line_out[0] == '.') {
                    $line_out = '.' . $line_out;
                }
                $this->client_send($line_out . self::CRLF);
            }
        }

        //Message data has been sent, complete the command
        //Increase timelimit for end of DATA command
        $savetimelimit = $this->Timelimit;
        $this->Timelimit = $this->Timelimit * 2;
        $result = $this->sendCommand('DATA END', '.', 250);
        //Restore timelimit
        $this->Timelimit = $savetimelimit;
        return $result;
    }

    /**
     * Send an SMTP HELO or EHLO command.
     * Used to identify the sending server to the receiving server.
     * This makes sure that client and server are in a known state.
     * Implements RFC 821: HELO <SP> <domain> <CRLF>
     * and RFC 2821 EHLO.
     * @param string $host The host name or IP to connect to
     * @access public
     * @return boolean
     */
    public function hello($host = '')
    {
        //Try extended hello first (RFC 2821)
        return (boolean)($this->sendHello('EHLO', $host) or $this->sendHello('HELO', $host));
    }

    /**
     * Send an SMTP HELO or EHLO command.
     * Low-level implementation used by hello()
     * @see hello()
     * @param string $hello The HELO string
     * @param string $host The hostname to say we are
     * @access protected
     * @return boolean
     */
    protected function sendHello($hello, $host)
    {
        $noerror = $this->sendCommand($hello, $hello . ' ' . $host, 250);
        $this->helo_rply = $this->last_reply;
        if ($noerror) {
            $this->parseHelloFields($hello);
        } else {
            $this->server_caps = null;
        }
        return $noerror;
    }

    /**
     * Parse a reply to HELO/EHLO command to discover server extensions.
     * In case of HELO, the only parameter that can be discovered is a server name.
     * @access protected
     * @param string $type - 'HELO' or 'EHLO'
     */
    protected function parseHelloFields($type)
    {
        $this->server_caps = array();
        $lines = explode("\n", $this->helo_rply);

        foreach ($lines as $n => $s) {
            //First 4 chars contain response code followed by - or space
            $s = trim(substr($s, 4));
            if (empty($s)) {
                continue;
            }
            $fields = explode(' ', $s);
            if (!empty($fields)) {
                if (!$n) {
                    $name = $type;
                    $fields = $fields[0];
                } else {
                    $name = array_shift($fields);
                    switch ($name) {
                        case 'SIZE':
                            $fields = ($fields ? $fields[0] : 0);
                            break;
                        case 'AUTH':
                            if (!is_array($fields)) {
                                $fields = array();
                            }
                            break;
                        default:
                            $fields = true;
                    }
                }
                $this->server_caps[$name] = $fields;
            }
        }
    }

    /**
     * Send an SMTP MAIL command.
     * Starts a mail transaction from the email address specified in
     * $from. Returns true if successful or false otherwise. If True
     * the mail transaction is started and then one or more recipient
     * commands may be called followed by a data command.
     * Implements rfc 821: MAIL <SP> FROM:<reverse-path> <CRLF>
     * @param string $from Source address of this message
     * @access public
     * @return boolean
     */
    public function mail($from)
    {
        $useVerp = ($this->do_verp ? ' XVERP' : '');
        return $this->sendCommand(
            'MAIL FROM',
            'MAIL FROM:<' . $from . '>' . $useVerp,
            250
        );
    }

    /**
     * Send an SMTP QUIT command.
     * Closes the socket if there is no error or the $close_on_error argument is true.
     * Implements from rfc 821: QUIT <CRLF>
     * @param boolean $close_on_error Should the connection close if an error occurs?
     * @access public
     * @return boolean
     */
    public function quit($close_on_error = true)
    {
        $noerror = $this->sendCommand('QUIT', 'QUIT', 221);
        $err = $this->error; //Save any error
        if ($noerror or $close_on_error) {
            $this->close();
            $this->error = $err; //Restore any error from the quit command
        }
        return $noerror;
    }

    /**
     * Send an SMTP RCPT command.
     * Sets the TO argument to $toaddr.
     * Returns true if the recipient was accepted false if it was rejected.
     * Implements from rfc 821: RCPT <SP> TO:<forward-path> <CRLF>
     * @param string $address The address the message is being sent to
     * @access public
     * @return boolean
     */
    public function recipient($address)
    {
        return $this->sendCommand(
            'RCPT TO',
            'RCPT TO:<' . $address . '>',
            array(250, 251)
        );
    }

    /**
     * Send an SMTP RSET command.
     * Abort any transaction that is currently in progress.
     * Implements rfc 821: RSET <CRLF>
     * @access public
     * @return boolean True on success.
     */
    public function reset()
    {
        return $this->sendCommand('RSET', 'RSET', 250);
    }

    /**
     * Send a command to an SMTP server and check its return code.
     * @param string $command The command name - not sent to the server
     * @param string $commandstring The actual command to send
     * @param integer|array $expect One or more expected integer success codes
     * @access protected
     * @return boolean True on success.
     */
    protected function sendCommand($command, $commandstring, $expect)
    {
        if (!$this->connected()) {
            $this->setError("Called $command without being connected");
            return false;
        }
        //Reject line breaks in all commands
        if (strpos($commandstring, "\n") !== false or strpos($commandstring, "\r") !== false) {
            $this->setError("Command '$command' contained line breaks");
            return false;
        }
        $this->client_send($commandstring . self::CRLF);

        $this->last_reply = $this->get_lines();
        // Fetch SMTP code and possible error code explanation
        $matches = array();
        if (preg_match("/^([0-9]{3})[ -](?:([0-9]\\.[0-9]\\.[0-9]) )?/", $this->last_reply, $matches)) {
            $code = $matches[1];
            $code_ex = (count($matches) > 2 ? $matches[2] : null);
            // Cut off error code from each response line
            $detail = preg_replace(
                "/{$code}[ -]".($code_ex ? str_replace('.', '\\.', $code_ex).' ' : '')."/m",
                '',
                $this->last_reply
            );
        } else {
            // Fall back to simple parsing if regex fails
            $code = substr($this->last_reply, 0, 3);
            $code_ex = null;
            $detail = substr($this->last_reply, 4);
        }

        $this->edebug('SERVER -> CLIENT: ' . $this->last_reply, self::DEBUG_SERVER);

        if (!in_array($code, (array)$expect)) {
            $this->setError(
                "$command command failed",
                $detail,
                $code,
                $code_ex
            );
            $this->edebug(
                'SMTP ERROR: ' . $this->error['error'] . ': ' . $this->last_reply,
                self::DEBUG_CLIENT
            );
            return false;
        }

        $this->setError('');
        return true;
    }

    /**
     * Send an SMTP SAML command.
     * Starts a mail transaction from the email address specified in $from.
     * Returns true if successful or false otherwise. If True
     * the mail transaction is started and then one or more recipient
     * commands may be called followed by a data command. This command
     * will send the message to the users terminal if they are logged
     * in and send them an email.
     * Implements rfc 821: SAML <SP> FROM:<reverse-path> <CRLF>
     * @param string $from The address the message is from
     * @access public
     * @return boolean
     */
    public function sendAndMail($from)
    {
        return $this->sendCommand('SAML', "SAML FROM:$from", 250);
    }

    /**
     * Send an SMTP VRFY command.
     * @param string $name The name to verify
     * @access public
     * @return boolean
     */
    public function verify($name)
    {
        return $this->sendCommand('VRFY', "VRFY $name", array(250, 251));
    }

    /**
     * Send an SMTP NOOP command.
     * Used to keep keep-alives alive, doesn't actually do anything
     * @access public
     * @return boolean
     */
    public function noop()
    {
        return $this->sendCommand('NOOP', 'NOOP', 250);
    }

    /**
     * Send an SMTP TURN command.
     * This is an optional command for SMTP that this class does not support.
     * This method is here to make the RFC821 Definition complete for this class
     * and _may_ be implemented in future
     * Implements from rfc 821: TURN <CRLF>
     * @access public
     * @return boolean
     */
    public function turn()
    {
        $this->setError('The SMTP TURN command is not implemented');
        $this->edebug('SMTP NOTICE: ' . $this->error['error'], self::DEBUG_CLIENT);
        return false;
    }

    /**
     * Send raw data to the server.
     * @param string $data The data to send
     * @access public
     * @return integer|boolean The number of bytes sent to the server or false on error
     */
    public function client_send($data)
    {
        $this->edebug("CLIENT -> SERVER: $data", self::DEBUG_CLIENT);
        return fwrite($this->smtp_conn, $data);
    }

    /**
     * Get the latest error.
     * @access public
     * @return array
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Get SMTP extensions available on the server
     * @access public
     * @return array|null
     */
    public function getServerExtList()
    {
        return $this->server_caps;
    }

    /**
     * A multipurpose method
     * The method works in three ways, dependent on argument value and current state
     *   1. HELO/EHLO was not sent - returns null and set up $this->error
     *   2. HELO was sent
     *     $name = 'HELO': returns server name
     *     $name = 'EHLO': returns boolean false
     *     $name = any string: returns null and set up $this->error
     *   3. EHLO was sent
     *     $name = 'HELO'|'EHLO': returns server name
     *     $name = any string: if extension $name exists, returns boolean True
     *       or its options. Otherwise returns boolean False
     * In other words, one can use this method to detect 3 conditions:
     *  - null returned: handshake was not or we don't know about ext (refer to $this->error)
     *  - false returned: the requested feature exactly not exists
     *  - positive value returned: the requested feature exists
     * @param string $name Name of SMTP extension or 'HELO'|'EHLO'
     * @return mixed
     */
    public function getServerExt($name)
    {
        if (!$this->server_caps) {
            $this->setError('No HELO/EHLO was sent');
            return null;
        }

        // the tight logic knot ;)
        if (!array_key_exists($name, $this->server_caps)) {
            if ($name == 'HELO') {
                return $this->server_caps['EHLO'];
            }
            if ($name == 'EHLO' || array_key_exists('EHLO', $this->server_caps)) {
                return false;
            }
            $this->setError('HELO handshake was used. Client knows nothing about server extensions');
            return null;
        }

        return $this->server_caps[$name];
    }

    /**
     * Get the last reply from the server.
     * @access public
     * @return string
     */
    public function getLastReply()
    {
        return $this->last_reply;
    }

    /**
     * Read the SMTP server's response.
     * Either before eof or socket timeout occurs on the operation.
     * With SMTP we can tell if we have more lines to read if the
     * 4th character is '-' symbol. If it is a space then we don't
     * need to read anything else.
     * @access protected
     * @return string
     */
    protected function get_lines()
    {
        // If the connection is bad, give up straight away
        if (!is_resource($this->smtp_conn)) {
            return '';
        }
        $data = '';
        $endtime = 0;
        stream_set_timeout($this->smtp_conn, $this->Timeout);
        if ($this->Timelimit > 0) {
            $endtime = time() + $this->Timelimit;
        }
        while (is_resource($this->smtp_conn) && !feof($this->smtp_conn)) {
            $str = @fgets($this->smtp_conn, 515);
            $this->edebug("SMTP -> get_lines(): \$data is \"$data\"", self::DEBUG_LOWLEVEL);
            $this->edebug("SMTP -> get_lines(): \$str is  \"$str\"", self::DEBUG_LOWLEVEL);
            $data .= $str;
            // If 4th character is a space, we are done reading, break the loop, micro-optimisation over strlen
            if ((isset($str[3]) and $str[3] == ' ')) {
                break;
            }
            // Timed-out? Log and break
            $info = stream_get_meta_data($this->smtp_conn);
            if ($info['timed_out']) {
                $this->edebug(
                    'SMTP -> get_lines(): timed-out (' . $this->Timeout . ' sec)',
                    self::DEBUG_LOWLEVEL
                );
                break;
            }
            // Now check if reads took too long
            if ($endtime and time() > $endtime) {
                $this->edebug(
                    'SMTP -> get_lines(): timelimit reached ('.
                    $this->Timelimit . ' sec)',
                    self::DEBUG_LOWLEVEL
                );
                break;
            }
        }
        return $data;
    }

    /**
     * Enable or disable VERP address generation.
     * @param boolean $enabled
     */
    public function setVerp($enabled = false)
    {
        $this->do_verp = $enabled;
    }

    /**
     * Get VERP address generation mode.
     * @return boolean
     */
    public function getVerp()
    {
        return $this->do_verp;
    }

    /**
     * Set error messages and codes.
     * @param string $message The error message
     * @param string $detail Further detail on the error
     * @param string $smtp_code An associated SMTP error code
     * @param string $smtp_code_ex Extended SMTP code
     */
    protected function setError($message, $detail = '', $smtp_code = '', $smtp_code_ex = '')
    {
        $this->error = array(
            'error' => $message,
            'detail' => $detail,
            'smtp_code' => $smtp_code,
            'smtp_code_ex' => $smtp_code_ex
        );
    }

    /**
     * Set debug output method.
     * @param string|callable $method The name of the mechanism to use for debugging output, or a callable to handle it.
     */
    public function setDebugOutput($method = 'echo')
    {
        $this->Debugoutput = $method;
    }

    /**
     * Get debug output method.
     * @return string
     */
    public function getDebugOutput()
    {
        return $this->Debugoutput;
    }

    /**
     * Set debug output level.
     * @param integer $level
     */
    public function setDebugLevel($level = 0)
    {
        $this->do_debug = $level;
    }

    /**
     * Get debug output level.
     * @return integer
     */
    public function getDebugLevel()
    {
        return $this->do_debug;
    }

    /**
     * Set SMTP timeout.
     * @param integer $timeout
     */
    public function setTimeout($timeout = 0)
    {
        $this->Timeout = $timeout;
    }

    /**
     * Get SMTP timeout.
     * @return integer
     */
    public function getTimeout()
    {
        return $this->Timeout;
    }
}
////////Mailer/////////////////////
class FM_Mailer
{
    private $config;
    private $logger;
    private $attachments;
    private $error_handler;

   public function __construct(&$config,&$logger,&$error_handler)
   {
      $this->config = &$config;
      $this->logger = &$logger;
      $this->error_handler = &$error_handler;
      
      $this->attachments=array();
   }
   
   function SendTextMail($from,$to,$subject,$mailbody)
   {
      $this->SendMail($from,$to,$subject,$mailbody,false);
   }
   
   function SendHtmlMail($from,$to,$subject,$mailbody)
   {
      $this->SendMail($from,$to,$subject,$mailbody,true);
   }
   
   function HandleConfigError($error)
   {
      $this->error_handler->HandleConfigError($error);
   }
   
    function addr($email)
    {
      //Returns 0 --> email , 1--> name

      if(preg_match('/([^<>]+)\s*<(.*)>/',$email,$matches) &&
         count($matches) == 3)
      {
        return array($matches[2],$matches[1]);
      }

      return array($email,'');
    }

    function call_mailer($mail,$fn,$email)
    {//Convert email address to the way PHPMailer Accepts 
      //Simfatic Forms saves email Name<email> form. The functions take the name and email seperate
        $arr = $this->addr($email);
        
        return $mail->$fn($arr[0],$arr[1]);
    }

    function SendMail($from,$to,$subject,$mailbody,$htmlformat)
    {
        $real_from='';
        $reply_to='';
        $mail = new PHPMailer;

        $mail->CharSet = 'UTF-8';

        

        if(true === $this->config->variable_from)
        {
            $real_from = $from;
            $reply_to=$from;
        }
        else
        {
            $real_from = $this->config->from_addr;
            $reply_to = $from;
        }
        
        
        if(true === $this->config->use_smtp)
        {
            $mail->isSMTP();

            $mail->Host = $this->config->smtp_host;

            if(!empty($this->config->smtp_uname))
            {
                $mail->Username = $this->config->smtp_uname;
                $mail->Password = sfm_crypt_decrypt($this->config->smtp_pwd,
                                         $this->config->encr_key);
                $mail->SMTPAuth = true;
                $mail->SMTPSecure = 'tls';//or ssl
                $mail->Port = $this->config->smtp_port;
                if($this->config->smtp_port === 465)
                {
                  $mail->SMTPSecure = 'ssl';
                }
                elseif($this->config->smtp_port === 25)
                {
                  $mail->SMTPSecure = '';
                }
            }

        }
        
        $mail->Subject = $subject;

        foreach($this->attachments as $file)
        {
            $filename = basename($file['filepath']);
            $mail->AddAttachment($file['filepath'], $filename, 'base64',$file['mime_type'] );
        }


        $this->call_mailer($mail,'setFrom', $real_from);

        $this->call_mailer($mail,'addReplyTo', $reply_to);

        if(is_array($to))
        {
            foreach($to as $one_addr)
            {
              $this->call_mailer($mail,'addAddress', $one_addr);     
            }
        }
        else
        {
            $this->call_mailer($mail,'addAddress', $to);  
        }
        

        $mail->isHTML($htmlformat);
        
        $mail->Body = $mailbody;

        $result = $mail->send();

        if(!$result)
        {
            $this->error_handler->HandleConfigError(" Error Sending Mail ".$mail->ErrorInfo);
            return false;
        }

        return true;
    }  
   
    function RFCDate() 
    {
        $tz = date('Z');
        $tzs = ($tz < 0) ? '-' : '+';
        $tz = abs($tz);
        $tz = (int)($tz/3600)*100 + ($tz%3600)/60;
        $result = sprintf("%s %s%04d", date('D, j M Y H:i:s'), $tzs, $tz);

        return $result;
    }
    function CheckHeaders(&$headers)
    {
        foreach ($headers as $key => $value) 
        {
            $value = trim($value);
            $headers[$key] = $value;

            if($this->IsInjected($value))
            {
                $this->logger->LogError("Suspicious email header: $key -> $value. Aborting email attempt");
                return false;
            }
        }
        return true;
    }


    function IsInjected($str) 
    {
       $injections = array('(\n+)',
                   '(\r+)',
                   '(\t+)',
                   '(%0A+)',
                   '(%0D+)',
                   '(%08+)',
                   '(%09+)'
                   );
       $inject = join('|', $injections);
       $inject = "/$inject/i";
       if(preg_match($inject,$str)) 
        {
          return true;
       }
       else 
        {
          return false;
       }
    }


    function AttachFile($filepath,$type)
    {
        $this->attachments[]=array('filepath'=>$filepath,'mime_type'=>$type);
    }
    

    function DefinePHPEOL()
    {
      if (!defined('PHP_EOL')) 
      {
         switch (strtoupper(substr(PHP_OS, 0, 3))) 
         {
         // Windows
         case 'WIN':
             define('PHP_EOL', "\r\n");
             break;

         // Mac
         case 'DAR':
             define('PHP_EOL', "\r");
             break;

         // Unix
         default:
             define('PHP_EOL', "\n");
         }
      }
    }
}

////////ComposedMailSender/////////////
class FM_ComposedMailSender extends FM_Module
{
	protected $config;
	protected $formvars;
	protected $message_subject;
	protected $message_body;	
    protected $mailer;
    
	public function __construct()
	{
        parent::__construct();
        $this->mailer = NULL;
	}
	
    function InitMailer()
    {
        $this->mailer = new FM_Mailer($this->config,$this->logger,$this->error_handler);
    }

	function ComposeMessage($subj_templ,$mail_templ)
	{
		$ret = false;
        $this->message_subject = $subj_templ;

		$templ_page = $mail_templ;
		if(strlen($templ_page)>0)
		{
			$composer = new FM_PageMerger();
        
			$tmpdatamap = 
                $this->common_objs->formvar_mx->CreateFieldMatrix($this->config->email_format_html);
			
            $ret = true;
			if(false == $composer->Merge($templ_page,$tmpdatamap))
            {
                $ret = false;
                $this->logger->LogError("MailComposer: merge failed");
            }
			$this->message_body = $composer->getMessageBody();

            $subj_merge = new FM_PageMerger();

			$tmpdatamap2 = 
                $this->common_objs->formvar_mx->CreateFieldMatrix(/*html?*/false);
                
            $subj_merge->Merge($this->message_subject,$tmpdatamap2);
            $this->message_subject = $subj_merge->getMessageBody();
		}
		return $ret;
	}//ComposeMessage	
	
	function SendMail($from,$to)
	{
        if(NULL == $this->mailer)
        {
            $this->logger->LogError("mail composer: not initialized");
            return false;
        }
		if(false== $this->config->email_format_html)
		{
			$this->mailer->SendTextMail($from,$to,
									$this->message_subject,
									$this->message_body);
		}
		else
		{
			$this->mailer->SendHtmlMail($from,$to,
									$this->message_subject,
									$this->message_body);				
		}	
	}
}//

////////FormDataSender/////////////
class FM_FormDataSender extends FM_ComposedMailSender 
{
    private $mail_subject;
    private $mail_template;
    private $dest_list;
    private $mail_from;
    private $file_upload;
    private $attach_files;

	public function __construct($subj="",$templ="",$from="")
	{
        parent::__construct();
        $this->mail_subject=$subj;
        $this->mail_template=$templ;
        $this->dest_list=array();
        $this->mail_from=$from;
        $this->file_upload=NULL;
        $this->attach_files = true;
	}
	
    function SetFileUploader(&$fileUploader)
    {
        $this->file_upload = &$fileUploader;
    }

    function AddToAddr($toaddr,$condn='')
    {
        array_push($this->dest_list,array('to'=>$toaddr,'condn'=>$condn));
    }
    
    function SetAttachFiles($attach_files)
    {
        $this->attach_files = $attach_files;
    }

	function SendFormData()
	{
		
        $this->InitMailer();

		$this->ComposeMessage($this->mail_subject,
					$this->mail_template);

		
        if($this->attach_files && NULL != $this->file_upload )
        {
            $this->file_upload->AttachFiles($this->mailer);
        }

        $from_merge = new FM_PageMerger();
        $from_merge->Merge($this->mail_from,$this->formvars);
        $this->mail_from = $from_merge->getMessageBody();
					
		foreach($this->dest_list as $dest_obj)
		{
            $to_address = $dest_obj['to'];
            $condns = $dest_obj['condn'];
            
            if(!empty($condns) && false === sfm_validate_multi_conditions($condns,$this->formvars))
            {
                $this->logger->LogInfo("Condition failed. Skipping email- to:$to_address  condn:$condns");
                continue;
            }
            
            if(!$this->ext_module->BeforeSendingFormSubmissionEMail($to_address,
                        $this->message_subject,$this->message_body))
            {
                $this->logger->LogInfo("Extension module prevented sending email to: $to_address");
                continue;
            }
            $this->logger->LogInfo("sending form data to: $to_address");
            $this->SendMail($this->mail_from,
					$to_address);
		}		
	}
    
    function ValidateInstallation(&$app_command_obj)
    {
        if(!$app_command_obj->IsEmailTested())
        {
            return $app_command_obj->TestSMTPEmail();
        }
        return true;
    }
    
    function Process(&$continue)
    {
		
        if(strlen($this->mail_template)<=0||
           count($this->dest_list)<=0)
        {
            return false;
        }
        $continue = true;
        $this->SendFormData();
        return true;
    }
}

class FM_ThankYouPage extends FM_Module
{
    private $page_templ;
    private $redir_url;

    public function __construct($page_templ="")
    {
        parent::__construct();
        $this->page_templ=$page_templ;
        $this->redir_url="";
    }
    
    function Process(&$continue)
    {
      $ret = true;
      if(false === $this->ext_module->FormSubmitted($this->formvars))
      {
         $this->logger->LogInfo("Extension Module returns false for FormSubmitted() notification");
         $ret = false;
      }
      else
      {
        $ret = $this->ShowThankYouPage();
      }
  
      if($ret)
      {
         $this->globaldata->SetFormProcessed(true);
      }
      
      return $ret;
    }
    
    function ShowThankYouPage($params='')
    {
      $ret = false;
      if(strlen($this->page_templ)>0)
      {
         $this->logger->LogInfo("Displaying thank you page");
         $ret = $this->ShowPage();
      }
      else
      if(strlen($this->redir_url)>0)
      {
         $this->logger->LogInfo("Redirecting to thank you URL");
         $ret = $this->Redirect($this->redir_url,$params);
      }    
      return $ret;
    }
    
    function SetRedirURL($url)
    {
        $this->redir_url=$url;
    }

    function ShowPage()
    {
        header("Content-Type: text/html");
        echo $this->ComposeContent($this->page_templ);
        return true;
    }
    
    function ComposeContent($content,$urlencode=false)
    {
        $merge = new FM_PageMerger();
        $html_conv = $urlencode?false:true;
        $tmpdatamap = $this->common_objs->formvar_mx->CreateFieldMatrix($html_conv);

        if($urlencode)
        {
            foreach($tmpdatamap as $name => $value)
            {
                $tmpdatamap[$name] = urlencode($value);
            }
        }
        
        $this->ext_module->BeforeThankYouPageDisplay($tmpdatamap);

        if(false == $merge->Merge($content,$tmpdatamap))
        {
            $this->logger->LogError("ThankYouPage: merge failed");
            return '';
        }

        return $merge->getMessageBody();
    }
    
    function Redirect($url,$params)
    {
        $has_variables = (FALSE === strpos($url,'?'))?false:true;
        
        if($has_variables)
        {
            $url = $this->ComposeContent($url,/*urlencode*/true);
            if(!empty($params))
            {
                $url .= '&'.$params;
            }
        }
        else if(!empty($params))
        {
            $url .= '?'.$params;
            $has_variables=true;
        }
        
        $from_iframe = isset($this->globaldata->session['sfm_from_iframe']) ? 
                    intval($this->globaldata->session['sfm_from_iframe']):0;
        
        if( $has_variables  || $from_iframe )
        {
            $url = htmlentities($url,ENT_QUOTES,"UTF-8");
            //The code below is put in so that it works with iframe-embedded forms also
            //$script = "window.open(\"$url\",\"_top\");";
            //$noscript = "<a href=\"$url\" target=\"_top\">Submitted the form successfully. Click here to redirect</a>";

            $page = <<<EOD
<html>
<head>
 <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
<script language='JavaScript'>
function redirToURL()
{
    var url = document.getElementById('redirurl').href;
    window.open(url,'_top');
}
</script>
</head>
<body onload='redirToURL()'>
<a style='display:none' id='redirurl' href='$url' target='_top'>Redirect</a>
<noscript>
<a href='$url' target='_top'>Submitted the form successfully. Click here to redirect</a>
</noscript>
</body>
</html>
EOD;
            header('Content-Type: text/html; charset=utf-8');
            echo $page;
        }
        else
        {
            header("Location: $url");
        }
        return true;
    }
}//FM_ThankYouPage

define("CONST_PHP_TAG_START","<"."?"."PHP");

///////Global Functions///////
function sfm_redirect_to($url)
{
	header("Location: $url");
}
function sfm_make_path($part1,$part2)
{
    $part1 = rtrim($part1,"/\\");
    $ret_path = $part1."/".$part2;
    return $ret_path;
}
function magicQuotesRemove(&$array) 
{
   if(version_compare(PHP_VERSION, '5.4', '>'))
   {
       return;
   }
   //Code below is for PHP <= 5.4 only
   
   if(!get_magic_quotes_gpc())
   {
       return;
   }
   foreach($array as $key => $elem) 
   {
      if(is_array($elem))
      {
           magicQuotesRemove($elem);
      }
      else
      {
           $array[$key] = stripslashes($elem);
      }//else
   }//foreach
}

function CreateHiddenInput($name, $objvalue)
{
    $objvalue = htmlentities($objvalue,ENT_QUOTES,"UTF-8");
    $str_ret = " <input type='hidden' name='$name' value='$objvalue'>";
    return $str_ret;
}

function sfm_get_disp_variable($var_name)
{
    return 'sfm_'.$var_name.'_disp';
}
function convert_html_entities_in_formdata($skip_var,&$datamap,$br=true)
{
    foreach($datamap as $name => $value)
    {
        if(strlen($skip_var)>0 && strcmp($name,$skip_var)==0)
        {
            continue;
        }
        if(true == is_string($datamap[$name]))
        {
          if($br)
          {
            $datamap[$name] = nl2br(htmlentities($datamap[$name],ENT_QUOTES,"UTF-8"));
          }
          else
          {
            $datamap[$name] = htmlentities($datamap[$name],ENT_QUOTES,"UTF-8");
          }
        }
    }//foreach
}

function sfm_get_mime_type($filename) 
{
   $mime_types = array(
            'txt' => 'text/plain',
            'htm' => 'text/html',
            'html' => 'text/html',
            'php' => 'text/plain',
            'css' => 'text/css',
            'swf' => 'application/x-shockwave-flash',
            'flv' => 'video/x-flv',

            // images
            'png' => 'image/png',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'ico' => 'image/vnd.microsoft.icon',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'svgz' => 'image/svg+xml',

            // archives
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            'exe' => 'application/x-msdownload',
            'msi' => 'application/x-msdownload',
            'cab' => 'application/vnd.ms-cab-compressed',

            // audio/video
            'mp3' => 'audio/mpeg',
            'qt' => 'video/quicktime',
            'mov' => 'video/quicktime',

            // adobe
            'pdf' => 'application/pdf',
            'psd' => 'image/vnd.adobe.photoshop',
            'ai' => 'application/postscript',
            'eps' => 'application/postscript',
            'ps' => 'application/postscript',

            // ms office
            'doc' => 'application/msword',
            'rtf' => 'application/rtf',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',

            // open office
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        );

    $ext = sfm_getfile_extension($filename);
    
    if (array_key_exists($ext, $mime_types)) 
    {
        return $mime_types[$ext];
    }
    elseif(function_exists('finfo_open')) 
    {
        $finfo = finfo_open(FILEINFO_MIME);
        $mimetype = finfo_file($finfo, $filename);
        finfo_close($finfo);
        return $mimetype;
    }
    else 
    {
        return 'application/octet-stream';
    }
}
    
function array_push_ref(&$target,&$value_array)
{
    if(!is_array($target))
    {
        return FALSE;
    }
    $target[]=&$value_array;
    return TRUE;
}

function sfm_checkConfigFileSign($conf_content,$strsign)
{
    $conf_content = substr($conf_content,strlen(CONST_PHP_TAG_START)+1);
    $conf_content = ltrim($conf_content); 

    if(0 == strncmp($conf_content,$strsign,strlen($strsign)))
    {
        return true;
    }
    return false;
}

function sfm_readfile($filepath)
{
    $retString = file_get_contents($filepath);
    return $retString;
}

function sfm_csv_escape($value)
{
    if(preg_match("/[\n\"\,\r]/i",$value))
    {
        $value = str_replace("\"","\"\"",$value);
        $value = "\"$value\"";
    }    
    return $value;
}

function sfm_crypt_decrypt($in_str,$key)
{
    $blowfish =& Crypt_Blowfish::factory('ecb');
    $blowfish->setKey($key);
    
    $bin_data = pack("H*",$in_str);
    $decr_str = $blowfish->decrypt($bin_data);
    if(PEAR::isError($decr_str))
    {
        return "";
    }
    $decr_str = trim($decr_str);
    return $decr_str;
}

function sfm_crypt_encrypt($str,$key)
{
    $blowfish =& Crypt_Blowfish::factory('ecb');
    $blowfish->setKey($key);

    $encr = $blowfish->encrypt($str);
    $retdata = bin2hex($encr);
    return $retdata;
}
function sfm_selfURL_abs()
{
    $s = '';
    if(!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == 'on')
    {
        $s='s';
    }
     
    $protocol = 'http'.$s;
    $port = ($_SERVER["SERVER_PORT"] == '80') ? '' : (':'.$_SERVER["SERVER_PORT"]);
    return $protocol."://".$_SERVER['HTTP_HOST'].$port.$_SERVER['PHP_SELF'];
}
function strleft($s1, $s2) 
{ 
    return substr($s1, 0, strpos($s1, $s2)); 
}
function sfm_getfile_extension($path)
{
    $info = pathinfo($path);
    $ext='';
    if(isset($info['extension']))
    {
        $ext = strtolower($info['extension']);
    }
    return $ext;
}

function sfm_filename_no_ext($fullpath)
{
    $filename = basename($fullpath);

    $pos = strrpos($filename, '.');
    if ($pos === false)
    { // dot is not found in the filename
        return $filename; // no extension
    }
    else
    {
        $justfilename = substr($filename, 0, $pos);
        return $justfilename;
    }
}

function sfm_validate_multi_conditions($condns,$formvariables)
{
   $arr_condns = preg_split("/(\s*\&\&\s*)|(\s*\|\|\s*)/", $condns, -1, 
                     PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
   
   $conn = '';
   $ret = false;
   
   foreach($arr_condns as $condn) 
   {
      $condn = trim($condn);
      if($condn == '&&' || $condn == '||')
      {
         $conn = $condn;
      }
      else
      {
         $res = sfm_validate_condition($condn,$formvariables);
         
         if(empty($conn))
         {
            $ret = $res ;
         }
         elseif($conn =='&&')
         {
           $ret = $ret && $res;
         }
         elseif($conn =='||')
         {
            $ret = $ret || $res;
         }
      }//else
   }
   return $ret ;
}

function sfm_compare_ip($ipcompare, $currentip)
{
   $arr_compare = explode('.',$ipcompare);
   $arr_current = explode('.',$currentip);

   $N = count($arr_compare); 
   
   for($i=0;$i<$N;$i++)
   {
      $piece1 = trim($arr_compare[$i]);
      
      if($piece1 == '*')
      {
       continue;
      }
      if(!isset($arr_current[$i]))
      {
         return false;
      }
      
      $piece2 = trim($arr_current[$i]);
      
      if($piece1 != $piece2)
      {
         return false;
      }
   }
   return true;
   
}

function sfm_validate_condition($condn,$formvariables)
{
  if(!preg_match("/([a-z_A-Z]*)\(([a-zA-Z0-9_]*),\"(.*)\"\)/",$condn,$res))
  {
      return false;
  }
  $type = strtolower(trim($res[1]));
  $arg1 = trim($res[2]);
  $arg2 = trim($res[3]);
  $bret=false;

  switch($type)
  {
      case "is_selected_radio":
      case "isequal":
      {
          if(isset($formvariables[$arg1]) &&
            strcasecmp($formvariables[$arg1],$arg2)==0 )
          {
              $bret=true;
          }
          break;
      }//case
      case "ischecked_single":
      {
          if(!empty($formvariables[$arg1]))
          {
              $bret=true;
          }
          break;
      }
      case "contains":
      {
          if(isset($formvariables[$arg1]) &&
            stristr($formvariables[$arg1],$arg2) !== FALSE )
          {
              $bret=true;
          }                
          break;
      }
      case "greaterthan":
      {
          if(isset($formvariables[$arg1]) &&
            floatval($formvariables[$arg1]) > floatval($arg2))
          {
              $bret=true;
          }                
          break;
      }
      case "lessthan":
      {
          if(isset($formvariables[$arg1]) &&
            floatval($formvariables[$arg1]) < floatval($arg2))
          {
              $bret=true;
          }                
          break;                
      }
      case "is_not_checked_single":
      {
          if(empty($formvariables[$arg1]) )
          {
              $bret=true;
          }
          break;
      }
      case "is_not_selected_radio":
      {
          if(!isset($formvariables[$arg1]) ||
            strcasecmp($formvariables[$arg1],$arg2) !=0 )
          {
              $bret=true;
          }
          break;
      }
      case "is_selected_list_item":
      case "is_checked_group":
      {
          if(isset($formvariables[$arg1]))
          {
              if(is_array($formvariables[$arg1]))
              {
                  foreach($formvariables[$arg1] as $chk)
                  {
                      if(strcasecmp($chk,$arg2)==0)
                      {
                          $bret=true;break;
                      }
                  }//foreach
              }
              else
              {
                  if(strcasecmp($formvariables[$arg1],$arg2)==0)
                  {
                      $bret=true;break;
                  }                        
              }//else
          }
          break;
      }//case]
  case "is_not_selected_list_item":
  case "is_not_checked_group":
      {
          $bret=true;
          if(isset($formvariables[$arg1]))
          {
              if(is_array($formvariables[$arg1]))
              {
                  foreach($formvariables[$arg1] as $chk)
                  {
                      if(strcasecmp($chk,$arg2)==0)
                      {
                          $bret=false;break;
                      }
                  }//foreach
              }
              else
              {
                  if(strcasecmp($formvariables[$arg1],$arg2)==0)
                  {
                      $bret=false;break;
                  }                        
              }//else
          }
          break;
      }//case
      case 'is_empty':
      {
          if(!isset($formvariables[$arg1]))
          {
              $bret=true;
          }
          else
          {
              $tmp_arg=trim($formvariables[$arg1]);
              if(empty($tmp_arg))
              {
                  $bret=true;
              }
          }
          break;
      }
      case 'is_not_empty':
      {
          if(isset($formvariables[$arg1]))
          {
              $tmp_arg=trim($formvariables[$arg1]);
              if(!empty($tmp_arg))
              {
                  $bret=true;
              }                    
          }
          break;
      }

  }//switch

  return $bret;
}
if (!function_exists('_'))
{
    function _($s)
    {
        return $s;
    }
}

class FM_ElementInfo
{
   private $elements;
   public $default_values;

   public function __construct()
   {
      $this->elements = array();
      $this->default_values = array();
   }
   function AddElementInfo($name,$type,$extrainfo,$page)
   {
      $this->elements[$name]["type"] = $type;
      $this->elements[$name]["extra"] = $extrainfo;
      $this->elements[$name]["page"]= $page;
   }
   function AddDefaultValue($name,$value)
   {
      
      if(isset($this->default_values[$name]))
      {
         if(is_array($this->default_values[$name]))
         {
            array_push($this->default_values[$name],$value);
         }
         else
         {
             $curvalue = $this->default_values[$name];
             $this->default_values[$name] = array($curvalue,$value);
         }
      }
      else
      {
        $this->default_values[$name] = $this->doStringReplacements($value);
      }
   }
   
   function doStringReplacements($strIn)
   {
     return str_replace(array('\n'),array("\n"),$strIn);
   } 
   
   function IsElementPresent($name)
   {
      return isset($this->elements[$name]);
   }
   function GetType($name)
   {
      if($this->IsElementPresent($name) && 
        isset($this->elements[$name]["type"]))
        {
            return $this->elements[$name]["type"];
        }
        else
        {
            return '';
        }
   }
   
   function IsUsingDisplayVariable($name)
   {
     $type = $this->GetType($name);
     $ret = false;
     if($type == 'datepicker' ||
        $type == 'decimal' ||
        $type == 'calcfield')
     {
        $ret = true;
     }
     return $ret;
   }
   function GetExtraInfo($name)
   {
     return $this->elements[$name]["extra"];
   }
   
   function GetPageNum($name)
   {
      return $this->elements[$name]["page"];
   }
   
   function GetElements($page,$type='')
   {
        $ret_arr = array();
        foreach($this->elements as $ename => $eprops)
        {
            if(($eprops['page'] == $page) && 
               (empty($type) || $type == $eprops['type']))
            {
                $ret_arr[$ename] = $eprops;
            }
        }
        return $ret_arr;
   }
   
   function GetAllElements()
   {
       return $this->elements;
   }
}

/////Config/////
class FM_Config
{
    public $formname;
    public $form_submit_variable; 
    public $form_page_code;
    public $error_display_variable;
    public $display_error_in_formpage;
    public $error_page_code;
    public $email_format_html;
    public $slashn;
    public $installed;
    public $log_flush_live;
    public $encr_key;
    public $form_id;
    public $sys_debug_mode;
    public $error_mail_to;
    public $use_smtp;
    public $smtp_host;
    public $smtp_uname;
    public $smtp_pwd;
    public $from_addr;
    public $variable_from;
    public $common_date_format;
    public $var_cur_form_page_num;
    public $var_form_page_count;
    public $var_page_progress_perc;
    public $element_info;
    public $print_preview_page;
    public $v4_email_headers;
    public $fmdb_host;
    public $fmdb_username;
    public $fmdb_pwd;
    public $fmdb_database;
    public $saved_message_templ;
	  public $default_timezone;
    public $enable_auto_field_table;
	public $rand_key;
    

//User configurable (through extension modules)  
    public  $form_file_folder;//location to save csv file, log file etc
    public  $load_values_from_url;
    public  $allow_nonsecure_file_attachments;
    public  $file_upload_folder;
    public  $debug_mode;    
    public  $logfile_size;   
    public  $bypass_spammer_validations;
    public  $passwords_encrypted;
	  public  $enable_p2p_header;
    public  $enable_session_id_url;
    public  $locale_name;
    public  $locale_dateformat;
    public  $array_disp_seperator;//used for imploding arrays before displaying
    
   public function __construct()
   {
      $this->form_file_folder="";
      $this->installed = false;

      $this->form_submit_variable   ="sfm_form_submitted";
      $this->form_page_code="<HTML><BODY><H1>Error! code 104</h1>%sfm_error_display_loc%</body></HTML>";
      $this->error_display_variable = "sfm_error_display_loc";
      $this->show_errors_single_box = false;
      $this->self_script_variable = "sfm_self_script";
      $this->form_filler_variable="sfm_form_filler_place";
      $this->confirm_file_list_var = "sfm_file_uploads";

      $this->config_update_var = "sfm_conf_update";

      $this->config_update_val = "sfm_conf_update_val";

      $this->config_form_id_var = "sfm_form_id";

      $this->visitor_ip_var = "_sfm_visitor_ip_";
	  
	  $this->unique_id_var = "_sfm_unique_id_";
      
      $this->form_page_session_id_var = "_sfm_form_page_session_id_";
      //identifies a particular display of the form page. refreshing the page
      // or opening a new browser tab creates a different id
	  
      $this->submission_time_var ="_sfm_form_submision_time_";

      $this->submission_date_var = "_sfm_form_submision_date_";

      $this->referer_page_var = "_sfm_referer_page_";

      $this->user_agent_var = "_sfm_user_agent_";

      $this->visitors_os_var = "_sfm_visitor_os_";

      $this->visitors_browser_var = "_sfm_visitor_browser_";
      
      $this->var_cur_form_page_num='sfm_current_page';
      
      $this->var_form_page_count = 'sfm_page_count';
      
      $this->var_page_progress_perc = 'sfm_page_progress_perc';
      
      $this->form_id_input_var = '_sfm_form_id_iput_var_';
      
      $this->form_id_input_value = '_sfm_form_id_iput_value_';

      $this->display_error_in_formpage=true;
      $this->error_page_code  ="<HTML><BODY><H1>Error!</h1>%sfm_error_display_loc%</body></HTML>";
      $this->email_format_html=false;
      $this->slashn = "\r\n";
      $this->saved_message_templ = "Saved Successfully. {link}";
      $this->reload_formvars_var="rd";
      
      $this->log_flush_live=false;

      $this->encr_key="";
      $this->form_id="";
      $this->error_mail_to="";
      $this->sys_debug_mode = false;
      $this->debug_mode = false;
      $this->element_info = new FM_ElementInfo();

      $this->use_smtp = false;
      $this->smtp_host='';
      $this->smtp_uname='';
      $this->smtp_pwd='';
      $this->smtp_port='';
      $this->from_addr='';
      $this->variable_from=false;
      $this->v4_email_headers=true;
      $this->common_date_format = 'Y-m-d';
      $this->load_values_from_url = false;
      $this->rand_key='';
      
      $this->hidden_input_trap_var='';
      
      $this->allow_nonsecure_file_attachments = false;
      
      $this->bypass_spammer_validations=false;
      
      $this->passwords_encrypted=true;
	    $this->enable_p2p_header = true;
      $this->enable_session_id_url=true;
      
	    $this->default_timezone = 'default';
      
      $this->array_disp_seperator ="\n";
      
      $this->enable_auto_field_table=false;
   }
    
   function set_encrkey($key)
   {
     $this->encr_key=$key;
   }
    
   function set_form_id($form_id)
   {
     $this->form_id = $form_id;
   }
    function set_rand_key($key)
    {
        $this->rand_key=$key;
    }
   function set_error_email($email)
   {
      $this->error_mail_to = $email;
   }

   function get_form_id()
   {
     return $this->form_id;
   }

   function setFormPage($formpage)
   {
      $this->form_page_code = $formpage;
   }

   function setDebugMode($enable)
   {
      $this->debug_mode = $enable;
      $this->log_flush_live = $enable?true:false;
   }

   function getCommonDateTimeFormat()
   {
     return $this->common_date_format." H:i:s T(O \G\M\T)";
   }

   function getFormConfigIncludeFileName($script_path,$formname)
   {
     $dir_name = dirname($script_path);

     $conf_file = $dir_name."/".$formname."_conf_inc.php";

     return $conf_file;
   }

   function getConfigIncludeSign()
   {
     return "//{__Simfatic Forms Config File__}";
   }
   
   function get_uploadfiles_folder()
    {
        $upload_folder = '';
        if(!empty($this->file_upload_folder))
        {
            $upload_folder = $this->file_upload_folder;
        }
        else
        {
            $upload_folder = sfm_make_path($this->getFormDataFolder(),"uploads_".$this->formname);
        }
        return $upload_folder;
    }
    function getFormDataFolder()
    {
      return $this->form_file_folder;
    }
   function InitSMTP($host,$uname,$pwd,$port)
   {
     $this->use_smtp = true;
     $this->smtp_host=$host;
     $this->smtp_uname=$uname;
     $this->smtp_pwd=$pwd;
     $this->smtp_port = $port;
   }
   
   function SetPrintPreviewPage($page)
   {
      $this->print_preview_page = $page;
   }
   function GetPrintPreviewPage()
   {
      return $this->print_preview_page;
   }
   
   function setFormDBLogin($host,$uname,$pwd,$database)
   {
    $this->fmdb_host = $host;
    $this->fmdb_username = $uname;
    $this->fmdb_pwd = $pwd;
    $this->fmdb_database = $database;
   }
   function  IsDBSupportRequired()
   {
        if(!empty($this->fmdb_host) && !empty($this->fmdb_username))
        {
            return true;
        }
        return false;
   }
   
   function IsSMTP()
   {
        return $this->use_smtp;
   }
   function GetPreParsedVar($varname)
   {
        return 'sfm_'.$varname.'_parsed';
   }
   function GetDispVar($varname)
   {
        return 'sfm_'.$varname.'_disp';
   }
   function SetLocale($locale_name,$date_format)
   {
        $this->locale_name = $locale_name;
        $this->locale_dateformat = $date_format;
        //TODO: use setLocale($locale_name) or locale_set_default
        //also, use strftime instead of date()
        $this->common_date_format = $this->toPHPDateFormat($date_format);
   }
   
    function toPHPDateFormat($stdDateFormat)
    {
        $map = array(
        'd'=>'j',
        'dd'=>'d',
        'ddd'=>'D',
        'dddd'=>'l',
        'M'=>'n',
        'MM'=>'m',
        'MMM'=>'M',
        'MMMM'=>'F',
        'yy'=>'y',
        'yyyy'=>'Y',
        'm'=>'i',
        'mm'=>'i',
        'h'=>'g',
        'hh'=>'h',
        'H'=>'H',
        'HH'=>'G',
        's'=>'s',
        'ss'=>'s',
        't'=>'A',
        'tt'=>'A'
        );
        
        if(empty($stdDateFormat))
        {
            return 'Y-m-d';
        }
        
        $arr_ret = preg_split('/([^\w]+)/i', $stdDateFormat,-1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

        foreach($arr_ret as $k=>$v)
        {
            if(isset($map[$v]))
            {
                $arr_ret[$k] = $map[$v];
            }
        }
        $php_format = implode($arr_ret); 
        return $php_format;
    }   
}


/* By Grant Burton @ BURTONTECH.COM (11-30-2008): IP-Proxy-Cluster Fix */
function checkIP($ip) 
{
   if (!empty($ip) && ip2long($ip)!=-1 && ip2long($ip)!=false) 
   {
       $private_ips = array (
       array('0.0.0.0','2.255.255.255'),
       array('10.0.0.0','10.255.255.255'),
       array('127.0.0.0','127.255.255.255'),
       array('169.254.0.0','169.254.255.255'),
       array('172.16.0.0','172.31.255.255'),
       array('192.0.2.0','192.0.2.255'),
       array('192.168.0.0','192.168.255.255'),
       array('255.255.255.0','255.255.255.255')
       );

       foreach ($private_ips as $r) 
       {
           $min = ip2long($r[0]);
           $max = ip2long($r[1]);
           if ((ip2long($ip) >= $min) && (ip2long($ip) <= $max)) return false;
       }
       return true;
   }
   else 
   { 
       return false;
   }
}

function determineIP() 
{
   if(isset($_SERVER["HTTP_CLIENT_IP"]) && checkIP($_SERVER["HTTP_CLIENT_IP"])) 
   {
       return $_SERVER["HTTP_CLIENT_IP"];
   }
   if(isset($_SERVER["HTTP_X_FORWARDED_FOR"]))
   {
       foreach (explode(",",$_SERVER["HTTP_X_FORWARDED_FOR"]) as $ip) 
       {
           if (checkIP(trim($ip))) 
           {
               return $ip;
           }
       }
   }
   
   if(isset($_SERVER["HTTP_X_FORWARDED"]) && checkIP($_SERVER["HTTP_X_FORWARDED"])) 
   {
       return $_SERVER["HTTP_X_FORWARDED"];
   } 
   elseif(isset($_SERVER["HTTP_X_CLUSTER_CLIENT_IP"]) && checkIP($_SERVER["HTTP_X_CLUSTER_CLIENT_IP"])) 
   {
       return $_SERVER["HTTP_X_CLUSTER_CLIENT_IP"];
   } 
   elseif(isset($_SERVER["HTTP_FORWARDED_FOR"]) && checkIP($_SERVER["HTTP_FORWARDED_FOR"])) 
   {
       return $_SERVER["HTTP_FORWARDED_FOR"];
   } 
   elseif(isset($_SERVER["HTTP_FORWARDED"]) && checkIP($_SERVER["HTTP_FORWARDED"])) 
   {
       return $_SERVER["HTTP_FORWARDED"];
   } 
   else 
   {
       return $_SERVER["REMOTE_ADDR"];
   }
}

//////GlobalData//////////
class FM_GlobalData
{
   public $get_vars;
   public $post_vars;
   public $server_vars;
   public $files;
   public $formvars;
   public $saved_data_varname;
   public $config;
   public $form_page_submitted;//means a submit button is pressed; need not be the last (final)submission
   public $form_processed;
   public $session;
   

   public function __construct(&$config)
   {
      $this->get_vars   =NULL;
      $this->post_vars =NULL;    
      $this->server_vars   =NULL;
      $this->files=NULL;
      $this->formvars=NULL;
      $this->saved_data_varname="sfm_saved_formdata_var";
      $this->config = &$config;
      $this->form_processed = false;
      $this->form_page_submitted = false;
      $this->form_page_num=-1;
      $this->LoadServerVars();
   }
   
   function LoadServerVars()
   {
        global $HTTP_GET_VARS, $HTTP_POST_VARS, $HTTP_SERVER_VARS,$HTTP_POST_FILES;
        $parser_version = phpversion();
        if ($parser_version <= "4.1.0") 
        {
            $this->get_vars   = $HTTP_GET_VARS;
            $this->post_vars  = $HTTP_POST_VARS;
            $this->server_vars= $HTTP_SERVER_VARS;
            $this->files = $HTTP_POST_FILES;
        }
        if ($parser_version >= "4.1.0")
        {
            $this->get_vars    = $_GET;
            $this->post_vars   = $_POST;
            $this->server_vars= $_SERVER;
            $this->files = $_FILES;
        }   
        $this->server_vars['REMOTE_ADDR'] = determineIP();        
   }
   
   function GetGlobalVars() 
   {
		if($this->is_submission($this->post_vars))
		{
			$this->formvars = $this->get_post_vars();
            $this->form_page_submitted = true;
		}
		elseif($this->is_submission($this->get_vars))
		{
			$this->formvars = $this->get_get_vars();
            $this->form_page_submitted = true;
		}
		else
		{
            $this->form_page_submitted = false;
			$this->formvars = array();
		}
        magicQuotesRemove($this->formvars);
        
        if($this->form_page_submitted)
        {
            $this->CollectInternalVars();
            $this->NormalizeFormVars();
        }

        if(isset($this->formvars[$this->saved_data_varname]))
        {
            $this->LoadFormDataFromSession();
        }
           
        $this->formvars[$this->config->visitor_ip_var] = 
                        $this->server_vars['REMOTE_ADDR'];
						
		$visitor_unique_id = $this->get_unique_id();
        $this->formvars[$this->config->unique_id_var]= $visitor_unique_id; 
        $this->formvars[$this->config->form_page_session_id_var] = md5($visitor_unique_id.uniqid(''));

        $this->formvars[$this->config->submission_time_var]= 
                        date($this->config->getCommonDateTimeFormat());

        $this->formvars[$this->config->submission_date_var] = date($this->config->common_date_format);

        $this->formvars[$this->config->referer_page_var] =  $this->get_form_referer();

        $ua ='';
        if(!empty($this->server_vars['HTTP_USER_AGENT']))
        {
            $ua = $this->server_vars['HTTP_USER_AGENT'];
        }
        else
        {
            $this->server_vars['HTTP_USER_AGENT']='';
        }

        $this->formvars[$this->config->user_agent_var] = $ua;

        $this->formvars[$this->config->visitors_os_var] = $this->DetectOS($ua);

        $this->formvars[$this->config->visitors_browser_var] = $this->DetectBrowser($ua);
   }
   
   function GetCurrentPageNum()
   {
      $page_num = 0;
      if($this->form_page_num >= 0)
      {
         $page_num = $this->form_page_num;
      }   
      return $page_num;
   }
    function NormalizeFormVarsBeforePageDisplay(&$var_map,$page_num)
    {
         $arr_elements = 
            $this->config->element_info->GetElements($page_num);
            
         foreach($arr_elements as $ename => $e)
         {
            $disp_var = $this->config->GetDispVar($ename);
            if(!empty($var_map[$disp_var]))
            {
                $var_map[$ename] = $var_map[$disp_var];
            }
         }    
    }
    
    function CollectInternalVars()
    {
        /*
        TODO: N9UVSWkdQeZF
        Collect & move all internal variables here.
        This way, it won't mess up the formvars vector
        To Do Add:
        sfm_prev_page
        sfm_save_n_close
        sfm_prev_page
        sfm_confirm_edit
        sfm_confirm
        config->form_submit_variable
        sfm_saved_formdata_var
        */
        if(isset($this->formvars['sfm_form_page_num']) && 
        is_numeric($this->formvars['sfm_form_page_num']))
        {
            $this->form_page_num = intval($this->formvars['sfm_form_page_num']);
            unset($this->formvars['sfm_form_page_num']);
        }
    }
    
    function NormalizeFormVars()
    {
     //for boolean inputs like checkbox, the absense of 
     //the element means false. Explicitely setting this false here
     //to help in later form value processing
         $arr_elements = 
            $this->config->element_info->GetElements($this->GetCurrentPageNum());
            
         foreach($arr_elements as $ename => $e)
         {
            $preparsed_var = $this->config->GetPreParsedVar($ename);
            if(isset($this->formvars[$preparsed_var]))
            {
                $disp_var = $this->config->GetDispVar($ename);
                $this->formvars[$disp_var] = $this->formvars[$ename];
                $this->formvars[$ename] = $this->formvars[$preparsed_var];
            }
            if(isset($this->formvars[$ename])){continue;}
            
            switch($e['type'])
            {
                case 'single_chk':
                {
                    $this->formvars[$ename] = false;
                    break;
                }
                case 'chk_group':
                case 'multiselect':
                {
                    $this->formvars[$ename] = array();
                    break;
                }
                default:
                {
                    $this->formvars[$ename]='';
                }
            }
         }
    }
    
	function is_submission($var_array)
	{
		if(empty($var_array)){ return false;}
		
		if(isset($var_array[$this->config->form_submit_variable])//full submission
			|| isset($var_array['sfm_form_page_num']))//partial- page submission
		{
			return true;
		}
		return false;
	}
    
    function RecordVariables()
    {
     if(!empty($this->get_vars['sfm_from_iframe']))
     {
         $this->session['sfm_from_iframe']= $this->get_vars['sfm_from_iframe'];
     }
     
     $this->session['sfm_referer_page'] = $this->get_referer();
    }
    
    function GetVisitorUniqueKey()
    {
      $seed = $this->config->get_form_id().
               $this->server_vars['SERVER_NAME'].
               $this->server_vars['REMOTE_ADDR'].
               $this->server_vars['HTTP_USER_AGENT'];
      return md5($seed);
    }
    function get_unique_id()
	{
	    if(empty($this->session['sfm_unique_id']))
        {
			$this->session['sfm_unique_id'] = 
				md5($this->GetVisitorUniqueKey().uniqid('',true));
		}
		return  $this->session['sfm_unique_id'];
	}
    function get_form_referer()
    {
        if(isset($this->session['sfm_referer_page']))
        {
           return  $this->session['sfm_referer_page'];
        }
        else
        {
            return $this->get_referer();
        }
    }
    function InitSession()
    {
        $id=$this->config->get_form_id();
        if(!isset($_SESSION[$id]))
        {
            $_SESSION[$id]=array();
        }
        $this->session = &$_SESSION[$id];
    }
    
    function DestroySession()
    {
        $id=$this->config->get_form_id();
        unset($_SESSION[$id]);
    } 
    
    function RemoveSessionValue($name)
    {
        unset($_SESSION[$this->config->get_form_id()][$name]);
    }
    
    function RecreateSessionValues($arr_session)
    {
      foreach($arr_session as $varname => $values)
      {
         $this->session[$varname] = $values;
      }        
    }
    function SetFormVar($name,$value)
    {
        $this->formvars[$name] = $value;
    }
    
    function LoadFormDataFromSession()
    {
        $varname = $this->formvars[$this->saved_data_varname];

         if(isset($this->session[$varname]))
         {
            $this->formvars = 
               array_merge($this->formvars,$this->session[$varname]);

            unset($this->session[$varname]);
            unset($this->session[$this->saved_data_varname]);
         }
    }

    function SaveFormDataToSession()
    {
        $varname = "sfm_form_var_".rand(1,1000)."_".rand(2,2000);

        $this->session[$varname] = $this->formvars;

        unset($this->session[$varname][$this->config->form_submit_variable]);

        return $varname;
    }
    
    function get_post_vars()
    {
        return $this->post_vars;
    }
    function get_get_vars()
    {
        return $this->get_vars;
    }

    function get_php_self() 
    {
        $from_iframe = isset($this->session['sfm_from_iframe']) ?  intval($this->session['sfm_from_iframe']):0;
        $sid=0;
        if($from_iframe)
        {
            $sid =  session_id();
        }
        else
        {
            if(empty($this->session['sfm_rand_sid']))
            {
                $this->session['sfm_rand_sid'] = rand(1,9999);
            }
            $sid = $this->session['sfm_rand_sid'];
        }
        $url = $this->server_vars['PHP_SELF']."?sfm_sid=$sid";
        return $url;
    }

    function get_referer()
    {
        if(isset($this->server_vars['HTTP_REFERER']))
        {
            return $this->server_vars['HTTP_REFERER'];
        }
        else
        {
            return '';
        }
    }
    
    function SetFormProcessed($processed)
    {
      $this->form_processed = $processed;
    }
    
    function IsFormProcessingComplete()
    {
      return $this->form_processed;
    }
    
    function IsButtonClicked($button_name)
    {
        if(isset($this->formvars[$button_name]))
        {
            return true;
        }
        if(isset($this->formvars[$button_name."_x"])||
           isset($this->formvars[$button_name."_y"]))
        {
            if($this->formvars[$button_name."_x"] == 0 &&
            $this->formvars[$button_name."_y"] == 0)
            {//Chrome & safari bug
                return false;
            }
         return true;
        }
      return false;
    }
    function ResetButtonValue($button_name)
    {
        unset($this->formvars[$button_name]);
        unset($this->formvars[$button_name."_x"]);
        unset($this->formvars[$button_name."_y"]);
    }

    function DetectOS($user_agent)
    {
        //code by Andrew Pociu
        $OSList = array
        (
            'Windows 3.11' => 'Win16',

            'Windows 95' => '(Windows 95)|(Win95)|(Windows_95)',

            'Windows 98' => '(Windows 98)|(Win98)',

            'Windows 2000' => '(Windows NT 5\.0)|(Windows 2000)',

            'Windows XP' => '(Windows NT 5\.1)|(Windows XP)',

            'Windows Server 2003' => '(Windows NT 5\.2)',

            'Windows Vista' => '(Windows NT 6\.0)',

            'Windows 7' => '(Windows NT 7\.0)|(Windows NT 6\.1)',
            
            'Windows 8' => '(Windows NT 6\.2)',

            'Windows NT 4.0' => '(Windows NT 4\.0)|(WinNT4\.0)|(WinNT)|(Windows NT)',

            'Windows ME' => '(Windows 98)|(Win 9x 4\.90)|(Windows ME)',

            'Open BSD' => 'OpenBSD',

            'Sun OS' => 'SunOS',

            'Linux' => '(Linux)|(X11)',

            'Mac OS' => '(Mac_PowerPC)|(Macintosh)',

            'QNX' => 'QNX',

            'BeOS' => 'BeOS',

            'OS/2' => 'OS/2',

            'Search Bot'=>'(nuhk)|(Googlebot)|(Yammybot)|(Openbot)|(Slurp)|(MSNBot)|(Ask Jeeves/Teoma)|(ia_archiver)'
        );

        foreach($OSList as $CurrOS=>$Match)
        {
            if (preg_match("#$Match#i", $user_agent))
            {
                break;
            }
        }

        return $CurrOS;        
    }


    function DetectBrowser($agent) 
    {
        $ret ="";
        $browsers = array("firefox", "msie", "opera", "chrome", "safari",
                            "mozilla", "seamonkey",    "konqueror", "netscape",
                            "gecko", "navigator", "mosaic", "lynx", "amaya",
                            "omniweb", "avant", "camino", "flock", "aol");

        $agent = strtolower($agent);
        foreach($browsers as $browser)
        {
            if (preg_match("#($browser)[/ ]?([0-9.]*)#", $agent, $match))
            {
                $br = $match[1];
                $ver = $match[2];
                if($br =='safari' && preg_match("#version[/ ]?([0-9.]*)#", $agent, $match))
                {
                    $ver = $match[1];
                }
                $ret = ($br=='msie')?'Internet Explorer':ucfirst($br);
                $ret .= " ". $ver;
                break ;
            }
        }
        return $ret;
    }

}

/////Logger/////
class FM_Logger
{
   private $config;
   private $log_file_path;
   private $formname;
   private $log_filename;
   private $whole_log;
   private $is_enabled;
   private $logfile_size;
   private $msg_log_enabled;
   private $log_source;
   

   public function __construct(&$config,$formname)
   {
      $this->config = &$config;
      $this->formname = $formname;
      $this->log_filename="";
      $this->whole_log="";
      $this->is_enabled = false;
      $this->log_flushed = false;
      $this->logfile_size=100;//In KBs
      $this->msg_log_enabled = true;
      $this->log_source = '';
   }   
   
   function EnableLogging($enable)
   {
      $this->is_enabled = $enable;
   }
   function SetLogSource($logSource)
   {
    $this->log_source = $logSource;
   }
   function CreateFileName()
   {
     $ret=false;
     $filename ="";
     if(strlen($this->log_filename)> 0)
     {
         $filename = $this->log_filename;
     }
     else
     if(strlen($this->config->get_form_id())>0)
     {
         $form_id_part = substr($this->config->get_form_id(),0,8);

         $filename = $this->formname.'-'.$form_id_part.'-log.php';
     }
     else
     {
         return false;
     }

     if(strlen($this->config->form_file_folder)>0)
     {
         $this->log_file_path = sfm_make_path($this->config->form_file_folder,
                                     $filename);
         $ret = true;
     }
     else
     {
         $this->log_file_path ="";
         $ret=false;
     }
     return $ret;
   }
   
   function LogString($string,$type)
   {
      $bret = false;
      $t_log = "\n";
      $t_log .= $_SERVER['REMOTE_ADDR']."|";

      $t_log .= date("Y-m-d h:i:s A|");
      $t_log .= $this->log_source.'|';
      $t_log .= "$type| ";
      $string = str_replace("\n","\\n",$string);      
      $t_log .= $string;

      if($this->is_enabled && $this->config->debug_mode)
      {
         $bret = $this->writeToFile($t_log);
      }

      $this->whole_log .= $t_log;
      return $bret;
   }

    function FlushLog()
    {
        if($this->is_enabled && 
        !$this->log_flushed &&
        !$this->config->debug_mode)
        {
            $this->writeToFile($this->get_log());
            $this->log_flushed = true;
        }
    }

    function print_log()
    {
        echo $this->whole_log;
    }

   function get_log()
   {
      return $this->whole_log;
   }

    function get_log_file_path()
    {
        if(strlen($this->log_file_path)<=0)
        {
            if(!$this->CreateFileName())
            {
                return "";
            }
        }
        return $this->log_file_path;
    }
   
    function writeToFile($t_log)
    {
        $this->get_log_file_path();

        if(strlen($this->log_file_path)<=0){ return false;}

        $fp =0;
        $create_file=false;

        if(file_exists($this->log_file_path))
        {
            $maxsize= $this->logfile_size * 1024;
            if(filesize($this->log_file_path) >= $maxsize)
             {
                $create_file = true;
             }
        }
        else
        {
           $create_file = true;
        }

        $ret = true;
        $file_maker = new SecureFileMaker($this->GetFileSignature());
        if(true == $create_file)
        {
            $ret = $file_maker->CreateFile($this->log_file_path,$t_log);
        }
        else
        {
            $ret = $file_maker->AppendLine($this->log_file_path,$t_log);
        }
      
      return $ret;
    }

    function GetFileSignature()
    {
        return "--Simfatic Forms Log File--";
    }

   function LogError($string)
   {
      return $this->LogString($string,"error");
   }
   
   function LogInfo($string)
   {
      if(false == $this->msg_log_enabled)     
      {
         return true;
      }
      return $this->LogString($string,"info");
   }
}

class FM_ErrorHandler
{
   private $logger;
   private $config;
   private $globaldata;
   private $formname;
   private $sys_error;
   private $formvars;
   private $common_objs;

   public $disable_syserror_handling;
   
    public function __construct(&$logger,&$config,&$globaldata,$formname,&$common_objs)
    {
      $this->logger = &$logger;
      $this->config = &$config;
      $this->globaldata = &$globaldata;
      $this->formname  = $formname;
      $this->sys_error="";
      $this->enable_error_formpagemerge=true;
      $this->common_objs = &$common_objs;
    }
   
   function SetFormVars(&$formvars)
   {
      $this->formvars = &$formvars;
   }

    function InstallConfigErrorCatch()
    {
        set_error_handler(array(&$this, 'sys_error_handler'));
    }

   function DisableErrorFormMerge()
   {
    $this->enable_error_formpagemerge = false;
   }
   
   function GetLastSysError()
   {
      return $this->sys_error;
   }

   function IsSysError()
   {
      if(strlen($this->sys_error)>0){return true;}
      else { return false;}
   }
   
   function GetSysError()
   {
      return $this->sys_error;
   }

   function sys_error_handler($errno, $errstr, $errfile, $errline)
   {
        if(defined('E_STRICT') && $errno == E_STRICT)
        {
            return true;
        }
        switch($errno)
        {
            case E_ERROR:
            case E_PARSE:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
            {
                $this->sys_error = "Error ($errno): $errstr\n file:$errfile\nline: $errline \n\n";

                if($this->disable_syserror_handling == true)
                {
                 return false;
                }
                $this->HandleConfigError($this->sys_error);
                exit;
                break;
            }
           default:
           {
                $this->logger->LogError("Error/Warning reported: $errstr\n file:$errfile\nline: $errline \n\n");
           }
        }
        return true;
   }
    
   function ShowError($error_code,$show_form=true)
   {
      if($show_form)
      {
         $this->DisplayError($error_code);
      }
      else
      {
         echo "<html><head>Error</head><body><h3>$error_code</h3></body></html>";
      }
   }
   function ShowErrorEx($error_code,$error_extra_info)
   {
      $error_extra_info = trim($error_extra_info);
      $this->DisplayError($error_code."\n".$error_extra_info);
   }
    function ShowInputError($error_hash,$formname)
    {
        $this->DisplayError("",$error_hash,$formname);
    }
    function NeedSeperateErrorPage($error_hash)
    {
        if(null == $error_hash)
        {
            if(false === strpos($this->config->form_page_code,
                $this->config->error_display_variable))
            {
                return true;
            }
        }

        return false;
    }

   function DisplayError($str_error,$error_hash=null,$formname="")
   {
      $str_error = trim($str_error);
      $this->logger->LogError($str_error);

      if(!$this->enable_error_formpagemerge)
      {
         $this->sys_error = $str_error;
         return;
      }        

      $str_error = nl2br($str_error);  

      $var_map = array(
                 $this->config->error_display_variable => $str_error
                 );

      
      
      if(null != $error_hash)
      {
         if($this->config->show_errors_single_box)
         {
             $this->CombineErrors($var_map,$error_hash);
         }   
         else
         {
             foreach($error_hash as $inpname => $inp_err)
             {
                 $err_var = $formname."_".$inpname."_errorloc";
                 $var_map[$err_var] = $inp_err;
             }
         }
      }
      
      
      if(!isset($this->common_objs->formpage_renderer))
      {
         $this->logger->LogError('Form page renderer not initialized');
      }
      else
      {
         $this->logger->LogInfo("Error display: Error map ".var_export($var_map,TRUE));
         $this->common_objs->formpage_renderer->DisplayCurrentPage($var_map);
      }

   }

    function CombineErrors(&$var_map,&$error_hash)
    {
        $error_str='';
        foreach($error_hash as $inpname => $inp_err)
        {
            $error_str .="\n<li>".$inp_err;
        }        

        if(!empty($error_str))
        {
            $error_str="\n<ul>".$error_str."\n</ul>";
        }

        $var_map[$this->config->error_display_variable]=
            $var_map[$this->config->error_display_variable].$error_str;

    }

   function EmailError($error_code)
   {
      $this->logger->LogInfo("Sending Error Email To: ".$this->config->error_mail_to);    
      $mailbody = sprintf(_("Error occured in form %s.\n\n%s\n\nLog:\n%s"),$this->formname,$error_code,$this->logger->get_log());
      $subj =  sprintf(_("Error occured in form %s."),$this->formname);

      $from = empty($this->config->from_addr) ? 'form.error@simfatic-forms.com' : $this->config->from_addr;
      $from = $this->formname.'<'.$from.'>';
      @mail($this->config->error_mail_to, $subj, $mailbody, 
         "From: $from");
   }  

   function NotifyError($error_code)
   {
        $this->logger->LogError($error_code);
        if(strlen($this->config->error_mail_to)>0)
        {
            $this->EmailError($error_code);
        }        
   }

   function HandleConfigError($error_code,$extrainfo="")
   {
        $logged = $this->logger->LogError($error_code);
        
        if(strlen($this->config->error_mail_to)>0)
        {
            $this->EmailError($error_code);
        }
        
        if(!$this->enable_error_formpagemerge)
        {
         $this->sys_error = "$error_code \n $extrainfo";
         return;
        }        
        $disp_error = $this->FormatError($logged,$error_code,$extrainfo);

        $this->DisplayError($disp_error);
   }
   
   function FormatError($logged,$error_code,$extrainfo)
   {
        $disp_error = "<p align='left'>";
        $disp_error .= _("There was a configuration error.");

        $extrainfo .= "\n server: ".$_SERVER["SERVER_SOFTWARE"];

        $error_code_disp ='';
        $error_code_disp_link ='';

        if($this->config->debug_mode)
        {
            $error_code_disp = $error_code.$extrainfo;
        }
        else
        {
            if($logged)
            {
                $error_code_disp .= _("The error is logged.");
            }
            else
            {
                $error_code_disp .= _("Could not log the error");
            }

            $error_code_disp .= "<br/>"._("Enable debug mode ('Form processing options' page) for displaying errors.");
        }

        $link = sprintf(_(" <a href='http://www.simfatic.com/forms/troubleshoot/checksol.php?err=%s'>Click here</a> for troubleshooting information."),
                        urlencode($error_code_disp));

        $disp_error .= "<br/>".$error_code_disp."<br/>$link";

        $disp_error .= "</p>";    
        
        return $disp_error;
   }   
}


class FM_FormFiller 
{
   private $filler_js_code;
   private $config;
   private $logger;

   public function __construct(&$config,&$logger)
   {
      $this->filler_js_code="";
      $this->form_filler_variable = "sfm_fill_the_form";
      $this->logger = &$logger;
      $this->config = &$config;
   }
   function GetFillerJSCode()
   {
      return $this->filler_js_code;
   }
   function GetFormFillerScriptEmbedded($formvars)
   {
      $ret_code="";
      if($this->CreateFormFillerScript($formvars))
      {
         $self_script = htmlentities($this->globaldata->get_php_self());
         $ret_code .= "<script language='JavaScript' src='$self_script?sfm_get_ref_file=form-filler-helper.js'></script>\n";
      
         $ret_code .= "<script language='JavaScript'>\n";
         $ret_code .= "\n$util_code\n";
         $ret_code .= $this->filler_js_code;
         $ret_code .= "\n</script>";
      }
      return $ret_code;
   }

   function CreateServerSideVector($formvars,&$outvector)
   {
      foreach($formvars as $name => $value)
      {
         /*if(!$this->config->element_info->IsElementPresent($name)||
         !isset($value))
         {
            continue; 
         }*/
         
         switch($this->config->element_info->GetType($name))
         {
            case "text":
            case "multiline":
            case "decimal":
            case "calcfield":
            case "datepicker":
            case "hidden":
               {
                  $outvector[$name] = $value;
                  break;
               }
            case "single_chk":
            case "radio_group":
            case "multiselect":
            case "chk_group":
               {
                  $this->SetGroupItemValue($outvector,$name,$value,"checked");
                  break;
               } 
            case "listbox":
               {
                  $this->SetGroupItemValue($outvector,$name,$value,"selected");
                  break;
               }
            default:
               {
                  $outvector[$name] = $value;
                  break;               
               }
         }//switch
      }//foreach
   }

   function SetGroupItemValue(&$outvector,$name,$value,$set_val)
   {
      if(is_array($value))
      {
         foreach($value as $val_item)
         {  
            $entry = md5($name.$val_item);
            $outvector[$entry]=$set_val;
         }
         $outvector[$name] = implode(',',$value);
      }
      else
      {
         $entry = md5($name.$value);
         $outvector[$entry]=$set_val;
         $outvector[$name] = $value;
      }
      
   }

   function CreateFormFillerScript($formvars)
   {
      
      $func_body="";
      foreach($formvars as $name => $value)
      {
         if(!$this->config->element_info->IsElementPresent($name)||
         !isset($value))
         {
            continue; 
         }
         switch($this->config->element_info->GetType($name))
         {
            case "text":
            case "multiline":
                case "decimal":
                case "calcfield":
                case "datepicker":
               {
                  $value = str_replace("\n","\\n",$value);
                  $value = str_replace("'","\\'",$value);
                  $func_body .= "formobj.elements['$name'].value = '$value';\n";
                  break;
               }
            case "single_chk":
               {
                  if(strlen($value) > 0 && strcmp($value,"off")!=0)
                  {
                     $func_body .= "formobj.elements['$name'].checked = true;\n";
                  }
                  break;
               }
            
            case "multiselect":
            case "chk_group":
               {
                  $name_tmp="$name"."[]";
                  foreach($value as $item)
                  {  
                     $func_body .= "SFM_SelectChkItem(formobj.elements['$name_tmp'],'$item');\n";
                  }
                  break;
               }
            case "radio_group":
               {
                  $func_body .= "SFM_SelectChkItem(formobj.elements['$name'],'$value');\n";
                  break;
               }
            case "listbox":
               {
                  if(is_array($value))
                  {
                     $name_tmp="$name"."[]";
                     foreach($value as $item)
                     {
                        $func_body .= "SFM_SelectListItem(formobj.elements['$name_tmp'],'$item');\n";
                     }
                  }
                  else
                  {
                     $func_body .= "formobj.elements['$name'].value = '$value';\n";
                  }
                  break;
               }
         }
      }//foreach

      $bret=false;
      $this->filler_js_code="";
      if(strlen($func_body)>0)
      {
         $function_name = "sfm_".$this->formname."formfiller"; 

         $this->filler_js_code .= "function $function_name (){\n";
         $this->filler_js_code .= " var formobj= document.forms['".$this->formname."'];\n";
         $this->filler_js_code .= $func_body;
         $this->filler_js_code .= "}\n";
         $this->filler_js_code .= "$function_name ();";
         $bret= true;
      }
      return $bret;
   }

}


class FM_FormVarMx
{
    private $logger;
    private $config;
    private $globaldata;
    private $formvars;
    private $html_vars;

    public function __construct(&$config,&$logger,&$globaldata)
    {
      $this->config = &$config;
      $this->logger = &$logger;
      $this->globaldata = &$globaldata;
      $this->formvars = &$this->globaldata->formvars;
      $this->html_vars = array();
    }
     
    function AddToHtmlVars($html_var)
    {
        $this->html_vars[] = $html_var;
    }
    function IsHtmlVar($var)
    {
        return (false === array_search($var,$this->html_vars)) ? false:true;
    }
    function CreateFieldMatrix($html=true)
    {
        $datamap = $this->formvars;
        foreach($datamap as $name => $value)
        {
            $value = $this->GetFieldValueAsString($name,/*$use_disp_var*/true);
            if($html && (false == $this->IsHtmlVar($name)) )
            {
                $datamap[$name] = nl2br(htmlentities($value,ENT_QUOTES,"UTF-8"));
            }
            else
            {
                $datamap[$name] = $value;
            }
        }
        
        if(true == $this->config->enable_auto_field_table)
        {
            $datamap['_sfm_non_blank_field_table_'] = $this->CreateFieldTable($datamap);
        }
        
        return $datamap;
    }
    
    function CreateFieldTable(&$datamap)
    {
        $ret_table ="<div class='sfm_table_container'><table cellspacing='0' cellpadding='5'><tbody>";
         $arr_elements = 
            $this->config->element_info->GetAllElements();
         foreach($arr_elements as $ename => $e)
         {
            if(isset($datamap[$ename]) && strlen($datamap[$ename]) > 0 )
            {
               $value = $datamap[$ename];
               
               $ret_table .= "<tr><td class='FieldName'>$ename</td><td class='FieldValue'>$value</td></tr>\n";
            }
         }    
         $ret_table .= "</tbody></table></div>";
         return $ret_table;
    }
    
    function GetFieldValueAsString($var_name,$use_disp_var=false)
    {
        $ret_val ='';
        if(isset($this->formvars[$var_name]))
        {
            $ret_val = $this->formvars[$var_name];
        }

        if(is_array($ret_val))
        {
            $ret_val = implode($this->config->array_disp_seperator,$ret_val);
        }
        else if($use_disp_var && $this->config->element_info->IsUsingDisplayVariable($var_name))
        {
            $disp_var_name = sfm_get_disp_variable($var_name);
            if(!empty($this->formvars[$disp_var_name]))
            {
                $ret_val = $this->formvars[$disp_var_name];
            }
        }
        return $ret_val;
    }    
}

class FM_FormPageRenderer
{
   private $config;
   private $logger;
   private $globaldata;
   private $arr_form_pages;
   private $security_monitor;
   private $ext_module;
   
   public function __construct(&$config,&$logger,&$globaldata,&$security_monitor)
   {
      $this->config = &$config;
      $this->logger = &$logger;
      $this->globaldata = &$globaldata;
      $this->security_monitor = &$security_monitor;
      
      $this->arr_form_pages = array();
      $this->ext_module = null;
   }
   
   function InitExtensionModule(&$extmodule)
   {
        $this->ext_module = &$extmodule;
   }
   
   function SetFormPage($page_num,$templ,$condn='')
   {
      $this->arr_form_pages[$page_num] = array();
      $this->arr_form_pages[$page_num]['templ'] = $templ;
      $this->arr_form_pages[$page_num]['condn'] = $condn;
   }
   
   function GetNumPages()
   {
      return count($this->arr_form_pages);
   }
   
   function GetCurrentPageNum()
   {
      return $this->globaldata->GetCurrentPageNum();
   }
   function GetLastPageNum()
   {
      return ($this->GetNumPages()-1);
   }
   
   function IsPageNumSet()
   {
      return ($this->globaldata->form_page_num >= 0);
   }
   
   function DisplayCurrentPage($addnl_vars=NULL)
   {
      $this->DisplayFormPage($this->getCurrentPageNum(),$addnl_vars);
   }
   
   function DisplayNextPage($addnl_vars,&$display_thankyou)
   {
      if($this->IsPageNumSet() && 
         $this->getCurrentPageNum() < $this->GetLastPageNum())
      {
         $nextpage = $this->GetNextPageNum($addnl_vars);
         
         if($nextpage < $this->GetNumPages())
         {
            $this->DisplayFormPage($nextpage,$addnl_vars);
            return;
         }
         else
         {
            $display_thankyou =true;
            return;
         }
      }
      
      $this->DisplayFormPage(0,$addnl_vars);
   }
   
   function DisplayFirstPage($addnl_vars)
   {
        $this->DisplayFormPage(0,$addnl_vars);
   }
   
   function DisplayPrevPage($addnl_vars)
   {
      if($this->IsPageNumSet())
      {
         $curpage = $this->getCurrentPageNum();
         
         $prevpage = $curpage-1;
         
         for(;$prevpage>=0;$prevpage--)
         {
            if($this->TestPageCondition($prevpage,$addnl_vars))
            {
               break;
            }
         }
         
         if($prevpage >= 0)
         {
            $this->DisplayFormPage($prevpage,$addnl_vars);   
            return;
         }
      }
      
      $this->DisplayFormPage(0,$addnl_vars);   
   }   
   
   function GetNextPageNum($addnl_vars)
   {
      $nextpage = 0;
      
      if($this->IsPageNumSet() )
      {
         $nextpage =  $this->getCurrentPageNum() + 1;
         
         for(;$nextpage < $this->GetNumPages(); $nextpage ++)
         {
            if($this->TestPageCondition($nextpage,$addnl_vars))
            {
                  break;
            }
         }
      }    
      return $nextpage;
   }
   
   function IsNextPageAvailable($addnl_vars)
   {
      if($this->GetNextPageNum($addnl_vars) < $this->GetNumPages())
      {
         return true;
      }
      return false;
   }
   
   function TestPageCondition($pagenum,$addnl_vars)
   {
      $condn = $this->arr_form_pages[$pagenum]['condn'];
      
      if(empty($condn))
      {
         return true;
      }
      elseif(sfm_validate_multi_conditions($condn,$addnl_vars))
      {
         return true;
      }
      $this->logger->LogInfo("TestPageCondition condn: returning false");
      return false;
   }
   
   function DisplayFormPage($page_num,$addnl_vars=NULL)
   {
      $fillerobj = new FM_FormFiller($this->config,$this->logger);
      
      $var_before_proc = array();
      if(!is_null($addnl_vars))
      {
         $var_before_proc = array_merge($var_before_proc,$addnl_vars);
      }
      $var_before_proc = array_merge($var_before_proc,$this->globaldata->formvars);
      
      $this->globaldata->NormalizeFormVarsBeforePageDisplay($var_before_proc,$page_num);
      
      if($this->ext_module && false === $this->ext_module->BeforeFormDisplay($var_before_proc,$page_num))
      {
         $this->logger->LogError("Extension Module 'BeforeFormDisplay' returned false! ");
         return false;
      }
      
      
      $var_map = array();
      $fillerobj->CreateServerSideVector($var_before_proc,$var_map);

      $var_map[$this->config->self_script_variable]  = $this->globaldata->get_php_self();
      
      $var_map['sfm_css_rand'] = rand();
      $var_map[$this->config->var_cur_form_page_num] = $page_num+1;
      
      $var_map[$this->config->var_form_page_count] = $this->GetNumPages();
      
      $var_map[$this->config->var_page_progress_perc] = ceil((($page_num)*100)/$this->GetNumPages());
      
      $this->security_monitor->AddSecurityVariables($var_map);

      $page_templ='';
      if(!isset($this->arr_form_pages[$page_num]))
      {
         $this->logger->LogError("Page $page_num not initialized");
      }
      else
      {
        $page_templ = $this->arr_form_pages[$page_num]['templ'];
      }
      
      ob_clean();
      $merge = new FM_PageMerger();
      
      convert_html_entities_in_formdata(/*skip var*/$this->config->error_display_variable,$var_map,/*nl2br*/false);
      if(false == $merge->Merge($page_templ,$var_map))
      {
         return false;
      }                
      $strdisp = $merge->getMessageBody();
      echo $strdisp;
      return true;    
   }
}

class FM_SecurityMonitor
{
   private $config;
   private $logger;
   private $globaldata;
   private $banned_ip_arr;
   private $session_input_id;
   
   
   public function __construct(&$config,&$logger,&$globaldata)
   {
      $this->config = &$config;
      $this->logger = &$logger;
      $this->globaldata = &$globaldata;   
      $this->banned_ip_arr = array();
      $this->session_input_id = '_sfm_session_input_id_';
      $this->session_input_value = '_sfm_session_input_value_';
   }
   
   function AddBannedIP($ip)
   {
      $this->banned_ip_arr[] = $ip;
   }
   
   function IsBannedIP()
   {
      $ip = $this->globaldata->server_vars['REMOTE_ADDR'];
      
      $n = count($this->banned_ip_arr);
      
      for($i=0;$i<$n;$i++)
      {
         if(sfm_compare_ip($this->banned_ip_arr[$i],$ip))
         {
            $this->logger->LogInfo("Banned IP ($ip) attempted the form. Returned error.");
            return true;
         }
      }
      return false;
   
   }
   
   function GetFormIDInputName()
   {
      if(!empty($this->globaldata->session[$this->session_input_id]))
      {
        return $this->globaldata->session[$this->session_input_id];
      }
      $idname = $this->globaldata->GetVisitorUniqueKey();
      $idname = str_replace('-','',$idname);
      $idname = 'id_'.substr($idname,0,20);
      
      $this->globaldata->session[$this->session_input_id] = $idname;
      return $idname;
   }
   
   function GetFormIDInputValue()
   {
      if(!empty($this->globaldata->session[$this->session_input_value]))
      {
        return $this->globaldata->session[$this->session_input_value];
      }
      $value = $this->globaldata->GetVisitorUniqueKey();
      
      $value = substr(md5($value),5,25);
      
      $this->globaldata->session[$this->session_input_value] = $value;
      
      return $value;
   }
   
   function AddSecurityVariables(&$varmap)
   {
      $varmap[$this->config->form_id_input_var] = $this->GetFormIDInputName();
      $varmap[$this->config->form_id_input_value] = $this->GetFormIDInputValue();
   }
   
   function Validate($formdata)
   {
      $formid_input_name = $this->GetFormIDInputName();
      
      $this->logger->LogInfo("Form ID input name: $formid_input_name ");
      
      if($this->IsBannedIP())
      {
         $this->logger->LogInfo("Is Banned IP");
         return false;
      }
      if(true == $this->config->bypass_spammer_validations)
	  {
			return true;
	  }
      if(!isset($formdata[$formid_input_name]))
      {
         $this->logger->LogError("Form ID input is not set");
         return false;
      }
      elseif($formdata[$formid_input_name] != $this->GetFormIDInputValue())
      {
         $this->logger->LogError("Spammer attempt foiled! Form ID input value not correct. expected:".
            $this->GetFormIDInputValue()." Received:".$formdata[$formid_input_name]);
            
         return false;
      }
      
      if(!empty($this->config->hidden_input_trap_var) && 
         !empty($formdata[$this->config->hidden_input_trap_var]) )
      {
         $this->logger->LogError("Hidden input trap value is not empty. Spammer attempt foiled!");
         return false;
      }
     $this->logger->LogInfo("Sec Monitor Validate returning true");
     return true;
   }
}

class FM_Module
{
    protected $config;
    protected $formvars;
    protected $logger;
    protected $globaldata;
    protected $error_handler;
    protected $formname;
    protected $ext_module;
    protected $common_objs;

    public function __construct()
    {
    }

    function Init(&$config,&$formvars,&$logger,&$globaldata,
         &$error_handler,$formname,&$ext_module,&$common_objs)
    {
      $this->config = &$config;
      $this->formvars = &$formvars;
      $this->logger = &$logger;
      $this->globaldata =&$globaldata;
      $this->error_handler = &$error_handler;
      $this->formname = $formname;
      $this->ext_module = &$ext_module;
      $this->common_objs = &$common_objs;
      $this->OnInit();
    }

    function OnInit()
    {
    }
    
    function AfterVariablessInitialized()
    {
        return true;
    }

    function Process(&$continue)
    {
        return true;
    }

    function ValidateInstallation(&$app_command_obj)
    {
        return true;
    }
    
    function DoAppCommand($cmd,$val,&$app_command_obj)
    {
        //Return true to indicate 'handled'
        return false;
    }
    
    function Destroy()
    {

    }
    function getFormDataFolder()
    {
      if(strlen($this->config->form_file_folder)<=0)
      {
         $this->error_handler->HandleConfigError("Config Error: No Form data folder is set; but tried to access form data folder");
         exit;
      }
      return $this->config->form_file_folder;
    }
}

///////PageMerger////////////////////
class FM_PageMerger
{
   var $message_body;
   
   public function __construct()
   {
      $this->message_body="";
   }

   function Merge($content,$variable_map)
   {
      $this->message_body = $this->mergeStr($content,$variable_map);
      
      return(strlen($this->message_body)>0?true:false);
   }  
   
   function mergeStr($template,$variable_map)
   {
        $ret_str = $template;
        $N = 0;
        $m = preg_match_all("/%([\w]*)%/", $template,$matches,PREG_PATTERN_ORDER);

        if($m > 0 || count($matches) > 1)
        {
            $N = count($matches[1]);
        }

        $source_arr = array();
        $value_arr = array();

        for($i=0;$i<$N;$i++)
        {
            $val = "";
            $key = $matches[1][$i];
            if(isset($variable_map[$key]))
            {
                if(is_array($variable_map[$key]))
                {
                    $val = implode(",",$variable_map[$key]);
                }
            else
                {
                    $val = $variable_map[$key];
                }
            }
            else
            if(strlen($key)<=0)
            {
                $val ='%';
            }
            $source_arr[$i] = $matches[0][$i];
            $value_arr[$i] = $val;
        }
        
        $ret_str = str_replace($source_arr,$value_arr,$template);
        
        return $ret_str;
   }

   function mergeArray(&$arrSource, $variable_map)
   {
        foreach($arrSource as $key => $value)
        {
            if(!empty($value) && false !== strpos($value,'%'))
            {
                $arrSource[$key] = $this->mergeStr($value,$variable_map);
            }
        }
   }
   function getMessageBody()
   {
      return $this->message_body;
   }
}

class FM_ExtensionModule
{
    protected $config;
    protected $formvars;
    protected $logger;
    protected $globaldata;
    protected $error_handler;
    protected $formname;

    public function __construct()
    {
        
    }

    function Init(&$config,&$formvars,&$logger,&$globaldata,&$error_handler,$formname)
    {
        $this->config = &$config;
        $this->formvars = &$formvars;
        $this->logger = &$logger;
        $this->globaldata =&$globaldata;
        $this->error_handler = &$error_handler;
        $this->formname = $formname;
    }
    function BeforeStartProcessing()
    {
        return true;
    }
    function AfterVariablessInitialized()
    {
        return true;
    }
   function BeforeFormDisplay(&$formvars,$pagenum=0)
   {
      return true;
   }
    function LoadDynamicList($listname,&$rows)
    {
        //return true if this overload loaded the list
        return false;
    }
    function LoadCascadedList($listname,$parent,&$rows)
    {
        return false;
    }
    function DoValidate(&$formvars, &$error_hash)
    {
        return true;
    }
    
    function DoValidatePage(&$formvars, &$error_hash,$page)
    {
        return true;
    }
    
    function PreprocessFormSubmission(&$formvars)
    {
        return true;
    }
    
   function BeforeConfirmPageDisplay(&$formvars)
   {
      return true;      
   }

   function FormSubmitted(&$formvars)
   {
      return true;
   }

    function BeforeThankYouPageDisplay(&$formvars)
    {
        return true;
    }
    
    function BeforeSendingFormSubmissionEMail(&$receipient,&$subject,&$body)
    {
        return true;
    }
    
    function BeforeSendingAutoResponse(&$receipient,&$subject,&$body)
    {
        return true;
    }
    function BeforeSubmissionTableDisplay(&$fields)
    {
        return true;
    }
    function BeforeDetailedPageDisplay(&$rec)
    {
        return true;
    }
	function HandleFilePreview($filepath)
	{
		return false;
	}
}

class FM_ExtensionModuleHolder
{
    private $modules;

    private $config;
    private $formvars;
    private $logger;
    private $globaldata;
    private $error_handler;
    private $formname;

    function Init(&$config,&$formvars,&$logger,&$globaldata,&$error_handler,$formname)
    {
        $this->config = &$config;
        $this->formvars = &$formvars;
        $this->logger = &$logger;
        $this->globaldata =&$globaldata;
        $this->error_handler = &$error_handler;
        $this->formname = $formname;
        $this->InitModules();
    }

   public function __construct()
   {
      $this->modules = array();
   }
   
   function AddModule(&$module)
   {
      array_push_ref($this->modules,$module);
   }
   
   function InitModules()
   {
      $N = count($this->modules);

        for($i=0;$i<$N;$i++)
        {
            $mod = &$this->modules[$i];
            $mod->Init($this->config,$this->formvars,
                $this->logger,$this->globaldata,
                $this->error_handler,$this->formname);
        }      
   }
    
   function Delegate($method,$params)
   {
        $N = count($this->modules);
        for($i=0;$i<$N;$i++)
        {
            $mod = &$this->modules[$i];
            $ret_c = call_user_func_array(array(&$mod, $method), $params);
            if(false === $ret_c)
            {
                return false;
            }
        }
        return true;
   }
   
   function DelegateFalseDefault($method,$params)
   {
        $N = count($this->modules);
        for($i=0;$i<$N;$i++)
        {
            $mod = &$this->modules[$i];
            $ret_c = call_user_func_array(array(&$mod, $method), $params);
            if(true === $ret_c)
            {
                return true;
            }
        }
        return false;
   }
   
   function DelegateEx($method,$params)
   {
        $N = count($this->modules);
        $ret = true;
        for($i=0;$i<$N;$i++)
        {
            $mod = &$this->modules[$i];
            $ret_c = call_user_func_array(array($mod, $method), $params);
            $ret = $ret && $ret_c;
        }
        return $ret;
   }

   function AfterVariablessInitialized()
    {
        return $this->Delegate('AfterVariablessInitialized',array()); 
    }   
    
    function BeforeStartProcessing()
    {
        return $this->Delegate('BeforeStartProcessing',array());    
    }
    
    function BeforeFormDisplay(&$formvars,$pagenum)
    {
        return $this->Delegate('BeforeFormDisplay',array(&$formvars,$pagenum));    
    }   
    function LoadDynamicList($listname,&$rows)
    {
        return $this->DelegateFalseDefault('LoadDynamicList',array($listname,&$rows));
    }
    
    function LoadCascadedList($listname,$parent,&$rows)
    {
        return $this->DelegateFalseDefault('LoadCascadedList',array($listname,$parent,&$rows));
    }    
    function DoValidatePage(&$formvars, &$error_hash,$page)
    {
        return $this->DelegateEx('DoValidatePage',array(&$formvars, &$error_hash,$page));
    }       

    function DoValidate(&$formvars, &$error_hash)
    {
        return $this->DelegateEx('DoValidate',array(&$formvars, &$error_hash));
    }
    
    function PreprocessFormSubmission(&$formvars)
    {
        return $this->Delegate('PreprocessFormSubmission',array(&$formvars));
    }

    function BeforeConfirmPageDisplay(&$formvars)
    {
      return $this->Delegate('BeforeConfirmPageDisplay',array(&$formvars));
    }

    function FormSubmitted(&$formvars)
    {
      return $this->Delegate('FormSubmitted',array(&$formvars));
    }

    function BeforeThankYouPageDisplay(&$formvars)
    {
      return $this->Delegate('BeforeThankYouPageDisplay',array(&$formvars));
    }
    
    
    function BeforeSendingFormSubmissionEMail(&$receipient,&$subject,&$body)
    {
       return $this->Delegate('BeforeSendingFormSubmissionEMail',array(&$receipient,&$subject,&$body));
    }
   
    function BeforeSendingAutoResponse(&$receipient,&$subject,&$body)
    {
        return $this->Delegate('BeforeSendingAutoResponse',array(&$receipient,&$subject,&$body));
    }

    function BeforeSubmissionTableDisplay(&$fields)
    {
        return $this->Delegate('BeforeSubmissionTableDisplay',array(&$fields));
    }

    function BeforeDetailedPageDisplay(&$rec)
    {
        return $this->Delegate('BeforeDetailedPageDisplay',array(&$rec));
    }

    function HandleFilePreview($filepath)
    {
        return $this->Delegate('HandleFilePreview',array($filepath));
    }    
}

///////Form Installer////////////////////
class SFM_AppCommand
{
    private $config;
    private $logger;
    private $error_handler;   
    private $globaldata;
    public  $response_sender;
    private $app_command;
    private $command_value;
    private $email_tested;
    private $dblogin_tested;

    public function __construct(&$globals, &$config,&$logger,&$error_handler)
    {
      $this->globaldata = &$globals;
      $this->config = &$config;
      $this->logger = &$logger;
      $this->error_handler = &$error_handler;    
      $this->response_sender = new FM_Response($this->config,$this->logger);
      $this->app_command='';
      $this->command_value='';
      
      $this->email_tested=false;
      $this->dblogin_tested=false;
    }
    
    function IsAppCommand()
    {
        return empty($this->globaldata->post_vars[$this->config->config_update_var])?false:true;
    }
     
    function Execute(&$modules)
    {
        $continue = false;
        if(!$this->IsAppCommand())
        {
            return true;
        }
        $this->config->debug_mode = true;
        $this->error_handler->disable_syserror_handling=true;
        $this->error_handler->DisableErrorFormMerge();
        
        if($this->DecodeAppCommand())
        {
            switch($this->app_command)
            {
                case 'ping':
                {
                    $this->DoPingCommand($modules);
                    break;
                }
                case 'log_file':
                {
                    $this->GetLogFile();
                    break;
                }
                default:
                {
                    $this->DoCustomModuleCommand($modules);
                    break;
                }
            }//switch
        }//if
        
        $this->ShowResponse();
        return $continue;
    }
    
    function DoPingCommand(&$modules)
    {
        if(!$this->Ping())
        {
            return false;
        }
        
        
        $N = count($modules);

        for($i=0;$i<$N;$i++)
        {
            $mod = &$modules[$i];
            if(!$mod->ValidateInstallation($this))
            {
               $this->logger->LogError("ValidateInstallation: module $i returns false!");
               return false;
            }
        }    
        return true;
    }
    
    function GetLogFile()
    {
		$log_file_path=$this->logger->get_log_file_path();

        $this->response_sender->SetNeedToRemoveHeader();
        
        return $this->response_sender->load_file_contents($log_file_path);
    }
    
    function DecodeAppCommand()
    {
        if(!$this->ValidateConfigInput())
        {
            return false;
        }
        $cmd = $this->globaldata->post_vars[$this->config->config_update_var];
        
        

        $this->app_command = $this->Decrypt($cmd);

        

        $val = "";
        if(isset($this->globaldata->post_vars[$this->config->config_update_val]))
        {
            $val = $this->globaldata->post_vars[$this->config->config_update_val];
            $this->command_value = $this->Decrypt($val);
        }
        
        return true;
    }
    
    function DoCustomModuleCommand(&$modules)
    {
        $N = count($modules);

        for($i=0;$i<$N;$i++)
        {
            $mod = &$modules[$i];
            if($mod->DoAppCommand($this->app_command,$this->command_value,$this))
            {
               break;
            }
        }
    }
    
    function IsPingCommand()
    {
        return ($this->app_command == 'ping')?true:false;
    }
    
    function Ping()
    {
        $ret = false;
        $installed="no";
        if(true == $this->config->installed)
        {
            $installed="yes";
            $ret=true;
        }
        $this->response_sender->appendResponse("is_installed",$installed);
        return $ret;
    }
    
    function TestSMTPEmail()
    {
        if(!$this->config->IsSMTP())
        {
            return true;
        }
        
        $initial_sys_error = $this->error_handler->IsSysError();
        
        $mailer = new FM_Mailer($this->config,$this->logger,$this->error_handler);
        //Note: this is only to test the SMTP settings. It tries to send an email with subject test Email
        // If there is any error in SMTP settings, this will throw error
        $ret = $mailer->SendMail('tests@simfatic.com','tests@simfatic.com','Test Email','Test Email',false);
        
        if($ret && !$initial_sys_error && $this->error_handler->IsSysError())
        {
            $ret = false;
        }
        
        if(!$ret)
        {
            $this->logger->LogInfo("SFM_AppCommand: Ping-> error sending email ");
            $this->response_sender->appendResponse('email_smtp','error');
        }
        
        $this->email_tested = true;
        
        return $ret;
    }
    function rem_file($filename,$base_folder)
    {
        $filename = trim($filename);
        if(strlen($filename)>0)
        {
          $filepath = sfm_make_path($base_folder,$filename);
          $this->logger->LogInfo("SFM_AppCommand: Removing file $filepath");
          
          $success=false;
          if(unlink($filepath))
          {
            $this->response_sender->appendResponse("result","success");
            $this->logger->LogInfo("SFM_AppCommand: rem_file removed file $filepath");
            $success=true;
          }
          $this->response_sender->appendResultResponse($success);
        }    
    }
    function IsEmailTested()
    {
        return $this->email_tested;
    }
    function TestDBLogin()
    {
        if($this->IsDBLoginTested())
        {
            return true;
        }
        $dbutil = new FM_DBUtil();
        $dbutil->Init($this->config,$this->logger,$this->error_handler);
        $dbtest_result = $dbutil->Login();

        if(false === $dbtest_result)
        {
            $this->logger->LogInfo("SFM_AppCommand: Ping-> dblogin error");
            $this->response_sender->appendResponse('dblogin','error');
        }
        $this->dblogin_tested = true;
        return $dbtest_result;
    }    
    
    function IsDBLoginTested()
    {
        return $this->dblogin_tested;
    }
    
    function ValidateConfigInput()
    {
        $ret=false;
        if(!isset($this->config->encr_key) ||
            strlen($this->config->encr_key)<=0)
        {
            $this->addError("Form key is not set");
        }
        else
        if(!isset($this->config->form_id) ||
            strlen($this->config->form_id)<=0)
        {
            $this->addError("Form ID is not set");
        }
        else
        if(!isset($this->globaldata->post_vars[$this->config->config_form_id_var]))
        {
            $this->addError("Form ID is not set");
        }
        else
        {
            $form_id = $this->globaldata->post_vars[$this->config->config_form_id_var];
            $form_id = $this->Decrypt($form_id);
            if(strcmp($form_id,$this->config->form_id)!=0)
            {
                $this->addError("Form ID Does not match");
            }
            else
            {
                $this->logger->LogInfo("SFM_AppCommand:ValidateConfigInput succeeded");
                $ret=true;
            }
        }
        return $ret;
    }    
    function Decrypt($str)
    {
        return sfm_crypt_decrypt($str,$this->config->encr_key);
    }    
    function ShowResponse()
    {
		if($this->error_handler->IsSysError())
		{
			$this->addError($this->error_handler->GetSysError());
		}
        $this->response_sender->ShowResponse();
    }    
    function addError($error)
    {
        $this->response_sender->addError($error);
    }    
}


class FM_Response
{
    private $error_str;
    private $response;
    private $encr_response;
    private $extra_headers;
    private $sfm_headers;

    public function __construct(&$config,&$logger)
    {
        $this->error_str="";
        $this->response="";
        $this->encr_response=true;
        $this->extra_headers = array();
        $this->sfm_headers = array();
        $this->logger = &$logger;
		$this->config = &$config;
    }

    function addError($error)
    {
        $this->error_str .= $error;
        $this->error_str .= "\n";
    }
	
	function isError()
	{
		return empty($this->error_str)?false:true;
	}
	function getError()
	{
		return $this->error_str;
	}
	
	function getResponseStr()
	{
		return $this->response;
	}
	
    function straighten_val($val)
    {
        $ret = str_replace("\n","\\n",$val);
        return $ret;
    }

    function appendResponse($name,$val)
    {
        $this->response .= "$name: ".$this->straighten_val($val);
        $this->response .= "\n";
    }

    function appendResultResponse($is_success)
    {
        if($is_success)
        {
            $this->appendResponse("result","success");
        }
        else
        {
            $this->appendResponse("result","failed");
        }
    }
    
    function SetEncrypt($encrypt)
    {
        $this->encr_response = $encrypt;
    }

    function AddResponseHeader($name,$val,$replace=false)
    {
        $header = "$name: $val";
        $this->extra_headers[$header] = $replace;
    }

    function AddSFMHeader($option)
    {
        $this->sfm_headers[$option]=1;
    }


    function SetNeedToRemoveHeader()
    {
        $this->AddSFMHeader('remove-header-footer');
    }

    function ShowResponse()
    {
        $err=false;
        ob_clean();
        if(strlen($this->error_str)>0) 
        {
            $err=true;
            $this->appendResponse("error",$this->error_str);
            $this->AddSFMHeader('sforms-error');
            $log_str = sprintf("FM_Response: reporting error:%s",$this->error_str);
            $this->logger->LogError($log_str);
        }
        
        $resp="";
        if(($this->encr_response || true == $err) && 
           (false == $this->config->sys_debug_mode))
        {
            $this->AddResponseHeader('Content-type','application/sforms-e');

            $resp = $this->Encrypt($this->response);
        }
        else
        {
            $resp = $this->response;
        }

        $cust_header = "SFM_COMM_HEADER_START{\n";
        foreach($this->sfm_headers as $sfm_header => $flag)
        {
             $cust_header .=  $sfm_header."\n";
        }
        $cust_header .= "}SFM_COMM_HEADER_END\n";

        $resp = $cust_header.$resp;

        $this->AddResponseHeader('pragma','no-cache',/*replace*/true);
		$this->AddResponseHeader('cache-control','no-cache');        
        $this->AddResponseHeader('Content-Length',strlen($resp));

        foreach($this->extra_headers as $header_str => $replace)
        {
            
            header($header_str, false);
        }


        print($resp);
        if(true == $this->config->sys_debug_mode)
        {
            $this->logger->print_log();
        }
    }

    function Encrypt($str)
    {
        //echo " Encrypt $str ";
        //$blowfish = new Crypt_Blowfish($this->config->encr_key);
        $retdata = sfm_crypt_encrypt($str,$this->config->encr_key);
        /*$blowfish =& Crypt_Blowfish::factory('ecb');
        $blowfish->setKey($this->config->encr_key);

        $encr = $blowfish->encrypt($str);
        $retdata = bin2hex($encr);*/
        return $retdata;
    }

    function load_file_contents($filepath)
    {
        $filename = basename($filepath);

        $this->encr_response=false;
        

        $fp = fopen($filepath,"r");

        if(!$fp)
        {
            $err = sprintf("Failed opening file %s",$filepath);
            $this->addError($err);
            return false;
        }

        $this->AddResponseHeader('Content-Disposition',"attachment; filename=\"$filename\"");

        $this->response = file_get_contents($filepath);
        
        return true;
    }
    
    function SetResponse($response)
    {
        $this->response = $response;
    }
}


class FM_CommonObjs
{
   public $formpage_renderer;
   public $security_monitor;
   public $formvar_mx;

   public function __construct(&$config,&$logger,&$globaldata)
   {
     $this->security_monitor = 
         new FM_SecurityMonitor($config,$logger,$globaldata); 
         
      $this->formpage_renderer = 
         new  FM_FormPageRenderer($config,$logger,$globaldata, $this->security_monitor);
         
      $this->formvar_mx = new FM_FormVarMx($config,$logger,$globaldata);
   }
   function InitFormVars(&$formvars)
   {
        $this->formvar_mx->InitFormVars($formvars);
   }
   function InitExtensionModule(&$extmodule)
   {
     $this->formpage_renderer->InitExtensionModule($extmodule);
   }
}

////SFM_FormProcessor////////////////
class SFM_FormProcessor
{
   private $globaldata;
   private $formvars;
   private $formname;
   private $logger;
   private $config;
   private $error_handler;
   private $modules;
   private $ext_module_holder;
   private $common_objs;

   public function __construct($formname)
   {
      ob_start();
	  
      $this->formname = $formname;
      $this->config = new FM_Config();
      $this->config->formname = $formname;
      
      $this->globaldata = new FM_GlobalData($this->config);

      $this->logger = new FM_Logger($this->config,$formname);
      $this->logger->SetLogSource("form:$formname");
      
      $this->common_objs = new FM_CommonObjs($this->config,$this->logger,$this->globaldata);
      
      $this->error_handler  = new FM_ErrorHandler($this->logger,$this->config,
                        $this->globaldata,$formname,$this->common_objs);
                        
      $this->error_handler->InstallConfigErrorCatch();
      $this->modules=array();
      $this->ext_module_holder = new FM_ExtensionModuleHolder();
      $this->common_objs->InitExtensionModule($this->ext_module_holder);
      $this->SetDebugMode(true);//till it is disabled explicitely
      
   }
	function initTimeZone($timezone)
	{
		$this->config->default_timezone = $timezone;
		
		if (!empty($timezone) && $timezone != 'default') 
		{
			//for >= PHP 5.1
			if(function_exists("date_default_timezone_set")) 
			{
				date_default_timezone_set($timezone);
			} 
			else// for PHP < 5.1 
			{
				@putenv("PHP_TZ=".$timezone);
				@putenv("TZ=" .$timezone);
			}
		}//if
		else
		{
            if(function_exists("date_default_timezone_set"))
            {
                date_default_timezone_set(date_default_timezone_get());
            }
		}
	}

	function init_session()
    {
	
        $ua = empty($_SERVER['HTTP_USER_AGENT']) ? '' : $_SERVER['HTTP_USER_AGENT'];
        
		if(true === $this->config->enable_p2p_header && 
		   false !== stristr($ua, 'MSIE'))
		{
			header('P3P: CP="CURa ADMa DEVa PSAo PSDo OUR BUS UNI PUR INT DEM STA PRE COM NAV OTC NOI DSP COR"');
		}
        
		session_start();
        $this->globaldata->InitSession();
        
        if(empty($this->globaldata->session['sfm_form_user_identificn']) )
        {
            if(!empty($_GET['sfm_sid']) &&
            true === $this->config->enable_session_id_url)
            {//session not loaded properly; load from sid passed through URL
                $this->logger->LogInfo('getting session ID from URL');
                session_destroy();
                session_id($_GET['sfm_sid']);
                session_start();
                $this->globaldata->InitSession();
                if(empty($this->globaldata->session['sfm_form_user_identificn'])||
                   $this->globaldata->session['sfm_form_user_identificn'] != $this->globaldata->GetVisitorUniqueKey())
                {//safety check. If the user is not same something wrong.
                    
                    $this->logger->LogInfo('sfm_form_user_identificn still does not match:'.
                            $this->globaldata->session['sfm_form_user_identificn']);
                            
                    session_regenerate_id(FALSE);
                    session_unset();
                    $this->globaldata->InitSession();
                }
            }
            
            $this->globaldata->session['sfm_form_user_identificn'] = $this->globaldata->GetVisitorUniqueKey();
        }
        
	}
	
    function setEmailFormatHTML($ishtml)
    {
        $this->config->email_format_html = $ishtml;
    }
    function setFormFileFolder($folder)
    {
        $this->config->form_file_folder = $folder;
    }
    
    function setIsInstalled($installed)
    {
        $this->config->installed = $installed;   
    }

    function SetSingleBoxErrorDisplay($enabled)
    {
        $this->config->show_errors_single_box = $enabled;
    }

   function setFormPage($page_num,$formpage_code,$condn='')
   {
      $this->common_objs->formpage_renderer->setFormPage($page_num,$formpage_code,$condn);
   }
    
    function setFormID($id)
    {
        $this->config->set_form_id($id);
    }
    
    function setLocale($name,$date_format)
    {
        $this->config->SetLocale($name,$date_format);
    }
    
    function setFormKey($key)
    {
        $this->config->set_encrkey($key);
    }
    function setRandKey($key)
    {
        $this->config->set_rand_key($key);
    }
	
	function DisableAntiSpammerSecurityChecks()
	{
		$this->config->bypass_spammer_validations=true;
	}
	
    function InitSMTP($host,$uname,$pwd,$port)
    {
        $this->config->InitSMTP($host,$uname,$pwd,$port);
    }
    function setFormDBLogin($host,$uname,$pwd,$database)
    {
        $this->config->setFormDBLogin($host,$uname,$pwd,$database);
    }

   function EnableLogging($enable)
   {
      $this->logger->EnableLogging($enable);
   }

   function SetErrorEmail($email)
   {
      $this->config->set_error_email($email);
   }
   
   function SetPasswordsEncrypted($encrypted)
   {
    $this->config->passwords_encrypted = $encrypted;
   }
   
   function SetPrintPreviewPage($preview_file)
   {
      $this->config->SetPrintPreviewPage($preview_file);
   }
   function AddElementInfo($name,$type,$extra_info,$page=0)
   {
      $this->config->element_info->AddElementInfo($name,$type,$extra_info,$page);
   }
   function AddDefaultValue($name,$value)
   {
      $this->config->element_info->AddDefaultValue($name,$value);
   }

   function SetDebugMode($enable)
   {
      $this->config->setDebugMode($enable);
   }

    function SetFromAddress($from)
    {
        $this->config->from_addr = $from;
    }

    function SetVariableFrom($enable)
    {
        $this->config->variable_from = $enable;
    }

    function SetHiddenInputTrapVarName($varname)
    {
      $this->config->hidden_input_trap_var = $varname;
    }    

    function EnableLoadFormValuesFromURL($enable)
    {
        $this->config->load_values_from_url = $enable;
    }
    
    function EnableAutoFieldTable($enable)
    {
        $this->config->enable_auto_field_table = $enable;
    }
    
    function BanIP($ip)
    {
      $this->common_objs->security_monitor->AddBannedIP($ip);
    }
    
    function SetSavedMessageTemplate($msg_templ)
    {
       $this->config->saved_message_templ = $msg_templ;
    }
     
   function GetVars()
   {
        $this->globaldata->GetGlobalVars();

        $this->formvars = &$this->globaldata->formvars;

        $this->logger->LogInfo("GetVars:formvars ".@print_r($this->formvars,true)."\n");

        if(!isset($this->formname) ||
           strlen($this->formname)==0)
        {
           $this->error_handler->HandleConfigError("Please set the form name","");
           return false;            
        }
        $this->error_handler->SetFormVars($this->formvars);
        return true;
   }
   
    function addModule(&$module)
    {
        array_push_ref($this->modules,$module);
    }

   function AddExtensionModule(&$module)
   {
      $this->ext_module_holder->AddModule($module);
   }

   function getmicrotime()
    { 
        list($usec, $sec) = explode(" ",microtime()); 
        return ((float)$usec + (float)$sec); 
    } 
    
   function AfterVariablessInitialized()
   {
        $N = count($this->modules);
        for($i=0;$i<$N;$i++)
        {
            if(false === $this->modules[$i]->AfterVariablessInitialized())
            {
                return false;
            }
        }
        if(false === $this->ext_module_holder->AfterVariablessInitialized())
        {
            return false;
        }
        return true;
   }
    
   function DoAppCommand()
   {
        $continue=true;
        $app_command = new SFM_AppCommand($this->globaldata,$this->config,$this->logger,$this->error_handler);
        $continue = $app_command->Execute($this->modules);
        return $continue;
   }
   
   function ProcessForm()
   {
        $timestart = $this->getmicrotime();
        
		$this->init_session();
		
        $N = count($this->modules);

        for($i=0;$i<$N;$i++)
        {
            $mod = &$this->modules[$i];
            $mod->Init($this->config,$this->globaldata->formvars,
                $this->logger,$this->globaldata,
                $this->error_handler,$this->formname,
            $this->ext_module_holder,$this->common_objs);
        }
        
        $this->ext_module_holder->Init($this->config,$this->globaldata->formvars,
               $this->logger,$this->globaldata,
               $this->error_handler,$this->formname);
      
        
        do
        {
            if(false === $this->ext_module_holder->BeforeStartProcessing())
            {
                $this->logger->LogInfo("Extension module returns false for BeforeStartProcessing. Stopping.");
                break;
            }
            
            if(false == $this->GetVars())
            {
                $this->logger->LogError("GetVars() Failed");
                break;
            }
            
            if(false === $this->DoAppCommand())
            {
                break;
            }
            
            if(false === $this->AfterVariablessInitialized() )
            {
                break;
            }
            
            for($i=0;$i<$N;$i++)
            {
                $mod = &$this->modules[$i];
                $continue = true;

                $mod->Process($continue);
                if(!$continue){break;}
            }

            for($i=0;$i<$N;$i++)
            {
                $mod = &$this->modules[$i];
                $mod->Destroy();
            }
            
            if($this->globaldata->IsFormProcessingComplete())
            {
                $this->globaldata->DestroySession();
            }
        }while(0);
        
        $timetaken  = $this->getmicrotime()-$timestart;

        $this->logger->FlushLog();

        ob_end_flush();
        return true;
   }
   
   function showVars()
   {
      foreach($this->formvars as $name => $value)
      {
         echo "$name $value <br>";
      }
   }
   
}


class SecureFileMaker
{
    private $signature_line;
    private $file_pos;

     public function __construct($signature)
     {
        $this->signature_line = $signature;
     }

     function CreateFile($filepath, $first_line)
     {
        $fp = fopen($filepath,"w");
        if(!$fp)
        {
          return false;
        }

        $header = $this->get_header()."\n";
        $first_line = trim($first_line);
        $header .= $first_line."\n";

        if(!fwrite($fp,$header))
        {
            return false;
        }

        $footer = $this->get_footer();

        if(!fwrite($fp,$footer))
        {
            return false;
        }

        fclose($fp);

        return true;
     }
     
     function get_header()
     {
        return "<?PHP /* $this->signature_line";
     }

     function get_footer()
     {
        return "$this->signature_line */ ?>";
     }

    function gets_backward($fp)
    {
        $ret_str="";
        $t="";
        while ($t != "\n") 
        {
            if(0 != fseek($fp, $this->file_pos, SEEK_END))
            {
              rewind($fp);
              break;
            }
            $t = fgetc($fp);
            
            $ret_str = $t.$ret_str;
            $this->file_pos --;
        }
        return $ret_str;
    }

    function AppendLine($file_path,$insert_line)
    {
        $fp = fopen($file_path,"r+");

        if(!$fp)
        {
            return false;
        }
        $all_lines="";

        $this->file_pos = -1;
        fseek($fp,$this->file_pos,SEEK_END);
        

        while(1)
        {
            $pos = ftell($fp);
            if($pos <= 0)
            {
                break;
            }
            $line = $this->gets_backward($fp);
            $cmpline = trim($line);

            $all_lines .= $line;

            if(strcmp($cmpline,$this->get_footer())==0)
            {
              break;
            }
        }
        
        $all_lines = trim($all_lines);
        $insert_line = trim($insert_line);

        $all_lines = "$insert_line\n$all_lines";

        if(!fwrite($fp,$all_lines))
        {
            return false;
        }

        fclose($fp);
        return true;
    }

    function ReadNextLine($fp)
    {
        while(!feof($fp))
        {
            $line = fgets($fp);
            $line = trim($line);

            if(strcmp($line,$this->get_header())!=0 &&
               strcmp($line,$this->get_footer())!=0)
            {
                return $line;
            }
        }
        return "";
    }
}


/**
 * Crypt_Blowfish allows for encryption and decryption on the fly using
 * the Blowfish algorithm. Crypt_Blowfish does not require the MCrypt
 * PHP extension, but uses it if available, otherwise it uses only PHP.
 * Crypt_Blowfish supports encryption/decryption with or without a secret key.
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Encryption
 * @package    Crypt_Blowfish
 * @author     Matthew Fonda <mfonda@php.net>
 * @copyright  2005 Matthew Fonda
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    CVS: $Id: encr-lib.php,v 1.2 2010/02/16 06:51:02 Prasanth Exp $
 * @link       http://pear.php.net/package/Crypt_Blowfish
 */



/**
 * Engine choice constants
 */
/**
 * To let the Crypt_Blowfish package decide which engine to use
 * @since 1.1.0
 */
define('CRYPT_BLOWFISH_AUTO',   1);
/**
 * To use the MCrypt PHP extension.
 * @since 1.1.0
 */
define('CRYPT_BLOWFISH_MCRYPT', 2);
/**
 * To use the PHP-only engine.
 * @since 1.1.0
 */
define('CRYPT_BLOWFISH_PHP',    3);


/**
 * Example using the factory method in CBC mode
 * <code>
 * $bf =& Crypt_Blowfish::factory('cbc');
 * if (PEAR::isError($bf)) {
 *     echo $bf->getMessage();
 *     exit;
 * }
 * $iv = 'abc123+=';
 * $key = 'My secret key';
 * $bf->setKey($key, $iv);
 * $encrypted = $bf->encrypt('this is some example plain text');
 * $bf->setKey($key, $iv);
 * $plaintext = $bf->decrypt($encrypted);
 * if (PEAR::isError($plaintext)) {
 *     echo $plaintext->getMessage();
 *     exit;
 * }
 * // Encrypted text is padded prior to encryption
 * // so you may need to trim the decrypted result.
 * echo 'plain text: ' . trim($plaintext);
 * </code>
 *
 * To disable using the mcrypt library, define the CRYPT_BLOWFISH_NOMCRYPT
 * constant. This is useful for instance on Windows platform with a buggy
 * mdecrypt_generic() function.
 * <code>
 * define('CRYPT_BLOWFISH_NOMCRYPT', true);
 * </code>
 *
 * @category   Encryption
 * @package    Crypt_Blowfish
 * @author     Matthew Fonda <mfonda@php.net>
 * @author     Philippe Jausions <jausions@php.net>
 * @copyright  2005-2006 Matthew Fonda
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @link       http://pear.php.net/package/Crypt_Blowfish
 * @version    @package_version@
 * @access     public
 */
 
 define('CRYPT_BLOWFISH_NOMCRYPT', true);

class Crypt_Blowfish
{
    /**
     * Implementation-specific Crypt_Blowfish object
     *
     * @var object
     * @access private
     */
    var $_crypt = null;

    /**
     * Initialization vector
     *
     * @var string
     * @access protected
     */
    var $_iv = null;

    /**
     * Holds block size
     *
     * @var integer
     * @access protected
     */
    var $_block_size = 8;

    /**
     * Holds IV size
     *
     * @var integer
     * @access protected
     */
    var $_iv_size = 8;

    /**
     * Holds max key size
     *
     * @var integer
     * @access protected
     */
    var $_key_size = 56;

    /**
     * Crypt_Blowfish Constructor
     * Initializes the Crypt_Blowfish object (in EBC mode), and sets
     * the secret key
     *
     * @param string $key
     * @access public
     * @deprecated Since 1.1.0
     * @see Crypt_Blowfish::factory()
     */
    function __construct($key)
    {
        $this->_crypt =& Crypt_Blowfish::factory('ecb', $key);
        if (!PEAR::isError($this->_crypt)) {
            $this->_crypt->setKey($key);
        }
    }

    /**
     * Crypt_Blowfish object factory
     *
     * This is the recommended method to create a Crypt_Blowfish instance.
     *
     * When using CRYPT_BLOWFISH_AUTO, you can force the package to ignore
     * the MCrypt extension, by defining CRYPT_BLOWFISH_NOMCRYPT.
     *
     * @param string $mode operating mode 'ecb' or 'cbc' (case insensitive)
     * @param string $key
     * @param string $iv initialization vector (must be provided for CBC mode)
     * @param integer $engine one of CRYPT_BLOWFISH_AUTO, CRYPT_BLOWFISH_PHP
     *                or CRYPT_BLOWFISH_MCRYPT
     * @return object Crypt_Blowfish object or PEAR_Error object on error
     * @access public
     * @static
     * @since 1.1.0
     */
    public static function &factory($mode = 'ecb', $key = null, $iv = null, $engine = CRYPT_BLOWFISH_AUTO)
    {
        switch ($engine) {
            case CRYPT_BLOWFISH_AUTO:
                if (!defined('CRYPT_BLOWFISH_NOMCRYPT')
                    && extension_loaded('mcrypt')) {
                    $engine = CRYPT_BLOWFISH_MCRYPT;
                } else {
                    $engine = CRYPT_BLOWFISH_PHP;
                }
                break;
            case CRYPT_BLOWFISH_MCRYPT:
                if (!PEAR::loadExtension('mcrypt')) {
                    return PEAR::raiseError('MCrypt extension is not available.');
                }
                break;
        }

        switch ($engine) {
            case CRYPT_BLOWFISH_PHP:
                $mode = strtoupper($mode);
                $class = 'Crypt_Blowfish_' . $mode;
                
                $crypt = new $class(null);
                break;

            case CRYPT_BLOWFISH_MCRYPT:
                
                $crypt = new Crypt_Blowfish_MCrypt(null, $mode);
                break;
        }

        if (!is_null($key) || !is_null($iv)) {
            $result = $crypt->setKey($key, $iv);
            if (PEAR::isError($result)) {
                return $result;
            }
        }

        return $crypt;
    }

    /**
     * Returns the algorithm's block size
     *
     * @return integer
     * @access public
     * @since 1.1.0
     */
    function getBlockSize()
    {
        return $this->_block_size;
    }

    /**
     * Returns the algorithm's IV size
     *
     * @return integer
     * @access public
     * @since 1.1.0
     */
    function getIVSize()
    {
        return $this->_iv_size;
    }

    /**
     * Returns the algorithm's maximum key size
     *
     * @return integer
     * @access public
     * @since 1.1.0
     */
    function getMaxKeySize()
    {
        return $this->_key_size;
    }

    /**
     * Deprecated isReady method
     *
     * @return bool
     * @access public
     * @deprecated
     */
    function isReady()
    {
        return true;
    }

    /**
     * Deprecated init method - init is now a private
     * method and has been replaced with _init
     *
     * @return bool
     * @access public
     * @deprecated
     */
    function init()
    {
        return $this->_crypt->init();
    }

    /**
     * Encrypts a string
     *
     * Value is padded with NUL characters prior to encryption. You may
     * need to trim or cast the type when you decrypt.
     *
     * @param string $plainText the string of characters/bytes to encrypt
     * @return string|PEAR_Error Returns cipher text on success, PEAR_Error on failure
     * @access public
     */
    function encrypt($plainText)
    {
        return $this->_crypt->encrypt($plainText);
    }


    /**
     * Decrypts an encrypted string
     *
     * The value was padded with NUL characters when encrypted. You may
     * need to trim the result or cast its type.
     *
     * @param string $cipherText the binary string to decrypt
     * @return string|PEAR_Error Returns plain text on success, PEAR_Error on failure
     * @access public
     */
    function decrypt($cipherText)
    {
        return $this->_crypt->decrypt($cipherText);
    }

    /**
     * Sets the secret key
     * The key must be non-zero, and less than or equal to
     * 56 characters (bytes) in length.
     *
     * If you are making use of the PHP MCrypt extension, you must call this
     * method before each encrypt() and decrypt() call.
     *
     * @param string $key
     * @return boolean|PEAR_Error  Returns TRUE on success, PEAR_Error on failure
     * @access public
     */
    function setKey($key)
    {
        return $this->_crypt->setKey($key);
    }
}


/**
 * Crypt_Blowfish allows for encryption and decryption on the fly using
 * the Blowfish algorithm. Crypt_Blowfish does not require the mcrypt
 * PHP extension, but uses it if available, otherwise it uses only PHP.
 * Crypt_Blowfish support encryption/decryption with or without a secret key.
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Encryption
 * @package    Crypt_Blowfish
 * @author     Matthew Fonda <mfonda@php.net>
 * @author     Philippe Jausions <jausions@php.net>
 * @copyright  2005-2006 Matthew Fonda
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    CVS: $Id: encr-lib.php,v 1.2 2010/02/16 06:51:02 Prasanth Exp $
 * @link       http://pear.php.net/package/Crypt_Blowfish
 * @since      1.1.0
 */


/**
 * Common class for PHP-only implementations
 *
 * @category   Encryption
 * @package    Crypt_Blowfish
 * @author     Matthew Fonda <mfonda@php.net>
 * @author     Philippe Jausions <jausions@php.net>
 * @copyright  2005-2006 Matthew Fonda
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @link       http://pear.php.net/package/Crypt_Blowfish
 * @version    @package_version@
 * @access     public
 * @since      1.1.0
 */
class Crypt_Blowfish_PHP extends Crypt_Blowfish
{
    /**
     * P-Array contains 18 32-bit subkeys
     *
     * @var array
     * @access protected
     */
    var $_P = array();

    /**
     * Array of four S-Blocks each containing 256 32-bit entries
     *
     * @var array
     * @access protected
     */
    var $_S = array();

    /**
     * Whether the IV is required
     *
     * @var boolean
     * @access protected
     */
    var $_iv_required = false;
    
    /**
     * Hash value of last used key
     * 
     * @var     string
     * @access  protected
     */
    var $_keyHash = null;

    /**
     * Crypt_Blowfish_PHP Constructor
     * Initializes the Crypt_Blowfish object, and sets
     * the secret key
     *
     * @param string $key
     * @param string $mode operating mode 'ecb' or 'cbc'
     * @param string $iv initialization vector
     * @access protected
     */
    function x_construct($key = null, $iv = null)
    {
        $this->_iv = $iv . ((strlen($iv) < $this->_iv_size)
                            ? str_repeat(chr(0), $this->_iv_size - strlen($iv))
                            : '');
        if (!is_null($key)) {
            $this->setKey($key, $this->_iv);
        }
    }

    /**
     * Initializes the Crypt_Blowfish object
     *
     * @access private
     */
    function _init()
    {
        $defaults = new Crypt_Blowfish_DefaultKey();
        $this->_P = $defaults->P;
        $this->_S = $defaults->S;
    }

    /**
     * Workaround for XOR on certain systems
     *
     * @param integer|float $l
     * @param integer|float $r
     * @return float
     * @access protected
     */
    function _binxor($l, $r)
    {
        $x = (($l < 0) ? (float)($l + 4294967296) : (float)$l)
             ^ (($r < 0) ? (float)($r + 4294967296) : (float)$r);

        return (float)(($x < 0) ? $x + 4294967296 : $x);
    }

    /**
     * Enciphers a single 64-bit block
     *
     * @param int &$Xl
     * @param int &$Xr
     * @access protected
     */
    function _encipher(&$Xl, &$Xr)
    {
        if ($Xl < 0) {
            $Xl += 4294967296;
        }
        if ($Xr < 0) {
            $Xr += 4294967296;
        }

        for ($i = 0; $i < 16; $i++) {
            $temp = $Xl ^ $this->_P[$i];
            if ($temp < 0) {
                $temp += 4294967296;
            }

            $Xl = fmod((fmod($this->_S[0][($temp >> 24) & 255]
                             + $this->_S[1][($temp >> 16) & 255], 4294967296) 
                        ^ $this->_S[2][($temp >> 8) & 255]) 
                       + $this->_S[3][$temp & 255], 4294967296) ^ $Xr;
            $Xr = $temp;
        }
        $Xr = $this->_binxor($Xl, $this->_P[16]);
        $Xl = $this->_binxor($temp, $this->_P[17]);
    }

    /**
     * Deciphers a single 64-bit block
     *
     * @param int &$Xl
     * @param int &$Xr
     * @access protected
     */
    function _decipher(&$Xl, &$Xr)
    {
        if ($Xl < 0) {
            $Xl += 4294967296;
        }
        if ($Xr < 0) {
            $Xr += 4294967296;
        }

        for ($i = 17; $i > 1; $i--) {
            $temp = $Xl ^ $this->_P[$i];
            if ($temp < 0) {
                $temp += 4294967296;
            }

            $Xl = fmod((fmod($this->_S[0][($temp >> 24) & 255]
                             + $this->_S[1][($temp >> 16) & 255], 4294967296) 
                        ^ $this->_S[2][($temp >> 8) & 255]) 
                       + $this->_S[3][$temp & 255], 4294967296) ^ $Xr;
            $Xr = $temp;
        }
        $Xr = $this->_binxor($Xl, $this->_P[1]);
        $Xl = $this->_binxor($temp, $this->_P[0]);
    }

    /**
     * Sets the secret key
     * The key must be non-zero, and less than or equal to
     * 56 characters (bytes) in length.
     *
     * If you are making use of the PHP mcrypt extension, you must call this
     * method before each encrypt() and decrypt() call.
     *
     * @param string $key
     * @param string $iv 8-char initialization vector (required for CBC mode)
     * @return boolean|PEAR_Error  Returns TRUE on success, PEAR_Error on failure
     * @access public
     * @todo Fix the caching of the key
     */
    function setKey($key, $iv = null)
    {
        if (!is_string($key)) {
            return PEAR::raiseError('Key must be a string', 2);
        }

        $len = strlen($key);

        if ($len > $this->_key_size || $len == 0) {
            return PEAR::raiseError('Key must be less than ' . $this->_key_size . ' characters (bytes) and non-zero. Supplied key length: ' . $len, 3);
        }

        if ($this->_iv_required) {
            if (strlen($iv) != $this->_iv_size) {
                return PEAR::raiseError('IV must be ' . $this->_iv_size . '-character (byte) long. Supplied IV length: ' . strlen($iv), 7);
            }
            $this->_iv = $iv;
        }

        if ($this->_keyHash == md5($key)) {
            return true;
        }

        $this->_init();

        $k = 0;
        $data = 0;
        $datal = 0;
        $datar = 0;

        for ($i = 0; $i < 18; $i++) {
            $data = 0;
            for ($j = 4; $j > 0; $j--) {
                    $data = $data << 8 | ord($key[$k]);
                    $k = ($k+1) % $len;
            }
            $this->_P[$i] ^= $data;
        }

        for ($i = 0; $i <= 16; $i += 2) {
            $this->_encipher($datal, $datar);
            $this->_P[$i] = $datal;
            $this->_P[$i+1] = $datar;
        }
        for ($i = 0; $i < 256; $i += 2) {
            $this->_encipher($datal, $datar);
            $this->_S[0][$i] = $datal;
            $this->_S[0][$i+1] = $datar;
        }
        for ($i = 0; $i < 256; $i += 2) {
            $this->_encipher($datal, $datar);
            $this->_S[1][$i] = $datal;
            $this->_S[1][$i+1] = $datar;
        }
        for ($i = 0; $i < 256; $i += 2) {
            $this->_encipher($datal, $datar);
            $this->_S[2][$i] = $datal;
            $this->_S[2][$i+1] = $datar;
        }
        for ($i = 0; $i < 256; $i += 2) {
            $this->_encipher($datal, $datar);
            $this->_S[3][$i] = $datal;
            $this->_S[3][$i+1] = $datar;
        }

        $this->_keyHash = md5($key);
        return true;
    }
}

/**
 * PHP implementation of the Blowfish algorithm in ECB mode
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Encryption
 * @package    Crypt_Blowfish
 * @author     Matthew Fonda <mfonda@php.net>
 * @author     Philippe Jausions <jausions@php.net>
 * @copyright  2005-2006 Matthew Fonda
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    CVS: $Id: encr-lib.php,v 1.2 2010/02/16 06:51:02 Prasanth Exp $
 * @link       http://pear.php.net/package/Crypt_Blowfish
 * @since      1.1.0
 */


/**
 * Example
 * <code>
 * $bf =& Crypt_Blowfish::factory('ecb');
 * if (PEAR::isError($bf)) {
 *     echo $bf->getMessage();
 *     exit;
 * }
 * $bf->setKey('My secret key');
 * $encrypted = $bf->encrypt('this is some example plain text');
 * $plaintext = $bf->decrypt($encrypted);
 * echo "plain text: $plaintext";
 * </code>
 *
 * @category   Encryption
 * @package    Crypt_Blowfish
 * @author     Matthew Fonda <mfonda@php.net>
 * @author     Philippe Jausions <jausions@php.net>
 * @copyright  2005-2006 Matthew Fonda
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @link       http://pear.php.net/package/Crypt_Blowfish
 * @version    @package_version@
 * @access     public
 * @since      1.1.0
 */
class Crypt_Blowfish_ECB extends Crypt_Blowfish_PHP
{
    /**
     * Crypt_Blowfish Constructor
     * Initializes the Crypt_Blowfish object, and sets
     * the secret key
     *
     * @param string $key
     * @param string $iv initialization vector
     * @access public
     */
    function __construct($key = null, $iv = null)
    {
        $this->x_construct($key, $iv);
    }

    /**
     * Class constructor
     *
     * @param string $key
     * @param string $iv initialization vector
     * @access public
     */
    function x_construct($key = null, $iv = null)
    {
        $this->_iv_required = false;
        parent::x_construct($key, $iv);
    }

    /**
     * Encrypts a string
     *
     * Value is padded with NUL characters prior to encryption. You may
     * need to trim or cast the type when you decrypt.
     *
     * @param string $plainText string of characters/bytes to encrypt
     * @return string|PEAR_Error Returns cipher text on success, PEAR_Error on failure
     * @access public
     */
    function encrypt($plainText)
    {
        if (!is_string($plainText)) {
            return PEAR::raiseError('Input must be a string', 0);
        } elseif (empty($this->_P)) {
            return PEAR::raiseError('The key is not initialized.', 8);
        }

        $cipherText = '';
        $len = strlen($plainText);
        $plainText .= str_repeat(chr(0), (8 - ($len % 8)) % 8);

        for ($i = 0; $i < $len; $i += 8) {
            list(, $Xl, $Xr) = unpack('N2', substr($plainText, $i, 8));
            $this->_encipher($Xl, $Xr);
            $cipherText .= pack('N2', $Xl, $Xr);
        }

        return $cipherText;
    }

    /**
     * Decrypts an encrypted string
     *
     * The value was padded with NUL characters when encrypted. You may
     * need to trim the result or cast its type.
     *
     * @param string $cipherText
     * @return string|PEAR_Error Returns plain text on success, PEAR_Error on failure
     * @access public
     */
    function decrypt($cipherText)
    {
        if (!is_string($cipherText)) {
            return PEAR::raiseError('Cipher text must be a string', 1);
        }
        if (empty($this->_P)) {
            return PEAR::raiseError('The key is not initialized.', 8);
        }

        $plainText = '';
        $len = strlen($cipherText);
        $cipherText .= str_repeat(chr(0), (8 - ($len % 8)) % 8);

        for ($i = 0; $i < $len; $i += 8) {
            list(, $Xl, $Xr) = unpack('N2', substr($cipherText, $i, 8));
            $this->_decipher($Xl, $Xr);
            $plainText .= pack('N2', $Xl, $Xr);
        }

        return $plainText;
    }
}

class Crypt_Blowfish_DefaultKey
{
    var $P = array();
    
    var $S = array();
    
    function __construct()
    {
        $this->P = array(
            0x243f6a88, 0x85a308d3, 0x13198a2e, 0x03707344,
	        0xa4093822, 0x299f31d0, 0x082efa98, 0xec4e6c89,
	        0x452821e6, 0x38d01377, 0xbe5466cf, 0x34e90c6c,
	        0xc0ac29b7, 0xc97c50dd, 0x3f84d5b5, 0xb5470917,
	        0x9216d5d9, 0x8979fb1b
        );
        
        $this->S = array(
            array(
         0xd1310ba6, 0x98dfb5ac, 0x2ffd72db, 0xd01adfb7,
	     0xb8e1afed, 0x6a267e96, 0xba7c9045, 0xf12c7f99,
	     0x24a19947, 0xb3916cf7, 0x0801f2e2, 0x858efc16,
	     0x636920d8, 0x71574e69, 0xa458fea3, 0xf4933d7e,
	     0x0d95748f, 0x728eb658, 0x718bcd58, 0x82154aee,
	     0x7b54a41d, 0xc25a59b5, 0x9c30d539, 0x2af26013,
	     0xc5d1b023, 0x286085f0, 0xca417918, 0xb8db38ef,
	     0x8e79dcb0, 0x603a180e, 0x6c9e0e8b, 0xb01e8a3e,
	     0xd71577c1, 0xbd314b27, 0x78af2fda, 0x55605c60,
	     0xe65525f3, 0xaa55ab94, 0x57489862, 0x63e81440,
	     0x55ca396a, 0x2aab10b6, 0xb4cc5c34, 0x1141e8ce,
	     0xa15486af, 0x7c72e993, 0xb3ee1411, 0x636fbc2a,
	     0x2ba9c55d, 0x741831f6, 0xce5c3e16, 0x9b87931e,
	     0xafd6ba33, 0x6c24cf5c, 0x7a325381, 0x28958677,
	     0x3b8f4898, 0x6b4bb9af, 0xc4bfe81b, 0x66282193,
	     0x61d809cc, 0xfb21a991, 0x487cac60, 0x5dec8032,
	     0xef845d5d, 0xe98575b1, 0xdc262302, 0xeb651b88,
	     0x23893e81, 0xd396acc5, 0x0f6d6ff3, 0x83f44239,
	     0x2e0b4482, 0xa4842004, 0x69c8f04a, 0x9e1f9b5e,
	     0x21c66842, 0xf6e96c9a, 0x670c9c61, 0xabd388f0,
	     0x6a51a0d2, 0xd8542f68, 0x960fa728, 0xab5133a3,
	     0x6eef0b6c, 0x137a3be4, 0xba3bf050, 0x7efb2a98,
	     0xa1f1651d, 0x39af0176, 0x66ca593e, 0x82430e88,
	     0x8cee8619, 0x456f9fb4, 0x7d84a5c3, 0x3b8b5ebe,
	     0xe06f75d8, 0x85c12073, 0x401a449f, 0x56c16aa6,
	     0x4ed3aa62, 0x363f7706, 0x1bfedf72, 0x429b023d,
	     0x37d0d724, 0xd00a1248, 0xdb0fead3, 0x49f1c09b,
	     0x075372c9, 0x80991b7b, 0x25d479d8, 0xf6e8def7,
	     0xe3fe501a, 0xb6794c3b, 0x976ce0bd, 0x04c006ba,
	     0xc1a94fb6, 0x409f60c4, 0x5e5c9ec2, 0x196a2463,
	     0x68fb6faf, 0x3e6c53b5, 0x1339b2eb, 0x3b52ec6f,
	     0x6dfc511f, 0x9b30952c, 0xcc814544, 0xaf5ebd09,
	     0xbee3d004, 0xde334afd, 0x660f2807, 0x192e4bb3,
	     0xc0cba857, 0x45c8740f, 0xd20b5f39, 0xb9d3fbdb,
	     0x5579c0bd, 0x1a60320a, 0xd6a100c6, 0x402c7279,
	     0x679f25fe, 0xfb1fa3cc, 0x8ea5e9f8, 0xdb3222f8,
	     0x3c7516df, 0xfd616b15, 0x2f501ec8, 0xad0552ab,
	     0x323db5fa, 0xfd238760, 0x53317b48, 0x3e00df82,
	     0x9e5c57bb, 0xca6f8ca0, 0x1a87562e, 0xdf1769db,
	     0xd542a8f6, 0x287effc3, 0xac6732c6, 0x8c4f5573,
	     0x695b27b0, 0xbbca58c8, 0xe1ffa35d, 0xb8f011a0,
	     0x10fa3d98, 0xfd2183b8, 0x4afcb56c, 0x2dd1d35b,
	     0x9a53e479, 0xb6f84565, 0xd28e49bc, 0x4bfb9790,
	     0xe1ddf2da, 0xa4cb7e33, 0x62fb1341, 0xcee4c6e8,
	     0xef20cada, 0x36774c01, 0xd07e9efe, 0x2bf11fb4,
	     0x95dbda4d, 0xae909198, 0xeaad8e71, 0x6b93d5a0,
	     0xd08ed1d0, 0xafc725e0, 0x8e3c5b2f, 0x8e7594b7,
	     0x8ff6e2fb, 0xf2122b64, 0x8888b812, 0x900df01c,
	     0x4fad5ea0, 0x688fc31c, 0xd1cff191, 0xb3a8c1ad,
	     0x2f2f2218, 0xbe0e1777, 0xea752dfe, 0x8b021fa1,
	     0xe5a0cc0f, 0xb56f74e8, 0x18acf3d6, 0xce89e299,
	     0xb4a84fe0, 0xfd13e0b7, 0x7cc43b81, 0xd2ada8d9,
	     0x165fa266, 0x80957705, 0x93cc7314, 0x211a1477,
	     0xe6ad2065, 0x77b5fa86, 0xc75442f5, 0xfb9d35cf,
	     0xebcdaf0c, 0x7b3e89a0, 0xd6411bd3, 0xae1e7e49,
	     0x00250e2d, 0x2071b35e, 0x226800bb, 0x57b8e0af,
	     0x2464369b, 0xf009b91e, 0x5563911d, 0x59dfa6aa,
	     0x78c14389, 0xd95a537f, 0x207d5ba2, 0x02e5b9c5,
	     0x83260376, 0x6295cfa9, 0x11c81968, 0x4e734a41,
	     0xb3472dca, 0x7b14a94a, 0x1b510052, 0x9a532915,
	     0xd60f573f, 0xbc9bc6e4, 0x2b60a476, 0x81e67400,
	     0x08ba6fb5, 0x571be91f, 0xf296ec6b, 0x2a0dd915,
	     0xb6636521, 0xe7b9f9b6, 0xff34052e, 0xc5855664,
	     0x53b02d5d, 0xa99f8fa1, 0x08ba4799, 0x6e85076a
            ),
            array(
        0x4b7a70e9, 0xb5b32944, 0xdb75092e, 0xc4192623,
	     0xad6ea6b0, 0x49a7df7d, 0x9cee60b8, 0x8fedb266,
	     0xecaa8c71, 0x699a17ff, 0x5664526c, 0xc2b19ee1,
	     0x193602a5, 0x75094c29, 0xa0591340, 0xe4183a3e,
	     0x3f54989a, 0x5b429d65, 0x6b8fe4d6, 0x99f73fd6,
	     0xa1d29c07, 0xefe830f5, 0x4d2d38e6, 0xf0255dc1,
	     0x4cdd2086, 0x8470eb26, 0x6382e9c6, 0x021ecc5e,
	     0x09686b3f, 0x3ebaefc9, 0x3c971814, 0x6b6a70a1,
	     0x687f3584, 0x52a0e286, 0xb79c5305, 0xaa500737,
	     0x3e07841c, 0x7fdeae5c, 0x8e7d44ec, 0x5716f2b8,
	     0xb03ada37, 0xf0500c0d, 0xf01c1f04, 0x0200b3ff,
	     0xae0cf51a, 0x3cb574b2, 0x25837a58, 0xdc0921bd,
	     0xd19113f9, 0x7ca92ff6, 0x94324773, 0x22f54701,
	     0x3ae5e581, 0x37c2dadc, 0xc8b57634, 0x9af3dda7,
	     0xa9446146, 0x0fd0030e, 0xecc8c73e, 0xa4751e41,
	     0xe238cd99, 0x3bea0e2f, 0x3280bba1, 0x183eb331,
	     0x4e548b38, 0x4f6db908, 0x6f420d03, 0xf60a04bf,
	     0x2cb81290, 0x24977c79, 0x5679b072, 0xbcaf89af,
	     0xde9a771f, 0xd9930810, 0xb38bae12, 0xdccf3f2e,
	     0x5512721f, 0x2e6b7124, 0x501adde6, 0x9f84cd87,
	     0x7a584718, 0x7408da17, 0xbc9f9abc, 0xe94b7d8c,
	     0xec7aec3a, 0xdb851dfa, 0x63094366, 0xc464c3d2,
	     0xef1c1847, 0x3215d908, 0xdd433b37, 0x24c2ba16,
	     0x12a14d43, 0x2a65c451, 0x50940002, 0x133ae4dd,
	     0x71dff89e, 0x10314e55, 0x81ac77d6, 0x5f11199b,
	     0x043556f1, 0xd7a3c76b, 0x3c11183b, 0x5924a509,
	     0xf28fe6ed, 0x97f1fbfa, 0x9ebabf2c, 0x1e153c6e,
	     0x86e34570, 0xeae96fb1, 0x860e5e0a, 0x5a3e2ab3,
	     0x771fe71c, 0x4e3d06fa, 0x2965dcb9, 0x99e71d0f,
	     0x803e89d6, 0x5266c825, 0x2e4cc978, 0x9c10b36a,
	     0xc6150eba, 0x94e2ea78, 0xa5fc3c53, 0x1e0a2df4,
	     0xf2f74ea7, 0x361d2b3d, 0x1939260f, 0x19c27960,
	     0x5223a708, 0xf71312b6, 0xebadfe6e, 0xeac31f66,
	     0xe3bc4595, 0xa67bc883, 0xb17f37d1, 0x018cff28,
	     0xc332ddef, 0xbe6c5aa5, 0x65582185, 0x68ab9802,
	     0xeecea50f, 0xdb2f953b, 0x2aef7dad, 0x5b6e2f84,
	     0x1521b628, 0x29076170, 0xecdd4775, 0x619f1510,
	     0x13cca830, 0xeb61bd96, 0x0334fe1e, 0xaa0363cf,
	     0xb5735c90, 0x4c70a239, 0xd59e9e0b, 0xcbaade14,
	     0xeecc86bc, 0x60622ca7, 0x9cab5cab, 0xb2f3846e,
	     0x648b1eaf, 0x19bdf0ca, 0xa02369b9, 0x655abb50,
	     0x40685a32, 0x3c2ab4b3, 0x319ee9d5, 0xc021b8f7,
	     0x9b540b19, 0x875fa099, 0x95f7997e, 0x623d7da8,
	     0xf837889a, 0x97e32d77, 0x11ed935f, 0x16681281,
	     0x0e358829, 0xc7e61fd6, 0x96dedfa1, 0x7858ba99,
	     0x57f584a5, 0x1b227263, 0x9b83c3ff, 0x1ac24696,
	     0xcdb30aeb, 0x532e3054, 0x8fd948e4, 0x6dbc3128,
	     0x58ebf2ef, 0x34c6ffea, 0xfe28ed61, 0xee7c3c73,
	     0x5d4a14d9, 0xe864b7e3, 0x42105d14, 0x203e13e0,
	     0x45eee2b6, 0xa3aaabea, 0xdb6c4f15, 0xfacb4fd0,
	     0xc742f442, 0xef6abbb5, 0x654f3b1d, 0x41cd2105,
	     0xd81e799e, 0x86854dc7, 0xe44b476a, 0x3d816250,
	     0xcf62a1f2, 0x5b8d2646, 0xfc8883a0, 0xc1c7b6a3,
	     0x7f1524c3, 0x69cb7492, 0x47848a0b, 0x5692b285,
	     0x095bbf00, 0xad19489d, 0x1462b174, 0x23820e00,
	     0x58428d2a, 0x0c55f5ea, 0x1dadf43e, 0x233f7061,
	     0x3372f092, 0x8d937e41, 0xd65fecf1, 0x6c223bdb,
	     0x7cde3759, 0xcbee7460, 0x4085f2a7, 0xce77326e,
	     0xa6078084, 0x19f8509e, 0xe8efd855, 0x61d99735,
	     0xa969a7aa, 0xc50c06c2, 0x5a04abfc, 0x800bcadc,
	     0x9e447a2e, 0xc3453484, 0xfdd56705, 0x0e1e9ec9,
	     0xdb73dbd3, 0x105588cd, 0x675fda79, 0xe3674340,
	     0xc5c43465, 0x713e38d8, 0x3d28f89e, 0xf16dff20,
	     0x153e21e7, 0x8fb03d4a, 0xe6e39f2b, 0xdb83adf7
            ),
            array(
      0xe93d5a68, 0x948140f7, 0xf64c261c, 0x94692934,
	 0x411520f7, 0x7602d4f7, 0xbcf46b2e, 0xd4a20068,
	 0xd4082471, 0x3320f46a, 0x43b7d4b7, 0x500061af,
	 0x1e39f62e, 0x97244546, 0x14214f74, 0xbf8b8840,
	 0x4d95fc1d, 0x96b591af, 0x70f4ddd3, 0x66a02f45,
	 0xbfbc09ec, 0x03bd9785, 0x7fac6dd0, 0x31cb8504,
	 0x96eb27b3, 0x55fd3941, 0xda2547e6, 0xabca0a9a,
	 0x28507825, 0x530429f4, 0x0a2c86da, 0xe9b66dfb,
	 0x68dc1462, 0xd7486900, 0x680ec0a4, 0x27a18dee,
	 0x4f3ffea2, 0xe887ad8c, 0xb58ce006, 0x7af4d6b6,
	 0xaace1e7c, 0xd3375fec, 0xce78a399, 0x406b2a42,
	 0x20fe9e35, 0xd9f385b9, 0xee39d7ab, 0x3b124e8b,
	 0x1dc9faf7, 0x4b6d1856, 0x26a36631, 0xeae397b2,
	 0x3a6efa74, 0xdd5b4332, 0x6841e7f7, 0xca7820fb,
	 0xfb0af54e, 0xd8feb397, 0x454056ac, 0xba489527,
	 0x55533a3a, 0x20838d87, 0xfe6ba9b7, 0xd096954b,
	 0x55a867bc, 0xa1159a58, 0xcca92963, 0x99e1db33,
	 0xa62a4a56, 0x3f3125f9, 0x5ef47e1c, 0x9029317c,
	 0xfdf8e802, 0x04272f70, 0x80bb155c, 0x05282ce3,
	 0x95c11548, 0xe4c66d22, 0x48c1133f, 0xc70f86dc,
	 0x07f9c9ee, 0x41041f0f, 0x404779a4, 0x5d886e17,
	 0x325f51eb, 0xd59bc0d1, 0xf2bcc18f, 0x41113564,
	 0x257b7834, 0x602a9c60, 0xdff8e8a3, 0x1f636c1b,
	 0x0e12b4c2, 0x02e1329e, 0xaf664fd1, 0xcad18115,
	 0x6b2395e0, 0x333e92e1, 0x3b240b62, 0xeebeb922,
	 0x85b2a20e, 0xe6ba0d99, 0xde720c8c, 0x2da2f728,
	 0xd0127845, 0x95b794fd, 0x647d0862, 0xe7ccf5f0,
	 0x5449a36f, 0x877d48fa, 0xc39dfd27, 0xf33e8d1e,
	 0x0a476341, 0x992eff74, 0x3a6f6eab, 0xf4f8fd37,
	 0xa812dc60, 0xa1ebddf8, 0x991be14c, 0xdb6e6b0d,
	 0xc67b5510, 0x6d672c37, 0x2765d43b, 0xdcd0e804,
	 0xf1290dc7, 0xcc00ffa3, 0xb5390f92, 0x690fed0b,
	 0x667b9ffb, 0xcedb7d9c, 0xa091cf0b, 0xd9155ea3,
	 0xbb132f88, 0x515bad24, 0x7b9479bf, 0x763bd6eb,
	 0x37392eb3, 0xcc115979, 0x8026e297, 0xf42e312d,
	 0x6842ada7, 0xc66a2b3b, 0x12754ccc, 0x782ef11c,
	 0x6a124237, 0xb79251e7, 0x06a1bbe6, 0x4bfb6350,
	 0x1a6b1018, 0x11caedfa, 0x3d25bdd8, 0xe2e1c3c9,
	 0x44421659, 0x0a121386, 0xd90cec6e, 0xd5abea2a,
	 0x64af674e, 0xda86a85f, 0xbebfe988, 0x64e4c3fe,
	 0x9dbc8057, 0xf0f7c086, 0x60787bf8, 0x6003604d,
	 0xd1fd8346, 0xf6381fb0, 0x7745ae04, 0xd736fccc,
	 0x83426b33, 0xf01eab71, 0xb0804187, 0x3c005e5f,
	 0x77a057be, 0xbde8ae24, 0x55464299, 0xbf582e61,
	 0x4e58f48f, 0xf2ddfda2, 0xf474ef38, 0x8789bdc2,
	 0x5366f9c3, 0xc8b38e74, 0xb475f255, 0x46fcd9b9,
	 0x7aeb2661, 0x8b1ddf84, 0x846a0e79, 0x915f95e2,
	 0x466e598e, 0x20b45770, 0x8cd55591, 0xc902de4c,
	 0xb90bace1, 0xbb8205d0, 0x11a86248, 0x7574a99e,
	 0xb77f19b6, 0xe0a9dc09, 0x662d09a1, 0xc4324633,
	 0xe85a1f02, 0x09f0be8c, 0x4a99a025, 0x1d6efe10,
	 0x1ab93d1d, 0x0ba5a4df, 0xa186f20f, 0x2868f169,
	 0xdcb7da83, 0x573906fe, 0xa1e2ce9b, 0x4fcd7f52,
	 0x50115e01, 0xa70683fa, 0xa002b5c4, 0x0de6d027,
	 0x9af88c27, 0x773f8641, 0xc3604c06, 0x61a806b5,
	 0xf0177a28, 0xc0f586e0, 0x006058aa, 0x30dc7d62,
	 0x11e69ed7, 0x2338ea63, 0x53c2dd94, 0xc2c21634,
	 0xbbcbee56, 0x90bcb6de, 0xebfc7da1, 0xce591d76,
	 0x6f05e409, 0x4b7c0188, 0x39720a3d, 0x7c927c24,
	 0x86e3725f, 0x724d9db9, 0x1ac15bb4, 0xd39eb8fc,
	 0xed545578, 0x08fca5b5, 0xd83d7cd3, 0x4dad0fc4,
	 0x1e50ef5e, 0xb161e6f8, 0xa28514d9, 0x6c51133c,
	 0x6fd5c7e7, 0x56e14ec4, 0x362abfce, 0xddc6c837,
	 0xd79a3234, 0x92638212, 0x670efa8e, 0x406000e0
            ),
            array(
0x3a39ce37, 0xd3faf5cf, 0xabc27737, 0x5ac52d1b,
	 0x5cb0679e, 0x4fa33742, 0xd3822740, 0x99bc9bbe,
	 0xd5118e9d, 0xbf0f7315, 0xd62d1c7e, 0xc700c47b,
	 0xb78c1b6b, 0x21a19045, 0xb26eb1be, 0x6a366eb4,
	 0x5748ab2f, 0xbc946e79, 0xc6a376d2, 0x6549c2c8,
	 0x530ff8ee, 0x468dde7d, 0xd5730a1d, 0x4cd04dc6,
	 0x2939bbdb, 0xa9ba4650, 0xac9526e8, 0xbe5ee304,
	 0xa1fad5f0, 0x6a2d519a, 0x63ef8ce2, 0x9a86ee22,
	 0xc089c2b8, 0x43242ef6, 0xa51e03aa, 0x9cf2d0a4,
	 0x83c061ba, 0x9be96a4d, 0x8fe51550, 0xba645bd6,
	 0x2826a2f9, 0xa73a3ae1, 0x4ba99586, 0xef5562e9,
	 0xc72fefd3, 0xf752f7da, 0x3f046f69, 0x77fa0a59,
	 0x80e4a915, 0x87b08601, 0x9b09e6ad, 0x3b3ee593,
	 0xe990fd5a, 0x9e34d797, 0x2cf0b7d9, 0x022b8b51,
	 0x96d5ac3a, 0x017da67d, 0xd1cf3ed6, 0x7c7d2d28,
	 0x1f9f25cf, 0xadf2b89b, 0x5ad6b472, 0x5a88f54c,
	 0xe029ac71, 0xe019a5e6, 0x47b0acfd, 0xed93fa9b,
	 0xe8d3c48d, 0x283b57cc, 0xf8d56629, 0x79132e28,
	 0x785f0191, 0xed756055, 0xf7960e44, 0xe3d35e8c,
	 0x15056dd4, 0x88f46dba, 0x03a16125, 0x0564f0bd,
	 0xc3eb9e15, 0x3c9057a2, 0x97271aec, 0xa93a072a,
	 0x1b3f6d9b, 0x1e6321f5, 0xf59c66fb, 0x26dcf319,
	 0x7533d928, 0xb155fdf5, 0x03563482, 0x8aba3cbb,
	 0x28517711, 0xc20ad9f8, 0xabcc5167, 0xccad925f,
	 0x4de81751, 0x3830dc8e, 0x379d5862, 0x9320f991,
	 0xea7a90c2, 0xfb3e7bce, 0x5121ce64, 0x774fbe32,
	 0xa8b6e37e, 0xc3293d46, 0x48de5369, 0x6413e680,
	 0xa2ae0810, 0xdd6db224, 0x69852dfd, 0x09072166,
	 0xb39a460a, 0x6445c0dd, 0x586cdecf, 0x1c20c8ae,
	 0x5bbef7dd, 0x1b588d40, 0xccd2017f, 0x6bb4e3bb,
	 0xdda26a7e, 0x3a59ff45, 0x3e350a44, 0xbcb4cdd5,
	 0x72eacea8, 0xfa6484bb, 0x8d6612ae, 0xbf3c6f47,
	 0xd29be463, 0x542f5d9e, 0xaec2771b, 0xf64e6370,
	 0x740e0d8d, 0xe75b1357, 0xf8721671, 0xaf537d5d,
	 0x4040cb08, 0x4eb4e2cc, 0x34d2466a, 0x0115af84,
	 0xe1b00428, 0x95983a1d, 0x06b89fb4, 0xce6ea048,
	 0x6f3f3b82, 0x3520ab82, 0x011a1d4b, 0x277227f8,
	 0x611560b1, 0xe7933fdc, 0xbb3a792b, 0x344525bd,
	 0xa08839e1, 0x51ce794b, 0x2f32c9b7, 0xa01fbac9,
	 0xe01cc87e, 0xbcc7d1f6, 0xcf0111c3, 0xa1e8aac7,
	 0x1a908749, 0xd44fbd9a, 0xd0dadecb, 0xd50ada38,
	 0x0339c32a, 0xc6913667, 0x8df9317c, 0xe0b12b4f,
	 0xf79e59b7, 0x43f5bb3a, 0xf2d519ff, 0x27d9459c,
	 0xbf97222c, 0x15e6fc2a, 0x0f91fc71, 0x9b941525,
	 0xfae59361, 0xceb69ceb, 0xc2a86459, 0x12baa8d1,
	 0xb6c1075e, 0xe3056a0c, 0x10d25065, 0xcb03a442,
	 0xe0ec6e0e, 0x1698db3b, 0x4c98a0be, 0x3278e964,
	 0x9f1f9532, 0xe0d392df, 0xd3a0342b, 0x8971f21e,
	 0x1b0a7441, 0x4ba3348c, 0xc5be7120, 0xc37632d8,
	 0xdf359f8d, 0x9b992f2e, 0xe60b6f47, 0x0fe3f11d,
	 0xe54cda54, 0x1edad891, 0xce6279cf, 0xcd3e7e6f,
	 0x1618b166, 0xfd2c1d05, 0x848fd2c5, 0xf6fb2299,
	 0xf523f357, 0xa6327623, 0x93a83531, 0x56cccd02,
	 0xacf08162, 0x5a75ebb5, 0x6e163697, 0x88d273cc,
	 0xde966292, 0x81b949d0, 0x4c50901b, 0x71c65614,
	 0xe6c6c7bd, 0x327a140a, 0x45e1d006, 0xc3f27b9a,
	 0xc9aa53fd, 0x62a80f00, 0xbb25bfe2, 0x35bdd2f6,
	 0x71126905, 0xb2040222, 0xb6cbcf7c, 0xcd769c2b,
	 0x53113ec0, 0x1640e3d3, 0x38abbd60, 0x2547adf0,
	 0xba38209c, 0xf746ce76, 0x77afa1c5, 0x20756060,
	 0x85cbfe4e, 0x8ae88dd8, 0x7aaaf9b0, 0x4cf9aa7e,
	 0x1948c25c, 0x02fb8a8c, 0x01c36ae4, 0xd6ebe1f9,
	 0x90d4f869, 0xa65cdea0, 0x3f09252d, 0xc208e69f,
	 0xb74e6132, 0xce77e25b, 0x578fdfe3, 0x3ac372e6
            )
        );
    }
    
}


class FM_FormPageDisplayModule extends FM_Module
{
   private $validator_obj;
   private $uploader_obj;
   
   private $formdata_cookiname;
   private $formpage_cookiname;
   
   public function __construct()
   {
      parent::__construct();
      $this->formdata_cookiname = 'sfm_saved_form_data';
      $this->formpage_cookiname = 'sfm_saved_form_page_num';
   }

   function SetFormValidator(&$validator)
   {
      $this->validator_obj = &$validator;
   }
   
   function SetFileUploader(&$uploader)
   {
      $this->uploader_obj = &$uploader;
   }
   
   function getSerializer()
   {
        $tablename = 'sfm_'.substr($this->formname,0,32).'_saved';
        return new FM_SubmissionSerializer($this->config,$this->logger,$this->error_handler,$tablename);
   }
   
   function Process(&$continue)
   {
      $display_thankyou = true;

      $this->SaveCurrentPageToSession();
      
      if($this->NeedSaveAndClose())
      {
         $serializer = $this->getSerializer();
         
         $id = $serializer->SerializeToTable($this->SaveAllDataToArray());
         $this->AddToSerializedIDs($id);
         $id_encr = $this->ConvertID($id,/*encrypt*/true);
         
         $this->SaveFormDataToCookie($id_encr);
         
         $continue=false;
         $display_thankyou = false;
         $url = sfm_selfURL_abs().'?'.$this->config->reload_formvars_var.'='.$id_encr;
         $url ='<code>'.$url.'</code>';
         $msg = str_replace('{link}',$url,$this->config->saved_message_templ);
         echo $msg;
      }
      else
      if($this->NeedDisplayFormPage())
      {
         $this->DisplayFormPage($display_thankyou);
         $continue=false;
      }
      
      if($display_thankyou)
      {
         $this->LoadAllPageValuesFromSession($this->formvars,/*load files*/true,
                                          /*overwrite_existing*/false);
         $continue=true;
      }
   }
   
   function ConvertID($id,$encrypt)
   {
       $ret='';
       if($encrypt)
       {
            $ret = sfm_crypt_encrypt('x'.$id,$this->config->rand_key);
       }
       else
       {
            $ret = sfm_crypt_decrypt($id,$this->config->rand_key);
            $ret = str_replace('x','',$ret);
       }
       return $ret;
   }
   
   function Destroy()
   {
      if($this->globaldata->IsFormProcessingComplete())
      {
         $this->RemoveUnSerializedRows();
         
         $this->RemoveCookies();
      }
   }
    
   function NeedDisplayFormPage()
   {

      if($this->globaldata->IsButtonClicked('sfm_prev_page'))
      {
         return true;
      }
      elseif(false == isset($this->formvars[$this->config->form_submit_variable]))
      {
         return true;   
      }
      return false;
   }
      
   function NeedSaveAndClose()
   {
     if($this->globaldata->IsButtonClicked('sfm_save_n_close'))
     {
       return true;
     }
     return false;
   }


   
      
   function DisplayFormPage(&$display_thankyou)
   {
      $display_thankyou = false;
      
      $var_map = array();
      
      $var_map = array_merge($var_map,$this->config->element_info->default_values);
      
      $var_map[$this->config->error_display_variable]="";
      
      $this->LoadAllPageValuesFromSession($var_map,/*load files*/false,/*overwrite_existing*/true);
      

      
      $id_reload = $this->GetReloadFormID();
      if(false !== $id_reload)
      {
        $id = $id_reload;
        
        $this->AddToSerializedIDs($id);
        
        $serializer = $this->getSerializer();
        $all_formdata = array();
        $serializer->RecreateFromTable($id,$all_formdata,/*reset*/false);
        $this->LoadAllDataFromArray($all_formdata,$var_map,$page_num);
        
        $this->common_objs->formpage_renderer->DisplayFormPage($page_num,$var_map);
      }
      elseif($this->globaldata->IsButtonClicked('sfm_prev_page'))
      {
         $this->common_objs->formpage_renderer->DisplayPrevPage($var_map);
      }
      elseif($this->common_objs->formpage_renderer->IsPageNumSet())
      {
        if(isset($this->validator_obj) && 
        !$this->validator_obj->ValidateCurrentPage($var_map))
        {
            return false;
        }
         $this->logger->LogInfo("FormPageDisplayModule:  DisplayNextPage");
         $this->common_objs->formpage_renderer->DisplayNextPage($var_map,$display_thankyou);
      }
      else
      {//display the very first page
        $this->globaldata->RecordVariables();
        
        if($this->config->load_values_from_url)
        {
            $this->LoadValuesFromURL($var_map);
        }
        
        $this->logger->LogInfo("FormPageDisplayModule:  DisplayFirstPage");
        $this->common_objs->formpage_renderer->DisplayFirstPage($var_map);
      }
      return true;
   }
   
   function LoadValuesFromURL(&$varmap)
   {
        foreach($this->globaldata->get_vars as $gk => $gv)
        {
            
            if(!$this->config->element_info->IsElementPresent($gk))
            { continue; }
            
            $pagenum = $this->config->element_info->GetPageNum($gk);
            
            if($pagenum == 0)
            {
                $varmap[$gk] = $gv;
            }
            else
            {
                 $varname = $this->GetPageDataVarName($pagenum);
                 
                 if(empty($this->globaldata->session[$varname]))
                 {
                    $this->globaldata->session[$varname] = array();
                 }
                 $this->globaldata->session[$varname][$gk] = $gv;
            }
        }
   }
   function AddToSerializedIDs($id)
   {
        if(!isset($this->globaldata->session['sfm_serialized_ids']))
        {
            $this->globaldata->session['sfm_serialized_ids'] = array();
        }
        $this->globaldata->session['sfm_serialized_ids'][$id] = 'k';
   }
   
   function RemoveUnSerializedRows()
   {
        if(empty($this->globaldata->session['sfm_serialized_ids']))
        {
            return;
        }
        $serializer = $this->getSerializer();
        $serializer->Login();
        foreach($this->globaldata->session['sfm_serialized_ids'] as $id => $val)
        {
            $serializer->DeleteRow($id);
        }
        $serializer->Close();
   }
   
   function GetReloadFormID()
   {
        $id_encr='';
        if(!empty($_GET[$this->config->reload_formvars_var]))
        {
            $id_encr = $_GET[$this->config->reload_formvars_var];
        }
        elseif($this->IsFormReloadCookieSet())
        {
            $id_encr = $_COOKIE[$this->formdata_cookiname];
        }
        if(!empty($id_encr))
        {
            $id = $this->ConvertID($id_encr,false/*encrypt*/);
            return $id;
        }
        return false;
   }
         
   function IsFormReloadCookieSet()
   {
      if(!$this->common_objs->formpage_renderer->IsPageNumSet() &&
      isset($_COOKIE[$this->formdata_cookiname]) )
      {
         return true;
      }
      return false;
   }
   
   function RemoveControlVariables($session_varname)
   {
        $this->RemoveButtonVariableFromSession($session_varname,'sfm_prev_page');
        $this->RemoveButtonVariableFromSession($session_varname,'sfm_save_n_close');
        $this->RemoveButtonVariableFromSession($session_varname,'sfm_prev_page');
        $this->RemoveButtonVariableFromSession($session_varname,'sfm_confirm_edit');
        $this->RemoveButtonVariableFromSession($session_varname,'sfm_confirm');
   }
   
   function RemoveButtonVariableFromSession($sess_var,$varname)
   {
        unset($this->globaldata->session[$sess_var][$varname]);
        unset($this->globaldata->session[$sess_var][$varname."_x"]);
        unset($this->globaldata->session[$sess_var][$varname."_y"]);
   }
   
   function RemoveCookies()
   {
      if(isset($_COOKIE[$this->formdata_cookiname]))
      {
         sfm_clearcookie($this->formdata_cookiname);
         sfm_clearcookie($this->formpage_cookiname);
      }
   }

    function SaveAllDataToArray()
    {
        $all_formdata = $this->globaldata->session;
        $all_formdata['sfm_latest_page_num'] = $this->common_objs->formpage_renderer->GetCurrentPageNum();
        
        return $all_formdata;
    }
   function SaveFormDataToCookie($id_encr)
   {
      setcookie($this->formdata_cookiname,$id_encr,mktime()+(86400*30));
   }
   
   function LoadAllDataFromArray($all_formdata,&$var_map,&$page_num)
   {
      if(isset($all_formdata['sfm_latest_page_num']))
      {
         $page_num = intval($all_formdata['sfm_latest_page_num']);
      }
      else
      {
         $page_num =0;
      }
      unset($all_formdata['sfm_latest_page_num']);
      
      $this->globaldata->RecreateSessionValues($all_formdata);
      
      $this->LoadFormPageFromSession($var_map,$page_num);   
   }
   
   function LoadAllPageValuesFromSession(&$varmap,$load_files,$overwrite_existing=true)
   {
      if(!$this->common_objs->formpage_renderer->IsPageNumSet())
      {
         return;
      }

      $npages = $this->common_objs->formpage_renderer->GetNumPages();

      $this->logger->LogInfo("LoadAllPageValuesFromSession npages $npages");

      for($p=0; $p < $npages; $p++)
      {
         $varname = $this->GetPageDataVarName($p);
         if(isset($this->globaldata->session[$varname]))
         {
            if($overwrite_existing)
            {
               $varmap = array_merge($varmap,$this->globaldata->session[$varname]);
            }
            else
            {
               //Array union: donot overwrite values
               $varmap = $varmap + $this->globaldata->session[$varname]; 
            }
            
            if($load_files && isset($this->uploader_obj))
            {
               $this->uploader_obj->LoadFileListFromSession($varname);
            }
         }
      }//for
     
   }//function
   
   function LoadFormPageFromSession(&$var_map, $page_num)
   {
      $varname = $this->GetPageDataVarName($page_num);
      if(isset($this->globaldata->session[$varname]))
      {
         $var_map = array_merge($var_map,$this->globaldata->session[$varname]);
         $this->logger->LogInfo(" LoadFormPageFromSession  var_map ".var_export($var_map,TRUE));
      }
   }
   
 
   function SaveCurrentPageToSession()
   {
      if($this->common_objs->formpage_renderer->IsPageNumSet())
      {
         $page_num = $this->common_objs->formpage_renderer->GetCurrentPageNum();
         
         $varname = $this->GetPageDataVarName($page_num);
         
         $this->globaldata->session[$varname] = $this->formvars;
         
         $this->RemoveControlVariables($varname);
         
         if(isset($this->uploader_obj))
         {
            $this->uploader_obj->HandleNativeFileUpload();
         }
         
         $this->logger->LogInfo(" SaveCurrentPageToSession _SESSION(varname) "
         .var_export($this->globaldata->session[$varname],TRUE));
      }
   }
   
   function GetPageDataVarName($page_num)
   {
      return "sfm_form_page_".$page_num."_data";
   }

   function DisplayUsingTemplate(&$var_map)
   {
      $merge = new FM_PageMerger();
      if(false == $merge->Merge($this->config->form_page_code,$var_map))
      {
         $this->error_handler->HandleConfigError(_("Failed merging form page"));
         return false;
      }
      $strdisp = $merge->getMessageBody();
      echo $strdisp;
   }
}

function sfm_clearcookie( $inKey ) 
{
    setcookie( $inKey , '' , time()-3600 );
    unset( $_COOKIE[$inKey] );
} 


?>