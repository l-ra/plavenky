# Konfigurace statistik

## Nastavení složky pro statistiky

Statistiky se ukládají do složky, která je určena proměnnou prostředí `STATS_DIR`.

### Výchozí hodnota
Pokud proměnná prostředí není nastavena, použije se výchozí cesta:
```
/working/plavenky-stats/
```

### Nastavení proměnné prostředí

#### Apache (.htaccess)
```apache
SetEnv STATS_DIR /custom/path/to/stats
```

#### Apache (VirtualHost konfigurace)
```apache
<VirtualHost *:80>
    SetEnv STATS_DIR /custom/path/to/stats
</VirtualHost>
```

#### Nginx (s PHP-FPM)
V souboru `/etc/php/[verze]/fpm/pool.d/www.conf`:
```ini
env[STATS_DIR] = /custom/path/to/stats
```

#### Docker
```bash
docker run -e STATS_DIR=/custom/path/to/stats ...
```

#### PHP přímo v kódu (pro testing)
Před voláním skriptů:
```php
putenv('STATS_DIR=/custom/path/to/stats');
```

## Struktura souborů

Statistiky jsou ukládány v JSON Lines formátu, jeden soubor na měsíc:
```
/working/plavenky-stats/
├── stats-2026-01.jsonl
├── stats-2026-02.jsonl
└── stats-2026-03.jsonl
```

## Oprávnění

Ujistěte se, že webový server (např. `www-data`, `apache`, `nginx`) má práva pro:
- Vytvoření složky (pokud neexistuje)
- Zápis do složky
- Vytváření nových souborů

Příklad nastavení oprávnění:
```bash
sudo mkdir -p /working/plavenky-stats
sudo chown www-data:www-data /working/plavenky-stats
sudo chmod 755 /working/plavenky-stats
```
