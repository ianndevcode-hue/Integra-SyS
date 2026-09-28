<?php
declare(strict_types=1);

/**
 * CRUD resource definitions consumed by the generic engine in api_lib.php.
 * Key = URL segment (/api/{key}); 'area' = permission area (see ROLES).
 */

function resources(): array
{
    $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    return [
        'customers' => [
            'table' => 'customers', 'area' => 'customers', 'timestamps' => true,
            'select' => 't.id, t.name, t.trade_name, t.document, t.email, t.phone, t.segment, t.city, t.state, t.address, t.address_number, t.district, t.city_ibge, t.postal_code, t.status, t.notes, t.tags, t.owner, t.asaas_customer_id, t.portal_enabled, t.portal_last_login_at, t.created_at, t.updated_at,
                (SELECT COUNT(*) FROM tickets k WHERE k.customer_id = t.id AND k.status IN ' . sql_in(TICKET_OPEN) . ') AS tickets_open,
                (SELECT COUNT(*) FROM projects p WHERE p.customer_id = t.id) AS projects_count,
                (SELECT COALESCE(SUM(amount),0) FROM financial_entries f WHERE f.customer_id = t.id AND f.entry_type = \'receivable\' AND f.status = \'open\') AS open_amount',
            'search' => ['t.name', 't.trade_name', 't.email', 't.document', 't.city', 't.phone', 't.tags'],
            'filters' => ['status', 'segment', 'owner'],
            'scope' => fn() => scope_tags('t.tags'),
            'sort' => ['name', 'created_at', 'city', 'status'], 'default_sort' => 'name', 'default_dir' => 'asc',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 160],
                'trade_name' => ['type' => 'string', 'max' => 160],
                'document' => ['type' => 'string', 'max' => 20],
                'email' => ['type' => 'email'],
                'phone' => ['type' => 'string', 'max' => 30],
                'segment' => ['type' => 'string', 'max' => 60],
                'city' => ['type' => 'string', 'max' => 80],
                'state' => ['type' => 'enum', 'values' => $ufs],
                'address' => ['type' => 'string', 'max' => 255],
                'address_number' => ['type' => 'string', 'max' => 20],
                'district' => ['type' => 'string', 'max' => 80],
                'city_ibge' => ['type' => 'string', 'max' => 7],
                'postal_code' => ['type' => 'string', 'max' => 10],
                'status' => ['type' => 'enum', 'values' => status_values('customer'), 'default' => 'active'],
                'notes' => ['type' => 'text'],
                'tags' => ['type' => 'string', 'max' => 255],
                'owner' => ['type' => 'string', 'max' => 120],
                'portal_enabled' => ['type' => 'bool'],
            ],
            'before_save' => function (array $data, ?array $existing) {
                if (!empty($data['email']) && ($dup = db_one('SELECT id, name FROM customers WHERE email = ? AND id != ?', [$data['email'], $existing['id'] ?? 0]))) {
                    json_error('Verifique os campos destacados.', 422, ['fields' => ['email' => "E-mail já usado pelo cliente \"{$dup['name']}\" (o e-mail é o login da Área do Cliente)."]]);
                }
                if (array_key_exists('tags', $data)) $data['tags'] = tags_normalize($data['tags']);
                if ($existing && isset($data['status']) && $data['status'] !== $existing['status']) activity_add('customer', (int)$existing['id'], 'event', 'Status: ' . status_label('customer', $existing['status']) . ' → ' . status_label('customer', $data['status']));
                if (isset($data['document'])) {
                    $doc = only_digits($data['document']);
                    if ($doc !== '' && !in_array(strlen($doc), [11, 14], true)) json_error('Verifique os campos destacados.', 422, ['fields' => ['document' => 'CPF deve ter 11 dígitos e CNPJ 14.']]);
                    $data['document'] = $doc ?: null;
                    if ($existing && $existing['document'] !== $data['document']) $data['asaas_customer_id'] = null;
                }
                $pwd = input()['portal_password'] ?? '';
                if ($pwd !== '') {
                    if (mb_strlen($pwd) < 8) json_error('Verifique os campos destacados.', 422, ['fields' => ['portal_password' => 'Mínimo de 8 caracteres.']]);
                    $data['portal_password_hash'] = password_hash($pwd, PASSWORD_DEFAULT);
                    $data['email_verified_at'] = now(); // staff vouches for this e-mail
                }
                return $data;
            },
            'before_delete' => function (int $id) {
                if (db_value('SELECT COUNT(*) FROM charges WHERE customer_id = ?', [$id])) json_error('Cliente possui cobranças. Inative-o em vez de excluir.', 409);
                db_exec('UPDATE projects SET customer_id = NULL WHERE customer_id = ?', [$id]);
                db_exec('UPDATE financial_entries SET customer_id = NULL WHERE customer_id = ?', [$id]);
                db_exec('UPDATE tickets SET customer_id = NULL WHERE customer_id = ?', [$id]);
                db_exec("DELETE FROM activities WHERE entity = 'customer' AND entity_id = ?", [$id]);
                attachments_delete_for('customer', $id);
            },
        ],

        'projects' => [
            'table' => 'projects', 'area' => 'projects', 'timestamps' => true,
            'from' => 'projects t LEFT JOIN customers c ON c.id = t.customer_id',
            'select' => "t.*, c.name AS customer_name,
                (SELECT name FROM project_stages s WHERE s.project_id = t.id AND s.status IN " . sql_in(STAGE_CURRENT) . " ORDER BY s.position LIMIT 1) AS current_stage,
                (SELECT status FROM project_stages s WHERE s.project_id = t.id AND s.status IN " . sql_in(STAGE_CURRENT) . " ORDER BY s.position LIMIT 1) AS current_stage_status,
                (SELECT COUNT(*) FROM project_stages s WHERE s.project_id = t.id) AS stages_total,
                (SELECT COUNT(*) FROM project_stages s WHERE s.project_id = t.id AND s.status IN ('done','skipped')) AS stages_done,
                (SELECT COUNT(*) FROM project_stages s WHERE s.project_id = t.id AND s.status = 'review' AND (s.client_approval IS NULL OR s.client_approval = 'pending')) AS approvals_pending,
                (SELECT COUNT(*) FROM tickets k WHERE k.project_id = t.id AND k.status IN " . sql_in(TICKET_OPEN) . ") AS tickets_open,
                (SELECT COUNT(*) FROM project_stages s WHERE s.project_id = t.id AND s.status = 'blocked') AS stages_blocked,
                (SELECT COALESCE(SUM(minutes),0) FROM time_entries te WHERE te.project_id = t.id) AS minutes_logged,
                (SELECT COUNT(*) FROM project_tasks k WHERE k.project_id = t.id) AS tasks_total,
                (SELECT COUNT(*) FROM project_tasks k WHERE k.project_id = t.id AND k.done = 1) AS tasks_done",
            'search' => ['t.name', 'c.name', 't.project_type', 't.manager', 't.tags'],
            'filters' => ['customer_id', 'priority', 'manager'],
            'scope' => function () {
                [$w, $p] = scope_tags('t.tags');
                $status = $_GET['status'] ?? '';
                $cond = $status === 'running' ? 't.status IN ' . sql_in(PROJECT_RUNNING) : ($status === 'open' ? "t.status NOT IN ('done','canceled')" : ($status !== '' ? 't.status = ?' : ''));
                if ($cond && !in_array($status, ['running', 'open'], true)) $p[] = $status;
                if (($_GET['late'] ?? '') === '1') $cond = trim($cond . ($cond ? ' AND ' : '') . "t.due_date < '" . today() . "' AND t.status NOT IN ('done','canceled')");
                return [implode(' AND ', array_filter([$w, $cond])), $p];
            },
            'sort' => ['created_at', 'name', 'due_date', 'budget'], 'default_sort' => 'created_at',
            'transform' => function (array $r) {
                $r['late'] = $r['due_date'] && $r['due_date'] < today() && !in_array($r['status'], ['done', 'canceled'], true);
                $total = (int)$r['stages_total'];
                $r['progress'] = $total ? (int)round(((int)$r['stages_done'] + ((int)$r['tasks_total'] ? (int)$r['tasks_done'] / (int)$r['tasks_total'] * 0.99 : 0)) / $total * 100) : 0;
                if ($r['status'] === 'done') $r['progress'] = 100;
                $r['hours_logged'] = round((int)$r['minutes_logged'] / 60, 1);
                $r['health'] = project_health($r);
                return $r;
            },
            'fields' => [
                'customer_id' => ['type' => 'int'],
                'name' => ['type' => 'string', 'required' => true, 'max' => 160],
                'description' => ['type' => 'text'],
                'project_type' => ['type' => 'string', 'max' => 60],
                'status' => ['type' => 'enum', 'values' => status_values('project'), 'default' => 'active'],
                'priority' => ['type' => 'enum', 'values' => ['low', 'normal', 'high', 'urgent'], 'default' => 'normal'],
                'start_date' => ['type' => 'date'],
                'due_date' => ['type' => 'date'],
                'budget' => ['type' => 'decimal', 'default' => 0],
                'manager' => ['type' => 'string', 'max' => 120],
                'tags' => ['type' => 'string', 'max' => 255],
                'estimated_hours' => ['type' => 'decimal'],
                'hourly_rate' => ['type' => 'decimal'],
            ],
            'before_save' => function (array $data, ?array $existing) {
                if (array_key_exists('tags', $data)) $data['tags'] = tags_normalize($data['tags']);
                if ($existing && isset($data['status']) && $data['status'] !== $existing['status']) activity_add('project', (int)$existing['id'], 'event', 'Status: ' . status_label('project', $existing['status']) . ' → ' . status_label('project', $data['status']));
                return $data;
            },
            'after_create' => function (int $id) {
                $tpl = (int)(input()['template_id'] ?? 0);
                if ($tpl && project_apply_template($id, $tpl)) return;
                create_project_stages($id);
            },
            'before_delete' => function (int $id) {
                db_exec('DELETE FROM project_tasks WHERE project_id = ?', [$id]);
                db_exec('DELETE FROM project_stages WHERE project_id = ?', [$id]);
                db_exec('UPDATE financial_entries SET project_id = NULL WHERE project_id = ?', [$id]);
                db_exec('UPDATE tickets SET project_id = NULL WHERE project_id = ?', [$id]);
                db_exec('DELETE FROM time_entries WHERE project_id = ?', [$id]);
                db_exec("DELETE FROM activities WHERE entity = 'project' AND entity_id = ?", [$id]);
                attachments_delete_for('project', $id);
            },
        ],

        'leads' => [
            'table' => 'leads', 'area' => 'leads', 'created_at' => true,
            'search' => ['t.name', 't.email', 't.company', 't.subject', 't.message', 't.phone', 't.tags'],
            'filters' => ['status', 'source', 'owner'],
            'scope' => function () {
                [$w, $p] = scope_tags('t.tags');
                if (($_GET['pipeline'] ?? '') === 'open') $w = trim($w . ($w ? ' AND ' : '') . "t.status NOT IN ('won','lost')");
                if (($_GET['followup'] ?? '') === 'due') { $w = trim($w . ($w ? ' AND ' : '') . 't.next_action_at <= ?'); $p[] = date('Y-m-d 23:59:59'); }
                return [$w, $p];
            },
            'sort' => ['created_at', 'name', 'status', 'next_action_at', 'estimated_value'],
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 160],
                'email' => ['type' => 'email'],
                'phone' => ['type' => 'string', 'max' => 30],
                'company' => ['type' => 'string', 'max' => 160],
                'subject' => ['type' => 'string', 'max' => 160],
                'message' => ['type' => 'text'],
                'source' => ['type' => 'enum', 'values' => ['contact', 'diagnostic', 'sys-demo', 'chat', 'manual', 'whatsapp', 'indicacao', 'instagram', 'google', 'evento'], 'default' => 'manual'],
                'status' => ['type' => 'enum', 'values' => status_values('lead'), 'default' => 'new'],
                'tags' => ['type' => 'string', 'max' => 255],
                'estimated_value' => ['type' => 'decimal'],
                'next_action_at' => ['type' => 'datetime'],
                'lost_reason' => ['type' => 'string', 'max' => 255],
                'owner' => ['type' => 'string', 'max' => 120],
            ],
            'before_save' => function (array $data, ?array $existing) {
                if (array_key_exists('tags', $data)) $data['tags'] = tags_normalize($data['tags']);
                if ($existing && isset($data['status']) && $data['status'] !== $existing['status']) activity_add('lead', (int)$existing['id'], 'event', 'Etapa do funil: ' . status_label('lead', $existing['status']) . ' → ' . status_label('lead', $data['status']));
                return $data;
            },
            'before_delete' => function (int $id) {
                db_exec("DELETE FROM activities WHERE entity = 'lead' AND entity_id = ?", [$id]);
                attachments_delete_for('lead', $id);
            },
        ],

        'appointments' => [
            'table' => 'appointments', 'area' => 'appointments', 'created_at' => true,
            'search' => ['t.name', 't.email', 't.company', 't.topic'],
            'filters' => ['status'],
            'sort' => ['scheduled_at', 'created_at'], 'default_sort' => 'scheduled_at',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 160],
                'email' => ['type' => 'email'],
                'phone' => ['type' => 'string', 'max' => 30],
                'company' => ['type' => 'string', 'max' => 160],
                'topic' => ['type' => 'string', 'max' => 160],
                'meeting_type' => ['type' => 'enum', 'values' => ['online', 'presencial', 'telefone'], 'default' => 'online'],
                'scheduled_at' => ['type' => 'datetime', 'required' => true],
                'status' => ['type' => 'enum', 'values' => status_values('appointment'), 'default' => 'scheduled'],
                'notes' => ['type' => 'text'],
            ],
        ],

        'tickets' => [
            'table' => 'tickets', 'area' => 'tickets', 'timestamps' => true,
            'from' => 'tickets t LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN projects pr ON pr.id = t.project_id',
            'select' => "t.*, c.name AS customer_name, u.name AS assignee_name, pr.name AS project_name,
                (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id AND m.kind = 'message') AS messages_count,
                (SELECT m.author_type FROM ticket_messages m WHERE m.ticket_id = t.id AND m.kind = 'message' AND m.internal = 0 ORDER BY m.id DESC LIMIT 1) AS last_author,
                (SELECT COUNT(*) FROM attachments a WHERE a.entity = 'ticket' AND a.entity_id = t.id) AS attachments_count",
            'search' => ['t.protocol', 't.name', 't.email', 't.subject', 't.tags', 'c.name'],
            'filters' => ['status', 'priority', 'category', 'customer_id', 'project_id', 'assigned_to', 'source'],
            'scope' => fn() => tickets_scope(),
            'sort' => ['updated_at', 'created_at', 'sla_due_at', 'priority'], 'default_sort' => 'updated_at',
            'transform' => function (array $r) {
                $r['sla_paused'] = !empty($r['sla_paused_at']);
                $r['sla_breached'] = in_array($r['status'], TICKET_OPEN, true) && !$r['sla_paused'] && $r['sla_due_at'] && $r['sla_due_at'] < now();
                return $r;
            },
            'fields' => [
                'customer_id' => ['type' => 'int'],
                'name' => ['type' => 'string', 'required' => true, 'max' => 160],
                'email' => ['type' => 'email', 'required' => true],
                'phone' => ['type' => 'string', 'max' => 30],
                'subject' => ['type' => 'string', 'required' => true, 'max' => 200],
                'category' => ['type' => 'enum', 'values' => array_keys(ticket_categories()), 'default' => 'duvida'],
                'priority' => ['type' => 'enum', 'values' => ['low', 'normal', 'high', 'urgent'], 'default' => 'normal'],
                'status' => ['type' => 'enum', 'values' => status_values('ticket'), 'default' => 'open'],
                'assigned_to' => ['type' => 'int'],
                'project_id' => ['type' => 'int'],
                'tags' => ['type' => 'string', 'max' => 255],
                'source' => ['type' => 'enum', 'values' => ['site', 'portal', 'email', 'whatsapp', 'phone', 'admin'], 'default' => 'admin'],
            ],
            'before_save' => function (array $data, ?array $existing) {
                if (array_key_exists('tags', $data)) $data['tags'] = tags_normalize($data['tags']);
                if (!$existing) {
                    $data['protocol'] = new_ticket_protocol();
                    $data['sla_due_at'] = ticket_sla_due($data['priority'] ?? 'normal');
                    return $data;
                }
                return ticket_before_update($data, $existing);
            },
            'after_create' => function (int $id) {
                $first = trim((string)(input()['first_message'] ?? ''));
                $t = db_find('tickets', $id);
                if ($first !== '') ticket_add_message($t, 'customer', $t['name'], $first);
                ticket_event($t, 'Chamado aberto pelo painel' . ($t['source'] !== 'admin' ? ' (canal: ' . $t['source'] . ')' : ''), current_user()['name'] ?? null);
            },
            'before_delete' => function (int $id) {
                db_exec('DELETE FROM ticket_messages WHERE ticket_id = ?', [$id]);
                db_exec("DELETE FROM activities WHERE entity = 'ticket' AND entity_id = ?", [$id]);
                attachments_delete_for('ticket', $id);
            },
        ],

        'canned-responses' => [
            'table' => 'canned_responses', 'area' => 'tickets', 'created_at' => true,
            'search' => ['t.title', 't.body'],
            'filters' => ['category'],
            'sort' => ['title', 'uses', 'created_at'], 'default_sort' => 'uses',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true, 'max' => 120],
                'body' => ['type' => 'text', 'required' => true],
                'category' => ['type' => 'string', 'max' => 40],
            ],
        ],

        'posts' => [
            'table' => 'posts', 'area' => 'content', 'created_at' => true,
            'select' => 't.id, t.slug, t.title, t.excerpt, t.content, t.category, t.cover_image, t.reading_minutes, t.published, t.published_at, t.created_at',
            'search' => ['t.title', 't.excerpt', 't.category'],
            'filters' => ['published', 'category'],
            'sort' => ['published_at', 'created_at', 'title'], 'default_sort' => 'created_at',
            'fields' => [
                'title' => ['type' => 'string', 'required' => true, 'max' => 200],
                'slug' => ['type' => 'string', 'max' => 190],
                'excerpt' => ['type' => 'string', 'max' => 400],
                'content' => ['type' => 'text'],
                'category' => ['type' => 'string', 'max' => 60],
                'cover_image' => ['type' => 'string', 'max' => 255],
                'reading_minutes' => ['type' => 'int', 'default' => 4],
                'published' => ['type' => 'bool'],
            ],
            'before_save' => function (array $data, ?array $existing) {
                if (array_key_exists('slug', $data) || !$existing) {
                    $base = slugify($data['slug'] ?: ($data['title'] ?? $existing['title']));
                    $slug = $base;
                    $n = 2;
                    while (db_value('SELECT id FROM posts WHERE slug = ? AND id != ?', [$slug, $existing['id'] ?? 0])) $slug = $base . '-' . $n++;
                    $data['slug'] = $slug;
                }
                if (!empty($data['published']) && (!$existing || !$existing['published_at'])) $data['published_at'] = now();
                if (isset($data['content'])) $data['content'] = sanitize_html($data['content']);
                return $data;
            },
        ],

        'help-articles' => [
            'table' => 'help_articles', 'area' => 'content',
            'search' => ['t.question', 't.answer', 't.keywords'],
            'filters' => ['category', 'published'],
            'sort' => ['position', 'views', 'helpful'], 'default_sort' => 'position', 'default_dir' => 'asc',
            'fields' => [
                'category' => ['type' => 'enum', 'values' => array_keys(help_categories()), 'required' => true],
                'question' => ['type' => 'string', 'required' => true, 'max' => 255],
                'answer' => ['type' => 'text', 'required' => true],
                'keywords' => ['type' => 'string', 'max' => 255],
                'position' => ['type' => 'int', 'default' => 0],
                'published' => ['type' => 'bool'],
            ],
        ],

        'categories' => [
            'table' => 'categories', 'area' => 'finance',
            'filters' => ['entry_type', 'dre_group'],
            'sort' => ['name', 'entry_type'], 'default_sort' => 'name', 'default_dir' => 'asc',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 100],
                'entry_type' => ['type' => 'enum', 'values' => ['receivable', 'payable'], 'required' => true],
                'dre_group' => ['type' => 'enum', 'values' => ['revenue', 'other_income', 'tax', 'cost', 'expense', 'investment', 'distribution'], 'required' => true],
                'color' => ['type' => 'string', 'max' => 10],
            ],
            'before_delete' => function (int $id) { db_exec('UPDATE financial_entries SET category_id = NULL WHERE category_id = ?', [$id]); },
        ],

        'entries' => [
            'table' => 'financial_entries', 'area' => 'finance', 'timestamps' => true,
            'from' => 'financial_entries t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN customers cu ON cu.id = t.customer_id LEFT JOIN projects p ON p.id = t.project_id',
            'select' => "t.*, c.name AS category_name, c.color AS category_color, c.dre_group, cu.name AS customer_name, p.name AS project_name,
                (SELECT COUNT(*) FROM attachments a WHERE a.entity = 'entry' AND a.entity_id = t.id) AS attachments_count",
            'search' => ['t.description', 't.supplier', 'cu.name', 't.document_number', 't.tags'],
            'filters' => ['entry_type', 'category_id', 'customer_id', 'project_id', 'contract_id'],
            'scope' => function () {
                $w = [];
                $p = [];
                $status = $_GET['status'] ?? '';
                if ($status === 'overdue') { $w[] = "t.status = 'open' AND t.due_date < ?"; $p[] = today(); }
                elseif ($status !== '') { $w[] = 't.status = ?'; $p[] = $status; }
                $dateCol = ($_GET['date_field'] ?? '') === 'paid_at' ? 't.paid_at' : 't.due_date';
                if (!empty($_GET['from'])) { $w[] = "$dateCol >= ?"; $p[] = $_GET['from']; }
                if (!empty($_GET['to'])) { $w[] = "$dateCol <= ?"; $p[] = $_GET['to']; }
                if (($_GET['reconciled'] ?? '') === '0') $w[] = 't.bank_transaction_id IS NULL';
                if (!empty($_GET['tag'])) { [$tw, $tp] = tag_filter('t.tags', (string)$_GET['tag']); $w[] = $tw; $p = array_merge($p, $tp); }
                if (!empty($_GET['supplier'])) { $w[] = 't.supplier = ?'; $p[] = $_GET['supplier']; }
                return [$w ? implode(' AND ', $w) : '', $p];
            },
            'sort' => ['due_date', 'amount', 'paid_at', 'created_at', 'description'], 'default_sort' => 'due_date', 'default_dir' => 'asc',
            'transform' => function (array $r) {
                $r['overdue'] = $r['status'] === 'open' && $r['due_date'] < today();
                return $r;
            },
            'fields' => [
                'entry_type' => ['type' => 'enum', 'values' => ['receivable', 'payable'], 'required' => true],
                'description' => ['type' => 'string', 'required' => true, 'max' => 255],
                'category_id' => ['type' => 'int'],
                'customer_id' => ['type' => 'int'],
                'project_id' => ['type' => 'int'],
                'supplier' => ['type' => 'string', 'max' => 160],
                'amount' => ['type' => 'decimal', 'required' => true],
                'due_date' => ['type' => 'date', 'required' => true],
                'competence_date' => ['type' => 'date'],
                'paid_amount' => ['type' => 'decimal'],
                'paid_at' => ['type' => 'date'],
                'status' => ['type' => 'enum', 'values' => ['open', 'paid', 'canceled'], 'default' => 'open'],
                'payment_method' => ['type' => 'string', 'max' => 30],
                'document_number' => ['type' => 'string', 'max' => 60],
                'notes' => ['type' => 'text'],
                'tags' => ['type' => 'string', 'max' => 255],
            ],
            'before_save' => function (array $data, ?array $existing) {
                if (array_key_exists('tags', $data)) $data['tags'] = tags_normalize($data['tags']);
                if (isset($data['amount']) && $data['amount'] <= 0) json_error('Verifique os campos destacados.', 422, ['fields' => ['amount' => 'O valor deve ser maior que zero.']]);
                $status = $data['status'] ?? $existing['status'] ?? 'open';
                if ($status === 'paid') {
                    $data['paid_at'] = $data['paid_at'] ?? $existing['paid_at'] ?? today();
                    $data['paid_amount'] = $data['paid_amount'] ?? $existing['paid_amount'] ?? $data['amount'] ?? $existing['amount'];
                } elseif (array_key_exists('status', $data)) {
                    $data['paid_at'] = null;
                    $data['paid_amount'] = null;
                }
                if (!$existing && empty($data['competence_date'])) $data['competence_date'] = $data['due_date'];
                return $data;
            },
            'before_delete' => function (int $id) {
                db_exec('UPDATE bank_transactions SET reconciled = 0, entry_id = NULL WHERE entry_id = ?', [$id]);
                db_exec('UPDATE charges SET entry_id = NULL WHERE entry_id = ?', [$id]);
                db_exec('UPDATE distribution_items SET entry_id = NULL WHERE entry_id = ?', [$id]);
                attachments_delete_for('entry', $id);
            },
        ],

        'partners' => [
            'table' => 'partners', 'area' => 'partners', 'created_at' => true,
            'sort' => ['share_percent', 'name'], 'default_sort' => 'share_percent',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 160],
                'document' => ['type' => 'string', 'max' => 20],
                'email' => ['type' => 'email'],
                'share_percent' => ['type' => 'decimal', 'required' => true],
                'pix_key' => ['type' => 'string', 'max' => 160],
                'active' => ['type' => 'bool', 'default' => 1],
            ],
            'before_save' => function (array $data, ?array $existing) {
                $share = $data['share_percent'] ?? $existing['share_percent'] ?? 0;
                if ($share < 0 || $share > 100) json_error('Verifique os campos destacados.', 422, ['fields' => ['share_percent' => 'Entre 0 e 100.']]);
                return $data;
            },
        ],

        'users' => [
            'table' => 'users', 'area' => 'users', 'created_at' => true,
            'select' => 't.id, t.name, t.email, t.role, t.active, t.last_login_at, t.created_at',
            'search' => ['t.name', 't.email'],
            'sort' => ['name', 'created_at'], 'default_sort' => 'name', 'default_dir' => 'asc',
            'fields' => [
                'name' => ['type' => 'string', 'required' => true, 'max' => 120],
                'email' => ['type' => 'email', 'required' => true],
                'role' => ['type' => 'enum', 'values' => array_keys(ROLES), 'required' => true],
                'active' => ['type' => 'bool'],
            ],
            'before_save' => function (array $data, ?array $existing) {
                if (isset($data['email']) && db_value('SELECT id FROM users WHERE email = ? AND id != ?', [$data['email'], $existing['id'] ?? 0])) {
                    json_error('Verifique os campos destacados.', 422, ['fields' => ['email' => 'E-mail já cadastrado.']]);
                }
                $pwd = (string)(input()['password'] ?? '');
                if (!$existing && $pwd === '' && !empty(input()['invite'])) $data['password_hash'] = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT); // set via invite link
                elseif (!$existing && mb_strlen($pwd) < 8) json_error('Verifique os campos destacados.', 422, ['fields' => ['password' => 'Senha com no mínimo 8 caracteres.']]);
                if ($pwd !== '') {
                    if (mb_strlen($pwd) < 8) json_error('Verifique os campos destacados.', 422, ['fields' => ['password' => 'Senha com no mínimo 8 caracteres.']]);
                    $data['password_hash'] = password_hash($pwd, PASSWORD_DEFAULT);
                }
                if ($existing && (int)$existing['id'] === (int)current_user()['id'] && (isset($data['active']) && !$data['active'] || (isset($data['role']) && $data['role'] !== 'admin'))) {
                    json_error('Você não pode desativar ou rebaixar o seu próprio usuário.', 409);
                }
                return $data;
            },
            'before_delete' => function (int $id) {
                if ($id === (int)current_user()['id']) json_error('Você não pode excluir o seu próprio usuário.', 409);
            },
        ],
    ];
}

/** ?tag=foo on list endpoints. */
function scope_tags(string $col): array
{
    $tag = trim((string)($_GET['tag'] ?? ''));
    if ($tag === '') return ['', []];
    [$sql, $p] = tag_filter($col, $tag);
    return [$sql, $p];
}

/** Ticket queue views: ?view=mine|unassigned|breached|waiting|open|done and ?tag= */
function tickets_scope(): array
{
    [$w, $p] = scope_tags('t.tags');
    $conds = $w ? [$w] : [];
    $open = 't.status IN ' . sql_in(TICKET_OPEN);
    switch ($_GET['view'] ?? '') {
        case 'open': $conds[] = $open; break;
        case 'mine': $conds[] = $open; $conds[] = 't.assigned_to = ?'; $p[] = (int)(current_user()['id'] ?? 0); break;
        case 'unassigned': $conds[] = $open; $conds[] = 't.assigned_to IS NULL'; break;
        case 'breached': $conds[] = "t.status IN ('open','in_progress') AND t.sla_paused_at IS NULL AND t.sla_due_at < ?"; $p[] = now(); break;
        case 'waiting': $conds[] = 't.status IN ' . sql_in(TICKET_PAUSED); break;
        case 'customer_replied': $conds[] = "t.status IN ('open','in_progress')"; $conds[] = "(SELECT m.author_type FROM ticket_messages m WHERE m.ticket_id = t.id AND m.kind = 'message' AND m.internal = 0 ORDER BY m.id DESC LIMIT 1) = 'customer'"; break;
        case 'done': $conds[] = "t.status IN ('resolved','closed')"; break;
    }
    return [implode(' AND ', $conds), $p];
}

/** Side effects of admin edits on a ticket: SLA clock, events for status/assignee/priority. */
function ticket_before_update(array $data, array $existing): array
{
    $who = current_user()['name'] ?? 'Equipe';
    if (isset($data['status']) && $data['status'] !== $existing['status']) {
        $data = ticket_status_patch($existing, $data['status']) + $data;
        ticket_event($existing, 'Status alterado: ' . status_label('ticket', $existing['status']) . ' → ' . status_label('ticket', $data['status']), $who);
        if ($data['status'] === 'resolved') register_shutdown_function(fn() => function_exists('mail_event_ticket_resolved') && mail_event_ticket_resolved(db_find('tickets', (int)$existing['id'])));
    }
    if (array_key_exists('assigned_to', $data) && (int)$data['assigned_to'] !== (int)$existing['assigned_to']) {
        $name = $data['assigned_to'] ? db_value('SELECT name FROM users WHERE id = ?', [$data['assigned_to']]) : null;
        if ($data['assigned_to'] && !$name) json_error('Verifique os campos destacados.', 422, ['fields' => ['assigned_to' => 'Usuário inválido.']]);
        ticket_event($existing, $name ? "Atribuído a $name" : 'Responsável removido', $who);
    }
    if (isset($data['priority']) && $data['priority'] !== $existing['priority']) {
        $data['sla_due_at'] = ticket_sla_for_priority($existing, $data['priority'], $data['sla_due_at'] ?? null);
        ticket_event($existing, 'Prioridade: ' . status_label('priority', $existing['priority']) . ' → ' . status_label('priority', $data['priority']), $who);
    }
    return $data;
}

function new_ticket_protocol(): string
{
    do {
        $protocol = 'IC' . date('ymd') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    } while (db_value('SELECT id FROM tickets WHERE protocol = ?', [$protocol]));
    return $protocol;
}

function ticket_sla_due(string $priority): string
{
    return date('Y-m-d H:i:s', strtotime('+' . ticket_sla_hours($priority) . ' hours'));
}

/**
 * Allow a safe subset of HTML for blog posts (admin-authored content).
 */
function sanitize_html(string $html): string
{
    $allowed = '<p><br><h2><h3><h4><ul><ol><li><strong><em><b><i><a><blockquote><code><pre><img><hr><table><thead><tbody><tr><th><td>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/(href|src)\s*=\s*(["\']?)\s*(javascript|data|vbscript):[^"\'>\s]*\2/i', '$1="#"', $html);
    $html = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html);
    return $html;
}
