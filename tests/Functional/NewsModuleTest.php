<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Push\MessageRepository;

final class NewsModuleTest extends AppTestCase
{
    private MessageRepository $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messages = new MessageRepository(':memory:');
    }

    private function korbo(): \Slim\App
    {
        return $this->createApp('korbo26', overrides: [MessageRepository::class => $this->messages]);
    }

    private function registeredId(): int
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/events/korbo26/fixtures/registered.json'), true)['tie:KORBO1'][0];
    }

    public function testEmptyNewsSaysSo(): void
    {
        self::assertStringContainsString('Zatím žádné novinky.', (string) $this->request($this->korbo(), 'GET', '/novinky')->getBody());
    }

    public function testEveryoneSeesEventWideMessagesNewestFirst(): void
    {
        $this->messages->add('korbo26', null, 'Všem', 'Starší', 'a', 'Lung', 0, 0, null);
        $this->messages->add('korbo26', null, 'Všem', 'Novější', "řádek\nřádek", 'Lung', 0, 0, null);

        $html = (string) $this->request($this->korbo(), 'GET', '/novinky')->getBody();

        self::assertLessThan(strpos($html, 'Starší'), strpos($html, 'Novější'));
        self::assertStringNotContainsString('Lung', $html);
    }

    public function testAProgrammeMessageIsOnlyForItsRegisteredParticipants(): void
    {
        $this->messages->add('korbo26', $this->registeredId(), 'Program', 'Jen pro přihlášené', 'a', 'Lung', 0, 0, 0);
        $this->messages->add('korbo26', 999999, 'Cizí', 'Cizí program', 'a', 'Lung', 0, 0, 0);
        $app = $this->korbo();

        self::assertStringNotContainsString('Jen pro přihlášené', (string) $this->request($app, 'GET', '/novinky')->getBody());

        $this->request($app, 'POST', '/profil/tie', ['tieCode' => 'KORBO1']);
        $html = (string) $this->request($app, 'GET', '/novinky')->getBody();
        self::assertStringContainsString('Jen pro přihlášené', $html);
        self::assertStringNotContainsString('Cizí program', $html);
    }

    public function testHiddenMessagesAreNotShownAndMarkupIsText(): void
    {
        $hidden = $this->messages->add('korbo26', null, 'Všem', 'Překlep', 'a', 'Lung', 0, 0, null);
        $this->messages->setHidden('korbo26', $hidden, true, 'Lung');
        $this->messages->add('korbo26', null, 'Všem', '<b>tučně</b>', 'a', 'Lung', 0, 0, null);

        $html = (string) $this->request($this->korbo(), 'GET', '/novinky')->getBody();

        self::assertStringNotContainsString('Překlep', $html);
        self::assertStringContainsString('&lt;b&gt;tučně&lt;/b&gt;', $html);
    }

    public function testNewsInMenu(): void
    {
        self::assertStringContainsString('Novinky', (string) $this->request($this->createApp(), 'GET', '/')->getBody());
    }

    public function testDisabledFeatureIs404(): void
    {
        $obrok19 = $this->createApp();
        $minimal = $this->createApp('minimal', fixtureEvent: true);

        self::assertSame(200, $this->request($minimal, 'GET', '/novinky')->getStatusCode());
        self::assertSame(404, $this->request($minimal, 'GET', '/mapa')->getStatusCode());
        self::assertSame(200, $this->request($obrok19, 'GET', '/mapa')->getStatusCode());
    }
}
