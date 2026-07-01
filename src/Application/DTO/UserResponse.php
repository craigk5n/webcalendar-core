<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

use WebCalendar\Core\Domain\Entity\User;

/**
 * Data Transfer Object for User responses.
 */
final class UserResponse implements \JsonSerializable
{
    public function __construct(
        public readonly string $login,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly bool $isAdmin,
        public readonly bool $isEnabled
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self(
            login: $user->login(),
            firstName: $user->firstName(),
            lastName: $user->lastName(),
            email: $user->email(),
            isAdmin: $user->isAdmin(),
            isEnabled: $user->isEnabled()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'login' => $this->login,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'isAdmin' => $this->isAdmin,
            'isEnabled' => $this->isEnabled,
        ];
    }
}
