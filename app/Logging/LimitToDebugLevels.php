<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FilterHandler;
use Monolog\Level;

/**
 * Tap that restricts a channel to the "below error" levels only.
 *
 * Monolog levels are cumulative (a handler set to DEBUG also catches
 * ERROR and above), so to keep the debug channel limited to debug, info,
 * notice and warning — and let the "laravel" channel own everything from
 * ERROR up — each handler is wrapped in a FilterHandler capped at WARNING.
 */
class LimitToDebugLevels
{
    public function __invoke(Logger $logger): void
    {
        $handlers = array_map(
            fn ($handler) => new FilterHandler($handler, Level::Debug, Level::Warning),
            $logger->getLogger()->getHandlers(),
        );

        $logger->getLogger()->setHandlers($handlers);
    }
}
