# Phase 3 R8: Inherit the business AI provider

New agents now store a null provider and model unless the merchant explicitly
chooses an override. Runtime resolution already falls back to the business
provider/model, so changing those business settings applies to inheriting
agents. The wizard labels inherited provider and model values. The API accepts
only Gemini and Groq, the providers supported by the reply runtime; unsupported
provider selections return field-level validation errors.

No migration is needed. Existing explicit agent overrides remain stored and
continue to be used.

Verification: feature tests confirm new agents retain null overrides and
unsupported providers cannot create an agent.
