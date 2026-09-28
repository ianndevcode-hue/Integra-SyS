<?php
declare(strict_types=1);

/**
 * Base seed: categories, settings, FAQ and blog content required by a fresh install.
 */

function seed_base(): void
{
    if (!(int)db_value('SELECT COUNT(*) FROM categories')) {
        $categories = [
            ['Desenvolvimento de sistemas', 'receivable', 'revenue', '#0066fe'],
            ['Mensalidades / Suporte (SLA)', 'receivable', 'revenue', '#00cf81'],
            ['Consultoria', 'receivable', 'revenue', '#2f7bff'],
            ['Licenças Integra SYS', 'receivable', 'revenue', '#14b8a6'],
            ['Rendimentos financeiros', 'receivable', 'other_income', '#22c55e'],
            ['Impostos sobre faturamento (Simples/ISS)', 'payable', 'tax', '#ef4444'],
            ['Infraestrutura (servidores, domínios)', 'payable', 'cost', '#6366f1'],
            ['Softwares e APIs', 'payable', 'cost', '#8b5cf6'],
            ['Freelancers / Terceiros', 'payable', 'cost', '#a855f7'],
            ['Salários e pró-labore', 'payable', 'expense', '#0ea5e9'],
            ['Marketing e anúncios', 'payable', 'expense', '#14b8a6'],
            ['Contabilidade', 'payable', 'expense', '#64748b'],
            ['Aluguel e escritório', 'payable', 'expense', '#78716c'],
            ['Tarifas bancárias (Asaas)', 'payable', 'expense', '#94a3b8'],
            ['Equipamentos (investimento)', 'payable', 'investment', '#475569'],
            ['Distribuição de lucros', 'payable', 'distribution', '#e11d48'],
        ];
        foreach ($categories as [$name, $type, $group, $color]) {
            db_insert('categories', ['name' => $name, 'entry_type' => $type, 'dre_group' => $group, 'color' => $color]);
        }
    }

    $defaults = [
        'company_name' => COMPANY['name'],
        'company_cnpj' => COMPANY['cnpj'],
        'company_email' => COMPANY['email'],
        'company_phone' => COMPANY['phone'],
        'asaas_environment' => 'sandbox',
        'profit_reserve_percent' => '20',
        'ticket_sla_hours' => '24',
        'appointment_slot_minutes' => '60',
        'project_stage_template' => 'Diagnóstico|Planejamento|Desenvolvimento|Implantação|Evolução',
    ];
    foreach ($defaults as $k => $v) {
        if (db_value('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$k]) == 0) set_setting($k, $v);
    }
    if (!setting('asaas_webhook_token')) set_setting('asaas_webhook_token', bin2hex(random_bytes(16)));

    if (!(int)db_value('SELECT COUNT(*) FROM help_articles')) {
        $faq = [
            ['primeiros-passos', 'Como funciona o diagnóstico gratuito?', 'Fazemos uma conversa de 30 a 60 minutos (online ou presencial) para entender seus processos, ferramentas atuais e principais dores. Em seguida você recebe um relatório com as oportunidades de melhoria e uma estimativa de investimento, sem compromisso.', 'diagnostico gratis gratuito reuniao avaliacao'],
            ['primeiros-passos', 'Quanto tempo leva para desenvolver um sistema?', 'Depende do escopo. Projetos enxutos ficam prontos em 3 a 6 semanas; sistemas completos (ERP, portais, integrações múltiplas) costumam levar de 2 a 6 meses. Trabalhamos com entregas parciais para você já usar partes do sistema durante o desenvolvimento.', 'prazo tempo demora quanto tempo'],
            ['primeiros-passos', 'Vocês atendem empresas de fora de São Paulo?', 'Sim. Atendemos empresas de todo o Brasil de forma remota, com reuniões por vídeo, acompanhamento pelo portal do cliente e suporte por WhatsApp e chamados.', 'remoto brasil outro estado cidade'],
            ['financeiro', 'Quais são as formas de pagamento?', 'Aceitamos PIX, boleto bancário e cartão de crédito, com cobranças emitidas pela plataforma Asaas. Projetos podem ser parcelados conforme as etapas de entrega.', 'pagamento pix boleto cartao parcelar'],
            ['financeiro', 'Como acesso a segunda via do meu boleto?', 'Acesse a Área do Cliente com seu e-mail e senha: todas as cobranças ficam disponíveis com link para boleto, PIX copia-e-cola e cartão. Se preferir, abra um chamado na categoria Financeiro.', 'segunda via boleto fatura cobranca'],
            ['financeiro', 'Vocês emitem nota fiscal?', 'Sim, emitimos nota fiscal de serviço (NFS-e) para todos os pagamentos recebidos.', 'nota fiscal nfse nf'],
            ['projetos', 'Como acompanho o andamento do meu projeto?', 'Na Área do Cliente você vê a etapa atual do projeto (Diagnóstico, Planejamento, Desenvolvimento, Implantação e Evolução), o percentual concluído e as próximas entregas.', 'andamento acompanhar etapa progresso status projeto'],
            ['projetos', 'Posso pedir alterações durante o desenvolvimento?', 'Sim. Pequenos ajustes são absorvidos no ciclo atual. Mudanças de escopo maiores são avaliadas, orçadas e aprovadas por você antes de entrar no planejamento.', 'alteracao mudanca escopo ajuste'],
            ['projetos', 'O código-fonte fica comigo?', 'Em projetos sob medida, as condições de propriedade do código são definidas em contrato. Para o Integra SYS (produto), você recebe licença de uso com todas as atualizações.', 'codigo fonte propriedade'],
            ['suporte', 'Qual o horário de atendimento?', 'Nosso atendimento humano funciona de segunda a sexta, das 8h às 18h. Clientes com contrato de SLA contam com monitoramento 24/7 e atendimento emergencial conforme o plano.', 'horario atendimento funcionamento'],
            ['suporte', 'Como abro um chamado de suporte?', 'Na Central de Ajuda, clique em "Abrir chamado", preencha seus dados e descreva o problema. Você recebe um número de protocolo para acompanhar a resposta a qualquer momento.', 'chamado ticket abrir suporte protocolo'],
            ['suporte', 'Qual o prazo de resposta dos chamados?', 'Chamados urgentes são respondidos em até 4 horas úteis, e os demais em até 24 horas úteis. Clientes com SLA contratado têm prazos específicos no contrato.', 'prazo resposta sla chamado demora'],
            ['seguranca', 'Meus dados estão seguros?', 'Sim. Usamos conexões criptografadas (HTTPS), senhas com hash, backups automáticos diários e controle de acesso por perfil. Seguimos as boas práticas da LGPD em todos os sistemas.', 'seguranca dados backup criptografia'],
            ['seguranca', 'Vocês estão adequados à LGPD?', 'Sim. Tratamos dados pessoais apenas para as finalidades informadas, com base legal adequada, e você pode solicitar acesso, correção ou exclusão dos seus dados a qualquer momento pelo e-mail de contato.', 'lgpd privacidade dados pessoais'],
            ['integra-sys', 'O que é o Integra SYS?', 'É o nosso sistema de gestão modular para PMEs: financeiro, estoque, vendas, CRM, fiscal, BI e atendimento em uma única plataforma na nuvem, que pode ser personalizada para o seu segmento.', 'integra sys erp sistema gestao'],
            ['integra-sys', 'O Integra SYS integra com outros sistemas?', 'Sim: bancos (PIX, boletos e conciliação), marketplaces como Mercado Livre, delivery como iFood, WhatsApp Business, maquininhas e outros ERPs via API.', 'integracao api mercado livre ifood'],
        ];
        foreach ($faq as $i => [$cat, $q, $a, $kw]) {
            db_insert('help_articles', ['category' => $cat, 'question' => $q, 'answer' => $a, 'keywords' => $kw, 'position' => $i, 'published' => 1]);
        }
    }

    if (!(int)db_value('SELECT COUNT(*) FROM posts')) {
        $posts = [
            ['Planilhas x Sistema de gestão: quando é hora de mudar?', 'Gestão', 'Os sinais de que as planilhas estão custando caro para a sua empresa e como fazer a transição sem dor.',
                "<p>Planilhas são ótimas para começar. O problema aparece quando a empresa cresce e elas viram o centro da operação: várias versões do mesmo arquivo, fórmulas quebradas e informações que só uma pessoa entende.</p><h2>Sinais de alerta</h2><ul><li>Você passa horas consolidando dados todo fim de mês;</li><li>Ninguém sabe qual é a versão correta da planilha;</li><li>Erros de digitação já causaram prejuízo;</li><li>Não é possível saber o saldo de estoque ou de caixa em tempo real.</li></ul><h2>Como fazer a transição</h2><p>Comece pelo processo que mais dói, geralmente o financeiro ou o estoque. Migre os dados históricos essenciais, treine a equipe e mantenha a planilha antiga apenas como consulta por um período curto.</p><p>Um bom sistema não substitui só a planilha: ele elimina o retrabalho e passa a entregar relatórios automáticos para decisões mais rápidas.</p>"],
            ['Conciliação bancária automática: o que é e por que você precisa', 'Financeiro', 'Entenda como cruzar extrato e lançamentos automaticamente e ter um fluxo de caixa confiável.',
                "<p>Conciliação bancária é o processo de conferir se cada movimentação do extrato corresponde a um lançamento do seu controle financeiro. Feita à mão, ela consome horas e é sujeita a erros.</p><h2>Como a automação ajuda</h2><p>Integrando o banco via API (como o Asaas), o extrato é importado automaticamente e cada recebimento é associado à cobrança correspondente. Sobram para análise apenas as exceções.</p><h2>Benefícios</h2><ul><li>Fluxo de caixa sempre atualizado;</li><li>Identificação rápida de inadimplência;</li><li>Tarifas bancárias lançadas automaticamente;</li><li>DRE e distribuição de lucros com números reais.</li></ul>"],
            ['5 automações que economizam horas da sua equipe toda semana', 'Automação', 'Cobranças, lembretes, aprovações e relatórios: tarefas que um sistema faz melhor que pessoas.',
                "<p>Toda empresa tem tarefas repetitivas que poderiam rodar sozinhas. Estas são as cinco que mais trazem retorno:</p><ol><li><strong>Cobranças automáticas</strong> com PIX e boleto, e lembretes antes do vencimento;</li><li><strong>Confirmação de agendamentos</strong> por WhatsApp;</li><li><strong>Fluxos de aprovação</strong> de compras e despesas;</li><li><strong>Relatórios diários</strong> enviados por e-mail;</li><li><strong>Alertas de estoque mínimo</strong> e reposição.</li></ol><p>O segredo é começar pequeno, medir o tempo economizado e expandir.</p>"],
            ['Inteligência Artificial na prática para pequenas e médias empresas', 'IA', 'Casos reais de uso de IA que já cabem no orçamento de uma PME.',
                "<p>IA deixou de ser coisa de grandes empresas. Hoje é possível aplicar inteligência artificial em problemas bem concretos:</p><ul><li><strong>Atendimento:</strong> chatbots que respondem dúvidas frequentes e encaminham o cliente para um humano quando necessário;</li><li><strong>Documentos:</strong> leitura automática de notas, contratos e comprovantes;</li><li><strong>Previsões:</strong> estimativa de vendas e necessidade de estoque;</li><li><strong>BI conversacional:</strong> perguntar em português \"quanto vendi este mês na filial 2?\" e receber a resposta.</li></ul><p>O ponto de partida é sempre um diagnóstico: onde a IA gera economia ou receita real para o seu negócio.</p>"],
            ['LGPD para PMEs: checklist básico de adequação', 'Segurança', 'Os primeiros passos para proteger os dados dos seus clientes e evitar problemas.',
                "<p>A Lei Geral de Proteção de Dados vale para empresas de todos os tamanhos. Um checklist inicial:</p><ul><li>Mapeie quais dados pessoais você coleta e por quê;</li><li>Publique uma política de privacidade clara;</li><li>Restrinja o acesso aos dados apenas a quem precisa;</li><li>Use senhas fortes e sistemas com criptografia;</li><li>Tenha backups e um plano para incidentes;</li><li>Defina um canal para o titular exercer seus direitos.</li></ul><p>Sistemas bem construídos já facilitam boa parte dessas exigências.</p>"],
        ];
        foreach ($posts as $i => [$title, $cat, $excerpt, $content]) {
            $date = date('Y-m-d H:i:s', strtotime('-' . ($i * 9 + 2) . ' days'));
            db_insert('posts', [
                'slug' => slugify($title), 'title' => $title, 'excerpt' => $excerpt, 'content' => $content,
                'category' => $cat, 'reading_minutes' => 4, 'published' => 1, 'published_at' => $date, 'created_at' => $date,
            ]);
        }
    }
}

function create_project_stages(int $projectId, ?string $currentStageName = null): void
{
    $names = explode('|', (string)setting('project_stage_template', 'Diagnóstico|Planejamento|Desenvolvimento|Implantação|Evolução'));
    $reachedCurrent = $currentStageName === null;
    foreach ($names as $pos => $name) {
        $status = 'pending';
        if ($currentStageName !== null) {
            if ($name === $currentStageName) { $status = 'in_progress'; $reachedCurrent = true; }
            elseif (!$reachedCurrent) $status = 'done';
        } elseif ($pos === 0) {
            $status = 'in_progress';
        }
        db_insert('project_stages', [
            'project_id' => $projectId, 'name' => trim($name), 'position' => $pos, 'status' => $status,
            'completed_at' => $status === 'done' ? now() : null,
        ]);
    }
}
