# Loom

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

**Loom** is a powerful yet lightweight platform for managing domains, DNS zones, servers, resellers, and billing — all in one unified interface. It includes a built-in order and invoice system, allowing users to request services like domain registrations or server plans, which are then processed and provisioned via EPP or other APIs.

While easy to use and modular by design, Loom is also in active development to support the full feature set required by **ICANN-accredited registrars**, including escrow, RDAP, contact validation, and reporting tools.

It’s ideal for self-hosted registrars, resellers, and service providers who want flexibility, control, and a clean starting point without the bloat.

## Features

- **Unified Dashboard** – Manage domains, DNS, servers, users, billing, and more from one clean UI.
- **EPP Automation** – Built-in support for domain provisioning and updates via EPP.
- **Lightweight Billing** – Users can place orders, receive invoices, and manage services like domains or hosting.
- **ICANN-Ready Architecture** – Designed to support the needs of accredited registrars, with features like WDRP, Transfer Policy compliance, and abuse handling. – *coming soon*
- **DNS Management** – Full zone and record editor included. – *coming soon*
- **Reseller System** – Allow others to offer and manage services under their own accounts. – *coming soon*
- **Modular & Extensible** – Add your own modules or integrate third-party tools easily.
- **Modern Stack** – Slim 4 Framework, Twig, Bootstrap 5, PHP 8+.
- **Self-Hosted** – Your data, your control.

## Supported Providers

Loom works with a variety of external services through its modular architecture.

### Domain Registries

- **Namingo** – ✅
- **CentralNic/.co** – ✅
- **CORE** – ✅
- **DNS.Business/RyCE** – ✅
- **.fi** – ✅
- **.it.com** – ✅
- **Tucows Registry** – ✅
- **.ua** – ✅
- **CoCCA** – ✅🧪
- **GoDaddy Registry** – ✅🧪
- **Google** – ✅🧪
- **Hello Registry** – ✅🧪
- **Identity Digital** – ✅🧪
- **VeriSign** – ✅🧪

### Hosting

- **cPanel/WHM** – *coming soon*
- **Hestia** – *coming soon*

### Cloud Infrastructure

- **Contabo** – *coming soon*
- **Hetzner** – *coming soon*
- **Vultr** – *coming soon*

### DNS hosting

- **ClouDNS** – *coming soon*
- **deSEC** – *coming soon*
- **Vultr** – *coming soon*

### Payment

- **Balance** – ✅
- **Stripe** – ✅
- **LiqPay** – ✅
- **plata by mono** – ✅
- **Revolut Pay** – *coming soon*
- **PayPal** – *coming soon*
- **Mollie** – *coming soon*
- **Razorpay** – *coming soon*
- **Paystack** – *coming soon*
- **MercadoPago** – *coming soon*
- **Komoju** – *coming soon*
- **Bootpay** – *coming soon*

## Get Involved

Loom is open source — and we’d love your help!

Whether you're a developer, designer, registrar, or just exploring alternatives to commercial control panels, here's how you can contribute:

- [Report bugs or issues](https://github.com/getnamingo/loom/issues)
- Suggest features and improvements
- Build and contribute new modules
- Help with language translations
- Improve documentation or write guides

> Planning to launch your own registrar? Loom can grow with you — from simple reseller setups to full ICANN accreditation support.

## Documentation

### Installation

**Minimum requirement:** a VPS running Ubuntu 22.04/24.04 or Debian 12/13, with at least 1 CPU core, 2 GB RAM, and 10 GB hard drive space.

To get started, copy the command below and paste it into your server terminal:

```bash
bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/loom/refs/heads/main/docs/install.sh)
```

For detailed installation steps, see [install.md](docs/install.md)

### Update

To get started, copy the command below and paste it into your server terminal:

```bash
bash <(wget -qO- https://raw.githubusercontent.com/getnamingo/loom/refs/heads/main/docs/update.sh)
```

## Support

Your feedback and inquiries are invaluable to Loom's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/loom/issues) section of our GitHub repository.

## Acknowledgements

**Loom** is built on top of the **Argora Foundry** framework — a modular, extensible boilerplate designed for modern SaaS platforms, control panels, and admin tools.

**Argora Foundry**, in turn, is based on the excellent [hezecom/slim-starter](https://github.com/omotsuebe/slim-starter) by [Hezekiah Omotsuebe](https://github.com/omotsuebe), which provided a solid and clean foundation using **Slim Framework 4**.

## Support This Project

Love Loom? Help support its development by **donating**. Every contribution helps us build better tools for the open-source community.

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

Loom is licensed under the MIT License.