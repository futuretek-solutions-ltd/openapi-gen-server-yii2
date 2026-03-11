<?php

declare(strict_types=1);

namespace futuretek\openapi\Command;

use futuretek\openapi\Config;
use futuretek\openapi\Generator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'generate',
    description: 'Generate PHP server code from an OpenAPI 3.0.x specification',
)]
final class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'spec',
                InputArgument::REQUIRED,
                'Path to the OpenAPI specification file (JSON or YAML)',
            )
            ->addOption(
                'base-dir',
                null,
                InputOption::VALUE_OPTIONAL,
                'Application base directory (@app root). Defaults to current working directory.',
                '.',
            )
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_OPTIONAL,
                'Root namespace for generated code',
                'app\\api',
            )
            ->addOption(
                'schema-ns',
                null,
                InputOption::VALUE_OPTIONAL,
                'Sub-namespace for schemas (DTOs)',
                'schemas',
            )
            ->addOption(
                'enum-ns',
                null,
                InputOption::VALUE_OPTIONAL,
                'Sub-namespace for enums',
                'enums',
            )
            ->addOption(
                'controller-ns',
                null,
                InputOption::VALUE_OPTIONAL,
                'Sub-namespace for controller interfaces and abstract controllers',
                'contracts',
            )
            ->addOption(
                'route-file',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output path for Yii2 route configuration (relative to base-dir)',
                'config/routes.api.php',
            )
            ->addOption(
                'route-prefix',
                null,
                InputOption::VALUE_OPTIONAL,
                'Prefix for route targets (e.g. "api" for module routes like "api/controller/action")',
                null,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $specPath = realpath($input->getArgument('spec'));
        if ($specPath === false) {
            $io->error("Specification file not found: {$input->getArgument('spec')}");
            return Command::FAILURE;
        }

        $baseDir = $input->getOption('base-dir');
        $routePrefix = $input->getOption('route-prefix');

        // Trim surrounding quotes that may leak through from certain shells
        // (e.g. --route-prefix='api' on Windows CMD passes literal "'api'")
        $trimQuotes = static fn(?string $v): ?string => $v !== null ? trim($v, "\"'") : null;

        $config = new Config(
            specPath: $specPath,
            baseDir: $baseDir,
            namespace: $trimQuotes($input->getOption('namespace')),
            schemaSubNamespace: $trimQuotes($input->getOption('schema-ns')),
            enumSubNamespace: $trimQuotes($input->getOption('enum-ns')),
            controllerSubNamespace: $trimQuotes($input->getOption('controller-ns')),
            routeFile: $trimQuotes($input->getOption('route-file')),
            routePrefix: $trimQuotes($routePrefix),
        );

        $io->title('PHP OpenAPI Server Generator');
        $io->text("Spec: $specPath");
        $io->text("Base dir: $baseDir");
        $io->text("Namespace: {$config->namespace}");
        $io->text("Schemas: {$config->schemaDir()}");
        $io->text("Enums: {$config->enumDir()}");
        $io->text("Controllers: {$config->controllerDir()}");
        $io->text("Routes: {$config->routeFilePath()}");
        $io->newLine();

        $generator = new Generator($config);
        $result = $generator->generate();

        // Show warnings
        if ($result->hasWarnings()) {
            $io->warning('Warnings:');
            foreach ($result->getWarnings() as $warning) {
                $io->text("  ⚠ $warning");
            }
            $io->newLine();
        }

        // Show errors
        if ($result->hasErrors()) {
            $io->error('Errors:');
            foreach ($result->getErrors() as $error) {
                $io->text("  ✗ $error");
            }
            return Command::FAILURE;
        }

        // Show generated files
        $generated = $result->getGenerated();
        $io->success(count($generated) . ' file(s) generated:');
        foreach ($generated as $file) {
            $io->text("  ✓ $file");
        }

        return Command::SUCCESS;
    }
}


