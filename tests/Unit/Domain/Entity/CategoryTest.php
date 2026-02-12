<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Category;

final class CategoryTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $category = new Category(
            id: 1,
            owner: 'jdoe',
            name: 'Work',
            color: '#FF0000',
            enabled: true
        );

        $this->assertSame(1, $category->id());
        $this->assertSame('jdoe', $category->owner());
        $this->assertSame('Work', $category->name());
        $this->assertSame('#FF0000', $category->color());
        $this->assertTrue($category->isEnabled());
    }

    public function testGlobalCategoryHasNullOwner(): void
    {
        $category = new Category(
            id: 2,
            owner: null,
            name: 'Holiday',
            color: '#00FF00',
            enabled: true
        );

        $this->assertNull($category->owner());
        $this->assertTrue($category->isGlobal());
    }
}
