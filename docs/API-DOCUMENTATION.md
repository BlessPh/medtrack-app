# Documentation OpenAPI Medtrack

Générer la spécification après toute modification de route ou de contrat :

```bash
php artisan api:docs
```

La spécification est écrite dans `storage/api-docs/api-docs.json`. L'interface L5-Swagger est accessible sur `/api/documentation` et le JSON sur `/docs/api-docs.json` selon la configuration publiée du package.

L'API utilise JWT Auth. Appeler `POST /api/v1/auth/login`, puis envoyer le jeton reçu dans l'en-tête `Authorization: Bearer <token>`. Les routes API JWT ne nécessitent ni cookie ni jeton CSRF. Le callback MaishaPay utilise séparément `X-MaishaPay-Signature`.

Le test `OpenApiDocumentationTest` garantit qu'aucune route Laravel `/api/v1` n'est absente de la spécification et que chaque écriture possède un payload et des réponses documentés.
