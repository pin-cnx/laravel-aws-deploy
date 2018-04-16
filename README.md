# laravel-aws-deploy
The artisan command to deploy to aws EC2 auto scale without down time.
This command will doing follow step.
 1. Backup an master instance to AMI
 2. Create Launch configuration with those AMI
 3. Update it to auto scale
 4. Generate new instance
 5. Terminate the old instances.


## Before use this plugin
- You should have an master instance to generate into AMI (You have to provide an instance Id)
- Then you have to setup the auto scale in aws (You have to provide an auto scale name in config)
- The security group is need (Normally I get it from the master instance but just open the option for different group)


## Install
```bash
$ composer require pin-cnx/laravel-aws-deploy
```

## Add this config to your services.php
You can have many profiles, it will do them all in sequence(or just specify profile see in how to run the command)

```php
'ec2' => [
        'key' => env('EC2_KEY'),
        'secret' => env('EC2_SECRET'),
        'region' => env('EC2_REGION', 'ap-southeast-1'),
        'profiles' => [
            'AnyName' => [
                'AMI_PREFIX' => 'Prefix for the AMI just for easy to regconize ie. AWSDEPLOY',
                'InstanceId' => 'The master instance id to clone ie. i-0123456789abcdef',
                'KeyName' => "The pem key name to access to the auto scale's instances" ,
                'AutoScalingGroupName' => 'The auto scale group name',
                'SecurityGroups' => 'The security group of instance ie. sg-123456',
                'InstanceType' => 'Instance type ie. t2.micro',


                'VolumeSize' => 30, // SSD size
                'region' => ['ap-southeast-1a','ap-southeast-1b'], // Region to spawn instances
                'NoReboot' => True, // Is it will reboot the master instance to make AMI
                'IsTerminateCurrentInstance' => true, // Is it will terminate the old launch confuguration's instance
                'AMI_TAGS' => [ // What ever tag you need for the new instances
                    [
                        'Key' => 'stage',
                        'Value' => 'aws-deploy',
                    ],
                    [
                        'Key' => 'Project',
                        'Value' => 'aws-deploy',
                    ]
                ]

                , 'UserData' => "#!/bin/bash \n" . // The first boot command to the instances
                    "#su - www-data -c \"php /var/www/html/artisan queue:restart\""
            ]
        ]
    ]
```

## Run the command

Run all profiles

```bash
$ php artisan ec2backup
```

or just one profile

```bash
$ php artisan ec2backup --profile AnyName
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.