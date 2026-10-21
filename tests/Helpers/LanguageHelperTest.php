<?php

declare(strict_types=1);

namespace Hirtz\Translation\Tests\Helpers;

use Hirtz\Skeleton\Test\TestCase;
use Hirtz\Translation\Helpers\LanguageHelper;

class LanguageHelperTest extends TestCase
{
    public function testIsRightToLeft(): void
    {
        foreach (['ar', 'ar-EG', 'he', 'fa_IR', 'ur', 'az-Arab'] as $language) {
            self::assertTrue(LanguageHelper::isRightToLeft($language), $language);
        }

        foreach (['', 'de', 'en-US', 'zh-Hant', 'sd-Deva', 'ku-Latn'] as $language) {
            self::assertFalse(LanguageHelper::isRightToLeft($language), $language);
        }
    }
}
