<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Game;
use App\Entity\Round;
use App\Game\DrawnBy;
use App\Game\Theme;
use App\Game\Turns;
use App\Repository\GameRepository;
use App\Tests\Functional\ScriptedModel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

/**
 * The settings screen, and what a game takes from it.
 */
final class SettingsControllerTest extends WebTestCase
{
    use ScriptedModel;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::scriptModel($this->client);
    }

    public function testTheHomePageSumsUpTheDefaults(): void
    {
        $crawler = $this->client->request('GET', '/fr');

        self::assertSame('6 manches de 60 s · on alterne · 7 thèmes · Régler', preg_replace('/\s+/', ' ', trim($crawler->filter('.game-settings')->text())));
        self::assertSame('/fr/settings', $crawler->filter('.game-settings a')->attr('href'));
    }

    public function testTheScreenShowsTheDefaultsThenWhatWasSaved(): void
    {
        $crawler = $this->client->request('GET', '/fr/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Réglages du jeu');
        $form = $crawler->selectButton('Enregistrer')->form();
        self::assertSame(array_map(static fn (Theme $theme): string => $theme->value, Theme::cases()), self::themesOf($form));
        self::assertSame('alternate', self::valueOf($form, 'turns'));
        self::assertSame('6', self::valueOf($form, 'rounds'));
        self::assertSame('60', self::valueOf($form, 'roundSeconds'));

        $this->submit($form, ['themes' => ['animals', 'nature'], 'turns' => 'player', 'rounds' => '3', 'roundSeconds' => '30']);
        self::assertResponseRedirects('/fr');

        $crawler = $this->client->followRedirect();
        self::assertSame('3 manches de 30 s · je dessine seulement · 2 thèmes sur 7 · Régler', preg_replace('/\s+/', ' ', trim($crawler->filter('.game-settings')->text())));

        $form = $this->client->request('GET', '/en/settings')->selectButton('Save')->form();
        self::assertSame(['animals', 'nature'], self::themesOf($form));
        self::assertSame('player', self::valueOf($form, 'turns'));
        self::assertSame('3', self::valueOf($form, 'rounds'));
        self::assertSame('30', self::valueOf($form, 'roundSeconds'));
        $this->client->request('GET', '/en');
        self::assertSelectorTextContains('.game-settings', '3 rounds of 30 s');
    }

    public function testTheEasyModeIsOffByDefaultAndSaidOnTheHomePageWhenOn(): void
    {
        $crawler = $this->client->request('GET', '/fr/settings');
        self::assertSelectorTextContains('.settings-easy', 'Mode facile');
        self::assertSelectorTextContains('.settings-easy .settings-hint', 'Le juge accepte les synonymes et les mots très proches, et dit « ça chauffe » plus souvent.');
        $form = $crawler->selectButton('Enregistrer')->form();
        self::assertNull($crawler->filter('input[name="game_settings[easyMode]"]')->attr('checked'), 'Off by default');

        $this->submit($form, ['easyMode' => '1']);
        self::assertResponseRedirects('/fr');
        $crawler = $this->client->followRedirect();
        self::assertSame('6 manches de 60 s · on alterne · 7 thèmes · mode facile · Régler', preg_replace('/\s+/', ' ', trim($crawler->filter('.game-settings')->text())));

        // A game keeps the mode it started with.
        self::startFirstRound($this->client);
        $easy = $this->lastGame();
        self::assertTrue($easy->isEasyMode());
        $form = $this->client->request('GET', '/fr/settings')->selectButton('Enregistrer')->form();
        $form->remove('game_settings[easyMode]');
        $this->client->submit($form);
        self::assertResponseRedirects('/fr');
        self::startFirstRound($this->client);
        self::assertFalse($this->lastGame()->isEasyMode(), 'Saved again without the box: the next game is normal');
        $this->client->request('GET', '/fr');
        self::assertStringNotContainsString('mode facile', (string) $this->client->getResponse()->getContent());
    }

    public function testNoThemeAtAllIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/en/settings');
        $form = $crawler->selectButton('Save')->form();
        $this->submit($form, ['themes' => [], 'rounds' => '2']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.settings-themes', 'Keep at least one theme');

        $this->client->request('GET', '/en');
        self::assertSelectorTextContains('.game-settings', '6 rounds of 60 s', 'Nothing was saved');
    }

    public function testEveryLanguageSumsUpTheSettingsOnOneLine(): void
    {
        foreach (['fr', 'en', 'de', 'es', 'it'] as $locale) {
            $crawler = $this->client->request('GET', '/'.$locale);

            self::assertResponseIsSuccessful();
            $line = preg_replace('/\s+/', ' ', trim($crawler->filter('.game-settings')->text()));
            self::assertStringNotContainsString('settings.', (string) $line, $locale);
            self::assertStringContainsString('6', (string) $line);
            $this->client->request('GET', '/'.$locale.'/settings');
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('settings.', (string) $this->client->getResponse()->getContent(), $locale);
        }
    }

    public function testANewGameUsesTheSettings(): void
    {
        $this->save(['themes' => ['places'], 'turns' => 'ai', 'rounds' => '2', 'roundSeconds' => '90']);

        $round = self::startFirstRound($this->client);
        $game = $this->lastGame();
        self::assertSame(2, $game->getRoundCount());
        self::assertSame(90, $game->getRoundSeconds());
        self::assertSame([Theme::Places], $game->getThemes());
        self::assertSame(Turns::AiDraws, $game->getTurns());

        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.round-number', 'Round 1 of 2');
        self::assertSelectorTextContains('.clock strong', '90');
        self::assertSelectorExists('[data-round-timer-gauge-value="[[0,1000],[10000,1000],[90000,100]]"]');
        $this->client->request('POST', $round.'/give-up');

        $this->client->request('POST', '/games/'.$game->getId()->toRfc4122().'/rounds');
        $second = $this->lastGame()->getRoundCount();
        self::assertSame(2, $second);
        $rounds = self::getContainer()->get('doctrine')->getRepository(Round::class)->findBy(['game' => $game], ['number' => 'ASC']);
        self::assertSame([Theme::Places, Theme::Places], array_map(static fn (Round $round): Theme => $round->getTheme(), $rounds));
        self::assertSame([DrawnBy::Ai, DrawnBy::Ai], array_map(static fn (Round $round): DrawnBy => $round->getDrawnBy(), $rounds));
    }

    public function testThePlayerWhoOnlyDrawsDrawsEveryRoundWhateverTheForm(): void
    {
        $this->save(['themes' => ['places'], 'turns' => 'player', 'rounds' => '3', 'roundSeconds' => '60']);

        self::startFirstRound($this->client, form: ['drawnBy' => 'ai']);
        $game = $this->lastGame();

        self::assertSame([DrawnBy::Player, DrawnBy::Player, DrawnBy::Player], array_map($game->drawnByInRound(...), [1, 2, 3]));
    }

    public function testAGameStartedBeforeAChangeKeepsItsSettings(): void
    {
        $this->save(['themes' => ['animals', 'nature'], 'turns' => 'player', 'rounds' => '4', 'roundSeconds' => '45']);
        $round = self::startFirstRound($this->client);
        $game = $this->lastGame();

        $this->save(['themes' => ['transport'], 'turns' => 'ai', 'rounds' => '9', 'roundSeconds' => '120']);

        $this->client->request('POST', '/games/'.$game->getId()->toRfc4122().'/rounds');
        $this->client->request('GET', $round);
        self::assertSelectorTextContains('.round-number', 'Round 1 of 4');
        self::assertSelectorTextContains('.clock strong', '45');
        $kept = $this->lastGame();
        self::assertSame(4, $kept->getRoundCount());
        self::assertSame(45, $kept->getRoundSeconds());
        self::assertSame([Theme::Animals, Theme::Nature], $kept->getThemes());
        self::assertSame(DrawnBy::Player, $kept->drawnByInRound(2));

        // The next game takes the new ones.
        self::startFirstRound($this->client);
        $next = $this->lastGame();
        self::assertSame(9, $next->getRoundCount());
        self::assertSame([Theme::Transport], $next->getThemes());
    }

    /**
     * @param array<string, string|list<string>> $values
     */
    private function save(array $values): void
    {
        $form = $this->client->request('GET', '/en/settings')->selectButton('Save')->form();
        $this->submit($form, $values);
        self::assertResponseRedirects('/en');
    }

    /**
     * Posts the form as the browser would, with these values instead of the ones it holds.
     *
     * @param array<string, string|list<string>> $values
     */
    private function submit(Form $form, array $values): void
    {
        $this->client->request('POST', $form->getUri(), ['game_settings' => array_merge($form->getPhpValues()['game_settings'] ?? [], $values)]);
    }

    /**
     * @return list<string>
     */
    private static function themesOf(Form $form): array
    {
        $themes = $form->getPhpValues()['game_settings']['themes'] ?? [];

        return \is_array($themes) ? array_values(array_map(strval(...), $themes)) : [];
    }

    private static function valueOf(Form $form, string $field): string
    {
        $value = $form->getPhpValues()['game_settings'][$field] ?? null;

        return \is_string($value) ? $value : '';
    }

    private function lastGame(): Game
    {
        $games = self::getContainer()->get(GameRepository::class)->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], 1);
        self::getContainer()->get('doctrine')->getManager()->clear();

        return self::getContainer()->get(GameRepository::class)->find($games[0]->getId()) ?? throw new \LogicException('No game.');
    }
}
