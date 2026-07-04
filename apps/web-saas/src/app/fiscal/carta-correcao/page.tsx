'use client';

import { useEffect, useState } from 'react';
import { formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface AuthorizedNfe {
  id: string;
  series: number;
  number: number;
  accessKey: string | null;
  issueDate: string;
  customer: { name: string } | null;
}

export default function CartaCorrecaoPage() {
  const [invoices, setInvoices] = useState<AuthorizedNfe[]>([]);
  const [invoiceId, setInvoiceId] = useState('');
  const [correctionText, setCorrectionText] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ success: boolean; sequence?: number; sefazCode?: string; sefazMessage?: string; protocol?: string } | null>(null);

  useEffect(() => {
    api<{ data: AuthorizedNfe[] }>('/fiscal/invoices?model=55&status=AUTHORIZED&pageSize=100')
      .then((response) => setInvoices(response.data))
      .catch((err) => setError(err.message));
  }, []);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    setResult(null);
    try {
      const response = await api<typeof result>(`/fiscal/invoices/${invoiceId}/correction-letter`, {
        method: 'POST',
        body: { correctionText },
      });
      setResult(response);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha ao registrar CC-e');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="page">
      <h1>Carta de Correção (CC-e)</h1>
      <p className="subtitle">
        Disponível apenas para NF-e autorizadas. Não corrige valores, quantidades ou dados cadastrais principais.
      </p>

      {error && <div className="alert alert-error">{error}</div>}
      {result && (
        <div className={`alert ${result.success ? 'alert-success' : 'alert-error'}`}>
          {result.success
            ? `Carta de correção nº ${result.sequence} registrada. Protocolo ${result.protocol}.`
            : `CC-e rejeitada: ${result.sefazCode} — ${result.sefazMessage}`}
        </div>
      )}

      <form onSubmit={handleSubmit} className="card">
        <div className="field">
          <label>NF-e autorizada</label>
          <select value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)} required>
            <option value="">Selecione a nota…</option>
            {invoices.map((invoice) => (
              <option key={invoice.id} value={invoice.id}>
                NF-e {invoice.series}/{invoice.number} — {invoice.customer?.name ?? 'Consumidor'} — {formatDateTime(invoice.issueDate)}
              </option>
            ))}
          </select>
        </div>
        <div className="field">
          <label>Texto da correção (15 a 1000 caracteres)</label>
          <textarea
            rows={4}
            value={correctionText}
            onChange={(e) => setCorrectionText(e.target.value)}
            required
            minLength={15}
            maxLength={1000}
          />
        </div>
        <button className="btn" disabled={loading || !invoiceId}>
          {loading ? 'Registrando…' : 'Registrar carta de correção'}
        </button>
      </form>
    </div>
  );
}
