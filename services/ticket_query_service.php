<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/ticket_log_service.php';
require_once __DIR__ . '/party_service.php';

// Returns the list filters used on the ticket list page.
function ticket_query_service_filters(array $query): array
{
    $allowedSorts = ['ticket_id', 'external_ticket_id', 'created_at', 'status', 'priority', 'customer', 'issue', 'source'];
    $requestedSortBy = trim((string) ($query['sort_by'] ?? 'ticket_id'));

    $status = trim((string) ($query['status'] ?? ''));
    $showDeleted = $status === 'Deleted';
    $showArchived = $status === 'Archived';

    return [
        'status' => $status,
        'priority' => trim((string) ($query['priority'] ?? '')),
        'country' => trim((string) ($query['country'] ?? '')),
        'customer' => trim((string) ($query['customer'] ?? '')),
        'assign_to' => trim((string) ($query['assign_to'] ?? '')),
        'assigned_vendor_id' => trim((string) ($query['assigned_vendor_id'] ?? '')),
        'search' => trim((string) ($query['search'] ?? '')),
        'external_ticket_id' => trim((string) ($query['external_ticket_id'] ?? '')),
        'from_date' => trim((string) ($query['from_date'] ?? '')),
        'to_date' => trim((string) ($query['to_date'] ?? '')),
        'show_deleted' => $showDeleted,
        'show_archived' => $showArchived,
        'page' => max(1, (int) ($query['page'] ?? 1)),
        'limit' => max(1, min(100, (int) ($query['limit'] ?? 20))),
        'sort_by' => in_array($requestedSortBy, $allowedSorts, true)
            ? $requestedSortBy
            : 'ticket_id',
        'sort_dir' => strtoupper((string) ($query['sort_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
    ];
}

// Builds the WHERE clause and parameter set for the ticket list query.
function ticket_query_service_where(array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['status'] !== '' && $filters['status'] !== 'Archived' && $filters['status'] !== 'Deleted') {
        $where[] = 't.status = :status';
        $params[':status'] = $filters['status'];
    }

    if ($filters['priority'] !== '') {
        $where[] = 't.priority = :priority';
        $params[':priority'] = $filters['priority'];
    }

    if ($filters['country'] !== '') {
        $where[] = 't.country LIKE :country';
        $params[':country'] = '%' . $filters['country'] . '%';
    }

    if ($filters['customer'] !== '') {
        $where[] = 't.customer LIKE :customer';
        $params[':customer'] = '%' . $filters['customer'] . '%';
    }

    if ($filters['assign_to'] !== '') {
        if ($filters['assign_to'] === '__unassigned__') {
            $where[] = '(t.assign_to IS NULL OR t.assign_to = \'\')';
        } else {
            $where[] = 't.assign_to = :assign_to';
            $params[':assign_to'] = $filters['assign_to'];
        }
    }

    if ($filters['assigned_vendor_id'] !== '') {
        $where[] = 't.assigned_vendor_id = :assigned_vendor_id';
        $params[':assigned_vendor_id'] = (int) $filters['assigned_vendor_id'];
    }

    if ($filters['external_ticket_id'] !== '') {
        $where[] = 't.external_ticket_id LIKE :external_ticket_id';
        $params[':external_ticket_id'] = '%' . $filters['external_ticket_id'] . '%';
    }

    if ($filters['from_date'] !== '') {
        $where[] = 'DATE(t.created_at) >= :from_date';
        $params[':from_date'] = $filters['from_date'];
    }

    if ($filters['to_date'] !== '') {
        $where[] = 'DATE(t.created_at) <= :to_date';
        $params[':to_date'] = $filters['to_date'];
    }

    if ($filters['search'] !== '') {
        $where[] = '(CAST(t.ticket_id AS CHAR) LIKE :search_ticket_id OR t.issue LIKE :search_subject OR t.description LIKE :search_description)';
        $params[':search_ticket_id'] = '%' . $filters['search'] . '%';
        $params[':search_subject'] = '%' . $filters['search'] . '%';
        $params[':search_description'] = '%' . $filters['search'] . '%';
    }

    if ($filters['show_deleted']) {
        $where[] = 't.is_deleted = 1';
    } elseif ($filters['show_archived']) {
        $where[] = 't.is_deleted = 1 AND t.deleted_at IS NOT NULL';
    } else {
        $where[] = 't.deleted_at IS NULL';
        $where[] = 't.is_deleted = 0';
    }

    return [
        'sql' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
    ];
}

// Fetches paginated tickets for the list view.
function ticket_query_service_list(PDO $pdo, array $filters, ?array $currentUser = null): array
{
    party_service_ensure_schema($pdo);
    $filters = ticket_query_service_filters($filters);
    $query = ticket_query_service_where($filters);
    $whereSql = $query['sql'];
    $params = $query['params'];

    if ($currentUser !== null) {
        [$scopeSql, $scopeParams] = ticket_scope($currentUser, 't', true);
        $whereSql .= $whereSql === '' ? ' WHERE ' . $scopeSql : ' AND ' . $scopeSql;
        $params = array_merge($params, $scopeParams);
    }

    $baseSql = ' FROM tickets t
                 LEFT JOIN parties initiator ON initiator.id = t.initiator_party_id
                 LEFT JOIN parties vendor ON vendor.id = t.assigned_vendor_id
                 LEFT JOIN users assignee ON assignee.user_id = t.assign_to
                 LEFT JOIN users creator ON creator.user_id = t.created_by
                 LEFT JOIN users updater ON updater.user_id = t.updated_by';

    $countStmt = $pdo->prepare('SELECT COUNT(*) AS cnt' . $baseSql . $whereSql);
    $countStmt->execute($params);
    $total = (int) ($countStmt->fetch()['cnt'] ?? 0);

    $totalPages = (int) max(1, ceil($total / $filters['limit']));
    $currentPage = min($filters['page'], $totalPages);
    $offset = ($currentPage - 1) * $filters['limit'];
    $sortByMap = [
        'ticket_id' => 't.ticket_id',
        'external_ticket_id' => 't.external_ticket_id',
        'created_at' => 't.created_at',
        'updated_at' => 't.updated_at',
        'status' => 't.status',
        'priority' => 't.priority',
        'customer' => 't.customer',
        'issue' => 't.issue',
        'source' => 't.source',
    ];
    $sortColumn = $sortByMap[$filters['sort_by']] ?? 't.ticket_id';

$dataSql = 'SELECT
                    t.ticket_id,
                    t.external_ticket_id,
                    t.customer,
                    t.customer_email,
                    t.country,
                    t.issue,
                    t.description,
                    t.status,
                    t.priority,
                    t.source,
                    ' . party_service_ticket_select_columns($pdo) . ',
                    initiator.name AS initiator_party_name,
                    vendor.name AS assigned_vendor_name,
                    t.assign_to,
                    t.created_by,
                    t.created_at,
                    t.closed_at,
                    t.updated_at,
                    t.updated_by,
                    t.deleted_at,
                    t.is_deleted,
                    t.delete_reason,
                    assignee.name AS assignee_name,
                    creator.name AS creator_name,
                    updater.name AS updater_name
                  ' . $baseSql . $whereSql . '
                ORDER BY ' . $sortColumn . ' ' . $filters['sort_dir'] . ', t.ticket_id DESC
                LIMIT ' . (int) $filters['limit'] . ' OFFSET ' . (int) $offset;

    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->execute($params);
    $tickets = $dataStmt->fetchAll();

    // Compute daily sequences for these tickets in one query (no window function)
    if ($tickets) {
        $ticketIds = array_column($tickets, 'ticket_id');
        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $seqSql = "
            SELECT t1.ticket_id, COUNT(*) as seq
            FROM tickets t1
            JOIN tickets t2 ON DATE(t2.created_at) = DATE(t1.created_at) AND t2.ticket_id <= t1.ticket_id
            WHERE t1.ticket_id IN ($placeholders)
            GROUP BY t1.ticket_id
            ORDER BY t1.ticket_id
        ";
        $seqStmt = $pdo->prepare($seqSql);
        $seqStmt->execute($ticketIds);
        $sequences = $seqStmt->fetchAll(PDO::FETCH_KEY_PAIR); // ticket_id => seq

        foreach ($tickets as &$ticket) {
            $tid = (int) $ticket['ticket_id'];
            $ticket['daily_sequence'] = $sequences[$tid] ?? 1;
        }
        unset($ticket);
    }

    return [
        'tickets' => $tickets,
        'total' => $total,
        'total_pages' => $totalPages,
        'page' => $currentPage,
        'range_start' => $total > 0 ? ($offset + 1) : 0,
        'range_end' => min($total, $offset + $filters['limit']),
    ];
}

// Returns one ticket plus logs/thread data for the detail page.
function ticket_query_service_detail(PDO $pdo, int $ticketId): ?array
{
    party_service_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT
            t.ticket_id,
            t.external_ticket_id,
            t.customer,
            t.customer_email,
            t.country,
            t.issue,
            t.description,
            t.status,
            t.priority,
            t.assign_to,
            t.created_by,
            t.created_at,
            t.closed_at,
            t.mail_message_id,
            t.mail_thread_id,
            t.send_auto_acknowledgement,
            ' . party_service_ticket_select_columns($pdo) . ',
            initiator.name AS initiator_party_name,
            vendor.name AS assigned_vendor_name,
            t.source,
            assignee.name AS assignee_name,
            creator.name AS creator_name
FROM tickets t
         LEFT JOIN parties initiator ON initiator.id = t.initiator_party_id
         LEFT JOIN parties vendor ON vendor.id = t.assigned_vendor_id
         LEFT JOIN users assignee ON assignee.user_id = t.assign_to
         LEFT JOIN users creator ON creator.user_id = t.created_by
         WHERE t.ticket_id = :ticket_id
         AND t.is_deleted = 0
         LIMIT 1'
    );
    $stmt->execute([':ticket_id' => $ticketId]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        return null;
    }

    // Compute daily_sequence for this single ticket
    $seqStmt = $pdo->prepare(
        "SELECT COUNT(*) as seq
         FROM tickets t1
         JOIN tickets t2 ON DATE(t2.created_at) = DATE(t1.created_at) AND t2.ticket_id <= t1.ticket_id
         WHERE t1.ticket_id = :ticket_id"
    );
    $seqStmt->execute([':ticket_id' => $ticketId]);
    $ticket['daily_sequence'] = (int) ($seqStmt->fetch()['seq'] ?? 1);

    $ticket['logs'] = ticket_log_service_for_ticket($pdo, $ticketId);
    $ticket['thread'] = array_values(array_filter(
        $ticket['logs'],
        static fn(array $log): bool => in_array($log['action'], ['email_received', 'incoming_reply', 'email_reply', 'email_sent', 'email_failed'], true)
    ));

    return $ticket;
}
