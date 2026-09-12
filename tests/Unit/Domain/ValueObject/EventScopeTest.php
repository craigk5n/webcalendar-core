<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventScope;

final class EventScopeTest extends TestCase
{
    private function user(string $login = 'jdoe'): User
    {
        return new User($login, 'John', 'Doe', 'john@example.com', false, true);
    }

    public function testForUserCarriesTheUserAndNoAccessLevel(): void
    {
        $user = $this->user();
        $scope = EventScope::forUser($user);

        $this->assertSame($user, $scope->user());
        $this->assertNull($scope->accessLevel());
        $this->assertFalse($scope->isAdministrative());
    }

    public function testPublicOnlyCarriesThePublicLevelAndNoUser(): void
    {
        $scope = EventScope::publicOnly();

        $this->assertNull($scope->user());
        $this->assertSame('P', $scope->accessLevel());
        $this->assertFalse($scope->isAdministrative());
    }

    public function testAtAccessLevelCarriesThatLevel(): void
    {
        $this->assertSame('R', EventScope::atAccessLevel(AccessLevel::PRIVATE)->accessLevel());
        $this->assertSame('C', EventScope::atAccessLevel(AccessLevel::CONFIDENTIAL)->accessLevel());
    }

    /**
     * The unrestricted scope is the one that has to be asked for by name.
     */
    public function testAdministrativeCarriesNeitherUserNorLevel(): void
    {
        $scope = EventScope::administrative();

        $this->assertNull($scope->user());
        $this->assertNull($scope->accessLevel());
        $this->assertTrue($scope->isAdministrative());
    }

    public function testLimitedToUsersNarrowsWithoutChangingTheAccessRule(): void
    {
        $user = $this->user();
        $scope = EventScope::forUser($user)->limitedToUsers(['bsmith']);

        $this->assertSame($user, $scope->user());
        $this->assertSame(['bsmith'], $scope->users());
    }

    public function testLimitedToUsersLeavesTheOriginalScopeAlone(): void
    {
        $scope = EventScope::publicOnly();
        $narrowed = $scope->limitedToUsers(['bsmith']);

        $this->assertNull($scope->users(), 'the original must not be mutated');
        $this->assertSame(['bsmith'], $narrowed->users());
    }

    /**
     * findByDateRange() treats an empty $users array as no restriction; the
     * scope must not turn it into "created by nobody".
     */
    public function testAnEmptyUserListMeansNoRestriction(): void
    {
        $this->assertNull(EventScope::publicOnly()->limitedToUsers([])->users());
    }

    public function testUserListIsReindexed(): void
    {
        $sparse = [3 => 'carol', 7 => 'bsmith'];

        $this->assertSame(['carol', 'bsmith'], EventScope::publicOnly()->limitedToUsers($sparse)->users());
    }

    /**
     * Pinning an administrative scope to one calendar still reads that
     * calendar's private entries -- narrowing by creator is not an access
     * filter, and isAdministrative() must keep saying so.
     */
    public function testNarrowingAnAdministrativeScopeDoesNotMakeItRestricted(): void
    {
        $scope = EventScope::administrative()->limitedToUsers(['bsmith']);

        $this->assertTrue($scope->isAdministrative());
        $this->assertSame(['bsmith'], $scope->users());
    }
}
