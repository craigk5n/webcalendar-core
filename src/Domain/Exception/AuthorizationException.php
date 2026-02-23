<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Exception;

final class AuthorizationException extends \RuntimeException
{
    public static function notOwner(string $resource, int $id, string $actor): self
    {
        return new self(sprintf(
            'User "%s" is not authorized to %s with ID %d',
            $actor,
            $resource,
            $id
        ));
    }

    public static function adminRequired(string $action): self
    {
        return new self(sprintf(
            'Admin privileges required to perform action: %s',
            $action
        ));
    }
}
