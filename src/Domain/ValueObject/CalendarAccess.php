<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * One row of legacy's `webcal_access_user`: what $grantee may do with
 * $owner's calendar.
 *
 * The column names are worth restating, because they read backwards at a
 * glance.  `cal_login` is "the current user who is attempting to look at
 * another user's calendar" -- the grantee -- and `cal_other_user` is "the
 * login of the other user whose calendar the current user wants to access"
 * -- the owner.  The grant is stored against the person doing the looking.
 *
 * Either side may be the literal {@see self::DEFAULT_LOGIN}, which is how
 * legacy expresses "anyone" or "any calendar" in its fallback chain.
 */
final class CalendarAccess
{
    /**
     * Legacy's `__default__` wildcard, used on either side of a grant.
     */
    public const DEFAULT_LOGIN = '__default__';

    /**
     * Legacy's anonymous pseudo-user, used as the grantee for public access.
     */
    public const PUBLIC_LOGIN = '__public__';

    public function __construct(
        private readonly string $grantee,
        private readonly string $owner,
        private readonly CalendarPermission $view,
        private readonly CalendarPermission $edit,
        private readonly CalendarPermission $approve,
        private readonly bool $canInvite = true,
        private readonly bool $canEmail = true,
        private readonly bool $seeTimeOnly = false,
    ) {
        if (trim($this->grantee) === '') {
            throw new \InvalidArgumentException('Grantee login cannot be empty.');
        }
        if (trim($this->owner) === '') {
            throw new \InvalidArgumentException('Owner login cannot be empty.');
        }
    }

    /**
     * A grant conveying nothing, for the "no row matched" case.
     */
    public static function none(string $grantee, string $owner): self
    {
        return new self(
            $grantee,
            $owner,
            CalendarPermission::none(),
            CalendarPermission::none(),
            CalendarPermission::none(),
            canInvite: false,
            canEmail: false,
        );
    }

    /**
     * A grant conveying everything -- what an owner has over their own
     * calendar, and what an admin has over anyone's.
     */
    public static function full(string $grantee, string $owner): self
    {
        return new self(
            $grantee,
            $owner,
            CalendarPermission::all(),
            CalendarPermission::all(),
            CalendarPermission::all(),
        );
    }

    public function grantee(): string
    {
        return $this->grantee;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function view(): CalendarPermission
    {
        return $this->view;
    }

    public function edit(): CalendarPermission
    {
        return $this->edit;
    }

    public function approve(): CalendarPermission
    {
        return $this->approve;
    }

    public function canInvite(): bool
    {
        return $this->canInvite;
    }

    public function canEmail(): bool
    {
        return $this->canEmail;
    }

    /**
     * When set, the grantee may see that the owner is busy but not what the
     * entry is -- the free/busy reading of a calendar.
     */
    public function seeTimeOnly(): bool
    {
        return $this->seeTimeOnly;
    }

    public function canView(EventType $type, AccessLevel $access): bool
    {
        return $this->view->allows($type, $access);
    }

    public function canEdit(EventType $type, AccessLevel $access): bool
    {
        return $this->edit->allows($type, $access);
    }

    public function canApprove(EventType $type, AccessLevel $access): bool
    {
        return $this->approve->allows($type, $access);
    }

    /**
     * True when this row is a wildcard on either side.
     */
    public function isDefaultGrant(): bool
    {
        return $this->grantee === self::DEFAULT_LOGIN || $this->owner === self::DEFAULT_LOGIN;
    }
}
