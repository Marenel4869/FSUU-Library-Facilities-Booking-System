# FSUU-Library-Facilities-Booking-System

## Modular PHP Structure

This codebase is organized using reusable include modules to keep pages clean:

- `includes/bootstrap.php`: central app bootstrap (`config`, constants, session, shared functions).
- `includes/layout.php`: reusable page rendering helpers (`renderPageStart`, `renderPageEnd`).
- `includes/functions.php`: shared utility and security functions.
- `includes/report_helpers.php`: reusable report/date-range helper functions for admin analytics.

Most entry pages now include only `bootstrap.php` instead of repeating four setup includes.

## Security Notes

1. Use HTTPS in production:
- Set APP_URL to an https URL.
- Set ENFORCE_HTTPS=1 so non-HTTPS requests are redirected.
- Keep TRUST_PROXY_HTTPS_HEADER enabled only when behind a trusted reverse proxy.

2. Run production mode safely:
- Set APP_ENV=production to hide PHP errors from users.

3. Database safety:
- Use PDO prepared statements for all user-influenced queries.
- Keep PDO::ATTR_EMULATE_PREPARES disabled.

4. File upload safety:
- Supporting documents are validated using extension and MIME checks.
- Allowed files: PDF and image formats only.
- Uploaded files should stay under uploads/requests and should not be executable.

5. Access control:
- Every module page should enforce role checks with requireRole.