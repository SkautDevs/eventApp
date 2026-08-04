<?php

declare(strict_types=1);

namespace App\Auth;

interface SkautisGatewayInterface
{
    public function getLoginUrl(string $returnUrl): string;

    public function getLogoutUrl(string $returnUrl): string;

    /** Verifies the POST data from SkautIS and returns the logged-in user's identity */
    public function loginFromPost(array $postData): Identity;
}
