'use client';

import { useEffect, useState } from 'react';
import { formatCnpj, formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface CertificateRow {
  id: string;
  subjectCnpj: string | null;
  validUntil: string | null;
  status: string;
  company: { corporateName: string; cnpj: string };
}

export default function CertificadosPage() {
  const [rows, setRows] = useState<CertificateRow[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<CertificateRow[]>('/admin/fiscal/certificates/expiring')
      .then(setRows)
      .catch((err) => setError(err.message));
  }, []);

  return (
    <div className="page">
      <h1>Certificados vencendo</h1>
      <p className="subtitle">Certificados A1 expirando nos próximos 45 dias — acione os clientes para renovação</p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Empresa</th><th>CNPJ</th><th>Válido até</th><th>Dias restantes</th><th>Status</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={5} className="muted">Nenhum certificado próximo do vencimento.</td></tr>}
            {rows.map((certificate) => {
              const days = certificate.validUntil
                ? Math.ceil((new Date(certificate.validUntil).getTime() - Date.now()) / 86_400_000)
                : null;
              return (
                <tr key={certificate.id}>
                  <td>{certificate.company.corporateName}</td>
                  <td className="mono">{formatCnpj(certificate.company.cnpj)}</td>
                  <td>{certificate.validUntil ? formatDateTime(certificate.validUntil) : '—'}</td>
                  <td style={{ color: days !== null && days < 0 ? '#dc2626' : days !== null && days <= 15 ? '#d97706' : undefined, fontWeight: 600 }}>
                    {days !== null ? (days < 0 ? `Expirado há ${-days} dia(s)` : `${days} dia(s)`) : '—'}
                  </td>
                  <td>{certificate.status}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
