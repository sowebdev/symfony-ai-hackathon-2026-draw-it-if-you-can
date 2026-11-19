<?php

namespace App\Tests\Unit\Ai;

use App\Ai\Encryption;
use App\Ai\EncryptionException;
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase
{
    private static function key(): string
    {
        return base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testWhatIsSealedIsOpenedAgain(): void
    {
        $encryption = new Encryption(self::key());
        $sealed = $encryption->encrypt('sk-ant-secret-3f9a');

        self::assertStringNotContainsString('secret', $sealed);
        self::assertSame('sk-ant-secret-3f9a', $encryption->decrypt($sealed));
        self::assertSame('', $encryption->decrypt($encryption->encrypt('')));
    }

    public function testEachSealingHasItsOwnNonce(): void
    {
        $encryption = new Encryption(self::key());

        self::assertNotSame($encryption->encrypt('same'), $encryption->encrypt('same'));
    }

    public function testAnotherKeyCannotOpenIt(): void
    {
        $sealed = (new Encryption(self::key()))->encrypt('sk-secret');
        $other = new Encryption(base64_encode(str_repeat("\x02", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessage('cannot be decrypted with the current APP_ENCRYPTION_KEY');
        $other->decrypt($sealed);
    }

    public function testAnAlteredTextCannotBeOpened(): void
    {
        $encryption = new Encryption(self::key());
        $raw = (string) base64_decode($encryption->encrypt('sk-secret'), true);
        $raw[-1] = $raw[-1] ^ "\x01";

        $this->expectException(EncryptionException::class);
        $encryption->decrypt(base64_encode($raw));
    }

    public function testGarbageCannotBeOpened(): void
    {
        $this->expectException(EncryptionException::class);
        (new Encryption(self::key()))->decrypt('not base64 at all!');
    }

    public function testWithoutAKeyNothingIsSealedAndTheMessageSaysHowToFixIt(): void
    {
        $encryption = new Encryption('');

        self::assertFalse($encryption->isConfigured());
        try {
            $encryption->encrypt('sk-secret');
            self::fail('Sealing without a key must fail');
        } catch (EncryptionException $exception) {
            self::assertStringContainsString('APP_ENCRYPTION_KEY is empty', $exception->getMessage());
            self::assertStringContainsString('random_bytes(32)', $exception->getMessage());
            self::assertStringContainsString('.env.local', $exception->getMessage());
            self::assertStringNotContainsString('sk-secret', $exception->getMessage());
        }
    }

    public function testAKeyOfAnotherSizeIsRefusedWithoutBeingSaid(): void
    {
        $short = base64_encode('too short to be a key');
        $encryption = new Encryption($short);

        self::assertFalse($encryption->isConfigured());
        try {
            $encryption->encrypt('x');
            self::fail('A key of the wrong size must fail');
        } catch (EncryptionException $exception) {
            self::assertStringContainsString('32 bytes', $exception->getMessage());
            self::assertStringNotContainsString($short, $exception->getMessage());
        }
    }
}
