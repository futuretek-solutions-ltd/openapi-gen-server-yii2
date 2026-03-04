# PHP OpenAPI Server Generator

[![Tests](https://github.com/futuretek-solutions-ltd/php-openapi-server-gen/actions/workflows/tests.yml/badge.svg)](https://github.com/futuretek-solutions-ltd/php-openapi-server-gen/actions/workflows/tests.yml)
[![PHP 8.4+](https://img.shields.io/badge/php-≥8.4-8892BF.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

A code generator that transforms an OpenAPI 3.0.x specification into a fully typed PHP server implementation for **Yii2**, with automatic request/response handling, body deserialization, and middleware support.

## Key Features

- **Schema DTOs** — Generates typed data classes with [`futuretek/data-mapper`](https://github.com/futuretek-solutions-ltd/data-mapper) attributes (`#[ArrayType]`, `#[MapType]`, `#[Format]`)
- **Backed Enums** — PHP 8.4 string/int backed enums from OpenAPI enums, with `x-enum-descriptions` support
- **Controller Interfaces & Abstract Controllers** — Generated into a `contracts` namespace (not `controllers`) to avoid conflicts with the Yii2 default controller namespace where your implementations live
- **Yii2 Route File** — Ready-to-use URL rules configuration
- **Discriminator Mapping** — Polymorphic body deserialization via `oneOf`/`anyOf` with discriminator
- **Pluggable Middleware** — Authentication, authorization, logging, and file handling with sensible defaults
- **Namespace = Directory** — Files are placed according to their namespace, following Yii2 conventions

## Requirements

- PHP ≥ 8.4
- Composer

## Installation

```bash
composer require futuretek/php-openapi-server-gen --dev
```

## Quick Start

### 1. Generate code from your OpenAPI spec

```bash
# Run from your Yii2 project root (@app)
vendor/bin/openapi-gen generate path/to/openapi.json
```

This uses defaults: `--base-dir=.`, `--namespace=app\api`, route file at `config/routes.api.php`.

### 2. With custom namespaces

```bash
vendor/bin/openapi-gen generate api.yaml \
  --base-dir='.' \
  --namespace='app\modules\api' \
  --schema-ns='schemas' \
  --enum-ns='enums' \
  --controller-ns='contracts' \
  --route-file='config/routes.api.php'
```

### 3. Implement the generated interfaces

```php
<?php

namespace app\modules\api\controllers;

use app\modules\api\contracts\AbstractPetController;
use app\modules\api\contracts\PetControllerInterface;

class PetController extends AbstractPetController implements PetControllerInterface
{
    public function actionListPets(?int $limit = 20, ?PetStatus $status = null): PetListResponse
    {
        // Your business logic here
        $response = new PetListResponse();
        $response->items = Pet::find()->limit($limit)->all();
        $response->total = Pet::find()->count();
        return $response;
    }

    public function actionCreatePet(CreatePetRequest $body): Pet
    {
        // Body is already deserialized and typed
        $pet = new Pet();
        $pet->name = $body->name;
        $pet->save();
        return $pet;
    }
}
```

### 4. Include the generated routes

```php
// config/web.php
'urlManager' => [
    'enablePrettyUrl' => true,
    'showScriptName' => false,
    'rules' => require __DIR__ . '/routes.api.php',
],
```

## Directory Layout

Generated files follow the **Yii2 namespace = directory** convention. The `--base-dir` points to your application root (`@app`), and namespaces are converted to directory paths by stripping the first segment (e.g. `app`).

```
# With --namespace='app\modules\api' --base-dir='.'

./
├── config/
│   └── routes.api.php              # Yii2 URL rules
└── modules/
    └── api/
        ├── enums/
        │   └── PetStatus.php        # app\modules\api\enums\PetStatus
        ├── schemas/
        │   ├── Pet.php              # app\modules\api\schemas\Pet
        │   ├── CreatePetRequest.php
        │   └── PetListResponse.php
        └── contracts/
            ├── PetControllerInterface.php    # Interface you implement
            └── AbstractPetController.php     # Base class (extends AbstractApiController)
```

The first namespace segment (`app`) maps to `--base-dir`. Remaining segments become subdirectories:

| Namespace | Directory (relative to base-dir) |
|---|---|
| `app\api\schemas` | `api/schemas/` |
| `app\modules\api\schemas` | `modules/api/schemas/` |
| `app\modules\v2\api\schemas` | `modules/v2/api/schemas/` |

## How It Works

### Request Flow

1. Yii2 routes the request to your controller action
2. `AbstractApiController::bindActionParams()` intercepts parameter binding:
   - Deserializes the request body (JSON / multipart) into the typed DTO
   - Extracts path, query, header, and cookie parameters with type casting
   - Resolves enum parameters via `::tryFrom()`
   - Handles discriminator mapping for polymorphic bodies
3. Your action receives fully typed parameters and returns a DTO
4. `AbstractApiController::afterAction()` serializes the response to JSON

### Body Handling

- **JSON** — Parsed from raw body, mapped to DTO via DataMapper
- **Multipart** — Form fields + file uploads, with `UploadedFileInterface` (PSR-7) for files
- **Discriminator** — Reads the discriminator property, resolves the concrete subclass, then deserializes

### Security

Security schemes are captured in `operationMeta`. The abstract controller calls `AuthenticationInterface` and `AuthorizationInterface` middleware in `beforeAction()`. Default implementations pass through — override the factory methods to plug in your auth:

```php
class PetController extends AbstractPetController implements PetControllerInterface
{
    protected function createAuthentication(): AuthenticationInterface
    {
        return new BearerTokenAuthentication();
    }
}
```

## CLI Options

```
vendor/bin/openapi-gen generate <spec> [options]

Arguments:
  spec                    Path to the OpenAPI specification file (JSON or YAML)

Options:
  --base-dir=DIR          Application base directory (@app root) [default: "."]
  --namespace=NS          Root namespace for generated code [default: "app\api"]
  --schema-ns=NS          Sub-namespace for schemas (DTOs) [default: "schemas"]
  --enum-ns=NS            Sub-namespace for enums [default: "enums"]
  --controller-ns=NS      Sub-namespace for controllers [default: "controllers"]
  --route-file=PATH       Route file path relative to base-dir [default: "config/routes.api.php"]
```

## Vendor Extensions

| Extension | Level | Description |
|---|---|---|
| `x-controller` | path / operation | Override the controller name |
| `x-ns` | path / operation | Override the controller namespace |
| `x-enum` | inline enum schema | Name override for inline enums (warns if missing) |
| `x-enum-descriptions` | enum schema | Array of per-value descriptions, aligned with enum values |

### Example

```yaml
paths:
  /pets:
    x-controller: Pet
    x-ns: app\modules\pet\controllers
    get:
      operationId: listPets
      parameters:
        - name: status
          in: query
          schema:
            type: string
            enum: [available, pending, sold]
            x-enum: PetStatus
            x-enum-descriptions:
              - Pet is available for adoption
              - Pet adoption is pending
              - Pet has been sold
```

## Schema Generation Details

### Type Mapping

| OpenAPI Type | PHP Type |
|---|---|
| `string` | `string` |
| `integer` | `int` |
| `number` | `float` |
| `boolean` | `bool` |
| `string` + `format: date` | `DateTimeInterface` |
| `string` + `format: date-time` | `DateTimeInterface` |
| `string` + `format: binary` | `UploadedFileInterface` |
| `array` + `items` | `array` with `#[ArrayType]` + `@var T[]` PHPDoc |
| `object` + `additionalProperties` | `object` with `#[MapType]` |
| `enum` | PHP backed enum |
| `allOf` | Class inheritance (`extends`) |
| `oneOf` / `anyOf` | Union types (`A\|B`) |

### PHPDoc Annotations

Array and map properties are annotated with `@var` for IDE autocompletion:

```php
/**
 * @var Pet[]|null
 */
#[ArrayType(Pet::class)]
public ?array $items = null;

/**
 * @var array<string, Setting>
 */
#[MapType(valueType: Setting::class)]
public array $settings;
```

## Controller Interface Conventions

- **Body first** — Request body is always the first parameter
- **Then path params** — Required path parameters
- **Then query/header/cookie** — Required first, then optional with defaults
- **Return type** — Success response DTO, `array` for array responses, `void` for no-content
- **Hyphenated names** — Converted to camelCase (`X-Request-Id` → `$xRequestId`)

## Discriminator Support

Both explicit and auto-derived discriminator mappings are supported:

```yaml
# Explicit mapping
Notification:
  oneOf:
    - $ref: '#/components/schemas/Email'
    - $ref: '#/components/schemas/Sms'
  discriminator:
    propertyName: channel
    mapping:
      email: '#/components/schemas/Email'
      sms: '#/components/schemas/Sms'

# Auto-derived (uses lcfirst schema name as discriminator value)
Notification:
  oneOf:
    - $ref: '#/components/schemas/Email'
    - $ref: '#/components/schemas/Sms'
  discriminator:
    propertyName: type
```

At runtime, the abstract controller reads the discriminator property from the raw JSON and deserializes into the correct concrete class.

## Development

### Running Tests

```bash
composer install
vendor/bin/pest
```

### Project Structure

```
src/
├── AbstractApiController.php       # Yii2 base controller (runtime)
├── Config.php                      # Generator configuration
├── Generator.php                   # Main orchestrator
├── GeneratorResult.php             # Warnings/errors/generated files collector
├── Command/
│   └── GenerateCommand.php         # Symfony Console command
├── Generator/
│   ├── SchemaGenerator.php         # DTO class generation
│   ├── EnumGenerator.php           # Backed enum generation
│   ├── ControllerInterfaceGenerator.php
│   ├── AbstractControllerGenerator.php
│   └── Yii2RouteGenerator.php
├── Middleware/
│   ├── AuthenticationInterface.php
│   ├── AuthorizationInterface.php
│   ├── LoggerInterface.php
│   ├── FileHandlerInterface.php
│   └── Default*.php                # Default pass-through implementations
└── Parser/
    ├── OpenApiParser.php           # OpenAPI 3.0.x spec parser
    ├── ParsedSchema.php
    ├── ParsedProperty.php
    ├── ParsedEnum.php
    ├── ParsedOperation.php
    └── ParsedParameter.php
```

## License

MIT License. See [LICENSE](LICENSE) for details.

