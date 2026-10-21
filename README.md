# 🤖 AutoReply — Backend

Backend for a WhatsApp auto-reply and CRM platform, built with **Laravel**.

## Features

- **Multi-channel Inbox** — unified inbox for WhatsApp conversations
- **CRM** — contact management, merging, and team assignment
- **Campaigns** — WhatsApp broadcast campaigns with audience targeting
- **Knowledge Base** — auto-reply knowledge retries
- **Analytics Dashboard** — scoped filters and reporting
- **Job Queue** — database-backed job locks for reliable processing
- **Admin Panel** — system health monitoring

## Tech Stack

- **Framework:** Laravel (PHP)
- **Queue:** Database-backed jobs
- **API:** RESTful JSON
- **Messaging:** WhatsApp Evolution API

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan queue:work
```
