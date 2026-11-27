---
description: "Connect Google Search Console to SimpleStats and see which Google keywords bring visitors to your Laravel app, with clicks, ranking and estimated revenue per keyword."
---

# Google Search Console

Since 2011 Google hides the search term of a visitor ("not provided"). No analytics tool can read it from the visit itself. Google only reports it in [Search Console](https://search.google.com/search-console), as numbers per keyword and page.

_SimpleStats_ imports these numbers and shows them next to your other channels: which keywords bring clicks, how you rank for them and how much revenue the landing pages behind them make.

## What you get

A **Keywords** tab in the **Tracking** card of the dashboard. For every keyword in the selected date range:

| Column | Meaning |
|--------|---------|
| **Clicks** | Clicks from Google search results to your site |
| **Impressions** | How often your site appeared in the results for this keyword |
| **CTR** | Share of impressions that led to a click |
| **Position** | Average ranking in the results (lower is better) |
| **Est. Revenue** | Estimated revenue of the keyword (only when you track payments, see below) |

The Keywords tab follows the project and date range of the dashboard and the **Entry Page** filter: click an entry page to see the keywords that lead there. Other filters (country, device, campaign, ...) do not apply, because Search Console reports totals, not single visitors.

You can also ask the **Ask AI** assistant or your [MCP client](mcp-server.md) about your keywords, e.g. _"Which Google keywords brought the most clicks last month?"_

## Estimated revenue

Google does not tell which visitor searched for which keyword, so _SimpleStats_ estimates it from the landing page:

1. It takes the revenue of the people who entered on a page (their payments in the selected range).
2. It gives each keyword the share of that page's traffic its Google clicks make up.

A page with 1,000 € revenue and 400 visitors, 100 of them from Google clicks on "laravel analytics", gives that keyword 250 €. If Google brings only a small part of a page's visitors, keywords only get that part of the revenue. Keywords that lead to several pages add up their shares.

Use it to compare keywords with each other, not as exact revenue.

## Connect your project

You need a verified property in [Google Search Console](https://search.google.com/search-console) for your site. Administrators and editors of a team can connect it:

1. Open **Projects**, then your project, and find the **Google Search Console** section.
2. Click **Connect Google Search Console** and sign in with a Google account that has access to the property. On the Google screen, tick **View Search Console data for your verified sites**. _SimpleStats_ only asks for read access to your Search Console data.

The property matching your project's domain is linked automatically. If there is none, or several fit, you pick it from a list. For another project, connect the same Google account with one click, no second Google sign-in needed.

_SimpleStats_ then imports up to 16 months of history (this takes a few minutes) and updates the keywords once a day.

::: tip
The Google account does not have to be the one you sign in to _SimpleStats_ with. Many teams use a shared webmaster account that owns the property.
:::

::: info
Search Console data is about two days behind. Today's and yesterday's keywords show up after that. The days are Google's days (Pacific Time), not the timezone of your team.
:::

## Reconnect or disconnect

If you remove the access in your Google account or lose access to the property, the project page asks you to **Reconnect**. The keywords already imported stay until then.

**Disconnect** deletes the imported keywords of the project and revokes the access at Google. To switch to another property, disconnect and connect again.

## Self-hosted

On a self-hosted installation the section only appears once you configured your own Google OAuth client:

1. Create a project in the [Google Cloud Console](https://console.cloud.google.com/) and enable the **Google Search Console API**.
2. Configure the OAuth consent screen and add the scope `https://www.googleapis.com/auth/webmasters.readonly`.
3. Create an OAuth client ID of type **Web application** with the authorized redirect URI `https://<your-domain>/search-console/callback`.
4. Add the credentials to your `.env` and restart the containers:

```dotenv
GSC_GOOGLE_CLIENT_ID=your-client-id
GSC_GOOGLE_CLIENT_SECRET=your-client-secret
```

The daily import runs in the scheduler and the queue workers you already have.
