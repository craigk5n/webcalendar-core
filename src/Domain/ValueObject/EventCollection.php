<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

use WebCalendar\Core\Domain\Entity\Event;

/**
 * Collection of Event entities.
 * 
 * @implements \IteratorAggregate<int, Event>
 */
final readonly class EventCollection implements \IteratorAggregate, \Countable
{
    /**
     * @param Event[] $events
     */
    public function __construct(
        private array $events = []
    ) {
    }

    /**
     * @return \Traversable<Event>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->events;
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * @return Event[]
     */
    public function all(): array
    {
        return $this->events;
    }
}
