<?php

    class Strompreis extends IPSModule
    {

        public function Create()
        {
            $this->RegisterPropertyString("Provider", "aWATTar");
            $this->RegisterPropertyString("EPEXSpotMarket", "DE-LU");
            $this->RegisterPropertyString("aWATTarMarket", "de");
            $this->RegisterPropertyFloat("PriceBase", 19.5);
            $this->RegisterPropertyFloat("PriceSurcharge", 3);
            $this->RegisterPropertyFloat("PriceTax", 19);

            if (!IPS_VariableProfileExists("Cent")) {
                IPS_CreateVariableProfile("Cent", 2);
                IPS_SetVariableProfileDigits("Cent", 2);
                IPS_SetVariableProfileText("Cent", "", " ct");
            }

            $this->RegisterVariableString("MarketData", $this->Translate("Market Data"), "~TextBox", 0);
            $this->RegisterVariableFloat("CurrentPrice", $this->Translate("Current Price"), "Cent", 1);

            $this->SetVisualizationType(1);

            // Load initially after 30 seconds
            $this->RegisterTimer("Update", 30000, 'SPX_Update($_IPS["TARGET"]);');
        }

        public function GetConfigurationForm() {
            $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            $form['elements'][1]['visible'] = $this->ReadPropertyString("Provider") === "aWATTar";
            $form['elements'][2]['visible'] = $this->ReadPropertyString("Provider") === "EPEXSpot";
            return json_encode($form);
        }

        public function Update()
        {
            $marketData = "[]";
            switch($this->ReadPropertyString("Provider")) {
                case "aWATTar":
                    $marketData = $this->FetchFromAwattar($this->ReadPropertyString("aWATTarMarket"));
                    break;
                case "EPEXSpot":
                    $marketData = $this->FetchFromEPEXSpot($this->ReadPropertyString("EPEXSpotMarket"));
                    break;
            }
            $this->UpdateVisualizationValue($marketData);
            $this->SetValue("MarketData", $marketData);

            $currentTime = time();
            $found = false;
            foreach (json_decode($marketData) as $row) {
                if ($currentTime >= $row->start && $currentTime <= $row->end) {
                    $this->SetValue("CurrentPrice", $row->price);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->SetValue("CurrentPrice", 999.99);
            }

            // Synchronize to xx:00:30
            $waitTime = (3600 * 1000) - ((microtime(true)*1000) % (3600 * 1000)) + (30 * 1000);
            $this->SetTimerInterval("Update", $waitTime);
        }

        public function GetVisualizationTile()
        {
            // Add static HTML content from file to make editing easier
            $module = file_get_contents(__DIR__ . '/module.html');

            // Inject current values
            $module = str_replace('%market_data%', $this->GetValue('MarketData'), $module);

            // Return everything to render our fancy tile!
            return $module;
        }

        private function NormalizeAndReduce($data)
        {
            $result = [];

            $base = $this->ReadPropertyFloat("PriceBase");
            $surcharge = (100 + $this->ReadPropertyFloat("PriceSurcharge")) / 100;

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
            if (count($data) > 24) {
                for($i = 0; $i < 48; $i++) {
                    if (count($data)) {
                        if (time() > ($data[0]['end_timestamp'] / 1000)) {
                            array_shift($data);
                        }
                    }
                }
            }
            foreach($data as $row) {
                $value = [
                    "start" => $row['start_timestamp'] / 1000,
                    "end"   => $row['end_timestamp'] / 1000,
                ];
                switch($row['unit']) {
                    case "Eur/MWh":
                        $value["price"] = $base + ((($row['marketprice'] * (1 + ($this->ReadPropertyFloat("PriceTax") / 100))) / 10) * $surcharge);
                        break;
                    default:
                        $value["price"] = 0;
                        break;
                }
                $result[] = $value;
            }

            return json_encode($result);
        }

        private function FetchFromEPEXSpotEx($market, $trading_date, $delivery_date) {
            $params = [
                "market_area"   => $market,
                "trading_date"  => $trading_date,
                "delivery_date" => $delivery_date,
                "modality"      => "Auction",
                "sub_modality"  => "DayAhead",
                "product"       => "60",
                "data_mode"     => "table",
                "ajax_form"     => "1",
            ];

            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query([
                        "form_id" => "market_data_filters_form",
                        "_triggering_element_name" => "submit_js",
                    ]),
                ],
            ];

            $response = file_get_contents("https://www.epexspot.com/en/market-data?" . http_build_query($params), false, stream_context_create($opts));

            $json = json_decode($response);

            // HTML extrahieren
            foreach ($json as $key => $value) {
                if ($value->command == 'invoke' && $value->method == 'html' && $value->selector == '.js-md-widget') {
                    $invoke = $value;
                }
            }

            // Als HTML DOM laden
            $doc = new DOMDocument();
            @$doc->loadHTML($invoke->args[0]); // Das @-Zeichen unterdrückt Warnungen wegen fehlerhaftem HTML
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
                list($startHour, $endHour) = explode(' - ', $row[0]);
                $startTime = clone $date;
                $startTime->setTime((int)$startHour, 0); // Stunden und Minuten setzen
                $endTime = clone $date;
                $endTime->setTime((int)$endHour, 0); // Stunden und Minuten setzen

                $result[] = [
                    "start_timestamp" => $startTime->getTimestamp() * 1000,
                    "end_timestamp" => $endTime->getTimestamp() * 1000,
                    "marketprice" => floatval($row[4]),
                    "unit" => "Eur/MWh"
                ];
            }
            return $result;
        }

        private function FetchFromEPEXSpot($market)
        {
            $data = [];
            $data = array_merge($data, $this->FetchFromEpexSpotEx($market, date("Y-m-d", strtotime("-1 day")), date("Y-m-d")));
            if (date("H") >= 14) {
                $data = array_merge($data, $this->FetchFromEpexSpotEx($market, date("Y-m-d"), date("Y-m-d", strtotime("+1 day"))));
            }
            return $this->NormalizeAndReduce($data);
        }

        private function FetchFromAwattar($market)
        {
            $start = mktime(0, 0, 0, date("m"), date("d"), date("Y"));
            $end = mktime(23, 59, 59, date("m"), date("d") + 1, date("Y"));
            $data = file_get_contents(sprintf("https://api.awattar.%s/v1/marketdata?start=%s&end=%s", $market, $start * 1000, $end * 1000));
            return $this->NormalizeAndReduce(json_decode($data, true)['data']);
        }

        public function UIChangeProvider(string $Provider)
        {
            $this->UpdateFormField("aWATTarMarket", "visible", $Provider === "aWATTar");
            $this->UpdateFormField("EPEXSpotMarket", "visible", $Provider === "EPEXSpot");
        }
    }
?>
