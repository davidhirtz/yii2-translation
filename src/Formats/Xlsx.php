<?php

declare(strict_types=1);

namespace Hirtz\Translation\Formats;

use Hirtz\Translation\Helpers\LanguageHelper;
use Hirtz\Translation\Models\Catalog;
use Hirtz\Translation\Models\MessageFiles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use yii\console\Exception;

class Xlsx
{
    /**
     * @param list<Catalog> $catalogs
     */
    public static function write(string $filename, array $catalogs, int $width = 100): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $spreadsheet->removeSheetByIndex(0);

        foreach ($catalogs as $catalog) {
            $worksheet = new Worksheet($spreadsheet, $catalog->category);
            $spreadsheet->addSheet($worksheet);

            self::writeWorksheet($worksheet, $catalog, $width);
        }

        (new XlsxWriter($spreadsheet))->save($filename);
    }

    /**
     * @param list<string> $categories
     * @return array<string, array<string, array<string, string>>>
     */
    public static function read(string $filename, MessageFiles $files, array $categories = []): array
    {
        // Not data only: the protection of the reference column is a style.
        $reader = new XlsxReader();

        $messages = [];

        foreach ($reader->load($filename)->getAllSheets() as $worksheet) {
            $category = MessageFiles::validateName($worksheet->getTitle());

            if (!$categories || in_array($category, $categories, true)) {
                $messages[$category] = self::readWorksheet($worksheet, $category, $files);
            }
        }

        return $messages;
    }

    /**
     * The key column is followed by the reference and the languages; a category without forced translation keys
     * its messages by the source text, so the key column is the source language.
     */
    private static function writeWorksheet(Worksheet $worksheet, Catalog $catalog, int $width): void
    {
        $columns = array_values(array_filter([$catalog->reference, ...$catalog->languages]));
        $rows = [[$catalog->forced ? 'key' : $catalog->sourceLanguage, ...$columns]];

        foreach ($catalog->keys as $key) {
            $row = [$key];

            foreach ($columns as $language) {
                $row[] = $catalog->getTranslation($language, $key);
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

        $style = $worksheet->getStyle($worksheet->calculateWorksheetDimension());

        $style->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        // What a translator types stays text, `0042`, `1.50` and `=…` included.
        $style->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $lastRow = count($rows);

        if ($catalog->reference !== null) {
            $worksheet->getStyle("B1:B$lastRow")->getProtection()->setLocked(Protection::PROTECTION_INHERIT);
        }

        foreach ($columns as $index => $language) {
            if (LanguageHelper::isRightToLeft($language)) {
                $column = Coordinate::stringFromColumnIndex($index + 2);

                $worksheet->getStyle("{$column}2:$column$lastRow")
                    ->getAlignment()
                    ->setReadOrder(Alignment::READORDER_RTL)
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }

        foreach ($worksheet->getColumnIterator() as $column) {
            $worksheet->getColumnDimension($column->getColumnIndex())->setWidth($width);
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function readWorksheet(Worksheet $worksheet, string $category, MessageFiles $files): array
    {
        $rows = $worksheet->toArray(formatData: false);
        $header = array_map(self::toString(...), array_shift($rows) ?? []);

        $sourceLanguage = $files->getSourceLanguage($category);
        $forced = $files->hasForcedTranslation($category);

        if ($forced) {
            if (($header[0] ?? null) !== 'key') {
                throw new Exception("Key must be the first column in worksheet \"$category\".");
            }
        } elseif (($header[0] ?? null) !== $sourceLanguage) {
            throw new Exception("Source language \"$sourceLanguage\" must be the first column in worksheet \"$category\".");
        }

        // Excel keeps the width of a column that once held a value, so the header can end in empty cells
        $languages = array_filter(array_slice($header, 1, preserve_keys: true));

        // The reference column was locked for the translator: it holds the source as exported, not an edit.
        $languages = array_filter(
            $languages,
            fn (int $index): bool => !self::isLocked($worksheet, $index),
            ARRAY_FILTER_USE_KEY,
        );
        $messages = [];

        foreach ($rows as $row) {
            $key = self::toString($row[0] ?? null);

            if ($key === '') {
                continue;
            }

            if (!$forced) {
                $messages[$sourceLanguage][$key] = '';
            }

            foreach ($languages as $index => $language) {
                $messages[$language][$key] = self::toString($row[$index] ?? null);
            }
        }

        return $messages;
    }

    private static function isLocked(Worksheet $worksheet, int $index): bool
    {
        $cell = Coordinate::stringFromColumnIndex($index + 1) . '2';

        return $worksheet->getProtection()->getSheet()
            && $worksheet->getStyle($cell)->getProtection()->getLocked() !== Protection::PROTECTION_UNPROTECTED;
    }

    private static function toString(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
