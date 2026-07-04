import { z } from 'zod';
import { invoiceItemSchema, invoicePaymentSchema, documentSchema } from './fiscal.schemas';

/** Venda enviada pelo PDV para emissão de NFC-e. */
export const pdvNfceSchema = z.object({
  /** id local gerado no dispositivo para idempotência da sincronização offline */
  localSaleId: z.string().min(1),
  soldAt: z.string().datetime(),
  customerDocument: documentSchema.optional(),
  customerName: z.string().optional(),
  items: z.array(invoiceItemSchema).min(1, 'Venda precisa ter ao menos um item'),
  payments: z.array(invoicePaymentSchema).min(1, 'Venda precisa ter forma de pagamento'),
  discount: z.number().nonnegative().default(0),
});

export type PdvNfceInput = z.infer<typeof pdvNfceSchema>;
