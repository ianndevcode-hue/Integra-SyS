import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'Integra SYS — Web SaaS',
  description: 'ERP/PDV SaaS com módulo fiscal NF-e/NFC-e',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="pt-BR">
      <body>{children}</body>
    </html>
  );
}
