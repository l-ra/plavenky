# Plavenky – zadání implementace šifrované synchronizace

**Repozitář:** https://github.com/l-ra/plavenky  
**Cílový soubor:** `docs/SYNCHRONIZACE_NAVRH_IMPLEMENTACE.md`  
**Cílová verze:** `1.6.0-dev`  
**Datum:** 2026-11-12  
**Stav:** zadání pro implementaci, nikoliv hotový kód

## 1. Cíl

Rozšířit lokální webovou aplikaci Evidence plaveckých čipů o volitelnou synchronizaci několika instancí pomocí jednoduchého PHP serveru, který ukládá soubory. Všechna aplikační data odcházející z prohlížeče se šifrují symetrickým klíčem odvozeným z hesla. **Uživatel zadává pouze jedno tajemství – heslo agendy**. Ze stejného hesla klient odvodí oddělený klíč pro šifrování a tajemství pro autentizaci na serveru.

- Backend **nemá aplikační znalost** čipů, zaměstnanců, výpůjček ani hlavní instance. Provádí pouze ověřování společného přístupového tajemství, validaci požadavků, bezpečnou souborovou manipulaci a atomický podmíněný zápis.
- Skupiny instancí jsou oddělené URL cestou a mají vlastní pracovní složku, salt, ověřovací údaj a šifrovaná data.
- Klient zajišťuje šifrování/dešifrování, změny dat, pravidla synchronizace, role hlavní/záložní instance a předání agendy.
- Synchronizace se nastavuje v záložce **Správa dat**. Bez nastavené URL nebo bez hesla v aktuální relaci nesmí probíhat.
- Pokud data na úložišti neexistují, klient nabízí **inicializaci aktuálními lokálními daty až po potvrzení uživatele**.
- Záložní instance může převzít agendu bez součinnosti původního počítače.
- Součástí práce je odstranění XSS nedostatků a bezpečnostní regresní testy.

**Přijaté omezení:** Není implementována kryptografická identita jednotlivých zařízení. Kdo zná společné heslo a má přístup k endpointu, může se autentizovat, data dešifrovat a vyvolat převzetí. Server neumí vynutit aplikační roli hlavní instance. Atomické podmíněné zápisy však zabrání tichému přepsání souběžně potvrzovaných revizí.

## 2. Výchozí stav a vývojová pravidla

- Současná aplikace: HTML/CSS/JavaScript bez frontend frameworku, lokální `localStorage` se samostatnými poli `chips`, `employees`, `borrowings`, import/export JSON a XLSX, identifikátor `analytics_instance_id` pro statistiku.
- Současný `Storage.set()` zapisuje samostatná pole; synchronizace musí zavést jednotný commit celého datového stavu. Import celé agendy musí být jedna logická operace.
- Dodržet `AGENTS.md`: upravovat pouze **`index-dev.html`**, ponechat produkční `index.html` beze změn až do explicitního pokynu, zachovat vývojové označení `1.6.0-dev` proti produkci `1.5.0`.
- Nové PHP API vytvořit v samostatném souboru **`sync.php`**. Testy a dokumentaci ukládat odděleně. Nepřidávat produkční hesla, tokeny ani data do repozitáře.

## 3. Jedno heslo, dva oddělené účely

### 3.1 Klientská derivace

Pro každou skupinu se při inicializaci vytvoří náhodný **salt 32 B**; ve veřejných metadatech serveru bude uložen salt, verze KDF a počet iterací. Salt není tajemství, je ale důležitý pro konzistentní derivaci.

Postup v prohlížeči:

1. Uživatel zadá **jediné heslo agendy** (URL úložiště je nezávislý netajný údaj). Preferovat náhodně generovanou dlouhou heslovou frázi; pro novou agendu doporučit tajemství alespoň se 128 bity entropie.
2. `K_master = PBKDF2-HMAC-SHA256(password, groupSalt, iterations, 32 B)`; výchozí `iterations = 600000`, parametr musí být verzovaný.
3. `K_enc = HKDF-SHA256(K_master, info="plavenky:data:v1|<canonicalGroup>", length=32 B)`.
4. `K_auth = HKDF-SHA256(K_master, info="plavenky:auth:v1|<canonicalGroup>", length=32 B)`.
5. `authToken = HKDF-SHA256(K_auth, info="plavenky:bearer:v1", length=32 B)`.
6. `authVerifier = SHA-256(authToken)`.

**Přesné kódování** řetězců `info`, reprezentace `canonicalGroup`, saltů, řetězce hesla (UTF-8/Unicode) a výstupů musí být jednoznačně specifikováno a pokryto testovacími vektory. Používat standardní **Web Crypto API**. Pro HKDF zvolit a explicitně verzovat konstantní salt podle definice protokolu; nedovolit různé klientské interpretace. Důsledně oddělit `K_enc` od `authToken`.

### 3.2 Autentizace vůči PHP

Při každém požadavku na chráněná data odešle klient **odvozený autentizační token**, nikoliv heslo nebo šifrovací klíč:

    Authorization: Bearer <base64url(authToken)>

Server ukládá pouze `authVerifier = SHA-256(authToken)` v neveřejném souboru. Na požadavku striktně ověří Base64url, očekávanou délku tokenu, spočítá jeho SHA-256 a porovná pomocí `hash_equals()` s uloženým verifierem. Chybějící nebo chybný token vrátí **HTTP 401**. Neposílat token v URL, logu, analytice nebo diagnostice. Přenos výhradně HTTPS; používat `Cache-Control: no-store`.

**Význam slova „hash“:** server neuchovává hash přímo použitelného šifrovacího klíče ani samotný autentizační token. Uchovává **hash autentizačního tokenu odvozeného z hesla**. Tím jeden uživatelský údaj slouží pro oba účely, ale uniklý bearer token není klíčem k AES-GCM.

**Omezení:** autentizační token je sdílený mezi uživateli skupiny a je opakovaně použitelný. Kompromitace tokenu dovolí přístup k serveru, ale sama o sobě neodhalí `K_enc`. Únik uloženého verifieru a saltu umožňuje offline hádání slabého hesla – vyžadovat silnou heslovou frázi a nákladnou KDF. Server sám může jako příjemce bearer token pozorovat, proto jej musí důvěryhodně chránit v paměti a v logování. Nelze odvolat jednu instanci bez změny společného hesla.

### 3.3 Šifrování

- Šifrovat **celý aplikační snapshot** přes AES-256-GCM.
- Pro každé šifrování vytvořit nový náhodný 96bitový nonce/IV (nesmí se opakovat se stejným klíčem).
- Ověřovat autentizační tag; v AAD vázat alespoň verzi protokolu, kanonickou identitu skupiny a typ šifrovaného objektu.
- Veřejná obálka může obsahovat verzi protokolu, použitý algoritmus, IV a ciphertext, nikoliv jména zaměstnanců, identifikátory čipů či obsah výpůjček.
- Heslo, `K_master`, `K_enc` a `authToken` držet jen v paměti běžící stránky. Nepersistovat je do `localStorage`, IndexedDB ani cookies. Po novém otevření/obnovení stránky vyžadovat heslo znovu.
- Chybné heslo nebo neplatný ciphertext nesmí přepsat lokální data.

## 4. PHP server a cesty skupin

### 4.1 Samostatný skript

Vytvořit **`sync.php`** pro PHP 8.x bez frameworku a databáze. Podporovat nasazení za Apache i Nginx/PHP-FPM. Skupinu určuje cesta za názvem skriptu (`PATH_INFO`):

    https://sync.example.cz/sync.php/oddeleni-a
    https://sync.example.cz/sync.php/organizace/tym-1

Pro tyto skupiny existují různé pracovní složky, hesla a snapshoty.

### 4.2 Pracovní složka z proměnné prostředí

Konfigurační proměnná:

    PLAVENKY_SYNC_STORAGE_TEMPLATE

Výchozí šablona:

    /working/{domenaRequestu}/{cestaRequestu}/data

Například `https://sync.example.cz/sync.php/oddeleni-a` používá:

    /working/sync.example.cz/oddeleni-a/data

`{domenaRequestu}` musí vycházet z **důvěryhodného, kanonického názvu virtuálního hostitele** (např. správcem nastavený `SERVER_NAME` / allowlist), **nikoli bez kontroly ze vstupní HTTP Host hlavičky**. `{cestaRequestu}` je kanonická relativní cesta skupiny odvozená z `PATH_INFO` po jediném URL dekódování.

Bezpečnost cest:

- Validovat doménu a jednotlivé segmenty skupiny. Povolit například `[A-Za-z0-9_.-]`, ale nepovolit prázdné segmenty, `.` a `..`.
- Odmítnout `\\`, NUL, encoded slash, neplatné procentové kódování, podezřelé dvojité kódování, traversal a nadměrně dlouhé cesty.
- Zakázat symlink průchod mimo kořen úložiště; kontrolovat výslednou cestu i při vytváření adresářů.
- Neplatný vstup odmítnout HTTP 400, **nesanitizovat odstraňováním znaků** tak, aby dvě různé URL splynuly.
- Úložiště je **mimo document root** a nedostupné přímým HTTP přístupem.

### 4.3 Struktura souborů

    <group-storage>/
      config.json          # KDF salt, parametry, protokol; veřejná část
      auth.verifier.json   # neveřejný authVerifier, nikdy nevydávat
      current.enc          # aktuální šifrovaný snapshot
      .sync.lock
      history/
        <uuid>.enc         # neměnné historické snapshoty

Backend neinterpretuje vnitřek `current.enc`. Nezpracovává role ani data evidence.

### 4.4 Diagnostický GET včetně cesty

    GET /sync.php/<group>

Vrátí JSON, například:

    {
      "service": "plavenky-sync",
      "configured": true,
      "initialized": true,
      "group": "oddeleni-a",
      "storage": { "accessible": true, "writable": true },
      "kdf": { "name": "PBKDF2-SHA256", "iterations": 600000, "salt": "<base64url>" },
      "errors": []
    }

Při nepřipraveném úložišti uvést `configured: false` a srozumitelné strojové kódy chyb (`INVALID_PATH`, `STORAGE_NOT_ACCESSIBLE`, `STORAGE_NOT_WRITABLE`, `CONFIG_CORRUPTED`, `LOCK_UNAVAILABLE` apod.). Diagnostika se vztahuje **na konkrétní požadovanou URL cestu**. Pokud data neexistují, vrátit `initialized: false`, což samo o sobě **není** chybou konfigurace.

GET neprovádí inicializaci a nesmí měnit agendu. Provozní diagnostika nesmí zveřejňovat verifier, interní tajemství ani zbytečně detailní absolutní systémové cesty. Případný test zápisové schopnosti může použít pouze jednorázový odstraněný testovací soubor, nikoli vytvoření skupinové agendy.

### 4.5 API

| Metoda | URL | Funkce | Autentizace |
| --- | --- | --- | --- |
| GET | `/sync.php/<group>` | Diagnostika, KDF metadata při existující skupině | Veřejná omezená diagnostika |
| POST | `/sync.php/<group>?object=initialize` | Prvotní atomická inicializace | Zvláštní bootstrap postup níže |
| GET | `/sync.php/<group>?object=current` | Číst ciphertext a ETag | Bearer |
| PUT | `/sync.php/<group>?object=current` | Atomický podmíněný zápis ciphertextu | Bearer |
| GET | `/sync.php/<group>?object=history` | Vypsat uložené verze | Bearer |
| GET | `/sync.php/<group>?object=history/<uuid>` | Přečíst historickou verzi | Bearer |
| PUT | `/sync.php/<group>?object=history/<uuid>` | Vytvořit neměnnou historickou verzi | Bearer |
| OPTIONS | `/sync.php/<group>` | CORS preflight | Bez přístupu k datům |

Zpřístupnit výhradně povolené objekty a metody. Všechny operace čtení/zápisu aplikačních dat vyžadují autentizaci. GET diagnostiky je omezený; veškerá další diagnostika vyžadující interní údaje patří do serverového logu.

### 4.6 Atomická inicializace

Po potvrzení v klientovi vygenerovat salt, odvodit klíče, vytvořit první šifrovaný snapshot a zavolat:

    POST /sync.php/<group>?object=initialize
    Authorization: Bearer <base64url(authToken)>
    If-None-Match: *

JSON tělo například:

    {
      "protocolVersion": 1,
      "kdf": { "name": "PBKDF2-SHA256", "iterations": 600000, "salt": "<base64url>" },
      "authVerifier": "<sha256-of-authToken>",
      "snapshot": "<base64url-of-encrypted-envelope>"
    }

Server nejprve ověří `SHA-256(authToken) == authVerifier` a zkontroluje velikost a formát vstupu, nikoli aplikační data. Inicializace musí být **atomická** pod zámkem, včetně bezpečné publikace KDF konfigurace, verifieru a `current.enc`: nesmí vzniknout trvale napůl inicializovaný stav. Při souběžné inicializaci smí uspět jen jeden klient; druhý dostane HTTP 412 a musí znovu načíst aktuální stav.

**Bezpečnost první inicializace:** Není-li skupina již založená, neexistuje společné tajemství, kterým by server mohl ověřit *prvního* zapisovatele. U veřejné předvídatelné cesty hrozí „first-claim“ obsazení cizím klientem. Požadovat provozní opatření: **náhodná dostatečně dlouhá neveřejná cesta skupiny** a/nebo přístupové omezení inicializace na reverzní proxy, případně předzaložení skupiny správcem. Běžný uživatel stále zadává jediné tajemství agendy; případná infrastrukturová ochrana inicializace se řeší mimo klientský formulář.

### 4.7 Aktuální data, ETag a If-Match

`GET ?object=current` vrací přesné šifrované bajty a silný `ETag`, např. SHA-256 souboru. `PUT ?object=current` vyžaduje `If-Match` s ETag naposledy načteného stavu. Chybějící podmínku odmítnout **428**; neodpovídající ETag odmítnout **412** bez jakékoli změny.

Implementovat:

1. Pod exkluzivním zámkem `flock()` ověřit současný ETag.
2. Zapsat kompletní nová data do jedinečného dočasného souboru ve stejném souborovém systému.
3. Proveďte flush (a tam, kde je dostupné, i fsync) před atomickým `rename()`.
4. Uvolnit zámek, vrátit nový ETag. Čtení musí zachovat konzistentní snapshot.
5. Při síťové chybě klient nejprve znovu načte skutečnou revizi; nesmí slepě opakovat commit.

Tato technická kontrola **není serverovou aplikační logikou**. PHP nečte roli hlavní instance ani data po dešifrování.

### 4.8 Historie a provozní opatření

Historické soubory jsou neměnné a vytvářejí se pouze s `If-None-Match: *`, s bezpečnými UUID názvy, limitovaným výpisem a autentizovaným přístupem. Obnova historického snapshotu znamená **nový** potvrzený snapshot, nikoli přepsání historie.

Dále zajistit HTTPS, CORS allowlist originů, `Access-Control-Allow-Headers: Authorization, Content-Type, If-Match, If-None-Match`, `Access-Control-Expose-Headers: ETag`, `Cache-Control: no-store`, limit velikosti souboru (výchozí 10 MiB), zákaz nezáměrného logování Authorization, kontrolu počtu verzí, chybové kódy bez úniku citlivých údajů a nezávislou zálohu úložiště. Dokumentovat předpoklad atomického `rename()` a správně fungujícího `flock()` na konkrétním filesystemu.

## 5. Klientská správa synchronizace

V záložce **Správa dat** vytvořit sekci „Synchronizace“:

- URL synchronizačního úložiště (včetně cesty skupiny).
- **Jedno heslové pole** „Heslo agendy“.
- „Ověřit připojení“, „Připojit“, „Odpojit“, „Synchronizovat“.
- Podle role „Předat agendu“ a „Převzít agendu“.
- Status: lokální identifikátor instance, hlavní/záložní, revize, poslední úspěšná synchronizace, stav serveru a chyba.

### 5.1 Vypnutý stav

Bez URL nebo bez hesla zadaného v aktuální relaci nesmí běžet žádná automatická synchronizace. Aplikace funguje jako dosud lokálně. URL je možné trvale uložit, ale **heslo, klíče a autentizační token nikoli**. Je-li URL nastavena a heslo zrovna chybí, jasně ukázat „Synchronizace pozastavena – zadejte heslo“; neslibovat, že lokálně provedené změny byly odeslány.

### 5.2 Připojení a ověření

1. Diagnostický GET pro zadanou skupinu. Pokud `configured=false`, zobrazit důvod a skončit bez změny dat.
2. Pokud `initialized=true`, načíst veřejné KDF parametry, odvodit `K_enc` a `authToken`, provést autentizovaný GET current a dešifrovat. Ověřit AES-GCM, AAD, formát a referenční integritu agendy.
3. Chybné heslo vede k HTTP 401, poškozený ciphertext či nekompatibilní formát hlásit odděleně. Nikdy nepřepisovat lokální data při chybě.
4. Pokud se vzdálená a lokální agenda liší, **nikdy je bez potvrzení nesloučit ani nepřepsat**. Před použitím vzdálených dat vytvořit lokální zálohu a vyžádat explicitní rozhodnutí uživatele.
5. Po úspěšném připojení aktivovat synchronizaci; role se čte z aktuálního dešifrovaného snapshotu.

### 5.3 Inicializace prázdného úložiště

Pokud diagnostika vrátí `initialized=false`, nabídnout dialog:

> „Na tomto úložišti zatím nejsou data. Chcete založit novou synchronizovanou agendu z aktuálních lokálních dat?“

**Teprve po souhlasu** vytvořit salt, klíče/token, počáteční šifrovaný snapshot s `revision=1`, `epoch=1` a touto instancí jako hlavní, pak atomicky inicializovat server. Při konfliktu inicializace (412) nabídnout načtení existující agendy místo přepsání.

### 5.4 Jediná hlavní instance a nouzové převzetí

Zašifrovaný snapshot obsahuje přibližně:

    {
      "protocolVersion": 1,
      "snapshotId": "uuid",
      "parentSnapshotId": "uuid-nebo-null",
      "revision": 17,
      "primaryInstanceId": "uuid",
      "epoch": 6,
      "chips": [],
      "employees": [],
      "borrowings": []
    }

ID lokální instalace generovat kryptograficky (`crypto.randomUUID()`), nenahrazovat jím autentizaci. Hlavní smí zapisovat jen po načtení aktuálního stavu, ověření vlastní role a přes `PUT If-Match`. Záložní má běžně pouze čtení. Při řádném či nouzovém převzetí záložní zvýší `epoch` a změní `primaryInstanceId` **atomickým podmíněným commitem**; původní počítač nemusí být přítomen. Při souběhu rozhodne jeden úspěšný PUT, ostatní dostanou 412 a stav znovu načtou.

Dojde-li ke ztrátě spojení, v synchronizovaném režimu **zakázat potvrzování nových zápisů**. Lze zobrazit poslední lokální kopii. Server neumí rozpoznat nepoctivého klienta, který se znalostí hesla přepíše roli, ale při správném použití aplikace zajistí podmíněné zápisy návaznost revizí.

### 5.5 Synchronizační cyklus a transakční datová vrstva

- Kontrolovat novou revizi například každých 30 s a při návratu stránky do popředí.
- Vytvořit centralizované API `DataStore.commit(mutator)`, které pracuje s **jedinou pracovní kopií všech tří kolekcí**.
- Před změnou stáhnout aktuální stav a ETag, ověřit roli, aplikovat mutaci, šifrovat novou revizi, uložit historickou kopii a potvrdit PUT current s If-Match.
- **Teprve po úspěšném serverovém potvrzení** zobrazit změnu jako uloženou a aktualizovat autoritativní lokální data.
- Při HTTP 412, 401 a síťové chybě se nesmí lokální změna tiše prohlásit za úspěšnou; načíst stav a nabídnout nové provedení/řešení konfliktu.
- Zachovat kompatibilitu původních JSON/XLSX importů/exportů; import všech dat musí být jediná transakce.
- Lokální režim bez synchronizace musí zůstat funkční.

## 6. Odstranění XSS před nasazením kryptografie

V `index-dev.html` provést revizi všech použití `innerHTML`, `outerHTML`, `insertAdjacentHTML`, dynamických šablon a inline `onclick`. Riziková pole zahrnují jména, zaměstnanecká čísla, odbory, identifikátory čipů, seznamy výpůjček, autocomplete a importované hodnoty.

1. Vkládat uživatelská data přes `textContent` / bezpečné DOM uzly (`createElement`, `append`, `addEventListener`), nikoli neescapované HTML.
2. Přepracovat inline event handlery; nepředávat uživatelská data do kódu JavaScriptu.
3. Zabezpečit zobrazení Markdown nápovědy a import JSON/XLSX.
4. Přibalit/připnout externí JS knihovny a připravit restriktivní CSP kompatibilní s aplikací.
5. Oddělit DEV a PROD origin/úložiště, aby se vývojová verze nedotýkala reálných produkčních dat.
6. Ověřit, že telemetrie neposílá žádný plaintext evidence, heslo, token ani šifrovací klíče.
7. Přidat regresní testy pro HTML/JS payloady v zadání údajů, editacích, importech i zobrazení.

Důvod: XSS v aplikaci s E2EE může zneužít již dešifrovaná data a získat heslo/token, i když je šifrování samo kryptograficky správně.

## 7. Testy a akceptační kritéria

### Server

- Diagnostika pracuje s konkrétní URL cestou, správně hlásí configured/initialized a problém s právy nebo zámky.
- Výchozí úložiště odpovídá `/working/{domenaRequestu}/{cestaRequestu}/data`; proměnná prostředí jej správně přepisuje.
- Dvě různé URL skupiny nesdílejí salt, verifier ani data.
- Traversal, encoded slash, symlink a neplatná doména jsou odmítnuty.
- Bez autentizace nelze číst/zapisovat current ani history.
- Server ukládá **pouze hash tokenu** a při žádné odpovědi neprozradí verifier, klíč či heslo.
- Správný token umožní přístup pouze ke skupině, pro kterou je odvozen.
- Inicializace je atomická a při souběhu může uspět pouze jednou.
- Dva současné PUT se stejným ETag nemohou oba uspět; 412/428 fungují.
- Při výpadku uprostřed zápisu zůstane použitelný poslední potvrzený stav.
- Ověřena CORS pravidla, limity velikostí, absence úniku Authorization do logu.

### Klient

- Bez URL nebo hesla nesynchronizuje.
- Uživatel zadává pouze **jedno tajemství**; derivace šifrování a autentizace se ověřuje testovacími vektory.
- Prázdné úložiště se inicializuje výhradně po potvrzení.
- Druhá instance se stejným heslem stáhne a dešifruje data.
- Chybné heslo způsobí 401; poškozená data se nenahrají do lokální evidence.
- Záložní instance nedělá běžné zápisy, ale umí nouzově převzít agendu bez původního zařízení.
- Původní instance po převzetí nemůže přepsat novější stav starým ETag.
- Při offline provozu nelze potvrdit změny.
- Při konfliktu nepřijde o dříve potvrzené změny a nabídne bezpečné řešení.
- Regresní XSS testy nevykonají útočný kód.

## 8. Implementační fáze

1. **Zabezpečení klienta:** odstranit XSS; opravit nebezpečné DOM konstrukce; přidat testy.
2. **Datová vrstva:** centralizovaný commit snapshotu, migrace lokálních dat, zachování exportů/importů.
3. **PHP server:** `sync.php`, směrování skupin, konfigurace cesty, diagnostika GET, bootstrap, autentizace, chráněné GET/PUT, ETag/If-Match, historie, testy.
4. **Kryptografie klienta:** PBKDF2/HKDF/AES-GCM, verze formátu, authToken/verifier, testovací vektory.
5. **Rozhraní Správa dat:** URL, heslo, ověření, připojení a inicializace po souhlasu, stav synchronizace.
6. **Synchronizační logika:** periodický polling, role hlavní/záložní, řádné i nouzové převzetí, řešení konfliktů.
7. **Dokumentace a akceptace:** návod pro Apache/Nginx/PHP-FPM, proměnné prostředí, ochrana bootstrapu, testování dvou instancí, README/CHANGELOG.

**Výstupy:** funkční `index-dev.html`, nový samostatný `sync.php`, automatické testy, dokumentace provozu a instalace. Produkční `index.html` neměnit bez výslovného schválení.

## 9. Bezpečnostní a provozní omezení

- Server ověřuje **skupinové sdílené tajemství**, nikoli jednotlivé osoby. Není možné odvolat jednu instanci bez změny hesla, přešifrování a výměny verifieru.
- Slabé heslo lze při získání verifieru a saltu hádat offline. Požadovat silné náhodné heslo, zálohovat je organizačně.
- Server nebo kompromitovaná infrastruktura může ciphertext smazat, zadržet nebo vrátit starší verzi; E2EE negarantuje dostupnost ani absolutní ochranu před rollbackem. Zálohovat nezávisle.
- Předvídatelnou prázdnou URL může obsadit útočník při prvním bootstrapu. Použít těžko uhádnutelnou skupinovou cestu nebo infrastrukturní omezení inicializace.
- Kompromitace klientského JavaScriptu/XSS může odhalit heslo, autentizační token nebo dešifrovaná data.
- Doména, název skupiny, salt, objem a čas komunikace jsou provozní metadata a nemusí být šifrována.
- Atomické `flock()` a `rename()` musí na cílovém filesystemu skutečně fungovat. Bez nich nelze zaručit správné souběžné commity.