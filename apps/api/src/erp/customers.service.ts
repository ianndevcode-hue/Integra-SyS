import { Injectable, NotFoundException } from '@nestjs/common';
import type { CustomerDto, JwtPayload } from '@integra/types';
import type { CustomerCreateInput, CustomerUpdateInput } from '@integra/validation';
import { PrismaService } from '../core/prisma.service';
import { ErpContextService } from './erp-context.service';

function toCustomerDto(customer: {
  id: string;
  name: string;
  document: string | null;
  email: string | null;
  phone: string | null;
  cityName: string | null;
  uf: string | null;
  createdAt: Date;
  updatedAt: Date;
}): CustomerDto {
  return {
    id: customer.id,
    name: customer.name,
    document: customer.document,
    email: customer.email,
    phone: customer.phone,
    cityName: customer.cityName,
    uf: customer.uf,
    createdAt: customer.createdAt.toISOString(),
    updatedAt: customer.updatedAt.toISOString(),
  };
}

@Injectable()
export class CustomersService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly context: ErpContextService,
  ) {}

  async list(user: JwtPayload, search?: string): Promise<CustomerDto[]> {
    const { companyId } = this.context.requireCompany(user);
    const customers = await this.prisma.customer.findMany({
      where: {
        companyId,
        ...(search
          ? {
              OR: [
                { name: { contains: search, mode: 'insensitive' } },
                { document: { contains: search, mode: 'insensitive' } },
              ],
            }
          : {}),
      },
      orderBy: { name: 'asc' },
    });
    return customers.map(toCustomerDto);
  }

  async create(user: JwtPayload, input: CustomerCreateInput): Promise<CustomerDto> {
    const { tenantId, companyId } = this.context.requireCompany(user);
    const customer = await this.prisma.customer.create({
      data: {
        tenantId,
        companyId,
        ...input,
        email: input.email || null,
      },
    });
    return toCustomerDto(customer);
  }

  async update(user: JwtPayload, id: string, input: CustomerUpdateInput): Promise<CustomerDto> {
    const { companyId } = this.context.requireCompany(user);
    const current = await this.prisma.customer.findFirst({ where: { id, companyId } });
    if (!current) throw new NotFoundException('Cliente não encontrado');

    const customer = await this.prisma.customer.update({
      where: { id },
      data: { ...input, email: input.email === '' ? null : input.email },
    });
    return toCustomerDto(customer);
  }

  async remove(user: JwtPayload, id: string): Promise<void> {
    const { companyId } = this.context.requireCompany(user);
    const current = await this.prisma.customer.findFirst({ where: { id, companyId } });
    if (!current) throw new NotFoundException('Cliente não encontrado');
    await this.prisma.customer.delete({ where: { id } });
  }
}
