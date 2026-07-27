# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

Please report security vulnerabilities **privately** by emailing the project maintainer.
Do NOT open a public issue for security vulnerabilities.

### What to include

- Description of the vulnerability
- Steps to reproduce
- Affected versions
- Possible impact

### What NOT to do

- Do NOT upload real student data in issues or discussions
- Do NOT commit `.env` files
- Do NOT upload Excel files, logs, or database dumps
- Do NOT include real credentials, API keys, or tokens

## Credential Exposure

If you accidentally committed credentials:

1. Rotate the exposed credentials immediately
2. Purge the commit history or request a force-push
3. Notify the maintainer

## Security Best Practices for Deployers

1. Set a strong `APP_KEY` using `php tools/generate_app_key.php`
2. Keep `.env` out of version control
3. Use HTTPS in production
4. Configure Turnstile for bot protection
5. Set up SMTP for email verification
6. Regularly review admin accounts
7. Keep PhpSpreadsheet and other dependencies updated