'use client';

import { useEffect, useState } from 'react';
import { StatCard } from '@integra/ui';
import { api } from '@/lib/api';

interface Overview {
  companiesWithFiscal: number;
  invoicesThisMonth: number;
  recentFailures: number;
  expiringCertificates: number;
  fiscalServiceHealth: { status: string; error?: string };
}

export default function AdminFiscalOverviewPage() {
  const [data, setData] = useState<Overview | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<Overview>('/admin/fiscal/overview')
      .then(setData)
      .catch((err) => setError(err.message));
  }, []);

  if (error) return <div className="page"><h1>Visão fiscal</h1><div className="alert alert-error">{error}</div></div>;
  if (!data) return <div className="page"><h1>Visão fiscal</h1><p className="muted">Carregando…</p></div>;

  const fiscalOnline = data.fiscalServiceHealth.status === 'ok';

  return (
    <div className="page">
      <h1>Visão fiscal administrativa</h1>
      <p className="subtitle">Suporte, diagnóstico e auditoria — este painel não emite notas em nome de clientes</p>

      {!fiscalOnline && (
        <div className="alert alert-error">
          fiscal-service offline: {data.fiscalServiceHealth.error ?? 'sem resposta'}
        </div>
      )}

      <div className="grid grid-4">
        <StatCard title="Empresas com fiscal ativo" value={data.companiesWithFiscal} />
        <StatCard title="Notas emitidas no mês" value={data.invoicesThisMonth} accent="#16a34a" />
        <StatCard title="Falhas (últimos 7 dias)" value={data.recentFailures} accent="#dc2626" />
        <StatCard title="Certificados vencendo (30d)" value={data.expiringCertificates} accent="#d97706" />
        <StatCard
          title="Fiscal Service"
          value={fiscalOnline ? 'Online' : 'Offline'}
          accent={fiscalOnline ? '#16a34a' : '#dc2626'}
        />
      </div>
    </div>
  );
}
