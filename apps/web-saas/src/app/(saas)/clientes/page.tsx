'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import type { CustomerDto } from '@integra/types';

const EMPTY = {
  name: '',
  document: '',
  email: '',
  phone: '',
  cityName: '',
  uf: 'SP',
};

export default function ClientesPage() {
  const [customers, setCustomers] = useState<CustomerDto[]>([]);
  const [form, setForm] = useState(EMPTY);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [error, setError] = useState<string | null>(null);

  async function load() {
    const data = await api<CustomerDto[]>(`/erp/customers${search ? `?search=${encodeURIComponent(search)}` : ''}`);
    setCustomers(data);
  }

  useEffect(() => {
    load().catch((err) => setError(err.message));
  }, [search]);

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    try {
      if (editingId) {
        await api(`/erp/customers/${editingId}`, { method: 'PATCH', body: form });
      } else {
        await api('/erp/customers', { method: 'POST', body: form });
      }
      setForm(EMPTY);
      setEditingId(null);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erro ao salvar');
    }
  }

  async function remove(id: string) {
    if (!confirm('Excluir este cliente?')) return;
    await api(`/erp/customers/${id}`, { method: 'DELETE' });
    await load();
  }

  return (
    <div className="page">
      <h1>Clientes</h1>
      <p className="subtitle">Cadastro de clientes para vendas e emissão de NF-e</p>
      {error && <div className="alert alert-error">{error}</div>}

      <div className="card">
        <h3 style={{ marginBottom: 12 }}>{editingId ? 'Editar cliente' : 'Novo cliente'}</h3>
        <form onSubmit={save}>
          <div className="form-grid">
            <div className="field"><label>Nome</label><input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></div>
            <div className="field"><label>CPF/CNPJ</label><input value={form.document} onChange={(e) => setForm({ ...form, document: e.target.value })} /></div>
            <div className="field"><label>E-mail</label><input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></div>
            <div className="field"><label>Telefone</label><input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></div>
            <div className="field"><label>Cidade</label><input value={form.cityName} onChange={(e) => setForm({ ...form, cityName: e.target.value })} /></div>
            <div className="field"><label>UF</label><input value={form.uf} maxLength={2} onChange={(e) => setForm({ ...form, uf: e.target.value.toUpperCase() })} /></div>
          </div>
          <button className="btn">{editingId ? 'Atualizar' : 'Cadastrar'}</button>
        </form>
      </div>

      <div className="card">
        <div className="field" style={{ maxWidth: 320 }}>
          <label>Buscar</label>
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nome ou documento" />
        </div>
        <table>
          <thead>
            <tr><th>Nome</th><th>Documento</th><th>Cidade/UF</th><th>Contato</th><th></th></tr>
          </thead>
          <tbody>
            {customers.map((customer) => (
              <tr key={customer.id}>
                <td>{customer.name}</td>
                <td>{customer.document ?? '—'}</td>
                <td>{[customer.cityName, customer.uf].filter(Boolean).join('/') || '—'}</td>
                <td>{customer.email ?? customer.phone ?? '—'}</td>
                <td>
                  <div className="actions">
                    <button className="btn btn-sm btn-secondary" onClick={() => { setEditingId(customer.id); setForm({ name: customer.name, document: customer.document ?? '', email: customer.email ?? '', phone: customer.phone ?? '', cityName: customer.cityName ?? '', uf: customer.uf ?? 'SP' }); }}>Editar</button>
                    <button className="btn btn-sm btn-danger" onClick={() => remove(customer.id)}>Excluir</button>
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
