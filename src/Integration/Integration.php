<?php

declare(strict_types=1);

namespace Minbaby\HyperfSentry\Integration;

use Minbaby\HyperfSentry\SentryContext;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\State\Scope;

class Integration implements IntegrationInterface
{
    /**
     * @var null|string
     */
    private static $transaction;

    /**
     * {@inheritdoc}
     */
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(function (Event $event): Event {
            $self = SentryContext::getHub()->getIntegration(self::class);

            if (! $self instanceof self) {
                return $event;
            }

            $event->setTransaction($self->getTransaction());

            return $event;
        });
    }

    /**
     * Adds a breadcrumb if the integration is enabled for Laravel.
     */
    public static function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        $self = SentryContext::getHub()->getIntegration(self::class);

        if (! $self instanceof self) {
            return;
        }

        SentryContext::getHub()->addBreadcrumb($breadcrumb);
    }

    /**
     * Configures the scope if the integration is enabled for Laravel.
     */
    public static function configureScope(callable $callback): void
    {
        $self = SentryContext::getHub()->getIntegration(self::class);

        if (! $self instanceof self) {
            return;
        }

        SentryContext::getHub()->configureScope($callback);
    }

    /**
     * @return null|string
     */
    public static function getTransaction()
    {
        return self::$transaction;
    }

    /**
     * @param null|string $transaction
     */
    public static function setTransaction($transaction): void
    {
        self::$transaction = $transaction;
    }

    /**
     * Block until all async events are processed for the HTTP transport.
     *
     * @internal this is not part of the public API and is here temporarily until
     *  the underlying issue can be resolved, this method will be removed
     */
    public static function flushEvents(): void
    {
        $client = SentryContext::getHub()->getClient();

        if ($client instanceof ClientInterface) {
            $client->flush();
        }
    }
}
