# Plan d'intervention : parité WP_Query ↔ Meilisearch (`use_meilisearch`)

Étude du 2026-10-08. À exécuter dans une nouvelle session, **une fois la PR #36 (admin) fusionnée**, sur une branche partant de `main` (`feat/wp-query-parity`).

## 1. Constat

### Méthode
- **Banc différentiel** sur la démo DDEV (`~/Sites/meiliscout-demo/scripts/query-parity.php`). Il exécute chaque `WP_Query` sur MySQL puis avec `use_meilisearch`, et compare les IDs, l'ordre, `found_posts`, `max_num_pages` et les champs des `WP_Post`. Il couvre 127 cas, sur tous les arguments de WP_Query.
  - Lancement : `ddev wp eval-file scripts/query-parity.php [filtre] [json=chemin]`.
  - Données complémentaires : `scripts/query-fixtures.php` (2ᵉ auteur, articles épinglés, commentaires, article privé).
- **Lecture du code** : `src/Query/**`, `PostIndexable`, `IndexNames`.
- **Référence ElasticPress 5.3.5**, lue dans le code d'EP (`Indexable/Post/Post.php`, `QueryIntegration.php`, `DateQuery.php`).

### Résultat de base (main + PR #36, Meilisearch 1.54.3, WP 7.1.3)
`{"OK":40, "DIFF":76, "FALLBACK":4, "INFO":7}` sur 127 cas.

**Ce qui marche bien :**
- `post_type` (chaîne, tableau, `any`) et `post_status=publish`.
- `tax_query` sous toutes ses formes : `term_id`, `slug`, `name`, `term_taxonomy_id`, `IN`, `NOT IN`, `AND`, `EXISTS`, `NOT EXISTS`, relations imbriquées, `include_children`.
- `meta_query` avec `=`, `!=`, `>`/`<`, `IN`/`NOT IN`, `BETWEEN`, `EXISTS`/`NOT EXISTS`, `DATE`, relations imbriquées.
- `orderby` `date`, `ID` et `rand`, la pagination simple (`posts_per_page`/`paged`) et `-1` (sous 1000 résultats).
- Les `WP_Post` renvoyés ont les mêmes champs que ceux de MySQL dans les cas comparés.
- Les dates stockées en chaîne se comparent correctement dans les filtres Meilisearch.

**Le problème principal : des résultats faux sans aucun signal.** Une trentaine d'arguments courants sont ignorés en silence. Meilisearch répond alors avec *tous* les contenus du type, sans repli MySQL ni erreur :
- `p`, `name`, `pagename`, `page_id`, `post__in`, `post__not_in`, `post_name__in` ;
- `post_parent*`, `author*`, `author_name` ;
- `cat`, `category_name`, `category__*`, `tag*`, et une variable de requête de taxonomie personnalisée (`project_type=refonte`) ;
- `year`, `monthnum`, `m`, `w`, `day` et tout `date_query` ;
- `has_password`, `comment_count`, `offset`, `nopaging` ;
- les `orderby` `name`, `modified`, `author`, `menu_order`, `parent`, `comment_count`, `type`, `none`, `post__in`.

Une archive de catégorie servie par Meilisearch affiche donc tous les articles.

### Bugs confirmés (les numéros B renvoient au rapport de lecture du code)

| # | Bug | Cause | Preuve |
|---|-----|-------|--------|
| B1 | `orderby=meta_value(_num)` + `meta_key` renvoie 0 résultat ; `meta_key` seul renvoie 0 au lieu de « la clé existe » | `fill_query_vars` met `meta_value=''`. `MeiliQueryBuilder::build()` (l. 66-79) en fait `metas.k = ''`, puis **réécrit** `meta_query` dans la requête, ce qui double la clause à chaque nouveau build | banc : 70 → 0, 20 → 0 |
| B2 | Raccourcis de taxonomie ignorés | seul `query_vars['tax_query']` est lu ; WP range le résultat analysé dans `$query->tax_query->queries` | banc : `cat` 45 → 182 |
| B8 | `fields=ids` / `id=>parent` donnent un `found_posts` faux (1 au lieu de 182) | WP rappelle `set_found_posts()` puis `SELECT FOUND_ROWS()` après le court-circuit | banc et sonde |
| B10 | `post_status` draft ou private, et utilisateur connecté ayant des contenus privés : 0 résultat au lieu d'un repli | seul `publish` est indexé, et aucun repli n'est prévu | banc : 12 → 0, 1 → 0 |
| — | `tax_query NOT IN` sans `post_type` mélange tous les types (274 au lieu de 163) | le type par défaut avec `tax_query` vaut « tous », alors que WP prend les types de la taxonomie | banc |
| — | `meta_query` `LIKE`/`NOT LIKE`/`REGEXP` : filtre invalide, aller-retour perdu, puis repli | les tests unitaires valident une sortie que Meilisearch rejette (`MetaQueryTest` l. 163-213) | banc : FALLBACK après erreur |
| B3 | Opérateurs sensibles à la casse : `'not in'` devient IN (résultat inversé), `'not exists'` est ignoré, `'numeric'` devient CHAR | `EnumValidator::tryFrom` | lecture |
| B4 | Une clause non prise en charge est supprimée en silence (en OR, le résultat rétrécit ; en AND, il s'élargit). Un groupe vide produit `()`, une erreur de syntaxe | `AbstractFilterBuilder` l. 81 | lecture |
| B5 | Pas d'échappement de `\`. Une valeur `NUMERIC` est insérée brute, ce qui permet d'injecter un filtre | `FormatsValues` l. 31, `MetaQueryBuilder` l. 153 | lecture |
| B9 | `TypeError` fatal, car le build est **hors** du `try` : valeur booléenne (ACF true/false), entier dans `post_status`, `compare` en tableau | `QueryIntegration` l. 69 | lecture |
| B11 | `found_posts` est estimé et plafonné à 1000 (`maxTotalHits` non configuré). `-1` est aussi plafonné à 1000, et les pages au-delà de l'offset 1000 sont vides | `PaginationBuilder`, réglages d'index | `pagination: {"maxTotalHits":1000}` |
| B12 | `DateQueryBuilder` attend un format maison (`column` + `value`) que WP n'utilise jamais, et vise `date.<col>`, un champ qui n'existe pas | — | lecture et banc |
| B15 | Écriture en base sur le front quand une clé non indexée est rencontrée (`non_indexable_meta_keys`) | `MetaQueryBuilder` l. 191 | lecture |

**Écarts sans gravité ou attendus**, à documenter plutôt qu'à corriger :
- **Tri par titre :** le début de liste est identique ; l'écart vient de l'interclassement MySQL (accents, casse) face au tri de Meilisearch.
- **`no_found_rows` :** Meilisearch remplit quand même `found_posts`.
- **Page au-delà des résultats :** WP renvoie `found_posts = 0`.
- **`meta_query` `>` sans type :** MySQL compare des chaînes, Meilisearch des nombres. Le comportement de Meilisearch est plus juste.
- **`s` :** sémantique différente par nature (`LIKE` contre recherche avec classement). Sur la démo, 145 résultats MySQL contre 156 côté Meilisearch, avec un ordre volontairement différent. Les cas `s` ne sont donc pas comparés strictement.

**Point à revérifier :** les objets construits depuis les documents pourraient polluer le cache des posts (risque B7 relevé à la lecture du code). Non reproduit sans cache objet persistant : `get_post()` renvoie le bon `post_password` après une boucle Meilisearch. À refaire avec Redis.

### Comparaison avec ElasticPress
ElasticPress traduit la plupart des arguments, mais **en ignore aussi plusieurs en silence** :
- `p`, `name`, `pagename`, `page_id`, `exact`, `sentence`, `comment_count`, `has_password=true`, `perm`, `lang` ;
- `REGEXP` devient une égalité.

Il ne bascule sur MySQL qu'en cas d'erreur du moteur. Les idées à reprendre :

| Idée ElasticPress | Comment |
|---|---|
| Taxonomies | lire `$query->tax_query->queries` (cat, tag, etc. sont alors couverts sans code dédié) |
| Champs de date | stocker les parties (`date_terms`) et des timestamps |
| Typage des métas | à l'indexation, pas à la requête |
| Totaux | corriger `found_posts` via le filtre `found_posts` |
| `fields` | `ids` et `id=>parent` renvoyés sans charger les posts |
| Agrégations | lues sur `$query` |
| Déclenchement | `ep_integrate` en opt-in, plus une intégration automatique de la recherche, avec les filtres `ep_skip_query_integration` et `ep_elasticpress_enabled` |
| Débogage | journal des requêtes échouées, en-tête `X-ElasticPress-Query`, Debug Bar avec « Copy as cURL » |

**Notre principe va plus loin :** ne **jamais** renvoyer un résultat faux en silence. Un argument est soit traduit fidèlement, soit la requête repasse sur MySQL avec une raison enregistrée.

## 2. Principes de conception

1. **Traduire ou se replier.** Une étape de contrôle des arguments s'exécute avant le build. Tout argument non vide que les builders ne traduisent pas fidèlement déclenche un repli MySQL, avec une raison (`unsupported_arg:<nom>`, `unindexed_status`, `unindexed_meta:<clé>`, `engine_error`, `schema_too_old`). Une liste blanche explicite remplace l'actuelle liste noire implicite.
2. **Lire l'état analysé par WordPress**, pas les variables brutes :
   - `$query->tax_query->queries` (contient déjà `cat`, `tag`, `category_name`, `taxonomy=term` et `include_children` développé) ;
   - `$query->meta_query->queries` ;
   - `WP_Date_Query` (`build_mysql_datetime()` résout « 6 months ago » et les tableaux) ;
   - les variables déjà normalisées par `fill_query_vars`/`parse_query` (`''` veut dire absent).
3. **Ne jamais modifier la requête** dans le build (supprimer le `$query->set('meta_query')`). Le build doit être une fonction pure : même requête, mêmes paramètres.
4. **Champs de document versionnés.** Les nouveaux champs passent par `SCHEMA_VERSION = 3`. Une requête qui en dépend repasse sur MySQL tant que `IndexNames::activeSchema() < 3` ; le mécanisme de migration de la 2.0 s'en charge.
5. **Le banc différentiel devient l'oracle.** Il vit dans le dépôt, comme commande WP-CLI, et chaque phase se termine par une passe sans DIFF.

## 3. Phases

### Phase 0 : outillage (½ jour)
- Livrer le banc comme **commande WP-CLI** : `wp meiliscout check-queries [--case=<filtre>] [--args=<json>] [--format=table|json]`.
  - Cas intégrés : ceux de `scripts/query-parity.php`, rendus indépendants des données (ils choisissent leurs IDs, termes et auteurs dans la base).
  - Utilisable sur n'importe quel site, la préproduction d'Aleteia comprise.
  - Résumé final avec les compteurs, et code de sortie non nul en cas de DIFF.
- **Tests d'intégration** dans `tests/Integration/` (groupe Pest `integration`, exclu de la CI unitaire). Ils tournent dans le conteneur de la démo (`ddev exec --dir …/plugins/meiliscout vendor/bin/pest --group=integration`) contre le vrai WordPress et le vrai Meilisearch.
- **Test de syntaxe des filtres** : chaque filtre produit par les tests unitaires des builders est validé contre une instance Meilisearch réelle. Cela aurait détecté le cas `LIKE`.
- Remplacer les assertions de `MetaQueryTest` qui valident une sortie invalide (`LIKE`, `REGEXP`).

### Phase 1 : ne plus jamais être faux (sans changer le schéma)
Objectif : 0 DIFF hors cas `s`. Ce qui n'est pas encore traduit devient un FALLBACK motivé.

1. **Contrôle des arguments** : nouvelle classe `Query/QuerySupport` qui renvoie `null` (pris en charge) ou une raison.
   - Elle couvre tous les arguments de la section 1 non pris en charge, les statuts non indexés (en comparant au statut effectif, y compris `private` ajouté par WP pour un utilisateur connecté qui peut le lire), `post_status` séparé par des virgules, les opérateurs `LIKE`/`REGEXP`/`RLIKE`/`compare_key`, et les `orderby` non triables.
   - `QueryIntegration` la consulte en premier.
2. **B1** : `meta_value` vide est ignoré. `meta_key` seul devient `EXISTS`, comme `WP_Meta_Query::parse_query_vars`. Le build ne modifie plus la requête.
3. **B2** : `TaxQueryBuilder` lit `$query->tax_query->queries`. Les clauses de `WP_Tax_Query` sont déjà normalisées ; vérifier leurs champs et le développement de `include_children`. Le type par défaut avec une taxonomie devient l'ensemble des types de cette taxonomie qui sont indexés, comme dans WP.
4. **B8** : quand Meilisearch a répondu, filtrer `found_posts_query` (renvoyer `''`, pour éviter le `FOUND_ROWS()`) et `found_posts` (renvoyer notre total). Couvrir `fields=ids`, `id=>parent` et les objets complets.
5. **B3, B4, B5, B9** :
   - opérateurs, `compare` et `type` en majuscules ;
   - un groupe vide n'émet rien ;
   - une clause non traduisible fait repasser **toute** la requête sur MySQL au lieu d'être supprimée ;
   - échappement de `\` et `'` ;
   - valeurs `NUMERIC` converties (`is_numeric` sinon repli) ;
   - booléens convertis en `'1'`/`'0'`, cohérents avec le stockage (à vérifier) ;
   - build placé dans le `try`, avec `\Throwable` capturé, ce qui donne un repli avec la raison `build_error`.
6. **Pagination** :
   - prise en charge de `offset` (qui remplace `paged`, comme dans WP) et de `nopaging` ;
   - `-1` et `nopaging` utilisent `maxTotalHits` ;
   - `maxTotalHits` est envoyé dans les réglages de l'index (valeur à décider, voir la section 4) ;
   - `found_posts` exact : utiliser `hitsPerPage`/`page`, qui donnent `totalHits` exhaustif, à la place de `limit`/`offset` (estimé) quand il n'y a pas d'`offset` ;
   - `no_found_rows` : ne plus calculer le total.
7. **Repli observable** : `SearchFallbacks` enregistre la **raison** (et non plus seulement erreur ou méta). La vue d'ensemble affiche la répartition des 24 dernières heures (« 12 replis : 8 `author`, 4 `date_query` »).
8. **Hydratation depuis la base** (décision 1) : `attributesToRetrieve` limité à `ID` (et `post_parent` pour `id=>parent`), puis `_prime_post_caches` ; filtre `meiliscout/hydrate_from_documents`.
9. **`maxTotalHits`** configurable (décision 2), et tri strict avec `s` (décision 5 : règles de classement, `matchingStrategy`).
10. **Supprimer l'écriture en base au front** (B15) : garder les clés manquées en mémoire et les écrire au `shutdown`, une fois, comme pour `SearchFallbacks`.

**Critère de fin :** `wp meiliscout check-queries` donne 0 DIFF (hors cas `s`), tous les replis ont une raison, et les tests unitaires et d'intégration passent.

### Phase 2 : couvrir les arguments manquants (schéma v3)
Champs ajoutés au document dans `PostIndexable::formatForIndexing` :
- types normalisés : `ID`, `post_author`, `post_parent`, `menu_order`, `comment_count` en entiers ;
- `post_name` ;
- `has_password` (booléen) ;
- `post_date_ts`, `post_date_gmt_ts`, `post_modified_ts`, `post_modified_gmt_ts` : timestamps. Les dates locales sont lues comme de l'UTC naïf, pour comparer avec ce que produit `WP_Date_Query` ;
- `date_parts` : `year`, `month`, `week` (mode WP `start_of_week`), `day`, `dayofweek`, `dayofweek_iso`, `dayofyear`, `hour`, `minute`, `second`, pour `post_date` et `post_modified` ;
- `post_title_sort` : titre en minuscules, sans accents, pour approcher l'interclassement MySQL ;
- `author_nicename` ? **Non** : `author_name` est résolu en ID à la requête (`get_user_by('slug')`), contrairement à EP qui utilise le nom affiché.

Réglages d'index :
- **filtrables** : `ID`, `post_name`, `post_author`, `post_parent`, `menu_order`, `comment_count`, `has_password`, les timestamps, `date_parts.*` ;
- **triables** : `ID`, `post_name`, `post_author`, `post_parent`, `menu_order`, `comment_count`, `post_type`, `post_title_sort`, `post_date_ts`, `post_modified_ts`, et les métas.

Traductions :
- **Contenu ciblé :**
  - `p`, `page_id` deviennent `ID = n` ;
  - `name`, `post_name__in` deviennent `post_name IN [...]` ;
  - pour `pagename`, chemin hiérarchique résolu en ID par `get_page_by_path` à la requête, ce qui évite d'indexer les chemins ;
  - `post__in` et `post__not_in` deviennent `ID IN` / `ID NOT IN`.
- **Parents et auteurs :** `post_parent`, `__in`, `__not_in`. Pour `author`, gérer les listes séparées par des virgules et les négatifs ; puis `author_name`, `author__in`, `author__not_in` (combinés en AND, comme WP, et non exclusifs comme EP).
- **Divers :** `has_password` ; `comment_count` (entier, ou tableau avec `value` et `compare`).
- **`date_query` complet**, réécriture de `DateQueryBuilder` sur la sémantique de `WP_Date_Query` :
  - `after`/`before` (chaîne ou tableau) et `inclusive` ;
  - `column` ;
  - les parties avec leurs `compare` (`=`, `!=`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `<`, `<=`, `>`, `>=`) ;
  - relations imbriquées ;
  - raccourcis `year`, `monthnum`, `w`, `day`, `hour`, `minute`, `second`, `m`.
  - Éviter les défauts d'EP : parties limitées à `post_date`, filtre écrasé en AND, imbrication non suivie.
- **`orderby`** :
  - `ID`, `name`, `author`, `modified`, `parent`, `menu_order`, `comment_count`, `type` ;
  - `title` sur `post_title_sort` ;
  - `none` : ordre ID croissant, comme le fait EP. En fait WP ne garantit aucun ordre, donc il faut seulement que le banc ne compare pas ce cas.
  - `post__in`, `post_name__in` et `post_parent__in` : réordonner en PHP quand tous les résultats tiennent sur une page (`post__in` est borné), sinon repli ;
  - `relevance` sans `s` : ordre par défaut ;
  - `rand` : Meilisearch n'a pas de tri aléatoire. Mélanger en PHP sur une page (`rand(seed)` avec la graine) quand `posts_per_page` couvre tout, sinon repli ;
  - `meta_value_num` et `meta_value` : corrigés en phase 1 ; vérifier que les contenus sans la clé sont **exclus**, comme dans WP (INNER JOIN), en ajoutant `metas.k EXISTS`.
- **Sémantique de `!=` et `NOT IN` sur les métas** : WP exige que la clé existe. Ajouter `metas.k EXISTS AND …`.

**Critère de fin :** `check-queries` OK sur toute la matrice, hors `s`, `REGEXP` et `rand` paginé. Tests de migration v2 → v3 : la recherche reste sur l'index actif et les requêtes v3 repassent sur MySQL tant que l'indexation complète n'a pas tourné.

### Phase 3 : recherche, métas `LIKE`, hydratation
- **`LIKE` / `NOT LIKE` sur les métas** (décision 3 : option dans Réglages › Avancé) : Meilisearch propose `CONTAINS` et `STARTS WITH` (ils figurent dans son message d'erreur), mais `CONTAINS` est une **fonctionnalité expérimentale à activer** (`containsFilter`). La traduction n'est active que si l'instance l'a ; sinon c'est un repli. `REGEXP` reste en repli.
- **`s` :**
  - `sentence=true` devient une recherche de phrase (`"…"`) ;
  - `search_columns` (WP 6.2, `post_title`, `post_excerpt`, `post_content`) devient `attributesToSearchOn` ;
  - `exact` : repli ;
  - `s='0'` : ne plus le perdre (`empty`) ;
  - documenter que `orderby` combiné à `s` ne départage qu'à pertinence égale, sauf si l'on place la règle `sort` en tête des règles de classement : à décider.
- ~~Hydratation~~ : décidée et avancée en phase 1 (section 4). Pour mémoire, aujourd'hui les `WP_Post` sont construits depuis le document. C'est rapide, mais la copie peut être obsolète (jusqu'à 5 min en asynchrone), elle n'a pas de `post_password` (le contenu protégé est vidé), elle n'a que les champs restreints par `displayed_attributes`, et le cache pourrait en être pollué (à revérifier avec Redis).
  - **Option recommandée :** demander seulement `ID` à Meilisearch (`attributesToRetrieve`), puis `_prime_post_caches($ids)` et `get_post`. Cela coûte une requête SQL indexée sur clé primaire et garantit fraîcheur et parité.
  - **Option rapide :** garder l'hydratation depuis le document derrière un filtre `meiliscout/hydrate_from_documents`.
- **Contenus privés** (décision 6 : option désactivée par défaut, avec encart sur les risques) : les indexer et filtrer selon `read_private_posts` ou l'auteur, comme le fait la fonctionnalité Protected Content d'EP. Sinon, le repli de la phase 1 suffit.

### Phase 4 : intégration et observabilité
- **Intégration automatique** (décision 4 : désactivée par défaut, proposée après la première indexation) réglable dans l'admin (onglet Réglages, section « Requêtes »), au lieu du mu-plugin de démo :
  - recherche du site ;
  - archives de types et de taxonomies ;
  - flux REST `/wp/v2/posts?search=` ;
  - listes de l'admin (option, comme `ep_admin_wp_query_integration`).
  
  Avec les filtres `meiliscout/integrate_query` (vote final) et `meiliscout/skip_query_integration`. Requêtes AJAX : par opt-in.
- **Débogage :**
  - `$query->meiliscout` : servie ou non, raison, paramètres envoyés, temps, index ;
  - en-tête `X-MeiliScout: served|fallback:<raison>` sur la requête principale ;
  - panneau Query Monitor (si l'extension est présente) listant chaque requête, ses paramètres Meilisearch, le temps et la raison du repli, avec « Copier en cURL ».
- **Testeur WP_Query dans l'admin** (onglet « Aperçu de recherche », mode « Arguments WP_Query ») : on colle des arguments, et l'écran montre côte à côte le résultat MySQL et le résultat Meilisearch, ou la raison du repli. Il réutilise le moteur de `check-queries`.

### Phase 5 : documentation et sortie
- `docs/WP_QUERY.md` :
  - tableau de couverture (argument, état, traduction, différences connues avec MySQL) ;
  - section « Pourquoi ma requête est passée par MySQL » ;
  - comparaison avec ElasticPress pour qui migre.
- Mettre à jour `CLAUDE.md` (architecture du système de requêtes, règle « traduire ou se replier ») et la page de documentation de l'admin.
- Note de version 2.0 : schéma v3 et indexation complète nécessaire. La migration se fait sans coupure grâce au mécanisme existant.

## 4. Décisions (prises le 2026-10-08)

1. **Hydratation : depuis la base.**
   - Meilisearch ne renvoie que les IDs (`attributesToRetrieve: ['ID']`, ce qui allège aussi la réponse), puis `_prime_post_caches($ids)` et `get_post()`.
   - On obtient les mêmes objets que MySQL (fraîcheur, `post_password`, champs complets, filtres `the_posts` habituels) et aucun risque de polluer le cache. Le coût est une requête SQL sur clé primaire, souvent servie par le cache objet.
   - Le filtre `meiliscout/hydrate_from_documents` (désactivé par défaut) garde l'hydratation depuis le document pour qui veut éviter cette requête et accepte les écarts.
   - `fields=ids` et `id=>parent` ne lisent jamais la base (`post_parent` est ajouté à `attributesToRetrieve`).
   - Conséquence : la phase 3 « hydratation » est avancée en **phase 1**. Elle simplifie la parité, car les écarts de champs des `WP_Post` disparaissent.

2. **`maxTotalHits` : 10 000 par défaut, réglable** dans Réglages › Avancé (« Résultats maximum par requête »).
   - Valeur envoyée dans les réglages `pagination` de chaque index.
   - `posts_per_page = -1` et `nopaging` s'arrêtent à cette valeur. Un avertissement est journalisé si le total la dépasse, pour ne pas tronquer en silence.
   - `found_posts` exact : utiliser `page`/`hitsPerPage`, ce qui donne `totalHits`, et non `limit`/`offset`, qui donne une estimation. Repasser sur `limit`/`offset` seulement quand un `offset` est demandé : le total reste alors exact via `totalHits` d'une requête `page` légère, ou le repli est accepté (à trancher en implémentant).

3. **`LIKE` / `NOT LIKE` : option dans Réglages › Avancé**, « Filtres partiels sur les champs (LIKE) ».
   - L'option n'apparaît que si la version de l'instance le permet (`CONTAINS` est expérimental depuis Meilisearch 1.10 ; détection par `/version` et `GET /experimental-features`).
   - L'activer appelle `PATCH /experimental-features {"containsFilter": true}`, et l'état réel de l'instance est affiché, car l'option peut aussi être changée hors du plugin.
   - Désactivée, une requête `LIKE` repasse sur MySQL avec la raison `unsupported_compare:LIKE`.
   - `REGEXP` et `RLIKE` repassent toujours sur MySQL.

4. **Intégration automatique : désactivée par défaut.** Activer à l'installation changerait la recherche du site avant même que l'index existe, ou avec une sélection de contenus incomplète.
   - Réglages › « Requêtes » : cases à cocher pour la recherche du site, les archives de types et de taxonomies, le REST `search` et les listes de l'admin.
   - Une fois la première indexation complète réussie, la vue d'ensemble **propose** d'activer la recherche du site (« Servir la recherche du site par Meilisearch »), avec un lien vers le réglage.
   - L'opt-in par requête (`use_meilisearch`) reste prioritaire dans les deux sens : `use_meilisearch => false` exclut une requête même si l'intégration automatique la couvre.

5. **Tri avec `s` : la règle `sort` passe en tête des règles de classement de l'index posts** : `["sort", "words", "typo", "proximity", "attribute", "exactness"]`. Une requête sans paramètre `sort` n'est pas affectée.
   - **Sans `orderby`, ou avec `relevance`** (défaut de WP quand `s` est présent) : aucun `sort` n'est envoyé, le classement par pertinence est inchangé.
   - **Avec un `orderby` explicite** (`date`, `title`, une méta…) : l'ordre est respecté strictement, comme sur MySQL, au lieu de seulement départager à pertinence égale.
   - Dans ce cas, envoyer aussi `matchingStrategy: "all"`, car MySQL exige tous les mots (`AND` de `LIKE`). Sinon un document ne contenant qu'un mot de la recherche passerait devant grâce à sa date.
   - Les règles de classement sont poussées par `IndexSettings` comme les autres réglages, donc changées à la prochaine indexation complète.
   - Documenter le choix ; le filtre `meiliscout/post/ranking_rules` permet de le modifier.

6. **Contenus privés : option désactivée par défaut**, Contenus › « Indexer les contenus privés », avec un encart qui expose les risques avant activation :
   - ces contenus seront stockés dans Meilisearch ; toute personne disposant d'une clé de recherche pour ces index peut les lire. Ne jamais exposer cette clé côté navigateur, et vérifier qui a accès à l'instance (tableau de bord Meilisearch Cloud, autres sites qui la partagent) ;
   - MeiliScout filtre à la requête selon les droits WordPress (`read_private_posts`, ou l'auteur pour ses propres contenus), mais une requête faite directement sur Meilisearch ne le ferait pas ;
   - les métas indexées de ces contenus le sont aussi.
   
   Option désactivée : une requête qui inclut le statut `private` (y compris le défaut WP pour un utilisateur connecté qui peut lire des contenus privés) repasse sur MySQL dès que le site **a** des contenus privés des types demandés ; sinon elle reste sur Meilisearch.
   
   Option activée : `meiliscout/indexable_post_statuses` inclut `private`, et `TypeStatusBuilder` ajoute le filtre de droits (`post_status = 'publish' OR (post_status = 'private' AND post_author = <user>)` si l'utilisateur ne peut pas lire tous les contenus privés).
   
   Les brouillons, contenus en attente et programmés ne sont jamais indexés : toujours repli MySQL.

## 5. Ordre de grandeur
- Phase 0 : ½ j.
- Phase 1 : 1,5 j.
- Phase 2 : 2 j.
- Phase 3 : 1 j.
- Phase 4 : 1,5 j.
- Phase 5 : ½ j.

Livraison en une PR par phase ; les phases 0 et 1 forment le minimum à livrer avant la 2.0.

## 6. Ressources
- Démo : `~/Sites/meiliscout-demo`. Le banc est dans `scripts/query-parity.php`, les données complémentaires dans `scripts/query-fixtures.php`, et la base de référence dans `scripts/parity.json` (127 cas, état au 2026-10-08).
- Code ElasticPress 5.3.5 de référence : `git clone https://github.com/10up/ElasticPress` (`includes/classes/Indexable/Post/Post.php` : `format_args`, `parse_orderby`, `parse_tax_query` ; `Indexable.php` : `build_meta_query` ; `Post/DateQuery.php`).
- Points de cœur WP où la requête court-circuitée continue son chemin : `class-wp-query.php`, `posts_pre_query` (≈ l. 3238), `set_found_posts` (≈ l. 3320 et 3341, `fields`), épinglés (≈ l. 3583), `update_post_caches` (≈ l. 3659).
