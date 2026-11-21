<?php

function client_ip(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45);
}

function audit(string $action, ?string $entity = null, $entityId = null, ?int $studentId = null, array $changedFields = [], ?array $actor = null): void {
    $actor = $actor ?? current_user();
    db_run(
        'INSERT INTO audit_logs (actor_id, actor_role, action, entity, entity_id, student_id, changed_fields, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $actor['id'] ?? null,
            $actor['role'] ?? null,
            $action,
            $entity,
            $entityId,
            $studentId,
            $changedFields ? substr(implode(',', $changedFields), 0, 500) : null,
            client_ip(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            now(),
        ]
    );
}

function changed_fields(array $before, array $after): array {
    $changed = [];
    foreach ($after as $k => $v) {
        if (array_key_exists($k, $before) && (string)$before[$k] !== (string)$v) {
            $changed[] = $k;
        }
    }
    return $changed;
}
