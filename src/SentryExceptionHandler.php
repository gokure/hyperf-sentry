<?php

declare(strict_types=1);

namespace Minbaby\HyperfSentry;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Sentry\ClientBuilderInterface;
use Sentry\FlushableClientInterface;
use Swoole\Runtime;
use Throwable;

class SentryExceptionHandler extends ExceptionHandler
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var ConfigInterface
     */
    protected $config;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->config = $container->get(ConfigInterface::class);
    }

    /**
     * Handle the exception, and return the specified result.
     * @return ResponseInterface
     */
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->wrapCurlHooks(function () use ($throwable) {
            $clientBuilder = $this->container->get(ClientBuilderInterface::class);

            SentryContext::getHub();

            SentryContext::getHub()->captureException($throwable);

            if (($client = $clientBuilder->getClient()) instanceof FlushableClientInterface) {
                $client->flush((int)$this->config->get('sentry.flush_timeout', 2));
            }
        });

        return $response;
    }

    /**
     * Determine if the current exception handler should handle the exception,.
     *
     * @return bool
     *              If return true, then this exception handler will handle the exception,
     *              If return false, then delegate to next handler
     */
    public function isValid(Throwable $throwable): bool
    {
        return !$this->shouldntReport($throwable);
    }

    /**
     * Determine if the exception is in the "do not report" list.
     *
     * @param Throwable $throwable
     * @return bool
     */
    protected function shouldntReport(Throwable $throwable): bool
    {
        $dontReport = $this->config->get('sentry.hyperf.dont_report', []);

        return ! is_null(collect($dontReport)->first(function ($type) use ($throwable) {
            return $throwable instanceof $type;
        }));
    }

    /**
     * Wrap CURL Hooks
     *
     *  swoole 4.6.7 之前关闭 SWOOLE_HOOK_CURL/SWOOLE_HOOK_NATIVE_CURL
     *
     *   - swoole-4.5.0 支持部分 curl hook
     *   - swoole-4.6.0 之后支持 native-curl hook，但存在问题
     *   - swoole-4.6.7 修复 Symfony HttpClient 使用 native curl 的问题 (#4208)
     *
     * @param Closure $callback
     * @return mixed
     */
    protected function wrapCurlHooks(Closure $callback)
    {
        // Symfony\Component\HttpClient\CurlHttpClient required.
        if (!defined('CURLHEADER_SEPARATE')) {
            define('CURLHEADER_SEPARATE', 1);
        }

        $symfonyHttpClientSupports = !function_exists('swoole_version') || version_compare(swoole_version(), '4.6.7') >= 0;
        if ($symfonyHttpClientSupports) {
            return $callback();
        }

        $curlFlags = $hookFlags = Runtime::getHookFlags() ?? SWOOLE_HOOK_ALL;

        if (defined('SWOOLE_HOOK_CURL') && ($hookFlags & SWOOLE_HOOK_CURL)) {
            $curlFlags ^= SWOOLE_HOOK_CURL;
        }

        if (defined('SWOOLE_HOOK_NATIVE_CURL') && ($hookFlags & SWOOLE_HOOK_NATIVE_CURL)) {
            $curlFlags ^= SWOOLE_HOOK_NATIVE_CURL;
        }

        try {
            Runtime::enableCoroutine(true, $curlFlags);
            return $callback();
        } finally {
            Runtime::enableCoroutine(true, $hookFlags);
        }
    }
}
