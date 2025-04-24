<?php

declare(strict_types=1);

namespace Core\Profiler;

use Throwable;

use Symfony\Component\Stopwatch\{Stopwatch, StopwatchEvent};
use function Support\str_start;

final class ClerkProfiler
{
    private static bool $disabled = false;

    /** @var array<string, array<string,bool>> */
    private array $events = [];

    protected ?string $category = null;

    public function __construct(
        public readonly Stopwatch $stopwatch,
        ?string                   $category = null,
    ) {
        $this->category = $this->category( $category );
    }

    public function event( string $name, ?string $category = null ) : ?StopwatchEvent
    {
        if ( self::$disabled ) {
            return null;
        }

        $category = $this->category( $category );
        $name     = $this->name( $name, $category );

        if ( $category ) {
            $this->events[$category][$name] ??= true;
        }

        return $this->stopwatch->start( $name, $category );
    }

    public function stop( ?string $name = null, ?string $category = null ) : void
    {
        if ( self::$disabled ) {
            return;
        }

        $category = $this->category( $category );

        if ( $name ) {
            $this->stopwatch->getEvent( $this->name( $name, $category ) )->ensureStopped();
        }

        if ( $category ) {
            foreach ( $this->events[$category] ?? [] as $event => $dummy ) {
                if ( ! $this->events[$category][$event] ) {
                    continue;
                }

                $this->events[$category][$event] = false;

                try {
                    $this->stopwatch->getEvent( $event )->ensureStopped();
                }
                catch ( Throwable ) {
                    continue;
                }
            }
        }
    }

    public static function from(
        null|Stopwatch|ClerkProfiler $profiler,
        ?string                      $category = null,
    ) : ?ClerkProfiler {
        if ( self::$disabled || $profiler === null ) {
            return null;
        }
        return $profiler instanceof Stopwatch
                ? new ClerkProfiler( $profiler, $category )
                : $profiler;
    }

    public static function isEnabled() : bool
    {
        return ! self::$disabled;
    }

    public static function disable() : void
    {
        self::$disabled = true;
    }

    public static function enable() : void
    {
        self::$disabled = false;
    }

    private function category( ?string $string ) : ?string
    {
        if ( ! $string ) {
            return $this->category;
        }

        $namespaced = \explode( '\\', $string );
        return \end( $namespaced );
    }

    private function name( string $string, ?string $category ) : string
    {
        if ( ! $category ) {
            return $string;
        }

        return \class_exists( $string, false )
                ? $string
                : str_start( $string, \rtrim( \strtolower( $category ), ' .' ).'.' );
    }
}
