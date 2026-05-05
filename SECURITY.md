# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 2.0.x (alpha) | ✅ Active development |
| 1.0.x | Security fixes only |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Send a report to **ashiqfardus@hotmail.com** with:

1. Description of the vulnerability
2. Steps to reproduce
3. Potential impact
4. Suggested fix (optional)

You will receive a response within **72 hours**. If the issue is confirmed, a fix will be released within **90 days** and you will be credited in the CHANGELOG (unless you prefer to remain anonymous).

## Scope

This package exposes user-controlled input to SQL queries. The following areas are in scope:

- SQL injection via column names, search terms, or algorithm names
- XSS via highlighted output (`->highlight()`)
- DoS via adversarial extended-query strings (max_depth / max_tokens guards)
- Cache poisoning via colliding cache keys

## Out of Scope

- Vulnerabilities in Laravel itself or its dependencies
- Issues requiring attacker access to the application's config or source code
