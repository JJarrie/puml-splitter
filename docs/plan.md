# Plan de développement — `puml-splitter`

Outil CLI PHP/Symfony qui post-traite la sortie PlantUML de `smeghead/php-class-diagram` pour découper un gros diagramme de classes (100+ classes, namespace à plat) en plusieurs sous-diagrammes lisibles, regroupés par clusters détectés automatiquement, avec une carte d'ensemble.

---

## 1. Contexte et problème

- Un namespace PHP à plat contient ~150 POPO sans sous-namespaces.
- `php-class-diagram` génère un unique script PlantUML : classes + relations sous forme de lignes `aliasA ..> aliasB`.
- À cette échelle, aucun moteur de layout (Graphviz, ELK) ne produit un rendu lisible.
- Certaines classes "hub" sont pointées par des dizaines d'autres et détruisent la lisibilité.

**L'outil ne parse PAS de PHP.** Il consomme le `.puml` produit par php-class-diagram (le graphe est déjà extrait) et opère uniquement sur ce graphe. Aucune dépendance à nikic/php-parser.

## 2. Objectifs / non-objectifs

### Objectifs
1. Parser un script PlantUML de diagramme de classes (format de sortie de php-class-diagram v1.6.x).
2. Construire un graphe orienté (nœuds = classes/interfaces/enums, arêtes = relations).
3. Détecter les hubs (fort degré entrant **ou sortant**) et leur appliquer une politique configurable, globalement ou par hub.
4. Partitionner le graphe en clusters de taille cible configurable.
5. Émettre un `.puml` par cluster + un `.puml` "carte" agrégée + un `index.html`.
6. Optionnellement invoquer `plantuml` pour produire les SVG.

### Non-objectifs (v1)
- Pas de parsing de code PHP source.
- Pas de support d'autres types de diagrammes PlantUML (séquence, package…) en entrée.
- Pas d'interface graphique.
- Pas de rendu PlantUML embarqué (délégué au binaire `plantuml` externe si présent).

## 3. Contraintes techniques

- PHP >= 8.2 typage strict partout (`declare(strict_types=1)`), propriétés `readonly` quand pertinent.
- `symfony/console` ^7.0 comme framework CLI (composant seul, pas le framework complet).
- `symfony/filesystem` et `symfony/process` autorisés. Minimiser toute autre dépendance.
- PHPUnit ^13 pour les tests. PHPStan niveau max.
- Distribution : projet Composer classique + build PHAR (via `box-project/box`) pour un usage standalone. Prévoir un `Dockerfile` (base `php:8.3-cli-alpine`, plantuml + graphviz installés) pour un usage 100% conteneurisé.
- Le code doit être structuré pour être testable sans I/O (logique de graphe pure, séparée des commandes).

## 4. Architecture

```
src/
├── Command/
│   └── SplitCommand.php          # commande unique `split`
├── Puml/
│   ├── Parser.php                # .puml → modèle
│   ├── Writer.php                # modèle/cluster → .puml
│   └── Model/
│       ├── Document.php          # headers, déclarations, relations
│       ├── ClassDeclaration.php  # alias, nom court, kind (class|interface|enum|abstract), corps brut (membres)
│       └── Relation.php          # source, cible, type de flèche, label éventuel
├── Graph/
│   ├── Graph.php                 # graphe orienté simple (adjacence, degrés)
│   ├── ConnectedComponents.php
│   ├── HubDetector.php
│   ├── LouvainClusterer.php      # détection de communautés (sur graphe non orienté dérivé)
│   ├── PrefixClusterer.php       # clustering par préfixe de nom de classe
│   └── ClusterRefiner.php        # split/merge pour respecter la taille cible
├── Output/
│   ├── ClusterPumlGenerator.php  # .puml d'un cluster (+ nœuds frontière <<external>>)
│   ├── OverviewPumlGenerator.php # carte : 1 package par cluster, arêtes agrégées
│   ├── IndexHtmlGenerator.php
│   └── SvgRenderer.php           # wrapper Process autour du binaire plantuml (optionnel)
└── Config/
    └── SplitConfig.php           # options résolues, immuable
```

Principe : `Parser` produit un `Document` immuable → pipeline `Graph` produit une `Partition` (liste de clusters + liste de hubs + arêtes inter-clusters) → générateurs `Output` écrivent les fichiers. Chaque étape testable isolément.

## 5. Format d'entrée à supporter (Parser)

Sortie type de php-class-diagram (à traiter ligne à ligne, tolérance aux variations d'indentation) :

```
@startuml class-diagram
  <headers éventuels : skinparam, !pragma, hide…>
  package foo as foo {            # possible mais rare en namespace à plat
    class "Name" as foo_Name {
      -name : string
    }
  }
  class "Product" as Product {
    -name : Name
  }
  abstract class "Base" as Base
  interface "X" as X
  enum "Suit" as Suit
  Product ..> Name
  Product ..> Price
@enduml
```

Règles de parsing :
- Déclarations : regex sur `^(abstract class|class|interface|enum)\s+"([^"]+)"\s+as\s+(\S+)` ; capturer le corps `{ … }` multi-lignes tel quel (bloc brut réémis à l'identique — le splitter ne réinterprète pas les membres).
- Relations : regex sur `^\s*(\S+)\s+(\.\.>|-->|<\|--|<\|\.\.|o--|\*--|-\[[^\]]*\]->)\s+(\S+)(\s*:\s*(.+))?$`. Conserver le type de flèche et le label.
- Headers : toute ligne entre `@startuml` et la première déclaration → conservée et réinjectée dans chaque fichier de sortie.
- Les relations d'héritage (`<|--`, `<|..`) comptent comme arêtes du graphe au même titre que les dépendances.
- Ligne non reconnue : conserver en "passthrough" attaché à la position, émettre un warning en sortie standard d'erreur, ne jamais crasher.

Fournir dans `tests/fixtures/` au moins : un petit puml synthétique, un puml avec packages, un puml de ~150 classes généré (peut être synthétique) pour les tests d'intégration.

### Anonymisation des fixtures réelles

### Anonymisation des fixtures réelles

Fournir `scripts/anonymize-puml.php` pour produire une fixture anonymisée depuis un `.puml` réel. Contraintes strictes :
- **Anonymisation par token, pas par alias** : chaque nom de classe est découpé en tokens CamelCase (`DateChainePenale` → `Date`, `Chaine`, `Penale`) ; chaque token distinct est mappé vers un token factice déterministe dans l'ordre de première apparition (`Date` → `Tok001`, `Chaine` → `Tok002`…) ; le nom anonymisé est la recomposition (`Tok001Tok002Tok003`). Deux noms partageant un token réel partagent ainsi le même token anonymisé — la structure de nommage exploitée par PrefixClusterer est préservée. Rationale : une anonymisation par alias entiers (`TypeNNN`) rend tous les noms préfixe-équivalents et dégénère la stratégie `prefix` ainsi que la comparaison `auto` sur la fixture.
- **Renommage pur** : le remplacement s'applique par mots entiers (word boundaries) sur tout le fichier — déclarations, noms entre guillemets, types dans les corps, relations. Rien d'autre ne change : mêmes lignes, mêmes relations, même topologie.
- **Auto-vérification obligatoire**, exit 1 sans écrire la sortie en cas d'écart :
   1. la séquence des degrés triée (in et out) du graphe est identique avant/après ;
   2. la structure de tokenisation est préservée : même nombre de tokens par nom, et le multiset des partages de tokens entre noms est identique (si N noms partageaient le token en position 1 avant, N noms partagent le token correspondant après).
- **Option `--scrub-members`** : le renommage pur ne touche pas les noms de champs, méthodes et paramètres, qui peuvent porter du vocabulaire métier réel. Avec ce drapeau, chaque corps de classe est régénéré à partir des relations sortantes (`+attrN : ?TypeNNN`, `+__construct(...)`), effaçant tout identifiant de membre réel. Les relations (donc la topologie et la vérification des degrés) restent intactes ; seul le nombre de lignes des corps change.

## 6. Pipeline de clustering

Ordre d'exécution :

1. **Retrait des hubs** (`HubDetector`) : un nœud est hub si `in-degree >= hub-threshold` (défaut : 8), OU si `out-degree >= hub-out-threshold` (défaut : 20), OU s'il est listé dans `--hub=Alias` (répétable). Les hubs sont retirés du graphe de clustering. Rationale du hub sortant : un nœud pointant vers une grande fraction du graphe (ex. une classe conteneur générée depuis un XSD avec 60+ dépendances sortantes) relie artificiellement presque toutes les classes ; sans son retrait, la détection de composantes connexes produit une composante géante unique et la modularité de Louvain s'effondre. Le détecteur mémorise pour chaque hub s'il l'est par degré entrant, sortant, ou les deux (utilisé par la politique et l'affichage).
2. **Composantes connexes** sur le graphe restant (non orienté pour la connexité). Chaque composante `<= max-size` devient directement un cluster.
3. **Découpage des grosses composantes** selon la stratégie choisie (`--strategy`, défaut `auto`) :
   - `louvain` : détection de communautés Louvain sur le sous-graphe non orienté. Implémentation naïve acceptable (150 nœuds) : optimisation de modularité par passes locales, une seule couche d'agrégation suffit. Déterminisme requis : itérer les nœuds dans l'ordre alphabétique des alias, seed fixe.
   - `prefix` : regroupement par plus long préfixe commun significatif des noms courts de classes (découpage CamelCase en tokens ; préfixe = premier token, puis deux premiers si groupes trop gros). Utile pour des POPO générés depuis XSD.
   - `auto` : calcule les deux, garde celle qui minimise les arêtes inter-clusters à contrainte de taille satisfaite ; en cas d'égalité, `prefix`.
4. **Raffinement** (`ClusterRefiner`) :
   - Cluster > `max-size` (défaut 25) : re-split (Louvain récursif, sinon bissection par tri topologique approx.).
   - Cluster < `min-size` (défaut 3) : fusion avec le cluster voisin le plus connecté ; s'il n'y a aucune arête vers un autre cluster, regroupement dans un cluster `misc`.
5. **Politique hubs** (`--hub-policy`, défaut `duplicate`) :
   - `duplicate` : chaque hub apparaît dans chaque cluster qui le référence, rendu en stéréotype `<<shared>>` avec un fond distinct, corps masqué (`hide members` ciblé) pour rester discret.
   - `separate` : les hubs vont dans un cluster dédié `shared-types` ; les clusters référencent le hub en nœud frontière.
   - `exclude` : hubs et leurs arêtes totalement absents des sorties (mentionnés dans l'index).

   **Politique par hub** : `--hub-policy-override=ALIAS:POLICY` (répétable) surcharge la politique globale pour un hub donné. **Défaut différencié** : sans override explicite, un hub détecté *uniquement* par degré sortant reçoit `separate` au lieu de la politique globale — dupliquer un nœud à 60 arêtes sortantes dans chaque cluster détruirait la lisibilité que l'outil cherche à restaurer. Un hub entrant (ou mixte) suit la politique globale.

## 7. Génération de sortie

Pour un run sur `PPN.puml` avec `--output docs/uml` :

```
docs/uml/
├── overview.puml / overview.svg      # carte
├── cluster-<slug>.puml / .svg        # un par cluster (slug = nom dérivé, cf. ci-dessous)
└── index.html
```

- **Nommage des clusters** : si stratégie `prefix`, le préfixe commun (ex. `cluster-invoice`). Sinon, nom de la classe de plus fort degré sortant du cluster (ex. `cluster-order`). Collisions : suffixe numérique.
- **Fichier cluster** : headers d'origine + déclarations complètes des classes du cluster + hubs selon politique + **nœuds frontière** : toute classe d'un autre cluster référencée par une arête est déclarée sans corps avec stéréotype `<<external: nom-du-cluster>>` et couleur atténuée ; l'arête est conservée. Ne jamais inclure les arêtes entre deux nœuds externes.
- **Overview** : un `package "<slug>" as <slug>` par cluster (contenant optionnellement la liste des noms de classes en commentaire), une arête `A --> B : n` par paire de clusters, `n` = nombre d'arêtes agrégées, épaisseur proportionnelle (`thickness=1..4`).
- **index.html** : autonome (CSS inline), liste overview + clusters avec taille et composition, `<embed>` des SVG si générés.
- **SvgRenderer** : si `--render`, invoque `plantuml -charset utf-8 -tsvg` via `symfony/process` sur chaque `.puml` ; erreur claire si le binaire est absent (`--plantuml-bin` pour le chemin).

## 8. Interface CLI

Commande unique :

```
puml-splitter split <input.puml> [options]
  --output=DIR            (défaut: ./puml-split)
  --max-size=N            (défaut: 25)
  --min-size=N            (défaut: 3)
  --strategy=auto|louvain|prefix   (défaut: auto)
  --hub-threshold=N       (défaut: 8, degré entrant)
  --hub-out-threshold=N   (défaut: 20, degré sortant)
  --hub=ALIAS             (répétable, force le statut hub)
  --hub-policy=duplicate|separate|exclude   (défaut: duplicate)
  --hub-policy-override=ALIAS:POLICY        (répétable, surcharge par hub)
  --render                (génère aussi les SVG)
  --plantuml-bin=PATH     (défaut: plantuml)
  --header=STRING         (répétable, headers additionnels injectés dans chaque sortie)
  --stdin                 (lit le puml sur stdin, permet le pipe direct depuis php-class-diagram)
  --dry-run               (affiche le plan de découpage sans écrire : clusters, tailles, hubs, arêtes coupées)
```

Le support `--stdin` est important : usage cible `php-class-diagram src/ | puml-splitter split --stdin --render --output docs/uml`.

Sortie console : résumé tabulaire (nom du cluster, nb classes, nb arêtes internes/externes), liste des hubs détectés avec leurs in/out-degrees, le motif de détection (in, out, forcé) et la politique appliquée, nombre total d'arêtes inter-clusters. Code retour 0 même avec warnings de parsing ; != 0 uniquement sur erreur fatale (fichier illisible, puml sans aucune classe).

## 9. Tests exigés

- **Unitaires** : Parser (chaque forme de ligne, lignes inconnues, corps multi-lignes), ConnectedComponents, HubDetector (seuil entrant, seuil sortant, hub forcé, hub mixte, application des overrides et du défaut différencié `separate` pour les hubs sortants purs), LouvainClusterer (graphe jouet à communautés évidentes → vérifier le partitionnement attendu et le déterminisme sur 2 runs), PrefixClusterer (jeux de noms CamelCase), ClusterRefiner (cas split et merge), générateurs (snapshots des `.puml` produits).
- **Intégration** : fixture ~150 classes → run complet → assertions : tous les nœuds présents exactement une fois hors hubs dupliqués, aucune arête perdue (somme arêtes internes + inter-clusters + arêtes hubs = total entrée), toutes les tailles dans les bornes, fichiers `.puml` re-parsables par le propre Parser de l'outil (round-trip).
- **Script d'anonymisation** : test d'intégration sur une fixture connue — séquence des degrés triée identique à l'entrée, structure de tokenisation préservée (nombres de tokens et partages), et deux cas volontairement altérés doivent faire échouer l'auto-vérification (exit 1) : un pour la topologie, un pour la tokenisation.
- **Property-based léger** (optionnel) : graphes aléatoires → invariants de partition (couverture, disjonction hors hubs).

## 10. Jalons de livraison

1. **M1 — Squelette** : projet Composer, commande `split` avec `--dry-run`, Parser + modèle, tests Parser. Livrable : parse un puml réel et affiche stats (nb classes, nb relations, top degrés).
2. **M2 — Clustering** : composantes connexes, hubs (seuils entrant et sortant, overrides, défaut différencié), refiner, `--strategy=prefix`, script `scripts/anonymize-puml.php`. Livrable : `--dry-run` affiche un plan de découpage complet, incluant la table des hubs avec motif de détection et politique.
3. **M3 — Sorties** : génération des `.puml` clusters + overview + index.html, `--render`. Livrable : pipeline bout-en-bout utilisable.
4. **M4 — Louvain + auto** : LouvainClusterer, stratégie `auto`, tests de déterminisme.
5. **M5 — Distribution** : PHAR via box, Dockerfile, README avec exemples (dont le pipe depuis php-class-diagram), CI GitHub Actions (phpstan + phpunit + build phar).

## 11. Points de vigilance pour l'agent

- Le Writer doit réémettre les corps de classes **byte-identiques** à l'entrée (pas de reformatage) : la fidélité prime.
- Déterminisme total : mêmes entrées + mêmes options → mêmes fichiers. Trier systématiquement (alias alphabétique) toute itération dont l'ordre influence la sortie.
- Les alias PlantUML sont les identifiants canoniques ; les noms courts entre guillemets ne servent qu'à l'affichage et au PrefixClusterer.
- Ne pas sur-ingénierer le graphe : tableaux d'adjacence + SplObjectStorage ou simples arrays indexés par alias suffisent à cette échelle.
- Prévoir que l'entrée puisse contenir des blocs `package` (les aplatir : le package d'origine devient une métadonnée du nœud, ignorée en v1).
- Ne jamais dupliquer un hub sortant pur dans les clusters (cf. défaut différencié §6.5) : un tel nœud réintroduirait des dizaines d'arêtes dans chaque sous-diagramme et annulerait le bénéfice du découpage.
- Toute fixture dérivée de données réelles passe par `scripts/anonymize-puml.php` ; ne jamais anonymiser une fixture par édition directe (humaine ou agent).