Installation
---

```bash
composer require ovrflo/api-platform-typescript-generator-bundle
```

Then, add to `config/bundles.php`:

```php
return [
    // ...
    Ovrflo\ApiPlatformTypescriptGeneratorBundle\OvrfloApiPlatformTypescriptGeneratorBundle::class => ['dev' => true],
];
```

Configuration
---

```yaml
# config/packages/ovrflo_api_platform_typescript_generator.yaml
when@dev:
    ovrflo_api_platform_typescript_generator:
        output_dir: '%kernel.project_dir%/assets/api'
        model_metadata:
            namespaces: ['App\Entity']
```


Usage
---

```bash
bin/console ovrflo:api-platform:typescript:generate
# or the watcher (requires nodejs and chokidar)
node ./vendor/.bin/generate_api_types_watch.js
```
