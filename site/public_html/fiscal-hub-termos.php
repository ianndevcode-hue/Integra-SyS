<?php
declare(strict_types=1);

require __DIR__ . '/inc/layout/site.php';
require_once INC_PATH . '/nfse.php';
require_once INC_PATH . '/fiscalhub.php';

$grace = fh_grace_days();
page_start(['title' => 'Termos de Uso do Integra Fiscal Hub', 'active' => '/fiscal-hub', 'description' => 'Termos de Uso do Integra Fiscal Hub, o emissor de notas fiscais de serviço da Integra Code.']);
?>
<section class="page-hero" style="padding-bottom:30px">
  <div class="container" style="max-width:860px">
    <nav class="breadcrumb" aria-label="Você está em"><a href="/">Início</a> / <a href="/fiscal-hub">Integra Fiscal Hub</a> / <span>Termos de uso</span></nav>
    <h1>Termos de Uso do <span class="grad-text">Integra Fiscal Hub</span></h1>
    <p class="lead">Versão <?= FH_TERMS_VERSION ?> · vigente desde 27/09/2026</p>
  </div>
</section>
<section class="section-sm" style="padding-top:0">
  <div class="container">
    <div class="prose">
      <p>Estes Termos regulam o uso do <strong><?= FH_NAME ?></strong> ("Fiscal Hub"), sistema online de emissão de Notas Fiscais de Serviço Eletrônicas (NFS-e) oferecido pela <strong><?= e(COMPANY['name']) ?></strong>, CNPJ <?= e(COMPANY['cnpj']) ?>, com sede em Marília-SP ("Integra Code"), à pessoa física ou jurídica que o contrata ("Cliente"). Ao marcar a opção de aceite e assinar eletronicamente com seu nome na contratação, o Cliente declara que leu e concorda com estes Termos. A data, a hora e o endereço IP do aceite ficam registrados.</p>

      <h2>1. Objeto</h2>
      <p>O Fiscal Hub permite ao Cliente cadastrar suas empresas emissoras, clientes (tomadores) e serviços, e emitir, consultar, enviar, cancelar, substituir e baixar NFS-e por meio (a) do webservice SIGISS da Prefeitura de Marília-SP e (b) do Sistema Nacional da NFS-e (Emissor Nacional), além de gerar relatórios e análises com inteligência artificial, conforme o plano contratado.</p>

      <h2>2. Contratação, planos e limites</h2>
      <ul>
        <li>A contratação é feita online: escolha do plano e do ciclo (mensal ou anual), aceite destes Termos e pagamento.</li>
        <li>Cada plano define um limite de notas por mês, de empresas emissoras e de análises com IA, descritos na página do produto. Notas canceladas contam no mês em que foram emitidas.</li>
        <li>Atingido o limite mensal, novas emissões ficam bloqueadas até o mês seguinte, até o upgrade do plano ou até a concessão de notas adicionais pela Integra Code.</li>
        <li>Mudanças de plano solicitadas pelo Cliente valem a partir da próxima renovação, salvo acordo diferente com a equipe.</li>
      </ul>

      <h2>3. Pagamento, renovação e atraso</h2>
      <ul>
        <li>Os pagamentos são processados pela plataforma Asaas (PIX, boleto ou cartão). A liberação da emissão ocorre após a confirmação do pagamento.</li>
        <li>A assinatura é renovada automaticamente ao fim de cada período. A fatura de renovação é gerada com antecedência e enviada ao e-mail do Cliente.</li>
        <li>Em caso de atraso, a emissão de novas notas permanece liberada por <?= $grace ?> dia(s) após o vencimento e é bloqueada em seguida. Consulta e download das notas já emitidas continuam disponíveis. A emissão é reativada automaticamente após a confirmação do pagamento.</li>
        <li>A Integra Code pode conceder meses gratuitos ou condições promocionais, registradas no histórico da assinatura.</li>
        <li>Reajustes de preço serão comunicados com pelo menos 30 dias de antecedência e não se aplicam ao período já pago.</li>
      </ul>

      <h2>4. Cancelamento</h2>
      <p>O Cliente pode cancelar a qualquer momento, sem multa, pela Área do Cliente. A emissão continua disponível até o fim do período pago. Não há reembolso proporcional de períodos já iniciados, exceto quando exigido por lei. Após o cancelamento, o Cliente mantém acesso para consulta e download das notas por, no mínimo, 5 (cinco) anos, prazo de guarda de documentos fiscais.</p>

      <h2>5. Responsabilidades do Cliente</h2>
      <ul>
        <li>Informar dados verdadeiros e atualizados: CNPJ/CPF, inscrição municipal, regime tributário, alíquotas, códigos de serviço, dados dos tomadores e valores.</li>
        <li>O Cliente é o único responsável pelo conteúdo fiscal das notas emitidas, pela correta tributação (ISS, retenções federais, benefícios, imunidades) e pelo recolhimento dos tributos. Recomenda-se a validação das configurações pelo contador responsável.</li>
        <li>Guardar com segurança o certificado digital, sua senha e a senha do SIGISS, e revogar ou trocar as credenciais em caso de suspeita de uso indevido.</li>
        <li>Utilizar o sistema apenas para fins lícitos e para empresas das quais seja titular ou tenha autorização expressa (por exemplo, escritório de contabilidade com procuração dos clientes).</li>
      </ul>

      <h2>6. Responsabilidades da Integra Code</h2>
      <ul>
        <li>Manter o sistema disponível e atualizado conforme os leiautes oficiais, validando o XML contra os esquemas oficiais antes da transmissão.</li>
        <li>Armazenar o certificado digital e as senhas com criptografia AES-256 e utilizá-los exclusivamente para as operações solicitadas pelo Cliente.</li>
        <li>Prestar suporte pelos canais da Área do Cliente, conforme o plano.</li>
      </ul>

      <h2>7. Disponibilidade e sistemas de terceiros</h2>
      <p>A emissão depende de sistemas públicos (Prefeitura de Marília e Sistema Nacional da NFS-e) e de serviços de terceiros (Asaas, provedores de e-mail e de inteligência artificial). Indisponibilidades, mudanças de regras ou rejeições desses sistemas não são de responsabilidade da Integra Code, que se compromete a informar o Cliente e a adaptar o sistema no menor prazo possível. Rejeições são exibidas com o motivo informado pelo órgão.</p>

      <h2>8. Inteligência artificial</h2>
      <p>As análises com IA são geradas a partir dos números do próprio Cliente e têm caráter informativo. Não substituem a orientação de contador ou consultor tributário.</p>

      <h2>9. Proteção de dados (LGPD)</h2>
      <p>A Integra Code atua como operadora dos dados pessoais inseridos pelo Cliente (por exemplo, dados de tomadores pessoas físicas), tratando-os apenas para a emissão e a gestão das notas, conforme a Lei 13.709/2018 e a <a href="/privacidade">Política de Privacidade</a>. O Cliente é o controlador desses dados e deve ter base legal para seu tratamento.</p>

      <h2>10. Limitação de responsabilidade</h2>
      <p>A responsabilidade total da Integra Code, por qualquer causa, fica limitada ao valor pago pelo Cliente nos 12 meses anteriores ao evento. A Integra Code não responde por lucros cessantes, multas ou autuações decorrentes de informações fiscais incorretas fornecidas pelo Cliente.</p>

      <h2>11. Alterações destes Termos</h2>
      <p>Estes Termos podem ser atualizados. Mudanças relevantes serão comunicadas por e-mail e na Área do Cliente com 30 dias de antecedência. O uso contínuo após a vigência da nova versão representa concordância.</p>

      <h2>12. Foro</h2>
      <p>Fica eleito o foro da Comarca de Marília-SP para dirimir questões oriundas destes Termos, ressalvado o direito do consumidor de ajuizar ações em seu domicílio.</p>
      <p><a class="btn btn-primary" href="/fiscal-hub#planos">Ver planos e contratar</a></p>
    </div>
  </div>
</section>
<?php page_end(); ?>
