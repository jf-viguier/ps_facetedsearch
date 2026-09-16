# Pastilles (couleur / image) sur les valeurs de caractéristiques

> Écart propre au fork Batinea. Dépend du module `creafeatures`.

## 1. Besoin

Le core sait afficher une pastille dans les facettes pour les **attributs** d'un groupe déclaré « groupe de couleurs » : code hexadécimal dans `ps_attribute.color`, ou image dans `img/co/{id_attribute}.jpg`. Rien d'équivalent n'existe pour les **caractéristiques**, alors qu'une facette « Coloris » portée par une caractéristique a exactement le même besoin visuel.

## 2. Où sont stockées les données

Le module `creafeatures` (v1.8.0) fournit la saisie et le stockage. Il reprend volontairement les conventions du core :

| | Attributs (core) | Valeurs de caractéristiques (creafeatures) |
| --- | --- | --- |
| Code couleur | `ps_attribute.color` | `ps_feature_value.color` |
| Image | `img/co/{id_attribute}.jpg` | `img/fv/{id_feature_value}.jpg` |

Les deux répertoires étant distincts, un `id_feature_value` et un `id_attribute` de même valeur ne se télescopent pas.

Côté back-office, `creafeatures` se greffe sur les hooks que le core dispatche déjà :

- `displayFeatureValueForm` (rendu depuis `controllers/feature_value/helpers/form/form.tpl`) pour ajouter le champ couleur et le champ fichier ;
- `actionFeatureValueSave` (dispatché par `FeatureValue::add()` / `update()`, donc l'id est connu même à la création) pour écrire la couleur et traiter l'upload ;
- `actionFeatureValueDelete` pour supprimer l'image avec la valeur.

Aucune surcharge de `AdminFeaturesController` ni de la classe `FeatureValue` n'est nécessaire.

## 3. Ce que fait le fork

Deux fichiers, une quinzaine de lignes.

### 3.1 `src/Filters/Block.php`

`getFeaturesBlock()` expose la couleur dans le bloc de filtres, **uniquement quand elle est renseignée** — un catalogue sans couleur produit donc exactement le même bloc qu'upstream :

```php
if (!empty($featureValues[$idFeatureValue]['color'])) {
    $featureBlock[$idFeature]['values'][$idFeatureValue]['color'] = $featureValues[$idFeatureValue]['color'];
}
```

Rien à changer dans `DataAccessor::getFeatureValues()` : la requête fait déjà `SELECT v.*`, donc la colonne `color` remonte d'elle-même dès que `creafeatures` l'a créée.

### 3.2 `src/Filters/Converter.php`

Une branche dédiée aux caractéristiques, qui laisse le chemin attribut d'upstream intact :

```php
if ($filterBlock['type'] === self::TYPE_FEATURE) {
    if (file_exists(_PS_IMG_DIR_ . self::FEATURE_VALUE_IMG_DIR . $id . '.jpg')) {
        $filter->setProperty(self::PROPERTY_TEXTURE, _PS_IMG_ . self::FEATURE_VALUE_IMG_DIR . $id . '.jpg');
    } elseif (!empty($filterArray['color'])) {
        $filter->setProperty(self::PROPERTY_COLOR, $filterArray['color']);
    }
} elseif (isset($filterArray['color'])) {
    // chemin upstream, inchangé
}
```

L'image l'emporte sur le code couleur quand les deux sont renseignés, comme le fait le core pour les attributs.

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

`ConverterTest::testGetFacetsFromFilterBlocksExposesFeatureValueSwatches` couvre les trois cas (image prioritaire, couleur seule, ni l'un ni l'autre), et `testGetFacetsFromFilterBlocksKeepsAttributeColorsOnTheirOwnDirectory` verrouille le fait que les attributs continuent de résoudre dans `img/co/`.

Fixture : `tests/php/files/fv/12.jpg`. Le bootstrap de tests définit `_PS_IMG_DIR_` et `_PS_IMG_` en plus de `_PS_COL_IMG_DIR_`.
