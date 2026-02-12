<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

use WebCalendar\Core\Domain\Entity\User;

/**
 * Interface for sending emails.
 */
interface EmailProviderInterface
{
    /**
     * Sends an email.
     */
    public function send(string $to, string $subject, string $body): void;
}
