'use client';

import { useEffect, useState } from 'react';
import { formatAccessKey, formatDateTime } from '@integra/shared';
import { api, downloadBase64 } from '@/lib/api';

interface InvoiceWithXml {
  id: string;
  model: 'NFE_55' | 'NFCE_65';
  series: number;
  number: number;
  status: string;
  accessKey: string | null;
  issueDate: string;
}

export default function XmlsPage() {
  const [rows, setRows] = useState<InvoiceWithXml[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: InvoiceWithXml[] }>('/fiscal/invoices?pageSize=100')
      .then((response) => setRows(response.data.filter((row) => row.accessKey)))
      .catch((err) => setError(err.message));
  }, []);

  async function download(invoice: InvoiceWithXml) {
    setBusy(invoice.id);
    setError(null);
    try {
      const result = await api<{ xmlBase64: string; fileName: string }>(`/fiscal/invoices/${invoice.id}/xml`);
      downloadBase64(result.xmlBase64, result.fileName, 'application/xml');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao baixar XML');
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="page">
      <h1>XMLs</h1>
      <p className="subtitle">
        XMLs autorizados e cancelados armazenados pelo fiscal-service — imutáveis após autorização
      </p>

      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <table>
          <thead>
            <tr><th>Nota</th><th>Chave de acesso</th><th>Status</th><th>Emissão</th><th></th></tr>
          </thead>
          <tbody>
            {rows.length === 0 && <tr><td colSpan={5} className="muted">Nenhum XML disponível ainda.</td></tr>}
            {rows.map((invoice) => (
              <tr key={invoice.id}>
                <td>{invoice.model === 'NFE_55' ? 'NF-e' : 'NFC-e'} {invoice.series}/{invoice.number}</td>
                <td className="mono">{invoice.accessKey ? formatAccessKey(invoice.accessKey) : '—'}</td>
                <td>{invoice.status}</td>
                <td className="muted">{formatDateTime(invoice.issueDate)}</td>
                <td>
                  <button className="btn btn-sm btn-secondary" disabled={busy === invoice.id} onClick={() => download(invoice)}>
                    {busy === invoice.id ? 'Baixando…' : 'Baixar XML'}
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
