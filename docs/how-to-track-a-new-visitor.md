---
description: "SimpleStats tracks new visitors automatically and GDPR compliant, using a daily salted visitor hash instead of storing IPs or user agents."
---

# How to track a new visitor

Once you have installed the _SimpleStats_ [client package](installation.md#composer),
visitor tracking is set up **automatically** —
no additional configuration is required on your part.
We ensure
this process is [**GDPR**-compliant](https://simplestats.io/compliance/gdpr) by generating a unique `visitor_hash` if a new visitor visits your site.

## The Visitor Hash

The hash is created from a combination of the visitor's `User-Agent`, the `IP address` and a `daily salt`.
This allows us to track unique visitors without storing any personally identifiable information,
keeping their data **safe** and **private**.

The `visitor_hash` enables seamless visitor identification across different sessions and provides insights into how your **marketing campaigns** and UTM parameters drive traffic.

With this, you gain a clear picture of which marketing efforts contribute most **effectively** to your site's growth, all while respecting _user privacy_.

::: tip
This privacy-first hashing approach is similar to what other analytics tools like **Plausible** and **Fathom Analytics** use. The key difference is that SimpleStats tracks entirely server-side, making it invisible to ad blockers. See [Counting Visitors](./counting-visitors.md) for how this affects your numbers versus other tools.
:::

::: info
Want to understand how visitor data flows into registrations and revenue? Check out [From Visitor to User to Payment](from-visitor-to-user-to-revenue.md).
:::
