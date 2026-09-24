<?php

declare(strict_types=1);

namespace Foreningssystem\Application\Meeting;

final class MinutesPdfDocument
{
    public function __construct(private readonly MinutesPdfLayout $layout = new MinutesPdfLayout())
    {
    }

    public function render(string $body, int $revisionNumber): string
    {
        $pages = $this->layout->pages('Revision ' . $revisionNumber . "\n\n" . $body);
        $streams = [];
        $count = count($pages);

        foreach ($pages as $index => $lines) {
            $streams[] = $this->stream($lines, $index + 1, $count);
        }

        return $this->pdf($streams);
    }

    /**
     * @param list<string> $lines
     */
    private function stream(array $lines, int $pageNumber, int $pageCount): string
    {
        $commands = ['BT', '/F1 11 Tf', '14 TL', '1 0 0 1 50 800 Tm'];

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $commands[] = 'T*';
            }

            $commands[] = $this->literal($line) . ' Tj';
        }

        $commands[] = 'ET';
        $commands[] = 'BT';
        $commands[] = '/F1 9 Tf';
        $commands[] = '1 0 0 1 270 36 Tm';
        $commands[] = $this->literal('Sida ' . $pageNumber . ' / ' . $pageCount) . ' Tj';
        $commands[] = 'ET';

        return implode("\n", $commands);
    }

    private function literal(string $utf8): string
    {
        if (! mb_check_encoding($utf8, 'UTF-8')) {
            throw new MinutesPdfException('The minutes contain text the PDF font cannot represent.');
        }

        $encoded = @iconv('UTF-8', 'Windows-1252', $utf8);

        if ($encoded === false || iconv('Windows-1252', 'UTF-8', $encoded) !== $utf8) {
            throw new MinutesPdfException('The minutes contain text the PDF font cannot represent.');
        }

        return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded) . ')';
    }

    /**
     * @param list<string> $streams
     */
    private function pdf(array $streams): string
    {
        $count = count($streams);
        $fontId = 3 + ($count * 2);
        $kids = [];
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            $fontId => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        for ($index = 0; $index < $count; $index++) {
            $pageId = 3 + ($index * 2);
            $contentId = $pageId + 1;
            $kids[] = $pageId . ' 0 R';
            $stream = $streams[$index];
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Contents ' . $contentId . ' 0 R /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> >>';
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Count ' . $count . ' /Kids [' . implode(' ', $kids) . '] >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $startxref = strlen($pdf);
        $size = $fontId + 1;
        $pdf .= 'xref\n0 ' . $size . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $pdf . 'trailer\n<< /Size ' . $size . " /Root 1 0 R >>\nstartxref\n" . $startxref . "\n%%EOF";
    }
}
