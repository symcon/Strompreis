<?php

declare(strict_types=1);

class PowerPrice extends IPSModule
{
    public function Create()
    {
        $this->RegisterPropertyString('Provider', 'aWATTar');
        $this->RegisterPropertyString('EPEXSpotMarket', 'DE-LU');
        $this->RegisterPropertyString('aWATTarMarket', 'de');
        $this->RegisterPropertyString('TibberPostalCode', '23554');
        $this->RegisterPropertyInteger('PriceResolution', 60);
        $this->RegisterPropertyFloat('PriceBase', 19.5);
        $this->RegisterPropertyFloat('PriceSurcharge', 3);
        $this->RegisterPropertyFloat('PriceTax', 19);

        if (!IPS_VariableProfileExists('Cent')) {
            IPS_CreateVariableProfile('Cent', 2);
            IPS_SetVariableProfileDigits('Cent', 2);
            IPS_SetVariableProfileText('Cent', '', ' ct');
        }

        $this->RegisterVariableString('MarketData', $this->Translate('Market Data'), '~TextBox', 0);
        $this->RegisterVariableFloat('CurrentPrice', $this->Translate('Current Price'), 'Cent', 1);

        $this->SetVisualizationType(1);

        // Market data update timer - every 2 hours, load initially after 30 seconds
        $this->RegisterTimer('UpdateMarketData', 30000, 'SPX_UpdateMarketData($_IPS["TARGET"]);');
        
        // Current price update timer - based on price resolution, load initially after 35 seconds
        $this->RegisterTimer('UpdateCurrentPrice', 35000, 'SPX_UpdateCurrentPrice($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->Update();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form['elements'][1]['visible'] = $this->ReadPropertyString('Provider') === 'aWATTar';
        $form['elements'][2]['visible'] = $this->ReadPropertyString('Provider') === 'Tibber';
        $form['elements'][3]['visible'] = $this->ReadPropertyString('Provider') === 'EPEXSpot';

        // 15 Minute Resolution is only available for Tibber/EPEX Sport
        $form['elements'][4]['visible'] = in_array($this->ReadPropertyString('Provider'), ['Tibber', 'EPEXSpot']);

        // Tibber includes full price information
        $form['elements'][5]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        $form['elements'][6]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        $form['elements'][7]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        $form['elements'][8]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        $form['elements'][9]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        return json_encode($form);
    }

    public function Update()
    {
        // Keep for backward compatibility, but use separate methods
        $this->UpdateMarketData();
        $this->UpdateCurrentPrice();
    }

    public function UpdateMarketData()
    {
        $marketData = '[]';
        switch ($this->ReadPropertyString('Provider')) {
            case 'aWATTar':
                $marketData = $this->FetchFromAwattar($this->ReadPropertyString('aWATTarMarket'));
                break;
            case 'Tibber':
                $marketData = $this->FetchFromTibber($this->ReadPropertyString('TibberPostalCode'));
                break;
            case 'EPEXSpot':
                $marketData = $this->FetchFromEPEXSpot($this->ReadPropertyString('EPEXSpotMarket'));
                break;
        }
        $this->UpdateVisualizationValue($marketData);
        $this->SetValue('MarketData', $marketData);

        // Set next market data update to synchronize to full 2-hour intervals (00:00, 02:00, 04:00, etc.)
        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $currentSecond = (int)date('s');
        
        // Calculate hours until next 2-hour interval
        $hoursToNext = 2 - ($currentHour % 2);
        if ($hoursToNext == 2 && $currentMinute == 0 && $currentSecond == 0) {
            $hoursToNext = 0; // Already at the interval
        }
        
        $waitTime = ($hoursToNext * 3600 - $currentMinute * 60 - $currentSecond) * 1000;
        $waitTime += 30 * 1000; // Synchronize 30 seconds after the full hour to ensure data availability
        if ($waitTime <= 0) {
            $waitTime = 2 * 3600 * 1000; // If calculation fails, use regular 2-hour interval
        }
        
        $this->SetTimerInterval('UpdateMarketData', $waitTime);
    }

    public function UpdateCurrentPrice()
    {
        $marketData = $this->GetValue('MarketData');
        $currentTime = time();
        $found = false;
        
        foreach (json_decode($marketData) as $row) {
            if ($currentTime >= $row->start && $currentTime <= $row->end) {
                $this->SetValue('CurrentPrice', $row->price);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $this->SetValue('CurrentPrice', 999.99);
        }

        // Set next current price update based on price resolution
        $priceResolution = $this->ReadPropertyInteger('PriceResolution');
        $updateInterval = $priceResolution * 60 * 1000; // Convert minutes to milliseconds
        
        // Synchronize to the next resolution interval (e.g., xx:00:00 for 60min, xx:00:00 or xx:15:00 etc. for 15min)
        $currentMinutes = (int)date('i');
        $currentSeconds = (int)date('s');
        
        // Calculate minutes until next resolution interval
        $minutesToNext = $priceResolution - ($currentMinutes % $priceResolution);
        if ($minutesToNext == $priceResolution && $currentSeconds == 0) {
            $minutesToNext = 0; // Already at the interval
        }
        
        $waitTime = ($minutesToNext * 60 - $currentSeconds) * 1000;
        if ($waitTime <= 0) {
            $waitTime = $updateInterval; // If calculation fails, use regular interval
        }
        
        $this->SetTimerInterval('UpdateCurrentPrice', $waitTime);
    }

    public function GetVisualizationTile()
    {
        // Add static HTML content from file to make editing easier
        $module = file_get_contents(__DIR__ . '/module.html');

        // Inject current values
        $module = str_replace('%market_data%', $this->GetValue('MarketData'), $module);

        // Inject resolution configuration
        $module = str_replace('%price_resolution%', strval($this->ReadPropertyInteger('PriceResolution')), $module);

        // Return everything to render our fancy tile!
        return $module;
    }

    public function UIChangeProvider(string $Provider)
    {
        $this->UpdateFormField('aWATTarMarket', 'visible', $Provider === 'aWATTar');
        $this->UpdateFormField('TibberPostalCode', 'visible', $Provider === 'Tibber');
        $this->UpdateFormField('EPEXSpotMarket', 'visible', $Provider === 'EPEXSpot');

        $this->UpdateFormField('PriceResolution', 'visible', $Provider != 'aWATTar');
        if ($Provider == 'aWATTar') {
            $this->UpdateFormField('PriceResolution', 'value', 60);
        }
        $this->UpdateFormField('PriceHint', 'visible', $Provider != 'Tibber');
        $this->UpdateFormField('PriceBase', 'visible', $Provider != 'Tibber');
        $this->UpdateFormField('PricePremiumHint', 'visible', $Provider != 'Tibber');
        $this->UpdateFormField('PriceSurcharge', 'visible', $Provider != 'Tibber');
        $this->UpdateFormField('PriceTax', 'visible', $Provider != 'Tibber');
    }

    private function NormalizeAndReduce($data)
    {
        $this->SendDebug('NormalizeAndReduce - Input Data', json_encode($data), 0);
        $result = [];

        $base = $this->ReadPropertyFloat('PriceBase');
        $surcharge = (100 + $this->ReadPropertyFloat('PriceSurcharge')) / 100;

        /*
         * We want to normalize data to this format:
         *
         * {
         *  "start" : <Unix-Timestamp>
         *  "end"   : <Unix-Timestamp>
         *  "price" : <Final price in cent per kwH>
         * }
         *
         */
        $multiplier = 60 / $this->ReadPropertyInteger('PriceResolution');
        if (count($data) > (24 * $multiplier)) {
            $now = time();
            $this->SendDebug('Filter Data - Now', $now, 0);
            while (($now > ($data[0]['end_timestamp'] / 1000)) && (count(array_filter($data, function ($element) use ($data)
            {
                return ($element['end_timestamp'] / 1000) === strtotime('+1 day', $data[0]['end_timestamp'] / 1000);
            })) > 0)) {
                array_shift($data);
            }

            while (count($data) > (24 * $multiplier)) {
                array_pop($data);
            }
            $this->SendDebug('Filter Data - Updated Data', json_encode($data), 0);
        }
        foreach ($data as $row) {
            $value = [
                'start' => $row['start_timestamp'] / 1000,
                'end'   => $row['end_timestamp'] / 1000,
            ];
            switch ($row['unit']) {
                case 'Eur/MWh':
                    $value['price'] = $base + ((($row['marketprice'] * (1 + ($this->ReadPropertyFloat('PriceTax') / 100))) / 10) * $surcharge);
                    break;
                default:
                    $value['price'] = 0;
                    break;
            }
            $result[] = $value;
        }

        return json_encode($result);
    }

    private function FetchFromEPEXSpotEx($market, $trading_date, $delivery_date)
    {
        $params = [
            'market_area'   => $market,
            'trading_date'  => $trading_date,
            'delivery_date' => $delivery_date,
            'modality'      => 'Auction',
            'sub_modality'  => 'DayAhead',
            'product'       => strval($this->ReadPropertyInteger('PriceResolution')),
            'data_mode'     => 'table',
        ];

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded' . "\r\n" .
                             'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
        ];

        $this->SendDebug('FetchFromEPEX - Parameters', json_encode($params), 0);
        $response = file_get_contents('https://www.epexspot.com/en/market-results?' . http_build_query($params), false, stream_context_create($opts));
        $this->SendDebug('FetchFromEPEX - Response', $response, 0);

        // Als HTML DOM laden
        $doc = new DOMDocument();
        @$doc->loadHTML($response); // Das @-Zeichen unterdrückt Warnungen wegen fehlerhaftem HTML
        $xpath = new DOMXPath($doc);

        $table = [];

        // Extrahieren der Stunden
        $hours = $xpath->query('//div[contains(@class, "js-table-times")]//li/a');
        foreach ($hours as $i => $hour) {
            $table[$i] = [$hour->nodeValue];
        }

        // Extrahieren der Preis- und Volumendaten
        $rows = $xpath->query('//div[contains(@class, "js-table-values")]//tr[contains(@class, "child")]');

        $i = 0;
        foreach ($rows as $row) {
            $cells = $row->childNodes;
            $rowData = [];
            $j = 1;
            foreach ($cells as $cell) {
                if ($cell instanceof DOMElement) {
                    $table[$i][$j] = $cell->nodeValue;
                    $j++;
                }
            }
            $i++;
        }

        // Daten zu unserem Zielformat konvertieren
        $result = [];
        foreach ($table as $row) {

            $date = DateTime::createFromFormat('Y-m-d', $delivery_date);
            list($startString, $endString) = explode(' - ', $row[0]);
            $startSplit = explode(':', $startString);
            $startTime = clone $date;
            $startTime->setTime((int) $startSplit[0], count($startSplit) > 1 ? (int) $startSplit[1] : 0); // Stunden und Minuten setzen
            $endSplit = explode(':', $endString);
            $endTime = clone $date;
            $endTime->setTime((int) $endSplit[0], count($endSplit) > 1 ? (int) $endSplit[1] : 0); // Stunden und Minuten setzen

            $result[] = [
                'start_timestamp' => $startTime->getTimestamp() * 1000,
                'end_timestamp'   => $endTime->getTimestamp() * 1000,
                'marketprice'     => floatval($row[4]),
                'unit'            => 'Eur/MWh'
            ];
        }
        return $result;
    }

    private function FetchFromEPEXSpot($market)
    {
        $data = [];
        $data = array_merge($data, $this->FetchFromEpexSpotEx($market, date('Y-m-d', strtotime('-1 day')), date('Y-m-d')));
        if (date('H') >= 14) {
            $data = array_merge($data, $this->FetchFromEpexSpotEx($market, date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))));
        }
        return $this->NormalizeAndReduce($data);
    }

    private function FetchFromAwattar($market)
    {
        $start = mktime(0, 0, 0, intval(date('m')), intval(date('d')), intval(date('Y')));
        $end = strtotime('+2 days', $start);
        $this->SendDebug('FetchFromAwattar - Request', "Fetching data from $market between " . date('Y-m-d H:i:s', $start) . "($start) and " . date('Y-m-d H:i:s', $end) . "($end)", 0);
        $data = file_get_contents(sprintf('https://api.awattar.%s/v1/marketdata?start=%s&end=%s', $market, $start * 1000, $end * 1000));
        $this->SendDebug('FetchFromAwattar - Result', $data, 0);
        return $this->NormalizeAndReduce(json_decode($data, true)['data']);
    }

    private function FetchFromTibber($postalCode)
    {
        $this->SendDebug('FetchFromTibber - Postal Code', $postalCode, 0);
        $data = file_get_contents(sprintf('https://tibber.com/de/api/lookup/price-overview?postalCode=%s', $postalCode));
        $this->SendDebug('FetchFromTibber - Result', $data, 0);
        switch ($this->ReadPropertyInteger('PriceResolution')) {
            case 15:
                $energy = json_decode($data, true)['energy']['todayQuarterHours'];
                break;
            case 60:
                $energy = json_decode($data, true)['energy']['todayHours'];
                break;
        }
        $result = [];
        foreach ($energy as $data) {
            $date = explode('-', $data['date']);
            $result[] = [
                'start' => mktime($data['hour'], $data['minute'], 0, intval($date[1]), intval($date[2]), intval($date[0])),
                'end'   => mktime($data['hour'] + 1, $data['minute'], 0, intval($date[1]), intval($date[2]), intval($date[0])),
                'price' => $data['priceIncludingVat'] * 100,
            ];
        }
        return json_encode($result);
    }
}
