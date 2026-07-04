export interface NavItem {
  href: string;
  label: string;
}

export interface NavSection {
  id: string;
  label: string;
  items: NavItem[];
}

export const NAV_SECTIONS: NavSection[] = [
  {
    id: 'principal',
    label: 'Principal',
    items: [{ href: '/dashboard', label: 'Dashboard' }],
  },
  {
    id: 'comercial',
    label: 'Comercial',
    items: [
      { href: '/produtos', label: 'Produtos' },
      { href: '/clientes', label: 'Clientes' },
      { href: '/vendas', label: 'Vendas' },
      { href: '/pdv', label: 'PDV' },
    ],
  },
  {
    id: 'operacional',
    label: 'Operacional',
    items: [
      { href: '/estoque', label: 'Estoque' },
      { href: '/relatorios', label: 'Relatórios' },
    ],
  },
  {
    id: 'fiscal',
    label: 'Fiscal',
    items: [
      { href: '/fiscal', label: 'Dashboard Fiscal' },
      { href: '/fiscal/notas', label: 'Notas Fiscais' },
      { href: '/fiscal/emitir-nfe', label: 'Emitir NF-e' },
      { href: '/fiscal/emitir-nfce', label: 'Emitir NFC-e' },
      { href: '/fiscal/configuracoes', label: 'Configurações Fiscais' },
      { href: '/fiscal/certificado', label: 'Certificado Digital' },
      { href: '/fiscal/series', label: 'Série e Numeração' },
      { href: '/fiscal/csc', label: 'CSC/Token NFC-e' },
      { href: '/fiscal/inutilizacao', label: 'Inutilização' },
      { href: '/fiscal/carta-correcao', label: 'Carta de Correção' },
      { href: '/fiscal/rejeicoes', label: 'Rejeições' },
      { href: '/fiscal/xmls', label: 'XMLs' },
      { href: '/fiscal/danfe', label: 'DANFE/DANFCE' },
      { href: '/fiscal/logs', label: 'Logs Fiscais' },
    ],
  },
  {
    id: 'sistema',
    label: 'Sistema',
    items: [{ href: '/configuracoes', label: 'Configurações' }],
  },
];

export function isNavActive(pathname: string, href: string): boolean {
  if (href === '/dashboard') return pathname === '/dashboard';
  if (href === '/fiscal') return pathname === '/fiscal';
  return pathname === href || pathname.startsWith(`${href}/`);
}
