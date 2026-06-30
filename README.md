# Borderless Bazzar Backend

MySQL-only PHP backend for Borderless Bazzar.

## Requirements

- PHP 8.2+
- PHP extensions: `pdo_mysql`, `curl`, `dom`, `json`
- MySQL 8+

## Setup

```bash
cd backend
copy .env.example .env
php bin/migrate.php
php -S 127.0.0.1:8080 -t public
```

Create the MySQL database first:

```sql
CREATE DATABASE borderless_bazzar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## API

### Auth

```http
POST /api/auth/register
POST /api/auth/login
GET /api/me
```

Use `Authorization: Bearer <token>` for protected routes.

### Product scraper

```http
POST /scrape-product
Content-Type: application/json

{ "url": "https://www.amazon.in/dp/..." }
```

The Expo app can point to it with:

```env
EXPO_PUBLIC_PRODUCT_SCRAPER_URL=https://your-domain.com/scrape-product
```

### Cart and orders

```http
GET /api/cart
POST /api/cart
PATCH /api/cart/items/{id}
DELETE /api/cart/items/{id}
GET /api/orders
POST /api/orders
POST /api/quote-requests
```

## Admin

Open:

```text
/admin
```

Default admin is created by `bin/migrate.php` from:

```env
ADMIN_EMAIL=
ADMIN_PASSWORD=
```

## Notes

This scraper uses PHP cURL + DOM parsing. It does not execute JavaScript. For stores that hide price behind client-side rendering or anti-bot flows, add a browser automation service later and keep this API contract unchanged.
