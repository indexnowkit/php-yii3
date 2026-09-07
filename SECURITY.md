# Security

This package is a thin adapter over `indexnowkit/core`; the security notes of the core package apply
(key handling, URL validation, HTTP limits): https://github.com/indexnowkit/php/blob/main/packages/core/SECURITY.md

Yii3-specific: the key file handler answers GET only, starts no session, needs no CSRF token and serves only the key
of the requested host. The `indexnow:config` command masks the key, `previous_key`, `key_location` and every DSN of
the optional packages before printing.

Report vulnerabilities privately via [GitHub security advisories](https://github.com/indexnowkit/php/security/advisories/new)
or to i.pinchuk.work@gmail.com. Please do not open public issues for security reports. Reports are acknowledged within 5 business days; a fix or a mitigation plan follows within 30 days.
