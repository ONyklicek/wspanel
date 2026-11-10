# Herní balíčky

Herní server v WSPanelu běží podle **balíčku**: JSON manifestu, který hru přidá
(`game`) nebo rozšíří existující o varianty (`extends`). Manifest nic nespouští,
jen pojmenovává mechanismy agenta a jejich parametry; agent ho před použitím
celý zkontroluje.

- `catalog/` – balíčky katalogu WSPanelu. Release je složí do
  `games/catalog.json` na GitHub Pages, podepsaný klíčem apt repozitáře;
  panel je nabízí v **Server → Herní balíčky**.
- Vestavěné balíčky (Minecraft, Factorio) jsou v agentovi:
  `agent/internal/ops/gamepkgs/`.
- Vlastní balíček správce: soubor v `/etc/wspanel/games.d/` nebo nahrání v panelu.

## Formát

```json
{
  "id": "minecraft-folia",            // a-z, 0-9, pomlčky
  "name": "Folia pro Minecraft",
  "version": "1.0.0",
  "description": "…",
  "extends": "minecraft",             // nebo "game": {…} pro novou hru
  "flavors": [
    {"id": "folia", "label": "Folia (vícevláknový)",
     "source": {"type": "papermc", "project": "folia"},
     "addons": {"kind": "plugins", "modrinth_loaders": ["folia"]}}
  ]
}
```

Nová hra (`game`): `id`, `label`, `engine`, `proto` (`tcp`/`udp`),
`default_port`, `min_memory_mb`, `arch` (`amd64`, `arm64`).

| engine | zdroje variant (`source.type`) |
|---|---|
| `minecraft` | `papermc` (+ `project`), `mojang`, `fabric`, `forge` |
| `factorio` | `factorio` |

Nový engine nebo zdroj (např. SteamCMD pro Counter-Strike 2) přidává agent,
ne balíček.
