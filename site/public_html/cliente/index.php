<?php
declare(strict_types=1);

/**
 * Customer portal: overview, projects (stages/tasks, approvals, files), billing (charges + NFS-e),
 * support tickets (open/reply with attachments, rating) and account (profile, address, password, LGPD).
 * Login lives in /entrar (inc/account.php).
 */

require dirname(__DIR__) . '/inc/layout/site.php';
require_once INC_PATH . '/account.php';
require_once INC_PATH . '/resources.php';
require_once INC_PATH . '/nfse.php';

$customer = current_customer();
if (!$customer) {
    header('Location: /entrar?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/cliente/'));
    exit;
}
$cid = (int)$customer['id'];
$tab = preg_replace('/[^a-z]/', '', (string)($_GET['aba'] ?? 'inicio')) ?: 'inicio';
$flash = ['ok' => null, 'err' => null];
$uploadsEnabled = setting('portal_uploads_enabled', '1') === '1';

/* ---------------------------------------------------------------- actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!verify_csrf($_POST['csrf'] ?? null)) throw new AppException('Sessão expirada. Recarregue a página e tente novamente.');
        $row = db_find('customers', $cid);
        switch ($action) {
            case 'password':
                $tab = 'conta';
                if ($row['portal_password_hash'] && !password_verify((string)($_POST['current'] ?? ''), $row['portal_password_hash'])) throw new AppException('Senha atual incorreta.');
                if (($_POST['new'] ?? '') !== ($_POST['confirm'] ?? '')) throw new AppException('As senhas não conferem.');
                if ($problem = password_problem((string)$_POST['new'])) throw new AppException($problem);
                db_update('customers', $cid, ['portal_password_hash' => password_hash((string)$_POST['new'], PASSWORD_DEFAULT), 'updated_at' => now()]);
                auth_tokens_revoke('customer', $cid, ['remember', 'reset', 'invite']);
                $flash['ok'] = 'Senha salva com sucesso.';
                break;

            case 'profile':
                $tab = 'conta';
                $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 30);
                $data = [
                    'trade_name' => mb_substr(trim((string)($_POST['trade_name'] ?? '')), 0, 160) ?: null,
                    'phone' => $phone ?: null,
                    'postal_code' => mb_substr(only_digits((string)($_POST['postal_code'] ?? '')), 0, 8) ?: null,
                    'address' => mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255) ?: null,
                    'address_number' => mb_substr(trim((string)($_POST['address_number'] ?? '')), 0, 20) ?: null,
                    'district' => mb_substr(trim((string)($_POST['district'] ?? '')), 0, 80) ?: null,
                    'city' => mb_substr(trim((string)($_POST['city'] ?? '')), 0, 80) ?: null,
                    'state' => preg_match('/^[A-Z]{2}$/', strtoupper((string)($_POST['state'] ?? ''))) ? strtoupper((string)$_POST['state']) : null,
                    'updated_at' => now(),
                ];
                if ($data['postal_code'] && ($cep = cep_lookup($data['postal_code']))) {
                    $data['city_ibge'] = $cep['ibge'];
                    $data['city'] = $data['city'] ?: $cep['localidade'];
                    $data['state'] = $data['state'] ?: $cep['uf'];
                }
                $doc = only_digits((string)($_POST['document'] ?? ''));
                if ($doc !== '' && !$row['document']) {
                    if (!in_array(strlen($doc), [11, 14], true)) throw new AppException('CPF deve ter 11 dígitos e CNPJ 14.');
                    $data['document'] = $doc;
                }
                db_update('customers', $cid, $data);
                audit('portal_profile', 'customer', $cid);
                $flash['ok'] = 'Dados atualizados. Obrigado!';
                break;

            case 'ticket_new':
                $tab = 'suporte';
                $subject = trim((string)($_POST['subject'] ?? ''));
                $message = trim((string)($_POST['message'] ?? ''));
                if (mb_strlen($subject) < 4 || mb_strlen($message) < 10) throw new AppException('Descreva o assunto e o problema com um pouco mais de detalhe.');
                if ($uploadsEnabled) attachments_validate_all('files');
                if (!throttle('portal-ticket', 10, 3600)) throw new AppException('Muitos chamados em pouco tempo. Aguarde alguns minutos.');
                $priority = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true) ? $_POST['priority'] : 'normal';
                $category = array_key_exists($_POST['category'] ?? '', ticket_categories()) ? $_POST['category'] : 'duvida';
                $projectId = (int)($_POST['project_id'] ?? 0);
                if ($projectId && !db_value('SELECT id FROM projects WHERE id = ? AND customer_id = ?', [$projectId, $cid])) $projectId = 0;
                $tid = db_insert('tickets', [
                    'protocol' => new_ticket_protocol(), 'customer_id' => $cid, 'name' => $row['trade_name'] ?: $row['name'],
                    'email' => $row['email'], 'phone' => $row['phone'], 'subject' => mb_substr($subject, 0, 200),
                    'category' => $category, 'priority' => $priority, 'status' => 'open', 'sla_due_at' => ticket_sla_due($priority),
                    'source' => 'portal', 'project_id' => $projectId ?: null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $ticket = db_find('tickets', $tid);
                $mid = ticket_add_message($ticket, 'customer', $row['trade_name'] ?: $row['name'], $message);
                if ($uploadsEnabled) attachments_store_all('files', 'ticket', $tid, $mid, true, 'customer', $row['trade_name'] ?: $row['name']);
                mail_event_ticket_opened($ticket, $message);
                header('Location: /cliente/?aba=suporte&chamado=' . $tid . '&ok=aberto');
                exit;

            case 'ticket_reply':
                $tab = 'suporte';
                $ticket = db_one('SELECT * FROM tickets WHERE id = ? AND customer_id = ?', [(int)($_POST['ticket_id'] ?? 0), $cid]);
                if (!$ticket) throw new AppException('Chamado não encontrado.');
                if ($ticket['status'] === 'closed') throw new AppException('Este chamado está encerrado. Abra um novo chamado.');
                $body = trim((string)($_POST['message'] ?? ''));
                $hasFiles = $uploadsEnabled && uploaded_files('files');
                if ($hasFiles) attachments_validate_all('files');
                if ($body === '' && !$hasFiles) throw new AppException('Escreva sua mensagem.');
                $who = $row['trade_name'] ?: $row['name'];
                $mid = ticket_add_message($ticket, 'customer', $who, $body !== '' ? $body : '(arquivo anexado)');
                $files = $hasFiles ? attachments_store_all('files', 'ticket', (int)$ticket['id'], $mid, true, 'customer', $who) : [];
                if ($ticket['status'] !== 'open') ticket_change_status($ticket, 'open', $who, false);
                db_update('tickets', (int)$ticket['id'], ['updated_at' => now()]);
                mail_event_ticket_customer_reply($ticket, mb_substr($body, 0, 8000) . ($files ? "\n\n[" . count($files) . ' arquivo(s) anexado(s)]' : ''));
                header('Location: /cliente/?aba=suporte&chamado=' . $ticket['id'] . '&ok=resposta');
                exit;

            case 'ticket_close':
                $tab = 'suporte';
                $ticket = db_one('SELECT * FROM tickets WHERE id = ? AND customer_id = ?', [(int)($_POST['ticket_id'] ?? 0), $cid]);
                if (!$ticket) throw new AppException('Chamado não encontrado.');
                ticket_add_message($ticket, 'customer', $row['trade_name'] ?: $row['name'], 'Marquei este chamado como resolvido. Obrigado!');
                ticket_change_status($ticket, 'resolved', $row['trade_name'] ?: $row['name'], false);
                header('Location: /cliente/?aba=suporte&chamado=' . $ticket['id'] . '&ok=resolvido');
                exit;

            case 'ticket_rate':
                $tab = 'suporte';
                $ticket = db_one('SELECT * FROM tickets WHERE id = ? AND customer_id = ?', [(int)($_POST['ticket_id'] ?? 0), $cid]);
                if (!$ticket) throw new AppException('Chamado não encontrado.');
                ticket_rate($ticket, (int)($_POST['score'] ?? 0), (string)($_POST['comment'] ?? ''));
                header('Location: /cliente/?aba=suporte&chamado=' . $ticket['id'] . '&ok=avaliado');
                exit;

            case 'stage_decision':
                $tab = 'projetos';
                $stage = db_one("SELECT s.* FROM project_stages s JOIN projects p ON p.id = s.project_id WHERE s.id = ? AND p.customer_id = ? AND s.status = 'review'", [(int)($_POST['stage_id'] ?? 0), $cid]);
                if (!$stage) throw new AppException('Esta etapa não está aguardando aprovação.');
                $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'changes';
                $feedback = mb_substr(trim((string)($_POST['feedback'] ?? '')), 0, 3000);
                if ($decision === 'changes' && mb_strlen($feedback) < 5) throw new AppException('Conte para a gente quais ajustes você precisa.');
                db_transaction(function () use ($stage, $decision, $feedback) {
                    db_update('project_stages', (int)$stage['id'], [
                        'client_approval' => $decision, 'client_feedback' => $feedback ?: null, 'approval_at' => now(),
                        'status' => $decision === 'approved' ? 'done' : 'in_progress',
                        'completed_at' => $decision === 'approved' ? now() : null,
                    ]);
                    if ($decision === 'approved') {
                        $next = db_one("SELECT * FROM project_stages WHERE project_id = ? AND position > ? AND status = 'pending' ORDER BY position LIMIT 1", [$stage['project_id'], $stage['position']]);
                        if ($next) db_update('project_stages', (int)$next['id'], ['status' => 'in_progress', 'start_date' => $next['start_date'] ?: today()]);
                        if ($next) db_exec("UPDATE projects SET status = 'active' WHERE id = ? AND status IN ('proposal','approved')", [$stage['project_id']]);
                    }
                    db_update('projects', (int)$stage['project_id'], ['updated_at' => now()]);
                });
                activity_add('project', (int)$stage['project_id'], 'event', ($decision === 'approved' ? 'Cliente APROVOU a etapa "' : 'Cliente pediu AJUSTES na etapa "') . $stage['name'] . '"' . ($feedback ? ": $feedback" : ''), null, ['id' => null, 'name' => $row['trade_name'] ?: $row['name']]);
                mail_event_stage_decision($stage, $row, $decision, $feedback);
                header('Location: /cliente/?aba=projetos&ok=' . ($decision === 'approved' ? 'aprovado' : 'ajustes') . '#etapa-' . $stage['id']);
                exit;

            case 'project_upload':
                $tab = 'projetos';
                if (!$uploadsEnabled) throw new AppException('O envio de arquivos está desativado.');
                $pid = (int)($_POST['project_id'] ?? 0);
                if (!db_value('SELECT id FROM projects WHERE id = ? AND customer_id = ?', [$pid, $cid])) throw new AppException('Projeto não encontrado.');
                $files = attachments_store_all('files', 'project', $pid, null, true, 'customer', $row['trade_name'] ?: $row['name']);
                if (!$files) throw new AppException('Selecione ao menos um arquivo.');
                activity_add('project', $pid, 'event', 'Cliente enviou arquivo(s): ' . implode(', ', array_column($files, 'file_name')), null, ['id' => null, 'name' => $row['trade_name'] ?: $row['name']]);
                mail_team('[Projeto] Cliente enviou arquivos — ' . db_value('SELECT name FROM projects WHERE id = ?', [$pid]), mail_template('Novos arquivos do cliente', mail_details(['Cliente' => $row['name'], 'Arquivos' => implode("\n", array_column($files, 'file_name'))]), ['label' => 'Abrir projeto', 'url' => app_link('/admin/#/projects/' . $pid)]), ['event' => 'portal_upload']);
                header('Location: /cliente/?aba=projetos&ok=arquivos#projeto-' . $pid);
                exit;

            case 'lgpd':
                $tab = 'conta';
                $kind = ($_POST['kind'] ?? '') === 'delete' ? 'exclusão' : 'cópia';
                if (!throttle('portal-lgpd', 3, 86400)) throw new AppException('Você já fez uma solicitação recentemente. Nossa equipe vai responder por e-mail.');
                mail_team("LGPD: pedido de $kind de dados — {$row['name']}", mail_template("Solicitação LGPD ($kind de dados)", mail_details(['Cliente' => $row['name'], 'E-mail' => $row['email'], 'Pedido' => $kind === 'exclusão' ? 'Exclusão dos dados pessoais' : 'Cópia dos dados pessoais', 'Observação' => mb_substr((string)($_POST['note'] ?? ''), 0, 1000)]) . '<p>Prazo legal de resposta: 15 dias.</p>'), ['event' => 'lgpd', 'reply_to' => $row['email']]);
                audit('lgpd_request', 'customer', $cid, ['kind' => $kind]);
                $flash['ok'] = 'Solicitação registrada. Vamos responder no seu e-mail em até 15 dias.';
                break;
        }
    } catch (AppException $e) {
        $flash['err'] = $e->getMessage();
    }
}
$okMessages = ['aberto' => 'Chamado aberto! Você receberá as respostas por e-mail e aqui.', 'resposta' => 'Mensagem enviada para a nossa equipe.', 'resolvido' => 'Chamado marcado como resolvido.',
    'avaliado' => 'Obrigado pela avaliação! 💙', 'aprovado' => 'Etapa aprovada. Obrigado! Já seguimos para a próxima fase.', 'ajustes' => 'Pedido de ajustes enviado. Nossa equipe vai retornar em breve.', 'arquivos' => 'Arquivo(s) enviado(s) para a equipe.'];
if (!$flash['ok'] && isset($okMessages[$_GET['ok'] ?? ''])) $flash['ok'] = $okMessages[$_GET['ok']];

/* ------------------------------------------------------------------- data */
$me = db_find('customers', $cid);
$display = trim($me['trade_name'] ?: $me['name']);
$firstName = mb_strlen($display) <= 18 ? $display : explode(' ', $display)[0];
$projects = db_all("SELECT * FROM projects WHERE customer_id = ? AND status != 'canceled' ORDER BY status = 'done', COALESCE(due_date, '9999-12-31')", [$cid]);
$stages = $tasks = [];
if ($projects) {
    $ph = implode(',', array_fill(0, count($projects), '?'));
    $ids = array_column($projects, 'id');
    foreach (db_all("SELECT * FROM project_stages WHERE project_id IN ($ph) ORDER BY position", $ids) as $r) $stages[$r['project_id']][] = $r;
    foreach (db_all("SELECT * FROM project_tasks WHERE project_id IN ($ph) ORDER BY done, position, id", $ids) as $r) $tasks[$r['project_id']][] = $r;
}
$progress = fn(array $p): int => project_progress($p['status'], $stages[$p['id']] ?? [], $tasks[$p['id']] ?? []);
$projectFiles = [];
foreach ($projects as $p) $projectFiles[$p['id']] = attachments_for('project', (int)$p['id'], true);
$pendingApprovals = [];
foreach ($projects as $p) foreach ($stages[$p['id']] ?? [] as $s) if ($s['status'] === 'review' && in_array($s['client_approval'], [null, '', 'pending'], true)) $pendingApprovals[] = $s + ['project_name' => $p['name']];
$charges = db_all("SELECT * FROM charges WHERE customer_id = ? AND status NOT IN ('CREATING','DELETED') ORDER BY CASE WHEN status IN ('PENDING','OVERDUE') THEN 0 ELSE 1 END, due_date DESC LIMIT 60", [$cid]);
$paidSt = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];
$openCharges = array_values(array_filter($charges, fn($c) => in_array($c['status'], ['PENDING', 'OVERDUE'], true)));
$overdue = array_values(array_filter($openCharges, fn($c) => $c['status'] === 'OVERDUE' || $c['due_date'] < today()));
$openTotal = array_sum(array_map(fn($c) => (float)$c['amount'], $openCharges));
$paidYear = (float)db_value("SELECT COALESCE(SUM(amount),0) FROM charges WHERE customer_id = ? AND status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH') AND paid_at >= ?", [$cid, date('Y') . '-01-01']);
$nextDue = null;
foreach ($openCharges as $c) if (!$nextDue || $c['due_date'] < $nextDue['due_date']) $nextDue = $c;
try {
    $contracts = db_all("SELECT * FROM contracts WHERE customer_id = ? AND status IN ('active','paused') ORDER BY start_date DESC", [$cid]);
} catch (Throwable $e) {
    $contracts = [];
}
$invoices = db_all("SELECT id, nfse_number, amount, description, issued_at, status, print_url, xml_nfse IS NOT NULL AS has_xml FROM nfse_invoices WHERE customer_id = ? AND status IN ('authorized','canceled') ORDER BY issued_at DESC LIMIT 60", [$cid]);
$tickets = db_all("SELECT t.*, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id AND m.internal = 0 AND m.kind = 'message') AS msgs FROM tickets t WHERE customer_id = ? ORDER BY updated_at DESC LIMIT 60", [$cid]);
$openTickets = array_filter($tickets, fn($t) => !in_array($t['status'], ['resolved', 'closed'], true));
$ticketId = (int)($_GET['chamado'] ?? 0);
$ticketView = $ticketId ? db_one('SELECT * FROM tickets WHERE id = ? AND customer_id = ?', [$ticketId, $cid]) : null;
$ticketMsgs = $ticketView ? db_all("SELECT * FROM ticket_messages WHERE ticket_id = ? AND internal = 0 AND kind = 'message' ORDER BY id", [$ticketView['id']]) : [];
$msgFiles = [];
if ($ticketView) foreach (attachments_for('ticket', (int)$ticketView['id'], true) as $a) $msgFiles[(int)$a['message_id']][] = $a;
$rateScore = max(0, min(5, (int)($_GET['avaliar'] ?? 0)));
$ticketFilter = in_array($_GET['filtro'] ?? '', ['abertos', 'resolvidos'], true) ? $_GET['filtro'] : 'todos';
if ($ticketView) $tab = 'suporte';

// Activity feed (most recent first)
$activity = [];
foreach ($projects as $p) foreach ($stages[$p['id']] ?? [] as $s) if ($s['completed_at']) $activity[] = [$s['completed_at'], 'check', 'Etapa concluída: ' . $s['name'], $p['name']];
foreach ($charges as $c) if (in_array($c['status'], $paidSt, true) && $c['paid_at']) $activity[] = [$c['paid_at'] . ' 12:00:00', 'cash', 'Pagamento confirmado: ' . money($c['amount']), (string)$c['description']];
foreach ($invoices as $n) if ($n['issued_at']) $activity[] = [$n['issued_at'], 'file', 'Nota fiscal emitida' . ($n['nfse_number'] ? ' nº ' . $n['nfse_number'] : ''), money($n['amount'])];
foreach (db_all("SELECT m.created_at, t.protocol, t.subject FROM ticket_messages m JOIN tickets t ON t.id = m.ticket_id WHERE t.customer_id = ? AND m.author_type = 'staff' AND m.internal = 0 AND m.kind = 'message' ORDER BY m.id DESC LIMIT 10", [$cid]) as $m) $activity[] = [$m['created_at'], 'chat', 'Nova resposta da equipe', $m['protocol'] . ' · ' . $m['subject']];
usort($activity, fn($a, $b) => strcmp($b[0], $a[0]));
$activity = array_slice($activity, 0, 8);

$chargeLabel = ['PENDING' => ['Em aberto', 'open'], 'OVERDUE' => ['Vencida', 'late'], 'RECEIVED' => ['Paga', 'ok'], 'CONFIRMED' => ['Paga', 'ok'], 'RECEIVED_IN_CASH' => ['Paga', 'ok'], 'REFUNDED' => ['Estornada', ''], 'REFUND_REQUESTED' => ['Estorno solicitado', '']];
$ticketLabel = ['open' => ['Aberto', 'open'], 'in_progress' => ['Em atendimento', 'warn'], 'waiting' => ['Aguardando você', 'late'], 'waiting_third' => ['Aguardando terceiros', 'warn'], 'on_hold' => ['Pausado', ''], 'resolved' => ['Resolvido', 'ok'], 'closed' => ['Encerrado', '']];
$projectPill = ['done' => 'ok', 'paused' => 'warn', 'waiting_client' => 'late', 'review' => 'warn', 'canceled' => '', 'proposal' => '', 'approved' => 'open', 'maintenance' => 'ok'];
$stageText = function (array $s) use (&$date): string {
    switch ($s['status']) {
        case 'done': return 'Concluída' . ($s['completed_at'] ? ' em ' . $date($s['completed_at']) : '') . ($s['client_approval'] === 'approved' ? ' · aprovada por você' : '');
        case 'skipped': return 'Dispensada';
        case 'in_progress': return 'Em andamento' . ($s['due_date'] ? ' · prazo ' . $date($s['due_date']) : '') . ($s['client_approval'] === 'changes' ? ' · ajustando conforme seu pedido' : '');
        case 'waiting_client': return 'Aguardando informações suas';
        case 'review': return 'Pronta para a sua aprovação';
        case 'blocked': return 'Temporariamente bloqueada';
        default: return 'Próxima etapa';
    }
};
$fileIcon = fn(string $name) => preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $name) ? 'eye' : 'file';
$pill = fn(array $map, string $k) => '<span class="pill ' . e($map[$k][1] ?? '') . '">' . e($map[$k][0] ?? $k) . '</span>';
$date = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '—';
$ago = function (string $d): string {
    $s = time() - strtotime($d);
    if ($s < 3600) return 'há ' . max(1, intdiv($s, 60)) . ' min';
    if ($s < 86400) return 'há ' . intdiv($s, 3600) . ' h';
    if ($s < 86400 * 30) return 'há ' . intdiv($s, 86400) . ' dia(s)';
    return date('d/m/Y', strtotime($d));
};
try {
    $fhSub = db_one("SELECT s.*, p.name AS plan_name FROM fh_subscriptions s LEFT JOIN fh_plans p ON p.code = s.plan_code WHERE s.customer_id = ? AND s.status != 'canceled' ORDER BY s.id DESC LIMIT 1", [$cid]);
} catch (Throwable $e) {
    $fhSub = null;
}
$tabs = ['inicio' => ['home', 'Visão geral'], 'projetos' => ['layers', 'Projetos'], 'financeiro' => ['cash', 'Financeiro'], 'suporte' => ['ticket', 'Suporte'], 'conta' => ['user', 'Minha conta']];
if (!isset($tabs[$tab])) $tab = 'inicio';
$badges = ['projetos' => count($pendingApprovals), 'financeiro' => count($overdue) ?: count($openCharges), 'suporte' => count(array_filter($tickets, fn($t) => $t['status'] === 'waiting'))];
$csrf = csrf_token();

page_start(['title' => 'Área do cliente', 'active' => '/cliente/', 'description' => 'Acompanhe seus projetos, faturas, notas fiscais e chamados com a Integra Code.', 'head' => '<meta name="robots" content="noindex">', 'body_class' => 'portal-page']);
?>
<section class="portal">
  <div class="container portal-grid">
    <aside class="portal-side" aria-label="Menu da área do cliente">
      <div class="portal-user">
        <span class="portal-avatar"><?= e(mb_strtoupper(mb_substr($firstName, 0, 1))) ?></span>
        <div><b><?= e($me['trade_name'] ?: $me['name']) ?></b><small><?= e($me['email']) ?></small></div>
      </div>
      <nav class="portal-nav" role="tablist">
        <?php foreach ($tabs as $k => [$ico, $label]): ?>
          <a href="/cliente/?aba=<?= $k ?>" data-tab="<?= $k ?>" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= icon($ico) ?><span><?= e($label) ?></span><?php if (!empty($badges[$k])): ?><em><?= (int)$badges[$k] ?></em><?php endif; ?></a>
        <?php endforeach; ?>
      </nav>
      <a class="portal-fh-link" href="/cliente/fiscal/"><?= icon('file') ?><span><b>Fiscal Hub</b><small><?= $fhSub ? 'Emitir notas fiscais' : 'Emissor de NFS-e' ?></small></span></a>
      <div class="portal-side-foot">
        <a href="<?= e(COMPANY['whatsapp']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-block"><?= icon('whatsapp') ?> Falar no WhatsApp</a>
        <form method="post" action="/sair"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><button class="btn btn-ghost btn-sm btn-block">Sair da conta</button></form>
      </div>
    </aside>

    <div class="portal-main">
      <?php if ($flash['ok']): ?><div class="auth-alert success" role="status"><?= e($flash['ok']) ?></div><?php endif; ?>
      <?php if ($flash['err']): ?><div class="auth-alert error" role="alert"><?= e($flash['err']) ?></div><?php endif; ?>
      <?php if (isset($_GET['bemvindo'])): ?><div class="auth-alert success">Bem-vindo(a) à Área do Cliente, <?= e($firstName) ?>! 🎉 Aqui você acompanha seus projetos, faturas, notas fiscais e chamados.</div><?php endif; ?>

      <!-- ============================================================ INÍCIO -->
      <section class="portal-panel <?= $tab === 'inicio' ? 'active' : '' ?>" data-panel="inicio" role="tabpanel">
        <header class="portal-head">
          <div><span class="kicker">Área do cliente</span><h1>Olá, <span class="grad-text"><?= e($firstName) ?></span> 👋</h1><p class="muted">Tudo sobre o seu relacionamento com a Integra Code, em um só lugar.</p></div>
          <a href="/cliente/?aba=suporte#novo" data-tab-link="suporte" class="btn btn-primary"><?= icon('ticket') ?> Abrir chamado</a>
        </header>

        <?php if ($overdue): ?>
          <div class="portal-alert late"><?= icon('clock') ?><div><b>Você tem <?= count($overdue) ?> fatura(s) vencida(s)</b> somando <?= e(money(array_sum(array_map(fn($c) => (float)$c['amount'], $overdue)))) ?>. Regularize para evitar juros.</div><a href="/cliente/?aba=financeiro" data-tab-link="financeiro" class="btn btn-sm btn-primary">Ver faturas</a></div>
        <?php elseif ($nextDue): ?>
          <div class="portal-alert"><?= icon('calendar') ?><div>Próxima fatura: <b><?= e(money($nextDue['amount'])) ?></b> com vencimento em <b><?= e($date($nextDue['due_date'])) ?></b>.</div><?php if ($nextDue['invoice_url']): ?><a href="<?= e($nextDue['invoice_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary">Pagar agora</a><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($pendingApprovals): $pa = $pendingApprovals[0]; ?>
          <div class="portal-alert warn"><?= icon('check') ?><div><b><?= count($pendingApprovals) ?> etapa(s) aguardando a sua aprovação</b> — <?= e($pa['name']) ?> (<?= e($pa['project_name']) ?>).</div><a class="btn btn-sm btn-primary" href="/cliente/?aba=projetos#etapa-<?= (int)$pa['id'] ?>">Revisar</a></div>
        <?php endif; ?>
        <?php foreach ($tickets as $t) if ($t['status'] === 'waiting'): ?>
          <div class="portal-alert warn"><?= icon('chat') ?><div>Nossa equipe respondeu o chamado <b><?= e($t['protocol']) ?></b> e aguarda seu retorno.</div><a class="btn btn-sm btn-ghost" href="/cliente/?chamado=<?= (int)$t['id'] ?>">Responder</a></div>
        <?php break; endif; ?>

        <div class="portal-kpis">
          <a class="portal-kpi" href="/cliente/?aba=projetos" data-tab-link="projetos"><span class="k-ico"><?= icon('layers') ?></span><small>Projetos em andamento</small><b><?= count(array_filter($projects, fn($p) => in_array($p['status'], PROJECT_RUNNING, true))) ?></b></a>
          <a class="portal-kpi" href="/cliente/?aba=financeiro" data-tab-link="financeiro"><span class="k-ico <?= $overdue ? 'late' : '' ?>"><?= icon('cash') ?></span><small>Em aberto</small><b><?= e(money($openTotal)) ?></b></a>
          <a class="portal-kpi" href="/cliente/?aba=financeiro" data-tab-link="financeiro"><span class="k-ico ok"><?= icon('check') ?></span><small>Pago em <?= date('Y') ?></small><b><?= e(money($paidYear)) ?></b></a>
          <a class="portal-kpi" href="/cliente/?aba=suporte" data-tab-link="suporte"><span class="k-ico"><?= icon('ticket') ?></span><small>Chamados abertos</small><b><?= count($openTickets) ?></b></a>
        </div>

        <div class="portal-cols">
          <div class="portal-card">
            <h2><?= icon('layers') ?> Seu projeto</h2>
            <?php $main = $projects[0] ?? null; if ($main): $pct = $progress($main); $cur = null; foreach ($stages[$main['id']] ?? [] as $s) if (in_array($s['status'], STAGE_CURRENT, true)) { $cur = $s; break; } ?>
              <div class="proj-mini">
                <div class="ring" style="--p:<?= $pct ?>"><span><?= $pct ?>%</span></div>
                <div><b><?= e($main['name']) ?></b><small class="muted"><?= $main['status'] === 'done' ? 'Concluído 🎉' : 'Etapa atual: ' . e($cur['name'] ?? '—') ?><?= $main['due_date'] ? ' · previsão ' . e($date($main['due_date'])) : '' ?></small>
                  <div class="stage-dots"><?php foreach ($stages[$main['id']] ?? [] as $s): ?><i class="<?= e($s['status']) ?>" title="<?= e($s['name']) ?>"></i><?php endforeach; ?></div></div>
              </div>
              <a href="/cliente/?aba=projetos" data-tab-link="projetos" class="more">Ver detalhes do projeto <?= icon('arrow') ?></a>
            <?php else: ?>
              <div class="portal-empty"><?= icon('rocket', 'ico ico-lg') ?><p>Assim que iniciarmos um projeto com você, as etapas e o progresso aparecem aqui.</p><a href="/agendar" class="btn btn-sm btn-ghost">Agendar uma conversa</a></div>
            <?php endif; ?>
          </div>
          <div class="portal-card">
            <h2><?= icon('clock') ?> Atividade recente</h2>
            <?php if ($activity): ?>
              <ul class="feed"><?php foreach ($activity as [$when, $ico, $title, $sub]): ?><li><span class="feed-ico"><?= icon($ico) ?></span><div><b><?= e($title) ?></b><small><?= e($sub) ?> · <?= e($ago($when)) ?></small></div></li><?php endforeach; ?></ul>
            <?php else: ?>
              <div class="portal-empty"><?= icon('sparkles', 'ico ico-lg') ?><p>Nenhuma atividade ainda. Pagamentos, etapas concluídas e respostas da equipe aparecem aqui.</p></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="portal-card portal-fh">
          <?php if ($fhSub): ?>
            <div><h2><?= icon('file') ?> Integra Fiscal Hub</h2><p class="muted">Plano <?= e($fhSub['plan_name'] ?: $fhSub['plan_code']) ?> · <?= $fhSub['status'] === 'pending' ? 'aguardando pagamento' : ($fhSub['paid_until'] ? 'liberado até ' . e($date($fhSub['paid_until'])) : 'ativo') ?></p></div>
            <a class="btn btn-primary" href="/cliente/fiscal/"><?= $fhSub['status'] === 'pending' ? 'Pagar e liberar' : 'Emitir nota fiscal' ?> <?= icon('arrow') ?></a>
          <?php else: ?>
            <div><h2><?= icon('file') ?> Emita suas notas fiscais de serviço aqui</h2><p class="muted">Conheça o Integra Fiscal Hub: SIGISS de Marília e Emissor Nacional, cálculo de impostos e relatórios com IA. Contratação online.</p></div>
            <a class="btn btn-ghost" href="/fiscal-hub">Conhecer o Fiscal Hub <?= icon('arrow') ?></a>
          <?php endif; ?>
        </div>

        <div class="portal-shortcuts">
          <a href="/cliente/?aba=financeiro" data-tab-link="financeiro"><?= icon('file') ?><span>2ª via de fatura</span></a>
          <a href="/cliente/?aba=financeiro#notas" data-tab-link="financeiro"><?= icon('book') ?><span>Notas fiscais</span></a>
          <a href="/suporte"><?= icon('help') ?><span>Central de ajuda</span></a>
          <a href="/agendar"><?= icon('calendar') ?><span>Agendar reunião</span></a>
        </div>
      </section>

      <!-- ============================================================ PROJETOS -->
      <section class="portal-panel <?= $tab === 'projetos' ? 'active' : '' ?>" data-panel="projetos" role="tabpanel">
        <header class="portal-head"><div><span class="kicker">Projetos</span><h1>Acompanhe cada etapa</h1><p class="muted">Transparência total: veja em que ponto está cada entrega.</p></div></header>
        <?php if (!$projects): ?>
          <div class="portal-card portal-empty"><?= icon('rocket', 'ico ico-lg') ?><p>Você ainda não tem projetos com a gente. Que tal conversar sobre o próximo?</p><a href="/diagnostico" class="btn btn-primary btn-sm">Fazer diagnóstico gratuito</a></div>
        <?php endif; ?>
        <?php foreach ($projects as $p): $pct = $progress($p); $st = $stages[$p['id']] ?? []; $tk = $tasks[$p['id']] ?? []; ?>
          <article class="portal-card project" id="projeto-<?= (int)$p['id'] ?>">
            <div class="project-head">
              <div><h2><?= e($p['name']) ?></h2><small class="muted"><?= e($p['project_type'] ?: 'Projeto') ?><?= $p['start_date'] ? ' · início ' . e($date($p['start_date'])) : '' ?><?= $p['due_date'] ? ' · previsão de entrega ' . e($date($p['due_date'])) : '' ?><?= $p['manager'] ? ' · responsável: ' . e($p['manager']) : '' ?></small></div>
              <span class="pill <?= e($projectPill[$p['status']] ?? 'open') ?>"><?= e(status_label('project', $p['status'])) ?></span>
            </div>
            <div class="bar"><i style="width:<?= $pct ?>%"></i></div><small class="muted"><?= $pct ?>% concluído</small>
            <?php if ($p['description']): ?><p class="muted" style="margin:14px 0 0"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
            <ol class="steps">
              <?php foreach ($st as $i => $s): $stTasks = array_filter($tk, fn($t) => (int)$t['stage_id'] === (int)$s['id'] && (int)($t['client_visible'] ?? 1)); ?>
                <li class="<?= e($s['status']) ?>" id="etapa-<?= (int)$s['id'] ?>">
                  <span class="n"><?= in_array($s['status'], STAGE_FINISHED, true) ? icon('check') : $i + 1 ?></span>
                  <div><b><?= e($s['name']) ?></b>
                    <small><?= e($stageText($s)) ?></small>
                    <?php if ($stTasks && $s['status'] !== 'pending'): ?>
                      <ul class="tasks"><?php foreach ($stTasks as $t): ?><li class="<?= (int)$t['done'] ? 'done' : '' ?>"><?= (int)$t['done'] ? icon('check') : '<i></i>' ?><?= e($t['title']) ?></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($s['status'] === 'review' && in_array($s['client_approval'], [null, '', 'pending'], true)): ?>
                      <form method="post" class="approval" data-approval>
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="stage_decision"><input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
                        <p><b>Esta etapa está pronta para a sua validação.</b> Revise a entrega e aprove, ou peça ajustes.</p>
                        <textarea name="feedback" rows="2" placeholder="Comentário (obrigatório se pedir ajustes)" aria-label="Comentário"></textarea>
                        <div class="approval-actions"><button class="btn btn-primary btn-sm" name="decision" value="approve"><?= icon('check') ?> Aprovar etapa</button><button class="btn btn-ghost btn-sm" name="decision" value="changes">Pedir ajustes</button></div>
                      </form>
                    <?php elseif ($s['client_feedback'] && $s['client_approval'] === 'changes'): ?>
                      <p class="approval-note">Seu pedido de ajustes: “<?= e($s['client_feedback']) ?>”</p>
                    <?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
            <div class="project-files">
              <h3><?= icon('file') ?> Arquivos do projeto</h3>
              <?php if ($projectFiles[$p['id']]): ?>
                <ul class="file-list"><?php foreach ($projectFiles[$p['id']] as $f): ?>
                  <li><a href="/cliente/arquivo.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= icon($fileIcon($f['file_name'])) ?><span><b><?= e($f['file_name']) ?></b><small class="muted"><?= e(human_size((int)$f['size_bytes'])) ?> · <?= $f['uploaded_by_type'] === 'customer' ? 'enviado por você' : 'enviado pela equipe' ?> · <?= e($date($f['created_at'])) ?></small></span></a></li>
                <?php endforeach; ?></ul>
              <?php else: ?><p class="muted small-text">Contratos, entregas e materiais compartilhados aparecem aqui.</p><?php endif; ?>
              <?php if ($uploadsEnabled && $p['status'] !== 'canceled'): ?>
                <form method="post" enctype="multipart/form-data" class="upload-inline">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="project_upload"><input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
                  <label class="file-pick"><input type="file" name="files[]" multiple required data-file-input><span data-file-label><?= icon('upload') ?> Enviar arquivos para a equipe</span></label>
                  <button class="btn btn-sm btn-ghost">Enviar</button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <!-- ============================================================ FINANCEIRO -->
      <section class="portal-panel <?= $tab === 'financeiro' ? 'active' : '' ?>" data-panel="financeiro" role="tabpanel">
        <header class="portal-head"><div><span class="kicker">Financeiro</span><h1>Faturas e notas fiscais</h1><p class="muted">Pague por PIX, boleto ou cartão e baixe suas notas fiscais.</p></div></header>
        <div class="portal-kpis three">
          <div class="portal-kpi"><span class="k-ico <?= $overdue ? 'late' : '' ?>"><?= icon('cash') ?></span><small>Em aberto</small><b><?= e(money($openTotal)) ?></b></div>
          <div class="portal-kpi"><span class="k-ico"><?= icon('calendar') ?></span><small>Próximo vencimento</small><b><?= $nextDue ? e($date($nextDue['due_date'])) : '—' ?></b></div>
          <div class="portal-kpi"><span class="k-ico ok"><?= icon('check') ?></span><small>Pago em <?= date('Y') ?></small><b><?= e(money($paidYear)) ?></b></div>
        </div>
        <?php foreach ($contracts as $k): $kItems = json_decode((string)$k['items'], true) ?: []; ?>
          <div class="portal-card plan-card">
            <div class="project-head"><div><small class="muted mono">Contrato <?= e($k['number']) ?> · desde <?= e($date($k['start_date'])) ?><?= $k['end_date'] ? ' · vigência até ' . e($date($k['end_date'])) : '' ?></small><h2><?= icon('layers') ?> Seu plano<?= $k['support_plan'] ? ' — ' . e($k['support_plan']) : '' ?></h2></div><?= $k['status'] === 'active' ? '<span class="pill ok">Ativo</span>' : '<span class="pill">Pausado</span>' ?></div>
            <div class="portal-table" role="table">
              <?php foreach ($kItems as $it): ?>
                <div class="tr" role="row"><div class="td grow"><b><?= e($it['name']) ?></b><?php if ((float)($it['qty'] ?? 1) != 1): ?><small class="muted"><?= e(rtrim(rtrim(number_format((float)$it['qty'], 2, ',', ''), '0'), ',')) ?> × <?= e(money($it['price'])) ?></small><?php endif; ?></div><div class="td amount"><?= e(money($it['monthly'] ?? $it['total'])) ?>/mês</div></div>
              <?php endforeach; ?>
              <div class="tr" role="row"><div class="td grow"><b>Mensalidade total</b><small class="muted">Vencimento todo dia <?= (int)$k['billing_day'] ?><?= $k['status'] === 'active' && $k['next_billing_date'] ? ' · próxima em ' . e($date($k['next_billing_date'])) : '' ?></small></div><div class="td amount"><b><?= e(money($k['monthly_amount'])) ?>/mês</b></div></div>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="portal-card">
          <h2><?= icon('file') ?> Faturas</h2>
          <?php if (!$charges): ?><div class="portal-empty"><p>Nenhuma fatura por enquanto.</p></div><?php else: ?>
          <div class="portal-table" role="table">
            <?php foreach ($charges as $c): $isOpen = in_array($c['status'], ['PENDING', 'OVERDUE'], true); $late = $isOpen && ($c['status'] === 'OVERDUE' || $c['due_date'] < today()); ?>
              <div class="tr" role="row">
                <div class="td grow"><b><?= e($c['description'] ?: 'Serviços Integra Code') ?></b><small class="muted"><?= $isOpen ? 'Vence ' : 'Venceu ' ?><?= e($date($c['due_date'])) ?><?= !$isOpen && $c['paid_at'] ? ' · pago em ' . e($date($c['paid_at'])) : '' ?></small></div>
                <div class="td amount"><?= e(money($c['amount'])) ?></div>
                <div class="td"><?= $late ? '<span class="pill late">Vencida</span>' : $pill($chargeLabel, $c['status']) ?></div>
                <div class="td act"><?php if ($c['invoice_url']): ?><a class="btn btn-sm <?= $isOpen ? 'btn-primary' : 'btn-ghost' ?>" href="<?= e($c['invoice_url']) ?>" target="_blank" rel="noopener"><?= $isOpen ? 'Pagar' : 'Recibo' ?></a><?php endif; ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="portal-card" id="notas">
          <h2><?= icon('book') ?> Notas fiscais de serviço</h2>
          <?php if (!$invoices): ?><div class="portal-empty"><p>As notas fiscais emitidas para você aparecem aqui para download.</p></div><?php else: ?>
          <div class="portal-table" role="table">
            <?php foreach ($invoices as $n): ?>
              <div class="tr" role="row">
                <div class="td grow"><b>NFS-e <?= $n['nfse_number'] ? 'nº ' . e($n['nfse_number']) : '' ?></b><small class="muted"><?= e(mb_substr((string)$n['description'], 0, 90)) ?> · <?= e($date($n['issued_at'])) ?></small></div>
                <div class="td amount"><?= e(money($n['amount'])) ?></div>
                <div class="td"><?= $n['status'] === 'canceled' ? '<span class="pill">Cancelada</span>' : '<span class="pill ok">Emitida</span>' ?></div>
                <div class="td act"><a class="btn btn-sm btn-ghost" href="/cliente/nota.php?id=<?= (int)$n['id'] ?>" target="_blank" rel="noopener">PDF</a><?php if ((int)$n['has_xml']): ?> <a class="btn btn-sm btn-ghost" href="/cliente/nota.php?id=<?= (int)$n['id'] ?>&xml=1">XML</a><?php endif; ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- ============================================================ SUPORTE -->
      <section class="portal-panel <?= $tab === 'suporte' ? 'active' : '' ?>" data-panel="suporte" role="tabpanel">
        <header class="portal-head"><div><span class="kicker">Suporte</span><h1>Seus chamados</h1><p class="muted">Converse com a nossa equipe técnica e acompanhe cada resposta.</p></div>
          <?php if ($ticketView): ?><a href="/cliente/?aba=suporte" class="btn btn-ghost">← Todos os chamados</a><?php endif; ?></header>

        <?php if ($ticketView): ?>
          <div class="portal-card">
            <div class="project-head"><div><small class="muted mono"><?= e($ticketView['protocol']) ?> · aberto em <?= e(date('d/m/Y H:i', strtotime($ticketView['created_at']))) ?></small><h2><?= e($ticketView['subject']) ?></h2></div><?= $pill($ticketLabel, $ticketView['status']) ?></div>
            <?php if (in_array($ticketView['status'], ['resolved', 'closed'], true) && $ticketView['satisfaction'] === null && setting('ticket_csat_enabled', '1') === '1'): ?>
              <form method="post" class="rate-box" id="avaliar">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="ticket_rate"><input type="hidden" name="ticket_id" value="<?= (int)$ticketView['id'] ?>">
                <b>Como foi o atendimento?</b>
                <div class="stars" role="radiogroup" aria-label="Nota de 1 a 5"><?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" id="st<?= $i ?>" name="score" value="<?= $i ?>" <?= $rateScore === $i ? 'checked' : '' ?> required><label for="st<?= $i ?>" title="<?= $i ?> de 5">★</label><?php endfor; ?></div>
                <input name="comment" maxlength="2000" placeholder="Quer deixar um comentário? (opcional)" aria-label="Comentário">
                <button class="btn btn-primary btn-sm">Enviar avaliação</button>
              </form>
            <?php elseif ($ticketView['satisfaction'] !== null): ?>
              <p class="rate-done">Sua avaliação: <span><?= str_repeat('★', (int)$ticketView['satisfaction']) ?><i><?= str_repeat('★', 5 - (int)$ticketView['satisfaction']) ?></i></span></p>
            <?php endif; ?>
            <div class="thread">
              <?php foreach ($ticketMsgs as $m): ?>
                <div class="msg <?= $m['author_type'] === 'staff' ? 'staff' : 'me' ?>"><header><b><?= $m['author_type'] === 'staff' ? '🛠 ' . e($m['author_name'] ?: 'Equipe Integra Code') : 'Você' ?></b><span><?= e(date('d/m/Y H:i', strtotime($m['created_at']))) ?></span></header><p><?= nl2br(e($m['body'])) ?></p>
                  <?php if (!empty($msgFiles[(int)$m['id']])): ?><div class="msg-files"><?php foreach ($msgFiles[(int)$m['id']] as $f): ?><a href="/cliente/arquivo.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= icon($fileIcon($f['file_name'])) ?><?= e($f['file_name']) ?> <small><?= e(human_size((int)$f['size_bytes'])) ?></small></a><?php endforeach; ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ($ticketView['status'] !== 'closed'): ?>
              <form method="post" class="form" style="margin-top:16px" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= (int)$ticketView['id'] ?>">
                <div class="field"><label for="tr-msg"><?= $ticketView['status'] === 'resolved' ? 'Ainda precisa de ajuda? Responda para reabrir' : 'Sua mensagem' ?></label><textarea id="tr-msg" name="message" placeholder="Escreva sua resposta..."></textarea></div>
                <?php if ($uploadsEnabled): ?><label class="file-pick"><input type="file" name="files[]" multiple data-file-input><span data-file-label><?= icon('upload') ?> Anexar prints ou arquivos (até 15 MB cada)</span></label><?php endif; ?>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                  <button class="btn btn-primary" name="action" value="ticket_reply"><?= icon('send') ?> Enviar</button>
                  <?php if (!in_array($ticketView['status'], ['resolved', 'closed'], true)): ?><button class="btn btn-ghost" name="action" value="ticket_close" formnovalidate><?= icon('check') ?> Problema resolvido</button><?php endif; ?>
                </div>
              </form>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="portal-cols">
            <div class="portal-card">
              <h2><?= icon('ticket') ?> Chamados</h2>
              <?php if ($tickets): ?><div class="chips"><?php foreach (['todos' => 'Todos', 'abertos' => 'Em aberto', 'resolvidos' => 'Resolvidos'] as $k => $l): ?><a href="/cliente/?aba=suporte&filtro=<?= $k ?>" class="<?= $ticketFilter === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></div><?php endif; ?>
              <?php if (!$tickets): ?><div class="portal-empty"><p>Nenhum chamado ainda. Precisa de ajuda? Abra um ao lado.</p></div><?php endif; ?>
              <ul class="ticket-list">
                <?php foreach ($tickets as $t): if ($ticketFilter === 'abertos' && !in_array($t['status'], TICKET_OPEN, true) || $ticketFilter === 'resolvidos' && in_array($t['status'], TICKET_OPEN, true)) continue; ?>
                  <li><a href="/cliente/?chamado=<?= (int)$t['id'] ?>"><div><b><?= e($t['subject']) ?></b><small class="muted mono"><?= e($t['protocol']) ?> · <?= e($ago($t['updated_at'])) ?> · <?= (int)$t['msgs'] ?> msg</small></div><?= $pill($ticketLabel, $t['status']) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <form method="post" class="portal-card form" id="novo" enctype="multipart/form-data">
              <h2><?= icon('send') ?> Abrir novo chamado</h2>
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="ticket_new">
              <div class="field"><label for="tn-subject">Assunto</label><input id="tn-subject" name="subject" required maxlength="200" placeholder="Ex.: Relatório não exporta"></div>
              <div class="form-row">
                <div class="field"><label for="tn-cat">Categoria</label><select id="tn-cat" name="category"><?php foreach (ticket_categories() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="tn-pri">Prioridade</label><select id="tn-pri" name="priority"><option value="low">Baixa</option><option value="normal" selected>Normal</option><option value="high">Alta</option><option value="urgent">Urgente (sistema parado)</option></select></div>
              </div>
              <?php $openProjects = array_filter($projects, fn($p) => !in_array($p['status'], ['done', 'canceled'], true)); if ($openProjects): ?>
                <div class="field"><label for="tn-proj">Sobre qual projeto? (opcional)</label><select id="tn-proj" name="project_id"><option value="">Nenhum / geral</option><?php foreach ($openProjects as $op): ?><option value="<?= (int)$op['id'] ?>"><?= e($op['name']) ?></option><?php endforeach; ?></select></div>
              <?php endif; ?>
              <div class="field"><label for="tn-msg">Descreva o que aconteceu</label><textarea id="tn-msg" name="message" required placeholder="Tela, mensagem de erro, horário..."></textarea></div>
              <?php if ($uploadsEnabled): ?><label class="file-pick"><input type="file" name="files[]" multiple data-file-input><span data-file-label><?= icon('upload') ?> Anexar prints ou arquivos (opcional)</span></label><?php endif; ?>
              <button class="btn btn-primary btn-block">Abrir chamado <?= icon('arrow') ?></button>
              <small class="muted">Primeira resposta em até <?= ticket_sla_hours('normal') ?>h (urgente: até <?= ticket_sla_hours('urgent') ?>h).</small>
            </form>
          </div>
        <?php endif; ?>
      </section>

      <!-- ============================================================ CONTA -->
      <section class="portal-panel <?= $tab === 'conta' ? 'active' : '' ?>" data-panel="conta" role="tabpanel">
        <header class="portal-head"><div><span class="kicker">Minha conta</span><h1>Seus dados</h1><p class="muted">Mantenha seus dados atualizados para faturas e notas fiscais corretas.</p></div></header>
        <div class="portal-cols">
          <form method="post" class="portal-card form" data-cep-form>
            <h2><?= icon('user') ?> Dados cadastrais</h2>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="profile">
            <div class="field"><label>Razão social / nome</label><input value="<?= e($me['name']) ?>" disabled><small class="muted">Para alterar, fale com a nossa equipe.</small></div>
            <div class="form-row">
              <div class="field"><label for="p-trade">Nome fantasia / contato</label><input id="p-trade" name="trade_name" value="<?= e($me['trade_name']) ?>"></div>
              <div class="field"><label for="p-doc">CPF/CNPJ</label><input id="p-doc" name="document" value="<?= e($me['document']) ?>" <?= $me['document'] ? 'disabled' : '' ?> inputmode="numeric"></div>
            </div>
            <div class="form-row">
              <div class="field"><label>E-mail de acesso</label><input value="<?= e($me['email']) ?>" disabled></div>
              <div class="field"><label for="p-phone">Telefone / WhatsApp</label><input id="p-phone" name="phone" value="<?= e($me['phone']) ?>" data-mask="phone" inputmode="tel"></div>
            </div>
            <div class="form-row">
              <div class="field"><label for="p-cep">CEP</label><input id="p-cep" name="postal_code" value="<?= e($me['postal_code']) ?>" inputmode="numeric" data-cep></div>
              <div class="field"><label for="p-num">Número</label><input id="p-num" name="address_number" value="<?= e($me['address_number'] ?? '') ?>"></div>
            </div>
            <div class="field"><label for="p-addr">Endereço</label><input id="p-addr" name="address" value="<?= e($me['address']) ?>"></div>
            <div class="form-row">
              <div class="field"><label for="p-dist">Bairro</label><input id="p-dist" name="district" value="<?= e($me['district'] ?? '') ?>"></div>
              <div class="field"><label for="p-city">Cidade / UF</label><div style="display:flex;gap:8px"><input id="p-city" name="city" value="<?= e($me['city']) ?>"><input name="state" value="<?= e($me['state']) ?>" maxlength="2" style="width:64px" aria-label="UF"></div></div>
            </div>
            <button class="btn btn-primary">Salvar dados</button>
          </form>
          <div>
            <form method="post" class="portal-card form">
              <h2><?= icon('lock') ?> <?= $customer['has_password'] ? 'Alterar senha' : 'Criar senha' ?></h2>
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="password">
              <input type="email" name="username" value="<?= e($me['email']) ?>" autocomplete="username" hidden>
              <?php if ($customer['has_password']): ?><div class="field"><label for="a-cur">Senha atual</label><input id="a-cur" type="password" name="current" autocomplete="current-password" required></div><?php endif; ?>
              <div class="form-row">
                <div class="field"><label for="a-new">Nova senha</label><input id="a-new" type="password" name="new" autocomplete="new-password" required minlength="8"></div>
                <div class="field"><label for="a-conf">Confirmar</label><input id="a-conf" type="password" name="confirm" autocomplete="new-password" required></div>
              </div>
              <button class="btn btn-primary">Salvar senha</button>
              <p class="muted" style="margin:0;font-size:.88rem">Login com Google: <?= $customer['google_sub'] ? '<span class="pill ok">Conectado</span>' : '<span class="pill">Não conectado</span>' ?><?= !$customer['google_sub'] && google_enabled() ? ' — saia e use "Continuar com Google" com este mesmo e-mail para conectar.' : '' ?></p>
            </form>
            <form method="post" class="portal-card form">
              <h2><?= icon('shield') ?> Privacidade (LGPD)</h2>
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="lgpd">
              <p class="muted" style="margin:0">Você pode pedir uma cópia ou a exclusão dos seus dados pessoais. Dados fiscais (faturas e notas) são mantidos pelo prazo legal.</p>
              <div class="field"><label for="l-kind">Tipo de pedido</label><select id="l-kind" name="kind"><option value="copy">Receber uma cópia dos meus dados</option><option value="delete">Excluir meus dados pessoais</option></select></div>
              <div class="field"><label for="l-note">Observação (opcional)</label><input id="l-note" name="note" maxlength="1000"></div>
              <button class="btn btn-ghost">Enviar solicitação</button>
            </form>
          </div>
        </div>
      </section>
    </div>
  </div>
</section>
<script>
(() => {
  const links = document.querySelectorAll('.portal-nav a[data-tab]');
  const panels = document.querySelectorAll('.portal-panel');
  const show = (tab, push) => {
    links.forEach((a) => { const on = a.dataset.tab === tab; a.classList.toggle('active', on); a.setAttribute('aria-selected', on); });
    panels.forEach((p) => p.classList.toggle('active', p.dataset.panel === tab));
    if (push) history.replaceState(null, '', '/cliente/?aba=' + tab);
    document.querySelector('.portal-nav a.active')?.scrollIntoView({ block: 'nearest', inline: 'center' });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  document.querySelector('.portal-nav a.active')?.scrollIntoView({ block: 'nearest', inline: 'center' });
  links.forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); show(a.dataset.tab, true); }));
  document.querySelectorAll('[data-tab-link]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault(); show(a.dataset.tabLink, true);
    const hash = a.getAttribute('href').split('#')[1];
    if (hash) setTimeout(() => document.getElementById(hash)?.scrollIntoView({ behavior: 'smooth' }), 150);
  }));
  document.querySelectorAll('[data-file-input]').forEach((inp) => inp.addEventListener('change', () => {
    const lbl = inp.parentElement.querySelector('[data-file-label]');
    const n = inp.files.length;
    if (lbl && n) lbl.lastChild.textContent = ' ' + (n === 1 ? inp.files[0].name : n + ' arquivos selecionados');
    inp.parentElement.classList.toggle('has-files', !!n);
  }));
  document.querySelectorAll('[data-approval]').forEach((f) => f.addEventListener('submit', (e) => {
    const changes = e.submitter && e.submitter.value === 'changes';
    if (changes && f.feedback.value.trim().length < 5) { e.preventDefault(); f.feedback.focus(); f.feedback.placeholder = 'Descreva os ajustes que você precisa'; }
  }));
  const cep = document.querySelector('[data-cep]');
  cep && cep.addEventListener('input', async () => {
    const d = cep.value.replace(/\D/g, '');
    if (d.length !== 8 || cep.dataset.last === d) return;
    cep.dataset.last = d;
    try {
      const r = await (await fetch('https://viacep.com.br/ws/' + d + '/json/')).json();
      if (r.erro) return;
      const f = cep.form;
      f.address.value = r.logradouro || f.address.value; f.district.value = r.bairro || f.district.value;
      f.city.value = r.localidade || f.city.value; f.state.value = r.uf || f.state.value;
      f.address_number.focus();
    } catch (e) { /* offline: fill manually */ }
  });
})();
</script>
<?php page_end();
