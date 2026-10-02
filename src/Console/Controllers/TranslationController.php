<?php

declare(strict_types=1);

namespace Hirtz\Translation\Console\Controllers;

use Hirtz\Skeleton\Console\Controllers\Traits\ControllerTrait;
use Hirtz\Skeleton\Helpers\FileHelper;
use Override;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Yii;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\console\Exception;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\VarDumper;
use yii\i18n\MessageSource;

/**
 * Exports message translations to an Excel file and imports them back.
 */
class TranslationController extends Controller
{
    use ControllerTrait;

    public $defaultAction = 'export';

    /**
     * The directory holding one subdirectory per language, as `yii message` writes it.
     */
    public string $messagePath = '@messages';

    /**
     * The docblock of a message file the import creates; an existing file keeps its own.
     */
    public ?string $phpDocBlock = null;

    public bool $sort = true;
    public int $width = 100;

    #[Override]
    public function options($actionID): array
    {
        return [
            ...parent::options($actionID),
            'messagePath',
            'phpDocBlock',
            'sort',
            'width',
        ];
    }

    /**
     * Exports translations to `translations.xlsx`, one worksheet per category.
     */
    public function actionExport(?string $outputDir = null): int
    {
        $outputDir = Yii::getAlias($outputDir ?? '@runtime');
        $filename = "$outputDir/translations.xlsx";

        $this->interactiveStartStdout('Exporting translations to ' . $this->ansiFormat($filename, Console::FG_CYAN) . ' ...');

        FileHelper::createDirectory($outputDir);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $spreadsheet->removeSheetByIndex(0);

        $messages = $this->findMessages();

        if (!$messages) {
            throw new Exception('No message files found in "' . $this->getMessagePath() . '".');
        }

        foreach ($messages as $category => $languages) {
            $worksheet = new Worksheet($spreadsheet, $category);
            $spreadsheet->addSheet($worksheet);

            $this->writeWorksheet($worksheet, $category, $languages);
        }

        (new XlsxWriter($spreadsheet))->save($filename);

        $this->interactiveDoneStdout();
        return ExitCode::OK;
    }

    /**
     * Imports translations from an Excel file the export wrote, merged into the existing message files.
     */
    public function actionImport(string $source): int
    {
        $source = Yii::getAlias($source);

        if (!is_file($source)) {
            throw new Exception("Failed to read source file \"$source\".");
        }

        $this->interactiveStartStdout('Importing translations ...');

        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);

        foreach ($reader->load($source)->getAllSheets() as $worksheet) {
            $category = $worksheet->getTitle();

            foreach ($this->readWorksheet($worksheet, $category) as $language => $translations) {
                if (!$this->saveMessages($category, $language, $translations)) {
                    $this->interactiveDoneStdout(false);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
            }
        }

        $this->interactiveDoneStdout();
        return ExitCode::OK;
    }

    /**
     * @return array<string, array<string, array<string, string>>> translations by category and language
     */
    protected function findMessages(): array
    {
        $files = FileHelper::findFiles($this->getMessagePath(), [
            'only' => ['*/*.php'],
        ]);

        sort($files);

        $messages = [];

        foreach ($files as $file) {
            $category = pathinfo($file, PATHINFO_FILENAME);
            $language = basename(dirname($file));

            $messages[$category][$language] = $this->loadMessageFile($file);
        }

        return $messages;
    }

    /**
     * A category without forced translation keys its messages by the source text, so the source language is the
     * key column. With forced translation the keys are identifiers and the source language is a column of its own.
     *
     * @param array<string, array<string, string>> $languages
     */
    protected function writeWorksheet(Worksheet $worksheet, string $category, array $languages): void
    {
        $sourceLanguage = $this->getSourceLanguage($category);
        $forced = $this->hasForcedTranslation($category);

        $keys = array_values(array_unique(array_merge(...array_values(array_map(array_keys(...), $languages)))));

        if (!$forced) {
            unset($languages[$sourceLanguage]);
        }

        $rows = [[$forced ? 'key' : $sourceLanguage, ...array_keys($languages)]];

        foreach ($keys as $key) {
            $row = [(string)$key];

            foreach ($languages as $translations) {
                $row[] = $translations[$key] ?? '';
            }

            $rows[] = $row;
        }

        // Every cell is written as a string: the default value binder would turn `=…` into a formula and a
        // numeric translation into a number, which the import would read back as something else
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $worksheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
            }
        }

        $worksheet->getProtection()
            ->setSheet(true)
            ->setFormatColumns(false);

        $worksheet->freezePane('B2');

        $header = $worksheet->getStyle('1:1');
        $header->getFont()->setBold(true);
        $header->getProtection()->setLocked(Protection::PROTECTION_INHERIT);

        $worksheet->getStyle($worksheet->calculateWorksheetDimension())
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        foreach ($worksheet->getColumnIterator() as $column) {
            $worksheet->getColumnDimension($column->getColumnIndex())->setWidth($this->width);
        }
    }

    /**
     * @return array<string, array<string, string>> translations by language
     */
    protected function readWorksheet(Worksheet $worksheet, string $category): array
    {
        $rows = $worksheet->toArray(formatData: false);
        $header = array_map($this->toString(...), array_shift($rows) ?? []);

        $sourceLanguage = $this->getSourceLanguage($category);
        $forced = $this->hasForcedTranslation($category);

        if ($forced) {
            if (($header[0] ?? null) !== 'key') {
                throw new Exception("Key must be the first column in worksheet \"$category\".");
            }
        } elseif (($header[0] ?? null) !== $sourceLanguage) {
            throw new Exception("Source language \"$sourceLanguage\" must be the first column in worksheet \"$category\".");
        }

        // Excel keeps the width of a column that once held a value, so the header can end in empty cells
        $languages = array_filter(array_slice($header, 1, preserve_keys: true));
        $messages = [];

        foreach ($rows as $row) {
            $key = $this->toString($row[0] ?? null);

            if ($key === '') {
                continue;
            }

            if (!$forced) {
                $messages[$sourceLanguage][$key] = '';
            }

            foreach ($languages as $index => $language) {
                $messages[$language][$key] = $this->toString($row[$index] ?? null);
            }
        }

        return $messages;
    }

    /**
     * @param array<string, string> $translations
     */
    protected function saveMessages(string $category, string $language, array $translations): bool
    {
        $filename = $this->getMessagePath() . "/$language/$category.php";
        FileHelper::createDirectory(dirname($filename));

        $docBlock = $this->phpDocBlock;

        if (is_file($filename)) {
            // Not a spread: it renumbers integer keys, and a numeric message key is one
            $translations = array_replace($this->loadMessageFile($filename), $translations);
            $docBlock = $this->getPhpDocBlock($filename) ?? $docBlock;
        }

        if ($this->sort) {
            ksort($translations);
        }

        $content = "<?php\n" . ($docBlock !== null ? "$docBlock\n" : '') . 'return ' . VarDumper::export($translations) . ";\n";

        return file_put_contents($filename, $content, LOCK_EX) !== false;
    }

    /**
     * @return array<string, string>
     */
    protected function loadMessageFile(string $filename): array
    {
        $messages = require $filename;
        $result = [];

        foreach (is_array($messages) ? $messages : [] as $key => $value) {
            $result[(string)$key] = $this->toString($value);
        }

        return $result;
    }

    /**
     * Yii's `FileHelper::findFiles()` matches no `only` pattern below a path containing `..`.
     */
    private function getMessagePath(): string
    {
        return FileHelper::normalizePath(Yii::getAlias($this->messagePath));
    }

    private function getPhpDocBlock(string $filename): ?string
    {
        $content = file_get_contents($filename);
        return $content !== false && preg_match('#^/\*\*.*?\*/#ms', $content, $matches) ? $matches[0] : null;
    }

    private function getSourceLanguage(string $category): string
    {
        return $this->getMessageSource($category)->sourceLanguage ?? Yii::$app->sourceLanguage;
    }

    private function hasForcedTranslation(string $category): bool
    {
        return $this->getMessageSource($category)->forceTranslation ?? false;
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

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
