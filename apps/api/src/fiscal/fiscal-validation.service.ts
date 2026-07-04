import { Injectable, UnprocessableEntityException } from '@nestjs/common';
import { isValidCpfOrCnpj } from '@integra/shared';
import type { EmitNfceInput, EmitNfeInput } from '@integra/validation';

/**
 * Validações fiscais pré-emissão (regras de negócio).
 * As validações estruturais (schema Zod) acontecem antes, no controller.
 */
@Injectable()
export class FiscalValidationService {
  validateItems(items: EmitNfeInput['items']): void {
    const problems: string[] = [];

    items.forEach((item, index) => {
      const label = `Item ${index + 1} (${item.description})`;
      if (!item.ncm) problems.push(`${label}: produto precisa ter NCM`);
      if (!item.cfop) problems.push(`${label}: produto precisa ter CFOP`);
      if (!item.unit) problems.push(`${label}: produto precisa ter unidade`);
      if (!item.icmsCst && !item.icmsCsosn) problems.push(`${label}: produto precisa ter CST ou CSOSN`);
      if (item.quantity <= 0) problems.push(`${label}: quantidade inválida`);
    });

    if (problems.length > 0) {
      throw new UnprocessableEntityException({ message: 'Produtos com pendências fiscais', details: problems });
    }
  }

  validatePayments(payments: EmitNfeInput['payments'], totalInvoice: number): void {
    if (!payments.length) {
      throw new UnprocessableEntityException('Venda precisa ter forma de pagamento');
    }

    const totalPaid = payments.reduce((sum, p) => sum + p.amount, 0);
    if (totalPaid + 0.009 < totalInvoice) {
      throw new UnprocessableEntityException(
        `Pagamentos (R$ ${totalPaid.toFixed(2)}) insuficientes para o total da nota (R$ ${totalInvoice.toFixed(2)})`,
      );
    }
  }

  validateNfeRecipient(document: string | null | undefined): void {
    if (!document || !isValidCpfOrCnpj(document)) {
      throw new UnprocessableEntityException('NF-e exige cliente com CPF/CNPJ válido');
    }
  }

  validateNfceRecipientDocument(document: string | null | undefined, totalInvoice: number): void {
    // NFC-e acima de R$ 10.000 exige identificação do consumidor
    if (totalInvoice >= 10000 && !document) {
      throw new UnprocessableEntityException('NFC-e com valor igual ou superior a R$ 10.000,00 exige CPF/CNPJ do consumidor');
    }
    if (document && !isValidCpfOrCnpj(document)) {
      throw new UnprocessableEntityException('CPF/CNPJ do consumidor inválido');
    }
  }

  computeTotals(input: EmitNfeInput | EmitNfceInput): { products: number; discount: number; invoice: number } {
    const products = input.items.reduce((sum, item) => sum + item.totalPrice, 0);
    const discount = input.items.reduce((sum, item) => sum + (item.discount ?? 0), 0);
    return { products, discount, invoice: products - discount };
  }
}
