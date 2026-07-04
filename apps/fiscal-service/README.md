# Integra SYS — Fiscal Service

Microserviço fiscal em PHP 8.2+ responsável por toda a parte técnica de
NF-e (modelo 55) e NFC-e (modelo 65), usando
[NFePHP/sped-nfe](https://github.com/nfephp-org/sped-nfe) e
[NFePHP/sped-da](https://github.com/nfephp-org/sped-da).

## Responsabilidades

- Montar XML de NF-e/NFC-e (layout 4.00) — `InvoiceXmlBuilder`
- Assinar XML com certificado A1 (.pfx/.p12) e validar schema — `InvoiceSigner`
- Enviar para SEFAZ (envio síncrono) — `InvoiceSender`
- Consultar status do serviço, recibo e protocolo — `InvoiceStatusService`
- Cancelar NF-e/NFC-e (evento 110111) — `InvoiceCancelService`
- Inutilizar numeração — `InvoiceInutilizationService`
- Carta de Correção Eletrônica para NF-e (evento 110110) — `CorrectionLetterService`
- Gerar DANFE (A4) e DANFCE (cupom 80mm) — `DanfeGenerator` / `DanfceGenerator`
- Armazenar XMLs autorizados/cancelados/inutilizações com imutabilidade — `FiscalStorageService`
- Logs fiscais rotativos — `FiscalLogger`
- Certificados A1 por empresa/tenant — `CertificateManager`

## Instalação

```bash
# Instalar o Composer (se ainda não tiver): https://getcomposer.org/download/
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Instalar dependências
cd apps/fiscal-service
composer install
```

Extensões PHP necessárias: `curl`, `dom`, `json`, `openssl`, `soap`, `xml`, `zip`, `mbstring`.

## Configuração

```bash
cp .env.example .env
```

| Variável | Descrição |
| --- | --- |
| `FISCAL_SERVICE_PORT` | Porta HTTP (padrão 3334) |
| `FISCAL_INTERNAL_KEY` | Chave exigida no header `X-INTEGRA-INTERNAL-KEY` |
| `FISCAL_STORAGE_PATH` | Diretório de certificados/XMLs/DANFEs/logs |
| `FISCAL_DEFAULT_ENVIRONMENT` | `homologation` ou `production` |
| `FISCAL_ENCRYPTION_KEY` | Chave AES-256-GCM (hex 64 chars) — igual à `APP_ENCRYPTION_KEY` da API |

## Executando

```bash
composer serve
# ou
php -S 0.0.0.0:3334 -t public public/index.php
```

## Endpoints internos

Todos exigem o header `X-INTEGRA-INTERNAL-KEY` (exceto `/internal/fiscal/health`):

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

## Segurança

- A senha do certificado A1 e o CSC token **nunca** trafegam nem são
  armazenados em texto puro: a API Node cifra com AES-256-GCM e este
  serviço decifra apenas em memória no momento do uso.
- XML autorizado é imutável: o storage nunca sobrescreve um XML já gravado.
- Certificados ficam fora do controle de versão (`storage/certificates` no `.gitignore`).

## Storage

```
storage/
├── certificates/{tenantId}/{companyId}/certificado-*.pfx
├── xml/
│   ├── nfe/{homologacao|producao}/{chave}.xml
│   ├── nfce/{homologacao|producao}/{chave}.xml
│   ├── cancelamentos/{homologacao|producao}/{chave}-canc-*.xml
│   ├── inutilizacoes/{homologacao|producao}/*.xml
│   └── cce/{homologacao|producao}/{chave}-cce-*.xml
├── danfe/{homologacao|producao}/{chave}.pdf
└── logs/fiscal-*.log
```
