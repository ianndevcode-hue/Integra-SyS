import { Injectable, UnauthorizedException } from '@nestjs/common';
import { signAccessToken, verifyAccessToken, verifyPassword } from '@integra/auth';
import { getApiConfig } from '@integra/config';
import type { AuthUser, JwtPayload } from '@integra/types';
import { PrismaService } from '../core/prisma.service';

@Injectable()
export class AuthService {
  private readonly config = getApiConfig();

  constructor(private readonly prisma: PrismaService) {}

  async login(email: string, password: string): Promise<{ accessToken: string; user: AuthUser }> {
    const user = await this.prisma.user.findUnique({ where: { email } });
    if (!user || !user.active || !verifyPassword(password, user.passwordHash)) {
      throw new UnauthorizedException('Credenciais inválidas');
    }

    const payload: JwtPayload = {
      sub: user.id,
      tenantId: user.tenantId,
      companyId: user.companyId,
      branchId: user.branchId,
      role: user.role,
      permissions: user.permissions,
    };

    return {
      accessToken: signAccessToken(payload, this.config.jwtSecret, this.config.jwtExpiresIn),
      user: {
        id: user.id,
        tenantId: user.tenantId,
        companyId: user.companyId,
        branchId: user.branchId,
        name: user.name,
        email: user.email,
        role: user.role,
        permissions: user.permissions,
      },
    };
  }

  verifyToken(token: string): JwtPayload {
    try {
      return verifyAccessToken(token, this.config.jwtSecret);
    } catch {
      throw new UnauthorizedException('Token inválido ou expirado');
    }
  }

  async profile(payload: JwtPayload) {
    const user = await this.prisma.user.findUnique({
      where: { id: payload.sub },
      select: {
        id: true,
        tenantId: true,
        companyId: true,
        branchId: true,
        name: true,
        email: true,
        role: true,
        permissions: true,
      },
    });
    if (!user) {
      throw new UnauthorizedException('Usuário não encontrado');
    }

    const [company, license] = await Promise.all([
      user.companyId
        ? this.prisma.company.findUnique({
            where: { id: user.companyId },
            select: { id: true, tradeName: true, corporateName: true, cnpj: true, uf: true },
          })
        : null,
      user.tenantId
        ? this.prisma.license.findFirst({
            where: { tenantId: user.tenantId, status: { in: ['ACTIVE', 'TRIAL'] } },
            orderBy: { createdAt: 'desc' },
          })
        : null,
    ]);

    return {
      user,
      company,
      license: license
        ? {
            plan: license.plan,
            status: license.status,
            modules: {
              fiscal: license.fiscalEnabled,
              pdv: license.pdvEnabled,
              inventory: license.inventoryEnabled,
              reports: license.reportsEnabled,
            },
          }
        : null,
    };
  }
}
