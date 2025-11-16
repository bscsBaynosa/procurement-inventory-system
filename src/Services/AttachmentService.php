<?php

namespace App\Services;

use App\Database\Connection;
use PDO;

/**
 * Manages process file attachments per PR lifecycle (PR, Consumption, Canvass, PO, RFP, Gate Pass).
 * Table layout kept minimal and idempotent; safe to call ensure() frequently.
 */
class AttachmentService
{
    /** @var PDO */
    private $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: Connection::resolve();
        $this->ensure();
    }

    /** Ensure backing table and indexes exist. */
    private function ensure(): void
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS pr_process_files (
                id BIGSERIAL PRIMARY KEY,
                pr_number VARCHAR(32) NOT NULL,
                file_type VARCHAR(32) NOT NULL, -- pr_pdf | consumption_report | canvass_pdf | po_pdf | rfp_pdf | gate_pass_pdf
                file_path TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_pr_process_files_pr ON pr_process_files (pr_number)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_pr_process_files_type ON pr_process_files (file_type)");
        } catch (\Throwable $e) { /* ignore */ }
    }

    /** Store or replace (pr_number, file_type). Newest wins; old rows retained only if path differs. */
    public function store(string $prNumber, string $type, string $path): bool
    {
        if ($prNumber === '' || $type === '' || $path === '') { return false; }
        $this->ensure();
        try {
            // If same pr+type exists with identical path skip; else insert (retain history)
            $st = $this->pdo->prepare('SELECT id, file_path FROM pr_process_files WHERE pr_number = :pr AND file_type = :t ORDER BY created_at DESC LIMIT 1');
            $st->execute(['pr' => $prNumber, 't' => $type]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && (string)$row['file_path'] === $path) { return true; }
            $ins = $this->pdo->prepare('INSERT INTO pr_process_files (pr_number, file_type, file_path) VALUES (:pr,:t,:p)');
            return $ins->execute(['pr' => $prNumber, 't' => $type, 'p' => $path]);
        } catch (\Throwable $e) { return false; }
    }

    /** Return list of attachments ordered oldest -> newest; filter optional file types. */
    public function listForPr(string $prNumber, ?array $types = null): array
    {
        if ($prNumber === '') { return []; }
        $this->ensure();
        try {
            $sql = 'SELECT file_type, file_path FROM pr_process_files WHERE pr_number = :pr';
            $params = ['pr' => $prNumber];
            if ($types && $types !== []) {
                $in = implode(',', array_fill(0, count($types), '?'));
                $sql .= ' AND file_type IN (' . $in . ')';
            }
            $sql .= ' ORDER BY created_at ASC';
            $st = $this->pdo->prepare($sql);
            if ($types && $types !== []) { $st->execute(array_merge([$prNumber], $types)); }
            else { $st->execute(['pr' => $prNumber]); }
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $rows;
        } catch (\Throwable $e) { return []; }
    }

    /** Build consolidated attachment descriptors for Admin message (all prior types). */
    public function buildAdminAttachmentSet(string $prNumber): array
    {
        $order = ['pr_pdf','consumption_report','canvass_pdf','po_pdf','rfp_pdf','gate_pass_pdf'];
        $rows = $this->listForPr($prNumber);
        // Keep only latest per type following defined order
        $latestByType = [];
        foreach ($rows as $r) { $latestByType[$r['file_type']] = $r['file_path']; }
        $attachments = [];
        foreach ($order as $t) {
            if (isset($latestByType[$t]) && is_file($latestByType[$t])) {
                $attachments[] = [ 'name' => strtoupper(str_replace('_',' ', $t)) . ' - PR ' . $prNumber . '.pdf', 'path' => $latestByType[$t] ];
            }
        }
        return $attachments;
    }

    /** Supplier attachment set (only PO PDF). */
    public function buildSupplierAttachmentSet(string $prNumber): array
    {
        $rows = $this->listForPr($prNumber, ['po_pdf']);
        $attachments = [];
        foreach ($rows as $r) {
            if (is_file($r['file_path'])) {
                $attachments[] = [ 'name' => 'PO - PR ' . $prNumber . '.pdf', 'path' => $r['file_path'] ];
            }
        }
        return $attachments;
    }
}
