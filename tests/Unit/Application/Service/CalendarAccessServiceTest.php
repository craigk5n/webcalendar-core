<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\CalendarAccessService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\CalendarAccessRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\CalendarAccess;
use WebCalendar\Core\Domain\ValueObject\CalendarPermission;
use WebCalendar\Core\Domain\ValueObject\EventType;

final class CalendarAccessServiceTest extends TestCase
{
    /** @var CalendarAccessRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $repository;
    private CalendarAccessService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CalendarAccessRepositoryInterface::class);
        $this->service = new CalendarAccessService($this->repository);
    }

    private function user(string $login = 'jdoe', bool $isAdmin = false): User
    {
        return new User($login, 'John', 'Doe', "$login@example.com", $isAdmin, true);
    }

    private function grant(string $grantee, string $owner, int $view): CalendarAccess
    {
        return new CalendarAccess(
            $grantee,
            $owner,
            new CalendarPermission($view),
            CalendarPermission::none(),
            CalendarPermission::none()
        );
    }

    public function testAdminGetsFullAccessWithoutConsultingTheRepository(): void
    {
        $this->repository->expects($this->never())->method('find');

        $grant = $this->service->effectiveAccess($this->user('admin', true), 'bsmith');

        $this->assertTrue($grant->view()->isAll());
        $this->assertTrue($grant->canView(EventType::EVENT, AccessLevel::PRIVATE));
    }

    public function testOwnCalendarIsFullAccessWithoutConsultingTheRepository(): void
    {
        $this->repository->expects($this->never())->method('find');

        $grant = $this->service->effectiveAccess($this->user('jdoe'), 'jdoe');

        $this->assertTrue($grant->view()->isAll());
    }

    public function testUnmatchedPairConveysNothingRatherThanNull(): void
    {
        $this->repository->method('find')->willReturn(null);

        $grant = $this->service->effectiveAccess($this->user('jdoe'), 'bsmith');

        $this->assertTrue($grant->view()->isNone());
        $this->assertFalse($grant->canView(EventType::EVENT, AccessLevel::PUBLIC));
    }

    /**
     * Legacy consults four keys in a fixed order and the first hit wins
     * outright. Installations depend on that order, so it is pinned here.
     */
    public function testChainIsConsultedMostSpecificFirst(): void
    {
        $seen = [];
        $this->repository->method('find')
            ->willReturnCallback(function (string $grantee, string $owner) use (&$seen) {
                $seen[] = "$grantee->$owner";
                return null;
            });

        $this->service->effectiveAccess($this->user('jdoe'), 'bsmith');

        $this->assertSame(
            [
                'jdoe->bsmith',
                'jdoe->__default__',
                '__default__->bsmith',
                '__default__->__default__',
            ],
            $seen
        );
    }

    public function testSpecificGrantShortCircuitsTheRestOfTheChain(): void
    {
        $this->repository->expects($this->once())
            ->method('find')
            ->with('jdoe', 'bsmith')
            ->willReturn($this->grant('jdoe', 'bsmith', 73));

        $grant = $this->service->effectiveAccess($this->user('jdoe'), 'bsmith');

        $this->assertSame(73, $grant->view()->value());
    }

    /**
     * A specific grant wins even when it conveys less than a wildcard that
     * would have matched later -- first hit wins, permissions do not merge.
     */
    public function testASpecificGrantIsNotWidenedByALaterWildcard(): void
    {
        $this->repository->method('find')
            ->willReturnCallback(function (string $grantee, string $owner) {
                if ($grantee === 'jdoe' && $owner === 'bsmith') {
                    return $this->grant('jdoe', 'bsmith', CalendarPermission::NONE);
                }
                return $this->grant(CalendarAccess::DEFAULT_LOGIN, CalendarAccess::DEFAULT_LOGIN, CalendarPermission::ALL);
            });

        $grant = $this->service->effectiveAccess($this->user('jdoe'), 'bsmith');

        $this->assertTrue($grant->view()->isNone(), 'site-wide default must not widen a specific denial');
    }

    /**
     * @dataProvider wildcardPositions
     */
    public function testEachWildcardKeyIsHonouredWhenItIsTheOnlyMatch(string $grantee, string $owner): void
    {
        $this->repository->method('find')
            ->willReturnCallback(function (string $g, string $o) use ($grantee, $owner) {
                return ($g === $grantee && $o === $owner)
                    ? $this->grant($g, $o, 511)
                    : null;
            });

        $grant = $this->service->effectiveAccess($this->user('jdoe'), 'bsmith');

        $this->assertTrue($grant->view()->isAll());
    }

    /** @return array<string, string[]> */
    public static function wildcardPositions(): array
    {
        return [
            'specific pair'      => ['jdoe', 'bsmith'],
            'this user, any cal' => ['jdoe', CalendarAccess::DEFAULT_LOGIN],
            'anyone, this cal'   => [CalendarAccess::DEFAULT_LOGIN, 'bsmith'],
            'site-wide default'  => [CalendarAccess::DEFAULT_LOGIN, CalendarAccess::DEFAULT_LOGIN],
        ];
    }

    public function testCanViewAppliesTheGrantsTypeAndLevelBits(): void
    {
        // Events at every level, no tasks or journals.
        $this->repository->method('find')->willReturn($this->grant('jdoe', 'bsmith', 73));
        $actor = $this->user('jdoe');

        $this->assertTrue($this->service->canView($actor, 'bsmith', EventType::EVENT, AccessLevel::PRIVATE));
        $this->assertFalse($this->service->canView($actor, 'bsmith', EventType::TASK, AccessLevel::PUBLIC));
    }

    public function testAnonymousCallersResolveWithoutAdminShortCircuit(): void
    {
        $this->repository->method('find')
            ->willReturnCallback(fn (string $g, string $o) => $g === CalendarAccess::PUBLIC_LOGIN
                ? $this->grant($g, $o, 7)
                : null);

        $grant = $this->service->accessFor(CalendarAccess::PUBLIC_LOGIN, 'bsmith');

        $this->assertTrue($grant->canView(EventType::EVENT, AccessLevel::PUBLIC));
        $this->assertFalse($grant->canView(EventType::EVENT, AccessLevel::PRIVATE));
    }
}
