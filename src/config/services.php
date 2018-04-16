<?php

return [
    'ec2' => [
        'key' => env('EC2_KEY'),
        'secret' => env('EC2_SECRET'),
        'region' => env('EC2_REGION', 'ap-southeast-1'),
        'profiles' => []
    ]
];