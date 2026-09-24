<?php

declare(strict_types=1);

namespace Foreningssystem\Infrastructure\WordPress;

use Foreningssystem\Domain\Meeting\AuditEvent;
use Foreningssystem\Domain\Meeting\AuditLog;

final class WpAuditLog implements AuditLog
{
    public function record(string $objectType, int $objectId, string $action, int $actorUserId): void
    {
        global $wpdb;

        $inserted = $wpdb->insert($this->table(), [
            'object_type' => $objectType,
            'object_id' => $objectId,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'created_at' => current_time('mysql', true),
        ], ['%s', '%d', '%s', '%d', '%s']);

        if ($inserted === false) {
            throw new \RuntimeException('The audit event could not be saved.');
        }
    }

    public function forObject(string $objectType, int $objectId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE object_type = %s AND object_id = %d ORDER BY id',
            $objectType,
            $objectId
        ), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        return array_map(static function (array $row): AuditEvent {
            return new AuditEvent(
                (int) $row['id'],
                (string) $row['object_type'],
                (int) $row['object_id'],
                (string) $row['action'],
                (int) $row['actor_user_id'],
                (string) $row['created_at']
            );
        }, $rows);
    }

    private function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'assoc_audit_event';
    }
}
