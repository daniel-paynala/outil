# Les erreurs de Paynala, une sonde par type

Relevé dans `paynala-dashboard` (backend et composants d'affichage).

**Une sonde par type d'erreur, jamais par thème.** Un « échec de
réconciliation » n'est pas un type : c'est une famille qui mélange un partenaire
injoignable, un client sans solde et un identifiant refusé. Ces trois-là
n'appellent ni le même geste, ni la même personne, ni le même délai. Les compter
ensemble donne un chiffre sur lequel on ne peut rien décider.

Les seize requêtes sont dans [`sondes/`](sondes/), une par fichier, **complètes
et collables telles quelles** dans le champ « Requête » d'une sonde Arche.

---

## Comment c'est découpé

Deux axes qui répondent à deux questions différentes. Le même échec est compté
une fois sur chacun — c'est voulu, et c'est ce qui permet de croiser.

### Axe « où » — la jambe qui casse

| # | Fichier | Ce qu'elle compte |
|---|---|---|
| 01 | `01-cp-echec.sql` | CP a répondu non |
| 02 | `02-mc1-echec.sql` | MC1 (ou MC) a répondu non |
| 03 | `03-mc2-echec.sql` | MC2 a répondu non — Rengus Digital seulement |
| 04 | `04-cp-sans-log.sql` | CP n'a laissé aucun log |
| 05 | `05-mc1-sans-log.sql` | MC1 (ou MC) n'a laissé aucun log |
| 06 | `06-mc2-sans-log.sql` | MC2 n'a laissé aucun log — Rengus seulement |

### Axe « quoi » — le type d'erreur

| # | Fichier | Ce qu'elle compte |
|---|---|---|
| 07 | `07-timeout.sql` | Time-out, quelle que soit la forme du log |
| 08 | `08-invalid-transaction-id.sql` | Identifiant de transaction refusé |
| 09 | `09-solde-insuffisant.sql` | Solde insuffisant |
| 10 | `10-coupure-reseau.sql` | Connexion coupée : socket, DNS, refus |
| 11 | `11-infrastructure-non-classee.sql` | Panne technique d'une forme inconnue |
| 12 | `12-echec-non-detaille.sql` | Airtel refuse sans dire pourquoi |
| 13 | `13-refus-metier-non-classe.sql` | Refus métier d'un libellé inconnu |

### Axe « paiement » — hors réconciliation

| # | Fichier | Ce qu'elle compte |
|---|---|---|
| 14 | `14-paiements-en-echec.sql` | Paiements en statut `FAILED` |
| 15 | `15-silence-production.sql` | Minutes depuis le dernier paiement |

Et `00-decouverte-des-libelles.sql`, qui n'est **pas** une sonde — voir plus bas.

---

## La partition est stricte, et c'est vérifié

Les sept sondes de type forment une partition : **tout échec tombe dans
exactement une**. Ni deux, ni zéro.

Ce n'est pas une intention, c'est une propriété testée. La logique des sept
clauses a été rejouée sur quinze libellés couvrant chaque forme documentée plus
des inconnus ; chacun atterrit dans une seule sonde.

Cela se contrôle aussi en production, et c'est le contrôle qui compte :

```
01 + 02 + 03  =  07 + 08 + 09 + 10 + 11 + 12 + 13
```

Les deux côtés comptent les mêmes jambes en échec, découpées autrement. S'ils
divergent, une sonde a été modifiée sans que la partition suive.

### Les deux filets, et ce qu'ils veulent dire

Les sondes **11** et **13** attrapent ce qu'aucune autre ne reconnaît. Elles ne
sont pas des fourre-tout : ce sont les deux seules qui peuvent te prévenir d'un
type d'erreur que personne n'avait anticipé.

**Si 13 monte pendant que 08 reste à zéro, c'est mon motif qui est faux, pas
Airtel qui va bien.** Lance alors `00-decouverte-des-libelles.sql` : le libellé
réel sera en tête. C'est la seule façon dont ce découpage se corrige tout seul.

---

## D'abord : la reconnaissance

`sondes/00-decouverte-des-libelles.sql` **ne se colle pas dans Arche** — elle
rend des lignes, pas un nombre. Elle se lance dans DBeaver ou dans l'éditeur SQL
de Supabase, sur trente jours.

Elle liste chaque libellé d'erreur distinct, son nombre d'occurrences et le
nombre de jambes touchées.

Lance-la avant tout le reste. Trois des sondes de type — 07, 08, 09 — reposent
sur des motifs de texte que **le dépôt de référence ne contient pas** :
`filters.js` ne documente que deux exemples, `"Proxy request failed / socket
hang up"` et `"Solde insuffisant…"`. Le reste, je l'ai construit sur les formes
d'erreur usuelles de Node et sur les libellés que tu as cités.

Un motif faux ne casse rien de visible : la sonde s'installe et ne signale
jamais rien. C'est le pire défaut possible — c'est précisément à elle qu'on fait
confiance. Les filets 11 et 13 sont là pour ça, mais la reconnaissance est plus
rapide.

Les motifs actuels, à confirmer :

| Sonde | Motifs |
|---|---|
| 07 | `timeout`, `time out`, `timed out`, `ETIMEDOUT`, `ESOCKETTIMEDOUT` |
| 08 | `invalid…transaction`, `transaction…invalide` |
| 09 | `solde…insuffisant`, `insufficient`, `balance` |
| 10 | `socket hang up`, `ECONNRESET`, `ECONNREFUSED`, `ENETUNREACH`, `EAI_AGAIN`, `EHOSTUNREACH` |

---

## Les règles reprises du projet

### 1. Trois états, jamais deux

`SUCCESS`, `FAILED`, `MISSING`. **Un log absent n'est pas un échec.**
`failedBeneficiaries.js` l'écrit noir sur blanc (« never "log absent", only a log
that came back unsuccessful ») et `walletFailures.js` ne compte que `= 'FAILED'`.

Un `FAILED` dit qu'Airtel a répondu non. Un `MISSING` dit que la réconciliation
n'a jamais été journalisée : un maillon s'est tu. D'où deux jeux de sondes
séparés — 01-03 contre 04-06.

### 2. L'autorité est le drapeau du log, pas le code HTTP

`response->'status'->>'success' = 'true'`. `http_code` est fréquemment `NULL`
**même sur des succès authentiques**, sur les canaux API et RECOVERY. Une sonde
qui compterait `http_code >= 400` raterait des échecs et en inventerait
d'autres.

### 3. Quatre canaux seulement se réconcilient

`WEB`, `USSD`, `API`, `RECOVERY`. `webview` et `backend_cron` ne portent jamais
de jambe CP/MC — ce n'est pas un défaut. Les inclure produirait un torrent de
`MISSING` permanent, et une sonde qui hurle en continu ne se lit plus.

### 4. On ne réconcilie que ce qui a réussi

`p.status = 'SUCCESS'`. Un paiement en échec n'a pas de jambe à attendre.

### 5. Une ligne par jambe, pas par paiement

Un même paiement peut voir MC1 **et** MC2 échouer. Le socle déplie donc les
trois jambes en trois lignes, et toutes les sondes comptent des lignes.

### 6. Le suffixe dépend du canal, le nombre de jambes du marchand

| | CP | deuxième | troisième |
|---|---|---|---|
| `USSD` | `airtel_money_id \|\| 'CP'` | `…'MC1'` ou `…'MC'` | `…'MC2'` |
| WEB / API / RECOVERY | `request_id \|\| '_CP'` | `…'_MC1'` ou `…'_MC'` | `…'_MC2'` |

**Rengus Digital** a trois wallets, tout le monde en a deux (`_MC` sans
chiffre). Reconnu par le nom, pas par l'identifiant — pour survivre à un
changement d'environnement.

> **Le piège de MC2.** La jointure `mc2` porte déjà
> `where m.name = 'Rengus Digital'` : pour tout autre marchand elle ne matche
> jamais, donc `MISSING`. Le socle neutralise ce piège une fois pour toutes
> avec sa colonne `concernee` — sans elle, la sonde 06 compterait **chaque
> paiement de tous les autres marchands**, et son chiffre serait simplement
> gros sans que rien ne semble anormal.

### 7. Deux formes de log, et la colonne qui les distingue

| Forme | JSON | Colonne `infrastructure` |
|---|---|---|
| Infrastructure | `{"error": …, "message": …}` | `true` |
| Métier | `{"status": {"success": false, "message": …}}` | `false` |

C'est la ligne de partage principale de l'axe « quoi » : 10 et 11 sont
techniques, 08, 09, 12 et 13 sont métier. Seule la 07 traverse les deux — un
time-out peut se présenter sous les deux formes.

### 8. `request.pin` ne sort jamais de la base

Interdiction explicite dans `failedBeneficiaries.js`. Les sondes ne rendent que
des nombres, donc la question ne se pose pas — elle se posera au premier qui
voudra une sonde qui remonte un détail.

### 9. Ce que le projet établit pour l'affichage

- `--status-good` / `--status-critical` sont réservées à Succès / Échec et
  jamais réutilisées pour une série de graphique.
- `--status-warning` est la couleur de `MISSING` — ni bonne, ni critique.
- La raison n'apparaît que sous une jambe non réussie, en petit et en gris :
  elle explique, elle n'alerte pas.
- Un `MISSING` affiche « Log Airtel absent » plutôt que rien : un vide se lit
  comme un bug de l'écran, pas comme un fait.

---

## Fidélité au projet d'origine

Le bloc de jointures et le `case` du statut sont recopiés **au caractère près**
depuis `filters.js`, vérifié par comparaison automatique. Le jour où quelqu'un
se demandera si une sonde compte la même chose que le tableau de bord, il
mettra les deux textes côte à côte.

Trois écarts, tous assumés :

**`?` devient `jsonb_exists`.** `WALLET_REASON_EXPR` écrit `response ? 'error'`.
La connexion de supervision utilise `PDO::ATTR_EMULATE_PREPARES` — le pooler
Supabase ne supporte pas les requêtes préparées nommées — donc PDO analyse le
SQL lui-même et prend le `?` pour un paramètre positionnel. Mesuré : la requête
est refusée avant d'atteindre Postgres.

**Les trois jambes sont dépliées en lignes.** Le dashboard les garde en colonnes
parce qu'il les affiche côte à côte ; une sonde compte, et compter des lignes
est ce qui rend la règle 5 automatique plutôt que recopiée trois fois.

**`request` n'est pas lu.** Un blob jsonb dont un dénombrement n'a que faire.

---

## Avant d'enregistrer une sonde

**`:depuis` est obligatoire.** La sonde reçoit exactement un paramètre ; une
requête qui ne l'utilise pas échoue sur « Invalid parameter number ». Elle peut
s'en servir plusieurs fois — permis en mode émulé, et la sonde 15 le fait.

**Le cast `::` est à proscrire, pour la même raison que `?`.**
`::timestamptz` est indistinguable d'un paramètre nommé `:timestamptz`. On écrit
`cast(x as timestamptz)`. Un premier jet de la sonde 15 en contenait deux ; le
contrôle les a vus.

**Une colonne nommée `valeur`, entière.** `NULL` est lu comme zéro, une absence
de ligne aussi.

**8 secondes.** `DatabaseConnector::TIMEOUT_MS`. Les treize sondes 01-13
partagent le même socle coûteux et tournent toutes au même rythme : c'est
treize fois la charge d'une seule. Si l'une passe de justesse au bouton
**Essayer**, les treize ensemble ne passeront pas. Les 14 et 15, sans jointure,
resteront debout quoi qu'il arrive — c'est leur raison d'être autant que leur
contenu.

**Le fuseau.** Arche calcule `:depuis` chez lui et l'envoie en texte. Si
`payment.created_at` est un `timestamp` sans fuseau dans un autre fuseau que
celui d'Arche, le décalage est silencieux. Le test : lancer **Essayer** sur 24 h
et comparer au même chiffre sur le dashboard Paynala pour la même période.

---

## Unités et paliers

L'unité est lue **dans le corps de la notification**, qu'Arche compose ainsi :

```
{valeur} {unité} sur {fenêtre} h (palier {palier}).
```

Donc sur un écran verrouillé, la nuit, par quelqu'un sans contexte. Elle doit
faire une phrase, distinguer la sonde de ses quatorze voisines, et nommer
l'objet métier plutôt que la table. C'est ce qui écarte « jambes » — *12 jambes
sur 24 h* ne se lit pas.

| # | Unité | 24 h | 48 h | Pourquoi ces paliers |
|---|---|---|---|---|
| 01 | `échecs CP` | `3, 10, 20, 40, 60, 100` | `10, 40, 100` | CP est la première jambe : elle casse rarement, et quand elle casse le reste suit. |
| 02 | `échecs MC1` | `3, 10, 20, 40, 60, 100` | `10, 40, 100` | Même échelle que CP, pour que les deux chiffres se comparent à l'œil. |
| 03 | `échecs MC2` | `3, 10, 20, 40` | `10, 40` | Un seul marchand, donc un volume bien moindre : la même échelle ne sonnerait jamais. |
| 04 | `CP sans log` | `5, 20, 50, 100` | `20, 100` | 5 et non 3 : un log peut arriver en retard, et la sonde en attrape en vol. |
| 05 | `MC1 sans log` | `5, 20, 50, 100` | `20, 100` | Idem. |
| 06 | `MC2 sans log` | `5, 20, 50` | `20` | Idem, à l'échelle d'un marchand. |
| 07 | `time-outs Airtel` | `1, 3, 10, 30` | `5, 30` | Le premier palier à **1**. Un time-out isolé est déjà une information : ce n'est pas du métier qui refuse. |
| 08 | `identifiants refusés` | `1, 5, 20, 50` | `10, 50` | À 1 aussi : un identifiant refusé signale presque toujours un défaut de génération, pas un cas isolé. |
| 09 | `soldes insuffisants` | `20, 100, 300, 1000` | `100, 1000` | Le métier qui fonctionne. Paliers hauts : on guette un décrochage, pas un incident. |
| 10 | `coupures réseau` | `1, 3, 10, 30` | `5, 30` | Comme 07 : Airtel injoignable. |
| 11 | `pannes techniques inconnues` | `1, 3, 10` | `3, 10` | À 1, parce que le premier exemplaire est ce qu'on veut voir : il nomme un type qu'aucune sonde n'attrape encore. |
| 12 | `refus sans motif` | `5, 20, 50` | `20, 50` | Un refus non détaillé est aveuglant en nombre : il empêche de savoir ce qui se passe. |
| 13 | `refus métier inconnus` | `3, 10, 30, 100` | `10, 100` | À 3 : au-delà, ce n'est plus un cas isolé mais un libellé nouveau qui mérite sa propre sonde. |
| 14 | `paiements en échec` | `10, 50, 100, 250, 500` | `50, 250` | Un volume d'échecs est normal ; on guette le décrochage. |
| 15 | `minutes sans paiement` | `30, 60, 180, 360` | — | **À régler après observation** — voir plus bas. |

### La sonde 15 mérite son propre paragraphe

Les quatorze autres comptent ce qui va mal. **Aucune ne se déclenche quand plus
rien n'arrive** — et c'est la panne la plus grave, parce qu'un compteur
d'échecs à zéro se lit exactement comme un système en bonne santé.

Celle-ci s'inverse : elle mesure les minutes depuis le dernier paiement et monte
toute seule quand la production s'arrête.

Le `coalesce` n'est pas une précaution de style. Sans lui, une fenêtre
entièrement vide rend `NULL`, que `readValue` lit comme zéro : la sonde
annoncerait « tout va bien » au moment précis où plus rien n'arrive depuis
vingt-quatre heures. Le repli mesure alors depuis le début de la fenêtre, ce qui
est un minorant.

Ses paliers, eux, dépendent du rythme réel de nuit, que ce fichier ne connaît
pas. Regarde le plus long trou habituel d'un dimanche à 4 h, puis pose le
premier au-dessus. Posés trop bas, ils réveilleront quelqu'un chaque nuit — et
une sonde qu'on apprend à ignorer ne sert plus à rien.

---

## Le socle

Les treize premières sondes partagent ce préambule mot pour mot. Il déplie les
trois jambes en lignes, calcule leur statut et leur raison avec les expressions
du projet, et neutralise le piège de MC2. **Seule la clause finale change d'une
sonde à l'autre** — c'est ce qui permet d'en ajouter une en copiant un fichier
et en changeant une ligne.

```sql
with jambes as (
    select
        v.jambe,
        case
            when v.request_id is null then 'MISSING'
            when v.response->'status'->>'success' = 'true' then 'SUCCESS'
            else 'FAILED'
        end as statut,
        case
            when v.request_id is null then 'Log Airtel absent'
            when v.response->'status'->>'success' = 'true' then null
            when jsonb_exists(v.response, 'error') then
                v.response->>'error' ||
                case when v.response->>'message' is not null
                     then ' \u2014 ' || (v.response->>'message') else '' end
            else coalesce(
                v.response->'status'->>'message',
                '\u00c9chec non d\u00e9taill\u00e9' || case when v.http_code is not null
                     then ' (HTTP ' || v.http_code || ')' else '' end
            )
        end as raison,
        jsonb_exists(v.response, 'error') as infrastructure
    from payment p
    join merchant m on m.id = p.merchant_id
    left join lateral (
        select al.request_id, al.http_code, al.response
        from airtel_logs al
        where al.request_id =
            case
                when p.channel = 'USSD' then p.airtel_money_id || 'CP'
                else p.request_id || '_CP'
            end
        order by al.created_at desc
        limit 1
    ) cp on true
    left join lateral (
        select al.request_id, al.http_code, al.response
        from airtel_logs al
        where al.request_id =
            case
                when m.name = 'Rengus Digital' then
                    case when p.channel = 'USSD' then p.airtel_money_id || 'MC1'
                         else p.request_id || '_MC1' end
                else
                    case when p.channel = 'USSD' then p.airtel_money_id || 'MC'
                         else p.request_id || '_MC' end
            end
        order by al.created_at desc
        limit 1
    ) mc1 on true
    left join lateral (
        select al.request_id, al.http_code, al.response
        from airtel_logs al
        where m.name = 'Rengus Digital'
          and al.request_id =
            case
                when p.channel = 'USSD' then p.airtel_money_id || 'MC2'
                else p.request_id || '_MC2'
            end
        order by al.created_at desc
        limit 1
    ) mc2 on true
    cross join lateral (values
        ('CP', cp.request_id, cp.http_code, cp.response, true),
        (case when m.name = 'Rengus Digital' then 'MC1' else 'MC' end,
         mc1.request_id, mc1.http_code, mc1.response, true),
        ('MC2', mc2.request_id, mc2.http_code, mc2.response,
         m.name = 'Rengus Digital')
    ) as v(jambe, request_id, http_code, response, concernee)
    where p.created_at >= :depuis
      and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
      and p.status = 'SUCCESS'
      and v.concernee
)
```

Puis, selon la sonde :

```sql
select count(*) as valeur
from jambes
where statut = 'FAILED'
  and jambe = 'CP'
```

```sql
select count(*) as valeur
from jambes
where statut = 'FAILED'
  and (raison ilike '%timeout%'
       or raison ilike '%time out%'
       or raison ilike '%timed out%'
       or raison ilike '%ETIMEDOUT%'
       or raison ilike '%ESOCKETTIMEDOUT%')
```

```sql
select count(*) as valeur
from jambes
where statut = 'FAILED'
  and not infrastructure
  and not (raison ilike '%timeout%'
       or raison ilike '%time out%'
       or raison ilike '%timed out%'
       or raison ilike '%ETIMEDOUT%'
       or raison ilike '%ESOCKETTIMEDOUT%')
  and not (raison ilike '%invalid%transaction%'
       or raison ilike '%transaction%invalide%')
  and not (raison ilike '%solde%insuffisant%'
       or raison ilike '%insufficient%'
       or raison ilike '%balance%')
  and not (raison like 'Échec non détaillé%')
```

Les seize fichiers complets sont dans [`sondes/`](sondes/).

---

## Ce qui n'est pas transcrit, et pourquoi

**Les rapports partenaires** (`partners.js`) ne décrivent pas des erreurs mais
des livrables hebdomadaires, avec leur liste de dates de test exclues en dur.
Rien à surveiller là.

**Le détail des bénéficiaires** (`failedBeneficiaries.js`) répond à « qui n'a
pas été payé », pas à « est-ce que ça va mal ». Une sonde rend un nombre ; cette
question demande une liste. Elle reste du ressort du dashboard.

**Le filtre par marchand** est absent des quinze sondes, volontairement : une
alerte doit dire que quelque chose ne va pas, pas demander pour qui. Si un
marchand doit être suivi à part, c'est une sonde de plus avec son
`and m.name = '…'` dans le socle — pas un paramètre.
