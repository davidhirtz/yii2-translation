<?php

declare(strict_types=1);

namespace Hirtz\Translation\Console\Controllers;

use Hirtz\Skeleton\Console\Controllers\Traits\ControllerTrait;
use Hirtz\Translation\Models\Actions\ExportMessages;
use Hirtz\Translation\Models\Actions\ImportMessages;
use Hirtz\Translation\Models\MessageFiles;
use Override;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\console\widgets\Table;
use yii\helpers\Console;

/**
 * Exports message translations to an Excel or XLIFF file and imports them back.
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
     * @var list<string>
     */
    public array $categories = [];

    /**
     * @var list<string>
     */
    public array $languages = [];

    public string $format = ExportMessages::FORMAT_XLSX;
    public bool $missing = false;

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
            'categories',
            'languages',
            'messagePath',
            ...match ($actionID) {
                'export' => ['format', 'missing', 'width'],
                'import' => ['phpDocBlock', 'sort'],
                default => [],
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function optionAliases(): array
    {
        return [
            ...parent::optionAliases(),
            'c' => 'categories',
            'f' => 'format',
            'l' => 'languages',
            'm' => 'missing',
        ];
    }

    /**
     * Exports translations to `translations.xlsx`, one worksheet per category, or one XLIFF file per language.
     */
    public function actionExport(?string $outputDir = null): int
    {
        $languages = $this->getLanguages();

        $action = Yii::createObject(ExportMessages::class, [
            'catalogs' => $this->getMessageFiles()->findCatalogs($this->categories, $languages),
            'outputDir' => Yii::getAlias($outputDir ?? '@runtime'),
            'format' => $this->format,
            'missing' => $this->missing,
            'languages' => $languages,
            'width' => $this->width,
        ]);

        $this->interactiveStartStdout('Exporting translations ...');
        $filenames = $action->run();
        $this->interactiveDoneStdout();

        foreach ($filenames as $filename) {
            $this->interactiveStdout('- ' . $this->ansiFormat($filename, Console::FG_CYAN) . PHP_EOL);
        }

        if (!$filenames) {
            $this->stdout('No translations are missing.' . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Imports translations from an Excel or XLIFF file the export wrote, merged into the existing message files.
     */
    public function actionImport(string $source): int
    {
        $action = Yii::createObject(ImportMessages::class, [
            'files' => $this->getMessageFiles(),
            'source' => Yii::getAlias($source),
            'categories' => $this->categories,
            'languages' => $this->getLanguages(),
            'phpDocBlock' => $this->phpDocBlock,
            'sort' => $this->sort,
        ]);

        $this->interactiveStartStdout('Importing translations ...');
        $success = $action->run();
        $this->interactiveDoneStdout($success);

        return $success ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Lists the missing and invalid translations per category and language; exits with an error while any remain.
     */
    public function actionStatus(): int
    {
        $rows = [];
        $isComplete = true;

        foreach ($this->getMessageFiles()->findCatalogs($this->categories, $this->getLanguages()) as $catalog) {
            foreach ($catalog->languages as $language) {
                $missing = count($catalog->getMissingKeys($language));
                $invalid = count($catalog->getInvalidKeys($language));
                $total = count($catalog->keys);

                $isComplete = $isComplete && !$missing && !$invalid;
                $rows[] = [$catalog->category, $language, ($total - $missing) . "/$total", $missing, $invalid];
            }
        }

        $this->stdout(Table::widget([
            'headers' => ['Category', 'Language', 'Translated', 'Missing', 'Invalid'],
            'rows' => $rows,
        ]));

        return $isComplete ? ExitCode::OK : ExitCode::DATAERR;
    }

    protected function getMessageFiles(): MessageFiles
    {
        return Yii::createObject(MessageFiles::class, [$this->messagePath]);
    }

    /**
     * @return list<string>
     */
    private function getLanguages(): array
    {
        return array_values(array_unique(array_map(MessageFiles::validateName(...), $this->languages)));
    }
}
