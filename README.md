# MyRenault für IP-Symcon

Bidirektionale Anbindung von Renault-Elektrofahrzeugen (ZOE, Megane E-Tech, Scenic E-Tech, R5, R4, Twingo E-Tech …) über die My-Renault-Cloud (Gigya-Login + Kamereon-API) – mit HTML-Kachel für die Tile-Visualisierung.

## Funktionen

| Bereich | Lesen | Schreiben |
|---|---|---|
| Laden | Akkustand, Reichweite, Stecker, Ladestatus, Ladeleistung, Restladezeit, Akkutemperatur | Laden starten/stoppen, Lademodus (Sofort / Zeitplan), Ladezeitplan |
| Klima | Klimastatus, Außentemperatur | Vorklimatisierung starten/stoppen mit Zieltemperatur (16–27 °C) |
| Fahrzeug | Kilometerstand, GPS-Position | – |

Alle schaltbaren Variablen (`Laden`, `Lademodus`, `Klimatisierung`, `Zieltemperatur`) haben eine Aktion und funktionieren auch im klassischen WebFront, in Ablaufplänen und Skripten.

## Voraussetzungen

- IP-Symcon ab 7.0 (HTML-Kachel); die Variablen funktionieren auch in älteren Versionen
- My-Renault-Konto mit eingebundenem Fahrzeug

## Installation

1. Ordner `SymconMyRenault` in ein Git-Repository legen und im Module Control hinzufügen, **oder** direkt nach `<Symcon>/modules/SymconMyRenault` kopieren und den Dienst neu starten.
2. Instanz **MyRenault** anlegen.
3. E-Mail, Passwort und Land eintragen → **Übernehmen**.
4. **Verbindung testen / Fahrzeuge suchen** drücken, Formular neu öffnen, Fahrzeug auswählen.
5. In der Tile-Visualisierung die Instanz als Kachel hinzufügen.

## E3DC-Wallbox (optional)

Eine bereits in IP-Symcon eingebundene E3DC-Wallbox kann mit in der Kachel erscheinen. Im Formular unter **E3DC-Wallbox** die vorhandenen Variablen auswählen:

| Feld | Inhalt |
|---|---|
| Wallbox-Leistung / Solarleistung | Leistung in W (Modbus) |
| WallBox_x_CTRL-Register | Bitfeld des E3DC-Registers 40088 ff.: Stecker, Verriegelung, Laden, Sperre, Phasen |
| Lademodus / Phasen | Bool-Variablen; schaltbar aus der Kachel, wenn die Variable eine Aktion hat. Die Beschriftung kommt aus dem Variablenprofil |
| Wirkarbeit / Solar-Wirkarbeit | Energie in kWh |
| Online-Status | beliebige Statusvariable, wird formatiert angezeigt |
| Solarprognose (optional) | Solarfenster Beginn/Ende (Unix-Zeit), Solarlademenge heute/morgen (kWh), Empfehlung (Text), z. B. aus einem eigenen Prognoseskript |
| Zielladen (optional) | Aktiv (Bool), Ziel-Ladestand (%, Integer), Abfahrt (Stunde 0–23, Integer), Status (Text). Ziel und Abfahrt sind in der Kachel mit −/+ einstellbar; Schritte und Grenzen kommen aus dem Variablenprofil. Planung und Umschalten der Wallbox macht dein eigenes Skript |

Die Kachel zeigt Ladeleistung, Solaranteil, Steckerstatus und die Schalter für Lademodus und Phasen. Sie aktualisiert sich sofort, wenn sich eine der Variablen ändert. Geschaltet wird über die Aktion der Variablen selbst (`RequestAction`), also über deine vorhandenen Skripte.

## PHP-Befehle

```php
MRN_Update(int $InstanzID): bool
MRN_StartClimate(int $InstanzID, float $Temperatur): bool
MRN_StopClimate(int $InstanzID): bool
MRN_StartCharging(int $InstanzID): bool
MRN_StopCharging(int $InstanzID): bool
MRN_SetChargeMode(int $InstanzID, int $Modus): bool   // 0 = sofort, 1 = Zeitplan
MRN_GetChargeSchedules(int $InstanzID): string        // JSON im Kamereon-Format
MRN_SetChargeSchedule(int $InstanzID, string $JSON): bool
MRN_DiscoverVehicles(int $InstanzID): string
```

Beispiel Zeitplan (Zeiten in **UTC**, Dauer in Minuten):

```php
MRN_SetChargeSchedule(12345, json_encode([[
    'id' => 1, 'activated' => true,
    'monday'  => ['startTime' => 'T21:00Z', 'duration' => 420],
    'tuesday' => ['startTime' => 'T21:00Z', 'duration' => 420]
]]));
```

Beispiel PV-Überschussladen in einem Ereignis-Skript:

```php
$ueberschuss = GetValue(11111); // W
if ($ueberschuss > 2000) MRN_StartCharging(12345);
elseif ($ueberschuss < 500) MRN_StopCharging(12345);
```

## Hinweise

- **Inoffizielle API.** Renault stellt keine öffentliche Schnittstelle bereit; das Modul nutzt dieselben Endpunkte wie die App (Vorlage: Projekt *renault-api* von hacf-fr). Renault kann Endpunkte oder API-Keys jederzeit ändern. Schlägt die Anmeldung plötzlich fehl, die Schlüssel unter *Experteneinstellungen* mit der `const.py` von renault-api abgleichen.
- **Rate-Limit:** Nicht öfter als alle 10–15 Minuten abfragen. Nach einem Befehl fragt das Modul nach 90 s automatisch einmal nach.
- **Neuere Modelle** (Megane E-Tech, Scenic, R5, R4): Für Laden Start/Pause die Option *KCM-Endpunkt* aktivieren. Nicht jedes Fahrzeug unterstützt jeden Endpunkt; nicht verfügbare Werte bleiben leer (siehe Debug-Ausgabe).
- Zugangsdaten und Tokens werden nicht in die Debug-Ausgabe geschrieben.
- `vorschau.html` zeigt die Kachel mit Beispieldaten im Browser (nicht Teil des Moduls).
