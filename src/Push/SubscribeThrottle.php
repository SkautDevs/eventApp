<?php

declare(strict_types=1);

namespace App\Push;

/**
 * A limit on new push subscriptions per address and event. The route is unauthenticated
 * and every saved row is a POST at every send, so three hundred new subscriptions per ten
 * minutes per address (an IPv6 /64) leaves room for a camp's Wi-Fi or a carrier NAT, where
 * hundreds of phones share one IPv4 address at the opening, and still bounds junk rows;
 * the on-curve key check and the strikes at send time do the rest. A re-send of a known subscription — what push.js does after every
 * login and logout — never counts.
 */
final class SubscribeThrottle
{
    public const LIMIT = 300;

    private const WINDOW = 'PT10M';

    /** rows older than this are pruned now and then, so the table stays small without a cron */
    private const KEEP = 'PT1H';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function tooMany(string $event, string $key, ?\DateTimeImmutable $now = null): bool
    {
        $since = ($now ?? new \DateTimeImmutable())->sub(new \DateInterval(self::WINDOW));
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM subscribe_attempts WHERE event = ? AND ip = ? AND attempted_at > ?');
        $statement->execute([$event, $key, self::stamp($since)]);

        return (int) $statement->fetchColumn() >= self::LIMIT;
    }

    public function record(string $event, string $key, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();
        $this->pdo->prepare('INSERT INTO subscribe_attempts (event, ip, attempted_at) VALUES (?, ?, ?)')
            ->execute([$event, $key, self::stamp($at)]);

        if (random_int(1, 100) === 1) {
            $this->pdo->prepare('DELETE FROM subscribe_attempts WHERE attempted_at < ?')
                ->execute([self::stamp($at->sub(new \DateInterval(self::KEEP)))]);
        }
    }

    /** UTC, so the text comparison holds across the DST change */
    private static function stamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
