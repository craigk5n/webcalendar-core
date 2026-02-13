<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

/**
 * Service for security utilities (tokens, CSRF, sanitization).
 */
final class SecurityService
{
    /** @var array<string, string> Simple in-memory storage for valid tokens [token => user] */
    private array $validTokens = [];

    public function __construct(
        private readonly string $secretKey
    ) {
    }

    /**
     * Generates a stateless session token for a user.
     */
    public function generateToken(string $login): string
    {
        $token = hash_hmac('sha256', bin2hex(random_bytes(32)), $this->secretKey);
        $this->validTokens[$token] = $login;
        
        return $token;
    }

    public function validateToken(string $token, string $login): bool
    {
        return isset($this->validTokens[$token]) && $this->validTokens[$token] === $login;
    }

    /**
     * Generates a CSRF token.
     */
    public function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(16));
        $this->validTokens['csrf_' . $token] = 'csrf_user';
        return $token;
    }

    public function validateCsrfToken(string $token): bool
    {
        return isset($this->validTokens['csrf_' . $token]);
    }

    /**
     * Sanitizes HTML content.
     */
    public function sanitizeHtml(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
