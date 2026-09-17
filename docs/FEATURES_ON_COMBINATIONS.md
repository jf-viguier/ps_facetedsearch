# Filtre à facettes sur les caractéristiques (features) au niveau combinaison

> Fork Batinea de `ps_facetedsearch`, branche `feat/facetedwithfeaturesoncombinations`.
> Base : `ps/dev` (upstream PrestaShop).

## 1. Contexte

Le module `creafeatures` (custom Batinea) permet d'affecter des caractéristiques PrestaShop **au niveau des combinaisons** d'un produit, via la table `ps_feature_product_attribute` :

```text
feature_product_attribute (
    id_feature              -- caractéristique (ex: "Robinet Coloris")
    id_product_attribute    -- combinaison ciblée
    id_feature_value        -- valeur (ex: "or beige")
)
```

Le `ps_facetedsearch` historique ne connaît que `ps_feature_product` (caractéristique attachée à un produit). Conséquence :

- les valeurs définies uniquement sur des combinaisons n'apparaissent pas dans les facettes ;
- un produit dont une combinaison vaut « or beige » n'est pas remonté quand on filtre sur « or beige ».

## 2. Origine de l'implémentation

Le moteur SQL n'est **plus un patch maison**. Il vient du core, via la PR
[PrestaShop/ps_facetedsearch#1292](https://github.com/PrestaShop/ps_facetedsearch/pull/1292)
(« Support combination feature values in feature filters »), mergée dans `dev` upstream.

Ce fork ne conserve qu'**un seul écart de code** avec upstream ; le reste vit dans le module `crea_facetedsearchcustomisations` :

| Écart | Fichier | Raison |
| --- | --- | --- |
| Filtrage combinaison toujours actif | `src/CombinationFeature.php` | upstream le conditionne à PrestaShop >= 9.3 + feature flag `combination_feature_values` ; ici on est en PrestaShop 8 et c'est `creafeatures` qui fournit la table |
| Présélection de la combinaison matchée sur la vignette | *(hors fork)* module `crea_facetedsearchcustomisations` | non couvert par la PR upstream |
| Tests upstream épinglés sur le comportement « produit seul » | `tests/php/FacetedSearch/Adapter/MySQLTest.php` | conséquence directe du point 1 |

`src/Adapter/MySQL.php` est **identique à upstream**, ce qui rend les rebases indolores sur le fichier le plus volumineux.

## 3. Architecture

### 3.1 Table dérivée « feature_product étendue » (core)

Quand `CombinationFeature::isFilteringEnabled()` renvoie `true`, `MySQL::getFieldMapping()` remplace la table `feature_product` des mappings `id_feature` et `id_feature_value` par une table dérivée :

```sql
(
    SELECT id_product, NULL AS id_product_attribute, id_feature, id_feature_value
    FROM ps_feature_product

    UNION

    SELECT pa.id_product, pa.id_product_attribute, fpa.id_feature, fpa.id_feature_value
    FROM ps_feature_product_attribute fpa
    INNER JOIN ps_product_attribute pa ON pa.id_product_attribute = fpa.id_product_attribute
)
```

Colonne `id_product_attribute` :

- `NULL` → la valeur vient du produit lui-même et s'applique à toutes ses combinaisons ;
- non-`NULL` → la valeur vient d'une combinaison précise.

Le `UNION` (et non `UNION ALL`) dédoublonne une valeur définie aux deux niveaux.

La clé `rawTable` du mapping indique au générateur de requête que `tableName` est déjà une expression de table complète et ne doit pas être préfixée par `_DB_PREFIX_` (cf. `MySQL::getQuery()` et `MySQL::addJoinConditions()`).

### 3.2 Corrélation avec la combinaison filtrée

La condition de jointure devient :

```sql
ON (p.id_product = fp.id_product
    AND (fp.id_product_attribute IS NULL OR fp.id_product_attribute = pa.id_product_attribute))
```

et le mapping déclare `dependencyField => id_product_attribute`, ce qui force la jointure sur `ps_product_attribute pa` en amont.

Conséquence : **un filtre feature et un filtre attribut doivent être satisfaits par la même combinaison**, pas par deux combinaisons différentes du même produit. Les valeurs définies au niveau produit (`id_product_attribute IS NULL`) continuent de s'appliquer à toutes les combinaisons.

### 3.3 Règle métier : différence avec l'ancien POC du fork

⚠️ **Changement de sémantique par rapport à l'implémentation précédente de cette branche.**

| Donnée | Ancien POC Batinea | Implémentation core (actuelle) |
| --- | --- | --- |
| Produit = « chrome », combinaison C1 = « or beige » | filtre « chrome » → produit **exclu** (la combinaison écrase le produit, via un `NOT EXISTS`) | filtre « chrome » → produit **retourné** ; filtre « or beige » → produit retourné aussi |

Le core fait l'**union** des deux niveaux, il n'y a plus de notion d'override. Si la règle « la combinaison écrase le produit » est requise métier, elle doit être obtenue en nettoyant les données (`feature_product`) plutôt qu'en SQL.

### 3.4 Présélection de la combinaison matchée (hors fork)

Assurée par le module `crea_facetedsearchcustomisations`, pas par le fork. Sa classe `CreaSearchProvider` appelle `parent::runQuery()` puis post-traite le résultat :

```php
$result = parent::runQuery($context, $query);
$this->injectFeatureMatchedCombinations($result, $facetedSearchFilters);
```

`CreaSearchProvider::injectFeatureMatchedCombinations()` lit `$result->getProducts()`, puis :

1. Sort immédiatement si aucun filtre `id_feature` n'est actif.
2. Ne s'intéresse qu'aux matchs **de niveau combinaison** — un produit qui ne matche que par sa feature produit n'a pas de combinaison à présélectionner. Elle interroge donc directement `feature_product_attribute` :

   ```sql
   SELECT pa.id_product, MIN(fpa.id_product_attribute) AS id_product_attribute
   FROM ps_feature_product_attribute fpa
   INNER JOIN ps_product_attribute pa ON pa.id_product_attribute = fpa.id_product_attribute
   WHERE pa.id_product IN (...IDs du résultat...)
     AND fpa.id_feature_value IN (...valeurs sélectionnées...)
   GROUP BY pa.id_product
   ```

3. Injecte `id_product_attribute` dans chaque ligne produit **sans écraser** une valeur déjà présente (respect d'un filtre `id_attribute_group` actif en parallèle).
4. Injecte également `cover_image_id` avec l'image de la combinaison : sans cela `Product::getProductProperties()` retombe sur `Product::getCover()`, qui ignore `id_product_attribute`, et la vignette affiche la cover du produit. L'image retenue est la première au sens de `Image::getImages()` — `cover DESC, position ASC, id_image ASC` — donc celle vue en premier sur la fiche produit.

PrestaShop, en aval, utilise `id_product_attribute` via son `ProductLazyArray` pour rendre la vignette avec la combinaison (URL, prix, image).

## 4. Tests

La suite tourne sous PHP 7.4 (cf. [MISE_A_JOUR_UPSTREAM.md](MISE_A_JOUR_UPSTREAM.md#35-vérifier)) :

```bash
PHP74="C:/wamp64/bin/php/php7.4.33/php.exe"
$PHP74 -d date.timezone=UTC ./vendor/bin/phpunit -c tests/php/phpunit.xml
```

- `tests/php/FacetedSearch/CombinationFeatureTest.php` (fork) : vérifie que le filtrage combinaison est toujours actif.
- `MySQLTest::testGetQueryWithFeatureIncludesCombinationFeatures` (core) : forme du `JOIN` sur la table dérivée.
- `MySQLTest::testFeatureAndAttributeFiltersMustMatchTheSameCombination` (core) : corrélation feature/attribut sur la même combinaison.
- Les autres tests de `MySQLTest` utilisent un adapter épinglé sur `isCombinationFeatureFilteringEnabled() === false` pour conserver telles quelles les attentes upstream décrivant le comportement produit seul.

## 5. Recette fonctionnelle

Vider le cache des blocs de filtres (HTML des facettes pré-rendu) avant de tester :

```sql
TRUNCATE TABLE ps_layered_filter_block;
```

| Cas | Donnée | Attendu |
| --- | --- | --- |
| Feature uniquement sur produit | `feature_product`, pas de combinaison | produit dans le résultat, vignette produit standard (`id_product_attribute = 0`) |
| Feature uniquement sur combinaisons | `feature_product_attribute` pour C1 (V1) et C2 (V2) | la facette propose V1 et V2 ; filtre V1 → vignette avec C1 présélectionnée et son image |
| Feature aux deux niveaux | produit = V1, C1 = V2 | filtre V1 **et** filtre V2 retournent le produit (cf. §3.3) |
| Feature + attribut | C1 = (V1, rouge), C2 = (V2, bleu) | filtre « V1 + bleu » ne retourne **pas** le produit |

## 6. Performance

Volumes mesurés sur le catalogue Batinea (production-like) :

| Table | Lignes |
| --- | --- |
| `ps_feature_product` | 229 963 |
| `ps_feature_product_attribute` | 424 494 |
| `ps_product_attribute` | 7 981 |
| `ps_product` | 6 080 |

Index existants, tous utilisés par la table dérivée :

| Table | Index |
| --- | --- |
| `ps_feature_product` | `PRIMARY(id_feature, id_product, id_feature_value)`, `id_feature_value`, `id_product` |
| `ps_feature_product_attribute` | `PRIMARY(id_feature, id_product_attribute, id_feature_value)`, `id_feature_value`, `id_product_attribute` |
| `ps_product_attribute` | `PRIMARY`, `product_default(id_product, default_on)`, `product_attribute_product(id_product)`, `id_product_id_product_attribute(id_product_attribute, id_product)` |

Aucun index supplémentaire n'est nécessaire.

> ⚠️ Les mesures de temps relevées sur l'ancien POC (+0,6 ms/requête) **ne sont plus valables** : le `NOT EXISTS` dépendant a disparu, remplacé par un simple `UNION`, mais la jointure feature est désormais corrélée à `ps_product_attribute`. À re-profiler sur la nouvelle requête, en particulier le comptage des valeurs de facette (`Block::valueCount('id_feature_value')`) sans filtre feature actif, où MySQL ne peut pas pousser de prédicat dans la dérivée.

## 7. Limites connues

- **Multi-shop** : la table dérivée ne filtre pas par `id_shop`. Le filtrage shop est appliqué en aval par les autres `JOIN` (`product_shop ps`), donc fonctionnel ; en revanche une combinaison désactivée dans un shop mais active dans un autre apparaît dans le pool. Acceptable en mono-shop, à surveiller en multistore.
- **Présélection sur match produit** : si la valeur filtrée existe à la fois sur le produit et sur une combinaison, la vignette présélectionne quand même la combinaison.
- **Suivi upstream** : à chaque mise à jour d'upstream, rebaser la branche sur `ps/dev` — procédure détaillée dans [MISE_A_JOUR_UPSTREAM.md](MISE_A_JOUR_UPSTREAM.md). Seuls `src/CombinationFeature.php`, `src/Product/SearchProvider.php` et `MySQLTest::setUp()` divergent ; `src/Adapter/MySQL.php` doit rester identique à upstream.

## 8. Historique

- Une première implémentation vivait dans le module `modules/creafacetedsearchcustom/` (vue SQL `ps_crea_effective_feature_product` + hook `productSearchProvider`). Abandonnée.
- Puis un patch maison dans ce fork (sous-requête `UNION` + `NOT EXISTS`, règle « la combinaison écrase le produit »). Remplacé par l'implémentation du core décrite ici.
