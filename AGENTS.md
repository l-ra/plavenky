# Pokyny pro práci agenta

## Vývojová vs. produkční verze

- Všechny průběžné vývojové změny prováděj výhradně v souboru `index-dev.html`.
- Soubor `index.html` je produkční verze a upravuj ho pouze na výslovný pokyn uživatele.

## Zahájení vývoje a verzování `index-dev.html`

- Při zahájení úprav vždy nejprve zkontroluj, zda existuje `index-dev.html`.
- Pokud `index-dev.html` neexistuje, vytvoř ho jako kopii `index.html`.
- Vývojová verze v `index-dev.html` musí být vždy o **1 minor** vyšší než produkční verze v `index.html` a musí mít příponu `-dev`.
- Verzi navyšuj podle semver pravidla: `MAJOR.MINOR.PATCH` -> zvýšit `MINOR` o 1, `PATCH` nastavit na `0`, přidat `-dev`.
- Příklad: produkce `1.2.3` -> vývoj `1.3.0-dev`.

## Nasazení vývojové verze do produkce

- Při výslovném pokynu k nasazení přenes celý obsah `index-dev.html` do `index.html`.
- Jediné povolené rozdíly při nasazení:
  - odstranit příponu `-dev` z označení verze,
  - odstranit viditelné označení vývojové verze (např. DEV badge, upozornění v UI, DEV označení v titulku).
- Mimo uvedené body musí být produkční soubor funkčně i obsahově shodný s `index-dev.html`.
