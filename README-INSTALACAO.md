# Projeto existente — V2

Mantenha seu `AGENTS.md` atual.

Substitua/copiei os demais arquivos deste pacote:
- `CLAUDE.md`
- `.claude/agents/`
- `.claude/settings.json`
- `.codex/`

O `settings.json` incluído preserva integralmente o bloco `permissions.allow`
fornecido e acrescenta:
- `"model": "sonnet"`
- `"effortLevel": "low"`
- `$schema` para validação no editor.

Claude:
- MICRO → Sonnet Low direto
- FRONTEND → Sonnet Low direto
- FEATURE → Sonnet Medium
- COMPLEX → Opus Medium
- DEEP → Opus High
- Haiku → somente explícito/opcional

Depois de copiar, recarregue o VS Code e abra uma nova sessão do Claude Code.
