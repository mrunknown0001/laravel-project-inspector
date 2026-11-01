# AI provider setup

AI analysis is optional. The inspector works fully without it, and nothing is sent to an AI
provider unless you run `larainspect ai`, pass `--ai`, or set `AI_ENABLED=true`.

## 1. Configure

Settings come from, in order of priority:

1. environment variables (`export ANTHROPIC_API_KEY=...`, CI secrets),
2. the inspector's own `.env` file (`larainspect config:init` creates it from `.env.example`),
3. built-in defaults.

The `.env` file belongs to the inspector, not to the project being inspected, and is git-ignored.
`larainspect config:show` lists every setting and where it came from, with API keys masked.

### General settings

| Variable | Default | Meaning |
|---|---|---|
| `AI_PROVIDER` | (none) | Provider used when `--provider` is not given. |
| `AI_MODEL` | (none) | Model for `AI_PROVIDER` when `<PROVIDER>_MODEL` is not set. |
| `AI_ENABLED` | `false` | Run AI analysis on every `inspect`/`report` without `--ai`. |
| `AI_TIMEOUT` | `300` | Seconds per request (minimum 5). |
| `AI_MAX_TOKENS` | `16000` | Output token limit per request. |
| `AI_MAX_RETRIES` | `2` | Retries for rate limits (429), overload (5xx/529) and timeouts; capped at 5. `Retry-After` is honoured up to 30 s. |
| `AI_MAX_INPUT_CHARS` | `60000` | Upper bound for the inspection data per task. Larger contexts are condensed (shorter evidence, fewer low-severity findings), and the report says so. |

Model precedence: `--model`, then `<PROVIDER>_MODEL`, then `AI_MODEL` (only for the `AI_PROVIDER`
provider), then the provider default. Model names are never hard-coded lists: use any id the
provider accepts (`larainspect ai:models <provider>` lists them).

### Anthropic

| Variable | Notes |
|---|---|
| `ANTHROPIC_API_KEY` | Required. |
| `ANTHROPIC_BASE_URL` | Default `https://api.anthropic.com`; with or without `/v1`. |
| `ANTHROPIC_MODEL` | Default `claude-opus-5-5`. |
| `ANTHROPIC_VERSION` | `anthropic-version` header, default `2026-10-21`. |
| `ANTHROPIC_EFFORT` | Optional `low`, `medium`, `high`, `xhigh`, `max`; unset uses the model's default. |
| `ANTHROPIC_REFUSAL_FALLBACK` | Default `true`. For models that support it (`claude-fable-5-1`, `claude-opus-5-5`, `claude-opus-5`, `claude-sonnet-5-5`) on `api.anthropic.com`, a request declined by safety classifiers is re-run server-side on Anthropic's recommended fallback model (`fallbacks: "default"`, beta `server-side-fallback-2026-07-01`). Set `false` to turn it off. |

Uses `POST /v1/messages` with `x-api-key` and `anthropic-version`. `temperature`, `top_p` and
`top_k` are never sent: API version `2026-10-21` rejects them. A refusal
(`stop_reason: "refusal"`) is shown in the report as a note, not treated as an error.

### OpenAI

| Variable | Notes |
|---|---|
| `OPENAI_API_KEY` | Required. |
| `OPENAI_BASE_URL` | Default `https://api.openai.com/v1`. Point it at any OpenAI-compatible server (Azure OpenAI, a local gateway). |
| `OPENAI_MODEL` | Required (no default). |
| `OPENAI_ORGANIZATION`, `OPENAI_PROJECT` | Optional `OpenAI-Organization` / `OpenAI-Project` headers. |
| `OPENAI_TOKEN_PARAMETER` | Set to `max_tokens` for compatible servers that do not support `max_completion_tokens` (the default, required by reasoning models). |

Uses Chat Completions (`POST /chat/completions`). Temperature is not sent: several current
models only accept the default.

### OpenRouter

| Variable | Notes |
|---|---|
| `OPENROUTER_API_KEY` | Required. |
| `OPENROUTER_BASE_URL` | Default `https://openrouter.ai/api/v1`. |
| `OPENROUTER_MODEL` | Required, e.g. `anthropic/claude-opus-4.5`. |
| `OPENROUTER_APP_NAME` | Sent as `X-OpenRouter-Title` / `X-Title`; default "Laravel Project Inspector". |
| `OPENROUTER_APP_URL` | Optional `HTTP-Referer`. |

Reports `usage.cost` when OpenRouter provides it, and the upstream provider name in errors.

### NoTokenLimit

| Variable | Notes |
|---|---|
| `NOTOKENLIMIT_API_KEY` | Required; keys start with `ntl_live_`. |
| `NOTOKENLIMIT_BASE_URL` | Default `https://notokenlimit.com/api/v1`. |
| `NOTOKENLIMIT_MODEL` | Required, e.g. `claude-sonnet-5.5`. |

NoTokenLimit is a third-party service that resells access to models from several vendors and
states that it is not affiliated with them. Its API documentation pages refuse automated access,
so this adapter is based on the behaviour observed on 2026-11-01: an OpenAI-compatible
`/chat/completions` endpoint with Bearer authentication, a public `/models` list, and errors shaped
`{"error": {"type", "code", "message"}}`. Its terms say API keys are personal and may not be shared.
Consider whether sending project metadata through a reseller is acceptable for your organisation.

## 2. Check

```bash
larainspect ai:providers            # configuration status, keys masked; sends nothing
larainspect ai:providers --check    # also calls each configured provider's model list
larainspect config:test anthropic   # one request: "Reply with the single word OK."
```

## 3. Use

```bash
larainspect ai /path/to/project --dry-run          # review the exact prompts; nothing is sent
larainspect ai /path/to/project                    # default tasks
larainspect ai --ai-task=documentation,dependencies  # over the latest saved results
larainspect inspect /path/to/project --ai --format=markdown --output=inspection.md
```

| Task | What you get |
|---|---|
| `explain-findings` | Plain-language explanation of the findings, most severe first. |
| `architecture` | How the application is structured, strengths, risks, a few improvements. |
| `dependencies` | Lock/PHP conflicts, abandoned and outdated packages explained, an upgrade order. |
| `security-fixes` | Prioritised remediation plan with Laravel-specific fixes and verification steps. |
| `documentation` | A technical overview draft for a README or onboarding page. |
| `technical-debt` | Debt themes with effort and risk, and an ordered plan. |
| `best-practices` | Recommendations specific to this project's version, packages and structure. |

Each task is one request; the default runs `explain-findings`, `security-fixes` and
`technical-debt`. Usage (tokens, and cost where reported) is shown for every task.

## What is sent

Per task, one system message (the reviewer's rules) and one user message: the task instruction and
a JSON summary of the inspection results relevant to that task:

- project name, framework, Laravel version and layout; inspector version and severity counts;
- for each relevant analyzer: facts (label, value, source), findings (id, severity, confidence,
  title, component, explanation, evidence, remediation) and notes;
- a whitelist of summary data (e.g. route counts, component counts, package lists).

Never sent: source code, `.env` or any env file, the absolute project path (replaced by
`<project>`), the audit log, full route lists. The JSON is passed through the secret redactor after
it is built (evidence was already redacted during inspection), and the model's answer is redacted
too. Every request is recorded in `reports/audit.log` (method, URL, model, outcome; never keys).
