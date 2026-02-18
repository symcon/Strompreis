<?php

declare(strict_types=1);

if (defined('PHPUNIT_TESTSUITE')) {
    trait TestGetContents
    {
        private $contentsOverrides = [];

        public function SetContentsOverride(string $url, string $contents)
        {
            $this->contentsOverrides[$url] = $contents;
        }

        protected function getContents($url)
        {
            return $this->contentsOverrides[$url] ?? false;
        }
    }
} else {
    trait TestGetContents
    {
        protected function getContents($url)
        {
            return file_get_contents($url);
        }
    }
}