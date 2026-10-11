# Merchant metric dictionary

All reporting dates are business-local calendar dates. Timestamps are stored in UTC and converted using the selected business timezone. Report day-range inputs accept 1–365 days. “Estimate” metrics expose their assumptions beside the displayed value.

| Metric | Definition / formula | Denominator | Range and timezone | Source |
|---|---|---|---|---|
| AI share of replies | Eligible AI customer-service replies ÷ eligible outbound customer-service replies × 100. Campaign, sequence, automation, proactive, and email-campaign sends are excluded. | Human + AI customer-service outbound replies | Selected range; business timezone | `messages`, `conversations` |
| Manual interventions | Eligible outbound non-AI customer-service messages. Automated and campaign sources are excluded. | None | Selected range; business timezone | `messages`, `conversations` |
| AI conversations | Distinct conversations with at least one eligible AI outbound reply in the selected range. | None | Selected range; business timezone | `messages`, `conversations` |
| Median response time | Median elapsed time from each inbound customer message to the first outbound human or AI reply before the next inbound message. Calculated with SQL and only paired messages are counted. | Inbound messages that received a reply | Selected range; business timezone | `messages`, `conversations` |
| Top questions | Normalized inbound messages ending in `?`/`؟` or beginning with a recognized English/Arabic question word. Only normalized questions with at least two occurrences are returned. | None | Selected range; business timezone | `messages`, `conversations` |
| Estimated time saved | Eligible AI service replies × merchant estimate for minutes per manual reply ÷ 60. | None | Selected range; business timezone | `messages`, `conversations`, business estimate settings |
| Estimated value saved | Eligible AI service replies × minutes per manual reply ÷ 60 × merchant hourly rate. Uses unrounded hours; rounded to whole currency units for display. | None | Selected range; business timezone | `messages`, `conversations`, business estimate settings |
| CSAT | Positive ratings ÷ all ratings × 100. | All ratings in period | Requested day count, maximum 365; business timezone | `csat_ratings` |

## Compatibility fields

`ai_success_rate`, `auto_reply_rate`, `avg_response_time_seconds`, `avg_response_time_formatted`, and `avg_response_time_minutes` remain temporarily in API responses as deprecated aliases. They carry the current AI-share or median-response value; new clients should use `ai_share_of_replies`, `median_response_time_seconds`, `median_response_time_formatted`, and `median_response_time_minutes`.

## Estimate inputs

Each business stores minutes per manual reply (default 3), hourly rate (default 100), and currency (default SAR). The reports UI labels derived savings and value as estimates and displays these assumptions.
