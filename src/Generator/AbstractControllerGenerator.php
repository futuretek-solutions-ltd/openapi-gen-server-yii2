<?php

declare(strict_types=1);

namespace futuretek\openapi\Generator;

use futuretek\openapi\Config;
use futuretek\openapi\GeneratorResult;
use futuretek\openapi\Parser\ParsedOperation;
use futuretek\openapi\Parser\ParsedSchema;

/**
 * Generates per-controller abstract classes extending AbstractApiController.
 *
 * These abstract classes contain the operationMeta array mapping operationIds to
 * their body class, media type, security, and parameter definitions.
 * User controllers extend these and implement the corresponding interface.
 */
final class AbstractControllerGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly GeneratorResult $result,
    ) {}

    private const BUILTIN_TYPES = ['int', 'float', 'string', 'bool', 'array', 'object', 'mixed'];

    /** @var array<string, ParsedSchema> */
    private array $schemaIndex = [];

    /**
     * @param ParsedOperation[] $operations
     * @param ParsedSchema[] $schemas
     */
    public function generate(array $operations, array $schemas = []): void
    {
        // Index schemas by name for discriminator lookup
        foreach ($schemas as $schema) {
            $this->schemaIndex[$schema->name] = $schema;
        }

        $dir = $this->config->controllerDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: $dir");
        }

        $grouped = $this->groupByController($operations);

        foreach ($grouped as $controllerName => $ops) {
            $this->generateAbstractController($controllerName, $ops, $dir);
        }
    }

    /**
     * @return array<string, ParsedOperation[]>
     */
    private function groupByController(array $operations): array
    {
        $grouped = [];
        foreach ($operations as $op) {
            $grouped[$op->controllerName][] = $op;
        }
        return $grouped;
    }

    /**
     * @param ParsedOperation[] $operations
     */
    private function generateAbstractController(string $controllerName, array $operations, string $dir): void
    {
        $namespace = $operations[0]->controllerNamespace ?? $this->config->controllerNamespace();
        $schemaNamespace = $this->config->schemaNamespace();
        $enumNamespace = $this->config->enumNamespace();
        $className = 'Abstract' . $controllerName . 'Controller';
        $interfaceName = $controllerName . 'ControllerInterface';

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace $namespace;";
        $lines[] = '';

        // Imports
        $lines[] = 'use futuretek\\openapi\\AbstractApiController;';
        $lines[] = '';

        $lines[] = "/**";
        $lines[] = " * Abstract controller for $controllerName API endpoints.";
        $lines[] = " *";
        $lines[] = " * This class is auto-generated. Do not edit manually.";
        $lines[] = " * Extend this class in your controller implementation and implement $interfaceName.";
        $lines[] = " */";
        $lines[] = "abstract class $className extends AbstractApiController";
        $lines[] = '{';

        // Generate operationMeta
        $lines[] = "    protected string \$controllerTag = '$controllerName';";
        $lines[] = '';
        $lines[] = '    protected array $operationMeta = [';

        foreach ($operations as $op) {
            $lines[] = "        '{$op->operationId}' => [";

            if ($op->requestBodyClass !== null) {
                if ($op->requestBodyIsArray) {
                    if (in_array($op->requestBodyClass, self::BUILTIN_TYPES, true)) {
                        // Primitive array body: no DTO class needed
                        $lines[] = "            'bodyType' => '{$op->requestBodyClass}',";
                    } else {
                        // Array body of DTOs: reference the item class
                        $fqcn = $schemaNamespace . '\\' . $op->requestBodyClass;
                        $lines[] = "            'bodyClass' => \\{$fqcn}::class,";
                    }
                    $lines[] = "            'bodyIsArray' => true,";
                } else {
                    $fqcn = $schemaNamespace . '\\' . $op->requestBodyClass;
                    $lines[] = "            'bodyClass' => \\{$fqcn}::class,";
                }
                $lines[] = "            'bodyRequired' => " . ($op->requestBodyRequired ? 'true' : 'false') . ',';
                $lines[] = "            'mediaType' => '{$op->requestBodyMediaType}',";

                // Discriminator mapping for polymorphic body types
                $bodySchema = $this->schemaIndex[$op->requestBodyClass] ?? null;
                if ($bodySchema !== null && $bodySchema->discriminator !== null) {
                    $disc = $bodySchema->discriminator;
                    $lines[] = "            'discriminator' => [";
                    $lines[] = "                'propertyName' => '{$disc['propertyName']}',";
                    $lines[] = "                'mapping' => [";
                    foreach ($disc['mapping'] as $value => $mappedClassName) {
                        $mappedFqcn = $schemaNamespace . '\\' . $mappedClassName;
                        $lines[] = "                    '$value' => \\{$mappedFqcn}::class,";
                    }
                    $lines[] = '                ],';
                    $lines[] = '            ],';
                }
            }

            if (!empty($op->security)) {
                $securityList = implode("', '", $op->security);
                $lines[] = "            'security' => ['$securityList'],";
            }

            if (!empty($op->parameters)) {
                $lines[] = "            'params' => [";
                foreach ($op->parameters as $param) {
                    $parts = [];
                    $parts[] = "'name' => '{$param->name}'";
                    if ($param->phpName !== $param->name) {
                        $parts[] = "'phpName' => '{$param->phpName}'";
                    }
                    $parts[] = "'in' => '{$param->in}'";
                    $parts[] = "'type' => '{$param->type}'";
                    $parts[] = "'required' => " . ($param->required ? 'true' : 'false');

                    if ($param->default !== null) {
                        $parts[] = "'default' => " . var_export($param->default, true);
                    }

                    if ($param->enumRef !== null) {
                        $fqcn = $enumNamespace . '\\' . $param->enumRef;
                        $parts[] = "'enumClass' => \\{$fqcn}::class";
                    }

                    $line = '                [' . implode(', ', $parts) . '],';
                    $lines[] = $line;
                }
                $lines[] = '            ],';
            }

            $lines[] = '        ],';
        }

        $lines[] = '    ];';

        // Generate Yii2 actions() method to map kebab-case action IDs
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @inheritDoc';
        $lines[] = '     */';
        $lines[] = '    public function actions(): array';
        $lines[] = '    {';
        $lines[] = '        return array_merge(parent::actions(), []);';
        $lines[] = '    }';

        $lines[] = '}';
        $lines[] = '';

        $filePath = $dir . DIRECTORY_SEPARATOR . $className . '.php';
        file_put_contents($filePath, implode("\n", $lines));
        $this->result->addGenerated($filePath);
    }
}







