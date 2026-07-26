# FOSSBilling VPS module package

This is a module for FOSSBilling that adds VPS hosting support with Proxmox.

## Features

- User sidebar adds "VPS" and "Containers" pages, allowing users to provision VMs and containers from a configured Proxmox instance, based on cloud-init templates. It also shows the total cost of the machine usage to date, to gauge whether the account needs to re-up.
- Admin section to populate credentials, can use any realm/secret/token, etc. Allows you to specify cost per virtual machine, with two tiers of storage (presumably "slow" and "fast"), CPU, memory by the hour, and also by IP address, with options for "Public", "Internal", and "IPv6-Only". The Admin page also provides a billing breakdown.
- Keeps track of the number of hours that each machine is powered on, and bills the user monthly based on that.

## Requirements

- Proxmox 9
- Latest FOSSBilling 0.8.5 (for version 0.8.3 see the v0.8.3 branch)
- Composer

## Information

The module works based on funds added to the account (via whatever your chosen FOSSBilling payment gateway module), and it requires the user to have sufficient funds for one month of continuous usage for the VM they are provisioning. This is made very clear, and it will not allow you to provision a VM with insufficient funds, although this requirement can of course be removed. Most of the default values (e.g. the calculator) are from its use at https://rc7.net

## What is included

- `manifest.json` — module metadata required by FOSSBilling
- `Service.php` — core VPS service layer and provisioning logic
- `Api/` — client, admin, and guest endpoints
- `Controller/` — route registration for admin and client areas
- `html_*` and `templates/` — admin/client UI templates
- `Api/composer.json` — API-layer dependency metadata for Composer installation

## Installation in FOSSBilling

1) Copy the files into place on your FOSSBilling 0.8.3 install under modules/Vps
2) In `modules/Vps/Api`, run `composer install` to install the module dependencies into `Api/vendor/`
3) Go to the FOSSBilling admin panel and enable the module
4) Go to Extensions -> VPS and configure it with your Proxmox details and billing rates
5) Once users begin provisioning VMs, the admin page will accummulate the totals

## Tech Specs

Uses guzzlehttp/guzzle for queries and zzantares/proxmoxve for the Proxmox integration

## Licensing

This package is released under the Apache 2.0 license. See [LICENSE](LICENSE).
