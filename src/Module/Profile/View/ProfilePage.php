<?php

declare(strict_types=1);

namespace App\Module\Profile\View;

use App\Auth\IdentityView;
use App\Toast\Toast;

/**
 * Everything templates/profile.twig is rendered with, one property per template variable:
 * who is paired (null when nobody is), the pairing in progress (only while nobody is
 * paired), the Czech line saying how the last pairing ended (while it lasts), and the
 * login's or the logout's toast. ShowAction renders get_object_vars() of it.
 */
final readonly class ProfilePage
{
    public function __construct(
        public ?IdentityView $identity,
        public ?PairingView $pairing,
        public ?string $outcome,
        public ?Toast $toast,
    ) {}
}
