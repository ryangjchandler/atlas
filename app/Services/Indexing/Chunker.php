<?php

namespace App\Services\Indexing;

class Chunker
{
    public function __construct(protected int $maxLines = 80) {}

    /**
     * Split a file into runs of at most $maxLines lines, preferring to break after a blank line.
     *
     * @return list<array{start_line: int, end_line: int, content: string}>
     */
    public function chunk(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        // A trailing newline is not an extra line...
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $chunks = [];
        $count = count($lines);
        $start = 0;

        while ($start < $count) {
            $end = min($start + $this->maxLines, $count);

            if ($end < $count) {
                for ($i = $end - 1; $i > $start + intdiv($this->maxLines, 2); $i--) {
                    if (trim($lines[$i]) === '') {
                        $end = $i + 1;

                        break;
                    }
                }
            }

            $text = implode("\n", array_slice($lines, $start, $end - $start));

            if (trim($text) !== '') {
                $chunks[] = ['start_line' => $start + 1, 'end_line' => $end, 'content' => $text];
            }

            $start = $end;
        }

        return $chunks;
    }
}
