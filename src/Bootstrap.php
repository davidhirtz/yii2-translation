<?php

namespace davidhirtz\yii2\translation;

use davidhirtz\yii2\translation\controllers\TranslationController;
use yii\base\BootstrapInterface;
use yii\console\Application;

class Bootstrap implements BootstrapInterface
{
    /**
     * @param Application $app
     */
    public function bootstrap($app): void
    {
        if ($app->getRequest()->getIsConsoleRequest()) {
            $app->controllerMap['translation'] = TranslationController::class;
        }
    }
}
