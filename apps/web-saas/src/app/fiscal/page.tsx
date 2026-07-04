'use client';

import { useEffect, useState } from 'react';
import { StatCard } from '@integra/ui';
import { formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface Dashboard {
  nfeIssuedThisMonth: number;
  nfceIssuedThisMonth: number;
  authorized: number;
  rejected: number;
  canceled: number;
  pending: number;
  lastIssueAt: string | null;
  environment: string;
  certificate: { validUntil: string | null; daysToExpire: number | null; status: string } | null;
  nfeSeries: number | null;
  nfceSeries: number | null;
  nextNfeNumber: number | null;
  nextNfceNumber: number | null;
  sefazStatus: Array<{ uf: string; environment: string; online: boolean; message: string | null; checkedAt: string }>;
  recentRejections: Array<{ id: string; code: string; message: string; createdAt: string }>;
}

export default function FiscalDashboardPage() {
  const [data, setData] = useState<Dashboard | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<Dashboard>('/fiscal/dashboard')
      .then(setData)
      .catch((err) => setError(err.message));
  }, []);

  if (error) {
    return (
      <div className="page">
        <h1>Dashboard Fiscal</h1>
        <div className="alert alert-error">{error}</div>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="page">
        <h1>Dashboard Fiscal</h1>
        <p className="muted">Carregando…</p>
      </div>
    );
  }

  const certWarning =
    data.certificate && data.certificate.daysToExpire !== null && data.certificate.daysToExpire <= 30;

  return (
    <div className="page">
      <h1>Dashboard Fiscal</h1>
      <p className="subtitle">
        Ambiente atual: <strong>{data.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</strong>
      </p>

      {certWarning && (
        <div className="alert alert-warning">
          Certificado digital próximo do vencimento: {data.certificate?.daysToExpire} dia(s) restante(s)
          {data.certificate?.validUntil ? ` (válido até ${formatDateTime(data.certificate.validUntil)})` : ''}.
        </div>
      )}
      {!data.certificate && (
        <div className="alert alert-warning">Nenhum certificado A1 cadastrado. Emissão bloqueada até o upload.</div>
      )}

      <div className="grid grid-4" style={{ marginBottom: 20 }}>
        <StatCard title="NF-e emitidas no mês" value={data.nfeIssuedThisMonth} />
        <StatCard title="NFC-e emitidas no mês" value={data.nfceIssuedThisMonth} />
        <StatCard title="Notas autorizadas" value={data.authorized} accent="#16a34a" />
        <StatCard title="Notas rejeitadas" value={data.rejected} accent="#dc2626" />
        <StatCard title="Notas canceladas" value={data.canceled} accent="#6b7280" />
        <StatCard title="Notas pendentes" value={data.pending} accent="#d97706" />
        <StatCard
          title="Última emissão"
          value={data.lastIssueAt ? formatDateTime(data.lastIssueAt) : '—'}
          accent="#0891b2"
        />
        <StatCard
          title="Certificado"
          value={data.certificate ? `${data.certificate.daysToExpire ?? '—'} dias` : 'Ausente'}
          hint={data.certificate?.status}
          accent={certWarning ? '#dc2626' : '#16a34a'}
        />
      </div>

      <div className="grid grid-2">
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Série e numeração</h3>
          <table>
            <tbody>
              <tr><td>Série NF-e atual</td><td><strong>{data.nfeSeries ?? '—'}</strong></td></tr>
              <tr><td>Próximo número NF-e</td><td><strong>{data.nextNfeNumber ?? '—'}</strong></td></tr>
              <tr><td>Série NFC-e atual</td><td><strong>{data.nfceSeries ?? '—'}</strong></td></tr>
              <tr><td>Próximo número NFC-e</td><td><strong>{data.nextNfceNumber ?? '—'}</strong></td></tr>
            </tbody>
          </table>
        </div>

        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Status SEFAZ por UF</h3>
          {data.sefazStatus.length === 0 ? (
            <p className="muted">Nenhuma consulta de status registrada. Use a tela de Notas Fiscais para consultar.</p>
          ) : (
            <table>
              <thead>
                <tr><th>UF</th><th>Ambiente</th><th>Status</th><th>Verificado</th></tr>
              </thead>
              <tbody>
                {data.sefazStatus.map((status, index) => (
                  <tr key={index}>
                    <td>{status.uf}</td>
                    <td>{status.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</td>
                    <td style={{ color: status.online ? '#16a34a' : '#dc2626', fontWeight: 600 }}>
                      {status.online ? 'Online' : 'Offline'}
                    </td>
                    <td className="muted">{formatDateTime(status.checkedAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Rejeições recentes</h3>
        {data.recentRejections.length === 0 ? (
          <p className="muted">Nenhuma rejeição recente.</p>
        ) : (
          <table>
            <thead>
              <tr><th>Código</th><th>Mensagem</th><th>Data</th></tr>
            </thead>
            <tbody>
              {data.recentRejections.map((rejection) => (
                <tr key={rejection.id}>
                  <td className="mono">{rejection.code}</td>
                  <td>{rejection.message}</td>
                  <td className="muted">{formatDateTime(rejection.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
