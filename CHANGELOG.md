# Changelog

All notable changes to QuadSSO will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-05-18

### 🔒 CRITICAL SECURITY FIX

- **Fixed:** SCIM endpoints are now automatically secured with bearer token authentication by default
- **Added:** Auto-configuration of `ScimBearerToken` middleware in `QuadSSOServiceProvider`
- **Impact:** Previously, SCIM endpoints were publicly accessible until manually configured. Users should upgrade immediately.

### Added

- `status` column now automatically created by migration with default value 'active'
- Security verification steps added to QUICK_START.md
- Enhanced security documentation in README.md with verification commands
- Command to generate secure SCIM bearer tokens in documentation
- Laravel 13.x support
- PHP 8.1, 8.2, 8.3 support

### Changed

- Updated QUICK_START.md to emphasize security best practices
- Improved migration to include all required fields (scim_external_id, email_verified_at, status)
- Updated documentation to clarify that migrations handle all field creation automatically
- Broadened Laravel support to 10.x, 11.x, 12.x, and 13.x
- Changed to use `illuminate/contracts` instead of individual illuminate packages

### Fixed

- Users no longer show `"active": false` by default (status field now has proper default)
- Removed need for manual SCIM middleware configuration
- Removed need for manual status field migration
- Resolved composer installation conflicts with Laravel framework

## [1.0.3] - 2026-05-18

### Added
- Laravel 13.x support

## [1.0.2] - 2026-05-18

### Changed
- Broadened PHP support to 8.1, 8.2, and 8.3
- Broadened Laravel support to 10.x, 11.x, and 12.x
- Relaxed dependency version constraints

## [1.0.1] - 2026-05-18

### Fixed
- Changed to use `illuminate/contracts` instead of individual illuminate packages
- Resolved composer installation conflicts

## [1.0.0] - 2024-01-01

### Added
- Initial release
- SCIM user provisioning with Authentik
- SSO authentication via OAuth/OIDC
- Single Logout (SLO) support with back-channel logout
- Configurable user creation, updates, and deletion via SCIM
- Automatic session invalidation when users are blocked
- Flexible field mappings for User model
- Comprehensive configuration options
- Detailed logging for debugging
- Support for Laravel 11.0 and 12.0
- Migration for adding SCIM fields to users table
- Bearer token authentication for SCIM endpoints
- Email verification on first SSO login
- Remember token invalidation on SLO

### Security
- Secure bearer token validation for SCIM requests
- JWT signature verification for SLO tokens
- Constant-time comparison for bearer tokens
- Session invalidation on user block/logout
