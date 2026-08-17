# Medtrack App

Monolithe modulaire Laravel 13 de Medtrack RDC.

## Prérequis

- PHP 8.3 ou supérieur
- Composer
- SQLite pour la boucle locale rapide, ou PostgreSQL/MySQL/MariaDB/SQL Server

## Installation locale

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

L'API est versionnée sous `/api/v1`. Le endpoint Laravel `/up` fournit le contrôle de santé général.

## Organisation

```text
app/Modules/{Module}/
├── Controllers/
├── Models/
├── Requests/
├── Resources/
├── Policies/
├── Services/      # uniquement quand une règle métier le justifie
└── Routes/api.php
```

Les routes sont incluses explicitement depuis `routes/api.php`. Il n'existe pas de Provider obligatoire par module.

## Base de données

Les 42 tables métier sont réparties dans 10 migrations ordonnées par domaine. Les migrations Laravel sont la source de vérité et utilisent uniquement le Schema Builder afin de rester portables.

Le MVP utilise :

- sessions fichier ;
- cache fichier ;
- queue synchrone ;
- stockage privé Laravel pour les documents.

## Qualité

```bash
php artisan test
vendor/bin/pint --test
php artisan route:list --path=api/v1 --except-vendor
```

Les tests utilisent SQLite en mémoire. La matrice des moteurs serveur sera exécutée par l'intégration continue.

## Matrice de base de données

Le workflow `.github/workflows/database-matrix.yml` exécute la suite sur :

- SQLite ;
- PostgreSQL ;
- MySQL ;
- MariaDB ;
- Microsoft SQL Server.

Cette matrice doit passer avant fusion. Les migrations n'utilisent aucun SQL brut et évitent les cascades multiples afin de rester compatibles avec SQL Server.
