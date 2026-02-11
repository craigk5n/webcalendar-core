<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing a User.
 */
final readonly class User
{
    /**
     * @param string $login Unique login identifier.
     * @param string $firstName User's first name.
     * @param string $lastName User's last name.
     * @param string $email User's email address.
     * @param bool $isAdmin Whether the user has administrative privileges.
     * @param bool $isEnabled Whether the user account is active.
     * @throws \InvalidArgumentException If login is empty or email is invalid.
     */
    public function __construct(
        private string $login,
        private string $firstName,
        private string $lastName,
        private string $email,
        private bool $isAdmin,
        private bool $isEnabled
    ) {
        if (empty(trim($this->login))) {
            throw new \InvalidArgumentException('Login cannot be empty.');
        }

        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address.');
        }
    }

    public function login(): string
    {
        return $this->login;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
