<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Auth\Identity;
use App\Auth\SkautisGatewayInterface;

final class FakeSkautisGateway implements SkautisGatewayInterface
{
    public function getLoginUrl(string $returnUrl): string
    {
        return 'https://test.skautis/login?ReturnUrl=' . $returnUrl;
    }

    public function getLogoutUrl(string $returnUrl): string
    {
        return 'https://test.skautis/logout?ReturnUrl=' . $returnUrl;
    }

    public function loginFromPost(array $postData): Identity
    {
        return new Identity(type: 'skautis', displayName: 'Jan Novák (jnovak)', skautisUserId: 123);
    }
}
