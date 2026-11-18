# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 0.x (latest minor) | Yes |

## Reporting a vulnerability

Please do not report security issues in public GitHub issues or pull requests.

Use GitHub's private vulnerability reporting ("Report a vulnerability" under the repository's
Security tab). Include:

- a description of the issue and its impact,
- steps or code to reproduce,
- affected versions and gateways.

You will receive an acknowledgement within 72 hours. Confirmed issues are fixed in a patch
release and disclosed after users have had a reasonable time to upgrade.

## Scope

In scope: signature verification, amount/reference checks, callback handling, leakage of
secret keys, and anything that could cause an unpaid order to be reported as paid.

Out of scope: vulnerabilities in Khalti or eSewa themselves (report those to the gateway),
and applications that do not follow the security checklist in the README.
