import type { CSSProperties, ReactNode } from 'react';

export interface StatCardProps {
  title: string;
  value: ReactNode;
  hint?: string;
  accent?: string;
  style?: CSSProperties;
}

export function StatCard({ title, value, hint, accent = '#1d4ed8', style }: StatCardProps) {
  return (
    <div
      style={{
        background: '#fff',
        borderRadius: 12,
        padding: '16px 20px',
        boxShadow: '0 1px 3px rgba(0,0,0,0.08)',
        borderLeft: `4px solid ${accent}`,
        minWidth: 180,
        ...style,
      }}
    >
      <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 4 }}>{title}</div>
      <div style={{ fontSize: 26, fontWeight: 700, color: '#111827' }}>{value}</div>
      {hint ? <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 4 }}>{hint}</div> : null}
    </div>
  );
}
