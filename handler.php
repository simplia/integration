<?php

// Keep function arguments out of exception traces. bref ships
// zend.exception_ignore_args=0 and Sentry serialises the arguments of every
// frame of a captured exception; the integration proxy client's default
// options (an argument of Symfony's CurlHttpClient::prepareRequest()) hold the
// Lambda's client private key, so any exception thrown there (a URL without a
// scheme, a malformed peer_fingerprint, ...) would send the key to Sentry.
// Set before anything can throw. It does not cover debug_backtrace(), which
// Sentry only uses with attach_stacktrace, hence that stays off below.
ini_set('zend.exception_ignore_args', '1');

require __DIR__ . '/../../autoload.php';

if (isset($_ENV['SENTRY_DSN'])) {
    \Sentry\init([
        'dsn' => $_ENV['SENTRY_DSN'],
        // attach_stacktrace adds a debug_backtrace() (with arguments, whatever the
        // ini setting above) to non-exception events; keep it off.
        'attach_stacktrace' => false,
    ]);
}

return new \Simplia\Integration\Handler(require __DIR__ . '/../../../index.php');
