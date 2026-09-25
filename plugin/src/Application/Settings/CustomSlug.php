<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Settings;

final class CustomSlug
{
    /**
     * @param list<string> $taken
     */
    public static function fromName(string $name, array $taken, string $fallback): string
    {
        $stem = self::stem($name);

        if ($stem === '') {
            $stem = $fallback;
        }

        $base = self::limit('custom_' . $stem, 50);
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $taken, true)) {
            $tail = '_' . $suffix;
            $candidate = self::limit($base, 50 - strlen($tail)) . $tail;
            $suffix++;

            if ($suffix > 999) {
                throw new StructureRuleException(StructureRuleException::INVALID);
            }
        }

        return $candidate;
    }

    private static function stem(string $name): string
    {
        $value = strtr(trim($name), [
            'Å' => 'a',
            'Ä' => 'a',
            'Ö' => 'o',
            'å' => 'a',
            'ä' => 'a',
            'ö' => 'o',
            'É' => 'e',
            'È' => 'e',
            'Ê' => 'e',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'Á' => 'a',
            'À' => 'a',
            'á' => 'a',
            'à' => 'a',
            'Ü' => 'u',
            'Ú' => 'u',
            'ü' => 'u',
            'ú' => 'u',
            'Ø' => 'o',
            'ø' => 'o',
            'Æ' => 'ae',
            'æ' => 'ae',
            'Ñ' => 'n',
            'ñ' => 'n',
            'Ç' => 'c',
            'ç' => 'c',
            'ß' => 'ss',
        ]);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_');
    }

    private static function limit(string $slug, int $length): string
    {
        $limited = rtrim(substr($slug, 0, $length), '_');

        return $limited === '' ? 'custom_item' : $limited;
    }
}
