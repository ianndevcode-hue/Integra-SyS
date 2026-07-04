import { Injectable, UnprocessableEntityException } from '@nestjs/common';
import type { JwtPayload } from '@integra/types';
import { PrismaService } from '../core/prisma.service';

@Injectable()
export class ErpContextService {
  constructor(private readonly prisma: PrismaService) {}

  requireCompany(user: JwtPayload) {
    if (!user.tenantId || !user.companyId) {
      throw new UnprocessableEntityException('Usuário sem empresa associada');
    }
    return { tenantId: user.tenantId, companyId: user.companyId };
  }

  async getLicenseModules(tenantId: string) {
    const license = await this.prisma.license.findFirst({
      where: { tenantId, status: { in: ['ACTIVE', 'TRIAL'] } },
      orderBy: { createdAt: 'desc' },
    });
    if (!license) return null;
    return {
      plan: license.plan,
      status: license.status,
      modules: {
        fiscal: license.fiscalEnabled,
        pdv: license.pdvEnabled,
        inventory: license.inventoryEnabled,
        reports: license.reportsEnabled,
      },
    };
  }
}
