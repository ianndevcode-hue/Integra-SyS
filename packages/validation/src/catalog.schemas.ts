import { z } from 'zod';

export const productCreateSchema = z.object({
  code: z.string().min(1).max(60),
  ean: z.string().max(14).optional(),
  description: z.string().min(1).max(200),
  ncm: z.string().max(8).optional(),
  cest: z.string().max(7).optional(),
  cfop: z.string().max(4).optional(),
  unit: z.string().min(1).max(6).default('UN'),
  price: z.number().nonnegative(),
  stockQuantity: z.number().nonnegative().default(0),
  icmsCst: z.string().max(3).optional(),
  icmsCsosn: z.string().max(3).optional(),
  icmsRate: z.number().nonnegative().optional(),
  pisCst: z.string().max(2).optional(),
  cofinsCst: z.string().max(2).optional(),
  origin: z.number().int().min(0).max(8).default(0),
  active: z.boolean().default(true),
});

export const productUpdateSchema = productCreateSchema.partial();

export const customerCreateSchema = z.object({
  name: z.string().min(1).max(200),
  document: z.string().max(14).optional(),
  email: z.string().email().optional().or(z.literal('')),
  phone: z.string().max(20).optional(),
  stateRegistration: z.string().max(20).optional(),
  stateRegistrationIndicator: z.number().int().min(1).max(9).default(9),
  street: z.string().max(120).optional(),
  number: z.string().max(20).optional(),
  complement: z.string().max(60).optional(),
  district: z.string().max(60).optional(),
  cityCode: z.string().max(7).optional(),
  cityName: z.string().max(60).optional(),
  uf: z.string().length(2).optional(),
  zipCode: z.string().max(8).optional(),
});

export const customerUpdateSchema = customerCreateSchema.partial();

export const stockAdjustSchema = z.object({
  quantity: z.number(),
  reason: z.string().max(200).optional(),
});

export type ProductCreateInput = z.infer<typeof productCreateSchema>;
export type ProductUpdateInput = z.infer<typeof productUpdateSchema>;
export type CustomerCreateInput = z.infer<typeof customerCreateSchema>;
export type CustomerUpdateInput = z.infer<typeof customerUpdateSchema>;
export type StockAdjustInput = z.infer<typeof stockAdjustSchema>;
