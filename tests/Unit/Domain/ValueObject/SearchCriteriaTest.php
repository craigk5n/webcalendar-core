<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\ValueObject;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\ValueObject\SearchCriteria;

final class SearchCriteriaTest extends TestCase
{
    public function testDefaultsAreUnfiltered(): void
    {
        $criteria = new SearchCriteria();

        $this->assertNull($criteria->keyword);
        $this->assertSame([], $criteria->categoryIds);
        $this->assertSame(100, $criteria->limit);
        $this->assertSame(0, $criteria->offset);
        $this->assertFalse($criteria->hasDistanceFilter());
    }

    public function testDistanceTrioMustComeTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchCriteria(nearLatitude: 38.0, nearLongitude: -78.0);
    }

    public function testNegativeRadiusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchCriteria(nearLatitude: 38.0, nearLongitude: -78.0, radiusKm: -5.0);
    }

    public function testLatitudeRangeIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchCriteria(nearLatitude: 91.0, nearLongitude: 0.0, radiusKm: 1.0);
    }

    public function testLimitCeilingIsEnforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchCriteria(limit: SearchCriteria::MAX_LIMIT + 1);
    }

    public function testNegativeOffsetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SearchCriteria(offset: -1);
    }

    public function testCompleteDistanceFilterIsAccepted(): void
    {
        $criteria = new SearchCriteria(nearLatitude: 38.0, nearLongitude: -78.0, radiusKm: 25.0);

        $this->assertTrue($criteria->hasDistanceFilter());
    }
}
