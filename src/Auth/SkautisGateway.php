<?php

declare(strict_types=1);

namespace App\Auth;

use Skautis\Skautis;

final class SkautisGateway implements SkautisGatewayInterface
{
    private ?Skautis $skautis = null;

    public function __construct(
        private readonly string $appId,
        private readonly bool $testMode,
    ) {
    }

    private function skautis(): Skautis
    {
        return $this->skautis ??= Skautis::getInstance($this->appId, $this->testMode);
    }

    public function getLoginUrl(string $returnUrl): string
    {
        return $this->skautis()->getLoginUrl($returnUrl);
    }

    public function getLogoutUrl(string $returnUrl): string
    {
        return $this->skautis()->getLogoutUrl() . '&ReturnUrl=' . $returnUrl;
    }

    public function loginFromPost(array $postData): Identity
    {
        $this->skautis()->setLoginData($postData);
        $user = $this->skautis()->usr->UserDetail();

        return new Identity(
            type: 'skautis',
            displayName: sprintf('%s (%s)', $user->Person, $user->UserName),
            skautisUserId: (int) $user->ID,
        );
    }
}
