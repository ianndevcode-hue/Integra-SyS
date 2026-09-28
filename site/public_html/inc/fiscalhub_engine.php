<?php
declare(strict_types=1);

/**
 * Integra Fiscal Hub — multi-company NFS-e engine.
 *
 * Each customer registers one or more emitters (fh_emitters) with their own certificate / SIGISS
 * password. Invoices (fh_invoices) are built from an emitter + taker + service and transmitted to:
 *  - Emissor Nacional (Sefin): full DPS v1.01 (substitution, intermediary, place of service, foreign
 *    trade, construction, events, deductions, municipal benefits, suspended liability, immunity,
 *    federal withholdings, approximate taxes), validated against the official XSDs and signed.
 *  - SIGISS Marília: every field of tcDescricaoRps, in the WSDL order.
 */

/** Unified ISS situation → [tribISSQN (nacional), tpRetISSQN, SIGISS situacao, label] */
const FH_ISS_SITUATIONS = [
    'tp' => ['1', '1', 'tp', 'Tributável — ISS devido pelo prestador'],
    'tt' => ['1', '2', 'tt', 'Tributável — ISS retido pelo tomador'],
    'ti' => ['1', '3', 'tt', 'Tributável — ISS retido pelo intermediário'],
    'is' => ['1', '1', 'is', 'Isenta (benefício municipal)'],
    'im' => ['2', '1', 'im', 'Imune (Constituição, art. 150)'],
    'nt' => ['4', '1', 'nt', 'Não incidência'],
    'ex' => ['3', '1', 'nt', 'Exportação de serviço'],
    'es' => ['1', '1', 'tp', 'Exigibilidade suspensa (decisão judicial ou processo administrativo)'],
];
const FH_IMUNIDADES = ['0' => 'Não informado', '1' => 'Patrimônio, renda ou serviços entre entes públicos (art. 150, VI, a)', '2' => 'Templos de qualquer culto (art. 150, VI, b)',
    '3' => 'Partidos, sindicatos de trabalhadores, educação e assistência social sem fins lucrativos (art. 150, VI, c)', '4' => 'Livros, jornais, periódicos e papel (art. 150, VI, d)',
    '5' => 'Fonogramas e videofonogramas musicais de autores brasileiros (art. 150, VI, e)'];
const FH_REG_ESP = ['0' => 'Nenhum', '1' => 'Ato cooperado (cooperativa)', '2' => 'Estimativa', '3' => 'Microempresa municipal', '4' => 'Notário ou registrador', '5' => 'Profissional autônomo', '6' => 'Sociedade de profissionais', '9' => 'Outros'];
const FH_DED_TYPES = ['1' => 'Alimentação e bebidas/frigobar', '2' => 'Materiais', '3' => 'Produção externa', '4' => 'Reembolso de despesas', '5' => 'Repasse consorciado', '6' => 'Repasse plano de saúde', '7' => 'Serviços', '8' => 'Subempreitada de mão de obra', '9' => 'Profissional parceiro', '99' => 'Outras deduções'];

/** Most used LC 116/2003 sub-items (code, description). The national code uses the "01" split by default. */
function fh_lc116_list(): array
{
    return [
        ['01.01', 'Análise e desenvolvimento de sistemas'], ['01.02', 'Programação'], ['01.03', 'Processamento, armazenamento ou hospedagem de dados, textos, imagens, vídeos, páginas e aplicativos'],
        ['01.04', 'Elaboração de programas de computadores, inclusive de jogos eletrônicos'], ['01.05', 'Licenciamento ou cessão de direito de uso de programas de computação'],
        ['01.06', 'Assessoria e consultoria em informática'], ['01.07', 'Suporte técnico em informática, instalação, configuração e manutenção de programas e bancos de dados'],
        ['01.08', 'Planejamento, confecção, manutenção e atualização de páginas eletrônicas'], ['01.09', 'Disponibilização de conteúdos de áudio, vídeo, imagem e texto pela internet (streaming)'],
        ['02.01', 'Serviços de pesquisas e desenvolvimento de qualquer natureza'], ['03.02', 'Cessão de direito de uso de marcas e de sinais de propaganda'],
        ['03.03', 'Exploração de salões de festas, centro de convenções, escritórios virtuais, stands, quadras e auditórios'], ['03.04', 'Locação, sublocação, arrendamento, direito de passagem ou permissão de uso de ferrovia, rodovia, postes, cabos, dutos'],
        ['04.01', 'Medicina e biomedicina'], ['04.02', 'Análises clínicas, patologia, eletricidade médica, radioterapia, quimioterapia, ultrassonografia, ressonância, radiologia, tomografia'],
        ['04.03', 'Hospitais, clínicas, laboratórios, sanatórios, manicômios, casas de saúde, prontos-socorros, ambulatórios'], ['04.04', 'Instrumentação cirúrgica'], ['04.05', 'Acupuntura'],
        ['04.06', 'Enfermagem, inclusive serviços auxiliares'], ['04.07', 'Serviços farmacêuticos'], ['04.08', 'Terapia ocupacional, fisioterapia e fonoaudiologia'],
        ['04.09', 'Terapias de qualquer espécie destinadas ao tratamento físico, orgânico e mental'], ['04.10', 'Nutrição'], ['04.11', 'Obstetrícia'], ['04.12', 'Odontologia'],
        ['04.13', 'Ortóptica'], ['04.14', 'Próteses sob encomenda'], ['04.15', 'Psicanálise'], ['04.16', 'Psicologia'], ['04.17', 'Casas de repouso, creches, asilos e congêneres'],
        ['04.18', 'Inseminação artificial, fertilização in vitro e congêneres'], ['04.19', 'Bancos de sangue, leite, pele, olhos, óvulos, sêmen e congêneres'],
        ['04.20', 'Coleta de sangue, leite, tecidos, sêmen, órgãos e materiais biológicos'], ['04.21', 'Unidade de atendimento, assistência ou tratamento móvel'],
        ['04.22', 'Planos de medicina de grupo ou individual e convênios'], ['04.23', 'Outros planos de saúde'],
        ['05.01', 'Medicina veterinária e zootecnia'], ['05.02', 'Hospitais, clínicas, ambulatórios e prontos-socorros veterinários'], ['05.03', 'Laboratórios de análise na área veterinária'],
        ['05.07', 'Unidade de atendimento, assistência ou tratamento móvel veterinário'], ['05.08', 'Guarda, tratamento, amestramento, embelezamento, alojamento de animais'],
        ['06.01', 'Barbearia, cabeleireiros, manicuros, pedicuros e congêneres'], ['06.02', 'Esteticistas, tratamento de pele, depilação e congêneres'],
        ['06.03', 'Banhos, duchas, sauna, massagens e congêneres'], ['06.04', 'Ginástica, dança, esportes, natação, artes marciais e demais atividades físicas'],
        ['06.05', 'Centros de emagrecimento, spa e congêneres'], ['06.06', 'Aplicação de tatuagens, piercings e congêneres'],
        ['07.01', 'Engenharia, agronomia, agrimensura, arquitetura, geologia, urbanismo, paisagismo e congêneres'],
        ['07.02', 'Execução, por administração, empreitada ou subempreitada, de obras de construção civil, hidráulica ou elétrica'],
        ['07.03', 'Elaboração de planos diretores, estudos de viabilidade, projetos básicos e projetos executivos para obras de engenharia'],
        ['07.04', 'Demolição'], ['07.05', 'Reparação, conservação e reforma de edifícios, estradas, pontes, portos e congêneres'],
        ['07.06', 'Colocação e instalação de tapetes, carpetes, assoalhos, cortinas, revestimentos, vidros, divisórias, papel de parede'],
        ['07.07', 'Recuperação, raspagem, polimento e lustração de pisos e congêneres'], ['07.08', 'Calafetação'], ['07.09', 'Varrição, coleta, remoção, incineração, tratamento e reciclagem de lixo'],
        ['07.10', 'Limpeza, manutenção e conservação de vias, imóveis, chaminés, piscinas, parques, jardins e congêneres'], ['07.11', 'Decoração e jardinagem, inclusive corte e poda de árvores'],
        ['07.12', 'Controle e tratamento de efluentes e agentes físicos, químicos e biológicos'], ['07.13', 'Dedetização, desinfecção, desinsetização, imunização, higienização, desratização'],
        ['07.16', 'Florestamento, reflorestamento, semeadura, adubação e congêneres'], ['07.17', 'Escoramento, contenção de encostas e serviços congêneres'],
        ['07.18', 'Limpeza e dragagem de rios, portos, canais, lagos e congêneres'], ['07.19', 'Acompanhamento e fiscalização da execução de obras de engenharia e arquitetura'],
        ['07.20', 'Aerofotogrametria, cartografia, mapeamento, levantamentos topográficos e congêneres'], ['07.21', 'Pesquisa, perfuração, cimentação, mergulho, perfilagem e congêneres (petróleo e gás)'],
        ['07.22', 'Nucleação e bombardeamento de nuvens e congêneres'],
        ['08.01', 'Ensino regular pré-escolar, fundamental, médio e superior'], ['08.02', 'Instrução, treinamento, orientação pedagógica e educacional, avaliação de conhecimentos'],
        ['09.01', 'Hospedagem em hotéis, apart-service, flats, motéis, pensões e congêneres'], ['09.02', 'Agenciamento, organização, promoção e execução de programas de turismo, passeios, viagens, excursões'],
        ['09.03', 'Guias de turismo'],
        ['10.01', 'Agenciamento, corretagem ou intermediação de câmbio, seguros, cartões de crédito, planos de saúde e previdência privada'],
        ['10.02', 'Agenciamento, corretagem ou intermediação de títulos em geral, valores mobiliários e contratos quaisquer'],
        ['10.03', 'Agenciamento, corretagem ou intermediação de direitos de propriedade industrial, artística ou literária'], ['10.04', 'Agenciamento, corretagem ou intermediação de contratos de arrendamento mercantil (leasing), franquia e faturização'],
        ['10.05', 'Agenciamento, corretagem ou intermediação de bens móveis ou imóveis'], ['10.06', 'Agenciamento marítimo'], ['10.07', 'Agenciamento de notícias'],
        ['10.08', 'Agenciamento de publicidade e propaganda, inclusive veiculação por quaisquer meios'], ['10.09', 'Representação de qualquer natureza, inclusive comercial'], ['10.10', 'Distribuição de bens de terceiros'],
        ['11.01', 'Guarda e estacionamento de veículos, aeronaves e embarcações'], ['11.02', 'Vigilância, segurança ou monitoramento de bens, pessoas e semoventes'],
        ['11.03', 'Escolta, inclusive de veículos e cargas'], ['11.04', 'Armazenamento, depósito, carga, descarga, arrumação e guarda de bens'],
        ['12.01', 'Espetáculos teatrais'], ['12.02', 'Exibições cinematográficas'], ['12.03', 'Espetáculos circenses'], ['12.07', 'Shows, ballet, danças, desfiles, bailes, óperas, concertos, recitais, festivais'],
        ['12.08', 'Feiras, exposições, congressos e congêneres'], ['12.11', 'Competições esportivas ou de destreza física ou intelectual'], ['12.13', 'Produção de eventos, espetáculos, entrevistas, shows, ballet, danças e congêneres'],
        ['12.14', 'Fornecimento de música para ambientes fechados ou não'], ['12.15', 'Desfiles de blocos carnavalescos ou folclóricos, trios elétricos'], ['12.16', 'Exibição de filmes, entrevistas, musicais, espetáculos, shows e concertos'],
        ['12.17', 'Recreação e animação, inclusive em festas e eventos de qualquer natureza'],
        ['13.02', 'Fonografia ou gravação de sons, inclusive trucagem, dublagem, mixagem e congêneres'], ['13.03', 'Fotografia e cinematografia, inclusive revelação, ampliação, cópia, reprodução, trucagem'],
        ['13.04', 'Reprografia, microfilmagem e digitalização'], ['13.05', 'Composição gráfica, inclusive confecção de impressos gráficos'],
        ['14.01', 'Lubrificação, limpeza, lustração, revisão, carga, conserto, restauração, manutenção de máquinas, veículos, aparelhos e equipamentos'],
        ['14.02', 'Assistência técnica'], ['14.03', 'Recondicionamento de motores'], ['14.04', 'Recauchutagem ou regeneração de pneus'],
        ['14.05', 'Restauração, recondicionamento, acondicionamento, pintura, beneficiamento, lavagem, secagem, tingimento de objetos'],
        ['14.06', 'Instalação e montagem de aparelhos, máquinas e equipamentos prestados ao usuário final'], ['14.07', 'Colocação de molduras e congêneres'],
        ['14.08', 'Encadernação, gravação e douração de livros, revistas e congêneres'], ['14.09', 'Alfaiataria e costura, quando o material for fornecido pelo usuário final'], ['14.10', 'Tinturaria e lavanderia'],
        ['14.11', 'Tapeçaria e reforma de estofamentos em geral'], ['14.12', 'Funilaria e lanternagem'], ['14.13', 'Carpintaria e serralheria'], ['14.14', 'Guincho intramunicipal, guindaste e içamento'],
        ['16.01', 'Transporte coletivo municipal rodoviário, metroviário, ferroviário e aquaviário de passageiros'], ['16.02', 'Outros serviços de transporte de natureza municipal'],
        ['17.01', 'Assessoria ou consultoria de qualquer natureza, análise, exame, pesquisa, coleta, compilação e fornecimento de dados e informações'],
        ['17.02', 'Datilografia, digitação, estenografia, expediente, secretaria em geral, resposta audível, redação, edição, revisão, apoio e infraestrutura administrativa'],
        ['17.03', 'Planejamento, coordenação, programação ou organização técnica, financeira ou administrativa'], ['17.04', 'Recrutamento, agenciamento, seleção e colocação de mão de obra'],
        ['17.05', 'Fornecimento de mão de obra, mesmo em caráter temporário'], ['17.06', 'Propaganda e publicidade, inclusive promoção de vendas, planejamento de campanhas ou sistemas de publicidade'],
        ['17.08', 'Franquia (franchising)'], ['17.09', 'Perícias, laudos, exames técnicos e análises técnicas'], ['17.10', 'Planejamento, organização e administração de feiras, exposições, congressos e congêneres'],
        ['17.11', 'Organização de festas e recepções; bufê'], ['17.12', 'Administração em geral, inclusive de bens e negócios de terceiros'], ['17.13', 'Leilão e congêneres'], ['17.14', 'Advocacia'],
        ['17.15', 'Arbitragem de qualquer espécie, inclusive jurídica'], ['17.16', 'Auditoria'], ['17.17', 'Análise de Organização e Métodos'], ['17.18', 'Atuária e cálculos técnicos de qualquer natureza'],
        ['17.19', 'Contabilidade, inclusive serviços técnicos e auxiliares'], ['17.20', 'Consultoria e assessoria econômica ou financeira'], ['17.21', 'Estatística'], ['17.22', 'Cobrança em geral'],
        ['17.23', 'Assessoria, análise, avaliação, atendimento, consulta, cadastro, seleção, gerenciamento de informações (factoring)'],
        ['17.24', 'Apresentação de palestras, conferências, seminários e congêneres'], ['17.25', 'Inserção de textos, desenhos e outros materiais de propaganda e publicidade em qualquer meio'],
        ['18.01', 'Regulação de sinistros, inspeção e avaliação de riscos para cobertura de contratos de seguros'],
        ['19.01', 'Distribuição e venda de bilhetes e demais produtos de loteria, bingos, cartões, pules ou cupons de apostas'],
        ['21.01', 'Registros públicos, cartorários e notariais'], ['22.01', 'Exploração de rodovia mediante cobrança de preço ou pedágio'],
        ['23.01', 'Programação e comunicação visual, desenho industrial e congêneres'], ['24.01', 'Chaveiros, confecção de carimbos, placas, sinalização visual, banners, adesivos e congêneres'],
        ['25.01', 'Funerais, inclusive fornecimento de caixão, urna ou esquifes'], ['25.02', 'Translado intramunicipal e cremação de corpos e partes de corpos cadavéricos'],
        ['26.01', 'Coleta, remessa ou entrega de correspondências, documentos, objetos, bens ou valores (courier)'],
        ['27.01', 'Serviços de assistência social'], ['28.01', 'Serviços de avaliação de bens e serviços de qualquer natureza'], ['29.01', 'Serviços de biblioteconomia'],
        ['30.01', 'Serviços de biologia, biotecnologia e química'], ['31.01', 'Serviços técnicos em edificações, eletrônica, eletrotécnica, mecânica, telecomunicações e congêneres'],
        ['32.01', 'Serviços de desenhos técnicos'], ['33.01', 'Serviços de desembaraço aduaneiro, comissários, despachantes e congêneres'],
        ['34.01', 'Serviços de investigações particulares, detetives e congêneres'], ['35.01', 'Serviços de reportagem, assessoria de imprensa, jornalismo e relações públicas'],
        ['36.01', 'Serviços de meteorologia'], ['37.01', 'Serviços de artistas, atletas, modelos e manequins'], ['38.01', 'Serviços de museologia'],
        ['39.01', 'Serviços de ourivesaria e lapidação (material fornecido pelo tomador)'], ['40.01', 'Obras de arte sob encomenda'],
    ];
}

function fh_lc_parts(string $lc): ?array
{
    if (!preg_match('/^(\d{1,2})\.?(\d{2})$/', trim($lc), $m)) return null;
    return [(int)$m[1], $m[2]];
}

function fh_lc_to_ctribnac(string $lc): string
{
    $p = fh_lc_parts($lc);
    return $p ? str_pad((string)$p[0], 2, '0', STR_PAD_LEFT) . $p[1] . '01' : '';
}

function fh_lc_to_sigiss(string $lc): string
{
    $p = fh_lc_parts($lc);
    return $p ? $p[0] . $p[1] : '';
}

/* ================================================================ EMITTERS */

const FH_MARILIA_IBGE = '3529005';
const FH_EMITTER_FIELDS = ['legal_name', 'trade_name', 'im', 'ie', 'cnae', 'email', 'phone', 'cep', 'street', 'number', 'complement', 'district', 'city', 'uf', 'city_ibge',
    'op_simp_nac', 'reg_ap_trib_sn', 'reg_esp_trib', 'simples_rate', 'total_tax_pct', 'provider', 'environment', 'sigiss_crc', 'sigiss_crc_uf', 'dps_serie', 'next_number',
    'iss_rate', 'pis_rate', 'cofins_rate', 'csll_rate', 'irrf_rate', 'inss_rate', 'pis_cofins_cst', 'withhold_federal_pj', 'show_taxes', 'auto_email', 'email_message', 'default_service_id', 'active'];

function fh_emitter(int $customerId, int $id): array
{
    $em = db_one('SELECT * FROM fh_emitters WHERE id = ? AND customer_id = ?', [$id, $customerId]);
    if (!$em) throw new AppException('Empresa emissora não encontrada.');
    return $em;
}

/** Public view of an emitter (no secrets). */
function fh_emitter_public(array $em): array
{
    $out = $em;
    unset($out['cert_pfx'], $out['cert_password'], $out['sigiss_password']);
    $out['has_certificate'] = !empty($em['cert_pfx']);
    $out['has_sigiss_password'] = !empty($em['sigiss_password']) && secret_readable($em['sigiss_password']);
    $out['has_certificate'] = $out['has_certificate'] && secret_readable($em['cert_pfx']);
    $out['cert_expired'] = $em['cert_valid_to'] && $em['cert_valid_to'] < today();
    $out['cert_days_left'] = $em['cert_valid_to'] ? (int)floor((strtotime($em['cert_valid_to']) - strtotime(today())) / 86400) : null;
    $out['ready'] = fh_emitter_problems($em) === [];
    $out['problems'] = fh_emitter_problems($em);
    return $out;
}

function fh_emitter_problems(array $em): array
{
    $p = [];
    if (!fh_valid_doc((string)$em['document'])) $p[] = 'CNPJ/CPF do emissor inválido.';
    if (trim((string)$em['legal_name']) === '') $p[] = 'Informe a razão social.';
    if ($em['provider'] === 'sigiss') {
        if (!only_digits((string)$em['im'])) $p[] = 'Informe a inscrição municipal (CCM) de Marília.';
        if (empty($em['sigiss_password'])) $p[] = 'Informe a senha do SIGISS (a mesma do portal da prefeitura).';
        elseif (!secret_readable($em['sigiss_password'])) $p[] = FH_SECRET_LOST_SIGISS;
    } else {
        if (strlen((string)$em['city_ibge']) !== 7) $p[] = 'Informe o município (código IBGE) do emissor — preencha pelo CEP.';
        if (empty($em['cert_pfx'])) $p[] = 'Envie o certificado digital A1 (.pfx) do emissor.';
        elseif (!secret_readable($em['cert_pfx']) || !secret_readable($em['cert_password'] ?? null)) $p[] = FH_SECRET_LOST_CERT;
        elseif ($em['cert_valid_to'] && $em['cert_valid_to'] < today()) $p[] = 'O certificado digital está vencido.';
    }
    return $p;
}

function fh_emitter_save(int $customerId, array $in, ?array $existing): array
{
    $access = fh_access($customerId);
    if (!$existing && $access['usage']['companies'] >= $access['usage']['companies_limit']) {
        throw new AppException('Seu plano permite ' . $access['usage']['companies_limit'] . ' empresa(s). Faça upgrade para cadastrar mais.');
    }
    $d = [];
    foreach (FH_EMITTER_FIELDS as $f) if (array_key_exists($f, $in)) $d[$f] = is_string($in[$f]) ? trim($in[$f]) : $in[$f];
    if (!$existing || isset($in['document'])) {
        $doc = only_digits((string)($in['document'] ?? $existing['document'] ?? ''));
        if (!fh_valid_doc($doc)) throw new AppException('Informe um CNPJ ou CPF válido.');
        $dup = db_value('SELECT id FROM fh_emitters WHERE customer_id = ? AND document = ?' . ($existing ? ' AND id != ?' : ''), $existing ? [$customerId, $doc, $existing['id']] : [$customerId, $doc]);
        if ($dup) throw new AppException('Esta empresa já está cadastrada.');
        $d['document'] = $doc;
    }
    foreach (['im', 'ie', 'cep', 'city_ibge', 'cnae'] as $k) if (isset($d[$k])) $d[$k] = only_digits((string)$d[$k]) ?: null;
    foreach (['simples_rate', 'total_tax_pct', 'iss_rate'] as $k) if (isset($d[$k])) $d[$k] = max(0, min(100, (float)str_replace(',', '.', (string)$d[$k])));
    foreach (['pis_rate', 'cofins_rate', 'csll_rate', 'irrf_rate', 'inss_rate'] as $k) if (array_key_exists($k, $d)) $d[$k] = $d[$k] === '' || $d[$k] === null ? null : max(0, min(100, (float)str_replace(',', '.', (string)$d[$k])));
    foreach (['withhold_federal_pj', 'show_taxes', 'auto_email', 'active'] as $k) if (isset($d[$k])) $d[$k] = filter_var($d[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    if (isset($d['op_simp_nac']) && !in_array($d['op_simp_nac'], ['1', '2', '3'], true)) $d['op_simp_nac'] = '3';
    if (isset($d['reg_ap_trib_sn']) && !in_array($d['reg_ap_trib_sn'], ['', '1', '2', '3'], true)) $d['reg_ap_trib_sn'] = '';
    if (isset($d['reg_esp_trib']) && !isset(FH_REG_ESP[$d['reg_esp_trib']])) $d['reg_esp_trib'] = '0';
    if (isset($d['provider']) && !in_array($d['provider'], ['sigiss', 'nacional'], true)) $d['provider'] = 'sigiss';
    if (isset($d['environment']) && !in_array($d['environment'], ['homologation', 'production'], true)) $d['environment'] = 'homologation';
    if (isset($d['pis_cofins_cst']) && $d['pis_cofins_cst'] !== '' && !preg_match('/^\d{2}$/', (string)$d['pis_cofins_cst'])) $d['pis_cofins_cst'] = null;
    if (isset($d['dps_serie'])) $d['dps_serie'] = preg_match('/^\d{1,5}$/', (string)$d['dps_serie']) ? (string)(int)$d['dps_serie'] : '1';
    if (isset($d['next_number'])) $d['next_number'] = max(1, (int)$d['next_number']);
    if (isset($d['uf'])) $d['uf'] = mb_strtoupper(mb_substr((string)$d['uf'], 0, 2));
    if (isset($d['default_service_id'])) $d['default_service_id'] = (int)$d['default_service_id'] ?: null;
    // SIGISS has no test environment (forced to production); switching to the national emitter starts in "produção restrita".
    if (($d['provider'] ?? null) === 'nacional' && ($existing['provider'] ?? 'sigiss') !== 'nacional' && !array_key_exists('environment', $in)) $d['environment'] = 'homologation';
    if (($d['provider'] ?? $existing['provider'] ?? 'sigiss') === 'sigiss') { $d['environment'] = 'production'; $d['city_ibge'] = $d['city_ibge'] ?? ($existing['city_ibge'] ?? null) ?: FH_MARILIA_IBGE; }
    if (array_key_exists('sigiss_password', $in) && $in['sigiss_password'] !== '' && strpos((string)$in['sigiss_password'], '•') === false) $d['sigiss_password'] = encrypt_secret((string)$in['sigiss_password']);
    if (!$existing && trim((string)($d['legal_name'] ?? '')) === '') throw new AppException('Informe a razão social.');
    foreach (['legal_name' => 200, 'trade_name' => 200, 'street' => 160, 'district' => 80, 'city' => 80, 'complement' => 80, 'number' => 20, 'email' => 190, 'phone' => 30] as $k => $max) if (isset($d[$k])) $d[$k] = mb_substr((string)$d[$k], 0, $max);
    if ($existing) {
        db_update('fh_emitters', (int)$existing['id'], $d + ['updated_at' => now()]);
        return db_find('fh_emitters', (int)$existing['id']);
    }
    $id = db_insert('fh_emitters', $d + ['customer_id' => $customerId, 'op_simp_nac' => $d['op_simp_nac'] ?? '3', 'provider' => $d['provider'] ?? 'sigiss', 'created_at' => now(), 'updated_at' => now()]);
    return db_find('fh_emitters', $id);
}

function fh_emitter_certificate(array $em, string $pfxB64, string $password): array
{
    $pfx = base64_decode($pfxB64, true);
    if (!$pfx || strlen($pfx) > 200000) throw new AppException('Envie o arquivo .pfx (ou .p12) do certificado A1.');
    $info = nfse_read_pfx($pfx, $password)['info'];
    if ($info['expired']) throw new AppException('Este certificado está vencido (validade ' . date('d/m/Y', strtotime($info['valid_to'])) . ').');
    $warn = null;
    if ($info['cnpj'] && $info['cnpj'] !== $em['document']) $warn = 'Atenção: o certificado é do CNPJ ' . $info['cnpj'] . ', diferente do emissor (' . $em['document'] . '). Use o certificado da própria empresa.';
    db_update('fh_emitters', (int)$em['id'], ['cert_pfx' => encrypt_secret(base64_encode($pfx)), 'cert_password' => encrypt_secret($password), 'cert_subject' => mb_substr($info['subject'], 0, 255), 'cert_valid_to' => $info['valid_to'], 'updated_at' => now()]);
    return ['info' => $info, 'warning' => $warn];
}

const FH_SECRET_LOST_SIGISS = 'A senha do SIGISS salva não pode mais ser lida (a chave de segurança do site mudou na reinstalação). Digite a senha do SIGISS de novo em Empresa e certificado.';
const FH_SECRET_LOST_CERT = 'O certificado digital salvo não pode mais ser lido (a chave de segurança do site mudou na reinstalação). Envie o arquivo .pfx e a senha de novo em Empresa e certificado.';

function fh_certificate(array $em): array
{
    if (!empty($em['cert_pfx']) && (!secret_readable($em['cert_pfx']) || !secret_readable($em['cert_password'] ?? null))) throw new NfseException(FH_SECRET_LOST_CERT);
    $pfx = base64_decode(decrypt_secret($em['cert_pfx'] ?? ''), true);
    if (!$pfx) throw new NfseException('Certificado digital A1 não enviado. Abra Empresa → Certificado digital.');
    $c = nfse_read_pfx($pfx, decrypt_secret($em['cert_password'] ?? ''));
    if ($c['info']['expired']) throw new NfseException('O certificado digital está vencido. Envie o certificado renovado.');
    return $c;
}

function fh_sigiss_cfg(array $em): array
{
    if (!secret_readable($em['sigiss_password'] ?? null)) throw new NfseException(FH_SECRET_LOST_SIGISS);
    return ['url' => (string)config('fh_sigiss_url', SIGISS_DEFAULT_URL), 'ccm' => only_digits((string)$em['im']), 'cnpj' => $em['document'], 'password' => decrypt_secret($em['sigiss_password'] ?? '')];
}

/** Tax configuration in the shape nfse_taxes() expects. */
function fh_tax_cfg(array $em): array
{
    $non = $em['op_simp_nac'] === '1';
    $rate = fn($k, $def) => $em[$k] === null || $em[$k] === '' ? $def : (float)$em[$k];
    return [
        'op_simp_nac' => (string)$em['op_simp_nac'], 'aliquota' => (float)$em['iss_rate'], 'simples_percent' => (float)$em['simples_rate'], 'total_tax_pct' => (float)$em['total_tax_pct'],
        'pis_rate' => $rate('pis_rate', $non ? 0.65 : 0), 'cofins_rate' => $rate('cofins_rate', $non ? 3 : 0), 'csll_rate' => $rate('csll_rate', $non ? 1 : 0),
        'irrf_rate' => $rate('irrf_rate', $non ? 1.5 : 0), 'inss_rate' => $rate('inss_rate', 0), 'pis_cofins_cst' => $em['pis_cofins_cst'] ?: ($non ? '01' : '49'),
        'withhold_federal_pj' => (bool)$em['withhold_federal_pj'],
    ];
}

function fh_test_connection(array $em): array
{
    if ($em['provider'] === 'sigiss') {
        $cfg = fh_sigiss_cfg($em);
        if (!$cfg['ccm'] || !$cfg['password']) throw new AppException('Informe a inscrição municipal (CCM) e a senha do SIGISS.');
        $xp = sigiss_call('ConsultarNotaPrestador', ['DadosPrestador' => ['type' => 'tns:tcDadosPrestador', 'fields' => ['ccm' => ['xsd:string', $cfg['ccm']], 'cnpj' => ['xsd:string', $cfg['cnpj']], 'senha' => ['xsd:string', $cfg['password']]]],
            'Nota' => ['type' => 'xsd:int', 'value' => 1]], $cfg['url']);
        $auth = sigiss_auth_errors(sigiss_errors($xp));
        return ['ok' => !$auth, 'messages' => $auth ?: ['Acesso ao SIGISS de Marília confirmado.']];
    }
    $cert = fh_certificate($em);
    $base = ['sefin' => nfse_endpoint((string)$em['environment'], 'sefin'), 'adn' => nfse_endpoint((string)$em['environment'], 'adn')];
    $msgs = ['Certificado válido até ' . date('d/m/Y', strtotime($cert['info']['valid_to'])) . ' (' . $cert['info']['subject'] . ').'];
    foreach ([$base['sefin'] . '/parametros_municipais/' . $em['city_ibge'] . '/convenio', $base['adn'] . '/parametros_municipais/' . $em['city_ibge'] . '/convenio'] as $url) {
        try {
            $res = nfse_http('GET', $url, null, $cert);
            if ($res['status'] === 200) return ['ok' => true, 'messages' => array_merge($msgs, ['Município ' . $em['city_ibge'] . ' conveniado ao Emissor Nacional.'])];
            $msgs[] = 'HTTP ' . $res['status'] . ($res['status'] === 403 ? ': certificado não aceito (use o e-CNPJ/e-CPF A1 ICP-Brasil do emissor).' : '');
        } catch (NfseException $e) {
            $msgs[] = $e->getMessage();
        }
    }
    return ['ok' => false, 'messages' => $msgs];
}

/* ========================================================= TAKERS/SERVICES */

const FH_TAKER_FIELDS = ['kind', 'document', 'name', 'trade_name', 'email', 'phone', 'im', 'ie', 'cep', 'street', 'number', 'complement', 'district', 'city', 'uf', 'city_ibge', 'country', 'foreign_city', 'foreign_region', 'foreign_postal', 'nif', 'notes'];

function fh_taker_clean(array $in): array
{
    $d = [];
    foreach (FH_TAKER_FIELDS as $f) if (array_key_exists($f, $in)) $d[$f] = is_string($in[$f]) ? trim($in[$f]) : $in[$f];
    $d['kind'] = in_array($d['kind'] ?? '', ['pj', 'pf', 'ext', 'pfni'], true) ? $d['kind'] : (strlen(only_digits((string)($d['document'] ?? ''))) === 11 ? 'pf' : 'pj');
    foreach (['document', 'im', 'cep', 'city_ibge'] as $k) if (isset($d[$k])) $d[$k] = only_digits((string)$d[$k]) ?: null;
    if (isset($d['country'])) $d['country'] = mb_strtoupper(mb_substr((string)$d['country'], 0, 2)) ?: null;
    if (isset($d['uf'])) $d['uf'] = mb_strtoupper(mb_substr((string)$d['uf'], 0, 2)) ?: null;
    if (isset($d['email'])) $d['email'] = filter_var($d['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower($d['email']) : null;
    if (in_array($d['kind'], ['pj', 'pf'], true)) {
        if (!fh_valid_doc((string)($d['document'] ?? ''))) throw new AppException($d['kind'] === 'pj' ? 'CNPJ do cliente inválido.' : 'CPF do cliente inválido.');
    } else {
        $d['document'] = null;
    }
    if ($d['kind'] === 'ext' && strlen((string)($d['country'] ?? '')) !== 2) throw new AppException('Informe o país do cliente no exterior (código ISO de 2 letras, ex.: US, PT).');
    if ($d['kind'] === 'pfni') $d['name'] = trim((string)($d['name'] ?? '')) ?: 'Consumidor não identificado';
    if (mb_strlen(trim((string)($d['name'] ?? ''))) < 2) throw new AppException('Informe o nome ou razão social do cliente.');
    foreach (['name' => 200, 'trade_name' => 200, 'street' => 160, 'district' => 80, 'city' => 80, 'complement' => 80, 'number' => 20, 'phone' => 30, 'foreign_city' => 80, 'foreign_region' => 80, 'foreign_postal' => 11, 'nif' => 40] as $k => $max) if (isset($d[$k])) $d[$k] = mb_substr((string)$d[$k], 0, $max);
    return $d;
}

function fh_taker_save(array $em, array $in, ?array $existing): array
{
    $d = fh_taker_clean($existing ? array_merge($existing, $in) : $in);
    if ($d['document'] && ($dup = db_value('SELECT id FROM fh_takers WHERE emitter_id = ? AND document = ?' . ($existing ? ' AND id != ?' : ''), $existing ? [$em['id'], $d['document'], $existing['id']] : [$em['id'], $d['document']]))) {
        throw new AppException('Já existe um cliente com este documento (#' . $dup . ').');
    }
    if ($existing) {
        db_update('fh_takers', (int)$existing['id'], $d + ['updated_at' => now()]);
        return db_find('fh_takers', (int)$existing['id']);
    }
    return db_find('fh_takers', db_insert('fh_takers', $d + ['emitter_id' => $em['id'], 'created_at' => now(), 'updated_at' => now()]));
}

function fh_service_save(array $em, array $in, ?array $existing): array
{
    $d = [];
    foreach (['name', 'description', 'lc116', 'ctribnac', 'ctribmun', 'sigiss_code', 'cnbs', 'iss_rate', 'sigiss_situacao', 'price', 'unit', 'pis_rate', 'cofins_rate', 'csll_rate', 'irrf_rate', 'inss_rate', 'active'] as $f) {
        if (array_key_exists($f, $in)) $d[$f] = is_string($in[$f]) ? trim($in[$f]) : $in[$f];
    }
    if (!$existing && mb_strlen((string)($d['name'] ?? '')) < 2) throw new AppException('Informe o nome do serviço.');
    if (isset($d['lc116'])) {
        $p = fh_lc_parts((string)$d['lc116']);
        if (!$p) throw new AppException('Informe o item da lista de serviços (LC 116), ex.: 01.07 ou 17.01.');
        $d['lc116'] = str_pad((string)$p[0], 2, '0', STR_PAD_LEFT) . '.' . $p[1];
        if (empty($d['ctribnac'])) $d['ctribnac'] = fh_lc_to_ctribnac($d['lc116']);
        if (empty($d['sigiss_code'])) $d['sigiss_code'] = fh_lc_to_sigiss($d['lc116']);
    }
    foreach (['ctribnac' => 6, 'ctribmun' => 3, 'cnbs' => 9] as $k => $len) {
        if (isset($d[$k])) {
            $d[$k] = only_digits((string)$d[$k]) ?: null;
            if ($d[$k] !== null && strlen($d[$k]) !== $len) throw new AppException(['ctribnac' => 'O código de tributação nacional tem 6 dígitos (ex.: 010701).', 'ctribmun' => 'O código de tributação municipal tem 3 dígitos.', 'cnbs' => 'O código NBS tem 9 dígitos (ex.: 115022000).'][$k]);
        }
    }
    if (!empty($d['cnbs']) && ($msg = nfse_nbs_problem((string)$d['cnbs'], (string)($d['lc116'] ?? $existing['lc116'] ?? '')))) throw new AppException($msg);
    if (isset($d['sigiss_code'])) $d['sigiss_code'] = only_digits((string)$d['sigiss_code']) ?: null;
    if (isset($d['sigiss_situacao']) && !isset(FH_ISS_SITUATIONS[$d['sigiss_situacao']])) $d['sigiss_situacao'] = 'tp';
    foreach (['iss_rate', 'price', 'pis_rate', 'cofins_rate', 'csll_rate', 'irrf_rate', 'inss_rate'] as $k) if (array_key_exists($k, $d)) $d[$k] = $d[$k] === '' || $d[$k] === null ? null : max(0, (float)str_replace(',', '.', (string)$d[$k]));
    if (isset($d['active'])) $d['active'] = filter_var($d['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    if (isset($d['name'])) $d['name'] = mb_substr((string)$d['name'], 0, 160);
    if ($existing) {
        db_update('fh_services', (int)$existing['id'], $d + ['updated_at' => now()]);
        return db_find('fh_services', (int)$existing['id']);
    }
    return db_find('fh_services', db_insert('fh_services', $d + ['emitter_id' => $em['id'], 'created_at' => now(), 'updated_at' => now()]));
}

/* ================================================================ INVOICES */

function fh_invoice(int $customerId, int $id): array
{
    $inv = db_one('SELECT * FROM fh_invoices WHERE id = ? AND customer_id = ?', [$id, $customerId]);
    if (!$inv) throw new AppException('Nota não encontrada.');
    return $inv;
}

function fh_invoice_public(array $inv): array
{
    $out = $inv;
    unset($out['xml_dps'], $out['xml_nfse'], $out['xml_cancel']);
    $out['extra'] = json_decode((string)$inv['extra'], true) ?: [];
    $out['taker'] = json_decode((string)$inv['toma_json'], true) ?: [];
    unset($out['toma_json']);
    $out['has_xml'] = !empty($inv['xml_nfse']);
    return $out;
}

/** Clean the optional groups of an invoice ("extra"). */
function fh_extra_clean(array $x): array
{
    $s = fn($v, $max = 255) => mb_substr(trim((string)$v), 0, $max);
    $out = [];
    $loc = $x['loc'] ?? [];
    if (($loc['type'] ?? '') === 'outro' && strlen(only_digits((string)($loc['city_ibge'] ?? ''))) === 7) $out['loc'] = ['type' => 'outro', 'city_ibge' => only_digits((string)$loc['city_ibge']), 'city' => $s($loc['city'] ?? '', 80)];
    elseif (($loc['type'] ?? '') === 'exterior' && preg_match('/^[A-Z]{2}$/', strtoupper((string)($loc['country'] ?? '')))) $out['loc'] = ['type' => 'exterior', 'country' => strtoupper($loc['country'])];
    if (!empty($x['imunidade']) && isset(FH_IMUNIDADES[(string)$x['imunidade']])) $out['imunidade'] = (string)$x['imunidade'];
    if (!empty($x['exig']['tipo']) && in_array((string)$x['exig']['tipo'], ['1', '2'], true)) $out['exig'] = ['tipo' => (string)$x['exig']['tipo'], 'processo' => str_pad(only_digits((string)($x['exig']['processo'] ?? '')), 30, '0', STR_PAD_LEFT)];
    if (!empty($x['bm']['numero']) && strlen(only_digits((string)$x['bm']['numero'])) === 14) {
        $out['bm'] = ['numero' => only_digits((string)$x['bm']['numero'])];
        if ((float)($x['bm']['valor'] ?? 0) > 0) $out['bm']['valor'] = round((float)$x['bm']['valor'], 2);
        elseif ((float)($x['bm']['percentual'] ?? 0) > 0) $out['bm']['percentual'] = round(min(100, (float)$x['bm']['percentual']), 2);
    }
    if (!empty($x['pais_resultado']) && preg_match('/^[A-Z]{2}$/', strtoupper((string)$x['pais_resultado']))) $out['pais_resultado'] = strtoupper((string)$x['pais_resultado']);
    if (!empty($x['ded_tipo']) && isset(FH_DED_TYPES[(string)$x['ded_tipo']])) $out['ded_tipo'] = (string)$x['ded_tipo'];
    if (!empty($x['ded_percentual']) && (float)$x['ded_percentual'] > 0) $out['ded_percentual'] = round(min(100, (float)$x['ded_percentual']), 2);
    $i = $x['interm'] ?? [];
    if (!empty($i['name']) && (fh_valid_doc((string)($i['document'] ?? '')) || !empty($i['nif']))) {
        $out['interm'] = ['document' => only_digits((string)($i['document'] ?? '')), 'nif' => $s($i['nif'] ?? '', 40), 'name' => $s($i['name'], 200), 'im' => only_digits((string)($i['im'] ?? '')),
            'email' => filter_var($i['email'] ?? '', FILTER_VALIDATE_EMAIL) ? mb_strtolower($i['email']) : '', 'phone' => only_digits((string)($i['phone'] ?? ''))];
    }
    $o = $x['obra'] ?? [];
    if (!empty($o['codigo']) || !empty($o['cib']) || !empty($o['cep'])) {
        $out['obra'] = array_filter(['inscricao' => $s($o['inscricao'] ?? '', 30), 'codigo' => $s($o['codigo'] ?? '', 30), 'cib' => only_digits((string)($o['cib'] ?? '')) ?: $s($o['cib'] ?? '', 8),
            'cep' => only_digits((string)($o['cep'] ?? '')), 'street' => $s($o['street'] ?? '', 160), 'number' => $s($o['number'] ?? '', 20), 'complement' => $s($o['complement'] ?? '', 80), 'district' => $s($o['district'] ?? '', 80)], fn($v) => $v !== '');
    }
    $e = $x['evento'] ?? [];
    if (!empty($e['nome']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($e['inicio'] ?? '')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($e['fim'] ?? ''))) {
        $out['evento'] = array_filter(['nome' => $s($e['nome']), 'inicio' => $e['inicio'], 'fim' => $e['fim'], 'id' => $s($e['id'] ?? '', 30), 'cep' => only_digits((string)($e['cep'] ?? '')),
            'street' => $s($e['street'] ?? '', 160), 'number' => $s($e['number'] ?? '', 20), 'complement' => $s($e['complement'] ?? '', 80), 'district' => $s($e['district'] ?? '', 80)], fn($v) => $v !== '');
    }
    $c = $x['comext'] ?? [];
    if (!empty($c['modo'])) {
        $out['comext'] = ['modo' => (string)(int)$c['modo'], 'vinculo' => (string)(int)($c['vinculo'] ?? 0), 'moeda' => str_pad(only_digits((string)($c['moeda'] ?? '220')), 3, '0', STR_PAD_LEFT),
            'valor_moeda' => round((float)($c['valor_moeda'] ?? 0), 2), 'mec_prest' => str_pad((string)(int)($c['mec_prest'] ?? 1), 2, '0', STR_PAD_LEFT), 'mec_toma' => str_pad((string)(int)($c['mec_toma'] ?? 1), 2, '0', STR_PAD_LEFT),
            'mov_temp' => (string)(int)($c['mov_temp'] ?? 1), 'di' => $s($c['di'] ?? '', 12), 're' => $s($c['re'] ?? '', 12), 'mdic' => !empty($c['mdic']) ? '1' : '0'];
    }
    $n = $x['info'] ?? [];
    foreach (['complementar' => 2000, 'doc_ref' => 255, 'pedido' => 60, 'doc_tecnico' => 40] as $k => $max) if (!empty($n[$k])) $out['info'][$k] = $s($n[$k], $max);
    if (!empty($x['subst']['chave']) && strlen(only_digits((string)$x['subst']['chave'])) === 50) {
        $out['subst'] = ['chave' => only_digits((string)$x['subst']['chave']), 'motivo' => in_array((string)($x['subst']['motivo'] ?? '99'), ['01', '02', '03', '04', '05', '99'], true) ? (string)$x['subst']['motivo'] : '99',
            'descricao' => $s($x['subst']['descricao'] ?? '', 255), 'invoice_id' => (int)($x['subst']['invoice_id'] ?? 0)];
    }
    if (!empty($x['retro'])) $out['retro'] = true;
    if (!empty($x['vencimento']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$x['vencimento'])) $out['vencimento'] = (string)$x['vencimento'];
    if (!empty($x['v_receb']) && (float)$x['v_receb'] > 0) $out['v_receb'] = round((float)$x['v_receb'], 2);
    if (!empty($x['codigo_interno']) && ($ci = substr(preg_replace('/[^A-Za-z0-9]/', '', strip_accents((string)$x['codigo_interno'])), 0, 20)) !== '') $out['codigo_interno'] = $ci;
    if (!empty($x['desc_raw'])) $out['desc_raw'] = mb_substr((string)$x['desc_raw'], 0, 2000);
    return $out;
}

/**
 * Create or update a draft. $in: taker_id | taker{...}, save_taker, service_id, lc116/ctribnac/ctribmun/
 * sigiss_code/cnbs, description, competence_date, amount, discount_incond, discount_cond, deductions,
 * situation (FH_ISS_SITUATIONS key), iss_rate, federal rates/withheld flags, pis_cofins_cst, extra{...}.
 */
function fh_invoice_save(array $em, array $in, ?array $existing = null, string $source = 'manual'): array
{
    if ($existing && !in_array($existing['status'], ['draft', 'rejected'], true)) throw new AppException('Somente rascunhos e notas rejeitadas podem ser editados.');
    $cfg = fh_tax_cfg($em);
    // taker
    $taker = null;
    if (!empty($in['taker_id'])) {
        $taker = db_one('SELECT * FROM fh_takers WHERE id = ? AND emitter_id = ?', [(int)$in['taker_id'], $em['id']]);
        if (!$taker) throw new AppException('Cliente (tomador) não encontrado.');
    } elseif (!empty($in['taker']) && is_array($in['taker'])) {
        $clean = fh_taker_clean($in['taker']);
        if (!empty($clean['document']) && ($found = db_one('SELECT * FROM fh_takers WHERE emitter_id = ? AND document = ?', [$em['id'], $clean['document']]))) {
            $taker = !empty($in['save_taker']) ? fh_taker_save($em, $in['taker'], $found) : array_merge($found, $clean);
        } elseif (!empty($in['save_taker']) && $clean['kind'] !== 'pfni') {
            $taker = fh_taker_save($em, $in['taker'], null);
        } else {
            $taker = $clean + ['id' => null];
        }
    }
    if (!$taker) throw new AppException('Informe o cliente (tomador) da nota.');
    // service
    $svc = !empty($in['service_id']) ? db_one('SELECT * FROM fh_services WHERE id = ? AND emitter_id = ?', [(int)$in['service_id'], $em['id']]) : null;
    $pick = fn($k) => isset($in[$k]) && $in[$k] !== '' && $in[$k] !== null ? $in[$k] : ($svc[$k] ?? null);
    $lc = (string)($pick('lc116') ?? '');
    $ctribnac = only_digits((string)($pick('ctribnac') ?? '')) ?: ($lc ? fh_lc_to_ctribnac($lc) : '');
    $sigCode = only_digits((string)($pick('sigiss_code') ?? '')) ?: ($lc ? fh_lc_to_sigiss($lc) : '');
    if ($em['provider'] === 'nacional' && strlen($ctribnac) !== 6) throw new AppException('Informe o código de tributação nacional (6 dígitos) ou o item da LC 116 do serviço.');
    if ($em['provider'] === 'sigiss' && $sigCode === '') throw new AppException('Informe o código do serviço no SIGISS (ex.: 107 para o item 1.07) ou o item da LC 116.');
    $situation = (string)($in['situation'] ?? ($svc['sigiss_situacao'] ?? 'tp'));
    if (!isset(FH_ISS_SITUATIONS[$situation])) $situation = 'tp';
    if ($em['provider'] === 'sigiss' && $situation === 'es') throw new AppException('O SIGISS de Marília não aceita exigibilidade suspensa pelo webservice. Emita pelo portal da prefeitura ou use o Emissor Nacional.');
    if ($em['provider'] === 'sigiss' && $situation === 'ti') $situation = 'tt';
    [$tribIssqn, $retention] = FH_ISS_SITUATIONS[$situation];
    $amount = round((float)str_replace(',', '.', (string)($in['amount'] ?? 0)), 2);
    if ($amount <= 0) throw new AppException('Informe o valor do serviço.');
    $issRate = isset($in['iss_rate']) && $in['iss_rate'] !== '' ? (float)str_replace(',', '.', (string)$in['iss_rate']) : (float)($svc['iss_rate'] ?? $cfg['aliquota']);
    $issExempt = in_array($situation, ['is', 'im', 'nt', 'ex'], true) || $em['op_simp_nac'] === '2';
    $taxIn = [
        'amount' => $amount, 'discount_amount' => $in['discount_incond'] ?? 0, 'deductions' => $in['deductions'] ?? 0,
        'iss_rate' => $issExempt ? 0 : max(0, min(5, $issRate)), 'iss_withheld' => $retention !== '1', 'toma_document' => (string)($taker['document'] ?? ''), 'pis_cofins_cst' => $in['pis_cofins_cst'] ?? '',
    ];
    foreach (['pis', 'cofins', 'csll', 'irrf', 'inss'] as $t) {
        $taxIn[$t . '_rate'] = isset($in[$t . '_rate']) && $in[$t . '_rate'] !== '' ? $in[$t . '_rate'] : ($svc[$t . '_rate'] ?? $cfg[$t . '_rate']);
        if ($em['op_simp_nac'] === '2' || $situation === 'ex') $taxIn[$t . '_rate'] = 0; // MEI: all in the DAS · export of services: no PIS/COFINS nor withholdings
        if (array_key_exists($t . '_withheld', $in)) $taxIn[$t . '_withheld'] = $in[$t . '_withheld'];
    }
    $tax = nfse_taxes($taxIn, $cfg);
    // description (+ approximate taxes note, recomputed on every save)
    $raw = trim((string)($in['description'] ?? '')) ?: trim((string)($svc['description'] ?? '')) ?: trim((string)($svc['name'] ?? ''));
    if ($raw === '') throw new AppException('Descreva o serviço prestado (discriminação).');
    $extra = fh_extra_clean(($in['extra'] ?? []) + ['desc_raw' => $raw]);
    if ($situation === 'ex' && empty($extra['comext'])) throw new AppException('Na exportação de serviço, preencha o grupo "Comércio exterior".');
    if ($situation === 'ex' && empty($extra['pais_resultado'])) {
        $country = $extra['loc']['country'] ?? ($taker['country'] ?? '');
        if (!preg_match('/^[A-Z]{2}$/', (string)$country)) throw new AppException('Na exportação, informe o país onde o resultado do serviço se verifica.');
        $extra['pais_resultado'] = $country;
    }
    if ($situation === 'es' && empty($extra['exig'])) throw new AppException('Informe o tipo e o número do processo da exigibilidade suspensa.');
    if ($situation === 'is' && $em['provider'] === 'nacional' && empty($extra['bm'])) throw new AppException('No Emissor Nacional, a isenção é informada pelo número do benefício municipal (14 dígitos).');
    $desc = $raw . ((int)$em['show_taxes'] && empty($in['skip_tax_note']) ? nfse_taxes_note($tax) : '');
    $competence = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['competence_date'] ?? '')) ? $in['competence_date'] : today();
    if ($competence > today()) throw new AppException('A competência não pode ser futura.');
    $row = [
        'provider' => $em['provider'], 'environment' => $em['provider'] === 'sigiss' ? 'production' : $em['environment'],
        'taker_id' => $taker['id'] ?? null, 'toma_kind' => $taker['kind'], 'toma_document' => $taker['document'] ?: ($taker['nif'] ?? null), 'toma_name' => $taker['name'], 'toma_email' => $taker['email'] ?? null,
        'toma_json' => json_encode(array_intersect_key($taker, array_flip(FH_TAKER_FIELDS)), JSON_UNESCAPED_UNICODE),
        'service_id' => $svc['id'] ?? null, 'service_name' => $svc['name'] ?? null, 'lc116' => $lc ?: null, 'ctribnac' => $ctribnac ?: null,
        'ctribmun' => only_digits((string)($pick('ctribmun') ?? '')) ?: null, 'sigiss_code' => $sigCode ?: null, 'cnbs' => only_digits((string)($pick('cnbs') ?? '')) ?: null,
        'description' => mb_substr($desc, 0, 2000), 'competence_date' => $competence, 'amount' => $amount,
        'discount_incond' => $tax['discount_amount'], 'discount_cond' => round(max(0, (float)($in['discount_cond'] ?? 0)), 2), 'deductions' => $tax['deductions'], 'base' => $tax['base'],
        'iss_rate' => $tax['iss_rate'], 'iss_amount' => $tax['iss_amount'], 'iss_retention' => $retention, 'trib_issqn' => $tribIssqn, 'sigiss_situacao' => $situation, 'pis_cofins_cst' => $tax['pis_cofins_cst'],
        'total_taxes_pct' => $tax['total_taxes_pct'], 'total_taxes_amount' => $tax['total_taxes_amount'], 'withheld_total' => $tax['withheld_total'], 'net_amount' => round($tax['net_amount'] - round(max(0, (float)($in['discount_cond'] ?? 0)), 2), 2),
        'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE), 'error_message' => null, 'updated_at' => now(),
    ];
    foreach (['pis', 'cofins', 'csll', 'irrf', 'inss'] as $t) { $row[$t . '_rate'] = $tax[$t . '_rate']; $row[$t . '_amount'] = $tax[$t . '_amount']; $row[$t . '_withheld'] = $tax[$t . '_withheld']; }
    if ($existing) {
        db_update('fh_invoices', (int)$existing['id'], $row + ['status' => 'draft']);
        return db_find('fh_invoices', (int)$existing['id']);
    }
    $id = db_transaction(function () use ($em, $row, $source, $in) {
        $env = $row['environment'];
        $fresh = db_find('fh_emitters', (int)$em['id']);
        $number = max((int)$fresh['next_number'], 1 + (int)db_value('SELECT COALESCE(MAX(dps_number), 0) FROM fh_invoices WHERE emitter_id = ? AND environment = ? AND dps_serie = ?', [$em['id'], $env, $fresh['dps_serie']]));
        db_update('fh_emitters', (int)$em['id'], ['next_number' => $number + 1]);
        $loc = str_pad((string)($fresh['city_ibge'] ?: FH_MARILIA_IBGE), 7, '0', STR_PAD_LEFT);
        $dpsId = 'DPS' . $loc . (strlen($fresh['document']) === 11 ? '1' : '2') . str_pad($fresh['document'], 14, '0', STR_PAD_LEFT) . str_pad($fresh['dps_serie'], 5, '0', STR_PAD_LEFT) . str_pad((string)$number, 15, '0', STR_PAD_LEFT);
        return db_insert('fh_invoices', $row + ['emitter_id' => $em['id'], 'customer_id' => $em['customer_id'], 'status' => 'draft', 'source' => $source, 'recurring_id' => $in['recurring_id'] ?? null,
            'dps_serie' => $fresh['dps_serie'], 'dps_number' => $number, 'dps_id' => $dpsId, 'created_at' => now()]);
    });
    return db_find('fh_invoices', $id);
}

/* ------------------------------------------------------------ DPS (nacional) */

/** @param bool|null $aliq force pAliq on/off (retry after an E06xx rejection); null = official rules. */
function fh_build_dps(array $inv, array $em, ?bool $aliq = null): string
{
    $x = json_decode((string)$inv['extra'], true) ?: [];
    $t = json_decode((string)$inv['toma_json'], true) ?: [];
    $dom = new DOMDocument('1.0', 'UTF-8');
    $el = function (DOMElement $parent, string $name, ?string $value = null) use ($dom): DOMElement {
        $e = $dom->createElement($name);
        if ($value !== null && $value !== '') $e->appendChild($dom->createTextNode($value));
        $parent->appendChild($e);
        return $e;
    };
    $num = fn($v) => number_format((float)$v, 2, '.', '');
    $txt = fn($v, $max) => nfse_text((string)$v, $max);
    $dps = $dom->createElementNS(NFSE_NS, 'DPS');
    $dps->setAttribute('versao', NFSE_LAYOUT);
    $dom->appendChild($dps);
    $inf = $dom->createElement('infDPS');
    $inf->setAttribute('Id', $inv['dps_id']);
    $dps->appendChild($inf);
    $tz = new DateTimeZone('America/Sao_Paulo');
    $now = (new DateTimeImmutable('now', $tz))->modify('-60 seconds');
    $city = str_pad((string)($em['city_ibge'] ?: FH_MARILIA_IBGE), 7, '0', STR_PAD_LEFT);
    $el($inf, 'tpAmb', $inv['environment'] === 'production' ? '1' : '2');
    $el($inf, 'dhEmi', $now->format('Y-m-d\TH:i:sP'));
    $el($inf, 'verAplic', 'IntegraFiscalHub-1.0');
    $el($inf, 'serie', (string)$inv['dps_serie']);
    $el($inf, 'nDPS', (string)$inv['dps_number']);
    $el($inf, 'dCompet', min((string)$inv['competence_date'], $now->format('Y-m-d')));
    $el($inf, 'tpEmit', '1');
    $el($inf, 'cLocEmi', $city);
    if (!empty($x['subst'])) {
        $s = $el($inf, 'subst');
        $el($s, 'chSubstda', $x['subst']['chave']);
        $el($s, 'cMotivo', $x['subst']['motivo']);
        if (($x['subst']['descricao'] ?? '') !== '') $el($s, 'xMotivo', $txt($x['subst']['descricao'], 255));
    }
    // prestador
    $prest = $el($inf, 'prest');
    $el($prest, strlen($em['document']) === 11 ? 'CPF' : 'CNPJ', $em['document']);
    if (only_digits((string)$em['im']) !== '') $el($prest, 'IM', only_digits((string)$em['im']));
    $fone = only_digits((string)$em['phone']);
    if (strlen($fone) >= 6 && strlen($fone) <= 20) $el($prest, 'fone', $fone);
    if (filter_var($em['email'], FILTER_VALIDATE_EMAIL)) $el($prest, 'email', mb_substr((string)$em['email'], 0, 80));
    [$regAp, $regEsp] = nfse_regime((string)$em['op_simp_nac'], $em['reg_ap_trib_sn'] ?? '', $em['reg_esp_trib'] ?? '0', (string)$inv['trib_issqn']);
    $reg = $el($prest, 'regTrib');
    $el($reg, 'opSimpNac', (string)$em['op_simp_nac']);
    if ($regAp !== '') $el($reg, 'regApTribSN', $regAp);
    $el($reg, 'regEspTrib', $regEsp);
    // pessoa (tomador / intermediário)
    $person = function (string $tag, array $p) use ($inf, $el, $txt) {
        $kind = $p['kind'] ?? 'pj';
        if ($kind === 'pfni') return;
        $node = $el($inf, $tag);
        $doc = only_digits((string)($p['document'] ?? ''));
        if ($kind === 'ext' || ($doc === '' && !empty($p['nif']))) {
            if (!empty($p['nif'])) $el($node, 'NIF', $txt($p['nif'], 40)); else $el($node, 'cNaoNIF', '2');
        } else {
            $el($node, strlen($doc) === 11 ? 'CPF' : 'CNPJ', $doc);
        }
        if (only_digits((string)($p['im'] ?? '')) !== '' && $kind !== 'ext') $el($node, 'IM', only_digits((string)$p['im']));
        $el($node, 'xNome', $txt($p['name'] ?? '', 300));
        $hasNac = strlen(only_digits((string)($p['city_ibge'] ?? ''))) === 7 && strlen(only_digits((string)($p['cep'] ?? ''))) === 8 && trim((string)($p['street'] ?? '')) !== '';
        $hasExt = $kind === 'ext' && !empty($p['country']) && trim((string)($p['street'] ?? '')) !== '';
        if ($hasNac || $hasExt) {
            $end = $el($node, 'end');
            if ($hasExt) {
                $ext = $el($end, 'endExt');
                $el($ext, 'cPais', strtoupper((string)$p['country']));
                $el($ext, 'cEndPost', $txt($p['foreign_postal'] ?: '0', 11));
                $el($ext, 'xCidade', $txt($p['foreign_city'] ?: '-', 60));
                $el($ext, 'xEstProvReg', $txt($p['foreign_region'] ?: '-', 60));
            } else {
                $nac = $el($end, 'endNac');
                $el($nac, 'cMun', only_digits((string)$p['city_ibge']));
                $el($nac, 'CEP', only_digits((string)$p['cep']));
            }
            $el($end, 'xLgr', $txt($p['street'], 255));
            $el($end, 'nro', $txt(($p['number'] ?? '') ?: 'S/N', 60));
            if (trim((string)($p['complement'] ?? '')) !== '') $el($end, 'xCpl', $txt($p['complement'], 156));
            $el($end, 'xBairro', $txt(($p['district'] ?? '') ?: '-', 60));
        }
        $f = only_digits((string)($p['phone'] ?? ''));
        if (strlen($f) >= 6 && strlen($f) <= 20) $el($node, 'fone', $f);
        if (filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) $el($node, 'email', mb_substr((string)$p['email'], 0, 80));
    };
    $person('toma', $t + ['kind' => $inv['toma_kind'] ?: 'pj']);
    if (!empty($x['interm'])) $person('interm', $x['interm'] + ['kind' => strlen((string)$x['interm']['document']) ? 'pj' : 'ext']);
    // serviço
    $serv = $el($inf, 'serv');
    $loc = $el($serv, 'locPrest');
    if (($x['loc']['type'] ?? '') === 'exterior') $el($loc, 'cPaisPrestacao', $x['loc']['country']);
    else $el($loc, 'cLocPrestacao', ($x['loc']['type'] ?? '') === 'outro' ? $x['loc']['city_ibge'] : $city);
    $cs = $el($serv, 'cServ');
    $el($cs, 'cTribNac', (string)$inv['ctribnac']);
    if (!empty($inv['ctribmun'])) $el($cs, 'cTribMun', (string)$inv['ctribmun']);
    $el($cs, 'xDescServ', $txt($inv['description'], 2000));
    if (!empty($inv['cnbs'])) $el($cs, 'cNBS', (string)$inv['cnbs']);
    if (!empty($x['codigo_interno'])) $el($cs, 'cIntContrib', $txt($x['codigo_interno'], 20));
    if (!empty($x['comext'])) {
        $c = $x['comext'];
        $ce = $el($serv, 'comExt');
        $el($ce, 'mdPrestacao', $c['modo']);
        $el($ce, 'vincPrest', $c['vinculo']);
        $el($ce, 'tpMoeda', $c['moeda']);
        $el($ce, 'vServMoeda', $num($c['valor_moeda'] ?: $inv['amount']));
        $el($ce, 'mecAFComexP', $c['mec_prest']);
        $el($ce, 'mecAFComexT', $c['mec_toma']);
        $el($ce, 'movTempBens', $c['mov_temp']);
        if ($c['di'] !== '') $el($ce, 'nDI', $c['di']);
        if ($c['re'] !== '') $el($ce, 'nRE', $c['re']);
        $el($ce, 'mdic', $c['mdic']);
    }
    $addr = function (DOMElement $parent, array $a) use ($el, $txt) {
        $end = $el($parent, 'end');
        $el($end, 'CEP', $a['cep']);
        $el($end, 'xLgr', $txt($a['street'] ?? '-', 255));
        $el($end, 'nro', $txt(($a['number'] ?? '') ?: 'S/N', 60));
        if (!empty($a['complement'])) $el($end, 'xCpl', $txt($a['complement'], 156));
        $el($end, 'xBairro', $txt(($a['district'] ?? '') ?: '-', 60));
    };
    if (!empty($x['obra'])) {
        $o = $x['obra'];
        $ob = $el($serv, 'obra');
        if (!empty($o['inscricao'])) $el($ob, 'inscImobFisc', $o['inscricao']);
        if (!empty($o['codigo'])) $el($ob, 'cObra', $o['codigo']);
        elseif (!empty($o['cib'])) $el($ob, 'cCIB', $o['cib']);
        elseif (!empty($o['cep'])) $addr($ob, $o);
    }
    if (!empty($x['evento'])) {
        $ev = $x['evento'];
        $ae = $el($serv, 'atvEvento');
        $el($ae, 'xNome', $txt($ev['nome'], 255));
        $el($ae, 'dtIni', $ev['inicio']);
        $el($ae, 'dtFim', $ev['fim']);
        if (!empty($ev['id'])) $el($ae, 'idAtvEvt', $ev['id']);
        else {
            $end = $el($ae, 'end');
            $el($end, 'CEP', $ev['cep'] ?? '');
            $el($end, 'xLgr', $txt($ev['street'] ?? '-', 255));
            $el($end, 'nro', $txt(($ev['number'] ?? '') ?: 'S/N', 60));
            if (!empty($ev['complement'])) $el($end, 'xCpl', $txt($ev['complement'], 156));
            $el($end, 'xBairro', $txt(($ev['district'] ?? '') ?: '-', 60));
        }
    }
    if (!empty($x['info'])) {
        $ic = $el($serv, 'infoCompl');
        if (!empty($x['info']['doc_tecnico'])) $el($ic, 'idDocTec', $x['info']['doc_tecnico']);
        if (!empty($x['info']['doc_ref'])) $el($ic, 'docRef', $x['info']['doc_ref']);
        if (!empty($x['info']['pedido'])) $el($ic, 'xPed', $txt($x['info']['pedido'], 60));
        if (!empty($x['info']['complementar'])) $el($ic, 'xInfComp', $txt($x['info']['complementar'], 2000));
    }
    // valores
    $val = $el($inf, 'valores');
    $vsp = $el($val, 'vServPrest'); // vReceb is only for DPS issued by the intermediary (E0424)
    $el($vsp, 'vServ', $num($inv['amount']));
    if ((float)$inv['discount_incond'] > 0 || (float)$inv['discount_cond'] > 0) {
        $d = $el($val, 'vDescCondIncond');
        if ((float)$inv['discount_incond'] > 0) $el($d, 'vDescIncond', $num($inv['discount_incond']));
        if ((float)$inv['discount_cond'] > 0) $el($d, 'vDescCond', $num($inv['discount_cond']));
    }
    if (!empty($x['ded_percentual'])) {
        $el($el($val, 'vDedRed'), 'pDR', $num($x['ded_percentual']));
    } elseif ((float)$inv['deductions'] > 0) {
        $el($el($val, 'vDedRed'), 'vDR', $num($inv['deductions']));
    }
    $trib = $el($val, 'trib');
    $tm = $el($trib, 'tribMun');
    $el($tm, 'tribISSQN', (string)$inv['trib_issqn']);
    if ($inv['trib_issqn'] === '3') $el($tm, 'cPaisResult', (string)($x['pais_resultado'] ?? ''));
    if ($inv['trib_issqn'] === '2') $el($tm, 'tpImunidade', (string)($x['imunidade'] ?? ''));
    if (!empty($x['exig'])) { $es = $el($tm, 'exigSusp'); $el($es, 'tpSusp', $x['exig']['tipo']); $el($es, 'nProcesso', $x['exig']['processo']); }
    if (!empty($x['bm'])) {
        $bm = $el($tm, 'BM');
        $el($bm, 'nBM', $x['bm']['numero']);
        if (!empty($x['bm']['valor'])) $el($bm, 'vRedBCBM', $num($x['bm']['valor']));
        elseif (!empty($x['bm']['percentual'])) $el($bm, 'pRedBCBM', $num($x['bm']['percentual']));
    }
    $el($tm, 'tpRetISSQN', (string)$inv['iss_retention']);
    $sendAliq = $aliq ?? nfse_send_aliq((string)$em['op_simp_nac'], $regAp, $regEsp, (string)$inv['trib_issqn'], $inv['iss_retention'] !== '1');
    if ($sendAliq && (float)$inv['iss_rate'] > 0 && $inv['trib_issqn'] === '1') $el($tm, 'pAliq', $num($inv['iss_rate']));
    $pis = (float)$inv['pis_amount'];
    $cof = (float)$inv['cofins_amount'];
    if ($pis > 0 || $cof > 0 || $inv['inss_withheld'] || $inv['irrf_withheld'] || $inv['csll_withheld']) {
        $fed = $el($trib, 'tribFed');
        if ($pis > 0 || $cof > 0) {
            $pc = $el($fed, 'piscofins');
            $el($pc, 'CST', (string)($inv['pis_cofins_cst'] ?: '01'));
            $el($pc, 'vBCPisCofins', $num((float)$inv['amount'] - (float)$inv['discount_incond']));
            if ($pis > 0) $el($pc, 'pAliqPis', $num($inv['pis_rate']));
            if ($cof > 0) $el($pc, 'pAliqCofins', $num($inv['cofins_rate']));
            if ($pis > 0) $el($pc, 'vPis', $num($pis));
            if ($cof > 0) $el($pc, 'vCofins', $num($cof));
            $map = ['111' => '3', '110' => '4', '100' => '5', '010' => '6', '011' => '7', '001' => '8', '101' => '9', '000' => '0'];
            $el($pc, 'tpRetPisCofins', $map[(int)!empty($inv['pis_withheld']) . (int)!empty($inv['cofins_withheld']) . (int)!empty($inv['csll_withheld'])]);
        }
        if ($inv['inss_withheld']) $el($fed, 'vRetCP', $num($inv['inss_amount']));
        if ($inv['irrf_withheld']) $el($fed, 'vRetIRRF', $num($inv['irrf_amount']));
        if ($inv['csll_withheld']) $el($fed, 'vRetCSLL', $num($inv['csll_amount']));
    }
    $fedPct = (float)$inv['pis_rate'] + (float)$inv['cofins_rate'] + (float)$inv['csll_rate'] + (float)$inv['irrf_rate'];
    nfse_append_tot_trib($dom, $trib, nfse_tot_trib((string)$em['op_simp_nac'], (float)$em['simples_rate'], (float)$inv['total_taxes_pct'], $fedPct, (float)$inv['iss_rate']));
    return $dom->saveXML($dom->documentElement);
}

/**
 * Pre-flight check of a DPS against the Sefin business rules (Anexo I) that the XSD cannot express.
 * Returns human messages (empty = OK). Each rule cites the rejection it prevents.
 */
function fh_dps_problems(array $inv, array $em): array
{
    $p = [];
    $x = json_decode((string)$inv['extra'], true) ?: [];
    $t = json_decode((string)$inv['toma_json'], true) ?: [];
    $op = (string)$em['op_simp_nac'];
    $trib = (string)$inv['trib_issqn'];
    $ret = (string)$inv['iss_retention'];
    [, $regEsp] = nfse_regime($op, $em['reg_ap_trib_sn'] ?? '', $em['reg_esp_trib'] ?? '0', $trib);
    $doc = only_digits((string)($inv['toma_document'] ?? ''));
    $kind = (string)($inv['toma_kind'] ?: 'pj');
    $amount = (float)$inv['amount'];
    $hasAddr = strlen(only_digits((string)($t['city_ibge'] ?? ''))) === 7 && strlen(only_digits((string)($t['cep'] ?? ''))) === 8 && trim((string)($t['street'] ?? '')) !== '';
    // tomador
    if ($doc !== '' && $doc === only_digits((string)$em['document'])) $p[] = 'O tomador não pode ser a própria empresa emissora (E0202).';
    if ($kind !== 'ext' && strlen($doc) === 14 && !$hasAddr) $p[] = 'Tomador com CNPJ precisa de endereço completo: CEP, rua e município (E0235). Abra o cadastro do cliente e informe o CEP.';
    if ($ret === '2') {
        if ($kind === 'pfni' || !in_array(strlen($doc), [11, 14], true)) $p[] = 'Para o ISS retido pelo tomador, informe o CPF/CNPJ do tomador (E0204).';
        elseif (!$hasAddr) $p[] = 'Para o ISS retido pelo tomador, o endereço do tomador é obrigatório (E0237).';
    }
    if ($ret === '3') $p[] = 'A retenção do ISS pelo intermediário exige o endereço do intermediário, que este emissor ainda não envia (E0293). Use "ISS retido pelo tomador" ou emita pelo portal nacional.';
    // retenção x regime
    if ($ret !== '1') {
        if ($op === '2') $p[] = 'MEI não pode ter ISS retido (E0583). Escolha "ISS devido pelo prestador".';
        if ($regEsp !== '0') $p[] = 'Com regime especial de tributação não pode haver retenção do ISS (E0588).';
        if ($trib !== '1') $p[] = 'Não há retenção de ISS em imunidade, exportação ou não incidência (E0580).';
        if ($op === '3' && ($em['reg_ap_trib_sn'] ?: '1') === '1' && (float)$inv['iss_rate'] < 1.8) $p[] = 'Com ISS retido, informe a alíquota do ISS do seu anexo do Simples (mínimo 1,8%) (E0621).';
    }
    // valores
    if ((float)$inv['discount_incond'] > 0 && (float)$inv['discount_incond'] >= $amount) $p[] = 'O desconto incondicionado deve ser menor que o valor do serviço (E0431).';
    if ((float)$inv['discount_cond'] > 0 && (float)$inv['discount_cond'] >= $amount) $p[] = 'O desconto condicionado deve ser menor que o valor do serviço (E0432).';
    if ((float)$inv['discount_incond'] + (float)$inv['deductions'] > $amount) $p[] = 'Descontos + deduções não podem passar do valor do serviço (E0427).';
    $hasDed = (float)$inv['deductions'] > 0 || !empty($x['ded_percentual']);
    if ($hasDed && $trib !== '1') $p[] = 'Deduções/reduções não são permitidas em imunidade, exportação ou não incidência (E0435).';
    if ($hasDed && $op === '2') $p[] = 'MEI não pode informar deduções/reduções (E0436).';
    if ($hasDed && $regEsp !== '0') $p[] = 'Com regime especial de tributação não se informam deduções/reduções (E0438).';
    if (!empty($x['bm'])) {
        if ($trib !== '1') $p[] = 'Benefício municipal só vale para operação tributável (E0533).';
        if ($op === '2') $p[] = 'MEI não pode informar benefício municipal (E0534).';
        if ($regEsp !== '0') $p[] = 'Com regime especial não se informa benefício municipal (E0535).';
    }
    // tributos federais
    $fed = (float)$inv['pis_amount'] > 0 || (float)$inv['cofins_amount'] > 0 || $inv['inss_withheld'] || $inv['irrf_withheld'] || $inv['csll_withheld'];
    if ($fed && strlen(only_digits((string)$em['document'])) === 11) $p[] = 'Emitente pessoa física (CPF) não informa tributos federais (E0675): zere PIS, COFINS, CSLL, IRRF e INSS.';
    if ($fed && $op === '2') $p[] = 'MEI não informa tributos federais (E0676): zere PIS, COFINS, CSLL, IRRF e INSS.';
    // serviço
    $ctn = (string)$inv['ctribnac'];
    if (($inv['ctribmun'] ?? '') === '000') $p[] = 'O código municipal (cTribMun) não pode ser 000 (E0315): deixe em branco.';
    if ($msg = nfse_nbs_problem((string)($inv['cnbs'] ?? ''), (string)($inv['lc116'] ?? ''))) $p[] = $msg;
    if ($trib === '3' && empty($inv['cnbs'])) $p[] = 'Na exportação de serviço o código NBS é obrigatório (E0318).';
    if (in_array($ctn, NFSE_OBRA_CODES, true) && empty($x['obra'])) $p[] = 'Este serviço de construção civil exige o grupo "Obra" (código da obra, CIB ou endereço) (E0370).';
    if (!in_array($ctn, NFSE_OBRA_CODES, true) && $ctn !== '990101' && !empty($x['obra'])) $p[] = 'O grupo "Obra" só é permitido para os serviços de construção civil da lista (E0372): remova-o.';
    if ($trib === '2' && !in_array((string)($x['imunidade'] ?? ''), ['1', '2', '3', '4', '5'], true)) $p[] = 'Escolha o tipo de imunidade (E0593: "não informado" não é aceito).';
    if ($ctn === '990101' && $trib !== '4') $p[] = 'O serviço 99.01.01 exige a situação "Não incidência" (E0532).';
    return $p;
}

/* ---------------------------------------------------------------- SIGISS */

/** GerarNota fields in the exact WSDL order (tcDescricaoRps). */
function fh_sigiss_fields(array $inv, array $em): array
{
    $cfg = fh_sigiss_cfg($em);
    $t = json_decode((string)$inv['toma_json'], true) ?: [];
    $x = json_decode((string)$inv['extra'], true) ?: [];
    $kind = $inv['toma_kind'] ?: 'pj';
    $tipo = ['pfni' => 1, 'pf' => 2, 'ext' => 5][$kind] ?? (only_digits((string)($t['city_ibge'] ?? '')) === FH_MARILIA_IBGE ? 3 : 4);
    if (in_array($tipo, [2, 3, 4], true)) {
        $miss = [];
        foreach (['street' => 'endereço', 'number' => 'número', 'district' => 'bairro', 'cep' => 'CEP', 'city_ibge' => 'cidade (código IBGE — preenchido pelo CEP)'] as $k => $l) if (trim((string)($t[$k] ?? '')) === '') $miss[] = $l;
        if ($miss) throw new NfseException('Complete o endereço do cliente: ' . implode(', ', $miss) . '.', ['O SIGISS exige o endereço completo do tomador. Informe o CEP no cadastro do cliente.']);
    }
    $issValue = round((float)$inv['base'] * (float)$inv['iss_rate'] / 100, 2);
    $date = strtotime((string)$inv['created_at']);
    $sit = FH_ISS_SITUATIONS[$inv['sigiss_situacao']][2] ?? 'tp';
    $retro = !empty($x['retro']) ? strtotime((string)$inv['competence_date']) : null;
    $c = $x['comext'] ?? null;
    $m = fn($v) => sigiss_money($v);
    $f = [
        'ccm' => ['xsd:string', $cfg['ccm']], 'cnpj' => ['xsd:string', $cfg['cnpj']], 'senha' => ['xsd:string', $cfg['password']],
        'crc' => ['xsd:int', only_digits((string)$em['sigiss_crc'])], 'crc_estado' => ['xsd:string', (string)$em['sigiss_crc_uf']],
        'aliquota_simples' => ['xsd:string', $em['op_simp_nac'] !== '1' && (float)$inv['iss_rate'] > 0 ? $m($inv['iss_rate']) : ''],
        'id_sis_legado' => ['xsd:string', 'FH' . $inv['id']],
        'servico' => ['xsd:int', (string)$inv['sigiss_code']], 'situacao' => ['xsd:string', $sit],
        'valor' => ['xsd:string', $m($inv['amount'])], 'base' => ['xsd:string', $m($inv['base'])],
        'descricaoNF' => ['xsd:string', nfse_text((string)$inv['description'], 1500)],
        'tomador_tipo' => ['xsd:int', (string)$tipo], 'tomador_cnpj' => ['xsd:string', $tipo === 5 || $tipo === 1 ? '' : only_digits((string)$inv['toma_document'])],
        'tomador_email' => ['xsd:string', (string)($t['email'] ?? '')], 'tomador_ie' => ['xsd:string', only_digits((string)($t['ie'] ?? ''))], 'tomador_im' => ['xsd:string', only_digits((string)($t['im'] ?? ''))],
        'tomador_razao' => ['xsd:string', nfse_text((string)$inv['toma_name'], 150)], 'tomador_fantasia' => ['xsd:string', nfse_text((string)($t['trade_name'] ?? ''), 150)],
        'tomador_endereco' => ['xsd:string', nfse_text((string)($t['street'] ?? ''), 120)], 'tomador_numero' => ['xsd:string', nfse_text((string)($t['number'] ?? ''), 10)],
        'tomador_complemento' => ['xsd:string', nfse_text((string)($t['complement'] ?? ''), 60)], 'tomador_bairro' => ['xsd:string', nfse_text((string)($t['district'] ?? ''), 60)],
        'tomador_cidade' => ['xsd:string', nfse_text((string)($t['city'] ?? ''), 60)], 'tomador_uf' => ['xsd:string', (string)($t['uf'] ?? '')],
        'tomador_CEP' => ['xsd:string', only_digits((string)($t['cep'] ?? ''))], 'tomador_cod_cidade' => ['xsd:string', $tipo === 5 ? '' : only_digits((string)($t['city_ibge'] ?? ''))],
        'tomador_pais' => ['xsd:string', $tipo === 5 ? (string)($t['country'] ?? '') : ''], 'tomador_cidade_exterior' => ['xsd:string', $tipo === 5 ? nfse_text((string)($t['foreign_city'] ?? ''), 60) : ''],
        'tomador_fone' => ['xsd:string', only_digits((string)($t['phone'] ?? ''))],
        'rps_num' => ['xsd:int', (string)$inv['dps_number']], 'rps_serie' => ['xsd:string', (string)$inv['dps_serie']],
        'rps_dia' => ['xsd:int', date('j', $date)], 'rps_mes' => ['xsd:int', date('n', $date)], 'rps_ano' => ['xsd:int', date('Y', $date)],
        'outro_municipio' => ['xsd:int', ($x['loc']['type'] ?? '') === 'outro' ? '1' : ''], 'cod_outro_municipio' => ['xsd:int', ($x['loc']['type'] ?? '') === 'outro' ? $x['loc']['city_ibge'] : ''],
        'retencao_iss' => ['xsd:string', $inv['iss_retention'] !== '1' ? $m($issValue) : ''],
        'pis' => ['xsd:string', (float)$inv['pis_amount'] > 0 ? $m($inv['pis_amount']) : ''], 'cofins' => ['xsd:string', (float)$inv['cofins_amount'] > 0 ? $m($inv['cofins_amount']) : ''],
        'inss' => ['xsd:string', $inv['inss_withheld'] ? $m($inv['inss_amount']) : ''], 'irrf' => ['xsd:string', $inv['irrf_withheld'] ? $m($inv['irrf_amount']) : ''],
        'csll' => ['xsd:string', (float)$inv['csll_amount'] > 0 ? $m($inv['csll_amount']) : ''],
        'retencao_pis' => ['xsd:int', $inv['pis_withheld'] ? '1' : ''], 'retencao_cofins' => ['xsd:int', $inv['cofins_withheld'] ? '1' : ''], 'retencao_csll' => ['xsd:int', $inv['csll_withheld'] ? '1' : ''],
        'valor_total_tributos' => ['xsd:string', (float)$inv['total_taxes_amount'] > 0 ? $m($inv['total_taxes_amount']) : ''],
        'dia_retro' => ['xsd:int', $retro ? date('j', $retro) : ''], 'mes_retro' => ['xsd:int', $retro ? date('n', $retro) : ''], 'ano_retro' => ['xsd:int', $retro ? date('Y', $retro) : ''],
        // SIGISS: "xnbs" is the NBS *description* and "dps_serv_cnbs" the 9-digit code. Sending the code in
        // xnbs left dps_serv_cnbs empty, so the prefeitura rejected the NBS. Description is optional.
        'xnbs' => ['xsd:string', ''],
        'dps_serv_cnbs' => ['xsd:string', only_digits((string)($inv['cnbs'] ?? ''))],
        'dps_serv_mdprestacao' => ['xsd:int', $c['modo'] ?? ''], 'dps_serv_mecafcomexp' => ['xsd:int', isset($c['mec_prest']) ? (string)(int)$c['mec_prest'] : ''],
        'dps_serv_mecafcomext' => ['xsd:int', isset($c['mec_toma']) ? (string)(int)$c['mec_toma'] : ''], 'dps_serv_vincprest' => ['xsd:int', $c['vinculo'] ?? ''],
        'dps_serv_tpmoeda' => ['xsd:string', $c['moeda'] ?? ''], 'dps_serv_vservmoeda' => ['xsd:string', $c ? $m($c['valor_moeda'] ?: $inv['amount']) : ''],
        'dps_serv_movtempbens' => ['xsd:int', $c['mov_temp'] ?? ''], 'dps_serv_mdic' => ['xsd:int', $c['mdic'] ?? ''],
    ];
    return $f;
}

/* -------------------------------------------------------------- transmit */

/** Financial side effects of an invoice (receivables); never breaks the emission itself. */
function fh_finance_hook(string $fn, ?array $inv): void
{
    if (!$inv || !function_exists($fn)) return;
    try { $fn($inv); } catch (Throwable $e) { log_line('fiscalhub', 'finance hook failed', ['fn' => $fn, 'invoice' => $inv['id'] ?? null, 'error' => $e->getMessage()]); }
}

/**
 * Drafts keep the channel (SIGISS/Emissor Nacional) chosen when they were saved. When the company
 * switched channels since then, adapt the draft before transmitting instead of sending it to the
 * old channel.
 */
function fh_invoice_sync_channel(array $inv, array $em): array
{
    $env = $em['provider'] === 'sigiss' ? 'production' : ($em['environment'] === 'production' ? 'production' : 'homologation');
    if ($inv['provider'] === $em['provider'] && $inv['environment'] === $env) return $inv;
    $upd = ['provider' => $em['provider'], 'environment' => $env, 'updated_at' => now()];
    $sit = (string)($inv['sigiss_situacao'] ?: 'tp');
    if ($em['provider'] === 'sigiss') {
        $code = only_digits((string)$inv['sigiss_code']) ?: ($inv['lc116'] ? fh_lc_to_sigiss((string)$inv['lc116']) : '');
        if ($code === '') throw new AppException('Esta nota foi criada para o Emissor Nacional. Abra a nota e informe o código do serviço no SIGISS (ou o item da LC 116) antes de emitir.');
        if ($sit === 'es') throw new AppException('O SIGISS de Marília não aceita exigibilidade suspensa pelo webservice. Edite a nota ou emita pelo portal da prefeitura.');
        if ($sit === 'ti') { $upd['sigiss_situacao'] = 'tt'; [$upd['trib_issqn'], $upd['iss_retention']] = FH_ISS_SITUATIONS['tt']; }
        $upd['sigiss_code'] = $code;
    } else {
        $ctrib = only_digits((string)$inv['ctribnac']) ?: ($inv['lc116'] ? fh_lc_to_ctribnac((string)$inv['lc116']) : '');
        if (strlen($ctrib) !== 6) throw new AppException('Esta nota foi criada para o SIGISS. Abra a nota e informe o código de tributação nacional (ou o item da LC 116) antes de emitir.');
        $x = json_decode((string)$inv['extra'], true) ?: [];
        if ($sit === 'is' && empty($x['bm'])) throw new AppException('No Emissor Nacional, a isenção exige o número do benefício municipal. Edite a nota antes de emitir.');
        $upd['ctribnac'] = $ctrib;
    }
    db_update('fh_invoices', (int)$inv['id'], $upd);
    return db_find('fh_invoices', (int)$inv['id']);
}

function fh_transmit(int $id, ?int $customerId = null): array
{
    $inv = db_find('fh_invoices', $id);
    if (!$inv || ($customerId && (int)$inv['customer_id'] !== $customerId)) throw new AppException('Nota não encontrada.');
    if (in_array($inv['status'], ['authorized', 'canceled'], true)) throw new AppException('Esta nota já foi emitida.');
    if ($inv['status'] === 'voided') throw new AppException('Este número foi inutilizado e não pode mais ser emitido. Duplique a nota para emitir com um novo número.');
    if ($inv['status'] === 'processing' && strtotime((string)$inv['updated_at']) > time() - 90) throw new AppException('Esta nota já está sendo transmitida. Aguarde alguns segundos.');
    fh_require_emit((int)$inv['customer_id']);
    $em = db_find('fh_emitters', (int)$inv['emitter_id']);
    if ($problems = fh_emitter_problems($em)) throw new AppException('Complete o cadastro da empresa antes de emitir: ' . implode(' ', $problems));
    $inv = fh_invoice_sync_channel($inv, $em);
    $fail = function (string $msg, array $details = []) use ($id) {
        db_update('fh_invoices', $id, ['status' => 'rejected', 'error_message' => implode("\n", array_merge([$msg], $details)), 'updated_at' => now()]);
        throw new NfseException($msg, $details);
    };
    if ($em['provider'] === 'sigiss') {
        try {
            $fields = fh_sigiss_fields($inv, $em);
        } catch (NfseException $e) {
            $fail($e->getMessage(), $e->details);
        }
        db_update('fh_invoices', $id, ['status' => 'processing', 'error_message' => null, 'updated_at' => now()]);
        try {
            $xp = sigiss_call('GerarNota', ['DescricaoRps' => ['type' => 'tns:tcDescricaoRps', 'fields' => $fields]], fh_sigiss_cfg($em)['url']);
        } catch (NfseException $e) {
            $fail($e->getMessage(), $e->details);
        }
        $number = sigiss_value($xp, 'Nota');
        if (!(sigiss_value($xp, 'Resultado') === '1' && (int)$number > 0)) {
            $errors = sigiss_errors($xp);
            log_line('fiscalhub', 'sigiss GerarNota refused', ['id' => $id, 'resultado' => sigiss_value($xp, 'Resultado'), 'nota' => $number, 'errors' => $errors, 'response' => sigiss_last_response()]);
            if (!fh_sigiss_note_is_rps($inv, $em, (int)$number)) {
                if (!$errors) $errors = fh_sigiss_diagnose($inv, $em, $xp);
                $fail('A Prefeitura de Marília (SIGISS) recusou a nota.', array_merge($errors, sigiss_hints($errors)));
            }
        }
        $upd = ['status' => 'authorized', 'nfse_number' => $number, 'print_url' => sigiss_value($xp, 'LinkImpressao') ?: null, 'verification_code' => sigiss_value($xp, 'autenticidade') ?: null,
            'issued_at' => now(), 'error_message' => null, 'updated_at' => now()];
        try {
            $cfg = fh_sigiss_cfg($em);
            $q = sigiss_call('ConsultarNotaPrestador', ['DadosPrestador' => ['type' => 'tns:tcDadosPrestador', 'fields' => ['ccm' => ['xsd:string', $cfg['ccm']], 'cnpj' => ['xsd:string', $cfg['cnpj']], 'senha' => ['xsd:string', $cfg['password']]]],
                'Nota' => ['type' => 'xsd:int', 'value' => (int)$number]], $cfg['url']);
            if ($k = only_digits(sigiss_value($q, 'chaveacesso'))) $upd['access_key'] = $k;
            if ($a = sigiss_value($q, 'autenticidade')) $upd['verification_code'] = $a;
            if (!$upd['print_url'] && ($l = sigiss_value($q, 'LinkImpressao'))) $upd['print_url'] = $l;
        } catch (Throwable $e) {
            log_line('fiscalhub', 'sigiss consult after emit failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
        db_update('fh_invoices', $id, $upd);
    } else {
        if ($problems = fh_dps_problems($inv, $em)) $fail('Corrija antes de emitir (a Sefin recusaria a nota):', $problems);
        try {
            $cert = fh_certificate($em);
        } catch (NfseException $e) {
            $fail($e->getMessage(), $e->details);
        }
        $base = nfse_endpoint((string)$inv['environment'], 'sefin');
        $aliq = null; // null = official rules; true/false after an E06xx answer (municipal parametrization)
        for ($attempt = 1; ; $attempt++) {
            try {
                $xml = fh_build_dps($inv, $em, $aliq);
                nfse_validate_xsd($xml, 'DPS_v1.01.xsd');
                $signed = nfse_sign($xml, 'infDPS', $cert);
            } catch (NfseException $e) {
                $fail($e->getMessage(), $e->details);
            }
            db_update('fh_invoices', $id, ['status' => 'processing', 'xml_dps' => $signed, 'error_message' => null, 'updated_at' => now()]);
            try {
                $res = nfse_http('POST', $base . '/nfse', ['dpsXmlGZipB64' => nfse_gzb64($signed)], $cert);
            } catch (NfseException $e) {
                $fail($e->getMessage(), $e->details);
            }
            $json = $res['json'];
            $nfseXml = nfse_ungzb64($json['nfseXmlGZipB64'] ?? null);
            $key = $json['chaveAcesso'] ?? null;
            if ($res['status'] === 409 || ($res['status'] >= 400 && !$key && preg_match('/E0014|duplic/i', $res['body']))) {
                $dps = nfse_http('GET', $base . '/dps/' . $inv['dps_id'], null, $cert);
                $key = $dps['json']['chaveAcesso'] ?? null;
                if ($key) $nfseXml = nfse_ungzb64(nfse_http('GET', $base . '/nfse/' . $key, null, $cert)['json']['nfseXmlGZipB64'] ?? null);
            }
            if ($key && $res['status'] < 500) break;
            $errors = nfse_errors($json) ?: ['HTTP ' . $res['status'] . ': ' . mb_substr(strip_tags((string)$res['body']), 0, 300)];
            // The municipality decides whether the rate goes in the DPS: retry once the way the Sefin asked.
            if ($attempt === 1 && ($want = nfse_aliq_from_errors($errors)) !== null) {
                if ($want && (float)$inv['iss_rate'] <= 0) $fail('A nota foi rejeitada pelo Emissor Nacional.', array_merge($errors, ['Como resolver: informe a alíquota do ISS na emissão (o município exige a alíquota neste caso).']));
                $aliq = $want;
                log_line('fiscalhub', 'dps retry with pAliq ' . ($want ? 'on' : 'off'), ['id' => $id, 'errors' => $errors]);
                continue;
            }
            if (in_array($res['status'], [401, 403], true)) array_unshift($errors, 'Acesso negado: confira se o certificado é do próprio emissor e se o município permite o Emissor Nacional.');
            if (!empty($inv['ctribmun']) && preg_grep('/E0314/', $errors)) $errors[] = 'Como resolver: apague o "Código municipal (cTribMun)" ' . $inv['ctribmun'] . ' na emissão e no cadastro do serviço. Ele é opcional e o seu município não usa esse código no Emissor Nacional (não confunda com o código de serviço do SIGISS).';
            $fail('A nota foi rejeitada pelo Emissor Nacional.', array_merge($errors, nfse_error_hints($errors)));
        }
        $number = $nfseXml && preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $nfseXml, $mm) ? $mm[1] : null;
        $vc = $nfseXml && preg_match('/<cVerif>([^<]+)<\/cVerif>/', $nfseXml, $mm) ? $mm[1] : null;
        db_update('fh_invoices', $id, ['status' => 'authorized', 'access_key' => $key, 'nfse_number' => $number, 'verification_code' => $vc, 'xml_nfse' => $nfseXml, 'issued_at' => now(),
            'error_message' => null, 'alerts' => !empty($json['alertas']) ? json_encode($json['alertas'], JSON_UNESCAPED_UNICODE) : null, 'updated_at' => now()]);
    }
    $inv = db_find('fh_invoices', $id);
    $x = json_decode((string)$inv['extra'], true) ?: [];
    if (!empty($x['subst']['invoice_id'])) {
        $replaced = db_exec("UPDATE fh_invoices SET status = 'canceled', canceled_at = ?, cancel_reason = ?, updated_at = ? WHERE id = ? AND customer_id = ? AND status = 'authorized'",
            [now(), 'Substituída pela NFS-e ' . ($inv['nfse_number'] ?: $inv['dps_number']), now(), (int)$x['subst']['invoice_id'], $inv['customer_id']]);
        if ($replaced) fh_finance_hook('fhf_on_invoice_canceled', db_find('fh_invoices', (int)$x['subst']['invoice_id']));
    }
    fh_finance_hook('fhf_on_invoice_authorized', $inv);
    if ((int)$em['auto_email'] && $inv['toma_email'] && $inv['environment'] === 'production') {
        try { fh_email_invoice($inv, $em); } catch (Throwable $e) { log_line('fiscalhub', 'email failed', ['id' => $id, 'error' => $e->getMessage()]); }
    }
    return $inv;
}

function fh_cancel(int $id, int $customerId, int $reason, string $justification): array
{
    $inv = fh_invoice($customerId, $id);
    if ($inv['status'] !== 'authorized') throw new AppException('Somente notas emitidas podem ser canceladas.');
    $justification = nfse_text($justification, 255);
    if (mb_strlen($justification) < 15) throw new AppException('A justificativa precisa ter pelo menos 15 caracteres.');
    if (!in_array($reason, [1, 2, 9], true)) throw new AppException('Motivo inválido.');
    $em = db_find('fh_emitters', (int)$inv['emitter_id']);
    if ($inv['provider'] === 'sigiss') {
        $cfg = fh_sigiss_cfg($em);
        $xp = sigiss_call('CancelarNota', ['DadosCancelaNota' => ['type' => 'tns:tcDadosCancelaNota', 'fields' => [
            'ccm' => ['xsd:string', $cfg['ccm']], 'cnpj' => ['xsd:string', $cfg['cnpj']], 'senha' => ['xsd:string', $cfg['password']],
            'nota' => ['xsd:int', (string)$inv['nfse_number']], 'cMotivo' => ['xsd:int', (string)$reason], 'xMotivo' => ['xsd:string', $justification], 'email' => ['xsd:string', (string)($inv['toma_email'] ?? '')],
        ]]], $cfg['url']);
        if (sigiss_value($xp, 'Resultado') !== '1') throw new NfseException('A Prefeitura (SIGISS) recusou o cancelamento.', sigiss_errors($xp) ?: ['Motivo não informado.']);
        db_update('fh_invoices', $id, ['status' => 'canceled', 'cancel_reason' => $justification, 'canceled_at' => now(), 'updated_at' => now()]);
        fh_finance_hook('fhf_on_invoice_canceled', db_find('fh_invoices', $id));
        return db_find('fh_invoices', $id);
    }
    if (!$inv['access_key']) throw new AppException('Nota sem chave de acesso.');
    $dom = new DOMDocument('1.0', 'UTF-8');
    $root = $dom->createElementNS(NFSE_NS, 'pedRegEvento');
    $root->setAttribute('versao', NFSE_LAYOUT);
    $dom->appendChild($root);
    $info = $dom->createElement('infPedReg');
    $info->setAttribute('Id', 'PRE' . $inv['access_key'] . '101101');
    $root->appendChild($info);
    $dh = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('-60 seconds')->format('Y-m-d\TH:i:sP');
    foreach (['tpAmb' => $inv['environment'] === 'production' ? '1' : '2', 'verAplic' => 'IntegraFiscalHub-1.0', 'dhEvento' => $dh, (strlen($em['document']) === 11 ? 'CPFAutor' : 'CNPJAutor') => $em['document'], 'chNFSe' => $inv['access_key']] as $k => $v) {
        $info->appendChild($dom->createElement($k))->appendChild($dom->createTextNode($v));
    }
    $grp = $dom->createElement('e101101');
    foreach (['xDesc' => 'Cancelamento de NFS-e', 'cMotivo' => (string)$reason, 'xMotivo' => $justification] as $k => $v) $grp->appendChild($dom->createElement($k))->appendChild($dom->createTextNode($v));
    $info->appendChild($grp);
    $xml = $dom->saveXML($dom->documentElement);
    nfse_validate_xsd($xml, 'pedRegEvento_v1.01.xsd');
    $cert = fh_certificate($em);
    $signed = nfse_sign($xml, 'infPedReg', $cert);
    $base = nfse_endpoint((string)$inv['environment'], 'sefin');
    $res = nfse_http('POST', $base . '/nfse/' . $inv['access_key'] . '/eventos', ['pedidoRegistroEventoXmlGZipB64' => nfse_gzb64($signed)], $cert);
    if ($res['status'] >= 400) throw new NfseException('O cancelamento foi recusado.', nfse_errors($res['json']) ?: ['HTTP ' . $res['status']]);
    db_update('fh_invoices', $id, ['status' => 'canceled', 'cancel_reason' => $justification, 'canceled_at' => now(), 'xml_cancel' => nfse_ungzb64($res['json']['eventoXmlGZipB64'] ?? null), 'updated_at' => now()]);
    fh_finance_hook('fhf_on_invoice_canceled', db_find('fh_invoices', $id));
    return db_find('fh_invoices', $id);
}

/** New draft that replaces an authorized note (Emissor Nacional substitution). */
function fh_substitute(int $id, int $customerId, string $motivo, string $descricao): array
{
    $inv = fh_invoice($customerId, $id);
    if ($inv['status'] !== 'authorized' || $inv['provider'] !== 'nacional' || !$inv['access_key']) throw new AppException('A substituição está disponível para notas autorizadas no Emissor Nacional. No SIGISS, cancele e emita uma nova.');
    $em = db_find('fh_emitters', (int)$inv['emitter_id']);
    $x = json_decode((string)$inv['extra'], true) ?: [];
    $in = fh_invoice_to_input($inv);
    $in['extra'] = $x + ['subst' => ['chave' => $inv['access_key'], 'motivo' => $motivo, 'descricao' => $descricao, 'invoice_id' => $inv['id']]];
    return fh_invoice_save($em, $in, null, 'substitute');
}

/** True when SIGISS note $n exists and was generated from this invoice's RPS (a refusal that still carried a note number). */
function fh_sigiss_note_is_rps(array $inv, array $em, int $n): bool
{
    if ($n <= 0) return false;
    try {
        $cfg = fh_sigiss_cfg($em);
        $q = sigiss_call('ConsultarNotaPrestador', ['DadosPrestador' => ['type' => 'tns:tcDadosPrestador', 'fields' => ['ccm' => ['xsd:string', $cfg['ccm']], 'cnpj' => ['xsd:string', $cfg['cnpj']], 'senha' => ['xsd:string', $cfg['password']]]],
            'Nota' => ['type' => 'xsd:int', 'value' => $n]], $cfg['url']);
        return ($rps = only_digits(sigiss_value($q, 'num_rps'))) !== '' && (int)$rps === (int)$inv['dps_number'];
    } catch (Throwable $e) {
        return false;
    }
}

/** The SIGISS refused without saying why: test the login and report what we can find out. */
function fh_sigiss_diagnose(array $inv, array $em, DOMXPath $xp): array
{
    $cfg = fh_sigiss_cfg($em);
    if ($cfg['password'] === '') return [FH_SECRET_LOST_SIGISS];
    try {
        $q = sigiss_call('ConsultarNotaPrestador', ['DadosPrestador' => ['type' => 'tns:tcDadosPrestador', 'fields' => ['ccm' => ['xsd:string', $cfg['ccm']], 'cnpj' => ['xsd:string', $cfg['cnpj']], 'senha' => ['xsd:string', $cfg['password']]]],
            'Nota' => ['type' => 'xsd:int', 'value' => 1]], $cfg['url']);
        if ($auth = sigiss_auth_errors(sigiss_errors($q))) return $auth;
    } catch (NfseException $e) {
        return [$e->getMessage()];
    }
    return ['O SIGISS recusou sem informar o motivo (Resultado ' . (sigiss_value($xp, 'Resultado') ?: 'vazio') . '). Testamos o acesso (CCM ' . $cfg['ccm'] . ', CNPJ e senha) e ele está correto.',
        'Confira com a Prefeitura se o código de serviço ' . ($inv['sigiss_code'] ?: '—') . ' está liberado para o CCM da empresa e se a alíquota ' . number_format((float)$inv['iss_rate'], 2, ',', '') . '% confere com o cadastro municipal.'];
}

/**
 * Inutilização of a DPS/RPS number that never became an NFS-e (draft, rejected or a transmission that got stuck).
 * Neither the Sefin Nacional nor the SIGISS has an inutilização service (the NFS-e number is assigned by the authority),
 * so the number is first checked with the authority itself: if it did become a note, the note is recovered instead
 * (and must be canceled); otherwise it is permanently voided here, with the justification and the verification made.
 */
function fh_void(int $id, int $customerId, string $justification, ?string $by = null): array
{
    $inv = fh_invoice($customerId, $id);
    if (in_array($inv['status'], ['authorized', 'canceled'], true)) throw new AppException('Esta nota já foi emitida. Para anulá-la, use "Cancelar".');
    if ($inv['status'] === 'voided') throw new AppException('Este número já foi inutilizado.');
    if ($inv['status'] === 'processing' && strtotime((string)$inv['updated_at']) > time() - 90) throw new AppException('Esta nota está sendo transmitida agora. Aguarde alguns segundos e tente de novo.');
    $justification = nfse_text($justification, 255);
    if (mb_strlen($justification) < 15) throw new AppException('A justificativa precisa ter pelo menos 15 caracteres.');
    $em = db_find('fh_emitters', (int)$inv['emitter_id']);
    $proof = [];
    if (!empty($inv['xml_dps'])) $proof[] = fh_void_check_nacional($inv, $em);
    elseif ($inv['provider'] === 'nacional') $proof[] = 'DPS nunca transmitida à Sefin Nacional.';
    if ($inv['provider'] === 'sigiss') $proof[] = fh_void_check_sigiss($inv, $em);
    $x = json_decode((string)$inv['extra'], true) ?: [];
    $x['inutilizacao'] = ['data' => now(), 'justificativa' => $justification, 'verificacao' => $proof, 'usuario' => $by];
    $done = db_exec("UPDATE fh_invoices SET status = 'voided', cancel_reason = ?, canceled_at = ?, extra = ?, error_message = NULL, updated_at = ? WHERE id = ? AND status = ?",
        [$justification, now(), json_encode($x, JSON_UNESCAPED_UNICODE), now(), $id, $inv['status']]);
    if (!$done) throw new AppException('A situação da nota mudou enquanto ela era inutilizada. Atualize a página.');
    log_line('fiscalhub', 'dps voided', ['id' => $id, 'dps' => $inv['dps_serie'] . '-' . $inv['dps_number'], 'proof' => $proof]);
    return db_find('fh_invoices', $id);
}

/** Asks the Sefin whether the DPS became an NFS-e; if it did, the note is recovered and the inutilização is refused. */
function fh_void_check_nacional(array $inv, array $em): string
{
    try {
        $cert = fh_certificate($em);
    } catch (NfseException $e) {
        throw new NfseException('Para inutilizar, precisamos confirmar com a Sefin Nacional que esta DPS não virou nota, e isso exige o certificado digital da empresa.', [$e->getMessage()]);
    }
    $base = nfse_endpoint((string)$inv['environment'], 'sefin');
    $res = nfse_http('GET', $base . '/dps/' . $inv['dps_id'], null, $cert);
    $key = $res['json']['chaveAcesso'] ?? null;
    if ($res['status'] === 404 && !$key) return 'Sefin Nacional consultada em ' . date('d/m/Y H:i') . ': a DPS ' . $inv['dps_id'] . ' não gerou NFS-e.';
    if (!$key) throw new NfseException('Não foi possível confirmar com a Sefin Nacional se esta DPS virou nota. Nada foi inutilizado; tente novamente em instantes.', nfse_errors($res['json']) ?: ['HTTP ' . $res['status']]);
    $xml = nfse_ungzb64(nfse_http('GET', $base . '/nfse/' . $key, null, $cert)['json']['nfseXmlGZipB64'] ?? null);
    $number = $xml && preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $xml, $mm) ? $mm[1] : null;
    $vc = $xml && preg_match('/<cVerif>([^<]+)<\/cVerif>/', $xml, $mm) ? $mm[1] : null;
    fh_void_recovered($inv, ['access_key' => $key, 'nfse_number' => $number, 'verification_code' => $vc, 'xml_nfse' => $xml]);
}

/** SIGISS: looks at the notes issued after the last one we know, comparing their RPS with this one. */
function fh_void_check_sigiss(array $inv, array $em): string
{
    $cfg = fh_sigiss_cfg($em);
    $nums = array_map('intval', array_column(db_all("SELECT nfse_number FROM fh_invoices WHERE emitter_id = ? AND provider = 'sigiss' AND nfse_number IS NOT NULL AND status IN ('authorized','canceled')", [$em['id']]), 'nfse_number'));
    $last = $nums ? max($nums) : 0;
    if (!$last) return 'SIGISS: não há notas anteriores desta empresa no sistema para comparar; a Prefeitura responde a emissão na hora e não autorizou este RPS.';
    $checked = [];
    for ($n = $last + 1; $n <= $last + 20; $n++) {
        $xp = sigiss_call('ConsultarNotaPrestador', ['DadosPrestador' => ['type' => 'tns:tcDadosPrestador', 'fields' => ['ccm' => ['xsd:string', $cfg['ccm']], 'cnpj' => ['xsd:string', $cfg['cnpj']], 'senha' => ['xsd:string', $cfg['password']]]],
            'Nota' => ['type' => 'xsd:int', 'value' => $n]], $cfg['url']);
        $errors = sigiss_errors($xp);
        if ($auth = sigiss_auth_errors($errors)) throw new NfseException('O SIGISS recusou a consulta, então não foi possível confirmar que este RPS não virou nota. Nada foi inutilizado.', $auth);
        $rps = only_digits(sigiss_value($xp, 'num_rps'));
        if ($rps === '' && sigiss_value($xp, 'nota') === '') {
            // no note with this number (end of the list); any other kind of error means we could not check
            if ($errors && !preg_grep('/\bnota\b|inexist|n[aã]o encontrad|n[aã]o localizad/iu', $errors)) throw new NfseException('O SIGISS não respondeu a consulta como esperado. Nada foi inutilizado; tente novamente em instantes.', $errors);
            break;
        }
        $checked[] = $n;
        $serie = trim(sigiss_value($xp, 'serie_rps'));
        if ((int)$rps === (int)$inv['dps_number'] && ($serie === '' || strcasecmp($serie, (string)$inv['dps_serie']) === 0)) {
            fh_void_recovered($inv, ['nfse_number' => (string)$n, 'access_key' => only_digits(sigiss_value($xp, 'chaveacesso')) ?: null,
                'verification_code' => sigiss_value($xp, 'autenticidade') ?: null, 'print_url' => sigiss_value($xp, 'LinkImpressao') ?: null]);
        }
    }
    return 'SIGISS consultado em ' . date('d/m/Y H:i') . ': ' . ($checked ? 'notas ' . $checked[0] . (count($checked) > 1 ? ' a ' . end($checked) : '') . ' conferidas, nenhuma é do RPS ' . $inv['dps_number'] : 'nenhuma nota emitida após a nº ' . $last) . '.';
}

/** The number did become a note at the authority: store it as authorized and refuse the inutilização. */
function fh_void_recovered(array $inv, array $upd): void
{
    db_update('fh_invoices', (int)$inv['id'], $upd + ['status' => 'authorized', 'issued_at' => $inv['issued_at'] ?: now(), 'error_message' => null, 'updated_at' => now()]);
    $fresh = db_find('fh_invoices', (int)$inv['id']);
    fh_finance_hook('fhf_on_invoice_authorized', $fresh);
    log_line('fiscalhub', 'void refused: note exists', ['id' => $inv['id'], 'nfse' => $fresh['nfse_number']]);
    throw new AppException('Não inutilizado: este número já virou a NFS-e' . ($fresh['nfse_number'] ? ' nº ' . $fresh['nfse_number'] : '') . ' na ' . ($inv['provider'] === 'sigiss' ? 'Prefeitura (SIGISS)' : 'Sefin Nacional')
        . '. Recuperamos a nota no sistema; se ela não deveria existir, abra-a e use "Cancelar".');
}

/** Rebuild the form input of an invoice (duplicate / substitute / recurring). */
function fh_invoice_to_input(array $inv): array
{
    $x = json_decode((string)$inv['extra'], true) ?: [];
    $in = ['taker' => json_decode((string)$inv['toma_json'], true) ?: [], 'service_id' => $inv['service_id'], 'lc116' => $inv['lc116'], 'ctribnac' => $inv['ctribnac'], 'ctribmun' => $inv['ctribmun'],
        'sigiss_code' => $inv['sigiss_code'], 'cnbs' => $inv['cnbs'], 'description' => $x['desc_raw'] ?? $inv['description'], 'amount' => $inv['amount'], 'discount_incond' => $inv['discount_incond'],
        'discount_cond' => $inv['discount_cond'], 'deductions' => $inv['deductions'], 'situation' => $inv['sigiss_situacao'], 'iss_rate' => $inv['iss_rate'], 'pis_cofins_cst' => $inv['pis_cofins_cst']];
    if ($inv['taker_id']) $in['taker_id'] = $inv['taker_id'];
    foreach (['pis', 'cofins', 'csll', 'irrf', 'inss'] as $t) { $in[$t . '_rate'] = $inv[$t . '_rate']; $in[$t . '_withheld'] = (bool)$inv[$t . '_withheld']; }
    unset($x['subst'], $x['desc_raw']);
    $in['extra'] = $x;
    return $in;
}
