# Mezzio + Twig + Vite setup

This guide walks through a complete setup of `sirix/inertia-psr15` in a Mezzio application generated with
[Mezzio Skeleton](https://github.com/mezzio/mezzio-skeleton) using Twig as the template engine. A
[small demo application](https://github.com/sirix777/mezzio-inertia-svelte-demo) shows the adapter in a Mezzio application.

### 1. Install and pipe the middleware

```shell
composer require sirix/inertia-psr15
```

```php
<?php
//my-mezzio-app/config/pipeline.php

// ...
// - $app->pipe('/files', $filesMiddleware);
$app->pipe(\Sirix\InertiaPsr15\Middleware\InertiaMiddleware::class);

// Register the routing middleware in the middleware pipeline.
// This middleware registers the Mezzio\Router\RouteResult request attribute.
$app->pipe(RouteMiddleware::class);

// ...
```

See the [README](../README.md#configuration) for the services the built-in factories need from the container.

### 2. Client side

Please refer to the [Inertia v3 client-side setup](https://inertiajs.com/docs/v3/installation/client-side-setup) to install a client-side adapter.

### 3. Vite

Using Vite is recommended to build the front-end application, however, to render the built JS/CSS
assets in a Twig template, the following extension can be used:

```shell
composer require sirix/twig-vite-extension
```

>> A factory might be needed to configure the Vite extension.

### 4. Twig configuration

Configure the template to use Vite extension and the Inertia Twig extension shipped with the adapter
by updating `config/autoload/template.global.php` and `vite.global.php` like the following:

```php
<?php

// template.global.php

declare(strict_types=1);

use Sirix\InertiaPsr15\Twig\InertiaExtension;
use Sirix\TwigViteExtension\Twig\ViteExtension;

return [
    'templates' => [
        'paths' => [
            'error'    => [dirname(__DIR__, 2) . '/templates/error'],
            '__main__' => [dirname(__DIR__, 2) . '/templates'],
        ],
    ],
    'twig'      => [
        'extensions' => [
            ViteExtension::class,
            InertiaExtension::class,
        ],
    ],
];
```

```php
<?php

// vite.global.php

declare(strict_types=1);

return [
    'vite' => [
        'options' => [
            'is_dev_mode'      => true,
            'vite_build_dir'   => 'public/build',
            'vite_public_base' => 'build',
            'dev_server'       => 'http://localhost:5173',
        ],
    ],
];
```

### 5. Root template

The adapter needs just one backend template to render the application, and by default it will look for 
`templates/app.html.twig` if a default template is not configured, the app template can be like the following:

The template name can be changed through application configuration:

```php
return [
    'inertia_psr15' => [
        'root_view' => 'inertia/app.html.twig',
    ],
];
```

```html
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}Mezzio with Inertia.js{% endblock %}</title>
    {{ vite_entry_link_tags('resources/js/app.ts') }}
</head>
<body class="font-sans antialiased">
{% block body %}
{{ inertia(page) }}
{% endblock %}

{{ vite_entry_script_tags('resources/js/app.ts') }}
</body>
</html>
```
>> The template uses Vite extension (vite_entry_link_tags) to render the assets and Inertia extension 
> `inertia(page)` to mount the front-end application.
