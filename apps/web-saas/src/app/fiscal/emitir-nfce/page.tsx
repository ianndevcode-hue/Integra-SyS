'use client';

import { EmissionForm } from '@/components/EmissionForm';

export default function EmitirNfcePage() {
  return (
    <div className="page">
      <h1>Emitir NFC-e (modelo 65)</h1>
      <p className="subtitle">
        Exige CSC/Token configurado. QRCode e envio à SEFAZ são feitos pelo fiscal-service.
      </p>
      <EmissionForm model="65" />
    </div>
  );
}
