<?php

namespace App\Tests\Functional\Command;

use App\Ai\ModelSettings;
use App\Command\BenchCommand;
use App\Drawing\StrokeRenderer;
use App\Drawing\StrokeTracer;
use App\Tests\Functional\ScriptedPlatforms;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BenchCommandTest extends KernelTestCase
{
    private string $dir;

    /** @var \ArrayObject<string, mixed> Calls to the model, and whether it fails */
    private \ArrayObject $model;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/bench-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->dir.'/bench.json', json_encode([
            'words' => [['word' => 'cat', 'theme' => 'animals']],
            'models' => [['label' => 'Scripted, local', 'provider' => 'openai_compatible', 'address' => 'http://scripted:8000', 'model' => 'scripted']],
        ], \JSON_THROW_ON_ERROR));
        $this->model = new \ArrayObject(['calls' => 0, 'failure' => null]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function bench(array $input = []): CommandTester
    {
        self::bootKernel();
        $model = $this->model;
        // One stroke, then nothing more: as a model that has finished its sketch.
        $platform = MockPlatformFactory::createPlatform(static function () use ($model): string|ResultInterface {
            ++$model['calls'];
            if ($model['failure'] instanceof \Throwable) {
                throw $model['failure'];
            }
            if (1 !== $model['calls'] % 2) {
                return 'done';
            }
            $cells = 'x25y10,x40y25,x25y40,x10y25,x25y10';

            return new StreamResult((static function () use ($cells): \Generator {
                yield new ToolCallStart('call_1', 'draw_stroke');
                yield new ToolInputDelta('call_1', 'draw_stroke', json_encode(['part' => 'head', 'cells' => $cells], \JSON_THROW_ON_ERROR));
                yield new ToolCallComplete([new ToolCall('call_1', 'draw_stroke', ['part' => 'head', 'cells' => $cells])]);
            })());
        });
        $container = self::getContainer();
        $application = new Application();
        $application->addCommand(new BenchCommand($container->get(ModelSettings::class), new ScriptedPlatforms($platform), $container->get(StrokeTracer::class), $container->get(StrokeRenderer::class), new Filesystem(), $this->dir));
        $tester = new CommandTester($application->find('app:bench'));
        $tester->execute($input);

        return $tester;
    }

    public function testEachModelDrawsEachWordAndThePageShowsIt(): void
    {
        $tester = $this->bench();

        $tester->assertCommandIsSuccessful();
        self::assertFileExists($this->dir.'/scripted-local/cat.png');
        $results = json_decode((string) file_get_contents($this->dir.'/results.json'), true);
        self::assertSame(1, $results['scripted-local/cat']['strokes']);
        self::assertSame(['head'], $results['scripted-local/cat']['parts']);
        $page = (string) file_get_contents($this->dir.'/README.md');
        self::assertStringContainsString('| Model | cat |', $page);
        self::assertStringContainsString('<img src="scripted-local/cat.png"', $page);
        self::assertStringContainsString('1 strokes', $page);
    }

    public function testWhatIsDrawnIsNotDrawnAgain(): void
    {
        $this->bench();
        $calls = $this->model['calls'];

        $tester = $this->bench();

        self::assertSame($calls, $this->model['calls']);
        self::assertStringContainsString('cat: already drawn', $tester->getDisplay());
    }

    public function testAFailureIsShownAndTriedAgainNextTime(): void
    {
        $this->model['failure'] = new \RuntimeException('model is not loaded');
        $this->bench();
        self::assertStringContainsString('failed: model is not loaded', (string) file_get_contents($this->dir.'/README.md'));

        $this->model->exchangeArray(['calls' => 0, 'failure' => null]);
        $this->bench();

        self::assertStringContainsString('<img src="scripted-local/cat.png"', (string) file_get_contents($this->dir.'/README.md'));
    }

    public function testThePageAloneCallsNoModel(): void
    {
        $tester = $this->bench(['--page-only' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(0, $this->model['calls']);
        self::assertStringContainsString('not drawn yet', (string) file_get_contents($this->dir.'/README.md'));
    }
}
