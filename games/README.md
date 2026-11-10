# Herní balíčky

Manifesty herních balíčků WSPanelu.

- `catalog/` – balíčky **katalogu WSPanelu**. Release je složí do
  `games/catalog.json` na GitHub Pages, podepsaný klíčem apt repozitáře
  (`provision/game-catalog.sh`); panel je nabízí v **Server → Herní balíčky**.
  Soubor se jmenuje podle `id` balíčku.
- Vestavěné balíčky (Minecraft, Factorio) jsou v agentovi:
  `agent/internal/ops/gamepkgs/`.

Jak přidat hru nebo variantu krok za krokem, formát manifestu, pravidla,
katalog, REST API, příkazová řádka a postup pro nový engine:
[docs/herni-balicky.md](../docs/herni-balicky.md).
