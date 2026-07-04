import { BadRequestException, Body, Controller, Get, Param, Post, Req, UseGuards } from '@nestjs/common';
import { PERMISSIONS } from '@integra/types';
import { pdvNfceSchema } from '@integra/validation';
import { JwtAuthGuard, AuthenticatedRequest } from '../auth/jwt-auth.guard';
import { PermissionsGuard, RequirePermissions } from '../auth/permissions.guard';
import { InvoiceService } from '../fiscal/invoice.service';
import { PdvService } from './pdv.service';

@Controller('pdv')
@UseGuards(JwtAuthGuard, PermissionsGuard)
export class PdvController {
  constructor(
    private readonly pdvService: PdvService,
    private readonly invoiceService: InvoiceService,
  ) {}

  /** Recebe venda do PDV, cria no banco e emite NFC-e via fiscal-service. */
  @Post('invoices/nfce')
  @RequirePermissions(PERMISSIONS.PDV_SELL, PERMISSIONS.FISCAL_EMIT)
  emitNfce(@Req() req: AuthenticatedRequest, @Body() body: unknown) {
    const parsed = pdvNfceSchema.safeParse(body);
    if (!parsed.success) {
      throw new BadRequestException({
        message: 'Venda inválida',
        details: parsed.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`),
      });
    }
    return this.pdvService.emitNfceForSale(req.user, parsed.data);
  }

  @Get('invoices/:id/status')
  @RequirePermissions(PERMISSIONS.PDV_SELL)
  status(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.pdvService.invoiceStatus(req.user, id);
  }

  @Get('invoices/:id/danfce')
  @RequirePermissions(PERMISSIONS.PDV_SELL)
  danfce(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.invoiceService.danfce(req.user, id);
  }

  @Get('invoices/:id/xml')
  @RequirePermissions(PERMISSIONS.PDV_SELL)
  xml(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.invoiceService.downloadXml(req.user, id);
  }

  /** Reenvia NFC-e rejeitada após correção dos dados no Web SaaS. */
  @Post('invoices/:id/retry')
  @RequirePermissions(PERMISSIONS.PDV_SELL, PERMISSIONS.FISCAL_EMIT)
  retry(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.pdvService.retryEmission(req.user, id);
  }
}
