<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\App;
use App\Core\HttpException;

/**
 * Ticket attachments. Files live outside the document root, names are generated
 * by the server and the MIME type is detected from the bytes, never trusted
 * from the browser. Only images, PDF, plain text and archives are accepted.
 */
final class Attachments
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string,string> mime => extension */
    private const ALLOWED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
        'application/gzip' => 'gz',
    ];

    /** @param array<string,mixed> $file $_FILES entry */
    public static function store(int $ticketId, int $messageId, array $file): ?array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new HttpException(422, __('attachment_invalid'));
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new HttpException(422, __('attachment_too_large'));
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new HttpException(422, __('attachment_invalid'));
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            throw new HttpException(422, __('attachment_type'));
        }

        $original = basename((string) ($file['name'] ?? 'file'));
        $original = preg_replace('/[^\pL\pN ._-]+/u', '_', $original) ?? 'file';
        $original = mb_substr(trim($original), 0, 180) ?: 'file';
        $extension = self::ALLOWED[$mime];
        $relative = 'attachments/tickets/' . $ticketId . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $directory = App::path('storage/' . dirname($relative));
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new HttpException(500, 'Cannot create attachment directory');
        }
        $destination = App::path('storage/' . $relative);
        if (!move_uploaded_file($tmp, $destination)) {
            throw new HttpException(500, 'Cannot store attachment');
        }
        try {
            $id = App::db()->insert('ticket_attachments', [
                'message_id' => $messageId,
                'filename' => $original,
                'path' => $relative,
                'mime' => $mime,
                'size' => $size,
            ]);
        } catch (\Throwable $e) {
            @unlink($destination);
            throw $e;
        }

        return App::db()->selectOne('SELECT * FROM ticket_attachments WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int,list<array<string,mixed>>> attachments grouped by message id */
    public static function forTicket(int $ticketId): array
    {
        $rows = App::db()->select(
            'SELECT a.* FROM ticket_attachments a
             JOIN ticket_messages m ON m.id = a.message_id
             WHERE m.ticket_id = :ticket ORDER BY a.id ASC',
            ['ticket' => $ticketId]
        );
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['message_id']][] = $row;
        }

        return $grouped;
    }

    /** @return array<string,mixed>|null attachment joined with its ticket owner */
    public static function find(int $id): ?array
    {
        return App::db()->selectOne(
            'SELECT a.*, m.ticket_id, t.user_id FROM ticket_attachments a
             JOIN ticket_messages m ON m.id = a.message_id
             JOIN tickets t ON t.id = m.ticket_id
             WHERE a.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findForUser(int $id, int $userId): ?array
    {
        $attachment = self::find($id);

        return $attachment !== null && (int) $attachment['user_id'] === $userId ? $attachment : null;
    }

    public static function absolutePath(array $attachment): string
    {
        return App::path('storage/' . ltrim((string) $attachment['path'], '/'));
    }
}
