<?php

    class Strompreis extends IPSModule
    {

        public function Create()
        {
            $this->RegisterPropertyString("Provider", "aWATTar");
            $this->RegisterPropertyFloat("PriceBase", 19.5);
            $this->RegisterPropertyFloat("PriceSurcharge", 3);

            $this->RegisterVariableString("MarketData", $this->Translate("Market Data"), "~TextBox", 0);

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

        public function FetchFromEpexSpot($market)
        {
            return "[]";
        }

        public function FetchFromAwattar()
        {
            $result = [];
            $data = file_get_contents("https://api.awattar.de/v1/marketdata");

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
            foreach(json_decode($data)->data as $row) {
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
