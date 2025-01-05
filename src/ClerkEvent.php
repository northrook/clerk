<?php

declare(strict_types=1);

namespace Northrook;

use Northrook\Logger\Log;
use Stringable;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Represents a {@see Stopwatch} event started by {@see Clerk}.
 *
 * - Tracks execution time for a named event.
 * - Can be categorized by group.
 * - Can automatically start and lap when called via {@see Clerk::event()}.
 *
 * @internal
 *
 * @author Martin Nielsen <mn@northrook.com>
 */
final readonly class ClerkEvent
{
    /**
     * The event starts automatically when created by default.
     *
     * @param Stopwatch $stopwatch
     * @param string    $name
     * @param ?string   $group
     * @param bool      $autoStart
     */
    public function __construct(
        private Stopwatch $stopwatch,
        public string     $name,
        public ?string    $group = null,
        bool              $autoStart = true,
    ) {
        if ( $autoStart ) {
            $this->start();
        }
    }

    /**
     * Start the stopwatch event, if it is not already active.
     *
     * Optionally logs a message.
     *
     * @param ?string                   $log     an optional log message
     * @param array<string, Stringable> $context optional context array for the log
     *
     * @return void
     */
    public function start( ?string $log = null, array $context = [] ) : void
    {
        if ( $this->isActive() ) {
            return;
        }

        if ( $log ) {
            Log::notice( $log );
        }

        $this->stopwatch->start( $this->name, $this->group );
    }

    /**
     * Records a lap for the event if it is active. If not, starts the event.
     *
     * @return void
     */
    public function lap() : void
    {
        match ( $this->isActive() ) {
            true  => $this->stopwatch->lap( $this->name ),
            false => $this->start(),
        };
    }

    /**
     * Stops the stopwatch event if it is currently active.
     *
     * @return void
     */
    public function stop() : void
    {
        if ( $this->isActive() ) {
            $this->stopwatch->stop( $this->name );
        }
    }

    /**
     * Checks whether the stopwatch event is currently running.
     *
     * @return bool
     */
    public function isActive() : bool
    {
        return $this->stopwatch->isStarted( $this->name );
    }
}
