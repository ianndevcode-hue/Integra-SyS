import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { FiscalController } from './fiscal.controller';
import { FiscalContextService } from './fiscal-context.service';
import { FiscalSequenceService } from './fiscal-sequence.service';
import { FiscalValidationService } from './fiscal-validation.service';
import { FiscalConfigService } from './fiscal-config.service';
import { FiscalDashboardService } from './fiscal-dashboard.service';
import { InvoiceService } from './invoice.service';

@Module({
  imports: [AuthModule],
  controllers: [FiscalController],
  providers: [
    FiscalContextService,
    FiscalSequenceService,
    FiscalValidationService,
    FiscalConfigService,
    FiscalDashboardService,
    InvoiceService,
  ],
  exports: [InvoiceService, FiscalContextService, FiscalConfigService],
})
export class FiscalModule {}
