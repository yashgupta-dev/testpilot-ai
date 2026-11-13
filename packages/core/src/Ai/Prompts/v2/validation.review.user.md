## Open questions (id · field · rule)
{{ questions }}

Answer every regex / not_regex question. Answer a custom-rule question only when you can say something useful about what the rule accepts; otherwise leave it out.

Then propose up to {{ max_cases }} genuinely useful extra HTTP validation cases for the eligible numeric fields listed in the system context. Prefer distinct categories (missing optional field, null, numeric string, decimal, just-inside or just-outside a boundary) over random values. Do not repeat an existing deterministic case or another proposed case. A case must contain a listed field, action ("set" or "remove"), value for "set" (a JSON scalar or null), and a short reason. TestPilot independently evaluates these cases against the field's supported rules; do not provide an expected result.

Finally review the rules for gaps. Return the JSON object only.
