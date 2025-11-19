# PayTo Backend

A comprehensive financial management API built with Laravel 12, designed to handle multi-company invoice management, payment tracking, collections, and AFIP integration for Argentine businesses.

![PayTo Dashboard Detail](../docs/images/dashboard-detail.png)
*Advanced financial analytics and reporting*

## 🎯 Overview

PayTo Backend is a robust REST API that powers the PayTo financial platform. It manages complex multi-company operations including:

- **Invoice Management**: Create, track, and manage invoices with full AFIP integration
- **Payment & Collection Tracking**: Register and confirm payments/collections with company isolation
- **Multi-Company Support**: Complete data isolation between companies with role-based access control
- **AFIP Integration**: Electronic invoice validation and CAE management
- **Audit Logging**: Complete audit trail for all financial operations
- **Notification System**: Real-time notifications for invoice and payment events
- **Network Management**: B2B connections and invoice sharing between companies

## 🛠 Tech Stack

### Core Framework
- **Laravel 12** - Modern PHP framework with elegant syntax
- **PHP 8.2+** - Latest PHP version with strong typing support
- **MySQL/PostgreSQL** - Relational database

### Key Libraries
- **Laravel Sanctum** - API authentication and token management
- **Symfony HTTP Client** - HTTP requests for AFIP integration
- **Mailgun** - Email delivery service
- **DomPDF** - PDF generation for invoices
- **QR Code & Barcode** - Invoice encoding

### Testing & Development
- **Pest PHP** - Modern testing framework
- **Laravel Pint** - Code style fixer
- **Faker** - Test data generation
- **Laravel Sail** - Docker development environment

## 📋 Features

### Invoice Management
- Create issued and received invoices
- Support for multiple invoice types (FA, FB, FC, ND, NC)
- AFIP validation and CAE tracking
- Invoice approval workflows
- Credit and debit notes management
- PDF generation and download

### Payment & Collection System
- Register payments with automatic status updates
- Collection tracking with withholding management
- Multi-currency support (ARS, USD, EUR)
- Payment method tracking
- Automatic retention calculations
- Company-isolated payment visibility

### Financial Analytics
- Real-time dashboard with KPIs
- Accounts receivable tracking
- Accounts payable management
- VAT balance calculations
- Overdue invoice alerts
- Period-based financial reports

### AFIP Integration
- Electronic invoice validation
- CAE (Código de Autorización Electrónica) management
- Certificate-based authentication
- Real-time AFIP status synchronization
- Error handling and retry logic

### Multi-Company Features
- Complete data isolation per company
- Role-based access control (Admin, Manager, Viewer)
- Company member management
- Network connections between companies
- Shared invoice access with permissions

### Audit & Compliance
- Complete audit logging of all operations
- User action tracking
- Change history for critical operations
- Compliance reporting

## 🚀 Getting Started

### Prerequisites
- PHP 8.2 or higher
- Composer
- MySQL 8.0+ or PostgreSQL 12+
- Docker (optional, for Sail)

### Installation

1. **Clone the repository**
```bash
git clone <repository-url>
cd payto-back
```

2. **Install dependencies**
```bash
composer install
```

3. **Setup environment**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Configure database**
Edit `.env` with your database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=payto
DB_USERNAME=root
DB_PASSWORD=
```

5. **Run migrations**
```bash
php artisan migrate
```

6. **Start the server**
```bash
php artisan serve
```

The API will be available at `http://localhost:8000`

### Using Docker (Laravel Sail)

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

## 📁 Project Structure

```
payto-back/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/        # API endpoints
│   │   ├── Middleware/             # Custom middleware
│   │   └── Requests/               # Form validation
│   ├── Models/                     # Eloquent models
│   ├── Services/                   # Business logic
│   ├── Repositories/               # Data access layer
│   └── Console/                    # Artisan commands
├── database/
│   ├── migrations/                 # Database schema
│   ├── factories/                  # Test data factories
│   └── seeders/                    # Database seeders
├── routes/
│   ├── api.php                     # Main API routes
│   └── api/                        # Grouped API routes
├── tests/
│   ├── Feature/                    # Feature tests
│   └── Unit/                       # Unit tests
└── resources/
    └── views/emails/               # Email templates
```

## 🔌 API Endpoints

### Authentication
- `POST /api/auth/register` - Register new user
- `POST /api/auth/login` - Login user
- `POST /api/auth/logout` - Logout user

### Companies
- `GET /api/companies` - List user's companies
- `POST /api/companies` - Create company
- `GET /api/companies/{id}` - Get company details
- `PUT /api/companies/{id}` - Update company

### Invoices
- `GET /api/companies/{id}/invoices` - List invoices
- `POST /api/companies/{id}/invoices` - Create invoice
- `GET /api/companies/{id}/invoices/{invoiceId}` - Get invoice details
- `PUT /api/companies/{id}/invoices/{invoiceId}` - Update invoice

### Payments & Collections
- `POST /api/companies/{id}/payments` - Register payment
- `POST /api/companies/{id}/collections` - Register collection
- `GET /api/companies/{id}/payments` - List payments
- `GET /api/companies/{id}/collections` - List collections

### Financial Reports
- `GET /api/companies/{id}/accounts-receivable` - Receivable invoices
- `GET /api/companies/{id}/accounts-payable` - Payable invoices
- `GET /api/companies/{id}/dashboard` - Financial dashboard

### Network
- `GET /api/companies/{id}/network` - Network connections
- `POST /api/companies/{id}/network/requests` - Send connection request
- `POST /api/companies/{id}/network/requests/{id}/accept` - Accept request

## 🔐 Authentication

The API uses **Laravel Sanctum** for token-based authentication:

1. Register or login to get an API token
2. Include the token in request headers:
```bash
Authorization: Bearer YOUR_API_TOKEN
```

## 🧪 Testing

Run the test suite:

```bash
# Run all tests
composer test

# Run specific test file
php artisan test tests/Feature/InvoiceControllerTest.php

# Run with coverage
php artisan test --coverage
```

## 📊 Database Schema

The application uses a comprehensive relational database with multiple tables supporting multi-company operations.

**Key tables**: companies, invoices, payments, collections, clients, suppliers, audit_logs, notifications, and access control tables.

**Data isolation**: All tables include `company_id` for complete multi-company data separation enforced at the model level through query scopes.

## 🔄 Key Services

### InvoiceService
Handles invoice creation, validation, and status management with AFIP integration.

### PaymentService
Manages payment registration, confirmation, and automatic invoice status updates.

### CompanyService
Handles company operations and multi-company data isolation.

### NotificationService
Manages real-time notifications for invoice and payment events.

### AuditService
Logs all critical operations for compliance and debugging.

## 🚨 Error Handling

The API returns standardized error responses:

```json
{
  "success": false,
  "error": "Error message",
  "code": "ERROR_CODE"
}
```

Common HTTP status codes:
- `200` - Success
- `201` - Created
- `400` - Bad request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not found
- `422` - Validation error
- `500` - Server error

## 📝 Environment Variables

Key environment variables in `.env`:

```env
APP_NAME=PayTo
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=payto
DB_USERNAME=root
DB_PASSWORD=

MAIL_MAILER=mailgun
MAILGUN_DOMAIN=your-domain.com
MAILGUN_SECRET=your-secret

AFIP_CERT_PATH=/path/to/cert.pem
AFIP_KEY_PATH=/path/to/key.pem
```

## 🔗 Integration Points

### AFIP Integration
- Electronic invoice validation
- CAE authorization
- Real-time status checking
- Error handling and logging

### Email Service
- Invoice notifications
- Payment confirmations
- User invitations
- System alerts

## 🚀 Deployment

### Production Checklist
- [ ] Set `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Generate secure `APP_KEY`
- [ ] Configure database with strong credentials
- [ ] Set up SSL/HTTPS certificates
- [ ] Configure email service (Mailgun)
- [ ] Upload AFIP certificates securely
- [ ] Set up proper logging and monitoring
- [ ] Configure backup strategy
- [ ] Run migrations on production database
- [ ] Clear and cache configuration

### Docker Deployment
```bash
docker-compose -f docker-compose.yml up -d
docker-compose exec payto-back php artisan migrate
```

### Manual Deployment
1. Deploy code to production server
2. Install dependencies: `composer install --no-dev`
3. Configure `.env` with production values
4. Run migrations: `php artisan migrate --force`
5. Cache configuration: `php artisan config:cache`
6. Cache routes: `php artisan route:cache`

## ⚡ Performance Optimization

### Database
- Indexes on frequently queried columns
- Query optimization with eager loading
- Connection pooling for high traffic
- Regular database maintenance

### Caching
- Configuration caching in production
- Route caching for faster routing
- Query result caching where appropriate
- Redis for session and cache storage

### API Response
- Pagination for large datasets
- Selective field loading
- Response compression (gzip)
- Rate limiting to prevent abuse

## 🔒 Security Best Practices

- **Input Validation**: All inputs validated server-side
- **CSRF Protection**: Enabled on all state-changing requests
- **SQL Injection Prevention**: Using parameterized queries
- **XSS Protection**: Output escaping and sanitization
- **Authentication**: Token-based with Laravel Sanctum
- **Authorization**: Role-based access control
- **Audit Logging**: All sensitive operations logged
- **HTTPS**: Required for production

## 📚 Documentation

For detailed information:
- API endpoints: Check `routes/api.php`
- Models: See `app/Models/`
- Services: See `app/Services/`
- Repositories: See `app/Repositories/`
- Migrations: See `database/migrations/`

## 🤝 Contributing

1. Create a feature branch from `develop`
2. Make your changes following PSR-12 standards
3. Write tests for new features
4. Run tests: `composer test`
5. Run linter: `./vendor/bin/pint`
6. Submit a pull request with description

## 📄 License

This project is licensed under the MIT License.

## 🆘 Support

For issues and questions:
1. Check existing issues on GitHub
2. Review the documentation
3. Contact the development team

---

**Last Updated**: November 2025
**Version**: 1.0.0
**Laravel Version**: 12.x
**PHP Version**: 8.2+
