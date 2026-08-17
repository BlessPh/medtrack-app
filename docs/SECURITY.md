# Sécurité avant production

## Contrôles applicatifs

- Toutes les routes métier privées utilisent `auth:api` (JWT Bearer) et `account.active`.
- Les seules routes publiques sont les health checks, l'authentification, la récupération du mot de passe et le callback MaishaPay signé.
- Les ressources institutionnelles sont toujours filtrées selon les adhésions de l'utilisateur côté serveur.
- Les documents utilisent le disque `local` privé avec `serve=false`; aucun lien public n'est généré.
- Les mots de passe, clés API, secrets HMAC et données de paiement ne doivent jamais être journalisés.
- Les secrets doivent provenir de l'environnement cible et être renouvelés avant la production.

## Vérifications avant chaque mise en production

1. `composer validate --strict`
2. `composer audit --locked`
3. `php artisan test`
4. `vendor/bin/pint --test`
5. Vérifier `APP_DEBUG=false`, HTTPS, la durée des JWT et le renouvellement des secrets.
6. Vérifier les permissions de `storage/` et l'absence d'accès HTTP à `storage/app/private`.
7. Tester un accès horizontal avec deux institutions distinctes.

## Conservation minimale proposée

- Logs applicatifs : 90 jours, sans données sensibles.
- Documents métier : selon la durée réglementaire validée par le responsable juridique.
- Notifications internes : 12 mois après lecture.
- Données financières et décisions académiques : aucune suppression automatique avant validation réglementaire.

Ces durées sont des propositions techniques et doivent être validées par le responsable métier/juridique avant production.
