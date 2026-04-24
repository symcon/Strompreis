<?php

declare(strict_types=1);

if (defined('PHPUNIT_TESTSUITE')) {
    trait TestTime
    {
        private $currentTime = 989884800;

        public function SetTime(int $Time) : void
        {
            $this->currentTime = $Time;
        }

        protected function getTime() : int
        {
            return $this->currentTime;
        }
    }
} else {
    trait TestTime
    {
        protected function getTime() : int
        {
            return time();
        }
    }
}