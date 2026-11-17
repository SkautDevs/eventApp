<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * A limit on pairing starts, so one phone cannot burn kissj's cap on pending pairings.
 * Every start counts. A camp behind one NAT puts hundreds of readers on one address, so
 * the limit is per (event, address) and far above any honest rush: three hundred starts
 * per ten minutes, the same room SubscribeThrottle gives a camp.
 */
final class PairingThrottle
{
    public const int LIMIT = 300;

    private const string WINDOW = 'PT10M';

    /** rows older than this are pruned now and then, so the table stays small without a cron */
    private const string KEEP = 'PT1H';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function tooMany(string $event, string $ip, ?\DateTimeImmutable $now = null): bool
    {
        $since = ($now ?? new \DateTimeImmutable())->sub(new \DateInterval(self::WINDOW));
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM pairing_attempts WHERE event = ? AND ip = ? AND attempted_at > ?');
        $statement->execute([$event, $ip, self::stamp($since)]);

        return (int) $statement->fetchColumn() >= self::LIMIT;
    }

    public function record(string $event, string $ip, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();
        $this->pdo->prepare('INSERT INTO pairing_attempts (event, ip, attempted_at) VALUES (?, ?, ?)')
            ->execute([$event, $ip, self::stamp($at)]);

        if (random_int(1, 100) === 1) {
            $this->pdo->prepare('DELETE FROM pairing_attempts WHERE attempted_at < ?')
                ->execute([self::stamp($at->sub(new \DateInterval(self::KEEP)))]);
        }
    }

    /** UTC, so the text comparison holds across the DST change */
    private static function stamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
