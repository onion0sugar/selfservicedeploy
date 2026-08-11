# Plugin GLPI `selfservicedeploy` (wariant jednoelementowy)

Jeden plugin w GLPI robi wszystko: stronę self-service, tokeny, CSRF,
rate limit, blokadę równoległych restartów, audit log i zlecenie deploymentu.
**Brak osobnej aplikacji, Docker i osobnej bazy.**

## Zasady bezpieczeństwa (twarde)

- Jedyny parametr całego systemu to **token w URL**. Strona nie przyjmuje
  żadnych pól: `command`, `service_name`, `package_id`, `computer_id`,
  kodu — nic z tego nie istnieje w formularzu.
- Komenda restartu usług (`OracleServiceXE`, `UpTimeServiceOffline`) żyje
  **na stałe** w pakiecie GLPI Inventory (`glpi-package/volvo_restart.bat`).
- Własne tabele pluginu (`tokens`, `logs`, `locks`) — dane glpiinventory
  są modyfikowane wyłącznie przez jego klasy
  (`PluginGlpiinventoryTaskjobstate::add()` itd.), zero SQL na nich.

## Instalacja

1. Skopiuj katalog `selfservicedeploy/` do `<GLPI>/plugins/selfservicedeploy`.
2. W GLPI: *Setup → Plugins → Self Service Deploy → Install → Enable*
   (install tworzy 3 własne tabele).
3. **Konfiguracja z poziomu GLPI** — *Setup → General → zakładka
   „Self Service Deploy”*: ustaw ID joba (taskjob), ewentualnie ID pakietu,
   wakeup, rate limit itd. Zakładka pokazuje też podgląd aktualnego
   deploymentu (nazwa joba, method, aktywność taska, pakiety) i ostrzega
   o błędach. `config.php` służy wyłącznie jako domyślne wartości.

> Uwaga: po zmianie wersji pluginu (np. dodaniu nowych pól) kliknij w GLPI
> *Setup → Plugins → Self Service Deploy* — przycisk aktualizacji (jeśli
> dostępny).

## Użycie

```bash
# utwórz token/link dla komputera (na serwerze GLPI):
php <GLPI>/plugins/selfservicedeploy/scripts/token_cli.php create \
    --computer-id 42 --name "PC-123" --url-prefix https://glpi.example.org

# lista tokenów / włącz-wyłącz:
php <GLPI>/plugins/selfservicedeploy/scripts/token_cli.php list
php <GLPI>/plugins/selfservicedeploy/scripts/token_cli.php set-enabled --token <t> --enabled false
```

Link użytkownika:
`https://glpi.example.org/plugins/selfservicedeploy/front/restart.php?token=<t>`

## Endpoint

| Metoda | Ścieżka | Opis |
| ------ | ------- | ---- |
| GET  | `/plugins/selfservicedeploy/front/restart.php?token=<t>` | strona z nazwą komputera + przycisk (cookie CSRF) |
| POST | jw. (formularz) | weryfikacja token/CSRF/rate limit/blokada → zlecenie joba → audit |

### Kody odpowiedzi (POST)

| Kod | Znaczenie |
| --- | --------- |
| 200 | "Restart został zlecony". |
| 403 | Wyłączony token / zły CSRF / złe Origin. |
| 404 | Nieprawidłowy link (token nie istnieje). |
| 409 | Restart już w kolejce/w trakcie (blokada lub already_pending w glpiinventory). |
| 429 | Rate limit: 1 restart / komputer / 2 min. |
| 500 | Błędna konfiguracja / nieaktywny task / brak pakietu / błąd bazy. |

## Audit log

```sql
SELECT timestamp, computer_id, token_id, action, result, source_ip, detail
FROM glpi_plugin_selfservicedeploy_logs ORDER BY id DESC LIMIT 20;
```

## Dlaczego tak, a nie "bezpośrednio agent"

Agent GLPI nie ma API do wykonywania komend: jego HTTP (port 62354) obsługuje
tylko wakeup/status i sam weryfikuje zaufanych nadawców. Jedynym kanałem
wykonania akcji z uprawnieniami agenta (SYSTEM) jest moduł **deploy**
GLPI Inventory — dlatego plugin zleca deployment, a nie "woła agenta wprost".

## Weryfikacja nazw klas na instalacji

```bash
P=<GLPI>/plugins/glpiinventory
grep -l "class PluginGlpiinventoryTaskjobstate" $P/inc/
grep -l "class PluginGlpiinventoryTaskjoblog"    $P/inc/
grep -n "PREPARED\s*="      $P/inc/taskjobstate.class.php   # oczekiwane: 0
grep -n "TASK_PREPARED\s*=" $P/inc/taskjoblog.class.php     # oczekiwane: 7
```
