<?php
declare(strict_types=1);

/** Bounded, session-only report snapshots. Never expose query context to the UI. */
final class ConversationHistory
{
    public static function append(array $entry, string $question, array $response, array $summary): array
    {
        $entry['turns'][] = $summary + ['question' => $question, 'response' => $response];
        $entry['turns'] = array_slice($entry['turns'], -12);
        // Preserve titles/questions while dropping the oldest large snapshots first.
        $bytes = 0;
        for ($index = count($entry['turns']) - 1; $index >= 0; $index--) {
            $snapshot = $entry['turns'][$index]['response'] ?? null;
            if (!is_array($snapshot)) { continue; }
            $size = strlen(json_encode($snapshot, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
            if ($bytes + $size > 2000000) {
                unset($entry['turns'][$index]['response']);
            } else {
                $bytes += $size;
            }
        }
        return $entry;
    }

    public static function summaries(array $entry): array
    {
        return array_map(static function (array $turn): array {
            unset($turn['response'], $turn['question']);
            return $turn;
        }, $entry['turns'] ?? []);
    }

    public static function restore(array $entry): array
    {
        return array_map(static function (array $turn): array {
            // An explicit allowlist prevents exposing SQL plans or private context.
            return [
                'question' => $turn['question'] ?? null,
                'title' => $turn['title'] ?? null,
                'date_start' => $turn['date_start'] ?? null,
                'date_end_exclusive' => $turn['date_end_exclusive'] ?? null,
                'response' => $turn['response'] ?? null,
            ];
        }, $entry['turns'] ?? []);
    }
}
