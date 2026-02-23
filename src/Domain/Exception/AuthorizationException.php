<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Exception;

final class AuthorizationException extends \RuntimeException
{
    public static function notOwner(string $action, int $id, string $actor): self
    {
        return new self(sprintf(
            'User "%s" is not authorized to %s with ID %d',
            $actor,
            $action,
            $id
        ));
    }

    /**
     * Creates an exception for actions that require ownership identified by login.
     */
    public static function notSelf(string $action, string $targetLogin, string $actor): self
    {
        return new self(sprintf(
            'User "%s" is not authorized to %s for user "%s"',
            $actor,
            $action,
            $targetLogin
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
