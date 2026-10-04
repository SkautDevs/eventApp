<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * A limit against guessing TIE codes. Only an unknown code counts: a camp behind one
 * NAT puts hundreds of readers on one address, and a provider outage or a typo rush on
 * the first evening must not lock them all out. Sixty failures per ten minutes per
 * address is far above any honest rush and 360 tries an hour against billions of codes.
 */
final class LoginThrottle
{
    public const LIMIT = 60;

    private const WINDOW = 'PT10M';

    /** rows older than this are pruned now and then, so the table stays small without a cron */
    private const KEEP = 'PT1H';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function tooMany(string $event, string $ip, ?\DateTimeImmutable $now = null): bool
    {
        $since = ($now ?? new \DateTimeImmutable())->sub(new \DateInterval(self::WINDOW));
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM tie_attempts WHERE event = ? AND ip = ? AND attempted_at > ?');
        $statement->execute([$event, $ip, self::stamp($since)]);

        return (int) $statement->fetchColumn() >= self::LIMIT;
    }

    public function recordFailure(string $event, string $ip, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();
        $this->pdo->prepare('INSERT INTO tie_attempts (event, ip, attempted_at) VALUES (?, ?, ?)')
            ->execute([$event, $ip, self::stamp($at)]);

        if (random_int(1, 100) === 1) {
            $this->pdo->prepare('DELETE FROM tie_attempts WHERE attempted_at < ?')
                ->execute([self::stamp($at->sub(new \DateInterval(self::KEEP)))]);
        }
    }

    /** UTC, so the text comparison holds across the DST change */
    private static function stamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
