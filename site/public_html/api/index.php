<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/bootstrap.php';
require INC_PATH . '/content.php';
require INC_PATH . '/api_lib.php';
require INC_PATH . '/resources.php';
require INC_PATH . '/finance.php';
require INC_PATH . '/seed.php';
require INC_PATH . '/help.php';
require_once INC_PATH . '/schema.php';
require INC_PATH . '/nfse.php';
require INC_PATH . '/ai.php';
require INC_PATH . '/mail.php';
require INC_PATH . '/account.php';
require INC_PATH . '/reports.php';
require INC_PATH . '/presentations.php';
require INC_PATH . '/pricing.php';
require INC_PATH . '/fiscalhub.php';

if (!is_installed()) json_error('Sistema não instalado. Acesse /install.php.', 503);


$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = '/' . trim(preg_replace('#^.*?/api#', '', $uri), '/');

set_exception_handler(function (Throwable $e) {
    if ($e instanceof NfseException) {
        json_error($e->getMessage(), 422, ['details' => $e->details]);
    }
    if ($e instanceof AppException || $e instanceof AsaasException) {
        json_error($e->getMessage(), $e instanceof AsaasException && $e->getCode() >= 400 ? 502 : 422);
    }
    log_line('api', 'unhandled', ['error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
    json_error(config('debug') ? $e->getMessage() : 'Erro interno. Tente novamente em instantes.', 500);
});

$routes = [];
function route(string $method, string $pattern, callable $handler): void
{
    global $routes;
    $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
    $routes[] = [$method, $regex, $handler];
}

/** Admin mutation guard: session + permission + CSRF. */
function guard(string $area): array
{
    $user = require_user($area);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_error('Token de segurança inválido. Recarregue a página.', 419);
    }
    return $user;
}

function public_guard(string $bucket, int $max = 8, int $window = 600): array
{
    $in = input();
    if (!empty($in['website'])) json_out(['ok' => true]); // honeypot: silently accept bots
    if (!throttle($bucket, $max, $window)) json_error('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429);
    return $in;
}

/* ================================================================ PUBLIC */

route('GET', '/health', fn() => json_out(['ok' => true, 'time' => now()]));

route('POST', '/public/contact', function () {
    $in = public_guard('contact');
    $data = validate_fields([
        'name' => ['type' => 'string', 'required' => true, 'max' => 160],
        'email' => ['type' => 'email', 'required' => true],
        'phone' => ['type' => 'string', 'max' => 30],
        'company' => ['type' => 'string', 'max' => 160],
        'subject' => ['type' => 'string', 'max' => 160],
        'message' => ['type' => 'text', 'required' => true],
        'source' => ['type' => 'enum', 'values' => ['contact', 'sys-demo', 'chat'], 'default' => 'contact'],
    ], $in);
    if (empty($in['consent'])) json_error('Verifique os campos destacados.', 422, ['fields' => ['consent' => 'É necessário concordar com a política de privacidade.']]);
    $data['message'] = mb_substr($data['message'], 0, 5000);
    $data['created_at'] = now();
    $data['status'] = 'new';
    db_insert('leads', $data);
    mail_event_contact($data);
    json_out(['ok' => true, 'message' => 'Mensagem recebida! Retornaremos em até 1 dia útil.']);
});

route('POST', '/public/diagnostic', function () {
    $in = public_guard('diagnostic');
    $data = validate_fields([
        'name' => ['type' => 'string', 'required' => true, 'max' => 160],
        'email' => ['type' => 'email', 'required' => true],
        'phone' => ['type' => 'string', 'max' => 30],
        'company' => ['type' => 'string', 'max' => 160],
    ], $in);
    $answers = is_array($in['answers'] ?? null) ? array_slice($in['answers'], 0, 20) : [];
    $score = max(0, min(100, (int)($in['score'] ?? 0)));
    db_insert('leads', $data + [
        'subject' => "Diagnóstico online (maturidade $score%)",
        'message' => 'Lead gerado pelo diagnóstico interativo do site.',
        'source' => 'diagnostic', 'payload' => json_encode(['score' => $score, 'answers' => $answers], JSON_UNESCAPED_UNICODE),
        'status' => 'new', 'created_at' => now(),
    ]);
    $aiReport = trim(mb_substr((string)($in['ai_report'] ?? ''), 0, 3000)) ?: null;
    mail_event_diagnostic($data, $score, $answers, $aiReport);
    json_out(['ok' => true]);
});

route('POST', '/public/newsletter', function () {
    $in = public_guard('newsletter', 5);
    $data = validate_fields(['email' => ['type' => 'email', 'required' => true]], $in);
    if (!db_value('SELECT id FROM newsletter WHERE email = ?', [$data['email']])) {
        db_insert('newsletter', ['email' => $data['email'], 'created_at' => now()]);
        mail_event_newsletter($data['email']);
    }
    json_out(['ok' => true, 'message' => 'Inscrição confirmada!']);
});

route('GET', '/public/slots', function () {
    $date = (string)($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_error('Data inválida.');
    json_out(['date' => $date, 'slots' => available_slots($date)]);
});

route('POST', '/public/appointments', function () {
    $in = public_guard('appointment', 5);
    $data = validate_fields([
        'name' => ['type' => 'string', 'required' => true, 'max' => 160],
        'email' => ['type' => 'email', 'required' => true],
        'phone' => ['type' => 'string', 'required' => true, 'max' => 30],
        'company' => ['type' => 'string', 'max' => 160],
        'topic' => ['type' => 'string', 'max' => 160],
        'meeting_type' => ['type' => 'enum', 'values' => ['online', 'presencial', 'telefone'], 'default' => 'online'],
        'date' => ['type' => 'date', 'required' => true],
        'time' => ['type' => 'string', 'required' => true, 'max' => 5],
        'notes' => ['type' => 'text'],
    ], $in);
    $free = array_column(array_filter(available_slots($data['date']), fn($s) => $s['available']), 'time');
    if (!in_array($data['time'], $free, true)) json_error('Este horário acabou de ser reservado. Escolha outro.', 409);
    $scheduled = $data['date'] . ' ' . $data['time'] . ':00';
    unset($data['date'], $data['time']);
    $apptId = db_insert('appointments', $data + ['scheduled_at' => $scheduled, 'status' => 'scheduled', 'created_at' => now()]);
    mail_event_appointment(db_find('appointments', $apptId));
    json_out(['ok' => true, 'scheduled_at' => $scheduled]);
});

route('POST', '/public/tickets', function () {
    $in = public_guard('ticket', 5);
    $data = validate_fields([
        'name' => ['type' => 'string', 'required' => true, 'max' => 160],
        'email' => ['type' => 'email', 'required' => true],
        'phone' => ['type' => 'string', 'max' => 30],
        'subject' => ['type' => 'string', 'required' => true, 'max' => 200],
        'category' => ['type' => 'enum', 'values' => array_keys(ticket_categories()), 'default' => 'duvida'],
        'priority' => ['type' => 'enum', 'values' => ['low', 'normal', 'high', 'urgent'], 'default' => 'normal'],
        'message' => ['type' => 'text', 'required' => true],
    ], $in);
    $message = mb_substr($data['message'], 0, 8000);
    unset($data['message']);
    $customerId = db_value('SELECT id FROM customers WHERE email = ?', [$data['email']]);
    $protocol = new_ticket_protocol();
    $id = db_insert('tickets', $data + [
        'protocol' => $protocol, 'customer_id' => $customerId ?: null, 'status' => 'open', 'source' => 'site',
        'sla_due_at' => ticket_sla_due($data['priority']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    ticket_add_message(db_find('tickets', $id), 'customer', $data['name'], $message);
    mail_event_ticket_opened(db_find('tickets', $id), $message);
    json_out(['ok' => true, 'protocol' => $protocol]);
});

route('GET', '/public/tickets/{protocol}', function ($p) {
    if (!throttle('ticket-lookup', 30, 600)) json_error('Muitas consultas. Aguarde alguns minutos.', 429);
    $ticket = db_one('SELECT id, protocol, subject, category, priority, status, created_at, updated_at, sla_due_at, satisfaction FROM tickets WHERE protocol = ? AND email = ?',
        [strtoupper($p['protocol']), mb_strtolower(trim((string)($_GET['email'] ?? '')))]);
    if (!$ticket) json_error('Chamado não encontrado. Confira o protocolo e o e-mail informado na abertura.', 404);
    $ticket['status_label'] = status_label('ticket', $ticket['status']);
    $ticket['messages'] = db_all("SELECT author_type, author_name, body, created_at FROM ticket_messages WHERE ticket_id = ? AND internal = 0 AND kind = 'message' ORDER BY id", [$ticket['id']]);
    unset($ticket['id']);
    json_out($ticket);
});

route('POST', '/public/tickets/{protocol}/reply', function ($p) {
    $in = public_guard('ticket-reply', 10);
    $ticket = db_one('SELECT * FROM tickets WHERE protocol = ? AND email = ?', [strtoupper($p['protocol']), mb_strtolower(trim((string)($in['email'] ?? '')))]);
    if (!$ticket) json_error('Chamado não encontrado.', 404);
    if ($ticket['status'] === 'closed') json_error('Este chamado está encerrado. Abra um novo chamado.', 409);
    $body = trim((string)($in['message'] ?? ''));
    if ($body === '') json_error('Escreva uma mensagem.', 422);
    ticket_add_message($ticket, 'customer', $ticket['name'], $body);
    if ($ticket['status'] !== 'open') ticket_change_status($ticket, 'open', $ticket['name'], false);
    db_update('tickets', (int)$ticket['id'], ['updated_at' => now()]);
    mail_event_ticket_customer_reply($ticket, mb_substr($body, 0, 8000));
    json_out(['ok' => true]);
});

route('POST', '/public/tickets/{protocol}/rate', function ($p) {
    $in = public_guard('ticket-rate', 10);
    $ticket = db_one('SELECT * FROM tickets WHERE protocol = ? AND email = ?', [strtoupper($p['protocol']), mb_strtolower(trim((string)($in['email'] ?? '')))]);
    if (!$ticket) json_error('Chamado não encontrado.', 404);
    ticket_rate($ticket, (int)($in['score'] ?? 0), (string)($in['comment'] ?? ''));
    json_out(['ok' => true]);
});

route('GET', '/public/help', function () {
    $q = trim((string)($_GET['q'] ?? ''));
    $cat = (string)($_GET['category'] ?? '');
    json_out(['data' => help_search($q, $cat, 20)]);
});

route('POST', '/public/help/{id}/vote', function ($p) {
    public_guard('help-vote', 20);
    if (!empty(input()['helpful'])) db_exec('UPDATE help_articles SET helpful = helpful + 1 WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

route('POST', '/public/help/{id}/view', function ($p) {
    if (throttle('help-view', 60, 600)) db_exec('UPDATE help_articles SET views = views + 1 WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

route('POST', '/public/chat', function () {
    if (!chat_enabled()) json_error('Assistente desativado.', 404);
    $in = public_guard('chat', 30, 600);
    $message = mb_substr(trim((string)($in['message'] ?? '')), 0, 500);
    if ($message === '') json_error('Escreva sua mensagem.', 422);
    if (ai_feature('chat')) {
        try {
            json_out(ai_site_chat($message, is_array($in['history'] ?? null) ? $in['history'] : []));
        } catch (Throwable $e) {
            // fall back to the rule-based FAQ bot below
        }
    }
    json_out(chatbot_reply($message));
});

route('POST', '/public/diagnostic-ai', function () {
    if (!ai_feature('diagnostic')) json_error('Recurso indisponível.', 404);
    $in = public_guard('diagnostic-ai', 4, 600);
    $answers = is_array($in['answers'] ?? null) ? $in['answers'] : [];
    json_out(['report' => ai_diagnostic_report(max(0, min(100, (int)($in['score'] ?? 0))), $answers, mb_substr((string)($in['company'] ?? ''), 0, 120))]);
});

route('POST', '/webhooks/asaas', function () {
    $token = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';
    $expected = (string)setting('asaas_webhook_token', '');
    if ($expected === '' || !hash_equals($expected, $token)) json_error('unauthorized', 401);
    $event = input();
    log_line('asaas', 'webhook', ['event' => $event['event'] ?? null, 'payment' => $event['payment']['id'] ?? null]);
    json_out(handle_asaas_webhook($event));
});

/* ================================================================== AUTH */

route('POST', '/auth/login', function () {
    $in = input();
    if (!throttle('login', 8, 900)) json_error('Muitas tentativas de login. Aguarde 15 minutos.', 429);
    $user = db_one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim((string)($in['email'] ?? '')))]);
    if (!$user || !password_verify((string)($in['password'] ?? ''), $user['password_hash'])) {
        json_error('E-mail ou senha incorretos.', 401);
    }
    if (!(int)$user['active']) json_error('Usuário desativado. Fale com o administrador.', 403);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_update('users', (int)$user['id'], ['password_hash' => password_hash((string)$in['password'], PASSWORD_DEFAULT)]);
    }
    login_staff($user, !empty($in['remember']));
    json_out(['user' => current_user(), 'csrf' => csrf_token(), 'permissions' => ROLES[$user['role']]]);
});

route('GET', '/auth/options', function () {
    json_out(['google' => google_enabled(), 'signup' => signup_enabled()]);
});

route('POST', '/auth/forgot', function () {
    $in = public_guard('forgot', 5, 900);
    request_password_reset((string)($in['email'] ?? ''));
    json_out(['ok' => true, 'message' => 'Se este e-mail tiver acesso, você receberá um link para criar uma nova senha em instantes.']);
});

route('POST', '/auth/logout', function () {
    logout_everything();
    json_out(['ok' => true]);
});

route('GET', '/auth/me', function () {
    $user = current_user();
    if (!$user) json_error('Não autenticado.', 401);
    json_out(['user' => $user, 'csrf' => csrf_token(), 'permissions' => ROLES[$user['role']],
        'asaas' => ['configured' => AsaasClient::isConfigured(), 'environment' => setting('asaas_environment', 'sandbox')],
        'ai' => ['configured' => ai_configured(), 'admin' => ai_feature('admin')],
        'nfse' => ['environment' => setting('nfse_environment', 'homologation'), 'certificate' => (bool)setting('nfse_cert_pfx')]]);
});

route('POST', '/auth/password', function () {
    $user = guard('dashboard');
    $in = input();
    $row = db_find('users', (int)$user['id']);
    if (!password_verify((string)($in['current'] ?? ''), $row['password_hash'])) json_error('Senha atual incorreta.', 422);
    if (mb_strlen((string)($in['new'] ?? '')) < 8) json_error('A nova senha precisa ter pelo menos 8 caracteres.', 422);
    db_update('users', (int)$user['id'], ['password_hash' => password_hash((string)$in['new'], PASSWORD_DEFAULT)]);
    audit('password_change', 'user', $user['id']);
    json_out(['ok' => true]);
});

/* ============================================================= DASHBOARD */

route('GET', '/dashboard', function () {
    guard('dashboard');
    $t = today();
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $pnl = pnl($monthStart, $monthEnd);
    json_out([
        'kpis' => [
            'customers_active' => (int)db_value("SELECT COUNT(*) FROM customers WHERE status = 'active'"),
            'projects_active' => (int)db_value('SELECT COUNT(*) FROM projects WHERE status IN ' . sql_in(PROJECT_RUNNING)),
            'projects_late' => (int)db_value('SELECT COUNT(*) FROM projects WHERE status IN ' . sql_in(PROJECT_RUNNING) . ' AND due_date < ?', [$t]),
            'approvals_pending' => (int)db_value("SELECT COUNT(*) FROM project_stages WHERE status = 'review' AND (client_approval IS NULL OR client_approval = 'pending')"),
            'leads_new' => (int)db_value("SELECT COUNT(*) FROM leads WHERE status = 'new'"),
            'leads_open_value' => (float)db_value("SELECT COALESCE(SUM(estimated_value),0) FROM leads WHERE status NOT IN ('won','lost')"),
            'tickets_open' => (int)db_value('SELECT COUNT(*) FROM tickets WHERE status IN ' . sql_in(TICKET_OPEN)),
            'tickets_mine' => (int)db_value('SELECT COUNT(*) FROM tickets WHERE status IN ' . sql_in(TICKET_OPEN) . ' AND assigned_to = ?', [(int)current_user()['id']]),
            'tickets_unassigned' => (int)db_value('SELECT COUNT(*) FROM tickets WHERE status IN ' . sql_in(TICKET_OPEN) . ' AND assigned_to IS NULL'),
            'tickets_sla_breached' => (int)db_value("SELECT COUNT(*) FROM tickets WHERE status IN ('open','in_progress') AND sla_paused_at IS NULL AND sla_due_at < ?", [now()]),
            'csat_avg' => (float)db_value('SELECT COALESCE(AVG(satisfaction),0) FROM tickets WHERE satisfaction IS NOT NULL AND updated_at >= ?', [date('Y-m-d', strtotime('-90 days'))]),
            'followups_due' => (int)db_value('SELECT COUNT(*) FROM activities WHERE done = 0 AND due_at IS NOT NULL AND due_at <= ?', [date('Y-m-d 23:59:59')]),
            'appointments_upcoming' => (int)db_value("SELECT COUNT(*) FROM appointments WHERE status IN ('scheduled','confirmed','rescheduled') AND scheduled_at >= ?", [now()]),
            'revenue_month' => $pnl['groups']['revenue'] + $pnl['groups']['other_income'],
            'goal_month' => (float)setting('goal_revenue_month', '0'),
            'mrr' => contracts_mrr(),
            'contracts_active' => (int)db_value("SELECT COUNT(*) FROM contracts WHERE status = 'active'"),
            'quotes_open' => (int)db_value("SELECT COUNT(*) FROM quotes WHERE status IN ('draft','sent')"),
            'presentations_views' => (int)db_value('SELECT COALESCE(SUM(views),0) FROM presentations WHERE last_viewed_at >= ?', [date('Y-m-d', strtotime('-30 days'))]),
            'profit_month' => $pnl['net_profit'],
            'receivable_open' => (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='receivable' AND status='open'"),
            'receivable_overdue' => (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='receivable' AND status='open' AND due_date < ?", [$t]),
            'payable_week' => (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE entry_type='payable' AND status='open' AND due_date <= ?", [date('Y-m-d', strtotime('+7 days'))]),
            'cash_balance' => cash_realized_until(date('Y-m-d', strtotime('+1 day'))),
            'unreconciled' => (int)db_value('SELECT COUNT(*) FROM bank_transactions WHERE reconciled = 0 AND ignored = 0'),
        ],
        'monthly' => pnl_monthly(6),
        'upcoming' => db_all("SELECT e.id, e.entry_type, e.description, e.amount, e.due_date, cu.name AS customer_name FROM financial_entries e LEFT JOIN customers cu ON cu.id = e.customer_id WHERE e.status = 'open' AND e.due_date <= ? ORDER BY e.due_date LIMIT 8", [date('Y-m-d', strtotime('+15 days'))]),
        'appointments' => db_all("SELECT id, name, company, topic, scheduled_at, meeting_type FROM appointments WHERE status IN ('scheduled','confirmed','rescheduled') AND scheduled_at >= ? ORDER BY scheduled_at LIMIT 5", [now()]),
        'tickets' => db_all("SELECT t.id, t.protocol, t.subject, t.priority, t.status, t.sla_due_at, t.sla_paused_at, u.name AS assignee_name FROM tickets t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.status IN ('open','in_progress') ORDER BY t.sla_due_at LIMIT 6"),
        'projects' => db_all("SELECT p.id, p.name, p.due_date, p.status, c.name AS customer_name, (SELECT name FROM project_stages s WHERE s.project_id = p.id AND s.status IN " . sql_in(STAGE_CURRENT) . " ORDER BY position LIMIT 1) AS current_stage FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.status IN " . sql_in(PROJECT_RUNNING) . " ORDER BY p.due_date IS NULL, p.due_date LIMIT 6"),
        'followups' => db_all('SELECT a.*, CASE a.entity WHEN \'customer\' THEN (SELECT name FROM customers WHERE id = a.entity_id) WHEN \'lead\' THEN (SELECT name FROM leads WHERE id = a.entity_id) WHEN \'project\' THEN (SELECT name FROM projects WHERE id = a.entity_id) WHEN \'ticket\' THEN (SELECT subject FROM tickets WHERE id = a.entity_id) END AS entity_name FROM activities a WHERE a.done = 0 AND a.due_at IS NOT NULL AND a.due_at <= ? ORDER BY a.due_at LIMIT 8', [date('Y-m-d 23:59:59', strtotime('+3 days'))]),
    ]);
});

/* ============================================================= PROJECTS */

route('GET', '/projects/{id}/board', function ($p) {
    guard('projects');
    $id = (int)$p['id'];
    $project = crud_get(resources()['projects'], $id);
    $stages = db_all('SELECT * FROM project_stages WHERE project_id = ? ORDER BY position', [$id]);
    $tasks = db_all('SELECT * FROM project_tasks WHERE project_id = ? ORDER BY done, position, id', [$id]);
    foreach ($stages as &$s) {
        $s['tasks'] = array_values(array_filter($tasks, fn($t) => (int)$t['stage_id'] === (int)$s['id']));
    }
    unset($s);
    $finance = db_one("SELECT COALESCE(SUM(CASE WHEN entry_type='receivable' AND status='paid' THEN paid_amount END),0) AS received,
        COALESCE(SUM(CASE WHEN entry_type='receivable' AND status='open' THEN amount END),0) AS to_receive,
        COALESCE(SUM(CASE WHEN entry_type='payable' AND status='paid' THEN paid_amount END),0) AS spent
        FROM financial_entries WHERE project_id = ?", [$id]);
    json_out(['project' => $project, 'stages' => $stages, 'finance' => $finance,
        'tickets' => db_all('SELECT id, protocol, subject, status, priority, updated_at FROM tickets WHERE project_id = ? ORDER BY updated_at DESC LIMIT 20', [$id])]);
});

route('POST', '/projects/{id}/stages', function ($p) {
    guard('projects');
    $name = trim((string)(input()['name'] ?? ''));
    if ($name === '') json_error('Informe o nome da etapa.', 422);
    $pos = (int)db_value('SELECT COALESCE(MAX(position), -1) + 1 FROM project_stages WHERE project_id = ?', [(int)$p['id']]);
    $id = db_insert('project_stages', ['project_id' => (int)$p['id'], 'name' => mb_substr($name, 0, 120), 'position' => $pos, 'status' => 'pending']);
    json_out(db_find('project_stages', $id), 201);
});

route('PUT', '/stages/{id}', function ($p) {
    guard('projects');
    $stage = db_find('project_stages', (int)$p['id']);
    if (!$stage) json_error('Etapa não encontrada.', 404);
    $data = validate_fields([
        'name' => ['type' => 'string', 'max' => 120],
        'status' => ['type' => 'enum', 'values' => status_values('stage')],
        'start_date' => ['type' => 'date'],
        'due_date' => ['type' => 'date'],
        'notes' => ['type' => 'text'],
    ], input(), true);
    if (isset($data['status']) && $data['status'] !== $stage['status']) {
        if (in_array($data['status'], STAGE_CURRENT, true)) db_exec("UPDATE projects SET status = 'active', start_date = COALESCE(start_date, ?) WHERE id = ? AND status IN ('proposal','approved')", [today(), $stage['project_id']]);
        $data['completed_at'] = in_array($data['status'], STAGE_FINISHED, true) ? ($stage['completed_at'] ?: now()) : null;
        if ($data['status'] === 'review' && !empty(input()['request_approval'])) { $data['client_approval'] = 'pending'; $data['client_feedback'] = null; }
        activity_add('project', (int)$stage['project_id'], 'event', "Etapa \"{$stage['name']}\": " . status_label('stage', $stage['status']) . ' → ' . status_label('stage', $data['status']));
    }
    db_update('project_stages', (int)$stage['id'], $data);
    audit('update', 'project_stage', $stage['id'], $data);
    if (($data['client_approval'] ?? null) === 'pending') mail_event_stage_approval_requested(db_find('project_stages', (int)$stage['id']));
    json_out(db_find('project_stages', (int)$stage['id']));
});

route('DELETE', '/stages/{id}', function ($p) {
    guard('projects');
    db_exec('UPDATE project_tasks SET stage_id = NULL WHERE stage_id = ?', [(int)$p['id']]);
    db_exec('DELETE FROM project_stages WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

route('POST', '/projects/{id}/advance', function ($p) {
    guard('projects');
    $id = (int)$p['id'];
    $stages = db_all('SELECT * FROM project_stages WHERE project_id = ? ORDER BY position', [$id]);
    $currentIdx = null;
    foreach ($stages as $i => $s) if (in_array($s['status'], STAGE_CURRENT, true)) { $currentIdx = $i; break; }
    if ($currentIdx === null) {
        foreach ($stages as $i => $s) if ($s['status'] === 'pending') { $currentIdx = $i - 1; break; }
    }
    db_transaction(function () use ($stages, $currentIdx, $id) {
        if ($currentIdx !== null && $currentIdx >= 0) db_update('project_stages', (int)$stages[$currentIdx]['id'], ['status' => 'done', 'completed_at' => now()]);
        $next = null;
        for ($i = ($currentIdx ?? -1) + 1; $i < count($stages); $i++) if ($stages[$i]['status'] !== 'skipped') { $next = $stages[$i]; break; }
        if ($next) {
            db_update('project_stages', (int)$next['id'], ['status' => 'in_progress', 'start_date' => $next['start_date'] ?: today()]);
            db_exec("UPDATE projects SET status = 'active', start_date = COALESCE(start_date, ?) WHERE id = ? AND status IN ('proposal','approved')", [today(), $id]);
        } else db_update('projects', $id, ['status' => 'done', 'updated_at' => now()]);
    });
    audit('advance', 'project', $id);
    json_out(['ok' => true]);
});

route('POST', '/projects/{id}/move-stage', function ($p) {
    guard('projects');
    $id = (int)$p['id'];
    $target = trim((string)(input()['stage'] ?? ''));
    $stages = db_all('SELECT * FROM project_stages WHERE project_id = ? ORDER BY position', [$id]);
    if ($target === '__done') {
        db_transaction(function () use ($stages, $id) {
            foreach ($stages as $s) if ($s['status'] !== 'done') db_update('project_stages', (int)$s['id'], ['status' => 'done', 'completed_at' => now()]);
            db_update('projects', $id, ['status' => 'done', 'updated_at' => now()]);
        });
        json_out(['ok' => true]);
    }
    $idx = null;
    foreach ($stages as $i => $s) if (mb_strtolower($s['name']) === mb_strtolower($target)) { $idx = $i; break; }
    if ($idx === null) json_error('Etapa não encontrada neste projeto.', 422);
    db_transaction(function () use ($stages, $idx, $id) {
        foreach ($stages as $i => $s) {
            $status = $i < $idx ? ($s['status'] === 'skipped' ? 'skipped' : 'done') : ($i === $idx ? (in_array($s['status'], STAGE_CURRENT, true) ? $s['status'] : 'in_progress') : ($s['status'] === 'skipped' ? 'skipped' : 'pending'));
            if ($status === $s['status']) continue;
            db_update('project_stages', (int)$s['id'], [
                'status' => $status,
                'completed_at' => $status === 'done' ? ($s['completed_at'] ?: now()) : null,
                'start_date' => $status === 'in_progress' ? ($s['start_date'] ?: today()) : $s['start_date'],
            ]);
        }
        $cur = db_value('SELECT status FROM projects WHERE id = ?', [$id]);
        if (!in_array($cur, PROJECT_RUNNING, true)) db_update('projects', $id, ['status' => 'active']);
        db_update('projects', $id, ['updated_at' => now()]);
    });
    audit('move_stage', 'project', $id, ['stage' => $stages[$idx]['name']]);
    json_out(['ok' => true]);
});

route('POST', '/projects/{id}/tasks', function ($p) {
    guard('projects');
    $data = validate_fields([
        'title' => ['type' => 'string', 'required' => true, 'max' => 200],
        'stage_id' => ['type' => 'int'],
        'assignee' => ['type' => 'string', 'max' => 120],
        'due_date' => ['type' => 'date'],
        'client_visible' => ['type' => 'bool', 'default' => 1],
    ], input());
    $data['project_id'] = (int)$p['id'];
    $data['position'] = (int)db_value('SELECT COUNT(*) FROM project_tasks WHERE project_id = ?', [(int)$p['id']]);
    $data['created_at'] = now();
    $id = db_insert('project_tasks', $data);
    json_out(db_find('project_tasks', $id), 201);
});

route('PUT', '/tasks/{id}', function ($p) {
    guard('projects');
    $data = validate_fields([
        'title' => ['type' => 'string', 'max' => 200],
        'stage_id' => ['type' => 'int'],
        'assignee' => ['type' => 'string', 'max' => 120],
        'due_date' => ['type' => 'date'],
        'done' => ['type' => 'bool'],
        'client_visible' => ['type' => 'bool'],
    ], input(), true);
    db_update('project_tasks', (int)$p['id'], $data);
    json_out(db_find('project_tasks', (int)$p['id']));
});

route('DELETE', '/tasks/{id}', function ($p) {
    guard('projects');
    db_exec('DELETE FROM project_tasks WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

/* =============================================================== TICKETS */

route('GET', '/tickets/{id}/messages', function ($p) {
    guard('tickets');
    $id = (int)$p['id'];
    $files = [];
    foreach (attachments_for('ticket', $id) as $a) $files[(int)$a['message_id']][] = $a;
    $msgs = db_all('SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id', [$id]);
    foreach ($msgs as &$m) $m['attachments'] = $files[(int)$m['id']] ?? [];
    unset($m);
    json_out(['data' => $msgs, 'loose_attachments' => $files[0] ?? []]);
});

route('POST', '/tickets/bulk', function () {
    $user = guard('tickets');
    $in = input();
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
    if (!$ids) json_error('Selecione ao menos um chamado.', 422);
    $action = (string)($in['action'] ?? '');
    $value = $in['value'] ?? null;
    $n = 0;
    foreach ($ids as $id) {
        $t = db_find('tickets', $id);
        if (!$t) continue;
        switch ($action) {
            case 'status': ticket_change_status($t, (string)$value, $user['name']); break;
            case 'assign':
                $uid = $value ? (int)$value : null;
                if ($uid && !db_value('SELECT id FROM users WHERE id = ? AND active = 1', [$uid])) json_error('Usuário inválido.', 422);
                if ((int)$t['assigned_to'] === (int)$uid) break;
                db_update('tickets', $id, ['assigned_to' => $uid, 'updated_at' => now()]);
                ticket_event($t, $uid ? 'Atribuído a ' . db_value('SELECT name FROM users WHERE id = ?', [$uid]) : 'Responsável removido', $user['name']);
                break;
            case 'priority':
                if (!in_array($value, ['low', 'normal', 'high', 'urgent'], true)) json_error('Prioridade inválida.', 422);
                db_update('tickets', $id, ['priority' => $value, 'sla_due_at' => ticket_sla_for_priority($t, $value), 'updated_at' => now()]);
                break;
            case 'tag':
                db_update('tickets', $id, ['tags' => tags_normalize(($t['tags'] ? $t['tags'] . ',' : '') . (string)$value), 'updated_at' => now()]);
                break;
            case 'delete':
                if (!can('users', $user)) json_error('Somente administradores podem excluir chamados.', 403);
                crud_delete(resources()['tickets'], $id);
                break;
            default: json_error('Ação inválida.', 422);
        }
        $n++;
    }
    audit('bulk_' . $action, 'ticket', null, ['ids' => $ids, 'value' => $value]);
    json_out(['ok' => true, 'count' => $n]);
});

route('GET', '/tickets-stats', function () {
    $user = guard('tickets');
    $open = sql_in(TICKET_OPEN);
    json_out([
        'open' => (int)db_value("SELECT COUNT(*) FROM tickets WHERE status IN $open"),
        'mine' => (int)db_value("SELECT COUNT(*) FROM tickets WHERE status IN $open AND assigned_to = ?", [$user['id']]),
        'unassigned' => (int)db_value("SELECT COUNT(*) FROM tickets WHERE status IN $open AND assigned_to IS NULL"),
        'breached' => (int)db_value("SELECT COUNT(*) FROM tickets WHERE status IN ('open','in_progress') AND sla_paused_at IS NULL AND sla_due_at < ?", [now()]),
        'waiting' => (int)db_value('SELECT COUNT(*) FROM tickets WHERE status IN ' . sql_in(TICKET_PAUSED)),
        'customer_replied' => (int)db_value("SELECT COUNT(*) FROM tickets t WHERE t.status IN ('open','in_progress') AND (SELECT m.author_type FROM ticket_messages m WHERE m.ticket_id = t.id AND m.kind = 'message' AND m.internal = 0 ORDER BY m.id DESC LIMIT 1) = 'customer'"),
        'done' => (int)db_value("SELECT COUNT(*) FROM tickets WHERE status IN ('resolved','closed')"),
        'csat_avg' => round((float)db_value('SELECT COALESCE(AVG(satisfaction),0) FROM tickets WHERE satisfaction IS NOT NULL'), 1),
        'csat_count' => (int)db_value('SELECT COUNT(*) FROM tickets WHERE satisfaction IS NOT NULL'),
        'first_response_avg_h' => round((float)db_value(db_driver() === 'sqlite'
            ? "SELECT COALESCE(AVG((julianday(first_response_at) - julianday(created_at)) * 24),0) FROM tickets WHERE first_response_at IS NOT NULL AND created_at >= ?"
            : 'SELECT COALESCE(AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) / 60,0) FROM tickets WHERE first_response_at IS NOT NULL AND created_at >= ?', [date('Y-m-d', strtotime('-30 days'))]), 1),
    ]);
});

route('POST', '/tickets/{id}/messages', function ($p) {
    $user = guard('tickets');
    $ticket = db_find('tickets', (int)$p['id']);
    if (!$ticket) json_error('Chamado não encontrado.', 404);
    $in = input();
    $body = trim((string)($in['body'] ?? ''));
    $internal = filter_var($in['internal'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $hasFiles = (bool)uploaded_files('files');
    if ($body === '' && !$hasFiles) json_error('Escreva a resposta ou anexe um arquivo.', 422);
    attachments_validate_all('files');
    $msgId = db_transaction(function () use ($ticket, $user, $body, $internal) {
        return ticket_add_message($ticket, 'staff', $user['name'], $body !== '' ? $body : '(arquivo anexado)', $internal, (int)$user['id']);
    });
    $files = attachments_store_all('files', 'ticket', (int)$ticket['id'], $msgId, !$internal, 'staff', $user['name']);
    $ticket = db_find('tickets', (int)$ticket['id']);
    if (!$ticket['assigned_to'] && !$internal) { db_update('tickets', (int)$ticket['id'], ['assigned_to' => $user['id']]); ticket_event($ticket, 'Atribuído a ' . $user['name'] . ' (primeira resposta)', $user['name']); }
    $status = (string)($in['status'] ?? '');
    if ($status !== '' && $status !== 'keep' && in_array($status, status_values('ticket'), true)) $ticket = ticket_change_status($ticket, $status, $user['name'], false);
    db_update('tickets', (int)$ticket['id'], ['updated_at' => now()]);
    if (!$internal) {
        $text = $body !== '' ? $body : 'Enviamos ' . count($files) . ' arquivo(s) no seu chamado.';
        $status === 'resolved' ? mail_event_ticket_resolved($ticket, $text, $user['name']) : mail_event_ticket_staff_reply($ticket, $text, $user['name']);
    }
    audit($internal ? 'note' : 'reply', 'ticket', $ticket['id']);
    json_out(['ok' => true, 'attachments' => count($files)]);
});

/* =============================================================== FINANCE */

route('POST', '/entries', function () {
    guard('finance');
    $res = resources()['entries'];
    $repeat = max(1, min(60, (int)(input()['repeat'] ?? 1)));
    $first = crud_create($res);
    if ($repeat > 1) {
        $base = db_find('financial_entries', (int)$first['id']);
        db_update('financial_entries', (int)$base['id'], ['description' => $base['description'] . " (1/$repeat)"]);
        for ($i = 1; $i < $repeat; $i++) {
            $row = $base;
            unset($row['id']);
            $row['description'] = $base['description'] . ' (' . ($i + 1) . "/$repeat)";
            $row['due_date'] = add_months($base['due_date'], $i);
            $row['competence_date'] = $row['due_date'];
            $row['status'] = 'open';
            $row['paid_at'] = $row['paid_amount'] = null;
            db_insert('financial_entries', $row);
        }
    }
    json_out($first, 201);
});

route('POST', '/entries/{id}/pay', function ($p) {
    guard('finance');
    $entry = db_find('financial_entries', (int)$p['id']);
    if (!$entry) json_error('Lançamento não encontrado.', 404);
    $data = validate_fields(['paid_at' => ['type' => 'date'], 'paid_amount' => ['type' => 'decimal'], 'payment_method' => ['type' => 'string', 'max' => 30]], input());
    db_update('financial_entries', (int)$entry['id'], [
        'status' => 'paid', 'paid_at' => $data['paid_at'] ?? today(), 'paid_amount' => $data['paid_amount'] ?? $entry['amount'],
        'payment_method' => $data['payment_method'] ?? $entry['payment_method'], 'updated_at' => now(),
    ]);
    audit('pay', 'financial_entry', $entry['id']);
    json_out(['ok' => true]);
});

route('POST', '/entries/bulk', function () {
    guard('finance');
    $in = input();
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
    if (!$ids) json_error('Selecione ao menos um lançamento.', 422);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    switch ($in['action'] ?? '') {
        case 'pay':
            db_exec("UPDATE financial_entries SET status = 'paid', paid_at = COALESCE(paid_at, ?), paid_amount = COALESCE(paid_amount, amount), updated_at = ? WHERE status = 'open' AND id IN ($ph)", array_merge([today(), now()], $ids));
            break;
        case 'cancel':
            db_exec("UPDATE financial_entries SET status = 'canceled', updated_at = ? WHERE status = 'open' AND id IN ($ph)", array_merge([now()], $ids));
            break;
        case 'delete':
            $res = resources()['entries'];
            foreach ($ids as $id) { $res['before_delete']($id); db_exec('DELETE FROM financial_entries WHERE id = ?', [$id]); }
            break;
        case 'postpone':
            $days = max(-365, min(365, (int)($in['days'] ?? 0)));
            if (!$days) json_error('Informe quantos dias adiar.', 422);
            foreach (db_all("SELECT id, due_date FROM financial_entries WHERE status = 'open' AND id IN ($ph)", $ids) as $r) {
                db_update('financial_entries', (int)$r['id'], ['due_date' => date('Y-m-d', strtotime($r['due_date'] . " $days days")), 'updated_at' => now()]);
            }
            break;
        case 'set_date':
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['value'] ?? ''))) json_error('Data inválida.', 422);
            db_exec("UPDATE financial_entries SET due_date = ?, updated_at = ? WHERE status = 'open' AND id IN ($ph)", array_merge([$in['value'], now()], $ids));
            break;
        case 'category':
            $cat = (int)($in['value'] ?? 0);
            if ($cat && !db_find('categories', $cat)) json_error('Categoria inválida.', 422);
            db_exec("UPDATE financial_entries SET category_id = ?, updated_at = ? WHERE id IN ($ph)", array_merge([$cat ?: null, now()], $ids));
            break;
        case 'project':
            $pid = (int)($in['value'] ?? 0);
            db_exec("UPDATE financial_entries SET project_id = ?, updated_at = ? WHERE id IN ($ph)", array_merge([$pid ?: null, now()], $ids));
            break;
        case 'tag':
            foreach (db_all("SELECT id, tags FROM financial_entries WHERE id IN ($ph)", $ids) as $r) db_update('financial_entries', (int)$r['id'], ['tags' => tags_normalize(($r['tags'] ? $r['tags'] . ',' : '') . (string)($in['value'] ?? ''))]);
            break;
        default:
            json_error('Ação inválida.', 422);
    }
    audit('bulk_' . $in['action'], 'financial_entry', null, $ids);
    json_out(['ok' => true, 'count' => count($ids)]);
});

route('GET', '/finance/summary', function () {
    guard('finance');
    $t = today();
    $sum = fn(string $where, array $params = []) => (float)db_value("SELECT COALESCE(SUM(amount),0) FROM financial_entries WHERE $where", $params);
    json_out([
        'receivable_open' => $sum("entry_type='receivable' AND status='open'"),
        'receivable_overdue' => $sum("entry_type='receivable' AND status='open' AND due_date < ?", [$t]),
        'receivable_today' => $sum("entry_type='receivable' AND status='open' AND due_date = ?", [$t]),
        'payable_open' => $sum("entry_type='payable' AND status='open'"),
        'payable_overdue' => $sum("entry_type='payable' AND status='open' AND due_date < ?", [$t]),
        'payable_today' => $sum("entry_type='payable' AND status='open' AND due_date = ?", [$t]),
        'cash_balance' => cash_realized_until(date('Y-m-d', strtotime('+1 day'))),
        'goal_month' => (float)setting('goal_revenue_month', '0'),
        'revenue_month' => (float)db_value("SELECT COALESCE(SUM(paid_amount),0) FROM financial_entries WHERE entry_type='receivable' AND status='paid' AND paid_at BETWEEN ? AND ?", [date('Y-m-01'), date('Y-m-t')]),
        'expense_month' => (float)db_value("SELECT COALESCE(SUM(paid_amount),0) FROM financial_entries WHERE entry_type='payable' AND status='paid' AND paid_at BETWEEN ? AND ?", [date('Y-m-01'), date('Y-m-t')]),
    ]);
});

route('POST', '/entries/{id}/duplicate', function ($p) {
    $user = guard('finance');
    $e = db_find('financial_entries', (int)$p['id']);
    if (!$e) json_error('Lançamento não encontrado.', 404);
    $months = max(0, min(24, (int)(input()['months'] ?? 1)));
    $row = array_intersect_key($e, array_flip(['entry_type', 'description', 'category_id', 'customer_id', 'project_id', 'supplier', 'amount', 'payment_method', 'notes', 'tags']));
    $row += ['status' => 'open', 'due_date' => add_months($e['due_date'], $months), 'competence_date' => add_months($e['competence_date'] ?: $e['due_date'], $months), 'created_at' => now(), 'updated_at' => now()];
    $id = db_insert('financial_entries', $row);
    audit('duplicate', 'financial_entry', $id, ['from' => $e['id']]);
    json_out(db_find('financial_entries', $id), 201);
});

/* ---------------------------------------------------------------- budgets */
route('GET', '/budgets', function () {
    guard('finance');
    $year = max(2000, min(2100, (int)($_GET['year'] ?? date('Y'))));
    $cats = db_all('SELECT id, name, entry_type, dre_group, color FROM categories ORDER BY entry_type DESC, name');
    $budget = [];
    foreach (db_all('SELECT category_id, month, amount FROM budgets WHERE month LIKE ?', [$year . '-%']) as $b) $budget[$b['category_id']][$b['month']] = (float)$b['amount'];
    $actual = [];
    foreach (db_all("SELECT category_id, SUBSTR(paid_at, 1, 7) AS m, SUM(paid_amount) AS total FROM financial_entries WHERE status = 'paid' AND paid_at BETWEEN ? AND ? AND category_id IS NOT NULL GROUP BY category_id, SUBSTR(paid_at, 1, 7)", ["$year-01-01", "$year-12-31"]) as $a) $actual[$a['category_id']][$a['m']] = (float)$a['total'];
    json_out(['year' => $year, 'categories' => $cats, 'budget' => $budget, 'actual' => $actual,
        'goals' => ['goal_revenue_month' => (float)setting('goal_revenue_month', '0'), 'cost_per_hour' => (float)setting('cost_per_hour', '0'), 'default_hourly_rate' => (float)setting('default_hourly_rate', '0')]]);
});

route('PUT', '/budgets', function () {
    guard('finance');
    $in = input();
    $n = 0;
    db_transaction(function () use ($in, &$n) {
        foreach ((array)($in['cells'] ?? []) as $c) {
            $cat = (int)($c['category_id'] ?? 0);
            $m = (string)($c['month'] ?? '');
            if (!$cat || !preg_match('/^\d{4}-\d{2}$/', $m)) continue;
            $raw = (string)($c['amount'] ?? 0);
            $amount = round((float)(strpos($raw, ',') !== false ? str_replace(['.', ','], ['', '.'], $raw) : $raw), 2);
            db_exec('DELETE FROM budgets WHERE category_id = ? AND month = ?', [$cat, $m]);
            if ($amount > 0) db_insert('budgets', ['category_id' => $cat, 'month' => $m, 'amount' => $amount]);
            $n++;
        }
    });
    audit('update', 'budgets', null, ['cells' => $n]);
    json_out(['ok' => true, 'count' => $n]);
});

route('PUT', '/finance/goals', function () {
    guard('finance');
    $in = input();
    foreach (['goal_revenue_month', 'cost_per_hour', 'default_hourly_rate'] as $k) {
        if (!array_key_exists($k, $in)) continue;
        $v = (string)$in[$k];
        set_setting($k, (string)max(0, round((float)(strpos($v, ',') !== false ? str_replace(['.', ','], ['', '.'], $v) : $v), 2)));
    }
    audit('update', 'finance_goals', null, array_keys($in));
    json_out(['ok' => true, 'goal_revenue_month' => (float)setting('goal_revenue_month', '0'), 'cost_per_hour' => (float)setting('cost_per_hour', '0'), 'default_hourly_rate' => (float)setting('default_hourly_rate', '0')]);
});

/* ---------------------------------------------------------------- pricing */
route('GET', '/pricing/catalog', function () {
    $user = guard('customers');
    json_out(['data' => pricing_catalog(), 'categories' => array_map(fn($c) => ['label' => $c[0], 'hint' => $c[1], 'required' => $c[2]], PRICE_CATEGORIES), 'billing' => PRICE_BILLING,
        'ai' => ai_feature('admin'), 'web_search' => (string)setting('search_api_key', '') !== '', 'factor' => MARKET_FACTOR, 'mrr' => can('finance', $user) ? contracts_mrr() : null]);
});

route('POST', '/pricing/catalog', function () {
    guard('users');
    $in = input();
    $data = pricing_item_fields($in);
    $data['code'] = mb_substr(strtoupper(trim((string)($in['code'] ?? ''))) ?: 'ITEM-' . strtoupper(bin2hex(random_bytes(2))), 0, 40);
    $data += ['position' => (int)db_value('SELECT COALESCE(MAX(position),0)+1 FROM price_items'), 'created_at' => now(), 'updated_at' => now()];
    $id = db_insert('price_items', $data);
    audit('create', 'price_item', $id);
    json_out(db_find('price_items', $id), 201);
});

route('PUT', '/pricing/catalog/{id}', function ($p) {
    guard('users');
    $item = db_find('price_items', (int)$p['id']);
    if (!$item) json_error('Item não encontrado.', 404);
    $data = pricing_item_fields(input() + $item) + ['updated_at' => now()];
    db_update('price_items', (int)$item['id'], $data);
    audit('update', 'price_item', $item['id'], ['price' => $data['price']]);
    json_out(db_find('price_items', (int)$item['id']));
});

route('DELETE', '/pricing/catalog/{id}', function ($p) {
    guard('users');
    db_update('price_items', (int)$p['id'], ['active' => 0, 'updated_at' => now()]);
    json_out(['ok' => true]);
});

route('POST', '/pricing/recalculate', function () {
    guard('users');
    $n = 0;
    foreach (pricing_catalog() as $it) if ((float)$it['market_avg'] > 0) { db_update('price_items', (int)$it['id'], ['price' => pricing_round((float)$it['market_avg'] * MARKET_FACTOR), 'updated_at' => now()]); $n++; }
    audit('recalculate', 'price_items', null, ['count' => $n]);
    json_out(['ok' => true, 'count' => $n]);
});

route('POST', '/pricing/simulate', function () {
    guard('customers');
    $in = input();
    json_out(pricing_calculate((array)($in['items'] ?? []), (float)($in['setup_discount'] ?? 0), (float)($in['monthly_discount'] ?? 0), (int)($in['installments'] ?? 1), (int)($in['contract_months'] ?? 12)));
});

route('POST', '/pricing/ask', function () {
    guard('customers');
    $in = input();
    if (!throttle('pricing-ask', 60, 3600)) json_error('Muitas perguntas seguidas. Aguarde alguns minutos.', 429);
    json_out(pricing_ask((string)($in['question'] ?? ''), (array)($in['history'] ?? [])));
});

route('GET', '/quotes', function () {
    guard('customers');
    $w = [];
    $params = [];
    foreach (['customer_id', 'lead_id', 'status'] as $k) if (!empty($_GET[$k])) { $w[] = "q.$k = ?"; $params[] = $_GET[$k]; }
    json_out(['data' => db_all('SELECT q.id, q.number, q.title, q.status, q.setup_total, q.monthly_total, q.first_year_total, q.valid_until, q.customer_id, q.lead_id, q.contract_id, q.project_id, q.presentation_id, q.created_by, q.created_at, q.updated_at, q.accepted_at, c.name AS customer_name, l.name AS lead_name
        FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id LEFT JOIN leads l ON l.id = q.lead_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY q.updated_at DESC LIMIT 300', $params)]);
});

route('GET', '/quotes/{id}', function ($p) {
    guard('customers');
    json_out(quote_get((int)$p['id']));
});

route('POST', '/quotes', function () {
    $user = guard('customers');
    json_out(quote_save(input(), null, $user), 201);
});

route('PUT', '/quotes/{id}', function ($p) {
    $user = guard('customers');
    $q = db_find('quotes', (int)$p['id']);
    if (!$q) json_error('Orçamento não encontrado.', 404);
    $in = input();
    if (array_keys($in) === ['status']) {
        if (!in_array($in['status'], ['draft', 'sent', 'rejected', 'expired'], true) || $q['status'] === 'accepted') json_error('Situação inválida.', 422);
        db_update('quotes', (int)$q['id'], ['status' => $in['status'], 'updated_at' => now()]);
        json_out(quote_get((int)$q['id']));
    }
    json_out(quote_save($in, $q, $user));
});

route('DELETE', '/quotes/{id}', function ($p) {
    guard('customers');
    $q = db_find('quotes', (int)$p['id']);
    if ($q && $q['status'] === 'accepted') json_error('Orçamento aceito não pode ser excluído (há contrato vinculado).', 409);
    db_exec('DELETE FROM quotes WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

route('POST', '/quotes/{id}/duplicate', function ($p) {
    $user = guard('customers');
    $q = quote_get((int)$p['id']);
    $copy = quote_save(['title' => $q['title'] . ' (nova versão)', 'customer_id' => $q['customer_id'], 'lead_id' => $q['lead_id'], 'items' => array_map(fn($l) => ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'price' => $l['price']], $q['items']),
        'setup_discount' => $q['setup_discount'], 'monthly_discount' => $q['monthly_discount'], 'installments' => $q['installments'], 'contract_months' => $q['contract_months'], 'notes' => $q['notes']], null, $user);
    json_out($copy, 201);
});

route('POST', '/quotes/{id}/accept', function ($p) {
    $user = guard('customers');
    $in = input();
    if (!empty($in['charge_setup']) && !can('charges', $user)) json_error('Você não tem permissão para emitir cobranças.', 403);
    json_out(quote_accept((int)$p['id'], $in, $user));
});

route('POST', '/quotes/{id}/presentation', function ($p) {
    $user = guard('customers');
    $q = quote_get((int)$p['id']);
    $in = input();
    $r = presentation_generate('proposal', ['quote_id' => $q['id'], 'lead_id' => $q['lead_id'], 'customer_id' => $q['customer_id'], 'theme' => $in['theme'] ?? 'dark', 'tone' => $in['tone'] ?? 'consultivo', 'instructions' => $in['instructions'] ?? '', 'use_ai' => $in['use_ai'] ?? true], $user);
    db_update('quotes', (int)$q['id'], ['presentation_id' => $r['id'], 'status' => $q['status'] === 'draft' ? 'sent' : $q['status'], 'updated_at' => now()]);
    json_out($r, 201);
});

route('GET', '/contracts', function () {
    $user = guard('customers');
    $w = [];
    $params = [];
    foreach (['customer_id', 'status'] as $k) if (!empty($_GET[$k])) { $w[] = "k.$k = ?"; $params[] = $_GET[$k]; }
    $rows = db_all('SELECT k.*, c.name AS customer_name FROM contracts k JOIN customers c ON c.id = k.customer_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY k.status = \'active\' DESC, k.created_at DESC LIMIT 300', $params);
    $prov = nfse_config()['provider'];
    foreach ($rows as &$r) {
        $r['items'] = json_decode((string)$r['items'], true) ?: [];
        $r['nfse_service_code'] = $r['service_code'] ? nfse_code_for((string)$r['service_code'], $prov) : '';
    }
    unset($r);
    $mrr = contracts_mrr();
    json_out(['data' => $rows, 'total' => count($rows), 'mrr' => $mrr, 'arr' => $mrr * 12,
        'billing_due' => (int)db_value("SELECT COUNT(*) FROM contracts WHERE status = 'active' AND next_billing_date <= ?", [date('Y-m-d', strtotime('+' . (int)setting('contracts_lead_days', '10') . ' days'))]),
        'lead_days' => (int)setting('contracts_lead_days', '10'), 'auto_billing' => setting('contracts_auto_billing', '0') === '1']);
});

route('PUT', '/contracts/{id}', function ($p) {
    $user = guard('customers');
    $c = db_find('contracts', (int)$p['id']);
    if (!$c) json_error('Contrato não encontrado.', 404);
    $data = validate_fields([
        'title' => ['type' => 'string', 'max' => 200], 'status' => ['type' => 'enum', 'values' => ['active', 'paused', 'canceled', 'ended']],
        'monthly_amount' => ['type' => 'decimal'], 'billing_day' => ['type' => 'int'], 'next_billing_date' => ['type' => 'date'], 'end_date' => ['type' => 'date'],
        'auto_charge' => ['type' => 'bool'], 'billing_type' => ['type' => 'enum', 'values' => ['UNDEFINED', 'BOLETO', 'PIX', 'CREDIT_CARD']], 'notes' => ['type' => 'text'],
    ], input(), true);
    if (isset($data['billing_day'])) $data['billing_day'] = max(1, min(28, $data['billing_day']));
    if (($data['status'] ?? '') === 'canceled' && $c['status'] !== 'canceled') $data['canceled_at'] = now();
    if (isset($data['status']) && $data['status'] !== $c['status']) activity_add('customer', (int)$c['customer_id'], 'event', "Contrato {$c['number']}: " . ['active' => 'ativo', 'paused' => 'pausado', 'canceled' => 'cancelado', 'ended' => 'encerrado'][$data['status']], null, $user);
    db_update('contracts', (int)$c['id'], $data + ['updated_at' => now()]);
    json_out(db_find('contracts', (int)$c['id']));
});

route('POST', '/contracts/bill', function () {
    $user = guard('finance');
    $in = input();
    $r = contracts_bill_due(max(0, min(40, (int)($in['lead_days'] ?? setting('contracts_lead_days', '10')))), !empty($in['contract_id']) ? (int)$in['contract_id'] : null);
    audit('bill', 'contracts', null, $r);
    json_out($r);
});

/* ---------------------------------------------------------------- reports */
route('GET', '/reports', function () {
    $user = guard('dashboard');
    json_out([
        'catalog' => array_values(array_map(fn($r) => ['key' => $r[0], 'title' => $r[1], 'description' => $r[2], 'icon' => $r[3], 'group' => $r[5]], array_filter(report_catalog(), fn($r) => can($r[4], $user)))),
        'saved' => db_all('SELECT id, name, description, config, pinned, created_by, updated_at FROM saved_reports ORDER BY pinned DESC, updated_at DESC'),
        'datasets' => report_datasets_meta($user),
        'ai' => ai_feature('admin'),
    ]);
});

route('GET', '/reports/run/{key}', function ($p) {
    $user = guard('dashboard');
    $cat = array_column(report_catalog(), null, 0);
    if (!isset($cat[$p['key']])) json_error('Relatório não encontrado.', 404);
    if (!can($cat[$p['key']][4], $user)) json_error('Você não tem acesso a este relatório.', 403);
    json_out(report_run($p['key'], $_GET));
});

route('POST', '/reports/build', function () {
    $user = guard('dashboard');
    json_out(report_build(input(), $user));
});

route('POST', '/reports/insights', function () {
    $user = guard('dashboard');
    $in = input();
    if (($in['key'] ?? '') === 'builder') $r = report_build((array)($in['config'] ?? []), $user);
    else {
        $cat = array_column(report_catalog(), null, 0);
        if (!isset($cat[$in['key'] ?? ''])) json_error('Relatório não encontrado.', 404);
        if (!can($cat[$in['key']][4], $user)) json_error('Você não tem acesso a este relatório.', 403);
        $r = report_run($in['key'], (array)($in['params'] ?? []));
    }
    json_out(report_insights($r));
});

route('POST', '/reports/saved', function () {
    $user = guard('dashboard');
    $in = input();
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') json_error('Dê um nome ao relatório.', 422);
    $id = db_insert('saved_reports', ['name' => mb_substr($name, 0, 160), 'description' => mb_substr((string)($in['description'] ?? ''), 0, 255), 'config' => json_encode((array)($in['config'] ?? []), JSON_UNESCAPED_UNICODE), 'pinned' => !empty($in['pinned']) ? 1 : 0, 'created_by' => $user['name'], 'created_at' => now(), 'updated_at' => now()]);
    json_out(db_find('saved_reports', $id), 201);
});

route('PUT', '/reports/saved/{id}', function ($p) {
    guard('dashboard');
    $r = db_find('saved_reports', (int)$p['id']);
    if (!$r) json_error('Relatório não encontrado.', 404);
    $in = input();
    $data = ['updated_at' => now()];
    if (isset($in['name'])) $data['name'] = mb_substr(trim((string)$in['name']), 0, 160) ?: $r['name'];
    if (isset($in['description'])) $data['description'] = mb_substr((string)$in['description'], 0, 255);
    if (isset($in['config'])) $data['config'] = json_encode((array)$in['config'], JSON_UNESCAPED_UNICODE);
    if (isset($in['pinned'])) $data['pinned'] = !empty($in['pinned']) ? 1 : 0;
    db_update('saved_reports', (int)$r['id'], $data);
    json_out(db_find('saved_reports', (int)$r['id']));
});

route('DELETE', '/reports/saved/{id}', function ($p) {
    guard('dashboard');
    db_exec('DELETE FROM saved_reports WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

/* ---------------------------------------------------------------- presentations */
route('GET', '/presentations', function () {
    guard('customers');
    json_out(['data' => db_all('SELECT p.id, p.title, p.kind, p.theme, p.shared, p.share_token, p.views, p.last_viewed_at, p.ai_generated, p.created_by, p.created_at, p.updated_at, c.name AS customer_name, l.name AS lead_name, pr.name AS project_name
        FROM presentations p LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN leads l ON l.id = p.lead_id LEFT JOIN projects pr ON pr.id = p.project_id ORDER BY p.updated_at DESC LIMIT 300'),
        'kinds' => array_map(fn($k) => ['label' => $k[0], 'description' => $k[1]], DECK_KINDS), 'ai' => ai_feature('admin'),
        'services' => array_map(fn($s) => $s['title'], services()), 'segments' => array_map(fn($s) => $s[1], segments()),
        'reports' => array_values(array_map(fn($r) => ['key' => $r[0], 'title' => $r[1]], report_catalog()))]);
});

route('POST', '/presentations/generate', function () {
    $user = guard('customers');
    $in = input();
    if (($in['kind'] ?? '') === 'report') {
        $cat = array_column(report_catalog(), null, 0);
        if (!isset($cat[$in['report'] ?? '']) || !can($cat[$in['report']][4], $user)) json_error('Relatório indisponível.', 403);
    }
    json_out(presentation_generate((string)($in['kind'] ?? ''), $in, $user), 201);
});

route('GET', '/presentations/{id}', function ($p) {
    guard('customers');
    $d = db_find('presentations', (int)$p['id']);
    if (!$d) json_error('Apresentação não encontrada.', 404);
    $d['slides'] = json_decode((string)$d['slides'], true) ?: [];
    $d['kind_label'] = DECK_KINDS[$d['kind']][0] ?? $d['kind'];
    $d['share_url'] = app_link('/apresentacao?t=' . $d['share_token']);
    json_out($d);
});

route('PUT', '/presentations/{id}', function ($p) {
    guard('customers');
    $d = db_find('presentations', (int)$p['id']);
    if (!$d) json_error('Apresentação não encontrada.', 404);
    $in = input();
    $data = ['updated_at' => now()];
    if (isset($in['title'])) $data['title'] = mb_substr(trim((string)$in['title']), 0, 200) ?: $d['title'];
    if (isset($in['theme']) && in_array($in['theme'], ['dark', 'light', 'brand'], true)) $data['theme'] = $in['theme'];
    if (isset($in['slides'])) $data['slides'] = json_encode(presentation_clean_slides($in['slides']), JSON_UNESCAPED_UNICODE);
    if (isset($in['shared'])) $data['shared'] = !empty($in['shared']) ? 1 : 0;
    if (!empty($in['new_token'])) $data['share_token'] = bin2hex(random_bytes(16));
    db_update('presentations', (int)$d['id'], $data);
    json_out(['ok' => true, 'share_url' => app_link('/apresentacao?t=' . ($data['share_token'] ?? $d['share_token']))]);
});

route('DELETE', '/presentations/{id}', function ($p) {
    guard('customers');
    db_exec('DELETE FROM presentations WHERE id = ?', [(int)$p['id']]);
    audit('delete', 'presentation', (int)$p['id']);
    json_out(['ok' => true]);
});

route('POST', '/presentations/{id}/duplicate', function ($p) {
    $user = guard('customers');
    $d = db_find('presentations', (int)$p['id']);
    if (!$d) json_error('Apresentação não encontrada.', 404);
    unset($d['id']);
    $d = array_merge($d, ['title' => mb_substr($d['title'] . ' (cópia)', 0, 200), 'share_token' => bin2hex(random_bytes(16)), 'shared' => 0, 'views' => 0, 'last_viewed_at' => null, 'created_by' => $user['name'], 'created_at' => now(), 'updated_at' => now()]);
    json_out(['id' => db_insert('presentations', $d)], 201);
});

route('POST', '/presentations/{id}/ai-slide', function ($p) {
    guard('customers');
    $d = db_find('presentations', (int)$p['id']);
    if (!$d) json_error('Apresentação não encontrada.', 404);
    $in = input();
    $slides = json_decode((string)$d['slides'], true) ?: [];
    $i = (int)($in['index'] ?? -1);
    if (!isset($slides[$i])) json_error('Slide não encontrado.', 404);
    $slide = is_array($in['slide'] ?? null) ? presentation_clean_slides([$in['slide']])[0] : $slides[$i];
    json_out(['slide' => presentation_ai_slide($slide, (string)($in['instruction'] ?? 'Deixe mais claro, objetivo e persuasivo.'), $d['title'] . ' (' . (DECK_KINDS[$d['kind']][0] ?? '') . ')')]);
});

/* ---------------------------------------------------------------- time tracking */
route('GET', '/projects/{id}/time', function ($p) {
    guard('projects');
    json_out(['data' => db_all('SELECT t.*, k.title AS task_title FROM time_entries t LEFT JOIN project_tasks k ON k.id = t.task_id WHERE t.project_id = ? ORDER BY t.work_date DESC, t.id DESC LIMIT 500', [(int)$p['id']])]);
});

route('POST', '/projects/{id}/time', function ($p) {
    $user = guard('projects');
    if (!db_find('projects', (int)$p['id'])) json_error('Projeto não encontrado.', 404);
    $in = input();
    $data = validate_fields(['work_date' => ['type' => 'date', 'required' => true], 'task_id' => ['type' => 'int'], 'description' => ['type' => 'string', 'max' => 255], 'billable' => ['type' => 'bool', 'default' => 1]], $in);
    $minutes = (int)round(((float)str_replace(',', '.', (string)($in['hours'] ?? 0))) * 60) + (int)($in['minutes'] ?? 0);
    if ($minutes <= 0 || $minutes > 24 * 60) json_error('Verifique os campos destacados.', 422, ['fields' => ['hours' => 'Informe entre 0,1 e 24 horas.']]);
    $id = db_insert('time_entries', $data + ['project_id' => (int)$p['id'], 'minutes' => $minutes, 'user_id' => $user['id'], 'user_name' => $user['name'], 'created_at' => now()]);
    json_out(db_find('time_entries', $id), 201);
});

route('DELETE', '/time/{id}', function ($p) {
    guard('projects');
    db_exec('DELETE FROM time_entries WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

/* ---------------------------------------------------------------- templates & duplicate */
route('GET', '/project-templates', function () {
    guard('projects');
    json_out(['data' => array_map(fn($t) => array_merge($t, ['stages' => json_decode((string)$t['stages'], true) ?: []]), db_all('SELECT * FROM project_templates ORDER BY name'))]);
});

route('POST', '/projects/{id}/save-template', function ($p) {
    guard('projects');
    $pr = db_find('projects', (int)$p['id']);
    if (!$pr) json_error('Projeto não encontrado.', 404);
    $name = trim((string)(input()['name'] ?? '')) ?: $pr['name'];
    $id = db_insert('project_templates', ['name' => mb_substr($name, 0, 160), 'description' => mb_substr((string)(input()['description'] ?? ''), 0, 255), 'project_type' => $pr['project_type'], 'stages' => json_encode(project_structure((int)$pr['id']), JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    json_out(db_find('project_templates', $id), 201);
});

route('DELETE', '/project-templates/{id}', function ($p) {
    guard('projects');
    db_exec('DELETE FROM project_templates WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

route('POST', '/projects/{id}/duplicate', function ($p) {
    guard('projects');
    $in = input();
    $src = db_find('projects', (int)$p['id']);
    if (!$src) json_error('Projeto não encontrado.', 404);
    $id = project_duplicate((int)$src['id'], trim((string)($in['name'] ?? '')) ?: $src['name'] . ' (cópia)', !empty($in['customer_id']) ? (int)$in['customer_id'] : null);
    audit('duplicate', 'project', $id, ['from' => $src['id']]);
    json_out(['id' => $id], 201);
});

route('GET', '/projects-timeline', function () {
    guard('projects');
    $rows = db_all("SELECT p.id, p.name, p.status, p.start_date, p.due_date, p.created_at, c.name AS customer_name FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.status NOT IN ('canceled') AND (p.status != 'done' OR p.updated_at >= ?) ORDER BY COALESCE(p.start_date, p.created_at)", [date('Y-m-d', strtotime('-60 days'))]);
    $stages = [];
    if ($rows) {
        $ph = implode(',', array_fill(0, count($rows), '?'));
        foreach (db_all("SELECT id, project_id, name, status, start_date, due_date, completed_at, position FROM project_stages WHERE project_id IN ($ph) ORDER BY position", array_column($rows, 'id')) as $s) $stages[$s['project_id']][] = $s;
    }
    foreach ($rows as &$r) $r['stages'] = $stages[$r['id']] ?? [];
    json_out(['data' => $rows]);
});

/* ---------------------------------------------------------------- calendar */
route('GET', '/calendar', function () {
    $user = guard('dashboard');
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-t');
    $ev = [];
    if (can('appointments', $user)) foreach (db_all("SELECT id, name, company, topic, scheduled_at, status FROM appointments WHERE scheduled_at BETWEEN ? AND ? AND status != 'canceled'", [$from . ' 00:00:00', $to . ' 23:59:59']) as $a)
        $ev[] = ['type' => 'meeting', 'date' => substr($a['scheduled_at'], 0, 10), 'time' => substr($a['scheduled_at'], 11, 5), 'title' => 'Reunião: ' . ($a['company'] ?: $a['name']), 'sub' => $a['topic'], 'url' => '#/appointments'];
    foreach (db_all("SELECT id, entity, entity_id, kind, body, due_at, done FROM activities WHERE due_at BETWEEN ? AND ? AND done = 0", [$from . ' 00:00:00', $to . ' 23:59:59']) as $a) {
        if (!can(ACTIVITY_ENTITIES[$a['entity']] ?? 'dashboard', $user)) continue;
        $ev[] = ['type' => 'followup', 'date' => substr($a['due_at'], 0, 10), 'time' => substr($a['due_at'], 11, 5), 'title' => mb_substr($a['body'], 0, 80), 'sub' => 'Follow-up', 'url' => ['customer' => '#/customers/', 'project' => '#/projects/', 'ticket' => '#/tickets/', 'lead' => '#/leads?open='][$a['entity']] . $a['entity_id']];
    }
    if (can('finance', $user)) foreach (db_all("SELECT id, entry_type, description, amount, due_date FROM financial_entries WHERE status = 'open' AND due_date BETWEEN ? AND ?", [$from, $to]) as $e)
        $ev[] = ['type' => $e['entry_type'] === 'receivable' ? 'receivable' : 'payable', 'date' => $e['due_date'], 'time' => '', 'title' => ($e['entry_type'] === 'receivable' ? 'Receber: ' : 'Pagar: ') . $e['description'], 'sub' => money($e['amount']), 'url' => '#/finance/entries?type=' . $e['entry_type']];
    if (can('projects', $user)) {
        foreach (db_all("SELECT id, name, due_date FROM projects WHERE due_date BETWEEN ? AND ? AND status NOT IN ('done','canceled')", [$from, $to]) as $p) $ev[] = ['type' => 'deadline', 'date' => $p['due_date'], 'time' => '', 'title' => 'Entrega: ' . $p['name'], 'sub' => 'Prazo do projeto', 'url' => '#/projects/' . $p['id']];
        foreach (db_all("SELECT s.name, s.due_date, p.id, p.name AS project FROM project_stages s JOIN projects p ON p.id = s.project_id WHERE s.due_date BETWEEN ? AND ? AND s.status NOT IN ('done','skipped')", [$from, $to]) as $s) $ev[] = ['type' => 'stage', 'date' => $s['due_date'], 'time' => '', 'title' => 'Etapa: ' . $s['name'], 'sub' => $s['project'], 'url' => '#/projects/' . $s['id']];
    }
    usort($ev, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
    json_out(['from' => $from, 'to' => $to, 'events' => $ev]);
});

route('GET', '/finance/cashflow', function () {
    guard('finance');
    json_out(cashflow_year((int)($_GET['year'] ?? date('Y'))));
});

route('GET', '/finance/projection', function () {
    guard('finance');
    json_out(cashflow_projection(max(7, min(180, (int)($_GET['days'] ?? 60)))));
});

route('GET', '/finance/pnl', function () {
    guard('finance');
    $start = (string)($_GET['start'] ?? date('Y-m-01'));
    $end = (string)($_GET['end'] ?? date('Y-m-t'));
    json_out(pnl($start, $end) + ['monthly' => pnl_monthly(12)]);
});

/* ------------------------------------------------------- bank / extrato */

route('GET', '/bank-transactions', function () {
    guard('finance');
    crud_list([
        'table' => 'bank_transactions',
        'from' => 'bank_transactions t LEFT JOIN financial_entries e ON e.id = t.entry_id',
        'select' => 't.*, e.description AS entry_description',
        'search' => ['t.description', 't.tx_type', 't.external_id'],
        'scope' => function () {
            $w = []; $p = [];
            $state = $_GET['state'] ?? '';
            if ($state === 'pending') $w[] = 't.reconciled = 0 AND t.ignored = 0';
            if ($state === 'reconciled') $w[] = 't.reconciled = 1';
            if ($state === 'ignored') $w[] = 't.ignored = 1';
            if (!empty($_GET['from'])) { $w[] = 't.tx_date >= ?'; $p[] = $_GET['from']; }
            if (!empty($_GET['to'])) { $w[] = 't.tx_date <= ?'; $p[] = $_GET['to']; }
            return [$w ? implode(' AND ', $w) : '', $p];
        },
        'sort' => ['tx_date', 'amount'], 'default_sort' => 'tx_date',
    ]);
});

route('POST', '/bank/import', function () {
    guard('finance');
    $in = input();
    $start = (string)($in['start'] ?? date('Y-m-d', strtotime('-30 days')));
    $end = (string)($in['end'] ?? today());
    json_out(import_asaas_statement($start, $end));
});

route('GET', '/bank/balance', function () {
    guard('finance');
    json_out(asaas_balance());
});

route('POST', '/bank/auto-reconcile', function () {
    guard('finance');
    json_out(['reconciled' => auto_reconcile()]);
});

route('GET', '/bank-transactions/{id}/candidates', function ($p) {
    guard('finance');
    $tx = db_find('bank_transactions', (int)$p['id']);
    if (!$tx) json_error('Transação não encontrada.', 404);
    $candidates = reconcile_candidates($tx, 20);
    // also offer same-sign open entries with close values for manual matching
    $type = $tx['amount'] >= 0 ? 'receivable' : 'payable';
    $ids = array_map(fn($c) => (int)$c['entry']['id'], $candidates);
    $others = db_all("SELECT e.*, cu.name AS customer_name FROM financial_entries e LEFT JOIN customers cu ON cu.id = e.customer_id
        WHERE e.entry_type = ? AND e.status = 'open' AND e.bank_transaction_id IS NULL ORDER BY ABS(e.amount - ?) LIMIT 15", [$type, abs((float)$tx['amount'])]);
    foreach ($others as $o) if (!in_array((int)$o['id'], $ids, true)) $candidates[] = ['entry' => $o, 'score' => 0, 'reason' => 'Lançamento em aberto'];
    json_out(['transaction' => $tx, 'candidates' => $candidates]);
});

route('POST', '/bank-transactions/{id}/link', function ($p) {
    guard('finance');
    reconcile_link((int)$p['id'], (int)(input()['entry_id'] ?? 0));
    audit('reconcile', 'bank_transaction', $p['id']);
    json_out(['ok' => true]);
});

route('POST', '/bank-transactions/{id}/unlink', function ($p) {
    guard('finance');
    reconcile_unlink((int)$p['id']);
    audit('unreconcile', 'bank_transaction', $p['id']);
    json_out(['ok' => true]);
});

route('POST', '/bank-transactions/{id}/create-entry', function ($p) {
    guard('finance');
    $in = input();
    $id = reconcile_create_entry((int)$p['id'], !empty($in['category_id']) ? (int)$in['category_id'] : null, trim((string)($in['description'] ?? '')) ?: null);
    json_out(['ok' => true, 'entry_id' => $id]);
});

route('POST', '/bank-transactions/{id}/ignore', function ($p) {
    guard('finance');
    db_update('bank_transactions', (int)$p['id'], ['ignored' => empty(input()['undo']) ? 1 : 0]);
    json_out(['ok' => true]);
});

route('POST', '/bank-transactions', function () {
    guard('finance');
    $data = validate_fields([
        'tx_date' => ['type' => 'date', 'required' => true],
        'description' => ['type' => 'string', 'required' => true, 'max' => 255],
        'amount' => ['type' => 'decimal', 'required' => true],
    ], input());
    $id = db_insert('bank_transactions', $data + ['source' => 'manual', 'reconciled' => 0, 'ignored' => 0, 'imported_at' => now()]);
    json_out(db_find('bank_transactions', $id), 201);
});

/* -------------------------------------------------------------- charges */

route('GET', '/charges', function () {
    guard('charges');
    crud_list([
        'table' => 'charges',
        'from' => 'charges t LEFT JOIN customers c ON c.id = t.customer_id',
        'select' => 't.*, c.name AS customer_name, c.email AS customer_email',
        'search' => ['c.name', 't.description', 't.asaas_payment_id'],
        'filters' => ['status', 'billing_type', 'customer_id', 'contract_id'],
        'sort' => ['created_at', 'due_date', 'amount'], 'default_sort' => 'created_at',
    ]);
});

route('POST', '/charges', function () {
    guard('charges');
    json_out(create_charge(input()), 201);
});

route('POST', '/charges/{id}/{action}', function ($p) {
    guard('charges');
    $charge = db_find('charges', (int)$p['id']);
    if (!$charge) json_error('Cobrança não encontrada.', 404);
    switch ($p['action']) {
        case 'refresh': json_out(refresh_charge($charge));
        case 'cancel': json_out(cancel_charge($charge));
    }
    json_error('Ação inválida.', 404);
});

route('GET', '/charges/{id}/pix', function ($p) {
    guard('charges');
    $charge = db_find('charges', (int)$p['id']);
    if (!$charge) json_error('Cobrança não encontrada.', 404);
    json_out(charge_pix($charge));
});

route('POST', '/customers/{id}/invite', function ($p) {
    guard('customers');
    $customer = db_find('customers', (int)$p['id']);
    if (!$customer) json_error('Cliente não encontrado.', 404);
    if (!filter_var((string)$customer['email'], FILTER_VALIDATE_EMAIL)) json_error('Cadastre o e-mail do cliente antes de enviar o convite.', 422);
    if (!mail_ready()) json_error('Configure o envio de e-mails em Configurações → E-mail antes de enviar convites.', 409);
    db_update('customers', (int)$customer['id'], ['portal_enabled' => 1, 'updated_at' => now()]);
    send_password_link('customer', $customer, 'invite');
    audit('invite', 'customer', $customer['id']);
    json_out(['ok' => true, 'message' => 'Convite enviado para ' . $customer['email'] . '.']);
});

route('POST', '/users/{id}/invite', function ($p) {
    guard('users');
    $user = db_find('users', (int)$p['id']);
    if (!$user) json_error('Usuário não encontrado.', 404);
    if (!mail_ready()) json_error('Configure o envio de e-mails em Configurações → E-mail antes de enviar convites.', 409);
    send_password_link('user', $user, 'invite');
    audit('invite', 'user', $user['id']);
    json_out(['ok' => true, 'message' => 'Convite enviado para ' . $user['email'] . '.']);
});

route('POST', '/customers/{id}/asaas-sync', function ($p) {
    guard('customers');
    $customer = db_find('customers', (int)$p['id']);
    if (!$customer) json_error('Cliente não encontrado.', 404);
    db_update('customers', (int)$customer['id'], ['asaas_customer_id' => null]);
    $customer['asaas_customer_id'] = null;
    json_out(['asaas_customer_id' => asaas_ensure_customer($customer)]);
});

/* -------------------------------------------------------- distributions */

route('GET', '/distributions', function () {
    guard('partners');
    $rows = db_all('SELECT * FROM distributions ORDER BY period_end DESC, id DESC');
    foreach ($rows as &$r) {
        $r['items'] = db_all('SELECT i.*, p.name, e.status AS entry_status FROM distribution_items i JOIN partners p ON p.id = i.partner_id LEFT JOIN financial_entries e ON e.id = i.entry_id WHERE i.distribution_id = ?', [$r['id']]);
    }
    json_out(['data' => $rows]);
});

route('POST', '/distributions/preview', function () {
    guard('partners');
    $in = input();
    json_out(distribution_preview((string)$in['start'], (string)$in['end'], (float)($in['reserve_percent'] ?? setting('profit_reserve_percent', 20))));
});

route('POST', '/distributions', function () {
    guard('partners');
    $in = input();
    $id = create_distribution((string)$in['start'], (string)$in['end'], (float)($in['reserve_percent'] ?? 0), (string)($in['payment_date'] ?? today()), $in['notes'] ?? null);
    json_out(['id' => $id], 201);
});

route('DELETE', '/distributions/{id}', function ($p) {
    guard('partners');
    $id = (int)$p['id'];
    if (db_value("SELECT COUNT(*) FROM financial_entries WHERE distribution_id = ? AND status = 'paid'", [$id])) {
        json_error('Há pagamentos desta distribuição já realizados. Estorne-os antes de excluir.', 409);
    }
    db_transaction(function () use ($id) {
        db_exec('DELETE FROM financial_entries WHERE distribution_id = ?', [$id]);
        db_exec('DELETE FROM distribution_items WHERE distribution_id = ?', [$id]);
        db_exec('DELETE FROM distributions WHERE id = ?', [$id]);
    });
    audit('delete', 'distribution', $id);
    json_out(['ok' => true]);
});

/* ============================================================== SETTINGS */

const EDITABLE_SETTINGS = ['nfse_provider', 'nfse_sigiss_password', 'nfse_sigiss_servico', 'nfse_sigiss_situacao', 'nfse_sigiss_url', 'company_name', 'company_cnpj', 'company_email', 'company_phone', 'asaas_environment', 'asaas_api_key', 'profit_reserve_percent', 'ticket_sla_hours', 'appointment_slot_minutes', 'project_stage_template',
    'ticket_sla_urgent', 'ticket_sla_high', 'ticket_sla_normal', 'ticket_sla_low', 'ticket_autoclose_days', 'ticket_csat_enabled', 'ticket_auto_assign', 'portal_uploads_enabled',
    'goal_revenue_month', 'cost_per_hour', 'default_hourly_rate',
    'fh_sales_enabled', 'fh_grace_days', 'fh_lead_days', 'fh_revenue_category', 'fh_openfinance_enabled', 'fh_pluggy_client_id', 'fh_pluggy_client_secret',
    'search_api_key', 'contracts_lead_days', 'contracts_auto_renew', 'contracts_auto_billing', 'pricing_setup_category', 'pricing_monthly_category',
    'nfse_pis_rate', 'nfse_cofins_rate', 'nfse_csll_rate', 'nfse_irrf_rate', 'nfse_inss_rate', 'nfse_pis_cofins_cst', 'nfse_withhold_federal_pj', 'nfse_total_tax_pct', 'nfse_show_taxes',
    'nfse_environment', 'nfse_cnpj', 'nfse_im', 'nfse_city_code', 'nfse_op_simp_nac', 'nfse_reg_ap_trib_sn', 'nfse_reg_esp_trib', 'nfse_serie', 'nfse_next_number', 'nfse_ctribnac', 'nfse_cnbs', 'nfse_aliquota', 'nfse_simples_percent', 'nfse_default_description', 'nfse_auto_on_payment',
    'cloudflare_account_id', 'cloudflare_api_token', 'ai_model', 'ai_chat_enabled', 'ai_diagnostic_enabled', 'ai_admin_enabled',
    'mail_enabled', 'mail_provider', 'mail_cf_token', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_password', 'mail_from_email', 'mail_from_name', 'mail_reply_to', 'mail_notify_to', 'mail_charge_emails', 'mail_nfse_emails',
    'google_client_id', 'google_client_secret', 'google_login_enabled', 'portal_signup_enabled'];
const MASKED_SETTINGS = ['asaas_api_key', 'cloudflare_api_token', 'mail_password', 'mail_cf_token', 'google_client_secret', 'nfse_sigiss_password', 'search_api_key', 'fh_pluggy_client_secret'];

route('GET', '/settings', function () {
    guard('users');
    $out = [];
    foreach (EDITABLE_SETTINGS as $k) $out[$k] = setting($k, '');
    foreach (MASKED_SETTINGS as $k) $out[$k] = $out[$k] ? mask_secret($out[$k]) : '';
    $out['nfse_certificate'] = null;
    if (setting('nfse_cert_pfx')) {
        try { $out['nfse_certificate'] = nfse_certificate()['info']; } catch (Throwable $e) { $out['nfse_certificate'] = ['error' => $e->getMessage()]; }
    }
    $out['ai_configured'] = ai_configured();
    $out['google_redirect_uri'] = google_redirect_uri();
    $out['mail_ready'] = mail_ready();
    if (!setting('cron_key')) set_setting('cron_key', bin2hex(random_bytes(12)));
    $out['cron_url'] = app_link('/cron.php?key=' . setting('cron_key'));
    $out['asaas_webhook_token'] = setting('asaas_webhook_token', '');
    $out['webhook_url'] = rtrim((string)config('app_url'), '/') . '/api/webhooks/asaas';
    json_out($out);
});

route('PUT', '/settings', function () {
    guard('users');
    $in = input();
    foreach (EDITABLE_SETTINGS as $k) {
        if (!array_key_exists($k, $in)) continue;
        $v = trim((string)$in[$k]);
        if (in_array($k, MASKED_SETTINGS, true) && strpos($v, '•') !== false) continue; // masked value unchanged
        if ($k === 'mail_password') $v = str_replace(' ', '', $v); // Google displays app passwords in 4-letter groups
        if (in_array($k, ['nfse_cnpj', 'nfse_im', 'nfse_ctribnac', 'nfse_cnbs', 'nfse_city_code', 'nfse_sigiss_servico'], true)) $v = only_digits($v);
        if ($k === 'nfse_provider' && !in_array($v, ['sigiss', 'nacional'], true)) continue;
        if ($k === 'nfse_sigiss_situacao' && !isset(SIGISS_SITUACOES[$v])) continue;
        if ($k === 'nfse_environment' && !in_array($v, ['homologation', 'production'], true)) continue;
        if ($k === 'nfse_aliquota' || $k === 'nfse_simples_percent') $v = (string)round((float)str_replace(',', '.', $v), 2);
        if ($k === 'asaas_environment' && !in_array($v, ['sandbox', 'production'], true)) continue;
        if (strpos($k, 'ticket_sla_') === 0 || $k === 'ticket_autoclose_days') $v = (string)max(0, min(720, (int)$v));
        if (in_array($k, ['nfse_pis_rate', 'nfse_cofins_rate', 'nfse_csll_rate', 'nfse_irrf_rate', 'nfse_inss_rate', 'nfse_total_tax_pct'], true) && $v !== '') $v = (string)max(0, min(100, round((float)str_replace(',', '.', $v), 2)));
        if ($k === 'contracts_lead_days') $v = (string)max(0, min(40, (int)$v));
        if ($k === 'nfse_pis_cofins_cst' && $v !== '' && !preg_match('/^\d{2}$/', $v)) continue;
        if ($k === 'fh_openfinance_enabled') $v = $v === '1' ? '1' : '0';
        if (in_array($k, ['fh_pluggy_client_id', 'fh_pluggy_client_secret'], true)) set_setting('fh_pluggy_api_key', ''); // new credentials: fetch a new API key
        if (in_array($k, ['goal_revenue_month', 'cost_per_hour', 'default_hourly_rate'], true)) $v = (string)max(0, round((float)(strpos($v, ',') !== false ? str_replace(['.', ','], ['', '.'], $v) : $v), 2));
        set_setting($k, $v);
    }
    if (!empty($in['regenerate_webhook_token'])) set_setting('asaas_webhook_token', bin2hex(random_bytes(16)));
    audit('update', 'settings', null, array_keys($in));
    json_out(['ok' => true]);
});

route('POST', '/asaas/test', function () {
    guard('users');
    if (!AsaasClient::isConfigured()) json_error('Informe e salve a chave da API antes de testar.', 422);
    $res = (new AsaasClient())->get('/finance/balance');
    json_out(['ok' => true, 'balance' => $res['balance'] ?? null, 'environment' => setting('asaas_environment')]);
});

route('GET', '/audit-log', function () {
    guard('users');
    crud_list([
        'table' => 'audit_log', 'search' => ['t.user_name', 't.action', 't.entity', 't.details'],
        'filters' => ['entity', 'action'], 'sort' => ['created_at'], 'default_sort' => 'created_at',
    ]);
});

route('GET', '/lookups', function () {
    guard('dashboard');
    json_out([
        'customers' => db_all("SELECT id, name, document FROM customers WHERE status != 'inactive' ORDER BY name"),
        'projects' => db_all("SELECT id, name, customer_id FROM projects WHERE status NOT IN ('done','canceled') ORDER BY name"),
        'users' => db_all('SELECT id, name, role FROM users WHERE active = 1 ORDER BY name'),
        'tags' => all_tags(),
        'statuses' => status_sets(),
        'categories' => db_all('SELECT id, name, entry_type, dre_group, color FROM categories ORDER BY name'),
        'help_categories' => array_map(fn($c) => $c[1], help_categories()),
        'ticket_categories' => ticket_categories(),
        'segments' => array_map(fn($s) => $s[1], segments()),
        'stage_template' => array_map('trim', explode('|', (string)setting('project_stage_template', 'Diagnóstico|Planejamento|Desenvolvimento|Implantação|Evolução'))),
        'services' => array_values(array_map(fn($s) => $s['title'], services())),
    ]);
});

/* ================================================================= NFS-e */

route('GET', '/nfse/status', function () {
    guard('finance');
    $cfg = nfse_config();
    $cert = null;
    if (setting('nfse_cert_pfx')) {
        try { $cert = nfse_certificate()['info']; } catch (Throwable $e) { $cert = ['error' => $e->getMessage()]; }
    }
    json_out([
        'config' => $cfg, 'certificate' => $cert,
        'ready' => nfse_ready(),
        'sigiss' => ['ready' => sigiss_ready(), 'servico' => sigiss_config()['servico'], 'situacao' => sigiss_config()['situacao']],
        'totals' => db_one("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status='authorized' THEN amount END),0) AS authorized_amount,
            COALESCE(SUM(CASE WHEN status='authorized' THEN iss_amount END),0) AS iss_amount,
            SUM(CASE WHEN status IN ('rejected','draft') THEN 1 ELSE 0 END) AS pending
            FROM nfse_invoices WHERE environment = ? AND created_at >= ?", [$cfg['environment'], date('Y-m-01 00:00:00')]),
    ]);
});

route('GET', '/nfse', function () {
    guard('finance');
    crud_list([
        'table' => 'nfse_invoices',
        'from' => 'nfse_invoices t LEFT JOIN customers c ON c.id = t.customer_id',
        'select' => 't.id, t.provider, t.print_url, t.verification_code, t.customer_id, t.charge_id, t.environment, t.dps_serie, t.dps_number, t.dps_id, t.toma_name, t.toma_document, t.service_code, t.description, t.amount, t.iss_rate, t.iss_amount, t.status, t.access_key, t.nfse_number, t.error_message, t.issued_at, t.canceled_at, t.competence_date, t.created_at, t.contract_id, t.net_amount, t.total_taxes_amount, c.name AS customer_name',
        'search' => ['t.toma_name', 't.description', 't.access_key', 't.nfse_number', 't.toma_document'],
        'filters' => ['status', 'environment', 'customer_id', 'contract_id'],
        'sort' => ['created_at', 'amount', 'dps_number'], 'default_sort' => 'created_at',
    ]);
});

route('POST', '/nfse/certificate', function () {
    guard('users');
    $in = input();
    $pfx = base64_decode((string)($in['pfx_b64'] ?? ''), true);
    if (!$pfx || strlen($pfx) > 200000) json_error('Envie o arquivo .pfx do certificado A1.', 422);
    $info = nfse_read_pfx($pfx, (string)($in['password'] ?? ''))['info'];
    if ($info['expired']) json_error('Este certificado está vencido (validade ' . $info['valid_to'] . ').', 422);
    set_setting('nfse_cert_pfx', base64_encode($pfx));
    set_setting('nfse_cert_password', (string)($in['password'] ?? ''));
    if ($info['cnpj'] && !setting('nfse_cnpj')) set_setting('nfse_cnpj', $info['cnpj']);
    audit('upload', 'nfse_certificate', null, ['subject' => $info['subject'], 'valid_to' => $info['valid_to']]);
    json_out(['ok' => true, 'info' => $info]);
});

route('DELETE', '/nfse/certificate', function () {
    guard('users');
    set_setting('nfse_cert_pfx', '');
    set_setting('nfse_cert_password', '');
    audit('delete', 'nfse_certificate');
    json_out(['ok' => true]);
});

route('POST', '/nfse/sigiss-test', function () {
    guard('finance');
    json_out(sigiss_test_credentials());
});

route('GET', '/cep/{cep}', function ($p) {
    guard('customers');
    $r = cep_lookup((string)$p['cep']);
    if (!$r) json_error('CEP não encontrado.', 404);
    json_out(['address' => $r['logradouro'] ?? '', 'district' => $r['bairro'] ?? '', 'city' => $r['localidade'] ?? '', 'state' => $r['uf'] ?? '', 'city_ibge' => $r['ibge'] ?? '']);
});

route('POST', '/nfse/municipality', function () {
    guard('finance');
    json_out(nfse_municipality_params());
});

route('POST', '/nfse/preview', function () {
    guard('finance');
    $in = input();
    $cfg = nfse_config();
    $customer = db_find('customers', (int)($in['customer_id'] ?? 0));
    if (!$customer) json_error('Selecione o cliente.', 422);
    $inv = [
        'dps_id' => nfse_dps_id($cfg, $cfg['serie'], $cfg['next_number']), 'dps_serie' => $cfg['serie'], 'dps_number' => $cfg['next_number'],
        'toma_document' => $customer['document'], 'toma_name' => $customer['name'], 'toma_email' => $customer['email'], 'toma_phone' => $customer['phone'],
        'service_code' => only_digits((string)($in['service_code'] ?? '')) ?: $cfg['ctribnac'], 'nbs_code' => only_digits((string)($in['nbs_code'] ?? '')) ?: $cfg['cnbs'],
        'description' => (string)($in['description'] ?? '') ?: $cfg['default_description'], 'amount' => (float)($in['amount'] ?? 0),
        'competence_date' => $in['competence_date'] ?? today(),
    ];
    $inv = nfse_taxes($in + ['toma_document' => $customer['document']], $cfg) + $inv;
    $xml = nfse_build_dps($inv, $cfg);
    $valid = true; $errors = [];
    try { nfse_validate_xsd($xml, 'DPS_v1.01.xsd'); } catch (NfseException $e) { $valid = false; $errors = $e->details; }
    $dom = new DOMDocument(); $dom->preserveWhiteSpace = false; $dom->loadXML($xml); $dom->formatOutput = true;
    json_out(['xml' => $dom->saveXML($dom->documentElement), 'valid' => $valid, 'errors' => $errors]);
});

route('POST', '/nfse/taxes', function () {
    guard('finance');
    $in = input();
    $doc = !empty($in['customer_id']) ? (string)db_value('SELECT document FROM customers WHERE id = ?', [(int)$in['customer_id']]) : '';
    $cfg = nfse_config();
    $t = nfse_taxes($in + ['toma_document' => $doc], $cfg);
    $t['note'] = nfse_taxes_note($t);
    $t['regime'] = $cfg['op_simp_nac'];
    json_out($t);
});

route('POST', '/nfse', function () {
    guard('finance');
    $in = input();
    $id = nfse_create_draft($in);
    if (!empty($in['transmit'])) {
        try { nfse_transmit($id); } catch (NfseException $e) { json_out(['id' => $id, 'invoice' => nfse_find($id), 'error' => $e->getMessage(), 'details' => $e->details], 201); }
    }
    json_out(['id' => $id, 'invoice' => nfse_find($id)], 201);
});

route('GET', '/nfse/{id}', function ($p) {
    guard('finance');
    $inv = nfse_find((int)$p['id']);
    $inv['has_xml_dps'] = !empty($inv['xml_dps']);
    $inv['has_xml_nfse'] = !empty($inv['xml_nfse']);
    unset($inv['xml_dps'], $inv['xml_nfse'], $inv['xml_cancel']);
    json_out($inv);
});

route('POST', '/nfse/{id}/transmit', function ($p) {
    guard('finance');
    json_out(nfse_transmit((int)$p['id']));
});

route('POST', '/nfse/{id}/cancel', function ($p) {
    guard('finance');
    $in = input();
    json_out(nfse_cancel((int)$p['id'], (int)($in['reason'] ?? 0), (string)($in['justification'] ?? '')));
});

route('DELETE', '/nfse/{id}', function ($p) {
    guard('finance');
    $inv = nfse_find((int)$p['id']);
    if (!in_array($inv['status'], ['draft', 'rejected'], true)) json_error('Somente rascunhos ou notas rejeitadas podem ser excluídos.', 409);
    db_exec('DELETE FROM nfse_invoices WHERE id = ?', [$inv['id']]);
    audit('delete', 'nfse', $inv['id']);
    json_out(['ok' => true]);
});

route('GET', '/nfse/{id}/xml', function ($p) {
    guard('finance');
    $inv = db_find('nfse_invoices', (int)$p['id']);
    if (!$inv) json_error('Nota não encontrada.', 404);
    $type = ($_GET['type'] ?? 'nfse') === 'dps' ? 'dps' : 'nfse';
    $xml = $inv['xml_' . $type];
    if (!$xml) json_error('XML indisponível.', 404);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($type === 'nfse' ? 'NFSe-' . ($inv['access_key'] ?: $inv['id']) : $inv['dps_id']) . '.xml"');
    echo $xml;
    exit;
});

route('GET', '/nfse/{id}/danfse', function ($p) {
    guard('finance');
    $inv = nfse_find((int)$p['id']);
    if ($inv['status'] !== 'authorized' && $inv['status'] !== 'canceled') json_error('A nota ainda não foi autorizada.', 409);
    if (($_GET['source'] ?? '') !== 'local' && !empty($inv['print_url'])) {
        header('Location: ' . $inv['print_url']);
        exit;
    }
    if (($_GET['source'] ?? '') !== 'local' && ($pdf = nfse_danfse_pdf($inv))) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="DANFSe-' . $inv['access_key'] . '.pdf"');
        echo $pdf;
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    require INC_PATH . '/danfse.php';
    exit;
});

/* ================================================================ E-MAIL */

route('POST', '/mail/test', function () {
    guard('users');
    $to = trim((string)(input()['to'] ?? '')) ?: current_user()['email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_error('Informe um e-mail válido para o teste.', 422);
    mail_test($to);
    json_out(['ok' => true, 'message' => "E-mail de teste enviado para $to via " . mail_provider_label() . '.']);
});

route('GET', '/mail/outbox', function () {
    guard('users');
    crud_list([
        'table' => 'email_outbox',
        'select' => 't.id, t.event, t.to_email, t.to_name, t.subject, t.status, t.attempts, t.last_error, t.sent_at, t.created_at',
        'search' => ['t.to_email', 't.subject', 't.event'],
        'filters' => ['status', 'event'],
        'sort' => ['created_at', 'status'], 'default_sort' => 'created_at',
    ]);
});

route('GET', '/mail/outbox/{id}', function ($p) {
    guard('users');
    $row = db_find('email_outbox', (int)$p['id']);
    if (!$row) json_error('E-mail não encontrado.', 404);
    unset($row['attachments']);
    json_out($row);
});

route('POST', '/mail/outbox/{id}/retry', function ($p) {
    guard('users');
    db_exec("UPDATE email_outbox SET status = 'pending', send_after = ?, attempts = 0 WHERE id = ?", [now(), (int)$p['id']]);
    json_out(mail_flush(5));
});

route('POST', '/mail/flush', function () {
    guard('users');
    json_out(mail_flush(30));
});

/* ============================================================ AI (admin) */

route('POST', '/ai/test', function () {
    guard('users');
    $t0 = microtime(true);
    $reply = ai_chat([['role' => 'user', 'content' => 'Responda apenas: "Conexão com a IA funcionando."']], 'test', 30, 0);
    json_out(['ok' => true, 'reply' => $reply, 'ms' => (int)((microtime(true) - $t0) * 1000), 'model' => setting('ai_model', AI_DEFAULT_MODEL)]);
});

function require_ai_admin(): void
{
    if (!ai_feature('admin')) json_error('Recursos de IA do painel desativados. Ative em Configurações → Inteligência Artificial.', 409);
}

route('POST', '/ai/ticket-reply/{id}', function ($p) {
    guard('tickets');
    require_ai_admin();
    $ticket = db_find('tickets', (int)$p['id']);
    if (!$ticket) json_error('Chamado não encontrado.', 404);
    json_out(['text' => ai_ticket_reply($ticket, db_all('SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id', [$ticket['id']]))]);
});

route('POST', '/ai/lead-summary/{id}', function ($p) {
    guard('leads');
    require_ai_admin();
    $lead = db_find('leads', (int)$p['id']);
    if (!$lead) json_error('Lead não encontrado.', 404);
    json_out(['text' => ai_lead_summary($lead)]);
});

route('POST', '/ai/blog-draft', function () {
    guard('content');
    require_ai_admin();
    $topic = trim((string)(input()['topic'] ?? ''));
    if (mb_strlen($topic) < 5) json_error('Descreva o tema do artigo.', 422);
    json_out(ai_blog_draft(mb_substr($topic, 0, 300), mb_substr((string)(input()['audience'] ?? ''), 0, 200)));
});

route('POST', '/ai/nfse-description', function () {
    guard('finance');
    require_ai_admin();
    $note = trim((string)(input()['note'] ?? ''));
    if (mb_strlen($note) < 3) json_error('Escreva uma nota curta sobre o serviço.', 422);
    json_out(['text' => ai_nfse_description(mb_substr($note, 0, 500))]);
});

route('GET', '/ai/usage', function () {
    guard('users');
    json_out(['data' => db_all('SELECT feature, COUNT(*) AS calls, SUM(ok) AS ok, SUM(prompt_chars + response_chars) AS chars FROM ai_logs WHERE created_at >= ? GROUP BY feature ORDER BY calls DESC', [date('Y-m-d H:i:s', strtotime('-30 days'))])]);
});

require __DIR__ . '/fiscalhub_routes.php';
require __DIR__ . '/fiscalhub_finance_routes.php';

/* ================================================== EXPORTS + GENERIC CRUD */

route('GET', '/{resource}/export.csv', function ($p) {
    $exports = [
        'customers' => ['customers', ['id' => 'ID', 'name' => 'Razão social', 'trade_name' => 'Nome fantasia', 'document' => 'CPF/CNPJ', 'email' => 'E-mail', 'phone' => 'Telefone', 'segment' => 'Segmento', 'city' => 'Cidade', 'state' => 'UF', 'status' => 'Status']],
        'entries' => ['finance', ['id' => 'ID', 'entry_type' => 'Tipo', 'description' => 'Descrição', 'category_name' => 'Categoria', 'customer_name' => 'Cliente', 'supplier' => 'Fornecedor', 'amount' => 'Valor', 'due_date' => 'Vencimento', 'paid_amount' => 'Valor pago', 'paid_at' => 'Pago em', 'status' => 'Status', 'payment_method' => 'Forma']],
        'bank-transactions' => ['finance', ['tx_date' => 'Data', 'description' => 'Descrição', 'tx_type' => 'Tipo', 'amount' => 'Valor', 'balance' => 'Saldo', 'external_id' => 'ID Asaas', 'reconciled' => 'Conciliado']],
        'leads' => ['leads', ['id' => 'ID', 'created_at' => 'Data', 'name' => 'Nome', 'email' => 'E-mail', 'phone' => 'Telefone', 'company' => 'Empresa', 'subject' => 'Assunto', 'source' => 'Origem', 'status' => 'Status']],
    ];
    if (!isset($exports[$p['resource']])) json_error('Exportação indisponível.', 404);
    [$area, $headers] = $exports[$p['resource']];
    guard($area);
    $sql = [
        'customers' => 'SELECT * FROM customers ORDER BY name',
        'entries' => 'SELECT t.*, c.name AS category_name, cu.name AS customer_name FROM financial_entries t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN customers cu ON cu.id = t.customer_id WHERE (? = \'\' OR t.entry_type = ?) AND (? = \'\' OR t.due_date >= ?) AND (? = \'\' OR t.due_date <= ?) ORDER BY t.due_date',
        'bank-transactions' => 'SELECT * FROM bank_transactions WHERE (? = \'\' OR tx_date >= ?) AND (? = \'\' OR tx_date <= ?) ORDER BY tx_date',
        'leads' => 'SELECT * FROM leads ORDER BY created_at DESC',
    ][$p['resource']];
    $params = [];
    if ($p['resource'] === 'entries') {
        $type = (string)($_GET['entry_type'] ?? ''); $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
        $params = [$type, $type, $from, $from, $to, $to];
    } elseif ($p['resource'] === 'bank-transactions') {
        $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? '');
        $params = [$from, $from, $to, $to];
    }
    csv_out($p['resource'] . '-' . date('Ymd-His') . '.csv', $headers, db_all($sql, $params));
});

$crudHandler = function (string $action) {
    return function ($p) use ($action) {
        $all = resources();
        $res = $all[$p['resource']] ?? null;
        if (!$res) json_error('Recurso não encontrado.', 404);
        guard($res['area']);
        switch ($action) {
            case 'list': crud_list($res);
            case 'get': json_out(crud_get($res, (int)$p['id']));
            case 'create': json_out(crud_create($res), 201);
            case 'update': json_out(crud_update($res, (int)$p['id']));
            case 'delete': crud_delete($res, (int)$p['id']); json_out(['ok' => true]);
        }
    };
};
/* ============================================== ACTIVITIES · FILES · SEARCH */

function entity_guard(string $entity, array $map): array
{
    if (!isset($map[$entity])) json_error('Tipo inválido.', 422);
    return guard($map[$entity]);
}

route('GET', '/search', function () {
    $user = guard('dashboard');
    json_out(['data' => global_search((string)($_GET['q'] ?? ''), $user)]);
});

route('GET', '/activities', function () {
    $entity = (string)($_GET['entity'] ?? '');
    if ($entity === '') {
        guard('dashboard');
        $w = ['a.done = 0', 'a.due_at IS NOT NULL'];
        $params = [];
        if (($_GET['scope'] ?? '') === 'mine') { $w[] = 'a.user_id = ?'; $params[] = (int)current_user()['id']; }
        json_out(['data' => db_all("SELECT a.*, CASE a.entity WHEN 'customer' THEN (SELECT name FROM customers WHERE id = a.entity_id) WHEN 'lead' THEN (SELECT name FROM leads WHERE id = a.entity_id) WHEN 'project' THEN (SELECT name FROM projects WHERE id = a.entity_id) WHEN 'ticket' THEN (SELECT subject FROM tickets WHERE id = a.entity_id) END AS entity_name FROM activities a WHERE " . implode(' AND ', $w) . ' ORDER BY a.due_at LIMIT 100', $params)]);
    }
    entity_guard($entity, ACTIVITY_ENTITIES);
    json_out(['data' => db_all('SELECT * FROM activities WHERE entity = ? AND entity_id = ? ORDER BY COALESCE(due_at, created_at) DESC, id DESC LIMIT 300', [$entity, (int)($_GET['entity_id'] ?? 0)])]);
});

route('POST', '/activities', function () {
    $in = input();
    $user = entity_guard((string)($in['entity'] ?? ''), ACTIVITY_ENTITIES);
    $data = validate_fields([
        'entity_id' => ['type' => 'int', 'required' => true],
        'kind' => ['type' => 'enum', 'values' => array_keys(ACTIVITY_KINDS), 'default' => 'note'],
        'body' => ['type' => 'text', 'required' => true],
        'due_at' => ['type' => 'datetime'],
    ], $in);
    $id = activity_add((string)$in['entity'], $data['entity_id'], $data['kind'], $data['body'], $data['due_at'], $user);
    if ($in['entity'] === 'lead' && $data['due_at']) db_update('leads', $data['entity_id'], ['next_action_at' => $data['due_at']]);
    json_out(db_find('activities', $id), 201);
});

route('PUT', '/activities/{id}', function ($p) {
    $a = db_find('activities', (int)$p['id']);
    if (!$a) json_error('Registro não encontrado.', 404);
    entity_guard($a['entity'], ACTIVITY_ENTITIES);
    $data = validate_fields(['body' => ['type' => 'text'], 'due_at' => ['type' => 'datetime'], 'done' => ['type' => 'bool'], 'kind' => ['type' => 'enum', 'values' => array_keys(ACTIVITY_KINDS)]], input(), true);
    db_update('activities', (int)$a['id'], $data);
    json_out(db_find('activities', (int)$a['id']));
});

route('DELETE', '/activities/{id}', function ($p) {
    $a = db_find('activities', (int)$p['id']);
    if (!$a) json_error('Registro não encontrado.', 404);
    entity_guard($a['entity'], ACTIVITY_ENTITIES);
    db_exec('DELETE FROM activities WHERE id = ?', [$a['id']]);
    json_out(['ok' => true]);
});

route('GET', '/attachments', function () {
    $entity = (string)($_GET['entity'] ?? '');
    entity_guard($entity, ATTACHMENT_ENTITIES);
    json_out(['data' => attachments_for($entity, (int)($_GET['entity_id'] ?? 0))]);
});

route('POST', '/attachments', function () {
    $in = input();
    $entity = (string)($in['entity'] ?? '');
    $user = entity_guard($entity, ATTACHMENT_ENTITIES);
    $entityId = (int)($in['entity_id'] ?? 0);
    if (!db_find(ATTACHMENT_TABLES[$entity], $entityId)) json_error('Registro não encontrado.', 404);
    attachments_validate_all('files');
    $files = attachments_store_all('files', $entity, $entityId, null, filter_var($in['client_visible'] ?? true, FILTER_VALIDATE_BOOLEAN), 'staff', $user['name']);
    if (!$files) json_error('Selecione um arquivo.', 422);
    if (in_array($entity, ['project', 'customer', 'lead'], true)) activity_add($entity, $entityId, 'event', 'Arquivo(s) anexado(s): ' . implode(', ', array_column($files, 'file_name')), null, $user);
    json_out(['data' => $files], 201);
});

route('GET', '/attachments/{id}/download', function ($p) {
    $a = db_find('attachments', (int)$p['id']);
    if (!$a) json_error('Arquivo não encontrado.', 404);
    entity_guard($a['entity'], ATTACHMENT_ENTITIES);
    attachment_output($a, isset($_GET['download']));
});

route('PUT', '/attachments/{id}', function ($p) {
    $a = db_find('attachments', (int)$p['id']);
    if (!$a) json_error('Arquivo não encontrado.', 404);
    entity_guard($a['entity'], ATTACHMENT_ENTITIES);
    $data = validate_fields(['client_visible' => ['type' => 'bool'], 'file_name' => ['type' => 'string', 'max' => 255]], input(), true);
    db_update('attachments', (int)$a['id'], $data);
    json_out(db_find('attachments', (int)$a['id']));
});

route('DELETE', '/attachments/{id}', function ($p) {
    $a = db_find('attachments', (int)$p['id']);
    if (!$a) json_error('Arquivo não encontrado.', 404);
    entity_guard($a['entity'], ATTACHMENT_ENTITIES);
    attachment_delete($a);
    json_out(['ok' => true]);
});

route('POST', '/canned-responses/{id}/use', function ($p) {
    guard('tickets');
    db_exec('UPDATE canned_responses SET uses = uses + 1 WHERE id = ?', [(int)$p['id']]);
    json_out(['ok' => true]);
});

route('GET', '/{resource}', $crudHandler('list'));
route('POST', '/{resource}', $crudHandler('create'));
route('GET', '/{resource}/{id}', $crudHandler('get'));
route('PUT', '/{resource}/{id}', $crudHandler('update'));
route('DELETE', '/{resource}/{id}', $crudHandler('delete'));

/* ================================================================ HELPERS */

function pricing_item_fields(array $in): array
{
    $d = validate_fields([
        'name' => ['type' => 'string', 'required' => true, 'max' => 160], 'category' => ['type' => 'enum', 'values' => array_keys(PRICE_CATEGORIES), 'required' => true],
        'billing' => ['type' => 'enum', 'values' => array_keys(PRICE_BILLING), 'required' => true], 'unit' => ['type' => 'string', 'max' => 30], 'tier' => ['type' => 'string', 'max' => 20],
        'price' => ['type' => 'decimal', 'required' => true], 'market_min' => ['type' => 'decimal'], 'market_avg' => ['type' => 'decimal'], 'market_max' => ['type' => 'decimal'],
        'market_source' => ['type' => 'string', 'max' => 500], 'max_discount' => ['type' => 'decimal', 'default' => 15], 'description' => ['type' => 'text'], 'includes' => ['type' => 'text'],
        'service_code' => ['type' => 'string', 'max' => 10], 'active' => ['type' => 'bool', 'default' => 1],
    ], $in);
    if (($d['market_avg'] ?? 0) > 0 && !empty($in['use_market'])) $d['price'] = pricing_round($d['market_avg'] * MARKET_FACTOR);
    if (!empty($in['market_touched'])) $d['market_updated_at'] = today();
    $d['unit'] = $d['unit'] ?: 'projeto';
    return $d;
}

function all_tags(): array
{
    $tags = [];
    foreach (['customers', 'projects', 'leads', 'tickets'] as $table) {
        foreach (db_all("SELECT tags FROM $table WHERE tags IS NOT NULL AND tags != '' ORDER BY id DESC LIMIT 400") as $r) {
            foreach (explode(',', $r['tags']) as $t) { $t = trim($t); if ($t !== '') $tags[$t] = ($tags[$t] ?? 0) + 1; }
        }
    }
    arsort($tags);
    return array_slice(array_keys($tags), 0, 60);
}

function add_months(string $date, int $months): string
{
    $d = new DateTime($date);
    $day = (int)$d->format('d');
    $d->modify('first day of this month')->modify("+$months months");
    $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($day, (int)$d->format('t')));
    return $d->format('Y-m-d');
}

function available_slots(string $date): array
{
    $ts = strtotime($date);
    $dow = (int)date('N', $ts);
    if ($dow > 5 || $date < today() || $date > date('Y-m-d', strtotime('+60 days'))) return [];
    $step = max(30, (int)setting('appointment_slot_minutes', 60));
    $taken = array_map(fn($r) => substr($r['scheduled_at'], 11, 5), db_all(
        "SELECT scheduled_at FROM appointments WHERE scheduled_at BETWEEN ? AND ? AND status NOT IN ('canceled')",
        ["$date 00:00:00", "$date 23:59:59"]
    ));
    $slots = [];
    for ($m = 8 * 60; $m + $step <= 18 * 60; $m += $step) {
        if ($m >= 12 * 60 && $m < 13 * 60) continue; // lunch
        $time = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        $past = $date === today() && strtotime("$date $time") < time() + 3600;
        $slots[] = ['time' => $time, 'available' => !$past && !in_array($time, $taken, true)];
    }
    return $slots;
}

/* ================================================================ DISPATCH */

foreach ($routes as [$m, $regex, $handler]) {
    if ($m !== $method) continue;
    if (preg_match($regex, $path, $matches)) {
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        $handler($params);
        json_error('Resposta vazia.', 500);
    }
}
json_error('Rota não encontrada.', 404);
