# Mise à jour du fork depuis upstream PrestaShop

> Procédure de rebase de la branche `feat/facetedwithfeaturesoncombinations` sur `ps/dev`.
> Voir [FEATURES_ON_COMBINATIONS.md](FEATURES_ON_COMBINATIONS.md) pour ce que contient la branche.

## 1. Prérequis

Ce dépôt est un **submodule** de `Batinea-OGS/www`, monté dans `modules/ps_facetedsearch`. Toutes les commandes ci-dessous se lancent depuis ce dossier, pas depuis la racine du projet.

Deux remotes sont nécessaires :

```bash
git remote -v
# origin  git@github.com:jf-viguier/ps_facetedsearch.git   (le fork)
# ps      git@github.com:PrestaShop/ps_facetedsearch.git    (upstream)
```

Si `ps` manque :

```bash
git remote add ps git@github.com:PrestaShop/ps_facetedsearch.git
```

## 2. Ce qui diverge d'upstream

C'est le point le plus important : la divergence tient en quatre fichiers de `src/` plus des tests et de la doc. Tant que cette liste reste juste, le rebase est indolore.

| Fichier | Nature de l'écart | Voir |
| --- | --- | --- |
| `src/CombinationFeature.php` | `isFilteringEnabled()` renvoie `true` en dur (upstream : PS >= 9.3 + feature flag `combination_feature_values`) | [FEATURES_ON_COMBINATIONS](FEATURES_ON_COMBINATIONS.md) |
| `src/Product/SearchProvider.php` | ajout de `injectFeatureMatchedCombinations()` + son appel dans `runQuery()` | [FEATURES_ON_COMBINATIONS](FEATURES_ON_COMBINATIONS.md) |
| `src/Filters/Block.php` | `getFeaturesBlock()` expose `color` quand la valeur en porte une | [FEATURE_VALUE_SWATCHES](FEATURE_VALUE_SWATCHES.md) |
| `src/Filters/Converter.php` | branche pastille dédiée aux caractéristiques (`img/fv/`), chemin attribut inchangé | [FEATURE_VALUE_SWATCHES](FEATURE_VALUE_SWATCHES.md) |
| `tests/php/FacetedSearch/Adapter/MySQLTest.php` | `setUp()` épingle l'adapter sur `isCombinationFeatureFilteringEnabled() === false` | |
| `tests/php/FacetedSearch/Filters/ConverterTest.php` | 2 tests ajoutés pour les pastilles | |
| `tests/php/FacetedSearch/CombinationFeatureTest.php` | fichier ajouté par le fork | |
| `tests/php/bootstrap.php` | ajoute `_PS_IMG_DIR_` et `_PS_IMG_` | |
| `tests/php/files/fv/12.jpg` | fixture ajoutée par le fork | |
| `docs/` | fichiers ajoutés par le fork | |

⚠️ **`src/Adapter/MySQL.php` doit rester identique à upstream.** C'est le fichier le plus gros et le plus actif ; toute modification locale dessus transformerait chaque rebase en corvée. Le moteur SQL du filtrage sur combinaison vient de la PR [#1292](https://github.com/PrestaShop/ps_facetedsearch/pull/1292), déjà mergée upstream.

## 3. Procédure

### 3.1 Récupérer upstream

```bash
git fetch ps --prune
git fetch origin --prune
```

### 3.2 Mettre à jour `dev` et `master` (fast-forward)

```bash
git merge-base --is-ancestor origin/dev ps/dev && echo "ff ok"

git checkout dev
git merge --ff-only ps/dev
git push origin dev:dev

# master sans checkout local
git push origin ps/master:refs/heads/master
```

Si `--ff-only` échoue, c'est que des commits ont été poussés directement sur `dev` du fork : les identifier (`git log ps/dev..origin/dev`) avant de décider.

### 3.3 Sauvegarder la branche avant de la réécrire

```bash
git tag -f backup/feat-combinations-$(date +%Y%m%d) feat/facetedwithfeaturesoncombinations
git push origin refs/tags/backup/feat-combinations-$(date +%Y%m%d)
```

### 3.4 Rebaser la branche

```bash
git checkout feat/facetedwithfeaturesoncombinations
git rebase ps/dev
```

En cas de conflit :

- **sur `src/Adapter/MySQL.php`** → prendre la version upstream telle quelle : `git checkout --theirs src/Adapter/MySQL.php` puis vérifier §3.5. Si un commit local touche ce fichier, c'est qu'une règle du §2 a été enfreinte.
- **sur `src/Product/SearchProvider.php`** → résoudre à la main, seuls l'appel dans `runQuery()` et la méthode en fin de classe sont à nous.
- **sur `src/CombinationFeature.php`** → si upstream fait évoluer la classe, reprendre sa version et re-supprimer le `version_compare` + la résolution du feature flag.
- **sur `src/Filters/Block.php` ou `src/Filters/Converter.php`** → reprendre la version upstream et rejouer la greffe décrite dans [FEATURE_VALUE_SWATCHES.md](FEATURE_VALUE_SWATCHES.md) §3 (une quinzaine de lignes au total).

Si upstream a repris un commit local à l'identique (ça s'est produit avec le fix `Tools::unSerialize`), `git rebase` le supprime tout seul.

### 3.5 Vérifier

```bash
# MySQL.php identique a upstream
git diff --quiet ps/dev HEAD -- src/Adapter/MySQL.php && echo "MySQL.php OK"

# la liste des fichiers divergents correspond au §2
git diff --stat ps/dev..HEAD

# syntaxe
php -l src/CombinationFeature.php
php -l src/Product/SearchProvider.php
php -l src/Filters/Block.php
php -l src/Filters/Converter.php
```

Tests et lint — ⚠️ `phpunit ~5.7` ne tourne **pas** sur le PHP 8.1 par défaut de wamp, il faut le PHP 7.4 également installé :

```bash
PHP74="C:/wamp64/bin/php/php7.4.33/php.exe"

$PHP74 /c/ProgramData/ComposerSetup/bin/composer.phar install --no-interaction
$PHP74 -d date.timezone=UTC ./vendor/bin/phpunit -c tests/php/phpunit.xml
$PHP74 ./vendor/bin/php-cs-fixer fix --no-interaction --dry-run --diff
```

Attendu : `OK (126 tests, 1089 assertions)` et aucun fichier remonté par php-cs-fixer (état au 2026-09-16).

### 3.6 Pousser

```bash
git push --force-with-lease origin feat/facetedwithfeaturesoncombinations
```

`--force-with-lease` (et non `--force`) : refuse de pousser si la branche distante a bougé depuis le dernier `fetch`.

### 3.7 Mettre à jour le pointeur de submodule

Depuis la racine du projet :

```bash
cd ../..
git add modules/ps_facetedsearch
git commit -m "submodule ps_facetedsearch: rebase sur upstream dev"
```

## 4. Recette après déploiement

Le HTML des facettes est mis en cache en base. Le vider avant de tester :

```sql
TRUNCATE TABLE ps_layered_filter_block;
```

Puis dérouler les cas de §5 de [FEATURES_ON_COMBINATIONS.md](FEATURES_ON_COMBINATIONS.md).

## 5. Journal des mises à jour

| Date | Base upstream | Tête de branche | Notes |
| --- | --- | --- | --- |
| 2026-09-16 | `4933b63` | `c3b1776` | Remplacement du POC maison par l'implémentation du core (PR #1292) ; suppression du feature flag ; `src/Adapter/MySQL.php` réaligné sur upstream. Sauvegarde : tag `backup/feat-facetedwithfeaturesoncombinations-pre-core` (`12cf94c`). |
