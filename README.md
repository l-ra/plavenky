# Evidence plaveckých čipů

Jednoduchá webová aplikace pro evidenci výpůjček plaveckých čipů. Aplikace běží kompletně v prohlížeči, ukládá data lokálně a funguje i bez webserveru.

## Spuštění

Stačí otevřít soubor `index.html` v libovolném moderním webovém prohlížeči (Chrome, Firefox, Safari, Edge).

**Poznámka:** Data jsou uložena v localStorage prohlížeče, takže zůstanou zachována i po zavření stránky. Pokud smažete data prohlížeče nebo použijete jiný prohlížeč, data nebudou dostupná.

## Funkce aplikace

### 1. Správa čipů
- Přidávání čipů s jedinečným identifikátorem
- Odebírání čipů (pokud nejsou vypůjčeny)
- Přehled všech dostupných čipů
- **Import z XLSX**: Import seznamu čipů ze souboru Excel
  - Sloupec: "Identifikátor"
- **Export do XLSX**: Export seznamu čipů do souboru Excel

### 2. Správa zaměstnanců
- Přidávání zaměstnanců (jméno + zaměstnanecké číslo + odbor)
- Odebírání zaměstnanců (pokud nemají výpůjčky)
- **Import z XLSX**: Import seznamu zaměstnanců ze souboru Excel
  - Sloupce: "Jméno", "Zaměstnanecké číslo" a "Odbor"
- **Export do XLSX**: Export seznamu zaměstnanců do souboru Excel

### 3. Nová výpůjčka
- **Výběr zaměstnance**: Našeptávač při psaní jména nebo čísla zaměstnance
- **Datum začátku**: Předvyplněno na aktuální datum
- **Výběr čipů**: Přidávání čipů pomocí našeptávače
  - Zobrazují se pouze čipy dostupné v daném termínu
  - Možnost přidat více čipů najednou
- **Datum konce** (volitelné): Pokud není zadáno, výpůjčka zůstává aktivní

### 4. Seznam výpůjček
- Přehled všech výpůjček s informacemi:
  - Zaměstnanec (jméno a číslo)
  - Odbor zaměstnance
  - Seznam vypůjčených čipů
  - Datum začátku a konce
  - Stav (Aktivní/Ukončeno)
- **Filtry**: Filtrování podle zaměstnance, odboru, čipu nebo stavu
- **Import z XLSX**: Import seznamu výpůjček ze souboru Excel
  - Sloupce: "Zaměstnanecké číslo", "Jméno zaměstnance", "Odbor", "Čipy", "Datum začátku", "Datum konce"
- **Export do XLSX**: Export všech výpůjček do souboru Excel
- **Ukončení výpůjčky**: Tlačítko pro rychlé ukončení aktivní výpůjčky
- **Úprava výpůjčky**: Změna zaměstnance, termínů nebo čipů
- **Smazání výpůjčky**: Kompletní odstranění záznamu

### 5. Export výpůjček
- Export výpůjček za vybraný měsíc do XLSX
- Pro každý čip a každý den výpůjčky vytvoří řádek s údaji:
  - Číslo čipu
  - Datum
  - Číslo zaměstnance
  - Jméno zaměstnance
  - Odbor

### 6. Správa dat
- **Export všech dat**: Exportuje všechna data (čipy, zaměstnance, výpůjčky) do jednoho JSON souboru
  - Soubor obsahuje časovou značku v názvu: `evidence-plavenek-YYYY-MM-DD-HHMM.json`
- **Import dat**: Importuje data ze záložního JSON souboru
  - Přepíše všechna současná data
  - Vyžaduje potvrzení
- **Smazání všech dat**: Kompletní vymazání všech dat z aplikace
  - Vyžaduje zadání potvrzovacího slova "SMAZAT"
- **Statistiky**: Zobrazuje aktuální počty čipů, zaměstnanců a výpůjček
- **Správa výpůjček podle měsíců**:
  - Přehled všech měsíců, pro které existují výpůjčky
  - Zobrazení počtu výpůjček v každém měsíci
  - Možnost smazat všechny výpůjčky z konkrétního měsíce
  - Měsíce jsou seřazeny od nejnovějších

## Technické informace

- **Frontend**: Pure HTML, CSS a JavaScript (žádné externí závislosti kromě SheetJS)
- **Úložiště**: localStorage (data v prohlížeči)
- **XLSX podpora**: SheetJS (načítáno z CDN)
- **UI dialogy**: Vlastní modální dialogy místo nativních alert/confirm/prompt
- **Kompatibilita**: Moderní prohlížeče (Chrome, Firefox, Safari, Edge)

## Tipy pro použití

1. **⚠️ DŮLEŽITÉ - Pravidelné zálohování**: Minimálně jednou týdně exportujte všechna data na záložce "Správa dat"
   - Export vytvoří JSON soubor se všemi daty, který si uložte na bezpečné místo
   - V případě problémů s prohlížečem můžete všechna data obnovit
   
2. **Import čipů**: Připravte si Excel soubor se sloupcem "Identifikátor" pro hromadný import čipů

3. **Import zaměstnanců**: Připravte si Excel soubor se sloupci "Jméno", "Zaměstnanecké číslo" a "Odbor" pro hromadný import

4. **Import výpůjček**: Připravte si Excel soubor se sloupci "Zaměstnanecké číslo", "Jméno zaměstnance", "Odbor", "Čipy" (oddělené čárkou), "Datum začátku" a "Datum konce"
   - Před importem výpůjček musí existovat příslušní zaměstnanci a čipy v systému
   
5. **Kontrola dostupnosti**: Při výběru čipů se automaticky zobrazují pouze ty, které nejsou v daném termínu vypůjčené

6. **Filtry**: Využijte filtry pro rychlé nalezení konkrétních výpůjček podle zaměstnance, odboru nebo čipu

7. **Úprava výpůjček**: Můžete kdykoliv upravit termíny, přidat/odebrat čipy nebo nastavit datum vrácení

8. **Migrace na jiné zařízení**: 
   - Na původním zařízení: Exportujte všechna data (Správa dat → Export všech dat)
   - Na novém zařízení: Importujte JSON soubor (Správa dat → Import dat)

## Správa a ochrana dat

### Úložiště dat
- Data jsou uložena **pouze na vašem počítači** v trvalé paměti prohlížeče (localStorage)
- Data **nejsou** posílána na internet ani žádný server
- Data nejsou synchronizována mezi různými zařízeními nebo prohlížeči

### Zálohování
- **Doporučujeme pravidelně exportovat všechna data** na záložce "Správa dat"
- Export vytvoří JSON soubor se všemi daty, který můžete uložit jako zálohu
- V případě problémů můžete data kdykoli obnovit importem

### Omezení
- Při mazání dat prohlížeče (vymazání cookies/localStorage) dojde ke ztrátě všech záznamů
- Aplikace vyžaduje povolený JavaScript
- Pro import/export XLSX je potřeba připojení k internetu (kvůli načtení knihovny SheetJS z CDN)
- Export/import JSON funguje bez připojení k internetu
