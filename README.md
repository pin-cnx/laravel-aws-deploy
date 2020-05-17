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
Modify services.php add following configuration. You can have many profiles, it will do them all in sequence(or just specify profile see in how to run the command)

```php
'ec2' => [
        'key' => env('EC2_KEY'),
        'secret' => env('EC2_SECRET'),
        'region' => env('EC2_REGION', 'ap-southeast-1'),
        'profiles' => [
            'AnyName' => [
                // The value with *** need to change to your own aws component name
                'AMI_PREFIX' => 'AWSDEPLOY', // Prefix for the AMI just for easy to regconize ie. AWSDEPLOY'
                'InstanceId' => '***i-0123456789abcdef', //The master instance id to clone ie. i-0123456789abcdef
                'KeyName' => '***server.pem', // The pem key name to access to the auto scale's instances
                'AutoScalingGroupName' => '***my-auto-scale', // The auto scale group name,
                'SecurityGroups' => '***sg-123456', // The security group of instance ie. sg-123456
                'InstanceType' => env('EC2_SIZE','t3.nano') , // 'Instance type ie. t2.micro',
                'VolumeSize' => 30, //(Optional) Default:30 SSD size
                'region' => ['ap-southeast-1a','ap-southeast-1b'], // Region to spawn instances
                'NoReboot' => false, //(Optional) Default:false Is it will reboot the master instance to make AMI
                'IsTerminateCurrentInstance' => true, //(Optional) Default:true Is it will terminate the old instance which launch with old configuration.
                'IamInstanceProfile' => null, //(Optional) Default:null
                'AMI_TAGS' => [ // (Optional) What ever tag you need for the new instances
                    [
                        'Key' => 'stage',
                        'Value' => 'aws-deploy',
                    ],
                    [
                        'Key' => 'Project',
                        'Value' => 'aws-deploy',
                    ]
                ]
                , 'UserData' => "#!/bin/bash \n" . // (Optional)The first boot command to the instances
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

## Troubleshooting 

#### AWS HTTP error: SSL CA bundle not found
for some reason aws need to have ca-bundle.crt with curl lib to run. So copy the ca-bundle.crt from this git repository then put it to your project at /config/ca-bundle.crt

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
