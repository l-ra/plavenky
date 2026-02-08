# Changelog

Všechny významné změny v projektu Evidence plaveckých čipů budou dokumentovány v tomto souboru.

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.0.0/)
a projekt se řídí [Semantic Versioning](https://semver.org/lang/cs/).

## [1.0.0] - 2026-02-08

### Přidáno
- Správa čipů (přidávání, mazání, import/export XLSX)
- Správa zaměstnanců s údaji: jméno, číslo, odbor (přidávání, úpravy, mazání, import/export XLSX)
- Správa výpůjček čipů s možností zadání více čipů na jednu výpůjčku
- Autocomplete pro zadávání zaměstnanců a čipů
- Export výpůjček za měsíc do XLSX formátu
- Import/export seznamu výpůjček ve formátu XLSX
- Filtrování výpůjček podle zaměstnance, čipu a stavu
- Validace datumů (začátek musí být před koncem)
- Správa dat: export/import všech dat do JSON, přehled měsíců s výpůjčkami, mazání výpůjček za měsíc
- Customizované modální dialogy (nahrazují nativní alert/confirm/prompt)
- Nápověda zobrazující obsah README.md (s fallbackem pro file:// protokol)
- Responzivní menu s hamburger ikonou na mobilních zařízeních
- Systém sběru anonymních statistik (volitelný)
  - Tracking klíčových událostí v aplikaci
  - PHP backend pro příjem a ukládání statistik
  - Webové rozhraní pro zobrazení statistik
  - Ochrana soukromí - sbírají se pouze agregované údaje
- Zobrazení verze aplikace v patičce
- Odkaz na GitHub repozitář v patičce

### Technické
- Jednosoborová HTML aplikace běžící kompletně v prohlížeči
- Ukládání dat do localStorage
- Podpora XLSX importu/exportu pomocí knihovny SheetJS
- Markdown rendering pomocí knihovny marked.js
- Responzivní design optimalizovaný pro desktop i mobilní zařízení
- CORS podpora v PHP endpointech pro statistiky
- JSON Lines formát pro ukládání statistik (jeden soubor na měsíc)
- Konfigurovatelná složka pro statistiky přes proměnnou prostředí

[1.0.0]: https://github.com/l-ra/evidence-plavenek/releases/tag/v1.0.0
