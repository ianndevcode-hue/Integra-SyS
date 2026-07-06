import { Injectable } from '@nestjs/common';
import type { JwtPayload, ReportsSummaryDto } from '@integra/types';
import { PrismaService } from '../core/prisma.service';
import { ErpContextService } from './erp-context.service';

const PAYMENT_LABELS: Record<string, string> = {
  '01': 'Dinheiro',
  '03': 'Crédito',
  '04': 'Débito',
  '17': 'PIX',
};

@Injectable()
export class ReportsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly context: ErpContextService,
  ) {}

  async summary(user: JwtPayload): Promise<ReportsSummaryDto> {
    const { companyId } = this.context.requireCompany(user);
    const since = new Date();
    since.setDate(since.getDate() - 30);

    const [sales, invoiceItems, payments] = await Promise.all([
      this.prisma.sale.findMany({
        where: { companyId, soldAt: { gte: since }, status: 'FINISHED' },
        select: { soldAt: true, total: true },
      }),
      this.prisma.invoiceItem.findMany({
        where: { invoice: { companyId, status: 'AUTHORIZED', issueDate: { gte: since } } },
        select: { code: true, description: true, totalPrice: true },
      }),
      this.prisma.invoicePayment.findMany({
        where: { invoice: { companyId, status: 'AUTHORIZED', issueDate: { gte: since } } },
        select: { paymentMethod: true, amount: true },
      }),
    ]);

    const byDay = new Map<string, { total: number; count: number }>();
    for (const sale of sales) {
      const date = sale.soldAt.toISOString().slice(0, 10);
      const current = byDay.get(date) ?? { total: 0, count: 0 };
      current.total += Number(sale.total);
      current.count += 1;
      byDay.set(date, current);
    }

    const productRevenue = new Map<string, { code: string; description: string; revenue: number }>();
    for (const item of invoiceItems) {
      const key = item.code;
      const current = productRevenue.get(key) ?? { code: item.code, description: item.description, revenue: 0 };
      current.revenue += Number(item.totalPrice);
      productRevenue.set(key, current);
    }

    const paymentMix = new Map<string, number>();
    for (const payment of payments) {
      const label = PAYMENT_LABELS[payment.paymentMethod] ?? payment.paymentMethod;
      paymentMix.set(label, (paymentMix.get(label) ?? 0) + Number(payment.amount));
    }

    return {
      salesByDay: [...byDay.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([date, value]) => ({ date, ...value })),
      topProducts: [...productRevenue.values()].sort((a, b) => b.revenue - a.revenue).slice(0, 10),
      paymentMix: [...paymentMix.entries()].map(([method, amount]) => ({ method, amount })),
    };
  }
}
