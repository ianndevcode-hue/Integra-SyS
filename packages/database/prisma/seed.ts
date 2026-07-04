/**
 * Seed de desenvolvimento — cria tenant, empresa, usuários, produtos e
 * configuração fiscal APENAS EM HOMOLOGAÇÃO.
 *
 * Nunca use estes dados em produção. A emissão em produção exige
 * certificado A1 real, CSC real e configuração fiscal da empresa.
 */
import { PrismaClient } from '@prisma/client';
import { randomBytes, scryptSync } from 'crypto';

const prisma = new PrismaClient();

function hashPassword(password: string): string {
  const salt = randomBytes(16).toString('hex');
  const hash = scryptSync(password, salt, 64).toString('hex');
  return `${salt}:${hash}`;
}

async function main() {
  const tenant = await prisma.tenant.upsert({
    where: { slug: 'demo' },
    update: {},
    create: {
      name: 'Empresa Demo (Homologação)',
      slug: 'demo',
    },
  });

  const company = await prisma.company.upsert({
    where: { cnpj: '99999999000191' },
    update: {},
    create: {
      tenantId: tenant.id,
      // CNPJ de teste — usado somente em ambiente de homologação
      cnpj: '99999999000191',
      corporateName: 'EMPRESA DEMO HOMOLOGACAO LTDA',
      tradeName: 'Demo Homologação',
      email: 'fiscal@demo.local',
      street: 'Rua de Teste',
      number: '100',
      district: 'Centro',
      cityCode: '3550308',
      cityName: 'São Paulo',
      uf: 'SP',
      zipCode: '01001000',
    },
  });

  const existingLicense = await prisma.license.findFirst({ where: { tenantId: tenant.id } });
  if (!existingLicense) {
    await prisma.license.create({
      data: {
        tenantId: tenant.id,
        plan: 'PRO',
        status: 'ACTIVE',
        fiscalEnabled: true,
        validUntil: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000),
      },
    });
  }

  await prisma.user.upsert({
    where: { email: 'master@integra.local' },
    update: {},
    create: {
      name: 'Master Admin',
      email: 'master@integra.local',
      passwordHash: hashPassword('master123'),
      role: 'MASTER_ADMIN',
      permissions: ['admin:support'],
    },
  });

  await prisma.user.upsert({
    where: { email: 'admin@demo.local' },
    update: {},
    create: {
      tenantId: tenant.id,
      companyId: company.id,
      name: 'Admin Demo',
      email: 'admin@demo.local',
      passwordHash: hashPassword('demo123'),
      role: 'TENANT_ADMIN',
      permissions: [
        'fiscal:emit',
        'fiscal:cancel',
        'fiscal:inutilize',
        'fiscal:correction',
        'fiscal:config',
        'fiscal:certificate',
        'fiscal:view',
        'pdv:sell',
      ],
    },
  });

  await prisma.user.upsert({
    where: { email: 'caixa@demo.local' },
    update: {},
    create: {
      tenantId: tenant.id,
      companyId: company.id,
      name: 'Operador de Caixa',
      email: 'caixa@demo.local',
      passwordHash: hashPassword('caixa123'),
      role: 'CASHIER',
      permissions: ['pdv:sell', 'fiscal:emit', 'fiscal:view'],
    },
  });

  // Configuração fiscal de homologação
  let fiscalConfig = await prisma.fiscalConfig.findFirst({ where: { companyId: company.id, branchId: null } });
  if (!fiscalConfig) {
    fiscalConfig = await prisma.fiscalConfig.create({
      data: {
        tenantId: tenant.id,
        companyId: company.id,
        uf: 'SP',
        cityCode: '3550308',
        cityName: 'São Paulo',
        stateRegistration: 'ISENTO',
        taxRegime: 'SIMPLES_NACIONAL',
        crt: 1,
        environment: 'HOMOLOGATION',
        defaultNfeSeries: 1,
        defaultNfceSeries: 1,
        nfeEnabled: true,
        nfceEnabled: true,
      },
    });
  }

  await prisma.fiscalResponsibleTechnician.upsert({
    where: { fiscalConfigId: fiscalConfig.id },
    update: {},
    create: {
      fiscalConfigId: fiscalConfig.id,
      name: 'Responsável Técnico Demo',
      email: 'tecnico@demo.local',
      cnpj: '99999999000191',
      phone: '11999999999',
    },
  });

  // Sequências fiscais separadas por modelo/série/ambiente (homologação)
  for (const model of ['NFE_55', 'NFCE_65'] as const) {
    const exists = await prisma.fiscalSequence.findFirst({
      where: { companyId: company.id, branchId: null, model, series: 1, environment: 'HOMOLOGATION' },
    });
    if (!exists) {
      await prisma.fiscalSequence.create({
        data: {
          tenantId: tenant.id,
          companyId: company.id,
          model,
          series: 1,
          currentNumber: 0,
          nextNumber: 1,
          environment: 'HOMOLOGATION',
        },
      });
    }
  }

  // Produtos de teste com dados fiscais completos (NCM, CFOP, CSOSN, unidade)
  const products = [
    { code: 'P001', description: 'Café Torrado 500g', ncm: '09012100', cfop: '5102', unit: 'UN', price: 24.9, icmsCsosn: '102' },
    { code: 'P002', description: 'Açúcar Cristal 1kg', ncm: '17019900', cfop: '5102', unit: 'UN', price: 5.49, icmsCsosn: '102' },
    { code: 'P003', description: 'Água Mineral 500ml', ncm: '22011000', cfop: '5405', unit: 'UN', price: 3.0, icmsCsosn: '500', cest: '0300700' },
  ];

  for (const p of products) {
    await prisma.product.upsert({
      where: { companyId_code: { companyId: company.id, code: p.code } },
      update: {},
      create: {
        tenantId: tenant.id,
        companyId: company.id,
        code: p.code,
        description: p.description,
        ncm: p.ncm,
        cest: p.cest,
        cfop: p.cfop,
        unit: p.unit,
        price: p.price,
        icmsCsosn: p.icmsCsosn,
        pisCst: '49',
        cofinsCst: '49',
        origin: 0,
      },
    });
  }

  await prisma.customer.upsert({
    where: { id: 'seed-customer-demo' },
    update: {},
    create: {
      id: 'seed-customer-demo',
      tenantId: tenant.id,
      companyId: company.id,
      name: 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL',
      document: '11144477735',
      cityCode: '3550308',
      cityName: 'São Paulo',
      uf: 'SP',
      street: 'Rua Cliente Teste',
      number: '1',
      district: 'Centro',
      zipCode: '01001000',
      stateRegistrationIndicator: 9,
    },
  });

  console.log('Seed de homologação concluído.');
  console.log('Usuários: master@integra.local/master123, admin@demo.local/demo123, caixa@demo.local/caixa123');
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(() => prisma.$disconnect());
