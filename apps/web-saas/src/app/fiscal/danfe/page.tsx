'use client';

import { useEffect, useState } from 'react';
import { formatDateTime } from '@integra/shared';
import { api, openPdfBase64 } from '@/lib/api';

interface AuthorizedInvoice {
  id: string;
  model: 'NFE_55' | 'NFCE_65';
  series: number;
  number: number;
  accessKey: string | null;
  issueDate: string;
  customer: { name: string } | null;
}

export default function DanfePage() {
  const [rows, setRows] = useState<AuthorizedInvoice[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: AuthorizedInvoice[] }>('/fiscal/invoices?status=AUTHORIZED&pageSize=100')
      .then((response) => setRows(response.data))
      .catch((err) => setError(err.message));
  }, []);

  async function print(invoice: AuthorizedInvoice) {
    setBusy(invoice.id);
    setError(null);
    try {
      const endpoint = invoice.model === 'NFE_55' ? 'danfe' : 'danfce';
      const result = await api<{ pdfBase64: string }>(`/fiscal/invoices/${invoice.id}/${endpoint}`);
      openPdfBase64(result.pdfBase64);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao gerar PDF');
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="page">
      <h1>DANFE / DANFCE</h1>
      <p className="subtitle">
        Impressão dos documentos auxiliares: DANFE (A4) para NF-e e DANFCE (cupom 80mm) para NFC-e — gerados pelo
        fiscal-service com NFePHP/sped-da
      </p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Nota</th><th>Cliente</th><th>Emissão</th><th>Documento</th><th></th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={5} className="muted">Nenhuma nota autorizada.</td></tr>}
            {rows.map((invoice) => (
              <tr key={invoice.id}>
                <td>{invoice.model === 'NFE_55' ? 'NF-e' : 'NFC-e'} {invoice.series}/{invoice.number}</td>
                <td>{invoice.customer?.name ?? 'Consumidor'}</td>
                <td className="muted">{formatDateTime(invoice.issueDate)}</td>
                <td>{invoice.model === 'NFE_55' ? 'DANFE (A4)' : 'DANFCE (80mm)'}</td>
                <td>
                  <button className="btn btn-sm" disabled={busy === invoice.id} onClick={() => print(invoice)}>
                    {busy === invoice.id ? 'Gerando…' : 'Imprimir'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
