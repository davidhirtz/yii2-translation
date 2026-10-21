<?php

declare(strict_types=1);

namespace Hirtz\Translation\Models;

use Hirtz\Skeleton\Helpers\FileHelper;
use Yii;
use yii\base\InvalidConfigException;
use yii\console\Exception;
use yii\helpers\VarDumper;
use yii\i18n\MessageSource;

class MessageFiles
{
    public readonly string $path;

    /**
     * Yii's `FileHelper::findFiles()` matches no `only` pattern below a path containing `..`.
     */
    public function __construct(string $path)
    {
        $this->path = FileHelper::normalizePath(Yii::getAlias($path));
    }

    /**
     * @param list<string> $categories
     * @param list<string> $languages
     * @return list<Catalog>
     */
    public function findCatalogs(array $categories = [], array $languages = []): array
    {
        $files = FileHelper::findFiles($this->path, [
            'only' => ['*/*.php'],
        ]);

        sort($files);

        $messages = [];

        foreach ($files as $file) {
            $category = pathinfo($file, PATHINFO_FILENAME);

            if (!$categories || in_array($category, $categories, true)) {
                $messages[$category][basename(dirname($file))] = $this->loadFile($file);
            }
        }

        foreach ($categories as $category) {
            if (!isset($messages[$category])) {
                throw new Exception("No message files found for category \"$category\".");
            }
        }

        if (!$messages) {
            throw new Exception("No message files found in \"$this->path\".");
        }

        $catalogs = [];

        foreach ($messages as $category => $translationsByLanguage) {
            $category = (string)$category;
            $sourceLanguage = $this->getSourceLanguage($category);
            $forced = $this->hasForcedTranslation($category);

            // The keys of every language, whichever are exported, so a language without a file gets every row
            $keys = array_values(array_unique(array_merge(...array_values(array_map(array_keys(...), $translationsByLanguage)))));
            $keys = array_map(strval(...), $keys);

            $catalogLanguages = $languages ?: array_map(strval(...), array_keys($translationsByLanguage));
            $reference = $forced && $languages && !in_array($sourceLanguage, $languages, true) ? $sourceLanguage : null;

            if (!$forced) {
                $catalogLanguages = array_values(array_diff($catalogLanguages, [$sourceLanguage]));
            }

            $catalogs[] = new Catalog($category, $sourceLanguage, $forced, $keys, $catalogLanguages, $reference, $translationsByLanguage);
        }

        return $catalogs;
    }

    /**
     * @return array<string, string>
     */
    public function load(string $category, string $language): array
    {
        $filename = $this->getFilename($category, $language);
        return is_file($filename) ? $this->loadFile($filename) : [];
    }

    /**
     * Merged into an existing file, which keeps its docblock; `$docBlock` is the one of a new file.
     *
     * @param array<string, string> $translations
     */
    public function save(string $category, string $language, array $translations, ?string $docBlock = null, bool $sort = true): bool
    {
        $filename = $this->getFilename($category, $language);
        FileHelper::createDirectory(dirname($filename));

        if (is_file($filename)) {
            // Not a spread: it renumbers integer keys, and a numeric message key is one
            $translations = array_replace($this->loadFile($filename), $translations);
            $docBlock = $this->getPhpDocBlock($filename) ?? $docBlock;
        }

        if ($sort) {
            ksort($translations);
        }

        $content = "<?php\n" . ($docBlock !== null ? "$docBlock\n" : '') . 'return ' . VarDumper::export($translations) . ";\n";

        return file_put_contents($filename, $content, LOCK_EX) !== false;
    }

    public function getSourceLanguage(string $category): string
    {
        return $this->getMessageSource($category)->sourceLanguage ?? Yii::$app->sourceLanguage;
    }

    public function hasForcedTranslation(string $category): bool
    {
        return $this->getMessageSource($category)->forceTranslation ?? false;
    }

    /**
     * A category and a language name a file below the path, so neither may leave it.
     */
    public static function validateName(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name)) {
            throw new Exception("\"$name\" is not a valid category or language.");
        }

        return $name;
    }

    private function getFilename(string $category, string $language): string
    {
        return "$this->path/" . self::validateName($language) . '/' . self::validateName($category) . '.php';
    }

    /**
     * @return array<string, string>
     */
    private function loadFile(string $filename): array
    {
        $messages = require $filename;
        $result = [];

        foreach (is_array($messages) ? $messages : [] as $key => $value) {
            $result[(string)$key] = is_scalar($value) ? (string)$value : '';
        }

        return $result;
    }

    private function getPhpDocBlock(string $filename): ?string
    {
        $content = file_get_contents($filename);
        return $content !== false && preg_match('#^/\*\*.*?\*/#ms', $content, $matches) ? $matches[0] : null;
    }

    /**
     * A category no message source is configured for is translated by nothing, so the defaults apply.
     */
    private function getMessageSource(string $category): ?MessageSource
    {
        try {
            return Yii::$app->getI18n()->getMessageSource($category);
        } catch (InvalidConfigException) {
            return null;
        }
    }
}
