<?php

declare( strict_types = 1 );

namespace Northrook;

use Northrook\Clerk\ClerkEvent;
use Northrook\Interface\Singleton;
use Northrook\Trait\SingletonClass;
use Symfony\Component\Stopwatch\Stopwatch;


/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final class Clerk implements Singleton
{
    use SingletonClass;


    /** @var array<string, string[]> */
    protected array $groups = [];

    /** @var ClerkEvent[] */
    protected array $events = [];

    public readonly Stopwatch $stopwatch;

    public function __construct( ?Stopwatch $stopwatch = null, bool $throwOnFail = true )
    {
        $this->instantiationCheck( throw : $throwOnFail );
        $this->stopwatch = $stopwatch ?? new Stopwatch( true );

        $this->instantiateSingleton();
    }

    public static function event( string $name, ?string $group = null, bool $autoStart = true ) : ClerkEvent
    {
        return Clerk::getInstance( true )->getEvent( $name, $group, $autoStart );
    }

    public static function stop( string $name ) : void
    {
        Clerk::getInstance( true )->getEvent( $name, lap : false )->stop();
    }

    public static function stopGroup( string ...$name ) : void
    {
        Clerk::getInstance( true )->stopGroupEvents( ...$name );
    }

    /**
     * @return ClerkEvent[]
     */
    public function getEvents() : array
    {
        return $this->events;
    }

    /**
     * @internal
     *
     * @param null|string  $group
     * @param bool         $lap
     *
     * @param string       $name
     *
     * @return ClerkEvent
     */
    private function getEvent( string $name, ?string $group = null, bool $lap = true ) : ClerkEvent
    {
        if ( \array_key_exists( $name, $this->events ) ) {
            if ( $lap ) {
                $this->events[ $name ]->lap();
            }
            return $this->events[ $name ];
        }

        $event = new ClerkEvent( $this->stopwatch, $name, $group, $lap );

        if ( $group ) {
            $this->groups[ $group ][] = $name;
        }

        return $this->events[ $name ] = $event;
    }

    /**
     * @internal
     *
     * @param string  ...$group
     *
     * @return string[]
     */
    private function stopGroupEvents( string ...$group ) : array
    {
        $stopped = [];
        foreach ( $group as $name ) {
            foreach ( $this->groups[ $name ] ?? [] as $event ) {
                if ( \array_key_exists( $event, $this->events ) ) {
                    $this->events[ $event ]->stop();
                    $stopped[] = $event;
                }
            }
        }
        return $stopped;
    }
}