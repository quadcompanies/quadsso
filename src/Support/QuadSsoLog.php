<?php

namespace QuadCompanies\QuadSSO\Support;

use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for everything this package writes to the log.
 *
 * The split that matters:
 *
 *   trace()/info() — the happy path. What the package is doing and why: a
 *   redirect issued, an identity resolved by this route rather than that one,
 *   an API hit received. Off by default, because it is verbose and carries
 *   personal data. Turn it on with QUADSSO_LOGGING=true.
 *
 *   warning()/error() — refusals and misconfiguration. Always written. A
 *   package whose security decisions are only visible when debug logging
 *   happens to be enabled cannot be audited after the fact, and the moment you
 *   need "why was this login refused" is exactly the moment nobody had the flag
 *   turned on.
 */
class QuadSsoLog
{
    public const SSO = 'sso_events';
    public const SLO = 'slo_events';
    public const API = 'api_events';

    /**
     * Is the info-level trace switched on for this category?
     *
     * The master switch turns on every category; the per-category flags remain
     * for anyone who wants just one stream.
     */
    public static function enabled(string $category): bool
    {
        if (config('quadsso.logging.enabled', false)) {
            return true;
        }

        return (bool) config("quadsso.logging.{$category}", false);
    }

    /**
     * A step in a flow. Only written when the category is switched on.
     */
    public static function trace(string $category, string $message, array $context = []): void
    {
        if (!static::enabled($category)) {
            return;
        }

        static::channel()->info('QuadSSO: ' . $message, $context);
    }

    /**
     * Lower-level detail — request bodies, token shapes. Same gate as trace().
     */
    public static function debug(string $category, string $message, array $context = []): void
    {
        if (!static::enabled($category)) {
            return;
        }

        static::channel()->debug('QuadSSO: ' . $message, $context);
    }

    /**
     * A refusal, or something the operator needs to know about. Always written.
     */
    public static function warning(string $message, array $context = []): void
    {
        static::channel()->warning('QuadSSO: ' . $message, $context);
    }

    /**
     * A failure or misconfiguration. Always written.
     */
    public static function error(string $message, array $context = []): void
    {
        static::channel()->error('QuadSSO: ' . $message, $context);
    }

    /**
     * Resolve the configured channel, falling back to the application default.
     * LogManager::channel(null) resolves the default driver, so no branch is
     * needed — but the facade root is not always a LogManager in tests, hence
     * the guard.
     */
    private static function channel(): LoggerInterface
    {
        $channel = config('quadsso.logging.channel');
        $logger = Log::getFacadeRoot();

        if ($channel && $logger instanceof LogManager) {
            $resolved = $logger->channel($channel);

            // A channel that resolves to something unusable must not take the
            // whole request down — logging is never worth failing a login over.
            if ($resolved instanceof LoggerInterface) {
                return $resolved;
            }
        }

        return $logger;
    }
}
