<?php

declare(strict_types=1);

namespace Hirtz\Translation\Formats;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Hirtz\Translation\Models\Catalog;
use yii\console\Exception;

class Xliff
{
    private const string NAMESPACE = 'urn:oasis:names:tc:xliff:document:1.2';

    /**
     * @param list<Catalog> $catalogs
     */
    public static function write(string $filename, string $language, array $catalogs): void
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $xliff = $document->createElementNS(self::NAMESPACE, 'xliff');
        $xliff->setAttribute('version', '1.2');
        $document->appendChild($xliff);

        foreach ($catalogs as $catalog) {
            $file = $document->createElementNS(self::NAMESPACE, 'file');
            $file->setAttribute('original', $catalog->category);
            $file->setAttribute('datatype', 'plaintext');
            $file->setAttribute('source-language', $catalog->sourceLanguage);
            $file->setAttribute('target-language', $language);
            $xliff->appendChild($file);

            $body = $document->createElementNS(self::NAMESPACE, 'body');
            $file->appendChild($body);

            foreach ($catalog->keys as $key) {
                $translation = $catalog->getTranslation($language, $key);

                // XML 1.0 cannot hold a control character, which would leave the whole file unreadable.
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $translation . $catalog->getSource($key) . $key)) {
                    throw new Exception("The message \"$catalog->category: $key\" holds a control character XLIFF cannot store.");
                }

                $unit = $document->createElementNS(self::NAMESPACE, 'trans-unit');
                $unit->setAttribute('id', $key);
                $unit->setAttribute('resname', $key);
                $unit->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $body->appendChild($unit);

                // A source text missing from the source language still needs a source to translate from
                $source = $document->createElementNS(self::NAMESPACE, 'source');
                $source->appendChild($document->createTextNode($catalog->getSource($key) ?: $key));
                $unit->appendChild($source);

                $target = $document->createElementNS(self::NAMESPACE, 'target');
                $target->setAttribute('state', $translation === '' ? 'needs-translation' : 'translated');
                $target->appendChild($document->createTextNode($translation));
                $unit->appendChild($target);
            }
        }

        if ($document->save($filename) === false) {
            throw new Exception("Failed to write \"$filename\".");
        }
    }

    /**
     * A unit without a `<target>` is left out, as CAT tools write an untranslated one.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public static function read(string $filename): array
    {
        $document = new DOMDocument();

        if (!@$document->load($filename, LIBXML_NONET)) {
            throw new Exception("Failed to read XLIFF file \"$filename\".");
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', self::NAMESPACE);

        $messages = [];

        foreach ($xpath->query('/x:xliff/x:file') ?: [] as $file) {
            if (!$file instanceof DOMElement) {
                continue;
            }

            $category = $file->getAttribute('original');
            $language = $file->getAttribute('target-language');

            if ($language === '') {
                throw new Exception("The file \"$category\" has no target language.");
            }

            $messages[$category][$language] ??= [];

            foreach ($xpath->query('x:body//x:trans-unit', $file) ?: [] as $unit) {
                if (!$unit instanceof DOMElement) {
                    continue;
                }

                $target = self::getTarget($unit);

                if ($target !== null) {
                    $key = $unit->getAttribute('resname') ?: $unit->getAttribute('id');
                    $messages[$category][$language][$key] = $target->textContent;
                }
            }
        }

        return $messages;
    }

    /**
     * Only the unit's own: an `<alt-trans>` holds a `<target>` too.
     */
    private static function getTarget(DOMElement $unit): ?DOMElement
    {
        foreach ($unit->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'target' && $node->namespaceURI === self::NAMESPACE) {
                return $node;
            }
        }

        return null;
    }
}
