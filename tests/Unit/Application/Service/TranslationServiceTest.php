<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\TranslationService;

final class TranslationServiceTest extends TestCase
{
    private TranslationService $translationService;
    private string $tempTransDir;

    protected function setUp(): void
    {
        $this->tempTransDir = sys_get_temp_dir() . '/webcal_trans_' . uniqid();
        mkdir($this->tempTransDir);
        
        file_put_contents($this->tempTransDir . '/English.txt', "hello: hello
welcome: Welcome to WebCalendar
");
        file_put_contents($this->tempTransDir . '/French.txt', "hello: bonjour
welcome: Bienvenue sur WebCalendar
");

        $this->translationService = new TranslationService($this->tempTransDir);
    }

    protected function tearDown(): void
    {
        $files = glob("$this->tempTransDir/*.*");
        if ($files !== false) {
            array_map('unlink', $files);
        }
        rmdir($this->tempTransDir);
    }

    public function testTranslateDefaultLanguage(): void
    {
        $this->assertSame('hello', $this->translationService->translate('hello'));
        $this->assertSame('Welcome to WebCalendar', $this->translationService->translate('welcome'));
    }

    public function testTranslateSwitchedLanguage(): void
    {
        $this->translationService->resetLanguage('French');
        $this->assertSame('bonjour', $this->translationService->translate('hello'));
        $this->assertSame('Bienvenue sur WebCalendar', $this->translationService->translate('welcome'));
    }

    public function testTranslateMissingKeyReturnsOriginal(): void
    {
        $this->assertSame('missing_key', $this->translationService->translate('missing_key'));
    }
}
