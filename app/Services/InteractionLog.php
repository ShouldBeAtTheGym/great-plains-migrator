<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;

class InteractionLog
{
    /**
     * One SalesPad interaction log becomes one Odoo note per dated entry.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    public function rows(array $row): array
    {
        $notes = $this->text($row['note_log'] ?? '');
        unset($row['note_log']);
        $fallback = $this->fallback($row['date'] ?? null);
        $intro = (string) ($row['body'] ?? '');
        $pieces = $this->pieces($notes, $fallback);
        if ($pieces === []) {
            return [$row];
        }
        if (count($pieces) === 1) {
            $row['body'] = $this->joinHtml($intro, $pieces[0]['text']);
            $row['date'] = $pieces[0]['date'];

            return [$row];
        }

        $preamble = null;
        $dated = [];
        foreach ($pieces as $piece) {
            if ($piece['preamble']) {
                $preamble = $piece;
            } else {
                $dated[] = $piece;
            }
        }

        $out = [];
        if ($preamble !== null) {
            $copy = $row;
            $copy['id'] = $row['id'].'_0';
            $copy['body'] = $this->joinHtml($intro, $preamble['text']);
            $copy['date'] = $preamble['date'];
            $out[] = $copy;
            $intro = '';
        }

        $count = count($dated);
        foreach ($dated as $index => $piece) {
            $copy = $row;
            $copy['id'] = $row['id'].'_'.($count - $index);
            $copy['body'] = $this->joinHtml($index === 0 ? $intro : '', $piece['text']);
            $copy['date'] = $piece['date'];
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * @return list<array{text: string, date: string, preamble: bool}>
     */
    public function pieces(string $notes, DateTimeInterface $fallback): array
    {
        $notes = str_replace(["\r\n", "\r"], "\n", $notes);
        $notes = preg_replace('/(?<=[.!?])(?=(?:<<?\s*)?(?:\d{1,2}\s*[.\/-]\s*\d{1,2}\b|'.$this->monthPattern().'\.?\s+\d{1,2}\b))/iu', "\n", $notes) ?? $notes;
        $notes = preg_replace('/(?<=[.!?])(?=\s*[-*=_]*\*{2,}\s*20\d{2}\s*\*{2,})/u', "\n", $notes) ?? $notes;

        $sections = [];
        $bucket = [];
        foreach (explode("\n", $notes) as $line) {
            $marker = $this->yearMarker($line);
            if ($marker !== null) {
                $sections[] = ['lines' => $bucket, 'year' => $marker];
                $bucket = [];

                continue;
            }
            $bucket[] = $line;
        }
        $sections[] = ['lines' => $bucket, 'year' => null];

        $fallbackYear = (int) $fallback->format('Y');
        $previousMarker = null;
        $seenDate = false;
        $preamble = [];
        $entries = [];
        foreach ($sections as $index => $section) {
            $year = $section['year'] ?? ($previousMarker !== null ? $previousMarker - 1 : $fallbackYear);
            if ($section['year'] !== null) {
                $previousMarker = $section['year'];
            }
            $lastMonth = null;
            $current = null;
            foreach ($section['lines'] as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $parsed = $this->parseDateLine($trimmed);
                if ($parsed !== null) {
                    if ($lastMonth !== null && $parsed['month'] > $lastMonth && $year > 1990) {
                        $year--;
                    }
                    $lastMonth = $parsed['month'];
                    $day = min($parsed['day'], (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $parsed['month'])))->format('t'));
                    $entries[] = [
                        'year' => $year,
                        'month' => $parsed['month'],
                        'day' => $day,
                        'lines' => [$trimmed],
                    ];
                    $current = array_key_last($entries);
                    $seenDate = true;

                    continue;
                }
                if ($current !== null) {
                    $entries[$current]['lines'][] = $trimmed;
                } elseif (! $seenDate && $index === 0) {
                    $preamble[] = $trimmed;
                } elseif ($entries !== []) {
                    $last = array_key_last($entries);
                    $entries[$last]['lines'][] = $trimmed;
                } else {
                    $preamble[] = $trimmed;
                }
            }
        }

        $groups = [];
        foreach ($entries as $index => $entry) {
            $groups[sprintf('%04d-%02d-%02d', $entry['year'], $entry['month'], $entry['day'])][] = $index;
        }

        $dated = [];
        foreach ($entries as $index => $entry) {
            $day = sprintf('%04d-%02d-%02d', $entry['year'], $entry['month'], $entry['day']);
            $indexes = $groups[$day];
            $position = (int) array_search($index, $indexes, true);
            $minutes = (8 * 60) + (count($indexes) - 1 - $position);
            $dated[] = [
                'text' => implode("\n", $entry['lines']),
                'date' => (new DateTimeImmutable($day.' 00:00:00'))->modify('+'.$minutes.' minutes')->format('Y-m-d H:i:s'),
                'preamble' => false,
            ];
        }

        $result = [];
        $preambleText = trim(implode("\n", $preamble));
        if ($preambleText !== '') {
            $result[] = [
                'text' => $preambleText,
                'date' => $fallback->format('Y-m-d H:i:s'),
                'preamble' => true,
            ];
        }
        foreach ($dated as $entry) {
            $result[] = $entry;
        }

        return $result;
    }

    private function text(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return trim((string) $value);
    }

    private function fallback(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value)) {
            $parsed = date_create_immutable($value);
            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        return new DateTimeImmutable('now');
    }

    private function joinHtml(string $intro, string $text): string
    {
        $block = '<p>'.str_replace("\n", '<br>', htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>';
        $intro = trim($intro);
        if ($intro === '' || trim(html_entity_decode(strip_tags(str_replace('<br>', ' ', $intro)))) === '') {
            return $block;
        }

        return $intro.$block;
    }

    private function yearMarker(string $line): ?int
    {
        if (! preg_match('/^\s*[-*=_\s]*\*{2,}\s*(20\d{2})\s*\*{2,}[-*=_\s]*$/', trim($line), $match)) {
            return null;
        }
        $year = (int) $match[1];

        return $year >= 1990 && $year <= 2100 ? $year : null;
    }

    /**
     * @return array{month: int, day: int}|null
     */
    private function parseDateLine(string $line): ?array
    {
        $pattern = '/^\s*(?:<<?\s*)?(?:(?<mon>'.$this->monthPattern().')\.?\s+(?<day>\d{1,2})|(?<m>\d{1,2})\s*[.\/-]\s*(?<d>\d{1,2}))\b/iu';
        if (! preg_match($pattern, $line, $match)) {
            return null;
        }
        if (($match['mon'] ?? '') !== '') {
            $month = $this->monthNumber($match['mon']);
            $day = (int) $match['day'];
        } else {
            $month = (int) $match['m'];
            $day = (int) $match['d'];
        }
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return ['month' => $month, 'day' => $day];
    }

    private function monthPattern(): string
    {
        return 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';
    }

    private function monthNumber(string $name): int
    {
        return match (strtolower(substr($name, 0, 3))) {
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            default => 12,
        };
    }
}
