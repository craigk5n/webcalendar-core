<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\AbstractEntry;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use WebCalendar\Core\Domain\ValueObject\Unchanged;
use WebCalendar\Core\Domain\ValueObject\VenueId;

/**
 * Coverage for Event::with(), the general copy API.
 *
 * Consumers that need "this event, but with two fields different" used to
 * hand-roll a full positional constructor call. Every field core added after
 * that call was written was then silently dropped to its default on the next
 * save — which cost real data in the WordPress plugin (venue, organizer and
 * the moderation status all reset by an unrelated edit). These tests pin the
 * two properties that make that impossible: omitted fields are carried over,
 * and the set of copyable fields cannot drift from the constructor.
 */
final class EventWithTest extends TestCase
{
    private const START = '2026-08-10 09:30:00';

    /**
     * An event with every field set to a distinctive, non-default value, so
     * that a field lost in a copy shows up as a difference rather than as a
     * coincidental match against a default.
     */
    private function populated(): Event
    {
        return new Event(
            id: new EventId(11),
            uid: 'uid-original',
            name: 'Original Name',
            description: 'Original description',
            location: 'Original location',
            start: new \DateTimeImmutable(self::START),
            duration: 90,
            createdBy: 'alice',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(new RecurrenceRule('FREQ=DAILY')),
            sequence: 4,
            status: 'TENTATIVE',
            allDay: true,
            modDate: 20260810,
            modTime: 93000,
            image: 'https://example.com/original.png',
            venueId: new VenueId(21),
            organizerId: new OrganizerId(31),
            conferenceUrl: 'https://meet.example.com/original',
            conferenceLabel: 'Google Meet',
        );
    }

    /**
     * Every readable field, keyed by the constructor parameter that sets it.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Event $event): array
    {
        return [
            'id' => $event->id(),
            'uid' => $event->uid(),
            'name' => $event->name(),
            'description' => $event->description(),
            'location' => $event->location(),
            'start' => $event->start(),
            'duration' => $event->duration(),
            'createdBy' => $event->createdBy(),
            'type' => $event->type(),
            'access' => $event->access(),
            'recurrence' => $event->recurrence(),
            'sequence' => $event->sequence(),
            'status' => $event->status(),
            'allDay' => $event->isAllDay(),
            'modDate' => $event->modDate(),
            'modTime' => $event->modTime(),
            'image' => $event->image(),
            'venueId' => $event->venueId(),
            'organizerId' => $event->organizerId(),
            'conferenceUrl' => $event->conferenceUrl(),
            'conferenceLabel' => $event->conferenceLabel(),
        ];
    }

    public function testWithNoArgumentsCopiesEveryField(): void
    {
        $original = $this->populated();

        $copy = $original->with();

        $this->assertNotSame($original, $copy, 'with() must return a new instance');
        $this->assertEquals($this->snapshot($original), $this->snapshot($copy));
    }

    /**
     * A distinct replacement value for every field, paired with the value the
     * getter should then report.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function fieldProvider(): array
    {
        return [
            'id' => ['id', new EventId(99)],
            'uid' => ['uid', 'uid-replaced'],
            'name' => ['name', 'Replaced Name'],
            'description' => ['description', 'Replaced description'],
            'location' => ['location', 'Replaced location'],
            'start' => ['start', new \DateTimeImmutable('2027-01-02 03:04:05')],
            'duration' => ['duration', 45],
            'createdBy' => ['createdBy', 'bob'],
            'type' => ['type', EventType::TASK],
            'access' => ['access', AccessLevel::PRIVATE],
            'recurrence' => ['recurrence', new Recurrence(new RecurrenceRule('FREQ=WEEKLY'))],
            'sequence' => ['sequence', 9],
            'status' => ['status', 'CONFIRMED'],
            'allDay' => ['allDay', false],
            'modDate' => ['modDate', 20270102],
            'modTime' => ['modTime', 30405],
            'image' => ['image', 'https://example.com/replaced.png'],
            'venueId' => ['venueId', new VenueId(77)],
            'organizerId' => ['organizerId', new OrganizerId(88)],
            'conferenceUrl' => ['conferenceUrl', 'https://meet.example.com/replaced'],
            'conferenceLabel' => ['conferenceLabel', 'Zoom'],
        ];
    }

    /**
     * Changing one field must change exactly that field.
     *
     * With 21 near-identical assignments inside with(), a copy-paste slip
     * that writes the right value into the wrong slot is the likeliest bug,
     * and it would be invisible to a test that only checked the field it set.
     *
     * @dataProvider fieldProvider
     */
    public function testWithChangesOnlyTheNamedField(string $field, mixed $newValue): void
    {
        $original = $this->populated();
        $before = $this->snapshot($original);

        // Named-argument spread: the field under test is data, so static
        // analysis cannot match it to a typed parameter. The provider is the
        // thing being checked, and testEveryFieldIsCoveredByTheProvider keeps
        // its keys tied to the real signature.
        $copy = $original->with(...[$field => $newValue]); // @phpstan-ignore-line

        $after = $this->snapshot($copy);

        $this->assertEquals($newValue, $after[$field], "with(): $field was not applied");

        unset($before[$field], $after[$field]);
        $this->assertEquals($before, $after, "with(): changing $field disturbed another field");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nullableFieldProvider(): array
    {
        return [
            'status' => ['status'],
            'modDate' => ['modDate'],
            'modTime' => ['modTime'],
            'image' => ['image'],
            'venueId' => ['venueId'],
            'organizerId' => ['organizerId'],
            'conferenceUrl' => ['conferenceUrl'],
            'conferenceLabel' => ['conferenceLabel'],
        ];
    }

    /**
     * Passing null must clear the field rather than read as "not supplied".
     *
     * This is the whole reason for the Unchanged sentinel: a plain
     * `?string $x = null` signature cannot tell "leave it alone" from
     * "remove it", so clearing a venue or a meeting link would be
     * impossible to express.
     *
     * @dataProvider nullableFieldProvider
     */
    public function testNullExplicitlyClearsANullableField(string $field): void
    {
        $original = $this->populated();
        $this->assertNotNull($this->snapshot($original)[$field], 'fixture must start non-null');

        $copy = $original->with(...[$field => null]); // @phpstan-ignore-line

        $this->assertNull($this->snapshot($copy)[$field]);
    }

    public function testWithDoesNotMutateTheOriginal(): void
    {
        $original = $this->populated();
        $before = $this->snapshot($original);

        $original->with(name: 'Something Else', venueId: null);

        $this->assertEquals($before, $this->snapshot($original));
    }

    public function testWithStillEnforcesConstructorInvariants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->populated()->with(name: '   ');
    }

    public function testWithRejectsANegativeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->populated()->with(duration: -1);
    }

    /**
     * The guard that keeps this from rotting.
     *
     * Adding a constructor field without adding it to with() recreates
     * exactly the silent-data-loss bug this API exists to prevent, so the
     * omission has to fail the build rather than wait to be noticed in
     * production.
     */
    public function testWithAcceptsEveryConstructorField(): void
    {
        $constructorParams = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(AbstractEntry::class, '__construct'))->getParameters()
        );
        $withParams = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Event::class, 'with'))->getParameters()
        );

        $this->assertSame(
            $constructorParams,
            $withParams,
            'Event::with() must accept every constructor field, in the same order'
        );
    }

    /**
     * Every with() parameter must also be covered by the per-field tests
     * above, so a new field cannot be added to both the constructor and
     * with() while going completely untested.
     */
    public function testEveryFieldIsCoveredByTheProvider(): void
    {
        $withParams = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Event::class, 'with'))->getParameters()
        );

        $this->assertSame($withParams, array_keys(self::fieldProvider()));
        $this->assertSame($withParams, array_keys($this->snapshot($this->populated())));
    }

    /**
     * Every parameter defaults to the sentinel, which is what makes omission
     * mean "carry over" rather than "reset to the constructor default".
     */
    public function testEveryParameterDefaultsToTheSentinel(): void
    {
        foreach ((new \ReflectionMethod(Event::class, 'with'))->getParameters() as $param) {
            $this->assertTrue($param->isDefaultValueAvailable(), $param->getName() . ' needs a default');
            $this->assertSame(
                Unchanged::Value,
                $param->getDefaultValue(),
                $param->getName() . ' must default to Unchanged::Value'
            );
        }
    }

    public function testExistingWithersStillWork(): void
    {
        $original = $this->populated();

        $this->assertSame(42, $original->withId(new EventId(42))->id()->value());
        $this->assertSame(7, $original->withVenueId(new VenueId(7))->venueId()?->value());
        $this->assertSame(8, $original->withOrganizerId(new OrganizerId(8))->organizerId()?->value());

        // The narrow withers clear, rather than ignore, an explicit null.
        $this->assertNull($original->withVenueId(null)->venueId());
        $this->assertNull($original->withOrganizerId(null)->organizerId());
    }
}
