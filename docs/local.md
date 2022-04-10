## Závislosti
Závislosti jsou řešeny standardně přes [composer](https://getcomposer.org/) a podporují pouze veřejně dostupné balíčky.

```bash
# instalace závislostí tak, jak jsou v produkci
docker run --rm -it -v ${PWD}:/app composer install --ignore-platform-reqs

# aktualizace závislostí na aktuální verze
docker run --rm -it -v ${PWD}:/app composer update -W --ignore-platform-reqs
```


## Spuštění
Pomocný spouštěcí skript provede vždy _docker build_ a _composer install_ stejným postupem jako v produkci.

```
# spuštění bez vstupu
./vendor/bin/run

# spuštění s inline eventem
./vendor/bin/run '{"type": "order.new", "id": "2000212"}'

# spuštění s eventem ze souboru
./vendor/bin/run --file=event.json
```

## Unit testy
PHPUnit lze spouštět buď přímo v editoru (vyžaduje ruční nastavení) nebo využít pomocný skript

```
./vendor/bin/run-tests
```

## Unit testy
Statickou analýzu kódu [PHPStan](https://phpstan.org/) lze spustit přes pomocný skript

```
./vendor/bin/run-phpstan
```
