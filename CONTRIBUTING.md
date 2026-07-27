# Contributing

## Getting Started

1. Fork the repository
2. Clone your fork
3. Copy `.env.example` to `.env` and configure
4. Run `composer install`
5. Run `php tools/install.php` (CLI only)
6. Start development

## Development Guidelines

- Follow PSR-12 coding standards
- Use `declare(strict_types=1)` in all PHP files
- Write CLI-only tools with `PHP_SAPI !== 'cli'` guard
- Do not commit `.env`, logs, or uploaded files
- Run `php tools/pre_open_source_check.php --runtime` before submitting

## Security

- Never hardcode credentials, keys, or secrets
- Use `random_bytes()` for cryptographic randomness
- Use `password_hash()` / `password_verify()` for passwords
- Use `hash_equals()` for hash comparison
- All sensitive data must be encrypted with `encrypt_value()`

## Pull Request Process

1. Ensure all PHP files pass `php -l`
2. Run `composer validate` and `composer audit`
3. Update documentation if needed
4. Add migration files for schema changes

## Code of Conduct

Be respectful and constructive. This is an open-source project for educational institutions.