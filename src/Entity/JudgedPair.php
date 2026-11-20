<?php

namespace App\Entity;

use App\Ai\Link;
use App\Game\Language;
use App\Repository\JudgedPairRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the judge said of an answer for a word to find, kept so that the same pair is never asked
 * again, whatever the game. Both words are normalized (WordMatcher::normalize()).
 */
#[ORM\Entity(repositoryClass: JudgedPairRepository::class)]
#[ORM\UniqueConstraint(name: 'judged_pair_unique', columns: ['word', 'answer', 'language'])]
class JudgedPair
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $judgedAt;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $word,
        #[ORM\Column(length: 100)]
        private string $answer,
        #[ORM\Column(length: 2, enumType: Language::class)]
        private Language $language,
        #[ORM\Column(length: 12, enumType: Link::class)]
        private Link $link,
    ) {
        $this->judgedAt = new \DateTimeImmutable();
    }

    public function getWord(): string
    {
        return $this->word;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    public function getLanguage(): Language
    {
        return $this->language;
    }

    public function getLink(): Link
    {
        return $this->link;
    }
}
