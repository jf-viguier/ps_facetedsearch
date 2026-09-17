# Pastilles (couleur / image) sur les valeurs de caractéristiques

> Écart propre au fork Batinea. Dépend du module `creafeatures`.

## 1. Besoin

Le core sait afficher une pastille dans les facettes pour les **attributs** d'un groupe déclaré « groupe de couleurs » : code hexadécimal dans `ps_attribute.color`, ou image dans `img/co/{id_attribute}.jpg`. Rien d'équivalent n'existe pour les **caractéristiques**, alors qu'une facette « Coloris » portée par une caractéristique a exactement le même besoin visuel.

## 2. Où sont stockées les données

Le module `creafeatures` (v1.8.1) fournit la saisie et le stockage. Il reprend volontairement les conventions du core :

| | Attributs (core) | Valeurs de caractéristiques (creafeatures) |
| --- | --- | --- |
| Code couleur | `ps_attribute.color` | `ps_feature_value.color` |
| Image | `img/co/{id_attribute}.jpg` | `img/fv/{id_feature_value}.jpg` |

Les deux répertoires étant distincts, un `id_feature_value` et un `id_attribute` de même valeur ne se télescopent pas.

Côté back-office, `creafeatures` se greffe sur les hooks que le core dispatche déjà :

- `displayFeatureValueForm` (rendu depuis `controllers/feature_value/helpers/form/form.tpl`) pour ajouter le champ couleur et le champ fichier ;
- `displayFeatureValuePostProcess` pour refuser une couleur mal formée **avant** la sauvegarde — même règle que le core applique aux couleurs d'attributs (`Validate::isColor()`, donc `#RRGGBB` ou un nom CSS comme `red`), plus la forme courte `#fff` — le core passe ses `errors` par référence justement pour permettre à un module d'interrompre l'enregistrement ;
- `actionFeatureValueSave` (dispatché par `FeatureValue::add()` / `update()`, donc l'id est connu même à la création) pour écrire la couleur et traiter l'upload ;
- `actionFeatureValueDelete` pour supprimer l'image avec la valeur.

Aucune surcharge de `AdminFeaturesController` ni de la classe `FeatureValue` n'est nécessaire.

## 3. Ce qui rend la pastille

**Rien dans ce fork.** Le rendu est assuré par le module `crea_facetedsearchcustomisations`, qui répond au hook `productSearchProvider` avant `ps_facetedsearch` et renvoie `CreaSearchProvider extends SearchProvider`. Cette sous-classe appelle `parent::runQuery()` puis pose les propriétés sur les filtres :

```php
foreach ($facetCollection->getFacets() as $facet) {
    if ($facet->getType() !== 'feature') { continue; }
    foreach ($facet->getFilters() as $filter) {
        // img/fv/{id}.jpg prioritaire, sinon feature_value.color
        $filter->setProperty('texture', ...);   // ou 'color'
    }
}
```

`ProductSearchResult`, `Facet` et `Filter` font partie de l'API publique du core — c'est la couture prévue par la [devdoc](https://devdocs.prestashop-project.org/9/development/components/faceted-search/inside-faceted-search-module/). Le fork n'a donc **aucune modification** de `Block.php` ni de `Converter.php`.

Mécanique complète du module, et les trois autres personnalisations qu'il porte : [crea_facetedsearchcustomisations/README.md](../../crea_facetedsearchcustomisations/README.md).

Conséquence appréciable : les propriétés sont posées **après** la lecture du cache `ps_layered_filter_block`. Changer une couleur ou une image est visible immédiatement, sans vider ce cache — ce qui n'était pas le cas quand la couleur voyageait dans le bloc mis en cache.

Coût : une requête supplémentaire par listing (`SELECT id_feature_value, color … WHERE color != "" AND id_feature_value IN (…)`, bornée aux valeurs réellement affichées, donc sur index primaire), et un `file_exists()` par valeur affichée — le même que faisait déjà le `Converter` pour les attributs.

## 4. Rendu front

**Aucune modification de thème n'est nécessaire.** `themes/crea_batinea/templates/catalog/_partials/facets.tpl` lit déjà `properties.color` et `properties.texture` sur n'importe quelle facette :

```smarty
{if isset($filter.properties.color)}
  <span class="facet__swatch" style="background-color:{$filter.properties.color}"></span>
{elseif isset($filter.properties.texture)}
  <span class="facet__swatch facet__swatch--texture" style="background-image:url({$filter.properties.texture})"></span>
{/if}
```

Les propriétés étant les mêmes que pour les attributs, les pastilles de caractéristiques héritent du style existant.

## 5. Cache

Les blocs de filtres sont mis en cache dans `ps_layered_filter_block`. Une couleur ou une image modifiée reste donc invisible tant que ce cache n'est pas vidé — `creafeatures` appelle `invalidateLayeredFilterBlockCache()` à chaque enregistrement et à chaque suppression. En cas de doute :

```sql
TRUNCATE TABLE ps_layered_filter_block;
```

## 6. Tests

⚠️ **Pas de test automatisé** sur cette fonctionnalité depuis qu'elle a quitté le fork : le module `crea_facetedsearchcustomisations` n'a pas d'infrastructure PHPUnit, et monter les mocks PrestaShop nécessaires représenterait plus de travail que le code testé.

La recette se fait donc sur le front (§5). Les trois formats acceptés — code hexadécimal, hexadécimal court, nom de couleur CSS — ainsi que la priorité de l'image sur la couleur, ont été vérifiés manuellement le 2026-09-17.
