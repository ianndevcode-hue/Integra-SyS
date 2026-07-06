import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { CustomersService } from './customers.service';
import { ErpContextService } from './erp-context.service';
import { ErpController } from './erp.controller';
import { ErpDashboardService } from './erp-dashboard.service';
import { ProductsService } from './products.service';
import { ReportsService } from './reports.service';
import { SalesService } from './sales.service';
import { SettingsService } from './settings.service';

@Module({
  imports: [AuthModule],
  controllers: [ErpController],
  providers: [
    ErpContextService,
    ErpDashboardService,
    ProductsService,
    CustomersService,
    SalesService,
    ReportsService,
    SettingsService,
  ],
})
export class ErpModule {}
