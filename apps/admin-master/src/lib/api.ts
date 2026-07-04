'use client';

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:3333/api/v1';

export function getToken(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem('integra_token');
}

export function setToken(token: string) {
  localStorage.setItem('integra_token', token);
}

export function clearToken() {
  localStorage.removeItem('integra_token');
}

export async function api<T = unknown>(
  path: string,
  options: { method?: string; body?: unknown } = {},
): Promise<T> {
  const response = await fetch(`${API_URL}${path}`, {
    method: options.method ?? 'GET',
    headers: {
      'Content-Type': 'application/json',
      ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {}),
    },
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  });

  const data = await response.json().catch(() => ({}));

  if (response.status === 401 && typeof window !== 'undefined' && !path.startsWith('/auth')) {
    clearToken();
    window.location.href = '/login';
  }

  if (!response.ok) {
    const message = (data as { message?: string | string[]; details?: string[] }).message;
    const details = (data as { details?: string[] }).details;
    throw new Error(
      [Array.isArray(message) ? message.join(', ') : message, details?.join('; ')].filter(Boolean).join(' — ') ||
        `Erro ${response.status}`,
    );
  }

  return data as T;
}

export function downloadBase64(base64: string, fileName: string, mime: string) {
  const bytes = atob(base64);
  const buffer = new Uint8Array(bytes.length);
  for (let i = 0; i < bytes.length; i++) buffer[i] = bytes.charCodeAt(i);
  const blob = new Blob([buffer], { type: mime });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.click();
  URL.revokeObjectURL(url);
}

export function openPdfBase64(base64: string) {
  const bytes = atob(base64);
  const buffer = new Uint8Array(bytes.length);
  for (let i = 0; i < bytes.length; i++) buffer[i] = bytes.charCodeAt(i);
  const blob = new Blob([buffer], { type: 'application/pdf' });
  window.open(URL.createObjectURL(blob), '_blank');
}
