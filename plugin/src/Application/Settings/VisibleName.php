<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Settings;

final class VisibleName
{
    public static function normalize(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return strtolower(strtr($collapsed, [
            'Å' => 'å',
            'Ä' => 'ä',
            'Ö' => 'ö',
            'É' => 'é',
            'È' => 'è',
            'Á' => 'á',
            'Ü' => 'ü',
        ]));
    }
}
