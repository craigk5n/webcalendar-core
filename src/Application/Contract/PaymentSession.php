<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * A started checkout at the payment provider: the provider's stable
 * reference for the payment plus the URL the buyer completes it at.
 */
final class PaymentSession
{
    public function __construct(
        public readonly string $externalRef,
        public readonly string $checkoutUrl,
    ) {
        if ($this->externalRef === '') {
            throw new \InvalidArgumentException('Payment session needs an external reference.');
        }
    }
}
