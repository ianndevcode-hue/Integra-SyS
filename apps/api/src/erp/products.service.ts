import { ConflictException, Injectable, NotFoundException } from '@nestjs/common';
import type { JwtPayload, ProductDto } from '@integra/types';
import type { ProductCreateInput, ProductUpdateInput, StockAdjustInput } from '@integra/validation';
import { PrismaService } from '../core/prisma.service';
import { ErpContextService } from './erp-context.service';

function toProductDto(product: {
  id: string;
  code: string;
  ean: string | null;
  description: string;
  ncm: string | null;
  cest: string | null;
  cfop: string | null;
  unit: string;
  price: { toString(): string };
  stockQuantity: { toString(): string };
  icmsCsosn: string | null;
  active: boolean;
  createdAt: Date;
  updatedAt: Date;
}): ProductDto {
  return {
    id: product.id,
    code: product.code,
    ean: product.ean,
    description: product.description,
    ncm: product.ncm,
    cest: product.cest,
    cfop: product.cfop,
    unit: product.unit,
    price: Number(product.price),
    stockQuantity: Number(product.stockQuantity),
    icmsCsosn: product.icmsCsosn,
    active: product.active,
    createdAt: product.createdAt.toISOString(),
    updatedAt: product.updatedAt.toISOString(),
  };
}

@Injectable()
export class ProductsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly context: ErpContextService,
  ) {}

  async list(user: JwtPayload, search?: string): Promise<ProductDto[]> {
    const { companyId } = this.context.requireCompany(user);
    const products = await this.prisma.product.findMany({
      where: {
        companyId,
        ...(search
          ? {
              OR: [
                { code: { contains: search, mode: 'insensitive' } },
                { description: { contains: search, mode: 'insensitive' } },
              ],
            }
          : {}),
      },
      orderBy: { description: 'asc' },
    });
    return products.map(toProductDto);
  }

  async create(user: JwtPayload, input: ProductCreateInput): Promise<ProductDto> {
    const { tenantId, companyId } = this.context.requireCompany(user);
    const exists = await this.prisma.product.findUnique({
      where: { companyId_code: { companyId, code: input.code } },
    });
    if (exists) throw new ConflictException('Já existe produto com este código');

    const product = await this.prisma.product.create({
      data: { tenantId, companyId, ...input },
    });
    return toProductDto(product);
  }

  async update(user: JwtPayload, id: string, input: ProductUpdateInput): Promise<ProductDto> {
    const { companyId } = this.context.requireCompany(user);
    const current = await this.prisma.product.findFirst({ where: { id, companyId } });
    if (!current) throw new NotFoundException('Produto não encontrado');

    if (input.code && input.code !== current.code) {
      const exists = await this.prisma.product.findUnique({
        where: { companyId_code: { companyId, code: input.code } },
      });
      if (exists) throw new ConflictException('Já existe produto com este código');
    }

    const product = await this.prisma.product.update({ where: { id }, data: input });
    return toProductDto(product);
  }

  async remove(user: JwtPayload, id: string): Promise<void> {
    const { companyId } = this.context.requireCompany(user);
    const current = await this.prisma.product.findFirst({ where: { id, companyId } });
    if (!current) throw new NotFoundException('Produto não encontrado');
    await this.prisma.product.update({ where: { id }, data: { active: false } });
  }

  async adjustStock(user: JwtPayload, id: string, input: StockAdjustInput): Promise<ProductDto> {
    const { companyId } = this.context.requireCompany(user);
    const current = await this.prisma.product.findFirst({ where: { id, companyId } });
    if (!current) throw new NotFoundException('Produto não encontrado');

    const next = Number(current.stockQuantity) + input.quantity;
    if (next < 0) throw new ConflictException('Estoque não pode ficar negativo');

    const product = await this.prisma.product.update({
      where: { id },
      data: { stockQuantity: next },
    });
    return toProductDto(product);
  }
}
