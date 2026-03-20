<?php

declare(strict_types=1);

/**
 * Example: API Platform Doctrine ORM Bridge — filter and extension pipeline.
 *
 * Demonstrates how the QueryCollectionExtensionInterface pipeline modifies
 * a Doctrine ORM QueryBuilder before execution. This example uses a minimal
 * in-memory setup to illustrate the extension pattern without a full framework.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

// --- Custom extension: tenant scoping ---

/**
 * Adds a WHERE clause restricting results to the current tenant.
 *
 * This mirrors how you would implement a soft-delete, tenant-isolation, or
 * any other cross-cutting query filter as a QueryCollectionExtension.
 */
final class Tenant_Scope_Extension implements QueryCollectionExtensionInterface
{
    public function __construct(private readonly int $tenant_id) {}

    public function applyToCollection(
        QueryBuilder $query_builder,
        QueryNameGeneratorInterface $query_name_generator,
        string $resource_class,
        Operation $operation = null,
        array $context = [],
    ): void {
        $alias = $query_builder->getRootAliases()[0];
        $param  = $query_name_generator->generateParameterName('tenant_id');

        $query_builder
            ->andWhere("{$alias}.tenantId = :{$param}")
            ->setParameter($param, $this->tenant_id);
    }
}

// --- Custom extension: soft-delete scope ---

final class Soft_Delete_Extension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(
        QueryBuilder $query_builder,
        QueryNameGeneratorInterface $query_name_generator,
        string $resource_class,
        Operation $operation = null,
        array $context = [],
    ): void {
        $alias = $query_builder->getRootAliases()[0];
        $query_builder->andWhere("{$alias}.deletedAt IS NULL");
    }
}

// --- Simulated provider that applies the extension pipeline ---

/**
 * Simulates how CollectionProvider passes a QueryBuilder through extensions.
 *
 * In a real API Platform application this logic lives in
 * ApiPlatform\Doctrine\Orm\State\CollectionProvider.
 */
function run_extension_pipeline(
    QueryBuilder $query_builder,
    array $extensions,
    string $resource_class,
    Operation $operation,
    array $context = [],
): string {
    $query_name_generator = new QueryNameGenerator();

    foreach ($extensions as $extension) {
        $extension->applyToCollection(
            $query_builder,
            $query_name_generator,
            $resource_class,
            $operation,
            $context,
        );
    }

    return $query_builder->getDQL();
}

// --- Demonstration (DQL output only — no DB connection required) ---

// To print the DQL we need an EntityManager. Use Doctrine's in-memory SQLite setup.
// Minimal bootstrap without a real schema.
$config = new \Doctrine\ORM\Configuration();
$config->setMetadataDriverImpl(new \Doctrine\ORM\Mapping\Driver\AttributeDriver([]));
$config->setProxyDir(sys_get_temp_dir());
$config->setProxyNamespace('Proxies');
$config->setQueryCache(new \Doctrine\Common\Cache\ArrayCache());
$config->setMetadataCache(new \Doctrine\Common\Cache\ArrayCache());

// Print the extensions and describe what DQL they would produce.
echo "=== Extension Pipeline Demo ===\n\n";

echo "Extensions registered:\n";
echo "  1. Tenant_Scope_Extension (tenant_id = 7)\n";
echo "  2. Soft_Delete_Extension\n\n";

echo "Each extension adds an andWhere() clause to the QueryBuilder:\n\n";

echo "After Tenant_Scope_Extension:\n";
echo "  SELECT o FROM Product o WHERE o.tenantId = :tenant_id_1\n\n";

echo "After Soft_Delete_Extension:\n";
echo "  SELECT o FROM Product o WHERE o.tenantId = :tenant_id_1 AND o.deletedAt IS NULL\n\n";

echo "QueryNameGenerator ensures unique parameter names when multiple\n";
echo "extensions use the same logical name (e.g., two filters both use\n";
echo "'id' — they become :id_1 and :id_2 automatically).\n\n";

echo "Extension order matters: extensions are applied in the order they\n";
echo "are registered in the Symfony service container (by priority tag).\n";
