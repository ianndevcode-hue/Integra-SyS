'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { NAV_SECTIONS, isNavActive } from '@/config/navigation';
import { api, clearToken } from '@/lib/api';

interface Profile {
  user: { name: string; email: string; role: string };
  company: { tradeName: string | null; corporateName: string; cnpj: string } | null;
  license: { plan: string; status: string } | null;
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [profile, setProfile] = useState<Profile | null>(null);

  useEffect(() => {
    api<Profile>('/auth/me')
      .then(setProfile)
      .catch(() => {
        clearToken();
        router.push('/login');
      });
  }, [router]);

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside
        style={{
          width: 260,
          background: '#111827',
          color: '#e5e7eb',
          padding: '20px 12px',
          flexShrink: 0,
          overflowY: 'auto',
        }}
      >
        <div style={{ fontWeight: 700, fontSize: 18, padding: '0 10px', marginBottom: 4, color: '#fff' }}>
          Integra SYS
        </div>
        <div style={{ fontSize: 12, padding: '0 10px', marginBottom: 16, color: '#9ca3af' }}>
          {profile?.company?.tradeName ?? profile?.company?.corporateName ?? 'ERP / PDV SaaS'}
        </div>

        {NAV_SECTIONS.map((section) => (
          <div key={section.id} style={{ marginBottom: 16 }}>
            <div
              style={{
                fontSize: 11,
                fontWeight: 700,
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
                color: '#6b7280',
                padding: '0 10px',
                marginBottom: 6,
              }}
            >
              {section.label}
            </div>
            <nav style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
              {section.items.map((item) => {
                const active = isNavActive(pathname, item.href);
                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    style={{
                      padding: '7px 10px',
                      borderRadius: 8,
                      fontSize: 13,
                      background: active ? '#1d4ed8' : 'transparent',
                      color: active ? '#fff' : '#d1d5db',
                    }}
                  >
                    {item.label}
                  </Link>
                );
              })}
            </nav>
          </div>
        ))}

        {profile && (
          <div style={{ padding: '12px 10px', marginTop: 8, borderTop: '1px solid #374151', fontSize: 12 }}>
            <div style={{ color: '#fff', fontWeight: 600 }}>{profile.user.name}</div>
            <div style={{ color: '#9ca3af' }}>{profile.user.email}</div>
            {profile.license && (
              <div style={{ color: '#6b7280', marginTop: 4 }}>
                Plano {profile.license.plan} · {profile.license.status}
              </div>
            )}
          </div>
        )}

        <button
          className="btn btn-secondary btn-sm"
          style={{ marginTop: 12, marginLeft: 10 }}
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
