<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Repository\TokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for security utilities (tokens, CSRF, sanitization).
 */
final readonly class SecurityService
{
    private LoggerInterface $logger;

    public function __construct(
        private string $secretKey,
        private TokenRepositoryInterface $tokenRepository,
        private int $sessionTtl = 86400,
        private int $csrfTtl = 3600,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Generates a session token for a user.
     *
     * @param string $login The user's login identifier
     * @return string The generated token
     */
    public function generateToken(string $login): string
    {
        $token = hash_hmac('sha256', bin2hex(random_bytes(32)), $this->secretKey);
        
        $this->tokenRepository->store(
            $token,
            'session',
            $login,
            $this->sessionTtl
        );

        $this->logger->debug('Generated session token', ['login' => $login]);
        
        return $token;
    }

    /**
     * Validates a session token.
     *
     * @param string $token The token to validate
     * @param string $login The expected user login
     * @return bool True if the token is valid
     */
    public function validateToken(string $token, string $login): bool
    {
        $valid = $this->tokenRepository->validate($token, 'session', $login);
        
        if (!$valid) {
            $this->logger->warning('Invalid session token', ['login' => $login]);
        }
        
        return $valid;
    }

    /**
     * Invalidates a session token.
     */
    public function invalidateToken(string $token): void
    {
        $this->tokenRepository->delete($token, 'session');
        $this->logger->debug('Invalidated session token');
    }

    /**
     * Invalidates all sessions for a user.
     */
    public function invalidateAllSessionsForUser(string $login): void
    {
        $this->tokenRepository->deleteByData('session', $login);
        $this->logger->info('Invalidated all sessions for user', ['login' => $login]);
    }

    /**
     * Generates a CSRF token.
     *
     * @param string $sessionId Optional session identifier to bind token to
     * @return string The generated CSRF token
     */
    public function generateCsrfToken(string $sessionId = ''): string
    {
        $token = bin2hex(random_bytes(32));
        
        $this->tokenRepository->store(
            $token,
            'csrf',
            $sessionId,
            $this->csrfTtl
        );

        $this->logger->debug('Generated CSRF token');
        
        return $token;
    }

    /**
     * Validates a CSRF token.
     *
     * @param string $token The token to validate
     * @param string $sessionId Optional session identifier to match
     * @return bool True if the token is valid
     */
    public function validateCsrfToken(string $token, string $sessionId = ''): bool
    {
        $valid = $this->tokenRepository->validate($token, 'csrf', $sessionId);

        if ($valid) {
            // Consume the token so it cannot be reused
            $this->tokenRepository->delete($token, 'csrf');
            $this->logger->debug('CSRF token consumed');
        } else {
            $this->logger->warning('Invalid CSRF token');
        }

        return $valid;
    }

    /**
     * Cleans up expired tokens.
     */
    public function cleanupExpiredTokens(): void
    {
        $this->tokenRepository->deleteExpired();
        $this->logger->debug('Cleaned up expired tokens');
    }

    /**
     * Sanitizes HTML content.
     */
    public function sanitizeHtml(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
