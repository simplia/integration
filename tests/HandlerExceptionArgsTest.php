<?php

namespace Simplia\Integration\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException as SymfonyInvalidArgument;

/**
 * handler.php must switch zend.exception_ignore_args on before anything can
 * throw: bref ships zend.exception_ignore_args=0, Sentry serialises the
 * arguments of every exception frame, and the proxy client's defaults (passed
 * as an argument to CurlHttpClient::prepareRequest()) hold the Lambda's client
 * private key in extra.curl[CURLOPT_PROXY_SSLKEY_BLOB].
 *
 * Each case runs in a fresh PHP process started with
 * zend.exception_ignore_args=0 (bref's value): once with the real handler.php
 * loaded from a vendor-like layout, once without it as the control.
 */
class HandlerExceptionArgsTest extends TestCase {
    private const KEY_MARKER = 'MIGHsecretkeymaterialDoNotLeak';

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/handler-args-' . bin2hex(random_bytes(6));
        // handler.php requires __DIR__/../../autoload.php and __DIR__/../../../index.php,
        // i.e. it expects to live at <app>/vendor/simplia/integration/handler.php.
        mkdir($this->dir . '/vendor/simplia/integration', 0700, true);
        copy(dirname(__DIR__) . '/handler.php', $this->dir . '/vendor/simplia/integration/handler.php');
        file_put_contents($this->dir . '/vendor/autoload.php',
            '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ";\n");
        file_put_contents($this->dir . '/index.php', "<?php return static fn() => null;\n");
        file_put_contents($this->dir . '/probe.php', self::probe());
    }

    protected function tearDown(): void {
        foreach (['vendor/simplia/integration/handler.php', 'vendor/autoload.php', 'index.php', 'probe.php'] as $f) {
            @unlink("$this->dir/$f");
        }
        foreach (['vendor/simplia/integration', 'vendor/simplia', 'vendor', ''] as $d) {
            @rmdir("$this->dir/$d");
        }
    }

    public function testHandlerKeepsTheClientKeyOutOfExceptionTraces(): void {
        $result = $this->runProbe(true);

        self::assertSame('1', $result['ini'], 'handler.php must set zend.exception_ignore_args=1');
        self::assertSame(SymfonyInvalidArgument::class, $result['exception'], 'the missing-scheme URL must fail in prepareRequest');
        self::assertFalse($result['trace_has_key'], 'getTrace() args contain the client private key');
        self::assertFalse($result['sentry_has_key'], 'Sentry frame vars contain the client private key');
    }

    public function testControlWithoutHandlerTheKeyIsInTheTrace(): void {
        // Proves the probe detects the leak, i.e. the test above is not vacuous.
        $result = $this->runProbe(false);

        self::assertSame('0', $result['ini']);
        self::assertSame(SymfonyInvalidArgument::class, $result['exception']);
        self::assertTrue($result['trace_has_key'], 'expected the key in getTrace() args without handler.php');
        self::assertTrue($result['sentry_has_key'], 'expected the key in Sentry frame vars without handler.php');
    }

    /** @return array{ini: string, exception: string, trace_has_key: bool, sentry_has_key: bool} */
    private function runProbe(bool $loadHandler): array {
        // The process inherits this PHP's ini (bref's in the test image); only the setting under test is pinned.
        $cmd = [PHP_BINARY, '-d', 'zend.exception_ignore_args=0', $this->dir . '/probe.php', $loadHandler ? 'handler' : 'none', self::KEY_MARKER];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        self::assertSame(0, $code, "probe failed: $out $err");
        $result = json_decode((string)$out, true);
        self::assertIsArray($result, "probe output: $out $err");
        return $result;
    }

    private static function probe(): string {
        return <<<'PHP'
<?php
// argv: <handler|none> <key marker>
use Simplia\Integration\Proxy\ProxyConfig;
use Simplia\Integration\Proxy\ProxyHttpClient;

if ($argv[1] === 'handler') {
    require __DIR__ . '/vendor/simplia/integration/handler.php';
} else {
    require __DIR__ . '/vendor/autoload.php';
}
$key = "-----BEGIN PRIVATE KEY-----\n{$argv[2]}\n-----END PRIVATE KEY-----\n";
$client = ProxyHttpClient::create(ProxyConfig::fromParameterJson(
    'https://integration-proxy.simplia.cz:8443',
    json_encode(['cert' => "-----BEGIN CERTIFICATE-----\nMIIBcert\n-----END CERTIFICATE-----\n", 'key' => $key,
        'ca' => "-----BEGIN CERTIFICATE-----\nMIIBca\n-----END CERTIFICATE-----\n"]),
    '/integration-proxy/client/a--b',
));

$contains = static function (mixed $value, int $depth = 0) use (&$contains, $argv): bool {
    if (is_string($value)) {
        return str_contains($value, $argv[2]);
    }
    if ($depth > 12) {
        return false;
    }
    if (is_object($value)) {
        $value = (array)$value;
    }
    if (is_array($value)) {
        foreach ($value as $v) {
            if ($contains($v, $depth + 1)) {
                return true;
            }
        }
    }
    return false;
};

try {
    $client->request('POST', 'gate.vision.cz/x'); // no scheme: throws inside CurlHttpClient::prepareRequest()
    echo json_encode(['exception' => 'none']);
    exit(0);
} catch (\Throwable $e) {
    $traceHasKey = false;
    $sentryHasKey = false;
    $options = new \Sentry\Options();
    $builder = new \Sentry\StacktraceBuilder($options, new \Sentry\Serializer\RepresentationSerializer($options));
    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
        $traceHasKey = $traceHasKey || $contains($x->getTrace());
        foreach ($builder->buildFromException($x)->getFrames() as $frame) {
            $sentryHasKey = $sentryHasKey || $contains($frame->getVars());
        }
    }
    echo json_encode([
        'ini' => ini_get('zend.exception_ignore_args'),
        'exception' => get_class($e),
        'trace_has_key' => $traceHasKey,
        'sentry_has_key' => $sentryHasKey,
    ]);
}
PHP;
    }
}
