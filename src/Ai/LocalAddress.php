<?php

namespace App\Ai;

/**
 * Whether an address is on this machine or its network: `localhost`, `127.0.0.1`,
 * `host.docker.internal`, a private network. Where the reasoning switch starts on.
 */
final class LocalAddress
{
    public static function isLocal(?string $address): bool
    {
        $host = parse_url(trim((string) $address), \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return false;
        }
        $host = strtolower(trim($host, '[]'));
        if (\in_array($host, ['localhost', 'host.docker.internal', 'host.containers.internal', 'gateway.docker.internal'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }
        if (false === filter_var($host, \FILTER_VALIDATE_IP)) {
            // A bare name with no dot ("gate") lives on the network of the machine, not on the internet.
            return !str_contains($host, '.');
        }

        // Not private, not reserved (loopback, link-local): a public address.
        return false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
    }
}
