<?php

declare(strict_types=1);

/**
 * Yii2 Integration Tests
 *
 * These tests generate code from OpenAPI specs, dynamically load the generated
 * classes, bootstrap a Yii2 web application, and verify the full pipeline.
 */

use futuretek\openapi\Config;
use futuretek\openapi\Generator;
use yii\web\Application;

// --- Helpers ---

function intApp(array $routes = []): Application
{
    if (\Yii::$app !== null) {
        \Yii::$app = null;
    }
    return new Application([
        'id' => 'test-app',
        'basePath' => sys_get_temp_dir(),
        'components' => [
            'request' => [
                'class' => \yii\web\Request::class,
                'enableCookieValidation' => false,
                'enableCsrfCookie' => false,
                'scriptUrl' => '',
                'parsers' => ['application/json' => \yii\web\JsonParser::class],
            ],
            'response' => ['class' => \yii\web\Response::class],
            'urlManager' => [
                'enablePrettyUrl' => true,
                'showScriptName' => false,
                'baseUrl' => '',
                'rules' => $routes,
            ],
        ],
    ]);
}

function intAutoload(string $baseDir): Closure
{
    $loader = function (string $class) use ($baseDir) {
        if (!str_starts_with($class, 'app\\')) {
            return;
        }
        $file = $baseDir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    };
    spl_autoload_register($loader);
    return $loader;
}

function intLoad(string $baseDir, string $nsPath, array $subDirs = ['enums', 'schemas']): void
{
    foreach ($subDirs as $sub) {
        $dir = $baseDir . '/' . $nsPath . '/' . $sub . '/';
        if (is_dir($dir)) {
            foreach (glob($dir . '*.php') as $file) {
                require_once $file;
            }
        }
    }
}

function intGen(string $specPath, string $baseDir, string $namespace, ?string $routePrefix = null): \futuretek\openapi\GeneratorResult
{
    return (new Generator(new Config(specPath: $specPath, baseDir: $baseDir, namespace: $namespace, routePrefix: $routePrefix)))->generate();
}

/**
 * Configure a Yii2 request with method, JSON body, query params, and headers.
 */
function intRequest(Application $app, string $method, ?string $jsonBody = null, array $query = [], array $headers = []): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['CONTENT_TYPE'] = 'application/json';

    // Inject headers via $_SERVER superglobal (Yii2 reads HTTP_ prefixed keys)
    foreach ($headers as $name => $value) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $_SERVER[$key] = $value;
    }

    $request = $app->request;

    if ($jsonBody !== null) {
        $request->setRawBody($jsonBody);
        $request->setBodyParams(null); // Force re-parse
    } else {
        $request->setRawBody('');
    }

    $request->setQueryParams($query);

    // Reset headers cache so Yii2 re-reads from $_SERVER
    $headersRef = new ReflectionProperty($request, '_headers');
    $headersRef->setAccessible(true);
    $headersRef->setValue($request, null);
}

/**
 * Configure a Yii2 request for multipart/form-data with file uploads.
 *
 * @param Application $app
 * @param array $files Simulated $_FILES entries, e.g. ['photo' => ['tmp_name' => '...', 'name' => '...', 'type' => '...', 'size' => 123, 'error' => 0]]
 * @param array $postData Simulated $_POST body params
 * @param array $query Query params
 */
function intFileRequest(Application $app, array $files = [], array $postData = [], array $query = []): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';

    $_FILES = $files;
    $_POST = $postData;

    // Reset Yii2 UploadedFile static cache
    \yii\web\UploadedFile::reset();

    $request = $app->request;
    $request->setRawBody('');
    $request->setBodyParams($postData);
    $request->setQueryParams($query);

    // Reset headers cache
    $headersRef = new ReflectionProperty($request, '_headers');
    $headersRef->setAccessible(true);
    $headersRef->setValue($request, null);
}

/**
 * Create a concrete PetController class via eval, returning its name.
 * The controller actions return real data instead of empty DTOs.
 */
function intMakePetController(string $ns): string
{
    $cls = 'RealPetCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'

        // Override authentication to pass-through for testing
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new class implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["testUser" => true]; } }; }'

        // listPets: returns a PetListResponse with filtered list
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse {'
        . '   $resp = new \\' . $ns . '\\schemas\\PetListResponse();'
        . '   $resp->total = $limit ?? 20;'
        . '   $resp->items = [];'
        . '   return $resp;'
        . ' }'

        // createPet: echoes back the submitted body data as a Pet
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = "new-123";'
        . '   $pet->name = $body->name;'
        . '   $pet->status = $body->status;'
        . '   return $pet;'
        . ' }'

        // getPet: returns a Pet with the given petId
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->name = "Pet-" . $petId;'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   return $pet;'
        . ' }'

        // updatePet: returns updated pet
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->name = $body->name;'
        . '   $pet->status = $body->status;'
        . '   return $pet;'
        . ' }'

        . ' public function actionDeletePet(string $petId): void {}'

        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->name = "photo-uploaded";'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   return $pet;'
        . ' }'

        . '}'
    );
    return $cls;
}

// --- Setup / Teardown ---

beforeEach(function () {
    $this->baseDir = __DIR__ . '/int_out_' . uniqid('', true);
    $this->nsSuffix = 't' . str_replace('.', '', uniqid('', true));
    $this->ns = 'app\\' . $this->nsSuffix;
    $this->nsPath = $this->nsSuffix;
    $this->autoloader = null;
});

afterEach(function () {
    if ($this->autoloader !== null) {
        spl_autoload_unregister($this->autoloader);
    }
    if (\Yii::$app !== null) {
        \Yii::$app = null;
    }
    // Restore $_SERVER state
    unset($_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE']);
    foreach (array_keys($_SERVER) as $key) {
        if (str_starts_with($key, 'HTTP_') && $key !== 'HTTP_HOST') {
            unset($_SERVER[$key]);
        }
    }
    // Clean up file upload state
    $_FILES = [];
    $_POST = [];
    \yii\web\UploadedFile::reset();
    if (is_dir($this->baseDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($this->baseDir);
    }
});

// --- Generated code validity ---

test('[yii2] generated classes are loadable', function () {
    $result = intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    expect($result->hasErrors())->toBeFalse();
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ns = $this->ns;
    expect(class_exists("{$ns}\\schemas\\Pet"))->toBeTrue();
    expect(class_exists("{$ns}\\schemas\\CreatePetRequest"))->toBeTrue();
    expect(class_exists("{$ns}\\schemas\\PetListResponse"))->toBeTrue();
    expect(class_exists("{$ns}\\schemas\\PetPhotoUpload"))->toBeTrue();
    expect(class_exists("{$ns}\\schemas\\Category"))->toBeTrue();
    expect(class_exists("{$ns}\\schemas\\Tag"))->toBeTrue();
    expect(enum_exists("{$ns}\\enums\\PetStatus"))->toBeTrue();
    expect(interface_exists("{$ns}\\contracts\\PetControllerInterface"))->toBeTrue();
    expect(class_exists("{$ns}\\contracts\\AbstractPetController"))->toBeTrue();
    // Note: CategoryController uses x-ns override in fixture, so it has a different namespace
});

test('[yii2] abstract controller extends AbstractApiController', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractPetController");
    expect($ref->isAbstract())->toBeTrue();
    expect($ref->isSubclassOf(\futuretek\openapi\AbstractApiController::class))->toBeTrue();
});

// --- DataMapper round-trip ---

test('[yii2] Pet DTO round-trips through DataMapper', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath, ['enums', 'schemas']);
    $pet = \futuretek\datamapper\DataMapper::toObject(
        ['id' => 'pet-123', 'name' => 'Buddy', 'status' => 'available'],
        "{$this->ns}\\schemas\\Pet",
    );
    expect($pet->id)->toBe('pet-123');
    expect($pet->name)->toBe('Buddy');
    expect($pet->status->value)->toBe('available');
    $arr = \futuretek\datamapper\DataMapper::toArray($pet);
    expect($arr['id'])->toBe('pet-123');
    expect($arr['status'])->toBe('available');
});

test('[yii2] nested objects round-trip through DataMapper', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath, ['enums', 'schemas']);
    $pet = \futuretek\datamapper\DataMapper::toObject([
        'id' => 'pet-456', 'name' => 'Max', 'status' => 'pending',
        'category' => ['id' => 1, 'name' => 'Dogs'],
        'tags' => [['id' => 10, 'name' => 'friendly'], ['id' => 20, 'name' => 'trained']],
    ], "{$this->ns}\\schemas\\Pet");
    expect($pet->category->name)->toBe('Dogs');
    expect($pet->tags)->toHaveCount(2);
    expect($pet->tags[0]->name)->toBe('friendly');
});

test('[yii2] enum tryFrom works', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath, ['enums']);
    $enumClass = "{$this->ns}\\enums\\PetStatus";
    expect(array_map(fn($c) => $c->value, $enumClass::cases()))->toBe(['available', 'pending', 'sold']);
    expect($enumClass::tryFrom('available'))->not->toBeNull();
    expect($enumClass::tryFrom('invalid'))->toBeNull();
});

// --- Route file integration ---

test('[yii2] generated routes have correct METHOD path format', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    )))->generate();
    $routes = require $this->baseDir . '/config/routes.api.php';
    expect($routes)->toBeArray()->not->toBeEmpty();
    foreach ($routes as $key => $value) {
        expect($key)->toMatch('/^(GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD|TRACE) /');
        expect($value)->toMatch('/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/');
    }
});

test('[yii2] routes with routePrefix have prefix in targets', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        routePrefix: 'api',
    )))->generate();
    $routes = require $this->baseDir . '/config/routes.api.php';
    foreach ($routes as $value) {
        expect($value)->toStartWith('api/');
    }
});

// --- Yii2 URL manager ---

test('[yii2] urlManager creates correct URLs from generated routes', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
    )))->generate();
    $routes = require $this->baseDir . '/config/routes.api.php';
    $app = intApp($routes);
    expect($app->urlManager->createUrl(['pet/list-pets']))->toBe('/pets');
    expect($app->urlManager->createUrl(['pet/get-pet', 'petId' => 'abc-123']))->toBe('/pets/abc-123');
    expect($app->urlManager->createUrl(['pet/upload-pet-photo', 'petId' => 'xyz']))->toBe('/pets/xyz/photo');
    expect($app->urlManager->createUrl(['category/list-categories']))->toBe('/categories');
});

test('[yii2] urlManager with routePrefix', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        routePrefix: 'api',
    )))->generate();
    $routes = require $this->baseDir . '/config/routes.api.php';
    $app = intApp($routes);
    expect($app->urlManager->createUrl(['api/pet/list-pets']))->toBe('/pets');
    expect($app->urlManager->createUrl(['api/pet/get-pet', 'petId' => 'abc-123']))->toBe('/pets/abc-123');
    expect($app->urlManager->createUrl(['api/category/list-categories']))->toBe('/categories');
});

test('[yii2] urlManager multiple HTTP methods on same path', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
    )))->generate();
    $routes = require $this->baseDir . '/config/routes.api.php';
    expect($routes)->toHaveKey('GET items');
    expect($routes)->toHaveKey('POST items');
    expect($routes['GET items'])->not->toBe($routes['POST items']);
});

test('[yii2] urlManager path params with routePrefix', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir,
        routePrefix: 'v1',
    )))->generate();
    $routes = require $this->baseDir . '/config/routes.api.php';
    $app = intApp($routes);
    expect($routes['GET items'])->toBe('v1/item/list-items');
    expect($routes['PUT mixed/<id>'])->toBe('v1/mixed/update-mixed');
    expect($app->urlManager->createUrl(['v1/item/list-items']))->toBe('/items');
    expect($app->urlManager->createUrl(['v1/mixed/update-mixed', 'id' => '99']))->toBe('/mixed/99');
});

// --- AbstractApiController: operationMeta ---

test('[yii2] operationMeta has correct body class, mediaType and security', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractPetController");
    $meta = $ref->getDefaultProperties()['operationMeta'];
    expect($meta['createPet']['bodyClass'])->toBe("{$this->ns}\\schemas\\CreatePetRequest");
    expect($meta['createPet']['bodyRequired'])->toBeTrue();
    expect($meta['createPet']['mediaType'])->toBe('application/json');
    expect($meta['createPet']['security'])->toBe(['bearerAuth']);
    expect($meta['uploadPetPhoto']['mediaType'])->toBe('multipart/form-data');
    expect($meta['listPets'])->not->toHaveKey('bodyClass');
    $paramNames = array_column($meta['listPets']['params'], 'name');
    expect($paramNames)->toContain('limit');
    expect($paramNames)->toContain('status');
});

// --- AbstractApiController: parameter casting ---

test('[yii2] castParameterValue handles scalar types', function () {
    $app = intApp();
    $controller = new class('test', $app) extends \futuretek\openapi\AbstractApiController {
        protected array $operationMeta = [];
        public function testCast(mixed $value, string $type, ?string $enumClass = null): mixed {
            $method = new ReflectionMethod(\futuretek\openapi\AbstractApiController::class, 'castParameterValue');
            $method->setAccessible(true);
            return $method->invoke($this, $value, $type, $enumClass);
        }
    };
    expect($controller->testCast('42', 'int'))->toBe(42);
    expect($controller->testCast('3.14', 'float'))->toBe(3.14);
    expect($controller->testCast('true', 'bool'))->toBe(true);
    expect($controller->testCast('false', 'bool'))->toBe(false);
    expect($controller->testCast(123, 'string'))->toBe('123');
    expect($controller->testCast(null, 'int'))->toBeNull();
});

test('[yii2] castParameterValue resolves enum via tryFrom', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath, ['enums']);
    $app = intApp();
    $enumClass = "{$this->ns}\\enums\\PetStatus";
    $controller = new class('test', $app) extends \futuretek\openapi\AbstractApiController {
        protected array $operationMeta = [];
        public function testCast(mixed $value, string $type, ?string $enumClass = null): mixed {
            $method = new ReflectionMethod(\futuretek\openapi\AbstractApiController::class, 'castParameterValue');
            $method->setAccessible(true);
            return $method->invoke($this, $value, $type, $enumClass);
        }
    };
    $val = $controller->testCast('available', 'string', $enumClass);
    expect($val)->not->toBeNull();
    expect($val->value)->toBe('available');
    expect($controller->testCast('nonexistent', 'string', $enumClass))->toBeNull();
});

// --- AbstractApiController: operationId resolution ---

test('[yii2] resolveOperationId maps kebab-case to camelCase', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ns = $this->ns;
    $ctrlClass = 'IntPetCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlClass
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );
    $app = intApp();
    $controller = new $ctrlClass('pet', $app);
    $method = new ReflectionMethod($controller, 'resolveOperationId');
    $method->setAccessible(true);
    expect($method->invoke($controller, 'list-pets'))->toBe('listPets');
    expect($method->invoke($controller, 'create-pet'))->toBe('createPet');
    expect($method->invoke($controller, 'upload-pet-photo'))->toBe('uploadPetPhoto');
    expect($method->invoke($controller, 'non-existent'))->toBeNull();
});

// --- Edge cases ---

test('[yii2] allOf generates inheriting class mappable by DataMapper', function () {
    intGen(realpath(__DIR__ . '/fixtures/edge_cases.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath, ['enums', 'schemas']);
    $ref = new ReflectionClass("{$this->ns}\\schemas\\ExtendedItem");
    expect($ref->getParentClass()->getName())->toBe("{$this->ns}\\schemas\\Item");
    $obj = \futuretek\datamapper\DataMapper::toObject(
        ['id' => 'ext-1', 'name' => 'Extended', 'type' => 'physical', 'extraField' => 'extra-value', 'weight' => 1.5],
        "{$this->ns}\\schemas\\ExtendedItem",
    );
    expect($obj->id)->toBe('ext-1');
    expect($obj->extraField)->toBe('extra-value');
    expect($obj->weight)->toBe(1.5);
});

test('[yii2] discriminator generates polymorphic mapping in operationMeta', function () {
    intGen(realpath(__DIR__ . '/fixtures/edge_cases.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractNotificationController");
    $meta = $ref->getDefaultProperties()['operationMeta'];
    expect($meta['sendNotification']['discriminator']['propertyName'])->toBe('channel');
    expect($meta['sendNotification']['discriminator']['mapping'])->toHaveKey('email');
    expect($meta['sendNotification']['discriminator']['mapping'])->toHaveKey('sms');
    $emailClass = $meta['sendNotification']['discriminator']['mapping']['email'];
    expect(class_exists($emailClass))->toBeTrue();
    $emailObj = \futuretek\datamapper\DataMapper::toObject(
        ['emailAddress' => 'test@example.com', 'subject' => 'Test'],
        $emailClass,
    );
    expect($emailObj->emailAddress)->toBe('test@example.com');
});

// --- Array request body bug fix ---

test('[yii2] array request body generates bodyIsArray with correct item class', function () {
    $spec = json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/items/batch' => [
                'post' => [
                    'operationId' => 'batchCreateItems',
                    'tags' => ['Batch'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/BatchItem'],
                                ],
                            ],
                        ],
                    ],
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'BatchItem' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'value' => ['type' => 'integer'],
                    ],
                ],
            ],
        ],
    ]);
    mkdir($this->baseDir, 0755, true);
    file_put_contents($this->baseDir . '/spec.json', $spec);
    intGen(realpath($this->baseDir . '/spec.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractBatchController");
    $meta = $ref->getDefaultProperties()['operationMeta'];
    expect($meta['batchCreateItems']['bodyClass'])->toBe("{$this->ns}\\schemas\\BatchItem");
    expect($meta['batchCreateItems']['bodyIsArray'])->toBeTrue();
    expect($meta['batchCreateItems']['bodyRequired'])->toBeTrue();
    $interfaceFile = file_get_contents(
        $this->baseDir . '/' . $this->nsPath . '/contracts/BatchControllerInterface.php',
    );
    expect($interfaceFile)->toContain('array $body');
    expect($interfaceFile)->not->toContain('\\array');
});

// --- Primitive array request body ---

test('[yii2] primitive array request body (int[]) generates bodyType meta without wrapper DTO', function () {
    $spec = json_encode([
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
                                    'items' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ]);
    mkdir($this->baseDir, 0755, true);
    file_put_contents($this->baseDir . '/spec.json', $spec);
    intGen(realpath($this->baseDir . '/spec.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractNotificationController");
    $meta = $ref->getDefaultProperties()['operationMeta'];

    // Should use bodyType instead of bodyClass for primitive arrays
    expect($meta['setSentNotifications'])->toHaveKey('bodyType');
    expect($meta['setSentNotifications']['bodyType'])->toBe('int');
    expect($meta['setSentNotifications'])->not->toHaveKey('bodyClass');
    expect($meta['setSentNotifications']['bodyIsArray'])->toBeTrue();
    expect($meta['setSentNotifications']['bodyRequired'])->toBeTrue();

    // No wrapper DTO should exist
    expect(file_exists($this->baseDir . '/' . $this->nsPath . '/schemas/SetSentNotificationsRequestItem.php'))->toBeFalse();

    $interfaceFile = file_get_contents(
        $this->baseDir . '/' . $this->nsPath . '/contracts/NotificationControllerInterface.php',
    );
    expect($interfaceFile)->toContain('array $body');
    expect($interfaceFile)->toContain('@param int[] $body');
});

test('[yii2] primitive array request body (string[]) generates bodyType meta', function () {
    $spec = json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/tags' => [
                'post' => [
                    'operationId' => 'addTags',
                    'tags' => ['Tag'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ]);
    mkdir($this->baseDir, 0755, true);
    file_put_contents($this->baseDir . '/spec.json', $spec);
    intGen(realpath($this->baseDir . '/spec.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractTagController");
    $meta = $ref->getDefaultProperties()['operationMeta'];

    expect($meta['addTags']['bodyType'])->toBe('string');
    expect($meta['addTags'])->not->toHaveKey('bodyClass');
    expect($meta['addTags']['bodyIsArray'])->toBeTrue();

    $interfaceFile = file_get_contents(
        $this->baseDir . '/' . $this->nsPath . '/contracts/TagControllerInterface.php',
    );
    expect($interfaceFile)->toContain('@param string[] $body');
});

// --- Module namespace ---

test('[yii2] module namespace generates correct directory structure', function () {
    $moduleNs = 'app\\modules\\api' . str_replace('.', '', uniqid('', true));
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: $moduleNs,
    )))->generate();
    $nsParts = explode('\\', $moduleNs);
    array_shift($nsParts);
    $nsPath = implode('/', $nsParts);
    expect(is_dir($this->baseDir . '/' . $nsPath . '/schemas'))->toBeTrue();
    expect(is_dir($this->baseDir . '/' . $nsPath . '/contracts'))->toBeTrue();
    $petFile = file_get_contents($this->baseDir . '/' . $nsPath . '/schemas/Pet.php');
    expect($petFile)->toContain("namespace {$moduleNs}\\schemas;");
    $ctrlFile = file_get_contents($this->baseDir . '/' . $nsPath . '/contracts/AbstractPetController.php');
    expect($ctrlFile)->toContain("namespace {$moduleNs}\\contracts;");
});

// --- Route prefix bug fix ---

test('[yii2] routePrefix produces correct module-style routes end-to-end', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: 'app\\modules\\api',
        routePrefix: 'api',
    )))->generate();
    $routes = require $this->baseDir . '/config/routes.api.php';
    expect($routes['GET pets'])->toBe('api/pet/list-pets');
    expect($routes['POST pets'])->toBe('api/pet/create-pet');
    expect($routes['GET pets/<petId>'])->toBe('api/pet/get-pet');
    expect($routes['DELETE pets/<petId>'])->toBe('api/pet/delete-pet');
    expect($routes['GET categories'])->toBe('api/category/list-categories');
    $app = intApp($routes);
    expect($app->urlManager->createUrl(['api/pet/list-pets']))->toBe('/pets');
    expect($app->urlManager->createUrl(['api/pet/get-pet', 'petId' => '123']))->toBe('/pets/123');
    expect($app->urlManager->createUrl(['api/category/list-categories']))->toBe('/categories');
});

// --- Full pipeline ---

test('[yii2] both fixtures produce valid Yii2 apps', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir . '/ps',
    )))->generate();
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/edge_cases.json'),
        baseDir: $this->baseDir . '/ec',
    )))->generate();
    $r1 = require $this->baseDir . '/ps/config/routes.api.php';
    $r2 = require $this->baseDir . '/ec/config/routes.api.php';
    expect($r1)->toBeArray()->not->toBeEmpty();
    expect($r2)->toBeArray()->not->toBeEmpty();
    expect(intApp($r1))->toBeInstanceOf(Application::class);
    expect(intApp($r2))->toBeInstanceOf(Application::class);
});

test('[yii2] concrete controller implementing interface is instantiable', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);
    $ns = $this->ns;
    $ctrlClass = 'IntPetCtrl2_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlClass
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );
    $app = intApp();
    $controller = new $ctrlClass('pet', $app);
    expect($controller)->toBeInstanceOf(\futuretek\openapi\AbstractApiController::class);
    $ref = new ReflectionClass($controller);
    expect($ref->implementsInterface("{$this->ns}\\contracts\\PetControllerInterface"))->toBeTrue();
});

// ============================================================
// Request / Response integration
// ============================================================

test('[yii2] JSON body is deserialized into typed DTO and passed to action', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ctrlClass = intMakePetController($this->ns);
    $app = intApp();
    intRequest($app, 'POST', json_encode(['name' => 'Buddy', 'status' => 'available']));

    $controller = new $ctrlClass('pet', $app);
    $result = $controller->runAction('create-pet', []);

    // afterAction serializes the DTO to a JSON string
    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['id'])->toBe('new-123');
    expect($result['name'])->toBe('Buddy');
    expect($result['status'])->toBe('available');
});

test('[yii2] path parameter is passed to action via runAction params', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ctrlClass = intMakePetController($this->ns);
    $app = intApp();
    intRequest($app, 'GET');

    $controller = new $ctrlClass('pet', $app);
    $result = $controller->runAction('get-pet', ['petId' => 'abc-999']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['id'])->toBe('abc-999');
    expect($result['name'])->toBe('Pet-abc-999');
    expect($result['status'])->toBe('available');
});

test('[yii2] query parameters are bound and type-cast', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ctrlClass = intMakePetController($this->ns);
    $app = intApp();
    intRequest($app, 'GET', null, ['limit' => '5']);

    $controller = new $ctrlClass('pet', $app);
    $result = $controller->runAction('list-pets', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['total'])->toBe(5); // limit was cast from string '5' to int 5
});

test('[yii2] enum query parameter is resolved via tryFrom', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    // Create a controller that exposes the status parameter
    $ns = $this->ns;
    $cls = 'EnumQryCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse {'
        . '   $resp = new \\' . $ns . '\\schemas\\PetListResponse();'
        . '   $resp->total = $limit ?? 0;'
        . '   $resp->items = [];'
        . '   if ($status !== null) {'
        . '     $resp->total = match($status) {'
        . '       \\' . $ns . '\\enums\\PetStatus::Available => 100,'
        . '       \\' . $ns . '\\enums\\PetStatus::Pending => 200,'
        . '       \\' . $ns . '\\enums\\PetStatus::Sold => 300,'
        . '     };'
        . '   }'
        . '   return $resp;'
        . ' }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'GET', null, ['status' => 'pending']);

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('list-pets', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['total'])->toBe(200); // pending → 200
});

test('[yii2] body + path params are bound together on update', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ctrlClass = intMakePetController($this->ns);
    $app = intApp();
    intRequest($app, 'PUT', json_encode(['name' => 'Updated', 'status' => 'sold']));

    $controller = new $ctrlClass('pet', $app);
    $result = $controller->runAction('update-pet', ['petId' => 'pet-42']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['id'])->toBe('pet-42');
    expect($result['name'])->toBe('Updated');
    expect($result['status'])->toBe('sold');
});

test('[yii2] response DTO is serialized to JSON array by afterAction', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ctrlClass = intMakePetController($this->ns);
    $app = intApp();
    intRequest($app, 'GET');

    $controller = new $ctrlClass('pet', $app);
    $result = $controller->runAction('get-pet', ['petId' => 'x1']);

    // afterAction should have set response format to JSON
    expect($app->response->format)->toBe(\yii\web\Response::FORMAT_JSON);
    // Result should be a JSON-encoded string (serialized by DataMapper + Json::encode)
    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect(array_key_exists('id', $result))->toBeTrue();
    expect(array_key_exists('name', $result))->toBeTrue();
    expect(array_key_exists('status', $result))->toBeTrue();
});

test('[yii2] void action does not set JSON response format', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ctrlClass = intMakePetController($this->ns);
    $app = intApp();
    intRequest($app, 'DELETE');

    $controller = new $ctrlClass('pet', $app);
    $result = $controller->runAction('delete-pet', ['petId' => 'del-1']);

    // void action returns null — afterAction should NOT set format to JSON
    expect($result)->toBeNull();
});

test('[yii2] authentication failure returns 401', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    // Create auth that always throws
    $authCls = 'FailAuth_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authCls . ' implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { throw new \\RuntimeException("Invalid token"); } }');

    $ctrlCls = 'AuthFailCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlCls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new \\' . $authCls . '(); }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'POST', json_encode(['name' => 'Buddy', 'status' => 'available']));

    $controller = new $ctrlCls('pet', $app);
    // createPet requires bearerAuth security
    $result = $controller->runAction('create-pet', []);

    expect($app->response->statusCode)->toBe(401);
    expect($app->response->data['error'])->toBe('Unauthorized');
    expect($app->response->data['message'])->toBe('Invalid token');
});

test('[yii2] authorization failure returns 403', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    // Auth passes but authz rejects
    $authCls = 'PassAuth_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authCls . ' implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["userId" => 1]; } }');
    $authzCls = 'DenyAuthz_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authzCls . ' implements \\futuretek\\openapi\\Middleware\\AuthorizationInterface { public function authorize(string $operationId, mixed $identity, string $controller): bool { return false; } }');

    $ctrlCls = 'AuthzFailCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlCls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new \\' . $authCls . '(); }'
        . ' protected function createAuthorization(): \\futuretek\\openapi\\Middleware\\AuthorizationInterface { return new \\' . $authzCls . '(); }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'DELETE');

    $controller = new $ctrlCls('pet', $app);
    // deletePet requires bearerAuth
    $result = $controller->runAction('delete-pet', ['petId' => 'x']);

    expect($app->response->statusCode)->toBe(403);
    expect($app->response->data['error'])->toBe('Forbidden');
});

test('[yii2] successful auth allows action through', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    $authCls = 'OkAuth_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authCls . ' implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["userId" => 42]; } }');

    $ctrlCls = 'AuthOkCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlCls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new \\' . $authCls . '(); }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet(); $pet->id = "auth-ok"; $pet->name = $body->name; $pet->status = $body->status; return $pet;'
        . ' }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'POST', json_encode(['name' => 'Authenticated', 'status' => 'available']));

    $controller = new $ctrlCls('pet', $app);
    $result = $controller->runAction('create-pet', []);

    // Auth succeeded, action ran
    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['id'])->toBe('auth-ok');
    expect($result['name'])->toBe('Authenticated');
});

test('[yii2] no-security action skips auth entirely', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    // Auth that would always fail — but listPets has no security requirement
    $authCls = 'BoomAuth_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authCls . ' implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { throw new \\RuntimeException("Should not be called"); } }');

    $ctrlCls = 'NoSecCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlCls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new \\' . $authCls . '(); }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse {'
        . '   $r = new \\' . $ns . '\\schemas\\PetListResponse(); $r->total = 42; $r->items = []; return $r;'
        . ' }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'GET');

    $controller = new $ctrlCls('pet', $app);
    // listPets has no security — auth should not be called
    $result = $controller->runAction('list-pets', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['total'])->toBe(42);
});

test('[yii2] header parameter is extracted from request', function () {
    intGen(realpath(__DIR__ . '/fixtures/edge_cases.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    // The edge_cases listItems has X-Request-Id header param
    // Create a controller that exposes the header value
    $cls = 'HeaderCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractItemController'
        . ' implements \\' . $ns . '\\contracts\\ItemControllerInterface {'
        . ' public function actionListItems(?int $page = 1, ?int $perPage = 25, ?\\' . $ns . '\\enums\\SortField $sort = null, ?string $xRequestId = null): \\' . $ns . '\\schemas\\PaginatedItems {'
        . '   $r = new \\' . $ns . '\\schemas\\PaginatedItems();'
        . '   $r->total = $xRequestId !== null ? strlen($xRequestId) : -1;'
        . '   $r->items = [];'
        . '   return $r;'
        . ' }'
        . ' public function actionCreateItem(?\\' . $ns . '\\schemas\\CreateItemRequest $body = null): \\' . $ns . '\\schemas\\Item { $i = new \\' . $ns . '\\schemas\\Item(); $i->id = "c1"; $i->name = "x"; $i->type = "digital"; return $i; }'
        . ' public function actionGetItem(string $itemId): \\' . $ns . '\\schemas\\Item { $i = new \\' . $ns . '\\schemas\\Item(); $i->id = $itemId; $i->name = "x"; $i->type = "digital"; return $i; }'
        . ' public function actionPatchItem(\\' . $ns . '\\schemas\\PatchItemRequest $body, string $itemId, ?bool $dryRun = false): \\' . $ns . '\\schemas\\Item { $i = new \\' . $ns . '\\schemas\\Item(); $i->id = $itemId; $i->name = "x"; $i->type = "digital"; return $i; }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'GET', null, [], ['X-Request-Id' => 'req-abc-12345']);

    $controller = new $cls('item', $app);
    $result = $controller->runAction('list-items', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['total'])->toBe(strlen('req-abc-12345'));
});

test('[yii2] default query parameter value is used when not provided', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ctrlClass = intMakePetController($this->ns);
    $app = intApp();
    // No query params → limit should default to 20
    intRequest($app, 'GET');

    $controller = new $ctrlClass('pet', $app);
    $result = $controller->runAction('list-pets', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['total'])->toBe(20); // default limit
});

test('[yii2] nested DTO in response is fully serialized', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    // Controller that returns a Pet with category and tags
    $cls = 'NestedCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId; $pet->name = "Rex"; $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   $cat = new \\' . $ns . '\\schemas\\Category(); $cat->id = 5; $cat->name = "Dogs";'
        . '   $pet->category = $cat;'
        . '   $t1 = new \\' . $ns . '\\schemas\\Tag(); $t1->id = 1; $t1->name = "friendly";'
        . '   $t2 = new \\' . $ns . '\\schemas\\Tag(); $t2->id = 2; $t2->name = "trained";'
        . '   $pet->tags = [$t1, $t2];'
        . '   return $pet;'
        . ' }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'GET');

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('get-pet', ['petId' => 'nested-1']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['id'])->toBe('nested-1');
    expect($result['category'])->toBeArray();
    expect($result['category']['id'])->toBe(5);
    expect($result['category']['name'])->toBe('Dogs');
    expect($result['tags'])->toBeArray();
    expect($result['tags'])->toHaveCount(2);
    expect($result['tags'][0]['name'])->toBe('friendly');
    expect($result['tags'][1]['name'])->toBe('trained');
});

test('[yii2] list response with DTO items is fully serialized', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    $cls = 'ListCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse {'
        . '   $resp = new \\' . $ns . '\\schemas\\PetListResponse();'
        . '   $p1 = new \\' . $ns . '\\schemas\\Pet(); $p1->id = "1"; $p1->name = "A"; $p1->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   $p2 = new \\' . $ns . '\\schemas\\Pet(); $p2->id = "2"; $p2->name = "B"; $p2->status = \\' . $ns . '\\enums\\PetStatus::Sold;'
        . '   $resp->items = [$p1, $p2];'
        . '   $resp->total = 2;'
        . '   return $resp;'
        . ' }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'GET');

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('list-pets', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['total'])->toBe(2);
    expect($result['items'])->toBeArray()->toHaveCount(2);
    expect($result['items'][0]['id'])->toBe('1');
    expect($result['items'][0]['status'])->toBe('available');
    expect($result['items'][1]['id'])->toBe('2');
    expect($result['items'][1]['status'])->toBe('sold');
});

test('[yii2] runAction via app routes full end-to-end', function () {
    (new Generator(new Config(
        specPath: realpath(__DIR__ . '/fixtures/petstore.json'),
        baseDir: $this->baseDir,
        namespace: $this->ns,
    )))->generate();
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    $ctrlClass = intMakePetController($ns);

    $routes = require $this->baseDir . '/config/routes.api.php';
    $app = intApp($routes);
    // Register the controller in the app's controllerMap
    $app->controllerMap['pet'] = $ctrlClass;

    intRequest($app, 'POST', json_encode(['name' => 'E2E-Pet', 'status' => 'pending']));

    $result = $app->runAction('pet/create-pet', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['id'])->toBe('new-123');
    expect($result['name'])->toBe('E2E-Pet');
    expect($result['status'])->toBe('pending');
});

// ============================================================
// File upload integration
// ============================================================

test('[yii2] single file upload via multipart/form-data converts to UploadedFileInterface', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    // Create a temp file to simulate an upload
    $tmpFile = tempnam(sys_get_temp_dir(), 'yii2_upload_test_');
    file_put_contents($tmpFile, 'fake photo content');

    // Controller that inspects the received file
    $cls = 'FileUploadCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new class implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["testUser" => true]; } }; }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        // Verify the file property is an UploadedFileInterface
        . '   if ($body->photo instanceof \\Psr\\Http\\Message\\UploadedFileInterface) {'
        . '     $pet->name = "file:" . $body->photo->getClientFilename() . ":" . $body->photo->getSize();'
        . '   } else {'
        . '     $pet->name = "no-file";'
        . '   }'
        . '   return $pet;'
        . ' }'
        . '}'
    );

    $app = intApp();
    intFileRequest($app, [
        'photo' => [
            'tmp_name' => $tmpFile,
            'name' => 'cat.jpg',
            'type' => 'image/jpeg',
            'size' => 18,
            'error' => UPLOAD_ERR_OK,
        ],
    ], ['caption' => 'My cat']);

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('upload-pet-photo', ['petId' => 'pet-photo-1']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['id'])->toBe('pet-photo-1');
    expect($result['name'])->toBe('file:cat.jpg:18');

    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

test('[yii2] file upload with UPLOAD_ERR_NO_FILE is skipped', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    // Controller that checks if photo is set
    $cls = 'NoFileCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new class implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["testUser" => true]; } }; }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   $ref = new \\ReflectionProperty($body, "photo");'
        . '   $pet->name = $ref->isInitialized($body) ? "photo-set" : "photo-not-set";'
        . '   return $pet;'
        . ' }'
        . '}'
    );

    $app = intApp();
    intFileRequest($app, [
        'photo' => [
            'tmp_name' => '',
            'name' => '',
            'type' => '',
            'size' => 0,
            'error' => UPLOAD_ERR_NO_FILE,
        ],
    ]);

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('upload-pet-photo', ['petId' => 'pet-nofile']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['name'])->toBe('photo-not-set');
});

test('[yii2] mixed form data and file fields in multipart request', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    $tmpFile = tempnam(sys_get_temp_dir(), 'yii2_mixed_test_');
    file_put_contents($tmpFile, 'mixed file content');

    // Controller that checks both photo file and caption text field
    $cls = 'MixedCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new class implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["testUser" => true]; } }; }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   $hasFile = $body->photo instanceof \\Psr\\Http\\Message\\UploadedFileInterface;'
        . '   $captionRef = new \\ReflectionProperty($body, "caption");'
        . '   $caption = $captionRef->isInitialized($body) ? ($body->caption ?? "null") : "unset";'
        . '   $pet->name = ($hasFile ? "file-ok" : "no-file") . "|caption:" . $caption;'
        . '   return $pet;'
        . ' }'
        . '}'
    );

    $app = intApp();
    intFileRequest($app, [
        'photo' => [
            'tmp_name' => $tmpFile,
            'name' => 'mixed.png',
            'type' => 'image/png',
            'size' => 18,
            'error' => UPLOAD_ERR_OK,
        ],
    ], ['caption' => 'A nice caption']);

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('upload-pet-photo', ['petId' => 'pet-mixed']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['name'])->toBe('file-ok|caption:A nice caption');

    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

test('[yii2] single file upload getStream returns correct content', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    $tmpFile = tempnam(sys_get_temp_dir(), 'yii2_stream_test_');
    file_put_contents($tmpFile, 'stream verification content');

    // Controller that reads the file stream and returns its content in the response
    $cls = 'StreamCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new class implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["testUser" => true]; } }; }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   $stream = $body->photo->getStream();'
        . '   $pet->name = (string) $stream;'
        . '   $stream->close();'
        . '   return $pet;'
        . ' }'
        . '}'
    );

    $app = intApp();
    intFileRequest($app, [
        'photo' => [
            'tmp_name' => $tmpFile,
            'name' => 'stream.bin',
            'type' => 'application/octet-stream',
            'size' => 27,
            'error' => UPLOAD_ERR_OK,
        ],
    ]);

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('upload-pet-photo', ['petId' => 'pet-stream']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['name'])->toBe('stream verification content');

    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

test('[yii2] array of files upload via multipart/form-data converts to UploadedFileInterface[]', function () {
    intGen(realpath(__DIR__ . '/fixtures/edge_cases.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    // Create temp files to simulate multiple uploads
    $tmpFile1 = tempnam(sys_get_temp_dir(), 'yii2_multi_test_1_');
    file_put_contents($tmpFile1, 'file one content');
    $tmpFile2 = tempnam(sys_get_temp_dir(), 'yii2_multi_test_2_');
    file_put_contents($tmpFile2, 'file two content here');
    $tmpFile3 = tempnam(sys_get_temp_dir(), 'yii2_multi_test_3_');
    file_put_contents($tmpFile3, 'third');

    // Create a controller for uploadMultipleFiles
    $cls = 'MultiFileCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractUploadController'
        . ' implements \\' . $ns . '\\contracts\\UploadControllerInterface {'
        . ' public function actionUploadMultipleFiles(\\' . $ns . '\\schemas\\MultiFileUpload $body): \\' . $ns . '\\schemas\\UploadMultipleFilesResponse200 {'
        . '   $resp = new \\' . $ns . '\\schemas\\UploadMultipleFilesResponse200();'
        . '   $names = [];'
        . '   foreach ($body->files as $f) {'
        . '     if ($f instanceof \\Psr\\Http\\Message\\UploadedFileInterface) {'
        . '       $names[] = $f->getClientFilename() . ":" . $f->getSize();'
        . '     }'
        . '   }'
        . '   $resp->uploadedCount = count($names);'
        . '   $resp->message = implode(",", $names);'
        . '   return $resp;'
        . ' }'
        . '}'
    );

    $app = intApp();
    // Yii2 multi-file $_FILES format
    intFileRequest($app, [
        'files' => [
            'tmp_name' => [$tmpFile1, $tmpFile2, $tmpFile3],
            'name' => ['doc1.txt', 'doc2.pdf', 'doc3.bin'],
            'type' => ['text/plain', 'application/pdf', 'application/octet-stream'],
            'size' => [16, 21, 5],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        ],
    ], ['description' => 'Batch upload']);

    $controller = new $cls('upload', $app);
    $result = $controller->runAction('upload-multiple-files', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['uploadedCount'])->toBe(3);
    expect($result['message'])->toBe('doc1.txt:16,doc2.pdf:21,doc3.bin:5');

    foreach ([$tmpFile1, $tmpFile2, $tmpFile3] as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
});

test('[yii2] array of files skips entries with UPLOAD_ERR_NO_FILE', function () {
    intGen(realpath(__DIR__ . '/fixtures/edge_cases.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    $tmpFile1 = tempnam(sys_get_temp_dir(), 'yii2_skip_test_1_');
    file_put_contents($tmpFile1, 'only this one');

    $cls = 'SkipFileCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractUploadController'
        . ' implements \\' . $ns . '\\contracts\\UploadControllerInterface {'
        . ' public function actionUploadMultipleFiles(\\' . $ns . '\\schemas\\MultiFileUpload $body): \\' . $ns . '\\schemas\\UploadMultipleFilesResponse200 {'
        . '   $resp = new \\' . $ns . '\\schemas\\UploadMultipleFilesResponse200();'
        . '   $resp->uploadedCount = count($body->files);'
        . '   $resp->message = "ok";'
        . '   return $resp;'
        . ' }'
        . '}'
    );

    $app = intApp();
    intFileRequest($app, [
        'files' => [
            'tmp_name' => [$tmpFile1, '', ''],
            'name' => ['real.txt', '', ''],
            'type' => ['text/plain', '', ''],
            'size' => [13, 0, 0],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_FILE],
        ],
    ], ['description' => 'Partial upload']);

    $controller = new $cls('upload', $app);
    $result = $controller->runAction('upload-multiple-files', []);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    // Only 1 file should be included (2 are UPLOAD_ERR_NO_FILE)
    expect($result['uploadedCount'])->toBe(1);

    if (file_exists($tmpFile1)) {
        unlink($tmpFile1);
    }
});

test('[yii2] file upload getStream reads binary content correctly', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    // Create a file with binary content (non-UTF8)
    $tmpFile = tempnam(sys_get_temp_dir(), 'yii2_binary_test_');
    $binaryContent = "\x00\x01\x02\xFF\xFE\xFD" . str_repeat("\xAB", 100);
    file_put_contents($tmpFile, $binaryContent);

    $cls = 'BinaryCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new class implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["testUser" => true]; } }; }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   $stream = $body->photo->getStream();'
        . '   $content = $stream->getContents();'
        . '   $pet->name = strlen($content) . ":" . bin2hex(substr($content, 0, 6));'
        . '   $stream->close();'
        . '   return $pet;'
        . ' }'
        . '}'
    );

    $app = intApp();
    intFileRequest($app, [
        'photo' => [
            'tmp_name' => $tmpFile,
            'name' => 'binary.dat',
            'type' => 'application/octet-stream',
            'size' => strlen($binaryContent),
            'error' => UPLOAD_ERR_OK,
        ],
    ]);

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('upload-pet-photo', ['petId' => 'pet-binary']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['name'])->toBe('106:000102fffefd');

    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

test('[yii2] file upload with custom FileHandler implementation', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    $tmpFile = tempnam(sys_get_temp_dir(), 'yii2_custom_handler_');
    file_put_contents($tmpFile, 'custom handled');

    // Custom file handler that adds a prefix to the filename
    $handlerCls = 'CustomHandler_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $handlerCls . ' implements \\futuretek\\openapi\\Middleware\\FileHandlerInterface {'
        . ' public function convertUploadedFile(mixed $file): \\Psr\\Http\\Message\\UploadedFileInterface {'
        . '   if ($file instanceof \\yii\\web\\UploadedFile) {'
        . '     return new \\futuretek\\openapi\\Middleware\\Psr7UploadedFile('
        . '       filePath: $file->tempName,'
        . '       size: $file->size,'
        . '       errorStatus: $file->error,'
        . '       clientFilename: "custom_" . $file->name,'
        . '       clientMediaType: $file->type,'
        . '     );'
        . '   }'
        . '   throw new \\InvalidArgumentException("Unsupported");'
        . ' }'
        . '}'
    );

    $cls = 'CustomHandlerCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new class implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["testUser" => true]; } }; }'
        . ' protected function createFileHandler(): \\futuretek\\openapi\\Middleware\\FileHandlerInterface { return new \\' . $handlerCls . '(); }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   $pet->name = $body->photo->getClientFilename();'
        . '   return $pet;'
        . ' }'
        . '}'
    );

    $app = intApp();
    intFileRequest($app, [
        'photo' => [
            'tmp_name' => $tmpFile,
            'name' => 'original.jpg',
            'type' => 'image/jpeg',
            'size' => 14,
            'error' => UPLOAD_ERR_OK,
        ],
    ]);

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('upload-pet-photo', ['petId' => 'pet-custom']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['name'])->toBe('custom_original.jpg');

    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

test('[yii2] multipart file upload metadata is preserved through pipeline', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;

    $tmpFile = tempnam(sys_get_temp_dir(), 'yii2_meta_test_');
    file_put_contents($tmpFile, 'metadata test');

    $cls = 'MetaCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $cls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new class implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["testUser" => true]; } }; }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet {'
        . '   $pet = new \\' . $ns . '\\schemas\\Pet();'
        . '   $pet->id = $petId;'
        . '   $pet->status = \\' . $ns . '\\enums\\PetStatus::Available;'
        . '   $f = $body->photo;'
        . '   $pet->name = implode("|", ['
        . '     "name:" . $f->getClientFilename(),'
        . '     "type:" . $f->getClientMediaType(),'
        . '     "size:" . $f->getSize(),'
        . '     "error:" . $f->getError(),'
        . '   ]);'
        . '   return $pet;'
        . ' }'
        . '}'
    );

    $app = intApp();
    intFileRequest($app, [
        'photo' => [
            'tmp_name' => $tmpFile,
            'name' => 'report.pdf',
            'type' => 'application/pdf',
            'size' => 13,
            'error' => UPLOAD_ERR_OK,
        ],
    ]);

    $controller = new $cls('pet', $app);
    $result = $controller->runAction('upload-pet-photo', ['petId' => 'pet-meta']);

    expect($result)->toBeString();
    $result = json_decode($result, true);
    expect($result['name'])->toBe('name:report.pdf|type:application/pdf|size:13|error:0');

    if (file_exists($tmpFile)) {
        unlink($tmpFile);
    }
});

// ============================================================
// Security inheritance from root-level
// ============================================================

test('[yii2] root-level security is inherited by operations without own security', function () {
    intGen(realpath(__DIR__ . '/fixtures/security_inheritance.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    // listItems has no operation-level security → should inherit root bearerAuth
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractItemController");
    $meta = $ref->getDefaultProperties()['operationMeta'];
    expect($meta['listItems']['security'])->toBe(['bearerAuth']);
});

test('[yii2] operation-level security overrides root-level security', function () {
    intGen(realpath(__DIR__ . '/fixtures/security_inheritance.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    // createItem has own security: apiKey → should NOT have bearerAuth
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractItemController");
    $meta = $ref->getDefaultProperties()['operationMeta'];
    expect($meta['createItem']['security'])->toBe(['apiKey']);
    expect($meta['createItem']['security'])->not->toContain('bearerAuth');
});

test('[yii2] operation with security: [] explicitly opts out of root security', function () {
    intGen(realpath(__DIR__ . '/fixtures/security_inheritance.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    // listPublicItems has security: [] → explicitly opts out, no security
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractItemController");
    $meta = $ref->getDefaultProperties()['operationMeta'];
    expect($meta['listPublicItems'])->not->toHaveKey('security');
});

test('[yii2] multiple operations across controllers inherit root security', function () {
    intGen(realpath(__DIR__ . '/fixtures/security_inheritance.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    // getSettings (Admin controller) has no own security → inherits root bearerAuth
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractAdminController");
    $meta = $ref->getDefaultProperties()['operationMeta'];
    expect($meta['getSettings']['security'])->toBe(['bearerAuth']);
});

test('[yii2] operation with multiple security schemes overrides root', function () {
    intGen(realpath(__DIR__ . '/fixtures/security_inheritance.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    // updateSettings has own security with bearerAuth + adminToken
    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractAdminController");
    $meta = $ref->getDefaultProperties()['operationMeta'];
    expect($meta['updateSettings']['security'])->toBe(['bearerAuth', 'adminToken']);
});

test('[yii2] spec without root security: operations without own security have no security', function () {
    // petstore.json has no root-level security
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractPetController");
    $meta = $ref->getDefaultProperties()['operationMeta'];

    // listPets has no operation security and no root security → no security
    expect($meta['listPets'])->not->toHaveKey('security');

    // getPet also has no security
    expect($meta['getPet'])->not->toHaveKey('security');

    // createPet has operation-level bearerAuth → should still have it
    expect($meta['createPet']['security'])->toBe(['bearerAuth']);
});

test('[yii2] inherited root security triggers authentication on beforeAction', function () {
    intGen(realpath(__DIR__ . '/fixtures/security_inheritance.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    // Auth that always throws
    $authCls = 'InheritedAuthFail_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authCls . ' implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { throw new \\RuntimeException("Auth required"); } }');

    $ctrlCls = 'InheritedAuthCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlCls
        . ' extends \\' . $ns . '\\contracts\\AbstractAdminController'
        . ' implements \\' . $ns . '\\contracts\\AdminControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new \\' . $authCls . '(); }'
        . ' public function actionGetSettings(): \\' . $ns . '\\schemas\\GetSettingsResponse200 { return new \\' . $ns . '\\schemas\\GetSettingsResponse200(); }'
        . ' public function actionUpdateSettings(\\' . $ns . '\\schemas\\SettingsUpdate $body): void {}'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'GET');

    $controller = new $ctrlCls('admin', $app);
    // getSettings inherits bearerAuth from root → auth should be called and fail
    $result = $controller->runAction('get-settings', []);

    expect($app->response->statusCode)->toBe(401);
    expect($app->response->data['error'])->toBe('Unauthorized');
    expect($app->response->data['message'])->toBe('Auth required');
});

test('[yii2] opted-out operation (security: []) skips auth even with root security', function () {
    intGen(realpath(__DIR__ . '/fixtures/security_inheritance.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    // Auth that would always fail
    $authCls = 'BoomAuth2_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authCls . ' implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { throw new \\RuntimeException("Should not be called"); } }');

    $ctrlCls = 'PublicItemCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlCls
        . ' extends \\' . $ns . '\\contracts\\AbstractItemController'
        . ' implements \\' . $ns . '\\contracts\\ItemControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new \\' . $authCls . '(); }'
        . ' public function actionListItems(): \\' . $ns . '\\schemas\\ListItemsResponse200 { $r = new \\' . $ns . '\\schemas\\ListItemsResponse200(); return $r; }'
        . ' public function actionCreateItem(\\' . $ns . '\\schemas\\CreateItemRequest $body): \\' . $ns . '\\schemas\\ItemResponse { return new \\' . $ns . '\\schemas\\ItemResponse(); }'
        . ' public function actionListPublicItems(): \\' . $ns . '\\schemas\\ListPublicItemsResponse200 { $r = new \\' . $ns . '\\schemas\\ListPublicItemsResponse200(); return $r; }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'GET');

    $controller = new $ctrlCls('item', $app);
    // listPublicItems has security: [] → auth should NOT be called
    $result = $controller->runAction('list-public-items', []);

    // Should succeed (no 401)
    expect($app->response->statusCode)->not->toBe(401);
});

// ============================================================
// Authorization with controller param
// ============================================================

test('[yii2] authorization receives controller/tag name', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    // Auth that passes
    $authCls = 'PassAuth2_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authCls . ' implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["userId" => 1]; } }');

    // Authz that captures the controller name
    $authzCls = 'CaptureAuthz_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authzCls . ' implements \\futuretek\\openapi\\Middleware\\AuthorizationInterface {
        public static string $capturedController = "";
        public static string $capturedOperationId = "";
        public function authorize(string $operationId, mixed $identity, string $controller): bool {
            self::$capturedController = $controller;
            self::$capturedOperationId = $operationId;
            return true;
        }
    }');

    $ctrlCls = 'AuthzTagCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlCls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new \\' . $authCls . '(); }'
        . ' protected function createAuthorization(): \\futuretek\\openapi\\Middleware\\AuthorizationInterface { return new \\' . $authzCls . '(); }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'POST', json_encode(['name' => 'Test', 'status' => 'available']));

    $controller = new $ctrlCls('pet', $app);
    $controller->runAction('create-pet', []);

    // Verify the controller name was passed to authorize
    expect($authzCls::$capturedController)->toBe('Pet');
    expect($authzCls::$capturedOperationId)->toBe('createPet');
});

test('[yii2] controllerTag is set in generated abstract controller', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractPetController");
    $defaults = $ref->getDefaultProperties();
    expect($defaults['controllerTag'])->toBe('Pet');
});

test('[yii2] controllerTag for different controllers matches their names', function () {
    intGen(realpath(__DIR__ . '/fixtures/security_inheritance.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $adminRef = new ReflectionClass("{$this->ns}\\contracts\\AbstractAdminController");
    expect($adminRef->getDefaultProperties()['controllerTag'])->toBe('Admin');

    $itemRef = new ReflectionClass("{$this->ns}\\contracts\\AbstractItemController");
    expect($itemRef->getDefaultProperties()['controllerTag'])->toBe('Item');
});

test('[yii2] authorization denying specific controller returns 403', function () {
    intGen(realpath(__DIR__ . '/fixtures/petstore.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ns = $this->ns;
    $authCls = 'PassAuth3_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authCls . ' implements \\futuretek\\openapi\\Middleware\\AuthenticationInterface { public function authenticate(string $operationId, array $securitySchemes): mixed { return ["userId" => 1]; } }');

    // Authz that denies Pet controller
    $authzCls = 'DenyPetAuthz_' . str_replace('.', '_', uniqid('', true));
    eval('class ' . $authzCls . ' implements \\futuretek\\openapi\\Middleware\\AuthorizationInterface {
        public function authorize(string $operationId, mixed $identity, string $controller): bool {
            return $controller !== "Pet";
        }
    }');

    $ctrlCls = 'DenyPetCtrl_' . str_replace('.', '_', uniqid('', true));
    eval(
        'class ' . $ctrlCls
        . ' extends \\' . $ns . '\\contracts\\AbstractPetController'
        . ' implements \\' . $ns . '\\contracts\\PetControllerInterface {'
        . ' protected function createAuthentication(): \\futuretek\\openapi\\Middleware\\AuthenticationInterface { return new \\' . $authCls . '(); }'
        . ' protected function createAuthorization(): \\futuretek\\openapi\\Middleware\\AuthorizationInterface { return new \\' . $authzCls . '(); }'
        . ' public function actionListPets(?int $limit = 20, ?\\' . $ns . '\\enums\\PetStatus $status = null): \\' . $ns . '\\schemas\\PetListResponse { return new \\' . $ns . '\\schemas\\PetListResponse(); }'
        . ' public function actionCreatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionGetPet(string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionUpdatePet(\\' . $ns . '\\schemas\\CreatePetRequest $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . ' public function actionDeletePet(string $petId): void {}'
        . ' public function actionUploadPetPhoto(\\' . $ns . '\\schemas\\PetPhotoUpload $body, string $petId): \\' . $ns . '\\schemas\\Pet { return new \\' . $ns . '\\schemas\\Pet(); }'
        . '}'
    );

    $app = intApp();
    intRequest($app, 'DELETE');

    $controller = new $ctrlCls('pet', $app);
    $result = $controller->runAction('delete-pet', ['petId' => 'x']);

    expect($app->response->statusCode)->toBe(403);
    expect($app->response->data['error'])->toBe('Forbidden');
});

// ============================================================
// DefaultAuthentication behavior
// ============================================================

test('[yii2] DefaultAuthentication is pass-through and always returns null', function () {
    $auth = new \futuretek\openapi\Middleware\DefaultAuthentication();

    // Pass-through: returns null regardless of operation or security schemes
    expect($auth->authenticate('testOp', ['bearerAuth']))->toBeNull();
    expect($auth->authenticate('anotherOp', []))->toBeNull();
    expect($auth->authenticate('createPet', ['apiKey', 'oauth2']))->toBeNull();
});

test('[yii2] DefaultAuthentication does not throw when user is not logged in', function () {
    $auth = new \futuretek\openapi\Middleware\DefaultAuthentication();

    // Create app with user component configured with a dummy identity class
    if (\Yii::$app !== null) {
        \Yii::$app = null;
    }
    new \yii\web\Application([
        'id' => 'test-app',
        'basePath' => sys_get_temp_dir(),
        'components' => [
            'request' => [
                'class' => \yii\web\Request::class,
                'enableCookieValidation' => false,
                'enableCsrfCookie' => false,
                'scriptUrl' => '',
            ],
            'response' => ['class' => \yii\web\Response::class],
            'user' => [
                'class' => \yii\web\User::class,
                'identityClass' => \yii\web\IdentityInterface::class,
                'enableSession' => false,
            ],
        ],
    ]);
    // Pass-through: does not check user identity, just returns null
    $result = $auth->authenticate('testOp', ['bearerAuth']);
    expect($result)->toBeNull();
});

// ============================================================
// DefaultAuthorization behavior
// ============================================================

test('[yii2] DefaultAuthorization always returns true (pass-through)', function () {
    $authz = new \futuretek\openapi\Middleware\DefaultAuthorization();

    expect($authz->authorize('listPets', ['userId' => 1], 'Pet'))->toBeTrue();
    expect($authz->authorize('createPet', null, 'Pet'))->toBeTrue();
    expect($authz->authorize('getSettings', ['admin' => true], 'Admin'))->toBeTrue();
    expect($authz->authorize('anyOperation', 'user-string', ''))->toBeTrue();
});

test('[yii2] DefaultAuthorization accepts controller parameter', function () {
    $authz = new \futuretek\openapi\Middleware\DefaultAuthorization();

    // Ensure the method signature accepts all three parameters without error
    $result = $authz->authorize('testOp', ['id' => 1], 'TestController');
    expect($result)->toBeTrue();
});

// ============================================================
// Security inheritance: inline spec tests
// ============================================================

test('[yii2] inline spec with root security: inherited operations get security in meta', function () {
    $spec = json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'security' => [['bearerAuth' => []]],
        'paths' => [
            '/things' => [
                'get' => [
                    'operationId' => 'listThings',
                    'tags' => ['Thing'],
                    'responses' => ['200' => ['description' => 'OK']],
                ],
                'post' => [
                    'operationId' => 'createThing',
                    'tags' => ['Thing'],
                    'security' => [['apiKey' => []]],
                    'responses' => ['201' => ['description' => 'Created']],
                ],
                'delete' => [
                    'operationId' => 'deleteAllThings',
                    'tags' => ['Thing'],
                    'security' => [],
                    'responses' => ['204' => ['description' => 'Deleted']],
                ],
            ],
        ],
        'components' => [
            'securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ],
        ],
    ]);
    mkdir($this->baseDir, 0755, true);
    file_put_contents($this->baseDir . '/spec.json', $spec);
    intGen(realpath($this->baseDir . '/spec.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractThingController");
    $meta = $ref->getDefaultProperties()['operationMeta'];

    // listThings: no own security → inherits bearerAuth from root
    expect($meta['listThings']['security'])->toBe(['bearerAuth']);

    // createThing: own security apiKey → overrides root
    expect($meta['createThing']['security'])->toBe(['apiKey']);

    // deleteAllThings: security: [] → explicitly opts out
    expect($meta['deleteAllThings'])->not->toHaveKey('security');
});

test('[yii2] spec without root security: operations without own security have empty security', function () {
    $spec = json_encode([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/open' => [
                'get' => [
                    'operationId' => 'openEndpoint',
                    'tags' => ['Open'],
                    'responses' => ['200' => ['description' => 'OK']],
                ],
            ],
        ],
    ]);
    mkdir($this->baseDir, 0755, true);
    file_put_contents($this->baseDir . '/spec.json', $spec);
    intGen(realpath($this->baseDir . '/spec.json'), $this->baseDir, $this->ns);
    $this->autoloader = intAutoload($this->baseDir);
    intLoad($this->baseDir, $this->nsPath);

    $ref = new ReflectionClass("{$this->ns}\\contracts\\AbstractOpenController");
    $meta = $ref->getDefaultProperties()['operationMeta'];

    // No root security, no operation security → no security key in meta
    expect($meta['openEndpoint'])->not->toHaveKey('security');
});


