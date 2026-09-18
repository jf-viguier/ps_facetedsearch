# Mise à jour du fork depuis upstream PrestaShop

> Procédure de rebase de la branche `feat/facetedwithfeaturesoncombinations` sur `ps/dev`.
> Voir [FEATURES_ON_COMBINATIONS.md](FEATURES_ON_COMBINATIONS.md) pour ce que contient la branche.
>
> ⚠️ **La mise à jour de ce module passe exclusivement par git.** Le bouton « Mettre à jour » du
> back-office écrase le fork sans prévenir — voir §6 pour le mécanisme et la protection en place.

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

C'est le point le plus important : **un seul fichier de `src/` diverge**, plus des tests et de la doc. Tant que cette liste reste juste, le rebase est indolore.

| Fichier | Nature de l'écart | Voir |
| --- | --- | --- |
| `src/CombinationFeature.php` | `isFilteringEnabled()` renvoie `true` en dur (upstream : PS >= 9.3 + feature flag `combination_feature_values`) | [FEATURES_ON_COMBINATIONS](FEATURES_ON_COMBINATIONS.md) |
| `tests/php/FacetedSearch/Adapter/MySQLTest.php` | `setUp()` épingle l'adapter sur `isCombinationFeatureFilteringEnabled() === false` | |
| `tests/php/FacetedSearch/CombinationFeatureTest.php` | fichier ajouté par le fork | |
| `docs/` | fichiers ajoutés par le fork | |

Tout le reste des customisations Batinea vit **hors du fork**, dans le module `crea_facetedsearchcustomisations` : il répond au hook `productSearchProvider` avant `ps_facetedsearch`, renvoie une sous-classe de son `SearchProvider`, et post-traite le `ProductSearchResult` — qui fait partie de l'API publique du core.

Il en porte quatre :

| Customisation | Détail |
| --- | --- |
| Présélection de la combinaison matchée par un filtre caractéristique, avec son image | [FEATURES_ON_COMBINATIONS.md](FEATURES_ON_COMBINATIONS.md) §3.4 |
| Pastilles couleur / image sur les valeurs de caractéristiques | [FEATURE_VALUE_SWATCHES.md](FEATURE_VALUE_SWATCHES.md) |
| Slider de prix sans centimes | [README du module](../../crea_facetedsearchcustomisations/README.md) §3.3 |
| Une facette gardant un filtre coché reste affichée | [README du module](../../crea_facetedsearchcustomisations/README.md) §3.4 |

Référence complète du module : [crea_facetedsearchcustomisations/README.md](../../crea_facetedsearchcustomisations/README.md) — il vit dans le dépôt parent `Batinea-OGS/www`, sous `modules/crea_facetedsearchcustomisations/`, donc les liens le pointant depuis ce dépôt ne résolvent pas sur GitHub.

Pourquoi `CombinationFeature.php` ne peut pas en sortir : la classe est consultée au fond de `MySQL::getFieldMapping()`, et `MySQL::getFilteredSearchAdapter()` fait `new self()` et non `new static()`. Une sous-classe d'adaptateur ne se propagerait donc pas aux adaptateurs imbriqués qui calculent les compteurs de facettes.

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
- **sur `src/CombinationFeature.php`** → si upstream fait évoluer la classe, reprendre sa version et re-supprimer le `version_compare` + la résolution du feature flag.
- **sur tout autre fichier de `src/`** → il ne devrait y en avoir aucun : prendre la version upstream. Si un conflit apparaît, c'est qu'une customisation a été réintroduite dans le fork au lieu du module.

Si upstream a repris un commit local à l'identique (ça s'est produit avec le fix `Tools::unSerialize`), `git rebase` le supprime tout seul.

### 3.5 Vérifier

```bash
# MySQL.php identique a upstream
git diff --quiet ps/dev HEAD -- src/Adapter/MySQL.php && echo "MySQL.php OK"

# la liste des fichiers divergents correspond au §2
git diff --stat ps/dev..HEAD

# syntaxe
php -l src/CombinationFeature.php
```

Tests et lint — ⚠️ `phpunit ~5.7` ne tourne **pas** sur le PHP 8.1 par défaut de wamp, il faut le PHP 7.4 également installé :

```bash
PHP74="C:/wamp64/bin/php/php7.4.33/php.exe"

$PHP74 /c/ProgramData/ComposerSetup/bin/composer.phar install --no-interaction
$PHP74 -d date.timezone=UTC ./vendor/bin/phpunit -c tests/php/phpunit.xml
$PHP74 ./vendor/bin/php-cs-fixer fix --no-interaction --dry-run --diff
```

Attendu : `OK (124 tests, 1080 assertions)` et aucun fichier remonté par php-cs-fixer (état au 2026-09-16).

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

## 6. Ne jamais mettre à jour ce module depuis le back-office

### 6.1 Ce qui s'est passé le 2026-09-16

Une mise à jour lancée depuis le Module Manager a remplacé tout le code du fork par le paquet publié en amont. Trace relevée dans l'access log Apache :

```
16/Sep/2026:15:25:50  POST /admin…/improve/modules/manage/action/upgrade/ps_facetedsearch
  ?source=https%3A%2F%2Fapi.prestashop-project.org%2Fassets%2Fmodules%2Fps_facetedsearch%2Fv5.1.0%2Fps_facetedsearch.zip
```

Dégâts : les quatre fichiers de `src/` qui divergent (§2) revenus en version upstream, le `vendor/` de dev écrasé par celui de production (classmap PHPUnit perdue), et des `index.php` de garde ajoutés. `docs/` et `tests/` ont survécu uniquement parce que le paquet ne les contient pas et que l'extraction se superpose sans supprimer.

Conséquence fonctionnelle immédiate : `CombinationFeature::isFilteringEnabled()` étant redevenu conditionné au feature flag, le filtrage sur les caractéristiques de combinaison s'est éteint silencieusement — les facettes ne remontaient plus que les valeurs de niveau produit.

### 6.2 D'où vient le zip

Ni Addons ni `ps_mbo` ne sont impliqués — le module `ps_mbo` n'est même pas installé. C'est **`ps_distributionapiclient`** qui alimente le Module Manager, via l'API du projet PrestaShop :

```php
// modules/ps_distributionapiclient/src/DistributionApi.php
private const API_ENDPOINT = 'https://api.prestashop-project.org';
```

Son hook `actionListModules` renvoie pour chaque module natif un tableau `['name', 'version_available', 'download_url']`. Le core fusionne ces attributs ([`ModuleRepository::enrichModuleAttributesFromHook()`](../../../src/Core/Module/ModuleRepository.php)) puis construit le lien du bouton ([`AdminModuleDataProvider::setActionUrls()`](../../../src/Adapter/Module/AdminModuleDataProvider.php)) :

```php
if ($action === 'upgrade' && $moduleAttributes->get('download_url') !== null) {
    $parameters['source'] = $moduleAttributes->get('download_url');
}
```

### 6.3 Les deux chemins de téléchargement

Ils sont distincts, et il faut fermer les deux. Dans `Core\Module\ModuleManager::upgrade()` :

```php
if ($source !== null) {
    $handler = $this->sourceFactory->getHandler($source);   // RemoteZipSourceHandler → télécharge
    $handler->handle($source);                              // extractTo(), sans suppression préalable
}
$this->hookManager->exec('actionBeforeUpgradeModule', ['moduleName' => $name, 'source' => $source]);
$upgraded = $this->upgradeMigration($name) && $module->onUpgrade(...);
```

| Chemin | Déclencheur | Qui télécharge |
| --- | --- | --- |
| Avec `source` | bouton du BO | le **core**, avant tout hook — `hookActionBeforeUpgradeModule()` sort d'ailleurs immédiatement quand `source` est renseigné |
| Sans `source` | `php bin/console prestashop:module upgrade <module>` | **`ps_distributionapiclient`**, dans son hook |

### 6.4 La protection en place

[`override/modules/ps_distributionapiclient/ps_distributionapiclient.php`](../../../override/modules/ps_distributionapiclient/ps_distributionapiclient.php) (dépôt `www`, commit `49fa517`) surcharge les deux méthodes :

- `hookActionListModules()` retire les modules épinglés de la liste → plus de `download_url`, donc plus de bouton et plus de `source` distant ;
- `hookActionBeforeUpgradeModule()` court-circuite le téléchargement pour ces mêmes modules → couvre le chemin CLI.

La liste est la constante `PINNED_MODULES`, à compléter si d'autres modules natifs sont forkés. PrestaShop charge ce fichier via [`Module::coreLoadModule()`](../../../classes/module/Module.php), qui cherche `override/modules/{module}/{module}.php` et y attend une classe `{module}Override` — pas de passage par le class_index, donc pas de vidage de cache nécessaire.

**Ce qui reste fonctionnel** : les mises à jour venant du disque. `Adapter\Module\Module::canBeUpgraded()` les détecte séparément de l'API :

```php
if ($this->hasNewVersionAvailable()) return true;                 // API — neutralisé
return version_compare($db_version, $disk_version, '<');          // disque — conservé
```

Bumper la version dans `ps_facetedsearch.php` continue donc de déclencher les scripts `upgrade/upgrade-*.php`.

### 6.5 Compatibilité de l'override

Vérifiée contre la branche `dev` d'upstream (version 2.1.1) le 2026-09-16, alors que l'installation locale est en 1.2.1 sur disque. Sont identiques entre les deux : la classe `Ps_Distributionapiclient extends Module`, les trois hooks enregistrés, les signatures `hookActionListModules(): array` et `hookActionBeforeUpgradeModule(array $params): void`, ainsi que les clés renvoyées par `DistributionApi::getModuleList()` — dont `name`, seule clé dont dépend le filtre.

À revérifier si upstream change l'une de ces signatures ou la structure de `getModuleList()`.

### 6.6 Détecter et réparer

```bash
# doit toujours ne rien afficher : si des M apparaissent, le fork est désactivé
git -C modules/ps_facetedsearch status -s src/

# rétablir
git -C modules/ps_facetedsearch checkout -- src/
```

Après restauration, vider le cache des blocs de filtres (§4) et relancer `composer install` dans le module si les tests ne démarrent plus — le `vendor/` de dev est écrasé lui aussi.
