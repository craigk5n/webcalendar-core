<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Domain\Entity;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Domain\Entity\Layer;

final class LayerTest extends TestCase
{
    public function testCanBeCreatedWithValidData(): void
    {
        $layer = new Layer(
            id: 1,
            owner: 'jdoe',
            layerUser: 'asmith',
            color: '#FF0000',
            showDuplicates: true
        );

        $this->assertSame(1, $layer->id());
        $this->assertSame('jdoe', $layer->owner());
        $this->assertSame('asmith', $layer->layerUser());
        $this->assertSame('#FF0000', $layer->color());
        $this->assertTrue($layer->showDuplicates());
    }
}
