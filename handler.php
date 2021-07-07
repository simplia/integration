<?php

require __DIR__ . '/../../autoload.php';

\Sentry\init(['dsn' => $_ENV['SENTRY_DSN']]);

return new \Simplia\Integration\Handler(require __DIR__ . '/../../../index.php');
