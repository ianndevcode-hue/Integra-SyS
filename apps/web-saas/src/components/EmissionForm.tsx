'use client';

import { useState } from 'react';
import { formatCurrency } from '@integra/shared';
import { api, openPdfBase64 } from '@/lib/api';

export interface EmissionItem {
  productId: string;
  code: string;
  description: string;
  ncm: string;
  cest?: string;
  cfop: string;
  unit: string;
  quantity: number;
  unitPrice: number;
  totalPrice: number;
  discount: number;
  icmsCst?: string;
  icmsCsosn?: string;
}

export interface EmissionPayment {
  method: string;
  amount: number;
}

const EMPTY_ITEM: EmissionItem = {
  productId: '',
  code: '',
  description: '',
  ncm: '',
  cfop: '5102',
  unit: 'UN',
  quantity: 1,
  unitPrice: 0,
  totalPrice: 0,
  discount: 0,
  icmsCsosn: '102',
};

const PAYMENT_METHODS = [
  ['01', 'Dinheiro'], ['02', 'Cheque'], ['03', 'Cartão de crédito'], ['04', 'Cartão de débito'],
  ['05', 'Crédito loja'], ['15', 'Boleto'], ['17', 'PIX'], ['99', 'Outros'],
] as const;

interface EmissionResult {
  result: {
    success: boolean;
    status: string;
    accessKey?: string;
    protocol?: string;
    rejectionCode?: string;
    rejectionMessage?: string;
    sefazCode?: string;
    sefazMessage?: string;
  };
  invoice: { id: string; series: number; number: number } | null;
}

export function EmissionForm({ model }: { model: '55' | '65' }) {
  const isNfe = model === '55';

  const [customerId, setCustomerId] = useState('');
  const [customerDocument, setCustomerDocument] = useState('');
  const [customerName, setCustomerName] = useState('');
  const [operationNature, setOperationNature] = useState('VENDA');
  const [items, setItems] = useState<EmissionItem[]>([{ ...EMPTY_ITEM }]);
  const [payments, setPayments] = useState<EmissionPayment[]>([{ method: '01', amount: 0 }]);
  const [additionalInformation, setAdditionalInformation] = useState('');

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<EmissionResult | null>(null);

  const totalItems = items.reduce((sum, item) => sum + item.totalPrice - item.discount, 0);
  const totalPayments = payments.reduce((sum, payment) => sum + payment.amount, 0);

  function updateItem(index: number, patch: Partial<EmissionItem>) {
    setItems((current) =>
      current.map((item, i) => {
        if (i !== index) return item;
        const updated = { ...item, ...patch };
        updated.totalPrice = Number((updated.quantity * updated.unitPrice).toFixed(2));
        return updated;
      }),
    );
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    setResult(null);

    try {
      const body = isNfe
        ? {
            customerId,
            operationNature,
            items,
            payments,
            additionalInformation: additionalInformation || undefined,
          }
        : {
            customerDocument: customerDocument || undefined,
            customerName: customerName || undefined,
            items,
            payments,
          };

      const response = await api<EmissionResult>(isNfe ? '/fiscal/nfe/emit' : '/fiscal/nfce/emit', {
        method: 'POST',
        body,
      });
      setResult(response);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha na emissão');
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      {error && <div className="alert alert-error">{error}</div>}

      {result && result.result.success && (
        <div className="alert alert-success">
          <strong>{isNfe ? 'NF-e' : 'NFC-e'} autorizada!</strong>
          <div className="mono" style={{ marginTop: 6 }}>Chave: {result.result.accessKey}</div>
          <div className="mono">Protocolo: {result.result.protocol}</div>
          {result.invoice && (
            <button
              type="button"
              className="btn btn-sm"
              style={{ marginTop: 8 }}
              onClick={async () => {
                const pdf = await api<{ pdfBase64: string }>(
                  `/fiscal/invoices/${result.invoice!.id}/${isNfe ? 'danfe' : 'danfce'}`,
                );
                openPdfBase64(pdf.pdfBase64);
              }}
            >
              Imprimir {isNfe ? 'DANFE' : 'DANFCE'}
            </button>
          )}
        </div>
      )}

      {result && !result.result.success && (
        <div className="alert alert-error">
          <strong>Nota rejeitada</strong>
          <div style={{ marginTop: 6 }}>
            {result.result.rejectionCode ?? result.result.sefazCode}: {result.result.rejectionMessage ?? result.result.sefazMessage}
          </div>
        </div>
      )}

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>{isNfe ? 'Destinatário' : 'Consumidor (opcional)'}</h3>
        <div className="form-grid">
          {isNfe ? (
            <>
              <div>
                <label>ID do cliente *</label>
                <input value={customerId} onChange={(e) => setCustomerId(e.target.value)} required placeholder="uuid do cliente" />
              </div>
              <div>
                <label>Natureza da operação *</label>
                <input value={operationNature} onChange={(e) => setOperationNature(e.target.value)} required />
              </div>
            </>
          ) : (
            <>
              <div>
                <label>CPF/CNPJ do consumidor</label>
                <input value={customerDocument} onChange={(e) => setCustomerDocument(e.target.value)} placeholder="Opcional até R$ 10.000" />
              </div>
              <div>
                <label>Nome do consumidor</label>
                <input value={customerName} onChange={(e) => setCustomerName(e.target.value)} />
              </div>
            </>
          )}
        </div>
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Itens</h3>
        {items.map((item, index) => (
          <div key={index} style={{ borderBottom: '1px solid #e5e7eb', paddingBottom: 14, marginBottom: 14 }}>
            <div className="form-grid">
              <div>
                <label>Código *</label>
                <input value={item.code} onChange={(e) => updateItem(index, { code: e.target.value, productId: e.target.value })} required />
              </div>
              <div style={{ gridColumn: 'span 2' }}>
                <label>Descrição *</label>
                <input value={item.description} onChange={(e) => updateItem(index, { description: e.target.value })} required />
              </div>
              <div>
                <label>NCM *</label>
                <input value={item.ncm} onChange={(e) => updateItem(index, { ncm: e.target.value })} required maxLength={8} placeholder="8 dígitos" />
              </div>
              <div>
                <label>CFOP *</label>
                <input value={item.cfop} onChange={(e) => updateItem(index, { cfop: e.target.value })} required maxLength={4} />
              </div>
              <div>
                <label>Unidade *</label>
                <input value={item.unit} onChange={(e) => updateItem(index, { unit: e.target.value })} required />
              </div>
              <div>
                <label>CSOSN/CST *</label>
                <input
                  value={item.icmsCsosn ?? item.icmsCst ?? ''}
                  onChange={(e) => {
                    const value = e.target.value;
                    updateItem(index, value.length >= 3 ? { icmsCsosn: value, icmsCst: undefined } : { icmsCst: value, icmsCsosn: undefined });
                  }}
                  required
                  placeholder="102 ou 00"
                />
              </div>
              <div>
                <label>Quantidade *</label>
                <input type="number" step="0.0001" min="0.0001" value={item.quantity} onChange={(e) => updateItem(index, { quantity: Number(e.target.value) })} required />
              </div>
              <div>
                <label>Valor unitário *</label>
                <input type="number" step="0.01" min="0" value={item.unitPrice} onChange={(e) => updateItem(index, { unitPrice: Number(e.target.value) })} required />
              </div>
              <div>
                <label>Desconto</label>
                <input type="number" step="0.01" min="0" value={item.discount} onChange={(e) => updateItem(index, { discount: Number(e.target.value) })} />
              </div>
              <div style={{ display: 'flex', alignItems: 'flex-end', gap: 8 }}>
                <span className="muted">Total: {formatCurrency(item.totalPrice - item.discount)}</span>
                {items.length > 1 && (
                  <button type="button" className="btn btn-sm btn-danger" onClick={() => setItems(items.filter((_, i) => i !== index))}>
                    Remover
                  </button>
                )}
              </div>
            </div>
          </div>
        ))}
        <button type="button" className="btn btn-secondary btn-sm" onClick={() => setItems([...items, { ...EMPTY_ITEM }])}>
          + Adicionar item
        </button>
      </div>

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>Pagamentos</h3>
        {payments.map((payment, index) => (
          <div key={index} className="form-grid" style={{ marginBottom: 10 }}>
            <div>
              <label>Forma de pagamento *</label>
              <select
                value={payment.method}
                onChange={(e) => setPayments(payments.map((p, i) => (i === index ? { ...p, method: e.target.value } : p)))}
              >
                {PAYMENT_METHODS.map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>
            <div>
              <label>Valor *</label>
              <input
                type="number"
                step="0.01"
                min="0"
                value={payment.amount}
                onChange={(e) => setPayments(payments.map((p, i) => (i === index ? { ...p, amount: Number(e.target.value) } : p)))}
                required
              />
            </div>
            <div style={{ display: 'flex', alignItems: 'flex-end' }}>
              {payments.length > 1 && (
                <button type="button" className="btn btn-sm btn-danger" onClick={() => setPayments(payments.filter((_, i) => i !== index))}>
                  Remover
                </button>
              )}
            </div>
          </div>
        ))}
        <button type="button" className="btn btn-secondary btn-sm" onClick={() => setPayments([...payments, { method: '01', amount: 0 }])}>
          + Adicionar pagamento
        </button>
        <p className="muted" style={{ marginTop: 12 }}>
          Total dos itens: <strong>{formatCurrency(totalItems)}</strong> — Total pago: <strong>{formatCurrency(totalPayments)}</strong>
        </p>
      </div>

      {isNfe && (
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Informações adicionais</h3>
          <textarea rows={3} value={additionalInformation} onChange={(e) => setAdditionalInformation(e.target.value)} />
        </div>
      )}

      <button className="btn" disabled={loading}>
        {loading ? 'Enviando para SEFAZ…' : `Emitir ${isNfe ? 'NF-e' : 'NFC-e'}`}
      </button>
    </form>
  );
}
