# Changelog

Všetky významné zmeny projektu sú dokumentované v tomto súbore.

Projekt dodržiava formát Keep a Changelog a Semantic Versioning.

---

## [Unreleased]

### Added

- Calendar events can now be linked with orders.
- Added onboarding setup checklist endpoint.
- Added daily overdue invoice digest notifications.
- Added OCR support for scanned PDF invoices.
- Expenses are now included in financial statistics.
- Added SEPA payment order export.
- Added settlement of paid proforma invoices.
- Added manual upload for Invoice Inbox.
- Added payment order batches for supplier invoices.

### Changed

- Invoice numbers are now assigned when an invoice is issued instead of when a draft is created.
- Improved invoice export and reporting.
- Improved invoice inbox processing.
- Improved statistics calculations.
- Improved payment workflow.

### Fixed

- Various bug fixes.
- Performance improvements.
- Minor API consistency improvements.

---

## [1.1.0] - 2026-07-09

### Added

- Customer/Vendor roles for clients.
- Configurable invoice numbering format.
- Pohoda XML export.
- CSV invoice export.
- Shared export filtering.
- Extended accounting export capabilities.

### Changed

- Improved export performance.
- Updated API documentation.

### Fixed

- Minor stability improvements.

---

## [1.0.0] - 2026-07-08

### Added

- Initial open-source release.
- Laravel 13 backend.
- Modular architecture.
- REST API.
- User authentication.
- Client management.
- Orders.
- Time tracking.
- Invoicing.
- PDF invoice generation.
- Dashboard.
- Integrations with public business registries.
