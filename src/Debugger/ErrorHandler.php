<?php

namespace Northrook\Debugger;

use Northrook\{Debugger, Helpers};
use ErrorException;
use Throwable;

/** @internal */
final readonly class ErrorHandler
{
    public ExceptionView $view;

    public function __construct(
        private DeferredContent $defer,
    ) {
        $this->view = new ExceptionView();
    }

    public function handleException( Throwable $exception, bool $firstTime ) : void
    {
        if ( Helpers::isAjax() && $this->defer->isAvailable() ) {
            $this->view->renderToAjax( $exception, $this->defer );
        }
        elseif ( $firstTime && Helpers::isHtmlMode() ) {
            $this->view->render( $exception );
        }
        else {
            $this->renderExceptionCli( $exception );
        }
    }

    public function handleError(
        int    $severity,
        string $message,
        string $file,
        int    $line,
    ) : void {
        $oldDisplay = \ini_set( 'display_errors', '1' );

        if (
            ( \is_bool( Debugger::$strictMode ) ? Debugger::$strictMode
                    : ( Debugger::$strictMode & $severity ) ) // $strictMode
            && ! isset( $_GET['_tracy_skip_error'] )
        ) {
            $e = new ErrorException( $message, 0, $severity, $file, $line );
            /** @noinspection PhpDynamicFieldDeclarationInspection */
            @$e->skippable = true; // dynamic properties are deprecated since PHP 8.2
            Debugger::exceptionHandler( $e );

            exit( 255 );
        }

        // $message = Helpers::errorTypeToString( $severity ).': '.Helpers::improveError( $message );
        // $count   = &$this->bar->getPanel( 'Tracy:errors' )->data["{$file}|{$line}|{$message}"];
        //
        // if ( ! $count++ && ! Helpers::isHtmlMode() && ! Helpers::isAjax() ) {
        //     echo "\n{$message} in {$file} on line {$line}\n";
        // }

        \ini_set( 'display_errors', $oldDisplay );
    }

    public function renderBar() : void
    {
        // if (function_exists('ini_set')) {
        //     ini_set('display_errors', '1');
        // }
        //
        // $this->bar->render($this->defer);
    }

    public function sendAssets() : bool
    {
        return $this->defer->sendAssets();
    }

    public function renderLoader() : void
    {
        // $this->bar->renderLoader($this->defer);
    }

    private function renderExceptionCli( Throwable $exception ) : void
    {
        // try {
        //     $logFile = Debugger::log($exception, Debugger::EXCEPTION);
        // } catch (\Throwable $e) {
        //     echo "$exception\nTracy is unable to log error: {$e->getMessage()}\n";
        //     return;
        // }

        // if ($logFile && !headers_sent()) {
        //     header("X-Tracy-Error-Log: $logFile", replace: false);
        // }

        if ( Helpers::detectColors() && @\is_file( $exception->getFile() ) ) {
            echo "\n\n".CodeHighlighter::highlightPhpCli(
                \file_get_contents( $exception->getFile() )
                                    ?: __METHOD__.' unable to read exception file.',
                $exception->getLine(),
            )."\n";
        }

        // echo "{$exception}\n".( $logFile ? "\n(stored in {$logFile})\n" : '' );
        // if ( $logFile && Debugger::$browser ) {
        //     \exec( Debugger::$browser.' '.\escapeshellarg( \strtr( $logFile, Debugger::$editorMapping ) ) );
        // }
    }
}
