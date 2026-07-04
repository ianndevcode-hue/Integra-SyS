import {
  BadRequestException,
  Body,
  Controller,
  Delete,
  Get,
  Param,
  Patch,
  Post,
  Query,
  Req,
  UseGuards,
} from '@nestjs/common';
import { PERMISSIONS } from '@integra/types';
import {
  cancelInvoiceSchema,
  certificateUploadSchema,
  correctionLetterSchema,
  emitNfceSchema,
  emitNfeSchema,
  fiscalConfigUpdateSchema,
  inutilizeSchema,
} from '@integra/validation';
import type { ZodType } from 'zod';
import { JwtAuthGuard, AuthenticatedRequest } from '../auth/jwt-auth.guard';
import { PermissionsGuard, RequirePermissions } from '../auth/permissions.guard';
import { InvoiceService } from './invoice.service';
import { FiscalConfigService } from './fiscal-config.service';
import { FiscalDashboardService } from './fiscal-dashboard.service';
import { FiscalSequenceService } from './fiscal-sequence.service';

function parse<T>(schema: ZodType<T, any, any>, body: unknown): T {
  const result = schema.safeParse(body);
  if (!result.success) {
    throw new BadRequestException({
      message: 'Dados inválidos',
      details: result.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`),
    });
  }
  return result.data;
}

@Controller('fiscal')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class FiscalController {
  constructor(
    private readonly invoiceService: InvoiceService,
    private readonly configService: FiscalConfigService,
    private readonly dashboardService: FiscalDashboardService,
    private readonly sequenceService: FiscalSequenceService,
  ) {}

  // ---------------- Emissão ----------------

  @Post('nfe/emit')
  @RequirePermissions(PERMISSIONS.FISCAL_EMIT)
  emitNfe(@Req() req: AuthenticatedRequest, @Body() body: unknown) {
    return this.invoiceService.emitNfe(req.user, parse(emitNfeSchema, body));
  }

  @Post('nfce/emit')
  @RequirePermissions(PERMISSIONS.FISCAL_EMIT)
  emitNfce(@Req() req: AuthenticatedRequest, @Body() body: unknown) {
    return this.invoiceService.emitNfce(req.user, parse(emitNfceSchema, body));
  }

  // ---------------- Eventos ----------------

  @Post('invoices/inutilize')
  @RequirePermissions(PERMISSIONS.FISCAL_INUTILIZE)
  inutilize(@Req() req: AuthenticatedRequest, @Body() body: unknown) {
    return this.invoiceService.inutilize(req.user, parse(inutilizeSchema, body));
  }

  @Post('invoices/:id/cancel')
  @RequirePermissions(PERMISSIONS.FISCAL_CANCEL)
  cancel(@Req() req: AuthenticatedRequest, @Param('id') id: string, @Body() body: unknown) {
    return this.invoiceService.cancel(req.user, id, parse(cancelInvoiceSchema, body));
  }

  @Post('invoices/:id/correction-letter')
  @RequirePermissions(PERMISSIONS.FISCAL_CORRECTION)
  correctionLetter(@Req() req: AuthenticatedRequest, @Param('id') id: string, @Body() body: unknown) {
    return this.invoiceService.correctionLetter(req.user, id, parse(correctionLetterSchema, body));
  }

  // ---------------- Consultas ----------------

  @Get('dashboard')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  dashboard(@Req() req: AuthenticatedRequest) {
    return this.dashboardService.dashboard(req.user);
  }

  @Get('status/:uf')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  sefazStatus(@Req() req: AuthenticatedRequest, @Param('uf') uf: string) {
    return this.invoiceService.sefazStatus(req.user, uf);
  }

  @Get('invoices')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  list(
    @Req() req: AuthenticatedRequest,
    @Query('model') model?: string,
    @Query('status') status?: string,
    @Query('customerId') customerId?: string,
    @Query('startDate') startDate?: string,
    @Query('endDate') endDate?: string,
    @Query('number') number?: string,
    @Query('series') series?: string,
    @Query('page') page?: string,
    @Query('pageSize') pageSize?: string,
  ) {
    return this.invoiceService.list(req.user, {
      model,
      status,
      customerId,
      startDate,
      endDate,
      number,
      series,
      page: page ? parseInt(page, 10) : undefined,
      pageSize: pageSize ? parseInt(pageSize, 10) : undefined,
    });
  }

  @Get('rejections')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  rejections(@Req() req: AuthenticatedRequest, @Query('page') page?: string) {
    return this.dashboardService.rejections(req.user, page ? parseInt(page, 10) : 1);
  }

  @Get('logs')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  logs(@Req() req: AuthenticatedRequest, @Query('page') page?: string) {
    return this.dashboardService.webserviceLogs(req.user, page ? parseInt(page, 10) : 1);
  }

  @Get('sequences')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  sequences(@Req() req: AuthenticatedRequest) {
    if (!req.user.companyId) return [];
    return this.sequenceService.list(req.user.companyId);
  }

  @Get('invoices/:id')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  findOne(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.invoiceService.findOne(req.user, id);
  }

  @Get('invoices/:id/query')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  query(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.invoiceService.queryAtSefaz(req.user, id);
  }

  @Get('invoices/:id/xml')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  xml(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.invoiceService.downloadXml(req.user, id);
  }

  @Get('invoices/:id/danfe')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  danfe(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.invoiceService.danfe(req.user, id);
  }

  @Get('invoices/:id/danfce')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  danfce(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.invoiceService.danfce(req.user, id);
  }

  // ---------------- Configuração ----------------

  @Get('config')
  @RequirePermissions(PERMISSIONS.FISCAL_VIEW)
  getConfig(@Req() req: AuthenticatedRequest) {
    return this.configService.getConfig(req.user);
  }

  @Patch('config')
  @RequirePermissions(PERMISSIONS.FISCAL_CONFIG)
  updateConfig(@Req() req: AuthenticatedRequest, @Body() body: unknown) {
    return this.configService.updateConfig(req.user, parse(fiscalConfigUpdateSchema, body));
  }

  // ---------------- Certificado ----------------

  @Post('certificates')
  @RequirePermissions(PERMISSIONS.FISCAL_CERTIFICATE)
  uploadCertificate(@Req() req: AuthenticatedRequest, @Body() body: unknown) {
    return this.configService.uploadCertificate(req.user, parse(certificateUploadSchema, body));
  }

  @Post('certificates/:id/test')
  @RequirePermissions(PERMISSIONS.FISCAL_CERTIFICATE)
  testCertificate(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.configService.testCertificate(req.user, id);
  }

  @Delete('certificates/:id')
  @RequirePermissions(PERMISSIONS.FISCAL_CERTIFICATE)
  removeCertificate(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.configService.removeCertificate(req.user, id);
  }
}
