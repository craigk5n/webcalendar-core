<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

use WebCalendar\Core\Domain\Entity\User;

/**
 * Who is asking, and therefore which entries a query may return.
 *
 * Read paths in this library used to express scoping as a pair of nullable
 * parameters, where passing neither meant "no access filter at all". That
 * default is why a public RSS feed, a report and a criteria search each
 * ended up returning every user's PRIVATE and CONFIDENTIAL entries: the
 * unrestricted query was what you got by leaving arguments out.
 *
 * This value object inverts that. There is no way to omit a scope, and the
 * unrestricted one has to be named {@see administrative()} -- which makes it
 * greppable, reviewable, and hard to reach by accident.
 *
 * The semantics match EventRepositoryInterface::findByDateRange():
 *
 * - {@see forUser()} -- public entries plus everything that user created;
 * - {@see publicOnly()} / {@see atAccessLevel()} -- one access level, for a
 *   caller with no identity;
 * - {@see administrative()} -- no access filter whatsoever.
 *
 * Any of them can be narrowed to particular creators with
 * {@see limitedToUsers()}, which is how a single calendar is selected.
 */
final class EventScope
{
    /**
     * @param string[]|null $users
     */
    private function __construct(
        private readonly ?User $user,
        private readonly ?string $accessLevel,
        private readonly ?array $users,
    ) {
    }

    /**
     * What a signed-in reader may see: public entries, plus their own at any
     * access level.
     */
    public static function forUser(User $user): self
    {
        return new self($user, null, null);
    }

    /**
     * Public entries only -- the scope a feed or an anonymous visitor gets.
     */
    public static function publicOnly(): self
    {
        return self::atAccessLevel(AccessLevel::PUBLIC);
    }

    /**
     * Entries at exactly one access level, for a caller with no identity.
     */
    public static function atAccessLevel(AccessLevel $level): self
    {
        return new self(null, $level->value, null);
    }

    /**
     * No access filter at all: every entry of every user, at every level.
     *
     * Correct for genuine administrative work and wrong for everything else.
     * It is spelled out rather than reachable by omission on purpose -- grep
     * for it when auditing what can read private entries.
     */
    public static function administrative(): self
    {
        return new self(null, null, null);
    }

    /**
     * Narrows any scope to entries created by the given logins.
     *
     * An empty list is treated as no restriction, matching
     * findByDateRange()'s handling of an empty $users array.
     *
     * @param string[] $logins
     */
    public function limitedToUsers(array $logins): self
    {
        return new self($this->user, $this->accessLevel, $logins === [] ? null : array_values($logins));
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function accessLevel(): ?string
    {
        return $this->accessLevel;
    }

    /**
     * @return string[]|null
     */
    public function users(): ?array
    {
        return $this->users;
    }

    /**
     * True when this scope applies no access filter.
     *
     * A creator restriction does not change the answer: limiting an
     * administrative scope to one calendar still returns that calendar's
     * private entries.
     */
    public function isAdministrative(): bool
    {
        return $this->user === null && $this->accessLevel === null;
    }
}
