# Taste
- Writes and specifies requirements in Indonesian (Bahasa Indonesia); expects explanations and reports in Indonesian. Confidence: 0.75
- Before making broad changes, wants the agent to first analyze the existing project structure (models, services, config, schema, authorization logic) and then explain which files will be created/changed and why, instead of editing immediately. Confidence: 0.8
- Prefers minimal, targeted changes: avoid altering existing business logic without justification, and integrate new behavior at the points the existing code already provides. Confidence: 0.7
- Never hardcode API keys or credentials in source code; keep secrets in environment/config. Confidence: 0.8
- Prefers using framework-native features (e.g. Laravel Notifications) over ad-hoc custom implementations. Confidence: 0.6
- Prefers slow operations (like sending email) run asynchronously via the queue (database queue driver), with failures recorded in failed_jobs and retryable. Confidence: 0.65
- Wants email/UI templates that are clean, professional, responsive, and consistent with project branding. Confidence: 0.6
