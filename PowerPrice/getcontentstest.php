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
            $override = $this->contentsOverrides[$url] ?? null;
            if ($override !== null) {
                return [
                    'body' => $override,
                    'header' => ['200']
                ];
            }
            else {
                return [
                    'body' => false,
                    'header' => ['404']
                ];
            }
        }
    }
} else {
    trait TestGetContents
    {
        protected function getContents($url)
        {
            $options = [
                'http' => [
                    'ignore_errors' => true
                ]
            ];
            return [
                'body' => file_get_contents($url, false, stream_context_create($options)),
                'header' => $http_response_header ?? null
            ];
        }
    }
}