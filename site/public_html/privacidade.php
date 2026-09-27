<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';

page_start(['title' => 'Política de Privacidade', 'active' => '', 'description' => 'Política de Privacidade e tratamento de dados pessoais da Integra Code, conforme a LGPD (Lei 13.709/2018).']);
?>
<section class="page-hero" style="padding-bottom:30px">
  <div class="container" style="max-width:860px">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <span>Privacidade</span></nav>
    <h1>Política de <span class="grad-text">Privacidade</span></h1>
    <p class="lead">Última atualização: <?= date('d/m/Y', filemtime(__FILE__)) ?></p>
  </div>
</section>
<section class="section-sm" style="padding-top:0">
  <div class="container">
    <div class="prose">
      <p>A <strong><?= e(COMPANY['name']) ?></strong> (CNPJ <?= e(COMPANY['cnpj']) ?>) respeita a sua privacidade e trata dados pessoais de acordo com a Lei Geral de Proteção de Dados (Lei nº 13.709/2018 — LGPD). Esta política explica quais dados coletamos, por que e como você pode exercer seus direitos.</p>

      <h2>1. Dados que coletamos</h2>
      <ul>
        <li><strong>Formulários de contato, agendamento, diagnóstico e demonstração:</strong> nome, e-mail, telefone/WhatsApp, empresa e a mensagem enviada.</li>
        <li><strong>Chamados de suporte:</strong> nome, e-mail, telefone e o conteúdo do atendimento.</li>
        <li><strong>Área do cliente:</strong> dados cadastrais da empresa, informações de projetos e cobranças.</li>
        <li><strong>Dados técnicos:</strong> endereço IP e registros de acesso, usados para segurança e prevenção de abusos.</li>
      </ul>

      <h2>2. Para que usamos</h2>
      <ul>
        <li>Responder solicitações, enviar propostas e realizar reuniões agendadas (execução de procedimentos preliminares a contrato);</li>
        <li>Prestar os serviços contratados, suporte técnico e emitir cobranças (execução de contrato);</li>
        <li>Cumprir obrigações legais e fiscais;</li>
        <li>Garantir a segurança do site e prevenir fraudes (legítimo interesse);</li>
        <li>Enviar novidades, apenas se você se inscrever (consentimento — pode ser revogado a qualquer momento).</li>
      </ul>

      <h2>3. Compartilhamento</h2>
      <p>Não vendemos dados pessoais. Compartilhamos apenas com fornecedores essenciais à operação, como provedor de hospedagem e a instituição de pagamentos <strong>Asaas</strong> (para emissão de boletos, PIX e cartões), sempre na medida necessária.</p>

      <h2>3.1 Assistente virtual e inteligência artificial</h2>
      <p>As mensagens enviadas ao assistente virtual do site e as respostas do diagnóstico online podem ser processadas por um modelo de inteligência artificial executado na infraestrutura da <strong>Cloudflare (Workers AI)</strong>, apenas para gerar a resposta. Não envie dados sensíveis (como senhas ou dados bancários) pelo chat.</p>

      <h2>4. Cookies</h2>
      <p>Utilizamos apenas cookies e armazenamento local essenciais: sessão de login, preferência de tema e registro do aceite deste aviso. Não utilizamos cookies de publicidade.</p>

      <h2>5. Segurança e retenção</h2>
      <p>Adotamos criptografia em trânsito (HTTPS), senhas com hash, criptografia de credenciais de integração, controle de acesso por perfil, registro de auditoria e backups. Mantemos os dados pelo tempo necessário às finalidades acima ou pelo prazo exigido em lei.</p>

      <h2>6. Seus direitos</h2>
      <p>Você pode solicitar confirmação de tratamento, acesso, correção, anonimização, portabilidade, eliminação de dados tratados com consentimento e informações sobre compartilhamento. Envie sua solicitação para <a href="mailto:<?= e(COMPANY['email']) ?>"><?= e(COMPANY['email']) ?></a>. Responderemos em até 15 dias.</p>

      <h2>7. Contato</h2>
      <p>Dúvidas sobre esta política: <a href="mailto:<?= e(COMPANY['email']) ?>"><?= e(COMPANY['email']) ?></a> · <?= e(COMPANY['phone']) ?>.</p>
    </div>
  </div>
</section>
<?php page_end();
