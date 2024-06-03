<?php

namespace davidhirtz\yii2\translation\controllers;

use davidhirtz\yii2\skeleton\console\controllers\traits\ControllerTrait;
use davidhirtz\yii2\skeleton\helpers\FileHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Yii;
use yii\console\Controller;
use yii\console\Exception;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;
use yii\helpers\Console;
use yii\helpers\VarDumper;

class TranslationController extends Controller
{
    use ControllerTrait;

    public ?string $docBlock = null;
    public string $messagePath = '@messages';
    public bool $sort = true;

    public function init(): void
    {
        $this->defaultAction = 'export';
        parent::init();
    }

    public function options($actionID): array
    {
        return [
            ...parent::options($actionID),
            'messagePath',
            'sort',
        ];
    }

    /**
     * Exports translations to Excel format.
     */
    public function actionExport(?string $outputDir = null): void
    {
        $outputDir = Yii::getAlias($outputDir ?? '@runtime');
        $filename = "$outputDir/translations.xlsx";

        $coloredFileName = Console::ansiFormat($filename, [BaseConsole::FG_CYAN]);
        $this->interactiveStartStdout("Exporting translations to $coloredFileName ...");

        FileHelper::createDirectory($outputDir);

        $files = FileHelper::findFiles(Yii::getAlias($this->messagePath), [
            'only' => ['*/*.php'],
            'recursive' => true,
        ]);

        sort($files);

        $messages = [];

        foreach ($files as $file) {
            $category = pathinfo((string)$file, PATHINFO_FILENAME);
            $language = pathinfo(dirname((string)$file), PATHINFO_FILENAME);
            $messages[$category][$language] = require $file;
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);

        $spreadsheet->removeSheetByIndex(0);
        $index = 0;

        foreach ($messages as $category => $languages) {
            $worksheet = new Worksheet($spreadsheet, $category);
            $spreadsheet->addSheet($worksheet, $index++);

            $worksheet->getProtection()
                ->setSheet(true)
                ->setFormatColumns(false);

            $forcedTranslation = $this->hasForcedTranslation($category);

            $rows = [
                $forcedTranslation
                    ? ['key', ...array_keys($languages)]
                    : array_unique([Yii::$app->sourceLanguage, ...array_keys($languages)]),
            ];

            foreach ($languages[Yii::$app->sourceLanguage] as $key => $translation) {
                $row = [$key];

                foreach ($languages as $language => $translations) {
                    if ($language !== Yii::$app->sourceLanguage || $forcedTranslation) {
                        $row[] = $translations[$key] ?? '';
                    }
                }

                $rows[] = $row;
            }

            $worksheet->fromArray($rows);

            $worksheet->freezePane('B2');

            $worksheet->getStyle(1)->getFont()->setBold(true);
            $worksheet->getStyle(1)->getProtection()->setLocked(Protection::PROTECTION_INHERIT);

            foreach ($worksheet->getColumnIterator() as $column) {
                $worksheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filename);

        $this->interactiveDoneStdout();
    }

    /**
     * Imports translations from Excel file.
     */
    public function actionImport(?string $source = null): int
    {
        if (!$source) {
            throw new Exception('Source file cannot be empty.');
        }

        $source = Yii::getAlias($source);
        $messagePath = Yii::getAlias($this->messagePath);

        if (!is_file($source)) {
            throw new Exception("Failed to read source file \"$source\".");
        }

        $this->interactiveStartStdout("Importing translations ...");

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($source);

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $category = $sheet->getTitle();
            $data = $sheet->toArray();
            $languages = array_shift($data);

            $forcedTranslation = $this->hasForcedTranslation($category);

            if ($forcedTranslation) {
                if (($languages[0] ?? null) !== 'key') {
                    throw new Exception("Key must be the first column in worksheet \"$category\".");
                }
            } elseif (($languages[0] ?? null) !== Yii::$app->sourceLanguage) {
                $language = Yii::$app->sourceLanguage;
                throw new Exception("Source language \"$language\" must be the first column in worksheet \"$category\".");
            }

            $messages = [];

            foreach ($data as $values) {
                foreach ($values as $key => $value) {
                    if ($key === 0) {
                        $source = $value;

                        if (!$forcedTranslation) {
                            $messages[Yii::$app->sourceLanguage][$source] = '';
                        }
                    } else {
                        $messages[$languages[$key]][$source] = $value ?? '';
                    }
                }
            }

            foreach ($messages as $language => $translations) {
                $filename = "$messagePath/$language/$category.php";
                FileHelper::createDirectory(dirname($filename));

                $content = "<?php\n";

                if (file_exists($filename)) {
                    $existing = require $filename;
                    $translations = [...$existing, ...$translations];
                    $content .= $this->getPhpDocBlock($filename) ?? "\n";
                }

                if ($this->sort) {
                    ksort($translations);
                }

                $array = VarDumper::export($translations);
                $content .= "return $array;\n";

                if (file_put_contents($filename, $content, LOCK_EX) === false) {
                    $this->interactiveDoneStdout(false);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
            }
        }

        $this->interactiveDoneStdout();
        return ExitCode::OK;
    }

    private function getPhpDocBlock(string $filePath): ?string
    {
        $lines = file($filePath);
        $isDocBlock = false;
        $phpDoc = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '/**')) {
                $isDocBlock = true;
            }

            if ($isDocBlock) {
                $phpDoc[] = $line;
            }

            if (str_ends_with($line, "*/\n")) {
                break;
            }
        }

        return $phpDoc ? implode('', $phpDoc) : null;
    }

    private function hasForcedTranslation(string $category): bool
    {
        return Yii::$app->getI18n()->translations[$category]['forceTranslation']
            ?? Yii::$app->getI18n()->translations['*']['forceTranslation']
            ?? false;
    }
}
