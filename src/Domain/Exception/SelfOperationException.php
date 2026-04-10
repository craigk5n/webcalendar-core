<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Exception;

final class SelfOperationException extends \RuntimeException
{
    public static function cannotDeleteSelf(): self
    {
        return new self('Cannot delete your own account');
    }

    public static function cannotDisableSelf(): self
    {
        return new self('Cannot disable your own account');
    }
}
