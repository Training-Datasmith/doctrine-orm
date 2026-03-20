# Architecture: doctrine-orm

## Purpose

The API Platform Doctrine ORM bridge: integrates Doctrine ORM with the API Platform framework. It provides state providers, filters, and query extensions so API Platform can expose Doctrine entities as REST/GraphQL resources with built-in filtering, sorting, pagination, and eager loading.

## Directory Structure

```
State/
  CollectionProvider.php          — ProviderInterface: builds a QueryBuilder for collection GET operations
  ItemProvider.php                — ProviderInterface: fetches a single entity by identifier(s)
  LinksHandler.php                — Resolves URL variables (IRI identifiers) into QueryBuilder WHERE clauses
  LinksHandlerInterface.php       — Contract for custom link-resolution logic
  LinksHandlerTrait.php           — Default link resolution implementation shared by both providers
  Options.php                     — State options value object: override entity class per operation

Extension/
  QueryCollectionExtensionInterface.php        — Contract: modify QueryBuilder for collection queries
  QueryItemExtensionInterface.php              — Contract: modify QueryBuilder for item queries
  QueryResultCollectionExtensionInterface.php  — Contract: extension that also produces the final result
  QueryResultItemExtensionInterface.php        — Contract: extension that also produces the final item result
  FilterExtension.php             — Applies registered filters to the QueryBuilder
  FilterEagerLoadingExtension.php — Re-fetches filtered collections to enable correct JOIN/eager loading
  OrderExtension.php              — Applies ORDER BY from request parameters
  PaginationExtension.php         — Applies LIMIT/OFFSET; wraps result in Doctrine ORM Paginator
  EagerLoadingExtension.php       — Adds JOINs for relations to reduce N+1 queries

Filter/
  FilterInterface.php             — Contract: apply(QueryBuilder, queryNameGenerator, resourceClass, operation, context)
  AbstractFilter.php              — Base: property normalisation, Doctrine type lookups
  SearchFilter.php                — Equality / partial / start / end / word_start string matching
  OrderFilter.php                 — Sorts by specified properties (ASC/DESC)
  BooleanFilter.php               — Filters on boolean properties
  NumericFilter.php               — Filters on numeric properties (int, float, decimal)
  DateFilter.php                  — Filters on date/datetime properties with before/after/strictly operators
  RangeFilter.php                 — Filters with gte/gt/lte/lt range operators
  ExistsFilter.php                — Filters by IS NULL / IS NOT NULL presence

Paginator.php                     — Wraps DoctrineOrmPaginator; implements API Platform's PaginatorInterface
AbstractPaginator.php             — Shared iterator logic for Paginator
PropertyHelperTrait.php           — Resolves nested property paths through Doctrine metadata
QueryAwareInterface.php           — Marks objects that expose the underlying QueryBuilder

Util/
  QueryBuilderHelper.php          — JOIN deduplication; alias/parameter generation helpers
  QueryNameGenerator.php          — Generates unique parameter/alias names to avoid DQL conflicts
  QueryNameGeneratorInterface.php — Contract for QueryNameGenerator
  QueryChecker.php                — Inspects QueryBuilder for features that affect pagination correctness

Metadata/
  Property/
    DoctrineOrmPropertyMetadataFactory.php   — Enriches API Platform property metadata with ORM type info
  Resource/
    DoctrineOrmLinkFactory.php               — Derives API Platform link definitions from ORM associations
    DoctrineOrmResourceCollectionMetadataFactory.php  — Adds ORM-aware defaults to resource metadata
```

## Key Design Decisions

- **Extension pipeline** — `CollectionProvider` and `ItemProvider` pass a `QueryBuilder` through an ordered list of `QueryCollectionExtensionInterface` / `QueryItemExtensionInterface` implementations. Each extension (filter, order, pagination, eager loading) modifies the builder independently, keeping concerns separated.
- **Short-circuit result extensions** — extensions implementing `QueryResultCollectionExtensionInterface` can return the final result directly (e.g., `PaginationExtension` wraps the query in `DoctrineOrmPaginator`); the provider stops iterating once a result extension fires.
- **QueryNameGenerator** — all DQL parameter names and JOIN aliases are generated through `QueryNameGenerator` to guarantee uniqueness when multiple filters and extensions contribute to the same query.
- **State options override** — `Options::getEntityClass()` allows an API resource (DTO) to be backed by a different Doctrine entity, enabling the DTO pattern without losing ORM integration.
- **Eager loading correctness** — `FilterEagerLoadingExtension` re-executes a count/ID query before the main fetch to ensure pagination is correct when JOIN-based filters are applied, avoiding the classic "wrong item count with JOIN + LIMIT" bug.

## Extension Points

- Implement `QueryCollectionExtensionInterface` to add a custom query modifier (e.g., soft-delete filter, tenant scoping).
- Implement `FilterInterface` to create a custom filter type and register it as a Symfony service tagged `api_platform.filter`.
- Implement `LinksHandlerInterface` to customise how URL variables are resolved to Doctrine criteria.
- Use `Options` to map an API resource to an entity class different from the resource class.

## Dependency Flow

```
API Platform GET /resource_collection
  └── CollectionProvider::provide(operation, uriVariables)
        ├── LinksHandlerTrait::handleLinks() — WHERE clauses from URL variables
        └── QueryCollectionExtension pipeline:
              ├── FilterExtension — WHERE from request filters
              ├── OrderExtension  — ORDER BY from request params
              ├── EagerLoadingExtension — JOINs for relations
              └── PaginationExtension — LIMIT/OFFSET → DoctrineOrmPaginator → Paginator
                    → iterable result set → API Platform serialiser → JSON-LD / HAL response
```
