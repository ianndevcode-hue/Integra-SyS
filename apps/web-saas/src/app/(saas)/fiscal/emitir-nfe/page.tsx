'use client';

import { EmissionForm } from '@/components/EmissionForm';

export default function EmitirNfePage() {
  return (
    <div className="page">
      <h1>Emitir NF-e (modelo 55)</h1>
      <p className="subtitle">
        A nota é montada, assinada e enviada à SEFAZ pelo fiscal-service (NFePHP/sped-nfe).
      </p>
      <EmissionForm model="55" />
    </div>
  );
}
