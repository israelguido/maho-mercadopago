# Mercado Pago para Maho Commerce

Checkout transparente do Mercado Pago para Pix, boleto e cartão de crédito no Brasil.

## Instalação

```bash
composer require israelguido/maho-mercadopago
```

Depois da instalação, limpe o cache do Maho.

Se o pacote for instalado por repositório `path` durante o desenvolvimento, rode
`composer dump-autoload` depois de alterar controllers para recompilar as rotas por atributo.

## Configuração

No admin, acesse **Sistema > Configuração > Formas de pagamento**. Preencha as credenciais no grupo **Mercado Pago** e ative individualmente Pix, boleto e cartão de crédito. A rua é sempre a linha 1; mapeie número, complemento e bairro nas linhas 1–4 (ou Nenhuma). O complemento só é enviado em `payer.address` da Orders API quando a linha estiver configurada.

Configure no Mercado Pago a seguinte URL de webhook:

```text
https://SEU-DOMINIO/israelguido_mercadopago/webhook
```

Use sempre HTTPS em produção.
