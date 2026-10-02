# Upgrading to 3.0

## Requirements

- PHP `^8.3`
- `davidhirtz/yii2-skeleton` `^3.8`; follow the skeleton's `UPGRADE.md` first, this bundle only adds to it
- `phpoffice/phpspreadsheet` `^5.0` (was `^2.0`)

## Renames

| v1                                                            | v3                                                    |
|---------------------------------------------------------------|-------------------------------------------------------|
| `davidhirtz\yii2\translation\`                                | `Hirtz\Translation\`                                  |
| `davidhirtz\yii2\translation\Bootstrap`                       | `Bootstrap`                                           |
| `davidhirtz\yii2\translation\controllers\TranslationController` | `Console\Controllers\TranslationController`         |
| `TranslationController::$docBlock` (unused)                   | `TranslationController::$phpDocBlock`                 |

The command keeps its name: `./yii translation/export` and `./yii translation/import`, with the same options.

## Behaviour

- `translation/import` without a file fails with Yii's `Missing required arguments: source` instead of
  `Source file cannot be empty.`
- `translation/export` fails when the message path holds no message file, instead of writing an empty workbook.
- The source language and `forceTranslation` of a category are read from its message source, so a wildcard
  entry such as `cms*` applies, as does a category's own `sourceLanguage`.
- Every exported cell is text. A v1 export turned a translation like `=…` into a formula and `0042` into `42`.
- Empty rows of a workbook are skipped, and numeric message keys are no longer renumbered by the import.

A workbook exported by v1 imports unchanged.
