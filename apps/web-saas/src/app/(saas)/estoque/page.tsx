'use client';

import { useEffect, useState } from 'react';
import { formatCurrency } from '@integra/shared';
import { api } from '@/lib/api';
import type { ProductDto } from '@integra/types';

export default function EstoquePage() {
  const [products, setProducts] = useState<ProductDto[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [adjustments, setAdjustments] = useState<Record<string, string>>({});

  async function load() {
    const data = await api<ProductDto[]>('/erp/products');
    setProducts(data);
  }

  useEffect(() => {
    load().catch((err) => setError(err.message));
  }, []);

  async function adjust(id: string) {
    const quantity = Number(adjustments[id]);
    if (!quantity) return;
    await api(`/erp/products/${id}/stock`, { method: 'POST', body: { quantity, reason: 'Ajuste manual' } });
    setAdjustments((current) => ({ ...current, [id]: '' }));
    await load();
  }

  const lowStock = products.filter((product) => product.stockQuantity <= 5);

  return (
    <div className="page">
      <h1>Estoque</h1>
      <p className="subtitle">Controle de saldo por produto</p>
      {error && <div className="alert alert-error">{error}</div>}

      {lowStock.length > 0 && (
        <div className="alert alert-warning">
          {lowStock.length} produto(s) com estoque baixo (≤ 5 unidades).
        </div>
      )}

      <div className="card">
        <table>
          <thead>
            <tr><th>Código</th><th>Produto</th><th>Saldo</th><th>Preço</th><th>Ajuste (+/-)</th><th></th></tr>
          </thead>
          <tbody>
            {products.map((product) => (
              <tr key={product.id} style={product.stockQuantity <= 5 ? { background: '#fffbeb' } : undefined}>
                <td className="mono">{product.code}</td>
                <td>{product.description}</td>
                <td><strong>{product.stockQuantity}</strong></td>
                <td>{formatCurrency(product.price)}</td>
                <td>
                  <input
                    type="number"
                    step="1"
                    style={{ maxWidth: 120 }}
                    value={adjustments[product.id] ?? ''}
                    onChange={(e) => setAdjustments({ ...adjustments, [product.id]: e.target.value })}
                    placeholder="Ex: 10 ou -2"
                  />
                </td>
                <td>
                  <button className="btn btn-sm" onClick={() => adjust(product.id)}>Aplicar</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
