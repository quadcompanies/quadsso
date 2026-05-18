# Changelog

All notable changes to QuadSSO will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
