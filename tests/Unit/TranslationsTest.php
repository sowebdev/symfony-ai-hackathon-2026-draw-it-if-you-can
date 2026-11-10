<?php

namespace App\Tests\Unit;

use App\Game\Language;
use App\Game\Theme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;

/**
 * Every language of the game has the same messages, and every theme has a name.
 */
final class TranslationsTest extends TestCase
{
    public function testEveryLanguageHasTheSameKeys(): void
    {
        $reference = self::keys(Language::English);

        foreach (Language::cases() as $language) {
            self::assertSame($reference, self::keys($language), "translations/messages.{$language->value}.yaml");
        }
    }

    public function testEveryThemeHasAName(): void
    {
        foreach (Theme::cases() as $theme) {
            self::assertContains('theme.'.$theme->value, self::keys(Language::English));
        }
    }

    /**
     * @return list<string>
     */
    private static function keys(Language $language): array
    {
        $file = \dirname(__DIR__, 2)."/translations/messages.{$language->value}.xlf";
        $keys = array_keys((new XliffFileLoader())->load($file, $language->value)->all('messages'));
        sort($keys);

        return $keys;
    }
}
