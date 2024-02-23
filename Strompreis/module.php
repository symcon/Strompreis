<?php

    class Strompreis extends IPSModule
    {

        public function Create()
        {
            $this->RegisterPropertyString("Provider", "aWATTar");
            $this->RegisterPropertyFloat("PriceBase", 19.5);
            $this->RegisterPropertyFloat("PriceSurcharge", 3);

            if (!IPS_VariableProfileExists("EuroCent")) {
                IPS_CreateVariableProfile("EuroCent", 2);
                IPS_SetVariableProfileDigits("EuroCent", 2);
                IPS_SetVariableProfileText("EuroCent", "", " ct");
            }

            $this->RegisterVariableString("MarketData", $this->Translate("Market Data"), "~TextBox", 0);
            $this->RegisterVariableFloat("CurrentPrice", $this->Translate("Current Price"), "EuroCent", 1);

            $this->SetVisualizationType(1);

            // Load initially after 30 seconds
            $this->RegisterTimer("Update", 30000, 'SPX_Update($_IPS["TARGET"]);');
        }

        public function Update()
        {
            $marketData = "[]";
            switch($this->ReadPropertyString("Provider")) {
                case "aWATTar":
                    $marketData = $this->FetchFromAwattar();
                    break;
                case "EPEXSpotDE":
                    $marketData = $this->FetchFromEpexSpot("de");
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

        private function FetchFromEpexSpot($market)
        {
            return "[]";
        }

        private function FetchFromAwattar()
        {
            $result = [];

            $start = mktime(0, 0, 0, date("m"), date("d"), date("Y"));
            $end = mktime(23, 59, 59, date("m"), date("d") + 1, date("Y"));
            $data = file_get_contents(sprintf("https://api.awattar.de/v1/marketdata?start=%s&end=%s", $start * 1000, $end * 1000));

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
            $json = json_decode($data);
            if (count($json->data) > 24) {
                for($i = 0; $i < 48; $i++) {
                    if (count($json->data)) {
                        if (time() > ($json->data[0]->end_timestamp / 1000)) {
                            array_shift($json->data);
                        }
                    }
                }
            }
            foreach($json->data as $row) {
                $value = [
                    "start" => $row->start_timestamp / 1000,
                    "end"   => $row->end_timestamp / 1000,
                ];
                switch($row->unit) {
                    case "Eur/MWh":
                        $value["price"] = $base + ((($row->marketprice * 1.19) / 10) * $surcharge);
                        break;
                    default:
                        $value["price"] = 0;
                        break;
                }

                $result[] = $value;
            }

            return json_encode($result);
        }
    }
?>
