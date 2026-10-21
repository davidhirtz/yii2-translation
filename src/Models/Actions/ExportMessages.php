<?php

declare(strict_types=1);

namespace Hirtz\Translation\Models\Actions;

use Hirtz\Skeleton\Helpers\FileHelper;
use Hirtz\Translation\Formats\Xliff;
use Hirtz\Translation\Formats\Xlsx;
use Hirtz\Translation\Models\Catalog;
use yii\console\Exception;

class ExportMessages
{
    final public const string FORMAT_XLSX = 'xlsx';
    final public const string FORMAT_XLIFF = 'xliff';

    /**
     * @param list<Catalog> $catalogs
     * @param list<string> $languages
     */
    public function __construct(
        protected array $catalogs,
        protected string $outputDir,
        protected string $format = self::FORMAT_XLSX,
        protected bool $missing = false,
        protected array $languages = [],
        protected int $width = 100,
    ) {
        if (!in_array($format, [self::FORMAT_XLSX, self::FORMAT_XLIFF], true)) {
            throw new Exception('Format must be "xlsx" or "xliff".');
        }
    }

    /**
     * @return list<string>
     */
    public function run(): array
    {
        FileHelper::createDirectory($this->outputDir);

        return $this->format === self::FORMAT_XLIFF
            ? $this->writeXliff()
            : $this->writeXlsx();
    }

    /**
     * @return list<string>
     */
    protected function writeXlsx(): array
    {
        $catalogs = [];

        foreach ($this->catalogs as $catalog) {
            if ($this->missing) {
                $catalog = $catalog->withKeys($catalog->getMissingKeys());
            }

            if ($catalog->keys || !$this->missing) {
                $catalogs[] = $catalog;
            }
        }

        if (!$catalogs) {
            return [];
        }

        $filename = "$this->outputDir/" . implode('-', ['translations', ...$this->languages]) . '.xlsx';
        Xlsx::write($filename, $catalogs, $this->width);

        return [$filename];
    }

    /**
     * The source language is never a target: it is the `<source>` of every unit.
     *
     * @return list<string>
     */
    protected function writeXliff(): array
    {
        $catalogsByLanguage = [];

        foreach ($this->catalogs as $catalog) {
            foreach ($catalog->languages as $language) {
                if ($language === $catalog->sourceLanguage) {
                    continue;
                }

                $languageCatalog = $this->missing ? $catalog->withKeys($catalog->getMissingKeys($language)) : $catalog;

                if ($languageCatalog->keys || !$this->missing) {
                    $catalogsByLanguage[$language][] = $languageCatalog;
                }
            }
        }

        $filenames = [];

        foreach ($catalogsByLanguage as $language => $catalogs) {
            $filename = "$this->outputDir/translations-$language.xlf";
            Xliff::write($filename, (string)$language, $catalogs);

            $filenames[] = $filename;
        }

        return $filenames;
    }
}
