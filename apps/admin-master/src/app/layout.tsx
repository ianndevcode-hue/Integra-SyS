import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'Integra SYS — Admin Master',
  description: 'Administração global do Integra SYS: suporte, diagnóstico e auditoria fiscal',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="pt-BR">
      <body>{children}</body>
    </html>
  );
}
