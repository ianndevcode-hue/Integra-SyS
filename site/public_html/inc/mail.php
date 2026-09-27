<?php
declare(strict_types=1);

/**
 * Outgoing e-mail with two providers and a DB-backed outbox:
 *   - cloudflare: Cloudflare Email Service REST API (domain must be onboarded to Email Sending)
 *   - smtp: any SMTP server, Gmail by default (PHPMailer + app password)
 *
 * mail_queue() stores the message; the outbox is flushed after the HTTP response is sent
 * (fastcgi_finish_request / litespeed_finish_request), so visitors never wait for SMTP.
 * Failed messages are retried with backoff by later requests or by cron.php.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once INC_PATH . '/vendor/PHPMailer/Exception.php';
require_once INC_PATH . '/vendor/PHPMailer/PHPMailer.php';
require_once INC_PATH . '/vendor/PHPMailer/SMTP.php';

function mail_config(): array
{
    return [
        'enabled' => setting('mail_enabled', '0') === '1',
        'provider' => setting('mail_provider', 'smtp') === 'cloudflare' ? 'cloudflare' : 'smtp',
        'cf_account' => preg_replace('/[^a-f0-9]/', '', (string)setting('cloudflare_account_id', '')),
        'cf_token' => (string)setting('mail_cf_token', '') ?: (string)setting('cloudflare_api_token', ''),
        'host' => (string)setting('mail_host', 'smtp.hostinger.com'),
        'port' => (int)setting('mail_port', 465),
        'encryption' => (string)setting('mail_encryption', 'ssl'), // tls = STARTTLS (587), ssl = SMTPS (465)
        'username' => (string)setting('mail_username', ''),
        'password' => (string)setting('mail_password', ''),
        'from_email' => (string)setting('mail_from_email', '') ?: ((string)setting('mail_username', '') ?: COMPANY['email']),
        'from_name' => (string)setting('mail_from_name', COMPANY['name']),
        'notify_to' => (string)setting('mail_notify_to', COMPANY['email']),
        'reply_to' => (string)setting('mail_reply_to', ''),
    ];
}

function mail_ready(?array $c = null): bool
{
    $c = $c ?? mail_config();
    if (!$c['enabled']) return false;
    return $c['provider'] === 'cloudflare'
        ? $c['cf_account'] !== '' && $c['cf_token'] !== '' && $c['from_email'] !== ''
        : $c['username'] !== '' && $c['password'] !== '';
}

function mail_provider_label(): string
{
    return mail_config()['provider'] === 'cloudflare' ? 'Cloudflare Email Service' : 'SMTP (' . mail_config()['host'] . ')';
}

/* ------------------------------------------------------------------ queue */

/**
 * @param array $opts ['name' => recipient name, 'reply_to' => email, 'event' => string,
 *                     'attachments' => [['name' => ..., 'content' => raw bytes, 'type' => mime]]]
 */
function mail_queue(string $to, string $subject, string $html, array $opts = []): ?int
{
    $to = trim(mb_strtolower($to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return null;
    $attachments = array_map(fn($a) => ['name' => $a['name'], 'type' => $a['type'] ?? 'application/octet-stream', 'b64' => base64_encode($a['content'])], $opts['attachments'] ?? []);
    $id = db_insert('email_outbox', [
        'event' => $opts['event'] ?? null,
        'to_email' => $to,
        'to_name' => $opts['name'] ?? null,
        'reply_to' => $opts['reply_to'] ?? null,
        'subject' => mb_substr($subject, 0, 255),
        'html' => $html,
        'text_body' => mail_html_to_text($html),
        'attachments' => $attachments ? json_encode($attachments) : null,
        'status' => 'pending', 'attempts' => 0, 'send_after' => now(), 'created_at' => now(),
    ]);
    mail_schedule_flush();
    return $id;
}

/** Notify the team inbox (mail_notify_to). */
function mail_team(string $subject, string $html, array $opts = []): void
{
    foreach (preg_split('/[\s,;]+/', mail_config()['notify_to']) as $addr) {
        if ($addr !== '') mail_queue($addr, $subject, $html, $opts);
    }
}

function mail_schedule_flush(): void
{
    static $scheduled = false;
    if ($scheduled || !mail_ready()) return;
    $scheduled = true;
    register_shutdown_function(function () {
        // Send the HTTP response first, then talk to SMTP.
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        ignore_user_abort(true);
        @set_time_limit(90);
        try { mail_flush(10); } catch (Throwable $e) { log_line('mail', 'flush failed', ['error' => $e->getMessage()]); }
    });
}

/**
 * Send due messages. Retries with backoff (1, 5, 30, 120 min); gives up after 5 attempts.
 */
function mail_flush(int $limit = 20): array
{
    if (!mail_ready()) return ['sent' => 0, 'failed' => 0, 'skipped' => 'mail disabled'];
    $cfg = mail_config();
    $rows = db_all("SELECT * FROM email_outbox WHERE status = 'pending' AND (send_after IS NULL OR send_after <= ?) ORDER BY id LIMIT $limit", [now()]);
    $sent = 0;
    $failed = 0;
    $mailer = null;
    foreach ($rows as $row) {
        // claim the row so concurrent flushes don't double-send
        if (!db_exec("UPDATE email_outbox SET status = 'sending' WHERE id = ? AND status = 'pending'", [$row['id']])) continue;
        try {
            if ($cfg['provider'] === 'cloudflare') {
                mail_send_cloudflare($row, $cfg);
            } else {
                $mailer = $mailer ?? mail_mailer(true);
                mail_send_row($mailer, $row);
            }
            db_update('email_outbox', (int)$row['id'], ['status' => 'sent', 'sent_at' => now(), 'attempts' => $row['attempts'] + 1, 'last_error' => null]);
            $sent++;
        } catch (Throwable $e) {
            $attempts = (int)$row['attempts'] + 1;
            $delays = [1, 5, 30, 120];
            $permanent = $e instanceof MailPermanentFailure;
            db_update('email_outbox', (int)$row['id'], [
                'status' => $attempts >= 5 || $permanent ? 'failed' : 'pending',
                'attempts' => $attempts,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                'send_after' => date('Y-m-d H:i:s', time() + 60 * ($delays[$attempts - 1] ?? 240)),
            ]);
            $failed++;
            log_line('mail', 'send failed', ['id' => $row['id'], 'error' => $e->getMessage()]);
            $mailer = null; // reconnect on next message
        }
    }
    if ($mailer) $mailer->smtpClose();
    return ['sent' => $sent, 'failed' => $failed];
}

function mail_mailer(bool $keepAlive = false, ?array $cfg = null): PHPMailer
{
    $cfg = $cfg ?? mail_config();
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host = $cfg['host'];
    $m->Port = $cfg['port'];
    $m->SMTPAuth = true;
    $m->Username = $cfg['username'];
    $m->Password = $cfg['password'];
    $m->SMTPSecure = $cfg['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    if ($cfg['encryption'] === 'none') { $m->SMTPSecure = ''; $m->SMTPAutoTLS = false; }
    $m->SMTPKeepAlive = $keepAlive;
    $m->Timeout = 20;
    $m->CharSet = PHPMailer::CHARSET_UTF8;
    $m->Encoding = PHPMailer::ENCODING_BASE64;
    $m->setLanguage('pt_br', INC_PATH . '/vendor/PHPMailer/');
    $m->setFrom($cfg['from_email'], $cfg['from_name']);
    $m->XMailer = 'Integra Code';
    return $m;
}

function mail_send_row(PHPMailer $m, array $row): void
{
    $m->clearAllRecipients();
    $m->clearAttachments();
    $m->clearReplyTos();
    $m->addAddress($row['to_email'], (string)$row['to_name']);
    $replyTo = $row['reply_to'] ?: mail_config()['reply_to'];
    if ($replyTo) $m->addReplyTo($replyTo);
    $m->Subject = $row['subject'];
    $m->isHTML(true);
    $m->Body = $row['html'];
    $m->AltBody = (string)$row['text_body'];
    foreach (json_decode((string)$row['attachments'], true) ?: [] as $a) {
        $m->addStringAttachment(base64_decode($a['b64']), $a['name'], PHPMailer::ENCODING_BASE64, $a['type']);
    }
    $m->send();
}

class MailPermanentFailure extends RuntimeException {}

/**
 * Cloudflare Email Service REST API.
 * https://developers.cloudflare.com/api/resources/email_sending/methods/send
 */
function mail_send_cloudflare(array $row, array $cfg): void
{
    $payload = [
        'to' => $row['to_email'],
        'from' => ['address' => $cfg['from_email'], 'name' => $cfg['from_name']],
        'subject' => $row['subject'],
        'html' => $row['html'],
        'text' => (string)$row['text_body'] ?: mail_html_to_text($row['html']),
    ];
    if ($row['reply_to'] ?: $cfg['reply_to']) $payload['reply_to'] = $row['reply_to'] ?: $cfg['reply_to'];
    $atts = json_decode((string)($row['attachments'] ?? ''), true) ?: [];
    if ($atts) {
        $payload['attachments'] = array_map(fn($a) => ['content' => $a['b64'], 'filename' => $a['name'], 'type' => $a['type'], 'disposition' => 'attachment'], $atts);
    }
    $ch = curl_init('https://api.cloudflare.com/client/v4/accounts/' . $cfg['cf_account'] . '/email/sending/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['cf_token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Falha de conexão com a Cloudflare: ' . $err);
    $json = json_decode($raw, true) ?? [];
    if ($status === 200 && !empty($json['success'])) {
        if (!empty($json['result']['permanent_bounces'])) throw new MailPermanentFailure('Endereço recusou a mensagem (bounce permanente): ' . implode(', ', $json['result']['permanent_bounces']));
        return;
    }
    $msg = trim(($json['errors'][0]['code'] ?? '') . ' ' . ($json['errors'][0]['message'] ?? ('HTTP ' . $status)));
    if ($status === 401 || $status === 403 || stripos($msg, 'Authentication') !== false) {
        throw new MailPermanentFailure('Token da Cloudflare sem permissão de envio de e-mail (Email Sending). Edite o token no painel da Cloudflare e adicione a permissão "Email Sending: Edit". Detalhe: ' . $msg);
    }
    if (stripos($msg, 'domain') !== false || stripos($msg, 'verified') !== false || stripos($msg, 'sender') !== false) {
        throw new MailPermanentFailure('O domínio do remetente (' . $cfg['from_email'] . ') ainda não está habilitado no Cloudflare Email Sending. Detalhe: ' . $msg);
    }
    if ($status === 400) throw new MailPermanentFailure('A Cloudflare recusou a mensagem: ' . $msg);
    throw new RuntimeException('Cloudflare: ' . $msg); // 429/5xx → retry
}

/**
 * Synchronous test used by the settings screen (does not use the outbox).
 * Accepts unsaved config so the admin can test before saving.
 */
function mail_test(string $to, ?array $cfg = null): void
{
    $cfg = $cfg ?? mail_config();
    if ($cfg['provider'] === 'cloudflare') {
        if ($cfg['cf_account'] === '' || $cfg['cf_token'] === '') throw new AppException('Informe o Account ID e o token da Cloudflare.');
        $html = mail_template('E-mail configurado! ✅', '<p>Se você recebeu esta mensagem, o envio de e-mails do site <b>' . e(COMPANY['domain']) . '</b> pela <b>Cloudflare Email Service</b> está funcionando.</p>');
        try {
            mail_send_cloudflare(['to_email' => $to, 'subject' => 'Teste de e-mail — ' . COMPANY['name'], 'html' => $html, 'text_body' => mail_html_to_text($html), 'reply_to' => null, 'attachments' => null], $cfg);
        } catch (RuntimeException $e) {
            throw new AppException($e->getMessage());
        }
        return;
    }
    try {
        $m = mail_mailer(false, $cfg);
        $m->addAddress($to);
        $m->Subject = 'Teste de e-mail — ' . COMPANY['name'];
        $m->isHTML(true);
        $m->Body = mail_template('E-mail configurado! ✅', '<p>Se você recebeu esta mensagem, o envio de e-mails do site <b>' . e(COMPANY['domain']) . '</b> está funcionando.</p><p>Servidor: ' . e($cfg['host']) . ':' . (int)$cfg['port'] . ' · usuário: ' . e($cfg['username']) . '</p>');
        $m->AltBody = 'O envio de e-mails está funcionando.';
        $m->send();
    } catch (MailerException $e) {
        throw new AppException(mail_friendly_error($e->getMessage(), $cfg));
    }
}

function mail_friendly_error(string $raw, array $cfg): string
{
    $isAuth = stripos($raw, 'autentic') !== false || stripos($raw, 'authenticate') !== false || stripos($raw, '535') !== false || stripos($raw, 'Username and Password not accepted') !== false;
    if ($isAuth && stripos($cfg['host'], 'gmail') === false) {
        return 'O servidor ' . $cfg['host'] . ' recusou usuário/senha. Confira se o usuário é o e-mail completo (ex.: dev@integra-code.tech) e a senha da caixa — na Hostinger você pode redefini-la em E-mails → Contas de e-mail. Detalhe: ' . $raw;
    }
    if ($isAuth) {
        return 'O Gmail recusou o login. Use uma SENHA DE APP (16 letras) gerada em myaccount.google.com/apppasswords — a senha normal da conta não funciona. Detalhe: ' . $raw;
    }
    if (stripos($raw, 'connect') !== false) {
        return 'Não foi possível conectar em ' . $cfg['host'] . ':' . $cfg['port'] . '. Confira porta/criptografia (587 + TLS ou 465 + SSL). Detalhe: ' . $raw;
    }
    return $raw;
}

/* -------------------------------------------------------------- templates */

function mail_html_to_text(string $html): string
{
    $html = preg_replace('/<(head|style|title)[^>]*>.*?<\/\1>/is', '', $html);
    $html = preg_replace('/<\/td>\s*<td[^>]*>/i', ': ', $html);
    $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|h[1-6]|li|tr)>/i', '/<li[^>]*>/i'], ["\n", "\n\n", '- '], $html);
    $text = preg_replace('/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', '$2 ($1)', $text);
    return trim(html_entity_decode(strip_tags(preg_replace("/\n{3,}/", "\n\n", $text)), ENT_QUOTES, 'UTF-8'));
}

/**
 * Branded, email-client-safe HTML layout (tables + inline styles).
 * $cta = ['label' => ..., 'url' => ...]
 */
function mail_template(string $title, string $bodyHtml, ?array $cta = null, string $footerNote = ''): string
{
    $base = rtrim((string)config('app_url', 'https://' . COMPANY['domain']), '/');
    $button = $cta ? '<tr><td style="padding:8px 32px 28px"><a href="' . e($cta['url']) . '" style="display:inline-block;background:#0066fe;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 26px;border-radius:10px;font-family:Arial,Helvetica,sans-serif;font-size:15px">' . e($cta['label']) . '</a></td></tr>' : '';
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef2f8"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef2f8;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#1d2433">'
        . '<tr><td style="background:#050a16;padding:22px 32px"><img src="' . $base . '/assets/img/mail-mark.png" width="44" height="44" alt="" style="vertical-align:middle;border-radius:10px"> <span style="color:#ffffff;font-size:20px;font-weight:bold;vertical-align:middle;margin-left:8px">Integra<span style="color:#2f7bff">Code</span></span></td></tr>'
        . '<tr><td style="height:4px;background:linear-gradient(90deg,#0066fe,#00cf81);background-color:#0066fe"></td></tr>'
        . '<tr><td style="padding:28px 32px 8px"><h1 style="margin:0 0 14px;font-size:22px;color:#0b1530">' . e($title) . '</h1><div style="font-size:15px;line-height:1.6;color:#33405a">' . $bodyHtml . '</div></td></tr>'
        . $button
        . '<tr><td style="padding:18px 32px;background:#f5f7fb;border-top:1px solid #e3e8f2;font-size:12px;color:#6b7690;line-height:1.5">'
        . ($footerNote ? e($footerNote) . '<br><br>' : '')
        . e(COMPANY['name']) . ' · ' . e(COMPANY['tagline']) . '<br>WhatsApp ' . e(COMPANY['phone']) . ' · <a href="mailto:' . e(COMPANY['email']) . '" style="color:#0066fe">' . e(COMPANY['email']) . '</a> · <a href="' . $base . '" style="color:#0066fe">' . e(COMPANY['domain']) . '</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Key/value table for notification e-mails. */
function mail_details(array $rows): string
{
    $html = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:8px 0 4px;font-size:14px">';
    foreach ($rows as $k => $v) {
        if ($v === null || $v === '') continue;
        $html .= '<tr><td style="padding:7px 10px 7px 0;color:#6b7690;white-space:nowrap;vertical-align:top;border-bottom:1px solid #eef1f6">' . e($k) . '</td><td style="padding:7px 0;border-bottom:1px solid #eef1f6">' . nl2br(e((string)$v)) . '</td></tr>';
    }
    return $html . '</table>';
}

function app_link(string $path): string
{
    return rtrim((string)config('app_url', 'https://' . COMPANY['domain']), '/') . $path;
}

/* ----------------------------------------------------------------- events */

function mail_event_contact(array $lead): void
{
    $sourceLabel = ['contact' => 'Formulário de contato', 'sys-demo' => 'Demonstração Integra SYS', 'chat' => 'Chat', 'diagnostic' => 'Diagnóstico online'][$lead['source']] ?? $lead['source'];
    mail_team("Novo lead: {$lead['name']}" . ($lead['company'] ? " ({$lead['company']})" : ''),
        mail_template('Novo contato pelo site', mail_details(['Origem' => $sourceLabel, 'Nome' => $lead['name'], 'Empresa' => $lead['company'] ?? '', 'E-mail' => $lead['email'] ?? '', 'Telefone' => $lead['phone'] ?? '', 'Assunto' => $lead['subject'] ?? '', 'Mensagem' => $lead['message'] ?? '']),
            ['label' => 'Abrir no painel', 'url' => app_link('/admin/#/leads')]),
        ['event' => 'lead_team', 'reply_to' => $lead['email'] ?? null]);
    if (!empty($lead['email'])) {
        $first = explode(' ', trim($lead['name']))[0];
        mail_queue($lead['email'], 'Recebemos sua mensagem — ' . COMPANY['name'],
            mail_template("Olá, $first! Recebemos sua mensagem 👋",
                '<p>Obrigado pelo contato com a Integra Code. Nossa equipe vai analisar sua solicitação e retornar em até <b>1 dia útil</b>.</p><p>Se preferir agilizar, fale com a gente pelo WhatsApp <b>' . e(COMPANY['phone']) . '</b> ou agende uma conversa no melhor horário para você.</p>',
                ['label' => 'Agendar conversa', 'url' => app_link('/agendar')]),
            ['event' => 'lead_ack', 'name' => $lead['name'], 'reply_to' => COMPANY['email']]);
    }
}

function mail_event_diagnostic(array $lead, int $score, array $answers, ?string $aiReport): void
{
    $rows = [];
    foreach ($answers as $a) if (!empty($a['question'])) $rows[$a['question']] = $a['answer'] ?? '—';
    mail_team("Diagnóstico: {$lead['name']} — maturidade $score%", mail_template('Novo diagnóstico online',
        mail_details(['Nome' => $lead['name'], 'Empresa' => $lead['company'] ?? '', 'E-mail' => $lead['email'], 'Telefone' => $lead['phone'] ?? '', 'Maturidade' => "$score%"]) . mail_details($rows),
        ['label' => 'Abrir no painel', 'url' => app_link('/admin/#/leads')]), ['event' => 'diagnostic_team', 'reply_to' => $lead['email']]);
    $first = explode(' ', trim($lead['name']))[0];
    $body = '<p>Obrigado por fazer o diagnóstico de maturidade digital da Integra Code, ' . e($first) . '.</p>'
        . '<p style="font-size:28px;font-weight:bold;color:#0066fe;margin:18px 0">' . $score . '% de maturidade</p>'
        . ($aiReport ? '<div style="background:#f3f8ff;border-left:4px solid #00cf81;padding:14px 16px;border-radius:8px;margin:14px 0">' . nl2br(e($aiReport)) . '</div>' : '')
        . '<p>Nossa equipe vai preparar um plano de ação detalhado e entrar em contato em até 1 dia útil.</p>';
    mail_queue($lead['email'], "Seu diagnóstico: $score% de maturidade digital", mail_template('Seu diagnóstico está pronto', $body, ['label' => 'Agendar conversa gratuita', 'url' => app_link('/agendar')]), ['event' => 'diagnostic_ack', 'name' => $lead['name']]);
}

function mail_ics(string $start, int $minutes, string $summary, string $description, string $uid): string
{
    $tz = new DateTimeZone('UTC');
    $s = (new DateTimeImmutable($start, new DateTimeZone('America/Sao_Paulo')))->setTimezone($tz);
    $e = $s->modify("+$minutes minutes");
    $esc = fn($t) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $t);
    return implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Integra Code//Agenda//PT', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
        'UID:' . $uid, 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . $s->format('Ymd\THis\Z'), 'DTEND:' . $e->format('Ymd\THis\Z'),
        'SUMMARY:' . $esc($summary), 'DESCRIPTION:' . $esc($description), 'ORGANIZER;CN=Integra Code:mailto:' . COMPANY['email'],
        'END:VEVENT', 'END:VCALENDAR']) . "\r\n";
}

function mail_event_appointment(array $a): void
{
    $when = date('d/m/Y \à\s H:i', strtotime($a['scheduled_at']));
    $types = ['online' => 'Vídeo (link enviado antes da reunião)', 'telefone' => 'Telefone / WhatsApp', 'presencial' => 'Presencial'];
    $minutes = max(30, (int)setting('appointment_slot_minutes', 60));
    $ics = mail_ics($a['scheduled_at'], $minutes, 'Conversa com a Integra Code', ($a['topic'] ?? '') . ' — ' . ($types[$a['meeting_type']] ?? ''), 'appt-' . md5($a['email'] . $a['scheduled_at']) . '@' . COMPANY['domain']);
    mail_queue($a['email'], "Agendamento confirmado: $when", mail_template('Sua conversa está agendada! 📅',
        '<p>Olá, ' . e(explode(' ', trim($a['name']))[0]) . '! Confirmamos o seu agendamento com a Integra Code.</p>' . mail_details(['Quando' => $when . ' (horário de Brasília)', 'Assunto' => $a['topic'] ?? '', 'Formato' => $types[$a['meeting_type']] ?? '']) . '<p>O convite está anexado — é só abrir para adicionar à sua agenda. Precisa remarcar? Responda este e-mail ou chame no WhatsApp.</p>'),
        ['event' => 'appointment_ack', 'name' => $a['name'], 'reply_to' => COMPANY['email'], 'attachments' => [['name' => 'integra-code-reuniao.ics', 'content' => $ics, 'type' => 'text/calendar; charset=utf-8; method=PUBLISH']]]);
    mail_team("Novo agendamento: $when — {$a['name']}", mail_template('Nova reunião agendada pelo site', mail_details(['Quando' => $when, 'Nome' => $a['name'], 'Empresa' => $a['company'] ?? '', 'E-mail' => $a['email'], 'Telefone' => $a['phone'] ?? '', 'Assunto' => $a['topic'] ?? '', 'Formato' => $types[$a['meeting_type']] ?? '', 'Observações' => $a['notes'] ?? '']), ['label' => 'Ver agenda', 'url' => app_link('/admin/#/appointments')]),
        ['event' => 'appointment_team', 'reply_to' => $a['email'], 'attachments' => [['name' => 'reuniao.ics', 'content' => $ics, 'type' => 'text/calendar; charset=utf-8; method=PUBLISH']]]);
}

function mail_ticket_link(array $t): string
{
    if (!empty($t['customer_id']) && db_value('SELECT portal_enabled FROM customers WHERE id = ?', [$t['customer_id']])) {
        return app_link('/cliente/?chamado=' . (int)$t['id']);
    }
    return app_link('/suporte?protocolo=' . rawurlencode($t['protocol']) . '&email=' . rawurlencode($t['email']) . '#consultar');
}

/** Resolution notice with the last reply (optional) and a 1–5 rating request. */
function mail_event_ticket_resolved(array $t, string $lastReply = '', string $staffName = ''): void
{
    $link = mail_ticket_link($t);
    $body = $lastReply !== '' ? '<p>' . e($staffName ?: 'Nossa equipe') . ' respondeu e marcou o chamado <b>' . e($t['protocol']) . '</b> como resolvido:</p><div style="background:#f5f7fb;border-radius:10px;padding:14px 16px;white-space:pre-wrap">' . e($lastReply) . '</div>'
        : '<p>Marcamos o chamado <b>' . e($t['protocol']) . '</b> (' . e($t['subject']) . ') como resolvido.</p>';
    if (setting('ticket_csat_enabled', '1') === '1') {
        $body .= '<p style="margin-top:18px"><b>Como foi o atendimento?</b> Sua avaliação leva 5 segundos e nos ajuda a melhorar:</p><p style="margin:6px 0 0">';
        [$base, $frag] = array_pad(explode('#', $link, 2), 2, '');
        for ($i = 1; $i <= 5; $i++) $body .= '<a href="' . e($base . (strpos($base, '?') !== false ? '&' : '?') . 'avaliar=' . $i . ($frag !== '' ? '#' . $frag : '')) . '" style="display:inline-block;margin:0 3px;padding:6px 10px;border-radius:8px;background:#fff7e0;color:#b7791f;font-size:16px;letter-spacing:0;text-decoration:none;font-weight:700">' . $i . ' ★</a>';
        $body .= '</p><p style="color:#6b7690;font-size:13px;margin:4px 0 0">1 estrela = ruim · 5 estrelas = excelente</p>';
    }
    $days = (int)setting('ticket_autoclose_days', 5);
    $body .= '<p style="color:#6b7690;font-size:13px">Se o problema continuar, é só responder' . ($days > 0 ? " em até $days dias" : '') . ' que o chamado é reaberto.</p>';
    mail_queue($t['email'], "[{$t['protocol']}] Chamado resolvido: {$t['subject']}", mail_template('Chamado resolvido ✅', $body, ['label' => 'Ver chamado', 'url' => $link]), ['event' => 'ticket_resolved', 'name' => $t['name']]);
}

function mail_event_stage_approval_requested(array $stage): void
{
    $p = db_one('SELECT p.*, c.email AS customer_email, c.name AS customer_name, c.portal_enabled FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$stage['project_id']]);
    if (!$p || !$p['customer_email']) return;
    $link = $p['portal_enabled'] ? app_link('/cliente/?aba=projetos#etapa-' . (int)$stage['id']) : app_link('/entrar');
    mail_queue($p['customer_email'], "Aprovação pendente: {$stage['name']} — {$p['name']}", mail_template('Uma etapa aguarda a sua aprovação',
        '<p>Concluímos a etapa <b>' . e($stage['name']) . '</b> do projeto <b>' . e($p['name']) . '</b> e ela está pronta para a sua validação.</p>'
        . '<p>Na Área do Cliente você pode <b>aprovar</b> ou <b>pedir ajustes</b> com um comentário.</p>',
        ['label' => 'Revisar e aprovar', 'url' => $link]), ['event' => 'stage_approval', 'name' => $p['customer_name']]);
}

function mail_event_stage_decision(array $stage, array $customer, string $decision, string $feedback): void
{
    $p = db_find('projects', (int)$stage['project_id']);
    $title = $decision === 'approved' ? 'Etapa aprovada pelo cliente ✅' : 'Cliente pediu ajustes na etapa';
    mail_team("[Projeto] $title: {$stage['name']} — " . ($p['name'] ?? ''), mail_template($title, mail_details(['Projeto' => $p['name'] ?? '', 'Etapa' => $stage['name'], 'Cliente' => $customer['name'], 'Comentário' => $feedback]), ['label' => 'Abrir projeto', 'url' => app_link('/admin/#/projects/' . (int)$stage['project_id'])]), ['event' => 'stage_decision', 'reply_to' => $customer['email'] ?? null]);
}

function mail_event_ticket_opened(array $t, string $message): void
{
    mail_queue($t['email'], "[{$t['protocol']}] Chamado aberto: {$t['subject']}", mail_template('Recebemos seu chamado',
        '<p>Seu chamado foi registrado e nossa equipe técnica já foi avisada.</p>' . mail_details(['Protocolo' => $t['protocol'], 'Assunto' => $t['subject'], 'Prazo de 1ª resposta' => $t['sla_due_at'] ? date('d/m/Y H:i', strtotime($t['sla_due_at'])) : '']) . '<p>Você pode acompanhar e responder pelo link abaixo.</p>',
        ['label' => 'Acompanhar chamado', 'url' => mail_ticket_link($t)]), ['event' => 'ticket_ack', 'name' => $t['name']]);
    mail_team("[{$t['protocol']}] Novo chamado ({$t['priority']}): {$t['subject']}", mail_template('Novo chamado de suporte', mail_details(['Protocolo' => $t['protocol'], 'Cliente' => $t['name'], 'E-mail' => $t['email'], 'Prioridade' => $t['priority'], 'Categoria' => $t['category'], 'Assunto' => $t['subject'], 'Mensagem' => $message]), ['label' => 'Atender no painel', 'url' => app_link('/admin/#/tickets/' . $t['id'])]),
        ['event' => 'ticket_team', 'reply_to' => $t['email']]);
}

function mail_event_ticket_staff_reply(array $t, string $body, string $staffName): void
{
    mail_queue($t['email'], "[{$t['protocol']}] Nova resposta: {$t['subject']}", mail_template('Seu chamado foi respondido',
        '<p>' . e($staffName) . ', da equipe Integra Code, respondeu ao seu chamado <b>' . e($t['protocol']) . '</b>:</p><div style="background:#f5f7fb;border-radius:10px;padding:14px 16px;white-space:pre-wrap">' . e($body) . '</div>',
        ['label' => 'Ver e responder', 'url' => mail_ticket_link($t)]), ['event' => 'ticket_reply', 'name' => $t['name']]);
}

function mail_event_ticket_customer_reply(array $t, string $body): void
{
    mail_team("[{$t['protocol']}] Cliente respondeu: {$t['subject']}", mail_template('Nova mensagem do cliente', mail_details(['Protocolo' => $t['protocol'], 'Cliente' => $t['name'], 'Mensagem' => $body]), ['label' => 'Abrir chamado', 'url' => app_link('/admin/#/tickets/' . $t['id'])]), ['event' => 'ticket_customer', 'reply_to' => $t['email']]);
}

function mail_event_charge_created(array $charge, array $customer): void
{
    if (setting('mail_charge_emails', '1') !== '1' || empty($customer['email']) || empty($charge['invoice_url'])) return;
    $url = strpos($charge['invoice_url'], 'http') === 0 ? $charge['invoice_url'] : app_link($charge['invoice_url']);
    mail_queue($customer['email'], 'Nova cobrança: ' . money($charge['amount']) . ' — vence ' . date('d/m/Y', strtotime($charge['due_date'])), mail_template('Você tem uma nova fatura',
        '<p>Olá! Segue a fatura referente aos serviços da Integra Code.</p>' . mail_details(['Descrição' => $charge['description'], 'Valor' => money($charge['amount']), 'Vencimento' => date('d/m/Y', strtotime($charge['due_date']))]) . '<p>Você pode pagar por PIX, boleto ou cartão pelo link abaixo. Todas as faturas também ficam na sua Área do Cliente.</p>',
        ['label' => 'Ver fatura e pagar', 'url' => $url]), ['event' => 'charge_created', 'name' => $customer['name']]);
}

function mail_event_charge_paid(array $charge): void
{
    $customer = db_find('customers', (int)$charge['customer_id']);
    if (!$customer) return;
    if (setting('mail_charge_emails', '1') === '1' && !empty($customer['email'])) {
        mail_queue($customer['email'], 'Pagamento confirmado — obrigado!', mail_template('Recebemos seu pagamento ✅',
            '<p>Confirmamos o recebimento do seu pagamento. Obrigado pela confiança!</p>' . mail_details(['Descrição' => $charge['description'], 'Valor' => money($charge['amount']), 'Pago em' => $charge['paid_at'] ? date('d/m/Y', strtotime($charge['paid_at'])) : date('d/m/Y')])),
            ['event' => 'charge_paid', 'name' => $customer['name']]);
    }
    mail_team('💰 Pagamento recebido: ' . money($charge['amount']) . " — {$customer['name']}", mail_template('Pagamento recebido', mail_details(['Cliente' => $customer['name'], 'Descrição' => $charge['description'], 'Valor' => money($charge['amount']), 'Líquido' => $charge['net_amount'] ? money($charge['net_amount']) : '']), ['label' => 'Ver cobranças', 'url' => app_link('/admin/#/finance/charges')]), ['event' => 'charge_paid_team']);
}

function mail_event_nfse_authorized(array $inv): void
{
    if (setting('mail_nfse_emails', '1') !== '1' || empty($inv['toma_email']) || $inv['environment'] !== 'production') return;
    $atts = [];
    $xml = db_value('SELECT xml_nfse FROM nfse_invoices WHERE id = ?', [$inv['id']]);
    if ($xml) $atts[] = ['name' => 'NFSe-' . $inv['access_key'] . '.xml', 'content' => $xml, 'type' => 'application/xml'];
    mail_queue($inv['toma_email'], 'Nota fiscal de serviço nº ' . ($inv['nfse_number'] ?: $inv['dps_number']) . ' — ' . COMPANY['name'], mail_template('Sua nota fiscal foi emitida',
        '<p>Segue a NFS-e referente aos serviços prestados pela Integra Code. O XML oficial está anexado.</p>' . mail_details(['Número' => $inv['nfse_number'] ?: '—', 'Valor' => money($inv['amount']), 'Chave de acesso' => $inv['access_key'], 'Discriminação' => $inv['description']]) . '<p>Consulte a autenticidade em <a href="https://www.nfse.gov.br/consultapublica" style="color:#0066fe">nfse.gov.br/consultapublica</a>.</p>'),
        ['event' => 'nfse_authorized', 'name' => $inv['toma_name'], 'attachments' => $atts]);
}

function mail_event_newsletter(string $email): void
{
    mail_queue($email, 'Inscrição confirmada — novidades da Integra Code', mail_template('Bem-vindo(a) às novidades! 🎉', '<p>Sua inscrição foi confirmada. Vamos enviar conteúdos práticos sobre gestão, automação e tecnologia para PMEs — sem spam.</p>', ['label' => 'Ler o blog', 'url' => app_link('/blog')], 'Para cancelar, responda este e-mail com "cancelar".'), ['event' => 'newsletter']);
}
