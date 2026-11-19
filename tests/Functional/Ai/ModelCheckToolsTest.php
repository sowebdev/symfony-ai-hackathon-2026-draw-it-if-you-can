<?php

namespace App\Tests\Functional\Ai;

use App\Ai\ModelCheck;
use App\Ai\PlatformFactory;
use App\Ai\Role;
use App\Tests\Functional\ScriptedPlatforms;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The drawer's check asks for one stroke, since a drawer that cannot call tools cannot play.
 */
final class ModelCheckToolsTest extends KernelTestCase
{
    /** @var list<string> */
    private array $asked = [];

    /**
     * @param \Closure(): (string|ResultInterface) $toolAnswer What the model answers when asked to draw one stroke
     */
    private function check(\Closure $toolAnswer): ModelCheck
    {
        self::bootKernel();
        $platform = MockPlatformFactory::createPlatform(function ($model, $input) use ($toolAnswer): string|ResultInterface {
            if (str_contains((string) json_encode($input), 'with one single stroke')) {
                $this->asked[] = 'stroke';

                return $toolAnswer();
            }
            $this->asked[] = 'image';

            return 'circle';
        });
        self::getContainer()->set(PlatformFactory::class, new ScriptedPlatforms($platform));

        return self::getContainer()->get(ModelCheck::class);
    }

    private static function call(string $arguments): StreamResult
    {
        return new StreamResult((static function () use ($arguments): \Generator {
            yield new ToolCallStart('c1', 'draw_stroke');
            yield new ToolInputDelta('c1', 'draw_stroke', $arguments);
            yield new ToolCallComplete([new ToolCall('c1', 'draw_stroke', json_decode($arguments, true) ?? [])]);
        })());
    }

    public function testADrawerThatDrawsAStrokePlays(): void
    {
        $check = $this->check(static fn (): StreamResult => self::call('{"part":"ring","cells":"x25y10,x40y25,x25y40,x10y25,x25y10"}'));

        self::assertNull($check->check(Role::Drawer));
        self::assertSame(['image', 'stroke'], $this->asked);
    }

    public function testADrawerThatAnswersInWordsCannotPlay(): void
    {
        $check = $this->check(static fn (): string => 'Here is a circle: O');

        $problem = $check->check(Role::Drawer);

        self::assertNotNull($problem);
        self::assertStringContainsString('did not call the draw_stroke tool', $problem);
        self::assertStringContainsString('must be a model that calls tools', $problem);
    }

    public function testACallWithoutReadableCellsIsNotAStroke(): void
    {
        $check = $this->check(static fn (): StreamResult => self::call('{"part":"ring","cells":"a circle"}'));

        self::assertStringContainsString('did not call the draw_stroke tool', (string) $check->check(Role::Drawer));
    }

    public function testTheGuesserIsCheckedOnImagesOnly(): void
    {
        $check = $this->check(static fn (): string => 'no tool here');

        self::assertNull($check->check(Role::Guesser));
        self::assertSame(['image'], $this->asked, 'The guesser is never asked for a stroke');
    }

    public function testADrawerThatDoesNotSeeImagesIsNotAskedForAStroke(): void
    {
        self::bootKernel();
        $asked = [];
        $platform = MockPlatformFactory::createPlatform(static function ($model, $input) use (&$asked): string {
            $asked[] = str_contains((string) json_encode($input), 'with one single stroke') ? 'stroke' : 'image';

            return 'cat';
        });
        self::getContainer()->set(PlatformFactory::class, new ScriptedPlatforms($platform));

        self::assertStringContainsString('reads images', (string) self::getContainer()->get(ModelCheck::class)->check(Role::Drawer));
        self::assertSame(['image'], $asked);
    }
}
