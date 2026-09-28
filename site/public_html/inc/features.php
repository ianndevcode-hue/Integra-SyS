<?php
declare(strict_types=1);

/**
 * Cross-cutting features: status catalog, tags, activity timeline (notes/follow-ups),
 * file attachments, support-desk workflow (SLA pause, events, CSAT) and global search.
 */

/* ================================================================ STATUSES */

/** Single source of truth for statuses (value => label). Mirrored in admin/js/core.js LABELS. */
function status_sets(): array
{
    return [
        'customer' => ['lead' => 'Prospect', 'onboarding' => 'Em implantação', 'active' => 'Ativo', 'paused' => 'Pausado', 'delinquent' => 'Inadimplente', 'inactive' => 'Inativo'],
        'project' => ['proposal' => 'Proposta', 'approved' => 'Aprovado (a iniciar)', 'active' => 'Em andamento', 'waiting_client' => 'Aguardando cliente', 'review' => 'Em homologação', 'paused' => 'Pausado', 'maintenance' => 'Em manutenção', 'done' => 'Concluído', 'canceled' => 'Cancelado'],
        'stage' => ['pending' => 'Pendente', 'in_progress' => 'Em andamento', 'waiting_client' => 'Aguardando cliente', 'review' => 'Aguardando aprovação', 'blocked' => 'Bloqueada', 'done' => 'Concluída', 'skipped' => 'Dispensada'],
        'lead' => ['new' => 'Novo', 'contacted' => 'Contatado', 'meeting' => 'Reunião marcada', 'proposal' => 'Proposta enviada', 'negotiation' => 'Negociação', 'won' => 'Ganho', 'lost' => 'Perdido', 'nurture' => 'Nutrir (futuro)'],
        'appointment' => ['scheduled' => 'Agendado', 'confirmed' => 'Confirmado', 'rescheduled' => 'Remarcado', 'done' => 'Realizado', 'canceled' => 'Cancelado', 'no_show' => 'Não compareceu'],
        'priority' => ['low' => 'Baixa', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente'],
        'ticket' => ['open' => 'Aberto', 'in_progress' => 'Em atendimento', 'waiting' => 'Aguardando cliente', 'waiting_third' => 'Aguardando terceiros', 'on_hold' => 'Pausado', 'resolved' => 'Resolvido', 'closed' => 'Encerrado'],
    ];
}

function status_values(string $set): array
{
    return array_keys(status_sets()[$set]);
}

function status_label(string $set, ?string $value): string
{
    return status_sets()[$set][$value ?? ''] ?? (string)$value;
}

/** Projects that count as "in execution" (dashboard, lookups, lateness). */
const PROJECT_RUNNING = ['approved', 'active', 'waiting_client', 'review', 'maintenance'];
const STAGE_FINISHED = ['done', 'skipped'];
const STAGE_CURRENT = ['in_progress', 'waiting_client', 'review', 'blocked'];
const TICKET_OPEN = ['open', 'in_progress', 'waiting', 'waiting_third', 'on_hold'];
const TICKET_PAUSED = ['waiting', 'waiting_third', 'on_hold'];

function sql_in(array $values): string
{
    return "('" . implode("','", array_map(fn($v) => str_replace("'", '', $v), $values)) . "')";
}

/* ==================================================================== TAGS */

function tags_normalize($raw): ?string
{
    $list = is_array($raw) ? $raw : preg_split('/[,;#]+/', (string)$raw);
    $out = [];
    foreach ($list as $t) {
        $t = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$t)));
        if ($t !== '' && mb_strlen($t) <= 30 && !in_array($t, $out, true)) $out[] = $t;
    }
    return $out ? mb_substr(implode(', ', array_slice($out, 0, 12)), 0, 255) : null;
}

/** SQL fragment filtering a comma-separated tags column by one tag (portable MySQL/SQLite). */
function tag_filter(string $col, string $tag): array
{
    $tag = mb_strtolower(trim($tag));
    $concat = db_driver() === 'sqlite' ? "(', ' || $col || ',')" : "CONCAT(', ', $col, ',')";
    return ["$concat LIKE ?", ['%, ' . $tag . ',%']];
}

/* ============================================================== ACTIVITIES */

const ACTIVITY_ENTITIES = ['customer' => 'customers', 'lead' => 'leads', 'project' => 'projects', 'ticket' => 'tickets'];
const ACTIVITY_KINDS = ['note' => 'Anotação', 'call' => 'Ligação', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'meeting' => 'Reunião', 'task' => 'Follow-up', 'event' => 'Evento'];

function activity_add(string $entity, int $id, string $kind, string $body, ?string $dueAt = null, ?array $user = null): int
{
    $user = $user ?? current_user();
    return db_insert('activities', [
        'entity' => $entity, 'entity_id' => $id, 'kind' => array_key_exists($kind, ACTIVITY_KINDS) ? $kind : 'note',
        'body' => mb_substr($body, 0, 5000), 'due_at' => $dueAt, 'done' => 0,
        'user_id' => $user['id'] ?? null, 'user_name' => $user['name'] ?? 'Sistema', 'created_at' => now(),
    ]);
}

/* ============================================================= ATTACHMENTS */

/** entity => permission area */
const ATTACHMENT_ENTITIES = ['ticket' => 'tickets', 'project' => 'projects', 'customer' => 'customers', 'lead' => 'leads', 'entry' => 'finance'];
/** entity => table */
const ATTACHMENT_TABLES = ['ticket' => 'tickets', 'project' => 'projects', 'customer' => 'customers', 'lead' => 'leads', 'entry' => 'financial_entries'];
const ATTACHMENT_EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'zip', 'rar', '7z', 'xml', 'json', 'mp4', 'mov', 'mp3'];
const ATTACHMENT_MAX_BYTES = 15 * 1024 * 1024;

/** Normalize $_FILES[field] (single or multiple) into a list of file arrays. */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/** Validate an uploaded file and return its sanitized name (throws AppException). */
function attachment_validate(array $file): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new AppException(in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Arquivo grande demais (máximo 15 MB).' : 'Falha no envio do arquivo. Tente novamente.');
    }
    if ($file['size'] > ATTACHMENT_MAX_BYTES) throw new AppException('Arquivo grande demais (máximo 15 MB).');
    $name = trim(preg_replace('/[\x00-\x1f\/\\\\]+/u', '_', basename((string)$file['name']))) ?: 'arquivo';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ATTACHMENT_EXT, true)) throw new AppException("Tipo de arquivo não permitido (.$ext). Envie PDF, imagem, documento Office, planilha, texto ou ZIP.");
    if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') throw new AppException('Upload inválido.');
    return $name;
}

/** Validate every file of a multipart field up-front (so nothing is saved when one is invalid). */
function attachments_validate_all(string $field): void
{
    $files = uploaded_files($field);
    if (count($files) > 10) throw new AppException('Envie no máximo 10 arquivos por vez.');
    foreach ($files as $f) attachment_validate($f);
}

function attachment_store(array $file, string $entity, int $entityId, ?int $messageId, bool $clientVisible, string $byType, string $byName): array
{
    if (!isset(ATTACHMENT_ENTITIES[$entity])) throw new AppException('Tipo de anexo inválido.');
    $name = attachment_validate($file);
    $dir = STORAGE_PATH . '/uploads/' . date('Y/m');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new AppException('Não foi possível salvar o arquivo (pasta de uploads sem permissão).');
    $stored = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.bin';
    $target = STORAGE_PATH . '/uploads/' . $stored;
    $ok = PHP_SAPI === 'cli' ? copy($file['tmp_name'], $target) : move_uploaded_file($file['tmp_name'], $target);
    if (!$ok) throw new AppException('Não foi possível salvar o arquivo.');
    $mime = function_exists('mime_content_type') ? (mime_content_type($target) ?: null) : null;
    $id = db_insert('attachments', [
        'entity' => $entity, 'entity_id' => $entityId, 'message_id' => $messageId,
        'file_name' => mb_substr($name, 0, 255), 'stored_name' => $stored, 'mime' => $mime ? mb_substr($mime, 0, 120) : null,
        'size_bytes' => (int)$file['size'], 'client_visible' => $clientVisible ? 1 : 0,
        'uploaded_by_type' => $byType, 'uploaded_by_name' => mb_substr($byName, 0, 160), 'created_at' => now(),
    ]);
    return db_find('attachments', $id);
}

function attachments_store_all(string $field, string $entity, int $entityId, ?int $messageId, bool $clientVisible, string $byType, string $byName): array
{
    $files = uploaded_files($field);
    if (count($files) > 10) throw new AppException('Envie no máximo 10 arquivos por vez.');
    return array_map(fn($f) => attachment_store($f, $entity, $entityId, $messageId, $clientVisible, $byType, $byName), $files);
}

function attachments_for(string $entity, int $entityId, bool $onlyVisible = false): array
{
    return db_all('SELECT id, entity, entity_id, message_id, file_name, mime, size_bytes, client_visible, uploaded_by_type, uploaded_by_name, created_at FROM attachments WHERE entity = ? AND entity_id = ?' . ($onlyVisible ? ' AND client_visible = 1' : '') . ' ORDER BY id DESC', [$entity, $entityId]);
}

function attachment_delete(array $a): void
{
    $path = STORAGE_PATH . '/uploads/' . $a['stored_name'];
    if (is_file($path)) @unlink($path);
    db_exec('DELETE FROM attachments WHERE id = ?', [$a['id']]);
}

function attachments_delete_for(string $entity, int $entityId): void
{
    foreach (db_all('SELECT * FROM attachments WHERE entity = ? AND entity_id = ?', [$entity, $entityId]) as $a) attachment_delete($a);
}

function attachment_output(array $a, bool $download = false): void
{
    $path = STORAGE_PATH . '/uploads/' . $a['stored_name'];
    if (!is_file($path)) { http_response_code(404); exit('Arquivo não encontrado.'); }
    $inline = !$download && preg_match('#^(image/(png|jpe?g|gif|webp)|application/pdf|text/plain)$#', (string)$a['mime']);
    header('Content-Type: ' . ($inline ? $a['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . str_replace('"', '', strip_accents($a['file_name'])) . "\"; filename*=UTF-8''" . rawurlencode($a['file_name']));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

function human_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

/* ============================================================ SUPPORT DESK */

function ticket_sla_hours(string $priority): int
{
    $defaults = ['urgent' => 4, 'high' => 8, 'normal' => 24, 'low' => 48];
    $h = (int)setting('ticket_sla_' . $priority, '0');
    if ($h <= 0 && $priority === 'normal') $h = (int)setting('ticket_sla_hours', '0');
    return $h > 0 ? $h : ($defaults[$priority] ?? 24);
}

/** Insert a message; staff public replies set first_response_at. */
/** New SLA deadline for a priority change, keeping time already added by SLA pauses. */
function ticket_sla_for_priority(array $ticket, string $newPriority, ?string $currentDue = null): string
{
    $created = strtotime($ticket['created_at']);
    $due = strtotime($currentDue ?? $ticket['sla_due_at'] ?? '') ?: $created + ticket_sla_hours($ticket['priority']) * 3600;
    $pauseOffset = max(0, $due - ($created + ticket_sla_hours($ticket['priority']) * 3600));
    return date('Y-m-d H:i:s', $created + ticket_sla_hours($newPriority) * 3600 + $pauseOffset);
}

function ticket_add_message(array $ticket, string $authorType, ?string $authorName, string $body, bool $internal = false, ?int $userId = null, string $kind = 'message'): int
{
    $id = db_insert('ticket_messages', [
        'ticket_id' => $ticket['id'], 'author_type' => $authorType, 'author_name' => $authorName,
        'body' => mb_substr($body, 0, 8000), 'internal' => $internal ? 1 : 0, 'kind' => $kind, 'user_id' => $userId, 'created_at' => now(),
    ]);
    if ($authorType === 'staff' && !$internal && $kind === 'message' && empty($ticket['first_response_at'])) {
        db_update('tickets', (int)$ticket['id'], ['first_response_at' => now()]);
    }
    return $id;
}

/**
 * Fields to apply when a ticket changes status: pauses the SLA clock while waiting on the
 * customer/third parties, resumes it (pushing the deadline) and stamps resolved/closed times.
 */
function ticket_status_patch(array $ticket, string $status): array
{
    $old = $ticket['status'];
    if ($old === $status) return [];
    $patch = ['status' => $status];
    $wasPaused = in_array($old, TICKET_PAUSED, true) && !empty($ticket['sla_paused_at']);
    $nowPaused = in_array($status, TICKET_PAUSED, true);
    if ($nowPaused && !$wasPaused) $patch['sla_paused_at'] = now();
    if (!$nowPaused && $wasPaused) {
        $pausedFor = time() - strtotime($ticket['sla_paused_at']);
        if ($ticket['sla_due_at'] && $pausedFor > 0) $patch['sla_due_at'] = date('Y-m-d H:i:s', strtotime($ticket['sla_due_at']) + $pausedFor);
        $patch['sla_paused_at'] = null;
    }
    if ($status === 'resolved') $patch['resolved_at'] = now();
    if ($status === 'closed') { $patch['closed_at'] = now(); $patch['resolved_at'] = $ticket['resolved_at'] ?: now(); }
    if (in_array($status, TICKET_OPEN, true)) { $patch['resolved_at'] = null; $patch['closed_at'] = null; }
    return $patch;
}

/** Change status with SLA handling + internal event line + e-mails where relevant. */
function ticket_change_status(array $ticket, string $status, ?string $who = null, bool $notify = true): array
{
    if (!in_array($status, status_values('ticket'), true)) throw new AppException('Status inválido.');
    $patch = ticket_status_patch($ticket, $status);
    if (!$patch) return $ticket;
    db_update('tickets', (int)$ticket['id'], $patch + ['updated_at' => now()]);
    ticket_add_message($ticket, 'system', $who ?? 'Sistema', 'Status alterado: ' . status_label('ticket', $ticket['status']) . ' → ' . status_label('ticket', $status), true, null, 'event');
    $fresh = db_find('tickets', (int)$ticket['id']);
    if ($notify && $status === 'resolved' && function_exists('mail_event_ticket_resolved')) mail_event_ticket_resolved($fresh);
    return $fresh;
}

function ticket_event(array $ticket, string $text, ?string $who = null): void
{
    ticket_add_message($ticket, 'system', $who ?? 'Sistema', $text, true, null, 'event');
}

/** Close tickets resolved more than N days ago (cron). */
function tickets_autoclose(): int
{
    $days = (int)setting('ticket_autoclose_days', 5);
    if ($days <= 0) return 0;
    $n = 0;
    foreach (db_all("SELECT * FROM tickets WHERE status = 'resolved' AND resolved_at IS NOT NULL AND resolved_at < ?", [date('Y-m-d H:i:s', time() - $days * 86400)]) as $t) {
        ticket_change_status($t, 'closed', 'Automação', false);
        $n++;
    }
    return $n;
}

function ticket_rate(array $ticket, int $score, string $comment = ''): void
{
    if ($score < 1 || $score > 5) throw new AppException('Escolha uma nota de 1 a 5.');
    if (!in_array($ticket['status'], ['resolved', 'closed'], true)) throw new AppException('A avaliação fica disponível quando o chamado é resolvido.');
    db_update('tickets', (int)$ticket['id'], ['satisfaction' => $score, 'satisfaction_comment' => mb_substr(trim($comment), 0, 2000) ?: null, 'updated_at' => now()]);
    ticket_event($ticket, "Cliente avaliou o atendimento: $score/5" . (trim($comment) !== '' ? " — \"" . mb_substr(trim($comment), 0, 300) . '"' : ''), $ticket['name']);
}

/* ================================================================ PROJECTS */

/** Progress 0-100 from stages (finished = done/skipped) + partial credit for tasks of the current stages. */
function project_progress(string $projectStatus, array $stages, array $tasks): int
{
    if ($projectStatus === 'done') return 100;
    $total = count($stages);
    if (!$total) return 0;
    $done = count(array_filter($stages, fn($s) => in_array($s['status'], STAGE_FINISHED, true)));
    $partial = 0.0;
    foreach ($stages as $s) {
        if (in_array($s['status'], STAGE_FINISHED, true)) continue;
        $st = array_filter($tasks, fn($t) => (int)$t['stage_id'] === (int)$s['id']);
        if ($st) $partial += count(array_filter($st, fn($t) => (int)$t['done'])) / count($st) * 0.9;
    }
    return (int)min(99, round(($done + $partial) / $total * 100));
}

/** on_track | attention | at_risk | done | idle — used for badges and reports. */
function project_health(array $p): string
{
    if (in_array($p['status'], ['done', 'canceled'], true)) return 'done';
    if (in_array($p['status'], ['proposal', 'paused'], true)) return 'idle';
    $late = !empty($p['due_date']) && $p['due_date'] < today();
    $blocked = (int)($p['stages_blocked'] ?? 0) > 0;
    $overHours = !empty($p['estimated_hours']) && (float)$p['estimated_hours'] > 0 && ((int)($p['minutes_logged'] ?? 0) / 60) > (float)$p['estimated_hours'] * 1.1;
    if ($late || $blocked) return 'at_risk';
    $soon = !empty($p['due_date']) && $p['due_date'] <= date('Y-m-d', strtotime('+10 days')) && (int)($p['progress'] ?? 0) < 80;
    if ($soon || $overHours || $p['status'] === 'waiting_client' || (int)($p['approvals_pending'] ?? 0) > 0) return 'attention';
    return 'on_track';
}

/** Stages + tasks of a project as a reusable template structure. */
function project_structure(int $projectId): array
{
    $tasks = db_all('SELECT stage_id, title, client_visible FROM project_tasks WHERE project_id = ? ORDER BY position, id', [$projectId]);
    return array_map(fn($s) => [
        'name' => $s['name'], 'estimated_hours' => $s['estimated_hours'] !== null ? (float)$s['estimated_hours'] : null,
        'tasks' => array_values(array_map(fn($t) => ['title' => $t['title'], 'client_visible' => (int)$t['client_visible']], array_filter($tasks, fn($t) => (int)$t['stage_id'] === (int)$s['id']))),
    ], db_all('SELECT * FROM project_stages WHERE project_id = ? ORDER BY position', [$projectId]));
}

function project_build_structure(int $projectId, array $stages): void
{
    foreach (array_values($stages) as $pos => $st) {
        $sid = db_insert('project_stages', ['project_id' => $projectId, 'name' => mb_substr((string)$st['name'], 0, 120), 'position' => $pos, 'status' => $pos === 0 ? 'in_progress' : 'pending', 'start_date' => $pos === 0 ? today() : null, 'estimated_hours' => $st['estimated_hours'] ?? null]);
        foreach (($st['tasks'] ?? []) as $i => $t) {
            db_insert('project_tasks', ['project_id' => $projectId, 'stage_id' => $sid, 'title' => mb_substr((string)($t['title'] ?? ''), 0, 200), 'client_visible' => (int)($t['client_visible'] ?? 1), 'done' => 0, 'position' => $i, 'created_at' => now()]);
        }
    }
}

function project_apply_template(int $projectId, int $templateId): bool
{
    $tpl = db_find('project_templates', $templateId);
    $stages = $tpl ? json_decode((string)$tpl['stages'], true) : null;
    if (!is_array($stages) || !$stages) return false;
    project_build_structure($projectId, $stages);
    return true;
}

function project_duplicate(int $projectId, string $name, ?int $customerId): int
{
    $p = db_find('projects', $projectId);
    if (!$p) throw new AppException('Projeto não encontrado.');
    $id = db_insert('projects', [
        'customer_id' => $customerId ?: $p['customer_id'], 'name' => mb_substr($name, 0, 160), 'description' => $p['description'], 'project_type' => $p['project_type'],
        'status' => 'approved', 'priority' => $p['priority'], 'budget' => $p['budget'], 'manager' => $p['manager'], 'tags' => $p['tags'],
        'estimated_hours' => $p['estimated_hours'], 'hourly_rate' => $p['hourly_rate'], 'created_at' => now(), 'updated_at' => now(),
    ]);
    project_build_structure($id, project_structure($projectId));
    return $id;
}

/* ================================================================== SEARCH */

function global_search(string $q, array $user): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = '%' . $q . '%';
    $digits = only_digits($q);
    $out = [];
    if (can('customers', $user)) {
        foreach (db_all('SELECT id, name, trade_name, email, status FROM customers WHERE name LIKE ? OR trade_name LIKE ? OR email LIKE ? OR phone LIKE ? OR tags LIKE ?' . ($digits !== '' ? ' OR document LIKE ?' : '') . ' ORDER BY name LIMIT 6',
            array_merge([$like, $like, $like, $like, $like], $digits !== '' ? ['%' . $digits . '%'] : [])) as $r) {
            $out[] = ['type' => 'customer', 'id' => (int)$r['id'], 'title' => $r['name'], 'sub' => $r['trade_name'] ?: $r['email'], 'status' => status_label('customer', $r['status']), 'url' => '#/customers/' . $r['id']];
        }
    }
    if (can('projects', $user)) {
        foreach (db_all('SELECT p.id, p.name, p.status, c.name AS customer FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.name LIKE ? OR c.name LIKE ? OR p.tags LIKE ? ORDER BY p.updated_at DESC LIMIT 6', [$like, $like, $like]) as $r) {
            $out[] = ['type' => 'project', 'id' => (int)$r['id'], 'title' => $r['name'], 'sub' => $r['customer'], 'status' => status_label('project', $r['status']), 'url' => '#/projects/' . $r['id']];
        }
    }
    if (can('tickets', $user)) {
        foreach (db_all('SELECT id, protocol, subject, name, status FROM tickets WHERE protocol LIKE ? OR subject LIKE ? OR name LIKE ? OR email LIKE ? OR tags LIKE ? ORDER BY updated_at DESC LIMIT 6', [$like, $like, $like, $like, $like]) as $r) {
            $out[] = ['type' => 'ticket', 'id' => (int)$r['id'], 'title' => $r['subject'], 'sub' => $r['protocol'] . ' · ' . $r['name'], 'status' => status_label('ticket', $r['status']), 'url' => '#/tickets/' . $r['id']];
        }
    }
    if (can('leads', $user)) {
        foreach (db_all('SELECT id, name, company, status FROM leads WHERE name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? OR tags LIKE ? ORDER BY created_at DESC LIMIT 5', [$like, $like, $like, $like, $like]) as $r) {
            $out[] = ['type' => 'lead', 'id' => (int)$r['id'], 'title' => $r['name'], 'sub' => $r['company'], 'status' => status_label('lead', $r['status']), 'url' => '#/leads?open=' . $r['id']];
        }
    }
    if (can('finance', $user)) {
        foreach (db_all('SELECT id, description, amount, due_date, entry_type FROM financial_entries WHERE description LIKE ? OR supplier LIKE ? OR document_number LIKE ? ORDER BY due_date DESC LIMIT 5', [$like, $like, $like]) as $r) {
            $out[] = ['type' => 'entry', 'id' => (int)$r['id'], 'title' => $r['description'], 'sub' => ($r['entry_type'] === 'receivable' ? 'A receber · ' : 'A pagar · ') . money($r['amount']) . ' · ' . date('d/m/Y', strtotime($r['due_date'])), 'status' => '', 'url' => '#/finance/entries?q=' . rawurlencode($r['description'])];
        }
    }
    return $out;
}
