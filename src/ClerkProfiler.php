<?php

declare(strict_types=1);

namespace Core\Profiler;

use Throwable;

use Symfony\Component\Stopwatch\{Stopwatch, StopwatchEvent};

final class ClerkProfiler
{
    private static bool $disabled = false;

    /** @var array<string, array<string,bool>> */
    private array $events = [];

    protected ?string $group = null;

    public function __construct(
        private readonly Stopwatch $stopwatch,
        ?string                    $group = null,
    ) {
        $this->group = $this->category( $group );
    }

    private function category( ?string $string ) : ?string
    {
        if ( ! $string ) {
            return $this->group;
        }

        $namespaced = \explode( '\\', $string );
        return \end( $namespaced );
    }

    public function event( string $name, ?string $category = null ) : ?StopwatchEvent
    {
        if ( self::$disabled ) {
            return null;
        }

        $category = $this->category( $category );

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

        if ( $name ) {
            $this->stopwatch->getEvent( $name )->ensureStopped();
        }

        $category = $this->category( $category );

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
        ?string                      $group = null,
    ) : ?ClerkProfiler {
        if ( self::$disabled || $profiler === null ) {
            return null;
        }
        return $profiler instanceof Stopwatch
                ? new ClerkProfiler( $profiler, $group )
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
}
