import { CanActivate, Controller, ExecutionContext, ForbiddenException, Get, Injectable, Param, Query, UseGuards } from '@nestjs/common';
import { JwtAuthGuard, AuthenticatedRequest } from '../auth/jwt-auth.guard';
import { AdminFiscalService } from './admin-fiscal.service';

/** Somente MASTER_ADMIN acessa a visão fiscal administrativa. */
@Injectable()
export class MasterAdminGuard implements CanActivate {
  canActivate(context: ExecutionContext): boolean {
    const request = context.switchToHttp().getRequest<AuthenticatedRequest>();
    if (request.user.role !== 'MASTER_ADMIN') {
      throw new ForbiddenException('Acesso restrito ao Admin Master');
    }
    return true;
  }
}

@Controller('admin/fiscal')
@UseGuards(JwtAuthGuard, MasterAdminGuard)
export class AdminFiscalController {
  constructor(private readonly adminService: AdminFiscalService) {}

  @Get('overview')
  overview() {
    return this.adminService.overview();
  }

  @Get('companies')
  companies() {
    return this.adminService.companiesWithFiscal();
  }

  @Get('companies/:companyId/invoices')
  invoicesByCompany(@Param('companyId') companyId: string, @Query('page') page?: string) {
    return this.adminService.invoicesByCompany(companyId, page ? parseInt(page, 10) : 1);
  }

  @Get('failures')
  failures(@Query('page') page?: string) {
    return this.adminService.recentFailures(page ? parseInt(page, 10) : 1);
  }

  @Get('certificates/expiring')
  expiringCertificates() {
    return this.adminService.expiringCertificates();
  }

  @Get('usage')
  usage() {
    return this.adminService.fiscalUsageByTenant();
  }

  @Get('logs')
  logs(@Query('page') page?: string) {
    return this.adminService.webserviceLogs(page ? parseInt(page, 10) : 1);
  }

  @Get('sefaz-status')
  sefazStatus() {
    return this.adminService.sefazStatusHistory();
  }
}
