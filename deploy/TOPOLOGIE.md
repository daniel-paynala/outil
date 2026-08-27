# Comment une requête atteint Arche

Relevé le 27/08/2026 depuis l'extérieur, en interrogeant le serveur — pas
d'après ce que le dépôt suppose.

```
navigateur / app mobile
        │  https://arche.paynala.com
        ▼
   Cloudflare                      (termine le TLS, IP 172.67.154.52 / 104.21.4.152)
        │
        ▼
   nginx de l'hôte  :443           (nginx 1.26 Ubuntu, certificat valide pour ce nom)
        │  :80 → redirection 301 vers https
        ▼
   nginx du conteneur  :8090       (compose, nginx 1.27 alpine)
        │
        ├── /api/*  ─────────────▶ Laravel (php-fpm)
        └── reste   ─────────────▶ Next.js
```

## Ce qui s'en déduit, et qui n'est pas évident

**Il y a deux proxies, pas un.** D'où `trustProxies(at: '*')` dans
`bootstrap/app.php` : sans lui, Laravel ne voit que la connexion HTTP interne,
`isSecure()` est faux, et les URL absolues qu'il génère sortent en `http://`.
Le lien fonctionne quand même, ce qui rend le défaut invisible.

**Le port du conteneur est lié à la boucle locale** (`127.0.0.1:8090:80` dans
`docker-compose.prod.yml`). Sans cela, `http://<ip>:8090` reste joignable en
clair à côté du HTTPS — et c'est le chemin en clair que trouvent les outils
d'analyse, pas celui qu'on a pris soin de chiffrer.

**Le mode TLS de Cloudflare doit être « Full (strict) ».** L'origine présente un
certificat valide pour `arche.paynala.com` (vérifié : `ssl_verify_result=0`), le
mode strict est donc possible. En mode « Flexible », Cloudflare parlerait à
l'origine en HTTP simple : le cadenas s'afficherait dans le navigateur alors que
la moitié du trajet serait en clair.

**Le vhost de l'hôte n'est pas dans ce dépôt.** Il a été posé à la main, comme
`nginx-docker.conf`, `api/Dockerfile` et `web/Dockerfile`. C'est le même écart
que celui qui a fait croire à l'existence d'un worker de file inexistant : le
dépôt décrit une installation, le serveur en fait tourner une autre.

## Limites héritées de Cloudflare

| Limite | Valeur (offre gratuite) | Ce qui la touche |
| --- | --- | --- |
| Corps de requête | 100 Mo | Pièces jointes — la nôtre plafonne à 25 Mo |
| Délai de réponse de l'origine | 100 s → erreur 524 | Synchronisation GitHub, réindexation |

Mesuré : aucun refus jusqu'à 30 Mo de corps. La chaîne accepte donc largement
ce que l'app envoie.

## Vérifier la chaîne

```bash
curl -s https://arche.paynala.com/api/health
# {"status":"ok","service":"Arche","time":"…"}

# Le port en clair ne doit PAS répondre depuis l'extérieur :
curl -s -o /dev/null -w '%{http_code}\n' --max-time 5 http://<ip>:8090/api/health
```
