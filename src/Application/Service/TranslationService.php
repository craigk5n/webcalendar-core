<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

/**
 * Service for multi-language support (i18n).
 * 
 * Implements the custom translation system defined in PRD Section 26.
 */
final class TranslationService
{
    /** @var array<string, string> */
    private array $translations = [];
    private string $currentLanguage = 'English';

    public function __construct(
        private readonly string $translationsDir = 'legacy/translations'
    ) {
        $this->loadTranslations($this->currentLanguage);
    }

    /**
     * Gets translated string (returns original if no translation found).
     */
    public function translate(string $text): string
    {
        return $this->translations[$text] ?? $text;
    }

    /**
     * Switches active language.
     */
    public function resetLanguage(string $language): void
    {
        if ($this->currentLanguage === $language) {
            return;
        }

        $this->currentLanguage = $language;
        $this->loadTranslations($language);
    }

    private function loadTranslations(string $language): void
    {
        $this->translations = [];
        $file = $this->translationsDir . '/' . $language . '.txt';

        if (!file_exists($file)) {
            return;
        }

        $lines = file($file);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($value === '=') {
                $value = $key;
            }

            $this->translations[$key] = $value;
        }
    }
}
