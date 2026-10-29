<?php

declare(strict_types=1);

namespace Hirtz\Translation\Models;

use Hirtz\Translation\Helpers\IcuHelper;

/**
 * `languages` lacks the source language when the messages are keyed by their source text; `reference` stands in.
 */
final readonly class Catalog
{
    /**
     * @param list<string> $keys
     * @param list<string> $languages
     * @param array<string, array<string, string>> $messages
     */
    public function __construct(
        public string $category,
        public string $sourceLanguage,
        public bool $forced,
        public array $keys,
        public array $languages,
        public ?string $reference,
        public array $messages,
    ) {
    }

    public function getTranslation(string $language, string $key): string
    {
        return $this->messages[$language][$key] ?? '';
    }

    public function getSource(string $key): string
    {
        return $this->forced ? $this->getTranslation($this->sourceLanguage, $key) : $key;
    }

    /**
     * @return list<string>
     */
    public function getMissingKeys(?string $language = null): array
    {
        $languages = $language !== null ? [$language] : $this->languages;
        $keys = [];

        foreach ($this->keys as $key) {
            foreach ($languages as $language) {
                if ($this->getTranslation($language, $key) === '') {
                    $keys[] = $key;
                    break;
                }
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function getInvalidKeys(string $language): array
    {
        return array_values(array_filter(
            $this->keys,
            fn (string $key): bool => !IcuHelper::hasSameArguments($this->getSource($key), $this->getTranslation($language, $key))
        ));
    }

    /**
     * @param list<string> $keys
     */
    public function withKeys(array $keys): self
    {
        return new self(
            $this->category,
            $this->sourceLanguage,
            $this->forced,
            $keys,
            $this->languages,
            $this->reference,
            $this->messages,
        );
    }
}
