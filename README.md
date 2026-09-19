# 🇧🇷 SolidSign API - Exemplo de Integração: Assinatura PAdES (PDF) com PKCS#12 (certificado pré-importado) (PHP)

## ⚠️ Disponibilidade

Este método (importação de certificado PKCS#12 direto no servidor) só está disponível em instâncias do SolidSign API rodando **on-premises** (localmente, na infraestrutura do próprio cliente). **Não está disponível na versão SaaS pública** do SolidSign.

Motivo: a importação PKCS#12 mantém a chave privada decriptada em cache no servidor por até 2 horas — um risco aceitável numa instância on-premises própria, mas não numa instância SaaS compartilhada entre vários clientes. Se você usa o SaaS público, use `sign-hsm-cloud` (seu próprio PSC) ou a custódia KMS SolidSign em vez deste método.

## Requisitos

- PHP (built-in server) (`composer install && cp .env.example .env`)
- Um token JWT válido (`POST /solidsign/auth/token`)
- Um certificado PKCS#12 já importado (`POST /solidsign/dsig/certificates/pkcs12/import`) — o `id` retornado é o `pfxCode`
- Front-end de referência (opcional): [`exemplo-react-pdf-pkcs12`](https://github.com/SolidTechSolutions/exemplo-react-pdf-pkcs12)

## Como rodar

```bash
composer install && cp .env.example .env
php -S 0.0.0.0:8088 -t public
```

O serviço sobe em `http://localhost:8088`.

## Como funciona

Este back-end expõe o endpoint abaixo, que recebe um formulário (`multipart/form-data`, CORS liberado) e repassa os dados pra SolidSign API real, devolvendo o resultado:

- `POST /api/pdf/sign/form`

## Variáveis do formulário

| Campo | Significado | Default |
|---|---|---|
| `document[i]` | Documento(s) a assinar | — |
| `authorization` | Token JWT (Bearer) | — |
| `baseUrl` | URL base da SolidSign API | `https://www.solidsign.com.br` |
| `pfxCode` | ID do certificado PKCS#12 importado | — |
| `signatureImage[i]` | Imagem da estampa visual (opcional) | (nenhuma) |
| `profile` | Perfil de assinatura PBAD/ETSI | `ADRB` |
| `hashAlgorithm` | Algoritmo de hash | `SHA256` |
| `reason / location / contact` | Metadados da assinatura (opcionais) | (vazio) |

---

# 🇬🇧 SolidSign API - Integration Example: PAdES (PDF) Signing with PKCS#12 (pre-imported certificate) (PHP)

## ⚠️ Availability

This method (server-side PKCS#12 certificate import) is only available on **on-premises** SolidSign API instances (running locally, on the customer's own infrastructure). **It is not available on the public SaaS** version of SolidSign.

Why: PKCS#12 import keeps the decrypted private key cached on the server for up to 2 hours — an acceptable risk on your own on-premises instance, but not on a shared multi-tenant SaaS instance. If you use the public SaaS, use `sign-hsm-cloud` (your own PSC) or KMS SolidSign custody instead of this method.

## Requirements

- PHP (built-in server) (`composer install && cp .env.example .env`)
- A valid JWT token (`POST /solidsign/auth/token`)
- A PKCS#12 certificate already imported (`POST /solidsign/dsig/certificates/pkcs12/import`) — the returned `id` is the `pfxCode`
- Reference front-end (optional): [`exemplo-react-pdf-pkcs12`](https://github.com/SolidTechSolutions/exemplo-react-pdf-pkcs12)

## Running

```bash
composer install && cp .env.example .env
php -S 0.0.0.0:8088 -t public
```

The service starts on `http://localhost:8088`.

## How it works

This backend exposes the endpoint below, which accepts a form (`multipart/form-data`, CORS-enabled) and forwards the data to the real SolidSign API, returning the result:

- `POST /api/pdf/sign/form`

## Form fields

| Field | Meaning | Default |
|---|---|---|
| `document[i]` | Document(s) to sign | — |
| `authorization` | JWT (Bearer) token | — |
| `baseUrl` | SolidSign API base URL | `https://www.solidsign.com.br` |
| `pfxCode` | ID of the imported PKCS#12 certificate | — |
| `signatureImage[i]` | Visual stamp image (optional) | (none) |
| `profile` | PBAD/ETSI signature profile | `ADRB` |
| `hashAlgorithm` | Hash algorithm | `SHA256` |
| `reason / location / contact` | Signature metadata (optional) | (empty) |
