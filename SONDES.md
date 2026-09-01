# Les règles d'erreur de Paynala, et leur transcription en sondes

Relevé dans `paynala-dashboard` (backend + composants d'affichage), pas d'après
ce qu'on suppose du métier. Chaque règle porte la raison qui l'a établie, parce
que plusieurs sont contre-intuitives et qu'une sonde écrite sans elles compterait
autre chose que ce qu'on croit.

## Les règles

### 1. Trois états, jamais deux

`SUCCESS`, `FAILED`, `MISSING`. **Un log absent n'est pas un échec.** Ce n'est
pas une commodité d'affichage : `failedBeneficiaries.js` l'écrit noir sur blanc
(« never "log absent", only a log that came back unsuccessful ») et
`walletFailures.js` ne compte que `= 'FAILED'`.

Les deux appellent des gestes différents. Un `FAILED` dit qu'Airtel a répondu
non — solde insuffisant, numéro invalide. Un `MISSING` dit que la réconciliation
n'a jamais été journalisée, c'est-à-dire qu'un maillon de la chaîne s'est tu.
Les additionner dans un seul chiffre rendrait la panne d'infrastructure
invisible derrière le bruit des refus ordinaires.

D'où **deux sondes séparées**, jamais une.

### 2. L'autorité est le drapeau du log, pas le code HTTP

```sql
response->'status'->>'success' = 'true'
```

`http_code` est fréquemment `NULL` **même sur des succès authentiques**, sur les
canaux API et RECOVERY — constaté sur les données réelles. Une sonde qui
compterait `http_code >= 400` raterait donc une partie des échecs et en
inventerait d'autres.

### 3. Quatre canaux seulement se réconcilient

`WEB`, `USSD`, `API`, `RECOVERY`.

`webview` et `backend_cron` **ne portent jamais** de jambe CP/MC — ce n'est pas
un défaut, ces flux ne journalisent simplement pas la réconciliation. Les
inclure produirait un torrent de `MISSING` permanent, et une sonde qui hurle en
continu ne se lit plus.

### 4. On ne réconcilie que ce qui a réussi

`p.status = 'SUCCESS'`. Un paiement en échec n'a pas de jambe à attendre : la
compter manquante n'aurait pas de sens.

### 5. Une ligne par jambe, pas par paiement

Un même paiement peut voir MC1 **et** MC2 échouer. Compter les paiements plutôt
que les jambes sous-estimerait l'incident d'un facteur deux.

### 6. Le suffixe dépend du canal, le nombre de jambes du marchand

| | CP | deuxième | troisième |
|---|---|---|---|
| `USSD` | `airtel_money_id \|\| 'CP'` | `…'MC1'` ou `…'MC'` | `…'MC2'` |
| WEB / API / RECOVERY | `request_id \|\| '_CP'` | `…'_MC1'` ou `…'_MC'` | `…'_MC2'` |

**Rengus Digital** a trois wallets (`_MC1` / `_MC2`), tout le monde en a deux
(`_MC` sans chiffre). Reconnu par le nom du marchand, pas par son identifiant —
délibéré, pour que ça survive à un changement d'environnement.

> **Le piège de MC2.** La jointure `mc2` contient déjà
> `where m.name = 'Rengus Digital'` : pour tout autre marchand elle ne matche
> jamais, donc `MISSING`. C'est sans effet quand on compte les `FAILED` — mais
> une sonde de logs absents qui oublierait ce filtre compterait **chaque
> paiement de chaque autre marchand**. C'est l'erreur la plus facile à commettre
> ici.

### 7. Deux formes de raison d'échec, et elles n'appellent pas le même geste

| Forme | JSON | Exemple |
|---|---|---|
| Infrastructure | `response->>'error'` + `message` | `Proxy request failed — socket hang up` |
| Métier | `response->'status'->>'message'` | `Solde insuffisant` |

« Solde insuffisant » est le métier qui fonctionne. « socket hang up » est
Airtel injoignable. Les mélanger noierait la panne dans le bruit — d'où une
sonde dédiée à l'infrastructure, avec des paliers bien plus bas.

### 8. La raison d'un paiement échoué vient d'ailleurs

Pas de `response->'status'`, mais de
`response->'data'->'transaction'->>'message'`, filtré sur
`status = 'TF'`.

### 9. `request.pin` ne sort jamais de la base

Interdiction explicite dans `failedBeneficiaries.js`. Les sondes ne comptent que
des nombres, donc la question ne se pose pas — mais elle se posera au premier
qui voudra une sonde qui remonte un détail.

### 10. Ce que le projet établit pour l'affichage

- `--status-good` / `--status-critical` sont réservées à Succès / Échec et
  **jamais réutilisées pour une série de graphique**.
- `--status-warning` est la couleur de `MISSING` — ni bonne, ni critique.
- La raison n'est affichée que sous une jambe non réussie (`WalletCell`), en
  petit et en gris : elle explique, elle n'alerte pas.
- `MISSING` sans log affiche « Log Airtel absent » plutôt que rien : un vide
  se lit comme un bug de l'écran, pas comme un fait.

## Deux contraintes d'Arche à connaître avant d'écrire une sonde

**`?` est interdit.** La connexion de supervision utilise
`PDO::ATTR_EMULATE_PREPARES` (le pooler Supabase ne supporte pas les requêtes
préparées nommées). PDO analyse donc le SQL lui-même et prendrait l'opérateur
jsonb `?` pour un paramètre positionnel. On écrit `jsonb_exists(response,
'error')`, jamais `response ? 'error'`.

**`:depuis` est obligatoire.** La sonde reçoit exactement un paramètre. Une
requête qui ne l'utilise pas échoue sur « Invalid parameter number ».

**8 secondes.** `DatabaseConnector::TIMEOUT_MS`. La requête wallet du dashboard
peut prendre 30 s — mais sur des mois d'historique ; sur une fenêtre de 24 h le
volume est sans commune mesure. À vérifier au bouton **Essayer** avant
d'enregistrer : c'est exactement ce pour quoi il existe.

## Les sondes

Le bloc de jointures est le même partout. Il est en `LATERAL` et non en
`LEFT JOIN` pour une raison mesurée dans le projet d'origine : avec un
`LEFT JOIN`, Postgres bascule sur un hash join dès que son estimation de lignes
grimpe, et parcourt alors les ~890 000 lignes d'`airtel_logs` en entier. Un
`LATERAL` ne lui laisse pas le choix : boucle imbriquée sur l'index de
`request_id`.

```sql
-- ⟨JOINTURES⟩ — à recopier tel quel dans les sondes qui le réclament
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where al.request_id = case
        when p.channel = 'USSD' then p.airtel_money_id || 'CP'
        else p.request_id || '_CP' end
    order by al.created_at desc limit 1
) cp on true
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where al.request_id = case
        when m.name = 'Rengus Digital' then
            case when p.channel = 'USSD' then p.airtel_money_id || 'MC1'
                 else p.request_id || '_MC1' end
        else
            case when p.channel = 'USSD' then p.airtel_money_id || 'MC'
                 else p.request_id || '_MC' end end
    order by al.created_at desc limit 1
) mc1 on true
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where m.name = 'Rengus Digital'
      and al.request_id = case
        when p.channel = 'USSD' then p.airtel_money_id || 'MC2'
        else p.request_id || '_MC2' end
    order by al.created_at desc limit 1
) mc2 on true
```

---

### Sonde 1 — Jambes de réconciliation en échec

*Unité : `jambes` · fenêtres : 24 h `3, 10, 20, 40, 60, 100` — 48 h `10, 40, 100`*

Le chiffre de tête. Un `FAILED` par jambe, règle 5.

```sql
select
    count(*) filter (
        where cp.request_id is not null
          and cp.response->'status'->>'success' is distinct from 'true')
  + count(*) filter (
        where mc1.request_id is not null
          and mc1.response->'status'->>'success' is distinct from 'true')
  + count(*) filter (
        where mc2.request_id is not null
          and mc2.response->'status'->>'success' is distinct from 'true')
    as valeur
from payment p
join merchant m on m.id = p.merchant_id
⟨JOINTURES⟩
where p.created_at >= :depuis
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and p.status = 'SUCCESS'
```

### Sonde 2 — Logs Airtel absents

*Unité : `jambes sans log` · fenêtres : 24 h `5, 20, 50, 100` — 48 h `20, 100`*

Règle 1 : ce n'est pas un échec, c'est un silence. Noter le
`m.name = 'Rengus Digital'` sur la troisième jambe — sans lui, cette sonde
compterait chaque paiement de chaque autre marchand (règle 6).

```sql
select
    count(*) filter (where cp.request_id is null)
  + count(*) filter (where mc1.request_id is null)
  + count(*) filter (
        where mc2.request_id is null and m.name = 'Rengus Digital')
    as valeur
from payment p
join merchant m on m.id = p.merchant_id
⟨JOINTURES⟩
where p.created_at >= :depuis
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and p.status = 'SUCCESS'
```

### Sonde 3 — Échecs d'infrastructure Airtel

*Unité : `refus techniques` · fenêtres : 24 h `1, 3, 10, 30` — 48 h `5, 30`*

Règle 7. Paliers volontairement bas : « socket hang up » n'est pas du métier
qui refuse, c'est un partenaire injoignable. Trois en une journée méritent déjà
un appel.

```sql
select
    count(*) filter (
        where jsonb_exists(cp.response, 'error'))
  + count(*) filter (
        where jsonb_exists(mc1.response, 'error'))
  + count(*) filter (
        where jsonb_exists(mc2.response, 'error'))
    as valeur
from payment p
join merchant m on m.id = p.merchant_id
⟨JOINTURES⟩
where p.created_at >= :depuis
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and p.status = 'SUCCESS'
```

### Sonde 4 — Paiements en échec

*Unité : `paiements` · fenêtres : 24 h `10, 50, 100, 250, 500` — 48 h `50, 250`*

Niveau paiement, pas réconciliation. N'a besoin d'aucune jointure : c'est la
sonde la moins chère, et celle qui répondra encore quand `airtel_logs` sera
trop lent pour les autres.

```sql
select count(*) as valeur
from payment p
where p.created_at >= :depuis
  and p.status = 'FAILED'
```

### Sonde 5 — Silence de la production

*Unité : `minutes sans paiement` · fenêtres : 24 h `30, 60, 180, 360`*

Les quatre sondes ci-dessus comptent des choses qui vont mal. Aucune ne se
déclenche quand **plus rien n'arrive** — et c'est pourtant la panne la plus
grave : un compteur d'échecs à zéro se lit comme un système en bonne santé.

Celle-ci s'inverse : elle compte les minutes écoulées depuis le dernier
paiement. Elle monte toute seule quand la production s'arrête.

```sql
select coalesce(
    extract(epoch from (now() - max(p.created_at))) / 60,
    extract(epoch from (now() - :depuis::timestamp)) / 60
)::int as valeur
from payment p
where p.created_at >= :depuis
```

Le `coalesce` compte : sans lui, une fenêtre entièrement vide rend `NULL`, que
`readValue` interprète comme zéro — la sonde annoncerait « tout va bien » au
moment précis où plus rien n'arrive depuis vingt-quatre heures.

## Ce qui n'est pas transcrit, et pourquoi

**Les rapports partenaires** (`partners.js`) ne décrivent pas des erreurs mais
des livrables hebdomadaires — avec leur liste de dates de test exclues en dur.
Rien à surveiller là.

**Le détail des bénéficiaires** (`failedBeneficiaries.js`) répond à « qui n'a pas
été payé », pas à « est-ce que ça va mal ». Une sonde rend un nombre ; cette
question demande une liste. Elle reste du ressort du dashboard.

**Le filtre par marchand** est absent de toutes les sondes ci-dessus,
volontairement : une alerte est censée dire que quelque chose ne va pas, pas
demander pour qui. Si un marchand doit être suivi à part, c'est une sonde de
plus avec son `and m.name = '…'`, pas un paramètre.
