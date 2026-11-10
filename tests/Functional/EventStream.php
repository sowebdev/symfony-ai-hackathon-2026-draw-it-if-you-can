<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Reads a server-sent events response until the server closes it.
 */
trait EventStream
{
    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private static function readEvents(KernelBrowser $client, string $url): array
    {
        ob_start();
        $client->request('GET', $url);
        $body = (string) ob_get_clean().$client->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();

        $events = [];
        foreach (preg_split('/\n\n/', $body) ?: [] as $block) {
            if (preg_match('/^event: (\w+)$/m', $block, $type) && preg_match('/^data: (.*)$/m', $block, $data)) {
                $events[] = ['type' => $type[1], 'data' => json_decode($data[1], true, flags: \JSON_THROW_ON_ERROR)];
            }
        }

        return $events;
    }
}
