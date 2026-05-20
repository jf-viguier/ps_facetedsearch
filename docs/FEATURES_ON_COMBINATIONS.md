# Filtre à facettes sur les caractéristiques (features) au niveau combinaison

> Patch propre au fork Batinea (branche `feat/facetedwithfeaturesoncombinations`).
> Ne pas merger dans `upstream/master` — dépend du module `creafeatures`.

## 1. Contexte

Le module `creafeatures` (custom Batinea) ajoute la possibilité d'affecter des caractéristiques PrestaShop **au niveau des combinaisons** d'un produit, via la table `ps_feature_product_attribute` :

```text
feature_product_attribute (
    id_feature              -- caractéristique (ex: "Robinet Coloris")
    id_product_attribute    -- combinaison ciblée
    id_feature_value        -- valeur (ex: "or beige")
)
```

Le module standard `ps_facetedsearch` ne connaît que `ps_feature_product` (caractéristique attachée à un produit). Conséquence avant patch :

- les valeurs définies uniquement sur des combinaisons n'apparaissent pas dans les facettes ;
- un produit dont une combinaison vaut « or beige » mais dont la fiche produit vaut « chrome » n'est **pas** remonté quand on filtre sur « or beige ».

## 2. Règle métier

Pour chaque (produit, caractéristique), on veut une **valeur effective** :

- si au moins une combinaison définit la caractéristique → on prend la (ou les) valeur(s) combinaison ;
- sinon → on retombe sur la valeur produit (`feature_product`).

C'est exactement la règle déjà implémentée pour l'affichage fiche produit dans `CreaTools::enrichProductFeatures` ([override/classes/CreaTools.php:198](../../override/classes/CreaTools.php#L198)) — on la rejoue ici au niveau filtre.

Effets attendus :

1. **Facettes** : la liste des valeurs proposées pour une caractéristique inclut celles présentes uniquement sur des combinaisons.
2. **Filtrage** : un produit match si **une de ses combinaisons** ou (à défaut) **lui-même** porte la valeur sélectionnée. La combinaison écrase le produit, jamais l'inverse.
3. **Listing** : quand le match vient d'une combinaison, la vignette du produit est rendue avec cette combinaison **présélectionnée** (URL `#/...`, prix et image de la combinaison) via le champ `id_product_attribute` standard de PrestaShop.

## 3. Architecture du patch

Tout est confiné à **deux fichiers** du module forké :

- [`src/Adapter/MySQL.php`](../src/Adapter/MySQL.php) — moteur de génération SQL des requêtes facettes.
- [`src/Product/SearchProvider.php`](../src/Product/SearchProvider.php) — orchestrateur appelé par PrestaShop pour chaque listing.

Aucune vue SQL, aucune table d'index, aucun module satellite : le SQL effectif est calculé à la volée par une sous-requête `UNION` injectée dans le `JOIN`.

### 3.1 Sous-requête « feature_product effective »

Méthode protégée `MySQL::getEffectiveFeatureProductSubquery()` (~ ligne 358) qui retourne :

```sql
(
    -- Partie 1 : caractéristiques définies au niveau combinaison
    SELECT pa.id_product, fpa.id_feature, fpa.id_feature_value, pa.id_product_attribute
    FROM ps_feature_product_attribute fpa
    INNER JOIN ps_product_attribute pa ON pa.id_product_attribute = fpa.id_product_attribute

    UNION

    -- Partie 2 : caractéristiques produit, uniquement si aucune combinaison
    --            n'override déjà cette même feature pour ce produit
    SELECT fp_src.id_product, fp_src.id_feature, fp_src.id_feature_value, NULL AS id_product_attribute
    FROM ps_feature_product fp_src
    WHERE NOT EXISTS (
        SELECT 1
        FROM ps_feature_product_attribute fpa2
        INNER JOIN ps_product_attribute pa2 ON pa2.id_product_attribute = fpa2.id_product_attribute
        WHERE pa2.id_product = fp_src.id_product
          AND fpa2.id_feature = fp_src.id_feature
    )
)
```

Colonne `id_product_attribute` :

- non-`NULL` → la valeur vient d'une combinaison précise (utile pour la présélection front) ;
- `NULL` → la valeur vient du produit lui-même.

Le `NOT EXISTS` implémente la règle « combinaison écrase produit » : dès qu'une combinaison définit la feature F sur le produit P, la ligne produit de P pour F est exclue de la partie 2.

### 3.2 Injection dans la chaîne de jointures

Le moteur de jointures utilise un *field mapping* `'champ' => ['tableName' => ..., 'joinCondition' => ..., 'joinType' => ...]`. Lors de la construction du SQL, `'tableName'` est concaténée à `_DB_PREFIX_` :

```php
$query .= ' ' . $joinInfos['joinType'] . ' ' . _DB_PREFIX_ . $joinInfos['tableName'] . ' ' . $tableAlias . ...
```

Pour permettre une **sous-requête** à la place d'un nom de table, on introduit une clé optionnelle `rawTable` :

```php
// MySQL.php, dans la boucle de construction des JOIN (~ ligne 120)
$tableExpression = isset($joinInfos['rawTable'])
    ? $joinInfos['rawTable']
    : _DB_PREFIX_ . $joinInfos['tableName'];
$query .= ' ' . $joinInfos['joinType'] . ' ' . $tableExpression . ' ' . $tableAlias . ' ON ' . $joinInfos['joinCondition'];
```

**Piège trouvé** : `addJoinConditions` (~ ligne 749) recopie le mapping vers `$joinInfos` en n'exposant que `tableName`, `joinCondition`, `joinType`. Notre clé `rawTable` était silencieusement perdue. Patch :

```php
$joinInfos[$joinMapping['tableAlias']] = [
    'tableName' => $joinMapping['tableName'],
    'joinCondition' => $joinMapping['joinCondition'],
    'joinType' => $joinMapping['joinType'],
];
if (isset($joinMapping['rawTable'])) {
    $joinInfos[$joinMapping['tableAlias']]['rawTable'] = $joinMapping['rawTable'];
}
```

Enfin, les deux mappings `id_feature` et `id_feature_value` (méthode `getFieldMapping`) reçoivent `rawTable` :

```php
'id_feature' => [
    'tableName' => 'feature_product',
    'rawTable' => $this->getEffectiveFeatureProductSubquery(),
    'tableAlias' => 'fp',
    'joinCondition' => '(p.id_product = fp.id_product)',
    'joinType' => self::INNER_JOIN,
],
'id_feature_value' => [
    'tableName' => 'feature_product',
    'rawTable' => $this->getEffectiveFeatureProductSubquery(),
    'tableAlias' => 'fp',
    'joinCondition' => '(p.id_product = fp.id_product)',
    'joinType' => self::LEFT_JOIN,
],
```

`tableName` reste à `'feature_product'` : c'est lui qui sert de **clé** pour dédupliquer les JOIN (`$joinList->set($alias . '_' . $tableName, ...)`). Du point de vue de l'algorithme de génération, rien d'autre ne change.

### 3.3 Présélection de la combinaison matchée

Dans `SearchProvider::runQuery`, après `getProductByFilters` :

```php
$this->injectFeatureMatchedCombinations(
    $productsAndCount['products'],
    $facetedSearchFilters,
    $facetedSearch->getSearchAdapter()
);
```

Méthode protégée `injectFeatureMatchedCombinations` (~ ligne 631) :

1. Sortie immédiate si aucun filtre `id_feature` n'est actif.
2. Pour la présélection, seuls les matchs **au niveau combinaison** sont pertinents (un produit qui ne match que via sa feature produit n'a pas de combinaison à présélectionner). On interroge donc directement `feature_product_attribute` :

   ```sql
   SELECT pa.id_product, MIN(fpa.id_product_attribute) AS id_product_attribute
   FROM ps_feature_product_attribute fpa
   INNER JOIN ps_product_attribute pa ON pa.id_product_attribute = fpa.id_product_attribute
   WHERE pa.id_product IN (...IDs du résultat...)
     AND fpa.id_feature_value IN (...valeurs sélectionnées...)
   GROUP BY pa.id_product
   ```

3. Injecte `id_product_attribute` dans chaque ligne produit du résultat **sans écraser** une valeur déjà présente (respect d'un filtre `id_attribute_group` actif en parallèle).

Aucune dépendance entre `SearchProvider` et les internes de `MySQL` : la cohérence avec le filtre est assurée par la sémantique (les combinaisons override les produits, donc filtrer sur les combinaisons donne le même résultat que la partie 1 du UNION qui sert au filtre principal).

PrestaShop, en aval, utilise ce `id_product_attribute` via son `ProductLazyArray` pour rendre la vignette avec la combinaison (URL, prix, image cover).

## 4. Fichiers modifiés

| Fichier | Lignes affectées | Nature |
| --- | --- | --- |
| `src/Adapter/MySQL.php` | ~120 (lecture de `rawTable` dans le `JOIN`) | modif. |
| `src/Adapter/MySQL.php` | ~190 et ~211 (ajout de `rawTable` sur les mappings feature) | modif. |
| `src/Adapter/MySQL.php` | ~358 (`getEffectiveFeatureProductSubquery`) | ajout |
| `src/Adapter/MySQL.php` | ~760 (préserver `rawTable` dans `addJoinConditions`) | modif. |
| `src/Product/SearchProvider.php` | ~190 (appel `injectFeatureMatchedCombinations`) | modif. |
| `src/Product/SearchProvider.php` | ~631 (méthode `injectFeatureMatchedCombinations`) | ajout |

Total : ~115 lignes ajoutées, 2 lignes modifiées, dans 2 fichiers.

## 5. Vérification

### 5.1 Sanity check du SQL

Lancer un test de bout en bout :

```bash
# Vider le cache des blocs de filtres (HTML facettes pré-rendu)
php -r "define('_PS_ADMIN_DIR_', __DIR__.'/admin'); require __DIR__.'/config/config.inc.php'; \
        Db::getInstance()->execute('TRUNCATE TABLE ps_layered_filter_block');"
```

Recharger une page catégorie avec un filtre feature dont la valeur n'existe que sur combinaison, ex. :

```text
mitigeur-lavabo-vasque.html?q=Robinet+Coloris-or+beige
```

Sur le cas testé :

```html
<article data-id-product="27494" data-id-product-attribute="27482">  <!-- combinaison or beige -->
<article data-id-product="27495" data-id-product-attribute="27484">  <!-- combinaison or beige -->
<article data-id-product="32078" data-id-product-attribute="0">      <!-- feature niveau produit -->
```

### 5.2 Cas à valider

| Cas | Donnée | Attendu |
| --- | --- | --- |
| Feature uniquement sur produit | `feature_product`, pas de combinaison | produit dans le résultat, vignette produit standard (`id_product_attribute = 0`) |
| Feature uniquement sur combinaisons | `feature_product_attribute` pour C1 (V1) et C2 (V2) | facette propose V1 et V2 ; filtre V1 → vignette avec C1 présélectionnée |
| Override combinaison sur produit | produit a V1 dans `feature_product`, C1 a V2 dans `feature_product_attribute` | filtre V1 **ne retourne pas** le produit ; filtre V2 retourne le produit avec C1 présélectionnée |

## 6. Performance & index

Mesures sur le catalogue Batinea (production-like).

### 6.1 Volumes

| Table | Lignes |
| --- | --- |
| `ps_feature_product` | 229 963 |
| `ps_feature_product_attribute` | 424 494 |
| `ps_product_attribute` | 7 981 |
| `ps_product` | 6 080 |

### 6.2 Index existants (utilisés par la sous-requête)

| Table | Index utilisés |
| --- | --- |
| `ps_feature_product` | `PRIMARY(id_feature, id_product, id_feature_value)`, `id_feature_value`, `id_product` |
| `ps_feature_product_attribute` | `PRIMARY(id_feature, id_product_attribute, id_feature_value)`, `id_feature_value`, `id_product_attribute` |
| `ps_product_attribute` | `PRIMARY`, `product_default(id_product, default_on)`, `product_attribute_product(id_product)`, `id_product_id_product_attribute(id_product_attribute, id_product)` |

✅ **Aucun index manquant**. Les trois jointures internes de la sous-requête (partie combinaison + partie produit + `NOT EXISTS`) accèdent toutes par index — `Using index` (covering) sur la plupart des étapes.

### 6.3 Predicate pushdown

Sur un filtre `id_feature_value = X` appliqué côté outer query, MySQL pousse le prédicat dans la dérivée. EXPLAIN observé pour la facette « Robinet Coloris = or beige » :

```text
id=3 (DERIVED partie 1) : ref="const" sur id_feature_value → 11 rows
id=4 (UNION partie 2)   : ref="const" sur id_feature_value → 3 rows
id=5 (DEPENDENT NOT EXISTS) : key=PRIMARY (composite feature_product_attribute) → 1 row par lookup
```

Toute la sous-requête est ramenée à ~14 lignes effectives au lieu des 654k lignes brutes.

### 6.4 Mesures de temps

Temps moyen sur 5 itérations, requête facette catégorie + filtre feature :

| Version | Temps moyen |
| --- | --- |
| `ps_facetedsearch` upstream (sans patch) | **0,7 ms** |
| Patch combinaison (présent fork) | **1,3 ms** |

Overhead absolu : **+0,6 ms** par requête. Négligeable face au coût total de rendu d'une page catégorie (typiquement 100-500 ms). Le surcoût provient essentiellement de la branche `NOT EXISTS` (DEPENDENT SUBQUERY).

### 6.5 Cas potentiellement coûteux non encore profilés

- Comptage des valeurs de facette (`Block::valueCount('id_feature_value')`) sans filtre sur `id_feature` — MySQL ne peut pas pousser de prédicat dans la sous-requête, qui est alors matérialisée en totalité. Restreinte malgré tout par les JOIN catégorie/shop en aval.
- Catégorie racine ou résultats de recherche très large (> 10k produits matchés).

### 6.6 Recommandation de scaling

Si l'augmentation du catalogue ou des features sur combinaison dégrade les temps de réponse au-delà de quelques ms, basculer vers une **table matérialisée** maintenue par hooks (`actionProductSave`, `actionObjectCombinationAddAfter`, `actionFeatureDelete`...). Le shape du JOIN ne changerait pas — seul `getEffectiveFeatureProductSubquery()` retournerait `ps_<table>` au lieu d'un subquery.

## 7. Limites connues

- **Cohérence multi-shop** : la sous-requête ne filtre pas par `id_shop`. Le filtrage shop est appliqué en aval par les autres JOIN (`product_shop ps`), donc fonctionnel ; en revanche une combinaison désactivée dans un shop mais active dans un autre apparaîtra dans le pool. Acceptable pour un mono-shop, à surveiller en multistore.
- **Mise à jour upstream** : à chaque update de `ps_facetedsearch` upstream, rejouer le rebase de la branche `feat/facetedwithfeaturesoncombinations` et revalider les 6 points d'injection listés en §4.

## 8. Lien avec le module `creafacetedsearchcustom`

Une première implémentation hors-module avait été développée dans `modules/creafacetedsearchcustom/` (utilisant une vue SQL `ps_crea_effective_feature_product` et le hook `productSearchProvider` priorisé). Elle a été **désactivée** au profit du présent patch, qui réduit la surface modifiée et supprime la dépendance à un module satellite + une vue MySQL.

Pour désactiver / réinstaller :

```bash
php bin/console prestashop:module disable creafacetedsearchcustom
php bin/console prestashop:module enable  creafacetedsearchcustom  # si retour en arrière
```
