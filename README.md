# yii2-translation

Exports a project's message files to an Excel workbook or XLIFF files for translators, imports them back into the
message files and reports what is left to translate. Only the `php` message format is supported. Depends on `davidhirtz/yii2-skeleton` and
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

`--languages` (`-l`) exports only the languages named, a language without message files included, as an empty
column for its first translation; the file is then named after them (`translations-fr.xlsx`). A category with
`forceTranslation` keeps the source language as a locked reference column unless it is named. `--categories`
(`-c`) exports only the worksheets named:

```bash
./yii translation/export -l=fr -c=app,cms
```

`--missing` (`-m`) exports only the rows a language still lacks, so a later round sends the translator only what
is new; a category with nothing missing gets no worksheet. Columns of a right-to-left language (Arabic, Hebrew,
Persian, Urdu, …) are aligned right to left.

### XLIFF

`--format=xliff` (`-f`) writes XLIFF 1.2 for CAT tools instead: one `translations-<language>.xlf` per language, a
`<file>` per category. The source language is no target; it is every unit's `<source>`, the key standing in where
the source language lacks the text. `--languages`, `--categories` and `--missing` apply as to a workbook.

## Import

```bash
./yii translation/import <file>
```

Reads a workbook in the export's layout, or an XLIFF file (`.xlf`, `.xliff`), and merges each worksheet or
`<file>` into `<messagePath>/<language>/<category>.php`. A unit without a `<target>` is skipped.
A translation present in the workbook replaces the one in the file, an empty cell included; a key the workbook
lacks is kept. An existing file keeps its docblock, and the files are sorted by key as `yii message` sorts them,
so a round trip without edits leaves the files unchanged. `--languages` and `--categories` import only the columns
and worksheets named; a category the file lacks is refused.

A translation must name the same ICU arguments as its source (`{name}`, `{n, plural, …}`, nested ones included),
with balanced braces: otherwise the message breaks once a parameter is passed. The import lists every such
translation and writes nothing.

## Status

```bash
./yii translation/status [-l=fr,it] [-c=app]
```

Prints the translated, missing and invalid (arguments differing from the source) messages per category and
language, and exits with `65` while any is missing or invalid, so CI can hold a release on it.

## Options

| Option          | Action  | Default     | Meaning                                                      |
|-----------------|---------|-------------|--------------------------------------------------------------|
| `--categories`  | all     | `[]`        | The categories, comma-separated; all when empty              |
| `--languages`   | all     | `[]`        | The languages, comma-separated; all when empty               |
| `--messagePath` | all     | `@messages` | The directory holding one subdirectory per language          |
| `--format`      | export  | `xlsx`      | `xlsx` or `xliff`                                            |
| `--missing`     | export  | `false`     | Whether only the untranslated messages are exported          |
| `--width`       | export  | `100`       | The width of the exported columns                            |
| `--phpDocBlock` | import  | `null`      | The docblock of a message file the import creates            |
| `--sort`        | import  | `true`      | Whether the import sorts the messages by key                 |

A bundle's own messages are exported with its path, for example
`./yii translation/export --messagePath=@skeleton/../messages`.
