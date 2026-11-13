<?php

declare(strict_types=1);

namespace Hirtz\Translation;

use Hirtz\Skeleton\Console\Application as ConsoleApplication;
use Hirtz\Skeleton\Models\User;
use Hirtz\Skeleton\Web\Application;
use Hirtz\Translation\Console\Controllers\TranslationController;
use Override;
use yii\base\BootstrapInterface;

class Bootstrap implements BootstrapInterface
{
    /**
     * @param Application<User>|ConsoleApplication $app
     */
    #[Override]
    public function bootstrap($app): void
    {
        // Not `getIsConsoleRequest()`, which a web application under the CLI SAPI answers `true` as well
        if ($app instanceof ConsoleApplication) {
            $app->controllerMap['translation'] ??= TranslationController::class;
        }
    }
}
