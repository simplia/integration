## Typy eventů

Lambda handler dostává na vstupu JSON payload (přímo, nebo zabalený v SQS `Records[0].body`).
`EventDecoder::fromInput()` ho podle pole `type` rozhoduje na konkrétní typovanou
`IntegrationEvent` instanci a předá ji handleru:

```php
return function (Context $ctx, ?IntegrationEvent $event) {
    // ...
};
```

Pokud manifest integrace deklaruje víc typů eventů, handler musí instanci routovat
pomocí `instanceof`:

```php
return function (Context $ctx, ?IntegrationEvent $event) {
    if ($event instanceof ExportTransformEvent) {
        // ...
    } elseif ($event instanceof FrontendCallEvent) {
        // ...
    }
};
```

Neznámý nebo chybějící `type` vrací `null` — handler dostane `$event === null`.

### Přehled

Všechny typy z `EventDecoder::fromInput()` (`src/Event/EventDecoder.php`):

| `type` | Event třída | Směr |
|---|---|---|
| `http.request` | `HttpRequest` | sync |
| `http.webhook` | `Webhook` | async |
| `order.new` | `Order\NewOrderEvent` | async |
| `admin.order.batch` | `Order\AdminBatchOrdersEvent` | sync |
| `admin.product.batch` | `Order\AdminBatchProductsEvent` | sync |
| `admin.user.batch` | `Order\AdminBatchUsersEvent` | sync |
| `stock-input-supplier.new` | `Stock\NewStockInputSupplierEvent` | async |
| `shipment.normalize` | `Shipment\ShipmentNormalizeEvent` | sync |
| `admin.terminal.page` | `Terminal\TerminalPageEvent` | sync |
| `frontend.call` | `Frontend\FrontendCallEvent` | sync |
| `export.transform` | `Export\ExportTransformEvent` | sync |

Detailní popis payloadu má zatím jen `export.transform` (níže); ostatní typy
zatím dokumentované nejsou.

## `export.transform`

Nechá integraci upravit vygenerovaný export před tím, než ho eshop nabídne ke stažení
(`pohoda-invoice`) nebo než ho vrátí jako feed (`feed`).

**Manifest:**

```xml
<actions>
    <export-transform type="pohoda-invoice"/>
</actions>
```

nebo

```xml
<actions>
    <export-transform type="feed"/>
</actions>
```

### Payload

`ExportTransformEvent` je konstruovaný z `target`, `source`, `destination` a
volitelného `context` z rootu JSON payloadu. `source`/`destination` jsou presigned
URL na S3 s `encoding: "gzip"` (zdroj i cíl jsou vždy gzip).

`pohoda-invoice`:

```json
{
    "type": "export.transform",
    "target": "pohoda-invoice",
    "source": {
        "url": "https://...presigned-get...",
        "encoding": "gzip"
    },
    "destination": {
        "url": "https://...presigned-put...",
        "encoding": "gzip"
    },
    "context": {}
}
```

`feed` — `context` navíc nese, který feed se generuje:

```json
{
    "type": "export.transform",
    "target": "feed",
    "source": {
        "url": "https://...presigned-get...",
        "encoding": "gzip"
    },
    "destination": {
        "url": "https://...presigned-put...",
        "encoding": "gzip"
    },
    "context": {
        "feedConfigId": 12,
        "feedUrlId": 34,
        "url": "https://shop.example.com/feed/heureka.xml",
        "format": "xml"
    }
}
```

### `ExportFile` API

`$event->getFile()` vrací `ExportFile` s metodami pro stažení zdroje a nahrání výsledku:

| Metoda | Účel |
|---|---|
| `download(): string` | Stáhne zdroj a vrátí cestu k lokálnímu **rozgzipovanému** dočasnému souboru. |
| `upload(string $path): void` | Zagzipuje daný lokální soubor a nahraje ho jako výsledek. |
| `getContent(): string` | Zkratka: stáhne a vrátí obsah jako string (rozgzipovaný, celý v paměti). |
| `uploadContent(string $content): void` | Zkratka: zagzipuje string a nahraje ho jako výsledek (celý v paměti). |
| `downloadRaw(): string` | Stáhne zdroj beze změny (stále gzip) — pro velké soubory. |
| `uploadRaw(string $compressedPath): void` | Nahraje už zagzipovaný soubor beze změny — pro velké soubory. |

```php
return function (Context $ctx, ExportTransformEvent $event) {
    $path = $event->getFile()->download();   // lokální, rozgzipovaný tmp soubor
    // ... uprav soubor ...
    $event->getFile()->upload($path);        // zagzipuje a PUT na destination
};
```

`getContent()`/`uploadContent()`/`upload()` drží celý payload v paměti (gzip probíhá
najednou nad celým obsahem) — pro malé exporty v pořádku. U velkých feedů (typicky
`target: "feed"`) radši použij `download()` + transformaci souboru na disku +
`uploadRaw()`, ať se nezagzipovává celý výstup najednou v paměti.

### Kontrakt: musíš něco nahrát

Handler **musí** před návratem zavolat některou z upload metod. Pokud se nic
nenahraje, eshop se pokusí stáhnout výsledek z `destination.url` a **celý export/feed
selže s chybou** — není žádný fallback na netransformovaný soubor. Stejně tak
selže export, pokud je nahraný výsledek neplatný gzip nebo prázdný.

### Časový rozpočet

- HTTP klient v `ExportFile::getClient()` má timeout **5 minut na jeden přenos**
  (`HttpClient::create(['timeout' => 5 * 60])`) — platí pro download i upload.
- Presigned URL mají omezenou platnost: **10 minut** pro `pohoda-invoice`,
  **30 minut** pro `feed`.
- Navíc platí vlastní limit běhu Lambdy (max 15 minut).
- U `pohoda-invoice` na výsledek synchronně čeká admin v prohlížeči — transformace
  musí být rychlá.

### Lokální testování

Presigned URL nevyžadují AWS credentials, takže lokálně místo nich lze použít
libovolnou HTTP URL, kterou ovládáš (např. dočasný soubor na vlastním serveru
nebo `http.server`).

```bash
# pohoda-invoice
./vendor/bin/run '{
    "type": "export.transform",
    "target": "pohoda-invoice",
    "source": {"url": "https://example.com/source.xml.gz", "encoding": "gzip"},
    "destination": {"url": "https://example.com/upload-target", "encoding": "gzip"},
    "context": {}
}'

# feed
./vendor/bin/run '{
    "type": "export.transform",
    "target": "feed",
    "source": {"url": "https://example.com/source.xml.gz", "encoding": "gzip"},
    "destination": {"url": "https://example.com/upload-target", "encoding": "gzip"},
    "context": {
        "feedConfigId": 12,
        "feedUrlId": 34,
        "url": "https://shop.example.com/feed/heureka.xml",
        "format": "xml"
    }
}'
```
