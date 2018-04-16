# laravel-aws-deploy
Deploy to auto scale without down time. By backup an master instance to AMI, create Launch configuration with those AMI, update it to auto scale, generate new instance, terminate the old instances.
