# Security

Last updated: November 21, 2026

This page describes how We protect the data You send to SimpleStats. The binding version of these measures is part of Our [Data Processing Agreement](/dpa), Section 8. For the legal side, see [Compliance](/compliance).

## Data Minimization

The safest data is data that is never stored. IP addresses and User-Agents are only processed for a moment and never stored in the database. Visitors are identified by a hash that is built in Your application with Your own secret key and rotates every day. Location lookups are cached under a daily rotating hash that contains no IP address.

## Hosting and Infrastructure

- The application, the database and the backups are hosted by Hetzner Online GmbH in Germany.
- Hetzner's data centers are certified according to ISO/IEC 27001.
- We monitor the infrastructure and publish its availability on Our [status page](https://status.simplestats.io).

## Encryption

- All traffic to the service, the dashboard and the API uses TLS.
- Backups are encrypted with AES-256.

## Backups

The database is backed up every day. The backups are stored on the server and in a separate object storage within Germany. Deleted data disappears from the backups as they rotate out, at the latest after two years.

## Access Control

- Access to production systems is restricted to authorized personnel.
- Server access is only possible with SSH keys, password logins are disabled.
- The hosting and deployment accounts are protected by two-factor authentication.

## Your Account

- You can sign in with a passkey instead of a password.
- Email addresses are verified before an account can be used.
- Team members can be invited with their own account, so nobody needs to share credentials.
- Every query is scoped to the team that owns the data, data of different customers is never mixed.
- Public URLs and API responses use random identifiers instead of internal ids.

## Payments

Payments are handled by Paddle, Our merchant of record. Your card or bank details are entered at Paddle and never reach Our servers.

## Sub-processors

We use as few sub-processors as possible. The current list, with the purpose and location of each, is in Section 6 of the [Data Processing Agreement](/dpa). We announce changes at least 30 days in advance.

## Open Source

The SimpleStats code is public under the [GNU Affero General Public License v3.0](https://github.com/simplestats-io/app/blob/main/LICENSE). You and Your security team can review exactly how data is collected and stored. If You need full control, You can [self-host](/docs/self-hosted.html) SimpleStats, then no data reaches Us at all.

## Incident Response

If We become aware of a personal data breach affecting Your data, We notify You without undue delay, as set out in the DPA.

## Reporting a Vulnerability

If You discover a security vulnerability, please do not open a public issue. Email simplestats@laracraft.tech with a description and, if possible, steps to reproduce it. We will get back to You as soon as possible and keep You updated until the issue is resolved.

## Certifications

SimpleStats itself is not SOC 2 or ISO 27001 certified. If Your procurement process needs a security questionnaire, email simplestats@laracraft.tech.
