<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

use WebCalendar\Core\Domain\Entity\TicketOrder;

/**
 * Contract for taking and refunding ticket payments — Epic 28. Core
 * never touches gateway SDKs: apps implement this against Stripe,
 * PayPal, WooCommerce or anything else (the EmailProviderInterface
 * pattern). Zero-commission by design — whatever the gateway charges
 * is between the site and its gateway.
 */
interface PaymentProviderInterface
{
    /**
     * Start a checkout for a pending order.
     *
     * @throws PaymentException If the provider refuses.
     */
    public function createPayment(TicketOrder $order): PaymentSession;

    /**
     * Whether the provider reports this payment as completed —
     * confirmation webhooks call this before the order flips to PAID
     * (the webhook payload alone is forgeable).
     *
     * @throws PaymentException If the reference is unknown.
     */
    public function isPaid(string $externalRef): bool;

    /**
     * Refund a completed payment in full.
     *
     * @throws PaymentException If the refund fails — the order then
     *         stays PAID.
     */
    public function refund(string $externalRef, int $amountMinor): void;
}
