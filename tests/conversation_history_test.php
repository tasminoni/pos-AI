<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/ConversationHistory.php';

function checkHistory(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$response = ['status' => 'ok', 'assistant_text' => 'Saved results',
    'table' => ['rows' => [['amount' => '9007199254740993.01']]],
    'conversation_id' => str_repeat('a', 32)];
$entry = ['context' => ['private_sql' => 'private'], 'identity' => ['private']];
$entry = ConversationHistory::append($entry, 'Show sales', $response, ['title' => 'Sales']);
$restored = ConversationHistory::restore($entry);
checkHistory($restored[0]['question'] === 'Show sales', 'The original question was not restored.');
checkHistory($restored[0]['response'] === $response, 'Saved report data changed during restoration.');
checkHistory(!str_contains(json_encode($restored), 'private'), 'Private session context was exposed.');
checkHistory(ConversationHistory::summaries($entry) === [['title' => 'Sales']], 'Bootstrap returned full report snapshots.');
$legacy = ConversationHistory::restore(['turns' => [['title' => 'Older report']]]);
checkHistory($legacy[0]['response'] === null && $legacy[0]['title'] === 'Older report', 'Legacy reports were not handled.');

for ($index = 0; $index < 15; $index++) {
    $entry = ConversationHistory::append($entry, 'Question ' . $index, $response, ['title' => 'Report ' . $index]);
}
checkHistory(count($entry['turns']) === 12, 'History exceeded the turn limit.');
checkHistory($entry['turns'][11]['question'] === 'Question 14', 'The latest question was dropped.');
$large = ['status' => 'ok', 'assistant_text' => str_repeat('x', 1100000)];
$bounded = ConversationHistory::append([], 'First', $large, ['title' => 'First']);
$bounded = ConversationHistory::append($bounded, 'Second', $large, ['title' => 'Second']);
checkHistory(!isset($bounded['turns'][0]['response']), 'Old snapshots exceeded the byte budget.');
checkHistory($bounded['turns'][1]['response'] === $large, 'The latest snapshot was dropped.');
checkHistory($bounded['turns'][0]['question'] === 'First', 'Evicting a snapshot dropped its question.');
echo "Conversation history tests passed.\n";
