<?php
declare(strict_types=1);

/**
 * Static site content (pt-BR). Data sourced from the original Integra Code site.
 */

/** Virtual assistant chat widget (disabled for now; set true to re-enable). */
const FEATURE_CHAT = false;

const COMPANY = [
    'name' => 'Integra Code',
    'tagline' => 'Tecnologia que simplifica o seu dia a dia',
    'pitch' => 'Transformamos necessidades do seu negócio em soluções inteligentes.',
    'about' => 'Desenvolvimento de sistemas e soluções. Transformamos necessidades empresariais em sistemas inteligentes. Desenvolvimento sob medida, IA aplicada e suporte contínuo para PMEs.',
    'phone' => '(14) 99853-7913',
    'phone_raw' => '5514998537913',
    'email' => 'dev@integra-code.tech',
    'whatsapp' => 'https://wa.me/5514998537913',
    'hours' => 'Segunda a sexta, 8h às 18h',
    'cnpj' => '68.275.010/0001-73',
    'domain' => 'integra-code.tech',
];

function nav_items(): array
{
    return [
        ['/', 'Início'],
        ['/sobre', 'Sobre'],
        ['/solucoes', 'Soluções'],
        ['/segmentos', 'Segmentos'],
        ['/integra-sys', 'Integra SYS'],
        ['/fiscal-hub', 'Fiscal Hub'],
        ['/suporte', 'Suporte'],
        ['/blog', 'Blog'],
        ['/contato', 'Contato'],
    ];
}

function pains(): array
{
    return [
        ['sheet', 'Informações espalhadas', 'Dados em planilhas, papéis e cadernos que ninguém consegue encontrar quando precisa.'],
        ['repeat', 'Processos manuais e retrabalho', 'A mesma informação digitada várias vezes, gerando erros e horas perdidas.'],
        ['unlink', 'Sistemas que não conversam', 'Loja, financeiro, estoque e marketplaces funcionando como ilhas isoladas.'],
        ['chat', 'Atendimento lento e desorganizado', 'Clientes esperando, mensagens perdidas e nenhum histórico centralizado.'],
        ['chart', 'Falta de relatórios', 'Decisões tomadas no "achismo" por não ter números claros e atualizados.'],
        ['eye', 'Sem rastreio de processos', 'Impossível saber em que etapa está um pedido, projeto ou solicitação.'],
    ];
}

function services(): array
{
    return [
        'sistemas-web' => [
            'icon' => 'browser', 'title' => 'Sistemas Web',
            'short' => 'Plataformas completas para gestão, vendas e operação do seu negócio.',
            'items' => ['ERPs sob medida', 'CRMs e funis de vendas', 'Dashboards gerenciais', 'Portais do cliente', 'Controle de estoque e pedidos'],
            'text' => 'Desenvolvemos sistemas web sob medida que centralizam a operação da sua empresa em um só lugar, acessível de qualquer dispositivo, com segurança e alta performance.',
        ],
        'aplicativos-mobile' => [
            'icon' => 'phone', 'title' => 'Aplicativos Mobile',
            'short' => 'Apps nativos para iOS e Android com notificações e modo offline.',
            'items' => ['Apps iOS e Android', 'Notificações push', 'Modo offline', 'Integração com o seu sistema', 'Publicação nas lojas'],
            'text' => 'Leve seu negócio para o bolso do cliente e da sua equipe com aplicativos rápidos, intuitivos e integrados aos seus sistemas.',
        ],
        'inteligencia-artificial' => [
            'icon' => 'brain', 'title' => 'Inteligência Artificial',
            'short' => 'Chatbots, análise preditiva, leitura de documentos e BI conversacional.',
            'items' => ['Chatbots inteligentes', 'Análise preditiva', 'Leitura e análise de documentos', 'BI conversacional', 'Automação com IA'],
            'text' => 'Aplicamos IA de forma prática: atendimento automatizado, previsões de vendas e estoque, extração de dados de documentos e perguntas em linguagem natural sobre os seus números.',
        ],
        'integracoes' => [
            'icon' => 'plug', 'title' => 'Integrações',
            'short' => 'Conecte iFood, Mercado Livre, Stone, WhatsApp Business e ERPs.',
            'items' => ['iFood e delivery', 'Mercado Livre e marketplaces', 'Stone e meios de pagamento', 'WhatsApp Business API', 'Integração entre ERPs', 'Bancos (Asaas, PIX, boletos)'],
            'text' => 'Fazemos seus sistemas conversarem entre si: pedidos, estoque, financeiro e atendimento sincronizados automaticamente, sem digitação dupla.',
        ],
        'automacao' => [
            'icon' => 'bolt', 'title' => 'Automação de Processos',
            'short' => 'Cobranças automáticas, notificações e fluxos de aprovação.',
            'items' => ['Cobranças automáticas', 'Notificações por e-mail e WhatsApp', 'Fluxos de aprovação', 'Rotinas agendadas', 'Conciliação automática'],
            'text' => 'Eliminamos tarefas repetitivas com automações que trabalham 24 horas por dia, liberando sua equipe para o que realmente importa.',
        ],
        'consultoria' => [
            'icon' => 'compass', 'title' => 'Consultoria Técnica',
            'short' => 'Diagnóstico gratuito, roadmap tecnológico e redução de custos.',
            'items' => ['Diagnóstico gratuito', 'Roadmap de tecnologia', 'Redução de custos com software', 'Escolha de ferramentas', 'Adequação à LGPD'],
            'text' => 'Analisamos seus processos e ferramentas atuais e entregamos um plano claro de evolução tecnológica, priorizado pelo retorno para o seu negócio.',
        ],
        'suporte-sla' => [
            'icon' => 'shield', 'title' => 'Suporte & SLA',
            'short' => 'Monitoramento 24/7, SLA garantido e sistema de chamados.',
            'items' => ['Monitoramento 24/7', 'SLA garantido em contrato', 'Sistema de chamados', 'Backups automáticos', 'Atualizações contínuas'],
            'text' => 'Seu sistema não para. Monitoramos, mantemos e evoluímos as soluções entregues, com prazos de atendimento definidos em contrato.',
        ],
        'dashboards-bi' => [
            'icon' => 'chart', 'title' => 'Dashboards & BI',
            'short' => 'KPIs em tempo real, exportação PDF/Excel e relatórios automáticos.',
            'items' => ['KPIs em tempo real', 'Exportação PDF e Excel', 'Relatórios automáticos', 'Metas e alertas', 'Visão por unidade/filial'],
            'text' => 'Transformamos dados em decisões: painéis visuais com os indicadores que importam, atualizados automaticamente e disponíveis em qualquer lugar.',
        ],
    ];
}

function methodology(): array
{
    return [
        ['01', 'Diagnóstico', 'Entendemos seu negócio, seus processos e onde estão as maiores perdas de tempo e dinheiro.'],
        ['02', 'Planejamento', 'Definimos escopo, prioridades, prazos e investimento com total transparência.'],
        ['03', 'Desenvolvimento', 'Construímos em ciclos curtos, com entregas frequentes para você acompanhar e validar.'],
        ['04', 'Implantação', 'Colocamos no ar, migramos dados e treinamos a sua equipe.'],
        ['05', 'Evolução', 'Suporte contínuo, melhorias e novas funcionalidades conforme o negócio cresce.'],
    ];
}

function segments(): array
{
    return [
        'supermercados' => ['cart', 'Supermercados', 'Controle de estoque, validade, compras, PDV integrado e promoções.', ['Gestão de estoque e validade', 'Integração com PDV', 'Pedidos de compra automáticos', 'Delivery e e-commerce']],
        'farmacias' => ['pill', 'Farmácias', 'Controle de lotes, receitas, convênios e fidelidade.', ['Lotes e rastreabilidade', 'Programa de fidelidade', 'Integração com convênios', 'Alertas de reposição']],
        'restaurantes' => ['food', 'Restaurantes', 'Comandas, cardápio digital, iFood e controle de insumos.', ['Integração com iFood', 'Cardápio digital com QR Code', 'Ficha técnica e CMV', 'Gestão de mesas e comandas']],
        'varejo' => ['bag', 'Varejo', 'Vendas multicanal, marketplaces e gestão de lojas.', ['Mercado Livre e marketplaces', 'Estoque multi-loja', 'CRM e pós-venda', 'Campanhas no WhatsApp']],
        'logistica' => ['truck', 'Logística', 'Rastreio de entregas, roteirização e controle de frota.', ['Rastreamento em tempo real', 'Roteirização', 'Controle de frota', 'Comprovante digital de entrega']],
        'agronegocio' => ['leaf', 'Agronegócio', 'Gestão de safra, insumos, maquinário e custos por talhão.', ['Custos por safra e talhão', 'Controle de insumos', 'Manutenção de maquinário', 'Relatórios de produtividade']],
        'clinicas' => ['heart', 'Clínicas', 'Agenda online, prontuário, confirmações e financeiro.', ['Agenda online', 'Confirmação por WhatsApp', 'Prontuário eletrônico', 'Faturamento e convênios']],
        'servicos' => ['tool', 'Prestadores de Serviço', 'Ordens de serviço, contratos recorrentes e cobrança automática.', ['Ordens de serviço', 'Contratos recorrentes', 'Cobrança automática (PIX/boleto)', 'App para técnicos em campo']],
    ];
}

function differentials(): array
{
    return [
        ['handshake', 'Atendimento próximo', 'Você fala direto com quem desenvolve. Sem call center, sem intermediários.'],
        ['lock', 'Segurança de dados', 'Backups automáticos, criptografia e conformidade total com a LGPD.'],
        ['rocket', 'Tecnologia moderna', 'Stack atual, rápida e escalável, pronta para crescer com o seu negócio.'],
        ['puzzle', 'Sob medida', 'Nada de adaptar sua empresa ao sistema: o sistema se adapta a você.'],
    ];
}

function sys_modules(): array
{
    return [
        ['cash', 'Financeiro', 'Contas a pagar e receber, fluxo de caixa, conciliação bancária e DRE.'],
        ['box', 'Estoque', 'Entradas, saídas, inventário, curva ABC e alertas de reposição.'],
        ['cart', 'Vendas & PDV', 'Pedidos, orçamentos, frente de caixa e comissões.'],
        ['users', 'CRM', 'Funil de vendas, histórico de clientes e campanhas.'],
        ['file', 'Fiscal', 'Emissão de NF-e, NFC-e e NFS-e integrada.'],
        ['chart', 'BI & Relatórios', 'Painéis em tempo real e relatórios automáticos.'],
        ['chat', 'Atendimento', 'WhatsApp integrado, chamados e chatbot com IA.'],
        ['plug', 'Integrações', 'Bancos, marketplaces, delivery e meios de pagamento.'],
    ];
}

function help_categories(): array
{
    return [
        'primeiros-passos' => ['rocket', 'Primeiros passos'],
        'financeiro' => ['cash', 'Financeiro e cobranças'],
        'projetos' => ['compass', 'Projetos e prazos'],
        'suporte' => ['shield', 'Suporte técnico'],
        'seguranca' => ['lock', 'Segurança e LGPD'],
        'integra-sys' => ['box', 'Integra SYS'],
    ];
}

function ticket_categories(): array
{
    return [
        'duvida' => 'Dúvida',
        'problema' => 'Problema técnico',
        'melhoria' => 'Sugestão de melhoria',
        'financeiro' => 'Financeiro',
        'comercial' => 'Comercial',
    ];
}
