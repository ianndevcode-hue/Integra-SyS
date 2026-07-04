# Integra SYS

ERP/PDV SaaS multi-tenant com **módulo fiscal real** para NF-e (modelo 55) e NFC-e (modelo 65),
construído sobre [NFePHP/sped-nfe](https://github.com/nfephp-org/sped-nfe) e
[NFePHP/sped-da](https://github.com/nfephp-org/sped-da).

## Estrutura do monorepo

```
integra-sys/
├── apps/
│   ├── admin-master/     # Painel administrativo global (Next.js) — suporte, diagnóstico e auditoria fiscal
│   ├── web-saas/         # Aplicação do cliente (Next.js) — telas fiscais completas
│   ├── mobile-pdv/       # PDV mobile (Expo/React Native) — vendas + NFC-e
│   ├── api/              # API principal (NestJS) — permissões, licença, tenant e banco
│   └── fiscal-service/   # Microserviço fiscal (PHP 8.2+ / NFePHP) — XML, assinatura, SEFAZ, DANFE
├── packages/
│   ├── ui/               # Componentes React compartilhados
│   ├── config/           # Leitura centralizada de env
│   ├── types/            # Tipos TS compartilhados (incluindo domínio fiscal)
│   ├── database/         # Prisma schema + client + seed
│   ├── auth/             # JWT, hash de senha, criptografia AES-256-GCM
│   ├── validation/       # Schemas Zod (fiscal e PDV)
│   ├── fiscal-client/    # Client TypeScript para o fiscal-service
│   └── shared/           # Helpers (CPF/CNPJ, moeda, chave de acesso, UFs)
├── docker-compose.yml
├── .env.example
├── README.md
└── package.json
```

## Arquitetura fiscal

```
Web SaaS ou PDV
      ↓
API Node.js (NestJS)
      ↓  valida permissão, licença fiscal e tenant
      ↓  cria venda/nota no banco (Prisma/PostgreSQL)
      ↓  aloca numeração (empresa + modelo + série + ambiente)
      ↓
packages/fiscal-client  →  HTTP interno com X-INTEGRA-INTERNAL-KEY
      ↓
fiscal-service (PHP)  →  NFePHP/sped-nfe monta, assina (A1) e envia o XML à SEFAZ
      ↓
API salva protocolo, XML (hash SHA-256), status, eventos e rejeições
      ↓
Web SaaS ou PDV recebe o resultado (autorizada/rejeitada + DANFE/DANFCE)
```

- A API **não monta XML fiscal**: toda a parte técnica fica isolada no `fiscal-service`.
- Senha do certificado A1 e CSC token são cifrados com **AES-256-GCM** — nunca em texto puro.
- XML autorizado é **imutável** no storage.
- Numeração fiscal por empresa/modelo/série com **homologação e produção separadas**.
- Toda emissão, cancelamento, inutilização e CC-e gera **audit log** e **evento fiscal**.

## Pré-requisitos

| Ferramenta | Versão | Uso |
| --- | --- | --- |
| Node.js | 20+ | API, Web SaaS, Admin Master |
| pnpm | 9+ | Gerenciador do monorepo (`corepack enable`) |
| PHP | 8.2+ | fiscal-service |
| Composer | 2.x | Dependências PHP |
| PostgreSQL | 16 | Banco de dados |
| Redis | 7 | Cache/filas |
| Docker (opcional) | — | Subir tudo com docker compose |

Extensões PHP necessárias: `curl`, `dom`, `json`, `openssl`, `soap`, `xml`, `zip`, `mbstring`.

```bash
# Ubuntu/Debian
sudo apt install php8.3-cli php8.3-curl php8.3-xml php8.3-soap php8.3-zip php8.3-mbstring php8.3-bcmath

# Instalar Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
```

## Instalação

```bash
git clone <repo> integra-sys && cd integra-sys

# 1. Variáveis de ambiente
cp .env.example .env
cp apps/fiscal-service/.env.example apps/fiscal-service/.env
# Gere a chave de criptografia (use o MESMO valor em APP_ENCRYPTION_KEY e FISCAL_ENCRYPTION_KEY):
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
# Defina também FISCAL_INTERNAL_KEY (mesmo valor na raiz e no fiscal-service)

# 2. Dependências Node (todos os apps e packages)
corepack enable
pnpm install

# 3. Dependências PHP do fiscal-service (via Composer)
cd apps/fiscal-service && composer install && cd ../..

# 4. Banco de dados (suba o postgres antes — ex: docker compose up -d postgres redis)
pnpm db:generate     # gera o Prisma Client
pnpm db:push         # cria as tabelas
pnpm db:seed         # dados de teste de HOMOLOGAÇÃO (tenant demo, usuários, produtos)
```

## Rodando os 5 serviços

```bash
# 1. API principal (NestJS)          → http://localhost:3333/api/v1
pnpm dev:api

# 2. Admin Master (Next.js)          → http://localhost:3001
pnpm dev:admin

# 3. Web SaaS (Next.js)              → http://localhost:3000
pnpm dev:web

# 4. Mobile/PDV (Expo)               → Expo Dev Tools
pnpm dev:mobile

# 5. Fiscal Service (PHP/NFePHP)     → http://localhost:3334
pnpm dev:fiscal
# equivalente a: cd apps/fiscal-service && composer serve
```

Usuários do seed (senhas de desenvolvimento):

| Usuário | Senha | Papel |
| --- | --- | --- |
| `master@integra.local` | `master123` | Admin Master |
| `admin@demo.local` | `demo123` | Admin do tenant demo (todas as permissões fiscais) |
| `caixa@demo.local` | `caixa123` | Operador de caixa (PDV + emissão) |

## Docker

```bash
docker compose up -d --build
```

Sobe: `postgres`, `redis`, `api`, `web-saas`, `admin-master` e `fiscal-service`
(container PHP com curl, dom, json, openssl, soap, xml, zip e mbstring).

---

# Módulo Fiscal com NFePHP/sped-nfe

## Como instalar o Composer e as dependências do fiscal-service

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer

cd apps/fiscal-service
composer install
```

Principais dependências: `nfephp-org/sped-nfe` (XML/assinatura/SEFAZ) e
`nfephp-org/sped-da` (DANFE/DANFCE).

## Como configurar o certificado A1

1. Acesse o **Web SaaS → Fiscal → Certificado Digital**.
2. Envie o arquivo `.pfx`/`.p12` e informe a senha.
3. A API cifra a senha com AES-256-GCM (`APP_ENCRYPTION_KEY`) e envia o arquivo ao
   fiscal-service, que valida com `NFePHP\Common\Certificate::readPfx` e armazena em
   `apps/fiscal-service/storage/certificates/{tenant}/{empresa}/`.
4. A tela mostra CNPJ, validade e status; use **Testar certificado** para revalidar.

A senha **nunca** é salva em texto puro. Certificado expirado bloqueia emissão.

## Como configurar CSC/Token NFC-e

1. Obtenha o CSC ID e o token no portal da SEFAZ do seu estado (há um por ambiente).
2. Acesse **Web SaaS → Fiscal → CSC/Token NFC-e** e cadastre.
3. O token é cifrado no banco (`FiscalCsc.encryptedCscToken`) e decifrado somente em
   memória no fiscal-service ao gerar o QRCode da NFC-e.

Sem CSC configurado para o ambiente atual, a emissão de NFC-e é bloqueada com erro claro.

## Como configurar homologação/produção

Em **Web SaaS → Fiscal → Configurações Fiscais**:

- **Ambiente**: `Homologação` ou `Produção` (campo `environment`).
- Homologação e produção têm **numeração separada** (`FiscalSequence` por ambiente).
- Em homologação, o fiscal-service insere automaticamente a razão social exigida pela
  SEFAZ no destinatário/infAdic (`... SEM VALOR FISCAL`).
- **Nunca use dados fictícios em produção** — o seed cria apenas dados de homologação.

## Como rodar o fiscal-service

```bash
cd apps/fiscal-service
cp .env.example .env   # configure FISCAL_INTERNAL_KEY e FISCAL_ENCRYPTION_KEY
composer serve         # php -S 0.0.0.0:3334 -t public public/index.php
```

## Como a API Node.js se comunica com o fiscal-service

A API usa o pacote `packages/fiscal-client` (`FiscalClient`), que chama os endpoints
internos por HTTP com o header `X-INTEGRA-INTERNAL-KEY`:

```ts
const client = new FiscalClient({ baseUrl: FISCAL_SERVICE_URL, internalKey: FISCAL_INTERNAL_KEY });
await client.emitirNFe(payload);          // POST /internal/fiscal/nfe/emit
await client.emitirNFCe(payload);         // POST /internal/fiscal/nfce/emit
await client.cancelarNota(id, payload);   // POST /internal/fiscal/invoices/:id/cancel
await client.inutilizarNumeracao(p);      // POST /internal/fiscal/invoices/inutilize
await client.gerarCartaCorrecao(id, p);   // POST /internal/fiscal/invoices/:id/correction-letter
await client.consultarStatusServico(uf, ctx); // GET/POST /internal/fiscal/status/:uf
await client.consultarNota(id, ctx, chave);   // /internal/fiscal/invoices/:id/query
await client.consultarRecibo(rec, ctx);       // /internal/fiscal/receipts/:receiptId/query
await client.baixarXml(id, chave);        // GET /internal/fiscal/invoices/:id/xml
await client.obterDanfe(id, chave);       // GET /internal/fiscal/invoices/:id/danfe
await client.obterDanfce(id, chave);      // GET /internal/fiscal/invoices/:id/danfce
```

## Como emitir NF-e pelo Web SaaS

1. Cadastre a configuração fiscal, o certificado A1 e (para NF-e) o cliente com CPF/CNPJ.
2. Acesse **Fiscal → Emitir NF-e**, informe destinatário, itens (NCM, CFOP, CST/CSOSN,
   unidade) e pagamentos.
3. A API valida permissão (`fiscal:emit`), licença fiscal, dados fiscais dos produtos e
   aloca o próximo número da série.
4. O fiscal-service monta o XML (layout 4.00), assina, valida o schema e envia à SEFAZ.
5. Autorizada: chave, protocolo e XML ficam salvos; imprima o DANFE na hora.
   Rejeitada: código e mensagem aparecem na tela e em **Fiscal → Rejeições**.

## Como emitir NFC-e pelo PDV

1. Faça login no app PDV (ex.: `caixa@demo.local`).
2. Adicione itens, escolha a forma de pagamento e toque em **Finalizar venda e emitir NFC-e**.
3. O app envia a venda para `POST /api/v1/pdv/invoices/nfce`; a API cria a venda e chama
   o fiscal-service.
4. A tela de confirmação mostra: número da venda, status da NFC-e, chave de acesso e
   protocolo (quando autorizada), botões de **imprimir DANFCE**, **compartilhar recibo**
   e **tentar emitir novamente** (em caso de rejeição, com código e mensagem).

**Offline:** a venda é salva localmente como *“Venda pendente de emissão fiscal”* —
a NFC-e não é emitida offline na primeira versão. Quando a conexão volta, o operador
toca no aviso de pendências e o app sincroniza e emite as notas (idempotente por
`localSaleId`).

## Como consultar XML

- Web SaaS: **Fiscal → XMLs** (ou botão *Baixar XML* na lista de notas) →
  `GET /api/v1/fiscal/invoices/:id/xml` (retorna base64).
- PDV: `GET /api/v1/pdv/invoices/:id/xml`.
- Os arquivos ficam em `apps/fiscal-service/storage/xml/{nfe|nfce}/{ambiente}/{chave}.xml`;
  cancelamentos, inutilizações e CC-e têm pastas próprias.

## Como imprimir DANFE/DANFCE

- **DANFE (A4, NF-e)**: botão *DANFE* na lista de notas ou tela **Fiscal → DANFE/DANFCE** →
  `GET /api/v1/fiscal/invoices/:id/danfe` — gerado pelo `DanfeGenerator` (sped-da).
- **DANFCE (cupom 80mm, NFC-e)**: botão *DANFCE* / PDV →
  `GET /api/v1/fiscal/invoices/:id/danfce` — gerado pelo `DanfceGenerator`.
- Os PDFs retornam em base64 e são abertos/enviados para impressão pelo front.

## Como testar em homologação

1. Configure `environment = homologação` nas Configurações Fiscais (padrão do seed).
2. Use um certificado A1 **válido** (mesmo em homologação a SEFAZ exige certificado real).
3. Cadastre o CSC de **homologação** para NFC-e.
4. Rode `pnpm db:seed` para criar produtos com NCM/CFOP/CSOSN corretos e o cliente de teste.
5. Emita pela tela **Emitir NF-e/NFC-e** ou pelo PDV e acompanhe:
   - **Fiscal → Notas Fiscais** (status, chave, protocolo)
   - **Fiscal → Rejeições** (código/mensagem da SEFAZ)
   - **Fiscal → Logs Fiscais** (chamadas aos webservices)
6. Notas de homologação **não têm valor fiscal**.

## Endpoints

<details>
<summary><strong>Fiscal Service (internos, exigem X-INTEGRA-INTERNAL-KEY)</strong></summary>

```
POST /internal/fiscal/nfe/emit
POST /internal/fiscal/nfce/emit
POST /internal/fiscal/invoices/:id/cancel
POST /internal/fiscal/invoices/inutilize
POST /internal/fiscal/invoices/:id/correction-letter
GET  /internal/fiscal/status/:uf
GET  /internal/fiscal/invoices/:id/query
GET  /internal/fiscal/receipts/:receiptId/query
GET  /internal/fiscal/invoices/:id/xml
GET  /internal/fiscal/invoices/:id/danfe
GET  /internal/fiscal/invoices/:id/danfce
POST /internal/fiscal/certificates/upload
POST /internal/fiscal/certificates/test
POST /internal/fiscal/certificates/remove
GET  /internal/fiscal/health
```
</details>

<details>
<summary><strong>API principal (autenticados via JWT)</strong></summary>

```
POST   /api/v1/fiscal/nfe/emit
POST   /api/v1/fiscal/nfce/emit
POST   /api/v1/fiscal/invoices/:id/cancel
POST   /api/v1/fiscal/invoices/inutilize
POST   /api/v1/fiscal/invoices/:id/correction-letter
GET    /api/v1/fiscal/status/:uf
GET    /api/v1/fiscal/invoices
GET    /api/v1/fiscal/invoices/:id
GET    /api/v1/fiscal/invoices/:id/xml
GET    /api/v1/fiscal/invoices/:id/danfe
GET    /api/v1/fiscal/invoices/:id/danfce
GET    /api/v1/fiscal/config
PATCH  /api/v1/fiscal/config
POST   /api/v1/fiscal/certificates
POST   /api/v1/fiscal/certificates/:id/test
DELETE /api/v1/fiscal/certificates/:id
GET    /api/v1/fiscal/dashboard | /rejections | /logs | /sequences

# PDV
POST   /api/v1/pdv/invoices/nfce
GET    /api/v1/pdv/invoices/:id/status
GET    /api/v1/pdv/invoices/:id/danfce
GET    /api/v1/pdv/invoices/:id/xml
POST   /api/v1/pdv/invoices/:id/retry

# Admin Master (somente MASTER_ADMIN)
GET    /api/v1/admin/fiscal/overview | /companies | /companies/:id/invoices
GET    /api/v1/admin/fiscal/failures | /certificates/expiring | /usage | /logs | /sefaz-status
```
</details>

## Regras fiscais implementadas

- Emissão exige: CNPJ da empresa, configuração fiscal, certificado A1 válido,
  CSC para NFC-e, NCM/CFOP/CST-CSOSN/unidade nos produtos, CPF/CNPJ do cliente
  quando obrigatório (NF-e sempre; NFC-e ≥ R$ 10.000) e forma de pagamento.
- Numeração não se repete (constraint única por empresa+modelo+série+número+ambiente
  e alocação transacional).
- Cancelamento, inutilização e carta de correção exigem justificativa/texto (mín. 15
  caracteres) e geram eventos fiscais.
- Rejeições são salvas com código e mensagem (`FiscalRejection`).
- Apenas usuários com permissões fiscais (`fiscal:emit`, `fiscal:cancel`, ...) e
  empresas com licença fiscal ativa emitem notas.

## Build e verificação

```bash
pnpm run build          # packages + api + web-saas + admin-master
pnpm typecheck          # typecheck de todos os workspaces (inclui mobile-pdv)
cd apps/fiscal-service && composer install && php -l src/**/*.php
```
