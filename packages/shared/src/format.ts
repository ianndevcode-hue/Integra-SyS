/** Formatação de valores para exibição. */

export function formatCurrency(value: number): string {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
}

export function formatDateTime(value: string | Date): string {
  const date = typeof value === 'string' ? new Date(value) : value;
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(date);
}

export function formatDate(value: string | Date): string {
  const date = typeof value === 'string' ? new Date(value) : value;
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(date);
}

/** Formata chave de acesso NF-e/NFC-e em grupos de 4 dígitos. */
export function formatAccessKey(accessKey: string): string {
  return accessKey.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
}
