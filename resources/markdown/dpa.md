# Data Processing Agreement

Last updated: November 21, 2026

This Data Processing Agreement ("DPA") is an addendum to the [Terms and Conditions](/terms-of-service) between Laracraft GbR, Brunnengasse 12, D-87629 Füssen ("We", "Us", the "Processor") and the customer using the hosted service at simplestats.io ("You", the "Controller"). It applies automatically when You create an account, no separate signature is required.

This DPA only covers the hosted service. If You self-host the open source version of the software, no personal data is processed by Us and this DPA does not apply.

## 1. Subject Matter and Duration

We process personal data on Your behalf to provide the analytics service described in the Terms and Conditions: receiving tracking events from Your applications (through the client packages or the API), deriving analytics from them and displaying them to You.

The processing lasts as long as You use the service. Section 9 describes what happens when it ends.

## 2. Roles

You are the controller of the personal data You send to the service. We are Your processor within the meaning of Art. 28 of the General Data Protection Regulation (GDPR) and process the data only on Your documented instructions. The Terms and Conditions, this DPA and Your configuration of the service (for example which events You track) are Your instructions.

Where the California Consumer Privacy Act, as amended by the California Privacy Rights Act (CCPA), applies, We act as Your service provider. We do not sell or share the personal information, and We do not retain, use or disclose it for any purpose other than providing the service to You, nor outside of Our direct business relationship with You. We do not combine it with personal information We receive from other sources, except as permitted by the CCPA.

We process data of Your own account (name, email, billing details) as an independent controller. That processing is covered by Our [Privacy Policy](/privacy-policy), not by this DPA.

## 3. Data Subjects and Categories of Data

**Data subjects:** visitors of Your websites and applications, and the registered users and customers of Your applications.

**Categories of personal data:**

- **IP address and User-Agent:** processed only transiently to build the daily visitor hash, to detect bots and to derive the country and device type. They are never stored.
- **Visitor hash:** a pseudonymous identifier derived from IP address, User-Agent, the current date and a secret key. It changes every day and cannot be traced back to the IP address.
- **User identifier:** the identifier of a registered user that Your application sends (typically the primary key of Your users table). We cannot link it to a person, only You can.
- **Event data:** registration, login and payment timestamps, payment amounts and currency, subscription interval.
- **Attribution data:** referrer, UTM parameters and entry page.
- **Derived data:** country, region, city, browser, operating system and device type.
- **Custom events and custom properties:** any additional data You choose to send.

No special categories of personal data (Art. 9 GDPR) are processed. You must not send them.

## 4. Your Obligations

You are responsible for the lawfulness of the processing, in particular for having a legal basis, for informing the data subjects in Your privacy notice and for handling their requests.

Send only the data the service needs. Do not include names, email addresses or other directly identifying information in user identifiers, custom events or custom properties.

## 5. Our Obligations

We will:

- process the personal data only on Your documented instructions, unless required otherwise by Union or Member State law, in which case We inform You beforehand where legally permitted
- ensure that everyone authorized to process the personal data is bound to confidentiality
- implement the technical and organizational measures described in Section 8
- assist You, taking into account the nature of the processing, in responding to data subject requests and in meeting Your obligations under Art. 32 to 36 GDPR
- notify You without undue delay after becoming aware of a personal data breach affecting Your data
- make available to You the information necessary to demonstrate compliance with Art. 28 GDPR and allow for and contribute to audits, conducted with reasonable notice and at Your cost

## 6. Sub-processors

You authorize Us to engage the sub-processors listed below. We bind every sub-processor to data protection obligations equivalent to those in this DPA.

| Sub-processor | Purpose | Location |
|---------------|---------|----------|
| Hetzner Online GmbH | Hosting of the application and database, storage of backups | Germany |
| Artia International S.R.L. (ip-api.com) | Location lookup and datacenter detection of IP addresses (IP address only, not stored) | Romania |
| Functional Software, Inc. (Sentry) | Error monitoring | EU (Germany) |
| Google Ireland Ltd. / Google LLC (Gemini API) | Optional AI assistant, aggregated analytics only | EU / USA |

We announce new or replaced sub-processors on this page at least 30 days in advance. You may object on reasonable data protection grounds within that period. If We cannot address the objection, You may terminate the affected service.

## 7. International Transfers

Where personal data is transferred to a country outside the EU/EEA without an adequacy decision, the transfer is safeguarded by the EU Standard Contractual Clauses or another mechanism under Chapter V GDPR.

## 8. Technical and Organizational Measures

- **Data minimization:** IP addresses and User-Agents are never stored. Visitors are identified by a hash that rotates daily, location lookups are cached under a daily rotating hash that contains no IP address.
- **Encryption in transit:** all traffic to the service and to the API uses TLS.
- **Access control:** access to production systems is restricted to authorized personnel. Server access is only possible with SSH keys, password logins are disabled. The hosting and deployment accounts are protected by two-factor authentication.
- **Tenant separation:** every query is scoped to the team that owns the data, data of different customers is never mixed.
- **Availability:** monitoring of the infrastructure and daily database backups, encrypted with AES-256 and stored on the server and in a separate object storage within Germany.
- **Pseudonymous identifiers:** public URLs and API responses use random identifiers instead of internal ids.

## 9. Deletion and Return

When You delete a project, a team or Your account, the associated analytics data is deleted. Accounts that are deleted for inactivity are removed permanently after a grace period of two months. Before deleting Your account, You can export Your data through the API.

Backups are not modified retroactively. Deleted data disappears from them as they rotate out, at the latest after two years.

## 10. Precedence

In case of conflict between this DPA and the Terms and Conditions regarding the protection of personal data, this DPA prevails.

## Contact

For questions about this DPA or data protection, contact Our Data Protection Officer, Elisabeth Wintergerst, Rechtsanwältin, Brunnengasse 12, 87629 Füssen, or email simplestats@laracraft.tech.
