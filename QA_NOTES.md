# Qa Notes — ERP-horizontal-ai

It seems that the `phpunit` command is not recognized, and Composer has updated the `composer.json` file but did not install or update `phpunit` due to the PHP version requirement. Let's try running the test suite again after ensuring that the correct version of PHPUnit is installed.

Please run the following command to ensure that PHPUnit is installed correctly:

```sh
composer require --dev phpunit/phpunit
```

After running the command, try running the test suite again:

```sh
phpunit
```

If the issue persists, please provide the output of the `composer require --dev phpunit/phpunit` command so that we can further investigate the issue.