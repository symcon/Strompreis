<?php

declare(strict_types=1);
include_once __DIR__ . '/timetest.php';
include_once __DIR__ . '/getcontentstest.php';

class PowerPrice extends IPSModule
{
    use TestTime;
    use TestGetContents;

    public function Create()
    {
        $this->RegisterPropertyString('Provider', 'aWATTar');
        $this->RegisterPropertyString('EPEXSpotToken', '');
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
        $form = json_decode($this->getContents(__DIR__ . '/form.json'), true);
        $form['elements'][1]['visible'] = $this->ReadPropertyString('Provider') === 'aWATTar';
        $form['elements'][2]['visible'] = $this->ReadPropertyString('Provider') === 'Tibber';
        $form['elements'][3]['visible'] = $this->ReadPropertyString('Provider') === 'EPEXSpot';
        $form['elements'][4]['visible'] = $this->ReadPropertyString('Provider') === 'EPEXSpot';
        $form['elements'][5]['visible'] = $this->ReadPropertyString('Provider') === 'EPEXSpot';

        // Price resolution can only be changed for Tibber
        $form['elements'][6]['visible'] = $this->ReadPropertyString('Provider') === 'Tibber';

        // Tibber includes full price information
        $form['elements'][7]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        $form['elements'][8]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        $form['elements'][9]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        $form['elements'][10]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
        $form['elements'][11]['visible'] = $this->ReadPropertyString('Provider') != 'Tibber';
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
                $marketData = $this->FetchFromEntsoe($this->ReadPropertyString('EPEXSpotMarket'));
                break;
        }
        $this->UpdateVisualizationValue($marketData);
        $this->SetValue('MarketData', $marketData);

        // Set next market data update to synchronize to full 2-hour intervals (00:00, 02:00, 04:00, etc.)
        $currentHour = (int) date('H');
        $currentMinute = (int) date('i');
        $currentSecond = (int) date('s');

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
        $this->UpdateCurrentPrice();
    }

    public function UpdateCurrentPrice()
    {
        $marketData = $this->GetValue('MarketData');
        $currentTime = $this->getTime();
        $this->SendDebug('UpdateCurrentPrice - Current Time', date('H:i:s', $currentTime) . '(' . $currentTime . ')', 0);
        $found = false;

        foreach (json_decode($marketData) as $row) {
            if ($currentTime >= $row->start && $currentTime < $row->end) {
                $this->SendDebug('UpdateCurrentPrice - Found row', json_encode($row), 0);
                $this->SetValue('CurrentPrice', $row->price);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $this->SetValue('CurrentPrice', 999.99);
        }

        // Set next current price update based on price resolution
        $priceResolution = $this->GetPriceResolution() * 60;
        $this->SendDebug('UpdateCurrentPrice - Price Resolution', json_encode($priceResolution), 0);

        $remainder = $currentTime % $priceResolution;
        $nextUpdate = $currentTime + ($priceResolution - $remainder);

        $this->SetTimerInterval('UpdateCurrentPrice', max(($nextUpdate - $this->getTime()) * 1000, 1));
    }

    public function GetVisualizationTile()
    {
        // Add static HTML content from file to make editing easier
        $module = $this->getContents(__DIR__ . '/module.html');

        // Inject current values
        $module = str_replace('%market_data%', $this->GetValue('MarketData'), $module);

        // Determine resolution from market data if available, otherwise use property
        $priceResolution = $this->GetPriceResolution();

        // Inject resolution configuration
        $module = str_replace('%price_resolution%', strval($priceResolution), $module);

        // Return everything to render our fancy tile!
        return $module;
    }

    public function UIChangeProvider(string $Provider)
    {
        $this->UpdateFormField('aWATTarMarket', 'visible', $Provider === 'aWATTar');
        $this->UpdateFormField('TibberPostalCode', 'visible', $Provider === 'Tibber');
        $this->UpdateFormField('EPEXSpotMarket', 'visible', $Provider === 'EPEXSpot');
        $this->UpdateFormField('EPEXSpotToken', 'visible', $Provider === 'EPEXSpot');
        $this->UpdateFormField('EPEXSpotTokenHint', 'visible', $Provider === 'EPEXSpot');

        $this->UpdateFormField('PriceResolution', 'visible', $Provider === 'Tibber');
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
        // Determine resolution from first data entry if available, otherwise use property
        $priceResolution = $this->GetPriceResolution($data, 'start_timestamp', 'end_timestamp', 1000);

        $multiplier = 60 / $priceResolution;
        if (count($data) > (24 * $multiplier)) {
            $now = $this->getTime();
            $this->SendDebug('Filter Data - Now', date('Y-m-d H:i:s', $now) . "($now)", 0);
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

    private function FetchFromEntsoe($market)
    {
        switch ($market) {
            case 'AT':
                $market = '10YAT-APG------L'; // Austria
                break;
            case 'BE':
                $market = '10YBE----------2'; // Belgium
                break;
            case 'CH':
                $market = '10YCH-SWISSGRIDZ'; // Switzerland
                break;
            case 'DE-LU':
                $market = '10Y1001A1001A82H'; // Germany/Luxembourg
                break;
            case 'DK1':
                $market = '10YDK-1--------W'; // Denmark 1
                break;
            case 'DK2':
                $market = '10YDK-2--------M'; // Denmark 2
                break;
            case 'FI':
                $market = '10YFI-1--------U'; // Finland
                break;
            case 'FR':
                $market = '10YFR-RTE------C'; // France
                break;
            case 'GB':
                $market = '10YGB----------A'; // Great Britain
                break;
            case 'NL':
                $market = '10YNL----------L'; // Netherlands
                break;
            case 'NO1':
                $market = '10YNO-1--------2'; // Norway 1
                break;
            case 'NO2':
                $market = '10YNO-2--------T'; // Norway 2
                break;
            case 'NO3':
                $market = '10YNO-3--------J'; // Norway 3
                break;
            case 'NO4':
                $market = '10YNO-4--------9'; // Norway 4
                break;
            case 'NO5':
                $market = '10YNO-5--------C'; // Norway 5
                break;
            case 'PHELIX':
                $market = '10Y1001A1001A46C'; // PHELIX (Physical Electricity Index)
                break;
            case 'PL':
                $market = '10YPL-AREA-----S'; // Poland
                break;
            case 'SE1':
                $market = '10Y1001A1001A44P'; // Sweden 1
                break;
            case 'SE2':
                $market = '10Y1001A1001A45W'; // Sweden 2
                break;
            case 'SE3':
                $market = '10Y1001A1001A46L'; // Sweden 3
                break;
            case 'SE4':
                $market = '10Y1001A1001A47J'; // Sweden 4
                break;
            default:
                $this->SendDebug('FetchFromEntsoe - Unsupported Market', $market, 0);
                return [];
        }
        $start = mktime(0, 0, 0, intval(date('m', $this->getTime())), intval(date('d', $this->getTime())), intval(date('Y', $this->getTime())));
        $end = strtotime('+2 days', $start);
        $dateFormat = 'YmdHi';
        $securityToken = $this->ReadPropertyString('EPEXSpotToken');
        $this->SendDebug('FetchFromEntsoe - Request', "Fetching data from $market between " . date('Y-m-d H:i:s', $start) . "($start) and " . date('Y-m-d H:i:s', $end) . "($end)", 0);
        $data = $this->getContents(sprintf('https://web-api.tp.entsoe.eu/api?securityToken=%s&documentType=A44&periodStart=%s&periodEnd=%s&out_Domain=%s&in_Domain=%s', $securityToken, gmdate($dateFormat, $start), gmdate($dateFormat, $end), $market, $market));
        $this->SendDebug('FetchFromEntsoe - Result', json_encode($data), 0);

        if (!is_string($data)) {
            $this->SendDebug('FetchFromEntsoe - Error', 'Failed to fetch data', 0);
            return json_encode([]);
        }

        // Parse XML and extract Point data
        $xml = simplexml_load_string($data);
        if ($xml === false) {
            $this->SendDebug('FetchFromEntsoe - XML Parse Error', 'Failed to parse XML', 0);
            return json_encode([]);
        }

        // Get all TimeSeries elements and their Points
        $result = [];
        $timeSeriesList = [];
        $timeSeriesIndexes = [];
        foreach ($xml->children('urn:iec62325.351:tc57wg16:451-3:publicationdocument:7:3') as $child) {
            if ($child->getName() === 'TimeSeries') {
                $positionElement = (string) $child->{'classificationSequence_AttributeInstanceComponent.position'};
                $start = (string) $child->Period->timeInterval->start;
                $index = strlen($positionElement) > 0 ? (int) $positionElement : 0;
                if (!isset($timeSeriesIndexes[$start]) || $index < $timeSeriesIndexes[$start]) {
                    $timeSeriesIndexes[$start] = $index;
                    $timeSeriesList[$start] = $child;
                }
            }
        }

        if (empty($timeSeriesList)) {
            $this->SendDebug('FetchFromEntsoe - No TimeSeries Found', 'No TimeSeries element found in XML', 0);
            return json_encode([]);
        }

        foreach ($timeSeriesList as $timeSeries) {
            $period = $timeSeries->Period;
            $this->SendDebug('FetchFromEntsoe - Period', json_encode($period), 0);
            $this->SendDebug('FetchFromEntsoe - Period Start', (string) $period->timeInterval->start, 0);
            $start = strtotime((string) $period->timeInterval->start);
            $resolution = ((string) $period->resolution === 'PT15M' ? 15 : 60) * 60; // Currently only handling 15 or 60 minutes
            $currency = '';
            $unit = '';
            // Access elements with dots in their names using array syntax
            $currencyName = (string) $timeSeries->{'currency_Unit.name'};
            $unitName = (string) $timeSeries->{'price_Measure_Unit.name'};

            switch ($currencyName) {
                case 'EUR':
                    $currency = 'Eur';
                    // Valid unit
                    break;
                default:
                    $this->SendDebug('FetchFromEntsoe - Unsupported Currency', $currencyName, 0);
                    $currency = $currencyName;
                    break;
            }
            switch ($unitName) {
                case 'MWH':
                    $unit = 'MWh';
                    // Valid unit
                    break;
                default:
                    $this->SendDebug('FetchFromEntsoe - Unsupported Unit', $unitName, 0);
                    $unit = $unitName;
                    break;
            }
            $position = 1;
            foreach ($period->Point as $point) {
                $pointPosition = (int) $point->position;
                while ($position < $pointPosition) {
                    if (count($result) === 0) {
                        $this->SendDebug('FetchFromEntsoe - Missing position at start', $unitName, 0);
                        break; // No previous value to fill from
                    }
                    // Fill missing points with previous
                    $previous = end($result);
                    $result[] = [
                        'start_timestamp' => (($position - 1) * $resolution + $start) * 1000,
                        'end_timestamp'   => (($position) * $resolution + $start) * 1000,
                        'marketprice'     => $previous['marketprice'],
                        'unit'            => $currency . '/' . $unit,
                    ];
                    $position++;
                }
                $result[] = [
                    'start_timestamp' => (($pointPosition - 1) * $resolution + $start) * 1000,
                    'end_timestamp'   => (($pointPosition) * $resolution + $start) * 1000,
                    'marketprice'     => (float) $point->{'price.amount'},
                    'unit'            => $currency . '/' . $unit,
                ];

                $position++;
            }
        }

        usort($result, function ($a, $b)
        {
            return $a['start_timestamp'] <=> $b['start_timestamp'];
        });

        $this->SendDebug('FetchFromEntsoe - Points Found', json_encode(count($result)), 0);

        return $this->NormalizeAndReduce($result);
    }

    private function FetchFromAwattar($market)
    {
        $start = mktime(0, 0, 0, intval(date('m', $this->getTime())), intval(date('d', $this->getTime())), intval(date('Y', $this->getTime())));
        $end = strtotime('+2 days', $start);
        $this->SendDebug('FetchFromAwattar - Request', "Fetching data from $market between " . date('Y-m-d H:i:s', $start) . "($start) and " . date('Y-m-d H:i:s', $end) . "($end)", 0);
        $data = $this->getContents(sprintf('https://api.awattar.%s/v1/marketdata?start=%s&end=%s', $market, $start * 1000, $end * 1000));
        
        // Validate that data is a string and valid JSON
        if (!is_string($data) || $data === false) {
            $this->SendDebug('FetchFromAwattar - Error', 'Failed to fetch data or data is not a string', 0);
            return json_encode([]);
        }
        $this->SendDebug('FetchFromAwattar - Result', $data, 0);
        
        $decodedData = json_decode($data, true);
        if ($decodedData === null || !isset($decodedData['data'])) {
            $this->SendDebug('FetchFromAwattar - Error', 'Invalid JSON response or missing data field', 0);
            return json_encode([]);
        }
        
        return $this->NormalizeAndReduce($decodedData['data']);
    }

    private function FetchFromTibber($postalCode)
    {
        $this->SendDebug('FetchFromTibber - Postal Code', $postalCode, 0);
        $data = $this->getContents(sprintf('https://tibber.com/de/api/lookup/price-overview?postalCode=%s', $postalCode));
        $this->SendDebug('FetchFromTibber - Result', $data, 0);
        $resolution = $this->ReadPropertyInteger('PriceResolution');
        switch ($resolution) {
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
                'end'   => mktime($data['hour'], $data['minute'] + $resolution, 0, intval($date[1]), intval($date[2]), intval($date[0])),
                'price' => $data['priceIncludingVat'] * 100,
            ];
        }
        return json_encode($result);
    }

    private function GetPriceResolution($data = null, $startField = 'start', $endField = 'end', $divisorToSeconds = 1)
    {
        if ($data === null) {
            $data = json_decode($this->GetValue('MarketData'), true);
        }
        $this->SendDebug('GetPriceResolution - Data', json_encode($data), 0);
        // Determine resolution from first data entry if available, otherwise use property
        if (count($data) > 0 && isset($data[0][$endField]) && isset($data[0][$startField])) {
            $resolutionSeconds = ($data[0][$endField] - $data[0][$startField]) / $divisorToSeconds;
            $resolutionMinutes = $resolutionSeconds / 60;
            if ($resolutionMinutes > 0) {
                return (int) $resolutionMinutes;
            }
        }
        return $this->ReadPropertyInteger('PriceResolution');
    }
}
