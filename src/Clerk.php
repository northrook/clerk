<?php

declare( strict_types = 1 );

namespace Northrook;

use Northrook\Clerk\ClerkEvent;
use Northrook\Trait\SingletonClass;
use Symfony\Component\Stopwatch\Stopwatch;


/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final class Clerk
{
    use SingletonClass;


    private static Clerk $instance;

    protected array $groups = [];
    protected array $events = [];

    public readonly ?Stopwatch $stopwatch;

    public function __construct(
            ?Stopwatch $stopwatch = null,
            bool       $throwOnFail = true,
    )
    {
        $this->instantiationCheck( throwOnFail : $throwOnFail );
        $this->stopwatch = $stopwatch ?? new Stopwatch( true );

        $this::$instance = $this;
    }

    public static function event( string $name, ?string $group = null, bool $autoStart = true ) : ClerkEvent
    {
        return Clerk::getInstance( true )->getEvent( $name, $group, $autoStart );
    }

    public static function stop( string $name ) : void
    {
        Clerk::getInstance( true )->getEvent( $name, autoStart : false )->stop();
    }

    public static function stopGroup( string ...$name ) : void
    {
        Clerk::getInstance( true )->stopGroupEvents( ...$name );
    }

    /**
     * @return array<ClerkEvent>
     */
    public function getEvents() : array
    {
        return $this->events;
    }

    // Internals

    /**
     * @param string       $name
     * @param null|string  $group
     * @param bool         $autoStart
     *
     * @return ClerkEvent
     */
    private function getEvent( string $name, ?string $group = null, bool $autoStart = true ) : ClerkEvent
    {
        if ( \array_key_exists( $name, $this->events ) ) {
            return $this->events[ $name ];
        }

        $event = new ClerkEvent( $name, $group, $this->stopwatch, $autoStart );

        if ( $group ) {
            $this->groups[ $group ][] = $name;
        }

        return $this->events[ $name ] = $event;
    }

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