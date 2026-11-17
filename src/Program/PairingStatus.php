<?php

declare(strict_types=1);

namespace App\Program;

/** What a pairing poll answers, spelled as kissj spells it. */
enum PairingStatus: string
{
    case Pending = 'pending';
    case SlowDown = 'slowDown';
    case Expired = 'expired';
    case Denied = 'denied';
    case Approved = 'approved';
}
