<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;

class CustomizeFormatter
{
    /**
     * Tap method for customizing the Monolog instance.
     *
     * @param  \Monolog\Logger  $logger
     * @return void
     */
    public function __invoke($logger)
    {
        /** @var \Monolog\Handler\HandlerInterface $handler */
        foreach ($logger->getHandlers() as $handler) {
            // format: [timestamp] channel.level: message context extra
            $format = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
            $dateFormat = "Y-m-d H:i:s.u"; // microseconds precision
            $handler->setFormatter(new LineFormatter($format, $dateFormat, true, true));
        }
    }
}
