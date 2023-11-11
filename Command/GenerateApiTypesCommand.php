<?php

declare(strict_types=1);

namespace Ovrflo\ApiPlatformTypescriptGeneratorBundle\Command;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ovrflo\ApiPlatformTypescriptGeneratorBundle\Event\ManipulateFilesEvent;
use Ovrflo\ApiPlatformTypescriptGeneratorBundle\Event\ManipulateMetadataEvent;
use Ovrflo\ApiPlatformTypescriptGeneratorBundle\Event\ManipulateRouteMetadataEvent;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Service\Attribute\SubscribedService;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function Symfony\Component\String\u;

#[AsCommand(
    name: 'ovrflo:api-platform:typescript:generate',
)]
final class GenerateApiTypesCommand extends Command implements ServiceSubscriberInterface
{
    private array $types = [];
    private array $operations = [];
    private array $files = [];
    private array $fragments = [];

    private ?InputInterface $input = null;
    private ?SymfonyStyle $io = null;
    private readonly string $outputDir;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ParameterBagInterface $parameterBag,
        private readonly Filesystem $filesystem,
        private readonly string $projectDir,
        private readonly array $options,
    ) {
        parent::__construct();
        $outputDir = $this->options['output_dir'] ?? $this->projectDir.'/assets/api/';
        $this->outputDir = rtrim($outputDir, '/').'/';
    }

    public static function getSubscribedServices(): array
    {
        return [
            EntityManagerInterface::class,
            ResourceNameCollectionFactoryInterface::class,
            ResourceMetadataCollectionFactoryInterface::class,
            PropertyNameCollectionFactoryInterface::class,
            PropertyMetadataFactoryInterface::class,
            ClockInterface::class,
            RouterInterface::class,
            TranslatorInterface::class,
            ValidatorInterface::class,
            EventDispatcherInterface::class,
            new SubscribedService('filters', ContainerInterface::class, attributes: new Autowire(service: 'api_platform.filter_locator')),
        ];
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write files, just print what would be done.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->filesystem->exists($this->outputDir)) {
            $this->filesystem->mkdir($this->outputDir);
        }

        $this->input = $input;
        $this->io = new SymfonyStyle($input, $output);
        $this->io->writeln('Generating <info>API</info> types...');
        $this->loadBaseMetadata();
        $this->extractModelMetadata();
        $this->extractOperationMetadata();
        $this->dispatchManipulateMetadataEvent();
        $this->buildModelFiles();
        $this->buildEndpointFiles();
        $this->buildRouteFile();
        $this->dispatchManipulateFilesEvent();
        $changedFileCount = $this->dumpFiles();

        if (!$changedFileCount) {
            $this->io->writeln('No files changed.');
        } else {
            $this->io->writeln(sprintf('Done. <info>%d</info> files changed.', $changedFileCount));
        }

        return Command::SUCCESS;
    }

    private function dispatchManipulateMetadataEvent(): void
    {
        $event = new ManipulateMetadataEvent($this->types, $this->operations, $this->fragments);
        $this->container->get(EventDispatcherInterface::class)->dispatch($event);
        $this->types = $event->types;
        $this->operations = $event->operations;
        $this->fragments = $event->fragments;
    }

    private function dispatchManipulateFilesEvent(): void
    {
        $event = new ManipulateFilesEvent($this->files, $this->fragments);
        $this->container->get(EventDispatcherInterface::class)->dispatch($event);
        $this->files = $event->files;
        $this->fragments = $event->fragments;
    }

    private function loadBaseMetadata(): void
    {
        $this->types = [
            'string' => ['type' => 'builtin'],
            'number' => ['type' => 'builtin'],
            'boolean' => ['type' => 'builtin'],
            'null' => ['type' => 'builtin'],
            'Array<T>' => ['type' => 'builtin'],
            'object' => ['type' => 'builtin'],
            'any' => ['type' => 'builtin'],
            'DateTime' => ['type' => 'type', 'alias' => 'string', 'file' => 'interfaces/ApiTypes'],
            'DateOnly' => ['type' => 'type', 'alias' => 'string', 'file' => 'interfaces/ApiTypes'],
            'HydraIri' => ['type' => 'type', 'alias' => 'string', 'file' => 'interfaces/ApiTypes'],
        ];
        $this->types['HydraItem'] = [
            'file' => 'interfaces/ApiTypes',
            'type' => 'interface',
            'properties' => [
                '@id' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                    'required' => false,
                ],
                '@type' => [
                    'type' => 'string',
                    'required' => false,
                ],
            ],
        ];
        $this->types['HydraCollection'] = [
            'file' => 'interfaces/ApiTypes',
            'type' => 'interface',
            'generic' => 'T extends HydraItem',
            'properties' => [
                '@id' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                    'required' => true,
                ],
                '@type' => [
                    'type' => 'string',
                    'enum' => ['hydra:Collection'],
                    'required' => true,
                ],
                '@context' => [
                    'type' => 'HydraIri',
                    'required' => true,
                ],
                'hydra:totalItems' => [
                    'type' => 'number',
                    'required' => true,
                ],
                'hydra:member' => [
                    'type' => 'Array<T>',
                    'required' => true,
                ],
                'hydra:view' => [
                    'type' => 'HydraView',
                ],
            ],
        ];

        $this->types['HydraView'] = [
            'file' => 'interfaces/ApiTypes',
            'type' => 'interface',
            'properties' => [
                '@type' => [
                    'type' => 'string',
                    'enum' => ['hydra:PartialCollectionView'],
                ],
                '@id' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                ],
                'hydra:first' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                ],
                'hydra:last' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                ],
                'hydra:next' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                ],
            ],
        ];

        $this->types['HydraSearchMapping'] = [
            'file' => 'interfaces/ApiTypes',
            'type' => 'interface',
            'properties' => [
                '@type' => [
                    'type' => 'string',
                    'enum' => ['hydra:IriTemplateMapping'],
                ],
                'variable' => [
                    'type' => 'string',
                ],
                'property' => [
                    'type' => 'string',
                ],
                'required' => [
                    'type' => 'boolean',
                ],
            ],
        ];

        $this->types['HydraSearch'] = [
            'file' => 'interfaces/ApiTypes',
            'type' => 'interface',
            'properties' => [
                '@type' => [
                    'type' => 'string',
                    'enum' => ['hydra:IriTemplate'],
                ],
                'hydra:template' => [
                    'type' => 'string',
                ],
                'hydra:variableRepresentation' => [
                    'type' => 'string',
                    'enum' => ['BasicRepresentation'],
                ],
                'hydra:mapping' => [
                    'type' => 'Array<HydraSearchMapping>',
                ],
            ],
        ];

        $this->types['BooleanEnum'] = [
            'file' => 'interfaces/Enum',
            'type' => 'enum',
            'values' => [
                'True' => 'true',
                'False' => 'false',
            ],
        ];

        $this->types['ListParams'] = [
            'file' => 'interfaces/ApiTypes',
            'type' => 'interface',
            'properties' => [
            ],
        ];

        if ($this->parameterBag->get('api_platform.collection.pagination.enabled')) {
            $this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.page_parameter_name')] = [
                'type' => 'number',
                'format' => 'int32',
            ];
            if ($this->parameterBag->get('api_platform.collection.pagination.client_items_per_page')) {
                $this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.items_per_page_parameter_name')] = [
                    'type' => 'number',
                    'format' => 'int32',
                    'max' => $this->parameterBag->get('api_platform.collection.pagination.maximum_items_per_page'),
                ];
            }
            if ($this->parameterBag->get('api_platform.collection.pagination.client_enabled')) {
                $this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.enabled_parameter_name')] = [
                    'type' => 'BooleanEnum',
                ];
            }
            if ($this->parameterBag->get('api_platform.collection.pagination.client_partial')) {
                $this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.partial_parameter_name')] = [
                    'type' => 'BooleanEnum',
                ];
            }
        }
    }

    private function extractModelMetadata(): void
    {
        if (!$this->options['model_metadata']['namespaces'] && $this->io->isVerbose()) {
            $this->io->note('No namespaces configured for model metadata extraction. Skipping.');
            return;
        }

        foreach ($this->container->get(ResourceNameCollectionFactoryInterface::class)->create() as $resourceName) {
            $matches = false;
            foreach ($this->options['model_metadata']['namespaces'] as $namespace) {
                if ($namespace) {
                    $namespace .= '\\';
                }

                if (str_starts_with($resourceName, $namespace)) {
                    $matches = true;
                    break;
                }
            }

            if (!$matches) {
                continue;
            }

            $this->extractModelMetadataForModel($resourceName);
        }
    }

    private function extractModelMetadataForModel(string $resourceName): array
    {
        $matchingNamespaces = array_filter(
            array_map(
                static fn (string $namespace) => $namespace ? rtrim($namespace, '\\').'\\' : $namespace,
                $this->options['model_metadata']['namespaces'] ?? [],
            ),
            static fn (string $namespace) => str_starts_with($resourceName, $namespace)
        );
        $matchingNamespace = reset($matchingNamespaces) ?: '';

        /** @var ApiResource|null $resourceMetadata */
        $resourceMetadata = $this->container->get(ResourceMetadataCollectionFactoryInterface::class)->create($resourceName)[0] ?? null;
        $typeName = $resourceMetadata?->getShortName() ?? u($resourceName)->slice(strlen($matchingNamespace))->replace('\\', '')->toString();
        if (isset($this->types[$typeName])) {
            return $this->types[$typeName];
        }

        $reflection = new \ReflectionClass($resourceName);

        $this->types[$typeName] = $generatedType = [
            'name' => $typeName,
            'file' => 'interfaces/' . $typeName,
            'class' => $resourceName,
            'type' => 'interface',
            'properties' => [],
            'depends' => [
                'interfaces/ApiTypes' => [
                    'HydraItem',
                ],
            ],
            'extends' => 'HydraItem',
            'doc_block' => [
                '@see ' . str_replace($this->projectDir . '/', '', $reflection->getFileName()),
            ],
        ];

        foreach ($this->container->get(PropertyNameCollectionFactoryInterface::class)->create($resourceName) as $propertyName) {
            $propertyMetadata = $this->container->get(PropertyMetadataFactoryInterface::class)->create($resourceName, $propertyName);
            if (!$propertyMetadata->isReadable() && !$propertyMetadata->isWritable()) {
                continue;
            }

            $tsTypes = [];
            $depends = [];

            $builtinTypes = $propertyMetadata->getBuiltinTypes();
            foreach ($builtinTypes as $builtinType) {
                if ($builtinType->isNullable()) {
                    $tsTypes[] = 'null';
                }

                if ($builtinType->getBuiltinType() === 'string') {
                    $tsTypes[] = 'string';
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'int' || $builtinType->getBuiltinType() === 'float') {
                    $tsTypes[] = 'number';
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'bool') {
                    $tsTypes[] = 'boolean';
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'null') {
                    $tsTypes[] = 'null';
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'array') {
                    $tsTypes[] = 'Array<any>';
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'object') {
                    if ($builtinType->isCollection()) {
                        $tsTypes[] = $this->resolveCollectionType($builtinType, $generatedType);
                        continue;
                    }

                    $resolvedType = match ($builtinType->getClassName()) {
                        \DateTimeImmutable::class => 'DateTime',
                        \DateTime::class => 'DateTime',
                        \DateTimeInterface::class => 'DateTime',
                        default => null,
                    };

                    if ($resolvedType) {
                        $tsTypes[] = $resolvedType;
                        $depends['interfaces/ApiTypes'][$resolvedType] = $resolvedType;
                        $generatedType['depends']['interfaces/ApiTypes'][$resolvedType] = $resolvedType;
                        continue;
                    }

                    if ($builtinType->getClassName() && enum_exists($builtinType->getClassName())) {
                        $typeInfo = $this->buildTypeFromEnum($builtinType->getClassName());
                        $tsTypes[] = $typeInfo['name'];
                        $depends[$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];
                        $generatedType['depends'][$typeInfo['file']][] = $typeInfo['name'];
                        continue;
                    }

                    $doctrineMetadata = null;
                    try {
                        $doctrineMetadata = $this->getEntityManager()->getMetadataFactory()->getMetadataFor($builtinType->getClassName());
                    } catch (\Throwable) {
                    }

                    if ($doctrineMetadata) {
                        $typeInfo = $this->extractModelMetadataForModel($builtinType->getClassName());
                        $tsTypes[] = $typeInfo['name'];
                        $depends[$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];
                        $generatedType['depends'][$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];
                        if (in_array($builtinType->getClassName(), iterator_to_array($this->container->get(ResourceNameCollectionFactoryInterface::class)->create()))) {
                            $tsTypes[] = 'HydraIri';
                            $depends['interfaces/ApiTypes']['HydraIri'] = 'HydraIri';
                            $generatedType['depends']['interfaces/ApiTypes']['HydraIri'] = 'HydraIri';
                        }
                        continue;
                    }
                }

                if (in_array($builtinType->getClassName(), [null, UploadedFile::class, File::class])) {
                    $tsTypes[] = 'any';
                    continue;
                }

                throw new \InvalidArgumentException(sprintf('Unknown builtin type "%s".', $builtinType->getClassName() ?? $builtinType->getBuiltinType()));
            }

            $generatedType['properties'][$propertyName] = [
                'type' => implode('|', $tsTypes ?: ['any']),
                'api_platform_schema' => $propertyMetadata->getSchema(),
                'required' => $propertyMetadata->isRequired(),
                'readOnly' => $propertyMetadata->isReadable() && !$propertyMetadata->isWritable(),
                'depends' => $depends,
            ];
        }

        return $this->types[$typeName] = $generatedType;
    }

    private function buildTypeFromEnum(string $enum): array
    {
        $typeName = u($enum)
            ->trimPrefix('App\\')
            ->trimPrefix('Entity\\')
            ->trimPrefix('Enum\\')
            ->replace('\\Enum\\', '')
            ->replace('\\', '')
            ->toString()
        ;
        $originalTypeName = str_replace('\\', '', $typeName);
        $parts = explode('\\', $typeName, 2);
        if (count($parts) > 1) {
            if (str_starts_with($parts[1], $parts[0])) {
                $typeName = $parts[1];
            }
        }
        $typeName = str_replace('\\', '', $typeName);

        if (isset($this->types[$typeName])) {
            if ($this->types[$typeName]['class'] === $enum) {
                return $this->types[$typeName];
            }

            $typeName = $originalTypeName;
        }

        return $this->types[$typeName] = [
            'name' => $typeName,
            'file' => 'interfaces/Enum',
            'type' => 'native_enum',
            'class' => $enum,
            'doc_block' => [
                '@see ' . str_replace($this->projectDir . '/', '', (new \ReflectionClass($enum))->getFileName()),
            ],
        ];
    }

    private function extractOperationMetadata(): void
    {
        foreach ($this->container->get(ResourceNameCollectionFactoryInterface::class)->create() as $resourceName) {
            $matchingNamespaces = array_filter(
                $this->options['model_metadata']['namespaces'] ?? [],
                static fn (string $namespace) => str_starts_with($resourceName, $namespace)
            );
            if (!$matchingNamespaces) {
                continue;
            }

            foreach ($this->container->get(ResourceMetadataCollectionFactoryInterface::class)->create($resourceName) as $resourceMetadata) {
                $this->operations[$resourceName] = [
                    'name' => $resourceMetadata->getShortName(),
                    'file' => 'endpoint/' . $resourceMetadata->getShortName(),
                    'operations' => [],
                    'depends' => [],
                    'types' => [],
                    'security' => $resourceMetadata->getSecurity(),
                    'securityPostDenormalize' => $resourceMetadata->getSecurityPostDenormalize(),
                ];
                foreach ($resourceMetadata->getOperations() as $operation) {
                    $operationName = match (true) {
                        $operation instanceof GetCollection => 'list',
                        $operation instanceof Get => 'read',
                        $operation instanceof Put => 'replace',
                        $operation instanceof Patch => 'update',
                        $operation instanceof Post => 'create',
                        $operation instanceof Delete => 'remove',
                        default => throw new \InvalidArgumentException(sprintf('Unknown operation "%s".', get_debug_type($operation))),
                    };

                    if ('list' === $operationName) {
                        $listTypeName = $resourceMetadata->getShortName() . 'ListParams';
                        $this->types[$listTypeName] = [
                            'name' => $listTypeName,
                            'file' => 'endpoint/' . $resourceMetadata->getShortName(),
                            'generate' => false,
                        ];
                        $this->operations[$resourceName]['types'][$listTypeName] = [
                            'name' => $listTypeName,
                            'file' => 'endpoint/' . $resourceMetadata->getShortName(),
                            'type' => 'interface',
                            'extends' => 'ListParams',
                            'properties' => [],
                        ];
                        $this->operations[$resourceName]['depends']['interfaces/ApiTypes']['ListParams'] = 'ListParams';

                        $filteredFields = [];
                        foreach ($operation->getFilters() as $filterName) {
                            /** @var FilterInterface $filter */
                            $filter = $this->container->get('filters')->get($filterName);
                            foreach ($filter->getDescription($resourceName) as $filteredField) {
                                if (!isset($this->types[$resourceMetadata->getShortName()])) {
                                    $this->extractModelMetadataForModel($resourceMetadata->getClass());
                                }
                                if (!isset($this->types[$resourceMetadata->getShortName()]['properties'][$filteredField['property']])) {
                                    throw new \Exception(sprintf('Property "%s" of %s is not exposed or does not exist while being used in an ApiFilter. Did you #[Ignore] it?', $filteredField['property'], $resourceMetadata->getShortName()));
                                }
                                $propertyMetadata = $this->types[$resourceMetadata->getShortName()]['properties'][$filteredField['property']];
                                foreach ($propertyMetadata['depends'] ?? [] as $file => $items) {
                                    foreach ($items as $item) {
                                        $this->operations[$resourceName]['depends'][$file][$item] = $item;
                                    }
                                }
                                $type = $filteredField['type'];
                                if ($propertyMetadata['api_platform_schema'] && ($propertyMetadata['api_platform_schema']['type'] === $type || is_array($propertyMetadata['api_platform_schema']['type']) && in_array($type, $propertyMetadata['api_platform_schema']['type']))) {
                                    $format = $propertyMetadata['api_platform_schema']['format'] ?? null;
                                    if ($format === 'iri-reference') {
                                        $type = 'HydraIri';
                                    } else {
                                        $type = $propertyMetadata['type'];
                                        if (isset($this->types[$type]) && 'builtin' !== $this->types[$type]['type']) {
                                            $this->operations[$resourceName]['depends'][$this->types[$type]['file']][] = $type;
                                        }
                                    }
                                }
                                $type = match ($type) {
                                    'int' => 'number',
                                    'float' => 'number',
                                    'bool' => 'boolean',
                                    default => $type,
                                };
                                if (!isset($filteredFields[$filteredField['property']])) {
                                    $filteredFields[$filteredField['property']] = [
                                        'type' => $type,
                                        'types' => [$type],
                                        'required' => $filteredField['required'],
                                        'is_collection' => $filteredField['is_collection'],
                                    ];
                                } else {
                                    $filteredFields[$filteredField['property']]['types'][] = $type;
                                    $filteredFields[$filteredField['property']]['types'] = array_unique($filteredFields[$filteredField['property']]['types']);
                                    $filteredFields[$filteredField['property']]['required'] = $filteredField['required'] || $filteredFields[$filteredField['property']]['required'];
                                    $filteredFields[$filteredField['property']]['is_collection'] = $filteredField['is_collection'] || $filteredFields[$filteredField['property']]['is_collection'];
                                }
                            }

                            unset($filteredField);
                            foreach ($filteredFields as &$filteredField) {
                                if (count($filteredField['types']) > 1) {
                                    $filteredField['type'] = implode('|', $filteredField['types']);
                                }
                                if ($filteredField['is_collection']) {
                                    $filteredField['type'] .= '|Array<' . $filteredField['type'] . '>';
                                }
                            }
                            unset($filteredField);

                            $this->operations[$resourceName]['types'][$listTypeName]['properties'] = $filteredFields;
                        }
                    }

                    $this->operations[$resourceName]['operations'][$operationName] = [
                        'method' => $operation->getMethod(),
                        'path' => preg_replace('#\{\._format}$#', '', $operation->getUriTemplate()),
                        'security' => $operation->getSecurity(),
                        'securityPostDenormalize' => $operation->getSecurityPostDenormalize(),
                        'class' => $resourceName,
                        'input' => $operation->getInput(),
                        'output' => $operation->getOutput(),
                    ];
                    if ($operationName === 'list') {
                        $this->operations[$resourceName]['operations'][$operationName]['listType'] = $listTypeName;
                    } elseif (in_array($operationName, ['read', 'replace', 'update'])) {
                        $this->operations[$resourceName]['operations'][$operationName]['path'] = preg_replace('#/\{.+?}$#', '', $this->operations[$resourceName]['operations'][$operationName]['path']);
                    }
                }
            }
        }
    }

    private function buildEndpointFiles(): void
    {
        foreach ($this->operations as $resourceDefinition) {
            $currentFileDir = dirname($this->outputDir . $resourceDefinition['file']);
            $imports = [
                'ApiMethods' => [],
            ];
            $importLines = $lines = $typeLines = [];
            if ($resourceDefinition['security']) {
                $lines[] = sprintf('// %s', $resourceDefinition['security']);
            }
            $lines[] = 'export default {';
            $lines[] = sprintf('    %s: {', lcfirst($resourceDefinition['name']));
            foreach ($resourceDefinition['depends'] ?? [] as $file => $types) {
                foreach ($types as $type) {
                    $imports[$file][$type] = $type;
                }
            }
            foreach ($resourceDefinition['operations'] as $operationName => $operation) {
                $input = $operation['input'] ?? $operation['class'];
                $output = $operation['output'] ?? $operation['class'];
                $typeMap = [];
                foreach (array_unique([$input, $output]) as $resource) {
                    $targetType = null;
                    foreach ($this->types as $type) {
                        if (($type['class'] ?? null) === $resource) {
                            $targetType = $type;
                            break;
                        }
                    }
                    if ($targetType) {
                        $imports[$targetType['file']][$targetType['name']] = $targetType['name'];
                        $typeMap[$resource] = $targetType['name'];
                    }
                }

                $imports['ApiMethods'][$operationName] = $operationName;
                $genericParams = match ($operationName) {
                    'list' => [$typeMap[$input] ?? 'any', $operation['listType']],
                    default => [$typeMap[$input] ?? 'any']
                };
                $args = [json_encode($operation['path'], \JSON_UNESCAPED_SLASHES)];
                if ($operation['security']) {
                    $lines[] = sprintf('        // @security: %s', $operation['security']);
                }
                if ($resourceDefinition['securityPostDenormalize']) {
                    $lines[] = sprintf('// @securityPostDenormalize: %s', $resourceDefinition['securityPostDenormalize']);
                }
                $lines[] = sprintf('        %s: %s<%s>(%s),', $operationName, $operationName, implode(', ', $genericParams), implode(', ', $args));
            }
            $lines[] = '    }';
            $lines[] = '}';

            foreach ($resourceDefinition['types'] ?? [] as $typeName => $typeDefinition) {
                $typeLines = [...$typeLines, ...$this->buildType($typeName, $typeDefinition, $resourceDefinition['file'], $imports)];
            }

            foreach ($imports as $file => $typeNames) {
                $targetFileDir = dirname($this->outputDir . $file);
                $importLines[] = sprintf('import { %s } from "%s";', implode(', ', $typeNames), $this->filesystem->makePathRelative($targetFileDir, $currentFileDir) . basename($file));
            }

            if ($typeLines) {
                $lines = [...$typeLines, '', ...$lines];
            }

            if ($importLines) {
                $lines = [...$importLines, '', ...$lines];
            }

            $this->files[$resourceDefinition['file']] = implode("\n", $lines) . "\n";
        }
    }

    private function buildModelFiles(): void
    {
        $byFile = [];
        foreach ($this->types as $type => $metadata) {
            if (($metadata['generate'] ?? null) === false) {
                continue;
            }
            if ($metadata['type'] === 'builtin') {
                continue;
            }

            $fileName = $metadata['file'] ?? null;
            $byFile[$fileName] ??= [];
            $byFile[$fileName][$type] = $metadata;
            if (!$fileName) {
                throw new \InvalidArgumentException(sprintf('Type "%s" does not have a file name.', $type));
            }
        }

        foreach ($byFile as $fileName => $types) {
            $currentFileDir = dirname($this->outputDir . $fileName);
            $fileBody = [];

            $imports = [];
            $importsBody = [];
            foreach ($types as $type => $metadata) {
                foreach ($metadata['depends'] ?? [] as $file => $typeNames) {
                    foreach ($typeNames as $typeName) {
                        $imports[$file][$typeName] = $typeName;
                    }
                }
            }

            foreach ($types as $type => $metadata) {
                if (!empty($fileBody)) {
                    $fileBody[] = '';
                }
                $fileBody = [...$fileBody, ...$this->buildType($type, $metadata, $fileName, $imports)];
            }

            if ($imports) {
                foreach ($imports as $file => $typeNames) {
                    $targetFileDir = dirname($this->outputDir . $file);
                    $importsBody[] = sprintf('import { %s } from "%s";', implode(', ', $typeNames), $this->filesystem->makePathRelative($targetFileDir, $currentFileDir) . basename($file));
                }

                $fileBody = [...$importsBody, '', ...$fileBody];
            }

            if (isset($this->fragments[$fileName]['fragments'])) {
                foreach ($this->fragments[$fileName]['fragments'] as $key => $fragment) {
                    $fileBody = [...$fileBody, '', ...$fragment];
                }
            }

            $this->files[$fileName] = implode("\n", $fileBody)."\n";
        }
    }

    private function buildRouteFile(): void
    {
        $lines = [
            'import {RouteInterface} from "./Router";',
            'import type { Component } from \'vue\';',
            'import {RouteRecordRaw} from "vue-router";',
            '',
            'declare global {',
            '    interface Window {',
            '        resolveVueComponent: (name: string) => Promise<Component>;',
            '    }',
            '}',
            '',
        ];

        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);
        foreach ($this->container->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            $typescriptName = $name;
            if (str_starts_with($typescriptName, '_') || str_starts_with($typescriptName, 'api_')) {
                continue;
            }

            $typescriptName = u($typescriptName)->snake()->upper()->toString();
            $config = ['name' => $name, 'path' => $route->getPath(), 'vars' => new \ArrayObject(), 'defaults' => new \ArrayObject(), 'meta' => new \ArrayObject()];
            $config['path'] = preg_replace_callback('#\{(.+?)}#', function (array $matches) use (&$config, $route) {
                $name = $matches[1];
                $requirements = null;
                if (preg_match('#^(.+?)<(.+?)>$#', $name, $matches)) {
                    $name = $matches[1];
                    $requirements = $matches[2];
                }

                if ($route->hasDefault($name)) {
                    $config['defaults'][$name] = $route->getDefault($name);
                }

                $config['vars'][$name] = [
                    'isRequired' => !$route->hasDefault($name),
                ];
                return '{'.$name.'}';
            }, $config['path']);
            if (preg_match('#^\\d+#', $typescriptName)) {
                $typescriptName = 'COMPAT_'.$typescriptName;
            }

            $event = new ManipulateRouteMetadataEvent($name, $route, $typescriptName, $config);
            $eventDispatcher->dispatch($event);
            if (!$event->shouldGenerate) {
                continue;
            }

            $config = $event->typescriptName;
            $config = $event->config;

            $lines[] = sprintf('export const %s: RouteInterface = %s;', $typescriptName, json_encode($config));
        }

        if (isset($this->fragments['routes']['fragments'])) {
            foreach ($this->fragments['routes']['fragments'] as $key => $fragment) {
                $lines = [...$lines, '', ...$fragment];
            }
        }

        $this->files['routes'] = implode("\n", $lines)."\n";
    }

    private function dumpFiles(): int
    {
        if ($isDryRun = $this->input->getOption('dry-run')) {
            $this->io->note('Dry run enabled. No files will be written (or removed).');
        }

        $changed = 0;
        foreach ($this->files as $fileName => $fileBody) {
            $relativePath = 'assets/api/' . $fileName . '.ts';
            $path = $this->projectDir . '/' . $relativePath;
            $existingFile = $this->filesystem->exists($path) ? file_get_contents($path) : null;

            if ($existingFile) {
                $lines = explode("\n", $existingFile);
                $body = [];
                $header = [];
                $isHeaderLine = true;
                $annotations = [];
                foreach ($lines as $line) {
                    if ($isHeaderLine && $line && !str_starts_with($line, '//')) {
                        $isHeaderLine = false;
                    }
                    if ($isHeaderLine && preg_match('#^//\\s*@([\\w_-]+)\\s*#', $line, $matches)) {
                        $annotations[] = strtolower($matches[1]);
                    }
                    if ($isHeaderLine) {
                        $header[] = $line;
                    } else {
                        $body[] = $line;
                    }
                }

                $body = implode("\n", $body);

                if ($body === $fileBody) {
                    continue;
                }

                if (in_array('no-regenerate', $annotations)) {
                    continue;
                }
            }

            $this->io->writeln(sprintf('Writing <info>%s</info>...', $relativePath));

            $header = [
                sprintf('// This file was last generated with bin/console %s on %s', $this->getName(), $this->container->get(ClockInterface::class)->now()->format(\DateTimeInterface::ATOM)),
                '// DO NOT EDIT! IF you need to edit this file, add the "no-regenerate" annotation on the next line: "// @no-regenerate".',
                '',
                '',
            ];
            $fileBody = implode("\n", $header) .  $fileBody;
            if (!$isDryRun) {
                $this->filesystem->dumpFile($path, $fileBody);
            }
            $changed++;
        }

        $existingFiles = iterator_to_array((new Finder())->in([$this->outputDir . 'interfaces'])->name('*.ts'));
        foreach ($existingFiles as $existingFile) {
            $fullPath = (string) $existingFile;
            $relativePath = str_replace($this->outputDir, '', $fullPath);
            $fileName = substr($relativePath, 0, -3);
            if (isset($this->files[$fileName])) {
                continue;
            }
            $this->io->writeln('<comment>WARNING!</comment> Removing <info>' . $relativePath . '</info>...');
            if (!$isDryRun) {
                $this->filesystem->remove($fullPath);
            }
            $changed++;
        }

        return $changed;
    }

    private function buildType(string $type, array $metadata, string $filename, array &$imports): array
    {
        if ($metadata['type'] === 'type') {
            return [sprintf('export type %s = %s;', $type, $metadata['alias'])];
        }

        if ($metadata['type'] === 'interface') {
            $lines = [];
            foreach ($metadata['doc_block'] ?? [] as $docBlockLine) {
                $lines[] = sprintf('// %s', $docBlockLine);
            }
            if ($metadata['generic'] ?? null) {
                $lines[] = sprintf('export interface %s<' . $metadata['generic'] . '>%s {', $type, isset($metadata['extends']) ? ' extends ' . $metadata['extends'] : '');
            } else {
                $lines[] = sprintf('export interface %s%s {', $type, isset($metadata['extends']) ? ' extends ' . $metadata['extends'] : '');
            }
            foreach ($metadata['properties'] as $property => $propertyMetadata) {
                $escapedPropertyName = $property;
                if (str_starts_with($property, '@') || str_contains($property, ':')) {
                    $escapedPropertyName = sprintf('"%s"', $property);
                }

                $type = $propertyMetadata['type'];
                if (isset($this->types[$type]) && $this->types[$type]['type'] !== 'builtin' && $filename !== $this->types[$type]['file']) {
                    $imports[$this->types[$type]['file']][$type] = $type;
                }
                $isRequired = $propertyMetadata['required'] ?? false;
                $isReadOnly = $propertyMetadata['readOnly'] ?? false;
                $lines[] = sprintf(
                    '    %s%s%s: %s;',
                    $isReadOnly ? 'readonly ' : '',
                    $escapedPropertyName,
                    $isRequired ? '' : '?',
                    $type,
                );
            }

            $lines[] = '}';

            return $lines;
        }

        if ($metadata['type'] === 'native_enum') {
            $lines = [];
            foreach ($metadata['doc_block'] ?? [] as $docBlockLine) {
                $lines[] = sprintf('// %s', $docBlockLine);
            }
            $lines[] = sprintf('export enum %s {', $type);
            $class = $metadata['class'];
            $reflection = new \ReflectionEnum($class);
            if ($reflection->isBacked()) {
                foreach ($class::cases() as $case) {
                    $lines[] = sprintf('    %s = %s,', $case->name, json_encode($case->value));
                }
            } else {
                foreach ($class::cases() as $case) {
                    $lines[] = sprintf('    %s,', $case->name);
                }
            }
            $lines[] = '}';

            return $lines;
        }

        if ($metadata['type'] === 'enum') {
            $lines = [];
            foreach ($metadata['doc_block'] ?? [] as $docBlockLine) {
                $lines[] = sprintf('// %s', $docBlockLine);
            }
            $lines[] = sprintf('export enum %s {', $type);
            if (array_is_list($metadata['values'])) {
                foreach ($metadata['values'] as $name) {
                    $lines[] = sprintf('    %s,', $name);
                }
            } else {
                foreach ($metadata['values'] as $name => $value) {
                    $lines[] = sprintf('    %s = %s,', $name, json_encode($value));
                }
            }
            $lines[] = '}';

            return $lines;
        }

        throw new \InvalidArgumentException(sprintf('Unknown type "%s".', $metadata['type']));
    }

    private function resolveCollectionType(Type $type, array &$generatedType): string
    {
        $keyType = $type->getCollectionKeyTypes()[0]->getBuiltinType();
        $isArray = $keyType === 'int';
        $valueType = $type->getCollectionValueTypes()[0];
        $actualValueType = 'any';
        if ($valueType->getClassName()) {
            $doctrineMetadata = null;
            try {
                $doctrineMetadata = $this->getEntityManager()->getMetadataFactory()->getMetadataFor($valueType->getClassName());
            } catch (\Throwable) {
            }

            if ($doctrineMetadata) {
                $doctrineMetadata = $this->extractModelMetadataForModel($valueType->getClassName());
                $actualValueType = $doctrineMetadata['name'];
                $generatedType['depends'][$doctrineMetadata['file']][] = $actualValueType;
            }
        }

        return $isArray ? 'Array<' . $actualValueType . '>' : sprintf('{ [key: %s]: %s }', $keyType, $actualValueType);
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->container->get(EntityManagerInterface::class);
    }
}
