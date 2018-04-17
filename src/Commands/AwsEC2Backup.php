<?php

namespace pinCnx\laravelAwsDeploy\Commands;

use Aws\Credentials\Credentials;
use Illuminate\Console\Command;
use Aws\Ec2\Ec2Client;
use Aws\AutoScaling\AutoScalingClient;


class AwsEC2Backup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ec2backup {--profile=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create ec2 image';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {


        //$mode = $this->option('mode');

        $credential = new Credentials(config('services.ec2.key'), config('services.ec2.secret'), '');
        $ec2Region = config('services.ec2.region');

        $awsProfile = [
            'credentials' => $credential,
            'region' => $ec2Region,
            'version' => 'latest',
            'http' => [
                'verify' => config_path() . "/ca-bundle.crt"
            ]
        ];

        $profiles = config('services.ec2.profiles');

//        var_dump($profiles);


        $AIM_TO_KEEP_AMOUNT = 5; //number
        $AIM_TO_KEEP_DAY = 3; //Day


//echo $result->__toString();
//exit();


//$snapshot_ = 'snap-ed6d246c';
        /*$this->info("Create current snapshot of /".PHP_EOL);
        $result = $clientEc2->createSnapshot(array(
            'DryRun' => false,
            'VolumeId' => 'vol-f2f13be9',
            'Description' => $confName." /",
        ));
        $snapshot_ = $result->getPath('SnapshotId');
        $this->info("Done snapshot id {$snapshot_}".PHP_EOL);
        */

//$snapshot_mnt_extra ='snap-922c6213';
        /*$this->info("Create current snapshot of /mnt/extra".PHP_EOL);
        $result = $client->createSnapshot(array(
            'DryRun' => false,
            'VolumeId' => 'vol-c00ed9db',
            'Description' => $confName." /mnt/extra",
        ));
        $snapshot_mnt_extra = $result->getPath('SnapshotId');*/

        //    $clientAs = new AutoScalingClient($awsProfile);

        $spec_profile = $this->option('profile');

        if ($spec_profile) {
            $tmp = [];
            foreach ($profiles as $Key => $profile) {
                if ($Key == $spec_profile) {
                    $tmp[$Key] = $profile;
                }
            }

            $profiles = $tmp;

        }


        foreach ($profiles as $Key => $profile) {

            $aim_prefix = $profile['AMI_PREFIX'];
            $profiles[$Key]['confName'] = $aim_prefix . '-' . date('Ymd-His') . rand(1000, 9999) . '';
            $this->info("==== Profile:$Key conf: " . $profiles[$Key]['confName'] . " region[" . implode(',', $profile['region']) . "]=====" . PHP_EOL);

            $clientEc2 = new Ec2Client($awsProfile);


            $this->info("Check instance status");
            $result = $clientEc2->describeInstanceStatus(array(
                'DryRun' => false,
                'InstanceIds' => array($profile['InstanceId']),
            ));

            $InstanceState_Code = $result['InstanceStatuses'][0]['InstanceState']['Code'];
            $SystemStatus_Status = $result['InstanceStatuses'][0]['SystemStatus']['Status'];

            if ($InstanceState_Code != '16' || $SystemStatus_Status != 'ok') {
                $this->info("!!!Fail!!!" . PHP_EOL);
                $this->info("InstanceState:$InstanceState_Code" . PHP_EOL);
                $this->info("SystemStatus:$SystemStatus_Status" . PHP_EOL);
                $this->info("!!!Fail!!!" . PHP_EOL);
                continue;
            }
            $this->info("Instance State Code [$InstanceState_Code] System Status [$SystemStatus_Status]" . PHP_EOL);
            //$imageID = 'ami-e05a0888';
            $this->info("Create image of ec2 from instance id " . $profile['InstanceId'] . " to image " . $profiles[$Key]['confName']);
            $result = $clientEc2->createImage(array(
                'DryRun' => false,
                // InstanceId is required
                'InstanceId' => $profile['InstanceId'],
                // Name is required
                'Name' => $profiles[$Key]['confName'],
                'Description' => $profiles[$Key]['confName'],
                'NoReboot' => $profiles[$Key]['NoReboot']?true:false,
                /*
                    'BlockDeviceMappings' => array(
                        array(
                            //'VirtualName' => 'string',
                            'DeviceName' => '/dev/sda1',
                            'Ebs' => array(
                                'SnapshotId' => $snapshot_,
                                'VolumeSize' => 8,
                                'DeleteOnTermination' => true ,
                                'VolumeType' => 'gp2',
                                //'Iops' => integer,
                                //'Encrypted' => false,
                            ),

                        ),
                        array(
                            //'VirtualName' => 'string',
                            'DeviceName' => '/dev/xvdf',
                            'Ebs' => array(
                                'SnapshotId' => $snapshot_mnt_extra,
                                'VolumeSize' => 20,
                                'DeleteOnTermination' => true ,
                                'VolumeType' => 'gp2',
                                //'Iops' => integer,
                                //'Encrypted' => false,
                            ),

                        ),
                        // ... repeated
                    ),*/

            ));
            $imageID = $result['ImageId'];
            $this->info("ImageId [$imageID]" . PHP_EOL);
            $profiles[$Key]['ImageId'] = $imageID;


            $tags = $profiles[$Key]['AMI_TAGS'];


            $this->info("Create tag for $imageID" . PHP_EOL);
            $clientEc2->createTags(array(
                'DryRun' => false,
                // Resources is required
                'Resources' => array($imageID),
                // Tags is required
                'Tags' => array_merge($tags, array(array(
                    'Key' => 'role',
                    'Value' => $Key,
                )))
            ));
        }
        foreach ($profiles as $Key => $profile) {
            if ($profile['ImageId'] == '') continue;

            $clientEc2 = new Ec2Client($awsProfile);
            $clientAs = new AutoScalingClient($awsProfile);


            $this->info("==== Profile:$Key conf: " . $profiles[$Key]['confName'] . " image: {$profile['ImageId']} =====" . PHP_EOL);

            $this->info("Wait for image {$profile['ImageId']} being ready ");
            $success = false;
            $BlockDeviceMappings = null;
            while (true) {
                $result = $clientEc2->describeImages(array(
                    'DryRun' => false,
                    'ImageIds' => array($profile['ImageId']),
                ));
                $State = $result['Images'][0]['State'];
                $BlockDeviceMappings = $result['Images'][0]['BlockDeviceMappings'];


                if ($State == 'pending') {
                    $this->info(".");
                } elseif ($State == 'failed') {
                    $this->info("!!!Fail!!!" . PHP_EOL);
                    $this->info("Image State: $State" . PHP_EOL);
                    $this->info("!!!Fail!!!" . PHP_EOL);
                    break;
                } elseif ($State == 'available') {
                    $success = true;
                    break;
                } else {
                    $this->info("$State");
                }
                sleep(2);
            }

            if (!$success) {
                continue;
            }

            $this->info("\n");
            $this->info("Done image id {$profile['ImageId']}" . PHP_EOL);

            $this->info("Image contain " . count($BlockDeviceMappings) . " blocks" . PHP_EOL);
            foreach ($BlockDeviceMappings as $BlockDevice) {
                $snapshot = $BlockDevice['Ebs']['SnapshotId'];
                $this->info("Create tag for $snapshot" . PHP_EOL);
                $clientEc2->createTags(array(
                    'DryRun' => false,
                    // Resources is required
                    'Resources' => array($snapshot),
                    // Tags is required
                    'Tags' => array_merge($tags, array(array(
                        'Key' => 'role',
                        'Value' => $Key,
                    )))
                ));
            }

            if ($profile['AutoScalingGroupName']) {
                //*/
                $this->info("Create Launch Configuration" . PHP_EOL);
                $clientAs->createLaunchConfiguration(array(
                    // LaunchConfigurationName is required
                    'LaunchConfigurationName' => $profiles[$Key]['confName'],
                    'ImageId' => $profile['ImageId'],
                    'KeyName' => $profile['KeyName'],
                    'SecurityGroups' => array($profile['SecurityGroups']),
                    'UserData' => base64_encode(array_get($profile, 'UserData')),
//                    'InstanceType' => "t2.medium",
                    'InstanceType' => $profile['InstanceType'],
                    /*'InstanceId' => 'string',
                    'InstanceType' => 'string',
                    /*'KerlId' => 'string',
                    'RamdiskId' => 'string',*/
                    'BlockDeviceMappings' => array(
                        array(
                            //'VirtualName' => 'string',
                            // DeviceName is required
                            'DeviceName' => '/dev/sda1',
                            'Ebs' => array(
                                //'SnapshotId' => $snapshot_,
                                'VolumeSize' => $profile['VolumeSize'],
                                'VolumeType' => 'gp2',
                                'DeleteOnTermination' => true,
                            ),
                        )/*,
                    array(
                        //'VirtualName' => 'string',
                        // DeviceName is required
                        'DeviceName' => '/dev/xvdf',
                        'Ebs' => array(
                            //'SnapshotId' => $snapshot_mnt_extra,
                            'VolumeSize' => 20,
                            'VolumeType' => 'gp2',
                            'DeleteOnTermination' => true,
                        ),
                    ),*/
                        // ... repeated
                    ),
                    //'InstanceMonitoring' => array(
                    //	'Enabled' => true || false,
                    //),
                    //'SpotPrice' => 'string',
                    //'IamInstanceProfile' => 'webserver',
                    //'EbsOptimized' => true || false,
                    'AssociatePublicIpAddress' => true,
                    //'PlacementTenancy' => 'string',//*/
                ));//*/


                /*$result = $clientAs->describeLaunchConfigurations(array(
                    'LaunchConfigurationNames' => array($profiles[$Key]['confName']),
                ));

                $LaunchConfigurationsARN = $result->getPath('LaunchConfigurations/0/LaunchConfigurationARN');
                $this->info("Launch Configuration ID: $LaunchConfigurationsARN");*/


                if (!$profile['IsTerminateCurrentInstance']) {
                    $this->info("Update AutoScalingGroup " . $profile['AutoScalingGroupName'] . PHP_EOL);
                    $clientAs->updateAutoScalingGroup(array(
                        // AutoScalingGroupName is required
                        'AutoScalingGroupName' => $profile['AutoScalingGroupName'],
                        'LaunchConfigurationName' => $profiles[$Key]['confName'],
                        //'MinSize' => 1,
                        //'MaxSize' => $MaxSize * 2,
                        //'DesiredCapacity' => $DesiredCapacity * 2,
                        //'DefaultCooldown' => 300,
                        //'AvailabilityZones' => array('us-east-1a'),
                        //'HealthCheckType' => 'ELB',
                        //'HealthCheckGracePeriod' => 300,
                        //'PlacementGroup' => 'string',
                        //'VPCZoneIdentifier' => 'subnet-4ccb1d67',
                        //'TerminationPolicies' => array('string', ... ),
                    ));

                } else {
                    $this->info("Grep AutoScalingGroup " . $profile['AutoScalingGroupName'] . "" . PHP_EOL);
                    $result = $clientAs->describeAutoScalingGroups([
                        'AutoScalingGroupNames' => [$profile['AutoScalingGroupName']],
                    ]);

                    $DesiredCapacity = max(1, $result['AutoScalingGroups'][0]['DesiredCapacity']);
                    $MaxSize = max(1, $result['AutoScalingGroups'][0]['MaxSize']);

                    $this->info("Update AutoScalingGroup " . $profile['AutoScalingGroupName'] . " Launch[" . $profiles[$Key]['confName'] . "]" . PHP_EOL);
                    $clientAs->updateAutoScalingGroup(array(
                        // AutoScalingGroupName is required
                        'AutoScalingGroupName' => $profile['AutoScalingGroupName'],
                        'LaunchConfigurationName' => $profiles[$Key]['confName'],
                        //'MinSize' => 1,
                        //'MaxSize' => $MaxSize * 2,
                        //'DesiredCapacity' => $DesiredCapacity * 2,
                        //'DefaultCooldown' => 300,
                        //'AvailabilityZones' => array('us-east-1a'),
                        //'HealthCheckType' => 'ELB',
                        //'HealthCheckGracePeriod' => 300,
                        //'PlacementGroup' => 'string',
                        //'VPCZoneIdentifier' => 'subnet-4ccb1d67',
                        //'TerminationPolicies' => array('string', ... ),
                    ));

                    $tmp_desire = $DesiredCapacity * 2;
                    $tmp_max = max($DesiredCapacity * 2, $MaxSize);

                    $this->info("Update AutoScalingGroup " . $profile['AutoScalingGroupName'] . " Desire[$DesiredCapacity => " . ($tmp_desire) . "] Max[$MaxSize => " . ($tmp_max) . "]" . PHP_EOL);
                    $clientAs->updateAutoScalingGroup(array(
                        // AutoScalingGroupName is required
                        'AutoScalingGroupName' => $profile['AutoScalingGroupName'],
                        //'LaunchConfigurationName' => $profiles[$Key]['confName'],
                        //'MinSize' => 1,
                        'MaxSize' => $tmp_max,
                        'DesiredCapacity' => $tmp_desire,
                        //'DefaultCooldown' => 300,
                        //'AvailabilityZones' => array('us-east-1a'),
                        //'HealthCheckType' => 'ELB',
                        //'HealthCheckGracePeriod' => 300,
                        //'PlacementGroup' => 'string',
                        //'VPCZoneIdentifier' => 'subnet-4ccb1d67',
                        //'TerminationPolicies' => array('string', ... ),
                    ));


                    $this->info("Wait capacity " . PHP_EOL);

                    $isCancel = false;
                    while (true) {
                        $result = $clientAs->describeAutoScalingGroups([
                            'AutoScalingGroupNames' => [$profile['AutoScalingGroupName']],
                        ]);


                        $currentLaunchConfigurationName = $result['AutoScalingGroups'][0]['LaunchConfigurationName'];

                        if ($currentLaunchConfigurationName != $profiles[$Key]['confName']) {
                            $this->info("Detect new AutoScalingGroup launch conf conflict expect " . $profiles[$Key]['confName'] . " but $currentLaunchConfigurationName [BREAK CURRENT THREAD]" . PHP_EOL);
                            $isCancel = true;
                            break;
                        }


                        $currentDesiredCapacity = $result['AutoScalingGroups'][0]['DesiredCapacity'];
                        if ($currentDesiredCapacity < $tmp_desire) {
                            $this->info("Detect scale down re-configure AutoScalingGroup " . $profile['AutoScalingGroupName'] . " Desire[$currentDesiredCapacity => " . ($tmp_desire) . "]" . PHP_EOL);
                            $clientAs->updateAutoScalingGroup(array(
                                'AutoScalingGroupName' => $profile['AutoScalingGroupName'],
                                'DesiredCapacity' => $tmp_desire,
                            ));
                        }

                        $count_new_conf = 0;
                        $count_old_conf = 0;
                        $describes = "";
                        $i = 0;
                        foreach ($result['AutoScalingGroups'][0]['Instances'] as $instance) {
                            $describes .= "#$i" . $instance['LifecycleState'] . ":" . $instance['LaunchConfigurationName'] . ", ";
                            if ($instance['LifecycleState'] == 'InService' && $instance['LaunchConfigurationName'] == $profiles[$Key]['confName']) {
                                $count_new_conf++;
                            } else {
                                $count_old_conf++;
                            }
                        }
                        $this->info("O[" . $count_old_conf . "] N[" . $count_new_conf . "] $describes");

                        if ($count_new_conf >= $DesiredCapacity) {
                            break;
                        }
                        sleep(2);
                    }

                    if (!$isCancel) {
                        $this->info("Restore AutoScalingGroup Desire[$DesiredCapacity] Max[$MaxSize]" . PHP_EOL);
                        $clientAs->updateAutoScalingGroup(array(
                            // AutoScalingGroupName is required
                            'AutoScalingGroupName' => $profile['AutoScalingGroupName'],
                            //'MinSize' => 1,
                            'MaxSize' => $MaxSize,
                            'DesiredCapacity' => $DesiredCapacity,
                            //'DefaultCooldown' => 300,
                            //'AvailabilityZones' => array('us-east-1a'),
                            //'HealthCheckType' => 'ELB',
                            //'HealthCheckGracePeriod' => 300,
                            //'PlacementGroup' => 'string',
                            //'VPCZoneIdentifier' => 'subnet-4ccb1d67',
                            //'TerminationPolicies' => array('string', ... ),
                        ));

                    }
                }
//                //Terminate the current instance
//                if ($profile['IsTerminateCurrentInstance']) {
//                    $result = $clientAs->describeAutoScalingGroups([
//                        'AutoScalingGroupNames' => [$profile['AutoScalingGroupName']],
//                    ]);
//
//                    foreach ($result['AutoScalingGroups'][0]['Instances'] as $instance) {
//                        if ($instance['LifecycleState'] == 'InService' && $instance['LaunchConfigurationName'] != $profiles[$Key]['confName']) {
//                            $this->info("Terminating " . $instance['InstanceId'] . PHP_EOL);
//                            $result = $clientAs->terminateInstanceInAutoScalingGroup([
//                                'InstanceId' => $instance['InstanceId'], // REQUIRED
//                                'ShouldDecrementDesiredCapacity' => true, // REQUIRED
//                            ]);
//                        } else {
//                            $this->info("Instance " . $instance['InstanceId'] . " in status of  " . $instance['LifecycleState'] . PHP_EOL);
//                        }
//                    }
//                }
//
                $this->info("Done" . PHP_EOL);
                //$this->info("====================================".PHP_EOL);


            }
        }


        $this->info("-----------  Clean up  process ----------- " . PHP_EOL);
        foreach ($profiles as $Key => $profile) {
            $this->info("Profile: $Key" . PHP_EOL);

            $clientEc2 = new Ec2Client($awsProfile);


            $result = $clientEc2->describeImages(array(
                'DryRun' => false,
                'Filters' => array(
                    array(
                        'Name' => 'tag:stage',
                        'Values' => ['autobackup']
                    ),
                    array(
                        'Name' => 'tag:role',
                        'Values' => [$Key]
                    ),
                ),
            ));

            $ImageArray = $result['Images'];
            $countImage = count($ImageArray);
            $snapshotEbsToDelArray = array();
            $this->info("Total images so far $countImage" . PHP_EOL);
            $this->info("But keep total only $AIM_TO_KEEP_DAY days or $AIM_TO_KEEP_AMOUNT images" . PHP_EOL);

            for ($i = $countImage - 1 - $AIM_TO_KEEP_AMOUNT; $i >= 0; $i--) {
                $image = $ImageArray[$i];
                $ImageId = $image['ImageId'];
                $Name = $image['Name'];
                $CreationDate = $image['CreationDate'];

                $datePHP = strtotime(substr($CreationDate, 0, 10));
                //echo date("jS F, Y",$datePHP);

                if (time() - $datePHP < $AIM_TO_KEEP_DAY * 24 * 60 * 60) {
                    $this->info("Skip AIM Crated on $CreationDate $Name " . PHP_EOL);
                    continue;
                }
                //echo $datePHP;

                $this->info("Delete AIM Crated on $CreationDate $Name " . PHP_EOL);
                $BlockDeviceMappings = $image['BlockDeviceMappings'];
                foreach ($BlockDeviceMappings as $BlockDevice) {
                    $snapshotEbsToDelArray[] = $BlockDevice['Ebs'];
                }

                $clientEc2->deregisterImage(array(
                    'DryRun' => false,
                    // ImageId is required
                    'ImageId' => $ImageId,
                ));

            }

            foreach ($snapshotEbsToDelArray as $snapshortEbsToDel) {
                $SnapshotId = $snapshortEbsToDel['SnapshotId'];
                $VolumeSize = $snapshortEbsToDel['VolumeSize'];
                $this->info("Delete snapshot $SnapshotId size[{$VolumeSize}G]" . PHP_EOL);

                $clientEc2->deleteSnapshot(array(
                    'DryRun' => false,
                    // SnapshotId is required
                    'SnapshotId' => $SnapshotId,
                ));
            }
        }


        $this->info("----------- All Done ----------- " . PHP_EOL);

        $this->info("================================================================" . PHP_EOL);
    }
}
