<?php

declare(strict_types=1);

namespace futuretek\openapi;

/**
 * Generator configuration.
 *
 * In Yii2, namespaces map directly to directories. The `baseDir` points to the application
 * root (@app) and all generated files are placed according to their full namespace path.
 *
 * Example:
 *   baseDir    = '/var/www/myapp'    (maps to @app)
 *   namespace  = 'app\modules\api'
 *   schemaSubNamespace = 'schemas'
 *   → schema namespace: app\modules\api\schemas
 *   → schema directory: /var/www/myapp/modules/api/schemas/
 */
final class Config
{
    /**
     * @param string $specPath Path to the OpenAPI 3.0.x specification file (JSON or YAML)
     * @param string $baseDir Application base directory (@app). Namespace segments become subdirectories relative to this.
     * @param string $namespace Root namespace for generated code (e.g. app\modules\api)
     * @param string $schemaSubNamespace Sub-namespace for generated DTOs (appended to $namespace)
     * @param string $enumSubNamespace Sub-namespace for generated enums (appended to $namespace)
     * @param string $controllerSubNamespace Sub-namespace for generated controller interfaces (appended to $namespace)
     * @param string $routeFile Output path for the Yii2 route configuration file (relative to baseDir or absolute)
     * @param string|null $routePrefix Prefix for route targets (e.g. 'api' for module routes like 'api/controller/action'). If null, no prefix is added.
     * @param bool $cleanTargetDirs Whether to clean (delete all .php files from) target directories for enums, schemas and contracts before generation.
     */
    public function __construct(
        public readonly string $specPath,
        public readonly string $baseDir = '.',
        public readonly string $namespace = 'app\\api',
        public readonly string $schemaSubNamespace = 'schemas',
        public readonly string $enumSubNamespace = 'enums',
        public readonly string $controllerSubNamespace = 'contracts',
        public readonly string $routeFile = 'config/routes.api.php',
        public readonly ?string $routePrefix = null,
        public readonly bool $cleanTargetDirs = false,
    ) {}

    /**
     * Full namespace for schemas.
     */
    public function schemaNamespace(): string
    {
        return $this->namespace . '\\' . $this->schemaSubNamespace;
    }

    /**
     * Full namespace for enums.
     */
    public function enumNamespace(): string
    {
        return $this->namespace . '\\' . $this->enumSubNamespace;
    }

    /**
     * Full namespace for controllers.
     */
    public function controllerNamespace(): string
    {
        return $this->namespace . '\\' . $this->controllerSubNamespace;
    }

    /**
     * Convert a full namespace to its corresponding directory path.
     *
     * Strips the first segment (e.g. 'app') since that maps to baseDir itself,
     * then converts remaining segments to directory separators.
     *
     * app\modules\api\schemas → baseDir/modules/api/schemas
     */
    public function namespaceToDir(string $fullNamespace): string
    {
        $parts = explode('\\', $fullNamespace);
        // Remove the first segment (app) — it maps to baseDir
        array_shift($parts);

        if (empty($parts)) {
            return $this->baseDir;
        }

        return $this->baseDir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Absolute output directory for schemas.
     */
    public function schemaDir(): string
    {
        return $this->namespaceToDir($this->schemaNamespace());
    }

    /**
     * Absolute output directory for enums.
     */
    public function enumDir(): string
    {
        return $this->namespaceToDir($this->enumNamespace());
    }

    /**
     * Absolute output directory for controllers.
     */
    public function controllerDir(): string
    {
        return $this->namespaceToDir($this->controllerNamespace());
    }

    /**
     * Absolute path for the route file.
     *
     * If routeFile is an absolute path, returns it as-is.
     * Otherwise, resolves it relative to baseDir.
     */
    public function routeFilePath(): string
    {
        // Absolute path (Unix or Windows)
        if (str_starts_with($this->routeFile, '/') || preg_match('/^[A-Z]:\\\\/i', $this->routeFile)) {
            return $this->routeFile;
        }

        return $this->baseDir . DIRECTORY_SEPARATOR . $this->routeFile;
    }
}

