import { Injectable, NotFoundException } from '@nestjs/common';
import type { JwtPayload, SaleDto } from '@integra/types';
import { PrismaService } from '../core/prisma.service';
import { ErpContextService } from './erp-context.service';

@Injectable()
export class SalesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly context: ErpContextService,
  ) {}

  async list(user: JwtPayload): Promise<SaleDto[]> {
    const { companyId } = this.context.requireCompany(user);
    const sales = await this.prisma.sale.findMany({
      where: { companyId },
      include: {
        customer: { select: { name: true } },
        invoices: { orderBy: { createdAt: 'desc' }, take: 1, select: { status: true, series: true, number: true } },
      },
      orderBy: { soldAt: 'desc' },
      take: 200,
    });

    return sales.map((sale) => {
      const invoice = sale.invoices[0];
      return {
        id: sale.id,
        localSaleId: sale.localSaleId,
        status: sale.status,
        total: Number(sale.total),
        discount: Number(sale.discount),
        soldAt: sale.soldAt.toISOString(),
        customerName: sale.customer?.name ?? null,
        invoiceStatus: invoice?.status ?? null,
        invoiceNumber: invoice ? `${invoice.series}/${invoice.number}` : null,
      };
    });
  }

  async get(user: JwtPayload, id: string) {
    const { companyId } = this.context.requireCompany(user);
    const sale = await this.prisma.sale.findFirst({
      where: { id, companyId },
      include: {
        customer: true,
        invoices: { orderBy: { createdAt: 'desc' }, include: { items: true, payments: true } },
      },
    });
    if (!sale) throw new NotFoundException('Venda não encontrada');
    return {
      ...sale,
      total: Number(sale.total),
      discount: Number(sale.discount),
      soldAt: sale.soldAt.toISOString(),
      invoices: sale.invoices.map((invoice) => ({
        ...invoice,
        totalInvoice: Number(invoice.totalInvoice),
        items: invoice.items.map((item) => ({
          ...item,
          quantity: Number(item.quantity),
          unitPrice: Number(item.unitPrice),
          totalPrice: Number(item.totalPrice),
        })),
        payments: invoice.payments.map((payment) => ({
          ...payment,
          amount: Number(payment.amount),
        })),
      })),
    };
  }
}
