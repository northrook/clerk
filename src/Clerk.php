<?php

declare(strict_types=1);

namespace Northrook;

use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use BadMethodCallException;

/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final class Clerk
{
    protected static bool $enabled = true;

    private static ?Clerk $instance = null;

    /** @var array<string, string[]> */
    protected array $groups = [];

    /** @var ClerkEvent[] */
    protected array $events = [];

    public readonly Stopwatch $stopwatch;

    public readonly LoggerInterface $logger;

    public function __construct(
        ?Stopwatch       $stopwatch = null,
        ?LoggerInterface $logger = null,
        bool             $enabled = true,
    ) {
        $this::$enabled = $enabled;

        if ( ! $this::$enabled ) {
            return;
        }

        $this->logger = $logger ?? new Logger();

        if ( $stopwatch && isset( $this::$instance->stopwatch ) ) {
            $this->logger->warning(
                __METHOD__.':: called repeatedly.',
                ['stopwatch' => $stopwatch, 'logger' => $logger],
            );
        }

        $this->stopwatch ??= $stopwatch ?? new Stopwatch( true );

        $this::$instance ??= $this;
    }

    public function enabled( ?bool $set = null ) : bool
    {
        return null === $set ? $this::$enabled : ( $this::$enabled = $set );
    }

    /**
     * Retrieve the active {@see Clerk} instance.
     *
     * @return Clerk
     */
    protected static function getInstance() : Clerk
    {
        return self::$instance ??= new Clerk();
    }

    public static function event( string $name, ?string $group = null, bool $autoStart = true ) : ?ClerkEvent
    {
        return Clerk::$enabled ? Clerk::getInstance()->getEvent( $name, $group, $autoStart ) : null;
    }

    public static function stop( string $name ) : void
    {
        if ( Clerk::$enabled ) {
            Clerk::getInstance()->getEvent( $name, lap : false )->stop();
        }
    }

    public static function stopGroup( string ...$name ) : void
    {
        if ( Clerk::$enabled ) {
            Clerk::getInstance()->stopGroupEvents( ...$name );
        }
    }

    /**
     * @return ClerkEvent[]
     */
    public function getEvents() : array
    {
        return $this->events;
    }

    public function reset( bool $areYouSure = false ) : void
    {
        if ( $areYouSure ) {
            $this::$instance = null;
        }
    }

    /**
     * @internal
     *
     * @param string      $name
     * @param null|string $group
     *
     * @param bool $lap
     *
     * @return ClerkEvent
     */
    private function getEvent( string $name, ?string $group = null, bool $lap = true ) : ClerkEvent
    {
        if ( \array_key_exists( $name, $this->events ) ) {
            if ( $lap ) {
                $this->events[$name]->lap();
            }
            return $this->events[$name];
        }

        $event = new ClerkEvent( $this->stopwatch, $name, $group, $lap );

        if ( $group ) {
            $this->groups[$group][] = $name;
        }

        return $this->events[$name] = $event;
    }

    /**
     * @internal
     *
     * @param string ...$group
     *
     * @return string[]
     */
    private function stopGroupEvents( string ...$group ) : array
    {
        $stopped = [];

        foreach ( $group as $name ) {
            foreach ( $this->groups[$name] ?? [] as $event ) {
                if ( \array_key_exists( $event, $this->events ) ) {
                    $this->events[$event]->stop();
                    $stopped[] = $event;
                }
            }
        }
        return $stopped;
    }

    public function __serialize() : array
    {
        throw $this->singletonBadMethodCallException( __METHOD__ );
    }

    /**
     * @param array<array-key, null> $data
     *
     * @return void
     */
    public function __unserialize( array $data ) : void
    {
        throw $this->singletonBadMethodCallException( __METHOD__ );
    }

    private function __clone() : void
    {
        throw $this->singletonBadMethodCallException( __METHOD__ );
    }

    public function __sleep() : array
    {
        throw $this->singletonBadMethodCallException( __METHOD__ );
    }

    public function __wakeup() : void
    {
        throw $this->singletonBadMethodCallException( __METHOD__ );
    }

    private function singletonBadMethodCallException( string $method ) : BadMethodCallException
    {
        return new BadMethodCallException(
            "Calling {$method} is not allowed, ".self::class.' is a Singleton.',
        );
    }
}
