<?php
/**
 * One-time backfill script: Generate LM-YYYYMMDD-XX serials for existing tickets
 * Only fills tickets where external_ticket_id is empty (i.e., manually created)
 * Usage: php cron/backfill_ticket_serials.php
 */

require_once __DIR__ . '/../core/bootstrap.php';

try {
    echo "Starting backfill of ticket serials...\n";
    
    // Fetch tickets ordered by created_at, only those without external_ticket_id
    $stmt = $pdo->prepare("
        SELECT ticket_id, created_at 
        FROM tickets 
        WHERE external_ticket_id IS NULL OR external_ticket_id = ''
        ORDER BY created_at ASC, ticket_id ASC
    ");
    $stmt->execute();
    $tickets = $stmt->fetchAll();
    
    $total = count($tickets);
    echo "Found {$total} tickets to process (without existing external ID).\n";
    
    $currentDate = null;
    $dailySequence = 0;
    $updated = 0;
    $errors = 0;
    
    foreach ($tickets as $index => $ticket) {
        $ticketDate = date('Ymd', strtotime($ticket['created_at']));
        
        // Reset daily counter on date change
        if ($ticketDate !== $currentDate) {
            $currentDate = $ticketDate;
            $dailySequence = 1;
        } else {
            $dailySequence++;
        }
        
        $serial = 'LM-' . $ticketDate . '-' . str_pad($dailySequence, 2, '0', STR_PAD_LEFT);
        
        // Update external_ticket_id
        $update = $pdo->prepare("UPDATE tickets SET external_ticket_id = :serial WHERE ticket_id = :id");
        try {
            $update->execute([':serial' => $serial, ':id' => $ticket['ticket_id']]);
            $updated++;
            
            // Progress indicator
            if (($index + 1) % 100 === 0) {
                echo "Processed " . ($index + 1) . "/{$total} tickets...\n";
            }
        } catch (Exception $e) {
            echo "Failed to update ticket #{$ticket['ticket_id']}: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n✓ Backfill complete! Updated: {$updated}, Errors: {$errors}\n";
    
} catch (Exception $e) {
    echo "Critical error: " . $e->getMessage() . "\n";
    exit(1);
}
