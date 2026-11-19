<?php

namespace App\Tests\Functional\Command;

use App\Ai\ModelSettings;
use App\Ai\PlatformFactory;
use App\Ai\Provider;
use App\Tests\Functional\ScriptedPlatforms;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class CheckModelsCommandTest extends KernelTestCase
{
    private function runCheck(): CommandTester
    {
        $tester = new CommandTester((new Application(self::getContainer()->get(KernelInterface::class)))->find('app:ai:check'));
        $tester->execute([]);

        return $tester;
    }

    public function testWithNoModelItSaysSoAndGivesTheAddressOfThePage(): void
    {
        self::bootKernel();

        $tester = $this->runCheck();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No model is chosen yet', $tester->getDisplay());
        self::assertStringContainsString('/en/models', $tester->getDisplay());
        self::assertStringNotContainsStringIgnoringCase('.env.local', $tester->getDisplay());
        self::assertStringNotContainsStringIgnoringCase('ollama', $tester->getDisplay());
    }

    public function testItChecksTheStoredSetting(): void
    {
        self::bootKernel();
        self::getContainer()->set(PlatformFactory::class, new ScriptedPlatforms(MockPlatformFactory::createPlatform(static fn (): string => 'circle')));
        self::getContainer()->get(ModelSettings::class)->save(Provider::Compatible, 'http://scripted:8000', 'scripted', 'scripted', true);

        $tester = $this->runCheck();

        self::assertStringContainsString('guesser (scripted at http://scripted:8000)', $tester->getDisplay());
    }
}
