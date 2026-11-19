<?php

namespace App\Ai;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What is wrong with a model, as a translation id with its parameters (never a sentence in one
 * language): it is kept in a cache and shown in the language of whoever looks, on the home page, on
 * the model screen and in the console. The advice, when there is one, says which way to flip the
 * reasoning switch.
 */
final readonly class Problem
{
    /**
     * @param array<string, string> $parameters Placeholders of the translation, e.g. ['%answer%' => 'cat']
     */
    public function __construct(
        public string $id,
        public array $parameters = [],
        public ?string $advice = null,
    ) {
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        $text = $translator->trans($this->id, $this->parameters, null, $locale);

        return null === $this->advice ? $text : $text.' '.$translator->trans($this->advice, [], null, $locale);
    }

    /**
     * @return array{id: string, parameters: array<string, string>, advice: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'parameters' => $this->parameters, 'advice' => $this->advice];
    }

    /**
     * @param array{id: string, parameters: array<string, string>, advice: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['parameters'], $data['advice']);
    }
}
