# Symfony Quality Cockpit

Reusable Symfony bundle for presenting PHPUnit, Infection, PhpMetrics, Deptrac and Composer
Audit reports in one development dashboard.

## Status

The package is being extracted from the Repair Workshop prototype. The extraction audit and
target architecture are documented in the source project before application code is ported.

## Installation

```bash
composer require --dev chrisdev/symfony-quality-cockpit
```

Register the bundle when Symfony Flex is not configured for the package:

```php
Chrisdev\QualityCockpit\QualityCockpitBundle::class => ['dev' => true, 'test' => true],
```

Import the routes:

```yaml
quality_cockpit:
    resource: '@QualityCockpitBundle/config/routes.php'
```

Configure the report root:

```yaml
quality_cockpit:
    reports:
        base_directory: '%kernel.project_dir%/var/reports'
```

Compatibility currently follows the extracted source environment: PHP 8.4 and Symfony 7.4 or
8.0. The Symfony 7.4 path remains to be verified in CI before it is advertised as supported.
