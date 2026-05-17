---
layout: home

hero:
  name: "NENE2"
  text: "Minimales PHP API Framework"
  tagline: JSON APIs schnell bauen. OpenAPI und MCP eingebaut. Vom ersten Tag an KI-bereit.
  actions:
    - theme: brand
      text: Loslegen →
      link: /de/tutorial/first-api
    - theme: alt
      text: Auf GitHub ansehen
      link: https://github.com/hideyukiMORI/NENE2
    - theme: alt
      text: Packagist
      link: https://packagist.org/packages/hideyukimori/nene2

features:
  - icon: 🚀
    title: In Minuten einsatzbereit
    details: Ein einfaches composer require hideyukimori/nene2 und Sie haben eine laufende JSON API mit Health Checks, Request IDs und Problem Details Fehlern — bevor Sie eine einzige Route schreiben.

  - icon: 📄
    title: OpenAPI zuerst
    details: Jeder Endpoint, den Sie erstellen, kommt mit einem OpenAPI-Vertrag. Swagger UI ist inklusive. Der Vertrag ist das, was Sie Ihrem Kunden übergeben, kein nachträglicher Gedanke.

  - icon: 🤖
    title: MCP-bereit
    details: Ein lokaler MCP-Server stellt Ihre API als Werkzeuge bereit, die KI-Agenten (Claude, Cursor) direkt aufrufen können. Keine spezielle Integration — er liest aus Ihrem OpenAPI-Katalog.

  - icon: 🛡️
    title: RFC 9457 Fehler
    details: Jede Fehlerantwort ist ein Problem Details Objekt — eine maschinenlesbare JSON-Struktur mit type, title, status und detail. Keine rohen Ausnahmen in der Produktion.

  - icon: 🧱
    title: Saubere Architektur
    details: UseCase → RepositoryInterface → PDO-Adapter. Jede Schicht isoliert testbar. Kein Magic, keine versteckte Verdrahtung, kein Framework, das in Ihre Domäne eindringt.

  - icon: 🔬
    title: PHPStan Level 8
    details: Statische Analyse auf höchster Stufe. Wenn es PHPStan besteht, überrascht es Sie nicht zur Laufzeit. Funktioniert sofort mit PHPUnit und PHP-CS-Fixer.
---
