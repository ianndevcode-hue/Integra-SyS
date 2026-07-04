'use client';

import { useEffect, useState } from 'react';
import { formatCurrency } from '@integra/shared';
import { api } from '@/lib/api';
import type { ProductDto } from '@integra/types';

interface CartItem {
  product: ProductDto;
  quantity: number;
}

const PAYMENT_METHODS = [
  ['01', 'Dinheiro'],
  ['03', 'Crédito'],
  ['04', 'Débito'],
  ['17', 'PIX'],
] as const;

export default function PdvPage() {
  const [products, setProducts] = useState<ProductDto[]>([]);
  const [cart, setCart] = useState<CartItem[]>([]);
  const [paymentMethod, setPaymentMethod] = useState('01');
  const [customerDocument, setCustomerDocument] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<ProductDto[]>('/erp/products')
      .then(setProducts)
      .catch((err) => setError(err.message));
  }, []);

  const total = cart.reduce((sum, item) => sum + item.product.price * item.quantity, 0);

  function addToCart(product: ProductDto) {
    setCart((current) => {
      const existing = current.find((item) => item.product.id === product.id);
      if (existing) {
        return current.map((item) =>
          item.product.id === product.id ? { ...item, quantity: item.quantity + 1 } : item,
        );
      }
      return [...current, { product, quantity: 1 }];
    });
  }

  async function finishSale() {
    if (cart.length === 0) return;
    setLoading(true);
    setError(null);
    setMessage(null);
    try {
      const body = {
        localSaleId: `web-pdv-${Date.now()}`,
        soldAt: new Date().toISOString(),
        customerDocument: customerDocument || undefined,
        items: cart.map((item) => ({
          productId: item.product.id,
          code: item.product.code,
          description: item.product.description,
          ncm: item.product.ncm ?? '00000000',
          cfop: item.product.cfop ?? '5102',
          unit: item.product.unit,
          quantity: item.quantity,
          unitPrice: item.product.price,
          totalPrice: Number((item.product.price * item.quantity).toFixed(2)),
          discount: 0,
          icmsCsosn: item.product.icmsCsosn ?? '102',
        })),
        payments: [{ method: paymentMethod, amount: Number(total.toFixed(2)) }],
        discount: 0,
      };
      const result = await api<{ invoice: { status: string; series: number; number: number } | null }>(
        '/pdv/invoices/nfce',
        { method: 'POST', body },
      );
      setCart([]);
      setCustomerDocument('');
      setMessage(
        result.invoice
          ? `Venda finalizada — NFC-e ${result.invoice.series}/${result.invoice.number} (${result.invoice.status})`
          : 'Venda registrada.',
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Falha na venda');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="page">
      <h1>PDV Web</h1>
      <p className="subtitle">Ponto de venda no navegador com emissão de NFC-e</p>
      {error && <div className="alert alert-error">{error}</div>}
      {message && <div className="alert alert-success">{message}</div>}

      <div className="grid grid-2">
        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Produtos</h3>
          <div className="actions" style={{ flexWrap: 'wrap' }}>
            {products.map((product) => (
              <button key={product.id} className="btn btn-secondary" onClick={() => addToCart(product)}>
                {product.description} — {formatCurrency(product.price)}
              </button>
            ))}
          </div>
        </div>

        <div className="card">
          <h3 style={{ marginBottom: 12 }}>Carrinho</h3>
          {cart.length === 0 ? (
            <p className="muted">Nenhum item.</p>
          ) : (
            <table>
              <tbody>
                {cart.map((item) => (
                  <tr key={item.product.id}>
                    <td>{item.quantity}x {item.product.description}</td>
                    <td>{formatCurrency(item.product.price * item.quantity)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          <div className="field" style={{ marginTop: 12 }}>
            <label>Forma de pagamento</label>
            <select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
              {PAYMENT_METHODS.map(([code, label]) => (
                <option key={code} value={code}>{label}</option>
              ))}
            </select>
          </div>
          <div className="field">
            <label>CPF/CNPJ do consumidor (opcional)</label>
            <input value={customerDocument} onChange={(e) => setCustomerDocument(e.target.value)} />
          </div>
          <div style={{ fontSize: 24, fontWeight: 800, margin: '12px 0' }}>Total: {formatCurrency(total)}</div>
          <button className="btn" disabled={loading || cart.length === 0} onClick={finishSale}>
            {loading ? 'Processando…' : 'Finalizar e emitir NFC-e'}
          </button>
        </div>
      </div>
    </div>
  );
}
