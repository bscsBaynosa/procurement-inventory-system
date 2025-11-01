<?php
// php scripts/encrypt_backfill.php
// One-time helper to encrypt existing plaintext rows for messages.body and purchase_requests.justification

require __DIR__ . '/../vendor/autoload.php';

$pdo = \App\Database\Connection::resolve();

function enc($s, $aad = '') {
    return \App\Services\CryptoService::encrypt($s, $aad);
}

try {
    echo "Backfilling messages.body...\n";
    $stmt = $pdo->query("SELECT id, body, sender_id, recipient_id FROM messages ORDER BY id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE messages SET body = :b WHERE id = :id");
    $count = 0;
    foreach ($rows as $r) {
        $body = (string)($r['body'] ?? '');
        if ($body === '' || str_starts_with($body, 'v1:')) { continue; }
        $aad = 'msg:' . (string)($r['sender_id'] ?? '') . '->' . (string)($r['recipient_id'] ?? '');
        $cipher = enc($body, $aad);
        if ($cipher && $cipher !== $body) { $upd->execute(['b' => $cipher, 'id' => (int)$r['id']]); $count++; }
    }
    echo "Messages updated: {$count}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Messages backfill error: " . $e->getMessage() . "\n");
}

try {
    echo "Backfilling purchase_requests.justification...\n";
    $stmt = $pdo->query("SELECT request_id, justification, pr_number FROM purchase_requests ORDER BY request_id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare("UPDATE purchase_requests SET justification = :j WHERE request_id = :id");
    $count = 0;
    foreach ($rows as $r) {
        $j = (string)($r['justification'] ?? '');
        if ($j === '' || str_starts_with($j, 'v1:')) { continue; }
        $aad = 'pr:' . (string)($r['pr_number'] ?? $r['request_id']);
        $cipher = enc($j, $aad);
        if ($cipher && $cipher !== $j) { $upd->execute(['j' => $cipher, 'id' => (int)$r['request_id']]); $count++; }
    }
    echo "Purchase requests updated: {$count}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "PR backfill error: " . $e->getMessage() . "\n");
}

echo "Done.\n";
