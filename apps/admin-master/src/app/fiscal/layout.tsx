'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { clearToken } from '@/lib/api';

const MENU = [
  { href: '/fiscal', label: 'Visão geral' },
  { href: '/fiscal/empresas', label: '1. Empresas com fiscal ativo' },
  { href: '/fiscal/falhas', label: '2. Falhas fiscais recentes' },
  { href: '/fiscal/certificados', label: '3. Certificados vencendo' },
  { href: '/fiscal/uso', label: '4. Uso fiscal por cliente' },
  { href: '/fiscal/logs', label: '5. Logs do fiscal-service' },
  { href: '/fiscal/sefaz', label: '6. Status dos serviços SEFAZ' },
  { href: '/fiscal/config-global', label: '7. Configuração global' },
];

export default function AdminFiscalLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside style={{ width: 260, background: '#0f172a', color: '#e5e7eb', padding: '20px 12px', flexShrink: 0 }}>
        <div style={{ fontWeight: 700, fontSize: 17, padding: '0 10px', marginBottom: 4, color: '#fff' }}>
          Integra SYS
        </div>
        <div style={{ fontSize: 12, padding: '0 10px', marginBottom: 20, color: '#94a3b8' }}>
          Admin Master — Fiscal
        </div>
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
                  background: active ? '#7c3aed' : 'transparent',
                  color: active ? '#fff' : '#cbd5e1',
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
