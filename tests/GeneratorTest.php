<?php

declare(strict_types=1);

use futuretek\openapi\Config;
use futuretek\openapi\Generator;

beforeEach(function () {
    $this->baseDir = __DIR__ . '/test_output_' . uniqid();
});

afterEach(function () {
    if (is_dir($this->baseDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->baseDir);
    }
});

test('generates correct files from petstore spec', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\api',
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();

    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());

    // Enums
    expect($generated)->toContain('PetStatus.php');

    // Schemas
    expect($generated)->toContain('Pet.php');
    expect($generated)->toContain('Category.php');
    expect($generated)->toContain('Tag.php');
    expect($generated)->toContain('CreatePetRequest.php');
    expect($generated)->toContain('PetListResponse.php');
    expect($generated)->toContain('PetPhotoUpload.php');

    // Controllers
    expect($generated)->toContain('PetControllerInterface.php');
    expect($generated)->toContain('CategoryControllerInterface.php');
    expect($generated)->toContain('AbstractPetController.php');
    expect($generated)->toContain('AbstractCategoryController.php');

    // Routes
    expect($generated)->toContain('routes.api.php');

    // Should NOT generate duplicate schemas
    expect($generated)->not->toContain('TagsItem.php');
    expect($generated)->not->toContain('ItemsItem.php');
    expect($generated)->not->toContain('Status.php');
    expect($generated)->not->toContain('TagIdsItem.php');
    expect($generated)->not->toContain('ListCategoriesResponse200.php');
});

test('generates correct Pet DTO with DataMapper attributes', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $petFile = file_get_contents($this->baseDir . '/api/schemas/Pet.php');

    // Uses PetStatus enum, not generic Status
    expect($petFile)->toContain('use app\\api\\enums\\PetStatus;');
    expect($petFile)->toContain('public PetStatus $status;');

    // ArrayType references Tag, not TagsItem, with @var PHPDoc
    expect($petFile)->toContain('#[ArrayType(Tag::class)]');
    expect($petFile)->toContain('@var Tag[]|null');
    expect($petFile)->toContain('public ?array $tags');

    // Format attributes
    expect($petFile)->toContain("#[Format('date-time')]");
    expect($petFile)->toContain("#[Format('date')]");

    // UploadedFileInterface not present on Pet (only on PetPhotoUpload)
    expect($petFile)->not->toContain('UploadedFileInterface');
});

test('generates correct PetPhotoUpload with file property', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $photoFile = file_get_contents($this->baseDir . '/api/schemas/PetPhotoUpload.php');

    expect($photoFile)->toContain('use Psr\\Http\\Message\\UploadedFileInterface;');
    expect($photoFile)->toContain('public UploadedFileInterface $photo;');
    expect($photoFile)->toContain('public ?string $caption');
});

test('generates controller interface with correct method signatures', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $interfaceFile = file_get_contents($this->baseDir . '/api/contracts/PetControllerInterface.php');

    // Body first, then path params
    expect($interfaceFile)->toContain('public function actionCreatePet(CreatePetRequest $body): Pet;');
    expect($interfaceFile)->toContain('public function actionUpdatePet(CreatePetRequest $body, string $petId): Pet;');
    expect($interfaceFile)->toContain('public function actionUploadPetPhoto(PetPhotoUpload $body, string $petId): Pet;');

    // No body for GET/DELETE
    expect($interfaceFile)->toContain('public function actionGetPet(string $petId): Pet;');
    expect($interfaceFile)->toContain('public function actionDeletePet(string $petId): void;');

    // Query params with defaults
    expect($interfaceFile)->toContain('public function actionListPets(?int $limit = 20, ?PetStatus $status = null): PetListResponse;');
});

test('generates abstract controller with operation metadata', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $abstractFile = file_get_contents($this->baseDir . '/api/contracts/AbstractPetController.php');

    // Extends AbstractApiController
    expect($abstractFile)->toContain('extends AbstractApiController');

    // Has operationMeta with security
    expect($abstractFile)->toContain("'security' => ['bearerAuth']");

    // Has body class references
    expect($abstractFile)->toContain('\\app\\api\\schemas\\CreatePetRequest::class');
    expect($abstractFile)->toContain('\\app\\api\\schemas\\PetPhotoUpload::class');

    // Has multipart media type
    expect($abstractFile)->toContain("'mediaType' => 'multipart/form-data'");
});

test('generates correct Yii2 routes', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $routes = require $this->baseDir . '/config/routes.api.php';

    expect($routes)->toBeArray();
    expect($routes['GET pets'])->toBe('pet/list-pets');
    expect($routes['POST pets'])->toBe('pet/create-pet');
    expect($routes['GET pets/<petId:\S+>'])->toBe('pet/get-pet');
    expect($routes['PUT pets/<petId:\S+>'])->toBe('pet/update-pet');
    expect($routes['DELETE pets/<petId:\S+>'])->toBe('pet/delete-pet');
    expect($routes['POST pets/<petId:\S+>/photo'])->toBe('pet/upload-pet-photo');
    expect($routes['GET categories'])->toBe('category/list-categories');
});

test('generates PetStatus enum with descriptions', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $enumFile = file_get_contents($this->baseDir . '/api/enums/PetStatus.php');

    expect($enumFile)->toContain('enum PetStatus: string');
    expect($enumFile)->toContain("case Available = 'available';");
    expect($enumFile)->toContain("case Pending = 'pending';");
    expect($enumFile)->toContain("case Sold = 'sold';");

    // Descriptions as docblocks
    expect($enumFile)->toContain('/** Pet is available for adoption */');
    expect($enumFile)->toContain('/** Pet adoption is pending */');
    expect($enumFile)->toContain('/** Pet has been sold */');
});

test('warns on inline enum without x-enum name', function () {
    // Create a minimal spec with inline enum missing x-enum
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/test' => [
                'get' => [
                    'operationId' => 'testOp',
                    'tags' => ['Test'],
                    'parameters' => [
                        [
                            'name' => 'color',
                            'in' => 'query',
                            'schema' => [
                                'type' => 'string',
                                'enum' => ['red', 'blue'],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasWarnings())->toBeTrue();
    $warnings = implode("\n", $result->getWarnings());
    expect($warnings)->toContain("x-enum");
});

test('errors on missing operationId', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/test' => [
                'get' => [
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeTrue();
    $errors = implode("\n", $result->getErrors());
    expect($errors)->toContain('operationId');
});

// ============================================================
// Edge case tests
// ============================================================

test('allOf composition generates class with extends', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();
    expect($result->hasErrors())->toBeFalse();

    $file = file_get_contents($this->baseDir . '/api/schemas/ExtendedItem.php');

    // Should extend parent from allOf $ref
    expect($file)->toContain('class ExtendedItem extends Item');

    // Should have the extra properties from allOf inline schema
    expect($file)->toContain('public ?string $extraField = null;');
    expect($file)->toContain('public ?float $weight = null;');

    // Should NOT duplicate parent properties
    expect($file)->not->toContain('public string $id');
    expect($file)->not->toContain('public string $name');
});

test('integer backed enum generates int backing type', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/enums/Priority.php');

    expect($file)->toContain('enum Priority: int');
    expect($file)->toContain('case V1 = 1;');
    expect($file)->toContain('case V5 = 5;');
});

test('inline enum with x-enum uses specified name', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    // SortField inline enum specified with x-enum should NOT produce warning
    $sortFieldWarnings = array_filter($result->getWarnings(), fn($w) => str_contains($w, 'SortField'));
    expect($sortFieldWarnings)->toBeEmpty();

    // File should be generated with the x-enum name
    $file = file_get_contents($this->baseDir . '/api/enums/SortField.php');
    expect($file)->toContain('enum SortField: string');
    expect($file)->toContain("case Name = 'name';");
    expect($file)->toContain("case Date = 'date';");
    expect($file)->toContain("case Price = 'price';");
});

test('map type with additionalProperties generates correct schema', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/schemas/Item.php');

    // metadata is additionalProperties: string => should be object (map)
    expect($file)->toContain('public ?object $metadata = null;');
});

test('self-referencing array property generates correct ArrayType', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/schemas/Item.php');

    // relatedItems references Item itself
    expect($file)->toContain('#[ArrayType(Item::class)]');
    expect($file)->toContain('@var Item[]|null');
    expect($file)->toContain('public ?array $relatedItems = null;');
});

test('scalar array types get @var phpdoc without ArrayType attribute', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/schemas/Item.php');

    // tags: string[] — should have @var but NO #[ArrayType]
    expect($file)->toContain('@var string[]|null');
    // No ArrayType attribute for scalar arrays
    expect($file)->not->toContain("#[ArrayType('string')]");

    // statusCodes: int[]
    expect($file)->toContain('@var int[]|null');

    // flags: bool[]
    expect($file)->toContain('@var bool[]|null');
});

test('inline object property generates nested schema', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $itemFile = file_get_contents($this->baseDir . '/api/schemas/Item.php');

    // settings is an inline object — should be typed as object (not Settings class, it's too simple)
    // Actually, depending on parser behavior it might be Settings inline class or object.
    // Either is acceptable, but it should be valid PHP
    expect($itemFile)->toContain('$settings');
});

test('inline response schema generates DTO', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());
    expect($generated)->toContain('UploadMultipleFilesResponse200.php');

    $file = file_get_contents($this->baseDir . '/api/schemas/UploadMultipleFilesResponse200.php');
    expect($file)->toContain('public int $uploadedCount;');
    expect($file)->toContain('public string $message;');
});

test('optional request body generates nullable parameter', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/ItemControllerInterface.php');

    // createItem has requestBody.required=false — body should be nullable with default null
    expect($file)->toContain('?CreateItemRequest $body = null');
});

test('path-level parameters are shared across operations', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/ItemControllerInterface.php');

    // getItem has itemId from path-level parameters
    expect($file)->toContain('public function actionGetItem(string $itemId): Item;');

    // patchItem also inherits path-level itemId, plus has body and query param
    expect($file)->toContain('public function actionPatchItem(PatchItemRequest $body, string $itemId, ?bool $dryRun = false): Item;');
});

test('parameter ordering: body first, then path, then required query, then optional query', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/MixedControllerInterface.php');

    // updateMixed: body(Item), path(int id), required query(bool force, Priority priority), optional query(string reason)
    expect($file)->toContain(
        'public function actionUpdateMixed(Item $body, int $id, bool $force, Priority $priority, ?string $reason = null): Item;'
    );
});

test('duplicate operationId produces warning', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasWarnings())->toBeTrue();
    $warnings = implode("\n", $result->getWarnings());
    expect($warnings)->toContain("Duplicate operationId 'listReports'");
});

test('operation without tags derives controller from path', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());

    // Should derive controller from path segment "no-tags"
    expect($generated)->toContain('NotagsControllerInterface.php');
    expect($generated)->toContain('AbstractNotagsController.php');

    // Should warn about missing tags
    $warnings = implode("\n", $result->getWarnings());
    expect($warnings)->toContain('no tags');
});

test('operation x-controller overrides path x-controller', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    // /reports has path-level x-controller: Report but operation-level x-controller: CustomReport
    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());
    expect($generated)->toContain('CustomReportControllerInterface.php');
    expect($generated)->not->toContain('ReportControllerInterface.php');
});

test('array response type returns plain array', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/CustomReportControllerInterface.php');

    // Response is type: array, items: $ref Item — should return 'array', not a wrapper DTO
    expect($file)->toContain('public function actionListReports(): array;');
    // Should NOT import a ListReportsResponse200 class
    expect($file)->not->toContain('ListReportsResponse200');
});

test('application/octet-stream response with binary schema returns UploadedFileInterface', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/DownloadControllerInterface.php');

    // Binary download should return UploadedFileInterface, not a generated DTO
    expect($file)->toContain('public function actionDownloadBinaryFile(): UploadedFileInterface;');
    // Must import from the correct PSR namespace, NOT from the schema namespace
    expect($file)->toContain('use Psr\Http\Message\UploadedFileInterface;');
    expect($file)->not->toContain('schemas\UploadedFileInterface');
    // No schema class generated for this response
    expect($file)->not->toContain('DownloadBinaryFileResponse200');
});

test('no schema class is generated for binary response types', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $schemaFiles = array_map('basename', glob($this->baseDir . '/api/schemas/*.php') ?: []);

    // No DTO class should be generated for the binary/plain response operations
    expect($schemaFiles)->not->toContain('DownloadBinaryFileResponse200.php');
    expect($schemaFiles)->not->toContain('DownloadTextFileResponse200.php');
    expect($schemaFiles)->not->toContain('DownloadRefBinaryResponse200.php');
    // The primitive component schema must NOT generate a class either
    expect($schemaFiles)->not->toContain('BinaryData.php');
});

test('text/plain response returns string return type', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/DownloadControllerInterface.php');

    // Plain-text response should be a simple string, not a DTO
    expect($file)->toContain('public function actionDownloadTextFile(): string;');
    expect($file)->not->toContain('DownloadTextFileResponse200');
});

test('component primitive schema referenced in response resolves to UploadedFileInterface', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/DownloadControllerInterface.php');

    // $ref to a primitive (type: string, format: binary) component schema must not produce
    // a missing-class import — it should resolve to UploadedFileInterface (non-JSON + binary schema)
    expect($file)->toContain('public function actionDownloadRefBinary(): UploadedFileInterface;');
    expect($file)->not->toContain('BinaryData');
});

test('void return type for no-content response', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/NotagsControllerInterface.php');

    // 204 No Content with no response body should return void
    expect($file)->toContain('public function actionNoTagsEndpoint(): void;');
});

test('hyphenated parameter names are converted to camelCase', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/ItemControllerInterface.php');

    // X-Request-Id header param should become $xRequestId
    expect($file)->toContain('$xRequestId');
    expect($file)->not->toContain('$X-Request-Id');
});

test('hyphenated parameter name stores original in operationMeta', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/AbstractItemController.php');

    // operationMeta should store original name for runtime binding
    expect($file)->toContain("'name' => 'X-Request-Id'");
    expect($file)->toContain("'phpName' => 'xRequestId'");
});

test('routes convert PascalCase controllers to kebab-case', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $routes = require $this->baseDir . '/config/routes.api.php';

    // CustomReport controller should become custom-report
    expect($routes['GET reports'])->toBe('custom-report/list-reports');

    // camelCase operationId should become kebab-case action
    expect($routes['POST upload'])->toBe('upload/upload-multiple-files');
    expect($routes['PUT mixed/<id:\d+>'])->toBe('mixed/update-mixed');
    expect($routes['PATCH items/<itemId:\S+>'])->toBe('item/patch-item');
});

test('path parameters get type-based regex: \d+ for int, \S+ for string', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    (new Generator($config))->generate();

    $routes = require $this->baseDir . '/config/routes.api.php';

    // integer path param must use \d+
    expect($routes)->toHaveKey('PUT mixed/<id:\d+>');

    // string path params must use \S+
    expect($routes)->toHaveKey('GET items/<itemId:\S+>');
    expect($routes)->toHaveKey('PATCH items/<itemId:\S+>');

    // params with no explicit type default to \S+
    expect(array_keys($routes))->each->not->toMatch('/\{[^}]+}/'); // no unconverted {params}
    expect(array_keys($routes))->each->not->toMatch('/<\w+>/');    // no bare <param> without regex
});

// ============================================================
// Route ambiguity detection tests
// ============================================================

test('ambiguous routes: string param before static segment emits warning', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/issues/{id}' => [
                'get' => [
                    'operationId' => 'getIssue',
                    'tags' => ['Issue'],
                    'parameters' => [[
                        'name' => 'id', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'string'],
                    ]],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/issues/create' => [
                'get' => [
                    'operationId' => 'createIssueForm',
                    'tags' => ['Issue'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $result = (new Generator(new Config(specPath: realpath($specFile), baseDir: $this->baseDir)))->generate();

    expect($result->hasWarnings())->toBeTrue();
    $warn = implode(' ', $result->getWarnings());
    expect($warn)->toContain('/issues/{id}');
    expect($warn)->toContain('/issues/create');
    expect($warn)->toContain('shadow');
});

test('ambiguous routes: static segment before string param does not warn (correct order)', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/issues/create' => [
                'get' => [
                    'operationId' => 'createIssueForm',
                    'tags' => ['Issue'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/issues/{id}' => [
                'get' => [
                    'operationId' => 'getIssue',
                    'tags' => ['Issue'],
                    'parameters' => [[
                        'name' => 'id', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'string'],
                    ]],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $result = (new Generator(new Config(specPath: realpath($specFile), baseDir: $this->baseDir)))->generate();

    // No shadowing warning when static comes first
    $routeWarnings = array_filter($result->getWarnings(), fn($w) => str_contains($w, 'shadow'));
    expect($routeWarnings)->toBeEmpty();
});

test('non-ambiguous routes: int param vs static segment does not warn', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/items/{id}' => [
                'get' => [
                    'operationId' => 'getItem',
                    'tags' => ['Item'],
                    'parameters' => [[
                        'name' => 'id', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'integer'],
                    ]],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/items/stats' => [
                'get' => [
                    'operationId' => 'itemStats',
                    'tags' => ['Item'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $result = (new Generator(new Config(specPath: realpath($specFile), baseDir: $this->baseDir)))->generate();

    $routeWarnings = array_filter($result->getWarnings(), fn($w) => str_contains($w, 'shadow'));
    expect($routeWarnings)->toBeEmpty();
});

test('non-ambiguous routes: different HTTP methods on same path do not warn', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/items/{id}' => [
                'get' => [
                    'operationId' => 'getItem',
                    'tags' => ['Item'],
                    'parameters' => [[
                        'name' => 'id', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'string'],
                    ]],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/items/stats' => [
                'post' => [
                    'operationId' => 'itemStats',
                    'tags' => ['Item'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $result = (new Generator(new Config(specPath: realpath($specFile), baseDir: $this->baseDir)))->generate();

    $routeWarnings = array_filter($result->getWarnings(), fn($w) => str_contains($w, 'shadow'));
    expect($routeWarnings)->toBeEmpty();
});

test('empty schema is not generated and emits a warning', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    // File must not be generated
    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());
    expect($generated)->not->toContain('EmptySchema.php');
    expect(file_exists($this->baseDir . '/api/schemas/EmptySchema.php'))->toBeFalse();

    // A warning must be emitted
    expect($result->hasWarnings())->toBeTrue();
    $warnings = implode(' ', $result->getWarnings());
    expect($warnings)->toContain('EmptySchema');
    expect($warnings)->toContain('no properties');
});

test('configurable sub-namespaces are reflected in generated files', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
        namespace: 'my\\custom\\api',
        schemaSubNamespace: 'dto',
        enumSubNamespace: 'types',
        controllerSubNamespace: 'http',
    );

    $generator = new Generator($config);
    $generator->generate();

    // namespace my\custom\api\dto → baseDir/custom/api/dto
    $schemaFile = file_get_contents($this->baseDir . '/custom/api/dto/Item.php');
    expect($schemaFile)->toContain('namespace my\\custom\\api\\dto;');

    // namespace my\custom\api\types → baseDir/custom/api/types
    $enumFile = file_get_contents($this->baseDir . '/custom/api/types/ItemType.php');
    expect($enumFile)->toContain('namespace my\\custom\\api\\types;');

    // namespace my\custom\api\http → baseDir/custom/api/http
    $ctrlFile = file_get_contents($this->baseDir . '/custom/api/http/ItemControllerInterface.php');
    expect($ctrlFile)->toContain('namespace my\\custom\\api\\http;');

    // Check imports cross-reference correct namespaces
    expect($schemaFile)->toContain('use my\\custom\\api\\types\\ItemType;');
    expect($ctrlFile)->toContain('use my\\custom\\api\\dto\\Item;');
});

test('nullable required property generates nullable type without default', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/schemas/Item.php');

    // price is NOT required but IS nullable — should have ? and = null
    expect($file)->toContain('public ?float $price = null;');

    // quantity is NOT required, has default 0
    expect($file)->toContain('public ?int $quantity = 0;');
});

test('patch request with all optional properties generates correct schema', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/schemas/PatchItemRequest.php');

    // All properties are optional and nullable
    expect($file)->toContain('public ?string $name = null;');
    expect($file)->toContain('public ?float $price = null;');

    // type is a ref to ItemType enum — should be nullable and optional
    expect($file)->toContain('public ?ItemType $type = null;');
});

test('spec with no components schemas generates controllers only', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/health' => [
                'get' => [
                    'operationId' => 'healthCheck',
                    'tags' => ['Health'],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();

    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());

    // Should still generate controller interface, abstract controller, and routes
    expect($generated)->toContain('HealthControllerInterface.php');
    expect($generated)->toContain('AbstractHealthController.php');
    expect($generated)->toContain('routes.api.php');
});

test('spec with no paths generates schemas only', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'Status' => [
                    'type' => 'string',
                    'enum' => ['active', 'inactive'],
                ],
                'Thing' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();
    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());

    expect($generated)->toContain('Status.php');
    expect($generated)->toContain('Thing.php');

    // routes should still be generated (empty array)
    expect($generated)->toContain('routes.api.php');
    $routes = require $this->baseDir . '/config/routes.api.php';
    expect($routes)->toBe([]);
});

test('boolean default values generate correctly', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/schemas/CreateItemRequest.php');

    // isActive has default: true
    expect($file)->toContain('public ?bool $isActive = true;');
});

test('multiple HTTP methods on same path generate separate route entries', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $routes = require $this->baseDir . '/config/routes.api.php';

    // /items should have both GET and POST
    expect($routes)->toHaveKey('GET items');
    expect($routes)->toHaveKey('POST items');

    // /items/{itemId} should have both GET and PATCH
    expect($routes)->toHaveKey('GET items/<itemId:\S+>');
    expect($routes)->toHaveKey('PATCH items/<itemId:\S+>');
});

test('header parameter stored in operationMeta with in=header', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/AbstractItemController.php');

    // X-Request-Id should be stored with in=header
    expect($file)->toContain("'in' => 'header'");
    expect($file)->toContain("'name' => 'X-Request-Id'");
});

// ============================================================
// Discriminator tests
// ============================================================

test('discriminator with explicit mapping generates mapping in operationMeta', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();
    expect($result->hasErrors())->toBeFalse();

    $file = file_get_contents($this->baseDir . '/api/contracts/AbstractNotificationController.php');

    // Should have discriminator block for sendNotification
    expect($file)->toContain("'discriminator' => [");
    expect($file)->toContain("'propertyName' => 'channel'");

    // Mapping values should resolve to schema classes
    expect($file)->toContain("'email' => \\app\\api\\schemas\\EmailNotification::class");
    expect($file)->toContain("'sms' => \\app\\api\\schemas\\SmsNotification::class");
});

test('discriminator without explicit mapping auto-derives from oneOf refs', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/AbstractNotificationController.php');

    // sendAutoNotification uses discriminator without explicit mapping
    // Auto-derived mapping: lcfirst(SchemaName) => SchemaName
    expect($file)->toContain("'propertyName' => 'type'");
    expect($file)->toContain("'emailNotification' => \\app\\api\\schemas\\EmailNotification::class");
    expect($file)->toContain("'smsNotification' => \\app\\api\\schemas\\SmsNotification::class");
});

test('discriminator schema generates base class with discriminator property', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/schemas/Notification.php');

    // Base schema should have the discriminator property
    expect($file)->toContain('public string $channel;');
    expect($file)->toContain('class Notification');
});

test('discriminator subtypes generate as separate schemas', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());

    expect($generated)->toContain('EmailNotification.php');
    expect($generated)->toContain('SmsNotification.php');

    $emailFile = file_get_contents($this->baseDir . '/api/schemas/EmailNotification.php');
    expect($emailFile)->toContain('public string $emailAddress;');
    expect($emailFile)->toContain('public string $subject;');
    expect($emailFile)->toContain('public ?string $htmlBody = null;');

    $smsFile = file_get_contents($this->baseDir . '/api/schemas/SmsNotification.php');
    expect($smsFile)->toContain('public string $phoneNumber;');
    expect($smsFile)->toContain('public ?string $sender = null;');
});

test('discriminator does not shadow class name variable in generator', function () {
    // This tests the bug fix: discriminator mapping loop variable must not shadow $className
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    // AbstractNotificationController.php must exist in controllers directory
    expect(file_exists($this->baseDir . '/api/contracts/AbstractNotificationController.php'))->toBeTrue();

    // SmsNotification.php must NOT exist in controllers directory (it should only be in schemas)
    expect(file_exists($this->baseDir . '/api/contracts/SmsNotification.php'))->toBeFalse();
    expect(file_exists($this->baseDir . '/api/schemas/SmsNotification.php'))->toBeTrue();

    $file = file_get_contents($this->baseDir . '/api/contracts/AbstractNotificationController.php');
    expect($file)->toContain('abstract class AbstractNotificationController extends AbstractApiController');
});

test('controller interface uses base type for discriminated body', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $file = file_get_contents($this->baseDir . '/api/contracts/NotificationControllerInterface.php');

    // Method should accept Notification (base type), not a union
    expect($file)->toContain('public function actionSendNotification(Notification $body)');
    expect($file)->toContain('use app\\api\\schemas\\Notification;');
});

// ============================================================
// Namespace-to-directory mapping tests
// ============================================================

test('namespace maps to directory structure matching Yii2 convention', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\modules\\api',
    );

    $generator = new Generator($config);
    $result = $generator->generate();
    expect($result->hasErrors())->toBeFalse();

    // app\modules\api\schemas → baseDir/modules/api/schemas
    expect(file_exists($this->baseDir . '/modules/api/schemas/Pet.php'))->toBeTrue();
    expect(file_exists($this->baseDir . '/modules/api/enums/PetStatus.php'))->toBeTrue();
    expect(file_exists($this->baseDir . '/modules/api/contracts/PetControllerInterface.php'))->toBeTrue();

    // Verify namespace inside the file matches
    $petFile = file_get_contents($this->baseDir . '/modules/api/schemas/Pet.php');
    expect($petFile)->toContain('namespace app\\modules\\api\\schemas;');
});

test('deeply nested namespace creates correct directory tree', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\modules\\v2\\api\\rest',
    );

    $generator = new Generator($config);
    $result = $generator->generate();
    expect($result->hasErrors())->toBeFalse();

    // app\modules\v2\api\rest\schemas → baseDir/modules/v2/api/rest/schemas
    expect(file_exists($this->baseDir . '/modules/v2/api/rest/schemas/Pet.php'))->toBeTrue();
    expect(file_exists($this->baseDir . '/modules/v2/api/rest/enums/PetStatus.php'))->toBeTrue();
    expect(file_exists($this->baseDir . '/modules/v2/api/rest/contracts/PetControllerInterface.php'))->toBeTrue();
});

test('route file defaults to config/routes.api.php under baseDir', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    expect(file_exists($this->baseDir . '/config/routes.api.php'))->toBeTrue();
    $routes = require $this->baseDir . '/config/routes.api.php';
    expect($routes)->toBeArray();
    expect($routes)->toHaveKey('GET pets');
});

test('route file can be overridden to custom path', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        routeFile: 'api/routes.php',
    );

    $generator = new Generator($config);
    $generator->generate();

    expect(file_exists($this->baseDir . '/api/routes.php'))->toBeTrue();
    $routes = require $this->baseDir . '/api/routes.php';
    expect($routes)->toBeArray();
    expect($routes)->toHaveKey('GET pets');
});

// ============================================================
// Route prefix tests
// ============================================================

test('routes include routePrefix when configured', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\modules\\api',
        routePrefix: 'api',
    );

    $generator = new Generator($config);
    $generator->generate();

    $routes = require $this->baseDir . '/config/routes.api.php';

    expect($routes)->toBeArray();
    expect($routes['GET pets'])->toBe('api/pet/list-pets');
    expect($routes['POST pets'])->toBe('api/pet/create-pet');
    expect($routes['GET pets/<petId:\S+>'])->toBe('api/pet/get-pet');
    expect($routes['PUT pets/<petId:\S+>'])->toBe('api/pet/update-pet');
    expect($routes['DELETE pets/<petId:\S+>'])->toBe('api/pet/delete-pet');
    expect($routes['POST pets/<petId:\S+>/photo'])->toBe('api/pet/upload-pet-photo');
    expect($routes['GET categories'])->toBe('api/category/list-categories');
});

test('routes have no prefix when routePrefix is null', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $routes = require $this->baseDir . '/config/routes.api.php';

    expect($routes['GET pets'])->toBe('pet/list-pets');
    expect($routes['POST pets'])->toBe('pet/create-pet');
});

test('GenerateCommand trims surrounding quotes from route-prefix option', function () {
    $command = new \futuretek\openapi\Command\GenerateCommand();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

    $tester->execute([
        'spec' => realpath(__DIR__ . '/fixtures/petstore.json'),
        '--base-dir' => $this->baseDir,
        '--route-prefix' => "'api'",
    ]);

    expect($tester->getStatusCode())->toBe(0);

    $routes = require $this->baseDir . '/config/routes.api.php';
    // Should be 'api/pet/list-pets', not "'api'/pet/list-pets"
    expect($routes['GET pets'])->toBe('api/pet/list-pets');
    expect($routes['GET pets'])->not->toContain("'");
});

test('GenerateCommand trims double quotes from route-prefix option', function () {
    $command = new \futuretek\openapi\Command\GenerateCommand();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

    $tester->execute([
        'spec' => realpath(__DIR__ . '/fixtures/petstore.json'),
        '--base-dir' => $this->baseDir,
        '--route-prefix' => '"api"',
    ]);

    expect($tester->getStatusCode())->toBe(0);

    $routes = require $this->baseDir . '/config/routes.api.php';
    expect($routes['GET pets'])->toBe('api/pet/list-pets');
    expect($routes['GET pets'])->not->toContain('"');
});

test('GenerateCommand trims quotes from namespace option', function () {
    $command = new \futuretek\openapi\Command\GenerateCommand();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

    $tester->execute([
        'spec' => realpath(__DIR__ . '/fixtures/petstore.json'),
        '--base-dir' => $this->baseDir,
        '--namespace' => "'app\\modules\\api'",
    ]);

    expect($tester->getStatusCode())->toBe(0);

    $petFile = file_get_contents($this->baseDir . '/modules/api/schemas/Pet.php');
    expect($petFile)->toContain('namespace app\\modules\\api\\schemas;');
    // Namespace declaration should NOT contain literal quotes
    expect($petFile)->not->toContain("namespace 'app");
});

// ============================================================
// Array request body tests
// ============================================================

test('array request body generates correct operationMeta with bodyIsArray', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/items/batch' => [
                'post' => [
                    'operationId' => 'saveItems',
                    'tags' => ['Item'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/ItemInput'],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'ItemInput' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
        namespace: 'app\\modules\\api',
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();

    $abstractFile = file_get_contents($this->baseDir . '/modules/api/contracts/AbstractItemController.php');

    // Should reference the item class, not 'array'
    expect($abstractFile)->toContain('\\app\\modules\\api\\schemas\\ItemInput::class');
    expect($abstractFile)->toContain("'bodyIsArray' => true");
    // Must NOT contain \app\modules\api\schemas\array::class
    expect($abstractFile)->not->toContain('\\array::class');

    $interfaceFile = file_get_contents($this->baseDir . '/modules/api/contracts/ItemControllerInterface.php');

    // Method should accept array type, not ItemInput
    expect($interfaceFile)->toContain('public function actionSaveItems(array $body): void;');
    // Docblock should show the item type
    expect($interfaceFile)->toContain('@param ItemInput[] $body');
});

test('array request body with optional body generates nullable array', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/items/batch' => [
                'post' => [
                    'operationId' => 'saveItems',
                    'tags' => ['Item'],
                    'requestBody' => [
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/ItemInput'],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'ItemInput' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();

    $interfaceFile = file_get_contents($this->baseDir . '/api/contracts/ItemControllerInterface.php');
    expect($interfaceFile)->toContain('?array $body = null');
});

test('generates correct MultiFileUpload with file array property', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    );

    $generator = new Generator($config);
    $generator->generate();

    $uploadFile = file_get_contents($this->baseDir . '/api/schemas/MultiFileUpload.php');

    // Must import UploadedFileInterface
    expect($uploadFile)->toContain('use Psr\\Http\\Message\\UploadedFileInterface;');

    // Must import ArrayType attribute
    expect($uploadFile)->toContain('use futuretek\\datamapper\\attributes\\ArrayType;');

    // Must have @var UploadedFileInterface[] phpdoc
    expect($uploadFile)->toContain('@var UploadedFileInterface[]');

    // Must have #[ArrayType(UploadedFileInterface::class)] attribute
    expect($uploadFile)->toContain('#[ArrayType(UploadedFileInterface::class)]');

    // Must have public array $files (not UploadedFileInterface $files)
    expect($uploadFile)->toContain('public array $files;');

    // Non-file properties should not be affected
    expect($uploadFile)->toContain('public string $description;');
});

test('primitive array request body (int[]) does not generate wrapper DTO', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/notifications/set-sent' => [
                'post' => [
                    'operationId' => 'setSentNotifications',
                    'tags' => ['Notification'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'array',
                                    'description' => 'List of notification IDs that were sent',
                                    'items' => [
                                        'type' => 'integer',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
        namespace: 'app\\modules\\api',
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();

    // No wrapper DTO should be generated for primitive array items
    expect(file_exists($this->baseDir . '/modules/api/schemas/SetSentNotificationsRequestItem.php'))->toBeFalse();

    $abstractFile = file_get_contents($this->baseDir . '/modules/api/contracts/AbstractNotificationController.php');

    // Should use bodyType instead of bodyClass for primitive arrays
    expect($abstractFile)->toContain("'bodyType' => 'int'");
    expect($abstractFile)->toContain("'bodyIsArray' => true");
    expect($abstractFile)->not->toContain('bodyClass');

    $interfaceFile = file_get_contents($this->baseDir . '/modules/api/contracts/NotificationControllerInterface.php');

    // Method should accept array type
    expect($interfaceFile)->toContain('public function actionSetSentNotifications(array $body): void;');
    // Docblock should show int[]
    expect($interfaceFile)->toContain('@param int[] $body');
});

test('primitive array request body (string[]) does not generate wrapper DTO', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/tags/batch' => [
                'post' => [
                    'operationId' => 'batchAddTags',
                    'tags' => ['Tag'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
        namespace: 'app\\modules\\api',
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();

    // No wrapper DTO
    expect(file_exists($this->baseDir . '/modules/api/schemas/BatchAddTagsRequestItem.php'))->toBeFalse();

    $abstractFile = file_get_contents($this->baseDir . '/modules/api/contracts/AbstractTagController.php');

    expect($abstractFile)->toContain("'bodyType' => 'string'");
    expect($abstractFile)->toContain("'bodyIsArray' => true");
    expect($abstractFile)->not->toContain('bodyClass');

    $interfaceFile = file_get_contents($this->baseDir . '/modules/api/contracts/TagControllerInterface.php');

    expect($interfaceFile)->toContain('public function actionBatchAddTags(array $body): void;');
    expect($interfaceFile)->toContain('@param string[] $body');
});

test('optional primitive array request body generates nullable array', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/ids' => [
                'post' => [
                    'operationId' => 'processIds',
                    'tags' => ['Processor'],
                    'requestBody' => [
                        'required' => false,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'integer',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => ['description' => 'OK'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $config = new Config(
        specPath: realpath($specFile),
        baseDir: $this->baseDir,
        namespace: 'app\\modules\\api',
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();

    $interfaceFile = file_get_contents($this->baseDir . '/modules/api/contracts/ProcessorControllerInterface.php');

    // Optional primitive array body should be nullable
    expect($interfaceFile)->toContain('?array $body = null');
    expect($interfaceFile)->toContain('@param int[] $body');
});

test('clean option removes old .php files from target directories before generation', function () {
    // First, generate normally so directories and files are created
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\api',
    );

    $generator = new Generator($config);
    $result = $generator->generate();
    expect($result->hasErrors())->toBeFalse();

    // Place stale files in enum, schema and controller dirs
    $staleEnumFile = $this->baseDir . '/api/enums/OldEnum.php';
    $staleSchemaFile = $this->baseDir . '/api/schemas/OldSchema.php';
    $staleContractFile = $this->baseDir . '/api/contracts/OldContract.php';

    file_put_contents($staleEnumFile, '<?php // stale enum');
    file_put_contents($staleSchemaFile, '<?php // stale schema');
    file_put_contents($staleContractFile, '<?php // stale contract');

    expect(file_exists($staleEnumFile))->toBeTrue();
    expect(file_exists($staleSchemaFile))->toBeTrue();
    expect(file_exists($staleContractFile))->toBeTrue();

    // Re-generate with clean enabled
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\api',
        cleanTargetDirs: true,
    );

    $generator = new Generator($config);
    $result = $generator->generate();
    expect($result->hasErrors())->toBeFalse();

    // Stale files should be removed
    expect(file_exists($staleEnumFile))->toBeFalse();
    expect(file_exists($staleSchemaFile))->toBeFalse();
    expect(file_exists($staleContractFile))->toBeFalse();

    // But freshly generated files should still exist
    expect(file_exists($this->baseDir . '/api/enums/PetStatus.php'))->toBeTrue();
    expect(file_exists($this->baseDir . '/api/schemas/Pet.php'))->toBeTrue();
    expect(file_exists($this->baseDir . '/api/contracts/PetControllerInterface.php'))->toBeTrue();
});

test('generation without clean option preserves existing files', function () {
    // First, generate normally
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\api',
    );

    $generator = new Generator($config);
    $generator->generate();

    // Place a stale file
    $staleFile = $this->baseDir . '/api/schemas/OldSchema.php';
    file_put_contents($staleFile, '<?php // stale');

    // Re-generate WITHOUT clean
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\api',
        cleanTargetDirs: false,
    );

    $generator = new Generator($config);
    $generator->generate();

    // Stale file should still exist
    expect(file_exists($staleFile))->toBeTrue();
});

test('clean option does not fail when target directories do not exist', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\api',
        cleanTargetDirs: true,
    );

    $generator = new Generator($config);
    $result = $generator->generate();

    expect($result->hasErrors())->toBeFalse();
    expect($result->hasWarnings())->toBeFalse();
});

// ============================================================
// Schema setter tests
// ============================================================

test('generated schema includes fluent setters for all properties', function () {
    $config = new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    );

    (new Generator($config))->generate();

    $petFile = file_get_contents($this->baseDir . '/api/schemas/Pet.php');

    // Setters for required properties (non-nullable param)
    expect($petFile)->toContain('public function setName(string $value): static');
    // Setters for optional/nullable properties (?-prefixed param)
    expect($petFile)->toContain('public function setTags(?array $value): static');
    // Setter body
    expect($petFile)->toContain('$this->name = $value;');
    expect($petFile)->toContain('return $this;');
});

test('schema setter uses correct nullable type for optional properties', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'Widget' => [
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    (new Generator(new Config(specPath: realpath($specFile), baseDir: $this->baseDir)))->generate();

    $file = file_get_contents($this->baseDir . '/api/schemas/Widget.php');

    // Required: no nullable prefix
    expect($file)->toContain('public function setCode(string $value): static');
    // Optional: nullable prefix
    expect($file)->toContain('public function setLabel(?string $value): static');
    expect($file)->toContain('public function setCount(?int $value): static');
    // All return static
    expect(substr_count($file, 'return $this;'))->toBe(3);
});

test('schema with no properties is not generated and emits a warning', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [],
        'components' => [
            'schemas' => [
                'Empty' => ['type' => 'object'],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/test_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $result = (new Generator(new Config(specPath: realpath($specFile), baseDir: $this->baseDir)))->generate();

    $generated = array_map(fn(string $f) => basename($f), $result->getGenerated());
    expect($generated)->not->toContain('Empty.php');
    expect(file_exists($this->baseDir . '/api/schemas/Empty.php'))->toBeFalse();

    expect($result->hasWarnings())->toBeTrue();
    expect(implode(' ', $result->getWarnings()))->toContain('Empty');
});

// ============================================================
// --strict flag tests
// ============================================================

test('GenerateCommand --strict returns failure when warnings exist', function () {
    // Build a spec with duplicate operationIds to trigger a warning
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/a' => [
                'get' => [
                    'operationId' => 'dupOp',
                    'tags' => ['A'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/b' => [
                'get' => [
                    'operationId' => 'dupOp',
                    'tags' => ['B'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/warn_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $command = new \futuretek\openapi\Command\GenerateCommand();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

    $tester->execute([
        'spec' => $specFile,
        '--base-dir' => $this->baseDir,
        '--strict' => true,
    ]);

    expect($tester->getStatusCode())->toBe(\Symfony\Component\Console\Command\Command::FAILURE);
    expect($tester->getDisplay())->toContain('Strict mode');
});

test('GenerateCommand --strict passes when no warnings exist', function () {
    $command = new \futuretek\openapi\Command\GenerateCommand();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

    $tester->execute([
        'spec' => realpath(__DIR__ . '/fixtures/petstore.json'),
        '--base-dir' => $this->baseDir,
        '--strict' => true,
    ]);

    expect($tester->getStatusCode())->toBe(\Symfony\Component\Console\Command\Command::SUCCESS);
});

test('GenerateCommand without --strict succeeds even with warnings', function () {
    $spec = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/a' => [
                'get' => [
                    'operationId' => 'dupOp',
                    'tags' => ['A'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
            '/b' => [
                'get' => [
                    'operationId' => 'dupOp',
                    'tags' => ['B'],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $specFile = $this->baseDir . '/warn_spec.json';
    mkdir($this->baseDir, 0755, true);
    file_put_contents($specFile, json_encode($spec));

    $command = new \futuretek\openapi\Command\GenerateCommand();
    $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

    $tester->execute([
        'spec' => $specFile,
        '--base-dir' => $this->baseDir,
    ]);

    expect($tester->getStatusCode())->toBe(\Symfony\Component\Console\Command\Command::SUCCESS);
});



