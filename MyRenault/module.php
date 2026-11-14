<?php

declare(strict_types=1);

/**
 * MyRenault – bidirektionale Schnittstelle zur My-Renault-Cloud (Gigya + Kamereon)
 *
 * Lesen:     Akku, Reichweite, Stecker-/Ladestatus, Ladeleistung, Restladezeit,
 *            Kilometerstand, Außentemperatur, Klimastatus, Lademodus, Ladezeitplan, Position
 * Schreiben: Klimatisierung Start/Stop (mit Zieltemperatur), Laden Start/Stop,
 *            Lademodus (Sofort/Zeitplan), Ladezeitplan
 * Visualisierung: HTML-Kachel (Tile-Visualisierung, IP-Symcon ≥ 7.0)
 */
class MyRenault extends IPSModule
{
    // aktueller Gigya-Schlüssel für alle EU-Länder (Quelle: renault-api, const.py)
    private const GIGYA_KEY = '3_VgdkgtIRH3AdHvJm-cjV2ug2EFE0lxt0IJzMC4MFqZjFpn_GYFXVdNZ19L7wZX0N';
    private const OBSOLETE_GIGYA_KEYS = [
        '3_7PLksOyBRkHv126x5WhHb-5pqC1qFR8pQjxSeLB6nhAnPERTUlwnYoznHSxwX668'
    ];

    private const PLUG_STATES = [
        0  => 'Nicht eingesteckt',
        1  => 'Eingesteckt',
        -1 => 'Steckerfehler'
    ];

    // Kamereon-Ladezustände (Float-Codes) → Integer-Code für das Profil
    private const CHARGE_STATES = [
        '0.0'  => [0, 'Lädt nicht'],
        '0.1'  => [1, 'Wartet auf Zeitplan'],
        '0.2'  => [2, 'Ladung beendet'],
        '0.3'  => [3, 'Wartet auf Strom'],
        '0.4'  => [4, 'Ladeklappe offen'],
        '1.0'  => [10, 'Lädt'],
        '-1.0' => [-1, 'Ladefehler'],
        '-1.1' => [-2, 'Nicht verfügbar']
    ];

    private $lastError = '';

    // ------------------------------------------------------------------ Lebenszyklus

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('Locale', 'de_DE');
        $this->RegisterPropertyString('VIN', '');
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyBoolean('ReadLocation', true);
        $this->RegisterPropertyBoolean('UseKCM', false);
        $this->RegisterPropertyString('GigyaURL', 'https://accounts.eu1.gigya.com');
        $this->RegisterPropertyString('GigyaAPIKey', self::GIGYA_KEY);
        $this->RegisterPropertyString('KamereonURL', 'https://api-wired-prod-1-euw1.wrd-aws.com');
        $this->RegisterPropertyString('KamereonAPIKey', 'YjkKtHmGfaceeuExUDKGxrLZGGvtVS0J');

        // optionale E3DC-Wallbox (vorhandene IPS-Variablen, 0 = nicht verwendet)
        foreach (self::WB_PROPS as $prop) {
            $this->RegisterPropertyInteger($prop, 0);
        }
        $this->RegisterAttributeString('WBWatched', '[]');

        $this->RegisterAttributeString('CredentialHash', '');
        $this->RegisterAttributeString('LoginToken', '');
        $this->RegisterAttributeString('PersonId', '');
        $this->RegisterAttributeString('AccountId', '');
        $this->RegisterAttributeString('IdToken', '');
        $this->RegisterAttributeInteger('IdTokenExpires', 0);
        $this->RegisterAttributeString('Vehicles', '[]');
        $this->RegisterAttributeString('Schedules', '[]');
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeString('Unsupported', '[]');

        $this->RegisterTimer('Update', 0, 'MRN_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('QuickRefresh', 0, 'MRN_QuickRefresh($_IPS[\'TARGET\']);');

        if (method_exists($this, 'SetVisualizationType')) {
            $this->SetVisualizationType(1); // HTML-SDK-Kachel
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->watchWallbox();

        $this->createProfiles();
        $this->registerVariables();

        // Zugangsdaten geändert → alle Tokens verwerfen
        $hash = md5($this->ReadPropertyString('Username') . '|' . $this->ReadPropertyString('Password') . '|'
            . $this->ReadPropertyString('Locale') . '|' . $this->ReadPropertyString('GigyaAPIKey'));
        if ($this->ReadAttributeString('CredentialHash') !== $hash) {
            $this->resetSession(true);
            $this->WriteAttributeString('CredentialHash', $hash);
        }
        // nach Änderungen (z. B. anderes Fahrzeug) alle Endpunkte erneut probieren
        $this->WriteAttributeString('Unsupported', '[]');

        if ($this->ReadPropertyString('Username') === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('Update', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $interval > 0 ? max(5, $interval) * 60 * 1000 : 0);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            // asynchron erste Abfrage, damit "Übernehmen" nicht blockiert
            $this->SetTimerInterval('QuickRefresh', 2000);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        } elseif ($Message == VM_UPDATE) {
            // Wallbox-Wert geändert → Kachel aktualisieren (nur bei echter Änderung)
            if (!empty($Data[1])) {
                $this->updateVisu();
            }
        }
    }

    // ------------------------------------------------------------------ E3DC-Wallbox

    private const WB_PROPS = ['WBPower', 'WBSolarPower', 'WBCtrl', 'WBMode', 'WBPhases', 'WBEnergy', 'WBSolarEnergy', 'WBOnline',
        'WBFcStart', 'WBFcEnd', 'WBFcKWh', 'WBFcKWhTomorrow', 'WBFcText'];

    private function watchWallbox(): void
    {
        foreach (json_decode($this->ReadAttributeString('WBWatched'), true) ?: [] as $id) {
            $this->UnregisterMessage($id, VM_UPDATE);
        }
        $watched = [];
        foreach (self::WB_PROPS as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
                $watched[] = $id;
            }
        }
        $this->WriteAttributeString('WBWatched', json_encode(array_values(array_unique($watched))));
    }

    private function wbVar(string $prop): int
    {
        $id = $this->ReadPropertyInteger($prop);
        return ($id > 0 && IPS_VariableExists($id)) ? $id : 0;
    }

    private function wbHasAction(int $id): bool
    {
        $v = IPS_GetVariable($id);
        return ($v['VariableCustomAction'] ?? 0) > 1 || (($v['VariableCustomAction'] ?? 0) === 0 && ($v['VariableAction'] ?? 0) > 1);
    }

    /** Texte des Bool-Profils: [false-Text, true-Text] */
    private function wbBoolTexts(int $id, array $fallback): array
    {
        $v = IPS_GetVariable($id);
        $profile = $v['VariableCustomProfile'] !== '' ? $v['VariableCustomProfile'] : $v['VariableProfile'];
        $texts = $fallback;
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            foreach (IPS_GetVariableProfile($profile)['Associations'] as $a) {
                if ($a['Name'] !== '') {
                    $texts[(int) (bool) $a['Value']] = $a['Name'];
                }
            }
        }
        return $texts;
    }

    private function getWallboxData(): ?array
    {
        $power = $this->wbVar('WBPower');
        $ctrl = $this->wbVar('WBCtrl');
        if ($power === 0 && $ctrl === 0) {
            return null;
        }
        $num = function (string $prop) {
            $id = $this->wbVar($prop);
            return $id ? (float) GetValue($id) : null;
        };
        $wb = [
            'power'       => $num('WBPower'),       // W
            'solarPower'  => $num('WBSolarPower'),  // W
            'energy'      => $num('WBEnergy'),      // kWh
            'solarEnergy' => $num('WBSolarEnergy'), // kWh
            'online'      => null,
            'ctrl'        => null
        ];
        if (($id = $this->wbVar('WBOnline')) > 0) {
            $wb['online'] = GetValueFormatted($id);
        }
        // optionale Solarprognose (z. B. Skript "W51 Wallbox Solarprognose"): Fenster als Unix-Zeit, Mengen in kWh, Empfehlung als Text
        $wb['forecast'] = null;
        if ($this->wbVar('WBFcKWh') > 0 || $this->wbVar('WBFcText') > 0) {
            $wb['forecast'] = [
                'start'    => ($id = $this->wbVar('WBFcStart')) ? (int) GetValue($id) : 0,
                'end'      => ($id = $this->wbVar('WBFcEnd')) ? (int) GetValue($id) : 0,
                'kwh'      => $num('WBFcKWh'),
                'tomorrow' => $num('WBFcKWhTomorrow'),
                'text'     => ($id = $this->wbVar('WBFcText')) ? (string) GetValue($id) : ''
            ];
        }
        if ($ctrl > 0) {
            // E3DC WallBox_x_CTRL (Register 40088 ff.), Bitfeld
            $c = (int) GetValue($ctrl);
            $wb['ctrl'] = [
                'available' => (bool) ($c & 1),
                'solar'     => (bool) ($c & 2),
                'blocked'   => (bool) ($c & 4),
                'charging'  => (bool) ($c & 8),
                'locked'    => (bool) ($c & 16),
                'plugged'   => (bool) ($c & 32),
                'onePhase'  => (bool) ($c & 4096)
            ];
        }
        foreach (['WBMode' => ['Mischbetrieb', 'Sonnenmodus'], 'WBPhases' => ['3-phasig', '1-phasig']] as $prop => $fallback) {
            $key = $prop === 'WBMode' ? 'mode' : 'phases';
            if (($id = $this->wbVar($prop)) > 0) {
                $wb[$key] = [
                    'value'      => (bool) GetValue($id),
                    'texts'      => $this->wbBoolTexts($id, $fallback),
                    'actionable' => $this->wbHasAction($id)
                ];
            } else {
                $wb[$key] = null;
            }
        }
        return $wb;
    }

    private function wbSwitch(string $prop, bool $value): bool
    {
        $id = $this->wbVar($prop);
        if ($id === 0 || !$this->wbHasAction($id)) {
            $this->lastError = 'Wallbox-Variable nicht konfiguriert oder ohne Aktion';
            return false;
        }
        $ok = RequestAction($id, $value);
        $this->updateVisu();
        return $ok !== false;
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $vehicles = json_decode($this->ReadAttributeString('Vehicles'), true) ?: [];
        if (count($vehicles) > 0) {
            // gefundene Fahrzeuge als Auswahlliste anbieten
            $options = [['caption' => 'Erstes Fahrzeug im Konto', 'value' => '']];
            foreach ($vehicles as $v) {
                $options[] = ['caption' => trim($v['model'] . ' ' . $v['plate']) . ' – ' . $v['vin'], 'value' => $v['vin']];
            }
            foreach ($form['elements'] as &$el) {
                if (($el['name'] ?? '') === 'VIN') {
                    $el = ['type' => 'Select', 'name' => 'VIN', 'caption' => 'Fahrzeug', 'options' => $options];
                }
            }
            unset($el);
        }
        return json_encode($form);
    }

    // ------------------------------------------------------------------ Visualisierung (Kachel)

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->getVisuData())) . ');</script>';
    }

    // ------------------------------------------------------------------ Bedienung (WebFront + Kachel)

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'HvacStatus':
                $ok = $Value ? $this->StartClimate($this->GetValue('TargetTemperature')) : $this->StopClimate();
                break;
            case 'TargetTemperature':
                $this->SetValue('TargetTemperature', max(16.0, min(27.0, round((float) $Value * 2) / 2)));
                $ok = true;
                break;
            case 'Charging':
                $ok = $Value ? $this->StartCharging() : $this->StopCharging();
                break;
            case 'ChargeMode':
                $ok = $this->SetChargeMode((int) $Value);
                break;
            case 'Refresh':
                $ok = $this->Update();
                break;
            case 'WBMode':
            case 'WBPhases':
                $ok = $this->wbSwitch($Ident, (bool) $Value);
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
        $this->updateVisu();
        if (!$ok) {
            echo 'My Renault: ' . ($this->lastError !== '' ? $this->lastError : 'Befehl fehlgeschlagen');
        }
    }

    // ------------------------------------------------------------------ Öffentliche Funktionen (MRN_*)

    public function QuickRefresh(): bool
    {
        $this->SetTimerInterval('QuickRefresh', 0);
        return $this->Update();
    }

    public function Update(): bool
    {
        if (!$this->ensureReady()) {
            $this->updateVisu();
            return false;
        }

        $any = false;

        if (($b = $this->tryRead('v2', 'battery-status')) !== null) {
            $any = true;
            $this->setNum('BatteryLevel', $b['batteryLevel'] ?? null, 'int');
            $this->setNum('BatteryAutonomy', $b['batteryAutonomy'] ?? null, 'int');
            $this->setNum('BatteryTemperature', $b['batteryTemperature'] ?? null, 'float');
            if (isset($b['plugStatus'])) {
                $this->SetValue('PlugStatus', array_key_exists((int) $b['plugStatus'], self::PLUG_STATES) ? (int) $b['plugStatus'] : -1);
            }
            if (isset($b['chargingStatus'])) {
                $key = number_format((float) $b['chargingStatus'], 1, '.', '');
                $code = self::CHARGE_STATES[$key][0] ?? -2;
                $this->SetValue('ChargingStatus', $code);
                $this->SetValue('Charging', $code === 10);
            }
            $power = (float) ($b['chargingInstantaneousPower'] ?? 0);
            if ($power > 100) {
                $power /= 1000; // manche Modelle liefern Watt statt kW
            }
            $this->SetValue('ChargingPower', round($power, 1));
            $this->SetValue('ChargingRemainingTime', (int) ($b['chargingRemainingTime'] ?? 0));
        }

        $c = $this->tryRead('v1', 'cockpit') ?? $this->tryRead('v2', 'cockpit');
        if ($c !== null) {
            $any = true;
            $this->setNum('Mileage', $c['totalMileage'] ?? null, 'float');
        }

        if (($h = $this->tryRead('v1', 'hvac-status')) !== null) {
            $any = true;
            $this->setNum('ExternalTemperature', $h['externalTemperature'] ?? null, 'float');
            if (isset($h['hvacStatus'])) {
                $this->SetValue('HvacStatus', strtolower((string) $h['hvacStatus']) === 'on');
            }
        }

        if (($m = $this->tryRead('v1', 'charge-mode')) !== null) {
            $any = true;
            $mode = strtolower((string) ($m['chargeMode'] ?? ''));
            $this->SetValue('ChargeMode', (strpos($mode, 'schedule') !== false) ? 1 : 0);
        }

        if (($s = $this->tryRead('v1', 'charging-settings')) !== null) {
            $any = true;
            $this->WriteAttributeString('Schedules', json_encode($s['schedules'] ?? []));
            $this->SetValue('ChargeSchedule', $this->scheduleText($s['schedules'] ?? []));
        }

        if ($this->ReadPropertyBoolean('ReadLocation') && ($l = $this->tryRead('v1', 'location')) !== null) {
            $any = true;
            $this->setNum('Latitude', $l['gpsLatitude'] ?? null, 'float');
            $this->setNum('Longitude', $l['gpsLongitude'] ?? null, 'float');
        }

        if ($any) {
            $this->SetValue('LastUpdate', time());
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(102);
        } else {
            $this->WriteAttributeString('LastError', $this->lastError);
            $this->SetStatus(203);
        }
        $this->updateVisu();
        return $any;
    }

    public function StartClimate(float $Temperature): bool
    {
        $Temperature = max(16.0, min(27.0, $Temperature));
        $ok = $this->sendCarAction('v1', 'hvac-start', 'HvacStart', ['action' => 'start', 'targetTemperature' => $Temperature]);
        if ($ok) {
            $this->SetValue('TargetTemperature', $Temperature);
            $this->SetValue('HvacStatus', true);
        }
        return $ok;
    }

    public function StopClimate(): bool
    {
        $ok = $this->sendCarAction('v1', 'hvac-start', 'HvacStart', ['action' => 'cancel']);
        if ($ok) {
            $this->SetValue('HvacStatus', false);
        }
        return $ok;
    }

    public function StartCharging(): bool
    {
        if ($this->ReadPropertyBoolean('UseKCM')) {
            $ok = $this->sendKcmAction('charge/pause-resume', 'ChargePauseResume', ['action' => 'resume']);
        } else {
            $ok = $this->sendCarAction('v1', 'charging-start', 'ChargingStart', ['action' => 'start']);
        }
        if ($ok) {
            $this->SetValue('Charging', true);
        }
        return $ok;
    }

    public function StopCharging(): bool
    {
        if ($this->ReadPropertyBoolean('UseKCM')) {
            $ok = $this->sendKcmAction('charge/pause-resume', 'ChargePauseResume', ['action' => 'pause']);
        } else {
            $ok = $this->sendCarAction('v1', 'charging-start', 'ChargingStart', ['action' => 'stop']);
        }
        if ($ok) {
            $this->SetValue('Charging', false);
        }
        return $ok;
    }

    /** @param int $Mode 0 = sofort laden, 1 = nach Zeitplan */
    public function SetChargeMode(int $Mode): bool
    {
        $ok = $this->sendCarAction('v1', 'charge-mode', 'ChargeMode', ['action' => $Mode === 1 ? 'schedule_mode' : 'always_charging']);
        if ($ok) {
            $this->SetValue('ChargeMode', $Mode === 1 ? 1 : 0);
        }
        return $ok;
    }

    /**
     * Ladezeitplan setzen. $Schedules = JSON-Array im Kamereon-Format, z. B.
     * [{"id":1,"activated":true,"monday":{"startTime":"T22:00Z","duration":480}, ...}]
     * Zeiten sind UTC. Den aktuellen Plan liefert MRN_GetChargeSchedules().
     */
    public function SetChargeSchedule(string $Schedules): bool
    {
        $list = json_decode($Schedules, true);
        if (!is_array($list)) {
            $this->lastError = 'Ungültiges JSON für Ladezeitplan';
            return false;
        }
        $ok = $this->sendCarAction('v2', 'charge-schedule', 'ChargeSchedule', ['schedules' => $list]);
        if ($ok) {
            $this->WriteAttributeString('Schedules', json_encode($list));
            $this->SetValue('ChargeSchedule', $this->scheduleText($list));
        }
        return $ok;
    }

    public function GetChargeSchedules(): string
    {
        return $this->ReadAttributeString('Schedules');
    }

    public function DiscoverVehicles(): string
    {
        $this->resetSession(false);
        try {
            $this->getAccountId();
            $vehicles = $this->loadVehicles();
        } catch (Exception $e) {
            $this->SetStatus(201);
            return 'Fehler: ' . $e->getMessage();
        }
        if (count($vehicles) === 0) {
            $this->SetStatus(202);
            return 'Anmeldung erfolgreich, aber kein Fahrzeug im Konto gefunden.';
        }
        $this->SetStatus(102);
        $out = "Anmeldung erfolgreich. Gefundene Fahrzeuge:\n";
        foreach ($vehicles as $v) {
            $out .= '• ' . trim($v['model'] . ' ' . $v['plate']) . ' – VIN ' . $v['vin'] . "\n";
        }
        return $out . "\nDas Formular neu öffnen, um das Fahrzeug auszuwählen.";
    }

    // ------------------------------------------------------------------ Authentifizierung

    private function resetSession(bool $full): void
    {
        $this->WriteAttributeString('IdToken', '');
        $this->WriteAttributeInteger('IdTokenExpires', 0);
        $this->WriteAttributeString('LoginToken', '');
        if ($full) {
            $this->WriteAttributeString('PersonId', '');
            $this->WriteAttributeString('AccountId', '');
            $this->WriteAttributeString('Vehicles', '[]');
        }
    }

    private function gigyaKey(): string
    {
        $key = trim($this->ReadPropertyString('GigyaAPIKey'));
        // veraltete (länderspezifische) Schlüssel automatisch auf den aktuellen EU-Schlüssel umstellen
        if ($key === '' || in_array($key, self::OBSOLETE_GIGYA_KEYS, true)) {
            $key = self::GIGYA_KEY;
        }
        return $key;
    }

    private function gigya(string $method, array $params): array
    {
        $params['apiKey'] = $this->gigyaKey();
        $url = rtrim($this->ReadPropertyString('GigyaURL'), '/') . '/accounts.' . $method;
        $res = $this->http('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($params));
        $data = json_decode($res['body'], true);
        if (!is_array($data) || (int) ($data['errorCode'] ?? 0) !== 0) {
            $msg = $data['errorDetails'] ?? $data['errorMessage'] ?? ('HTTP ' . $res['code']);
            if ((int) ($data['errorCode'] ?? 0) === 403042) {
                $msg .= ' – E-Mail/Passwort wie in der My-Renault-App prüfen; ggf. Gigya-API-Key unter Experteneinstellungen aktualisieren';
            }
            throw new Exception('Gigya ' . $method . ': ' . $msg);
        }
        return $data;
    }

    private function login(): string
    {
        $data = $this->gigya('login', [
            'loginID'  => trim($this->ReadPropertyString('Username')),
            'password' => $this->ReadPropertyString('Password')
        ]);
        $token = $data['sessionInfo']['cookieValue'] ?? '';
        if ($token === '') {
            throw new Exception('Gigya login: kein Login-Token erhalten');
        }
        $this->WriteAttributeString('LoginToken', $token);
        return $token;
    }

    private function getIdToken(): string
    {
        $token = $this->ReadAttributeString('IdToken');
        if ($token !== '' && $this->ReadAttributeInteger('IdTokenExpires') > time() + 60) {
            return $token;
        }

        $loginToken = $this->ReadAttributeString('LoginToken');
        if ($loginToken === '') {
            $loginToken = $this->login();
        }

        $params = ['fields' => 'data.personId,data.gigyaDataCenter', 'expiration' => 900];
        try {
            $jwt = $this->gigya('getJWT', $params + ['login_token' => $loginToken]);
        } catch (Exception $e) {
            // Login-Token abgelaufen → neu anmelden
            $loginToken = $this->login();
            $jwt = $this->gigya('getJWT', $params + ['login_token' => $loginToken]);
        }

        if ($this->ReadAttributeString('PersonId') === '') {
            $info = $this->gigya('getAccountInfo', ['login_token' => $loginToken]);
            $pid = $info['data']['personId'] ?? '';
            if ($pid === '') {
                throw new Exception('Gigya getAccountInfo: keine personId');
            }
            $this->WriteAttributeString('PersonId', $pid);
        }

        $this->WriteAttributeString('IdToken', $jwt['id_token']);
        $this->WriteAttributeInteger('IdTokenExpires', time() + 900);
        return $jwt['id_token'];
    }

    private function getAccountId(): string
    {
        $acc = $this->ReadAttributeString('AccountId');
        if ($acc !== '') {
            return $acc;
        }
        $this->getIdToken(); // stellt PersonId sicher
        $person = $this->kamereon('GET', '/commerce/v1/persons/' . $this->ReadAttributeString('PersonId'));
        $accounts = $person['accounts'] ?? [];
        foreach ($accounts as $a) {
            if (($a['accountType'] ?? '') === 'MYRENAULT' && ($a['accountStatus'] ?? 'ACTIVE') === 'ACTIVE') {
                $acc = $a['accountId'];
                break;
            }
        }
        if ($acc === '' && isset($accounts[0]['accountId'])) {
            $acc = $accounts[0]['accountId'];
        }
        if ($acc === '') {
            throw new Exception('Kein My-Renault-Konto gefunden');
        }
        $this->WriteAttributeString('AccountId', $acc);
        return $acc;
    }

    private function loadVehicles(): array
    {
        $res = $this->kamereon('GET', '/commerce/v1/accounts/' . $this->getAccountId() . '/vehicles');
        $list = [];
        foreach ($res['vehicleLinks'] ?? [] as $link) {
            $d = $link['vehicleDetails'] ?? [];
            $list[] = [
                'vin'   => $link['vin'] ?? ($d['vin'] ?? ''),
                'model' => $d['model']['label'] ?? 'Renault',
                'plate' => $d['registrationNumber'] ?? ''
            ];
        }
        $this->WriteAttributeString('Vehicles', json_encode($list));
        return $list;
    }

    private function getVin(): string
    {
        $vin = trim($this->ReadPropertyString('VIN'));
        if ($vin !== '') {
            return strtoupper($vin);
        }
        $vehicles = json_decode($this->ReadAttributeString('Vehicles'), true) ?: [];
        if (count($vehicles) === 0) {
            $vehicles = $this->loadVehicles();
        }
        if (count($vehicles) === 0 || $vehicles[0]['vin'] === '') {
            throw new Exception('Kein Fahrzeug im Konto');
        }
        return $vehicles[0]['vin'];
    }

    private function ensureReady(): bool
    {
        if ($this->ReadPropertyString('Username') === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetStatus(104);
            $this->lastError = 'Keine Zugangsdaten';
            return false;
        }
        try {
            $this->getAccountId();
        } catch (Exception $e) {
            return $this->fail(201, $e->getMessage());
        }
        try {
            $this->getVin();
        } catch (Exception $e) {
            return $this->fail(202, $e->getMessage());
        }
        return true;
    }

    private function fail(int $status, string $msg): bool
    {
        $this->lastError = $msg;
        $this->WriteAttributeString('LastError', $msg);
        $this->SetStatus($status);
        $this->LogMessage($msg, KL_ERROR);
        return false;
    }

    // ------------------------------------------------------------------ Kamereon

    private function country(): string
    {
        $parts = explode('_', $this->ReadPropertyString('Locale'));
        return strtoupper($parts[1] ?? 'DE');
    }

    private function carPath(string $version, string $endpoint): string
    {
        return '/commerce/v1/accounts/' . $this->getAccountId()
            . '/kamereon/kca/car-adapter/' . $version . '/cars/' . $this->getVin() . '/' . $endpoint;
    }

    private function kamereon(string $method, string $path, ?array $body = null, bool $retry = true): array
    {
        $url = rtrim($this->ReadPropertyString('KamereonURL'), '/') . $path
            . (strpos($path, '?') === false ? '?' : '&') . 'country=' . $this->country();
        $headers = [
            'apikey: ' . $this->ReadPropertyString('KamereonAPIKey'),
            'x-gigya-id_token: ' . $this->getIdToken(),
            'Content-Type: application/vnd.api+json',
            'Accept: application/json'
        ];
        $res = $this->http($method, $url, $headers, $body === null ? null : json_encode($body));

        $data = json_decode($res['body'], true);
        [$code, $msg] = $res['code'] >= 400 ? $this->parseKamereonError($data, $res['body']) : ['', ''];

        // 401 bzw. 403 ohne Funktions-Code = Token abgelaufen → einmal mit neuem Token wiederholen.
        // "err.func.wired.forbidden" heißt dagegen: Funktion für dieses Fahrzeug nicht freigegeben.
        if (($res['code'] === 401 || ($res['code'] === 403 && $code !== 'err.func.wired.forbidden')) && $retry) {
            $this->WriteAttributeString('IdToken', '');
            $this->WriteAttributeInteger('IdTokenExpires', 0);
            return $this->kamereon($method, $path, $body, false);
        }
        if ($res['code'] >= 400) {
            if ($code === '409001' || stripos($msg, 'already in progress') !== false) {
                throw new Exception('Das Fahrzeug bearbeitet noch einen vorherigen Befehl. Bitte 1–2 Minuten warten.', 409);
            }
            // Code 403 nur für "Funktion nicht freigegeben", sonstige 403 (Auth) als 401 melden
            $exCode = ($res['code'] === 403 && $code !== 'err.func.wired.forbidden') ? 401 : $res['code'];
            throw new Exception('Kamereon HTTP ' . $res['code'] . ': ' . $msg, $exCode);
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Kamereon verschachtelt Fehler teils mehrfach (errors[].errorMessage enthält wieder JSON).
     * Liefert [innerster Fehlercode, innerste Meldung].
     */
    private function parseKamereonError($data, string $raw): array
    {
        $code = '';
        $msg = '';
        for ($depth = 0; $depth < 4 && is_array($data); $depth++) {
            $err = $data['errors'][0] ?? $data['messages'][0] ?? $data[0] ?? $data;
            if (!is_array($err)) {
                break;
            }
            $code = (string) ($err['errorCode'] ?? $err['code'] ?? $code);
            $m = $err['errorMessage'] ?? $err['message'] ?? '';
            if (!is_string($m) || $m === '') {
                break;
            }
            $msg = $m;
            $data = json_decode($m, true); // weitere Ebene?
        }
        if ($msg === '') {
            $msg = trim(substr($raw, 0, 200));
        }
        return [$code, $msg];
    }

    private function tryRead(string $version, string $endpoint): ?array
    {
        $key = $version . '/' . $endpoint;
        $unsupported = json_decode($this->ReadAttributeString('Unsupported'), true) ?: [];
        if (in_array($key, $unsupported, true)) {
            return null; // vom Fahrzeug nicht unterstützt → nicht erneut abfragen
        }
        try {
            $res = $this->kamereon('GET', $this->carPath($version, $endpoint));
            return $res['data']['attributes'] ?? null;
        } catch (Exception $e) {
            if (in_array($e->getCode(), [403, 404, 501], true)) {
                $unsupported[] = $key;
                $this->WriteAttributeString('Unsupported', json_encode(array_values(array_unique($unsupported))));
                $this->SendDebug('Lesen ' . $endpoint, 'Vom Fahrzeug nicht unterstützt – wird bis zum nächsten Übernehmen übersprungen (' . $e->getMessage() . ')', 0);
                return null;
            }
            $this->lastError = $e->getMessage();
            $this->SendDebug('Lesen ' . $endpoint, $e->getMessage(), 0);
            return null;
        }
    }

    private function sendCarAction(string $version, string $action, string $type, array $attributes): bool
    {
        if (!$this->ensureReady()) {
            return false;
        }
        return $this->postAction($this->carPath($version, 'actions/' . $action), $type, $attributes);
    }

    private function sendKcmAction(string $endpoint, string $type, array $attributes): bool
    {
        if (!$this->ensureReady()) {
            return false;
        }
        $path = '/commerce/v1/accounts/' . $this->getAccountId() . '/kamereon/kcm/v1/vehicles/' . $this->getVin() . '/' . $endpoint;
        return $this->postAction($path, $type, $attributes);
    }

    private function postAction(string $path, string $type, array $attributes): bool
    {
        try {
            $this->kamereon('POST', $path, ['data' => ['type' => $type, 'attributes' => $attributes]]);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->LogMessage('Befehl ' . $type . ' fehlgeschlagen: ' . $e->getMessage(), $e->getCode() === 409 ? KL_WARNING : KL_ERROR);
            if ($e->getCode() === 409) {
                // vorheriger Befehl läuft noch → Stand bald neu abfragen, damit die Anzeige stimmt
                $this->SetTimerInterval('QuickRefresh', 90 * 1000);
            }
            return false;
        }
        $this->LogMessage('Befehl ' . $type . ' gesendet: ' . json_encode($attributes), KL_MESSAGE);
        // Fahrzeug braucht etwas, bis der neue Zustand in der Cloud steht
        $this->SetTimerInterval('QuickRefresh', 90 * 1000);
        $this->updateVisu();
        return true;
    }

    private function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // keine Zugangsdaten/Tokens ins Debug schreiben
        // Tokens und Sitzungsdaten nie in die Debug-Ausgabe schreiben
        $debug = preg_replace(
            '/("(?:id_token|cookieValue|cookieName|login_token|sessionToken|sessionSecret|UID|UIDSignature)"\s*:\s*")[^"]*"/i',
            '$1***"',
            (string) $resp
        );
        $this->SendDebug($method . ' ' . preg_replace('/\?.*$/', '', $url), 'HTTP ' . $code . ' ' . substr($debug, 0, 1500), 0);
        if ($resp === false) {
            throw new Exception('Verbindungsfehler: ' . $err);
        }
        return ['code' => $code, 'body' => (string) $resp];
    }

    // ------------------------------------------------------------------ Variablen & Profile

    private function registerVariables(): void
    {
        $this->RegisterVariableInteger('BatteryLevel', 'Akkustand', 'MRN.Percent', 10);
        $this->RegisterVariableInteger('BatteryAutonomy', 'Reichweite', 'MRN.Kilometer', 20);
        $this->RegisterVariableInteger('PlugStatus', 'Stecker', 'MRN.Plug', 30);
        $this->RegisterVariableInteger('ChargingStatus', 'Ladestatus', 'MRN.ChargeState', 40);
        $this->RegisterVariableBoolean('Charging', 'Laden', '~Switch', 50);
        $this->RegisterVariableInteger('ChargeMode', 'Lademodus', 'MRN.ChargeMode', 60);
        $this->RegisterVariableFloat('ChargingPower', 'Ladeleistung', 'MRN.Power', 70);
        $this->RegisterVariableInteger('ChargingRemainingTime', 'Restladezeit', 'MRN.Minutes', 80);
        $this->RegisterVariableString('ChargeSchedule', 'Ladezeitplan', '', 90);
        $this->RegisterVariableBoolean('HvacStatus', 'Klimatisierung', '~Switch', 100);
        $this->RegisterVariableFloat('TargetTemperature', 'Zieltemperatur', 'MRN.TargetTemp', 110);
        $this->RegisterVariableFloat('ExternalTemperature', 'Außentemperatur', '~Temperature', 120);
        $this->RegisterVariableFloat('BatteryTemperature', 'Akkutemperatur', '~Temperature', 130);
        $this->RegisterVariableFloat('Mileage', 'Kilometerstand', 'MRN.Mileage', 140);
        $this->RegisterVariableFloat('Latitude', 'Breitengrad', '', 150);
        $this->RegisterVariableFloat('Longitude', 'Längengrad', '', 160);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 170);

        foreach (['Charging', 'ChargeMode', 'HvacStatus', 'TargetTemperature'] as $ident) {
            $this->EnableAction($ident);
        }
        if ($this->GetValue('TargetTemperature') == 0) {
            $this->SetValue('TargetTemperature', 21.0);
        }
    }

    private function createProfiles(): void
    {
        $this->profile('MRN.Percent', 1, '', ' %', 0, 100, 1, 0);
        $this->profile('MRN.Kilometer', 1, '', ' km', 0, 1000, 1, 0);
        $this->profile('MRN.Mileage', 2, '', ' km', 0, 0, 0, 0);
        $this->profile('MRN.Power', 2, '', ' kW', 0, 150, 0.1, 1);
        $this->profile('MRN.Minutes', 1, '', ' min', 0, 1440, 1, 0);
        $this->profile('MRN.TargetTemp', 2, 'Temperature', ' °C', 16, 27, 0.5, 1);

        $plug = [];
        foreach (self::PLUG_STATES as $k => $t) {
            $plug[] = [$k, $t, '', $k === 1 ? 0x2E9E5B : ($k === 0 ? -1 : 0xD23C3C)];
        }
        $this->profile('MRN.Plug', 1, 'Plug', '', 0, 0, 0, 0, $plug);

        $charge = [];
        foreach (self::CHARGE_STATES as [$code, $text]) {
            $charge[] = [$code, $text, '', $code === 10 ? 0x2E9E5B : ($code < 0 ? 0xD23C3C : -1)];
        }
        $this->profile('MRN.ChargeState', 1, 'Electricity', '', 0, 0, 0, 0, $charge);

        $this->profile('MRN.ChargeMode', 1, 'Clock', '', 0, 1, 0, 0, [
            [0, 'Sofort laden', '', -1],
            [1, 'Nach Zeitplan', '', -1]
        ]);
    }

    private function profile(string $name, int $type, string $icon, string $suffix, float $min, float $max, float $step, int $digits, array $assoc = []): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        if ($type === 2) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
        foreach ($assoc as [$value, $text, $aIcon, $color]) {
            IPS_SetVariableProfileAssociation($name, $value, $text, $aIcon, $color);
        }
    }

    private function setNum(string $ident, $value, string $type): void
    {
        if ($value === null || !is_numeric($value)) {
            return;
        }
        $this->SetValue($ident, $type === 'int' ? (int) round((float) $value) : (float) $value);
    }

    private function scheduleText(array $schedules): string
    {
        $days = ['monday' => 'Mo', 'tuesday' => 'Di', 'wednesday' => 'Mi', 'thursday' => 'Do', 'friday' => 'Fr', 'saturday' => 'Sa', 'sunday' => 'So'];
        $lines = [];
        foreach ($schedules as $s) {
            if (empty($s['activated'])) {
                continue;
            }
            $parts = [];
            foreach ($days as $key => $short) {
                if (!isset($s[$key]['startTime'])) {
                    continue;
                }
                $start = $this->utcToLocal($s[$key]['startTime']);
                $dur = (int) ($s[$key]['duration'] ?? 0);
                $parts[] = $short . ' ' . $start . ' (' . intdiv($dur, 60) . ':' . str_pad((string) ($dur % 60), 2, '0', STR_PAD_LEFT) . ' h)';
            }
            if ($parts) {
                $lines[] = 'Plan ' . ($s['id'] ?? '?') . ': ' . implode(', ', $parts);
            }
        }
        return $lines ? implode("\n", $lines) : 'Kein aktiver Zeitplan';
    }

    private function utcToLocal(string $t): string
    {
        if (!preg_match('/T?(\d{2}):(\d{2})/', $t, $m)) {
            return $t;
        }
        $dt = new DateTime('today ' . $m[1] . ':' . $m[2], new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('H:i');
    }

    // ------------------------------------------------------------------ Kachel-Daten

    private function getVisuData(): array
    {
        $get = function (string $ident) {
            $id = @$this->GetIDForIdent($ident);
            return $id ? GetValue($id) : null;
        };
        $vin = '';
        $model = 'Renault';
        try {
            $vin = trim($this->ReadPropertyString('VIN'));
            $vehicles = json_decode($this->ReadAttributeString('Vehicles'), true) ?: [];
            foreach ($vehicles as $v) {
                if ($vin === '' || strcasecmp($v['vin'], $vin) === 0) {
                    $vin = $v['vin'];
                    $model = $v['model'];
                    break;
                }
            }
        } catch (Exception $e) {
        }
        $chargeCode = (int) $get('ChargingStatus');
        $chargeText = 'Unbekannt';
        foreach (self::CHARGE_STATES as [$code, $text]) {
            if ($code === $chargeCode) {
                $chargeText = $text;
            }
        }
        return [
            'model'       => $model,
            'vin'         => $vin,
            'battery'     => $get('BatteryLevel'),
            'autonomy'    => $get('BatteryAutonomy'),
            'plug'        => $get('PlugStatus'),
            'plugText'    => self::PLUG_STATES[(int) $get('PlugStatus')] ?? 'Unbekannt',
            'chargeState' => $chargeCode,
            'chargeText'  => $chargeText,
            'charging'    => (bool) $get('Charging'),
            'chargeMode'  => (int) $get('ChargeMode'),
            'power'       => $get('ChargingPower'),
            'remaining'   => $get('ChargingRemainingTime'),
            'batteryTemp' => $get('BatteryTemperature'),
            'mileage'     => $get('Mileage'),
            'outsideTemp' => $get('ExternalTemperature'),
            'hvac'        => (bool) $get('HvacStatus'),
            'targetTemp'  => $get('TargetTemperature'),
            'schedules'   => json_decode($this->ReadAttributeString('Schedules'), true) ?: [],
            'lat'         => $get('Latitude'),
            'lon'         => $get('Longitude'),
            'lastUpdate'  => $get('LastUpdate'),
            'status'      => $this->GetStatus(),
            'error'       => $this->ReadAttributeString('LastError'),
            'unsupported' => json_decode($this->ReadAttributeString('Unsupported'), true) ?: [],
            'wallbox'     => $this->getWallboxData()
        ];
    }

    private function updateVisu(): void
    {
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $this->UpdateVisualizationValue(json_encode($this->getVisuData()));
        }
    }
}
