# Start a Fresh Session Here

**Best practice for this project after long conversations:**

1. Start a completely new chat/session with the AI.
2. Paste this short prompt (or just point the AI at the files):

---

You are helping with the BibleDB project at https://github.com/RichardAMcGough/BibleDB (or the local clone).

First action: Read and internalize `docs/HANDOFF-current.md` completely. This is the single source of truth for the current architecture, decisions, and state.

Then report back in 1-2 paragraphs:
- The current high-level state of the project.
- The most recent completed work.
- What is currently uncommitted in the working tree.
- Any open items or "next" suggestions mentioned in the handoff.

After that, ask me what I want to work on next.

Do not re-explain old history unless I ask. Focus on the content of HANDOFF-current.md + actual files in the repo.

---

**Key files for new sessions:**
- `docs/HANDOFF-current.md` (start here)
- `docs/README.md`
- `web/HANDOFF.md` (for UI-specific work)

**Current tip (as of latest handoff):** The data/ folder was just added with LFS. There is also a big batch of uncommitted improvements to the verse notes system (CSRF, remote API support, delete, editor integration, etc.). The HANDOFF already describes the intended behavior.

Use this starter to get the AI on the same page quickly without dragging in the entire previous long thread.