'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { clearToken } from '@/lib/api';

const MENU = [
  { href: '/fiscal', label: '1. Dashboard Fiscal' },
  { href: '/fiscal/notas', label: '2. Notas Fiscais' },
  { href: '/fiscal/emitir-nfe', label: '3. Emitir NF-e' },
  { href: '/fiscal/emitir-nfce', label: '4. Emitir NFC-e' },
  { href: '/fiscal/configuracoes', label: '5. Configurações Fiscais' },
  { href: '/fiscal/certificado', label: '6. Certificado Digital' },
  { href: '/fiscal/series', label: '7. Série e Numeração' },
  { href: '/fiscal/csc', label: '8. CSC/Token NFC-e' },
  { href: '/fiscal/inutilizacao', label: '9. Inutilização' },
  { href: '/fiscal/carta-correcao', label: '10. Carta de Correção' },
  { href: '/fiscal/rejeicoes', label: '11. Rejeições' },
  { href: '/fiscal/xmls', label: '12. XMLs' },
  { href: '/fiscal/danfe', label: '13. DANFE/DANFCE' },
  { href: '/fiscal/logs', label: '14. Logs Fiscais' },
];

export default function FiscalLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside
        style={{
          width: 250,
          background: '#111827',
          color: '#e5e7eb',
          padding: '20px 12px',
          flexShrink: 0,
        }}
      >
        <div style={{ fontWeight: 700, fontSize: 18, padding: '0 10px', marginBottom: 6, color: '#fff' }}>
          Integra SYS
        </div>
        <div style={{ fontSize: 12, padding: '0 10px', marginBottom: 20, color: '#9ca3af' }}>Menu Fiscal</div>
        <nav style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          {MENU.map((item) => {
            const active = pathname === item.href;
            return (
              <Link
                key={item.href}
                href={item.href}
                style={{
                  padding: '8px 10px',
                  borderRadius: 8,
                  fontSize: 13.5,
                  background: active ? '#1d4ed8' : 'transparent',
                  color: active ? '#fff' : '#d1d5db',
                }}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
        <button
          className="btn btn-secondary btn-sm"
          style={{ marginTop: 24, marginLeft: 10 }}
          onClick={() => {
            clearToken();
            router.push('/login');
          }}
        >
          Sair
        </button>
      </aside>
      <main style={{ flex: 1, overflow: 'auto' }}>{children}</main>
    </div>
  );
}
