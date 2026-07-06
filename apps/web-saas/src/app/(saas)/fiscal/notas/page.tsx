'use client';

import { useCallback, useEffect, useState } from 'react';
import { InvoiceStatusBadge } from '@integra/ui';
import type { InvoiceStatus } from '@integra/types';
import { INVOICE_STATUS_LABELS } from '@integra/types';
import { formatCurrency, formatDateTime } from '@integra/shared';
import { api, downloadBase64, openPdfBase64 } from '@/lib/api';

interface InvoiceRow {
  id: string;
  model: 'NFE_55' | 'NFCE_65';
  series: number;
  number: number;
  status: InvoiceStatus;
  environment: string;
  accessKey: string | null;
  protocol: string | null;
  totalInvoice: string;
  issueDate: string;
  rejectionCode: string | null;
  rejectionMessage: string | null;
  customer: { name: string; document: string | null } | null;
}

const STATUS_OPTIONS = Object.entries(INVOICE_STATUS_LABELS);

export default function NotasFiscaisPage() {
  const [rows, setRows] = useState<InvoiceRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const [model, setModel] = useState('');
  const [status, setStatus] = useState('');
  const [customer, setCustomer] = useState('');
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [number, setNumber] = useState('');
  const [series, setSeries] = useState('');

  const load = useCallback(async () => {
    setError(null);
    try {
      const params = new URLSearchParams();
      if (model) params.set('model', model);
      if (status) params.set('status', status);
      if (startDate) params.set('startDate', startDate);
      if (endDate) params.set('endDate', endDate);
      if (number) params.set('number', number);
      if (series) params.set('series', series);
      params.set('page', String(page));
      const result = await api<{ data: InvoiceRow[]; total: number }>(`/fiscal/invoices?${params}`);
      setRows(
        customer
          ? result.data.filter((row) => row.customer?.name.toLowerCase().includes(customer.toLowerCase()))
          : result.data,
      );
      setTotal(result.total);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erro ao carregar notas');
    }
  }, [model, status, customer, startDate, endDate, number, series, page]);

  useEffect(() => {
    load();
  }, [load]);

  async function action(id: string, label: string, fn: () => Promise<void>) {
    setBusy(id + label);
    setError(null);
    setInfo(null);
    try {
      await fn();
    } catch (err) {
      setError(err instanceof Error ? err.message : `Falha em ${label}`);
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="page" style={{ maxWidth: 1400 }}>
      <h1>Notas Fiscais</h1>
      <p className="subtitle">NF-e (modelo 55) e NFC-e (modelo 65) emitidas pela empresa</p>

      {error && <div className="alert alert-error">{error}</div>}
      {info && <div className="alert alert-success">{info}</div>}

      <div className="card">
        <div className="form-grid">
          <div>
            <label>Modelo</label>
            <select value={model} onChange={(e) => setModel(e.target.value)}>
              <option value="">Todos</option>
              <option value="55">NF-e (55)</option>
              <option value="65">NFC-e (65)</option>
            </select>
          </div>
          <div>
            <label>Status</label>
            <select value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">Todos</option>
              {STATUS_OPTIONS.map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </div>
          <div>
            <label>Cliente</label>
            <input value={customer} onChange={(e) => setCustomer(e.target.value)} placeholder="Nome do cliente" />
          </div>
          <div>
            <label>Data inicial</label>
            <input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </div>
          <div>
            <label>Data final</label>
            <input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </div>
          <div>
            <label>Número</label>
            <input value={number} onChange={(e) => setNumber(e.target.value)} placeholder="Ex: 123" />
          </div>
          <div>
            <label>Série</label>
            <input value={series} onChange={(e) => setSeries(e.target.value)} placeholder="Ex: 1" />
          </div>
          <div style={{ display: 'flex', alignItems: 'flex-end' }}>
            <button className="btn" onClick={() => { setPage(1); load(); }}>Filtrar</button>
          </div>
        </div>
      </div>

      <div className="card">
        <table>
          <thead>
            <tr>
              <th>Modelo</th>
              <th>Série/Número</th>
              <th>Cliente</th>
              <th>Emissão</th>
              <th>Total</th>
              <th>Status</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr><td colSpan={7} className="muted">Nenhuma nota encontrada.</td></tr>
            )}
            {rows.map((row) => (
              <tr key={row.id}>
                <td>{row.model === 'NFE_55' ? 'NF-e' : 'NFC-e'}</td>
                <td>{row.series}/{row.number}</td>
                <td>{row.customer?.name ?? 'Consumidor'}</td>
                <td className="muted">{formatDateTime(row.issueDate)}</td>
                <td>{formatCurrency(Number(row.totalInvoice))}</td>
                <td>
                  <InvoiceStatusBadge status={row.status} />
                  {row.status === 'REJECTED' && row.rejectionCode && (
                    <div className="muted" style={{ marginTop: 4, maxWidth: 220 }}>
                      {row.rejectionCode}: {row.rejectionMessage}
                    </div>
                  )}
                </td>
                <td>
                  <div className="actions">
                    <button
                      className="btn btn-sm btn-secondary"
                      onClick={() => window.location.assign(`/fiscal/notas/${row.id}`)}
                    >
                      Visualizar
                    </button>
                    {row.accessKey && (
                      <button
                        className="btn btn-sm btn-secondary"
                        disabled={busy === row.id + 'xml'}
                        onClick={() =>
                          action(row.id, 'xml', async () => {
                            const result = await api<{ xmlBase64: string; fileName: string }>(`/fiscal/invoices/${row.id}/xml`);
                            downloadBase64(result.xmlBase64, result.fileName, 'application/xml');
                          })
                        }
                      >
                        Baixar XML
                      </button>
                    )}
                    {row.model === 'NFE_55' && row.status === 'AUTHORIZED' && (
                      <button
                        className="btn btn-sm btn-secondary"
                        disabled={busy === row.id + 'danfe'}
                        onClick={() =>
                          action(row.id, 'danfe', async () => {
                            const result = await api<{ pdfBase64: string }>(`/fiscal/invoices/${row.id}/danfe`);
                            openPdfBase64(result.pdfBase64);
                          })
                        }
                      >
                        DANFE
                      </button>
                    )}
                    {row.model === 'NFCE_65' && row.status === 'AUTHORIZED' && (
                      <button
                        className="btn btn-sm btn-secondary"
                        disabled={busy === row.id + 'danfce'}
                        onClick={() =>
                          action(row.id, 'danfce', async () => {
                            const result = await api<{ pdfBase64: string }>(`/fiscal/invoices/${row.id}/danfce`);
                            openPdfBase64(result.pdfBase64);
                          })
                        }
                      >
                        DANFCE
                      </button>
                    )}
                    {row.status === 'AUTHORIZED' && (
                      <button
                        className="btn btn-sm btn-danger"
                        disabled={busy === row.id + 'cancel'}
                        onClick={() =>
                          action(row.id, 'cancel', async () => {
                            const justification = window.prompt('Justificativa do cancelamento (mínimo 15 caracteres):');
                            if (!justification) return;
                            await api(`/fiscal/invoices/${row.id}/cancel`, { method: 'POST', body: { justification } });
                            setInfo(`Cancelamento solicitado para a nota ${row.series}/${row.number}.`);
                            await load();
                          })
                        }
                      >
                        Cancelar
                      </button>
                    )}
                    {row.model === 'NFE_55' && row.status === 'AUTHORIZED' && (
                      <button
                        className="btn btn-sm btn-secondary"
                        disabled={busy === row.id + 'cce'}
                        onClick={() =>
                          action(row.id, 'cce', async () => {
                            const text = window.prompt('Texto da carta de correção (mínimo 15 caracteres):');
                            if (!text) return;
                            await api(`/fiscal/invoices/${row.id}/correction-letter`, {
                              method: 'POST',
                              body: { correctionText: text },
                            });
                            setInfo('Carta de correção registrada.');
                            await load();
                          })
                        }
                      >
                        Carta correção
                      </button>
                    )}
                    {row.accessKey && (
                      <button
                        className="btn btn-sm btn-secondary"
                        disabled={busy === row.id + 'query'}
                        onClick={() =>
                          action(row.id, 'query', async () => {
                            const result = await api<{ sefazCode: string; sefazMessage: string }>(`/fiscal/invoices/${row.id}/query`);
                            setInfo(`SEFAZ: ${result.sefazCode} — ${result.sefazMessage}`);
                            await load();
                          })
                        }
                      >
                        Consultar SEFAZ
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 16 }}>
          <button className="btn btn-sm btn-secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Anterior
          </button>
          <span className="muted">Página {page} — {total} nota(s)</span>
          <button
            className="btn btn-sm btn-secondary"
            disabled={page * 20 >= total}
            onClick={() => setPage((p) => p + 1)}
          >
            Próxima
          </button>
        </div>
      </div>
    </div>
  );
}
