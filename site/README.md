# Integra Code — Site + Painel de Gestão

Site institucional (integra-code.tech) com central de ajuda, agendamento, diagnóstico interativo e área do cliente, mais um painel administrativo com CRM, projetos por etapas, chamados e um financeiro completo integrado ao **Asaas** (cobranças, extrato, conciliação, fluxo de caixa, DRE e distribuição de lucros).

- **Stack:** PHP 8.1+ · MySQL/MariaDB (produção) ou SQLite (desenvolvimento) · HTML/CSS/JS puro (sem build).
- **Hospedagem-alvo:** Hostinger (hospedagem compartilhada, qualquer plano com PHP + MySQL).

---

## 1. Estrutura

```
Integra Site/
├── public_html/            ← é isto que vai para o servidor
│   ├── index.php, sobre.php, solucoes.php, segmentos.php, integra-sys.php,
│   │   suporte.php, blog.php, post.php, contato.php, agendar.php,
│   │   diagnostico.php, privacidade.php, 404.php
│   ├── install.php         ← instalador web (apagar após instalar)
│   ├── api/index.php       ← API REST (público, admin, webhook do Asaas)
│   ├── admin/              ← painel administrativo (SPA)
│   ├── cliente/            ← área do cliente
│   ├── assets/             ← CSS, JS, imagens
│   ├── inc/                ← núcleo PHP (bloqueado para acesso web)
│   └── storage/            ← sessões e logs (bloqueado para acesso web)
├── dev/                    ← ferramentas locais (servidor, empacotamento)
└── dist/                   ← .zip pronto para upload (gerado)
```

Textos do site (serviços, segmentos, contatos, etapas) ficam em `public_html/inc/content.php`. Cores e fontes ficam nas variáveis do topo de `public_html/assets/css/site.css`.

---

## 2. Publicar na Hostinger (integra-code.tech)

### 2.1 Gerar o pacote
```bash
python3 dev/build.py
```
Gera `dist/integra-code-hostinger.zip` **sem** arquivos locais (config de desenvolvimento, banco SQLite, sessões e logs).

### 2.2 Preparar o hPanel
1. **Domínio:** aponte `integra-code.tech` para a Hostinger (nameservers `ns1.dns-parking.com` / `ns2.dns-parking.com`, ou os indicados no hPanel).
2. **PHP:** *Avançado → Configuração do PHP* → versão **8.2 ou superior**. As extensões `pdo_mysql`, `curl`, `openssl` e `mbstring` já vêm ativas.
3. **Banco:** *Bancos de dados → Gerenciamento* → crie um banco MySQL. Anote **nome do banco**, **usuário** e **senha** (o host é `localhost`).
   Em produção o sistema usa **somente MySQL/MariaDB** (o SQLite é apenas para desenvolvimento local). Instalador, migrações automáticas, API, painel e Área do Cliente foram testados em **MySQL 8.0** e **MariaDB 11.4**, com tabelas `utf8mb4`.
4. **SSL:** *Segurança → SSL* → instale o certificado gratuito. O `.htaccess` já força HTTPS e remove o `www`.

### 2.3 Enviar os arquivos
1. *Arquivos → Gerenciador de arquivos* → abra `public_html` (apague o `default.php` se existir).
2. Envie o `integra-code-hostinger.zip` e use **Extrair** dentro de `public_html`.
   Os arquivos (index.php, api/, admin/…) devem ficar **diretamente** em `public_html`, não numa subpasta.

### 2.4 Instalar
1. Acesse `https://integra-code.tech/install.php`.
2. Preencha os dados do banco e crie o usuário administrador (senha com 10+ caracteres).
   O sistema começa vazio, sem dados de demonstração.
3. Ao concluir, **apague o `install.php`** pelo Gerenciador de arquivos (ele se bloqueia sozinho, mas é boa prática remover).
4. Entre no painel em `https://integra-code.tech/admin/`.

---

## 3. Integração com o Asaas

Sem chave configurada, tudo funciona em **modo demonstração** (cobranças e extrato simulados, com o botão "Simular pagamento"), para você testar os fluxos com segurança.

### 3.1 Chave da API
1. Comece pelo **sandbox**: crie uma conta em `sandbox.asaas.com` → *Integrações → Chave de API* → gerar.
2. No painel: *Configurações → Integração Asaas* → Ambiente **Sandbox** → cole a chave → **Testar conexão** → **Salvar**.
3. Quando estiver tudo certo, gere a chave na conta real (`www.asaas.com`) e troque o ambiente para **Produção**.

A chave é guardada **criptografada (AES-256-GCM)** no banco e nunca aparece inteira no painel.

### 3.2 Webhook (baixa automática)
No Asaas: *Integrações → Webhooks → Webhook para cobranças*:
- **URL:** `https://integra-code.tech/api/webhooks/asaas`
- **Token de autenticação:** copie de *Configurações → Webhook* no painel
- **Eventos:** todos os de cobrança · **Versão da API:** v3 · ative a fila

Com isso, quando um cliente paga, a cobrança vira "Recebida" e a conta a receber vinculada é baixada automaticamente.

### 3.3 O que a integração faz
| Função | Onde | API Asaas |
|---|---|---|
| Criar/vincular cliente no Asaas (pelo CPF/CNPJ) | Ficha do cliente → Sincronizar, ou automático ao cobrar | `GET/POST /v3/customers` |
| Emitir cobrança (boleto, PIX, cartão ou cliente escolhe) | Financeiro → Cobranças | `POST /v3/payments` |
| QR Code PIX / copia-e-cola | Detalhe da cobrança | `GET /v3/payments/{id}/pixQrCode` |
| Atualizar status / cancelar | Detalhe da cobrança | `GET` / `DELETE /v3/payments/{id}` |
| Importar extrato para o sistema interno | Financeiro → Extrato & Conciliação | `GET /v3/financialTransactions` |
| Saldo da conta | Cobranças / Extrato | `GET /v3/finance/balance` |
| Baixa automática | Webhook | `POST /api/webhooks/asaas` |

---

## 3.4 Notas fiscais de serviço (NFS-e)

O painel emite a NFS-e por **dois emissores**. Escolha um em Painel → Configurações → NFS-e → *Emissor*:

### a) SIGISS Marília (recomendado para Marília-SP)
Webservice oficial da prefeitura (`https://testemarilia.sigissweb.com/…`, SOAP, manual "WebService SigissWeb"). Usa o **CCM (inscrição municipal)** e a **senha do SIGISS**. Não precisa de certificado digital. A nota é gerada na hora e o link oficial de impressão fica salvo.
1. Preencha CNPJ e **inscrição municipal (CCM)** em "Dados do emissor".
2. Em SIGISS, informe a **senha do SIGISS**, o **código do serviço** do seu cadastro municipal (ex.: 101) e a **situação** (normalmente *tp: tributada no prestador*).
3. Clique em **"Salvar e testar acesso ao SIGISS"**. O sistema autentica sem emitir nota.
4. Os clientes precisam de CPF/CNPJ e endereço. Na ficha do cliente, o **CEP preenche o endereço sozinho** (ViaCEP + código IBGE).

> Atenção: no SIGISS **toda nota é real** (não existe homologação). Faça a primeira emissão com um valor e cliente de verdade e confira no portal da prefeitura.

### b) Sistema Nacional (Sefin Nacional)
Direto na API nacional: DPS no leiaute oficial v1.01 → validação local contra os XSDs oficiais (`inc/nfse-schemas`) → assinatura XMLDSig (RSA-SHA1) com o **certificado A1 (e-CNPJ ICP-Brasil)** → envio por mTLS.
1. Envie o arquivo **.pfx do e-CNPJ A1** e a senha. Ficam criptografados no banco.
2. Confira o município (3529005 = Marília-SP), o regime do Simples, a série e o próximo número da DPS.
3. Defina **cTribNac** (ex.: 010101) e **NBS** (ex.: 115022000). Confirme com a sua contabilidade.
4. Comece em **Produção restrita**, use **"Verificar município no Sistema Nacional"** e só depois troque para **Produção**. Se o município não tiver convênio para o seu CNPJ, a Sefin recusa. Nesse caso use o SIGISS.

**Tributos:** a emissão calcula ISS, **PIS, COFINS, CSLL, IRRF e INSS** (alíquotas e "retido pelo tomador"), CST de PIS/COFINS, desconto incondicionado, deduções da base do ISS, **valor líquido** e o "valor aproximado dos tributos" da Lei 12.741/2012. Os padrões ficam em Configurações → NFS-e → *Tributos federais*. Em branco = automático pelo regime (Simples/MEI: 0%, recolhidos no DAS; não optante: PIS 0,65%, COFINS 3%, CSLL 1%, IRRF 1,5%). A DPS nacional envia `tribFed` (piscofins, vRetCP, vRetIRRF, vRetCSLL) validada nos XSDs, e o SIGISS envia os campos `pis`, `cofins`, `inss`, `irrf`, `csll`, `retencao_*` e `valor_total_tributos`. IRRF abaixo de R$ 10,00 não é retido.

**Recursos (ambos):** emissão manual ou a partir de uma cobrança paga, emissão automática ao receber pagamento (opcional), DANFSe/impressão oficial, XML, cancelamento e reenvio após correção. O cliente baixa as notas em **Área do Cliente → Financeiro**.

> **Reforma tributária:** os campos de IBS/CBS ainda não são obrigatórios na NFS-e. Para o Simples Nacional, a exigência está prevista para 2027.
>
> **OpenSSL:** o Sistema Nacional exige assinatura **SHA1**. Servidores com OpenSSL 3.5+ podem vir com SHA1 desativado. Nesse caso a emissão mostra um erro claro, e a solução é pedir ao suporte da Hostinger para habilitar o "legacy provider".

## 3.5 Inteligência Artificial — Cloudflare Workers AI

Configure em Painel → Configurações → Inteligência Artificial (Account ID + API Token com permissão **Workers AI**). O token fica criptografado no banco e só é usado pelo servidor.

| Recurso | Onde |
|---|---|
| Chat do site com IA (responde com base nos serviços e na central de ajuda, lembra a conversa e cai para o assistente por regras se a IA falhar) | Balão de chat em todas as páginas |
| Análise personalizada do diagnóstico | Resultado de /diagnostico |
| Sugerir resposta de chamado | Painel → Chamados → conversa |
| Análise e qualificação de lead | Painel → Leads → detalhe |
| Rascunho de artigo do blog | Painel → Blog → Novo artigo |
| Melhorar a descrição da NFS-e | Painel → Notas fiscais → Emitir |

Cada recurso pode ser ligado ou desligado separadamente. O uso aparece nas Configurações (chamadas nos últimos 30 dias). O custo do Workers AI depende do plano da sua conta Cloudflare (há uma cota gratuita diária).

## 3.6 E-mails (Gmail ou Cloudflare Email Service)

Painel → Configurações → **E-mail**. Tudo o que o site envia passa por uma fila (o visitante não espera o envio), com até 5 tentativas automáticas e histórico em **Sistema → E-mails enviados**.

**E-mails automáticos:** confirmação e aviso à equipe para contato, diagnóstico (com a análise da IA), agendamento (com convite `.ics` para a agenda), chamado aberto/respondido, nova cobrança, pagamento confirmado, NFS-e emitida (com XML), newsletter, confirmação de cadastro, redefinição de senha e convites de acesso.

**Opção recomendada — e-mail da Hostinger (dev@integra-code.tech):** o domínio já recebe e-mails pela Hostinger e o SPF autoriza só os servidores dela. No painel, clique em "Configuração rápida → Hostinger" (servidor `smtp.hostinger.com`, porta 465/SSL, usuário `dev@integra-code.tech`), digite a senha da caixa (que pode ser redefinida no hPanel → E-mails → Contas de e-mail) e envie um teste.

**Opção A — Gmail:** só vale a pena se o remetente for o próprio endereço @gmail.com. Se a página de senhas de app disser "não disponível para sua conta", a verificação em duas etapas não está ativa ou a conta não permite senhas de app.
1. Na conta Google que vai enviar, ative a **Verificação em duas etapas** (myaccount.google.com/security).
2. Crie uma **senha de app** em myaccount.google.com/apppasswords (nome: "Site Integra Code") e copie as 16 letras.
3. No painel: provedor **Gmail / SMTP**, servidor `smtp.gmail.com`, porta **587 (TLS)**, usuário = o endereço Gmail, senha = a senha de app.
4. Remetente: o próprio Gmail, ou outro endereço seu (ex.: dev@integra-code.tech) configurado em Gmail → Configurações → Contas → **"Enviar e-mail como"**.
5. Clique em **"Salvar e enviar teste"**.
Limites: cerca de 500 e-mails/dia (conta pessoal) ou 2.000/dia (Google Workspace).

**Opção B — Cloudflare Email Service:**
1. Coloque o domínio **integra-code.tech** na Cloudflare (Add a domain) e troque os nameservers no painel da Hostinger. Antes de trocar, confira se os registros **A** do site e os **MX** de e-mail foram importados.
2. Em Compute & AI → Email Service → **Email Sending → Onboard Domain** (o SPF e o DKIM são criados sozinhos).
3. Dê ao token a permissão **Account → Email Sending → Edit**, ou crie um token só para e-mail.
4. No painel: provedor **Cloudflare Email Service**, remetente `algo@integra-code.tech`, e envie um teste.

**Cron (recomendado):** hPanel → Avançado → Cron Jobs, a cada 5 minutos: `php /home/SEU_USUARIO/domains/integra-code.tech/public_html/cron.php`. Ele reenvia e-mails que falharam e limpa dados temporários.

## 3.7 Login (e-mail/senha + Google)

- **/entrar**: login único. A equipe vai para o painel e o cliente para a Área do Cliente. Tem "manter conectado" (30 dias, com troca do token a cada uso), "esqueci minha senha" e "Continuar com Google".
- **/cadastro**: o cliente cria a conta e confirma o e-mail pelo link. Se o e-mail já pertence a um cliente cadastrado, o sistema envia um link seguro para ele criar a senha. Pode ser desativado ("Somente por convite").
- **Convites:** na ficha do cliente, "Convidar p/ Área do Cliente". Em Usuários, o botão de envelope envia o convite ou a redefinição de senha.
- **Segurança:** tokens de uso único com hash, links de senha que expiram (1 h, ou 7 dias para convites), cookies HttpOnly/SameSite, CSRF, limite de tentativas, resposta igual para e-mails inexistentes, e revogação das sessões lembradas ao trocar a senha.

**Configurar o login com Google:**
1. Em console.cloud.google.com, crie um projeto.
2. Em Google Auth Platform / **Tela de permissão OAuth**: tipo Externo, nome "Integra Code", e-mail de suporte, domínio autorizado `integra-code.tech`, link da política `https://integra-code.tech/privacidade`, escopos `openid email profile`. Publique o app.
3. Em **Credenciais → ID do cliente OAuth → Aplicativo da Web**, informe o URI de redirecionamento autorizado `https://integra-code.tech/google-login` (e `http://localhost:8080/google-login` para testes).
4. Cole o Client ID e o Client Secret no painel (Configurações → Login e cadastro), ative e salve.

## 4. Painel administrativo

| Área | Recursos |
|---|---|
| **Dashboard** | KPIs de receita, lucro, caixa, inadimplência, projetos, chamados, leads; gráfico 6 meses; vencimentos |
| **Clientes** | Cadastro completo, ficha com projetos/financeiro/cobranças/chamados, acesso ao portal, sync Asaas, CSV |
| **Leads** | Contatos do site, diagnóstico (com respostas), conversão em cliente, WhatsApp, CSV |
| **Agenda** | Reuniões agendadas pelo site com confirmação via WhatsApp |
| **Projetos** | Kanban por etapa (arrastar e soltar), etapas personalizáveis, tarefas com responsável, progresso, margem |
| **Chamados** | SLA por prioridade, conversa com o cliente (que acompanha pelo protocolo) |
| **Contas a pagar/receber** | Parcelamento/recorrência, baixa individual e em massa, centro de custo por projeto, CSV |
| **Cobranças** | Emissão via Asaas vinculada ao contas a receber |
| **Notas fiscais (NFS-e)** | Emissão, DANFSe, XML e cancelamento no Sistema Nacional, com certificado A1 |
| **Extrato & Conciliação** | Importação do Asaas, conciliação automática (por ID da cobrança ou valor + data), manual, tarifas automáticas |
| **Fluxo de caixa** | Realizado x previsto por mês, saldo acumulado e projeção diária 30/60/90 dias |
| **Lucros & gastos (DRE)** | DRE gerencial por período, margens, gastos por categoria, evolução 12 meses |
| **Sócios & distribuição** | Participação %, reserva de lucro, simulação e geração das contas a pagar de repasse |
| **Conteúdo** | Blog e artigos da central de ajuda (alimentam também o assistente virtual) |
| **Sistema** | Configurações, usuários com perfis, plano de contas (DRE), auditoria |

**Perfis de acesso:** Administrador (tudo) · Financeiro · Gestor de projetos · Suporte.

---


### Suporte (Chamados)
- **Filas:** Abertos, Meus, Sem responsável, Cliente respondeu, SLA vencido, Aguardando e Resolvidos, com lista ou quadro (arrastar muda o status).
- **Situações:** Aberto, Em atendimento, Aguardando cliente, Aguardando terceiros, Pausado, Resolvido e Encerrado. O SLA **pausa sozinho** enquanto aguarda o cliente ou terceiros, e o prazo é por prioridade (Configurações → Suporte).
- **Atendimento:** responsável, tags, projeto vinculado, canal de origem, **notas internas** (o cliente nunca vê), **respostas prontas** com variáveis ({nome}, {protocolo}, {assunto}, {atendente}), anexos, sugestão por IA e ações em massa.
- **Qualidade:** tempo de 1ª resposta, **avaliação de 1 a 5 estrelas** enviada ao resolver e encerramento automático após N dias (pelo cron).

### Flexibilidade de situações
- **Clientes:** Prospect, Em implantação, Ativo, Pausado, Inadimplente, Inativo, com responsável pela conta e tags.
- **Leads (funil):** Novo, Contatado, Reunião marcada, Proposta enviada, Negociação, Ganho, Perdido (com motivo) e Nutrir. Tem valor estimado, próximo contato e quadro com arrastar.
- **Projetos:** Proposta, Aprovado, Em andamento, Aguardando cliente, Em homologação, Pausado, Em manutenção, Concluído, Cancelado. Quadro por etapa ou por situação.
- **Etapas:** Pendente, Em andamento, Aguardando cliente, Aguardando aprovação, Bloqueada, Concluída, Dispensada. O botão **"Pedir aprovação"** envia e-mail e mostra *Aprovar / Pedir ajustes* na Área do Cliente.
- **Histórico e follow-ups** (anotações, ligações, WhatsApp, reuniões e lembretes com data) em clientes, leads e projetos, reunidos na tela **Follow-ups**.
- **Arquivos** em clientes, leads, projetos e chamados (até 15 MB cada), com a opção "visível para o cliente".
- **Busca global:** `Ctrl + K` (ou `/`) procura clientes, projetos, chamados, leads e lançamentos.


### Vendas & preços (Painel → Vendas & preços)
- **Tabela de preços:** 28 itens em 6 categorias (implantação, mensalidade do Integra SYS / manutenção, suporte, licenças, desenvolvimento e adicionais), cada um com **mínimo, média e máximo de mercado em Marília** e o **preço Integra = média − 10%**. O botão *Recalcular* reaplica a regra. As fontes da pesquisa ficam registradas em cada item.
- **Simulador:** pacotes prontos, cartões com a faixa de mercado, quantidades, preço negociado (avisa quando fica abaixo do desconto máximo ou do mínimo de mercado), descontos, parcelas da implantação, fidelidade e economia contra a média de Marília. **Para contratar é obrigatório:** pelo menos uma implantação, exatamente um plano de suporte e uma mensalidade.
- **Orçamentos:** salvar, duplicar (nova versão), situação, conversão e ticket médio. *Gerar proposta* cria a apresentação com os slides de implantação e mensalidade. *Contratar* cria o **contrato**, converte o lead em cliente, abre o projeto de implantação, lança as parcelas em contas a receber e, se quiser, emite a 1ª cobrança no Asaas.
- **Contratos & MRR:** MRR, ARR, próximos faturamentos, *Gerar mensalidades* (idempotente por mês, com cobrança automática no Asaas opcional) e atalhos para emitir a **NFS-e** e a cobrança de cada contrato. O cron diário pode faturar sozinho (Configurações → Vendas, preços e contratos).
- **Consultor de preços com IA:** pergunte "quanto cobrar por…" e receba mínimo, média e máximo em Marília e o preço sugerido (média −10%), com botões para usar no simulador ou salvar na tabela. Com uma chave da **Brave Search API** (Configurações), a IA consulta preços na web em tempo real e cita as fontes. Sem ela, usa a tabela de referência e o conhecimento de mercado.
- **Integrações:** ficha do cliente (aba *Contratos & orçamentos*, MRR), lead (*Simular orçamento*), dashboard (MRR), projetos (link para o orçamento), cobranças e NFS-e vinculadas ao contrato e Área do Cliente (*Seu plano*).

### Integra Fiscal Hub (produto: emissor de NFS-e para clientes)
Emissor de notas fiscais de serviço vendido no site e usado pelos clientes dentro da Área do Cliente.
- **Site:** `/fiscal-hub` (página do produto com os 4 planos, comparação e FAQ), `/fiscal-hub-contratar` (contratação online: conta, CPF/CNPJ, forma de pagamento, aceite dos termos com assinatura eletrônica — data, hora e IP registrados) e `/fiscal-hub-termos` (termos de uso, versão 2026.09).
- **Planos:** Essencial, Profissional, Business e Enterprise, com preço = média de mercado −10%. As referências (Actana ERP, integrado à Prefeitura de Marília, e emissores nacionais como NFE.io, eNotas, Emitte, Notafly, MandaNotas, ClickNotas e Meu Emissor) ficam em Painel → Integra Fiscal Hub → Planos e preços, onde os preços, limites e vantagens podem ser editados ou recalculados.
- **Pagamento:** a contratação gera a cobrança no Asaas. O webhook de pagamento libera o acesso automaticamente. O cron gera as renovações com antecedência (`fh_lead_days`), bloqueia a emissão após a tolerância (`fh_grace_days`) e emite as notas recorrentes.
- **App do cliente (`/cliente/fiscal/`):** painel, emissão com cálculo ao vivo, notas (filtros, seleção múltipla, ZIP de PDF/XML, planilha), clientes (busca de CNPJ/CEP), serviços (lista LC 116), recorrentes, emissão em lote por CSV, relatórios com IA, empresa e certificado, e assinatura (pagar, trocar plano, cancelar).
- **Emissão:** SIGISS de Marília (CCM + senha, os 60 campos do WSDL na ordem oficial) e Emissor Nacional (DPS v1.01 completa, validada nos XSDs e assinada com o A1 do cliente). Cobre ISS retido pelo tomador ou intermediário, imunidade, isenção com benefício municipal, não incidência, exportação (comércio exterior), exigibilidade suspensa, obra, evento, local da prestação, deduções, descontos e retenções de PIS, COFINS, CSLL, IRRF e INSS. Também faz cancelamento, substituição e envio automático ao tomador.
- **Admin (Painel → Produtos):** visão geral (assinantes, MRR, notas, certificados vencendo), assinantes (conceder meses grátis, ativar pagamento feito fora do Asaas, gerar cobrança, alterar plano/valor/notas extras, suspender/cancelar), planos e preços, monitor de notas e configurações.
- **Testes:** o motor de emissão aceita `fh_sigiss_url` em `inc/config.php` para apontar o SIGISS para um simulador local. Nunca teste com credenciais reais: toda nota no SIGISS é real.

### Relatórios (Painel → Inteligência → Relatórios)
19 relatórios prontos, com filtro de período, comparação com o período anterior, gráficos, tabelas, CSV e PDF com a marca:
- **Financeiro:** executivo, DRE comparativa, fluxo de caixa projetado, orçamento × realizado, receita por cliente (curva ABC), despesas, contas a receber e a pagar (aging) e inadimplência.
- **Projetos:** rentabilidade (contrato, custos, horas, margem), carteira e saúde, horas trabalhadas.
- **Comercial:** funil (conversão, origem, motivos de perda), carteira de clientes (LTV, sem movimento), **receita recorrente (MRR, churn, evolução)**, **orçamentos e conversão** e **tabela de preços × mercado**.
- **Operação e fiscal:** suporte e SLA (1ª resposta, resolução, CSAT) e NFS-e com ISS, PIS, COFINS, CSLL, IRRF, INSS, retenções e valor líquido.
- **Construtor:** escolha a base (lançamentos, cobranças, projetos, chamados, leads, clientes, horas, notas, orçamentos, contratos), o agrupamento, a medida e o gráfico. Os relatórios ficam salvos e podem ser fixados no topo.
- **Análise executiva:** resumo, destaques, pontos de atenção e recomendações. Com a IA configurada (Cloudflare), o texto é escrito pela IA. Sem ela, é gerado por regras.

### Apresentações com IA (Painel → Inteligência → Apresentações)
Apresentações 16:9 com a identidade visual da Integra Code (temas escuro, azul e claro):
- **Proposta comercial** (lead ou cliente novo), **Relatório de resultados** e **Revisão estratégica/QBR** (clientes atuais), **Kickoff de projeto**, **Institucional** e **Apresentação de relatório**.
- **Números sempre reais:** vêm do sistema (projetos, etapas, chamados, SLA, CSAT, pagamentos). A IA escreve apenas os textos, no tom escolhido, e é orientada a nunca inventar dados.
- **Editor:** reordenar, duplicar e adicionar slides (13 tipos), editar textos, destacar palavras com `**asteriscos**`, reescrever um slide com IA e ver a prévia ao vivo.
- **Compartilhamento:** link público com contagem de visualizações, modo apresentação (setas, tela cheia, toque) e **PDF** (um slide por página).

### Financeiro e projetos (novidades)
- **Orçamento e metas:** previsto × realizado por categoria e mês, meta de receita mensal (no dashboard), custo/hora da equipe e valor-hora padrão.
- **Lançamentos:** comprovantes anexados, duplicar para o próximo mês, tags e ações em massa (adiar, nova data, categoria, projeto, tag).
- **Projetos:** apontamento de horas (faturáveis ou não), horas estimadas e valor-hora, saúde (no prazo / atenção / em risco), cronograma (Gantt), modelos de projeto, duplicar e apresentação de kickoff.
- **Calendário:** reuniões, follow-ups, contas a pagar e receber e prazos de projetos e etapas em uma visão mensal.

### Área do Cliente (`/cliente/`)
Visão geral (próxima fatura, alertas de vencidas, progresso do projeto, atividade recente), **Projetos** (etapas, tarefas visíveis, % concluído, **aprovação de etapas** e **arquivos** compartilhados nos dois sentidos), **Financeiro** (seu plano contratado com os itens da mensalidade, pagar faturas em aberto, recibos, PDF/XML das NFS-e), **Suporte** (abrir chamado com anexos, vincular a um projeto, conversar, reabrir e avaliar o atendimento) e **Minha conta** (dados cadastrais com CEP automático, senha, status do login Google e pedido LGPD).

---

## 5. Desenvolvimento local

Requer PHP 8.1+ com `pdo_sqlite`.
```bash
php public_html/inc/cli.php setup-local   # cria o SQLite local
./dev/serve.sh                             # http://localhost:8080
```
- Admin local: `admin@local.test` / `admin12345` (criado pelo `setup-local`)

Outros comandos: `php public_html/inc/cli.php migrate` · `create-admin <email> <senha> [nome]`.

---

## 6. Segurança

- Senhas com `password_hash` (bcrypt/argon), sessão `HttpOnly` + `SameSite=Lax` + `Secure` em HTTPS.
- Proteção CSRF em todas as alterações do painel; limite de tentativas no login e nos formulários públicos; honeypot anti-spam.
- SQL sempre com *prepared statements*; ordenação e filtros por lista branca.
- Chave do Asaas criptografada; webhook autenticado por token (comparação em tempo constante).
- `inc/` e `storage/` bloqueados via `.htaccess`; cabeçalhos de segurança (HSTS, nosniff, frame-options).
- Registro de auditoria de todas as ações.

**Backups:** a Hostinger faz backup diário (hPanel → Backups). Recomenda-se também exportar os CSVs financeiros periodicamente.
