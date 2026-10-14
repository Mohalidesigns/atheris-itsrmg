# Atheris ITSRM&G

A multi-tenant IT security risk and GRC platform for Nigerian banks: CBN-CSAT, NDPC and ITSB regulatory returns, Enterprise Architecture, TPRM, CCM, KRIs and more. Built with Laravel 13, Inertia 2, React 18 and Tailwind 3. Developer guidance is in [`CLAUDE.md`](CLAUDE.md).

## Module validation programme

Each sidebar module is validated end to end, in order: its features, forms, tables, modals and record relationships, and how it feeds the dashboards. Findings are fixed, written up and locked in with a feature test. The working handoff, including how to start the next module in a new session, is [`docs/VALIDATION_HANDOFF.md`](docs/VALIDATION_HANDOFF.md).

| # | Module | Status | Report |
|---|---|---|---|
| 1 | IT Risk Management | Done | [ATH-VAL-001](docs/ATH-VAL-001_IT_Risk_Management_Validation_Report.md) |
| 2 | CBN Cyber Assessment (CSAT) | Done | [ATH-VAL-002](docs/ATH-VAL-002_CBN_Cyber_Assessment_Validation_Report.md) |
| 3 | Compliance | Done | [ATH-VAL-003](docs/ATH-VAL-003_Compliance_Validation_Report.md) |
| 4 | Regulatory Intelligence | Done | [ATH-VAL-004](docs/ATH-VAL-004_Regulatory_Intelligence_Validation_Report.md) |
| 5 | Security Operations | Done | [ATH-VAL-005](docs/ATH-VAL-005_Security_Operations_Validation_Report.md) |
| 6 | Issues & Remediation | Done | [ATH-VAL-006](docs/ATH-VAL-006_Issues_Remediation_Validation_Report.md) |
| 7 | Asset Management | Done | [ATH-VAL-007](docs/ATH-VAL-007_Asset_Management_Validation_Report.md) |
| — | Change Management (new module, built to [ATH-CHG-001](docs/ATH-CHG-001_Change_Management_Module_Specification_v1.0.md)) | Built & validated | [ATH-CHG-002](docs/ATH-CHG-002_Change_Management_Build_Update.md) |
| 8 | Vendor Management | Done | [ATH-VAL-008](docs/ATH-VAL-008_Vendor_Management_Validation_Report.md) |
| 9 | Policy Management | Done | [ATH-VAL-009](docs/ATH-VAL-009_Policy_Management_Validation_Report.md) |
| 10 | ISMS | Done | [ATH-VAL-010](docs/ATH-VAL-010_ISMS_Validation_Report.md) |
| 11 | PCI Management | Done | [ATH-VAL-011](docs/ATH-VAL-011_PCI_Management_Validation_Report.md) |
| 12 | Business Continuity | Done | [ATH-VAL-012](docs/ATH-VAL-012_Business_Continuity_Validation_Report.md) |
| 13 | Continuous Monitoring | **Next** | — |
| 14+ | KRIs & Dashboards → rest of sidebar | To do | — |

Last full PHP suite: 300 tests, 299 passed, 1 skipped (the route smoke test, run separately over 205 routes), 3,084 assertions (14 Oct 2026, local, PHP 8.4).

## Quick start

```bash
composer install && npm install --legacy-peer-deps
php artisan migrate:fresh --seed   # both demo banks; all demo passwords are "password"
composer dev                       # serve + queue + logs + vite
composer test                      # PHP suite
```

Demo logins: `admin@kanoheritage.ng` (Kano Heritage Bank, plus the platform layer) and `admin@firstbanknigeria.ng` (First Bank, plus the EA repository).

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
