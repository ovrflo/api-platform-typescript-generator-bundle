Description
---

This bundle exposes a command to generate typescript interfaces for your API platform resources.
It can generate interfaces for models, enums, API endpoints, app routes and more.

Why?
---
When bootstrapping a new project with API platform, I found myself writing a lot of boilerplate code to get the frontend to work with the API.
Looking for a solution for generating glue code for the frontend, I couldn't find anything to express the breadth of metadata that API platform exposes.
Another factor was the fact that existing solutions tend to be focused on boostraping the project, with limited use after that.

This bundle aims to generate the glue code for the frontend, and keep it up to date with the API platform metadata
while you develop your app.

Installation
---

```bash
composer require --dev ovrflo/api-platform-typescript-generator-bundle:dev-main
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

The generated endpoint files also rely on an `ApiMethods.ts` file existing in your project
and exporting a few functions that are used to make the API calls.

```typescript

Output
---
When running the command, it will generate a bunch of files in the `config.output_dir` (default `assets/api`) directory.

```bash
<output_dir>/interfaces/ApiTypes.ts # common interfaces for API platform
<output_dir>/interfaces/Enum.ts # enum types
<output_dir>/interfaces/<Entity|Resource>.ts # interfaces for the different entities discovered
<output_dir>/endpoint/<Resource>.ts
```
