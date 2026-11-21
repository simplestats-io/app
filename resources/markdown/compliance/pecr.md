# PECR Compliance

Last updated: November 21, 2026

[Compliance overview](/compliance) / PECR

In the United Kingdom, two sets of rules apply to analytics. The UK GDPR and the Data Protection Act 2018 cover personal data in general. The Privacy and Electronic Communications Regulations (PECR) cover cookies and similar technologies: storing information on a visitor's device, or reading it from there, needs consent unless it is strictly necessary. This is the rule behind most cookie banners.

## No Cookie Banner for Analytics

SimpleStats does not store or read anything on Your visitors' devices:

- It sets no cookies and uses no local storage or other browser storage.
- Tracking runs on Your server inside Your application. No script of Ours runs in the visitor's browser.
- With the default `session` storage, the attribution data lives in the server-side session that Your Laravel application already uses, no additional cookie is set. With the `cache` storage, not even a session is needed, see [Headless, SPA & Stateless](/docs/headless-stateless-spa.html).

So PECR does not require a consent banner for SimpleStats. If Your website uses other cookies, for example for advertising or embedded content, those still need their own consent.

## UK GDPR

- IP addresses and User-Agents are never stored. Visitors are identified by a hash that rotates every day.
- We act as Your processor under the [Data Processing Agreement](/dpa), which applies automatically when You create an account.
- The data is hosted by Hetzner in Germany. The UK recognizes the EU/EEA as providing an adequate level of data protection, so no additional transfer mechanism is needed for data sent from the UK to Germany.

The details of how SimpleStats handles personal data are the same as under the EU GDPR, see [GDPR compliance](/compliance/gdpr).

## Not Legal Advice

This page explains how SimpleStats is built. It is not legal advice.
