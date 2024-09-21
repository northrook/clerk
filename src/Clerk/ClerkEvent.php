<?php

namespace Northrook\Symfony\Core\Telemetry\Clerk;

use Northrook\Logger\Log;
use Symfony\Component\Stopwatch\Stopwatch;


/**
 * @internal
 */
final readonly class ClerkEvent
{

    public function __construct(
            public string        $name,
            public ?string       $group = null,
            protected ?Stopwatch $stopwatch = null,
            bool                 $autoStart = true,
    )
    {
        if ( $autoStart ) {
            $this->start();
        }
    }

    /**
     * @param ?string  $log  language=Smarty
     * @param array    $context
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

    public function lap() : void
    {
        $this->stopwatch->lap( $this->name );
    }

    public function stop() : void
    {
        if ( !$this->isActive() ) {
            return;
        }
        $this->stopwatch->stop( $this->name );
    }

    public function isActive() : bool
    {
        return $this->stopwatch->isStarted( $this->name );
    }
}