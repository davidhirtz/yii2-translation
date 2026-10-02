# yii2-translation

Exports a project's message files to an Excel workbook for translators and imports the workbook back into the
message files. Only the `php` message format is supported. Depends on `davidhirtz/yii2-skeleton` and
`phpoffice/phpspreadsheet`.

## Installation

```bash
composer require davidhirtz/yii2-translation --dev
```

The bundle bootstraps itself through `extra.bootstrap` (`Hirtz\Translation\Bootstrap`) and registers the
`translation` console command; the web application is left alone.

## Export

```bash
./yii translation/export [outputDir]
```

Writes `translations.xlsx` to `outputDir` (default `@runtime`), one worksheet per category and one column per
language. The sheet is protected so that only the translations can be edited; the key column and the header are
locked.

How the columns are laid out depends on the category's message source:

- **Without `forceTranslation`** the messages are keyed by their source text, so the first column is the source
  language (`en-US`), holding the text itself, followed by every other language.
- **With `forceTranslation`** (every bundle category in v3) the keys are identifiers: the first column is `key`,
  followed by every language, the source language included.

Every cell is written as text, so a translation that looks like a number or a formula survives the trip.

## Import

```bash
./yii translation/import <file>
```

Reads a workbook in the export's layout and merges each worksheet into `<messagePath>/<language>/<category>.php`.
A translation present in the workbook replaces the one in the file, an empty cell included; a key the workbook
lacks is kept. An existing file keeps its docblock, and the files are sorted by key as `yii message` sorts them,
so a round trip without edits leaves the files unchanged.

## Options

| Option          | Default     | Meaning                                                                |
|-----------------|-------------|------------------------------------------------------------------------|
| `--messagePath` | `@messages` | The directory holding one subdirectory per language                    |
| `--phpDocBlock` | `null`      | The docblock of a message file the import creates                      |
| `--sort`        | `true`      | Whether the import sorts the messages by key                           |
| `--width`       | `100`       | The width of the exported columns                                      |

A bundle's own messages are exported with its path, for example
`./yii translation/export --messagePath=@skeleton/../messages`.
