<?php

declare(strict_types=1);

return [
    'endpoint' => 'https://cgi-api.vocabs.ga.gov.au/sparql',
    'allowed_host' => 'cgi-api.vocabs.ga.gov.au',
    'scheme_name' => 'CGI Simple Lithology',
    'scheme_uri' => 'http://resource.geosciml.org/classifierscheme/cgi/2016.01/simplelithology',
    'collection_uri' => 'http://resource.geosciml.org/classifier/cgi/lithology',
    'output_file' => 'cgi-simple-lithology.json',
    'connect_timeout' => 10,
    'timeout' => 60,
    'max_response_bytes' => 10 * 1024 * 1024,
    'min_concepts' => 200,
    'max_concepts' => 2000,
    'max_paths' => 10000,
    'max_depth' => 32,
];
