<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\SearchCriteria;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * Access scoping on the two search surfaces, against the shipped schema.
 *
 * searchByCriteria() previously had no access filter of any kind and no way
 * to be given one, so it returned every user's PRIVATE and CONFIDENTIAL
 * entries to any caller. search() had one, but reached the same unfiltered
 * state by being called with its arguments left out.
 */
final class PdoEventSearchScopeTest extends RepositoryTestCase
{
    private PdoEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoEventRepository($this->pdo);

        $date = new \DateTimeImmutable('2026-02-11 10:00:00');
        $rows = [
            ['jdoe-public', 'Standup meeting', 'jdoe', AccessLevel::PUBLIC],
            ['jdoe-private', 'Therapy meeting', 'jdoe', AccessLevel::PRIVATE],
            ['jdoe-conf', 'Salary meeting', 'jdoe', AccessLevel::CONFIDENTIAL],
            ['bsmith-public', 'All hands meeting', 'bsmith', AccessLevel::PUBLIC],
            ['bsmith-private', 'Divorce meeting', 'bsmith', AccessLevel::PRIVATE],
        ];

        foreach ($rows as [$uid, $name, $owner, $access]) {
            $this->repository->save(new Event(
                new EventId(0),
                $uid,
                $name,
                '',
                '',
                $date,
                60,
                $owner,
                EventType::EVENT,
                $access
            ));
        }
    }

    private function user(string $login): User
    {
        return new User($login, 'Test', 'User', "$login@example.com", false, true);
    }

    /**
     * @param iterable<Event> $events
     * @return string[]
     */
    private function namesOf(iterable $events): array
    {
        $names = [];
        foreach ($events as $event) {
            $names[] = $event->name();
        }
        sort($names);
        return $names;
    }

    private function criteria(): SearchCriteria
    {
        return new SearchCriteria(keyword: 'meeting');
    }

    // -- searchByCriteria -------------------------------------------------

    /**
     * The regression this whole change exists for.
     */
    public function testCriteriaSearchAsAUserHidesOtherPeoplesPrivateEntries(): void
    {
        $found = $this->repository->searchByCriteria(
            $this->criteria(),
            EventScope::forUser($this->user('jdoe'))
        );

        // Own entries at any level, plus other people's public ones.
        $this->assertSame(
            ['All hands meeting', 'Salary meeting', 'Standup meeting', 'Therapy meeting'],
            $this->namesOf($found)
        );
        $this->assertNotContains('Divorce meeting', $this->namesOf($found));
    }

    public function testCriteriaSearchPublicOnlyReturnsOnlyPublicEntries(): void
    {
        $found = $this->repository->searchByCriteria($this->criteria(), EventScope::publicOnly());

        $this->assertSame(['All hands meeting', 'Standup meeting'], $this->namesOf($found));
    }

    public function testCriteriaSearchAdministrativeStillSeesEverything(): void
    {
        $found = $this->repository->searchByCriteria($this->criteria(), EventScope::administrative());

        $this->assertCount(5, $this->namesOf($found));
    }

    public function testCriteriaSearchCanBePinnedToOneCalendar(): void
    {
        $found = $this->repository->searchByCriteria(
            $this->criteria(),
            EventScope::publicOnly()->limitedToUsers(['bsmith'])
        );

        $this->assertSame(['All hands meeting'], $this->namesOf($found));
    }

    /**
     * Pinning to a calendar is not an access filter: an administrative scope
     * narrowed to one user still reads that user's private entries.
     */
    public function testPinningAnAdministrativeScopeStillReadsPrivateEntries(): void
    {
        $found = $this->repository->searchByCriteria(
            $this->criteria(),
            EventScope::administrative()->limitedToUsers(['bsmith'])
        );

        $this->assertSame(['All hands meeting', 'Divorce meeting'], $this->namesOf($found));
    }

    public function testCriteriaSearchScopeSurvivesOtherFilters(): void
    {
        // Scope must AND with the criteria's own filters, not replace them.
        $found = $this->repository->searchByCriteria(
            new SearchCriteria(keyword: 'Divorce'),
            EventScope::forUser($this->user('jdoe'))
        );

        $this->assertSame([], $this->namesOf($found), "another user's private entry stays hidden");
    }

    // -- search -----------------------------------------------------------

    public function testKeywordSearchAsAUserHidesOtherPeoplesPrivateEntries(): void
    {
        $found = $this->repository->search('meeting', EventScope::forUser($this->user('jdoe')));

        $this->assertSame(
            ['All hands meeting', 'Salary meeting', 'Standup meeting', 'Therapy meeting'],
            $this->namesOf($found)
        );
    }

    public function testKeywordSearchPublicOnly(): void
    {
        $found = $this->repository->search('meeting', EventScope::publicOnly());

        $this->assertSame(['All hands meeting', 'Standup meeting'], $this->namesOf($found));
    }

    public function testKeywordSearchAdministrativeSeesEverything(): void
    {
        $found = $this->repository->search('meeting', EventScope::administrative());

        $this->assertCount(5, $this->namesOf($found));
    }

    public function testKeywordSearchCanBePinnedToOneCalendar(): void
    {
        $found = $this->repository->search(
            'meeting',
            EventScope::forUser($this->user('jdoe'))->limitedToUsers(['jdoe'])
        );

        $this->assertSame(
            ['Salary meeting', 'Standup meeting', 'Therapy meeting'],
            $this->namesOf($found),
            'pinned to jdoe, so bsmith\'s public entry drops out'
        );
    }
}
