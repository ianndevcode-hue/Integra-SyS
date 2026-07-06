import { Injectable } from '@nestjs/common';
import type { ErpDashboardDto, JwtPayload } from '@integra/types';
import { PrismaService } from '../core/prisma.service';
import { ErpContextService } from './erp-context.service';

@Injectable()
export class ErpDashboardService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly context: ErpContextService,
  ) {}

  async dashboard(user: JwtPayload): Promise<ErpDashboardDto> {
    const { tenantId, companyId } = this.context.requireCompany(user);
    const monthStart = new Date();
    monthStart.setDate(1);
    monthStart.setHours(0, 0, 0, 0);

    const [company, products, customers, salesThisMonth, salesAgg, lowStock, pendingInvoices, license] =
      await Promise.all([
        this.prisma.company.findUnique({ where: { id: companyId }, select: { tradeName: true, corporateName: true } }),
        this.prisma.product.count({ where: { companyId, active: true } }),
        this.prisma.customer.count({ where: { companyId } }),
        this.prisma.sale.count({ where: { companyId, soldAt: { gte: monthStart }, status: 'FINISHED' } }),
        this.prisma.sale.aggregate({
          where: { companyId, soldAt: { gte: monthStart }, status: 'FINISHED' },
          _sum: { total: true },
        }),
        this.prisma.product.count({ where: { companyId, active: true, stockQuantity: { lte: 5 } } }),
        this.prisma.invoice.count({
          where: { companyId, status: { in: ['PENDING', 'SENDING', 'DRAFT'] } },
        }),
        this.context.getLicenseModules(tenantId),
      ]);

    return {
      products,
      customers,
      salesThisMonth,
      revenueThisMonth: Number(salesAgg._sum.total ?? 0),
      lowStockProducts: lowStock,
      pendingInvoices,
      companyName: company?.tradeName ?? company?.corporateName ?? null,
      license,
    };
  }
}
