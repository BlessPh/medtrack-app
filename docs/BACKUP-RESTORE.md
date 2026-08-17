# Sauvegarde et restauration

La sauvegarde doit toujours contenir la base relationnelle et `storage/app/private` au même instant logique.

## Procédure minimale

1. Mettre l'application en maintenance ou utiliser un snapshot cohérent fourni par la base.
2. Exporter la base avec l'outil natif du moteur retenu.
3. Archiver séparément `storage/app/private`.
4. Chiffrer les sauvegardes et les stocker hors du serveur applicatif.
5. Conserver au minimum une sauvegarde quotidienne et une copie hors site selon la politique validée.

## Test de restauration

1. Restaurer dans un environnement isolé avec des secrets distincts.
2. Restaurer la base puis les documents privés.
3. Exécuter `php artisan migrate --force` et `php artisan about`.
4. Vérifier `/up`, la connexion, un téléchargement autorisé et un reçu financier.
5. Comparer le nombre d'utilisateurs, institutions, étudiants, transactions et documents avec le rapport de sauvegarde.
6. Consigner la date, la durée, les écarts et le responsable du test.

La restauration doit être répétée avant la production puis périodiquement. La phase 17 automatisera son exécution dans l'environnement de staging.
