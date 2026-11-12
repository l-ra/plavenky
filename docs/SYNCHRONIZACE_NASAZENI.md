# Plavenky: nasazení šifrované synchronizace

**Rozsah:** PHP server sync.php a vývojový klient index-dev.html (1.6.0-dev). Produkční index.html zůstává beze změny.

## Princip

Klienti používají jedinou společnou heslovou frázi. Z hesla a náhodného saltu skupiny odvodí PBKDF2-HMAC-SHA256 (600 000 iterací) hlavní klíč, HKDF pak oddělený šifrovací klíč AES-256-GCM a bearer token pro přístup k serveru. PHP uchovává pouze SHA-256 hash autentizačního tokenu a šifrované soubory. Server nikdy nezíská heslo, hlavní ani datový šifrovací klíč.

Kdo má heslo a URL skupiny, může data dešifrovat a po připojení převzít hlavní roli; jednotlivá zařízení nejsou kryptograficky odlišena.

## PHP server

Nasadit samostatný sync.php na PHP 8+ za HTTPS a zajistit podporu PATH_INFO, Authorization hlavičky, flock() a atomického rename() na jednom filesystemu. Úložiště musí být mimo document root.

Konfigurační proměnné:

| Proměnná | Význam |
|---|---|
| PLAVENKY_SYNC_STORAGE_TEMPLATE | Výchozí: /working/{domenaRequestu}/{cestaRequestu}/data |
| PLAVENKY_SYNC_DOMAIN | Důvěryhodný název vhostu, doporučeně explicitně nastavit |
| PLAVENKY_SYNC_ALLOWED_ORIGINS | Čárkami oddělené povolené originy klientů, např. https://l-ra.github.io |
| PLAVENKY_SYNC_TRUST_PROXY | 1 pouze za důvěryhodnou proxy, která bezpečně nastavuje X-Forwarded-Proto |
| PLAVENKY_SYNC_ALLOW_HTTP | 1 jen pro lokální loopback test; v produkci 0 |

Příklad URL: https://sync.example.cz/sync.php/organizace/tym-a

Výchozí fyzická cesta: /working/sync.example.cz/organizace/tym-a/data

Používá se kanonická doména a kanonická cesta skupiny. Neplatné segmenty, traversal a percent-encoding URL cesty jsou odmítnuty. Podkladové adresáře musí být čitelné/zapisovatelné PHP procesem. Pro první inicializaci může vzniknout data adresář až při prvním zápisu; GET jej nevytváří.

Apache: pro sync.php povolit AcceptPathInfo On a předávat Authorization do PHP. Pro Nginx/PHP-FPM:

    location ~ ^/sync\.php(/.*)?$ {
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/plavenky/sync.php;
        fastcgi_param SCRIPT_NAME /sync.php;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

Cesty, socket a proměnné prostředí upravit pro konkrétní instalaci. Pokud HTTP provoz končí na reverse proxy, přístup k X-Forwarded-Proto musí být omezen výhradně na důvěryhodnou proxy.

## Kontrola konfigurace

Neautentizovaný diagnostický požadavek:

    curl https://sync.example.cz/sync.php/organizace/tym-a

Příklad odpovědi:

    {"service":"plavenky-sync","configured":true,"initialized":false,
     "group":"organizace/tym-a",
     "storage":{"accessible":true,"writable":true},"errors":[]}

Initialized=false není chyba; znamená, že skupina dosud nemá data. Při chybě configured=false a seznam kódů závad. Po inicializaci GET vrací i veřejné parametry KDF a salt, nikdy nikoli uložený autentizační verifier.

## Endpointy

| HTTP | Objekt | Funkce |
|---|---|---|
| GET | bez object | Diagnostika a KDF metadata, veřejné |
| POST | ?object=initialize | Atomická první inicializace, If-None-Match: * |
| GET | ?object=current | Čtení šifrovaného snapshotu + ETag |
| PUT | ?object=current | Uložení snapshotu, If-Match |
| GET | ?object=history | Výpis historických UUID |
| GET | ?object=history/UUID | Historický snapshot |
| PUT | ?object=history/UUID | Neměnná verze, If-None-Match: * |

Pro všechna ostatní volání je nutný Authorization: Bearer TOKEN (TOKEN odvozuje klient, není to heslo!). Při neshodě tokenu server vrátí HTTP 401, při kolizi revize 412 a při chybějící podmínce 428. Souběžné podmíněné zápisy se synchronizují flock a atomickým rename.

Nenastavovat veřejně dostupné statické soubory v pracovním úložišti. Token neuvádět do URL, logů, analytiky ani chybových zpráv. Používat pouze HTTPS.

## Nastavení ve webové aplikaci

1. Otevřít index-dev.html z HTTPS originu (pro testování oddělený profil nebo oddělený origin od produkce).
2. V záložce Správa dat → Synchronizace zadat URL skupiny včetně sync.php/nazev-skupiny a silné společné heslo.
3. Kliknout na Ověřit a připojit.
4. Je-li skupina nová, aplikace nabídne potvrzení její inicializace z aktuálních lokálních dat. Heslo pro novou skupinu musí mít alespoň 20 znaků; doporučena je náhodná fráze s vysokou entropií.
5. Další uživatelé zadají stejnou URL a heslo; získají záložní instanci a dešifrovanou kopii agendy.
6. Hlavní instance může předat roli zadáním UUID jiné instance. Záložní instance může nouzově převzít roli bez součinnosti původního počítače.
7. Pokud se lokální a vzdálené údaje liší, klient nabídne lokální JSON zálohu před jejich nahrazením. Automatické slučování odlišných agend není součástí první verze.

Heslo, autentizační token ani klíč se trvale neukládají do prohlížeče. Po obnovení stránky je třeba znovu zadat heslo. URL se ukládá do localStorage, bez aktivního připojení synchronizace neprobíhá.

Při připojeném režimu hlavní instance před každým zápisem načte aktuální revizi a potvrzuje změny pomocí If-Match. Záložní instance může číst, nikoli běžně zapisovat. Po výpadku spojení nelze změnu potvrdit; klient ponechá poslední dostupnou lokální kopii.

## Bezpečnost a provozní omezení

- Server zná hash bearer tokenu, nikdy šifrovací klíč ani plaintext.
- Skupiny mají oddělenou cestu, salt a heslo.
- Sdílené heslo není vazbou na fyzické zařízení; odvolání jednotlivé osoby vyžaduje výměnu hesla a přešifrování dat.
- PHP neověřuje, která instance je hlavní. Neautorizovaný nebo upravený klient, který zná heslo, může vytvořit vlastní snapshot; atomické ETag řeší souběhy, ne individuální oprávnění.
- Klientský JavaScript je součástí důvěryhodného bezpečnostního perimetru. XSS, kompromitace originu nebo externí knihovny mohou zpřístupnit plaintext.
- Pouhé šifrování neřeší dostupnost, výmaz dat ani záměrné vracení starých verzí serverem; vést nezávislé zálohy pracovního adresáře a chránit je.
- Nechráněnou předvídatelnou prázdnou cestu může třetí strana inicializovat dříve než vlastník; před první inicializací použít náhodnou neveřejnou cestu nebo omezení přístupu na proxy.
- Z historie lze obnovit šifrovaný snapshot; obnova vytvoří novou autoritativní revizi. Starší historie může vyžadovat původní heslo po jeho změně.

## Testy

    php -l sync.php
    node tests/sync-crypto.mjs
    node tests/sync-integration.mjs

GitHub Actions workflow .github/workflows/sync-ci.yml spouští PHP syntax check, WebCrypto testy a HTTP testy včetně dvou izolovaných klientů a nouzového převzetí. Produkční index.html nebyl změněn.
