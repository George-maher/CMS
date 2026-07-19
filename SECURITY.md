# Security Policy — Church Management System (CMS)

## ⚠️ Proprietary Software Notice

The Church Management System (CMS) is **proprietary commercial software**.
Unauthorized access, copying, modification, or distribution is prohibited.

## Supported Versions

| Version | Supported          | Commercial Support |
|---------|-------------------|-------------------|
| 1.x     | ✅ Yes            | ✅ Available      |

## Reporting a Vulnerability

We take the security of the Church Management System seriously. If you
discover a security vulnerability, please follow these steps:

1. **DO NOT** disclose the vulnerability publicly or in public GitHub issues.
2. Send details to: **security@churchmanager.app**
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Affected versions
   - Potential impact
   - Any suggested mitigation (if known)

### Response Timeline

- **Acknowledgment**: Within 48 hours
- **Initial Assessment**: Within 5 business days
- **Fix Timeline**: Depending on severity, typically 7–30 days
- **Public Disclosure**: After a fix is deployed and customers are notified

## Security Measures

### Authentication & Authorization
- Token-based authentication via Laravel Sanctum
- Role-based access control (Platform Admin, Admin, Servant, Member)
- Permission-based authorization with dedicated middleware
- Hidden platform admin login path with rate limiting
- Email verification required for all new accounts

### Data Protection
- All passwords hashed using bcrypt (12 rounds minimum)
- Church-level data isolation via BelongsToChurch trait
- Soft deletes on users and churches for data safety
- Audit logging for sensitive operations
- Rate limiting on all API endpoints (28 named limiters)

### QR Security
- QR tokens are 64-character random strings (no sensitive data)
- Configurable expiration (default 4 hours)
- Single-use/multi-use with race condition prevention
- Optimistic locking on token usage

### API Security
- All authenticated routes require valid Sanctum token
- CORS restricted to allowed origins
- SQL injection prevented by Eloquent ORM
- XSS protection via React's default escaping
- Form Request validation on all inputs

### Infrastructure
- HTTPS required in production
- Security headers configured in Nginx
- CORS credentials enabled for SPA authentication
- Rate limiting prevents brute force and DDoS attacks

## Commercial Deployment

For commercial deployments, additional security requirements apply:

- Environment variables must use strong, unique values
- Database credentials must be restricted to least-privilege
- SSL/TLS must be enforced at the load balancer level
- Regular security audits are recommended
- License validation is enforced for commercial use

## Contact

- **Security**: security@churchmanager.app
- **Licensing**: legal@churchmanager.app
- **Commercial Support**: enterprise@churchmanager.app

© 2026 Church Management System. All Rights Reserved.
