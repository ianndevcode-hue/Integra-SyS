import { Injectable, NotFoundException } from '@nestjs/common';
import type { JwtPayload } from '@integra/types';
import { PrismaService } from '../core/prisma.service';
import { ErpContextService } from './erp-context.service';

@Injectable()
export class SettingsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly context: ErpContextService,
  ) {}

  async company(user: JwtPayload) {
    const { companyId } = this.context.requireCompany(user);
    const company = await this.prisma.company.findUnique({
      where: { id: companyId },
      include: { branches: { where: { active: true } } },
    });
    if (!company) throw new NotFoundException('Empresa não encontrada');
    return company;
  }

  async users(user: JwtPayload) {
    const { tenantId } = this.context.requireCompany(user);
    return this.prisma.user.findMany({
      where: { tenantId },
      select: {
        id: true,
        name: true,
        email: true,
        role: true,
        active: true,
        permissions: true,
        createdAt: true,
      },
      orderBy: { name: 'asc' },
    });
  }
}
