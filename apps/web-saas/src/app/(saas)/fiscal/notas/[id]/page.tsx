'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { InvoiceStatusBadge } from '@integra/ui';
import type { InvoiceStatus } from '@integra/types';
import { formatAccessKey, formatCurrency, formatDateTime } from '@integra/shared';
import { api } from '@/lib/api';

interface InvoiceDetail {
  id: string;
  model: 'NFE_55' | 'NFCE_65';
  series: number;
  number: number;
  status: InvoiceStatus;
  environment: string;
  operationNature: string;
  accessKey: string | null;
  protocol: string | null;
  issueDate: string;
  authorizedAt: string | null;
  canceledAt: string | null;
  totalProducts: string;
  totalDiscount: string;
  totalInvoice: string;
  rejectionCode: string | null;
  rejectionMessage: string | null;
  customer: { name: string; document: string | null } | null;
  items: Array<{
    id: string;
    code: string;
    description: string;
    ncm: string;
    cfop: string;
    unit: string;
    quantity: string;
    unitPrice: string;
    totalPrice: string;
  }>;
  payments: Array<{ id: string; paymentMethod: string; amount: string }>;
  events: Array<{
    id: string;
    eventType: string;
    sequence: number;
    protocol: string | null;
    justification: string | null;
    correctionText: string | null;
    status: string;
    createdAt: string;
  }>;
}

const PAYMENT_LABELS: Record<string, string> = {
  '01': 'Dinheiro', '02': 'Cheque', '03': 'Cartão de crédito', '04': 'Cartão de débito',
  '05': 'Crédito loja', '15': 'Boleto', '17': 'PIX', '90': 'Sem pagamento', '99': 'Outros',
};

export default function InvoiceDetailPage() {
  const params = useParams<{ id: string }>();
  const [invoice, setInvoice] = useState<InvoiceDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (params?.id) {
      api<InvoiceDetail>(`/fiscal/invoices/${params.id}`)
        .then(setInvoice)
        .catch((err) => setError(err.message));
    }
  }, [params?.id]);

  if (error) return <div className="page"><div className="alert alert-error">{error}</div></div>;
  if (!invoice) return <div className="page"><p className="muted">Carregando…</p></div>;

  return (
    <div className="page">
      <h1>
        {invoice.model === 'NFE_55' ? 'NF-e' : 'NFC-e'} {invoice.series}/{invoice.number}{' '}
        <InvoiceStatusBadge status={invoice.status} />
      </h1>
      <p className="subtitle">{invoice.operationNature} — {invoice.environment === 'PRODUCTION' ? 'Produção' : 'Homologação'}</p>

      {invoice.rejectionCode && (
        <div className="alert alert-error">
          Rejeição {invoice.rejectionCode}: {invoice.rejectionMessage}
        </div>
      )}

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Dados gerais</h3>
        <table>
          <tbody>
            <tr><td>Cliente</td><td>{invoice.customer?.name ?? 'Consumidor não identificado'}</td></tr>
            <tr><td>Chave de acesso</td><td className="mono">{invoice.accessKey ? formatAccessKey(invoice.accessKey) : '—'}</td></tr>
            <tr><td>Protocolo</td><td className="mono">{invoice.protocol ?? '—'}</td></tr>
            <tr><td>Emissão</td><td>{formatDateTime(invoice.issueDate)}</td></tr>
            <tr><td>Autorizada em</td><td>{invoice.authorizedAt ? formatDateTime(invoice.authorizedAt) : '—'}</td></tr>
            {invoice.canceledAt && <tr><td>Cancelada em</td><td>{formatDateTime(invoice.canceledAt)}</td></tr>}
            <tr><td>Total produtos</td><td>{formatCurrency(Number(invoice.totalProducts))}</td></tr>
            <tr><td>Desconto</td><td>{formatCurrency(Number(invoice.totalDiscount))}</td></tr>
            <tr><td><strong>Total da nota</strong></td><td><strong>{formatCurrency(Number(invoice.totalInvoice))}</strong></td></tr>
          </tbody>
        </table>
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Itens</h3>
        <table>
          <thead>
            <tr><th>Código</th><th>Descrição</th><th>NCM</th><th>CFOP</th><th>Un</th><th>Qtd</th><th>Vl. unit</th><th>Total</th></tr>
          </thead>
          <tbody>
            {invoice.items.map((item) => (
              <tr key={item.id}>
                <td className="mono">{item.code}</td>
                <td>{item.description}</td>
                <td className="mono">{item.ncm}</td>
                <td className="mono">{item.cfop}</td>
                <td>{item.unit}</td>
                <td>{Number(item.quantity)}</td>
                <td>{formatCurrency(Number(item.unitPrice))}</td>
                <td>{formatCurrency(Number(item.totalPrice))}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Pagamentos</h3>
        <table>
          <thead><tr><th>Forma</th><th>Valor</th></tr></thead>
          <tbody>
            {invoice.payments.map((payment) => (
              <tr key={payment.id}>
                <td>{PAYMENT_LABELS[payment.paymentMethod] ?? payment.paymentMethod}</td>
                <td>{formatCurrency(Number(payment.amount))}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Eventos fiscais</h3>
        {invoice.events.length === 0 ? (
          <p className="muted">Nenhum evento registrado.</p>
        ) : (
          <table>
            <thead><tr><th>Evento</th><th>Seq.</th><th>Protocolo</th><th>Detalhe</th><th>Status</th><th>Data</th></tr></thead>
            <tbody>
              {invoice.events.map((event) => (
                <tr key={event.id}>
                  <td>{event.eventType}</td>
                  <td>{event.sequence}</td>
                  <td className="mono">{event.protocol ?? '—'}</td>
                  <td>{event.justification ?? event.correctionText ?? '—'}</td>
                  <td>{event.status}</td>
                  <td className="muted">{formatDateTime(event.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
