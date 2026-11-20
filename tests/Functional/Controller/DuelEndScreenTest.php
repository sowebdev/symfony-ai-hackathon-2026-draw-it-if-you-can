<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Game;
use App\Entity\Guess;
use App\Entity\Round;
use App\Game\Author;
use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Mode;
use App\Game\Theme;
use App\Tests\Functional\ScriptedModel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The end of a duel: a banner in the colour of the winner, with its name and both scores.
 */
final class DuelEndScreenTest extends WebTestCase
{
    use ScriptedModel;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::scriptModel($this->client);
    }

    public function testTheBannerNamesTheTeamThatWon(): void
    {
        $this->client->request('GET', $this->duel([Author::Player, Author::Ai]));

        self::assertSelectorExists('.duel-banner.side-team');
        self::assertSelectorTextContains('.duel-banner-title', 'Toi et Olaf gagnent !');
        self::assertSelectorTextContains('.duel-banner-score', "2\u{202F}000 à 0");
        self::assertSelectorExists('table .side-team');
        self::assertSelectorNotExists('table .side-rival');
    }

    public function testTheBannerNamesTheRivalThatWon(): void
    {
        $this->client->request('GET', $this->duel([Author::Agent, Author::Agent], 'Picasso'));

        self::assertSelectorExists('.duel-banner.side-rival');
        self::assertSelectorTextContains('.duel-banner-title', 'Picasso gagne !');
        self::assertSelectorTextContains('.duel-banner-score', "0 à 2\u{202F}000");
        self::assertSelectorExists('table .side-rival');
    }

    public function testTheRivalWithoutANameIsYourRival(): void
    {
        $this->client->request('GET', $this->duel([Author::Agent, Author::Agent]));

        self::assertSelectorTextContains('.duel-banner-title', 'Ton rival gagne !');
    }

    public function testATieIsClear(): void
    {
        $this->client->request('GET', $this->duel([Author::Player, Author::Agent]));

        self::assertSelectorExists('.duel-banner.duel-tie');
        self::assertSelectorNotExists('.duel-banner.side-team');
        self::assertSelectorNotExists('.duel-banner.side-rival');
        self::assertSelectorTextContains('.duel-banner-title', 'Égalité !');
        self::assertSelectorTextContains('.duel-banner-score', "1\u{202F}000 à 1\u{202F}000");
    }

    public function testTheWordIsShownWithItsArticle(): void
    {
        $this->client->request('GET', $this->duel([Author::Player, Author::Player]));

        self::assertSelectorTextContains('table', 'un château fort');
    }

    /**
     * A finished duel of two rounds, each found at once by the given author: 1 000 points a round.
     *
     * @param list<Author> $foundBy
     */
    private function duel(array $foundBy, ?string $rivalName = null): string
    {
        $manager = $this->client->getContainer()->get(EntityManagerInterface::class);
        $game = new Game(Language::French, 2, DrawnBy::Ai, Mode::Duel);
        if (null !== $rivalName) {
            $game->agentSeen('claude-code', new \DateTimeImmutable());
            $game->nameAgent($rivalName);
        }
        $manager->persist($game);
        foreach ($foundBy as $index => $author) {
            $round = new Round($game, $index + 1, 'château fort', 60_000, Theme::Places, 'un');
            $manager->persist($round);
            $guess = new Guess($round, 'château fort', 0, $author);
            $manager->persist($guess);
            $round->markFound($guess);
        }
        $manager->flush();

        return '/games/'.$game->getId();
    }
}
