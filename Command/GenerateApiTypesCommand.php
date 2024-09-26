<?php

declare(strict_types=1);

namespace Ovrflo\ApiPlatformTypescriptGeneratorBundle\Command;

use ApiPlatform\Doctrine\Orm\Filter\BackedEnumFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\NotExposed;
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
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Service\Attribute\SubscribedService;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use function Symfony\Component\String\u;

#[AsCommand(
    name: 'ovrflo:api-platform:typescript:generate',
    description: 'Generate typescript types for api-platform resources/endpoints.',
)]
final class GenerateApiTypesCommand extends Command implements ServiceSubscriberInterface
{
    private array $types = [];
    private array $operations = [];
    private array $files = [];

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
            ValidatorInterface::class,
            EventDispatcherInterface::class,
            ClassMetadataFactoryInterface::class,
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
        $this->linkDependantOperations();
        $this->addPayloadManipulationMethods();
        $this->dispatchManipulateMetadataEvent();
        $this->buildModelFiles();
        $this->buildEndpointFiles();
        $this->buildRouteFile();
        $this->buildApiMethodsFile();
        $this->dispatchManipulateFilesEvent();
        $changedFileCount = $this->dumpFiles();

        if (!$changedFileCount) {
            $this->io->writeln('No files changed.');
        } else {
            $this->io->writeln(sprintf('Done. <info>%d</info> files changed.', $changedFileCount));
        }

        return Command::SUCCESS;
    }

    private function addPayloadManipulationMethods(): void
    {
        foreach ($this->types as $typeName => $typeInfo) {
            if (!($typeInfo['is_doctrine_entity'] ?? false)) {
                continue;
            }

            $imports = [];
            $lines = [];
            $relatedEntities = [];
            foreach ($typeInfo['properties'] as $property => $propertyMetadata) {
                if (isset($propertyMetadata['model']['class'])) {
                    $relatedEntities[$property] = $propertyMetadata;
                }
            }

            $lines[] = '';
            $lines[] = sprintf('export function prepare%1$sPayload(entity: Partial<%1$s>, excludedFields?: Array<keyof %1$s>, fields?: Array<keyof %1$s>): Partial<%s> {', $typeInfo['name']);
            if ($relatedEntities) {
                $lines[] = sprintf('    const allFields = %s;', json_encode(array_keys($relatedEntities)));
                $lines[] = sprintf('    let fieldsToUse = (fields || allFields);');
                $lines[] = sprintf('    if (excludedFields) {');
                $lines[] = sprintf('        fieldsToUse = fieldsToUse.filter(field => !excludedFields.includes(field));');
                $lines[] = sprintf('    }');
                $lines[] = sprintf('    if (!fieldsToUse.length) {');
                $lines[] = sprintf('        return entity;');
                $lines[] = sprintf('    }');
                $lines[] = sprintf('    entity = {...entity};');
                foreach ($relatedEntities as $property => $propertyMetadata) {
                    $encodedProperty = json_encode($property);
                    $propertyTypeInfo = $this->extractModelMetadataForModel($propertyMetadata['model']['class']);
                    if ($propertyTypeInfo['is_api_resource'] ?? false) {
                        $operationsDefinition = $this->operations[$propertyMetadata['model']['class']];
                        $firstResource = reset($operationsDefinition['resources']) ?: null;
                        if (isset($firstResource['operations']['read']) || isset($firstResource['operations']['list'])) {
                            $generator = sprintf('generate%sIri', $propertyMetadata['model']['name']);
                            $imports[$operationsDefinition['file']][$generator] = $generator;
                            $lines[] = sprintf('    if (fieldsToUse.includes(%1$s) && %1$s in entity && entity[%1$s]) {', $encodedProperty);
                            if ($propertyMetadata['is_collection']) {
                                $lines[] = sprintf('        entity[%s] = entity[%1$s].map(item => %s(item));', $encodedProperty, $generator);
                            } else {
                                $lines[] = sprintf('        entity[%s] = %s(entity[%1$s]);', $encodedProperty, $generator);
                            }
                            $lines[] = sprintf('    }');
                            continue;
                        }
                    }

                    $targetPreparePayloadFunction = sprintf('prepare%sPayload', $propertyMetadata['model']['name']);
                    $imports[$propertyTypeInfo['file']][$targetPreparePayloadFunction] = $targetPreparePayloadFunction;
                    $lines[] = sprintf('    if (fieldsToUse.includes(%1$s) && %1$s in entity && entity[%1$s]) {', $encodedProperty);
                    if ($propertyMetadata['is_collection']) {
                        $lines[] = sprintf('        entity[%s] = entity[%1$s].map(item => %s(item));', $encodedProperty, $targetPreparePayloadFunction);
                    } else {
                        $lines[] = sprintf('        entity[%s] = %s(entity[%1$s]);', $encodedProperty, $targetPreparePayloadFunction);
                    }
                    $lines[] = sprintf('    }');
                }
            }
            $lines[] = '    return entity;';
            $lines[] = '}';

            $this->files[$typeInfo['file']][] = ['body' => implode("\n", $lines), 'priority' => -100, 'imports' => $imports];
        }
    }

    private function dispatchManipulateMetadataEvent(): void
    {
        $event = new ManipulateMetadataEvent($this->types, $this->operations, $this->files);
        $this->container->get(EventDispatcherInterface::class)->dispatch($event);
        $this->types = $event->types;
        $this->operations = $event->operations;
        $this->files = $event->files;
    }

    private function dispatchManipulateFilesEvent(): void
    {
        $event = new ManipulateFilesEvent($this->files);
        $this->container->get(EventDispatcherInterface::class)->dispatch($event);
        $this->files = $event->files;
    }

    private function loadBaseMetadata(): void
    {
        // hydra prefix can be disabled in >=3.4 and <4.0
        $defaultApiPlatformSerializerContext = $this->parameterBag->get('api_platform.serializer.default_context');
        $hydraPrefix = ($defaultApiPlatformSerializerContext['hydra_prefix'] ?? true) ? 'hydra:' : '';

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
            'HydraIri' => [
                'type' => 'type',
                'alias' => 'string',
                'file' => 'interfaces/ApiTypes',
                'generic' => 'T extends HydraItem',
            ],
        ];
        $this->types['HydraItem'] = [
            'file' => 'interfaces/ApiTypes',
            'type' => 'interface',
            'generic' => 'T',
            'properties' => [
                '@id' => [
                    'type' => 'HydraIri<T>',
                    'format' => 'iri-reference',
                    'required' => false,
                    'readOnly' => true,
                ],
                '@type' => [
                    'type' => 'string',
                    'required' => false,
                    'readOnly' => true,
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
                    'enum' => [$hydraPrefix.'Collection'],
                    'required' => true,
                ],
                '@context' => [
                    'type' => 'HydraIri',
                    'required' => true,
                ],
                $hydraPrefix.'totalItems' => [
                    'type' => 'number',
                    'required' => true,
                ],
                $hydraPrefix.'member' => [
                    'type' => 'Array<T>',
                    'required' => true,
                ],
                $hydraPrefix.'view' => [
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
                    'enum' => [$hydraPrefix.'PartialCollectionView'],
                ],
                '@id' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                ],
                $hydraPrefix.'first' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                ],
                $hydraPrefix.'last' => [
                    'type' => 'HydraIri',
                    'format' => 'iri-reference',
                ],
                $hydraPrefix.'next' => [
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
                    'enum' => [$hydraPrefix.'IriTemplateMapping'],
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
                    'enum' => [$hydraPrefix.'IriTemplate'],
                ],
                $hydraPrefix.'template' => [
                    'type' => 'string',
                ],
                $hydraPrefix.'variableRepresentation' => [
                    'type' => 'string',
                    'enum' => ['BasicRepresentation'],
                ],
                $hydraPrefix.'mapping' => [
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

        $this->types['Order'] = [
            'file' => 'interfaces/Enum',
            'type' => 'enum',
            'values' => [
                'Asc' => 'asc',
                'Desc' => 'desc',
            ],
        ];

        $this->types['ListParams'] = [
            'file' => 'interfaces/ApiTypes',
            'type' => 'interface',
            'generic' => 'T extends HydraItem',
            'properties' => [
            ],
        ];

        $apiPlatformConfiguration = [
            'hydra_prefix' => $hydraPrefix,
            'pagination' => [
                'enabled' => $this->parameterBag->get('api_platform.collection.pagination.enabled'),
                'clientEnabled' => $this->parameterBag->get('api_platform.collection.pagination.client_enabled'),
                'clientPartialEnabled' => $this->parameterBag->get('api_platform.collection.pagination.client_partial'),
                'pageParameter' => $this->parameterBag->get('api_platform.collection.pagination.page_parameter_name'),
            ],
            'itemsPerPage' => [
                'enabled' => $this->parameterBag->get('api_platform.collection.pagination.client_items_per_page'),
                'itemsPerPageParameter' => $this->parameterBag->get('api_platform.collection.pagination.items_per_page_parameter_name'),
                'maximumItemsPerPage' => $this->parameterBag->get('api_platform.collection.pagination.maximum_items_per_page'),
                'default' => $this->parameterBag->get('api_platform.collection.pagination.items_per_page'),
            ],
            'paginationClient' => [
                'enabled' => $this->parameterBag->get('api_platform.collection.pagination.client_enabled'),
                'parameter' => $this->parameterBag->get('api_platform.collection.pagination.enabled_parameter_name'),
            ],
            'partial' => [
                'enabled' => $this->parameterBag->get('api_platform.collection.pagination.client_partial'),
                'parameter' => $this->parameterBag->get('api_platform.collection.pagination.partial_parameter_name'),
            ],
            'order' => [
                'parameter' => $this->parameterBag->get('api_platform.collection.order_parameter_name'),
                'default' => $this->parameterBag->get('api_platform.collection.order'),
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
                $this->types['ListParams']['depends']['interfaces/Enum']['BooleanEnum'] = 'BooleanEnum';
                $this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.enabled_parameter_name')] = [
                    'type' => 'BooleanEnum',
                ];
            }
            if ($this->parameterBag->get('api_platform.collection.pagination.client_partial')) {
                $this->types['ListParams']['depends']['interfaces/Enum']['BooleanEnum'] = 'BooleanEnum';
                $this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.partial_parameter_name')] = [
                    'type' => 'BooleanEnum',
                ];
            }

            $this->types['ListParams']['depends']['interfaces/Enum']['Order'] = 'Order';
            $this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.order_parameter_name')] = [
                'type' => 'Record<string, Order>',
            ];
        }

        $generateIriFunction = [
            'export function generateIri<T extends HydraItem>(item: T|string|number|null, uriTemplate: string): null|HydraIri<T> {',
            '    if (item === null) {',
            '        return null;',
            '    }',
            '',
            '    if (typeof item === "object" && item["@id"] && typeof item["@id"] === "string") {',
            '        return item["@id"] as HydraIri<T>;',
            '    }',
            '',
            '    if (typeof item === "string" || typeof item === "number") {',
            '        if (uriTemplate.includes("{id}")) {',
            '            return uriTemplate.replace("{id}", encodeURIComponent(item.toString())) as HydraIri<T>;',
            '        }',
            '',
            '        return uriTemplate + "/" + encodeURIComponent(item.toString());',
            '    }',
            '',
            '    return null;',
            '}',
        ];

        $this->files['interfaces/ApiTypes'] ??= [];
        $this->files['interfaces/ApiTypes'][] = ['body' => 'export const apiPlatformConfig = ' . json_encode($apiPlatformConfiguration, JSON_PRETTY_PRINT) . ';'];
        $this->files['interfaces/ApiTypes'][] = ['body' => implode("\n", $generateIriFunction)];
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
            'factory' => true,
            'type' => 'interface',
            'extends' => [],
            'properties' => [],
            'is_api_resource' => $resourceMetadata !== null,
            'is_doctrine_entity' => $this->getEntityManager()->getMetadataFactory()->hasMetadataFor($resourceName),
            'api_resource' => $resourceMetadata ? [
                'short_name' => $resourceMetadata->getShortName(),
            ] : [],
            'doc_block' => [
                '@see ' . str_replace($this->projectDir . '/', '', $reflection->getFileName()),
            ],
        ];

        $parentType = null;
        if ($reflection->getParentClass()) {
            $parentType = $this->extractModelMetadataForModel($reflection->getParentClass()->getName());
            $generatedType['extends'][] = $parentType['name'];
            $generatedType['depends'][$parentType['file']] = [$parentType['name']];
        }

        if ($resourceMetadata) {
            $generatedType['extends'][] = 'HydraItem';
            $generatedType['depends']['interfaces/ApiTypes'][] = 'HydraItem';
        }

        $doctrineMetadataFactory = $this->getEntityManager()->getMetadataFactory();
        $resourceDoctrineMetadata = $doctrineMetadataFactory->hasMetadataFor($resourceName) ? $doctrineMetadataFactory->getMetadataFor($resourceName) : null;

        foreach ($this->container->get(PropertyNameCollectionFactoryInterface::class)->create($resourceName) as $propertyName) {
            $propertyMetadata = $this->container->get(PropertyMetadataFactoryInterface::class)->create($resourceName, $propertyName);
            if (!$propertyMetadata->isReadable() && !$propertyMetadata->isWritable()) {
                continue;
            }

            $isOwnProperty = true;
            try {
                $reflectionProperty = $reflection->getProperty($propertyName);
                $isOwnProperty = $reflectionProperty->getDeclaringClass()->name === $reflection->name;
            } catch (\Throwable) {
            }

            $tsTypes = [];
            $depends = [];
            $defaultValue = null;
            $extraProps = [
                'is_collection' => false,
                'collection_type' => null,
                'is_nullable' => false,
                'enum' => null,
            ];

            $builtinTypes = $propertyMetadata->getBuiltinTypes();
            foreach ($builtinTypes as $builtinType) {
                if ($builtinType->isNullable()) {
                    $tsTypes[] = 'null';
                    $extraProps['nullable'] = true;
                }

                if ($builtinType->getBuiltinType() === 'string') {
                    $tsTypes[] = 'string';
                    $defaultValue = json_encode($propertyMetadata->getDefault());
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'int' || $builtinType->getBuiltinType() === 'float') {
                    $tsTypes[] = 'number';
                    $defaultValue = json_encode($propertyMetadata->getDefault());
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'bool') {
                    $tsTypes[] = 'boolean';
                    $defaultValue = json_encode($propertyMetadata->getDefault());
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'null') {
                    $tsTypes[] = 'null';
                    $defaultValue = json_encode($propertyMetadata->getDefault());
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'array') {
                    $tsTypes[] = 'Array<any>';
                    $tsTypes[] = 'Record<number|string, any>';
                    $defaultValue = '[]';
                    $extraProps['is_collection'] = true;
                    continue;
                }

                if ($builtinType->getBuiltinType() === 'object') {
                    if ($builtinType->isCollection()) {
                        $extraProps['is_collection'] = true;
                        $extraProps['collection_type'] = $reflection->getName();
                        $collectionType = $this->resolveCollectionType($builtinType, $generatedType, $reflection->getName(), $extraProps);
                        $tsTypes[] = $collectionType;
                        $defaultValue = '[]';
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
                        $typeInfo = $this->buildTypeFromEnum($builtinType->getClassName(), 'interfaces/' . $typeName);
                        $tsTypes[] = $typeInfo['name'];
                        $depends[$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];
                        $generatedType['depends'][$typeInfo['file']][] = $typeInfo['name'];
                        if ($propertyMetadata->getDefault()) {
                            $defaultValue = $typeInfo['name'] . '.' . $propertyMetadata->getDefault()->name;
                        }
                        $extraProps['enum'] = ['class' => $builtinType->getClassName(), 'name' => $typeInfo['name']];
                        continue;
                    }

                    if ($builtinType->getClassName() && is_a($builtinType->getClassName(), AbstractUid::class, true)) {
                        $tsTypes[] = 'string';
                        continue;
                    }

                    $doctrineMetadata = null;
                    try {
                        $doctrineMetadata = $doctrineMetadataFactory->getMetadataFor($builtinType->getClassName());
                    } catch (\Throwable) {
                    }

                    if ($doctrineMetadata) {
                        $typeInfo = $this->extractModelMetadataForModel($builtinType->getClassName());
                        $tsTypes[] = $typeInfo['name'];
                        $extraProps['model'] = ['class' => $builtinType->getClassName(), 'name' => $typeInfo['name']];
                        if ($builtinType->getClassName() !== $reflection->getName()) {
                            $depends[$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];
                            $generatedType['depends'][$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];
                        }
                        if (in_array($builtinType->getClassName(), iterator_to_array($this->container->get(ResourceNameCollectionFactoryInterface::class)->create()))) {
                            $typeInfo = $this->extractModelMetadataForModel($builtinType->getClassName());
                            $tsTypes[] = 'HydraIri<'.$typeInfo['name'].'>';
                            $extraProps['model'] = ['class' => $builtinType->getClassName(), 'name' => $typeInfo['name']];
                            $depends[$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];
                            $depends['interfaces/ApiTypes']['HydraIri'] = 'HydraIri';
                            $generatedType['depends']['interfaces/ApiTypes']['HydraIri'] = 'HydraIri';
                        }
                        continue;
                    } elseif ($builtinType->getClassName()) {
                        $typeInfo = $this->extractModelMetadataForModel($builtinType->getClassName());
                        $tsTypes[] = $typeInfo['name'];
                        $extraProps['model'] = ['class' => $builtinType->getClassName(), 'name' => $typeInfo['name']];
                        $depends[$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];
                        $generatedType['depends'][$typeInfo['file']][$typeInfo['name']] = $typeInfo['name'];

                        continue;
                    }
                }

                if (in_array($builtinType->getClassName(), [null, UploadedFile::class, File::class])) {
                    $tsTypes[] = 'any';
                    continue;
                }

                throw new \InvalidArgumentException(sprintf('Unknown builtin type "%s" on %s::%s.', $builtinType->getClassName() ?? $builtinType->getBuiltinType(), $resourceName, $propertyName));
            }

            $tsTypes = array_unique($tsTypes);
            $generatedType['properties'][$propertyName] = [
                'type' => implode('|', $tsTypes ?: ['any']),
                'types' => $tsTypes,
                'api_platform_schema' => $propertyMetadata->getSchema(),
                'required' => $propertyMetadata->isRequired(),
                'readOnly' => $propertyMetadata->isReadable() && !$propertyMetadata->isWritable(),
                'depends' => $depends,
                'default' => $defaultValue,
                'is_own_property' => $isOwnProperty,
                ...$extraProps,
            ];
        }

        return $this->types[$typeName] = $generatedType;
    }

    public function buildTypeFromEnum(string $enum, ?string $file = null): array
    {
        $file ??= 'interfaces/Enum';
        $fqcnParts = explode('\\', $enum);
        $typeName = '';
        do {
            $typeName = array_pop($fqcnParts) . $typeName;
        } while (!empty($fqcnParts) && (isset($this->types[$typeName]) && $this->types[$typeName]['class'] !== $enum));

        if (isset($this->types[$typeName])) {
            if ($this->types[$typeName]['class'] === $enum) {
                $this->types[$typeName]['files'][$file] = true;
                return $this->types[$typeName];
            }
        }

        return $this->types[$typeName] = [
            'name' => $typeName,
            'file' => $file,
            'files' => [$file => true],
            'type' => 'native_enum',
            'class' => $enum,
            'doc_block' => [
                '@see ' . str_replace($this->projectDir . '/', '', (new \ReflectionClass($enum))->getFileName()),
            ],
        ];
    }

    private function extractOperationMetadata(): void
    {
        $apiPrefix = $this->options['api_prefix'] ?? '';
        foreach ($this->container->get(ResourceNameCollectionFactoryInterface::class)->create() as $resourceName) {
            $matchingNamespaces = array_filter(
                $this->options['model_metadata']['namespaces'] ?? [],
                static fn (string $namespace) => str_starts_with($resourceName, $namespace)
            );
            if (!$matchingNamespaces) {
                continue;
            }

            foreach ($this->container->get(ResourceMetadataCollectionFactoryInterface::class)->create($resourceName) as $resourceMetadata) {
                $filteredUriVariables = array_filter($resourceMetadata->getUriVariables(), static fn (Link $link) => $link->getFromClass() !== $resourceName);
                $firstUriVariable = reset($filteredUriVariables) ?: null;
                $resourceNamePrefix = '';
                if ($firstUriVariable) {
                    $resourceNamePrefix = lcfirst(basename(str_replace('\\', DIRECTORY_SEPARATOR, $firstUriVariable->getFromClass())));
                }
                $symbol = $resourceNamePrefix . ($resourceNamePrefix ? $resourceMetadata->getShortName() : lcfirst($resourceMetadata->getShortName()));
                $this->operations[$resourceName] ??= [
                    'name' => $resourceMetadata->getShortName(),
                    'class' => $resourceName,
                    'file' => 'endpoint/' . $resourceMetadata->getShortName(),
                    'resources' => [],
                    'depends' => [],
                    'types' => [],
                ];
                $this->operations[$resourceName]['resources'][$symbol] ??= [
                    'name' => $resourceMetadata->getShortName(),
                    'symbol' => $symbol,
                    'security' => $resourceMetadata->getSecurity(),
                    'securityPostDenormalize' => $resourceMetadata->getSecurityPostDenormalize(),
                    'operations' => [],
                ];
                foreach ($resourceMetadata->getOperations() as $operation) {
                    if ($operation instanceof NotExposed) {
                        continue;
                    }

                    $extraProperties = $operation->getExtraProperties() ?? [];

                    /** @var HttpOperation $operation */
                    $operationName = match (true) {
                        $operation instanceof GetCollection => 'list',
                        $operation instanceof Get => 'read',
                        $operation instanceof Put => 'replace',
                        $operation instanceof Patch => 'update',
                        $operation instanceof Post => 'create',
                        $operation instanceof Delete => 'remove',
                        $operation instanceof HttpOperation => match (strtolower($operation->getMethod())) {
                            'get' => 'read',
                            'post' => 'create',
                            'put' => 'replace',
                            'patch' => 'update',
                            'delete' => 'remove',
                            default => throw new \InvalidArgumentException(sprintf('Unknown operation "%s".', $operation->getMethod())),
                        },
                        default => throw new \InvalidArgumentException(sprintf('Unknown operation "%s".', get_debug_type($operation))),
                    };
                    $operationKind = $operationName;

                    if ($operation->getName() && !str_starts_with($operation->getName(), '_api_')) {
                        $operationName = $operation->getName();
                    }

                    $inputFormats = $operation->getInputFormats();
                    $isMultipart = isset($inputFormats['multipart']);

                    if ('list' === $operationKind) {
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
                            'extends' => ['ListParams<'.$resourceMetadata->getShortName().'>'],
                            'properties' => [],
                        ];
                        $this->operations[$resourceName]['depends']['interfaces/ApiTypes']['ListParams'] = 'ListParams';

                        $filteredFields = [];

                        $paginationEnabled = $operation->getPaginationEnabled() ?? $resourceMetadata->getPaginationEnabled() ?? $this->parameterBag->get('api_platform.collection.pagination.enabled');
                        $paginationClientItemsPerPage = $operation->getPaginationClientItemsPerPage() ?? $resourceMetadata->getPaginationClientEnabled() ?? $this->parameterBag->get('api_platform.collection.pagination.client_items_per_page');
                        $paginationClientEnabled = $operation->getPaginationClientEnabled() ?? $resourceMetadata->getPaginationClientEnabled() ?? $this->parameterBag->get('api_platform.collection.pagination.client_enabled');
                        $paginationClientPartial = $operation->getPaginationClientPartial() ?? $resourceMetadata->getPaginationClientPartial() ?? $this->parameterBag->get('api_platform.collection.pagination.client_partial');
                        if ($paginationEnabled && !isset($this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.page_parameter_name')])) {
                            $filteredFields[$this->parameterBag->get('api_platform.collection.pagination.page_parameter_name')] = [
                                'type' => 'number',
                                'types' => ['number'],
                                'required' => false,
                                'is_collection' => false,
                            ];
                        }
                        if ($paginationClientItemsPerPage && !isset($this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.items_per_page_parameter_name')])) {
                            $filteredFields[$this->parameterBag->get('api_platform.collection.pagination.items_per_page_parameter_name')] = [
                                'type' => 'number',
                                'types' => ['number'],
                                'required' => false,
                                'is_collection' => false,
                            ];
                        }
                        if ($paginationClientEnabled && !isset($this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.enabled_parameter_name')])) {
                            $filteredFields[$this->parameterBag->get('api_platform.collection.pagination.enabled_parameter_name')] = [
                                'type' => 'BooleanEnum',
                                'types' => ['boolean'],
                                'required' => false,
                                'is_collection' => false,
                            ];
                        }
                        if ($paginationClientPartial && !isset($this->types['ListParams']['properties'][$this->parameterBag->get('api_platform.collection.pagination.partial_parameter_name')])) {
                            $filteredFields[$this->parameterBag->get('api_platform.collection.pagination.partial_parameter_name')] = [
                                'type' => 'BooleanEnum',
                                'types' => ['boolean'],
                                'required' => false,
                                'is_collection' => false,
                            ];
                        }

                        foreach ($operation->getFilters() as $filterName) {
                            /** @var FilterInterface $filter */
                            $filter = $this->container->get('filters')->get($filterName);
                            if ($filter instanceof SearchFilter || $filter instanceof \ApiPlatform\Doctrine\Odm\Filter\SearchFilter) {
                                foreach ($filter->getDescription($resourceName) as $filteredField) {
                                    if (!isset($this->types[$resourceMetadata->getShortName()])) {
                                        $this->extractModelMetadataForModel($resourceMetadata->getClass());
                                    }

                                    $localPropertyName = $filteredField['property'];
                                    if (false !== strpos($localPropertyName, '.')) {
                                        $parts = explode('.', $localPropertyName);
                                        $localPropertyName = array_shift($parts);
                                    }
//                                    if (!isset($this->types[$resourceMetadata->getShortName()]['properties'][$filteredField['property']])) {
//                                        throw new \Exception(sprintf('Property "%s" of %s is not exposed or does not exist while being used in an ApiFilter. Did you #[Ignore] it?', $filteredField['property'], $resourceMetadata->getShortName()));
//                                    }
                                    $propertyMetadata = $this->types[$resourceMetadata->getShortName()]['properties'][$localPropertyName] ?? ['api_platform_schema' => []];
                                    foreach ($propertyMetadata['depends'] ?? [] as $file => $items) {
                                        foreach ($items as $item) {
                                            $this->operations[$resourceName]['depends'][$file][$item] = $item;
                                        }
                                    }
                                    $type = $filteredField['type'];
                                    if ($propertyMetadata['api_platform_schema'] && ($propertyMetadata['api_platform_schema']['type'] === $type || is_array($propertyMetadata['api_platform_schema']['type']) && in_array($type, $propertyMetadata['api_platform_schema']['type']))) {
                                        $format = $propertyMetadata['api_platform_schema']['format'] ?? null;
                                        if ($format === 'iri-reference') {
                                            $generic = '';
                                            if (isset($propertyMetadata['model']['class'])) {
                                                $type = $propertyMetadata['model']['name'];
                                                $typeInfo = $this->extractModelMetadataForModel($propertyMetadata['model']['class']);
                                                $generic = '<'.$type.'>';
                                                $this->operations[$resourceName]['depends'][$typeInfo['file']][$typeInfo['type']] = $type;
                                            }
                                            $type = 'HydraIri'.$generic;
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
                            } elseif ($filter instanceof OrderFilter || $filter instanceof \ApiPlatform\Doctrine\Odm\Filter\OrderFilter) {
                                $this->operations[$resourceName]['depends']['interfaces/Enum']['Order'] = 'Order';
                                $parameterName = null;
                                $fields = [];
                                foreach ($filter->getDescription($resourceName) as $fieldName => $field) {
                                    if (!$parameterName) {
                                        [$parameterName] = explode('[', $fieldName, 2);
                                    }
                                    $fields[] = '"'.$field['property'].'"';
                                }
                                $type = 'Partial<Record<' . implode('|', $fields) . ', Order>>';
                                $filteredFields[$parameterName] = [
                                    'type' => $type,
                                    'types' => [$type],
                                    'required' => false,
                                    'is_collection' => false,
                                ];
                            } elseif ($filter instanceof DateFilter || $filter instanceof \ApiPlatform\Doctrine\Odm\Filter\DateFilter) {
                                $dateTimeType = $this->types['DateTime'];
                                $this->operations[$resourceName]['depends'][$dateTimeType['file']]['DateTime'] = 'DateTime';
                                $parameters = [];
                                foreach ($filter->getDescription($resourceName) as $fieldName => $field) {
                                    [, $subfield] = explode('[', $fieldName, 2);
                                    $subfield = rtrim($subfield, ']');
                                    $parameters[$field['property']] ??= [];
                                    $parameters[$field['property']][] = '"'.$subfield.'"';
                                }

                                foreach ($parameters as $parameterName => $subfields) {
                                    if (isset($filteredFields[$parameterName])) {
                                        dump($filteredFields[$parameterName]);
                                    }

                                    $type = 'Partial<Record<' . implode('|', $subfields) . ', DateTime>>';
                                    $filteredFields[$parameterName] = [
                                        'type' => $type,
                                        'types' => [$type],
                                        'required' => false,
                                        'is_collection' => false,
                                    ];
                                }
                            } elseif ($filter instanceof BooleanFilter || $filter instanceof \ApiPlatform\Doctrine\Odm\Filter\BooleanFilter) {
                                foreach ($filter->getDescription($resourceName) as $fieldName => $field) {
                                    $filteredFields[$field['property']] = [
                                        'type' => 'boolean',
                                        'types' => ['boolean'],
                                        'required' => false,
                                        'is_collection' => false,
                                    ];
                                }
                            } elseif ($filter instanceof ExistsFilter || $filter instanceof \ApiPlatform\Doctrine\Odm\Filter\ExistsFilter) {
                                $parameterName = null;
                                $fields = [];
                                foreach ($filter->getDescription($resourceName) as $fieldName => $field) {
                                    if (!$parameterName) {
                                        [$parameterName] = explode('[', $fieldName, 2);
                                    }
                                    $fields[] = '"'.$field['property'].'"';
                                }
                                $type = 'Partial<Record<' . implode('|', $fields) . ', boolean>>';
                                $filteredFields[$parameterName] = [
                                    'type' => $type,
                                    'types' => [$type],
                                    'required' => false,
                                    'is_collection' => false,
                                ];
                            } elseif ($filter instanceof RangeFilter || $filter instanceof \ApiPlatform\Doctrine\Odm\Filter\RangeFilter) {
                                $fields = $operators = [];
                                foreach ($filter->getDescription($resourceName) as $fieldName => $field) {
                                    $operator = explode('[', $fieldName, 2)[1];
                                    $operator = rtrim($operator, ']');
                                    $fields[$field['property']] = true;
                                    $operators[] = '"'.$operator.'"';
                                }
                                $type = 'Partial<Record<' . implode('|', $operators) . ', string>>';
                                foreach (array_keys($fields) as $field) {
                                    $filteredFields[$field] = [
                                        'type' => $type,
                                        'types' => [$type],
                                        'required' => false,
                                        'is_collection' => false,
                                    ];
                                }
                            } elseif ($filter instanceof BackedEnumFilter || $filter instanceof \ApiPlatform\Doctrine\Odm\Filter\BackedEnumFilter) {
                                $fields = $operators = [];
                                foreach ($filter->getDescription($resourceName) as $fieldName => $filteredField) {
                                    $localPropertyName = $filteredField['property'];
                                    if (false !== strpos($localPropertyName, '.')) {
                                        $parts = explode('.', $localPropertyName);
                                        $localPropertyName = array_shift($parts);
                                    }
                                    $propertyMetadata = $this->types[$resourceMetadata->getShortName()]['properties'][$localPropertyName] ?? ['api_platform_schema' => []];
                                    if (isset($propertyMetadata['enum']['name'])) {
                                        $targetEnumType = $this->types[$propertyMetadata['enum']['name']];
                                        $filteredFields[$fieldName] = [
                                            'type' => $propertyMetadata['enum']['name'],
                                            'types' => ['null', $propertyMetadata['enum']['name']],
                                            'required' => false,
                                            'is_collection' => false,
                                        ];
                                        $this->operations[$resourceName]['depends'][$targetEnumType['file']][$propertyMetadata['enum']['name']] = $propertyMetadata['enum']['name'];
                                    } else {
                                        $type = 'null|'.implode('|', array_map(json_encode(...), $filteredField['schema']['enum']));
                                        $filteredFields[$fieldName] = [
                                            'type' => $type,
                                            'types' => ['null', $type],
                                            'required' => false,
                                            'is_collection' => false,
                                        ];
                                    }
                                }
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

                    if ($input = ($operation->getInput()['class'] ?? null)) {
                        $this->extractModelMetadataForModel($input);
                    }

                    if ($output = ($operation->getOutput()['class'] ?? null)) {
                        $this->extractModelMetadataForModel($output);
                    }

                    $path = $apiPrefix.preg_replace('#\{\._format}$#', '', $operation->getUriTemplate());
                    $uriVariables = [];
                    if (!$operation instanceof GetCollection && !$operation instanceof Post) {
                        $uriVariables = $resourceMetadata->getUriVariables() ?? [];
                    }
                    $uriVariables = [...$uriVariables, ...($operation->getUriVariables() ?? [])];
                    unset($uriVariables['id']);

                    $this->operations[$resourceName]['resources'][$symbol]['operations'][$operationName] = [
                        'kind' => $operationKind,
                        'method' => $operation->getMethod(),
                        'path' => $path,
                        'security' => $operation->getSecurity(),
                        'securityPostDenormalize' => $operation->getSecurityPostDenormalize(),
                        'class' => $resourceName,
                        'input' => $input ?? $resourceName,
                        'output' => $output ?? $resourceName,
                        'isMultipart' => $isMultipart,
                        'method' => $operation->getMethod(),
                        'api_request_method' => $extraProperties['api_request_method'] ?? null,
                        'mandatoryParams' => $uriVariables,
                        'uriTemplate' => $operation->getUriTemplate() ?? $resourceMetadata->getUriTemplate(),
                    ];
                    if ($operationName === 'list') {
                        $this->operations[$resourceName]['resources'][$symbol]['operations'][$operationName]['listType'] = $listTypeName;
                    } elseif (in_array($operationName, ['read', 'replace', 'update'])) {
                        $this->operations[$resourceName]['resources'][$symbol]['operations'][$operationName]['path'] = preg_replace('#/\{[a-zA-Z0-9_]+?}$#', '', $this->operations[$resourceName]['resources'][$symbol]['operations'][$operationName]['path']);
                    }
                }
            }
        }
    }

    private function linkDependantOperations(): void
    {
        $apiPrefix = $this->options['api_prefix'] ?? '';
        foreach ($this->container->get(ResourceNameCollectionFactoryInterface::class)->create() as $resourceName) {
            $matchingNamespaces = array_filter(
                $this->options['model_metadata']['namespaces'] ?? [],
                static fn (string $namespace) => str_starts_with($resourceName, $namespace)
            );
            if (!$matchingNamespaces) {
                continue;
            }

            foreach ($this->container->get(ResourceMetadataCollectionFactoryInterface::class)->create($resourceName) as $resourceMetadata) {$filteredUriVariables = array_filter($resourceMetadata->getUriVariables(), static fn (Link $link) => $link->getFromClass() !== $resourceName);
                $firstUriVariable = reset($filteredUriVariables) ?: null;
                $resourceNamePrefix = '';
                if ($firstUriVariable) {
                    $resourceNamePrefix = lcfirst(basename(str_replace('\\', DIRECTORY_SEPARATOR, $firstUriVariable->getFromClass())));
                }
                $symbol = $resourceNamePrefix . ($resourceNamePrefix ? $resourceMetadata->getShortName() : lcfirst($resourceMetadata->getShortName()));

                /** @var Link[] $filteredLinks */
                $filteredLinks = array_filter(
                    $resourceMetadata->getUriVariables(),
                    static fn(Link $link) => $link->getFromClass() !== $resourceName
                );
                if (!$filteredLinks) {
                    continue;
                }

                $mappedLinks = [];
                foreach ($filteredLinks as $link) {
                    $mappedLinks[$link->getFromClass()] = [
                        'class' => $link->getFromClass(),
                    ];
                }

                foreach ($filteredLinks as $link) {
                    $targetClass = $link->getFromClass();
                    $firstResource = reset($this->operations[$targetClass]['resources']);
                    $this->operations[$targetClass]['resources'][$firstResource['symbol']]['child_endpoints'] ??= [];
                    if (!isset($this->operations[$targetClass]['resources'][$firstResource['symbol']]['child_endpoints'][$link->getFromProperty()])) {
                        $this->operations[$targetClass]['resources'][$firstResource['symbol']]['child_endpoints'][$link->getFromProperty()] = [
                            'class' => $resourceName,
                            'name' => $symbol,
                        ];
                        $this->operations[$targetClass]['depends'][$this->operations[$resourceName]['file']][$symbol] = $symbol;
                    }
                }
            }
        }
    }

    private function buildEndpointFiles(): void
    {
        foreach ($this->operations as $resourceDefinition) {
            if (!$resourceDefinition['resources']) {
                continue;
            }

            $currentFileDir = dirname($this->outputDir . $resourceDefinition['file']);
            $imports = [
                'ApiMethods' => [],
            ];
            foreach ($resourceDefinition['depends'] ?? [] as $file => $types) {
                foreach ($types as $type) {
                    $imports[$file][$type] = $type;
                }
            }
            $importLines = $lines = $factoryLines = $typeLines = [];
            foreach ($resourceDefinition['resources'] as $symbolName => $resourceMetadata) {
                if (!$resourceMetadata['operations']) {
                    continue;
                }
                if ($resourceMetadata['security']) {
                    $lines[] = sprintf('// %s', $resourceMetadata['security']);
                    $factoryLines[] = sprintf('// %s', $resourceMetadata['security']);
                }
                $lines[] = sprintf('export const %s = {', $resourceMetadata['symbol']);
                $factoryLines[] = sprintf('export function %sEndpointFactory(defaultConfig?: RequestConfig) {', $resourceMetadata['symbol']);
                $factoryLines[] = sprintf('    return {', $resourceMetadata['symbol']);
                foreach ($resourceMetadata['operations'] as $operationName => $operation) {
                    $operationKind = $operation['kind'];
                    $typeMap = [];
                    $input = $operation['input'];
                    $output = $operation['output'];
                    foreach (array_filter(array_unique([$input, $output])) as $resource) {
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

                    $operationKind = $operation['api_request_method']['kind'] ?? $operationKind;

                    $mandatoryParams = array_keys($operation['mandatoryParams']);
                    $imports['ApiMethods'][$operationKind] = $operationKind;
                    $genericParams = $operation['api_request_method']['generic_params'] ?? match ($operationKind) {
                        'list' => [$typeMap[$input] ?? 'any', $operation['listType'], ...array_filter([implode("|", array_map(json_encode(...), $mandatoryParams))])],
                        'create', 'update', 'replace' => [...[$typeMap[$input] ?? 'any', $typeMap[$output] ?? $typeMap[$input] ?? 'any'], ...array_filter([implode("|", array_map(json_encode(...), $mandatoryParams))])],
                        'read' => [$typeMap[$input] ?? 'any', ...array_filter([implode("|", array_map(json_encode(...), $mandatoryParams))])],
                        default => [$typeMap[$input] ?? 'any']
                    };
                    $args = match ($operationKind) {
                        'download' => [json_encode($operation['path'], \JSON_UNESCAPED_SLASHES), json_encode($operation['method']), json_encode($mandatoryParams)],
                        'downloadAsString' => [json_encode($operation['path'], \JSON_UNESCAPED_SLASHES), json_encode($operation['method']), json_encode($mandatoryParams)],
                        default => [json_encode($operation['path'], \JSON_UNESCAPED_SLASHES), json_encode($mandatoryParams)],
                    };
                    if ($operation['security']) {
                        $lines[] = sprintf('    // @security: %s', $operation['security']);
                        $factoryLines[] = sprintf('        // @security: %s', $operation['security']);
                    }
                    if ($resourceMetadata['securityPostDenormalize']) {
                        $lines[] = sprintf('    // @securityPostDenormalize: %s', $resourceMetadata['securityPostDenormalize']);
                        $factoryLines[] = sprintf('        // @securityPostDenormalize: %s', $resourceMetadata['securityPostDenormalize']);
                    }
                    if (!$operation['isMultipart']) {
                        $lines[] = sprintf('    %s: %s<%s>(%s),', $operationName, $operationKind, implode(', ', $genericParams), implode(', ', $args));
                        $factoryLines[] = sprintf('        %s: %s<%s>(%s),', $operationName, $operationKind, implode(', ', $genericParams), implode(', ', $args));
                    } else {
                        $imports['ApiMethods']['multipart'] = 'multipart';
                        $lines[] = sprintf('    %s: %s<%s>(%s, %s),', $operationName, 'multipart', implode(', ', $genericParams), json_encode(strtoupper($operation['method'])), implode(', ', $args));
                        $factoryLines[] = sprintf('        %s: %s<%s>(%s, %s),', $operationName, 'multipart', implode(', ', $genericParams), json_encode(strtoupper($operation['method'])), implode(', ', $args));
                    }
                }

                foreach ($resourceMetadata['child_endpoints'] ?? [] as $property => $childEndpoint) {
                    $lines[] = sprintf('    %s: %s,', $property, $childEndpoint['name']);
                    $factoryLines[] = sprintf('        %s: %s,', $property, $childEndpoint['name']);
                }

                $lines[] = '}';
                $factoryLines[] = '    }';
                $factoryLines[] = '}';

                if (isset($resourceMetadata['operations']['read']) || isset($resourceMetadata['operations']['list'])) {
                    $imports['interfaces/ApiTypes']['HydraIri'] = 'HydraIri';
                    $imports['interfaces/ApiTypes']['generateIri'] = 'generateIri';
                    $lines[] = '';
                    $lines[] = sprintf('export function generate%sIri(id: Partial<%s>|string|number|null): HydraIri<%s> {', $resourceMetadata['name'], $resourceMetadata['name'], $resourceMetadata['name']);
                    $lines[] = sprintf('    return generateIri<%s>(id, %s);', $resourceMetadata['name'], json_encode(($resourceMetadata['operations']['read'] ?? $resourceMetadata['operations']['list'])['path']));
                    $lines[] = '}';
                }
            }

            $lines = [...$lines, '', ...$factoryLines];

            foreach ($resourceDefinition['types'] ?? [] as $typeName => $typeDefinition) {
                $typeLines = [...$typeLines, ...$this->buildType($typeName, $typeDefinition, $resourceDefinition['file'], $imports)];
            }

            if ($typeLines) {
                $lines = [...$typeLines, '', ...$lines];
            }

            $this->files[$resourceDefinition['file']] ??= [];
            $this->files[$resourceDefinition['file']][] = ['body' => implode("\n", $lines)."\n", 'imports' => $imports];
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

            uasort($types, function (array $a, array $b) {
                $selfDependenciesA = $a['depends'][$a['file']] ?? [];
                $selfDependenciesB = $b['depends'][$b['file']] ?? [];
                return count($selfDependenciesA) <=> count($selfDependenciesB);
            });

            foreach ($types as $type => $metadata) {
                if (!empty($fileBody)) {
                    $fileBody[] = '';
                }
                $fileBody = [...$fileBody, ...$this->buildType($type, $metadata, $fileName, $imports)];
            }

            $this->files[$fileName] ??= [];
            $this->files[$fileName][] = ['body' => implode("\n", $fileBody)."\n", 'imports' => $imports];
        }
    }

    private function buildRouteFile(): void
    {
        $importLines = [
            'import {RouteInterface,LocaleAwareRouteInterface} from "./Router";',
            'import type { Component } from \'vue\';',
            'import {RouteRecordRaw} from "vue-router";',
        ];

        $lines = [
            'declare global {',
            '    interface Window {',
            '        resolveVueComponent: (name: string) => Promise<Component>;',
            '    }',
            '}',
            '',
        ];

        $routeGroups = [];
        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);
        foreach ($this->container->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            $typescriptName = $name;
            if (str_starts_with($typescriptName, '_') || str_starts_with($typescriptName, 'api_')) {
                continue;
            }

            $typescriptName = u($typescriptName)->snake()->upper()->trim('_')->toString();
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

            if (\preg_match('#^(?<route>.+?)\\.(?<locale>\\w{2,3}(?:_\\w{2,3})?)$#', $name, $matches)
                && $route->hasRequirement('_locale')
                && $route->getRequirement('_locale') === $matches['locale']
            ) {
                $routeGroups[$matches['route']] ??= [];
                $routeGroups[$matches['route']][$matches['locale']] = $config;
            }

            $lines[] = sprintf('export const %s: RouteInterface = %s;', $typescriptName, json_encode($config));
        }

        foreach ($routeGroups as $routeName => $locales) {
            $typescriptName = u($routeName)->snake()->upper()->trim('_')->toString();
            $finalRouteConfig = $locales[$this->parameterBag->get('kernel.default_locale')] ?? reset($locales);
            $finalRouteConfig['name'] = $routeName;
            $finalRouteConfig['paths'] = array_combine(
                array_keys($locales),
                array_map(static fn ($locale) => $locales[$locale]['path'], array_keys($locales)),
            );
            $lines[] = sprintf('export const %s: LocaleAwareRouteInterface = %s;', $typescriptName, json_encode($finalRouteConfig));
        }

        $this->files['routes'] ??= [];
        $this->files['routes'][] = ['body' => implode("\n", $lines)."\n", 'importLines' => $importLines];
    }

    private function buildApiMethodsFile(): void
    {
        $content = file_get_contents(__DIR__ . '/../Resources/js/ApiMethods.ts');
        $apiEntryPointUrl = $this->container->get(RouterInterface::class)->generate('api_entrypoint', referenceType: RouterInterface::ABSOLUTE_URL);
        $parsed = parse_url($apiEntryPointUrl);
        $finalUrl = sprintf('%s://%s%s', $parsed['scheme'], $parsed['host'], isset($parsed['port']) ? ':'.$parsed['port'] : '');
        $content = str_replace('\'__API_BASE_URL__\'', json_encode($finalUrl), $content);

        $this->files['ApiMethods'] ??= [];
        $this->files['ApiMethods'][] = ['body' => $content];
    }

    private function dumpFiles(): int
    {
        if ($isDryRun = $this->input->getOption('dry-run')) {
            $this->io->note('Dry run enabled. No files will be written (or removed).');
        }

        $changed = 0;
        foreach ($this->files as $fileName => $fragments) {
            $imports = [];
            $importLines = [];
            foreach ($fragments as $fragment) {
                if ($fragmentImports = $fragment['imports'] ?? []) {
                    foreach ($fragmentImports as $file => $types) {
                        foreach ($types as $type) {
                            $imports[$file][$type] = $type;
                        }
                    }
                }
                if ($fragmentImportLines = $fragment['importLines'] ?? []) {
                    $importLines = [...$importLines, ...$fragmentImportLines];
                }
            }

            if ($imports || $importLines) {
                foreach ($imports as $file => $typeNames) {
                    $targetFileDir = dirname($this->outputDir . $file);
                    $importLines[] = sprintf('import { %s } from "%s";', implode(', ', $typeNames), $this->filesystem->makePathRelative($targetFileDir, dirname($this->outputDir.$fileName)).basename($file));
                }
                $fragments[] = ['body' => implode("\n", $importLines)."\n", 'priority' => 1100];
            }

            usort($fragments, static fn ($a, $b) => -1 * (($a['priority'] ?? 100) <=> ($b['priority'] ?? 100)));
            $fragments = array_filter($fragments, static fn ($fragment) => strlen($fragment['body'] ?? '') > 0);
            $fragmentBodies = array_map(static fn ($fragment) => trim($fragment['body'], "\n"), $fragments);
            $fileBody = trim(implode("\n\n", $fragmentBodies), "\n") . "\n";

            if (!$fileBody) {
                continue;
            }

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

        $existingFiles = iterator_to_array((new Finder())->in([$this->outputDir . 'interfaces', $this->outputDir . 'endpoint'])->name('*.ts'));
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

    public function buildType(string $type, array $metadata, string $filename, array &$imports): array
    {
        if ($metadata['type'] === 'type') {
            $generic = isset($metadata['generic']) ? '<' . $metadata['generic'] . '>' : '';
            return [sprintf('export type %s%s = %s;', $type, $generic, $metadata['alias'])];
        }

        if ($metadata['type'] === 'interface') {
            $lines = [];
            foreach ($metadata['doc_block'] ?? [] as $docBlockLine) {
                $lines[] = sprintf('// %s', $docBlockLine);
            }
            if ($metadata['generic'] ?? null) {
                $lines[] = sprintf('export interface %s<' . $metadata['generic'] . '>%s {', $type, isset($metadata['extends']) && $metadata['extends'] ? ' extends ' . implode(', ', $metadata['extends']) : '');
            } else {
                $lines[] = sprintf('export interface %s%s {', $type, isset($metadata['extends']) && $metadata['extends'] ? ' extends ' . implode(', ', $metadata['extends']) : '');
            }
            foreach ($metadata['properties'] as $property => $propertyMetadata) {
                if (false === ($propertyMetadata['is_own_property'] ?? true)) {
                    continue;
                }

                $escapedPropertyName = $property;
                if (str_starts_with($property, '@') || str_contains($property, ':') || str_contains($property, '.')) {
                    $escapedPropertyName = sprintf('"%s"', $property);
                }

                $propertyType = $propertyMetadata['type'];
                if (isset($this->types[$propertyType]) && $this->types[$propertyType]['type'] !== 'builtin' && $filename !== $this->types[$propertyType]['file']) {
                    $imports[$this->types[$propertyType]['file']][$propertyType] = $propertyType;
                }
                $isRequired = $propertyMetadata['required'] ?? false;
                $isReadOnly = $propertyMetadata['readOnly'] ?? false;
                $lines[] = sprintf(
                    '    %s%s%s: %s;',
                    $isReadOnly ? 'readonly ' : '',
                    $escapedPropertyName,
                    $isRequired ? '' : '?',
                    $propertyType,
                );
            }

            $lines[] = '}';
            if ($metadata['factory'] ?? false) {
                $lines[] = '';
                $lines[] = 'export function ' . lcfirst($type) . 'Factory('.lcfirst($type).': Partial<'.$type.'> = {}): ' . $type . ' {';
                $lines[] = sprintf('    return {');
                foreach ($metadata['properties'] as $property => $propertyMetadata) {
                    if ($propertyMetadata['readOnly'] ?? false) {
                        continue;
                    }
                    $lines[] = sprintf('        %s: %s,', preg_match('#^[a-zA-Z][a-zA-Z0-9_]*$#', $property) ? $property : json_encode($property), $propertyMetadata['default'] ?? 'null');
                }
                $lines[] = sprintf('        ...%s,', lcfirst($type));
                $lines[] = sprintf('    } as %s;', $type);
                $lines[] = '}';
            }

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

    private function resolveCollectionType(Type $type, array &$generatedType, string $selfClass, array &$extraProps): string
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
                $extraProps['is_collection'] = true;
                $extraProps['model'] = ['class' => $valueType->getClassName(), 'name' => $actualValueType];
                if ($valueType->getClassName() !== $selfClass) {
                    $generatedType['depends'][$doctrineMetadata['file']][] = $actualValueType;
                }
            }
        }

        return $isArray ? 'Array<' . $actualValueType . '>' : sprintf('{ [key: %s]: %s }', $keyType, $actualValueType);
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->container->get(EntityManagerInterface::class);
    }
}
