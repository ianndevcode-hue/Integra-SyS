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
import {
  customerCreateSchema,
  customerUpdateSchema,
  productCreateSchema,
  productUpdateSchema,
  stockAdjustSchema,
} from '@integra/validation';
import type { ZodType } from 'zod';
import { JwtAuthGuard, AuthenticatedRequest } from '../auth/jwt-auth.guard';
import { CustomersService } from './customers.service';
import { ErpDashboardService } from './erp-dashboard.service';
import { ProductsService } from './products.service';
import { ReportsService } from './reports.service';
import { SalesService } from './sales.service';
import { SettingsService } from './settings.service';

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

@Controller('erp')
@UseGuards(JwtAuthGuard)
export class ErpController {
  constructor(
    private readonly dashboardService: ErpDashboardService,
    private readonly productsService: ProductsService,
    private readonly customersService: CustomersService,
    private readonly salesService: SalesService,
    private readonly reportsService: ReportsService,
    private readonly settingsService: SettingsService,
  ) {}

  @Get('dashboard')
  dashboard(@Req() req: AuthenticatedRequest) {
    return this.dashboardService.dashboard(req.user);
  }

  @Get('company')
  company(@Req() req: AuthenticatedRequest) {
    return this.settingsService.company(req.user);
  }

  @Get('users')
  users(@Req() req: AuthenticatedRequest) {
    return this.settingsService.users(req.user);
  }

  @Get('reports/summary')
  reports(@Req() req: AuthenticatedRequest) {
    return this.reportsService.summary(req.user);
  }

  @Get('products')
  listProducts(@Req() req: AuthenticatedRequest, @Query('search') search?: string) {
    return this.productsService.list(req.user, search);
  }

  @Post('products')
  createProduct(@Req() req: AuthenticatedRequest, @Body() body: unknown) {
    return this.productsService.create(req.user, parse(productCreateSchema, body));
  }

  @Patch('products/:id')
  updateProduct(@Req() req: AuthenticatedRequest, @Param('id') id: string, @Body() body: unknown) {
    return this.productsService.update(req.user, id, parse(productUpdateSchema, body));
  }

  @Delete('products/:id')
  removeProduct(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.productsService.remove(req.user, id);
  }

  @Post('products/:id/stock')
  adjustStock(@Req() req: AuthenticatedRequest, @Param('id') id: string, @Body() body: unknown) {
    return this.productsService.adjustStock(req.user, id, parse(stockAdjustSchema, body));
  }

  @Get('customers')
  listCustomers(@Req() req: AuthenticatedRequest, @Query('search') search?: string) {
    return this.customersService.list(req.user, search);
  }

  @Post('customers')
  createCustomer(@Req() req: AuthenticatedRequest, @Body() body: unknown) {
    return this.customersService.create(req.user, parse(customerCreateSchema, body));
  }

  @Patch('customers/:id')
  updateCustomer(@Req() req: AuthenticatedRequest, @Param('id') id: string, @Body() body: unknown) {
    return this.customersService.update(req.user, id, parse(customerUpdateSchema, body));
  }

  @Delete('customers/:id')
  removeCustomer(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.customersService.remove(req.user, id);
  }

  @Get('sales')
  listSales(@Req() req: AuthenticatedRequest) {
    return this.salesService.list(req.user);
  }

  @Get('sales/:id')
  getSale(@Req() req: AuthenticatedRequest, @Param('id') id: string) {
    return this.salesService.get(req.user, id);
  }
}
