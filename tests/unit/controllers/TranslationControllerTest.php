<?php

namespace davidhirtz\yii2\translation\tests\unit\controllers;

use Codeception\Test\Unit;
use davidhirtz\yii2\skeleton\codeception\traits\StdOutBufferControllerTrait;
use davidhirtz\yii2\skeleton\console\Application;
use davidhirtz\yii2\skeleton\helpers\FileHelper;
use davidhirtz\yii2\translation\controllers\TranslationController;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Yii;
use yii\i18n\PhpMessageSource;

class TranslationControllerTest extends Unit
{
    protected ?TranslationControllerMock $controller = null;
    protected ?string $messagePath = '@runtime/messages';

    protected function _before(): void
    {
        $config = require __DIR__ . '/../../config/test.php';
        (new Application($config));

        $this->controller = new TranslationControllerMock('translation', Yii::$app);

        parent::_before();
    }

    public function _after(): void
    {
        FileHelper::removeDirectory(Yii::getAlias($this->messagePath));
    }

    /**
     * @see TranslationController::actionExport()
     */
    public function testActionExport(): void
    {
        $this->controller->runAction('export', [
            'messagePath' => '@tests/support/messages',
            $this->messagePath,
        ]);

        $filename = Yii::getAlias("$this->messagePath/translations.xlsx");
        self::assertFileExists($filename);

        $data = $this->getArrayDataFromExcelFile($filename);

        self::assertEquals($data[0][0], Yii::$app->sourceLanguage);
    }

    /**
     * @see TranslationController::actionExport()
     */
    public function testActionExportWithForcedTranslation(): void
    {
        $this->setForcedTranslation();

        $this->controller->runAction('export', [
            'messagePath' => '@tests/support/messages',
            $this->messagePath,
        ]);

        $filename = Yii::getAlias("$this->messagePath/translations.xlsx");
        self::assertFileExists($filename);

        $data = $this->getArrayDataFromExcelFile($filename);

        self::assertEquals(['key', 'de', 'en-US'], $data[0]);
    }

    /**
     * @see TranslationController::actionImport()
     */
    public function testActionImportWithMissingSourceFile()
    {
        self::expectExceptionMessage('Source file cannot be empty.');
        $this->controller->runAction('import');
    }

    /**
     * @see TranslationController::actionImport()
     */
    public function testActionImportWithInvalidSourceFile()
    {
        self::expectExceptionMessageMatches('/^Failed to read source file/');
        $this->controller->runAction('import', ["$this->messagePath/invalid.xlsx"]);
    }

    /**
     * @see TranslationController::actionImport()
     */
    public function testActionImportWithInvalidSourceLanguage()
    {
        $language = Yii::$app->sourceLanguage;
        self::expectExceptionMessage("Source language \"$language\" must be the first column in worksheet \"app\".");

        $filename = $this->getTestExcelFile([
            ['de', $language],
        ]);

        $this->controller->runAction('import', [$filename]);
    }

    /**
     * @see TranslationController::actionImport()
     */
    public function testActionImportWithForcedTranslationAndInvalidKeyColumn()
    {
        $this->setForcedTranslation();

        $language = Yii::$app->sourceLanguage;
        self::expectExceptionMessage("Key must be the first column in worksheet \"app\".");

        $filename = $this->getTestExcelFile([
            ['de', $language],
        ]);

        $this->controller->runAction('import', [$filename]);
    }

    /**
     * @see TranslationController::actionImport()
     */
    public function testActionImportWithoutPreviousTranslations(): void
    {
        $filename = $this->getTestExcelFile([
            ['en-US', 'de'],
            ['This is a test string', 'Das ist ein Teststring'],
        ]);

        $this->controller->runAction('import', [
            'messagePath' => $this->messagePath,
            $filename,
        ]);

        self::assertFileExists(Yii::getAlias('@runtime/messages/en-US/app.php'));
        self::assertFileExists(Yii::getAlias('@runtime/messages/de/app.php'));

        $data = require Yii::getAlias('@runtime/messages/de/app.php');
        self::assertEquals('Das ist ein Teststring', $data['This is a test string']);
    }

    /**
     * @see TranslationController::actionImport()
     */
    public function testActionImportWithPreviousTranslations()
    {
        FileHelper::copyDirectory(Yii::getAlias('@tests/support/messages'), Yii::getAlias($this->messagePath));

        $filename = $this->getTestExcelFile([
            ['en-US', 'de'],
            ['This is a test string', 'Das ist ein Teststring'],
        ]);

        $this->controller->runAction('import', [
            'messagePath' => $this->messagePath,
            $filename,
        ]);

        self::assertFileExists(Yii::getAlias('@runtime/messages/en-US/app.php'));
        self::assertFileExists(Yii::getAlias('@runtime/messages/de/app.php'));

        $data = require Yii::getAlias('@runtime/messages/de/app.php');

        self::assertEquals('Das ist ein Teststring', $data['This is a test string']);
        self::assertEquals('Sprache', $data['Language']);
    }

    public function testActionImportWithForcedTranslation()
    {
        $this->setForcedTranslation();

        $filename = $this->getTestExcelFile([
            ['key', 'en-US', 'de'],
            ['TEST_STRING', 'This is a test string', 'Das ist ein Teststring'],
        ]);

        $this->controller->runAction('import', [
            'messagePath' => $this->messagePath,
            $filename,
        ]);

        self::assertFileExists(Yii::getAlias('@runtime/messages/en-US/app.php'));
        self::assertFileExists(Yii::getAlias('@runtime/messages/de/app.php'));

        $data = require Yii::getAlias('@runtime/messages/en-US/app.php');
        self::assertEquals('This is a test string', $data['TEST_STRING']);

        $data = require Yii::getAlias('@runtime/messages/de/app.php');
        self::assertEquals('Das ist ein Teststring', $data['TEST_STRING']);
    }

    protected function getTestExcelFile(array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getSheet(0);
        $worksheet->setTitle('app');
        $worksheet->fromArray($data);

        $directory = Yii::getAlias('@runtime/messages');
        $filename = "$directory/app.xlsx";

        FileHelper::createDirectory($directory);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filename);

        return $filename;
    }

    protected function setForcedTranslation(): void
    {
        Yii::$app->getI18n()->translations['app'] = [
            'class' => PhpMessageSource::class,
            'forceTranslation' => true,
        ];
    }

    protected function getArrayDataFromExcelFile(string $filename): array
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filename);
        $worksheet = $spreadsheet->getSheetByName('app');

        return $worksheet->toArray();
    }
}

class TranslationControllerMock extends TranslationController
{
    use StdOutBufferControllerTrait;
}
