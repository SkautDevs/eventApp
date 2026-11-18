<?php

declare(strict_types=1);

namespace App\Module\Profile\View;

/**
 * The pairing in progress as Profil shows it: the user code grouped for reading
 * (`483 291`) and kissj's verification URL, which the `Otevřít KissJ` link opens.
 */
final readonly class PairingView
{
    public function __construct(
        public string $userCode,
        public string $verificationUrl,
    ) {}
}
