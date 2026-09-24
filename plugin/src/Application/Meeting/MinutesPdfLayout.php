<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

use InvalidArgumentException;

final class MinutesPdfLayout
{
    public function __construct(private readonly int $linesPerPage = 46)
    {
        if ($this->linesPerPage < 2) {
            throw new InvalidArgumentException('A PDF page needs room for a heading and the next line.');
        }
    }

    /**
     * @return list<list<string>>
     */
    public function pages(string $body): array
    {
        $lines = $this->wrap($body);
        $pages = [];
        $current = [];
        $index = 0;
        $count = count($lines);

        while ($index < $count) {
            $line = $lines[$index];
            $room = $this->linesPerPage - count($current);
            $keepWithNext = $this->isHeading($line) && $index + 1 < $count;

            if ($keepWithNext && $room < 2 && $current !== []) {
                $pages[] = $current;
                $current = [];
            }

            if (count($current) >= $this->linesPerPage) {
                $pages[] = $current;
                $current = [];
            }

            $current[] = $line;
            $index++;
        }

        if ($current !== []) {
            $pages[] = $current;
        }

        return $pages === [] ? [['']] : $pages;
    }

    /**
     * @return list<string>
     */
    private function wrap(string $body): array
    {
        $wrapped = [];
        $source = preg_split("/\r\n|\n|\r/", $body) ?: [];

        foreach ($source as $line) {
            foreach ($this->wrapLine($line) as $part) {
                $wrapped[] = $part;
            }
        }

        return $wrapped;
    }

    /**
     * @return list<string>
     */
    private function wrapLine(string $line): array
    {
        $width = 80;

        if (mb_strlen($line) <= $width) {
            return [$line];
        }

        $words = preg_split('/\s+/u', $line) ?: [$line];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            while (mb_strlen($word) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }

                $lines[] = mb_substr($word, 0, $width);
                $word = (string) mb_substr($word, $width);
            }

            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (mb_strlen($candidate) <= $width) {
                $current = $candidate;
                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function isHeading(string $line): bool
    {
        return in_array($line, ['Närvarande', 'Frånvarande', 'Adjungerade', 'Dagordning', 'Övrigt', 'Avslutning'], true);
    }
}
