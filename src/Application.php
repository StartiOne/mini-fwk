<?php

namespace Mini;

use Throwable;

/**
 * Application
 * 
 * Handle application specific behaviors using predefined hooks methods. You can extend it in your app
 *
 * @package Mini
 */
class Application
{
    public function afterContainerSetUp()
    {
        // Is exected before router initialize
    }

    public function afterConfigurationSetup()
    {
        // Is exected before router initialize
    }

    public function onException($exception)
    {
        if (defined('IS_CONSOLE')) {
            $c = new \Colors\Color();
            echo $c($exception->getMessage())->white()->bold()->highlight('red') . PHP_EOL;
            echo $c($exception->getTraceAsString())->white()->bold()->highlight('red') . PHP_EOL;
            exit(1);
        }
        if ($exception instanceof \Mini\Validation\ValidationException) {
            response()->json([
                'error' => [
                    'detail' => $exception->errors
                ]
            ], 400);
        } else {
            response()->json([
                'error' => [
                    'detail' => $exception->getMessage() . ' ' . $exception->getTraceAsString()
                ]
            ], 500);
        }
    }
}
