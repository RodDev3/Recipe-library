<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Recipe;
use Doctrine\ORM\QueryBuilder;

final class ExcludeAllergensFilter implements FilterInterface
{
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        // ex: ?excludeAllergens[]=1&excludeAllergens[]=4 -> ['1', '4']
        $allergenIds = $context['filters']['excludeAllergens'] ?? null;

        if (null === $allergenIds || '' === $allergenIds) {
            return;
        }

        // alias déjà utilisé pour Recipe dans la requête principale (ex: "o")
        $alias = $queryBuilder->getRootAliases()[0];
        $subQueryAlias = $queryNameGenerator->generateJoinAlias('recipe');   // ex: recipe_a1
        $allergenAlias = $queryNameGenerator->generateJoinAlias('allergen'); // ex: allergen_a2
        $parameterName = $queryNameGenerator->generateParameterName('excludeAllergens');

        // sous-requête : "cette recette a-t-elle un des allergènes exclus ?"
        $subQuery = $queryBuilder->getEntityManager()->createQueryBuilder()
            ->select('1') // on veut juste savoir si une ligne existe, pas de vraies données
            ->from(Recipe::class, $subQueryAlias)
            ->innerJoin(sprintf('%s.allergens', $subQueryAlias), $allergenAlias)
            // corrélation : la recette de la sous-requête = la recette "o" en cours d'examen
            ->where(sprintf('%s = %s', $subQueryAlias, $alias))
            ->andWhere(sprintf('%s.id IN (:%s)', $allergenAlias, $parameterName));

        $queryBuilder
            // garde la recette seulement si la sous-requête ne trouve rien
            ->andWhere($queryBuilder->expr()->not($queryBuilder->expr()->exists($subQuery->getDQL())))
            ->setParameter($parameterName, array_values($allergenIds));
    }

    // sert seulement à générer la doc OpenAPI (/api/docs), aucun effet sur le comportement du filtre
    public function getDescription(string $resourceClass): array
    {
        return [
            'excludeAllergens[]' => [
                'property' => null,       // pas lié à une propriété unique de Recipe
                'type' => 'int',          // chaque valeur du tableau est un id d'allergène
                'required' => false,      // filtre optionnel
                'is_collection' => true,  // accepte plusieurs valeurs : excludeAllergens[]=1&excludeAllergens[]=4
                'description' => 'Exclude recipes containing any of the given allergen IDs',
            ],
        ];
    }
}
