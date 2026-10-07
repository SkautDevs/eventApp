<?php

declare(strict_types=1);

namespace App\Telemetry;

use Sentry\Event;

/**
 * Takes this app's secrets out of every event before it leaves: the admin token in a
 * URL (old links still carry it), a TIE code or token in a form body, a whole push
 * subscribe or unsubscribe body (endpoint and keys together are the credential to push to a device),
 * a whole admin form (the message, its Podpis and the CSRF token), and the Authorization and Cookie headers. The exception message and the stack frames
 * are never touched — nothing secret is put there in the first place.
 */
final class Scrubber
{
    public const string REDACTED = '<redacted>';

    public const string SUBSCRIPTION_REDACTED = '<subscription redacted>';

    public const string ADMIN_REDACTED = '<admin form redacted>';

    private const array BODY_FIELDS = ['tieCode', 'token'];

    private const array HEADERS = ['authorization', 'cookie'];

    public static function scrub(Event $event): Event
    {
        $transaction = $event->getTransaction();
        if ($transaction !== null) {
            $event->setTransaction(self::redact($transaction));
        }

        $request = $event->getRequest();
        if ($request !== []) {
            foreach (['url', 'query_string'] as $key) {
                if (is_string($request[$key] ?? null)) {
                    $request[$key] = self::redact($request[$key]);
                }
            }

            $path = is_string($request['url'] ?? null) ? (string) parse_url($request['url'], \PHP_URL_PATH) : '';
            if (array_key_exists('data', $request) && (str_ends_with($path, '/push/subscribe') || str_ends_with($path, '/push/unsubscribe'))) {
                $request['data'] = self::SUBSCRIPTION_REDACTED;
            } elseif (array_key_exists('data', $request) && (str_ends_with($path, '/admin/notify') || str_contains($path, '/admin/notify/'))) {
                $request['data'] = self::ADMIN_REDACTED;
            } elseif (is_array($request['data'] ?? null)) {
                foreach (self::BODY_FIELDS as $field) {
                    if (array_key_exists($field, $request['data'])) {
                        $request['data'][$field] = self::REDACTED;
                    }
                }
            } elseif (is_string($request['data'] ?? null)) {
                $request['data'] = preg_replace('~(^|&)(tieCode|token)=[^&]*~', '$1$2=' . self::REDACTED, $request['data']) ?? self::REDACTED;
            }

            if (is_array($request['headers'] ?? null)) {
                foreach (array_keys($request['headers']) as $name) {
                    if (in_array(strtolower((string) $name), self::HEADERS, true)) {
                        $request['headers'][$name] = self::REDACTED;
                    }
                }
            }

            $event->setRequest($request);
        }

        $http = $event->getContexts()['http'] ?? null;
        if (is_array($http) && is_string($http['url'] ?? null)) {
            $http['url'] = self::redact($http['url']);
            $event->setContext('http', $http);
        }

        $message = $event->getMessage();
        if ($message !== null) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                self::redact($message),
                array_map(static fn (mixed $param): mixed => is_string($param) ? self::redact($param) : $param, $event->getMessageParams()),
                $formatted === null ? null : self::redact($formatted),
            );
        }

        return $event;
    }

    /** The `token` query parameter, wherever a URL or a query string carries it. */
    public static function redact(string $value): string
    {
        return preg_replace('~(^|[?&])token=[^&#]*~', '${1}token=' . self::REDACTED, $value) ?? self::REDACTED;
    }
}
