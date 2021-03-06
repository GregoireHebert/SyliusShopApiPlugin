<?php

declare(strict_types=1);

namespace Sylius\ShopApiPlugin\FilterExtension\Filters;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\QueryBuilder;
use Sylius\ShopApiPlugin\Exception\InvalidArgumentException;
use Sylius\ShopApiPlugin\FilterExtension\Util\QueryBuilderHelper;
use Sylius\ShopApiPlugin\FilterExtension\Util\QueryNameGenerator;
use Sylius\ShopApiPlugin\FilterExtension\Util\QueryNameGeneratorInterface;

/**
 * Filter the collection by given properties.
 *
 * @see       https://github.com/api-platform/core for the canonical source repository
 *
 * @copyright Copyright (c) 2015-present Kévin Dunglas
 * @license   https://github.com/api-platform/core/blob/master/LICENSE MIT License
 * @author Kévin Dunglas <dunglas@gmail.com>
 * @author Grégoire Hébert <gregoire@les-tilleuls.coop>
 */
class SearchFilter extends AbstractFilter
{
    /**
     * @var string Exact matching
     */
    public const STRATEGY_EXACT = 'exact';

    /**
     * @var string The value must be contained in the field
     */
    public const STRATEGY_PARTIAL = 'partial';

    /**
     * @var string Finds fields that are starting with the value
     */
    public const STRATEGY_START = 'start';

    /**
     * @var string Finds fields that are ending with the value
     */
    public const STRATEGY_END = 'end';

    /**
     * @var string Finds fields that are starting with the word
     */
    public const STRATEGY_WORD_START = 'word_start';

    /**
     * {@inheritdoc}
     *
     * @throws \invalidArgumentException
     */
    // protected function applyFilter(string $property, $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null)
    public function applyFilter(array $conditions, string $resourceClass, QueryBuilder $queryBuilder): void
    {
        if (empty($conditions['search'])) {
            return;
        }

        foreach ($conditions['search'] as $property => $strategies) {
            foreach ($strategies as $strategy => $value) {
                $queryNameGenerator = new  QueryNameGenerator();

                if (
                    null === $value ||
                    !$this->isPropertyMapped($property, $resourceClass, true)
                ) {
                    return;
                }

                $alias = $queryBuilder->getRootAliases()[0];
                $field = $property;

                if ($this->isPropertyNested($property, $resourceClass)) {
                    [$alias, $field, $associations] = $this->addJoinsForNestedProperty($property, $alias, $queryBuilder, $queryNameGenerator, $resourceClass);
                    $metadata = $this->getNestedMetadata($resourceClass, $associations);
                } else {
                    $metadata = $this->getClassMetadata($resourceClass);
                }

                $values = $this->normalizeValues((array) $value);

                if (empty($values)) {
                    $this->logger->notice('Invalid filter ignored', [
                        'exception' => new InvalidArgumentException(sprintf('At least one value is required, multiple values should be in "filter=[search][%1$s][%2$s][]=firstvalue&filter=[search][%1$s][%2$s][]=secondvalue" format', $property, $strategy)),
                    ]);

                    return;
                }

                $caseSensitive = true;

                if ($metadata->hasField($field)) {
                    if (!$this->hasValidValues($values, $this->getDoctrineFieldType($property, $resourceClass))) {
                        $this->logger->notice('Invalid filter ignored', [
                            'exception' => new InvalidArgumentException(sprintf('Values for field "%s" are not valid according to the doctrine type.', $field)),
                        ]);

                        return;
                    }

                    $strategy = $strategy ?? self::STRATEGY_EXACT;

                    // prefixing the strategy with i makes it case insensitive
                    if (0 === strpos($strategy, 'i')) {
                        $strategy = substr($strategy, 1);
                        $caseSensitive = false;
                    }

                    if (1 === \count($values)) {
                        $this->addWhereByStrategy($strategy, $queryBuilder, $queryNameGenerator, $alias, $field, $values[0], $caseSensitive);

                        return;
                    }

                    if (self::STRATEGY_EXACT !== $strategy) {
                        $this->logger->notice('Invalid filter ignored', [
                            'exception' => new InvalidArgumentException(sprintf('"%s" strategy selected for "%s" property, but only "%s" strategy supports multiple values', $strategy, $property, self::STRATEGY_EXACT)),
                        ]);

                        return;
                    }

                    $wrapCase = $this->createWrapCase($caseSensitive);
                    $valueParameter = $queryNameGenerator->generateParameterName($field);

                    $queryBuilder
                        ->andWhere(sprintf($wrapCase('%s.%s') . ' IN (:%s)', $alias, $field, $valueParameter))
                        ->setParameter($valueParameter, $caseSensitive ? $values : array_map('strtolower', $values));
                }

                // metadata doesn't have the field, nor an association on the field
                if (!$metadata->hasAssociation($field)) {
                    return;
                }

                if (!$this->hasValidValues($values, $this->getDoctrineFieldType($property, $resourceClass))) {
                    $this->logger->notice('Invalid filter ignored', [
                        'exception' => new InvalidArgumentException(sprintf('Values for field "%s" are not valid according to the doctrine type.', $field)),
                    ]);

                    return;
                }

                $association = $field;
                $valueParameter = $queryNameGenerator->generateParameterName($association);

                if ($metadata->isCollectionValuedAssociation($association)) {
                    $associationAlias = QueryBuilderHelper::addJoinOnce($queryBuilder, $queryNameGenerator, $alias, $association);
                    $associationField = 'id';
                } else {
                    $associationAlias = $alias;
                    $associationField = $field;
                }

                if (1 === \count($values)) {
                    $queryBuilder
                        ->andWhere(sprintf('%s.%s = :%s', $associationAlias, $associationField, $valueParameter))
                        ->setParameter($valueParameter, $values[0]);
                } else {
                    $queryBuilder
                        ->andWhere(sprintf('%s.%s IN (:%s)', $associationAlias, $associationField, $valueParameter))
                        ->setParameter($valueParameter, $values);
                }
            }
        }
    }

    /**
     * Adds where clause according to the strategy.
     *
     * @param string                      $strategy
     * @param QueryBuilder                $queryBuilder
     * @param QueryNameGeneratorInterface $queryNameGenerator
     * @param string                      $alias
     * @param string                      $field
     * @param mixed                       $value
     * @param bool                        $caseSensitive
     *
     * @throws InvalidArgumentException If strategy does not exist
     */
    protected function addWhereByStrategy(string $strategy, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $alias, string $field, $value, bool $caseSensitive)
    {
        $wrapCase = $this->createWrapCase($caseSensitive);
        $valueParameter = $queryNameGenerator->generateParameterName($field);

        switch ($strategy) {
            case null:
            case self::STRATEGY_EXACT:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%s.%s') . ' = ' . $wrapCase(':%s'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);

                break;
            case self::STRATEGY_PARTIAL:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%s.%s') . ' LIKE ' . $wrapCase('CONCAT(\'%%\', :%s, \'%%\')'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);

                break;
            case self::STRATEGY_START:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%s.%s') . ' LIKE ' . $wrapCase('CONCAT(:%s, \'%%\')'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);

                break;
            case self::STRATEGY_END:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%s.%s') . ' LIKE ' . $wrapCase('CONCAT(\'%%\', :%s)'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);

                break;
            case self::STRATEGY_WORD_START:
                $queryBuilder
                    ->andWhere(sprintf($wrapCase('%1$s.%2$s') . ' LIKE ' . $wrapCase('CONCAT(:%3$s, \'%%\')') . ' OR ' . $wrapCase('%1$s.%2$s') . ' LIKE ' . $wrapCase('CONCAT(\'%% \', :%3$s, \'%%\')'), $alias, $field, $valueParameter))
                    ->setParameter($valueParameter, $value);

                break;
            default:
                throw new InvalidArgumentException(sprintf('strategy %s does not exist.', $strategy));
        }
    }

    /**
     * Creates a function that will wrap a Doctrine expression according to the
     * specified case sensitivity.
     *
     * For example, "o.name" will get wrapped into "LOWER(o.name)" when $caseSensitive
     * is false.
     *
     * @param bool $caseSensitive
     *
     * @return \Closure
     */
    protected function createWrapCase(bool $caseSensitive): \Closure
    {
        return function (string $expr) use ($caseSensitive): string {
            if ($caseSensitive) {
                return $expr;
            }

            return sprintf('LOWER(%s)', $expr);
        };
    }

    /**
     * Normalize the values array.
     *
     * @param array $values
     *
     * @return array
     */
    protected function normalizeValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (!\is_int($key) || !\is_string($value)) {
                unset($values[$key]);
            }
        }

        return array_values($values);
    }

    /**
     * When the field should be an integer, check that the given value is a valid one.
     *
     * @param array $values
     * @param Type|string $type
     *
     * @return bool
     */
    protected function hasValidValues(array $values, $type = null): bool
    {
        foreach ($values as $key => $value) {
            if (Type::INTEGER === $type && null !== $value && false === filter_var($value, FILTER_VALIDATE_INT)) {
                return false;
            }
        }

        return true;
    }
}
