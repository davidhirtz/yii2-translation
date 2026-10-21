<?php

declare(strict_types=1);

namespace Hirtz\Translation\Tests\Console;

use Hirtz\Skeleton\Console\Application;
use Hirtz\Skeleton\Helpers\FileHelper;
use Hirtz\Skeleton\Test\TestCase;
use Hirtz\Skeleton\Test\Traits\StdOutBufferControllerTrait;
use Hirtz\Translation\Console\Controllers\TranslationController;
use Override;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Yii;
use yii\console\Exception;
use yii\console\ExitCode;
use yii\helpers\VarDumper;
use yii\i18n\PhpMessageSource;

class TranslationControllerTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../Data/Messages';

    protected string $applicationClass = Application::class;

    private string $path;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Yii::getAlias('@runtime/translation');
        FileHelper::createDirectory($this->path);
    }

    #[Override]
    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->path);
        parent::tearDown();
    }

    public function testBootstrapRegistersTheController(): void
    {
        self::assertSame(TranslationController::class, Yii::$app->controllerMap['translation'] ?? null);
    }

    public function testActionExport(): void
    {
        $this->export(self::FIXTURE_PATH);

        self::assertSame([
            ['en-US', 'de'],
            ['Language', 'Sprache'],
        ], $this->readExport());
    }

    public function testActionExportWithForcedTranslation(): void
    {
        $this->setForcedTranslation();
        $this->export(self::FIXTURE_PATH);

        self::assertSame([
            ['key', 'de', 'en-US'],
            ['Language', 'Sprache', ''],
        ], $this->readExport());
    }

    /**
     * A key only a translation has is still a row, and the source language's own keys are never lost.
     */
    public function testActionExportListsTheKeysOfEveryLanguage(): void
    {
        $this->writeMessageFile('en-US', ['Only in source' => '']);
        $this->writeMessageFile('de', ['Only in German' => 'Nur auf Deutsch']);

        $this->export($this->path);

        self::assertSame([
            ['en-US', 'de'],
            ['Only in German', 'Nur auf Deutsch'],
            ['Only in source', ''],
        ], $this->readExport());
    }

    /**
     * A language without message files gets a column of its own, ready for its first translation.
     */
    public function testActionExportWithLanguages(): void
    {
        $this->export(self::FIXTURE_PATH, ['languages' => 'fr']);

        self::assertSame([
            ['en-US', 'fr'],
            ['Language', ''],
        ], $this->readExport('translations-fr.xlsx'));
    }

    public function testActionExportWithLanguagesAndForcedTranslationLocksTheSourceLanguage(): void
    {
        $this->setForcedTranslation();
        $this->export(self::FIXTURE_PATH, ['languages' => 'fr,de']);

        self::assertSame([
            ['key', 'en-US', 'fr', 'de'],
            ['Language', '', '', 'Sprache'],
        ], $this->readExport('translations-fr-de.xlsx'));

        $worksheet = (new XlsxReader())->load("$this->path/translations-fr-de.xlsx")->getSheetByName('app')
            ?? self::fail('The export has no "app" worksheet.');

        self::assertSame(Protection::PROTECTION_INHERIT, $worksheet->getStyle('B2')->getProtection()->getLocked());
        self::assertSame(Protection::PROTECTION_UNPROTECTED, $worksheet->getStyle('C2')->getProtection()->getLocked());
    }

    public function testActionExportWithLanguagesNamingTheSourceLanguage(): void
    {
        $this->setForcedTranslation();
        $this->export(self::FIXTURE_PATH, ['languages' => 'en-US']);

        $worksheet = (new XlsxReader())->load("$this->path/translations-en-US.xlsx")->getSheetByName('app')
            ?? self::fail('The export has no "app" worksheet.');

        self::assertSame([['key', 'en-US'], ['Language', '']], $worksheet->toArray(''));
        self::assertSame(Protection::PROTECTION_UNPROTECTED, $worksheet->getStyle('B2')->getProtection()->getLocked());
    }

    public function testActionExportWithInvalidLanguage(): void
    {
        $this->expectExceptionMessage('"../de" is not a valid category or language.');
        $this->createController()->runAction('export', ['messagePath' => self::FIXTURE_PATH, 'languages' => '../de', $this->path]);
    }

    public function testActionExportWithCategories(): void
    {
        FileHelper::copyDirectory(self::FIXTURE_PATH, $this->path);
        $this->writeMessageFile('de', ['Key' => 'Schlüssel'], 'other');

        $this->export($this->path, ['categories' => 'other']);

        $spreadsheet = (new XlsxReader())->load("$this->path/translations.xlsx");
        self::assertSame(['other'], $spreadsheet->getSheetNames());
    }

    public function testActionExportWithUnknownCategory(): void
    {
        $this->expectExceptionMessage('No message files found for category "other".');
        $this->createController()->runAction('export', ['messagePath' => self::FIXTURE_PATH, 'categories' => 'other', $this->path]);
    }

    public function testActionExportWithoutMessageFiles(): void
    {
        $this->expectExceptionMessageMatches('/^No message files found in /');
        $this->createController()->runAction('export', ['messagePath' => $this->path, $this->path]);
    }

    public function testActionImportWithMissingSourceFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing required arguments: source');

        $this->createController()->runAction('import');
    }

    public function testActionImportWithInvalidSourceFile(): void
    {
        $this->expectExceptionMessageMatches('/^Failed to read source file/');
        $this->createController()->runAction('import', ["$this->path/invalid.xlsx"]);
    }

    public function testActionImportWithInvalidSourceLanguage(): void
    {
        $this->expectExceptionMessage('Source language "en-US" must be the first column in worksheet "app".');
        $this->import([['de', 'en-US']]);
    }

    public function testActionImportRefusesALanguageLeavingTheMessagePath(): void
    {
        $this->writeMessageFile('de', ['Key' => 'Schlüssel']);

        try {
            $this->import([
                ['en-US', 'de', '../config'],
                ['Key', 'Anderer Schlüssel', 'attacker'],
            ]);

            self::fail('The import must be refused.');
        } catch (Exception $exception) {
            self::assertSame('"../config" is not a valid category or language.', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist(dirname($this->path) . '/config');
        self::assertSame(['Key' => 'Schlüssel'], $this->readMessageFile('de'));
    }

    public function testActionImportRefusesACategoryLeavingTheMessagePath(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getSheet(0)->setTitle('..')->fromArray([['en-US']]);

        $filename = "$this->path/app.xlsx";
        (new XlsxWriter($spreadsheet))->save($filename);

        $this->expectExceptionMessage('".." is not a valid category or language.');
        $this->createController()->runAction('import', ['messagePath' => $this->path, $filename]);
    }

    public function testActionImportWithForcedTranslationAndInvalidKeyColumn(): void
    {
        $this->setForcedTranslation();

        $this->expectExceptionMessage('Key must be the first column in worksheet "app".');
        $this->import([['de', 'en-US']]);
    }

    public function testActionImportWithoutPreviousTranslations(): void
    {
        $this->import([
            ['en-US', 'de'],
            ['This is a test string', 'Das ist ein Teststring'],
        ]);

        self::assertSame(['This is a test string' => ''], $this->readMessageFile('en-US'));
        self::assertSame(['This is a test string' => 'Das ist ein Teststring'], $this->readMessageFile('de'));

        self::assertStringStartsWith("<?php\nreturn [", $this->readMessageFileContent('de'));
    }

    public function testActionImportWritesTheConfiguredDocBlockIntoANewFile(): void
    {
        $docBlock = "/**\n * Generated.\n */";

        $this->import([
            ['en-US', 'de'],
            ['Language', 'Sprache'],
        ], ['phpDocBlock' => $docBlock]);

        self::assertStringStartsWith("<?php\n$docBlock\nreturn [", $this->readMessageFileContent('de'));
    }

    public function testActionImportWithPreviousTranslations(): void
    {
        FileHelper::copyDirectory(self::FIXTURE_PATH, $this->path);

        $this->import([
            ['en-US', 'de'],
            ['This is a test string', 'Das ist ein Teststring'],
        ]);

        self::assertSame([
            'Language' => 'Sprache',
            'This is a test string' => 'Das ist ein Teststring',
        ], $this->readMessageFile('de'));

        self::assertStringStartsWith("<?php\n/**\n * Message translations.\n", $this->readMessageFileContent('de'));
    }

    public function testActionImportWithLanguages(): void
    {
        $this->import([
            ['en-US', 'de', 'fr'],
            ['Language', 'Sprache', 'Langue'],
        ], ['languages' => 'fr']);

        self::assertSame(['Language' => 'Langue'], $this->readMessageFile('fr'));
        self::assertFileDoesNotExist("$this->path/de/app.php");
        self::assertFileDoesNotExist("$this->path/en-US/app.php");
    }

    public function testActionImportWithCategories(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getSheet(0)->setTitle('app')->fromArray([['en-US', 'de'], ['Language', 'Sprache']]);
        $spreadsheet->createSheet()->setTitle('other')->fromArray([['en-US', 'de'], ['Key', 'Schlüssel']]);

        $filename = "$this->path/translations.xlsx";
        (new XlsxWriter($spreadsheet))->save($filename);

        $controller = $this->createController();
        $exitCode = $controller->runAction('import', ['messagePath' => $this->path, 'categories' => 'other', $filename]);

        self::assertSame(ExitCode::OK, $exitCode);
        self::assertSame(['Key' => 'Schlüssel'], $this->readMessageFile('de', 'other'));
        self::assertFileDoesNotExist("$this->path/de/app.php");
    }

    public function testActionImportWithUnknownCategory(): void
    {
        $this->expectExceptionMessage('The workbook has no worksheet "other".');
        $this->import([['en-US', 'de']], ['categories' => 'other']);
    }

    public function testActionImportWithForcedTranslation(): void
    {
        $this->setForcedTranslation();

        $this->import([
            ['key', 'en-US', 'de'],
            ['TEST_STRING', 'This is a test string', 'Das ist ein Teststring'],
        ]);

        self::assertSame(['TEST_STRING' => 'This is a test string'], $this->readMessageFile('en-US'));
        self::assertSame(['TEST_STRING' => 'Das ist ein Teststring'], $this->readMessageFile('de'));
    }

    /**
     * Excel keeps the extent of a column or row that once held a value, so a sheet can end in empty cells.
     */
    public function testActionImportSkipsEmptyRowsAndColumns(): void
    {
        $this->import([
            ['en-US', 'de', null],
            ['Language', 'Sprache', null],
            [null, null, null],
        ]);

        self::assertSame(['Language' => 'Sprache'], $this->readMessageFile('de'));
    }

    /**
     * A numeric key is an integer in a PHP array, which a spread would renumber.
     */
    public function testActionImportKeepsNumericKeys(): void
    {
        $this->setForcedTranslation();
        $this->writeMessageFile('de', ['335' => 'Dreihundertfünfunddreißig', 'B' => 'b']);

        $this->import([
            ['key', 'de'],
            ['A', 'a'],
        ]);

        self::assertSame(['335' => 'Dreihundertfünfunddreißig', 'A' => 'a', 'B' => 'b'], $this->readMessageFile('de'));
    }

    /**
     * Neither a formula-like nor a numeric translation may change on the way through Excel, nor may text in any
     * script, decomposed or not.
     */
    public function testExportAndImportRoundTrip(): void
    {
        $this->setForcedTranslation();

        $translations = [
            '0042' => '0042',
            'CJK' => '翻訳',
            'CYRILLIC' => 'Перевод',
            'EMOJI' => 'Übersetzung 🌍',
            'FORMULA' => '=SUM(A1:A2)',
            'NFD' => "Mu\u{308}ller",
            'NUMBER' => '1.50',
            'POLISH' => 'Tłumaczenie ą',
            'SWEDISH' => 'Översättning Å',
        ];

        $this->writeMessageFile('de', $translations);
        $before = $this->readMessageFileContent('de');

        $this->export($this->path);

        $controller = $this->createController();
        $exitCode = $controller->runAction('import', ['messagePath' => $this->path, "$this->path/translations.xlsx"]);

        self::assertSame(ExitCode::OK, $exitCode);
        self::assertSame($translations, $this->readMessageFile('de'));
        self::assertSame($before, $this->readMessageFileContent('de'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function export(string $messagePath, array $params = []): void
    {
        $exitCode = $this->createController()->runAction('export', [
            'messagePath' => $messagePath,
            ...$params,
            $this->path,
        ]);

        self::assertSame(ExitCode::OK, $exitCode);
    }

    /**
     * @param list<list<string|null>> $rows
     * @param array<string, mixed> $params
     */
    private function import(array $rows, array $params = []): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getSheet(0)->setTitle('app')->fromArray($rows);

        $filename = "$this->path/app.xlsx";
        (new XlsxWriter($spreadsheet))->save($filename);

        $exitCode = $this->createController()->runAction('import', [
            'messagePath' => $this->path,
            ...$params,
            $filename,
        ]);

        self::assertSame(ExitCode::OK, $exitCode);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readExport(string $filename = 'translations.xlsx'): array
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);

        $worksheet = $reader->load("$this->path/$filename")->getSheetByName('app')
            ?? self::fail('The export has no "app" worksheet.');

        return $worksheet->toArray('');
    }

    /**
     * @param array<array-key, string> $messages
     */
    private function writeMessageFile(string $language, array $messages, string $category = 'app'): void
    {
        FileHelper::createDirectory("$this->path/$language");
        file_put_contents("$this->path/$language/$category.php", "<?php\nreturn " . VarDumper::export($messages) . ";\n");
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readMessageFile(string $language, string $category = 'app'): array
    {
        $messages = require "$this->path/$language/$category.php";
        self::assertIsArray($messages);

        return $messages;
    }

    private function readMessageFileContent(string $language): string
    {
        $content = file_get_contents("$this->path/$language/app.php");
        self::assertNotFalse($content);

        return $content;
    }

    private function setForcedTranslation(): void
    {
        Yii::$app->getI18n()->translations['app'] = [
            'class' => PhpMessageSource::class,
            'sourceLanguage' => 'en-US',
            'forceTranslation' => true,
        ];
    }

    private function createController(): TestTranslationController
    {
        $controller = new TestTranslationController('translation', Yii::$app);
        $controller->interactive = false;

        return $controller;
    }
}

class TestTranslationController extends TranslationController
{
    use StdOutBufferControllerTrait;
}
