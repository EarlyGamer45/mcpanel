<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Events;
use App\Core\Sequences;

final class TicketService
{
    /** @return array<string,mixed> */
    public static function open(int $userId, string $department, string $subject, string $body, ?int $serviceId = null, string $priority = 'normal'): array
    {
        if (!in_array($department, ['technical', 'billing', 'sales'], true)) {
            $department = 'technical';
        }
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        $db = App::db();
        $year = (int) gmdate('Y');
        $number = sprintf('T-%d-%05d', $year, Sequences::next('ticket:' . $year));
        $ticketId = $db->insert('tickets', [
            'number' => $number,
            'user_id' => $userId,
            'service_id' => $serviceId,
            'department' => $department,
            'priority' => $priority,
            'status' => 'open',
            'subject' => mb_substr($subject, 0, 200),
            'created_at' => Database::now(),
            'updated_at' => Database::now(),
        ]);
        $db->insert('ticket_messages', [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'body' => $body,
            'internal' => 0,
            'created_at' => Database::now(),
        ]);
        Events::dispatch('ticket.opened', ['ticket_id' => $ticketId]);
        Audit::log('ticket.opened', 'ticket', $ticketId, ['department' => $department]);

        return self::find($ticketId) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return App::db()->selectOne('SELECT * FROM tickets WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findForUser(int $id, int $userId): ?array
    {
        return App::db()->selectOne('SELECT * FROM tickets WHERE id = :id AND user_id = :user', ['id' => $id, 'user' => $userId]);
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return App::db()->select('SELECT * FROM tickets WHERE user_id = :user ORDER BY id DESC', ['user' => $userId]);
    }

    /** @return list<array<string,mixed>> */
    public static function messages(int $ticketId, bool $includeInternal = false): array
    {
        $sql = 'SELECT * FROM ticket_messages WHERE ticket_id = :id';
        if (!$includeInternal) {
            $sql .= ' AND internal = 0';
        }
        $sql .= ' ORDER BY id ASC';

        return App::db()->select($sql, ['id' => $ticketId]);
    }

    public static function latestMessageId(int $ticketId): int
    {
        return (int) App::db()->value('SELECT MAX(id) FROM ticket_messages WHERE ticket_id = :id', ['id' => $ticketId]);
    }

    public static function reply(int $ticketId, ?int $userId, string $body, bool $internal = false): void
    {
        $db = App::db();
        $ticket = self::find($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }
        $db->insert('ticket_messages', [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'body' => $body,
            'internal' => $internal ? 1 : 0,
            'created_at' => Database::now(),
        ]);
        $data = ['updated_at' => Database::now()];
        if (!$internal) {
            $data['status'] = $userId === null || $userId !== (int) $ticket['user_id'] ? 'waiting_customer' : 'waiting_staff';
            if (empty($ticket['first_response_at']) && $userId !== (int) $ticket['user_id']) {
                $data['first_response_at'] = Database::now();
            }
        }
        $db->update('tickets', $data, 'id = :id', ['id' => $ticketId]);
        if (!$internal) {
            Events::dispatch('ticket.replied', ['ticket_id' => $ticketId]);
        }
    }

    public static function close(int $ticketId, ?int $rating = null, ?int $actorId = null): void
    {
        App::db()->update('tickets', [
            'status' => 'closed',
            'closed_at' => Database::now(),
            'rating' => $rating,
            'updated_at' => Database::now(),
        ], 'id = :id', ['id' => $ticketId]);
        Audit::log('ticket.closed', 'ticket', $ticketId, ['rating' => $rating, 'actor' => $actorId]);
    }
}
