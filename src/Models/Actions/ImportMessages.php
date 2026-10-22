<?php

declare(strict_types=1);

namespace Hirtz\Translation\Models\Actions;

use Hirtz\Translation\Formats\Xliff;
use Hirtz\Translation\Formats\Xlsx;
use Hirtz\Translation\Helpers\IcuHelper;
use Hirtz\Translation\Models\MessageFiles;
use yii\console\Exception;

class ImportMessages
{
    /**
     * @param list<string> $categories
     * @param list<string> $languages
     */
    public function __construct(
        protected MessageFiles $files,
        protected string $source,
        protected array $categories = [],
        protected array $languages = [],
        protected ?string $phpDocBlock = null,
        protected bool $sort = true,
    ) {
        if (!is_file($source)) {
            throw new Exception("Failed to read source file \"$source\".");
        }
    }

    /**
     * Everything is read and checked before anything is written, so a refused file leaves none half imported.
     */
    public function run(): bool
    {
        $messages = $this->filterMessages($this->readMessages());
        $this->validateArguments($messages);

        foreach ($messages as $category => $translationsByLanguage) {
            foreach ($translationsByLanguage as $language => $translations) {
                if (!$this->files->save($category, $language, $translations, $this->phpDocBlock, $this->sort)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    protected function readMessages(): array
    {
        return in_array(strtolower(pathinfo($this->source, PATHINFO_EXTENSION)), ['xlf', 'xliff'], true)
            ? Xliff::read($this->source)
            : Xlsx::read($this->source, $this->files, $this->categories);
    }

    /**
     * @param array<string, array<string, array<string, string>>> $messages
     * @return array<string, array<string, array<string, string>>>
     */
    protected function filterMessages(array $messages): array
    {
        foreach ($this->categories as $category) {
            if (!isset($messages[$category])) {
                throw new Exception("The file has no category \"$category\".");
            }
        }

        $result = [];

        foreach ($messages as $category => $translationsByLanguage) {
            $category = MessageFiles::validateName((string)$category);

            if ($this->categories && !in_array($category, $this->categories, true)) {
                continue;
            }

            $sourceLanguage = $this->files->getSourceLanguage($category);
            $forced = $this->files->hasForcedTranslation($category);

            foreach ($translationsByLanguage as $language => $translations) {
                $language = MessageFiles::validateName((string)$language);

                // An empty cell is a translation not made, which the file leaves out rather than holding as `''`.
                if ($forced || $language !== $sourceLanguage) {
                    $translations = array_filter($translations, fn (string $translation): bool => $translation !== '');
                }

                if ($translations && (!$this->languages || in_array($language, $this->languages, true))) {
                    $result[$category][$language] = $translations;
                }
            }
        }

        return $result;
    }

    /**
     * A translation must name the arguments its source names, or the message breaks once a parameter is passed.
     *
     * @param array<string, array<string, array<string, string>>> $messages
     */
    protected function validateArguments(array $messages): void
    {
        $errors = [];

        foreach ($messages as $category => $translationsByLanguage) {
            $sourceLanguage = $this->files->getSourceLanguage($category);
            $forced = $this->files->hasForcedTranslation($category);

            $sources = $forced
                ? array_replace($this->files->load($category, $sourceLanguage), $translationsByLanguage[$sourceLanguage] ?? [])
                : [];

            foreach ($translationsByLanguage as $language => $translations) {
                if ($language === $sourceLanguage) {
                    continue;
                }

                foreach ($translations as $key => $translation) {
                    $source = $forced ? ($sources[$key] ?? '') : (string)$key;

                    if (!IcuHelper::hasSameArguments($source, $translation)) {
                        $errors[] = "- $category, $language: $key";
                    }
                }
            }
        }

        if ($errors) {
            throw new Exception("These translations name other arguments than their source:\n" . implode("\n", $errors));
        }
    }
}
