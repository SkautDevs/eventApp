<?php

declare(strict_types=1);

namespace App\Auth;

interface SkautisGatewayInterface
{
    public function getLoginUrl(string $returnUrl): string;

    public function getLogoutUrl(string $returnUrl): string;

    /** Ověří POST data ze SkautISu a vrátí identitu přihlášeného uživatele */
    public function loginFromPost(array $postData): Identity;
}
