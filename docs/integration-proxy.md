# Integration proxy (pevné odchozí IP)

Lambda integrace nemají pevnou odchozí IP adresu. Někteří poskytovatelé (např. ERP u zákazníka) ale pouští jen povolené IP. Integration proxy je proto služba s pevnými odchozími adresami (seznam je v dokumentaci proxy v interním repozitáři `microservice/kubernetes`, `docs/reference/integration-proxy.md`). Lambda se k ní připojí přes mTLS (HTTP `CONNECT`) a proxy otevře spojení s cílem. TLS s cílem zůstává end-to-end, proxy provoz nevidí.

## Kdy použít

- Třetí strana povoluje přístup jen z konkrétních IP adres a my jí dáváme výše uvedené.
- Cíl musí být předem schválený (viz [Jak přidat cíl](#jak-přidat-cíl)). Proxy odmítne vše ostatní, včetně privátních sítí a wildcard hostů.

Pro běžná volání (Simplia API, S3, ...) proxy nepoužívejte, `$context->getClient()` zůstává beze změny.

## Použití

Instance musí být povolená (viz níže), jinak `$context->getProxyClient()` vyhodí `ProxyConfigurationException`.

### Čisté HTTP

```php
use Simplia\Integration\Proxy\ProxyTransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

$client = $context->getProxyClient(); // Symfony HttpClientInterface

$response = null;
try {
    $response = $client->request('POST', 'https://erp.example.com/api/orders', [
        'json' => $order,
        'timeout' => 30,
    ]);
    $content = $response->getContent();
} catch (TransportExceptionInterface $e) {
    throw $response === null
        ? new ProxyTransportException($e->getMessage(), false, $e) // request() nic neodesílá
        : ProxyTransportException::fromTransport($e, $response->getInfo());
}
```

Parametry TLS cíle jsou běžné volby požadavku: `verify_peer`, `verify_host`, `cafile` a `peer_fingerprint`. Curl podporuje jen otisk veřejného klíče ve tvaru `pin-sha256`.

### SOAP

`ProxySoapClient` nahrazuje nativní `SoapClient`, jen přenos jde přes proxy. WSDL musí být lokální soubor (vzdálené by se stahovalo mimo proxy).

```php
use Simplia\Integration\Proxy\ProxySoapClient;

$soap = new ProxySoapClient(
    $context->getProxyClient(),
    __DIR__ . '/wsdl/erp.wsdl',
    ['location' => 'https://erp.example.com/soap', 'login' => $user, 'password' => $pass, 'trace' => true],
    [
        'timeout' => 30,
        'verify_peer' => false,
        'verify_host' => false,
        // certifikát cíle nepřipínáme na CA, ale na jeho veřejný klíč
        'peer_fingerprint' => ['pin-sha256' => 'base64otisk='],
    ]
);
$result = $soap->getStock(['sku' => 'ABC']);
```

Otisk zjistíte takto:

```bash
openssl s_client -connect erp.example.com:443 </dev/null 2>/dev/null \
  | openssl x509 -pubkey -noout | openssl pkey -pubin -outform der \
  | openssl dgst -sha256 -binary | base64
```

## Chyby a opakování

Chyba přenosu se převádí na `ProxyTransportException`. Metoda `mayHaveReachedTarget()` říká, jestli požadavek mohl dorazit k cíli:

- `false`: proxy cíl odmítla (403, cíl není povolený), proxy je nedostupná nebo nesouhlasí `pin-sha256`. Nic se neodeslalo, **opakovat lze bezpečně**.
- `true`: spojení s cílem bylo navázáno a chyba (např. timeout) nastala později. Požadavek mohl být zpracován. **Opakujte jen idempotentní operace**, jinak vzniknou duplicity.

Pravidlo: opakovat jen když `mayHaveReachedTarget() === false`.

Stav 4xx/5xx od cíle je běžná odpověď cíle, ne chyba proxy. Odmítnutý `CONNECT` se vždy projeví jako výjimka, nikdy jako HTTP 403 od cíle.

## Zakázané volby

Klient z `getProxyClient()` odmítne s `\InvalidArgumentException` volby požadavku `proxy`, `no_proxy` a `extra`, protože by umožnily obejít tunel (např. `'proxy' => null` připojí přímo). Stejné volby odmítá i `ProxySoapClient` v parametru `$httpOptions` a navíc `body`, `json`, `headers`, `auth_basic`, `auth_bearer`, `query` a `base_uri`.

`ProxySoapClient` také odmítá SOAP volby, které by se tiše ignorovaly (`stream_context`, `proxy_host`, `connection_timeout`, `local_cert`, ...). Timeouty nastavujte v `$httpOptions` (`timeout`, `max_duration`).

Nepřenášejí se cookies a přesměrování 301/302 mění POST na GET.

## Jak přidat cíl

Cíle schvalují merge requesty do `microservice/kubernetes`, soubor `cluster/apps/integration-proxy/config/targets.yaml`. Přidejte host a port cíle (ne wildcard, ne privátní IP), znovu vygenerujte konfiguraci podle návodu v adresáři aplikace a MR nechte schválit.

## Jak povolit instanci

V `terraform/shop-integrations-instances` nastavte u instance:

```json
"integration_proxy": true
```

Terraform vydá klientský certifikát pro identitu `<repository>--<shop>`, uloží ho do SSM (`/integration-proxy/client/<repository>--<shop>`) a nastaví Lambdě proměnné `INTEGRATION_PROXY_URL` a `INTEGRATION_PROXY_PARAMETER_PATH`.

Je-li instance povolená, ale parametr v SSM chybí nebo je poškozený, Lambda skončí chybou `ProxyConfigurationException` s cestou parametru ještě před spuštěním vašeho handleru. Nikdy nepřechází na přímé spojení.

## X-Ray

Požadavky přes proxy se do X-Ray zapisují jako segmenty pojmenované podle hostu cíle. Zaznamenává se jen metoda, URL bez query řetězce, status a časy, nikdy tělo ani hlavičky.

## Lokální vývoj

Lokálně se konfigurace čte z proměnných prostředí (např. v `.env`). Všechny čtyři musí být nastavené, jinak běh skončí chybou:

| Proměnná | Obsah |
|----------|-------|
| `INTEGRATION_PROXY_URL` | `https://integration-proxy.simplia.cz:8443` |
| `INTEGRATION_PROXY_CERT` | klientský certifikát (PEM) |
| `INTEGRATION_PROXY_KEY` | privátní klíč (PEM) |
| `INTEGRATION_PROXY_CA` | CA, která podepsala certifikát proxy (PEM) |

Pokud `INTEGRATION_PROXY_URL` není nastavená, proxy je vypnutá a `getProxyClient()` vyhodí výjimku. Klíč nikdy neukládejte do gitu.
