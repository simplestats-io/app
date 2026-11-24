export const campaignMetricInfo = {
    runs: {title: 'Runs', text: 'First to last day covered by the ad spend you entered for this campaign, independent of the selected period. A campaign that ran outside the selected period shows dashes instead of zeros.'},
    spend: {title: 'Ad Spend', text: 'The share of this campaign\'s ad spend that falls into the selected period. Each entry\'s budget is spread evenly across its days, so a period covering only part of a run counts only those days. Entered manually via "Add ad spend".'},
    visitors: {title: 'Visitors', text: 'Unique visitors who arrived through this campaign\'s linked utm_campaign values during the selected period.'},
    costPerVisitor: {title: 'Cost Per Visitor', text: 'Ad spend divided by visitors. What one visitor cost you in the selected period, shown only when there is spend and at least one visitor.'},
    registrations: {title: 'Registrations', text: 'New sign-ups from this campaign\'s visitors during the selected period. The same registrations the CAC is based on.'},
    bounceRate: {title: 'Bounce Rate', text: 'Share of visitors with page views who left after a single page. Lower is better: it tells you early on whether the traffic is any good, before revenue comes in.'},
    revenue: {title: 'Net Revenue', text: 'Net revenue (after fees) to date from the customers this campaign acquired during the selected period, including what they paid afterwards. Choose "All Time" to see whether a campaign paid off overall.'},
    netProfit: {title: 'Net Profit', text: 'Revenue minus ad spend. Green means the campaign earned more than it cost, red means it cost more than it earned.'},
    roas: {title: 'Return On Ad Spend (ROAS)', text: 'Revenue divided by spend. 2.00× means you earned 2 € back for every 1 € spent. Above 1.00× is profitable.'},
    cac: {title: 'Customer Acquisition Cost (CAC)', text: 'Ad spend divided by new registrations. The average cost of acquiring one new sign-up, shown only when there is spend in the selected period. Coloured by the LTV:CAC ratio: green when a customer is worth far more than they cost.'},
    ltvCac: {title: 'LTV : CAC Ratio', text: 'A subscriber\'s lifetime value divided by what it cost to acquire them. 3.00× means each customer is worth three times their acquisition cost. ≥3.00× is healthy, below 1.00× means you lose money on every customer.'},
    cacPayback: {title: 'CAC Payback', text: 'Months until a campaign\'s customers earn back their acquisition cost through recurring revenue. Under 12 months is healthy, over 24 months ties up cash for too long.'},
};
