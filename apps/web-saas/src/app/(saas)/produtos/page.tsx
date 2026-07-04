'use client';

import { useEffect, useState } from 'react';
import { formatCurrency } from '@integra/shared';
import { api } from '@/lib/api';
import type { ProductDto } from '@integra/types';

const EMPTY = {
  code: '',
  description: '',
  ncm: '',
  cfop: '5102',
  unit: 'UN',
  price: 0,
  stockQuantity: 0,
  icmsCsosn: '102',
};

export default function ProdutosPage() {
  const [products, setProducts] = useState<ProductDto[]>([]);
  const [form, setForm] = useState(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function load() {
    const data = await api<ProductDto[]>(`/erp/products${search ? `?search=${encodeURIComponent(search)}` : ''}`);
    setProducts(data);
  }

  useEffect(() => {
    load().catch((err) => setError(err.message));
  }, [search]);

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    try {
      if (editingId) {
        await api(`/erp/products/${editingId}`, { method: 'PATCH', body: form });
      } else {
        await api('/erp/products', { method: 'POST', body: form });
      }
      setForm(EMPTY);
      setEditingId(null);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erro ao salvar');
    } finally {
      setLoading(false);
    }
  }

  async function remove(id: string) {
    if (!confirm('Desativar este produto?')) return;
    await api(`/erp/products/${id}`, { method: 'DELETE' });
    await load();
  }

  return (
    <div className="page">
      <h1>Produtos</h1>
      <p className="subtitle">Cadastro de produtos com dados fiscais para NF-e e NFC-e</p>
      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>{editingId ? 'Editar produto' : 'Novo produto'}</h3>
        <form onSubmit={save}>
          <div className="form-grid">
            <div className="field"><label>Código</label><input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} required /></div>
            <div className="field"><label>Descrição</label><input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} required /></div>
            <div className="field"><label>NCM</label><input value={form.ncm} onChange={(e) => setForm({ ...form, ncm: e.target.value })} /></div>
            <div className="field"><label>CFOP</label><input value={form.cfop} onChange={(e) => setForm({ ...form, cfop: e.target.value })} /></div>
            <div className="field"><label>Unidade</label><input value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} /></div>
            <div className="field"><label>Preço</label><input type="number" step="0.01" value={form.price} onChange={(e) => setForm({ ...form, price: Number(e.target.value) })} /></div>
            <div className="field"><label>Estoque</label><input type="number" step="0.0001" value={form.stockQuantity} onChange={(e) => setForm({ ...form, stockQuantity: Number(e.target.value) })} /></div>
            <div className="field"><label>CSOSN</label><input value={form.icmsCsosn} onChange={(e) => setForm({ ...form, icmsCsosn: e.target.value })} /></div>
          </div>
          <div className="actions">
            <button className="btn" disabled={loading}>{loading ? 'Salvando…' : editingId ? 'Atualizar' : 'Cadastrar'}</button>
            {editingId && (
              <button type="button" className="btn btn-secondary" onClick={() => { setEditingId(null); setForm(EMPTY); }}>
                Cancelar
              </button>
            )}
          </div>
        </form>
      </div>

      <div className="card">
        <div className="field" style={{ maxWidth: 320 }}>
          <label>Buscar</label>
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Código ou descrição" />
        </div>
        <table>
          <thead>
            <tr><th>Código</th><th>Descrição</th><th>NCM</th><th>Preço</th><th>Estoque</th><th></th></tr>
          </thead>
          <tbody>
            {products.map((product) => (
              <tr key={product.id}>
                <td className="mono">{product.code}</td>
                <td>{product.description}</td>
                <td>{product.ncm ?? '—'}</td>
                <td>{formatCurrency(product.price)}</td>
                <td>{product.stockQuantity}</td>
                <td>
                  <div className="actions">
                    <button className="btn btn-sm btn-secondary" onClick={() => { setEditingId(product.id); setForm({ code: product.code, description: product.description, ncm: product.ncm ?? '', cfop: product.cfop ?? '5102', unit: product.unit, price: product.price, stockQuantity: product.stockQuantity, icmsCsosn: product.icmsCsosn ?? '102' }); }}>Editar</button>
                    <button className="btn btn-sm btn-danger" onClick={() => remove(product.id)}>Desativar</button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
