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

    // ── Epic 23: tags (flat, global category variant) ─────────────

    public function testIsNotATagByDefault(): void
    {
        $category = new Category(id: 1, owner: null, name: 'Work', color: null);

        $this->assertFalse($category->isTag());
    }

    public function testTagIsGlobalAndFlat(): void
    {
        $tag = new Category(id: 3, owner: null, name: 'outdoors', color: null, isTag: true);

        $this->assertTrue($tag->isTag());
        $this->assertTrue($tag->isGlobal());
    }

    public function testOwnedTagIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Category(id: 3, owner: 'jdoe', name: 'mine', color: null, isTag: true);
    }
}
