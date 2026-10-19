# Glenmark Connector Client

Klientský WordPress plugin pro zabezpečené poskytování technických informací centrálnímu Glenmark Connector Hubu. Vyžaduje WordPress 6.4+, PHP 8.0+ a HTTPS. Nepoužívá ACF Pro.

## Instalace a propojení

1. Nainstalujte a aktivujte plugin na cílovém WordPress webu.
2. Otevřete **Nastavení → Glenmark Connector**. Zaznamenejte identifikátor webu a endpoint; endpoint použijte jako úplnou URL konektoru v Hubu.
3. Klikněte na **Vygenerovat nový klíč**. Náhodný klíč se zobrazí pouze jednou. Bezpečně jej přeneste do Glenmark Connector Hubu a uložte k odpovídajícímu webu spolu s identifikátorem. Klíč neposílejte e-mailem ani jej nevkládejte do URL.
4. V Hubu zapněte konektor a proveďte ruční první aktualizaci. Klient nevyvolává spojení zpět do Hubu.
5. Při rotaci vygenerujte nový klíč a ihned jej aktualizujte v Hubu. Generování zneplatní předchozí klíč. Tlačítko **Zneplatnit klíč** odstraní hash a přístup okamžitě zablokuje.

## API v1

Klient poskytuje pouze `GET /wp-json/glenmark-connector/v1/snapshot`. Požadavek musí přijít přes HTTPS a mít platné hlavičky `Authorization: Bearer <64 hex znaků>` a `X-Glenmark-Site-ID: <site id>`. Server uchovává pouze SHA-256 hash přístupového klíče. Neautorizované požadavky jsou odmítnuty; odpověď má `Cache-Control: private, no-store`.

Snapshot obsahuje `schema_version`, identitu webu, verzi konektoru, prostředí, výsledky testů Site Health, povolené technické sekce, komponenty Glenmark a UTC čas sběru. Hub vyžaduje, aby URL odpovídala jeho evidenci. Podrobný kontrakt a allowlist polí jsou popsány v [dokumentaci Hubu](../glenmark-connector-hub/README.md#api-kontrakt-v1).

## Diagnostika a soukromí

Plugin používá `WP_Site_Health` a `WP_Debug_Data`, neimplementuje vlastní WordPress diagnostiku. Spouští dostupné přímé testy s časovým rozpočtem; asynchronní testy označí jako `unsupported` a překročení rozpočtu jako `pending`. Výsledky nikdy automaticky nepovažuje za úspěšné. Snapshot se krátce cachuje pro omezení zátěže a souběžné požadavky se zamykají.

Z technických informací se vybírají pouze povolené Site Health sekce a pole; hodnoty označené WordPressem jako soukromé se vynechávají. Klient nevrací přístupové údaje, uživatelské účty, cesty k souborům ani libovolná debug data. Plugin neumožňuje vzdálenou správu, změnu nastavení cílového webu ani vzdálené aktualizace.

Deaktivace klienta odstraní uložený hash klíče, cache snapshotu a zámek. Po opětovné aktivaci je nutné vygenerovat a propojit nový klíč.
