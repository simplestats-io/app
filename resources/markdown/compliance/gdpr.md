# GDPR Compliance

Last updated: November 21, 2026

[Compliance overview](/compliance) / GDPR

The General Data Protection Regulation (GDPR) protects the personal data of people in the European Union and the European Economic Area. Together with the ePrivacy Directive (in Germany implemented by the TDDDG), it decides whether You need consent and a cookie banner for analytics.

SimpleStats is run by Laracraft GbR in Germany, hosted in Germany and built to collect as little personal data as possible.

## No Consent Banner for Analytics

The ePrivacy Directive requires consent before anything is stored on, or read from, a visitor's device, unless it is strictly necessary. SimpleStats does neither:

- It sets no cookies and uses no local storage or other browser storage.
- Tracking runs on Your server inside Your application. No script of Ours runs in the visitor's browser.
- With the default `session` storage, the attribution data lives in the server-side session that Your Laravel application already uses, no additional cookie is set. With the `cache` storage, not even a session is needed, see [Headless, SPA & Stateless](/docs/headless-stateless-spa.html).

## Data Minimization

- **IP address and User-Agent** are processed only for a moment to build the visitor hash, to detect bots and to derive the country and device type. They are never stored.
- **Visitor hash:** a pseudonymous identifier built in Your application from IP address, User-Agent, the current date and Your application's secret key. It changes every day and cannot be traced back to the IP address.
- **User identifier:** the id of a registered user in Your application, typically the primary key. We cannot link it to a person, only You can.
- **No profiles across sites:** the hash is different for every site and every day, so visitors cannot be followed across sites, days or devices.

The full list of data categories is part of the [Data Processing Agreement](/dpa), Section 3.

## Roles and Data Processing Agreement

You are the controller of the analytics data, We are Your processor under Art. 28 GDPR. Our [Data Processing Agreement](/dpa) applies automatically when You create an account, no separate signature is needed. It covers Our obligations, the technical and organizational measures and the sub-processors.

## Hosting and Transfers

The application, the database and the backups are hosted by Hetzner Online GmbH in Germany. Sub-processors outside the EU/EEA are only used where they are listed in the DPA, and those transfers are safeguarded by the EU Standard Contractual Clauses or an adequacy decision. The current list of sub-processors is in Section 6 of the [DPA](/dpa).

## Rights of Your Visitors

Because SimpleStats does not store IP addresses and the visitor hash rotates daily, a visitor's analytics data cannot be found again from the visitor's identity. Requests from Your visitors and users go to You as the controller. If a request concerns a registered user, contact Us with the user identifier and We assist You as described in the DPA. When You delete a project, a team or Your account, the associated analytics data is deleted.

## What You Need to Do

- Mention SimpleStats in Your privacy notice, for example as server-side analytics processed by Laracraft GbR on Your behalf.
- Do not send names, email addresses or other directly identifying data in user identifiers, custom events or custom properties.
- Most companies rely on their legitimate interest (Art. 6(1)(f) GDPR) for privacy-friendly analytics like this. Check the legal basis with Your own advisor.

## Not Legal Advice

This page explains how SimpleStats is built. It is not legal advice.
