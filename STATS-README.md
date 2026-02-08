# Konfigurace statistik

## Ochrana soukromí

Systém statistik **nesbírá žádná citlivá data** z aplikace. Zaznamenávají se pouze:
- Typ události (např. "chip_added", "employee_deleted")
- Agregované počty (např. počet importovaných záznamů)
- ID instance (náhodně generované UUID)
- Časové razítko události

**Co se NESBÍRÁ:**
- ❌ Identifikátory čipů
- ❌ Čísla nebo jména zaměstnanců
- ❌ Konkrétní data výpůjček
- ❌ Jakékoliv osobní údaje

Backend automaticky filtruje a odmítá jakákoliv citlivá data, i kdyby byla omylem zaslána.

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

## Sbírané události

Systém sleduje následující typy událostí:

### Životní cyklus aplikace
- `first_use` - První spuštění aplikace na dané instanci
- `app_started` - Spuštění aplikace (načtení stránky)

### Správa čipů
- `chip_added` - Přidán nový čip
- `chip_deleted` - Smazán čip
- `chips_imported` - Import čipů z XLSX (obsahuje: count)
- `chips_exported` - Export čipů do XLSX (obsahuje: count)

### Správa zaměstnanců
- `employee_added` - Přidán nový zaměstnanec
- `employee_updated` - Upraven zaměstnanec
- `employee_deleted` - Smazán zaměstnanec
- `employees_imported` - Import zaměstnanců z XLSX (obsahuje: count)
- `employees_exported` - Export zaměstnanců do XLSX (obsahuje: count)

### Správa výpůjček
- `borrowing_created` - Vytvořena nová výpůjčka (obsahuje: chipsCount)
- `borrowing_updated` - Upravena výpůjčka
- `borrowing_ended` - Ukončena výpůjčka
- `borrowings_imported` - Import výpůjček z XLSX (obsahuje: count, skipped)
- `borrowings_list_exported` - Export seznamu výpůjček (obsahuje: count)
- `borrowings_exported_xlsx` - Export výpůjček za měsíc (obsahuje: rowCount)
- `month_borrowings_deleted` - Smazány výpůjčky za měsíc (obsahuje: count)

### Správa dat
- `all_data_imported` - Import všech dat (obsahuje: chips, employees, borrowings - počty)
- `all_data_exported` - Export všech dat (obsahuje: chips, employees, borrowings - počty)

### Ostatní
- `help_viewed` - Zobrazena nápověda

## Příklady záznamů

### Spuštění aplikace
```json
{
  "instanceId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "event": "app_started",
  "timestamp": "2026-02-08T14:30:45.123Z",
  "data": null,
  "userAgent": "Mozilla/5.0...",
  "ip": "192.168.1.1",
  "received": "2026-02-08T14:30:45+00:00"
}
```

### Import s agregovanými daty
```json
{
  "instanceId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "event": "chips_imported",
  "timestamp": "2026-02-08T14:30:45.123Z",
  "data": {
    "count": 25
  },
  "userAgent": "Mozilla/5.0...",
  "ip": "192.168.1.1",
  "received": "2026-02-08T14:30:45+00:00"
}
```
