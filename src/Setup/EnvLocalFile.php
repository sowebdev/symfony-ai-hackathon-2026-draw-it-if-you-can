<?php

namespace App\Setup;

/**
 * Writes the AI_* settings into .env.local, keeping every other line of the file.
 */
final class EnvLocalFile
{
    /**
     * @param array<string, string> $values variable name => value
     */
    public static function merge(string $content, array $values): string
    {
        $lines = '' === $content ? [] : explode("\n", rtrim($content, "\n"));
        $lines = array_values(array_filter(
            $lines,
            static fn (string $line): bool => !preg_match('/^\s*('.implode('|', array_map(preg_quote(...), array_keys($values))).')=/', $line),
        ));
        foreach ($values as $name => $value) {
            $lines[] = $name.'='.self::quote($value);
        }

        return implode("\n", $lines)."\n";
    }

    private static function quote(string $value): string
    {
        return '' === $value || preg_match('/^[A-Za-z0-9_.:\/@-]+$/', $value) ? $value : '"'.addcslashes($value, '"\\$').'"';
    }
}
