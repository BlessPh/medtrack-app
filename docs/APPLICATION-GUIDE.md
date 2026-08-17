# Medtrack — Guide fonctionnel et technique de l’application

## 1. Objet du document

Ce document explique l’application Medtrack telle qu’elle existe actuellement. Il s’adresse aux développeurs, testeurs, responsables produit et membres des équipes universitaires ou hospitalières qui doivent comprendre le système avant de l’utiliser ou de le faire évoluer.

Il complète la documentation OpenAPI : Swagger décrit précisément les requêtes HTTP, tandis que ce guide explique pourquoi les modules existent, comment les données circulent et quelles règles métier doivent rester vraies.

## 2. Présentation de Medtrack

Medtrack est une plateforme de gestion des stages académiques en milieu hospitalier. Elle relie les universités, les établissements de santé, les étudiants et les équipes administratives dans un parcours unique.

L’application couvre :

- l’administration des comptes et institutions ;
- le référentiel académique ;
- l’import Excel des étudiants ;
- l’activation des comptes étudiants par matricule ;
- les campagnes de stage ;
- les candidatures et admissions ;
- les stages, rotations et prolongations ;
- les plannings et présences ;
- les évaluations et décisions académiques ;
- les obligations financières, paiements et remboursements ;
- les documents privés ;
- les notifications ;
- les tableaux de bord et exports CSV.

Medtrack est une API Laravel destinée à une application front-end séparée. Laravel reste l’unique autorité sur les accès, les rôles, les transitions et les calculs.

## 3. Principes d’architecture

Medtrack est un monolithe modulaire : une application Laravel, une base relationnelle et un déploiement. Les modules organisent le code par responsabilité sans se comporter comme des microservices.

```text
Front-end SPA
    ↓ HTTPS et jeton JWT Bearer
API Laravel /api/v1
    ↓
Modules métier partageant Eloquent et les transactions
    ↓
Base relationnelle + stockage privé
```

Conséquences pratiques :

- aucun appel HTTP entre modules ;
- aucune API Gateway ;
- aucun outbox ou bus d’événements ;
- aucune duplication de données entre services ;
- transactions directes lorsque plusieurs tables doivent changer ensemble ;
- Redis, queue et scheduler non obligatoires ;
- stockage de documents sur un disque Laravel privé ;
- e-mails synchrones avec échec isolé de l’opération métier.

Les migrations Laravel sont la source de vérité du schéma. Le projet vise SQLite, PostgreSQL, MySQL/MariaDB et SQL Server en évitant les types propres à un moteur.

## 4. Organisation du code

Les modules principaux se trouvent dans `app/Modules` :

| Module | Responsabilité |
|---|---|
| Auth | JWT, comptes, profil, mot de passe et inscription étudiante |
| Institution | Universités, hôpitaux, unités, contacts, membres et rôles |
| Academic | Programmes, années, promotions, étudiants, inscriptions, campagnes et import Excel |
| Admission | Candidatures, capacités, réservations et admissions |
| Internship | Parcours types, stages, rotations et prolongations |
| Scheduling | Plannings, appareils biométriques, présences et corrections |
| Assessment | Grilles, évaluations, contestations et décisions académiques |
| Finance | Obligations, paiements, allocations, reçus et remboursements |
| Media | Documents privés rattachés aux dossiers métier |
| Notification | Notifications internes et e-mails |
| Reporting | Tableaux de bord, recherches et exports CSV |

`app/Shared` ne contient que les composants utilisés par plusieurs modules : enums, middleware, accès institutionnel et support des UUID publics.

## 5. Utilisateurs et autorisations

### 5.1 Rôle global

Le rôle global distingue :

- `SUPER_ADMIN` : administration globale de la plateforme ;
- `USER` : utilisateur ordinaire dont les droits viennent de ses institutions.

### 5.2 Rôle institutionnel

Un même utilisateur peut appartenir à plusieurs institutions avec un rôle différent :

- `INSTITUTION_ADMIN` ;
- `ACADEMIC_MANAGER` ;
- `HOSPITAL_MANAGER` ;
- `SUPERVISOR` ;
- `FINANCE_OFFICER` ;
- `STUDENT` ;
- `MEMBER`.

Le front-end utilise ces rôles pour présenter l’interface. Le serveur vérifie systématiquement le rôle, l’institution et la ressource. Connaître un UUID ne donne jamais accès à une ressource d’une autre institution.

### 5.3 États des comptes

Les comptes peuvent être `PENDING`, `ACTIVE`, `SUSPENDED` ou `DISABLED`. Un JWT encore valide ne permet pas à un compte suspendu de continuer à utiliser les routes privées : le middleware `account.active` le refuse côté serveur.

## 6. Authentification JWT

Medtrack utilise `php-open-source-saver/jwt-auth` avec le guard `api`.

Parcours de connexion :

1. le front-end envoie `POST /api/v1/auth/login` avec l’e-mail et le mot de passe ;
2. l’API retourne `access_token`, `token_type` et `expires_in` ;
3. les appels suivants envoient `Authorization: Bearer <access_token>` ;
4. `GET /api/v1/auth/me` retourne l’utilisateur courant ;
5. `POST /api/v1/auth/refresh` renouvelle le jeton ;
6. `POST /api/v1/auth/logout` invalide le jeton.

Les routes JWT sont stateless et ne nécessitent ni cookie ni protection CSRF.

Les mots de passe doivent contenir au moins 12 caractères. La connexion, la récupération du mot de passe, la vérification d’e-mail et l’inscription par matricule sont limitées en fréquence.

## 7. Différence entre compte, dossier et inscription

Ces objets ne doivent pas être confondus :

- le **compte utilisateur** permet de se connecter ;
- le **dossier étudiant** représente l’étudiant dans une université et contient son matricule ;
- l’**inscription académique** rattache le dossier étudiant à une promotion ;
- la **promotion** associe un programme, un niveau et une année académique.

Un dossier étudiant peut exister sans compte. C’est volontaire : l’université importe d’abord ses étudiants, puis chacun active son propre compte en prouvant qu’il connaît le matricule associé au bon contexte académique.

## 8. Institutions et cloisonnement

Une institution représente principalement une université ou un hôpital. Elle possède un UUID public, un type, un nom, un état, des adresses, des contacts et une structure interne hiérarchique.

Les institutions commencent en `PENDING`. Seul un super administrateur peut les activer, suspendre ou désactiver. Un administrateur institutionnel peut modifier uniquement sa propre institution et ses informations rattachées.

Les unités représentent par exemple une faculté, un département ou un service hospitalier. Une unité peut posséder une unité parente. La suppression d’une unité contenant des sous-unités est refusée.

## 9. Référentiel académique

Une université définit :

1. ses programmes ;
2. les années académiques ;
3. les promotions qui associent programme, niveau et année ;
4. les étudiants ;
5. les inscriptions des étudiants dans les promotions.

Le système vérifie que programme, promotion et année appartiennent à la même université. Une référence académique provenant d’une autre université est rejetée même si son identifiant existe.

## 10. Import Excel des étudiants

L’import appartient au module Academic, car il crée des données académiques. Le fichier n’est pas enregistré comme document.

### 10.1 Préparation dans l’interface

L’utilisateur sélectionne d’abord :

- l’université ;
- la promotion ;
- l’année académique.

Ce contexte est appliqué à toutes les lignes et vérifié côté serveur.

### 10.2 Format

Formats acceptés : `XLSX` et `CSV`, avec une limite de 10 Mio, 1 000 étudiants et 30 colonnes. La première ligne contient les en-têtes.

Colonnes obligatoires :

- Matricule ;
- Nom ;
- Post-nom ;
- Prénom ;
- Sexe ;
- Date de naissance au format `AAAA-MM-JJ`.

E-mail et téléphone sont facultatifs. Les variantes usuelles d’en-têtes français ou anglais sont reconnues. Les formules Excel ne sont jamais évaluées.

### 10.3 Prévisualisation

`POST /api/v1/academic/student-imports/preview` lit le fichier et retourne un tableau JSON. Chaque ligne contient :

- le numéro de ligne ;
- les données normalisées ;
- `valid` ;
- `selected` ;
- la liste structurée des erreurs.

La prévisualisation signale les champs absents, dates ou e-mails invalides, doublons dans le fichier et matricules déjà présents. Elle ne modifie pas la base et ne conserve pas le fichier.

### 10.4 Confirmation

Le front-end permet de retirer des lignes et de corriger les valeurs, puis envoie uniquement les étudiants retenus à `POST /api/v1/academic/student-imports/confirm`.

Le serveur valide de nouveau toutes les données. Les dossiers et inscriptions sont créés dans une transaction unique : si une ligne échoue, aucun étudiant du lot n’est enregistré.

## 11. Création du compte étudiant par matricule

Le parcours public fonctionne en deux appels.

1. `POST /api/v1/auth/student-registration/check` vérifie université, promotion, année et matricule. Le dossier doit être actif, inscrit à cette promotion et sans compte associé.
2. Le serveur retourne un jeton chiffré valable 15 minutes.
3. `POST /api/v1/auth/student-registration` reçoit ce jeton, l’e-mail, le mot de passe et les informations complémentaires.
4. Le serveur verrouille le dossier, crée le compte et le profil, rattache le compte au dossier et ajoute le rôle `STUDENT` dans l’université.

Le verrou garantit qu’un même matricule ne crée pas deux comptes, même si deux requêtes arrivent simultanément. Un jeton expiré, falsifié ou déjà consommé est refusé.

## 12. Campagnes, candidatures et admissions

Une campagne appartient à une université et une année académique. Elle cible une ou plusieurs promotions, référence des hôpitaux participants et possède une période. Les transitions autorisées sont contrôlées : brouillon vers ouverte ou annulée, puis ouverte vers fermée ou annulée.

Un étudiant est éligible si son dossier est actif, la campagne est ouverte dans sa période et il possède une inscription active dans une promotion ciblée.

L’étudiant soumet au maximum une candidature par campagne. Une candidature soumise peut être retirée par son propriétaire, rejetée par le responsable académique ou acceptée avec une capacité disponible.

Les capacités sont réparties par hôpital et éventuellement par niveau. L’acceptation verrouille la capacité avec `lockForUpdate()`, incrémente le nombre réservé, crée la réservation et l’admission dans la même transaction. La capacité ne peut donc pas être dépassée.

## 13. Stages et rotations

Une admission acceptée produit au maximum un stage. Le responsable hospitalier définit la date de début, un parcours type et éventuellement un superviseur principal.

Un parcours type contient des étapes ordonnées et leur durée. Le stage contient des rotations rattachées à une unité hospitalière, une période et éventuellement un superviseur.

Les stages et rotations suivent des transitions explicites : planifié, actif, terminé ou annulé. Un stage ne peut être terminé tant qu’une rotation reste incomplète. Une prolongation conserve l’ancienne date, la nouvelle date, le motif et l’approbateur.

## 14. Planning et présences

Un planning appartient à un stage. Il commence en brouillon, contient des entrées datées et doit avoir au moins une entrée avant publication. Il peut ensuite être annulé ; les entrées encore planifiées sont alors annulées.

Les présences enregistrent un `CHECK_IN` ou `CHECK_OUT`, une heure et une source `MANUAL` ou `BIOMETRIC`. Une présence biométrique exige un appareil actif enregistré dans l’hôpital. Une présence future ou strictement dupliquée est refusée.

L’étudiant ne modifie pas silencieusement une présence : il crée une demande de correction avec une nouvelle heure et un motif. Un responsable l’approuve ou la rejette, avec auteur et date de décision. La synthèse est calculée à la demande depuis les présences valides ou corrigées.

## 15. Évaluations et décisions académiques

L’hôpital définit des modèles d’évaluation avec des critères JSON. Chaque critère possède une clé et un maximum ; le score maximal du modèle est calculé côté serveur.

Un superviseur évalue une rotation qui lui est affectée. Chaque valeur est contrôlée contre son critère et le score total est calculé. Un évaluateur ne peut créer qu’une évaluation par rotation.

Une évaluation commence en brouillon et devient finalisée après soumission. Une évaluation finalisée ne peut plus être resoumise. L’étudiant peut ouvrir une contestation, résolue par le responsable académique. La décision finale — validation, échec ou reprise — est réservée à l’université et exige que les rotations possèdent des évaluations finalisées.

## 16. Finance

Une obligation financière représente une somme due par un étudiant à une institution. Elle contient plusieurs lignes avec quantité et prix unitaire ; le montant total est calculé côté serveur.

L’étudiant déclenche une transaction de paiement. L’adaptateur MaishaPay actuel expose le contrat local, tandis que l’intégration HTTP réelle doit être finalisée avec la sandbox du fournisseur.

Le callback MaishaPay est public mais limité en fréquence et protégé par `X-MaishaPay-Signature`, calculée avec HMAC-SHA256. Une signature incorrecte est rejetée.

Après un paiement confirmé, l’allocation est transactionnelle. Le total alloué ne peut dépasser ni la transaction ni le solde de l’obligation. Un callback répété ne produit pas de deuxième allocation. Un remboursement est réservé au propriétaire de la transaction et ne peut dépasser le montant payé restant.

## 17. Documents privés

Le module Media gère une seule table `documents`. Un document est rattaché à un étudiant ou un stage, stocké sur le disque privé `local`, et reçoit un nom physique UUID indépendant du nom envoyé par le client.

Collections acceptées : `identity`, `proof` et `evaluation`. Formats acceptés : PDF, JPEG et PNG, avec limite propre à la collection. Laravel vérifie l’extension, le MIME réel et la taille.

Le téléchargement passe toujours par un contrôleur et une Policy. Aucune route `/storage` publique n’expose les fichiers. La suppression est logique ; le nom original sert uniquement à l’affichage et au téléchargement.

## 18. Notifications

Les notifications utilisent les fonctions natives Laravel : canal interne en base et canal e-mail. Après une admission, le service métier appelle explicitement le service de notification.

Une panne SMTP est journalisée sans contenu sensible et ne doit pas annuler l’admission déjà validée. Les utilisateurs peuvent lister leurs notifications, en marquer une comme lue ou marquer toutes les notifications comme lues.

Mailpit est disponible en développement via Docker Compose sur le port SMTP 1025 et l’interface 8025.

## 19. Tableaux de bord et rapports

Le module Reporting fournit une vue institutionnelle ou étudiante : étudiants, candidatures en attente, capacités disponibles, stages actifs, corrections ou évaluations à traiter, montants impayés, paiements récents et notifications non lues.

La recherche couvre les étudiants, candidatures, stages et paiements. Elle est paginée, filtrable et strictement limitée aux institutions de l’utilisateur. Les exports CSV reprennent le même cloisonnement et sont limités en fréquence.

## 20. Contrats HTTP

Toutes les routes métier sont sous `/api/v1`. Les réponses JSON utilisent généralement une clé `data`. Les collections paginées comportent les métadonnées Laravel de pagination.

Codes principaux :

| Code | Signification |
|---|---|
| 200 | lecture ou action réussie |
| 201 | ressource créée |
| 204 | action réussie sans corps |
| 401 | absence de session ou signature externe invalide |
| 403 | compte inactif, rôle ou institution non autorisé |
| 404 | ressource ou contexte inexistant |
| 409 | transition d’état impossible |
| 422 | format ou règle métier invalide |
| 429 | limite de fréquence dépassée |

Les entités exposées utilisent généralement un UUID public. Quelques référentiels internes utilisent encore un entier ; Swagger précise le type attendu pour chaque route. Les dates sont ISO 8601, les jours `AAAA-MM-JJ` et les montants sont stockés en décimal avec une devise séparée.

Chaque réponse API contient `X-Request-ID`. Le client peut fournir un identifiant conforme dans la requête pour faciliter le support.

## 21. Sécurité

Les règles essentielles sont :

- authentification et état actif sur toutes les routes privées ;
- contrôle de rôle et d’appartenance institutionnelle côté serveur ;
- protection CSRF et origines CORS explicites ;
- régénération et invalidation des sessions ;
- rate limiting sur les opérations sensibles ;
- mots de passe, cookies, jetons et secrets jamais journalisés ;
- stockage des secrets uniquement dans l’environnement ;
- fichiers privés hors du web ;
- calculs financiers côté serveur ;
- transactions et verrous pour capacités, paiements et activation du matricule ;
- réponses sans SQL ni stack trace en production.

Avant production : `APP_DEBUG=false`, HTTPS, cookie `Secure`, domaines Sanctum/CORS exacts, secrets renouvelés et test de restauration réussi.

## 22. Configuration locale

Étapes usuelles :

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Réglages simples recommandés en local :

```dotenv
DB_CONNECTION=sqlite
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

Les identifiants MaishaPay restent vides tant que la sandbox n’est pas disponible. Aucun secret réel ne doit entrer dans Git.

## 23. Tests et qualité

Les tests sont séparés en :

- `tests/Unit` pour les composants isolés ;
- `tests/Feature` pour les endpoints et parcours ;
- `tests/Integration` pour les transactions, la base et les collaborations entre modules.

Commandes principales :

```powershell
vendor\bin\pint --test
php artisan test
composer validate --strict --no-check-publish
composer audit --locked
```

La suite vérifie notamment le cloisonnement institutionnel, la surréservation, les doubles allocations, les jetons falsifiés, les fichiers privés, l’import Excel, les routes privées et la couverture OpenAPI.

## 24. Documentation API

La documentation machine est générée avec :

```powershell
php artisan api:docs
```

- Swagger UI : `/api/documentation` ;
- document JSON : `/docs` ;
- fichier : `storage/api-docs/api-docs.json`.

Swagger contient les 85 opérations, paramètres, payloads, réponses et sécurités. Le test `OpenApiDocumentationTest` échoue lorsqu’une route `/api/v1` n’est pas documentée.

## 25. Sauvegarde et exploitation

Une sauvegarde cohérente contient la base et `storage/app/private`. Elle doit être chiffrée, conservée hors du serveur applicatif et régulièrement restaurée dans un environnement isolé.

Les logs utilisent le `request_id`. Les requêtes SQL cumulées lentes déclenchent un avertissement sans inclure leurs paramètres sensibles. Les alertes externes de disponibilité et d’espace disque seront configurées avec le déploiement de staging.

## 26. Limites actuelles

Les éléments suivants ne sont pas finalisés ou sont volontairement reportés :

- appel HTTP réel et certification sandbox MaishaPay ;
- conteneurisation complète, CI/CD et staging ;
- reprise des données historiques ;
- antivirus applicatif pour les fichiers ;
- queues, retries avancés et SMS ;
- stockage S3 et URLs temporaires ;
- moteur de reporting analytique avancé ;
- application front-end, hors de ce dépôt back-end.

Ces limites ne remettent pas en cause les parcours backend déjà implémentés et testés.

## 27. Règles d’évolution

Toute nouvelle fonctionnalité doit :

1. appartenir au module métier concerné ;
2. valider ses données côté serveur ;
3. vérifier rôle et institution ;
4. utiliser une transaction si plusieurs écritures sont atomiques ;
5. exposer un contrat OpenAPI ;
6. ajouter des tests unitaires, Feature ou Integration adaptés ;
7. éviter toute nouvelle infrastructure sans problème mesuré.

Une fonctionnalité n’est terminée que lorsque le code, les règles, les tests et la documentation racontent la même chose.

## 28. Références du dépôt

- `docs/API-DOCUMENTATION.md` : génération OpenAPI ;
- `docs/STUDENT-IMPORT.md` : contrat d’import Excel ;
- `docs/SECURITY.md` : contrôle avant production ;
- `docs/BACKUP-RESTORE.md` : sauvegarde et restauration ;
- `storage/api-docs/api-docs.json` : contrat HTTP complet ;
- `database/migrations` : source de vérité du schéma relationnel.
