<?php

namespace App\Tests\Unit\Setup;

use App\Ai\ModelsDev;
use App\Command\ModelsDevCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `make install` runs this command: without network, or with models.dev down, the installation goes on.
 */
final class ModelsDevCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{MockResponse|\Closure}>
     */
    public static function failures(): iterable
    {
        yield 'no network' => [static fn (): never => throw new TransportException('Could not resolve host: models.dev')];
        yield 'models.dev down' => [new MockResponse('', ['http_code' => 503])];
        yield 'not JSON' => [new MockResponse('<html>maintenance</html>')];
        yield 'none of the game providers' => [new MockResponse('{"groq": {"models": {}}}')];
    }

    #[DataProvider('failures')]
    public function testAFailedDownloadWarnsAndSucceeds(MockResponse|\Closure $answer): void
    {
        $file = sys_get_temp_dir().'/models-dev-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($file, '{"before": true}');
        $previous = $_SERVER['MODELS_DEV_FILE'] ?? null;
        $_SERVER['MODELS_DEV_FILE'] = $file;
        try {
            $application = new Application();
            $application->addCommand(new ModelsDevCommand(new ModelsDev(new MockHttpClient($answer))));
            $tester = new CommandTester($application->find('app:models-dev'));

            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('models.dev did not answer', $tester->getDisplay());
            self::assertSame('{"before": true}', file_get_contents($file), 'The file in place is kept');
        } finally {
            $_SERVER['MODELS_DEV_FILE'] = $previous;
            @unlink($file);
            ModelsDev::forget();
        }
    }
}
