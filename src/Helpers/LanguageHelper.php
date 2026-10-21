<?php

declare(strict_types=1);

namespace Hirtz\Translation\Helpers;

class LanguageHelper
{
    private const array RIGHT_TO_LEFT_LANGUAGES = ['ar', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];
    private const array RIGHT_TO_LEFT_SCRIPTS = ['adlm', 'arab', 'hebr', 'nkoo', 'rohg', 'syrc', 'thaa'];

    /**
     * A script subtag decides over the language: `az-Arab` is right-to-left, `ku-Latn` is not.
     */
    public static function isRightToLeft(string $language): bool
    {
        $subtags = preg_split('/[-_]/', strtolower($language)) ?: [];

        foreach (array_slice($subtags, 1) as $subtag) {
            if (strlen($subtag) === 4 && ctype_alpha($subtag)) {
                return in_array($subtag, self::RIGHT_TO_LEFT_SCRIPTS, true);
            }
        }

        return in_array($subtags[0] ?? '', self::RIGHT_TO_LEFT_LANGUAGES, true);
    }
}
