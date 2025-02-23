<?php

namespace Northrook;

use Northrook\Debugger\{Bar\Bar,
    Bar\DefaultBarPanel,
    DeferredContent,
    ErrorHandler,
    Session\FileSession,
    Session\NativeSession,
    Session\SessionStorage
};
use ErrorException;
use RuntimeException;
use Throwable;
use Error;
use ReflectionClass;
use ReflectionProperty;
use ReflectionException;
use Stringable;
use function Support\getProjectDirectory;
use const Support\AUTO;

final class Debugger
{
    private const int               RESERVED_BYTES = 500_000;

    private const array             PHP_INI = [
        'display_errors'             => 0,
        'html_errors'                => 0,
        'log_errors'                 => 0,
        'zend.exception_ignore_args' => 0,
    ];

    /** determines whether any error will cause immediate death in development mode; if integer that it's matched against error severity */
    public static bool|int $strictMode = false;

    /** disables the @ (shut-up) operator so that notices and warnings are no longer hidden; if integer than it's matched against error severity */
    public static bool|int $scream = false;

    /** URI pattern mask to open editor */
    // jetbrains://php-storm/navigate/reference?project=${projectName}&path=${path}:${line}
    // public static ?string $editor = 'phpstorm://%action?file=%file&line=%line&search=%search&replace=%replace';
    public static ?string $editor = null;

    /** replacements in path */
    public static array $editorMapping = [];

    private static ?Debugger $debugger = null;

    /** @var null|string reserved memory; also prevents double rendering */
    protected ?string $reserved = null;

    /** @var string[] */
    protected array $customCss = [];

    protected int $obLevel;

    /** @var array<string, int|string> */
    protected array $obStatus;

    /** @var null|array<string, int> */
    protected ?array $cpuUsage;

    public readonly string $environment;

    /** timestamp with microseconds of the start of the request */
    public readonly float $time;

    public bool $production;

    /** @var bool */
    private bool $enabled;

    /**
     * @param string                                    $environment
     * @param string                                    $projectRootDirectory
     * @param callable[]                                $onFatalError
     * @param array|string[]                            $customCss
     * @param array<string, null|bool|float|int|string> $ini_set
     */
    public function __construct(
        string                 $environment,
        public readonly string $projectRootDirectory,
        protected array        $onFatalError = [],
        string|array           $customCss = [],
        array                  $ini_set = [],
    ) {
        $this->assignEnvironment( $environment );
        $this->reserveMemory( self::RESERVED_BYTES );
        $this->startTime();
        $this->obLevel ??= \ob_get_level();
        $this->cpuUsage();

        $this->phpConfig( $ini_set );

        \error_reporting( E_ALL );
        \register_shutdown_function( [self::class, 'shutdownHandler'] );
        \set_exception_handler(
            function( Throwable $throwable ) : void {
                Debugger::exceptionHandler( $throwable );

                exit( 255 );
            },
        );
        \set_error_handler( [self::class, 'errorHandler'] );

        $this->enabled = true;

        $this::$debugger = $this;

        $this->assignCustomCss( $customCss );
    }

    public static function time() : float
    {
        return self::$debugger->time;
    }

    public static function isEnabled( ?bool $set = null ) : bool
    {
        if ( ! isset( self::$debugger ) ) {
            return false;
        }
        if ( $set !== null ) {
            self::$debugger->enabled = $set;
        }
        return self::$debugger->enabled;
    }

    public static function getProjectRootDirectory() : string
    {
        return self::$debugger->projectRootDirectory ?? getProjectDirectory();
    }

    /**
     * Shutdown handler to catch fatal errors and execute of the planned activities.
     *
     * @internal
     */
    public static function shutdownHandler() : void
    {
        $error = \error_get_last();
        if ( \in_array(
            $error['type'] ?? null,
            [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR, E_USER_ERROR],
            true,
        ) ) {
            self::exceptionHandler(
                new ErrorException( $error['message'], 0, $error['type'], $error['file'], $error['line'] ),
            );
        }
        elseif ( E_COMPILE_WARNING === ( $error['type'] ?? null ) ) {
            \error_clear_last();
            self::errorHandler( $error['type'], $error['message'], $error['file'], $error['line'] );
        }

        self::debugger()->reserved = null;

        // if ( ! Helpers::isCli() ) {
        //     try {
        //         self::$debugger->handler()->renderBar();
        //     }
        //     catch ( Throwable $e ) {
        //         self::exceptionHandler( $e );
        //     }
        // }
    }

    private static function debugger() : Debugger
    {
        return self::$debugger ?? throw new RuntimeException(
            'The Debugger has not been started yet.',
        );
    }

    public static function getUserAgent() : string
    {
        \assert( $_SERVER['HTTP_USER_AGENT'] && \is_string( $_SERVER['HTTP_USER_AGENT'] ) );
        return $_SERVER['HTTP_USER_AGENT'];
    }

    /**
     * Handler to catch uncaught exception.
     *
     * @internal
     *
     * @param Throwable $exception
     */
    public static function exceptionHandler( Throwable $exception ) : void
    {
        $firstTime                 = (bool) self::debugger()->reserved;
        self::debugger()->reserved = null;
        self::debugger()->obStatus = \ob_get_status( true );

        if ( ! \headers_sent() ) {
            \http_response_code( \str_contains( Debugger::getUserAgent(), 'MSIE ' ) ? 503 : 500 );
        }

        self::improveException( $exception );
        self::removeOutputBuffers( true );

        self::handler()->handleException( $exception, $firstTime );

        try {
            foreach ( $firstTime ? self::debugger()->onFatalError : [] as $handler ) {
                $handler( $exception );
            }
        }
        catch ( Throwable $e ) {
            throw new RuntimeException( $exception->getMessage(), $exception->getCode(), $exception );
        }
    }

    /**
     * @internal
     */
    public static function getSessionStorage() : SessionStorage
    {
        static $sessionStorage;
        if ( empty( $sessionStorage ) ) {
            $sessionStorage = @\is_dir( $dir = \session_save_path() )
                              || @\is_dir( $dir = \ini_get( 'upload_tmp_dir' ) )
                              || @\is_dir( $dir = \sys_get_temp_dir() )
                // || ( $dir = self::$logDirectory )
                    ? new FileSession( $dir )
                    : new NativeSession();
        }

        return $sessionStorage;
    }

    /**
     * Handler to catch warnings and notices.
     *
     * @internal
     *
     * @param int    $severity
     * @param string $message
     * @param string $file
     * @param int    $line
     *
     * @return bool           false to call normal error handler, null otherwise
     * @throws ErrorException
     */
    public static function errorHandler(
        int    $severity,
        string $message,
        string $file,
        int    $line,
    ) : bool {
        $error = \error_get_last();
        if ( E_COMPILE_WARNING === ( $error['type'] ?? null ) ) {
            // compile-warning does not trigger the handler, so we are testing it now
            \error_clear_last();
            self::errorHandler( $error['type'], $error['message'], $error['file'], $error['line'] );
        }

        if ( $severity === E_RECOVERABLE_ERROR || $severity === E_USER_ERROR ) {
            throw new ErrorException( $message, 0, $severity, $file, $line );
        }
        if (
            ( $severity & \error_reporting() )
            || ( \is_int( self::$scream ) ? $severity & self::$scream : self::$scream )
        ) {
            self::handler()->handleError( $severity, $message, $file, $line );
        }

        return false; // calls normal error handler to fill-in error_get_last()
    }

    /**
     * @param array<string, null|bool|float|int|string> $ini_set
     *
     * @return void
     */
    private function phpConfig( array $ini_set ) : void
    {
        foreach ( \array_merge( Debugger::PHP_INI, $ini_set ) as $set => $value ) {
            \ini_set( $set, $value );
        }
    }

    /**
     * @param string                                    $environment
     * @param ?string                                   $projectRootDirectory [auto]
     * @param callable[]                                $onFatalError
     * @param string[]                                  $customCSS            Paths or raw `css`
     * @param array<string, null|bool|float|int|string> $ini_set
     *
     * @return Debugger
     */
    public static function enable(
        string       $environment = 'dev',
        ?string      $projectRootDirectory = AUTO,
        array        $onFatalError = [],
        string|array $customCSS = [],
        array        $ini_set = [],
    ) : Debugger {
        return Debugger::$debugger ?? new Debugger(
            $environment,
            $projectRootDirectory ?? getProjectDirectory(),
            $onFatalError,
            $customCSS,
            $ini_set,
        );
    }

    public function reserveMemory( int $bytes, bool $clear = false ) : void
    {
        if ( $clear ) {
            $this->reserved = null;
        }
        else {
            $this->reserved ??= \str_repeat( '_', $bytes );
        }
    }

    protected static function handler() : ErrorHandler
    {
        static $handler;
        if ( empty( $handler ) ) {
            // $bar = new Bar();
            // $bar->addPanel( $info = new DefaultBarPanel( 'info' ), 'Tracy:info' );
            // $info->cpuUsage = self::$debugger->cpuUsage();
            // $bar->addPanel( new DefaultBarPanel( 'errors' ), 'Tracy:errors' ); // filled by errorHandler()
            $handler = ( new ErrorHandler(
                // $bar,
                new DeferredContent( self::getSessionStorage() ),
            ) );
        }
        return $handler;
    }

    /**
     * @return null|array<string, int>
     */
    protected function cpuUsage() : ?array
    {
        if ( $this->production ) {
            return null;
        }

        return $this->cpuUsage = \getrusage() ?: null;
    }

    private function assignEnvironment( string $environment ) : void
    {
        $this->environment = \substr( \strtolower( $environment ), 0, 4 );
        \assert( \in_array( $environment, ['dev', 'test', 'prod'] ) );
        $this->production = $this->environment === 'prod';
    }

    private function startTime() : void
    {
        $time = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        $this->time = ( \is_float( $time ) ? $time : (float) \hrtime( true ) );
    }

    /**
     * @param string[] $customCss
     *
     * @return void
     */
    private function assignCustomCss( string|array $customCss ) : void
    {
        foreach ( (array) $customCss as $css ) {
            if ( \str_contains( $css, '{' ) && \str_contains( $css, '}' ) ) {
                $this->customCss[] = $css;
            }
            elseif ( \str_ends_with( $css, '.css' ) && \file_exists( $css ) ) {
                $this->customCss[] = \file_get_contents( $css )
                        ?: throw new RuntimeException( 'CSS File '.$css.' could not be read.' );
            }
            else {
                throw new RuntimeException( 'Invalid custom css: '.$css );
            }
        }
    }

    public static function isCli() : bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    public static function improveException( Throwable $e ) : void
    {
        $message = $e->getMessage();
        if (
            ! ( $e instanceof Error || $e instanceof ErrorException )
            || \str_contains( $e->getMessage(), 'did you mean' )
        ) {
            return;
        }
        if ( \preg_match(
            '~Argument #(\d+)(?: \(\$\w+\))? must be of type callable, (.+ given)~',
            $message,
            $m,
        ) ) {
            $arg = $e->getTrace()[0]['args'][$m[1] - 1] ?? null;
            if ( \is_string( $arg ) && \str_contains( $arg, '::' ) ) {
                $arg = \explode( '::', $arg, 2 );
            }
            if ( ! \is_callable( $arg, syntax_only : true ) ) {
                return;
            }
            if ( \is_array( $arg )
                 && \is_string( $arg[0] )
                 && ! \class_exists( $arg[0] )
                 && ! \trait_exists( $arg[0] )
            ) {
                $message = \str_replace( $m[2], "but class '{$arg[0]}' does not exist", $message );
            }
            elseif ( \is_array( $arg )
                     && ( \is_string( $arg[0] ) || \is_object( $arg[0] ) )
                     && \is_string( $arg[1] )
                     && ! \method_exists( $arg[0], $arg[1] ) ) {
                $hint    = self::getSuggestion( \get_class_methods( $arg[0] ) ?: [], $arg[1] );
                $class   = \is_object( $arg[0] ) ? \get_class( $arg[0] ) : $arg[0];
                $message = \str_replace(
                    $m[2],
                    "but method {$class}::{$arg[1]}() does not exist".( $hint ? " (did you mean {$hint}?)" : '' ),
                    $message,
                );
            }
            elseif ( \is_string( $arg ) && ! \function_exists( $arg ) ) {
                $funcs   = \array_merge( \get_defined_functions()['internal'], \get_defined_functions()['user'] );
                $hint    = self::getSuggestion( $funcs, $arg );
                $message = \str_replace(
                    $m[2],
                    "but function '{$arg}' does not exist".( $hint ? " (did you mean {$hint}?)" : '' ),
                    $message,
                );
            }
        }
        elseif ( \preg_match( '#^Call to undefined function (\S+\\\\)?(\w+)\(#', $message, $m ) ) {
            $funcs = \array_merge( \get_defined_functions()['internal'], \get_defined_functions()['user'] );
            if ( $hint = self::getSuggestion( $funcs, $m[1].$m[2] ) ?: self::getSuggestion( $funcs, $m[2] ) ) {
                $message = "Call to undefined function {$m[2]}(), did you mean {$hint}()?";
                $replace = ["{$m[2]}(", "{$hint}("];
            }
        }
        elseif ( \preg_match( '#^Call to undefined method ([\w\\\\]+)::(\w+)#', $message, $m ) ) {
            if ( $hint = self::getSuggestion( \get_class_methods( $m[1] ) ?: [], $m[2] ) ) {
                $message .= ", did you mean {$hint}()?";
                $replace = ["{$m[2]}(", "{$hint}("];
            }
        }
        elseif ( \preg_match( '#^Undefined property: ([\w\\\\]+)::\$(\w+)#', $message, $m ) ) {
            \assert( \class_exists( $m[1] ) );
            $rc    = new ReflectionClass( $m[1] );
            $items = \array_filter(
                $rc->getProperties( ReflectionProperty::IS_PUBLIC ),
                fn( $prop ) => ! $prop->isStatic(),
            );
            if ( $hint = self::getSuggestion( $items, $m[2] ) ) {
                $message .= ", did you mean \${$hint}?";
                $replace = ["->{$m[2]}", "->{$hint}"];
            }
        }
        elseif ( \preg_match( '#^Access to undeclared static property:? ([\w\\\\]+)::\$(\w+)#', $message, $m ) ) {
            \assert( \class_exists( $m[1] ) );
            $rc    = new ReflectionClass( $m[1] );
            $items = \array_filter(
                $rc->getProperties( ReflectionProperty::IS_STATIC ),
                fn( $prop ) => $prop->isPublic(),
            );
            if ( $hint = self::getSuggestion( $items, $m[2] ) ) {
                $message .= ", did you mean \${$hint}?";
                $replace = ["::\${$m[2]}", "::\${$hint}"];
            }
        }

        if ( $e->getMessage() !== $message ) {
            try {
                $ref = new ReflectionProperty( $e, 'message' );
                /** @noinspection PhpExpressionResultUnusedInspection */
                $ref->setAccessible( true );
                $ref->setValue( $e, $message );
            }
            catch ( ReflectionException $e ) {
                throw new RuntimeException( $e->getMessage() );
            }
        }
    }

    /**
     * Finds the best suggestion.
     *
     * @internal
     *
     * @param array<int, object|string|Stringable> $items
     * @param string                               $value
     *
     * @return null|string
     */
    public static function getSuggestion( array $items, string $value ) : ?string
    {
        $best  = null;
        $min   = ( \strlen( $value ) / 4 + 1 ) * 10 + .1;
        $items = \array_map(
            fn( $item ) => ( \is_object( $item ) && \method_exists( $item, 'getName' ) )
                        ? $item->getName()
                        : ( \is_object( $item ) ? $item::class : (string) $item ),
            $items,
        );

        foreach ( \array_unique( $items ) as $item ) {
            if ( ( $len = \levenshtein(
                $item,
                $value,
                10,
                11,
                10,
            ) ) > 0 && $len < $min ) {
                $min  = $len;
                $best = $item;
            }
        }

        return $best;
    }

    public static function removeOutputBuffers( bool $errorOccurred ) : void
    {
        while ( \ob_get_level() > self::debugger()->obLevel ) {
            $status = \ob_get_status();
            if ( \in_array( $status['name'], ['ob_gzhandler', 'zlib output compression'], true ) ) {
                break;
            }

            $fnc = $status['chunk_size'] || ! $errorOccurred
                    ? 'ob_end_flush'
                    : 'ob_end_clean';
            if ( ! @$fnc() ) { // @ may be not removable
                break;
            }
        }
    }

    public static function isHtmlMode() : bool
    {
        return empty( $_SERVER['HTTP_X_REQUESTED_WITH'] )
               && empty( $_SERVER['HTTP_X_TRACY_AJAX'] )
               && isset( $_SERVER['HTTP_HOST'] )
               && ! self::isCli()
               && ! \preg_match( '#^Content-Type: *+(?!text/html)#im', \implode( "\n", \headers_list() ) );
    }

    public static function isAjax() : bool
    {
        return isset( $_SERVER['HTTP_X_TRACY_AJAX'] ) && \preg_match(
            '#^\w{10,15}$#D',
            $_SERVER['HTTP_X_TRACY_AJAX'],
        );
    }

    public static function isRedirect() : bool
    {
        return (bool) \preg_match( '#^Location:#im', \implode( "\n", \headers_list() ) );
    }

    /**
     * Captures PHP output into a string.
     *
     * @param callable $func
     */
    public static function capture( callable $func ) : string
    {
        \ob_start( fn() => null );
        try {
            $func();
            return \ob_get_clean();
        }
        catch ( Throwable $e ) {
            \ob_end_clean();
            throw $e;
        }
    }

    /**
     * @internal
     */
    public static function getNonceAttr() : string
    {
        return \preg_match(
            '#^Content-Security-Policy(?:-Report-Only)?:.*\sscript-src\s+(?:[^;]+\s)?\'nonce-([\w+/]+=*)\'#mi',
            \implode( "\n", \headers_list() ),
            $m,
        )
                ? ' nonce="'.Helpers::escapeHtml( $m[1] ).'"'
                : '';
    }

    public static function formatHtml( string $mask ) : string
    {
        $args = \func_get_args();
        return \preg_replace_callback(
            '#%#',
            function() use ( &$args, &$count ) : string {
                return \str_replace( "\n", '&#10;', Helpers::escapeHtml( $args[++$count] ) );
            },
            $mask,
        );
    }

    public static function getExceptionChain( Throwable $ex ) : array
    {
        $res = [$ex];
        while ( ( $ex = $ex->getPrevious() ) && ! \in_array( $ex, $res, true ) ) {
            $res[] = $ex;
        }

        return $res;
    }

    /**
     * @internal
     */
    public static function getSource() : string
    {
        if ( self::isCli() ) {
            return 'CLI (PID: '.\getmypid().')'
                   .( isset( $_SERVER['argv'] ) ? ': '.\implode(
                       ' ',
                       \array_map(
                           [self::class, 'escapeArg'],
                           $_SERVER['argv'],
                       ),
                   ) : '' );
        }
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            return ( ! empty( $_SERVER['HTTPS'] ) && \strcasecmp( $_SERVER['HTTPS'], 'off' ) ? 'https://'
                            : 'http://' )
                   .( $_SERVER['HTTP_HOST'] ?? '' )
                   .$_SERVER['REQUEST_URI'];
        }

        return PHP_SAPI;
    }

    /**
     * @internal
     *
     * @param string $class
     */
    public static function guessClassFile( string $class ) : ?string
    {
        $segments = \explode( '\\', $class );
        $res      = null;
        $max      = 0;

        foreach ( \get_declared_classes() as $class ) {
            $parts = \explode( '\\', $class );

            foreach ( $parts as $i => $part ) {
                if ( $part !== ( $segments[$i] ?? null ) ) {
                    break;
                }
            }

            \assert( \class_exists( $class ) );
            if ( $i > $max && $i < \count( $segments )
                           && ( $file = ( new ReflectionClass( $class ) )->getFileName() )
            ) {
                $max = $i;
                $res = \array_merge(
                    \array_slice( \explode( DIRECTORY_SEPARATOR, $file ), 0, $i - \count( $parts ) ),
                    \array_slice( $segments, $i ),
                );
                $res = \implode( DIRECTORY_SEPARATOR, $res ).'.php';
            }
        }

        return $res;
    }

    /**
     * @param string $file
     * @param int    $line
     *
     * @return array{file: string, line: int, label: string, active: bool}
     */
    public static function mapSource( string $file, int $line ) : ?array
    {
        // foreach (static::debugger()->sourceMappers as $mapper) {
        //     if ($res = $mapper($file, $line)) {
        //         return $res;
        //     }
        // }

        return null;
    }

    public static function getEditorReferenceUrl() : string
    {
        if ( ! self::$editor ) {
            $projectRoot = getProjectDirectory();

            $projectName = \pathinfo( $projectRoot, PATHINFO_BASENAME );

            self::$editor = "jetbrains://php-storm/navigate/reference?project={$projectName}&path=/index.php:12";
            // public static ?string $editor = 'phpstorm://%action?file=%file&line=%line&search=%search&replace=%replace';

            // dd( $projectRoot, $projectName );
        }

        return self::$editor;
    }
}
