# NEW CHAT SESSION RESUME PROMPT — WBPMS

> **How to use this file:**
> Whenever you open a brand-new chat window with **Antigravity (Gemini)**, **Claude Code**, or **OpenAI Codex** in `c:\xampp\htdocs\wbpms`, copy and paste the prompt block below as your very first message.
> It grounds the AI on live WBPMS state without loading massive historical logs.

---

```markdown
Hello! We are resuming our engineering work on WBPMS (`c:\xampp\htdocs\wbpms`).

To synchronize your memory without reading large historical logs:
1. Read `SESSION_HANDOVER.md` (< 80 lines) and check `AGENTS.md` for active guardrails.
2. Run a read-only working-tree check (`git status --short`) to see current uncommitted files. (Do not commit, push, or touch the database.)
3. In exactly 3 concise bullet points, tell me:
   - What was accomplished in the most recent session(s).
   - What files currently have uncommitted changes or active work.
   - The immediate next task or open verification item.

Do not dump raw logs or recite entire rulebooks. Consult `Agent Handshake.md` and `.agents/skills/wbpms-qa-and-learning/references/` on demand when touching specific modules. Wait for my confirmation before editing any files.
```

---

## Why this prompt works
1. **Zero Hallucination**: Forces the AI to read live files and `git status --short` instead of guessing from memory or stale `docs/`.
2. **Token Efficient**: Bootstraps from `SESSION_HANDOVER.md` (< 80 lines) instead of ingesting the 800+ line `agent-work-ledger.md`.
3. **Safety Gate**: Prevents unsolicited edits, commits, or destructive DB actions before the owner confirms scope.
