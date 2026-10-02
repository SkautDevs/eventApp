<?php

declare(strict_types=1);

namespace Tests\Functional;

final class BasePathTest extends AppTestCase
{
    public function testEveryLinkInTheShellCarriesTheEventPrefix(): void
    {
        $html = (string) $this->request($this->createApp('obrok19'), 'GET', '/programy')->getBody();

        self::assertStringContainsString('href="/obrok19/profil"', $html);
        self::assertStringContainsString('href="/obrok19/programy"', $html);
        self::assertStringNotContainsString('href="/programy"', $html);
        self::assertStringContainsString('<meta name="event-base" content="/obrok19/">', $html);
    }

    public function testTheSkipLinkStaysInsideTheEvent(): void
    {
        // <base href="/"> would send a bare "#obsah" to "/#obsah", the picker
        foreach (['/', '/programy'] as $path) {
            $html = (string) $this->request($this->createApp('obrok19'), 'GET', $path)->getBody();

            self::assertStringContainsString('class="skip-link" href="/obrok19' . $path . '#obsah"', $html);
            self::assertDoesNotMatchRegularExpression('/href="#/', $html);
        }
    }

    public function testTheOldUnprefixedRoutesAreGone(): void
    {
        $app = $this->createApp('obrok19');

        self::assertSame(404, $this->rawRequest($app, 'GET', '/programy')->getStatusCode());
        self::assertSame(200, $this->rawRequest($app, 'GET', '/obrok19/')->getStatusCode());
    }

    public function testTheBarePrefixRedirectsToTheHomepage(): void
    {
        $response = $this->rawRequest($this->createApp('obrok19'), 'GET', '/obrok19');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/obrok19/', $response->getHeaderLine('Location'));
    }

    public function testAnotherEventsPrefixIsNotFound(): void
    {
        self::assertSame(404, $this->rawRequest($this->createApp('obrok19'), 'GET', '/obrok27/programy')->getStatusCode());
    }

    public function testTieLoginRedirectsInsideTheEvent(): void
    {
        $response = $this->request($this->createApp('obrok19'), 'POST', '/profil/tie', ['tieCode' => '']);

        self::assertSame('/obrok19/profil', $response->getHeaderLine('Location'));
    }
}
