<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Exception;

use WebCalendar\Core\Domain\ValueObject\EventId;

/**
 * Exception thrown when an event cannot be found.
 */
final class EventNotFoundException extends \DomainException
{
    public static function forId(EventId $id): self
    {
        return new self(sprintf('Event with ID %d not found.', $id->value()));
    }
}
