# Bilan de la parité WP_Query, audit restant, plan WP_Term_Query

Rédigé le 2026-10-08, après l'exécution du plan `2026-10-wp-query-parity.md`. La suite se fait sur une branche partant de `feat/wp-query-parity` (PR #37), ou de `main` une fois #36 et #37 fusionnées.

## 1. Ce qui a été fait (PR #37)

Les six phases du plan précédent sont livrées, avec un commit par phase. La PR #37 est empilée sur #36 (base `feat/admin-redesign`).

**Principe appliqué : traduire ou se replier.** Un argument est soit traduit fidèlement, soit la requête entière passe par MySQL avec une raison enregistrée (`unsupported_arg:<var>`, `unindexed_meta:<clé>`, `schema_too_old`…). Une clause n'est jamais abandonnée en silence.

| Phase | Livré |
|---|---|
| 0 | Banc `wp meiliscout check-queries` (`src/Diagnostics/QueryParity`) et suite d'intégration `composer test:integration` (cas de parité, et syntaxe de chaque filtre produit par les tests unitaires contre un vrai Meilisearch) |
| 1 | `QuerySupport` (liste blanche des variables, statuts et types non indexés), lecture de l'état analysé par WP (`QueryVars`), build pur dans le `try`, `UnsupportedQuery`, échappement, totaux exacts, `fields`, hydratation depuis la base, `sort` en tête des règles de classement, replis par raison dans la vue d'ensemble, B1 à B15 corrigés |
| 2 | Schéma v3 (ids numériques, `has_password`, timestamps et parties de dates, `post_title_sort`). `p`, `name`, `pagename`, `post__in`, parents, auteurs, `date_query` complet et raccourcis, tous les `orderby`, ordres PHP (`rand`, `post__in`…) |
| 3 | `LIKE` via `CONTAINS` (option), `sentence`, `search_columns`, contenus privés en option avec filtre de droits |
| 4 | Intégration automatique (Réglages › Requêtes), en-tête `X-MeiliScout`, panneau Query Monitor, testeur WP_Query dans l'admin |
| 5 | `docs/WP_QUERY.md`, `docs/FILTERS.md`, `CLAUDE.md`, `CHANGELOG.md`, traductions |

**État mesuré sur la démo** (146 cas) : 0 DIFF, 133 OK, 7 INFO (recherches `s`), 6 replis voulus (brouillons, `REGEXP`, méta non indexée, `exact`, article privé demandé par `p`). Tests : 248 unitaires, 148 d'intégration, PHPStan propre.

**Décisions prises pendant l'exécution** (à garder en tête) :
- **Métas sans `type` :** une méta numérique comparée ou triée sans `type` l'est comme un nombre (MySQL : comme du texte). C'est documenté, dans la ligne de la décision du plan sur `>` sans type.
- **Ex aequo :** le banc a un mode `sorted` qui compare la suite des clés de tri, car aucun des deux moteurs ne garantit l'ordre entre égaux.
- **Requêtes singulières :** une requête singulière (`p`, `name`…) visant un contenu dont le statut n'est pas servi par l'index repasse sur MySQL, connecté ou non (WP compte ce contenu dans `found_posts`).
- **Statuts interrogeables :** les requêtes s'appuient sur les statuts envoyés par la dernière indexation complète (`PostIndexable::queryableStatuses()`), pas sur le réglage seul.
- **Ordres PHP :** `rand` et les ordres de liste sont appliqués en PHP jusqu'à 1000 résultats ; au-delà, MySQL.

## 2. Audit restant côté WP_Query

But : s'assurer que **chaque** argument possible est soit traduit, soit balisé comme repli, et le prouver dans le banc.

### Inventaire des arguments de WP_Query (WP 7.1)

Sources : `fill_query_vars()` et la doc de `WP_Query::parse_query()`.

| Argument | État | Raison de repli |
|---|---|---|
| `attachment`, `attachment_id`, `subpost`, `subpost_id` | ❌ | `unsupported_arg` (les pièces jointes, statut `inherit`, ne sont pas indexées) |
| `title` | ❌ | `unsupported_arg:title`. Traduisible facilement : égalité sur `post_title`, insensible à la casse comme la collation MySQL |
| `post_mime_type` | ❌ | `unsupported_arg` (lié aux pièces jointes) |
| `comment_status`, `ping_status` | ❌ | `unsupported_arg`. Traduisibles facilement (champs déjà dans le document, à rendre filtrables) |
| `perm`, `post_password` | ❌ | `unsupported_arg` |
| `exact` | ❌ | `unsupported_arg` (LIKE sans `%`, pas d'équivalent) |
| `meta_compare_key`, `meta_type_key`, `compare_key`, `type_key` | ❌ | `unsupported_meta_compare_key` |
| `REGEXP`, `NOT REGEXP`, `RLIKE` | ❌ | `unsupported_compare` |
| `embed`, `feed`, `tb`, `preview`, `error`, `page`, `cpage`, `comments_per_page`… | ✅ | sans effet sur les contenus renvoyés |
| tout le reste de la doc (`p` … `w`, `tax_query`, `meta_query`, `date_query`, `orderby`, pagination, `fields`) | ✅ | voir `docs/WP_QUERY.md` |

Aucun argument documenté n'est ignoré en silence : ce qui n'est pas traduit tombe dans la liste blanche inversée de `QuerySupport`.

### Trous identifiés, à traiter

1. **Filtres SQL des extensions (le plus important).** Les filtres `posts_where`, `posts_join`, `posts_clauses`, `posts_search`, `posts_orderby`, `posts_groupby`, `posts_distinct` et `posts_fields` sont ignorés quand Meilisearch sert la requête, c'est-à-dire sans `suppress_filters`. Une extension qui restreint par SQL (multilingue, restrictions d'accès, WooCommerce : visibilité du catalogue, stock) produit alors un résultat différent sans signal. À prévoir :
   - comparer les callbacks accrochés à ces filtres à une liste de callbacks connus du cœur ;
   - repli `sql_filter:<hook>` si un callback inconnu est présent ;
   - filtre `meiliscout/ignored_sql_filters` pour déclarer ceux qui sont sans effet ou déjà traduits ;
   - un cas de banc avec un `posts_where` de test.
2. **Variables publiques de WP** (`WP::$public_query_vars` : `robots`, `favicon`, `sitemap`, `rest_route`, `post_format`…). Elles sont couvertes par le repli générique ; vérifier qu'aucune requête principale courante n'est envoyée inutilement sur MySQL.
3. **Banc : un cas par argument non traduit**, qui doit sortir FALLBACK avec la bonne raison. Manquent : `title`, `post_mime_type`, `comment_status`, `ping_status`, `perm`, `post_password`, `attachment`, `subpost`, `compare_key`, `tax_query` sans `taxonomy`, `date_query` sur `comment_date`, colonne GMT avec parties. Ajouter aussi :
   - un test qui parcourt `fill_query_vars()` + la doc et vérifie que chaque variable est soit dans `QuerySupport::HANDLED` / les `VARS` des builders, soit couverte par un cas FALLBACK ;
   - ce test casse quand une version de WP ajoute un argument.
4. **Traductions faciles à ajouter :** `title`, `comment_status`, `ping_status`. `post_password` également (champ non indexé aujourd'hui, à ne pas exposer : stocker un hash ? sinon laisser en repli).
5. **Métas multivaluées et valeurs vides :** seule la première valeur est indexée, les valeurs vides ne le sont pas. Option : indexer la liste complète (Meilisearch filtre sur les tableaux), et `''` pour les valeurs vides. Schéma 4.
6. **Pièces jointes :** les indexer avec le statut `inherit` (option) permettrait de servir les médiathèques et `post_type => any` avec `post_status => inherit`. À évaluer selon la demande.
7. **Cache objet persistant :** les objets viennent désormais de la base, donc le risque B7 disparaît en principe. Refaire un passage du banc avec Redis pour le confirmer.

## 3. Plan WP_Term_Query (`get_terms()`)

### Constat
- WP propose le court-circuit `terms_pre_query` (WP 5.3+), symétrique de `posts_pre_query`. **Attention :** quand on court-circuite, WP renvoie notre tableau tel quel. Tout le post-traitement est sauté : `child_of`, `pad_counts`, `hide_empty` hiérarchique, `number`/`offset` hiérarchiques, mise en forme selon `fields`, `populate_terms`. Il faut donc renvoyer la sortie finale, dans le format demandé.
- L'index `taxonomies` existe (`TaxonomyIndexable`), mais son document est minimal : il n'a que `taxonomy` en filtrable et `name` en triable.
- **Bugs relevés à la lecture, à corriger d'abord :**
  - les métas de termes reprennent la sélection `indexed_meta_keys`, qui est celle des **métas d'articles** ;
  - `getItems()` applique le même `offset` à chaque taxonomie, ce qui fausse l'indexation par lots ;
  - le nombre d'articles d'un terme (`count`) n'est pas réindexé quand il change : il n'y a pas de hook sur `edited_term_taxonomy`, déclenché par `wp_update_term_count_now()` ;
  - l'index des termes est créé deux fois par indexation complète (déjà noté dans #36).

### Arguments de `WP_Term_Query` et traduction prévue

| Argument | Traduction |
|---|---|
| `taxonomy` | `taxonomy IN [...]`, taxonomie non indexée → repli |
| `include`, `exclude` | `term_id IN / NOT IN` |
| `exclude_tree` | champ `ancestors` (liste) : exclure le terme et `ancestors` contenant l'id |
| `child_of` | `ancestors` contient l'id |
| `parent` | `parent = n` |
| `childless` | champ booléen `has_children`, à réindexer quand un enfant est ajouté, déplacé ou supprimé |
| `name`, `slug`, `term_taxonomy_id` | égalités et `IN` (slugs passés par `sanitize_title`) |
| `hide_empty` | `count > 0`. En hiérarchique, WP garde un parent vide qui a des descendants non vides : champ `count_with_children`, calculé comme `_pad_term_counts` |
| `pad_counts` | renvoyer `count` remplacé par `count_with_children` (objets hydratés puis mis à jour, comme WP) |
| `search` | WP fait `name LIKE %s% OR slug LIKE %s%`. Deux options : `CONTAINS` sur `name`/`slug` (exact, si la fonctionnalité est activée), sinon la recherche Meilisearch (classement différent, comparé en INFO dans le banc) |
| `name__like`, `description__like` | `CONTAINS` (option), sinon repli |
| `number`, `offset` | pagination ; en hiérarchique avec `hide_empty`, WP pagine après le filtrage PHP : à reproduire |
| `fields` | `all`, `ids`, `names`, `slugs`, `count`, `id=>name`, `id=>slug`, `id=>parent`, `tt_ids` : sortie produite nous-mêmes. `all_with_object_id` → repli |
| `object_ids` | repli au départ (relation article/terme ; possible plus tard via l'index des articles) |
| `orderby` | `name` (clé repliée comme `post_title_sort`), `slug`, `term_group`, `term_id`/`id`, `description`, `parent`, `count`, `none`, `include` et `slug__in` (ordre PHP), `meta_value(_num)`/clause nommée. `term_order` → repli (n'existe qu'avec `object_ids`) |
| `meta_query`, `meta_key`, `meta_value`… | réutiliser `MetaQueryBuilder` avec une sélection de **métas de termes** propre (nouveau réglage) |
| `get => 'all'`, `cache_domain`, `cache_results`, `update_term_meta_cache` | sans effet |
| filtres `terms_clauses`, `get_terms_fields`, `list_terms_exclusions` | même traitement que les filtres SQL des articles (§ 2.1) |

### Architecture visée
- `TermQueryIntegration` sur `terms_pre_query`, sur le modèle de `QueryIntegration`, avec :
  - `TermQuerySupport` (liste blanche) ;
  - `TermQueryBuilder` (filtres, tri, pagination) ;
  - hydratation : ids depuis Meilisearch → `_prime_term_caches()` → `get_term()` → mise en forme selon `fields` ;
  - `$query->meiliscout` et `QueryLog` partagés (onglet Termes dans Query Monitor).
- **Déclenchement :** opt-in `'use_meilisearch' => true` dans les arguments de `get_terms()` (WP conserve les clés inconnues). Réglages › Requêtes ajoute :
  - les recherches de termes (boîte des catégories et étiquettes de l'éditeur, `ajax-tag-search`) ;
  - le REST `/wp/v2/categories?search=` ;
  - les listes de termes de l'admin ;
  - le tout désactivé par défaut.
- **Document de terme (schéma 4) :** `term_id`, `term_taxonomy_id`, `taxonomy`, `name`, `name_sort`, `slug`, `description`, `parent`, `ancestors`, `depth`, `has_children`, `count`, `count_with_children`, `term_group`, `metas`.
- **Fraîcheur :**
  - réindexer le terme sur `edited_term_taxonomy` (compteurs), avec ses ancêtres (`count_with_children`, `has_children`) ;
  - regrouper au `shutdown` comme pour les articles : la publication d'un article touche plusieurs termes.

### Phases proposées
- **T0, banc :** `TermQueryParity` et `wp meiliscout check-queries --terms` (ou `check-terms`), avec des cas pris dans les données du site ; tests d'intégration.
- **T1, corrections :** les quatre bugs du constat, et une sélection propre des métas de termes (écran Contenus).
- **T2, schéma 4 et cœur :** `taxonomy`, `include`/`exclude`, `parent`, `name`/`slug`/`term_taxonomy_id`, `hide_empty` non hiérarchique, `number`/`offset`, tous les `fields` sauf `all_with_object_id`, `orderby` simples. Tout le reste en repli motivé. Critère : 0 DIFF.
- **T3, hiérarchie :** `child_of`, `exclude_tree`, `childless`, `hide_empty` et pagination hiérarchiques, `pad_counts`.
- **T4, recherche et métas :** `search`, `name__like`, `description__like`, `meta_query`, `orderby` sur les métas.
- **T5, intégration et documentation :** réglages, Query Monitor, testeur admin (mode « arguments get_terms »), `docs/TERM_QUERY.md`.

### Décisions (prises le 2026-10-08)
1. **`search` :** parité stricte quand la fonctionnalité expérimentale `containsFilter` est activée sur l'instance (`ContainsFilter::enabled()`). `search` devient alors `(name CONTAINS s OR slug CONTAINS s)`, avec un ordre et des totaux identiques à MySQL. Sinon, c'est la recherche Meilisearch, classée par pertinence, comme pour `s` côté articles. Dans le banc, le premier cas est comparé strictement, le second en INFO. `name__like` et `description__like` suivent la même règle de disponibilité ; sans `CONTAINS`, ils repassent sur MySQL, car ce ne sont pas des recherches.
2. **Métas de termes :** sélection séparée de celle des métas d'articles.
   - nouveau réglage `indexed_term_meta_keys`, choisi dans l'écran Contenus, avec un catalogue des clés de métas de termes sur le modèle de `MetaKeyCatalog` ;
   - les clés de termes manquées par les requêtes sont proposées comme pour les articles.
   
   Cela corrige au passage le bug qui faisait reprendre aux termes la sélection des métas d'articles.
3. **`object_ids` :** même règle que pour WP_Query, traduire fidèlement ou se replier.
   - **Traduction :** via l'index des articles, qui porte `taxonomies.<taxonomie>.term_id`. On récupère les termes des articles donnés, puis on filtre l'index des termes sur ces ids.
   - **Condition :** que l'index connaisse tous ces articles. Si l'un d'eux a un type ou un statut non indexé (vérification en base, comme `QuerySupport`), repli (`unindexed_object`).
   - **Restent en repli :** `fields => all_with_object_id` et `orderby => term_order`, qui dépendent de `term_relationships`.
   - **Calendrier :** `object_ids` reste en repli en T2 et est traduit en T4.
4. **Version de schéma par index :**
   - `IndexNames` passe d'une version commune à une version par index : `SCHEMA_VERSIONS = ['posts' => 3, 'taxonomies' => 4]` ;
   - l'option `meiliscout/schema_version` devient un tableau par base, en relisant l'entier actuel comme la version de chaque index ;
   - `activeSchema($base)` et `migrationPending()` se lisent index par index, et une indexation complète n'active que les index qu'elle a reconstruits ;
   - passer les termes en v4 n'impose pas de réindexer les articles ;
   - à faire en T1, avant le schéma 4 des termes.

## 4. Bilan de l'exécution (2026-10-08)

Branche `feat/term-query`, partie de `feat/wp-query-parity`, un commit par étape.

| Étape | Commit | Livré |
|---|---|---|
| T1 | `65b00a2` | Version de schéma par index (`SCHEMA_VERSIONS`, lecture de l'ancien entier, activation des seuls index reconstruits). Métas de termes à part (`indexed_term_meta_keys`, écran Contenus › Champs des termes, clés manquées des requêtes de termes). `getItems()` pagine sur l'ensemble des taxonomies. Compteurs réindexés sur `edited_term_taxonomy`. Index créé une seule fois par indexation complète |
| Audit § 2 | `cc273d4` | 2.1 : `SqlFilters` compare ce que chaque filtre SQL reçoit et renvoie ; repli `sql_filter:<hook>` seulement si un callback a changé la requête, `meiliscout/ignored_sql_filters`. 2.2 : requêtes principales vérifiées (`X-MeiliScout`), toutes servies. 2.3 : `QuerySupport::UNTRANSLATED` (un cas de banc FALLBACK par argument) et test d'intégration qui parcourt `fill_query_vars()` + variables publiques et privées + la doc ; il a trouvé `withcomments` (repli) et des variables publiques héritées sans effet. 2.4 : `title`, `comment_status`, `ping_status` traduits ; `post_password` reste en repli (rien de sûr à indexer). 2.5 : schéma 4 des articles (toutes les valeurs, `''` gardé, `IS EMPTY`, `TO` pour `BETWEEN`), `MetaValueFlags` et replis `multivalued_meta`, `structured_meta`, `meta_not_numeric` |
| T0, T2, T3 | `cee802b` | `TermQueryIntegration` sur `terms_pre_query`, `TermQueryBuilder` / `TermQueryPlan` / `TermResults` (post-traitement de WordPress avec ses propres fonctions, clés de tableau comprises), `TermSqlFilters` (pile de frames, les filtres ne reçoivent pas la requête), schéma 4 des termes (`name_sort`, `description_fold`, `description_sort`, `tree_count`), ancêtres réindexés. Banc `check-queries --terms` |
| T4 | `fa726f7` | `object_ids` via l'index des articles (`ObjectTerms`) ; un article dont les termes changent hors sauvegarde est réindexé (`set_object_terms`, `deleted_term_relationships`) |
| T5 | `ebb9fc5` | Réglages › Requêtes › Requêtes de termes, Query Monitor, testeur get_terms(), `docs/TERM_QUERY.md`, traductions |

**Écarts au plan :**
- `ancestors` et `has_children` ne sont pas indexés : `child_of`, `exclude_tree` et `childless` reprennent ce que WordPress a calculé (exclusions lues dans la requête, `_get_term_children()` sur la liste), ce qui garde aussi les clés de tableau. Seul `tree_count` est indexé, pour `hide_empty` hiérarchique.
- `count_with_children` n'est pas indexé : `pad_counts` dépend des termes du résultat, il est calculé par `_pad_term_counts()` comme dans WordPress.
- Les arguments inconnus de `WP_Term_Query` ne provoquent pas de repli (au contraire de `WP_Query`) : seuls les filtres SQL peuvent les exploiter, et ils sont surveillés.
- `object_ids` est traduit dès T4, comme décidé, avec un repli si l'article n'est pas d'un type de la taxonomie.

**État mesuré sur la démo :** articles 141 OK, 38 replis voulus, 7 INFO, 0 DIFF ; termes 75 OK, 12 replis voulus, 1 INFO (recherche sans CONTAINS), 0 DIFF. Tests : 276 unitaires, 285 d'intégration, PHPStan propre.

**Reste :**
- 2.6, pièces jointes : non fait, à décider selon la demande.
- 2.7, passage du banc avec un cache objet persistant (Redis) : la démo n'en a pas ; à faire après l'ajout de l'add-on DDEV.
